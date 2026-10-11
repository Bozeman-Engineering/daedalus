<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\NodeTree;

use Drupal\Component\Uuid\Uuid;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\LayoutBuilder\FieldBlock;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Section;

/**
 * Instantiates a document or fragment into a host with fresh identity.
 *
 * The one machinery behind every placement of a whole tree: a blueprint
 * stamped onto a page (place_blueprint), a linked placement detached into
 * the copy it showed (detach_blueprint, the in-place expansion replace()),
 * a page-language document or fragment landing (page_create, insert), and
 * a page's first override seeded from its display's template. Its input
 * is the spine's lean node
 * tree (NodeTreeBuilder's shape: kind-discriminated container, region and
 * block nodes with ONE `children` axis and styles ON the node, keyed by the
 * tree's local remainder); its output is the batch operations that already
 * exist on the spine, run as a nested dressed batch, so an instantiation
 * IS a normal placement scripted by a tree:
 * - a CONTAINER node becomes add_section at its location (born as its
 *   layout_id, carrying its label and layout_settings; a caller may name
 *   the layout containers without one take),
 * - a BLOCK node becomes add_element placed 'inside' its parent: an inline
 *   block by block_type with its block_fields plus its managed configuration
 *   keys as the property bag (form-only stubs dropped, the op sorting the
 *   bag into fields and configuration) and its render view_mode, a plugin
 *   block by plugin_id with its label and its own configuration keys as the
 *   bag T3 gave plugin blocks (a view, a menu), a field block by the FIELD
 *   it names, expanded here to core's derivative for the destination
 *   host's subject (FieldBlock::pluginId over subject()'s entity type and
 *   bundle; a field the subject cannot place is refused FIELD_NOT_ON_HOST
 *   with the subject's placeable fields as the corrective) with the
 *   formatter's own keys as its bag; label_rendered is the one spelling
 *   for a shown label (core's label_display stays inside storage),
 * - a BLUEPRINT node (the page language's <pb-blueprint>) becomes an
 *   place_blueprint op, which comes back through here,
 * - REGION nodes group a multi-region layout's named slots; their children
 *   land with the region name in the location,
 * - every co-located styles entry becomes update_style or apply_variant
 *   against the absolute selectable key minted for its node: the layered
 *   entries travel, variant references and overrides alike. Structured
 *   references ({variant, fragment, via}) normalize to authored root
 *   wearings: a non-empty fragment or an origin ("via") is DERIVED data a
 *   composition root's own apply re-creates, and drops,
 * - a whole document's node-less entries (what the source document said
 *   about ITS root: entity: and field: keys and their rule forms, the
 *   page background, the gap between its sections) become the same style
 *   operations against this host's root (instantiateDocument()), so a
 *   document copied host to host arrives whole.
 * Style operations trail the ENTIRE structural expansion: a composition
 * variant's apply resolves its slot paths over the subtree once, at the
 * mutation moment (bind-once), so the whole tree must exist first.
 *
 * Fresh identity is the rule: every node's uuid is minted here, through
 * the caller's minting strategy (random by default; a derived uuid for a
 * seed, so a page reads the same seed until it stages; the node's own uuid
 * for a document whose author already minted it), and the source uuid a
 * node carried maps to the minted one in the expansion's uuid map. Styles
 * re-key onto the minted identity by construction (the tree keys them by
 * local remainder, the expansion prefixes the minted key). Value blocks
 * (a template's inline blocks stored as values) become unsaved blocks on a
 * content host because add_element creates one from the bag; on a
 * valueBlocks host the same op stores values.
 *
 * Because the expansion only speaks the existing grammar, everything the
 * spine enforces (block type validity, placement restrictions, field
 * validation, style-guide validation, variant scope matching) applies with
 * no new validation surface, and batch rollback makes a failed
 * instantiation leave the host untouched. The batch is dressed: a tree is
 * a total statement, every element wears exactly what it says, and birth
 * subscribers stamp no defaults under it.
 *
 * This class owns the tree GRAMMAR (node shapes, key vocabulary, relative
 * style keys) and validates the whole tree before executing anything; the
 * VALUES flow through the spine untouched. The blueprint definition grammar
 * IS this grammar minus per-placement facts (no uuids), so any node-tree
 * subtree is a blueprint.
 *
 * The grammar spells everything a read emits, so a tree read from one host
 * instantiates onto another without editing. What a read carries that no
 * write can mean is ignored, never refused: a delivery annotation (any key
 * beginning with an underscore: the annotator's `_element`, the capability
 * stamp VerdictStamper::KEY), storage residue (block_revision_id,
 * section_index), and blueprint provenance BELOW a root node (provenance is
 * a birth fact of the placement being made, so only a root's stamp
 * travels: a nested placement's stamp names a placement this expansion is
 * not making).
 */
class DocumentInstantiator {

  /**
   * The default code of a tree grammar error.
   */
  public const GRAMMAR_CODE = 'INVALID_NODE';

  /**
   * The keys a container node consists of.
   */
  protected const CONTAINER_KEYS = [
    'kind', 'uuid', 'layout_id', 'label', 'layout_settings', 'blueprint', 'slot', 'children', 'styles',
  ];

  /**
   * The keys a region node consists of.
   */
  protected const REGION_KEYS = ['kind', 'region', 'children'];

  /**
   * The keys a block node consists of.
   */
  protected const BLOCK_KEYS = [
    'kind', 'uuid', 'block_type', 'block_fields', 'configuration', 'plugin_id',
    'field', 'label', 'label_rendered', 'view_mode', 'blueprint', 'styles',
  ];

  /**
   * The keys a blueprint reference node consists of.
   */
  protected const BLUEPRINT_KEYS = ['kind', 'uuid', 'name'];

  /**
   * The keys a linked node consists of.
   */
  protected const LINKED_KEYS = ['kind', 'uuid', LinkedBlueprintBlock::BLUEPRINT_KEY, 'styles'];

  /**
   * Storage residue a read carries that no write can mean.
   *
   * Ignored on every node, beside the delivery annotations (keys beginning
   * with an underscore) and a non-root node's blueprint provenance.
   */
  protected const READ_ONLY_KEYS = ['block_revision_id', 'section_index'];

  /**
   * The corrective for a node outside the tree grammar.
   */
  protected const TREE_GRAMMAR_HINT = 'Tree nodes declare their kind: {kind: container, layout_id, children: [...]} with {kind: region, region, children} nodes for multi-region layouts, {kind: block, block_type, block_fields} for an inline block, {kind: block, plugin_id, configuration} for a plugin block, {kind: block, field, configuration} for one of the page\'s fields, and {kind: linked, blueprint} for a blueprint shown by reference.';

  /**
   * Constructs the instantiator.
   *
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator (the default minting strategy).
   * @param \Closure $operations
   *   Service closure resolving daedalus_blueprint.operations. A closure
   *   because the dependency is circular by construction: operations owns
   *   the handlers that call back into this class.
   * @param \Closure $hostResolver
   *   Service closure resolving daedalus_blueprint.host_resolver, which
   *   hands this instantiator to every content host it builds.
   * @param \Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder $treeBuilder
   *   The lean node tree builder: a template's document as a tree.
   * @param \Drupal\Core\Block\BlockManagerInterface $blockManager
   *   The block plugin manager: the field block derivatives a subject can
   *   place are the ones core derived for its bundle.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter $layoutAdapter
   *   The daedalus_layout adapter: the root list of a storage and the
   *   container component a section is embedded by, for the merges.
   * @param \Drupal\Core\Layout\LayoutPluginManagerInterface|null $layoutManager
   *   The layout plugin manager, when layout plugins are available.
   */
  public function __construct(
    protected UuidInterface $uuid,
    protected \Closure $operations,
    protected \Closure $hostResolver,
    protected NodeTreeBuilder $treeBuilder,
    protected BlockManagerInterface $blockManager,
    protected DaedalusLayoutAdapter $layoutAdapter,
    protected ?LayoutPluginManagerInterface $layoutManager = NULL,
  ) {}

  /**
   * Instantiates a tree into a host: expands it and runs the batch.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the copy lands on.
   * @param array $nodes
   *   The root nodes, in order (one for a fragment, the top-level
   *   containers for a document).
   * @param array $location
   *   The location the first root lands at ({anchor, placement, region?});
   *   each following root lands after the previous one.
   * @param array $options
   *   The expansion options (see expand()), plus:
   *   - root_path: the path prefix of the FIRST root operation's own errors
   *     (its location refusals). Defaults to the root node's path; a caller
   *     whose own parameter space the location belongs to passes ''.
   *   - batch: options forwarded to the nested batch (dressed is set here).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The batch result, its errors re-rooted onto the tree's coordinates.
   */
  public function instantiate(HostInterface $host, array $nodes, array $location, array $options = []): BlueprintResult {
    $expansion = $this->expand($host, $nodes, $location, $options);
    if (!$expansion->isValid()) {
      return BlueprintResult::structuralFailure($expansion->errors);
    }
    return $this->run($host, $expansion->operations, $expansion->paths, $options);
  }

  /**
   * Instantiates a whole document into a host: its roots and its root styles.
   *
   * The whole-document copy, the one road for every host-to-host move of
   * a document: a page's first override seeded from its template (seed()),
   * a page captured into a saved blueprint, a page started from one. The
   * roots land inside the host's own root, in order, and the document's
   * node-less style entries (what the source said about ITS root: the
   * entity:{uuid} and field:{uuid}:{name} keys and their rule forms,
   * whatever root uuid they carried, none at all for a shipped file) land
   * re-addressed to this host's root through the same style operations
   * every node's styles ride, in the same dressed batch: one grammar, one
   * validation path, one rollback. A node-less key of a node kind (a
   * stale container: or block: key a read keeps) names an element the
   * document does not have and is ignored, as every read-only residue is.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the document lands on.
   * @param array $nodes
   *   The root nodes, in order.
   * @param array $styles
   *   The source document's node-less style entries
   *   (NodeTreeBuilder::nodelessStyles()), keyed as the source stored them.
   * @param array $options
   *   The instantiate() options: mint, provenance, sample_fields, path,
   *   indexed, grammar_code, batch.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The batch result, its errors re-rooted onto the document's
   *   coordinates (the roots under path, the root styles under styles).
   */
  public function instantiateDocument(HostInterface $host, array $nodes, array $styles, array $options = []): BlueprintResult {
    return $this->instantiate($host, $nodes, ['anchor' => $host->uuid(), 'placement' => 'inside'], ['styles' => $styles] + $options);
  }

  /**
   * Instantiates a tree in the place of one element, which goes.
   *
   * The in-place expansion, the one road for a node that stands for a
   * document to become that document: a linked blueprint placement
   * detached into the copy it showed (detach_blueprint). The roots land
   * AFTER the element, in its own flow (the same section and region, so
   * the copy takes the element's place among its siblings), with fresh
   * identity and whatever provenance the caller stamps, and the element
   * is removed last, after the style operations, so every op of the
   * expansion runs against the document as it stood with the element
   * still in it (the first root's anchor is the element itself). One
   * dressed batch, one rollback: a tree that does not instantiate leaves
   * the element where it was.
   *
   * The element's own style entries are not carried onto the copy: they
   * are the placement's box (a linked node's block key), and the copy's
   * root is the source document's own root with the source's styles. The
   * map keeps the old key as residue, by the serializer's law.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose document holds the element.
   * @param string $uuid
   *   The element the tree replaces.
   * @param array $nodes
   *   The root nodes, in order.
   * @param array $options
   *   The instantiate() options. The removal's own errors (an element
   *   that is not there) sit under "element".
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The batch result: the roots ADDED and the element REMOVED, or the
   *   errors re-rooted onto the tree's coordinates.
   */
  public function replace(HostInterface $host, string $uuid, array $nodes, array $options = []): BlueprintResult {
    $expansion = $this->expand($host, $nodes, ['anchor' => $uuid, 'placement' => 'after'], $options);
    if (!$expansion->isValid()) {
      return BlueprintResult::structuralFailure($expansion->errors);
    }
    $operations = $expansion->operations;
    $paths = $expansion->paths;
    // The removal's errors already sit at its own parameter ("element"),
    // so it takes no tree prefix.
    $operations[] = ['op' => 'remove_element', 'element' => $uuid];
    $paths[] = '';
    return $this->run($host, $operations, $paths, $options);
  }

  /**
   * Runs expanded operations on a host as one dressed batch.
   *
   * The second half of instantiate(), for a caller that expands first and
   * runs a subset (the whole-layout write runs the structural operations
   * and writes the node styles as one map): the batch's errors come back
   * on the tree's coordinates.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the copy lands on.
   * @param array[] $operations
   *   The operations, from an expansion.
   * @param string[] $paths
   *   The node path of each operation, parallel to $operations.
   * @param array $options
   *   The instantiate() options: root_path and batch are read here.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The batch result, its errors re-rooted onto the tree's coordinates.
   */
  public function run(HostInterface $host, array $operations, array $paths, array $options = []): BlueprintResult {
    if (array_key_exists('root_path', $options) && $paths !== []) {
      $paths[0] = (string) $options['root_path'];
    }

    $result = ($this->operations)()->batchOperations($host, $operations, ($options['batch'] ?? []) + ['dressed' => TRUE]);
    if ($result->isSuccess()) {
      return $result;
    }

    // The nested batch already rolled the host back; its error indexes
    // refer to the expanded operations, so re-root each error onto the
    // tree's own coordinate space. A block node IS add_element's data, so
    // the op's own "data." namespace is dropped from its errors: the
    // tree spells the node's keys directly (block_type, block_fields).
    $remapped = [];
    foreach ($result->getErrors() as $error) {
      $index = $error->getOperationIndex() ?? -1;
      $prefix = $paths[$index] ?? '';
      $path = $error->getPath();
      if (($operations[$index]['op'] ?? NULL) === 'add_element') {
        $path = $path === 'data' ? '' : (str_starts_with($path, 'data.') ? substr($path, 5) : $path);
      }
      if ($prefix !== '') {
        $path = $path === '' ? $prefix : "$prefix.$path";
      }
      $remapped[] = new BlueprintError($path, $error->getCode(), $error->getMessage(), $error->getContext(), $error->getHint());
    }
    return $result->getPhase() === 'semantic'
      ? BlueprintResult::semanticFailure($remapped, $result->getWarnings())
      : BlueprintResult::structuralFailure($remapped);
  }

  /**
   * Seeds a host's own document from its display's template.
   *
   * The first override of a page is a duplicate of the template's document
   * with fresh identity: every element uuid derived from the page's own
   * uuid over the template element's (derivedUuid()), so the same page
   * reads the same seed on every request until it stages, no two pages
   * share an element uuid, and an anchor a writer copied from a read of the
   * page names the seeded element. Node styles re-key onto the derived
   * identity and the template's own root entries (the page background,
   * the gap between its sections) re-address to the page's root, all in
   * the page's own map, so the page keeps the template's whole look once
   * it owns its document; the template's value blocks become the page's
   * unsaved blocks, their managed configuration and shown label with
   * them. Nothing is persisted: the caller decides to stage or save.
   *
   * A page whose own document holds slot sections and nothing else was
   * locked and is being unlocked: the fills it made inside the template
   * (one section per slot, tagged with the slot id) are its own, so the
   * seed lays the template around them. Each fill is lifted out of the
   * root first, the template lands as above, and the fill then takes its
   * slot's place: the copied slot block (daedalus_slot:{id}) is replaced,
   * in its region and at its weight, by the fill embedded as a nested
   * section, with its own identity (section, components and inline blocks
   * untouched, so the page's style entries for them stay addressed) and
   * its slot tag dropped (a section inside a document the page owns whole
   * fills nothing). A fill whose slot the template no longer places lands
   * after the template's sections as a plain top-level section: a merge
   * drops nothing of the page's own. A slot block the page did not fill
   * stays as copied and renders its default, as it did while locked.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose own document is empty, or holds only slot sections.
   *
   * @return bool
   *   TRUE when the template had a document to seed, FALSE when the
   *   display is not a Layout Builder template or holds nothing, or when
   *   the host already owns a document (a section of its own that fills
   *   no slot: core's blank marker included).
   *
   * @throws \RuntimeException
   *   When the template's document does not instantiate on the host: the
   *   template is broken for this page, loudly.
   */
  public function seed(HostInterface $host): bool {
    $display = $host->display();
    if (!$display instanceof LayoutEntityDisplayInterface || !$display->isLayoutBuilderEnabled()) {
      return FALSE;
    }
    $storage = $host->sectionStorage();
    if ($storage === NULL) {
      return FALSE;
    }
    $root = $this->layoutAdapter->rootList($storage);
    $fills = $root->getSections();
    if (!LayoutSerializer::allSlotSections($fills)) {
      return FALSE;
    }
    $template = ($this->hostResolver)()->fromEntity($display);
    $nodes = $this->treeBuilder->build($template);
    if ($nodes === []) {
      return FALSE;
    }
    if ($fills !== []) {
      $root->removeAllSections();
    }
    $styles = $template->hasStyleStore() ? NodeTreeBuilder::nodelessStyles($nodes, $template->styleMap()) : [];
    $namespace = $host->uuid();
    $result = $this->instantiateDocument($host, $nodes, $styles, [
      'mint' => fn (?string $source): string => $source === NULL
        ? $this->uuid->generate()
        : self::derivedUuid($namespace, $source),
      'path' => 'layout',
      // The template's document is already on the display: restrictions
      // decide what an author may pick, not what a page inherits (a core
      // layout a takeover display holds keeps rendering, unpickable). The
      // seed acts for the site, not the reader whose request triggered
      // it, so the capability gate is not asked either.
      'batch' => ['admitted' => TRUE, 'system' => TRUE],
    ]);
    if (!$result->isSuccess()) {
      throw new \RuntimeException(sprintf(
        'The %s template did not instantiate on %s %s: %s',
        $display->id(),
        $host->entityTypeId(),
        $host->id() ?? $host->uuid(),
        implode('; ', array_map(static fn (BlueprintError $error): string => $error->getPath() . ': ' . $error->getMessage(), $result->getErrors())),
      ));
    }
    if ($fills !== []) {
      $this->dropFills($host, $fills);
    }
    return TRUE;
  }

  /**
   * Narrows a host's own document to its slot sections: the lock's merge.
   *
   * A page rendered inside a foreign document (a page of a locked bundle)
   * owns only what it holds inside that document, its slot sections. Its
   * field may hold more: the whole document it owned while its bundle was
   * overridable, or core's blank marker. On the page's next read the door
   * (ContentHost::seed()) drops everything that fills no slot, in memory,
   * so the page's next save persists what it owns and an unlock finds the
   * shape seed() lays the template around. Nothing is persisted here.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host, rendered inside a foreign document.
   *
   * @return bool
   *   TRUE when a section was dropped, FALSE when the document was already
   *   its fills alone (or the host holds no document).
   */
  public function narrowToSlots(HostInterface $host): bool {
    $storage = $host->sectionStorage();
    if ($storage === NULL) {
      return FALSE;
    }
    $root = $this->layoutAdapter->rootList($storage);
    $sections = $root->getSections();
    if (LayoutSerializer::allSlotSections($sections)) {
      return FALSE;
    }
    $kept = array_filter($sections, static fn (Section $section): bool => LayoutSerializer::slotOf($section) !== NULL);
    $root->removeAllSections();
    foreach ($kept as $section) {
      $root->appendSection($section);
    }
    return TRUE;
  }

  /**
   * Drops a page's fills into their slots' places in its seeded document.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host, its template just seeded.
   * @param \Drupal\layout_builder\Section[] $fills
   *   The slot sections lifted out before the seed, in stored order.
   */
  protected function dropFills(HostInterface $host, array $fills): void {
    $storage = $host->sectionStorage();
    assert($storage instanceof NestedSectionStorageInterface);
    foreach ($storage->getAllSections() as $section) {
      foreach ($section->getComponents() as $uuid => $component) {
        $slot = SlotBlock::slotIdOf($component->getPluginId());
        if ($slot === NULL) {
          continue;
        }
        foreach ($fills as $index => $fill) {
          if (LayoutSerializer::slotOf($fill) !== $slot) {
            continue;
          }
          unset($fills[$index]);
          $fill->unsetThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING);
          $section->insertAfterComponent($uuid, $this->layoutAdapter->createSectionComponent($fill, $component->getRegion()));
          $section->removeComponent($uuid);
          break;
        }
      }
    }
    $root = $this->layoutAdapter->rootList($storage);
    foreach ($fills as $fill) {
      $fill->unsetThirdPartySetting(LayoutSerializer::SLOT_PROVIDER, LayoutSerializer::SLOT_SETTING);
      $root->appendSection($fill);
    }
    $storage->bubbleChangesToRoot();
  }

  /**
   * Expands a tree into batch operations with fresh identity.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the copy lands on: its subject's entity type and bundle
   *   spell the derivative a field node places.
   * @param array $nodes
   *   The root nodes, in order.
   * @param array $location
   *   The location the first root lands at; each following root lands
   *   after the previous one.
   * @param array $options
   *   The expansion options:
   *   - mint: callable(?string $source_uuid): string, the minting strategy.
   *     Receives the node's own uuid when it carries one (a source identity
   *     to map, or an identity the author chose) and NULL otherwise.
   *     Defaults to a random uuid for every node.
   *   - root_uuid: the uuid of the first root node, whatever the strategy
   *     (a creating op's optional client-minted identity).
   *   - container_layout: the layout id a container node without layout_id
   *     is born as; without it such a node is a grammar error.
   *   - provenance: a blueprint id stamped onto every root node's create op.
   *   - sample_fields: TRUE makes an inline block's absent fields
   *     sample-populate at placement (the palette's populate: 'sample');
   *     FALSE, the default, leaves them empty (a copy copies).
   *   - path: the node path of the roots, for error coordinates: the path
   *     itself for one root, path[i] for several. Defaults to 'nodes'.
   *   - indexed: TRUE addresses every root as path[i], one root included
   *     (the roots are a list in the caller's grammar).
   *   - grammar_code: the code of a tree grammar error (GRAMMAR_CODE).
   *   - styles: a whole document's node-less style entries (its root's
   *     entity: and field: keys and their rule forms, keyed as the source
   *     stored them), re-addressed to the host's root and expanded ahead
   *     of the nodes' own, the canonical order of a map; their errors sit
   *     under "styles". The whole-document copy's option
   *     (instantiateDocument()).
   *
   * @return \Drupal\daedalus_blueprint\NodeTree\DocumentExpansion
   *   The expansion.
   */
  public function expand(HostInterface $host, array $nodes, array $location, array $options = []): DocumentExpansion {
    $state = [
      'host' => $host,
      'mint' => $options['mint'] ?? fn (): string => $this->uuid->generate(),
      'container_layout' => $options['container_layout'] ?? NULL,
      'sample_fields' => !empty($options['sample_fields']),
      'code' => (string) ($options['grammar_code'] ?? self::GRAMMAR_CODE),
      'ops' => [],
      'paths' => [],
      'style_ops' => [],
      'style_paths' => [],
      'styles' => [],
      'uuid_map' => [],
      'errors' => [],
    ];
    if (!empty($options['styles'])) {
      $this->expandRootStyles($options['styles'], $state);
    }
    $base = (string) ($options['path'] ?? 'nodes');
    $single = count($nodes) === 1 && empty($options['indexed']);

    $roots = [];
    $previous = NULL;
    foreach (array_values($nodes) as $index => $node) {
      $path = $single ? $base : "{$base}[$index]";
      $node_location = $previous === NULL ? $location : ['anchor' => $previous, 'placement' => 'after'];
      $first = count($state['ops']);
      $uuid = $this->expandNode($node, $node_location, $path, $state, $index === 0 ? ($options['root_uuid'] ?? NULL) : NULL, TRUE);
      if ($uuid === NULL) {
        continue;
      }
      // Provenance is a birth fact riding the root create op: block roots
      // carry the stamp in add_element's additional data, container roots
      // pass add_section's blueprint parameter (the section value's
      // third-party settings are the uniform slot at every depth). Both
      // land the stamp at creation, so the trailing style ops (a blueprint's
      // own owned-variant applies) run against a stamped root.
      if (isset($options['provenance']) && isset($state['ops'][$first])) {
        if ($state['ops'][$first]['op'] === 'add_section') {
          $state['ops'][$first]['blueprint'] = $options['provenance'];
        }
        elseif ($state['ops'][$first]['op'] === 'add_element') {
          $state['ops'][$first]['additional'][LayoutSerializer::BLUEPRINT_PROVENANCE_KEY] = $options['provenance'];
        }
      }
      $roots[] = $uuid;
      $previous = $uuid;
    }

    return new DocumentExpansion(
      array_merge($state['ops'], $state['style_ops']),
      array_merge($state['paths'], $state['style_paths']),
      count($state['ops']),
      $state['styles'],
      $state['uuid_map'],
      $roots,
      $state['errors'],
    );
  }

  /**
   * A uuid derived from a namespace uuid and a name (RFC 4122 version 5).
   *
   * The seed's minting strategy: the page's uuid as the namespace, the
   * template element's uuid as the name, so the derived identity is a
   * function of the page and the element and nothing else.
   *
   * @param string $namespace
   *   A uuid.
   * @param string $name
   *   The name within the namespace.
   *
   * @return string
   *   The derived uuid.
   */
  public static function derivedUuid(string $namespace, string $name): string {
    $bytes = hex2bin(str_replace('-', '', $namespace));
    if ($bytes === FALSE || strlen($bytes) !== 16) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a uuid to derive from.', $namespace));
    }
    $hex = substr(sha1($bytes . $name), 0, 32);
    $hex = substr($hex, 0, 12) . '5' . substr($hex, 13, 3)
      . dechex((hexdec($hex[16]) & 0x3) | 0x8) . substr($hex, 17, 15);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
  }

  /**
   * Whether a node is a container node.
   *
   * The tree's one discriminator: nodes declare their kind. A section IS
   * a container node.
   */
  public static function isContainerNode(mixed $node): bool {
    return is_array($node) && ($node['kind'] ?? NULL) === 'container';
  }

  /**
   * Expands one node, dispatching on its declared kind.
   *
   * @param mixed $node
   *   The node.
   * @param array $location
   *   The location it lands at.
   * @param string $path
   *   Its path, for error coordinates.
   * @param array $state
   *   The expansion state.
   * @param string|null $uuid
   *   An identity chosen for it ahead of minting (a root_uuid).
   * @param bool $root
   *   Whether the node is a root of this expansion: a root's blueprint
   *   provenance travels onto its create op, a nested node's is read-only.
   *
   * @return string|null
   *   The node's minted uuid, or NULL when the node is not a placeable
   *   element (a grammar error was recorded).
   */
  protected function expandNode(mixed $node, array $location, string $path, array &$state, ?string $uuid = NULL, bool $root = FALSE): ?string {
    if (!is_array($node) || $node === [] || array_is_list($node)) {
      $state['errors'][] = new BlueprintError($path, $state['code'], 'A tree node is a map declaring its kind.', [], self::TREE_GRAMMAR_HINT);
      return NULL;
    }
    $node = $this->writable($node, $root);
    if (array_key_exists('layout', $node)) {
      // The dead pre-tree shape, refused with a pointer at the one grammar:
      // blocks never hold layouts; a container IS its own node.
      $state['errors'][] = new BlueprintError("$path.layout", $state['code'], 'Blocks do not hold layouts.', [], 'Nest a container by placing a container node (kind: container, layout_id, children) in the parent\'s children instead of a layout list on a block.');
      return NULL;
    }
    switch ($node['kind'] ?? NULL) {
      case 'container':
        return $this->expandContainerNode($node, $location, $path, $state, $uuid, $root);

      case 'block':
        return $this->expandBlockNode($node, $location, $path, $state, $uuid);

      case 'blueprint':
        return $this->expandBlueprintNode($node, $location, $path, $state, $uuid);

      case LinkedBlueprintBlock::KIND:
        return $this->expandLinkedNode($node, $location, $path, $state, $uuid);

      case 'region':
        $state['errors'][] = new BlueprintError("$path.kind", $state['code'], 'A region node only appears among a multi-region container\'s children.', [], 'Region nodes group a container layout\'s named slots; they are not placeable elements.');
        return NULL;
    }
    $state['errors'][] = new BlueprintError("$path.kind", $state['code'], 'Every tree node declares its kind: "container", "block" or "linked".', [], self::TREE_GRAMMAR_HINT);
    return NULL;
  }

  /**
   * The node reduced to the keys a write can mean.
   *
   * What a read carries beyond them is dropped here, so a read
   * instantiates as it is.
   *
   * @param array $node
   *   The node as given.
   * @param bool $root
   *   Whether the node is a root of the expansion (its provenance travels).
   *
   * @return array
   *   The node without delivery annotations, storage residue, and (below
   *   a root) blueprint provenance.
   */
  protected function writable(array $node, bool $root): array {
    foreach (array_keys($node) as $key) {
      if (str_starts_with((string) $key, '_') || in_array($key, self::READ_ONLY_KEYS, TRUE)) {
        unset($node[$key]);
      }
    }
    // Below a root, `blueprint` is provenance and read-only; on a linked
    // node it is the identity of what the node shows and travels.
    if (!$root && ($node['kind'] ?? NULL) !== LinkedBlueprintBlock::KIND) {
      unset($node['blueprint']);
    }
    return $node;
  }

  /**
   * Mints a node's identity and records its source in the map.
   */
  protected function mint(array $node, string $path, array &$state, ?string $uuid): ?string {
    $source = $node['uuid'] ?? NULL;
    if ($source !== NULL && (!is_string($source) || !Uuid::isValid($source))) {
      $state['errors'][] = new BlueprintError("$path.uuid", $state['code'], 'A node\'s uuid is a uuid.', [], 'Omit the key: identity is minted at placement.');
      return NULL;
    }
    $uuid ??= (string) ($state['mint'])($source);
    if ($source !== NULL) {
      $state['uuid_map'][$source] = $uuid;
    }
    return $uuid;
  }

  /**
   * Expands a container node into add_section and its children.
   */
  protected function expandContainerNode(array $node, array $location, string $path, array &$state, ?string $uuid = NULL, bool $root = FALSE): ?string {
    if ($unknown = array_diff(array_keys($node), self::CONTAINER_KEYS)) {
      $hint = in_array('regions', $unknown, TRUE)
        ? self::TREE_GRAMMAR_HINT
        : 'A container node consists of kind, layout_id, label, layout_settings, blueprint, slot, children, and styles.';
      $state['errors'][] = new BlueprintError($path, $state['code'], sprintf('Unknown key(s) %s on the container at "%s".', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $path), [], $hint);
    }
    $layout_id = $node['layout_id'] ?? $state['container_layout'];
    if (!is_string($layout_id) || $layout_id === '') {
      $state['errors'][] = new BlueprintError("$path.layout_id", $state['code'], 'Every container names its layout_id.', [], 'Set layout_id to a layout plugin ID.');
      return NULL;
    }
    $label = $node['label'] ?? NULL;
    if ($label !== NULL && (!is_string($label) || $label === '')) {
      $state['errors'][] = new BlueprintError("$path.label", $state['code'], 'label is a non-empty string.', [], 'Omit the key for a container named after its layout.');
      $label = NULL;
    }
    // Opaque-but-structural plugin settings (a two-column layout's
    // column_widths): applied verbatim through add_section's
    // layout_settings patch; the plugin itself owns what the values mean.
    $layout_settings = $node['layout_settings'] ?? [];
    if (!is_array($layout_settings) || (array_is_list($layout_settings) && $layout_settings !== [])) {
      $state['errors'][] = new BlueprintError("$path.layout_settings", $state['code'], 'layout_settings is a map of layout plugin settings.', [], 'Provide settings the layout plugin understands (e.g. column_widths for two-column layouts); omitted keys use the plugin defaults.');
      $layout_settings = [];
    }
    $blueprint = $node['blueprint'] ?? NULL;
    if ($blueprint !== NULL && (!is_string($blueprint) || $blueprint === '')) {
      $state['errors'][] = new BlueprintError("$path.blueprint", $state['code'], 'blueprint is a non-empty blueprint ID.', [], 'Omit the key for an element that is not a blueprint placement.');
      $blueprint = NULL;
    }
    // A slot is a birth fact of a top-level section, so it rides a root's
    // add_section (the op door judges the id, the anchor and uniqueness);
    // a container nested in this tree fills no slot, whatever it claims.
    $slot = $node['slot'] ?? NULL;
    if ($slot !== NULL && (!is_string($slot) || $slot === '')) {
      $state['errors'][] = new BlueprintError("$path.slot", $state['code'], 'slot is a non-empty slot id.', [], 'Omit the key for a section that fills no slot.');
      $slot = NULL;
    }
    elseif ($slot !== NULL && !$root) {
      $state['errors'][] = new BlueprintError("$path.slot", $state['code'], 'Only a top-level section fills a slot.', [], 'A slot section is one of the document\'s own top-level containers; a container nested inside another carries no slot.');
      $slot = NULL;
    }
    $children = $node['children'] ?? [];
    if (!is_array($children) || !array_is_list($children)) {
      $state['errors'][] = new BlueprintError("$path.children", $state['code'], 'children is a list of nodes.', [], 'Provide the container\'s blocks and nested containers as a list; a multi-region layout\'s slots are region nodes ({kind: region, region, children}).');
      $children = [];
    }

    // The layout's own validity is the spine's call (add_section rejects
    // unknown layouts); when the plugin is resolvable here, region node
    // names are checked against it so a typo fails the placement instead of
    // silently stranding nodes in a nonexistent region.
    $valid_regions = NULL;
    $definition = $this->layoutManager?->getDefinition($layout_id, FALSE);
    if ($definition !== NULL) {
      $valid_regions = $definition->getRegionNames();
    }

    $uuid = $this->mint($node, $path, $state, $uuid);
    if ($uuid === NULL) {
      return NULL;
    }
    // The name lands in the section's label slot at birth
    // (layout_settings.label, the slot rename and set {name} write).
    if ($label !== NULL) {
      $layout_settings['label'] = $label;
    }
    $add_section = [
      'op' => 'add_section',
      'location' => $location,
      'uuid' => $uuid,
      'layout' => $layout_id,
    ];
    if ($layout_settings !== []) {
      $add_section['layout_settings'] = $layout_settings;
    }
    if ($blueprint !== NULL) {
      $add_section['blueprint'] = $blueprint;
    }
    if ($slot !== NULL) {
      $add_section['slot'] = $slot;
    }
    $state['ops'][] = $add_section;
    $state['paths'][] = $path;

    // The node's styles collect into the deferred style stream in tree
    // order, this container's before its descendants'. The container is
    // born as its layout, so its root styles (a layout-scoped variant, a
    // container part) validate against the right layout from the first op.
    $this->expandStyles($node['styles'] ?? NULL, "container:$uuid", TRUE, "$path.styles", $state);

    // ONE child axis: element nodes ride directly (a container layout's
    // ordered flow; the location carries no region), region nodes carry a
    // multi-region layout's named slots. The two shapes never mix.
    $has_region_children = FALSE;
    $has_element_children = FALSE;
    foreach ($children as $index => $child) {
      $child_path = "$path.children[$index]";
      if (is_array($child) && ($child['kind'] ?? NULL) === 'region') {
        $has_region_children = TRUE;
        $this->expandRegionNode($child, $uuid, $layout_id, $valid_regions, $child_path, $state);
        continue;
      }
      $has_element_children = TRUE;
      $this->expandNode($child, ['anchor' => $uuid, 'placement' => 'inside'], $child_path, $state);
    }
    if ($has_region_children && $has_element_children) {
      $state['errors'][] = new BlueprintError("$path.children", $state['code'], 'Region nodes and element nodes do not mix in one children list.', [], 'A container layout\'s flow is element nodes directly; a multi-region layout\'s slots are ALL region nodes.');
    }
    return $uuid;
  }

  /**
   * Expands a region node's children into the containing section's region.
   *
   * Region nodes are grouping, not elements: no uuid, no styles, no ops of
   * their own.
   */
  protected function expandRegionNode(array $node, string $section_uuid, string $layout_id, ?array $valid_regions, string $path, array &$state): void {
    $node = $this->writable($node, FALSE);
    if ($unknown = array_diff(array_keys($node), self::REGION_KEYS)) {
      $state['errors'][] = new BlueprintError($path, $state['code'], sprintf('Unknown key(s) %s on the region node at "%s".', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $path), [], 'A region node consists of kind, region, and children; regions are grouping, not addressable elements.');
    }
    $region = $node['region'] ?? NULL;
    if (!is_string($region) || $region === '') {
      $state['errors'][] = new BlueprintError("$path.region", $state['code'], 'Every region node names its region.', [], 'Set region to a region the containing layout defines.');
      return;
    }
    if ($valid_regions !== NULL && !in_array($region, $valid_regions, TRUE)) {
      $state['errors'][] = new BlueprintError("$path.region", $state['code'], sprintf('Region "%s" does not exist on layout "%s".', $region, $layout_id), ['available' => $valid_regions], 'Use a region the containing layout defines.');
      return;
    }
    $children = $node['children'] ?? [];
    if (!is_array($children) || !array_is_list($children)) {
      $state['errors'][] = new BlueprintError("$path.children", $state['code'], 'A region node holds a list of nodes.', [], 'Provide the region\'s blocks and nested containers as a list.');
      return;
    }
    foreach ($children as $index => $child) {
      $this->expandNode($child, ['anchor' => $section_uuid, 'placement' => 'inside', 'region' => $region], "$path.children[$index]", $state);
    }
  }

  /**
   * Expands a block node into add_element.
   *
   * An inline block places by block_type with block_fields plus its managed
   * configuration keys as its bag and its view_mode; a plugin block by
   * plugin_id with label and its own configuration keys as its bag (T3's
   * schema-derived properties); a field block by field, the destination
   * subject's derivative resolved here (fieldBlockPluginId()), with the
   * formatter's own keys as its bag. Each spells a shown label as
   * label_rendered.
   */
  protected function expandBlockNode(array $node, array $location, string $path, array &$state, ?string $uuid = NULL): ?string {
    // A block node's slot is the slot its plugin id declares (a slot
    // block's door, LayoutSerializer's print of SlotBlock::slotIdOf()),
    // a fact of the plugin id a write cannot mean on its own: read-only,
    // like residue, so a template holding a slot block seeds and copies.
    unset($node['slot']);
    if ($unknown = array_diff(array_keys($node), self::BLOCK_KEYS)) {
      $hint = in_array('label_display', $unknown, TRUE)
        ? 'label_rendered: true is the one spelling for a shown label; label_display is storage\'s and never travels in a tree.'
        : 'A block node consists of kind, block_type, block_fields, configuration (its managed keys), view_mode (an inline block) or plugin_id, configuration (a plugin block) or field, configuration (one of the page\'s fields with its formatter), label, label_rendered, blueprint, and styles.';
      $state['errors'][] = new BlueprintError($path, $state['code'], sprintf('Unknown key(s) %s on the block node at "%s".', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $path), [], $hint);
    }
    $block_type = $node['block_type'] ?? NULL;
    $plugin_id = $node['plugin_id'] ?? NULL;
    $field = $node['field'] ?? NULL;
    $named = array_keys(array_filter(['block_type' => $block_type, 'plugin_id' => $plugin_id, 'field' => $field], static fn ($value) => $value !== NULL));
    if (count($named) > 1) {
      $state['errors'][] = new BlueprintError($path, $state['code'], sprintf('A block node names block_type (an inline block), plugin_id (a plugin block) or field (one of the page\'s fields), not %s.', implode(' and ', $named)), [], 'Inline blocks hold their own content; plugin blocks render existing content; a field block renders one of the page\'s own fields.');
      return NULL;
    }
    if ($plugin_id !== NULL && (!is_string($plugin_id) || $plugin_id === '')) {
      $state['errors'][] = new BlueprintError("$path.plugin_id", $state['code'], 'plugin_id is a block plugin ID.', [], 'Set plugin_id to a block plugin ID (a view, a menu); one of the page\'s fields is placed by field.');
      return NULL;
    }
    if ($plugin_id !== NULL && FieldBlock::isFieldBlock($plugin_id)) {
      $state['errors'][] = new BlueprintError("$path.plugin_id", $state['code'], sprintf('A field block is placed by field, never by its plugin id "%s".', $plugin_id), ['field' => FieldBlock::fieldOf($plugin_id)], sprintf('Write {kind: block, field: "%s"}: the page the tree lands on supplies its own field block.', FieldBlock::fieldOf($plugin_id) ?? 'name'));
      return NULL;
    }
    if ($field !== NULL && (!is_string($field) || $field === '')) {
      $state['errors'][] = new BlueprintError("$path.field", $state['code'], 'field is a field name of the page.', [], 'Set field to the machine name of one of the page\'s fields (available_fields in read_schema).');
      return NULL;
    }
    if ($named === [] || ($block_type !== NULL && (!is_string($block_type) || $block_type === ''))) {
      $state['errors'][] = new BlueprintError("$path.block_type", $state['code'], 'Every block node names its block_type, plugin_id or field.', [], 'Set block_type to an inline block type machine name, plugin_id to a block plugin ID, or field to one of the page\'s field names.');
      return NULL;
    }
    if ($field !== NULL) {
      $plugin_id = $this->fieldBlockPluginId($field, $path, $state);
      if ($plugin_id === NULL) {
        return NULL;
      }
    }
    $blueprint = $node['blueprint'] ?? NULL;
    if ($blueprint !== NULL && (!is_string($blueprint) || $blueprint === '')) {
      $state['errors'][] = new BlueprintError("$path.blueprint", $state['code'], 'blueprint is a non-empty blueprint ID.', [], 'Omit the key for an element that is not a blueprint placement.');
      $blueprint = NULL;
    }

    // The property bag: block_fields (inline) or configuration (plugin),
    // plus the display label; the tree's label_rendered becomes core's
    // label_display spelling at the op (absent, the op hides the label).
    $properties = [];
    $bag_key = $plugin_id === NULL ? 'block_fields' : 'configuration';
    $bag = $node[$bag_key] ?? [];
    if (!is_array($bag) || (array_is_list($bag) && $bag !== [])) {
      $state['errors'][] = new BlueprintError("$path.$bag_key", $state['code'], "$bag_key is a map of names to values.", [], $plugin_id === NULL ? 'Omit a field to leave it empty.' : 'Provide the plugin\'s own settings keys as its config schema spells them.');
      $bag = [];
    }
    foreach ($bag as $key => $value) {
      // A form-only stub carries identity, never a value.
      if (!FormOnlyStub::isStub($value)) {
        $properties[$key] = $value;
      }
    }
    if ($plugin_id === NULL) {
      // An inline block's configuration: its block type's managed keys, as
      // a read emits them. They join the one bag (the op sorts fields from
      // configuration by the managed-keys registry, so a key that is
      // neither is the op's UNKNOWN_PROPERTY). A null is the absent key
      // (a read spells an unset managed key as null), so it writes
      // nothing.
      $configuration = $node['configuration'] ?? [];
      if (!is_array($configuration) || (array_is_list($configuration) && $configuration !== [])) {
        $state['errors'][] = new BlueprintError("$path.configuration", $state['code'], 'configuration is a map of the block type\'s managed keys to values.', [], 'Provide the managed keys read_schema lists for the block type; omit a key to leave it unset.');
        $configuration = [];
      }
      foreach ($configuration as $key => $value) {
        if (array_key_exists($key, $bag)) {
          $state['errors'][] = new BlueprintError("$path.configuration.$key", $state['code'], sprintf('"%s" is named in both block_fields and configuration.', $key), [], 'A block field lives in block_fields, a managed configuration key in configuration; name each once.');
          continue;
        }
        if ($value !== NULL) {
          $properties[$key] = $value;
        }
      }
    }
    if (isset($node['label'])) {
      $properties['label'] = $node['label'];
    }
    if (!empty($node['label_rendered'])) {
      $properties['label_display'] = 'visible';
    }

    // The render view mode is an inline block's placement configuration,
    // which the op takes in the bag like label_display; core's block plugin
    // settings carry none for a plugin block, so a read never emits one
    // there and a write naming one is a mistake.
    $view_mode = $node['view_mode'] ?? NULL;
    if ($view_mode !== NULL) {
      if ($plugin_id !== NULL) {
        $state['errors'][] = new BlueprintError("$path.view_mode", $state['code'], 'A plugin block has no view_mode.', [], 'view_mode is an inline block\'s render view mode; omit it on a plugin block.');
      }
      elseif (!is_string($view_mode) || $view_mode === '') {
        $state['errors'][] = new BlueprintError("$path.view_mode", $state['code'], 'view_mode is a view mode id.', [], 'Name a view mode of the block type (full is the default), or omit the key.');
      }
      else {
        $properties['view_mode'] = $view_mode;
      }
    }

    $uuid = $this->mint($node, $path, $state, $uuid);
    if ($uuid === NULL) {
      return NULL;
    }
    $op = [
      'op' => 'add_element',
      'location' => $location,
      'uuid' => $uuid,
    ];
    if ($plugin_id !== NULL) {
      $op['plugin_id'] = $plugin_id;
    }
    else {
      $op['block_type'] = $block_type;
      if ($state['sample_fields']) {
        $op['populate'] = 'sample';
      }
    }
    if ($properties !== []) {
      $op['properties'] = $properties;
    }
    if ($blueprint !== NULL) {
      $op['additional'][LayoutSerializer::BLUEPRINT_PROVENANCE_KEY] = $blueprint;
    }
    $state['ops'][] = $op;
    $state['paths'][] = $path;

    $this->expandStyles($node['styles'] ?? NULL, "block:$uuid", FALSE, "$path.styles", $state);
    return $uuid;
  }

  /**
   * The field block derivative a field node places on the destination host.
   *
   * The host's subject (the entity a render of the document shows: a page
   * itself, a template's or a blueprint's sample) names the entity type
   * and bundle; the field is placeable exactly when core derived a field
   * block for it there. A field the subject cannot place is refused
   * FIELD_NOT_ON_HOST with the subject's placeable fields as the
   * corrective, so the model picks a field the page has instead of
   * guessing at a plugin id.
   *
   * @param string $field
   *   The field name the node spells.
   * @param string $path
   *   The node path, for error coordinates.
   * @param array $state
   *   The expansion state (its host).
   *
   * @return string|null
   *   The derivative plugin id, or NULL when refused.
   */
  protected function fieldBlockPluginId(string $field, string $path, array &$state): ?string {
    /** @var \Drupal\daedalus_blueprint\Host\HostInterface $host */
    $host = $state['host'];
    $subject = $host->subject();
    if ($subject === NULL) {
      // A host with no subject (the site frame) shows no entity: there is
      // no field anywhere a field node could present.
      $state['errors'][] = new BlueprintError(
        "$path.field",
        'FIELD_NOT_ON_HOST',
        sprintf('This %s shows no entity, so no field "%s" can be placed in it.', $host->entityTypeId(), $field),
        ['field' => $field, 'entity_type' => $host->entityTypeId(), 'bundle' => $host->bundle(), 'available' => []],
        'A field block presents a field of the page it sits in; this document renders around every page and has none. Omit the node.',
      );
      return NULL;
    }
    $entity_type = $subject->getEntityTypeId();
    $bundle = $subject->bundle();
    $plugin_id = FieldBlock::pluginId($entity_type, $bundle, $field);
    if ($this->blockManager->hasDefinition($plugin_id)) {
      return $plugin_id;
    }
    $available = array_keys(FieldBlock::fieldsIn($this->blockManager->getDefinitions(), $entity_type, $bundle));
    $state['errors'][] = new BlueprintError(
      "$path.field",
      'FIELD_NOT_ON_HOST',
      sprintf('This %s (%s) has no placeable field "%s".', $entity_type, $bundle, $field),
      ['field' => $field, 'entity_type' => $entity_type, 'bundle' => $bundle, 'available' => $available],
      $available === []
        ? sprintf('No field of this %s can be placed as a block; omit the node.', $entity_type)
        : sprintf('Name one of the page\'s placeable fields: %s.', implode(', ', $available)),
    );
    return NULL;
  }

  /**
   * Expands a blueprint reference node into place_blueprint.
   *
   * The page language's <pb-blueprint name="..."/>: the placement comes back
   * through here when the blueprint handler expands the definition.
   */
  protected function expandBlueprintNode(array $node, array $location, string $path, array &$state, ?string $uuid = NULL): ?string {
    if ($unknown = array_diff(array_keys($node), self::BLUEPRINT_KEYS)) {
      $state['errors'][] = new BlueprintError($path, $state['code'], sprintf('Unknown key(s) %s on the blueprint reference at "%s".', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $path), [], 'A blueprint reference consists of kind and name; place the blueprint, then style the placed elements.');
    }
    $name = $node['name'] ?? NULL;
    if (!is_string($name) || $name === '') {
      $state['errors'][] = new BlueprintError("$path.name", $state['code'], 'A blueprint reference names its blueprint.', [], 'Set name to a blueprint ID.');
      return NULL;
    }
    $uuid = $this->mint($node, $path, $state, $uuid);
    if ($uuid === NULL) {
      return NULL;
    }
    $state['ops'][] = [
      'op' => 'place_blueprint',
      'blueprint' => $name,
      'location' => $location,
      'uuid' => $uuid,
    ];
    $state['paths'][] = $path;
    return $uuid;
  }

  /**
   * Expands a linked node into the add_element placing the linked block.
   *
   * `{kind: linked, blueprint: name}` (the dialect's <pb-linked
   * blueprint="name"/>) is the blueprint shown by reference: one block
   * component, daedalus_blueprint_linked:{name}, whose plugin derives per
   * published block-scope blueprint entity (LinkedBlueprintDeriver), so a
   * name that is no such blueprint is the op door's INVALID_PLUGIN_ID, and
   * whether the blueprint fits the host is judged there too, once, for
   * every road into the document (BlueprintOperations, the linked fit
   * gate). The node carries no provenance and no children; its styles
   * are the placement's own box, block-keyed.
   */
  protected function expandLinkedNode(array $node, array $location, string $path, array &$state, ?string $uuid = NULL): ?string {
    if ($unknown = array_diff(array_keys($node), self::LINKED_KEYS)) {
      $state['errors'][] = new BlueprintError($path, $state['code'], sprintf('Unknown key(s) %s on the linked node at "%s".', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $path), [], 'A linked node consists of kind, blueprint (the machine name of the blueprint shown by reference) and styles; its content is the blueprint\'s own document, edited on the blueprint.');
    }
    $name = $node[LinkedBlueprintBlock::BLUEPRINT_KEY] ?? NULL;
    if (!is_string($name) || $name === '') {
      $state['errors'][] = new BlueprintError("$path." . LinkedBlueprintBlock::BLUEPRINT_KEY, $state['code'], 'A linked node names the blueprint it shows.', [], 'Set blueprint to the machine name of a published block blueprint saved on this site.');
      return NULL;
    }
    $uuid = $this->mint($node, $path, $state, $uuid);
    if ($uuid === NULL) {
      return NULL;
    }
    $state['ops'][] = [
      'op' => 'add_element',
      'location' => $location,
      'uuid' => $uuid,
      'plugin_id' => LinkedBlueprintBlock::pluginId($name),
    ];
    $state['paths'][] = $path;
    $this->expandStyles($node['styles'] ?? NULL, "block:$uuid", FALSE, "$path.styles", $state);
    return $uuid;
  }

  /**
   * Expands a document's root styles onto the host's root.
   *
   * The node-less entries of a source document address its root: the
   * entity:{uuid} key, the field:{uuid}:{name} keys and their "::" rule
   * forms. Each re-addresses to the host's uuid (the kind and the field
   * name kept, the selector kept) and rides expandStyles() under that
   * root key, so the entries travel exactly as a node's do: the same
   * operations, the same normalization of variant references, the same
   * place in the expansion's style map. A node-less key of a node kind
   * (container:, block:) names an element the document does not have, a
   * stale key a read keeps so the map stays reconstructible: no write can
   * mean it, so it is ignored. A key outside the key grammar is a grammar
   * error at "styles.{key}".
   *
   * The entries land BENEATH what the host's root already says: a host
   * that takes a document holds none, so whatever its map carries at a
   * root key is its own statement and stands (beneath()). Only the seed
   * meets one (a never-edited page with root styles of its own); a start
   * lands on a new entity and a capture on an emptied map.
   *
   * @param array $styles
   *   The node-less entries, keyed as the source stored them.
   * @param array $state
   *   The expansion state (its host is the destination).
   */
  protected function expandRootStyles(array $styles, array &$state): void {
    /** @var \Drupal\daedalus_blueprint\Host\HostInterface $host */
    $host = $state['host'];
    $target = $host->uuid();
    $own = $host->hasStyleStore() ? $host->styleMap() : [];
    $grouped = [];
    foreach ($styles as $key => $entry) {
      $key = (string) $key;
      $split = self::splitBareRootKey($key) ?? RelativeSelector::splitKey($key);
      $parts = $split === NULL ? [] : explode(':', $split[0], 3);
      $root_key = match ($parts[0] ?? NULL) {
        'entity' => count($parts) === 2 ? "entity:$target" : NULL,
        'field' => count($parts) === 3 && $parts[2] !== '' ? "field:$target:{$parts[2]}" : NULL,
        'container', 'block' => '',
        default => NULL,
      };
      if ($root_key === NULL) {
        $state['errors'][] = new BlueprintError("styles.$key", $state['code'], sprintf('"%s" is not a style map key of the document\'s root.', $key), [], 'A document\'s styles map holds the node-less keys: entity:{uuid} and field:{uuid}:{field_name}, each optionally followed by "::" and a relative selector; a node\'s own styles ride the node.');
        continue;
      }
      if ($root_key === '') {
        continue;
      }
      $relative = $split[1] === NULL ? '' : RelativeSelector::SUFFIX . $split[1];
      if (is_array($entry) && is_array($own[$root_key . $relative] ?? NULL)) {
        $entry = self::beneath($entry, $own[$root_key . $relative]);
        if ($entry === []) {
          continue;
        }
      }
      $grouped[$root_key][$relative] = $entry;
    }
    foreach ($grouped as $root_key => $entries) {
      $this->expandStyles($entries, $root_key, TRUE, 'styles', $state);
    }
  }

  /**
   * What an incoming style entry adds beneath one the host already holds.
   *
   * The write-side twin of the render's layering (daedalus_stylish's
   * RenderStyles::layer(), the template's root entries under the page's
   * own): per condition the host's own properties stand and the incoming
   * entry supplies only the ones the host did not set, and the host's own
   * "variants" list stands whole (a reference list is one statement, not
   * a property bag). A condition left with nothing to add is dropped.
   *
   * @param array $entry
   *   The incoming entry.
   * @param array $own
   *   The entry the host's map already holds at the same key.
   *
   * @return array
   *   The part of the incoming entry the host's own does not cover; []
   *   when the host's own covers all of it.
   */
  protected static function beneath(array $entry, array $own): array {
    foreach ($entry as $condition => $value) {
      if (!array_key_exists($condition, $own)) {
        continue;
      }
      if ($condition === 'variants' || !is_array($value) || !is_array($own[$condition])) {
        unset($entry[$condition]);
        continue;
      }
      $entry[$condition] = array_diff_key($value, $own[$condition]);
      if ($entry[$condition] === []) {
        unset($entry[$condition]);
      }
    }
    return $entry;
  }

  /**
   * Splits a shipped file's bare root key, or NULL for any other key.
   *
   * A file has no host, so its root keys carry no uuid (the shipper's
   * spelling: `entity:`, `entity:::> *`, `field::body`, `field::body::p`).
   * RelativeSelector::splitKey() cannot read them: the first "::" it
   * finds is the bare key's own trailing colon run into the rule suffix,
   * so this reads the bare forms by their shape and answers what
   * splitKey() answers for a keyed one, the key's base and its parsed
   * selector (NULL for none). A key whose uuid is present, or any other
   * key, answers NULL and takes the ordinary split.
   *
   * @return array{0: string, 1: \Drupal\daedalus_blueprint\Materials\RelativeSelector|null}|null
   *   The base key and the selector, or NULL.
   */
  protected static function splitBareRootKey(string $key): ?array {
    if (!preg_match('/^(entity:|field::([^:]+))(?:' . preg_quote(RelativeSelector::SUFFIX, '/') . '(.*))?$/s', $key, $match)) {
      return NULL;
    }
    $selector = NULL;
    if (isset($match[3])) {
      $selector = RelativeSelector::parse($match[3]);
      if ($selector === NULL || (string) $selector !== $match[3]) {
        return NULL;
      }
    }
    return [$match[1], $selector];
  }

  /**
   * Expands a node's co-located styles into style operations.
   *
   * Translates the element-relative keys (the tree's local-remainder
   * grammar) onto the absolute selectable key minted for the node and
   * emits daedalus_stylish's update_style / apply_variant operations, so
   * every style flows through the same StyleMapValidator as any other
   * style write. The ops land in the deferred style stream, and the same
   * entry (variants as authored machine names, the condition maps as
   * given) lands in the expansion's style map under the absolute key, for
   * a consumer that writes one whole map through the style store's field
   * handler instead of running the ops.
   *
   * @param mixed $styles
   *   The node's styles map, or NULL when the node carries none.
   * @param string $root_key
   *   The node's absolute selectable key (block:{uuid} or container:{uuid}).
   * @param bool $container
   *   Whether the node is a container (selects the container-vocabulary
   *   correctives; a block's ':{field}' local is a field entry).
   * @param string $path
   *   The styles map's path, for error coordinates ("{node}.styles" for a
   *   node's own, "styles" for a document's root entries).
   * @param array $state
   *   The expansion state.
   */
  protected function expandStyles(mixed $styles, string $root_key, bool $container, string $path, array &$state): void {
    if ($styles === NULL) {
      return;
    }
    if (!is_array($styles) || (array_is_list($styles) && $styles !== [])) {
      $state['errors'][] = new BlueprintError($path, $state['code'], 'styles is a map of element-relative keys to style entries.', [], "Key by '' for the element itself and '::' plus a relative selector for what is inside it ('::.class-name' a part, '::> *' its children, '::p' its paragraphs).");
      return;
    }

    foreach ($styles as $relative => $entry) {
      $relative = (string) $relative;
      $entry_path = $relative === '' ? $path : "$path.$relative";

      if ($relative === '' || str_starts_with($relative, '::')) {
        $key = $root_key . $relative;
      }
      elseif (!$container && str_starts_with($relative, ':')) {
        // A block field's entry: ':{field}' (and its part compounds) is the
        // tree's field-local remainder, mapping onto block:{uuid}:{field}.
        $key = $root_key . $relative;
      }
      elseif ($container && str_starts_with($relative, 'region:')) {
        // The retired pre-container form keeps its corrective: a
        // mental-model retirement (regions stopped being addressable), so
        // its teaching hint lives in the spine's shared retired-grammar map.
        $state['errors'][] = new BlueprintError($entry_path, $state['code'], sprintf('The "%s" relative style form is retired: regions are not addressable under the container model.', $relative), [], BlueprintOperations::RETIRED_GRAMMAR['region:NAME']);
        continue;
      }
      else {
        $hint = $container
          ? "A container's styles key by '' for the container itself and '::' plus a relative selector ('::.class-name' a part, '::> *' its children, '::> pb-text' the text blocks among them) for what is inside it."
          : "A block's styles key by '' for the block itself, ':field_name' for a field, and '::' plus a relative selector ('::.class-name' a part, '::p' its paragraphs) for what is inside it.";
        $state['errors'][] = new BlueprintError($entry_path, $state['code'], sprintf('"%s" is not an element-relative style key.', $relative), [], $hint);
        continue;
      }

      if (!is_array($entry) || $entry === [] || array_is_list($entry)) {
        $state['errors'][] = new BlueprintError($entry_path, $state['code'], 'A styles entry is a layered map: an optional "variants" list plus condition-keyed property maps.', [], 'Provide {"variants": ["variant_id"]} and/or condition keys such as {"default": {"padding": "2rem"}}.');
        continue;
      }
      if (array_key_exists('variant', $entry)) {
        $state['errors'][] = new BlueprintError("$entry_path.variant", $state['code'], 'The singular "variant" key is no longer part of the grammar.', [], 'Reference variants with the "variants" list: {"variants": ["variant_id"]}. It composes one variant per axis and layers with condition maps.');
        continue;
      }

      $authored = [];
      if (array_key_exists('variants', $entry)) {
        $variants = $entry['variants'];
        unset($entry['variants']);
        if (!is_array($variants) || !array_is_list($variants) || $variants === []) {
          $state['errors'][] = new BlueprintError("$entry_path.variants", $state['code'], '"variants" is a non-empty list of variant machine names.', [], 'Reference each variant by its machine name, e.g. {"variants": ["card"]}; at most one per axis.');
          continue;
        }
        foreach ($variants as $index => $reference) {
          $name = self::authoredVariant($reference);
          if ($name === FALSE) {
            $state['errors'][] = new BlueprintError("$entry_path.variants.$index", $state['code'], 'A variant reference is a machine name.', [], 'Reference each variant by its machine name, e.g. {"variants": ["card"]}.');
            continue;
          }
          if ($name === NULL) {
            // Derived data (a fragment a composition distributed, a wear
            // with an origin): the root's own apply re-creates it.
            continue;
          }
          $state['style_ops'][] = ['op' => 'apply_variant', 'key' => $key, 'variant' => $name];
          $state['style_paths'][] = "$entry_path.variants.$index";
          $authored[] = $name;
        }
      }

      // One update_style per condition (the operation's own granularity).
      // Condition names and property values are the spine's to validate,
      // so a retired freeform key flows through and fails there like any
      // other malformed condition.
      foreach ($entry as $condition => $properties) {
        $condition = (string) $condition;
        $state['style_ops'][] = [
          'op' => 'update_style',
          'key' => $key,
          'condition' => $condition,
          'properties' => $properties,
        ];
        $state['style_paths'][] = "$entry_path.$condition";
      }
      $state['styles'][$key] = ($authored === [] ? [] : ['variants' => $authored]) + $entry;
    }
  }

  /**
   * The authored root wearing a variant reference spells, if any.
   *
   * The map stores structured references ({variant, fragment, via}); the
   * tree grammar also speaks bare machine names. A '' fragment with no
   * origin is an ordinary root wearing and answers its name; a non-empty
   * fragment or an origin is derived data and answers NULL (dropped
   * without a warning: nothing inexpressible is lost, the composition
   * root's own apply re-distributes it). Anything else is malformed.
   * Public and static: the one reading of a reference, which the fit
   * derivation (BlueprintRequirements) walks a tree with too.
   *
   * @return string|null|false
   *   The machine name, NULL for derived data, FALSE when malformed.
   */
  public static function authoredVariant(mixed $reference): string|null|false {
    if (is_string($reference)) {
      return $reference === '' ? FALSE : $reference;
    }
    if (!is_array($reference) || !is_string($reference['variant'] ?? NULL) || $reference['variant'] === '') {
      return FALSE;
    }
    if (($reference['fragment'] ?? '') !== '') {
      return NULL;
    }
    if (is_string($reference['via'] ?? NULL) && $reference['via'] !== '') {
      return NULL;
    }
    return $reference['variant'];
  }

}
