<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Compiles the site's defaults into one shared stylesheet file.
 *
 * The defaults counterpart of VariantStylesheet: everything an element
 * wears BEFORE its own styles, printed once into a single hash-busted
 * public file the "daedalus_stylish/defaults" library resolves at library-discovery
 * time, exactly like the variants library. Two tiers print, in this order:
 *
 * - THE DECLARED TIER (code, always): every declaration's own `defaults`
 *   (StyleGuide::declaredDefaults(): the literal box facts the markup a
 *   declaration owns needs, token-free, validated at guide build; a
 *   refinement's root and parts, a composed kind's root, any kind's
 *   declared parts). Printed FIRST and with NO skin gate: a site with no
 *   active design system, a system carrying no skin, and the recipe
 *   window before activation all serve it, because a block's box is the
 *   block's fact, not the site's look. Element scopes only, in delivery
 *   rank order (kind scopes, then refinements: block plugins, block
 *   types, layouts; stored order within), each an @scope block at its
 *   stamped scope class through the same printer path the skin's element
 *   defaults ride.
 * - THE SKIN'S TIERS (data, only with an active PUBLISHED skin): the
 *   skin's token values, as CSS custom property declarations on the TOKEN
 *   SCOPE ELEMENT (the MATERIALS ROSTER's token records: the active
 *   skin's minted tokens on the declared scales, in declared scale order,
 *   so the file carries exactly the tokens the validator admits and the
 *   prompt teaches), then its site defaults, one block per scope in
 *   DELIVERY RANK order (base:{tag} scopes, then kind scopes, then
 *   refinements, stored order within a rank), because a refined element
 *   wears BOTH its kind's and its refinement's scope class and at
 *   identical specificity document order must let the refinement win.
 *
 * The declared tier prints entirely beneath the skin's tiers at the same
 * :scope specificity, so document order lets any skin scope for the same
 * target win, base tags included: the block ships the floor, the skin is
 * the site's look. Two delivery shapes for defaults:
 * - base:{tag} (skin only): bare rules at ':where(.daedalus-stylish-token-scope)
 *   :where(tag)', inside the token scope element so prose defaults never
 *   restyle admin chrome outside the page canvas, UNSCOPED (no @scope) and
 *   FULLY :where()-wrapped (scope class included) on purpose: at zero
 *   specificity the element tag tier's bare in-scope tag (0-0-1) beats a
 *   base default outright, and scoped declarations beat unscoped ones on
 *   scoping proximity at equal specificity, so every scoped path
 *   (per-entity maps, variants, element defaults) overrides a base default
 *   regardless of document order.
 * - element scopes (both tiers): @scope blocks rooted at the scope CLASS
 *   the render seams stamp on demand (scopeClass(); daedalus_stylish.module's
 *   entity-view, layout, block and field stamps), prefixed by the token
 *   scope element so the SAME boundary bounds every tier: a stamped class
 *   outside the scope element (admin theme, dialogs, non-builder layout
 *   renders) is inert. Core markup carries no bundle classes, so delivery
 *   stamps its own, demand-driven per scope against the UNION of the two
 *   tiers' scopes (scopes()), except that the two FAMILY kind classes
 *   (kind:container on every section, kind:block on every block) stamp
 *   whenever EITHER tier holds any element defaults: they double as the
 *   donut boundary (DEFAULTS_SCOPE_LIMIT), which must exist wherever any
 *   defaults block could leak into nested identical markup. Per-entity
 *   styles, wearing the same 0,1,0 specificity later in the document (the
 *   entity's inline style tag), override every default; unsetting an
 *   override reveals the default again: LIVE semantics, per decision.
 *
 * THE DELIVERY THEME: declarations layer per theme (a theme may restate
 * a module's declared defaults: it owns the markup model), and the file
 * is one per site, so the declared tier compiles for the site's DEFAULT
 * theme (system.theme), never the request's active theme: compiles run
 * from admin routes too, where the active theme is the admin theme, and
 * admin-theme pages are outside the file's reach anyway. The stamps read
 * the active theme's layering at render, which is the default theme on
 * every page the file reaches.
 *
 * THE TOKEN SCOPE ELEMENT (.daedalus-stylish-token-scope): tokens deliver on a
 * dedicated wrapper element daedalus_stylish stamps just inside the off-canvas page
 * canvas (daedalus_stylish_preprocess_off_canvas_page_wrapper()), never on :root.
 * Width-conditioned token values compile as @container queries, NEVER
 *
 * @media, because the edit-mode responsive canvas is a constrained div
 * (#daedalus-ui-stage): @media reads the real viewport and would not
 * react to the posed canvas width, the same reason container breakpoint
 * conditions are @container (see ContainerBreakpoint). Container queries
 * cannot style the query container itself (custom properties included), so
 * the definitions need an element just INSIDE the width container:
 * - Edit mode: the stage is the width container (daedalus_ui declares
 *   container-type on it in any mode), and the scope wrapper renders inside
 *   it (daedalus_stylish's preprocess is ordered before daedalus_ui's, see
 *   daedalus_stylish_theme_registry_alter()), so posing a canvas width flips
 *   width-conditioned tokens live. The token thresholds are the same
 *   container-breakpoint conditions the canvas ruler already marks
 *   (js/src/canvas-stops.js registers them on DaedalusUiCanvasStops
 *   from the guide payload), so token flip points need no registration of
 *   their own.
 * - Live: no stage exists, so when any tier holds width-conditioned values
 *   the compiled file ships the shared page-container rule making the
 *   off-canvas canvas the page-level width container
 *   (CssPrinter::PAGE_CONTAINER_RULE; the constant's docblock owns the
 *   canvas-not-body reasoning). Emission is demand-driven (only when
 *   width-conditioned values exist) and honors the kill switch
 *   (daedalus_stylish.settings container_roots) for themes that manage containment
 *   themselves. The canvas width is the page's true available width (core's
 *   toolbar displacement is a margin on it), which is what tokens should
 *   answer to.
 *
 * Scheme/motion-conditioned values stay @media (user-preference axes); the
 * canvas vector's emulation rewrites those conditions across all same-origin
 * stylesheets (emulate-media.js), compiled file included, so the scheme and
 * motion chips flip tokens on the canvas too.
 *
 * The workspace-preview edge: this file always holds the LIVE
 * (default-revision, published) skin. Inside a workspace the active skin may
 * carry a staged revision, so its workspace CSS inlines per page at the same
 * scope-class selectors (workspaceInlineCss(), embedded by
 * daedalus_stylish_page_top()); later in the document than the compiled <link>, it
 * wins the cascade for identical selectors. Publishing the workspace
 * recompiles the file (SkinWorkspaceSubscriber). The declared tier is code
 * and stages nothing; it rides the one print path in the preview too, so
 * the two can never emit different CSS for the same revision.
 *
 * The file mechanics (content-addressed name, state key, cache tag +
 * library reset, media-recompile bookkeeping, the accepted staleness
 * class) live on CompiledStylesheetBase. compile() runs on every event
 * that can change either tier: skin insert/update/delete (daedalus_stylish entity
 * hooks), a design-system switch (DesignSystemSwitchSubscriber: the active
 * skin is the active system's default_skin, and a package's declarations
 * layer only while its system is active), every daedalus_stylish.settings save and
 * a default-theme change (DefaultsSettingsSubscriber: the breakpoint
 * thresholds baked into @container wrappers, the container_roots switch,
 * and the delivery theme), workspace publish (SkinWorkspaceSubscriber),
 * changes to a media entity the compiled skin defaults reference
 * (referencesMedia()), and every declaration change: module install and
 * uninstall, theme install and uninstall, and the cache rebuild
 * (daedalus_stylish.module's extension hooks and daedalus_stylish_rebuild()). Each resets
 * what it invalidates (the roster, the guide) before compiling, and the
 * content-addressed name makes an unchanged recompile idempotent.
 */
class DefaultsStylesheet extends CompiledStylesheetBase {

  /**
   * Invalidated on every recompile.
   *
   * Rendered pages attach the "daedalus_stylish/defaults" library and carry this
   * tag (daedalus_stylish_page_top()); stamped fragments carry it too
   * (_daedalus_stylish_stamp_defaults_scope_classes()), so skin saves,
   * design-system switches and declaration changes reach already-cached
   * pages.
   */
  public const CACHE_TAG = 'daedalus_stylish_defaults';

  /**
   * State key holding the current stylesheet URI (content-addressed).
   */
  public const STATE_KEY = 'daedalus_stylish.defaults_stylesheet_uri';

  /**
   * The compiled file's basename prefix.
   */
  public const FILE_PREFIX = 'defaults';

  /**
   * The token scope element's class.
   *
   * Stamped on the wrapper daedalus_stylish_preprocess_off_canvas_page_wrapper()
   * renders around the page, and the selector every token block scopes to.
   */
  public const SCOPE_CLASS = 'daedalus-stylish-token-scope';

  /**
   * The class prefix of stamped defaults scope classes (scopeClass()).
   */
  public const SCOPE_CLASS_PREFIX = 'daedalus-stylish-defaults--';

  /**
   * The @scope limit for container-family defaults blocks.
   *
   * CssPrinter::CONTAINER_SCOPE_LIMIT extended with the stamped family
   * kind classes: the selectable-key stamps the variant donut relies on
   * are demand-driven per entity and absent from most live markup, while
   * the family classes stamp on EVERY container and block whenever either
   * tier carries any element defaults, so they are the boundary the
   * compiled defaults can actually rely on. The key stamps stay in the
   * list for edit mode, where they are always present.
   */
  public const DEFAULTS_SCOPE_LIMIT = CssPrinter::CONTAINER_SCOPE_LIMIT
    . ', :scope .' . self::SCOPE_CLASS_PREFIX . 'kind-container'
    . ', :scope .' . self::SCOPE_CLASS_PREFIX . 'kind-block';

  /**
   * State key holding the media uuids the compiled skin defaults reference.
   *
   * The defaults counterpart of VariantStylesheet::MEDIA_STATE_KEY: media
   * tokens in skin defaults resolve to concrete file URLs baked into the
   * static file, and cache tags cannot bust it, so media change hooks
   * consult this list (referencesMedia()) and recompile. Declared defaults
   * are code and name no site content, so only the skin's are scanned.
   */
  public const MEDIA_STATE_KEY = 'daedalus_stylish.defaults_stylesheet_media';

  public function __construct(
    protected CssPrinter $printer,
    protected SkinManager $skinManager,
    protected ConditionTypePluginManager $conditionTypes,
    protected MaterialsRosterBuilder $rosterBuilder,
    protected StyleGuide $styleGuide,
    protected ConfigFactoryInterface $configFactory,
    StateInterface $state,
    FileSystemInterface $fileSystem,
    StreamWrapperManagerInterface $streamWrapperManager,
    LibraryDiscoveryInterface $libraryDiscovery,
    protected MediaValueResolver $mediaValueResolver,
    ?WorkspaceManagerInterface $workspaceManager,
  ) {
    parent::__construct($state, $fileSystem, $streamWrapperManager, $libraryDiscovery, $workspaceManager);
  }

  /**
   * The class stamped on elements of a defaults scope, and its selector root.
   *
   * The ONE derivation between the stamping seams (daedalus_stylish.module) and the
   * compiled selectors here: 'daedalus-stylish-defaults--' + the root address with
   * ':' folded to '-', injective because address families contain no
   * hyphens and ids no colons. base:{tag} scopes stamp nothing (the tag
   * IS the selector) and derive no class. The address grammar is the
   * emit-side hygiene: the scope lands inside class attributes and
   * selectors, and only a root address (Address::parse(), no part, no
   * children) prints there.
   *
   * @return string|null
   *   The class, or NULL for base scopes and anything that is not a root
   *   address.
   */
  public static function scopeClass(string $scope): ?string {
    $address = Address::parse($scope);
    if ($address === NULL || $address->isBase() || !$address->isRoot()) {
      return NULL;
    }
    return self::SCOPE_CLASS_PREFIX . $address->family . '-' . $address->id;
  }

  /**
   * The scopes the compiled defaults carry rules for: the stamping demand.
   *
   * The stamping seams' ONE read (daedalus_stylish.module's entity-view and
   * preprocess stamps): scope classes are demand-driven, so every stamp
   * decision asks "does either tier default this scope?" here. The union
   * of the declared tier's scopes (the active theme's layering, matching
   * the markup being rendered) and the active skin's
   * (SkinManager::activeDefaultScopes(), which inside a workspace answers
   * for the staged revision, so the stamped markup always matches the CSS
   * the page previews).
   *
   * @return string[]
   *   The scope strings, declared first, then the skin's in stored order,
   *   deduplicated; [] when neither tier holds defaults.
   */
  public function scopes(): array {
    return array_values(array_unique(array_merge(
      array_keys($this->styleGuide->declaredDefaults()),
      $this->skinManager->activeDefaultScopes(),
    )));
  }

  /**
   * Whether the defaults scopes include any ELEMENT scope.
   *
   * Base:{tag} defaults deliver on tag selectors and need no stamped
   * classes; every other scope does. The family kind classes (and with
   * them the donut boundary) stamp exactly when this is TRUE: the one
   * demand rule every stamping seam (entity view, plugin block, templated
   * layout, composed section) asks.
   *
   * @param string[] $scopes
   *   The scopes, as scopes() returns them.
   */
  public static function hasElementDefaults(array $scopes): bool {
    foreach ($scopes as $scope) {
      if (!str_starts_with((string) $scope, 'base:')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Prints the defaults stylesheet: the declared tier, then the live skin.
   *
   * Deterministic (content-addressed output): the same declarations and
   * skin always produce the same file. Only the ACTIVE skin's tiers
   * compile (inactive skins are held looks, not delivered CSS) and only
   * its published default revision, the posture variants take (the roster
   * serves no tokens for an unpublished active skin either). The declared
   * tier needs no skin at all.
   *
   * @return string
   *   CSS text, '' when neither tier prints anything.
   */
  public function css(): string {
    $skin = $this->skinManager->loadActiveSkin();
    return $this->print($skin !== NULL && $skin->isPublished() ? $skin : NULL);
  }

  /**
   * Prints the declared tier and, with a skin, its tokens and defaults.
   *
   * The one printing path behind the compiled file and the workspace inline
   * preview, so the two can never emit different CSS for the same revision.
   * The declared tier prints first (defaultsCss() over the guide's
   * declaredDefaults() for the delivery theme), then the skin's tokens (an
   * `@scope` block on the token scope element, read as the roster's token
   * records: the roster is built for the calling window, so the compile
   * prints the live skin's tokens and the workspace preview the staged
   * ones), then the skin's defaults blocks in delivery rank order from the
   * entity. Reuses the CssPrinter: the emit gate and the condition wrapper
   * composition (at-rules nest, compounds compose) are the same machinery
   * every style map rides.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface|null $skin
   *   The active published skin (the one the roster's tokens are read
   *   from), or NULL to print the declared tier alone.
   * @param array $cache_tags
   *   Accumulator the resolved media (and image style) tags merge into;
   *   the workspace inline path passes its own through. The compile path
   *   omits it: cache tags cannot bust the static file; the media-change
   *   recompile hook covers it (MEDIA_STATE_KEY).
   *
   * @return string
   *   CSS text, '' when nothing printed.
   */
  public function print(?DaedalusStylishSkinInterface $skin, array &$cache_tags = []): string {
    $blocks = [];
    $uses_container = FALSE;
    foreach ($this->orderedDefaults($this->styleGuide->declaredDefaults($this->deliveryTheme())) as $scope => $styles) {
      $css = $this->printDefaultsScope($scope, $styles, $cache_tags);
      if ($css !== '') {
        $blocks[] = $css;
        $uses_container = $uses_container || $this->stylesUseContainerConditions($styles);
      }
    }
    if ($skin !== NULL) {
      $entry = $this->tokensEntry($this->rosterBuilder->build()->tokens());
      if ($entry !== []) {
        $css = $this->printer->printEntry('.' . self::SCOPE_CLASS, NULL, $entry);
        if ($css !== '') {
          $blocks[] = $css;
          $uses_container = $uses_container || $this->usesContainerConditions($entry);
        }
      }
      foreach ($this->orderedDefaults($skin->getDefaults()) as $scope => $styles) {
        $css = $this->printDefaultsScope($scope, $styles, $cache_tags);
        if ($css !== '') {
          $blocks[] = $css;
          $uses_container = $uses_container || $this->stylesUseContainerConditions($styles);
        }
      }
    }
    if ($blocks === []) {
      return '';
    }
    $css = implode("\n", $blocks);
    // The live page-level width container, demand-driven: only a tier that
    // actually conditions values on width needs one, and the kill
    // switch (container_roots) lets a theme own containment itself. The
    // shared constant's docblock owns the canvas-not-body reasoning.
    if ($uses_container && $this->conditionTypes->containerRootsEnabled()) {
      $css .= "\n" . CssPrinter::PAGE_CONTAINER_RULE;
    }
    return $css;
  }

  /**
   * The theme whose declaration layering the compiled file serves.
   *
   * The site's default theme (see the class docblock); NULL, the guide's
   * active-theme fallback, only where no default theme is configured (a
   * kernel test without system config).
   */
  protected function deliveryTheme(): ?string {
    $default = $this->configFactory->get('system.theme')->get('default');
    return is_string($default) && $default !== '' ? $default : NULL;
  }

  /**
   * A defaults map in delivery rank order.
   *
   * Base:{tag} scopes first, kind scopes second, refinements
   * (layout:{id}, block_type:{bundle}, block_plugin:{plugin_id}) last,
   * stored order within a rank: a refined element wears its kind's AND its
   * refinement's scope class, and at identical :scope specificity document
   * order must let the refinement win, the only ordering that matches what
   * the layered data means (the same reasoning as tokensEntry()'s
   * compound-last rule). Both tiers ride it: the guide already yields the
   * declared tier in this rank order (kinds, then block plugins, block
   * types, layouts), so the sort is a restatement there and the law for
   * the skin's stored map.
   *
   * @return array
   *   scope => element-relative styles, non-array entries dropped (inert,
   *   the emit-gate posture).
   */
  protected function orderedDefaults(array $defaults): array {
    $rank = static fn (string $scope): int => match (Address::parse($scope)?->family) {
      Address::FAMILY_BASE => 0,
      Address::FAMILY_KIND => 1,
      default => 2,
    };
    $sortable = [];
    $index = 0;
    foreach ($defaults as $scope => $styles) {
      if (is_array($styles)) {
        $sortable[] = [$rank((string) $scope), $index++, (string) $scope, $styles];
      }
    }
    usort($sortable, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $ordered = [];
    foreach ($sortable as [, , $scope, $styles]) {
      $ordered[$scope] = $styles;
    }
    return $ordered;
  }

  /**
   * Prints one defaults scope's styles.
   *
   * Media tokens resolve pre-print exactly like variant styles (the
   * resolver reads the image-style constraint from the scope's own
   * declaration); the printer never sees a token. Two shapes per the class
   * docblock: base scopes print bare :where(tag) rules inside the token
   * scope element, element scopes print @scope blocks at their stamped
   * scope class (token-scope-prefixed) with the class-extended donut. Both
   * tiers ride this one path; the declared tier only ever carries element
   * scopes (the guide admits nothing else).
   *
   * @return string
   *   CSS text, '' when nothing printed.
   */
  protected function printDefaultsScope(string $scope, array $styles, array &$cache_tags): string {
    $address = Address::parse($scope);
    if ($address === NULL) {
      return '';
    }
    $styles = $this->mediaValueResolver->resolveVariantStyles($styles, $scope, $cache_tags);
    if ($address->isBase()) {
      $tag = $address->id;
      // The closed vocabulary is the emit gate too: the tag prints into a
      // live selector, so anything outside it emits nothing (inert data,
      // like an uninstalled condition's blocks).
      if (!in_array($tag, StyleGuide::BASE_TAGS, TRUE)) {
        return '';
      }
      // The whole selector rides inside :where() so the base tier really
      // is zero-specificity: a raw scope-class prefix would put base
      // defaults at 0-1-0, beating the element tag tier's bare in-scope
      // tag (0-0-1) and silently inverting the three-tier text cascade.
      // The class still bounds delivery (prose defaults never restyle
      // admin chrome outside the page canvas); it just carries no weight.
      $selector = ':where(.' . self::SCOPE_CLASS . ') :where(' . $tag . ')';
      $blocks = [];
      foreach ($styles as $relative => $entry) {
        $relative = (string) $relative;
        if (!is_array($entry)) {
          continue;
        }
        $sub = NULL;
        if ($relative !== '') {
          if (!preg_match('/^::(\.[A-Za-z0-9_-]+)$/', $relative, $matches)) {
            continue;
          }
          $sub = $matches[1];
        }
        $block = $this->printer->printBareEntry($selector, $sub, $entry);
        if ($block !== '') {
          $blocks[] = $block;
        }
      }
      return implode("\n", $blocks);
    }
    $class = static::scopeClass($scope);
    if ($class === NULL) {
      return '';
    }
    // The token scope element prefixes the @scope root so the boundary the
    // base tier already honors bounds element defaults too: defaults CSS
    // can only apply inside the scope element, wherever the stamped
    // classes end up (admin-theme pages, dialogs attached to <body>,
    // non-builder layout renders). The prefix narrows which elements
    // qualify as roots without touching the inner rules' specificity or
    // proximity.
    return $this->printer->printFragmentStyles('.' . self::SCOPE_CLASS . ' .' . $class, $scope, $styles, '', self::DEFAULTS_SCOPE_LIMIT);
  }

  /**
   * Whether any of one scope's default entries use a container condition.
   */
  protected function stylesUseContainerConditions(array $styles): bool {
    foreach ($styles as $entry) {
      if (is_array($entry) && $this->usesContainerConditions($entry)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Inline CSS for the active skin's staged revision inside a workspace.
   *
   * The workspace-preview edge at page level: the compiled file is
   * per-site and library assets cannot vary by workspace, so inside a
   * workspace the active skin's (possibly staged) revision prints into a
   * page-level <style> tag (daedalus_stylish_page_top()) at the same scope-class
   * selectors, where document order beats the compiled file. The one print
   * path: the declared tier rides along (identical to the file's, inert by
   * repetition), the skin's tiers are the staged ones. Without a published
   * active skin there is nothing staged to preview: the declared tier is
   * code and the file already serves it.
   *
   * @param array $cache_tags
   *   Cache tags accumulator; the skin's tags merge in so staged edits
   *   invalidate the workspace-context page cache entries.
   *
   * @return string
   *   CSS text, '' outside an active workspace or without a printable skin.
   */
  public function workspaceInlineCss(array &$cache_tags): string {
    if ($this->workspaceManager === NULL || !$this->workspaceManager->hasActiveWorkspace()) {
      return '';
    }
    // Loads resolve to the active workspace's revisions (workspaces swaps
    // storage handlers and alters entity queries).
    $skin = $this->skinManager->loadActiveSkin();
    if ($skin === NULL) {
      return '';
    }
    $cache_tags = Cache::mergeTags($cache_tags, $skin->getCacheTags());
    if (!$skin->isPublished()) {
      return '';
    }
    return $this->print($skin, $cache_tags);
  }

  /**
   * Scans the active skin's RAW defaults for media token payloads.
   *
   * Tokens (custom property values) never carry media tokens, and declared
   * defaults are code that names no site content: only the skin's defaults
   * entries are scanned. The stored-not-resolved reasoning lives on the
   * base contract.
   *
   * @return string[]
   *   Lowercased token payloads, deduplicated.
   */
  protected function referencedMediaUuids(): array {
    $skin = $this->skinManager->loadActiveSkin();
    if ($skin === NULL || !$skin->isPublished()) {
      return [];
    }
    $uuids = [];
    foreach ($skin->getDefaults() as $styles) {
      if (!is_array($styles)) {
        continue;
      }
      foreach ($styles as $entry) {
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

  /**
   * Projects the roster's token records into one condition-keyed entry.
   *
   * Records become {condition: {--variable: value}}: each token's custom
   * property is its own style spelling read back (MaterialsSchema::
   * customProperty(), the var() the provider spelled through the ONE
   * derivation rule, StyleGuide::tokenVariable()), unconditional values
   * land under "default", condition-keyed values under their
   * (canonicalized) condition keys. Records come in roster order (declared
   * scale order, tokens in stored order, the site theme's scales), so the
   * output is deterministic regardless of the stored scale order; a token
   * on a scale the site no longer declares is not a material and prints
   * nothing: inert data, the same degradation an uninstalled condition
   * plugin gets at the emit gate.
   *
   * Condition blocks order for the cascade: "default" first, then single
   * conditions in vocabulary order, then compounds (by part count, then
   * part positions). Distinct conditions on one axis never co-apply, but a
   * compound co-applies with each of its parts, so emitting compounds LAST
   * makes the more specific value win by document order ("narrow+dark"
   * beats "narrow" and "dark" when both hold), the only ordering that
   * matches what the layered data means.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $tokens
   *   The roster's token records.
   *
   * @return array
   *   The condition-keyed entry, [] when nothing survives.
   */
  protected function tokensEntry(array $tokens): array {
    $blocks = [];
    foreach ($tokens as $token) {
      $variable = MaterialsSchema::customProperty($token);
      $value = $token->value;
      if (is_scalar($value)) {
        $blocks['default'][$variable] = (string) $value;
        continue;
      }
      foreach ((array) $value as $condition => $conditioned) {
        if (!is_scalar($conditioned)) {
          continue;
        }
        // Spine writes store canonical compound spellings; canonicalizing
        // again here folds any map written around them into one block per
        // condition instead of forking on spelling.
        $condition = (string) $condition;
        if ($condition !== 'default') {
          $condition = $this->conditionTypes->canonicalizeCondition($condition);
        }
        $blocks[$condition][$variable] = (string) $conditioned;
      }
    }
    if ($blocks === []) {
      return [];
    }
    $order = array_flip($this->conditionTypes->getConditionNames());
    uksort($blocks, fn (string $a, string $b): int => $this->conditionSortKey($a, $order) <=> $this->conditionSortKey($b, $order));
    return $blocks;
  }

  /**
   * The sort key ordering a condition block for emission.
   *
   * @param string $condition
   *   The condition key ("default", "narrow", "narrow+dark").
   * @param array $order
   *   Condition name => vocabulary position.
   *
   * @return array
   *   [part count (0 for "default"), sorted part positions]; unknown parts
   *   sort last among their count class, deterministically.
   */
  protected function conditionSortKey(string $condition, array $order): array {
    if ($condition === 'default') {
      return [0, []];
    }
    $positions = array_map(
      static fn (string $part): int => $order[$part] ?? PHP_INT_MAX,
      ConditionTypePluginManager::compoundParts($condition),
    );
    sort($positions);
    return [count($positions), $positions];
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

}
