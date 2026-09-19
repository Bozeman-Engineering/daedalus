<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layers\Asset;

use Drupal\Core\Asset\AssetCollectionGrouperInterface;
use Drupal\daedalus_stylish_layers\Layers;

/**
 * Decorates asset.css.collection_grouper to keep groups stamp-homogeneous.
 *
 * Core groups consecutive preprocessable files by [type, group, media] only,
 * so an exempt (unstamped) file would otherwise be aggregated into a stamped
 * group and get wrapped in the layer with it. Splitting the inner grouper's
 * output on the stamp — preserving order, never regrouping — guarantees the
 * collection optimizer sees all-stamped or all-unstamped groups. The asset
 * controller uses this same decorated service when it rebuilds groups, so
 * page-side and controller-side deltas and hashes stay aligned.
 */
final class LayeredCssCollectionGrouper implements AssetCollectionGrouperInterface {

  public function __construct(
    private readonly AssetCollectionGrouperInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function group(array $css_assets) {
    $groups = [];
    foreach ($this->inner->group($css_assets) as $group) {
      foreach ($this->splitByStamp($group) as $subgroup) {
        $groups[] = $subgroup;
      }
    }
    return $groups;
  }

  /**
   * Splits a group into consecutive runs of identically-stamped items.
   *
   * Each subgroup keeps the original group's properties but carries its own
   * items' stamp (or none) at the group level, which is what optimizeGroup()
   * and the renderer read. The inner grouper initializes a group from its
   * first item's properties, so a stale group-level stamp is dropped first.
   */
  private function splitByStamp(array $group): array {
    $runs = [];
    foreach ($group['items'] as $item) {
      $stamp = $item[Layers::STAMP_KEY] ?? NULL;
      if (!$runs || $stamp !== $runs[array_key_last($runs)]['stamp']) {
        $runs[] = ['stamp' => $stamp, 'items' => []];
      }
      $runs[array_key_last($runs)]['items'][] = $item;
    }

    $subgroups = [];
    foreach ($runs as $run) {
      $subgroup = $group;
      unset($subgroup[Layers::STAMP_KEY]);
      if ($run['stamp'] !== NULL) {
        $subgroup[Layers::STAMP_KEY] = $run['stamp'];
      }
      $subgroup['items'] = $run['items'];
      $subgroups[] = $subgroup;
    }
    return $subgroups;
  }

}
