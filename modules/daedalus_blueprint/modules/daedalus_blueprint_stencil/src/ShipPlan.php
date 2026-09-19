<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

use Drupal\file\FileInterface;

/**
 * What a stencil ship would do, or did: the report both runs print.
 *
 * StencilShipper::plan() builds one without touching disk or entities (the
 * dry run) and ship() executes exactly the same plan, so the lines a dry
 * run prints are the lines the real run prints. Every item carries an
 * outcome in the sync vocabulary (created / updated / unchanged), the icon
 * its own (copied / kept / none), the variants a name the ship leaves out
 * of the per-id file it rewrites drop, variants the ship cannot take along
 * are listed as skipped with the reason, and everything said out loud
 * (unowned variants shipping unowned, dangling references, a missing
 * info.yml dependency, an icon media without a file) rides the warnings.
 */
final class ShipPlan {

  /**
   * Constructs a ShipPlan.
   *
   * @param string $module
   *   The target module.
   * @param string $id
   *   The stencil id: the file's name suffix and the variants' owner.
   * @param array $definition
   *   The stencil file's content (label, description, element, and the
   *   icon key when the icon outcome is not none).
   * @param string $stencil
   *   The stencil file's outcome: StencilShipper::CREATED, UPDATED, or
   *   UNCHANGED.
   * @param string $icon
   *   The icon's outcome: StencilShipper::ICON_COPIED (the graduating
   *   stencil's media file lands at the definition's icon key), ICON_KEPT
   *   (the existing file's key survives), or ICON_NONE.
   * @param \Drupal\file\FileInterface|null $iconSource
   *   The file the ship copies into the module, for ICON_COPIED only.
   * @param \Drupal\daedalus_blueprint_stencil\StencilInterface|null $graduating
   *   The content stencil that graduates into the file (deleted by the
   *   ship, its owned variants adopted in place), or NULL when no content
   *   stencil carries the id.
   * @param array $variants
   *   The variant definitions the ship writes, keyed by machine name, in
   *   the variants disk lane's grammar (owned ones carry `stencil: ID`).
   * @param array $variantOutcomes
   *   The variant shipper's outcome per machine name: created, updated,
   *   unchanged, or dropped (a name the per-id file held that this ship
   *   leaves out).
   * @param array $skipped
   *   Variants the ship leaves alone, keyed by machine name; the value says
   *   why (shipped by another module or file, owned by another stencil).
   * @param string[] $warnings
   *   Everything said out loud: capture warnings, unowned variants shipping
   *   unowned, dangling references, and a missing info.yml dependency.
   */
  public function __construct(
    public readonly string $module,
    public readonly string $id,
    public readonly array $definition,
    public readonly string $stencil,
    public readonly string $icon,
    public readonly ?FileInterface $iconSource,
    public readonly ?StencilInterface $graduating,
    public readonly array $variants,
    public readonly array $variantOutcomes,
    public readonly array $skipped,
    public readonly array $warnings,
  ) {}

  /**
   * Whether the ship deletes a content stencil (the confirmation gate).
   */
  public function graduates(): bool {
    return $this->graduating !== NULL;
  }

}
