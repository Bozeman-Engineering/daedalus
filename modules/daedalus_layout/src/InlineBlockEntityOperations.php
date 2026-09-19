<?php

namespace Drupal\daedalus_layout;

use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\SectionComponent;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\layout_builder\InlineBlockUsageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\InlineBlockEntityOperations as InlineBlockEntityOperationsBase;

/**
 * Depth-aware inline block entity operations.
 *
 * Core's inline-block lifecycle walkers (handlePreSave saving unsaved inline
 * blocks, getRemovedBlockIds diffing revisions for on-save GC) are one level
 * deep: they walk the root section list only. Under the embedded-section
 * model nesting is Section values inside container component configuration,
 * so there is no entity-save cascade that would reach nested inline blocks:
 * the ROOT entity's presave is the ONE save event for the whole tree.
 *
 * This subclass makes every core call site depth-aware at a single seam:
 * getEntitySections() returns ALL sections (root and embedded, identity
 * stable), so core's getInlineBlockComponents() finds inline blocks at every
 * depth. saveInlineBlockComponent() then mutates components of memoized
 * embedded Section objects, and handlePreSave() finishes by flushing those
 * mutations post-order (deepest first) back up the tree via
 * bubbleChangesToRoot(): each child section re-encodes into its parent
 * container's component configuration before the parent itself serializes,
 * so fresh block_revision_ids land in the stored root sections.
 *
 * Usage rows always point at the ROOT host entity. That is both more correct
 * than the old immediate-parent rows and load-bearing: edit access for
 * non-reusable blocks requires a usage row resolving to an accessible host
 * (see SetInlineBlockDependency), and GC on host delete marks the whole
 * tree's rows unused in one query.
 */
class InlineBlockEntityOperations extends InlineBlockEntityOperationsBase {

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * Nested-aware storages hydrated during the current operation.
   *
   * Keyed by spl_object_id() of the entity (the saved entity and its
   * original are distinct objects needing distinct wrappers). Memoization is
   * correctness, not caching: every core call site within one presave must
   * see the SAME hydrated Section objects, so mutations made by the save
   * loop are visible to the removed-blocks diff and reachable by the flush.
   *
   * @var \Drupal\daedalus_layout\NestedSectionStorageInterface[]
   */
  private array $nestedStorages = [];

  public function __construct(EntityTypeManagerInterface $entityTypeManager, InlineBlockUsageInterface $usage, SectionStorageManagerInterface $section_storage_manager, EntityRepositoryInterface $entity_repository) {
    parent::__construct($entityTypeManager, $usage, $section_storage_manager);
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('inline_block.usage'),
      $container->get('plugin.manager.layout_builder.section_storage'),
      $container->get('entity.repository'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Returns ALL sections, root and embedded, making every core call site
   * (handlePreSave's save loop, getRemovedBlockIds' revision diff)
   * depth-aware. Embedded sections are identity-stable per wrapper: the
   * components core mutates are the same objects bubbleChangesToRoot()
   * flushes.
   */
  protected function getEntitySections(EntityInterface $entity) {
    $key = spl_object_id($entity);
    if (!isset($this->nestedStorages[$key])) {
      $storage = $this->getSectionStorageForEntity($entity);
      if (!$storage) {
        return [];
      }
      $this->nestedStorages[$key] = NestedAwareSectionStorage::wrap($storage);
    }
    return $this->nestedStorages[$key]->getAllSections();
  }

  /**
   * {@inheritdoc}
   *
   * The post-order presave walk: core's loop saves inline blocks at every
   * depth (via the depth-aware getEntitySections()), writing fresh
   * block_revision_ids into components of hydrated embedded sections; the
   * bubble then flushes deepest-first, re-encoding each embedded section
   * into its container component's configuration on the way up so the root
   * sections that persist with the entity carry the whole updated tree.
   */
  public function handlePreSave(EntityInterface $entity) {
    try {
      parent::handlePreSave($entity);
      $wrapped = $this->nestedStorages[spl_object_id($entity)] ?? NULL;
      $wrapped?->bubbleChangesToRoot();
    }
    finally {
      // The class resolver serves one instance per request; hydrated
      // wrappers must not leak into the next entity's save.
      $this->nestedStorages = [];
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function saveInlineBlockComponent(EntityInterface $entity, SectionComponent $component, $new_revision, $duplicate_blocks) {
    /** @var \Drupal\layout_builder\Plugin\Block\InlineBlock $plugin */
    $plugin = $component->getPlugin();
    $plugin->saveBlockContent($new_revision, $duplicate_blocks);
    $post_save_configuration = $plugin->getConfiguration();
    $block_content_id = $this->getPluginBlockId($plugin);
    if ($block_content_id) {
      // Flag the usage so it can be recorded after the host entity has been
      // saved. @see trackInlineBlockUsage().
      $this->nestedUsage([
        'block_content_id' => $block_content_id,
        'layout_entity_uuid' => $entity->uuid(),
        'layout_entity_type' => $entity->getEntityTypeId(),
      ]);
    }
    $component->setConfiguration($post_save_configuration);
  }

  /**
   * Gets a block ID for an inline block plugin.
   *
   * @param \Drupal\layout_builder\Plugin\Block\InlineBlock $block_plugin
   *   The inline block plugin.
   *
   * @return int
   *   The block content ID or null none available.
   */
  protected function getPluginBlockId(InlineBlock $block_plugin) {
    $configuration = $block_plugin->getConfiguration();
    if (!empty($configuration['block_revision_id'])) {
      $revision_ids = $this->getBlockIdsForRevisionIds([$configuration['block_revision_id']]);
      return array_pop($revision_ids);
    }
    return NULL;
  }

  /**
   * Flags an inline block usage for recording after the host entity saves.
   *
   * The deferred-usage entry point for callers outside the presave walk.
   * Default-content import is the known consumer: imported hosts save with
   * isSyncing() TRUE, which makes handlePreSave() a no-op, so nothing else
   * would record inline_block_usage rows for them, and those rows are
   * load-bearing (non-reusable block access resolves through them, host
   * delete marks them unused for cron GC).
   *
   * @param int|string $block_content_id
   *   The block content entity ID.
   * @param string $layout_entity_type
   *   The entity type ID of the layout host entity.
   * @param string $layout_entity_uuid
   *   The uuid of the layout host entity.
   *
   * @see \Drupal\daedalus_layout\EventSubscriber\DefaultContentSubscriber
   * @see ::trackInlineBlockUsage()
   */
  public function flagUsage(int|string $block_content_id, string $layout_entity_type, string $layout_entity_uuid): void {
    $this->nestedUsage([
      'block_content_id' => $block_content_id,
      'layout_entity_uuid' => $layout_entity_uuid,
      'layout_entity_type' => $layout_entity_type,
    ]);
  }

  /**
   * Track inline block usage.
   *
   * In regular layout builder, the host entity is saved before you ever load
   * the layout builder UI, so it always has an ID when its inline blocks
   * save. This suite also creates entities WITH a layout in one operation
   * (blueprint ops, AI composition), where the ROOT host is still unsaved at
   * presave time and cannot be a usage row yet. saveInlineBlockComponent()
   * flags each saved block; this method records the rows once the host has
   * an ID, always pointing at the ROOT host entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was just inserted or updated.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function trackInlineBlockUsage(EntityInterface $entity) {
    $usages = $this->nestedUsage();
    if (!empty($usages)) {
      foreach ($usages as $delta => $usage) {
        $tracked_entity = $this->entityRepository->loadEntityByUuid($usage['layout_entity_type'], $usage['layout_entity_uuid']);
        if ($tracked_entity && !$tracked_entity->isNew()) {
          // addUsage() merges on (block, host) as a compound key, so a
          // stale row pointing elsewhere must be deleted or it would linger
          // as a second row. This makes rows self-healing toward the
          // current root host on every save.
          if ($this->usage->getUsage($usage['block_content_id'])) {
            $this->usage->deleteUsage([$usage['block_content_id']]);
          }
          $this->usage->addUsage($usage['block_content_id'], $tracked_entity);
          unset($usages[$delta]);
        }
      }
      $this->nestedUsage(NULL, $usages);
    }
  }

  /**
   * Nested usage.
   *
   * Keeps track of inline block usages until the host entity has been saved.
   *
   * @param array|null $usage
   *   A usage array consisting of block_content_id, layout_entity_uuid, and
   *   layout_entity_type.
   * @param array|null $usages
   *   An array of usages.
   *
   * @return array
   *   An array of inline_block_usage records by the layout_entity_uuid.
   */
  private function nestedUsage(?array $usage = NULL, ?array $usages = NULL) {
    $cached_usages = &drupal_static(__FUNCTION__);
    if (is_null($cached_usages)) {
      $cached_usages = [];
    }
    if ($usage) {
      $cached_usages[] = $usage;
    }
    if (!is_null($usages)) {
      $cached_usages = $usages;
    }
    return $cached_usages;
  }

}
