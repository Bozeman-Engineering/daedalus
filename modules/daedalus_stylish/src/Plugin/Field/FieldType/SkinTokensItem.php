<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A skin's design tokens: scale-keyed token values in one JSON document.
 *
 * Shares StyleMapItem's storage internals (one JSON text column, array in /
 * array out) but is deliberately a SIBLING type, like the variant styles:
 * the key grammar differs ({scale: {name: value}} against the site's
 * declared token scales, vs selectable keys against element declarations)
 * and so does the validation engine seam
 * (StyleMapValidator::validateTokens(), not validate()). A distinct type id
 * lets the daedalus_blueprint field handler target skin tokens precisely.
 */
#[FieldType(
  id: 'daedalus_skin_tokens',
  label: new TranslatableMarkup('Skin tokens'),
  description: new TranslatableMarkup('Design token values carried by a skin, keyed by token scale and token name.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class SkinTokensItem extends StyleMapItem {

}
