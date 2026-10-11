<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the container-model migration over real captured data.
 *
 * The primary fixture is a site recipe footer layout block exactly as
 * it shipped under the old model (daedalus_stylish_twocol + daedalus_stylish_onecol
 * sections, section:/mobile style grammar) - real captured data, not a
 * synthetic shape. Around it: region-style relocation onto minted
 * children, variant reference re-pointing (shipped-unmodified only),
 * compound condition rewrites, variant entity migration (scopes, slot
 * paths, conditions).
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ContainerModelMigrationTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    'daedalus_stylish_layouts',
    'file',
    'image',
    'media',
    // Re-declares the retired daedalus_stylish_onecol..fourcol plugins: persisting
    // the captured PRE-migration fixtures needs live plugins because core's
    // Section::toArray() resolves layout_settings through the plugin. Real
    // sites saved this data while the plugins still shipped; the transforms
    // themselves run on ContainerMigration's frozen shapes either way.
    'daedalus_stylish_layouts_retired_test',
  ];

  /**
   * The daedalus_layout uuid of the captured twocol footer section.
   */
  protected const TWOCOL_UUID = 'af23ae09-0a81-4f55-b1bf-344852458a67';

  /**
   * The daedalus_layout uuid of the captured onecol footer section.
   */
  protected const ONECOL_UUID = '2cc835c0-2d01-4686-86d2-8d4872e54a54';

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
   * A site recipe footer's sections, as shipped pre-container-model.
   *
   * Captured from a real shipped footer block_content export before this
   * migration rewrote the recipe.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The two sections.
   */
  protected function capturedFooterSections(): array {
    return [
      Section::fromArray([
        'layout_id' => 'daedalus_stylish_twocol',
        'layout_settings' => ['label' => ''],
        'components' => [
          '77d8924a-fe5d-4333-8b97-ce53cd82e899' => [
            'uuid' => '77d8924a-fe5d-4333-8b97-ce53cd82e899',
            'region' => 'first',
            'configuration' => [
              'id' => 'inline_block:text',
              'label' => 'Footer blurb',
              'label_display' => '0',
              'provider' => 'layout_builder',
              'view_mode' => 'default',
              'block_uuid' => '5c34ce99-8e4e-449f-bd34-a99b231fc636',
            ],
            'weight' => 0,
            'additional' => [],
          ],
          '5a1df62c-caf2-4db0-913f-da3bd84b92ea' => [
            'uuid' => '5a1df62c-caf2-4db0-913f-da3bd84b92ea',
            'region' => 'second',
            'configuration' => [
              'id' => 'inline_block:text',
              'label' => 'Footer contact',
              'label_display' => '0',
              'provider' => 'layout_builder',
              'view_mode' => 'default',
              'block_uuid' => '733df029-d473-4457-bdea-9b19d02b9e61',
            ],
            'weight' => 0,
            'additional' => [],
          ],
        ],
        'third_party_settings' => [
          'daedalus_layout' => ['uuid' => self::TWOCOL_UUID],
        ],
      ]),
      Section::fromArray([
        'layout_id' => 'daedalus_stylish_onecol',
        'layout_settings' => ['label' => ''],
        'components' => [
          '5672f04e-f11d-4daf-b7e0-6d39917d2b6f' => [
            'uuid' => '5672f04e-f11d-4daf-b7e0-6d39917d2b6f',
            'region' => 'first',
            'configuration' => [
              'id' => 'inline_block:text',
              'label' => 'Footer legal',
              'label_display' => '0',
              'provider' => 'layout_builder',
              'view_mode' => 'default',
              'block_uuid' => 'dd68fa64-9970-4942-8923-6b6505b47046',
            ],
            'weight' => 0,
            'additional' => [],
          ],
        ],
        'third_party_settings' => [
          'daedalus_layout' => ['uuid' => self::ONECOL_UUID],
        ],
      ]),
    ];
  }

  /**
   * The footer's style map, as shipped pre-container-model.
   */
  protected function capturedFooterMap(): array {
    return [
      'section:' . self::TWOCOL_UUID . '::.daedalus-stylish-layout-container' => [
        'default' => [
          'display' => 'grid',
          'grid-template-columns' => 'minmax(0, 1fr) minmax(0, 1fr)',
          'width' => '100%',
          'max-width' => '80rem',
          'margin-left' => 'auto',
          'margin-right' => 'auto',
        ],
        'mobile' => ['grid-template-columns' => 'none'],
      ],
      'section:' . self::ONECOL_UUID . '::.daedalus-stylish-layout-container' => [
        'default' => [
          'width' => '100%',
          'max-width' => '80rem',
          'margin-left' => 'auto',
          'margin-right' => 'auto',
        ],
      ],
      'block:77d8924a-fe5d-4333-8b97-ce53cd82e899' => [
        'default' => ['text-align' => 'left'],
        'mobile' => ['text-align' => 'center'],
      ],
      'block:5a1df62c-caf2-4db0-913f-da3bd84b92ea' => [
        'default' => ['text-align' => 'right'],
        'mobile' => ['text-align' => 'center'],
      ],
      'block:5672f04e-f11d-4daf-b7e0-6d39917d2b6f' => [
        'default' => ['font-size' => '0.875rem', 'margin-bottom' => '0px'],
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
   * The captured footer migrates end to end.
   */
  public function testCapturedFooterMigration(): void {
    $entity = $this->createLegacyEntity($this->capturedFooterSections(), $this->capturedFooterMap());
    $this->walkHosts();
    $entity = EntityTest::load($entity->id());
    $sections = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $this->assertCount(2, $sections);

    // The twocol section: a container whose two populated regions became
    // two child containers in region order, one text block each.
    $twocol = $sections[0];
    $this->assertSame('daedalus_stylish_container', $twocol->getLayoutId());
    $this->assertSame(self::TWOCOL_UUID, $twocol->getThirdPartySetting('daedalus_layout', 'uuid'));
    $children = array_values($twocol->getComponents());
    usort($children, static fn ($a, $b) => $a->getWeight() <=> $b->getWeight());
    $this->assertCount(2, $children);
    $embedded_uuids = [];
    foreach ($children as $i => $child) {
      $this->assertSame('content', $child->getRegion());
      $this->assertTrue(SectionBlock::isSectionComponent($child), "Child $i is a container component.");
      $embedded = SectionBlock::embeddedSection($child);
      $this->assertSame('daedalus_stylish_container', $embedded->getLayoutId());
      $embedded_uuids[] = $embedded->getThirdPartySetting('daedalus_layout', 'uuid');
      $this->assertCount(1, $embedded->getComponents());
    }
    $first_child_blocks = array_keys(SectionBlock::embeddedSection($children[0])->getComponents());
    $second_child_blocks = array_keys(SectionBlock::embeddedSection($children[1])->getComponents());
    $this->assertSame(['77d8924a-fe5d-4333-8b97-ce53cd82e899'], $first_child_blocks, 'Region order preserved: first region first.');
    $this->assertSame(['5a1df62c-caf2-4db0-913f-da3bd84b92ea'], $second_child_blocks);
    foreach (SectionBlock::embeddedSection($children[0])->getComponents() as $component) {
      $this->assertSame('content', $component->getRegion());
    }

    // The onecol section: components inline directly, no wrapper level.
    $onecol = $sections[1];
    $this->assertSame('daedalus_stylish_container', $onecol->getLayoutId());
    $this->assertSame(self::ONECOL_UUID, $onecol->getThirdPartySetting('daedalus_layout', 'uuid'));
    $components = $onecol->getComponents();
    $this->assertCount(1, $components);
    $legal = $components['5672f04e-f11d-4daf-b7e0-6d39917d2b6f'];
    $this->assertSame('content', $legal->getRegion());
    $this->assertFalse(SectionBlock::isSectionComponent($legal), 'A single unstyled column inlines without a minted wrapper.');

    // The style map: container: keys, narrow conditions, block keys kept.
    // The row-arrangement sweep runs in the same walk: the stepper-shaped
    // explicit tracks (display grid + equal minmax tracks + narrow 'none')
    // convert to the one row reference and the equal ratio writes no child
    // grows. The part-hoist sweep runs after it, so the surviving width
    // properties land on the ROOT key (the container is one box).
    $map = $this->styleMap($entity);
    $this->assertSame([
      'variants' => [['variant' => 'columns', 'fragment' => '']],
      'default' => [
        'width' => '100%',
        'max-width' => '80rem',
        'margin-left' => 'auto',
        'margin-right' => 'auto',
      ],
    ], $map['container:' . self::TWOCOL_UUID]);
    foreach ($embedded_uuids as $embedded_uuid) {
      $this->assertArrayNotHasKey("container:$embedded_uuid", $map, 'Equal columns write no child grows.');
    }
    $this->assertSame([
      'default' => ['text-align' => 'left'],
      'narrow' => ['text-align' => 'center'],
    ], $map['block:77d8924a-fe5d-4333-8b97-ce53cd82e899']);
    $this->assertSame([
      'default' => [
        'width' => '100%',
        'max-width' => '80rem',
        'margin-left' => 'auto',
        'margin-right' => 'auto',
      ],
    ], $map['container:' . self::ONECOL_UUID]);
    foreach (array_keys($map) as $key) {
      $this->assertStringStartsNotWith('section:', $key);
      $this->assertStringStartsNotWith('region:', $key);
      $this->assertStringNotContainsString('.daedalus-stylish-layout-container', $key);
    }
    $this->assertStringNotContainsString('"mobile"', json_encode($map));
  }

  /**
   * Region styles move onto the minted child; shipped refs re-point.
   */
  public function testRegionStylesAndVariantRefs(): void {
    // twocol_lg ships unmodified: references re-point to container_lg.
    $shipped = DaedalusStylishVariant::create([
      'label' => 'Two column LG',
      'machine_name' => 'twocol_lg',
      'scope' => 'layout:daedalus_stylish_twocol',
      'axis' => 'width',
      'status' => TRUE,
    ]);
    $styles = ['::.daedalus-stylish-layout-container' => ['default' => ['max-width' => '80rem']]];
    $shipped->setStyles($styles);
    $shipped->setShippedSource('daedalus_stylish_layouts', $styles);
    $shipped->save();

    // twocol_50_50 is user-MODIFIED: its references stay and the variant
    // itself migrates (scope, conditions).
    $modified = DaedalusStylishVariant::create([
      'label' => 'Fifty fifty (tweaked)',
      'machine_name' => 'twocol_50_50',
      'scope' => 'layout:daedalus_stylish_twocol',
      // The historical axis was 'proportions'; that axis is off the
      // container declaration now, so the fixture saves on the built-in
      // axis (the axis is irrelevant to what this test pins).
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $modified->setStyles([
      '::.daedalus-stylish-layout-container' => [
        'default' => ['display' => 'grid', 'grid-template-columns' => '3fr 2fr'],
        'mobile' => ['grid-template-columns' => 'none'],
      ],
    ]);
    $modified->setShippedSource('daedalus_stylish_layouts', $styles);
    $modified->save();

    $section_uuid = $this->uuid->generate();
    $section = Section::fromArray([
      'layout_id' => 'daedalus_stylish_twocol',
      'layout_settings' => ['label' => 'Hero'],
      'components' => [
        '11111111-0000-0000-0000-000000000001' => [
          'uuid' => '11111111-0000-0000-0000-000000000001',
          'region' => 'second',
          'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Power', 'label_display' => '0'],
          'weight' => 0,
          'additional' => [],
        ],
      ],
      'third_party_settings' => ['daedalus_layout' => ['uuid' => $section_uuid]],
    ]);
    $map = [
      "section:$section_uuid" => [
        'variants' => [
          ['variant' => 'twocol_50_50', 'fragment' => ''],
          ['variant' => 'twocol_lg', 'fragment' => ''],
        ],
        'default' => ['padding-top' => '3rem'],
      ],
      "region:$section_uuid:first" => ['default' => ['display' => 'flex'], 'tablet' => ['padding' => '1rem']],
      "region:$section_uuid:first::.cap" => ['default' => ['color' => 'red']],
    ];
    $entity = $this->createLegacyEntity([$section], $map);
    $this->walkHosts();
    $entity = EntityTest::load($entity->id());
    $migrated = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
    $this->assertSame('daedalus_stylish_container', $migrated->getLayoutId());
    $this->assertSame('Hero', $migrated->getLayoutSettings()['label']);

    // Two children: region first minted for its STYLES (empty of content),
    // region second for its block - region order preserved.
    $children = array_values($migrated->getComponents());
    usort($children, static fn ($a, $b) => $a->getWeight() <=> $b->getWeight());
    $this->assertCount(2, $children);
    $first_child = SectionBlock::embeddedSection($children[0]);
    $this->assertCount(0, $first_child->getComponents(), 'The styled-but-empty region minted an empty child.');
    $second_child = SectionBlock::embeddedSection($children[1]);
    $this->assertSame(['11111111-0000-0000-0000-000000000001'], array_keys($second_child->getComponents()));

    $map = $this->styleMap($entity);
    $first_uuid = $first_child->getThirdPartySetting('daedalus_layout', 'uuid');
    $this->assertSame(['default' => ['display' => 'flex'], 'narrow' => ['padding' => '1rem']], $map["container:$first_uuid"], 'Region styles moved to the minted child, tablet rewritten to narrow.');
    $this->assertSame(['default' => ['color' => 'red']], $map["container:$first_uuid::.cap"], 'Region part styles ride along.');
    $this->assertSame([
      ['variant' => 'twocol_50_50', 'fragment' => ''],
      ['variant' => 'container_lg', 'fragment' => ''],
    ], $map["container:$section_uuid"]['variants'], 'Unmodified shipped refs re-point; modified ones stay.');

    // The variant entities: shipped-unmodified untouched, modified
    // migrated to the container grammar.
    $lines = $this->container->get('daedalus_stylish_layouts.container_migration')->migrateVariants();
    $this->assertNotEmpty($lines);
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();
    $shipped = $storage->loadUnchanged($shipped->id());
    $this->assertSame('layout:daedalus_stylish_twocol', $shipped->getScope(), 'Unmodified shipped variants are left untouched.');
    $modified = $storage->loadUnchanged($modified->id());
    $this->assertSame('kind:container', $modified->getScope());
    $this->assertSame(['grid-template-columns' => 'none'], $modified->getStyles()['::.daedalus-stylish-layout-container']['narrow']);
  }

  /**
   * Nested multi-region sections convert at depth in the same walk.
   */
  public function testNestedSectionsConvertAtDepth(): void {
    $nested_uuid = $this->uuid->generate();
    $nested = Section::fromArray([
      'layout_id' => 'daedalus_stylish_threecol',
      'layout_settings' => [],
      'components' => [
        '22222222-0000-0000-0000-000000000002' => [
          'uuid' => '22222222-0000-0000-0000-000000000002',
          'region' => 'third',
          'configuration' => ['id' => 'system_powered_by_block', 'label' => 'P', 'label_display' => '0'],
          'weight' => 0,
          'additional' => [],
        ],
      ],
      'third_party_settings' => ['daedalus_layout' => ['uuid' => $nested_uuid]],
    ]);
    $outer = $this->createSection('daedalus_stylish_onecol', [
      SectionBlock::createComponent($nested, 'first'),
    ]);
    $entity = $this->createLegacyEntity([$outer]);
    $this->walkHosts();
    $entity = EntityTest::load($entity->id());
    $migrated = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0];
    $this->assertSame('daedalus_stylish_container', $migrated->getLayoutId());

    // The onecol inlined its one child (the nested section), which itself
    // converted: threecol -> container with ONE minted child (only the
    // third region was populated; empty regions mint nothing).
    $components = array_values($migrated->getComponents());
    $this->assertCount(1, $components);
    $inner = SectionBlock::embeddedSection($components[0]);
    $this->assertSame('daedalus_stylish_container', $inner->getLayoutId());
    $this->assertSame($nested_uuid, $inner->getThirdPartySetting('daedalus_layout', 'uuid'));
    $inner_children = array_values($inner->getComponents());
    $this->assertCount(1, $inner_children);
    $minted = SectionBlock::embeddedSection($inner_children[0]);
    $this->assertSame(['22222222-0000-0000-0000-000000000002'], array_keys($minted->getComponents()));
  }

  /**
   * The map sweep: compounds, merges, strays - map-wide.
   */
  public function testGrammarSweep(): void {
    $section = $this->createSection('daedalus_stylish_container');
    $section_uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $orphan = '99999999-0000-0000-0000-000000000009';
    $map = [
      // A container section styled under the old key spelling.
      "section:$section_uuid" => [
        'mobile+dark' => ['color' => 'white'],
      ],
      // A region key with no element left: dropped loudly.
      "region:$orphan:top" => ['default' => ['display' => 'flex']],
      // Colliding stops on a block entry: mobile wins over tablet wins
      // over the pre-existing narrow, per property.
      'block:' . $orphan => [
        'narrow' => ['color' => 'red', 'margin' => '0'],
        'tablet' => ['color' => 'blue', 'padding' => '1px'],
        'mobile' => ['color' => 'green'],
      ],
    ];
    $entity = $this->createLegacyEntity([$section], $map);
    $this->walkHosts();
    $map = $this->styleMap(EntityTest::load($entity->id()));

    $this->assertSame(['narrow+dark' => ['color' => 'white']], $map["container:$section_uuid"], 'Compound parts rewrite and re-canonicalize.');
    $this->assertArrayNotHasKey("region:$orphan:top", $map);
    $this->assertSame([
      'narrow' => ['color' => 'green', 'padding' => '1px', 'margin' => '0'],
    ], $map['block:' . $orphan]);
  }

  /**
   * Variant entities: slot paths and conditions migrate across revisions.
   */
  public function testVariantSlotPathMigration(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Composed hero',
      'machine_name' => 'composed_hero',
      'scope' => 'layout:daedalus_stylish_twocol',
      'status' => TRUE,
    ]);
    $variant->setStyles([
      '' => ['default' => ['padding' => '2rem'], 'mobile' => ['padding' => '1rem']],
      'region(first)/block(text,2)::.quote' => ['mobile' => ['font-size' => '1rem']],
      'region(second)/section(1)' => ['default' => ['gap' => '1rem']],
    ]);
    $variant->save();

    $this->container->get('daedalus_stylish_layouts.container_migration')->migrateVariants();
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();
    $variant = $storage->loadUnchanged($variant->id());
    $this->assertSame('kind:container', $variant->getScope());
    $this->assertSame([
      '' => ['default' => ['padding' => '2rem'], 'narrow' => ['padding' => '1rem']],
      'container(1)/block(text,2)::.quote' => ['narrow' => ['font-size' => '1rem']],
      'container(2)/container(1)' => ['default' => ['gap' => '1rem']],
    ], $variant->getStyles());
  }

  /**
   * Retirement deletes only unmodified shipped layout-exact variants.
   */
  public function testRetireShippedLayoutVariants(): void {
    $styles = ['::.daedalus-stylish-layout-container' => ['default' => ['max-width' => '80rem']]];

    // Shipped and unmodified: retired.
    $shipped = DaedalusStylishVariant::create([
      'label' => 'Two column LG',
      'machine_name' => 'twocol_lg',
      'scope' => 'layout:daedalus_stylish_twocol',
      'axis' => 'width',
      'status' => TRUE,
    ]);
    $shipped->setStyles($styles);
    $shipped->setShippedSource('daedalus_stylish_layouts', $styles);
    $shipped->save();

    // Shipped but user-modified: kept (it migrated as an entity instead).
    $modified = DaedalusStylishVariant::create([
      'label' => 'Fifty fifty (tweaked)',
      'machine_name' => 'twocol_50_50',
      'scope' => 'layout:daedalus_stylish_twocol',
      // The historical axis was 'proportions'; that axis is off the
      // container declaration now, so the fixture saves on the built-in
      // axis (the axis is irrelevant to what this test pins).
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $modified->setStyles([
      '::.daedalus-stylish-layout-container' => ['default' => ['display' => 'grid', 'grid-template-columns' => '3fr 2fr']],
    ]);
    $modified->setShippedSource('daedalus_stylish_layouts', $styles);
    $modified->save();

    // A user variant that happens to reuse a retired name: never touched.
    $user = DaedalusStylishVariant::create([
      'label' => 'My own onecol_lg',
      'machine_name' => 'onecol_lg',
      'scope' => 'kind:container',
      'axis' => 'width',
      'status' => TRUE,
    ]);
    $user->setStyles($styles);
    $user->save();

    $lines = $this->container->get('daedalus_stylish_layouts.container_migration')->retireShippedLayoutVariants();
    $this->assertNotEmpty($lines);
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();
    $this->assertNull($storage->loadUnchanged($shipped->id()), 'The unmodified shipped variant is deleted.');
    $this->assertNotNull($storage->loadUnchanged($modified->id()), 'The modified shipped variant survives.');
    $this->assertNotNull($storage->loadUnchanged($user->id()), 'A user variant reusing a retired name survives.');
  }


}
