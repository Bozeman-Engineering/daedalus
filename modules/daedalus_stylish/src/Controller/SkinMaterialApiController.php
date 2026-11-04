<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Controller;

use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\Exception\SkinMaterialValidationException;
use Drupal\daedalus_stylish\FontFiles;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\SkinMaterialManager;
use Drupal\daedalus_stylish\SkinStylesheet;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_ui\File\FileLaneException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The skin material lifecycle endpoints: tokens, animations and fonts.
 *
 * The variant API's twin for the materials that live on the skin, under
 * /daedalus_stylish/skin/{section}/…: the per-material management read
 * (usage), rename, revert and delete, each route naming its section in
 * its defaults (tokens address "{scale}/{name}" as two path parameters,
 * animations and fonts "{name}"). Minting and the value, steps, faces,
 * description and offered editors are not routes: the skin tab submits
 * save_material / update_material (kind token, animation, font) through
 * the page batch, the same ops the AI submits. The tab manages the rest of
 * the vocabulary from wherever it is open, with no page batch to ride, so
 * those gestures speak to the skin material manager through these routes
 * and receive the same materials delta the op lane answers with (the skin
 * save's entity hook records every material id it carried); a write's
 * response is that delta beside the route's own facts (deleted, renamed,
 * references, batch_url), never an echo of the material.
 *
 * The fonts section adds what the ops cannot carry: BYTES. A face's file
 * uploads here first (upload(): the font store over the daedalus_ui file
 * lane, woff2 and woff, permanent) and the mint names the uuid the
 * response answered; and the delivered @font-face text is read here
 * (fontStylesheet()) for the canvas to stamp after a font write (the
 * compiled skin file the page loaded predates it, and a face's url() is
 * the server's to print, so no client twin prints a face).
 *
 * Every route addresses the ACTIVE skin's material (a skin material is
 * never skin-qualified). Delete is an adapter over
 * DaedalusStylishMaterialsProvider::remove() like the variant delete;
 * rename and revert are the manager's own gestures, since a rename may
 * stage a batch the op spine cannot drive.
 */
final class SkinMaterialApiController extends GestureApiControllerBase {

  /**
   * {@inheritdoc}
   */
  protected const PAYLOAD_HINT = 'Send {label} as JSON.';

  public function __construct(
    private readonly SkinMaterialManager $manager,
    private readonly DaedalusStylishMaterialsProvider $provider,
    private readonly MaterialsRosterBuilder $rosterBuilder,
    private readonly FontFiles $fontFiles,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly SkinStylesheet $skinStylesheet,
    MaterialsChangeSet $materialsChangeSet,
  ) {
    parent::__construct($materialsChangeSet);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_stylish.skin_material_manager'),
      $container->get('daedalus_stylish.materials_provider'),
      $container->get('daedalus_blueprint.materials_roster_builder'),
      $container->get('daedalus_stylish.font_files'),
      $container->get('file_url_generator'),
      $container->get('daedalus_stylish.skin_stylesheet'),
      $container->get('daedalus_blueprint.materials_change_set'),
    );
  }

  /**
   * POST /daedalus_stylish/skin/fonts/files: saves one uploaded font file.
   *
   * Multipart, field "file". The bytes door of the fonts section: the
   * mint (save_material, kind font) names the uuid this answers.
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
   * GET /daedalus_stylish/skin/fonts/stylesheet: the delivered @font-face text.
   *
   * The canvas's landing after a font write (leader-source.js): the
   * compiled skin file this page loaded predates any font minted, edited
   * or deleted this session, so the leader re-reads the active skin's
   * whole @font-face print (SkinStylesheet::fontFacesCss(), the same text
   * the file opens with) and stamps it on the page.
   */
  public function fontStylesheet(): JsonResponse {
    return new JsonResponse(['css' => $this->skinStylesheet->fontFacesCss()]);
  }

  /**
   * GET /daedalus_stylish/skin/{section}/…/usage: the management read.
   *
   * The card's loud number: the count drifts mid-session, so the prompts
   * that speak consequences (rename, delete) fetch it at gesture time.
   * Beside it the management facts a card needs beyond the roster record:
   * which layer(s) hold the material (package, site or both), whether the
   * package layer is locked on this site (the identity gestures' gate:
   * rename and delete of a package material refuse while it holds;
   * SkinMaterialManager::writesSiteLayer()), the skin's provider and the
   * material's shipped state against the disk file right now (the revert
   * affordance's gate). None rides the roster record (a record says where
   * a material came from, never what a module's disk copy says today, and
   * a skin material record is always updatable: the site layer takes the
   * write).
   */
  public function usage(string $section, string $name, ?string $scale = NULL): JsonResponse {
    $address = $this->address($section, $name, $scale);
    if ($address instanceof JsonResponse) {
      return $address;
    }
    [$skin, $id] = $address;
    if ($section === SkinMaterialManager::ANIMATIONS) {
      return new JsonResponse([
        'id' => $id,
        'name' => $name,
        'keyframes' => StyleGuide::animationName($name),
        'usages' => $this->manager->animationUsages($name),
        'layer' => $this->manager->animationLayer($skin, $name),
        'locked' => $this->manager->writesSiteLayer($skin),
        'provider' => $skin->getProvider(),
        'shipped_state' => $this->manager->animationShippedState($skin, $name),
      ]);
    }
    if ($section === SkinMaterialManager::FONTS) {
      $font = $this->manager->resolveFont($skin, $name);
      assert($font !== NULL);
      return new JsonResponse([
        'id' => $id,
        'name' => $name,
        'family' => $font->family(),
        'spelling' => $font->spelling,
        'usages' => $this->manager->fontUsages($skin, $name),
        'layer' => $this->manager->fontLayer($skin, $name),
        'locked' => $this->manager->writesSiteLayer($skin),
        'provider' => $skin->getProvider(),
        'shipped_state' => $this->manager->fontShippedState($skin, $name),
      ]);
    }
    return new JsonResponse([
      'id' => $id,
      'scale' => $scale,
      'name' => $name,
      'variable' => $this->manager->variable($scale, $name),
      'usages' => $this->manager->usages($scale, $name),
      'layer' => $this->manager->layer($skin, $scale, $name),
      'locked' => $this->manager->writesSiteLayer($skin),
      'provider' => $skin->getProvider(),
      'shipped_state' => $this->manager->shippedState($skin, $scale, $name),
    ]);
  }

  /**
   * POST /daedalus_stylish/skin/{section}/…/rename: the name gesture.
   *
   * Body: {label}, the new name. The name IS the reference's tail (a
   * token's custom property, an animation's keyframes name), so an
   * unreferenced material moves in this request (200, the delta removing
   * the old id and upserting the new) and a REFERENCED one stages the
   * rewrite-then-rename Batch API job (SkinMaterialManager::rename() /
   * renameAnimation()) and answers 202 with the batch URL for the client
   * to drive: every reference is respelled, then the record moves. The
   * response states the outcome the staged batch is committed to (the old
   * id's removal and the renamed record), and the client applies it once
   * it has driven the batch to the end. A package material refuses on a
   * consuming site. A font's name is its roster id alone (a stack names
   * the family), so its rename is always immediate.
   */
  public function rename(string $section, string $name, Request $request, ?string $scale = NULL): JsonResponse {
    $address = $this->address($section, $name, $scale);
    if ($address instanceof JsonResponse) {
      return $address;
    }
    [$skin, $id] = $address;
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->badRequest();
    }
    $label = is_string($payload['label'] ?? NULL) ? $payload['label'] : '';
    if ($section === SkinMaterialManager::FONTS) {
      try {
        $this->manager->renameFont($skin, $name, $label);
      }
      catch (SkinMaterialValidationException $exception) {
        return $this->unprocessable($exception);
      }
      return $this->mutated(['renamed' => TRUE, 'id' => trim($label), 'references' => 0]);
    }
    $animations = $section === SkinMaterialManager::ANIMATIONS;
    try {
      $references = $animations
        ? $this->manager->renameAnimation($skin, $name, $label)
        : $this->manager->rename($skin, $scale, $name, $label);
    }
    catch (SkinMaterialValidationException $exception) {
      return $this->unprocessable($exception);
    }
    $new_id = $animations ? trim($label) : $scale . '/' . trim($label);
    if ($references === 0) {
      return $this->mutated(['renamed' => TRUE, 'id' => $new_id, 'references' => 0]);
    }
    $batch_response = batch_process();
    $kind = $animations ? MaterialKind::Animation : MaterialKind::Token;
    $record = $this->rosterBuilder->build()->get($kind, $id);
    assert($record !== NULL);
    $renamed = $animations
      ? $this->manager->renamedAnimationRecord($record, trim($label))
      : $this->manager->renamedRecord($record, trim($label));
    return $this->mutated([
      'renamed' => FALSE,
      'id' => $new_id,
      'references' => $references,
      'batch_url' => $batch_response->getTargetUrl(),
      'materials' => array_merge_recursive(
        $this->materialsChangeSet->stagedRemoval($kind, $id),
        $this->materialsChangeSet->stagedUpsert($renamed),
      ),
    ], 202);
  }

  /**
   * POST /daedalus_stylish/skin/{section}/…/revert: back to shipped.
   *
   * An ordinary revisioned save converging the material toward what the
   * skin's provider ships on disk right now (the site's record over it
   * dropped; the package record rewritten under the developer gate). No
   * body; the disk is the payload. The reverted record rides the delta.
   */
  public function revert(string $section, string $name, ?string $scale = NULL): JsonResponse {
    $address = $this->address($section, $name, $scale);
    if ($address instanceof JsonResponse) {
      return $address;
    }
    [$skin] = $address;
    try {
      if ($section === SkinMaterialManager::ANIMATIONS) {
        $this->manager->revertAnimation($skin, $name);
      }
      elseif ($section === SkinMaterialManager::FONTS) {
        $this->manager->revertFont($skin, $name);
      }
      else {
        $this->manager->revert($skin, $scale, $name);
      }
    }
    catch (SkinMaterialValidationException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated([]);
  }

  /**
   * DELETE /daedalus_stylish/skin/{section}/…: delete, always safely.
   *
   * The site's record goes in this request when the material stays minted
   * (a site override dropped reveals the package's record: the unset
   * gesture) or nothing references it. A REFERENCED material whose removal
   * would delete it stages the rewrite-then-delete Batch API job
   * (SkinMaterialDeleteBatch) and responds 202 with the batch URL for the
   * client to drive: every var() of a token becomes its literal value,
   * every keyframes name of an animation becomes none, every stack naming
   * a font's family loses it, then the material goes. There is no "delete
   * anyway". The two permission grades: the route admits both, and a
   * referenced material without the in-use grade comes back 403 with the
   * *_IN_USE corrective. A package material the site never touched
   * refuses either way (the distro lock).
   */
  public function delete(string $section, string $name, ?string $scale = NULL): JsonResponse {
    $address = $this->address($section, $name, $scale);
    if ($address instanceof JsonResponse) {
      return $address;
    }
    [$skin, $id] = $address;
    $kind = match ($section) {
      SkinMaterialManager::ANIMATIONS => MaterialKind::Animation,
      SkinMaterialManager::FONTS => MaterialKind::Font,
      default => MaterialKind::Token,
    };
    try {
      $result = $this->provider->remove($kind, $id, [MaterialGestureOperationHandler::BATCH_OPTION => TRUE]);
    }
    catch (MaterialGestureException $exception) {
      $codes = array_column($exception->getErrorsAsArrays(), 'code');
      $in_use = array_intersect([
        'STYLE_TOKEN_IN_USE',
        'STYLE_ANIMATION_IN_USE',
        'STYLE_FONT_IN_USE',
      ], $codes);
      return $this->unprocessable($exception, $in_use !== [] ? 403 : 422);
    }
    if ($result->staged === 0) {
      return $this->mutated([
        'deleted' => match ($kind) {
          MaterialKind::Animation => !$this->manager->hasAnimation($skin, $name),
          MaterialKind::Font => !$this->manager->hasFont($skin, $name),
          default => !$this->manager->has($skin, $scale, $name),
        },
        'references' => 0,
      ]);
    }
    $batch_response = batch_process();
    // The material goes in the batch's last request, so this response
    // cannot carry the removal as a flushed delta (the roster still holds
    // it); it states the outcome the staged batch is committed to, and
    // the client applies it once it has driven the batch to the end.
    return $this->mutated([
      'deleted' => FALSE,
      'references' => $result->staged,
      'batch_url' => $batch_response->getTargetUrl(),
      'materials' => $this->materialsChangeSet->stagedRemoval($kind, $id),
    ], 202);
  }

  /**
   * Resolves a route's section and path to the active skin's material.
   *
   * @return array{0: \Drupal\daedalus_stylish\DaedalusStylishSkinInterface, 1: string}|\Symfony\Component\HttpFoundation\JsonResponse
   *   The skin and the material's roster id, or the error response: 422
   *   when no skin is active, 404 when the active skin mints no such
   *   material.
   */
  private function address(string $section, string $name, ?string $scale): array|JsonResponse {
    try {
      $skin = $this->manager->activeSkin();
    }
    catch (SkinMaterialValidationException $exception) {
      return $this->unprocessable($exception);
    }
    assert($skin instanceof DaedalusStylishSkinInterface);
    if ($section === SkinMaterialManager::ANIMATIONS) {
      if (!$this->manager->hasAnimation($skin, $name)) {
        return $this->singleError('id', 'STYLE_ANIMATION_NOT_FOUND', sprintf('"%s" is not an animation of the active skin.', $name), 'Address an animation by its name, as /skin/animations/{name}.', 404);
      }
      return [$skin, $name];
    }
    if ($section === SkinMaterialManager::FONTS) {
      if (!$this->manager->hasFont($skin, $name)) {
        return $this->singleError('id', 'STYLE_FONT_NOT_FOUND', sprintf('"%s" is not a font of the active skin.', $name), 'Address a font by its name, as /skin/fonts/{name}.', 404);
      }
      return [$skin, $name];
    }
    if ($scale === NULL || !$this->manager->has($skin, $scale, $name)) {
      return $this->singleError('id', 'STYLE_TOKEN_NOT_FOUND', sprintf('"%s/%s" is not a token of the active skin.', (string) $scale, $name), 'Address a token by its roster id, "{scale}/{name}", as /skin/tokens/{scale}/{name}.', 404);
    }
    return [$skin, "$scale/$name"];
  }

}
