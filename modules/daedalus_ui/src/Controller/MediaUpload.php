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
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\Event\LayoutBuilderReplaceMedia;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Media upload.
 *
 * The human upload lane's HTTP adapter: the dropped file is saved through
 * the daedalus_ui file lane (validators, the SVG sanitizer, renaming on
 * collision) as a permanent file under public://media, and a refusal is
 * answered as a 400 whose body names the reasons.
 */
final class MediaUpload extends ControllerBase {

  /**
   * Where the human upload lane keeps its files.
   */
  private const DIRECTORY = 'public://media';

  public function __construct(
    private EntityTempstoreRepository $tempstoreRepository,
    private FileLane $fileLane,
    private EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    private EventDispatcherInterface $eventDispatcher,
    private HostResolver $hostResolver,
    private DaedalusUi $daedalusUi,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('daedalus_tempstore.entity_tempstore_repository'),
      $container->get('daedalus_ui.file_lane'),
      $container->get('entity_type.bundle.info'),
      $container->get('event_dispatcher'),
      $container->get('daedalus_blueprint.host_resolver'),
      $container->get('daedalus_ui.ui'),
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
    $this->hostOf($entity, $view_mode);

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
   * A user has dragged a Media Type compatible file from their browser onto
   * a media reference rendered on the page (a placed block's field or the
   * host's own), and the media is replaced on the entity whose field it is.
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
    $this->hostOf($entity, $view_mode);

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
   * Commits the replacement through the spine and answers its payload.
   *
   * One road for every page: the Layout Builder integration answers the
   * event with one update_element on the entity whose field the drop
   * landed on (a placed block named by the request's blockUuid, else the
   * host's own field), judged at the op door like any other write.
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
    $event = $this->eventDispatcher->dispatch(new LayoutBuilderReplaceMedia($media, $entity, $view_mode, $media_reference), LayoutBuilderReplaceMedia::class);
    return $event->getResponse();
  }

  /**
   * The host a media route addresses, its view mode checked.
   *
   * Any host: a content entity or a Layout Builder display (the template
   * canvas takes file drops too). A display renders in the one view mode
   * its display is for; a content entity in any of its type's.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The route's entity.
   * @param string $view_mode
   *   The route's view mode.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface
   *   The host.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   When nothing hosts the entity.
   * @throws \InvalidArgumentException
   *   When the view mode is not one the host renders in.
   */
  private function hostOf(EntityInterface $entity, string $view_mode): HostInterface {
    if (!$this->hostResolver->hosts($entity)) {
      throw new NotFoundHttpException(sprintf('Nothing hosts %s %s.', $entity->getEntityTypeId(), $entity->id()));
    }
    $host = $this->hostResolver->fromEntity($entity);
    if (!$this->daedalusUi->isValidViewMode($host, $view_mode)) {
      throw new \InvalidArgumentException(sprintf('Invalid view mode for %s', $host->label()));
    }
    return $host;
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
