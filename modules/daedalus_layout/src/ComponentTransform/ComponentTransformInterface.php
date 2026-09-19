<?php

namespace Drupal\daedalus_layout\ComponentTransform;

use Drupal\Core\Entity\EntityInterface;
use Drupal\layout_builder\SectionComponent;

/**
 * Rewrites one kind of layout component in place during a deprecation.
 *
 * A transform is a single block deprecation's mapping (e.g. heading becomes
 * text): given a component and the entity hosting its layout, it decides
 * whether the component is one it migrates and, if so, rewrites the
 * component's configuration in place. The migration spine (store walkers,
 * presave safety net) discovers transforms through the
 * daedalus_layout.component_transform service tag and applies them via
 * ComponentTransformRegistry, so each future deprecation is one tagged
 * transform class rather than a new walker.
 *
 * Transforms MUST NOT change the component UUID: styles and anything else
 * addressing the component do so by UUID, and preserving it keeps that
 * addressing stable across the migration.
 *
 * Inline block replacements go through block_serialized: a transform that
 * replaces a component's inline block entity writes the NEW, unsaved entity
 * to the configuration's block_serialized key and empties block_revision_id
 * and block_uuid. That single output shape is correct in every store the
 * spine walks: content entity hosts and entity view displays persist the
 * serialized entity on save (layout_builder's InlineBlockEntityOperations
 * saves it, sets block_revision_id, and records usage, which is why the
 * spine walkers deliberately do not mark their saves as syncing). Transforms
 * MUST NOT save replacement block entities themselves: a saved entity with
 * no usage record is deleted by layout_builder's unused-block garbage
 * collection out from under the layout that references it.
 */
interface ComponentTransformInterface {

  /**
   * Determines whether this transform migrates the given component.
   *
   * Called for every component of every store the spine walks (and for
   * every component saved while the presave safety net is active), so this
   * must be cheap: typically a plugin id check on the component's
   * configuration.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component under consideration.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the component: a content entity
   *   revision/translation for host layouts, or an entity view display for
   *   layout defaults.
   *
   * @return bool
   *   TRUE when transform() should rewrite this component.
   */
  public function applies(SectionComponent $component, EntityInterface $host): bool;

  /**
   * Rewrites the component in place.
   *
   * Only called when applies() returned TRUE for the same arguments. The
   * transform mutates the component (setConfiguration()) and may touch the
   * host (e.g. write styles to a host field); the caller owns persisting the
   * host afterwards.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to rewrite. Keep its UUID.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the component.
   */
  public function transform(SectionComponent $component, EntityInterface $host): void;

}
