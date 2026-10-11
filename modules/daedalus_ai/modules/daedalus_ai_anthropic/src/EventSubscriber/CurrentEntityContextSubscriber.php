<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_anthropic\EventSubscriber;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\daedalus_ai\EventSubscriber\AgentUserMessageTrait;
use Drupal\daedalus_ai\RouteEntityResolver;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects current page host context using Anthropic conventions.
 *
 * Formats the route host's facts (a page's entity, the template a display
 * hosts, or a saved blueprint on its own canvas) as a <system-reminder>
 * block prepended to the last user message, and injects Selection Scope
 * protocol instructions into the system prompt. The line states the host-level refusals in advance (the
 * root verdict RouteEntityResolver::describe() carries, in the spine's
 * own sentences): a page whose layout belongs to its template says so and
 * where the layout is edited, a frozen translation and a required
 * workspace likewise, so the model never learns at a refusal what the
 * read already knew.
 */
class CurrentEntityContextSubscriber implements EventSubscriberInterface {

  use AgentUserMessageTrait;

  protected const CURRENT_PAGE_MARKER = "<system-reminder>\nCurrent page:";

  /**
   * The resolved host's facts for the current request.
   *
   * @var array|null
   *
   * @see \Drupal\daedalus_ai\RouteEntityResolver::describe()
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
      $host = $this->routeEntityResolver->resolve($context['current_route']);
      if ($host) {
        $this->currentEntity = $this->routeEntityResolver->describe($host);
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
- "Current page" tag: the entity the user is viewing, the template they are editing (a bundle's default layout, entity_type entity_view_display), or the saved blueprint they are editing on its own canvas (entity_type blueprint). "This page" / "the content" / "the template" / "the blueprint" mean it; use its entity_type and ID as the default target for operations. Pass its view_mode value to eb_read_schema (for a template, read the schema of the type and bundle it renders). When the line says the layout belongs to a template, or that the layout is frozen, structure and style ops on this page are refused: edit the page's own fields here and the layout where the line says.
- "Current page print" tag: the current page in the page language, refreshed every turn, complete. This IS the page's current state: never call a read tool on the current page (read tools are for OTHER entities). Its ids and data-alias names are the refs page_edit ops and style-op CSS selectors speak. An element carrying data-can lists what you may still do to it (content, configure, style, arrange, insert); data-can="" means nothing: do not write to it. Never author data-can.
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
      if ($this->currentEntity['template'] !== NULL) {
        // A template: say what editing it reaches (every entity of the kind
        // without a layout of its own) and that its field blocks show sample
        // content, since the model otherwise reads the print as one page.
        $parts[] = sprintf(
          'Current page: %s (a template, not a page: %s ID: %s, UUID: %s). Editing it changes every %s %s without a layout of its own; its field blocks show sample content, never a real entity\'s.',
          $this->currentEntity['template'],
          $this->currentEntity['entity_type'],
          $this->currentEntity['entity_id'],
          $this->currentEntity['uuid'],
          $this->currentEntity['kind']['bundle'],
          $this->currentEntity['kind']['entity_type'],
        );
      }
      elseif ($this->currentEntity['blueprint'] !== NULL) {
        // A saved blueprint on its own canvas: its document is material for
        // hosts of its subject's kind, so say what editing it reaches (the
        // saved definition, hence every later placement; placements already
        // on pages are independent copies) and that its field blocks show
        // the subject's sample content.
        $parts[] = sprintf(
          'Current page: %s (a saved blueprint, not a page: %s ID: %s, UUID: %s). Editing it changes the blueprint every later placement lands; placements already on pages are independent copies. Its field blocks are the %s %s\'s fields and show sample content, never a real entity\'s.',
          $this->currentEntity['blueprint'],
          $this->currentEntity['entity_type'],
          $this->currentEntity['entity_id'],
          $this->currentEntity['uuid'],
          $this->currentEntity['kind']['bundle'],
          $this->currentEntity['kind']['entity_type'],
        );
      }
      else {
        $parts[] = sprintf(
          'Current page: %s (ID: %s, UUID: %s, type: %s, title: "%s").',
          $this->currentEntity['entity_type'],
          $this->currentEntity['entity_id'],
          $this->currentEntity['uuid'],
          $this->currentEntity['bundle'],
          $this->currentEntity['label'],
        );
      }
      // The host-level refusals, in advance: what the root verdict refuses
      // and where the gesture belongs, in the spine's sentences.
      array_push($parts, ...VerdictStamper::explain($this->currentEntity['verdict'] ?? []));
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
