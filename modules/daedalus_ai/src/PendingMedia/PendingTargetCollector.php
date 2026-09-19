<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\PendingMedia;

use Drupal\daedalus_ai\Ajax\TriggerDeferredCommand;
use Drupal\daedalus_ai\DeferredProcessor\DeferredProcessorManager;
use Drupal\daedalus_ai\DeferredProcessor\ImageGenerationProcessor;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\HttpFoundation\Request;

/**
 * Request-scoped collector of pending image generations and their targets.
 *
 * Identity-first, content-deferred: every reference to a minted media entity
 * is final at compose time, so nothing about the WRITE is deferred. What the
 * turn still owes the client is the processing trigger and the pending
 * visual: each surface that mints (the media-reference field handler, the
 * style-value handler) registers its media uuid here together with the
 * target it wrote the reference into, and the chat response listener flushes
 * the collection as ONE TriggerDeferredCommand: one batch per turn, however
 * many tool calls minted.
 *
 * A target is either a {uuid, field_name} array (the completion's swap
 * subtree, mapped to a selectable key against the persisted entity),
 * optionally carrying a pending_key (a style write: the styled element's
 * selectable key, which the shimmer targets while the swap targets the
 * styles field hosting the <style> tag), or a raw selectable-key string
 * (a caller that already names the swap subtree directly), or NULL when
 * the surface has no target context. The batch stash carries the media
 * uuids plus their targets; the DeferredProcessingController loads each
 * media uuid's generation job from the GenerationJobStore and refreshes
 * the targets when the pixels land.
 *
 * The AiToolActionSubscriber binds the persisted entity: the batch needs an
 * entity route for the trigger POST and the completion re-render. A
 * registration that never gets an entity bound (a lane with no processing
 * trigger, e.g. the HTTP API) is dropped at flush; its minted media keeps
 * the placeholder file until a trigger lane exists for it.
 */
class PendingTargetCollector {

  /**
   * Pending registrations: {media_uuid: string, target: array|string|null}.
   *
   * @var array<int, array{media_uuid: string, target: array{uuid: string, field_name: string}|string|null}>
   */
  protected array $pending = [];

  /**
   * The entity whose persistence carried the minted references.
   */
  protected ?FieldableEntityInterface $entity = NULL;

  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    // A service closure, not the mapper itself: the mapper's construction
    // graph (element locator -> field handler manager, whose collector pass
    // eagerly builds every tagged field handler) leads back to services
    // that depend on this collector, so resolving it during THIS service's
    // construction closes a container cycle whenever the collector's side
    // is built first (e.g. a consumer of daedalus_stylish's style-map field handler,
    // whose style-value resolver reaches this collector through the
    // generate handler, constructed before the field handler manager). The
    // closure defers resolution to flush time, when the container is
    // settled. Setter injection is NOT enough: `calls` also run inside
    // construction.
    protected \Closure $targetKeyMapper,
    protected DeferredProcessorManager $processorManager,
  ) {}

  /**
   * Registers a minted media uuid and the target its reference was set into.
   *
   * @param string $media_uuid
   *   The uuid of the minted media whose generation job is pending.
   * @param array{uuid: string, field_name: string, pending_key?: string}|string|null $target
   *   The write target: a {uuid, field_name} pair naming the completion's
   *   swap subtree (with an optional pending_key selectable key for the
   *   shimmer, used by style writes), a raw selectable key string for a
   *   caller that names the swap subtree directly, or NULL when the surface
   *   has no target context (the media still processes; no pending visual).
   */
  public function register(string $media_uuid, array|string|null $target = NULL): void {
    $this->pending[] = ['media_uuid' => $media_uuid, 'target' => $target];
  }

  /**
   * Whether any generation was registered during this request.
   */
  public function hasPending(): bool {
    return $this->pending !== [];
  }

  /**
   * Returns the pending registrations.
   *
   * @return array<int, array{media_uuid: string, target: array{uuid: string, field_name: string}|string|null}>
   *   The registrations in registration order.
   */
  public function getPending(): array {
    return $this->pending;
  }

  /**
   * Binds the persisted entity the batch will be processed against.
   *
   * Called by the tool-action subscriber on every entity persistence; the
   * last persisted entity of the turn hosts the batch (generation itself is
   * entity-independent, the entity only routes the trigger POST and scopes
   * the completion re-render).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The persisted working copy.
   */
  public function bindEntity(FieldableEntityInterface $entity): void {
    $this->entity = $entity;
  }

  /**
   * Flushes the turn's registrations into one trigger command.
   *
   * Stashes the batch (type + media uuids + targets) into the
   * daedalus_ai_deferred private tempstore under a fresh batch id and builds the
   * TriggerDeferredCommand the client processes it with. Clears the
   * collection either way.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The chat request; its JSON body's `contexts.view_mode` names the view
   *   mode the client displays.
   *
   * @return \Drupal\daedalus_ai\Ajax\TriggerDeferredCommand|null
   *   The command to queue, or NULL when nothing is pending or no entity was
   *   bound.
   */
  public function flush(Request $request): ?TriggerDeferredCommand {
    $pending = $this->pending;
    $entity = $this->entity;
    $this->pending = [];
    $this->entity = NULL;

    if ($pending === [] || $entity === NULL) {
      return NULL;
    }

    $contexts = Json::decode($request->getContent())['contexts'] ?? [];
    $view_mode = is_string($contexts['view_mode'] ?? NULL) ? $contexts['view_mode'] : 'full';

    $batch_id = $entity->getEntityTypeId() . ':' . $entity->id() . ':' . uniqid('', TRUE);
    $this->tempStoreFactory->get('daedalus_ai_deferred')->set($batch_id, [
      'type' => ImageGenerationProcessor::TYPE,
      'jobs' => $pending,
    ]);

    // Pending-effect targeting rides spine keys. A target's pending_key
    // (a style write: the styled element's own key) wins outright — the
    // shimmer belongs on the element the user sees changing, while the
    // {uuid, field_name} half of the same target names the completion's
    // swap subtree (the styles field hosting the <style> tag). A field
    // target without one maps to the same selectable key the mutation
    // response's markup swap will use; a bare string target is already a
    // selectable key.
    $field_targets = [];
    $target_keys = [];
    foreach (array_column($pending, 'target') as $target) {
      if (is_array($target) && is_string($target['pending_key'] ?? NULL)) {
        $target_keys[] = $target['pending_key'];
      }
      elseif (is_array($target)) {
        $field_targets[] = $target;
      }
      elseif (is_string($target)) {
        $target_keys[] = $target;
      }
    }
    $target_keys = array_merge(($this->targetKeyMapper)()->map($entity, $field_targets), $target_keys);

    $messages = $this->processorManager->getProcessor(ImageGenerationProcessor::TYPE)?->getMessages() ?? [];

    return new TriggerDeferredCommand(
      batchId: $batch_id,
      entityType: $entity->getEntityTypeId(),
      entityId: (string) $entity->id(),
      viewMode: $view_mode,
      targetKeys: array_values(array_unique($target_keys)),
      messages: $messages,
    );
  }

}
