<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
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
 *   types, layouts; stored order within), through the same printer path
 *   the skin's element defaults ride.
 * - THE SKIN'S TIERS (data, only with an active PUBLISHED skin): the
 *   skin's token values, as CSS custom property declarations on the TOKEN
 *   SCOPE ELEMENT (the MATERIALS ROSTER's token records: the active
 *   skin's minted tokens on the declared scales, in declared scale order,
 *   so the file carries exactly the tokens the validator admits and the
 *   prompt teaches), then its site defaults, one block per scope in
 *   DELIVERY RANK order (base:{tag} scopes, then kind scopes, then
 *   refinements, stored order within a rank), because a refined element
 *   matches BOTH its kind's and its refinement's selector and at
 *   identical specificity document order must let the refinement win.
 *
 * The declared tier prints entirely beneath the skin's tiers at the same
 * specificity, so document order lets any skin scope for the same target
 * win, base tags included: the block ships the floor, the skin is the
 * site's look.
 *
 * THE WHOLE TIER PRINTS BARE AT 0-0-0 (CssPrinter::printBareEntry): plain
 * rules OUTSIDE any @scope, every selector fully :where()-wrapped, inside
 * the token scope element so defaults never restyle admin chrome outside
 * the page canvas. A default is the floor under every authored rule, and
 * zero specificity is what makes that true structurally: every authored
 * path (a per-entity @scope block, a compiled variant, an ancestor's rule
 * for its descendants, the element tag tier's bare in-scope tag at 0-0-1)
 * beats a default by specificity alone, and @scope proximity only ever
 * orders authored rules among themselves. A default in its own @scope at
 * the element would sit at 0-1-0 and, by the cascade's proximity step,
 * beat an ancestor's equal-weight rule for that very element, putting a
 * default above an authored rule. Beating the theme's own CSS is the
 * cascade-layers sub-module's job, not specificity's. Unsetting an
 * authored override reveals the default again: LIVE semantics, per
 * decision. Two selector shapes:
 * - base:{tag}: ':where(.daedalus-stylish-token-scope) :where(tag)', the
 *   tag being the selector.
 * - element scopes (both tiers): ':where(.daedalus-stylish-token-scope
 *   [data-element="{scope}"])' (elementSelector()), selecting by the
 *   element address daedalus_ui stamps UNCONDITIONALLY on every addressable
 *   root in every mode (ElementAddress: the declaration the element
 *   resolved through, spelled exactly as the scope). A kind scope selects
 *   every address of that kind (kind:block is the block_type: and
 *   block_plugin: families, kind:container the layout: family). Nothing
 *   here demands a stamp and nothing bounds a scope from below: a default
 *   is the same value on every instance of its scope, so reaching nested
 *   identical anatomy changes nothing. Part entries print as :where()
 *   descendants, children entries as ' > *'.
 *
 * THE DELIVERY THEME: declarations layer per theme (a theme may restate
 * a module's declared defaults: it owns the markup model), and the file
 * is one per site, so the declared tier compiles for the site's DEFAULT
 * theme (system.theme), never the request's active theme: compiles run
 * from admin routes too, where the active theme is the admin theme, and
 * admin-theme pages are outside the file's reach anyway.
 *
 * THE TOKEN SCOPE ELEMENT (.daedalus-stylish-token-scope): tokens deliver on a
 * dedicated wrapper element daedalus_stylish stamps just inside the off-canvas page
 * canvas (daedalus_stylish_preprocess_off_canvas_page_wrapper()), never on :root.
 * The file's ONE :root rule is not a token: the scheme declaration
 * (CssPrinter::COLOR_SCHEME_RULE, `color-scheme: light dark`), appended
 * on demand when any condition on the skin's tokens or the skin's
 * defaults is a scheme condition, so the UA's own chrome (the viewport
 * scrollbar, native form controls) follows the scheme the skin's values
 * follow; the viewport reads color-scheme from the root element alone,
 * which the scope element cannot reach. The declared tier does not feed
 * that detection (a module's dark-conditioned box fact says nothing about
 * the SITE's scheme), and neither do swatches or variants (a dark palette
 * face does not flip the page floor).
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
 * selectors (workspaceInlineCss(), printed inside the token scope element
 * by daedalus_stylish_preprocess_off_canvas_page_wrapper()); later in the
 * document than the compiled <link>, it
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
   * tag (daedalus_stylish_page_attachments()), so skin saves, design-system
   * switches and declaration changes reach already-cached pages.
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
   * The zero-specificity selector an element-scope default delivers on.
   *
   * The ONE derivation between the element address daedalus_ui stamps
   * (ElementAddress, `data-element="{family}:{id}"`) and the compiled
   * selectors here. A refinement scope (block_type:, block_plugin:,
   * layout:) selects its own address exactly; a kind scope selects every
   * address of that kind: kind:block the two block placement families,
   * kind:container the layout family, kind:entity and kind:field their
   * own bare kinds, which are the addresses entity and field roots wear.
   * Every member is prefixed by the token scope element and the whole
   * list rides inside :where(), so the selector carries no weight at all.
   * base:{tag} scopes derive no selector (the tag IS the selector), and
   * neither does a kind no root resolves through.
   *
   * @return string|null
   *   The selector, or NULL when the scope selects nothing.
   */
  public static function elementSelector(Address $address): ?string {
    $members = CssPrinter::addressMembers($address);
    if ($members === []) {
      return NULL;
    }
    return ':where(' . implode(', ', array_map(
      static fn (string $member): string => '.' . self::SCOPE_CLASS . ' ' . $member,
      $members,
    )) . ')';
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
    // Read from the skin's tiers only (the class docblock owns why the
    // declared tier says nothing about the site's scheme).
    $uses_scheme = FALSE;
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
          $uses_scheme = $uses_scheme || $this->usesSchemeConditions($entry);
        }
      }
      foreach ($this->orderedDefaults($skin->getDefaults()) as $scope => $styles) {
        $css = $this->printDefaultsScope($scope, $styles, $cache_tags);
        if ($css !== '') {
          $blocks[] = $css;
          $uses_container = $uses_container || $this->stylesUseContainerConditions($styles);
          $uses_scheme = $uses_scheme || $this->stylesUseSchemeConditions($styles);
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
    // The scheme declaration, demand-driven the same way: a skin whose
    // values follow the visitor's scheme tells the UA to follow it too, and
    // a light-only skin prints nothing (the used scheme is already light).
    // The constant's docblock owns the root-not-scope reasoning.
    if ($uses_scheme) {
      $css .= "\n" . CssPrinter::COLOR_SCHEME_RULE;
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
   * Prints one defaults scope's styles: bare zero-specificity rules.
   *
   * Media tokens resolve pre-print exactly like variant styles (the
   * resolver reads the image-style constraint from the scope's own
   * declaration); the printer never sees a token. One shape per the class
   * docblock: base scopes print at ':where(.token-scope) :where(tag)',
   * element scopes at their address selector (elementSelector()), every
   * entry through the printer's bare lane. Relative keys are the defaults
   * grammar's ('' the element, '::' plus a relative selector under it:
   * a part, its children, a tag, a type among its children); the
   * grammar's canonical spelling is the emit gate, since the selector
   * lands inside a selector, and anything else is inert data. Both tiers
   * ride this one path; the declared tier only ever carries element
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
    if ($address->isBase()) {
      // The closed vocabulary is the emit gate too: the tag prints into a
      // live selector, so anything outside it emits nothing (inert data,
      // like an uninstalled condition's blocks).
      if (!in_array($address->id, StyleGuide::BASE_TAGS, TRUE)) {
        return '';
      }
      $selector = ':where(.' . self::SCOPE_CLASS . ') :where(' . $address->id . ')';
      // base:{tag} defaults ARE the interposer the co-target companion
      // exists to beat, so they never co-target.
      $co_target = FALSE;
    }
    else {
      $selector = static::elementSelector($address);
      if ($selector === NULL) {
        return '';
      }
      $co_target = TRUE;
    }
    $styles = $this->mediaValueResolver->resolveVariantStyles($styles, $scope, $cache_tags);
    $blocks = [];
    foreach ($styles as $relative => $entry) {
      $relative = (string) $relative;
      $split = RelativeSelector::splitKey($relative);
      if (!is_array($entry) || $split === NULL || $split[0] !== '') {
        continue;
      }
      $block = $this->printer->printBareEntry($selector, $split[1], $entry, $co_target && $split[1] === NULL);
      if ($block !== '') {
        $blocks[] = $block;
      }
    }
    return implode("\n", $blocks);
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
   * Whether any of one scope's default entries use a scheme condition.
   */
  protected function stylesUseSchemeConditions(array $styles): bool {
    foreach ($styles as $entry) {
      if (is_array($entry) && $this->usesSchemeConditions($entry)) {
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
   * page-level <style> tag (inside the token scope element,
   * daedalus_stylish_preprocess_off_canvas_page_wrapper()) at the same
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

  /**
   * Whether any emitted condition block tests the color scheme preference.
   */
  protected function usesSchemeConditions(array $entry): bool {
    foreach (array_keys($entry) as $condition) {
      if ($this->conditionTypes->isSchemeCondition((string) $condition)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
