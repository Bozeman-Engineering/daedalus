<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
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
    'filter',
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
      "block:{$this->uuid}::> *" => ['default' => ['color' => 'red']],
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
   * A position is a qualifier of its compound, never a condition.
   *
   * The keys the fact-condition convergence writes (`> *:first-child`,
   * `&:empty`): the position prints at its compound inside the weight
   * discipline (no force-state dual, nothing the editor stamps can make
   * it true), a state on the same rule keeps its :is() dual as the
   * entry's condition suffix, and every pseudo-class condition the
   * printer meets prints the dual: there is no bare print left.
   */
  public function testPositionIsNeverCondition(): void {
    $css = $this->printer->printMap([
      "container:{$this->uuid}::> *:first-child" => ['default' => ['margin-top' => '0']],
      "container:{$this->uuid}::> *:nth-child(odd)" => ['default' => ['background-color' => '#f5f5f5']],
      "container:{$this->uuid}::> *:last-child" => ['hover' => ['color' => 'blue']],
      "block:{$this->uuid}::&:empty" => ['default' => ['display' => 'none']],
      "block:{$this->uuid}::&:nth-child(even)" => ['narrow' => ['padding' => '1rem']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="container:{$this->uuid}"]) {
  :scope > *:where(:first-child) {
    margin-top: 0;
  }
}
@scope ([data-selectable="container:{$this->uuid}"]) {
  :scope > *:where(:nth-child(odd)) {
    background-color: #f5f5f5;
  }
}
@scope ([data-selectable="container:{$this->uuid}"]) {
  :scope > *:where(:last-child):is(:hover, [data-force-state~="hover"]) {
    color: blue;
  }
}
@scope ([data-selectable="block:{$this->uuid}"]) {
  :scope:where(:empty) {
    display: none;
  }
}
@scope ([data-selectable="block:{$this->uuid}"]) {
  @container (max-width: 767px) {
    :scope:where(:nth-child(even)) {
      padding: 1rem;
    }
  }
}
CSS;
    $this->assertSame($expected, $css);
    $this->assertStringNotContainsString('data-force-state~="first-child"', $css);
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
   * order. Pseudo suffixes append to the tag; a container's tag rule
   * reaches its whole subtree (doctrine: a tag compound crosses nested
   * roots on purpose, bounded by proximity precedence, so a text block's
   * root paragraph IS reachable from its container); a tag children entry
   * ("::ul > *") extends the single target with ' > *'; tag entries never
   * co-target (they already address descendants directly).
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

    // A container's tag rule takes no donut: it reaches every paragraph
    // under the container, nested block roots included, and loses to a
    // block's own rule by proximity.
    $css = $this->printer->printMap([
      "container:{$this->uuid}::ul" => ['default' => ['margin-block' => '0']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="container:{$this->uuid}"]) {
  ul {
    margin-block: 0;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // A tag children entry keeps the single descendant target, extended
    // one level down.
    $css = $this->printer->printMap([
      "block:{$this->uuid}::ul > *" => ['default' => ['padding-inline-start' => '1rem']],
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
   * The daedalus_layout nests sections (containers embed sections at any depth)
   * and every layout template repeats the same part classes, so a section
   * block's scope stops at nested section roots — an outer section's styles
   * never restyle an inner section's identical markup. Nested layout markup can
   * also hide under a block root (a collapsed block's composed markup), so the
   * limit stops at nested block roots too.
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
   * The '> *' children selector prints as a ' > *' target extension.
   *
   * The children entry rides the addressed element's own targets one level
   * down: ':scope > *' for a root key, the part dual with ' > *' appended
   * for a part's children ('::.class > *'). A part-first children entry
   * on a container takes the CHILD-SHIFTED donut: its targets are exactly
   * the nested container/block roots the standard donut excludes, so the
   * boundary moves to those roots' children; the columns stay stylable,
   * their interiors stay out, and the descendant half of the part dual
   * cannot leak into a nested container's identical part markup. The bare
   * '> *' takes no donut at all: the child combinator self-limits.
   *
   * The part half's class wraps in :where(), capping every children
   * target at ':scope' weight (0-1-0): a child's own entry ties it and
   * wins on scope proximity, so per-child overrides beat children rules
   * at every depth, in both delivery modes (mode + share's cascade seam).
   */
  public function testChildrenPseudoPart(): void {
    $css = $this->printer->printMap([
      "container:{$this->uuid}::.daedalus-stylish-layout-container > *" => [
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

    // Bare root children on a container key: ':scope > *', no donut (the
    // child combinator self-limits).
    $css = $this->printer->printMap([
      "container:{$this->uuid}::> *" => ['default' => ['min-width' => '0']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="container:{$this->uuid}"]) {
  :scope > * {
    min-width: 0;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // Non-container keys print children unbounded, like their other
    // entries.
    $css = $this->printer->printMap([
      "block:{$this->uuid}::> *" => ['default' => ['gap' => '1rem']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="block:{$this->uuid}"]) {
  :scope > * {
    gap: 1rem;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // A doubled or misplaced children suffix, the retired '::>' spelling
    // and a non-canonical spelling are not keys and print nothing (the
    // emit gate's grammar posture).
    $this->assertSame('', $this->printer->printMap([
      "container:{$this->uuid}::>::>" => ['default' => ['color' => 'red']],
      "container:{$this->uuid}::>x" => ['default' => ['color' => 'red']],
      "container:{$this->uuid}::>" => ['default' => ['color' => 'red']],
      "container:{$this->uuid}::>*" => ['default' => ['color' => 'red']],
    ]));
  }

  /**
   * Type compounds and chains print by the specificity discipline.
   *
   * ONE link of the chain carries the weight (the last non-universal
   * compound, ':scope' included) and every other prints inside :where(),
   * so an ancestor's rule for a descendant weighs exactly what the
   * descendant's own rule weighs and loses to it on proximity. A type
   * compound selects by the element address stamp; the two kind spellings
   * select their families; a chain crossing into children takes no donut.
   */
  public function testSelectorDiscipline(): void {
    $cases = [
      "container:{$this->uuid}::> d-text" => ':where(:scope) > [data-element="block_type:text"]',
      "container:{$this->uuid}::d-formatted-text" => '[data-element="block_type:formatted_text"]',
      "container:{$this->uuid}::> d-text .text-block-link" => ':where(:scope) > :where([data-element="block_type:text"]) .text-block-link',
      "container:{$this->uuid}::> d-text p" => ':where(:scope) > :where([data-element="block_type:text"]) p',
      "container:{$this->uuid}::> d-container" => ':where(:scope) > [data-element^="layout:"]',
      "container:{$this->uuid}::d-block" => ':is([data-element^="block_type:"], [data-element^="block_plugin:"])',
      "container:{$this->uuid}::> * > *" => ':scope > * > *',
    ];
    foreach ($cases as $key => $target) {
      $css = $this->printer->printMap([$key => ['default' => ['color' => 'red'], 'hover' => ['color' => 'blue']]]);
      $this->assertStringNotContainsString(' to (', $css, "$key crosses into children, so it takes no donut.");
      $this->assertStringContainsString("\n  $target {\n    color: red;\n  }\n", $css, $key);
      $this->assertStringContainsString("\n  {$target}:is(:hover, [data-force-state~=\"hover\"]) {\n    color: blue;\n  }\n", $css, $key);
    }
    // A child part keeps the hoisted dual (the collapsed container wears
    // its part class on the root the scope names) and the standard donut.
    $css = $this->printer->printMap([
      "container:{$this->uuid}::> .quote" => ['default' => ['color' => 'red']],
    ]);
    $this->assertStringContainsString('to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"])', $css);
    $this->assertStringContainsString(':scope.quote, :where(:scope) > .quote {', $css);
    // A part reaching a type by '>' shifts the donut and puts the weight
    // on the type; a part continuing by a descendant combinator takes no
    // donut and keeps the tag's 0-0-1.
    $css = $this->printer->printMap([
      "container:{$this->uuid}::.section > d-text" => ['default' => ['color' => 'red']],
      "container:{$this->uuid}::.section p" => ['default' => ['color' => 'blue']],
    ]);
    $this->assertStringContainsString('to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *) {' . "\n" . '  :where(:scope):where(.section) > [data-element="block_type:text"], :where(.section) > [data-element="block_type:text"] {', $css);
    $this->assertStringContainsString('@scope ([data-selectable="container:' . $this->uuid . '"]) {' . "\n" . '  :where(:scope):where(.section) p, :where(.section) p {', $css);
  }

  /**
   * Sibling combinators and qualifiers print by the same discipline.
   *
   * A sibling combinator prints like `>` (` + `, ` ~ `); a qualifier
   * prints on its compound and adds no weight: `:has()` and `:not()` take
   * their argument's specificity, so every argument compound prints
   * inside :where() with its combinators bare (:where() takes complex
   * selectors, never relative ones, so the wrap is per compound). The
   * subject alone weighs, so `> *:not(d-image)` leaves the weight on
   * `:scope`. A part's dual carries the part's qualifiers and is skipped
   * when the part's next compound is a sibling (the scope root has no
   * sibling inside itself). The bare print path (the defaults tier)
   * prints the same compounds.
   */
  public function testRelationDiscipline(): void {
    $cases = [
      "container:{$this->uuid}::> d-text:has(a)" => ':where(:scope) > [data-element="block_type:text"]:has(:where(a))',
      "container:{$this->uuid}::> d-container:has(> d-image)" => ':where(:scope) > [data-element^="layout:"]:has(> :where([data-element="block_type:image"]))',
      "container:{$this->uuid}::> d-text:has(+ d-image)" => ':where(:scope) > [data-element="block_type:text"]:has(+ :where([data-element="block_type:image"]))',
      "container:{$this->uuid}::> p + p" => ':where(:scope) > :where(p) + p',
      "container:{$this->uuid}::> p ~ p" => ':where(:scope) > :where(p) ~ p',
      "container:{$this->uuid}::> p + *" => ':where(:scope) > p + *',
      "container:{$this->uuid}::> *:not(d-image)" => ':scope > *:not(:where([data-element="block_type:image"]))',
      "container:{$this->uuid}::> *:not(d-image):not(d-video)" => ':scope > *:not(:where([data-element="block_type:image"])):not(:where([data-element="block_type:video"]))',
      "container:{$this->uuid}::> d-text:not(:has(a))" => ':where(:scope) > [data-element="block_type:text"]:not(:has(:where(a)))',
      "container:{$this->uuid}::> d-text:has(a):not(.featured)" => ':where(:scope) > [data-element="block_type:text"]:has(:where(a)):not(:where(.featured))',
      "container:{$this->uuid}::> d-text .link ~ .link" => ':where(:scope) > :where([data-element="block_type:text"]) :where(.link) ~ .link',
      "block:{$this->uuid}::p:has(a)" => 'p:has(:where(a))',
    ];
    foreach ($cases as $key => $target) {
      $css = $this->printer->printMap([$key => ['default' => ['color' => 'red'], 'hover' => ['color' => 'blue']]]);
      $this->assertStringNotContainsString(' to (', $css, "$key crosses into children, so it takes no donut.");
      $this->assertStringContainsString("\n  $target {\n    color: red;\n  }\n", $css, $key);
      $this->assertStringContainsString("\n  {$target}:is(:hover, [data-force-state~=\"hover\"]) {\n    color: blue;\n  }\n", $css, $key);
    }
    $css = $this->printer->printMap([
      "container:{$this->uuid}::> d-text:has(a)" => ['default' => ['color' => 'red']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="container:{$this->uuid}"]) {
  :where(:scope) > [data-element="block_type:text"]:has(:where(a)) {
    color: red;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // A part-first sibling chain prints no dual; a qualified part keeps
    // it, the qualifier riding both halves, and the standard donut.
    $css = $this->printer->printMap([
      "container:{$this->uuid}::.section + .section" => ['default' => ['margin-top' => '0']],
      "container:{$this->uuid}::.section:has(a)" => ['default' => ['color' => 'red']],
      "container:{$this->uuid}::.section:has(a) > *" => ['default' => ['color' => 'blue']],
    ]);
    $this->assertStringContainsString("\n  :where(.section) + .section {\n", $css);
    $this->assertStringNotContainsString(':scope:where(.section) + .section', $css);
    $this->assertStringContainsString('to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {' . "\n" . '  :scope.section:has(:where(a)), .section:has(:where(a)) {', $css);
    $this->assertStringContainsString('to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *) {' . "\n" . '  :scope:where(.section):has(:where(a)) > *, .section:has(:where(a)) > * {', $css);

    // The bare lane prints the same compounds after the element's selector.
    $css = $this->printer->printBareEntry(':where([data-element="kind:container"])', RelativeSelector::parse('> d-text:has(+ d-image) ~ p'), ['default' => ['color' => 'red']]);
    $this->assertSame(":where([data-element=\"kind:container\"]) > :where([data-element=\"block_type:text\"]):has(+ :where([data-element=\"block_type:image\"])) ~ :where(p) {\n  color: red;\n}", $css);
  }

  /**
   * A position qualifier prints inside :where() at its compound.
   *
   * The qualified anchor prints on the scope root.
   *
   * Position is structure (the WHERE), selector text the markup decides,
   * and a position pseudo-class weighs 0-1-0 on its own; the discipline
   * puts ONE weight on the chain, so it prints inside its own :where()
   * with its canonical argument, inside a negation too, and a rule's
   * weight never moves (`> *:not(:last-child)` weighs what `> *` weighs,
   * `li:nth-child(2n+1)` what `li` weighs: proved by the specificity
   * algorithm, not by eye). The qualified anchor is the owner standing
   * first: it prints as the `:scope` link itself with its qualifiers
   * glued on, bare when it carries the weight and in :where() otherwise;
   * a part behind it keeps the hoisted dual (the class glued onto the
   * anchor's print) and the part's donut, and a condition suffix and the
   * box compose after it as on any host. The bare lane glues the anchor's
   * qualifiers onto the element's own selector.
   */
  public function testPositionQualifiers(): void {
    $cases = [
      ['> *:not(:last-child)', '> *', [':scope > *:not(:where(:last-child))']],
      ['> *:first-child', '> *', [':scope > *:where(:first-child)']],
      ['li:nth-child(2n+1)', 'li', ['li:where(:nth-child(2n+1))']],
      [
        '> d-text:first-of-type',
        '> d-text',
        [':where(:scope) > [data-element="block_type:text"]:where(:first-of-type)'],
      ],
      ['&:first-child', NULL, [':scope:where(:first-child)']],
      ['&:not(:last-child) > *', '> *', [':scope:not(:where(:last-child)) > *']],
      ['&:first-child > d-text', '> d-text', [':where(:scope):where(:first-child) > [data-element="block_type:text"]']],
      ['&:only-child > *:nth-last-child(-n+3)', '> *', [':scope:where(:only-child) > *:where(:nth-last-child(-n+3))']],
      ['&:first-child::before', '&::before', [':scope:where(:first-child)::before']],
      [
        '&:first-child .quote',
        '.quote',
        [':scope:where(:first-child).quote', ':where(:scope):where(:first-child) .quote'],
      ],
      [
        '&:first-child .quote > *',
        '.quote > *',
        [':scope:where(:first-child):where(.quote) > *', ':where(:scope):where(:first-child) .quote > *'],
      ],
      [
        '.section:not(:first-child) > *',
        '.section > *',
        [':scope:where(.section):not(:where(:first-child)) > *', '.section:not(:where(:first-child)) > *'],
      ],
    ];
    foreach ($cases as [$qualified, $plain, $targets]) {
      $this->assertSame($targets, CssPrinter::scopeTargets(RelativeSelector::parse($qualified)), $qualified);
      $plain_targets = CssPrinter::scopeTargets($plain === NULL ? NULL : RelativeSelector::parse($plain));
      $this->assertSame(array_map(self::specificity(...), $plain_targets), array_map(self::specificity(...), $targets), sprintf('%s weighs what %s weighs.', $qualified, $plain ?? 'the root'));
      $key = ($qualified === '&:first-child' || $qualified === '&:first-child::before' || $qualified === 'li:nth-child(2n+1)' ? 'block:' : 'container:') . $this->uuid . '::' . $qualified;
      $css = $this->printer->printMap([$key => ['default' => ['color' => 'red'], 'hover' => ['color' => 'blue']]]);
      $list = implode(', ', $targets);
      $this->assertStringContainsString("\n  $list {\n    color: red;\n  }\n", $css, $key);
      $this->assertStringContainsString("\n  " . implode(', ', array_map(static fn (string $target): string => preg_replace('/(::\w+)?$/', ':is(:hover, [data-force-state~="hover"])$1', $target, 1), $targets)) . " {\n    color: blue;\n  }\n", $css, $key);
    }
    // The algorithm sees a bare position weigh, so the equalities above
    // are not vacuous.
    $this->assertSame([0, 2, 0], self::specificity(':scope > *:first-child'));
    $this->assertSame([0, 1, 1], self::specificity('li:nth-child(2n+1)'));
    $this->assertSame([0, 1, 0], self::specificity(':scope:where(:first-child)'));

    // The donut follows the part behind the anchor; the anchor's own
    // children take none.
    $this->assertSame(CssPrinter::donutLimit(RelativeSelector::parse('.quote')), CssPrinter::donutLimit(RelativeSelector::parse('&:first-child .quote')));
    $this->assertSame(CssPrinter::donutLimit(RelativeSelector::parse('.quote > *')), CssPrinter::donutLimit(RelativeSelector::parse('&:first-child .quote > *')));
    $this->assertNull(CssPrinter::donutLimit(RelativeSelector::parse('&:first-child > *')));
    $css = $this->printer->printMap([
      "container:{$this->uuid}::&:first-child .quote" => ['default' => ['color' => 'red']],
      "block:{$this->uuid}::&:first-child::before" => ['default' => ['content' => '""'], 'hover' => ['color' => 'blue']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="container:{$this->uuid}"]) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {
  :scope:where(:first-child).quote, :where(:scope):where(:first-child) .quote {
    color: red;
  }
}
@scope ([data-selectable="block:{$this->uuid}"]) {
  :scope:where(:first-child)::before {
    content: "";
  }
  :scope:where(:first-child):is(:hover, [data-force-state~="hover"])::before {
    color: blue;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // The bare lane: the anchor's qualifiers glue onto the element's own
    // selector, a position prints inside :where() as in the @scope lane.
    $this->assertSame(":where([data-element=\"kind:block\"]):where(:first-child) {\n  margin-top: 0;\n}", $this->printer->printBareEntry(':where([data-element="kind:block"])', RelativeSelector::parse('&:first-child'), ['default' => ['margin-top' => '0']]));
    $this->assertSame(":where([data-element=\"kind:container\"]):not(:where(:last-child)) > * {\n  margin-bottom: 1rem;\n}", $this->printer->printBareEntry(':where([data-element="kind:container"])', RelativeSelector::parse('&:not(:last-child) > *'), ['default' => ['margin-bottom' => '1rem']]));
    $this->assertSame(":where([data-element=\"kind:container\"]) > *:not(:where(:last-child)) {\n  margin-bottom: 1rem;\n}", $this->printer->printBareEntry(':where([data-element="kind:container"])', RelativeSelector::parse('> *:not(:last-child)'), ['default' => ['margin-bottom' => '1rem']]));
  }

  /**
   * A qualifier prints at its compound inside :where(); a state as the dual.
   *
   * A class, an attribute and a pseudo-class qualify the compound they
   * stand on (the WHERE the markup decides), and each weighs 0-1-0 on its
   * own; the discipline puts ONE weight on the chain, so every one prints
   * inside its own :where() at its compound (`li:where(.active) > ul`
   * weighs what `li > ul` weighs: proved by the specificity algorithm, not
   * by eye), inside a negation and a `:has()` argument too. A pseudo-class
   * spelling a published state is posable, so it prints the same
   * force-state dual the condition suffix prints (ONE helper, two
   * callers), inside the :where(): the pose on the `li` reveals
   * `li:hover > ul`. A pseudo-class the roster does not publish
   * (`:visited`) prints bare. The anchor's qualifiers print on the scope
   * root (`&.open > ul` is the owner wearing the class, `&:hover > ul`
   * the owner hovered), a part's extra classes ride its hoisted dual, and
   * the condition suffix and the box still compose after the whole
   * chain. The bare lane glues the anchor's qualifiers onto the element's
   * own selector. An `:is()` prints inside its own :where() like the rest
   * (it takes its most specific member), its members bare inside it by
   * the same rules: the dual per posable member for Lily's
   * `.menu-item:is(:focus-within, :hover) > ul`, a type by its address
   * stamp, a tag bare (`*:where(:is(h1, h2))`, the fold of `h1, h2`,
   * weighs nothing as `*` does), a member's own `:has()` and state
   * unwrapped, and inside a `:has()` argument too.
   */
  public function testQualifiersPlace(): void {
    $hover = ':is(:hover, [data-force-state~="hover"])';
    $focus = ':is(:focus-within, [data-force-state~="focus-within"])';
    $either = ":is($focus, $hover)";
    $cases = [
      [
        '.menu-item:is(:focus-within, :hover) > ul',
        '.menu-item > ul',
        [":where(:scope):where(.menu-item):where($either) > ul", ":where(.menu-item):where($either) > ul"],
      ],
      [
        '> d-text:is(:focus-within, :hover) a',
        '> d-text a',
        [":where(:scope) > :where([data-element=\"block_type:text\"]):where($either) a"],
      ],
      [
        '> *:is(d-image, d-text)',
        '> *',
        [':scope > *:where(:is([data-element="block_type:image"], [data-element="block_type:text"]))'],
      ],
      [
        '> d-text:has(*:is(a, button))',
        '> d-text',
        [':where(:scope) > [data-element="block_type:text"]:has(*:where(:is(a, button)))'],
      ],
      ['li:hover > ul', 'li > ul', [":where(li):where($hover) > ul"]],
      [
        '> d-text:hover a',
        '> d-text a',
        [":where(:scope) > :where([data-element=\"block_type:text\"]):where($hover) a"],
      ],
      ['li.active > ul', 'li > ul', [':where(li):where(.active) > ul']],
      ['li[aria-current="page"] > a', 'li > a', [':where(li):where([aria-current="page"]) > a']],
      ['*[aria-expanded="true"] > ul', '> ul', ['*:where([aria-expanded="true"]) > ul']],
      ['&.open > ul', '> ul', [':where(:scope):where(.open) > ul']],
      ['&:hover > ul', '> ul', [":where(:scope):where($hover) > ul"]],
      ['&.open', NULL, [':scope:where(.open)']],
      ['a:visited', 'a', ['a:where(:visited)']],
      ['> *:not(:hover)', '> *', [":scope > *:not(:where($hover))"]],
      [
        '> d-text:has(a:hover)',
        '> d-text',
        [":where(:scope) > [data-element=\"block_type:text\"]:has(:where(a):where($hover))"],
      ],
      ['.featured.quote', '.featured', [':scope.featured:where(.quote)', '.featured:where(.quote)']],
      [
        'li.active:hover[aria-current="page"] > a',
        'li > a',
        [":where(li):where(.active):where($hover):where([aria-current=\"page\"]) > a"],
      ],
    ];
    $conditions = $this->container->get('plugin.manager.daedalus_stylish_condition_type')->getConditions();
    foreach ($cases as [$qualified, $plain, $targets]) {
      $this->assertSame($targets, CssPrinter::scopeTargets(RelativeSelector::parse($qualified), $conditions), $qualified);
      $plain_targets = CssPrinter::scopeTargets($plain === NULL ? NULL : RelativeSelector::parse($plain), $conditions);
      $this->assertSame(array_map(self::specificity(...), $plain_targets), array_map(self::specificity(...), $targets), sprintf('%s weighs what %s weighs.', $qualified, $plain ?? 'the root'));
      $css = $this->printer->printMap([
        "container:{$this->uuid}::$qualified" => ['default' => ['color' => 'red'], 'hover' => ['color' => 'blue']],
      ]);
      $this->assertStringContainsString("\n  " . implode(', ', $targets) . " {\n    color: red;\n  }\n", $css, $qualified);
      $this->assertStringContainsString("\n  " . implode(', ', array_map(static fn (string $target): string => $target . $hover, $targets)) . " {\n    color: blue;\n  }\n", $css, $qualified);
    }
    // The fold of `h1, h2` by descent weighs nothing, as `*` does: the
    // :is() rides inside :where(), its tag members bare. A member's own
    // qualifiers print by the same rules, unwrapped (the one :where() has
    // zeroed them): a `:has()` argument bare, a state the dual.
    foreach ([
      ['*:is(h1, h2)', '*:where(:is(h1, h2))'],
      ['*:is(.a, :not(.b))', '*:where(:is(.a, :not(.b)))'],
      ['*:is(.x:has(em), a:hover)', "*:where(:is(.x:has(em), a$hover))"],
    ] as [$selector, $target]) {
      $this->assertSame([$target], CssPrinter::scopeTargets(RelativeSelector::parse($selector), $conditions), $selector);
      $this->assertSame([0, 0, 0], self::specificity($target), "$selector weighs nothing.");
    }
    $this->assertSame([0, 0, 1], self::specificity('*:is(h1, h2)'));
    $this->assertSame([0, 1, 1], self::specificity("*:is(.x:has(em), a$hover)"));
    // Without a condition table no pseudo-class is a published state: the
    // same qualifier prints bare, which is what makes the dual the
    // table's verdict and not the grammar's, inside :is() too.
    $this->assertSame([':where(li):where(:hover) > ul'], CssPrinter::scopeTargets(RelativeSelector::parse('li:hover > ul')));
    $this->assertSame([
      ':where(:scope):where(.menu-item):where(:is(:focus-within, :hover)) > ul',
      ':where(.menu-item):where(:is(:focus-within, :hover)) > ul',
    ], CssPrinter::scopeTargets(RelativeSelector::parse('.menu-item:is(:focus-within, :hover) > ul')));
    // The algorithm sees a bare qualifier weigh, so the equalities above
    // are not vacuous.
    $this->assertSame([0, 1, 2], self::specificity('li.active > ul'));
    $this->assertSame([0, 1, 2], self::specificity('li:hover > ul'));
    $this->assertSame([0, 1, 2], self::specificity('li[aria-current="page"] > a'));
    $this->assertSame([0, 2, 0], self::specificity(":scope$hover"));
    $this->assertSame([0, 1, 0], self::specificity(":scope:where($hover)"));

    // The box and the condition suffix compose after the qualified chain,
    // the state qualifier at its compound and the condition on the host.
    $css = $this->printer->printMap([
      "container:{$this->uuid}::li:hover > ul::before" => [
        'default' => ['content' => '""'],
        'hover' => ['color' => 'blue'],
      ],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="container:{$this->uuid}"]) {
  :where(li):where($hover) > ul::before {
    content: "";
  }
  :where(li):where($hover) > ul$hover::before {
    color: blue;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // The bare lane: the anchor's qualifiers glue onto the element's own
    // selector, a chain's at their compounds, the dual included.
    $this->assertSame(":where([data-element=\"kind:container\"]):where(.open) > :where(ul) {\n  display: block;\n}", $this->printer->printBareEntry(':where([data-element="kind:container"])', RelativeSelector::parse('&.open > ul'), ['default' => ['display' => 'block']]));
    $this->assertSame(":where([data-element=\"kind:container\"]) :where(li):where($hover) > :where(ul) {\n  display: block;\n}", $this->printer->printBareEntry(':where([data-element="kind:container"])', RelativeSelector::parse('li:hover > ul'), ['default' => ['display' => 'block']]));
  }

  /**
   * The specificity of one compound or complex selector, as [a, b, c].
   *
   * CSS's algorithm over the printer's output, so the weight discipline
   * is proved rather than eyeballed: :where() weighs nothing; :is(),
   * :not() and :has() weigh their most specific argument; an id 1-0-0; a
   * class, an attribute and every other pseudo-class (:scope included)
   * 0-1-0; a tag and a pseudo-element 0-0-1; `*` and combinators nothing.
   */
  protected static function specificity(string $selector): array {
    $weight = [0, 0, 0];
    $length = strlen($selector);
    for ($i = 0; $i < $length; $i++) {
      $char = $selector[$i];
      if ($char === '#' || $char === '.') {
        preg_match('/[A-Za-z0-9_-]+/A', $selector, $m, 0, $i + 1);
        $i += strlen($m[0]);
        $weight[$char === '#' ? 0 : 1]++;
      }
      elseif ($char === '[') {
        $i = strpos($selector, ']', $i);
        $weight[1]++;
      }
      elseif ($char === ':') {
        $element = ($selector[$i + 1] ?? '') === ':';
        preg_match('/[a-z-]+/A', $selector, $m, 0, $i + ($element ? 2 : 1));
        $name = $m[0];
        $i += strlen($name) + ($element ? 1 : 0);
        $argument = NULL;
        if (($selector[$i + 1] ?? '') === '(') {
          $depth = 0;
          for ($j = $i + 1; $j < $length; $j++) {
            $depth += match ($selector[$j]) {
              '(' => 1,
              ')' => -1,
              default => 0,
            };
            if ($depth === 0) {
              break;
            }
          }
          $argument = substr($selector, $i + 2, $j - $i - 2);
          $i = $j;
        }
        if ($element) {
          $weight[2]++;
        }
        elseif (in_array($name, ['is', 'not', 'has'], TRUE)) {
          $most = max(array_map(self::specificity(...), self::selectorList($argument)));
          $weight = [$weight[0] + $most[0], $weight[1] + $most[1], $weight[2] + $most[2]];
        }
        elseif ($name !== 'where') {
          $weight[1]++;
        }
      }
      elseif (ctype_alpha($char)) {
        preg_match('/[A-Za-z0-9-]+/A', $selector, $m, 0, $i);
        $i += strlen($m[0]) - 1;
        $weight[2]++;
      }
    }
    return $weight;
  }

  /**
   * The members of a selector list, split at its TOP-LEVEL commas only.
   *
   * A nested list (`:not(:where(:is(:hover, […])))`) stays one member, so
   * a zero-weight :where() around a dual weighs nothing, as CSS says.
   *
   * @return string[]
   *   The members, in order.
   */
  protected static function selectorList(string $text): array {
    $members = [];
    $depth = 0;
    $start = 0;
    $length = strlen($text);
    for ($i = 0; $i < $length; $i++) {
      if ($text[$i] === '(') {
        $depth++;
      }
      elseif ($text[$i] === ')') {
        $depth--;
      }
      elseif ($depth === 0 && substr($text, $i, 2) === ', ') {
        $members[] = substr($text, $start, $i - $start);
        $start = $i + 2;
      }
    }
    $members[] = substr($text, $start);
    return $members;
  }

  /**
   * A terminal pseudo-element prints last, after the host and its states.
   *
   * A generated box (`::before`, `::after`) has no node: the selector's
   * chain addresses its HOST by the discipline, and the pseudo-element
   * glues onto every target after the last compound's qualifiers, after
   * the condition suffixes (the state holds on the host:
   * `:scope:is(:hover, …)::before`) and after the hold's `:not()` (the
   * runtime stamps the host). :where() cannot wrap a pseudo-element, so
   * every rule addressing a box weighs its host's weight plus 0-0-1, own
   * and ancestor alike, and the ties the discipline relies on hold on the
   * boxes as on their hosts. The owner's anchor (`&::before`) is the
   * scoping root's own box and never co-targets: the companion is the
   * root's own entry's, and a box has no claiming interior. The part
   * dual carries the pseudo on both halves and keeps the part's donut;
   * the bare lane prints the same tail after the element's selector; a
   * fragment worn at a rule continues the anchor onto the rule's landing
   * and nothing continues under a rule that already ends in a box.
   */
  public function testPseudoElement(): void {
    $css = $this->printer->printMap([
      "block:{$this->uuid}::&::before" => [
        'default' => ['content' => '""', 'color' => 'red', 'animation-trigger' => '--in-view play-once'],
        'hover' => ['color' => 'blue'],
        'narrow' => ['display' => 'none'],
      ],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="block:{$this->uuid}"]) {
  :scope::before {
    content: "";
    color: red;
    animation-trigger: --in-view play-once;
    --daedalus-stylish-animation-trigger: --in-view play-once;
  }
  @supports not (animation-trigger: none) {
    :scope:not([data-daedalus-stylish-triggered])::before {
      animation-play-state: paused;
    }
  }
  :scope:is(:hover, [data-force-state~="hover"])::before {
    color: blue;
  }
  @container (max-width: 767px) {
    :scope::before {
      display: none;
    }
  }
}
CSS;
    $this->assertSame($expected, $css);
    $this->assertStringNotContainsString('data-claims-content', $css);

    // Every chain shape carries the box after its host, the discipline
    // untouched beneath it; a pseudo rule crosses into children exactly
    // as its host does, so it takes no donut.
    $cases = [
      "container:{$this->uuid}::> d-text::before" => [':where(:scope) > [data-element="block_type:text"]', '::before'],
      "container:{$this->uuid}::> d-text:has(a)::after" => [
        ':where(:scope) > [data-element="block_type:text"]:has(:where(a))',
        '::after',
      ],
      "container:{$this->uuid}::> p + p::before" => [':where(:scope) > :where(p) + p', '::before'],
      "container:{$this->uuid}::> *::after" => [':scope > *', '::after'],
      "container:{$this->uuid}::d-block::before" => [
        ':is([data-element^="block_type:"], [data-element^="block_plugin:"])',
        '::before',
      ],
      "container:{$this->uuid}::> d-text .link::after" => [
        ':where(:scope) > :where([data-element="block_type:text"]) .link',
        '::after',
      ],
      "block:{$this->uuid}::p::before" => ['p', '::before'],
      "block:{$this->uuid}::&::after" => [':scope', '::after'],
    ];
    foreach ($cases as $key => [$host, $pseudo]) {
      $css = $this->printer->printMap([$key => ['default' => ['content' => '""'], 'hover' => ['color' => 'blue']]]);
      $this->assertStringNotContainsString(' to (', $css, "$key takes no donut.");
      $this->assertStringContainsString("\n  {$host}{$pseudo} {\n    content: \"\";\n  }\n", $css, $key);
      $this->assertStringContainsString("\n  {$host}:is(:hover, [data-force-state~=\"hover\"]){$pseudo} {\n    color: blue;\n  }\n", $css, $key);
      $this->assertStringNotContainsString('data-claims-content', $css, $key);
    }

    // The part dual carries the box on both halves and keeps the part's
    // donut (standard for the bare part, child-shifted through `>`, none
    // after a sibling step, where the dual is skipped too).
    $css = $this->printer->printMap([
      "container:{$this->uuid}::.quote::after" => ['default' => ['content' => '""'], 'hover' => ['color' => 'purple']],
      "container:{$this->uuid}::.section > *::before" => ['default' => ['content' => '""']],
      "container:{$this->uuid}::.section + .section::before" => ['default' => ['content' => '""']],
    ]);
    $this->assertStringContainsString('to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {' . "\n" . '  :scope.quote::after, .quote::after {', $css);
    $this->assertStringContainsString("\n  :scope.quote:is(:hover, [data-force-state~=\"hover\"])::after, .quote:is(:hover, [data-force-state~=\"hover\"])::after {\n", $css);
    $this->assertStringContainsString('to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *) {' . "\n" . '  :scope:where(.section) > *::before, .section > *::before {', $css);
    $this->assertStringContainsString("@scope ([data-selectable=\"container:{$this->uuid}\"]) {\n  :where(.section) + .section::before {", $css);
    $this->assertStringNotContainsString(':scope:where(.section) + .section::before', $css);

    // Any pseudo-element the browser spells prints as the box's tail.
    $this->assertStringContainsString(":scope::marker {\n    color: red;", $this->printer->printMap([
      "block:{$this->uuid}::&::marker" => ['default' => ['color' => 'red']],
    ]));
    $this->assertStringContainsString("\n  li::first-letter {\n    color: red;", $this->printer->printMap([
      "block:{$this->uuid}::li::first-letter" => ['default' => ['color' => 'red']],
    ]));
    // Outside the grammar nothing prints: a functional pseudo-element, a
    // bare anchor, a space before the box, a box behind a box.
    $this->assertSame('', $this->printer->printMap([
      "block:{$this->uuid}::&::part(x)" => ['default' => ['color' => 'red']],
      "block:{$this->uuid}::&" => ['default' => ['color' => 'red']],
      "block:{$this->uuid}::& ::before" => ['default' => ['color' => 'red']],
      "block:{$this->uuid}::.quote::before::after" => ['default' => ['color' => 'red']],
    ]));

    // The bare lane (the defaults tier, both scopes of it) prints the
    // same tail after the element's selector, its states before the box.
    $css = $this->printer->printBareEntry(':where(.daedalus-stylish-token-scope [data-element="kind:block"])', RelativeSelector::parse('&::before'), [
      'default' => ['content' => '""', 'animation-trigger' => '--in-view play-once'],
      'hover' => ['color' => 'purple'],
    ]);
    $expected = <<<CSS
:where(.daedalus-stylish-token-scope [data-element="kind:block"])::before {
  content: "";
  animation-trigger: --in-view play-once;
  --daedalus-stylish-animation-trigger: --in-view play-once;
}
@supports not (animation-trigger: none) {
  :where(.daedalus-stylish-token-scope [data-element="kind:block"]):not([data-daedalus-stylish-triggered])::before {
    animation-play-state: paused;
  }
}
:where(.daedalus-stylish-token-scope [data-element="kind:block"]):is(:hover, [data-force-state~="hover"])::before {
  color: purple;
}
CSS;
    $this->assertSame($expected, $css);
    $css = $this->printer->printBareEntry(':where(.daedalus-stylish-token-scope [data-element="block_type:text"])', RelativeSelector::parse('.quote::after'), ['default' => ['content' => '""']]);
    $this->assertSame(":where(.daedalus-stylish-token-scope [data-element=\"block_type:text\"]) :where(.quote)::after {\n  content: \"\";\n}", $css);
    $css = $this->printer->printBareEntry(':where(.daedalus-stylish-token-scope [data-element="kind:container"])', RelativeSelector::parse('> d-text::before'), ['default' => ['content' => '""']]);
    $this->assertSame(":where(.daedalus-stylish-token-scope [data-element=\"kind:container\"]) > :where([data-element=\"block_type:text\"])::before {\n  content: \"\";\n}", $css);

    // Worn at a rule, a fragment's anchor continues onto the rule's
    // landing and a part's box under the rule by descent.
    $styles = [
      '' => ['default' => ['padding' => '1rem']],
      '::&::before' => ['default' => ['content' => '""'], 'hover' => ['color' => 'purple']],
      '::.link::after' => ['default' => ['content' => '""']],
    ];
    $css = $this->printer->printFragmentStyles('[data-selectable="container:x"]', 'block_type:text', $styles, '', RelativeSelector::fromString('> d-text'));
    $expected = <<<CSS
@scope ([data-selectable="container:x"]) {
  :where(:scope) > [data-element="block_type:text"] {
    padding: 1rem;
  }
}
@scope ([data-selectable="container:x"]) {
  :where(:scope) > [data-element="block_type:text"]::before {
    content: "";
  }
  :where(:scope) > [data-element="block_type:text"]:is(:hover, [data-force-state~="hover"])::before {
    color: purple;
  }
}
@scope ([data-selectable="container:x"]) {
  :where(:scope) > :where([data-element="block_type:text"]) .link::after {
    content: "";
  }
}
CSS;
    $this->assertSame($expected, $css);

    // A rule ending in a box admits no continuation: only the fragment's
    // own entry prints, at the box.
    $css = $this->printer->printFragmentStyles('[data-selectable="container:x"]', 'block_type:text', $styles, '', RelativeSelector::fromString('> d-text::before'));
    $expected = <<<CSS
@scope ([data-selectable="container:x"]) {
  :where(:scope) > [data-element="block_type:text"]::before {
    padding: 1rem;
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

  /**
   * A map prints one @scope block per key, joined in the map's order.
   *
   * The printer imposes no order of its own: delivery hands it the
   * canonical order (NodeTreeBuilder::styleMapOf(), CssEmissionTest).
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
   * A variant's '::> *' and '::.class > *' entries ride their element's
   * fragment like part entries do, so the compiled shared stylesheet gets
   * the children rule at the variant-class scope root: the '.variant-class
   * > *' delivery of the equal-fill model, the part's children with the
   * child-shifted donut whenever the fragment addresses a container, the
   * bare children with none.
   */
  public function testFragmentChildrenDelivery(): void {
    $styles = [
      '' => ['default' => ['padding' => '0']],
      '::> *' => ['default' => ['flex-grow' => '1', 'min-width' => '0'], 'narrow' => ['flex-grow' => '0']],
      '::.daedalus-stylish-layout-container > *' => ['default' => ['min-width' => '0']],
    ];

    $css = $this->printer->printFragmentStyles('.daedalus-stylish-variant--row', 'kind:container', $styles, '');
    $expected = <<<CSS
@scope (.daedalus-stylish-variant--row) to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {
  :scope {
    padding: 0;
  }
}
@scope (.daedalus-stylish-variant--row) {
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
      'block(text,1)::> *' => ['default' => ['gap' => '1rem']],
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
   * browsers without the Animation Triggers draft drop them at parse time. So
   * each prints its marker custom property beside it (the runtime reads the
   * effective pair off computed style, through the same cascade), and a block
   * binding an animation to a trigger gets the hold: a sibling `@supports not
   * (animation-trigger: none)` after the rule and its co-target companion,
   * inside the same condition wrappers, pausing the block's own targets (pseudo
   * suffixes kept, part dual and children extension each :not-extended) until
   * the runtime stamps `data-daedalus-stylish-triggered`. A `none` binding and
   * a lone timeline-trigger print their marker and no hold: nothing is bound,
   * so nothing holds.
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
      "container:{$this->uuid}::> *" => ['default' => ['animation-trigger' => '--in-view play-forwards play-backwards']],
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
@scope ([data-selectable="container:{$this->uuid}"]) {
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
   * The emit gate covers everything the printer writes.
   *
   * A value prints `url()` only in the module's two spellings (the media
   * token and the resolved file on this site), wherever the call stands,
   * and nothing that does not close as it opens; condition text passes the
   * same gate, so a query that would end the rule drops its block; an
   * attribute value prints its gated marks as hex escapes, and a key that
   * spells them raw is not canonical and prints nothing.
   */
  public function testEmitGateCoversEverythingPrinted(): void {
    $key = "block:{$this->uuid}";
    $css = $this->printer->printMap([
      $key => [
        'default' => [
          'color' => 'red',
          'background-image' => "url('media:{$this->uuid}')",
          'list-style-image' => 'url("/sites/default/files/a.png")',
          'content' => '"\201C"',
          'background' => 'url(//example.com/x)',
          'mask-image' => 'var(--m, url(//example.com/x))',
          'width' => 'calc(1px + url(//example.com/x))',
          '--x' => 'url("https://example.com/x")',
          'border-image' => 'image-set("//example.com/x" 1x)',
          'margin' => 'calc(1px',
          'padding' => '1px)',
          'font-family' => '"Inter',
          'height' => 'u\72l(//example.com/x)',
          'min-height' => '1px /* ) */',
        ],
        '@supports (display: grid){}</style><script>alert(1)</script>' => ['color' => 'blue'],
        '@supports (display: grid' => ['color' => 'green'],
        '@supports (background: url(//example.com/x))' => ['gap' => '2px'],
        '@supports (display: grid) and (gap: 1px)' => ['gap' => '1px'],
      ],
      $key . '::&[title="\3c /style\3e \3c script\3e "]' => ['default' => ['color' => 'teal']],
      $key . '::&[title="</style><script>"]' => ['default' => ['color' => 'red']],
      $key . '::a[href$=";}{"]' => ['default' => ['color' => 'red']],
    ]);
    $expected = <<<CSS
@scope ([data-selectable="$key"]) {
  :scope {
    color: red;
    background-image: url('media:{$this->uuid}');
    list-style-image: url("/sites/default/files/a.png");
    content: "\\201C";
  }
  :scope :where([data-claims-content]) {
    color: red;
  }
  @supports (display: grid) and (gap: 1px) {
    :scope {
      gap: 1px;
    }
  }
}
@scope ([data-selectable="$key"]) {
  :scope:where([title="\\3c /style\\3e \\3c script\\3e "]) {
    color: teal;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // A scope root, a scope limit or a bare selector the gate refuses
    // prints no block.
    $entry = ['default' => ['color' => 'red']];
    $this->assertSame('', $this->printer->printEntry('.a { } </style>', NULL, $entry));
    $this->assertSame('', $this->printer->printEntry('.a', NULL, $entry, '.b) { } :is(.c'));
    $this->assertNotSame('', $this->printer->printEntry('.a, .b', NULL, $entry, ':scope > *'));
    $this->assertSame('', $this->printer->printBareEntry('p { } </style>', NULL, $entry));
    $this->assertNotSame('', $this->printer->printBareEntry(':where(p)', NULL, $entry));
  }

  /**
   * A font face prints only when its family and its file pass the gate.
   */
  public function testFontFaceGate(): void {
    $face = fn (string $family, string $url): string => $this->printer->printFontFace($family, '400', 'normal', $url, 'woff2');
    $this->assertStringContainsString('font-family: "Inter Tight";', $face('"Inter Tight"', '/sites/default/files/fonts/inter.woff2'));
    $this->assertStringContainsString('src: url("https://cdn.example.com/inter.woff2") format("woff2");', $face('"Inter"', 'https://cdn.example.com/inter.woff2'));
    $this->assertSame('', $face('"Inter</style><script>"', '/inter.woff2'));
    $this->assertSame('', $face('"Inter;}"', '/inter.woff2'));
    $this->assertSame('', $face('"Inter"', '/inter.woff2?</style>'));
    $this->assertSame('', $face('"Inter"', '/inter.woff2;}'));
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

  /**
   * A fragment printed under a rule continues every suffix by descent.
   *
   * The rule-wear rebase: the '' suffix prints at the rule's own targets,
   * '::.cls' at `{rule} .cls` (under a tag rule too, since a class
   * qualifies any compound: `a .link`), '::> *' at `{rule} > *`; and the
   * donut follows the rebased selector's shape (a type rule is never
   * part-first, so a container-scoped variant worn at `> d-container`
   * prints unbounded). A suffix the grammar refuses under the rule (any
   * suffix under a box rule) prints nothing: printer.test.mjs locks it.
   */
  public function testFragmentUnderRule(): void {
    $styles = [
      '' => ['default' => ['padding' => '1rem'], 'hover' => ['color' => 'purple']],
      '::.link' => ['default' => ['font-weight' => '700']],
      '::> *' => ['default' => ['margin' => '0']],
    ];
    $css = $this->printer->printFragmentStyles('[data-selectable="container:x"]', 'block_type:text', $styles, '', RelativeSelector::fromString('> d-text'));
    $expected = <<<CSS
@scope ([data-selectable="container:x"]) {
  :where(:scope) > [data-element="block_type:text"] {
    padding: 1rem;
  }
  :where(:scope) > [data-element="block_type:text"]:is(:hover, [data-force-state~="hover"]) {
    color: purple;
  }
}
@scope ([data-selectable="container:x"]) {
  :where(:scope) > :where([data-element="block_type:text"]) .link {
    font-weight: 700;
  }
}
@scope ([data-selectable="container:x"]) {
  :where(:scope) > [data-element="block_type:text"] > * {
    margin: 0;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // Under a tag rule the class suffix continues by descent like any
    // other (`a .link`), the tag inside :where() and the class carrying
    // the weight.
    $css = $this->printer->printFragmentStyles('[data-selectable="container:x"]', 'base:a', $styles, '', RelativeSelector::fromString('a'));
    $expected = <<<CSS
@scope ([data-selectable="container:x"]) {
  a {
    padding: 1rem;
  }
  a:is(:hover, [data-force-state~="hover"]) {
    color: purple;
  }
}
@scope ([data-selectable="container:x"]) {
  :where(a) .link {
    font-weight: 700;
  }
}
@scope ([data-selectable="container:x"]) {
  a > * {
    margin: 0;
  }
}
CSS;
    $this->assertSame($expected, $css);

    // A container variant at a type rule: no donut (the rebased selector
    // is not part-first), the part continued under the rule.
    $css = $this->printer->printFragmentStyles('[data-selectable="container:x"]', 'layout:daedalus_stylish_container', [
      '' => ['default' => ['gap' => '2rem']],
      '::.daedalus-stylish-layout-container' => ['default' => ['max-width' => '60rem']],
      'container(1)' => ['default' => ['padding' => '0']],
    ], '', RelativeSelector::fromString('> d-container'));
    $expected = <<<CSS
@scope ([data-selectable="container:x"]) {
  :where(:scope) > [data-element^="layout:"] {
    gap: 2rem;
  }
}
@scope ([data-selectable="container:x"]) {
  :where(:scope) > :where([data-element^="layout:"]) .daedalus-stylish-layout-container {
    max-width: 60rem;
  }
}
CSS;
    $this->assertSame($expected, $css);
  }

}
