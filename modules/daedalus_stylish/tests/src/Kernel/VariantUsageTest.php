<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Database\Statement\FetchAs;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestRev;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\VariantUsage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the daedalus_variant_usage table and its save chokepoint.
 *
 * The table replaces content parsing (the retired LIKE scan and
 * load-everything walk): host entity saves and deletes maintain it through
 * the daedalus_stylish_entity_insert()/update()/delete() hooks, default revisions
 * only, and rebuild() reproduces exactly what incremental maintenance
 * wrote (rebuild parity).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantUsageTest extends KernelTestBase {

  use UserCreationTrait;

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
    'daedalus_stylish_guide_test',
  ];

  /**
   * The usage tracker under test.
   */
  protected VariantUsage $usage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('entity_test_rev');
    $this->installEntitySchema('daedalus_variant');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->setUpCurrentUser();
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test_rev', 'entity_test_rev');
    $this->usage = $this->container->get('daedalus_stylish.variant_usage');
  }

  /**
   * Creates a saved variant to count against.
   */
  protected function createVariant(string $label, string $machine_name): DaedalusStylishVariantInterface {
    $variant = DaedalusStylishVariant::create(['label' => $label, 'machine_name' => $machine_name, 'scope' => 'kind:block']);
    $variant->setStyles(['' => ['default' => ['padding' => '1rem']]]);
    $variant->save();
    return $variant;
  }

  /**
   * A style map entry wearing the given references.
   */
  protected function wearing(string ...$references): array {
    return [
      'variants' => array_map(
      static fn (string $reference): array => ['variant' => $reference, 'fragment' => ''],
      $references,
      ),
    ];
  }

  /**
   * All usage table rows, ordered, for parity comparisons.
   */
  protected function allRows(): array {
    return $this->container->get('database')->select(VariantUsage::TABLE, 'u')
      ->fields('u', ['variant', 'entity_type', 'entity_id'])
      ->orderBy('u.variant')
      ->orderBy('u.entity_type')
      ->orderBy('u.entity_id')
      ->execute()
      ->fetchAll(FetchAs::Associative);
  }

  /**
   * The chokepoint: host saves, updates, and deletes maintain the table.
   */
  public function testChokepointTracking(): void {
    $variant = $this->createVariant('Card', 'card');
    $uuid = fn (): string => $this->container->get('uuid')->generate();

    // Two entries on one entity: one wearer, not two.
    $double = EntityTest::create([
      'name' => 'double',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        'block:' . $uuid() => $this->wearing('card'),
        'block:' . $uuid() => $this->wearing('card'),
      ]),
    ]);
    $double->save();
    $this->assertSame(1, $this->usage->countWearers($variant));

    // A wearer by uuid counts too.
    $by_uuid = EntityTest::create([
      'name' => 'by-uuid',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode(['block:' . $uuid() => $this->wearing($variant->uuid())]),
    ]);
    $by_uuid->save();
    $this->assertSame(2, $this->usage->countWearers($variant));

    // Both reference forms on ONE entity still count once (the DISTINCT
    // wearer collapse).
    $both_forms = EntityTest::create([
      'name' => 'both-forms',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        'block:' . $uuid() => $this->wearing('card'),
        'block:' . $uuid() => $this->wearing($variant->uuid()),
      ]),
    ]);
    $both_forms->save();
    $this->assertSame(3, $this->usage->countWearers($variant));

    // A lookalike mentioning the name inside a css value is no ghost: the
    // structured reference read feeds the table, not string matching.
    EntityTest::create([
      'name' => 'no-use',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode(['block:' . $uuid() => ['default' => ['background-image' => 'url("/card.png")']]]),
    ])->save();
    $this->assertSame(3, $this->usage->countWearers($variant));

    // The work list names every wearer once, ordered.
    $this->assertSame([
      ['entity_type' => 'entity_test', 'entity_id' => (string) $double->id()],
      ['entity_type' => 'entity_test', 'entity_id' => (string) $by_uuid->id()],
      ['entity_type' => 'entity_test', 'entity_id' => (string) $both_forms->id()],
    ], $this->usage->wearers($variant));

    // Removing the reference on save drops the rows.
    $by_uuid->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode(['block:' . $uuid() => ['default' => ['padding' => '2rem']]]));
    $by_uuid->save();
    $this->assertSame(2, $this->usage->countWearers($variant));

    // Deleting a host drops its rows.
    $double->delete();
    $both_forms->delete();
    $this->assertSame(0, $this->usage->countWearers($variant));
    $this->assertSame([], $this->usage->wearers($variant));

    // An unsaved reference universe counts zero, never errors.
    $unworn = $this->createVariant('Unworn', 'unworn');
    $this->assertSame(0, $this->usage->countWearers($unworn));
  }

  /**
   * Only default revisions are tracked: staging never moves the counts.
   */
  public function testDefaultRevisionsOnly(): void {
    $variant = $this->createVariant('Card', 'card');
    $key = 'block:' . $this->container->get('uuid')->generate();

    $host = EntityTestRev::create([
      'name' => 'host',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([$key => $this->wearing('card')]),
    ]);
    $host->save();
    $this->assertSame(1, $this->usage->countWearers($variant));

    // A pending (non-default) revision dropping the reference changes
    // nothing: the table mirrors the default revision the compiled
    // stylesheet serves.
    $host->set(DaedalusStylishFieldManager::FIELD_NAME, NULL);
    $host->setNewRevision(TRUE);
    $host->isDefaultRevision(FALSE);
    $host->save();
    $this->assertSame(1, $this->usage->countWearers($variant));

    // A new DEFAULT revision dropping the reference untracks it.
    $default = EntityTestRev::load($host->id());
    $default->set(DaedalusStylishFieldManager::FIELD_NAME, NULL);
    $default->setNewRevision(TRUE);
    $default->save();
    $this->assertSame(0, $this->usage->countWearers($variant));
  }

  /**
   * Rebuild parity: rebuild() reproduces incremental maintenance exactly.
   */
  public function testRebuildParity(): void {
    $variant = $this->createVariant('Card', 'card');
    $other = $this->createVariant('Hero', 'hero');
    $uuid = fn (): string => $this->container->get('uuid')->generate();

    EntityTest::create([
      'name' => 'a',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode([
        'block:' . $uuid() => $this->wearing('card', 'hero'),
      ]),
    ])->save();
    EntityTest::create([
      'name' => 'b',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode(['block:' . $uuid() => $this->wearing($variant->uuid())]),
    ])->save();
    EntityTestRev::create([
      'name' => 'c',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode(['block:' . $uuid() => $this->wearing('hero')]),
    ])->save();
    // A styled host with no references contributes no rows.
    EntityTest::create([
      'name' => 'unref',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode(['block:' . $uuid() => ['default' => ['padding' => '2rem']]]),
    ])->save();

    $maintained = $this->allRows();
    $this->assertCount(4, $maintained);

    // Corrupt the table both ways: a lost row and a ghost row.
    $database = $this->container->get('database');
    $database->delete(VariantUsage::TABLE)->condition('variant', 'hero')->condition('entity_type', 'entity_test')->execute();
    $database->insert(VariantUsage::TABLE)->fields([
      'variant' => 'ghost',
      'entity_type' => 'entity_test',
      'entity_id' => '999999',
    ])->execute();
    $this->assertNotSame($maintained, $this->allRows());

    // Rebuild restores exactly what the chokepoint had written.
    $this->assertSame(3, $this->usage->rebuild());
    $this->assertSame($maintained, $this->allRows());
    $this->assertSame(2, $this->usage->countWearers($variant));
    $this->assertSame(2, $this->usage->countWearers($other));
  }

  /**
   * A template display is a host: its map is tracked, untracked and rebuilt.
   *
   * The display's map is its third-party setting; the same chokepoint
   * (the entity save hooks) and the same rebuild enumerate it beside the
   * content hosts, keyed by the config id.
   */
  public function testDisplayHostTracking(): void {
    $variant = $this->createVariant('Card', 'card');
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->save();
    $this->assertSame(0, $this->usage->countWearers($variant));

    $section_uuid = $this->container->get('uuid')->generate();
    $host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display);
    $host->setStyleMap(["container:$section_uuid" => $this->wearing('card')]);
    $display->save();
    $this->assertSame(1, $this->usage->countWearers($variant));
    $this->assertSame([
      ['entity_type' => 'entity_view_display', 'entity_id' => $display->id()],
    ], $this->usage->wearers($variant));

    // A content host beside it: two wearers, two stores.
    $entity = EntityTest::create([
      'name' => 'page',
      DaedalusStylishFieldManager::FIELD_NAME => json_encode(['block:' . $this->container->get('uuid')->generate() => $this->wearing('card')]),
    ]);
    $entity->save();
    $this->assertSame(2, $this->usage->countWearers($variant));

    // Rebuild parity over both stores.
    $maintained = $this->allRows();
    $this->container->get('database')->delete(VariantUsage::TABLE)->condition('entity_type', 'entity_view_display')->execute();
    $this->assertSame(1, $this->usage->countWearers($variant));
    $this->assertSame(2, $this->usage->rebuild());
    $this->assertSame($maintained, $this->allRows());

    // Dropping the template's reference on save untracks it.
    $host->setStyleMap([]);
    $display->save();
    $this->assertSame(1, $this->usage->countWearers($variant));

    // Deleting a wearing display drops its rows.
    $host->setStyleMap(["container:$section_uuid" => $this->wearing('card')]);
    $display->save();
    $this->assertSame(2, $this->usage->countWearers($variant));
    $display->delete();
    $this->assertSame(1, $this->usage->countWearers($variant));
    $this->assertSame([
      ['entity_type' => 'entity_test', 'entity_id' => (string) $entity->id()],
    ], $this->usage->wearers($variant));
  }

}
