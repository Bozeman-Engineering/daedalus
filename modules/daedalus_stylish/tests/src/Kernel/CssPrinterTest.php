<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\CssPrinter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the @scope CSS printer.
 *
 * The printer is deliberately dumb-deterministic — the client printer must
 * reproduce this output exactly (round-trip equivalence), so these tests
 * assert exact text, not just containment.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class CssPrinterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'entity_test',
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
   * The CSS printer under test.
   */
  protected CssPrinter $printer;

  /**
   * The fixture entity's uuid.
   */
  protected string $uuid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->printer = $this->container->get('daedalus_stylish.css_printer');
    $this->uuid = $this->container->get('uuid')->generate();
  }

  /**
   * Condition blocks emit in structural order, not stored order.
   *
   * The update_style appends a condition block the first time its condition
   * is touched, so stored key order is write order. At-rule wrappers add
   * no specificity, so document order is the whole cascade between the
   * blocks: base written AFTER a width condition must still print before
   * it, or the base edit would beat the condition inside its own range
   * (the insertion-order cascade bug). Singles print before compounds so
   * a compound always beats the singles it extends.
   */
  public function testConditionOrderIsStructural(): void {
    $css = $this->printer->printMap([
      "entity:{$this->uuid}" => [
        'narrow+dark' => ['padding' => '3rem'],
        'medium' => ['padding' => '4rem'],
        'default' => ['padding' => '2rem'],
      ],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="entity:{$this->uuid}"]) {
  :scope {
    padding: 2rem;
  }
  @container (min-width: 768px) and (max-width: 1023px) {
    :scope {
      padding: 4rem;
    }
  }
  @container (max-width: 767px) {
    @media (prefers-color-scheme: dark) {
      :scope {
        padding: 3rem;
      }
    }
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * Structured conditions print with their wrappers, base first.
   */
  public function testStructuredConditions(): void {
    $css = $this->printer->printMap([
      "entity:{$this->uuid}" => [
        'default' => ['padding' => '2rem', 'background-color' => '#f5f5f5'],
        'hover' => ['background-color' => '#eaeaea'],
        'narrow' => ['padding' => '1rem'],
        'dark' => ['background-color' => '#2a2a2a'],
      ],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="entity:{$this->uuid}"]) {
  :scope {
    padding: 2rem;
    background-color: #f5f5f5;
  }
  :scope:is(:hover, [data-force-state~="hover"]) {
    background-color: #eaeaea;
  }
  @container (max-width: 767px) {
    :scope {
      padding: 1rem;
    }
  }
  @media (prefers-color-scheme: dark) {
    :scope {
      background-color: #2a2a2a;
    }
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * Scope-root entries co-target inherited text properties onto claimers.
   *
   * A conditional part claiming the block's text (a link wrapping the text
   * field) receives skin defaults like base:a DIRECT, beating anything the
   * root reaches by inheritance — so every scope-root condition block
   * emits a companion rule carrying ONLY its INHERITED_TEXT_PROPERTIES
   * subset at ':scope :where([data-claims-content])', inside the same
   * condition wrappers. Pseudo-class suffixes stay on :scope (the
   * condition holds on the styled element); a block with no co-targetable
   * property emits no companion. Box properties must never ride along
   * (double-applied padding/background), which is why the list is an
   * independent constant, not the Typography group.
   */
  public function testClaimsContentCoTarget(): void {
    // The load-bearing safety pin: the set is exactly these ten. Growing it
    // is a deliberate act (and must be mirrored client-side), never a side
    // effect of property-group growth.
    $this->assertSame([
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
    ], CssPrinter::INHERITED_TEXT_PROPERTIES);

    $css = $this->printer->printMap([
      "block:{$this->uuid}" => [
        'default' => ['color' => '#123456', 'padding' => '2rem', 'font-size' => '1.25rem'],
        'hover' => ['color' => 'purple', 'background-color' => '#eaeaea'],
        'narrow' => ['font-size' => '1rem', 'margin' => '0'],
        'dark' => ['background-color' => '#2a2a2a'],
      ],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="block:{$this->uuid}"]) {
  :scope {
    color: #123456;
    padding: 2rem;
    font-size: 1.25rem;
  }
  :scope :where([data-claims-content]) {
    color: #123456;
    font-size: 1.25rem;
  }
  :scope:is(:hover, [data-force-state~="hover"]) {
    color: purple;
    background-color: #eaeaea;
  }
  :scope:is(:hover, [data-force-state~="hover"]) :where([data-claims-content]) {
    color: purple;
  }
  @container (max-width: 767px) {
    :scope {
      font-size: 1rem;
      margin: 0;
    }
    :scope :where([data-claims-content]) {
      font-size: 1rem;
    }
  }
  @media (prefers-color-scheme: dark) {
    :scope {
      background-color: #2a2a2a;
    }
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * Only the scope root's own entry co-targets.
   *
   * A part entry styles a concrete interior element — a real part style on
   * a claiming element must WIN over the co-target, never feed it — and a
   * children entry already addresses descendants directly. Neither emits a
   * companion, whatever text properties they carry.
   */
  public function testCoTargetOnlyOnScopeRootEntries(): void {
    $css = $this->printer->printMap([
      "block:{$this->uuid}::.quote" => ['default' => ['color' => 'teal']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="block:{$this->uuid}"]) {
  :scope.quote, .quote {
    color: teal;
  }
}
CSS;
    $this->assertSame($expected, $css);

    $css = $this->printer->printMap([
      "block:{$this->uuid}::>" => ['default' => ['color' => 'red']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="block:{$this->uuid}"]) {
  :scope > * {
    color: red;
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * Compound conditions compose their parts' wrappers.
   *
   * At-rules nest around the rule in part order (outermost first),
   * pseudo-class suffixes append to the target selector, and the two mix
   * freely; a compound with any unknown part prints nothing (the emit
   * gate's membership posture, per part).
   */
  public function testCompoundConditions(): void {
    $css = $this->printer->printMap([
      "entity:{$this->uuid}" => [
        'narrow+dark' => ['padding' => '1rem'],
        'hover+medium' => ['background-color' => '#eaeaea'],
        'narrow+dark+reduced-motion+hover' => ['transition' => 'none'],
        'narrow+bogus' => ['color' => 'red'],
      ],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="entity:{$this->uuid}"]) {
  @container (min-width: 768px) and (max-width: 1023px) {
    :scope:is(:hover, [data-force-state~="hover"]) {
      background-color: #eaeaea;
    }
  }
  @container (max-width: 767px) {
    @media (prefers-color-scheme: dark) {
      :scope {
        padding: 1rem;
      }
    }
  }
  @container (max-width: 767px) {
    @media (prefers-color-scheme: dark) {
      @media (prefers-reduced-motion: reduce) {
        :scope:is(:hover, [data-force-state~="hover"]) {
          transition: none;
        }
      }
    }
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * Sub-element keys select their class inside the parent's scope root.
   *
   * The target is a selector LIST: the descendant part class plus the
   * scope root itself carrying it (":scope.cls") — a collapsed container
   * hoists its part class onto the element carrying the key, so
   * part-targeted styles must land there too. Pseudo suffixes append to
   * both selectors.
   */
  public function testSubElementKey(): void {
    $css = $this->printer->printMap([
      "block:{$this->uuid}::.quote" => [
        'default' => ['font-size' => '1.25rem'],
        'hover' => ['color' => 'purple'],
      ],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="block:{$this->uuid}"]) {
  :scope.quote, .quote {
    font-size: 1.25rem;
  }
  :scope.quote:is(:hover, [data-force-state~="hover"]), .quote:is(:hover, [data-force-state~="hover"]) {
    color: purple;
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * Tag-tier keys print as bare descendant tag rules inside the scope.
   *
   * A "{key}::p" entry (the element-scoped middle tier of the three-tier
   * text model) targets ONE bare tag selector: no ":scope" dual (the
   * scope root carries the key and is never one of its own text tags) and
   * no :where() wrap, so the rule sits at 0-0-1. That weight IS the tier
   * cascade: it beats the skin's zero-specificity base:{tag} default and
   * loses to every minted part class (0-1-0), regardless of document
   * order. Pseudo suffixes append to the tag; container keys keep the
   * standard donut; a tag children entry ("::ul::>") extends the single
   * target with ' > *'; tag entries never co-target (they already address
   * descendants directly).
   */
  public function testTagTierKey(): void {
    $css = $this->printer->printMap([
      "block:{$this->uuid}::p" => [
        'default' => ['font-size' => '1.25rem', 'color' => 'teal'],
        'hover' => ['color' => 'purple'],
      ],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="block:{$this->uuid}"]) {
  p {
    font-size: 1.25rem;
    color: teal;
  }
  p:is(:hover, [data-force-state~="hover"]) {
    color: purple;
  }
}
CSS;
    $this->assertSame($expected, $css);
    // Text properties on a tag entry never emit the co-target companion.
    $this->assertStringNotContainsString('[data-claims-content]', $css);

    // Container keys keep the standard donut: the tag rule stops at
    // nested container and block roots, whose text belongs to their own
    // key families.
    $css = $this->printer->printMap([
      "container:{$this->uuid}::ul" => ['default' => ['margin-block' => '0']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="container:{$this->uuid}"]) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {
  ul {
    margin-block: 0;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // A tag children entry keeps the single descendant target, extended
    // one level down.
    $css = $this->printer->printMap([
      "block:{$this->uuid}::ul::>" => ['default' => ['padding-inline-start' => '1rem']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="block:{$this->uuid}"]) {
  ul > * {
    padding-inline-start: 1rem;
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * Section keys print as donuts: limited at nested section and block roots.
   *
   * The daedalus_layout nests sections (containers embed sections at any depth) and
   * every layout template repeats the same part classes, so a section
   * block's scope stops at nested section roots — an outer section's styles
   * never restyle an inner section's identical markup. Nested layout markup
   * can also hide under a block root (a collapsed block's composed markup),
   * so the limit stops at nested block roots too.
   */
  public function testSectionScopeLimit(): void {
    $css = $this->printer->printMap([
      "container:{$this->uuid}" => ['default' => ['padding' => '4rem']],
      "container:{$this->uuid}::.daedalus-stylish-layout-container" => ['default' => ['max-width' => '60rem']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="container:{$this->uuid}"]) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {
  :scope {
    padding: 4rem;
  }
}
@scope ([data-selectable="container:{$this->uuid}"]) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {
  :scope.daedalus-stylish-layout-container, .daedalus-stylish-layout-container {
    max-width: 60rem;
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * The '::>' children pseudo-part prints as a ' > *' target extension.
   *
   * The children entry rides the addressed element's own targets one level
   * down: ':scope > *' for a root key, the part dual with ' > *' appended
   * for a part's children ('::.class::>'). Container-rooted children
   * entries take the CHILD-SHIFTED donut: their targets are exactly the
   * nested container/block roots the standard donut excludes, so the
   * boundary moves to those roots' children. The columns stay stylable,
   * their interiors stay out, and the descendant half of the part dual
   * cannot leak into a nested container's identical part markup.
   *
   * The part half's class wraps in :where(), capping every children
   * target at ':scope' weight (0-1-0): a child's own entry ties it and
   * wins on scope proximity, so per-child overrides beat children rules
   * at every depth, in both delivery modes (mode + share's cascade seam).
   */
  public function testChildrenPseudoPart(): void {
    $css = $this->printer->printMap([
      "container:{$this->uuid}::.daedalus-stylish-layout-container::>" => [
        'default' => ['flex-grow' => '1', 'min-width' => '0'],
        'narrow' => ['flex-grow' => '0'],
      ],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="container:{$this->uuid}"]) to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *) {
  :scope:where(.daedalus-stylish-layout-container) > *, .daedalus-stylish-layout-container > * {
    flex-grow: 1;
    min-width: 0;
  }
  @container (max-width: 767px) {
    :scope:where(.daedalus-stylish-layout-container) > *, .daedalus-stylish-layout-container > * {
      flex-grow: 0;
    }
  }
}
CSS;
    $this->assertSame($expected, $css);

    // Bare root children on a container key: ':scope > *' with the same
    // child-shifted donut.
    $css = $this->printer->printMap([
      "container:{$this->uuid}::>" => ['default' => ['min-width' => '0']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="container:{$this->uuid}"]) to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *) {
  :scope > * {
    min-width: 0;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // Non-container keys print children unbounded, like their other
    // entries.
    $css = $this->printer->printMap([
      "block:{$this->uuid}::>" => ['default' => ['gap' => '1rem']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="block:{$this->uuid}"]) {
  :scope > * {
    gap: 1rem;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // A doubled or misplaced children suffix is not a key and prints
    // nothing (the emit gate's grammar posture).
    $this->assertSame('', $this->printer->printMap([
      "container:{$this->uuid}::>::>" => ['default' => ['color' => 'red']],
      "container:{$this->uuid}::>x" => ['default' => ['color' => 'red']],
    ]));
  }

  /**
   * A map prints one @scope block per key, joined in stored order.
   */
  public function testMultipleKeys(): void {
    $other = $this->container->get('uuid')->generate();
    $css = $this->printer->printMap([
      "container:{$this->uuid}" => ['default' => ['padding' => '4rem']],
      "block:{$other}:field_title" => ['default' => ['font-weight' => '700']],
    ]);
    $this->assertSame(2, substr_count($css, '@scope ('));
    $this->assertStringContainsString("@scope ([data-selectable=\"container:{$this->uuid}\"])", $css);
    $this->assertStringContainsString("@scope ([data-selectable=\"block:{$other}:field_title\"])", $css);
  }

  /**
   * Variant references, bad keys, and unknown conditions print nothing.
   */
  public function testUnprintableEntriesAreSkipped(): void {
    $this->assertSame('', $this->printer->printMap([
      "block:{$this->uuid}" => ['variants' => [['variant' => 'cta_bold', 'fragment' => '']]],
      'not-a-key' => ['default' => ['padding' => '1rem']],
      "entity:{$this->uuid}" => ['bogus-condition' => ['color' => 'red']],
      "container:{$this->uuid}" => 'not-an-entry',
    ]));
  }

  /**
   * Keyframes print as a named at-rule outside any @scope.
   *
   * The named at-rule lane: an offset-keyed body, one declaration block
   * per step in stored order, the declaration hygiene gate reused inside
   * each step, empty and unprintable steps dropped, and nothing at all for
   * a name that is not a custom-ident or an animation with no printable
   * step. The client printer mirrors this text byte for byte.
   */
  public function testKeyframes(): void {
    $css = $this->printer->printKeyframes('motion-fade_in', [
      '0%' => ['opacity' => '0', 'transform' => 'translateY(1rem)'],
      '50%' => [],
      '75%' => ['font size' => 'bad'],
      '100%' => ['opacity' => '1', 'animation-timing-function' => 'ease-out'],
    ]);
    $expected = <<<CSS
@keyframes motion-fade_in {
  0% {
    opacity: 0;
    transform: translateY(1rem);
  }
  100% {
    opacity: 1;
    animation-timing-function: ease-out;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // from/to and decimal offsets print verbatim; a bad offset drops.
    $css = $this->printer->printKeyframes('motion-rise', [
      'from' => ['opacity' => '0'],
      '33.3%' => ['opacity' => '0.5'],
      'over' => ['opacity' => '0.7'],
      'to' => ['opacity' => '1'],
    ]);
    $this->assertSame("@keyframes motion-rise {\n  from {\n    opacity: 0;\n  }\n  33.3% {\n    opacity: 0.5;\n  }\n  to {\n    opacity: 1;\n  }\n}", $css);

    // The name gate and the no-printable-step outcome.
    $this->assertSame('', $this->printer->printKeyframes('motion fade', ['0%' => ['opacity' => '0']]));
    $this->assertSame('', $this->printer->printKeyframes('motion-x', ['0%' => [], '100%' => ['color' => "\x07red"]]));
    $this->assertSame('', $this->printer->printKeyframes('motion-x', []));
  }

  /**
   * A fragment prints its own entry and its part entries, nothing deeper.
   *
   * The delivery half of the distributed-reference model: a
   * {variant, fragment} reference delivers exactly its fragment's styles
   * at the caller's scope root — the fragment's own entry at :scope, its
   * '::.class' part entries at their class selectors. Deeper slot paths
   * under the fragment are OTHER fragments delivered by the elements
   * carrying their own references, so they never print here; no
   * positional selector compilation exists anymore. The container donut
   * rides fragments that ADDRESS a container — the '' fragment of a
   * container-family variant, and any container(n) fragment, whose class
   * sits on a container root — never block or field fragments.
   */
  public function testFragmentDelivery(): void {
    $styles = [
      '' => ['default' => ['padding' => '0']],
      '::.daedalus-stylish-layout-container' => ['default' => ['max-width' => '60rem']],
      'block(text,1)' => ['default' => ['margin' => '0']],
      'block(text,1)::.quote' => ['hover' => ['color' => 'purple']],
      'container(1)' => ['default' => ['gap' => '2rem']],
    ];
    $scope = 'layout:daedalus_stylish_container';

    $css = $this->printer->printFragmentStyles('.daedalus-stylish-variant--cta_card', $scope, $styles, '');
    $expected = <<<CSS
@scope (.daedalus-stylish-variant--cta_card) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {
  :scope {
    padding: 0;
  }
}
@scope (.daedalus-stylish-variant--cta_card) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {
  :scope.daedalus-stylish-layout-container, .daedalus-stylish-layout-container {
    max-width: 60rem;
  }
}
CSS;
    $this->assertSame($expected, $css);

    $css = $this->printer->printFragmentStyles('.daedalus-stylish-variant--cta_card--block-text-1', $scope, $styles, 'block(text,1)');
    $expected = <<<CSS
@scope (.daedalus-stylish-variant--cta_card--block-text-1) {
  :scope {
    margin: 0;
  }
}
@scope (.daedalus-stylish-variant--cta_card--block-text-1) {
  :scope.quote:is(:hover, [data-force-state~="hover"]), .quote:is(:hover, [data-force-state~="hover"]) {
    color: purple;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // A nested-container fragment keeps the donut: its class sits on a
    // container's own root element.
    $css = $this->printer->printFragmentStyles('.x', $scope, $styles, 'container(1)');
    $expected = <<<CSS
@scope (.x) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {
  :scope {
    gap: 2rem;
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * Children pseudo-part entries deliver through fragments (compiled form).
   *
   * A variant's '::>' and '::.class::>' entries ride their element's
   * fragment like part entries do, so the compiled shared stylesheet gets
   * the children rule at the variant-class scope root: the '.variant-class
   * > *' delivery of the equal-fill model, with the child-shifted donut
   * whenever the fragment addresses a container.
   */
  public function testFragmentChildrenDelivery(): void {
    $styles = [
      '' => ['default' => ['padding' => '0']],
      '::>' => ['default' => ['flex-grow' => '1', 'min-width' => '0'], 'narrow' => ['flex-grow' => '0']],
      '::.daedalus-stylish-layout-container::>' => ['default' => ['min-width' => '0']],
    ];

    $css = $this->printer->printFragmentStyles('.daedalus-stylish-variant--row', 'kind:container', $styles, '');
    $expected = <<<CSS
@scope (.daedalus-stylish-variant--row) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {
  :scope {
    padding: 0;
  }
}
@scope (.daedalus-stylish-variant--row) to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *) {
  :scope > * {
    flex-grow: 1;
    min-width: 0;
  }
  @container (max-width: 767px) {
    :scope > * {
      flex-grow: 0;
    }
  }
}
@scope (.daedalus-stylish-variant--row) to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *) {
  :scope:where(.daedalus-stylish-layout-container) > *, .daedalus-stylish-layout-container > * {
    min-width: 0;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // On a fragment addressing a non-container element the children entry
    // prints unbounded, like the element's own entry would.
    $css = $this->printer->printFragmentStyles('.daedalus-stylish-variant--pull--block-text-1', 'layout:daedalus_stylish_container', [
      'block(text,1)::>' => ['default' => ['gap' => '1rem']],
    ], 'block(text,1)');
    $expected = <<<CSS
@scope (.daedalus-stylish-variant--pull--block-text-1) {
  :scope > * {
    gap: 1rem;
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * The fragment grammar gates what prints; degradation stays graceful.
   *
   * A block-root fragment prints donut-free with its parts riding along;
   * an unresolvable scope degrades to the path-less key space (only the
   * '' fragment can print, donut-free); an unparseable fragment or one
   * the variant holds nothing at prints nothing; a part entry whose
   * selector fails the charset gate drops at the emit gate.
   */
  public function testFragmentGates(): void {
    $css = $this->printer->printFragmentStyles('.daedalus-stylish-variant--text_pull--field-field_body', 'block_type:text', [
      '' => ['default' => ['padding' => '2rem']],
      'field(field_body)' => ['default' => ['font-size' => '1.25rem']],
      'field(field_body)::.credit' => ['default' => ['font-style' => 'italic']],
      'field(field_body)::.bad{selector}' => ['default' => ['color' => 'red']],
    ], 'field(field_body)');
    $expected = <<<CSS
@scope (.daedalus-stylish-variant--text_pull--field-field_body) {
  :scope {
    font-size: 1.25rem;
  }
  :scope :where([data-claims-content]) {
    font-size: 1.25rem;
  }
}
@scope (.daedalus-stylish-variant--text_pull--field-field_body) {
  :scope.credit, .credit {
    font-style: italic;
  }
}
CSS;
    $this->assertSame($expected, $css);

    $degraded = [
      '' => ['default' => ['padding' => '1rem']],
      '::.quote' => ['default' => ['color' => 'teal']],
      'container(1)' => ['default' => ['color' => 'red']],
    ];
    $css = $this->printer->printFragmentStyles('.daedalus-stylish-variant--mystery', 'not-a-scope', $degraded, '');
    $expected = <<<CSS
@scope (.daedalus-stylish-variant--mystery) {
  :scope {
    padding: 1rem;
  }
}
@scope (.daedalus-stylish-variant--mystery) {
  :scope.quote, .quote {
    color: teal;
  }
}
CSS;
    $this->assertSame($expected, $css);
    // The slot key held by the degraded variant cannot parse against an
    // unknown root kind: nothing prints.
    $this->assertSame('', $this->printer->printFragmentStyles('.x', 'not-a-scope', $degraded, 'container(1)'));
    // A fragment the variant holds nothing at is valid but inert.
    $this->assertSame('', $this->printer->printFragmentStyles('.x', 'kind:container', $degraded, 'container(2)'));
    // The retired region segment left the grammar: nothing prints.
    $this->assertSame('', $this->printer->printFragmentStyles('.x', 'kind:container', $degraded, 'region(first)'));
    // A fragment the grammar rejects prints nothing.
    $this->assertSame('', $this->printer->printFragmentStyles('.x', 'kind:container', $degraded, 'not a key'));
  }

  /**
   * Motion triggers print their markers and the polyfill hold.
   *
   * `timeline-trigger` / `animation-trigger` say WHEN an animation plays;
   * browsers without the Animation Triggers draft drop them at parse time.
   * So each prints its marker custom property beside it (the runtime reads
   * the effective pair off computed style, through the same cascade), and
   * a block binding an animation to a trigger gets the hold: a sibling
   * `@supports not (animation-trigger: none)` after the rule and its
   * co-target companion, inside the same condition wrappers, pausing the
   * block's own targets (pseudo suffixes kept, part dual and children
   * extension each :not-extended) until the runtime stamps
   * `data-daedalus-stylish-triggered`. A `none` binding and a lone timeline-trigger
   * print their marker and no hold: nothing is bound, so nothing holds.
   */
  public function testTriggerHold(): void {
    $this->assertSame([
      'timeline-trigger' => '--daedalus-stylish-timeline-trigger',
      'animation-trigger' => '--daedalus-stylish-animation-trigger',
    ], CssPrinter::TRIGGER_MARKERS);
    $css = $this->printer->printMap([
      "block:{$this->uuid}" => [
        'default' => [
          'color' => 'teal',
          'animation-name' => 'motion-fade_in',
          'timeline-trigger' => '--in-view view() entry 20%',
          'animation-trigger' => '--in-view play-once',
        ],
        'hover' => ['animation-trigger' => '--in-view replay reset'],
        'narrow' => ['timeline-trigger' => '--in-view view() entry 50%', 'animation-trigger' => '--in-view play'],
        'medium' => ['animation-trigger' => 'none'],
        'reduced-motion' => ['timeline-trigger' => 'none'],
      ],
      "block:{$this->uuid}::.quote" => ['default' => ['animation-trigger' => '--in-view play-once']],
      "container:{$this->uuid}::>" => ['default' => ['animation-trigger' => '--in-view play-forwards play-backwards']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="block:{$this->uuid}"]) {
  :scope {
    color: teal;
    animation-name: motion-fade_in;
    timeline-trigger: --in-view view() entry 20%;
    --daedalus-stylish-timeline-trigger: --in-view view() entry 20%;
    animation-trigger: --in-view play-once;
    --daedalus-stylish-animation-trigger: --in-view play-once;
  }
  :scope :where([data-claims-content]) {
    color: teal;
  }
  @supports not (animation-trigger: none) {
    :scope:not([data-daedalus-stylish-triggered]) {
      animation-play-state: paused;
    }
  }
  :scope:is(:hover, [data-force-state~="hover"]) {
    animation-trigger: --in-view replay reset;
    --daedalus-stylish-animation-trigger: --in-view replay reset;
  }
  @supports not (animation-trigger: none) {
    :scope:is(:hover, [data-force-state~="hover"]):not([data-daedalus-stylish-triggered]) {
      animation-play-state: paused;
    }
  }
  @container (max-width: 767px) {
    :scope {
      timeline-trigger: --in-view view() entry 50%;
      --daedalus-stylish-timeline-trigger: --in-view view() entry 50%;
      animation-trigger: --in-view play;
      --daedalus-stylish-animation-trigger: --in-view play;
    }
    @supports not (animation-trigger: none) {
      :scope:not([data-daedalus-stylish-triggered]) {
        animation-play-state: paused;
      }
    }
  }
  @container (min-width: 768px) and (max-width: 1023px) {
    :scope {
      animation-trigger: none;
      --daedalus-stylish-animation-trigger: none;
    }
  }
  @media (prefers-reduced-motion: reduce) {
    :scope {
      timeline-trigger: none;
      --daedalus-stylish-timeline-trigger: none;
    }
  }
}
@scope ([data-selectable="block:{$this->uuid}"]) {
  :scope.quote, .quote {
    animation-trigger: --in-view play-once;
    --daedalus-stylish-animation-trigger: --in-view play-once;
  }
  @supports not (animation-trigger: none) {
    :scope.quote:not([data-daedalus-stylish-triggered]), .quote:not([data-daedalus-stylish-triggered]) {
      animation-play-state: paused;
    }
  }
}
@scope ([data-selectable="container:{$this->uuid}"]) to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *) {
  :scope > * {
    animation-trigger: --in-view play-forwards play-backwards;
    --daedalus-stylish-animation-trigger: --in-view play-forwards play-backwards;
  }
  @supports not (animation-trigger: none) {
    :scope > *:not([data-daedalus-stylish-triggered]) {
      animation-play-state: paused;
    }
  }
}
CSS;
    $this->assertSame($expected, $css);

    // The variant lane rides the same seam: a shipped entrance's root
    // fragment holds under its class exactly like an authored entry.
    $css = $this->printer->printFragmentStyles('.daedalus-stylish-variant--block_fade_in', 'kind:block', [
      '' => [
        'default' => ['animation-name' => 'motion-fade_in', 'animation-trigger' => '--in-view play-once'],
        'reduced-motion' => ['animation-name' => 'none', 'animation-trigger' => 'none'],
      ],
    ], '');
    $expected = <<<CSS
@scope (.daedalus-stylish-variant--block_fade_in) {
  :scope {
    animation-name: motion-fade_in;
    animation-trigger: --in-view play-once;
    --daedalus-stylish-animation-trigger: --in-view play-once;
  }
  @supports not (animation-trigger: none) {
    :scope:not([data-daedalus-stylish-triggered]) {
      animation-play-state: paused;
    }
  }
  @media (prefers-reduced-motion: reduce) {
    :scope {
      animation-name: none;
      animation-trigger: none;
      --daedalus-stylish-animation-trigger: none;
    }
  }
}
CSS;
    $this->assertSame($expected, $css);

    // The bare lane (skin base:{tag} defaults) holds too, its :not riding
    // the full selector; and a trigger the emit gate drops holds nothing.
    $css = $this->printer->printBareEntry(':where(.daedalus-stylish-token-scope) :where(h2)', NULL, [
      'default' => ['animation-trigger' => '--in-view play-once'],
      'hover' => ['animation-trigger' => 'x; } </style>'],
    ]);
    $expected = <<<CSS
:where(.daedalus-stylish-token-scope) :where(h2) {
  animation-trigger: --in-view play-once;
  --daedalus-stylish-animation-trigger: --in-view play-once;
}
@supports not (animation-trigger: none) {
  :where(.daedalus-stylish-token-scope) :where(h2):not([data-daedalus-stylish-triggered]) {
    animation-play-state: paused;
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * The emit gate drops unsafe declarations instead of printing.
   */
  public function testEmitHygiene(): void {
    $css = $this->printer->printMap([
      "entity:{$this->uuid}" => [
        'default' => [
          'padding' => '2rem',
          'background' => 'red; } </style><script>alert(1)</script>',
          'color' => "\x07red",
          'font size' => '1rem',
          'margin' => ['not' => 'scalar'],
        ],
      ],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="entity:{$this->uuid}"]) {
  :scope {
    padding: 2rem;
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * Entries where everything drops produce no @scope block at all.
   */
  public function testEmptyEntryPrintsNothing(): void {
    $this->assertSame('', $this->printer->printMap([
      "entity:{$this->uuid}" => [
        'default' => ['font size' => 'bad'],
      ],
    ]));
  }

}
