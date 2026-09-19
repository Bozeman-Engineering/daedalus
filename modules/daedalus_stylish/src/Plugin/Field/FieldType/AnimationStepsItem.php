<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Plugin\Field\FieldType\JsonBlobItemBase;

/**
 * An animation's keyframe steps: an offset-keyed declaration map, one JSON doc.
 *
 * Shares SwatchValueItem's storage (JsonBlobItemBase) but is its own
 * type: the grammar is a keyframes BODY, offsets ("0%", "50%",
 * "100%") mapping CSS property names to values, validated through
 * StyleMapValidator::validateAnimationSteps(). A distinct type id lets the
 * daedalus_blueprint field handler target animation steps precisely.
 *
 * Offsets are stored canonical (canonicalizeSteps()): `from` and `to` fold
 * into `0%` and `100%`, numbers normalize (`50.0%` is `50%`), and steps
 * sort ascending, so the printer, the roster record and the editor read
 * one shape whatever spelling the writer used.
 */
#[FieldType(
  id: 'daedalus_animation_steps',
  label: new TranslatableMarkup('Animation steps'),
  description: new TranslatableMarkup('The keyframe steps an animation names: offsets mapping CSS properties to values.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class AnimationStepsItem extends JsonBlobItemBase {

  /**
   * {@inheritdoc}
   */
  protected static function valueLabel(): TranslatableMarkup {
    return new TranslatableMarkup('Animation steps JSON');
  }

  /**
   * Returns the decoded steps.
   *
   * @return array<string, array<string, string>>
   *   Canonical offsets mapping property names to values; [] when the item
   *   holds no steps.
   */
  public function getSteps(): array {
    $steps = $this->getJson();
    return is_array($steps) ? $steps : [];
  }

  /**
   * Sets the steps, canonicalizing offsets.
   *
   * An empty map empties the item so "no steps" and "no field value" are
   * the same state.
   */
  public function setSteps(array $steps): void {
    $this->setJson(static::canonicalizeSteps($steps));
  }

  /**
   * The canonical percentage spelling of a keyframe offset, or NULL.
   *
   * The one offset grammar, shared by the setter (which stores it) and the
   * validator (which refuses what it cannot canonicalize): `from` is `0%`,
   * `to` is `100%`, and `N%` with N in [0, 100] normalizes its number
   * (`050%` and `50.0%` are `50%`). Anything else (a bare number, a
   * percentage outside the range, a keyword, a comma list of selectors) is
   * not an offset; comma lists are refused on purpose, one offset per
   * step keeps the map a map.
   */
  public static function canonicalOffset(string $offset): ?string {
    $offset = strtolower(trim($offset));
    if ($offset === 'from') {
      return '0%';
    }
    if ($offset === 'to') {
      return '100%';
    }
    if (!preg_match('/^(\d+(?:\.\d+)?|\.\d+)%$/', $offset, $matches)) {
      return NULL;
    }
    $number = (float) $matches[1];
    if ($number > 100) {
      return NULL;
    }
    // A trimmed decimal rendering: no trailing zeros, no dangling point.
    $rendered = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    return ($rendered === '' ? '0' : $rendered) . '%';
  }

  /**
   * Canonicalizes a steps map: offsets folded and sorted ascending.
   *
   * Offsets that do not canonicalize are kept verbatim (the validator
   * refuses them; the setter never silently drops a step), and two
   * spellings of one offset (`from` beside `0%`) collapse with the later
   * winning (the validator refuses that too, DUPLICATE_ANIMATION_OFFSET).
   */
  public static function canonicalizeSteps(array $steps): array {
    $canonical = [];
    foreach ($steps as $offset => $declarations) {
      $key = static::canonicalOffset((string) $offset) ?? (string) $offset;
      $canonical[$key] = $declarations;
    }
    uksort($canonical, static function (string $a, string $b): int {
      $a_offset = static::canonicalOffset($a);
      $b_offset = static::canonicalOffset($b);
      if ($a_offset === NULL || $b_offset === NULL) {
        // Uncanonical keys sort last, stably among themselves.
        return ($a_offset === NULL ? 1 : 0) <=> ($b_offset === NULL ? 1 : 0) ?: strcmp($a, $b);
      }
      return (float) $a_offset <=> (float) $b_offset;
    });
    return $canonical;
  }

}
