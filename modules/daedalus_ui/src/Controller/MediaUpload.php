<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Controller;

use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\Core\File\FileExists;
use Drupal\media\MediaTypeInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Utility\Bytes;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\file\Upload\FormUploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Drupal\daedalus_ui\Event\LayoutBuilderNewMedia;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\daedalus_ui\LoadEditablePageResponseTrait;
use Drupal\daedalus_ui\Event\LayoutBuilderReplaceMedia;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Media upload.
 */
final class MediaUpload extends ControllerBase {

  use LoadEditablePageResponseTrait;

  public function __construct(
    private EntityTempstoreRepository $tempstoreRepository,
    private FileUploadHandlerInterface $fileUploadHandler,
    private EntityDisplayRepositoryInterface $entityDisplayRepository,
    private EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private EventDispatcherInterface $eventDispatcher,
    private FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('daedalus_tempstore.entity_tempstore_repository'),
      $container->get('file.upload_handler'),
      $container->get('entity_display.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('event_dispatcher'),
      $container->get('file_system'),
    );
  }

  /**
   * New media.
   *
   * A user dragged a Media Type compatible file from their desktop and
   * dropped it at the insertion caret. This endpoint owns the upload leg
   * only: it saves the file and asks subscribers to resolve which block
   * type carries it. Placement is the client's — it turns the resolution
   * into an add_element operation through the blueprint mutation endpoint,
   * the same path as every other placement.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be updated.
   * @param string $view_mode
   *   The view mode.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|\Drupal\Core\Ajax\AjaxResponse
   *   A JSON envelope {resolution: {block_type, field_name,
   *   media_target_id}} when a stored file association resolved the block
   *   type, or the block-type chooser dialog as a Drupal AJAX command
   *   response (the client plays it and awaits the form's choice).
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function newMedia(Request $request, EntityInterface $entity, string $view_mode) {
    // Sanitize view mode.
    $valid_view_modes = $this->entityDisplayRepository->getViewModes($entity->getEntityTypeId());
    $valid_view_modes['default'] = TRUE;
    if (empty($valid_view_modes[$view_mode])) {
      throw new \InvalidArgumentException(sprintf('Invalid view mode for %s', $entity->label()));
    }

    $file = $request->files->get('file');
    if (!empty($file)) {
      $file = $this->uploadFile($request, $this->getNewMediaValidators());
      $session = $request->getSession();
      $session->set('uploaded_file', $file);
    }

    $entity = $this->tempstoreRepository->get($entity);
    $event = $this->eventDispatcher->dispatch(new LayoutBuilderNewMedia($request, $entity, $view_mode), LayoutBuilderNewMedia::class);
    if ($resolution = $event->getResolution()) {
      return new JsonResponse(['resolution' => $resolution]);
    }
    $response = $event->getResponse();
    if ($response === NULL) {
      throw new BadRequestHttpException('No module resolved the media placement. Is a Layout Builder integration (daedalus_layout) enabled?');
    }
    return $response;
  }

  /**
   * Replace media.
   *
   * A user has dragged a Media Type compatible file from their browser to a
   * dropzone on an existing Media Block on the page. We then replace the media.
   * This works on regular and LB pages.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be updated.
   * @param string $view_mode
   *   The view mode.
   * @param string $media_reference
   *   The media reference field name.
   * @param string $media_bundle
   *   The media entity type ID.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   A response that updates the page with the newly uploaded media.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function replaceMedia(Request $request, EntityInterface $entity, string $view_mode, string $media_reference, string $media_bundle) {

    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo('media');
    if (empty($bundle_info[$media_bundle])) {
      throw new \InvalidArgumentException(sprintf('Invalid media bundle %s', $entity->label()));
    }

    $entity = $this->tempstoreRepository->get($entity);
    $file = $this->uploadFile($request, $this->getReplaceMediaValidators($media_bundle));
    $media = $this->createMedia($file, $media_bundle);

    return $this->replacePageElementWithNewMedia($request, $media, $entity, $view_mode, $media_reference);
  }

  /**
   * Replace page element with new media.
   *
   * Determines if the media needs to be replaced on a regular or LB page.
   * Preserved fields on LB pages (fields rendered outside blocks) are treated
   * as non-LB fields since they lack block context.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param \Drupal\media\Entity\Media $media
   *   The newly uploaded media.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be updated.
   * @param string $view_mode
   *   The view mode.
   * @param string $media_reference
   *   The media reference field name.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   A response that updates the page with the newly uploaded media.
   */
  protected function replacePageElementWithNewMedia(Request $request, Media $media, EntityInterface $entity, string $view_mode, string $media_reference) {
    $has_block_context = $request->query->has('blockUuid') && $request->query->get('blockUuid');
    if ($this->isLayoutBuilderPage($entity, $view_mode) && $has_block_context) {
      $event = $this->eventDispatcher->dispatch(new LayoutBuilderReplaceMedia($media, $entity, $view_mode, $media_reference), LayoutBuilderReplaceMedia::class);
      return $event->getResponse();
    }
    else {
      return $this->updateNonLayoutBuilderPage($media, $entity, $view_mode, $media_reference);
    }
  }

  /**
   * Update field.
   *
   * Updates the page with the replaced media on non-layout builder pages.
   *
   * @param \Drupal\media\Entity\Media $media
   *   The newly uploaded media.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to be updated.
   * @param string $view_mode
   *   The view mode.
   * @param string $media_reference
   *   The media reference field name.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   A response that updates the page with the newly uploaded media.
   */
  private function updateNonLayoutBuilderPage(Media $media, EntityInterface $entity, string $view_mode, string $media_reference) {
    if (!$entity->hasField($media_reference)) {
      throw new \InvalidArgumentException(sprintf('Invalid media reference field "%s"', $media_reference));
    }
    $entity->get($media_reference)->setValue($media->id());
    $this->tempstoreRepository->set($entity);
    $build = $this->entityTypeManager()->getViewBuilder($entity->getEntityTypeId())->view($entity, $view_mode);

    return $this->getAjaxReplaceResponse($entity, $view_mode, $build);
  }

  /**
   * Is layout builder page.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param string $view_mode
   *   The view mode.
   *
   * @return bool
   *   Whether this particular view mode a layout builder page.
   */
  private function isLayoutBuilderPage(EntityInterface $entity, string $view_mode) {

    // Is this entity's display managed by layout builder?
    $config_name = sprintf('core.entity_view_display.%s.%s.%s',
      $entity->getEntityTypeId(),
      $entity->bundle(),
      $view_mode,
    );

    $config = $this->config($config_name);
    // Check that this view mode isn't just falling back to default. If so, load
    // the default.
    if ($view_mode !== 'default' && empty($config->getRawData())) {
      $view_mode = 'default';
    }

    $view_display = $this->entityDisplayRepository->getViewDisplay($entity->getEntityTypeId(), $entity->bundle(), $view_mode);
    if ($view_display) {
      // Check if Layout Builder is enabled for this view display.
      return !empty($view_display->getThirdPartySetting('layout_builder', 'enabled', FALSE));
    }
    return FALSE;
  }

  /**
   * Create Media.
   *
   * @param \Drupal\file\FileInterface $file
   *   The uploaded file entity.
   * @param string $media_bundle
   *   The media bundle.
   *
   * @return \Drupal\media\Entity\Media
   *   The created media entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function createMedia(FileInterface $file, string $media_bundle) {

    $media_type = $this->entityTypeManager()->getStorage('media_type')->load($media_bundle);
    $file_reference_field = $media_type->get('source_configuration')['source_field'];

    $media = Media::create([
      'bundle' => $media_bundle,
      'uid' => $this->currentUser()->id(),
      'name' => $file->getFilename(),
      $file_reference_field => [
        'target_id' => $file->id(),
      ],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Upload file.
   *
   * Validates the uploaded file and writes it to public://media as a permanent
   * File entity using Drupal core's file upload handler. The handler applies
   * the supplied validators (extension/size) and the core filename sanitizer,
   * so this is the single point where the browser upload becomes a saved file.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param array $validators
   *   File validators keyed by validator plugin ID, in the format expected by
   *   \Drupal\file\Validation\FileValidatorInterface (e.g. 'FileExtension',
   *   'FileSizeLimit'). An empty array lets core apply its default extension
   *   list.
   *
   * @return \Drupal\file\FileInterface
   *   The uploaded file entity.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Thrown when validation fails.
   */
  public function uploadFile(Request $request, array $validators = []): FileInterface {
    $file = $request->files->get('file');
    if (!$file instanceof UploadedFile) {
      throw new \InvalidArgumentException('Invalid file');
    }

    $directory = 'public://media';
    // Core's file upload handler does not create the destination directory; it
    // assumes the caller has prepared it. (The legacy file.repository path used
    // to do this implicitly.) Ensure it exists and is writable before handing
    // off, or moveUploadedFile() fails with "Could not move uploaded file".
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $result = $this->fileUploadHandler->handleFileUpload(
      new FormUploadedFile($file),
      $validators,
      $directory,
      FileExists::Rename,
    );

    if ($result->hasViolations()) {
      $messages = [];
      foreach ($result->getViolations() as $violation) {
        $messages[] = (string) $violation->getMessage();
      }
      throw new BadRequestHttpException(implode(' ', $messages));
    }

    $uploaded_file = $result->getFile();
    // The handler creates the file as temporary (status 0); media uploads are
    // intended to persist, so mark it permanent.
    $uploaded_file->setPermanent();
    $uploaded_file->save();
    return $uploaded_file;
  }

  /**
   * Builds file validators for a new-media upload.
   *
   * At new-media time the target media bundle is not yet known (the user picks
   * it on the later file-association form), so the extension validator is the
   * union of every media type's source-field extensions: "could this file
   * become some media here?". This mirrors daedalus_layout
   * NewMedia::getCompatibleMediaTypes() and fails early with a clear error
   * instead of dead-ending at the association form.
   *
   * @return array
   *   File validators keyed by validator plugin ID.
   */
  protected function getNewMediaValidators(): array {
    $media_types = $this->entityTypeManager()->getStorage('media_type')->loadMultiple();
    $extensions = [];
    foreach ($media_types as $media_type) {
      $settings = $this->getMediaSourceFieldSettings($media_type);
      if ($settings === NULL) {
        continue;
      }
      // An empty file_extensions setting means "allow any extension"; if any
      // media type allows anything, the union allows anything.
      if (empty($settings['file_extensions'])) {
        return [];
      }
      foreach (explode(' ', $settings['file_extensions']) as $extension) {
        $extension = trim($extension);
        if ($extension !== '') {
          $extensions[$extension] = $extension;
        }
      }
    }

    $validators = [];
    if (!empty($extensions)) {
      $validators['FileExtension'] = ['extensions' => implode(' ', $extensions)];
    }
    return $validators;
  }

  /**
   * Builds file validators for a replace-media upload.
   *
   * The target media bundle is known here, so validators are derived directly
   * from that bundle's source field: its allowed extensions and (if set) its
   * maximum file size.
   *
   * @param string $media_bundle
   *   The media bundle the file will be saved into.
   *
   * @return array
   *   File validators keyed by validator plugin ID.
   */
  protected function getReplaceMediaValidators(string $media_bundle): array {
    $media_type = $this->entityTypeManager()->getStorage('media_type')->load($media_bundle);
    $settings = $media_type ? $this->getMediaSourceFieldSettings($media_type) : NULL;

    $validators = [];
    if ($settings === NULL) {
      return $validators;
    }
    if (!empty($settings['file_extensions'])) {
      $validators['FileExtension'] = ['extensions' => $settings['file_extensions']];
    }
    if (!empty($settings['max_filesize'])) {
      $validators['FileSizeLimit'] = ['fileLimit' => (int) Bytes::toNumber($settings['max_filesize'])];
    }
    return $validators;
  }

  /**
   * Gets a media type's source-field settings.
   *
   * @param \Drupal\media\MediaTypeInterface $media_type
   *   The media type.
   *
   * @return array|null
   *   The source field settings (containing keys such as 'file_extensions' and
   *   'max_filesize'), or NULL if the source has no source field.
   */
  protected function getMediaSourceFieldSettings(MediaTypeInterface $media_type): ?array {
    $source = $media_type->getSource();
    $source_field = $source->getSourceFieldDefinition($media_type);
    if ($source_field === NULL) {
      return NULL;
    }
    return $source_field->getSettings();
  }

}
