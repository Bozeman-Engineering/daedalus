<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rebuilds the layered declarations when the active design system changes.
 *
 * The active system's package is a layer of its own (the design-system
 * stratum, see PageBuilderDeclarations) and an inactive package layers
 * nothing, so a switch changes the provider set itself: the old package's
 * law leaves and the new one's arrives. Reacting at the config save event
 * of daedalus_blueprint.settings covers every writer (the activate op and
 * admin form through ActiveDesignSystem::activate(), drush config-set,
 * config sync) through one seam. The persistent discovery entry carries
 * the settings tag and would drop on its own; the reset also clears the
 * per-request memo (an activate followed by a read in one request) and
 * the family tag every projection of the declarations rides (the client
 * anatomy payload, composed renders, the AI schema fragment, daedalus_stylish's
 * client style guide), which the config tag alone would not reach.
 */
final class DesignSystemSwitch implements EventSubscriberInterface {

  public function __construct(
    protected PageBuilderDeclarations $declarations,
  ) {}

  /**
   * Resets the declarations when the pointer changes.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() !== ActiveDesignSystem::CONFIG_NAME || !$event->isChanged(ActiveDesignSystem::KEY)) {
      return;
    }
    $this->declarations->resetCache();
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
