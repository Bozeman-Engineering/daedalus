<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_stylish\Entity\DaedalusStylishFont;
use Drupal\daedalus_stylish\FontStylesheet;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests font delivery: the compiled fonts stylesheet.
 *
 * Every face of every published font compiles into one shared,
 * hash-busted file of @font-face at-rules (the family quoted through the
 * one derivation rule, font-display: swap on every face;
 * all-published-are-live, no active pointer), outside any @scope. The
 * "daedalus_stylish/fonts" library resolves the current file at discovery
 * time and sorts before the swatch file; recompiles run on font saves and
 * on workspace publish, with staged revisions previewing via page-level
 * inline CSS in the meantime.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class FontStylesheetTest extends KernelTestBase {

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
    'file',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('daedalus_font');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * Saves font bytes through the font store.
   */
  protected function fontFile(string $name): FileInterface {
    return $this->container->get('daedalus_stylish.font_files')->fromBytes($name, 'wOF2' . str_repeat("\0", 16));
  }

  /**
   * Creates and saves a font (which compiles via hook_entity_insert()).
   */
  protected function createFont(string $label, array $faces, array $values = []): DaedalusStylishFont {
    $font = DaedalusStylishFont::create($values + ['label' => $label, 'family' => $label]);
    $font->setFaces($faces);
    $font->save();
    return $font;
  }

  /**
   * The URL a file delivers at, as the at-rule prints it.
   */
  protected function url(FileInterface $file): string {
    return $this->container->get('file_url_generator')->generateString($file->getFileUri());
  }

  /**
   * The current compiled stylesheet URI from state.
   */
  protected function stylesheetUri(): ?string {
    return $this->container->get('state')->get(FontStylesheet::STATE_KEY);
  }

  /**
   * The published fonts compile on every save; unpublished ones drop out.
   */
  public function testCompileOnSave(): void {
    // Nothing published, nothing compiled.
    $this->assertNull($this->stylesheetUri());

    $regular = $this->fontFile('inter-regular.woff2');
    $bold = $this->fontFile('inter-bold.woff');
    $serif = $this->fontFile('source-serif.woff2');
    $inter = $this->createFont('Inter', [
      ['weight' => 'bold', 'source' => ['file' => $bold->uuid()]],
      ['source' => ['file' => $regular->uuid()]],
    ]);
    $this->createFont('Source Serif', [
      ['weight' => '200 900', 'style' => 'italic', 'source' => ['file' => $serif->uuid()]],
    ]);

    $uri = $this->stylesheetUri();
    $this->assertIsString($uri);
    $this->assertStringStartsWith(FontStylesheet::DIRECTORY . '/fonts-', $uri);
    $this->assertFileExists($uri);
    $css = file_get_contents($uri);

    // One at-rule per face in label then face order, the family quoted,
    // swap on every face, the format from the file, no @scope anywhere.
    $expected = <<<CSS
@font-face {
  font-family: "Inter";
  font-style: normal;
  font-weight: 400;
  font-display: swap;
  src: url("{$this->url($regular)}") format("woff2");
}
@font-face {
  font-family: "Inter";
  font-style: normal;
  font-weight: 700;
  font-display: swap;
  src: url("{$this->url($bold)}") format("woff");
}
@font-face {
  font-family: "Source Serif";
  font-style: italic;
  font-weight: 200 900;
  font-display: swap;
  src: url("{$this->url($serif)}") format("woff2");
}

CSS;
    $this->assertSame($expected, $css);
    $this->assertStringNotContainsString('@scope', $css);

    // A face edit recompiles to a NEW filename and cleans up the old file.
    $inter->setFaces([['source' => ['file' => $regular->uuid()]]]);
    $inter->save();
    $new_uri = $this->stylesheetUri();
    $this->assertNotSame($uri, $new_uri);
    $this->assertFileExists($new_uri);
    $this->assertFileDoesNotExist($uri);
    $this->assertStringNotContainsString('font-weight: 700', file_get_contents($new_uri));

    // Unpublishing drops the font from the file: all PUBLISHED fonts are
    // live, and an unpublished one simply stops delivering.
    $inter->setUnpublished();
    $inter->save();
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringNotContainsString('"Inter"', $css);
    $this->assertStringContainsString('font-family: "Source Serif";', $css);

    // Deleting every published font clears the compiled file.
    $unpublished_uri = $this->stylesheetUri();
    $inter->delete();
    $this->assertStringContainsString('"Source Serif"', file_get_contents($this->stylesheetUri()));
    foreach ($this->entityTypeManager()->getStorage('daedalus_font')->loadMultiple() as $font) {
      $font->delete();
    }
    $this->assertNull($this->stylesheetUri());
    $this->assertFileDoesNotExist($unpublished_uri);
  }

  /**
   * The emit gate: a face whose file is gone prints nothing.
   */
  public function testEmitGate(): void {
    $regular = $this->fontFile('inter-regular.woff2');
    $lost = $this->fontFile('inter-lost.woff2');
    $this->createFont('Inter', [
      ['source' => ['file' => $regular->uuid()]],
      ['weight' => 700, 'source' => ['file' => $lost->uuid()]],
    ]);
    $this->assertStringContainsString('font-weight: 700', file_get_contents($this->stylesheetUri()));

    // A file deleted around the spine leaves its face unprinted (never a
    // dead url()); the other face still lands.
    $lost->delete();
    $stylesheet = $this->container->get('daedalus_stylish.font_stylesheet');
    $stylesheet->compile();
    $css = file_get_contents($this->stylesheetUri());
    $this->assertStringContainsString('font-weight: 400', $css);
    $this->assertStringNotContainsString('font-weight: 700', $css);

    // With no printable face the file is absent.
    $regular->delete();
    $stylesheet->compile();
    $this->assertNull($this->stylesheetUri());
  }

  /**
   * The daedalus_stylish/fonts library resolves the current compiled file.
   */
  public function testLibraryResolvesCurrentFile(): void {
    // Before any font exists the library is asset-less but defined: pages
    // attach it unconditionally of compile state
    // (daedalus_stylish_page_attachments()).
    $discovery = $this->container->get('library.discovery');
    $library = $discovery->getLibraryByName('daedalus_stylish', 'fonts');
    $this->assertNotFalse($library);
    $this->assertSame([], $library['css'] ?? []);

    $this->createFont('Inter', [['source' => ['file' => $this->fontFile('inter.woff2')->uuid()]]]);
    $library = $discovery->getLibraryByName('daedalus_stylish', 'fonts');
    $this->assertCount(1, $library['css']);
    $this->assertStringContainsString(basename($this->stylesheetUri()), $library['css'][0]['data']);
    // The at-rules compete unlayered, like every daedalus-stylish-owned file.
    $this->assertTrue($library['css'][0]['daedalus_stylish_layers_exempt'] ?? FALSE);
    // And sort BEFORE the swatch file (-20): the declarations lead every
    // file that may name the family.
    $this->assertSame(CSS_THEME - 25, $library['css'][0]['weight']);
  }

  /**
   * Workspace preview: staged faces inline; the file stays live.
   *
   * The edge at page level: library assets cannot vary by workspace, so
   * the compiled file always holds the LIVE revisions; inside a workspace
   * staged edits and workspace-minted fonts print via workspaceInlineCss()
   * into the page-level tag. Publishing recompiles the file: per-entity
   * update hooks do not fire on publish, so that exercises
   * FontWorkspaceSubscriber.
   */
  public function testWorkspacePreviewInlineFallback(): void {
    $regular = $this->fontFile('inter-regular.woff2');
    $bold = $this->fontFile('inter-bold.woff2');
    $mono = $this->fontFile('mono.woff2');
    $font = $this->createFont('Inter', [['source' => ['file' => $regular->uuid()]]]);

    $this->initializeWorkspacesModule();
    $this->setUpCurrentUser();
    $live_uri = $this->stylesheetUri();
    $stylesheet = $this->container->get('daedalus_stylish.font_stylesheet');

    // Outside a workspace there is no preview to inline.
    $tags = [];
    $this->assertSame('', $stylesheet->workspaceInlineCss($tags));

    // Stage a faces edit and mint a NEW font: the shared public file keeps
    // the live set.
    $this->switchToWorkspace('stage');
    $staged = $this->entityTypeManager->getStorage('daedalus_font')->load($font->id());
    $this->assertInstanceOf(DaedalusStylishFont::class, $staged);
    $staged->setFaces([
      ['source' => ['file' => $regular->uuid()]],
      ['weight' => 700, 'source' => ['file' => $bold->uuid()]],
    ]);
    $staged->save();
    $this->createFont('Mono', [['source' => ['file' => $mono->uuid()]]]);
    $this->assertSame($live_uri, $this->stylesheetUri());
    $live_css = file_get_contents($live_uri);
    $this->assertStringNotContainsString('font-weight: 700', $live_css);
    $this->assertStringNotContainsString('"Mono"', $live_css);

    // Inside the workspace the staged revisions inline as the same
    // at-rules (the repository reads per workspace), and the font LIST
    // tag rides along.
    $tags = [];
    $css = $stylesheet->workspaceInlineCss($tags);
    $this->assertStringContainsString('font-weight: 700', $css);
    $this->assertStringContainsString('font-family: "Mono";', $css);
    $this->assertContains('daedalus_font_list', $tags);

    // Publishing lands the staged set in the shared file.
    $this->switchToLive();
    $this->workspaces['stage']->publish();
    $published_uri = $this->stylesheetUri();
    $this->assertNotSame($live_uri, $published_uri);
    $published_css = file_get_contents($published_uri);
    $this->assertStringContainsString('font-weight: 700', $published_css);
    $this->assertStringContainsString('font-family: "Mono";', $published_css);
    $this->assertFileDoesNotExist($live_uri);
  }

  /**
   * The entity type manager (the trait re-fetches it after a container change).
   */
  protected function entityTypeManager() {
    return $this->entityTypeManager ?? $this->container->get('entity_type.manager');
  }

}
