<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_layout_transform_test\TransformLog;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the section- and host-transform seams of the migration spine.
 *
 * Component transforms are covered by ComponentTransformRegistryTest and
 * the walker tests; this test covers the two newer transform kinds: section
 * transforms (which REPLACE sections, since core sections cannot change
 * layout id in place) and host transforms (one host-level pass after the
 * section walk).
 *
 * @group daedalus_layout
 */
#[RunTestsInSeparateProcesses]
class SectionTransformSpineTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_layout_transform_test'];

  /**
   * {@inheritdoc}
   *
   * The test transform writes marker keys (migrated_from, annotated) that no
   * block plugin schema declares; real transforms write real plugin
   * configuration.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TransformLog::reset();
  }

  /**
   * Builds a convert-flagged twocol section with a block per region.
   */
  protected function convertibleSection(string $uuid): Section {
    $section = new Section('layout_twocol_section');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    $section->setThirdPartySetting('daedalus_layout_transform_test', 'convert', TRUE);
    $section->appendComponent($this->createBasicBlockComponent(NULL, 'second'));
    $section->appendComponent($this->createBasicBlockComponent(NULL, 'first'));
    return $section;
  }

  /**
   * A section transform's replacement persists through the host walker.
   */
  public function testSectionReplacementPersistsOnHosts(): void {
    $section_uuid = $this->uuid->generate();
    $entity = EntityTest::create([
      'name' => 'A host',
      'layout_builder__layout' => [$this->convertibleSection($section_uuid)],
    ]);
    // Syncing saves are exempt from the presave net, so the fixture stays
    // un-migrated until the walker runs (the LayoutBlockMigrationTest
    // pattern).
    $entity->setSyncing(TRUE);
    $entity->save();

    $sandbox = [];
    do {
      $this->container->get('daedalus_layout.host_entity_walker')->walk($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);

    $this->entityTypeManager->getStorage('entity_test')->resetCache();
    $entity = EntityTest::load($entity->id());
    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $section = reset($sections);
    $this->assertSame('layout_onecol', $section->getLayoutId());
    $this->assertSame($section_uuid, $section->getThirdPartySetting('daedalus_layout', 'uuid'), 'Identity survives replacement.');
    $this->assertNull($section->getThirdPartySetting('daedalus_layout_transform_test', 'convert'));
    $components = $section->getComponentsByRegion('content');
    $this->assertCount(2, $components, 'Both regions folded into content.');
    foreach ($section->getComponents() as $component) {
      $this->assertSame('content', $component->getRegion());
    }
  }

  /**
   * An embedded section's replacement is re-encoded into its container.
   */
  public function testEmbeddedSectionReplacementReencodes(): void {
    $nested_uuid = $this->uuid->generate();
    $nested = $this->convertibleSection($nested_uuid);
    $outer = $this->createSection('layout_onecol', [
      $this->createNestedSectionComponent($nested, 'content'),
    ]);
    $sections = [$outer];

    $registry = $this->container->get('daedalus_layout.component_transform_registry');
    $host = EntityTest::create(['name' => 'A host']);
    $this->assertTrue($registry->transformSections($sections, $host));

    $container_component = $sections[0]->getComponent($nested_uuid);
    $embedded = SectionBlock::embeddedSection($container_component);
    $this->assertSame('layout_onecol', $embedded->getLayoutId(), 'The replacement was re-encoded into the container configuration.');
    $this->assertSame($nested_uuid, $embedded->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertCount(2, $embedded->getComponentsByRegion('content'));
  }

  /**
   * A section transform's replacement persists on entity view displays.
   */
  public function testSectionReplacementPersistsOnDisplays(): void {
    $section_uuid = $this->uuid->generate();
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $display->appendSection($this->convertibleSection($section_uuid));
    $display->setSyncing(TRUE);
    $display->save();

    $sandbox = [];
    do {
      $this->container->get('daedalus_layout.entity_view_display_walker')->walk($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);

    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $delta = $this->findSectionDeltaInList($display, $section_uuid);
    $section = $display->getSection($delta);
    $this->assertSame('layout_onecol', $section->getLayoutId());
    $this->assertCount(2, $section->getComponentsByRegion('content'));
  }

  /**
   * Host transforms dirty the host once, after the section walk.
   */
  public function testHostTransformRunsAfterSectionWalk(): void {
    $entity = EntityTest::create([
      'name' => 'Legacy host',
      'layout_builder__layout' => [$this->createSection()],
    ]);
    $entity->setSyncing(TRUE);
    $entity->save();

    $sandbox = [];
    do {
      $this->container->get('daedalus_layout.host_entity_walker')->walk($sandbox);
    } while (empty($sandbox['#finished']) || $sandbox['#finished'] < 1);

    $this->entityTypeManager->getStorage('entity_test')->resetCache();
    $entity = EntityTest::load($entity->id());
    $this->assertSame('Modern host', $entity->label(), 'The host transform dirtied the host and the walker saved it.');

    // Idempotence: a second walk changes nothing.
    $registry = $this->container->get('daedalus_layout.component_transform_registry');
    $this->assertFalse($registry->transformLayoutFields($entity, ['layout']));
  }

  /**
   * The presave net applies section transforms on ordinary saves.
   */
  public function testPresaveNetAppliesSectionTransforms(): void {
    $section_uuid = $this->uuid->generate();
    $entity = EntityTest::create([
      'name' => 'A host',
      'layout_builder__layout' => [$this->convertibleSection($section_uuid)],
    ]);
    // An ordinary (non-syncing) save passes through the presave net.
    $entity->save();

    $this->entityTypeManager->getStorage('entity_test')->resetCache();
    $entity = EntityTest::load($entity->id());
    $section = $entity->get('layout_builder__layout')->getSections()[0];
    $this->assertSame('layout_onecol', $section->getLayoutId());
    $this->assertSame($section_uuid, $section->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

}
