<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui_roster_test\EventSubscriber;

use Drupal\daedalus_ui\Event\BundleRosterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds a "Fixture" column to the bundle roster for bundles that are on.
 *
 * The shape a contributing module takes: one column under one id, the
 * header the same on every row, the cell this row's value. It answers only
 * where the page builder is on, so a table with the column also has a row
 * with no answer, which the roster must render as an empty cell.
 */
final class FixtureColumn implements EventSubscriberInterface {

  /**
   * Adds the column.
   */
  public function onBundleRoster(BundleRosterEvent $event): void {
    $bundle = $event->getBundle();
    if (!$bundle->getThirdPartySetting('daedalus_ui', 'enabled', FALSE)) {
      return;
    }
    $event->setColumn('fixture', 'Fixture', 'Fixture ' . $bundle->id());
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      BundleRosterEvent::class => ['onBundleRoster'],
    ];
  }

}
