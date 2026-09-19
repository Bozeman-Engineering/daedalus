<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Core\Layout\LayoutDefinition;
use Drupal\Core\Layout\LayoutPluginManagerInterface;

/**
 * Spatial summaries of the site's available layout plugins.
 *
 * The icon map is the layout's own 2D sketch: rows of region names stack
 * top-to-bottom, regions within a row sit side by side, and a region
 * repeated within a row spans those columns. The column count is the widest
 * row. Both the schema's available_layouts and corrective INVALID_LAYOUT
 * errors derive their spatial vocabulary here so the two surfaces cannot
 * drift apart.
 */
final class AvailableLayouts {

  /**
   * Summarizes the available layouts for corrective error context.
   *
   * @param \Drupal\Core\Layout\LayoutPluginManagerInterface $layout_plugin_manager
   *   The layout plugin manager.
   *
   * @return array
   *   A list of ['id' => string, 'columns' => int, 'container' => bool]
   *   entries sorted by id. Each entry is a slice of the schema's
   *   available_layouts entry for the same layout; columns is omitted when
   *   the layout declares no icon map, container when the layout is not a
   *   container layout (whose section nodes carry an ordered `children`
   *   list instead of regions).
   */
  public static function summarize(LayoutPluginManagerInterface $layout_plugin_manager): array {
    $available = [];
    foreach ($layout_plugin_manager->getFilteredDefinitions('layout_builder') as $id => $definition) {
      $entry = ['id' => $id];
      $columns = self::columns($definition);
      if ($columns !== NULL) {
        $entry['columns'] = $columns;
      }
      if (ContainerLayout::isContainer($definition)) {
        $entry['container'] = TRUE;
      }
      $available[] = $entry;
    }
    usort($available, fn (array $a, array $b) => strcmp($a['id'], $b['id']));
    return $available;
  }

  /**
   * Derives a layout's column count from its icon map.
   *
   * @param \Drupal\Core\Layout\LayoutDefinition $definition
   *   The layout plugin definition.
   *
   * @return int|null
   *   The widest icon map row, or NULL when no icon map is declared.
   */
  public static function columns(LayoutDefinition $definition): ?int {
    $icon_map = $definition->getIconMap();
    return empty($icon_map) ? NULL : max(array_map('count', $icon_map));
  }

}
