<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Controller;

use Drupal\daedalus_ai\DeferredProcessor\DeferredProcessorManager;
use Drupal\daedalus_ai\DeferredProcessor\RetryableProcessorInterface;
use Drupal\daedalus_ai\MutatedTurn;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint_api\Controller\AjaxPageStateRequestTrait;
use Drupal\daedalus_ui\DaedalusUi;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Processes generation batches and answers the mutation-response contract.
 *
 * A batch stash names a type plus the media uuids to fill (with the targets
 * their references were written into); each media uuid is dispatched to the
 * processor for the batch type, which fills the file behind that uuid. A
 * completed batch returns the same {blueprint, markup, targets, errors,
 * attachments, drupal_commands} payload every mutation path returns, plus a
 * `failures` key for jobs that did not complete. The client applies the
 * payload through the app's applyResponse pipeline and reports the failures
 * in the chat.
 */
class DeferredProcessingController extends ControllerBase {

  use AjaxPageStateRequestTrait;

  public function __construct(
    protected DeferredProcessorManager $processorManager,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected MutatedTurn $mutatedTurn,
    protected EntityStorageHandler $entityStorageHandler,
    protected DaedalusUi $daedalusUi,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_ai.deferred_processor_manager'),
      $container->get('tempstore.private'),
      $container->get('daedalus_ai.mutated_turn'),
      $container->get('daedalus_blueprint.storage_handler'),
      $container->get('daedalus_ui.ui'),
    );
  }

  /**
   * Processes a batch of pending generation jobs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request. Body JSON: {"batch_id": "...", "ajax_page_state": {...}}.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The upcast entity.
   * @param string $view_mode
   *   The view mode to render on success.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   JSON: the mutation-response payload (when anything succeeded) plus
   *   {failures: [...]}, or {processing: true} while another request holds
   *   the batch.
   */
  public function process(Request $request, string $entity_type, EntityInterface $entity, string $view_mode): Response {
    $this->assertValidViewMode($entity, $view_mode);

    $body = Json::decode($request->getContent()) ?? [];
    $this->applyAjaxPageState($request, $body);
    $batch_id = $body['batch_id'] ?? NULL;
    if (!$batch_id || !is_string($batch_id)) {
      throw new BadRequestHttpException('Missing batch_id.');
    }

    $store = $this->tempStoreFactory->get('daedalus_ai_deferred');
    $batch_data = $store->get($batch_id);
    if (!$batch_data || !is_string($batch_data['type'] ?? NULL) || !is_array($batch_data['jobs'] ?? NULL)) {
      return new JsonResponse(['failures' => []], 200);
    }

    // Another request is already processing this batch.
    if (!empty($batch_data['processing'])) {
      if (isset($batch_data['result'])) {
        // Processing finished: render refresh from stored result and clean up.
        $store->delete($batch_id);
        return $this->buildResponseFromResult($batch_data['result'], $request, $entity, $view_mode);
      }
      return new JsonResponse(['processing' => TRUE]);
    }

    // Claim the batch.
    $batch_data['processing'] = time();
    $store->set($batch_id, $batch_data);

    [$success_targets, $failures] = $this->executeJobs($batch_data['type'], $batch_data['jobs'], $entity_type, $entity, $view_mode);

    // Store result for any subsequent request that finds this batch.
    $batch_data['result'] = [
      'success_targets' => $success_targets,
      'failures' => $failures,
    ];
    $store->set($batch_id, $batch_data);

    // Render and return the response (it may not reach the client if a
    // navigation killed the XHR).
    return $this->buildResponseFromResult($batch_data['result'], $request, $entity, $view_mode);
  }

  /**
   * Retries failed jobs by delegating to processor retry logic.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request. Body JSON: {"failures": [...], "ajax_page_state": {...}}.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The upcast entity.
   * @param string $view_mode
   *   The view mode to render on success.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   JSON: the mutation-response payload plus {failures: [...]}.
   */
  public function retry(Request $request, string $entity_type, EntityInterface $entity, string $view_mode): Response {
    $this->assertValidViewMode($entity, $view_mode);

    $body = Json::decode($request->getContent()) ?? [];
    $this->applyAjaxPageState($request, $body);
    $failures = $body['failures'] ?? [];
    if (empty($failures) || !is_array($failures)) {
      throw new BadRequestHttpException('Missing failures array.');
    }

    // Group failures by type and delegate to processors. The retry path
    // re-registers jobs (prompt rewrite) under the same media uuids and
    // reports back which uuids to re-process.
    $grouped = [];
    foreach ($failures as $failure) {
      $type = $failure['type'] ?? NULL;
      if ($type) {
        $grouped[$type][] = $failure;
      }
    }

    $success_targets = [];
    $new_failures = [];
    $retried = FALSE;
    foreach ($grouped as $type => $type_failures) {
      $processor = $this->processorManager->getProcessor($type);
      if (!$processor instanceof RetryableProcessorInterface) {
        array_push($new_failures, ...$type_failures);
        continue;
      }
      $media_uuids = $processor->buildRetryJobs($type_failures);
      if ($media_uuids === []) {
        array_push($new_failures, ...$type_failures);
        continue;
      }
      $retried = TRUE;
      // Rebuild the job entries so each retried media uuid keeps the target
      // its reference was originally written into.
      $targets_by_uuid = array_column($type_failures, 'target', 'media_uuid');
      $jobs = array_map(
        static fn(string $media_uuid) => [
          'media_uuid' => $media_uuid,
          'target' => $targets_by_uuid[$media_uuid] ?? NULL,
        ],
        $media_uuids,
      );
      [$type_successes, $type_failures] = $this->executeJobs($type, $jobs, $entity_type, $entity, $view_mode);
      array_push($success_targets, ...$type_successes);
      array_push($new_failures, ...$type_failures);
    }

    if (!$retried) {
      return new JsonResponse([
        'failures' => $failures,
      ]);
    }

    return $this->buildResponseFromResult([
      'success_targets' => $success_targets,
      'failures' => $new_failures,
    ], $request, $entity, $view_mode);
  }

  /**
   * Rejects a request whose client-supplied view mode is not valid.
   *
   * The view mode rides the request URL, so a crafted request can name an
   * arbitrary string. It flows unchecked into the entity view builder when the
   * refresh is rendered. Validate it here the same way daedalus_ui does
   * when it loads the editable page, so the deferred render path behaves
   * identically to a normal Edit Mode load.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity the view mode applies to.
   * @param string $view_mode
   *   The client-supplied view mode.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   When the view mode is not configured for the entity type.
   */
  protected function assertValidViewMode(EntityInterface $entity, string $view_mode): void {
    if (!$this->daedalusUi->isValidViewMode($entity, $view_mode)) {
      throw new BadRequestHttpException(sprintf(
        'Invalid view mode "%s" for %s.',
        $view_mode,
        $entity->getEntityTypeId(),
      ));
    }
  }

  /**
   * Executes a batch's jobs and returns raw results without rendering.
   *
   * @param string $type
   *   The batch type, naming the processor.
   * @param array $jobs
   *   Job entries: each {media_uuid: string, target: array|string|null}.
   * @param string $entity_type
   *   The entity type ID.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $view_mode
   *   The view mode.
   *
   * @return array
   *   Tuple of [success_targets, failures]. Success targets are the
   *   {uuid, field_name} or selectable-key string targets of completed jobs
   *   (the subtrees to re-render); failures carry the media uuid, reason,
   *   retryability, the processor's retry data, and the original target.
   */
  protected function executeJobs(string $type, array $jobs, string $entity_type, EntityInterface $entity, string $view_mode): array {
    $context = [
      'entity_type' => $entity_type,
      'entity_id' => $entity->id(),
      'view_mode' => $view_mode,
    ];

    $success_targets = [];
    $failures = [];

    foreach ($jobs as $job) {
      $media_uuid = $job['media_uuid'] ?? NULL;
      if (!is_string($media_uuid) || $media_uuid === '') {
        continue;
      }
      $target = $job['target'] ?? NULL;
      $result = $this->processorManager->process($type, $media_uuid, $context);
      if ($result->success) {
        // Both target shapes name a re-renderable subtree: {uuid, field_name}
        // maps through TargetKeyMapper (a style write targets the styles
        // field hosting the <style> tag, so a generated CSS background
        // repaints on completion; its shimmer-only pending_key is dropped
        // here), and a selectable-key string rides verbatim. A NULL target
        // (variant lane) has no page presence; the filled media's cache tags
        // refresh it on the next render.
        if (is_array($target) && isset($target['uuid'], $target['field_name'])) {
          $success_targets[] = ['uuid' => $target['uuid'], 'field_name' => $target['field_name']];
        }
        elseif (is_string($target) && $target !== '') {
          $success_targets[] = $target;
        }
      }
      else {
        $failures[] = [
          'type' => $type,
          'media_uuid' => $result->mediaUuid,
          'reason' => $result->reason,
          'retryable' => $result->retryable,
          'data' => $result->data,
          'target' => $target,
        ];
      }
    }

    return [$success_targets, $failures];
  }

  /**
   * Builds the mutation response from stored job results.
   *
   * @param array $result
   *   Array with 'success_targets' and 'failures' keys.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $view_mode
   *   The view mode for rendering.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON: the mutation-response payload for the completed targets (fresh
   *   working copy render, highlight riding drupal_commands) merged with
   *   {failures: [...]}.
   */
  protected function buildResponseFromResult(array $result, Request $request, EntityInterface $entity, string $view_mode): JsonResponse {
    $success_targets = $result['success_targets'] ?? [];
    $failures = $result['failures'] ?? [];

    $payload = [];
    if (!empty($success_targets) && $entity instanceof FieldableEntityInterface) {
      $fresh_entity = $this->entityStorageHandler->load(
        $entity->getEntityTypeId(),
        $entity->id(),
      );
      $fresh_entity = $fresh_entity instanceof FieldableEntityInterface ? $fresh_entity : $entity;
      $payload = $this->mutatedTurn->buildPayloadFor($request, $fresh_entity, $success_targets, $view_mode);
    }

    return new JsonResponse($payload + ['failures' => $failures]);
  }

}
