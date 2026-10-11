<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Event;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\layout_builder\SectionComponent;

/**
 * Fired when an add operation creates a Layout Builder component.
 *
 * Dispatched for every successful add_element of a Layout Builder block,
 * inline or plugin, whatever the door (palette, AI, programmatic, a blueprint
 * expansion). Whether the block arrived dressed (its styles come with it)
 * is the batch's posture, read through dressed(); the `populate: 'sample'`
 * option means only that unprovided fields were sample-filled and says
 * nothing about styling.
 *
 * The event fires after the component's configuration is assembled and
 * before it is inserted into its section, so subscribers may adjust the
 * configuration (including a serialized inline block in
 * `configuration['block_serialized']`) via
 * $event->getComponent()->setConfiguration(). daedalus_layout re-fires this as its
 * PlaceBlockEvent so existing block-type subscribers keep working.
 *
 * Field changes a subscriber makes on the root entity here ride the same
 * save as the add itself; the subscriber declares each one with
 * addTarget() so the operation's result re-renders it.
 */
class ElementCreatedEvent extends LifecycleEventBase {

  /**
   * Constructs a ElementCreatedEvent.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the component is being added to.
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The created component, not yet inserted into its section.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $blockContent
   *   The inline block_content entity, or NULL for plugin blocks. Field
   *   changes made here are NOT persisted automatically — the serialized
   *   copy in the component's configuration is authoritative, so subscribers
   *   changing block content must re-serialize it into the configuration.
   * @param bool $dressed
   *   Whether the block arrived dressed (its batch declares its styles);
   *   see LifecycleEventBase::dressed().
   */
  public function __construct(
    HostInterface $host,
    protected SectionComponent $component,
    protected ?FieldableEntityInterface $blockContent = NULL,
    bool $dressed = FALSE,
  ) {
    parent::__construct($host, $dressed);
  }

  /**
   * Gets the created component (mutable, not yet inserted).
   */
  public function getComponent(): SectionComponent {
    return $this->component;
  }

  /**
   * Gets the inline block_content entity, or NULL for plugin blocks.
   */
  public function getBlockContent(): ?FieldableEntityInterface {
    return $this->blockContent;
  }

}
