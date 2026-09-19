<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_layout\DaedalusLayoutEntityHelperTrait;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the storage spine's shared defaults->overrides upgrade helper.
 *
 * A not-yet-overridden root entity (its layout field is EMPTY) must yield an
 * OVERRIDES section storage from the render path (DaedalusLayoutEntityHelperTrait,
 * what daedalus_layout publishes to the editing UI) with the same tempstore key the
 * tempstore write path (LayoutTempstoreStrategy) resolves. Before the shared
 * helper, the render path returned the shared bundle display's DEFAULTS
 * storage (rightly rejected by LayoutBuilderPlus::isCompatibleStorage) and
 * keyed the view mode as the literal 'full' while the tempstore keyed the
 * resolved display mode ('default'), so the UI and the staged draft
 * disagreed.
 *
 * @group daedalus_layout
 */
#[RunTestsInSeparateProcesses]
class StorageSpineUpgradeTest extends NestedStorageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node'];

  /**
   * A test consumer of DaedalusLayoutEntityHelperTrait.
   */
  protected object $traitConsumer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->setOverridable()
      ->save();

    $this->traitConsumer = new class() {
      use DaedalusLayoutEntityHelperTrait;

      /**
       * Exposes the trait's protected storage lookup.
       */
      public function storage(EntityInterface $entity, string $view_mode = 'full') {
        return $this->getSectionStorageForEntity($entity, $view_mode);
      }

    };
  }

  /**
   * An empty-layout block_content root upgrades to an overrides storage.
   */
  public function testEmptyLayoutBlockContentYieldsOverridesStorage(): void {
    $block_content = BlockContent::create([
      'type' => 'layout_block',
      'info' => 'Footer',
      'reusable' => TRUE,
    ]);
    $block_content->save();
    $this->assertTrue($block_content->get(OverridesSectionStorage::FIELD_NAME)->isEmpty());

    $this->assertUpgradedStorage($block_content, 'block_content.layout_block.default');
  }

  /**
   * An empty-layout node root upgrades to an overrides storage.
   */
  public function testEmptyLayoutNodeYieldsOverridesStorage(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Empty layout page']);
    $node->save();
    $this->assertTrue($node->get(OverridesSectionStorage::FIELD_NAME)->isEmpty());

    $this->assertUpgradedStorage($node, 'node.page.default');
  }

  /**
   * An already-overridden entity keeps its own sections (no re-seeding).
   */
  public function testOverriddenEntityKeepsOwnSections(): void {
    $section = $this->createSection();
    $node = Node::create([
      'type' => 'page',
      'title' => 'Overridden page',
      OverridesSectionStorage::FIELD_NAME => [$section],
    ]);
    $node->save();

    $storage = $this->traitConsumer->storage($node);
    $this->assertInstanceOf(OverridesSectionStorage::class, $storage);
    $this->assertCount(1, $storage->getSections());
    $this->assertSame(
      $section->getThirdPartySetting('daedalus_layout', 'uuid'),
      $storage->getSections()[0]->getThirdPartySetting('daedalus_layout', 'uuid')
    );
  }

  /**
   * Asserts the upgraded storage shape and key agreement for a root entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A saved entity whose layout field is empty.
   * @param string $display_id
   *   The Layout Builder enabled view display config ID.
   */
  protected function assertUpgradedStorage(EntityInterface $entity, string $display_id): void {
    // The render path yields an overrides storage, the exact shape
    // LayoutBuilderPlus::isCompatibleStorage() accepts, seeded with the
    // bundle display's default sections.
    $storage = $this->traitConsumer->storage($entity);
    $this->assertInstanceOf(OverridesSectionStorage::class, $storage);
    $display = LayoutBuilderEntityViewDisplay::load($display_id);
    $this->assertCount(count($display->getSections()), $storage->getSections());

    // The tempstore write path resolves the SAME storage identity: entity
    // and render-path storage produce one key in the overrides collection.
    $strategy = $this->container->get('daedalus_tempstore.strategy.layout');
    $entity_key = $strategy->getKey($entity);
    $this->assertSame($entity_key, $strategy->getKey($storage));
    $this->assertSame('layout_builder.section_storage.overrides', $strategy->getCollection($entity));
    $this->assertSame('layout_builder.section_storage.overrides', $strategy->getCollection($storage));

    // The view mode context is the RESOLVED display mode, not the requested
    // 'full' literal: these bundles have no 'full' display, so a 'full' key
    // would never match what the tempstore stores.
    $this->assertStringContainsString('.default', $entity_key);
    $this->assertStringNotContainsString('.full', $entity_key);
  }

}
