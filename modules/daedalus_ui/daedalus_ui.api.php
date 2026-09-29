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
 * The canvas-view axis.
 *
 * @section canvas_view The data-daedalus-ui-canvas-view attribute on <html>
 *
 * `data-daedalus-ui-canvas-view` on the document element says the canvas
 * (`#daedalus-ui-stage`) is being shown under a projection other than the
 * flat page: its value names the view (daedalus_explode writes `exploded`
 * while its panel is open). It sits beside the two other html-level axes
 * daedalus_ui stamps, `data-daedalus-ui-mode` (js/daedalus-ui.js) and
 * `data-daedalus-ui-chrome-density` (chrome-density-setting.js), and like
 * them it is read by CSS, never by a second JavaScript derivation.
 *
 * The contract:
 * - Writers are opt-in modules. daedalus_ui never sets the attribute; a
 *   module that projects the canvas sets it when the projection starts and
 *   removes it when the projection ends. One writer at a time; the value
 *   is the writer's to name.
 * - Rect-measured chrome hides under it, in its OWN stylesheet. Anything
 *   positioned from an element's getBoundingClientRect (daedalus_ui's
 *   selection frame, insertion caret and selection indicator, the canvas
 *   vector's handles and ruler; daedalus_stylish's highlight bands and
 *   paint canvas) measures the 2D box of a projected quad while the canvas
 *   is transformed, so each owner carries an
 *   `html[data-daedalus-ui-canvas-view] … { display: none }` rule. The
 *   writer never names another module's classes: a projection that had to
 *   hide chrome by reaching into other stylesheets would be a second road
 *   into every one of them.
 * - Outline-based paint may ignore it. Rules that paint on the real
 *   elements (an injected `outline`, daedalus_stylish's selector paint)
 *   ride the elements through any transform and stay truthful, so they
 *   need no rule.
 */

/**
 * The part-behavior contract.
 *
 * @section part_behavior One behavior resolves parts for every spine consumer
 *
 * The Page elements tree's shell rows and the canvas's part addresses
 * ({parentKey, id}: the owning element's selection key plus a
 * provider-scoped id, in practice a relative selector under that key)
 * are daedalus_ui structure, but what a part OFFERS (a style vocabulary,
 * a selector grammar, the words for a selector) is another module's
 * meaning. That module registers ONE part behavior through the app API,
 * `app.tree.registerPartBehavior(behavior)` (daedalus_stylish's
 * tree-parts.js does; the last registration wins), and every spine
 * consumer (selector-events, the canvas paint adapters, the breadcrumb,
 * the projection index, daedalus_explode's plane host) translates
 * between the DOM and part addresses through it without learning the
 * grammar. The registry (js/edit_mode/components/page-elements/
 * view-registry.js, whose file docblock is the normative contract) wraps
 * resolve() in the projection gate, both tiers of it, so a behavior only
 * ever resolves presented owners and placed rows.
 *
 * The behavior's entries, all pure:
 * - resolve(domTarget, presented, placed): the part under a canvas DOM
 *   target as {parentKey, id, hit} (hit the canvas element the part
 *   matched on, for part-versus-element depth arbitration), or null for
 *   off-canvas targets, editor chrome and part-less markup.
 * - selectorFor(parentKey, selector, declarations): the complete scoped
 *   CSS rule text applying `declarations` to every match of a relative
 *   selector in its owner's scope, the behavior's printed targets, so a
 *   painted affordance is a truthful preview of the selector's reach.
 *   Null outside the grammar. For a GENERATED BOX selector (the grammar's
 *   terminal `::before` / `::after`: `&::before`, `.quote::after`,
 *   `> pb-text::before`) the printed rule carries the pseudo-element, so
 *   an `outline` in `declarations` paints the generated box itself.
 * - matches(parentKey, selector): {elements, pseudo}, the DOM twin of
 *   selectorFor: the live canvas elements the selector hits under its
 *   owner, document order, empty when the owner is off the canvas or the
 *   selector outside the grammar. A generated box has no node to match,
 *   so for a box selector `elements` are the box's HOSTS and `pseudo`
 *   names the box ('before' / 'after'; null for an element selector). A
 *   consumer reads a box's computed style as
 *   getComputedStyle(host, '::before'), stamps a forced state on the
 *   host (the printed state suffix precedes the pseudo, so the host's
 *   attribute is what the rule reads), and a consumer that measures
 *   geometry withholds: a box has no rect, and the host's is not its.
 *   This shape replaced a bare Element[]; a consumer that cannot serve a
 *   box refuses at the read (returns nothing for it), never degrades to
 *   the host's geometry. Optional; absent, selector reach is CSS only.
 * - generated(key): the generated boxes the behavior's cascade
 *   materializes on an element, [{id, pseudo, label}] in grammar order
 *   (id the box's relative selector `&::before`, label its words "Before"
 *   / "After"). Existence is the behavior's CASCADE fact, never anatomy's:
 *   a box is listed iff something delivers `content` other than `none`
 *   at it under any condition, so the list follows the style map, the
 *   worn variants and the ancestor rules, not a declaration. The tree
 *   renders these as pseudo rows under the element's row (id as the row
 *   id) exactly as it renders anatomy's part rows, in every view
 *   (page-elements/generated-rows.js withGeneratedRows), and the
 *   projection index places the row at `key::&::before` as a part-tier
 *   entry, so clicking one seats {parentKey: key, id} as the styling
 *   target through the same spine. The answer is read ONLY through
 *   generatedRows(key) in generated-rows.js, which memoizes it on the
 *   blueprint mirror, the materials records and the registered behavior
 *   (the behavior builds full stacks per call); a render path never
 *   calls getPartBehavior().generated() directly. Optional; absent, no
 *   boxes are listed.
 * - label(key, selector): the words for a relative selector under an
 *   owner ("Every link", "Quote › After"), read by the breadcrumb's
 *   target crumb; optional, the raw selector stands in.
 *
 * Pseudo-row click, active state and hover are never behavior callbacks:
 * the tree writes the selection spine itself and reads the row's state
 * back from it, so every part surface, a real part or a generated box,
 * agrees through the one store.
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
