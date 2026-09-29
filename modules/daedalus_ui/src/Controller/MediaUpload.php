<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Controller;

use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\daedalus_ui\File\FileLaneException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
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
 *
 * The human upload lane's HTTP adapter: the dropped file is saved through
 * the daedalus_ui file lane (validators, the SVG sanitizer, renaming on
 * collision) as a permanent file under public://media, and a refusal is
 * answered as a 400 whose body names the reasons.
 */
final class MediaUpload extends ControllerBase {

  use LoadEditablePageResponseTrait;

  /**
   * Where the human upload lane keeps its files.
   */
  private const DIRECTORY = 'public://media';

  public function __construct(
    private EntityTempstoreRepository $tempstoreRepository,
    private FileLane $fileLane,
    private EntityDisplayRepositoryInterface $entityDisplayRepository,
    private EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('daedalus_tempstore.entity_tempstore_repository'),
      $container->get('daedalus_ui.file_lane'),
      $container->get('entity_display.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('event_dispatcher'),
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

    // The target media bundle is not known yet (the user picks it on the
    // later file-association form), so the lane's full accepted set applies:
    // "could this file become some media here?".
    $upload = $request->files->get('file');
    if ($upload instanceof UploadedFile) {
      $file = $this->saveUpload($upload);
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

    $upload = $request->files->get('file');
    if (!$upload instanceof UploadedFile) {
      throw new BadRequestHttpException('Invalid file');
    }

    $entity = $this->tempstoreRepository->get($entity);
    // The target bundle is known, so the lane is narrowed to its source
    // field's extensions.
    $media_type = $this->entityTypeManager()->getStorage('media_type')->load($media_bundle);
    $file = $this->saveUpload($upload, $this->fileLane->mediaTypeExtensions($media_type));
    $media = $this->createMedia($file, $media_bundle);

    return $this->replacePageElementWithNewMedia($request, $media, $entity, $view_mode, $media_reference);
  }

  /**
   * Saves the dropped file through the lane, a refusal answered as a 400.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The dropped file.
   * @param list<string>|null $extensions
   *   A narrowing of the lane's accepted extensions, or NULL for the full set.
   */
  private function saveUpload(UploadedFile $upload, ?array $extensions = NULL): FileInterface {
    try {
      return $this->fileLane->fromRequest($upload, self::DIRECTORY, TRUE, $extensions);
    }
    catch (FileLaneException $e) {
      throw new BadRequestHttpException($e->getMessage(), $e);
    }
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

}
