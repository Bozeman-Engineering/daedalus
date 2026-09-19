<?php

namespace Drupal\daedalus_edit\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\daedalus_ui\Event\EditableFieldAttributes;

/**
 * Lets subscribers suppress inline-editing attribution for a field render.
 *
 * Dispatched before a field is attributed as inline editable. A subscriber
 * that calls suppress() prevents the field from becoming editable in that
 * render (e.g. renders inside the media library).
 */
class SuppressInlineEditing extends Event {

  /**
   * Whether a subscriber has suppressed inline editing for this render.
   *
   * @var bool
   */
  protected bool $suppressed = FALSE;

  /**
   * Constructs a SuppressInlineEditing event.
   *
   * @param \Drupal\daedalus_ui\Event\EditableFieldAttributes $editableFieldAttributes
   *   The field attribution event about to make the field editable.
   */
  public function __construct(
    protected EditableFieldAttributes $editableFieldAttributes,
  ) {}

  /**
   * Suppresses inline editing for this field render.
   */
  public function suppress(): void {
    $this->suppressed = TRUE;
  }

  /**
   * Whether inline editing has been suppressed.
   *
   * @return bool
   *   TRUE when a subscriber called suppress().
   */
  public function isSuppressed(): bool {
    return $this->suppressed;
  }

  /**
   * Gets the field attribution event.
   *
   * @return \Drupal\daedalus_ui\Event\EditableFieldAttributes
   *   The event about to make the field editable.
   */
  public function getEditableFieldAttributes(): EditableFieldAttributes {
    return $this->editableFieldAttributes;
  }

}
