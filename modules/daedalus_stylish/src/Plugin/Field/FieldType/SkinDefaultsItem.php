<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A skin's site defaults: scope-keyed element-relative styles in one JSON.
 *
 * Shares StyleMapItem's storage internals (one JSON text column, array in /
 * array out) but is deliberately a SIBLING type, like the tokens and the
 * variant styles: the key grammar differs ({scope: {relative key: entry}}
 * against declared scopes, vs selectable keys against a host layout) and so
 * does the validation engine seam (StyleMapValidator::validateDefaults(),
 * which rides the scope-only seam). A distinct type id lets the
 * daedalus_blueprint field handler target skin defaults precisely.
 *
 * A skin carries two fields of this type: the package layer (what a
 * module ships, sync's field) and the site layer over it (what the site
 * added or changed, down to the property). Both hold the same grammar;
 * mergeLayers() is the read every consumer but the disk lane takes.
 */
#[FieldType(
  id: 'daedalus_skin_defaults',
  label: new TranslatableMarkup('Skin defaults'),
  description: new TranslatableMarkup('Site element defaults carried by a skin, keyed by scope.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class SkinDefaultsItem extends StyleMapItem {

  /**
   * The keyed depth of a defaults map: scope, relative key, condition.
   *
   * Beneath it sits the property map, where a site value replaces the
   * package's whole.
   */
  protected const MERGE_DEPTH = 3;

  /**
   * Merges a skin's site defaults over its package defaults, per property.
   *
   * The one read of a skin's defaults as worn: a site value at
   * {scope, relative key, condition, property} replaces the package's
   * there and the package's other properties show through, a site scope,
   * key or condition the package lacks joins the map, and a site entry
   * removed reveals the package's value. A property value replaces whole
   * (a value is one CSS string, never merged inside). Order is package
   * order with the site's additions after, at every level. Both inputs
   * are stores: a site level that is not a map is corrupt and fails
   * loudly, while a package level the site's map lands on that is not a
   * map is simply replaced (the site's map is the newer fact).
   *
   * @throws \UnexpectedValueException
   *   When the site layer is not maps down to the property level.
   */
  public static function mergeLayers(array $package, array $site): array {
    return static::mergeToDepth($package, $site, self::MERGE_DEPTH, '');
  }

  /**
   * Merges maps keyed to the given depth, replacing values beneath it.
   */
  protected static function mergeToDepth(array $base, array $over, int $depth, string $path): array {
    foreach ($over as $key => $value) {
      $at = $path === '' ? (string) $key : "$path.$key";
      if ($depth === 0) {
        $base[$key] = $value;
        continue;
      }
      if (!is_array($value)) {
        throw new \UnexpectedValueException(sprintf('The site defaults entry "%s" is not a map; a skin\'s defaults are maps of scope, relative key and condition down to the properties.', $at));
      }
      $beneath = $base[$key] ?? NULL;
      $base[$key] = static::mergeToDepth(is_array($beneath) ? $beneath : [], $value, $depth - 1, $at);
    }
    return $base;
  }

}
