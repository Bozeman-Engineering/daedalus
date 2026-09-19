<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Core\Layout\LayoutDefinition;

/**
 * Container-layout facts read from layout plugin definitions.
 *
 * A container layout declares `container: true` in its layouts.yml entry
 * and defines exactly one region. That region is a storage vestige (core's
 * SectionComponent requires a region name): it renders no wrapper and has
 * no addressable identity, so a container section's components are ONE
 * ordered flow, not a map of named slots. The serialization spine follows
 * the data's real shape: container sections carry an ordered `children`
 * list instead of the `regions` map, at every depth. Multi-region layouts
 * (genuinely named slots) keep the regions shape.
 *
 * The flag lives on the layout DEFINITION so the layout provider declares
 * the vestige and this module stays generic: core's single-region layouts
 * (e.g. layout_onecol) are NOT containers — their region is a real named
 * slot — and keep the regions shape untouched.
 */
final class ContainerLayout {

  /**
   * Whether a layout definition declares the container shape.
   *
   * @param \Drupal\Core\Layout\LayoutDefinition|null $definition
   *   The layout plugin definition, or NULL when unresolvable (the caller's
   *   invalid-layout handling answers there; an unresolvable layout is
   *   never a container).
   *
   * @return bool
   *   TRUE for a `container: true` definition with exactly one region.
   */
  public static function isContainer(?LayoutDefinition $definition): bool {
    return $definition !== NULL
      && $definition->get('container') === TRUE
      && count($definition->getRegionNames()) === 1;
  }

  /**
   * The vestigial storage region children map to.
   *
   * @param \Drupal\Core\Layout\LayoutDefinition $definition
   *   A container layout definition (see isContainer()).
   *
   * @return string
   *   The sole region's name.
   */
  public static function storageRegion(LayoutDefinition $definition): string {
    $regions = $definition->getRegionNames();
    return (string) reset($regions);
  }

}
