<?php

namespace Drupal\daedalus_ui\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * AJAX command for displaying messages through Edit Mode's message system.
 */
class EditModeMessageCommand implements CommandInterface {

  /**
   * The message to display.
   *
   * @var string
   */
  protected $message;

  /**
   * The message type.
   *
   * @var string
   */
  protected $type;

  /**
   * The element ID this message is associated with.
   *
   * @var string|null
   */
  protected $elementId;

  /**
   * How long to show the message, in milliseconds.
   *
   * @var int|null
   */
  protected $duration;

  /**
   * Constructs an EditModeMessageCommand object.
   *
   * @param string $message
   *   The message to display.
   * @param string $type
   *   The message type ('status', 'warning', 'error', etc.).
   * @param string|null $element_id
   *   Optional element ID if this message is associated with a specific form
   *   element.
   * @param int|null $duration
   *   Optional display duration in milliseconds. When NULL the client falls
   *   back to its per-type default. Use -1 to show the message indefinitely.
   */
  public function __construct(string $message, string $type = 'status', ?string $element_id = NULL, ?int $duration = NULL) {
    $this->message = $message;
    $this->type = $type;
    $this->elementId = $element_id;
    $this->duration = $duration;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $output = [
      'command' => 'editModeMessage',
      'message' => $this->message,
      'type' => $this->type,
    ];

    if ($this->elementId) {
      $output['elementId'] = $this->elementId;
    }

    if ($this->duration !== NULL) {
      $output['duration'] = $this->duration;
    }

    return $output;
  }

}
