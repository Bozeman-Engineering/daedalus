<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus_stylish\Traits\HostTrait;

/**
 * Tests style carry through placement and duplication.
 *
 * Default-variants-on-place: an UNDRESSED add_element (the op door: the
 * palette's drop, a flat AI or programmatic add) of a block type whose
 * style guide names default_variants (axis => variant machine name) writes
 * one variant reference per resolvable axis into the host map; a DRESSED
 * batch (a blueprint expansion, a document dialect) is a total statement
 * and gets none. populate: 'sample' means field sampling only. Duplicate
 * carry: duplicate_element's fresh identities get their style entries
 * copied to the rewritten keys — custom styles, sub-element keys, field
 * keys, variant references, and keys nested inside duplicated sections
 * (components and daedalus_layout section UUIDs) alike.
 *
 * Every map write the subscriber makes is declared as a target on the
 * lifecycle event and merged into the op's result after the op's own
 * targets: placement, section add, and duplicate results all end with the
 * root entity's daedalus_stylish_styles field. That field target forces the
 * mutation response's whole-entity swap, which is the only swap that
 * carries the entity-level style tag holding the written CSS.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ElementStylesTest extends KernelTestBase {

  use HostTrait;
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
    'daedalus_stylish_guide_test',
    // Layers default_variants onto layout_twocol_section (style:
    // twocol_default) so an add_section can exercise the section write.
    'daedalus_stylish_section_defaults_test',
    // The unbound site: the class writes literal paddings, gaps and sizes
    // on a site that mints nothing, and the suite's bindings would refuse
    // every one for a reason that is the scale's, not the claim's.
    'daedalus_stylish_unbound_test',
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
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
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

    // The guide's testimonial declaration (default_variants: {style:
    // testimonial_default, ...}) needs a real bundle; "plain" declares
    // nothing.
    foreach (['testimonial', 'plain'] as $type) {
      BlockContentType::create(['id' => $type, 'label' => $type, 'revision' => TRUE])->save();
    }
    FieldStorageConfig::create([
      'field_name' => 'field_quote',
      'entity_type' => 'block_content',
      'type' => 'string',
    ])->save();
    foreach (['testimonial', 'plain'] as $type) {
      FieldConfig::create([
        'field_name' => 'field_quote',
        'entity_type' => 'block_content',
        'bundle' => $type,
        'label' => 'Quote',
      ])->save();
    }

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
   * The daedalus_layout UUID of the default host section (the placement anchor).
   */
  protected const HOST_SECTION_UUID = 'aaaaaaaa-0000-4000-8000-000000000000';

  /**
   * Creates a styling-enabled host entity with one empty section.
   */
  protected function createHostEntity(array $map = [], array $sections = []): EntityTest {
    $entity = EntityTest::create([
      'name' => 'host',
      'layout_builder__layout' => $sections ?: [
        new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => self::HOST_SECTION_UUID]]),
      ],
    ]);
    if ($map) {
      $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    }
    $entity->save();
    return $entity;
  }

  /**
   * Reads an entity's style map back.
   */
  protected function styleMap(EntityTest $entity): array {
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * Places a block through the batch grammar.
   */
  protected function place(EntityTest $entity, array $data): BlueprintResult {
    $result = $this->operations->batchOperations($this->host($entity), [
      $data + ['op' => 'add_element', 'location' => ['anchor' => self::HOST_SECTION_UUID, 'placement' => 'inside']],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    return $result;
  }

  /**
   * The target the subscriber declares for a style map write.
   */
  protected function stylesTarget(EntityTest $entity): array {
    return ['uuid' => $entity->uuid(), 'field_name' => DaedalusStylishFieldManager::FIELD_NAME];
  }

  /**
   * Asserts a result's targets end with the root style map field.
   *
   * The op's own targets (ADDED, RESTRUCTURED) come first; the event's
   * declared targets follow in declaration order, so the style write is
   * the last entry.
   */
  protected function assertStylesTargetLast(BlueprintResult $result, EntityTest $entity, string $message): void {
    $targets = $result->getTargets();
    $this->assertNotEmpty($targets, $message);
    $this->assertSame($this->stylesTarget($entity), end($targets), $message);
  }

  /**
   * Asserts a result declares no style map target (nothing was written).
   */
  protected function assertNoStylesTarget(BlueprintResult $result, EntityTest $entity, string $message): void {
    $this->assertNotContains($this->stylesTarget($entity), $result->getTargets(), $message);
  }

  /**
   * An undressed placement writes the declared default variant reference.
   */
  public function testDefaultVariantOnPlace(): void {
    $this->createVariant('testimonial_default', 'block_type:testimonial');
    $entity = $this->createHostEntity();

    // Palette placement (populate: 'sample') gets the default variant, and
    // the write is declared on the result after the op's ADDED target.
    $result = $this->place($entity, [
      'block_type' => 'testimonial',
      'uuid' => '0a000001-0000-4000-8000-000000000001',
      'populate' => 'sample',
    ]);
    $this->assertSame(['variants' => [['variant' => 'testimonial_default', 'fragment' => '']]], $this->styleMap($entity)['block:0a000001-0000-4000-8000-000000000001'] ?? NULL);
    $this->assertStylesTargetLast($result, $entity, 'A palette placement that wrote a default variant declares the style map target last.');

    // A flat add without the populate flag (an AI or programmatic add
    // through the op door) is undressed all the same: birth is gated on
    // the batch's posture, and populate means field sampling only.
    $result = $this->place($entity, ['block_type' => 'testimonial', 'uuid' => '0a000002-0000-4000-8000-000000000002']);
    $this->assertSame(['variants' => [['variant' => 'testimonial_default', 'fragment' => '']]], $this->styleMap($entity)['block:0a000002-0000-4000-8000-000000000002'] ?? NULL);
    $this->assertStylesTargetLast($result, $entity, 'A flat op-door add wears the default too and declares the write.');

    // A dressed batch is a total statement: the same add, sampled or not,
    // wears exactly what the batch applies (here nothing).
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'block_type' => 'testimonial',
        'uuid' => '0a000005-0000-4000-8000-000000000005',
        'populate' => 'sample',
        'location' => ['anchor' => self::HOST_SECTION_UUID, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'block_type' => 'testimonial',
        'uuid' => '0a000006-0000-4000-8000-000000000006',
        'location' => ['anchor' => self::HOST_SECTION_UUID, 'placement' => 'inside'],
      ],
    ], ['dressed' => TRUE]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertArrayNotHasKey('block:0a000005-0000-4000-8000-000000000005', $this->styleMap($entity));
    $this->assertArrayNotHasKey('block:0a000006-0000-4000-8000-000000000006', $this->styleMap($entity));
    $this->assertNoStylesTarget($result, $entity, 'A dressed batch writes nothing and declares nothing.');

    // A block type declaring no default variant places unstyled.
    $result = $this->place($entity, [
      'block_type' => 'plain',
      'uuid' => '0a000003-0000-4000-8000-000000000003',
      'populate' => 'sample',
    ]);
    $this->assertArrayNotHasKey('block:0a000003-0000-4000-8000-000000000003', $this->styleMap($entity));
    $this->assertNoStylesTarget($result, $entity, 'A block type without defaults writes nothing and declares nothing.');

    // Plugin blocks have no block type to declare one.
    $result = $this->place($entity, [
      'plugin_id' => 'system_powered_by_block',
      'uuid' => '0a000004-0000-4000-8000-000000000004',
      'populate' => 'sample',
    ]);
    $this->assertArrayNotHasKey('block:0a000004-0000-4000-8000-000000000004', $this->styleMap($entity));
    $this->assertNoStylesTarget($result, $entity, 'A plugin block placement writes nothing and declares nothing.');
  }

  /**
   * A kind:block-scoped default variant applies too (the block family).
   */
  public function testDefaultVariantKindBlockScope(): void {
    $this->createVariant('testimonial_default', 'kind:block');
    $entity = $this->createHostEntity();

    $this->place($entity, [
      'block_type' => 'testimonial',
      'uuid' => '0a000001-0000-4000-8000-000000000001',
      'populate' => 'sample',
    ]);
    $this->assertSame(['variants' => [['variant' => 'testimonial_default', 'fragment' => '']]], $this->styleMap($entity)['block:0a000001-0000-4000-8000-000000000001'] ?? NULL);
  }

  /**
   * Multi-axis defaults compose: one reference per resolvable axis.
   */
  public function testMultiAxisDefaultsOnPlace(): void {
    // The testimonial declaration defaults both of its axes (style:
    // testimonial_default, emphasis: testimonial_emphatic).
    $this->createVariant('testimonial_default', 'block_type:testimonial');
    $this->createVariant('testimonial_emphatic', 'block_type:testimonial', TRUE, 'emphasis');
    $entity = $this->createHostEntity();

    // The reference list is written in axis-vocabulary order: the built-in
    // style axis first, then variant_axes declaration order.
    $this->place($entity, [
      'block_type' => 'testimonial',
      'uuid' => '0a000001-0000-4000-8000-000000000001',
      'populate' => 'sample',
    ]);
    $this->assertSame([
      'variants' => [
        ['variant' => 'testimonial_default', 'fragment' => ''],
        ['variant' => 'testimonial_emphatic', 'fragment' => ''],
      ],
    ], $this->styleMap($entity)['block:0a000001-0000-4000-8000-000000000001'] ?? NULL);
  }

  /**
   * Each axis resolves independently; a mismatched axis skips silently.
   */
  public function testPerAxisDefaultSkips(): void {
    // Only the emphasis default exists: the style axis skips silently and
    // the entry still gets the resolvable reference.
    $this->createVariant('testimonial_emphatic', 'block_type:testimonial', TRUE, 'emphasis');
    $entity = $this->createHostEntity();
    $this->place($entity, [
      'block_type' => 'testimonial',
      'uuid' => '0a000001-0000-4000-8000-000000000001',
      'populate' => 'sample',
    ]);
    $this->assertSame(['variants' => [['variant' => 'testimonial_emphatic', 'fragment' => '']]], $this->styleMap($entity)['block:0a000001-0000-4000-8000-000000000001'] ?? NULL);

    // A variant whose own axis is not the axis it is declared under skips
    // too: writing it would break the one-reference-per-axis invariant the
    // map validator enforces.
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->delete(
      [$this->container->get('daedalus_stylish.variant_repository')->resolve('testimonial_emphatic')],
    );
    $this->createVariant('testimonial_emphatic', 'block_type:testimonial');
    $this->place($entity, [
      'block_type' => 'testimonial',
      'uuid' => '0a000002-0000-4000-8000-000000000002',
      'populate' => 'sample',
    ]);
    $this->assertArrayNotHasKey('block:0a000002-0000-4000-8000-000000000002', $this->styleMap($entity));
  }

  /**
   * Placement never fails over a bad default: it degrades to unstyled.
   */
  public function testDefaultVariantSkips(): void {
    $entity = $this->createHostEntity();

    // The declared variant does not exist.
    $this->place($entity, [
      'block_type' => 'testimonial',
      'uuid' => '0b000001-0000-4000-8000-000000000001',
      'populate' => 'sample',
    ]);
    $this->assertArrayNotHasKey('block:0b000001-0000-4000-8000-000000000001', $this->styleMap($entity));

    // Unpublished: not in the compiled stylesheet, would place invisibly.
    $this->createVariant('testimonial_default', 'block_type:testimonial', FALSE);
    $this->place($entity, [
      'block_type' => 'testimonial',
      'uuid' => '0b000002-0000-4000-8000-000000000002',
      'populate' => 'sample',
    ]);
    $this->assertArrayNotHasKey('block:0b000002-0000-4000-8000-000000000002', $this->styleMap($entity));

    // Mis-scoped: the same verdict validation would reach on the map write.
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->delete(
      [$this->container->get('daedalus_stylish.variant_repository')->resolve('testimonial_default')],
    );
    $this->createVariant('testimonial_default', 'kind:container');
    $this->place($entity, [
      'block_type' => 'testimonial',
      'uuid' => '0b000003-0000-4000-8000-000000000003',
      'populate' => 'sample',
    ]);
    $this->assertArrayNotHasKey('block:0b000003-0000-4000-8000-000000000003', $this->styleMap($entity));

    // A host without styling enabled is untouched (and does not crash).
    $this->createVariant('other_default', 'block_type:testimonial');
    $this->container->get('daedalus_stylish.field_manager')->disable('entity_test', 'entity_test');
    $bare = EntityTest::create([
      'name' => 'bare',
      'layout_builder__layout' => [
        new Section('layout_onecol', [], [], ['daedalus_layout' => ['uuid' => self::HOST_SECTION_UUID]]),
      ],
    ]);
    $bare->save();
    $this->place($bare, [
      'block_type' => 'testimonial',
      'uuid' => '0b000004-0000-4000-8000-000000000004',
      'populate' => 'sample',
    ]);
    $this->assertFalse($bare->hasField(DaedalusStylishFieldManager::FIELD_NAME));
  }

  /**
   * Duplicating a component carries its style entries to the clone's keys.
   */
  public function testDuplicateCarriesStyles(): void {
    $this->createVariant('testimonial_default', 'block_type:testimonial');
    $block = $this->container->get('entity_type.manager')->getStorage('block_content')->create([
      'type' => 'testimonial',
      'reusable' => FALSE,
      'info' => 'Styled',
      'field_quote' => 'Quoted',
    ]);
    $section = new Section('layout_onecol');
    $section->appendComponent(new SectionComponent('0c000001-0000-4000-8000-000000000001', 'content', [
      'id' => 'inline_block:testimonial',
      'provider' => 'layout_builder',
      'label' => 'Styled',
      'label_display' => '0',
      'view_mode' => 'full',
      'block_serialized' => serialize($block),
    ]));
    $section->appendComponent(new SectionComponent('0c000002-0000-4000-8000-000000000002', 'content', [
      'id' => 'system_powered_by_block',
    ]));

    $map = [
      'block:0c000001-0000-4000-8000-000000000001' => [
        'default' => ['padding' => '2rem'],
        'hover' => ['padding' => '3rem'],
      ],
      'block:0c000001-0000-4000-8000-000000000001::.testimonial__quote' => ['default' => ['font-size' => '1.5rem']],
      'block:0c000001-0000-4000-8000-000000000001:field_quote' => ['default' => ['margin' => '1rem']],
      'block:0c000002-0000-4000-8000-000000000002' => [
        'variants' => [['variant' => 'testimonial_default', 'fragment' => '']],
      ],
      'block:untouched' => ['default' => ['padding' => '9rem']],
    ];
    $entity = $this->createHostEntity($map, [$section]);

    // Custom styles, sub-element, and field keys all follow the clone.
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'duplicate_element',
        'element' => '0c000001-0000-4000-8000-000000000001',
        'uuid' => '0c000003-0000-4000-8000-000000000003',
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $carried = $this->styleMap($entity);
    $this->assertSame($map['block:0c000001-0000-4000-8000-000000000001'], $carried['block:0c000003-0000-4000-8000-000000000003'] ?? NULL);
    $this->assertSame($map['block:0c000001-0000-4000-8000-000000000001::.testimonial__quote'], $carried['block:0c000003-0000-4000-8000-000000000003::.testimonial__quote'] ?? NULL);
    $this->assertSame($map['block:0c000001-0000-4000-8000-000000000001:field_quote'], $carried['block:0c000003-0000-4000-8000-000000000003:field_quote'] ?? NULL);
    $this->assertStylesTargetLast($result, $entity, 'The duplicate result declares the style map target after the ADDED target.');

    // Delivery: the style map field target has no stamped element, so the
    // renderer falls back to one whole-entity swap under the entity key,
    // and that markup carries the entity-level style tag with the clone's
    // rules. The ADDED target alone would extract the owning container,
    // which sits beside the tag and never delivers it.
    $rendered = $this->container->get('daedalus_blueprint_api.subtree_renderer')->render($this->host($entity), $result->getTargets());
    $entity_key = 'entity:' . $entity->uuid();
    $this->assertSame([$entity_key], array_keys($rendered['markup']), 'A duplicate carrying styles re-renders as one whole-entity swap.');
    // Read the tag's own text: the clone's markup carries its key in a
    // data-selectable attribute, so a substring check on the whole markup
    // would pass without any CSS delivered.
    $tag_pattern = '{<style data-daedalus-stylish-entity="' . preg_quote($entity->uuid(), '{') . '">(.*?)</style>}s';
    $this->assertSame(1, preg_match($tag_pattern, $rendered['markup'][$entity_key], $tag), 'The whole-entity markup carries the entity-level style tag.');
    $this->assertStringContainsString('@scope ([data-selectable="block:0c000003-0000-4000-8000-000000000003"])', $tag[1], "The style tag holds a rule scoped to the clone's key.");

    // Variant references copy verbatim; both placements share the variant.
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'duplicate_element',
        'element' => '0c000002-0000-4000-8000-000000000002',
        'uuid' => '0c000004-0000-4000-8000-000000000004',
      ],
    ]);
    $this->assertTrue($result->isSuccess());
    $carried = $this->styleMap($entity);
    $this->assertSame(['variants' => [['variant' => 'testimonial_default', 'fragment' => '']]], $carried['block:0c000004-0000-4000-8000-000000000004'] ?? NULL);
    $this->assertStylesTargetLast($result, $entity, 'A duplicate carrying a variant reference declares the style map target too.');

    // Originals are intact and unrelated keys were not copied.
    foreach ($map as $key => $entry) {
      $this->assertSame($entry, $carried[$key] ?? NULL);
    }
    $this->assertCount(count($map) + 4, $carried);
  }

  /**
   * Section keys resolve their layout: declaration, scope, and constraints.
   *
   * The layout plugin id is to sections what the bundle is to inline
   * blocks: with the host entity in context, a container:{uuid} key validates
   * against its layout's guide declaration (daedalus_stylish_guide_test declares
   * layout_twocol_section with a .band-content part capped at
   * max-width 80rem) and captures a "layout:{layout_id}" variant scope,
   * degrading to the universal section kind without context.
   */
  public function testSectionLayoutResolution(): void {
    $section_uuid = 'aaaaaaaa-1111-2222-3333-444444444444';
    $section = new Section('layout_twocol_section', [], [], [
      'daedalus_layout' => ['uuid' => $section_uuid],
    ]);
    $entity = $this->createHostEntity([], [$section]);
    $validator = $this->container->get('daedalus_stylish.style_map_validator');

    // Scope capture: per-layout with the host in context, kind without.
    $this->assertSame('layout:layout_twocol_section', (string) $validator->captureScope("container:$section_uuid", ['host' => $this->host($entity)]));
    $this->assertSame('kind:container', (string) $validator->captureScope("container:$section_uuid"));
    // A rule key captures its LANDING, the element its last compound
    // names, and a part lands on no element scope (nothing wears there);
    // keyAddress() still answers the full address under the layout.
    $this->assertNull($validator->captureScope("container:$section_uuid::.band-content", ['host' => $this->host($entity)]));
    $this->assertSame('layout:layout_twocol_section::.band-content', (string) $validator->keyAddress("container:$section_uuid::.band-content", ['host' => $this->host($entity)]));

    // Per-layout declaration enforcement on the declared part.
    $errors = $validator->validate([
      "container:$section_uuid::.band-content" => ['default' => ['max-width' => '85rem']],
    ], ['host' => $this->host($entity)]);
    $this->assertCount(1, $errors);
    $this->assertSame('STYLE_VALUE_ABOVE_MAX', $errors[0]->getCode());
    $this->assertSame([], $validator->validate([
      "container:$section_uuid::.band-content" => ['default' => ['max-width' => '60rem']],
    ], ['host' => $this->host($entity)]));

    // The section family follows the one family rule: the kind is the
    // wildcard ("kind:container" applies to any section; a degraded capture
    // without host context pairs with any section scope), layouts must
    // match exactly — a twocol treatment is layout-exact, never offered on
    // a onecol section — and families never mix.
    $this->assertTrue($validator->scopeMatches('layout:layout_twocol_section', "container:$section_uuid", ['host' => $this->host($entity)]));
    $this->assertTrue($validator->scopeMatches('kind:container', "container:$section_uuid", ['host' => $this->host($entity)]));
    $this->assertTrue($validator->scopeMatches('layout:layout_twocol_section', "container:$section_uuid"));
    $this->assertFalse($validator->scopeMatches('layout:layout_onecol', "container:$section_uuid", ['host' => $this->host($entity)]));
    // Families never mix: a block key — degraded or resolved — never pairs
    // with layout scopes (blocks never hold layouts).
    $this->assertFalse($validator->scopeMatches('layout:layout_twocol_section', "block:$section_uuid"));

    // Reference validation and the corrective not-found payload speak the
    // same rule: with the layout resolved, only the exact layout's variants
    // and section-wide ones apply.
    $this->createVariant('twocol_hero', 'layout:layout_twocol_section');
    $this->createVariant('onecol_hero', 'layout:layout_onecol');
    $this->createVariant('section_airy', 'kind:container');
    $this->assertSame([], $validator->validate([
      "container:$section_uuid" => ['variants' => [['variant' => 'twocol_hero', 'fragment' => '']]],
    ], ['host' => $this->host($entity)]));
    $this->assertSame([], $validator->validate([
      "container:$section_uuid" => ['variants' => [['variant' => 'section_airy', 'fragment' => '']]],
    ], ['host' => $this->host($entity)]));
    $errors = $validator->validate([
      "container:$section_uuid" => ['variants' => [['variant' => 'onecol_hero', 'fragment' => '']]],
    ], ['host' => $this->host($entity)]);
    $this->assertSame('STYLE_VARIANT_SCOPE_MISMATCH', $errors[0]->getCode());
    $errors = $validator->validate([
      "container:$section_uuid" => ['variants' => [['variant' => 'no_such_variant', 'fragment' => '']]],
    ], ['host' => $this->host($entity)]);
    $this->assertSame('STYLE_VARIANT_NOT_FOUND', $errors[0]->getCode());
    $this->assertSame(['section_airy', 'twocol_hero'], array_column($errors[0]->getContext()['candidates'], 'id'));

    // A section born with this layout through add_section wears the
    // layout's declared default (daedalus_stylish_section_defaults_test: style:
    // twocol_default), and the write is declared on the result after the
    // op's own targets.
    $this->createVariant('twocol_default', 'layout:layout_twocol_section');
    $added_uuid = 'bbbbbbbb-1111-2222-3333-444444444444';
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_section',
        'uuid' => $added_uuid,
        'layout' => 'layout_twocol_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['variants' => [['variant' => 'twocol_default', 'fragment' => '']]], $this->styleMap($entity)["container:$added_uuid"] ?? NULL);
    $this->assertStylesTargetLast($result, $entity, 'The add_section result declares the style map target after the op targets.');
  }

  /**
   * Nested identities inside a duplicated section carry their keys.
   *
   * Duplicating a nested section regenerates the whole subtree's identities
   * (the section's daedalus_layout uuid and its components' uuids); container:/
   * region:/block: entries addressing them follow onto the clone's keys.
   */
  public function testDuplicateCarriesNestedKeys(): void {
    $nested_section = new Section('layout_onecol', [], [], [
      'daedalus_layout' => ['uuid' => '0d000002-0000-4000-8000-000000000002'],
    ]);
    $nested_section->appendComponent(new SectionComponent('0d000003-0000-4000-8000-000000000003', 'content', [
      'id' => 'system_powered_by_block',
    ]));
    $section = new Section('layout_onecol');
    $section->appendComponent(SectionBlock::createComponent($nested_section, 'content'));

    $map = [
      'container:0d000002-0000-4000-8000-000000000002' => ['default' => ['padding' => '4rem']],
      'region:0d000002-0000-4000-8000-000000000002:content' => ['default' => ['margin' => '2rem']],
      'block:0d000003-0000-4000-8000-000000000003' => ['default' => ['color' => 'teal']],
    ];
    $entity = $this->createHostEntity($map, [$section]);

    // Sections are duplicated by their SECTION uuid (component uuid ==
    // section uuid); the clone mints its own identity.
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'duplicate_element',
        'element' => '0d000002-0000-4000-8000-000000000002',
        'uuid' => '0d000004-0000-4000-8000-000000000004',
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    // Read the clone's regenerated nested identities.
    $adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $host_section = $entity->get('layout_builder__layout')->first()->section;
    $clone_section = $adapter->embeddedSection($host_section->getComponent('0d000004-0000-4000-8000-000000000004'));
    $this->assertNotNull($clone_section);
    $new_section_uuid = $adapter->getSectionUuid($clone_section);
    $this->assertSame('0d000004-0000-4000-8000-000000000004', $new_section_uuid);
    $new_component_uuids = array_keys($clone_section->getComponents());
    $this->assertCount(1, $new_component_uuids);
    $new_component_uuid = $new_component_uuids[0];
    $this->assertNotSame('0d000003-0000-4000-8000-000000000003', $new_component_uuid);

    $carried = $this->styleMap($entity);
    $this->assertSame($map['container:0d000002-0000-4000-8000-000000000002'], $carried["container:$new_section_uuid"] ?? NULL);
    $this->assertSame($map['region:0d000002-0000-4000-8000-000000000002:content'], $carried["region:$new_section_uuid:content"] ?? NULL);
    $this->assertSame($map['block:0d000003-0000-4000-8000-000000000003'], $carried["block:$new_component_uuid"] ?? NULL);
    // Originals intact.
    foreach ($map as $key => $entry) {
      $this->assertSame($entry, $carried[$key] ?? NULL);
    }
  }

  /**
   * Birth defaults, duplicate carry and the target follow a display host.
   *
   * A template is a host like any other: the same lifecycle events fire
   * for its placements, and the subscriber reads and writes the display's
   * map (its third-party setting) through the host, declaring the write
   * as the host's style store (named `styles` on a display, where a
   * content host names its field).
   */
  public function testDisplayHostFollowsTheSameLifecycle(): void {
    $this->createVariant('testimonial_default', 'block_type:testimonial');
    $this->createVariant('twocol_default', 'layout:layout_twocol_section');
    $host = $this->container->get('daedalus_blueprint.storage_handler')->load('entity_view_display', 'entity_test.entity_test.default');
    $this->assertNotNull($host);
    $this->assertSame('entity_view_display', $host->entityTypeId());
    $styles_target = ['uuid' => $host->uuid(), 'field_name' => 'styles'];

    // A section born on the template wears its layout's default.
    $section_uuid = 'dd000001-0000-4000-8000-000000000001';
    $result = $this->operations->batchOperations($host, [
      [
        'op' => 'add_section',
        'uuid' => $section_uuid,
        'layout' => 'layout_twocol_section',
        'location' => ['anchor' => $host->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['variants' => [['variant' => 'twocol_default', 'fragment' => '']]], $host->styleMap()["container:$section_uuid"] ?? NULL);
    $targets = $result->getTargets();
    $this->assertSame($styles_target, end($targets), 'The add_section result declares the display host\'s style store last.');

    // A block placed on the template wears its type's default (as a value
    // block: the template holds what the field grammar spells).
    $block_uuid = 'dd000002-0000-4000-8000-000000000002';
    $result = $this->operations->batchOperations($host, [
      [
        'op' => 'add_element',
        'uuid' => $block_uuid,
        'block_type' => 'testimonial',
        'properties' => ['field_quote' => 'Template quote'],
        'location' => ['anchor' => $section_uuid, 'placement' => 'inside'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['variants' => [['variant' => 'testimonial_default', 'fragment' => '']]], $host->styleMap()["block:$block_uuid"] ?? NULL);
    $targets = $result->getTargets();
    $this->assertSame($styles_target, end($targets));

    // A styled block duplicated on the template carries its entry.
    $result = $this->operations->batchOperations($host, [
      [
        'op' => 'update_style',
        'element' => $block_uuid,
        'key' => "block:$block_uuid",
        'condition' => 'default',
        'properties' => ['padding' => '2rem'],
      ],
      [
        'op' => 'duplicate_element',
        'element' => $block_uuid,
        'uuid' => 'dd000003-0000-4000-8000-000000000003',
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $map = $host->styleMap();
    $this->assertSame($map["block:$block_uuid"], $map['block:dd000003-0000-4000-8000-000000000003'] ?? NULL);
    $this->assertSame(['padding' => '2rem'], $map['block:dd000003-0000-4000-8000-000000000003']['default'] ?? NULL);

    // The map lives on the display, as config: rows of key and entry.
    $this->assertSame($map, array_column($host->entity()->getThirdPartySetting('daedalus_stylish', 'styles'), 'entry', 'key'));
  }

}
