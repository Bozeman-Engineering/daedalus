<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Session\AccountProxyInterface;

/**
 * The inspector's gesture gates: which controls the panel renders.
 *
 * Honest chrome, not access control (enforcement is the routes'
 * permissions): the one place the in-page host (InspectorSidebar) and the
 * satellite window (InspectorPopout) read the current user's variant,
 * swatch, token, animation and skin grants from, so both windows render
 * the same controls. The ownership grant is the blueprint side's: may the
 * actor edit blueprints of some type, the any-type gate
 * daedalus_blueprint_library's ownership routes require
 * (`daedalus_blueprint_library.type_access`, taken optionally). A
 * variant's owner is blueprint vocabulary, read here through that one
 * gate, never a module dependency, and absent without the module.
 */
final class InspectorGates {

  /**
   * The library's any-type gate, or NULL without daedalus_blueprint_library.
   *
   * Untyped on purpose: this module does not depend on the library.
   *
   * @var \Drupal\daedalus_blueprint_library\Access\BlueprintTypeAccess|null
   */
  private readonly ?object $blueprintTypeAccess;

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    ?object $blueprint_type_access = NULL,
  ) {
    $this->blueprintTypeAccess = $blueprint_type_access;
  }

  /**
   * The variant gesture gates (drupalSettings.daedalusStylish.variants).
   *
   * @return array{canCreate: bool, canUpdate: bool, canDelete: bool, canDeleteInUse: bool, canOwn: bool}
   *   The gate flags.
   */
  public function variants(): array {
    $administer = $this->currentUser->hasPermission('administer variants');
    return [
      'canCreate' => $administer || $this->currentUser->hasPermission('create variants'),
      'canUpdate' => $administer || $this->currentUser->hasPermission('update variants'),
      'canDelete' => $administer || $this->currentUser->hasPermission('delete variants'),
      'canDeleteInUse' => $administer || $this->currentUser->hasPermission('delete variants in use'),
      'canOwn' => $this->blueprintTypeAccess?->anyType($this->currentUser, 'update')->isAllowed() ?? FALSE,
    ];
  }

  /**
   * The swatch gesture gates (drupalSettings.daedalusStylish.swatches).
   *
   * On strict style-guide sites withholding "create swatches" IS the
   * vocabulary-extension gate; enforcement stays on the routes.
   *
   * @return array{canCreate: bool, canUpdate: bool, canDelete: bool, canDeleteInUse: bool}
   *   The gate flags.
   */
  public function swatches(): array {
    $administer = $this->currentUser->hasPermission('administer swatches');
    return [
      'canCreate' => $administer || $this->currentUser->hasPermission('create swatches'),
      'canUpdate' => $administer || $this->currentUser->hasPermission('update swatches'),
      'canDelete' => $administer || $this->currentUser->hasPermission('delete swatches'),
      'canDeleteInUse' => $administer || $this->currentUser->hasPermission('delete swatches in use'),
    ];
  }

  /**
   * The token gesture gates (drupalSettings.daedalusStylish.tokens).
   *
   * The swatch gates' twin for the material that lives on the skin: the
   * administer grade is the skin's ("administer skins" grants every token
   * gesture). On a consuming site "update tokens" IS the override grade
   * (a write lands in the skin's site layer over the package's record),
   * so a tenant that may not change shipped values is one without it;
   * "create tokens" is the extension gate; enforcement stays on the op
   * spine and the routes.
   *
   * @return array{canCreate: bool, canUpdate: bool, canDelete: bool, canDeleteInUse: bool}
   *   The gate flags.
   */
  public function tokens(): array {
    $administer = $this->currentUser->hasPermission('administer skins');
    return [
      'canCreate' => $administer || $this->currentUser->hasPermission('create tokens'),
      'canUpdate' => $administer || $this->currentUser->hasPermission('update tokens'),
      'canDelete' => $administer || $this->currentUser->hasPermission('delete tokens'),
      'canDeleteInUse' => $administer || $this->currentUser->hasPermission('delete tokens in use'),
    ];
  }

  /**
   * The animation gesture gates (drupalSettings.daedalusStylish.animations).
   *
   * The swatch gates' twin at structure scope: withholding "create
   * animations" IS the motion-vocabulary extension gate on strict sites;
   * enforcement stays on the routes and the provider.
   *
   * @return array{canCreate: bool, canUpdate: bool, canDelete: bool, canDeleteInUse: bool}
   *   The gate flags.
   */
  public function animations(): array {
    $administer = $this->currentUser->hasPermission('administer animations');
    return [
      'canCreate' => $administer || $this->currentUser->hasPermission('create animations'),
      'canUpdate' => $administer || $this->currentUser->hasPermission('update animations'),
      'canDelete' => $administer || $this->currentUser->hasPermission('delete animations'),
      'canDeleteInUse' => $administer || $this->currentUser->hasPermission('delete animations in use'),
    ];
  }

  /**
   * The font gesture gates (drupalSettings.daedalusStylish.fonts).
   *
   * The Fonts tab's mint-from-files, edit and delete controls; "create
   * fonts" IS the type-vocabulary extension gate on strict sites;
   * enforcement stays on the routes and the provider.
   *
   * @return array{canCreate: bool, canUpdate: bool, canDelete: bool, canDeleteInUse: bool}
   *   The gate flags.
   */
  public function fonts(): array {
    $administer = $this->currentUser->hasPermission('administer fonts');
    return [
      'canCreate' => $administer || $this->currentUser->hasPermission('create fonts'),
      'canUpdate' => $administer || $this->currentUser->hasPermission('update fonts'),
      'canDelete' => $administer || $this->currentUser->hasPermission('delete fonts'),
      'canDeleteInUse' => $administer || $this->currentUser->hasPermission('delete fonts in use'),
    ];
  }

  /**
   * The skin gesture gates (drupalSettings.daedalusStylish.skin).
   *
   * The skin session's pending lane: without "edit skin defaults" (or
   * the skin's administer grade) the session frame mounts read-only,
   * as a variant's does without "update variants"; the update_default
   * op enforces. And the skin card's lifecycle: choose, copy, restore
   * and revert are the administer grade's alone (each restyles the whole
   * site), so the card offers them under it and reads only otherwise;
   * the routes enforce.
   *
   * @return array{canEditDefaults: bool, canAdminister: bool}
   *   The gate flags.
   */
  public function skin(): array {
    $administer = $this->currentUser->hasPermission('administer skins');
    return [
      'canEditDefaults' => $administer || $this->currentUser->hasPermission('edit skin defaults'),
      'canAdminister' => $administer,
    ];
  }

}
