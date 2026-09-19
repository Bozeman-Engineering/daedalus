<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Controller;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_stylish\Exception\VariantValidationException;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\VariantManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The variant lifecycle endpoints.
 *
 * Usage, details and delete (ADAPTERS over the variant kind of the material
 * gesture family: the same DaedalusStylishMaterialsProvider::update() / remove() the
 * update_material / remove_material ops dispatch to), copy, revert,
 * revisions/restore.
 *
 * Save-as and push are not routes: the inspector's push bar submits
 * save_material / update_material (kind variant) through the page batch,
 * carrying its own capture as `key` + `styles` (the walk that drives the
 * live preview), the same ops the AI submits. The palette manages the
 * rest of the vocabulary from wherever it is open, with no page batch to
 * ride, so those gestures ride these routes; a route answers the same
 * materials delta the op lane does beside its own facts (usages, deleted,
 * wearers, batch_url), never an echo of the variant (the client reads the
 * written record from the delta). There is no list endpoint: the
 * published variants are materials, delivered with the page as roster
 * records and kept current by the materials delta every write here
 * answers with. Access is per-gesture route permissions matching the
 * governance model, plus the standard CSRF header check on the writes.
 * Errors come back in the blueprint error shape ({path, code, message,
 * hint}, the shared envelope, GestureApiControllerBase) so the client
 * reports them like any other Daedalus Stylish failure.
 */
final class VariantApiController extends GestureApiControllerBase {

  /**
   * {@inheritdoc}
   */
  protected const PAYLOAD_HINT = 'Send {label?, description?} as JSON.';

  public function __construct(
    private readonly VariantManager $variantManager,
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
      $container->get('daedalus_stylish.variant_manager'),
      $container->get('daedalus_stylish.materials_provider'),
      $container->get('daedalus_blueprint.materials_change_set'),
    );
  }

  /**
   * GET /daedalus_stylish/variants/{reference}/usage — the management read.
   *
   * The push gesture's loud count beside the management facts a card or a
   * worn-variant notice needs past the roster record: the shipped state
   * (the revert affordance's gate) and the shipping module. Neither rides
   * the record (a record says where a material came from, never what a
   * module's disk copy says today), so the client reads them here, per
   * open, exactly like the count.
   */
  public function usage(string $reference): JsonResponse {
    $variant = $this->variantManager->loadVariant($reference);
    if ($variant === NULL) {
      return $this->notFound($reference);
    }
    $payload = $this->variantManager->variantPayload($variant);
    return new JsonResponse([
      'machine_name' => $payload['machine_name'],
      'label' => $payload['label'],
      'usages' => $this->variantManager->usages($variant),
      'provider' => $payload['provider'],
      'shipped_state' => $payload['shipped_state'],
    ]);
  }

  /**
   * POST /daedalus_stylish/variants/{reference}/revert: revert to shipped defaults.
   *
   * An ordinary revisioned save of the styles the
   * variant's provider ships on disk right now (workspace-staged inside a
   * workspace). No body; the disk is the payload. Responds with the use
   * count the revert updated; the reverted record rides the delta.
   */
  public function revert(string $reference): JsonResponse {
    $variant = $this->variantManager->loadVariant($reference);
    if ($variant === NULL) {
      return $this->notFound($reference);
    }
    try {
      $usages = $this->variantManager->revert($variant);
    }
    catch (VariantValidationException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated(['usages' => $usages]);
  }

  /**
   * POST /daedalus_stylish/variants/{reference}/copy: copy as my variant.
   *
   * The distro lock's fork gesture (shipped variants are read-only; the
   * copy is the site's own and editable), offered uniformly on any
   * variant. Body: {label?} — omitted derives "<source label> (copy)".
   * The copy is the one record the response's delta upserts.
   */
  public function copy(string $reference, Request $request): JsonResponse {
    $variant = $this->variantManager->loadVariant($reference);
    if ($variant === NULL) {
      return $this->notFound($reference);
    }
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->badRequest();
    }
    $this->variantManager->copyAsMyVariant(
      $variant,
      is_string($payload['label'] ?? NULL) ? $payload['label'] : NULL,
    );
    return $this->mutated([], 201);
  }

  /**
   * DELETE /daedalus_stylish/variants/{reference} — delete, always safely.
   *
   * Unworn variants delete in this request. WORN variants stage the
   * detach-then-delete Batch API job (VariantManager::deleteVariant() /
   * VariantDeleteBatch) and respond 202 with the batch URL for the client
   * to drive: every wearer keeps the variant's delivered look as its own
   * styles, then the entity goes. There is no "delete anyway" — an action
   * whose only effect is visibly breaking N pages is not a button. The
   * two permission grades: the route admits both, and a worn
   * variant without the in-use grade comes back 403 with the
   * STYLE_VARIANT_IN_USE corrective.
   */
  public function delete(string $reference): JsonResponse {
    $variant = $this->variantManager->loadVariant($reference);
    if ($variant === NULL) {
      return $this->notFound($reference);
    }
    try {
      $wearers = $this->provider->remove(MaterialKind::Variant, $reference, [MaterialGestureOperationHandler::BATCH_OPTION => TRUE])->staged;
    }
    catch (MaterialGestureException $exception) {
      return $this->unprocessable($exception, in_array('STYLE_VARIANT_IN_USE', array_column($exception->getErrorsAsArrays(), 'code'), TRUE) ? 403 : 422);
    }
    if ($wearers === 0) {
      return $this->mutated(['deleted' => TRUE, 'wearers' => 0]);
    }
    $batch_response = batch_process();
    // The entity goes in the batch's last request, so this response cannot
    // carry the removal as a flushed delta (the roster still holds the
    // variant); it states the outcome the staged batch is committed to,
    // and the client applies it once it has driven the batch to the end.
    return $this->mutated([
      'deleted' => FALSE,
      'wearers' => $wearers,
      'batch_url' => $batch_response->getTargetUrl(),
      'materials' => $this->materialsChangeSet->stagedRemoval(MaterialKind::Variant, $variant->getMachineName()),
    ], 202);
  }

  /**
   * POST /daedalus_stylish/variants/{reference}/details: the face gesture.
   *
   * Body: {label?, description?, offered?}, at least one; the provider's own
   * update_material grammar validates them (a body naming neither is the
   * spine's MISSING_PARAMETER, an empty label its own corrective, an empty
   * description clears it). Identity (machine name, uuid) never changes,
   * so every style map reference survives; the updated record rides the
   * delta.
   */
  public function details(string $reference, Request $request): JsonResponse {
    $variant = $this->variantManager->loadVariant($reference);
    if ($variant === NULL) {
      return $this->notFound($reference);
    }
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->badRequest();
    }
    try {
      $this->provider->update(MaterialKind::Variant, $reference, array_intersect_key($payload, [
        'label' => TRUE,
        'description' => TRUE,
        'offered' => TRUE,
      ]), NULL, []);
    }
    catch (MaterialGestureException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated([]);
  }

  /**
   * GET /daedalus_stylish/variants/{reference}/revisions — the restore menu.
   */
  public function revisions(string $reference): JsonResponse {
    $variant = $this->variantManager->loadVariant($reference);
    if ($variant === NULL) {
      return $this->notFound($reference);
    }
    return new JsonResponse([
      'machine_name' => $variant->getMachineName(),
      'label' => (string) $variant->label(),
      'revisions' => $this->variantManager->revisionsList($variant),
    ]);
  }

  /**
   * POST /daedalus_stylish/variants/{reference}/revisions/{revision_id}/restore.
   *
   * A NEW default revision carrying the old revision's content — never a
   * rollback, so a restore is itself one restore away from undo. No body;
   * responds with the use count the restore updated; the restored record
   * rides the delta.
   */
  public function restore(string $reference, string|int $revision_id): JsonResponse {
    $variant = $this->variantManager->loadVariant($reference);
    if ($variant === NULL) {
      return $this->notFound($reference);
    }
    try {
      $usages = $this->variantManager->restoreRevision($variant, (int) $revision_id);
    }
    catch (VariantValidationException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated(['usages' => $usages]);
  }

  /**
   * A 404 in the standard error shape.
   */
  private function notFound(string $reference): JsonResponse {
    return $this->singleError('variant', 'STYLE_VARIANT_NOT_FOUND', sprintf('"%s" is not a saved style variant.', $reference), 'Reference a variant by its machine name or uuid.', 404);
  }

}
