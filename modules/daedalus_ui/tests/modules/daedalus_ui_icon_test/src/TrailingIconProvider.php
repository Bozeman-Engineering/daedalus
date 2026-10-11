<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui_icon_test;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_ui\ElementModel\ElementIconProviderInterface;

/**
 * The lower-priority provider: answers everything it is asked.
 */
final class TrailingIconProvider implements ElementIconProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function icon(array $component, string $kind, CacheableMetadata $cacheability): ?string {
    return '/trailing/' . $kind . '.svg';
  }

}
