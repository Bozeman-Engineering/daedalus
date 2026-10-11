<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\FontFacesItem;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Compiles the published fonts into one shared stylesheet file.
 *
 * The type material's delivery half: every face of every published font
 * prints as one `@font-face` at-rule (the family quoted through the ONE
 * derivation rule, StyleGuide::fontFamily(); `font-display: swap` on
 * every face, so text renders in the fallback until the file lands and
 * never blocks) into a single hash-busted public file. The
 * "daedalus_stylish/fonts" library resolves the current file at
 * library-discovery time, the exact posture of the animations, swatch,
 * skin and variants libraries. Like keyframes, an at-rule sits outside
 * any @scope and is referenced by its family from a value, so no scope
 * element, no condition wrappers and no page-container rule are involved.
 *
 * The published set is the FontRepository's (publishedFonts(),
 * label-sorted, then machine name): the same read the materials roster's
 * font records come from, so the fonts this file carries are exactly the
 * fonts the roster offers. ALL published fonts compile
 * (all-published-are-live, no active pointer): families are a namespace,
 * and an unpublished font simply stops delivering.
 *
 * The file sorts FIRST among the compiled files, before the swatch file
 * (-20), at library weight -25: a skin's font-scale tokens and every
 * variant may name the family, so the declarations lead their wearers in
 * the delivered document (order is readability here, not correctness: a
 * font-family reference resolves lazily whatever the order).
 *
 * The workspace-preview edge mirrors the animation file: this file always
 * holds the LIVE (default-revision, published) fonts. Inside a workspace
 * staged revisions (and workspace-minted fonts) inline per page
 * (workspaceInlineCss(), printed inside the token scope element by
 * daedalus_stylish_preprocess_off_canvas_page_wrapper()); a later face of
 * the same family, weight and style in document order wins.
 * Publishing the workspace recompiles the file (FontWorkspaceSubscriber).
 *
 * The file mechanics live on CompiledStylesheetBase. compile() runs on
 * font insert/update/delete (_daedalus_font_change(), after the
 * repository reset so the compile reads the post-write set) and on
 * workspace publish. No media hook: a face names a managed FILE, not a
 * media item, and the file is held by usage for the font's life, so
 * referencedMediaUuids() records nothing.
 */
class FontStylesheet extends CompiledStylesheetBase {

  /**
   * Invalidated on every recompile.
   *
   * Rendered pages attach the "daedalus_stylish/fonts" library and carry
   * this tag (daedalus_stylish_page_attachments()), so font saves reach
   * already-cached pages.
   */
  public const CACHE_TAG = 'daedalus_stylish_fonts';

  /**
   * State key holding the current stylesheet URI (content-addressed).
   */
  public const STATE_KEY = 'daedalus_stylish.font_stylesheet_uri';

  /**
   * State key holding the media uuids the compiled fonts reference.
   *
   * Always empty (a face names a file, never a media item), recorded
   * anyway because the base contract writes it on every compile.
   */
  public const MEDIA_STATE_KEY = 'daedalus_stylish.font_stylesheet_media';

  /**
   * The compiled file's basename prefix.
   */
  public const FILE_PREFIX = 'fonts';

  public function __construct(
    protected CssPrinter $printer,
    protected FontRepository $fontRepository,
    protected FontFiles $fontFiles,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected EntityTypeManagerInterface $entityTypeManager,
    StateInterface $state,
    FileSystemInterface $fileSystem,
    StreamWrapperManagerInterface $streamWrapperManager,
    LibraryDiscoveryInterface $libraryDiscovery,
    ?WorkspaceManagerInterface $workspaceManager,
  ) {
    parent::__construct($state, $fileSystem, $streamWrapperManager, $libraryDiscovery, $workspaceManager);
  }

  /**
   * Prints every published font's @font-face at-rules.
   *
   * Deterministic (content-addressed output): the repository's published
   * set comes label-sorted with the unique machine name as tiebreak, and
   * each font prints its canonical faces in stored (weight, style) order,
   * so the same fonts always produce the same file.
   *
   * @return string
   *   CSS text, '' when no published face prints.
   */
  public function css(): string {
    return $this->printFonts($this->fontRepository->publishedFonts());
  }

  /**
   * Prints fonts as @font-face at-rules, one block per face.
   *
   * The one printing path behind the compiled file and the workspace
   * inline preview, so the two can never emit different CSS for the same
   * revisions. A face whose file is gone or is not a font file prints
   * nothing (the emit gate: the validator refused it at write, and a file
   * deleted around the spine must not print a dead url()); the printer
   * owns the declaration hygiene and drops what fails it.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishFontInterface[] $fonts
   *   The fonts to print.
   *
   * @return string
   *   CSS text, '' when nothing printed.
   */
  protected function printFonts(array $fonts): string {
    $blocks = [];
    foreach ($fonts as $font) {
      $family = StyleGuide::fontFamily($font->getFamily());
      foreach ($font->getFaces() as $face) {
        $uuid = $face['source'][FontFacesItem::SOURCE_FILE] ?? NULL;
        $file = is_string($uuid) ? $this->fontFiles->load($uuid) : NULL;
        $format = $file === NULL ? NULL : FontFiles::format($file);
        if ($file === NULL || $format === NULL) {
          continue;
        }
        $block = $this->printer->printFontFace($family, (string) ($face['weight'] ?? ''), (string) ($face['style'] ?? ''), $this->fileUrlGenerator->generateString($file->getFileUri()), $format);
        if ($block !== '') {
          $blocks[] = $block;
        }
      }
    }
    return implode("\n", $blocks);
  }

  /**
   * Inline CSS for the staged fonts inside a workspace.
   *
   * The workspace-preview edge at page level, the animation file's exact
   * shape: the compiled file is per-site and library assets cannot vary
   * by workspace, so inside a workspace the (possibly staged) published
   * fonts print into a page-level <style> tag (inside the token scope
   * element) as the same at-rules, and the later declaration of a face
   * wins. Workspace-minted fonts are covered too: the repository queried
   * inside the workspace reads the workspace's tracked revisions.
   *
   * @param array $cache_tags
   *   Cache tags accumulator; the font LIST tag merges in (invalidated by
   *   any font save, so staged edits, mints and unpublishes invalidate
   *   the workspace-context page cache entries).
   *
   * @return string
   *   CSS text, '' outside an active workspace or with nothing to print.
   */
  public function workspaceInlineCss(array &$cache_tags): string {
    if ($this->workspaceManager === NULL || !$this->workspaceManager->hasActiveWorkspace()) {
      return '';
    }
    $cache_tags = Cache::mergeTags($cache_tags, $this->entityTypeManager->getDefinition('daedalus_font')->getListCacheTags());
    return $this->printFonts($this->fontRepository->publishedFonts());
  }

  /**
   * Scans the compiled fonts for media token payloads: none, by design.
   *
   * A face names a managed file held by usage, never a media item, so
   * nothing is ever recorded and the media-change hooks never gate a
   * recompile on this file.
   *
   * @return string[]
   *   Always empty.
   */
  protected function referencedMediaUuids(): array {
    return [];
  }

}
