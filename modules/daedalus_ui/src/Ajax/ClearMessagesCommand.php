<?php

namespace Drupal\daedalus_ui\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * AJAX command for clearing all messages from the message area.
 *
 * This command uses Drupal core's Message API to clear all messages
 * without removing the message container, allowing future messages
 * to be added properly.
 */
class ClearMessagesCommand implements CommandInterface {

  /**
   * Implements Drupal\Core\Ajax\CommandInterface::render().
   */
  public function render() {
    return [
      'command' => 'clearMessages',
    ];
  }

}
