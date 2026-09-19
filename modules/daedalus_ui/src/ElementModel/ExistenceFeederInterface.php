<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

/**
 * Feeds the element model's existence inference tier.
 *
 * A feeder derives field NON-existence from a render truth its module owns:
 * daedalus_ui's own feeder reads what a bundle's view display hides,
 * daedalus_layout's reads which root fields a Layout Builder display preserves.
 * Register with the daedalus_ui.existence_feeder service tag; the model
 * collects feeders and unions their answers under its declaration tier
 * (the LiveAttributionDemand collector pattern, so owners feed without a
 * dependency cycle).
 *
 * A feeder must only report fields that NEVER render for the given node kind,
 * in any view the model serves: a false "does not exist" hides a real element
 * from every consumer (rows, canvas, styling, AI anatomy). When a feeder's
 * truth does not apply (wrong kind, a display shape it does not govern), it
 * returns [] and lets the other tiers speak.
 */
interface ExistenceFeederInterface {

  /**
   * The field names that never render for this node, or [].
   *
   * @param string $kind
   *   ElementModel::KIND_ENTITY (a root entity rendering through the routed
   *   display) or ElementModel::KIND_BLOCK (an inline block rendering
   *   through its bundle's own view display).
   * @param string $entity_type_id
   *   The entity type the fields belong to.
   * @param string $bundle
   *   The bundle machine name.
   * @param string $view_mode
   *   The view mode the node renders with.
   *
   * @return string[]
   *   The field names this feeder can vouch never render.
   */
  public function nonExistentFields(string $kind, string $entity_type_id, string $bundle, string $view_mode): array;

}
