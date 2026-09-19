<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Trims chat history to keep the context window bounded.
 *
 * Segments messages into "turns" (a user message + all subsequent
 * assistant/tool messages until the next user message). When the total
 * message count exceeds max_history_messages, drops middle turns while
 * preserving the first turn and the last keep_recent_turns turns.
 */
class ChatHistoryTrimmer {

  /**
   * Constructs ChatHistoryTrimmer.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Trims chat history to stay within configured limits.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $chatHistory
   *   The chat history messages.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage[]
   *   The trimmed chat history.
   */
  public function trim(array $chatHistory): array {
    $config = $this->configFactory->get('daedalus_ai_memory.settings');
    $maxMessages = (int) $config->get('max_history_messages');
    $keepRecentTurns = (int) $config->get('keep_recent_turns');

    if (count($chatHistory) <= $maxMessages) {
      return $chatHistory;
    }

    $turns = $this->segmentIntoTurns($chatHistory);

    if (count($turns) <= 1) {
      return $chatHistory;
    }

    if (count($turns) <= ($keepRecentTurns + 1)) {
      return $chatHistory;
    }

    $firstTurn = [$turns[0]];
    $recentTurns = array_slice($turns, -$keepRecentTurns);
    $kept = array_merge($firstTurn, $recentTurns);

    $result = [];
    foreach ($kept as $turn) {
      foreach ($turn as $message) {
        $result[] = $message;
      }
    }

    return $result;
  }

  /**
   * Segments a flat message array into turns.
   *
   * A turn starts with a user message and includes all subsequent
   * assistant/tool messages until the next user message.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $messages
   *   The flat message array.
   *
   * @return array
   *   An array of turns, where each turn is an array of ChatMessage objects.
   */
  protected function segmentIntoTurns(array $messages): array {
    $turns = [];
    $currentTurn = [];

    foreach ($messages as $message) {
      $role = $message->getRole();
      if ($role === 'user' && !empty($currentTurn)) {
        $turns[] = $currentTurn;
        $currentTurn = [];
      }
      $currentTurn[] = $message;
    }

    if (!empty($currentTurn)) {
      $turns[] = $currentTurn;
    }

    return $turns;
  }

}
