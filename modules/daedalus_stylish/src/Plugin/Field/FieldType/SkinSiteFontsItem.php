<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A skin's SITE layer of fonts: what the site added or changed, by name.
 *
 * The package layer (SkinFontsItem) is what a module ships and sync
 * writes; this layer is the site's own and holds PARTIAL records: a site
 * that swaps a package font's faces stores {faces}, one that only
 * describes it stores {description}, one that withholds it from the AI
 * stores {offered: false}, and a font the site mints stores whatever facts
 * it states (family and faces at least). Unstated facts show through from
 * the package. SkinFontsItem::mergeLayers() is the read; SkinSiteTokensItem
 * is the tokens twin of this type and owns the sibling-type rationale.
 */
#[FieldType(
  id: 'daedalus_skin_site_fonts',
  label: new TranslatableMarkup('Skin site fonts'),
  description: new TranslatableMarkup('The font facts a site added or changed over the fonts its skin ships, keyed by font name.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class SkinSiteFontsItem extends SkinFontsItem {

  /**
   * {@inheritdoc}
   *
   * A record keeps exactly the facts it states, its faces canonical.
   */
  protected static function canonicalizeLayer(array $map): array {
    return static::canonicalizeSiteFonts($map);
  }

  /**
   * Canonicalizes an authored site layer: every record keeps its stated facts.
   */
  public static function canonicalizeSiteFonts(array $fonts): array {
    foreach ($fonts as $name => $font) {
      if (is_array($font)) {
        $fonts[$name] = static::canonicalizeSiteFont($font);
      }
    }
    return $fonts;
  }

  /**
   * Canonicalizes one authored site font: stated facts only, faces settled.
   *
   * A record's keys settle into stored order, its family trimmed and its
   * faces canonical when they are a list, and it keeps only the facts it
   * states; a key outside RECORD_KEYS rides along for the validator to
   * refuse.
   */
  public static function canonicalizeSiteFont(array $font): array {
    $record = [];
    foreach (self::RECORD_KEYS as $key) {
      if (!array_key_exists($key, $font)) {
        continue;
      }
      $record[$key] = match ($key) {
        'family' => is_string($font[$key]) ? trim($font[$key]) : $font[$key],
        'faces' => is_array($font[$key]) && array_is_list($font[$key]) ? static::canonicalizeFaces($font[$key]) : $font[$key],
        default => $font[$key],
      };
    }
    return $record + $font;
  }

}
