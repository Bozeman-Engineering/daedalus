<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Service;

use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Drops file bytes from every chat message after each leg.
 *
 * The runner persists the whole chat history in the threads tempstore after
 * every leg, and every persisted message rides back to the model on every
 * later turn. A message that kept its files would store the bytes on every
 * persist (a base64 image or PDF weighs megabytes in a serialized record) and
 * re-send them, at the provider's per-image and per-document token price, on
 * every turn for the life of the thread. Bytes are delivered once, in the leg
 * they arrive; the ATTACHMENTS block on the user message names each file by
 * its handle and stays in the message text, so the model re-opens a file
 * through view_file when it needs to see it again. Text is never touched
 * here, only the files.
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
