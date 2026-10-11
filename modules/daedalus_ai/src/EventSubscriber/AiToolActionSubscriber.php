<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\EventSubscriber;

use Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent;
use Drupal\daedalus_ai\Ajax\OpenLinkCommand;
use Drupal\daedalus_ai\MutatedTurn;
use Drupal\daedalus_ai\PendingMedia\PendingTargetCollector;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\daedalus_ui\Ajax\HighlightCommand;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ai\UiCommandQueue;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint_ai\Event\AiToolActionEvent;
use Drupal\tool_ai_connector\Plugin\AiFunctionCall\ToolPluginBase;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Translates AI tool actions into client effects.
 *
 * Mutations are collected into the MutatedTurn, whose mutation-response
 * payloads ride the chat response and are applied through the app's one
 * applyResponse pipeline (no parallel refresh path). Every reference is
 * final at compose time (identity-first), so all targets refresh
 * immediately; pending generations only need the persisted host bound
 * onto the PendingTargetCollector, which the response listener flushes as
 * one trigger command. New-entity creation opens the created page. Every
 * effect is keyed by the host the tool persisted (a page's entity or a
 * template's display), never by a fieldable entity.
 */
class AiToolActionSubscriber implements EventSubscriberInterface {

  /**
   * Tool plugin IDs that trigger a workspace toolbar update.
   */
  protected const WORKSPACE_TOOL_IDS = [
    'tool:workspace_switch',
    'tool:workspace_create_and_switch',
  ];

  /**
   * Constructs an AiToolActionSubscriber.
   */
  public function __construct(
    protected UiCommandQueue $commandQueue,
    protected MutatedTurn $mutatedTurn,
    protected PendingTargetCollector $pendingTargets,
    protected DaedalusUi $daedalusUi,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      AiToolActionEvent::class => 'onToolAction',
      AgentToolFinishedExecutionEvent::EVENT_NAME => 'onAgentToolFinished',
    ];
  }

  /**
   * Reacts to AI tool actions from daedalus_blueprint_ai.
   *
   * @param \Drupal\daedalus_blueprint_ai\Event\AiToolActionEvent $event
   *   The tool action event.
   */
  public function onToolAction(AiToolActionEvent $event): void {
    match ($event->getAction()) {
      'entity_persisted' => $this->handleEntityPersisted($event),
      default => NULL,
    };
  }

  /**
   * Reacts to agent tool execution for workspace toolbar updates.
   *
   * @param \Drupal\ai_agents\Event\AgentToolFinishedExecutionEvent $event
   *   The agent tool finished event.
   */
  public function onAgentToolFinished(AgentToolFinishedExecutionEvent $event): void {
    $tool = $event->getTool();
    if (!in_array($tool->getPluginId(), self::WORKSPACE_TOOL_IDS, TRUE)) {
      return;
    }

    if (!$tool instanceof ToolPluginBase) {
      return;
    }

    $plugin = $tool->getPluginInstance();
    if ($plugin === NULL || !$plugin->getResult()->isSuccess()) {
      return;
    }

    $this->handleWorkspaceSwitched();
  }

  /**
   * Collects the mutation and queues deferred/new-page effects.
   *
   * @param \Drupal\daedalus_blueprint_ai\Event\AiToolActionEvent $event
   *   The tool action event.
   */
  protected function handleEntityPersisted(AiToolActionEvent $event): void {
    $context = $event->getContext();
    $host = $context['host'] ?? NULL;
    // A config result (a swatch, a variant: site-wide material with no
    // page) carries no host: nowhere to navigate, nothing to refresh; its
    // mint reaches the client as the turn's materials delta (MutatedTurn),
    // which the entity hooks recorded when the tool persisted it.
    if (!$host instanceof HostInterface) {
      return;
    }

    // Pending generations registered during this turn ride this host's
    // batch: the collector needs a host route for the trigger POST and
    // the completion re-render. The response listener flushes it.
    if ($this->pendingTargets->hasPending()) {
      $this->pendingTargets->bindHost($host);
    }

    // Open a newly created page on its editing surface. Nothing to refresh
    // here: the browser navigates to the fresh render. The URL comes from the
    // spine (canonical for most entities, the site frame among them; core's
    // manage-display layout route for a template).
    if (!empty($context['is_new'])) {
      $entity = $host->entity();
      if ($this->daedalusUi->hasEditingUrl($entity)) {
        $url = $this->daedalusUi->getEditingUrl($entity)->toString();
        $this->commandQueue->add(new OpenLinkCommand($url, $host->label()));
      }
      return;
    }

    // Every reference is final at compose time, so every target joins the
    // turn's mutation-response payload; the pending shimmer rides the
    // trigger command's target keys, not a refresh split.
    $this->mutatedTurn->collect($host, $context['targets'] ?? []);
  }

  /**
   * Queues a workspace switcher toolbar replacement.
   */
  protected function handleWorkspaceSwitched(): void {
    $selector = '.toolbar-block__list:has([class*="toolbar-button--workspaces"])';
    $build = [
      '#lazy_builder' => ['navigation.workspaces_lazy_builders:renderNavigationLinks', []],
      '#create_placeholder' => FALSE,
    ];
    $this->commandQueue->add(new ReplaceCommand($selector, $build));
    $this->commandQueue->add(new InvokeCommand('.wse-workspace-switcher-form', 'removeAttr', ['action']));
    $this->commandQueue->add(new HighlightCommand(['[class*="toolbar-button--workspaces"]']));
  }

}
