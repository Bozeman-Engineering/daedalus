<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_anthropic\EventSubscriber;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\daedalus_ai\EventSubscriber\AgentUserMessageTrait;
use Drupal\daedalus_ai\RouteEntityResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects current page entity context using Anthropic conventions.
 *
 * Formats entity metadata as a <system-reminder> block prepended to the last
 * user message, and injects Selection Scope protocol instructions into the
 * system prompt.
 */
class CurrentEntityContextSubscriber implements EventSubscriberInterface {

  use AgentUserMessageTrait;

  protected const CURRENT_PAGE_MARKER = "<system-reminder>\nCurrent page:";

  /**
   * Resolved entity metadata for the current request.
   *
   * @var array|null
   */
  protected ?array $currentEntity = NULL;

  /**
   * The current Layout Builder view mode.
   *
   * @var string|null
   */
  protected ?string $viewMode = NULL;

  public function __construct(
    protected RouteEntityResolver $routeEntityResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiAssistantPassContextToAgentEvent::EVENT_NAME => 'onPassContext',
      BuildSystemPromptEvent::EVENT_NAME => 'onBuildSystemPrompt',
    ];
  }

  /**
   * Resolves the current route entity and injects context.
   */
  public function onPassContext(AiAssistantPassContextToAgentEvent $event): void {
    $context = $event->getContext();

    if (!empty($context['view_mode'])) {
      $this->viewMode = $context['view_mode'];
    }

    if (!empty($context['current_route'])) {
      $entity = $this->routeEntityResolver->resolve($context['current_route']);
      if ($entity) {
        $this->currentEntity = [
          'entity_type' => $entity->getEntityTypeId(),
          'entity_id' => $entity->id(),
          'uuid' => $entity->uuid(),
          'bundle' => method_exists($entity, 'bundle') ? $entity->bundle() : $entity->getEntityTypeId(),
          'label' => $entity->label(),
        ];
      }
    }

    if (!$this->currentEntity && !$this->viewMode) {
      return;
    }

    if ($this->alreadyInjected($event->getAgent())) {
      return;
    }

    $this->injectIntoLastUserMessage($event->getAgent());
  }

  /**
   * Injects Selection Scope protocol into the system prompt.
   */
  public function onBuildSystemPrompt(BuildSystemPromptEvent $event): void {
    // Strip the ai_agents loop-count notice ("This is the Nth time that this
    // agent has been run.") that the agent wrapper hardcodes into the base
    // prompt. This agent declares no default_information_tools, so the line
    // has no payload and is pure noise to a small model. Done unconditionally
    // (before the context guard) because it is present on every turn.
    $event->setSystemPrompt($this->stripLoopCountNotice($event->getSystemPrompt()));

    $entity = $this->currentEntity
      ?? $event->getTokens()['current_entity_context']
      ?? NULL;
    $view_mode = $this->viewMode
      ?? $event->getTokens()['current_view_mode']
      ?? NULL;

    if (!$entity && !$view_mode) {
      return;
    }

    $scope_rule = <<<'INSTRUCTION'
## Selection Scope

When a user message includes an ACTIVE SELECTION, that selection defines the scope of your actions.

Rule 1: Direct references ("this", "it", "the heading", "change this to...", "update it") resolve to the selection. Not the page, not the section. Act immediately, no clarification needed.

Rule 2: Ask for clarification only when the request neither references the selection nor names a scope. "Change the topic" could mean the page or the selection: ask. "Change this to be about dogs" is not ambiguous: proceed.

Rule 3: Never modify elements outside the selection unless the user explicitly names them ("the whole page", "all sections", "everything").

Violating scope destroys the user's work. A user who selects one paragraph and says "change this" will lose their entire page if you rewrite everything.

## Context Tags

User messages include <system-reminder> tags with contextual metadata:
- "Current page" tag: the entity the user is viewing. "This page" / "the content" mean this entity; use it as the default entity for operations. Pass its view_mode value to eb_read_schema.
- "Current page print" tag: the current page in the page language, refreshed every turn, complete. This IS the page's current state: never call a read tool on the current page (read tools are for OTHER entities). Its ids and data-alias names are the refs page_edit ops and style-op CSS selectors speak.
- "ACTIVE SELECTION" tag: the element(s) the user has selected on the page. This is the scope constraint described above.

INSTRUCTION;

    // Append after the agent's own identity + RULES so a small model anchors
    // on who it is first; the scope protocol and the skills block (appended
    // later at a lower priority) follow it.
    $event->setSystemPrompt($event->getSystemPrompt() . "\n\n" . $scope_rule);
  }

  /**
   * Checks if context was already injected.
   */
  protected function alreadyInjected(AiAgentInterface $agent): bool {
    $message = $this->getLastUserMessage($agent);
    return $message && str_contains($message->getText(), self::CURRENT_PAGE_MARKER);
  }

  /**
   * Prepends page context as a system-reminder to the last user message.
   */
  protected function injectIntoLastUserMessage(AiAgentInterface $agent): void {
    $message = $this->getLastUserMessage($agent);
    if (!$message || str_contains($message->getText(), self::CURRENT_PAGE_MARKER)) {
      return;
    }

    $parts = [];
    if ($this->currentEntity) {
      $parts[] = sprintf(
        'Current page: %s (ID: %s, UUID: %s, type: %s, title: "%s").',
        $this->currentEntity['entity_type'],
        $this->currentEntity['entity_id'],
        $this->currentEntity['uuid'],
        $this->currentEntity['bundle'],
        $this->currentEntity['label'],
      );
    }
    if ($this->viewMode) {
      $parts[] = sprintf('View mode: "%s".', $this->viewMode);
    }

    if (empty($parts)) {
      return;
    }

    $reminder = "<system-reminder>\n" . implode(' ', $parts) . "\n</system-reminder>\n\n";
    $message->setText($reminder . $message->getText());
  }

  /**
   * Removes the ai_agents loop-count notice from the system prompt.
   *
   * The agent wrapper (AiAgentEntityWrapper::getDefaultInformationTools)
   * hardcodes "This is the Nth time that this agent has been run." onto the
   * base prompt, separated by a blank line. This agent declares no
   * default_information_tools, so the line carries no payload and only adds
   * noise. Matches the sentence plus its surrounding whitespace and removes it.
   *
   * @param string $prompt
   *   The system prompt to clean.
   *
   * @return string
   *   The prompt without the loop-count notice.
   */
  protected function stripLoopCountNotice(string $prompt): string {
    $cleaned = preg_replace(
      '/\n\nThis is the \S+ time that this agent has been run\. *\n?/',
      '',
      $prompt,
    );
    return $cleaned ?? $prompt;
  }

}
