<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

use Drupal\Component\Uuid\Uuid;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Dialect\PageOnlyOperationsInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;

/**
 * Registers the instantiate_stencil batch operation.
 *
 * Instantiation STAMPS a stencil: the operation expands the stencil's
 * element tree into the batch operations that already exist on the spine
 * and runs them as a nested batch, so a stencil placement IS a normal
 * palette placement, just scripted by a definition:
 * - a CONTAINER node (the usual root: stencils are composed sections)
 *   becomes add_section at the given location — the ONE placement grammar,
 *   so the same stencil lands as a top-level band (anchor the root entity
 *   or a sibling section) or nested inside any section (anchor a section
 *   with 'inside') — born as the node's layout_id (carrying its
 *   layout_settings, patched over the plugin defaults) followed by an
 *   update_element for its label,
 * - every block node becomes an add_element placed 'inside' its parent
 *   container (fresh UUID minted here; pinned fields applied — media and
 *   entity references included; absent fields sample-populate through the
 *   same populate: 'sample' path a palette drop uses),
 * - container nodes inside children recurse: containers all the way down.
 *   A container layout's ordered flow is its `children` directly (location
 *   carries no region, exactly like the dialect's DOM expansion); a
 *   multi-region layout's named slots ride REGION nodes, whose children
 *   land with the region name in the location,
 * - every co-located styles entry becomes an update_style or apply_variant
 *   against the absolute selectable key minted for its node — the full
 *   layered entries travel, variant references and overrides alike, re-keyed
 *   onto the fresh uuids. Style operations trail the ENTIRE structural
 *   expansion (see apply()): a composition variant's apply resolves its
 *   slot paths over the subtree once, at the mutation moment (bind-once),
 *   so the whole tree must exist before the first style op runs,
 * - the placed root carries the provenance stamp from birth: the source
 *   stencil ID rides the root create op — add_element's additional data
 *   (block roots) or add_section's stencil parameter (container roots) — so
 *   the stamp exists the moment the root does, and the trailing style ops
 *   (a stencil's own owned-variant applies) already see it.
 *
 * Because the expansion only speaks the existing grammar, everything the
 * spine already enforces (block type validity, placement restrictions,
 * field validation, style-guide validation, variant scope matching) applies
 * to stencils with zero new validation surface, and batch rollback semantics
 * make a failed instantiate leave the entity untouched.
 *
 * The stencil node grammar IS the spine's lean node tree (NodeTreeBuilder's
 * shape) minus per-placement facts: kind-discriminated container / region /
 * block nodes with ONE `children` axis, no uuids, no provenance, no derived
 * keys. Storing the tree — never dialect text — keeps saved stencils
 * independent of the model-facing serialization's version (the dialect
 * version lives on DialectCatalog, not in stored artifacts). Relative style
 * keys are the tree's local-remainder grammar, translated to
 * daedalus_ui selectable keys:
 * - a block node's '' maps to block:{uuid}, ':{field}' to
 *   block:{uuid}:{field}, '::.class' to block:{uuid}::.class,
 * - a container node's '' maps to container:{uuid}, '::.class' to
 *   container:{uuid}::.class. The legacy 'region:NAME' relative form is
 *   RETIRED: regions are not addressable under the container model, so it
 *   is refused here with the container-vocabulary corrective (stored
 *   stencils were rewritten by the container migration; a module-shipped
 *   stencil still spelling it is the module's bug to fix). The pre-tree
 *   LB-shaped grammar (kind-less section nodes with a `regions` map) is
 *   refused the same way: stored content stencils were rewritten by the
 *   node-tree migration.
 * The style operations are daedalus_stylish's; a stencil that ships styles on a site
 * without daedalus_stylish fails loudly with INVALID_OPERATION.
 *
 * Discovery validated the definition envelope; this handler owns the element
 * TREE grammar (node shapes, key vocabulary, relative style keys) and
 * validates the whole tree before executing anything. The VALUES flow
 * through the spine untouched.
 */
class StencilOperationHandler implements PageOnlyOperationsInterface {

  /**
   * The key carrying a placement's provenance stamp.
   *
   * Instantiation stamps the source stencil ID onto the placed root — a
   * block root's additional data (the opaque per-placement slot
   * add_element accepts) or a container root's third-party settings (under
   * this key as the provider, key 'stencil'; a top-level band has no wrapper
   * component, the section value is the uniform slot at every depth). The
   * stamp is a BIRTH FACT: it rides the root create op (add_element's
   * additional data / add_section's stencil parameter), never a post-hoc
   * fixup, because it is load-bearing the moment the root exists — the
   * update-stencil verb offer reads it, and stencil-owned variants read it
   * to decide where they offer (a stencil's own shipped applies trail the
   * structural ops inside the same batch). A placement never re-syncs from
   * its stencil, and a stamp whose stencil was deleted or is file-shipped
   * simply resolves to nothing. StencilExporter never reads additional data
   * or section third-party settings, so the stamp cannot leak into a
   * captured stencil (a stencil is not a placement).
   *
   * Aliases the base LayoutSerializer constant: base owns the key name so
   * the serialization spine can surface the stamp as the element's
   * read-only `stencil` key without depending on this optional submodule.
   */
  public const PROVENANCE_KEY = LayoutSerializer::STENCIL_PROVENANCE_KEY;

  /**
   * The keys a stencil block node consists of.
   */
  protected const BLOCK_KEYS = ['kind', 'block_type', 'block_fields', 'styles'];

  /**
   * The keys a stencil container node consists of.
   */
  protected const CONTAINER_KEYS = ['kind', 'layout_id', 'label', 'layout_settings', 'children', 'styles'];

  /**
   * The keys a stencil region node consists of.
   */
  protected const REGION_KEYS = ['kind', 'region', 'children'];

  /**
   * The corrective for the retired LB-shaped (pre-tree) stencil grammar.
   */
  protected const TREE_GRAMMAR_HINT = 'Stencil elements are lean tree nodes: {kind: container, layout_id, children: [...]} with {kind: region, region, children} nodes for multi-region layouts, and {kind: block, block_type, block_fields}. Stored content stencils were rewritten by the node-tree migration; a module-shipped stencil still spelling the kind-less section/regions grammar is the module\'s bug to fix.';

  /**
   * Constructs the handler.
   *
   * @param \Drupal\daedalus_blueprint_stencil\StencilDiscoveryInterface $discovery
   *   The stencil discovery.
   * @param \Drupal\Component\Uuid\UuidInterface $uuid
   *   The UUID generator.
   * @param \Closure $operations
   *   Service closure resolving daedalus_blueprint.operations. A closure
   *   because the dependency is circular by construction: operations owns
   *   the handler registry that collects this handler.
   * @param \Drupal\Core\Layout\LayoutPluginManagerInterface|null $layoutManager
   *   The layout plugin manager, when layout plugins are available.
   */
  public function __construct(
    protected StencilDiscoveryInterface $discovery,
    protected UuidInterface $uuid,
    protected \Closure $operations,
    protected ?LayoutPluginManagerInterface $layoutManager = NULL,
  ) {}

  /**
   * Whether a stencil node is a container node.
   *
   * The tree's one discriminator: nodes declare their kind. Mirrors
   * NodeTreeBuilder's shape, where a section IS a container node.
   */
  public static function isContainerNode(mixed $node): bool {
    return is_array($node) && ($node['kind'] ?? NULL) === 'container';
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations(): array {
    return ['instantiate_stencil'];
  }

  /**
   * {@inheritdoc}
   *
   * Instantiation targets the layout tree, and the page language stamps a
   * stencil as <pb-stencil> inside an insert fragment, so on a
   * page-language site the JSON spelling leaves eb_edit's roll-call.
   */
  public function getPageOnlyOperations(): array {
    return $this->getOperations();
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): array {
    return [
      'instantiate_stencil' => 'with "stencil" ID from the schema\'s materials.stencils, "location" anchor object, optional root "uuid"; stamps a fresh independent copy (the page language\'s <pb-stencil> element is the taught form)',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(FieldableEntityInterface $entity, array $operation, array $options = []): BlueprintResult {
    $id = $operation['stencil'] ?? NULL;
    if (!is_string($id) || $id === '') {
      return BlueprintResult::structuralFailure([
        new BlueprintError('stencil', 'MISSING_PARAMETER', 'A non-empty stencil is required.', [], 'Provide the ID of the stencil to instantiate.'),
      ]);
    }
    $definition = $this->discovery->getStencil($id);
    if ($definition === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('stencil', 'STENCIL_NOT_FOUND', sprintf('"%s" is not an available stencil.', $id), [
          'available' => array_keys($this->discovery->getStencils()),
        ], 'Use one of the available stencil IDs.'),
      ]);
    }
    $provider = $definition['provider'];

    $location = $operation['location'] ?? [];
    if (!is_array($location)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('location', 'INVALID_LOCATION', 'The location is not a location object.', [], 'Both root kinds take the ONE placement grammar: {anchor, placement: before|after|inside} with region only for multi-region layouts.'),
      ]);
    }

    // Creates honor client-generated UUIDs (the batch grammar's optimistic
    // seam); a stencil placement is a create whose addressable element is the
    // root (a container or a block), so an operation-level uuid names that
    // root. Nested UUIDs stay handler-minted: the tree's internals are not
    // addressable until the response reports them.
    $root_uuid = $operation['uuid'] ?? NULL;
    if ($root_uuid !== NULL && (!is_string($root_uuid) || !Uuid::isValid($root_uuid))) {
      return BlueprintResult::structuralFailure([
        BlueprintError::invalidUuid('uuid', $root_uuid),
      ]);
    }

    // Expand the whole tree BEFORE executing anything, so a grammar error
    // anywhere in the shipped definition fails the placement without
    // touching the entity.
    $ops = [];
    $paths = [];
    $style_ops = [];
    $style_paths = [];
    $errors = [];
    $section_root = self::isContainerNode($definition['element']);
    $this->expandNode($definition['element'], $location, 'element', $ops, $paths, $style_ops, $style_paths, $errors, $root_uuid);
    if ($errors) {
      return BlueprintResult::structuralFailure($this->withStencilCoordinates($errors, $id, $provider));
    }
    // Style operations run after the ENTIRE structural expansion: a
    // composition variant's apply_variant resolves its slot paths over the
    // staged subtree once, at the mutation moment (bind-once), so the whole
    // tree must exist before the root apply distributes its fragment
    // references — over an empty section nothing would bind, ever. Within
    // the style stream the tree order (a node's styles before its
    // descendants') reproduces the captured source's final state: a child's
    // own shipped reference lands after the root apply and replaces the
    // distributed same-axis fragment, exactly as the newest application won
    // the axis on the source.
    $ops = array_merge($ops, $style_ops);
    $paths = array_merge($paths, $style_paths);
    // The root op's own error paths (location.*) point at THIS operation's
    // parameter space, so the root op keeps no stencil prefix.
    $paths[0] = '';
    // Provenance is a birth fact riding the root create op — the root op is
    // always $ops[0]: expansion appends the root op before any descent.
    // Block roots carry the stamp in add_element's additional data;
    // container roots pass add_section's stencil parameter (the section
    // value's third-party settings are the uniform slot at every depth).
    // Both land the stamp at creation, so the trailing style ops (a
    // stencil's own owned-variant applies) run against a stamped root.
    if ($section_root) {
      $ops[0]['stencil'] = $id;
    }
    else {
      $ops[0]['additional'][self::PROVENANCE_KEY] = $id;
    }

    // A stencil placement is a total statement: every element it expands
    // to wears exactly what the definition captured, so the nested batch
    // declares itself dressed and birth subscribers stamp no defaults
    // under it (a blank root would otherwise wear the layout's house
    // defaults beneath the captured variants, and every sampled block its
    // block type's). populate: 'sample' on the block ops means field
    // sampling only.
    $result = ($this->operations)()->batchOperations($entity, $ops, ['dressed' => TRUE]);
    if ($result->isSuccess()) {
      return $result;
    }

    // The nested batch already rolled the entity back; its error indexes
    // refer to the expanded operations, so re-root each error onto the
    // stencil definition's own coordinate space.
    $remapped = [];
    foreach ($result->getErrors() as $error) {
      $prefix = $paths[$error->getOperationIndex() ?? -1] ?? '';
      $path = $error->getPath();
      if ($prefix !== '') {
        $path = $path === '' ? $prefix : "$prefix.$path";
      }
      $remapped[] = new BlueprintError($path, $error->getCode(), $error->getMessage(), $error->getContext(), $error->getHint());
    }
    $remapped = $this->withStencilCoordinates($remapped, $id, $provider);

    return $result->getPhase() === 'semantic'
      ? BlueprintResult::semanticFailure($remapped, $result->getWarnings())
      : BlueprintResult::structuralFailure($remapped);
  }

  /**
   * Expands one stencil tree node, dispatching on its declared kind.
   *
   * @param mixed $node
   *   The node from the stencil definition.
   * @param array $location
   *   The location the node lands at: the root node's location comes from
   *   the operation; nested nodes anchor 'inside' their parent container
   *   (with the region name when they ride a region node).
   * @param string $path
   *   The node's path within the stencil definition, for error coordinates.
   * @param array $ops
   *   The expanded structural operations, appended in execution order.
   * @param array $paths
   *   The stencil path of each expanded operation, parallel to $ops.
   * @param array $style_ops
   *   The expanded style operations, appended in tree order. They execute
   *   after ALL structural operations (see apply()).
   * @param array $style_paths
   *   The stencil path of each style operation, parallel to $style_ops.
   * @param array $errors
   *   Grammar errors, appended.
   * @param string|null $uuid
   *   A caller-provided UUID for this node (the operation's optional root
   *   uuid), or NULL to mint one.
   */
  protected function expandNode(mixed $node, array $location, string $path, array &$ops, array &$paths, array &$style_ops, array &$style_paths, array &$errors, ?string $uuid = NULL): void {
    if (!is_array($node) || $node === [] || array_is_list($node)) {
      $errors[] = new BlueprintError($path, 'INVALID_STENCIL', 'A stencil node is a map declaring its kind.', [], self::TREE_GRAMMAR_HINT);
      return;
    }
    if (array_key_exists('layout', $node)) {
      // The dead pre-D shape, refused with a pointer at the one grammar:
      // blocks never hold layouts; a container IS its own node.
      $errors[] = new BlueprintError("$path.layout", 'INVALID_STENCIL', 'Blocks do not hold layouts.', [], 'Nest a container by placing a container node (kind: container, layout_id, children) in the parent\'s children instead of a layout list on a block.');
      return;
    }
    switch ($node['kind'] ?? NULL) {
      case 'container':
        $this->expandContainerNode($node, $location, $path, $ops, $paths, $style_ops, $style_paths, $errors, $uuid);
        return;

      case 'block':
        $this->expandBlockNode($node, $location, $path, $ops, $paths, $style_ops, $style_paths, $errors, $uuid);
        return;

      case 'region':
        $errors[] = new BlueprintError("$path.kind", 'INVALID_STENCIL', 'A region node only appears among a multi-region container\'s children.', [], 'Region nodes group a container layout\'s named slots; they are not placeable elements.');
        return;
    }
    $errors[] = new BlueprintError("$path.kind", 'INVALID_STENCIL', 'Every stencil node declares its kind: "container" or "block".', [], self::TREE_GRAMMAR_HINT);
  }

  /**
   * Expands a stencil container node into batch operations.
   *
   * See expandNode() for the shared parameters.
   */
  protected function expandContainerNode(array $node, array $location, string $path, array &$ops, array &$paths, array &$style_ops, array &$style_paths, array &$errors, ?string $uuid = NULL): void {
    if ($unknown = array_diff(array_keys($node), self::CONTAINER_KEYS)) {
      $hint = in_array('regions', $unknown, TRUE)
        ? self::TREE_GRAMMAR_HINT
        : 'A container node consists of kind, layout_id, label, layout_settings, children, and styles.';
      $errors[] = new BlueprintError($path, 'INVALID_STENCIL', sprintf('Unknown key(s) %s on the container at "%s".', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $path), [], $hint);
    }
    $layout_id = $node['layout_id'] ?? NULL;
    if (!is_string($layout_id) || $layout_id === '') {
      $errors[] = new BlueprintError("$path.layout_id", 'INVALID_STENCIL', 'Every stencil container names its layout_id.', [], 'Set layout_id to a layout plugin ID.');
      return;
    }
    $label = $node['label'] ?? NULL;
    if ($label !== NULL && (!is_string($label) || $label === '')) {
      $errors[] = new BlueprintError("$path.label", 'INVALID_STENCIL', 'label is a non-empty string.', [], 'Omit the key for a container named after its layout.');
      $label = NULL;
    }
    // Opaque-but-structural plugin settings (a two-column layout's
    // column_widths): applied verbatim through add_section's
    // layout_settings patch; the plugin itself owns what the values mean.
    $layout_settings = $node['layout_settings'] ?? [];
    if (!is_array($layout_settings) || (array_is_list($layout_settings) && $layout_settings !== [])) {
      $errors[] = new BlueprintError("$path.layout_settings", 'INVALID_STENCIL', 'layout_settings is a map of layout plugin settings.', [], 'Provide settings the layout plugin understands (e.g. column_widths for two-column layouts); omitted keys use the plugin defaults.');
      $layout_settings = [];
    }
    $children = $node['children'] ?? [];
    if (!is_array($children) || !array_is_list($children)) {
      $errors[] = new BlueprintError("$path.children", 'INVALID_STENCIL', 'children is a list of nodes.', [], 'Provide the container\'s blocks and nested containers as a list; a multi-region layout\'s slots are region nodes ({kind: region, region, children}).');
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

    $uuid ??= $this->uuid->generate();
    $add_section = [
      'op' => 'add_section',
      'location' => $location,
      'uuid' => $uuid,
      'layout' => $layout_id,
    ];
    if ($layout_settings !== []) {
      $add_section['layout_settings'] = $layout_settings;
    }
    $ops[] = $add_section;
    $paths[] = $path;
    if ($label !== NULL) {
      $ops[] = ['op' => 'update_element', 'element' => $uuid, 'properties' => ['label' => $label]];
      $paths[] = "$path.label";
    }

    // The node's styles collect into the deferred style stream in tree
    // order — this container's before its descendants'. Every style op runs
    // after the full structural expansion (see apply()). The container is
    // born as the stencil's layout_id, so its root styles (a layout-scoped
    // variant, a container part) validate against the right layout from
    // the first op.
    $this->expandStyles($node['styles'] ?? NULL, "container:$uuid", $uuid, $path, $style_ops, $style_paths, $errors);

    // ONE child axis: element nodes ride directly (a container layout's
    // ordered flow — the location carries no region, same as the dialect's
    // DOM expansion), region nodes carry a multi-region layout's named
    // slots. The two shapes never mix in one children list.
    $has_region_children = FALSE;
    $has_element_children = FALSE;
    foreach ($children as $index => $child) {
      $child_path = "$path.children[$index]";
      if (is_array($child) && ($child['kind'] ?? NULL) === 'region') {
        $has_region_children = TRUE;
        $this->expandRegionNode($child, $uuid, $layout_id, $valid_regions, $child_path, $ops, $paths, $style_ops, $style_paths, $errors);
        continue;
      }
      $has_element_children = TRUE;
      $this->expandNode(
        $child,
        ['anchor' => $uuid, 'placement' => 'inside'],
        $child_path,
        $ops,
        $paths,
        $style_ops,
        $style_paths,
        $errors,
      );
    }
    if ($has_region_children && $has_element_children) {
      $errors[] = new BlueprintError("$path.children", 'INVALID_STENCIL', 'Region nodes and element nodes do not mix in one children list.', [], 'A container layout\'s flow is element nodes directly; a multi-region layout\'s slots are ALL region nodes.');
    }
  }

  /**
   * Expands a stencil region node's children into batch operations.
   *
   * Region nodes are grouping, not elements: no uuid, no styles, no ops of
   * their own. Their children land 'inside' the containing section with
   * the region name in the location.
   *
   * @param array $node
   *   The region node.
   * @param string $section_uuid
   *   The containing section's minted uuid.
   * @param string $layout_id
   *   The containing section's layout, for error messages.
   * @param string[]|null $valid_regions
   *   The layout's region names when the plugin resolved, NULL otherwise.
   * @param string $path
   *   The node's path within the stencil definition.
   * @param array $ops
   *   The expanded structural operations, appended in execution order.
   * @param array $paths
   *   The stencil path of each expanded operation, parallel to $ops.
   * @param array $style_ops
   *   The expanded style operations, appended in tree order.
   * @param array $style_paths
   *   The stencil path of each style operation, parallel to $style_ops.
   * @param array $errors
   *   Grammar errors, appended.
   */
  protected function expandRegionNode(array $node, string $section_uuid, string $layout_id, ?array $valid_regions, string $path, array &$ops, array &$paths, array &$style_ops, array &$style_paths, array &$errors): void {
    if ($unknown = array_diff(array_keys($node), self::REGION_KEYS)) {
      $errors[] = new BlueprintError($path, 'INVALID_STENCIL', sprintf('Unknown key(s) %s on the region node at "%s".', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $path), [], 'A region node consists of kind, region, and children; regions are grouping, not addressable elements.');
    }
    $region = $node['region'] ?? NULL;
    if (!is_string($region) || $region === '') {
      $errors[] = new BlueprintError("$path.region", 'INVALID_STENCIL', 'Every region node names its region.', [], 'Set region to a region the containing layout defines.');
      return;
    }
    if ($valid_regions !== NULL && !in_array($region, $valid_regions, TRUE)) {
      $errors[] = new BlueprintError("$path.region", 'INVALID_STENCIL', sprintf('Region "%s" does not exist on layout "%s".', $region, $layout_id), ['available' => $valid_regions], 'Use a region the containing layout defines.');
      return;
    }
    $children = $node['children'] ?? [];
    if (!is_array($children) || !array_is_list($children)) {
      $errors[] = new BlueprintError("$path.children", 'INVALID_STENCIL', 'A region node holds a list of nodes.', [], 'Provide the region\'s blocks and nested containers as a list.');
      return;
    }
    foreach ($children as $index => $child) {
      $this->expandNode(
        $child,
        ['anchor' => $section_uuid, 'placement' => 'inside', 'region' => $region],
        "$path.children[$index]",
        $ops,
        $paths,
        $style_ops,
        $style_paths,
        $errors,
      );
    }
  }

  /**
   * Expands a stencil block node into batch operations.
   *
   * See expandNode() for the shared parameters.
   */
  protected function expandBlockNode(array $node, array $location, string $path, array &$ops, array &$paths, array &$style_ops, array &$style_paths, array &$errors, ?string $uuid = NULL): void {
    if ($unknown = array_diff(array_keys($node), self::BLOCK_KEYS)) {
      $errors[] = new BlueprintError($path, 'INVALID_STENCIL', sprintf('Unknown key(s) %s on the block node at "%s".', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $path), [], 'A block node consists of kind, block_type, block_fields, and styles.');
    }
    $block_type = $node['block_type'] ?? NULL;
    if (!is_string($block_type) || $block_type === '') {
      $errors[] = new BlueprintError("$path.block_type", 'INVALID_STENCIL', 'Every stencil block node names its block_type.', [], 'Set block_type to an inline block type machine name.');
      return;
    }
    $block_fields = $node['block_fields'] ?? [];
    if (!is_array($block_fields) || (array_is_list($block_fields) && $block_fields !== [])) {
      $errors[] = new BlueprintError("$path.block_fields", 'INVALID_STENCIL', 'block_fields is a map of field names to pinned values.', [], 'Omit a field to sample-populate it at placement.');
      $block_fields = [];
    }

    $uuid ??= $this->uuid->generate();
    $op = [
      'op' => 'add_element',
      'location' => $location,
      'block_type' => $block_type,
      'uuid' => $uuid,
      'populate' => 'sample',
    ];
    if ($block_fields !== []) {
      $op['properties'] = $block_fields;
    }
    $ops[] = $op;
    $paths[] = $path;

    $this->expandStyles($node['styles'] ?? NULL, "block:$uuid", NULL, $path, $style_ops, $style_paths, $errors);
  }

  /**
   * Expands a node's co-located styles into style operations.
   *
   * Translates the element-relative keys (the tree's local-remainder
   * grammar) onto the absolute selectable keys minted for the node, and
   * emits daedalus_stylish's update_style / apply_variant operations, so every
   * shipped style flows through the same StyleMapValidator as any other
   * style write. The ops land in the deferred style stream — they execute
   * only after the whole subtree's structural operations (see apply()).
   *
   * @param mixed $styles
   *   The node's styles map, or NULL when the node ships none.
   * @param string $root_key
   *   The node's absolute selectable key (block:{uuid} or container:{uuid}).
   * @param string|null $section_uuid
   *   The section UUID when the node is a container (selects the
   *   container-vocabulary correctives), NULL for block nodes.
   * @param string $node_path
   *   The node's path within the stencil definition.
   * @param array $style_ops
   *   The expanded style operations, appended.
   * @param array $style_paths
   *   The stencil path of each style operation, parallel to $style_ops.
   * @param array $errors
   *   Grammar errors, appended.
   */
  protected function expandStyles(mixed $styles, string $root_key, ?string $section_uuid, string $node_path, array &$style_ops, array &$style_paths, array &$errors): void {
    if ($styles === NULL) {
      return;
    }
    $path = "$node_path.styles";
    if (!is_array($styles) || (array_is_list($styles) && $styles !== [])) {
      $errors[] = new BlueprintError($path, 'INVALID_STENCIL', 'styles is a map of element-relative keys to style entries.', [], "Key by '' for the element itself and '::.class-name' for parts.");
      return;
    }

    foreach ($styles as $relative => $entry) {
      $relative = (string) $relative;
      $entry_path = $relative === '' ? $path : "$path.$relative";

      if ($relative === '' || str_starts_with($relative, '::')) {
        $key = $root_key . $relative;
      }
      elseif ($section_uuid === NULL && str_starts_with($relative, ':')) {
        // A block field's entry: ':{field}' (and its part compounds) is the
        // tree's field-local remainder, mapping onto block:{uuid}:{field}.
        $key = $root_key . $relative;
      }
      elseif ($section_uuid !== NULL && str_starts_with($relative, 'region:')) {
        // The retired pre-container form keeps its corrective: a
        // mental-model retirement (regions stopped being addressable), so
        // its teaching hint lives in the spine's shared retired-grammar map.
        $errors[] = new BlueprintError($entry_path, 'INVALID_STENCIL', sprintf('The "%s" relative style form is retired: regions are not addressable under the container model.', $relative), [], BlueprintOperations::RETIRED_GRAMMAR['region:NAME']);
        continue;
      }
      else {
        $hint = $section_uuid === NULL
          ? "A block's styles key by '' for the block itself, ':field_name' for a field, and '::.class-name' for its parts."
          : "A container's styles key by '' for the container itself and '::.class-name' for its parts.";
        $errors[] = new BlueprintError($entry_path, 'INVALID_STENCIL', sprintf('"%s" is not an element-relative style key.', $relative), [], $hint);
        continue;
      }

      if (!is_array($entry) || $entry === [] || array_is_list($entry)) {
        $errors[] = new BlueprintError($entry_path, 'INVALID_STENCIL', 'A styles entry is a layered map: an optional "variants" list plus condition-keyed property maps.', [], 'Provide {"variants": ["variant_id"]} and/or condition keys such as {"default": {"padding": "2rem"}}.');
        continue;
      }

      if (array_key_exists('variant', $entry)) {
        $errors[] = new BlueprintError("$entry_path.variant", 'INVALID_STENCIL', 'The singular "variant" key is no longer part of the grammar.', [], 'Reference variants with the "variants" list: {"variants": ["variant_id"]}. It composes one variant per axis and layers with condition maps.');
        continue;
      }

      if (array_key_exists('variants', $entry)) {
        $variants = $entry['variants'];
        unset($entry['variants']);
        $valid = is_array($variants) && array_is_list($variants) && $variants !== []
          && array_filter($variants, static fn ($reference) => !is_string($reference) || $reference === '') === [];
        if (!$valid) {
          $errors[] = new BlueprintError("$entry_path.variants", 'INVALID_STENCIL', '"variants" is a non-empty list of variant machine names.', [], 'Reference each variant by its machine name, e.g. {"variants": ["card"]}; at most one per axis.');
          continue;
        }
        foreach ($variants as $index => $reference) {
          $style_ops[] = ['op' => 'apply_variant', 'key' => $key, 'variant' => $reference];
          $style_paths[] = "$entry_path.variants.$index";
        }
      }

      // One update_style per condition (the operation's own granularity).
      // Condition names and property values are the spine's to validate,
      // so the retired freeform key flows through and fails there like
      // any other malformed condition.
      foreach ($entry as $condition => $properties) {
        $condition = (string) $condition;
        $style_ops[] = [
          'op' => 'update_style',
          'key' => $key,
          'condition' => $condition,
          'properties' => $properties,
        ];
        $style_paths[] = "$entry_path.$condition";
      }
    }
  }

  /**
   * Stamps stencil coordinates onto errors.
   *
   * Instantiation errors surface far from the definition that caused them,
   * so every error names the stencil and the module shipping it (NULL for
   * a content stencil saved on this site).
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The errors.
   * @param string $id
   *   The stencil ID.
   * @param string|null $provider
   *   The module shipping the stencil, NULL for a content stencil.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The errors with stencil and provider context.
   */
  protected function withStencilCoordinates(array $errors, string $id, ?string $provider): array {
    return array_map(
      static fn (BlueprintError $error) => new BlueprintError(
        $error->getPath(),
        $error->getCode(),
        $error->getMessage(),
        $error->getContext() + ['stencil' => $id, 'provider' => $provider],
        $error->getHint(),
        $error->getOperationIndex(),
      ),
      $errors,
    );
  }

}
