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
 */
#[FieldType(
  id: 'daedalus_skin_defaults',
  label: new TranslatableMarkup('Skin defaults'),
  description: new TranslatableMarkup('Site element defaults carried by a skin, keyed by scope.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class SkinDefaultsItem extends StyleMapItem {

}
