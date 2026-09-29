<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\CssPrinter;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Drupal\daedalus_stylish\SwatchStylesheet;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests swatch delivery: the compiled swatch stylesheet.
 *
 * Every published swatch compiles into one shared, hash-busted file of
 * --swatch-{name} custom property declarations on the token scope element
 * (all-published-are-live, no active pointer), width-conditioned values as.
 *
 * @container blocks and scheme/motion-conditioned values as @media blocks —
 * the exact machinery skin tokens ride. The "daedalus_stylish/swatches" library
 * resolves the current file at discovery time and sorts before the skin
 * file; recompiles run on swatch saves and on workspace publish, with
 * staged revisions previewing via page-level inline CSS in the meantime.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SwatchStylesheetTest extends KernelTestBase {

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
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * Creates and saves a swatch (which compiles via hook_entity_insert()).
   */
  protected function createSwatch(string $label, string|array $value, array $values = []): DaedalusStylishSwatch {
    $swatch = DaedalusStylishSwatch::create($values + [
      'label' => $label,
      'kind' => 'color',
    ]);
    $swatch->setSwatchValue($value);
    $swatch->save();
    return $swatch;
  }

  /**
   * The current compiled stylesheet URI from state.
   */
  protected function stylesheetUri(): ?string {
    return $this->container->get('state')->get(SwatchStylesheet::STATE_KEY);
  }

  /**
   * The published swatches compile on every save; unpublished ones drop out.
   */
  public function testCompileOnSave(): void {
    // Nothing published, nothing compiled.
    $this->assertNull($this->stylesheetUri());

    $red = $this->createSwatch('Brand red', '#e94560');
    // Deliberately unordered condition spellings: 'dark+narrow' is the
    // non-canonical spelling of 'narrow+dark' — delivery folds spellings
    // into one block per canonical condition.
    $accent = $this->createSwatch('Accent', [
      'dark+narrow' => '#333333',
      'dark' => '#eeeeee',
      'default' => '#111111',
      'narrow' => '#222222',
    ]);

    $uri = $this->stylesheetUri();
    $this->assertIsString($uri);
    $this->assertStringStartsWith(SwatchStylesheet::DIRECTORY . '/swatches-', $uri);
    $this->assertFileExists($uri);
    $css = file_get_contents($uri);

    // Swatches deliver on the token scope element, never :root, in the
    // reserved namespace (the ONE derivation rule).
    $this->assertStringContainsString('@scope (.' . DefaultsStylesheet::SCOPE_CLASS . ')', $css);
    $this->assertStringNotContainsString(':root', $css);
    $this->assertStringContainsString('--swatch-brand_red: #e94560;', $css);
    $this->assertStringContainsString('--swatch-accent: #111111;', $css);

    // Width conditions are @container (the edit-mode canvas is a
    // constrained div; @media would not react), scheme conditions @media.
    $this->assertStringContainsString('@container (max-width: 767px)', $css);
    $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $css);
    // The compound composes BOTH wrappers despite its non-canonical stored
    // spelling.
    $this->assertSame(2, substr_count($css, '@container (max-width: 767px)'));
    // Block order is the cascade: default, then singles in vocabulary
    // order, then compounds — the more specific compound value wins by
    // document order where it co-applies with its parts.
    $this->assertLessThan(strpos($css, '#222222'), strpos($css, '#111111'));
    $this->assertLessThan(strpos($css, '#eeeeee'), strpos($css, '#222222'));
    $this->assertLessThan(strpos($css, '#333333'), strpos($css, '#eeeeee'));

    // Width-conditioned swatches demand the live page-level width container.
    $this->assertStringContainsString(CssPrinter::PAGE_CONTAINER_RULE, $css);

    // A value edit recompiles to a NEW filename and cleans up the old file.
    $red->setSwatchValue('#ff0000');
    $red->save();
    $new_uri = $this->stylesheetUri();
    $this->assertNotSame($uri, $new_uri);
    $this->assertFileExists($new_uri);
    $this->assertFileDoesNotExist($uri);
    $this->assertStringContainsString('--swatch-brand_red: #ff0000;', file_get_contents($new_uri));

    // Unpublishing drops the swatch from the file: all PUBLISHED swatches
    // are live, and an unpublished one simply stops delivering.
    $accent->setUnpublished();
    $accent->save();
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringNotContainsString('--swatch-accent', $css);
    // No width conditions left: the containment rule is demand-driven.
    $this->assertStringNotContainsString('container-type', $css);

    // Deleting the last published swatch clears the compiled file.
    $unpublished_uri = $this->stylesheetUri();
    $red->delete();
    $this->assertNull($this->stylesheetUri());
    $this->assertFileDoesNotExist($unpublished_uri);
  }

  /**
   * Delivery is kind-agnostic: a gradient swatch is just another value.
   */
  public function testGradientSwatchDelivers(): void {
    $this->createSwatch('Hero fade', 'linear-gradient(135deg, var(--swatch-brand_red), transparent)', ['kind' => 'gradient']);
    $this->createSwatch('Brand red', '#e94560');
    $css = file_get_contents($this->stylesheetUri());
    // The gradient prints verbatim — var() stops included, resolved by the
    // browser on the scope element like any token-referencing token.
    $this->assertStringContainsString('--swatch-hero_fade: linear-gradient(135deg, var(--swatch-brand_red), transparent);', $css);
    $this->assertStringContainsString('--swatch-brand_red: #e94560;', $css);
  }

  /**
   * The daedalus_stylish/swatches library resolves the current compiled file.
   */
  public function testLibraryResolvesCurrentFile(): void {
    // Before any swatch exists the library is asset-less but defined —
    // pages attach it unconditionally of compile state
    // (daedalus_stylish_page_attachments()).
    $discovery = $this->container->get('library.discovery');
    $library = $discovery->getLibraryByName('daedalus_stylish', 'swatches');
    $this->assertNotFalse($library);
    $this->assertSame([], $library['css'] ?? []);

    $this->createSwatch('Brand red', '#e94560');
    $library = $discovery->getLibraryByName('daedalus_stylish', 'swatches');
    $this->assertCount(1, $library['css']);
    $this->assertStringContainsString(basename($this->stylesheetUri()), $library['css'][0]['data']);
    // The palette competes unlayered, like every daedalus-stylish-owned file.
    $this->assertTrue($library['css'][0]['daedalus_stylish_layers_exempt'] ?? FALSE);
    // And sorts BEFORE the defaults file (-10) and the variants file (0): skin
    // tokens and variant values may reference swatches.
    $this->assertSame(CSS_THEME - 20, $library['css'][0]['weight']);
  }

  /**
   * Workspace preview: staged palette CSS inlines; the file stays live.
   *
   * The edge at page level: library assets cannot vary by workspace, so
   * the compiled file always holds the LIVE revisions; inside a workspace
   * staged edits and workspace-minted swatches print via
   * workspaceInlineCss() into the page-level tag (document order beats the
   * compiled <link>). Publishing recompiles the file — per-entity update
   * hooks do not fire on publish, so that exercises
   * SwatchWorkspaceSubscriber.
   */
  public function testWorkspacePreviewInlineFallback(): void {
    $swatch = $this->createSwatch('Brand red', '#e94560');

    $this->initializeWorkspacesModule();
    $this->setUpCurrentUser();
    $live_uri = $this->stylesheetUri();
    $stylesheet = $this->container->get('daedalus_stylish.swatch_stylesheet');

    // Outside a workspace there is no preview to inline.
    $tags = [];
    $this->assertSame('', $stylesheet->workspaceInlineCss($tags));

    // Stage a value edit and mint a NEW swatch: the shared public file
    // keeps the live palette.
    $this->switchToWorkspace('stage');
    $staged = $this->entityTypeManager->getStorage('daedalus_swatch')->load($swatch->id());
    $staged->setSwatchValue('#00ff00');
    $staged->save();
    $this->createSwatch('Minted in stage', '#123456');
    $this->assertSame($live_uri, $this->stylesheetUri());
    $live_css = file_get_contents($live_uri);
    $this->assertStringContainsString('--swatch-brand_red: #e94560;', $live_css);
    $this->assertStringNotContainsString('minted_in_stage', $live_css);

    // Inside the workspace the staged revisions inline at the same scope
    // element (the roster is built per workspace), and the roster's tags
    // ride along: the swatch LIST tag, invalidated by any swatch save, so
    // staged edits, mints and unpublishes invalidate the page.
    $tags = [];
    $css = $stylesheet->workspaceInlineCss($tags);
    $this->assertStringContainsString('@scope (.' . DefaultsStylesheet::SCOPE_CLASS . ')', $css);
    $this->assertStringContainsString('--swatch-brand_red: #00ff00;', $css);
    $this->assertStringContainsString('--swatch-minted_in_stage: #123456;', $css);
    $this->assertContains('daedalus_swatch_list', $tags);

    // Publishing lands the staged palette in the shared file.
    $this->switchToLive();
    $this->workspaces['stage']->publish();
    $published_uri = $this->stylesheetUri();
    $this->assertNotSame($live_uri, $published_uri);
    $published_css = file_get_contents($published_uri);
    $this->assertStringContainsString('--swatch-brand_red: #00ff00;', $published_css);
    $this->assertStringContainsString('--swatch-minted_in_stage: #123456;', $published_css);
    $this->assertFileDoesNotExist($live_uri);
  }

}
