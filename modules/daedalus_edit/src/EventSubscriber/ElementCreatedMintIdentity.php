<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_edit\EntityIdentityMinting;
use Drupal\daedalus_blueprint\Event\ElementCreatedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Mints identity on sample-populated inline blocks at placement time.
 *
 * The palette flow's add operation fills fields the caller didn't provide
 * via field_sample_value generators, outside the field-handler deserialize
 * pipeline, so the stage-time seam (IdentityMintingTextFieldHandler) never
 * sees those values; and placement stages without a save, so the presave
 * backstop doesn't run either. This subscriber closes that gap: it mints
 * the freshly created block's formatted text fields before the element
 * is inserted, completing eager identity's three seams (deserialize-time
 * for authored values, element-created for sample values, presave as the
 * universal backstop).
 *
 * Runs at low priority, after daedalus_layout's PlaceBlockEvent bridge and any
 * block-type sample-content subscribers, so whatever block they left in
 * configuration['block_serialized'] (the authoritative copy) is what gets
 * minted. An unmintable format's LogicException flies, failing the
 * mutation loudly like the other seams.
 */
class ElementCreatedMintIdentity implements EventSubscriberInterface {

  public function __construct(
    protected EntityIdentityMinting $entityIdentityMinting,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ElementCreatedEvent::class => ['onElementCreated', -100]];
  }

  /**
   * Mints the serialized inline block's formatted text fields.
   *
   * @param \Drupal\daedalus_blueprint\Event\ElementCreatedEvent $event
   *   The element-created event.
   */
  public function onElementCreated(ElementCreatedEvent $event): void {
    if ($event->getBlockContent() === NULL) {
      return;
    }
    $component = $event->getComponent();
    $configuration = $component->get('configuration');
    if (empty($configuration['block_serialized'])) {
      return;
    }
    // Layout Builder's own serialized inline block: the classes cannot be
    // limited without breaking the block, and no user input reaches it.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $block = unserialize($configuration['block_serialized'], ['allowed_classes' => TRUE]);
    if (!$block instanceof FieldableEntityInterface) {
      return;
    }
    if ($this->entityIdentityMinting->mintEntity($block)) {
      $configuration['block_serialized'] = serialize($block);
      $component->setConfiguration($configuration);
    }
  }

}
