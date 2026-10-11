<?php

namespace Drupal\daedalus_layout\ComponentTransform;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;

/**
 * Deprecation-window safety net: converges layouts at save AND load time.
 *
 * The migration spine's batched walkers rewrite every persisted layout
 * store, but an editing session staged in tempstore before the update ran
 * still holds deprecated components and would write them back verbatim on
 * save. Rather than walking tempstore's per-user serialized envelopes, every
 * entity save passes through this net (EntityPresave::entityPresave()): while any
 * transform is registered, the layout sections of the entity being saved are
 * converged in place. One save-path chokepoint catches stale sessions,
 * forgotten drafts, replayed blueprint placements, and anything else
 * that slips past the walkers, and it costs a single hasTransforms() check
 * once a deprecation window closes and its transform is unregistered.
 *
 * The save-time half alone is not enough for structural migrations: a
 * staged session whose sections reference RETIRED plugins (the four
 * multi-region layouts) cannot even render, and a session that cannot
 * render can never reach save. convergeStagedStorage() is the load-time
 * half, called by the tempstore load chokepoint
 * (daedalus_tempstore's LayoutTempstoreStrategy::get()) on every staged
 * envelope it deserializes, so a pre-migration session converges before
 * its first edit-mode render instead of crashing it.
 *
 * The net runs before layout_builder's inline block presave machinery (see
 * the call order in EntityPresave::entityPresave()) so a block_serialized
 * replacement written by a transform is saved and usage-tracked within the
 * same host save, per the block_serialized contract on
 * ComponentTransformInterface.
 *
 * Syncing saves are exempt: a synchronization must round-trip data
 * verbatim, and core's InlineBlockEntityOperations::handlePreSave() skips
 * syncing saves too, so a block_serialized replacement written during one
 * would be stranded serialized with no usage record.
 */
class PresaveSafetyNet {

  /**
   * Constructs a PresaveSafetyNet.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\daedalus_layout\ComponentTransform\ComponentTransformRegistry $transforms
   *   The component transform registry.
   */
  public function __construct(
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ComponentTransformRegistry $transforms,
  ) {}

  /**
   * Applies registered transforms to the layouts of an entity being saved.
   *
   * Covers every store the spine walks, because they all pass through
   * entity presave: content entity hosts (any layout_section field) and
   * Layout Builder enabled entity view displays.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @return bool
   *   TRUE if any transform rewrote anything, so callers holding a copy of
   *   the entity elsewhere (a tempstore envelope) know to re-persist it.
   */
  public function apply(EntityInterface $entity): bool {
    if (!$this->transforms->hasTransforms()) {
      return FALSE;
    }
    if ($entity instanceof SynchronizableInterface && $entity->isSyncing()) {
      return FALSE;
    }
    if ($entity instanceof ContentEntityInterface) {
      $field_map = $this->entityFieldManager->getFieldMapByFieldType('layout_section');
      $field_names = array_keys($field_map[$entity->getEntityTypeId()] ?? []);
      if (!empty($field_names)) {
        return $this->transforms->transformLayoutFields($entity, $field_names);
      }
    }
    elseif ($entity instanceof LayoutEntityDisplayInterface && $entity->isLayoutBuilderEnabled()) {
      return $this->transforms->transformDisplay($entity);
    }
    return FALSE;
  }

  /**
   * Converges the host staged inside a tempstore section storage.
   *
   * The load-time half of the net: an overrides storage stages its whole
   * host entity (layout fields AND host state like style maps), a defaults
   * storage stages its display, and both are the same stores apply()
   * converges at save time. Transforms are idempotent, so a converged
   * session costs only applies() checks on later loads.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   A section storage deserialized from a tempstore envelope.
   *
   * @return bool
   *   TRUE if anything was rewritten, so the caller knows the envelope it
   *   deserialized is stale and should be re-persisted.
   */
  public function convergeStagedStorage(SectionStorageInterface $section_storage): bool {
    if (!$this->transforms->hasTransforms()) {
      return FALSE;
    }
    $values = $section_storage->getContextValues();
    $host = $values['entity'] ?? $values['display'] ?? NULL;
    if (!$host instanceof EntityInterface) {
      return FALSE;
    }
    return $this->apply($host);
  }

}
