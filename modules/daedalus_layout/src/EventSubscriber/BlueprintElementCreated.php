<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\daedalus_blueprint\Event\ElementCreatedEvent;
use Drupal\daedalus_layout\Event\PlaceBlockEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Re-fires daedalus_blueprint's element-created event as PlaceBlockEvent.
 *
 * The blueprint add operations replaced the Dropzones placement path, but
 * PlaceBlockEvent remains the extension point block-type modules subscribe
 * to for tweaking freshly placed blocks (sample content, default nested
 * sections). This bridges the two: the component's configuration is
 * rehydrated into a block plugin instance, PlaceBlockEvent subscribers
 * mutate it, and the resulting configuration is written back onto the
 * component before it is inserted.
 */
class BlueprintElementCreated implements EventSubscriberInterface {

  public function __construct(
    protected BlockManagerInterface $blockManager,
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Re-fires the element-created event as PlaceBlockEvent.
   *
   * @param \Drupal\daedalus_blueprint\Event\ElementCreatedEvent $event
   *   The element-created event.
   */
  public function onElementCreated(ElementCreatedEvent $event): void {
    $component = $event->getComponent();
    $configuration = $component->get('configuration');

    $block_plugin = $this->blockManager->createInstance($configuration['id'], $configuration);
    // @todo Should we deprecate the PlaceBlockEvent
    $this->eventDispatcher->dispatch(new PlaceBlockEvent($block_plugin), PlaceBlockEvent::class);
    $component->setConfiguration($block_plugin->getConfiguration());
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ElementCreatedEvent::class => ['onElementCreated'],
    ];
  }

}
