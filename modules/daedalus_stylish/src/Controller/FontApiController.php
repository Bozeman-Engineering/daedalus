<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Controller;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_stylish\FontFiles;
use Drupal\daedalus_stylish\FontManager;
use Drupal\daedalus_stylish\FontStylesheet;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_ui\File\FileLaneException;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The font lifecycle endpoints: the animation API's twin for the type
 * material.
 *
 * Minting and editing a font are the save_material / update_material ops
 * (kind font); what the ops cannot carry is bytes, so the Fonts tab
 * uploads each face's file here first and then mints with the uuids the
 * responses name. The upload rides the font store (FontFiles, the
 * daedalus_ui file lane narrowed to woff2 and woff, permanent), and a
 * refusal is answered as a 422 whose body names the reasons in the
 * gesture error shape. The rest is the animation template: the
 * per-font management read (the live reference count beside the
 * shipped facts), the delete (an ADAPTER over the font kind of the
 * material gesture family, batch-staged when the family is named), and
 * the delivery read the canvas refreshes its @font-face from after a
 * write (the compiled file this page loaded predates any font minted
 * this session, and a face's url() is the server's to print).
 */
final class FontApiController extends GestureApiControllerBase {

  public function __construct(
    private readonly FontFiles $fontFiles,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly FontManager $fontManager,
    private readonly DaedalusStylishMaterialsProvider $provider,
    private readonly FontStylesheet $fontStylesheet,
    MaterialsChangeSet $materialsChangeSet,
  ) {
    parent::__construct($materialsChangeSet);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_stylish.font_files'),
      $container->get('file_url_generator'),
      $container->get('daedalus_stylish.font_manager'),
      $container->get('daedalus_stylish.materials_provider'),
      $container->get('daedalus_stylish.font_stylesheet'),
      $container->get('daedalus_blueprint.materials_change_set'),
    );
  }

  /**
   * Saves one uploaded font file: POST multipart, field "file".
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   200 {file: uuid, name, format, url}; 400 without a file; 422 when
   *   the lane refuses it.
   */
  public function upload(Request $request): JsonResponse {
    $upload = $request->files->get('file');
    if (!$upload instanceof UploadedFile) {
      return $this->singleError('file', 'MISSING_PARAMETER', 'The request carries no file.', 'Send the font file as the multipart form field "file" (woff2 or woff).', 400);
    }
    try {
      $file = $this->fontFiles->fromRequest($upload);
    }
    catch (FileLaneException $e) {
      return $this->singleError('file', 'INVALID_FONT_FILE', $e->getMessage(), 'Upload a woff2 or woff file within the size ceiling.', 422);
    }
    return new JsonResponse([
      'file' => $file->uuid(),
      'name' => $file->getFilename(),
      'format' => FontFiles::format($file),
      'url' => $this->fileUrlGenerator->generateString($file->getFileUri()),
    ]);
  }

  /**
   * GET /daedalus_stylish/fonts/{reference}/usage: the live reference count.
   *
   * The card's loud number beside the management facts no roster record
   * carries (the shipping module and the shipped state), read per open
   * and again at gesture time, the animation usage endpoint's twin.
   */
  public function usage(string $reference): JsonResponse {
    $font = $this->fontManager->loadFont($reference);
    if ($font === NULL) {
      return $this->notFound($reference);
    }
    $payload = $this->fontManager->fontPayload($font);
    return new JsonResponse([
      'machine_name' => $payload['machine_name'],
      'label' => $payload['label'],
      'family' => $payload['family'],
      'spelling' => $payload['spelling'],
      'usages' => $this->fontManager->usages($font),
      'provider' => $payload['provider'],
      'shipped_state' => $payload['shipped_state'],
    ]);
  }

  /**
   * GET /daedalus_stylish/fonts/stylesheet: the delivered @font-face text.
   *
   * The canvas's landing after a font write (leader-source.js): the
   * compiled fonts file this page loaded predates any font minted,
   * edited or deleted this session, so the leader re-reads the whole
   * current print and stamps it on the page, the skin defaults route's
   * posture. No client twin prints @font-face: a face's url() is the
   * managed file's, the server's to derive.
   */
  public function stylesheet(): JsonResponse {
    return new JsonResponse(['css' => $this->fontStylesheet->css()]);
  }

  /**
   * DELETE /daedalus_stylish/fonts/{reference}: delete, always safely.
   *
   * Unnamed fonts delete in this request. NAMED fonts stage the
   * rewrite-then-delete Batch API job (FontManager::deleteFont() /
   * FontDeleteBatch) and respond 202 with the batch URL for the client
   * to drive: the family leaves every font-family stack first, then the
   * entity goes. There is no "delete anyway". The two permission grades:
   * the route admits both, and a named font without the in-use grade
   * comes back 403 with the STYLE_FONT_IN_USE corrective. Shipped fonts
   * refuse either way (the distro lock).
   */
  public function delete(string $reference): JsonResponse {
    $font = $this->fontManager->loadFont($reference);
    if ($font === NULL) {
      return $this->notFound($reference);
    }
    try {
      $references = $this->provider->remove(MaterialKind::Font, $reference, [MaterialGestureOperationHandler::BATCH_OPTION => TRUE])->staged;
    }
    catch (MaterialGestureException $exception) {
      return $this->unprocessable($exception, in_array('STYLE_FONT_IN_USE', array_column($exception->getErrorsAsArrays(), 'code'), TRUE) ? 403 : 422);
    }
    if ($references === 0) {
      return $this->mutated(['deleted' => TRUE, 'references' => 0]);
    }
    $batch_response = batch_process();
    // The entity goes in the batch's last request, so this response cannot
    // carry the removal as a flushed delta (the roster still holds the
    // font); it states the outcome the staged batch is committed to, and
    // the client applies it once it has driven the batch to the end.
    return $this->mutated([
      'deleted' => FALSE,
      'references' => $references,
      'batch_url' => $batch_response->getTargetUrl(),
      'materials' => $this->materialsChangeSet->stagedRemoval(MaterialKind::Font, $font->getMachineName()),
    ], 202);
  }

  /**
   * A 404 in the standard error shape.
   */
  private function notFound(string $reference): JsonResponse {
    return $this->singleError('font', 'STYLE_FONT_NOT_FOUND', sprintf('"%s" is not a font.', $reference), 'Reference a font by its machine name or uuid.', 404);
  }

}
