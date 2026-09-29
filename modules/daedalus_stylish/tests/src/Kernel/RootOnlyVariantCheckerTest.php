<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\daedalus_stylish\RootOnlyVariantChecker;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the depth gate on the apply seam (RootOnlyVariantChecker).
 *
 * Placement curates offers and birth, never legality. The shipped
 * container's width axis is root-only (daedalus_stylish_layouts.page_builder.yml),
 * so its variants carry the roster's applies.depth stamp: a NEW wearing of
 * one on a nested container is refused at the apply_variant gesture with
 * VARIANT_ROOT_ONLY, in the dialect's AXIS_NOT_TOP_LEVEL words, while the
 * same apply at root and any non-root-only apply nested go through. A map
 * that already carries the nested reference validates untouched (a
 * whole-map write cannot tell a new reference from an old one, so the
 * validator stays depth-blind on references) and a re-apply is still the
 * gate; depth itself is settled on the move path: out to root the
 * reference rides along, back inside it is stripped (the whole move
 * lifecycle is SectionMoveWearTest's).
 *
 * Real declarations and real shipped variants (daedalus_stylish_layouts installed),
 * so the record's stamp is the production one, not a fixture's.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class RootOnlyVariantCheckerTest extends KernelTestBase {

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
    // every build, and the checker reads the roster.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    // The daedalus_stylish_layouts install syncs the shipped variants (the entrance
    // family's motion-* references mirror into the animation usage table);
    // the importer switches to an administrative account. A host entity
    // saved directly (the whole-map lane below) mirrors its variant
    // references into the variant usage table.
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
   * Runs a batch of operations, asserting success.
   */
  protected function batch(EntityTest $entity, array $operations): void {
    $result = $this->operations->batchOperations($entity, $operations);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
  }

  /**
   * Reads an entity's style map back.
   */
  protected function styleMap(EntityTest $entity): array {
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * A host with one root band nesting one container, both minted by ops.
   *
   * @return array{0: \Drupal\entity_test\Entity\EntityTest, 1: string, 2: string}
   *   The entity, the root section uuid, the nested section uuid.
   */
  protected function createHost(): array {
    $entity = EntityTest::create(['name' => 'host']);
    $entity->save();
    $uuid_service = $this->container->get('uuid');
    $root = $uuid_service->generate();
    $nested = $uuid_service->generate();
    $this->batch($entity, [
      ['op' => 'add_section', 'uuid' => $root, 'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside']],
      ['op' => 'add_section', 'uuid' => $nested, 'location' => ['anchor' => $root, 'placement' => 'inside']],
    ]);
    return [$entity, $root, $nested];
  }

  /**
   * The checker is registered on the seam through daedalus_stylish's own services.
   */
  public function testCheckerRegistered(): void {
    $this->assertInstanceOf(RootOnlyVariantChecker::class, $this->container->get('daedalus_stylish.root_only_variant_checker'));
  }

  /**
   * A root-only variant is refused nested and allowed at root.
   */
  public function testNestedApplyRefused(): void {
    [$entity, $root, $nested] = $this->createHost();
    $root_key = "container:$root";
    $nested_key = "container:$nested";

    // Birth: the band wears container_site, the nested container skips the
    // root-only axis (the default_variants face, untouched).
    $this->assertSame(['variants' => [['variant' => 'container_site', 'fragment' => '']]], $this->styleMap($entity)[$root_key] ?? NULL);
    $this->assertArrayNotHasKey($nested_key, $this->styleMap($entity));

    // A NEW wearing of the root-only width variant on the nested container
    // is refused in AXIS_NOT_TOP_LEVEL's words, and nothing lands.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $nested_key, 'variant' => 'container_site'],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('VARIANT_ROOT_ONLY', $error->getCode());
    $this->assertSame('The "container_site" variant applies to top-level containers only, never nested inside another container.', $error->getMessage());
    $this->assertSame('Set it on the outermost container.', $error->getHint());
    $this->assertSame(['variant' => 'container_site', 'axis' => 'width'], $error->getContext());
    $this->assertArrayNotHasKey($nested_key, $this->styleMap($entity));

    // Every root-only record refuses nested, not only the birth default.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $nested_key, 'variant' => 'container_md'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('VARIANT_ROOT_ONLY', $result->getErrors()[0]->getCode());

    // Deeper nesting is nested all the same.
    $deep = $this->container->get('uuid')->generate();
    $this->batch($entity, [
      ['op' => 'add_section', 'uuid' => $deep, 'location' => ['anchor' => $nested, 'placement' => 'inside']],
    ]);
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => "container:$deep", 'variant' => 'container_site'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('VARIANT_ROOT_ONLY', $result->getErrors()[0]->getCode());

    // The gate is per record, not per depth: a variant on an axis the
    // container offers at every depth applies nested.
    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => $nested_key, 'variant' => 'columns'],
    ]);
    $this->assertSame(['variants' => [['variant' => 'columns', 'fragment' => '']]], $this->styleMap($entity)[$nested_key] ?? NULL);

    // At root the same root-only variants apply: a fresh width pick, and
    // the birth default worn again after it is taken off.
    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => $root_key, 'variant' => 'container_md'],
    ]);
    $this->assertSame(['variants' => [['variant' => 'container_md', 'fragment' => '']]], $this->styleMap($entity)[$root_key] ?? NULL);
    $this->batch($entity, [
      ['op' => 'remove_variant', 'key' => $root_key, 'variant' => 'container_md'],
      ['op' => 'apply_variant', 'key' => $root_key, 'variant' => 'container_site'],
    ]);
    $this->assertSame(['variants' => [['variant' => 'container_site', 'fragment' => '']]], $this->styleMap($entity)[$root_key] ?? NULL);
  }

  /**
   * An existing nested reference validates; a move settles its depth.
   *
   * The validator stays depth-blind on references (no whole-map write can
   * tell a new reference from an old one), so the nested reference saves
   * and validates clean and a re-apply is the only gate. The move path is
   * where depth is settled: out to the root flow the reference rides
   * along (it IS the house width, so the redress adds nothing), and back
   * inside another band it is stripped.
   */
  public function testExistingNestedReferenceStrippedOnNest(): void {
    [$entity, $root, $nested] = $this->createHost();
    $nested_key = "container:$nested";
    $nested_entry = ['variants' => [['variant' => 'container_site', 'fragment' => '']]];

    // A whole-map write carrying the nested reference (the field handler's
    // and the deserializer's lane) saves and validates clean.
    $map = $this->styleMap($entity);
    $map[$nested_key] = $nested_entry;
    $entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $entity->save();
    $this->assertSame([], $this->container->get('daedalus_stylish.style_map_validator')->validate($map, ['entity' => $entity]));
    $this->assertSame($nested_entry, $this->styleMap($entity)[$nested_key] ?? NULL);

    // Removing and detaching stay legal nested; a re-apply is the gate.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => $nested_key, 'variant' => 'container_md'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('VARIANT_ROOT_ONLY', $result->getErrors()[0]->getCode());
    $this->assertSame($nested_entry, $this->styleMap($entity)[$nested_key] ?? NULL);

    // Out to the root flow: the container becomes a band, the reference
    // rides along; the redress finds the width axis already worn.
    $locator = $this->container->get('daedalus_blueprint.element_locator');
    $this->assertTrue($locator->isNestedSection($entity, $nested));
    $this->batch($entity, [
      ['op' => 'move_element', 'element' => $nested, 'location' => ['anchor' => $root, 'placement' => 'after']],
    ]);
    $this->assertFalse($locator->isNestedSection($entity, $nested));
    $this->assertSame($nested_entry, $this->styleMap($entity)[$nested_key] ?? NULL);

    // Back inside another band: root-only wear is a fact of depth, so the
    // move strips the width reference; the entry held nothing else and is
    // dropped.
    $this->batch($entity, [
      ['op' => 'move_element', 'element' => $nested, 'location' => ['anchor' => $root, 'placement' => 'inside']],
    ]);
    $this->assertTrue($locator->isNestedSection($entity, $nested));
    $this->assertArrayNotHasKey($nested_key, $this->styleMap($entity));
    $this->assertSame([], $this->container->get('daedalus_stylish.style_map_validator')->validate($this->styleMap($entity), ['entity' => $entity]));
  }


  /**
   * A root-only variant is refused at any rule: matches are never top-level.
   *
   * The rule's owner may be the page band itself; what the rule reaches
   * sits inside it, so the depth question has one answer before the
   * locator is asked. An every-depth variant wears at the rule as one
   * reference on the rule's entry, distributing nothing.
   */
  public function testRuleApplyRefused(): void {
    [$entity, $root, $nested] = $this->createHost();
    $rule = "container:$root::> pb-container";

    foreach (['container_site', 'container_md'] as $variant) {
      $result = $this->operations->batchOperations($entity, [
        ['op' => 'apply_variant', 'key' => $rule, 'variant' => $variant],
      ]);
      $this->assertFalse($result->isSuccess());
      $error = $result->getErrors()[0];
      $this->assertSame('VARIANT_ROOT_ONLY', $error->getCode());
      $this->assertStringContainsString("a rule's matches are never top-level", $error->getMessage());
      $this->assertSame(['variant' => $variant, 'axis' => 'width'], $error->getContext());
      $this->assertArrayNotHasKey($rule, $this->styleMap($entity));
    }

    // The every-depth axis wears at the rule: one reference on the rule's
    // own entry, the nested container's entry untouched.
    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => $rule, 'variant' => 'columns'],
    ]);
    $map = $this->styleMap($entity);
    $this->assertSame(['variants' => [['variant' => 'columns', 'fragment' => '']]], $map[$rule] ?? NULL);
    $this->assertArrayNotHasKey("container:$nested", $map);
  }

}
