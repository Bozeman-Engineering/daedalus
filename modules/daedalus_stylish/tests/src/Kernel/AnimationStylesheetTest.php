<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\AnimationStylesheet;
use Drupal\daedalus_stylish\Entity\DaedalusStylishAnimation;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests animation delivery: the compiled animations stylesheet.
 *
 * Every published animation compiles into one shared, hash-busted file of
 * named @keyframes at-rules (motion-{name}, the one derivation rule;
 * all-published-are-live, no active pointer), outside any @scope and free
 * of condition wrappers. The "daedalus_stylish/animations" library resolves the
 * current file at discovery time and sorts between the swatch and skin
 * files; recompiles run on animation saves and on workspace publish, with
 * staged revisions previewing via page-level inline CSS in the meantime.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class AnimationStylesheetTest extends KernelTestBase {

  use UserCreationTrait;
  use WorkspaceTestTrait;

  /**
   * The entity type manager, re-fetched by WorkspaceTestTrait.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * Creates and saves an animation (which compiles via hook_entity_insert()).
   */
  protected function createAnimation(string $label, array $steps, array $values = []): DaedalusStylishAnimation {
    $animation = DaedalusStylishAnimation::create($values + ['label' => $label]);
    $animation->setSteps($steps);
    $animation->save();
    return $animation;
  }

  /**
   * The current compiled stylesheet URI from state.
   */
  protected function stylesheetUri(): ?string {
    return $this->container->get('state')->get(AnimationStylesheet::STATE_KEY);
  }

  /**
   * The published animations compile on every save; unpublished ones drop out.
   */
  public function testCompileOnSave(): void {
    // Nothing published, nothing compiled.
    $this->assertNull($this->stylesheetUri());

    $fade = $this->createAnimation('Fade in', [
      'from' => ['opacity' => '0'],
      'to' => ['opacity' => '1'],
    ]);
    // Steps land canonical and sorted whatever the write spelled, and a
    // var() reference inside a step prints verbatim (resolved on the
    // animated element like anywhere else).
    $rise = $this->createAnimation('Rise', [
      '100%' => ['transform' => 'none', 'opacity' => '1'],
      '0%' => ['transform' => 'translateY(var(--space-md, 1rem))', 'opacity' => '0'],
      '50.0%' => ['animation-timing-function' => 'ease-out'],
    ]);

    $uri = $this->stylesheetUri();
    $this->assertIsString($uri);
    $this->assertStringStartsWith(AnimationStylesheet::DIRECTORY . '/animations-', $uri);
    $this->assertFileExists($uri);
    $css = file_get_contents($uri);

    // Named at-rules in the motion namespace (the ONE derivation rule),
    // label order, no @scope anywhere: keyframes are referenced by name,
    // never matched by selector.
    $expected = <<<CSS
@keyframes motion-fade_in {
  0% {
    opacity: 0;
  }
  100% {
    opacity: 1;
  }
}
@keyframes motion-rise {
  0% {
    transform: translateY(var(--space-md, 1rem));
    opacity: 0;
  }
  50% {
    animation-timing-function: ease-out;
  }
  100% {
    transform: none;
    opacity: 1;
  }
}

CSS;
    $this->assertSame($expected, $css);
    $this->assertStringNotContainsString('@scope', $css);

    // A steps edit recompiles to a NEW filename and cleans up the old file.
    $fade->setSteps(['from' => ['opacity' => '0.2'], 'to' => ['opacity' => '1']]);
    $fade->save();
    $new_uri = $this->stylesheetUri();
    $this->assertNotSame($uri, $new_uri);
    $this->assertFileExists($new_uri);
    $this->assertFileDoesNotExist($uri);
    $this->assertStringContainsString("  0% {\n    opacity: 0.2;\n  }", file_get_contents($new_uri));

    // Unpublishing drops the animation from the file: all PUBLISHED
    // animations are live, and an unpublished one simply stops delivering.
    $rise->setUnpublished();
    $rise->save();
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringNotContainsString('motion-rise', $css);
    $this->assertStringContainsString('@keyframes motion-fade_in {', $css);

    // Deleting the last published animation clears the compiled file.
    $unpublished_uri = $this->stylesheetUri();
    $fade->delete();
    $this->assertNull($this->stylesheetUri());
    $this->assertFileDoesNotExist($unpublished_uri);
  }

  /**
   * The emit gate: an animation with no printable step delivers nothing.
   */
  public function testEmitGate(): void {
    // An empty keyframe is legal on write (validateAnimationSteps admits
    // {}) but declares nothing, so the animation prints no at-rule and
    // the file stays absent.
    $this->createAnimation('Hold', ['50%' => []]);
    $this->assertNull($this->stylesheetUri());

    // Beside a printable one, only the printable one lands.
    $this->createAnimation('Blink', ['0%' => ['opacity' => '1'], '50%' => ['opacity' => '0']]);
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringContainsString('@keyframes motion-blink {', $css);
    $this->assertStringNotContainsString('motion-hold', $css);
  }

  /**
   * The daedalus_stylish/animations library resolves the current compiled file.
   */
  public function testLibraryResolvesCurrentFile(): void {
    // Before any animation exists the library is asset-less but defined:
    // pages attach it unconditionally of compile state
    // (daedalus_stylish_page_attachments()).
    $discovery = $this->container->get('library.discovery');
    $library = $discovery->getLibraryByName('daedalus_stylish', 'animations');
    $this->assertNotFalse($library);
    $this->assertSame([], $library['css'] ?? []);

    $this->createAnimation('Fade in', ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']]);
    $library = $discovery->getLibraryByName('daedalus_stylish', 'animations');
    $this->assertCount(1, $library['css']);
    $this->assertStringContainsString(basename($this->stylesheetUri()), $library['css'][0]['data']);
    // The at-rules compete unlayered, like every daedalus-stylish-owned file.
    $this->assertTrue($library['css'][0]['daedalus_stylish_layers_exempt'] ?? FALSE);
    // And sort AFTER the swatch file (-20) and BEFORE the skin file (-10):
    // steps may reference swatches, skin defaults may wear animations.
    $this->assertSame(CSS_THEME - 15, $library['css'][0]['weight']);
  }

  /**
   * Workspace preview: staged keyframes inline; the file stays live.
   *
   * The edge at page level: library assets cannot vary by workspace, so
   * the compiled file always holds the LIVE revisions; inside a workspace
   * staged edits and workspace-minted animations print via
   * workspaceInlineCss() into the page-level tag (the later @keyframes of
   * a name wins). Publishing recompiles the file: per-entity update hooks
   * do not fire on publish, so that exercises AnimationWorkspaceSubscriber.
   */
  public function testWorkspacePreviewInlineFallback(): void {
    $animation = $this->createAnimation('Fade in', ['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']]);

    $this->initializeWorkspacesModule();
    $this->setUpCurrentUser();
    $live_uri = $this->stylesheetUri();
    $stylesheet = $this->container->get('daedalus_stylish.animation_stylesheet');

    // Outside a workspace there is no preview to inline.
    $tags = [];
    $this->assertSame('', $stylesheet->workspaceInlineCss($tags));

    // Stage a steps edit and mint a NEW animation: the shared public file
    // keeps the live set.
    $this->switchToWorkspace('stage');
    $staged = $this->entityTypeManager->getStorage('daedalus_animation')->load($animation->id());
    $staged->setSteps(['from' => ['opacity' => '0.5'], 'to' => ['opacity' => '1']]);
    $staged->save();
    $this->createAnimation('Minted in stage', ['0%' => ['transform' => 'scale(0.9)'], '100%' => ['transform' => 'none']]);
    $this->assertSame($live_uri, $this->stylesheetUri());
    $live_css = file_get_contents($live_uri);
    $this->assertStringContainsString("  0% {\n    opacity: 0;\n  }", $live_css);
    $this->assertStringNotContainsString('minted_in_stage', $live_css);

    // Inside the workspace the staged revisions inline as the same named
    // at-rules (the repository reads per workspace), and the animation
    // LIST tag rides along: invalidated by any animation save, so staged
    // edits, mints and unpublishes invalidate the page.
    $tags = [];
    $css = $stylesheet->workspaceInlineCss($tags);
    $this->assertStringContainsString("@keyframes motion-fade_in {\n  0% {\n    opacity: 0.5;\n  }", $css);
    $this->assertStringContainsString('@keyframes motion-minted_in_stage {', $css);
    $this->assertContains('daedalus_animation_list', $tags);

    // Publishing lands the staged set in the shared file.
    $this->switchToLive();
    $this->workspaces['stage']->publish();
    $published_uri = $this->stylesheetUri();
    $this->assertNotSame($live_uri, $published_uri);
    $published_css = file_get_contents($published_uri);
    $this->assertStringContainsString("  0% {\n    opacity: 0.5;\n  }", $published_css);
    $this->assertStringContainsString('@keyframes motion-minted_in_stage {', $published_css);
    $this->assertFileDoesNotExist($live_uri);
  }

}
