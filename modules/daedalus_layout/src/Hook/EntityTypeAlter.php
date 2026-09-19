<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay;

/**
 * Swaps the entity view display class for the Daedalus Layout one.
 */
final class EntityTypeAlter {

  /**
   * Implements hook_entity_type_alter().
   *
   * Ordered after layout_builder, whose own alter sets core's
   * LayoutBuilderEntityViewDisplay: the last writer wins, so this must run
   * after it or the default (alphabetical) order decides which class serves
   * the page.
   */
  #[Hook('entity_type_alter', order: new OrderAfter(modules: ['layout_builder']))]
  public function entityTypeAlter(array &$entity_types): void {
    $entity_types['entity_view_display']->setClass(LayoutBuilderEntityViewDisplay::class);
  }

}
