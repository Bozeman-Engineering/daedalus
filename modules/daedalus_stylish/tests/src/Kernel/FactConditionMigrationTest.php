<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\FactConditionConvergence;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the fact-condition convergence: position is a qualifier.
 *
 * Every stored position-fact condition ("first-child", "not-last-child",
 * "odd", …) moves onto the qualified key that says the same thing: the
 * owner's own position as the qualified anchor ("{key}::&:first-child"),
 * a rule's as the qualifier on its last compound
 * ("{key}::> *:not(:last-child)"), the posable remainder of a compound
 * staying the block's condition. Covered stores: host style maps (the
 * FactConditionSweep, riding daedalus_layout's transform spine after the
 * relative selector sweep), daedalus_variant styles across revisions
 * (slot paths and the entries carrying nested references included),
 * daedalus_skin defaults, and stale tempstore sessions converging at load
 * time. A collision merges with the later entry in map order winning;
 * shipped-unmodified entities are left to the sync lane; a second pass
 * changes nothing.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class FactConditionMigrationTest extends NestedStorageKernelTestBase {

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
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
  }

  /**
   * The key rewrite: the qualifier lands on the anchor or the last compound.
   */
  public function testQualifiedKey(): void {
    $cases = [
      ['container:x', ':first-child', 'container:x::&:first-child'],
      ['', ':nth-child(odd)', '::&:nth-child(odd)'],
      ['container(1)', ':not(:last-child)', 'container(1)::&:not(:last-child)'],
      ['container(1)/block(text,1)', ':empty', 'container(1)/block(text,1)::&:empty'],
      ['container:x::> *', ':not(:last-child)', 'container:x::> *:not(:last-child)'],
      ['container:x::> d-text:has(a)', ':first-child', 'container:x::> d-text:first-child:has(a)'],
      ['container:x::.quote', ':nth-child(even)', 'container:x::.quote:nth-child(even)'],
      ['container:x::> p + p', ':last-child', 'container:x::> p + p:last-child'],
      ['container:x::&::before', ':first-child', 'container:x::&:first-child::before'],
      ['container:x::> *::after', ':nth-child(even)', 'container:x::> *:nth-child(even)::after'],
      ['container:x::&:first-child', ':last-child', 'container:x::&:first-child:last-child'],
      ['container:x::&:first-child > *', ':only-child', 'container:x::&:first-child > *:only-child'],
      ['container:x', ':first-child:not(:empty)', 'container:x::&:first-child:not(:empty)'],
      // Outside the grammar: nothing to qualify.
      ['container:x::not a selector!', ':first-child', NULL],
      ['container:x::>', ':first-child', NULL],
    ];
    foreach ($cases as [$key, $qualifier, $expected]) {
      $this->assertSame($expected, FactConditionConvergence::qualifiedKey($key, $qualifier), "$key + $qualifier");
    }
    foreach (FactConditionConvergence::QUALIFIERS as $fact => $qualifier) {
      $this->assertSame("container:x::&$qualifier", FactConditionConvergence::qualifiedKey('container:x', $qualifier), $fact);
      $this->assertSame("container:x::> *$qualifier", FactConditionConvergence::qualifiedKey('container:x::> *', $qualifier), $fact);
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
    if ($item === NULL) {
      return [];
    }
    assert($item instanceof StyleMapItem);
    return $item->getStyleMap();
  }

  /**
   * Host maps: every fact block moves onto its qualified key.
   *
   * The owner's facts (plain and inside a compound), a rule's fact, the
   * boxes of both, and a compound with a width remainder; the emptied
   * entries are dropped and the posable remainder stays the condition.
   */
  public function testHostMapConverges(): void {
    $section = $this->createSection('daedalus_stylish_container');
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $entity = $this->createLegacyEntity([$section], [
      "container:$uuid" => [
        'variants' => [['variant' => 'columns', 'fragment' => '']],
        'default' => ['padding' => '1rem'],
        'first-child' => ['margin-top' => '0'],
        'hover+last-child' => ['color' => 'blue'],
      ],
      "container:$uuid::> *" => ['default' => ['margin' => '0'], 'not-last-child' => ['margin-bottom' => '24px']],
      "container:$uuid::> *::after" => ['odd' => ['content' => '""']],
      "container:$uuid::&::before" => ['default' => ['content' => '""'], 'even' => ['width' => '2rem']],
      "container:$uuid::> d-text:has(a)" => [
        'first-child+narrow' => ['font-size' => '2rem'],
        'hover' => ['color' => 'red'],
      ],
      "container:$uuid::p" => ['default' => ['color' => 'gray']],
    ]);

    $this->walkHosts();
    $map = $this->styleMap($this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id()));
    $this->assertSame([
      "container:$uuid" => [
        'variants' => [['variant' => 'columns', 'fragment' => '']],
        'default' => ['padding' => '1rem'],
      ],
      "container:$uuid::&:first-child" => ['default' => ['margin-top' => '0']],
      "container:$uuid::&:last-child" => ['hover' => ['color' => 'blue']],
      "container:$uuid::> *" => ['default' => ['margin' => '0']],
      "container:$uuid::> *:not(:last-child)" => ['default' => ['margin-bottom' => '24px']],
      "container:$uuid::> *:nth-child(odd)::after" => ['default' => ['content' => '""']],
      "container:$uuid::&::before" => ['default' => ['content' => '""']],
      "container:$uuid::&:nth-child(even)::before" => ['default' => ['width' => '2rem']],
      "container:$uuid::> d-text:has(a)" => ['hover' => ['color' => 'red']],
      "container:$uuid::> d-text:first-child:has(a)" => ['narrow' => ['font-size' => '2rem']],
      "container:$uuid::p" => ['default' => ['color' => 'gray']],
    ], $map);

    // Idempotence: a second walk changes nothing.
    $this->walkHosts();
    $this->assertSame($map, $this->styleMap($this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id())));
  }

  /**
   * A collision merges; the later entry in map order wins and keeps its place.
   *
   * Both directions: the qualified key already stored later in the map
   * wins over the block moving onto it, and a block moving onto a key
   * stored earlier wins over that entry.
   */
  public function testCollisionMergesByMapOrder(): void {
    $convergence = $this->container->get('daedalus_stylish.fact_condition_convergence');
    $lines = [];
    $map = $convergence->convergeStyleMap([
      'container:x' => ['first-child' => ['color' => 'red', 'margin' => '1px']],
      'container:x::&:first-child' => ['default' => ['color' => 'blue'], 'hover' => ['color' => 'green']],
      'container:x::> *:not(:last-child)' => ['default' => ['margin-bottom' => '8px', 'padding' => '1px']],
      'container:x::> *' => ['default' => ['margin' => '0'], 'not-last-child' => ['margin-bottom' => '24px']],
    ], 'test', $lines);
    $this->assertSame([
      'container:x::&:first-child' => [
        'default' => ['color' => 'blue', 'margin' => '1px'],
        'hover' => ['color' => 'green'],
      ],
      'container:x::> *' => ['default' => ['margin' => '0']],
      'container:x::> *:not(:last-child)' => ['default' => ['margin-bottom' => '24px', 'padding' => '1px']],
    ], $map);
    $won = array_values(array_filter($lines, static fn (string $line) => str_contains($line, 'won')));
    $this->assertCount(2, $won, implode("\n", $lines));
    $this->assertStringContainsString('"container:x::&:first-child"', $won[0]);
    $this->assertStringContainsString('default color', $won[0]);
    $this->assertStringContainsString('"container:x::> *:not(:last-child)"', $won[1]);
    $this->assertStringContainsString('default margin-bottom', $won[1]);

    // Nothing left to move: NULL and silence.
    $lines = [];
    $this->assertNull($convergence->convergeStyleMap($map, 'test', $lines));
    $this->assertSame([], $lines);

    // Two blocks of one entry landing on one key: each keeps its own
    // remainder. A block whose key is outside the grammar stays put, said
    // loudly on every pass (the validator refuses such a key).
    $lines = [];
    $map = $convergence->convergeStyleMap([
      'container:x::> *' => [
        'not-last-child' => ['margin-bottom' => '24px'],
        'hover+not-last-child' => ['margin-bottom' => '32px'],
      ],
      'container:x::not a selector!' => ['first-child' => ['color' => 'red']],
    ], 'test', $lines);
    $this->assertSame([
      'container:x::> *:not(:last-child)' => [
        'default' => ['margin-bottom' => '24px'],
        'hover' => ['margin-bottom' => '32px'],
      ],
      'container:x::not a selector!' => ['first-child' => ['color' => 'red']],
    ], $map);
    $this->assertNotEmpty(array_filter($lines, static fn (string $line) => str_contains($line, 'left the "first-child" block')));
    $lines = [];
    $this->assertNull($convergence->convergeStyleMap($map, 'test', $lines));
    $this->assertCount(1, $lines);
  }

  /**
   * Variants converge across ALL revisions; shipped-unmodified are skipped.
   *
   * The root entry keeps its nested reference list while its fact block
   * moves; composition slot paths qualify their own anchor.
   */
  public function testVariantsConverge(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'My section',
      'machine_name' => 'my_section',
      'scope' => 'kind:container',
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $variant->setStyles([
      '' => [
        'default' => ['background-color' => 'aliceblue'],
        'first-child' => ['margin-top' => '0'],
        'variants' => [['variant' => 'pull', 'fragment' => '']],
      ],
      '::> *' => ['not-last-child' => ['margin-bottom' => '24px']],
      'container(1)' => ['odd' => ['background-color' => 'gray']],
      'container(1)::.section' => ['default' => ['gap' => '1rem'], 'hover+even' => ['gap' => '2rem']],
      'block(text,1)' => ['variants' => [['variant' => 'pull', 'fragment' => '']]],
    ]);
    $variant->save();
    $first_revision_id = $variant->getRevisionId();
    $variant->setStyles([
      '::.section' => ['default' => ['padding-top' => '6rem'], 'last-child' => ['padding-bottom' => '0']],
    ]);
    $variant->setNewRevision(TRUE);
    $variant->save();

    $shipped_styles = ['::> *' => ['default' => ['max-width' => '80rem'], 'first-child' => ['margin-top' => '0']]];
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

    $lines = $this->container->get('daedalus_stylish.fact_condition_convergence')->convergeVariants();
    $this->assertNotEmpty($lines);
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $storage->resetCache();

    $unchanged = $storage->loadUnchanged($variant->id());
    assert($unchanged instanceof DaedalusStylishVariant);
    $this->assertSame([
      '::.section' => ['default' => ['padding-top' => '6rem']],
      '::.section:last-child' => ['default' => ['padding-bottom' => '0']],
    ], $unchanged->getStyles());
    assert($storage instanceof RevisionableStorageInterface);
    $first = $storage->loadRevision($first_revision_id);
    assert($first instanceof DaedalusStylishVariant);
    $this->assertSame([
      '' => [
        'default' => ['background-color' => 'aliceblue'],
        'variants' => [['variant' => 'pull', 'fragment' => '']],
      ],
      '::&:first-child' => ['default' => ['margin-top' => '0']],
      '::> *:not(:last-child)' => ['default' => ['margin-bottom' => '24px']],
      'container(1)::&:nth-child(odd)' => ['default' => ['background-color' => 'gray']],
      'container(1)::.section' => ['default' => ['gap' => '1rem']],
      'container(1)::.section:nth-child(even)' => ['hover' => ['gap' => '2rem']],
      'block(text,1)' => ['variants' => [['variant' => 'pull', 'fragment' => '']]],
    ], $first->getStyles());

    $shipped = $storage->loadUnchanged($shipped->id());
    assert($shipped instanceof DaedalusStylishVariant);
    $this->assertSame($shipped_styles, $shipped->getStyles(), 'A shipped unmodified variant is left to the sync lane.');
    $this->assertFalse($shipped->isModified());

    // Idempotence.
    $this->assertSame([], $this->container->get('daedalus_stylish.fact_condition_convergence')->convergeVariants());
  }

  /**
   * Skin defaults converge in both layers; shipped-unmodified are skipped.
   */
  public function testSkinsConverge(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora', 'status' => TRUE]);
    $skin->setPackageDefaults([
      'kind:container' => [
        '' => ['default' => ['background-color' => 'white'], 'first-child' => ['margin-top' => '0']],
        '::> *' => ['default' => ['min-width' => '0'], 'not-last-child' => ['margin-bottom' => '24px']],
      ],
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
    ]);
    $skin->setSiteDefaults([
      'base:li' => ['' => ['odd' => ['background-color' => '#f5f5f5']]],
    ]);
    $skin->save();

    $shipped_data = [
      'tokens' => [],
      'animations' => [],
      'fonts' => [],
      'defaults' => [
        'kind:container' => [
          '::> *' => ['default' => ['max-width' => '90rem'], 'last-child' => ['margin-bottom' => '0']],
        ],
      ],
    ];
    $shipped = DaedalusStylishSkin::create(['label' => 'Shipped', 'machine_name' => 'shipped_skin', 'status' => TRUE]);
    $shipped->setPackageDefaults($shipped_data['defaults']);
    $shipped->setShippedSource('daedalus_stylish_test', $shipped_data);
    $shipped->setSiteDefaults([
      'base:h2' => ['' => ['default' => ['color' => 'navy'], 'empty' => ['display' => 'none']]],
    ]);
    $shipped->save();

    $lines = $this->container->get('daedalus_stylish.fact_condition_convergence')->convergeSkins();
    $this->assertNotEmpty($lines);
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $storage->resetCache();

    /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $aurora */
    $aurora = $storage->loadUnchanged($skin->id());
    $this->assertSame([
      'kind:container' => [
        '' => ['default' => ['background-color' => 'white']],
        '::&:first-child' => ['default' => ['margin-top' => '0']],
        '::> *' => ['default' => ['min-width' => '0']],
        '::> *:not(:last-child)' => ['default' => ['margin-bottom' => '24px']],
      ],
      'base:h2' => ['' => ['default' => ['font-size' => '2rem']]],
    ], $aurora->packageDefaults());
    $this->assertSame([
      'base:li' => ['::&:nth-child(odd)' => ['default' => ['background-color' => '#f5f5f5']]],
    ], $aurora->siteDefaults());

    /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $shipped */
    $shipped = $storage->loadUnchanged($shipped->id());
    $this->assertSame($shipped_data['defaults'], $shipped->packageDefaults(), 'A shipped unmodified package layer is left to the sync lane.');
    $this->assertFalse($shipped->isModified());
    $this->assertSame([
      'base:h2' => [
        '' => ['default' => ['color' => 'navy']],
        '::&:empty' => ['default' => ['display' => 'none']],
      ],
    ], $shipped->siteDefaults(), 'The site layer over a shipped skin is the site\'s and converges.');

    $this->assertSame([], $this->container->get('daedalus_stylish.fact_condition_convergence')->convergeSkins());
  }

  /**
   * A stale tempstore session's fact conditions converge at LOAD time.
   */
  public function testStaleSessionConvergesAtLoad(): void {
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
      "container:$uuid::> *" => ['default' => ['margin' => '0'], 'not-last-child' => ['margin-bottom' => '24px']],
    ], JSON_UNESCAPED_SLASHES));
    $strategy = $this->container->get('daedalus_tempstore.strategy.layout');
    $key = $strategy->getKey($storage);
    $this->container->get('tempstore.shared')
      ->get('layout_builder.section_storage.overrides')
      ->set($key, ['section_storage' => $storage]);

    $fresh = $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($entity->id());
    $staged = $this->container->get('daedalus_tempstore.entity_tempstore_repository')->get($fresh);
    $this->assertNotSame($fresh, $staged, 'The staged session is served.');

    assert($staged instanceof EntityTest);
    $map = $this->styleMap($staged);
    $this->assertSame(['default' => ['margin' => '0']], $map["container:$uuid::> *"]);
    $this->assertSame(['default' => ['margin-bottom' => '24px']], $map["container:$uuid::> *:not(:last-child)"]);
  }

}
