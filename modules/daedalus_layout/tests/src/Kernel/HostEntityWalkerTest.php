<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\layout_builder\Section;
use Drupal\field\Entity\FieldConfig;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\entity_test\Entity\EntityTestMulRev;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout_transform_test\TransformLog;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\daedalus_layout\ComponentTransform\HostEntityWalker;
use Drupal\daedalus_layout\ComponentTransform\ComponentTransformRegistry;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the batched host entity walker of the migration spine.
 *
 * @group daedalus_layout
 */
#[RunTestsInSeparateProcesses]
class HostEntityWalkerTest extends NestedStorageKernelTestBase {

  /**
   * The name of a translatable layout_section field on entity_test_mulrev.
   *
   * Core forces layout_builder__layout untranslatable (storage and field),
   * so the per-translation walk is exercised through a second layout field,
   * which also proves the walker handles multiple layout_section fields on
   * one host type.
   */
  protected const TRANSLATABLE_FIELD = 'layout_i18n';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'daedalus_layout_transform_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('entity_test_mulrev');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('de')->save();

    // A revisionable, translatable host: saving an overridable display
    // auto-creates the (untranslatable) layout_builder__layout field on
    // entity_test_mulrev.
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_mulrev',
      'bundle' => 'entity_test_mulrev',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();

    // A second, translatable layout field on the same host type.
    FieldStorageConfig::create([
      'entity_type' => 'entity_test_mulrev',
      'field_name' => static::TRANSLATABLE_FIELD,
      'type' => 'layout_section',
      'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'entity_test_mulrev',
      'bundle' => 'entity_test_mulrev',
      'field_name' => static::TRANSLATABLE_FIELD,
      'label' => 'Translatable layout',
      'translatable' => TRUE,
    ])->save();

    TransformLog::reset();
  }

  /**
   * Creates a section holding one legacy component with a known UUID.
   */
  protected function legacySection(string $component_uuid, string $label): Section {
    return $this->createSection('layout_onecol', [
      new SectionComponent($component_uuid, 'content', [
        'id' => 'inline_block:legacy',
        'label' => $label,
      ]),
    ]);
  }

  /**
   * Creates a section holding one component no transform matches.
   */
  protected function cleanSection(): Section {
    return $this->createSection('layout_onecol', [
      new SectionComponent($this->uuid->generate(), 'content', [
        'id' => 'system_powered_by_block',
      ]),
    ]);
  }

  /**
   * Collects a revision's component configurations, keyed by UUID.
   */
  protected function componentConfigs(string|int $revision_id, string $field_name, string $langcode = 'en'): array {
    $storage = $this->entityTypeManager->getStorage('entity_test_mulrev');
    /** @var \Drupal\entity_test\Entity\EntityTestMulRev $revision */
    $revision = $storage->loadRevision($revision_id);
    $host = $revision->getTranslation($langcode);
    $configs = [];
    foreach ($host->get($field_name)->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $configs[$component->getUuid()] = $component->get('configuration');
      }
    }
    return $configs;
  }

  /**
   * Walks every revision, translation, and host type in batches.
   */
  public function testWalkTransformsAllRevisionsAndTranslations(): void {
    $storage = $this->entityTypeManager->getStorage('entity_test_mulrev');
    $uuid_shared_v1 = '10000000-0000-0000-0000-000000000001';
    $uuid_de = '20000000-0000-0000-0000-000000000002';
    $uuid_shared_v3 = '30000000-0000-0000-0000-000000000003';
    $uuid_plain = '40000000-0000-0000-0000-000000000004';

    // Host A revision 1: a legacy component in the shared (untranslatable)
    // layout, a clean EN translatable layout, and a legacy DE one. Staging
    // saves are syncing saves throughout: the presave safety net converges
    // legacy components on any normal save, which is exactly what these
    // tests must first sneak past to have something to walk.
    $a = EntityTestMulRev::create([
      'name' => 'Host A',
      OverridesSectionStorage::FIELD_NAME => [$this->legacySection($uuid_shared_v1, 'Shared v1')],
      static::TRANSLATABLE_FIELD => [$this->cleanSection()],
    ]);
    $a->addTranslation('de', ['name' => 'Host A de']);
    $a->getTranslation('de')->set(static::TRANSLATABLE_FIELD, [$this->legacySection($uuid_de, 'DE only')]);
    $a->setSyncing(TRUE);
    $a->save();
    $vid1 = $a->getRevisionId();

    // Revision 2 (default): the shared layout cleaned up manually; the DE
    // translatable layout still carries its legacy copy, so only the
    // translation walk can find this revision dirty.
    $rev2 = $storage->createRevision($a);
    $rev2->set(OverridesSectionStorage::FIELD_NAME, [$this->cleanSection()]);
    $rev2->setSyncing(TRUE);
    $rev2->save();
    $vid2 = $rev2->getRevisionId();

    // Revision 3, pending (non-default): the shape of a workspace revision.
    $rev3 = $storage->createRevision($rev2, FALSE);
    $rev3->set(OverridesSectionStorage::FIELD_NAME, [$this->legacySection($uuid_shared_v3, 'Shared v3')]);
    $rev3->getTranslation('de')->set(static::TRANSLATABLE_FIELD, [$this->cleanSection()]);
    $rev3->setSyncing(TRUE);
    $rev3->save();
    $vid3 = $rev3->getRevisionId();

    // Control host B: nothing a transform matches; must never be re-saved.
    $b = EntityTestMulRev::create([
      'name' => 'Control',
      OverridesSectionStorage::FIELD_NAME => [$this->cleanSection()],
    ]);
    $b->save();

    // Host C: no layout at all.
    $c = EntityTestMulRev::create(['name' => 'Empty']);
    $c->save();

    // Host D: a non-revisionable host type (plain entity_test).
    $d = EntityTest::create([
      OverridesSectionStorage::FIELD_NAME => [$this->legacySection($uuid_plain, 'Plain')],
    ]);
    $d->setSyncing(TRUE);
    $d->save();

    TransformLog::reset();

    /** @var \Drupal\daedalus_layout\ComponentTransform\HostEntityWalker $walker */
    $walker = $this->container->get('daedalus_layout.host_entity_walker');
    $sandbox = [];
    $calls = 0;
    $saved = 0;
    do {
      $saved += $walker->walk($sandbox, 2);
      $calls++;
      $this->assertLessThan(20, $calls, 'The walk terminates.');
    } while ($sandbox['#finished'] < 1);

    // 6 revisions at batch size 2 requires multiple passes with state
    // resuming from the sandbox.
    $this->assertGreaterThan(1, $calls);
    $this->assertSame(4, $saved, 'Three dirty revisions of host A and the plain host were re-saved.');

    // Revision 1: the shared layout and the DE translatable layout migrated,
    // UUIDs and unrelated configuration preserved, and the translation
    // object was the host (label() resolved per language).
    $shared_v1 = $this->componentConfigs($vid1, OverridesSectionStorage::FIELD_NAME)[$uuid_shared_v1];
    $this->assertSame('inline_block:modern', $shared_v1['id']);
    $this->assertSame('Shared v1', $shared_v1['label']);
    $this->assertSame('Host A', $shared_v1['migrated_from']);
    $this->assertTrue($shared_v1['annotated']);
    $de_v1 = $this->componentConfigs($vid1, static::TRANSLATABLE_FIELD, 'de')[$uuid_de];
    $this->assertSame('inline_block:modern', $de_v1['id']);
    $this->assertSame('Host A de', $de_v1['migrated_from']);
    $this->assertTrue($de_v1['annotated']);
    $en_v1 = $this->componentConfigs($vid1, static::TRANSLATABLE_FIELD);
    $this->assertSame('system_powered_by_block', reset($en_v1)['id'], 'The clean EN translation is untouched.');

    // Revision 2: only dirty through the DE translation.
    $shared_v2 = $this->componentConfigs($vid2, OverridesSectionStorage::FIELD_NAME);
    $this->assertSame('system_powered_by_block', reset($shared_v2)['id']);
    $de_v2 = $this->componentConfigs($vid2, static::TRANSLATABLE_FIELD, 'de')[$uuid_de];
    $this->assertSame('inline_block:modern', $de_v2['id']);

    // Revision 3 (pending, non-default) migrated too.
    $shared_v3 = $this->componentConfigs($vid3, OverridesSectionStorage::FIELD_NAME)[$uuid_shared_v3];
    $this->assertSame('inline_block:modern', $shared_v3['id']);
    $this->assertSame('Shared v3', $shared_v3['label']);

    // The non-revisionable host migrated through the entity ID branch.
    $this->entityTypeManager->getStorage('entity_test')->resetCache();
    $d = EntityTest::load($d->id());
    $plain_configs = [];
    foreach ($d->get(OverridesSectionStorage::FIELD_NAME)->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        $plain_configs[$component->getUuid()] = $component->get('configuration');
      }
    }
    $this->assertSame('inline_block:modern', $plain_configs[$uuid_plain]['id']);
    $this->assertSame('Plain', $plain_configs[$uuid_plain]['label']);

    // Exactly the dirty hosts were re-saved: one save per dirty revision
    // (not per translation or field), and the clean and empty hosts never
    // saved.
    $expected_saves = [
      "entity_test_mulrev:{$a->id()}:$vid1",
      "entity_test_mulrev:{$a->id()}:$vid2",
      "entity_test_mulrev:{$a->id()}:$vid3",
      "entity_test:{$d->id()}:",
    ];
    sort($expected_saves);
    $actual_saves = TransformLog::$saves;
    sort($actual_saves);
    $this->assertSame($expected_saves, $actual_saves);

    // The default revision pointer was not moved by rewriting revisions.
    $storage->resetCache();
    $this->assertSame($vid2, EntityTestMulRev::load($a->id())->getRevisionId());
  }

  /**
   * An empty registry finishes immediately without touching anything.
   */
  public function testNoTransformsShortCircuits(): void {
    $uuid = '50000000-0000-0000-0000-000000000005';
    // Staged with a syncing save so the presave safety net (backed by the
    // container registry, which does hold the test transforms) leaves the
    // legacy component in place.
    $entity = EntityTest::create([
      OverridesSectionStorage::FIELD_NAME => [$this->legacySection($uuid, 'Legacy stays')],
    ]);
    $entity->setSyncing(TRUE);
    $entity->save();
    TransformLog::reset();

    $walker = new HostEntityWalker(
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      new ComponentTransformRegistry($this->container, []),
    );
    $sandbox = [];
    $this->assertSame(0, $walker->walk($sandbox));
    $this->assertSame(1, $sandbox['#finished']);
    $this->assertSame([], TransformLog::$saves);

    $this->entityTypeManager->getStorage('entity_test')->resetCache();
    $entity = EntityTest::load($entity->id());
    $sections = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $components = reset($sections)->getComponents();
    $this->assertSame('inline_block:legacy', reset($components)->get('configuration')['id']);
  }

}
