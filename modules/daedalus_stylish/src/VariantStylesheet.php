<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Compiles variant CSS into one shared, hash-busted stylesheet file.
 *
 * An applied variant is a REFERENCE in the host entity's style map; the
 * element carries a variant class stamped at render, and the CSS lives here:
 * every published variant's styles print once, scoped to its class, into a
 * single public file regenerated on variant save. The published set is the
 * MATERIALS ROSTER's variant records (the file carries exactly the variants
 * the validator admits, the catalog parses and the prompt teaches, because
 * all of them read the one roster); the entity behind a record loads
 * through the same identity map validation resolves references with
 * (StyleMapValidator::loadVariant()). The filename embeds a
 * content hash, so browsers cache it cross-page and every regeneration is a
 * new URL — the "daedalus_stylish/variants" library resolves the current file at
 * library-discovery time (hook_library_info_build()), which is why cached
 * renders that attached the library never go stale on the library NAME.
 *
 * Scope roots are classes, not selectable keys: per-entity @scope roots
 * would tie the shared stylesheet to every page's uuids and force a
 * recompile on every map write. The file compiles ONE CLASS PER
 * (variant, fragment) — the '' fragment is the plain variant class, a
 * distributed fragment appends its encoded slot key (fragmentClass()) —
 * and each scopes to BOTH the machine-name and uuid form because a map
 * reference may use either — render-time stamping then derives the class
 * from the stored reference verbatim, without loading variants.
 *
 * The workspace-preview edge: this file always holds the LIVE
 * (default-revision, published) variants. Inside a workspace, referenced
 * variants may carry staged revisions, so the CSS backend inlines their
 * workspace CSS per entity at the same class roots (workspaceInlineCss());
 * later in the document than the compiled <link>, it wins the cascade for
 * identical selectors. Publishing the workspace recompiles the file.
 *
 * All of the above is LIVE delivery. In edit mode a variant is a preset,
 * not a stylesheet: the CSS backend resolves references inline at their
 * data-selectable key roots instead (editModeInlineCss()) — see that
 * method for why class delivery never worked on the canvas.
 *
 * Cacheability rides the read: the three inline lanes resolve every
 * reference through resolveWorn(), which attaches what that one read
 * depends on at the read (the roster's metadata hit or miss, the
 * variant's own tags on a hit). A map without references attaches no
 * roster dependency and builds no roster; the compile path
 * (rosterVariants()) is the one build here.
 *
 * The file mechanics (content-addressed name, state key, cache tag +
 * library reset, media-recompile bookkeeping, the accepted staleness
 * class) live on CompiledStylesheetBase; a variant-side note on staleness:
 * nothing pushes a recompile into open tabs (a drupal_commands link swap
 * is deliberately deferred). compile() runs on variant
 * insert/update/delete (after the roster and repository resets, so it
 * reads the post-write set), on workspace publish, and on
 * insert/update/delete of a media entity the compiled variants reference
 * (referencesMedia()).
 */
class VariantStylesheet extends CompiledStylesheetBase {

  /**
   * Invalidated on every recompile.
   *
   * Rendered output that depends on variant delivery (stamped classes ride
   * host-entity data, but the compiled file's URL and content do not) carries
   * this tag so variant saves reach already-cached pages.
   */
  public const CACHE_TAG = 'daedalus_stylish_variants';

  /**
   * State key holding the current stylesheet URI (content-addressed).
   */
  public const STATE_KEY = 'daedalus_stylish.variant_stylesheet_uri';

  /**
   * State key holding the media uuids the published variants reference.
   *
   * Written on every compile from the RAW stored tokens (not the resolved
   * output: a payload whose media was deleted stays listed, so the delete
   * and a later restore both recompile; the base contract's reasoning).
   * Cache tags cannot bust a static file, so media insert/update/delete
   * hooks consult this list (referencesMedia()) and recompile when a
   * compiled variant references the changed media.
   */
  public const MEDIA_STATE_KEY = 'daedalus_stylish.variant_stylesheet_media';

  /**
   * The compiled file's basename prefix.
   */
  public const FILE_PREFIX = 'variants';

  /**
   * Prefix of the stamped variant class.
   */
  public const CLASS_PREFIX = 'daedalus-stylish-variant--';

  public function __construct(
    protected CssPrinter $printer,
    protected ConditionTypePluginManager $conditionTypes,
    StateInterface $state,
    FileSystemInterface $fileSystem,
    StreamWrapperManagerInterface $streamWrapperManager,
    LibraryDiscoveryInterface $libraryDiscovery,
    protected StyleMapValidator $styleMapValidator,
    protected MediaValueResolver $mediaValueResolver,
    ?WorkspaceManagerInterface $workspaceManager,
    protected MaterialsRosterBuilder $rosterBuilder,
  ) {
    parent::__construct($state, $fileSystem, $streamWrapperManager, $libraryDiscovery, $workspaceManager);
  }

  /**
   * The class stamped on an element referencing a variant.
   *
   * Derived from the reference VERBATIM (machine name or uuid — the
   * compiled stylesheet scopes each variant to both classes), so stamping a
   * single reference needs no entity loads (multi-reference entries load
   * only to ORDER the classes — StyleMapValidator::orderedEntryRefs()).
   * The charset gate is the emit-side hygiene: the reference lands inside a
   * class attribute and the stylesheet's selectors.
   *
   * @return string|null
   *   The class, or NULL when the reference cannot be a class name.
   */
  public static function variantClass(string $reference): ?string {
    return preg_match('/^[A-Za-z0-9_-]+$/', $reference) ? self::CLASS_PREFIX . $reference : NULL;
  }

  /**
   * The class stamped for one {variant, fragment} reference.
   *
   * The fragment counterpart of variantClass(), still derived from the
   * stored reference VERBATIM: the '' (root) fragment IS the plain variant
   * class, and a distributed fragment appends a deterministic encoding of
   * its slot key — '/' becomes '--', '(' and ',' become '-', ')' drops —
   * so "region(first)/block(text,1)" reads
   * "{variant-class}--region-first--block-text-1". The encoding is
   * injective: segment names are machine names (no hyphens) and a
   * reference never contains '--', so distinct (reference, fragment)
   * pairs always produce distinct classes. The charset gate mirrors
   * variantClass(): anything that cannot be a class name (a part-suffixed
   * fragment's ':' and '.', a malformed reference) stamps nothing and
   * compiles nothing — inert, like a stale reference.
   *
   * @return string|null
   *   The class, or NULL when the pair cannot be a class name.
   */
  public static function fragmentClass(string $reference, string $fragment): ?string {
    $class = static::variantClass($reference);
    if ($class === NULL || $fragment === '') {
      return $class;
    }
    $suffix = str_replace(['/', '(', ',', ')'], ['--', '-', '-', ''], $fragment);
    return preg_match('/^[A-Za-z0-9_-]+$/', $suffix) ? $class . '--' . $suffix : NULL;
  }

  /**
   * Prints every published variant's CSS.
   *
   * Deterministic (content-addressed output): the same variants always
   * produce the same file name. Blocks sort by (scope, axis-declaration
   * position, roster position): the file's block order IS the live cascade
   * among variants co-occurring on one element (they always share a scope),
   * so it must mirror the axis-declaration emission order every other
   * delivery path uses (StyleMapValidator::orderedEntryVariants()); the
   * roster-order tiebreak (weight, then label, the order every material
   * surface lists variants in) keeps the whole file stable. An axis the
   * scope no longer declares sorts after the placed axes, exactly like a
   * stale reference at stamping. Scope and axis are the record's facts;
   * the styles are the entity's, loaded through
   * StyleMapValidator::loadVariant().
   */
  public function css(): string {
    $axes_by_scope = [];
    $sortable = [];
    foreach (array_values($this->rosterVariants()) as $index => [$record, $variant]) {
      $scope = (string) $record->scope;
      $axes_by_scope[$scope] ??= $this->styleMapValidator->scopeAxes($scope);
      $axis_index = $axes_by_scope[$scope] === NULL
        ? FALSE
        : array_search($record->group, $axes_by_scope[$scope], TRUE);
      // The index tiebreak (roster order) keeps the sort stable.
      $sortable[] = [$scope, $axis_index === FALSE ? PHP_INT_MAX : $axis_index, $index, $variant];
    }
    usort($sortable, static fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
    $blocks = [];
    $uses_container = FALSE;
    foreach (array_column($sortable, 3) as $variant) {
      $block = $this->printVariant($variant);
      if ($block !== '') {
        $blocks[] = $block;
        $uses_container = $uses_container || $this->conditionTypes->mapUsesContainerConditions($variant->getStyles());
      }
    }
    // The page-level width-band query root, demand-driven: a worn variant's
    // width conditions must find their query container on live renders even
    // when the host map itself has none (the entity's inline tag only ships
    // the rule for the map's OWN width conditions), so the file that
    // carries the width-conditioned CSS carries the rule too. Same kill
    // switch as every other tier (container_roots).
    if ($uses_container && $this->conditionTypes->containerRootsEnabled()) {
      $blocks[] = CssPrinter::PAGE_CONTAINER_RULE;
    }
    return implode("\n", $blocks);
  }

  /**
   * Prints one variant's styles as @scope blocks, one class per fragment.
   *
   * The compile-path projection of the distributed-reference model: every
   * fragment the variant's styles hold (the distinct element paths among
   * its style keys — '' for the root, slot paths for composition
   * children, part suffixes riding their element's fragment) compiles to
   * its own class-rooted blocks, scoped to BOTH the machine-name and uuid
   * fragment classes because a stored reference may use either form and
   * stamping derives the class verbatim. The printing itself lives in
   * CssPrinter::printFragmentStyles() (the seam the client mirror and the
   * round-trip equivalence test lock onto), which also owns the section
   * donut for fragments addressing section roots. Fragments print in
   * stored key order — deterministic, and the order never matters in the
   * cascade: distinct fragments' classes land on distinct elements.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant
   *   The variant.
   * @param array $cache_tags
   *   Accumulator the resolved media (and image style) tags merge into —
   *   the workspace inline path passes its own through printFragment()
   *   instead. The compile path omits it: cache tags cannot bust the
   *   static file, the media-change recompile hook covers it
   *   (MEDIA_STATE_KEY).
   *
   * @return string
   *   CSS text, '' when nothing printed.
   */
  public function printVariant(DaedalusStylishVariantInterface $variant, array &$cache_tags = []): string {
    // Media tokens resolve pre-print — the printer never sees a token — with
    // the image style constraint read from the variant's own scope
    // declaration.
    $styles = $this->mediaValueResolver->resolveVariantStyles($variant->getStyles(), $variant->getScope(), $cache_tags);
    $blocks = [];
    foreach ($this->heldFragments($styles) as $fragment) {
      $roots = [];
      foreach ([$variant->getMachineName(), $variant->uuid()] as $reference) {
        $class = static::fragmentClass((string) $reference, $fragment);
        if ($class !== NULL) {
          $roots[] = '.' . $class;
        }
      }
      if ($roots === []) {
        continue;
      }
      $block = $this->printer->printFragmentStyles(implode(', ', $roots), $variant->getScope(), $styles, $fragment);
      if ($block !== '') {
        $blocks[] = $block;
      }
    }
    return implode("\n", $blocks);
  }

  /**
   * Prints ONE fragment of a variant at a caller-chosen scope root.
   *
   * The single-reference projection the inline delivery paths use: a map
   * entry's {variant, fragment} reference delivers exactly its fragment's
   * styles, rooted at the referencing key's selectable attribute (edit
   * mode) or the reference's fragment class (workspace fallback).
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant
   *   The variant.
   * @param string $fragment
   *   The reference's fragment ('' for the root).
   * @param string $scope_root
   *   The @scope prelude selector.
   * @param array $cache_tags
   *   Accumulator the resolved media (and image style) tags merge into, so
   *   a media edit invalidates the host render.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector|null $under
   *   The rule the reference sits at (ruleWearCss()), or NULL at an
   *   element: the fragment's suffixes then continue the rule by descent
   *   (CssPrinter::printFragmentStyles()).
   *
   * @return string
   *   CSS text, '' when nothing printed.
   */
  public function printFragment(DaedalusStylishVariantInterface $variant, string $fragment, string $scope_root, array &$cache_tags = [], ?RelativeSelector $under = NULL): string {
    $styles = $this->mediaValueResolver->resolveVariantStyles($variant->getStyles(), $variant->getScope(), $cache_tags);
    return $this->printer->printFragmentStyles($scope_root, $variant->getScope(), $styles, $fragment, $under);
  }

  /**
   * Inline CSS for a style map's variant references at RULE keys.
   *
   * The third delivery lane, the same in every mode: a reference on a
   * rule entry ("container:X::> pb-text" wearing a text variant) has no
   * one element to stamp a class on and no selectable attribute of its
   * own, so its fragment prints inside the rule OWNER's @scope
   * ([data-selectable="container:X"]) with the variant's suffixes
   * continued under the rule by descent (printFragment() under the
   * rule): the root entry at the rule's targets, a '::.link' entry at
   * `> pb-text .link`, a '::> *' entry at `> pb-text > *`. Class delivery
   * (the stamp, the compiled file, workspaceInlineCss()) and the edit-mode
   * element lane (editModeInlineCss()) skip rule keys; this lane prints
   * only them. The caller puts it before the map's own CSS so a rule's
   * own properties beat what it wears by order, exactly as an element's
   * do; a member's own entry beats both by @scope proximity.
   *
   * @param array $map
   *   The host entity's style map.
   * @param array $cache_tags
   *   Cache tags accumulator; each reference's dependency merges in at its
   *   resolution (resolveWorn()).
   *
   * @return string
   *   CSS text, '' without printable rule references.
   */
  public function ruleWearCss(array $map, array &$cache_tags): string {
    $blocks = [];
    foreach ($map as $key => $entry) {
      $key = (string) $key;
      $parsed = $this->styleMapValidator->parseKey($key);
      if ($parsed === NULL || $parsed['selector'] === NULL) {
        continue;
      }
      $root = substr($key, 0, strpos($key, RelativeSelector::SUFFIX));
      // Axis-declaration order, later beating earlier, as the element
      // lanes deliver (orderedEntryRefs()).
      foreach ($this->styleMapValidator->orderedEntryRefs($entry) as $ref) {
        $variant = $this->resolveWorn($ref['variant'], $cache_tags);
        if ($variant === NULL) {
          continue;
        }
        $block = $this->printFragment($variant, $ref['fragment'], sprintf('[data-selectable="%s"]', $root), $cache_tags, $parsed['selector']);
        if ($block !== '') {
          $blocks[] = $block;
        }
      }
    }
    return implode("\n", $blocks);
  }

  /**
   * The distinct fragments a variant's styles hold, in stored key order.
   *
   * A fragment is a style key's element path — the key minus its
   * '::selector' suffix — so selector entries ride their element's
   * fragment rather than forming fragments of their own. No grammar gate
   * here: printFragmentStyles() drops unparseable fragments at the emit
   * gate, the same division of labor authored map keys get.
   *
   * @return string[]
   *   The fragments, '' (the root) included when any root or root-part
   *   entry exists.
   */
  protected function heldFragments(array $styles): array {
    $fragments = [];
    foreach (array_keys($styles) as $key) {
      $key = (string) $key;
      $fragment = ($pos = strpos($key, '::')) !== FALSE ? substr($key, 0, $pos) : $key;
      $fragments[$fragment] = TRUE;
    }
    return array_keys($fragments);
  }

  /**
   * Inline CSS for a style map's variant references inside a workspace.
   *
   * The workspace-preview edge, resolved as the inline fallback: the
   * compiled file is per-site, but library assets cannot vary by workspace —
   * so inside a workspace the referenced variants' (possibly staged)
   * revisions print into the entity's own <style> tag at the same
   * variant-class scope roots, where document order beats the compiled file.
   *
   * @param array $map
   *   The host entity's style map.
   * @param array $cache_tags
   *   Cache tags accumulator; each reference's dependency merges in at its
   *   resolution (resolveWorn()), so staged edits invalidate the
   *   workspace-context render cache entries and a referenced draft
   *   becoming a material (publish) invalidates them too.
   *
   * @return string
   *   CSS text, '' outside an active workspace or without printable refs.
   */
  public function workspaceInlineCss(array $map, array &$cache_tags): string {
    if ($this->workspaceManager === NULL || !$this->workspaceManager->hasActiveWorkspace()) {
      return '';
    }
    $blocks = [];
    $uses_container = FALSE;
    foreach ($map as $key => $entry) {
      // Rule keys are the rule-wear lane's (ruleWearCss()): no element
      // carries their class, so their class-rooted CSS would reach
      // nothing.
      if (str_contains((string) $key, RelativeSelector::SUFFIX)) {
        continue;
      }
      // Axis-declaration order: each reference prints its own block, and a
      // later block beats an earlier one's identical selectors, so the
      // emission order is the deterministic vocabulary order, never the
      // application sequence (orderedEntryRefs()).
      foreach ($this->styleMapValidator->orderedEntryRefs($entry) as $ref) {
        // A reference delivers exactly its fragment, at the same
        // (variant, fragment) class root the compiled file scopes it to.
        $class = static::fragmentClass($ref['variant'], $ref['fragment']);
        if ($class === NULL) {
          continue;
        }
        // Loads resolve to the active workspace's revisions (workspaces
        // swaps storage handlers and alters entity queries).
        $variant = $this->resolveWorn($ref['variant'], $cache_tags);
        if ($variant === NULL) {
          continue;
        }
        $block = $this->printFragment($variant, $ref['fragment'], '.' . $class, $cache_tags);
        if ($block !== '') {
          $blocks[] = $block;
          $uses_container = $uses_container || $this->conditionTypes->mapUsesContainerConditions($variant->getStyles());
        }
      }
    }
    $blocks = array_unique($blocks);
    // The workspace mirror of css()'s page-container rule: a STAGED width
    // condition may exist in no live variant, so the compiled file cannot
    // be relied on to establish the query root, so the inline fallback ships
    // its own copy (idempotent when both emit it).
    if ($uses_container && $this->conditionTypes->containerRootsEnabled()) {
      $blocks[] = CssPrinter::PAGE_CONTAINER_RULE;
    }
    return implode("\n", $blocks);
  }

  /**
   * Inline CSS resolving a style map's variant references in edit mode.
   *
   * In edit mode a variant is a preset, not a stylesheet: the editing spine
   * (map mirror, client printer, inline per-entity @scope tag) addresses
   * elements by their data-selectable keys, and class stamping never reaches
   * the canvas (sections build inside the canvas element's #pre_render,
   * after hook_entity_view's walk). So each referenced variant's styles
   * print here rooted at the REFERENCING KEY's selectable attribute — no
   * classes, no compiled-file dependency. The compiled <link> may stay
   * attached; this tag is later in the document and wins the cascade for
   * identical selectors, the same trick as the workspace fallback. Loads
   * resolve to the active workspace's revisions when one is active, so the
   * workspace edge is covered in edit mode too.
   *
   * @param array $map
   *   The host entity's style map.
   * @param array $cache_tags
   *   Cache tags accumulator; each reference's dependency merges in at its
   *   resolution (resolveWorn()), so variant edits and publishes invalidate
   *   the host's render cache entries.
   *
   * @return string
   *   CSS text, '' without printable references.
   */
  public function editModeInlineCss(array $map, array &$cache_tags): string {
    $blocks = [];
    foreach ($map as $key => $entry) {
      $key = (string) $key;
      // Rule keys are the rule-wear lane's (ruleWearCss(), every mode):
      // they have no selectable attribute of their own to root at. The
      // charset gate is the emit-side hygiene: the key lands inside the
      // @scope prelude's attribute selector.
      if (str_contains($key, RelativeSelector::SUFFIX) || !preg_match('/^[A-Za-z0-9:_-]+$/', $key)) {
        continue;
      }
      // Axis-declaration order: each reference prints its own block, and a
      // later block lands later in the document, beating an earlier one's
      // identical selectors — so the cascade among co-occurring variants is
      // the deterministic vocabulary order, never the application sequence
      // (orderedEntryRefs()). A reference delivers exactly its fragment's
      // styles at the referencing key: composition children are ordinary
      // entries wearing their own {variant, fragment} references.
      foreach ($this->styleMapValidator->orderedEntryRefs($entry) as $ref) {
        $variant = $this->resolveWorn($ref['variant'], $cache_tags);
        if ($variant === NULL) {
          continue;
        }
        $block = $this->printFragment($variant, $ref['fragment'], sprintf('[data-selectable="%s"]', $key), $cache_tags);
        if ($block !== '') {
          $blocks[] = $block;
        }
      }
    }
    return implode("\n", $blocks);
  }

  /**
   * Resolves one worn reference for delivery, attaching what the read owes.
   *
   * The inline lanes' one read of the roster, and the one place a render's
   * dependency on it is merged: the roster's cache metadata on every call,
   * hit or miss, because a reference the roster refuses today (a draft, a
   * not-yet-minted name) prints nothing and loads nothing, so only the
   * roster's list tag can reach the render that a publish must
   * invalidate; then the variant's own tags on a hit, so its edits reach
   * the render too. Resolution itself is StyleMapValidator::loadVariant()
   * (identity map plus roster membership), so delivery and validation
   * resolve references identically; the roster builds inside that
   * membership read, and only there, so a map without references never
   * builds it. The metadata is the builder's own derivation
   * (MaterialsRosterBuilder::cacheMetadata()), answered without a build.
   */
  protected function resolveWorn(string $reference, array &$cache_tags): ?DaedalusStylishVariantInterface {
    $cache_tags = Cache::mergeTags($cache_tags, $this->rosterBuilder->cacheMetadata()->getCacheTags());
    $variant = $this->styleMapValidator->loadVariant($reference);
    if ($variant !== NULL) {
      $cache_tags = Cache::mergeTags($cache_tags, $variant->getCacheTags());
    }
    return $variant;
  }

  /**
   * The roster's variants paired with their entities, in roster order.
   *
   * The compile path's one read of the published set: each variant record
   * of the roster (built for the compile's window, the live one under
   * executeOutsideWorkspace()) with the entity
   * StyleMapValidator::loadVariant() resolves for its id. A record whose
   * entity fails to load is skipped: the roster and the identity map read
   * the same storage in the same window, so that is a mid-request race,
   * not a state to compile.
   *
   * @return array<int, array{\Drupal\daedalus_blueprint\Materials\MaterialRecord, \Drupal\daedalus_stylish\DaedalusStylishVariantInterface}>
   *   [record, entity] pairs.
   */
  protected function rosterVariants(): array {
    $pairs = [];
    foreach ($this->rosterBuilder->build()->variants() as $record) {
      $variant = $this->styleMapValidator->loadVariant($record->id);
      if ($variant !== NULL) {
        $pairs[] = [$record, $variant];
      }
    }
    return $pairs;
  }

  /**
   * Scans the published variants' RAW styles for media token payloads.
   *
   * A second pass over the same variants css() prints (the base contract's
   * reasoning: the list gates the media hooks, so a payload whose media
   * was deleted since the variant was judged stays on it).
   *
   * @return string[]
   *   Lowercased token payloads, deduplicated.
   */
  protected function referencedMediaUuids(): array {
    $uuids = [];
    foreach ($this->rosterVariants() as [, $variant]) {
      foreach ($variant->getStyles() as $entry) {
        if (!is_array($entry)) {
          continue;
        }
        foreach ($entry as $properties) {
          if (!is_array($properties)) {
            continue;
          }
          foreach ($properties as $value) {
            if (is_scalar($value) && MediaToken::mentions((string) $value)) {
              array_push($uuids, ...MediaToken::extract((string) $value));
            }
          }
        }
      }
    }
    return array_values(array_unique(array_map('strtolower', $uuids)));
  }

}
