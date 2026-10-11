<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus_stylish\Traits\HostTrait;

/**
 * Locks the PHP and JS style stacks together (round-trip equivalence).
 *
 * The server's StyleStack answers the AI's explain tool; the client's
 * style-stack.js answers every inspector row, well and code island. The
 * two must project the same layers, in the same order, with the same raw
 * styles and the same index winners for the same key, or the inspector
 * shows a stack the AI cannot explain. This test builds a battery of
 * stacks through both, the PHP service in process and the JS module via
 * node (tests/js/stack-cases.mjs), and asserts deep equality of the
 * stack shape and of linesAt() at every stored condition. The client's
 * posed read (stackAt) is a client-only projection over the same raw
 * layers, node-pinned in style-stack.test.mjs.
 *
 * The client side reads what the browser reads: the ClientStyleGuide
 * payload (declared and skin defaults, the served axes), the roster's
 * delivery records, the blueprint's uuid tables and the annotated lean
 * node tree (the ancestor tier's input on both sides), so the test
 * exercises the production data path. The host entity's layout resolves
 * the block to its bundle and the section to its layout on the server;
 * the uuid tables carry the same facts to the client. The host nests: a
 * container section holds the two-column section holding the block, so
 * the container's rules reach the block by descent and the two-column
 * section's `> * > *` through its region wrapper, while its `> *` stops
 * at the regions. The block's anatomy is declared: a paragraph root, a
 * link part rendering an anchor (the container's `a` rule reaches it, at
 * the part key and at the block's tag tier) and the quote part declaring
 * no tag (unknown, reached by nothing). Two more testimonial blocks
 * follow the two-column section in the container, a band of three
 * children: the container's `> p + p` rule reaches the last alone (the
 * only p-root after a p-root) and its `> *` rule's `not-last-child`
 * block folds on every child but the last. Wear at a rule reads as a
 * GROUP in a member's ancestor tier: the container's `> pb-testimonial`
 * rule wears two looks over its own padding (three layers at the band's
 * blocks, the worn ones naming the variant and the rule as carrier),
 * one look's quote entry continues under the rule to the blocks' quote
 * parts (a worn layer carried by the rule, the own layer riding empty),
 * and the two-column section's `> * > pb-testimonial` rule only wears
 * (a group whose own layer rides empty). Generated boxes are addresses
 * of their own: the block's `&::before` reads its root's wearings at the
 * box beneath its own lines (a look's stored `::&::before` fragment
 * delivers there) and takes the container's `pb-testimonial::before`
 * rule (never a pseudo-free rule, never the other box), a look's box
 * fragment worn at the container's `> pb-testimonial` rule continues
 * under it as `> pb-testimonial::before` at the band's blocks' boxes (a
 * group the rule carries, listed before the map's own box rules), the
 * container's `a::before` rule reaches the link part's box and the
 * block's `a::before` tier, and a box has no floor and no base layer
 * whatever its host renders as.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class StackEquivalenceTest extends KernelTestBase {

  use HostTrait;
  use DesignSystemActivationTrait;
  use NodeSuitesTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'entity_test',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_stylish',
    // The container layout the outer section nests the two-column one in.
    'daedalus_stylish_layouts',
    // The testimonial block type (an emphasis axis, a quote part) and the
    // two-column layout (a band part) the refinement scopes resolve to.
    'daedalus_stylish_guide_test',
    // Injects declared defaults from state: the declared tier's
    // in-fixture source for both a kind and a refinement scope.
    'daedalus_stylish_control_test',
    // The unbound site: the fixture writes literal paddings and gaps on a
    // site that mints nothing.
    'daedalus_stylish_unbound_test',
  ];

  /**
   * The outer container's uuid (resolves to daedalus_stylish_container).
   */
  protected const OUTER = 'eeeeeeee-1111-4000-8000-000000000000';

  /**
   * The nested section's uuid (resolves to layout_twocol_section).
   */
  protected const SECTION = 'aaaaaaaa-1111-4000-8000-000000000001';

  /**
   * The placed testimonial block's uuid (resolves to block_type:testimonial).
   */
  protected const BLOCK = 'bbbbbbbb-1111-4000-8000-000000000002';

  /**
   * The first testimonial block after the nested section (a p-root after
   * a section, never after a p-root).
   */
  protected const AFTER_SECTION = 'ffffffff-1111-4000-8000-000000000005';

  /**
   * The second testimonial block after the nested section (a p-root
   * right after a p-root, the container's last child).
   */
  protected const AFTER_P = 'ffffffff-1111-4000-8000-000000000006';

  /**
   * A block uuid the layout does not carry (degrades to kind:block).
   */
  protected const LOOSE = 'cccccccc-1111-4000-8000-000000000003';

  /**
   * A block uuid the map never mentions (the bare element).
   */
  protected const BARE = 'dddddddd-1111-4000-8000-000000000004';

  /**
   * The stored conditions every case reads lines at.
   */
  protected const CONDITIONS = ['default', 'hover', 'narrow', 'dark', 'narrow+dark'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('daedalus_variant');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');

    // Layout Builder overrides on entity_test provide the layout field the
    // element locator resolves placements and sections through.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();
    BlockContentType::create(['id' => 'testimonial', 'label' => 'Testimonial', 'revision' => TRUE])->save();

    // One theme per read on both sides: the site default, which the stack
    // and the client payload each default to once.
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();

    // The declared tier: a composed container kind with a display default,
    // and the testimonial refinement with root and part defaults, a
    // paragraph root tag (the tree fact a `p` compound matches) and a
    // link part rendering an anchor (the guide_test quote part declares
    // no tag: the annotation carries it as NULL, the unknown).
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => ['composed' => TRUE, 'defaults' => ['' => ['default' => ['display' => 'grid']]]],
    ]);
    $this->container->get('state')->set('daedalus_stylish_control_test.block_types', [
      'testimonial' => [
        'tag' => 'p',
        'elements' => ['.testimonial__link' => ['label' => 'Link', 'tag' => 'a']],
        'defaults' => [
          '' => ['default' => ['margin' => '0', 'padding' => '0.5rem']],
          '::.testimonial__quote' => ['default' => ['font-style' => 'italic']],
        ],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();
  }

  /**
   * Creates a published variant with styles.
   */
  protected function createVariant(string $machine_name, string $label, string $scope, array $styles, ?string $axis = NULL): DaedalusStylishVariant {
    $variant = DaedalusStylishVariant::create([
      'label' => $label,
      'machine_name' => $machine_name,
      'scope' => $scope,
      'description' => "The $label look.",
    ] + ($axis === NULL ? [] : ['axis' => $axis]));
    $variant->setStyles($styles);
    $variant->save();
    return $variant;
  }

  /**
   * The PHP and JS stacks are identical for the same keys, skin or no skin.
   */
  public function testStacksAreEquivalent(): void {
    // The roster: two testimonial looks on two axes (Sleek carrying a
    // fragment at its own generated box, delivered wherever the look is
    // worn), a container composition distributing a fragment (with a
    // children block), a composition to be the origin of a via wearing,
    // and two style looks for the loose block.
    $this->createVariant('sleek', 'Sleek', 'block_type:testimonial', [
      '' => [
        'default' => ['padding' => '1rem', 'background-color' => '#ffffff'],
        'hover' => ['background-color' => '#eeeeee'],
      ],
      '::.testimonial__quote' => ['default' => ['font-size' => '1.5rem']],
      '::&::before' => [
        'default' => ['content' => '""', 'background-color' => 'gold'],
        'hover' => ['background-color' => 'goldenrod'],
      ],
    ]);
    $this->createVariant('loud', 'Loud', 'block_type:testimonial', [
      '' => ['default' => ['padding' => '2rem', 'font-weight' => 'bold'], 'dark' => ['color' => '#eeeeee']],
    ], 'emphasis');
    $this->createVariant('duo', 'Duo', 'kind:container', [
      '' => ['default' => ['gap' => '1rem'], 'narrow' => ['gap' => '0.5rem']],
      '::> *' => ['default' => ['display' => 'flex']],
      'block(testimonial,1)' => ['default' => ['color' => 'red'], 'narrow' => ['padding' => '0']],
      'block(testimonial,1)::.testimonial__quote' => ['default' => ['color' => 'crimson']],
    ]);
    $this->createVariant('card_left', 'Card Left', 'kind:container', [
      '' => ['default' => ['gap' => '2rem']],
    ]);
    $this->createVariant('row', 'Row', 'block_type:testimonial', [
      '' => ['default' => ['display' => 'flex']],
    ]);
    $this->createVariant('plain_row', 'Plain Row', 'block_type:testimonial', [
      '' => ['default' => ['display' => 'inline-flex', 'gap' => '4px']],
    ]);

    // The skin: base blocks (one the fixture renders as, one it never
    // does, one the link part renders as), the kind blocks beneath both
    // refinements, and the refinement blocks themselves, part included.
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setPackageDefaults([
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
      'kind:block' => ['' => ['default' => ['padding' => '0.75rem'], 'narrow' => ['padding' => '0.25rem']]],
      'block_type:testimonial' => [
        '' => ['default' => ['background-color' => '#fafafa'], 'dark' => ['background-color' => '#222222']],
        '::.testimonial__quote' => ['default' => ['font-size' => '1.25rem']],
      ],
      'kind:container' => ['' => ['default' => ['gap' => '1.5rem', 'display' => 'flex'], 'narrow' => ['gap' => '0.5rem']]],
      'layout:layout_twocol_section' => ['' => ['default' => ['gap' => '3rem']]],
      'base:p' => ['' => ['default' => ['line-height' => '1.6']]],
      'base:a' => ['' => ['default' => ['text-decoration' => 'none']]],
    ])->save();
    $this->activateSkin($skin);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();

    // The host: a container section holding the two-column section
    // carrying the testimonial block, then two testimonial blocks.
    $storage = $this->container->get('entity_type.manager')->getStorage('block_content');
    $component = static fn (string $uuid, string $region, string $info, int $weight): SectionComponent => (new SectionComponent($uuid, $region, [
      'id' => 'inline_block:testimonial',
      'provider' => 'layout_builder',
      'label' => $info,
      'label_display' => 'visible',
      'view_mode' => 'full',
      'block_serialized' => serialize($storage->create(['type' => 'testimonial', 'info' => $info])),
    ]))->setWeight($weight);
    $section = new Section('layout_twocol_section', [], [
      $component(self::BLOCK, 'first', 'A quote', 0),
    ], ['daedalus_layout' => ['uuid' => self::SECTION]]);
    $outer = new Section('daedalus_stylish_container', [], [
      SectionBlock::createComponent($section, 'content'),
      $component(self::AFTER_SECTION, 'content', 'A second quote', 1),
      $component(self::AFTER_P, 'content', 'A third quote', 2),
    ], ['daedalus_layout' => ['uuid' => self::OUTER]]);
    $block_key = 'block:' . self::BLOCK;
    $after_section_key = 'block:' . self::AFTER_SECTION;
    $after_p_key = 'block:' . self::AFTER_P;
    $section_key = 'container:' . self::SECTION;
    $outer_key = 'container:' . self::OUTER;
    $loose_key = 'block:' . self::LOOSE;
    $map = [
      // Two worn references, one a distributed fragment, stored out of
      // delivery order; own overrides at two conditions.
      $block_key => [
        'variants' => [
          ['variant' => 'loud', 'fragment' => ''],
          ['variant' => 'duo', 'fragment' => 'block(testimonial,1)'],
          ['variant' => 'sleek', 'fragment' => ''],
        ],
        'default' => ['padding' => '3rem'],
        'dark' => ['background-color' => '#000000'],
        'narrow+dark' => ['padding' => '0'],
      ],
      "$block_key::.testimonial__quote" => ['default' => ['color' => 'blue']],
      "$block_key::p" => ['default' => ['line-height' => '1.4']],
      // The block's own generated box: a sibling suffix of the root, its
      // own lines read over the root's wearings at the box.
      "$block_key::&::before" => ['default' => ['content' => '""', 'width' => '1rem']],
      $section_key => [
        'variants' => [['variant' => 'duo', 'fragment' => '']],
        'default' => ['gap' => '2rem'],
      ],
      "$section_key::> *" => ['default' => ['flex-grow' => '1'], 'narrow' => ['flex-grow' => '0']],
      // The ancestor rules: the two-column section reaches its block
      // through the region wrapper (`> * > *`), never by `> *`; the outer
      // container reaches the block by descent (a type compound with a
      // structural fact the block satisfies and one it does not), its
      // three children by `> *` (the negated fact folding on every child
      // but the last), the nested section by `> pb-container` and the
      // last child alone by `> p + p` (the only p-root after a p-root).
      "$section_key::> * > *" => ['default' => ['flex-basis' => '0']],
      // A rule that only wears: the two-column section's testimonial
      // children (through the region wrapper) take the Row look with no
      // entry lines of the rule's own.
      "$section_key::> * > pb-testimonial" => ['variants' => [['variant' => 'row', 'fragment' => '']]],
      "$outer_key::> *" => ['default' => ['margin' => '0'], 'not-last-child' => ['margin-bottom' => '24px']],
      "$outer_key::> p + p" => ['default' => ['margin-top' => '40px']],
      "$outer_key::pb-testimonial" => [
        'default' => ['color' => 'teal', 'padding' => '4px'],
        'first-child' => ['outline' => '1px solid', 'padding' => '5px'],
        'last-child' => ['color' => 'black'],
        'hover+only-child' => ['color' => 'white'],
      ],
      "$outer_key::> pb-container" => ['default' => ['margin' => '1rem']],
      // The outer container's `a` rule reaches the block's link part (the
      // anchor it renders) and the block's own `::a` tier, never its
      // tagless quote part.
      "$outer_key::a" => ['default' => ['font-size' => '30px']],
      // Wear at a rule: the outer container's `> pb-testimonial` rule
      // wears two testimonial looks (stored out of delivery order) over
      // its own padding; sleek's quote entry continues under the rule
      // to the two direct testimonial children's quote parts, and its
      // box fragment continues as `> pb-testimonial::before` to their
      // `&::before` boxes.
      "$outer_key::> pb-testimonial" => [
        'variants' => [
          ['variant' => 'loud', 'fragment' => ''],
          ['variant' => 'sleek', 'fragment' => ''],
        ],
        'default' => ['padding' => '6px'],
      ],
      // Rules for generated boxes: the descent rule's `::before` box
      // (with a structural fact the nested block satisfies and the band
      // does not) reaches every testimonial's `&::before` and no
      // pseudo-free key; the direct children's `::after` box reaches the
      // band's `&::after` alone, never their `&::before`; the `a` rule's
      // `::before` box reaches the link part's box and the block's own
      // `::a::before` tier.
      "$outer_key::pb-testimonial::before" => ['default' => ['background-color' => 'teal'], 'first-child' => ['width' => '2rem']],
      "$outer_key::> pb-testimonial::after" => ['default' => ['content' => '""', 'inset' => '0']],
      "$outer_key::a::before" => ['default' => ['content' => '""', 'margin-right' => '4px']],
      // A stale reference, a via wearing whose origin resolves, and one
      // whose origin is gone; stored stale-first so the unplaceable
      // reference's sort-last posture is observable.
      $loose_key => [
        'variants' => [
          ['variant' => 'ghost', 'fragment' => ''],
          ['variant' => 'row', 'fragment' => '', 'via' => 'card_left'],
          ['variant' => 'plain_row', 'fragment' => '', 'via' => 'gone'],
        ],
      ],
    ];
    $entity = EntityTest::create([
      'name' => 'host',
      'layout_builder__layout' => [$outer],
      DaedalusStylishFieldManager::FIELD_NAME => json_encode($map),
    ]);
    $entity->save();
    $stored = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    // The annotated lean tree, exactly as the explain tool and the client
    // mirror carry it.
    $backend = $this->container->get('daedalus_blueprint.backend_resolver')->resolve('entity_test');
    $tree = $this->container->get('daedalus_ui.element_annotator')->annotate($backend->serialize($entity), $this->host($entity), 'full')['layout'];
    $this->assertSame([self::OUTER], array_column($tree, 'uuid'));
    $this->assertSame([self::SECTION, self::AFTER_SECTION, self::AFTER_P], array_column($tree[0]['children'], 'uuid'));
    $block_markup = $tree[0]['children'][0]['children'][0]['children'][0]['_element']['markup'];
    $this->assertSame('p', $block_markup['tag']);
    $this->assertSame('a', $block_markup['parts']['.testimonial__link']['tag']);
    $this->assertNull($block_markup['parts']['.testimonial__quote']['tag']);
    $this->assertSame('p', $tree[0]['children'][2]['_element']['markup']['tag']);

    // Every key reads its base layer from the tree's annotation: the
    // block's p root, the link part's a, nothing for the tagless quote
    // part, the tag tiers, the chains and the bare block the tree does
    // not carry.
    $cases = [
      $block_key,
      "$block_key::.testimonial__quote",
      "$block_key::p",
      $section_key,
      "$section_key::> *",
      $outer_key,
      $loose_key,
      'entity:' . $entity->uuid(),
      'block:' . self::BARE,
      "$block_key::.testimonial__link",
      "$block_key::a",
      $after_section_key,
      $after_p_key,
      "$outer_key::> pb-testimonial",
      "$outer_key::> pb-testimonial .testimonial__quote",
      "$after_section_key::.testimonial__quote",
      // The generated boxes: the nested block's own (the descent rule's
      // box, the root's wearings read at the box with Sleek's stored
      // box fragment delivering, its own lines), the band's first
      // block's before (Sleek's box fragment continued under the
      // `> pb-testimonial` rule as a group, then the descent rule's box
      // with its fact dropped) and after (the children rule's box
      // alone), the link part's box and the block's `a::before` tier.
      "$block_key::&::before",
      "$after_section_key::&::before",
      "$after_section_key::&::after",
      "$block_key::.testimonial__link::before",
      "$block_key::a::before",
    ];

    // Battery one under the skin, battery two with the design system
    // deactivated: the same keys, the skin tier and the base layer gone.
    $batteries = [];
    $php = [];
    foreach ([TRUE, FALSE] as $skinned) {
      if (!$skinned) {
        $this->deactivateDesignSystem();
        $this->container->get('daedalus_stylish.style_guide')->resetCache();
      }
      $stack_service = $this->container->get('daedalus_stylish.style_stack');
      $answers = [];
      foreach ($cases as $key) {
        $stack = $stack_service->stack($stored, $key, ['host' => $this->host($entity)], NULL, $tree);
        $lines = [];
        foreach (self::CONDITIONS as $condition) {
          $lines[$condition] = $stack_service->linesAt($stack, $condition);
        }
        $answers[] = ['stack' => $stack, 'lines' => $lines];
      }
      $php[] = $answers;
      $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
      $batteries[] = [
        'guide' => $this->container->get('daedalus_stylish.client_style_guide')->build(),
        'variants' => array_values(array_map(static fn ($record) => $record->toArray(), $roster->variants())),
        'blockTypes' => [self::BLOCK => 'testimonial', self::AFTER_SECTION => 'testimonial', self::AFTER_P => 'testimonial'],
        'sectionLayouts' => [self::SECTION => 'layout_twocol_section', self::OUTER => 'daedalus_stylish_container'],
        'tree' => $tree,
        'cases' => array_map(static fn (string $key): array => [
          'key' => $key,
          'map' => $stored,
          'conditions' => self::CONDITIONS,
        ], $cases),
      ];
    }

    $js = json_decode($this->runNodeScript('tests/js/stack-cases.mjs', json_encode(['batteries' => $batteries], JSON_THROW_ON_ERROR)), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($js['batteries'] ?? NULL);
    $this->assertSameSize($php, $js['batteries']);
    foreach ($php as $battery => $answers) {
      $this->assertSameSize($answers, $js['batteries'][$battery]);
      foreach ($answers as $index => $expected) {
        $label = sprintf('battery %d case %d (%s)', $battery, $index, $cases[$index]);
        $this->assertSame($expected['stack'], $js['batteries'][$battery][$index]['stack'], "The stack of $label diverged between the server and the client twin.");
        foreach (self::CONDITIONS as $condition) {
          $this->assertSame($expected['lines'][$condition], $js['batteries'][$battery][$index]['lines'][$condition] ?? NULL, "The $condition lines of $label diverged between the server and the client twin.");
        }
      }
    }

    // Guard against a silently inert battery: every posture the lock is
    // for is present on the PHP side.
    $ids = static fn (array $answer): array => array_column($answer['stack']['layers'], 'id');
    // The skinned root: both floor tiers per scope, the base layer for
    // the tree's rendered tag (the block's p root), the ancestor rules
    // reaching the block (the outer container's by descent, farthest
    // first, then the nested section's through its region wrapper, its
    // wear-only rule as a group of the worn layer and an empty own
    // layer; its `> *` stops at the regions), the references in delivery
    // order (style root, style fragment, emphasis), the own layer on top.
    $this->assertSame([
      'declared:block_type:testimonial',
      'skin:base:p',
      'skin:kind:block',
      'skin:block_type:testimonial',
      "ancestor:$outer_key::pb-testimonial",
      "ancestor:$section_key::> * > *",
      "ancestor:$section_key::> * > pb-testimonial|row",
      "ancestor:$section_key::> * > pb-testimonial",
      'variant:sleek',
      'variant:duo',
      'variant:loud',
      'element',
    ], $ids($php[0][0]));
    $this->assertSame('block_type:testimonial', $php[0][0]['stack']['scope']);
    $this->assertSame(['h2', 'p', 'a'], $php[0][0]['stack']['baseTags']);
    $this->assertTrue($php[0][0]['stack']['skin']);
    // The ancestor layer: the block is alone in its region, so the
    // first-child block folds into default (its padding beats the plain
    // block's) and the last-child and only-child blocks fold too; the
    // hover+only-child compound delivers at hover.
    $this->assertSame([
      'id' => "ancestor:$outer_key::pb-testimonial",
      'tier' => 'ancestor',
      'scope' => $outer_key,
      'variant' => NULL,
      'fragment' => NULL,
      'via' => NULL,
      'viaLabel' => NULL,
      'label' => 'Section',
      'owner' => 'Section',
      'description' => NULL,
      'axis' => NULL,
      'resolved' => TRUE,
      'editable' => FALSE,
      'carrier' => NULL,
      'styles' => [
        'default' => ['color' => 'black', 'padding' => '5px', 'outline' => '1px solid'],
        'hover' => ['color' => 'white'],
      ],
    ], $php[0][0]['stack']['layers'][4]);
    $this->assertSame(['default' => ['flex-basis' => '0']], $php[0][0]['stack']['layers'][5]['styles']);
    // The wear-only rule's group: the worn layer carries Row's identity
    // as its own wearing would, scoped to the owner, its carrier the
    // rule; the own layer rides the group with no styles (the rule
    // exists; the map carries no lines there).
    $this->assertSame([
      'id' => "ancestor:$section_key::> * > pb-testimonial|row",
      'tier' => 'ancestor',
      'scope' => $section_key,
      'variant' => 'row',
      'fragment' => '',
      'via' => NULL,
      'viaLabel' => NULL,
      'label' => 'Row',
      'description' => 'The Row look.',
      'axis' => 'style',
      'resolved' => TRUE,
      'editable' => FALSE,
      'carrier' => ['key' => $section_key, 'selector' => '> * > pb-testimonial'],
      'styles' => ['default' => ['display' => 'flex']],
      'owner' => 'Container',
    ], $php[0][0]['stack']['layers'][6]);
    $this->assertSame([], $php[0][0]['stack']['layers'][7]['styles']);
    $this->assertNull($php[0][0]['stack']['layers'][7]['variant']);
    $this->assertNull($php[0][0]['stack']['layers'][7]['carrier']);
    // The block's own wearings name the root as their carrier.
    $this->assertSame(['key' => $block_key, 'selector' => NULL], $php[0][0]['stack']['layers'][8]['carrier']);
    $this->assertSame('block(testimonial,1)', $php[0][0]['stack']['layers'][9]['fragment']);
    $this->assertSame(['default' => ['color' => 'red'], 'narrow' => ['padding' => '0']], $php[0][0]['stack']['layers'][9]['styles']);
    $this->assertSame('The Sleek look.', $php[0][0]['stack']['layers'][8]['description']);
    $this->assertContains(['layer' => 8, 'property' => 'padding', 'value' => '1rem', 'lost' => TRUE, 'winner' => 11], $php[0][0]['lines']['default']);
    $this->assertContains(['layer' => 4, 'property' => 'color', 'value' => 'black', 'lost' => TRUE, 'winner' => 9], $php[0][0]['lines']['default']);
    $this->assertContains(['layer' => 4, 'property' => 'outline', 'value' => '1px solid', 'lost' => FALSE, 'winner' => NULL], $php[0][0]['lines']['default']);
    $this->assertContains(['layer' => 6, 'property' => 'display', 'value' => 'flex', 'lost' => FALSE, 'winner' => NULL], $php[0][0]['lines']['default']);
    $this->assertContains(['layer' => 11, 'property' => 'padding', 'value' => '0', 'lost' => FALSE, 'winner' => NULL], $php[0][0]['lines']['narrow+dark']);
    // The part key: the tiers' part blocks, no base layer (the quote
    // part declares no tag: the subject's tag is unknown, never the
    // root's), the same references at the part's relative position.
    $this->assertSame([
      'declared:block_type:testimonial',
      'skin:block_type:testimonial',
      'variant:sleek',
      'variant:duo',
      'variant:loud',
      'element',
    ], $ids($php[0][1]));
    $this->assertSame('::.testimonial__quote', $php[0][1]['stack']['relative']);
    $this->assertSame(['default' => ['color' => 'crimson']], $php[0][1]['stack']['layers'][3]['styles']);
    $this->assertSame([], $php[0][1]['stack']['layers'][4]['styles']);
    // The tag key and the children key: plain suffixes under the root
    // scope, no base layer for either (a rule has no one subject).
    $this->assertSame('::p', $php[0][2]['stack']['relative']);
    $this->assertSame('block_type:testimonial', $php[0][2]['stack']['scope']);
    $this->assertNotContains('skin:base:p', $ids($php[0][2]));
    $this->assertSame(['default' => ['line-height' => '1.4']], $php[0][2]['stack']['layers'][3]['styles']);
    $this->assertSame('::> *', $php[0][4]['stack']['relative']);
    $this->assertSame('layout:layout_twocol_section', $php[0][4]['stack']['scope']);
    $this->assertSame(['variant:duo', 'element'], $ids($php[0][4]));
    $this->assertSame(['default' => ['display' => 'flex']], $php[0][4]['stack']['layers'][0]['styles']);
    $this->assertSame(['key' => $section_key, 'selector' => NULL], $php[0][4]['stack']['layers'][0]['carrier']);
    // The nested section root: kind and refinement blocks of both tiers,
    // then the outer container's rules reaching it (`> *` and
    // `> pb-container`, in the outer key's suffix order; `pb-testimonial`
    // never), then its wearing and its own entry.
    $this->assertSame([
      'declared:kind:container',
      'skin:kind:container',
      'skin:layout:layout_twocol_section',
      "ancestor:$outer_key::> *",
      "ancestor:$outer_key::> pb-container",
      'variant:duo',
      'element',
    ], $ids($php[0][3]));
    // The nested section is the container's first of three children: the
    // `> *` rule's not-last-child block folds into its default.
    $this->assertSame(['default' => ['margin' => '0', 'margin-bottom' => '24px']], $php[0][3]['stack']['layers'][3]['styles']);
    $this->assertContains(['layer' => 3, 'property' => 'margin', 'value' => '0', 'lost' => TRUE, 'winner' => 4], $php[0][3]['lines']['default']);
    // The outer container: a root section, no ancestors.
    $outer_ids = $ids($php[0][5]);
    $this->assertSame('layout:daedalus_stylish_container', $php[0][5]['stack']['scope']);
    $this->assertSame([], array_filter($outer_ids, static fn (string $id): bool => str_starts_with($id, 'ancestor:')));
    $this->assertSame('element', end($outer_ids));
    // The loose block: stale last, via origins resolved and stale.
    $this->assertSame(['skin:kind:block', 'variant:row', 'variant:plain_row', 'variant:ghost', 'element'], $ids($php[0][6]));
    $this->assertSame([TRUE, TRUE, TRUE, FALSE, TRUE], array_column($php[0][6]['stack']['layers'], 'resolved'));
    $this->assertSame([NULL, 'Card Left', NULL, NULL, NULL], array_column($php[0][6]['stack']['layers'], 'viaLabel'));
    $this->assertSame([NULL, 'card_left', 'gone', NULL, NULL], array_column($php[0][6]['stack']['layers'], 'via'));
    // The entity and the bare block: the own layer alone, or the floor
    // beneath an empty own layer (no base layer: the tree carries no
    // node for the bare block, so its tag is unknown).
    $this->assertSame(['element'], $ids($php[0][7]));
    $this->assertSame(['skin:kind:block', 'element'], $ids($php[0][8]));
    $this->assertSame([], $php[0][8]['stack']['layers'][1]['styles']);
    // The link part: the skin's base:a block for the part's declared tag
    // (its subject is the anchor it renders), then the container's `a`
    // rule as its ancestor layer (the part renders the anchor the rule
    // targets), beneath the references at the part's relative position;
    // no scoped floor of any tier (neither the declared tier nor the skin
    // carries a block for the part); the tagless quote part above has
    // no base layer and no ancestor layer (its ids are pinned without
    // either).
    $this->assertSame([
      'skin:base:a',
      "ancestor:$outer_key::a",
      'variant:sleek',
      'variant:duo',
      'variant:loud',
      'element',
    ], $ids($php[0][9]));
    $this->assertSame('::.testimonial__link', $php[0][9]['stack']['relative']);
    $this->assertSame(['default' => ['text-decoration' => 'none']], $php[0][9]['stack']['layers'][0]['styles']);
    $this->assertSame([
      'id' => "ancestor:$outer_key::a",
      'tier' => 'ancestor',
      'scope' => $outer_key,
      'variant' => NULL,
      'fragment' => NULL,
      'via' => NULL,
      'viaLabel' => NULL,
      'label' => 'Section',
      'owner' => 'Section',
      'description' => NULL,
      'axis' => NULL,
      'resolved' => TRUE,
      'editable' => FALSE,
      'carrier' => NULL,
      'styles' => ['default' => ['font-size' => '30px']],
    ], $php[0][9]['stack']['layers'][1]);
    $this->assertContains(['layer' => 1, 'property' => 'font-size', 'value' => '30px', 'lost' => FALSE, 'winner' => NULL], $php[0][9]['lines']['default']);
    // The block's own `::a` tier counts the part's anchor as an `a`
    // inside it: the same rule is its ancestor layer too.
    $this->assertContains("ancestor:$outer_key::a", $ids($php[0][10]));
    $this->assertSame('::a', $php[0][10]['stack']['relative']);
    // The band after the nested section: both blocks take the `> *` rule
    // and the descent `pb-testimonial` rule; the sibling rule `> p + p`
    // reaches the last child alone (its previous sibling is a p-root; the
    // block after the section has none), and the `> *` rule's
    // not-last-child block folds on the middle child, never the last,
    // whose pb-testimonial layer folds the last-child block instead.
    $ancestor_ids = static fn (array $answer): array => array_values(array_filter($ids($answer), static fn (string $id): bool => str_starts_with($id, 'ancestor:')));
    $this->assertSame(["ancestor:$outer_key::> *", "ancestor:$outer_key::pb-testimonial", "ancestor:$outer_key::> pb-testimonial|sleek", "ancestor:$outer_key::> pb-testimonial|loud", "ancestor:$outer_key::> pb-testimonial"], $ancestor_ids($php[0][11]));
    $this->assertSame(["ancestor:$outer_key::> *", "ancestor:$outer_key::> p + p", "ancestor:$outer_key::pb-testimonial", "ancestor:$outer_key::> pb-testimonial|sleek", "ancestor:$outer_key::> pb-testimonial|loud", "ancestor:$outer_key::> pb-testimonial"], $ancestor_ids($php[0][12]));
    $layer = static fn (array $answer, string $id): array => array_values(array_filter($answer['stack']['layers'], static fn (array $layer): bool => $layer['id'] === $id))[0];
    // Wear at the `> pb-testimonial` rule: the rule's own stack folds its
    // two worn looks (style axis first) beneath its entry, each naming
    // the rule as its carrier; a direct child's ancestor tier holds the
    // rule as a group, the two looks with their identity (carried by
    // the rule) beneath the rule's own padding; sleek's quote entry
    // continues under the rule to the child's quote part (a group whose
    // worn layer the RULE carries and whose own layer rides empty), and
    // the continued key reads it as its own wear.
    $this->assertSame(['variant:sleek', 'variant:loud', 'element'], $ids($php[0][13]));
    $this->assertSame(['default' => ['padding' => '1rem', 'background-color' => '#ffffff'], 'hover' => ['background-color' => '#eeeeee']], $php[0][13]['stack']['layers'][0]['styles']);
    $rule_carrier = ['key' => $outer_key, 'selector' => '> pb-testimonial'];
    $this->assertSame([$rule_carrier, $rule_carrier, NULL], array_column($php[0][13]['stack']['layers'], 'carrier'));
    $this->assertSame([
      'id' => "ancestor:$outer_key::> pb-testimonial|sleek",
      'tier' => 'ancestor',
      'scope' => $outer_key,
      'variant' => 'sleek',
      'fragment' => '',
      'via' => NULL,
      'viaLabel' => NULL,
      'label' => 'Sleek',
      'description' => 'The Sleek look.',
      'axis' => 'style',
      'resolved' => TRUE,
      'editable' => FALSE,
      'carrier' => $rule_carrier,
      'styles' => ['default' => ['padding' => '1rem', 'background-color' => '#ffffff'], 'hover' => ['background-color' => '#eeeeee']],
      'owner' => 'Section',
    ], $layer($php[0][11], "ancestor:$outer_key::> pb-testimonial|sleek"));
    $this->assertSame('emphasis', $layer($php[0][11], "ancestor:$outer_key::> pb-testimonial|loud")['axis']);
    $this->assertSame(['default' => ['padding' => '2rem', 'font-weight' => 'bold'], 'dark' => ['color' => '#eeeeee']], $layer($php[0][11], "ancestor:$outer_key::> pb-testimonial|loud")['styles']);
    $this->assertSame(['default' => ['padding' => '6px']], $layer($php[0][11], "ancestor:$outer_key::> pb-testimonial")['styles']);
    // Own beats worn inside the group by order: the rule's padding wins
    // over both looks'; loud's font-weight and sleek's background stand.
    $sleek_layer = array_search("ancestor:$outer_key::> pb-testimonial|sleek", $ids($php[0][11]), TRUE);
    $rule_layer = array_search("ancestor:$outer_key::> pb-testimonial", $ids($php[0][11]), TRUE);
    $this->assertContains(['layer' => $sleek_layer, 'property' => 'padding', 'value' => '1rem', 'lost' => TRUE, 'winner' => $rule_layer], $php[0][11]['lines']['default']);
    $this->assertContains(['layer' => $sleek_layer, 'property' => 'background-color', 'value' => '#ffffff', 'lost' => FALSE, 'winner' => NULL], $php[0][11]['lines']['default']);
    // The member wears nothing of its own: no variant-tier layer, the
    // honesty read behind ElementMaterials::worn().
    $this->assertNotContains('variant', array_column($php[0][11]['stack']['layers'], 'tier'));
    $this->assertSame(['variant:sleek', 'variant:loud', 'element'], $ids($php[0][14]));
    $this->assertSame(['default' => ['font-size' => '1.5rem']], $php[0][14]['stack']['layers'][0]['styles']);
    $this->assertSame([], $php[0][14]['stack']['layers'][1]['styles']);
    $this->assertSame([$rule_carrier, $rule_carrier, NULL], array_column($php[0][14]['stack']['layers'], 'carrier'));
    $this->assertSame([
      'declared:block_type:testimonial',
      'skin:block_type:testimonial',
      "ancestor:$outer_key::> pb-testimonial .testimonial__quote|sleek",
      "ancestor:$outer_key::> pb-testimonial .testimonial__quote",
      'element',
    ], $ids($php[0][15]));
    $this->assertSame(['default' => ['font-size' => '1.5rem']], $layer($php[0][15], "ancestor:$outer_key::> pb-testimonial .testimonial__quote|sleek")['styles']);
    $this->assertSame($rule_carrier, $layer($php[0][15], "ancestor:$outer_key::> pb-testimonial .testimonial__quote|sleek")['carrier']);
    $this->assertSame([], $layer($php[0][15], "ancestor:$outer_key::> pb-testimonial .testimonial__quote")['styles']);
    $this->assertSame(['default' => ['margin' => '0', 'margin-bottom' => '24px']], $layer($php[0][11], "ancestor:$outer_key::> *")['styles']);
    $this->assertSame(['default' => ['margin' => '0']], $layer($php[0][12], "ancestor:$outer_key::> *")['styles']);
    $this->assertSame(['default' => ['margin-top' => '40px']], $layer($php[0][12], "ancestor:$outer_key::> p + p")['styles']);
    $this->assertSame(['default' => ['color' => 'teal', 'padding' => '4px']], $layer($php[0][11], "ancestor:$outer_key::pb-testimonial")['styles']);
    $this->assertSame(['default' => ['color' => 'black', 'padding' => '4px']], $layer($php[0][12], "ancestor:$outer_key::pb-testimonial")['styles']);
    $sibling_layer = array_search("ancestor:$outer_key::> p + p", $ids($php[0][12]), TRUE);
    $this->assertContains(['layer' => $sibling_layer, 'property' => 'margin-top', 'value' => '40px', 'lost' => FALSE, 'winner' => NULL], $php[0][12]['lines']['default']);
    $this->assertSame($ancestor_ids($php[0][12]), $ancestor_ids($php[1][12]));
    // The generated boxes. The nested block's own `&::before`: no floor
    // of any tier (no tier block at the box, and a box renders as no tag,
    // so no base layer), the container's `pb-testimonial::before` rule
    // reaching it by descent with its first-child block folded (the
    // block is alone in its region), never its pseudo-free
    // `pb-testimonial` rule nor its `> pb-testimonial::after` box, nor
    // the nested section's pseudo-free rules, nor the outer container's
    // `> pb-testimonial` rule (the block is no direct child); then the
    // root's wearings read at the box (each a layer: Sleek delivers its
    // stored box fragment there, the other two nothing) and the own
    // lines on top.
    $pseudo_rule = "ancestor:$outer_key::pb-testimonial::before";
    $this->assertSame([$pseudo_rule, 'variant:sleek', 'variant:duo', 'variant:loud', 'element'], $ids($php[0][16]));
    $this->assertSame('::&::before', $php[0][16]['stack']['relative']);
    $this->assertSame('block_type:testimonial', $php[0][16]['stack']['scope']);
    $this->assertSame([
      'id' => $pseudo_rule,
      'tier' => 'ancestor',
      'scope' => $outer_key,
      'variant' => NULL,
      'fragment' => NULL,
      'via' => NULL,
      'viaLabel' => NULL,
      'label' => 'Section',
      'owner' => 'Section',
      'description' => NULL,
      'axis' => NULL,
      'resolved' => TRUE,
      'editable' => FALSE,
      'carrier' => NULL,
      'styles' => ['default' => ['background-color' => 'teal', 'width' => '2rem']],
    ], $php[0][16]['stack']['layers'][0]);
    // The root's wearings at the box carry the root as their carrier;
    // Sleek's layer is its stored `::&::before` fragment, the box's own
    // wear beating the descent rule's background and losing its content
    // to the own line.
    $this->assertSame(['key' => $block_key, 'selector' => NULL], $php[0][16]['stack']['layers'][1]['carrier']);
    $this->assertSame('sleek', $php[0][16]['stack']['layers'][1]['variant']);
    $sleek_box = ['default' => ['content' => '""', 'background-color' => 'gold'], 'hover' => ['background-color' => 'goldenrod']];
    $this->assertSame([$sleek_box, [], []], array_column(array_slice($php[0][16]['stack']['layers'], 1, 3), 'styles'));
    $this->assertSame(['default' => ['content' => '""', 'width' => '1rem']], $php[0][16]['stack']['layers'][4]['styles']);
    $this->assertSame([
      ['layer' => 0, 'property' => 'background-color', 'value' => 'teal', 'lost' => TRUE, 'winner' => 1],
      ['layer' => 0, 'property' => 'width', 'value' => '2rem', 'lost' => TRUE, 'winner' => 4],
      ['layer' => 1, 'property' => 'content', 'value' => '""', 'lost' => TRUE, 'winner' => 4],
      ['layer' => 1, 'property' => 'background-color', 'value' => 'gold', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 4, 'property' => 'content', 'value' => '""', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 4, 'property' => 'width', 'value' => '1rem', 'lost' => FALSE, 'winner' => NULL],
    ], $php[0][16]['lines']['default']);
    $this->assertSame([
      ['layer' => 1, 'property' => 'background-color', 'value' => 'goldenrod', 'lost' => FALSE, 'winner' => NULL],
    ], $php[0][16]['lines']['hover']);
    // The box is floorless whatever the skin says: the same stack with
    // the design system gone.
    $this->assertSame($ids($php[0][16]), $ids($php[1][16]));
    // The band's first block's `&::before`: Sleek's box fragment, worn
    // at the outer container's `> pb-testimonial` rule, continues under
    // the rule as `> pb-testimonial::before` (the anchor binds to the
    // rule's landing) and reaches the direct child's box as a group the
    // RULE carries (the worn layer with Sleek's identity, the own layer
    // riding empty: the map holds no lines at the continued key), listed
    // before the map's own descent box (a continued worn box precedes the
    // map's suffixes), whose first-child block drops (the block is the
    // container's middle child) and whose background beats the continued
    // gold by that order; the direct children's pseudo-free rule and its
    // `::after` box never. The block wears nothing of its own and owns no
    // lines at its box.
    $continued_box = "ancestor:$outer_key::> pb-testimonial::before";
    $this->assertSame(["$continued_box|sleek", $continued_box, $pseudo_rule, 'element'], $ids($php[0][17]));
    $this->assertSame([
      'id' => "$continued_box|sleek",
      'tier' => 'ancestor',
      'scope' => $outer_key,
      'variant' => 'sleek',
      'fragment' => '',
      'via' => NULL,
      'viaLabel' => NULL,
      'label' => 'Sleek',
      'description' => 'The Sleek look.',
      'axis' => 'style',
      'resolved' => TRUE,
      'editable' => FALSE,
      'carrier' => $rule_carrier,
      'styles' => $sleek_box,
      'owner' => 'Section',
    ], $php[0][17]['stack']['layers'][0]);
    $this->assertSame([], $php[0][17]['stack']['layers'][1]['styles']);
    $this->assertNull($php[0][17]['stack']['layers'][1]['variant']);
    $this->assertNull($php[0][17]['stack']['layers'][1]['carrier']);
    $this->assertSame(['default' => ['background-color' => 'teal']], $php[0][17]['stack']['layers'][2]['styles']);
    $this->assertSame([], $php[0][17]['stack']['layers'][3]['styles']);
    $this->assertNotContains('variant', array_column($php[0][17]['stack']['layers'], 'tier'));
    $this->assertSame([
      ['layer' => 0, 'property' => 'content', 'value' => '""', 'lost' => FALSE, 'winner' => NULL],
      ['layer' => 0, 'property' => 'background-color', 'value' => 'gold', 'lost' => TRUE, 'winner' => 2],
      ['layer' => 2, 'property' => 'background-color', 'value' => 'teal', 'lost' => FALSE, 'winner' => NULL],
    ], $php[0][17]['lines']['default']);
    $this->assertSame([
      ['layer' => 0, 'property' => 'background-color', 'value' => 'goldenrod', 'lost' => FALSE, 'winner' => NULL],
    ], $php[0][17]['lines']['hover']);
    $this->assertSame($ids($php[0][17]), $ids($php[1][17]));
    // The fragment never leaks into a pseudo-free key: the band's blocks'
    // own ancestor tiers hold the rule's pseudo-free group alone (pinned
    // above), and the box's continued group is nowhere in them.
    $this->assertNotContains("$continued_box|sleek", $ids($php[0][11]));
    $this->assertNotContains($continued_box, $ids($php[0][12]));
    // The same block's `&::after`: the children rule's `::after` box
    // alone; every `::before` rule and box is another box's.
    $this->assertSame(["ancestor:$outer_key::> pb-testimonial::after", 'element'], $ids($php[0][18]));
    $this->assertSame(['default' => ['content' => '""', 'inset' => '0']], $php[0][18]['stack']['layers'][0]['styles']);
    // The link part's `::before` box: no base layer though the part
    // renders as `a` and the skin carries base:a (the box is no anchor),
    // the container's `a::before` rule reaching the part's box exactly
    // as its `a` rule reaches the part, the type rules' boxes never;
    // the block's own `a::before` tier takes the same rule.
    $this->assertSame(["ancestor:$outer_key::a::before", 'variant:sleek', 'variant:duo', 'variant:loud', 'element'], $ids($php[0][19]));
    $this->assertSame('::.testimonial__link::before', $php[0][19]['stack']['relative']);
    $this->assertSame(['default' => ['content' => '""', 'margin-right' => '4px']], $php[0][19]['stack']['layers'][0]['styles']);
    $this->assertSame(["ancestor:$outer_key::a::before", 'variant:sleek', 'variant:duo', 'variant:loud', 'element'], $ids($php[0][20]));
    $this->assertSame('::a::before', $php[0][20]['stack']['relative']);
    // No skin: no skin layers anywhere, no base tags, the declared tier
    // and the references untouched.
    $this->assertFalse($php[1][0]['stack']['skin']);
    $this->assertSame([], $php[1][0]['stack']['baseTags']);
    $this->assertSame([
      'declared:block_type:testimonial',
      "ancestor:$outer_key::pb-testimonial",
      "ancestor:$section_key::> * > *",
      "ancestor:$section_key::> * > pb-testimonial|row",
      "ancestor:$section_key::> * > pb-testimonial",
      'variant:sleek',
      'variant:duo',
      'variant:loud',
      'element',
    ], $ids($php[1][0]));
    $this->assertSame(['element'], $ids($php[1][8]));
    // The link part with no design system: the same five layers.
    $this->assertSame([
      "ancestor:$outer_key::a",
      'variant:sleek',
      'variant:duo',
      'variant:loud',
      'element',
    ], $ids($php[1][9]));
  }

}
