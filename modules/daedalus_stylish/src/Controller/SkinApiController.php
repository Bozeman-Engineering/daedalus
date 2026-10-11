<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Controller;

use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Drupal\daedalus_stylish\Exception\SkinValidationException;
use Drupal\daedalus_stylish\SkinManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The skin lifecycle endpoints.
 *
 * The skin card's gestures with no page batch to ride: choose (which
 * member skin the active design system wears on this site), copy any
 * skin on the site into the active system as the site's own skin, the
 * revision history and restore, and the site layer's revert. Adapters
 * over SkinManager, which owns the gestures, answering the same
 * materials delta the op lane does (a choice swaps the active skin's
 * tokens, and DefaultsSettingsSubscriber records both skins' token ids
 * on the request's change set; a restore or revert of the worn skin is
 * a skin save, whose entity hook records every token id it carried; a
 * copy a site's own unit takes as its first skin is a unit save, whose
 * entity hook records the copy's), so the client store learns the
 * change from the route lane exactly as from a design-system switch.
 * Beside them the card's read (skins(): the active system's members and
 * what may be worn, and every skin on the site as a copy's source) and
 * the one read the skin session lands through: the active skin's
 * defaults and their compiled CSS (defaults()).
 *
 * A skin is addressed by its machine name, the stable identity the
 * skins lane syncs by; every route speaks the ACTIVE design system.
 */
final class SkinApiController extends GestureApiControllerBase {

  /**
   * {@inheritdoc}
   */
  protected const PAYLOAD_HINT = 'Send {label} as JSON, or an empty object.';

  public function __construct(
    private readonly SkinManager $skinManager,
    private readonly DefaultsStylesheet $defaultsStylesheet,
    MaterialsChangeSet $materialsChangeSet,
  ) {
    parent::__construct($materialsChangeSet);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_stylish.skin_manager'),
      $container->get('daedalus_stylish.defaults_stylesheet'),
      $container->get('daedalus_blueprint.materials_change_set'),
    );
  }

  /**
   * GET /daedalus_stylish/skins: the skin card's read.
   *
   * The active design system, the skin it wears, the site's choice and
   * the unit's default, and every member skin with the facts the card's
   * gestures key on (SkinManager::card()). A live read, fetched on open
   * and after every gesture: which skin is worn changes with a choice and
   * a copy adds a member, so none of it rides the guide payload.
   */
  public function skins(): JsonResponse {
    return new JsonResponse($this->skinManager->card());
  }

  /**
   * GET /daedalus_stylish/defaults: the active skin's delivered defaults.
   *
   * The skin session's landing read. `defaults` is the ACTIVE published
   * skin's defaults as stored, the merge of both layers, exactly the
   * guide payload's skinDefaults (ClientStyleGuide: the inspector's
   * provenance source, which a push of update_default ops rewrites);
   * `css` is the defaults stylesheet's current text (DefaultsStylesheet::
   * css(): the declared tier, the skin's tokens and its defaults, what
   * the compiled file holds, or inside a workspace the staged revision's
   * print the page inlines), which the client stamps on the canvas so
   * every element of the pushed scope restyles before any reload. `skin`
   * is the delivering skin's machine name, NULL when no published skin
   * is active (defaults then serve empty and the CSS the declared tier
   * alone).
   */
  public function defaults(): JsonResponse {
    $skin = $this->skinManager->loadActiveSkin();
    $delivering = $skin !== NULL && $skin->isPublished();
    return new JsonResponse([
      'skin' => $delivering ? $skin->getMachineName() : NULL,
      'defaults' => $delivering ? array_filter($skin->defaults(), 'is_array') : [],
      'css' => $this->defaultsStylesheet->css(),
    ]);
  }

  /**
   * POST /daedalus_stylish/skins/{reference}/choose: wear this skin.
   *
   * No body; the reference is the skin's machine name. Records the
   * site's choice for the active design system
   * (daedalus_stylish.settings skin_choice) and answers the system it
   * was recorded for beside the delta. 404 for a machine name that is
   * no skin, 422 with the refusal for a skin the system may not wear
   * (not a member, unpublished, nonconforming) and with no active
   * system.
   */
  public function choose(string $reference): JsonResponse {
    try {
      $system = $this->skinManager->choose($reference);
    }
    catch (SkinValidationException $exception) {
      $codes = array_column($exception->getErrorsAsArrays(), 'code');
      return $this->unprocessable($exception, in_array('STYLE_SKIN_NOT_FOUND', $codes, TRUE) ? 404 : 422);
    }
    return $this->mutated([
      'chosen' => $reference,
      'design_system' => $system,
    ]);
  }

  /**
   * POST /daedalus_stylish/skins/{reference}/copy: copy into the active system.
   *
   * The reference is ANY skin on the site (the card's `sources`: a
   * member of the active system, another system's member, a global
   * skin). Body: {label?}, the copy's label ('<source label> (copy)'
   * when absent or empty). A CREATE, never a write to the source: a new
   * skin with no provider, the source's merged look as its one layer, a
   * member of the ACTIVE design system whatever the source's membership,
   * published (SkinManager::copyAsMySkin()). Answers the copy's machine
   * name and label, and `worn`: TRUE when the copy is worn now, which is
   * the first-skin door (the active unit had no package and no
   * default_skin, so the copy became its default_skin, the defaults file
   * recompiled and the delta carries the copy's token ids, as a choice's
   * does); FALSE when the copy wears nothing (a packaged unit, or one
   * that already names a default: an empty delta, and the card chooses
   * it next). 404 for a machine name that is no skin, 422 with no active
   * system (there is nothing to land the copy in).
   */
  public function copy(string $reference, Request $request): JsonResponse {
    $skin = $this->address($reference);
    if ($skin instanceof JsonResponse) {
      return $skin;
    }
    $payload = $this->decode($request);
    if ($payload === NULL) {
      return $this->badRequest();
    }
    $label = is_string($payload['label'] ?? NULL) ? $payload['label'] : NULL;
    try {
      $copy = $this->skinManager->copyAsMySkin($skin, $label);
    }
    catch (SkinValidationException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated([
      'copied' => $copy->getMachineName(),
      'label' => (string) $copy->label(),
      'worn' => $this->skinManager->activeSkinName() === $copy->getMachineName(),
    ]);
  }

  /**
   * GET /daedalus_stylish/skins/{reference}/revisions: the restore menu.
   */
  public function revisions(string $reference): JsonResponse {
    $skin = $this->address($reference);
    if ($skin instanceof JsonResponse) {
      return $skin;
    }
    return new JsonResponse([
      'machine_name' => $skin->getMachineName(),
      'label' => (string) $skin->label(),
      'locked' => $this->skinManager->writesSiteLayer($skin),
      'revisions' => $this->skinManager->revisionsList($skin),
    ]);
  }

  /**
   * POST /daedalus_stylish/skins/{reference}/revisions/{revision_id}/restore.
   *
   * An update gesture: a NEW default revision carrying the old one's
   * content, never a rollback, so a restore is itself one restore away
   * from undo. On a consuming site the old revision's SITE layer comes
   * back over the package layer as synced; elsewhere the revision whole
   * (SkinManager::restoreRevision()). No body. The restored tokens ride
   * the delta when the skin is the one worn.
   */
  public function restore(string $reference, string|int $revision_id): JsonResponse {
    $skin = $this->address($reference);
    if ($skin instanceof JsonResponse) {
      return $skin;
    }
    try {
      $this->skinManager->restoreRevision($skin, (int) $revision_id);
    }
    catch (SkinValidationException $exception) {
      return $this->unprocessable($exception, 404);
    }
    return $this->mutated(['restored' => (int) $revision_id]);
  }

  /**
   * POST /daedalus_stylish/skins/{reference}/revert: drop the site layer.
   *
   * One revisioned save emptying the site's tokens and defaults over the
   * package's, so the skin wears what its module ships, whole
   * (SkinManager::revertSiteLayer()). No body. 422 STYLE_SKIN_NOT_SHIPPED
   * for a skin with no provider. `reverted` is FALSE when the site layer
   * was already empty (nothing saved, an empty delta).
   */
  public function revert(string $reference): JsonResponse {
    $skin = $this->address($reference);
    if ($skin instanceof JsonResponse) {
      return $skin;
    }
    try {
      $reverted = $this->skinManager->revertSiteLayer($skin);
    }
    catch (SkinValidationException $exception) {
      return $this->unprocessable($exception);
    }
    return $this->mutated(['reverted' => $reverted]);
  }

  /**
   * Resolves a skin reference to the skin, or the 404 response.
   */
  private function address(string $reference): DaedalusStylishSkinInterface|JsonResponse {
    return $this->skinManager->loadByMachineName($reference)
      ?? $this->singleError('id', 'STYLE_SKIN_NOT_FOUND', sprintf('"%s" is not a skin on this site.', $reference), 'Name a skin by its machine name.', 404);
  }

}
