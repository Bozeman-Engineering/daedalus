<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit_image_block;

use Drupal\block_content\BlockContentInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_edit\DaedalusEditMediaLibraryOpener;
use Drupal\media\MediaInterface;
use Drupal\daedalus_ui\EventSubscriber\ReplaceMediaFieldAttributes;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\Render\ComposedBlockContentInterface;

/**
 * The image block's content for the generative composed render.
 *
 * Structure is the declaration's (the ordered root forms: anchor when
 * linked, img on raster media, inline svg on vector media, placeholder
 * div while empty); this provider supplies what only the module knows:
 * - The img's server-derived source properties (src through the media
 *   bundle's configured image style, alt, dimensions, loading), addressed
 *   to the declared `.image-block-image` part — which the resolver hoists
 *   onto the root when the img IS the root, so the properties follow the
 *   part in both link states.
 * - The vector lane's svg, addressed exactly like raster: the inliner's
 *   allowlisted attribute bag lands on the same picture element, and the
 *   sanitized inner markup mounts as the part-claimed field child (at the
 *   root when the part hoisted, since a hoisted part's claim dissolves).
 *   When the file cannot be inlined (missing, unparseable) the inner is
 *   an <image href> referencing the raw file instead: real SVG content
 *   whose HTTP request lets Stage File Proxy fetch a missing file from
 *   the origin.
 * - The empty state's placeholder content. The placeholder is editing
 *   chrome: an img with no src is broken markup, so an empty block
 *   renders the attributed placeholder on edit-capable renders and is
 *   SUPPRESSED live — an empty image block is invisible to visitors.
 * - The editing attribution: the widget-tier gate (the blueprint editor
 *   with the server-built media library URL on the media_library widget,
 *   the form-swap lookup attributes on any other configured widget), and
 *   the drop-to-replace bag. All of it rides the root, whatever form
 *   resolved: the root is the block, and everything that consumes it
 *   re-resolves by attribute after every swap.
 *
 * The derivation helpers stay in the .module file
 * (_daedalus_edit_image_block_image_properties and its svg inliner): they are
 * the media-to-markup facts this provider consumes, unchanged.
 */
final class ImageComposedContent implements ComposedBlockContentInterface {

  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
    protected DaedalusUi $daedalusUi,
    protected DaedalusEditMediaLibraryOpener $mediaLibraryOpener,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function blockType(): string {
    return 'image';
  }

  /**
   * {@inheritdoc}
   */
  public function content(BlockContentInterface $block_content, array $resolved, bool $edit_capable): array {
    $content = [];
    /** @var \Drupal\media\MediaInterface|null $media */
    $media = $block_content->get('field_image')->entity;
    $image = NULL;
    if ($media instanceof MediaInterface) {
      // The composed render reads the media (and its file) directly. With
      // the field hidden in the view display, no formatter chain renders
      // the media, so its cacheability rides here or a media swap/edit
      // would serve stale markup.
      $content['cache']['tags'] = $media->getCacheTags();
      $image = _daedalus_edit_image_block_image_properties($media);
    }

    $picture_key = $this->pictureElementKey($resolved);
    if ($image !== NULL && $picture_key !== NULL && array_key_exists('svg', $image)) {
      // The vector lane, addressed exactly like raster: the inliner's
      // allowlisted attribute bag lands on the declared picture element
      // (the root itself unlinked, the nested part linked), and the
      // sanitized inner markup mounts as the claimed field child. Inline
      // is the point: fill="currentColor" resolves against the inherited
      // CSS color, which a sealed img src never can.
      $content['attributes'][$picture_key] = $image['svg']['attributes'];
      $content['children']['field:field_image'] = ['#markup' => $image['svg']['inner']];
      $content['attached']['library'][] = 'daedalus_edit_image_block/vector';
    }
    elseif ($image !== NULL && $picture_key !== NULL) {
      // The raster lane: the derived source properties land on the
      // declared img element — the root itself unlinked (hoisted), the
      // nested part linked.
      $attributes = [
        'src' => $image['src'],
        'alt' => $image['alt'],
      ];
      if ($image['width']) {
        $attributes['width'] = (string) $image['width'];
      }
      if ($image['height']) {
        $attributes['height'] = (string) $image['height'];
      }
      $attributes['loading'] = $image['loading'];
      $content['attributes'][$picture_key] = $attributes;
    }
    elseif ($media === NULL) {
      if (!$edit_capable) {
        // An empty image block is invisible to visitors; its declared
        // placeholder root is editing chrome.
        $content['suppress'] = TRUE;
        return $content;
      }
      $content['attributes']['']['class'][] = 'daedalus-edit-image-block-placeholder';
      $content['children']['placeholder'] = ['#plain_text' => (string) $this->t('Add an image')];
      $content['attached']['library'][] = 'daedalus_edit_image_block/placeholder';
    }

    if ($edit_capable) {
      $content['attributes'][''] = array_merge(
        $content['attributes'][''] ?? [],
        $this->editingAttribution($block_content, $media),
      );
    }
    return $content;
  }

  /**
   * The root's editing attribution: the widget-tier gate plus the drop bag.
   */
  private function editingAttribution(BlockContentInterface $block_content, ?MediaInterface $media): array {
    $attributes = [];
    // The tier gate: the configured field_image widget decides which
    // editing path this render attributes. media_library_widget → the
    // blueprint editor (media selection commits through the opener,
    // form-free); anything else (entity_browser, custom widgets) → the
    // widget-agnostic form-swap path, exactly as any form-edited field.
    // One render stamps ONE editor kind: an element carrying
    // data-daedalus-edit-editor routes to the blueprint branch before
    // data-daedalus-edit-id is ever read.
    $form_display = $this->entityDisplayRepository->getFormDisplay('block_content', 'image');
    $widget = $form_display->getComponent('field_image')['type'] ?? NULL;
    if ($widget === 'media_library_widget') {
      $attributes['data-daedalus-edit-editor'] = 'image_composite';
      // The editor's one setting is the ready-made media library URL (state
      // hash included, built server-side per the CKEditor precedent); the
      // widget just opens it as an AJAX modal. The opener parameters carry
      // the HOST entity — the blueprint mutation root the selection commits
      // to — which is the route's main entity here: this render only
      // happens while that entity renders (initial page or its mutation
      // re-render), the same derivation that stamps data-main-entity.
      $host = $this->daedalusUi->deriveEntityFromRoute();
      $media_library_url = $host ? $this->mediaLibraryOpener->buildUrl(
        $host,
        $block_content->get('field_image')->getFieldDefinition(),
        $media?->bundle(),
      ) : NULL;
      if ($media_library_url) {
        $attributes['data-daedalus-edit-editor-settings'] = Json::encode([
          'media_library_url' => $media_library_url,
        ]);
      }
    }
    else {
      // The form path resolves its two required lookup attributes anywhere
      // in the DOM, so they hoist onto the composed root: the
      // partial-update selector then matches the root, making a field
      // re-render a whole-root swap — which is exactly right for a
      // composed block. The page-element id keeps the form-item →
      // page-element lookup working from the sidebar form side. The
      // property segment is 'value', NOT the field type's main property
      // (target_id): the form-side stampers hardcode 'value'
      // (FormDefaultFieldAttributes / FormMediaFieldAttributes), and the
      // render-side stamper agrees for every configurable field because
      // FieldConfig has no getMainPropertyName() method to consult
      // (RenderEditableFieldAttributes falls back to 'value'). The lookup
      // ids only ever match each other, so the grammar's de facto property
      // for configurable fields is 'value' — stamping target_id orphans
      // the form-item wrapper lookup and setWidget() throws.
      $id = daedalus_ui_entity_identifier($block_content);
      $attributes['data-daedalus-edit-field-value-wrapper'] = sprintf('%s::%s::%s::%s', $block_content->getEntityTypeId(), $id, 'field_image', 'value');
      $attributes['data-daedalus-edit-id'] = sprintf('%s::%s::%s::%s::%s::%s::%s::%s::%s', $block_content->getEntityTypeId(), $block_content->bundle(), $id, 'field_image', 0, $block_content->language()->getId(), 'entity_reference', 'entity_reference_entity_view', 'value');
      $attributes['data-daedalus-edit-page-element-id'] = sprintf('%s::%s::%s::%s::%s', $block_content->getEntityTypeId(), $id, 'field_image', 0, 'value');
    }

    // The drop-to-replace bag, one vocabulary shared with the per-item
    // field wrappers daedalus_ui stamps on ordinary renders. The media
    // TYPE is enough to attribute the target, so an empty block's
    // placeholder is a drop target for the first image too (the field's
    // single target bundle decides what a drop creates).
    $media_type_id = $media?->bundle();
    if (!$media_type_id) {
      $target_bundles = $block_content->get('field_image')->getFieldDefinition()->getSetting('handler_settings')['target_bundles'] ?? [];
      $media_type_id = reset($target_bundles) ?: NULL;
    }
    if ($media_type_id) {
      /** @var \Drupal\media\MediaTypeInterface|null $media_type */
      $media_type = $this->entityTypeManager->getStorage('media_type')->load($media_type_id);
      if ($media_type) {
        $attributes += ReplaceMediaFieldAttributes::mediaReferenceAttributes('field_image', $media_type);
      }
    }
    return $attributes;
  }

  /**
   * The resolved element the picture part answers to, or NULL when absent.
   *
   * '' (the root) when the resolver hoisted the part (the unlinked
   * picture IS the root, img and svg alike), the part key when it nests
   * (linked), NULL on the picture-free lanes (empty, degenerate media).
   */
  private function pictureElementKey(array $resolved): ?string {
    if (in_array('.image-block-image', $resolved['hoisted'], TRUE)) {
      return '';
    }
    return isset($resolved['parts']['.image-block-image']) ? '.image-block-image' : NULL;
  }

}
