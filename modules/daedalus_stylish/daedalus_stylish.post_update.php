<?php

/**
 * @file
 * Post update functions for Daedalus Stylish.
 */

declare(strict_types=1);

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Utility\UpdateException;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;

/**
 * Seeds the media_image_style site default for media-valued styles.
 *
 * Existing sites predate the key; an explicit empty string ("serve the
 * original file") keeps the stored config aligned with the schema and the
 * shipped install default.
 */
function daedalus_stylish_post_update_media_image_style_default(): void {
  $settings = \Drupal::configFactory()->getEditable('daedalus_stylish.settings');
  if ($settings->get('media_image_style') === NULL) {
    $settings->set('media_image_style', '')->save();
  }
}

/**
 * Pins existing daedalus_stylish_styles fields untranslatable.
 *
 * The style map keys into the layout tree, and core's layout model is one
 * structure shared by all translations, so a translatable style map was an
 * accidental default (DaedalusStylishFieldManager never set the flag). New fields
 * are created untranslatable; this aligns fields created before the pin.
 */
function daedalus_stylish_post_update_pin_styles_untranslatable(): void {
  foreach (FieldStorageConfig::loadMultiple() as $storage) {
    if ($storage->getName() !== DaedalusStylishFieldManager::FIELD_NAME || $storage->getType() !== 'daedalus_stylish_style_map') {
      continue;
    }
    if ($storage->isTranslatable()) {
      $storage->setTranslatable(FALSE);
      $storage->save();
    }
    foreach ($storage->getBundles() as $bundle) {
      $field = FieldConfig::loadByName($storage->getTargetEntityTypeId(), $bundle, DaedalusStylishFieldManager::FIELD_NAME);
      if ($field !== NULL && $field->isTranslatable()) {
        $field->setTranslatable(FALSE);
        $field->save();
      }
    }
  }
}

/**
 * Drops the retired mobile/tablet breakpoint thresholds.
 *
 * The mobile/tablet viewport conditions retired with the container model
 * (stored styles migrated to the narrow container condition), so their
 * thresholds are dead config the schema no longer describes.
 */
function daedalus_stylish_post_update_retire_media_breakpoints(): void {
  $settings = \Drupal::configFactory()->getEditable('daedalus_stylish.settings');
  $breakpoints = $settings->get('breakpoints');
  if (is_array($breakpoints) && (array_key_exists('mobile', $breakpoints) || array_key_exists('tablet', $breakpoints))) {
    unset($breakpoints['mobile'], $breakpoints['tablet']);
    $settings->set('breakpoints', $breakpoints)->save();
  }
}

/**
 * Reshapes the breakpoints config for the base-at-top width bands.
 *
 * The width vocabulary is now narrow / medium / base: "wide" is deleted
 * (the unconditioned base IS the full-width look) and "medium" covers the
 * former dead zone between narrow and wide. The stored config carries the
 * two band ceilings: medium ends where wide began (wide - 1), so base
 * keeps its lower edge exactly; narrow moves off the retired 640 default
 * to the shipped 767 (a site-chosen narrow threshold is kept). Content in
 * the 641-767 gap shifts from the base look to the narrow look, inherent
 * to the edge move. Stored style data under the "wide" condition is
 * migrated separately (the width-band style migration); until it runs,
 * unmigrated "wide" entries are inert, never fatal (the emit gate skips
 * unknown conditions).
 */
function daedalus_stylish_post_update_base_at_top_width_bands(): void {
  $settings = \Drupal::configFactory()->getEditable('daedalus_stylish.settings');
  $breakpoints = $settings->get('breakpoints');
  if (!is_array($breakpoints) || array_key_exists('medium', $breakpoints)) {
    return;
  }
  $narrow = (int) ($breakpoints['narrow'] ?? 640);
  $wide = (int) ($breakpoints['wide'] ?? 1024);
  $settings->set('breakpoints', [
    'narrow' => $narrow === 640 ? 767 : $narrow,
    'medium' => $wide - 1,
  ])->save();
}

/**
 * Folds "wide" style conditions into base on host entities.
 *
 * Every content entity revision and translation carrying a style map is
 * walked through daedalus_layout's transform spine: wide-carrying conditions fold
 * into their compound without the wide part (wide -> default, wide+dark ->
 * dark, the wide values winning per property), and the values that used to
 * render at 768-1023px spill into the lane's medium compound first, so
 * that band keeps rendering as before. Per-map details are logged to the
 * daedalus_stylish channel. Entity view displays hold layout structure only, never
 * style data, so there is no display walk.
 */
function daedalus_stylish_post_update_width_bands_0001_hosts(&$sandbox) {
  if (!\Drupal::hasService('daedalus_layout.host_entity_walker')) {
    $sandbox['#finished'] = 1;
    return t('daedalus_layout is not installed; no style map stores to migrate.');
  }
  $saved = \Drupal::service('daedalus_layout.host_entity_walker')->walk($sandbox);
  if (!empty($sandbox['#finished']) && $sandbox['#finished'] >= 1) {
    return t('Folded wide style conditions into base on host entities (all revisions and translations).');
  }
  return t('Folded wide conditions on @count host revisions in this batch.', ['@count' => $saved]);
}

/**
 * Folds "wide" style conditions into base on daedalus_variant entities.
 *
 * Across ALL revisions (workspace-staged and draft revisions included), in
 * place: a grammar migration is not an editorial change.
 */
function daedalus_stylish_post_update_width_bands_0002_variants() {
  $lines = \Drupal::service('daedalus_stylish.width_band_migration')->migrateVariants();
  return t('Folded wide style conditions into base on style variants (@count report lines logged to the daedalus_stylish channel).', ['@count' => count($lines)]);
}

/**
 * Folds "wide" conditions into base in skin tokens and defaults.
 *
 * Across ALL revisions. The container-model migration never covered skins;
 * this one must, because skins can store width-conditioned token values
 * and width-conditioned site default styles.
 */
function daedalus_stylish_post_update_width_bands_0003_skins() {
  $lines = \Drupal::service('daedalus_stylish.width_band_migration')->migrateSkins();
  return t('Folded wide conditions into base on skins (@count report lines logged to the daedalus_stylish channel).', ['@count' => count($lines)]);
}

/**
 * Seeds the ai_minting governance default for the palette.
 *
 * Existing sites predate the key; an explicit TRUE preserves their
 * behavior (the blueprint mint lane was open, gated only by entity
 * create access) and aligns stored config with the schema and the
 * shipped install default.
 */
function daedalus_stylish_post_update_ai_minting_default(): void {
  $settings = \Drupal::configFactory()->getEditable('daedalus_stylish.settings');
  if ($settings->get('ai_minting') === NULL) {
    $settings->set('ai_minting', TRUE)->save();
  }
}

/**
 * Syncs shipped variants and skins onto the relative selector grammar.
 *
 * The suffix after a key's "::" is a relative selector in one canonical
 * spelling now: the shipped row variant's children entry was rewritten on
 * disk from '::>' to '::> *'. Shipped-unmodified entities converge through
 * the sync lane here, BEFORE the convergence passes, so the in-place
 * rewrite never touches an unmodified shipped entity (which would flip
 * its hash to "modified" and cut it off from future updates).
 */
function daedalus_stylish_post_update_relative_selector_0001_sync_shipped(): void {
  $report = \Drupal::service('daedalus_stylish.shipped_sync')->sync();
  if ($report->hasFailures()) {
    throw new UpdateException(implode("\n", $report->failureLines()));
  }
}

/**
 * Converges host style maps onto the relative selector grammar.
 *
 * Every content entity revision and translation carrying a style map is
 * walked through daedalus_layout's transform spine: the retired "::>"
 * children spelling ("{key}::>", "{key}::.cls::>", "{key}::p::>") becomes
 * the selector it means ("::> *", "::.cls > *", "::p > *"). Per-map
 * details are logged to the daedalus_stylish channel.
 */
function daedalus_stylish_post_update_relative_selector_0002_hosts(&$sandbox) {
  if (!\Drupal::hasService('daedalus_layout.host_entity_walker')) {
    $sandbox['#finished'] = 1;
    return t('daedalus_layout is not installed; no style map stores to migrate.');
  }
  $saved = \Drupal::service('daedalus_layout.host_entity_walker')->walk($sandbox);
  if (!empty($sandbox['#finished']) && $sandbox['#finished'] >= 1) {
    return t('Converged host style maps onto the relative selector grammar (all revisions and translations).');
  }
  return t('Converged @count host revisions in this batch.', ['@count' => $saved]);
}

/**
 * Converges style variants onto the relative selector grammar.
 *
 * Across ALL revisions, composition slot paths and fragment references
 * included. Shipped unmodified variants are skipped: relative_selector_0001
 * synced their rewritten disk sources already.
 */
function daedalus_stylish_post_update_relative_selector_0003_variants() {
  $lines = \Drupal::service('daedalus_stylish.relative_selector_convergence')->convergeVariants();
  return t('Converged style variants onto the relative selector grammar (@count report lines logged to the daedalus_stylish channel).', ['@count' => count($lines)]);
}

/**
 * Converges skin defaults onto the relative selector grammar.
 *
 * Across ALL revisions. Shipped unmodified skins are skipped for the same
 * sync-lane reason as variants.
 */
function daedalus_stylish_post_update_relative_selector_0004_skins() {
  $lines = \Drupal::service('daedalus_stylish.relative_selector_convergence')->convergeSkins();
  return t('Converged skin defaults onto the relative selector grammar (@count report lines logged to the daedalus_stylish channel).', ['@count' => count($lines)]);
}
