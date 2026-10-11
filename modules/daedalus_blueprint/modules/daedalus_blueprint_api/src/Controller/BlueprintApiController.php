<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\Markup;
use Drupal\daedalus_blueprint\BlueprintOperationsInterface;
use Drupal\daedalus_blueprint\Dialect\CssFragmentDoor;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Dialect\DialectPrinter;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint_api\Event\BlueprintReadEvent;
use Drupal\daedalus_blueprint_api\MutationResponseBuilder;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_blueprint_api\Render\SubtreeRenderer;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;
use Drupal\daedalus_tempstore\History\HistoryManager;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * HTTP API for entity blueprint operations.
 *
 * The browser-facing mutation path of the page builder: the editing client
 * POSTs operation batches (the same operation grammar the AI tools use) and
 * receives the mutation-response contract back. Reads serve the staged
 * (tempstore) state so the client always sees the working copy.
 *
 * Route access is entity access (view for reads, update for mutations) plus
 * the CSRF request header check on POST. Field-level access is enforced
 * inside the operations layer per user.
 *
 * Beside the mutation door sits the peek door (peek()): the same batch
 * grammar applied to the same staged working copy, persisted nowhere and
 * answered as a document holding one element of the result, for a frame
 * that shows what a gesture would give.
 */
final class BlueprintApiController extends ControllerBase {

  use AjaxPageStateRequestTrait;

  /**
   * The attribute of the host's own style tag inside its rendered markup.
   *
   * The inline lane of daedalus_stylish prints a host's style map as one
   * <style> element inside the entity's markup, identified by this
   * attribute (CssStyleBackend::build()); the peek document carries that
   * tag beside the cut element, since an element's CSS never travels
   * inside the element. A site without daedalus_stylish renders no such
   * tag and the document holds the element alone.
   */
  public const ENTITY_STYLE_ATTRIBUTE = 'data-daedalus-stylish-entity';

  public function __construct(
    protected readonly BlueprintBackendResolver $backendResolver,
    protected readonly BlueprintOperationsInterface $blueprintOperations,
    protected readonly EntityStorageHandler $storageHandler,
    protected readonly MutationResponseBuilder $responseBuilder,
    protected readonly LoggerInterface $logger,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly NodeTreeBuilder $treeBuilder,
    protected readonly DialectCatalogBuilder $catalogBuilder,
    protected readonly DialectPrinter $dialectPrinter,
    protected readonly CssFragmentDoor $fragmentDoor,
    protected readonly HostResolver $hostResolver,
    protected readonly SubtreeRenderer $subtreeRenderer,
    protected readonly ?HistoryRecorder $historyRecorder = NULL,
    protected readonly ?HistoryManager $historyManager = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_blueprint.backend_resolver'),
      $container->get('daedalus_blueprint.operations'),
      $container->get('daedalus_blueprint.storage_handler'),
      $container->get('daedalus_blueprint_api.mutation_response_builder'),
      $container->get('logger.channel.daedalus_blueprint'),
      $container->get('event_dispatcher'),
      $container->get('daedalus_blueprint.node_tree_builder'),
      $container->get('daedalus_blueprint.dialect_catalog_builder'),
      $container->get('daedalus_blueprint.dialect_printer'),
      $container->get('daedalus_blueprint.css_fragment_door'),
      $container->get('daedalus_blueprint.host_resolver'),
      $container->get('daedalus_blueprint_api.subtree_renderer'),
      $container->has('daedalus_tempstore.history_recorder')
        ? $container->get('daedalus_tempstore.history_recorder')
        : NULL,
      $container->has('daedalus_tempstore.history_manager')
        ? $container->get('daedalus_tempstore.history_manager')
        : NULL,
    );
  }

  /**
   * Applies a batch of operations to an entity.
   *
   * Request body: {"operations": [{"op": "...", ...}, ...], "langcode"?: "x",
   * "view_mode"?: "full", "ajax_page_state"?: {...}, "thread_id"?: "x"}. The
   * view mode drives the markup re-render; ajax_page_state (as drupal.ajax
   * sends it) lets the response omit already-loaded assets; thread_id names
   * the chat conversation whose aliases the ops resolve against (the op
   * spine's 'conversation' option: the element ids an author chose and the
   * files the conversation holds, so {_file: name} places an attachment),
   * absent when the client has no conversation.
   * Operations use the batch grammar from BlueprintOperations and are applied
   * atomically — all succeed or all roll back, and a request is one unit of
   * change. Successful mutations persist through the storage handler
   * (tempstore when available, database otherwise).
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, upcast by the route for access checking.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The mutation-response contract.
   */
  public function mutate(Request $request, string $entity_type, EntityInterface $entity): JsonResponse {
    // The canvas every payload of this door is for: the client posts to
    // the host it is editing, so the route entity's host is the page it
    // is on (NULL for a config entity that hosts no document).
    $canvas = $this->hostOf($entity);
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      return $this->responseBuilder->error(
        'INVALID_REQUEST_BODY',
        'The request body must be a JSON object.',
        400,
        canvas: $canvas,
      );
    }

    $operations = $body['operations'] ?? NULL;
    if (!is_array($operations) || $operations === [] || !array_is_list($operations)) {
      return $this->responseBuilder->error(
        'MISSING_OPERATIONS',
        'The request body must contain a non-empty "operations" array.',
        400,
        canvas: $canvas,
      );
    }

    $langcode = is_string($body['langcode'] ?? NULL) ? $body['langcode'] : NULL;
    $view_mode = is_string($body['view_mode'] ?? NULL) && $body['view_mode'] !== '' ? $body['view_mode'] : 'full';
    $conversation = is_string($body['thread_id'] ?? NULL) ? $body['thread_id'] : '';
    $this->applyAjaxPageState($request, $body);

    $backend = $this->backendResolver->resolveFor($entity);
    if ($backend === NULL) {
      return $this->responseBuilder->error(
        'UNSUPPORTED_ENTITY_TYPE',
        "No blueprint backend supports $entity_type:{$entity->id()}.",
        400,
        canvas: $canvas,
      );
    }

    // Reload through the backend so operations apply to the staged
    // (tempstore) working copy rather than the saved entity.
    $working = $backend->load($entity_type, $entity->id());
    if ($working === NULL) {
      return $this->responseBuilder->error(
        'ENTITY_NOT_FOUND',
        "Entity $entity_type:{$entity->id()} could not be loaded.",
        404,
        canvas: $canvas,
      );
    }

    try {
      // The request's langcode names the translation the ops mutate and
      // the one the response renders: one language for the whole round.
      // The thread id is the conversation the alias layer resolves for,
      // exactly as the page tools pass it.
      $result = $backend->batchOperations($working, $operations, [
        'langcode' => $langcode,
        'conversation' => $conversation,
      ]);

      $extra = [];
      if ($result->isSuccess()) {
        // Annotate the history entry this persist will produce: the client
        // names the gesture ("Moved Heading"), the result supplies targets.
        if ($result instanceof BlueprintResult) {
          $label = is_string($body['label'] ?? NULL) ? $body['label'] : NULL;
          $this->historyRecorder?->annotate($label, $result->getTargets());
        }
        $extra['persisted'] = $this->persistResult($backend, $result);
        // Flush now (instead of the end-of-request fallback) so the response
        // carries the timeline the mutation just extended.
        if ($this->historyRecorder !== NULL && $this->historyManager !== NULL && $result instanceof BlueprintResult) {
          $this->historyRecorder->flush();
          $extra['history'] = $this->historyManager->metadata($result->getHost());
        }
      }

      return $this->responseBuilder->build($request, $entity, $result, $langcode, $extra, $view_mode, $canvas);
    }
    catch (WorkspaceRequiredException $e) {
      return $this->responseBuilder->error('WORKSPACE_REQUIRED', $e->getMessage(), 409, canvas: $canvas);
    }
    catch (\Throwable $e) {
      // Every failure answers the contract's shape: a block whose build or
      // access check throws an \Error (an assertion under dev settings, a
      // type error) would otherwise leave the door as a PHP fatal and a raw
      // 500 the client cannot read.
      $this->logger->error('Blueprint mutation failed for @type:@id: @message', [
        '@type' => $entity_type,
        '@id' => $entity->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->responseBuilder->error(
        'INTERNAL_ERROR',
        'An unexpected error occurred while applying the operations.',
        500,
        canvas: $canvas,
      );
    }
  }

  /**
   * Shows what a batch would give: a dry run, answered as a bare document.
   *
   * Request body: {"operations": [...], "show": "block:{uuid}" |
   * "container:{uuid}", "langcode"?: "x", "view_mode"?: "full"}. The
   * operations are the batch grammar mutate() takes (the client sends the
   * batch a drop would send, uuids of its own included), and show names
   * the element of the result to answer, by the selectable key the client
   * minted for it. The door is mutate() minus its persist: the staged
   * working copy is loaded as mutate() loads it, the batch is applied to
   * it in memory, and nothing is persisted, no history entry is annotated
   * and no materials delta is flushed; the next request finds the staged
   * copy as it was. The host's canvas is rendered as the mutation
   * response renders it (SubtreeRenderer::canvas(): the same render, no
   * render cache write) and the answer is a document whose main content
   * is the host's own style tag (ENTITY_STYLE_ATTRIBUTE) and the element
   * cut by show's key, with the render's attachments, so the element
   * shows in a frame with its own styles and, under daedalus_ui, in the
   * site's skin with none of the page around it: the route declares
   * daedalus_ui's bare option (see the routing file).
   *
   * A request outside the contract answers the shared error shape as
   * mutate() does (400; 404 for an entity that cannot be loaded or an
   * element the render does not stamp, which it stamps for any viewer
   * with the edit-mode permission); a batch the spine refuses answers the
   * mutation contract's error shape with 422 and no document.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, upcast by the route for access checking.
   *
   * @return \Symfony\Component\HttpFoundation\Response|array
   *   The document's main content as a render array; the JSON error shape
   *   for a refusal.
   */
  public function peek(Request $request, string $entity_type, EntityInterface $entity): Response|array {
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      return $this->responseBuilder->error(
        'INVALID_REQUEST_BODY',
        'The request body must be a JSON object.',
        400,
      );
    }
    $operations = $body['operations'] ?? NULL;
    if (!is_array($operations) || $operations === [] || !array_is_list($operations)) {
      return $this->responseBuilder->error(
        'MISSING_OPERATIONS',
        'The request body must contain a non-empty "operations" array.',
        400,
      );
    }
    $show = $body['show'] ?? NULL;
    if (!is_string($show) || !$this->isPeekKey($show)) {
      return $this->responseBuilder->error(
        'INVALID_REQUEST',
        'The request body must carry "show": the selectable key of the element to show, block:{uuid} or container:{uuid}.',
        400,
      );
    }
    $langcode = is_string($body['langcode'] ?? NULL) ? $body['langcode'] : NULL;
    $view_mode = is_string($body['view_mode'] ?? NULL) && $body['view_mode'] !== '' ? $body['view_mode'] : 'full';

    $backend = $this->backendResolver->resolveFor($entity);
    if ($backend === NULL) {
      return $this->responseBuilder->error(
        'UNSUPPORTED_ENTITY_TYPE',
        "No blueprint backend supports $entity_type:{$entity->id()}.",
        400,
      );
    }
    // The staged working copy, as mutate() loads it: what the actor sees on
    // the canvas is what the peek builds on.
    $working = $backend->load($entity_type, $entity->id());
    if ($working === NULL) {
      return $this->responseBuilder->error(
        'ENTITY_NOT_FOUND',
        "Entity $entity_type:{$entity->id()} could not be loaded.",
        404,
      );
    }

    try {
      $result = $backend->batchOperations($working, $operations, ['langcode' => $langcode]);
      if (!$result->isSuccess()) {
        return $this->responseBuilder->refused($entity, $result, $langcode);
      }
      $host = $result instanceof BlueprintResult ? $result->getHost() : NULL;
      if ($host === NULL || !$this->subtreeRenderer->renders($host)) {
        return $this->responseBuilder->error(
          'INVALID_REQUEST',
          "A peek shows an element of a page host's canvas; $entity_type:{$entity->id()} renders none.",
          400,
        );
      }
      $canvas = $this->subtreeRenderer->canvas($host, $view_mode, $langcode);
      $element = $this->subtreeRenderer->subtree($canvas['html'], $show);
      if ($element === NULL) {
        // The sentence is the editor's: the frame shows it in the element's
        // place. The key is the client's own mint, so it rides in context.
        return $this->responseBuilder->error(
          'ELEMENT_NOT_FOUND',
          'Nothing to preview: the placed element rendered no markup.',
          404,
          ['show' => $show],
        );
      }
      return [
        'styles' => ['#markup' => Markup::create($this->entityStyleTags($canvas['html']))],
        'element' => ['#markup' => Markup::create($element)],
        '#attached' => $canvas['attachments'],
      ];
    }
    catch (WorkspaceRequiredException $e) {
      return $this->responseBuilder->error('WORKSPACE_REQUIRED', $e->getMessage(), 409);
    }
    catch (\Throwable $e) {
      // As mutate(): an \Error from a placed block's build is answered in
      // the contract's shape, never as a PHP fatal.
      $this->logger->error('Blueprint peek failed for @type:@id: @message', [
        '@type' => $entity_type,
        '@id' => $entity->id(),
        '@message' => $e->getMessage(),
      ]);
      return $this->responseBuilder->error(
        'INTERNAL_ERROR',
        'An unexpected error occurred while applying the operations.',
        500,
      );
    }
  }

  /**
   * Whether a string is a key the peek can show: a block's or a container's.
   */
  protected function isPeekKey(string $key): bool {
    $parts = explode(':', $key);
    return count($parts) === 2
      && Uuid::isValid($parts[1])
      && in_array($key, [SelectableKey::block($parts[1]), SelectableKey::container($parts[1])], TRUE);
  }

  /**
   * The host's own style tags, cut out of its rendered canvas.
   *
   * @param string $html
   *   The whole-canvas markup.
   *
   * @return string
   *   The outer HTML of every style element carrying
   *   ENTITY_STYLE_ATTRIBUTE, in document order; empty when the host
   *   carries no styles or no inline lane prints them.
   */
  protected function entityStyleTags(string $html): string {
    $dom = Html::load($html);
    $tags = '';
    foreach ((new \DOMXPath($dom))->query('//style[@' . self::ENTITY_STYLE_ATTRIBUTE . ']') as $node) {
      $tags .= (string) $dom->saveHTML($node);
    }
    return $tags;
  }

  /**
   * Returns the blueprint for an entity, reflecting staged edits.
   *
   * Query parameters:
   * - mode: 'full' (default), 'summary', 'element', or 'css'.
   * - uuid: the element UUID, required when mode is 'element' or 'css'.
   * - selector: css mode only, optional; a rule's relative selector under
   *   the element (`> pb-text`, `.quote`), narrowing the print to that
   *   rule's entry and its continuations by descent.
   * - langcode: optional translation language.
   *
   * The css mode answers text/css (printCss()); the others JSON.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, upcast by the route for access checking.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The blueprint payload, or the element's CSS.
   */
  public function read(Request $request, string $entity_type, EntityInterface $entity): Response {
    $mode = $request->query->get('mode', 'full');
    $langcode = $request->query->get('langcode') ?: NULL;

    $backend = $this->backendResolver->resolveFor($entity);
    if ($backend === NULL) {
      return $this->responseBuilder->error(
        'UNSUPPORTED_ENTITY_TYPE',
        "No blueprint backend supports $entity_type:{$entity->id()}.",
        400,
      );
    }

    $working = $backend->load($entity_type, $entity->id());
    if ($working === NULL) {
      return $this->responseBuilder->error(
        'ENTITY_NOT_FOUND',
        "Entity $entity_type:{$entity->id()} could not be loaded.",
        404,
      );
    }

    switch ($mode) {
      case 'full':
        $blueprint = $backend->serialize($working, $langcode);
        break;

      case 'summary':
        $blueprint = $backend->serializeSummary($working, $langcode);
        break;

      case 'element':
        $uuid = (string) $request->query->get('uuid', '');
        $host = $this->hostOf($working);
        if ($uuid === '' || $host === NULL) {
          return $this->responseBuilder->error(
            'INVALID_REQUEST',
            'Element mode requires a "uuid" query parameter on a page host.',
            400,
          );
        }
        $blueprint = $this->blueprintOperations->getElement($host, $uuid, $langcode);
        if ($blueprint === NULL) {
          return $this->responseBuilder->error(
            'ELEMENT_NOT_FOUND',
            "No element with UUID '$uuid' was found on $entity_type:{$entity->id()}.",
            404,
          );
        }
        break;

      case 'css':
        $host = $this->hostOf($working);
        if ($host === NULL) {
          return $this->responseBuilder->error(
            'INVALID_REQUEST',
            'CSS mode requires a "uuid" query parameter on a page host.',
            400,
          );
        }
        return $this->printCss($request, $entity_type, $host);

      default:
        return $this->responseBuilder->error(
          'INVALID_MODE',
          "Unknown mode '$mode'. Valid modes: full, summary, element, css.",
          400,
        );
    }

    return new JsonResponse([
      'entity_type' => $entity_type,
      'entity_id' => $entity->id(),
      'entity_uuid' => $working->uuid(),
      'mode' => $mode,
      'langcode' => $langcode,
      'blueprint' => $blueprint,
    ]);
  }

  /**
   * Prints one element's styles as CSS (read mode css).
   *
   * The server print behind the panel's "Copy CSS": the element the uuid
   * names, pruned to itself (no descendants), printed by
   * DialectPrinter::printStyles() over the staged tree, so the text a
   * designer copies is the dialect the AI reads and the parse door takes
   * back. With `selector`, the print narrows to that rule's entry and its
   * continuations by descent (RelativeSelector::continues()), the
   * clipboard's read at a rule.
   *
   * The tree is the spine's (NodeTreeBuilder::build over the staged host;
   * the document and its style map are the host's, shared by every
   * translation) and passes through the BlueprintReadEvent first, so a
   * subscriber (daedalus_ui's annotator) can put the element's words on
   * the nodes the way the mutation seam does; the print heads each
   * element with them when present.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request (uuid, selector on the query).
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $working
   *   The host in its staged state.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   text/css on success; the shared JSON error shape otherwise.
   */
  protected function printCss(Request $request, string $entity_type, HostInterface $working): Response {
    $uuid = (string) $request->query->get('uuid', '');
    if ($uuid === '') {
      return $this->responseBuilder->error(
        'INVALID_REQUEST',
        'CSS mode requires a "uuid" query parameter on a page host.',
        400,
      );
    }
    $selector = $request->query->get('selector');
    $rule = NULL;
    if (is_string($selector) && $selector !== '') {
      $rule = RelativeSelector::parse($selector);
      if ($rule === NULL) {
        return $this->responseBuilder->error(
          'INVALID_REQUEST',
          "The selector '$selector' is outside the relative selector grammar.",
          400,
        );
      }
    }

    $event = new BlueprintReadEvent($request, $working->entity(), 'css', $this->treeBuilder->build($working));
    $this->eventDispatcher->dispatch($event);
    $node = NodeTreeBuilder::find($event->getTree(), $uuid);
    if ($node === NULL) {
      return $this->responseBuilder->error(
        'ELEMENT_NOT_FOUND',
        "No element with UUID '$uuid' was found on $entity_type:{$working->id()}.",
        404,
      );
    }
    // The element alone: its own entry and its rules, never its
    // descendants' (a subtree print is the printer's, not this mode's).
    unset($node['children']);
    if ($rule !== NULL) {
      // The rule's entry and its continuations; the element's own entry
      // (its wear included) is the element's, not the rule's.
      $node['styles'] = array_filter(
        $node['styles'] ?? [],
        static function ($local) use ($rule): bool {
          $local = (string) $local;
          if (!str_starts_with($local, RelativeSelector::SUFFIX)) {
            return FALSE;
          }
          $candidate = RelativeSelector::parse(substr($local, strlen(RelativeSelector::SUFFIX)));
          return $candidate !== NULL && ($candidate->equals($rule) || RelativeSelector::continues($rule, $candidate));
        },
        ARRAY_FILTER_USE_KEY,
      );
    }

    $css = $this->dialectPrinter->printStyles([$node], $this->catalogBuilder->build())->text;
    return new Response($css, 200, ['Content-Type' => 'text/css; charset=UTF-8']);
  }

  /**
   * Parses a CSS fragment under one style key: the panel's paste door.
   *
   * Request body: {"key": "container:<uuid>[::selector]", "css": "..."}.
   * A read that writes nothing: the fragment parses through
   * CssFragmentDoor over the staged working copy, and the response carries
   * the flat entries, the rule wear and the correctives for the client to
   * turn into its own batch (the element document) or stage (the variant
   * document). Update access and the CSRF header gate it as a mutation is
   * gated, because the answer feeds a write of the editor's own text.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity, upcast by the route for access checking.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   {key, entries, wear, errors}; the shared error shape for a request
   *   outside the contract.
   */
  public function parseCss(Request $request, string $entity_type, EntityInterface $entity): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body)) {
      return $this->responseBuilder->error(
        'INVALID_REQUEST_BODY',
        'The request body must be a JSON object.',
        400,
      );
    }
    $key = $body['key'] ?? NULL;
    $css = $body['css'] ?? NULL;
    if (!is_string($key) || $key === '' || !is_string($css)) {
      return $this->responseBuilder->error(
        'INVALID_REQUEST',
        'The request body must carry "key" (the style key the text belongs to) and "css" (the fragment).',
        400,
      );
    }

    $backend = $this->backendResolver->resolveFor($entity);
    if ($backend === NULL) {
      return $this->responseBuilder->error(
        'UNSUPPORTED_ENTITY_TYPE',
        "No blueprint backend supports $entity_type:{$entity->id()}.",
        400,
      );
    }
    // The staged working copy: the tree the keys and the ownership answers
    // come from is the one the client is editing.
    $working = $backend->load($entity_type, $entity->id());
    $host = $working === NULL ? NULL : $this->hostOf($working);
    if ($host === NULL) {
      return $this->responseBuilder->error(
        'ENTITY_NOT_FOUND',
        "Entity $entity_type:{$entity->id()} could not be loaded as a page host.",
        404,
      );
    }

    return new JsonResponse(['key' => $key] + $this->fragmentDoor->parse($host, $key, $css));
  }

  /**
   * The host of a loaded working entity, or NULL when nothing hosts it.
   *
   * The element, css and parse modes read a page document, which only a
   * host answers (a content entity, or a Layout Builder display hosting
   * its template); a config entity of the config backend's has none. The
   * mutation door names it as the canvas its payloads are for.
   *
   * @param \Drupal\Core\Entity\EntityInterface $working
   *   The staged working entity.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The host, or NULL.
   */
  protected function hostOf(EntityInterface $working): ?HostInterface {
    return $this->hostResolver->hosts($working) ? $this->hostResolver->fromEntity($working) : NULL;
  }

  /**
   * Persists a successful mutation result.
   *
   * Content entities route through the storage handler (tempstore-first, with
   * the persist hook). Other backends persist directly.
   *
   * @param \Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface $backend
   *   The backend that produced the result.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResultInterface $result
   *   The successful result.
   *
   * @return string
   *   Where the entity was persisted: 'database' or 'tempstore'.
   */
  protected function persistResult(BlueprintBackendInterface $backend, BlueprintResultInterface $result): string {
    if ($result instanceof BlueprintResult) {
      return $this->storageHandler->persist(
        $result->getHost(),
        element_uuids: $result->getElementUuids(),
      );
    }
    $backend->persist($result->getEntity());
    return 'database';
  }

}
