<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint_library\Materials\BlueprintMaterialsProvider;
use Drupal\daedalus_blueprint_library\BlueprintManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The blueprint gesture endpoints outside the op spine.
 *
 * Details, delete, and the variant-ownership lifecycle (release, transfer).
 *
 * Details and delete are ADAPTERS over the blueprint kind of the material
 * gesture family: the same BlueprintMaterialsProvider::update() / remove()
 * the update_material / remove_material ops dispatch to, so the palette's
 * Edit details and Delete gestures and the AI's ops share one grammar, one
 * validation path and one permission gate. They keep their own routes
 * because the Add panel's blueprint menu has no page batch to ride: the
 * palette manages the vocabulary from wherever it is open, and a route
 * answers the same `materials` {upsert, remove} delta the mutation response
 * carries (MaterialsChangeSet, fed by BlueprintCacheHooks), so the client
 * store learns the write from either lane identically. The delta is
 * resolved for the canvas the request names (the `entity_type` and
 * `entity_id` query coordinates the dialog routes carry, the page the Add
 * panel is open on), so a details change that ends the fit comes back as
 * a remove and leaves the open panel at once; a request naming no canvas
 * flushes unfiltered, as the op lane does over no canvas.
 *
 * Release and transfer stay manager gestures: they rewrite the
 * non-revisionable ownership identity on daedalus_stylish variants and are
 * not one of the family's three verbs (adopt rides the capture ops;
 * releasing or transferring curation is human judgment on these routes).
 *
 * Access is the route's permission; the provider asks the blueprint
 * entity's own access handler as well, so the handler stays the single
 * authority on blueprint mutation, and file-shipped blueprints refuse
 * loudly (read-only everywhere by design). Errors come back in the
 * blueprint error shape ({path, code, message, hint}) so the client
 * reports them like any other blueprint failure.
 */
final class BlueprintApiController extends ControllerBase {

  /**
   * The body keys the details route hands to the provider's update().
   */
  private const DETAILS_KEYS = ['label', 'description', 'icon', 'offered', 'subject', 'bundles', 'groups', 'favorite'];

  /**
   * The corrective for a canvas the request names but the site has not.
   */
  private const CANVAS_HINT = 'Name the canvas by the entity_type and entity_id of the page the Add panel is open on, or name none.';

  public function __construct(
    private readonly BlueprintManager $blueprintManager,
    private readonly BlueprintMaterialsProvider $provider,
    private readonly MaterialsChangeSet $materialsChangeSet,
    private readonly HostResolver $hostResolver,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    // ControllerBase declares the property untyped; it cannot be promoted.
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_blueprint_library.manager'),
      $container->get('daedalus_blueprint_library.materials_provider'),
      $container->get('daedalus_blueprint.materials_change_set'),
      $container->get('daedalus_blueprint.host_resolver'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * A write's response: its payload plus the request's materials delta.
   *
   * @param array $payload
   *   The gesture's own payload.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas the request names (canvas()), or NULL for none: the
   *   delta is resolved for it, so a touched blueprint it does not offer
   *   is a remove; with none the delta is unfiltered.
   * @param int $status
   *   The HTTP status.
   */
  private function mutated(array $payload, ?HostInterface $canvas, int $status = 200): JsonResponse {
    return new JsonResponse($payload + ['materials' => $this->materialsChangeSet->flush($canvas)], $status);
  }

  /**
   * The canvas host the request names, or NULL when it names none.
   *
   * The query's `entity_type` and `entity_id` are the coordinates the
   * dialog routes read (the entity being laid out, carried by
   * ModePluginBase.url()); the host they resolve to is the page the
   * client's Add panel is open on, the one its delta must be judged for.
   * A fit is judged on the subject's type and bundle, which no staged
   * edit changes, so the saved entity is the canvas. Resolved before the
   * write so a malformed canvas refuses with nothing stuck.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   *   When the coordinates name an unknown type, an entity that does not
   *   exist, or one that hosts no document: a client bug, refused loudly
   *   rather than answered with a delta for the wrong canvas.
   */
  private function canvas(Request $request): ?HostInterface {
    $entity_type = $request->query->get('entity_type');
    $entity_id = $request->query->get('entity_id');
    if ($entity_type === NULL && $entity_id === NULL) {
      return NULL;
    }
    if (!is_string($entity_type) || $entity_type === '' || !$this->entityTypeManager->hasDefinition($entity_type)) {
      throw new MaterialGestureException([
        new BlueprintError(
          'entity_type',
          'INVALID_PARAMETER',
          'The canvas names an unknown entity type.',
          ['entity_type' => $entity_type],
          self::CANVAS_HINT,
        ),
      ]);
    }
    $entity = is_string($entity_id) && $entity_id !== '' ? $this->entityTypeManager->getStorage($entity_type)->load($entity_id) : NULL;
    if ($entity === NULL || !$this->hostResolver->hosts($entity)) {
      throw new MaterialGestureException([
        new BlueprintError(
          'entity_id',
          'INVALID_PARAMETER',
          sprintf('No canvas %s:%s exists.', $entity_type, is_scalar($entity_id) ? (string) $entity_id : ''),
          ['entity_type' => $entity_type, 'entity_id' => $entity_id],
          self::CANVAS_HINT,
        ),
      ]);
    }
    return $this->hostResolver->fromEntity($entity);
  }

  /**
   * POST /daedalus-blueprint/blueprint/{reference}/details: the details face.
   *
   * Body: {label, description, icon?, offered?, subject?, bundles?,
   * groups?, favorite?}, handed to the provider's update() verbatim: label and
   * description required
   * (the palette entry's face, the admin edit form's grammar); icon
   * ("media:<uuid>") PRESENT writes (NULL or '' clears back to the palette
   * default), ABSENT leaves the stored icon standing, so a client speaking
   * only the two-field face never wipes an icon it did not offer to edit;
   * offered (a boolean) flips the AI opt-out the same way, ABSENT leaving
   * it; subject ("entity_type:bundle") and bundles (a list of them, [] for
   * any host) rewrite what the blueprint is material for, ABSENT leaving
   * them; groups (a list of the site's blueprint group ids, [] for none)
   * refiles it under the filters an offering surface shows, ABSENT
   * leaving them; favorite (a boolean) marks or unmarks it as a site
   * favorite the same way. The machine name and the scope are identity (placement
   * provenance stamps reference the name; what a blueprint stands in for
   * does not change with its details) and never change.
   *
   * The query names the canvas (canvas()): a subject or allow-list change
   * that ends the fit answers the blueprint under the delta's remove.
   */
  public function updateDetails(string $reference, Request $request): JsonResponse {
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->badRequest();
    }
    $changes = array_intersect_key($payload, array_flip(self::DETAILS_KEYS));
    $changes += ['label' => '', 'description' => ''];
    try {
      $canvas = $this->canvas($request);
      $result = $this->provider->update(MaterialKind::Blueprint, $reference, $changes, NULL, []);
    }
    catch (MaterialGestureException $exception) {
      return $this->gestureFailure($exception);
    }
    return $this->mutated(['blueprint' => $this->blueprintManager->blueprintPayload($result->entity)], $canvas);
  }

  /**
   * POST /daedalus-blueprint/blueprint/{reference}/delete: remove a blueprint.
   *
   * No body; the reference is the whole gesture, the query names the
   * canvas (canvas()). The response echoes the deleted blueprint's payload
   * so the client can name it in its message.
   */
  public function delete(string $reference, Request $request): JsonResponse {
    try {
      $canvas = $this->canvas($request);
      $payload = $this->blueprintManager->blueprintPayload($this->blueprintManager->resolveUpdatable($reference));
      $this->provider->remove(MaterialKind::Blueprint, $reference, []);
    }
    catch (MaterialGestureException $exception) {
      return $this->gestureFailure($exception);
    }
    return $this->mutated(['deleted' => $payload], $canvas);
  }

  /**
   * POST /daedalus-blueprint/blueprint-variant/{reference}/release.
   *
   * No body; the reference (a variant machine name or uuid) is the whole
   * gesture. The variant returns to general-purpose: both ownership lanes
   * clear, and it offers by its scope everywhere again. Shipped variants
   * refuse (the disk lane re-adopts at the next import); unowned variants
   * refuse (nothing to release). The response echoes the variant's new
   * ownership state so the client can patch its library in place. The
   * query may name the canvas (canvas()).
   */
  public function releaseVariant(string $reference, Request $request): JsonResponse {
    try {
      $canvas = $this->canvas($request);
      $variant = $this->blueprintManager->releaseVariant($reference);
    }
    catch (MaterialGestureException $exception) {
      return $this->gestureFailure($exception);
    }
    return $this->mutated(['variant' => $this->blueprintManager->variantOwnershipPayload($variant)], $canvas);
  }

  /**
   * POST /daedalus-blueprint/blueprint-variant/{reference}/transfer.
   *
   * Body: {blueprint}: the content blueprint (machine name or uuid) taking
   * ownership. Release + adopt as ONE atomic gesture, so the owned-by-
   * another case never exposes an intermediate unowned state. File-shipped
   * target blueprints refuse (only the disk lane writes file ownership),
   * as do shipped variants (same disk-authority rule as release). The
   * query may name the canvas (canvas()).
   */
  public function transferVariant(string $reference, Request $request): JsonResponse {
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->badRequest();
    }
    $target = $payload['blueprint'] ?? NULL;
    if (!is_string($target) || $target === '') {
      return new JsonResponse([
        'errors' => [
          [
            'path' => 'blueprint',
            'code' => 'MISSING_PARAMETER',
            'message' => 'A non-empty blueprint reference is required.',
            'hint' => 'Name the content blueprint taking ownership by its machine name or uuid.',
          ],
        ],
      ], 422);
    }
    try {
      $canvas = $this->canvas($request);
      $blueprint = $this->blueprintManager->resolveUpdatable($target);
      $variant = $this->blueprintManager->transferVariant($reference, $blueprint);
    }
    catch (MaterialGestureException $exception) {
      return $this->gestureFailure($exception);
    }
    return $this->mutated(['variant' => $this->blueprintManager->variantOwnershipPayload($variant)], $canvas);
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
   * unknown blueprint or variant reference is a 404, a refusal by the
   * permission gate or the blueprint's own access handler a 403,
   * everything else (read-only file blueprints, shipped variants, missing
   * parameters, a canvas that does not exist) a 422 the client should
   * relay verbatim.
   */
  private function gestureFailure(MaterialGestureException $exception): JsonResponse {
    $codes = array_map(static fn ($error) => $error->getCode(), $exception->getErrors());
    $status = match (TRUE) {
      $codes === ['BLUEPRINT_NOT_FOUND'], $codes === ['BLUEPRINT_VARIANT_NOT_FOUND'] => 404,
      $codes === ['BLUEPRINT_FORBIDDEN'], $codes === ['PERMISSION_DENIED'] => 403,
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
