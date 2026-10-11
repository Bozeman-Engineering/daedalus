<?php

/**
 * @file
 * Hooks provided by the Daedalus Blueprint AI module.
 */

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Reacts to an entity being persisted by an AI tool.
 *
 * Called after an AI function call plugin successfully persists an entity via
 * DaedalusBlueprintToolBase::persistEntity(). This hook fires in addition to
 * hook_daedalus_blueprint_entity_persisted() and is specific to AI tool
 * invocations. Use it for AI-tool-specific side effects such as generating
 * open links or triggering instant refresh.
 *
 * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
 *   The host that was persisted.
 * @param bool $is_new
 *   TRUE if this is a newly created entity.
 * @param string[] $element_uuids
 *   UUIDs of elements affected by the operation. Empty when no
 *   element-level operations were performed (e.g., updateFields).
 */
function hook_daedalus_blueprint_ai_tool_persisted(HostInterface $host, bool $is_new, array $element_uuids): void {
}

/**
 * Alters the list of entity types and bundles visible to the AI.
 *
 * The list starts from every fieldable entity type with bundles, filtered by
 * daedalus_blueprint_ai.settings (hidden_entity_types / hidden_bundles); this
 * alter runs last. Hiding here is ENFORCEMENT, not just presentation: every
 * AI tool refuses hidden types and bundles (read, create, schema, element
 * and batch operations), so removing an entry removes the AI's whole
 * surface for it. The alter runs per request and may key on the acting
 * account; use it to gate an entity type's AI exposure on a condition
 * config cannot express, e.g. daedalus_stylish hides daedalus_skin unless the
 * acting account may administer skins.
 *
 * @param array &$bundle_list
 *   Keyed by entity type ID; each value has 'label' and 'bundles' (bundle ID
 *   => ['label', 'description']). Descriptions may also be enriched here.
 */
function hook_daedalus_blueprint_ai_bundle_list_alter(array &$bundle_list): void {
  unset($bundle_list['my_internal_entity_type']);
}

/**
 * @} End of "addtogroup hooks".
 */
