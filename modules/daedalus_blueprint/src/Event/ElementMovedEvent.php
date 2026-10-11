<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Event;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Fired after move_element relocates an element (block or section).
 *
 * A move is a pure value move: the element keeps its identity and content
 * and only its position changes. What a subscriber cannot read off the
 * element afterwards is where it CAME FROM, and in particular whether the
 * move crossed a depth boundary: a section leaving the root list to sit
 * inside another section's region, or coming back out to root. Depth is
 * positional (ElementLocator::isNestedSection()), so this event carries the
 * two depth facts the operation knew while it moved, before and after.
 *
 * Dispatched for section moves AND component moves, one grammar for every
 * subscriber, after the element is in place and nested changes have
 * bubbled to the root entity; field changes made here ride the same save
 * as the move itself, and the subscriber declares each one with
 * addTarget() so the operation's result re-renders it.
 *
 * A component's depth is its containing section's depth: nested when the
 * section holding it is nested, root when that section is a root row.
 */
class ElementMovedEvent extends LifecycleEventBase {

  /**
   * Constructs an ElementMovedEvent.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the move happened on.
   * @param string $uuid
   *   The UUID of the moved element: a component UUID, or a section's
   *   daedalus_layout UUID (which is also its container component's UUID when
   *   nested).
   * @param bool $isSection
   *   TRUE for a section move, FALSE for a component move.
   * @param bool $wasNested
   *   Whether the element sat at nested depth before the move.
   * @param bool $isNested
   *   Whether the element sits at nested depth after the move.
   */
  public function __construct(
    HostInterface $host,
    protected string $uuid,
    protected bool $isSection,
    protected bool $wasNested,
    protected bool $isNested,
  ) {
    parent::__construct($host);
  }

  /**
   * Gets the UUID of the moved element.
   */
  public function getUuid(): string {
    return $this->uuid;
  }

  /**
   * Whether the moved element is a section (FALSE for a component).
   */
  public function isSection(): bool {
    return $this->isSection;
  }

  /**
   * Whether the element sat at nested depth before the move.
   */
  public function wasNested(): bool {
    return $this->wasNested;
  }

  /**
   * Whether the element sits at nested depth after the move.
   */
  public function isNested(): bool {
    return $this->isNested;
  }

}
