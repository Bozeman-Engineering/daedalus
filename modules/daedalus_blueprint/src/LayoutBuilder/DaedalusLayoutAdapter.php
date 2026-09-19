<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_blueprint\ElementContext;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_layout\SectionStorage\EmbeddedSectionList;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;

/**
 * Encapsulates all Layout Builder component access behind a single service.
 *
 * Every place the module needs Layout Builder functionality calls this adapter.
 * When daedalus_layout is installed, uses O(1) TreeIndex lookups and nested layout
 * traversal. Without daedalus_layout, falls back to flat section iteration.
 */
class DaedalusLayoutAdapter {

  /**
   * Constructs an DaedalusLayoutAdapter.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface|null $sectionStorageManager
   *   The section storage manager, or NULL if layout_builder is not installed.
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface|null $layoutTempstoreRepository
   *   The layout tempstore repository, or NULL if layout_builder is not
   *   installed.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface|null $placementRestrictions
   *   The placement-restrictions spine, for the default-section layout
   *   ladder. NULL only in hand-wired construction.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?SectionStorageManagerInterface $sectionStorageManager = NULL,
    protected ?LayoutTempstoreRepositoryInterface $layoutTempstoreRepository = NULL,
    protected ?PlacementRestrictionsInterface $placementRestrictions = NULL,
  ) {}

  /**
   * Returns whether the daedalus_layout module is available.
   *
   * @return bool
   *   TRUE if the daedalus_layout module is installed.
   */
  public function isDaedalusLayoutAvailable(): bool {
    return $this->moduleHandler->moduleExists('daedalus_layout');
  }

  /**
   * Resolves tempstore-aware section storage for an entity.
   *
   * Loads the overrides section storage, then checks tempstore for an
   * in-progress version. When daedalus_layout is available, wraps the result in
   * NestedAwareSectionStorage for nested layout traversal.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to resolve section storage for.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The section storage, or NULL if not available.
   */
  public function getSectionStorage(FieldableEntityInterface $entity): ?SectionStorageInterface {
    if ($this->sectionStorageManager === NULL || $this->layoutTempstoreRepository === NULL) {
      return NULL;
    }

    if (!$entity->hasField('layout_builder__layout')) {
      return NULL;
    }

    $view_mode = LayoutBuilderEntityViewDisplay::collectRenderDisplay($entity, 'full')->getMode();
    $contexts = [
      'entity' => EntityContext::fromEntity($entity),
      'view_mode' => new Context(new ContextDefinition('string'), $view_mode),
    ];

    $storage = $this->sectionStorageManager->load('overrides', $contexts);
    if ($storage === NULL) {
      return NULL;
    }

    // Prefer the tempstore storage (in-progress edits) only when it stages
    // THIS entity instance: then its request-shared wrapper (TreeIndex and
    // embedded-list registry included) is the one everything else in the
    // request mutates. The staged storage carries its own entity object, so
    // for any other instance it is a parallel world: locates through it
    // cannot see what operations just did to the passed entity (a nested
    // section minted mid-batch resolved nowhere: ANCHOR_NOT_FOUND on
    // identity that plainly existed), and every caller of this method
    // passes the entity whose tree it is about to read or mutate.
    if ($this->layoutTempstoreRepository->has($storage)) {
      $staged = $this->layoutTempstoreRepository->get($storage);
      if ($staged->getContext('entity')->getContextValue() === $entity) {
        $storage = $staged;
      }
    }

    // Wrap with NestedAwareSectionStorage if daedalus_layout is available.
    if ($this->isDaedalusLayoutAvailable()) {
      return NestedAwareSectionStorage::wrap($storage);
    }

    return $storage;
  }

  /**
   * Extracts a block_content entity from an InlineBlock component.
   *
   * Handles both block_serialized (PHP unserialize) and block_revision_id
   * (revision load) paths.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The section component.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The block_content entity or NULL if extraction fails.
   */
  public function extractBlockContent(SectionComponent $component): ?FieldableEntityInterface {
    $configuration = $component->get('configuration');

    // Path 1: block_serialized (unsaved block in PHP serialized form).
    if (!empty($configuration['block_serialized'])) {
      // Layout Builder's own inline-block payload (InlineBlock::blockSubmit()).
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
      $block = unserialize($configuration['block_serialized']);
      if ($block) {
        return $block;
      }
    }

    // Path 2: block_revision_id (saved block — load revision).
    if (!empty($configuration['block_revision_id'])) {
      $storage = $this->entityTypeManager->getStorage('block_content');
      return $storage->loadRevision($configuration['block_revision_id']);
    }

    return NULL;
  }

  /**
   * Gets the section UUID from daedalus_layout third-party settings.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section.
   *
   * @return string|null
   *   The section UUID or NULL if daedalus_layout is not available or no UUID set.
   */
  public function getSectionUuid(Section $section): ?string {
    if (!$this->isDaedalusLayoutAvailable()) {
      return NULL;
    }

    return SectionIdentity::uuid($section);
  }

  /**
   * Sets the section UUID via daedalus_layout third-party settings.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section.
   * @param string $uuid
   *   The UUID to set.
   */
  public function setSectionUuid(Section $section, string $uuid): void {
    if (!$this->isDaedalusLayoutAvailable()) {
      return;
    }

    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
  }

  /**
   * Checks if a component is an inline block.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The section component.
   *
   * @return bool
   *   TRUE if the component uses an inline_block plugin.
   */
  public function isInlineBlock(SectionComponent $component): bool {
    return $this->getInlineBlockType($component) !== NULL;
  }

  /**
   * Checks if a component is a nested-section container.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The section component.
   *
   * @return bool
   *   TRUE if the component embeds a section (daedalus_layout_section plugin).
   */
  public function isSectionComponent(SectionComponent $component): bool {
    return $this->isDaedalusLayoutAvailable() && SectionBlock::isSectionComponent($component);
  }

  /**
   * Hydrates the section embedded in a container component.
   *
   * Read-only hydration for serialization walks; mutation flows go through
   * the scoped section list machinery (identity-stable hydration).
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The section component.
   *
   * @return \Drupal\layout_builder\Section|null
   *   The embedded section, or NULL if the component is not a container.
   */
  public function embeddedSection(SectionComponent $component): ?Section {
    if (!$this->isDaedalusLayoutAvailable()) {
      return NULL;
    }
    return SectionBlock::embeddedSection($component);
  }

  /**
   * Creates a container component embedding the given section.
   *
   * Delegates to SectionBlock::createComponent(), the one blessed container
   * constructor (component uuid == embedded section uuid, default context
   * mapping stamped).
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section to embed.
   * @param string $region
   *   The parent section region.
   * @param int $weight
   *   The component weight.
   * @param array $additional
   *   Opaque additional data carried by the component (e.g. stencil
   *   provenance).
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The container component.
   */
  public function createSectionComponent(Section $section, string $region, int $weight = 0, array $additional = []): SectionComponent {
    return SectionBlock::createComponent($section, $region, $weight, $additional);
  }

  /**
   * Resolves a nested section's container placement in its parent section.
   *
   * @param object|null $storage
   *   The nested-aware section storage.
   * @param string $section_uuid
   *   The section UUID.
   *
   * @return array{component: \Drupal\layout_builder\SectionComponent, parentSection: \Drupal\layout_builder\Section}|null
   *   The container component and the section holding it, or NULL when the
   *   UUID is a top-level section (or cannot be resolved as nested).
   */
  public function locateContainer(?object $storage, string $section_uuid): ?array {
    if (!$storage instanceof NestedSectionStorageInterface) {
      return NULL;
    }
    $list = $storage->getSectionsFor($section_uuid);
    if (!$list instanceof EmbeddedSectionList) {
      return NULL;
    }
    return [
      'component' => $list->getContainerComponent(),
      'parentSection' => $list->getParentSection(),
    ];
  }

  /**
   * Gets the block type from an inline block component's plugin ID.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The section component.
   *
   * @return string|null
   *   The block content type machine name, or NULL if not inline.
   */
  public function getInlineBlockType(SectionComponent $component): ?string {
    $configuration = $component->get('configuration');
    $plugin_id = $configuration['id'] ?? '';
    if (str_starts_with($plugin_id, 'inline_block:')) {
      return substr($plugin_id, strlen('inline_block:'));
    }
    return NULL;
  }

  /**
   * Gets the default layout sections for a Layout Builder entity.
   *
   * When a Layout Builder entity has no overridden layout, sections come from
   * the entity view display. This method loads those defaults and returns them.
   * If the default is only the blank placeholder section, a usable one-column
   * layout is returned instead.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to get default sections for.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The default sections, or an empty array if LB is not enabled.
   */
  public function getDefaultSections(FieldableEntityInterface $entity): array {
    $display_id = $entity->getEntityTypeId() . '.' . $entity->bundle() . '.default';
    $display = $this->entityTypeManager
      ->getStorage('entity_view_display')
      ->load($display_id);

    if (!$display instanceof LayoutBuilderEntityViewDisplay) {
      return [];
    }

    if (!$display->isLayoutBuilderEnabled()) {
      return [];
    }

    $sections = $display->getSections();

    // If the default only has a blank placeholder section, replace it with a
    // usable section so the AI has one to work with — the same ladder the
    // palette's add-section uses. When no layout is mintable, reads degrade
    // to "no sections"; the write paths surface NO_ALLOWED_LAYOUTS loudly.
    if (empty($sections) || (count($sections) === 1 && $sections[0]->getLayoutId() === 'layout_builder_blank')) {
      $default = $this->createDefaultSection($entity);
      return $default === NULL ? [] : [$default];
    }

    return $sections;
  }

  /**
   * Creates a new section for an implicit create, resolving the layout.
   *
   * The one answer for every consumer that mints a section without an
   * explicit layout choice (palette drops, AI add_section, blank-default
   * replacement). New sections are containers whenever the site has them:
   * arrangement is styling on the container, not a layout choice, so no
   * chooser follows the add. The resolution ladder:
   * 1. The render display's daedalus_layout `default_section` third-party setting,
   *    when its layout is currently in the allowed set AND is a container
   *    layout (the whole setting array doubles as the section settings).
   * 2. The first container layout (ContainerLayout::isContainer()) in the
   *    placement-restrictions allowed-layouts answer.
   * 3. The configured `default_section` setting when its (non-container)
   *    layout is allowed — the pre-container behavior, kept for generic
   *    sites that ship no container layout.
   * 4. The first definition of the placement-restrictions allowed-layouts
   *    answer — the same filtered list a picker would offer.
   * 5. NULL. No layout id is ever assumed by name: the spine must never
   *    mint a section it would itself reject, and layouts like
   *    layout_onecol are neither guaranteed installed nor allowed.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity whose display supplies the default-section setting.
   * @param string|null $uuid
   *   A client-provided section UUID to honor, or NULL for a fresh one.
   * @param \Drupal\layout_builder\SectionStorageInterface|null $storage
   *   The operation's already-resolved section storage, or NULL to resolve
   *   one for the restrictions answer.
   *
   * @return \Drupal\layout_builder\Section|null
   *   The new, uninserted section, or NULL when no allowed layout exists.
   */
  public function createDefaultSection(FieldableEntityInterface $entity, ?string $uuid = NULL, ?SectionStorageInterface $storage = NULL): ?Section {
    $settings = [];
    if ($this->isDaedalusLayoutAvailable()) {
      $display = LayoutBuilderEntityViewDisplay::collectRenderDisplay($entity, 'full');
      $settings = $display->getThirdPartySetting('daedalus_layout', 'default_section', []);
    }

    // NULL means no restrictions answer is computable (hand-wired
    // construction without the service); a configured default is trusted
    // then, but no layout is ever invented.
    $allowed = NULL;
    if ($this->placementRestrictions !== NULL) {
      $allowed = $this->placementRestrictions->allowedLayoutDefinitions($storage ?? $this->getSectionStorage($entity));
    }

    $configured = $settings['layout_plugin'] ?? NULL;
    $configured_allowed = !empty($configured) && ($allowed === NULL || isset($allowed[$configured]));

    // Containers first: with a container layout in play, an implicit create
    // mints a container (arrangement is styling, not a layout choice). A
    // configured container default keeps its settings; otherwise the first
    // allowed container layout wins over any non-container configuration.
    $container = NULL;
    if ($allowed !== NULL) {
      foreach ($allowed as $id => $definition) {
        if (ContainerLayout::isContainer($definition)) {
          $container = $id;
          break;
        }
      }
    }
    if ($configured_allowed && $allowed !== NULL && ContainerLayout::isContainer($allowed[$configured] ?? NULL)) {
      $section = new Section($configured, $settings);
    }
    elseif ($container !== NULL) {
      $section = new Section($container);
    }
    elseif ($configured_allowed) {
      $section = new Section($configured, $settings);
    }
    elseif (!empty($allowed)) {
      $section = new Section(array_key_first($allowed));
    }
    else {
      return NULL;
    }
    $this->setSectionUuid($section, $uuid ?? (new PhpUuid())->generate());
    return $section;
  }

  /**
   * Locates a section nested inside a container component by its daedalus_layout UUID.
   *
   * Root-level sections are found by iterating the entity's layout field
   * directly; this method covers sections at any nesting depth via daedalus_layout's
   * TreeIndex. Returns NULL without daedalus_layout (nested sections require it).
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity to search.
   * @param string $uuid
   *   The section UUID to find.
   * @param object|null $sectionStorage
   *   A nested-aware section storage the caller already holds, or NULL to load
   *   the entity's.
   *
   * @return array{section: \Drupal\layout_builder\Section, storage: object}|null
   *   The section and the nested-aware storage (needed to bubble changes
   *   back to the root entity), or NULL if not found.
   */
  public function locateNestedSection(FieldableEntityInterface $entity, string $uuid, ?object $sectionStorage = NULL): ?array {
    $sectionStorage ??= $this->getSectionStorage($entity);
    if (!$sectionStorage instanceof NestedSectionStorageInterface) {
      return NULL;
    }

    $section = $sectionStorage->getSectionByUuid($uuid);
    if ($section === NULL) {
      return NULL;
    }

    // There is no entity boundary inside the tree: every section at every
    // depth is a value on the root entity, so no owner rides along.
    return [
      'section' => $section,
      'storage' => $sectionStorage,
    ];
  }

  /**
   * Locates a Layout Builder component by UUID.
   *
   * When daedalus_layout is available, uses NestedAwareSectionStorage for O(1)
   * component lookup at any nesting depth. Without daedalus_layout, performs a flat
   * iteration of the entity's layout_builder__layout field.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity to search.
   * @param string $uuid
   *   The component UUID to find.
   * @param object|null $sectionStorage
   *   An already-resolved section storage to search. Pass this when one
   *   operation performs multiple locates that will mutate the results:
   *   each getSectionStorage() call builds a fresh wrapper whose nested
   *   Section/entity instances are NOT shared with other wrappers, so
   *   mutations spread across wrappers lose each other on bubbling.
   *
   * @return \Drupal\daedalus_blueprint\ElementContext|null
   *   The component context, or NULL if the component is not found.
   */
  public function locateElement(FieldableEntityInterface $entity, string $uuid, ?object $sectionStorage = NULL): ?ElementContext {
    $sectionStorage ??= $this->getSectionStorage($entity);
    if ($sectionStorage === NULL) {
      return NULL;
    }

    if ($sectionStorage instanceof NestedSectionStorageInterface) {
      return $this->locateElementViaTreeIndex($entity, $sectionStorage, $uuid);
    }

    return $this->locateElementFlat($entity, $sectionStorage, $uuid);
  }

  /**
   * Locates a component via daedalus_layout O(1) TreeIndex lookup.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity to search.
   * @param \Drupal\daedalus_layout\NestedSectionStorageInterface $wrapped
   *   The tempstore-aware nested section storage.
   * @param string $uuid
   *   The component UUID to find.
   *
   * @return \Drupal\daedalus_blueprint\ElementContext|null
   *   The component context, or NULL if not found.
   */
  protected function locateElementViaTreeIndex(FieldableEntityInterface $entity, NestedSectionStorageInterface $wrapped, string $uuid): ?ElementContext {
    $component = $wrapped->getComponent($uuid);
    if ($component === NULL) {
      return NULL;
    }

    $section = $wrapped->getSectionFor($uuid);
    // Owner-entity plumbing is gone: components at every depth belong to the
    // root entity (nested sections are values, not entities).
    $parent_entity = $entity;
    $tree_path = $wrapped->getPath($uuid);
    $nested_context = $wrapped->getNestedContext($uuid);

    // Section index: local delta for nested, root delta for top-level.
    $section_index = $nested_context !== NULL
      ? $nested_context['localDelta']
      : $tree_path[0];

    return new ElementContext(
      type: ElementContext::TYPE_LB_COMPONENT,
      target: $component,
      parentEntity: $parent_entity,
      path: $this->buildPathString($wrapped, $tree_path),
      section: $section,
      sectionIndex: $section_index,
      sectionStorage: $wrapped,
    );
  }

  /**
   * Locates a component via the flat walk of the root sections.
   *
   * Rides walkComponents(), so the path it reports is the one the
   * block-content sweep yields for the same component.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity with a layout_builder__layout field.
   * @param \Drupal\layout_builder\SectionStorageInterface $sectionStorage
   *   The tempstore-aware section storage.
   * @param string $uuid
   *   The component UUID to find.
   *
   * @return \Drupal\daedalus_blueprint\ElementContext|null
   *   The component context, or NULL if not found.
   */
  protected function locateElementFlat(FieldableEntityInterface $entity, SectionStorageInterface $sectionStorage, string $uuid): ?ElementContext {
    foreach ($this->walkComponents($sectionStorage) as $path => [$section_index, $section, $component]) {
      if ($component->getUuid() === $uuid) {
        return new ElementContext(
          type: ElementContext::TYPE_LB_COMPONENT,
          target: $component,
          parentEntity: $entity,
          path: $path,
          section: $section,
          sectionIndex: $section_index,
          sectionStorage: $sectionStorage,
        );
      }
    }

    return NULL;
  }

  /**
   * Yields block_content entities from Layout Builder sections lazily.
   *
   * When daedalus_layout is available, uses the TreeIndex for efficient iteration
   * at all nesting depths. Without daedalus_layout, performs flat iteration of
   * root sections. Extraction (unserialize/revision load) happens on
   * demand so the caller can stop early.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity.
   *
   * @return \Generator
   *   Yields arrays with 'entity' (FieldableEntityInterface),
   *   'component_uuid' (string), and 'path' (string) keys.
   */
  public function getBlockContentEntities(FieldableEntityInterface $entity): \Generator {
    $sectionStorage = $this->getSectionStorage($entity);
    if ($sectionStorage instanceof NestedSectionStorageInterface) {
      yield from $this->getBlockContentEntitiesViaTreeIndex($sectionStorage);
    }
    elseif ($sectionStorage !== NULL) {
      yield from $this->getBlockContentEntitiesFlat($sectionStorage);
    }
  }

  /**
   * Yields block_content entities via daedalus_layout TreeIndex.
   *
   * @param \Drupal\daedalus_layout\NestedSectionStorageInterface $wrapped
   *   The tempstore-aware nested section storage.
   *
   * @return \Generator
   *   Yields arrays with 'entity', 'component_uuid', and 'path' keys.
   */
  protected function getBlockContentEntitiesViaTreeIndex(NestedSectionStorageInterface $wrapped): \Generator {
    $tree_index = $wrapped->getTreeIndex();
    $blocks = $tree_index->getAllOfType('block');

    foreach ($blocks as $block_uuid => $info) {
      $component = $wrapped->getComponent($block_uuid);
      if ($component === NULL || !$this->isInlineBlock($component)) {
        continue;
      }
      $block_content = $this->extractBlockContent($component);
      if ($block_content === NULL) {
        continue;
      }
      yield [
        'entity' => $block_content,
        'component_uuid' => $block_uuid,
        'path' => $this->buildPathString($wrapped, $info['path']),
      ];
    }
  }

  /**
   * Yields block_content entities via the flat walk of the root sections.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $sectionStorage
   *   The tempstore-aware section storage.
   *
   * @return \Generator
   *   Yields arrays with 'entity', 'component_uuid', and 'path' keys.
   */
  protected function getBlockContentEntitiesFlat(SectionStorageInterface $sectionStorage): \Generator {
    foreach ($this->walkComponents($sectionStorage) as $path => [, , $component]) {
      if (!$this->isInlineBlock($component)) {
        continue;
      }
      $block_content = $this->extractBlockContent($component);
      if ($block_content === NULL) {
        continue;
      }
      yield [
        'entity' => $block_content,
        'component_uuid' => $component->getUuid(),
        'path' => $path,
      ];
    }
  }

  /**
   * Walks every component of the root sections, keyed by element path.
   *
   * The one flat walk (no daedalus_layout, so no recursion into nested layouts).
   * The locate and the block-content sweep both read it, so they agree on
   * section order, on the positional component index and on the path
   * format by construction rather than by copy.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $sectionStorage
   *   The tempstore-aware section storage.
   *
   * @return \Generator<string, array{int, \Drupal\layout_builder\Section, \Drupal\layout_builder\SectionComponent}>
   *   The element path of each component, mapped to its root section index,
   *   its section and the component itself, in section then component order.
   */
  protected function walkComponents(SectionStorageInterface $sectionStorage): \Generator {
    foreach ($sectionStorage->getSections() as $section_index => $section) {
      foreach (array_values($section->getComponents()) as $component_index => $component) {
        yield $this->componentPath($section_index, $component_index) => [$section_index, $section, $component];
      }
    }
  }

  /**
   * Formats one element-path segment for a component.
   *
   * @param int $section_index
   *   The section delta within its section list.
   * @param int $component_index
   *   The zero-based positional index of the component within the section.
   *
   * @return string
   *   The segment, such as "layout_builder__layout[0].components[1]".
   */
  protected function componentPath(int $section_index, int $component_index): string {
    return "layout_builder__layout[{$section_index}].components[{$component_index}]";
  }

  /**
   * Builds a path string from a TreeIndex path array.
   *
   * Converts the alternating [sectionDelta, blockUuid, ...] array into
   * a dotted path like "layout_builder__layout[0].components[1]".
   *
   * @param object $wrapped
   *   The NestedAwareSectionStorage instance.
   * @param array $tree_path
   *   Path array from TreeIndex: [sectionDelta, blockUuid, ...].
   *
   * @return string
   *   The dotted path string.
   */
  protected function buildPathString(object $wrapped, array $tree_path): string {
    $segments = [];
    for ($i = 0; $i < count($tree_path); $i += 2) {
      $section_delta = $tree_path[$i];
      $block_uuid = $tree_path[$i + 1];
      $section = $wrapped->getSectionFor($block_uuid);
      $segments[] = $this->componentPath($section_delta, $this->getComponentIndex($section, $block_uuid));
    }
    return implode('.', $segments);
  }

  /**
   * Gets the positional index of a component within a section.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section.
   * @param string $uuid
   *   The component UUID.
   *
   * @return int
   *   The zero-based positional index.
   */
  protected function getComponentIndex(Section $section, string $uuid): int {
    $index = 0;
    foreach ($section->getComponents() as $component) {
      if ($component->getUuid() === $uuid) {
        return $index;
      }
      $index++;
    }
    return 0;
  }

}
