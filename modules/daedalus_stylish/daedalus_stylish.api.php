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
 * Daedalus Stylish's styling sections (property_groups, default_variants, controls,
 * variant_axes, defaults, and the site declaration's token_scales)
 * ride the shared page builder declaration files —
 * PROVIDER.page_builder.yml, PROVIDER.page_builder.block_type.BUNDLE.yml,
 * PROVIDER.page_builder.block_plugin.PLUGIN_ID.yml,
 * PROVIDER.page_builder.layout.LAYOUT_ID.yml — discovered and layered by
 * daedalus_ui (modules in module list order, then the active theme's
 * base chain, then the active theme). To alter the layered result, use
 * hook_page_builder_declarations_alter() (see daedalus_ui.api.php);
 * \Drupal\daedalus_stylish\StyleGuide documents the styling sections and their
 * validation.
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
 *   needs (a container root's `display: grid`, without which its gap is
 *   dead) is code and belongs with the declaration, where no skin can
 *   forget it; a look is the skin's.
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
 * explain_element_styles prints both tiers beneath the stack for the
 * element's scope (StyleStack::floor()), naming the skin's base:{tag}
 * tier as the half only the rendered tag resolves.
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
 *         display: grid
 * entity:
 *   elements:
 *     '.daedalus-layout-layout':
 *       label: 'Layout'
 *       tag: div
 *   defaults:
 *     '::.daedalus-layout-layout':
 *       default:
 *         display: grid
 * @endcode
 *
 * A theme restating one property merges per property through the shared
 * layering (a NULL removes it): the theme owns the markup model, so this
 * is the one override doorway; there is no alter hook of its own.
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
