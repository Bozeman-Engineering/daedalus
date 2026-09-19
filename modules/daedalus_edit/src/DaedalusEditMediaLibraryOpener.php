<?php

namespace Drupal\daedalus_edit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\daedalus_edit\Ajax\MediaLibrarySelection;
use Drupal\media_library\MediaLibraryOpenerInterface;
use Drupal\media_library\MediaLibraryState;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;

/**
 * The media library opener for blueprint-edited leaves.
 *
 * Core's media library has a designed form-free seam: an opener service
 * interacts with the library on behalf of whatever opened it. This opener
 * serves blueprint-edited media references (the image block's composed
 * editor is the first consumer; any media-reference blueprint editor is a
 * future one): the editor opens the media_library.ui URL its preprocess
 * embedded in the editor settings, and the user's selection returns as one
 * AJAX command that the client routes to the current edit, which commits it
 * as an `update_element` op on the blueprint mutation spine. No host form
 * exists at any point.
 *
 * Access mirrors the mutation spine the selection commits through
 * (daedalus_blueprint_api.mutate requires entity.update on the host): the
 * opener parameters carry the HOST entity, and update access is checked on
 * its tempstore-staged copy when one exists, because that staged copy is
 * what the edit session is mutating. The state hash covers the opener
 * parameters, so the client cannot retarget the library at another host.
 *
 * @see \Drupal\media_library\MediaLibraryOpenerInterface
 * @see \Drupal\daedalus_edit\Ajax\MediaLibrarySelection
 */
class DaedalusEditMediaLibraryOpener implements MediaLibraryOpenerInterface {

  /**
   * The opener's service id — the media_library_opener_id the state carries.
   */
  public const SERVICE_ID = 'daedalus_edit.media_library.opener';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTempstoreRepository $entityTempstoreRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function checkAccess(MediaLibraryState $state, AccountInterface $account) {
    $parameters = $state->getOpenerParameters();
    foreach (['entity_type_id', 'entity_id'] as $key) {
      if (empty($parameters[$key])) {
        return AccessResult::forbidden("$key parameter is missing.")
          ->addCacheableDependency($state);
      }
    }

    $host = $this->entityTypeManager->getStorage($parameters['entity_type_id'])
      ->load($parameters['entity_id']);
    if (!$host) {
      return AccessResult::forbidden('The host entity could not be loaded.')
        ->addCacheableDependency($state);
    }

    // The edit session mutates the tempstore-staged copy of the host, so
    // that copy (when one exists) is what access is checked against.
    $host = $this->entityTempstoreRepository->get($host);
    $access = $host->access('update', $account, TRUE);
    if ($access instanceof RefinableCacheableDependencyInterface) {
      $access->addCacheableDependency($state);
    }
    return $access;
  }

  /**
   * {@inheritdoc}
   */
  public function getSelectionResponse(MediaLibraryState $state, array $selected_ids) {
    $response = new AjaxResponse();
    $response->addCommand(new MediaLibrarySelection(array_values($selected_ids)));
    return $response;
  }

  /**
   * Builds the media_library.ui URL a blueprint editor opens as a modal.
   *
   * The CKEditor precedent (ckeditor5's MediaLibrary plugin): the state is
   * constructed server-side, hash included, and the ready-made URL travels
   * to the client in the editor settings, so the widget just opens it as a
   * Drupal AJAX modal — no client-side state construction. One remaining
   * slot: a blueprint media-reference edit is a "replace the reference"
   * gesture, selecting exactly one item.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The host entity the selection will commit to (the blueprint mutation
   *   root — the page's main entity, not the inline block).
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The media reference field being edited; its target bundles are the
   *   library's allowed types.
   * @param string|null $selected_type_id
   *   (optional) The media type whose tab opens selected, e.g. the type of
   *   the currently referenced media. Falls back to the first allowed type.
   *
   * @return string|null
   *   The generated URL, or NULL when no library can be offered (an unsaved
   *   host cannot be addressed by the opener parameters; no allowed media
   *   types means there is nothing to select).
   */
  public function buildUrl(EntityInterface $host, FieldDefinitionInterface $field_definition, ?string $selected_type_id = NULL): ?string {
    if (!$host->id()) {
      return NULL;
    }
    $allowed_type_ids = array_values($field_definition->getSetting('handler_settings')['target_bundles'] ?? []);
    if (!$allowed_type_ids) {
      $allowed_type_ids = array_values($this->entityTypeManager->getStorage('media_type')->getQuery()->execute());
    }
    if (!$allowed_type_ids) {
      return NULL;
    }
    if (!$selected_type_id || !in_array($selected_type_id, $allowed_type_ids, TRUE)) {
      $selected_type_id = reset($allowed_type_ids);
    }

    $state = MediaLibraryState::create(
      self::SERVICE_ID,
      $allowed_type_ids,
      $selected_type_id,
      1,
      [
        'entity_type_id' => $host->getEntityTypeId(),
        'entity_id' => (string) $host->id(),
      ],
    );

    return Url::fromRoute('media_library.ui')
      ->setOption('query', $state->all())
      ->toString(TRUE)
      ->getGeneratedUrl();
  }

}
