<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\UnknownDesignSystemException;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\IconResolver;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint_library\Exception\BlueprintDefinitionException;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers blueprints from module-shipped YAML files and content entities.
 *
 * Each file blueprint lives in its own file at the shipping module's root:
 * MODULE.daedalus_blueprint.blueprints.ID.yml (the skills-file convention;
 * the filename suffix is the blueprint ID). The envelope is the document
 * a saved blueprint's host answers, plus its face:
 * @code
 * label: 'Call to Action'
 * description: 'Two-column card: heading with link beside an image.'
 * icon: assets/call_to_action.svg
 * type: section
 * scope: block
 * subject: 'node:page'
 * layout:
 *   - kind: container
 *     layout_id: layout_twocol_section
 *     label: 'Call to Action'
 *     styles:
 *       '':
 *         default: { display: flex }
 *     children:
 *       - kind: region
 *         region: first
 *         children:
 *           - kind: block
 *             block_type: text
 *       - kind: region
 *         region: second
 *         children:
 *           - kind: block
 *             block_type: image
 * styles: {}
 * @endcode
 * `layout` is the spine's lean node tree (LayoutSerializer's shape: a list
 * of root CONTAINER nodes, recursive, a multi-region layout's slots riding
 * region nodes, styles on the nodes; roots are always containers, a block
 * root is an envelope error); `styles` is the node-less remainder of the
 * flat style map (the `entity:` and `field:` keys no layout node carries,
 * what a page-scope blueprint says about the page root), optional and
 * empty for a block-scope blueprint. `type` is the blueprint type (the
 * permission axis and the roster's grouping, BlueprintTypeInterface),
 * optional with ONE spelling of the default: absent means `section`; a
 * named type is a machine name, not checked against the site's types for
 * the same reason the subject is not (a package's files are read before
 * its own install config lands, and the type it names may ship beside
 * them). `scope` says what the material
 * stands in for: `block` (one container placed into a page: exactly one
 * root) or `page` (a whole page's document). `subject` is the kind of host
 * the blueprint is for, `entity_type:bundle`, the pair a fork stamps on
 * the new entity; the file's subject is not checked against the site (a
 * package's files are read before its own install config lands, and a
 * module may ship material for a bundle another module provides).
 *
 * Shipped files declare the sample-media strategy by OMITTING reference
 * fields (media, entity, image, file): their targets are site-local IDs
 * that cannot ship, and absent fields sample-populate at placement. The
 * ship command writes exactly that shape; content blueprints saved on a
 * site preserve their references. The optional icon key names an icon
 * file relative to the shipping module (a graduating content blueprint's
 * icon media is copied to assets/blueprints/ID.<ext> by the ship command
 * and named here); the served definition carries it resolved into the icon
 * value grammar (a root-relative path here, `media:<uuid>` for a content
 * blueprint's media reference, NULL for the no-icon default), so palette
 * consumers read one spelling regardless of source. A file's variant
 * references may be written as bare machine names (the authoring sugar
 * the instantiator also reads); the served `layout` carries them in the
 * tree's reference shape ({variant: id}), the one grammar every reader of
 * a served definition walks (BlueprintLayout::normalize()).
 *
 * A blueprint is a member of a design system, or global. The optional
 * design_system key follows the one disk-lane rule every member kind
 * shares (ActiveDesignSystem::shippedMembership()): the key wins, `~`
 * opts out (global, offered under every system), an absent key is the
 * module default (the design system the shipping module packages, global
 * when it packages none), and a key naming a system that is neither
 * landed nor shipped as install config by an installed module fails
 * discovery loudly. The rule answers from disk: a package's files are
 * read in the window before its own install config lands (a recipe
 * installs the module first and imports its config after), and a
 * shipped-but-pending system is a valid home in that window; the roster
 * hides its members until it activates. The served definition always
 * carries the RESOLVED membership under design_system (NULL for global),
 * from the file rule or a content blueprint's own field, so the materials
 * provider projects one key; the roster, not discovery, partitions on it
 * (an inactive system's blueprint is still resolvable by id, like every
 * material).
 *
 * The AI opt-out follows the same disk grammar as daedalus_stylish's
 * materials: `ai: false` keeps a blueprint out of the AI's roster (the
 * brief and the schema; the palette still places it and a placement still
 * validates), and that is the key's ONLY spelling, since offered is the
 * default every material has and a design-system file never ships the
 * key; `ai: true` is refused rather than growing a second spelling of the
 * default. The served definition carries the RESOLVED flag under
 * `offered` (TRUE or FALSE, from the file key or a content blueprint's own
 * field), so the materials provider projects one key here too.
 *
 * Content blueprints (the `blueprint` entity: the save-as-blueprint lane
 * and the blueprint's own canvas) carry the same face as base fields and
 * their document in core's override field and the style map field, read
 * through the entity's host (HostResolver::fromEntity()): `layout` is
 * NodeTreeBuilder::build() over the SAVED entity, `styles` the node-less
 * remainder of its style map, built once per discovery rebuild. Discovery
 * serves both sources uniformly; a consumer that needs to tell them apart
 * reads the injected source marker (file blueprints are read-only in
 * every UI, content blueprints are the updatable ones). Only published
 * content blueprints are served, but every content blueprint reserves its
 * ID: an unpublished blueprint must not be silently shadowed by a later
 * file.
 *
 * Discovery owns the FILE grammar (which envelope keys exist, their PHP
 * types, container roots) and enforces it loudly here, so a broken shipped
 * file fails at discovery time (the first cache rebuild), not at first
 * placement. Content blueprints enforce the equivalent at entity presave
 * and through the host's own doors. The VALUES inside layout (block
 * types, layouts, element-relative style keys, variant references) belong
 * to the spine: they are validated by the normal deserialization and
 * style-map write paths when a placement instantiates a copy. File
 * definitions carry no UUIDs and never import; the definition is the
 * palette entry. A content definition's nodes carry the saved document's
 * uuids, which the instantiator maps to fresh identity at placement.
 *
 * Blueprint IDs are one flat site-wide vocabulary across BOTH sources,
 * like variant machine names: any collision (file-vs-file, file-vs-content,
 * content-vs-content) is a loud conflict, never a merge. Content presave
 * validates new machine names against the combined vocabulary; discovery
 * catches the other direction, where a module shipping a colliding ID is
 * installed after the content blueprint already exists.
 *
 * The parsed and validated set is cached permanently (the info-file
 * pattern: parse once, read a parsed array afterwards) behind the
 * CACHE_TAG cache tag. CACHE_TAG is the single "the blueprint vocabulary
 * changed" signal for every consumer, including downstream render caches:
 * cache rebuilds and explicit invalidation cover file changes, and the
 * blueprint entity's CRUD hooks (BlueprintCacheHooks) invalidate it for
 * content changes.
 */
class BlueprintDiscovery implements BlueprintDiscoveryInterface {

  protected const CACHE_ID = 'daedalus_blueprint_library:blueprints';

  public const CACHE_TAG = 'daedalus_blueprint_library';

  /**
   * The infix of a per-file blueprint name: MODULE.<infix>.ID.yml.
   *
   * The file grammar's one owner: discovery scans by it and the ship lane
   * writes through fileName(), so the two directions can never disagree on
   * what a blueprint file is called.
   */
  public const FILE_INFIX = 'daedalus_blueprint.blueprints';

  /**
   * The file name a module ships a blueprint under, relative to its root.
   */
  public static function fileName(string $module, string $id): string {
    return sprintf('%s.%s.%s.yml', $module, self::FILE_INFIX, $id);
  }

  /**
   * The keys a blueprint file consists of.
   *
   * The label and description are the palette entry's face (the Add panel
   * and the AI both read them); type is the blueprint type (optional:
   * absent means `section`, the one spelling of the default); scope and
   * subject say what the material
   * stands in for and whom it is for; layout is the document's tree and
   * styles its node-less style keys; icon is the face's optional image, a
   * module-relative file path (e.g. assets/hero.svg) that discovery
   * resolves to a root-relative path in the icon value grammar; ai is the
   * optional AI opt-out (false, the one spelling: absent means offered,
   * the default); design_system is the optional membership (a design
   * system id, or ~ for global; absent means the shipping module's own
   * packaged system); groups is the optional list of group ids the
   * blueprint is filed under (filter categories on an offering surface,
   * the site's group vocabulary, the dialect catalog's groups; absent
   * means no group, the one spelling of the default); favorite is the
   * optional site favorite (true, the one spelling: absent means not a
   * favorite, the default; an offering surface lists a favorite under
   * Favorites for everyone, and each editor keeps their own marks over
   * it); groups and favorite are filter and listing facts alone, read by
   * no operation, fit or placement rule.
   */
  protected const DEFINITION_KEYS = [
    'label',
    'description',
    'icon',
    'ai',
    'design_system',
    'groups',
    'favorite',
    'type',
    'scope',
    'subject',
    'layout',
    'styles',
  ];

  /**
   * The shape of a blueprint type id: a bundle machine name.
   */
  protected const TYPE_PATTERN = '/^[a-z0-9_]{1,32}$/';

  /**
   * The DEFINITION_KEYS a blueprint file cannot omit.
   */
  protected const REQUIRED_KEYS = ['label', 'description', 'scope', 'subject', 'layout'];

  /**
   * The prefixes of a style key a layout node carries (never node-less).
   */
  protected const NODE_KEY_PREFIXES = ['container:', 'block:'];

  /**
   * Constructs the discovery.
   *
   * The node tree builder arrives as a service closure. Lazy because the
   * builder's dependency chain (the layout serializer, the field handler
   * manager, daedalus_stylish's style map handler and validator, the
   * materials roster builder, this module's provider) leads back to this
   * discovery; the content lane only builds trees at a rebuild, so
   * resolving it then breaks the cycle at the one edge that is followed at
   * call time.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected CacheBackendInterface $cache,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleExtensionList $moduleList,
    protected ActiveDesignSystem $activeDesignSystem,
    protected HostResolver $hostResolver,
    protected \Closure $nodeTreeBuilder,
  ) {}

  /**
   * The node tree builder, resolved on first use.
   */
  protected function nodeTreeBuilder(): NodeTreeBuilder {
    return ($this->nodeTreeBuilder)();
  }

  /**
   * {@inheritdoc}
   */
  public function getBlueprints(): array {
    $cached = $this->cache->get(static::CACHE_ID);
    if ($cached) {
      return $cached->data;
    }

    // The types are read once per rebuild: each definition serves its
    // type's frame-only rule beside the type, for both sources alike.
    $frame_only = $this->frameOnlyTypes();
    $blueprints = $this->mergeContentBlueprints($this->fileBlueprints($frame_only), $frame_only);

    // Membership resolves against the landed and shipped systems, so a
    // package's files answer right before its install config lands; the
    // list tag is for a site-built system (no shipped file) a member
    // names explicitly, whose deletion must turn the cached membership
    // into a refusal on the next read. The type list tag: a type's
    // frame-only rule rides every definition of that type.
    $this->cache->set(
      static::CACHE_ID,
      $blueprints,
      CacheBackendInterface::CACHE_PERMANENT,
      [
        static::CACHE_TAG,
        ...$this->activeDesignSystem->packagesCacheTags(),
        ...$this->entityTypeManager->getDefinition(BlueprintTypeInterface::ENTITY_TYPE_ID)->getListCacheTags(),
      ],
    );

    return $blueprints;
  }

  /**
   * The frame-only rule per blueprint type, read once per rebuild.
   *
   * @return array<string, bool>
   *   TRUE by type id where the type's blueprints are the site frame's
   *   material (BlueprintTypeInterface::isFrameOnly()).
   */
  protected function frameOnlyTypes(): array {
    $frame_only = [];
    foreach ($this->entityTypeManager->getStorage(BlueprintTypeInterface::ENTITY_TYPE_ID)->loadMultiple() as $id => $type) {
      assert($type instanceof BlueprintTypeInterface);
      $frame_only[$id] = $type->isFrameOnly();
    }
    return $frame_only;
  }

  /**
   * {@inheritdoc}
   */
  public function getBlueprint(string $id): ?array {
    return $this->getBlueprints()[$id] ?? NULL;
  }

  /**
   * Scans enabled modules for per-file blueprint definitions.
   *
   * @param array<string, bool> $frame_only
   *   The frame-only rule per type (frameOnlyTypes()).
   *
   * @return array
   *   File-source definitions keyed by blueprint ID.
   */
  protected function fileBlueprints(array $frame_only = []): array {
    $blueprints = [];
    foreach ($this->moduleHandler->getModuleDirectories() as $module => $directory) {
      $prefix = "{$module}." . self::FILE_INFIX . '.';
      foreach (glob("{$directory}/{$prefix}*.yml") as $file) {
        $id = substr(basename($file, '.yml'), strlen($prefix));
        if ($id === '') {
          throw new BlueprintDefinitionException($module, $id, [
            new BlueprintError(
              '',
              'INVALID_BLUEPRINT',
              sprintf('The blueprint file "%s" has no blueprint ID.', basename($file)),
              [],
              'Name blueprint files MODULE.daedalus_blueprint.blueprints.ID.yml; the filename suffix is the ID.',
            ),
          ]);
        }
        if (isset($blueprints[$id])) {
          throw new BlueprintDefinitionException($module, $id, [
            new BlueprintError(
              $id,
              'BLUEPRINT_CONFLICT',
              sprintf('The blueprint ID "%s" is already shipped by the "%s" module.', $id, $blueprints[$id]['provider']),
              ['providers' => [$blueprints[$id]['provider'], $module]],
              'Blueprint IDs are site-wide vocabulary; rename one of the blueprints.',
            ),
          ]);
        }
        $definition = Yaml::parseFile($file);
        $this->checkDefinitionShape($module, $id, $definition, $directory);
        // The authored icon is module-relative (files ship inside their
        // module); the catalog carries the icon value grammar, whose path
        // spelling is a finished root-relative URL, so the module prefix is
        // resolved here, once, at discovery time. Every definition carries
        // the key: NULL is the uniform "no icon, render the default" value
        // across both sources.
        $icon = isset($definition['icon'])
          ? '/' . $this->moduleList->getPath($module) . '/' . $definition['icon']
          : NULL;
        $blueprints[$id] = [
          'label' => $definition['label'],
          'description' => $definition['description'],
          'icon' => $icon,
          'design_system' => $this->membership($module, $id, $definition),
          // The resolved flag replaces the disk key: consumers read one
          // `offered` spelling across both sources, like design_system.
          'offered' => ($definition['ai'] ?? TRUE) !== FALSE,
          'scope' => $definition['scope'],
          'subject' => $definition['subject'],
          // A file carries no allow-list: it fits any host that has what
          // its layout needs. One entry shape across both lanes.
          'bundles' => [],
          // The groups are read as authored: shape-checked ids, resolved
          // against the site's vocabulary by the surface that filters.
          'groups' => array_values($definition['groups'] ?? []),
          // The site favorite, resolved like `offered`: the disk key's
          // one spelling is `favorite: true`, absence the default.
          'favorite' => ($definition['favorite'] ?? FALSE) === TRUE,
          // The type, resolved like `offered`: absent means `section`, so
          // consumers read one `type` across both sources.
          'type' => $definition['type'] ?? BlueprintInterface::TYPE_SECTION,
          // The type's frame-only rule, resolved from the type entity once
          // per rebuild: a file naming a type the site lacks reads FALSE.
          'frame_only' => $frame_only[$definition['type'] ?? BlueprintInterface::TYPE_SECTION] ?? FALSE,
          'layout' => BlueprintLayout::normalize($definition['layout']),
          'styles' => $definition['styles'] ?? [],
          'provider' => $module,
          'source' => self::SOURCE_MODULE,
        ];
      }
    }
    return $blueprints;
  }

  /**
   * Merges blueprint content entities into the file-source set.
   *
   * Content definitions are served in the exact shape file definitions
   * are, so consumers never branch on origin except by reading the source
   * marker. A content ID colliding with a file ID errors exactly like
   * file-vs-file: this is the loud half covering a module installed AFTER
   * the content blueprint was saved (presave covers the other direction).
   * Content-vs-content duplicates cannot be saved, but hand-edited or
   * imported rows still fail loudly here rather than one silently winning.
   *
   * The document is read from the SAVED entity through its host: a fresh
   * load carries no staged copy, so an editor's unsaved canvas never
   * reaches a placement. Access-filtered facts of the serializer (an
   * inline block's field visibility) are judged as the rebuilding account
   * sees them, like every cached projection of the roster.
   *
   * @param array $blueprints
   *   The file-source definitions keyed by blueprint ID.
   * @param array<string, bool> $frame_only
   *   The frame-only rule per type (frameOnlyTypes()).
   *
   * @return array
   *   The combined definitions keyed by blueprint ID.
   */
  protected function mergeContentBlueprints(array $blueprints, array $frame_only = []): array {
    $storage = $this->entityTypeManager->getStorage(BlueprintHost::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('id')->execute();
    $taken = $blueprints;
    foreach ($storage->loadMultiple($ids) as $entity) {
      assert($entity instanceof BlueprintInterface);
      $id = $entity->getMachineName();
      if (isset($taken[$id])) {
        $existing = $taken[$id];
        throw new BlueprintDefinitionException(NULL, $id, [
          new BlueprintError(
            $id,
            'BLUEPRINT_CONFLICT',
            sprintf(
              'The blueprint ID "%s" is already %s.',
              $id,
              $existing['source'] === self::SOURCE_MODULE
                ? sprintf('shipped by the "%s" module', $existing['provider'])
                : 'used by another content blueprint',
            ),
            ['providers' => [$existing['provider'], NULL]],
            'Blueprint IDs are one flat site-wide vocabulary across module-shipped files and content blueprints; rename or delete one of the colliding blueprints.',
          ),
        ]);
      }
      // The content lane's icon is a media reference, served in the icon
      // value grammar's media spelling (`media:<uuid>`; consumers resolve it
      // through daedalus_blueprint's IconResolver, which also owns the
      // dangling-media degradation).
      $icon = $entity->getIcon();
      $host = $this->hostResolver->fromEntity($entity);
      $layout = $this->nodeTreeBuilder()->build($host);
      $definition = [
        'label' => (string) $entity->label(),
        'description' => $entity->getDescription(),
        'icon' => $icon !== NULL ? IconResolver::MEDIA_PREFIX . $icon->uuid() : NULL,
        'design_system' => $entity->getDesignSystem(),
        'offered' => $entity->isOffered(),
        'scope' => $entity->getScope(),
        'subject' => $entity->getSubjectType() . ':' . $entity->getSubjectBundle(),
        // The author's allow-list (`entity_type:bundle` entries), a fit
        // requirement beside what the layout needs (BlueprintRequirements).
        'bundles' => $entity->getBundles(),
        'groups' => $entity->getGroups(),
        'favorite' => $entity->isFavorite(),
        'type' => $entity->getType(),
        'frame_only' => $frame_only[$entity->getType()] ?? FALSE,
        'layout' => $layout,
        'styles' => NodeTreeBuilder::nodelessStyles($layout, $host->styleMap()),
        'provider' => NULL,
        'source' => self::SOURCE_CONTENT,
        // The mint gate's provenance (user or ai); file definitions carry
        // none, their origin IS their source.
        'origin' => $entity->getOrigin(),
        // A saved blueprint is a page of its own: the id the page tools
        // address it by (entity_type blueprint). A file has no page.
        'entity_id' => (string) $entity->id(),
      ];
      $taken[$id] = $definition;
      // Unpublished content blueprints reserve their ID (the collision check
      // above must see them, or publishing later would explode) but are not
      // served: the palette must not offer a blueprint its author is still
      // staging.
      if ($entity->isPublished()) {
        $blueprints[$id] = $definition;
      }
    }
    return $blueprints;
  }

  /**
   * Enforces the blueprint-file grammar: the envelope keys and their types.
   *
   * @param string $module
   *   The shipping module.
   * @param string $id
   *   The blueprint ID.
   * @param mixed $definition
   *   The parsed file contents.
   * @param string $directory
   *   The shipping module's directory, for the icon existence check: a
   *   dangling MEDIA icon degrades at render (site content comes and goes),
   *   but a dangling file icon is a developer error in the shipping module,
   *   caught loudly here like every other file-grammar violation.
   */
  protected function checkDefinitionShape(string $module, string $id, mixed $definition, string $directory): void {
    $envelope = 'A blueprint file consists of label, description, scope (block or page), subject (entity_type:bundle) and layout (a list of root container nodes), plus optional styles, icon, ai, design_system, groups, favorite and type keys.';
    // The id is the filename's suffix; the two starts that are not
    // blueprints share the slot a blueprint id is named in, so a file
    // named for one is refused before its envelope is read (the content
    // lane refuses the same ids at presave).
    if (in_array($id, BlueprintInterface::RESERVED_IDS, TRUE)) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('The blueprint ID "%s" is reserved.', $id), ['reserved' => BlueprintInterface::RESERVED_IDS], sprintf('%s name the starts that are not blueprints (an empty page, the bundle\'s template) beside a page blueprint\'s id; name the file MODULE.daedalus_blueprint.blueprints.ID.yml with another ID.', implode(' and ', array_map(static fn (string $reserved): string => "\"$reserved\"", BlueprintInterface::RESERVED_IDS)))),
      ]);
    }
    if (!is_array($definition) || array_is_list($definition)) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file does not contain a blueprint definition.', $id), [], $envelope),
      ]);
    }
    if ($unknown = array_diff(array_keys($definition), self::DEFINITION_KEYS)) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('Unknown key(s) %s in the "%s" blueprint file.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $id), [], $envelope),
      ]);
    }
    if ($missing = array_diff(self::REQUIRED_KEYS, array_keys($definition))) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file is missing the %s key(s).', $id, implode(', ', array_map(static fn ($key) => "\"$key\"", $missing))), [], $envelope),
      ]);
    }
    if (!is_string($definition['label']) || trim($definition['label']) === '' || !is_string($definition['description']) || trim($definition['description']) === '') {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file has the wrong shape.', $id), [], 'label and description are non-empty strings: the palette entry\'s face, read by editors and the AI alike.'),
      ]);
    }
    $scope = $definition['scope'];
    if (!is_string($scope) || !in_array($scope, BlueprintInterface::SCOPES, TRUE)) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file has an invalid scope.', $id), ['scopes' => BlueprintInterface::SCOPES], 'scope is "block" (one container placed into a page) or "page" (a whole page\'s document).'),
      ]);
    }
    $subject = $definition['subject'];
    if (!is_string($subject) || !preg_match('/^[a-z0-9_]+:[a-z0-9_]+$/', $subject)) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file has an invalid subject.', $id), [], 'subject is the kind of host the blueprint is for, as an entity_type:bundle pair, e.g. node:page.'),
      ]);
    }
    $layout = $definition['layout'];
    $tree = 'layout is a non-empty list of root container nodes in the lean tree grammar (kind: container with layout_id and children; a multi-region layout\'s slots ride region nodes; blocks declare kind: block with block_type and block_fields for an inline block, plugin_id and configuration for a plugin block, or field for one of the subject\'s fields). Roots are always containers: capture the container a block sits in.';
    if (!is_array($layout) || $layout === [] || !array_is_list($layout)) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file\'s layout is not a list of root nodes.', $id), [], $tree),
      ]);
    }
    foreach ($layout as $index => $root) {
      if (!DocumentInstantiator::isContainerNode($root)) {
        throw new BlueprintDefinitionException($module, $id, [
          new BlueprintError("$id.layout.$index", 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file\'s layout root %d is not a container node.', $id, $index), [], $tree),
        ]);
      }
    }
    if ($scope === BlueprintInterface::SCOPE_BLOCK && count($layout) !== 1) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError("$id.layout", 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file has scope block and %d layout roots.', $id, count($layout)), [], 'A block-scope blueprint is one container placed into a page: its layout holds exactly one root. A document of several roots is a page-scope blueprint.'),
      ]);
    }
    if (array_key_exists('styles', $definition)) {
      $styles = $definition['styles'];
      if (!is_array($styles) || ($styles !== [] && array_is_list($styles))) {
        throw new BlueprintDefinitionException($module, $id, [
          new BlueprintError("$id.styles", 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file\'s styles is not a map.', $id), [], 'styles maps the node-less style keys (entity: and field: keys and their rule forms, what the document says about the page root) to their entries; a node\'s own styles ride the node in layout.'),
        ]);
      }
      foreach ($styles as $key => $entry) {
        $key = (string) $key;
        foreach (self::NODE_KEY_PREFIXES as $prefix) {
          if (str_starts_with($key, $prefix)) {
            throw new BlueprintDefinitionException($module, $id, [
              new BlueprintError("$id.styles.$key", 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file\'s styles carries the node key "%s".', $id, $key), [], 'A node\'s styles ride the node in layout, keyed element-relative (\'\' for the node itself); the top-level styles holds only the node-less keys.'),
            ]);
          }
        }
        if (!is_array($entry) || $entry === [] || array_is_list($entry)) {
          throw new BlueprintDefinitionException($module, $id, [
            new BlueprintError("$id.styles.$key", 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file\'s styles entry "%s" is not a layered map.', $id, $key), [], 'A styles entry is an optional "variants" list plus condition-keyed property maps, e.g. {"default": {"padding": "2rem"}}.'),
          ]);
        }
      }
    }
    if (array_key_exists('icon', $definition)) {
      $icon = $definition['icon'];
      if (!is_string($icon) || trim($icon) === '' || str_starts_with($icon, '/') || str_contains($icon, '://') || str_contains($icon, '..')) {
        throw new BlueprintDefinitionException($module, $id, [
          new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file has an invalid icon.', $id), [], 'icon is a file path relative to the shipping module, e.g. assets/hero.svg; discovery resolves it to a root-relative path.'),
        ]);
      }
      if (!is_file($directory . '/' . $icon)) {
        throw new BlueprintDefinitionException($module, $id, [
          new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file\'s icon "%s" does not exist in the "%s" module.', $id, $icon, $module), [], 'Ship the icon file at the module-relative path the icon key names.'),
        ]);
      }
    }
    if (array_key_exists('ai', $definition) && $definition['ai'] !== FALSE) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file has an invalid ai key.', $id), [], 'ai, when given, is false: the blueprint is kept out of the AI\'s roster (the palette still places it); omit the key to offer it, the default.'),
      ]);
    }
    if (array_key_exists('design_system', $definition)) {
      $design_system = $definition['design_system'];
      if ($design_system !== NULL && (!is_string($design_system) || trim($design_system) === '')) {
        throw new BlueprintDefinitionException($module, $id, [
          new BlueprintError($id, 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file has an invalid design_system.', $id), [], 'design_system, when given, is a design system id, or ~ (null) for a global blueprint offered under every design system; omit it to join the design system the shipping module packages.'),
        ]);
      }
    }
    if (array_key_exists('groups', $definition)) {
      $groups = $definition['groups'];
      $malformed = !is_array($groups) || $groups === [] || !array_is_list($groups)
        || array_filter($groups, static fn ($group): bool => !is_string($group) || !preg_match(BlueprintInterface::GROUP_ID_PATTERN, $group)) !== []
        || count(array_unique($groups)) !== count($groups);
      if ($malformed) {
        throw new BlueprintDefinitionException($module, $id, [
          new BlueprintError("$id.groups", 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file has an invalid groups list.', $id), [], 'groups, when given, is a non-empty list of distinct group ids (machine names: lowercase letters, digits and underscores), the keys of the site\'s group vocabulary (the site declaration\'s groups map); omit the key for a blueprint in no group.'),
        ]);
      }
    }
    if (array_key_exists('favorite', $definition) && $definition['favorite'] !== TRUE) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError("$id.favorite", 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file has an invalid favorite key.', $id), [], 'favorite, when given, is true: the blueprint is a site favorite, listed under Favorites for everyone where blueprints are offered; omit the key for the default, not a favorite.'),
      ]);
    }
    if (array_key_exists('type', $definition) && (!is_string($definition['type']) || !preg_match(self::TYPE_PATTERN, $definition['type']) || $definition['type'] === BlueprintInterface::TYPE_SECTION)) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError("$id.type", 'INVALID_BLUEPRINT', sprintf('The "%s" blueprint file has an invalid type key.', $id), [], sprintf('type, when given, is the machine name of a blueprint type other than "%s" (e.g. site_chrome for a header or footer); omit the key for the default, a %s blueprint.', BlueprintInterface::TYPE_SECTION, BlueprintInterface::TYPE_SECTION)),
      ]);
    }
  }

  /**
   * A file blueprint's membership through the one disk-lane rule, loudly.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintDefinitionException
   *   BLUEPRINT_DESIGN_SYSTEM_UNKNOWN when the key names a design system
   *   that is neither landed on this site nor shipped as install config
   *   by an installed module (a pending system's members resolve; only an
   *   id nothing lands and nothing ships refuses): a developer error in
   *   the shipping module, caught at discovery like every other
   *   file-grammar violation, never as a blueprint that quietly went
   *   global.
   */
  protected function membership(string $module, string $id, array $definition): ?string {
    try {
      return $this->activeDesignSystem->shippedMembership($module, $definition);
    }
    catch (UnknownDesignSystemException $exception) {
      throw new BlueprintDefinitionException($module, $id, [
        new BlueprintError($id, 'BLUEPRINT_DESIGN_SYSTEM_UNKNOWN', sprintf('The "%s" blueprint file names the design system "%s", which is neither landed on this site nor shipped by an installed module.', $id, $exception->designSystem), ['design_systems' => $exception->known], $exception->known === [] ? 'The site has no design systems, landed or shipped; ship the design system as install config (daedalus_blueprint.design_system.ID.yml) beside the blueprint, or drop the key.' : sprintf('The design systems landed or shipped here: %s. Name one of these, ship the missing one as install config beside the blueprint, or drop the key (the module\'s own shipped design system applies) or set it to ~ (global).', implode(', ', $exception->known))),
      ]);
    }
  }

}
