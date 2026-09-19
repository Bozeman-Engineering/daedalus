<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Compiles the published swatches into one shared stylesheet file.
 *
 * The palette's delivery half: every published swatch prints as one CSS
 * custom property declaration (--swatch-{name}, the ONE derivation rule —
 * StyleGuide::swatchVariable(), read back from the record's var() spelling)
 * on the TOKEN SCOPE ELEMENT (DefaultsStylesheet::SCOPE_CLASS), into a single
 * hash-busted public file. The "daedalus_stylish/swatches" library resolves the
 * current file at library-discovery time, the exact posture of the skin
 * and variants libraries.
 *
 * The published set is the MATERIALS ROSTER's swatch records, in roster
 * order: the palette this file delivers is exactly the palette the
 * validator admits, the catalog parses and the prompt teaches, because all
 * four read the one roster (a swatch the validator accepts is one this file
 * carries). ALL published swatches compile (all-published-are-live, no
 * active pointer): the palette is a namespace, names never compete, and an
 * unpublished swatch simply leaves the roster and stops delivering.
 *
 * The file sorts BEFORE the skin file (library weight -20 against the
 * skin's -10 and the variants' 0): skin token values and variant styles may
 * reference swatches via var(--swatch-*) (the validator admits the
 * reference in both stores), and while custom properties resolve lazily at
 * computed-value time regardless of declaration order, keeping the
 * definitions first keeps the delivered document readable in dependency
 * order and gives any non-custom-property swatch consumer a deterministic
 * cascade.
 *
 * Condition-keyed values ride the exact machinery skin tokens ride: the
 * same {condition: value} grammar (validateSwatchValue() restricts it to
 * the TOKEN_CONDITION_AXES), the same @container-not-@media width wrappers
 * (the edit-mode canvas is a constrained div — see DefaultsStylesheet's class
 * docblock, which owns the token scope element reasoning wholesale), the
 * same demand-driven page-container rule for live pages.
 *
 * The workspace-preview edge mirrors the skin file: this file always
 * holds the LIVE (default-revision, published) swatches. Inside a workspace
 * staged swatch revisions (and workspace-minted swatches) inline per page
 * at the same scope selector (workspaceInlineCss(), embedded by
 * daedalus_stylish_page_top()); later in the document than the compiled <link>, the
 * inline redefinition of the same custom property wins. The roster is
 * built per workspace, so the inline preview reads the staged palette and
 * the compile (outside the workspace) the live one. Publishing the
 * workspace recompiles the file (SwatchWorkspaceSubscriber).
 *
 * The file mechanics (content-addressed name, state key, cache tag + library
 * reset, the accepted staleness class) live on CompiledStylesheetBase.
 * compile() runs on swatch insert/update/delete (_daedalus_swatch_change(),
 * after the roster reset so the compile reads the post-write set), on a
 * design-system switch (DesignSystemSwitchSubscriber: the roster's swatches are
 * the active system's plus the global ones) and on workspace publish
 * (SwatchWorkspaceSubscriber), deliberately NOT on daedalus_stylish.settings saves: like
 * the variants file (see DefaultsSettingsSubscriber's docblock), every compile
 * here builds the roster, whose provider queries the daedalus_stylish storages, which do
 * not exist yet when default config imports at module install, so a settings
 * change leaves this file stale until the next swatch save (the same accepted
 * staleness class breakpoint edits always had for the variants file). No media
 * hook either: swatch values are media-free by design (the gradient kind
 * rejects url()/media layers, colors carry none), so referencedMediaUuids()
 * records nothing and referencesMedia() never gates a recompile.
 */
class SwatchStylesheet extends CompiledStylesheetBase {

  /**
   * Invalidated on every recompile.
   *
   * Rendered pages attach the "daedalus_stylish/swatches" library and carry this tag
   * (daedalus_stylish_page_top()), so swatch saves reach already-cached pages.
   */
  public const CACHE_TAG = 'daedalus_stylish_swatches';

  /**
   * State key holding the current stylesheet URI (content-addressed).
   */
  public const STATE_KEY = 'daedalus_stylish.swatch_stylesheet_uri';

  /**
   * State key holding the media uuids the compiled swatches reference.
   *
   * Always empty (swatch values are media-free by design), recorded
   * anyway because the base contract writes it on every compile — the day a
   * kind admits media tokens, the recompile bookkeeping is already wired.
   */
  public const MEDIA_STATE_KEY = 'daedalus_stylish.swatch_stylesheet_media';

  /**
   * The compiled file's basename prefix.
   */
  public const FILE_PREFIX = 'swatches';

  public function __construct(
    protected CssPrinter $printer,
    protected ConditionTypePluginManager $conditionTypes,
    protected MaterialsRosterBuilder $rosterBuilder,
    StateInterface $state,
    FileSystemInterface $fileSystem,
    FileUrlGeneratorInterface $fileUrlGenerator,
    LibraryDiscoveryInterface $libraryDiscovery,
    ?WorkspaceManagerInterface $workspaceManager,
  ) {
    parent::__construct($state, $fileSystem, $fileUrlGenerator, $libraryDiscovery, $workspaceManager);
  }

  /**
   * Prints every published swatch's custom property declarations.
   *
   * Deterministic (content-addressed output): the roster's swatch records
   * come in roster order (label, then the stored order of the query), and
   * each swatch declares one custom property under each of its condition
   * keys, so the same palette always produces the same file.
   *
   * @return string
   *   CSS text, '' when no published swatch exists.
   */
  public function css(): string {
    return $this->printSwatches($this->rosterBuilder->build()->swatches());
  }

  /**
   * Prints swatch records as one scope block on the token scope element.
   *
   * The one printing path behind the compiled file and the workspace inline
   * preview, so the two can never emit different CSS for the same
   * revisions. All swatches merge into ONE condition-keyed entry
   * ({condition: {--swatch-{name}: value}}) printed through the same
   * printer machinery skin tokens ride: the printer owns the condition
   * block ordering (default first, singles in vocabulary order, compounds
   * last — the cascade among co-applying blocks) and the @container/@media
   * wrapper composition. The custom property is the record's own style
   * spelling read back (MaterialsSchema::customProperty()), never a second
   * concatenation of the reserved prefix.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $swatches
   *   The swatch records to print.
   *
   * @return string
   *   CSS text, '' when nothing printed.
   */
  protected function printSwatches(array $swatches): string {
    $entry = [];
    foreach ($swatches as $swatch) {
      $variable = MaterialsSchema::customProperty($swatch);
      $value = $swatch->value;
      if (is_scalar($value)) {
        $entry['default'][$variable] = (string) $value;
        continue;
      }
      foreach ((array) $value as $condition => $conditioned) {
        if (!is_scalar($conditioned)) {
          continue;
        }
        // Write paths store canonical compound spellings; canonicalizing
        // again here folds any value written around them into one block per
        // condition instead of forking on spelling (the tokensEntry()
        // posture).
        $condition = (string) $condition;
        if ($condition !== 'default') {
          $condition = $this->conditionTypes->canonicalizeCondition($condition);
        }
        $entry[$condition][$variable] = (string) $conditioned;
      }
    }
    if ($entry === []) {
      return '';
    }
    $css = $this->printer->printEntry('.' . DefaultsStylesheet::SCOPE_CLASS, NULL, $entry);
    if ($css === '') {
      return '';
    }
    // The live page-level width container, demand-driven, honoring the
    // kill switch, the skin file's exact posture: only a palette that
    // actually conditions values on width needs the off-canvas canvas
    // declared as the page-level width container on live pages (edit mode's
    // stage already is one).
    if ($this->usesContainerConditions($entry) && $this->conditionTypes->containerRootsEnabled()) {
      $css .= "\n" . CssPrinter::PAGE_CONTAINER_RULE;
    }
    return $css;
  }

  /**
   * Inline CSS for the staged palette inside a workspace.
   *
   * The workspace-preview edge at page level, the skin file's exact
   * shape: the compiled file is per-site and library assets cannot vary by
   * workspace, so inside a workspace the (possibly staged) published
   * swatches print into a page-level <style> tag (daedalus_stylish_page_top()) at
   * the same scope selector — the inline redefinition of the same custom
   * property is later in the document and wins. Workspace-minted swatches
   * are covered too: the roster built inside the workspace reads the
   * workspace's tracked revisions.
   *
   * @param array $cache_tags
   *   Cache tags accumulator; the roster's tags merge in (the swatch LIST
   *   tag among them, invalidated by any swatch save, so staged edits,
   *   mints, and unpublishes invalidate the workspace-context page cache
   *   entries; a record carries no per-entity tag and needs none, the
   *   list tag is what every one of those writes invalidates).
   *
   * @return string
   *   CSS text, '' outside an active workspace or with nothing to print.
   */
  public function workspaceInlineCss(array &$cache_tags): string {
    if ($this->workspaceManager === NULL || !$this->workspaceManager->hasActiveWorkspace()) {
      return '';
    }
    $roster = $this->rosterBuilder->build();
    $cache_tags = Cache::mergeTags($cache_tags, $roster->getCacheableMetadata()->getCacheTags());
    return $this->printSwatches($roster->swatches());
  }

  /**
   * Whether any emitted condition block wraps in a container query.
   */
  protected function usesContainerConditions(array $entry): bool {
    foreach (array_keys($entry) as $condition) {
      if ($this->conditionTypes->isContainerCondition((string) $condition)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Scans the compiled swatches for media token payloads: none, by design.
   *
   * Swatch values are media-free (the gradient kind rejects url()/media
   * layers, the color kind is a color), so nothing is ever recorded and the
   * media-change hooks never gate a recompile on this file. A future kind
   * admitting media tokens implements the scan here and adds the file to
   * _daedalus_stylish_media_change().
   *
   * @return string[]
   *   Always empty.
   */
  protected function referencedMediaUuids(): array {
    return [];
  }

}
