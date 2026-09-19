<?php

namespace Drupal\daedalus_layout\ComponentTransform;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Rewrites host-level state once per content host during a migration.
 *
 * Component and section transforms rewrite pieces of the layout tree; some
 * migrations also need one pass over host-level state that is not tied to a
 * single section (e.g. a style map whose key grammar changed). A host
 * transform runs ONCE per content host object, AFTER the host's section
 * walk, so it sees the converged tree.
 *
 * Contract:
 * - MUST be idempotent and cheap: it runs on every store walk and on every
 *   entity presave while registered (the same deprecation-window posture as
 *   component transforms).
 * - MUST NOT save the host; the caller owns persisting.
 * - Only content hosts are passed; config stores (entity view displays)
 *   carry no host-level content state and are never given to host
 *   transforms.
 *
 * Register with the daedalus_layout.host_transform service tag (optional priority,
 * higher first).
 */
interface HostTransformInterface {

  /**
   * Transforms host-level state in place.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $host
   *   The host entity (or revision/translation) whose layout was walked.
   *
   * @return bool
   *   TRUE when the host was changed and needs persisting.
   */
  public function transformHost(ContentEntityInterface $host): bool;

}
