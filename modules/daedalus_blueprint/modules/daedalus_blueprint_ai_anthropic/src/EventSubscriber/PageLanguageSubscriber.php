<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai_anthropic\EventSubscriber;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\daedalus_blueprint_ai\ConversationContext;
use Drupal\daedalus_blueprint_ai\DialectCatalogPrompt;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Puts the page language into the cached prompt and keys the conversation.
 *
 * Two duties of the runtime contract:
 * 1. The generated catalog (component reference + token roster + op set)
 *    rides the SYSTEM prompt (the stable cached prefix) so the facts
 *    every write needs are pre-paid on every turn. The current PAGE never
 *    rides here: it changes every turn and lives on the user message
 *    (CurrentPageMarkupContextSubscriber), or the cache would break on
 *    every edit.
 * 2. The runner's job id (the conversation's stable identity, stamped on
 *    the agent as runner id) is copied into ConversationContext at
 *    pass-context time, so the page tools thread it into the dialect
 *    alias layer (options['conversation']).
 */
class PageLanguageSubscriber implements EventSubscriberInterface {

  protected const MARKER = '## Page language';

  public function __construct(
    protected DialectCatalogPrompt $catalogPrompt,
    protected ConversationContext $conversationContext,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // After the identity/scope sections (0), before the skills block
      // (-20): the language the agent writes precedes the skills that
      // refine how it writes.
      BuildSystemPromptEvent::EVENT_NAME => ['onBuildSystemPrompt', -15],
      AiAssistantPassContextToAgentEvent::EVENT_NAME => ['onPassContext', 100],
    ];
  }

  /**
   * Appends the page-language catalog to the cached system prompt.
   */
  public function onBuildSystemPrompt(BuildSystemPromptEvent $event): void {
    $prompt = $event->getSystemPrompt();
    if (str_contains($prompt, static::MARKER)) {
      return;
    }
    $event->setSystemPrompt($prompt . "\n\n" . $this->catalogPrompt->render());
  }

  /**
   * Keys the conversation for the tool layer.
   */
  public function onPassContext(AiAssistantPassContextToAgentEvent $event): void {
    $runner_id = $event->getAgent()->getRunnerId();
    if (is_string($runner_id) && $runner_id !== '') {
      $this->conversationContext->set($runner_id);
    }
  }

}
