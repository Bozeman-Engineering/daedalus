<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint_api\Controller\AjaxPageStateRequestTrait;
use Drupal\daedalus_blueprint_api\MutationResponseBuilder;
use Drupal\daedalus_ui\Ajax\HighlightCommand;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_tempstore\History\HistoryManager;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Collects AI-tool mutations and answers the mutation-response contract.
 *
 * AI edits converge on the same mutation-response contract every other
 * mutation path returns ({blueprint, markup, targets, errors, attachments,
 * drupal_commands, history, materials}): AiToolActionSubscriber collects
 * each persisted mutation's entity + targets here during the chat request,
 * and DeepChatResponseListener asks for the built payloads when the chat
 * response goes out. The client applies them through the app's one
 * applyResponse pipeline, so AI edits join the blueprint mirror, subtree
 * swaps, the undo timeline, the materials store and piggybacked commands
 * by construction; there is no parallel selector-based refresh path. The
 * collection is keyed by host (a page's entity, a template's display), so
 * an AI edit on a template answers the same payload a page edit does.
 *
 * Two channels the page render does not describe ride the same payloads:
 * - `history`: the tools' tempstore writes are captured by the history
 *   recorder; each payload flushes them and carries the timeline they
 *   extended, so the client's undo chrome moves with an AI turn exactly
 *   as with a browser mutation (the end-of-request flush would be too
 *   late for the response).
 * - `materials`: a tool that persisted a NON-PAGE entity (a swatch, a
 *   variant) staged nothing and targets nothing, yet the mint must reach
 *   the client store. The entity hooks recorded it on the request's
 *   MaterialsChangeSet; the first payload built carries the delta, and a
 *   turn with no page mutation at all answers with a materials-only
 *   payload (MutationResponseBuilder::materialsPayload()). The delta is
 *   resolved for the CANVAS, the page the chat sits on (the request's
 *   `current_route` context, resolved as every other current-page reader
 *   resolves it), which is not always the host a tool wrote: a turn on
 *   one page may write another host by type and id, and the client store
 *   that applies the delta is the page's.
 *
 * The deferred-processing controller shares buildPayloadFor(): a completed
 * deferred batch answers the same contract.
 */
class MutatedTurn {

  use AjaxPageStateRequestTrait;

  /**
   * Mutations collected during this request, keyed by host UUID.
   *
   * @var array<string, array{host: \Drupal\daedalus_blueprint\Host\HostInterface, targets: array}>
   */
  protected array $mutations = [];

  public function __construct(
    protected MutationResponseBuilder $mutationResponseBuilder,
    protected RouteMatchInterface $routeMatch,
    protected DaedalusUi $daedalusUi,
    protected MaterialsChangeSet $materialsChangeSet,
    protected RouteEntityResolver $routeEntityResolver,
    protected ?HistoryRecorder $historyRecorder = NULL,
    protected ?HistoryManager $historyManager = NULL,
  ) {}

  /**
   * Records a persisted AI-tool mutation for the response payload.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The persisted working copy's host (a page's entity, a template's
   *   display).
   * @param array<int, array{uuid: string, field_name: string}> $targets
   *   The immediate (non-deferred) change targets.
   */
  public function collect(HostInterface $host, array $targets): void {
    if (empty($targets)) {
      return;
    }
    $uuid = $host->uuid();
    $existing = $this->mutations[$uuid]['targets'] ?? [];
    $this->mutations[$uuid] = [
      // Keep the latest working copy: successive tool calls in one turn each
      // persist a fresher host.
      'host' => $host,
      'targets' => array_values(array_unique(array_merge($existing, $targets), SORT_REGULAR)),
    ];
  }

  /**
   * Whether any mutation was collected during this request.
   */
  public function hasMutations(): bool {
    return $this->mutations !== [];
  }

  /**
   * Builds one payload per mutated entity and clears the collection.
   *
   * A turn whose tools changed materials without mutating a page (a swatch
   * mint through eb_create_entity) yields one materials-only payload, so
   * the delta reaches the client store through the same pipeline.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The chat request. Its JSON body's `contexts` carries `view_mode` and
   *   `ajax_page_state` (registered as request-context providers by the
   *   chat's edit-mode chrome).
   *
   * @return array[]
   *   The payloads, oldest entity first, a materials-only payload last when
   *   no page payload carried the delta. Empty when nothing mutated.
   */
  public function buildPayloads(Request $request): array {
    $payloads = [];
    $contexts = Json::decode($request->getContent())['contexts'] ?? [];
    $canvas = $this->canvasOf($contexts);
    if ($this->mutations !== []) {
      $mutations = $this->mutations;
      $this->mutations = [];

      $view_mode = is_string($contexts['view_mode'] ?? NULL) ? $contexts['view_mode'] : 'full';
      // The attachments processor diffs rendered assets against the request's
      // ajax_page_state; without it every payload re-ships all core scripts
      // and replaying add_js resets window.Drupal. The chat request carries it
      // as a context (compressed, as drupalSettings holds it).
      $this->applyAjaxPageState($request, ['ajax_page_state' => $contexts['ajax_page_state'] ?? NULL]);

      foreach ($mutations as $mutation) {
        $payloads[] = $this->buildPayloadFor($request, $mutation['host'], $mutation['targets'], $view_mode, $canvas);
      }
    }
    // The first page payload flushed the delta; what is left was changed by
    // a turn that mutated no page.
    if (!$this->materialsChangeSet->isEmpty()) {
      $payloads[] = $this->mutationResponseBuilder->materialsPayload($canvas);
    }
    return $payloads;
  }

  /**
   * The canvas a chat turn's payloads are for: the page the chat sits on.
   *
   * The client names it as the `current_route` context, the path every
   * current-page reader (the prompt's current-page line, the selection,
   * the page print) resolves through RouteEntityResolver; a request that
   * names none, or names a path that is no page the user may view, has no
   * canvas and its delta rides unfiltered.
   *
   * @param array $contexts
   *   The chat request's decoded `contexts`.
   */
  protected function canvasOf(array $contexts): ?HostInterface {
    $route = $contexts['current_route'] ?? NULL;
    return is_string($route) && $route !== '' ? $this->routeEntityResolver->resolve($route) : NULL;
  }

  /**
   * Builds the mutation-response payload for a host's changes.
   *
   * The response render must behave exactly like an Edit Mode load: the
   * navigationMode cookie is path-scoped to the edited page and never rides
   * this request, so the mode is forced onto the query (the convention every
   * edit-mode AJAX path uses), and the route match is pointed at the host's
   * entity so the canvas derives its route host correctly (a display
   * parameter derives the template host, as core's defaults route does).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request (its ajax_page_state attribute, if applied, feeds
   *   the attachments diff).
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The mutated working copy's host to render.
   * @param array<int, array{uuid: string, field_name: string}|string> $targets
   *   The change targets; a string entry is a selectable key (a style
   *   write's target), rendered verbatim.
   * @param string $view_mode
   *   The view mode the client displays.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas host the payload is for (the page the client applying it
   *   is on, which the mutated host need not be), or NULL when the request
   *   names no page: the materials delta then rides unfiltered.
   *
   * @return array
   *   The decoded mutation-response payload, with a highlight command
   *   appended for the swapped subtrees (played after the markup swap).
   */
  public function buildPayloadFor(Request $request, HostInterface $host, array $targets, string $view_mode, ?HostInterface $canvas): array {
    $entity = $host->entity();
    $result = BlueprintResult::success($host)->withTargets($targets);

    // The timeline the tools' writes extended rides the payload: flush the
    // capture now (the end-of-request fallback fires after this response
    // is built), the same order the blueprint endpoint keeps. Optional the
    // way the endpoint's is: history is daedalus_tempstore's.
    $extra = [];
    if ($this->historyRecorder !== NULL && $this->historyManager !== NULL) {
      $this->historyRecorder->flush();
      $extra['history'] = $this->historyManager->metadata($host);
    }

    $request->query->set('navigationMode', 'edit');
    $route_parameters = $this->routeMatch->getParameters();
    $had_entity = $route_parameters->get('entity');
    $route_parameters->set('entity', $entity);
    // The chat route derived no host earlier in this request; forget that
    // answer so the render derives the host from the parameter just set.
    $this->daedalusUi->clearDerivedHost();

    try {
      $response = $this->mutationResponseBuilder->build($request, $entity, $result, NULL, $extra, $view_mode, $canvas);
      $payload = Json::decode($response->getContent());
    }
    finally {
      $request->query->remove('navigationMode');
      if ($had_entity !== NULL) {
        $route_parameters->set('entity', $had_entity);
      }
      else {
        $route_parameters->remove('entity');
      }
      $this->daedalusUi->clearDerivedHost();
    }

    // Call attention to what the AI changed: highlight each swapped subtree.
    // Rides drupal_commands, which the client plays AFTER the markup swap.
    $selectors = array_map(
      static fn(string $key) => '[data-selectable="' . $key . '"]',
      array_keys($payload['markup'] ?? []),
    );
    if ($selectors !== []) {
      $payload['drupal_commands'][] = (new HighlightCommand($selectors))->render();
    }

    return $payload;
  }

}
