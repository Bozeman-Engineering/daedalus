<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A skin's fonts: name-keyed self-hosted family records in one JSON document.
 *
 * The type section of the skin, the tokens and animations sections'
 * sibling type (SkinTokensItem, SkinAnimationsItem): a skin is the site's
 * look as data, and its type is part of that look, so a skin's fonts swap
 * with the skin exactly as its tokens do. The grammar is {name: {family,
 * faces, description, offered}}: the FAMILY is what a font-family value
 * names (bare, "Inter"; the compiled @font-face and the roster spelling
 * quote it through StyleGuide::fontFamily()), the FACES the files the
 * family ships, one per weight and style, each naming a managed file
 * through its typed source ({file: uuid}). The validation seam is
 * FontValidator::validateFonts(), which runs every record through the
 * family and faces grammars and resolves each face's file.
 *
 * A stored font is a RECORD and admits no bare shorthand (a family alone
 * delivers nothing, faces alone can be referenced by nothing): the doors
 * that take authored text (a hand-written skins file, this setter) fill
 * the missing facts (description '', offered TRUE) and canonicalize the
 * faces (canonicalizeFaces()): weight keywords fold to their numbers
 * (`normal` is 400, `bold` is 700), a range spells as two numbers with
 * one space, an absent weight or style takes the default (400, normal),
 * styles lowercase, keys sit in one order, and faces sort by weight then
 * style, so the printer, the roster record and the tab read one shape
 * whatever spelling the writer used.
 *
 * This type is a skin's PACKAGE layer of fonts, the type a module ships
 * and the one layer of a skin the site made itself. The site layer over
 * it is the sibling type SkinSiteFontsItem (partial records by name), and
 * the two meet in mergeLayers(), the read every consumer but the disk
 * lane takes.
 */
#[FieldType(
  id: 'daedalus_skin_fonts',
  label: new TranslatableMarkup('Skin fonts'),
  description: new TranslatableMarkup('Self-hosted font family records carried by a skin, keyed by font name.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class SkinFontsItem extends StyleMapItem {

  /**
   * The keys of a font record, in stored order.
   *
   * - family: the name font-family values reference, bare ("Inter").
   * - faces: the faces the family ships, a non-empty list of {weight,
   *   style, source: {file: uuid}}, canonical (canonicalizeFaces()).
   * - description: when to reach for the family, the prose the AI and the
   *   skin tab read; '' when nobody wrote one.
   * - offered: whether the AI's roster advertises the font; TRUE by
   *   default. Delivery never reads it: the faces print either way.
   */
  public const RECORD_KEYS = ['family', 'faces', 'description', 'offered'];

  /**
   * The shape of a font name.
   *
   * The roster id and the disk file's key, the animation name's charset:
   * lowercase letters, digits, and inner hyphens or underscores.
   */
  public const NAME = '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/';

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
   *
   * Canonicalizes on write: records fill and faces settle, so the store
   * never holds two shapes of one font.
   */
  public function setStyleMap(array $map): void {
    parent::setStyleMap(static::canonicalizeLayer($map));
  }

  /**
   * Canonicalizes an authored map to this layer's stored shape.
   */
  protected static function canonicalizeLayer(array $map): array {
    return static::canonicalizeFonts($map);
  }

  /**
   * Canonicalizes an authored fonts map: every entry becomes a full record.
   *
   * The one door grammar every authored surface shares (the skins disk
   * lane, the entity setter): each named font passes through
   * canonicalizeFont(); a non-map entry passes through verbatim for the
   * validator to refuse (canonicalization never silently drops one).
   */
  public static function canonicalizeFonts(array $fonts): array {
    foreach ($fonts as $name => $font) {
      if (is_array($font)) {
        $fonts[$name] = static::canonicalizeFont($font);
      }
    }
    return $fonts;
  }

  /**
   * Canonicalizes one authored font: the facts fill, the faces settle.
   *
   * Keys settle into stored order, a missing description or offered flag
   * takes its default, the faces canonicalize when they are a list, and
   * any key outside RECORD_KEYS rides along for the validator to refuse.
   * A missing family or faces stays missing (the validator refuses it).
   */
  public static function canonicalizeFont(array $font): array {
    $record = [];
    if (array_key_exists('family', $font)) {
      $record['family'] = is_string($font['family']) ? trim($font['family']) : $font['family'];
    }
    if (array_key_exists('faces', $font)) {
      $record['faces'] = is_array($font['faces']) && array_is_list($font['faces']) ? static::canonicalizeFaces($font['faces']) : $font['faces'];
    }
    $record['description'] = $font['description'] ?? '';
    $record['offered'] = $font['offered'] ?? TRUE;
    return $record + $font;
  }

  /**
   * Whether a stored font entry is a record.
   */
  public static function isRecord(mixed $font): bool {
    return is_array($font) && array_key_exists('family', $font) && array_key_exists('faces', $font);
  }

  /**
   * The canonical spelling of a face weight, or NULL.
   *
   * The one weight grammar, shared by the store (which keeps it) and the
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

  /**
   * The managed file uuids a faces list names, deduplicated.
   *
   * @param array $faces
   *   Canonical faces, each source {file: uuid}.
   *
   * @return string[]
   *   The uuids, in face order.
   */
  public static function faceFileUuids(array $faces): array {
    $uuids = [];
    foreach ($faces as $face) {
      $uuid = is_array($face) ? ($face['source'][self::SOURCE_FILE] ?? NULL) : NULL;
      if (is_string($uuid) && $uuid !== '') {
        $uuids[$uuid] = $uuid;
      }
    }
    return array_values($uuids);
  }

  /**
   * The managed file uuids a fonts map's STATED faces name, deduplicated.
   *
   * The read that serves both layers (a site record may change one fact
   * and state no faces at all) and the file hold (FontFiles::hold()).
   *
   * @return string[]
   *   The uuids every stated face names.
   */
  public static function fileUuids(array $fonts): array {
    $uuids = [];
    foreach ($fonts as $font) {
      if (is_array($font) && is_array($font['faces'] ?? NULL)) {
        array_push($uuids, ...static::faceFileUuids($font['faces']));
      }
    }
    return array_values(array_unique($uuids));
  }

  /**
   * Merges a skin's site layer over its package layer: records per name.
   *
   * The one read of a skin's fonts as worn, SkinAnimationsItem::
   * mergeLayers()'s rule: a site record over a package font replaces the
   * facts it states and keeps the rest; a site record naming a font the
   * package lacks mints it, its unstated facts at their defaults. Order is
   * package order with the site's additions after. Both inputs are
   * stores, canonical by construction: a site entry that is not a map, or
   * a site-minted font that states no family or faces, is corrupt and
   * fails loudly.
   *
   * @param array $package
   *   The package layer, full records (this type's store).
   * @param array $site
   *   The site layer, partial records (SkinSiteFontsItem's store).
   *
   * @return array
   *   {name: {family, faces, description, offered}}, full records.
   *
   * @throws \UnexpectedValueException
   *   On a corrupt layer.
   */
  public static function mergeLayers(array $package, array $site): array {
    $merged = $package;
    foreach ($site as $name => $record) {
      if (!is_array($record)) {
        throw new \UnexpectedValueException(sprintf('The site font %s is not a record; the site layer stores the facts it changes per font.', $name));
      }
      if (isset($merged[$name]) && static::isRecord($merged[$name])) {
        $merged[$name] = static::canonicalizeFont($record + $merged[$name]);
        continue;
      }
      if (!static::isRecord($record)) {
        throw new \UnexpectedValueException(sprintf('The site font %s states no family or faces and the package mints no such font; a site-minted font carries both.', $name));
      }
      $merged[$name] = static::canonicalizeFont($record);
    }
    return $merged;
  }

}
