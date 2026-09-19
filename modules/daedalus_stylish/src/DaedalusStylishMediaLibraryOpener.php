<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\media_library\MediaLibraryOpenerInterface;
use Drupal\media_library\MediaLibraryState;
use Drupal\daedalus_stylish\Ajax\MediaLibrarySelection;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;

/**
 * The media library opener for media-valued styles.
 *
 * Core's media library has a designed form-free seam: an opener service
 * interacts with the library on behalf of whatever opened it (the daedalus_edit
 * opener is the precedent). This opener serves the style inspector's media
 * pickers: the picker opens the media_library.ui URL the sidebar embedded in
 * drupalSettings, and the user's selection returns as one AJAX command
 * carrying the semantic token url('media:<uuid>') plus the server-resolved
 * preview URL, which the client routes to the waiting picker. No host form
 * exists at any point.
 *
 * Access mirrors the mutation spine the selection commits through (an
 * update_style op via daedalus_blueprint_api.mutate requires entity.update on
 * the map host): the opener parameters carry the HOST entity, and update
 * access is checked on its tempstore-staged copy when one exists, because
 * that staged copy is what the edit session is mutating. The state hash
 * covers the opener parameters, so the client cannot retarget the library at
 * another host.
 *
 * The allowed media types are the types whose source field is an image or a
 * vector (MediaValueResolver::SOURCE_FIELD_TYPES) — the same gate validation
 * enforces on the token (PropertyGroupBase::validateMediaTokens's
 * MEDIA_NOT_IMAGE), so the library can only offer what the map will accept.
 * Vectors stay inert here: a pick resolves to a plain file URL for
 * background-image, never inline markup.
 *
 * @see \Drupal\media_library\MediaLibraryOpenerInterface
 * @see \Drupal\daedalus_stylish\Ajax\MediaLibrarySelection
 */
class DaedalusStylishMediaLibraryOpener implements MediaLibraryOpenerInterface {

  /**
   * The opener's service id — the media_library_opener_id the state carries.
   */
  public const SERVICE_ID = 'daedalus_stylish.media_library.opener';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MediaValueResolver $mediaValueResolver,
    protected ?EntityTempstoreRepository $entityTempstoreRepository = NULL,
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
    $host = $this->entityTempstoreRepository?->get($host) ?? $host;
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
    // Remaining = 1: a style pick is a "use this image" gesture, one item.
    $media = $this->entityTypeManager->getStorage('media')->load(reset($selected_ids));
    if ($media instanceof MediaInterface) {
      $uuid = strtolower($media->uuid());
      $response->addCommand(new MediaLibrarySelection(
        $uuid,
        MediaToken::build($uuid),
        // The resolved URL makes the pick's preview instant (the client
        // extends its uuid -> URL table before staging). Keyless preview
        // resolution: no map key exists at selection time, so the site
        // default style applies (see MediaValueResolver::previewUrl()).
        $this->mediaValueResolver->previewUrl($uuid),
      ));
    }
    return $response;
  }

  /**
   * Builds the media_library.ui URL the inspector's pickers open as a modal.
   *
   * The CKEditor precedent (and daedalus_edit's): the state is constructed
   * server-side, hash included, and the ready-made URL travels to the client
   * in drupalSettings (InspectorSidebar), so a picker just opens it as a
   * Drupal AJAX modal — no client-side state construction. One URL per map
   * host serves every media-capable property row.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The map host entity the selection will commit to (the page's root
   *   entity — the blueprint mutation root that owns the style map).
   *
   * @return string|null
   *   The generated URL, or NULL when no library can be offered (an unsaved
   *   host cannot be addressed by the opener parameters; no image- or
   *   vector-source media types means nothing selectable could ever
   *   validate).
   */
  public function buildUrl(EntityInterface $host): ?string {
    if (!$host->id() || !$this->entityTypeManager->hasDefinition('media_type')) {
      return NULL;
    }
    $allowed_type_ids = $this->allowedMediaTypeIds();
    if (!$allowed_type_ids) {
      return NULL;
    }

    $state = MediaLibraryState::create(
      self::SERVICE_ID,
      $allowed_type_ids,
      reset($allowed_type_ids),
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

  /**
   * The media types a token can reference: image and vector source types.
   *
   * The same gate as validation (the source FIELD TYPE per
   * MediaValueResolver::SOURCE_FIELD_TYPES, not the source plugin class, so
   * custom sources with a qualifying source field count).
   *
   * @return string[]
   *   The media type ids.
   */
  protected function allowedMediaTypeIds(): array {
    $ids = [];
    /** @var \Drupal\media\MediaTypeInterface $type */
    foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $type) {
      $source_field = $type->getSource()->getSourceFieldDefinition($type);
      if (in_array($source_field?->getType(), MediaValueResolver::SOURCE_FIELD_TYPES, TRUE)) {
        $ids[] = (string) $type->id();
      }
    }
    return $ids;
  }

}
