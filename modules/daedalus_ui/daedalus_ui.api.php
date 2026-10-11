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
 * - Daedalus Edit's SuppressInlineEditing event: a per-RENDER contextual
 *   opt-out (e.g. media library renders), decided where the render
 *   happens, so it stays an event rather than a declaration.
 * - Daedalus Layout's empty-region seeding: edit-mode-only creation of
 *   addressable drop targets. The model decides existence; what the edit
 *   mode injects into the DOM to make editing ergonomic is a delivery
 *   detail.
 * - This module's LiveAttributionDemand collector: whether selectable keys
 *   are delivered on LIVE pages is orthogonal to what the keys address.
 * - Daedalus Layout's favorites and default sections: palette curation,
 *   not anatomy.
 *
 * @section element_model_existence_feeders Existence inference feeders
 *
 * The model's lowest-precedence tier is pluggable: tag a service
 * `daedalus_ui.existence_feeder` and implement
 * \Drupal\daedalus_ui\ElementModel\ExistenceFeederInterface to report
 * fields nothing renders for (daedalus_ui infers from hidden display
 * components; daedalus_layout feeds root-field existence on Layout Builder
 * displays). Feeders infer; owners that KNOW a field is infrastructure
 * declare `element: false` in their page_builder file instead.
 *
 * @section element_model_icon_providers Element icon providers
 *
 * `_element.icon` is the element's third minted word beside `type` and
 * `name`: a root-relative URL, or NULL, minted by
 * \Drupal\daedalus_ui\ElementModel\BlueprintElementAnnotator and delivered
 * as data on the mirror, so every outline (the page elements tree, code
 * mode in the leader page and in the satellite) reads one answer and no
 * client derives what an element looks like. daedalus_ui mints its own
 * kinds from its own glyphs (assets/outline: the root page, a template
 * root, a field block, a plugin block). A CONTAINER and an INLINE BLOCK
 * are another module's concepts, so for those the annotator asks the
 * tagged providers: tag a service `daedalus_ui.element_icon_provider` and
 * implement \Drupal\daedalus_ui\ElementModel\ElementIconProviderInterface
 * (daedalus_layout answers the Container's asset and the block type's
 * `daedalus_layout.icon` setting resolved through the icon value grammar,
 * the Add panel's two sources, with its default block icon as the
 * fallback). Providers are asked in service priority order and the first
 * non-null answer wins; a provider that does not speak for the node
 * answers NULL. What a provider reads (a block type, a media icon's tags)
 * goes on the CacheableMetadata it is handed, which the mirror applies to
 * the render that delivers the annotation. Pseudo rows the client alone
 * draws (shell parts, generated boxes, instance carriers) are its own
 * anatomy and never ask: the tree picks their glyph from the row's tag.
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
 * The host boundary.
 *
 * @section host_boundary Edit Mode addresses a host, never the entity on screen
 *
 * Everything Edit Mode does is keyed by the page document's host
 * (\Drupal\daedalus_blueprint\Host\HostInterface), derived once per
 * request from the route (DaedalusUi::deriveHostFromRoute(): a route
 * parameter entity HostResolver::hosts(), or a SectionStorageInterface
 * parameter through fromSectionStorage(); a defaults storage answers its
 * display's host whatever subject the render handed it, an overrides
 * storage its entity; a saved blueprint's canonical route answers the
 * spine's BlueprintHost like any content host). ShouldNotEditModeEvent
 * and EditableUiBuilder carry the host, and the bundle switch reads the
 * document's kind through $host->display(), so a template's Edit Mode
 * follows its target bundle and a blueprint's follows its subject bundle.
 * The mirror envelope's entityId is the host's id and every client URL is
 * {entityType}/{entityId}. Rendering follows the host's subject()
 * (LayoutBuilderPlus::renderContexts(): the subject as
 * `layout_builder.entity` and the root entity, one rule for every host),
 * which is how a blueprint's field blocks render against a sample of its
 * subject bundle. A render is a stack of documents (the site frame, a
 * template, the page, the blueprints shown by reference), and a JOIN is
 * a node whose content is another document's: a slot door
 * (`daedalus_slot:{id}`) or a linked placement. Every join the delivered
 * document holds carries `_element.showing` (BlueprintElementAnnotator,
 * copying the spine's one answer, VerdictStamper::showing(), seen from
 * the canvas by the actor the read was made for): `shows` (route | fill
 * | default | nothing), `document` ({entity_type, id, label}: the route
 * host, the filler, the blueprint; NULL when nothing is shown),
 * `blueprint` (the shown blueprint's machine name under default),
 * `portal` ({entity_type, entity_id, label, url}: where the shown
 * blueprint is edited, only when the actor may update it, the url
 * resolved by the annotator through DaedalusUi::getEditingUrl, NULL
 * where the site has no such route) and `claimable` (whether this canvas
 * may detach the shown default into the slot). A detached placement is a
 * copy and no join: it carries no showing. daedalus_layout's "Open
 * blueprint" verb, the Page elements tree's shown-document row and the
 * AI's chips read the showing; nothing derives a route or guesses at
 * access itself.
 *
 * Two facts other modules build on:
 * - DaedalusUi::ownsFields($entity) is the one owner rule for editable
 *   attribution: the host's fields entity, or an inline block whose root
 *   (RootEntityRegistry) is the host's subject, only when the host has a
 *   document. wrapAndAttributeFieldsForEditing(), SelectableFieldDelivery
 *   and the presented_field attribution read it; nothing else decides
 *   editability, and no preview flag exists, so core's sample entity on a
 *   template and any entity a formatter renders are inert by
 *   construction.
 * - What the actor may do to an element is the spine's answer, delivered
 *   with every read: the serializer stamps `_verdict` (daedalus_blueprint's
 *   VerdictStamper) on the root and on every container and block node of
 *   the lean tree the blueprint's `layout` carries (the one shape every
 *   client reader walks; region nodes are structure and carry nothing),
 *   and BlueprintElementAnnotator annotates that same tree in place,
 *   copying the verdict onto every owning node as `_element.can` (the
 *   five capabilities, content,
 *   configure, style, arrange and insert, to booleans) and
 *   `_element.refused` ({code, capabilities} per refusal), the root
 *   included; the root's refusals, explained and carrying the owner and
 *   the portal (with its editing url, for an actor who may open the
 *   owner), ride the mirror envelope and every mutation response as
 *   `verdict`. The client projection copies `can` onto its rows, and the
 *   projection index carries it on every address, field rows and parts
 *   inheriting their element's. No client surface interprets a cause
 *   (ownership, a translation, a workspace) itself.
 *
 * @section verb_contract The verb contract: every gesture asks one gate
 *
 * The client has ONE capability gate, `capable(key, capability)`
 * (js/edit_mode/selection/capable.js, exposed as
 * `Drupal.Daedalus.Selection.capable` and to registration callbacks as
 * `app.Selection.capable`): whether the published verdict on the key's
 * projection row allows the capability. Verbs, the drag controller (arrange
 * of the moved element at dragstart, insert of the destination container
 * at every resolved drop, pointer and keyboard alike), the tree's rename,
 * paste styles, the file-drop faces, the Add panel's click-to-place and
 * the stylish inspector all ask it; a module adding a gesture asks it too
 * and never derives permission from DOM stamps, the owner, or a role.
 *
 * A verb registered through `app.verbs.register()` (verbs/verbs.js) MUST
 * declare the capability it requires:
 * - `capability`: one of 'content', 'configure', 'style', 'arrange',
 *   'insert', or a map of those by kind when the verb's kinds differ
 *   (Rename: configure of a container, content of the root entity).
 * - `of`: the key the capability is asked of, 'element' (the selected
 *   key, the default) or 'parent' (its projection parent: the destination
 *   of an insert after a block), a map by kind, or a function of
 *   (key, kind) answering one of the two (daedalus_layout's Insert asks
 *   the parent of a block, and the block itself when it is a slot door).
 * Registration throws without a valid capability, naming the five: a
 * default would keep a third-party verb running past the gate and hide
 * the contract. The registry's applies gate (verbs/verb-gate.js) asks
 * capable() before the verb's own applies() refinement, so a verb never
 * sees a key its capability is refused on.
 *
 * A verb MAY name a `group`: an id string shared by the verbs that form
 * one control (daedalus_layout's Open, Update and Save as blueprint share
 * 'daedalus_layout.blueprint'). The group is a registry fact every face
 * reads the same way, never a face's own widget: one control where a face
 * can draw one, plain members everywhere else. The one fold is
 * `groupVerbs()` (js/edit_mode/verbs/verb-groups.js): a menu-ordered
 * list becomes entries, a plain `{verb}` or a `{group, primary, rest}`;
 * the group sits where its lightest applicable member sits, that member
 * is the primary, the rest follow in weight order, and a group with one
 * applicable member folds to the plain `{verb}` entry it would be alone,
 * so a face never special-cases it. The selection chip is the one face
 * that draws groups (components/selection-frame/selection-frame.js
 * GroupControl): a group entry is a `daedalus-ui-split-button`, the top
 * bar's Save control and stylesheet (css/split-button.css in the `modes`
 * library), whose main half is the primary's verb button and whose caret
 * half ("More @label actions", @label the primary's label) opens the
 * shared menu, aligned to its end, over the rest through verbMenuItems.
 * At clean density the chip partitions by `tab` first and folds what
 * stays on the face, so a group's untabbed members go behind the chevron
 * as flat items. The context menu, the tree's row menu, the document menu
 * and the hotkeys list the members flat in weight order and never read
 * the key. A module adds a verb to a control by naming the group; it
 * never draws the control itself.
 *
 * A drag source whose data names the element it MOVES passes its
 * selectable key as `data.key`; the controller asks arrange of it at
 * dragstart and refuses the drag otherwise. Sources that place something
 * new carry no key and are judged at the destination alone.
 *
 * Selection is gated by the same answer: `selectable(key)` (capable of
 * anything, `Drupal.Daedalus.Selection.selectable`) is asked by the
 * pointer chain (a click resolves to the outermost selectable key; a
 * field block presenting one of the host's own fields resolves to the
 * placement row that carries that field's content verdict), by keyboard
 * reach, by the editing gate and by the store's selection middleware,
 * so no dispatch road (the facade, the tree, a module's own select) can
 * seat a key the actor can do nothing to. The page elements tree draws
 * such a row inert and still expandable. A sidebar plugin mounts by
 * capability on top of existence (VerdictStamper::allowsAnywhere($host,
 * $capability) in its applies(): the stylish inspector for style, the
 * Add panel for insert), and ShouldNotEditMode vetoes Edit Mode for an
 * actor with no capability on anything.
 *
 * The document notice (components/page-elements/document-notice.js) at
 * the top of the page elements panel is the one explanation of a
 * host-level refusal, worded from the envelope's `verdict` by code
 * (FOREIGN_DOCUMENT names the template, LAYOUT_NOT_TRANSLATABLE the
 * translation, WORKSPACE_REQUIRED the workspace) and carrying "Edit the
 * template" only when the verdict carries a portal with a url. No chip,
 * menu or inspector offers a portal of its own.
 *
 * @section portal_actions The doorways: one registry for the chip and the row
 *
 * A foreign document on the page (a linked blueprint, the site frame's
 * nodes, a template's on a locked page) is a PORTAL: outside the
 * projection, no selection, no verb. On the canvas its surface is the
 * portal chip (components/portal/portal.js), which reads the stamps the
 * server put on the placement root
 * (LayoutBuilderBlockAttributes::stampShowing, one projection of the
 * spine's showing and content verdict): the portal host's editing URL,
 * label and kind word (`data-daedalus-ui-portal-url`, `-label`, `-type`:
 * the blueprint a join shows when the actor may open it, else the holder
 * the FOREIGN_DOCUMENT verdict names; the kind is DaedalusUi::hostKind's
 * word, "Blueprint", "Site frame", "Template", which the chip reads into
 * "Header · blueprint"), and on a slot door showing its default, the claim
 * (SlotBlock::CLAIM_ATTRIBUTE, the default blueprint's machine name)
 * exactly when the page may fill the slot and nothing fills it yet. In the
 * Page elements tree the same join has a second face: the shown-document
 * row under a door showing its default
 * (components/page-elements/shown-rows.js), built from the annotation's
 * `_element.showing`, not a selection and not a styling address.
 *
 * Both faces offer DOORWAYS over the one showing. Edit is each face's
 * own and takes the one leave (`Drupal.Daedalus.Portal.leave(url)`, the
 * showing's portal url: the chip's anchor, the row's first menu item,
 * daedalus_layout's "Open blueprint" verb on the door's own row). A
 * gesture that is the PAGE's, made on what the join shows, registers once
 * (`app.portal.registerAction({id, label, icon?, applies(showing),
 * run(showing, uuid)})`, components/portal/portal-actions.js) and both
 * faces offer it when it applies: the chip as a button beside Edit, the
 * row as menu items after Edit. `applies(showing)` answers from the
 * showing alone; `label` is a string or a function of the showing;
 * `run(showing, uuid)` takes the showing and the door's component uuid
 * (the placement root's `data-layout-builder-block-uuid`; the row's
 * parentKey minus its `block:` prefix), and an action that must read the
 * canvas finds the door by that uuid inside run(). The chip hands the
 * registry the stamps read back into the showing's shape
 * (portal-model.js portalShowing: `shows` "default", `blueprint` and
 * `claimable` from the claim stamp, `document.label` from the label, the
 * url and its kind as `portal`; what the stamps do not say is null), the
 * row the annotation's copy; an action reads the showing's keys and never
 * a DOM stamp or a role. daedalus_layout's "Detach into this slot" applies
 * on `claimable`, reads the slot id off the door it finds by uuid
 * (SlotBlock::DOOR_ATTRIBUTE) and submits the spine's detach_blueprint op
 * with it, the one op the AI composes with, reloading when the door sits
 * outside `[data-main-entity]` (the frame is not in the mutation
 * response).
 */

/**
 * The outline row grammar.
 *
 * @section outline_row_grammar One row CSS for every outline in the rail
 *
 * An outline in the left rail (the Page elements tree, daedalus_stylish's
 * code mode in the leader page and in its satellite window, the Add
 * panel's set headings) draws its rows over ONE grammar, the
 * `daedalus-ui-outline__row` block of css/panel-controls.css in the
 * `daedalus_ui/panel_controls` library (the disclosure grammar's own
 * library, which every outline already attaches; the satellite attaches
 * it alone). The adapters share the CSS, not a component: each keeps its
 * own row element and its own interaction contract (a treeitem, mouse
 * chrome over editable islands, a button that is itself the toggle), and
 * what they share is the geometry, the zones, the voice and the states.
 * Tim's rule (2026-10-04): the three outlines share the same CSS, not only
 * the same dimensions, and a per-adapter rule beside the grammar carries
 * its reason in a comment.
 *
 * The row is four zones in a fixed order, and nothing else:
 * - `__chevron`: the disclosure column, 1rem. The tree's and code mode's
 *   `daedalus-ui-tree__disclosure` button wears it; a leaf renders an
 *   inert span of the same footprint so every icon starts in one column;
 *   a heading that is itself the toggle puts the bare
 *   `daedalus-ui-tree__disclosure-marker` inside the span. The marker's
 *   open rotation is keyed off each carrier's own open state in the
 *   grammar (a details element's `open`, `aria-expanded` on the
 *   disclosure button, `aria-expanded` on a row that is itself the
 *   toggle), so no adapter restates it.
 * - `__icon`: what the row IS, the element's minted glyph
 *   (`_element.icon`, a URL, or the tree's own glyph for a pseudo row).
 *   The adapter hands the URL in as the inline custom property
 *   `--daedalus-outline-icon` and the grammar paints it as a mask over
 *   `currentColor`, so one asset reads muted at rest and in the
 *   selection color when selected; a raster icon masks to its
 *   silhouette. Never render the span without a URL: the mask rule paints
 *   a solid box for a missing property, so a null icon draws no icon
 *   zone.
 * - `__name`: what the row says, the one zone that gives way (ellipsis).
 *   The type is not printed; it is the row's `title`.
 * - `__facts`: right-aligned, never shrunk, after everything else: the
 *   row-facts seam's `__dot` (the shared `daedalus-ui-dot` swatch, hue
 *   inline) and `__text` (caption voice), a `__count` (a folded row's
 *   rows, a set's members; tabular numerals) and the Markup view's
 *   `__tag` (end-aligned dim monospace, no angle brackets).
 *
 * Depth is the row's own padding: `calc(var(--daedalus-outline-depth) *
 * var(--daedalus-outline-step))` (css/variables.css; the step is 0.5rem),
 * the depth set inline by the adapter on the row or on a wrapper the row
 * inherits it from (code mode sets it on the node so the islands' rail
 * can read it). No adapter keeps a nesting-wrapper margin, so the hover
 * and selection washes span the panel's width. `--daedalus-indent` is
 * the disclosure grammar's child-step for drop-down bodies and serves no
 * outline.
 *
 * Voice is a modifier on the row, by what the row is, not where it sits:
 * `--structure` (the page, a section, a container: the name at 600),
 * none for a block, `--content` (muted: a field, a region, a value, a
 * rich-text carrier), `--anatomy` (muted italic: a shell part, a
 * generated box), `--heading` (the Add panel's sets: muted, 600). State:
 * `--hovered` (the lighter wash), `--selected` (the full wash with the
 * name and the icon in the selection color and NO border, so a row's
 * content never shifts), `--marked` (the shared focus treatment,
 * wash plus stripe, for `app.tree.setRowMarks`). The voice and state
 * rules use DESCENDANT combinators, so an adapter may wrap zones in its
 * own hit target (code mode's select button holds the icon and the name).
 *
 * The tree's rows wear both this grammar and their own
 * `daedalus-ui-tree__row` identity (the kind and root classes tests bind
 * to); code mode's wear `daedalus-stylish-page-tree__header` beside it as
 * the hover paint's handle; the Add panel's headings wear
 * `daedalus-layout-add-panel__heading` for the sticky rule. A change to
 * the grammar or to js/outline/ reaches every bundled adapter: rebuild
 * all four bundles (`npm run build:prod` from the daedalus root).
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
 * The row-facts contract.
 *
 * @section row_facts Facts about rows that exist, from any module
 *
 * The Page elements tree lists what the page IS (the projection) and
 * what a view adds to it (the view seam, app.tree.registerViewProvider);
 * what another module can TELL about a row that is already listed is a
 * fact, and facts have their own seam: `app.tree.registerRowFacts({id,
 * facts, doorways, subscribe?})` (daedalus_stylish's variant provider
 * does: a dot per variant an element wears and one in the element hue
 * when it is styled on itself; doorways "Edit {variant}", "Outline
 * everything wearing it", "Show its own styles in code"). The registry
 * (js/edit_mode/components/page-elements/row-facts-registry.js, whose
 * file docblock is the normative contract) is read by the tree's two
 * rows on every render and by the row menu on every open.
 *
 * What makes a fact a fact and not a view: it never adds a row, never
 * removes one and never touches reach (reach.js), so the pointer, the
 * keyboard, the breadcrumb and daedalus_explode's planes land exactly
 * where they did before the provider registered. It also has no toggle:
 * a provider is on while it is registered (unregister through the
 * function registerRowFacts returns). Where a module needs rows of its
 * own, that is a view; where it needs a word on a row, that is a fact.
 *
 * The provider's entries, all pure:
 * - facts(address): [{mark: 'dot' | 'text', hue?, text?, title}] for the
 *   row at a projection-index address (an element key for a model row,
 *   the `key::selector` pair for a part row); a dot is a round swatch
 *   in `hue`, a text its `text` in the caption voice. Drawn in the outline
 *   row grammar's facts zone (css/panel-controls.css, the
 *   `daedalus-ui-outline__facts` zone) ahead of the Markup view's tag,
 *   providers in registration order. Empty for a row the provider has
 *   nothing to say about.
 * - doorways(address): row menu items in the menu grammar
 *   (js/menu/menu-state.js), listed ahead of the selection's verbs behind
 *   one separator when the menu is opened on a single row (a set's menu
 *   is the set's verbs alone). A doorway leads from the row to where
 *   something about it lives; it is never a verb on the element (verbs
 *   register through app.verbs, with a capability).
 * - subscribe(callback): optional; announces that the provider's answers
 *   changed from a source outside the store, and the tree re-renders.
 *
 * Re-registering an id replaces the earlier provider in place, so a
 * module re-registering on its own re-boot never stacks. A provider
 * whose entry throws is contained for that read (console.error, nothing
 * from it) so one module's fact cannot blank the panel's rows.
 */

/**
 * The bundle form's Page builder section.
 *
 * @section bundle_section One tab, one switch, groups named by outcome
 *
 * Every bundle edit form whose bundle config type carries this module's
 * settings gets one "Page builder" vertical tab, owned by
 * \Drupal\daedalus_ui\Form\BundleEditFormAlter. It leads with the switch
 * for the one boolean, `third_party_settings.daedalus_ui.enabled` ("the
 * page builder is on for this bundle"), which is the gate every reader
 * asks (ShouldNotEditMode, daedalus_stylish's style store); under it sit
 * groups named by the OUTCOME a control serves and never by module
 * (BundleEditFormAlter::GROUP_LAYOUT, GROUP_CREATION and GROUP_STYLING on
 * a page bundle; GROUP_ADD_PANEL, GROUP_ON_PAGE and GROUP_STYLING on a
 * block type), hidden through core's #states while the switch is off.
 * There is no per-mode status map and no mode table: one per-bundle mode
 * exists and it has no configuration.
 *
 * The contract for a module that owns a bundle setting:
 * - Ask the section owner for the group its control belongs to:
 *   `$owner->ensureGroup($form, $form_state, GROUP_*)` (the owner is
 *   `\Drupal\daedalus_ui\Form\BundleEditFormAlter`, injectable by class).
 *   TRUE means the group exists under $form['page_builder'][$group]; add
 *   the control there. FALSE means the section does not belong on this
 *   form (an add form, a bundle config type whose schema is not named):
 *   add nothing. Create-if-missing, so form alter order does not matter.
 *   Never park a details element of your own in the tab.
 * - Route the value FLAT: set explicit `#parents` on the input and persist
 *   it in your own submit handler, never through a third_party_settings
 *   `#parents` route (copyFormValuesToEntity() would replace the entity's
 *   third-party settings wholesale, clobbering keys no form element
 *   carries).
 * - Probe your own schema before writing a key:
 *   `$owner->hasSettingsSchema($bundle_entity_type, 'your_module')`. Core
 *   names a third-party section per bundle config type and the typed
 *   config fallback would answer a bare lookup with the bundle type's own
 *   mapping, so the probe matches the resolved type's name; declare one
 *   `{prefix}.*.third_party.{module}` line per bundle config type you
 *   serve (daedalus_layout's `offer_starts` is the model).
 * - Let the tab's summary line state your setting: give a checkbox
 *   `'#attributes' => [BundleEditFormAlter::SUMMARY_ATTRIBUTE => t('...')]`,
 *   one short sentence without the final stop; js/bundle-form.js joins the
 *   checked ones after "On".
 * - A group's fieldset carries `data-daedalus-ui-group="{group}"`; select
 *   by that in tests and CSS, not by the generated id.
 */

/**
 * Chromeless documents.
 *
 * @section chromeless_documents Three kinds, two route options, one drop
 *
 * A document that renders without the page chrome (core navigation's rail
 * and top bar, the toolbar, this module's mode bars, sidebars and fabs,
 * and everything those attach) is one of three kinds, named by
 * \Drupal\daedalus_ui\Chromeless, and the drop of page_top and page_bottom
 * is one seam for all of them (DaedalusUiHooks::preprocessHtml). A route
 * never carries a chrome story of its own; it declares one of the two
 * options below, or its document is reached in preview mode
 * (?navigationMode=preview, the staged page as a visitor sees it, which
 * keeps the theme's regions and the skin).
 *
 * - `_daedalus_ui.popout: true` (Chromeless::ROUTE_OPTION): a tool window
 *   the editing page opens beside itself. The document takes the pop-out
 *   display variant daedalus_ui_popout (no block placement, no page
 *   title), wears the pop-out document chrome and the keyboard half that
 *   senses Edit Mode shortcuts for the editing page, receives the edit
 *   session's identity from its ?scope query as
 *   drupalSettings.daedalusUi.popout.scope, and is outside the skin's
 *   reach. Use it for a panel or a preview SHELL, never for site content.
 * - `_daedalus_ui.bare: true` (Chromeless::BARE_ROUTE_OPTION): a bare
 *   site document, one piece of site content rendered alone in the skin
 *   (daedalus_blueprint_api's peek door is the first). The document takes
 *   core's simple_page variant (no block placement, so none of the theme's
 *   regions), keeps the skin (the token scope wraps the content and the
 *   host's own style tag applies inside it), wears no pop-out class,
 *   stylesheet or keyboard, carries no session identity, and never builds
 *   Edit Mode (ShouldNotEditMode vetoes the mode on it whatever mode the
 *   request names). Use it when site content must be shown outside the
 *   canvas: a frame is the only place the skin is whole and the canvas's
 *   machinery cannot mistake the content for canvas.
 *
 * A controller on either route returns an ordinary render array; the
 * variant selection (PopoutPageVariant), the chromeless drop and the
 * mode veto are the option's, and the route's title (when it declares
 * one) is the window's <title> on a pop-out and core's page title on a
 * bare document, so a bare route that wants no heading declares none.
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
 * with their markup vocabulary, a fixed tag or ordered first-match-wins
 * forms with per-form labels, present conditions, attributes;
 * fields existence/presentation, node presentation, root forms, compose);
 * foreign sections contributed by other modules ride the same structure.
 * Validation runs AFTER this hook, so altered declarations must satisfy
 * the same grammar as authored ones. Every merged value records the last
 * layer to write it (PageBuilderDeclarations::getOrigins(), the provider
 * and file); a value this hook adds or changes carries the alter origin
 * instead, since the module handler runs every implementation as one
 * layer.
 *
 * Block compose rides this file family too: `composed: true` on a block
 * type (authored in PROVIDER.page_builder.block_type.BUNDLE.yml) makes the
 * declaration GENERATIVE: the declared markup model IS the render, printed
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
 *   by BASE block plugin id; derivative ids cannot key a declaration),
 *   'layouts' (keyed by layout plugin id), and 'site' (the site's own
 *   declaration: site-scope foreign sections authored under the reserved
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
