<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Controller;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_stylish\Exception\SwatchValidationException;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\SwatchManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The swatch lifecycle endpoints.
 *
 * Rename and delete (ADAPTERS over the swatch kind of the material gesture
 * family: the same DaedalusStylishMaterialsProvider::update() / remove() the
 * update_material / remove_material ops dispatch to), revisions/restore,
 * revert, and the per-swatch management read.
 *
 * The variant API's twin at value scope. Minting and the value editor's
 * commit are not routes: the palette's "+ New swatch", the builder's and
 * the color field's "Save as swatch" and the card's value editor submit
 * save_material / update_material (kind swatch) through the page batch,
 * the same ops the AI submits. The palette manages the rest of the
 * vocabulary from wherever it is open, with no page batch to ride, so
 * those gestures speak to the provider through these routes and receive
 * the same materials delta the op lane answers with; a write's response
 * is that delta beside the route's own facts (deleted, references,
 * batch_url), never an echo of the swatch (the client reads the written
 * record from the delta).
 * Access is per-gesture route permissions matching the swatch grades, plus
 * the standard CSRF header check on the writes. Errors ride the shared
 * envelope (GestureApiControllerBase), so the client reports them like any
 * other Daedalus Stylish failure. There is no list: the roster the page boots with
 * (and every response's materials delta) is the palette every client reads
 * (daedalus_blueprint's MaterialsDelivery); the facts a card needs beyond the
 * record (usages, shipped state) are management facts read per gesture.
 */
final class SwatchApiController extends GestureApiControllerBase {

  /**
   * {@inheritdoc}
   */
  protected const PAYLOAD_HINT = 'Send {label} as JSON.';

  public function __construct(
    private readonly SwatchManager $swatchManager,
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
      $container->get('daedalus_stylish.swatch_manager'),
      $container->get('daedalus_stylish.materials_provider'),
      $container->get('daedalus_blueprint.materials_change_set'),
    );
  }

  /**
   * GET /daedalus_stylish/swatches/{reference}/usage — the live reference count.
   *
   * The palette card's loud number: the count drifts mid-session, so the
   * prompts that speak consequences (restore, delete) fetch it at gesture
   * time — the variant usage endpoint's twin.
   */
  public function usage(string $reference): JsonResponse {
    $swatch = $this->swatchManager->loadSwatch($reference);
    if ($swatch === NULL) {
      return $this->notFound($reference);
    }
    // The management facts beside the count: the shipped state (the
    // revert affordance's gate) and the shipping module. Neither rides the
    // roster record (a record says where a material came from, never what
    // a module's disk copy says today), so the card reads them here, per
    // open, exactly like the count.
    $payload = $this->swatchManager->swatchPayload($swatch);
    return new JsonResponse([
      'machine_name' => $payload['machine_name'],
      'label' => $payload['label'],
      'usages' => $this->swatchManager->usages($swatch),
      'provider' => $payload['provider'],
      'shipped_state' => $payload['shipped_state'],
    ]);
  }

  /**
   * POST /daedalus_stylish/swatches/{reference}/rename — the label gesture.
   *
   * Body: {label}. Identity (machine name, uuid) never changes, so every
   * var(--swatch-*) reference survives; the relabeled record rides the
   * materials delta.
   */
  public function rename(string $reference, Request $request): JsonResponse {
    $swatch = $this->swatchManager->loadSwatch($reference);
    if ($swatch === NULL) {
      return $this->notFound($reference);
    }
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->badRequest();
    }
    try {
      $this->provider->update(MaterialKind::Swatch, $reference, [
        'label' => is_string($payload['label'] ?? NULL) ? $payload['label'] : '',
      ], NULL, []);
    }
    catch (MaterialGestureException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated([]);
  }

  /**
   * GET /daedalus_stylish/swatches/{reference}/revisions — the restore menu.
   */
  public function revisions(string $reference): JsonResponse {
    $swatch = $this->swatchManager->loadSwatch($reference);
    if ($swatch === NULL) {
      return $this->notFound($reference);
    }
    return new JsonResponse([
      'machine_name' => $swatch->getMachineName(),
      'label' => (string) $swatch->label(),
      'revisions' => $this->swatchManager->revisionsList($swatch),
    ]);
  }

  /**
   * POST /daedalus_stylish/swatches/{reference}/revisions/{revision_id}/restore.
   *
   * A NEW default revision carrying the old revision's content — never a
   * rollback, so a restore is itself one restore away from undo. No body;
   * every element referencing the swatch restyles instantly (the value IS
   * a live custom property). The restored record rides the delta.
   */
  public function restore(string $reference, string|int $revision_id): JsonResponse {
    $swatch = $this->swatchManager->loadSwatch($reference);
    if ($swatch === NULL) {
      return $this->notFound($reference);
    }
    try {
      $this->swatchManager->restoreRevision($swatch, (int) $revision_id);
    }
    catch (SwatchValidationException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated([]);
  }

  /**
   * POST /daedalus_stylish/swatches/{reference}/revert: revert to shipped defaults.
   *
   * An ordinary revisioned save of the payload the swatch's provider ships
   * on disk right now (workspace-staged inside a workspace). No body; the
   * disk is the payload. The reverted record rides the delta.
   */
  public function revert(string $reference): JsonResponse {
    $swatch = $this->swatchManager->loadSwatch($reference);
    if ($swatch === NULL) {
      return $this->notFound($reference);
    }
    try {
      $this->swatchManager->revert($swatch);
    }
    catch (SwatchValidationException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated([]);
  }

  /**
   * DELETE /daedalus_stylish/swatches/{reference} — delete, always safely.
   *
   * Unreferenced swatches delete in this request. REFERENCED swatches
   * stage the rewrite-then-delete Batch API job
   * (SwatchManager::deleteSwatch() / SwatchDeleteBatch) and respond 202
   * with the batch URL for the client to drive: every var(--swatch-*)
   * becomes the swatch's literal value, then the entity goes. There is no
   * "delete anyway" — leaving N values dangling is not a button. The two
   * permission grades: the route admits both, and a referenced swatch
   * without the in-use grade comes back 403 with the STYLE_SWATCH_IN_USE
   * corrective. Shipped swatches refuse either way (the distro lock).
   */
  public function delete(string $reference): JsonResponse {
    $swatch = $this->swatchManager->loadSwatch($reference);
    if ($swatch === NULL) {
      return $this->notFound($reference);
    }
    try {
      $references = $this->provider->remove(MaterialKind::Swatch, $reference, [MaterialGestureOperationHandler::BATCH_OPTION => TRUE])->staged;
    }
    catch (MaterialGestureException $exception) {
      return $this->unprocessable($exception, in_array('STYLE_SWATCH_IN_USE', array_column($exception->getErrorsAsArrays(), 'code'), TRUE) ? 403 : 422);
    }
    if ($references === 0) {
      return $this->mutated(['deleted' => TRUE, 'references' => 0]);
    }
    $batch_response = batch_process();
    // The entity goes in the batch's last request, so this response cannot
    // carry the removal as a flushed delta (the roster still holds the
    // swatch); it states the outcome the staged batch is committed to, and
    // the client applies it once it has driven the batch to the end.
    return $this->mutated([
      'deleted' => FALSE,
      'references' => $references,
      'batch_url' => $batch_response->getTargetUrl(),
      'materials' => $this->materialsChangeSet->stagedRemoval(MaterialKind::Swatch, $swatch->getMachineName()),
    ], 202);
  }

  /**
   * A 404 in the standard error shape.
   */
  private function notFound(string $reference): JsonResponse {
    return $this->singleError('swatch', 'STYLE_SWATCH_NOT_FOUND', sprintf('"%s" is not a swatch.', $reference), 'Reference a swatch by its machine name or uuid.', 404);
  }

}
