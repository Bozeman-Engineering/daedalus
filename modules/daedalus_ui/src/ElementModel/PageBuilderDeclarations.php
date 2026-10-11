<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_ui\Presentation\Presentation;

/**
 * Discovers and layers the page builder declaration file family.
 *
 * The declarations answer one question, "what does this thing render as,
 * and what of it is addressable?", in one canonical format with one
 * discovery and one validation path. Every consumer (the element model, the
 * page-elements tree, canvas attribution, the AI anatomy schema, styling
 * guides) projects from the same layered result instead of keeping a
 * bespoke registry of its own.
 *
 * Four file shapes, all at an extension's root:
 * - PROVIDER.page_builder.yml: universal declarations keyed by element kind
 *   (entity, container, region, field, block).
 * - PROVIDER.page_builder.block_type.BUNDLE.yml: a per-block-type
 *   declaration, refining the universal block kind.
 * - PROVIDER.page_builder.layout.LAYOUT_ID.yml: the same shape for
 *   containers (Layout Builder sections), keyed by the section's layout
 *   plugin id, refining the universal container kind. The layout plugin id
 *   is to containers what the bundle is to inline blocks, and the file
 *   ships next to the layout's template exactly as block type files ship
 *   next to theirs.
 * - PROVIDER.page_builder.block_plugin.PLUGIN_ID.yml: the same shape for
 *   PLUGIN block placements (site branding, menus) whose plugin is not an
 *   inline block, refining the universal block kind. The file keys on the
 *   BASE plugin id (system_menu_block, never system_menu_block:main):
 *   derivative ids carry colons, which filenames cannot, and all
 *   derivatives of a plugin render through one template, so one declaration
 *   honestly covers them; getBlockPlugin() strips the derivative suffix so
 *   every consumer resolves identically. Accepted asymmetry: block type and
 *   layout files ship next to the template owning the markup, but plugin
 *   block templates live in core or the theme, so this file ships in
 *   whichever module or theme owns the override.
 *
 * Daedalus UI owns and validates these sections of a declaration:
 * - elements: the anatomy tree, the markup shell a template puts between an
 *   element's root and its content. A part is keyed by its CSS class
 *   selector and carries a label, an optional contains: slot naming which
 *   of the element's model children mount inside it, and optionally its own
 *   nested elements: map, so a declaration tree is a model of the wrapper
 *   markup. Shell ownership follows template ownership: whoever ships the
 *   template declares its shell (daedalus_ui ships core's field chain
 *   in daedalus_ui.page_builder.yml; a theme whose block.html.twig
 *   emits a content wrapper declares it in THEME.page_builder.yml, and a
 *   theme overriding field.html.twig re-declares the changed parts or
 *   removes one with NULL). A part key ending in "*" (".s-*") is a PATTERN
 *   part declaration: instead of one class it declares a whole class
 *   NAMESPACE, every concrete single-class selector its prefix matches.
 *   The markup for such parts is content, not template shell (rich text
 *   elements wearing minted identity classes), so a pattern part never
 *   renders as a shell row and cannot carry contains:, nested elements,
 *   or any of the markup keys; it exists so consumers of these files
 *   (styling facts, labels) can cover the namespace's instances without
 *   enumerating them (findPatternElement() is the lookup). Beyond label,
 *   contains, and nested elements, a part may declare its MARKUP: `tag`
 *   (a literal tag name, or {field: NAME} when the named field's value
 *   decides it), `present` (the ONE condition under which the part
 *   renders at all, from the closed condition vocabulary below), and
 *   `attributes` (attribute name => literal string or {field: NAME}). A
 *   part whose markup swaps with entity data declares `forms` INSTEAD of
 *   tag (one element, one markup grammar: tag or forms, never both): the
 *   same ordered first-match-wins list of {tag, present, attributes,
 *   label} maps a multi-form root declares, the matched form supplying
 *   the part's tag, attributes, and label (a form's label is its own
 *   name, "Raster" for the image part's img form; the part-level label
 *   is the fallback). Unlike root forms the list need not be exhaustive:
 *   a part none of whose forms match is simply absent. Hoisting is
 *   general: whenever a part's element coincides with its root, the
 *   part's class hoists onto the root and the part key still addresses
 *   that element.
 * - fields: per-field declarations, valid on the kinds that own fields
 *   (entity, block) and on block types, keyed by field machine name.
 *   `element: false` is the EXISTENCE axis: nothing answers to the field's
 *   key in any view, so no row, no canvas element, no styling surface.
 *   `presentation: open|unit` is the developer/theme tier of the
 *   presentation decision, between site-builder third-party settings and
 *   inference.
 * - presentation (entity and block kinds, block types): the node's own
 *   presentation tier, one of open / unit / merged, or a map with a
 *   required mode plus the optional label and edit_target a merged node
 *   presents under (see \Drupal\daedalus_ui\Presentation\Presentation).
 * - tag (any declaration's top level): the element root's markup tag, a
 *   literal tag name or {field: NAME} when a field's value decides it
 *   (the text block's field_text_tag). Mutually exclusive with root:.
 * - root (block types and the container family): the multi-form root, for
 *   elements whose root ELEMENT swaps with instance facts — entity data
 *   (the image block: anchor when linked, img on raster media, svg on
 *   vector media, placeholder div while empty) or, for containers, depth
 *   and emptiness (the shipped container: section at root depth, div
 *   nested). root.forms is the same forms grammar parts may declare, an
 *   ORDERED list of {tag, present, attributes, label} maps resolved
 *   first-match-wins, plus the root's exhaustiveness rule: validation
 *   proves the order total, so no form before the last may be
 *   unconditional (it would shadow every later form), and the last form
 *   must be unconditional or the {empty: true} residual, so some form
 *   always matches (an element always has a root, while a part may be
 *   absent).
 * - composed (block types and the container family): TRUE declares the
 *   type's render composes to ONE root element tree — exactly the tree
 *   this declaration describes — an identity merger (the section key is
 *   never stamped; the composed root carries the hoisted attributes).
 *   Compose makes the declaration GENERATIVE: the shared renderer prints
 *   the resolved tree, no per-type template exists (see
 *   \Drupal\daedalus_ui\Render\ComposedBlockRenderer).
 *
 * The present: condition vocabulary is CLOSED: {field: NAME} (the named
 * field is non-empty), {media: raster|vector} (the element's media kind),
 * {depth: root|nested} (the section's nesting depth; container-family
 * ROOT FORMS only — parts never vary on depth), and {empty: true} (the
 * element has no content: every content field empty, or a section with no
 * children). A part's present map carries ONE condition; a ROOT FORM may
 * compose several, resolved as an AND (the container's empty-at-root
 * form: {depth: root, empty: true}). Which conditions a tree may use
 * follows what its kind's markup can actually vary on
 * (PRESENT_CONDITIONS), so a kind-impossible condition is a build error.
 * Retired spellings fail loud with correctives naming the new one:
 * `collapsed: true` became `composed: true`, and a part's `depth: root`
 * grew into the root-form condition `present: {depth: root}`.
 *
 * Any other key is a FOREIGN SECTION and rides through untouched: other
 * modules contribute their own vocabulary to the SAME files (styling
 * property_groups, constraints, controls, default_variants) and consume it
 * through this discovery, so anatomy and its consumers' concerns co-locate
 * per provider without daedalus_ui depending on any of them.
 *
 * Besides the element kinds, a universal file may carry the reserved
 * top-level `site` key: the declaration of the SITE itself, for vocabulary
 * scoped to the whole site rather than any element (daedalus_stylish's token_scales,
 * say). Everything inside it is foreign sections — daedalus_ui owns no
 * site vocabulary — merged across layers exactly like a kind declaration
 * and served whole by getSite(). A universal file may also carry the
 * reserved top-level `instances` key: the site's INSTANCE NAMESPACES,
 * content-borne identity-class vocabularies (minted rich-text identities)
 * declared once at the spine because carrier ownership is a DOM fact that
 * varies (a composed block's carriers ride its own markup, an open
 * render's ride the field key) while the stylable vocabulary must not.
 * Each entry keys by a pattern selector ('.s-*', PART_PATTERN) and
 * declares `label` (the namespace's human name) plus `tags`, the ONE tag
 * vocabulary truth ({tag: label}): the minter, the CKEditor contract, the
 * annotator's row labels, and every styling resolver read it from here.
 * Foreign sections (property_groups, controls) ride through untouched,
 * exactly as in kind declarations; the shell/markup vocabulary (tag,
 * forms, present, attributes, contains, elements) is impossible there —
 * instances are content, each carrying its own markup. The top-level
 * vocabulary otherwise stays closed (an unknown key is a build error, so
 * a typo'd kind can never silently become a site section); reserved lanes
 * buy theirs by naming them.
 *
 * Layering, lowest to highest: modules that package no design system (in
 * module list order), the ACTIVE design system's package, the active
 * theme's base chain (root base first), the active theme, then
 * hook_page_builder_declarations_alter(). Maps merge recursively; lists and
 * scalars replace; NULL removes a key entirely. Themes participate because
 * anatomy follows templates: a theme overriding a template changes the
 * markup model, so its declarations must be able to override a module's.
 * The universal block kind is the base layer under every per-block-type
 * declaration and the universal container kind under every per-layout
 * declaration, so undeclared bundles and layouts still resolve.
 *
 * The design-system stratum: a module that packages a design system (the
 * `package` of an daedalus_blueprint design_system config entity) ships its
 * law (token scales, bindings, allowlists, axes, birth defaults) beside its
 * skin and materials, and that law is in force only while its system is
 * the site's active one. The active package layers above every ordinary
 * module whatever its list order (so its lists replace the suite's without
 * a module weight) and below the themes (the element-model stratum stays
 * the theme's to finish); an INACTIVE package's files do not layer at all,
 * so two installed systems never merge their law and switching back
 * leaves the other whole. A module is a package from the moment it is
 * INSTALLED, not from the moment its system lands: the shipped set
 * (ActiveDesignSystem::shippedPackages(), the design_system files under
 * each installed module's config/install) counts beside the landed set
 * (packages()), because a recipe installs the module (its files read, the
 * materials lanes synced against the guide) before it imports the system
 * config, and a package's law is authored to layer ABOVE the suite's, so
 * layering it below as a plain module in that window breaks the guide.
 * The built set depends on the activation and on the set of packaged
 * systems, and its cache says so.
 *
 * Origins: beside the merged set the build records, for every merged
 * value, the last layer to write it (getOrigins()): a tree mirroring the
 * set with a DeclarationOrigin (provider and file basename, or the alter
 * origin) at every leaf, a leaf being a scalar, a list, or an empty map.
 * A layer restating a value identically still becomes its origin (it
 * wrote it); an empty map in a higher layer writes nothing, so the lower
 * origins stand; a NULL drops the origin with the key. The alter hook is
 * one layer the module handler runs whole, so its origin is attributed by
 * difference: every leaf the alter added or changed. The origin tree
 * composes through merge() exactly as declarations do, so the effective
 * origins of a block type, plugin, or layout are merge() of the universal
 * kind's tree under the specific one's, the same call the effective
 * getters make. The origins are built and cached with the set.
 *
 * Validation runs after the alter, on the final layered result (that is
 * what must be sound), and the first build with problems throws once,
 * listing all of them: an owned section in a context that cannot honor it,
 * a malformed elements tree (duplicate class, impossible contains token),
 * or an out-of-vocabulary existence/presentation/collapse value would
 * otherwise sit half-honored in the file.
 *
 * @see daedalus_ui.api.php
 */
class PageBuilderDeclarations {

  /**
   * The cache tag every projection of the layered declarations rides.
   *
   * Also the prefix of the per-theme discovery cache ids.
   */
  public const CACHE_TAG = 'daedalus_ui_page_builder';

  /**
   * The element kinds universal declarations may key by.
   */
  public const KINDS = ['entity', 'container', 'region', 'field', 'block'];

  /**
   * The reserved universal-file key declaring the site itself.
   *
   * A non-kind top-level key: site-scope vocabulary (all of it foreign
   * sections) rides here instead of pretending to be an element kind.
   */
  public const SITE = 'site';

  /**
   * The reserved universal-file key declaring the site's instance namespaces.
   *
   * The spine-level home of content-borne identity-class vocabularies:
   * {pattern selector: {label, tags, ...sections other modules contribute}}, merged across
   * layers exactly like the kind declarations (a higher layer refines an
   * entry; NULL withdraws it).
   */
  public const INSTANCES = 'instances';

  /**
   * The condition kinds a present: map may use, per element kind.
   *
   * The vocabulary follows what each kind's markup can actually vary on:
   * entities and blocks render (or not) on their DATA (a non-empty field,
   * having no content at all; blocks additionally on their media kind),
   * while a container's markup varies on its nesting depth and on having
   * no children at all (empty). A kind-impossible condition is a build
   * error, mirroring CONTAINS_TOKENS.
   */
  protected const PRESENT_CONDITIONS = [
    'entity' => ['field', 'empty'],
    'container' => ['depth', 'empty'],
    'region' => [],
    'field' => [],
    'block' => ['field', 'media', 'empty'],
  ];

  /**
   * The values a {depth: ...} condition accepts.
   *
   * `present: {depth: root}` on a ROOT FORM of a container-family tree
   * declares the form the element's root takes at root depth (the shipped
   * container's section/div swap); parts never condition on depth — an
   * element is one box at every depth. Mirrors the variant-axis depth
   * qualifier's vocabulary (daedalus_stylish's {axis: {depth: root}}): both name
   * the same placement fact, that full-bleed/constrained is a root-depth
   * phenomenon.
   */
  protected const DEPTHS = ['root', 'nested'];

  /**
   * The values a {media: ...} condition accepts.
   */
  protected const MEDIA_KINDS = ['raster', 'vector'];

  /**
   * The shape of a literal tag value and of an attribute name.
   */
  protected const MARKUP_NAME = '/^[a-z][a-z0-9-]*$/';

  /**
   * The shape of the field machine name in a {field: NAME} value.
   */
  protected const FIELD_NAME = '/^[a-z0-9_]+$/';

  /**
   * The shape of a pattern part key: a class-selector prefix plus "*".
   *
   * A pattern part (".s-*") declares the whole class namespace its prefix
   * matches. The star is only legal as the final character of an otherwise
   * class-shaped key; any other "*" placement is a grammar error, so a
   * typo can never silently become a pattern.
   */
  public const PART_PATTERN = '/^\.[A-Za-z0-9_-]*\*$/';

  /**
   * The sections daedalus_ui owns per element kind.
   *
   * Only kinds that own fields (entity, block) may declare them, and only
   * those kinds carry a presentation of their own; every context may name
   * its root's tag; block types (see validate()) and the container family
   * (the container kind, layouts) additionally own 'composed' and 'root':
   * both render composed to ONE root element tree, and a container's root
   * swaps on depth and emptiness exactly as a block type's swaps on data.
   * An owned section outside its contexts is a build error, never silently
   * ignored, while every key NOT named here is a foreign section and
   * passes through unvalidated.
   */
  protected const OWNED_SECTIONS = [
    'entity' => ['elements', 'fields', 'presentation', 'tag'],
    'container' => ['elements', 'tag', 'composed', 'root'],
    'region' => ['elements', 'tag'],
    'field' => ['elements', 'tag'],
    'block' => ['elements', 'fields', 'presentation', 'tag'],
  ];

  /**
   * Every section daedalus_ui owns, in any context.
   */
  protected const ALL_OWNED_SECTIONS = ['elements', 'fields', 'presentation', 'tag', 'root', 'composed'];

  /**
   * The contains: slot tokens each element kind's parts may declare.
   *
   * A part's contains: slot names which of the element's model children
   * mount inside it. Generic buckets claim a child kind wholesale and take
   * the residual after specific claims; specific tokens (prefix:NAME) claim
   * one named child. The vocabulary follows what each kind's markup can
   * actually wrap (containers contain their children, blocks contain
   * fields, entities contain fields and sections, a field contains its
   * value), so anything else is a kind-impossible token and a build error.
   * Regions have no contains vocabulary: their children (blocks) always
   * mount at the region root. The entity kind's `sections` bucket is the
   * layout field's section list (daedalus_layout's layout root claims it): one
   * generic bucket with no `section:NAME` form, because an entity has one
   * section list whose members are ordered rows, not named children.
   * The container kind keeps the `regions` bucket vocabulary: a container's
   * model children occupy the region POSITION (one ordered flow through the
   * storage vestige; a multi-region layout's named slots claim theirs by
   * region:NAME), so the slot tokens describe where children mount without
   * regions being addressable elements.
   */
  protected const CONTAINS_TOKENS = [
    'entity' => ['generic' => ['fields', 'sections'], 'specific' => ['field']],
    'container' => ['generic' => ['regions'], 'specific' => ['region']],
    'region' => ['generic' => [], 'specific' => []],
    'field' => ['generic' => ['value'], 'specific' => []],
    'block' => ['generic' => ['fields'], 'specific' => ['field']],
  ];

  /**
   * The shape of a well-formed contains: slot token.
   */
  protected const CONTAINS_TOKEN = '/^(regions|fields|sections|value|(field|region):[a-z0-9_]+)$/';

  /**
   * The presentation modes a node declaration may carry.
   */
  protected const PRESENTATIONS = [Presentation::OPEN, Presentation::UNIT, Presentation::MERGED];

  /**
   * The presentation modes a field declaration may carry.
   *
   * MERGED is a node decision (a container absorbing its content), never a
   * field's own.
   */
  protected const FIELD_PRESENTATIONS = [Presentation::OPEN, Presentation::UNIT];

  /**
   * Built results keyed by theme name: the set and its origins.
   *
   * @var array<string, array{declarations: array, origins: array}>
   */
  protected array $built = [];

  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected ThemeHandlerInterface $themeHandler,
    protected ThemeManagerInterface $themeManager,
    protected CacheBackendInterface $cache,
    protected ActiveDesignSystem $activeDesignSystem,
  ) {}

  /**
   * Gets the merged declaration for an element kind.
   *
   * @return array
   *   The kind's declaration, or [] if nothing is declared.
   */
  public function getKind(string $kind, ?string $theme = NULL): array {
    if (!in_array($kind, self::KINDS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a page builder element kind (expected one of: %s).', $kind, implode(', ', self::KINDS)));
    }
    return $this->getDeclarations($theme)['kinds'][$kind] ?? [];
  }

  /**
   * Gets the merged declaration for a block type.
   *
   * The universal "block" kind declaration is the base layer, so an
   * undeclared bundle resolves to it and a declared bundle refines it.
   *
   * @return array
   *   The effective block type declaration.
   */
  public function getBlockType(string $bundle, ?string $theme = NULL): array {
    $declarations = $this->getDeclarations($theme);
    return static::merge($declarations['kinds']['block'] ?? [], $declarations['block_types'][$bundle] ?? []);
  }

  /**
   * Lists the block types the declarations explicitly cover.
   *
   * @return string[]
   *   Block type bundle machine names.
   */
  public function getBlockTypes(?string $theme = NULL): array {
    return array_keys($this->getDeclarations($theme)['block_types']);
  }

  /**
   * Gets the merged declaration for a plugin block.
   *
   * The universal "block" kind declaration is the base layer, so an
   * undeclared plugin resolves to it and a declared plugin refines it,
   * exactly as getBlockType() serves inline block bundles. The plugin id is
   * to plugin blocks what the bundle is to inline blocks.
   *
   * @param string $plugin_id
   *   A block plugin id. A derivative id (system_menu_block:main) resolves
   *   to its BASE plugin's declaration: all derivatives of a plugin render
   *   through one template, and the file grammar keys on the base id, so
   *   stripping the suffix here keeps every consumer's resolution
   *   identical.
   * @param string|null $theme
   *   The theme whose declarations apply; NULL for the active theme.
   *
   * @return array
   *   The effective block plugin declaration.
   */
  public function getBlockPlugin(string $plugin_id, ?string $theme = NULL): array {
    [$base_id] = explode(':', $plugin_id, 2);
    $declarations = $this->getDeclarations($theme);
    return static::merge($declarations['kinds']['block'] ?? [], $declarations['block_plugins'][$base_id] ?? []);
  }

  /**
   * Lists the block plugins the declarations explicitly cover.
   *
   * @return string[]
   *   Base block plugin ids.
   */
  public function getBlockPlugins(?string $theme = NULL): array {
    return array_keys($this->getDeclarations($theme)['block_plugins']);
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
   *   The theme whose declarations apply; NULL for the active theme.
   *
   * @return array
   *   The effective layout declaration.
   */
  public function getLayout(string $layout_id, ?string $theme = NULL): array {
    $declarations = $this->getDeclarations($theme);
    return static::merge($declarations['kinds']['container'] ?? [], $declarations['layouts'][$layout_id] ?? []);
  }

  /**
   * Lists the layouts the declarations explicitly cover.
   *
   * @return string[]
   *   Layout plugin ids.
   */
  public function getLayouts(?string $theme = NULL): array {
    return array_keys($this->getDeclarations($theme)['layouts']);
  }

  /**
   * Gets the merged site declaration.
   *
   * The site declaration is vocabulary scoped to the whole site rather than
   * any element, authored under the reserved top-level `site` key of
   * universal files. Its content is entirely foreign sections; owners
   * validate their own vocabulary (daedalus_stylish validates token_scales, say).
   *
   * @return array
   *   The layered site declaration, or [] if nothing is declared.
   */
  public function getSite(?string $theme = NULL): array {
    return $this->getDeclarations($theme)[self::SITE] ?? [];
  }

  /**
   * Gets the merged instance-namespace declarations.
   *
   * The site's content-borne identity-class vocabularies, keyed by pattern
   * selector ('.s-*'). Each declaration carries the namespace's label and
   * its `tags` vocabulary ({tag: label}) — the one tag-vocabulary truth
   * minting, row labels, and the styling resolvers all consume — plus any
   * foreign sections riding the same entry (styling property groups,
   * controls).
   *
   * @return array<string, array>
   *   Instance declarations keyed by pattern selector, or [] when nothing
   *   is declared.
   */
  public function getInstances(?string $theme = NULL): array {
    return $this->getDeclarations($theme)[self::INSTANCES] ?? [];
  }

  /**
   * Gets the whole layered declaration set.
   *
   * @return array
   *   ['kinds' => [kind => declaration], 'block_types' => [bundle =>
   *   declaration], 'block_plugins' => [plugin_id => declaration],
   *   'layouts' => [layout_id => declaration], 'site' => declaration,
   *   'instances' => [pattern => declaration]].
   *   Block type, block plugin, and layout declarations here are
   *   as-authored; use getBlockType()/getBlockPlugin()/getLayout() for the
   *   effective declaration with the universal kind layered underneath.
   */
  public function getDefinitions(?string $theme = NULL): array {
    return $this->getDeclarations($theme);
  }

  /**
   * Gets the origins of the whole layered declaration set.
   *
   * A tree keyed exactly as getDefinitions() ('kinds', 'block_types',
   * 'block_plugins', 'layouts', 'site', 'instances'), mirroring the set
   * down to its leaves, where a DeclarationOrigin names the last layer to
   * write the value there (the provider and file, or the alter hook). The
   * set says which nodes are leaves: a scalar, a list, or an empty map has
   * an origin; a populated map has children. Origins here are as-authored,
   * like the set: the effective origins of a block type, block plugin, or
   * layout are merge() of the universal kind's tree under the specific
   * one's, the call getBlockType()/getBlockPlugin()/getLayout() make on
   * the declarations.
   *
   * @return array
   *   The origin tree, built and cached with the declarations.
   */
  public function getOrigins(?string $theme = NULL): array {
    return $this->built($theme)['origins'];
  }

  /**
   * The theme whose declarations speak for the site: the default theme.
   *
   * Off-page consumers — the AI bundle schema and its style guide field
   * schemas, the client style guide payload — must all read the SAME
   * layered declarations regardless of the route they resolve on: schemas
   * are bundle-level and cached, the styling sidebar can render on
   * admin-theme routes, and stored style maps are validated and compiled
   * for the site's front theme. This is that one answer; hand-rolling
   * theme resolution in a consumer forks the guide it reads from the guide
   * writes are checked against. Live renders are the other lane on
   * purpose: the getters' NULL fallback resolves the ACTIVE theme, because
   * what a route renders is that route's theme's business.
   *
   * @return string|null
   *   The site default theme name, or NULL when none is configured (the
   *   getters then fall back to the active theme).
   */
  public function siteTheme(): ?string {
    return $this->themeHandler->getDefault() ?: NULL;
  }

  /**
   * Clears built declarations: the provider set or its order changed.
   *
   * Drops the per-request memo and invalidates the family tag every
   * projection of the declarations rides (the discovery entries, the
   * client anatomy payload, composed renders, the AI schema fragment).
   * Called after installing an extension, when a design system lands or
   * leaves, and when the site switches its active design system.
   */
  public function resetCache(): void {
    $this->built = [];
    Cache::invalidateTags([self::CACHE_TAG]);
  }

  /**
   * Returns the layered declarations for a theme, building if needed.
   */
  protected function getDeclarations(?string $theme): array {
    return $this->built($theme)['declarations'];
  }

  /**
   * Returns the built result for a theme, the set and its origins.
   *
   * The entry depends on the activation (which package layers) and on the
   * set of packaged systems (which modules are packages at all), so it
   * carries both tags beside the family tag: a switch or a landing that
   * happens outside this request still finds the entry gone.
   *
   * @return array{declarations: array, origins: array}
   *   The layered set and the origin tree mirroring it.
   */
  protected function built(?string $theme): array {
    $theme ??= $this->themeManager->getActiveTheme()->getName();
    if (isset($this->built[$theme])) {
      return $this->built[$theme];
    }
    $cid = self::CACHE_TAG . ':' . $theme;
    if ($cached = $this->cache->get($cid)) {
      return $this->built[$theme] = $cached->data;
    }
    $built = $this->build($theme);
    $tags = Cache::mergeTags([self::CACHE_TAG, 'config:' . ActiveDesignSystem::CONFIG_NAME], $this->activeDesignSystem->packagesCacheTags());
    $this->cache->set($cid, $built, Cache::PERMANENT, $tags);
    return $this->built[$theme] = $built;
  }

  /**
   * Builds the declaration set by layering every provider's files.
   *
   * Each file layers through layer() under its own origin, so the origin
   * tree grows beside the set; the alter hook, one layer the module
   * handler runs whole, is attributed afterwards by difference.
   *
   * @return array{declarations: array, origins: array}
   *   The layered set and the origin tree mirroring it.
   */
  protected function build(string $theme): array {
    $declarations = [
      'kinds' => [],
      'block_types' => [],
      'block_plugins' => [],
      'layouts' => [],
      self::SITE => [],
      self::INSTANCES => [],
    ];
    $origins = $declarations;
    foreach ($this->getProviderDirectories($theme) as $provider => $directory) {
      $universal = "$directory/$provider.page_builder.yml";
      if (is_file($universal)) {
        $origin = new DeclarationOrigin($provider, basename($universal));
        foreach (Yaml::decode(file_get_contents($universal)) ?? [] as $kind => $declaration) {
          if ($kind === self::SITE || $kind === self::INSTANCES) {
            // The reserved lanes: the site's own declaration and the
            // instance namespaces, merged across layers like a kind's;
            // NULL withdraws everything lower layers declared (a single
            // namespace withdraws with a NULL entry inside the map).
            if ($declaration === NULL) {
              $declarations[$kind] = [];
              $origins[$kind] = [];
            }
            else {
              static::layer($declarations[$kind], $origins[$kind], $declaration, $origin);
            }
          }
          elseif ($declaration === NULL) {
            unset($declarations['kinds'][$kind], $origins['kinds'][$kind]);
          }
          else {
            $declarations['kinds'][$kind] ??= [];
            $origins['kinds'][$kind] ??= [];
            static::layer($declarations['kinds'][$kind], $origins['kinds'][$kind], $declaration, $origin);
          }
        }
      }
      foreach (glob("$directory/$provider.page_builder.block_type.*.yml") ?: [] as $file) {
        // PROVIDER.page_builder.block_type.BUNDLE.yml: the bundle is the
        // segment between the fixed prefix and the extension.
        $bundle = substr(basename($file, '.yml'), strlen("$provider.page_builder.block_type."));
        $declarations['block_types'][$bundle] ??= [];
        $origins['block_types'][$bundle] ??= [];
        static::layer($declarations['block_types'][$bundle], $origins['block_types'][$bundle], Yaml::decode(file_get_contents($file)) ?? [], new DeclarationOrigin($provider, basename($file)));
      }
      foreach (glob("$directory/$provider.page_builder.block_plugin.*.yml") ?: [] as $file) {
        // PROVIDER.page_builder.block_plugin.PLUGIN_ID.yml: the base plugin
        // id is the segment between the fixed prefix and the extension.
        $plugin_id = substr(basename($file, '.yml'), strlen("$provider.page_builder.block_plugin."));
        $declarations['block_plugins'][$plugin_id] ??= [];
        $origins['block_plugins'][$plugin_id] ??= [];
        static::layer($declarations['block_plugins'][$plugin_id], $origins['block_plugins'][$plugin_id], Yaml::decode(file_get_contents($file)) ?? [], new DeclarationOrigin($provider, basename($file)));
      }
      foreach (glob("$directory/$provider.page_builder.layout.*.yml") ?: [] as $file) {
        // PROVIDER.page_builder.layout.LAYOUT_ID.yml: the layout plugin id
        // is the segment between the fixed prefix and the extension.
        $layout_id = substr(basename($file, '.yml'), strlen("$provider.page_builder.layout."));
        $declarations['layouts'][$layout_id] ??= [];
        $origins['layouts'][$layout_id] ??= [];
        static::layer($declarations['layouts'][$layout_id], $origins['layouts'][$layout_id], Yaml::decode(file_get_contents($file)) ?? [], new DeclarationOrigin($provider, basename($file)));
      }
    }
    $authored = $declarations;
    $this->moduleHandler->alter('page_builder_declarations', $declarations, $theme);
    $origins = static::attribute($authored, $declarations, $origins, DeclarationOrigin::alter());
    // Validation runs after the alter (the final layered result is what
    // must be sound) and the first build with problems throws once,
    // listing all of them.
    $errors = [];
    $this->validate($declarations, $errors);
    if ($errors !== []) {
      throw new DeclarationException(implode("\n", $errors));
    }
    return ['declarations' => $declarations, 'origins' => $origins];
  }

  /**
   * Validates every kind, block type, block plugin, and layout declaration.
   *
   * Block types, block plugins, and layouts validate as their EFFECTIVE
   * declarations (the universal kind layered underneath, the same view
   * getBlockType()/getBlockPlugin()/getLayout() serve), so a cross-layer
   * merge collision fails the same checks as an in-file mistake.
   */
  protected function validate(array $declarations, array &$errors): void {
    // The site declaration's content is foreign (owners validate their own
    // sections), but the declaration itself must be a map — the alter hook
    // could hand back anything.
    if (!is_array($declarations[self::SITE] ?? [])) {
      $errors[] = 'The site declaration must be a map of foreign sections.';
    }
    $this->validateInstances($declarations[self::INSTANCES] ?? [], $errors);
    foreach ($declarations['kinds'] as $kind => $declaration) {
      if (!in_array($kind, self::KINDS, TRUE)) {
        $errors[] = sprintf('"%s" is not a page builder element kind; universal declarations key by one of: %s.', $kind, implode(', ', self::KINDS));
        continue;
      }
      if (!is_array($declaration)) {
        $errors[] = sprintf('The declaration of kind "%s" must be a map.', $kind);
        continue;
      }
      $this->validateDeclaration($declaration, $kind, sprintf('kind "%s"', $kind), self::OWNED_SECTIONS[$kind], $errors);
    }
    foreach ($declarations['block_types'] as $bundle => $declaration) {
      if (!is_array($declaration)) {
        $errors[] = sprintf('The declaration of block type "%s" must be a map.', $bundle);
        continue;
      }
      $effective = static::merge($declarations['kinds']['block'] ?? [], $declaration);
      $this->validateDeclaration($effective, 'block', sprintf('block type "%s"', $bundle), [
        ...self::OWNED_SECTIONS['block'],
        'composed',
        'root',
      ], $errors);
    }
    foreach ($declarations['block_plugins'] as $plugin_id => $declaration) {
      if (!is_array($declaration)) {
        $errors[] = sprintf('The declaration of block plugin "%s" must be a map.', $plugin_id);
        continue;
      }
      if (str_contains((string) $plugin_id, ':')) {
        $errors[] = sprintf('The declaration of block plugin "%s" keys on a derivative id; block plugin declarations key on the BASE plugin id, which covers every derivative.', $plugin_id);
        continue;
      }
      // No 'composed' or 'root' here: composed rendering keys off block
      // types (inline block bundles own their composed markup model), so a
      // block plugin declaring either would sit dead in the file.
      $effective = static::merge($declarations['kinds']['block'] ?? [], $declaration);
      $this->validateDeclaration($effective, 'block', sprintf('block plugin "%s"', $plugin_id), self::OWNED_SECTIONS['block'], $errors);
    }
    foreach ($declarations['layouts'] as $layout_id => $declaration) {
      if (!is_array($declaration)) {
        $errors[] = sprintf('The declaration of layout "%s" must be a map.', $layout_id);
        continue;
      }
      $effective = static::merge($declarations['kinds']['container'] ?? [], $declaration);
      $this->validateDeclaration($effective, 'container', sprintf('layout "%s"', $layout_id), self::OWNED_SECTIONS['container'], $errors);
    }
  }

  /**
   * Validates the instance-namespace declarations, appending errors.
   *
   * Navigation_plus owns the lane's grammar: keys are pattern selectors
   * (PART_PATTERN), `label` is the namespace's human name, and `tags` is
   * the required tag vocabulary ({tag: label}) — the one truth minting,
   * labels, and validation consume, so a namespace without it would be a
   * vocabulary nothing can speak. Instances are CONTENT (each carrier owns
   * its own markup), so the shell/markup vocabulary of elements trees is
   * impossible here, mirroring the pattern-part rules. Every other key is
   * a foreign section and rides through untouched.
   */
  protected function validateInstances(mixed $instances, array &$errors): void {
    if (!is_array($instances)) {
      $errors[] = 'The instances declaration must be a map of instance-namespace declarations keyed by pattern selector (e.g. ".s-*").';
      return;
    }
    foreach ($instances as $pattern => $declaration) {
      $pattern = (string) $pattern;
      if (!preg_match(self::PART_PATTERN, $pattern)) {
        $errors[] = sprintf('The instance namespace "%s" is not a valid pattern key; a namespace keys by a class-selector prefix ending in "*", e.g. ".s-*".', $pattern);
        continue;
      }
      if (!is_array($declaration)) {
        $errors[] = sprintf('The instance namespace "%s" must be a map (label, tags, plus any foreign sections), or NULL to withdraw a lower layer\'s declaration.', $pattern);
        continue;
      }
      if (array_key_exists('label', $declaration) && (!is_string($declaration['label']) || $declaration['label'] === '')) {
        $errors[] = sprintf('The label of instance namespace "%s" must be a non-empty string.', $pattern);
      }
      if (!is_array($declaration['tags'] ?? NULL) || $declaration['tags'] === [] || array_is_list($declaration['tags'])) {
        $errors[] = sprintf('The instance namespace "%s" must declare tags: a non-empty {tag: label} map, the one tag-vocabulary truth its consumers (minting, labels, validation) read.', $pattern);
      }
      else {
        foreach ($declaration['tags'] as $tag => $label) {
          if (!preg_match(self::MARKUP_NAME, (string) $tag)) {
            $errors[] = sprintf('The instance namespace "%s" declares tag %s, which is not a tag name; tags are lowercase literals like "p" or "h2".', $pattern, var_export((string) $tag, TRUE));
          }
          if (!is_string($label) || $label === '') {
            $errors[] = sprintf('The tag "%s" of instance namespace "%s" must carry a non-empty string label.', $tag, $pattern);
          }
        }
      }
      foreach (['tag', 'forms', 'present', 'attributes', 'contains', 'elements'] as $markup_key) {
        if (array_key_exists($markup_key, $declaration)) {
          $errors[] = sprintf('The instance namespace "%s" declares %s, but a namespace\'s instances are CONTENT, each carrying its own markup; the shell vocabulary cannot apply to it.', $pattern, $markup_key);
        }
      }
    }
  }

  /**
   * Validates one declaration's owned sections, appending errors.
   *
   * @param array $declaration
   *   The effective declaration to validate.
   * @param string $kind
   *   The element kind whose contains vocabulary applies: the kind itself
   *   for kind declarations, "block" for block types, "container" for
   *   layouts.
   * @param string $where
   *   Names the declaration in errors, e.g. 'block type "card"'.
   * @param string[] $allowed
   *   The owned sections this context may carry.
   * @param string[] $errors
   *   The error list, appended to.
   */
  protected function validateDeclaration(array $declaration, string $kind, string $where, array $allowed, array &$errors): void {
    // An owned section outside its contexts would sit dead in the file
    // (collapsed on an entity kind, fields on a layout), so it is a grammar
    // error, never silently ignored. Foreign sections (any key not owned at
    // all) pass through untouched for other consumers of the same files.
    foreach (self::ALL_OWNED_SECTIONS as $section) {
      if (array_key_exists($section, $declaration) && !in_array($section, $allowed, TRUE)) {
        $errors[] = sprintf('The %s declaration of %s is not valid there: a %s declaration cannot honor it.', $section, $where, $kind);
      }
    }
    // The retired spelling fails loud, never half-honored: collapse grew
    // into the generative composed model, so the old key is a corrective,
    // not a synonym.
    if (array_key_exists('collapsed', $declaration)) {
      $errors[] = sprintf('The collapsed declaration of %s is retired: a block type whose render composes to ONE root element declares `composed: true` (composed subsumes collapsed — the declared tree IS the markup model).', $where);
    }
    if (in_array('elements', $allowed, TRUE) && array_key_exists('elements', $declaration)) {
      $this->validateDeclarationElements($declaration, $kind, $where, $errors);
    }
    if (in_array('fields', $allowed, TRUE) && array_key_exists('fields', $declaration)) {
      $this->validateFields($declaration['fields'], $where, $errors);
    }
    if (in_array('presentation', $allowed, TRUE) && array_key_exists('presentation', $declaration)) {
      $this->validatePresentation($declaration['presentation'], $where, $errors);
    }
    if (in_array('composed', $allowed, TRUE) && array_key_exists('composed', $declaration) && !is_bool($declaration['composed'])) {
      $errors[] = sprintf('The composed declaration of %s must be a boolean.', $where);
    }
    if (in_array('tag', $allowed, TRUE) && array_key_exists('tag', $declaration)) {
      $this->validateTag($declaration['tag'], sprintf('The tag declaration of %s', $where), $errors);
    }
    if (in_array('root', $allowed, TRUE) && array_key_exists('root', $declaration)) {
      if (array_key_exists('tag', $declaration)) {
        $errors[] = sprintf('The declaration of %s carries both tag and root; one element has one root, so declare tag (one fixed form) or root.forms (data-decided forms), never both.', $where);
      }
      $this->validateRoot($declaration['root'], $kind, $where, $errors);
    }
  }

  /**
   * Validates one tag value, appending errors.
   *
   * A tag is a literal tag name ("h2") or {field: NAME} when the named
   * field's value decides it (the text block's field_text_tag).
   */
  protected function validateTag(mixed $tag, string $at, array &$errors): void {
    if (is_string($tag)) {
      if (!preg_match(self::MARKUP_NAME, $tag)) {
        $errors[] = sprintf('%s is %s, which is not a tag name; a tag is a lowercase literal like "h2", or {field: NAME} when the named field\'s value decides it.', $at, var_export($tag, TRUE));
      }
      return;
    }
    if (!$this->isFieldReference($tag)) {
      $errors[] = sprintf('%s must be a literal tag name ("h2") or {field: NAME} (the named field\'s value decides the tag).', $at);
    }
  }

  /**
   * Whether a value is a well-formed {field: NAME} reference.
   */
  protected function isFieldReference(mixed $value): bool {
    return is_array($value) && array_keys($value) === ['field']
      && is_string($value['field']) && (bool) preg_match(self::FIELD_NAME, $value['field']);
  }

  /**
   * Validates one attributes map, appending errors.
   *
   * A literal value may be the explicit empty string — a bare boolean
   * attribute, the empty container form's data-container-empty — which
   * must be spelled '' on purpose: a valueless YAML key decodes to NULL
   * and stays an error, so a forgotten value can never silently become a
   * boolean attribute.
   */
  protected function validateAttributes(mixed $attributes, string $at, array &$errors): void {
    if (!is_array($attributes) || array_is_list($attributes)) {
      $errors[] = sprintf('%s must be a map of attribute name => value, each value a literal string (\'\' for a bare boolean attribute) or {field: NAME} (the named field\'s value).', $at);
      return;
    }
    foreach ($attributes as $name => $value) {
      if (!preg_match(self::MARKUP_NAME, (string) $name)) {
        $errors[] = sprintf('%s names an attribute %s; attribute names are lowercase like "href" or "data-role".', $at, var_export((string) $name, TRUE));
        continue;
      }
      if (!is_string($value) && !$this->isFieldReference($value)) {
        $errors[] = sprintf('%s gives attribute "%s" an impossible value; values are a literal string (\'\' for a bare boolean attribute) or {field: NAME} (the named field\'s value).', $at, $name);
      }
    }
  }

  /**
   * Validates one present: condition map, appending errors.
   *
   * @param mixed $present
   *   The declared condition map.
   * @param string $kind
   *   The element kind whose condition vocabulary applies.
   * @param string $at
   *   Names the condition in errors.
   * @param bool $root_form
   *   Whether the condition sits on a root form. Root forms may compose
   *   several conditions as an AND, and depth is legal ONLY there: the
   *   element's own root varies by nesting depth, while a part is one box
   *   at every depth.
   * @param string[] $errors
   *   The error list, appended to.
   */
  protected function validatePresent(mixed $present, string $kind, string $at, bool $root_form, array &$errors): void {
    if (!is_array($present) || $present === [] || array_is_list($present)) {
      $errors[] = sprintf('%s must be a map of conditions from the closed vocabulary: {field: NAME} (the named field is non-empty), {media: raster|vector}, {depth: root|nested}, or {empty: true}.', $at);
      return;
    }
    if (count($present) > 1 && !$root_form) {
      $errors[] = sprintf('%s composes %d conditions, but conditions compose (AND) only on root forms; a part declares ONE condition.', $at, count($present));
      return;
    }
    foreach ($present as $condition => $value) {
      $condition = (string) $condition;
      if (!in_array($condition, ['field', 'media', 'depth', 'empty'], TRUE)) {
        $errors[] = sprintf('%s uses "%s", which is not a condition; the closed vocabulary is {field: NAME}, {media: raster|vector}, {depth: root|nested}, and {empty: true}.', $at, $condition);
        continue;
      }
      $possible = self::PRESENT_CONDITIONS[$kind];
      if (!in_array($condition, $possible, TRUE)) {
        $errors[] = sprintf('%s conditions on "%s", which is impossible in a %s tree%s.', $at, $condition, $kind, $possible !== [] ? sprintf(' (a %s may condition on: %s)', $kind, implode(', ', $possible)) : sprintf(' (a %s has nothing to vary on, so its parts are unconditional)', $kind));
        continue;
      }
      switch ($condition) {
        case 'field':
          if (!is_string($value) || !preg_match(self::FIELD_NAME, $value)) {
            $errors[] = sprintf('%s conditions on field %s, which is not a field machine name.', $at, var_export($value, TRUE));
          }
          break;

        case 'media':
          if (!in_array($value, self::MEDIA_KINDS, TRUE)) {
            $errors[] = sprintf('%s conditions on media %s; the media kinds are %s.', $at, var_export($value, TRUE), implode(' and ', self::MEDIA_KINDS));
          }
          break;

        case 'depth':
          if (!in_array($value, self::DEPTHS, TRUE)) {
            $errors[] = sprintf('%s conditions on depth %s; the depths are %s.', $at, var_export($value, TRUE), implode(' and ', self::DEPTHS));
          }
          if (!$root_form) {
            $errors[] = sprintf('%s conditions on depth, but depth is a root-form condition (the element\'s own root varies by nesting depth); a part is one box at every depth and never conditions on it.', $at);
          }
          break;

        case 'empty':
          if ($value !== TRUE) {
            $errors[] = sprintf('%s conditions on empty %s; the only value is true (the element has no content).', $at, var_export($value, TRUE));
          }
          break;
      }
    }
  }

  /**
   * Validates one declaration's root, appending errors.
   *
   * The root is a map with the one key "forms", validated by
   * validateForms() with the root's exhaustiveness rule: an element always
   * has a root, so some form must always match.
   */
  protected function validateRoot(mixed $root, string $kind, string $where, array &$errors): void {
    if (!is_array($root) || array_keys($root) !== ['forms']) {
      $errors[] = sprintf('The root declaration of %s must be a map with the one key "forms": an ordered first-match-wins list of {tag, present, attributes, label} forms.', $where);
      return;
    }
    $this->validateForms($root['forms'], $kind, 'root', $where, TRUE, $errors);
  }

  /**
   * Validates one element's forms list, appending errors.
   *
   * The one multi-form grammar, root and part alike: forms resolve
   * FIRST-MATCH-WINS at render, so a non-last unconditional form is always
   * an error (it would shadow every later form). A root additionally
   * proves the order total: an element always has a root, so a
   * conditional last form could leave no form matching, and the last must
   * be unconditional or the {empty: true} residual. Part forms carry no
   * such rule: a part none of whose forms match is simply absent.
   *
   * @param mixed $forms
   *   The declared forms list.
   * @param string $kind
   *   The element kind whose condition vocabulary applies.
   * @param string $element
   *   Names the forms' owner in errors: "root", or 'part ".x"'.
   * @param string $where
   *   Names the declaration in errors, e.g. 'block type "card"'.
   * @param bool $root
   *   Whether these are ROOT forms: exhaustiveness is proven, conditions
   *   may compose (AND), and depth is legal (see validatePresent()); part
   *   forms get none of that.
   * @param string[] $errors
   *   The error list, appended to.
   */
  protected function validateForms(mixed $forms, string $kind, string $element, string $where, bool $root, array &$errors): void {
    if (!is_array($forms) || !array_is_list($forms) || $forms === []) {
      $errors[] = sprintf('The forms declaration of %s in %s must be a non-empty ordered list of {tag, present, attributes, label} forms.', $element, $where);
      return;
    }
    $last = array_key_last($forms);
    foreach ($forms as $index => $form) {
      $at = sprintf('%s form %d of %s', $element, $index, $where);
      if (!is_array($form) || array_is_list($form)) {
        $errors[] = sprintf('The %s must be a map: a required tag, plus optional present, attributes, and label.', $at);
        continue;
      }
      if ($unknown = array_diff(array_keys($form), ['tag', 'present', 'attributes', 'label'])) {
        $errors[] = sprintf('The %s has unknown key(s) %s; a form carries tag, present, attributes, and label.', $at, implode(', ', array_map(static fn ($key) => var_export((string) $key, TRUE), $unknown)));
      }
      if (!array_key_exists('tag', $form)) {
        $errors[] = sprintf('The %s declares no tag; every form names what the element renders as.', $at);
      }
      else {
        $this->validateTag($form['tag'], sprintf('The tag of %s', $at), $errors);
      }
      if (array_key_exists('attributes', $form)) {
        $this->validateAttributes($form['attributes'], sprintf('The attributes of %s', $at), $errors);
      }
      if (array_key_exists('label', $form) && (!is_string($form['label']) || $form['label'] === '')) {
        $errors[] = sprintf('The label of %s must be a non-empty string, the form\'s own name.', $at);
      }
      $conditional = array_key_exists('present', $form);
      if ($conditional) {
        $this->validatePresent($form['present'], $kind, sprintf('The present condition of %s', $at), $root, $errors);
      }
      if (!$conditional && $index !== $last) {
        $errors[] = sprintf('The %s is unconditional but not last; first-match-wins resolution would never reach the forms after it.', $at);
      }
      if ($root && $index === $last && $conditional && $form['present'] !== ['empty' => TRUE]) {
        $errors[] = sprintf('The %s is the last form but conditional; the forms must be exhaustive, so the last is unconditional or the {empty: true} residual.', $at);
      }
    }
  }

  /**
   * Validates one declaration's fields map, appending errors.
   */
  protected function validateFields(mixed $fields, string $where, array &$errors): void {
    if (!is_array($fields)) {
      $errors[] = sprintf('The fields declaration of %s must be a map of field declarations keyed by field machine name.', $where);
      return;
    }
    foreach ($fields as $name => $field) {
      $at = sprintf('The field "%s" of %s', $name, $where);
      if (!is_array($field)) {
        $errors[] = "$at must be a map (element, presentation, plus any foreign sections), or NULL to withdraw a lower layer's declaration.";
        continue;
      }
      if (array_key_exists('element', $field) && !is_bool($field['element'])) {
        $errors[] = "$at must declare element as a boolean; FALSE means nothing answers to the field's key in any view.";
      }
      if (array_key_exists('presentation', $field) && !in_array($field['presentation'], self::FIELD_PRESENTATIONS, TRUE)) {
        $errors[] = sprintf('%s must declare presentation as one of: %s.', $at, implode(', ', self::FIELD_PRESENTATIONS));
      }
    }
  }

  /**
   * Validates one declaration's own presentation, appending errors.
   *
   * A bare mode string is the shorthand; the map form carries the label and
   * edit_target a merged node presents under, mirroring
   * \Drupal\daedalus_ui\Presentation\Presentation::toArray().
   */
  protected function validatePresentation(mixed $presentation, string $where, array &$errors): void {
    if (is_string($presentation)) {
      if (!in_array($presentation, self::PRESENTATIONS, TRUE)) {
        $errors[] = sprintf('The presentation declaration of %s must be one of: %s.', $where, implode(', ', self::PRESENTATIONS));
      }
      return;
    }
    if (!is_array($presentation) || array_is_list($presentation)) {
      $errors[] = sprintf('The presentation declaration of %s must be a mode string (%s) or a map with a mode plus optional label and edit_target.', $where, implode(', ', self::PRESENTATIONS));
      return;
    }
    if (!in_array($presentation['mode'] ?? NULL, self::PRESENTATIONS, TRUE)) {
      $errors[] = sprintf('The presentation declaration of %s must carry a mode of: %s.', $where, implode(', ', self::PRESENTATIONS));
    }
    foreach (['label', 'edit_target'] as $key) {
      if (array_key_exists($key, $presentation) && !is_string($presentation[$key])) {
        $errors[] = sprintf('The presentation %s of %s must be a string.', $key, $where);
      }
    }
    foreach (array_keys(array_diff_key($presentation, array_flip(['mode', 'label', 'edit_target']))) as $key) {
      $errors[] = sprintf('The presentation declaration of %s has an unknown key "%s" (allowed: mode, label, edit_target).', $where, $key);
    }
  }

  /**
   * Validates one declaration's elements tree, appending errors.
   *
   * Two invariants, both loud because silent tolerance would corrupt the
   * addressing scheme or the composed markup model:
   * - Class uniqueness: sub-element keys stay flat ({key}::.class), so a
   *   class appearing twice in one tree would make the flat key ambiguous.
   *   Duplicates demand a curation rename.
   * - contains: grammar: tokens must be well-formed, possible for the
   *   declaring kind (CONTAINS_TOKENS), each specific child claimed by at
   *   most one slot, and at most one generic bucket per child kind in a
   *   tree (the residual rule needs exactly one place for the rest to go).
   */
  protected function validateDeclarationElements(array $declaration, string $kind, string $where, array &$errors): void {
    $seen = [];
    $generic = [];
    $claimed = [];
    $this->validateElementsTree($declaration, $kind, $where, $seen, $generic, $claimed, $errors);
  }

  /**
   * Walks one elements: level of a declaration tree, recursing into parts.
   *
   * $seen, $generic, and $claimed accumulate across the WHOLE tree; the
   * uniqueness and slot rules are per declaration tree, not per level.
   */
  protected function validateElementsTree(array $declaration, string $kind, string $where, array &$seen, array &$generic, array &$claimed, array &$errors): void {
    $elements = $declaration['elements'] ?? [];
    if (!is_array($elements)) {
      $errors[] = sprintf('The elements declaration of %s must be a map of part declarations keyed by CSS selector.', $where);
      return;
    }
    foreach ($elements as $selector => $part) {
      $selector = (string) $selector;
      if (!is_array($part)) {
        continue;
      }
      if (isset($seen[$selector])) {
        $errors[] = sprintf('The part "%s" of %s is declared more than once in the elements tree; classes must be unique within one declaration tree so the flat "::%s" sub-element key addresses exactly one element.', $selector, $where, $selector);
      }
      $seen[$selector] = TRUE;
      if (str_contains($selector, '*')) {
        // Pattern parts: the star is only legal as the trailing character
        // of a class-shaped key, and the declaration covers CONTENT-borne
        // classes (no template markup of its own), so the shell vocabulary
        // (contains: slots and nested parts) cannot apply to it.
        if (!preg_match(self::PART_PATTERN, $selector)) {
          $errors[] = sprintf('The part "%s" of %s is not a valid pattern part key; a pattern is a class-selector prefix ending in "*", e.g. ".s-*".', $selector, $where);
        }
        else {
          if (array_key_exists('contains', $part)) {
            $errors[] = sprintf('The pattern part "%s" of %s declares a contains slot, but a pattern names a class namespace, not a concrete wrapper; no model child can mount in it.', $selector, $where);
          }
          if (is_array($part['elements'] ?? NULL) && $part['elements'] !== []) {
            $errors[] = sprintf('The pattern part "%s" of %s declares nested elements, but a pattern names a class namespace, not a concrete wrapper; nothing can nest inside it.', $selector, $where);
          }
          foreach (['tag', 'forms', 'present', 'attributes'] as $markup_key) {
            if (array_key_exists($markup_key, $part)) {
              $errors[] = sprintf('The pattern part "%s" of %s declares %s, but a pattern names a class namespace whose instances are CONTENT, each carrying its own markup; no one declaration can describe them.', $selector, $where, $markup_key);
            }
          }
        }
      }
      if (array_key_exists('depth', $part)) {
        // The retired spelling: the placement fact grew into a ROOT-form
        // condition (an element's own root varies by nesting depth); a
        // part is one box at every depth.
        $errors[] = sprintf('The part "%s" of %s declares depth, which is retired; depth is a root-form condition (root.forms with `present: {depth: root}`), and a part never conditions on it.', $selector, $where);
      }
      if (array_key_exists('tag', $part)) {
        $this->validateTag($part['tag'], sprintf('The tag of part "%s" in %s', $selector, $where), $errors);
      }
      if (array_key_exists('forms', $part)) {
        if (array_key_exists('tag', $part)) {
          $errors[] = sprintf('The part "%s" of %s carries both tag and forms; an element has one markup grammar, so declare tag (one fixed form) or forms (data-decided forms), never both.', $selector, $where);
        }
        $this->validateForms($part['forms'], $kind, sprintf('part "%s"', $selector), $where, FALSE, $errors);
      }
      if (array_key_exists('present', $part)) {
        $this->validatePresent($part['present'], $kind, sprintf('The present condition of part "%s" in %s', $selector, $where), FALSE, $errors);
      }
      if (array_key_exists('attributes', $part)) {
        $this->validateAttributes($part['attributes'], sprintf('The attributes of part "%s" in %s', $selector, $where), $errors);
      }
      if (array_key_exists('contains', $part)) {
        $this->validateContainsSlot($part['contains'], $selector, $kind, $where, $generic, $claimed, $errors);
      }
      $this->validateElementsTree($part, $kind, $where, $seen, $generic, $claimed, $errors);
    }
  }

  /**
   * Validates one part's contains: slot, appending errors.
   */
  protected function validateContainsSlot(mixed $contains, string $selector, string $kind, string $where, array &$generic, array &$claimed, array &$errors): void {
    $at = sprintf('The contains slot of part "%s" in %s', $selector, $where);
    if (is_string($contains)) {
      $tokens = [$contains];
    }
    elseif (is_array($contains) && array_is_list($contains) && $contains !== []) {
      $tokens = $contains;
    }
    else {
      $errors[] = "$at must be a slot token or a list of slot tokens (regions, fields, sections, value, field:NAME, or region:NAME).";
      return;
    }
    $vocabulary = self::CONTAINS_TOKENS[$kind];
    $possible = [
      ...$vocabulary['generic'],
      ...array_map(static fn (string $prefix): string => "$prefix:NAME", $vocabulary['specific']),
    ];
    foreach ($tokens as $token) {
      if (!is_string($token) || !preg_match(self::CONTAINS_TOKEN, $token)) {
        $errors[] = sprintf('%s uses %s, which is not a slot token; tokens are regions, fields, sections, value, field:NAME, or region:NAME.', $at, is_scalar($token) ? sprintf('"%s"', $token) : 'a ' . gettype($token));
        continue;
      }
      if (str_contains($token, ':')) {
        [$prefix] = explode(':', $token, 2);
        if (!in_array($prefix, $vocabulary['specific'], TRUE)) {
          $errors[] = sprintf('%s uses "%s", which is impossible in a %s tree%s.', $at, $token, $kind, $possible !== [] ? sprintf(' (a %s may contain: %s)', $kind, implode(', ', $possible)) : sprintf(' (a %s has no contains vocabulary; its children mount at the root)', $kind));
        }
        elseif (isset($claimed[$token])) {
          $errors[] = sprintf('%s claims "%s", which the part "%s" already claims; a child mounts in exactly one slot.', $at, $token, $claimed[$token]);
        }
        else {
          $claimed[$token] = $selector;
        }
      }
      elseif (!in_array($token, $vocabulary['generic'], TRUE)) {
        $errors[] = sprintf('%s uses "%s", which is impossible in a %s tree%s.', $at, $token, $kind, $possible !== [] ? sprintf(' (a %s may contain: %s)', $kind, implode(', ', $possible)) : sprintf(' (a %s has no contains vocabulary; its children mount at the root)', $kind));
      }
      elseif (isset($generic[$token])) {
        $errors[] = sprintf('%s declares a second generic "%s" bucket, but the part "%s" already declares one; a tree gets at most one generic bucket per child kind, because the residual rule needs exactly one place to mount the unclaimed rest.', $at, $token, $generic[$token]);
      }
      else {
        $generic[$token] = $selector;
      }
    }
  }

  /**
   * Finds a part declaration anywhere in a declaration's elements tree.
   *
   * Parts nest, but sub-element keys stay flat ({key}::.class), so every
   * lookup of a part by its class selector searches the whole tree; this is
   * the one shared helper behind those lookups. Build validation guarantees
   * a class is unique within a declaration tree, so the first match is the
   * only match.
   *
   * @param array $declaration
   *   A declaration whose elements: tree to search (a whole kind/block
   *   type/layout declaration, or any part of one).
   * @param string $selector
   *   The part's selector key, e.g. ".card__header".
   *
   * @return array|null
   *   The part declaration, or NULL when no part in the tree carries the
   *   selector.
   */
  public static function findElement(array $declaration, string $selector): ?array {
    $elements = $declaration['elements'] ?? NULL;
    if (!is_array($elements)) {
      return NULL;
    }
    foreach ($elements as $key => $part) {
      if (!is_array($part)) {
        continue;
      }
      if ((string) $key === $selector) {
        return $part;
      }
      if (($found = static::findElement($part, $selector)) !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

  /**
   * Finds the pattern part declaration matching a concrete selector.
   *
   * The namespace companion of findElement(): a pattern part (".s-*")
   * declares every concrete single-class selector its prefix matches, so a
   * selector no part declares exactly can still resolve to the pattern's
   * declaration. Exact lookups stay findElement()'s: callers that honor
   * patterns compose `findElement(...) ?? findPatternElement(...)`, so an
   * exact declaration always wins over a pattern. Depth-first flatten
   * order; the first matching pattern is the match.
   *
   * @param array $declaration
   *   A declaration whose elements: tree to search.
   * @param string $selector
   *   A concrete part selector, e.g. ".s-a3".
   *
   * @return array|null
   *   The pattern part's declaration, or NULL when the selector is not a
   *   single class or no pattern's prefix matches it.
   */
  public static function findPatternElement(array $declaration, string $selector): ?array {
    if (!preg_match('/^\.[A-Za-z0-9_-]+$/', $selector)) {
      return NULL;
    }
    foreach (static::flattenElements($declaration) as $key => $part) {
      $key = (string) $key;
      if (!preg_match(self::PART_PATTERN, $key)) {
        continue;
      }
      $prefix = substr($key, 0, -1);
      // The selector must extend the prefix: the prefix alone is not an
      // instance of the namespace.
      if (str_starts_with($selector, $prefix) && strlen($selector) > strlen($prefix)) {
        return $part;
      }
    }
    return NULL;
  }

  /**
   * Finds the instance-namespace declaration matching a concrete selector.
   *
   * The instances-lane companion of findPatternElement(), over a
   * getInstances() map instead of an elements tree: a namespace ('.s-*')
   * covers every concrete single-class selector extending its prefix.
   * Resolution order everywhere is exact part, then owner pattern part,
   * then the site instance namespaces — callers compose this last, so an
   * owner's own declaration always wins over the site vocabulary.
   *
   * @param array $instances
   *   Instance declarations keyed by pattern selector (getInstances()).
   * @param string $selector
   *   A concrete part selector, e.g. ".s-a3".
   *
   * @return array|null
   *   The namespace's declaration, or NULL when the selector is not a
   *   single class or no namespace's prefix matches it.
   */
  public static function findInstanceDeclaration(array $instances, string $selector): ?array {
    if (!preg_match('/^\.[A-Za-z0-9_-]+$/', $selector)) {
      return NULL;
    }
    foreach ($instances as $pattern => $declaration) {
      $pattern = (string) $pattern;
      if (!is_array($declaration) || !preg_match(self::PART_PATTERN, $pattern)) {
        continue;
      }
      $prefix = substr($pattern, 0, -1);
      // The selector must extend the prefix: the prefix alone is not an
      // instance of the namespace.
      if (str_starts_with($selector, $prefix) && strlen($selector) > strlen($prefix)) {
        return $declaration;
      }
    }
    return NULL;
  }

  /**
   * Flattens a declaration's elements tree into one part-per-selector map.
   *
   * The flat companion of findElement() for consumers that speak lists.
   * Depth-first, parents before children; each part keeps its declaration
   * as authored, nested elements and contains slots included.
   *
   * @return array
   *   Part declarations keyed by selector, every depth of the tree.
   */
  public static function flattenElements(array $declaration): array {
    $flat = [];
    $elements = $declaration['elements'] ?? NULL;
    if (!is_array($elements)) {
      return $flat;
    }
    foreach ($elements as $key => $part) {
      if (!is_array($part)) {
        continue;
      }
      $flat += [(string) $key => $part];
      $flat += static::flattenElements($part);
    }
    return $flat;
  }

  /**
   * Provider directories in layering order.
   *
   * Modules that package no design system (module list order), then the
   * active design system's package, then the theme's base chain, then the
   * theme. A package whose system is not the active one is left out
   * entirely: its law is not in force, so none of its files layer. The
   * packages are the union of the landed systems' packages and the
   * shipped ones (a module whose system file is on disk but not yet
   * imported, or has left, is a dormant package, never a plain module);
   * the landed entry wins for an id both name.
   */
  protected function getProviderDirectories(string $theme): array {
    $directories = $this->moduleHandler->getModuleDirectories();
    $active_id = $this->activeDesignSystem->activeId();
    $active_package = [];
    $packages = $this->activeDesignSystem->packages() + $this->activeDesignSystem->shippedPackages();
    foreach ($packages as $id => $module) {
      if (!isset($directories[$module])) {
        continue;
      }
      if ($id === $active_id) {
        $active_package = [$module => $directories[$module]];
      }
      unset($directories[$module]);
    }
    $directories += $active_package;
    $themes = $this->themeHandler->listInfo();
    $theme_directories = $this->themeHandler->getThemeDirectories();
    $chain = [];
    if (isset($themes[$theme])) {
      // base_themes is ordered root base first; missing bases are NULL.
      foreach (array_keys(array_filter($themes[$theme]->base_themes ?? [])) as $base) {
        $chain[] = $base;
      }
      $chain[] = $theme;
    }
    foreach ($chain as $name) {
      if (isset($theme_directories[$name])) {
        $directories[$name] = $theme_directories[$name];
      }
    }
    return $directories;
  }

  /**
   * Merges a higher-precedence declaration over a lower one.
   *
   * Maps merge recursively, lists and scalars replace, and NULL removes the
   * key, so a theme can refine one part without restating the tree, replace
   * a slot list outright, or withdraw a declaration entirely.
   */
  public static function merge(array $low, array $high): array {
    $origins = [];
    static::layer($low, $origins, $high, NULL);
    return $low;
  }

  /**
   * Layers a higher declaration over a lower one in place, recording origins.
   *
   * The one merge grammar (maps merge recursively, lists and scalars
   * replace, NULL removes), writing every value it lands into $declaration
   * and, when $origin is given, the origin of every leaf it writes into
   * $origins, a tree mirroring $declaration. A leaf written whole (a
   * scalar, a list, an empty map, or a populated map landing where no map
   * was) takes this origin at every leaf it carries; a map merged into a
   * populated map keeps the lower leaves' origins where it writes nothing;
   * a NULL drops the origin with the key. With no origin the tree is left
   * alone, which is merge().
   *
   * @param array $declaration
   *   The lower declaration, layered over in place.
   * @param array $origins
   *   Its origin tree, kept in step in place.
   * @param array $high
   *   The higher declaration.
   * @param \Drupal\daedalus_ui\ElementModel\DeclarationOrigin|null $origin
   *   The higher declaration's origin, or NULL to record none.
   */
  protected static function layer(array &$declaration, array &$origins, array $high, ?DeclarationOrigin $origin): void {
    foreach ($high as $key => $value) {
      $mergeable = is_array($value) && (!array_is_list($value) || $value === [])
        && is_array($declaration[$key] ?? NULL) && (!array_is_list($declaration[$key]) || $declaration[$key] === []);
      if ($value === NULL) {
        unset($declaration[$key], $origins[$key]);
      }
      elseif ($mergeable) {
        if ($value === []) {
          // An empty map in a higher layer writes nothing: the lower map
          // stands, its origins with it.
          continue;
        }
        if ($origin !== NULL && ($declaration[$key] === [] || !is_array($origins[$key] ?? NULL))) {
          // An empty lower map was a leaf carrying one origin; it becomes
          // a node whose children carry theirs.
          $origins[$key] = [];
        }
        $origins[$key] ??= [];
        static::layer($declaration[$key], $origins[$key], $value, $origin);
      }
      else {
        $declaration[$key] = $value;
        if ($origin !== NULL) {
          $origins[$key] = static::stamp($value, $origin);
        }
      }
    }
  }

  /**
   * The origin tree of a value written whole by one layer.
   *
   * @return \Drupal\daedalus_ui\ElementModel\DeclarationOrigin|array
   *   The origin at a leaf (a scalar, a list, or an empty map), or the
   *   tree of a populated map's leaves.
   */
  protected static function stamp(mixed $value, DeclarationOrigin $origin): DeclarationOrigin|array {
    if (!is_array($value) || $value === [] || array_is_list($value)) {
      return $origin;
    }
    return array_map(static fn (mixed $child) => static::stamp($child, $origin), $value);
  }

  /**
   * Attributes what one whole layer changed to it, by difference.
   *
   * Rebuilds the origin tree of $after from $origins (the tree of
   * $before): a leaf $before holds with the same value keeps its origin,
   * every other leaf (added, changed, or a map turned leaf or leaf turned
   * map) takes $origin, and keys $after no longer holds leave the tree.
   * Used for the alter hook, which the module handler runs as one layer
   * over the finished set: a value it restates identically is invisible
   * here, so it keeps its file origin.
   *
   * @return array
   *   The origin tree of $after.
   */
  protected static function attribute(array $before, array $after, array $origins, DeclarationOrigin $origin): array {
    $attributed = [];
    foreach ($after as $key => $value) {
      $node = is_array($value) && $value !== [] && !array_is_list($value);
      $had = array_key_exists($key, $before);
      if ($node && $had && is_array($before[$key]) && $before[$key] !== [] && !array_is_list($before[$key])) {
        $attributed[$key] = static::attribute($before[$key], $value, is_array($origins[$key] ?? NULL) ? $origins[$key] : [], $origin);
      }
      elseif (!$node && $had && $before[$key] === $value && array_key_exists($key, $origins)) {
        // A leaf the alter left alone keeps its origin; so does an empty
        // lane no layer wrote (its entry is [] and stays so).
        $attributed[$key] = $origins[$key];
      }
      else {
        $attributed[$key] = static::stamp($value, $origin);
      }
    }
    return $attributed;
  }

}
