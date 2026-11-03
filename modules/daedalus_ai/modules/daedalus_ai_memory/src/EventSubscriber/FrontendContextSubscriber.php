<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the frontend's session context out of a managed agent's prompt.
 *
 * The ai_assistant_api module captures the context the frontend sent with
 * a request (the route, the selection, the view mode, the ajax page
 * state) and appends it to the agent's system prompt as a block of lines.
 * For an agent the context spine manages that block is a second road: the
 * current page, its print and the selection already ride each user turn
 * as blocks, and the sidebar's poll requests carry a
 * different context set than the turn's first request, so the lines
 * change between the legs of one turn and the prompt prefix the provider
 * cache depends on is rewritten on every leg after the first.
 *
 * Runs after every daedalus reader of the event (TokenPropagationSubscriber
 * at -10, the page language at 100) and before ai_assistant_api's capture
 * at -1000, and empties the context for the agents listed in
 * enabled_agents; every other agent keeps the contrib behaviour.
 */
final class FrontendContextSubscriber implements EventSubscriberInterface {

  /**
   * The memory settings config name.
   */
  private const SETTINGS = 'daedalus_ai_memory.settings';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiAssistantPassContextToAgentEvent::EVENT_NAME => ['onPassContext', -999],
    ];
  }

  /**
   * Empties the context for an agent the spine manages.
   *
   * @param \Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent $event
   *   The pass-context event.
   */
  public function onPassContext(AiAssistantPassContextToAgentEvent $event): void {
    $enabled = $this->configFactory->get(self::SETTINGS)->get('enabled_agents') ?: [];
    if (in_array($event->getAgent()->getId(), $enabled, TRUE)) {
      $event->setContext([]);
    }
  }

}
