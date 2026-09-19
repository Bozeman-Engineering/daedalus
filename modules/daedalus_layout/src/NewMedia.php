<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout;

use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\daedalus_ui\Controller\MediaUpload;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;

/**
 * New media.
 *
 * Common functionality shared by DaedalusUiNewMedia and
 * MediaBlockFileAssociationForm: resolving which block type / media
 * reference field carries a dragged file, and creating the media entity.
 * Placement itself is the client's — both callers hand a resolution back
 * to the browser, which places the block through the blueprint mutation
 * endpoint like every other placement.
 */
final class NewMedia {

  public function __construct(
    protected RequestStack $requestStack,
    protected ClassResolverInterface $classResolver,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected LayoutTempstoreRepositoryInterface $layoutBuilderTempstore,
  ) {}

  /**
   * Builds the client-facing resolution for a dragged file.
   *
   * Creates the media entity from the session's uploaded file and names
   * the block type + media reference field the client's add_element
   * operation should use.
   *
   * @param string $association
   *   The Block Content bundle carrying the media.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state (file_extension and entity are set by both callers).
   *
   * @return array
   *   Keys: block_type, field_name, media_target_id.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function buildResolution(string $association, FormStateInterface $form_state): array {
    $file = $this->requestStack->getCurrentRequest()->getSession()->get('uploaded_file');
    [$media_bundle, $field_name] = $this->getMediaReferenceInfo($association, $form_state);

    assert(!empty($field_name));
    assert(!empty($media_bundle));

    $media_upload = $this->classResolver->getInstanceFromDefinition(MediaUpload::class);
    $media = $media_upload->createMedia($file, array_key_first($media_bundle));

    return [
      'block_type' => $association,
      'field_name' => $field_name,
      'media_target_id' => (int) $media->id(),
    ];
  }

  /**
   * Get Section Storage Details.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   An array containing [NestedSectionStorageInterface, parameters].
   *
   * @throws \Exception
   */
  public function getSectionStorageDetails(FormStateInterface $form_state): array {
    $entity = $form_state->get('entity');
    $canonical_storage = NestedAwareSectionStorage::getSectionStorageForEntity($entity);

    // Get the storage from tempstore (where pending changes live).
    $section_storage = $this->layoutBuilderTempstore->get($canonical_storage);

    // Ensure it's wrapped as NestedSectionStorageInterface.
    $section_storage = NestedAwareSectionStorage::wrap($section_storage);

    $parameters = $form_state->get('query_parameters') ?? [];
    return [$section_storage, $parameters];
  }

  /**
   * Get media reference info.
   *
   * @param string $association
   *   The Block Content bundle.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   A tuple of [media bundle, field name] for the association.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getMediaReferenceInfo(string $association, FormStateInterface $form_state): array {
    $block_content_type = $this->entityTypeManager->getStorage('block_content_type')->load($association);
    $file_extension = $form_state->get('file_extension');
    $file_extension = strtolower($file_extension);
    $compatible_media_types = $this->getCompatibleMediaTypes($file_extension);
    $compatible_media_type_ids = array_map(function ($media_type) {
      return $media_type->id();
    }, $compatible_media_types);
    $fields = $this->entityFieldManager->getFieldDefinitions('block_content', $block_content_type->id());
    $media_bundle = NULL;
    $field_name = NULL;
    foreach ($fields as $field) {
      if ($field->getType() === 'entity_reference' && $field->getSetting('target_type') === 'media') {
        $handler_settings = $field->getSetting('handler_settings');
        $allowed_media_types = $handler_settings['target_bundles'] ?? [];

        // If no specific bundles are set (empty array), all media types are
        // allowed. Or if there's an overlap with compatible media types,
        // include this block content type.
        if (empty($allowed_media_types) || $media_bundle = array_intersect($allowed_media_types, $compatible_media_type_ids)) {
          $field_name = $field->getName();
          break;
        }
      }
    }
    return [$media_bundle, $field_name];
  }

  /**
   * Get compatible media types.
   *
   * @param string $file_extension
   *   A file extension e.g. jpg.
   *
   * @return array
   *   The media types whose source field accepts the file extension.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getCompatibleMediaTypes(string $file_extension): array {
    $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    $compatible_media_types = [];
    foreach ($media_types as $media_type) {
      $source_config = $media_type->getSource()->getConfiguration();
      $source_field = $source_config['source_field'] ?? NULL;
      if (!$source_field) {
        continue;
      }
      $field_config = $this->entityTypeManager->getStorage('field_config')->load('media.' . $media_type->id() . '.' . $source_field);
      if (!$field_config) {
        continue;
      }

      // A media type can carry a dropped file when its source field is
      // file-based. Detect that by capability (the file_extensions setting
      // every FileItem-derived field type defines) rather than by
      // enumerating field type ids: file, image, and svg_image_field are
      // all file-backed, and so is any future FileItem subclass. Non-file
      // sources (oembed's string_long, etc.) have no such setting and are
      // skipped.
      $settings = $field_config->getSettings();
      if (!array_key_exists('file_extensions', $settings)) {
        continue;
      }

      // An empty file_extensions setting means any extension is accepted.
      $allowed_extensions = $settings['file_extensions'];
      if (empty($allowed_extensions) || in_array($file_extension, explode(' ', strtolower($allowed_extensions)), TRUE)) {
        $compatible_media_types[] = $media_type;
      }
    }
    return $compatible_media_types;
  }

}
