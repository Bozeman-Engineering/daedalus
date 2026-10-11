<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_ui\ElementModel\ElementResolver;
use Drupal\daedalus_ui\Render\ElementAddress;

/**
 * Prints a style map as CSS text with @scope roots.
 *
 * The server half of the printer pair: the client printer powers live
 * preview and the code editor view, and a round-trip equivalence test locks
 * the two together — so this printer is deliberately dumb and deterministic.
 * Each element key becomes one @scope block rooted at the key's selectable
 * attribute, and the key's relative selector (RelativeSelector, the one
 * suffix grammar) prints as the block's targets by the SPECIFICITY
 * DISCIPLINE (scopeTargets()): every rule under an @scope root weighs
 * exactly what the addressed element's own equivalent rule weighs (0-1-0
 * when the last compound addresses an element root: `*`, a type, a part
 * class; 0-0-1 when it is a tag), so an ancestor's rule for a descendant
 * always TIES that descendant's own rule and loses to it on @scope
 * proximity (the descendant's root is the closer scoping root), beats
 * every default (the defaults tier prints bare at 0-0-0), and two
 * ancestors' rules resolve by proximity, nearest first. Mechanically ONE
 * compound of the chain carries the weight (the last non-universal one,
 * `:scope` included), every other prints inside :where(), `*` never
 * carries any, and a qualifier (`:has()`, `:not()`) takes its argument's
 * weight, so every argument compound prints inside :where() too with its
 * combinators bare (`:has(> :where([data-element="block_type:image"]))`;
 * :where() takes complex selectors, never relative ones, so the wrap is
 * per compound). Part keys keep their dual: the class inside the scope AND
 * on the scope root itself (":scope.cls, .cls" — a collapsed container
 * hoists its part class onto the element carrying the key); a type
 * compound selects by the element address stamp (`[data-element="…"]`,
 * daedalus_ui's ElementAddress); a terminal pseudo-element (`::before`,
 * `::after`, the generated box of the last compound, or of the scope
 * root itself for the owner's anchor `&::before`) prints LAST on every
 * target, after the condition suffixes (`:scope:is(:hover, …)::before`):
 * :where() cannot wrap a pseudo-element, so every rule addressing a box
 * weighs 0-0-1 more than its host's, own and ancestor alike, and the
 * tie-and-proximity discipline holds by construction;
 * conditions wrap declarations in pseudo-class selectors or at-rules, and
 * an "a+b" compound composes its parts' wrappers (at-rules nest in part
 * order, pseudo suffixes append: a posable state as an :is() that also
 * matches the editor's data-force-state stamp, a structural fact bare).
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
   * The @scope limit for container-rooted blocks: the donut.
   *
   * The daedalus_layout nests containers (a nested container renders inside the outer
   * container), and every layout template's own markup carries the same
   * part classes — so without a lower bound, an outer container's PART
   * styles would also match the identical markup of every nested
   * container. The donut stops at nested container roots: what a
   * container's part key styles is that container's own markup, never an
   * inner container's (which has its own key, stamped at its own root at
   * every depth). The donut stops at nested BLOCK roots too: a block's
   * interior (a collapsed block's composed markup, an inline block's own
   * layout classes) belongs to the block's own key family.
   *
   * Which entries take it is donutLimit(): the root entry (its co-target
   * companion reaches claiming interiors) and PART-FIRST selectors, the
   * owner's own anatomy, whose class names recur in nested anatomy. Every
   * other selector (a tag, a type, `> *`) reaches its whole subtree on
   * purpose: it addresses other elements by relation, a type compound
   * names a nested root itself, and @scope proximity precedence is what
   * bounds it (the nearest key's rule wins at equal weight).
   * DaedalusStylishLiveAttribution demands the stamps that form this boundary on
   * live renders (container roots, and block roots once the map styles any
   * container).
   */
  public const CONTAINER_SCOPE_LIMIT = ':scope [data-selectable^="container:"], :scope [data-selectable^="block:"]';

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
   * The rule telling the UA the document follows the visitor's color scheme.
   *
   * A skin's dark values ride @media (prefers-color-scheme: dark), so on a
   * dark OS the page paints dark; but the UA draws its OWN chrome (the
   * viewport scrollbar, native form controls, light-dark()) from the
   * document's used color-scheme, which stays `normal` (light) until
   * something declares it: a white scrollbar track down a dark page.
   * `light dark` is exactly "follow the preference", the scheme the skin's
   * tokens picked. The rule sits on :root because the viewport reads
   * color-scheme from the root element alone (the token scope element is
   * a descendant of body and cannot reach it). Only the compiled defaults
   * file emits it, on demand: when any condition on the active skin's
   * tokens or defaults is a scheme condition
   * (ConditionTypePluginManager::isSchemeCondition()), never otherwise, so
   * a light-only skin keeps light UA chrome. The edit canvas's posed
   * scheme is an inline color-scheme on the stage (canvas-vector.js),
   * which beats this inherited root value for the canvas subtree.
   */
  public const COLOR_SCHEME_RULE = ":root {\n  color-scheme: light dark;\n}";

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
   * The blocks print in the map's order, and the printer imposes none of
   * its own: delivery hands the map over in its canonical order, the
   * document's (NodeTreeBuilder::styleMapOf(), applied by
   * CssStyleBackend), which the client's printMap receives the same way
   * from styleMapOf in style-guide.js, so both printers emit the same
   * byte sequence for one document.
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
      $limit = $parsed['kind'] === 'container' ? static::donutLimit($parsed['selector']) : NULL;
      $block = $this->printEntry(sprintf('[data-selectable="%s"]', $root), $parsed['selector'], $entry, $limit);
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
   * A scope-root entry ($selector NULL) additionally co-targets its
   * inherited text properties onto declared text-claiming elements —
   * see INHERITED_TEXT_PROPERTIES. Every @scope lane rides this seam
   * (per-entity maps, compiled variants, edit-mode fragments), and the
   * bare defaults lane co-targets its element scopes' root entries the
   * same way (printBareEntry), so co-targeting is one behavior everywhere.
   *
   * @param string $scope_root
   *   The @scope prelude selector, e.g. '[data-selectable="block:{uuid}"]'.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector|null $selector
   *   The key's relative selector, or NULL when the entry styles the
   *   scope root itself. Its targets are scopeTargets()'s.
   * @param array $entry
   *   The entry: condition keys mapping properties to values.
   * @param string|null $scope_limit
   *   An @scope "to" limit selector bounding the scope from below (the
   *   donut, donutLimit()), or NULL for an unbounded scope.
   *
   * @return string
   *   The @scope block, or empty string when nothing inside it printed.
   */
  public function printEntry(string $scope_root, ?RelativeSelector $selector, array $entry, ?string $scope_limit = NULL): string {
    // Only the scope root's own entry co-targets: a selector entry styles
    // concrete interior elements (claiming parts included — a real part
    // style must WIN over the co-target, not feed it), and the owner's
    // own generated box (`&::before`) is a selector entry like any other.
    $pieces = $this->entryPieces(static::hostTargets($selector), $entry, 1, $selector === NULL ? ':scope' : NULL, static::pseudoTail($selector));

    if ($pieces === []) {
      return '';
    }
    $prelude = $scope_limit === NULL
      ? sprintf('@scope (%s)', $scope_root)
      : sprintf('@scope (%s) to (%s)', $scope_root, $scope_limit);
    return sprintf("%s {\n%s\n}", $prelude, implode("\n", $pieces));
  }

  /**
   * Prints one style entry as bare (unscoped) zero-specificity rules.
   *
   * The defaults lane, every tier of it (DefaultsStylesheet: the base:{tag}
   * tier and the element scopes alike): defaults print as plain selectors
   * OUTSIDE any @scope and at 0-0-0, on purpose. Every authored rule (a
   * per-entity @scope block, a compiled variant, an ancestor's rule for
   * its descendants) then beats a default by specificity, and @scope
   * proximity only ever orders authored rules among themselves: a default
   * in its own @scope at the element would sit at 0-1-0 and, by the
   * cascade's proximity step, beat an ancestor's equal-weight rule for
   * that element, inverting the layer model. Callers pass a fully
   * :where()-wrapped selector; the part and children forms added here stay
   * inside :where() too. Conditions wrap exactly as in printEntry() (same
   * pieces builder), so a compound means the same thing in every lane.
   *
   * @param string $selector
   *   The full zero-specificity selector for the styled element, e.g.
   *   ':where(.daedalus-stylish-token-scope) :where(h2)' or
   *   ':where(.daedalus-stylish-token-scope [data-element="kind:block"])'.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector|null $relative
   *   The key's relative selector, printed as a chain of :where()-wrapped
   *   compounds after the element's selector (`*` bare, since it carries
   *   no weight; a terminal pseudo-element glued last, after the
   *   condition suffixes, the anchor `&::before` printing it on the
   *   element's own selector), or NULL for the element itself.
   * @param array $entry
   *   The entry: condition keys mapping properties to values.
   * @param bool $co_target
   *   TRUE to co-target the entry's INHERITED_TEXT_PROPERTIES onto declared
   *   text-claiming elements, exactly as printEntry() does for a scope
   *   root's own entry (the companion prints at the same zero specificity
   *   under the same selector). An element scope's root default passes
   *   TRUE so a block's text default reaches its claimed content past a
   *   base:{tag} default, which prints earlier in the same file; base:{tag}
   *   defaults pass FALSE: they ARE the interposer the companion exists to
   *   beat, and co-targeting them would re-land the tag default on the
   *   claiming element.
   *
   * @return string
   *   The rules, or empty string when nothing printed.
   */
  public function printBareEntry(string $selector, ?RelativeSelector $relative, array $entry, bool $co_target = FALSE): string {
    $target = $selector;
    foreach ($relative?->compounds ?? [] as $compound) {
      $target .= static::renderCombinator($compound) . static::renderCompound($compound, FALSE);
    }
    $pieces = $this->entryPieces([$target], $entry, 0, $co_target ? $selector : NULL, static::pseudoTail($relative));
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
   *   The target selectors, the hosts of the styled boxes; pseudo-class
   *   condition suffixes append to each, then $pseudo.
   * @param array $entry
   *   The entry: condition keys mapping properties to values.
   * @param int $nesting
   *   How many levels the pieces are nested inside enclosing braces.
   * @param string|null $co_target_root
   *   The element's own selector to co-target from, for a scope-root entry
   *   (':scope' in the @scope lane, the bare zero-specificity selector in
   *   the bare lane): each condition block's rule gains a companion rule
   *   re-targeting its INHERITED_TEXT_PROPERTIES subset onto declared
   *   text-claiming elements ('{root} :where([data-claims-content])'),
   *   inside the same condition wrappers — pseudo-class suffixes stay on
   *   the root (the condition holds on the styled element; the claiming
   *   element merely receives), at-rules wrap rule and companion together.
   *   Nothing emits when the block holds no co-targetable property. NULL
   *   never co-targets (part and children entries, and base:{tag}
   *   defaults, which ARE the interposer this mechanism exists to beat).
   *
   *   A block binding an animation to a trigger additionally emits the
   *   polyfill hold (TRIGGER_HOLD_SUPPORTS) as a sibling of the rule, after
   *   the co-target companion and inside the same condition wrappers: the
   *   block's own selector list, pseudo suffixes included, each member
   *   extended with `:not([data-daedalus-stylish-triggered])`. Every lane rides this
   *   seam (per-entity maps, compiled variants, skin defaults, the bare
   *   lane), so a trigger holds the same way wherever it was declared.
   * @param string $pseudo
   *   The terminal pseudo-element the entry styles (pseudoTail(): '' for
   *   an element, `::before` or `::after` for its generated box), printed
   *   LAST on every target: after the condition suffixes (the state holds
   *   on the host, `:scope:is(:hover, …)::before`) and after the hold's
   *   `:not()` (the runtime stamps the host). Nothing composes behind a
   *   pseudo-element, and no companion co-targets one: the companion is
   *   the scope root's own entry's, and a generated box is a selector
   *   entry (the anchor `&::before` included), never the root.
   *
   * @return string[]
   *   The printed pieces; empty when nothing survived the emit gate.
   */
  protected function entryPieces(array $targets, array $entry, int $nesting, ?string $co_target_root = NULL, string $pseudo = ''): array {
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
            // A structural FACT (":first-child", ":nth-child(odd)") holds
            // by the element's place in the markup: nothing the editor
            // does can force it, so it prints bare, the pseudo-class
            // itself. Every other pseudo-class condition also matches the
            // editor's forced state (the inspector's DevTools-style :hov):
            // the canvas stamps data-force-state on the selected element,
            // and the :is() alternative makes the stored styles render
            // without the real interaction. Inert everywhere else,
            // nothing but edit mode ever stamps the attribute, and
            // specificity is unchanged (:is() takes its most specific
            // argument; the attribute selector ties the pseudo-class).
            // The part name is safe inside the quoted attribute value: it
            // resolved to a wrapper above, so it is a plugin-declared
            // condition name.
            $suffixes .= $this->conditionTypes->isFact($part)
              ? $wrapper
              : sprintf(':is(%s, [data-force-state~="%s"])', $wrapper, $part);
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
      // Pseudo-class suffixes append to EVERY selector in the target list,
      // the pseudo-element after them.
      $selector = implode(', ', array_map(
        static fn (string $target): string => $target . $suffixes . $pseudo,
        $targets,
      ));
      $piece = sprintf("%s%s {\n%s\n%s}", $target_indent, $selector, $declarations, $target_indent);
      if ($co_target_root !== NULL) {
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
            $co_target_root . $suffixes . ' ' . self::CLAIMS_CONTENT_SELECTOR,
            $companion,
            $target_indent,
          );
        }
      }
      if ($this->holdsAnimation($value)) {
        // The hold targets exactly what the rule targets (the same selector
        // list, the same pseudo-class suffixes, the same pseudo-element
        // last): the trigger binds the animation on those boxes, so those
        // are the boxes to hold, and the stamp the :not() reads sits on
        // the host.
        $held = implode(', ', array_map(
          static fn (string $target): string => $target . $suffixes . ':not([' . self::TRIGGERED_ATTRIBUTE . '])' . $pseudo,
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
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector|null $under
   *   The rule the fragment is worn at, or NULL at an element. Wear at a
   *   rule delivers inside the rule OWNER's scope (the scope root is the
   *   owner's selectable attribute) with every suffix continued under
   *   the rule by descent (RelativeSelector::under(): the '' suffix at
   *   the rule's own targets, '::.cls' at `{rule} .cls`, '::> *' at
   *   `{rule} > *`); a suffix the grammar refuses under the rule prints
   *   nothing, the same admissibility an authored key gets. Never a
   *   class: a rule has no one element to stamp. The donut is judged on
   *   the continued selector, as for an authored key.
   *
   * @return string
   *   CSS text, '' when nothing printed.
   */
  public function printFragmentStyles(string $scope_root, string $scope, array $styles, string $fragment, ?RelativeSelector $under = NULL): string {
    $root_kind = SlotKey::rootKindForScope($scope);
    $slot = SlotKey::parse($fragment, $root_kind);
    if ($slot === NULL) {
      return '';
    }
    $kind = $slot->segments === [] ? $root_kind : $slot->segments[array_key_last($slot->segments)]['type'];
    $blocks = [];
    foreach (SlotKey::fragmentStyles($styles, $fragment) as $suffix => $entry) {
      // The suffix is '' or '::selector'; the selector prints into
      // selectors, so the grammar (canonical spelling) is the emit gate,
      // the same gate authored keys pass (parseKey()).
      $split = RelativeSelector::splitKey((string) $suffix);
      if ($split === NULL) {
        continue;
      }
      $selector = $split[1];
      if ($under !== NULL) {
        $selector = $selector === NULL ? $under : RelativeSelector::under($under, $selector);
        if ($selector === NULL) {
          continue;
        }
      }
      $block = $this->printEntry($scope_root, $selector, $entry, $kind === 'container' ? static::donutLimit($selector) : NULL);
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
   * Prints one @font-face at-rule for a face of a self-hosted font.
   *
   * A font face is an at-rule like a keyframes block: it sits outside
   * every scope and is referenced by its family from a value, so it prints as
   * its own top-level rule with nothing composed in. Every face declares
   * `font-display: swap` (text renders in the fallback until the file
   * lands, never blocking), and the declaration order is fixed so the
   * compiled file is content-addressed on the faces alone. Delivered by
   * the compiled fonts file and its workspace inline preview
   * (FontStylesheet); no client mirror, the client never prints a face.
   *
   * The emit gate: the family is the quoted spelling
   * (StyleGuide::fontFamily(), a double-quoted string with no raw quote
   * or control character inside), the weight a number or an ascending
   * "min max" range, the style normal, italic or oblique, the url free of
   * quotes, parentheses and control characters, and the format one of the
   * formats the font store admits. A face failing any of it prints
   * nothing.
   *
   * @param string $family
   *   The family as it delivers (StyleGuide::fontFamily()), quotes included.
   * @param string $weight
   *   The canonical weight ("400", "100 900").
   * @param string $style
   *   The canonical style (normal, italic, oblique).
   * @param string $url
   *   The file's URL as the browser fetches it (root-relative or absolute).
   * @param string $format
   *   The format() keyword (woff2, woff).
   *
   * @return string
   *   The @font-face block, or '' when the face fails the gate.
   */
  public function printFontFace(string $family, string $weight, string $style, string $url, string $format): string {
    if (!preg_match('/^"(?:[^"\\\\\x00-\x1F\x7F]|\\\\.)+"$/', $family)) {
      return '';
    }
    if (!preg_match('/^\d+(?:\.\d+)?(?: \d+(?:\.\d+)?)?$/', $weight)) {
      return '';
    }
    if (!in_array($style, ['normal', 'italic', 'oblique'], TRUE)) {
      return '';
    }
    if ($url === '' || preg_match('/["\'()\s\x00-\x1F\x7F]/', $url)) {
      return '';
    }
    if (!in_array($format, ['woff2', 'woff'], TRUE)) {
      return '';
    }
    return sprintf("@font-face {\n  font-family: %s;\n  font-style: %s;\n  font-weight: %s;\n  font-display: swap;\n  src: url(\"%s\") format(\"%s\");\n}", $family, $style, $weight, $url, $format);
  }

  /**
   * The @scope targets a relative selector prints to: the discipline.
   *
   * The chain is `:scope` (the scoping root) followed by the selector's
   * compounds. Exactly ONE link of the chain carries specificity, the
   * LAST non-universal one (`*` never weighs), and every other link
   * prints inside :where(); so a rule's weight is its addressed element's
   * own kind of weight (0-1-0 for `:scope`, a type or a class, 0-0-1 for
   * a tag) and never more, and an ancestor's rule ties the descendant's
   * own and loses on proximity. A descendant-first chain omits `:scope`
   * from the print: inside @scope a bare selector is already relative to
   * the root at zero weight, which is the same thing.
   *
   * A PART-FIRST selector prints a second, first-listed target with the
   * part class glued onto the scope root (`:scope.cls`): a collapsed
   * container hoists its part class onto the very element carrying the
   * key (nested containers render as ONE element), so part-targeted
   * styles must land on the root exactly like on a descendant. The bare
   * part (`.cls`) keeps `:scope.cls` at 0-2-0 on purpose: the owner's own
   * part on the owner's own root outranks the owner's root entry, and no
   * other element competes there. A longer part-first chain puts the
   * root's weight where the discipline says: `:scope` carries it when
   * nothing after the class does (`:scope:where(.cls) > *`), otherwise
   * the last weighted compound does (`:where(:scope):where(.cls) > […]`).
   * The dual is skipped when the part's next compound is a sibling
   * (`.cls + .cls`): the scope root has no sibling inside itself.
   *
   * A sibling combinator prints like `>` (` + `, ` ~ `); a compound's
   * qualifiers print on it, their argument compounds each inside :where()
   * with bare combinators (renderCompound()), so a qualifier adds no
   * weight. Type compounds select by the element address stamp
   * (typeSelector()).
   *
   * A terminal pseudo-element glues onto every target after the last
   * compound's qualifiers (`:where(:scope) > [data-element="…"]::before`,
   * the part dual on both halves: `:scope.quote::after, .quote::after`),
   * and the owner's anchor (`&::before`, no compounds) is the scoping
   * root's own box (`:scope::before`). :where() cannot wrap a
   * pseudo-element, so every rule addressing a box weighs its host's
   * weight plus 0-0-1, own and ancestor alike: the ties the discipline
   * relies on hold on the boxes exactly as on their hosts. The printed
   * entry puts the condition suffixes BEFORE the pseudo (entryPieces()),
   * so these targets are the whole selectors of an unconditional rule and
   * the hosts are hostTargets()'s. Mirrored by printer.js's scopeTargets,
   * byte for byte.
   *
   * @return string[]
   *   The target selectors, the hoisted dual first when there is one.
   */
  public static function scopeTargets(?RelativeSelector $selector): array {
    $pseudo = static::pseudoTail($selector);
    return array_map(
      static fn (string $host): string => $host . $pseudo,
      static::hostTargets($selector),
    );
  }

  /**
   * The terminal pseudo-element a selector prints, as selector text.
   *
   * @return string
   *   `::before` / `::after`, or '' for an element (NULL included).
   */
  protected static function pseudoTail(?RelativeSelector $selector): string {
    return $selector?->pseudo === NULL ? '' : RelativeSelector::SUFFIX . $selector->pseudo;
  }

  /**
   * The @scope targets of a selector's HOSTS: scopeTargets() without the
   * pseudo-element, the selectors a condition suffix appends to.
   *
   * The owner's anchor (`&::before`) hosts on the scoping root itself.
   *
   * @return string[]
   *   The host selectors, the hoisted dual first when there is one.
   */
  protected static function hostTargets(?RelativeSelector $selector): array {
    if ($selector === NULL || $selector->compounds === []) {
      return [':scope'];
    }
    $compounds = $selector->compounds;
    $carrier = static::lastWeighted($compounds, 0);
    if ($compounds[0]['combinator'] === RelativeSelector::CHILD) {
      $main = ($carrier === -1 ? ':scope' : ':where(:scope)') . static::renderTail($compounds, $carrier, 0);
    }
    else {
      $main = static::renderCompound($compounds[0], $carrier === 0) . static::renderTail($compounds, $carrier, 1);
    }
    if (!$selector->isPartFirst()) {
      return [$main];
    }
    if (count($compounds) === 1) {
      return [':scope' . $compounds[0]['name'] . static::renderQualifiers($compounds[0]), $main];
    }
    if (static::isSibling($compounds[1])) {
      return [$main];
    }
    $rest_carrier = static::lastWeighted($compounds, 1);
    $dual = ($rest_carrier === -1 ? ':scope' : ':where(:scope)') . ':where(' . $compounds[0]['name'] . ')' . static::renderQualifiers($compounds[0]) . static::renderTail($compounds, $rest_carrier, 1);
    return [$dual, $main];
  }

  /**
   * Whether a compound is reached by a sibling combinator (`+`, `~`).
   */
  protected static function isSibling(array $compound): bool {
    return in_array($compound['combinator'], [RelativeSelector::NEXT, RelativeSelector::SUBSEQUENT], TRUE);
  }

  /**
   * The index of the last weighted (non-universal) compound from $from, or -1.
   *
   * The subject alone weighs: a qualifier takes its argument's weight,
   * and every argument compound prints inside :where().
   */
  protected static function lastWeighted(array $compounds, int $from): int {
    for ($i = count($compounds) - 1; $i >= $from; $i--) {
      if ($compounds[$i]['kind'] !== RelativeSelector::UNIVERSAL) {
        return $i;
      }
    }
    return -1;
  }

  /**
   * Prints the chain from one compound on, each behind its combinator.
   */
  protected static function renderTail(array $compounds, int $carrier, int $from): string {
    $printed = '';
    foreach (array_slice($compounds, $from, NULL, TRUE) as $i => $compound) {
      $printed .= static::renderCombinator($compound) . static::renderCompound($compound, $i === $carrier);
    }
    return $printed;
  }

  /**
   * The symbols of the spelled combinators (the descendant one is a space).
   */
  protected const COMBINATOR_SYMBOLS = [
    RelativeSelector::CHILD => '>',
    RelativeSelector::NEXT => '+',
    RelativeSelector::SUBSEQUENT => '~',
  ];

  /**
   * Prints the combinator reaching a compound: ` > `, ` + ` or ` ~ `, the
   * one space for a descendant.
   */
  protected static function renderCombinator(array $compound): string {
    return $compound['combinator'] === RelativeSelector::DESCENDANT
      ? ' '
      : ' ' . static::COMBINATOR_SYMBOLS[$compound['combinator']] . ' ';
  }

  /**
   * Prints one compound: its subject, bare when it carries the weight,
   * else in :where(), then its qualifiers.
   *
   * `*` prints bare (it weighs nothing); an implied subject (inside
   * `:not()`) prints nothing.
   */
  protected static function renderCompound(array $compound, bool $bare): string {
    if ($compound['kind'] === NULL) {
      return static::renderQualifiers($compound);
    }
    if ($compound['kind'] === RelativeSelector::UNIVERSAL) {
      return '*' . static::renderQualifiers($compound);
    }
    $printed = $compound['kind'] === RelativeSelector::TYPE ? static::typeSelector($compound['name']) : $compound['name'];
    return ($bare ? $printed : ':where(' . $printed . ')') . static::renderQualifiers($compound);
  }

  /**
   * Prints a compound's qualifiers, each at zero weight.
   *
   * `:has()` and `:not()` take their argument's specificity, and the
   * discipline puts ONE weight on the chain, so every argument compound
   * prints inside :where(); :where() takes complex selectors and never
   * relative ones, so the wrap is per compound and the argument's
   * combinators stay bare, its lead glued to its space
   * (`:has(> :where([data-element="block_type:image"]))`,
   * `:not(:where(p))`, `:not(:has(:where(a)))`).
   */
  protected static function renderQualifiers(array $compound): string {
    $printed = '';
    foreach ($compound['qualifiers'] as $qualifier) {
      $printed .= $qualifier['name'] === RelativeSelector::HAS
        ? ':has(' . static::renderArgument($qualifier['argument']) . ')'
        : ':not(' . static::renderCompound($qualifier['argument'], FALSE) . ')';
    }
    return $printed;
  }

  /**
   * Prints a `:has()` argument: its lead, then each compound in :where().
   */
  protected static function renderArgument(array $compounds): string {
    $printed = $compounds[0]['combinator'] === RelativeSelector::DESCENDANT
      ? ''
      : static::COMBINATOR_SYMBOLS[$compounds[0]['combinator']] . ' ';
    foreach ($compounds as $i => $compound) {
      $printed .= ($i === 0 ? '' : static::renderCombinator($compound)) . static::renderCompound($compound, FALSE);
    }
    return $printed;
  }

  /**
   * The selector a type compound matches: the element address stamp.
   *
   * `pb-text` is `[data-element="block_type:text"]`; the two kind
   * families select every member of the family (addressMembers()),
   * `pb-block` as an :is() list so the compound stays one link of the
   * chain. Mirrored by printer.js's typeSelector, byte for byte.
   */
  public static function typeSelector(string $type): string {
    $members = static::addressMembers(RelativeSelector::typeAddress($type));
    return count($members) === 1 ? $members[0] : ':is(' . implode(', ', $members) . ')';
  }

  /**
   * The attribute selectors a root address selects, by the stamp.
   *
   * The ONE derivation of "which stamped roots does this address name":
   * a refinement (block_type:, block_plugin:, layout:) its own address
   * exactly; a kind every address of that kind: kind:block the two block
   * placement families, kind:container the layout family, kind:entity
   * and kind:field their own bare kinds, which are the addresses entity
   * and field roots wear. The defaults tier (DefaultsStylesheet) and the
   * type compounds read here. A base tag derives no selector (the tag IS
   * the selector), and neither does a kind no root resolves through.
   *
   * @return string[]
   *   The members, [] when the address selects no stamped root.
   */
  public static function addressMembers(Address $address): array {
    if ($address->isBase() || !$address->isRoot()) {
      return [];
    }
    $attribute = ElementAddress::ATTRIBUTE;
    return match (TRUE) {
      !$address->isKind() => [sprintf('[%s="%s"]', $attribute, $address)],
      $address->id === Address::KIND_BLOCK => [
        sprintf('[%s^="%s"]', $attribute, ElementAddress::BLOCK_TYPE_PREFIX),
        sprintf('[%s^="%s"]', $attribute, ElementAddress::BLOCK_PLUGIN_PREFIX),
      ],
      $address->id === Address::KIND_CONTAINER => [sprintf('[%s^="%s"]', $attribute, ElementAddress::LAYOUT_PREFIX)],
      (string) $address === ElementAddress::ENTITY, (string) $address === ElementAddress::FIELD => [sprintf('[%s="%s"]', $attribute, $address)],
      default => [],
    };
  }

  /**
   * The donut a container-rooted entry takes, by its selector's shape.
   *
   * The root entry takes the standard donut (its co-target companion
   * reaches claiming interiors, which nested roots own). A part-first
   * selector takes it too, shifted one child level per compound the part
   * reaches by `>`: the targets of `.cls > *` are the nested roots the
   * standard limit would exclude, so the boundary moves to the nested
   * roots' children, which keeps the roots stylable as the part's
   * children while a nested container's identical part markup (whose own
   * children sit below the shifted limit) stays out; `.cls > pb-text > *`
   * shifts twice, and so on. A part-first selector that continues by a
   * DESCENDANT combinator (`.cls p`) reaches inside nested roots on
   * purpose (doctrine: a tag or type compound has descendant reach,
   * bounded by proximity precedence alone), so it takes no donut; nor
   * does any other selector shape. Mirrored by printer.js's donutLimit.
   */
  public static function donutLimit(?RelativeSelector $selector): ?string {
    if ($selector === NULL) {
      return self::CONTAINER_SCOPE_LIMIT;
    }
    if (!$selector->isPartFirst()) {
      return NULL;
    }
    $levels = 0;
    foreach (array_slice($selector->compounds, 1) as $compound) {
      if ($compound['combinator'] !== RelativeSelector::CHILD) {
        return NULL;
      }
      $levels++;
    }
    if ($levels === 0) {
      return self::CONTAINER_SCOPE_LIMIT;
    }
    return implode(', ', array_map(
      static fn (string $member): string => trim($member) . str_repeat(' > *', $levels),
      explode(',', self::CONTAINER_SCOPE_LIMIT),
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
