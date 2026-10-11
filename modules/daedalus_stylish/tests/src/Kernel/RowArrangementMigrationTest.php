<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish_layouts\ContainerMigration;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the proportions retirement (mode + share) over real captured data.
 *
 * The primary fixture is a site recipe front-page hero exactly as it
 * shipped under the proportions model (container_lg + split_50_50 worn on
 * the root band) - real captured data, not a synthetic shape. Around it:
 * unequal ratios becoming child flex-grow overrides in child order,
 * stepper-written explicit track overrides parsing by the stepper's own
 * minmax pattern (and shadowing a worn reference when both exist), track
 * styles outside that pattern staying Custom LOUDLY, the split_* entity
 * delete pass (the migrate_0006 precedent), the proportions axis moving
 * off surviving variants, and idempotence.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class RowArrangementMigrationTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    'daedalus_stylish_layouts',
    'file',
    'image',
    'media',
  ];

  /**
   * The daedalus_layout uuid of the captured front-page hero band.
   */
  protected const HERO_UUID = '09868d9b-a855-40f8-96fe-8afc921f885c';

  /**
   * The daedalus_layout uuid of the hero's nested text container.
   */
  protected const HERO_TEXT_UUID = '39b60dbf-71f2-4d9d-bc85-a02b6a1beedc';

  /**
   * The hero's image block component uuid.
   */
  protected const HERO_IMAGE_UUID = '13002d2e-c227-4dc7-9ff4-f3a33f22aa73';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * A site recipe front-page hero, as shipped pre-mode-+ share.
   *
   * Captured from a real shipped front-page node export before this
   * migration rewrote the recipe: one container band holding a nested text
   * container and an image block, side by side via split_50_50.
   */
  protected function capturedHeroSection(): Section {
    return Section::fromArray([
      'layout_id' => 'daedalus_stylish_container',
      'layout_settings' => ['label' => ''],
      'components' => [
        self::HERO_TEXT_UUID => [
          'uuid' => self::HERO_TEXT_UUID,
          'region' => 'content',
          'configuration' => [
            'id' => 'daedalus_layout_section',
            'label_display' => '0',
            'section' => [
              'layout_id' => 'daedalus_stylish_container',
              'layout_settings' => ['label' => ''],
              'components' => [
                'a26bd97e-3616-443c-9593-1cefb23d0433' => [
                  'uuid' => 'a26bd97e-3616-443c-9593-1cefb23d0433',
                  'region' => 'content',
                  'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Greeting', 'label_display' => '0'],
                  'weight' => 0,
                  'additional' => [],
                ],
              ],
              'third_party_settings' => [
                'daedalus_layout' => ['uuid' => self::HERO_TEXT_UUID],
              ],
            ],
          ],
          'weight' => 0,
          'additional' => [],
        ],
        self::HERO_IMAGE_UUID => [
          'uuid' => self::HERO_IMAGE_UUID,
          'region' => 'content',
          'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Image', 'label_display' => '0'],
          'weight' => 1,
          'additional' => [],
        ],
      ],
      'third_party_settings' => [
        'daedalus_layout' => ['uuid' => self::HERO_UUID],
      ],
    ]);
  }

  /**
   * The front-page style map, as shipped pre-mode + share.
   */
  protected function capturedHeroMap(): array {
    return [
      'container:' . self::HERO_UUID => [
        'variants' => [
          ['variant' => 'container_lg', 'fragment' => ''],
          ['variant' => 'split_50_50', 'fragment' => ''],
        ],
      ],
      'block:' . self::HERO_IMAGE_UUID => [
        'default' => ['width' => '50%', 'justify-self' => 'center'],
      ],
      'block:a26bd97e-3616-443c-9593-1cefb23d0433' => [
        'default' => ['align-self' => 'center'],
      ],
      'container:' . self::HERO_TEXT_UUID => [
        'default' => ['align-content' => 'center'],
      ],
    ];
  }

  /**
   * Persists an entity carrying pre-migration data.
   *
   * A syncing save bypasses the presave safety net, which is exactly how
   * pre-migration data exists on a site the update hooks have not run on
   * yet (and how default content imports land).
   */
  protected function createLegacyEntity(array $sections, array $map = []): EntityTest {
    $entity = EntityTest::create([
      OverridesSectionStorage::FIELD_NAME => $sections,
      DaedalusStylishFieldManager::FIELD_NAME => $map === [] ? NULL : json_encode($map, JSON_UNESCAPED_SLASHES),
    ]);
    $entity->setSyncing(TRUE);
    $entity->save();
    return $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
  }

  /**
   * Runs the host entity walker to completion.
   */
  protected function walkHosts(): void {
    $walker = $this->container->get('daedalus_layout.host_entity_walker');
    $sandbox = [];
    do {
      $walker->walk($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);
    $this->entityTypeManager->getStorage('entity_test')->resetCache();
  }

  /**
   * Reads an entity's style map.
   */
  protected function styleMap(EntityTest $entity): array {
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * A container section with the given ordered child components.
   *
   * @param string $uuid
   *   The section's uuid.
   * @param array $children
   *   Each 'container' => nested section uuid, or 'block' => component uuid.
   */
  protected function containerSection(string $uuid, array $children = []): Section {
    $section = $this->createSection('daedalus_stylish_container', [], $uuid);
    $weight = 0;
    foreach ($children as $child) {
      $component = isset($child['container'])
        ? SectionBlock::createComponent($this->createSection('daedalus_stylish_container', [], $child['container']), 'content')
        : new SectionComponent($child['block'], 'content', [
          'id' => 'system_powered_by_block',
          'label' => 'B',
          'label_display' => '0',
        ]);
      $section->appendComponent($component);
      $component->setWeight($weight++);
    }
    return $section;
  }

  /**
   * The captured front-page hero converges to mode + share, idempotently.
   */
  public function testCapturedHeroConverts(): void {
    $entity = $this->createLegacyEntity([$this->capturedHeroSection()], $this->capturedHeroMap());
    $this->walkHosts();
    $entity = EntityTest::load($entity->id());
    $map = $this->styleMap($entity);

    // The worn split_50_50 became the one row reference beside the width
    // reference; equal ratio, so no child grows appear anywhere.
    $this->assertSame([
      'variants' => [
        ['variant' => 'container_lg', 'fragment' => ''],
        ['variant' => 'columns', 'fragment' => ''],
      ],
    ], $map['container:' . self::HERO_UUID]);
    $this->assertSame(['default' => ['align-content' => 'center']], $map['container:' . self::HERO_TEXT_UUID]);
    $this->assertSame(['default' => ['width' => '50%', 'justify-self' => 'center']], $map['block:' . self::HERO_IMAGE_UUID]);

    // Idempotence: a second walk rewrites nothing.
    $before = json_encode($map);
    $sections_before = array_map(static fn (Section $section) => $section->toArray(), $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections());
    $this->walkHosts();
    $entity = EntityTest::load($entity->id());
    $this->assertSame($before, json_encode($this->styleMap($entity)));
    $this->assertSame($sections_before, array_map(static fn (Section $section) => $section->toArray(), $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections()));
  }

  /**
   * Unequal ratios become flex-grow on the children, in child order.
   */
  public function testUnequalSplitWritesChildGrows(): void {
    $wide = $this->uuid->generate();
    $narrow = $this->uuid->generate();
    $section_uuid = $this->uuid->generate();
    $section = $this->containerSection($section_uuid, [
      ['container' => $wide],
      ['block' => $narrow],
    ]);
    $middle_uuid = $this->uuid->generate();
    $blocks = [$this->uuid->generate(), $this->uuid->generate(), $this->uuid->generate()];
    $middle = $this->containerSection($middle_uuid, [
      ['block' => $blocks[0]],
      ['block' => $blocks[1]],
      ['block' => $blocks[2]],
    ]);
    $entity = $this->createLegacyEntity([$section, $middle], [
      "container:$section_uuid" => [
        'variants' => [['variant' => 'split_67_33', 'fragment' => '']],
      ],
      "container:$middle_uuid" => [
        'variants' => [['variant' => 'split_25_50_25', 'fragment' => '']],
      ],
    ]);
    $this->walkHosts();
    $map = $this->styleMap(EntityTest::load($entity->id()));

    // 67/33 is the frozen 2:1 - the wider child carries grow 2, the equal
    // child stays unwritten (weight 1 is spelled by NOT writing).
    $this->assertSame(['variants' => [['variant' => 'columns', 'fragment' => '']]], $map["container:$section_uuid"]);
    $this->assertSame(['default' => ['flex-grow' => '2']], $map["container:$wide"]);
    $this->assertArrayNotHasKey("block:$narrow", $map);

    // 25/50/25: only the middle child deviates from equal.
    $this->assertSame(['variants' => [['variant' => 'columns', 'fragment' => '']]], $map["container:$middle_uuid"]);
    $this->assertArrayNotHasKey("block:{$blocks[0]}", $map);
    $this->assertSame(['default' => ['flex-grow' => '2']], $map["block:{$blocks[1]}"]);
    $this->assertArrayNotHasKey("block:{$blocks[2]}", $map);
  }

  /**
   * Stepper-written tracks parse; the override shadows a worn reference.
   */
  public function testStepperTracksConvert(): void {
    // The captured footer part shape: equal stepper
    // tracks sharing the entry with hand-set width properties.
    $footer_uuid = $this->uuid->generate();
    $children = [$this->uuid->generate(), $this->uuid->generate()];
    $footer = $this->containerSection($footer_uuid, [
      ['container' => $children[0]],
      ['container' => $children[1]],
    ]);
    // A stepper override that shadowed a worn split_50_50: the override's
    // 2:1 ratio is the effective truth and wins the conversion.
    $shadowed_uuid = $this->uuid->generate();
    $shadowed_children = [$this->uuid->generate(), $this->uuid->generate()];
    $shadowed = $this->containerSection($shadowed_uuid, [
      ['block' => $shadowed_children[0]],
      ['block' => $shadowed_children[1]],
    ]);
    // The stepper's stacked spelling: stepping down to one column wrote
    // 'none' (also overriding a worn variant) - a plain stacked flow now.
    $stacked_uuid = $this->uuid->generate();
    $stacked = $this->containerSection($stacked_uuid, [['block' => $this->uuid->generate()]]);
    $entity = $this->createLegacyEntity([$footer, $shadowed, $stacked], [
      "container:$footer_uuid" . ContainerMigration::CONTAINER_PART => [
        'default' => [
          'display' => 'grid',
          'grid-template-columns' => 'minmax(0, 1fr) minmax(0, 1fr)',
          'width' => '100%',
          'max-width' => '80rem',
          'margin-left' => 'auto',
          'margin-right' => 'auto',
        ],
        'narrow' => ['grid-template-columns' => 'none'],
      ],
      "container:$shadowed_uuid" => [
        'variants' => [['variant' => 'split_50_50', 'fragment' => '']],
      ],
      "container:$shadowed_uuid" . ContainerMigration::CONTAINER_PART => [
        'default' => [
          'display' => 'grid',
          'grid-template-columns' => 'minmax(0, 2fr) minmax(0, 1fr)',
        ],
        'narrow' => ['grid-template-columns' => 'none'],
      ],
      "container:$stacked_uuid" . ContainerMigration::CONTAINER_PART => [
        'default' => ['display' => 'grid', 'grid-template-columns' => 'none'],
      ],
    ]);
    $this->walkHosts();
    $map = $this->styleMap(EntityTest::load($entity->id()));

    // The footer: tracks stripped, the emptied narrow block dropped, row
    // worn, equal ratio writes no grows; the part-hoist sweep runs after
    // the row sweep, so the surviving width properties land on the ROOT
    // key (the container is one box).
    $this->assertSame([
      'variants' => [['variant' => 'columns', 'fragment' => '']],
      'default' => [
        'width' => '100%',
        'max-width' => '80rem',
        'margin-left' => 'auto',
        'margin-right' => 'auto',
      ],
    ], $map["container:$footer_uuid"]);
    $this->assertArrayNotHasKey("container:$footer_uuid" . ContainerMigration::CONTAINER_PART, $map);
    $this->assertArrayNotHasKey("container:{$children[0]}", $map);

    // The shadowed container: the override's 2:1 wins, the retiring
    // reference drops, the part entry empties away entirely.
    $this->assertSame(['variants' => [['variant' => 'columns', 'fragment' => '']]], $map["container:$shadowed_uuid"]);
    $this->assertArrayNotHasKey("container:$shadowed_uuid" . ContainerMigration::CONTAINER_PART, $map);
    $this->assertSame(['default' => ['flex-grow' => '2']], $map["block:{$shadowed_children[0]}"]);
    $this->assertArrayNotHasKey("block:{$shadowed_children[1]}", $map);

    // The stacked spelling converges to nothing: no styles, no row.
    $this->assertArrayNotHasKey("container:$stacked_uuid" . ContainerMigration::CONTAINER_PART, $map);
    $this->assertArrayNotHasKey("container:$stacked_uuid", $map);
  }

  /**
   * Tracks outside the stepper's own pattern stay Custom, loudly.
   */
  public function testCustomTracksStayLoudly(): void {
    $custom_uuid = $this->uuid->generate();
    $worn_uuid = $this->uuid->generate();
    $map = [
      // Hand-written grid (code view): not the stepper's pattern.
      "container:$custom_uuid" . ContainerMigration::CONTAINER_PART => [
        'default' => ['display' => 'grid', 'grid-template-columns' => '1fr 300px'],
      ],
      // The same, shadowing a worn retiring reference: the reference
      // retires either way, its frozen grid styles materialize underneath
      // (per property - present values win), and the element stays Custom.
      "container:$worn_uuid" => [
        'variants' => [['variant' => 'split_50_50', 'fragment' => '']],
      ],
      "container:$worn_uuid" . ContainerMigration::CONTAINER_PART => [
        'default' => ['display' => 'grid', 'grid-template-columns' => 'repeat(2, minmax(0, 1fr))'],
      ],
    ];
    $lines = [];
    $migration = $this->container->get('daedalus_stylish_layouts.container_migration');
    $migrated = $migration->migrateArrangement($map, [], 'test', $lines);

    $this->assertNotNull($migrated);
    // The purely-custom container: untouched, one loud line.
    $this->assertSame($map["container:$custom_uuid" . ContainerMigration::CONTAINER_PART], $migrated["container:$custom_uuid" . ContainerMigration::CONTAINER_PART]);
    $this->assertArrayNotHasKey("container:$custom_uuid", $migrated);
    // repeat() is not a stepper spelling (the stepper always expanded),
    // so the worn container materializes and stays Custom: the override
    // keeps its values, the variant's narrow stacking lands underneath,
    // and the reference is gone.
    $this->assertSame([
      'default' => ['display' => 'grid', 'grid-template-columns' => 'repeat(2, minmax(0, 1fr))'],
      'narrow' => ['grid-template-columns' => 'none'],
    ], $migrated["container:$worn_uuid" . ContainerMigration::CONTAINER_PART]);
    $this->assertArrayNotHasKey('variants', $migrated["container:$worn_uuid"] ?? []);
    $loud = array_filter($lines, static fn (string $line) => str_contains($line, 'Custom'));
    $this->assertCount(2, $loud, print_r($lines, TRUE));

    // Idempotence on the data: a second pass changes nothing (the Custom
    // observation may repeat, but no rewrite happens).
    $lines = [];
    $this->assertNull($migration->migrateArrangement($migrated, [], 'test', $lines));
  }

  /**
   * A modified split variant keeps its references and survives.
   */
  public function testModifiedSplitVariantKeepsReference(): void {
    $shipped_styles = [
      ContainerMigration::CONTAINER_PART => [
        'default' => ['display' => 'grid', 'grid-template-columns' => 'minmax(0, 2fr) minmax(0, 1fr)'],
        'narrow' => ['grid-template-columns' => 'none'],
      ],
    ];
    $modified = DaedalusStylishVariant::create([
      'label' => '67/33 (tweaked)',
      'machine_name' => 'split_67_33',
      'scope' => 'kind:container',
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $modified->setStyles([
      ContainerMigration::CONTAINER_PART => [
        'default' => ['display' => 'grid', 'grid-template-columns' => '7fr 3fr', 'gap' => '2rem'],
      ],
    ]);
    $modified->setShippedSource('daedalus_stylish_layouts', $shipped_styles);
    $modified->save();

    $section_uuid = $this->uuid->generate();
    $section = $this->containerSection($section_uuid, [
      ['block' => $this->uuid->generate()],
      ['block' => $this->uuid->generate()],
    ]);
    $entity = $this->createLegacyEntity([$section], [
      "container:$section_uuid" => [
        'variants' => [['variant' => 'split_67_33', 'fragment' => '']],
      ],
    ]);
    $this->walkHosts();
    $map = $this->styleMap(EntityTest::load($entity->id()));
    $this->assertSame([
      'variants' => [['variant' => 'split_67_33', 'fragment' => '']],
    ], $map["container:$section_uuid"], 'A modified variant keeps its references; user tweaks are never replaced by the shipped conversion.');
  }

  /**
   * The delete pass and the axis retirement (the migrate_0006 precedent).
   */
  public function testRetireShippedSplitVariants(): void {
    $styles = [
      ContainerMigration::CONTAINER_PART => [
        'default' => ['display' => 'grid', 'grid-template-columns' => 'minmax(0, 1fr) minmax(0, 1fr)'],
        'narrow' => ['grid-template-columns' => 'none'],
      ],
    ];

    // Shipped and unmodified: retired.
    $shipped = DaedalusStylishVariant::create([
      'label' => '50 / 50',
      'machine_name' => 'split_50_50',
      'scope' => 'kind:container',
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $shipped->setStyles($styles);
    $shipped->setShippedSource('daedalus_stylish_layouts', $styles);
    $shipped->save();

    // Shipped but user-modified: kept as an ordinary variant.
    $modified = DaedalusStylishVariant::create([
      'label' => '67/33 (tweaked)',
      'machine_name' => 'split_67_33',
      'scope' => 'kind:container',
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $modified->setStyles([
      ContainerMigration::CONTAINER_PART => ['default' => ['display' => 'grid', 'grid-template-columns' => '7fr 3fr']],
    ]);
    $modified->setShippedSource('daedalus_stylish_layouts', $styles);
    $modified->save();

    // A user variant reusing a retired name: never deleted.
    $user = DaedalusStylishVariant::create([
      'label' => 'My own split',
      'machine_name' => 'split_25_75',
      'scope' => 'kind:container',
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $user->setStyles($styles);
    $user->save();

    // Real sites saved these variants on the proportions axis while the
    // container declaration still carried it; the axis cannot be saved
    // through the entity API anymore (that is the point), so the stored
    // state is reproduced directly in the base table (the axis is a
    // non-revisionable identity field).
    $this->container->get('database')->update('daedalus_variant')
      ->fields(['axis' => 'proportions'])
      ->condition('id', [$modified->id(), $user->id()], 'IN')
      ->execute();
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();

    $lines = $this->container->get('daedalus_stylish_layouts.container_migration')->retireShippedSplitVariants();
    $this->assertNotEmpty($lines);
    $storage->resetCache();
    $this->assertNull($storage->loadUnchanged($shipped->id()), 'The unmodified shipped variant is deleted.');
    $kept = $storage->loadUnchanged($modified->id());
    $this->assertNotNull($kept, 'The modified shipped variant survives.');
    $this->assertSame('style', $kept->getAxis(), 'The surviving variant moved off the retired proportions axis.');
    $user = $storage->loadUnchanged($user->id());
    $this->assertNotNull($user, 'A user variant reusing a retired name survives.');
    $this->assertSame('style', $user->getAxis());
  }


}
