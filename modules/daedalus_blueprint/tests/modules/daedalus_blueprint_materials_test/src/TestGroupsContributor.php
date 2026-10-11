<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_materials_test;

use Drupal\daedalus_blueprint\Dialect\Event\DialectCatalogBuildEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Contributes a hand-built group vocabulary to the dialect catalog.
 *
 * What daedalus_layout's SiteGroups does from the site declaration, for a
 * kernel that boots the spine alone: a test sets the static $groups
 * (group id => label and optional description, declaration order) and
 * resets the catalog builder, and the built catalog answers them exactly
 * as a declared vocabulary would. Empty by default, so the reference
 * roster's surfaces see no group until a test declares one.
 */
final class TestGroupsContributor implements EventSubscriberInterface {

  /**
   * The groups to contribute: id => {label, description?}.
   *
   * @var array<string, array{label: string, description?: string}>
   */
  public static array $groups = [];

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      DialectCatalogBuildEvent::EVENT_NAME => 'onCatalogBuild',
    ];
  }

  /**
   * Contributes the groups a test declared.
   */
  public function onCatalogBuild(DialectCatalogBuildEvent $event): void {
    $event->addGroups(self::$groups);
  }

}
