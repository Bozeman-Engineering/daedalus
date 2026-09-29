<?php

/**
 * @file
 * Post update functions for Daedalus Stylish Layouts.
 */

declare(strict_types=1);

use Drupal\Core\Utility\UpdateException;
use Drupal\daedalus_stylish_layouts\ContainerMigration;

/**
 * Syncs shipped definitions for an update, failing the update on any failure.
 *
 * The orchestrated sync is tolerant (every definition of every kind is
 * attempted, failures ride the report); an update hook that needs the
 * shipped families in place must still stop loudly when one did not land,
 * so the report's failure lines become the update's error.
 *
 * @param string[]|null $modules
 *   Modules to sync, or NULL for every enabled module.
 *
 * @throws \Drupal\Core\Utility\UpdateException
 *   When any shipped definition failed to sync.
 */
function _daedalus_stylish_layouts_sync_shipped(?array $modules = NULL): void {
  $report = \Drupal::service('daedalus_stylish.shipped_sync')->sync($modules);
  if ($report->hasFailures()) {
    throw new UpdateException(implode("\n", $report->failureLines()));
  }
}

/**
 * Imports the arrangement axis's shipped row variant (mode + share).
 *
 * Written when shipped variants synced on module install and by command
 * only: sites that installed daedalus_stylish_layouts before the arrangement axis
 * needed one explicit sync to receive the kind-scoped row variant the
 * layout widget's Columns mode wears. Every cache rebuild syncs shipped
 * definitions now (ShippedSync); the update stays for the sites still
 * carrying it and fails loudly if the sync does. Idempotent with the
 * earlier sync post updates ("one hash, two comparisons": existing
 * families are untouched, row imports as new).
 */
function daedalus_stylish_layouts_post_update_arrangement_row_variant(): void {
  _daedalus_stylish_layouts_sync_shipped(['daedalus_stylish_layouts']);
}

/**
 * Imports the generic container variant families (split_*, container_*).
 *
 * Written when shipped variants synced on module install and by command
 * only: sites that installed daedalus_stylish_layouts before the container model
 * needed one explicit sync to receive the kind-scoped proportions and
 * width families the daedalus_stylish_container layout's defaults reference. Every
 * cache rebuild syncs shipped definitions now (ShippedSync); the update
 * stays for the sites still carrying it.
 */
function daedalus_stylish_layouts_post_update_container_variants(): void {
  _daedalus_stylish_layouts_sync_shipped(['daedalus_stylish_layouts']);
}

/**
 * Syncs the generic variant families before the container migration.
 *
 * The migration re-points layout-exact variant references (twocol_lg,
 * fourcol_equal, ...) to the generic kind:container families, so those
 * variants must exist first. Idempotent with
 * daedalus_stylish_layouts_post_update_container_variants(); repeated here because
 * post updates run alphabetically across modules and this migrate_000N
 * sequence must be self-sufficient.
 */
function daedalus_stylish_layouts_post_update_migrate_0001_sync_variants(): void {
  _daedalus_stylish_layouts_sync_shipped(['daedalus_stylish_layouts']);
}

/**
 * Converts multi-region sections to containers on host entities.
 *
 * Every content entity revision and translation carrying layout sections
 * is walked; daedalus_stylish_onecol/twocol/threecol/fourcol sections become
 * daedalus_stylish_container sections (populated regions -> minted child
 * containers, region order preserved) and the host style maps follow
 * (section: -> container: keys, region entries onto the minted children,
 * mobile/tablet -> narrow, layout-exact variant references re-pointed).
 * Per-map details are logged to the daedalus_stylish_layouts channel.
 */
function daedalus_stylish_layouts_post_update_migrate_0002_container_hosts(&$sandbox) {
  if (!\Drupal::hasService('daedalus_layout.host_entity_walker')) {
    $sandbox['#finished'] = 1;
    return t('daedalus_layout is not installed; no section stores to migrate.');
  }
  $saved = \Drupal::service('daedalus_layout.host_entity_walker')->walk($sandbox);
  if (!empty($sandbox['#finished']) && $sandbox['#finished'] >= 1) {
    return t('Converted multi-region sections to containers on host entities (all revisions and translations).');
  }
  return t('Converted sections on @count host revisions in this batch.', ['@count' => $saved]);
}

/**
 * Converts multi-region sections to containers in default layouts.
 */
function daedalus_stylish_layouts_post_update_migrate_0003_container_displays(&$sandbox) {
  if (!\Drupal::hasService('daedalus_layout.entity_view_display_walker')) {
    $sandbox['#finished'] = 1;
    return t('daedalus_layout is not installed; no display defaults to migrate.');
  }
  $saved = \Drupal::service('daedalus_layout.entity_view_display_walker')->walk($sandbox);
  if (!empty($sandbox['#finished']) && $sandbox['#finished'] >= 1) {
    return t('Converted multi-region sections to containers in Layout Builder default layouts.');
  }
  return t('Converted sections on @count displays in this batch.', ['@count' => $saved]);
}

/**
 * Migrates daedalus_variant entities to the container grammar.
 *
 * The kind:section and layout:daedalus_stylish_* scopes become kind:container, slot
 * paths lose their region()/section() segments, and mobile/tablet
 * conditions become narrow - across ALL revisions. Unmodified shipped
 * daedalus_stylish_layouts variants are left alone: references re-point to the
 * generic families and they retire with the four layouts.
 */
function daedalus_stylish_layouts_post_update_migrate_0004_variants() {
  $lines = \Drupal::service('daedalus_stylish_layouts.container_migration')->migrateVariants();
  return t('Migrated style variants to the container grammar (@count report lines logged to the daedalus_stylish_layouts channel).', ['@count' => count($lines)]);
}

/**
 * Migrates stored stencils to the container grammar.
 *
 * Multi-region daedalus_stylish section nodes become container nodes, region:NAME
 * relative styles move onto the minted child nodes, retired conditions and
 * layout-exact variant names rewrite. File-shipped stencils are module
 * code and are not touched.
 */
function daedalus_stylish_layouts_post_update_migrate_0005_stencils() {
  $lines = \Drupal::service('daedalus_stylish_layouts.container_migration')->migrateStencils();
  return t('Migrated stored stencils to the container grammar (@count report lines logged to the daedalus_stylish_layouts channel).', ['@count' => count($lines)]);
}

/**
 * Deletes the retired shipped layout-exact variants (onecol_*..fourcol_*).
 *
 * The four multi-region layouts are gone and every reference re-pointed to
 * the generic kind:container families in migrate_0002..0005, so unmodified
 * shipped copies are dead weight. Modified copies carry user changes and
 * stay (already migrated to the container grammar by migrate_0004).
 */
function daedalus_stylish_layouts_post_update_migrate_0006_retire_layout_variants() {
  $lines = \Drupal::service('daedalus_stylish_layouts.container_migration')->retireShippedLayoutVariants();
  return t('Retired the shipped layout-exact variants (@count report lines logged to the daedalus_stylish_layouts channel).', ['@count' => count($lines)]);
}

/**
 * Converts the proportions grammar to mode + share on host entities.
 *
 * Worn split_* references and stepper-written explicit track overrides
 * become one `row` arrangement reference plus per-child flex-grow
 * overrides (the frozen ratios, children in order); track styles outside
 * the retired stepper's own minmax pattern stay Custom, loudly. The row
 * variant synced in daedalus_stylish_layouts_post_update_arrangement_row_variant()
 * (a < m, so it always runs first). Per-map details are logged to the
 * daedalus_stylish_layouts channel.
 */
function daedalus_stylish_layouts_post_update_migrate_0007_row_arrangement(&$sandbox) {
  if (!\Drupal::hasService('daedalus_layout.host_entity_walker')) {
    $sandbox['#finished'] = 1;
    return t('daedalus_layout is not installed; no section stores to migrate.');
  }
  $saved = \Drupal::service('daedalus_layout.host_entity_walker')->walk($sandbox);
  if (!empty($sandbox['#finished']) && $sandbox['#finished'] >= 1) {
    return t('Converted split_* references and stepper tracks to the row arrangement on host entities (all revisions and translations).');
  }
  return t('Converted @count host revisions in this batch.', ['@count' => $saved]);
}

/**
 * Converts stored stencils' proportions grammar to mode + share.
 *
 * Container stencil nodes naming split_* variants (or carrying stepper
 * tracks) convert exactly like host style maps: the `row` name plus
 * flex-grow on the child nodes in order. File-shipped stencils are module
 * code and are not touched.
 */
function daedalus_stylish_layouts_post_update_migrate_0008_row_arrangement_stencils() {
  $lines = \Drupal::service('daedalus_stylish_layouts.container_migration')->migrateStencils();
  return t('Converted stored stencils to the row arrangement (@count report lines logged to the daedalus_stylish_layouts channel).', ['@count' => count($lines)]);
}

/**
 * Retires the shipped split_* proportions variants and their axis.
 *
 * Every reference converted to the row arrangement in migrate_0007/0008,
 * so unmodified shipped copies are dead weight and delete (the
 * migrate_0006 precedent); modified copies carry user changes and live on
 * as ordinary variants. Surviving kind:container variants still on the
 * retired proportions axis move to the built-in style axis so they stay
 * saveable.
 */
function daedalus_stylish_layouts_post_update_migrate_0009_retire_split_variants() {
  $lines = \Drupal::service('daedalus_stylish_layouts.container_migration')->retireShippedSplitVariants();
  return t('Retired the shipped split_* proportions variants (@count report lines logged to the daedalus_stylish_layouts channel).', ['@count' => count($lines)]);
}

/**
 * Syncs the width family's shipped weights (widest first).
 *
 * The width variants now ship sort weights ordering the family Full width
 * down to Small (rosters sort weight-then-label), replacing the
 * alphabetical accident of the labels. Weight syncs independently of the
 * styles hash, but only on sync runs: installed sites need this one
 * explicit sync to converge on the shipped order.
 */
function daedalus_stylish_layouts_post_update_width_variant_weights(): void {
  _daedalus_stylish_layouts_sync_shipped(['daedalus_stylish_layouts']);
}

/**
 * Renames the arrangement axis to container_layout (label "Layout").
 *
 * The wear bar row now echoes the layout widget's own words: clicking
 * Columns in the Layout section reads back as "Layout: Columns", not
 * "Arrangement: Row" (the flex implementation leaking into editor
 * vocabulary). Stored variants on the arrangement axis move to
 * container_layout (the axis is non-revisionable identity, one save moves
 * every revision), and the shipped row variant relabels "Columns" here
 * because the sync lane cannot deliver a label/axis-only change (the
 * source hash covers styles only). Machine names (the `row` variant, the
 * AI recipes) are unchanged; style maps store axis-less references, so no
 * map rewrite exists.
 */
function daedalus_stylish_layouts_post_update_container_layout_axis() {
  $lines = \Drupal::service('daedalus_stylish_layouts.container_migration')->migrateContainerLayoutAxis();
  return t('Renamed the arrangement axis to container_layout (@count report lines logged to the daedalus_stylish_layouts channel).', ['@count' => count($lines)]);
}

/**
 * Syncs shipped variants and skins onto the one-box root key.
 *
 * The container is one element now: the shipped width and row variants and
 * the shipped skins' kind:container defaults were rewritten on disk to the
 * root key ('' / '::>', padding-inline width stops). Shipped-unmodified
 * entities converge through the sync lane here, BEFORE the hoist passes,
 * so the in-place hoist never rewrites an unmodified shipped entity (which
 * would flip its hash to "modified" and cut it off from future updates).
 */
function daedalus_stylish_layouts_post_update_one_box_0001_sync_shipped(): void {
  _daedalus_stylish_layouts_sync_shipped();
}

/**
 * Hoists container part styles onto the root key on host entities.
 *
 * Every content entity revision and translation carrying a style map is
 * walked through daedalus_layout's transform spine: the retired
 * `::.daedalus-stylish-layout-container` part entries (and their `::>` children
 * entries) merge onto the container's root key - the one element a
 * container is now. Per-map details are logged to the daedalus_stylish_layouts
 * channel. Entity view displays hold layout structure only, never style
 * data, so there is no display walk.
 */
function daedalus_stylish_layouts_post_update_one_box_0002_hoist_hosts(&$sandbox) {
  if (!\Drupal::hasService('daedalus_layout.host_entity_walker')) {
    $sandbox['#finished'] = 1;
    return t('daedalus_layout is not installed; no style map stores to migrate.');
  }
  $saved = \Drupal::service('daedalus_layout.host_entity_walker')->walk($sandbox);
  if (!empty($sandbox['#finished']) && $sandbox['#finished'] >= 1) {
    return t('Hoisted container part styles onto the root key on host entities (all revisions and translations).');
  }
  return t('Hoisted part styles on @count host revisions in this batch.', ['@count' => $saved]);
}

/**
 * Hoists container part styles onto the root key in style variants.
 *
 * Across ALL revisions, composition slot paths included. Shipped
 * unmodified variants are skipped: one_box_0001 synced their rewritten
 * disk sources already.
 */
function daedalus_stylish_layouts_post_update_one_box_0003_hoist_variants() {
  $lines = \Drupal::service('daedalus_stylish_layouts.container_part_hoist')->hoistVariants();
  return t('Hoisted container part styles onto the root key in style variants (@count report lines logged to the daedalus_stylish_layouts channel).', ['@count' => count($lines)]);
}

/**
 * Hoists container part defaults onto the root key in skins.
 *
 * Across ALL revisions. Shipped unmodified skins are skipped: one_box_0001
 * synced their rewritten disk sources already.
 */
function daedalus_stylish_layouts_post_update_one_box_0004_hoist_skins() {
  $lines = \Drupal::service('daedalus_stylish_layouts.container_part_hoist')->hoistSkins();
  return t('Hoisted container part defaults onto the root key in skins (@count report lines logged to the daedalus_stylish_layouts channel).', ['@count' => count($lines)]);
}

/**
 * Hoists container part styles onto the root key in stored stencils.
 *
 * At every node depth. File-shipped stencils are module code and are not
 * touched; one still carrying the part key fails loudly at instantiate
 * time.
 */
function daedalus_stylish_layouts_post_update_one_box_0005_hoist_stencils() {
  $lines = \Drupal::service('daedalus_stylish_layouts.container_part_hoist')->hoistStencils();
  return t('Hoisted container part styles onto the root key in stored stencils (@count report lines logged to the daedalus_stylish_layouts channel).', ['@count' => count($lines)]);
}

/**
 * Renames the shipped Columns variant from `row` to `columns`.
 *
 * A variant named `row` that means Columns misleads the AI (apply_variant
 * row) and every map reader once a real Row arrangement exists on the
 * same axis, so the shipped variant ships under `columns` now and `row`
 * is free for Row. The order is forced by the materials roster: two
 * published variants on one axis cannot spell the same label slug, so
 * `columns` cannot land beside the legacy `row` (the rebuild before this
 * update refuses it on the sync report, loudly but harmlessly). First the
 * unmodified shipped `row` entity retires (a modified one is kept and
 * reported; the sync then refuses `columns` and this update fails loudly
 * until the developer relabels or reverts it), then the module's shipped
 * definitions sync so `columns` lands (and, once Row ships, `row` lands
 * clean under its own label). Only then every stored `row` wearing is
 * repointed: host style maps (every revision and translation, through
 * daedalus_layout's host entity walker with a sweep that is deliberately
 * not registered, since a permanent row-to-columns sweep would eat Row
 * wearings), stored stencils and variant styles (composition variants
 * nesting the name; shipped-unmodified ones ride the sync lane).
 */
function daedalus_stylish_layouts_post_update_rename_row_to_columns(&$sandbox) {
  $from = 'row';
  $to = ContainerMigration::COLUMNS_VARIANT;
  $rename = \Drupal::service('daedalus_stylish_layouts.variant_rename');
  if (!isset($sandbox['daedalus_stylish_layouts_rename_lines'])) {
    $sandbox['daedalus_stylish_layouts_rename_lines'] = count($rename->retireShipped($from, 'daedalus_stylish_layouts'));
    _daedalus_stylish_layouts_sync_shipped(['daedalus_stylish_layouts']);
  }
  if (\Drupal::hasService('daedalus_layout.host_entity_walker')) {
    $saved = $rename->walkHosts($sandbox, $from, $to);
    if (empty($sandbox['#finished']) || $sandbox['#finished'] < 1) {
      return t('Repointed the "@from" wearing to "@to" on @count host revisions in this batch.', ['@from' => $from, '@to' => $to, '@count' => $saved]);
    }
  }
  $count = $sandbox['daedalus_stylish_layouts_rename_lines']
    + count($rename->renameInStencils($from, $to))
    + count($rename->renameInVariants($from, $to));
  $sandbox['#finished'] = 1;
  return t('Renamed the shipped Columns variant from "@from" to "@to" across host maps, stored stencils and variant styles (the host repoints logged per revision; @count retirement, stencil and variant report lines logged to the daedalus_stylish_layouts channel).', ['@from' => $from, '@to' => $to, '@count' => $count]);
}
