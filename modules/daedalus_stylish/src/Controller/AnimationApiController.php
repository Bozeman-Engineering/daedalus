<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Controller;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_stylish\AnimationManager;
use Drupal\daedalus_stylish\Exception\AnimationValidationException;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The animation lifecycle endpoints.
 *
 * Rename and delete (ADAPTERS over the animation kind of the material gesture
 * family: the same DaedalusStylishMaterialsProvider::update() / remove() the
 * update_material / remove_material ops dispatch to), revisions/restore,
 * revert, and the per-animation management read.
 *
 * The swatch API's twin at structure scope. Minting and the keyframes
 * editor's commit are not routes: the Materials section's "+ New
 * animation" and the card's editor submit save_material / update_material
 * (kind animation) through the page batch, the same ops the AI submits.
 * The section manages the rest of the vocabulary from wherever it is
 * open, with no page batch to ride, so those gestures speak to the
 * provider through these routes and receive the same materials delta the
 * op lane answers with; a write's response is that delta beside the
 * route's own facts (deleted, references, batch_url), never an echo of
 * the animation (the client reads the written record from the delta).
 * Access is per-gesture route permissions matching the animation grades,
 * plus the standard CSRF header check on the writes. Errors ride the
 * shared envelope (GestureApiControllerBase). There is no list: the
 * roster the page boots with (and every response's materials delta) is
 * the catalog every client reads; the facts a card needs beyond the
 * record (usages, shipped state) are management facts read per gesture.
 */
final class AnimationApiController extends GestureApiControllerBase {

  /**
   * {@inheritdoc}
   */
  protected const PAYLOAD_HINT = 'Send {label} as JSON.';

  public function __construct(
    private readonly AnimationManager $animationManager,
    private readonly DaedalusStylishMaterialsProvider $provider,
    MaterialsChangeSet $materialsChangeSet,
  ) {
    parent::__construct($materialsChangeSet);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_stylish.animation_manager'),
      $container->get('daedalus_stylish.materials_provider'),
      $container->get('daedalus_blueprint.materials_change_set'),
    );
  }

  /**
   * GET /daedalus_stylish/animations/{reference}/usage: the live wearer count.
   *
   * The card's loud number: the count drifts mid-session, so the prompts
   * that speak consequences (restore, delete) fetch it at gesture time,
   * the swatch usage endpoint's twin.
   */
  public function usage(string $reference): JsonResponse {
    $animation = $this->animationManager->loadAnimation($reference);
    if ($animation === NULL) {
      return $this->notFound($reference);
    }
    // The management facts beside the count: the shipped state (the
    // revert affordance's gate) and the shipping module. Neither rides the
    // roster record (a record says where a material came from, never what
    // a module's disk copy says today), so the card reads them here, per
    // open, exactly like the count.
    $payload = $this->animationManager->animationPayload($animation);
    return new JsonResponse([
      'machine_name' => $payload['machine_name'],
      'label' => $payload['label'],
      'usages' => $this->animationManager->usages($animation),
      'provider' => $payload['provider'],
      'shipped_state' => $payload['shipped_state'],
    ]);
  }

  /**
   * POST /daedalus_stylish/animations/{reference}/rename: the label gesture.
   *
   * Body: {label}. Identity (machine name, uuid) never changes, so every
   * motion-{name} reference survives; the relabeled record rides the
   * materials delta.
   */
  public function rename(string $reference, Request $request): JsonResponse {
    $animation = $this->animationManager->loadAnimation($reference);
    if ($animation === NULL) {
      return $this->notFound($reference);
    }
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->badRequest();
    }
    try {
      $this->provider->update(MaterialKind::Animation, $reference, [
        'label' => is_string($payload['label'] ?? NULL) ? $payload['label'] : '',
      ], NULL, []);
    }
    catch (MaterialGestureException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated([]);
  }

  /**
   * GET /daedalus_stylish/animations/{reference}/revisions: the restore menu.
   */
  public function revisions(string $reference): JsonResponse {
    $animation = $this->animationManager->loadAnimation($reference);
    if ($animation === NULL) {
      return $this->notFound($reference);
    }
    return new JsonResponse([
      'machine_name' => $animation->getMachineName(),
      'label' => (string) $animation->label(),
      'revisions' => $this->animationManager->revisionsList($animation),
    ]);
  }

  /**
   * POST /daedalus_stylish/animations/{reference}/revisions/{revision_id}/restore.
   *
   * A NEW default revision carrying the old revision's content, never a
   * rollback, so a restore is itself one restore away from undo. No body;
   * every wearer re-animates at once (the steps ARE the delivered
   * keyframes). The restored record rides the delta.
   */
  public function restore(string $reference, string|int $revision_id): JsonResponse {
    $animation = $this->animationManager->loadAnimation($reference);
    if ($animation === NULL) {
      return $this->notFound($reference);
    }
    try {
      $this->animationManager->restoreRevision($animation, (int) $revision_id);
    }
    catch (AnimationValidationException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated([]);
  }

  /**
   * POST /daedalus_stylish/animations/{reference}/revert: revert to shipped defaults.
   *
   * An ordinary revisioned save of the steps the animation's provider
   * ships on disk right now (workspace-staged inside a workspace). No
   * body; the disk is the payload. The reverted record rides the delta.
   */
  public function revert(string $reference): JsonResponse {
    $animation = $this->animationManager->loadAnimation($reference);
    if ($animation === NULL) {
      return $this->notFound($reference);
    }
    try {
      $this->animationManager->revert($animation);
    }
    catch (AnimationValidationException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated([]);
  }

  /**
   * DELETE /daedalus_stylish/animations/{reference}: delete, always safely.
   *
   * Unworn animations delete in this request. WORN animations stage the
   * rewrite-then-delete Batch API job (AnimationManager::deleteAnimation()
   * / AnimationDeleteBatch) and respond 202 with the batch URL for the
   * client to drive: every motion-{name} reference becomes `none`, then
   * the entity goes. There is no "delete anyway". The two permission
   * grades: the route admits both, and a worn animation without the
   * in-use grade comes back 403 with the STYLE_ANIMATION_IN_USE
   * corrective. Shipped animations refuse either way (the distro lock).
   */
  public function delete(string $reference): JsonResponse {
    $animation = $this->animationManager->loadAnimation($reference);
    if ($animation === NULL) {
      return $this->notFound($reference);
    }
    try {
      $references = $this->provider->remove(MaterialKind::Animation, $reference, [MaterialGestureOperationHandler::BATCH_OPTION => TRUE])->staged;
    }
    catch (MaterialGestureException $exception) {
      return $this->unprocessable($exception, in_array('STYLE_ANIMATION_IN_USE', array_column($exception->getErrorsAsArrays(), 'code'), TRUE) ? 403 : 422);
    }
    if ($references === 0) {
      return $this->mutated(['deleted' => TRUE, 'references' => 0]);
    }
    $batch_response = batch_process();
    // The entity goes in the batch's last request, so this response cannot
    // carry the removal as a flushed delta (the roster still holds the
    // animation); it states the outcome the staged batch is committed to,
    // and the client applies it once it has driven the batch to the end.
    return $this->mutated([
      'deleted' => FALSE,
      'references' => $references,
      'batch_url' => $batch_response->getTargetUrl(),
      'materials' => $this->materialsChangeSet->stagedRemoval(MaterialKind::Animation, $animation->getMachineName()),
    ], 202);
  }

  /**
   * A 404 in the standard error shape.
   */
  private function notFound(string $reference): JsonResponse {
    return $this->singleError('animation', 'STYLE_ANIMATION_NOT_FOUND', sprintf('"%s" is not an animation.', $reference), 'Reference an animation by its machine name or uuid.', 404);
  }

}
