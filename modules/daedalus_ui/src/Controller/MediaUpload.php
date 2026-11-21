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
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\Event\LayoutBuilderReplaceMedia;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Media upload.
 *
 * The human upload lane's HTTP adapter: the dropped file is saved through
 * the daedalus_ui file lane (validators, the SVG sanitizer, renaming on
 * collision) under public://media, and a refusal is answered as a 400
 * whose body names the reasons. A dropped file can only become media, so
 * the lane is narrowed to the media kinds (never the textual kinds the AI
 * reads), and the media is created only for an actor who may create media
 * of that bundle. The file is saved temporary: the media entity that takes
 * it records a file usage, which makes it permanent; a file no media ever
 * holds falls to core's temporary-file garbage collection.
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
    private BlueprintAccessChecker $accessChecker,
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
      $container->get('daedalus_blueprint.access_checker'),
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
   *   response (the client plays it and awaits the form's choice; the
   *   form submits to its own route, never back here).
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function newMedia(Request $request, EntityInterface $entity, string $view_mode) {
    $this->hostOf($entity, $view_mode);

    // The target media bundle is not known yet (the user picks it on the
    // later file-association form), so the lane is narrowed to the media
    // kinds: "could this file become some media here?".
    $upload = $request->files->get('file');
    if (!$upload instanceof UploadedFile) {
      throw new BadRequestHttpException('Invalid file');
    }
    $file = $this->saveUpload($upload, $this->fileLane->mediaExtensions());
    $request->getSession()->set('uploaded_file', $file);

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
      throw new NotFoundHttpException(sprintf('There is no %s media type.', $media_bundle));
    }
    // Judged before the file is saved, so a refusal leaves nothing behind.
    $this->assertMayCreate($media_bundle);
    $media_type = $this->entityTypeManager()->getStorage('media_type')->load($media_bundle);
    if (!$this->fileLane->hasFileSource($media_type)) {
      throw new BadRequestHttpException(sprintf('The %s media type does not keep its source in a file.', $media_bundle));
    }

    $upload = $request->files->get('file');
    if (!$upload instanceof UploadedFile) {
      throw new BadRequestHttpException('Invalid file');
    }
    $this->hostOf($entity, $view_mode);

    $entity = $this->tempstoreRepository->get($entity);
    // The target bundle is known, so the lane is narrowed to its source
    // field's extensions; a field that takes any extension is narrowed to
    // the media kinds, the lane never inheriting that site choice.
    $file = $this->saveUpload($upload, $this->fileLane->mediaTypeExtensions($media_type) ?? $this->fileLane->mediaExtensions());
    $media = $this->createMedia($file, $media_bundle);

    return $this->replacePageElementWithNewMedia($request, $media, $entity, $view_mode, $media_reference);
  }

  /**
   * Saves the dropped file through the lane, a refusal answered as a 400.
   *
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $upload
   *   The dropped file.
   * @param list<string> $extensions
   *   The narrowing of the lane's accepted extensions.
   */
  private function saveUpload(UploadedFile $upload, array $extensions): FileInterface {
    try {
      return $this->fileLane->fromRequest($upload, self::DIRECTORY, FALSE, $extensions);
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
   * Refuses an actor who may not create media of a bundle.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  private function assertMayCreate(string $media_bundle): void {
    if (!$this->accessChecker->createAccess('media', $media_bundle)) {
      throw new AccessDeniedHttpException(sprintf('You may not create %s media.', $media_bundle));
    }
  }

  /**
   * Create Media.
   *
   * The one place the upload lane creates media, for the replace drop and
   * for the page drop's resolution alike, so the create access check sits
   * here. Saving the media records its file usage, which is what makes
   * the uploaded file permanent.
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
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   When the current user may not create media of the bundle.
   */
  public function createMedia(FileInterface $file, string $media_bundle) {
    $this->assertMayCreate($media_bundle);

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
