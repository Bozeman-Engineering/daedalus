<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui_icon_test;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_ui\ElementModel\ElementIconProviderInterface;

/**
 * The higher-priority provider: answers containers, passes on blocks.
 *
 * Every call leaves a cache tag behind, so a test can prove what a
 * provider read rides the annotation's cacheability.
 */
final class LeadingIconProvider implements ElementIconProviderInterface {

  /**
   * The tag every answer carries.
   */
  public const CACHE_TAG = 'daedalus_ui_icon_test:leading';

  /**
   * {@inheritdoc}
   */
  public function icon(array $component, string $kind, CacheableMetadata $cacheability): ?string {
    $cacheability->addCacheTags([self::CACHE_TAG]);
    return $kind === self::KIND_CONTAINER ? '/leading/container.svg' : NULL;
  }

}
