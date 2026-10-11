<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui_bare_test\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;

/**
 * Renders a node's full view on a bare route.
 */
final class BareNode extends ControllerBase {

  /**
   * The node's full view, nothing else: the route option does the rest.
   */
  public function view(NodeInterface $node): array {
    return $this->entityTypeManager()->getViewBuilder('node')->view($node, 'full');
  }

}
