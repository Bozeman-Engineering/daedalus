<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\RelativeSelectorConvergence;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the relative selector convergence: one spelling in every store.
 *
 * The suffix after a key's "::" is a relative selector in its canonical
 * spelling; the retired pseudo-part spelling ("::>", "::.cls::>",
 * "::p::>") converges to the selector it means ("::> *", "::.cls > *",
 * "::p > *") everywhere it was stored: host style maps (the
 * RelativeSelectorSweep, riding daedalus_layout's transform spine after
 * the container sweeps), daedalus_variant styles across revisions
 * (fragment references included), daedalus_skin defaults, and stale
 * tempstore sessions converging at load time.
 * Shipped-unmodified entities are left to the sync lane; a second pass
 * changes nothing.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class RelativeSelectorSweepTest extends NestedStorageKernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * The key rewrite: legacy spellings converge, canonical keys stand.
   */
  public function testConvergeKey(): void {
    $cases = [
      'container:x' => 'container:x',
      'container:x::>' => 'container:x::> *',
      'container:x::.band::>' => 'container:x::.band > *',
      'container:x::ul::>' => 'container:x::ul > *',
      'container:x::> *' => 'container:x::> *',
      'container:x::.band > *' => 'container:x::.band > *',
      'container:x::>*' => 'container:x::> *',
      'container:x::.band  >  *' => 'container:x::.band > *',
      'container(1)::>' => 'container(1)::> *',
      '::>' => '::> *',
      '::.s-a1::>' => '::.s-a1 > *',
      // Not a selector: left for the validator to refuse loudly.
      'container:x::>::>' => 'container:x::>::>',
      'container:x::not a selector!' => 'container:x::not a selector!',
    ];
    foreach ($cases as $key => $expected) {
      $this->assertSame($expected, RelativeSelectorConvergence::convergeKey($key), $key);
    }
  }

  /**
   * Persists an entity carrying pre-convergence data.
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
   * Host maps: every legacy spelling converges, references included.
   */
  public function testHostMapConverges(): void {
    $section = $this->createSection('daedalus_stylish_container');
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $entity = $this->createLegacyEntity([$section], [
      "container:$uuid" => [
        'variants' => [['variant' => 'columns', 'fragment' => ''], ['variant' => 'pull', 'fragment' => 'container(1)::>']],
        'default' => ['padding-top' => '5rem'],
      ],
      "container:$uuid::>" => ['default' => ['min-width' => '0']],
      "container:$uuid::.band::>" => ['default' => ['flex-grow' => '1'], 'narrow' => ['flex-grow' => '0']],
      "container:$uuid::p" => ['default' => ['color' => 'red']],
      "container:$uuid::> pb-text" => ['default' => ['color' => 'blue']],
    ]);

    $this->walkHosts();
    $map = $this->styleMap($this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id()));
    $this->assertSame([
      "container:$uuid",
      "container:$uuid::> *",
      "container:$uuid::.band > *",
      "container:$uuid::p",
      "container:$uuid::> pb-text",
    ], array_keys($map));
    $this->assertSame(['default' => ['min-width' => '0']], $map["container:$uuid::> *"]);
    $this->assertSame(['default' => ['flex-grow' => '1'], 'narrow' => ['flex-grow' => '0']], $map["container:$uuid::.band > *"]);
    $this->assertSame('container(1)::> *', $map["container:$uuid"]['variants'][1]['fragment']);

    // Idempotence: a second walk changes nothing.
    $this->walkHosts();
    $this->assertSame($map, $this->styleMap($this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id())));
  }

  /**
   * A legacy spelling colliding with the canonical entry merges beneath it.
   */
  public function testCollisionKeepsTheCanonicalEntry(): void {
    $section = $this->createSection('daedalus_stylish_container');
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $entity = $this->createLegacyEntity([$section], [
      "container:$uuid::>" => ['default' => ['min-width' => '0', 'gap' => '1rem']],
      "container:$uuid::> *" => ['default' => ['gap' => '2rem'], 'hover' => ['gap' => '3rem']],
    ]);
    $this->walkHosts();
    $map = $this->styleMap($this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id()));
    $this->assertSame(["container:$uuid::> *"], array_keys($map));
    $this->assertSame(['default' => ['min-width' => '0', 'gap' => '2rem'], 'hover' => ['gap' => '3rem']], $map["container:$uuid::> *"]);
  }

  /**
   * Variants converge across ALL revisions; shipped-unmodified are skipped.
   */
  public function testVariantsConverge(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'My band',
      'machine_name' => 'my_band',
      'scope' => 'kind:container',
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $variant->setStyles([
      '' => ['default' => ['background-color' => 'aliceblue']],
      '::>' => ['default' => ['min-width' => '0']],
      'container(1)::.band::>' => ['default' => ['gap' => '1rem']],
      'block(text,1)' => ['variants' => [['variant' => 'pull', 'fragment' => '::>']]],
    ]);
    $variant->save();
    $first_revision_id = $variant->getRevisionId();
    $variant->setStyles(['::.band::>' => ['default' => ['padding-top' => '6rem']]]);
    $variant->setNewRevision(TRUE);
    $variant->save();

    $shipped_styles = ['::>' => ['default' => ['max-width' => '80rem']]];
    $shipped = DaedalusStylishVariant::create([
      'label' => 'Columns',
      'machine_name' => 'columns',
      'scope' => 'kind:container',
      'axis' => 'container_layout',
      'status' => TRUE,
    ]);
    $shipped->setStyles($shipped_styles);
    $shipped->setShippedSource('daedalus_stylish_layouts', $shipped_styles);
    $shipped->save();

    $lines = $this->container->get('daedalus_stylish.relative_selector_convergence')->convergeVariants();
    $this->assertNotEmpty($lines);
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();

    $this->assertSame(['::.band > *' => ['default' => ['padding-top' => '6rem']]], $storage->loadUnchanged($variant->id())->getStyles());
    /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $first */
    $first = $storage->loadRevision($first_revision_id);
    $this->assertSame([
      '' => ['default' => ['background-color' => 'aliceblue']],
      '::> *' => ['default' => ['min-width' => '0']],
      'container(1)::.band > *' => ['default' => ['gap' => '1rem']],
      'block(text,1)' => ['variants' => [['variant' => 'pull', 'fragment' => '::> *']]],
    ], $first->getStyles());

    $shipped = $storage->loadUnchanged($shipped->id());
    $this->assertSame($shipped_styles, $shipped->getStyles(), 'A shipped unmodified variant is left to the sync lane.');
    $this->assertFalse($shipped->isModified());

    // Idempotence.
    $this->assertSame([], $this->container->get('daedalus_stylish.relative_selector_convergence')->convergeVariants());
  }

  /**
   * Skin defaults converge; shipped-unmodified skins are skipped.
   */
  public function testSkinsConverge(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setPackageDefaults([
      'kind:container' => [
        '' => ['default' => ['background-color' => 'white']],
        '::>' => ['default' => ['min-width' => '0']],
        '::.band::>' => ['default' => ['flex' => '1']],
      ],
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
    ]);
    $skin->save();

    $shipped_data = [
      'tokens' => [],
      'defaults' => ['kind:container' => ['::>' => ['default' => ['max-width' => '90rem']]]],
    ];
    $shipped = DaedalusStylishSkin::create(['label' => 'Shipped', 'machine_name' => 'shipped_skin', 'status' => TRUE]);
    $shipped->setPackageDefaults($shipped_data['defaults']);
    $shipped->setShippedSource('daedalus_stylish_test', $shipped_data);
    $shipped->save();

    $lines = $this->container->get('daedalus_stylish.relative_selector_convergence')->convergeSkins();
    $this->assertNotEmpty($lines);
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $storage->resetCache();

    $this->assertSame([
      'kind:container' => [
        '' => ['default' => ['background-color' => 'white']],
        '::> *' => ['default' => ['min-width' => '0']],
        '::.band > *' => ['default' => ['flex' => '1']],
      ],
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
    ], $storage->loadUnchanged($skin->id())->packageDefaults());

    $shipped = $storage->loadUnchanged($shipped->id());
    $this->assertSame($shipped_data['defaults'], $shipped->packageDefaults(), 'A shipped unmodified skin is left to the sync lane.');
    $this->assertFalse($shipped->isModified());
    $this->assertSame([], $this->container->get('daedalus_stylish.relative_selector_convergence')->convergeSkins());
  }


  /**
   * A stale tempstore session's keys converge at LOAD time.
   */
  public function testStaleSessionConvergesAtLoad(): void {
    $section = $this->createSection('daedalus_stylish_container');
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $entity = $this->createTestEntity([$this->createSection('daedalus_stylish_container')]);

    $helper = $this->container->get('daedalus_tempstore.section_storage_helper');
    $storage = $helper->getEditableSectionStorage($entity);
    if ($storage instanceof \Drupal\daedalus_layout\NestedSectionStorageInterface) {
      $storage = $storage->getRootStorage();
    }
    $staged_entity = $storage->getContextValue('entity');
    $staged_entity->set(OverridesSectionStorage::FIELD_NAME, [$section]);
    $staged_entity->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "container:$uuid::>" => ['default' => ['min-width' => '0']],
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
    $this->assertSame(['default' => ['min-width' => '0']], $map["container:$uuid::> *"]);
    $this->assertArrayNotHasKey("container:$uuid::>", $map);
  }

}
