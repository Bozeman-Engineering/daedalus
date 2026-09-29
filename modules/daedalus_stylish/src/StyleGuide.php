<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\Exception\StyleGuideException;

/**
 * Daedalus Stylish's projection of the page builder declarations: the style guide.
 *
 * The style guide declares what is stylable: which property groups apply
 * to an element, with what constraints, defaults, and basic-mode
 * controls. It is developer-authored YAML living next to the templates it
 * describes, never an entity: renaming a class in a template and updating
 * its style guide entry belong in the same commit.
 *
 * This class is the LAW side only: discovery-side validation at guide
 * build and the raw layered declarations (getKind(), getBlockType(),
 * getLayout(), getBlockPlugin(), baseTagElement(), the site sections).
 * The resolved answer for one address ("what may this thing style": the
 * grants with allowlists and shorthand families resolved, the value
 * spaces, the axes, the parts, the applicable materials, the correctives)
 * is the VOCABULARY ROSTER (Vocabulary\VocabularyRoster, built by
 * Vocabulary\VocabularyRosterBuilder per address and theme), and every
 * consumer reads that: the validator's one door, the dialect catalog and
 * its prose, the client payload and the schema fragments.
 * No consumer projects the declarations for itself. The enforcement mode
 * the roster carries is the active design system's (enforcement()), not
 * site config; daedalus_stylish.settings holds site mechanics only.
 *
 * Discovery, layering, and the file family are daedalus_ui's
 * (\Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations): styling
 * sections ride the SAME PROVIDER.page_builder.yml /
 * PROVIDER.page_builder.block_type.BUNDLE.yml /
 * PROVIDER.page_builder.layout.LAYOUT_ID.yml files as the anatomy they
 * describe, layered modules → base themes → active theme →
 * hook_page_builder_declarations_alter(). Anatomy (the elements: trees,
 * their labels and contains: slots) is daedalus_ui's owned grammar,
 * validated at the spine; daedalus_stylish contributes these foreign sections over
 * the same declarations:
 * - property_groups: group id => optional {constraints, defaults}, on
 *   kinds, block types, layouts, and any part in an elements: tree. A
 *   part's property groups make the part a styling surface; anatomy without
 *   them is addressable in the markup view but offers no declared controls.
 *   An element declaration (a kind, block type, layout, or any part) may
 *   additionally carry a children section ({property_groups}) declaring the
 *   '> *' children selector: the element's direct children as ONE
 *   addressable, declared like a part but WITHOUT an anatomy shell (it
 *   lives beside the elements tree, not inside it, because "all direct
 *   children" is a styling addressable, never a markup wrapper). Its
 *   property_groups are what "{key}::> *" entries may style; an element
 *   declaring none follows the undeclared-part posture (rejected strict,
 *   grammar-only open).
 *   An element declaration (a kind, block type, block plugin, layout, a
 *   base tag or any part) may also carry a pseudo section, {pseudo:
 *   {before: {property_groups}, after: {property_groups}}}, declaring the
 *   element's two generated boxes ("{key}::&::before" on the element
 *   itself, "{key}::.part::after" on a part, "{key}::> pb-text::before"
 *   on the type's roster). Existence is universal (every element has
 *   both boxes in CSS; the box shows once its `content` is set), so the
 *   section declares VOCABULARY only: what may be styled there. Undeclared,
 *   the box derives its host's property_groups (rejected strict as
 *   STYLE_PSEUDO_NOT_DECLARED, granted open and guarded); declared, its
 *   own. The `generated_content` group (`content`, closed to `none | ""`)
 *   is granted at every generated box, declared or derived, and nowhere
 *   else: naming it on a real element fails the build
 *   (validatePseudoSections()), and a `content` write outside a
 *   pseudo-element is refused in every mode.
 *   A group declaration may carry `properties`, an allowlist narrowing the
 *   grant to a subset of the group's own property names ({properties:
 *   [padding-top, padding-bottom, ...]}); omitted, the whole group is
 *   granted. The allowlist is grant vocabulary, so it follows the grant
 *   posture exactly: a property outside it is undeclared on that node
 *   (rejected strict, grammar-only open/guarded) and every offer surface
 *   (widgets, completions, the AI corrective) curates to it in all modes.
 *   Because the value is a list, a higher layer's allowlist REPLACES a
 *   lower layer's wholesale, and `properties: null` withdraws it (the
 *   whole group again). The build validates each entry names a property
 *   the group provides (validatePropertyAllowlists()).
 *   An allowlist is stated over LONGHANDS: a shorthand of the group's
 *   families (PropertyGroupInterface::getShorthands(): padding, margin,
 *   gap, border-width, border-style, border-color, border-radius) is
 *   granted wherever every longhand it sets is granted, and never by its
 *   own name, so the build refuses an allowlist naming one and the roster
 *   derives it (a whole group grants its shorthands with the rest).
 *   A grant is a function of the declaration alone: an allowlist binds
 *   at every placement depth, and no depth qualifier exists on grants.
 *   Placement curates OFFERS and BIRTH (the variant_axes and
 *   default_variants depth qualifiers below), never legality: a nested
 *   container and a page band validate one vocabulary, and an own write
 *   that masks a worn variant's declaration (padding over a width
 *   variant's padding-inline) is surfaced by the inspector's provenance,
 *   never prevented by withholding.
 *   A property constraint may reference a token scale ({scale: space}):
 *   the property's value space becomes the scale's minted tokens as var()
 *   references, plus any allowed entries declared beside it — a closed
 *   list whose members are data (the active skin's tokens) while the
 *   constraint itself stays code. Beside scale, `stops` curates the
 *   OFFER ({scale: space, stops: ['2', '4', '8', '16']}): an ordered
 *   list of token names naming which of the scale's tokens the
 *   basic-mode stop rows present, in list order — offer-shaping only,
 *   never legality (validation keeps accepting the whole scale; a scale
 *   mints more tokens than a chip row fits). Entries are token names as
 *   the skin spells them (scalars accepted — a numeric name like 2
 *   arrives as an int from YAML and compares as its string form). The
 *   build validates the grammar (stops requires scale beside it, a
 *   non-empty list of non-empty scalars); MEMBERSHIP is a data question
 *   against the active skin's minted names, answered at delivery — the
 *   client drops un-minted names from the offer and the payload build
 *   logs the drift (a guide-build error here would let skin data brick
 *   the guide). The build validates the scale is declared
 *   (validateScaleConstraints()).
 *   Constraint blocks expand over SHORTHAND FAMILIES (the groups'
 *   getShorthands() tables: padding over its four sides, gap over
 *   row-gap and column-gap, border-radius over the four corners): a
 *   block authored on the shorthand is the block of every longhand of
 *   the family that authors none of its own, and a longhand's own block
 *   wins on that longhand. The expansion happens ONCE, where the guide
 *   serves constraints (expandConstraintFamilies(): the site's
 *   getConstraintDefaults() and the roster's node read), so every
 *   consumer (the validator, the client payload, the AI prose, the code
 *   view) sees per-longhand blocks and none expands for itself; the
 *   build validates each block as authored. The asymmetry with grants
 *   is deliberate: a grant is set membership stated over atoms, a
 *   constraint is one value rule for the whole family.
 * - default_variants: the shipped variants op-created placements receive
 *   (palette block placements, flat add_section sections; elements authored in
 *   a page document, the dialect's or a create-entity blueprint's, are never
 *   stamped: the document is a total statement), a map of axis => variant
 *   machine name covering any subset of the scope's axis vocabulary. An entry
 *   may instead be a depth-qualified map, {variant: row, depth: root}, valid
 *   only on container-family declarations (the "container" kind and layouts):
 *   the default then applies to TOP-LEVEL sections only while the axis itself
 *   stays offered at every depth — the default_variants dual of the
 *   variant_axes depth qualifier, for axes whose vocabulary is depth-blind but
 *   whose house default is page-band business (a page band born wearing a house
 *   variant a nested container is not). The map form exists to carry the
 *   qualifier; an unqualified entry is spelled as the plain machine name. Maps
 *   merge per key across layers, so a theme can override one axis's default
 *   without restating the others. The build validates the grammar and the axis
 *   vocabulary; whether each named variant exists, is published, and matches
 *   the scope is a content question answered at placement time, where a bad
 *   default degrades to an unstyled placement.
 * - variant_axes: additional variant axes for the scope, an ordered list.
 *   Every scope has the built-in "style" axis (STYLE_AXIS); declared axes
 *   extend that vocabulary. An element wears at most one variant per axis
 *   and composes variants across axes, so the axes are the scope's
 *   orthogonal treatment dimensions (a container's layout and
 *   width, say). Each entry is either an axis machine name or a one-key
 *   map qualifying the axis, {axis: {depth: root, label: Layout,
 *   description: ..., owns: [padding-left, padding-right]}}: the
 *   qualifiers are depth (only value "root"), label (a display string
 *   for the wear bar row, the axis picker, and the library; omitted, the
 *   capitalized machine name serves), description (the axis's meaning
 *   written for a designer, human or model: the sequence rules, the
 *   cardinalities and the "when" no single value can say; it partitions
 *   with the design system whose declaration carries it and reaches the
 *   AI through the vocabulary roster and the dialect catalog) and owns
 *   (the property names the axis's values write, each granted on the
 *   scope's effective declaration: a document wears the axis instead of
 *   writing them, and the validator's element door answers such a write
 *   with the STYLE_PROPERTY_AXIS_OWNED warning, never an error, because
 *   placement and doctrine never enter legality). Depth is meaningful
 *   only on container-family declarations (the "container" kind and
 *   layouts), where it marks the axis as page-band business: root-only
 *   axes are neither offered nor defaulted on NESTED containers, a NEW
 *   wearing at nested depth is refused (RootOnlyVariantChecker on the op
 *   spine, AXIS_NOT_TOP_LEVEL in the dialect), and the wear is a fact of
 *   depth, never a memory: a move that nests a container strips its
 *   root-only references and a move back to root redresses the layout's
 *   defaults (ElementStylesSubscriber::onElementMoved). Only the
 *   validator stays depth-blind on references (a whole-map write cannot
 *   tell a new reference from an old one), so one smuggled past the gate
 *   renders until the next move across depth. Because the value is a
 *   list, a higher layer's
 *   declaration REPLACES a lower layer's wholesale (list merge
 *   semantics), never appends to it.
 * - token_scales: the design-token vocabulary's scale declarations, on the
 *   SITE declaration only (the reserved top-level `site` key of universal
 *   files — tokens are site vocabulary, not any element's). A map of scale
 *   machine name => {label, shape, optional prefix, keywords for the
 *   keyword shape}. The shape names the value model every token minted on
 *   the scale validates against, reusing the property machinery's scalar
 *   shapes (TOKEN_SHAPES). Each token delivers as the CSS custom property
 *   --{prefix}-{name}, prefix defaulting to the scale's machine name
 *   (tokenVariable()); effective prefixes must be unambiguous — no
 *   duplicates, and none extending another with a hyphen, because token
 *   NAMES may contain hyphens, so an extending prefix would make var()
 *   references irresolvable to one scale. Scales are code (this grammar);
 *   token names and values are data on skin entities, validated against
 *   the declared scales.
 * - constraint_defaults: the site's authored offer vocabulary, on the SITE
 *   declaration only — site-wide constraint blocks, CSS property => the
 *   same {scale, stops, allowed, min, max, units} grammar node constraints
 *   speak (font-size: {scale: text} binds the type ramp site-wide). The
 *   BASE layer of constraint resolution: a node authoring its own block
 *   for the property wins on that node; every other node granting the
 *   property carries the default (law while constraintsAreLaw(),
 *   offer-shaping on open sites). A block on a shorthand (padding, gap,
 *   border-radius) is served expanded to the longhands of its family
 *   that author no block of their own. Build-time validation is code-vs-code
 *   facts only (the property is recognized, a referenced scale is
 *   declared, the stops grammar) — stops entries are token NAMES, skin
 *   data checked at delivery, never here.
 * - base: the bare-markup vocabulary, on the SITE declaration only — the
 *   ONE declaration of a prose tag's styleability. A map of HTML tag =>
 *   declaration ({property_groups} with constraints/defaults, optionally
 *   elements for parts), each tag forming BOTH the "base:{tag}" scope of
 *   the skin defaults tier (what a skin may style on prose markup no
 *   element key addresses: an h2 inside body text, links, list items) and
 *   the element-scoped tag tier ("{key}::{tag}", the same tag inside one
 *   element): two lanes, one vocabulary, so what a skin may set on a tag
 *   site-wide is exactly what an element may override on it. Instance
 *   namespaces (the spine's `instances:` lane) declare IDENTITY (the tags
 *   the minter mints, with their labels) and the groups their minted
 *   CLASS parts style; they never declare a tag's styleability. The tag
 *   vocabulary is CLOSED (BASE_TAGS): base defaults print the tag into a
 *   live selector on every page, so the legal tags are code, exactly
 *   like condition names — an undeclared-but-legal tag degrades to an
 *   empty declaration for skin defaults (grammar-checked-only on
 *   permissive sites, nothing stylable in strict) and is no tag-tier
 *   target at all (STYLE_TAG_NOT_DECLARED in every mode: the tier is
 *   declared spelling, never an open tag-selector lane); a tag outside
 *   the vocabulary is not a scope at all.
 * - defaults: the declared defaults tier — the literal box facts the
 *   declaring markup needs (the image block's picture is a block box; its
 *   linked root shrink-wraps the picture; the composed container's root
 *   is a grid so its gap acts), shipped as spine data by the declaration
 *   that owns the markup instead of as module CSS the layers lens cannot
 *   show. Declared defaults FOLLOW MARKUP OWNERSHIP: a block type, block
 *   plugin, or layout declaration may default its root and its declared
 *   parts; a kind may default its declared parts (anatomy is asserted by
 *   its declarer: a `::.part` key for an element in the kind's own
 *   declaration) and its root ONLY when the kind is composed
 *   (`composed: true`: the declaration is generative and renders the
 *   root itself); a non-composed kind's root comes from whatever template
 *   is in play, so a '' key there and any defaults on the site (which
 *   renders no markup) are build errors: that look is the skin's. The
 *   grammar is the skin defaults grammar for exactly the scope the
 *   declaration declares: {relative key: entry} with element-only keys
 *   ('' the root, '::.part' a declared part), each entry a
 *   condition-keyed property map. Three vocabularies share the word:
 *   DECLARED defaults are the declaration's (code, this section), SKIN
 *   defaults are the skin entity's (data, the site's look), BIRTH
 *   defaults are default_variants (law). Validated at guide build through
 *   the one defaults path (StyleMapValidator::validateDeclaredDefaults(),
 *   riding validateAt()) in the EMPTY minted universe: a declaration
 *   names no token whatever system is active or which enforcement mode
 *   it carries (token-bearing box defaults are design values and belong on the
 *   skin's defaults for the same scope). Delivered beneath the skin's tiers at
 *   equal specificity in delivery rank order (kind scopes before refinements,
 *   so a refinement's default wins over its kind's by document order, exactly
 *   as the skin's tiers rank), so any skin default for the same target wins
 *   likewise; declaredDefaults() is the tier's read.
 * - controls: curated basic-mode bindings keyed by control name, each a {label,
 *   widget, element, section} map naming a ControlWidget plugin and the
 *   addresses it drives. The element key is an ORDERED CANDIDATE LIST: each
 *   entry is '' (the element root, the declared-defaults grammar's spelling)
 *   or a declared part's selector, and the client resolves each property the
 *   widget owns to ONE candidate (the first whose stack delivers it from an
 *   authored layer, else the first delivering it at any tier, else the first
 *   present one) and reads and writes that candidate, so a block with more
 *   than one box (a text block's tag and its link) shows one row per binding
 *   with no tag exposed. Two shorthands exist for authoring only: a string is
 *   the one-candidate list, and omitting the key is [''] (the root). The
 *   guide normalizes every binding through controlCandidates() before
 *   validating and serving it, so the client and the validator see lists
 *   only. The section key is optional: it moves the control out of its
 *   widget's default basic-mode section into another of the ControlSection
 *   roster. A kind may bind a part its own declaration declares (the entity
 *   kind's `.daedalus-layout-layout`, the layout root daedalus_layout renders
 *   in every template and mode); a binding renders while any candidate is
 *   present, so a part a template never prints simply offers nothing.
 *   Instance-namespace controls stay root-bound: instances are content and
 *   declare no parts.
 *
 * Control declarations are validated when the guide is first read for a theme
 * (widget plugin exists, the section, when given, is in the roster, the
 * element key is a well-formed candidate list, every candidate is '' or a
 * declared part, and every candidate's property groups cover the widget's
 * owned properties: the part's groups for a part candidate, the element's
 * effective groups for the root candidate, the kind's own groups for a kind
 * control) and a bad binding throws StyleGuideException — failing loudly
 * beats shipping a control whose writes the validator would bounce at
 * runtime. A control whose whole value is FALSE is a tombstone suppressing an
 * inherited control and is valid as-is; unlike NULL, scalar FALSE survives
 * merge(), so a lower layer's version cannot resurface.
 *
 * @see \Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations
 */
class StyleGuide {

  /**
   * The built-in variant axis every scope has.
   *
   * A scope declaring no variant_axes speaks exactly this one axis, and an
   * axis-less variant lands on it, so single-slot behavior is the
   * degenerate one-axis case, not a mode.
   */
  public const STYLE_AXIS = 'style';

  /**
   * The one value the "depth" qualifier accepts.
   *
   * {axis: {depth: root}} in a section-family variant_axes list marks the
   * axis root-only: offered and defaulted for top-level sections only,
   * never for sections nested inside another section's region. The same
   * value qualifies a default_variants entry as root-only. Both faces
   * curate offers and birth; grants (property_groups allowlists) carry no
   * depth, because placement never decides legality.
   */
  public const DEPTH_ROOT = 'root';

  /**
   * The value shapes a token scale may declare.
   *
   * The scalar shapes of the property machinery
   * (PropertyGroupBase::validateScalar()): dimension, number, color, a
   * closed keyword vocabulary, and text for multi-part values no scalar
   * shape models ("150ms ease"). A scale's shape is what every token
   * minted on it validates against.
   */
  public const TOKEN_SHAPES = ['dimension', 'number', 'color', 'keyword', 'text'];

  /**
   * The closed tag vocabulary of the base:{tag} scope family.
   *
   * The prose-markup surface site defaults may style: text flow, links,
   * lists, quotations, code, figures, tables, and images — base.css
   * territory. Closed because base defaults compile the tag into live
   * selectors on every page: the legal tag set is code (like condition
   * names), while which tags a site declares stylable (the site
   * declaration's base section) and what values a skin supplies (the skin
   * entity's defaults field) are the usual code/data split.
   */
  public const BASE_TAGS = [
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'p', 'a', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
    'blockquote', 'pre', 'code', 'em', 'strong', 'small', 'sub', 'sup',
    'figure', 'figcaption', 'hr',
    'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    'img',
  ];

  /**
   * The word for each closed base tag, as prose speaks it.
   *
   * One label per BASE_TAGS entry, the word a surface uses where a tag
   * tier is spelled in a sentence ("Every link in Welcome", a tag row's
   * name, the completion's tag offer). The tag set is closed and the
   * word for a tag is not a design opinion, so the words are code beside
   * the tags; a site's base declaration overrides one with its own
   * `label:` (baseTagLabels() resolves the two). The instance lane's tag
   * words (instanceTags()) are identity words for MINTED tags; the tag
   * tier resolves to the base declaration for every owner, so the base
   * word wins over the instance word for the same tag.
   */
  public const BASE_TAG_LABELS = [
    'h1' => 'Heading 1',
    'h2' => 'Heading 2',
    'h3' => 'Heading 3',
    'h4' => 'Heading 4',
    'h5' => 'Heading 5',
    'h6' => 'Heading 6',
    'p' => 'Paragraph',
    'a' => 'Link',
    'ul' => 'Bulleted list',
    'ol' => 'Numbered list',
    'li' => 'List item',
    'dl' => 'Definition list',
    'dt' => 'Term',
    'dd' => 'Definition',
    'blockquote' => 'Block quote',
    'pre' => 'Preformatted text',
    'code' => 'Code',
    'em' => 'Emphasis',
    'strong' => 'Strong',
    'small' => 'Small print',
    'sub' => 'Subscript',
    'sup' => 'Superscript',
    'figure' => 'Figure',
    'figcaption' => 'Caption',
    'hr' => 'Rule',
    'table' => 'Table',
    'caption' => 'Table caption',
    'thead' => 'Table head',
    'tbody' => 'Table body',
    'tfoot' => 'Table foot',
    'tr' => 'Table row',
    'th' => 'Header cell',
    'td' => 'Cell',
    'img' => 'Image',
  ];

  /**
   * The reserved variable prefix user swatches deliver under.
   *
   * Swatches (the palette's user half, daedalus_swatch entities) live
   * OUTSIDE every declared token scale, in their own namespace:
   * --swatch-{machine_name}. The reservation is what keeps the two
   * vocabularies from ever colliding — validateTokenScales() refuses any
   * scale whose effective prefix equals or hyphen-extends this one, so a
   * var() reference resolves to a scale OR a swatch, never ambiguously to
   * both. It is also what keeps strict-guide curation intact: a {scale: x}
   * constrained property accepts only the scale's minted tokens, so
   * minting a swatch never extends a scale-locked option set.
   */
  public const SWATCH_PREFIX = 'swatch';

  /**
   * The reserved keyframes-name prefix site animations deliver under.
   *
   * Animations (the motion material, daedalus_animation entities) are
   * referenced by a bare custom-ident, not through var(): a keyframes name
   * is an ident component of animation-name or the animation shorthand.
   * The prefix is what partitions them: motion-{machine_name} is never an
   * animation keyword (none, ease, infinite, alternate...), never a
   * theme's own keyframes name, and only the provider knows it, so the
   * validator, the usage scan and the completions can tell a site
   * animation from a theme keyframes by spelling alone. Plain idents stay
   * literals (a theme keyframes) on permissive sites and are refused on
   * strict ones, exactly like a literal font family.
   */
  public const ANIMATION_PREFIX = 'motion';

  /**
   * The canonical key order of an AI style_guide fragment.
   *
   * The fragment reads materials-first: the valid space's enumerable
   * parts (the materials roster), then the applicability story (bindings,
   * deviations), the closed world where one exists (stylable), the
   * guidance paragraph carrying the standing procedure and the polarity
   * sentence, and the supporting vocabularies last. Field handlers author
   * their fragments in this order directly; the alter hook that lands the
   * materials are the schema's own top-level key (core renders them from
   * the roster), never part of this fragment.
   */
  public const FRAGMENT_ORDER = [
    'bindings',
    'deviations',
    'stylable',
    'guidance',
    'conditions',
    'current_values',
    'anatomy',
    'base_tags',
    'scopes',
  ];

  /**
   * The keys a token scale declaration may carry.
   */
  protected const TOKEN_SCALE_KEYS = ['label', 'shape', 'prefix', 'keywords'];

  /**
   * The shape of a scale machine name.
   *
   * Lowercase letters, numbers, and underscores, starting with a letter —
   * the codebase's machine-name convention, because constraints and skin
   * data reference scales by this key. The CSS-facing name is the derived
   * variable prefix, which the scale can override with `prefix` when the
   * machine name is not the ident it wants in CSS.
   */
  protected const TOKEN_SCALE_NAME = '/^[a-z][a-z0-9_]*$/';

  /**
   * The shape of a scale's declared variable prefix.
   *
   * A CSS-friendly ident segment: lowercase letters, numbers, and hyphens,
   * starting with a letter and never ending in a hyphen — the derived
   * variable inserts the separating hyphen itself (--{prefix}-{name}).
   */
  protected const TOKEN_SCALE_PREFIX = '/^[a-z](?:[a-z0-9-]*[a-z0-9])?$/';

  /**
   * Themes whose declarations passed control validation this request.
   *
   * The spine caches the layered declarations; daedalus_stylish only re-checks its
   * own sections' bindings, so a memo per theme keeps the per-key guide
   * lookups (one per style map entry) from re-validating.
   *
   * @var array<string, true>
   */
  protected array $validated = [];

  /**
   * Memoized media-capable property names, keyed by theme.
   *
   * @var array<string, string[]>
   */
  protected array $mediaProperties = [];

  /**
   * Memoized declared defaults per scope, keyed by theme.
   *
   * @var array<string, array<string, array>>
   */
  protected array $declaredDefaults = [];

  /**
   * Constructs the style guide.
   *
   * @param \Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations $declarations
   *   The page builder declarations.
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager.
   * @param \Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem $activeDesignSystem
   *   The active design system.
   * @param \Drupal\daedalus_stylish\ControlWidgetPluginManager $controlWidgetManager
   *   The control widget plugin manager.
   * @param \Drupal\daedalus_stylish\PropertyGroupPluginManager $propertyGroupManager
   *   The property group plugin manager.
   * @param \Closure $styleMapValidator
   *   Resolves to the daedalus_stylish.style_map_validator service. A service
   *   closure, not the service: the validator injects this guide (every
   *   scope it resolves is a guide read), so the declared-defaults check
   *   the guide runs at build reaches it lazily.
   * @param \Closure $vocabularyRosters
   *   Resolves to the daedalus_stylish.vocabulary_roster service, the roster
   *   builder projecting these declarations per address. A closure for
   *   the same reason: the builder injects this guide, and resetCache()
   *   drops its memo beside the declarations it was built from.
   */
  public function __construct(
    protected PageBuilderDeclarations $declarations,
    protected ThemeManagerInterface $themeManager,
    protected ActiveDesignSystem $activeDesignSystem,
    protected ControlWidgetPluginManager $controlWidgetManager,
    protected PropertyGroupPluginManager $propertyGroupManager,
    protected \Closure $styleMapValidator,
    protected \Closure $vocabularyRosters,
  ) {}

  /**
   * The open enforcement mode: the guide shapes offers, never writes.
   */
  public const ENFORCEMENT_OPEN = DesignSystemInterface::ENFORCEMENT_OPEN;

  /**
   * The guarded enforcement mode: authored constraints are law.
   */
  public const ENFORCEMENT_GUARDED = DesignSystemInterface::ENFORCEMENT_GUARDED;

  /**
   * The strict enforcement mode: a closed world of declared properties.
   */
  public const ENFORCEMENT_STRICT = DesignSystemInterface::ENFORCEMENT_STRICT;

  /**
   * The site's enforcement mode: the active design system's.
   *
   * A shipped system owns the meaning of its law, so the mode is a
   * property of the design_system entity (DesignSystemInterface::
   * getEnforcement()), never site config; a site with no active system
   * (or a pointer naming a departed one) is guarded. This is the ONE
   * server-side reader; the client reads the payload's `enforcement`.
   *
   * - open: the guide shapes offers only; any recognized property with a
   *   grammatical value is accepted, authored constraints are never law.
   * - guarded (the default): authored constraints are law; everything
   *   undeclared stays open with recognition and grammar checks.
   * - strict: a closed world, only explicitly declared properties may be
   *   styled, and var() references in declared scale namespaces must name
   *   minted tokens (published swatches for the swatch namespace).
   *
   * Callers should ask the question they mean through propertiesClosed()
   * or constraintsAreLaw() rather than comparing mode strings.
   */
  public function enforcement(): string {
    return $this->activeDesignSystem->load()?->getEnforcement() ?? self::ENFORCEMENT_GUARDED;
  }

  /**
   * The cache tags enforcement's source carries.
   *
   * What a persisted product of the mode (the vocabulary roster's
   * metadata, and through it the payload, the prompt, the conformance
   * verdict) depends on: the activation pointer (which system answers)
   * and the active system's own entity tag (its mode changing on an
   * edit). With no system active the pointer alone: the next activation
   * changes it.
   *
   * @return string[]
   *   The tags.
   */
  public function enforcementCacheTags(): array {
    return array_merge(['config:' . ActiveDesignSystem::CONFIG_NAME], $this->activeDesignSystem->load()?->getCacheTags() ?? []);
  }

  /**
   * Whether the property and token worlds are closed (strict mode).
   *
   * A closed-world site styles only explicitly declared properties, and
   * its declared-namespace var() references must resolve (the minting
   * check).
   */
  public function propertiesClosed(): bool {
    return $this->enforcement() === self::ENFORCEMENT_STRICT;
  }

  /**
   * Whether authored constraints are enforced as law (guarded or strict).
   *
   * Open mode demotes every authored constraint to offer-shaping.
   */
  public function constraintsAreLaw(): bool {
    return $this->enforcement() !== self::ENFORCEMENT_OPEN;
  }

  /**
   * Gets the merged declaration for an element kind.
   *
   * @return array
   *   A declaration with a property_groups map, or [] if nothing is declared.
   */
  public function getKind(string $kind, ?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->declarations->getKind($kind, $theme);
  }

  /**
   * Gets the merged declaration for a block type.
   *
   * The universal "block" kind declaration is the base layer, so an
   * undeclared bundle resolves to it and a declared bundle refines it.
   *
   * @return array
   *   A declaration with property_groups, and optionally default_variants and
   *   elements (sub-element declarations keyed by CSS selector).
   */
  public function getBlockType(string $bundle, ?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->declarations->getBlockType($bundle, $theme);
  }

  /**
   * Lists the block types the declarations explicitly cover.
   *
   * @return string[]
   *   Block type bundle machine names.
   */
  public function getBlockTypes(?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->declarations->getBlockTypes($theme);
  }

  /**
   * Gets the merged declaration for a plugin block.
   *
   * The universal "block" kind declaration is the base layer, so an
   * undeclared plugin resolves to it and a declared plugin refines it. The
   * plugin id is to plugin blocks what the bundle is to inline blocks;
   * derivative ids resolve to their base plugin's declaration (the spine
   * strips the suffix).
   *
   * @param string $plugin_id
   *   A block plugin id, e.g. "system_menu_block" or
   *   "system_menu_block:main".
   * @param string|null $theme
   *   The theme whose declarations to read, or NULL for the default.
   *
   * @return array
   *   A declaration with property_groups, and optionally default_variants
   *   and elements (sub-element declarations keyed by CSS selector).
   */
  public function getBlockPlugin(string $plugin_id, ?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->declarations->getBlockPlugin($plugin_id, $theme);
  }

  /**
   * Lists the block plugins the declarations explicitly cover.
   *
   * @return string[]
   *   Base block plugin ids.
   */
  public function getBlockPlugins(?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->declarations->getBlockPlugins($theme);
  }

  /**
   * Gets the merged declaration for a section layout.
   *
   * The universal "container" kind declaration is the base layer, so an
   * undeclared layout resolves to it and a declared layout refines it.
   *
   * @param string $layout_id
   *   A layout plugin id, e.g. "daedalus_stylish_container".
   * @param string|null $theme
   *   The theme whose declarations to read, or NULL for the default.
   *
   * @return array
   *   A declaration with property_groups, and optionally default_variants and
   *   elements (sub-element declarations keyed by CSS selector).
   */
  public function getLayout(string $layout_id, ?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->declarations->getLayout($layout_id, $theme);
  }

  /**
   * Lists the layouts the declarations explicitly cover.
   *
   * @return string[]
   *   Layout plugin ids.
   */
  public function getLayouts(?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->declarations->getLayouts($theme);
  }

  /**
   * Gets the merged instance-namespace declarations.
   *
   * The spine's `instances:` lane (PageBuilderDeclarations::getInstances()):
   * content-borne identity-class vocabularies keyed by pattern selector
   * ('.s-*'), each carrying the namespace's label, its declared tags, and
   * the styling sections daedalus_stylish layers there (property_groups). Instance
   * parts resolve through these AFTER the owner's own declarations: exact
   * part, then owner pattern part, then the site instance namespaces.
   *
   * @return array<string, array>
   *   Instance declarations keyed by pattern selector.
   */
  public function getInstances(?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->declarations->getInstances($theme);
  }

  /**
   * The instance-namespace declaration covering a concrete part selector.
   *
   * The last step of the one resolution rule (exact part -> owner pattern
   * part -> site instance namespaces), applied once by the vocabulary
   * roster's part rule (VocabularyRosterBuilder, read by the validator and
   * the media resolver alike); declarationForKey() is the client twin.
   *
   * @param string $selector
   *   A concrete part selector, e.g. ".s-a3".
   * @param string|null $theme
   *   The theme whose declarations to read, or NULL for the default.
   *
   * @return array|null
   *   The namespace's declaration, or NULL when no declared namespace
   *   covers the selector.
   */
  public function instanceElement(string $selector, ?string $theme = NULL): ?array {
    return PageBuilderDeclarations::findInstanceDeclaration($this->getInstances($theme), $selector);
  }

  /**
   * The base declaration a tag-tier tag resolves and validates against.
   *
   * The tag half of sub-element resolution: a "{key}::p" tag-tier key
   * styles every one of the element's text elements of that tag (the
   * middle tier of the three-tier text model: skin base:{tag} -> element
   * tag tier -> minted part), and its vocabulary is the site declaration's
   * base section, the one declaration of a prose tag's styleability. The
   * tier validates against base.{tag}.property_groups: what a skin may set
   * on the tag site-wide is exactly what an element may override on it,
   * closed-world under strict like any declared node. The one server-side
   * resolver is the vocabulary roster's part rule
   * (VocabularyRosterBuilder, read by the validator and the media
   * resolver alike); findBaseTagDeclaration() is the client twin.
   *
   * @param string $tag
   *   A tag name, e.g. "p".
   * @param string|null $theme
   *   The theme whose declarations to read, or NULL for the default.
   *
   * @return array|null
   *   The tag's base declaration, or NULL when the site's base section
   *   does not declare the tag (legal or not): no tag tier exists for it.
   */
  public function baseTagElement(string $tag, ?string $theme = NULL): ?array {
    $base = $this->getBaseElements($theme);
    return array_key_exists($tag, $base) && is_array($base[$tag]) ? $base[$tag] : NULL;
  }

  /**
   * The tag-tier vocabulary: every tag the site's base section declares.
   *
   * The styleability read, in authoring order. Correctives, the AI
   * catalog, and the client's tag offer enumerate the legal "{key}::{tag}"
   * targets from here; instanceTags() is the identity read (labels).
   *
   * @return string[]
   *   Tag names; [] when the site declares no base section.
   */
  public function baseTags(?string $theme = NULL): array {
    return array_map('strval', array_keys($this->getBaseElements($theme)));
  }

  /**
   * The declared identity vocabulary: every instance namespace's tags.
   *
   * The merged {tag: label} map across the site's instance namespaces, in
   * declaration order (first declaration wins a duplicate tag). This is
   * IDENTITY: the tags the minter mints, the CKEditor contract delivers,
   * and the annotator labels instance rows with. It is not the tag tier's
   * vocabulary (baseTags() is): a tag may carry identity without being
   * styleable, or be styleable without ever minting (links).
   *
   * @return array<string, string>
   *   Tag names mapped to their declared labels; [] when no namespace is
   *   declared.
   */
  public function instanceTags(?string $theme = NULL): array {
    $tags = [];
    foreach ($this->getInstances($theme) as $declaration) {
      if (is_array($declaration) && is_array($declaration['tags'] ?? NULL)) {
        foreach ($declaration['tags'] as $tag => $label) {
          $tags[(string) $tag] ??= (string) $label;
        }
      }
    }
    return $tags;
  }

  /**
   * The word for every closed base tag, the site's declared labels applied.
   *
   * Every BASE_TAGS entry in vocabulary order, mapped to the site's base
   * declaration `label:` for the tag when it declares one, else the
   * shipped word (BASE_TAG_LABELS). Declared or not, styleable or not:
   * a surface spelling a tag in prose reads its word here and never
   * falls through to the tag itself for a closed tag.
   *
   * @return array<string, string>
   *   Tag names mapped to their words.
   */
  public function baseTagLabels(?string $theme = NULL): array {
    $declared = $this->getBaseElements($theme);
    $labels = [];
    foreach (self::BASE_TAGS as $tag) {
      $label = $declared[$tag]['label'] ?? NULL;
      $labels[$tag] = is_string($label) ? $label : self::BASE_TAG_LABELS[$tag];
    }
    return $labels;
  }

  /**
   * The declared defaults tier: every declaration's own defaults, by scope.
   *
   * The validated `defaults` sections of the theme's layered kind, block
   * type, block plugin, and layout declarations, keyed by the declaring
   * scope ("kind:container", "block_type:image",
   * "block_plugin:system_menu_block", "layout:layout_onecol") in delivery
   * rank order (kinds, then block plugins, block types, layouts; stored
   * order within), each the skin-defaults-shaped {relative key: entry}
   * map. Theme-aware like every guide read: a theme restating a module's
   * defaults merges per property through the spine's layering (merge()),
   * so the answer here is the effective tier for that theme. The defaults
   * stylesheet, the stamping demand, and the client payload all read this
   * one answer. Memoized per theme with the guide.
   *
   * @return array<string, array<string, array>>
   *   {scope: {relative key: entry}}; [] when no declaration declares
   *   defaults.
   */
  public function declaredDefaults(?string $theme = NULL): array {
    $theme ??= $this->themeManager->getActiveTheme()->getName();
    if (!isset($this->declaredDefaults[$theme])) {
      $declared = [];
      foreach ($this->declaredDefaultsAsAuthored($this->getDefinitions($theme)) as [, , $scope, $defaults]) {
        $declared[$scope] = $defaults;
      }
      $this->declaredDefaults[$theme] = $declared;
    }
    return $this->declaredDefaults[$theme];
  }

  /**
   * Gets the whole layered declaration set.
   *
   * @return array
   *   ['kinds' => [kind => declaration], 'block_types' => [bundle =>
   *   declaration], 'block_plugins' => [plugin_id => declaration],
   *   'layouts' => [layout_id => declaration], 'site' => declaration].
   *   Block type, block plugin, and layout declarations here are
   *   as-authored; use getBlockType()/getBlockPlugin()/getLayout() for the
   *   effective declaration with the universal kind layered underneath.
   */
  public function getDefinitions(?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->declarations->getDefinitions($theme);
  }

  /**
   * The media-capable property names of the guide's referenced sets.
   *
   * The properties whose definitions carry the media flag — those whose
   * values may reference an image media entity as url('media:<uuid>')
   * (MediaToken). The AI fragment states the media-token rule ONCE in its
   * guidance paragraph, naming exactly these properties, so a site whose
   * referenced sets grant no media slot is never taught the token. Only
   * groups some declaration in the theme's layered guide references
   * count: root sections, parts at any depth in elements trees, children
   * pseudo-part sections, pseudo sections, instance namespaces, and the
   * site declaration's base tags are all referencing declarations, and a
   * referenced group no installed module provides is skipped, matching
   * reference emission (propertySetRefs()) and what validation can
   * enforce. The pseudo-only groups
   * (PropertyGroupPluginManager::getPseudoGroups(): `generated_content`,
   * whose `content` carries the flag) count as referenced on every site:
   * no declaration may name them (the guide build refuses it), the roster
   * walk grants them at every generated box, so their media slot is
   * always in the granted vocabulary. Memoized per theme.
   *
   * @return string[]
   *   Sorted CSS property names; [] when no referenced set grants a
   *   media-capable property.
   */
  public function mediaProperties(?string $theme = NULL): array {
    $theme ??= $this->themeManager->getActiveTheme()->getName();
    if (!isset($this->mediaProperties[$theme])) {
      $this->buildMediaProperties($theme);
    }
    return $this->mediaProperties[$theme];
  }

  /**
   * Builds and memoizes the media-capable names, one referencing walk.
   */
  protected function buildMediaProperties(string $theme): void {
    $declarations = $this->getDefinitions($theme);
    $referenced = [];
    foreach (['kinds', 'block_types', 'block_plugins', 'layouts', 'instances'] as $dimension) {
      foreach ($declarations[$dimension] ?? [] as $declaration) {
        if (is_array($declaration)) {
          $this->collectReferencedSets($declaration, $referenced);
        }
      }
    }
    $site = $declarations['site'] ?? [];
    foreach (is_array($site) ? ($site['base'] ?? []) : [] as $declaration) {
      if (is_array($declaration)) {
        $this->collectReferencedSets($declaration, $referenced);
      }
    }
    // The pseudo-only groups are granted by the walk at every generated
    // box and can be named by no declaration, so the referencing walk
    // above never sees them; fold them in or their media slot (content)
    // would be granted everywhere and taught nowhere.
    foreach ($this->propertyGroupManager->getPseudoGroups() as $group_id) {
      $referenced[$group_id] = TRUE;
    }
    $media = [];
    foreach ($this->propertyGroupManager->getGroups() as $id => $group) {
      if (!isset($referenced[$id])) {
        continue;
      }
      foreach ($group->getProperties() as $property => $definition) {
        if (!empty($definition['media'])) {
          $media[] = (string) $property;
        }
      }
    }
    sort($media);
    $this->mediaProperties[$theme] = $media;
  }

  /**
   * The site-wide constraint defaults: the site's own bindings.
   *
   * The site declaration's constraint_defaults section
   * (getConstraintDefaults()): deliberate site-wide bindings, and the
   * base layer of constraint resolution everywhere; a node authoring its
   * own block for the property overrides the default on that node. The
   * serialization map the AI fragments' bindings lines and the client
   * payload's constraintDefaults read. Nothing is derived into it: a
   * block a node authors is that node's value space, served per address
   * by the vocabulary roster (VocabularyRoster::valueSpaces()) and
   * spelled per address by the fragments' deviations lines, never
   * hoisted to a site-wide line the validator would not enforce there.
   * Shorthand families ARE expanded (expandConstraintFamilies()): a
   * block the site authors on padding is served on its four sides too,
   * the same rule the validator enforces there.
   *
   * @return array<string, array>
   *   Constraint blocks ({allowed, min, max, units, scale, stops}) keyed
   *   by CSS property name, in authoring order, each shorthand's block
   *   followed by the longhands it expands to.
   */
  public function constraintDefaults(?string $theme = NULL): array {
    return $this->getConstraintDefaults($theme);
  }

  /**
   * Projects one declaration node's property groups as set references.
   *
   * A node's referenced sets plus its constraint deltas, never an inline
   * copy of the sets' property vocabulary (the read the property-set
   * tests pin and the media-property catalog leans on). Deltas
   * flatten across the node's groups into one property-keyed map (every
   * property is owned by exactly one group, so flattening cannot collide)
   * and stay as authored ({allowed, min, max, units, scale, stops}).
   * Groups no installed module provides are skipped, so references
   * always name a set the site can enforce. Not a consumer surface: the
   * client payload and the AI read the vocabulary roster (the grants and
   * value spaces resolved per address, VocabularyRosterBuilder), never
   * these refs.
   *
   * @param array $declaration
   *   A style guide declaration node: an effective declaration as
   *   getKind()/getBlockType()/getBlockPlugin()/getLayout() serve it, a
   *   part from an elements tree, a children pseudo-part section, or a
   *   base tag's declaration.
   *
   * @return array
   *   ['sets' => set ids in declaration order], plus
   *   ['constraints' => property => delta map] when any group carries
   *   deltas, plus ['properties' => set id => allowlist] for referenced
   *   sets whose declaration narrows the grant to a subset of the set's
   *   vocabulary (the allowlist binds at every placement depth); [] when
   *   the node references no installed sets.
   */
  public function propertySetRefs(array $declaration): array {
    $sets = [];
    $constraints = [];
    $properties = [];
    $groups = $declaration['property_groups'] ?? [];
    foreach (is_array($groups) ? $groups : [] as $group_id => $group_declaration) {
      if (!is_string($group_id) || !$this->propertyGroupManager->hasDefinition($group_id)) {
        continue;
      }
      $sets[] = $group_id;
      $deltas = is_array($group_declaration) ? ($group_declaration['constraints'] ?? []) : [];
      foreach (is_array($deltas) ? $deltas : [] as $property => $delta) {
        if (is_array($delta) && $delta !== []) {
          $constraints[$property] = $delta;
        }
      }
      $allowed = is_array($group_declaration) ? ($group_declaration['properties'] ?? NULL) : NULL;
      if (is_array($allowed) && array_is_list($allowed) && $allowed !== []) {
        $properties[$group_id] = array_map(strval(...), $allowed);
      }
    }
    if ($sets === []) {
      return [];
    }
    $refs = ['sets' => $sets];
    if ($constraints !== []) {
      $refs['constraints'] = $constraints;
    }
    if ($properties !== []) {
      $refs['properties'] = $properties;
    }
    return $refs;
  }

  /**
   * Collects the set ids one declaration references, recursing into parts.
   *
   * The catalog's referencing walk: a node references sets through its own
   * property_groups, its children pseudo-part section, its declared
   * generated boxes (the pseudo section) and every part of its elements
   * tree at any depth. Only installed groups count, matching
   * propertySetRefs().
   *
   * @param array $declaration
   *   A declaration node, as authored or effective.
   * @param array<string, true> $referenced
   *   The collected set ids, accumulated by reference.
   */
  protected function collectReferencedSets(array $declaration, array &$referenced): void {
    $groups = $declaration['property_groups'] ?? [];
    foreach (is_array($groups) ? array_keys($groups) : [] as $group_id) {
      if (is_string($group_id) && $this->propertyGroupManager->hasDefinition($group_id)) {
        $referenced[$group_id] = TRUE;
      }
    }
    $children = $declaration['children'] ?? NULL;
    if (is_array($children)) {
      $this->collectReferencedSets($children, $referenced);
    }
    foreach (static::pseudoNodes($declaration) as $box) {
      $this->collectReferencedSets($box, $referenced);
    }
    $elements = $declaration['elements'] ?? [];
    foreach (is_array($elements) ? $elements : [] as $part) {
      if (is_array($part)) {
        $this->collectReferencedSets($part, $referenced);
      }
    }
  }

  /**
   * The variant axis vocabulary of a declaration.
   *
   * The one defaulting rule behind every axis consumer (the variant
   * entity's own axis check, map validation, the client payload, the AI
   * schema): the built-in "style" axis first, then the declared
   * variant_axes in declaration order. The order is load bearing: variant
   * classes are emitted in axis-declaration order, keeping rendering
   * deterministic regardless of reference order.
   *
   * Qualified entries ({axis: {depth: root}}) normalize to their axis name
   * here — the vocabulary is names in declaration order no matter how an
   * axis is authored, so no axis consumer re-parses the entry grammar.
   * Depth is a placement question, answered where placement is known
   * (rootOnlyAxes() exposes which axes carry the qualifier).
   *
   * @param array $declaration
   *   An effective style guide declaration, as getKind()/getBlockType()/
   *   getLayout() serve it.
   *
   * @return string[]
   *   The axis machine names, "style" always first.
   */
  public static function axes(array $declaration): array {
    $declared = $declaration['variant_axes'] ?? [];
    $names = [];
    foreach (is_array($declared) ? $declared : [] as $entry) {
      if (is_string($entry)) {
        $names[] = $entry;
      }
      elseif (is_array($entry) && count($entry) === 1 && is_string($axis = array_key_first($entry))) {
        $names[] = $axis;
      }
    }
    return [self::STYLE_AXIS, ...$names];
  }

  /**
   * The declaration's root-only axes: those qualified {axis: {depth: root}}.
   *
   * The depth qualifier's projection for placement-aware consumers: a
   * root-only axis is offered and defaulted for TOP-LEVEL sections only,
   * never for sections nested inside another section's region (a nested
   * section is always constrained by its parent container, so page-band
   * axes like width have nothing to say there). This list is the SOURCE
   * of the depth stamps, never the consumers' read: DaedalusStylishMaterialsProvider
   * writes it as each variant record's applies.depth
   * (MaterialRecord::rootOnly()) and VocabularyRosterBuilder as the
   * roster's per-axis root_only, and the picker, the dialect,
   * RootOnlyVariantChecker, the validator's owned-property warning and the
   * move subscriber that strips root-only wear on nest and redresses it at
   * root (ElementStylesSubscriber::onElementMoved) all read a stamp. Only
   * the birth and defaults paths (ElementStylesSubscriber,
   * LayoutDefaultsSchemaHooks) call this method directly.
   *
   * @param array $declaration
   *   An effective style guide declaration, as getKind()/getBlockType()/
   *   getLayout() serve it.
   *
   * @return string[]
   *   The root-only axis machine names, [] when every axis applies at
   *   every depth.
   */
  public static function rootOnlyAxes(array $declaration): array {
    $declared = $declaration['variant_axes'] ?? [];
    $names = [];
    foreach (is_array($declared) ? $declared : [] as $entry) {
      if (is_array($entry) && count($entry) === 1 && is_string($axis = array_key_first($entry))
        && is_array($entry[$axis]) && ($entry[$axis]['depth'] ?? NULL) === self::DEPTH_ROOT) {
        $names[] = $axis;
      }
    }
    return $names;
  }

  /**
   * The declaration's default variants, resolved for a placement depth.
   *
   * The one reader of the default_variants entry grammar: plain entries
   * (axis => machine name) apply at every depth, depth-qualified entries
   * ({variant: name, depth: root}) resolve to no default for NESTED
   * sections — the axis stays offered there, only the house default is
   * page-band business. Consumers get axis => machine name and never
   * re-parse the entry forms.
   *
   * Depth gates entries, not axes: a root-only AXIS (rootOnlyAxes()) is
   * the placement consumer's own skip on top of this projection.
   *
   * @param array $declaration
   *   An effective style guide declaration, as getKind()/getBlockType()/
   *   getLayout() serve it.
   * @param bool $nested
   *   Whether the placement sits nested inside another section's region.
   *
   * @return array<string, string>
   *   Axis => variant machine name, depth-skipped entries absent; [] when
   *   the declaration names none.
   */
  public static function defaultVariants(array $declaration, bool $nested = FALSE): array {
    $declared = $declaration['default_variants'] ?? [];
    $defaults = [];
    foreach (is_array($declared) ? $declared : [] as $axis => $entry) {
      if (is_string($entry)) {
        $defaults[$axis] = $entry;
      }
      elseif (is_array($entry) && is_string($entry['variant'] ?? NULL)
        && !($nested && ($entry['depth'] ?? NULL) === self::DEPTH_ROOT)) {
        $defaults[$axis] = $entry['variant'];
      }
    }
    return $defaults;
  }

  /**
   * The declaration's axis labels: those qualified {axis: {label: ...}}.
   *
   * The label qualifier's projection for display consumers (the wear bar
   * row, the save-as-variant axis picker, the library's axis chip and
   * filter). An axis without a declared label falls back to its derived
   * form (the capitalized machine name) client-side, so the map holds
   * only authored labels.
   *
   * @param array $declaration
   *   An effective style guide declaration, as getKind()/getBlockType()/
   *   getLayout() serve it.
   *
   * @return array<string, string>
   *   Labels keyed by axis machine name, [] when none are declared.
   */
  public static function axisLabels(array $declaration): array {
    $declared = $declaration['variant_axes'] ?? [];
    $labels = [];
    foreach (is_array($declared) ? $declared : [] as $entry) {
      if (is_array($entry) && count($entry) === 1 && is_string($axis = array_key_first($entry))
        && is_array($entry[$axis]) && is_string($entry[$axis]['label'] ?? NULL)) {
        $labels[$axis] = $entry[$axis]['label'];
      }
    }
    return $labels;
  }

  /**
   * The declaration's axis descriptions: the qualified {axis: {description}}.
   *
   * The description qualifier's projection: what the axis means, written
   * for a designer. Carried by the vocabulary roster to the client
   * payload and the dialect catalog (the AI's brief). The map holds only
   * authored descriptions.
   *
   * @param array $declaration
   *   An effective style guide declaration, as getKind()/getBlockType()/
   *   getLayout() serve it.
   *
   * @return array<string, string>
   *   Descriptions keyed by axis machine name, [] when none are declared.
   */
  public static function axisDescriptions(array $declaration): array {
    $descriptions = [];
    foreach (self::qualifiedAxes($declaration) as $axis => $qualifiers) {
      if (is_string($qualifiers['description'] ?? NULL) && trim($qualifiers['description']) !== '') {
        $descriptions[$axis] = $qualifiers['description'];
      }
    }
    return $descriptions;
  }

  /**
   * The declaration's owned properties per axis: {axis: {owns: [...]}}.
   *
   * The owns qualifier's projection: the property names each axis's
   * values write, so a document wears the axis instead of writing them
   * (the validator's STYLE_PROPERTY_AXIS_OWNED warning names the axis on
   * such a write). Validated at guide build to name granted properties
   * only. The map holds only axes that own something.
   *
   * @param array $declaration
   *   An effective style guide declaration, as getKind()/getBlockType()/
   *   getLayout() serve it.
   *
   * @return array<string, string[]>
   *   Property names keyed by axis machine name, in authored order; []
   *   when no axis owns a property.
   */
  public static function axisOwns(array $declaration): array {
    $owns = [];
    foreach (self::qualifiedAxes($declaration) as $axis => $qualifiers) {
      $properties = $qualifiers['owns'] ?? NULL;
      if (is_array($properties) && $properties !== []) {
        $owns[$axis] = array_values(array_map(strval(...), $properties));
      }
    }
    return $owns;
  }

  /**
   * The declaration's qualified axis entries: axis => its qualifier map.
   *
   * The one reader of the one-key map entry form behind the qualifier
   * projections (axisLabels(), axisDescriptions(), axisOwns()); plain
   * entries carry no qualifiers and are absent.
   *
   * @return array<string, array>
   *   Qualifier maps keyed by axis machine name, in declaration order.
   */
  private static function qualifiedAxes(array $declaration): array {
    $declared = $declaration['variant_axes'] ?? [];
    $qualified = [];
    foreach (is_array($declared) ? $declared : [] as $entry) {
      if (is_array($entry) && count($entry) === 1 && is_string($axis = array_key_first($entry)) && is_array($entry[$axis])) {
        $qualified[$axis] = $entry[$axis];
      }
    }
    return $qualified;
  }

  /**
   * Gets the declared token scales.
   *
   * The design-token vocabulary's code half: scale declarations from the
   * site declaration's token_scales section, validated. Token names and
   * values are data (skin entities), not served here. Map order is
   * authoring order — the order pickers present scales in.
   *
   * @return array
   *   Scale declarations ({label, shape, optional prefix, keywords for the
   *   keyword shape}) keyed by scale machine name, [] when none are
   *   declared.
   */
  public function getTokenScales(?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->declarations->getSite($theme)['token_scales'] ?? [];
  }

  /**
   * Gets the authored site-wide constraint defaults.
   *
   * The site declaration's constraint_defaults section, validated: CSS
   * property => constraint block in the same {scale, stops, allowed, min,
   * max, units} grammar node constraints speak. The offer vocabulary's
   * authored half, and the BASE layer of constraint resolution: a node
   * authoring its own block for a property wins on that node; on every
   * other node granting the property this block is the effective
   * constraint — law while constraintsAreLaw(), offer-shaping on open
   * sites. Served expanded over shorthand families
   * (expandConstraintFamilies()): a block authored on padding reaches
   * padding-top, padding-right, padding-bottom and padding-left unless
   * the site authors a side's own block. Map order is authoring order
   * with each family's longhands after their shorthand.
   * constraintDefaults() is the same map under the name the
   * serialization consumers read it by.
   *
   * @return array<string, array>
   *   Constraint blocks keyed by CSS property name, [] when none are
   *   declared.
   */
  public function getConstraintDefaults(?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->expandConstraintFamilies($this->declarations->getSite($theme)['constraint_defaults'] ?? []);
  }

  /**
   * Expands a map of constraint blocks over the shorthand families.
   *
   * The one family expansion for constraint blocks: a block authored on
   * a shorthand (the groups' getShorthands() tables, merged by the
   * manager: padding, margin, gap, border-width, border-style,
   * border-color, border-radius) is copied to each longhand of the
   * family that authors no block of its own, so a site or node states
   * one value rule per family and every consumer reads per-longhand
   * blocks. Authored order is kept; a family's longhands follow their
   * shorthand; a longhand's own block stays where it was authored and
   * wins over the expansion. Both constraint sources pass through here,
   * the site's constraint_defaults (getConstraintDefaults()) and a
   * node's constraints (VocabularyRosterBuilder::vocabulary()), and the
   * node-over-site resolution then works per longhand: a node's padding
   * block covers its sides against the site's, a site's padding block
   * fills the sides a node's padding-left leaves.
   *
   * @param array $blocks
   *   Constraint blocks keyed by CSS property name, as authored.
   *
   * @return array
   *   The blocks with each shorthand's block repeated on its unauthored
   *   longhands.
   */
  public function expandConstraintFamilies(array $blocks): array {
    $families = $this->propertyGroupManager->getShorthands();
    $expanded = [];
    foreach ($blocks as $property => $block) {
      $expanded[$property] = $block;
      foreach ($families[$property] ?? [] as $longhand) {
        if (!array_key_exists($longhand, $blocks)) {
          $expanded[$longhand] = $block;
        }
      }
    }
    return $expanded;
  }

  /**
   * Gets the declared base elements: the bare-markup styling vocabulary.
   *
   * The site declaration's base section, validated: HTML tag =>
   * declaration, tags from the closed BASE_TAGS vocabulary. Map order is
   * authoring order. Both prose lanes read it: the skin defaults tier by
   * scope (getBase()) and the element tag tier by tag (baseTagElement()).
   *
   * @return array
   *   Declarations ({property_groups, optionally elements and label})
   *   keyed by tag, [] when none are declared.
   */
  public function getBaseElements(?string $theme = NULL): array {
    $this->ensureValidated($theme);
    return $this->declarations->getSite($theme)['base'] ?? [];
  }

  /**
   * Gets the declaration for one base:{tag} scope.
   *
   * An undeclared (but legal) tag resolves to [] — grammar-checked-only on
   * permissive sites, nothing stylable in strict — the same degradation an
   * undeclared bundle gets from its universal kind, minus a universal
   * layer: bare markup has none.
   *
   * @return array
   *   The tag's declaration, [] when the site does not declare it.
   */
  public function getBase(string $tag, ?string $theme = NULL): array {
    return $this->getBaseElements($theme)[$tag] ?? [];
  }

  /**
   * The CSS variable prefix a scale's tokens derive under.
   *
   * The scale's machine name unless the declaration overrides it with
   * `prefix`. Grammar validation guarantees effective prefixes are unique
   * and non-extending across scales, so a var name resolves to at most one
   * scale.
   *
   * @param string $scale
   *   The scale's machine name.
   * @param array $declaration
   *   The scale's declaration.
   */
  public static function tokenPrefix(string $scale, array $declaration): string {
    return $declaration['prefix'] ?? $scale;
  }

  /**
   * The CSS custom property a token delivers as: --{prefix}-{name}.
   *
   * The ONE derivation rule behind every token consumer (the compiled skin
   * stylesheet, var() reference validation, pickers), so no consumer
   * concatenates its own variable names.
   *
   * @param string $scale
   *   The scale's machine name.
   * @param array $declaration
   *   The scale's declaration.
   * @param string $name
   *   The token's name on the scale, e.g. "4" or "accent-hover".
   */
  public static function tokenVariable(string $scale, array $declaration, string $name): string {
    return '--' . static::tokenPrefix($scale, $declaration) . '-' . $name;
  }

  /**
   * Resolves a CSS custom property back to its scale and token name.
   *
   * The reverse of tokenVariable(), and like it the ONE derivation rule: a
   * var() reference resolves to a scale by prefix match. Grammar validation
   * guarantees effective prefixes are unique and non-extending hyphen-wise,
   * so at most one scale can match — the first match is the only match. A
   * variable outside every declared prefix is NOT a token reference: the
   * token spine owns exactly the namespaces its scales declare, and custom
   * properties a theme or chrome defines are none of its business.
   *
   * @param string $variable
   *   A custom property name, leading dashes included ("--space-4").
   * @param array $scales
   *   Scale declarations keyed by machine name (getTokenScales()).
   *
   * @return array|null
   *   ['scale' => machine name, 'name' => token name], or NULL when the
   *   variable resolves to no declared scale.
   */
  public static function tokenReference(string $variable, array $scales): ?array {
    foreach ($scales as $scale => $declaration) {
      $prefix = '--' . static::tokenPrefix((string) $scale, is_array($declaration) ? $declaration : []) . '-';
      if (str_starts_with($variable, $prefix) && strlen($variable) > strlen($prefix)) {
        return ['scale' => (string) $scale, 'name' => substr($variable, strlen($prefix))];
      }
    }
    return NULL;
  }

  /**
   * The CSS custom property a swatch delivers as: --swatch-{name}.
   *
   * The swatch counterpart of tokenVariable(), and like it the ONE
   * derivation rule behind every swatch consumer (the compiled swatch
   * stylesheet, var() reference validation, the palette picker), so no
   * consumer concatenates its own variable names.
   *
   * @param string $name
   *   The swatch's machine name.
   */
  public static function swatchVariable(string $name): string {
    return '--' . self::SWATCH_PREFIX . '-' . $name;
  }

  /**
   * Resolves a CSS custom property back to its swatch machine name.
   *
   * The reverse of swatchVariable(): a variable in the reserved swatch
   * namespace resolves to the swatch name it references. Unambiguous by
   * construction — validateTokenScales() refuses any scale prefix that
   * equals or hyphen-extends the reserved prefix, so a variable is a
   * swatch reference or a token reference, never both. A variable outside
   * the namespace is not a swatch reference: like the token spine, the
   * swatch spine owns exactly its reserved prefix, and custom properties a
   * theme or chrome defines are none of its business.
   *
   * @param string $variable
   *   A custom property name, leading dashes included ("--swatch-hero").
   *
   * @return string|null
   *   The swatch machine name, or NULL when the variable is not in the
   *   swatch namespace.
   */
  public static function swatchReference(string $variable): ?string {
    $prefix = '--' . self::SWATCH_PREFIX . '-';
    return str_starts_with($variable, $prefix) && strlen($variable) > strlen($prefix)
      ? substr($variable, strlen($prefix))
      : NULL;
  }

  /**
   * The keyframes name an animation delivers as: motion-{name}.
   *
   * The animation counterpart of swatchVariable(), and like it the ONE
   * derivation rule behind every animation consumer (the compiled
   * keyframes stylesheet, reference validation, the roster record's
   * spelling, the usage scan, the completions), so no consumer
   * concatenates its own names.
   *
   * @param string $name
   *   The animation's machine name.
   */
  public static function animationName(string $name): string {
    return self::ANIMATION_PREFIX . '-' . $name;
  }

  /**
   * Resolves a keyframes name back to its animation machine name.
   *
   * The reverse of animationName(): an ident in the reserved motion
   * namespace resolves to the animation it references. An ident outside
   * the namespace is not an animation reference: it is a theme's own
   * keyframes name (or an animation keyword the property grammar owns),
   * and the motion spine has nothing to say about it beyond the strict
   * posture (see StyleMapValidator::checkAnimationReference()).
   *
   * @param string $ident
   *   A keyframes name as written ("motion-fade_in").
   *
   * @return string|null
   *   The animation machine name, or NULL when the ident is not in the
   *   motion namespace.
   */
  public static function animationReference(string $ident): ?string {
    $prefix = self::ANIMATION_PREFIX . '-';
    return str_starts_with($ident, $prefix) && strlen($ident) > strlen($prefix)
      ? substr($ident, strlen($prefix))
      : NULL;
  }

  /**
   * Clears built declarations, e.g. after installing an extension in tests.
   *
   * The vocabulary rosters built over the declarations go with them: a
   * roster memoized before the reset would answer from the old layering.
   */
  public function resetCache(): void {
    $this->validated = [];
    $this->mediaProperties = [];
    $this->declaredDefaults = [];
    $this->declarations->resetCache();
    ($this->vocabularyRosters)()->reset();
  }

  /**
   * Validates the theme's controls once per request.
   *
   * Anatomy grammar (elements trees, contains slots) is validated at the
   * spine when the declarations build; this is daedalus_stylish's own layer on top,
   * checking that every control binding can actually work.
   */
  protected function ensureValidated(?string $theme): void {
    $theme ??= $this->themeManager->getActiveTheme()->getName();
    if (isset($this->validated[$theme])) {
      return;
    }
    $declarations = $this->declarations->getDefinitions($theme);
    $errors = [];
    $this->validateControls($declarations, $errors);
    $this->validateVariantAxes($declarations, $theme, $errors);
    $this->validateDefaultVariants($declarations, $errors);
    $this->validateTokenScales($declarations, $errors);
    $this->validateBaseElements($declarations, $errors);
    $this->validateScaleConstraints($declarations, $errors);
    $this->validatePropertyAllowlists($declarations, $errors);
    $this->validateConstraintDefaults($declarations, $errors);
    $this->validateChildrenSections($declarations, $errors);
    $this->validatePseudoSections($declarations, $errors);
    if ($errors !== []) {
      throw new StyleGuideException(implode("\n", $errors));
    }
    // The declared-defaults check validates each declaration's defaults
    // through the style map validator, whose scope resolution re-enters
    // this guide, so the structurally sound guide is marked validated
    // before it runs and unmarked again if the defaults fail.
    $this->validated[$theme] = TRUE;
    $this->validateDeclaredDefaults($declarations, $theme, $errors);
    if ($errors !== []) {
      unset($this->validated[$theme]);
      throw new StyleGuideException(implode("\n", $errors));
    }
  }

  /**
   * Validates every declaration's declared defaults, appending errors.
   *
   * The declared-defaults tier's grammar gate and its ownership law. A
   * declaration may carry `defaults`: the literal box facts its markup
   * needs, in the skin defaults grammar for exactly the scope it declares
   * ({relative key: entry}, keys '' and '::.part'). Declared defaults
   * follow markup ownership, so which keys a declaration may carry is
   * decided by what it renders:
   * - a block type, block plugin, or layout declaration owns its root and
   *   its declared parts: any relative key;
   * - a COMPOSED kind (`composed: true`, the generative container kind)
   *   renders its own root: any relative key;
   * - any other kind owns only the anatomy it declares: '::.part' keys for
   *   elements in its own layered declaration (findElement()), never ''
   *   (its root is whatever template is in play, so that look is the
   *   site's: put kind-wide root defaults on the skin);
   * - the site renders nothing: `defaults` there is a misplacement error.
   * A refused key is reported by the rule it broke and dropped before the
   * grammar check, so one bad key never hides a second error beside it.
   *
   * Each surviving map is checked through
   * StyleMapValidator::validateDeclaredDefaults() (the one defaults path,
   * riding validateAt() in the EMPTY minted universe: a var() into any
   * declared scale's namespace fails the build whatever system is active
   * and whichever enforcement mode it carries, because declared defaults
   * are token-free code and token-bearing box defaults belong on the
   * skin). Declarations are checked as authored, like variant_axes:
   * catching the offender at its source.
   */
  protected function validateDeclaredDefaults(array $declarations, string $theme, array &$errors): void {
    $site = $declarations[PageBuilderDeclarations::SITE] ?? [];
    if (is_array($site) && array_key_exists('defaults', $site)) {
      $errors[] = 'The site declares defaults, but declared defaults follow markup ownership and the site renders no markup of its own. The site\'s look is the skin\'s: put site-wide defaults on the skin.';
    }
    foreach ($this->declaredDefaultsAsAuthored($declarations) as [$noun, $name, $scope, $defaults]) {
      if (!is_array($defaults) || $defaults === [] || array_is_list($defaults)) {
        $errors[] = sprintf('The defaults declaration of %s "%s" must be a non-empty map of element-relative styles (\'\' for the element, \'::.class-name\' for a declared part), or be omitted entirely.', $noun, $name);
        continue;
      }
      if ($noun === 'kind') {
        $defaults = $this->ownedKindDefaults($name, $declarations['kinds'][$name], $defaults, $errors);
        if ($defaults === []) {
          continue;
        }
      }
      foreach ($this->styleMapValidator()->validateDeclaredDefaults($defaults, $scope, $theme) as $error) {
        $message = $error->getMessage();
        if ($error->getCode() === 'STYLE_TOKEN_NOT_MINTED') {
          // The validator's corrective says "mint the token"; for a
          // declaration the remedy is the stratum rule.
          $message .= sprintf(' Declared defaults are token-free markup facts, checked in the empty token universe; a token-bearing default belongs on the skin\'s "%s" defaults.', $scope);
        }
        $errors[] = sprintf('The defaults declaration of %s "%s" is invalid at "%s": %s', $noun, $name, $error->getPath(), $message);
      }
    }
  }

  /**
   * A kind's declared defaults narrowed to the keys the kind's markup owns.
   *
   * The ownership law's kind half (see validateDeclaredDefaults()): '' only
   * on a composed kind, '::.part' only for an element the kind's own
   * layered declaration declares (PageBuilderDeclarations::findElement(),
   * so a nested element counts and a pattern element does not: a pattern
   * names a class namespace, no concrete element to default). Every
   * refused key appends its error naming the rule and is dropped.
   *
   * @return array
   *   The surviving {relative key: entry} map, possibly empty.
   */
  protected function ownedKindDefaults(string $kind, array $declaration, array $defaults, array &$errors): array {
    $composed = ($declaration['composed'] ?? FALSE) === TRUE;
    foreach (array_keys($defaults) as $relative) {
      $relative = (string) $relative;
      if ($relative === '') {
        if (!$composed) {
          $errors[] = sprintf('The kind "%s" declares root defaults (the \'\' key), but declared defaults follow markup ownership: a kind\'s root comes from whatever template is in play, so only a composed kind (composed: true, rendering its own root) may default it. A templated kind\'s look is the site\'s: put kind-wide root defaults on the skin\'s "kind:%1$s" defaults.', $kind);
          unset($defaults['']);
        }
        continue;
      }
      if ($composed) {
        continue;
      }
      if (!preg_match('/^::(\.[^:]+)$/', $relative, $matches) || PageBuilderDeclarations::findElement($declaration, $matches[1]) === NULL) {
        $errors[] = sprintf('The kind "%s" declares defaults at "%s", but declared defaults follow markup ownership: a kind that is not composed owns only the anatomy it declares, so its defaults keys are \'::.class-name\' for elements in its own declaration. Anything else there is the site\'s look: put it on the skin\'s "kind:%1$s" defaults.', $kind, $relative);
        unset($defaults[$relative]);
      }
    }
    return $defaults;
  }

  /**
   * Every declaration's defaults section, as authored.
   *
   * The one walk both the build check and declaredDefaults() read, in the
   * tier's delivery rank order: kinds first (a refined element wears its
   * kind's AND its refinement's scope class at equal specificity, so
   * document order must let the refinement win), then the block family's
   * refinements (block plugins, then block types), then layouts, stored
   * order within each. Ownership of the keys is judged by the build check
   * (validateDeclaredDefaults()), never here: the walk yields what was
   * authored.
   *
   * @return iterable<array{string, string, string, mixed}>
   *   [noun, name, scope, defaults] per declaring declaration.
   */
  protected function declaredDefaultsAsAuthored(array $declarations): iterable {
    $dimensions = [
      'kinds' => ['kind', 'kind'],
      'block_plugins' => ['block plugin', 'block_plugin'],
      'block_types' => ['block type', 'block_type'],
      'layouts' => ['layout', 'layout'],
    ];
    foreach ($dimensions as $dimension => [$noun, $family]) {
      foreach ($declarations[$dimension] ?? [] as $name => $declaration) {
        if (is_array($declaration) && array_key_exists('defaults', $declaration)) {
          yield [$noun, (string) $name, "$family:$name", $declaration['defaults']];
        }
      }
    }
  }

  /**
   * The style map validator, resolved lazily (see the constructor).
   */
  protected function styleMapValidator(): StyleMapValidator {
    return ($this->styleMapValidator)();
  }

  /**
   * Validates every kind's, refinement's, and namespace's controls.
   *
   * The first read with problems throws once, listing all of them. Three checks
   * per control, mirroring what a control needs to actually work: the named
   * ControlWidget plugin exists, every candidate of the element key (a
   * well-formed list, controlCandidates()) is '' or a declared part of the
   * declaration, and every candidate's property groups cover every CSS
   * property the widget owns, otherwise a write the resolver routes to that
   * candidate would be outside what the guide declares stylable there. A
   * fourth, presentational one: a section key, when declared, names a
   * ControlSection, or basic mode would have no header to file the control
   * under. The root candidate ('' or the omitted key) is covered by the
   * declaration's effective property groups.
   *
   * Every refinement of the block kind is walked: block types AND block
   * plugins, each as the effective declaration getBlockType() and
   * getBlockPlugin() serve (the kind layered underneath), so a theme's
   * plugin bindings (the header blocks) are vouched for before the client
   * ever receives them, and an inherited kind control is re-checked
   * against the refinement's effective groups.
   *
   * Kind controls follow the same rule as the refinements: a part
   * candidate binds a part the kind's OWN effective declaration declares
   * (found anywhere in its elements tree), covered by that part's property
   * groups; the root candidate binds the kind root, covered by the kind's own
   * groups. Kinds may bind parts because declared anatomy is asserted by
   * its declarer: the entity kind's layout root is markup daedalus_layout renders
   * in every template and mode, so a control bound to it renders wherever
   * the part is present (the client's presence gate) rather than wherever a
   * theme's template happens to print a class. Instance-namespace controls
   * are root-bound: a namespace's instances are content carrying their own
   * markup, so there are no declared parts to bind — each control targets
   * the instance part itself, covered by the namespace's property groups.
   *
   * A control whose whole value is FALSE is a tombstone (a higher layer or
   * a block type suppressing an inherited control) and is valid as-is:
   * scalar FALSE survives merge() where NULL would vanish and let a lower
   * layer's version resurface, so "suppressed" stays distinct from "never
   * declared" all the way through the effective declaration.
   */
  protected function validateControls(array $declarations, array &$errors): void {
    foreach ($declarations['kinds'] as $kind => $declaration) {
      // A kind's layered declaration IS its effective declaration (no
      // lower layer exists), so the same rule the bundle-like dimensions
      // get applies as-is: a control binds the kind root, or a part the
      // kind's own elements tree declares (the entity kind's layout root,
      // daedalus_layout's markup in every template and mode).
      $this->validateDeclarationControls(is_array($declaration) ? $declaration : [], sprintf('kind "%s"', $kind), 'the kind root', 'the kind', $errors);
    }
    foreach ($declarations['block_types'] as $bundle => $declaration) {
      // Validate the effective declaration — the universal block kind layered
      // underneath — the same view getBlockType() serves. Inherited kind
      // controls are re-validated here against the block type's effective
      // groups, so a block type that withdraws a property group a kind
      // control needs fails the build unless it tombstones the control too.
      $effective = PageBuilderDeclarations::merge($declarations['kinds']['block'] ?? [], $declaration);
      $this->validateDeclarationControls($effective, sprintf('block type "%s"', $bundle), 'the block root', 'the block type', $errors);
    }
    foreach ($declarations['block_plugins'] ?? [] as $plugin_id => $declaration) {
      // The block kind's other refinement, the same effective view
      // getBlockPlugin() serves and ClientStyleGuide delivers: a theme's
      // plugin declaration (curation shipped beside its template override)
      // binds parts only the effective declaration declares.
      $effective = PageBuilderDeclarations::merge($declarations['kinds']['block'] ?? [], $declaration);
      $this->validateDeclarationControls($effective, sprintf('block plugin "%s"', $plugin_id), 'the block root', 'the block plugin', $errors);
    }
    foreach ($declarations['layouts'] as $layout_id => $declaration) {
      // Same effective view getLayout() serves: the universal container
      // kind layered underneath.
      $effective = PageBuilderDeclarations::merge($declarations['kinds']['container'] ?? [], $declaration);
      $this->validateDeclarationControls($effective, sprintf('layout "%s"', $layout_id), 'the container root', 'the layout', $errors);
    }
    foreach ($declarations['instances'] ?? [] as $pattern => $declaration) {
      // Instance namespaces (the spine's `instances:` lane) bind controls
      // to each instance part itself — basic mode's curated surface for a
      // selected paragraph. Root-bound only, like kind controls but for
      // the opposite reason: an instance is CONTENT carrying its own
      // markup, so there are no declared parts to drill into. Coverage is
      // the namespace's own property groups.
      if (!is_array($declaration)) {
        continue;
      }
      $controls = $declaration['controls'] ?? [];
      if (!is_array($controls)) {
        $errors[] = sprintf('The controls declaration of instance namespace "%s" must be a map of control declarations.', $pattern);
        continue;
      }
      foreach ($controls as $name => $control) {
        if ($control === FALSE) {
          continue;
        }
        $at = sprintf('The control "%s" of instance namespace "%s"', $name, $pattern);
        if (!is_array($control)) {
          $errors[] = "$at must be a map with label and widget keys, or FALSE to suppress an inherited control.";
          continue;
        }
        if (array_key_exists('element', $control)) {
          $errors[] = "$at declares an element key, but an instance control binds the instance itself; a namespace's instances are content and declare no parts of their own.";
          continue;
        }
        $this->validateControlSection($at, $control, $errors);
        if (($widget_id = $this->validateControlWidgetId($at, $control, $errors)) !== NULL) {
          $this->validateControlCoverage($at, $widget_id, $declaration['property_groups'] ?? [], 'to the instance', 'the namespace', $errors);
        }
      }
    }
  }

  /**
   * The axis qualifier keys the variant_axes entry grammar accepts.
   */
  protected const AXIS_QUALIFIERS = ['depth', 'label', 'description', 'owns'];

  /**
   * Validates every declaration's variant_axes list, appending errors.
   *
   * Axes are validated as authored, per declaration: a higher layer's list
   * REPLACES a lower layer's wholesale (list merge semantics), so the
   * effective vocabulary is always exactly one authored list, and checking
   * each catches the offender at its source. The grammar is deliberately
   * small: a non-empty list of unique axis machine names, none of them the
   * built-in "style" axis (it exists on every scope; redeclaring it would
   * either duplicate the vocabulary or, worse, look like a way to reorder
   * or remove it). An entry may also be a one-key map qualifying its axis,
   * {axis: {depth: root, label: Layout, description: ..., owns: [...]}}:
   * the qualifier keys are "depth" (only value "root"), "label" (a
   * non-empty display string), "description" (a non-empty string, the
   * axis's meaning for a designer) and "owns" (a non-empty list of unique
   * property names, each granted on the scope's EFFECTIVE declaration:
   * the refinement with its kind layered underneath, the grant the
   * vocabulary roster answers for the address; a miss names the
   * declaration and the property, because an axis can only own what its
   * element may style). The depth qualifier is valid only on
   * container-family declarations (the "container" kind and layouts):
   * depth is a placement fact of containers nesting in containers, so
   * declaring it anywhere else is a category error and fails loudly
   * rather than silently applying at every depth. The other qualifiers
   * are valid on any declaration.
   */
  protected function validateVariantAxes(array $declarations, string $theme, array &$errors): void {
    $dimensions = [
      'kinds' => 'kind',
      'block_types' => 'block type',
      'block_plugins' => 'block plugin',
      'layouts' => 'layout',
    ];
    foreach ($dimensions as $dimension => $noun) {
      foreach ($declarations[$dimension] ?? [] as $name => $declaration) {
        if (!is_array($declaration) || !array_key_exists('variant_axes', $declaration)) {
          continue;
        }
        $at = sprintf('The variant_axes declaration of %s "%s"', $noun, $name);
        $axes = $declaration['variant_axes'];
        if (!is_array($axes) || !array_is_list($axes) || $axes === []) {
          $errors[] = "$at must be a non-empty list of axis machine names, or be omitted entirely (every scope has the built-in \"style\" axis).";
          continue;
        }
        $section_family = $dimension === 'layouts' || ($dimension === 'kinds' && $name === 'container');
        $granted = NULL;
        $seen = [];
        foreach ($axes as $entry) {
          $axis = $entry;
          if (is_array($entry)) {
            if (count($entry) !== 1 || !is_string($axis = array_key_first($entry))) {
              $errors[] = sprintf('%s contains %s, which is not an axis entry (an axis machine name, or a one-key qualifier map like {width: {depth: root}}).', $at, var_export($entry, TRUE));
              continue;
            }
            $qualifiers = $entry[$axis];
            if (!is_array($qualifiers) || $qualifiers === []) {
              $errors[] = sprintf('%s qualifies axis "%s" with %s; the qualifier must be a non-empty map (the qualifiers are {depth: root}, {label: ...}, {description: ...} and {owns: [...]}).', $at, $axis, var_export($qualifiers, TRUE));
            }
            else {
              if ($unknown = array_diff(array_keys($qualifiers), self::AXIS_QUALIFIERS)) {
                $errors[] = sprintf('%s qualifies axis "%s" with unknown key(s) %s; the axis qualifier keys are %s.', $at, $axis, implode(', ', array_map(static fn ($key) => var_export($key, TRUE), $unknown)), implode(', ', array_map(static fn (string $key): string => "\"$key\"", self::AXIS_QUALIFIERS)));
              }
              if (array_key_exists('depth', $qualifiers)) {
                if (!$section_family) {
                  $errors[] = sprintf('%s sets the depth of axis "%s", but the depth qualifier applies only to container-family declarations (the "container" kind and layouts); %s placements have no container nesting depth.', $at, $axis, $noun);
                }
                if ($qualifiers['depth'] !== self::DEPTH_ROOT) {
                  $errors[] = sprintf('%s sets the depth of axis "%s" to %s; the only depth value is "root" (an axis without the qualifier applies at every depth).', $at, $axis, var_export($qualifiers['depth'], TRUE));
                }
              }
              if (array_key_exists('label', $qualifiers) && (!is_string($qualifiers['label']) || trim($qualifiers['label']) === '')) {
                $errors[] = sprintf('%s sets the label of axis "%s" to %s; an axis label must be a non-empty string (omit it to derive the label from the machine name).', $at, $axis, var_export($qualifiers['label'], TRUE));
              }
              if (array_key_exists('description', $qualifiers) && (!is_string($qualifiers['description']) || trim($qualifiers['description']) === '')) {
                $errors[] = sprintf('%s sets the description of axis "%s" to %s; an axis description must be a non-empty string saying what the axis means and when to wear each value (omit it rather than declaring an empty one).', $at, $axis, var_export($qualifiers['description'], TRUE));
              }
              if (array_key_exists('owns', $qualifiers)) {
                $owns = $qualifiers['owns'];
                if (!is_array($owns) || !array_is_list($owns) || $owns === [] || array_filter($owns, static fn ($property): bool => !is_string($property) || $property === '') !== []) {
                  $errors[] = sprintf('%s sets the owns of axis "%s" to %s; owns must be a non-empty list of property names the axis\'s values write (omit it for an axis that owns nothing).', $at, $axis, var_export($owns, TRUE));
                }
                else {
                  if (count(array_unique($owns)) !== count($owns)) {
                    $errors[] = sprintf('%s lists a property more than once in the owns of axis "%s".', $at, $axis);
                  }
                  $granted ??= $this->effectiveGrant($dimension, (string) $name, $theme);
                  foreach (array_diff($owns, $granted) as $property) {
                    $errors[] = sprintf('%s says axis "%s" owns "%s", which the %s does not grant; an axis can only own a property its element may style (the effective grant of %s "%s" is: %s).', $at, $axis, $property, $noun, $noun, $name, $granted === [] ? 'nothing' : implode(', ', $granted));
                  }
                }
              }
            }
          }
          if (!is_string($axis) || !preg_match('/^[a-z0-9_]+$/', $axis)) {
            $errors[] = sprintf('%s contains %s, which is not an axis machine name (lowercase letters, numbers, and underscores).', $at, var_export($axis, TRUE));
            continue;
          }
          if ($axis === self::STYLE_AXIS) {
            $errors[] = "$at declares the built-in \"style\" axis; it exists on every scope, so declare only additional axes.";
          }
          elseif (isset($seen[$axis])) {
            $errors[] = sprintf('%s lists "%s" more than once.', $at, $axis);
          }
          $seen[$axis] = TRUE;
        }
      }
    }
  }

  /**
   * The property names a scope's effective declaration grants.
   *
   * The build-time twin of the vocabulary roster's grant (the same
   * derivation, PropertyGroupPluginManager::expandDeclaredProperties()
   * over the effective declaration's property_groups: a refinement with
   * its kind layered underneath, a kind as merged), read straight from
   * the layered declarations because the guide is mid-validation and the
   * roster builder would re-enter it.
   *
   * @return string[]
   *   The granted names, allowlists resolved and shorthand families
   *   derived.
   */
  protected function effectiveGrant(string $dimension, string $name, string $theme): array {
    $effective = match ($dimension) {
      'kinds' => $this->declarations->getKind($name, $theme),
      'block_types' => $this->declarations->getBlockType($name, $theme),
      'block_plugins' => $this->declarations->getBlockPlugin($name, $theme),
      'layouts' => $this->declarations->getLayout($name, $theme),
    };
    $groups = $effective['property_groups'] ?? [];
    return $this->propertyGroupManager->expandDeclaredProperties(is_array($groups) ? $groups : []);
  }

  /**
   * Validates every declaration's default_variants map, appending errors.
   *
   * Grammar per declaration: a non-empty map of axis => variant machine
   * name, where an entry may instead be the depth-qualified map
   * {variant: name, depth: root} — container-family only (the "container"
   * kind and layouts), depth's one value "root", and the map form exists
   * to carry the qualifier (a qualifier-less map is spelled as the plain
   * machine name, so one meaning keeps one spelling). Axis membership is
   * checked against the EFFECTIVE vocabulary the
   * declaration's placements resolve — the universal kind layered under
   * block types and layouts — because variant_axes may be declared at
   * either layer while the defaults ride the refinement. The named
   * variants themselves are content, not code: existence, published
   * state, scope, and axis fit are checked where a default is applied
   * (ElementStylesSubscriber), degrading silently to an unstyled
   * placement — the build cannot know what variants an environment holds.
   *
   * The pre-axes singular default_variant key is dead grammar and fails
   * the build loudly with a corrective, the same posture the map
   * validator takes toward a "variant" entry key: a silently ignored
   * default would ship unstyled placements with no clue why.
   */
  protected function validateDefaultVariants(array $declarations, array &$errors): void {
    $dimensions = [
      'kinds' => ['kind', NULL],
      'block_types' => ['block type', 'block'],
      'layouts' => ['layout', 'container'],
    ];
    foreach ($dimensions as $dimension => [$noun, $base_kind]) {
      foreach ($declarations[$dimension] as $name => $declaration) {
        if (!is_array($declaration)) {
          continue;
        }
        if (array_key_exists('default_variant', $declaration)) {
          $errors[] = sprintf('The %s "%s" declares default_variant, which no longer exists; declare default_variants, a map of axis => variant machine name ({style: some_variant} for the single-axis case).', $noun, $name);
        }
        if (!array_key_exists('default_variants', $declaration)) {
          continue;
        }
        $at = sprintf('The default_variants declaration of %s "%s"', $noun, $name);
        $defaults = $declaration['default_variants'];
        if (!is_array($defaults) || $defaults === [] || array_is_list($defaults)) {
          $errors[] = "$at must be a non-empty map of axis => variant machine name, or be omitted entirely.";
          continue;
        }
        $effective = $base_kind === NULL
          ? $declaration
          : PageBuilderDeclarations::merge($declarations['kinds'][$base_kind] ?? [], $declaration);
        $axes = static::axes($effective);
        $section_family = $dimension === 'layouts' || ($dimension === 'kinds' && $name === 'container');
        foreach ($defaults as $axis => $reference) {
          if (!is_string($axis) || !in_array($axis, $axes, TRUE)) {
            $errors[] = sprintf('%s names axis %s, which is not in the scope\'s axis vocabulary (%s).', $at, var_export($axis, TRUE), implode(', ', $axes));
          }
          if (is_array($reference)) {
            if ($unknown = array_diff(array_keys($reference), ['variant', 'depth'])) {
              $errors[] = sprintf('%s qualifies axis %s with unknown key(s) %s; the qualified entry keys are "variant" and "depth".', $at, var_export($axis, TRUE), implode(', ', array_map(static fn ($key) => var_export($key, TRUE), $unknown)));
            }
            if (!is_string($reference['variant'] ?? NULL) || !preg_match('/^[a-z0-9_]+$/', $reference['variant'] ?? '')) {
              $errors[] = sprintf('%s qualifies axis %s without naming its variant; a qualified entry is {variant: machine_name, depth: root}.', $at, var_export($axis, TRUE));
            }
            if (!array_key_exists('depth', $reference)) {
              $errors[] = sprintf('%s qualifies axis %s without a depth; the map form exists to carry the depth qualifier — an unqualified default is spelled as the plain machine name.', $at, var_export($axis, TRUE));
            }
            else {
              if (!$section_family) {
                $errors[] = sprintf('%s sets the depth of axis %s, but the depth qualifier applies only to container-family declarations (the "container" kind and layouts); %s placements have no container nesting depth.', $at, var_export($axis, TRUE), $noun);
              }
              if ($reference['depth'] !== self::DEPTH_ROOT) {
                $errors[] = sprintf('%s sets the depth of axis %s to %s; the only depth value is "root" (a default without the qualifier applies at every depth).', $at, var_export($axis, TRUE), var_export($reference['depth'], TRUE));
              }
            }
          }
          elseif (!is_string($reference) || !preg_match('/^[a-z0-9_]+$/', $reference)) {
            $errors[] = sprintf('%s maps axis %s to %s, which is not a variant machine name (lowercase letters, numbers, and underscores).', $at, var_export($axis, TRUE), var_export($reference, TRUE));
          }
        }
      }
    }
  }

  /**
   * Validates the site declaration's token_scales section, appending errors.
   *
   * Grammar per scale: a machine-name key and a map with a label, a shape
   * from TOKEN_SHAPES (the keyword shape additionally requiring its
   * keywords vocabulary, which no other shape may carry), and an optional
   * CSS-facing prefix. The section is site vocabulary, so declaring it
   * inside any kind, block type, or layout declaration is a misplacement
   * error, never silently dead grammar.
   *
   * Effective prefixes (declared prefix, else the scale name) must be
   * unambiguous as a set: no duplicates, and none extending another with a
   * hyphen. Token NAMES may contain hyphens (--color-accent-hover), so
   * "text" beside "text-heading" would make --text-heading-lg parse as
   * either scale; the grammar refuses the ambiguity once instead of every
   * var() consumer disambiguating forever. The swatch namespace
   * (SWATCH_PREFIX, "--swatch-") is reserved by the same rule: no prefix
   * may equal or extend it, because user swatches deliver there.
   */
  protected function validateTokenScales(array $declarations, array &$errors): void {
    $dimensions = [
      'kinds' => 'kind',
      'block_types' => 'block type',
      'layouts' => 'layout',
    ];
    foreach ($dimensions as $dimension => $noun) {
      foreach ($declarations[$dimension] as $name => $declaration) {
        if (is_array($declaration) && array_key_exists('token_scales', $declaration)) {
          $errors[] = sprintf('The %s "%s" declares token_scales, but token scales are site vocabulary; declare them under the top-level "site" key of a universal PROVIDER.page_builder.yml.', $noun, $name);
        }
      }
    }
    $site = $declarations['site'] ?? [];
    if (!is_array($site) || !array_key_exists('token_scales', $site)) {
      return;
    }
    $scales = $site['token_scales'];
    if (!is_array($scales) || $scales === [] || array_is_list($scales)) {
      $errors[] = 'The token_scales declaration of the site must be a non-empty map of scale declarations keyed by scale machine name, or be omitted entirely.';
      return;
    }
    $prefixes = [];
    foreach ($scales as $scale => $declaration) {
      $scale = (string) $scale;
      $at = sprintf('The token scale "%s"', $scale);
      $valid_name = (bool) preg_match(self::TOKEN_SCALE_NAME, $scale);
      if (!$valid_name) {
        $errors[] = sprintf('The token scale key "%s" is not a scale machine name (lowercase letters, numbers, and underscores, starting with a letter).', $scale);
      }
      if (!is_array($declaration) || array_is_list($declaration)) {
        $errors[] = sprintf('%s must be a map with label and shape keys (plus an optional prefix, and keywords for the keyword shape).', $at);
        continue;
      }
      if ($unknown = array_diff(array_keys($declaration), self::TOKEN_SCALE_KEYS)) {
        $errors[] = sprintf('%s has unknown key(s) %s (allowed: %s).', $at, implode(', ', array_map(static fn ($key) => var_export($key, TRUE), $unknown)), implode(', ', self::TOKEN_SCALE_KEYS));
      }
      if (!is_string($declaration['label'] ?? NULL) || trim($declaration['label']) === '') {
        $errors[] = sprintf('%s must declare a label string.', $at);
      }
      $shape = $declaration['shape'] ?? NULL;
      if (!in_array($shape, self::TOKEN_SHAPES, TRUE)) {
        $errors[] = sprintf('%s must declare a shape of: %s.', $at, implode(', ', self::TOKEN_SHAPES));
      }
      if ($shape === 'keyword') {
        $keywords = $declaration['keywords'] ?? NULL;
        if (!is_array($keywords) || !array_is_list($keywords) || $keywords === []
          || $keywords !== array_filter($keywords, static fn ($keyword) => is_string($keyword) && $keyword !== '')) {
          $errors[] = sprintf('%s has the keyword shape, so it must declare keywords: a non-empty list of the keyword strings its tokens may hold.', $at);
        }
      }
      elseif (array_key_exists('keywords', $declaration)) {
        $errors[] = sprintf('%s declares keywords, but only the keyword shape carries a keywords vocabulary.', $at);
      }
      $prefix = NULL;
      if (array_key_exists('prefix', $declaration)) {
        if (!is_string($declaration['prefix']) || !preg_match(self::TOKEN_SCALE_PREFIX, $declaration['prefix'])) {
          $errors[] = sprintf('%s must declare its prefix as a CSS-friendly name segment (lowercase letters, numbers, and hyphens, starting with a letter, not ending in a hyphen).', $at);
        }
        else {
          $prefix = $declaration['prefix'];
        }
      }
      elseif ($valid_name) {
        $prefix = $scale;
      }
      if ($prefix !== NULL) {
        // The swatch namespace is reserved before any scale speaks: user
        // swatches deliver as --swatch-{name} outside every scale, and a
        // scale claiming the prefix (or extending it hyphen-wise) would
        // make var(--swatch-…) references ambiguous between the two
        // vocabularies.
        if ($prefix === self::SWATCH_PREFIX || str_starts_with($prefix, self::SWATCH_PREFIX . '-')) {
          $errors[] = sprintf('%s derives CSS variables with the prefix "--%s-", which is inside the reserved swatch namespace ("--%s-"); user swatches deliver there, so no scale may claim it.', $at, $prefix, self::SWATCH_PREFIX);
        }
        elseif (isset($prefixes[$prefix])) {
          $errors[] = sprintf('%s derives CSS variables with the prefix "--%s-", which the scale "%s" already uses; every scale needs its own variable namespace.', $at, $prefix, $prefixes[$prefix]);
        }
        else {
          $prefixes[$prefix] = $scale;
        }
      }
    }
    // The extension check runs over the well-formed prefixes as a set:
    // reverse lookup of a var name must resolve to exactly one scale, and
    // token names may contain hyphens, so a prefix extending another
    // hyphen-wise is ambiguous no matter which scale authored it.
    $names = array_keys($prefixes);
    foreach ($names as $i => $a) {
      foreach ($names as $j => $b) {
        if ($i !== $j && str_starts_with((string) $b, "$a-")) {
          $errors[] = sprintf('The token scales "%s" and "%s" derive the variable prefixes "--%s-" and "--%s-", one extending the other; token names may contain hyphens, so var() references could not resolve to one scale.', $prefixes[$a], $prefixes[$b], $a, $b);
        }
      }
    }
  }

  /**
   * Validates the site declaration's base section, appending errors.
   *
   * Grammar: a map of HTML tag => declaration, tags from the closed
   * BASE_TAGS vocabulary — a tag outside it fails the build loudly with
   * the legal set, because base defaults print tags into live selectors
   * and a typo'd tag would otherwise ship a scope no page can wear. A
   * declaration may carry a `label:` (the word prose spells the tag by,
   * overriding BASE_TAG_LABELS); it must be a non-empty string. Like
   * token_scales, the section is site vocabulary: declaring it inside any
   * kind, block type, or layout declaration is a misplacement error, never
   * silently dead grammar.
   */
  protected function validateBaseElements(array $declarations, array &$errors): void {
    $dimensions = [
      'kinds' => 'kind',
      'block_types' => 'block type',
      'layouts' => 'layout',
    ];
    foreach ($dimensions as $dimension => $noun) {
      foreach ($declarations[$dimension] as $name => $declaration) {
        if (is_array($declaration) && array_key_exists('base', $declaration)) {
          $errors[] = sprintf('The %s "%s" declares base, but base elements are site vocabulary; declare them under the top-level "site" key of a universal PROVIDER.page_builder.yml.', $noun, $name);
        }
      }
    }
    $site = $declarations['site'] ?? [];
    if (!is_array($site) || !array_key_exists('base', $site)) {
      return;
    }
    $base = $site['base'];
    if (!is_array($base) || $base === [] || array_is_list($base)) {
      $errors[] = 'The base declaration of the site must be a non-empty map of declarations keyed by HTML tag, or be omitted entirely.';
      return;
    }
    foreach ($base as $tag => $declaration) {
      $tag = (string) $tag;
      if (!in_array($tag, self::BASE_TAGS, TRUE)) {
        $errors[] = sprintf('The base element "%s" is not in the base tag vocabulary (%s).', $tag, implode(', ', self::BASE_TAGS));
        continue;
      }
      if (!is_array($declaration) || array_is_list($declaration)) {
        $errors[] = sprintf('The base element "%s" must be a declaration map (property_groups, optionally elements and label).', $tag);
        continue;
      }
      if (array_key_exists('label', $declaration) && (!is_string($declaration['label']) || trim($declaration['label']) === '')) {
        $errors[] = sprintf('The base element "%s" sets its label to %s; a base element label must be a non-empty string (omit it to use the shipped word "%s").', $tag, var_export($declaration['label'], TRUE), self::BASE_TAG_LABELS[$tag]);
      }
    }
  }

  /**
   * Validates every scale-referencing constraint, appending errors.
   *
   * A property constraint may reference a token scale
   * ({constraints: {padding: {scale: space}}}): the property's value space
   * becomes the scale's minted tokens as var() references (plus any allowed
   * entries declared beside it). The scale is code referencing code, so a
   * constraint naming an undeclared scale fails the build loudly — a typo'd
   * scale would otherwise reject every value with an empty allowed list and
   * no clue why. Declarations are checked as authored, like variant_axes:
   * catching the offender at its source. Parts nest, so elements trees are
   * walked at every depth.
   */
  protected function validateScaleConstraints(array $declarations, array &$errors): void {
    $site = $declarations['site'] ?? [];
    $scales = is_array($site) && is_array($site['token_scales'] ?? NULL)
      ? array_map('strval', array_keys($site['token_scales']))
      : [];
    $dimensions = [
      'kinds' => 'kind',
      'block_types' => 'block type',
      'layouts' => 'layout',
    ];
    foreach ($dimensions as $dimension => $noun) {
      foreach ($declarations[$dimension] as $name => $declaration) {
        if (is_array($declaration)) {
          $this->checkScaleConstraints($declaration, sprintf('%s "%s"', $noun, $name), $scales, $errors);
        }
      }
    }
    // Base element declarations carry property_groups like any other
    // declaration, so their scale constraints are checked the same way.
    $base = is_array($site) ? ($site['base'] ?? []) : [];
    foreach (is_array($base) ? $base : [] as $tag => $declaration) {
      if (is_array($declaration)) {
        $this->checkScaleConstraints($declaration, sprintf('base element "%s"', $tag), $scales, $errors);
      }
    }
  }

  /**
   * Validates the site declaration's constraint_defaults, appending errors.
   *
   * The authored offer vocabulary's grammar gate: a non-empty map of CSS
   * property => constraint block, each block a non-empty map in the same
   * {scale, stops, allowed, min, max, units} grammar node constraints
   * speak, checked by the same rules (checkConstraintBlock() — one
   * grammar, one check). Code-vs-code facts only fail the build: the
   * property must be one some property group recognizes (a typo'd key
   * would silently serve a dead binding site-wide), a referenced scale
   * must be declared, stops must sit beside a scale as a non-empty list.
   * Stops entries are token NAMES — guide code naming skin data — so
   * membership against the minted corpus is a delivery question, never a
   * build error (skin data must not brick the guide). Like token_scales
   * and base, the section is site vocabulary: declaring it inside any
   * kind, block type, or layout declaration is a misplacement error,
   * never silently dead grammar.
   */
  protected function validateConstraintDefaults(array $declarations, array &$errors): void {
    $dimensions = [
      'kinds' => 'kind',
      'block_types' => 'block type',
      'layouts' => 'layout',
    ];
    foreach ($dimensions as $dimension => $noun) {
      foreach ($declarations[$dimension] as $name => $declaration) {
        if (is_array($declaration) && array_key_exists('constraint_defaults', $declaration)) {
          $errors[] = sprintf('The %s "%s" declares constraint_defaults, but constraint defaults are site vocabulary; declare them under the top-level "site" key of a universal PROVIDER.page_builder.yml (per-element constraints belong in the declaration\'s property_groups).', $noun, $name);
        }
      }
    }
    $site = $declarations['site'] ?? [];
    if (!is_array($site) || !array_key_exists('constraint_defaults', $site)) {
      return;
    }
    $defaults = $site['constraint_defaults'];
    if (!is_array($defaults) || $defaults === [] || array_is_list($defaults)) {
      $errors[] = 'The constraint_defaults declaration of the site must be a non-empty map of constraint blocks keyed by CSS property name, or be omitted entirely.';
      return;
    }
    $scales = is_array($site['token_scales'] ?? NULL)
      ? array_map('strval', array_keys($site['token_scales']))
      : [];
    foreach ($defaults as $property => $constraints) {
      $property = (string) $property;
      if ($this->propertyGroupManager->getGroupIdForProperty($property) === NULL) {
        $errors[] = sprintf('The constraint default on "%s" names a property no property group recognizes; a site-wide default on an unrecognized property could never bind.', $property);
      }
      if (!is_array($constraints) || $constraints === [] || array_is_list($constraints)) {
        $errors[] = sprintf('The constraint default on property "%s" must be a non-empty constraint block ({scale, stops, allowed, min, max, units} — the same grammar as a node constraint).', $property);
        continue;
      }
      $this->checkConstraintBlock($property, $constraints, "the site's constraint_defaults", $scales, $errors);
    }
  }

  /**
   * Validates every declaration's children sections, appending errors.
   *
   * The '> *' children selector's grammar gate: a children section,
   * legal on any element declaration (kinds, block types, layouts, parts
   * at any depth, base elements), must be a map carrying at most a
   * property_groups map, the groups its "> *" entries accept. The
   * vocabulary is deliberately closed (like every owned grammar here): the
   * children pseudo-part is styling-only, so an anatomy-shaped key inside
   * it (label, contains, nested elements) is a category error caught at
   * build, never silently dead grammar. Declarations are checked as
   * authored, like variant_axes: catching the offender at its source.
   */
  protected function validateChildrenSections(array $declarations, array &$errors): void {
    $dimensions = [
      'kinds' => 'kind',
      'block_types' => 'block type',
      'layouts' => 'layout',
    ];
    foreach ($dimensions as $dimension => $noun) {
      foreach ($declarations[$dimension] as $name => $declaration) {
        if (is_array($declaration)) {
          $this->checkChildrenSections($declaration, sprintf('%s "%s"', $noun, $name), $errors);
        }
      }
    }
    $site = $declarations['site'] ?? [];
    $base = is_array($site) ? ($site['base'] ?? []) : [];
    foreach (is_array($base) ? $base : [] as $tag => $declaration) {
      if (is_array($declaration)) {
        $this->checkChildrenSections($declaration, sprintf('base element "%s"', $tag), $errors);
      }
    }
  }

  /**
   * Checks one declaration's children section, recursing into its parts.
   */
  protected function checkChildrenSections(array $declaration, string $where, array &$errors): void {
    if (array_key_exists('children', $declaration)) {
      $children = $declaration['children'];
      if (!is_array($children) || (array_is_list($children) && $children !== [])) {
        $errors[] = sprintf('The children declaration of %s must be a map declaring the property_groups the element\'s direct children ("> *") may style, or be omitted entirely.', $where);
      }
      else {
        if ($unknown = array_diff(array_keys($children), ['property_groups'])) {
          $errors[] = sprintf('The children declaration of %s has unknown key(s) %s; the children pseudo-part is styling-only, carrying exactly a property_groups map.', $where, implode(', ', array_map(static fn ($key) => var_export($key, TRUE), $unknown)));
        }
        if (array_key_exists('property_groups', $children)
          && (!is_array($children['property_groups']) || (array_is_list($children['property_groups']) && $children['property_groups'] !== []))) {
          $errors[] = sprintf('The children declaration of %s must declare property_groups as a map of group id => optional {constraints, defaults}, like any element\'s.', $where);
        }
      }
    }
    $elements = $declaration['elements'] ?? [];
    foreach (is_array($elements) ? $elements : [] as $selector => $part) {
      if (is_array($part)) {
        $this->checkChildrenSections($part, sprintf('part "%s" of %s', $selector, $where), $errors);
      }
    }
  }

  /**
   * Validates every declaration's pseudo sections, appending errors.
   *
   * The generated-box grammar gate: a `pseudo` section is a map keyed by
   * the pseudo-element names the relative selector admits
   * (RelativeSelector::PSEUDOS: before, after), each a map carrying
   * exactly a property_groups map, on a kind, block type, block plugin,
   * layout, base tag or any part of their elements trees (the nodes the
   * roster walk can land a pseudo-element on). Its complement: the
   * pseudo-only groups (PropertyGroupPluginManager::getPseudoGroups(),
   * `generated_content`) are granted on every generated box by the walk
   * and mean nothing on a real box, so a declaration naming one on an
   * element, a part or the children fails the build instead of promising
   * a grant the validator refuses at write time.
   */
  protected function validatePseudoSections(array $declarations, array &$errors): void {
    $dimensions = [
      'kinds' => 'kind',
      'block_types' => 'block type',
      'block_plugins' => 'block plugin',
      'layouts' => 'layout',
    ];
    foreach ($dimensions as $dimension => $noun) {
      foreach ($declarations[$dimension] ?? [] as $name => $declaration) {
        if (is_array($declaration)) {
          $this->checkPseudoSections($declaration, sprintf('%s "%s"', $noun, $name), $errors);
        }
      }
    }
    $site = $declarations['site'] ?? [];
    $base = is_array($site) ? ($site['base'] ?? []) : [];
    foreach (is_array($base) ? $base : [] as $tag => $declaration) {
      if (is_array($declaration)) {
        $this->checkPseudoSections($declaration, sprintf('base element "%s"', $tag), $errors);
      }
    }
  }

  /**
   * Checks one declaration's pseudo section and its parts', appending errors.
   *
   * @param array $declaration
   *   The declaration node (an element, a part, the children section).
   * @param string $where
   *   Names the node in errors.
   * @param string[] $errors
   *   The error list, appended to.
   * @param bool $generated
   *   TRUE when the node is itself a generated box (a pseudo section's
   *   entry), where the pseudo-only groups belong.
   */
  protected function checkPseudoSections(array $declaration, string $where, array &$errors, bool $generated = FALSE): void {
    $groups = $declaration['property_groups'] ?? [];
    if (!$generated) {
      foreach (is_array($groups) ? array_keys($groups) : [] as $group_id) {
        if (is_string($group_id) && $this->propertyGroupManager->isPseudoOnly($group_id)) {
          $errors[] = sprintf('The property_groups of %s name the "%s" group, which is granted only at a generated box ("&::before", ".part::after") and means nothing on a real one; the roster grants it at every pseudo-element by itself, so drop it here (a pseudo section, {pseudo: {before: {property_groups: …}}}, declares what else the box may style).', $where, $group_id);
        }
      }
    }
    if (array_key_exists('pseudo', $declaration)) {
      $section = $declaration['pseudo'];
      if ($generated) {
        $errors[] = sprintf('The pseudo declaration of %s is not valid there: a generated box has no generated boxes of its own (a pseudo-element is terminal).', $where);
      }
      elseif (!is_array($section) || (array_is_list($section) && $section !== [])) {
        $errors[] = sprintf('The pseudo declaration of %s must be a map keyed by pseudo-element (%s), each declaring the property_groups that generated box may style, or be omitted entirely.', $where, implode(', ', RelativeSelector::PSEUDOS));
      }
      else {
        foreach ($section as $pseudo => $box) {
          $at = sprintf('the ::%s pseudo-element of %s', $pseudo, $where);
          if (!in_array($pseudo, RelativeSelector::PSEUDOS, TRUE)) {
            $errors[] = sprintf('The pseudo declaration of %s keys by "%s", which is not a pseudo-element the relative selector admits; the generated boxes are %s.', $where, $pseudo, implode(' and ', RelativeSelector::PSEUDOS));
            continue;
          }
          if (!is_array($box) || (array_is_list($box) && $box !== [])) {
            $errors[] = sprintf('The declaration of %s must be a map declaring the property_groups the generated box may style.', $at);
            continue;
          }
          if ($unknown = array_diff(array_keys($box), ['property_groups'])) {
            $errors[] = sprintf('The declaration of %s has unknown key(s) %s; a generated box is styling-only, carrying exactly a property_groups map.', $at, implode(', ', array_map(static fn ($key) => var_export($key, TRUE), $unknown)));
          }
          if (array_key_exists('property_groups', $box)
            && (!is_array($box['property_groups']) || (array_is_list($box['property_groups']) && $box['property_groups'] !== []))) {
            $errors[] = sprintf('The declaration of %s must declare property_groups as a map of group id => optional {constraints, defaults}, like any element\'s.', $at);
          }
          $this->checkPseudoSections($box, $at, $errors, TRUE);
        }
      }
    }
    $elements = $declaration['elements'] ?? [];
    foreach (is_array($elements) ? $elements : [] as $selector => $part) {
      if (is_array($part)) {
        $this->checkPseudoSections($part, sprintf('part "%s" of %s', $selector, $where), $errors);
      }
    }
    $children = $declaration['children'] ?? [];
    if (is_array($children) && !array_is_list($children)) {
      $this->checkPseudoSections($children, sprintf('the children of %s', $where), $errors);
    }
  }

  /**
   * The declared generated boxes of a node: pseudo name => its declaration.
   *
   * The shared read of the recursing build checks and of the client
   * payload's serving walk: a `pseudo` section's well-formed entries (a
   * map per pseudo-element the grammar admits), each a node like a
   * part's, carrying property_groups the allowlist and scale checks judge
   * as any node's. Malformed sections are the pseudo-section check's
   * business and are skipped here.
   *
   * @return array<string, array>
   *   Pseudo-element name => its declaration node.
   */
  public static function pseudoNodes(array $declaration): array {
    $section = $declaration['pseudo'] ?? NULL;
    $nodes = [];
    foreach (is_array($section) ? $section : [] as $pseudo => $box) {
      if (in_array($pseudo, RelativeSelector::PSEUDOS, TRUE) && is_array($box) && !array_is_list($box)) {
        $nodes[$pseudo] = $box;
      }
    }
    return $nodes;
  }

  /**
   * Checks one declaration's scale constraints, recursing into its parts.
   */
  protected function checkScaleConstraints(array $declaration, string $where, array $scales, array &$errors): void {
    $groups = $declaration['property_groups'] ?? [];
    foreach (is_array($groups) ? $groups : [] as $declared) {
      if (!is_array($declared) || !is_array($declared['constraints'] ?? NULL)) {
        continue;
      }
      foreach ($declared['constraints'] as $property => $constraints) {
        if (!is_array($constraints)) {
          continue;
        }
        $this->checkConstraintBlock((string) $property, $constraints, $where, $scales, $errors);
      }
    }
    $elements = $declaration['elements'] ?? [];
    foreach (is_array($elements) ? $elements : [] as $selector => $part) {
      if (is_array($part)) {
        $this->checkScaleConstraints($part, sprintf('part "%s" of %s', $selector, $where), $scales, $errors);
      }
    }
    // The children pseudo-part carries property_groups like any element's,
    // so its scale constraints are checked the same way (it declares no
    // elements of its own, so the recursion terminates there); a declared
    // generated box likewise.
    $children = $declaration['children'] ?? [];
    if (is_array($children) && !array_is_list($children)) {
      $this->checkScaleConstraints($children, sprintf('the children of %s', $where), $scales, $errors);
    }
    foreach (static::pseudoNodes($declaration) as $pseudo => $box) {
      $this->checkScaleConstraints($box, sprintf('the ::%s pseudo-element of %s', $pseudo, $where), $scales, $errors);
    }
  }

  /**
   * Validates every declaration's property allowlists, appending errors.
   *
   * The `properties` key of a group declaration narrows the grant to a
   * subset of the group's own vocabulary, so its grammar gate is
   * code-vs-code: a non-empty list whose every entry names a LONGHAND the
   * group provides. A typo'd entry would silently grant nothing (the
   * property it meant stays excluded), so it fails the build instead; so
   * does a shorthand, which the family rule grants from its longhands and
   * would otherwise name a grant the roster never gives. Groups no
   * installed module provides are skipped, matching reference emission
   * (propertySetRefs()) and what validation can enforce.
   */
  protected function validatePropertyAllowlists(array $declarations, array &$errors): void {
    $dimensions = [
      'kinds' => 'kind',
      'block_types' => 'block type',
      'layouts' => 'layout',
    ];
    foreach ($dimensions as $dimension => $noun) {
      foreach ($declarations[$dimension] as $name => $declaration) {
        if (is_array($declaration)) {
          $this->checkPropertyAllowlists($declaration, sprintf('%s "%s"', $noun, $name), $errors);
        }
      }
    }
    $site = $declarations['site'] ?? [];
    foreach (is_array($site) ? ($site['base'] ?? []) : [] as $tag => $declaration) {
      if (is_array($declaration)) {
        $this->checkPropertyAllowlists($declaration, sprintf('base element "%s"', $tag), $errors);
      }
    }
  }

  /**
   * Checks one declaration's property allowlists, recursing into its parts.
   */
  protected function checkPropertyAllowlists(array $declaration, string $where, array &$errors): void {
    $groups = $declaration['property_groups'] ?? [];
    foreach (is_array($groups) ? $groups : [] as $group_id => $declared) {
      if (!is_array($declared) || !is_string($group_id) || !$this->propertyGroupManager->hasDefinition($group_id)) {
        continue;
      }
      if (!array_key_exists('properties', $declared)) {
        continue;
      }
      $allowed = $declared['properties'];
      if (!is_array($allowed) || !array_is_list($allowed) || $allowed === []
        || array_filter($allowed, static fn ($name) => !is_string($name) || $name === '') !== []) {
        $errors[] = sprintf(
          'The properties allowlist on the "%s" group of %s must be a non-empty list of the group\'s own property names (the subset this element grants), or be omitted entirely.',
          $group_id,
          $where,
        );
        continue;
      }
      $group = $this->propertyGroupManager->createInstance($group_id);
      $owned = array_keys($group->getProperties());
      foreach (array_diff($allowed, $owned) as $name) {
        $errors[] = sprintf(
          'The properties allowlist on the "%s" group of %s names "%s", which the group does not provide; an allowlist may only narrow the group\'s own vocabulary.',
          $group_id,
          $where,
          $name,
        );
      }
      foreach (array_intersect($allowed, array_keys($group->getShorthands())) as $name) {
        $errors[] = sprintf(
          'The properties allowlist on the "%s" group of %s names "%s", a shorthand; grants are stated over longhands, so list %s instead (the shorthand is granted wherever every one of them is).',
          $group_id,
          $where,
          $name,
          implode(', ', $group->getShorthands()[$name]),
        );
      }
    }
    $elements = $declaration['elements'] ?? [];
    foreach (is_array($elements) ? $elements : [] as $selector => $part) {
      if (is_array($part)) {
        $this->checkPropertyAllowlists($part, sprintf('part "%s" of %s', $selector, $where), $errors);
      }
    }
    $children = $declaration['children'] ?? [];
    if (is_array($children) && !array_is_list($children)) {
      $this->checkPropertyAllowlists($children, sprintf('the children of %s', $where), $errors);
    }
    foreach (static::pseudoNodes($declaration) as $pseudo => $box) {
      $this->checkPropertyAllowlists($box, sprintf('the ::%s pseudo-element of %s', $pseudo, $where), $errors);
    }
  }

  /**
   * Checks one constraint block's scale grammar, appending errors.
   *
   * The one constraint grammar, shared by node constraints
   * (checkScaleConstraints()) and the site's authored constraint_defaults
   * (validateConstraintDefaults()): stops is only meaningful beside a
   * scale, a referenced scale must be declared, and stops must be a
   * non-empty list of token names. Grammar only for stops entries: names
   * are skin data — membership against the minted corpus is answered at
   * delivery (the client drops un-minted names, the payload build logs
   * the drift), never here, where an error would let skin data brick the
   * whole guide.
   */
  protected function checkConstraintBlock(string $property, array $constraints, string $where, array $scales, array &$errors): void {
    if (array_key_exists('stops', $constraints) && !array_key_exists('scale', $constraints)) {
      $errors[] = sprintf(
        'The constraint on property "%s" of %s declares stops without a scale; stops curates which of a scale\'s tokens the stop rows offer, so it is only meaningful beside a scale constraint.',
        $property,
        $where,
      );
    }
    if (!array_key_exists('scale', $constraints)) {
      return;
    }
    $scale = $constraints['scale'];
    if (!is_string($scale) || !in_array($scale, $scales, TRUE)) {
      $errors[] = sprintf(
        'The constraint on property "%s" of %s references the token scale %s, which is not declared%s.',
        $property,
        $where,
        var_export($scale, TRUE),
        $scales === []
          ? ' (this site declares no token scales; declare them under the site declaration\'s token_scales section)'
          : sprintf(' (declared scales: %s)', implode(', ', $scales)),
      );
    }
    if (array_key_exists('stops', $constraints)) {
      $stops = $constraints['stops'];
      $invalid = !is_array($stops) || !array_is_list($stops) || $stops === []
        || array_filter($stops, static fn ($name) => !is_scalar($name) || trim((string) $name) === '') !== [];
      if ($invalid) {
        $errors[] = sprintf(
          'The stops of the constraint on property "%s" of %s must be a non-empty list of token names (which of the "%s" scale\'s tokens the stop rows offer, in order).',
          $property,
          $where,
          is_string($scale) ? $scale : 'scale',
        );
      }
    }
  }

  /**
   * A control binding's element key as its ordered candidate list.
   *
   * The one place the binding grammar's shorthands are read: an omitted
   * key is [''] (the element root), a string is the one-candidate list,
   * and a list rides as authored. '' spells the root, the declared-defaults
   * grammar's spelling, so a list may put the root after a part
   * (['.text-block-link', '']: the link when it carries the value, else
   * the tag). Validation checks each candidate of the list this returns
   * and the client is served the list, so neither ever sees a shorthand
   * and the resolver (controls.js resolveCandidates()) has one input.
   *
   * @param array $control
   *   A control declaration map ({label, widget, element?, section?}).
   *
   * @return string[]
   *   The candidates in authored order, each '' or a part selector.
   *
   * @throws \InvalidArgumentException
   *   When the element key is neither absent, a string, nor a non-empty list
   *   of distinct strings; the message says what a well-formed key is, for
   *   the guide validator to attribute to the control.
   */
  public static function controlCandidates(array $control): array {
    if (!array_key_exists('element', $control)) {
      return [''];
    }
    $element = $control['element'];
    if (is_string($element)) {
      return [$element];
    }
    $well_formed = is_array($element) && array_is_list($element) && $element !== []
      && array_filter($element, 'is_string') === $element
      && count(array_unique($element)) === count($element);
    if (!$well_formed) {
      throw new \InvalidArgumentException(sprintf(
        'has %s under its element key, which is not a candidate list: the key is a part selector, a non-empty list of distinct part selectors in priority order (\'\' spelling the root), or omitted to bind the root.',
        var_export($element, TRUE),
      ));
    }
    return $element;
  }

  /**
   * Validates one effective declaration's control bindings, appending errors.
   *
   * The shared half of validateControls() for the declaration-shaped guide
   * dimensions (kinds, block types, block plugins and layouts): each binds
   * controls to an ordered candidate list (controlCandidates()), every
   * candidate '' for the element root or a declared part found anywhere in
   * the declaration's elements tree, since parts nest. Each candidate is
   * checked on its own and the error names it, so a list whose second
   * entry is undeclared or uncovered fails as loudly as a single binding
   * would: the resolver may route any owned property to any candidate, so
   * every candidate must be a target the guide vouches for. $where names
   * the declaration in errors ('block type "media"'), $root the root
   * binding ("the block root"), $owner the declaring owner ("the block
   * type").
   */
  protected function validateDeclarationControls(array $effective, string $where, string $root, string $owner, array &$errors): void {
    $controls = $effective['controls'] ?? [];
    if (!is_array($controls)) {
      $errors[] = sprintf('The controls declaration of %s must be a map of control declarations.', $where);
      return;
    }
    foreach ($controls as $name => $control) {
      if ($control === FALSE) {
        continue;
      }
      $at = sprintf('The control "%s" of %s', $name, $where);
      if (!is_array($control)) {
        $errors[] = "$at must be a map with label and widget keys plus an optional element key, or FALSE to suppress an inherited control.";
        continue;
      }
      $this->validateControlSection($at, $control, $errors);
      $widget_id = $this->validateControlWidgetId($at, $control, $errors);
      try {
        $candidates = static::controlCandidates($control);
      }
      catch (\InvalidArgumentException $e) {
        // The grammar error is the normalizer's; this build attributes it
        // to the control and keeps collecting, so one bad key never hides
        // the errors beside it.
        $errors[] = "$at {$e->getMessage()}";
        continue;
      }
      foreach ($candidates as $candidate) {
        if ($candidate === '') {
          // The root candidate: the effective property groups are what
          // must cover the widget.
          $groups = $effective['property_groups'] ?? [];
          [$binding, $owner_label] = ["to $root", $owner];
        }
        elseif (($part = PageBuilderDeclarations::findElement($effective, $candidate)) === NULL) {
          $errors[] = sprintf('%s binds element "%s", which is not a declared part of %s.', $at, $candidate, $owner);
          continue;
        }
        else {
          $groups = $part['property_groups'] ?? [];
          [$binding, $owner_label] = [sprintf('to element "%s"', $candidate), 'the part'];
        }
        if ($widget_id !== NULL) {
          $this->validateControlCoverage($at, $widget_id, $groups, $binding, $owner_label, $errors);
        }
      }
    }
  }

  /**
   * Validates a control's widget key, appending errors.
   *
   * @return string|null
   *   The widget plugin id when it names an existing plugin, NULL otherwise.
   */
  protected function validateControlWidgetId(string $at, array $control, array &$errors): ?string {
    $widget_id = $control['widget'] ?? NULL;
    if (!is_string($widget_id) || $widget_id === '') {
      $errors[] = "$at does not name a control widget plugin under its widget key.";
      return NULL;
    }
    if (!$this->controlWidgetManager->hasDefinition($widget_id)) {
      $errors[] = sprintf('%s names unknown control widget "%s".', $at, $widget_id);
      return NULL;
    }
    return $widget_id;
  }

  /**
   * Validates a control's optional section key, appending errors.
   *
   * A binding may move its control out of the widget's default section
   * (ControlWidget::$section) with `section: <id>`; the id must name a
   * ControlSection case, the closed roster basic mode renders headers
   * for. Absent means the widget's own.
   */
  protected function validateControlSection(string $at, array $control, array &$errors): void {
    if (!array_key_exists('section', $control)) {
      return;
    }
    $section = $control['section'];
    if (!is_string($section) || ControlSection::tryFrom($section) === NULL) {
      $errors[] = sprintf('%s names unknown control section "%s" under its section key; the sections are %s.', $at, is_scalar($section) ? (string) $section : gettype($section), implode(', ', array_map(static fn (ControlSection $case) => $case->value, ControlSection::cases())));
    }
  }

  /**
   * Checks that property groups cover a widget's owned properties.
   *
   * The bound target's property groups must cover the widget's owned
   * properties, or the control's writes would be undeclared. $binding and
   * $owner phrase the error for the target: "to the block root"/"the block
   * type", "to element X"/"the part", or "to the kind root"/"the kind".
   */
  protected function validateControlCoverage(string $at, string $widget_id, mixed $groups, string $binding, string $owner, array &$errors): void {
    foreach ($this->controlWidgetManager->getDefinition($widget_id)['properties'] as $property) {
      $group = $this->propertyGroupManager->getGroupIdForProperty($property);
      if ($group === NULL) {
        $errors[] = sprintf('%s uses widget "%s", which owns "%s", but no property group provides that property.', $at, $widget_id, $property);
      }
      elseif (!is_array($groups) || !array_key_exists($group, $groups)) {
        $errors[] = sprintf('%s binds widget "%s" %s, but %s does not declare the "%s" property group that owns "%s".', $at, $widget_id, $binding, $owner, $group, $property);
      }
    }
  }

}
