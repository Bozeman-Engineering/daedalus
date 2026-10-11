<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_ui\ElementModel\DeclarationOrigin;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder;

/**
 * Builds the style guide payload the inspector client renders from.
 *
 * The inspector's property-group sections are a projection of the style
 * guide (the guide IS the "what does the panel show" artifact), so
 * the client needs the same answers the validator enforces, plus the
 * property definitions the guide's group ids refer to, which live in the
 * property group plugins and are otherwise invisible to JS.
 *
 * One payload per page, attached as drupalSettings.daedalusStylish.guide by the
 * sidebar plugin and the pop-out satellite page. It is guide-shaped, not
 * selection-shaped: a served node for every kind, block type, block
 * plugin, layout, base tag and instance namespace ships up front, and the
 * client resolves the selected element to its node (style-guide.js
 * declarationForKey(), the client twin of the roster builder's address
 * resolution), so the panel follows selection without a round trip per
 * click.
 *
 * ROSTERS, not declarations: every served node carries the vocabulary roster's
 * resolved answers for its address (VocabularyRosterBuilder, the one derivation
 * the validator, the AI catalog and this payload all read): `grants` (group id
 * => the property names granted, allowlists resolved), `valueSpaces` (property
 * => the effective constraint block, the node's own, else the site's authored
 * constraint default) and, on a root node, `axes` (axis => {label, root_only,
 * description, owns}, the effective variant axis vocabulary with its depth
 * stamps, its descriptions and the properties each axis owns). The client never
 * re-derives a grant or a value space from authored declarations;
 * style-guide.js is the locked twin of the roster's reads
 * (VocabularyRosterTest:: testClientTwinEquivalence over
 * tests/js/vocabulary-cases.mjs). The sections a declaration carries beside its
 * styling (label, elements, controls, defaults, composed, fields, tags,
 * contains) ride as authored, each part and children section served as a node
 * of its own.
 *
 * Declarations only: the site's MATERIALS (the active skin's tokens, the
 * published swatches, the variants, the blueprints) are not guide data and
 * do not ride here. They ride the materials delivery payload beside the
 * blueprint mirror on the same render (daedalus_blueprint's
 * MaterialsDelivery, attached by daedalus_ui's BlueprintMirror) and
 * the `materials` delta on every mutation response, one record shape for
 * every kind, so a mint lands in every picker at once. The group
 * vocabularies the pickers sort materials under (token scales, swatch
 * kinds) ARE declarations and stay here.
 *
 * Theme resolution is PageBuilderDeclarations::siteTheme() (the site
 * default theme), matching validation and the AI schema: the guide the
 * panel displays must be the guide writes are checked against, and the
 * sidebar can render on admin-theme routes where the active theme would
 * resolve the wrong guide.
 */
class ClientStyleGuide {

  public function __construct(
    protected StyleGuide $styleGuide,
    protected PropertyGroupPluginManager $propertyGroups,
    protected ConditionTypePluginManager $conditionTypes,
    protected PageBuilderDeclarations $declarations,
    protected ControlWidgetPluginManager $controlWidgets,
    protected SkinManager $skinManager,
    protected SwatchKindPluginManager $swatchKinds,
    protected VariantConformance $variantConformance,
    protected VocabularyRosterBuilder $rosterBuilder,
    protected EntityTypeBundleInfoInterface $bundleInfo,
  ) {}

  /**
   * Cache tags the payload derives from.
   *
   * Attach these wherever the payload is attached: the layered page builder
   * declarations (daedalus_ui_page_builder), the condition breakpoints
   * the conditions block serves (daedalus_stylish.settings), the active design
   * system whose default skin the payload serves and whose enforcement
   * mode it reports (daedalus_blueprint.settings for the pointer,
   * design_system_list for the entity: an edit to the active system's mode
   * is an entity save), the default theme the guide resolves for
   * (system.theme), and the active skin's site defaults
   * the payload serves (daedalus_skin_list: the entity list tag, so any
   * skin save invalidates; the activation itself is config, already
   * covered), and the variant list the `withheld` verdict is judged over
   * (daedalus_variant_list: a variant save re-judges, so the reasons the
   * payload serves follow). Materials themselves are not the payload's:
   * the roster's own metadata rides the materials delivery payload.
   *
   * @var string[]
   */
  public const CACHE_TAGS = [
    'daedalus_ui_page_builder',
    'config:daedalus_stylish.settings',
    'config:daedalus_blueprint.settings',
    'design_system_list',
    'config:system.theme',
    'daedalus_skin_list',
    'daedalus_variant_list',
  ];

  /**
   * Builds the client payload.
   *
   * @param string|null $theme
   *   The style guide theme; defaults to the site default theme.
   *
   * @return array
   *   - enforcement: (string) the site's enforcement mode — open, guarded,
   *     or strict (strict is the closed world).
   *   - groups: property definitions per group id, in inspector panel order
   *     (weight, id), each {label, properties: {name: definition}} with
   *     labels cast to plain strings, plus `pseudo: true` on a group
   *     granted only at a generated box (PropertyGroupPluginManager::
   *     isPseudoOnly(): generated_content, the existence switch). This is
   *     the TYPED catalog the rosters' grants resolve against: the
   *     inspector renders real controls from the definitions (type,
   *     keywords, repeat, negative), a consumer-appropriate catalog beside
   *     the AI's prose. Curated groups only: a grant keyed by an id absent
   *     from this catalog is by construction a generated wide-tier group,
   *     whose granted names still ride the node and whose values the
   *     client lints through csstree's embedded tables instead.
   *   - conditions: condition name => {label, wrapper, axis, band?,
   *     preference?, fact?}, in declaration order ("default" is not
   *     listed; the client knows it comes first). The wrapper carries
   *     live thresholds: it is the same table the server printer
   *     dispatches on, so the client printer can never disagree. The axis
   *     is the compound lane (state/structural/width/scheme/motion):
   *     entry keys may compound conditions across axes ("narrow+dark"),
   *     one per axis, and the canonical part order is the order axes
   *     first appear in this table. The structured facts ride beside the
   *     wrapper exactly as the condition type declares them
   *     (ConditionTypeInterface::getConditions()): `band` {min?, max?}
   *     for a canvas-posable width band (the ruler's stops and the "drive
   *     the canvas there" pose), `preference` {feature, value} for a user
   *     preference the canvas emulates, `fact` TRUE for a structural
   *     pseudo-class the editor can neither force nor pose (the printer
   *     twin prints it bare, the pose model skips it, the code view
   *     lights its row when the selection satisfies it). The client
   *     never recovers a band or preference from the wrapper text.
   *   - containerRoots: (bool) whether the page-container rule is enabled
   *     (the container_roots kill switch); the client preview must
   *     replicate the backend's page-level width-band query root only when
   *     the server would emit it.
   *   - kinds: the served node per element kind (PageBuilderDeclarations::
   *     KINDS): the roster's answers at "kind:{kind}" (grants,
   *     valueSpaces, axes) plus the merged declaration's other sections
   *     as authored. Every part of its elements tree at any depth is
   *     served as the node at "kind:{kind}::{selector}", and a `children`
   *     section as the node at "{address}::> *" (the client resolves the
   *     '> *' compound to it, declarationForKey()). An owner
   *     PATTERN part ("{prefix}*", not an address) is served through the
   *     builder's node projection, so it answers exactly what the roster
   *     of any concrete class it covers answers. A DECLARED generated box
   *     (a node's `pseudo: {before: …, after: …}` section) is served
   *     under the node as `pseudo.before` / `pseudo.after`, the roster at
   *     "{address}::&::before" (a root's) or "{address}::before" (a
   *     part's), a pattern node's through the builder's pseudoNode()
   *     projection; an UNDECLARED box is not served at all, since
   *     existence is universal and its vocabulary derives from the host
   *     node already served (style-guide.js pseudoNode(): the host's
   *     grants plus the pseudo-only groups, the builder's own derivation
   *     read off served answers), so the payload never doubles. The
   *     authored property_groups and variant_axes never ride.
   *   - blockTypes: the served node per declared block type, the roster at
   *     "block_type:{bundle}" (the universal block kind layered underneath
   *     by the guide; an undeclared bundle falls back to kinds.block
   *     client-side, which is already the effective node), carrying the
   *     bundle's human `label` (the block_content type's, the one name
   *     every type surface wears: the code view's selector rows and its
   *     completion label a `pb-{bundle}` compound by it) when the bundle
   *     exists on the site (a declaration for a dormant bundle serves
   *     none). A composed
   *     bundle's node carries its composed: true as authored (the render
   *     composes to ONE root element tree, so no kind or field shells
   *     apply); the per-instance render fact rides the blueprint's
   *     _element annotations.
   *   - blockPlugins: the served node per declared PLUGIN block (site
   *     branding, menus), keyed by BASE plugin id, the roster at
   *     "block_plugin:{id}" (an undeclared plugin falls back to kinds.block
   *     client-side, same as block types).
   *   - layouts: the served node per declared section layout, the roster
   *     at "layout:{id}" (an undeclared layout falls back to
   *     kinds.container client-side, same as block types).
   *   - instances: the site's declared instance namespaces (the spine's
   *     `instances:` lane, StyleGuide::getInstances()), pattern selector
   *     => served node (grants and valueSpaces through the builder's node
   *     projection; label and tags ride as authored). The last step of
   *     declarationForKey()'s part resolution: a minted identity class no
   *     owner declaration covers resolves here, whichever key kind owns
   *     the carrier, and the answer equals the roster of that concrete
   *     address.
   *   - base: the site's declared base elements (the spine's site `base`
   *     section, StyleGuide::getBaseElements()), HTML tag => the roster's
   *     node at "base:{tag}". The one declaration of a prose tag's
   *     styleability: the tag tier ('{key}::p') resolves here
   *     (findBaseTagDeclaration(), declarationForKey()'s tag branch) and
   *     the code view's tag offer enumerates its keys; instance namespaces
   *     keep identity only.
   *   - tagLabels: {tag: word} for EVERY closed base tag
   *     (StyleGuide::baseTagLabels(): the base declaration's `label:`
   *     where the site declares one, else the shipped word), the one word
   *     a surface spells a tag tier by in prose (style-guide.js
   *     tagWord()). It covers undeclared tags too, so a label read never
   *     falls through to the tag itself for a closed tag; the instance
   *     lane's tag words are identity for minted tags and answer only
   *     for a tag outside the closed set.
   *     Each root node's `axes` entry carries {label, root_only,
   *     description, owns} as the roster serves it (VocabularyRoster::
   *     axes()): the description and the owned properties ride so the
   *     inspector may show why an axis exists and which properties a
   *     wear stands in for.
   *   - axisLabels: {axis: label}, the declared axis labels aggregated
   *     across every root node's axes (an axis machine name means one
   *     thing site-wide); an axis absent from it labels as its
   *     capitalized machine name (variant-scope.js axisLabel()).
   *   - controlWidgets: control widget definition per plugin id, each
   *     {label, section, properties} with labels cast to plain strings —
   *     the server half of the basic-mode control contract. The controls:
   *     bindings themselves ride inside the served declarations (kinds,
   *     block types, block plugins, layouts, instance namespaces) with
   *     one shape: `element` is always the ordered candidate list
   *     StyleGuide::controlCandidates() normalizes the authored key to
   *     ('' the root, else a declared part's selector), the guide validator
   *     having vouched for every candidate and for a binding's own
   *     section: override. The client never sees the authoring
   *     shorthands (a string, an omitted key): its resolver
   *     (controls.js resolveCandidates()) routes each owned property to
   *     one candidate of the list, and it resolves each binding's widget
   *     id against this table and its own component registry.
   *   - controlSections: the basic-mode section roster (ControlSection),
   *     section id => {label, weight} in weight order, the headers basic
   *     mode groups its controls under.
   *   - tokenScales: the design-token vocabulary's scale declarations
   *     (StyleGuide::getTokenScales()), scale machine name => {label,
   *     shape, prefix, keywords for the keyword shape} in authoring order
   *     (the order pickers present scales in). The prefix is served
   *     RESOLVED (the declared override, else the scale name), so the
   *     client derives --{prefix}-{name} without re-implementing the
   *     defaulting rule. Token names and values are materials, not guide
   *     payload: they ride the materials delivery payload as token records
   *     grouped by scale.
   *   - constraintDefaults: the site-wide constraint defaults
   *     (StyleGuide::constraintDefaults(): the authored
   *     constraint_defaults map and nothing derived into it), CSS
   *     property => constraint block ({scale, allowed, min, max, units};
   *     never stops, which are per element: a site-wide row leads with
   *     the scale's offered tokens, the `offered` flag each token record
   *     carries). The offer vocabulary's site-wide layer: the client guide
   *     model resolves a node's own value space first (valueSpaces, the
   *     roster's per-address answer), else this map, so a site-level
   *     binding (font-size -> the type scale) shapes stop rows everywhere
   *     without per-node authoring; the built-in stop tables stay the
   *     no-binding, no-skin fallback.
   *   - swatchKinds: kind plugin id => {label} in discovery order — the
   *     served vocabulary the palette picker groups user swatches under,
   *     labels cast to plain strings (the token scales' swatch
   *     counterpart: the client never hardcodes kind labels). The swatches
   *     themselves are materials and ride the materials delivery payload
   *     as swatch records grouped by kind.
   *   - declaredDefaults: the declared defaults tier
   *     (StyleGuide::declaredDefaults()): every kind, block type, block
   *     plugin, and layout declaration's own `defaults` (a kind's under
   *     the ownership law: its declared parts, and its root only when
   *     composed), {scope: {relative key: entry}} in delivery rank order
   *     (kind scopes first). Code, so it serves ALWAYS, skin or no skin:
   *     the inspector resolves an element's scopes against it the way it
   *     resolves the skin's (the family kind's block, the refinement's
   *     merged over it; no base:{tag} layer, since a declaration's box
   *     facts key off its own markup, never the rendered tag) and shows
   *     the value beneath the skin's in the defaults posture, the
   *     captured scope named in the origin, no edit doorway ({} when no
   *     declaration declares defaults for the theme).
   *   - skinDefaults: the ACTIVE skin's site defaults,
   *     {scope: {relative key: entry}} as stored — the inspector's
   *     provenance source: an element resolves its scope's entries
   *     (kind first, refinement over it, mirroring delivery rank order)
   *     to show "from skin" values and shadow badges, and its "Edit
   *     skin…" doorway opens the skin session on the delivering scope
   *     (the same map, refreshed after a push through the defaults route,
   *     SkinApiController::defaults()). Base:{tag} scopes
   *     ride along but no selectable element ever captures them ({} when
   *     no skin is active or it holds no defaults).
   *   - withheld: the published variants the site's CURRENT law refuses
   *     (VariantConformance::refusals()), variant machine name => the
   *     refusals as {path, code, message} triples, paths relative to the
   *     variant's styles ("default.border-radius"). The verdict is a fact
   *     about (variant, law), judged under the site default theme like
   *     every write; such a variant is off the roster (not offered, not
   *     delivered), so an element still wearing it shows a STALE row in
   *     the wear bar, and this map is the row's WHY: "withheld by the
   *     style guide" with the refused values, against the plain "deleted
   *     or unpublished" a name absent from it means. The same triples
   *     ride the STYLE_VARIANT_NONCONFORMING refusal's `refused` context
   *     when a new wearing is attempted. {} when every offered variant
   *     conforms.
   *   - law: the LAW projection, what the style guide declares with the
   *     origin of every declared fact, read under the active design
   *     system's enforcement mode (the Materials place's Law tab, a
   *     read-only view: the guide is authored in files and shown here).
   *     Self-contained: the tab reads this key alone, so its rosters
   *     restate the served nodes' answers (the same roster reads, never a
   *     second derivation) beside facts no served node carries (origins,
   *     birth defaults, the contract). `enforcement` the mode word,
   *     `properties` 'closed' (strict: only granted properties may be
   *     styled) or 'open', `constraints` 'law' (guarded or strict) or
   *     'offer' (open: an authored constraint shapes the rows' offer and
   *     binds nothing). `scales`: scale => {label, shape, prefix,
   *     contract (the names the site's token_contract promises, [] when
   *     none), origins: {scale (the distinct origins of the scale's
   *     facts, the grants rule below: a declaration several layers may
   *     restate), contract (the list's one origin, NULL when none is
   *     declared)}}. `bindings`: the site's
   *     constraint_defaults (constraintDefaults above) as property =>
   *     {block, origins: {fact: origin}}, the origins expanded over the
   *     shorthand families exactly as the blocks are. `nodes`: address
   *     => node for every served kind, block type, block plugin and
   *     layout ("kind:container", "block_type:text", "block_plugin:
   *     system_menu_block", "layout:layout_onecol", the declaring-scope
   *     grammar the stack's floor layers and the skin's scopes speak), a
   *     root node carrying `grants` (the roster's, group => names),
   *     `constraints` (the roster's value spaces, property => the
   *     effective block, curated `stops` inside), `axes` (the roster's,
   *     the built-in style axis first), `birth` (the default variants
   *     an element of the type is born wearing, StyleGuide::
   *     defaultVariants(): axis => {variant, depth} with depth 'root'
   *     for an entry qualified to top-level sections, else NULL),
   *     `defaults` (the declaration's own declared defaults, the
   *     declaredDefaults entry at the node's scope, as authored: the
   *     kind's are the kind node's, never merged beneath a refinement's,
   *     because the stack layers them apart), `label` where the
   *     declaration or the bundle carries one, and `origins`, a mirror
   *     naming where each fact came from: `grants` group => the distinct
   *     origins of every authored fact under the group (a grant is a
   *     key several layers may restate, so it names every layer whose
   *     facts survive under it; one entry for a bare `{}` grant), `
   *     constraints` property => {source: 'node' (the node's own block
   *     wins whole) | 'site' (the site's binding), facts: {fact: origin}},
   *     `axes` the variant_axes list's one origin (NULL for a node
   *     declaring none: the style axis is built in), `birth` axis =>
   *     origin, `defaults` key => condition => property => origin. An
   *     origin is {provider, file} (a module or theme and the file's
   *     basename), {provider: NULL, file: NULL} for a value
   *     hook_page_builder_declarations_alter() wrote, NULL where no
   *     layer wrote the fact (a derived or built-in one). Effective
   *     origins compose as effective declarations do (PageBuilder
   *     Declarations::merge() of the universal kind's tree under the
   *     refinement's). Parts ride as `elements` (selector => node, the
   *     tree as authored), a children section as `children`, a declared
   *     generated box as `pseudo.before` / `pseudo.after`; a part node
   *     carries grants, constraints, origins and label alone (axes,
   *     birth and defaults are the root's). Base tags and instance
   *     namespaces are anatomy the tab does not show.
   */
  public function build(?string $theme = NULL): array {
    $theme ??= $this->declarations->siteTheme();

    // Curated groups only: the generated wide tier is server-side
    // vocabulary, and the client lints wide-tier properties through
    // csstree's embedded mdn tables — zero payload growth by decision.
    $groups = [];
    foreach ($this->propertyGroups->getCuratedGroups() as $id => $group) {
      $properties = [];
      foreach ($group->getProperties() as $name => $definition) {
        $definition['label'] = (string) $definition['label'];
        $properties[$name] = $definition;
      }
      $groups[$id] = [
        'label' => (string) $group->label(),
        'properties' => $properties,
      ];
      // The pseudo-only groups say so, the fact the client's derivation
      // of an undeclared generated box reads (style-guide.js
      // pseudoGroups()): granted at every box and nowhere else.
      if ($this->propertyGroups->isPseudoOnly($id)) {
        $groups[$id]['pseudo'] = TRUE;
      }
    }

    $conditions = [];
    foreach ($this->conditionTypes->getConditions() as $name => $definition) {
      $conditions[$name] = [
        'label' => (string) $definition['label'],
        'wrapper' => $definition['wrapper'],
        'axis' => $definition['axis'],
      ] + array_intersect_key($definition, ['band' => TRUE, 'preference' => TRUE, 'fact' => TRUE]);
    }

    $axis_labels = [];
    $kinds = [];
    foreach (PageBuilderDeclarations::KINDS as $kind) {
      if ($declaration = $this->styleGuide->getKind($kind, $theme)) {
        $kinds[$kind] = $this->serveRoot(Address::kind($kind), $declaration, $theme, $axis_labels);
      }
    }

    $block_types = [];
    $bundle_labels = $this->bundleInfo->getBundleInfo('block_content');
    foreach ($this->styleGuide->getBlockTypes($theme) as $bundle) {
      $block_types[$bundle] = $this->serveRoot(Address::blockType($bundle), $this->styleGuide->getBlockType($bundle, $theme), $theme, $axis_labels);
      if (isset($bundle_labels[$bundle]['label'])) {
        $block_types[$bundle]['label'] = (string) $bundle_labels[$bundle]['label'];
      }
    }

    $block_plugins = [];
    foreach ($this->styleGuide->getBlockPlugins($theme) as $plugin_id) {
      $block_plugins[$plugin_id] = $this->serveRoot(Address::blockPlugin($plugin_id), $this->styleGuide->getBlockPlugin($plugin_id, $theme), $theme, $axis_labels);
    }

    $layouts = [];
    foreach ($this->styleGuide->getLayouts($theme) as $layout_id) {
      $layouts[$layout_id] = $this->serveRoot(Address::layout($layout_id), $this->styleGuide->getLayout($layout_id, $theme), $theme, $axis_labels);
    }

    // Instance namespaces are patterns, not addresses: served through the
    // builder's node projection, the same derivation the roster of every
    // concrete class they cover reads. No axes: instance parts never wear
    // variants (validateAt()'s gate).
    $instances = [];
    foreach ($this->styleGuide->getInstances($theme) as $pattern => $declaration) {
      if (is_array($declaration)) {
        $instances[(string) $pattern] = $this->serveNode($declaration, NULL, $theme);
      }
    }

    // Base elements are addresses ("base:{tag}", the closed BASE_TAGS
    // vocabulary the guide build enforces): the tag tier resolves against
    // them client-side exactly as the validator does server-side. No
    // axes: a tag tier never wears variants.
    $base = [];
    foreach ($this->styleGuide->getBaseElements($theme) as $tag => $declaration) {
      if (is_array($declaration)) {
        $base[(string) $tag] = $this->serveNode($declaration, Address::base((string) $tag), $theme);
      }
    }

    // Token scales serve with their variable prefix resolved, mirroring
    // serveRoot(): the payload is the client's whole world, so
    // defaulting rules resolve server-side.
    $token_scales = [];
    foreach ($this->styleGuide->getTokenScales($theme) as $scale => $declaration) {
      $entry = [
        'label' => (string) $declaration['label'],
        'shape' => $declaration['shape'],
        'prefix' => StyleGuide::tokenPrefix($scale, $declaration),
      ];
      if ($declaration['shape'] === 'keyword') {
        $entry['keywords'] = $declaration['keywords'];
      }
      $token_scales[$scale] = $entry;
    }

    // The active skin's site defaults serve as stored — the inspector's
    // provenance source. Its tokens are materials: the roster's, delivered
    // beside the blueprint mirror, not the guide's.
    $skin_defaults = [];
    $skin = $this->skinManager->loadActiveSkin();
    if ($skin !== NULL && $skin->isPublished()) {
      $skin_defaults = array_filter($skin->defaults(), 'is_array');
    }

    // The kind vocabulary the palette groups swatches under (the swatches
    // themselves ride the materials delivery payload).
    $swatch_kinds = [];
    foreach ($this->swatchKinds->getDefinitions() as $id => $definition) {
      $swatch_kinds[$id] = ['label' => (string) $definition['label']];
    }

    // Widget definitions are declaration-only (id, label, section, owned
    // properties); sorted by id so the payload is deterministic across
    // discovery order.
    $control_widgets = [];
    $definitions = $this->controlWidgets->getDefinitions();
    ksort($definitions);
    foreach ($definitions as $id => $definition) {
      $control_widgets[$id] = [
        'label' => (string) $definition['label'],
        'section' => $definition['section']->value,
        'properties' => $definition['properties'],
      ];
    }

    return [
      'enforcement' => $this->styleGuide->enforcement(),
      'groups' => $groups,
      'conditions' => $conditions,
      'containerRoots' => $this->conditionTypes->containerRootsEnabled(),
      'kinds' => $kinds,
      'blockTypes' => $block_types,
      'blockPlugins' => $block_plugins,
      'layouts' => $layouts,
      'instances' => $instances,
      'base' => $base,
      'tagLabels' => $this->styleGuide->baseTagLabels($theme),
      'controlWidgets' => $control_widgets,
      'controlSections' => ControlSection::roster(),
      'constraintDefaults' => $this->styleGuide->constraintDefaults($theme),
      'tokenScales' => $token_scales,
      'swatchKinds' => $swatch_kinds,
      'declaredDefaults' => $this->styleGuide->declaredDefaults($theme),
      'skinDefaults' => $skin_defaults,
      'axisLabels' => $axis_labels,
      'withheld' => $this->variantConformance->refusals(),
      'law' => $this->serveLaw($theme, $bundle_labels),
    ];
  }

  /**
   * Serves the law projection (see build(): `law`).
   *
   * @param string|null $theme
   *   The theme the guide resolves for.
   * @param array $bundle_labels
   *   The block_content bundle info, for the block type nodes' labels.
   */
  protected function serveLaw(?string $theme, array $bundle_labels): array {
    $origins = $this->declarations->getOrigins($theme);
    $site_origins = is_array($origins['site'] ?? NULL) ? $origins['site'] : [];

    $contract = $this->styleGuide->getTokenContract($theme);
    $scales = [];
    foreach ($this->styleGuide->getTokenScales($theme) as $scale => $declaration) {
      $scale = (string) $scale;
      $scales[$scale] = [
        'label' => (string) $declaration['label'],
        'shape' => $declaration['shape'],
        'prefix' => StyleGuide::tokenPrefix($scale, $declaration),
        'contract' => $contract[$scale] ?? [],
        'origins' => [
          'scale' => static::originsUnder($site_origins['token_scales'][$scale] ?? NULL),
          'contract' => isset($contract[$scale]) ? static::originAt($site_origins, ['token_contract', $scale]) : NULL,
        ],
      ];
    }

    // The site's bindings and their origins expand over the shorthand
    // families together: the origin tree under constraint_defaults
    // mirrors the blocks (property => fact => origin), so the guide's
    // one expansion copies a shorthand's origins to its unauthored
    // longhands exactly as it copies the block.
    $binding_origins = $this->styleGuide->expandConstraintFamilies(static::originsMap($site_origins['constraint_defaults'] ?? NULL));
    $bindings = [];
    foreach ($this->styleGuide->constraintDefaults($theme) as $property => $block) {
      $bindings[$property] = [
        'block' => $block,
        'origins' => static::factOrigins($block, $binding_origins[$property] ?? NULL),
      ];
    }

    $declared_defaults = $this->styleGuide->declaredDefaults($theme);
    $nodes = [];
    foreach (PageBuilderDeclarations::KINDS as $kind) {
      if ($declaration = $this->styleGuide->getKind($kind, $theme)) {
        $nodes["kind:$kind"] = $this->lawRoot(Address::kind($kind), $declaration, $origins['kinds'][$kind] ?? [], $origins['kinds'][$kind] ?? [], $declared_defaults, $binding_origins, $theme);
      }
    }
    $block_origins = $origins['kinds']['block'] ?? [];
    foreach ($this->styleGuide->getBlockTypes($theme) as $bundle) {
      $own = $origins['block_types'][$bundle] ?? [];
      $node = $this->lawRoot(Address::blockType($bundle), $this->styleGuide->getBlockType($bundle, $theme), PageBuilderDeclarations::merge($block_origins, $own), $own, $declared_defaults, $binding_origins, $theme);
      if (isset($bundle_labels[$bundle]['label'])) {
        $node['label'] = (string) $bundle_labels[$bundle]['label'];
      }
      $nodes["block_type:$bundle"] = $node;
    }
    foreach ($this->styleGuide->getBlockPlugins($theme) as $plugin_id) {
      $own = $origins['block_plugins'][$plugin_id] ?? [];
      $nodes["block_plugin:$plugin_id"] = $this->lawRoot(Address::blockPlugin($plugin_id), $this->styleGuide->getBlockPlugin($plugin_id, $theme), PageBuilderDeclarations::merge($block_origins, $own), $own, $declared_defaults, $binding_origins, $theme);
    }
    $container_origins = $origins['kinds']['container'] ?? [];
    foreach ($this->styleGuide->getLayouts($theme) as $layout_id) {
      $own = $origins['layouts'][$layout_id] ?? [];
      $nodes["layout:$layout_id"] = $this->lawRoot(Address::layout($layout_id), $this->styleGuide->getLayout($layout_id, $theme), PageBuilderDeclarations::merge($container_origins, $own), $own, $declared_defaults, $binding_origins, $theme);
    }

    return [
      'enforcement' => $this->styleGuide->enforcement(),
      'properties' => $this->styleGuide->propertiesClosed() ? 'closed' : 'open',
      'constraints' => $this->styleGuide->constraintsAreLaw() ? 'law' : 'offer',
      'scales' => $scales,
      'bindings' => $bindings,
      'nodes' => $nodes,
    ];
  }

  /**
   * Serves one root declaration's law node.
   *
   * @param \Drupal\daedalus_blueprint\Materials\Address $address
   *   The root address.
   * @param array $declaration
   *   The effective declaration.
   * @param array $origins
   *   The effective origin tree (the universal kind's merged under the
   *   refinement's).
   * @param array $own_origins
   *   The declaration's own origin tree, as authored: the declared
   *   defaults are the node's own scope layer, never the kind's merged
   *   beneath, so their origins read here.
   * @param array $declared_defaults
   *   StyleGuide::declaredDefaults(): scope => the declared entries.
   * @param array $binding_origins
   *   The site bindings' origin map, expanded over the families.
   * @param string|null $theme
   *   The theme.
   */
  protected function lawRoot(Address $address, array $declaration, array $origins, array $own_origins, array $declared_defaults, array $binding_origins, ?string $theme): array {
    $node = $this->lawNode($declaration, $origins, $binding_origins, $theme);
    $roster = $this->rosterBuilder->build($address, $theme);
    // Depth through the one reader of the entry grammar: an entry the
    // nested read drops is qualified to the root.
    $birth = [];
    $birth_origins = [];
    $nested = StyleGuide::defaultVariants($declaration, TRUE);
    foreach (StyleGuide::defaultVariants($declaration) as $axis => $variant) {
      $birth[$axis] = ['variant' => $variant, 'depth' => isset($nested[$axis]) ? NULL : StyleGuide::DEPTH_ROOT];
      $birth_origins[$axis] = static::originAt($origins, ['default_variants', $axis, 'variant']);
    }
    $scope = (string) $address;
    $defaults = $declared_defaults[$scope] ?? [];
    $default_origins = [];
    foreach ($defaults as $key => $entry) {
      foreach (is_array($entry) ? $entry : [] as $condition => $properties) {
        foreach (is_array($properties) ? $properties : [] as $property => $value) {
          $default_origins[$key][$condition][$property] = static::originAt(
            $own_origins,
            ['defaults', $key, $condition, $property],
          );
        }
      }
    }
    $node['axes'] = $roster->axes();
    $node['birth'] = $birth;
    $node['defaults'] = $defaults;
    $node['origins'] += [
      'axes' => static::originAt($origins, ['variant_axes']),
      'birth' => $birth_origins,
      'defaults' => $default_origins,
    ];
    return $node;
  }

  /**
   * Serves one declaration node's law: its rosters with their origins.
   *
   * The grants and value spaces are the roster builder's one derivation
   * (VocabularyRosterBuilder::vocabulary(), what the roster at the
   * node's address answers); the origins follow the same walk: a
   * property's block is the node's own when the group's authored
   * constraints (expanded over the families) carry the property, else
   * the site's binding, and each fact's origin reads from the matching
   * origin tree.
   *
   * @param array $node
   *   The declaration node (a root, a part, a children section, a
   *   declared generated box).
   * @param array $origins
   *   The node's origin tree.
   * @param array $binding_origins
   *   The site bindings' origin map, expanded over the families.
   * @param string|null $theme
   *   The theme.
   */
  protected function lawNode(array $node, array $origins, array $binding_origins, ?string $theme): array {
    ['grants' => $grants, 'valueSpaces' => $spaces] = $this->rosterBuilder->vocabulary($node, $theme);
    $grant_origins = [];
    $own_blocks = [];
    $own_origins = [];
    $groups = is_array($node['property_groups'] ?? NULL) ? $node['property_groups'] : [];
    $group_origins = is_array($origins['property_groups'] ?? NULL) ? $origins['property_groups'] : [];
    foreach ($grants as $group => $properties) {
      $grant_origins[$group] = static::originsUnder($group_origins[$group] ?? NULL);
      $declared = $groups[$group] ?? NULL;
      $constraints = is_array($declared) && is_array($declared['constraints'] ?? NULL) ? $declared['constraints'] : [];
      $expanded = $this->styleGuide->expandConstraintFamilies($constraints);
      $origin_tree = is_array($group_origins[$group] ?? NULL) ? $group_origins[$group] : [];
      $expanded_origins = $this->styleGuide->expandConstraintFamilies(static::originsMap($origin_tree['constraints'] ?? NULL));
      foreach ($properties as $property) {
        if (array_key_exists($property, $expanded)) {
          $own_blocks[$property] = TRUE;
          $own_origins[$property] = $expanded_origins[$property] ?? NULL;
        }
      }
    }
    $constraint_origins = [];
    foreach ($spaces as $property => $block) {
      $own = isset($own_blocks[$property]);
      $constraint_origins[$property] = [
        'source' => $own ? 'node' : 'site',
        'facts' => static::factOrigins($block, $own ? $own_origins[$property] : ($binding_origins[$property] ?? NULL)),
      ];
    }
    $served = [
      'grants' => $grants,
      'constraints' => $spaces,
      'origins' => ['grants' => $grant_origins, 'constraints' => $constraint_origins],
    ];
    if (is_string($node['label'] ?? NULL)) {
      $served['label'] = $node['label'];
    }
    foreach (StyleGuide::pseudoNodes($node) as $pseudo => $box) {
      $box_origins = is_array($origins['pseudo'][$pseudo] ?? NULL) ? $origins['pseudo'][$pseudo] : [];
      $served['pseudo'][$pseudo] = $this->lawNode($this->rosterBuilder->pseudoNode($node, $pseudo), $box_origins, $binding_origins, $theme);
    }
    if (is_array($node['children'] ?? NULL)) {
      $served['children'] = $this->lawNode($node['children'], is_array($origins['children'] ?? NULL) ? $origins['children'] : [], $binding_origins, $theme);
    }
    if (is_array($node['elements'] ?? NULL)) {
      $served['elements'] = [];
      foreach ($node['elements'] as $selector => $part) {
        if (is_array($part)) {
          $served['elements'][(string) $selector] = $this->lawNode($part, is_array($origins['elements'][$selector] ?? NULL) ? $origins['elements'][$selector] : [], $binding_origins, $theme);
        }
      }
    }
    return $served;
  }

  /**
   * The origin at a path of an origin tree, as a plain map.
   *
   * A leaf met before the path ends covers everything beneath it (a
   * list's entries carry the list's origin; a scalar entry is its own
   * leaf whatever the path expects under it).
   *
   * @return array{provider: string|null, file: string|null}|null
   *   The origin, or NULL when no leaf is recorded there.
   */
  protected static function originAt(mixed $tree, array $path): ?array {
    foreach ($path as $step) {
      if ($tree instanceof DeclarationOrigin) {
        return $tree->toArray();
      }
      if (!is_array($tree) || !array_key_exists($step, $tree)) {
        return NULL;
      }
      $tree = $tree[$step];
    }
    return $tree instanceof DeclarationOrigin ? $tree->toArray() : NULL;
  }

  /**
   * The distinct origins recorded at or beneath a point of an origin tree.
   *
   * @return list<array{provider: string|null, file: string|null}>
   *   In walk order; [] when none is recorded.
   */
  protected static function originsUnder(mixed $tree): array {
    if ($tree instanceof DeclarationOrigin) {
      return [$tree->toArray()];
    }
    $found = [];
    foreach (is_array($tree) ? $tree : [] as $child) {
      foreach (static::originsUnder($child) as $origin) {
        $found[json_encode($origin)] = $origin;
      }
    }
    return array_values($found);
  }

  /**
   * An origin subtree as a map the family expansion can walk, [] when a leaf.
   */
  protected static function originsMap(mixed $tree): array {
    return is_array($tree) ? $tree : [];
  }

  /**
   * The origin of each fact of a constraint block.
   *
   * @param array $block
   *   The effective block.
   * @param mixed $tree
   *   The block's origin tree (fact => origin), or the one origin of a
   *   block written as a leaf, or NULL.
   *
   * @return array<string, array|null>
   *   Fact => origin, in the block's order.
   */
  protected static function factOrigins(array $block, mixed $tree): array {
    $origins = [];
    foreach (array_keys($block) as $fact) {
      $origins[$fact] = static::originAt($tree, [$fact]);
    }
    return $origins;
  }

  /**
   * Serves a root declaration: the roster's answers plus its axes.
   *
   * @param \Drupal\daedalus_blueprint\Materials\Address $address
   *   The root address the declaration is the effective declaration of.
   * @param array $declaration
   *   The effective declaration (the guide's getKind/getBlockType/
   *   getBlockPlugin/getLayout answer).
   * @param string|null $theme
   *   The theme the rosters resolve for (NULL: the builder's default).
   * @param array $axis_labels
   *   The payload-level axis label map, accumulated by reference.
   */
  protected function serveRoot(Address $address, array $declaration, ?string $theme, array &$axis_labels): array {
    $roster = $this->rosterBuilder->build($address, $theme);
    $served = $this->serveNode($declaration, $address, $theme);
    foreach ($roster->axes() as $axis => $facts) {
      if ($facts['label'] !== NULL) {
        $axis_labels += [$axis => $facts['label']];
      }
    }
    return ['grants' => $served['grants'], 'valueSpaces' => $served['valueSpaces'], 'axes' => $roster->axes()] + $served;
  }

  /**
   * Serves one declaration node as its roster's answers.
   *
   * Every node (a root, each part of its elements tree at any depth, each
   * children pseudo-part section, a pattern part, an instance namespace)
   * swaps its authored property_groups and variant_axes for the roster's
   * resolved `grants` and `valueSpaces`: the roster at the node's address
   * when it has one, else (a pattern node, not an address) the builder's
   * node projection, the same derivation. A control binding's element key
   * is served as its normalized candidate list (StyleGuide::
   * controlCandidates()), the one shape the client's resolver takes; a
   * FALSE tombstone rides as-is. A declared generated box (the node's
   * `pseudo` section, StyleGuide::pseudoNodes()) is served as the roster
   * at the box's address (the pseudo appended to the node's selector, the
   * anchor's for a root) or, on a pattern node, through the builder's
   * pseudoNode() projection; a box the node does not declare is never
   * served, the client deriving it from this node (style-guide.js
   * pseudoNode()). Everything else on the node ships as authored, its
   * parts and children served recursively.
   *
   * @param array $node
   *   The declaration node.
   * @param \Drupal\daedalus_blueprint\Materials\Address|null $address
   *   The node's address, or NULL for a pattern node.
   * @param string|null $theme
   *   The theme the rosters resolve for (NULL: the builder's default).
   */
  protected function serveNode(array $node, ?Address $address, ?string $theme): array {
    if ($address !== NULL) {
      $roster = $this->rosterBuilder->build($address, $theme);
      $vocabulary = ['grants' => $roster->grants(), 'valueSpaces' => $roster->valueSpaces()];
    }
    else {
      $vocabulary = $this->rosterBuilder->vocabulary($node, $theme);
    }
    foreach (StyleGuide::pseudoNodes($node) as $pseudo => $box) {
      $node['pseudo'][$pseudo] = $address === NULL
        ? $this->serveNode($this->rosterBuilder->pseudoNode($node, $pseudo), NULL, $theme)
        : $this->serveNode($box, Address::of($address->family, $address->id, ($address->selector ?? RelativeSelector::ANCHOR) . RelativeSelector::SUFFIX . $pseudo), $theme);
    }
    unset($node['property_groups'], $node['variant_axes']);
    if (isset($node['controls']) && is_array($node['controls'])) {
      foreach ($node['controls'] as $name => $control) {
        if (is_array($control)) {
          $node['controls'][$name]['element'] = StyleGuide::controlCandidates($control);
        }
      }
    }
    if (isset($node['children']) && is_array($node['children'])) {
      $node['children'] = $this->serveNode(
        $node['children'],
        $address === NULL ? NULL : Address::of($address->family, $address->id, trim(($address->selector ?? '') . ' > *')),
        $theme,
      );
    }
    if (isset($node['elements']) && is_array($node['elements'])) {
      $root = $address?->root();
      foreach ($node['elements'] as $selector => $part) {
        if (is_array($part)) {
          $selector = (string) $selector;
          // A pattern part is not an address; a concrete part under any
          // depth of the tree is the part address of the root (the roster
          // finds it at any depth, as the client does).
          $part_address = $root !== NULL && preg_match(RelativeSelector::CLASS_PATTERN, $selector)
            ? Address::of($root->family, $root->id, $selector)
            : NULL;
          $node['elements'][$selector] = $this->serveNode($part, $part_address, $theme);
        }
      }
    }
    return $vocabulary + $node;
  }

}
