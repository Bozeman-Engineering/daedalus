<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Cache;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextInterface;
use Drupal\daedalus_ui\ModeState;

/**
 * Cache context for the EFFECTIVE mode of the page builder.
 *
 * Defines the daedalus_ui_mode cache context: the mode a request
 * actually renders under, resolved the same way every consumer reads it
 * (query parameter first, then the navigationMode cookie, then 'none').
 *
 * This exists because cookies:navigationMode varies on the raw cookie only.
 * A request whose query overrides its cookie (?navigationMode=none while the
 * cookie says 'edit', endpoint requests carrying mode as a param) would
 * store its render into the cookie-value slot, poisoning it for every
 * request that really renders under the cookie's mode. Varying on the
 * resolved value gives every effective mode its own slot.
 */
final class DaedalusUiModeCacheContext implements CacheContextInterface {

  public function __construct(
    private readonly ModeState $modeState,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getLabel() {
    return t('Daedalus UI effective mode');
  }

  /**
   * {@inheritdoc}
   */
  public function getContext() {
    return $this->modeState->getMode();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata() {
    return new CacheableMetadata();
  }

}
