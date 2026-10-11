<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

// cspell:ignore scopep
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
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
    'file',
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
      // The '> *' children selector: root and part-composed forms, the
      // child-shifted donut on a container's part children, no donut
      // elsewhere, and a malformed doubled suffix and the retired '::>'
      // spelling dropping identically.
      [
        "container:$uuid_a::> *" => ['default' => ['min-width' => '0']],
        "container:$uuid_a::.daedalus-stylish-layout-container > *" => [
          'default' => ['flex-grow' => '1'],
          'narrow' => ['flex-grow' => '0'],
        ],
        "entity:$uuid_a::> *" => ['default' => ['gap' => '1rem']],
        "block:$uuid_a::>::>" => ['default' => ['color' => 'red']],
        "block:$uuid_a::>" => ['default' => ['color' => 'red']],
      ],
      // Type compounds and chains: the specificity discipline (one
      // weighted link, :where() on the rest, the address stamp for a
      // type, :is() for the block family), conditions composing on the
      // last compound, the part-first donut shifting per child level.
      [
        "container:$uuid_a::> pb-text" => ['default' => ['color' => 'red'], 'hover' => ['color' => 'blue']],
        "container:$uuid_a::> pb-text .text-block-link" => ['narrow' => ['padding' => '0']],
        "container:$uuid_a::pb-block" => ['default' => ['margin' => '0']],
        "container:$uuid_a::> pb-container > *" => ['default' => ['gap' => '1rem']],
        "container:$uuid_a::.band > pb-text" => ['default' => ['color' => 'teal']],
        "container:$uuid_a::.band p" => ['default' => ['color' => 'navy']],
        "container:$uuid_a::> .quote" => ['default' => ['color' => 'olive']],
        "block:$uuid_a::> pb-formatted-text p" => ['hover' => ['color' => 'purple']],
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
      // property order kept, box properties filtered out; part and '::> *'
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
        "entity:$uuid_a::> *" => ['default' => ['color' => 'blue']],
      ],
      // Empty map.
      [],
      // Tag-tier keys (the element-scoped middle tier of the three-tier
      // text model): ONE bare descendant tag target inside the scope (no
      // ':scope' dual, no :where — 0-0-1 IS the tier cascade), pseudo
      // suffixes and at-rules composing on it, the container donut kept,
      // a tag children entry ('::ul > *') extending the single target and
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
        "container:$uuid_a::ul > *" => ['default' => ['padding-inline-start' => '1rem']],
        "block:$uuid_a::ul > *" => ['hover' => ['gap' => '0.5rem']],
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
        "container:$uuid_a::> *" => ['default' => ['animation-trigger' => '--in-view play-forwards play-backwards']],
        "block:$uuid_b::p" => ['default' => ['animation-trigger' => '--in-view play-once']],
        "entity:$uuid_b" => ['default' => ['animation-trigger' => 'x; } </style>']],
      ],
      // Structural facts print bare (no force-state dual) on both sides:
      // alone, beside a posable state, under an at-rule, on a selector
      // entry and on the root.
      [
        "container:$uuid_a::> *" => [
          'first-child' => ['margin-top' => '0'],
          'odd' => ['background-color' => '#f5f5f5'],
          'hover+last-child' => ['color' => 'blue'],
        ],
        "block:$uuid_b" => [
          'empty' => ['display' => 'none'],
          'even+narrow' => ['padding' => '1rem'],
        ],
        "block:$uuid_b::.quote" => [
          'only-child' => ['font-style' => 'italic'],
        ],
      ],
      // Relations: sibling combinators print like `>`, a qualifier prints
      // on its compound with every argument compound inside :where() and
      // bare combinators, an implied subject prints nothing, a part's
      // dual carries the part's qualifiers and is skipped after a
      // sibling step, and the children extension keeps its donut rule.
      [
        "container:$uuid_a::> p + p" => ['default' => ['margin-top' => '0'], 'hover' => ['color' => 'blue']],
        "container:$uuid_a::> p ~ *" => ['narrow' => ['margin-top' => '1rem']],
        "container:$uuid_a::> pb-text:has(a)" => ['default' => ['color' => 'red']],
        "container:$uuid_a::> pb-container:has(> pb-image) > *" => ['default' => ['gap' => '1rem']],
        "container:$uuid_a::> pb-text:has(+ pb-image)" => ['default' => ['margin-bottom' => '0']],
        "container:$uuid_a::> *:not(pb-image):not(pb-video)" => ['default' => ['padding' => '1rem'], 'last-child' => ['padding' => '0']],
        "container:$uuid_a::> pb-text:not(:has(a))" => ['default' => ['color' => 'teal']],
        "container:$uuid_a::> pb-text:has(a):not(.featured)" => ['dark' => ['color' => 'olive']],
        "container:$uuid_a::.band + .band" => ['default' => ['margin-top' => '0']],
        "container:$uuid_a::.band:has(a) > *" => ['default' => ['color' => 'navy']],
        "block:$uuid_a::p:has(a)" => ['default' => ['color' => 'purple']],
        "block:$uuid_a::> pb-formatted-text p + p" => ['hover' => ['color' => 'purple']],
      ],
      // Generated boxes: the terminal pseudo-element prints last on every
      // target, after the host's chain, after a state's suffix and after
      // the hold's :not(); the owner's anchor is the scope root's own box
      // and never co-targets; the part dual carries the box on both
      // halves with the part's donut (standard, child-shifted, none after
      // a sibling step); a tag, a type, the children, a qualified chain
      // and an ancestor rule each carry their box; at-rules wrap the box's
      // rule as any other; and every spelling outside the grammar (another
      // pseudo-element, the bare anchor, a space before the box, a box
      // behind a box) drops identically at both emit gates.
      [
        "block:$uuid_a::&::before" => [
          'default' => ['content' => '""', 'color' => 'red', 'animation-trigger' => '--in-view play-once'],
          'hover' => ['color' => 'blue'],
          'first-child' => ['display' => 'none'],
          'narrow+dark' => ['display' => 'none'],
        ],
        "block:$uuid_a::&::after" => ['default' => ['content' => '""', 'font-weight' => '700']],
        "container:$uuid_a::.quote::after" => ['default' => ['content' => '""'], 'hover' => ['color' => 'purple']],
        "container:$uuid_a::.band > *::before" => ['default' => ['content' => '""']],
        "container:$uuid_a::.band + .band::before" => ['default' => ['content' => '""']],
        "container:$uuid_a::> pb-text::before" => ['default' => ['content' => '""'], 'hover+medium' => ['color' => 'blue']],
        "container:$uuid_a::> pb-text:has(a)::after" => ['default' => ['content' => '""']],
        "container:$uuid_a::> p + p::before" => ['default' => ['content' => '""']],
        "container:$uuid_a::> *::after" => ['odd' => ['content' => '""']],
        "container:$uuid_a::pb-block::before" => ['default' => ['content' => '""']],
        "block:$uuid_a::p::before" => ['default' => ['content' => '""'], 'hover' => ['animation-trigger' => '--in-view replay reset']],
        "block:$uuid_b::&::marker" => ['default' => ['color' => 'red']],
        "block:$uuid_b::&" => ['default' => ['color' => 'red']],
        "block:$uuid_b::& ::before" => ['default' => ['color' => 'red']],
        "block:$uuid_b::.quote::before::after" => ['default' => ['color' => 'red']],
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
      '::> *' => ['default' => ['flex-grow' => '1'], 'narrow' => ['flex-grow' => '0']],
      '::.daedalus-stylish-layout-container > *' => ['default' => ['min-width' => '0']],
      'block(text,2)::> *' => ['hover' => ['gap' => '1rem']],
      '::>x' => ['default' => ['color' => 'red']],
      '::>' => ['default' => ['color' => 'red']],
      '::> pb-text .link' => ['default' => ['color' => 'red']],
      'container(1)::p' => ['default' => ['color' => 'red']],
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

    // The rule-wear lane: the same fragment printed under a rule, every
    // suffix continued by descent, the class suffix refused under a tag
    // rule, and the donut judged on the rebased selector, must not
    // diverge between the entity's inline tag and the client's preview.
    foreach (['> pb-text', 'a', '> pb-container', '.band > pb-container', '> p + p'] as $under) {
      $variant_cases[] = [
        'scopeRoot' => '[data-selectable="container:aaaaaaaa-1111-2222-3333-444444444444"]',
        'scope' => 'layout:layout_twocol_section',
        'styles' => $composition_styles,
        'fragment' => '',
        'under' => $under,
      ];
    }
    $variant_cases[] = [
      'scopeRoot' => '[data-selectable="container:aaaaaaaa-1111-2222-3333-444444444444"]',
      'scope' => 'block_type:text',
      'styles' => [
        '' => ['default' => ['padding' => '1rem'], 'hover' => ['color' => 'purple']],
        '::.link' => ['default' => ['font-weight' => '700']],
        '::> *' => ['default' => ['margin' => '0']],
        '::p:has(a)' => ['default' => ['color' => 'navy']],
      ],
      'fragment' => '',
      'under' => '> pb-text:has(a)',
    ];
    // A fragment carrying generated boxes: the anchor and a part's box at
    // the element (a class root), the anchor continued onto the rule's
    // landing and the part's box under the rule by descent, and under a
    // rule already ending in a box only the fragment's own entry (the
    // grammar refuses every continuation) must not diverge between the
    // compiled file, the entity's inline tag and the client's preview.
    $box_styles = [
      '' => ['default' => ['padding' => '1rem']],
      '::&::before' => ['default' => ['content' => '""', 'animation-trigger' => '--in-view play-once'], 'hover' => ['color' => 'purple']],
      '::.link::after' => ['default' => ['content' => '""'], 'narrow' => ['display' => 'none']],
      '::&::marker' => ['default' => ['color' => 'red']],
    ];
    $variant_cases[] = [
      'scopeRoot' => '.daedalus-stylish-variant--sleek',
      'scope' => 'block_type:text',
      'styles' => $box_styles,
      'fragment' => '',
    ];
    foreach (['> pb-text', '> pb-text::before', 'a'] as $under) {
      $variant_cases[] = [
        'scopeRoot' => '[data-selectable="container:aaaaaaaa-1111-2222-3333-444444444444"]',
        'scope' => 'block_type:text',
        'styles' => $box_styles,
        'fragment' => '',
        'under' => $under,
      ];
    }

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

    // Document cases print a whole document's map through the ordering
    // pair (NodeTreeBuilder::styleMapOf / styleMapOf in style-guide.js):
    // the client builds its flat index from the mirror's tree plus the
    // node-less keys of the top-level map, the server orders the stored
    // map by the same tree, and the two must print the same bytes for a
    // map written in a different order than its document. Each case is
    // a lean tree (styles on the nodes, by local remainder) and the
    // top-level `styles` map as the mirror delivers it.
    $uuid_c = 'cccccccc-1111-2222-3333-444444444444';
    $uuid_d = 'dddddddd-1111-2222-3333-444444444444';
    $uuid_e = 'eeeeeeee-1111-2222-3333-444444444444';
    $entries = [
      "entity:$uuid_a" => ['default' => ['padding' => '2rem']],
      "field:$uuid_a:field_body" => ['hover' => ['color' => 'teal']],
      "container:$uuid_a" => ['default' => ['padding' => '4rem']],
      "container:$uuid_a::.band" => ['narrow' => ['gap' => '1rem']],
      "block:$uuid_b" => ['default' => ['color' => 'red']],
      "block:$uuid_b:field_title" => ['default' => ['font-weight' => '700']],
      "container:$uuid_c::> *" => ['default' => ['min-width' => '0']],
      "block:$uuid_d" => ['hover' => ['color' => 'purple']],
      "container:$uuid_e" => ['default' => ['margin' => '0']],
    ];
    $document_tree = [
      [
        'kind' => 'container',
        'uuid' => $uuid_a,
        'layout_id' => 'layout_twocol_section',
        // The node's own keys in the node's stored order: the part before
        // the root entry here, kept verbatim.
        'styles' => ['::.band' => $entries["container:$uuid_a::.band"], '' => $entries["container:$uuid_a"]],
        'children' => [
          [
            'kind' => 'region',
            'region' => 'first',
            'children' => [
              [
                'kind' => 'block',
                'uuid' => $uuid_b,
                'block_type' => 'text',
                'styles' => ['' => $entries["block:$uuid_b"], ':field_title' => $entries["block:$uuid_b:field_title"]],
              ],
            ],
          ],
          [
            'kind' => 'region',
            'region' => 'second',
            'children' => [
              [
                'kind' => 'container',
                'uuid' => $uuid_c,
                'layout_id' => 'daedalus_stylish_container',
                'styles' => ['::> *' => $entries["container:$uuid_c::> *"]],
                'children' => [
                  [
                    'kind' => 'block',
                    'uuid' => $uuid_d,
                    'block_type' => 'text',
                    'styles' => ['' => $entries["block:$uuid_d"]],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      [
        'kind' => 'container',
        'uuid' => $uuid_e,
        'layout_id' => 'daedalus_stylish_container',
        'styles' => ['' => $entries["container:$uuid_e"]],
        'children' => [],
      ],
    ];
    // The stored map, written in no document order at all.
    $scrambled = [
      "block:$uuid_d" => $entries["block:$uuid_d"],
      "container:$uuid_e" => $entries["container:$uuid_e"],
      "field:$uuid_a:field_body" => $entries["field:$uuid_a:field_body"],
      "block:$uuid_b:field_title" => $entries["block:$uuid_b:field_title"],
      "container:$uuid_a::.band" => $entries["container:$uuid_a::.band"],
      "container:$uuid_c::> *" => $entries["container:$uuid_c::> *"],
      "entity:$uuid_a" => $entries["entity:$uuid_a"],
      "block:$uuid_b" => $entries["block:$uuid_b"],
      "container:$uuid_a" => $entries["container:$uuid_a"],
    ];
    $document_cases = [
      // The whole stored map beside the tree (the mirror today).
      ['tree' => $document_tree, 'styles' => $scrambled],
      // The node-less remainder alone beside the tree (the mirror once
      // the serializer emits the tree).
      [
        'tree' => $document_tree,
        'styles' => array_intersect_key($scrambled, ["field:$uuid_a:field_body" => 1, "entity:$uuid_a" => 1]),
      ],
      // No tree at all: the stored order stands.
      ['tree' => [], 'styles' => $scrambled],
      // A key addressing no node of the document is node-less and prints
      // first, in its stored place among the node-less keys.
      ['tree' => [$document_tree[1]], 'styles' => $scrambled],
    ];

    // The same conditions payload ClientStyleGuide ships to the browser.
    $conditions = [];
    foreach ($this->container->get('plugin.manager.daedalus_stylish_condition_type')->getConditions() as $name => $definition) {
      $conditions[$name] = [
        'label' => (string) $definition['label'],
        'wrapper' => $definition['wrapper'],
        'axis' => $definition['axis'],
      ] + array_intersect_key($definition, ['fact' => TRUE]);
    }

    $printer = $this->container->get('daedalus_stylish.css_printer');
    $php_output = array_map(
      static fn (array $case) => $printer->printMap($case),
      $cases,
    );
    $php_variant_output = array_map(
      static fn (array $case) => $printer->printFragmentStyles($case['scopeRoot'], $case['scope'], $case['styles'], $case['fragment'], isset($case['under']) ? RelativeSelector::fromString($case['under']) : NULL),
      $variant_cases,
    );
    $php_keyframes_output = array_map(
      static fn (array $case) => $printer->printKeyframes($case['name'], $case['steps']),
      $keyframes_cases,
    );
    $php_document_output = array_map(
      static fn (array $case) => $printer->printMap(NodeTreeBuilder::styleMapOf($case['tree'], $case['styles'])),
      $document_cases,
    );

    $input = json_encode([
      'conditions' => $conditions,
      // JSON round-trip the cases exactly as the client would receive them
      // (a map with non-scalar noise still must not diverge).
      'cases' => $cases,
      'variantCases' => $variant_cases,
      'keyframesCases' => $keyframes_cases,
      'documentCases' => $document_cases,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    $js_output = json_decode($this->runNodeScript('tests/js/print-cases.mjs', $input), TRUE);

    $this->assertIsArray($js_output);
    $this->assertIsArray($js_output['cases'] ?? NULL);
    $this->assertIsArray($js_output['variantCases'] ?? NULL);
    $this->assertSameSize($php_output, $js_output['cases']);
    foreach ($php_output as $index => $expected) {
      $this->assertSame($expected, $js_output['cases'][$index], "Case $index diverged between the PHP and JS printers.");
    }
    $this->assertIsArray($js_output['documentCases'] ?? NULL);
    $this->assertSameSize($php_document_output, $js_output['documentCases']);
    foreach ($php_document_output as $index => $expected) {
      $this->assertSame($expected, $js_output['documentCases'][$index], "Document case $index diverged between the PHP and JS printers.");
    }
    // The document cases are in the battery (silently-empty guard): the
    // scrambled map prints in document order, node-less keys first, a
    // node's own keys in their stored order, region nodes transparent, a
    // nested container before its children, and the same bytes whether
    // the top-level map holds every key or the node-less remainder alone.
    $document_order = [
      "field:$uuid_a:field_body",
      "entity:$uuid_a",
      "container:$uuid_a::.band",
      "container:$uuid_a",
      "block:$uuid_b",
      "block:$uuid_b:field_title",
      "container:$uuid_c::> *",
      "block:$uuid_d",
      "container:$uuid_e",
    ];
    $this->assertSame($document_order, array_keys(NodeTreeBuilder::styleMapOf($document_tree, $scrambled)));
    $this->assertSame($document_order, array_keys(NodeTreeBuilder::styleMapOf($document_tree, $document_cases[1]['styles'])));
    $this->assertSame(array_keys($scrambled), array_keys(NodeTreeBuilder::styleMapOf([], $scrambled)));
    $this->assertSame(
      [
        "block:$uuid_d",
        "field:$uuid_a:field_body",
        "block:$uuid_b:field_title",
        "container:$uuid_a::.band",
        "container:$uuid_c::> *",
        "entity:$uuid_a",
        "block:$uuid_b",
        "container:$uuid_a",
        "container:$uuid_e",
      ],
      array_keys(NodeTreeBuilder::styleMapOf([$document_tree[1]], $scrambled)),
    );
    $this->assertSame($php_document_output[0], $php_document_output[1]);
    $this->assertNotSame($php_document_output[0], $php_document_output[2]);
    $this->assertSame(9, substr_count($php_document_output[0], '@scope ('));
    $scope_roots = [];
    preg_match_all('/@scope \(\[data-selectable="([^"]+)"\]\)/', $php_document_output[0], $scope_roots);
    $this->assertSame(array_map(static fn (string $key): string => ($pos = strpos($key, '::')) === FALSE ? $key : substr($key, 0, $pos), $document_order), $scope_roots[1]);
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
    // Structural facts are in the battery (silently-empty guard): bare
    // pseudo-classes, the posable state keeping its dual beside one.
    $facts = $php_output[13];
    $this->assertStringContainsString(':scope > *:first-child {', $facts);
    $this->assertStringContainsString(':scope > *:nth-child(odd) {', $facts);
    $this->assertStringContainsString(':scope > *:is(:hover, [data-force-state~="hover"]):last-child {', $facts);
    $this->assertStringContainsString(':scope.quote:only-child, .quote:only-child {', $facts);
    $this->assertStringNotContainsString('data-force-state~="first-child"', $facts);
    // Part selectors are dual-target on both sides: the descendant class
    // reaches the root-depth part element, the ":scope" half reaches a
    // collapsed container root carrying the hoisted part class.
    $this->assertStringContainsString(':scope.daedalus-stylish-layout-container, .daedalus-stylish-layout-container {', $php_output[2]);
    // Children pseudo-part targets and the child-shifted donut are in the
    // battery (silently-empty guard for the '::> *' grammar).
    $this->assertStringContainsString(') to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *)', $php_output[6]);
    // The part half's class wraps in :where(): children targets never
    // outweigh ':scope', so a child's own entry wins on scope proximity.
    $this->assertStringContainsString(':scope:where(.daedalus-stylish-layout-container) > *, .daedalus-stylish-layout-container > * {', $php_output[6]);
    // The bare children take no donut; the retired spelling drops.
    $this->assertStringContainsString("@scope ([data-selectable=\"container:$uuid_a\"]) {\n  :scope > * {", $php_output[6]);
    $this->assertSame(0, substr_count($php_output[6], 'color: red'), 'The malformed and retired spellings print nothing.');
    // Type compounds and chains are in the battery (silently-empty guard
    // for the specificity discipline): the address stamp, the :is() family,
    // :where() on every link but the weighted one, the shifted donut on a
    // part reaching a type, none on a part continuing by descent.
    $this->assertStringContainsString(':where(:scope) > [data-element="block_type:text"] {', $php_output[7]);
    $this->assertStringContainsString(':where(:scope) > [data-element="block_type:text"]:is(:hover, [data-force-state~="hover"]) {', $php_output[7]);
    $this->assertStringContainsString(':where(:scope) > :where([data-element="block_type:text"]) .text-block-link {', $php_output[7]);
    $this->assertStringContainsString(':is([data-element^="block_type:"], [data-element^="block_plugin:"]) {', $php_output[7]);
    $this->assertStringContainsString(':where(:scope) > [data-element^="layout:"] > * {', $php_output[7]);
    $this->assertStringContainsString(") to (:scope [data-selectable^=\"container:\"] > *, :scope [data-selectable^=\"block:\"] > *) {\n  :where(:scope):where(.band) > [data-element=\"block_type:text\"], :where(.band) > [data-element=\"block_type:text\"] {", $php_output[7]);
    $this->assertStringContainsString("\"]) {\n  :where(:scope):where(.band) p, :where(.band) p {", $php_output[7]);
    $this->assertStringContainsString(':scope.quote, :where(:scope) > .quote {', $php_output[7]);
    $this->assertStringContainsString(':where(:scope) > :where([data-element="block_type:formatted_text"]) p:is(:hover, [data-force-state~="hover"]) {', $php_output[7]);
    $this->assertStringContainsString(':where(:scope) > :where([data-element="block_type:text"]) .link {', $php_variant_output[0]);
    // The co-target companion is in the battery (silently-empty guard):
    // unconditional, and pseudo-suffixed on :scope with the claiming
    // element merely receiving; the variant lane rides the same seam. A
    // part-entry-only map never co-targets.
    $this->assertStringContainsString(':scope :where([data-claims-content]) {', $php_output[9]);
    $this->assertStringContainsString(':scope:is(:hover, [data-force-state~="hover"]) :where([data-claims-content]) {', $php_output[9]);
    $this->assertStringContainsString('[data-claims-content]', $php_variant_output[2]);
    $this->assertStringNotContainsString('[data-claims-content]', $php_output[1]);
    // Tag-tier cases are in the battery (silently-empty guard for the
    // '::{tag}' grammar): the bare single target, the tag children's
    // single extended target, and no co-target companion on tag entries.
    $this->assertStringContainsString("\n  p {", $php_output[11]);
    $this->assertStringNotContainsString(':scopep', $php_output[11]);
    $this->assertStringContainsString('ul > * {', $php_output[11]);
    $this->assertStringNotContainsString('[data-claims-content]', $php_output[11]);
    $this->assertStringContainsString(':scope.s-a3, .s-a3 {', $php_output[11]);
    // Relations are in the battery (silently-empty guard): the sibling
    // combinators, a qualifier's argument compounds each in :where() with
    // bare combinators, the implied subject, the part dual carrying the
    // part's qualifiers and skipped after a sibling step.
    $this->assertStringContainsString(':where(:scope) > :where(p) + p {', $php_output[14]);
    $this->assertStringContainsString(':where(:scope) > p ~ * {', $php_output[14]);
    $this->assertStringContainsString(':where(:scope) > [data-element="block_type:text"]:has(:where(a)) {', $php_output[14]);
    $this->assertStringContainsString(':where(:scope) > [data-element^="layout:"]:has(> :where([data-element="block_type:image"])) > * {', $php_output[14]);
    $this->assertStringContainsString(':where(:scope) > [data-element="block_type:text"]:has(+ :where([data-element="block_type:image"])) {', $php_output[14]);
    $this->assertStringContainsString(':scope > *:not(:where([data-element="block_type:image"])):not(:where([data-element="block_type:video"])):last-child {', $php_output[14]);
    $this->assertStringContainsString(':where(:scope) > [data-element="block_type:text"]:not(:has(:where(a))) {', $php_output[14]);
    $this->assertStringContainsString("\n  :where(.band) + .band {", $php_output[14]);
    $this->assertStringNotContainsString(':scope:where(.band) + .band', $php_output[14]);
    $this->assertStringContainsString(':scope:where(.band):has(:where(a)) > *, .band:has(:where(a)) > * {', $php_output[14]);
    $this->assertStringContainsString("\n  p:has(:where(a)) {", $php_output[14]);
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
    $this->assertStringContainsString("timeline-trigger: --in-view view() entry 20%;\n    --daedalus-stylish-timeline-trigger: --in-view view() entry 20%;", $php_output[12]);
    $this->assertStringContainsString("animation-trigger: --in-view play-once;\n    --daedalus-stylish-animation-trigger: --in-view play-once;", $php_output[12]);
    $this->assertStringContainsString("@supports not (animation-trigger: none) {\n    :scope:not([data-daedalus-stylish-triggered]) {\n      animation-play-state: paused;", $php_output[12]);
    $this->assertStringContainsString(':scope:is(:hover, [data-force-state~="hover"]):not([data-daedalus-stylish-triggered]) {', $php_output[12]);
    $this->assertStringContainsString(':scope.quote:not([data-daedalus-stylish-triggered]), .quote:not([data-daedalus-stylish-triggered]) {', $php_output[12]);
    $this->assertStringContainsString(':scope > *:not([data-daedalus-stylish-triggered]) {', $php_output[12]);
    $this->assertStringContainsString('p:not([data-daedalus-stylish-triggered]) {', $php_output[12]);
    $this->assertSame(6, substr_count($php_output[12], '@supports not (animation-trigger: none) {'));
    $this->assertStringContainsString("--daedalus-stylish-animation-trigger: none;", $php_output[12]);
    $this->assertStringNotContainsString('</style>', $php_output[12]);
    $this->assertStringNotContainsString("entity:$uuid_b", $php_output[12]);
    $this->assertSame(2, substr_count($php_variant_output[9], '@supports not (animation-trigger: none) {'));
    $this->assertStringContainsString("--daedalus-stylish-animation-trigger: none;", $php_variant_output[9]);
    // Generated boxes are in the battery (silently-empty guard): the
    // anchor as the root's own box with its state, its fact and its hold
    // each before the pseudo and no companion beside it, the part dual
    // carrying the box on both halves with its donut and skipped after a
    // sibling step, a rule's box after the rule's chain under an at-rule,
    // the children's box under a fact, the tag's box with its hold, and
    // nothing at all for the spellings outside the grammar.
    $boxes = $php_output[15];
    $this->assertStringContainsString("\n  :scope::before {\n    content: \"\";\n    color: red;", $boxes);
    $this->assertStringContainsString(':scope:is(:hover, [data-force-state~="hover"])::before {', $boxes);
    $this->assertStringContainsString(':scope:first-child::before {', $boxes);
    $this->assertStringContainsString(':scope:not([data-daedalus-stylish-triggered])::before {', $boxes);
    $this->assertStringContainsString("\n  :scope::after {\n    content: \"\";\n    font-weight: 700;", $boxes);
    $this->assertStringNotContainsString('data-claims-content', $boxes);
    $this->assertStringContainsString('to (:scope [data-selectable^="container:"], :scope [data-selectable^="block:"]) {' . "\n" . '  :scope.quote::after, .quote::after {', $boxes);
    $this->assertStringContainsString(':scope.quote:is(:hover, [data-force-state~="hover"])::after, .quote:is(:hover, [data-force-state~="hover"])::after {', $boxes);
    $this->assertStringContainsString('to (:scope [data-selectable^="container:"] > *, :scope [data-selectable^="block:"] > *) {' . "\n" . '  :scope:where(.band) > *::before, .band > *::before {', $boxes);
    $this->assertStringContainsString("\n  :where(.band) + .band::before {", $boxes);
    $this->assertStringNotContainsString(':scope:where(.band) + .band::before', $boxes);
    $this->assertStringContainsString("@container (min-width: 768px) and (max-width: 1023px) {\n    :where(:scope) > [data-element=\"block_type:text\"]:is(:hover, [data-force-state~=\"hover\"])::before {", $boxes);
    $this->assertStringContainsString(':where(:scope) > [data-element="block_type:text"]:has(:where(a))::after {', $boxes);
    $this->assertStringContainsString(':where(:scope) > :where(p) + p::before {', $boxes);
    $this->assertStringContainsString(':scope > *:nth-child(odd)::after {', $boxes);
    $this->assertStringContainsString(':is([data-element^="block_type:"], [data-element^="block_plugin:"])::before {', $boxes);
    $this->assertStringContainsString("\n  p::before {", $boxes);
    $this->assertStringContainsString('p:is(:hover, [data-force-state~="hover"]):not([data-daedalus-stylish-triggered])::before {', $boxes);
    $this->assertStringNotContainsString($uuid_b, $boxes);
    // The variant lane rides the same seam: the anchor at the class root,
    // continued onto the rule's landing with the part's box under it,
    // the tag rule refusing the part's box, and a box rule taking only
    // the fragment's own entry; the `::marker` fragment prints nowhere.
    $box_at_element = $php_variant_output[16];
    $this->assertStringContainsString("@scope (.daedalus-stylish-variant--sleek) {\n  :scope::before {\n    content: \"\";", $box_at_element);
    $this->assertStringContainsString(':scope:not([data-daedalus-stylish-triggered])::before {', $box_at_element);
    $this->assertStringContainsString(':scope:is(:hover, [data-force-state~="hover"])::before {', $box_at_element);
    $this->assertStringContainsString(':scope.link::after, .link::after {', $box_at_element);
    $this->assertStringNotContainsString('marker', $box_at_element);
    $box_under_rule = $php_variant_output[17];
    $this->assertStringContainsString(':where(:scope) > [data-element="block_type:text"]::before {', $box_under_rule);
    $this->assertStringContainsString(':where(:scope) > :where([data-element="block_type:text"]) .link::after {', $box_under_rule);
    $this->assertSame(3, substr_count($box_under_rule, '@scope ('));
    $box_under_box = $php_variant_output[18];
    $this->assertSame(1, substr_count($box_under_box, '@scope ('));
    $this->assertStringContainsString(":where(:scope) > [data-element=\"block_type:text\"]::before {\n    padding: 1rem;", $box_under_box);
    $box_under_tag = $php_variant_output[19];
    $this->assertStringContainsString("\n  a::before {", $box_under_tag);
    $this->assertStringNotContainsString('.link', $box_under_tag);
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
