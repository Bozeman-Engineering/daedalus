<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\EventSubscriber;

use Drupal\ai\Service\HostnameFilter;
use Drupal\daedalus_ai\MutatedTurn;
use Drupal\daedalus_ai\PendingMedia\PendingTargetCollector;
use Drupal\daedalus_ai\UiCommandQueue;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Injects queued UI commands and mutation payloads into chat responses.
 *
 * This listener is also the DISPLAY BOUNDARY for the assistant's text: the
 * page-builder agent runs with transport hostname filtering disabled
 * (hostname_filter_disabled on the daedalus agent, so tool arguments
 * reach the op spine byte-intact), and the chat link protection that
 * filtering provided re-lands here instead, where output actually becomes
 * output. Every leg's `html` body value goes through the ai module's
 * hostname filter with settings-derived defaults before it reaches the
 * browser — the per-call full-trust DTO the agent path sets is scoped to
 * the one provider invocation and long restored by response time — so a
 * prompt-injected model cannot show an editor a disallowed-hostname link.
 * DeepChatApi's Xss::filter already ran on the html; it strips tags, not
 * hostnames, so this is not redundant.
 *
 * Two injections ride every DeepChat API JSON response:
 * - `drupal_commands`: queued AJAX side effects (open-link, workspace
 *   toolbar, trigger-deferred), played by the client command processor.
 * - `blueprint_responses`: one mutation-response payload per entity the AI
 *   tools mutated during the turn, applied by the client through the app's
 *   applyResponse pipeline (mirror replacement, subtree swaps, attachments,
 *   piggybacked commands), the same path every other mutation rides.
 *
 * A chat turn spans several HTTP requests: the continuation loop polls once
 * per LLM leg, and each poll response passes through here. Immediate
 * commands flush onto whichever response their leg produced, but
 * end-of-turn commands (EndOfTurnCommandInterface: open-link, the deferred
 * trigger) are HELD across intermediate poll responses — the body's truthy
 * `should_continue` marks those — in a thread-keyed tempstore, and released
 * together on the turn's final response, sorted so the deferred trigger's
 * localStorage stash exists before open-link navigates. Firing them
 * mid-turn would navigate the polling document away and land the final
 * response (summary text, trigger) in a dead page.
 */
class DeepChatResponseListener implements EventSubscriberInterface {

  /**
   * Tempstore collection holding a turn's end-of-turn commands per thread.
   */
  protected const HELD_COLLECTION = 'daedalus_ai_held_commands';

  /**
   * Held commands older than this many seconds are dropped at release.
   *
   * A turn's polls are seconds apart, so anything this old belongs to a
   * turn that died mid-loop (browser closed, error path that never
   * finalized). Dropping is benign — the user stays where they are — while
   * replaying a stale open-link at the end of an unrelated later exchange
   * would navigate them without warning.
   */
  protected const HELD_TTL = 600;

  /**
   * Constructs a DeepChatResponseListener.
   *
   * @param \Drupal\daedalus_ai\UiCommandQueue $commandQueue
   *   The UI command queue.
   * @param \Drupal\daedalus_ai\MutatedTurn $mutatedTurn
   *   The turn's collected mutations.
   * @param \Drupal\daedalus_ai\PendingMedia\PendingTargetCollector $pendingTargets
   *   The turn's pending generations, flushed as one trigger command.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $tempStoreFactory
   *   Private tempstore, holding end-of-turn commands across the turn's
   *   poll requests (per user, keyed by chat thread).
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, stamping held commands for the staleness guard.
   * @param \Drupal\ai\Service\HostnameFilter $hostnameFilter
   *   The ai module's hostname filter, applied to the assistant's html at
   *   this display boundary (the transport is inert for the page-builder
   *   agent).
   */
  public function __construct(
    protected UiCommandQueue $commandQueue,
    protected MutatedTurn $mutatedTurn,
    protected PendingTargetCollector $pendingTargets,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected TimeInterface $time,
    protected HostnameFilter $hostnameFilter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => 'onResponse',
    ];
  }

  /**
   * Injects UI commands and mutation payloads into the DeepChat response.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    $route = $event->getRequest()->attributes->get('_route');
    if ($route !== 'ai_chatbot.api') {
      return;
    }

    $response = $event->getResponse();
    if (!$response instanceof JsonResponse) {
      return;
    }

    $data = json_decode($response->getContent(), TRUE);
    if (!is_array($data)) {
      return;
    }

    $injected = $this->injectInto($data, $event->getRequest());
    if ($injected !== $data) {
      $response->setContent(json_encode($injected));
    }
  }

  /**
   * Adds the turn's command and mutation payloads to a chat response body.
   *
   * Public so a test route can exercise the exact injection a chat turn
   * gets without an LLM in the loop.
   *
   * @param array $data
   *   The decoded chat response body. A truthy `should_continue` marks an
   *   intermediate poll response of a still-running turn; its absence (the
   *   final leg, an error body, a single-leg turn) marks the turn complete.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The chat request (its body contexts feed the payload render; its
   *   `thread_id` keys the end-of-turn holding store).
   *
   * @return array
   *   The body with `drupal_commands` and/or `blueprint_responses` added
   *   when the turn produced any.
   */
  public function injectInto(array $data, Request $request): array {
    // Output filtering at the display boundary: the assistant's text is
    // filtered for disallowed hostnames on EVERY leg — intermediate polls
    // render in the sidebar too. The filter runs with settings-derived
    // defaults (ai.settings allowed_hosts); the agent path's per-call
    // full-trust DTO was scoped to the provider invocation and is already
    // restored. Only the html (display) value is filtered: the command and
    // mutation payloads below are structured app data, not model output.
    if (is_string($data['html'] ?? NULL)) {
      $data['html'] = $this->hostnameFilter->filterText($data['html']);
    }

    // The turn's pending generations flush as ONE trigger command riding the
    // same drupal_commands channel as every other queued effect. The
    // collector is request-scoped, so this must run on EVERY leg that
    // registered generations; the batch lands in tempstore now, and the
    // trigger command itself is end-of-turn (held below), so nothing fires
    // until the turn completes.
    if ($trigger = $this->pendingTargets->flush($request)) {
      $this->commandQueue->add($trigger);
    }

    $commands = $this->commandQueue->flush();
    $end_of_turn = $this->flushEndOfTurnFor($data, $request);
    if ($end_of_turn !== []) {
      $commands = array_merge($commands, $end_of_turn);
    }
    if (!empty($commands)) {
      $data['drupal_commands'] = $commands;
    }

    $payloads = $this->mutatedTurn->buildPayloads($request);
    if ($payloads !== []) {
      $data['blueprint_responses'] = $payloads;
    }

    return $data;
  }

  /**
   * Holds or releases the end-of-turn commands for this response.
   *
   * On an intermediate poll response the leg's end-of-turn commands are
   * appended to the thread's held list and nothing is returned; on the final
   * response the held list joins the current leg's commands and the merged
   * set is released, sorted by endOfTurnOrder() (stable, so ties keep queue
   * order). Without a thread key there is nothing to hold against, so the
   * commands release immediately — a thread-less caller has no continuation
   * loop to strand.
   *
   * @param array $data
   *   The decoded chat response body (read for `should_continue`).
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The chat request (read for `thread_id`).
   *
   * @return array[]
   *   Rendered command arrays to inject on this response; empty when held.
   */
  protected function flushEndOfTurnFor(array $data, Request $request): array {
    $end_of_turn = $this->commandQueue->flushEndOfTurn();

    $body = Json::decode($request->getContent());
    $thread = is_array($body) && is_string($body['thread_id'] ?? NULL) ? $body['thread_id'] : NULL;
    $store = $thread !== NULL ? $this->tempStoreFactory->get(self::HELD_COLLECTION) : NULL;

    if (!empty($data['should_continue'])) {
      // Intermediate leg: hold this leg's end-of-turn commands for the
      // final response.
      if ($store !== NULL) {
        if ($end_of_turn !== []) {
          $now = $this->time->getCurrentTime();
          $held = $store->get($thread) ?? [];
          foreach ($end_of_turn as $entry) {
            $held[] = $entry + ['queued' => $now];
          }
          $store->set($thread, $held);
        }
        return [];
      }
      // No thread to hold against: fall through and release now (the
      // pre-hold behavior; better a mid-turn effect than a lost one).
    }
    elseif ($store !== NULL && ($held = $store->get($thread))) {
      $store->delete($thread);
      $fresh_after = $this->time->getCurrentTime() - self::HELD_TTL;
      $held = array_filter($held, fn(array $entry) => ($entry['queued'] ?? 0) >= $fresh_after);
      $end_of_turn = array_merge(array_values($held), $end_of_turn);
    }

    if ($end_of_turn === []) {
      return [];
    }
    usort($end_of_turn, fn(array $a, array $b) => $a['order'] <=> $b['order']);
    return array_column($end_of_turn, 'command');
  }

}
