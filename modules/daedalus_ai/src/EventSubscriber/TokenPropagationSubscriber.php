<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\EventSubscriber;

use Drupal\ai_agents\Event\BuildSystemPromptEvent;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\daedalus_ai\RouteEntityResolver;
use Drupal\daedalus_blueprint\CurrentEntityContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Propagates current entity and view mode tokens to sub-agents.
 *
 * This subscriber is model-neutral. It ensures sub-agents inherit the current
 * page context regardless of which provider-specific formatting module is
 * active.
 */
class TokenPropagationSubscriber implements EventSubscriberInterface {

  /**
   * View mode resolved during the current request.
   *
   * @var string|null
   */
  protected ?string $viewMode = NULL;

  /**
   * The route host's facts resolved during the current request.
   *
   * @var array|null
   *
   * @see \Drupal\daedalus_ai\RouteEntityResolver::describe()
   */
  protected ?array $entityContext = NULL;

  public function __construct(
    protected RouteEntityResolver $routeEntityResolver,
    protected CurrentEntityContext $currentEntityContext,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiAssistantPassContextToAgentEvent::EVENT_NAME => ['onPassContext', -10],
      BuildSystemPromptEvent::EVENT_NAME => ['onBuildSystemPrompt', -10],
    ];
  }

  /**
   * Captures entity and view mode from the context event.
   */
  public function onPassContext(AiAssistantPassContextToAgentEvent $event): void {
    $context = $event->getContext();

    if (!empty($context['view_mode'])) {
      $this->viewMode = $context['view_mode'];
    }

    if (!empty($context['current_route'])) {
      $host = $this->routeEntityResolver->resolve($context['current_route']);
      if ($host) {
        $this->entityContext = $this->routeEntityResolver->describe($host);

        // Hand the current document's kind to daedalus_blueprint so AI tools
        // and skills can resolve guidance for the page the user is on (a
        // template's guidance is its target bundle's; the site frame has no
        // kind and no guidance of a bundle's). Tools execute with no
        // access to these tokens (see CurrentEntityContext), so this shared
        // service is how that context reaches them.
        if ($this->entityContext['kind'] !== NULL) {
          $this->currentEntityContext->set(
            $this->entityContext['kind']['entity_type'],
            $this->entityContext['kind']['bundle'],
          );
        }
      }
    }
  }

  /**
   * Propagates tokens to sub-agents.
   */
  public function onBuildSystemPrompt(BuildSystemPromptEvent $event): void {
    $view_mode = $this->viewMode
      ?? $event->getTokens()['current_view_mode']
      ?? NULL;
    if ($view_mode) {
      $event->setToken('current_view_mode', $view_mode);
    }

    $entity = $this->entityContext
      ?? $event->getTokens()['current_entity_context']
      ?? NULL;
    if ($entity) {
      $event->setToken('current_entity_context', $entity);
    }
  }

}
