<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A skin's SITE layer of tokens: what the site added or changed, by name.
 *
 * The package layer (SkinTokensItem) is what a module ships and sync
 * writes; this layer is the site's own and holds PARTIAL records: a site
 * that re-values a package token stores {value}, one that only describes
 * it stores {description}, one that withholds it from rows stores
 * {offered: false}, and a token the site mints stores whatever facts it
 * states. Unstated facts show through from the package, so a package
 * update to them lands on the site; removing a site entry reveals the
 * package's token whole. SkinTokensItem::mergeLayers() is the read.
 *
 * A sibling type rather than a setting on the package type, deliberately:
 * the store's grammar differs (partial records against full ones), and a
 * distinct type id is what keeps the blueprint's tokens handler, which
 * canonicalizes to FULL records and validates them, off this field. The
 * setter is still an authored door: a bare value is shorthand for {value}
 * (canonicalizeSiteTokens()), the shorthand every token door speaks.
 */
#[FieldType(
  id: 'daedalus_skin_site_tokens',
  label: new TranslatableMarkup('Skin site tokens'),
  description: new TranslatableMarkup('The token facts a site added or changed over the tokens its skin ships, keyed by token scale and token name.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class SkinSiteTokensItem extends SkinTokensItem {

  /**
   * {@inheritdoc}
   *
   * Bare values become {value} records, and a record keeps exactly the
   * facts it states.
   */
  protected static function canonicalizeLayer(array $map): array {
    return static::canonicalizeSiteTokens($map);
  }

  /**
   * Canonicalizes an authored site layer: every bare value becomes {value}.
   *
   * The site twin of canonicalizeTokens(): a scale entry that is not a
   * map of names passes through verbatim (never silently dropped), and
   * each named token passes through canonicalizeSiteToken().
   */
  public static function canonicalizeSiteTokens(array $tokens): array {
    foreach ($tokens as $scale => $names) {
      if (!is_array($names) || array_is_list($names)) {
        continue;
      }
      foreach ($names as $name => $token) {
        $tokens[$scale][$name] = static::canonicalizeSiteToken($token);
      }
    }
    return $tokens;
  }

  /**
   * Canonicalizes one authored site token: a bare value becomes {value}.
   *
   * A map is a record when it carries a 'value' key or when every key it
   * carries is a record fact (RECORD_KEYS): {offered: false} changes one
   * fact and states no value, and no condition is spelled like a record
   * fact (a bare condition map carries 'default'). A record's keys settle
   * into stored order and it keeps only the facts it states; a key
   * outside RECORD_KEYS rides along for the validator to refuse. Anything
   * else is a bare value and wraps.
   */
  public static function canonicalizeSiteToken(mixed $token): array {
    if (is_array($token) && $token !== [] && (array_key_exists('value', $token) || array_diff(array_keys($token), self::RECORD_KEYS) === [])) {
      $record = [];
      foreach (self::RECORD_KEYS as $key) {
        if (array_key_exists($key, $token)) {
          $record[$key] = $token[$key];
        }
      }
      return $record + $token;
    }
    return ['value' => $token];
  }

}
