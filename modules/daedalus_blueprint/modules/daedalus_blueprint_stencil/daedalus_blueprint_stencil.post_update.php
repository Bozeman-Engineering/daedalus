<?php

/**
 * @file
 * Post update functions for daedalus_blueprint_stencil.
 */

declare(strict_types=1);

use Drupal\daedalus_blueprint\LayoutBuilder\ContainerLayout;
use Drupal\layout_builder\Section;

/**
 * Installs the icon field on the stencil entity (existing sites).
 *
 * The stencil half of the icon value grammar's user lane: a media reference
 * whose catalog spelling is `media:<uuid>`. Fresh installs get the field
 * with the entity type; existing sites install the new base field here.
 */
function daedalus_blueprint_stencil_post_update_install_stencil_icon_field(): void {
  $update_manager = \Drupal::entityDefinitionUpdateManager();
  if ($update_manager->getFieldStorageDefinition('icon', 'stencil') !== NULL) {
    return;
  }
  $definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('stencil');
  $update_manager->installFieldStorageDefinition('icon', 'stencil', 'daedalus_blueprint_stencil', $definitions['icon']);
}

/**
 * Renames the stencil `component` base field to `element` (existing sites).
 *
 * The stencil grammar's vocabulary unification: the definition key, the
 * entity field, and the field type all speak element now (there is ONE
 * concept and one name; `component` was a grammar synonym). The stored
 * JSON blob is grammar-identical under both names, so the value is carried
 * across the storage swap verbatim, keyed by revision. Runs before the
 * other stencil post updates alphabetically, so a site catching up on
 * several updates converts its storage before anything loads a stencil.
 */
function daedalus_blueprint_stencil_post_update_element_field_rename(): void {
  $update_manager = \Drupal::entityDefinitionUpdateManager();
  if ($update_manager->getFieldStorageDefinition('element', 'stencil') !== NULL) {
    // Fresh install (the field was born as element) or already renamed.
    return;
  }
  $database = \Drupal::database();
  // Capture the blobs by revision before the old column drops. The base
  // table's current-revision rows restore via the same revision IDs.
  $values = array_filter($database->select('stencil_revision', 'r')
    ->fields('r', ['revision_id', 'component'])
    ->execute()
    ->fetchAllKeyed());
  // Empty the old column so uninstalling its storage definition never
  // trips the has-data guard.
  $database->update('stencil')->fields(['component' => NULL])->execute();
  $database->update('stencil_revision')->fields(['component' => NULL])->execute();
  $old = $update_manager->getFieldStorageDefinition('component', 'stencil');
  if ($old !== NULL) {
    $update_manager->uninstallFieldStorageDefinition($old);
  }
  $definitions = \Drupal::service('entity_field.manager')->getFieldStorageDefinitions('stencil');
  $update_manager->installFieldStorageDefinition('element', 'stencil', 'daedalus_blueprint_stencil', $definitions['element']);
  foreach ($values as $revision_id => $blob) {
    $database->update('stencil_revision')->fields(['element' => $blob])->condition('revision_id', $revision_id)->execute();
    $database->update('stencil')->fields(['element' => $blob])->condition('revision_id', $revision_id)->execute();
  }
}

/**
 * Converts a stored Section value into a stencil section node (best effort).
 *
 * The value-based half of the section-library/stencil migration: walks a
 * Section (old shape with serialized layout-block nesting, or new shape with
 * embedded container sections) into the stencil grammar. Styles do not
 * travel: templates predate the style map and carry none.
 *
 * @param \Drupal\layout_builder\Section $section
 *   The stored section value.
 * @param string[] $skipped
 *   Reasons pieces were skipped, appended.
 *
 * @return array
 *   The stencil section node.
 */
function _daedalus_blueprint_stencil_section_node(Section $section, array &$skipped): array {
  $serializer = \Drupal::service('daedalus_blueprint.layout_serializer');
  $node = ['layout_id' => $section->getLayoutId()];
  $settings = $section->getLayoutSettings();
  if (!empty($settings['label']) && is_string($settings['label'])) {
    $node['label'] = $settings['label'];
  }
  unset($settings['label'], $settings['context_mapping']);
  if ($settings !== []) {
    $node['layout_settings'] = $settings;
  }

  $components = $section->getComponents();
  uasort($components, static fn ($a, $b) => $a->getWeight() <=> $b->getWeight());
  foreach ($components as $component) {
    $configuration = $component->get('configuration');
    $plugin_id = $component->getPluginId();

    // New-shape container: the embedded section recurses directly.
    if (isset($configuration['section']) && is_array($configuration['section'])) {
      $node['regions'][$component->getRegion()][] = _daedalus_blueprint_stencil_section_node(Section::fromArray($configuration['section']), $skipped);
      continue;
    }

    if (!str_starts_with($plugin_id, 'inline_block:') || empty($configuration['block_serialized'])) {
      $skipped[] = sprintf('component "%s" (%s): only inline blocks with serialized content convert', $component->getUuid(), $plugin_id);
      continue;
    }
    // Layout Builder's own inline-block payload, written by
    // InlineBlock::blockSubmit().
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $block = unserialize($configuration['block_serialized'], ['allowed_classes' => TRUE]);
    if (!is_object($block) || !method_exists($block, 'bundle')) {
      $skipped[] = sprintf('component "%s": serialized block content could not be decoded', $component->getUuid());
      continue;
    }

    // Old-shape layout block: its own layout field's sections become nested
    // section nodes in place of the block.
    if ($block->hasField('layout_builder__layout') && !$block->get('layout_builder__layout')->isEmpty()) {
      foreach ($block->get('layout_builder__layout') as $item) {
        $node['regions'][$component->getRegion()][] = _daedalus_blueprint_stencil_section_node($item->section, $skipped);
      }
      continue;
    }

    $block_node = ['block_type' => $block->bundle()];
    $fields = $serializer->serializeBlockFields($block);
    unset($fields['layout_builder__layout']);
    if ($fields !== []) {
      $block_node['block_fields'] = $fields;
    }
    if (!empty($configuration['label']) && is_string($configuration['label'])) {
      $block_node['block_fields']['label'] = $configuration['label'];
    }
    $node['regions'][$component->getRegion()][] = $block_node;
  }

  return $node;
}

/**
 * Migrates section library templates to stencils (best effort).
 *
 * The section library is removed: stencils are THE reuse mechanism. Each
 * template's stored sections convert into one section-rooted content
 * stencil (a multi-section template becomes a onecol section wrapping its
 * sections as nested section nodes, since a stencil has ONE root). Pieces
 * the stencil grammar cannot express are skipped with a logged reason;
 * templates are left in place for manual review — uninstalling
 * section_library (and removing its composer dependency) deletes them.
 */
function daedalus_blueprint_stencil_post_update_migrate_section_library(): string {
  if (!\Drupal::moduleHandler()->moduleExists('section_library')) {
    return 'The section_library module is not installed; nothing to migrate.';
  }
  $storage = \Drupal::entityTypeManager()->getStorage('section_library_template');
  $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
  if ($ids === []) {
    return 'No section library templates exist; nothing to migrate. Uninstall section_library and daedalus_layout_section_library.';
  }

  $stencil_storage = \Drupal::entityTypeManager()->getStorage('stencil');
  $logger = \Drupal::logger('daedalus_blueprint_stencil');
  $migrated = 0;
  foreach ($storage->loadMultiple($ids) as $template) {
    $skipped = [];
    $sections = [];
    foreach ($template->get('layout_section') as $item) {
      $sections[] = $item->section;
    }
    if ($sections === []) {
      $logger->warning('Section library template @id ("@label") holds no sections; skipped.', [
        '@id' => $template->id(),
        '@label' => $template->label(),
      ]);
      continue;
    }
    if (count($sections) === 1) {
      $element = _daedalus_blueprint_stencil_section_node($sections[0], $skipped);
    }
    else {
      // One root per stencil: wrap the template's bands in a onecol section
      // whose content region nests them in order.
      $element = ['layout_id' => 'layout_onecol'];
      foreach ($sections as $section) {
        $element['regions']['content'][] = _daedalus_blueprint_stencil_section_node($section, $skipped);
      }
    }
    $element['label'] ??= (string) $template->label();

    $stencil = $stencil_storage->create([
      'label' => (string) $template->label(),
      'description' => sprintf('Migrated from the section library template "%s".', $template->label()),
      'uid' => $template->get('user_id')->target_id ?? 1,
    ]);
    $stencil->setElement($element);
    $stencil->save();
    $migrated++;
    if ($skipped !== []) {
      $logger->warning('Template "@label" migrated with skipped pieces: @skipped', [
        '@label' => $template->label(),
        '@skipped' => implode('; ', $skipped),
      ]);
    }
  }

  return sprintf('Migrated %d of %d section library templates to stencils (skipped pieces are logged). Review them, then uninstall section_library and daedalus_layout_section_library and remove the drupal/section_library composer dependency.', $migrated, count($ids));
}

/**
 * Converts one legacy stencil node into current-grammar nodes (best effort).
 *
 * The pre-D stencil grammar let a block node carry a `layout` list (the
 * layout-block shape); the current grammar refuses it loudly: blocks never
 * hold layouts, a container IS a section node. This walk replaces each
 * layout-carrying block node with its section node(s) (the wrapper block
 * was pure plumbing) and recurses into regions so legacy nodes convert at
 * any depth. A wrapper's admin label (block_fields label/info) becomes the
 * single resulting section's label; its styles merge onto that section
 * (same rendered root). Anything without a section equivalent is dropped
 * with a reason, mirroring the section-library migration's best-effort
 * posture.
 *
 * @param array $node
 *   The stencil node.
 * @param string[] $dropped
 *   Reasons pieces were dropped, appended.
 * @param bool $changed
 *   Set TRUE when anything converted.
 * @param string $path
 *   The node's path, for drop reasons.
 *
 * @return array
 *   A LIST of replacement nodes (a legacy layout-block node can expand to
 *   several section nodes, or none).
 */
function _daedalus_blueprint_stencil_convert_legacy_layout_node(array $node, array &$dropped, bool &$changed, string $path): array {
  // Section node: recurse into regions, splicing expansions in place.
  if (isset($node['layout_id'])) {
    foreach ($node['regions'] ?? [] as $region => $children) {
      if (!is_array($children)) {
        continue;
      }
      $replacement = [];
      foreach ($children as $delta => $child) {
        if (!is_array($child)) {
          $replacement[] = $child;
          continue;
        }
        array_push($replacement, ..._daedalus_blueprint_stencil_convert_legacy_layout_node($child, $dropped, $changed, "$path.regions.$region.$delta"));
      }
      $node['regions'][$region] = $replacement;
    }
    return [$node];
  }

  // Legacy layout-carrying block node: lift its sections.
  if (array_key_exists('layout', $node)) {
    $changed = TRUE;
    $sections = [];
    foreach ((array) $node['layout'] as $delta => $section_node) {
      if (is_array($section_node) && isset($section_node['layout_id'])) {
        array_push($sections, ..._daedalus_blueprint_stencil_convert_legacy_layout_node($section_node, $dropped, $changed, "$path.layout.$delta"));
      }
      else {
        $dropped[] = sprintf('%s.layout.%s: not a section node', $path, $delta);
      }
    }
    if ($sections === []) {
      $dropped[] = sprintf('%s: layout-block node held no sections', $path);
      return [];
    }

    $fields = is_array($node['block_fields'] ?? NULL) ? $node['block_fields'] : [];
    $label = NULL;
    foreach (['label', 'info'] as $key) {
      if (!empty($fields[$key]) && is_string($fields[$key])) {
        $label ??= trim($fields[$key]);
      }
      unset($fields[$key]);
    }
    if ($fields !== []) {
      $dropped[] = sprintf('%s: block_fields (%s) have no section equivalent', $path, implode(', ', array_keys($fields)));
    }
    if ($label !== NULL && $label !== '' && strcasecmp($label, 'Layout Block') !== 0 && count($sections) === 1 && empty($sections[0]['label'])) {
      $sections[0]['label'] = $label;
    }
    if (!empty($node['styles']) && is_array($node['styles'])) {
      if (count($sections) === 1) {
        $sections[0]['styles'] = ($sections[0]['styles'] ?? []) + $node['styles'];
      }
      else {
        $dropped[] = sprintf('%s: styles on a multi-section layout-block node were dropped', $path);
      }
    }
    return $sections;
  }

  // Plain block node: unchanged.
  return [$node];
}

/**
 * Migrates legacy layout-block stencil elements to section nodes.
 *
 * Content stencils saved under the pre-D grammar (block nodes carrying a
 * `layout` list) stopped instantiating when the grammar closed: the ops
 * refuse blocks holding layouts. This converts each stored element to
 * the current grammar in place. Shipped file stencils are code, not
 * content: any remaining legacy YAML file keeps failing loudly at
 * instantiate and must be rewritten by hand.
 */
function daedalus_blueprint_stencil_post_update_migrate_layout_block_nodes(): string {
  $storage = \Drupal::entityTypeManager()->getStorage('stencil');
  $logger = \Drupal::logger('daedalus_blueprint_stencil');
  $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
  $converted = 0;
  foreach ($storage->loadMultiple($ids) as $stencil) {
    $dropped = [];
    $changed = FALSE;
    $nodes = _daedalus_blueprint_stencil_convert_legacy_layout_node($stencil->getElement(), $dropped, $changed, 'element');
    if (!$changed) {
      continue;
    }
    if (count($nodes) === 1) {
      $element = $nodes[0];
    }
    elseif ($nodes === []) {
      $logger->warning('Stencil @id ("@label") converted to nothing (@dropped); left unchanged for manual review.', [
        '@id' => $stencil->id(),
        '@label' => $stencil->label(),
        '@dropped' => implode('; ', $dropped),
      ]);
      continue;
    }
    else {
      // One root per stencil: wrap the sections in a one-column section.
      $element = ['layout_id' => 'layout_onecol', 'regions' => ['content' => $nodes]];
    }
    $stencil->setElement($element);
    $stencil->save();
    $converted++;
    if ($dropped !== []) {
      $logger->warning('Stencil "@label" converted with dropped pieces: @dropped', [
        '@label' => $stencil->label(),
        '@dropped' => implode('; ', $dropped),
      ]);
    }
  }
  return sprintf('Converted %d of %d stencils from the legacy layout-block grammar to section nodes (dropped pieces are logged).', $converted, count($ids));
}

/**
 * Converts one LB-shaped stencil node into a lean-tree node (recursive).
 *
 * The pre-tree grammar discriminated by key shape (layout_id = section,
 * block_type = block) and grouped section children in a `regions` map. The
 * lean-tree grammar (NodeTreeBuilder's shape) discriminates by `kind` and
 * has ONE `children` axis: a container layout's flow is direct children, a
 * multi-region layout's named slots ride region nodes. Nodes already
 * declaring a kind pass through (their children still recurse, so a
 * half-converted blob heals). Style keys are untouched: the relative
 * grammar's spellings ('' / '::.class') are lean-tree locals already.
 *
 * @param array $node
 *   The stored stencil node.
 * @param bool $changed
 *   Set TRUE when anything converted.
 *
 * @return array
 *   The lean-tree node.
 */
function _daedalus_blueprint_stencil_node_tree_node(array $node, bool &$changed): array {
  $recurse = static function (array $children) use (&$changed): array {
    $converted = [];
    foreach ($children as $child) {
      $converted[] = is_array($child) ? _daedalus_blueprint_stencil_node_tree_node($child, $changed) : $child;
    }
    return $converted;
  };

  if (isset($node['kind'])) {
    if (isset($node['children']) && is_array($node['children'])) {
      $node['children'] = $recurse($node['children']);
    }
    return $node;
  }

  if (array_key_exists('layout_id', $node)) {
    $changed = TRUE;
    $tree = ['kind' => 'container'] + $node;
    unset($tree['regions']);
    $layout_manager = \Drupal::hasService('plugin.manager.core.layout')
      ? \Drupal::service('plugin.manager.core.layout')
      : NULL;
    $definition = is_string($node['layout_id'] ?? NULL)
      ? $layout_manager?->getDefinition($node['layout_id'], FALSE)
      : NULL;
    $is_container = ContainerLayout::isContainer($definition);
    $children = [];
    foreach ((array) ($node['regions'] ?? []) as $region => $kids) {
      $converted = $recurse(is_array($kids) ? $kids : []);
      if ($is_container) {
        // The sole region is a storage vestige: its flow IS the children.
        array_push($children, ...$converted);
      }
      else {
        $children[] = ['kind' => 'region', 'region' => (string) $region, 'children' => $converted];
      }
    }
    if ($children !== []) {
      $tree['children'] = $children;
    }
    return $tree;
  }

  // A block node — including a still-legacy layout-carrying one, which the
  // earlier migrate_layout_block_nodes update (running before this one
  // alphabetically) normally already lifted; a remaining `layout` key keeps
  // failing loudly at instantiate exactly as before.
  $changed = TRUE;
  return ['kind' => 'block'] + $node;
}

/**
 * Migrates stored stencil elements to the lean node tree grammar.
 *
 * Stencils store the spine's node tree now (kind-discriminated container /
 * region / block nodes with one children axis — never dialect text, so
 * saved stencils stay independent of the model-facing serialization's
 * version). The instantiate operation refuses the old LB-shaped grammar
 * loudly; this converts each stored element in place. Shipped file
 * stencils are code, not content: a legacy YAML file keeps failing at
 * instantiate and must be rewritten by hand.
 */
function daedalus_blueprint_stencil_post_update_node_tree_elements(): string {
  $storage = \Drupal::entityTypeManager()->getStorage('stencil');
  $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
  $converted = 0;
  foreach ($storage->loadMultiple($ids) as $stencil) {
    $element = $stencil->getElement();
    if ($element === []) {
      continue;
    }
    $changed = FALSE;
    $tree = _daedalus_blueprint_stencil_node_tree_node($element, $changed);
    if (!$changed) {
      continue;
    }
    $stencil->setElement($tree);
    $stencil->save();
    $converted++;
  }
  return sprintf('Converted %d of %d stencils to the lean node tree grammar.', $converted, count($ids));
}
