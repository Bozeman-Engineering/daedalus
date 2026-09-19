<?php

namespace Drupal\daedalus_layout\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;

/**
 * Service description.
 */
class NoHelpBlock implements ConfigFactoryOverrideInterface {

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    $overrides = [];
    // Find the help block.
    $grepped = preg_grep('/block\.block\..*help$/', $names);
    if (!empty($grepped)) {
      $grepped = reset($grepped);
      // Don't show the help block on layout pages.
      $overrides[$grepped] = [
        'visibility' => [
          'request_path' => [
            'id' => 'request_path',
            'negate' => TRUE,
            'pages' => "/*/*/layout\n/*/*/layout/*",
          ],
        ],
      ];
    }
    return $overrides;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'daedalus_layout_no_help_block';
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

}
