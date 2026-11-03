<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A skin's SITE layer of animations: what the site added or changed, by name.
 *
 * The package layer (SkinAnimationsItem) is what a module ships and sync
 * writes; this layer is the site's own and holds PARTIAL records: a site
 * that re-steps a package animation stores {steps}, one that only
 * describes it stores {description}, one that withholds it from the AI
 * stores {offered: false}, and an animation the site mints stores
 * whatever facts it states. Unstated facts show through from the package.
 * SkinAnimationsItem::mergeLayers() is the read; SkinSiteTokensItem is
 * the tokens twin of this type and owns the sibling-type rationale.
 */
#[FieldType(
  id: 'daedalus_skin_site_animations',
  label: new TranslatableMarkup('Skin site animations'),
  description: new TranslatableMarkup('The animation facts a site added or changed over the animations its skin ships, keyed by animation name.'),
  no_ui: TRUE,
  cardinality: 1,
)]
class SkinSiteAnimationsItem extends SkinAnimationsItem {

  /**
   * {@inheritdoc}
   *
   * Bare steps become {steps} records, and a record keeps exactly the
   * facts it states.
   */
  protected static function canonicalizeLayer(array $map): array {
    return static::canonicalizeSiteAnimations($map);
  }

  /**
   * Canonicalizes an authored site layer: every bare steps map becomes {steps}.
   */
  public static function canonicalizeSiteAnimations(array $animations): array {
    foreach ($animations as $name => $animation) {
      if (is_array($animation)) {
        $animations[$name] = static::canonicalizeSiteAnimation($animation);
      }
    }
    return $animations;
  }

  /**
   * Canonicalizes one authored site animation: bare steps become {steps}.
   *
   * A map is a record when it carries a 'steps' key or when every key it
   * carries is a record fact (RECORD_KEYS): {offered: false} changes one
   * fact and states no steps, and no offset is spelled like a record
   * fact. A record's keys settle into stored order, its steps canonical,
   * and it keeps only the facts it states; a key outside RECORD_KEYS rides
   * along for the validator to refuse. Anything else is a bare steps map
   * and wraps.
   */
  public static function canonicalizeSiteAnimation(array $animation): array {
    if ($animation !== [] && (array_key_exists('steps', $animation) || array_diff(array_keys($animation), self::RECORD_KEYS) === [])) {
      $record = [];
      foreach (self::RECORD_KEYS as $key) {
        if (array_key_exists($key, $animation)) {
          $record[$key] = $key === 'steps' && is_array($animation[$key]) ? static::canonicalizeSteps($animation[$key]) : $animation[$key];
        }
      }
      return $record + $animation;
    }
    return ['steps' => static::canonicalizeSteps($animation)];
  }

}
