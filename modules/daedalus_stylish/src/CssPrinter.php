<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_ui\ElementModel\ElementResolver;

/**
 * Prints a style map as CSS text with @scope roots.
 *
 * The server half of the printer pair: the client printer powers live
 * preview and the code editor view, and a round-trip equivalence test locks
 * the two together — so this printer is deliberately dumb and deterministic.
 * Each element key becomes one @scope block rooted at the key's selectable
 * attribute; sub-element keys select their class inside the parent's scope
 * AND on the scope root itself (":scope.cls, .cls" — a collapsed container
 * hoists its part class onto the element carrying the key); tag-tier keys
 * ("{key}::p") select their bare tag as a descendant rule inside the scope
 * (the element-scoped middle tier of the three-tier text model);
 * conditions wrap declarations in pseudo-class selectors or at-rules, and
 * an "a+b" compound composes its parts' wrappers (at-rules nest in part
 * order, pseudo suffixes append — each as an :is() that also matches the
 * editor's data-force-state stamp).
 * Because @scope preludes name their root by selector, the emitted text is
 * position-independent — where the <style> tag lands in the DOM is purely a
 * delivery concern.
 *
 * The printer is also the emit gate: the map was validated on write, but
 * this is the last line before text lands inside a <style> element, so the
 * same hygiene is enforced again and anything that fails is dropped from the
 * output rather than emitted. Variant references print nothing here —
 * their CSS ships in the compiled shared stylesheet (VariantStylesheet).
 */
class CssPrinter {

  /**
   * The @scope limit for container-rooted blocks.
   *
   * The daedalus_layout nests containers (a nested container renders inside the outer
   * container), and every layout template's own markup carries the same
   * part classes — so without a lower bound, an outer container's
   * sub-element styles would also match the identical markup of every
   * nested container. The donut stops at nested container roots: what a
   * container key styles is that container's own markup, never an inner
   * container's (which has its own key, stamped at its own root at every
   * depth). The donut stops at nested BLOCK roots too: a block's interior
   * (a collapsed block's composed markup, an inline block's own layout
   * classes) belongs to the block's own key family.
   * DaedalusStylishLiveAttribution demands the stamps that form this boundary on
   * live renders (container roots, and block roots once the map styles any
   * container).
   */
  public const CONTAINER_SCOPE_LIMIT = ':scope [data-selectable^="container:"], :scope [data-selectable^="block:"]';

  /**
   * The @scope limit for container-rooted CHILDREN pseudo-part entries.
   *
   * A '::>' entry's targets are exactly the elements the standard donut
   * excludes: a container's direct children ARE nested container/block
   * roots. Shifting the boundary one level down (the CHILDREN of nested
   * roots instead of the roots themselves) keeps the roots stylable as
   * children while their interiors stay out, so a part-composed children
   * selector ('.cls > *') cannot leak into a nested container's identical
   * part markup (the nested part's children sit below the shifted limit).
   * The '>' combinator makes the bare form (':scope > *') self-limiting
   * anyway; the shifted donut is what makes the descendant half of the
   * part dual safe.
   */
  public const CONTAINER_CHILDREN_SCOPE_LIMIT = ':scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *';

  /**
   * The page-level width-band query root's selector.
   *
   * Width bands (narrow/medium) measure ONE page-level query container
   * everywhere: they are a poor man's media query, and container queries
   * are only the MECHANISM (the edit-mode canvas is a constrained div, so.
   *
   * @media would read the browser viewport, which is wrong when posing
   * and when sidebars shrink the canvas). Core's off-canvas page canvas is
   * the live root: the always-present wrapper whose inline size is the
   * page's true available width (core's toolbar displacement is a margin
   * on it). The canvas, NOT body: fixed-position elements inside a query
   * container are contained by it (they scroll with content); body
   * containment would capture the admin toolbar, dialogs, and the
   * off-canvas tray, which are body children OUTSIDE the canvas.
   *
   * In edit mode nearest-ancestor resolution hands the root to the posed
   * stage with zero mode branching: daedalus_ui makes
   * #daedalus-ui-stage a query container in any mode
   * (canvas-vector.css), and the stage sits INSIDE this canvas, so it wins
   * for everything on it. Every CSS tier that emits width-conditioned
   * blocks ships its own copy of PAGE_CONTAINER_RULE (entity inline tags,
   * the compiled variants file, the compiled skin file), demand-driven and
   * gated by the container_roots kill switch for themes that manage
   * containment themselves.
   */
  public const PAGE_CONTAINER_SELECTOR = '.dialog-off-canvas-main-canvas';

  /**
   * The rule establishing the page-level width-band query root.
   *
   * The one containment rule any tier emits; the client printer mirrors
   * the exact text (printer.js containerRootsCss).
   */
  public const PAGE_CONTAINER_RULE = self::PAGE_CONTAINER_SELECTOR . " {\n  container-type: inline-size;\n}";

  /**
   * The properties a scope-root entry co-targets onto text-claiming parts.
   *
   * A composed block's conditional part can claim the block's text content
   * (a link wrapping the text field): skin defaults like base:a then land
   * DIRECT on that interior element, and a direct rule always beats what a
   * root style reaches only by inheritance — regardless of layers,
   * specificity, or order. So every scope-root entry emits a companion rule
   * re-targeting these properties onto declared claiming elements
   * (CLAIMS_CONTENT_SELECTOR), fixing it at delivery: a pure print-time
   * derivation, so style-then-link and link-then-style converge with no
   * data migration.
   *
   * An INDEPENDENT list, deliberately NOT derived from the Typography
   * property group: group growth must never silently widen the co-target
   * set, and a constant list is what lets the guide-blind printer co-target
   * with no guide injection. Box properties (padding, background, borders)
   * must never join — co-targeted they would double-apply on the root and
   * the claiming element. text-decoration-line is a member on purpose
   * despite not being CSS-inherited: co-targeting is the only way a root
   * "Text decoration: none" can kill the skin's underline on a linked
   * heading. The client printer mirrors this list byte for byte.
   */
  public const INHERITED_TEXT_PROPERTIES = [
    'font-family',
    'font-size',
    'font-weight',
    'font-style',
    'line-height',
    'letter-spacing',
    'text-align',
    'text-transform',
    'text-decoration-line',
    'color',
  ];

  /**
   * The selector matching declared text-claiming elements, at zero weight.
   *
   * The attribute is the element model's anatomy fact (daedalus_ui
   * stamps it on any present part whose declaration claims a field slot);
   * the printer only consumes it. :where() keeps the companion rule at the
   * mirrored rule's own weight so the cascade does the rest: it beats the
   * skin's base:a default (also zero-spec, but the skin file is library
   * weight -10 and entry CSS prints later, all unlayered) and loses to any
   * real part style (a class selector, specificity 0-1-0), so
   * button-making on the claiming element stays untouched.
   */
  public const CLAIMS_CONTENT_SELECTOR = ':where([' . ElementResolver::CLAIMS_CONTENT_ATTRIBUTE . '])';

  /**
   * The keyframes name shape the emit gate admits: a CSS custom-ident.
   *
   * The name lands in an at-rule prelude, so this is charset hygiene, the
   * same posture as the declaration gate (the validator owns vocabulary;
   * StyleGuide::animationName() owns the spelling). Mirrored by
   * printer.js's KEYFRAMES_NAME_PATTERN, byte for byte.
   */
  public const KEYFRAMES_NAME_PATTERN = '/^-?[A-Za-z_][A-Za-z0-9_-]*$/';

  /**
   * The keyframe offset shape the emit gate admits.
   *
   * `from`, `to`, or a percentage; the stored steps are canonical
   * percentages already (AnimationStepsItem::canonicalizeSteps()), so this
   * only keeps a value written around the entity out of the at-rule body.
   * Mirrored by printer.js's KEYFRAME_OFFSET_PATTERN, byte for byte.
   */
  public const KEYFRAME_OFFSET_PATTERN = '/^(?:from|to|(?:\d+(?:\.\d+)?|\.\d+)%)$/';

  /**
   * The marker custom property printed beside each motion trigger property.
   *
   * `timeline-trigger` / `animation-trigger` (the Animation Triggers draft,
   * curated in the Motion group) say WHEN an animation plays. Browsers
   * without the draft drop the declarations at parse time, so the printer
   * prints a custom-property twin beside each one, in whatever block the
   * property lands (element, variant, skin, any condition): a custom
   * property resolves through the cascade exactly as the real property
   * would (verified inside @scope), so the motion-triggers runtime reads
   * the effective trigger pair off computed style and polyfills it where
   * the browser cannot. The whole polyfill era is deleted by removing this
   * emission and the runtime. Mirrored by printer.js's TRIGGER_MARKERS,
   * byte for byte.
   */
  public const TRIGGER_MARKERS = [
    'timeline-trigger' => '--daedalus-stylish-timeline-trigger',
    'animation-trigger' => '--daedalus-stylish-animation-trigger',
  ];

  /**
   * The attribute the motion-triggers runtime stamps once a trigger fired.
   *
   * The hold rule (TRIGGER_HOLD_SUPPORTS) pauses a triggered animation on
   * every element NOT carrying it; the runtime stamps it on activation and
   * removes it on a `reset` deactivation. Mirrored by printer.js's
   * TRIGGERED_ATTRIBUTE, byte for byte.
   */
  public const TRIGGERED_ATTRIBUTE = 'data-daedalus-stylish-triggered';

  /**
   * The feature query wrapping the polyfill hold.
   *
   * A block binding an animation to a trigger (`animation-trigger` other
   * than `none`) must not start that animation at render where the browser
   * lacks the draft: natively a triggered animation reads `paused` until
   * its trigger fires, so the hold makes the polyfill path read the same.
   * The printer emits, beside the block and inside the same condition
   * wrappers, `@supports not (animation-trigger: none)` holding the block's
   * targets `:not([data-daedalus-stylish-triggered])` at `animation-play-state:
   * paused`. Native browsers skip the at-rule and honor the trigger; every
   * other browser holds until the runtime stamps the attribute. The `:not`
   * outweighs the block's own rule, so no `!important` is needed and a
   * stamped element falls back to the author's play state. Mirrored by
   * printer.js's TRIGGER_HOLD_SUPPORTS, byte for byte.
   */
  public const TRIGGER_HOLD_SUPPORTS = '@supports not (animation-trigger: none)';

  public function __construct(
    protected StyleMapValidator $validator,
    protected ConditionTypePluginManager $conditionTypes,
  ) {}

  /**
   * Prints a whole style map.
   *
   * @param array $map
   *   The decoded style map: selectable keys mapped to style data.
   *
   * @return string
   *   CSS text, one @scope block per printable key. Empty string when
   *   nothing prints (unstyled, variant references only, or everything
   *   failed hygiene).
   */
  public function printMap(array $map): string {
    $blocks = [];
    foreach ($map as $key => $entry) {
      $key = (string) $key;
      if (!is_array($entry)) {
        continue;
      }
      $parsed = $this->validator->parseKey($key);
      if ($parsed === NULL) {
        continue;
      }
      $root = ($pos = strpos($key, '::')) === FALSE ? $key : substr($key, 0, $pos);
      // Children pseudo-part entries take the child-shifted donut: their
      // targets are the nested roots the standard donut would exclude
      // (see CONTAINER_CHILDREN_SCOPE_LIMIT).
      $limit = $parsed['kind'] === 'container'
        ? ($parsed['children'] ? self::CONTAINER_CHILDREN_SCOPE_LIMIT : self::CONTAINER_SCOPE_LIMIT)
        : NULL;
      $block = $this->printEntry(sprintf('[data-selectable="%s"]', $root), $parsed['sub'], $entry, $limit, $parsed['children']);
      if ($block !== '') {
        $blocks[] = $block;
      }
    }
    return implode("\n", $blocks);
  }

  /**
   * Prints one style entry as an @scope block.
   *
   * Public so variant compilation can reuse it with a variant-class scope
   * root instead of a selectable-key root.
   *
   * A scope-root entry ($sub NULL, not children) additionally co-targets
   * its inherited text properties onto declared text-claiming elements —
   * see INHERITED_TEXT_PROPERTIES. Every @scope lane rides this seam
   * (per-entity maps, compiled variants, edit-mode fragments, skin element
   * defaults), so co-targeting is one behavior everywhere; the bare lane
   * (printBareEntry) does not.
   *
   * @param string $scope_root
   *   The @scope prelude selector, e.g. '[data-selectable="block:{uuid}"]'.
   * @param string|null $sub
   *   The sub-element class selector (".quote") for sub-element keys, a
   *   bare tag name ("p") for tag-tier keys, or NULL when the entry styles
   *   the scope root itself.
   * @param array $entry
   *   The entry: condition keys mapping properties to values.
   * @param string|null $scope_limit
   *   An @scope "to" limit selector bounding the scope from below (the donut
   *   for container-rooted blocks: CONTAINER_SCOPE_LIMIT, and its
   *   child-shifted variant for children entries), or NULL for an
   *   unbounded scope.
   * @param bool $children
   *   TRUE for a '::>' children pseudo-part entry: every target selector is
   *   extended with ' > *', so the entry styles the addressed element's
   *   direct children (':scope > *' for the root, ':scope.cls > *, .cls > *'
   *   for a part's children).
   *
   * @return string
   *   The @scope block, or empty string when nothing inside it printed.
   */
  public function printEntry(string $scope_root, ?string $sub, array $entry, ?string $scope_limit = NULL, bool $children = FALSE): string {
    // A sub-element CLASS selector targets the part class inside the scope
    // AND on the scope root itself (":scope.cls" first, then the descendant
    // selector): a collapsed container hoists its part class onto the very
    // element carrying the key (nested containers render as ONE element),
    // so part-targeted styles must land on the root exactly like on a
    // descendant. Leakage into nested sections' identical part markup is
    // still stopped by the scope-limit donut.
    // A dotless sub is a TAG-TIER entry ("{key}::p"): it emits ONE bare
    // descendant tag target inside the scope. No ":scope" dual (the scope
    // root is the element carrying the key, never one of its own text
    // tags), and no :where() wrap on purpose: the bare tag stays at 0-0-1,
    // which is the whole three-tier cascade. It beats the skin's
    // zero-specificity base:{tag} default (tier 1), and every minted part
    // rule (tier 3, a class at 0-1-0 or higher) beats it, regardless of
    // document order.
    $is_tag = $sub !== NULL && !str_starts_with($sub, '.');
    $targets = match (TRUE) {
      $sub === NULL => [':scope'],
      $is_tag => [$sub],
      default => [':scope' . $sub, $sub],
    };
    // The children pseudo-part rides the same targets one level down: the
    // element's own selectors, each extended with the direct-child
    // combinator. Children rules are DEFAULTS under any per-child fact: a
    // child's own entry (':scope' at its own scope root, weight 0-1-0)
    // must always win, which at equal weight it does on scope proximity
    // (the child's root is closer than the parent's). So no children
    // target may outweigh ':scope': the part half would ('':scope.cls' is
    // 0-2-0) exactly where it matters most, on a collapsed nested
    // container wearing the hoisted part class on the very element
    // carrying the variant class or selectable key, so its class wraps in
    // :where(). Without the cap a column's flex-grow override would
    // silently lose to the row variant's equal-fill rule at nested depth,
    // in both delivery modes. A tag-tier children entry keeps its single
    // descendant target ("p > *"): no root dual exists to cap.
    if ($children) {
      if ($sub !== NULL && !$is_tag) {
        $targets[0] = ':scope:where(' . $sub . ')';
      }
      $targets = array_map(static fn (string $target): string => $target . ' > *', $targets);
    }
    // Only the scope root's own entry co-targets: a part entry styles a
    // concrete interior element (claiming parts included — a real part
    // style must WIN over the co-target, not feed it), and a children entry
    // already addresses descendants directly.
    $pieces = $this->entryPieces($targets, $entry, 1, $sub === NULL && !$children);

    if ($pieces === []) {
      return '';
    }
    $prelude = $scope_limit === NULL
      ? sprintf('@scope (%s)', $scope_root)
      : sprintf('@scope (%s) to (%s)', $scope_root, $scope_limit);
    return sprintf("%s {\n%s\n}", $prelude, implode("\n", $pieces));
  }

  /**
   * Prints one style entry as bare (unscoped) rules.
   *
   * The skin defaults' base:{tag} lane: prose-markup defaults print as
   * plain selectors OUTSIDE any @scope, on purpose. In the cascade, scoped
   * declarations beat unscoped ones on scoping proximity at equal
   * specificity, so staying unscoped is exactly what makes EVERY scoped
   * delivery path — per-entity @scope tags, variant classes, element-
   * default scope classes — override a base default regardless of document
   * order; callers additionally wrap the tag in :where() so the selector
   * never outweighs a single class. Conditions wrap exactly as in
   * printEntry() (same pieces builder), so a compound means the same thing
   * in every lane.
   *
   * @param string $selector
   *   The full selector for the styled element, e.g.
   *   ':where(.daedalus-stylish-token-scope) :where(h2)'.
   * @param string|null $sub
   *   The sub-element class selector ('.quote') for part keys, or NULL.
   * @param array $entry
   *   The entry: condition keys mapping properties to values.
   *
   * @return string
   *   The rules, or empty string when nothing printed.
   */
  public function printBareEntry(string $selector, ?string $sub, array $entry): string {
    $targets = $sub === NULL ? [$selector] : [$selector . ' ' . $sub];
    $pieces = $this->entryPieces($targets, $entry, 0);
    return $pieces === [] ? '' : implode("\n", $pieces);
  }

  /**
   * Builds an entry's printed pieces, one per condition block.
   *
   * The shared body behind printEntry() (nesting 1: everything indents one
   * level inside the @scope block) and printBareEntry() (nesting 0: rules
   * at top level). Extraction only — the emitted bytes for the @scope path
   * are locked by the printer equivalence test and unchanged.
   *
   * @param string[] $targets
   *   The target selectors; pseudo-class condition suffixes append to each.
   * @param array $entry
   *   The entry: condition keys mapping properties to values.
   * @param int $nesting
   *   How many levels the pieces are nested inside enclosing braces.
   * @param bool $co_target
   *   TRUE for a scope-root entry: each condition block's rule gains a
   *   companion rule re-targeting its INHERITED_TEXT_PROPERTIES subset onto
   *   declared text-claiming elements (':scope :where([data-claims-content])'),
   *   inside the same condition wrappers — pseudo-class suffixes stay on
   *   :scope (the condition holds on the styled element; the claiming
   *   element merely receives), at-rules wrap rule and companion together.
   *   Nothing emits when the block holds no co-targetable property. The
   *   bare lane (printBareEntry) never co-targets: base:{tag} defaults ARE
   *   the interposer this mechanism exists to beat.
   *
   *   A block binding an animation to a trigger additionally emits the
   *   polyfill hold (TRIGGER_HOLD_SUPPORTS) as a sibling of the rule, after
   *   the co-target companion and inside the same condition wrappers: the
   *   block's own selector list, pseudo suffixes included, each member
   *   extended with `:not([data-daedalus-stylish-triggered])`. Every lane rides this
   *   seam (per-entity maps, compiled variants, skin defaults, the bare
   *   lane), so a trigger holds the same way wherever it was declared.
   *
   * @return string[]
   *   The printed pieces; empty when nothing survived the emit gate.
   */
  protected function entryPieces(array $targets, array $entry, int $nesting, bool $co_target = FALSE): array {
    $pieces = [];

    foreach ($this->orderedConditions($entry) as $condition => $value) {
      $condition = (string) $condition;
      // The "variants" reference list prints nothing here: referenced
      // variants deliver via the compiled shared stylesheet and a class
      // stamped at render. An entry's own condition blocks — the overrides
      // layered over its references — print normally and beat the compiled
      // variant CSS in the cascade.
      if ($condition === 'variants') {
        continue;
      }
      if (!is_array($value)) {
        continue;
      }
      // "default" applies unconditionally; every other condition key — a
      // single condition or an "a+b" compound — maps each part to a wrapper
      // via the condition type plugins and COMPOSES them: pseudo-class
      // suffixes append to the target selector, at-rules nest around the
      // rule in part order (outermost first). A key with any unknown part
      // prints nothing — the emit gate's counterpart to the validator's
      // membership check (a plugin uninstalled after write degrades to
      // inert data).
      $suffixes = '';
      $at_rules = [];
      if ($condition !== 'default') {
        $unknown = FALSE;
        foreach (ConditionTypePluginManager::compoundParts($condition) as $part) {
          $wrapper = $this->conditionTypes->getWrapper($part);
          if ($wrapper === NULL) {
            $unknown = TRUE;
            break;
          }
          if (str_starts_with($wrapper, ':')) {
            // A pseudo-class condition also matches the editor's forced
            // state (the inspector's DevTools-style :hov): the
            // canvas stamps data-force-state on the selected element, and
            // the :is() alternative makes the stored styles render without
            // the real interaction. Inert everywhere else — nothing but
            // edit mode ever stamps the attribute — and specificity is
            // unchanged (:is() takes its most specific argument; the
            // attribute selector ties the pseudo-class). The part name is
            // safe inside the quoted attribute value: it resolved to a
            // wrapper above, so it is a plugin-declared condition name.
            $suffixes .= sprintf(':is(%s, [data-force-state~="%s"])', $wrapper, $part);
          }
          else {
            $at_rules[] = $wrapper;
          }
        }
        if ($unknown) {
          continue;
        }
      }
      $target_indent = str_repeat('  ', count($at_rules) + $nesting);
      $declarations = $this->printDeclarations($value, $target_indent . '  ');
      if ($declarations === '') {
        continue;
      }
      // Pseudo suffixes append to EVERY selector in the target list.
      $selector = implode(', ', array_map(
        static fn (string $target): string => $target . $suffixes,
        $targets,
      ));
      $piece = sprintf("%s%s {\n%s\n%s}", $target_indent, $selector, $declarations, $target_indent);
      if ($co_target) {
        // The companion declarations are the block's INHERITED_TEXT_PROPERTIES
        // subset in stored order, through the same emit gate (a subset of
        // declarations that just printed, so an empty main rule above never
        // reaches here with a non-empty companion).
        $inherited = array_intersect_key($value, array_flip(self::INHERITED_TEXT_PROPERTIES));
        $companion = $this->printDeclarations($inherited, $target_indent . '  ');
        if ($companion !== '') {
          $piece .= sprintf(
            "\n%s%s {\n%s\n%s}",
            $target_indent,
            ':scope' . $suffixes . ' ' . self::CLAIMS_CONTENT_SELECTOR,
            $companion,
            $target_indent,
          );
        }
      }
      if ($this->holdsAnimation($value)) {
        // The hold targets exactly what the rule targets (the same selector
        // list, the same pseudo suffixes): the trigger binds the animation
        // on those elements, so those are the elements to hold.
        $held = implode(', ', array_map(
          static fn (string $target): string => $target . $suffixes . ':not([' . self::TRIGGERED_ATTRIBUTE . '])',
          $targets,
        ));
        $piece .= sprintf(
          "\n%s%s {\n%s  %s {\n%s    animation-play-state: paused;\n%s  }\n%s}",
          $target_indent,
          self::TRIGGER_HOLD_SUPPORTS,
          $target_indent,
          $held,
          $target_indent,
          $target_indent,
          $target_indent,
        );
      }
      for ($i = count($at_rules) - 1; $i >= 0; $i--) {
        $indent = str_repeat('  ', $i + $nesting);
        $piece = sprintf("%s%s {\n%s\n%s}", $indent, $at_rules[$i], $piece, $indent);
      }
      $pieces[] = $piece;
    }

    return $pieces;
  }

  /**
   * Orders an entry's condition blocks for emission.
   *
   * Stored key order is write order (update_style appends a block the
   * first time its condition is touched) and carries no cascade meaning,
   * so emission orders structurally — the orderedEntryRefs() posture
   * applied to conditions. "default" prints first, then single conditions,
   * then compounds (more parts print later), ties broken by the parts'
   * positions in the flattened condition table and then by stored order.
   * At-rule wrappers add no specificity, so document order is the whole
   * cascade between these blocks: an active condition must beat base, and
   * a compound must beat the singles it extends, regardless of which was
   * written first. (Pseudo-class conditions also gain specificity from
   * their suffix; the ordering is what makes @container/@media blocks
   * deterministic.) The client printer mirrors this byte for byte.
   */
  protected function orderedConditions(array $entry): array {
    $position = array_flip($this->conditionTypes->getConditionNames());
    $vectors = [];
    $index = 0;
    foreach (array_keys($entry) as $key) {
      $key = (string) $key;
      if ($key === 'default') {
        $vector = [0, 0, []];
      }
      else {
        $parts = [];
        foreach (ConditionTypePluginManager::compoundParts($key) as $part) {
          $parts[] = $position[$part] ?? PHP_INT_MAX;
        }
        $vector = [1, count($parts), $parts];
      }
      $vectors[$key] = [$vector, $index++];
    }
    uksort($entry, static fn ($a, $b) => $vectors[(string) $a] <=> $vectors[(string) $b]);
    return $entry;
  }

  /**
   * Prints ONE fragment of a variant's element-relative styles.
   *
   * The variant half of the printer, and the delivery half of the
   * distributed-reference model: a {variant, fragment} reference on a map
   * entry delivers exactly its fragment's styles — the fragment's own
   * entry at the scope root, its '::.class' part entries at their class
   * selectors — rooted wherever the caller stamps the reference (a
   * (variant, fragment) class in the compiled shared file and the
   * workspace fallback, the referencing key's selectable attribute in edit
   * mode). Deeper slot paths under the fragment are OTHER fragments,
   * delivered by the elements carrying their own references; no positional
   * selector compilation exists anywhere anymore (position resolved to
   * identity when apply distributed the references). The client mirror
   * (printFragmentStyles in js/src/printer.js) is locked to this output
   * byte for byte by the round-trip equivalence test, and the fragment
   * lookup itself is SlotKey::fragmentStyles() — the same read detach
   * materialization uses, so delivery and detach can never disagree.
   *
   * The container donut (CONTAINER_SCOPE_LIMIT) applies whenever the
   * fragment ADDRESSES a container — the '' fragment of a container-family
   * variant, or any container(n) fragment — because the scope root then
   * sits on a container's root element, whose part styles' descendant
   * selectors could leak into nested containers' identical layout markup.
   * Every other fragment prints unbounded, exactly as the equivalent
   * authored entry would.
   *
   * @param string $scope_root
   *   The @scope prelude selector, e.g. '.daedalus-stylish-variant--{ref}' (possibly
   *   a comma list of class roots — the targets ride the prelude, so they
   *   distribute over every root).
   * @param string $scope
   *   The variant's scope string; picks the slot grammar's root kind and
   *   the fragment's addressed element kind.
   * @param array $styles
   *   The variant's element-relative styles.
   * @param string $fragment
   *   The fragment to print ('' for the root element). An unparseable
   *   fragment prints nothing — the grammar is the one gate on what
   *   prints, since key pieces land inside selectors. An unresolvable
   *   scope degrades to the path-less key space: only the '' fragment can
   *   print.
   * @param string|null $container_scope_limit
   *   The @scope "to" limit to use WHEN the fragment addresses a container,
   *   overriding CONTAINER_SCOPE_LIMIT. The skin defaults lane passes its
   *   class-extended donut (DefaultsStylesheet::DEFAULTS_SCOPE_LIMIT): live
   *   markup carries selectable-key stamps only on demand, so a boundary
   *   the compiled defaults can rely on must include the stamped scope
   *   classes. NULL (every variant path) keeps the standard donut.
   *
   * @return string
   *   CSS text, '' when nothing printed.
   */
  public function printFragmentStyles(string $scope_root, string $scope, array $styles, string $fragment, ?string $container_scope_limit = NULL): string {
    $root_kind = SlotKey::rootKindForScope($scope);
    $slot = SlotKey::parse($fragment, $root_kind);
    if ($slot === NULL) {
      return '';
    }
    $kind = $slot->segments === [] ? $root_kind : $slot->segments[array_key_last($slot->segments)]['type'];
    $limit = $kind === 'container' ? ($container_scope_limit ?? self::CONTAINER_SCOPE_LIMIT) : NULL;
    $blocks = [];
    foreach (SlotKey::fragmentStyles($styles, $fragment) as $suffix => $entry) {
      $sub = NULL;
      // The '::>' children suffix strips first, mirroring the key grammar
      // (SlotKey::parse()): '::.class::>' is the part's children.
      $entry_children = str_ends_with($suffix, '::>');
      if ($entry_children) {
        $suffix = substr($suffix, 0, -3);
      }
      if ($suffix !== '') {
        $sub = substr($suffix, 2);
        // The part selector prints verbatim, so its charset is the same
        // emit-side gate authored '::.class' keys pass (parseKey()).
        if (!preg_match('/^\.[A-Za-z0-9_-]+$/', $sub)) {
          continue;
        }
      }
      // Children entries take the child-shifted donut: their targets are
      // the nested roots the standard donut excludes.
      $entry_limit = $entry_children && $limit !== NULL ? static::childShiftedLimit($limit) : $limit;
      $block = $this->printEntry($scope_root, $sub, $entry, $entry_limit, $entry_children);
      if ($block !== '') {
        $blocks[] = $block;
      }
    }
    return implode("\n", $blocks);
  }

  /**
   * Prints one animation as a named @keyframes at-rule.
   *
   * The named at-rule lane: the first thing daedalus_stylish delivers that is not a
   * selector block. A keyframes body is offset-keyed (each step a
   * declaration block), sits outside any @scope, and is referenced by its
   * bare name from a value (`animation-name: motion-fade_in`), so it prints
   * as its own top-level rule: nothing here composes with conditions,
   * scope roots or co-targets. Delivered by the compiled animations file
   * and its workspace inline preview (AnimationStylesheet), and re-printed
   * client-side per settle for the keyframes editor's live preview; the
   * client mirror (printKeyframes in js/src/printer.js) is locked to this
   * output byte for byte by the round-trip equivalence test.
   *
   * The emit gate reused: the name must be a custom-ident
   * (KEYFRAMES_NAME_PATTERN), each offset an offset
   * (KEYFRAME_OFFSET_PATTERN), and each step's declarations pass the same
   * hygiene every declaration passes (printDeclarations()). A step with
   * nothing printable is dropped (an empty keyframe declares nothing), and
   * an animation with no printable step prints nothing at all.
   *
   * @param string $name
   *   The keyframes name as it delivers (StyleGuide::animationName()).
   * @param array $steps
   *   Offsets mapping property names to values (the entity's canonical
   *   steps; any spelling the offset gate admits prints verbatim).
   *
   * @return string
   *   The @keyframes block, or '' when nothing printed.
   */
  public function printKeyframes(string $name, array $steps): string {
    if (!preg_match(self::KEYFRAMES_NAME_PATTERN, $name)) {
      return '';
    }
    $pieces = [];
    foreach ($steps as $offset => $declarations) {
      $offset = (string) $offset;
      if (!is_array($declarations) || !preg_match(self::KEYFRAME_OFFSET_PATTERN, $offset)) {
        continue;
      }
      $printed = $this->printDeclarations($declarations, '    ');
      if ($printed === '') {
        continue;
      }
      $pieces[] = sprintf("  %s {\n%s\n  }", $offset, $printed);
    }
    if ($pieces === []) {
      return '';
    }
    return sprintf("@keyframes %s {\n%s\n}", $name, implode("\n", $pieces));
  }

  /**
   * Shifts an @scope donut one level down for children pseudo-part entries.
   *
   * Every comma member gets the direct-child combinator appended, turning
   * "stop at nested roots" into "stop at nested roots' children": the
   * standard donut maps onto CONTAINER_CHILDREN_SCOPE_LIMIT, and the skin
   * defaults' class-extended donut shifts the same way. Members must stay
   * comma-safe (no :is(a, b) selectors), which every donut here is.
   */
  protected static function childShiftedLimit(string $limit): string {
    return implode(', ', array_map(
      static fn (string $member): string => trim($member) . ' > *',
      explode(',', $limit),
    ));
  }

  /**
   * Prints a property map as declaration lines, dropping unsafe entries.
   *
   * A motion trigger property (TRIGGER_MARKERS) prints its marker custom
   * property on the next line, carrying the same value, wherever the
   * property prints: the marker rides the declaration, never the block, so
   * the runtime reads the effective trigger through the same cascade the
   * real property takes.
   */
  protected function printDeclarations(array $properties, string $indent): string {
    $lines = [];
    foreach ($this->admittedDeclarations($properties) as $property => $value) {
      $lines[] = sprintf('%s%s: %s;', $indent, $property, $value);
      if (isset(self::TRIGGER_MARKERS[$property])) {
        $lines[] = sprintf('%s%s: %s;', $indent, self::TRIGGER_MARKERS[$property], $value);
      }
    }
    return implode("\n", $lines);
  }

  /**
   * Whether a declaration block binds an animation to a trigger.
   *
   * TRUE when the block's `animation-trigger` passes the emit gate and is
   * not `none` (the draft's "no trigger": the animation plays at render,
   * so nothing holds it). Decided on the admitted value, never the stored
   * one, so a trigger the gate drops holds nothing either.
   */
  protected function holdsAnimation(array $properties): bool {
    $trigger = $this->admittedDeclarations($properties)['animation-trigger'] ?? NULL;
    return $trigger !== NULL && strtolower($trigger) !== 'none';
  }

  /**
   * The declarations of a property map that pass the emit gate.
   *
   * Property names and values were validated on write; re-checking here
   * keeps the emit path safe against maps written around daedalus_blueprint
   * (direct entity saves, imported content).
   *
   * @return array<string, string>
   *   Property names mapped to trimmed values, stored order kept.
   */
  protected function admittedDeclarations(array $properties): array {
    $admitted = [];
    foreach ($properties as $property => $value) {
      $property = (string) $property;
      if (!is_scalar($value)) {
        continue;
      }
      $value = trim((string) $value);
      // The underscore is a legal CSS ident character, present in custom
      // property names derived from machine names (--swatch-brand_red);
      // this is a charset hygiene gate, not a vocabulary check (the
      // validator owns vocabulary). Mirrored by printer.js's
      // PROPERTY_PATTERN, byte for byte.
      if ($value === '' || !preg_match('/^-{0,2}[A-Za-z][A-Za-z0-9_-]*$/', $property)) {
        continue;
      }
      if (preg_match('/[;{}<>\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
        continue;
      }
      $admitted[$property] = $value;
    }
    return $admitted;
  }

}
