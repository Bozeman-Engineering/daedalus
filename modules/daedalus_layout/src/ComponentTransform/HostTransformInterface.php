<?php

namespace Drupal\daedalus_layout\ComponentTransform;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Rewrites host-level state once per host during a migration.
 *
 * Component and section transforms rewrite pieces of the layout tree; some
 * migrations also need one pass over host-level state that is not tied to a
 * single section (e.g. a style map whose key grammar changed). A host
 * transform runs ONCE per host object, AFTER the host's section walk, so it
 * sees the converged tree.
 *
 * Contract:
 * - MUST be idempotent and cheap: it runs on every store walk and on every
 *   entity presave while registered (the same deprecation-window posture as
 *   component transforms).
 * - MUST NOT save the host; the caller owns persisting.
 * - Every store the spine walks is passed as its host: a content host for
 *   each revision and translation of an entity carrying a layout field
 *   (HostEntityWalker), a display host for each Layout Builder enabled
 *   entity view display (EntityViewDisplayWalker), both at presave. The
 *   host answers the state a transform rewrites (its style map through
 *   styleMap()/setStyleMap()) wherever the host keeps it; a transform that
 *   reads the layout tree must read the host's store raw (the content
 *   host's document door seeds an empty override, and a migration never
 *   instantiates documents).
 *
 * Register with the daedalus_layout.host_transform service tag (optional priority,
 * higher first).
 */
interface HostTransformInterface {

  /**
   * Transforms host-level state in place.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host (a content entity revision or translation, or a display)
   *   whose layout was walked.
   *
   * @return bool
   *   TRUE when the host was changed and needs persisting.
   */
  public function transformHost(HostInterface $host): bool;

}
