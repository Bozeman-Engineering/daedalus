<?php

namespace Drupal\daedalus_layout\ComponentTransform;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;

/**
 * Migration spine store walker 2: entity view display default layouts.
 *
 * Default layouts live in the layout_builder third-party settings of entity
 * view display config entities, and they are edited with the same block UI
 * as overrides, so inline blocks (including deprecated ones) can occur in
 * them. This walker applies the registered component transforms
 * (ComponentTransformRegistry) to every section component of every
 * Layout Builder enabled display, re-saving only the displays a transform
 * actually rewrote.
 *
 * Displays are config entities: there are no revisions or translations to
 * fan out over, so unlike HostEntityWalker this walk is one query plus one
 * pass over the (typically small) set of enabled displays.
 *
 * Designed for a batched hook_post_update_NAME():
 * @code
 * function example_post_update_migrate_default_layouts(&$sandbox) {
 *   \Drupal::service('daedalus_layout.entity_view_display_walker')->walk($sandbox);
 * }
 * @endcode
 */
class EntityViewDisplayWalker {

  /**
   * The key under which walk state is kept in the caller's sandbox.
   */
  public const SANDBOX_KEY = 'daedalus_layout_entity_view_display_walker';

  /**
   * Constructs an EntityViewDisplayWalker.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\daedalus_layout\ComponentTransform\ComponentTransformRegistry $transforms
   *   The component transform registry.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ComponentTransformRegistry $transforms,
  ) {}

  /**
   * Processes one batch of Layout Builder enabled entity view displays.
   *
   * @param array $sandbox
   *   The batch sandbox (a post_update hook's $sandbox). The walker owns the
   *   self::SANDBOX_KEY entry and sets $sandbox['#finished'] (1 when the
   *   walk is complete).
   * @param int $batch_size
   *   Maximum displays to load and examine in this call.
   *
   * @return int
   *   The number of displays rewritten and re-saved during this call.
   */
  public function walk(array &$sandbox, int $batch_size = 50): int {
    if (!$this->transforms->hasTransforms()) {
      $sandbox['#finished'] = 1;
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('entity_view_display');
    if (!isset($sandbox[self::SANDBOX_KEY])) {
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('third_party_settings.layout_builder.enabled', TRUE)
        ->sort('id')
        ->execute();
      $sandbox[self::SANDBOX_KEY] = [
        'ids' => array_values($ids),
        'total' => count($ids),
        'saved' => 0,
      ];
    }
    $state = &$sandbox[self::SANDBOX_KEY];

    // Claimed IDs are removed from the queue before processing so a failing
    // batch does not reprocess them forever on retry.
    $ids = array_splice($state['ids'], 0, $batch_size);
    $saved = 0;
    foreach ($storage->loadMultiple($ids) as $display) {
      if (!$display instanceof LayoutEntityDisplayInterface || !$display->isLayoutBuilderEnabled()) {
        continue;
      }
      // getSections() returns the live Section objects held in the display's
      // third-party settings (LayoutBuilderEntityViewDisplayStorage maps them
      // to objects on load), so in-place component mutation dirties the
      // display without a setSections() dance.
      if ($this->transforms->transformDisplay($display)) {
        // Deliberately not a syncing save: layout_builder's inline block
        // presave machinery must run so a block_serialized replacement
        // written by a transform is saved and usage-tracked for the display.
        $display->save();
        $saved++;
      }
    }

    $state['saved'] += $saved;
    if (empty($state['ids'])) {
      $sandbox['#finished'] = 1;
    }
    else {
      $done = $state['total'] - count($state['ids']);
      $sandbox['#finished'] = $state['total'] > 0 ? min($done / $state['total'], 0.99) : 0.99;
    }
    return $saved;
  }

}
