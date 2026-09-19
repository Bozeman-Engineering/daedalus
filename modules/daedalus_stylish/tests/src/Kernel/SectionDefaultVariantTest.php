<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests per-layout default variants on the section-created event.
 *
 * The add_section op writes the section layout's declared default_variants
 * (axis => variant machine name) as references at container:{uuid} — at EVERY
 * depth: a nested section is an ordinary section (position, not kind), so
 * the house default applies inside a section exactly as on a page row. A
 * section is born with its REAL layout (the default ladder's pick, or an
 * explicit layout on add_section) and keeps it for life, so birth is the
 * one defaulting seam; the retired change_section_layout re-evaluation is
 * gone with the op. Every guard degrades silently — a missing/unpublished/
 * mis-scoped/mis-axed default never fails the operation.
 *
 * daedalus_stylish_section_defaults_test declares {style: onecol_default, width:
 * onecol_wide, flow: {variant: onecol_row, depth: root}} for layout_onecol
 * (the ladder's pick here) and {style:
 * twocol_default, width: twocol_wide} for layout_twocol_section (the
 * explicit-layout birth). Both layouts qualify the width axis
 * {depth: root} (the production daedalus_stylish_layouts posture): a root-only
 * axis is never defaulted at nested depth — skipped on add — and a move
 * across depth strips or redresses it (SectionMoveWearTest). The flow axis
 * inverts that: the axis applies at every depth, only its DEFAULT is
 * depth-qualified root-only (the production container_layout posture).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SectionDefaultVariantTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The daedalus_layout uuid of the fixture's root section.
   */
  protected const ROOT_SEC = '11111111-1111-4111-8111-111111111111';

  /**
   * The daedalus_layout uuid of the fixture's nested section (container identity).
   */
  protected const NESTED_SEC = '22222222-2222-4222-8222-222222222222';

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
    'daedalus_ui',
    'daedalus_layout',
    'daedalus_stylish',
    'daedalus_stylish_section_defaults_test',
  ];

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperationsInterface
   */
  protected $operations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('daedalus_variant');
    // The schema build path serves the materials roster, which reads the
    // published swatches (testLayoutEntryCarriesDefaultsLine).
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');

    // Layout Builder overrides on entity_test provide the layout field.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * Creates a published variant.
   */
  protected function createVariant(string $machine_name, string $scope, bool $published = TRUE, ?string $axis = NULL): DaedalusStylishVariant {
    $variant = DaedalusStylishVariant::create([
      'label' => $machine_name,
      'machine_name' => $machine_name,
      'scope' => $scope,
      'status' => $published,
      'styles' => json_encode(['' => ['default' => ['padding' => '1rem']]]),
    ] + ($axis === NULL ? [] : ['axis' => $axis]));
    $variant->save();
    return $variant;
  }

  /**
   * Creates a styling-enabled host: one root section nesting a section.
   *
   * Structure: root section ROOT_SEC (daedalus_layout uuid) > container component
   * (uuid == embedded section uuid, sections all the way down) > embedded
   * section NESTED_SEC.
   */
  protected function createHostEntity(array $map = []): EntityTest {
    $nested_section = new Section('layout_onecol', [], [], [
      'daedalus_layout' => ['uuid' => self::NESTED_SEC],
    ]);
    $section = new Section('layout_onecol', [], [], [
      'daedalus_layout' => ['uuid' => self::ROOT_SEC],
    ]);
    $section->appendComponent(SectionBlock::createComponent($nested_section, 'content'));

    $entity = EntityTest::create([
      'name' => 'host',
      'layout_builder__layout' => [$section],
    ]);
    if ($map) {
      $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    }
    $entity->save();
    return $entity;
  }

  /**
   * Mints a valid uuid for a fixture role.
   */
  protected function mintUuid(): string {
    return $this->container->get('uuid')->generate();
  }

  /**
   * Reads an entity's style map back.
   */
  protected function styleMap(EntityTest $entity): array {
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * Runs a batch of operations, asserting success.
   */
  protected function batch(EntityTest $entity, array $operations): void {
    $result = $this->operations->batchOperations($entity, $operations);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
  }

  /**
   * Adds a section through the batch grammar.
   */
  protected function addSection(EntityTest $entity, string $uuid, ?array $location = NULL, ?string $layout = NULL): void {
    $operation = [
      'op' => 'add_section',
      'location' => $location ?? ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      'uuid' => $uuid,
    ];
    if ($layout !== NULL) {
      $operation['layout'] = $layout;
    }
    $this->batch($entity, [$operation]);
  }

  /**
   * A new page section gets its layout's default variant by reference.
   */
  public function testPageSectionGetsDefault(): void {
    $this->createVariant('onecol_default', 'layout:layout_onecol');
    $entity = $this->createHostEntity();

    // Root-entity anchor.
    $sec_1 = $this->mintUuid();
    $this->addSection($entity, $sec_1);
    $this->assertSame(['variants' => [['variant' => 'onecol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$sec_1"] ?? NULL);

    // Sibling anchor beside a root section is a page section too.
    $sec_2 = $this->mintUuid();
    $this->addSection($entity, $sec_2, ['anchor' => self::ROOT_SEC, 'placement' => 'after']);
    $this->assertSame(['variants' => [['variant' => 'onecol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$sec_2"] ?? NULL);
  }

  /**
   * A kind:container-scoped default applies too (the family wildcard).
   */
  public function testKindSectionScopedDefault(): void {
    $this->createVariant('onecol_default', 'kind:container');
    $entity = $this->createHostEntity();

    $sec_1 = $this->mintUuid();
    $this->addSection($entity, $sec_1);
    $this->assertSame(['variants' => [['variant' => 'onecol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$sec_1"] ?? NULL);
  }

  /**
   * Nested sections get the default too: nesting is position, not kind.
   *
   * QW2 in structural form — a nested section's key stamps at its own root
   * and its styling lifecycle is a section's, so the house default lands at
   * every depth (the old page-sections-only gate is gone with the owner
   * concept it keyed on).
   */
  public function testNestedSectionGetsDefault(): void {
    $this->createVariant('onecol_default', 'layout:layout_onecol');
    $entity = $this->createHostEntity();

    // Nested inside an existing section.
    $nested_1 = $this->mintUuid();
    $this->addSection($entity, $nested_1, ['anchor' => self::NESTED_SEC, 'placement' => 'inside']);
    $this->assertSame(['variants' => [['variant' => 'onecol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$nested_1"] ?? NULL);

    // Sibling of a nested section (a container beside the anchor's).
    $nested_2 = $this->mintUuid();
    $this->addSection($entity, $nested_2, ['anchor' => self::NESTED_SEC, 'placement' => 'after']);
    $this->assertSame(['variants' => [['variant' => 'onecol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$nested_2"] ?? NULL);
  }

  /**
   * A nested add skips root-only axes: the width default is page-band only.
   *
   * The layout_onecol qualifies its width axis {depth: root}, so a page-row
   * add writes both defaults while a nested add writes only the style one,
   * at any nesting depth. Depth gates per axis, not per container: the style
   * default still lands nested (position, not kind).
   */
  public function testNestedAddSkipsRootOnlyDefault(): void {
    $this->createVariant('onecol_default', 'layout:layout_onecol');
    $this->createVariant('onecol_wide', 'layout:layout_onecol', TRUE, 'width');
    $entity = $this->createHostEntity();

    // A page row gets both defaults, in axis-vocabulary order.
    $root = $this->mintUuid();
    $this->addSection($entity, $root);
    $this->assertSame([
      'variants' => [['variant' => 'onecol_default', 'fragment' => ''], ['variant' => 'onecol_wide', 'fragment' => '']],
    ], $this->styleMap($entity)["container:$root"] ?? NULL);

    // Nested inside a section's region: the width default is skipped.
    $nested = $this->mintUuid();
    $this->addSection($entity, $nested, ['anchor' => self::NESTED_SEC, 'placement' => 'inside']);
    $this->assertSame(['variants' => [['variant' => 'onecol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$nested"] ?? NULL);

    // Deeper nesting is nested all the same.
    $deep = $this->mintUuid();
    $this->addSection($entity, $deep, ['anchor' => $nested, 'placement' => 'inside']);
    $this->assertSame(['variants' => [['variant' => 'onecol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$deep"] ?? NULL);
  }

  /**
   * A depth-qualified default lands at root only; its axis stays offered.
   *
   * The layout_onecol's flow axis applies at every depth, but its default is
   * the qualified {variant: onecol_row, depth: root}: a page-row add
   * wears it, a nested add is born without it, and an explicit
   * apply_variant still lands nested — only the DEFAULT is root-scoped,
   * unlike the width axis where the whole axis is page-band business.
   */
  public function testRootQualifiedDefaultSkipsNested(): void {
    $this->createVariant('onecol_default', 'layout:layout_onecol');
    $this->createVariant('onecol_row', 'layout:layout_onecol', TRUE, 'flow');
    $entity = $this->createHostEntity();

    // A page row wears the qualified default, in axis-vocabulary order.
    $root = $this->mintUuid();
    $this->addSection($entity, $root);
    $this->assertSame([
      'variants' => [['variant' => 'onecol_default', 'fragment' => ''], ['variant' => 'onecol_row', 'fragment' => '']],
    ], $this->styleMap($entity)["container:$root"] ?? NULL);

    // Nested, the qualified default is skipped: born a plain flow.
    $nested = $this->mintUuid();
    $this->addSection($entity, $nested, ['anchor' => self::NESTED_SEC, 'placement' => 'inside']);
    $this->assertSame(['variants' => [['variant' => 'onecol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$nested"] ?? NULL);

    // The axis itself is offered at every depth: the explicit gesture
    // still dresses the nested section in the row.
    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => "container:$nested", 'variant' => 'onecol_row'],
    ]);
    $this->assertSame([
      'variants' => [['variant' => 'onecol_default', 'fragment' => ''], ['variant' => 'onecol_row', 'fragment' => '']],
    ], $this->styleMap($entity)["container:$nested"] ?? NULL);
  }

  /**
   * An add never fails over a bad default: it degrades to unstyled.
   */
  public function testDefaultSkips(): void {
    $entity = $this->createHostEntity();

    // The declared variant does not exist.
    $missing = $this->mintUuid();
    $this->addSection($entity, $missing);
    $this->assertArrayNotHasKey("container:$missing", $this->styleMap($entity));

    // Unpublished: not in the compiled stylesheet, would place invisibly.
    $unpublished_variant = $this->createVariant('onecol_default', 'layout:layout_onecol', FALSE);
    $unpublished = $this->mintUuid();
    $this->addSection($entity, $unpublished);
    $this->assertArrayNotHasKey("container:$unpublished", $this->styleMap($entity));

    // Mis-scoped: layout-exact matching rejects another layout's scope.
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->delete([$unpublished_variant]);
    $this->createVariant('onecol_default', 'layout:layout_twocol_section');
    $misscoped = $this->mintUuid();
    $this->addSection($entity, $misscoped);
    $this->assertArrayNotHasKey("container:$misscoped", $this->styleMap($entity));

    // A host without styling enabled is untouched (and does not crash).
    $this->container->get('daedalus_stylish.field_manager')->disable('entity_test', 'entity_test');
    $bare = EntityTest::create([
      'name' => 'bare',
      'layout_builder__layout' => [new Section('layout_onecol')],
    ]);
    $bare->save();
    $this->addSection($bare, $this->mintUuid());
    $this->assertFalse($bare->hasField(DaedalusStylishFieldManager::FIELD_NAME));
  }

  /**
   * Existing entries are never overridden by the default.
   */
  public function testExistingEntriesNeverOverridden(): void {
    $this->createVariant('onecol_default', 'layout:layout_onecol');
    $custom = ['default' => ['padding' => '9rem']];
    $sec_1 = $this->mintUuid();
    $sec_2 = $this->mintUuid();
    $entity = $this->createHostEntity([
      "container:$sec_1" => $custom,
      "container:$sec_2::.daedalus-stylish-layout-container" => $custom,
    ]);

    // A base entry for the section's own key.
    $this->addSection($entity, $sec_1);
    $this->assertSame($custom, $this->styleMap($entity)["container:$sec_1"] ?? NULL);

    // A sub-element entry alone also counts as existing styles.
    $this->addSection($entity, $sec_2);
    $this->assertArrayNotHasKey("container:$sec_2", $this->styleMap($entity));
    $this->assertSame($custom, $this->styleMap($entity)["container:$sec_2::.daedalus-stylish-layout-container"] ?? NULL);
  }

  /**
   * An explicit apply_variant in the same batch wins over the default.
   */
  public function testExplicitApplyVariantWins(): void {
    $this->createVariant('onecol_default', 'layout:layout_onecol');
    $this->createVariant('explicit_pick', 'kind:container');
    $entity = $this->createHostEntity();

    $uuid = $this->mintUuid();
    $this->batch($entity, [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
        'uuid' => $uuid,
      ],
      ['op' => 'apply_variant', 'key' => "container:$uuid", 'variant' => 'explicit_pick'],
    ]);
    // apply_variant writes the canonical structured reference form.
    $this->assertSame(['variants' => [['variant' => 'explicit_pick', 'fragment' => '']]], $this->styleMap($entity)["container:$uuid"] ?? NULL);
  }

  /**
   * A dressed batch stamps nothing; an undressed one stamps every default.
   *
   * Birth is gated on the batch's posture alone: a batch declaring
   * ['dressed' => TRUE] (a stencil expansion, a document dialect) is a
   * total statement, so its sections wear exactly what the batch itself
   * applies, house defaults included only when the batch names them. The
   * same add in an undressed batch (the op door) is born wearing the
   * layout's declared defaults, at root and nested alike.
   */
  public function testDressedBatchStampsNothing(): void {
    $this->createVariant('onecol_default', 'layout:layout_onecol');
    $this->createVariant('onecol_wide', 'layout:layout_onecol', TRUE, 'width');
    $this->createVariant('onecol_row', 'layout:layout_onecol', TRUE, 'flow');
    $this->createVariant('explicit_pick', 'kind:container');
    $entity = $this->createHostEntity();

    // Dressed: a bare add_section is born wearing NONE, at either depth.
    $bare_root = $this->mintUuid();
    $bare_nested = $this->mintUuid();
    $result = $this->operations->batchOperations($entity, [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
        'uuid' => $bare_root,
      ],
      [
        'op' => 'add_section',
        'location' => ['anchor' => self::NESTED_SEC, 'placement' => 'inside'],
        'uuid' => $bare_nested,
      ],
    ], ['dressed' => TRUE]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertArrayNotHasKey("container:$bare_root", $this->styleMap($entity));
    $this->assertArrayNotHasKey("container:$bare_nested", $this->styleMap($entity));
    $this->assertNotContains(
      ['uuid' => $entity->uuid(), 'field_name' => DaedalusStylishFieldManager::FIELD_NAME],
      $result->getTargets(),
      'A dressed batch writes nothing and declares nothing.',
    );

    // Dressed: what the batch applies is exactly what the section wears,
    // with no house default beneath it.
    $dressed = $this->mintUuid();
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'add_section', 'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'], 'uuid' => $dressed],
      ['op' => 'apply_variant', 'key' => "container:$dressed", 'variant' => 'explicit_pick'],
    ], ['dressed' => TRUE]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['variants' => [['variant' => 'explicit_pick', 'fragment' => '']]], $this->styleMap($entity)["container:$dressed"] ?? NULL);

    // Undressed: the same adds are born wearing the declared defaults.
    $root = $this->mintUuid();
    $nested = $this->mintUuid();
    $this->batch($entity, [
      ['op' => 'add_section', 'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'], 'uuid' => $root],
      ['op' => 'add_section', 'location' => ['anchor' => self::NESTED_SEC, 'placement' => 'inside'], 'uuid' => $nested],
    ]);
    $this->assertSame([
      'variants' => [
        ['variant' => 'onecol_default', 'fragment' => ''],
        ['variant' => 'onecol_wide', 'fragment' => ''],
        ['variant' => 'onecol_row', 'fragment' => ''],
      ],
    ], $this->styleMap($entity)["container:$root"] ?? NULL);
    $this->assertSame(['variants' => [['variant' => 'onecol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$nested"] ?? NULL);
  }

  /**
   * A declared default whose variant sits on another axis skips silently.
   */
  public function testAxisMismatchSkips(): void {
    $this->createVariant('twocol_default', 'layout:layout_twocol_section');
    // Declared under the width axis, but the variant's own axis is the
    // default style: writing it would break one-reference-per-axis.
    $this->createVariant('twocol_wide', 'layout:layout_twocol_section');
    $entity = $this->createHostEntity();
    $sec_1 = $this->mintUuid();

    $this->addSection($entity, $sec_1, NULL, 'layout_twocol_section');
    $this->assertSame(['variants' => [['variant' => 'twocol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$sec_1"] ?? NULL);
  }

  /**
   * An explicit birth layout applies THAT layout's defaults.
   *
   * A section is born with its real layout, so an add_section naming a
   * layout writes that layout's defaults — not the ladder default's — and
   * the root-only width gate applies at nested depth exactly like an
   * implicit add.
   */
  public function testExplicitLayoutBirthDefaults(): void {
    $this->createVariant('onecol_default', 'layout:layout_onecol');
    $this->createVariant('twocol_default', 'layout:layout_twocol_section');
    $this->createVariant('twocol_wide', 'layout:layout_twocol_section', TRUE, 'width');
    $entity = $this->createHostEntity();

    // A page row born twocol wears twocol's defaults, both axes.
    $root = $this->mintUuid();
    $this->addSection($entity, $root, NULL, 'layout_twocol_section');
    $this->assertSame([
      'variants' => [['variant' => 'twocol_default', 'fragment' => ''], ['variant' => 'twocol_wide', 'fragment' => '']],
    ], $this->styleMap($entity)["container:$root"] ?? NULL);

    // Nested, the root-only width default is skipped.
    $nested = $this->mintUuid();
    $this->addSection($entity, $nested, ['anchor' => self::NESTED_SEC, 'placement' => 'inside'], 'layout_twocol_section');
    $this->assertSame(['variants' => [['variant' => 'twocol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$nested"] ?? NULL);
  }

  /**
   * The create door stamps nothing: a blueprint's map is a total statement.
   *
   * Birth has two doors with two semantics. add_section (the op door,
   * every test above) stamps the layout's declared defaults;
   * BlueprintDeserializer (eb_create_entity) dispatches no
   * section-created event, so a blueprint-created entity's map holds
   * ONLY the references it authored — the map grammar is declarative
   * (an absent reference means wears NONE), and stamping defaults under
   * an authored blueprint would make a deliberately stacked or
   * full-bleed section unauthorable.
   */
  public function testBlueprintCreateStampsNothing(): void {
    // Every declared default is satisfiable, so a stamp WOULD land if
    // the create door fired the section-created seam.
    $this->createVariant('onecol_default', 'layout:layout_onecol');
    $this->createVariant('onecol_wide', 'layout:layout_onecol', TRUE, 'width');
    $this->createVariant('onecol_row', 'layout:layout_onecol', TRUE, 'flow');
    $deserializer = $this->container->get('daedalus_blueprint.deserializer');

    // A blueprint authoring one reference: the created map holds exactly
    // that entry, nothing stamped beside or under it.
    $authored = $this->mintUuid();
    $result = $deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Blueprint born',
        'daedalus_stylish_styles' => [
          "container:$authored" => ['variants' => [['variant' => 'onecol_row', 'fragment' => '']]],
        ],
      ],
      'layout_builder__layout' => [
        ['layout_id' => 'layout_onecol', 'uuid' => $authored, 'regions' => []],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $entity = $result->getEntity();
    $this->assertSame([
      "container:$authored" => ['variants' => [['variant' => 'onecol_row', 'fragment' => '']]],
    ], $this->styleMap($entity));

    // A blueprint authoring no styles at all: the section wears none.
    $bare = $this->mintUuid();
    $result = $deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Blueprint bare'],
      'layout_builder__layout' => [
        ['layout_id' => 'layout_onecol', 'uuid' => $bare, 'regions' => []],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $this->assertSame([], $this->styleMap($result->getEntity()));
  }

  /**
   * The schema teaches the split: a generated line per declaring layout.
   *
   * LayoutDefaultsSchemaHooks serializes each layout's declared
   * default_variants onto its available_layouts entry from guide DATA
   * (the same projections the stamping subscriber consumes): what
   * add_section stamps, which entries stamp top-level sections only
   * (root-only AXIS and depth-qualified DEFAULT alike), and that
   * document-authored sections (page_create, page_edit, eb_create_entity)
   * are never stamped. A layout declaring no defaults carries no line.
   */
  public function testLayoutEntryCarriesDefaultsLine(): void {
    $schema = $this->container->get('daedalus_blueprint.schema_builder')
      ->getSchema('entity_test', 'entity_test');
    $layouts = [];
    foreach ($schema['layout_builder__layout']['available_layouts'] as $layout) {
      $layouts[$layout['id']] = $layout;
    }

    // layout_onecol: width is a root-only AXIS, flow a depth-qualified
    // DEFAULT — both serialize as top-level-only; style stamps at every
    // depth. The door split rides the same line.
    $this->assertSame(
      'add_section stamps these variant references on the new section: style: onecol_default, width: onecol_wide (top-level sections only), flow: onecol_row (top-level sections only). A section authored in a page document (page_create, page_edit, eb_create_entity) is never stamped: it wears exactly what the document states, so state wanted defaults explicitly there.',
      $layouts['layout_onecol']['default_variants'] ?? NULL,
    );
    $this->assertSame(
      'add_section stamps these variant references on the new section: style: twocol_default, width: twocol_wide (top-level sections only). A section authored in a page document (page_create, page_edit, eb_create_entity) is never stamped: it wears exactly what the document states, so state wanted defaults explicitly there.',
      $layouts['layout_twocol_section']['default_variants'] ?? NULL,
    );

    // A layout declaring no defaults gets no line (empty means absent).
    $undeclared = array_diff(array_keys($layouts), ['layout_onecol', 'layout_twocol_section']);
    $this->assertNotEmpty($undeclared, 'The site offers undeclared layouts to pin absence on.');
    foreach ($undeclared as $id) {
      $this->assertArrayNotHasKey('default_variants', $layouts[$id], "$id carries no defaults line.");
    }
  }

}
