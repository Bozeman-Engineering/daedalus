<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Plugin\Field\FieldType\JsonBlobItemBase;

/**
 * A font's faces: a list of {weight, style, source} maps, one JSON doc.
 *
 * Shares the animation steps' storage (JsonBlobItemBase) but is its own
 * type: the grammar is a list of FACES, each a weight (a number or a
 * variable-font range), a style (normal, italic, oblique) and a typed
 * source map ({file: uuid} today; a hosted kind joins beside `file` later
 * without a reshape), validated through FontValidator::validateFaces().
 *
 * Faces are stored canonical (canonicalizeFaces()): weight keywords fold
 * to their numbers (`normal` is 400, `bold` is 700), a range spells as
 * two numbers with one space, an absent weight or style takes the default
 * (400, normal), styles lowercase, keys sit in one order, and faces sort
 * by weight then style, so the printer, the roster record and the tab
 * read one shape whatever spelling the writer used.
 */
#[FieldType(
  id: 'daedalus_font_faces',
  label: new TranslatableMarkup('Font faces'),
  description: new TranslatableMarkup('The faces a font family ships: weight, style and source per face.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class FontFacesItem extends JsonBlobItemBase {

  /**
   * The source kind naming a managed file by uuid: the one kind today.
   */
  public const SOURCE_FILE = 'file';

  /**
   * The styles a face may declare.
   */
  public const STYLES = ['normal', 'italic', 'oblique'];

  /**
   * The weight keywords and the numbers they fold to.
   */
  public const WEIGHT_KEYWORDS = ['normal' => '400', 'bold' => '700'];

  /**
   * The defaults an absent weight or style takes.
   */
  public const DEFAULT_WEIGHT = '400';
  public const DEFAULT_STYLE = 'normal';

  /**
   * {@inheritdoc}
   */
  protected static function valueLabel(): TranslatableMarkup {
    return new TranslatableMarkup('Font faces JSON');
  }

  /**
   * Returns the decoded faces.
   *
   * @return array<int, array>
   *   Canonical faces; [] when the item holds none.
   */
  public function getFaces(): array {
    $faces = $this->getJson();
    return is_array($faces) ? $faces : [];
  }

  /**
   * Sets the faces, canonicalizing them.
   *
   * An empty list empties the item so "no faces" and "no field value" are
   * the same state.
   */
  public function setFaces(array $faces): void {
    $this->setJson(static::canonicalizeFaces($faces));
  }

  /**
   * The canonical spelling of a face weight, or NULL.
   *
   * The one weight grammar, shared by the setter (which stores it) and the
   * validator (which refuses what it cannot canonicalize): a number from 1
   * to 1000 ("400", 400, "400.0" all spell "400"), a keyword (`normal`,
   * `bold`), or a variable-font range of two ascending numbers separated
   * by whitespace ("100 900"). An absent weight (NULL) is the default.
   */
  public static function canonicalWeight(mixed $weight): ?string {
    if ($weight === NULL) {
      return self::DEFAULT_WEIGHT;
    }
    if (is_int($weight) || is_float($weight)) {
      $weight = (string) $weight;
    }
    if (!is_string($weight)) {
      return NULL;
    }
    $weight = strtolower(trim($weight));
    if (isset(self::WEIGHT_KEYWORDS[$weight])) {
      return self::WEIGHT_KEYWORDS[$weight];
    }
    $numbers = preg_split('/\s+/', $weight) ?: [];
    if (count($numbers) > 2) {
      return NULL;
    }
    $canonical = [];
    foreach ($numbers as $number) {
      if (!preg_match('/^\d+(?:\.\d+)?$/', $number)) {
        return NULL;
      }
      $value = (float) $number;
      if ($value < 1 || $value > 1000) {
        return NULL;
      }
      $canonical[] = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
    if (count($canonical) === 2 && (float) $canonical[0] > (float) $canonical[1]) {
      return NULL;
    }
    return implode(' ', $canonical);
  }

  /**
   * The canonical spelling of a face style, or NULL.
   *
   * One of normal, italic or oblique, case-insensitively; an absent style
   * (NULL) is the default.
   */
  public static function canonicalStyle(mixed $style): ?string {
    if ($style === NULL) {
      return self::DEFAULT_STYLE;
    }
    if (!is_string($style)) {
      return NULL;
    }
    $style = strtolower(trim($style));
    return in_array($style, self::STYLES, TRUE) ? $style : NULL;
  }

  /**
   * Canonicalizes a faces list: weights and styles folded, faces sorted.
   *
   * Values that do not canonicalize are kept verbatim (the validator
   * refuses them; the setter never silently drops a face), a face that is
   * not a map is kept as it is, and two faces of one weight and style
   * both survive (the validator refuses that too, DUPLICATE_FONT_FACE).
   * Keys settle in one order (weight, style, source, then anything else
   * the validator will refuse) so the stored shape, and the hash over it,
   * never depend on the writer's key order.
   */
  public static function canonicalizeFaces(array $faces): array {
    $canonical = [];
    foreach ($faces as $face) {
      if (!is_array($face)) {
        $canonical[] = $face;
        continue;
      }
      $weight = static::canonicalWeight($face['weight'] ?? NULL) ?? $face['weight'];
      $style = static::canonicalStyle($face['style'] ?? NULL) ?? $face['style'];
      $canonical[] = ['weight' => $weight, 'style' => $style, 'source' => $face['source'] ?? NULL]
        + array_diff_key($face, array_flip(['weight', 'style', 'source']));
    }
    usort($canonical, static function (mixed $a, mixed $b): int {
      $a_weight = is_array($a) ? static::canonicalWeight($a['weight'] ?? NULL) : NULL;
      $b_weight = is_array($b) ? static::canonicalWeight($b['weight'] ?? NULL) : NULL;
      if ($a_weight === NULL || $b_weight === NULL) {
        // Uncanonical faces sort last, stably among themselves.
        return ($a_weight === NULL ? 1 : 0) <=> ($b_weight === NULL ? 1 : 0);
      }
      $by_weight = (float) $a_weight <=> (float) $b_weight;
      if ($by_weight !== 0) {
        return $by_weight;
      }
      $a_style = static::canonicalStyle($a['style'] ?? NULL) ?? '';
      $b_style = static::canonicalStyle($b['style'] ?? NULL) ?? '';
      return array_search($a_style, self::STYLES, TRUE) <=> array_search($b_style, self::STYLES, TRUE);
    });
    return $canonical;
  }

}
