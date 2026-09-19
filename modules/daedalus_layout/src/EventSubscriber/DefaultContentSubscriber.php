<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\DefaultContent\ExportMetadata;
use Drupal\Core\DefaultContent\ImportException;
use Drupal\Core\DefaultContent\PreEntityImportEvent;
use Drupal\Core\DefaultContent\PreExportEvent;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\layout_builder\Plugin\Field\FieldType\LayoutSectionItem;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\InlineBlockEntityOperations;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Completes the default-content round trip for layouts with inline blocks.
 *
 * Core's layout_builder DefaultContentSubscriber handles only REUSABLE block
 * components: their plugin id carries the block_content uuid, so exporting
 * them as dependencies is all import needs. INLINE blocks are referenced by
 * site-local block_id/block_revision_id inside component configuration and
 * break on import. This subscriber supersedes core's
 * `field_item:layout_section` export callback (PreExportEvent callbacks are a
 * last-writer-wins map, so this subscriber runs at a lower priority) and:
 * - keeps core's reusable-component dependency behavior;
 * - exports inline_block components by uuid: the block_content entity becomes
 *   an export dependency, and the component configuration drops
 *   block_id/block_revision_id/block_serialized in favor of a portable
 *   block_uuid key;
 * - recurses into embedded sections (daedalus_layout_section containers), which
 *   core's one-level callback never sees, so nested inline AND reusable
 *   components round-trip too.
 *
 * At import, before the entity is created, block_uuid keys are resolved back
 * to the freshly imported block_content's block_id/block_revision_id
 * (dependencies import first via _meta.depends ordering, so
 * loadEntityByUuid() resolves at host-import time).
 *
 * Imported hosts save with isSyncing() TRUE, which makes
 * InlineBlockEntityOperations::handlePreSave() a no-op: nothing records
 * inline_block_usage rows for the imported host. Those rows are load-bearing
 * (non-reusable block access resolves through them, and host-delete GC marks
 * them unused), so import resolution also flags each resolved block via
 * InlineBlockEntityOperations::flagUsage(); the rows are recorded by
 * trackInlineBlockUsage() once the host insert completes.
 *
 * The walkers here operate on the exported/imported configuration arrays
 * directly rather than instantiated plugins: at export/import time a
 * component's plugin may not be instantiable (a reusable block's derivative
 * only exists for discoverable entities), and the arrays are what gets
 * rewritten anyway.
 */
final class DefaultContentSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly EntityRepositoryInterface $entityRepository,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ClassResolverInterface $classResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Must run AFTER core layout_builder's DefaultContentSubscriber
      // (priority 0): setCallback() is last-writer-wins per key, and this
      // callback supersedes core's for field_item:layout_section.
      PreExportEvent::class => ['preExport', -100],
      PreEntityImportEvent::class => 'preEntityImport',
    ];
  }

  /**
   * Registers the layout_section export callback.
   *
   * @param \Drupal\Core\DefaultContent\PreExportEvent $event
   *   The event object.
   */
  public function preExport(PreExportEvent $event): void {
    $event->setCallback('field_item:layout_section', function (LayoutSectionItem $item, ExportMetadata $metadata): array {
      $section = $item->get('section')->getValue();
      assert($section instanceof Section);
      $section_array = $section->toArray();
      $this->prepareSectionForExport($section_array, $metadata);
      return [
        'section' => $section_array,
      ];
    });
  }

  /**
   * Makes a section array portable, recursing into embedded sections.
   *
   * @param array $section
   *   A Section::toArray() value; modified by reference.
   * @param \Drupal\Core\DefaultContent\ExportMetadata $metadata
   *   The export metadata collecting dependencies.
   */
  private function prepareSectionForExport(array &$section, ExportMetadata $metadata): void {
    if (empty($section['components'])) {
      return;
    }
    foreach ($section['components'] as &$component) {
      $configuration = &$component['configuration'];
      $plugin_id = $configuration['id'] ?? '';

      // Core behavior, kept: reusable components carry the uuid in the
      // plugin id, so the entity only needs to become a dependency.
      if (str_starts_with($plugin_id, 'block_content:')) {
        $block_content = $this->entityRepository->loadEntityByUuid('block_content', substr($plugin_id, strlen('block_content:')));
        if ($block_content) {
          $metadata->addDependency($block_content);
        }
      }
      elseif (str_starts_with($plugin_id, 'inline_block:')) {
        $block_content = $this->resolveInlineBlockForExport($configuration);
        $metadata->addDependency($block_content);
        unset($configuration['block_id'], $configuration['block_revision_id'], $configuration['block_serialized']);
        $configuration['block_uuid'] = $block_content->uuid();
      }
      elseif ($plugin_id === SectionBlock::PLUGIN_ID && is_array($configuration[SectionBlock::SECTION_KEY] ?? NULL)) {
        $this->prepareSectionForExport($configuration[SectionBlock::SECTION_KEY], $metadata);
      }
    }
  }

  /**
   * Resolves an inline_block component's block_content entity for export.
   *
   * The dependency file itself is exported from storage by uuid (default
   * revision), so which handle resolves here only decides the recorded uuid;
   * they all point at the same entity.
   *
   * @param array $configuration
   *   The inline_block component configuration.
   *
   * @return \Drupal\block_content\BlockContentInterface
   *   The block content entity.
   */
  private function resolveInlineBlockForExport(array $configuration): BlockContentInterface {
    $storage = $this->entityTypeManager->getStorage('block_content');
    $block_content = NULL;
    if (!empty($configuration['block_revision_id'])) {
      $block_content = $storage->loadRevision($configuration['block_revision_id']);
    }
    elseif (!empty($configuration['block_id'])) {
      $block_content = $storage->load($configuration['block_id']);
    }
    elseif (!empty($configuration['block_serialized'])) {
      // Layout Builder's own inline-block payload (InlineBlock::blockSubmit()).
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
      $serialized = unserialize($configuration['block_serialized'], ['allowed_classes' => TRUE]);
      // Only a persisted entity can be exported as a dependency: the
      // dependency export loads it from storage by uuid, and an unsaved
      // serialized copy is not there to load.
      if ($serialized instanceof BlockContentInterface && !$serialized->isNew()) {
        $block_content = $storage->load($serialized->id());
      }
    }
    if (!$block_content instanceof BlockContentInterface) {
      throw new \RuntimeException(sprintf('The inline block component "%s" cannot be exported as default content because its block_content entity could not be resolved. Save the host entity so unsaved inline blocks are persisted before exporting.', $configuration['id'] ?? 'unknown'));
    }
    return $block_content;
  }

  /**
   * Resolves block_uuid references in layout fields before import.
   *
   * @param \Drupal\Core\DefaultContent\PreEntityImportEvent $event
   *   The event object.
   */
  public function preEntityImport(PreEntityImportEvent $event): void {
    $entity_type_id = $event->metadata['entity_type'];
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    if (!$entity_type->entityClassImplements(FieldableEntityInterface::class)) {
      return;
    }

    $bundle = $event->metadata['bundle'] ?? $entity_type_id;
    foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $field_name => $definition) {
      if ($definition->getType() !== 'layout_section') {
        continue;
      }
      if (isset($event->data['default'][$field_name])) {
        $this->resolveItemsForImport($event->data['default'][$field_name], $entity_type_id, $event->metadata['uuid']);
      }
      foreach (array_keys($event->data['translations'] ?? []) as $langcode) {
        if (isset($event->data['translations'][$langcode][$field_name])) {
          $this->resolveItemsForImport($event->data['translations'][$langcode][$field_name], $entity_type_id, $event->metadata['uuid']);
        }
      }
    }
  }

  /**
   * Resolves the section value of each layout field item.
   *
   * @param array $items
   *   The exported field items; modified by reference.
   * @param string $host_entity_type
   *   The entity type id of the host being imported.
   * @param string $host_uuid
   *   The uuid of the host being imported.
   */
  private function resolveItemsForImport(array &$items, string $host_entity_type, string $host_uuid): void {
    foreach ($items as &$item) {
      if (is_array($item['section'] ?? NULL)) {
        $this->resolveSectionForImport($item['section'], $host_entity_type, $host_uuid);
      }
    }
  }

  /**
   * Resolves inline blocks in a section array, recursing into embedded ones.
   *
   * @param array $section
   *   A Section::toArray() value; modified by reference.
   * @param string $host_entity_type
   *   The entity type id of the host being imported.
   * @param string $host_uuid
   *   The uuid of the host being imported.
   */
  private function resolveSectionForImport(array &$section, string $host_entity_type, string $host_uuid): void {
    if (empty($section['components'])) {
      return;
    }
    foreach ($section['components'] as &$component) {
      $configuration = &$component['configuration'];
      $plugin_id = $configuration['id'] ?? '';

      if (str_starts_with($plugin_id, 'inline_block:') && isset($configuration['block_uuid'])) {
        $block_content = $this->entityRepository->loadEntityByUuid('block_content', $configuration['block_uuid']);
        if (!$block_content instanceof BlockContentInterface) {
          throw new ImportException(sprintf('Could not resolve the "%s" component of %s %s: no block_content entity with uuid %s exists. Inline blocks must be exported alongside their host so they import first as dependencies.', $plugin_id, $host_entity_type, $host_uuid, $configuration['block_uuid']));
        }
        $configuration['block_id'] = $block_content->id();
        $configuration['block_revision_id'] = $block_content->getRevisionId();
        unset($configuration['block_uuid']);
        // The imported host saves while syncing, so handlePreSave() will not
        // record its inline_block_usage rows; flag them here and
        // trackInlineBlockUsage() records them after the host insert.
        $this->classResolver->getInstanceFromDefinition(InlineBlockEntityOperations::class)
          ->flagUsage($block_content->id(), $host_entity_type, $host_uuid);
      }
      elseif ($plugin_id === SectionBlock::PLUGIN_ID && is_array($configuration[SectionBlock::SECTION_KEY] ?? NULL)) {
        $this->resolveSectionForImport($configuration[SectionBlock::SECTION_KEY], $host_entity_type, $host_uuid);
      }
    }
  }

}
