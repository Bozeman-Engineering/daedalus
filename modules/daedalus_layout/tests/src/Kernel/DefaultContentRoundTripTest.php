<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DefaultContent\Existing;
use Drupal\Core\DefaultContent\Exporter;
use Drupal\Core\DefaultContent\Finder;
use Drupal\Core\DefaultContent\Importer;
use Drupal\layout_builder\InlineBlockEntityOperations as CoreInlineBlockEntityOperations;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;

/**
 * Tests the default-content round trip for layouts with inline blocks.
 *
 * Core's default content machinery only handles REUSABLE block components
 * (the plugin id carries the uuid); inline blocks ride on site-local
 * block_id/block_revision_id and break on import. The daedalus_layout
 * DefaultContentSubscriber supersedes core's field_item:layout_section
 * export callback so inline blocks export by uuid + dependency and resolve
 * back to freshly imported ids at import, including inside embedded
 * (nested) sections, with inline_block_usage rows recorded for the imported
 * host despite the syncing save skipping handlePreSave().
 *
 * @group daedalus_layout
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class DefaultContentRoundTripTest extends NestedStorageKernelTestBase {

  /**
   * The export destination directory.
   */
  protected const DESTINATION = 'public://default-content-round-trip';

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
   * Reads a component configuration from a stored/exported section array.
   *
   * @param array $section
   *   A Section::toArray() value.
   * @param string[] $container_path
   *   Container component uuids from the root section down.
   * @param string $component_uuid
   *   The component whose configuration to read.
   *
   * @return array
   *   The component configuration.
   */
  protected function componentConfiguration(array $section, array $container_path, string $component_uuid): array {
    foreach ($container_path as $container_uuid) {
      $section = $section['components'][$container_uuid]['configuration'][SectionBlock::SECTION_KEY];
      $this->assertIsArray($section, "Container $container_uuid embeds a section value.");
    }
    $this->assertArrayHasKey($component_uuid, $section['components']);
    return $section['components'][$component_uuid]['configuration'];
  }

  /**
   * Export with inline blocks, import on clean state, everything resolves.
   */
  public function testInlineBlockRoundTrip(): void {
    $block_storage = $this->entityTypeManager->getStorage('block_content');

    // A reusable component proves core's dependency behavior is kept by the
    // superseding callback. The derivative only exists for discoverable
    // reusable entities, so the block plugin definitions must be cleared.
    $reusable = BlockContent::create([
      'type' => 'basic',
      'info' => 'Reusable round trip block',
      'reusable' => TRUE,
    ]);
    $reusable->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();
    $reusable_component = new SectionComponent($this->uuid->generate(), 'content', [
      'id' => 'block_content:' . $reusable->uuid(),
      'label' => 'Reusable block',
      'label_display' => FALSE,
    ]);

    // Shape: root section holding an inline block, a reusable block, and a
    // container whose embedded section holds another inline block, so the
    // round trip is proven at depth too.
    $nested_inline = $this->createBasicBlockComponent();
    $nested_section = $this->createSection('layout_onecol', [$nested_inline]);
    $container = $this->createNestedSectionComponent($nested_section);
    $root_inline = $this->createBasicBlockComponent();
    $root_section = $this->createSection('layout_onecol', [$root_inline, $container, $reusable_component]);
    $entity = $this->createTestEntity([$root_section]);

    // The fixture save persisted the inline blocks; capture their identities.
    $stored = $entity->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0]->toArray();
    $root_config = $this->componentConfiguration($stored, [], $root_inline->getUuid());
    $nested_config = $this->componentConfiguration($stored, [$container->getUuid()], $nested_inline->getUuid());
    $root_block = $block_storage->loadRevision($root_config['block_revision_id']);
    $nested_block = $block_storage->loadRevision($nested_config['block_revision_id']);
    $old_ids = [
      'host' => $entity->id(),
      'root_block' => $root_block->id(),
      'nested_block' => $nested_block->id(),
    ];

    // Export the host with its dependencies.
    $this->container->get(Exporter::class)->exportWithDependencies($entity, self::DESTINATION);

    $exported = Yaml::decode(file_get_contents(self::DESTINATION . '/entity_test/' . $entity->uuid() . '.yml'));

    // All three block_content entities are dependencies.
    $depends = $exported['_meta']['depends'] ?? [];
    foreach ([$root_block, $nested_block, $reusable] as $block) {
      $this->assertSame('block_content', $depends[$block->uuid()] ?? NULL, "Block {$block->label()} is an export dependency.");
      $this->assertFileExists(self::DESTINATION . '/block_content/' . $block->uuid() . '.yml');
    }

    // Inline components carry a portable block_uuid and no site-local keys,
    // at the root and inside the embedded section.
    $exported_section = $exported['default'][OverridesSectionStorage::FIELD_NAME][0]['section'];
    $exported_root = $this->componentConfiguration($exported_section, [], $root_inline->getUuid());
    $exported_nested = $this->componentConfiguration($exported_section, [$container->getUuid()], $nested_inline->getUuid());
    $exports = [
      'root' => [$exported_root, $root_block],
      'nested' => [$exported_nested, $nested_block],
    ];
    foreach ($exports as $depth => [$configuration, $block]) {
      $this->assertSame($block->uuid(), $configuration['block_uuid'] ?? NULL, "The $depth inline component exports its block by uuid.");
      foreach (['block_id', 'block_revision_id', 'block_serialized'] as $key) {
        $this->assertArrayNotHasKey($key, $configuration, "The $depth inline component export carries no site-local $key.");
      }
    }

    // The reusable component is untouched: the uuid stays in the plugin id.
    $exported_reusable = $this->componentConfiguration($exported_section, [], $reusable_component->getUuid());
    $this->assertSame('block_content:' . $reusable->uuid(), $exported_reusable['id']);
    $this->assertArrayNotHasKey('block_uuid', $exported_reusable);

    // Wipe to a clean state: host, blocks, and usage rows all gone.
    $entity->delete();
    $block_storage->delete([$root_block, $nested_block, $reusable]);
    \Drupal::database()->truncate('inline_block_usage')->execute();
    $block_storage->resetCache();

    // Import. Existing::Skip matches how recipes import default content;
    // the exported host owner still exists and must be skipped cleanly.
    $finder = new Finder($this->container->get('file_system')->realpath(self::DESTINATION));
    $this->container->get(Importer::class)->importContent($finder, Existing::Skip);

    $repository = $this->container->get('entity.repository');
    $new_host = $repository->loadEntityByUuid('entity_test', $entity->uuid());
    $this->assertNotNull($new_host, 'The host was imported.');
    $this->assertNotSame((string) $old_ids['host'], (string) $new_host->id(), 'The imported host has a new id.');

    // The sections resolve to the NEW block ids at both depths and the
    // portable block_uuid marker is gone.
    $imported = $new_host->get(OverridesSectionStorage::FIELD_NAME)->getSections()[0]->toArray();
    $imported_root = $this->componentConfiguration($imported, [], $root_inline->getUuid());
    $imported_nested = $this->componentConfiguration($imported, [$container->getUuid()], $nested_inline->getUuid());
    $new_blocks = [];
    $imports = [
      'root' => [$imported_root, $root_block->uuid(), $old_ids['root_block']],
      'nested' => [
        $imported_nested,
        $nested_block->uuid(),
        $old_ids['nested_block'],
      ],
    ];
    foreach ($imports as $depth => [$configuration, $uuid, $old_id]) {
      $this->assertArrayNotHasKey('block_uuid', $configuration, "The $depth inline component no longer carries block_uuid after import.");
      $this->assertNotEmpty($configuration['block_revision_id'], "The $depth inline component resolved to a revision id.");
      $new_block = $block_storage->loadRevision($configuration['block_revision_id']);
      $this->assertNotNull($new_block, "The $depth inline component's revision id loads.");
      $this->assertSame($uuid, $new_block->uuid(), "The $depth component resolved to the re-imported block.");
      $this->assertSame((string) $new_block->id(), (string) $configuration['block_id'], "The $depth component's block_id matches.");
      $this->assertNotSame((string) $old_id, (string) $new_block->id(), "The $depth block has a new id, so resolution was real.");
      $new_blocks[$depth] = $new_block;
    }

    // The reusable block round-tripped as itself.
    $new_reusable = $repository->loadEntityByUuid('block_content', $reusable->uuid());
    $this->assertNotNull($new_reusable, 'The reusable block was imported.');
    $this->assertTrue($new_reusable->isReusable());

    // Usage rows exist for the imported inline blocks and point at the new
    // host, despite the syncing import save skipping handlePreSave().
    foreach ($new_blocks as $depth => $new_block) {
      $row = $this->usageRow($new_block->id());
      $this->assertNotFalse($row, "A usage row exists for the imported $depth inline block.");
      $this->assertSame('entity_test', $row->layout_entity_type);
      $this->assertSame((string) $new_host->id(), (string) $row->layout_entity_id, "The $depth usage row points at the imported host.");
    }
    $this->assertFalse($this->usageRow($new_reusable->id()), 'Reusable blocks get no usage row.');

    // The cron GC keeps the imported blocks: their rows are in use.
    \Drupal::classResolver(CoreInlineBlockEntityOperations::class)->removeUnused();
    $block_storage->resetCache();
    foreach ($new_blocks as $depth => $new_block) {
      $this->assertNotNull($block_storage->load($new_block->id()), "GC keeps the imported $depth inline block.");
    }

    // A second import converges as a no-op (the recipe re-apply case).
    $count_before = count($block_storage->getQuery()->accessCheck(FALSE)->execute());
    $this->container->get(Importer::class)->importContent($finder, Existing::Skip);
    $this->assertCount($count_before, $block_storage->getQuery()->accessCheck(FALSE)->execute(), 'Re-import creates no duplicate blocks.');
  }

}
