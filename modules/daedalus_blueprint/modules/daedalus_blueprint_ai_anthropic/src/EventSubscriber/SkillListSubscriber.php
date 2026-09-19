<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai_anthropic\EventSubscriber;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginInterfaces\ConfigAiAgentInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\daedalus_blueprint\SkillsResolverInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects the skills system into AI agent conversations.
 *
 * Two-part injection:
 * 1. System prompt instruction (BuildSystemPromptEvent) — creates the
 *    behavioral obligation to check and invoke skills before acting.
 * 2. Skill catalog (AiAssistantPassContextToAgentEvent) — lists available
 *    skills in a <system-reminder> on the first user message.
 */
class SkillListSubscriber implements EventSubscriberInterface {

  protected const MARKER = 'The following skills are available for use with the Skill tool:';

  protected const SYSTEM_PROMPT_MARKER = '## Skills';

  public function __construct(
    protected SkillsResolverInterface $skillsResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      BuildSystemPromptEvent::EVENT_NAME => ['onBuildSystemPrompt', -20],
      AiAssistantPassContextToAgentEvent::EVENT_NAME => ['onPassContext', -10],
    ];
  }

  /**
   * Injects skill usage instructions into the system prompt.
   */
  public function onBuildSystemPrompt(BuildSystemPromptEvent $event): void {
    $skills = $this->skillsResolver->getSkills();
    if (empty($skills)) {
      return;
    }

    $prompt = $event->getSystemPrompt();
    if (str_contains($prompt, static::SYSTEM_PROMPT_MARKER)) {
      return;
    }

    $instruction = <<<'INSTRUCTION'
## Skills

When the user asks you to perform tasks, check whether any of the available skills match. Skills provide specialized capabilities and domain knowledge.

When a skill matches the user's request, this is a BLOCKING REQUIREMENT: invoke it with the eb_skill tool BEFORE generating any other response about the task, then hold to what it teaches for the rest of the task.

- Available skills are listed in <system-reminder> messages in the conversation; only invoke a skill that appears in that list
- Do not skip skills to save time; they contain critical guidance that changes your approach

INSTRUCTION;

    $event->setSystemPrompt($prompt . "\n" . $instruction);
  }

  /**
   * Injects skill catalog on first turn.
   */
  public function onPassContext(AiAssistantPassContextToAgentEvent $event): void {
    $agent = $event->getAgent();

    if (!$this->isFirstTurn($agent)) {
      return;
    }

    $skills = $this->skillsResolver->getSkills();
    if (empty($skills)) {
      return;
    }

    $message = $this->getFirstUserMessage($agent);
    if (!$message || str_contains($message->getText(), static::MARKER)) {
      return;
    }

    $lines = [static::MARKER];
    $lines[] = '';
    foreach ($skills as $id => $definition) {
      $description = $definition['description'] ?? '';
      $lines[] = "- {$id}: {$description}";
    }

    $reminder = "<system-reminder>\n" . implode("\n", $lines) . "\n</system-reminder>\n\n";
    $message->setText($reminder . $message->getText());
  }

  /**
   * Detects whether this is the first turn of the conversation.
   */
  protected function isFirstTurn(AiAgentInterface $agent): bool {
    if (!$agent instanceof ConfigAiAgentInterface) {
      return TRUE;
    }

    $history = $agent->getChatHistory();
    foreach ($history as $message) {
      if ($message instanceof ChatMessage && $message->getRole() === 'assistant') {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Gets the first user message from the agent's input.
   */
  protected function getFirstUserMessage(AiAgentInterface $agent): ?ChatMessage {
    if ($agent instanceof ConfigAiAgentInterface) {
      $history = $agent->getChatHistory();
      foreach ($history as $message) {
        if ($message instanceof ChatMessage && $message->getRole() === 'user') {
          return $message;
        }
      }
    }

    $messages = $agent->getChatInput()->getMessages();
    foreach ($messages as $message) {
      if ($message instanceof ChatMessage && $message->getRole() === 'user') {
        return $message;
      }
    }

    return NULL;
  }

}
