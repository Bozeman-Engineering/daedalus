<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\EventSubscriber;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;

/**
 * Provides a helper to find the last user message in an agent's chat history.
 */
trait AgentUserMessageTrait {

  /**
   * Finds the last user ChatMessage on the agent.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent
   *   The agent instance.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage|null
   *   The last user message, or NULL if none found.
   */
  protected function getLastUserMessage(AiAgentInterface $agent): ?ChatMessage {
    if ($agent instanceof ConfigAiAgentInterface) {
      $history = $agent->getChatHistory();
      for ($i = count($history) - 1; $i >= 0; $i--) {
        if ($history[$i] instanceof ChatMessage && $history[$i]->getRole() === 'user') {
          return $history[$i];
        }
      }
    }

    $messages = $agent->getChatInput()->getMessages();
    for ($i = count($messages) - 1; $i >= 0; $i--) {
      if ($messages[$i] instanceof ChatMessage && $messages[$i]->getRole() === 'user') {
        return $messages[$i];
      }
    }

    return NULL;
  }

  /**
   * Removes a per-turn reminder block from every user message before the last.
   *
   * A per-turn block (the page print, the active selection) is true for the
   * turn it was injected on and stale on every later one: left in the
   * persisted history it rides every call as dead weight (a page print is
   * thousands of tokens, and the trimmer pins turn 0 forever) and, for a
   * selection, as a scope the user may since have released. Called before
   * the current turn's block is injected, so the history holds exactly one
   * live block: the last user message's. The block spans the marker through
   * its closing tag and the blank line the injection appends; the user's own
   * words after it stay.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent
   *   The agent whose persisted history is walked.
   * @param string $marker
   *   The block's opening marker (the tag plus its header prefix).
   */
  protected function stripEarlierReminderBlocks(AiAgentInterface $agent, string $marker): void {
    if (!$agent instanceof ConfigAiAgentInterface) {
      return;
    }
    $last = $this->getLastUserMessage($agent);
    $pattern = '/' . preg_quote($marker, '/') . '.*?<\/system-reminder>\n*/s';
    foreach ($agent->getChatHistory() as $message) {
      if (!$message instanceof ChatMessage || $message->getRole() !== 'user' || $message === $last) {
        continue;
      }
      if (str_contains($message->getText(), $marker)) {
        $message->setText(preg_replace($pattern, '', $message->getText()));
      }
    }
  }

}
