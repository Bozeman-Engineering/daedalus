<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layers\EventSubscriber;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Recomputes stamped asset arrays when the daedalus_stylish_layers config changes.
 *
 * AssetResolver caches resolved CSS asset arrays permanently under the
 * library_info tag, and those arrays bake in the stamps (and, with
 * aggregation on, the stamp-derived aggregate URLs). Rendered pages already
 * vary on this config via the cache tag hook_page_attachments() attaches;
 * this covers the asset-array layer beneath them. Stale aggregate FILES need
 * no handling: stamps feed the filename hash, so changed config yields new
 * URLs and old files are never referenced again.
 */
final class ConfigSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onConfigSave'];
  }

  /**
   * Invalidates cached asset arrays when daedalus_stylish_layers settings change.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() === 'daedalus_stylish_layers.settings') {
      $this->cacheTagsInvalidator->invalidateTags(['library_info']);
    }
  }

}
