<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\SwatchKind;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Attribute\SwatchKind;
use Drupal\daedalus_stylish\SwatchKindBase;

/**
 * The gradient kind: a named CSS gradient, an <image> value.
 *
 * Server checks are csstree-informed but match the wide-tier posture: the
 * shared hygiene gate runs first (the ;{}<> ban, media rejection, the
 * script-scheme scan), then the structure the server can honestly check —
 * every comma-separated layer must BE a CSS gradient function
 * (linear/radial/conic and their repeating- forms) with balanced
 * parentheses. What the server does not attempt is the inner stop grammar:
 * that is the client csstree lexer's job, and an ungrammatical stop list is
 * inert, never unsafe. A var() layer passes — a gradient swatch may alias
 * or layer another named value — and var() stops inside a gradient function
 * pass structurally like everywhere else. url() layers do NOT pass: media
 * belongs in the style value composing the gradient over it, not inside the
 * named gradient (swatch compilation is media-free).
 */
#[SwatchKind(
  id: 'gradient',
  label: new TranslatableMarkup('Gradient'),
)]
final class Gradient extends SwatchKindBase {

  /**
   * The head of a CSS gradient function.
   */
  protected const GRADIENT_FUNCTION = '/^(?:repeating-)?(?:linear|radial|conic)-gradient\(/i';

  /**
   * Values that pass any property's grammar (CSS-wide keywords).
   */
  protected const CSS_WIDE_KEYWORDS = ['inherit', 'initial', 'unset', 'revert', 'revert-layer'];

  /**
   * {@inheritdoc}
   */
  public function validateValue(string $path, string $value): array {
    // The shared hygiene gate (the 'text' shape carries no structure of its
    // own, exactly what we want: structure is checked below).
    $errors = $this->propertyGroups->validateShape($path, $value, 'text');
    if ($errors !== []) {
      return $errors;
    }
    $value = trim($value);
    if (in_array(strtolower($value), self::CSS_WIDE_KEYWORDS, TRUE)) {
      return [];
    }
    $layers = $this->splitLayers($value);
    if ($layers === NULL) {
      return [
        new BlueprintError($path, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a gradient value.', $value), [], 'A gradient swatch value is one or more comma-separated CSS gradient functions with balanced parentheses, e.g. "linear-gradient(135deg, #1a1a2e, #e94560)".'),
      ];
    }
    foreach ($layers as $layer) {
      // A var() layer references another named value (a token or swatch);
      // whether it resolves is the spine's strict-site check.
      if (preg_match('/^var\(/i', $layer)) {
        continue;
      }
      if (!preg_match(self::GRADIENT_FUNCTION, $layer) || !str_ends_with($layer, ')')) {
        return [
          new BlueprintError($path, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a CSS gradient.', $layer), [], 'Each layer of a gradient swatch must be a gradient function — linear-gradient(), radial-gradient(), conic-gradient(), or a repeating- form — or a var() reference to a named value. Media images compose with a gradient in the style value referencing it, never inside the named gradient itself.'),
        ];
      }
    }
    return [];
  }

  /**
   * Splits a value at top-level commas, parenthesized groups kept intact.
   *
   * @return string[]|null
   *   The trimmed layers, or NULL when the value is malformed: unbalanced
   *   parentheses, an empty layer (leading, trailing, or doubled comma), or
   *   nothing at all.
   */
  protected function splitLayers(string $value): ?array {
    $layers = [];
    $current = '';
    $depth = 0;
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
      $char = $value[$i];
      if ($char === '(') {
        $depth++;
      }
      elseif ($char === ')' && --$depth < 0) {
        return NULL;
      }
      if ($char === ',' && $depth === 0) {
        if (trim($current) === '') {
          return NULL;
        }
        $layers[] = trim($current);
        $current = '';
        continue;
      }
      $current .= $char;
    }
    if ($depth !== 0 || trim($current) === '') {
      return NULL;
    }
    $layers[] = trim($current);
    return $layers;
  }

}
