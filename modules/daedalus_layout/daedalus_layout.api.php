<?php

/**
 * @file
 * Hooks provided by the Daedalus Layout module.
 */

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Core's Layout Builder routes on a hosted storage.
 *
 * @section hostless_storage The _daedalus_layout_hostless_storage requirement
 *
 * A document Daedalus hosts (an entity's overrides, a saved blueprint's
 * document, a display's defaults) is written through the blueprint op
 * spine alone, where the capability resolver judges every write. LayoutBuilderRouteSubscriber therefore
 * sets the `_daedalus_layout_hostless_storage` requirement
 * (\Drupal\daedalus_layout\Access\HostlessStorageAccessCheck) on core's
 * write routes (block add, choose, remove, move; section add, choose,
 * remove; every storage's revert): forbidden when
 * HostResolver::fromSectionStorage() resolves a host, allowed for a
 * storage no host owns (core's navigation layout), which keeps core's
 * element, forms and contextual links. The overrides and defaults forms
 * drop Save layout and Revert to defaults for a hosted storage
 * (OverridesEntityForm) and keep Discard. A module adding a route that
 * writes a section storage beside the spine puts the same requirement
 * on it; a module that wants the write judged registers an operation
 * handler instead.
 *
 * @section render_subject The render follows the host's subject
 *
 * What a document renders AGAINST is the host's subject(), never the
 * entity on screen: LayoutBuilderEntityViewDisplay::getContextsForEntity()
 * hands every section the host's subject as `layout_builder.entity` and
 * the root-entity context, and buildSections() is core's loop over those
 * contexts. For a page the subject is the entity, for a template core's
 * sample entity, for a saved blueprint the sample entity of its subject
 * bundle, so a blueprint's field blocks render on its live route as they
 * do on its canvas. Anything that changes what a host renders against
 * changes the host's subject(), never these render sites.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alters the canvas root the layout_builder_plus element wraps a host in.
 *
 * On a canonical entity page the entity view is the canvas root, and a
 * module delivers what rides the host's render root (a style tag, root
 * attributes, classes) through hook_entity_view(). Where no entity view
 * supplies the root (a template host's canvas on core's manage-display
 * route, core's manage-layout route of an entity) the element wraps the
 * layout in the main-entity wrapper itself and fires this alter on that
 * wrapper, so the same delivery lands on the same root. $element already
 * carries the full main-entity attribute set (the host's selectable key,
 * identity, view mode) and, in edit mode, the blueprint mirror.
 *
 * @param array $element
 *   The canvas root render element (the container wrapping the layout).
 * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
 *   The host whose canvas it is.
 */
function hook_daedalus_layout_canvas_root_alter(array &$element, HostInterface $host): void {
  $element['#attributes']['data-my-module-host'] = $host->uuid();
}

/**
 * @} End of "addtogroup hooks".
 */
