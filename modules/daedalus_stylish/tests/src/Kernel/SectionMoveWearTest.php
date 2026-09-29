<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests root-only wear following a section's depth on move_element.
 *
 * A container's root-only wear (width on the shipped container) is a fact
 * of its depth, never a memory. ElementStylesSubscriber::onElementMoved
 * answers the spine's ElementMovedEvent the way birth defaults answer the
 * created events: a section landing nested loses every root reference
 * whose roster record is root-only (every-depth wear and own styles stay,
 * an emptied entry is dropped), a section coming out to root is redressed
 * with its layout's root defaults on the axes it does not already wear, a
 * root-to-root move touches nothing, and a component move is ignored. The
 * map write is declared as the host's daedalus_stylish field target exactly when
 * the map changed.
 *
 * Real declarations and real shipped variants (daedalus_stylish_layouts installed):
 * container_site is the root-only birth width, container_md another
 * root-only width, columns the every-depth container_layout variant. The UI
 * drag and the AI's op lane issue the same move_element, so these pins
 * see every failure the browser would.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SectionMoveWearTest extends KernelTestBase {

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
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and the strip reads the roster's depth stamp.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    // The daedalus_stylish_layouts install syncs the shipped variants; a host saved
    // directly (the whole-map lane) mirrors its references into the usage
    // tables.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_animation_usage']);
    $this->setUpCurrentUser([], [], TRUE);
    \Drupal::service('module_installer')->install(['daedalus_stylish_layouts']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');

    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * Runs a batch of operations, asserting success, and returns the result.
   */
  protected function batch(EntityTest $entity, array $operations): BlueprintResult {
    $result = $this->operations->batchOperations($entity, $operations);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    return $result;
  }

  /**
   * Moves one element through the batch grammar.
   */
  protected function move(EntityTest $entity, string $uuid, string $anchor, string $placement): BlueprintResult {
    return $this->batch($entity, [
      ['op' => 'move_element', 'element' => $uuid, 'location' => ['anchor' => $anchor, 'placement' => $placement]],
    ]);
  }

  /**
   * Reads an entity's style map back.
   */
  protected function styleMap(EntityTest $entity): array {
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * Writes a whole style map onto the host (the field handler's lane).
   */
  protected function writeStyleMap(EntityTest $entity, array $map): void {
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();
  }

  /**
   * Asserts the map validates clean against the host.
   */
  protected function assertMapValid(EntityTest $entity): void {
    $this->assertSame([], $this->container->get('daedalus_stylish.style_map_validator')->validate($this->styleMap($entity), ['entity' => $entity]));
  }

  /**
   * Asserts whether the result declares the host's daedalus_stylish field target.
   */
  protected function assertDaedalusStylishTarget(BlueprintResult $result, EntityTest $entity, bool $declared): void {
    $target = ['uuid' => $entity->uuid(), 'field_name' => DaedalusStylishFieldManager::FIELD_NAME];
    if ($declared) {
      $this->assertContains($target, $result->getTargets(), 'The map changed, so the daedalus_stylish field target is declared.');
    }
    else {
      $this->assertNotContains($target, $result->getTargets(), 'The map did not change, so no daedalus_stylish field target is declared.');
    }
  }

  /**
   * Whether a section sits nested, per the element locator.
   */
  protected function isNested(EntityTest $entity, string $uuid): bool {
    return $this->container->get('daedalus_blueprint.element_locator')->isNestedSection($entity, $uuid);
  }

  /**
   * A host with two root bands, the first nesting one container.
   *
   * Every section is minted by ops, so the bands are born wearing the
   * shipped width default (container_site) and the nested container is
   * born wearing nothing (the root-only axis is skipped at nested depth).
   *
   * @return array{0: \Drupal\entity_test\Entity\EntityTest, 1: string, 2: string, 3: string}
   *   The entity, the first band's uuid, the second band's uuid, the
   *   nested container's uuid.
   */
  protected function createHost(): array {
    $entity = EntityTest::create(['name' => 'host']);
    $entity->save();
    $uuid_service = $this->container->get('uuid');
    $band_a = $uuid_service->generate();
    $band_b = $uuid_service->generate();
    $nested = $uuid_service->generate();
    $this->batch($entity, [
      ['op' => 'add_section', 'uuid' => $band_a, 'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside']],
      ['op' => 'add_section', 'uuid' => $band_b, 'location' => ['anchor' => $band_a, 'placement' => 'after']],
      ['op' => 'add_section', 'uuid' => $nested, 'location' => ['anchor' => $band_a, 'placement' => 'inside']],
    ]);
    $this->assertSame(['variants' => [['variant' => 'container_site', 'fragment' => '']]], $this->styleMap($entity)["container:$band_a"] ?? NULL);
    $this->assertSame(['variants' => [['variant' => 'container_site', 'fragment' => '']]], $this->styleMap($entity)["container:$band_b"] ?? NULL);
    $this->assertArrayNotHasKey("container:$nested", $this->styleMap($entity));
    return [$entity, $band_a, $band_b, $nested];
  }

  /**
   * Root to nested strips the width, keeps every-depth wear and own styles.
   */
  public function testRootToNestedStrips(): void {
    [$entity, $band_a, $band_b] = $this->createHost();
    $key = "container:$band_a";

    // The band wears the house width, Columns, and an own style block.
    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'columns'],
    ]);
    $map = $this->styleMap($entity);
    $map[$key]['default'] = ['padding-top' => '2rem'];
    $this->writeStyleMap($entity, $map);
    $this->assertSame([
      'variants' => [['variant' => 'container_site', 'fragment' => ''], ['variant' => 'columns', 'fragment' => '']],
      'default' => ['padding-top' => '2rem'],
    ], $this->styleMap($entity)[$key]);

    // Inside the other band: the root-only width is stripped, the
    // every-depth Columns reference and the own block stay in place.
    $result = $this->move($entity, $band_a, $band_b, 'inside');
    $this->assertTrue($this->isNested($entity, $band_a));
    $this->assertSame([
      'variants' => [['variant' => 'columns', 'fragment' => '']],
      'default' => ['padding-top' => '2rem'],
    ], $this->styleMap($entity)[$key] ?? NULL);
    $this->assertDaedalusStylishTarget($result, $entity, TRUE);
    $this->assertMapValid($entity);

    // A band wearing only its width is left with nothing: the entry is
    // dropped rather than kept as an empty object.
    $bare = $this->container->get('uuid')->generate();
    $this->batch($entity, [
      ['op' => 'add_section', 'uuid' => $bare, 'location' => ['anchor' => $band_b, 'placement' => 'after']],
    ]);
    $this->assertSame(['variants' => [['variant' => 'container_site', 'fragment' => '']]], $this->styleMap($entity)["container:$bare"] ?? NULL);
    $result = $this->move($entity, $bare, $band_b, 'inside');
    $this->assertTrue($this->isNested($entity, $bare));
    $this->assertArrayNotHasKey("container:$bare", $this->styleMap($entity));
    $this->assertDaedalusStylishTarget($result, $entity, TRUE);
    $this->assertMapValid($entity);
  }

  /**
   * Nested to root redresses with the shipped defaults on unworn axes only.
   */
  public function testNestedToRootRedresses(): void {
    [$entity, $band_a, , $nested] = $this->createHost();
    $key = "container:$nested";

    // The nested container wears Columns and an own style block; out to
    // root it gains the house width after what it already wears.
    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'columns'],
    ]);
    $map = $this->styleMap($entity);
    $map[$key]['default'] = ['padding-top' => '2rem'];
    $this->writeStyleMap($entity, $map);
    $result = $this->move($entity, $nested, $band_a, 'after');
    $this->assertFalse($this->isNested($entity, $nested));
    $this->assertSame([
      'variants' => [['variant' => 'columns', 'fragment' => ''], ['variant' => 'container_site', 'fragment' => '']],
      'default' => ['padding-top' => '2rem'],
    ], $this->styleMap($entity)[$key] ?? NULL);
    $this->assertDaedalusStylishTarget($result, $entity, TRUE);
    $this->assertMapValid($entity);

    // A nested container wearing nothing comes out wearing the defaults.
    $blank = $this->container->get('uuid')->generate();
    $this->batch($entity, [
      ['op' => 'add_section', 'uuid' => $blank, 'location' => ['anchor' => $band_a, 'placement' => 'inside']],
    ]);
    $this->assertArrayNotHasKey("container:$blank", $this->styleMap($entity));
    $result = $this->move($entity, $blank, $band_a, 'after');
    $this->assertFalse($this->isNested($entity, $blank));
    $this->assertSame(['variants' => [['variant' => 'container_site', 'fragment' => '']]], $this->styleMap($entity)["container:$blank"] ?? NULL);
    $this->assertDaedalusStylishTarget($result, $entity, TRUE);

    // A width already worn (a whole-map write carrying container_md at
    // nested depth) is not replaced by the default: the axis is worn, the
    // entry stays byte-identical and no target is declared.
    $worn = $this->container->get('uuid')->generate();
    $worn_key = "container:$worn";
    $worn_entry = ['variants' => [['variant' => 'container_md', 'fragment' => '']]];
    $this->batch($entity, [
      ['op' => 'add_section', 'uuid' => $worn, 'location' => ['anchor' => $band_a, 'placement' => 'inside']],
    ]);
    $map = $this->styleMap($entity);
    $map[$worn_key] = $worn_entry;
    $this->writeStyleMap($entity, $map);
    $result = $this->move($entity, $worn, $band_a, 'after');
    $this->assertFalse($this->isNested($entity, $worn));
    $this->assertSame($worn_entry, $this->styleMap($entity)[$worn_key] ?? NULL);
    $this->assertDaedalusStylishTarget($result, $entity, FALSE);
    $this->assertMapValid($entity);
  }

  /**
   * Root to root leaves the entry byte-identical: a reorder never restyles.
   */
  public function testRootToRootUntouched(): void {
    [$entity, $band_a, $band_b] = $this->createHost();
    $key = "container:$band_a";

    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'columns'],
    ]);
    $map = $this->styleMap($entity);
    $map[$key]['default'] = ['padding-top' => '2rem'];
    $this->writeStyleMap($entity, $map);
    $before = $this->styleMap($entity);

    $result = $this->move($entity, $band_a, $band_b, 'after');
    $this->assertFalse($this->isNested($entity, $band_a));
    $this->assertSame($before, $this->styleMap($entity));
    $this->assertDaedalusStylishTarget($result, $entity, FALSE);
  }

  /**
   * Nested to nested strips: depth is nested on both sides.
   */
  public function testNestedToNestedStrips(): void {
    [$entity, , $band_b, $nested] = $this->createHost();
    $key = "container:$nested";

    // The whole-map lane smuggles a root-only width onto the nested
    // container (the validator stays depth-blind on references), beside
    // an every-depth Columns reference.
    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'columns'],
    ]);
    $map = $this->styleMap($entity);
    $map[$key]['variants'][] = ['variant' => 'container_site', 'fragment' => ''];
    $this->writeStyleMap($entity, $map);
    $this->assertMapValid($entity);

    // Into the other band's region: still nested, and the width goes.
    $result = $this->move($entity, $nested, $band_b, 'inside');
    $this->assertTrue($this->isNested($entity, $nested));
    $this->assertSame(['variants' => [['variant' => 'columns', 'fragment' => '']]], $this->styleMap($entity)[$key] ?? NULL);
    $this->assertDaedalusStylishTarget($result, $entity, TRUE);
    $this->assertMapValid($entity);
  }

  /**
   * A component move touches nothing: blocks wear no root-only axis.
   */
  public function testComponentMoveTouchesNothing(): void {
    [$entity, $band_a, , $nested] = $this->createHost();

    $block = $this->container->get('uuid')->generate();
    $this->batch($entity, [
      [
        'op' => 'add_element',
        'plugin_id' => 'system_powered_by_block',
        'uuid' => $block,
        'location' => ['anchor' => $band_a, 'placement' => 'inside'],
      ],
    ]);
    $map = $this->styleMap($entity);
    $map["block:$block"] = ['default' => ['padding-top' => '2rem']];
    $this->writeStyleMap($entity, $map);
    $before = $this->styleMap($entity);

    // Root band to nested container, the depth case a section would
    // answer; the block's entry and every other are untouched.
    $result = $this->move($entity, $block, $nested, 'inside');
    $this->assertSame($before, $this->styleMap($entity));
    $this->assertDaedalusStylishTarget($result, $entity, FALSE);
  }

  /**
   * Both shipped arrangements import at install, on one every-depth axis.
   *
   * The install sync landed the container_layout family: columns (equal
   * shares, its narrow block the stack) and row (natural widths, wrapping,
   * no narrow block and no children rule), so the layout widget's three
   * modes and the AI's apply_variant find both under the guide.
   */
  public function testShippedArrangementsImport(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('daedalus_variant');
    $arrangements = [];
    foreach (['columns', 'row'] as $name) {
      $variants = $storage->loadByProperties(['machine_name' => $name]);
      $this->assertCount(1, $variants, "One shipped $name variant.");
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant */
      $variant = reset($variants);
      $this->assertTrue($variant->isShipped());
      $this->assertFalse($variant->isModified());
      $this->assertTrue($variant->isPublished());
      $this->assertSame('daedalus_stylish_layouts', $variant->getProvider());
      $this->assertSame('container_layout', $variant->getAxis());
      $this->assertSame('kind:container', $variant->getScope());
      $arrangements[$name] = $variant;
    }
    $this->assertSame('Columns', $arrangements['columns']->label());
    $this->assertSame('Row', $arrangements['row']->label());

    $row = $arrangements['row']->getStyles();
    $this->assertSame([''], array_keys($row), 'Row styles the root alone: no children rule.');
    $this->assertSame(['default'], array_keys($row['']), 'Row wraps rather than stacking: no narrow block.');
    $this->assertSame(['flex-direction' => 'row', 'flex-wrap' => 'wrap', 'gap' => 'var(--space-gutter)'], $row['']['default']);
    $columns = $arrangements['columns']->getStyles();
    $this->assertSame(['', '::> *'], array_keys($columns));
    $this->assertSame('column', $columns['']['narrow']['flex-direction']);
  }

}
