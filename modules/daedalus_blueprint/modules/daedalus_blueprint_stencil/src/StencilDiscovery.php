<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\UnknownDesignSystemException;
use Drupal\daedalus_blueprint\IconResolver;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint_stencil\Exception\StencilDefinitionException;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers stencils from module-shipped YAML files and content entities.
 *
 * Each file stencil lives in its own file at the shipping module's root:
 * MODULE.daedalus_blueprint.stencils.STENCIL_ID.yml (the skills-file
 * convention; the filename suffix is the stencil ID). The element key
 * holds the root NODE in the spine's lean-tree grammar: usually a
 * container node (kind: container, layout_id, children — recursive; a
 * multi-region layout's slots ride region nodes), or a bare block node.
 * The envelope:
 * @code
 * label: 'Call to Action'
 * description: 'Two-column card: heading with link beside an image.'
 * icon: assets/call_to_action.svg
 * element:
 *   kind: container
 *   layout_id: layout_twocol_section
 *   label: 'Call to Action'
 *   styles:
 *     '':
 *       default: { display: flex }
 *   children:
 *     - kind: region
 *       region: first
 *       children:
 *         - kind: block
 *           block_type: text
 *     - kind: region
 *       region: second
 *       children:
 *         - kind: block
 *           block_type: image
 * @endcode
 * Shipped files declare the sample-media strategy by OMITTING reference
 * fields (media, entity, image, file): their targets are site-local IDs
 * that cannot ship, and absent fields sample-populate at placement. The
 * ship command (drush daedalus-blueprint:stencil-ship) writes exactly that
 * shape; content stencils saved on a site preserve their references. The
 * optional icon key names an icon file relative to the shipping module (a
 * graduating content stencil's icon media is copied to
 * assets/stencils/STENCIL_ID.<ext> by the ship command and named here); the
 * served definition carries it resolved into the icon value grammar (a
 * root-relative path here, `media:<uuid>` for a content stencil's media
 * reference, NULL for the no-icon default), so palette consumers read one
 * spelling regardless of source.
 *
 * A stencil is a member of a design system, or global. The optional
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
 * carries the RESOLVED
 * membership under design_system (NULL for global), from the file rule or
 * a content stencil's own field, so the materials provider projects one
 * key; the roster, not discovery, partitions on it (an inactive system's
 * stencil is still resolvable by id, like every material).
 *
 * The AI opt-out follows the same disk grammar as daedalus_stylish's materials:
 * `ai: false` keeps a stencil out of the AI's roster (the brief and the
 * schema; the palette still places it and a placement still validates),
 * and that is the key's ONLY spelling, since offered is the default every
 * material has and a design-system file never ships the key; `ai: true`
 * is refused rather than growing a second spelling of the default. The
 * served definition carries the RESOLVED flag under `offered` (TRUE or
 * FALSE, from the file key or a content stencil's own field), so the
 * materials provider projects one key here too.
 *
 * Content stencils (the save-as-stencil lane) carry the same face as base
 * fields: label, description, and an element blob in the same grammar as
 * the file's element key. Discovery serves both sources uniformly; a
 * consumer that needs to tell them apart reads the injected source marker
 * (file stencils are read-only in every UI, content stencils are the
 * updatable ones). Only published content stencils are served, but every
 * content stencil reserves its ID: an unpublished stencil must not be
 * silently shadowed by a later file.
 *
 * Discovery owns the FILE grammar (which envelope keys exist, their PHP
 * types) and enforces it loudly here, so a broken shipped file fails at
 * discovery time (the first cache rebuild), not at first placement. Content
 * stencils enforce the equivalent at entity presave. The VALUES inside
 * element (block types, layouts, element-relative style keys, variant
 * references) belong to the spine: they are validated by the normal
 * blueprint deserialization and style-map write paths when the instantiate
 * operation stamps a copy. Stencil definitions carry no UUIDs and never
 * import; the definition is the palette entry.
 *
 * Stencil IDs are one flat site-wide vocabulary across BOTH sources, like
 * variant machine names: any collision (file-vs-file, file-vs-content,
 * content-vs-content) is a loud conflict, never a merge. Content presave
 * validates new machine names against the combined vocabulary; discovery
 * catches the other direction, where a module shipping a colliding ID is
 * installed after the content stencil already exists.
 *
 * The parsed and validated set is cached permanently (the info-file pattern:
 * parse once, read a parsed array afterwards) behind the CACHE_TAG cache
 * tag. CACHE_TAG is the single "the stencil vocabulary changed" signal for
 * every consumer, including downstream render caches: cache rebuilds and
 * explicit invalidation cover file changes, and stencil entity CRUD hooks
 * invalidate it for content changes.
 */
class StencilDiscovery implements StencilDiscoveryInterface {

  protected const CACHE_ID = 'daedalus_blueprint_stencil:stencils';

  public const CACHE_TAG = 'daedalus_blueprint_stencils';

  /**
   * The infix of a per-file stencil name: MODULE.<infix>.STENCIL_ID.yml.
   *
   * The file grammar's one owner: discovery scans by it and the ship lane
   * (StencilShipper) writes through fileName(), so the two directions can
   * never disagree on what a stencil file is called.
   */
  public const FILE_INFIX = 'daedalus_blueprint.stencils';

  /**
   * The file name a module ships a stencil under, relative to its root.
   */
  public static function fileName(string $module, string $id): string {
    return sprintf('%s.%s.%s.yml', $module, self::FILE_INFIX, $id);
  }

  /**
   * The keys a stencil file consists of.
   *
   * The label and description are the palette entry's face (the Add panel
   * and the AI both read them); element is the blueprint element subtree
   * the instantiate operation stamps; icon is the face's optional image, a
   * module-relative file path (e.g. assets/hero.svg) that discovery resolves
   * to a root-relative path in the icon value grammar; ai is the optional
   * AI opt-out (false, the one spelling: absent means offered, the
   * default); design_system is the optional membership (a design system
   * id, or ~ for global; absent means the shipping module's own packaged
   * system).
   */
  protected const DEFINITION_KEYS = ['label', 'description', 'icon', 'ai', 'design_system', 'element'];

  /**
   * The DEFINITION_KEYS a stencil file cannot omit (icon is optional).
   */
  protected const REQUIRED_KEYS = ['label', 'description', 'element'];

  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected CacheBackendInterface $cache,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleExtensionList $moduleList,
    protected ActiveDesignSystem $activeDesignSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getStencils(): array {
    $cached = $this->cache->get(static::CACHE_ID);
    if ($cached) {
      return $cached->data;
    }

    $stencils = $this->mergeContentStencils($this->fileStencils());

    // Membership resolves against the landed and shipped systems, so a
    // package's files answer right before its install config lands; the
    // list tag is for a site-built system (no shipped file) a member
    // names explicitly, whose deletion must turn the cached membership
    // into a refusal on the next read.
    $this->cache->set(
      static::CACHE_ID,
      $stencils,
      CacheBackendInterface::CACHE_PERMANENT,
      [static::CACHE_TAG, ...$this->activeDesignSystem->packagesCacheTags()],
    );

    return $stencils;
  }

  /**
   * {@inheritdoc}
   */
  public function getStencil(string $id): ?array {
    return $this->getStencils()[$id] ?? NULL;
  }

  /**
   * Scans enabled modules for per-file stencil definitions.
   *
   * @return array
   *   File-source definitions keyed by stencil ID.
   */
  protected function fileStencils(): array {
    $stencils = [];
    foreach ($this->moduleHandler->getModuleDirectories() as $module => $directory) {
      $prefix = "{$module}." . self::FILE_INFIX . '.';
      foreach (glob("{$directory}/{$prefix}*.yml") as $file) {
        $id = substr(basename($file, '.yml'), strlen($prefix));
        if ($id === '') {
          throw new StencilDefinitionException($module, $id, [
            new BlueprintError(
              '',
              'INVALID_STENCIL',
              sprintf('The stencil file "%s" has no stencil ID.', basename($file)),
              [],
              'Name stencil files MODULE.daedalus_blueprint.stencils.STENCIL_ID.yml; the filename suffix is the ID.',
            ),
          ]);
        }
        if (isset($stencils[$id])) {
          throw new StencilDefinitionException($module, $id, [
            new BlueprintError(
              $id,
              'STENCIL_CONFLICT',
              sprintf('The stencil ID "%s" is already shipped by the "%s" module.', $id, $stencils[$id]['provider']),
              ['providers' => [$stencils[$id]['provider'], $module]],
              'Stencil IDs are site-wide vocabulary; rename one of the stencils.',
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
        $definition['icon'] = isset($definition['icon'])
          ? '/' . $this->moduleList->getPath($module) . '/' . $definition['icon']
          : NULL;
        $definition['design_system'] = $this->membership($module, $id, $definition);
        // The resolved flag replaces the disk key: consumers read one
        // `offered` spelling across both sources, like design_system.
        $definition['offered'] = ($definition['ai'] ?? TRUE) !== FALSE;
        unset($definition['ai']);
        $stencils[$id] = $definition + [
          'provider' => $module,
          'source' => self::SOURCE_MODULE,
        ];
      }
    }
    return $stencils;
  }

  /**
   * Merges stencil content entities into the file-source set.
   *
   * Content definitions are served in the exact shape file definitions are,
   * so consumers never branch on origin except by reading the source marker.
   * A content ID colliding with a file ID errors exactly like file-vs-file:
   * this is the loud half covering a module installed AFTER the content
   * stencil was saved (presave covers the other direction). Content-vs-
   * content duplicates cannot be saved, but hand-edited or imported rows
   * still fail loudly here rather than one silently winning.
   *
   * @param array $stencils
   *   The file-source definitions keyed by stencil ID.
   *
   * @return array
   *   The combined definitions keyed by stencil ID.
   */
  protected function mergeContentStencils(array $stencils): array {
    $storage = $this->entityTypeManager->getStorage('stencil');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('id')->execute();
    $taken = $stencils;
    foreach ($storage->loadMultiple($ids) as $entity) {
      assert($entity instanceof StencilInterface);
      $id = $entity->getMachineName();
      if (isset($taken[$id])) {
        $existing = $taken[$id];
        throw new StencilDefinitionException(NULL, $id, [
          new BlueprintError(
            $id,
            'STENCIL_CONFLICT',
            sprintf(
              'The stencil ID "%s" is already %s.',
              $id,
              $existing['source'] === self::SOURCE_MODULE
                ? sprintf('shipped by the "%s" module', $existing['provider'])
                : 'used by another content stencil',
            ),
            ['providers' => [$existing['provider'], NULL]],
            'Stencil IDs are one flat site-wide vocabulary across module-shipped files and content stencils; rename or delete one of the colliding stencils.',
          ),
        ]);
      }
      // The content lane's icon is a media reference, served in the icon
      // value grammar's media spelling (`media:<uuid>`; consumers resolve it
      // through daedalus_blueprint's IconResolver, which also owns the
      // dangling-media degradation).
      $icon = $entity->getIcon();
      $definition = [
        'label' => (string) $entity->label(),
        'description' => $entity->getDescription(),
        'icon' => $icon !== NULL ? IconResolver::MEDIA_PREFIX . $icon->uuid() : NULL,
        'design_system' => $entity->getDesignSystem(),
        'offered' => $entity->isOffered(),
        'element' => $entity->getElement(),
        'provider' => NULL,
        'source' => self::SOURCE_CONTENT,
        // The mint gate's provenance (user or ai); file definitions carry
        // none, their origin IS their source.
        'origin' => $entity->getOrigin(),
      ];
      $taken[$id] = $definition;
      // Unpublished content stencils reserve their ID (the collision check
      // above must see them, or publishing later would explode) but are not
      // served: the palette must not offer a stencil its author is still
      // staging.
      if ($entity->isPublished()) {
        $stencils[$id] = $definition;
      }
    }
    return $stencils;
  }

  /**
   * Enforces the stencil-file grammar: the envelope keys and their PHP types.
   *
   * @param string $module
   *   The shipping module.
   * @param string $id
   *   The stencil ID.
   * @param mixed $definition
   *   The parsed file contents.
   * @param string $directory
   *   The shipping module's directory, for the icon existence check: a
   *   dangling MEDIA icon degrades at render (site content comes and goes),
   *   but a dangling file icon is a developer error in the shipping module,
   *   caught loudly here like every other file-grammar violation.
   */
  protected function checkDefinitionShape(string $module, string $id, mixed $definition, string $directory): void {
    if (!is_array($definition) || array_is_list($definition)) {
      throw new StencilDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_STENCIL', sprintf('The "%s" stencil file does not contain a stencil definition.', $id), [], 'A stencil file maps label, description, and element keys.'),
      ]);
    }
    if ($unknown = array_diff(array_keys($definition), self::DEFINITION_KEYS)) {
      throw new StencilDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_STENCIL', sprintf('Unknown key(s) %s in the "%s" stencil file.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $id), [], 'A stencil file consists of label, description, element, and optional icon, ai and design_system keys.'),
      ]);
    }
    if ($missing = array_diff(self::REQUIRED_KEYS, array_keys($definition))) {
      throw new StencilDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_STENCIL', sprintf('The "%s" stencil file is missing the %s key(s).', $id, implode(', ', array_map(static fn ($key) => "\"$key\"", $missing))), [], 'A stencil file consists of label, description, and element.'),
      ]);
    }
    if (!is_string($definition['label']) || trim($definition['label']) === '' || !is_string($definition['description']) || trim($definition['description']) === '' || !is_array($definition['element']) || $definition['element'] === [] || array_is_list($definition['element'])) {
      throw new StencilDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_STENCIL', sprintf('The "%s" stencil file has the wrong shape.', $id), [], 'label and description are non-empty strings; element is the blueprint node tree (a map declaring kind: block with block_type and block_fields, or kind: container with layout_id and children for a section root).'),
      ]);
    }
    if (array_key_exists('icon', $definition)) {
      $icon = $definition['icon'];
      if (!is_string($icon) || trim($icon) === '' || str_starts_with($icon, '/') || str_contains($icon, '://') || str_contains($icon, '..')) {
        throw new StencilDefinitionException($module, $id, [
          new BlueprintError($id, 'INVALID_STENCIL', sprintf('The "%s" stencil file has an invalid icon.', $id), [], 'icon is a file path relative to the shipping module, e.g. assets/hero.svg; discovery resolves it to a root-relative path.'),
        ]);
      }
      if (!is_file($directory . '/' . $icon)) {
        throw new StencilDefinitionException($module, $id, [
          new BlueprintError($id, 'INVALID_STENCIL', sprintf('The "%s" stencil file\'s icon "%s" does not exist in the "%s" module.', $id, $icon, $module), [], 'Ship the icon file at the module-relative path the icon key names.'),
        ]);
      }
    }
    if (array_key_exists('ai', $definition) && $definition['ai'] !== FALSE) {
      throw new StencilDefinitionException($module, $id, [
        new BlueprintError($id, 'INVALID_STENCIL', sprintf('The "%s" stencil file has an invalid ai key.', $id), [], 'ai, when given, is false: the stencil is kept out of the AI\'s roster (the palette still places it); omit the key to offer it, the default.'),
      ]);
    }
    if (array_key_exists('design_system', $definition)) {
      $design_system = $definition['design_system'];
      if ($design_system !== NULL && (!is_string($design_system) || trim($design_system) === '')) {
        throw new StencilDefinitionException($module, $id, [
          new BlueprintError($id, 'INVALID_STENCIL', sprintf('The "%s" stencil file has an invalid design_system.', $id), [], 'design_system, when given, is a design system id, or ~ (null) for a global stencil offered under every design system; omit it to join the design system the shipping module packages.'),
        ]);
      }
    }
  }

  /**
   * A file stencil's membership through the one disk-lane rule, loudly.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilDefinitionException
   *   STENCIL_DESIGN_SYSTEM_UNKNOWN when the key names a design system
   *   that is neither landed on this site nor shipped as install config
   *   by an installed module (a pending system's members resolve; only an
   *   id nothing lands and nothing ships refuses): a developer error in
   *   the shipping module, caught at discovery like every other
   *   file-grammar violation, never as a stencil that quietly went global.
   */
  protected function membership(string $module, string $id, array $definition): ?string {
    try {
      return $this->activeDesignSystem->shippedMembership($module, $definition);
    }
    catch (UnknownDesignSystemException $exception) {
      throw new StencilDefinitionException($module, $id, [
        new BlueprintError($id, 'STENCIL_DESIGN_SYSTEM_UNKNOWN', sprintf('The "%s" stencil file names the design system "%s", which is neither landed on this site nor shipped by an installed module.', $id, $exception->designSystem), ['design_systems' => $exception->known], $exception->known === [] ? 'The site has no design systems, landed or shipped; ship the design system as install config (daedalus_blueprint.design_system.ID.yml) beside the stencil, or drop the key.' : sprintf('The design systems landed or shipped here: %s. Name one of these, ship the missing one as install config beside the stencil, or drop the key (the module\'s own shipped design system applies) or set it to ~ (global).', implode(', ', $exception->known))),
      ]);
    }
  }

}
