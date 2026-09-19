<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\media_library\MediaLibraryOpenerInterface;
use Drupal\media_library\MediaLibraryState;
use Drupal\daedalus_blueprint\IconResolver;

/**
 * The media library opener for icon pickers.
 *
 * Core's media library has a designed form-free seam: an opener service
 * interacts with the library on behalf of whatever opened it (the daedalus_edit
 * and daedalus_stylish openers are the precedents). This opener serves the
 * daedalus_layout_icon_picker form element: the picker renders the media_library.ui
 * URL built here as a use-ajax modal link, and the user's selection returns
 * as AJAX commands that write the canonical icon value `media:<uuid>` (the
 * icon grammar's user lane, see IconResolver) into the picker's hidden input
 * and refresh its preview. No host form round-trip is involved: the value
 * only persists when the host form is saved.
 *
 * Access: the opener parameters carry the host config entity whose icon the
 * picker edits (a block type today; hash-covered, so the client cannot
 * retarget the library), and the library is offered to whoever may update
 * that host — the same gate as the form the picker sits on.
 *
 * The allowed media types are the types whose source field holds an image
 * or a vector (SOURCE_FIELD_TYPES, the same capability gate as daedalus_stylish's
 * MediaValueResolver::SOURCE_FIELD_TYPES): icons are visual by definition,
 * so the picker offers only visual media. The resolver deliberately accepts
 * any file-backed media (pickers constrain the offer, not the grammar).
 * Icons render as inert chrome (background-image / <img>), so vectors stay
 * sealed from page CSS and scripts here.
 *
 * @see \Drupal\daedalus_layout\Element\IconPicker
 * @see \Drupal\daedalus_blueprint\IconResolver
 */
class IconPickerOpener implements MediaLibraryOpenerInterface {

  /**
   * The opener's service id — the media_library_opener_id the state carries.
   */
  public const SERVICE_ID = 'daedalus_layout.icon_picker.opener';

  /**
   * The source field types whose media types the picker offers.
   */
  public const SOURCE_FIELD_TYPES = ['image', 'svg_image_field'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected IconResolver $iconResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function checkAccess(MediaLibraryState $state, AccountInterface $account) {
    $parameters = $state->getOpenerParameters();
    foreach (['entity_type_id', 'entity_id', 'picker'] as $key) {
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
    $picker = (string) $state->getOpenerParameters()['picker'];
    // The key is generated from form element parents (see IconPicker), so
    // this only ever rejects a hand-tampered state; belt-and-suspenders for
    // the attribute selector below.
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $picker)) {
      return $response;
    }
    // Remaining = 1: an icon pick is a "use this image" gesture, one item.
    $media = $this->entityTypeManager->getStorage('media')->load(reset($selected_ids));
    if ($media instanceof MediaInterface) {
      $value = IconResolver::MEDIA_PREFIX . strtolower($media->uuid());
      $cache_tags = [];
      $url = $this->iconResolver->resolve($value, $cache_tags);
      $selector = '[data-daedalus-layout-icon-picker-value="' . $picker . '"]';
      $response->addCommand(new InvokeCommand($selector, 'val', [$value]));
      // The picker's behavior updates the preview from the resolved URL (or
      // its default icon when the media has no file). The library dialog's
      // close command rides the same response (MediaLibrarySelectForm).
      $response->addCommand(new InvokeCommand($selector, 'trigger', ['daedalusLayoutIconPicked', [$url]]));
    }
    return $response;
  }

  /**
   * Builds the media_library.ui URL an icon picker opens as a modal.
   *
   * The CKEditor precedent (and daedalus_edit's, daedalus_stylish's): the state is
   * constructed server-side, hash included, and the ready-made URL renders
   * into the picker as a use-ajax link — no client-side state construction.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose icon the picker edits (the access subject — the
   *   library is offered to whoever may update it).
   * @param string $picker_key
   *   The picker instance key the selection response targets
   *   (data-daedalus-layout-icon-picker-value).
   * @param string|null $selected_type_id
   *   (optional) The media type whose tab opens selected, e.g. the type of
   *   the currently referenced media. Falls back to the first allowed type.
   *
   * @return string|null
   *   The generated URL, or NULL when no library can be offered (an unsaved
   *   host cannot be addressed by the opener parameters; no image- or
   *   vector-source media types means nothing selectable).
   */
  public function buildUrl(EntityInterface $host, string $picker_key, ?string $selected_type_id = NULL): ?string {
    if (!$host->id() || !$this->entityTypeManager->hasDefinition('media_type')) {
      return NULL;
    }
    $allowed_type_ids = $this->allowedMediaTypeIds();
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
        'picker' => $picker_key,
      ],
    );

    return Url::fromRoute('media_library.ui')
      ->setOption('query', $state->all())
      ->toString(TRUE)
      ->getGeneratedUrl();
  }

  /**
   * The media types the picker offers: image and vector source types.
   *
   * Gated on the source FIELD TYPE, not the source plugin class, so custom
   * sources with a qualifying source field count (the same shape as
   * daedalus_stylish's gate; the constant is not shared because daedalus_layout does not
   * depend on daedalus_stylish).
   *
   * @return string[]
   *   The media type ids.
   */
  protected function allowedMediaTypeIds(): array {
    $ids = [];
    /** @var \Drupal\media\MediaTypeInterface $type */
    foreach ($this->entityTypeManager->getStorage('media_type')->loadMultiple() as $type) {
      $source_field = $type->getSource()->getSourceFieldDefinition($type);
      if (in_array($source_field?->getType(), self::SOURCE_FIELD_TYPES, TRUE)) {
        $ids[] = (string) $type->id();
      }
    }
    return $ids;
  }

}
