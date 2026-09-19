<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\SwatchKind;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\SwatchKind;
use Drupal\daedalus_stylish\SwatchKindBase;

/**
 * The color kind: a named CSS color.
 *
 * Validates through the color scalar shape the property machinery and skin
 * tokens already share — hex, functional notations (rgb/hsl/oklch/…), and
 * named colors, permissive on names because the client's csstree lexer is
 * the precise grammar check. var() passes: a color swatch may alias a skin
 * token (so a skin swap recolors it) or another swatch.
 */
#[SwatchKind(
  id: 'color',
  label: new TranslatableMarkup('Color'),
)]
final class Color extends SwatchKindBase {

  /**
   * {@inheritdoc}
   */
  public function validateValue(string $path, string $value): array {
    return $this->propertyGroups->validateShape($path, $value, 'color');
  }

}
