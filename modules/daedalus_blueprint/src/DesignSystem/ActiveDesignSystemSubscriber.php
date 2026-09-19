<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Drops the memoized roster when the active design system changes.
 *
 * The roster partitions on the active system, so a switch mid-request
 * (an activate op followed by a read in one batch, a drush activate
 * before a compile) must rebuild it. Reacting at the config save event
 * rather than inside ActiveDesignSystem::activate() covers every writer
 * (drush config-set, config sync, the admin form) through one seam. The
 * cache tag side needs nothing here: the roster's metadata carries
 * config:daedalus_blueprint.settings and the config save invalidates it.
 */
final class ActiveDesignSystemSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected MaterialsRosterBuilder $rosterBuilder,
  ) {}

  /**
   * Resets the roster memo when the pointer changes.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() !== ActiveDesignSystem::CONFIG_NAME || !$event->isChanged(ActiveDesignSystem::KEY)) {
      return;
    }
    $this->rosterBuilder->reset();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onConfigSave',
    ];
  }

}
