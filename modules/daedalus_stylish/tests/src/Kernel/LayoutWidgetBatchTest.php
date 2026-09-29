<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Locks the layout widget's batch contract (mode + share).
 *
 * The widget is the single coordinator for the container_layout axis, and every
 * gesture it fires is a batch of ordinary spine ops — the EXACT shapes the
 * client's layout-widget.js emits (its node suite locks the client half).
 * A container is ONE box, so every style lands on the container ROOT key:
 * - Mode flip on: [apply_variant row] — the shipped kind:container
 *   arrangement variant, count-independent, zero per-child writes.
 * - Preset with minting: [add_section x N, update_style flex-grow on the
 *   deviating children] — later ops key on the uuids earlier ones minted.
 * - Drag commit: [update_style flex-grow, update_style flex-grow] on the
 *   divider's pair, weight 1 spelled as UNSET (equal is the ::> * rule).
 * - Gap override/reset: one update_style on the container root.
 * - Mode flip off: [remove_variant row] — the reference drops WITHOUT
 *   materializing, and the children's grow overrides stay in the map,
 *   inert, so flipping back restores the proportions.
 * - Alignment (the folded-in Arrange control): a stack pick is a plain
 *   flex override on the root (align-items across the declared flex
 *   column, justify-content along it), never a display of its own; the
 *   row cross-axis is a plain align-items override on the same key.
 *
 * Styles land through the UNIVERSAL container-kind declaration
 * (daedalus_stylish_layouts.page_builder.yml) and the shipped row variant the
 * daedalus_stylish_layouts install syncs, so the whole batch validates against the
 * real guide and the real variant, not fixtures.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class LayoutWidgetBatchTest extends KernelTestBase {

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
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    $this->installSchema('layout_builder', ['inline_block_usage']);
    // The daedalus_stylish_layouts install syncs the shipped variants (the row
    // variant included, and the entrance family whose motion-* references
    // a variant save mirrors into the animation usage table); the importer
    // switches to an administrative account, so give the switcher one.
    $this->installSchema('daedalus_stylish', ['daedalus_animation_usage']);
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
   * The ordered child section uuids of a container section.
   *
   * @return string[]
   *   The child container components' uuids, weight-ordered.
   */
  protected function childUuids(EntityTest $entity, string $section_uuid): array {
    $section = NULL;
    foreach ($entity->get('layout_builder__layout') as $item) {
      if ($item->section->getThirdPartySetting('daedalus_layout', 'uuid') === $section_uuid) {
        $section = $item->section;
      }
    }
    $this->assertNotNull($section);
    $components = $section->getComponents();
    uasort($components, static fn ($a, $b) => $a->getWeight() <=> $b->getWeight());
    return array_keys($components);
  }

  /**
   * The full gesture arc: mode on, preset minting, drag, gap, mode off.
   */
  public function testLayoutWidgetBatches(): void {
    $entity = EntityTest::create(['name' => 'host']);
    $entity->save();

    $uuid_service = $this->container->get('uuid');
    $section_uuid = $uuid_service->generate();
    $key = "container:$section_uuid";
    $this->batch($entity, [
      [
        'op' => 'add_section',
        'uuid' => $section_uuid,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);

    // Birth default: a root band is born wearing container_site (width)
    // only — no arrangement reference, which the widget reads as Stack.
    $this->assertSame(
      ['variants' => [['variant' => 'container_site', 'fragment' => '']]],
      $this->styleMap($entity)[$key] ?? NULL,
    );

    // Mode flip to Columns then back to Stack: ONE apply_variant, ONE
    // remove_variant, nothing per child, no other write — equal columns at
    // any count are the variant + the ::> * rule. The row reference composes
    // with the band's birth default on the width axis.
    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'columns'],
    ]);
    $this->assertSame(
      [
        'variants' => [
        ['variant' => 'container_site', 'fragment' => ''],
        ['variant' => 'columns', 'fragment' => ''],
        ],
      ],
      $this->styleMap($entity)[$key] ?? NULL,
    );
    $this->batch($entity, [
      ['op' => 'remove_variant', 'key' => $key, 'variant' => 'columns'],
    ]);
    $this->assertSame(
      ['variants' => [['variant' => 'container_site', 'fragment' => '']]],
      $this->styleMap($entity)[$key] ?? NULL,
    );
    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'columns'],
    ]);

    // Preset with minting on a childless band (the widget's [1, 2, 1]
    // chip): pure add_sections mint the columns, then the ONE deviating
    // grow keys on a uuid minted earlier in the same batch.
    $first = $uuid_service->generate();
    $second = $uuid_service->generate();
    $third = $uuid_service->generate();
    $this->batch($entity, [
      ['op' => 'add_section', 'uuid' => $first, 'location' => ['anchor' => $section_uuid, 'placement' => 'inside']],
      ['op' => 'add_section', 'uuid' => $second, 'location' => ['anchor' => $section_uuid, 'placement' => 'inside']],
      ['op' => 'add_section', 'uuid' => $third, 'location' => ['anchor' => $section_uuid, 'placement' => 'inside']],
      ['op' => 'update_style', 'key' => "container:$second", 'properties' => ['flex-grow' => '2']],
    ]);
    $this->assertSame([$first, $second, $third], $this->childUuids($entity, $section_uuid));
    $map = $this->styleMap($entity);
    $this->assertSame(['default' => ['flex-grow' => '2']], $map["container:$second"] ?? NULL);
    $this->assertArrayNotHasKey("container:$first", $map);
    $this->assertArrayNotHasKey("container:$third", $map);

    // Drag commit on the first divider (50/25/25 dragged to center =
    // 1.5/1.5 over the pair): both pair children write, weight 1 on any
    // child spells UNSET, the third column is untouched.
    $this->batch($entity, [
      ['op' => 'update_style', 'key' => "container:$first", 'properties' => ['flex-grow' => '1.5']],
      ['op' => 'update_style', 'key' => "container:$second", 'properties' => ['flex-grow' => '1.5']],
    ]);
    $map = $this->styleMap($entity);
    $this->assertSame(['default' => ['flex-grow' => '1.5']], $map["container:$first"] ?? NULL);
    $this->assertSame(['default' => ['flex-grow' => '1.5']], $map["container:$second"] ?? NULL);

    // Back toward equal: the pair unsets (equal fill is the ::> * rule, so
    // equal means NO entry, never flex-grow 1).
    $this->batch($entity, [
      ['op' => 'update_style', 'key' => "container:$first", 'properties' => ['flex-grow' => NULL]],
      ['op' => 'update_style', 'key' => "container:$second", 'properties' => ['flex-grow' => '2']],
    ]);
    $this->assertArrayNotHasKey("container:$first", $this->styleMap($entity));

    // Gap override on the container root (the one box), then the reset
    // gesture: unset returns the container to the variant's
    // push-restylable site default, and the entry keeps only its worn
    // references.
    $this->batch($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['gap' => '24px']],
    ]);
    $this->assertSame(['gap' => '24px'], $this->styleMap($entity)[$key]['default'] ?? NULL);
    $this->batch($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['gap' => NULL]],
    ]);
    $this->assertArrayNotHasKey('default', $this->styleMap($entity)[$key] ?? []);

    // Mode flip off: remove_variant drops the reference WITHOUT
    // materializing — no flex/display land anywhere as overrides — while
    // the cross-axis width reference and the child grow both stay (the
    // grow inert, ready for a flip back).
    $this->batch($entity, [
      ['op' => 'remove_variant', 'key' => $key, 'variant' => 'columns'],
    ]);
    $map = $this->styleMap($entity);
    $this->assertSame(
      ['variants' => [['variant' => 'container_site', 'fragment' => '']]],
      $map[$key] ?? NULL,
    );
    $this->assertSame(['default' => ['flex-grow' => '2']], $map["container:$second"] ?? NULL);

    // Flipping back on restores the proportions purely by re-reference.
    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'columns'],
    ]);
    $map = $this->styleMap($entity);
    $this->assertSame([
      ['variant' => 'container_site', 'fragment' => ''],
      ['variant' => 'columns', 'fragment' => ''],
    ], $map[$key]['variants'] ?? NULL);
    $this->assertSame(['default' => ['flex-grow' => '2']], $map["container:$second"] ?? NULL);

    // The count stepper's minus over the empty trailing child: a bare
    // remove_element, no style write rides along.
    $this->batch($entity, [
      ['op' => 'remove_element', 'element' => $third],
    ]);
    $this->assertSame([$first, $second], $this->childUuids($entity, $section_uuid));
  }

  /**
   * The folded-in alignment writes: stack upgrade, unset, columns flip.
   */
  public function testAlignmentBatches(): void {
    $entity = EntityTest::create(['name' => 'host']);
    $entity->save();

    $uuid_service = $this->container->get('uuid');
    $section_uuid = $uuid_service->generate();
    $key = "container:$section_uuid";
    // A root band is born stacked (no arrangement reference at birth);
    // the alignment arc below is the stack story.
    $this->batch($entity, [
      [
        'op' => 'add_section',
        'uuid' => $section_uuid,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);

    // An alignment pick on a plain stack is ONE plain override on the
    // container ROOT (the one box that lays out the children at every
    // depth): the kind's declaration already makes the box a flex
    // column, so the pick carries no display of its own. Until this
    // pick the container cost nothing.
    $this->batch($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['align-items' => 'center']],
    ]);
    $this->assertSame(
      ['align-items' => 'center'],
      $this->styleMap($entity)[$key]['default'] ?? NULL,
    );

    // The second axis (justify-content, along the column) writes alone;
    // a stack-mode gap tick is the same ordinary root override.
    $this->batch($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['justify-content' => 'center']],
      ['op' => 'update_style', 'key' => $key, 'properties' => ['gap' => '8px']],
    ]);
    $this->assertSame(
      ['align-items' => 'center', 'justify-content' => 'center', 'gap' => '8px'],
      $this->styleMap($entity)[$key]['default'] ?? NULL,
    );

    // Unsetting an axis clears only its own property (unset is a local
    // statement and never re-arranges), and the container still reads
    // as an aligned stack.
    $this->batch($entity, [
      ['op' => 'update_style', 'key' => $key, 'properties' => ['align-items' => NULL]],
    ]);
    $this->assertSame(
      ['justify-content' => 'center', 'gap' => '8px'],
      $this->styleMap($entity)[$key]['default'] ?? NULL,
    );

    // Columns over an aligned stack: the flip is the variant apply alone
    // (nothing to clear, the widget wrote no display) while the stack's
    // alignment stays inert, exactly like grows stay inert under Stack;
    // the row cross-axis is then a plain align-items override on the
    // same key.
    $this->batch($entity, [
      ['op' => 'apply_variant', 'key' => $key, 'variant' => 'columns'],
      ['op' => 'update_style', 'key' => $key, 'properties' => ['align-items' => 'center']],
    ]);
    $map = $this->styleMap($entity);
    $this->assertSame(
      ['justify-content' => 'center', 'gap' => '8px', 'align-items' => 'center'],
      $map[$key]['default'] ?? NULL,
    );
    $this->assertContains(['variant' => 'columns', 'fragment' => ''], $map[$key]['variants'] ?? []);
  }

  /**
   * The remove_variant guards its parameters like detach does.
   */
  public function testRemoveVariantErrors(): void {
    $entity = EntityTest::create(['name' => 'host']);
    $entity->save();

    $section_uuid = $this->container->get('uuid')->generate();
    $key = "container:$section_uuid";
    $this->batch($entity, [
      [
        'op' => 'add_section',
        'uuid' => $section_uuid,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);

    // Removing a variant the entry does not wear fails loudly (the band
    // is born wearing container_site and row; container_sm it is not).
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'remove_variant', 'key' => $key, 'variant' => 'container_sm'],
    ]);
    $this->assertFalse($result->isSuccess());
    $errors = $result->toArray()['errors'] ?? [];
    $this->assertSame('STYLE_VARIANT_NOT_REFERENCED', $errors[0]['code'] ?? NULL, print_r($errors, TRUE));

    // A missing variant parameter is structural.
    $result = $this->operations->batchOperations($entity, [
      ['op' => 'remove_variant', 'key' => $key],
    ]);
    $this->assertFalse($result->isSuccess());
    $errors = $result->toArray()['errors'] ?? [];
    $this->assertSame('MISSING_PARAMETER', $errors[0]['code'] ?? NULL, print_r($errors, TRUE));
  }

}
