<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_test\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Stashes a pending-generation batch for the CURRENT user.
 *
 * In production, PendingTargetCollector::flush() writes the batch to the
 * 'daedalus_ai_deferred' private tempstore during the chat request, so the batch
 * is owned by the user the browser is logged in as. A behavior test cannot
 * reach that store from the test runner (the runner process is not the
 * browser's session/user), so this route lets the BROWSER stash the batch
 * itself, landing it under the same owner the DeferredProcessingController
 * will read it back for. The payload mirrors flush()'s storage shape
 * exactly: {type: "...", jobs: [{media_uuid: "...", target: ...}, ...]}.
 */
class StashDeferredBatch extends ControllerBase {

  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('tempstore.private'),
    );
  }

  /**
   * Writes the posted batch into the daedalus_ai_deferred private tempstore.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request. Body JSON: {"batch_id": "...", "type": "...",
   *   "jobs": [{"media_uuid": "...", "target": ...}, ...]}.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON: {stashed: true}.
   */
  public function stash(Request $request): JsonResponse {
    $body = Json::decode($request->getContent()) ?? [];
    $batch_id = $body['batch_id'] ?? NULL;
    $type = $body['type'] ?? NULL;
    $jobs = $body['jobs'] ?? NULL;
    if (!is_string($batch_id) || $batch_id === '' || !is_string($type) || $type === '' || !is_array($jobs) || $jobs === []) {
      throw new BadRequestHttpException('Missing batch_id, type, or jobs.');
    }
    foreach ($jobs as $job) {
      if (!is_array($job) || !is_string($job['media_uuid'] ?? NULL)) {
        throw new BadRequestHttpException('Malformed job.');
      }
    }

    $this->tempStoreFactory->get('daedalus_ai_deferred')->set($batch_id, [
      'type' => $type,
      'jobs' => $jobs,
    ]);

    return new JsonResponse(['stashed' => TRUE]);
  }

}
