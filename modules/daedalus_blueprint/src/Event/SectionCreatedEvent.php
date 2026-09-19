<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Event;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\layout_builder\Section;

/**
 * Fired when the add_section operation creates a Layout Builder section.
 *
 * Dispatched for every successful add_section, whatever the anchor: the root
 * entity ('inside'), a sibling section ('before'/'after'), or another
 * section ('inside', nesting). The event fires after the section is inserted
 * and nested changes bubbled to the root entity, so subscribers see the
 * entity in its final post-add state; field changes made here ride the same
 * save as the add itself, and the subscriber declares each one with
 * addTarget() so the operation's result re-renders it.
 *
 * Sections at every depth are values on the root entity (sections all the
 * way down), so the entity the event carries is the one owner every section
 * has: the root entity.
 *
 * A section is born with its real layout — a container from the default
 * ladder, or the explicitly named layout — and keeps it for life, so this
 * event is the one seam for layout-derived state.
 */
class SectionCreatedEvent extends LifecycleEventBase {

  /**
   * Constructs a SectionCreatedEvent.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity the operation ran against.
   * @param \Drupal\layout_builder\Section $section
   *   The inserted section.
   * @param string|null $sectionUuid
   *   The section's daedalus_layout UUID, or NULL when daedalus_layout is not installed.
   * @param bool $dressed
   *   Whether the section arrived dressed (its batch declares its styles);
   *   see LifecycleEventBase::dressed().
   */
  public function __construct(
    FieldableEntityInterface $entity,
    protected Section $section,
    protected ?string $sectionUuid = NULL,
    bool $dressed = FALSE,
  ) {
    parent::__construct($entity, $dressed);
  }

  /**
   * Gets the inserted section.
   */
  public function getSection(): Section {
    return $this->section;
  }

  /**
   * Gets the section's daedalus_layout UUID, or NULL without daedalus_layout.
   */
  public function getSectionUuid(): ?string {
    return $this->sectionUuid;
  }

}
