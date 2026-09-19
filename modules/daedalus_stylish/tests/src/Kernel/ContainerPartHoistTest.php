<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish_layouts\ContainerMigration;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the one-box convergence: part styles hoist onto the root key.
 *
 * The container is ONE element at every depth now, so stored styles still
 * addressing the retired `.daedalus-stylish-layout-container` part (and its `::>`
 * children entries) re-key onto the root everywhere they live: host style
 * maps (the ContainerPartHoistSweep, riding daedalus_layout's transform spine
 * after the row sweep), daedalus_variant styles across revisions
 * (composition slot paths included), daedalus_skin defaults, stored
 * stencils, and stale tempstore sessions converging at load time.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ContainerPartHoistTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    'daedalus_stylish_layouts',
    // The stencil entity's icon field references media, so installing the
    // stencil schema needs the media entity type (and its field types).
    'file',
    'image',
    'media',
    'daedalus_blueprint_stencil',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    // The materials roster's daedalus_stylish provider reads all four storages on
    // every build, and every compile and validation builds it.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('stencil');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * Persists an entity carrying pre-hoist data.
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
   * Host maps: part and children entries hoist onto the root, root wins.
   */
  public function testHostMapHoist(): void {
    $section = $this->createSection('daedalus_stylish_container');
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $entity = $this->createLegacyEntity([$section], [
      "container:$uuid" => [
        'variants' => [['variant' => 'container_lg', 'fragment' => '']],
        'default' => ['background-color' => 'aliceblue', 'padding-top' => '5rem'],
      ],
      "container:$uuid" . ContainerMigration::CONTAINER_PART => [
        // padding-top collides with the root's own 5rem: the root wins.
        'default' => ['padding-top' => '4rem', 'align-items' => 'center'],
        'narrow' => ['padding-top' => '2rem'],
      ],
      "container:$uuid" . ContainerMigration::CONTAINER_PART . '::>' => [
        'default' => ['min-width' => '0'],
      ],
    ]);
    $this->walkHosts();
    $entity = EntityTest::load($entity->id());
    $map = $this->styleMap($entity);

    $this->assertSame([
      'variants' => [['variant' => 'container_lg', 'fragment' => '']],
      'default' => ['background-color' => 'aliceblue', 'padding-top' => '5rem', 'align-items' => 'center'],
      'narrow' => ['padding-top' => '2rem'],
    ], $map["container:$uuid"]);
    $this->assertSame(['default' => ['min-width' => '0']], $map["container:$uuid::>"]);
    $this->assertStringNotContainsString('.daedalus-stylish-layout-container', implode(' ', array_keys($map)));

    // Idempotence: a second walk rewrites nothing.
    $before = json_encode($map);
    $this->walkHosts();
    $this->assertSame($before, json_encode($this->styleMap(EntityTest::load($entity->id()))));
  }

  /**
   * Variants hoist across ALL revisions; shipped-unmodified are skipped.
   */
  public function testVariantHoist(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'My band',
      'machine_name' => 'my_band',
      'scope' => 'kind:container',
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $variant->setStyles([
      '' => ['default' => ['background-color' => 'aliceblue']],
      ContainerMigration::CONTAINER_PART => ['default' => ['padding-top' => '4rem']],
      ContainerMigration::CONTAINER_PART . '::>' => ['default' => ['min-width' => '0']],
      'container(1)' . ContainerMigration::CONTAINER_PART => ['default' => ['gap' => '1rem']],
    ]);
    $variant->save();
    $first_revision_id = $variant->getRevisionId();
    $variant->setStyles([
      ContainerMigration::CONTAINER_PART => ['default' => ['padding-top' => '6rem']],
    ]);
    $variant->setNewRevision(TRUE);
    $variant->save();

    // A shipped, unmodified variant still carrying the part key: the sync
    // lane owns it, so the hoist must not touch it (an in-place rewrite
    // would flip its hash to "modified").
    $shipped_styles = [ContainerMigration::CONTAINER_PART => ['default' => ['max-width' => '80rem']]];
    $shipped = DaedalusStylishVariant::create([
      'label' => 'Large (1024px)',
      'machine_name' => 'container_lg',
      'scope' => 'kind:container',
      'axis' => 'width',
      'status' => TRUE,
    ]);
    $shipped->setStyles($shipped_styles);
    $shipped->setShippedSource('daedalus_stylish_layouts', $shipped_styles);
    $shipped->save();

    $lines = $this->container->get('daedalus_stylish_layouts.container_part_hoist')->hoistVariants();
    $this->assertNotEmpty($lines);
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();

    $current = $storage->loadUnchanged($variant->id());
    $this->assertSame([
      '' => ['default' => ['padding-top' => '6rem']],
    ], $current->getStyles());
    /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $first */
    $first = $storage->loadRevision($first_revision_id);
    $this->assertSame([
      '' => ['default' => ['background-color' => 'aliceblue', 'padding-top' => '4rem']],
      '::>' => ['default' => ['min-width' => '0']],
      'container(1)' => ['default' => ['gap' => '1rem']],
    ], $first->getStyles());

    $shipped = $storage->loadUnchanged($shipped->id());
    $this->assertSame($shipped_styles, $shipped->getStyles(), 'A shipped unmodified variant is left to the sync lane.');
    $this->assertFalse($shipped->isModified());
  }

  /**
   * Skin defaults hoist; shipped-unmodified skins are skipped.
   */
  public function testSkinHoist(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setDefaults([
      'kind:container' => [
        '' => ['default' => ['background-color' => 'white']],
        ContainerMigration::CONTAINER_PART => ['default' => ['max-width' => '80rem']],
        ContainerMigration::CONTAINER_PART . '::>' => ['default' => ['min-width' => '0']],
      ],
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
    ]);
    $skin->save();

    $shipped_data = [
      'tokens' => [],
      'defaults' => ['kind:container' => [ContainerMigration::CONTAINER_PART => ['default' => ['max-width' => '90rem']]]],
    ];
    $shipped = DaedalusStylishSkin::create(['label' => 'Shipped', 'machine_name' => 'shipped_skin', 'status' => TRUE]);
    $shipped->setDefaults($shipped_data['defaults']);
    $shipped->setShippedSource('daedalus_stylish_test', $shipped_data);
    $shipped->save();

    $lines = $this->container->get('daedalus_stylish_layouts.container_part_hoist')->hoistSkins();
    $this->assertNotEmpty($lines);
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $storage->resetCache();

    $defaults = $storage->loadUnchanged($skin->id())->getDefaults();
    $this->assertSame([
      'kind:container' => [
        '' => ['default' => ['background-color' => 'white', 'max-width' => '80rem']],
        '::>' => ['default' => ['min-width' => '0']],
      ],
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
    ], $defaults);

    $shipped = $storage->loadUnchanged($shipped->id());
    $this->assertSame($shipped_data['defaults'], $shipped->getDefaults(), 'A shipped unmodified skin is left to the sync lane.');
    $this->assertFalse($shipped->isModified());
  }

  /**
   * Stored stencils hoist at every node depth, idempotently.
   */
  public function testStencilHoist(): void {
    $stencil = Stencil::create([
      'label' => 'Legacy band',
      'machine_name' => 'legacy_band',
      'description' => 'Captured before the one-box container.',
      'status' => TRUE,
    ]);
    $stencil->setElement([
      'layout_id' => 'daedalus_stylish_container',
      'label' => 'Band',
      'styles' => [
        '' => ['variants' => ['container_lg']],
        ContainerMigration::CONTAINER_PART => ['default' => ['padding-top' => '4rem']],
        ContainerMigration::CONTAINER_PART . '::>' => ['default' => ['min-width' => '0']],
      ],
      'regions' => [
        'content' => [
          [
            'layout_id' => 'daedalus_stylish_container',
            'styles' => [ContainerMigration::CONTAINER_PART => ['default' => ['gap' => '2rem']]],
          ],
          ['block_type' => 'text'],
        ],
      ],
    ]);
    $stencil->save();

    $hoist = $this->container->get('daedalus_stylish_layouts.container_part_hoist');
    $lines = $hoist->hoistStencils();
    $this->assertNotEmpty($lines);
    $storage = $this->entityTypeManager->getStorage('stencil');
    $storage->resetCache();
    $element = $storage->loadUnchanged($stencil->id())->getElement();

    $this->assertSame([
      '' => ['variants' => ['container_lg'], 'default' => ['padding-top' => '4rem']],
      '::>' => ['default' => ['min-width' => '0']],
    ], $element['styles']);
    $this->assertSame(['' => ['default' => ['gap' => '2rem']]], $element['regions']['content'][0]['styles']);

    // Idempotence: a second pass leaves the stencil untouched.
    $hoist->hoistStencils();
    $storage->resetCache();
    $this->assertSame($element, $storage->loadUnchanged($stencil->id())->getElement());
  }

  /**
   * A stale tempstore session's part keys converge at LOAD time.
   *
   * A session staged before the one-box migration holds a map still
   * addressing the part; the tempstore load chokepoint runs the same
   * transform spine, so the staged map speaks the root key before any
   * editor gesture writes it back.
   */
  public function testStaleSessionHoistsAtLoad(): void {
    $section = $this->createSection('daedalus_stylish_container');
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $entity = $this->createTestEntity([$this->createSection('daedalus_stylish_container')]);

    $helper = $this->container->get('daedalus_tempstore.section_storage_helper');
    $storage = $helper->getEditableSectionStorage($entity);
    if ($storage instanceof NestedSectionStorageInterface) {
      $storage = $storage->getRootStorage();
    }
    $staged_entity = $storage->getContextValue('entity');
    $staged_entity->set(OverridesSectionStorage::FIELD_NAME, [$section]);
    $staged_entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "container:$uuid" . ContainerMigration::CONTAINER_PART => [
        'default' => ['padding-top' => '4rem'],
      ],
    ], JSON_UNESCAPED_SLASHES));
    $strategy = $this->container->get('daedalus_tempstore.strategy.layout');
    $key = $strategy->getKey($storage);
    $this->container->get('tempstore.shared')
      ->get('layout_builder.section_storage.overrides')
      ->set($key, ['section_storage' => $storage]);

    $fresh = $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
    $staged = $this->container->get('daedalus_tempstore.entity_tempstore_repository')->get($fresh);
    $this->assertNotSame($fresh, $staged, 'The staged session is served.');

    $map = $staged->get(DaedalusStylishFieldManager::FIELD_NAME)->first()->getStyleMap();
    $this->assertSame(['default' => ['padding-top' => '4rem']], $map["container:$uuid"]);
    $this->assertArrayNotHasKey("container:$uuid" . ContainerMigration::CONTAINER_PART, $map);
  }

}
