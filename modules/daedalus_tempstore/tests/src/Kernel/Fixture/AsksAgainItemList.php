<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_tempstore\Kernel\Fixture;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;
use Drupal\daedalus_tempstore\ParamConverter\EntityConverter;

/**
 * Test field whose value comes from asking the converter for its own entity.
 *
 * A computed field that matches the current route to answer does the same:
 * the match converts the route's entity parameter again, while the converter
 * is still reading the entity to decide the swap.
 */
final class AsksAgainItemList extends FieldItemList {

  use ComputedItemListTrait;

  /**
   * How many of these fields are being computed right now.
   */
  protected static int $computing = 0;

  /**
   * {@inheritdoc}
   */
  protected function computeValue(): void {
    if (self::$computing > 0) {
      throw new \LogicException('The converter read the entity again while it was already deciding the swap.');
    }
    self::$computing++;
    try {
      $answer = \Drupal::service(EntityConverter::class)->loadEntityFromTempstore($this->getEntity());
      $this->list[0] = $this->createItem(0, $answer->label());
    }
    finally {
      self::$computing--;
    }
  }

}
