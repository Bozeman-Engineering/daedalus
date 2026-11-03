<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;

/**
 * The inspector's gesture gates: which controls the panel renders.
 *
 * Honest chrome, not access control (enforcement is the routes'
 * permissions): the one place the in-page host (InspectorSidebar) and the
 * satellite window (InspectorPopout) read the current user's variant,
 * skin material, skin and law grants from, and the AI context
 * door (the usage display's URL for a user who may read it), so both
 * windows render the same controls. The ownership grant is the blueprint
 * side's: may the actor edit blueprints of some type, the any-type gate
 * daedalus_blueprint_library's ownership routes require
 * (`daedalus_blueprint_library.type_access`, taken optionally). A
 * variant's owner is blueprint vocabulary, read here through that one
 * gate, never a module dependency, and absent without the module.
 */
final class InspectorGates {

  /**
   * The permission behind the Law tab and its doorways (law()).
   */
  public const LAW_PERMISSION = 'view style law';

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
   * The skin material gesture gates: one grant family, one client key.
   *
   * The variant gates' twin for the materials that live on the skin, its
   * tokens, animations and fonts: one permission family ("create / update
   * / delete skin materials", "delete skin materials in use") beside the
   * skin's administer grade, which grants every gesture. The client reads
   * the family under drupalSettings.daedalusStylish.skinMaterials (the
   * Skin tab's "+ New" and cards, the wells' save-as-token). On strict
   * style-guide sites withholding the create grade IS the
   * vocabulary-extension gate; on a consuming site the update
   * grade IS the override grade (a write lands in the skin's site layer
   * over the package's record); enforcement stays on the op spine and the
   * routes.
   *
   * @return array{canCreate: bool, canUpdate: bool, canDelete: bool, canDeleteInUse: bool}
   *   The gate flags.
   */
  public function skinMaterials(): array {
    $administer = $this->currentUser->hasPermission('administer skins');
    return [
      'canCreate' => $administer || $this->currentUser->hasPermission('create skin materials'),
      'canUpdate' => $administer || $this->currentUser->hasPermission('update skin materials'),
      'canDelete' => $administer || $this->currentUser->hasPermission('delete skin materials'),
      'canDeleteInUse' => $administer || $this->currentUser->hasPermission('delete skin materials in use'),
    ];
  }

  /**
   * The law gate (drupalSettings.daedalusStylish.law).
   *
   * Whether the Design system place offers its Law tab (the style guide
   * read-only, law.js over the payload's law projection) and every road
   * to it: the "See the declaration" doorway on a provenance row and the
   * code view's declared run, the law document openDocument routes to
   * the tab. One permission, "view style law", granted to no role by
   * default; an administrator role holds it as it holds every
   * permission, which is accepted. Honest chrome like the rest: the law
   * projection still rides the guide payload (the stack's declared tier
   * reads it), the gate only closes the tab and its doorways.
   *
   * @return array{canView: bool}
   *   The gate flag.
   */
  public function law(): array {
    return [
      'canView' => $this->currentUser->hasPermission(self::LAW_PERMISSION),
    ];
  }

  /**
   * The AI context door (drupalSettings.daedalusStylish.aiContext).
   *
   * The "Offer to the AI" toggle on every material card and mint
   * (ai-offer.js) carries a link to daedalus_blueprint's usage display,
   * the bar of what the site shares with the AI against the budget, for
   * a user holding MaterialsBudget::PERMISSION; the display's route
   * enforces for real. The URL rides by route name, never a path the
   * client spells. NULL without the permission, and the link is absent.
   *
   * @return array{url: string}|null
   *   The dialog route's URL, or NULL.
   */
  public function aiContext(): ?array {
    if (!$this->currentUser->hasPermission(MaterialsBudget::PERMISSION)) {
      return NULL;
    }
    return [
      'url' => Url::fromRoute('daedalus_blueprint.ai_context')->toString(TRUE)->getGeneratedUrl(),
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
