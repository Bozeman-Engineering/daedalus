<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Plugin\Field\FieldType\JsonBlobItemBase;

/**
 * The style map: every Daedalus Stylish style on this entity, in one JSON document.
 *
 * Maps selectable keys (SelectableKey format, verbatim — including the
 * `{parent}::.css-class` sub-element extension) to layered style entries:
 * an optional ordered "variants" reference list of {"variant", "fragment"}
 * objects ({"variants": [{"variant": "hero", "fragment": ""}]}) plus
 * condition-keyed semantic CSS property maps, where the entry's own
 * properties override every referenced variant and later references beat
 * earlier ones. The map lives on the styled entity so styles version,
 * stage (tempstore), and undo with everything else by construction.
 *
 * The field is auto-managed by DaedalusStylishFieldManager via the per-bundle
 * "Enable styling" setting (the Layout Builder overrides pattern) and never
 * appears in Field UI.
 */
#[FieldType(
  id: 'daedalus_stylish_style_map',
  label: new TranslatableMarkup('Style map'),
  description: new TranslatableMarkup('Daedalus Stylish styles for elements rendered by this entity, keyed by selectable key.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class StyleMapItem extends JsonBlobItemBase {

  /**
   * {@inheritdoc}
   */
  protected static function valueLabel(): TranslatableMarkup {
    return new TranslatableMarkup('Style map JSON');
  }

  /**
   * Returns the decoded style map.
   *
   * @return array
   *   Selectable keys mapped to style data. Empty array when unstyled.
   */
  public function getStyleMap(): array {
    $map = $this->getJson();
    return is_array($map) ? $map : [];
  }

  /**
   * Sets the style map.
   *
   * An empty map empties the item so "no styles" and "no field value" are
   * the same state.
   */
  public function setStyleMap(array $map): void {
    $this->setJson($map);
  }

}
