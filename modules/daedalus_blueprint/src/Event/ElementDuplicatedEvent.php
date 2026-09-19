<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Event;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\layout_builder\SectionComponent;

/**
 * Fired after duplicate_element deep-clones an element (block or section).
 *
 * Duplicate_element clones with fresh identity throughout: the element
 * itself, every nested component and section inside a duplicated layout
 * block, and the duplicated inline block_content entities all get new UUIDs.
 * Data keyed by those UUIDs elsewhere on the entity (style maps, any
 * per-placement metadata) goes stale on the clone unless its owner follows
 * the rewrite — this event carries the complete old-to-new UUID map so
 * subscribers can carry their entries over.
 *
 * Dispatched after the clone is inserted into its section and nested
 * changes have bubbled to the root entity, so subscribers see (and may
 * mutate) the entity in its final post-duplicate state; field changes made
 * here ride the same save as the duplicate itself, and the subscriber
 * declares each one with addTarget() so the operation's result re-renders
 * it.
 */
class ElementDuplicatedEvent extends LifecycleEventBase {

  /**
   * Constructs a ElementDuplicatedEvent.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity the duplicate happened on.
   * @param \Drupal\layout_builder\SectionComponent|null $clone
   *   The inserted clone component, or NULL for a duplicated section (the
   *   section is the identity; its container wrapper is plumbing).
   * @param string $sourceUuid
   *   The UUID of the element that was duplicated.
   * @param array $uuidMap
   *   Old UUID => new UUID for every identity the clone regenerated:
   *   the duplicated component, nested components and sections (section
   *   UUIDs only where daedalus_layout provides them), and duplicated inline
   *   block_content entities.
   */
  public function __construct(
    FieldableEntityInterface $entity,
    protected ?SectionComponent $clone,
    protected string $sourceUuid,
    protected array $uuidMap,
  ) {
    parent::__construct($entity);
  }

  /**
   * Gets the inserted clone component (NULL for a duplicated section).
   */
  public function getClone(): ?SectionComponent {
    return $this->clone;
  }

  /**
   * Gets the UUID of the element that was duplicated.
   */
  public function getSourceUuid(): string {
    return $this->sourceUuid;
  }

  /**
   * Gets the old UUID => new UUID map for everything the clone regenerated.
   *
   * @return array
   *   Old UUID => new UUID.
   */
  public function getUuidMap(): array {
    return $this->uuidMap;
  }

}
