<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_anthropic\EventSubscriber;

use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\daedalus_ai\ElementSelectionResolver;
use Drupal\daedalus_ai\EventSubscriber\AgentUserMessageTrait;
use Drupal\daedalus_ai\RouteEntityResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects selected element context using Anthropic conventions.
 *
 * Formats resolved elements as a numbered <system-reminder> ACTIVE SELECTION
 * block prepended to the last user message. Each element names what it
 * wears (its variants by label and spelling) and the stencil it was placed
 * from (by id and label) so imprecise references resolve against the
 * materials and "update the stencil this came from" needs no print read;
 * per-turn facts, never on the prefix. What an element KIND is for is not
 * repeated here: the cached design system brief and the schema teach
 * elements once, and a selection block that restated it paid that teaching
 * again per selected element per turn.
 *
 * ONE LIVE SELECTION: before injecting, every earlier user message in the
 * agent's persisted history sheds its selection block. A selection is the
 * scope of its own turn only (the Selection Scope protocol makes it law),
 * so a stale block is a scope the user may since have released. The
 * stripping runs whether or not this turn carries a selection.
 */
class SelectedElementContextSubscriber implements EventSubscriberInterface {

  use AgentUserMessageTrait;

  protected const SELECTED_ELEMENTS_MARKER = "<system-reminder>\nACTIVE SELECTION";

  public function __construct(
    protected RouteEntityResolver $routeEntityResolver,
    protected ElementSelectionResolver $elementSelectionResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiAssistantPassContextToAgentEvent::EVENT_NAME => ['onPassContext', 10],
    ];
  }

  /**
   * Resolves selected elements and injects them into the last user message.
   */
  public function onPassContext(AiAssistantPassContextToAgentEvent $event): void {
    $this->stripEarlierReminderBlocks($event->getAgent(), self::SELECTED_ELEMENTS_MARKER);

    $context = $event->getContext();

    if (empty($context['selected_elements']) || !is_array($context['selected_elements'])) {
      return;
    }

    if ($this->alreadyInjected($event->getAgent())) {
      return;
    }

    $current_route = $context['current_route'] ?? NULL;
    $entity = $current_route ? $this->routeEntityResolver->resolve($current_route) : NULL;

    $resolved = $this->elementSelectionResolver->resolve(
      $context['selected_elements'],
      $entity,
    );

    if (empty($resolved)) {
      return;
    }

    $this->injectIntoLastUserMessage($event->getAgent(), $resolved);
  }

  /**
   * Checks if context was already injected.
   */
  protected function alreadyInjected(AiAgentInterface $agent): bool {
    $message = $this->getLastUserMessage($agent);
    return $message && str_contains($message->getText(), self::SELECTED_ELEMENTS_MARKER);
  }

  /**
   * Prepends selected element context as a system-reminder block.
   *
   * @param \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent
   *   The agent instance.
   * @param array<int, array{description: string, materials?: string, placed_from?: string}> $elements
   *   Resolved elements keyed by selection number.
   */
  protected function injectIntoLastUserMessage(AiAgentInterface $agent, array $elements): void {
    $message = $this->getLastUserMessage($agent);
    if (!$message || str_contains($message->getText(), self::SELECTED_ELEMENTS_MARKER)) {
      return;
    }

    $lines = [];
    foreach ($elements as $number => $element) {
      $lines[] = '- Element ' . $number . ': ' . $element['description'];
      if (!empty($element['materials'])) {
        $lines[] = '  Wears: ' . $element['materials'];
      }
      if (!empty($element['placed_from'])) {
        $lines[] = '  Placed from: ' . $element['placed_from'];
      }
    }

    $tag_content = "ACTIVE SELECTION: the user has selected the following element(s) on the page:\n"
      . implode("\n", $lines);
    $reminder = "<system-reminder>\n" . $tag_content . "\n</system-reminder>\n\n";

    $message->setText($reminder . $message->getText());
  }

}
