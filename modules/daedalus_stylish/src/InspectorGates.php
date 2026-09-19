<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * The inspector's gesture gates: which controls the panel renders.
 *
 * Honest chrome, not access control (enforcement is the routes'
 * permissions): the one place the in-page host (InspectorSidebar) and the
 * satellite window (InspectorPopout) read the current user's variant,
 * swatch and animation grants from, so both windows render the same
 * controls. The
 * ownership grant is the stencil side's ("save stencils", the permission
 * daedalus_blueprint_stencil's ownership routes require): a variant's owner
 * is stencil vocabulary, read here as a permission fact, never a module
 * dependency, and absent without the module.
 */
final class InspectorGates {

  public function __construct(
    private readonly AccountProxyInterface $currentUser,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

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
      'canOwn' => $this->moduleHandler->moduleExists('daedalus_blueprint_stencil') && $this->currentUser->hasPermission('save stencils'),
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

}
