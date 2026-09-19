<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint_stencil\Materials\StencilMaterialsProvider;
use Drupal\daedalus_blueprint_stencil\StencilManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The stencil gesture endpoints outside the op spine.
 *
 * Details, delete, and the variant-ownership lifecycle (release, transfer).
 *
 * Details and delete are ADAPTERS over the stencil kind of the material
 * gesture family: the same StencilMaterialsProvider::update() / remove()
 * the update_material / remove_material ops dispatch to, so the palette's
 * Edit details and Delete gestures and the AI's ops share one grammar, one
 * validation path and one permission gate. They keep their own routes
 * because the Add panel's stencil menu has no page batch to ride: the
 * palette manages the vocabulary from wherever it is open, and a route
 * answers the same `materials` {upsert, remove} delta the mutation response
 * carries (MaterialsChangeSet, fed by StencilCacheHooks), so the client
 * store learns the write from either lane identically.
 *
 * Release and transfer stay manager gestures: they rewrite the
 * non-revisionable ownership identity on daedalus_stylish variants and are not one
 * of the family's three verbs (adopt rides the capture ops; releasing or
 * transferring curation is human judgment on these routes).
 *
 * Access is the route's "save stencils" permission; the provider asks the
 * stencil entity's own access handler as well, so the handler stays the
 * single authority on stencil mutation, and file-shipped stencils refuse
 * loudly (read-only everywhere by design). Errors come back in the
 * blueprint error shape ({path, code, message, hint}) so the client
 * reports them like any other blueprint failure.
 */
final class StencilApiController extends ControllerBase {

  public function __construct(
    private readonly StencilManager $stencilManager,
    private readonly StencilMaterialsProvider $provider,
    private readonly MaterialsChangeSet $materialsChangeSet,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_blueprint_stencil.manager'),
      $container->get('daedalus_blueprint_stencil.materials_provider'),
      $container->get('daedalus_blueprint.materials_change_set'),
    );
  }

  /**
   * A write's response: its payload plus the request's materials delta.
   */
  private function mutated(array $payload, int $status = 200): JsonResponse {
    return new JsonResponse($payload + ['materials' => $this->materialsChangeSet->flush()], $status);
  }

  /**
   * POST /daedalus-blueprint/stencil/{reference}/details: the palette face.
   *
   * Body: {label, description, icon?, offered?}, handed to the provider's
   * update() verbatim: label and description required (the palette
   * entry's face, the admin edit form's grammar); icon ("media:<uuid>")
   * PRESENT writes (NULL or '' clears back to the palette default), ABSENT
   * leaves the stored icon standing, so a client speaking only the
   * two-field face never wipes an icon it did not offer to edit; offered
   * (a boolean) flips the AI opt-out the same way, ABSENT leaving it. The
   * machine name is identity (placement provenance stamps reference it)
   * and never changes.
   */
  public function updateDetails(string $reference, Request $request): JsonResponse {
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->badRequest();
    }
    $changes = array_intersect_key($payload, array_flip(['label', 'description', 'icon', 'offered']));
    $changes += ['label' => '', 'description' => ''];
    try {
      $result = $this->provider->update(MaterialKind::Stencil, $reference, $changes, NULL, []);
    }
    catch (MaterialGestureException $exception) {
      return $this->gestureFailure($exception);
    }
    return $this->mutated(['stencil' => $this->stencilManager->stencilPayload($result->entity)]);
  }

  /**
   * POST /daedalus-blueprint/stencil/{reference}/delete: remove a stencil.
   *
   * No body; the reference is the whole gesture. The response echoes the
   * deleted stencil's payload so the client can name it in its message.
   */
  public function delete(string $reference): JsonResponse {
    try {
      $payload = $this->stencilManager->stencilPayload($this->stencilManager->resolveUpdatable($reference));
      $this->provider->remove(MaterialKind::Stencil, $reference, []);
    }
    catch (MaterialGestureException $exception) {
      return $this->gestureFailure($exception);
    }
    return $this->mutated(['deleted' => $payload]);
  }

  /**
   * POST /daedalus-blueprint/stencil-variant/{reference}/release.
   *
   * No body; the reference (a variant machine name or uuid) is the whole
   * gesture. The variant returns to general-purpose: both ownership lanes
   * clear, and it offers by its scope everywhere again. Shipped variants
   * refuse (the disk lane re-adopts at the next import); unowned variants
   * refuse (nothing to release). The response echoes the variant's new
   * ownership state so the client can patch its library in place.
   */
  public function releaseVariant(string $reference): JsonResponse {
    try {
      $variant = $this->stencilManager->releaseVariant($reference);
    }
    catch (MaterialGestureException $exception) {
      return $this->gestureFailure($exception);
    }
    return $this->mutated(['variant' => $this->stencilManager->variantOwnershipPayload($variant)]);
  }

  /**
   * POST /daedalus-blueprint/stencil-variant/{reference}/transfer.
   *
   * Body: {stencil}: the content stencil (machine name or uuid) taking
   * ownership. Release + adopt as ONE atomic gesture, so the owned-by-
   * another case never exposes an intermediate unowned state. File-shipped
   * target stencils refuse (only the disk lane writes file ownership), as
   * do shipped variants (same disk-authority rule as release).
   */
  public function transferVariant(string $reference, Request $request): JsonResponse {
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->badRequest();
    }
    $target = $payload['stencil'] ?? NULL;
    if (!is_string($target) || $target === '') {
      return new JsonResponse([
        'errors' => [
          [
            'path' => 'stencil',
            'code' => 'MISSING_PARAMETER',
            'message' => 'A non-empty stencil reference is required.',
            'hint' => 'Name the content stencil taking ownership by its machine name or uuid.',
          ],
        ],
      ], 422);
    }
    try {
      $stencil = $this->stencilManager->resolveUpdatable($target);
      $variant = $this->stencilManager->transferVariant($reference, $stencil);
    }
    catch (MaterialGestureException $exception) {
      return $this->gestureFailure($exception);
    }
    return $this->mutated(['variant' => $this->stencilManager->variantOwnershipPayload($variant)]);
  }

  /**
   * Decodes a JSON request body to an array, or NULL when malformed.
   */
  private function decode(Request $request): ?array {
    $decoded = json_decode($request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * A gesture refusal in the standard shape, with the honest status code.
   *
   * The provider and the resolvers speak in the same errors the ops do: an
   * unknown stencil or variant reference is a 404, a refusal by the
   * permission gate or the stencil's own access handler a 403, everything
   * else (read-only file
   * stencils, shipped variants, missing parameters) a 422 the client
   * should relay verbatim.
   */
  private function gestureFailure(MaterialGestureException $exception): JsonResponse {
    $codes = array_map(static fn ($error) => $error->getCode(), $exception->getErrors());
    $status = match (TRUE) {
      $codes === ['STENCIL_NOT_FOUND'], $codes === ['STENCIL_VARIANT_NOT_FOUND'] => 404,
      $codes === ['STENCIL_FORBIDDEN'], $codes === ['PERMISSION_DENIED'] => 403,
      default => 422,
    };
    return new JsonResponse(['errors' => $exception->getErrorsAsArrays()], $status);
  }

  /**
   * A 400 for unparseable bodies.
   */
  private function badRequest(): JsonResponse {
    return new JsonResponse([
      'errors' => [
        [
          'path' => '',
          'code' => 'INVALID_PAYLOAD',
          'message' => 'The request body is not a JSON object.',
          'hint' => 'Send the gesture\'s parameters as a JSON object.',
        ],
      ],
    ], 400);
  }

}
