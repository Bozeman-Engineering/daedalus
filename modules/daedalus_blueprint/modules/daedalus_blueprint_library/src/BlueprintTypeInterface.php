<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityDescriptionInterface;
use Drupal\Core\Entity\RevisionableEntityBundleInterface;

/**
 * A blueprint type: the bundle axis of the blueprint entity.
 *
 * A type says who may work on the blueprints of that kind, and where
 * they are placed: every permission over a blueprint is per type
 * (BlueprintPermissions), the library ships `section` (the compositions
 * editors save from a page and place from the Add panel) and
 * `site_chrome` (headers and footers, the site frame's material, the site
 * builder's), and a site adds a type by config. A frame-only type
 * (isFrameOnly(): `site_chrome` is, `section` is not) keeps its
 * blueprints on the site frame: the fit judgement
 * (BlueprintRequirements::FRAME) refuses them on a page or a template, so
 * the Add panel, the AI's roster and every placement door leave the
 * chrome out there, and a page takes the frame's default as its own only
 * through a slot claim. The document, the canvas, the vocabulary (scope,
 * subject, groups, favorite, membership) and the gestures are the same
 * whatever the type; a type is a permission axis, a roster grouping and
 * a placement rule, never a second kind of material.
 *
 * A type is always a page-builder bundle (a blueprint is a page of its
 * own, with a document), so it answers daedalus_ui's bundle gate as on by
 * construction, and every save of a blueprint is a new revision, so the
 * revision policy the spine's storage handler asks of a bundle is TRUE.
 */
interface BlueprintTypeInterface extends ConfigEntityInterface, EntityDescriptionInterface, RevisionableEntityBundleInterface {

  /**
   * The entity type id.
   */
  public const ENTITY_TYPE_ID = 'blueprint_type';

  /**
   * Whether the type's blueprints are the site frame's material.
   *
   * TRUE for the site chrome: its blueprints fit the site frame alone
   * (BlueprintRequirements::FRAME), so no page or template canvas offers
   * or places them, linked or detached; a page reaches one through a slot
   * claim. FALSE (the default, and what an absent config key reads as)
   * for a type whose blueprints are placed into pages.
   */
  public function isFrameOnly(): bool;

}
