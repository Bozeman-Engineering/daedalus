<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A skin's design tokens: scale-keyed token records in one JSON document.
 *
 * Shares StyleMapItem's storage internals (one JSON text column, array in /
 * array out) but is deliberately a SIBLING type, like the variant styles:
 * the key grammar differs ({scale: {name: record}} against the site's
 * declared token scales, vs selectable keys against element declarations)
 * and so does the validation engine seam
 * (StyleMapValidator::validateTokens(), not validate()). A distinct type id
 * lets the daedalus_blueprint field handler target skin tokens precisely.
 *
 * A stored token is a RECORD, {value, description, offered}: a token is a
 * material, and a material's facts (its reasoning, whether a row offers
 * it) belong on the token itself rather than in a YAML comment or a guide
 * file. The store admits records only; the doors that take authored text
 * (a hand-written skins file, a blueprint payload, this setter) accept a
 * BARE value as shorthand for {value} and canonicalize it
 * (canonicalizeTokens()), the animation steps precedent (offsets are
 * stored canonical whatever spelling the writer used). Machine-written
 * files (the shipper) and every reader hold records; a reader that wants
 * the values alone projects them (values()).
 *
 * This type is a skin's PACKAGE layer, the tokens a module ships and the
 * one layer of a skin the site made itself. The site layer over it is the
 * sibling type SkinSiteTokensItem (partial records by name), and the two
 * meet in mergeLayers(), the read every consumer but the disk lane takes.
 */
#[FieldType(
  id: 'daedalus_skin_tokens',
  label: new TranslatableMarkup('Skin tokens'),
  description: new TranslatableMarkup('Design token records carried by a skin, keyed by token scale and token name.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class SkinTokensItem extends StyleMapItem {

  /**
   * The keys of a token record, in stored order.
   *
   * - value: a CSS string, or a condition-keyed map whose 'default' entry
   *   always applies (the one value model tokens and swatches share).
   * - description: the token's reasoning, the prose the AI and the
   *   palette read; '' when nobody wrote one.
   * - offered: whether rows bound to the token's scale offer it as one of
   *   the scale's everyday steps, and whether the AI's roster advertises
   *   it. TRUE by default (a scale nobody curated offers everything it
   *   mints); legality never reads it.
   */
  public const RECORD_KEYS = ['value', 'description', 'offered'];

  /**
   * {@inheritdoc}
   *
   * Canonicalizes on write: bare values become records, so the store never
   * holds two shapes of one token.
   */
  public function setStyleMap(array $map): void {
    parent::setStyleMap(static::canonicalizeLayer($map));
  }

  /**
   * Canonicalizes an authored map to this layer's stored shape.
   *
   * The package layer stores full records; the site layer (the sibling
   * type) stores the facts a record states.
   */
  protected static function canonicalizeLayer(array $map): array {
    return static::canonicalizeTokens($map);
  }

  /**
   * Canonicalizes an authored tokens map: every bare value becomes a record.
   *
   * The one door grammar every authored surface shares (the skins disk
   * lane, the blueprint field handler, the entity setter): a scale entry
   * that is not a map of names passes through verbatim (the validator
   * refuses it; canonicalization never silently drops a scale), and each
   * named token passes through canonicalizeToken().
   */
  public static function canonicalizeTokens(array $tokens): array {
    foreach ($tokens as $scale => $names) {
      if (!is_array($names) || array_is_list($names)) {
        continue;
      }
      foreach ($names as $name => $token) {
        $tokens[$scale][$name] = static::canonicalizeToken($token);
      }
    }
    return $tokens;
  }

  /**
   * Canonicalizes one authored token: a bare value becomes {value}.
   *
   * A map carrying a 'value' key IS a record (no condition is spelled
   * "value", and a bare condition map carries 'default' instead): its
   * missing facts fill with their defaults, its keys settle into stored
   * order, and any key outside RECORD_KEYS rides along for the validator
   * to refuse (INVALID_TOKEN_RECORD). Anything else is a bare value and
   * wraps: a string, a condition-keyed map, or a malformed value the
   * validator refuses at "{scale}.{name}.value".
   */
  public static function canonicalizeToken(mixed $token): array {
    if (is_array($token) && array_key_exists('value', $token)) {
      return [
        'value' => $token['value'],
        'description' => $token['description'] ?? '',
        'offered' => $token['offered'] ?? TRUE,
      ] + $token;
    }
    return ['value' => $token, 'description' => '', 'offered' => TRUE];
  }

  /**
   * Whether a stored token entry is a record.
   */
  public static function isRecord(mixed $token): bool {
    return is_array($token) && array_key_exists('value', $token);
  }

  /**
   * Projects a stored tokens map to its values: {scale: {name: value}}.
   *
   * The read every value-only consumer shares (the validator's minted
   * universe, usage scans, condition folds): the shape the delivery
   * variables and the correctives speak. Stored data is canonical by
   * construction, so an entry that is not a record is a corrupt store and
   * fails loudly rather than reading as a value.
   *
   * @throws \UnexpectedValueException
   *   When a scale entry holds a token that is not a record.
   */
  public static function values(array $tokens): array {
    $values = [];
    foreach ($tokens as $scale => $names) {
      if (!is_array($names) || array_is_list($names)) {
        continue;
      }
      $values[$scale] = [];
      foreach ($names as $name => $token) {
        if (!static::isRecord($token)) {
          throw new \UnexpectedValueException(sprintf('The stored token %s.%s is not a record; a skin stores {value, description, offered} per token.', $scale, $name));
        }
        $values[$scale][$name] = $token['value'];
      }
    }
    return $values;
  }

  /**
   * Projects a tokens map to the values it STATES: {scale: {name: value}}.
   *
   * The read that serves both layers: the package layer states every value
   * (values() is its strict twin), while a site layer record may change
   * one fact of a package token and state no value at all, so such a
   * record contributes nothing here. What is refused is still refused: an
   * entry that is not a record at all is a corrupt store. Usage scans and
   * reference rewrites read this, since they run over each layer as
   * stored.
   *
   * @throws \UnexpectedValueException
   *   When a scale entry holds a token that is not a record.
   */
  public static function statedValues(array $tokens): array {
    $values = [];
    foreach ($tokens as $scale => $names) {
      if (!is_array($names) || array_is_list($names)) {
        continue;
      }
      $values[$scale] = [];
      foreach ($names as $name => $token) {
        if (!is_array($token)) {
          throw new \UnexpectedValueException(sprintf('The stored token %s.%s is not a record; a skin stores {value, description, offered} per token.', $scale, $name));
        }
        if (array_key_exists('value', $token)) {
          $values[$scale][$name] = $token['value'];
        }
      }
    }
    return $values;
  }

  /**
   * Merges a skin's site layer over its package layer: records per key.
   *
   * The one read of a skin's tokens as worn. A site record over a package
   * token replaces the facts it states and keeps the rest (a site may
   * re-value a token, or only describe it or withhold it from rows,
   * without restating its value, and a package update to an unstated
   * fact shows through); a site record naming a token the package lacks
   * mints it, its unstated facts at their defaults. Order is package
   * order with the site's additions after, per scale and per name, the
   * order rows and the roster read. Both inputs are stores, canonical by
   * construction: a scale that is not a map of names, a site entry that
   * is not a record, or a site-minted token that states no value is
   * corrupt and fails loudly.
   *
   * @param array $package
   *   The package layer, full records (this type's store).
   * @param array $site
   *   The site layer, partial records (SkinSiteTokensItem's store).
   *
   * @return array
   *   {scale: {name: {value, description, offered}}}, full records.
   *
   * @throws \UnexpectedValueException
   *   On a corrupt layer.
   */
  public static function mergeLayers(array $package, array $site): array {
    $merged = $package;
    foreach ($site as $scale => $names) {
      if (!is_array($names) || array_is_list($names)) {
        throw new \UnexpectedValueException(sprintf('The site layer\'s "%s" scale is not a map of token names.', $scale));
      }
      $base = $merged[$scale] ?? [];
      if (!is_array($base) || array_is_list($base)) {
        throw new \UnexpectedValueException(sprintf('The package layer\'s "%s" scale is not a map of token names.', $scale));
      }
      foreach ($names as $name => $record) {
        if (!is_array($record)) {
          throw new \UnexpectedValueException(sprintf('The site token %s.%s is not a record; the site layer stores the facts it changes per token.', $scale, $name));
        }
        if (isset($base[$name]) && static::isRecord($base[$name])) {
          $base[$name] = static::canonicalizeToken($record + $base[$name]);
          continue;
        }
        if (!array_key_exists('value', $record)) {
          throw new \UnexpectedValueException(sprintf('The site token %s.%s states no value and the package mints no such token; a site-minted token carries its value.', $scale, $name));
        }
        $base[$name] = static::canonicalizeToken($record);
      }
      $merged[$scale] = $base;
    }
    return $merged;
  }

}
