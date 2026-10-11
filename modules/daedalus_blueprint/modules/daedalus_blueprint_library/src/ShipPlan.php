<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\file\FileInterface;

/**
 * What a blueprint ship would do, or did: the report both runs print.
 *
 * BlueprintShipper::plan() builds one without touching disk or entities
 * (the dry run) and ship() executes exactly the same plan, so the lines a
 * dry run prints are the lines the real run prints. The blueprint file is
 * always created (a content blueprint never shares its id with a file), the
 * icon carries its own outcome (copied / none), the variants the disk
 * lane's vocabulary (created / updated / unchanged / dropped), variants the
 * ship cannot take along are listed as skipped with the reason, and
 * everything said out loud (unowned variants shipping unowned, dangling
 * references, a missing info.yml dependency, an icon media without a file)
 * rides the warnings.
 */
final class ShipPlan {

  /**
   * Constructs a ShipPlan.
   *
   * @param string $module
   *   The target module.
   * @param string $id
   *   The blueprint id: the file's name suffix and the variants' owner.
   * @param array $definition
   *   The blueprint file's content, in discovery's envelope (label,
   *   description, icon when copied, ai when withheld, design_system,
   *   scope, subject, layout, styles when the document has node-less
   *   keys).
   * @param string $icon
   *   The icon's outcome: BlueprintShipper::ICON_COPIED (the blueprint's
   *   media file lands at the definition's icon key) or ICON_NONE.
   * @param \Drupal\file\FileInterface|null $iconSource
   *   The file the ship copies into the module, for ICON_COPIED only.
   * @param \Drupal\daedalus_blueprint_library\BlueprintInterface $blueprint
   *   The content blueprint that graduates into the file: deleted by the
   *   ship, its owned variants adopted in place.
   * @param array $variants
   *   The variant definitions the ship writes, keyed by machine name, in
   *   the variants disk lane's grammar (owned ones carry `blueprint: ID`).
   * @param array $variantOutcomes
   *   The variant shipper's outcome per machine name: created, updated,
   *   unchanged, or dropped (a name the per-id file held that this ship
   *   leaves out).
   * @param array $skipped
   *   Variants the ship leaves alone, keyed by machine name; the value says
   *   why (shipped by another module or file, owned by another blueprint).
   * @param string[] $warnings
   *   Everything said out loud: unowned variants shipping unowned,
   *   dangling references, a missing info.yml dependency, an icon media
   *   without a file.
   */
  public function __construct(
    public readonly string $module,
    public readonly string $id,
    public readonly array $definition,
    public readonly string $icon,
    public readonly ?FileInterface $iconSource,
    public readonly BlueprintInterface $blueprint,
    public readonly array $variants,
    public readonly array $variantOutcomes,
    public readonly array $skipped,
    public readonly array $warnings,
  ) {}

}
