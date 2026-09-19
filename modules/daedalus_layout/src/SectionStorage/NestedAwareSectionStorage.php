<?php

namespace Drupal\daedalus_layout\SectionStorage;

use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_layout\TreeIndexInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\layout_builder\SectionComponent;
use Symfony\Component\Routing\RouteCollection;
use Drupal\block_content\BlockContentInterface;
use Drupal\layout_builder\SectionListInterface;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\OverridesSectionStorageInterface;
use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\Component\Plugin\Context\ContextInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\layout_builder\Context\LayoutBuilderContextTrait;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;

/**
 * Decorator providing nested-aware capabilities to any section storage.
 *
 * The tree model is sections all the way down: a nested section is an
 * ordinary SectionComponent (the daedalus_layout_section container) whose
 * configuration embeds ONE Section::toArray() value, recursively. This
 * decorator wraps a root section storage and provides O(1) access to blocks
 * and sections anywhere in that tree via TreeIndex; embedded sections are
 * served through identity-stable EmbeddedSectionList adapters so core-shaped
 * forms and ops mutate the same Section objects the flush writes back.
 *
 * IMPORTANT: getSections()/getSection() operate at ROOT for compatibility
 * with core Layout Builder code, unless scoped via forSection().
 *
 * IMPORTANT: bubbleChangesToRoot() happens automatically at the tempstore
 * seam (LayoutTempstoreStrategy::set()); it flushes every embedded list
 * unconditionally, deepest-first.
 */
class NestedAwareSectionStorage implements NestedSectionStorageInterface {

  use LayoutBuilderContextTrait;
  use DependencySerializationTrait;

  /**
   * The root section storage.
   */
  protected SectionStorageInterface $rootStorage;

  /**
   * State shared between this wrapper and its scoped clones.
   *
   * An \ArrayObject so forSection() clones and the unscoped wrapper stay one
   * storage: the same TreeIndex, the same embedded-list registry (so
   * whichever scope hydrated a section, the same object flushes), and the
   * same dirty flag. Keys:
   * - treeIndex: ?TreeIndexInterface, the cached index.
   * - lists: \ArrayObject<string, EmbeddedSectionList>, keyed by
   *   container/section uuid (component uuid == section uuid, one identity).
   * - dirty: bool, whether identity stamping/repair needs persisting.
   */
  protected \ArrayObject $state;

  /**
   * The section uuid this storage is scoped to.
   *
   * When scoped, getSections()/getSection()/count() and mutation methods
   * operate on the section list containing that section instead of root:
   * for a nested section, the EmbeddedSectionList of its container (the
   * section itself is delta 0); for a root section, the root list.
   */
  protected ?string $sectionScope = NULL;

  /**
   * Constructs a NestedAwareSectionStorage.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $root_storage
   *   The root section storage to wrap.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|null $entityTypeManager
   *   The entity type manager. If NULL, will be lazy-loaded from container.
   */
  public function __construct(
    SectionStorageInterface $root_storage,
    protected ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {
    // Unwrap if already wrapped to avoid double-wrapping.
    if ($root_storage instanceof NestedSectionStorageInterface) {
      $this->rootStorage = $root_storage->getRootStorage();
    }
    else {
      $this->rootStorage = $root_storage;
    }
    $this->state = new \ArrayObject([
      'treeIndex' => NULL,
      'lists' => new \ArrayObject(),
      'dirty' => FALSE,
    ]);
  }

  /**
   * The shared embedded-list registry.
   *
   * @return \ArrayObject<string, \Drupal\daedalus_layout\SectionStorage\EmbeddedSectionList>
   *   The registry, keyed by container/section uuid.
   */
  protected function embeddedLists(): \ArrayObject {
    return $this->state['lists'];
  }

  /**
   * Wraps a section storage with nested-aware capabilities.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $storage
   *   The storage to wrap.
   *
   * @return \Drupal\daedalus_layout\NestedSectionStorageInterface
   *   The wrapped storage.
   */
  public static function wrap(SectionStorageInterface $storage): NestedSectionStorageInterface {
    if ($storage instanceof NestedSectionStorageInterface) {
      return $storage;
    }
    if ($storage instanceof OverridesSectionStorageInterface) {
      return new NestedAwareOverridesSectionStorage($storage);
    }
    if ($storage instanceof DefaultsSectionStorageInterface) {
      return new NestedAwareDefaultsSectionStorage($storage);
    }
    return new static($storage);
  }

  /**
   * {@inheritdoc}
   */
  public function getRootStorage(): SectionStorageInterface {
    return $this->rootStorage;
  }

  /**
   * Extracts the block_content entity from an InlineBlock plugin.
   *
   * Generic inline-block helper (media blocks, usage tracking, form alters);
   * not a traversal primitive.
   *
   * @param \Drupal\Core\Block\BlockPluginInterface $plugin
   *   The block plugin.
   *
   * @return \Drupal\block_content\BlockContentInterface|null
   *   The block_content entity, or NULL if not applicable.
   */
  public static function extractBlockContent(BlockPluginInterface $plugin): ?BlockContentInterface {
    if (!$plugin instanceof InlineBlock) {
      return NULL;
    }

    $configuration = $plugin->getConfiguration();

    if (!empty($configuration['block_serialized'])) {
      // Layout Builder's own inline-block payload (InlineBlock::blockSubmit()).
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
      return unserialize($configuration['block_serialized'], ['allowed_classes' => TRUE]);
    }

    if (!empty($configuration['block_revision_id'])) {
      return \Drupal::entityTypeManager()
        ->getStorage('block_content')
        ->loadRevision($configuration['block_revision_id']);
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function forSection(?string $section_uuid): static {
    // Return same instance if scope unchanged.
    if ($section_uuid === $this->sectionScope) {
      return $this;
    }

    // Create a scoped clone. The embedded-list registry is a shared object,
    // so every scope hydrates and flushes the same Section instances.
    $scoped = clone $this;
    $scoped->sectionScope = $section_uuid;
    return $scoped;
  }

  /**
   * {@inheritdoc}
   */
  public function getSectionScope(): ?string {
    return $this->sectionScope;
  }

  /**
   * Gets the section list for the current scope.
   *
   * @return \Drupal\layout_builder\SectionListInterface
   *   The section list for the current scope.
   *
   * @throws \OutOfBoundsException
   *   When the scoped section no longer exists. There is no root fallback:
   *   a silent one would redirect the caller's edits to the ROOT layout,
   *   data corruption dressed as resilience.
   */
  protected function getScopedSectionList(): SectionListInterface {
    if ($this->sectionScope === NULL) {
      return $this->rootStorage;
    }
    $list = $this->getSectionsFor($this->sectionScope);
    if ($list === NULL) {
      throw new \OutOfBoundsException(sprintf('The scoped section "%s" was not found in storage "%s". Refusing to fall back to the root section list: edits meant for a nested section must never land on the root layout.', $this->sectionScope, $this->rootStorage->getStorageId()));
    }
    return $list;
  }

  /**
   * Resolves a UUID to its containing section list and local coordinates.
   *
   * Centralizes the path-lookup, type-detection, and path-popping logic
   * shared by getComponent(), getSectionByUuid(), and getSectionFor().
   *
   * @param string $uuid
   *   The block or section UUID.
   *
   * @return array|null
   *   Array with keys:
   *   - 'sectionList': The SectionListInterface containing this item.
   *   - 'delta': The section delta (int).
   *   - 'blockUuid': The block UUID (string) for blocks, NULL for sections.
   *   Returns NULL if the UUID is not found.
   */
  protected function resolveUuid(string $uuid): ?array {
    $path = $this->getPath($uuid);
    if ($path === NULL) {
      return NULL;
    }

    if ($this->getTreeIndex()->isBlock($uuid)) {
      // Path: [..., delta, blockUuid].
      $block_uuid = array_pop($path);
      $delta = array_pop($path);
    }
    else {
      // Path: [..., delta].
      $block_uuid = NULL;
      $delta = array_pop($path);
    }

    $section_list = $this->getSectionListAtPath($path);
    if (!$section_list) {
      return NULL;
    }

    return [
      'sectionList' => $section_list,
      'delta' => (int) $delta,
      'blockUuid' => $block_uuid,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getBlockContentFromPlugin(BlockPluginInterface $plugin): ?BlockContentInterface {
    return static::extractBlockContent($plugin);
  }

  /**
   * {@inheritdoc}
   */
  public function getComponent(string $uuid): ?SectionComponent {
    $resolved = $this->resolveUuid($uuid);
    if (!$resolved || $resolved['blockUuid'] === NULL) {
      return NULL;
    }
    return $resolved['sectionList']->getSection($resolved['delta'])->getComponent($resolved['blockUuid']);
  }

  /**
   * {@inheritdoc}
   */
  public function getContainerComponent(string $section_uuid): ?SectionComponent {
    // Force an index build so the registry is populated and repaired.
    $this->getTreeIndex();
    $list = $this->embeddedLists()[$section_uuid] ?? NULL;
    if ($list === NULL || $list->isContainerRemoved()) {
      return NULL;
    }
    return $list->getContainerComponent();
  }

  /**
   * {@inheritdoc}
   */
  public function getSectionByUuid(string $uuid): ?Section {
    $resolved = $this->resolveUuid($uuid);
    if (!$resolved || $resolved['blockUuid'] !== NULL) {
      return NULL;
    }
    return $resolved['sectionList']->getSection($resolved['delta']);
  }

  /**
   * {@inheritdoc}
   */
  public function getSectionFor(string $uuid): ?Section {
    $resolved = $this->resolveUuid($uuid);
    if (!$resolved) {
      return NULL;
    }
    return $resolved['sectionList']->getSection($resolved['delta']);
  }

  /**
   * {@inheritdoc}
   */
  public function getSectionsFor(string $uuid): ?SectionListInterface {
    $path = $this->getPath($uuid);
    if ($path === NULL || $this->getTreeIndex()->isBlock($uuid)) {
      // Blocks contain no sections under the embedded-section model; only
      // sections resolve to a containing list.
      return NULL;
    }

    // Section path: [..., delta] - remove the delta to get the containing
    // list (the root storage, or the embedded list of the container).
    return $this->getSectionListAtPath(array_slice($path, 0, -1));
  }

  /**
   * {@inheritdoc}
   */
  public function getTreeIndex(): TreeIndexInterface {
    if ($this->state['treeIndex'] === NULL) {
      $this->state['treeIndex'] = $this->buildTreeIndex();
    }
    return $this->state['treeIndex'];
  }

  /**
   * {@inheritdoc}
   */
  public function getPath(string $uuid): ?array {
    return $this->getTreeIndex()->getPath($uuid);
  }

  /**
   * {@inheritdoc}
   */
  public function getNestedContext(string $uuid): ?array {
    $path = $this->getPath($uuid);
    if ($path === NULL) {
      return NULL;
    }

    $length = count($path);
    $index = $this->getTreeIndex();
    $is_block = $index->isBlock($uuid);

    if ($is_block) {
      // Block paths: [delta, blockUuid] (root) or
      // [..., sectionUuid, delta, blockUuid] (nested).
      // Need length > 2 to be nested.
      if ($length <= 2) {
        return NULL;
      }
      return [
        'sectionUuid' => $path[$length - 3],
        'localDelta' => $path[$length - 2],
      ];
    }

    // Section paths: [delta] (root) or [..., sectionUuid, delta] (nested,
    // where sectionUuid is the section's OWN uuid — the scope that makes a
    // nested section addressable as (list, delta) is its own container).
    // Need length > 1 to be nested.
    if ($length <= 1) {
      return NULL;
    }
    return [
      'sectionUuid' => $path[$length - 2],
      'localDelta' => $path[$length - 1],
    ];
  }

  /**
   * Gets the section list at a given path.
   *
   * Returns root storage for an empty path. Non-empty paths end at a
   * container uuid; the embedded-list registry (populated by the index
   * build) serves the identity-stable list.
   *
   * @param array $path
   *   The path array [delta, uuid, delta, uuid, ...]. Empty for root.
   *
   * @return \Drupal\layout_builder\SectionListInterface|null
   *   The section list at the path, or NULL if not found.
   */
  protected function getSectionListAtPath(array $path): ?SectionListInterface {
    if (empty($path)) {
      return $this->rootStorage;
    }
    // Ensure the registry reflects the current tree.
    $this->getTreeIndex();
    $container_uuid = end($path);
    return $this->embeddedLists()[$container_uuid] ?? NULL;
  }

  /**
   * Builds the tree index by recursively walking sections and blocks.
   *
   * @return \Drupal\daedalus_layout\TreeIndexInterface
   *   The built tree index.
   */
  protected function buildTreeIndex(): TreeIndexInterface {
    $index = new TreeIndex();
    $this->indexSectionList($this->rootStorage->getSections(), [], $index, 0);

    return $index;
  }

  /**
   * Recursively indexes a section list and the containers inside it.
   *
   * Sections without an daedalus_layout uuid are stamped here (the index is the one
   * walk guaranteed to see every section), and duplicate section uuids are
   * repaired deterministically: the same collision at the same path always
   * produces the same replacement, because indexing happens independently on
   * each request before tempstore is populated.
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The sections to index.
   * @param array $base_path
   *   The path prefix of the list these sections live in.
   * @param \Drupal\daedalus_layout\TreeIndexInterface $index
   *   The tree index to populate.
   * @param int $depth
   *   The nesting depth of this list (0 = root).
   */
  protected function indexSectionList(array $sections, array $base_path, TreeIndexInterface $index, int $depth): void {
    foreach ($sections as $delta => $section) {
      $section_path = array_merge($base_path, [$delta]);
      $section_uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
      if (empty($section_uuid)) {
        $section_uuid = \Drupal::service('uuid')->generate();
        $section->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);
        $this->state['dirty'] = TRUE;
      }
      elseif ($index->has($section_uuid)) {
        // Uuids are supposed to be unique yet here we are (duplicated
        // container config duplicates section uuids). Repair as we find
        // them. Only reachable for ROOT sections: embedded sections are
        // deduplicated in indexContainer() before recursing here.
        $section_uuid = $this->generateDeterministicUuid($section_uuid, $section_path);
        $section->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);
        $this->state['dirty'] = TRUE;
      }

      $index->add($section_uuid, $section_path, TreeIndexInterface::TYPE_SECTION, [
        'layout_id' => $section->getLayoutId(),
      ]);

      foreach ($section->getComponents() as $component) {
        // Nested-section containers are not indexed as blocks: the wrapper
        // component is plumbing, and its uuid IS the embedded section's
        // uuid, which indexContainer() indexes as a section.
        if (SectionBlock::isSectionComponent($component)) {
          $this->indexContainer($section, $component, $section_path, $index, $depth);
          continue;
        }

        $block_uuid = $component->getUuid();
        $block_path = array_merge($section_path, [$block_uuid]);

        $index->add($block_uuid, $block_path, TreeIndexInterface::TYPE_BLOCK, [
          'region' => $component->getRegion(),
          'weight' => $component->getWeight(),
          'plugin_id' => $component->getPluginId(),
        ]);
      }
    }
  }

  /**
   * Indexes one nested-section container and recurses into it.
   *
   * Enforces the one-identity invariant before recursing: component uuid ==
   * embedded section uuid. Repairs happen in lockstep so the two can never
   * drift:
   * - Section uuid missing: stamped from the component uuid.
   * - Section uuid present but differs from the component uuid (a copy that
   *   re-minted only the wrapper): the SECTION uuid wins — it is the
   *   user-facing identity styles and stencils key on — and the component is
   *   re-keyed to match.
   * - Uuid already in the index (duplicated container config): a
   *   deterministic replacement is minted for BOTH.
   *
   * @param \Drupal\layout_builder\Section $parent_section
   *   The section holding the container component.
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The container component.
   * @param array $parent_section_path
   *   The tree path of the parent section.
   * @param \Drupal\daedalus_layout\TreeIndexInterface $index
   *   The tree index to populate.
   * @param int $depth
   *   The nesting depth of the parent section's list.
   */
  protected function indexContainer(Section $parent_section, SectionComponent $component, array $parent_section_path, TreeIndexInterface $index, int $depth): void {
    $component_uuid = $component->getUuid();
    $list = $this->embeddedLists()[$component_uuid] ?? NULL;
    if ($list !== NULL && $list->getContainerComponent() !== $component) {
      // Same uuid, different component object: a duplicated container. The
      // registered list keeps serving the original; the duplicate gets its
      // own list and the collision repair below re-mints its identity.
      $list = NULL;
    }
    if ($list === NULL) {
      $list = new EmbeddedSectionList($parent_section, $component);
    }
    else {
      // The live component object may have moved between parents since the
      // last build; re-bind so removal/re-creation target reality.
      $list->rebind($parent_section, $component);
    }

    $section = $list->getSection(0);
    $stored_uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $final_uuid = $stored_uuid ?: $component_uuid;
    if ($index->has($final_uuid)) {
      $final_uuid = $this->generateDeterministicUuid($final_uuid, array_merge($parent_section_path, [$component_uuid]));
    }
    if ($stored_uuid !== $final_uuid) {
      $section->setThirdPartySetting('daedalus_layout', 'uuid', $final_uuid);
      $this->state['dirty'] = TRUE;
    }
    if ($component_uuid !== $final_uuid) {
      $list->rekeyContainer($final_uuid);
      $this->state['dirty'] = TRUE;
    }

    if (($this->embeddedLists()[$component_uuid] ?? NULL) === $list) {
      unset($this->embeddedLists()[$component_uuid]);
    }
    $this->embeddedLists()[$final_uuid] = $list;
    $list->setDepth($depth + 1);

    $this->indexSectionList($list->getSections(), array_merge($parent_section_path, [$final_uuid]), $index, $depth + 1);
  }

  /**
   * Generates a deterministic UUID from a source UUID and path.
   *
   * Uses SHA-1 hashing to produce the same output for the same input across
   * requests, formatted as a UUID v5 string. This ensures that section UUID
   * collision resolution is consistent between the initial page render and
   * subsequent AJAX requests.
   *
   * @param string $source_uuid
   *   The original (colliding) UUID.
   * @param array $path
   *   The path in the tree where the collision occurred.
   *
   * @return string
   *   A deterministic UUID derived from the source UUID and path.
   */
  protected function generateDeterministicUuid(string $source_uuid, array $path): string {
    $name = implode('/', $path);
    $hash = sha1($source_uuid . ':' . $name);

    // Format as a UUID: five hyphenated hex groups (8-4-4-4-12), version 5.
    return sprintf(
      '%s-%s-5%s-%s%s-%s',
      substr($hash, 0, 8),
      substr($hash, 8, 4),
      substr($hash, 13, 3),
      // Variant bits: 10xx (8, 9, a, or b).
      dechex(0x8 | (hexdec(substr($hash, 16, 1)) & 0x3)),
      substr($hash, 17, 3),
      substr($hash, 20, 12)
    );
  }

  /**
   * {@inheritdoc}
   */
  public function bubbleChangesToRoot(): void {
    // Rebuild against the current structure: re-binds every embedded list to
    // its component's current parent, refreshes depths, and stamps identity
    // on sections created since the last build.
    $this->state['treeIndex'] = NULL;
    $this->getTreeIndex();

    // Flush deepest-first and UNCONDITIONALLY: children serialize into their
    // parent section objects before the parents themselves serialize, and no
    // dirty flag exists because core forms mutate hydrated Sections in place
    // with no signal. Writing back what was read is idempotent.
    $lists = iterator_to_array($this->embeddedLists());
    usort($lists, fn(EmbeddedSectionList $a, EmbeddedSectionList $b) => $b->getDepth() <=> $a->getDepth());
    foreach ($lists as $list) {
      $list->flush();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateCache(): void {
    // Only invalidate the TreeIndex, NOT the embedded-list registry. The
    // TreeIndex is stale after structural changes (adding/removing/moving
    // sections), but the registry holds hydrated Sections carrying unflushed
    // mutations; the next index build re-binds them in place.
    $this->state['treeIndex'] = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isDirty(): bool {
    return $this->state['dirty'];
  }

  /**
   * {@inheritdoc}
   */
  public function markDirty(): void {
    $this->state['dirty'] = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllSections(): array {
    $all_sections = [];

    // Use the TreeIndex to efficiently get all sections.
    $index = $this->getTreeIndex();
    foreach ($index->getAllOfType(TreeIndexInterface::TYPE_SECTION) as $uuid => $data) {
      $section = $this->getSectionByUuid($uuid);
      if ($section) {
        $all_sections[] = $section;
      }
    }

    return $all_sections;
  }

  /**
   * The root Layout Builder entity, when the root storage exposes one.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The root entity, or NULL for storages without an entity context.
   */
  protected function getRootEntity(): ?FieldableEntityInterface {
    foreach (['layout_builder.entity', 'entity'] as $name) {
      try {
        $context = $this->rootStorage->getContext($name);
      }
      catch (\Exception) {
        continue;
      }
      if ($context->hasContextValue()) {
        $value = $context->getContextValue();
        if ($value instanceof FieldableEntityInterface) {
          return $value;
        }
      }
    }
    return NULL;
  }

  /**
   * Exposes the root entity under ROOT_ENTITY_CONTEXT_ID.
   *
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $contexts
   *   The contexts from the root storage.
   *
   * @return \Drupal\Core\Plugin\Context\ContextInterface[]
   *   The contexts with the root entity context added.
   */
  protected function addRootEntityContext(array $contexts): array {
    $root_entity = $this->getRootEntity();
    if ($root_entity) {
      $contexts[static::ROOT_ENTITY_CONTEXT_ID] = EntityContext::fromEntity($root_entity);
    }
    return $contexts;
  }

  /**
   * {@inheritdoc}
   */
  public function getSections() {
    return $this->getScopedSectionList()->getSections();
  }

  /**
   * {@inheritdoc}
   */
  public function getSection($delta) {
    return $this->getScopedSectionList()->getSection($delta);
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    return $this->getScopedSectionList()->count();
  }

  /**
   * {@inheritdoc}
   */
  public function appendSection(Section $section) {
    $this->getScopedSectionList()->appendSection($section);
    $this->invalidateCache();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function insertSection($delta, Section $section) {
    $this->getScopedSectionList()->insertSection($delta, $section);
    $this->invalidateCache();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function removeSection($delta) {
    $this->getScopedSectionList()->removeSection($delta);
    $this->invalidateCache();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function removeAllSections($set_blank = FALSE) {
    $this->getScopedSectionList()->removeAllSections($set_blank);
    $this->invalidateCache();
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageId() {
    return $this->rootStorage->getStorageId();
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageType() {
    return $this->rootStorage->getStorageType();
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    // NOTE: Bubbling is NOT automatic here. The tempstore seam bubbles;
    // direct save() callers must call bubbleChangesToRoot() first when
    // nested changes were made.
    return $this->rootStorage->save();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRoutes(RouteCollection $collection) {
    return $this->rootStorage->buildRoutes($collection);
  }

  /**
   * {@inheritdoc}
   */
  public function getRedirectUrl() {
    return $this->rootStorage->getRedirectUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function getLayoutBuilderUrl($rel = 'view') {
    return $this->rootStorage->getLayoutBuilderUrl($rel);
  }

  /**
   * {@inheritdoc}
   */
  public function deriveContextsFromRoute($value, $definition, $name, array $defaults) {
    return $this->rootStorage->deriveContextsFromRoute($value, $definition, $name, $defaults);
  }

  /**
   * {@inheritdoc}
   */
  public function getContextsDuringPreview() {
    return $this->addRootEntityContext($this->rootStorage->getContextsDuringPreview());
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->rootStorage->label();
  }

  /**
   * {@inheritdoc}
   */
  public function isApplicable(RefinableCacheableDependencyInterface $cacheability) {
    return $this->rootStorage->isApplicable($cacheability);
  }

  /**
   * {@inheritdoc}
   */
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $this->rootStorage->access($operation, $account, $return_as_object);
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId() {
    return $this->rootStorage->getPluginId();
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginDefinition() {
    return $this->rootStorage->getPluginDefinition();
  }

  /**
   * {@inheritdoc}
   */
  public function getContextMapping() {
    return $this->rootStorage->getContextMapping();
  }

  /**
   * {@inheritdoc}
   */
  public function setContextMapping(array $context_mapping) {
    $this->rootStorage->setContextMapping($context_mapping);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinitions() {
    return $this->rootStorage->getContextDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public function getContextDefinition($name) {
    return $this->rootStorage->getContextDefinition($name);
  }

  /**
   * {@inheritdoc}
   */
  public function getContexts() {
    return $this->addRootEntityContext($this->rootStorage->getContexts());
  }

  /**
   * {@inheritdoc}
   */
  public function getContext($name) {
    return $this->rootStorage->getContext($name);
  }

  /**
   * {@inheritdoc}
   */
  public function setContext($name, ContextInterface $context) {
    $this->rootStorage->setContext($name, $context);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getContextValues() {
    return $this->rootStorage->getContextValues();
  }

  /**
   * {@inheritdoc}
   */
  public function getContextValue($name) {
    return $this->rootStorage->getContextValue($name);
  }

  /**
   * {@inheritdoc}
   */
  public function setContextValue($name, $value) {
    $this->rootStorage->setContextValue($name, $value);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function validateContexts() {
    return $this->rootStorage->validateContexts();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->rootStorage->getConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->rootStorage->setConfiguration($configuration);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return $this->rootStorage->defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    return $this->rootStorage->calculateDependencies();
  }

  /**
   * Gets a section storage for a fieldable entity.
   *
   * This is a utility method for getting a canonical section storage from
   * an entity, primarily used when you need to then fetch from tempstore.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The fieldable entity.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The section storage, or NULL if the entity does not support Layout
   *   Builder.
   */
  public static function getSectionStorageForEntity($entity): ?SectionStorageInterface {
    if (!$entity->hasField(OverridesSectionStorage::FIELD_NAME)) {
      return NULL;
    }

    $view_mode = LayoutBuilderEntityViewDisplay::collectRenderDisplay($entity, 'full')->getMode();
    $contexts['view_mode'] = new Context(new ContextDefinition('string'), $view_mode);
    $contexts['entity'] = EntityContext::fromEntity($entity);
    return \Drupal::service('plugin.manager.layout_builder.section_storage')->load('overrides', $contexts);
  }

  /**
   * Gets the entity type manager.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   */
  protected function entityTypeManager(): EntityTypeManagerInterface {
    if ($this->entityTypeManager === NULL) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }
    return $this->entityTypeManager;
  }

  /**
   * Forwards any unhandled method calls to the root storage.
   *
   * This handles methods specific to section storage implementations like
   * OverridesSectionStorage::isOverridable() that aren't part of the interface.
   *
   * @param string $name
   *   The method name.
   * @param array $arguments
   *   The method arguments.
   *
   * @return mixed
   *   The result of the method call.
   */
  public function __call(string $name, array $arguments) {
    return $this->rootStorage->$name(...$arguments);
  }

}
