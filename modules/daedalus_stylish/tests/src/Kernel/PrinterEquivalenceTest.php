<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

// cspell:ignore scopep
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Locks the PHP and JS printers together (round-trip equivalence).
 *
 * The client printer powers the live preview and the code editor view; the
 * PHP printer emits what persists. The two must produce byte-identical CSS
 * for the same map, or the user previews something other than what saves.
 * This test prints a battery of maps through both — the PHP service in
 * process, the JS module via node (tests/js/print-cases.mjs) — and asserts
 * exact equality. The pure-JS suites under tests/js/ run from JsSuitesTest.
 *
 * The condition wrapper table is passed to the JS side exactly as
 * ClientStyleGuide ships it to the browser — live thresholds included — so
 * the test exercises the same data path production uses.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class PrinterEquivalenceTest extends KernelTestBase {

  use NodeSuitesTrait;

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
   * The PHP and JS printers emit byte-identical CSS for the same maps.
   */
  public function testPrintersAreEquivalent(): void {
    $uuid_a = 'aaaaaaaa-1111-2222-3333-444444444444';
    $uuid_b = 'bbbbbbbb-1111-2222-3333-444444444444';

    $cases = [
      // Structured conditions of every wrapper shape: none, pseudo, at-rule.
      [
        "entity:$uuid_a" => [
          'default' => ['padding' => '2rem', 'background-color' => '#f5f5f5'],
          'hover' => ['background-color' => '#eaeaea'],
          'narrow' => ['padding' => '1rem'],
          'medium' => ['padding' => '3rem'],
          'dark' => ['background-color' => '#2a2a2a'],
          'reduced-motion' => ['transition' => 'none'],
          'focus' => ['outline' => '2px solid blue'],
        ],
      ],
      // Sub-element key.
      [
        "block:$uuid_a::.quote" => [
          'default' => ['font-size' => '1.25rem'],
          'hover' => ['color' => 'purple'],
        ],
      ],
      // Section sub-element: the donut limit prints on both sides.
      [
        "container:$uuid_a::.daedalus-stylish-layout-container" => [
          'default' => ['max-width' => '60rem', 'margin-inline' => 'auto'],
        ],
      ],
      // Multiple keys, one @scope block each, stored order.
      [
        "container:$uuid_a" => ['default' => ['padding' => '4rem']],
        "block:$uuid_b:field_title" => ['default' => ['font-weight' => '700']],
        "field:$uuid_b:field_body" => ['hover' => ['color' => 'teal']],
      ],
      // Compound conditions: at-rules nest in part order, pseudo suffixes
      // append, mixes compose, non-canonical spellings print as stored,
      // and a compound with an unknown part prints nothing on both sides.
      [
        "entity:$uuid_a" => [
          'narrow+dark' => ['padding' => '1rem'],
          'dark+narrow' => ['padding' => '1.5rem'],
          'hover+medium' => ['background-color' => '#eaeaea'],
          'narrow+dark+reduced-motion+hover' => ['transition' => 'none'],
          'narrow+bogus' => ['color' => 'red'],
        ],
        "block:$uuid_a::.quote" => [
          'dark+hover' => ['color' => 'purple'],
        ],
      ],
      // Unprintable entries: variant refs, bad keys, unknown conditions,
      // non-array entries.
      [
        "block:$uuid_a" => ['variants' => [['variant' => 'cta_bold', 'fragment' => '']]],
        'not-a-key' => ['default' => ['padding' => '1rem']],
        "entity:$uuid_a" => ['bogus-condition' => ['color' => 'red']],
        "container:$uuid_a" => 'not-an-entry',
      ],
      // The '::>' children pseudo-part: root and part-composed forms, the
      // child-shifted donut on container keys, no donut elsewhere, and a
      // malformed doubled suffix dropping identically.
      [
        "container:$uuid_a::>" => ['default' => ['min-width' => '0']],
        "container:$uuid_a::.daedalus-stylish-layout-container::>" => [
          'default' => ['flex-grow' => '1'],
          'narrow' => ['flex-grow' => '0'],
        ],
        "entity:$uuid_a::>" => ['default' => ['gap' => '1rem']],
        "block:$uuid_a::>::>" => ['default' => ['color' => 'red']],
      ],
      // Emit hygiene: unsafe declarations drop identically.
      [
        "entity:$uuid_a" => [
          'default' => [
            'padding' => '2rem',
            'background' => 'red; } </style><script>alert(1)</script>',
            'color' => "\x07red",
            'font size' => '1rem',
          ],
        ],
        "container:$uuid_a" => [
          'default' => ['font size' => 'bad'],
        ],
      ],
      // Co-target: a scope-root entry re-targets its INHERITED_TEXT_PROPERTIES
      // subset onto declared text-claiming elements — companion appended
      // after the rule INSIDE the same condition wrappers (one at-rule
      // block holds both), pseudo suffixes staying on :scope, stored
      // property order kept, box properties filtered out; part and '::>'
      // children entries never co-target, and a block with no
      // co-targetable property emits no companion.
      [
        "block:$uuid_a" => [
          'default' => ['color' => 'teal', 'padding' => '2rem', 'font-weight' => '700'],
          'hover' => ['text-decoration-line' => 'none'],
          'narrow' => ['font-size' => '1.25rem', 'margin' => '0'],
          'narrow+dark+hover' => ['letter-spacing' => '0.05em'],
          'medium' => ['padding' => '3rem'],
        ],
        "block:$uuid_a::.quote" => ['default' => ['color' => 'red']],
        "entity:$uuid_a::>" => ['default' => ['color' => 'blue']],
      ],
      // Empty map.
      [],
      // Tag-tier keys (the element-scoped middle tier of the three-tier
      // text model): ONE bare descendant tag target inside the scope (no
      // ':scope' dual, no :where — 0-0-1 IS the tier cascade), pseudo
      // suffixes and at-rules composing on it, the container donut kept,
      // a tag children entry ('::ul::>') extending the single target and
      // taking the child-shifted donut on container keys, never a
      // co-target companion, and a minted part beside the tag printing
      // its usual dual — byte-identical on both sides.
      [
        "block:$uuid_a::p" => [
          'default' => ['font-size' => '1.25rem', 'color' => 'teal'],
          'hover' => ['color' => 'purple'],
          'narrow+dark' => ['font-size' => '1rem'],
        ],
        "container:$uuid_a::ul" => ['default' => ['margin-block' => '0']],
        "container:$uuid_a::ul::>" => ['default' => ['padding-inline-start' => '1rem']],
        "block:$uuid_a::ul::>" => ['hover' => ['gap' => '0.5rem']],
        "block:$uuid_a::.s-a3" => ['default' => ['color' => 'red']],
      ],
      // The motion trigger lane: a marker custom property beside each
      // trigger declaration and, beside any block binding an animation
      // to a trigger, the @supports polyfill hold over the block's own
      // targets (pseudo suffixes kept, at-rules wrapping rule, co-target
      // companion and hold together, the part dual and the children
      // extension each :not-extended); a `none` binding and a lone
      // timeline-trigger print their marker and no hold; a trigger the
      // emit gate drops holds nothing on either side.
      [
        "block:$uuid_a" => [
          'default' => [
            'color' => 'teal',
            'animation-name' => 'motion-fade_in',
            'timeline-trigger' => '--in-view view() entry 20%',
            'animation-trigger' => '--in-view play-once',
          ],
          'hover' => ['animation-trigger' => '--in-view replay reset'],
          'narrow+dark' => ['timeline-trigger' => '--in-view view() entry 50%', 'animation-trigger' => '--in-view play'],
          'medium' => ['animation-trigger' => 'none'],
          'reduced-motion' => ['timeline-trigger' => 'none'],
        ],
        "block:$uuid_a::.quote" => [
          'default' => ['animation-trigger' => '--in-view play-once'],
          'hover' => ['animation-trigger' => 'NONE'],
        ],
        "container:$uuid_a::>" => ['default' => ['animation-trigger' => '--in-view play-forwards play-backwards']],
        "block:$uuid_b::p" => ['default' => ['animation-trigger' => '--in-view play-once']],
        "entity:$uuid_b" => ['default' => ['animation-trigger' => 'x; } </style>']],
      ],
    ];

    // Variant cases print through the fragment printer pair
    // (CssPrinter::printFragmentStyles / printFragmentStyles): the
    // fragment lookup (own entry + '::.class' part entries, deeper
    // fragments excluded), the section donut on section-addressing
    // fragments, and the grammar gate must not diverge between the
    // compiled file and the client.
    $composition_styles = [
      '' => ['default' => ['padding' => '0'], 'hover' => ['outline' => '1px solid teal']],
      '::.daedalus-stylish-layout-container' => ['default' => ['max-width' => '60rem']],
      // Children pseudo-part entries ride their element's fragment like
      // part entries: the root's children, a part's children (the
      // child-shifted donut on container-addressing fragments), a block
      // fragment's children (donut-free), and a malformed '::>x' suffix
      // dropping identically at both emit gates.
      '::>' => ['default' => ['flex-grow' => '1'], 'narrow' => ['flex-grow' => '0']],
      '::.daedalus-stylish-layout-container::>' => ['default' => ['min-width' => '0']],
      'block(text,2)::>' => ['hover' => ['gap' => '1rem']],
      '::>x' => ['default' => ['color' => 'red']],
      'block(text,2)' => ['default' => ['margin' => '0'], 'hover' => ['color' => 'teal']],
      'block(text,1)::.quote' => ['hover' => ['color' => 'purple']],
      'container(1)' => ['default' => ['gap' => '2rem']],
      'container(1)/block(image,1)/field(field_image)' => ['default' => ['border-radius' => '0.5rem']],
      '::.bad{selector}' => ['default' => ['color' => 'red']],
      'block(text,3)' => 'not-an-entry',
    ];
    $variant_cases = [
      // The '' fragment of a section-family composition: root + part
      // entries with the donut, deeper fragments excluded, the part
      // charset gate and a non-array entry dropping identically.
      [
        'scopeRoot' => '.daedalus-stylish-variant--cta_card, .daedalus-stylish-variant--aaaaaaaa-1111-2222-3333-444444444444',
        'scope' => 'layout:layout_twocol_section',
        'styles' => $composition_styles,
        'fragment' => '',
      ],
      // A block fragment whose only styles are a part entry.
      [
        'scopeRoot' => '.daedalus-stylish-variant--cta_card--block-text-1',
        'scope' => 'layout:layout_twocol_section',
        'styles' => $composition_styles,
        'fragment' => 'block(text,1)',
      ],
      // A block fragment with structured styles under two conditions.
      [
        'scopeRoot' => '.daedalus-stylish-variant--cta_card--block-text-2',
        'scope' => 'layout:layout_twocol_section',
        'styles' => $composition_styles,
        'fragment' => 'block(text,2)',
      ],
      // A nested-container fragment keeps the donut: its class sits on a
      // container root, whose descendants repeat layout markup.
      [
        'scopeRoot' => '.daedalus-stylish-variant--cta_card--container-1',
        'scope' => 'layout:layout_twocol_section',
        'styles' => $composition_styles,
        'fragment' => 'container(1)',
      ],
      // A deep field fragment, donut-free.
      [
        'scopeRoot' => '.daedalus-stylish-variant--cta_card--container-1--block-image-1--field-field_image',
        'scope' => 'layout:layout_twocol_section',
        'styles' => $composition_styles,
        'fragment' => 'container(1)/block(image,1)/field(field_image)',
      ],
      // A block-root composition's field fragment: part entries ride it.
      [
        'scopeRoot' => '.daedalus-stylish-variant--text_pull--field-field_body',
        'scope' => 'block_type:text',
        'styles' => [
          '' => ['default' => ['padding' => '2rem']],
          'field(field_body)' => ['default' => ['font-size' => '1.25rem']],
          'field(field_body)::.credit' => ['default' => ['font-style' => 'italic']],
        ],
        'fragment' => 'field(field_body)',
      ],
      // An unresolvable scope degrades to the path-less key space: the ''
      // fragment prints (donut-free), slot keys never match it.
      [
        'scopeRoot' => '.daedalus-stylish-variant--mystery',
        'scope' => 'not-a-scope',
        'styles' => [
          '' => ['default' => ['padding' => '1rem']],
          '::.quote' => ['default' => ['color' => 'teal']],
          'container(1)' => ['default' => ['color' => 'red']],
        ],
        'fragment' => '',
      ],
      // An unparseable fragment prints nothing at all.
      [
        'scopeRoot' => '.daedalus-stylish-variant--mystery--container-1',
        'scope' => 'not-a-scope',
        'styles' => ['container(1)' => ['default' => ['color' => 'red']]],
        'fragment' => 'container(1)',
      ],
      // A fragment the variant holds nothing at is valid but inert.
      [
        'scopeRoot' => '.daedalus-stylish-variant--empty--container-9',
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => '1rem']]],
        'fragment' => 'region(x)',
      ],
      // The motion trigger lane on a variant: a shipped entrance's root
      // fragment (the block family, no donut) carries the trigger pair in
      // `default`, so the compiled file and the preview hold it the same
      // way; a hover block re-binding prints its own hold under the
      // suffix, and the reduced-motion unset prints no hold.
      [
        'scopeRoot' => '.daedalus-stylish-variant--block_fade_in',
        'scope' => 'kind:block',
        'styles' => [
          '' => [
            'default' => [
              'animation-name' => 'motion-fade_in',
              'animation-duration' => '600ms',
              'timeline-trigger' => '--in-view view() entry 20%',
              'animation-trigger' => '--in-view play-once',
            ],
            'hover' => ['animation-trigger' => '--in-view replay reset'],
            'reduced-motion' => ['animation-name' => 'none', 'animation-trigger' => 'none'],
          ],
        ],
        'fragment' => '',
      ],
    ];

    // Keyframes cases print through the named at-rule printer pair
    // (CssPrinter::printKeyframes / printKeyframes): the offset-keyed body
    // outside any @scope, the name and offset gates, and the declaration
    // hygiene inside a step must not diverge between the compiled file
    // and the keyframes editor's live preview.
    $keyframes_cases = [
      // Canonical steps, several declarations, stored order kept.
      [
        'name' => 'motion-fade_in',
        'steps' => [
          '0%' => ['opacity' => '0', 'transform' => 'translateY(1rem)'],
          '100%' => ['opacity' => '1', 'transform' => 'none'],
        ],
      ],
      // Every offset spelling the gate admits prints verbatim; an empty
      // step, a non-array step, an unprintable-only step and a bad offset
      // drop identically.
      [
        'name' => 'motion-rise',
        'steps' => [
          'from' => ['opacity' => '0'],
          '33.5%' => [],
          '50%' => 'not-a-step',
          '.5%' => ['font size' => 'bad'],
          '150' => ['opacity' => '0.5'],
          'over' => ['opacity' => '0.5'],
          'to' => ['opacity' => '1', 'background' => 'red; } </style><script>alert(1)</script>'],
        ],
      ],
      // A var() reference and a per-step easing are ordinary declarations.
      [
        'name' => 'motion-pulse',
        'steps' => [
          '50%' => ['opacity' => 'var(--swatch-dim, 0.5)', 'animation-timing-function' => 'ease-in-out'],
        ],
      ],
      // A name failing the custom-ident gate prints nothing.
      [
        'name' => 'motion fade',
        'steps' => ['0%' => ['opacity' => '0']],
      ],
      // No printable step prints nothing.
      [
        'name' => 'motion-empty',
        'steps' => ['0%' => [], '100%' => ['font size' => 'bad']],
      ],
      // No steps at all.
      [
        'name' => 'motion-none',
        'steps' => [],
      ],
    ];

    // The same conditions payload ClientStyleGuide ships to the browser.
    $conditions = [];
    foreach ($this->container->get('plugin.manager.daedalus_stylish_condition_type')->getConditions() as $name => $definition) {
      $conditions[$name] = [
        'label' => (string) $definition['label'],
        'wrapper' => $definition['wrapper'],
        'axis' => $definition['axis'],
      ];
    }

    $printer = $this->container->get('daedalus_stylish.css_printer');
    $php_output = array_map(
      static fn (array $case) => $printer->printMap($case),
      $cases,
    );
    $php_variant_output = array_map(
      static fn (array $case) => $printer->printFragmentStyles($case['scopeRoot'], $case['scope'], $case['styles'], $case['fragment']),
      $variant_cases,
    );
    $php_keyframes_output = array_map(
      static fn (array $case) => $printer->printKeyframes($case['name'], $case['steps']),
      $keyframes_cases,
    );

    $input = json_encode([
      'conditions' => $conditions,
      // JSON round-trip the cases exactly as the client would receive them
      // (a map with non-scalar noise still must not diverge).
      'cases' => $cases,
      'variantCases' => $variant_cases,
      'keyframesCases' => $keyframes_cases,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    $js_output = json_decode($this->runNodeScript('tests/js/print-cases.mjs', $input), TRUE);

    $this->assertIsArray($js_output);
    $this->assertIsArray($js_output['cases'] ?? NULL);
    $this->assertIsArray($js_output['variantCases'] ?? NULL);
    $this->assertSameSize($php_output, $js_output['cases']);
    foreach ($php_output as $index => $expected) {
      $this->assertSame($expected, $js_output['cases'][$index], "Case $index diverged between the PHP and JS printers.");
    }
    $this->assertSameSize($php_variant_output, $js_output['variantCases']);
    foreach ($php_variant_output as $index => $expected) {
      $this->assertSame($expected, $js_output['variantCases'][$index], "Variant case $index diverged between the PHP and JS printers.");
    }
    $this->assertIsArray($js_output['keyframesCases'] ?? NULL);
    $this->assertSameSize($php_keyframes_output, $js_output['keyframesCases']);
    foreach ($php_keyframes_output as $index => $expected) {
      $this->assertSame($expected, $js_output['keyframesCases'][$index], "Keyframes case $index diverged between the PHP and JS printers.");
    }
    // Guard against a silently-empty battery.
    $this->assertStringContainsString('@scope (', $php_output[0]);
    // Part selectors are dual-target on both sides: the descendant class
    // reaches the root-depth part element, the ":scope" half reaches a
    // collapsed container root carrying the hoisted part class.
    $this->assertStringContainsString(':scope.daedalus-stylish-layout-container, .daedalus-stylish-layout-container {', $php_output[2]);
    // Children pseudo-part targets and the child-shifted donut are in the
    // battery (silently-empty guard for the '::>' grammar).
    $this->assertStringContainsString(') to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *)', $php_output[6]);
    // The part half's class wraps in :where(): children targets never
    // outweigh ':scope', so a child's own entry wins on scope proximity.
    $this->assertStringContainsString(':scope:where(.daedalus-stylish-layout-container) > *, .daedalus-stylish-layout-container > * {', $php_output[6]);
    // The co-target companion is in the battery (silently-empty guard):
    // unconditional, and pseudo-suffixed on :scope with the claiming
    // element merely receiving; the variant lane rides the same seam. A
    // part-entry-only map never co-targets.
    $this->assertStringContainsString(':scope :where([data-claims-content]) {', $php_output[8]);
    $this->assertStringContainsString(':scope:is(:hover, [data-force-state~="hover"]) :where([data-claims-content]) {', $php_output[8]);
    $this->assertStringContainsString('[data-claims-content]', $php_variant_output[2]);
    $this->assertStringNotContainsString('[data-claims-content]', $php_output[1]);
    // Tag-tier cases are in the battery (silently-empty guard for the
    // '::{tag}' grammar): the bare single target, the tag children's
    // single extended target, and no co-target companion on tag entries.
    $this->assertStringContainsString("\n  p {", $php_output[10]);
    $this->assertStringNotContainsString(':scopep', $php_output[10]);
    $this->assertStringContainsString('ul > * {', $php_output[10]);
    $this->assertStringNotContainsString('[data-claims-content]', $php_output[10]);
    $this->assertStringContainsString(':scope.s-a3, .s-a3 {', $php_output[10]);
    $this->assertStringContainsString(':scope > * {', $php_variant_output[0]);
    $this->assertStringContainsString(') to (', $php_variant_output[0]);
    $this->assertStringContainsString('@scope (.daedalus-stylish-variant--cta_card--block-text-1)', $php_variant_output[1]);
    $this->assertSame('', $php_variant_output[7]);
    $this->assertSame('', $php_variant_output[8]);
    // The trigger lane is in the battery (silently-empty guard): markers
    // beside both properties, the hold beside a bound block under its own
    // suffixes, on the part dual and the children extension, none beside
    // a `none` binding, a lone timeline-trigger, or a dropped value; the
    // variant lane rides the same seam.
    $this->assertStringContainsString("timeline-trigger: --in-view view() entry 20%;\n    --daedalus-stylish-timeline-trigger: --in-view view() entry 20%;", $php_output[11]);
    $this->assertStringContainsString("animation-trigger: --in-view play-once;\n    --daedalus-stylish-animation-trigger: --in-view play-once;", $php_output[11]);
    $this->assertStringContainsString("@supports not (animation-trigger: none) {\n    :scope:not([data-daedalus-stylish-triggered]) {\n      animation-play-state: paused;", $php_output[11]);
    $this->assertStringContainsString(':scope:is(:hover, [data-force-state~="hover"]):not([data-daedalus-stylish-triggered]) {', $php_output[11]);
    $this->assertStringContainsString(':scope.quote:not([data-daedalus-stylish-triggered]), .quote:not([data-daedalus-stylish-triggered]) {', $php_output[11]);
    $this->assertStringContainsString(':scope > *:not([data-daedalus-stylish-triggered]) {', $php_output[11]);
    $this->assertStringContainsString('p:not([data-daedalus-stylish-triggered]) {', $php_output[11]);
    $this->assertSame(6, substr_count($php_output[11], '@supports not (animation-trigger: none) {'));
    $this->assertStringContainsString("--daedalus-stylish-animation-trigger: none;", $php_output[11]);
    $this->assertStringNotContainsString('</style>', $php_output[11]);
    $this->assertStringNotContainsString("entity:$uuid_b", $php_output[11]);
    $this->assertSame(2, substr_count($php_variant_output[9], '@supports not (animation-trigger: none) {'));
    $this->assertStringContainsString("--daedalus-stylish-animation-trigger: none;", $php_variant_output[9]);
    // Keyframes cases are in the battery (silently-empty guard for the
    // named at-rule lane): the at-rule prelude, offsets as step selectors
    // outside any @scope, every admitted spelling kept, the gates dropping
    // the rest, and the three empty outcomes.
    $this->assertStringStartsWith('@keyframes motion-fade_in {', $php_keyframes_output[0]);
    $this->assertStringNotContainsString('@scope', $php_keyframes_output[0]);
    $this->assertStringContainsString("\n  from {", $php_keyframes_output[1]);
    $this->assertStringContainsString("\n  to {", $php_keyframes_output[1]);
    $this->assertStringNotContainsString('33.5%', $php_keyframes_output[1]);
    $this->assertStringNotContainsString('.5%', $php_keyframes_output[1]);
    $this->assertStringNotContainsString('150', $php_keyframes_output[1]);
    $this->assertStringNotContainsString('over', $php_keyframes_output[1]);
    $this->assertStringNotContainsString('script', $php_keyframes_output[1]);
    $this->assertStringContainsString('animation-timing-function: ease-in-out;', $php_keyframes_output[2]);
    $this->assertSame('', $php_keyframes_output[3]);
    $this->assertSame('', $php_keyframes_output[4]);
    $this->assertSame('', $php_keyframes_output[5]);
  }

}
