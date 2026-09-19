<?php

/**
 * @file
 * Hooks provided by the Daedalus UI module.
 */

/**
 * The element model boundary.
 *
 * @section element_model_boundary What the element model does NOT own
 *
 * The element model (PROVIDER.page_builder.yml declarations layered by
 * \Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations, resolved by
 * \Drupal\daedalus_ui\ElementModel\ElementModel, delivered as the
 * `_element` blueprint annotation) answers one question: what does a thing
 * render as, and what of it is addressable. Some page-builder mechanisms
 * look adjacent but are deliberately OUTSIDE it, because they are not facts
 * about a type's anatomy:
 * - Daedalus Edit's SuppressInlineEditing event: a per-RENDER contextual opt-out
 *   (e.g. media library renders), decided where the render happens, so it
 *   stays an event rather than a declaration.
 * - Daedalus Layout's empty-region seeding: edit-mode-only creation of
 *   addressable drop targets. The model decides existence; what the edit
 *   mode injects into the DOM to make editing ergonomic is a delivery
 *   detail.
 * - This module's LiveAttributionDemand collector: whether selectable keys
 *   are delivered on LIVE pages is orthogonal to what the keys address.
 * - Daedalus Layout's promoted blocks and default sections: palette
 *   curation, not anatomy.
 *
 * @section element_model_existence_feeders Existence inference feeders
 *
 * The model's lowest-precedence tier is pluggable: tag a service
 * `daedalus_ui.existence_feeder` and implement
 * \Drupal\daedalus_ui\ElementModel\ExistenceFeederInterface to report
 * fields nothing renders for (daedalus_ui infers from hidden display
 * components; daedalus_layout feeds root-field existence from its preserved
 * fields). Feeders infer; owners that KNOW a field is infrastructure
 * declare `element: false` in their page_builder file instead.
 *
 * @section element_model_design_system_stratum The design-system stratum
 *
 * The declaration file family carries two strata. The element-model
 * stratum (parts, forms, property groups, controls: what is addressable
 * and what it renders as) is site-level, owned by the suite modules and
 * the theme, and layers in module list order, then the theme chain. The
 * design-system stratum (token scales, bindings, allowlists, axes, birth
 * defaults, stops: the law a skin and its materials are written against)
 * travels with its design system: a module that packages one (the
 * `package` of an daedalus_blueprint `design_system` config entity, shipped
 * as the module's install config) ships that law in its own
 * PROVIDER.page_builder.yml, and it is in force only while its system is
 * the site's active one (`daedalus_blueprint.settings active_design_system`).
 * PageBuilderDeclarations layers the ACTIVE package above every module
 * that packages no system, whatever its list order, and below the themes;
 * an inactive package's files do not layer at all. So a package never
 * needs a module weight to put its lists above the suite's, two installed
 * systems never merge their law, and switching systems switches the law
 * with the skin and materials. A package that also ships element-model
 * facts (a block type's parts) layers them conditionally too: a design
 * system module's declarations are its own. A module that ships a design
 * system is a package from install, before its config lands: the shipped
 * file on disk (`ActiveDesignSystem::shippedPackages()`) counts beside the
 * landed entity, so on a recipe apply (modules installed first, config
 * imported after) the package's files never layer as a plain module's in
 * between, and a system that leaves the site does not turn its module
 * back into one. The layered result is cached per theme under the family
 * tag `daedalus_ui_page_builder` plus `config:daedalus_blueprint.settings`
 * and the design_system list tag; a switch, a landing, a leaving, and a
 * module install each reset it.
 */

/**
 * Alters the layered page builder declarations.
 *
 * The highest-precedence layer over the PROVIDER.page_builder.yml file
 * family (modules packaging no design system in module list order, then
 * the active design system's package, then base themes, then the active
 * theme), for declarations not expressible as YAML layering. See
 * \Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations for the
 * format and the sections daedalus_ui owns (elements anatomy trees
 * with their markup vocabulary — a fixed tag or ordered first-match-wins
 * forms with per-form labels, present conditions, attributes —
 * fields existence/presentation, node presentation, root forms, compose);
 * foreign sections contributed by other modules ride the same structure.
 * Validation runs AFTER this hook, so altered declarations must satisfy
 * the same grammar as authored ones.
 *
 * Block compose rides this file family too: `composed: true` on a block
 * type (authored in PROVIDER.page_builder.block_type.BUNDLE.yml) makes the
 * declaration GENERATIVE — the declared markup model IS the render, printed
 * by the shared composed_block element from the element resolver's answer
 * (see \Drupal\daedalus_ui\Render\ComposedBlockRenderer; block type
 * owners plug content in through
 * \Drupal\daedalus_ui\Render\ComposedBlockContentInterface). A composed
 * block renders WITHOUT the block sandwich: its single
 * composed root IS the block, carrying the hoisted editing attributes, and
 * its component nodes carry `_element.composed: true` in DELIVERED
 * blueprints (an absent key means the sandwich), and client code reads that
 * annotation, never bundle lists of its own.
 *
 * @param array $declarations
 *   The layered declarations: 'kinds' (universal declarations keyed by
 *   element kind), 'block_types' (keyed by bundle), 'block_plugins' (keyed
 *   by BASE block plugin id — derivative ids cannot key a declaration),
 *   'layouts' (keyed by layout plugin id), and 'site' (the site's own
 *   declaration — site-scope foreign sections authored under the reserved
 *   top-level `site` key of universal files), all as authored. Block type,
 *   block plugin, and layout declarations are validated and served with
 *   the universal block/section kind layered underneath.
 * @param string $theme
 *   The theme the declarations were layered for.
 */
function hook_page_builder_declarations_alter(array &$declarations, string $theme): void {
  // An internal bookkeeping field never renders, so nothing answers to its
  // key in any view.
  $declarations['kinds']['block']['fields']['field_internal_notes']['element'] = FALSE;
}
