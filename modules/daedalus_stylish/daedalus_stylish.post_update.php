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
 * accidental default (DaedalusStylishFieldManager never set the flag). New
 * fields are created untranslatable; this aligns fields created before the pin.
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
 * Every content entity revision and translation carrying a style map is walked
 * through daedalus_layout's transform spine: wide-carrying conditions fold into
 * their compound without the wide part (wide -> default, wide+dark -> dark, the
 * wide values winning per property), and the values that used to render at
 * 768-1023px spill into the lane's medium compound first, so that band keeps
 * rendering as before. Per-map details are logged to the daedalus_stylish
 * channel. Entity view displays hold layout structure only, never style data,
 * so there is no display walk.
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

/**
 * Syncs shipped variants and skins onto the position-qualifier grammar.
 *
 * Position is a qualifier now, not a condition: the shipped definitions'
 * disk sources speak the qualifier grammar (none ever shipped a fact
 * condition). Shipped-unmodified entities converge through the sync lane
 * here, BEFORE the convergence passes, so the in-place rewrite never
 * touches an unmodified shipped entity (which would flip its hash to
 * "modified" and cut it off from future updates).
 */
function daedalus_stylish_post_update_fact_condition_0001_sync_shipped(): void {
  $report = \Drupal::service('daedalus_stylish.shipped_sync')->sync();
  if ($report->hasFailures()) {
    throw new UpdateException(implode("\n", $report->failureLines()));
  }
}

/**
 * Moves host style maps' position-fact conditions onto qualified keys.
 *
 * Every content entity revision and translation carrying a style map is
 * walked through daedalus_layout's transform spine: an entry's
 * "first-child" block at "{key}" becomes the entry at "{key}::&:first-child",
 * a "not-last-child" block at "{key}::> *" the entry at
 * "{key}::> *:not(:last-child)", "odd" and "even" ":nth-child(odd|even)",
 * the posable remainder of a compound ("hover+first-child") staying the
 * block's condition. Per-map details are logged to the daedalus_stylish
 * channel.
 */
function daedalus_stylish_post_update_fact_condition_0002_hosts(&$sandbox) {
  if (!\Drupal::hasService('daedalus_layout.host_entity_walker')) {
    $sandbox['#finished'] = 1;
    return t('daedalus_layout is not installed; no style map stores to migrate.');
  }
  $saved = \Drupal::service('daedalus_layout.host_entity_walker')->walk($sandbox);
  if (!empty($sandbox['#finished']) && $sandbox['#finished'] >= 1) {
    return t('Moved position-fact conditions onto qualified keys in host style maps (all revisions and translations).');
  }
  return t('Converged @count host revisions in this batch.', ['@count' => $saved]);
}

/**
 * Moves style variants' position-fact conditions onto qualified keys.
 *
 * Across ALL revisions, composition slot paths and the entries carrying
 * nested references included. Shipped unmodified variants are skipped:
 * fact_condition_0001 synced their disk sources already.
 */
function daedalus_stylish_post_update_fact_condition_0003_variants() {
  $lines = \Drupal::service('daedalus_stylish.fact_condition_convergence')->convergeVariants();
  return t('Moved position-fact conditions onto qualified keys in style variants (@count report lines logged to the daedalus_stylish channel).', ['@count' => count($lines)]);
}

/**
 * Moves skin defaults' position-fact conditions onto qualified keys.
 *
 * Across ALL revisions. Shipped unmodified skins are skipped for the same
 * sync-lane reason as variants.
 */
function daedalus_stylish_post_update_fact_condition_0004_skins() {
  $lines = \Drupal::service('daedalus_stylish.fact_condition_convergence')->convergeSkins();
  return t('Moved position-fact conditions onto qualified keys in skin defaults (@count report lines logged to the daedalus_stylish channel).', ['@count' => count($lines)]);
}

/**
 * Drops host style map keys the style map key grammar rejects.
 *
 * Every content entity revision and translation carrying a style map is
 * walked through daedalus_layout's transform spine. A key outside the
 * grammar (an element type in a spelling the grammar no longer knows,
 * most often) prints nothing, and the stored styles validation refuses
 * it, so its host cannot be saved while it is there. Each such key is
 * deleted with its entry, and logged to the daedalus_stylish channel
 * with what the entry held. Nothing is rewritten and no page changes.
 */
function daedalus_stylish_post_update_key_grammar_0001_hosts(&$sandbox) {
  if (!\Drupal::hasService('daedalus_layout.host_entity_walker')) {
    $sandbox['#finished'] = 1;
    return t('daedalus_layout is not installed; no style map stores to converge.');
  }
  $saved = \Drupal::service('daedalus_layout.host_entity_walker')->walk($sandbox);
  if (!empty($sandbox['#finished']) && $sandbox['#finished'] >= 1) {
    return t('Dropped the keys outside the style map key grammar from host style maps (all revisions and translations); each dropped key is logged to the daedalus_stylish channel.');
  }
  return t('Converged @count host revisions in this batch.', ['@count' => $saved]);
}

/**
 * Drops style variant keys the variant style key grammar rejects.
 *
 * Across ALL revisions. A key whose "::" suffix is no canonical selector
 * prints nothing; it is deleted with its entry and logged. Shipped
 * unmodified variants are skipped: the sync lane owns them.
 */
function daedalus_stylish_post_update_key_grammar_0002_variants() {
  $lines = \Drupal::service('daedalus_stylish.key_grammar_convergence')->convergeVariants();
  return t('Dropped @count keys outside the variant style key grammar from style variants (each logged to the daedalus_stylish channel).', ['@count' => count($lines)]);
}

/**
 * Drops skin default keys the skin default key grammar rejects.
 *
 * Across ALL revisions, both layers. A default's key is its scope itself
 * or a selector under it; any other key prints nothing, and is deleted
 * with its entry and logged. Shipped unmodified package layers are
 * skipped: the sync lane owns them.
 */
function daedalus_stylish_post_update_key_grammar_0003_skins() {
  $lines = \Drupal::service('daedalus_stylish.key_grammar_convergence')->convergeSkins();
  return t('Dropped @count keys outside the skin default key grammar from skin defaults (each logged to the daedalus_stylish channel).', ['@count' => count($lines)]);
}

/**
 * Reports what the stored styles validation still refuses on a host.
 *
 * The passes before this one drop only what provably prints nothing. Any
 * other refusal (a malformed entry, a reference to a variant that is
 * gone) may sit on a key that does print, so it is left as it is, and
 * its host cannot be saved until someone repairs it. This pass reads the
 * current revision of every host and logs each refusal with its host and
 * the path inside the style map. It changes nothing.
 */
function daedalus_stylish_post_update_key_grammar_0004_report(&$sandbox) {
  $report = \Drupal::service('daedalus_stylish.stored_styles_report');
  $report->report($sandbox);
  if (empty($sandbox['#finished']) || $sandbox['#finished'] < 1) {
    return NULL;
  }
  $totals = $report->totals($sandbox);
  if ($totals['refusals'] === 0) {
    return t('The stored styles validation accepts the style map of every host (@hosts read); nothing was changed.', ['@hosts' => $totals['hosts']]);
  }
  return t('The stored styles validation refuses @count style map entries on @refused of @hosts hosts. Those hosts cannot be saved until the entries are repaired; each refusal is logged to the daedalus_stylish channel with its host and path. Nothing was changed.', [
    '@count' => $totals['refusals'],
    '@refused' => $totals['refused_hosts'],
    '@hosts' => $totals['hosts'],
  ]);
}
