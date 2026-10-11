<?php

/**
 * @file
 * Hooks provided by the Daedalus Stylish module.
 */

declare(strict_types=1);

/**
 * @addtogroup hooks
 * @{
 */

/**
 * The style guide has no discovery or alter hook of its own.
 *
 * Daedalus Stylish's styling sections (property_groups with their
 * constraints and per-element stops, default_variants, controls,
 * variant_axes, defaults, children, pseudo, and the site declaration's
 * token_scales, token_contract, constraint_defaults and base) ride the
 * shared page builder declaration files (PROVIDER.page_builder.yml,
 * PROVIDER.page_builder.block_type.BUNDLE.yml,
 * PROVIDER.page_builder.block_plugin.PLUGIN_ID.yml,
 * PROVIDER.page_builder.layout.LAYOUT_ID.yml) discovered and layered by
 * daedalus_ui (modules in module list order, then the active design
 * system's package, then the active theme's base chain, then the active
 * theme). To alter the layered result, use
 * hook_page_builder_declarations_alter() (see daedalus_ui.api.php);
 * \Drupal\daedalus_stylish\StyleGuide documents the styling sections and their
 * validation.
 *
 * The law is authored in files and SHOWN in the UI, never edited there:
 * daedalus_ui records, beside the merged set, which provider and file
 * supplied each merged value (PageBuilderDeclarations::getOrigins(), the
 * last layer to write it; the alter hook is attributed by difference),
 * and ClientStyleGuide serves the law projection (the scales and their
 * bindings, the token contract, and per kind, block type, block plugin
 * and layout the grants, constraints, curated stops, axes and birth
 * defaults, each with its origin, read under the active system's
 * enforcement mode) that the inspector's Law tab renders read-only. A
 * row whose value a declaration supplies opens the Law tab at that
 * declaration, so an author learns which file to edit.
 */

/**
 * The token contract: a guide promises names, a skin mints them.
 *
 * A stop is a token NAME, never a value, and stops never define
 * legality (legality is the whole bound scale plus the block's `allowed`
 * literals, on strict as on guarded); a `stops:` list only curates which
 * names a row shows. Two rules keep the guide and the skin honest about
 * each other:
 * - `site.token_contract: {scale: [names]}` declares, per declared
 *   scale, the token names the design system promises (the names its
 *   theme CSS reads, the names its stops curate). A guide that curates
 *   `stops:` on a scale anywhere must declare that scale's contract, and
 *   every stop must be a contract name; both are checked at guide build
 *   (StyleGuide::validateTokenContract(), checkConstraintBlock()), so a
 *   curated name the skin does not mint is a build-time fact, never a
 *   delivery-time drift. SkinConformance answers whether a skin mints
 *   the contract and names what it lacks; a skin is choosable for a
 *   system only while it conforms.
 * - Site-wide stops do not exist: `stops` under
 *   `site.constraint_defaults` is a build error whose corrective names
 *   the token's `offered` flag. Which tokens a scale's rows lead with is
 *   a fact about the tokens, stored on each token record as `offered`
 *   (also the AI's opt-out: an unoffered token leaves the roster the
 *   brief prints), in the skin's stored order, where the site layer lets
 *   a tenant curate it. Per-element `stops:` (a button offers three
 *   sizes) are keyed by anatomy and stay in the block type, block plugin
 *   and layout files.
 *
 * Row resolution has one rule on both sides
 * (VocabularyRoster::scaleStops(), style-guide.js scaleRow()): the
 * element's curated stops in list order, unminted names dropped; else the
 * bound scale's offered tokens in stored order. On a curated row the
 * tokens the site minted (origin other than shipped) follow the curated
 * ones on open and guarded systems and are withheld from basic rows on
 * strict; the code view's completions and the validator keep the whole
 * scale.
 *
 * @code
 * # my_brand.page_builder.yml
 * site:
 *   token_scales:
 *     space: {label: Space, shape: dimension, prefix: space}
 *   token_contract:
 *     space: [sm, md, lg, xl]
 *
 * # my_brand.page_builder.block_type.text.yml
 * property_groups:
 *   spacing:
 *     constraints:
 *       padding: {scale: space, stops: [sm, md, lg]}
 * @endcode
 */

/**
 * A skin is two layers on one entity, and its tokens are materials.
 *
 * The daedalus_skin entity holds the PACKAGE layer (`tokens`, `defaults`:
 * what a module ships, the fields sync writes and the ship reads, the
 * payload the source hash covers) and the SITE layer (`site_tokens`,
 * `site_defaults`: what the site added or changed over it, by name down
 * to one fact of a token or one property of a default). Every reader but
 * the disk lane reads the merge (tokens(), defaults();
 * SkinTokensItem::mergeLayers(), SkinDefaultsItem::mergeLayers()), so a
 * package update lands in its own layer and shows through wherever the
 * site changed nothing, and removing a site entry reveals the package's.
 * A skin with no provider (the site's own, a "Copy as my skin") has one
 * layer, the package one, edited directly. The site layer is off the
 * blueprint field spine (internal fields): the generic entity ops read
 * and write the package layer alone, and the site layer is written only
 * by the operations below, whose layer rule is
 * SkinManager::writesSiteLayer() (a provider AND the distro lock holding
 * means the site layer; otherwise the package layer).
 *
 * A stored token is a record {value, description, offered}
 * (SkinTokensItem::RECORD_KEYS; `value` a string or a condition map,
 * `description` the reasoning the AI and the Palette read, `offered`
 * whether rows and the AI lead with it, TRUE by default). Every door
 * that takes authored text (the importer, the blueprint field handler)
 * canonicalizes a bare value into its record before validation
 * (StyleMapValidator::validateTokens() admits records only), so a
 * hand-authored skin file may spell `md: 1rem`; stores and
 * machine-written files hold records.
 *
 * Tokens are MaterialKind::Token on the materials spine, served by
 * DaedalusStylishMaterialsProvider and TokenManager:
 * - save_material {kind: token, group: SCALE, label: NAME, value,
 *   description?, offered?} mints (the label IS the name, never slugged;
 *   `global` is refused because a token's membership is its skin's);
 * - update_material {kind: token, id: 'SCALE/NAME', value?,
 *   description?, offered?} changes one or more facts;
 * - remove_material {kind: token, id} removes a site-minted token, drops
 *   a site override to reveal the shipped value, and refuses a shipped
 *   token the site never touched (STYLE_TOKEN_SHIPPED_LOCKED) or a token
 *   something references (STYLE_TOKEN_IN_USE; the Palette's delete
 *   stages TokenDeleteBatch, which rewrites every reference to the
 *   literal first, under "delete tokens in use").
 * Validation runs on the MERGE of both layers with errors re-rooted onto
 * the gesture keys. TokenUsage (`daedalus_token_usage`) records every
 * `var(--x)` reference outside the swatch namespace across host maps,
 * variant styles and skin data, verbatim; which scale a variable belongs
 * to is resolved at lookup through the guide's prefixes. Rename, revert
 * (to the shipped record) and usage ride /daedalus_stylish/tokens/….
 *
 * Site element defaults are written by ONE style operation, update_default
 * {scope, key?, condition?, properties}, the site-scope twin of
 * update_style on DaedalusStylishOperationHandler: a patch of one scope's
 * defaults under one condition, null unsetting, validated through
 * validateDefaults() against the merged tokens (the unminted-var()
 * refusal applies in the closed universe, strict or the declared lane,
 * exactly as for element styles). The `defaults` field has no blueprint
 * write on any skin (SkinDefaultsFieldHandler refuses every payload with
 * SKIN_DEFAULTS_OP_ONLY); the importer lands shipped defaults itself.
 * Permissions follow the per-material pattern: create tokens, update
 * tokens, delete tokens, delete tokens in use, edit skin defaults, and
 * administer skins over them all.
 *
 * The canvas follows a skin session's Push and the skin card's gestures
 * at once: each ends with source.refreshDefaults() (push-bar.js,
 * palette.js), a read of GET /daedalus_stylish/defaults
 * (SkinApiController::defaults(): the merged defaults map and the
 * compiled CSS) that the leader source stamps into the page's defaults
 * delivery tag. An update_default the AI submits during a turn has no
 * such wire, on purpose for now: the mutation response carries the
 * materials delta and not the skin's defaults (a key in
 * daedalus_blueprint's response envelope, kept out of the skin editor's
 * daedalus_blueprint scope), and the page applies that response through
 * daedalus_ui's applyResponse pipeline (op-queue.js), which sees no
 * operation names, so nothing on this module's side can tell that a
 * default changed. The recompiled defaults reach an open editing page at
 * the next page load, or at the next session Push or card gesture.
 * Following at once needs a "defaults changed" signal in the response,
 * recorded as a later topic's candidate, not a stylish-side wire.
 *
 * Which skin a design system wears: `daedalus_stylish.settings`
 * `skin_choice` holds one choice per design system id; SkinManager
 * answers the choice while it names a published member of the active
 * system that conforms to the contract, else the unit's default_skin
 * (judged on every read, so an unpublish, a move or a contract change
 * needs no reaction and the stored choice survives). Choosing, copying
 * (the merge of any skin on the site folded into a new unlocked skin
 * that is a member of the ACTIVE design system whatever its source
 * belonged to; a unit with no package and no default_skin takes its
 * first copy as its default and wears it at once, the door through
 * which a site-created design system gets its first skin, while a
 * packaged unit or one naming a default is never written and its copy
 * waits to be chosen),
 * restoring a revision (layer-aware on a consuming site: the old site
 * layer over the package layer as synced now) and reverting the site
 * layer are SkinManager's gestures, adapted by SkinApiController under
 * /daedalus_stylish/skins/… and `drush daedalus_stylish:skin:choose`;
 * the AI has no choose op by design.
 */

/**
 * A font is an at-rule material on the animation pattern.
 *
 * `@font-face` is a named at-rule block referenced by ident from one
 * property, exactly what keyframes are, so fonts take the animation
 * material's shape: a daedalus_font entity (MaterialKind::Font) with a
 * `family` and `faces` [{weight, style, source: {file: UUID}}], both
 * internal fields written only through the material ops (save_material
 * {kind: font, label, family?, faces, description?}, update_material,
 * remove_material) so FontValidator sees every write. A face's source is
 * a TYPED map: `file` names a managed file the site holds (uploaded
 * through daedalus_ui's file lane, `woff2` and `woff` only, by
 * POST /daedalus_stylish/fonts/files), and a hosted source is a later kind
 * beside `file`, an addition rather than a reshape. FontStylesheet
 * (CompiledStylesheetBase, weight -25, before the swatch file) prints one
 * `@font-face` per face with `font-display: swap`;
 * CssPrinter::printFontFace() is the emit gate and nothing on the client
 * prints a url() (the inspector fetches
 * GET /daedalus_stylish/fonts/stylesheet when the roster's fonts change).
 * The spelling is the quoted family in the `font` slot
 * (StyleGuide::fontFamily()); a reference is that family in a
 * `font-family` stack or in a token on the scale the guide binds
 * `font-family` to (FontFamilies, FontUsage, `daedalus_font_usage`), and
 * deleting a font in use drops the family from every stack first
 * (FontDeleteBatch; a stack that named nothing else becomes `initial`).
 * On disk a face's file is a basename under the module's assets/fonts/
 * and the shipped payload is content-addressed (a sha256 of the bytes),
 * so one hash bridges the site's uuids and the disk's basenames.
 */

/**
 * The disk lane and the package ship.
 *
 * A module ships materials as MODULE.daedalus_stylish.KIND.yml (the
 * hand-authored single file per kind: fonts, skins, variants, swatches,
 * animations) and MODULE.daedalus_stylish.KIND.ID.yml (the per-id form a
 * machine writes), discovered and merged by ShippedDefinitionsImporter and
 * landed by ShippedSync in dependency order (fonts, animations, swatches,
 * skins, variants) on cache rebuild, module install, design system
 * activation and `drush daedalus_stylish:sync [MODULE]`. Each importer
 * stamps provider and source hash (ShippedSourceInterface) and runs the
 * hash loop (created / updated / unchanged / kept / failed); a skin is
 * never kept because the sync writes its package layer alone. A module
 * packaging a design system syncs only while its system is active.
 *
 * The other direction has one command for a whole design system, in
 * daedalus_blueprint: `drush daedalus-blueprint:design-system:ship SYSTEM
 * [MODULE]` creates the package module when absent, lands the unit as
 * install config, then runs every service tagged
 * `daedalus_blueprint.package_shipper` (PackageShipperInterface:
 * packageDependencies(), planPackage(), shipPackage() over one
 * ShipReport) in priority order. This module's lane is the `Ship`
 * service (`daedalus_stylish.ship`) at priority 0, after the blueprint
 * lane (daedalus_blueprint_library's BlueprintShipper at priority 10
 * writes the system's saved blueprints as blueprint files and their
 * owned variants through VariantShipper under the `blueprint:` owner key,
 * which VariantImporter::OWNER_KEY carries back onto the variant's
 * `blueprint` / `blueprint_file` fields without knowing its meaning): it
 * rebuilds every per-id file of the module from the site's
 * entities through each kind's ShippedDefinitionsShipper (FontShipper,
 * AnimationShipper, SwatchShipper, SkinShipper, VariantShipper) and
 * adopts every member of the system nothing ships yet, a font's files
 * copied under assets/fonts/. An entry of the hand-authored single file
 * the site edited is reported `modified` and never written. A module
 * wanting a lane of its own for another kind registers a shipper with
 * the same tag; daedalus_stylish exposes no hook for it.
 */

/**
 * Placement curates offers and birth, never legality.
 *
 * A declaration's grant vocabulary (property_groups and their
 * `properties` allowlists) is a function of the declaration alone: a
 * container nested inside a page band validates exactly what the band
 * validates, because nesting is position, not kind. The resolved answer
 * for one address is the vocabulary roster
 * (\Drupal\daedalus_stylish\Vocabulary\VocabularyRoster, keyed on the address
 * grammar \Drupal\daedalus_blueprint\Materials\Address: kind:*, block_type:*,
 * block_plugin:*, layout:*, base:*, with part and children suffixes), and
 * the validator, the AI catalog, the client payload and the schema all
 * read it, so no consumer holds a roster of its own. Depth may qualify
 * what is OFFERED and what is worn at BIRTH ({axis: {depth: root}} on
 * variant_axes, {variant: name, depth: root} on default_variants; both
 * container-family only, both soft: an existing reference at nested
 * depth is tolerated), never what validates. A write that masks a worn
 * variant's declaration (an own `padding` over a width variant's
 * padding-inline) is a layer mask like any other, surfaced by the
 * inspector's provenance and undone by Reset overrides, never prevented
 * by withholding a grant.
 *
 * Grants are stated over LONGHANDS. A property group declares its
 * shorthand families (PropertyGroupInterface::getShorthands(): Spacing's
 * padding, margin and gap; Border's border-width, border-style,
 * border-color and border-radius), and the roster grants a shorthand iff
 * every longhand it sets is granted; an allowlist naming a shorthand
 * fails the guide build with the longhands to list instead, and a miss on
 * a partly granted family names the missing sides. A curated property
 * with no sides is its own longhand: Border's outline-width,
 * outline-style, outline-color and outline-offset declare no family, so
 * any grant of the group carries them, and the `outline` shorthand stays
 * in the wide tier like `border`, a multi-part value no row represents.
 *
 * Constraint blocks EXPAND over the same families. A block authored on
 * a shorthand (a node's `padding: {scale: space}`, the site's
 * `constraint_defaults` `gap: {scale: space, allowed: ['0']}`) is the
 * block of every longhand of the family that authors none of its own,
 * and a longhand's own block wins on that longhand; the expansion happens
 * once, where the guide serves constraints
 * (StyleGuide::expandConstraintFamilies(), behind getConstraintDefaults()
 * and the roster's node read), so the validator, the client payload, the
 * AI prose and the code view all see per-longhand blocks. The asymmetry
 * with grants is deliberate: a grant is set membership stated over atoms,
 * a constraint is one value rule for the whole family.
 *
 * The enforcement mode (open, guarded, strict) is a property of the
 * active design system (DesignSystemInterface::getEnforcement(); a site
 * with none active is guarded), read through StyleGuide::enforcement().
 * daedalus_stylish.settings carries site mechanics only (ai_minting,
 * container_roots, media_image_style, opt_outs, breakpoints).
 */

/**
 * Axis qualifiers: an axis carries its meaning and the properties it owns.
 *
 * A variant_axes entry may qualify its axis with four keys,
 * {axis: {depth: root, label: Layout, description: ..., owns: [...]}}
 * (\Drupal\daedalus_stylish\StyleGuide documents the grammar and validates it at
 * guide build):
 * - description: the axis's meaning written for a designer, human or
 *   model: the sequence rules, the cardinalities and the "when" no single
 *   value can say ("Alternate tint and plain bands; Accent once, for the
 *   call to action"). A fact about one axis lives on its declaration,
 *   never in prose elsewhere: the description partitions with the design
 *   system whose declaration carries it, rides the vocabulary roster
 *   (VocabularyRoster::axisDescriptions()) to the client payload (each
 *   root node's axes entry, read by variant-scope.js axisDescription())
 *   and the dialect catalog (DialectCatalog::axesFor() entries, the
 *   design system brief), and never enters legality.
 * - owns: the property names the axis's values write (a width axis owns
 *   padding-left, padding-right and max-width; a rhythm axis padding-top
 *   and padding-bottom), each granted on the scope's effective
 *   declaration, or the build fails naming the declaration and the
 *   property. An element write of an owned property is legal and lands
 *   (placement and doctrine never enter legality), and the validator's
 *   element door answers it with the STYLE_PROPERTY_AXIS_OWNED WARNING at
 *   the property's path, naming the axis and its attribute spelling so
 *   the writer wears the axis instead (StyleMapValidator::validate()'s
 *   warnings, surfaced on the field handler's and the update_style op's
 *   results). The element itself only: a part's or the children's box is
 *   not the axis's, and the variant lane never warns, because writing
 *   the owned properties is exactly what an axis variant does.
 *
 * @code
 * container:
 *   variant_axes:
 *     - width:
 *         depth: root
 *         description: 'How wide a page band lets its content run ...'
 *         owns: [padding-left, padding-right, max-width]
 *     - rhythm:
 *         depth: root
 *         label: 'Rhythm'
 *         description: 'The band\'s vertical breathing room ...'
 *         owns: [padding-top, padding-bottom]
 * @endcode
 */

/**
 * Declared defaults: a declaration ships the box facts its markup needs.
 *
 * Three things are called "defaults" around a styled element, and the
 * vocabulary keeps them apart:
 * - DECLARED defaults: the `defaults` section of a declaration (code,
 *   shipped by the module or theme that owns the markup). Literal markup
 *   facts only, in the skin defaults grammar for exactly the declaring
 *   scope: element-relative keys ('' the root, '::.part' a declared
 *   part), conditions, properties. Declared defaults FOLLOW MARKUP
 *   OWNERSHIP, so the keys a declaration may carry are the markup it
 *   renders: a block type, block plugin, or layout declaration any
 *   relative key; a composed kind (`composed: true`, the generative
 *   container kind, which renders its own root) any relative key; any
 *   other kind only '::.part' keys for elements its own declaration
 *   declares (anatomy is asserted by its declarer, so the entity kind may
 *   default a layout root a module renders and declares under it), never
 *   '' (a templated kind's root is the theme's, so its look is the
 *   site's); the site, which renders nothing, none. A key outside the
 *   rule is a build error naming it. Validated at guide build in the
 *   EMPTY minted universe on the declared lane (recognition, grammar,
 *   token-free; never against grants or constraints), so a declaration
 *   cannot name a design token whatever design system is active or which
 *   enforcement mode it carries. The law's reason: a mechanic the markup
 *   needs (a container root's `display: flex; flex-direction: column`,
 *   the stack, without which its gap is dead and its Columns variant has
 *   no direction to flip) is code and belongs with the declaration,
 *   where no skin can forget it; a look is the skin's.
 * - SKIN defaults: the active skin's site defaults per scope (data, the
 *   design system's look; token-bearing box defaults belong here, on the
 *   skin's block_type:/block_plugin:/layout: scopes).
 * - BIRTH defaults: `default_variants`, the variants a new element wears
 *   at placement (law, traveling with the design system).
 *
 * Delivery: the defaults stylesheet prints the declared tier first (in
 * delivery rank order: kind scopes, then block plugins, block types,
 * layouts; stored order within; each at :scope inside @scope at its
 * stamped scope class, so a refinement's default wins over its kind's by
 * document order) with no skin gate, then, only with an active published
 * skin, that skin's tokens and defaults, so a skin value for the same
 * target wins at equal specificity. The stamping demand follows the
 * tier's scopes: a kind that declares defaults has its scope class
 * stamped wherever the family classes already land. The client
 * guide serves the tier as `declaredDefaults` beside `skinDefaults`; the
 * inspector shows a declared value in the defaults posture with the
 * declaring scope named and no edit doorway, and the AI's
 * explain_element_styles prints both tiers as the lowest layers of the
 * element's stack for its scope (StyleStack::stack()), naming the skin's
 * base:{tag} tier as the half only the rendered tag resolves.
 *
 * @code
 * # my_module.page_builder.block_type.image.yml
 * defaults:
 *   '':
 *     default:
 *       display: block
 *       width: fit-content
 *   '::.image-block-image':
 *     default:
 *       display: block
 *
 * # my_module.page_builder.yml: the composed container kind defaults its
 * # own root; the templated entity kind defaults only a part it declares.
 * container:
 *   composed: true
 *   defaults:
 *     '':
 *       default:
 *         display: flex
 *         flex-direction: column
 * entity:
 *   elements:
 *     '.daedalus-layout-layout':
 *       label: 'Layout'
 *       tag: div
 *   defaults:
 *     '::.daedalus-layout-layout':
 *       default:
 *         display: flex
 *         flex-direction: column
 * @endcode
 *
 * A theme restating one property merges per property through the shared
 * layering (a NULL removes it): the theme owns the markup model, so this
 * is the one override doorway; there is no alter hook of its own.
 */

/**
 * Generated boxes: the terminal pseudo-element, its vocabulary, its content.
 *
 * The relative selector (\Drupal\daedalus_blueprint\Materials\RelativeSelector,
 * JS twin daedalus_ui/js/edit_mode/materials/relative-selector.js) admits
 * one terminal pseudo-element, `::before` or `::after`, after the last
 * compound and nowhere else (never inside `:has()` or `:not()`, never
 * followed by a compound, one per selector). The owner's own box is the
 * CSS nesting anchor `&::before`, the one place `&` is admitted (the
 * whole selector; `& > p` is not one); a part's box `.quote::after`; a
 * descendant rule's `> pb-text::before`. The pseudo lives on the selector
 * record (RelativeSelector::$pseudo), not on a compound: a box is a WHERE
 * with no node, the doctrine of `> *`, so it is neither a condition
 * (`:is(::before)` is invalid CSS) nor a markup part (a decorative
 * element would cost a DOM node on every element and cannot reach a
 * template-rendered block). Style map keys read `{key}::&::before`,
 * `{key}::.quote::after`, `{key}::> pb-text::before`; the first `::` of a
 * key still splits root from selector because the anchor or a compound
 * always precedes the box's own `::`. A rule ending in a box admits no
 * continuation (RelativeSelector::under()); a worn fragment's `&::before`
 * under `> pb-text` continues as `> pb-text::before`. Printed, the box
 * comes after the condition suffixes and the animation hold
 * (`:scope:is(:hover, [data-force-state~="hover"])::before`), because a
 * pseudo-element is the last thing a CSS selector may carry; `:where()`
 * cannot wrap it, so every rule addressing a box weighs +0-0-1 on its
 * host's, own and ancestor alike, and the tie-and-proximity discipline
 * holds by construction.
 *
 * EXISTENCE IS UNIVERSAL, VOCABULARY IS DECLARED OR DERIVED. Every element
 * has both boxes in CSS, so no declaration says an element has one; a
 * `pseudo` section on any element declaration (a kind, block type, block
 * plugin, layout, base tag, or any part at any depth; NOT the children
 * section, whose `> *::before` is always derived, and NOT an instance
 * namespace) declares what may be styled there, and only that:
 *
 * @code
 * # my_module.page_builder.block_type.testimonial.yml
 * pseudo:
 *   before:
 *     property_groups:
 *       background: {}
 *       sizing: {}
 *       border: {}
 *   after:
 *     property_groups:
 *       background:
 *         properties: [background-color]
 * elements:
 *   '.quote':
 *     label: 'Quote'
 *     tag: blockquote
 *     pseudo:
 *       after:
 *         property_groups:
 *           typography: {}
 * @endcode
 *
 * The grammar (StyleGuide::validatePseudoSections(), at guide build): a
 * map keyed by `before` and `after` only, each a map carrying exactly
 * `property_groups` in the ordinary grant grammar (allowlists,
 * constraints and referenced sets ride along and are walked like any
 * node's); no `pseudo` inside a pseudo (a box is terminal). The roster
 * walk (\Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder::walk())
 * runs the chain, then lands the box on the chain's landing: the host's
 * declared section is the node when it names the box, else the box
 * DERIVES its host's property groups and is reported undeclared as a
 * pseudo "compound" (VocabularyRoster::KIND_PSEUDO) that still carries the
 * derived grant, the one undeclared shape with one. So an open or guarded
 * site grants the derivation and a strict site refuses it
 * (STYLE_PSEUDO_NOT_DECLARED at the key); a design system that wants a
 * closed world runs strict and declares its boxes. VocabularyRoster::
 * landingScope() is NULL for a box (no variant pairs land on one; a
 * reference at a box key is STYLE_VARIANT_SCOPE_MISMATCH, as for a part).
 * The client payload serves a DECLARED box under its host node as
 * `pseudo.before` / `pseudo.after` and lets style-guide.js derive an
 * undeclared one from the served host, so the JS twin (landingForKey,
 * declaredForKey) answers what the roster answers without a second
 * derivation.
 *
 * `content` IS THE EXISTENCE SWITCH, CLOSED TO `none | "" | ONE MEDIA
 * REFERENCE`. A box exists exactly while the cascade sets its `content`,
 * and that fact is owned by one curated group, `generated_content`
 * (\Drupal\daedalus_stylish\Plugin\PropertyGroup\GeneratedContent, attribute
 * flag `pseudo: TRUE`, PropertyGroupPluginManager::getPseudoGroups()):
 * granted last at every box landing, declared or derived, and nowhere
 * else. Naming it in a real element's, part's or children's
 * property_groups fails the guide build (a `pseudo` section may name it,
 * to constrain `content`); a `content` write outside a box is
 * STYLE_PROPERTY_PSEUDO_ONLY in every enforcement mode. Its grammar
 * (GeneratedContent::validate()) is exactly three spellings: `""` (the
 * box present and empty, GeneratedContent::BOX), `none` (no box,
 * GeneratedContent::NONE), or ONE media reference as the whole value,
 * `url('media:<uuid>')`, the token `background-image` takes. The
 * property carries `media: TRUE` beside `type: keyword`, and that flag is
 * the one seam every media consumer reads, so `content` joins the media
 * machinery with no road of its own: StyleGuide::mediaProperties() lists
 * it beside `background-image` (the pseudo-only groups are folded into the
 * referenced set, since no declaration references them) and the AI
 * guidance derives from that; PropertyGroupBase::validate()'s token gates
 * judge the token (uuid shape, the media exists, its source is an image
 * or vector) and substitute `none` before the keyword grammar sees it;
 * MediaValueResolver delivers it property-blind; a `_generate` object
 * stands in as `none` and commits the token; the client's media control
 * (the library chip, the thumbnail, the text field) rides
 * `definition.media`, while the offers follow the type (the two keywords,
 * never the gradient swatches a free image slot takes). None of the base
 * class's pass-throughs (no CSS-wide keyword, `var()` or `calc()`, since a
 * token of text shape could smuggle a string); no strings, `attr()`,
 * counters or `quotes`, because text is content and belongs in a field,
 * where it is translated, searched and read aloud, while generated text
 * is read by screen readers and invisible to every content surface; and
 * no plain `url()`, gradient, `image-set()`, `/ "alt"` suffix, second
 * token or anything beside the one: each is INVALID_STYLE_VALUE with the
 * three spellings in the hint (a malformed or missing token is the media
 * machinery's own corrective, INVALID_MEDIA_REFERENCE, MEDIA_NOT_FOUND or
 * MEDIA_NOT_IMAGE, at the same path). Two costs are accepted and
 * documented rather than guarded. A content image is replaced content at
 * its intrinsic size, so an image style is the way to size it: a declared
 * box's `generated_content: {constraints: {content: {image_style: …}}}`,
 * else the site default `media_image_style`, exactly as a background
 * resolves (MediaValueResolver::imageStyleFor() reads the box's roster
 * node), while `background-image` on the same box remains the road that
 * sizes, positions and layers. And a content image is exposed to
 * assistive technology as an unlabelled image, where a background is not.
 * A media that no longer exists delivers `content: none`, which on a box
 * means no box: the honest degradation, the same word the seed's
 * opposite uses.
 *
 * A BOX IS BORN WITH `content: ""`, SEEDED AT THE OP SPINE. A box without
 * content is invisible, so a first write that seeded nothing would look
 * like a no-op to every lane. DaedalusStylishOperationHandler's
 * `update_style` is the one door every lane's writes pass (the inspector's
 * rows and chip, the code view's islands, the AI's dialect) and the one
 * seed site: a patch that CREATES a pseudo-element entry merges
 * `{default: {content: ""}}` beneath the validated patch in the same op,
 * whatever condition the write lands on, unless the write sets content at
 * default itself (the author's `content: none` stands, and so does a
 * first write of `content: url('media:…')`: the token, nothing beneath
 * it; the seed is unchanged by the media road). The seed
 * validates with the write, so a strict site's undeclared box refuses the
 * whole patch and mints nothing; an unset-only patch at a box that never
 * existed mints nothing; an existing entry is never re-seeded, so
 * deleting the content line is a deliberate act and the box honestly
 * disappears (explain_element_styles and the code view show why). There
 * is no validator corrective for a contentless box (the validator cannot
 * read the stack, and a variant or ancestor rule may legitimately supply
 * the content) and no client-side seed (an optimistic mirror is replaced
 * by the server's result). Variants and skins are AUTHORED, not born: a
 * variant fragment or a skin default at a box key (`'::&::before'` in the
 * same relative-key grammar as parts) carries its own `content`, and a
 * look worn on a rule continues its box under it; apply_variant mints no
 * box entry and detach_variant materializes a fragment verbatim.
 *
 * Seating is a doorway, the first write births: "+ Before" / "+ After" on
 * the inspector's address chip (advanced mode; basic mode mounts no rows
 * at a box and offers no doorway), a "Before" / "After" row in the Page
 * elements tree (listed once the cascade delivers content at the box; see
 * daedalus_ui.api.php, the part-behavior contract's generated()), or
 * `&::before {` on the code view's root island only target the box.
 * Selection never lands on one (a pointer hits the host; a box has no
 * node), computed reads pass the box to getComputedStyle(host, '::before'),
 * a forced state stamps the host, and rect-measured chrome withholds (a
 * box has no rect). Edit-mode chrome that itself paints on a `::before`
 * (daedalus_explode's plane label chips, daedalus_stylish_layouts' empty
 * container hint) asserts its own box, `all: initial` first under the
 * `#daedalus-ui-stage` id, rather than moving off pseudo-elements, so it
 * wins exactly while it exists and the page's box returns when it leaves.
 * `::marker`, `::placeholder`, `::selection`, `::first-letter` and
 * `::first-line` are refused: each has its own property law and lands
 * separately if ever.
 */

/**
 * Styling follows the host.
 *
 * A style map is read and written through a page document's host
 * (\Drupal\daedalus_blueprint\Host\HostInterface: hasStyleStore(),
 * styleMapDefinition(), styleMap(), setStyleMap()), the one door for a
 * content host's style field (a page's, or a saved blueprint's: the
 * blueprint host of daedalus_blueprint_library's `blueprint` entity is a
 * ContentHost whose subject is a sample of its subject bundle, and its
 * map lives in the style field on the blueprint entity once the bundle is
 * enabled) and a display host's `daedalus_stylish.styles` third-party
 * setting. The display's map is
 * stored as a LIST of {key, entry} rows in map order (config schema
 * daedalus_stylish.style_map) because config refuses a dot anywhere in a
 * key and a part or rule key carries one (`block:{uuid}::.quote`);
 * DisplayHost is the one reader and writer of that shape. The validator,
 * the stack and the value resolver carry the host under the `host` context
 * key (NULL on the variant lane); VariantApplicabilityCheckerInterface::
 * check() and StyleBackendInterface::build() take the host; the save and
 * delete hooks gate on _daedalus_stylish_style_host() (the resolver plus
 * hasStyleStore()), never on a field check; the usage tables and the
 * delete batches walk every host with a style store, Layout Builder
 * displays included (VariantUsage::templateDisplayIds()); and the style
 * map sweeps read and write through the host, so a migration covers
 * templates.
 *
 * Storage stays one flat map; delivery is the page document's one shape:
 * the blueprint's `layout` carries each node's entries on the node (keyed
 * by the local remainder of the selectable key) and the top-level
 * `styles` only the keys no node owns. The flat map is a derived index of
 * that tree with one canonical order, the document's
 * (daedalus_blueprint's NodeTreeBuilder::styleMapOf(): node-less keys
 * first, then every node in document order, each node's own keys as
 * stored); CssStyleBackend prints through orderedStyleMap() and the
 * client's styleMapOf() (style-guide.js) rebuilds the same index from the
 * mirror, so both printers emit identical bytes whatever order the writes
 * left in storage. Nothing reads stored order any more.
 *
 * Delivery follows the host of the RENDER (HostResolver::renderHost(),
 * core's rule: an overridable display with a non-empty override renders
 * the entity's own document, anything else on a Layout Builder display
 * renders the template). RenderStyles (`daedalus_stylish.render_styles`)
 * is the one reading of a rendered entity's maps that CSS delivery
 * (daedalus_stylish_entity_view()), field renders and live attribution
 * demand share, so what is stamped is what is styled. It reads and never
 * authors: a content host seeds an empty override from its template on
 * the first read of either document door, so a never-edited page is read
 * through a host over a disposable clone of its entity (one per entity
 * per request), the inline tag prints the template's element entries
 * under the seeded, derived keys the edit canvas renders the page under
 * on arrival, and the request's entity stays as loaded. On a template
 * render the display's element entries go to a SHARED lane, one html_head
 * tag per display per
 * page keyed by the display and cache-tagged on its config, while the
 * display's document-level entries (`entity:{display uuid}` and its parts)
 * are re-keyed onto the rendered entity's own root key and layered under
 * the entity's own map in the INLINE lane (own wins per condition and
 * property; a variants list replaces whole), so one root attribute, one
 * printer and one client twin serve both. A host with no entity view (a
 * template's canvas on core's manage-display route) receives the same
 * tag, root classes and variants library through
 * hook_daedalus_layout_canvas_root_alter(); _daedalus_stylish_deliver() is
 * the one inline delivery both doors call.
 */

/**
 * Who may style: the style permission and the style policy.
 *
 * Style is one of daedalus_blueprint's five capabilities, judged by its
 * capability resolver at the op door and published on every read (see
 * daedalus_blueprint.api.php, "The capability resolver"). Daedalus
 * Stylish owns the style half of the role grammar as a policy under the
 * spine's tag (\Drupal\daedalus_stylish\Capability\StylePolicy, service
 * daedalus_stylish.capability_policy.style, priority 30 beside the
 * spine's RolePolicy): on a content host, style on any target is open to
 * an actor holding `style {bundle} {type} elements` for the host's bundle
 * or the umbrella `style any elements`; on a template (a display host)
 * style follows `configure any layout` beside the display's update
 * access; a host with no style store has nothing to permit, and the
 * operation answers for itself there. A missing permission is
 * Refusal::permission() (ACCESS_DENIED_PERMISSION, `context.permissions`
 * naming the grants that open the gesture).
 *
 * The per-bundle permission is minted by
 * \Drupal\daedalus_stylish\Capability\StylePermissions
 * (daedalus_stylish.permissions.yml `permission_callbacks`) for every
 * bundle carrying the style map field, with a dependency on that field
 * config, the way core's LayoutBuilderOverridesPermissions mint theirs
 * from the display: the field's existence IS the bundle's styling
 * enablement (DaedalusStylishFieldManager), so the permission exists
 * exactly where styling does and goes with it. A kernel test granting it
 * must create the field before the account (the name must exist).
 *
 * No surface decides style on its own: the inspector and its satellite
 * ask daedalus_ui's one client gate (`capable(key, 'style')`, read from
 * the published `_element.can`), InspectorSidebar::applies() mounts the
 * panel only where the host keeps a style store and
 * VerdictStamper::allowsAnywhere($host, Capability::Style) holds, and a
 * template element a plain host renders is refused style by the spine's
 * ownership policy (a document is the node tree with its styles on the
 * nodes, one unit), the page's own root key staying the page's.
 */

/**
 * Alters property group plugin definitions.
 *
 * @param array $definitions
 *   Property group definitions keyed by plugin ID.
 *
 * @see \Drupal\daedalus_stylish\Attribute\PropertyGroup
 */
function hook_daedalus_stylish_property_group_info_alter(array &$definitions): void {
  // Reorder the inspector: typography above spacing.
  $definitions['typography']['weight'] = 5;
}

/**
 * Alters control widget plugin definitions.
 *
 * @param array $definitions
 *   Control widget definitions keyed by plugin ID.
 *
 * @see \Drupal\daedalus_stylish\Attribute\ControlWidget
 */
function hook_daedalus_stylish_control_widget_info_alter(array &$definitions): void {
  // Extend the properties the spacing widget may write.
  $definitions['spacing_scale']['properties'][] = 'row-gap';
}

/**
 * Alters condition type plugin definitions.
 *
 * @param array $definitions
 *   Condition type definitions keyed by plugin ID.
 *
 * @see \Drupal\daedalus_stylish\Attribute\ConditionType
 */
function hook_daedalus_stylish_condition_type_info_alter(array &$definitions): void {
  // Let a custom condition type's names win over the shipped ones.
  $definitions['my_condition_type']['weight'] = -10;
}

/**
 * @} End of "addtogroup hooks".
 */
