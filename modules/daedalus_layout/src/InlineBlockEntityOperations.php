<?php

namespace Drupal\daedalus_layout;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\LayoutBuilder\InlineBlockValues;
use Drupal\layout_builder\SectionComponent;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\layout_builder\InlineBlockUsageInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
 *
 * The save walks what the host OWNS (OwnedSectionsTrait: the host's own
 * section storage, the seed declined), never what renders for it. A
 * content host saves each inline block of its own document as a
 * block_content entity (above); a page with no document of its own
 * (following its template, or a plain host) saves nothing of the template.
 * A display host stores each as VALUES (InlineBlockValues::store(): the
 * block's fields in the field grammar under block_values, no entity, no
 * revision id, no usage row), because a display is config and its export
 * cannot carry a content id. Core's removal walk then garbage-collects the
 * entities a converted display no longer names.
 */
class InlineBlockEntityOperations extends InlineBlockEntityOperationsBase {

  use OwnedSectionsTrait {
    ownedHost as private freshOwnedHost;
    ownedStorage as private freshOwnedStorage;
  }

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected EntityRepositoryInterface $entityRepository;

  /**
   * The hosts resolved during the current operation.
   *
   * Keyed by spl_object_id() of the entity, as the storages below are: the
   * host's persistence decides the block's shape in the save loop, and the
   * host is resolved once per entity beside its storage.
   *
   * @var array<int, \Drupal\daedalus_blueprint\Host\HostInterface|null>
   */
  private array $hosts = [];

  /**
   * The hosts' own nested-aware storages hydrated during the current operation.
   *
   * Keyed by spl_object_id() of the entity (the saved entity and its
   * original are distinct objects needing distinct wrappers). Memoization is
   * correctness, not caching: every core call site within one presave must
   * see the SAME hydrated Section objects, so mutations made by the save
   * loop are visible to the removed-blocks diff and reachable by the flush.
   *
   * @var array<int, \Drupal\daedalus_layout\NestedSectionStorageInterface|null>
   */
  private array $nestedStorages = [];

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    InlineBlockUsageInterface $usage,
    SectionStorageManagerInterface $section_storage_manager,
    EntityRepositoryInterface $entity_repository,
    protected InlineBlockValues $inlineBlockValues,
    protected DaedalusLayoutAdapter $adapter,
    protected LoggerInterface $logger,
    protected HostResolver $hostResolver,
  ) {
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
      $container->get('daedalus_blueprint.inline_block_values'),
      $container->get('daedalus_blueprint.daedalus_layout_adapter'),
      $container->get('logger.channel.daedalus_layout'),
      $container->get('daedalus_blueprint.host_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Memoized per entity object for the operation: one host per entity, its
   * seed declined once, read by the save loop (persistence) and the
   * storage below.
   */
  protected function ownedHost(EntityInterface $entity): ?HostInterface {
    $key = spl_object_id($entity);
    if (!array_key_exists($key, $this->hosts)) {
      $this->hosts[$key] = $this->freshOwnedHost($entity);
    }
    return $this->hosts[$key];
  }

  /**
   * {@inheritdoc}
   *
   * Memoized per entity object for the operation: the host's
   * sectionStorage() is a fresh wrapper per call, and every core call site
   * within one presave must see the SAME hydrated Section objects.
   */
  protected function ownedStorage(EntityInterface $entity): ?NestedSectionStorageInterface {
    $key = spl_object_id($entity);
    if (!array_key_exists($key, $this->nestedStorages)) {
      $this->nestedStorages[$key] = $this->freshOwnedStorage($entity);
    }
    return $this->nestedStorages[$key];
  }

  /**
   * {@inheritdoc}
   *
   * The sections the entity OWNS (ownedSections(), the host's own storage
   * with the seed declined), ALL of them, root and embedded, making every
   * core call site (handlePreSave's save loop, getRemovedBlockIds' revision
   * diff) depth-aware. Embedded sections are identity-stable per wrapper:
   * the components core mutates are the same objects bubbleChangesToRoot()
   * flushes. A page following its template owns no section and saves
   * nothing; core's reading would have walked the template.
   */
  protected function getEntitySections(EntityInterface $entity) {
    return $this->ownedSections($entity);
  }

  /**
   * {@inheritdoc}
   *
   * An entity that can hold a document of its own. A plain host (no
   * override field on its bundle) renders its display's document and saves
   * none of it.
   */
  protected function isLayoutCompatibleEntity(EntityInterface $entity) {
    return $this->ownsDocument($entity);
  }

  /**
   * {@inheritdoc}
   *
   * A host fact: the original's own document was empty and this one is not,
   * so this save lands the page's first document (the seed, or a creator's
   * authored one). A seeded document can carry a block_revision_id (a
   * template block the grammar could not spell stays an entity on the
   * display), and the page must get its own copy rather than share the
   * template's entity, as core intends for a first override. Core's
   * reading asked which STORAGE each resolved to, which for an empty
   * override is the display's.
   */
  protected function originalEntityUsesDefaultStorage(EntityInterface $entity) {
    if ($entity->isNew() || !$entity->getOriginal()) {
      return FALSE;
    }
    return $this->ownedSections($entity->getOriginal()) === [] && $this->ownedSections($entity) !== [];
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
      // wrappers and declined hosts must not leak into the next entity's
      // save (spl_object_id() values are reused once an object is freed).
      $this->nestedStorages = [];
      $this->hosts = [];
    }
  }

  /**
   * {@inheritdoc}
   *
   * The host's persistence decides: a host whose blocks persist as values
   * (a display) stores the block as values; any other saves it as an
   * entity.
   */
  protected function saveInlineBlockComponent(EntityInterface $entity, SectionComponent $component, $new_revision, $duplicate_blocks) {
    if ($this->ownedHost($entity)->persistence()->valueBlocks && $this->storeValueBlock($component)) {
      return;
    }
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
   * Stores a display's inline block as values.
   *
   * A component already stored as values (or naming no block at all) is
   * left as it is. A block the grammar cannot spell (a non-empty field
   * whose handler serializes nothing) stays an entity on core's road, with
   * a warning: the spine's doors refuse such a block on a template, so one
   * reaching a save predates the door, and losing its field is worse than
   * keeping its id.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The inline block component.
   *
   * @return bool
   *   TRUE when the component holds values now, FALSE when the entity road
   *   must run.
   */
  protected function storeValueBlock(SectionComponent $component): bool {
    $configuration = $component->get('configuration');
    if (empty($configuration['block_serialized']) && empty($configuration['block_revision_id'])) {
      return TRUE;
    }
    $block = $this->adapter->extractBlockContent($component);
    if ($block === NULL) {
      return TRUE;
    }
    $unspellable = $this->inlineBlockValues->unspellable($block);
    if ($unspellable !== []) {
      $this->logger->warning('Inline block @uuid (@type) on a display holds fields the field grammar cannot spell (@fields); it stays a block entity.', [
        '@uuid' => $component->getUuid(),
        '@type' => $block->bundle(),
        '@fields' => implode(', ', $unspellable),
      ]);
      return FALSE;
    }
    $this->inlineBlockValues->store($component, $block);
    return TRUE;
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
