<?php

/**
 * @file
 * Post update functions for daedalus_layout.
 *
 * The 000N name prefixes are load-bearing: post updates execute in
 * alphabetical order across ALL modules (UpdateRegistry sorts the pending
 * function list), so the layout-block migration sequence below (rewrite
 * hosts, rewrite display defaults, garbage-collect the orphaned entities,
 * then retire the module and bundle) orders itself by name.
 */

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;

/**
 * Converts legacy layout blocks on host entities to nested sections.
 *
 * Every content entity revision and translation carrying layout sections is
 * walked; inline_block:layout_block components are rewritten in place to
 * nested-section container components at any depth (see
 * LayoutBlockToSectionTransform). Re-saving each rewritten host also runs
 * the inline-block presave machinery, which re-points the usage rows of
 * inline blocks that lived inside layout blocks at the ROOT host, the
 * step that makes the garbage collection below safe.
 */
function daedalus_layout_post_update_0001_migrate_layout_blocks_hosts(&$sandbox) {
  $saved = \Drupal::service('daedalus_layout.host_entity_walker')->walk($sandbox);
  if (!empty($sandbox['#finished']) && $sandbox['#finished'] >= 1) {
    return t('Converted legacy layout blocks to nested sections on host entities (all revisions and translations).');
  }
  return t('Converted layout blocks on @count host revisions in this batch.', ['@count' => $saved]);
}

/**
 * Converts legacy layout blocks in default layouts to nested sections.
 */
function daedalus_layout_post_update_0002_migrate_layout_blocks_displays(&$sandbox) {
  $saved = \Drupal::service('daedalus_layout.entity_view_display_walker')->walk($sandbox);
  if (!empty($sandbox['#finished']) && $sandbox['#finished'] >= 1) {
    return t('Converted legacy layout blocks to nested sections in Layout Builder default layouts.');
  }
  return t('Converted layout blocks on @count displays in this batch.', ['@count' => $saved]);
}

/**
 * Deletes the orphaned layout_block block_content entities.
 *
 * After the store walks above, no layout references a layout_block entity:
 * their section lists live embedded in container components now. The
 * migration deliberately did not delete them inline (other not-yet-walked
 * host revisions still referenced other revisions of the same entity), so
 * this pass deletes every ORPHANED layout_block entity with its usage rows:
 * core never garbage-collects blocks removed from revisionable hosts.
 *
 * Not every layout_block entity is a nested-model orphan, though. A
 * reusable layout_block, or one a block config entity places in a theme
 * region (e.g. a site footer placed via /admin/structure/block), is a
 * standalone page-builder root under the current model and is kept: deleting
 * it would leave block.block.* placements pointing at nothing and destroy
 * live site content the walkers above never touched.
 */
function daedalus_layout_post_update_0003_layout_block_gc(&$sandbox) {
  if (!\Drupal::entityTypeManager()->hasDefinition('block_content')) {
    $sandbox['#finished'] = 1;
    return t('block_content is not installed; no layout_block entities exist.');
  }
  $storage = \Drupal::entityTypeManager()->getStorage('block_content');
  if (!isset($sandbox['total'])) {
    $sandbox['total'] = (int) $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'layout_block')
      ->count()
      ->execute();
    $sandbox['deleted'] = 0;
    $sandbox['kept'] = 0;
    $sandbox['last_id'] = 0;
    // The uuids of every block_content entity a block config entity places
    // (block_content:{uuid} plugins): those entities are live site content,
    // not migration orphans.
    $sandbox['placed_uuids'] = [];
    if (\Drupal::entityTypeManager()->hasDefinition('block')) {
      foreach (\Drupal::entityTypeManager()->getStorage('block')->loadMultiple() as $block) {
        $plugin_id = $block->getPluginId();
        if (str_starts_with($plugin_id, 'block_content:')) {
          $sandbox['placed_uuids'][] = substr($plugin_id, strlen('block_content:'));
        }
      }
    }
  }

  // Paginate by id (rather than re-querying the head) so kept entities do
  // not make the batch spin on the same page forever.
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'layout_block')
    ->condition('id', $sandbox['last_id'], '>')
    ->sort('id')
    ->range(0, 50)
    ->execute();
  if ($ids !== []) {
    $sandbox['last_id'] = max(array_map('intval', $ids));
    $delete = [];
    foreach ($storage->loadMultiple($ids) as $layout_block) {
      if ($layout_block->isReusable() || in_array($layout_block->uuid(), $sandbox['placed_uuids'], TRUE)) {
        $sandbox['kept']++;
        continue;
      }
      $delete[$layout_block->id()] = $layout_block;
    }
    if ($delete !== []) {
      \Drupal::service('inline_block.usage')->deleteUsage(array_keys($delete));
      $storage->delete($delete);
      $sandbox['deleted'] += count($delete);
    }
  }

  $processed = $sandbox['deleted'] + $sandbox['kept'];
  $sandbox['#finished'] = $ids === [] ? 1 : ($sandbox['total'] > 0 ? min($processed / $sandbox['total'], 0.99) : 1);
  if ($sandbox['#finished'] >= 1) {
    return t('Deleted @count orphaned layout_block entities (usage rows included); kept @kept reusable/region-placed entities.', [
      '@count' => $sandbox['deleted'],
      '@kept' => $sandbox['kept'],
    ]);
  }
}

/**
 * Retires the daedalus_edit_layout_block module and the layout_block bundle.
 *
 * Uninstalls the module when its code is still present, or purges its
 * core.extension/system.schema records when the code was already removed
 * (the module is deleted from the codebase in the same release as this
 * update). Then deletes the bundle and its displays (install-provided
 * config carries no enforced module dependency, so uninstalling alone
 * leaves it behind) and strips the inline_block:layout_block icon entry
 * from every display's daedalus_layout block_config setting. Field config
 * cascades with the bundle (field_entity_bundle_delete()), and the shared
 * layout_builder__layout
 * storage on block_content auto-deletes with its last field
 * (persist_with_no_fields: false).
 *
 * When the GC above kept reusable/region-placed layout_block entities, the
 * bundle, its displays, and the field stay: deleting them would orphan the
 * surviving entities and PURGE their layout field data, exactly the loss the
 * GC skip exists to prevent. The module retirement and the icon strip
 * (nested-model concerns) proceed either way.
 */
function daedalus_layout_post_update_0004_remove_daedalus_edit_layout_block() {
  $entity_type_manager = \Drupal::entityTypeManager();

  // The icon entry first: it is a plain third-party setting, valid to
  // rewrite whether or not the module/bundle still exist.
  $display_storage = $entity_type_manager->getStorage('entity_view_display');
  foreach ($display_storage->loadMultiple() as $display) {
    if (!$display instanceof ThirdPartySettingsInterface) {
      continue;
    }
    $block_config = $display->getThirdPartySetting('daedalus_layout', 'block_config');
    if (is_array($block_config) && isset($block_config['icon']['inline_block:layout_block'])) {
      unset($block_config['icon']['inline_block:layout_block']);
      $display->setThirdPartySetting('daedalus_layout', 'block_config', $block_config);
      $display->save();
    }
  }

  $extension_config = \Drupal::configFactory()->getEditable('core.extension');
  if ($extension_config->get('module.daedalus_edit_layout_block') !== NULL) {
    if (\Drupal::service('extension.list.module')->exists('daedalus_edit_layout_block')) {
      \Drupal::service('module_installer')->uninstall(['daedalus_edit_layout_block']);
    }
    else {
      // The code is already gone; purge the bookkeeping a normal uninstall
      // would have cleaned up.
      $extension_config->clear('module.daedalus_edit_layout_block')->save();
      \Drupal::keyValue('system.schema')->delete('daedalus_edit_layout_block');
      $post_update = \Drupal::keyValue('post_update');
      $existing = $post_update->get('existing_updates', []);
      $post_update->set('existing_updates', array_values(array_filter(
        $existing,
        static fn (string $function) => !str_starts_with($function, 'daedalus_edit_layout_block_post_update_'),
      )));
    }
  }

  // Surviving layout_block entities (reusable/region-placed roots the GC
  // kept) still need the bundle, its displays, and the field: deleting those
  // would orphan the entities and purge their layout field data.
  if ($entity_type_manager->hasDefinition('block_content')) {
    $remaining = (int) $entity_type_manager->getStorage('block_content')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'layout_block')
      ->count()
      ->execute();
    if ($remaining > 0) {
      return t('Retired the daedalus_edit_layout_block module and removed Add panel references; kept the layout_block bundle, displays, and field for @count surviving entities.', ['@count' => $remaining]);
    }
  }

  // The displays before the bundle: deleting the bundle first would orphan
  // them as dangling config.
  foreach (['entity_view_display', 'entity_form_display'] as $display_type) {
    $entity_type_manager->getStorage($display_type)->load('block_content.layout_block.default')?->delete();
  }
  if ($entity_type_manager->hasDefinition('block_content_type')) {
    BlockContentType::load('layout_block')?->delete();
  }

  return t('Retired the daedalus_edit_layout_block module: bundle, displays, field config, and Add panel references removed.');
}

/**
 * Moves Add panel block icons from entity view displays onto block types.
 *
 * Block icons were per-display config
 * (`third_party_settings.daedalus_layout.block_config.icon.<block_plugin_id>`); the
 * icon is a property of the block TYPE now
 * (`block_content.type.*.third_party.daedalus_layout.icon`, in the icon value
 * grammar \Drupal\daedalus_blueprint\IconResolver documents; stored path
 * strings are valid grammar values and migrate verbatim). Every display's
 * icon map is walked: inline_block icons land on their block type unless
 * the type already carries one (a configured icon is never overwritten, and
 * across displays the first-written value wins, matching the old model
 * where any variance between displays was accidental). Entries for other
 * plugin ids or missing bundles have no block type to carry them and are
 * dropped: the Add panel renders its default icon for those. The retired
 * `block_config` setting is deleted from each display either way; this
 * update and its test are the only place the old shape survives.
 */
function daedalus_layout_post_update_0005_block_icons_to_block_types() {
  $entity_type_manager = \Drupal::entityTypeManager();
  $block_type_storage = $entity_type_manager->hasDefinition('block_content_type')
    ? $entity_type_manager->getStorage('block_content_type')
    : NULL;

  foreach ($entity_type_manager->getStorage('entity_view_display')->loadMultiple() as $display) {
    if (!$display instanceof ThirdPartySettingsInterface) {
      continue;
    }
    $block_config = $display->getThirdPartySetting('daedalus_layout', 'block_config');
    if ($block_config === NULL) {
      continue;
    }
    foreach ($block_config['icon'] ?? [] as $block_plugin_id => $icon) {
      if (!is_string($icon) || $icon === '' || !str_starts_with($block_plugin_id, 'inline_block:')) {
        continue;
      }
      $block_type = $block_type_storage?->load(substr($block_plugin_id, strlen('inline_block:')));
      if ($block_type === NULL || $block_type->getThirdPartySetting('daedalus_layout', 'icon') !== NULL) {
        continue;
      }
      $block_type->setThirdPartySetting('daedalus_layout', 'icon', $icon);
      $block_type->save();
    }
    $display->unsetThirdPartySetting('daedalus_layout', 'block_config');
    $display->save();
  }
  return t('Moved Add panel block icons from entity view displays onto their block types.');
}

/**
 * Stores the inline blocks of Layout Builder default layouts as values.
 *
 * A display is a host now, and its blocks are values: every inline block a
 * Layout Builder enabled display names by block revision id is rewritten
 * to block_values (the block's fields in the blueprint field grammar,
 * references by uuid) so the display exports as config with no content id
 * in it; the orphaned block entities are garbage-collected by the save.
 * See ValueBlockTransform.
 */
function daedalus_layout_post_update_0006_display_value_blocks(&$sandbox) {
  $saved = \Drupal::service('daedalus_layout.entity_view_display_walker')->walk($sandbox);
  if (!empty($sandbox['#finished']) && $sandbox['#finished'] >= 1) {
    return t('Stored the inline blocks of Layout Builder default layouts as values.');
  }
  return t('Stored inline blocks as values on @count displays in this batch.', ['@count' => $saved]);
}

/**
 * Drops the daedalus_layout.settings config object.
 *
 * Its one key, contextual_links, switched on core's block contextual links
 * over a Daedalus canvas; core's Layout Builder routes they pointed at are
 * closed for any storage Daedalus hosts, so the links and their switch are
 * gone.
 */
function daedalus_layout_post_update_0007_drop_contextual_links_setting() {
  \Drupal::configFactory()->getEditable('daedalus_layout.settings')->delete();
}
