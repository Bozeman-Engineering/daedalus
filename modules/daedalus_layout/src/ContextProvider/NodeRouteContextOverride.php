<?php

namespace Drupal\daedalus_layout\ContextProvider;

use Drupal\node\ContextProvider\NodeRouteContext;

/**
 * Provides the current node on section storage routes without a node parameter.
 */
class NodeRouteContextOverride extends NodeRouteContext {

  /**
   * {@inheritdoc}
   */
  public function getRuntimeContexts(array $unqualified_context_ids) {
    $result = parent::getRuntimeContexts($unqualified_context_ids);
    // The node context is empty.
    if (!empty($result['node']) && ($node = $result['node']) && !$node->hasContextValue()) {
      $section_storage = $this->routeMatch->getParameter('section_storage');
      // Determine the node context via section storage.
      if (!empty($section_storage)) {
        $contexts = $section_storage->getContexts();
        if (!empty($contexts['entity'])) {
          $result['node'] = $section_storage->getContext('entity');
        }
      }
    }

    return $result;
  }

}
