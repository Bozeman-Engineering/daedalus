<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Controller;

use Drupal\block_content\BlockContentTypeInterface;
use Drupal\daedalus_layout\AddPanel\BlockTypeFace;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Favorite for everyone on a block type: the Add panel's write door.
 *
 * A favorite is a fact on the thing, and a block type's is its
 * `third_party_settings.daedalus_layout.favorite` (BlockTypeFace), the
 * same key the block type form writes. The Add panel's row menu offers
 * "Favorite for everyone" on a block type's row where the face says the
 * actor may write it (AddPanelFaces `editable`), and posts here:
 * `POST /daedalus-layout/block-type/{block_content_type}/favorite` with
 * `{favorite: bool}`. The route is gated by the block type's update
 * access, which is the bundle's own admin permission ("administer block
 * types"), and by the session's CSRF header token, as the blueprint
 * gestures' routes are.
 *
 * Why a route of its own and not the config blueprint door through the
 * API module's `mutate`: that door's `update_properties` sets a whole
 * property (`$entity->set('third_party_settings', ...)`), so one key of
 * one module's section could only be written by resending every module's
 * settings on the type, a read-modify-write the panel has no honest
 * source for (its face carries the resolved values, never the map).
 *
 * The answer is the face's new default under the panel's item grammar,
 * `{id: "inline_block:<type>", favorite: bool}`, so the client restates
 * the one face it holds. A blueprint's row writes its favorite through
 * the library's details route instead (js/src/blueprint-gestures.js).
 * A refusal answers the gestures' error shape (`errors[{path, code,
 * message, hint}]`), which the client relays verbatim.
 */
final class BlockTypeFavorite {

  /**
   * The route name.
   */
  public const ROUTE = 'daedalus_layout.block_type_favorite';

  /**
   * The prefix of the inline block plugin id the face answers under.
   */
  private const INLINE_BLOCK_PREFIX = 'inline_block:';

  /**
   * Writes the block type's site default favorite.
   *
   * @param \Drupal\block_content\BlockContentTypeInterface $block_content_type
   *   The block type, upcast from the route.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, whose JSON body carries `favorite`.
   */
  public function set(BlockContentTypeInterface $block_content_type, Request $request): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body) || !array_key_exists('favorite', $body) || !is_bool($body['favorite'])) {
      return new JsonResponse([
        'errors' => [
          [
            'path' => 'favorite',
            'code' => 'INVALID_PAYLOAD',
            'message' => 'The request body must be a JSON object with a boolean "favorite".',
            'hint' => 'Send {"favorite": true} to list the block type under Favorites for everyone, or false to stop.',
          ],
        ],
      ], 400);
    }
    BlockTypeFace::setFavorite($block_content_type, $body['favorite']);
    $block_content_type->save();
    return new JsonResponse([
      'id' => self::INLINE_BLOCK_PREFIX . $block_content_type->id(),
      'favorite' => $body['favorite'],
    ]);
  }

}
