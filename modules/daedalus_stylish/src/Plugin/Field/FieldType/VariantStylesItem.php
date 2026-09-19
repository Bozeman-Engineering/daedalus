<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A variant's style data: element-relative styles in one JSON document.
 *
 * Shares StyleMapItem's storage internals (one JSON text column, array in /
 * array out) but is deliberately a SIBLING type rather than a reuse of
 * daedalus_stylish_style_map: the key grammar differs ('' for the root element plus
 * '::.class-name' sub-element suffixes, vs the style map's full selectable
 * keys) and so does the validation context (a declared scope, not a host
 * entity's rendered layout). A distinct type id lets daedalus_blueprint field
 * handlers and validators target variant styles precisely instead of
 * inheriting selectable-key semantics that do not apply here.
 */
#[FieldType(
  id: 'daedalus_variant_styles',
  label: new TranslatableMarkup('Variant styles'),
  description: new TranslatableMarkup('Element-relative Daedalus Stylish styles carried by a style variant, keyed by relative element key.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class VariantStylesItem extends StyleMapItem {

}
