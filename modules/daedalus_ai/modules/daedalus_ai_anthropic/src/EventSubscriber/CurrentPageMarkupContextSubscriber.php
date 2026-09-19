<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_anthropic\EventSubscriber;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_assistant_api\Event\AiAssistantPassContextToAgentEvent;
use Drupal\daedalus_ai\EventSubscriber\AgentUserMessageTrait;
use Drupal\daedalus_ai\RouteEntityResolver;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Dialect\DialectPrinter;
use Drupal\daedalus_blueprint\Dialect\DialectPrintException;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Markup\MarkupProjection;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects the current page's page-language print each turn.
 *
 * The model's first write should need no read: the page the user is
 * looking at is the context of nearly every request, so its dialect print
 * (the same language page_edit writes and the cached catalog teaches)
 * rides the last user message as a <system-reminder> every turn. Aliases
 * the conversation has minted print back as data-alias, keyed by the
 * runner id the agent carries.
 *
 * Cache contract: the system prompt is the stable cached prefix and holds
 * only what is the same on every turn (the dialect catalog, the reading
 * conventions, the op set). The page changes with every edit, so its
 * print rides the user message, never the system prompt; putting it there
 * would invalidate the cached prefix on every turn.
 *
 * The print reads the STAGED entity (the storage handler routes to the
 * tempstore copy when one exists), because the canvas shows staged state,
 * not the saved revision.
 *
 * ONE LIVE PRINT: before injecting, every earlier user message in the
 * agent's persisted history sheds its print block (found by the marker
 * through the closing tag). A print is true for its own turn only; kept,
 * it rides every later call as dead weight and turn 0's copy is pinned by
 * the history trimmer forever. The stripping runs whether or not this turn
 * injects a print (no routed page, no access), so a stale print never
 * outlives its turn.
 *
 * Fallback: the dialect printer refuses, with DialectPrintException, any
 * structure it cannot print losslessly (multi-region layouts, field values
 * outside its grammar). Such a page gets the key-annotated markup print
 * instead, so the sidebar stays usable there and the DOM ops still address
 * every element by its key; the header names which print rides. With no
 * markup projector either, the subscriber is silent.
 *
 * Access rides on RouteEntityResolver: a path the current user cannot view
 * resolves to NULL and nothing is injected.
 *
 * Ordering (all three daedalus_ai_anthropic pass-context subscribers PREPEND
 * to the same message, so later-running blocks land higher): selection
 * (10) runs first, this subscriber (5) next, the current-page metadata
 * reminder (0) last. The model reads metadata, then the page print, then
 * the active selection, then the user's words.
 */
class CurrentPageMarkupContextSubscriber implements EventSubscriberInterface {

  use AgentUserMessageTrait;

  /**
   * Shared prefix of both print headers.
   *
   * Marker collision trap: the metadata marker is "Current page:" and this
   * one "Current page print" (no colon after "page"); neither
   * substring-matches the other, so the guards stay independent. Keep it
   * that way if either header is reworded.
   */
  protected const PAGE_MARKUP_MARKER = "<system-reminder>\nCurrent page print";

  public function __construct(
    protected RouteEntityResolver $routeEntityResolver,
    protected EntityStorageHandler $storageHandler,
    protected MarkupProjection $markupProjection,
    protected NodeTreeBuilder $treeBuilder,
    protected DialectPrinter $dialectPrinter,
    protected DialectCatalogBuilder $catalogBuilder,
    protected DialectAliasMap $aliasMap,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiAssistantPassContextToAgentEvent::EVENT_NAME => ['onPassContext', 5],
    ];
  }

  /**
   * Resolves the current route entity and injects its dialect print.
   */
  public function onPassContext(AiAssistantPassContextToAgentEvent $event): void {
    $this->stripEarlierReminderBlocks($event->getAgent(), self::PAGE_MARKUP_MARKER);

    $context = $event->getContext();
    if (empty($context['current_route'])) {
      return;
    }

    $entity = $this->routeEntityResolver->resolve($context['current_route']);
    if (!$entity) {
      return;
    }

    if ($this->alreadyInjected($event->getAgent())) {
      return;
    }

    // The canvas shows the staged state; print that, not the last-saved
    // revision. The resolver already gated view access on the entity.
    $staged = $this->storageHandler->load($entity->getEntityTypeId(), $entity->id()) ?? $entity;
    if (!$staged instanceof FieldableEntityInterface) {
      return;
    }

    $conversation = (string) ($event->getAgent()->getRunnerId() ?? '');
    try {
      $print = $this->dialectPrinter->print(
        $this->treeBuilder->build($staged),
        $this->catalogBuilder->build(),
        $this->aliasMap->printAliases($staged, $conversation),
      );
      $header = ' (the page in the page language):';
      if (trim($print) === '') {
        $print = '(the page is empty: no layout content yet)';
      }
    }
    catch (DialectPrintException $e) {
      // A structure outside the dialect's print contract: fall back to the
      // key-annotated markup print rather than going silent (the DOM ops
      // still address every element by uuid).
      if (!$this->markupProjection->hasProjector()) {
        return;
      }
      $markup = $this->markupProjection->projectEntity($staged);
      if ($markup === NULL) {
        return;
      }
      $print = $markup;
      $header = ' (this page holds structure outside the page language; key-annotated markup fallback — element data-key values are still valid refs):';
    }

    $this->injectIntoLastUserMessage($event->getAgent(), $header, $print);
  }

  /**
   * Checks if the page print was already injected on this message.
   */
  protected function alreadyInjected(AiAgentInterface $agent): bool {
    $message = $this->getLastUserMessage($agent);
    return $message && str_contains($message->getText(), self::PAGE_MARKUP_MARKER);
  }

  /**
   * Prepends the page print as a system-reminder to the last user message.
   *
   * The print's reading conventions live in the Context Tags protocol on
   * the (cached) system prompt; this per-turn block carries only the
   * header and the print itself.
   */
  protected function injectIntoLastUserMessage(AiAgentInterface $agent, string $header, string $print): void {
    $message = $this->getLastUserMessage($agent);
    if (!$message || str_contains($message->getText(), self::PAGE_MARKUP_MARKER)) {
      return;
    }

    $reminder = self::PAGE_MARKUP_MARKER . $header . "\n\n"
      . $print
      . "\n</system-reminder>\n\n";
    $message->setText($reminder . $message->getText());
  }

}
