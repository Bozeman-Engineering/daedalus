<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The shared JSON envelope of the daedalus_stylish gesture endpoints.
 *
 * Variant and swatch gestures answer in one error shape — {errors:
 * [{path, code, message, hint}]}, the blueprint error list — so the client
 * transport (gesture-transport.js) reports every failure identically,
 * whichever entity the gesture touched, and every WRITE answers with the
 * same `materials` {upsert, remove} delta the mutation response carries
 * (MaterialsChangeSet, fed by the entity hooks the write ran), so the
 * client store learns a mint, rename, restore or delete from the route
 * lane exactly as from the op lane. This base owns the envelope (decoding,
 * the 400/404/422 responses, the delta); each controller owns its routes,
 * payload shapes, and entity-specific error codes.
 */
abstract class GestureApiControllerBase extends ControllerBase {

  /**
   * The controller's payload grammar, hinted on unparseable bodies.
   */
  protected const PAYLOAD_HINT = 'Send a JSON object.';

  public function __construct(
    protected readonly MaterialsChangeSet $materialsChangeSet,
  ) {}

  /**
   * Decodes a JSON request body to an array, or NULL when malformed.
   */
  protected function decode(Request $request): ?array {
    $decoded = json_decode($request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * A write's response: its payload plus the request's materials delta.
   */
  protected function mutated(array $payload, int $status = 200): JsonResponse {
    return new JsonResponse($payload + ['materials' => $this->materialsChangeSet->flush()], $status);
  }

  /**
   * A single error in the standard envelope.
   */
  protected function singleError(string $path, string $code, string $message, string $hint, int $status): JsonResponse {
    return new JsonResponse([
      'errors' => [
        [
          'path' => $path,
          'code' => $code,
          'message' => $message,
          'hint' => $hint,
        ],
      ],
    ], $status);
  }

  /**
   * A 400 for unparseable bodies.
   *
   * The hint is the controller's PAYLOAD_HINT — the one part of the
   * response each gesture family owns.
   */
  protected function badRequest(): JsonResponse {
    return $this->singleError('', 'INVALID_PAYLOAD', 'The request body is not a JSON object.', static::PAYLOAD_HINT, 400);
  }

  /**
   * A 422 (or the given status) carrying the validation errors.
   *
   * Typed on the spine's MaterialGestureException: the one structured
   * error contract the material gesture family speaks, which daedalus_stylish's
   * variant and swatch exceptions extend.
   */
  protected function unprocessable(MaterialGestureException $exception, int $status = 422): JsonResponse {
    return new JsonResponse(['errors' => $exception->getErrorsAsArrays()], $status);
  }

}
