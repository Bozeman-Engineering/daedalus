<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Service;

use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Strips file binary data from chat messages before persistence.
 *
 * Workaround for a contrib bug: ChatMessage::toArray() stores files as
 * raw binary strings, but fromArray() cannot reconstruct them.
 */
class ChatMessageFileStripper {

  /**
   * Removes file binary data from all messages in the agent's chat history.
   *
   * @param object $agent
   *   The AI agent plugin instance.
   */
  public function stripFiles(object $agent): void {
    $history = $agent->getChatHistory();
    if (empty($history)) {
      return;
    }

    $changed = FALSE;
    $filesProperty = NULL;
    foreach ($history as $chatMsg) {
      if (!empty($chatMsg->getFiles())) {
        // @todo Remove when ChatMessage gets a public clearFiles() method.
        if (!$filesProperty) {
          $filesProperty = new \ReflectionProperty(ChatMessage::class, 'files');
        }
        $filesProperty->setValue($chatMsg, []);
        $changed = TRUE;
      }
    }

    if ($changed) {
      $agent->setChatHistory($history);
    }
  }

}
