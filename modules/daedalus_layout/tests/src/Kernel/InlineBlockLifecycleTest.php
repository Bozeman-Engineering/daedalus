<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\BlockContentTypeInterface;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\block_content\Event\BlockContentGetDependencyEvent;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\InlineBlockValues;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\EntityTestHelper;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\InlineBlockEntityOperations as CoreInlineBlockEntityOperations;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the inline-block lifecycle across embedded section depths.
 *
 * Under the sections-all-the-way-down model there is no entity-save cascade
 * reaching nested inline blocks: the root entity's presave is the one save
 * event for the whole tree. This covers the depth-aware walker end to end:
 * post-order presave saves (block_serialized -> block_revision_id at every
 * depth, propagated up into the stored root sections), usage rows pointing
 * at the ROOT host, on-save GC of removed nested blocks, host-delete plus
 * cron GC, access dependency resolution to the root host, defaults
 * display dependency calculation/removal recursion, and the ownership
 * rule: the presave saves what the host OWNS, so a host with no document
 * of its own mints nothing of its template.
 *
 * This is the new-shape replacement for the old-model
 * testInlineBlockEntityOperationsIntegration coverage (nested inline blocks
 * were invisible to core's walker until the daedalus_layout walker existed).
 *
 * @group daedalus_layout
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class InlineBlockLifecycleTest extends NestedStorageKernelTestBase {

  /**
   * Builds a depth-2 nested layout and returns it with its identities.
   *
   * Shape: root section holding a root-level inline block and a container
   * (depth 1) whose embedded section holds an inline block and another
   * container (depth 2) whose embedded section holds an inline block.
   *
   * @return array{
   *   entity: \Drupal\entity_test\Entity\EntityTest,
   *   root_block_uuid: string,
   *   mid_container_uuid: string,
   *   mid_block_uuid: string,
   *   deep_container_uuid: string,
   *   deep_block_uuid: string,
   *   }
   */
  protected function buildDepthTwoEntity(): array {
    $deep_block = $this->createBasicBlockComponent();
    $deep_section = $this->createSection('layout_onecol', [$deep_block]);
    $deep_container = $this->createNestedSectionComponent($deep_section);

    $mid_block = $this->createBasicBlockComponent();
    $mid_section = $this->createSection('layout_onecol', [$mid_block, $deep_container]);
    $mid_container = $this->createNestedSectionComponent($mid_section);

    $root_block = $this->createBasicBlockComponent();
    $root_section = $this->createSection('layout_onecol', [$root_block, $mid_container]);

    $entity = $this->createTestEntity([$root_section]);

    return [
      'entity' => $entity,
      'root_block_uuid' => $root_block->getUuid(),
      'mid_container_uuid' => $mid_container->getUuid(),
      'mid_block_uuid' => $mid_block->getUuid(),
      'deep_container_uuid' => $deep_container->getUuid(),
      'deep_block_uuid' => $deep_block->getUuid(),
    ];
  }

  /**
   * Reads a component's raw stored configuration at any depth.
   *
   * Walks the PERSISTED shape (embedded Section::toArray() values inside
   * container component configuration), which is what proves the post-order
   * write-back reached storage.
   *
   * @param \Drupal\entity_test\Entity\EntityTest $entity
   *   The freshly loaded host entity.
   * @param string[] $container_path
   *   Container component uuids from the root section down.
   * @param string $component_uuid
   *   The component whose configuration to read.
   *
   * @return array
   *   The component configuration.
   */
  protected function storedComponentConfiguration(EntityTest $entity, array $container_path, string $component_uuid): array {
    $sections = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $section_array = $sections[0]->toArray();
    foreach ($container_path as $container_uuid) {
      $section_array = $section_array['components'][$container_uuid]['configuration'][SectionBlock::SECTION_KEY];
      $this->assertIsArray($section_array, "Container $container_uuid embeds a section value.");
    }
    $this->assertArrayHasKey($component_uuid, $section_array['components']);
    return $section_array['components'][$component_uuid]['configuration'];
  }

  /**
   * The usage row for a block content id.
   */
  protected function usageRow(int|string $block_content_id): object|false {
    return \Drupal::database()->select('inline_block_usage', 'u')
      ->fields('u')
      ->condition('block_content_id', $block_content_id)
      ->execute()
      ->fetchObject();
  }

  /**
   * The usage row pointing at a host entity.
   */
  protected function usageRowForHost(EntityInterface $entity): object|false {
    return \Drupal::database()->select('inline_block_usage', 'u')
      ->fields('u')
      ->condition('layout_entity_type', $entity->getEntityTypeId())
      ->condition('layout_entity_id', $entity->id())
      ->execute()
      ->fetchObject();
  }

  /**
   * An inline block component stored as values, a template's shape.
   */
  protected function valueBlockComponent(string $uuid, string $info): SectionComponent {
    return new SectionComponent($uuid, 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Basic block',
      'label_display' => '0',
      'view_mode' => 'full',
      InlineBlockValues::KEY => ['info' => $info],
    ]);
  }

  /**
   * A component's configuration on the display as the request caches it.
   *
   * Read through the entity type manager, the same static-cached object
   * core's render rule would have handed the presave: a walk of the
   * template mutates these Section objects in memory for the rest of the
   * request.
   */
  protected function cachedDisplayComponentConfiguration(string $display_id, string $component_uuid): array {
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load($display_id);
    foreach ($display->getSections() as $section) {
      if (array_key_exists($component_uuid, $section->getComponents())) {
        return $section->getComponent($component_uuid)->get('configuration');
      }
    }
    $this->fail("The display $display_id holds no component $component_uuid.");
  }

  /**
   * A save of a host that owns no document mints nothing of its template.
   *
   * The presave walks what the host OWNS, never what renders for it. A
   * page following its template (an empty override on an overridable
   * bundle), a plain host (a bundle whose display is not overridable) and a
   * blank page (core's marker) each save, twice, with the template's value
   * block left on the display: no block_content minted, no usage row at
   * the page, the cached display's component still values. The page's
   * first edit seeds its own document, and that save mints exactly one
   * block, the page's own, with its usage row and its revision id in the
   * stored override (the duplicate-on-first-document road).
   */
  public function testSaveOfTemplateFollowingHostMintsNothing(): void {
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $display_storage = $this->entityTypeManager->getStorage('entity_view_display');
    $count = fn (): int => (int) $block_storage->getQuery()->accessCheck(FALSE)->count()->execute();

    // The template: one section holding a value block.
    $template_uuid = $this->uuid->generate();
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $display_storage->load('entity_test.entity_test.default');
    $display->appendSection($this->createSection('layout_onecol', [$this->valueBlockComponent($template_uuid, 'Template block')]));
    $display->save();
    $this->assertSame(0, $count(), 'The display stores its block as values: nothing minted.');

    // A page following its template: an empty override, saved twice.
    $entity = EntityTest::create(['name' => 'Follows the template']);
    $entity->save();
    $entity->save();
    $this->assertSame(0, $count(), 'A template-following page mints nothing of the template.');
    $this->assertFalse($this->usageRowForHost($entity), 'No usage row points at the template-following page.');
    $configuration = $this->cachedDisplayComponentConfiguration('entity_test.entity_test.default', $template_uuid);
    $this->assertSame('Template block', $configuration[InlineBlockValues::KEY]['info'], 'The cached display still carries its values.');
    $this->assertArrayNotHasKey('block_revision_id', $configuration, 'The cached display names no block revision.');

    // The first edit: the seed, then the save that lands it.
    $seeded = $this->host($entity)->sectionStorage()->getSections();
    $this->assertCount(1, $seeded, 'The first read of the document seeds it from the template.');
    $entity->save();
    $this->assertSame(1, $count(), 'The first edit saves exactly one block: the page\'s own.');
    $row = $this->usageRowForHost($entity);
    $this->assertNotFalse($row, 'The seeded page\'s block is used by the page.');
    $stored = EntityTest::load($entity->id())->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $this->assertCount(1, $stored);
    $components = $stored[0]->getComponents();
    $this->assertCount(1, $components);
    $stored_configuration = reset($components)->get('configuration');
    $this->assertNotEmpty($stored_configuration['block_revision_id'], 'The stored override carries the block\'s revision id.');
    $this->assertArrayNotHasKey(InlineBlockValues::KEY, $stored_configuration, 'The page holds an entity, not values.');
    $this->assertSame((string) $block_storage->loadRevision($stored_configuration['block_revision_id'])->id(), (string) $row->block_content_id, 'The usage row names the stored block.');
    $configuration = $this->cachedDisplayComponentConfiguration('entity_test.entity_test.default', $template_uuid);
    $this->assertArrayNotHasKey('block_revision_id', $configuration, 'The template is untouched by the page\'s first save.');

    // A plain host: a bundle whose display is Layout Builder enabled and
    // not overridable renders the template and owns nothing of its own
    // inside it.
    EntityTestHelper::createBundle('plain', 'Plain', 'entity_test');
    $plain_uuid = $this->uuid->generate();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'plain',
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->appendSection($this->createSection('layout_onecol', [$this->valueBlockComponent($plain_uuid, 'Plain template block')]))
      ->save();
    $plain = EntityTest::create(['type' => 'plain', 'name' => 'Plain']);
    $plain->save();
    $plain->save();
    $this->assertTrue($this->host($plain)->hasDocument(), 'A plain host owns a document (the override field is on every enabled bundle), empty here.');
    $this->assertSame([], $this->host($plain)->ownSections(), 'A plain host is never seeded from its template.');
    $this->assertSame(1, $count(), 'A plain host mints nothing of its template.');
    $this->assertFalse($this->usageRowForHost($plain), 'No usage row points at the plain host.');
    $configuration = $this->cachedDisplayComponentConfiguration('entity_test.plain.default', $plain_uuid);
    $this->assertSame('Plain template block', $configuration[InlineBlockValues::KEY]['info'], 'The plain bundle\'s cached display still carries its values.');

    // Blank: core's marker is an owned, empty document.
    $blank = EntityTest::create([
      'name' => 'Blank',
      OverridesSectionStorage::FIELD_NAME => [new Section('layout_builder_blank')],
    ]);
    $blank->save();
    $blank->save();
    $this->assertSame(1, $count(), 'A blank page mints nothing.');
    $this->assertFalse($this->usageRowForHost($blank), 'No usage row points at the blank page.');
  }

  /**
   * Presave saves nested inline blocks and propagates config to storage.
   */
  public function testPresaveWalkerSavesNestedInlineBlocks(): void {
    $ids = $this->buildDepthTwoEntity();
    $entity = $ids['entity'];

    $root_config = $this->storedComponentConfiguration($entity, [], $ids['root_block_uuid']);
    $mid_config = $this->storedComponentConfiguration($entity, [$ids['mid_container_uuid']], $ids['mid_block_uuid']);
    $deep_config = $this->storedComponentConfiguration($entity, [
      $ids['mid_container_uuid'],
      $ids['deep_container_uuid'],
    ], $ids['deep_block_uuid']);

    foreach (['root' => $root_config, 'mid' => $mid_config, 'deep' => $deep_config] as $depth => $configuration) {
      $this->assertNotEmpty($configuration['block_revision_id'], "The $depth inline block was saved and its revision id reached the stored root sections.");
      $this->assertEmpty($configuration['block_serialized'], "The $depth inline block no longer rides serialized.");
    }

    // Three distinct persisted block_content entities.
    $revision_ids = [
      $root_config['block_revision_id'],
      $mid_config['block_revision_id'],
      $deep_config['block_revision_id'],
    ];
    $this->assertCount(3, array_unique($revision_ids));

    // Usage rows point at the ROOT host at every depth.
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    foreach ($revision_ids as $revision_id) {
      $block = $block_storage->loadRevision($revision_id);
      $this->assertNotNull($block);
      $row = $this->usageRow($block->id());
      $this->assertNotFalse($row, 'A usage row exists.');
      $this->assertSame('entity_test', $row->layout_entity_type);
      $this->assertSame((string) $entity->id(), (string) $row->layout_entity_id, 'The usage row points at the root host.');
    }
  }

  /**
   * On-save GC of removed nested blocks, where core has that path at all.
   *
   * Core's removeUnusedForEntityOnSave() early-returns for every content
   * entity (ContentEntityBase implements RevisionableInterface), so the
   * on-save GC path exists only for config-entity hosts: defaults displays.
   * This asserts BOTH sides at depth: the content-entity host keeps the
   * removed block (exact core parity with root-level behavior; cleanup is
   * host delete plus cron), and the defaults display deletes it on save via
   * the depth-aware removed-revision diff.
   */
  public function testRemovedNestedInlineBlockOnSaveGc(): void {
    // Content-entity host: the removed nested block survives the save, as
    // it does for root-level blocks in core.
    $ids = $this->buildDepthTwoEntity();
    $entity = $ids['entity'];
    $deep_config = $this->storedComponentConfiguration($entity, [
      $ids['mid_container_uuid'],
      $ids['deep_container_uuid'],
    ], $ids['deep_block_uuid']);
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $deep_block_id = $block_storage->loadRevision($deep_config['block_revision_id'])->id();

    $sections = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections();
    $mid_component = $sections[0]->getComponent($ids['mid_container_uuid']);
    $mid_section = Section::fromArray($mid_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $deep_component = $mid_section->getComponent($ids['deep_container_uuid']);
    $deep_section = Section::fromArray($deep_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $deep_section->removeComponent($ids['deep_block_uuid']);
    $deep_configuration = $deep_component->get('configuration');
    $deep_configuration[SectionBlock::SECTION_KEY] = $deep_section->toArray();
    $deep_component->setConfiguration($deep_configuration);
    $mid_configuration = $mid_component->get('configuration');
    $mid_configuration[SectionBlock::SECTION_KEY] = $mid_section->toArray();
    $mid_component->setConfiguration($mid_configuration);
    $entity->save();

    $block_storage->resetCache();
    $this->assertNotNull($block_storage->load($deep_block_id), 'Content-entity hosts keep removed blocks on save (core parity); host delete plus cron cleans up.');

    // Defaults display host: a display stores its inline blocks as values
    // (a display is config; its export carries no content id), so a block
    // it names by revision id (the shape before the display was a host) is
    // converted at save, at depth 2, and the on-save GC path deletes the
    // entity the display no longer names.
    $saved_block = BlockContent::create([
      'type' => 'basic',
      'info' => 'Saved display block',
      'reusable' => FALSE,
    ]);
    $saved_block->save();
    // The pre-host shape lands through a syncing save (a config import),
    // which neither the presave net nor the inline block machinery touches.
    $display_ids = $this->buildDefaultsDisplay(['block_revision_id' => $saved_block->getRevisionId()], syncing: TRUE);
    /** @var \Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test.entity_test.default');
    $this->assertStringContainsString('block_revision_id', serialize($display->toArray()), 'The syncing save kept the revision id verbatim.');
    // An ordinary save converts.
    $display->save();
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test.entity_test.default');
    $display_mid = $display->getSections()[0]->getComponent($display_ids['mid_container_uuid']);
    $display_mid_section = Section::fromArray($display_mid->get('configuration')[SectionBlock::SECTION_KEY]);
    $display_deep = $display_mid_section->getComponent($display_ids['deep_container_uuid']);
    $display_deep_section = Section::fromArray($display_deep->get('configuration')[SectionBlock::SECTION_KEY]);
    $inline_configuration = $display_deep_section->getComponent($display_ids['inline_uuid'])->get('configuration');
    $this->assertArrayNotHasKey('block_revision_id', $inline_configuration, 'The display names no block revision after its save.');
    $this->assertSame('Saved display block', $inline_configuration[InlineBlockValues::KEY]['info'], 'The saved block became values at depth 2.');

    $block_storage->resetCache();
    $this->assertNull($block_storage->load($saved_block->id()), 'The defaults display deleted the depth-2 inline block entity it no longer names.');
    $this->assertFalse($this->usageRow($saved_block->id()), 'Its usage row is gone.');
  }

  /**
   * Deleting the host unhooks all rows; cron GC deletes the blocks.
   */
  public function testHostDeleteAndCronGc(): void {
    $ids = $this->buildDepthTwoEntity();
    $entity = $ids['entity'];

    $deep_config = $this->storedComponentConfiguration($entity, [
      $ids['mid_container_uuid'],
      $ids['deep_container_uuid'],
    ], $ids['deep_block_uuid']);
    $mid_config = $this->storedComponentConfiguration($entity, [$ids['mid_container_uuid']], $ids['mid_block_uuid']);
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $deep_block_id = $block_storage->loadRevision($deep_config['block_revision_id'])->id();
    $mid_block_id = $block_storage->loadRevision($mid_config['block_revision_id'])->id();

    $entity->delete();

    // Rows point at the root host, so one removeByLayoutEntity() covers the
    // whole tree: rows remain but are marked unused (host columns nulled).
    foreach ([$deep_block_id, $mid_block_id] as $block_id) {
      $row = $this->usageRow($block_id);
      $this->assertNotFalse($row);
      $this->assertNull($row->layout_entity_id, 'The row is marked unused after host delete.');
    }

    // The cron GC path (hook_cron -> removeUnused) deletes the blocks and
    // their rows.
    \Drupal::classResolver(CoreInlineBlockEntityOperations::class)->removeUnused();
    $block_storage->resetCache();
    foreach ([$deep_block_id, $mid_block_id] as $block_id) {
      $this->assertNull($block_storage->load($block_id), 'Cron GC deleted the orphaned nested block.');
      $this->assertFalse($this->usageRow($block_id), 'Cron GC deleted the usage row.');
    }
  }

  /**
   * A nested inline block's access dependency resolves to the root host.
   */
  public function testAccessDependencyResolvesToRootHost(): void {
    $ids = $this->buildDepthTwoEntity();
    $entity = $ids['entity'];

    $deep_config = $this->storedComponentConfiguration($entity, [
      $ids['mid_container_uuid'],
      $ids['deep_container_uuid'],
    ], $ids['deep_block_uuid']);
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $deep_block = $block_storage->loadRevision($deep_config['block_revision_id']);

    $event = new BlockContentGetDependencyEvent($deep_block);
    \Drupal::service('event_dispatcher')->dispatch($event, 'block_content.get_dependency');

    $dependency = $event->getAccessDependency();
    $this->assertNotNull($dependency, 'A dependency was resolved: the usage row points at the root and the depth-aware section walk found the revision.');
    $this->assertInstanceOf(EntityTest::class, $dependency);
    $this->assertSame($entity->id(), $dependency->id());
  }

  /**
   * Builds a defaults display carrying a depth-2 nested layout.
   *
   * Root section: a container (depth 1) embedding a section with a static
   * plugin block and another container (depth 2) embedding a section with an
   * inline block.
   *
   * @param array $block_configuration
   *   The inline block's storage shape: block_serialized of a fresh block
   *   by default, or a block_revision_id naming a saved one.
   * @param bool $syncing
   *   TRUE to save as a synchronization (a config import), which lands the
   *   given shape verbatim: no presave net, no inline block machinery.
   *
   * @return array{
   *   display: \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay,
   *   mid_container_uuid: string,
   *   deep_container_uuid: string,
   *   inline_uuid: string,
   *   static_uuid: string,
   *   }
   */
  protected function buildDefaultsDisplay(array $block_configuration = [], bool $syncing = FALSE): array {
    if ($block_configuration === []) {
      $block_content = BlockContent::create([
        'type' => 'basic',
        'info' => 'Defaults basic block',
        'reusable' => FALSE,
      ]);
      $block_configuration = ['block_serialized' => serialize($block_content)];
    }
    $inline_uuid = $this->uuid->generate();
    $inline = new SectionComponent($inline_uuid, 'content', [
      'id' => 'inline_block:basic',
      'label' => 'Basic block',
      // Strict config schema: label_display is a string on the defaults
      // path, never a boolean.
      'label_display' => '0',
      'view_mode' => 'full',
    ] + $block_configuration);

    $deep_section = $this->createSection('layout_onecol', [$inline]);
    $deep_container = $this->createNestedSectionComponent($deep_section);

    $static_uuid = $this->uuid->generate();
    $static = new SectionComponent($static_uuid, 'content', [
      'id' => 'system_powered_by_block',
      'label' => 'Powered by',
      'label_display' => '0',
    ]);

    $mid_section = $this->createSection('layout_onecol', [$static, $deep_container]);
    $mid_container = $this->createNestedSectionComponent($mid_section);

    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load('entity_test.entity_test.default');
    $display->appendSection($this->createSection('layout_onecol', [$mid_container]));
    $display->setSyncing($syncing)->save();
    $display->setSyncing(FALSE);

    return [
      'display' => $display,
      'mid_container_uuid' => $mid_container->getUuid(),
      'deep_container_uuid' => $deep_container->getUuid(),
      'inline_uuid' => $inline_uuid,
      'static_uuid' => $static_uuid,
    ];
  }

  /**
   * Defaults display dependencies include nested component dependencies.
   *
   * Also exercises the presave walker on the DEFAULTS storage: saving the
   * display stores the nested inline block as values (a display host's
   * shape: the block's fields under block_values, no entity, no revision
   * id), written back through both containers.
   */
  public function testDefaultsDisplayCalculateDependencies(): void {
    $ids = $this->buildDefaultsDisplay();

    /** @var \Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test.entity_test.default');

    // The walker ran for the defaults storage: the nested inline block was
    // stored as values and the values written back through both containers.
    $mid_component = $display->getSections()[0]->getComponent($ids['mid_container_uuid']);
    $mid_section = Section::fromArray($mid_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $deep_component = $mid_section->getComponent($ids['deep_container_uuid']);
    $deep_section = Section::fromArray($deep_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $inline_configuration = $deep_section->getComponent($ids['inline_uuid'])->get('configuration');
    $this->assertSame('Defaults basic block', $inline_configuration[InlineBlockValues::KEY]['info'], 'The nested inline block in the defaults display was stored as values.');
    foreach (InlineBlockValues::ENTITY_KEYS as $key) {
      $this->assertArrayNotHasKey($key, $inline_configuration, "The display names no block entity ($key).");
    }

    $dependencies = $display->calculateDependencies()->getDependencies();
    $this->assertContains('block_content.type.basic', $dependencies['config'] ?? [], 'A dependency of a depth-2 component reaches the display through the container aggregation.');
    $this->assertContains('system', $dependencies['module'] ?? [], 'The depth-1 static plugin block provider is a display dependency.');
  }

  /**
   * Dependency removal prunes surgically at depth.
   *
   * A removed dependency used by one nested component removes THAT
   * component, not the containers above it (core's blanket walk would drop
   * the whole depth-1 container, because containers aggregate their
   * subtree's dependencies).
   */
  public function testDefaultsDisplayOnDependencyRemovalPrunesSurgically(): void {
    $ids = $this->buildDefaultsDisplay();

    /** @var \Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->loadUnchanged('entity_test.entity_test.default');

    $basic_type = BlockContentType::load('basic');
    $this->assertInstanceOf(BlockContentTypeInterface::class, $basic_type);
    $changed = $display->onDependencyRemoval([
      'config' => [$basic_type->getConfigDependencyName() => $basic_type],
      'content' => [],
      'module' => [],
      'theme' => [],
    ]);
    $this->assertTrue($changed);

    // Both containers survive; only the inline block is gone.
    $mid_component = $display->getSections()[0]->getComponent($ids['mid_container_uuid']);
    $mid_section = Section::fromArray($mid_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $this->assertArrayHasKey($ids['static_uuid'], $mid_section->getComponents(), 'The unrelated depth-1 block survives.');
    $deep_component = $mid_section->getComponent($ids['deep_container_uuid']);
    $deep_section = Section::fromArray($deep_component->get('configuration')[SectionBlock::SECTION_KEY]);
    $this->assertArrayNotHasKey($ids['inline_uuid'], $deep_section->getComponents(), 'The offending depth-2 component was pruned.');

    // The pruned tree no longer carries the removed dependency.
    $dependencies = $display->calculateDependencies()->getDependencies();
    $this->assertNotContains('block_content.type.basic', $dependencies['config'] ?? []);
  }

  /**
   * A nested section whose layout goes away goes away WITH its container.
   */
  public function testDefaultsDisplayOnDependencyRemovalRemovesContainerWithLayout(): void {
    $this->enableModules(['layout_test']);

    $inline_uuid = $this->uuid->generate();
    $static = new SectionComponent($inline_uuid, 'top', [
      'id' => 'system_powered_by_block',
      'label' => 'Powered by',
      'label_display' => '0',
    ]);
    $embedded = $this->createSection('layout_test_1col', [$static]);
    $container = $this->createNestedSectionComponent($embedded);

    /** @var \Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = $this->entityTypeManager->getStorage('entity_view_display')->load('entity_test.entity_test.default');
    $display->appendSection($this->createSection('layout_onecol', [$container]));

    $changed = $display->onDependencyRemoval([
      'config' => [],
      'content' => [],
      'module' => ['layout_test'],
      'theme' => [],
    ]);
    $this->assertTrue($changed);

    // The embedded layout's removal removes the nested section, and with it
    // the container component (an empty container is not a state); the
    // root section itself survives, its own layout being unaffected.
    $sections = $display->getSections();
    $root_section = end($sections);
    $this->assertArrayNotHasKey($container->getUuid(), $root_section->getComponents(), 'The container went away with its embedded layout.');
  }

}
