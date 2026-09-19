<?php

namespace Drupal\daedalus_ui;

use Drupal\Core\Entity\EntityInterface;

/**
 * Wraps the main entity so the client can find and replace it.
 */
trait MainEntityWrapperTrait {

  /**
   * Attribute main entity wrapper.
   *
   * Wraps the item as if it were the main entity so the JS can act on it.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $view_mode
   *   The view mode.
   * @param array $content
   *   The content to replace into the page.
   *
   * @see \Drupal\daedalus_ui\EventSubscriber\EntityUiWrapper::onTwigRenderTemplate()
   */
  public function mainEntityWrapper(EntityInterface $entity, string $view_mode, array &$content): void {
    $content['#type'] = 'container';
    $content['#attributes']['data-daedalus-ui-entity-wrapper'] = $this->getWrapperId($entity);
    $content['#attributes']['data-daedalus-ui-view-mode'] = $view_mode;
    $content['#attributes']['data-main-entity'] = 'true';
    $content['#attributes']['data-entity-uuid'] = $entity->uuid();
    $content['#attributes']['class'] = 'daedalus-ui-entity-wrapper';
  }

}
