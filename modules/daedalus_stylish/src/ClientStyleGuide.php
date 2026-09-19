<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
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
 * published swatches, the variants, the stencils) are not guide data and
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
    protected MaterialsRosterBuilder $materialsRosterBuilder,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected VariantConformance $variantConformance,
    protected VocabularyRosterBuilder $rosterBuilder,
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
   *     labels cast to plain strings. This is the TYPED catalog the
   *     rosters' grants resolve against: the inspector renders real
   *     controls from the definitions (type, keywords, repeat, negative),
   *     a consumer-appropriate catalog beside the AI's prose. Curated
   *     groups only: a grant keyed by an id absent from this catalog is by
   *     construction a generated wide-tier group, whose granted names
   *     still ride the node and whose values the client lints through
   *     csstree's embedded tables instead.
   *   - conditions: condition name => {label, wrapper, axis, band?,
   *     preference?}, in declaration order ("default" is not listed; the
   *     client knows it comes first). The wrapper carries live thresholds
   *     — it is the same table the server printer dispatches on, so the
   *     client printer can never disagree. The axis is the compound lane
   *     (width/scheme/motion/state): entry keys may compound conditions
   *     across axes ("narrow+dark"), one per axis, and the canonical part
   *     order is the order axes first appear in this table. The structured
   *     facts ride beside the wrapper exactly as the condition type
   *     declares them (ConditionTypeInterface::getConditions()): `band`
   *     {min?, max?} for a canvas-posable width band (the ruler's stops
   *     and the "drive the canvas there" pose), `preference` {feature,
   *     value} for a user preference the canvas emulates. The client
   *     never recovers either from the wrapper text.
   *   - containerRoots: (bool) whether the page-container rule is enabled
   *     (the container_roots kill switch); the client preview must
   *     replicate the backend's page-level width-band query root only when
   *     the server would emit it.
   *   - kinds: the served node per element kind (PageBuilderDeclarations::
   *     KINDS): the roster's answers at "kind:{kind}" (grants,
   *     valueSpaces, axes) plus the merged declaration's other sections
   *     as authored. Every part of its elements tree at any depth is
   *     served as the node at "kind:{kind}::{selector}", and a `children`
   *     section as the node at "{address}::>" (the client resolves the
   *     '::>' children pseudo-part to it, declarationForKey()). An owner
   *     PATTERN part ("{prefix}*", not an address) is served through the
   *     builder's node projection, so it answers exactly what the roster
   *     of any concrete class it covers answers. The authored
   *     property_groups and variant_axes never ride.
   *   - blockTypes: the served node per declared block type, the roster at
   *     "block_type:{bundle}" (the universal block kind layered underneath
   *     by the guide; an undeclared bundle falls back to kinds.block
   *     client-side, which is already the effective node). A composed
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
   *     bindings themselves ride inside the blockTypes declarations as
   *     authored (the guide validator has already vouched for them, a
   *     binding's own section: override included); the client resolves
   *     each binding's widget id against this table and its own component
   *     registry.
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
   *     property => constraint block ({scale, stops, allowed, min, max,
   *     units}). The offer vocabulary's site-wide layer: the client guide
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
   *     to show "from skin" values and shadow badges. Base:{tag} scopes
   *     ride along but no selectable element ever captures them ({} when
   *     no skin is active or it holds no defaults).
   *   - skinEditUrl: the ACTIVE skin's edit-form URL (the DaedalusStylishSkin
   *     `edit-form` link, /admin/appearance/skins/{daedalus_skin}), the
   *     inspector's "Edit skin…" doorway for a skin-supplied value. NULL
   *     when no skin is active or the active one is unpublished: the same
   *     gate as skinDefaults, so the doorway exists exactly when a skin
   *     value can show.
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
    }

    $conditions = [];
    foreach ($this->conditionTypes->getConditions() as $name => $definition) {
      $conditions[$name] = [
        'label' => (string) $definition['label'],
        'wrapper' => $definition['wrapper'],
        'axis' => $definition['axis'],
      ] + array_intersect_key($definition, ['band' => TRUE, 'preference' => TRUE]);
    }

    $axis_labels = [];
    $kinds = [];
    foreach (PageBuilderDeclarations::KINDS as $kind) {
      if ($declaration = $this->styleGuide->getKind($kind, $theme)) {
        $kinds[$kind] = $this->serveRoot(Address::kind($kind), $declaration, $theme, $axis_labels);
      }
    }

    $block_types = [];
    foreach ($this->styleGuide->getBlockTypes($theme) as $bundle) {
      $block_types[$bundle] = $this->serveRoot(Address::blockType($bundle), $this->styleGuide->getBlockType($bundle, $theme), $theme, $axis_labels);
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
    $skin_edit_url = NULL;
    $skin = $this->skinManager->loadActiveSkin();
    if ($skin !== NULL && $skin->isPublished()) {
      $skin_defaults = array_filter($skin->getDefaults(), 'is_array');
      $skin_edit_url = $skin->toUrl('edit-form')->toString();
    }

    // Curated stops meet the minted corpus here for the first time (stops
    // entries are token NAMES — guide code naming skin data — and the guide
    // build deliberately checks only their grammar, so a skin switch can
    // never brick the guide). The client drops un-minted names from the
    // offer; this is the developer-facing half of that honesty, logged once
    // per payload rebuild (the payload caches on skin and declaration tags,
    // so drift-inducing events are exactly what rebuilds it). The minted
    // corpus is the roster's token universe regrouped by scale, the same
    // universe the validator checks references against.
    $tokens = [];
    foreach ($this->materialsRosterBuilder->build()->tokens() as $record) {
      $tokens[$record->group][$record->label] = $record->value;
    }
    $this->warnUnmintedStops([
      'kind' => $kinds,
      'block type' => $block_types,
      'block plugin' => $block_plugins,
      'layout' => $layouts,
      'instance namespace' => $instances,
      'base element' => $base,
    ], $tokens, $theme);

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
      'controlWidgets' => $control_widgets,
      'controlSections' => ControlSection::roster(),
      'constraintDefaults' => $this->styleGuide->constraintDefaults($theme),
      'tokenScales' => $token_scales,
      'swatchKinds' => $swatch_kinds,
      'declaredDefaults' => $this->styleGuide->declaredDefaults($theme),
      'skinDefaults' => $skin_defaults,
      'skinEditUrl' => $skin_edit_url,
      'axisLabels' => $axis_labels,
      'withheld' => $this->variantConformance->refusals(),
    ];
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
   * node projection, the same derivation. Everything else on the node
   * ships as authored, its parts and children served recursively.
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
    unset($node['property_groups'], $node['variant_axes']);
    if (isset($node['children']) && is_array($node['children'])) {
      $node['children'] = $this->serveNode(
        $node['children'],
        $address === NULL ? NULL : Address::of($address->family, $address->id, $address->part, TRUE),
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
          $part_address = $root !== NULL && preg_match('/^(?:' . Address::PART . ')$/', $selector)
            ? Address::of($root->family, $root->id, $selector)
            : NULL;
          $node['elements'][$selector] = $this->serveNode($part, $part_address, $theme);
        }
      }
    }
    return $vocabulary + $node;
  }

  /**
   * Logs curated stops the active skin does not mint.
   *
   * The site's authored constraint_defaults are walked once under their
   * own name; a node whose value space for a property IS that authored
   * default (the roster folds the site layer into every granted
   * property's value space) is not named again for it.
   *
   * @param array $dimensions
   *   The served node maps, keyed by the noun naming their dimension
   *   ('kind' => $kinds, ...).
   * @param array $tokens
   *   The active skin's minted tokens, {scale: {name: value}}.
   * @param string|null $theme
   *   The theme whose authored constraint defaults are the site layer.
   */
  protected function warnUnmintedStops(array $dimensions, array $tokens, ?string $theme): void {
    $drift = [];
    $defaults = $this->styleGuide->getConstraintDefaults($theme);
    $this->collectUnmintedStops(['valueSpaces' => $defaults], "the site's constraint_defaults", $tokens, [], $drift);
    foreach ($dimensions as $noun => $nodes) {
      foreach ($nodes as $name => $node) {
        $this->collectUnmintedStops($node, sprintf('%s "%s"', $noun, $name), $tokens, $defaults, $drift);
      }
    }
    if ($drift !== []) {
      $this->loggerFactory->get('daedalus_stylish')->warning(
        'The style guide curates stops the active skin does not mint; they will not be offered until the skin mints them or the guide stops naming them: @drift.',
        ['@drift' => implode('; ', $drift)],
      );
    }
  }

  /**
   * Collects one served node's un-minted stops, recursing into its parts.
   *
   * Walks the `valueSpaces` map of a served node (the root, each part of
   * its elements tree, each children pseudo-part section) for {scale,
   * stops} blocks whose stop names the minted corpus lacks, skipping a
   * block equal to the site's authored default for the property (named
   * once by the caller). Grammar problems are not this walk's business:
   * the guide build already threw on those, so anything non-list-shaped
   * is skipped.
   */
  protected function collectUnmintedStops(array $node, string $where, array $tokens, array $defaults, array &$drift): void {
    $spaces = $node['valueSpaces'] ?? [];
    foreach (is_array($spaces) ? $spaces : [] as $property => $block) {
      if (!is_array($block) || !is_string($block['scale'] ?? NULL) || !is_array($block['stops'] ?? NULL)) {
        continue;
      }
      if (isset($defaults[$property]) && $defaults[$property] === $block) {
        continue;
      }
      $minted = array_map('strval', array_keys($tokens[$block['scale']] ?? []));
      $unminted = array_filter(
        array_map('strval', array_filter($block['stops'], 'is_scalar')),
        static fn (string $name) => !in_array($name, $minted, TRUE),
      );
      if ($unminted !== []) {
        $drift[] = sprintf('%s of %s ("%s" scale: %s)', $property, $where, $block['scale'], implode(', ', $unminted));
      }
    }
    if (is_array($node['children'] ?? NULL)) {
      $this->collectUnmintedStops($node['children'], sprintf('the children of %s', $where), $tokens, $defaults, $drift);
    }
    foreach (is_array($node['elements'] ?? NULL) ? $node['elements'] : [] as $selector => $part) {
      if (is_array($part)) {
        $this->collectUnmintedStops($part, sprintf('part "%s" of %s', $selector, $where), $tokens, $defaults, $drift);
      }
    }
  }

}
