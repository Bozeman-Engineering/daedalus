<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Controller;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\Exception\TokenValidationException;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\TokenManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The token lifecycle endpoints.
 *
 * The swatch API's twin for the material that lives on the skin: the
 * per-token management read (usage), rename, revert and delete. Minting
 * and the value, description and offered editors are not routes: the
 * palette submits save_material / update_material (kind token) through
 * the page batch, the same ops the AI submits. The palette manages the
 * rest of the vocabulary from wherever it is open, with no page batch to
 * ride, so those gestures speak to the token manager through these routes
 * and receive the same materials delta the op lane answers with (the skin
 * save's entity hook records every token id it carried); a write's
 * response is that delta beside the route's own facts (deleted, renamed,
 * references, batch_url), never an echo of the token.
 *
 * A token id is "{scale}/{name}", the roster's, and every route addresses
 * the ACTIVE skin's token (a token is never skin-qualified). The path
 * spells the id as two parameters, /tokens/{scale}/{name}, never as one
 * slash-carrying {reference}: Drupal's route provider matches a request
 * by pattern outline (one % per parameter), so a parameter holding a
 * slash can only ever be a path's trailing segment, and /usage, /rename
 * and /revert after it would be unreachable (404). Delete is an
 * adapter over DaedalusStylishMaterialsProvider::remove() like the swatch
 * delete; rename and revert are the manager's own gestures, since a
 * rename may stage a batch the op spine cannot drive.
 */
final class TokenApiController extends GestureApiControllerBase {

  /**
   * {@inheritdoc}
   */
  protected const PAYLOAD_HINT = 'Send {label} as JSON.';

  public function __construct(
    private readonly TokenManager $tokenManager,
    private readonly DaedalusStylishMaterialsProvider $provider,
    private readonly MaterialsRosterBuilder $rosterBuilder,
    MaterialsChangeSet $materialsChangeSet,
  ) {
    parent::__construct($materialsChangeSet);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_stylish.token_manager'),
      $container->get('daedalus_stylish.materials_provider'),
      $container->get('daedalus_blueprint.materials_roster_builder'),
      $container->get('daedalus_blueprint.materials_change_set'),
    );
  }

  /**
   * GET /daedalus_stylish/tokens/{scale}/{name}/usage: the management read.
   *
   * The palette row's loud number: the count drifts mid-session, so the
   * prompts that speak consequences (rename, delete) fetch it at gesture
   * time. Beside it the management facts a row needs beyond the roster
   * record: which layer(s) hold the token (package, site or both),
   * whether the package layer is locked on this site (the identity
   * gestures' gate: rename and delete of a package token refuse while it
   * holds; TokenManager::writesSiteLayer()), the skin's provider and the
   * token's shipped state against the disk file right now (the revert
   * affordance's gate). None rides the roster record (a record says where
   * a material came from, never what a module's disk copy says today, and
   * a token record is always updatable: the site layer takes the write).
   */
  public function usage(string $scale, string $name): JsonResponse {
    $address = $this->address($scale, $name);
    if ($address instanceof JsonResponse) {
      return $address;
    }
    [$skin, $scale, $name] = $address;
    return new JsonResponse([
      'id' => "$scale/$name",
      'scale' => $scale,
      'name' => $name,
      'variable' => $this->tokenManager->variable($scale, $name),
      'usages' => $this->tokenManager->usages($scale, $name),
      'layer' => $this->tokenManager->layer($skin, $scale, $name),
      'locked' => $this->tokenManager->writesSiteLayer($skin),
      'provider' => $skin->getProvider(),
      'shipped_state' => $this->tokenManager->shippedState($skin, $scale, $name),
    ]);
  }

  /**
   * POST /daedalus_stylish/tokens/{scale}/{name}/rename: the name gesture.
   *
   * Body: {label}, the new name. The name IS the custom property's tail,
   * so an unreferenced token moves in this request (200, the delta
   * removing the old id and upserting the new) and a REFERENCED token
   * stages the rewrite-then-rename Batch API job (TokenManager::rename())
   * and answers 202 with the batch URL for the client to drive: every
   * var() of it is respelled, then the record moves. The response states
   * the outcome the staged batch is committed to (the old id's removal
   * and the renamed record, TokenManager::renamedRecord()), and the
   * client applies it once it has driven the batch to the end. A package
   * token refuses on a consuming site.
   */
  public function rename(string $scale, string $name, Request $request): JsonResponse {
    $address = $this->address($scale, $name);
    if ($address instanceof JsonResponse) {
      return $address;
    }
    [$skin, $scale, $name] = $address;
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->badRequest();
    }
    $label = is_string($payload['label'] ?? NULL) ? $payload['label'] : '';
    try {
      $references = $this->tokenManager->rename($skin, $scale, $name, $label);
    }
    catch (TokenValidationException $exception) {
      return $this->unprocessable($exception);
    }
    $id = $scale . '/' . trim($label);
    if ($references === 0) {
      return $this->mutated(['renamed' => TRUE, 'id' => $id, 'references' => 0]);
    }
    $batch_response = batch_process();
    $record = $this->rosterBuilder->build()->get(MaterialKind::Token, "$scale/$name");
    assert($record !== NULL);
    return $this->mutated([
      'renamed' => FALSE,
      'id' => $id,
      'references' => $references,
      'batch_url' => $batch_response->getTargetUrl(),
      'materials' => array_merge_recursive(
        $this->materialsChangeSet->stagedRemoval(MaterialKind::Token, "$scale/$name"),
        $this->materialsChangeSet->stagedUpsert($this->tokenManager->renamedRecord($record, trim($label))),
      ),
    ], 202);
  }

  /**
   * POST /daedalus_stylish/tokens/{scale}/{name}/revert: back to shipped.
   *
   * An ordinary revisioned save converging the token toward what the
   * skin's provider ships on disk right now (the site's record over it
   * dropped; the package record rewritten under the developer gate). No
   * body; the disk is the payload. The reverted record rides the delta.
   */
  public function revert(string $scale, string $name): JsonResponse {
    $address = $this->address($scale, $name);
    if ($address instanceof JsonResponse) {
      return $address;
    }
    [$skin, $scale, $name] = $address;
    try {
      $this->tokenManager->revert($skin, $scale, $name);
    }
    catch (TokenValidationException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated([]);
  }

  /**
   * DELETE /daedalus_stylish/tokens/{scale}/{name}: delete, always safely.
   *
   * The site's record goes in this request when the token stays minted
   * (a site override dropped reveals the package's token: the unset
   * gesture) or nothing references it. A REFERENCED token whose removal
   * would delete it stages the rewrite-then-delete Batch API job
   * (TokenManager::deleteToken() / TokenDeleteBatch) and responds 202 with
   * the batch URL for the client to drive: every var() of it becomes the
   * token's literal value, then the token goes. There is no "delete
   * anyway". The two permission grades: the route admits both, and a
   * referenced token without the in-use grade comes back 403 with the
   * STYLE_TOKEN_IN_USE corrective. A package token the site never touched
   * refuses either way (the distro lock).
   */
  public function delete(string $scale, string $name): JsonResponse {
    $address = $this->address($scale, $name);
    if ($address instanceof JsonResponse) {
      return $address;
    }
    [$skin, $scale, $name] = $address;
    try {
      $result = $this->provider->remove(MaterialKind::Token, "$scale/$name", [MaterialGestureOperationHandler::BATCH_OPTION => TRUE]);
    }
    catch (MaterialGestureException $exception) {
      return $this->unprocessable($exception, in_array('STYLE_TOKEN_IN_USE', array_column($exception->getErrorsAsArrays(), 'code'), TRUE) ? 403 : 422);
    }
    if ($result->staged === 0) {
      return $this->mutated([
        'deleted' => !$this->tokenManager->has($skin, $scale, $name),
        'references' => 0,
      ]);
    }
    $batch_response = batch_process();
    // The token goes in the batch's last request, so this response cannot
    // carry the removal as a flushed delta (the roster still holds the
    // token); it states the outcome the staged batch is committed to, and
    // the client applies it once it has driven the batch to the end.
    return $this->mutated([
      'deleted' => FALSE,
      'references' => $result->staged,
      'batch_url' => $batch_response->getTargetUrl(),
      'materials' => $this->materialsChangeSet->stagedRemoval(MaterialKind::Token, "$scale/$name"),
    ], 202);
  }

  /**
   * Resolves the path's scale and name to the active skin's token.
   *
   * @return array{0: \Drupal\daedalus_stylish\DaedalusStylishSkinInterface, 1: string, 2: string}|\Symfony\Component\HttpFoundation\JsonResponse
   *   The address, or the error response: 422 when no skin is active,
   *   404 when the active skin mints no such token.
   */
  private function address(string $scale, string $name): array|JsonResponse {
    try {
      $skin = $this->tokenManager->activeSkin();
    }
    catch (TokenValidationException $exception) {
      return $this->unprocessable($exception);
    }
    if (!$this->tokenManager->has($skin, $scale, $name)) {
      return $this->singleError('id', 'STYLE_TOKEN_NOT_FOUND', sprintf('"%s/%s" is not a token of the active skin.', $scale, $name), 'Address a token by its roster id, "{scale}/{name}", as /tokens/{scale}/{name}.', 404);
    }
    assert($skin instanceof DaedalusStylishSkinInterface);
    return [$skin, $scale, $name];
  }

}
