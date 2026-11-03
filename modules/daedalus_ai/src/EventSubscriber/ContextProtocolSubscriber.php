<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\EventSubscriber;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Puts the Selection Scope and Context Tags protocols on the system prompt.
 *
 * The protocols are the behavioral contract that makes the model respect a
 * selection and read the per-turn blocks the producers state (the current
 * page, its print, the active selection); losing them is a silent safety
 * regression. They ride the cached system prompt, after the agent's own
 * identity and rules and before the page language and the skills
 * obligation, whenever the turn carries page context: the subscriber reads
 * that off the event's tokens, which TokenPropagationSubscriber sets just
 * before it from what this request captured or what a sub-agent's prompt
 * build inherited.
 *
 * The ai_agents loop-count notice ("This is the Nth time that this agent
 * has been run.") the agent wrapper hardcodes into the base prompt is
 * stripped unconditionally: this agent declares no
 * default_information_tools, so the line has no payload, is noise to a
 * small model, and would change the cached prefix on every leg.
 */
final class ContextProtocolSubscriber implements EventSubscriberInterface {

  public const PROTOCOLS = <<<'INSTRUCTION'
## Selection Scope

When a user message includes an ACTIVE SELECTION, that selection defines the scope of your actions.

Rule 1: Direct references ("this", "it", "the heading", "change this to...", "update it") resolve to the selection. Not the page, not the section. Act immediately, no clarification needed.

Rule 2: Ask for clarification only when the request neither references the selection nor names a scope. "Change the topic" could mean the page or the selection: ask. "Change this to be about dogs" is not ambiguous: proceed.

Rule 3: Never modify elements outside the selection unless the user explicitly names them ("the whole page", "all sections", "everything").

Violating scope destroys the user's work. A user who selects one paragraph and says "change this" will lose their entire page if you rewrite everything.

## Context Tags

User messages include <system-reminder> tags with contextual metadata. Each tag is true as of the message it rides; the newest copy in the conversation is the current one, and earlier copies are history.
- "Current page" tag: the entity the user is viewing, the template they are editing (a bundle's default layout, entity_type entity_view_display), or the saved blueprint they are editing on its own canvas (entity_type blueprint). "This page" / "the content" / "the template" / "the blueprint" mean it; use its entity_type and ID as the default target for operations. Pass its view_mode value to eb_read_schema (for a template, read the schema of the type and bundle it renders). When the line says the layout belongs to a template, or that the layout is frozen, structure and style ops on this page are refused: edit the page's own fields here and the layout where the line says.
- "Current page print" tag: the current page in the page language, complete. The newest print in the conversation IS the page's current state: never call a read tool on the current page (read tools are for OTHER entities). Its ids and data-alias names are the refs page_edit ops and style-op CSS selectors speak. An element carrying data-can lists what you may still do to it (content, configure, style, arrange, insert); data-can="" means nothing: do not write to it. Never author data-can.
- "ACTIVE SELECTION" tag: the element(s) the user has selected on the page for the message it rides. This is the scope constraint described above; an earlier message's selection is not the scope of a later one.

INSTRUCTION;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After TokenPropagationSubscriber (-10) sets the context tokens and
      // before the page language (-15) and the workflow (-20): the
      // identity first, then the protocols, then the language the agent
      // writes and the loop every task runs through it.
      BuildSystemPromptEvent::EVENT_NAME => ['onBuildSystemPrompt', -12],
    ];
  }

  /**
   * Strips the loop-count notice and appends the protocols when in context.
   */
  public function onBuildSystemPrompt(BuildSystemPromptEvent $event): void {
    $prompt = self::stripLoopCountNotice($event->getSystemPrompt());
    $tokens = $event->getTokens();
    if (!empty($tokens['current_entity_context']) || !empty($tokens['current_view_mode'])) {
      $prompt .= "\n\n" . self::PROTOCOLS;
    }
    $event->setSystemPrompt($prompt);
  }

  /**
   * Removes the ai_agents loop-count notice from the system prompt.
   *
   * The agent wrapper (AiAgentEntityWrapper::getDefaultInformationTools)
   * hardcodes "This is the Nth time that this agent has been run." onto the
   * base prompt, separated by a blank line. Matches the sentence plus its
   * surrounding whitespace and removes it.
   */
  private static function stripLoopCountNotice(string $prompt): string {
    return preg_replace('/\n\nThis is the \S+ time that this agent has been run\. *\n?/', '', $prompt) ?? $prompt;
  }

}
