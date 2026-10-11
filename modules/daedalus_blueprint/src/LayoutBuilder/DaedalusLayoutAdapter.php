<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\daedalus_blueprint\ElementContext;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionListInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_layout\SectionStorage\EmbeddedSectionList;

/**
 * Encapsulates all Layout Builder component access behind a single service.
 *
 * Every place the module needs Layout Builder functionality calls this
 * adapter. The document is read through its host (HostInterface): the
 * host answers the storage, the adapter walks and mutates what the storage
 * holds. When daedalus_layout is installed, uses O(1) TreeIndex lookups and
 * nested layout traversal. Without daedalus_layout, falls back to flat
 * section iteration.
 */
class DaedalusLayoutAdapter {

  /**
   * Constructs an DaedalusLayoutAdapter.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\InlineBlockValues $inlineBlockValues
   *   Inline blocks as values: hydrates a value block (a display's stored
   *   shape) into the unsaved block the spine works on.
   * @param \Drupal\Core\Block\BlockManagerInterface $blockManager
   *   The block manager: whether a placed slot block's plugin exists, the
   *   slot deriver's answer for the roster (declaredSlots()).
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface|null $placementRestrictions
   *   The placement-restrictions spine, for the default-section layout
   *   ladder. NULL only in hand-wired construction.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected InlineBlockValues $inlineBlockValues,
    protected BlockManagerInterface $blockManager,
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
   * Extracts a block_content entity from an InlineBlock component.
   *
   * Three storage shapes, in precedence order: block_serialized (an
   * unsaved block, the staged shape), block_revision_id (a saved block, a
   * content host's stored shape) and block_values (a value block, a
   * display's stored shape, hydrated to an unsaved block).
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

    // Path 3: block_values (a value block, hydrated fresh each time).
    if (InlineBlockValues::hasValues($configuration)) {
      $block_type = $this->getInlineBlockType($component);
      if ($block_type !== NULL) {
        return $this->inlineBlockValues->hydrate($block_type, $configuration[InlineBlockValues::KEY]);
      }
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
   * Indexes a section tree by element: each uuid to its parent's.
   *
   * Every element at any depth (a section by its daedalus_layout uuid, a
   * component by its own; a container is one element, its component uuid
   * being its embedded section's) maps to the uuid of the section holding
   * it, or NULL for a top-level section (held by the root). The one walk
   * behind "is this element in the document" and "what does this element
   * sit in" (the capability resolver's ownership answer, the op door's
   * destination of a placement).
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The top-level sections.
   *
   * @return array<string, string|null>
   *   Element uuid to parent section uuid (NULL at the top level).
   */
  public function parentIndex(array $sections): array {
    $index = [];
    $this->indexSections($sections, NULL, $index);
    return $index;
  }

  /**
   * Walks sections into the parent index (see parentIndex()).
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The sections at this depth.
   * @param string|null $parent
   *   The uuid of the section holding them, or NULL at the top level.
   * @param array<string, string|null> $index
   *   The index being built.
   */
  protected function indexSections(array $sections, ?string $parent, array &$index): void {
    foreach ($sections as $section) {
      $section_uuid = $this->getSectionUuid($section);
      // A container's own entry was written by its component below (the
      // uuid is one); a top-level section is held by the root.
      if ($section_uuid !== NULL && !array_key_exists($section_uuid, $index)) {
        $index[$section_uuid] = $parent;
      }
      foreach ($section->getComponents() as $component) {
        $index[$component->getUuid()] ??= $section_uuid;
        $embedded = $this->embeddedSection($component);
        if ($embedded !== NULL) {
          $this->indexSections([$embedded], $section_uuid, $index);
        }
      }
    }
  }

  /**
   * The slot ids a section tree declares: its slot blocks, at any depth.
   *
   * A slot block (daedalus_slot:{id}, SlotBlock::slotIdOf()) placed in a
   * document declares the slot the documents rendered inside it may fill.
   * The one walk behind "which slots does this template place": the
   * ownership policy's corrective names them, and a start of slot fills
   * fits a locked bundle only when every fill names one of them
   * (CanvasOffer::fillsSlots()).
   *
   * Only a slot whose block plugin exists counts. A slot block derives per
   * roster id (SlotDeriver), so a placed daedalus_slot:{id} whose id the
   * roster dropped (a config write core never validates: a bare config
   * save, drush config:set) resolves to core's broken block, a door
   * nothing renders into and nothing can fill. Counting it would make the
   * page display variant skip its main-content fallback (the whole theme
   * swallowed), the ownership corrective name a dead door and a fit
   * accept a fill nothing shows, so the block manager's answer, the
   * deriver's own, is the judge rather than the plugin id string.
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The top-level sections.
   *
   * @return string[]
   *   The slot ids in document order, each once.
   */
  public function declaredSlots(array $sections): array {
    $slots = [];
    foreach ($this->components($sections) as $component) {
      $plugin_id = $component->getPluginId();
      $slot = SlotBlock::slotIdOf($plugin_id);
      if ($slot !== NULL && $this->blockManager->hasDefinition($plugin_id)) {
        $slots[] = $slot;
      }
    }
    return array_values(array_unique($slots));
  }

  /**
   * Every component of a document, at any depth, in document order.
   *
   * The one walk of a section list through its nested containers for a
   * read-only question about what a document places (which slots it
   * declares, whether it holds a block of some kind): each container's
   * embedded section is hydrated by value (embeddedSection()), so this is
   * never a road for a mutation.
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The top-level sections.
   *
   * @return \Generator<\Drupal\layout_builder\SectionComponent>
   *   The components, a container before what it embeds.
   */
  public function components(array $sections): \Generator {
    foreach ($sections as $section) {
      foreach ($section->getComponents() as $component) {
        yield $component;
        $embedded = $this->embeddedSection($component);
        if ($embedded !== NULL) {
          yield from $this->components([$embedded]);
        }
      }
    }
  }

  /**
   * The host's own field a component presents, when it is such a field block.
   *
   * Core's field block plugin id is field_block:{entity_type}:{bundle}:
   * {field}; one naming the host's own type and bundle shows the host's
   * field, whose content is the host's to edit: the capability resolver
   * keeps that field's content verdict on the block (OwnershipPolicy), and
   * a write into the field it shows is a write on the host's root. The
   * component is found in the document the host renders (its own or its
   * template's, nested sections included), since a plain host's template
   * elements are not locatable in its own storage.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param string $uuid
   *   The component uuid.
   *
   * @return string|null
   *   The presented field's name, or NULL when the uuid names no component
   *   or one that is not a field block of the host's own bundle.
   */
  public function presentedHostField(HostInterface $host, string $uuid): ?string {
    $component = $this->findComponent($host->sections(), $uuid);
    if ($component === NULL) {
      return NULL;
    }
    return FieldBlock::fieldFor($component->getPluginId(), $host->entityTypeId(), $host->bundle());
  }

  /**
   * Finds a component by uuid in a section list, nested sections included.
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The sections.
   * @param string $uuid
   *   The component uuid.
   */
  protected function findComponent(array $sections, string $uuid): ?SectionComponent {
    foreach ($sections as $section) {
      if (!$section instanceof Section) {
        continue;
      }
      foreach ($section->getComponents() as $component) {
        if ($component->getUuid() === $uuid) {
          return $component;
        }
        $embedded = $this->embeddedSection($component);
        if ($embedded !== NULL) {
          $found = $this->findComponent([$embedded], $uuid);
          if ($found !== NULL) {
            return $found;
          }
        }
      }
    }
    return NULL;
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
   *   Opaque additional data carried by the component (e.g. blueprint
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
   * Whether a section list is core's blank section list.
   *
   * Core marks a layout that "has purposefully had all sections removed"
   * (as against one never instantiated, which is the truly empty list)
   * with one layout_builder_blank section at delta 0 (SectionListTrait::
   * hasBlankSection(), written by removeSection() when the last section
   * goes and by removeAllSections(TRUE)) and hides it behind count(): the
   * section renders nothing and no core consumer ever sees it as a
   * section. Every Daedalus read of a section list answers the same way
   * through this one predicate (the host's ownSections() and sections()
   * included), so the marker never surfaces as an element a client can
   * name, anchor or style. What the marker MEANS is the host's business:
   * on a page it is a started, empty document that seeds nothing and
   * renders as its own (ContentHost::seed()); on a template it is an
   * empty template that seeds nothing (DocumentInstantiator::seed()).
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   A section list, root or display.
   *
   * @return bool
   *   TRUE when the list is the blank placeholder.
   */
  public static function isBlankSectionList(array $sections): bool {
    $first = reset($sections);
    return $first instanceof Section && $first->getLayoutId() === 'layout_builder_blank';
  }

  /**
   * The root section list of a host's storage.
   *
   * Root sections are read and written through the storage at every depth,
   * never through a field: a nested-aware wrapper's own list is SCOPED
   * (forSection()), so the root list is the wrapper at its root scope (its
   * append, insert, remove and removeAll land on the root list and
   * invalidate the tree index), and a plain storage is its own root list.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $storage
   *   The host's storage, as sectionStorage() answered it or as an
   *   operation passes it along.
   *
   * @return \Drupal\layout_builder\SectionListInterface
   *   The root section list.
   */
  public function rootList(SectionStorageInterface $storage): SectionListInterface {
    if ($storage instanceof NestedSectionStorageInterface) {
      return $storage->forSection(NULL);
    }
    return $storage;
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
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose display supplies the default-section setting.
   * @param string|null $uuid
   *   A client-provided section UUID to honor, or NULL for a fresh one.
   * @param \Drupal\layout_builder\SectionStorageInterface|null $storage
   *   The operation's already-resolved section storage, or NULL to resolve
   *   the host's for the restrictions answer.
   *
   * @return \Drupal\layout_builder\Section|null
   *   The new, uninserted section, or NULL when no allowed layout exists.
   */
  public function createDefaultSection(HostInterface $host, ?string $uuid = NULL, ?SectionStorageInterface $storage = NULL): ?Section {
    $settings = [];
    if ($this->isDaedalusLayoutAvailable()) {
      $settings = $host->display()?->getThirdPartySetting('daedalus_layout', 'default_section', []) ?? [];
    }

    // NULL means no restrictions answer is computable (hand-wired
    // construction without the service); a configured default is trusted
    // then, but no layout is ever invented.
    $allowed = NULL;
    if ($this->placementRestrictions !== NULL) {
      $allowed = $this->placementRestrictions->allowedLayoutDefinitions($storage ?? $host->sectionStorage());
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
   * Root-level sections are found by iterating the root list of the host's
   * storage (ElementLocator::locateSection()); this method covers sections
   * at any nesting depth via daedalus_layout's TreeIndex. Returns NULL
   * without daedalus_layout (nested sections require it).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to search.
   * @param string $uuid
   *   The section UUID to find.
   * @param object|null $sectionStorage
   *   A nested-aware section storage the caller already holds, or NULL to
   *   resolve the host's.
   *
   * @return array{section: \Drupal\layout_builder\Section, storage: object}|null
   *   The section and the nested-aware storage (needed to bubble changes
   *   back to the root entity), or NULL if not found.
   */
  public function locateNestedSection(HostInterface $host, string $uuid, ?object $sectionStorage = NULL): ?array {
    $sectionStorage ??= $host->sectionStorage();
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
   * component lookup at any nesting depth. Without daedalus_layout, performs a
   * flat iteration of the root sections of the host's storage.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to search.
   * @param string $uuid
   *   The component UUID to find.
   * @param object|null $sectionStorage
   *   An already-resolved section storage to search. Pass this when one
   *   operation performs multiple locates that will mutate the results:
   *   each sectionStorage() call on the host builds a fresh wrapper whose
   *   nested Section/entity instances are NOT shared with other wrappers,
   *   so mutations spread across wrappers lose each other on bubbling.
   *
   * @return \Drupal\daedalus_blueprint\ElementContext|null
   *   The component context, or NULL if the component is not found.
   */
  public function locateElement(HostInterface $host, string $uuid, ?object $sectionStorage = NULL): ?ElementContext {
    $sectionStorage ??= $host->sectionStorage();
    if ($sectionStorage === NULL) {
      return NULL;
    }

    if ($sectionStorage instanceof NestedSectionStorageInterface) {
      return $this->locateElementViaTreeIndex($host, $sectionStorage, $uuid);
    }

    return $this->locateElementFlat($host, $sectionStorage, $uuid);
  }

  /**
   * Locates a component via daedalus_layout O(1) TreeIndex lookup.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to search.
   * @param \Drupal\daedalus_layout\NestedSectionStorageInterface $wrapped
   *   The tempstore-aware nested section storage.
   * @param string $uuid
   *   The component UUID to find.
   *
   * @return \Drupal\daedalus_blueprint\ElementContext|null
   *   The component context, or NULL if not found.
   */
  protected function locateElementViaTreeIndex(HostInterface $host, NestedSectionStorageInterface $wrapped, string $uuid): ?ElementContext {
    $component = $wrapped->getComponent($uuid);
    if ($component === NULL) {
      return NULL;
    }

    $section = $wrapped->getSectionFor($uuid);
    // Owner-entity plumbing is gone: components at every depth belong to the
    // host's fields entity (nested sections are values, not entities).
    $parent_entity = $host->fields();
    $tree_path = $wrapped->getPath($uuid);
    $nested_context = $wrapped->getNestedContext($uuid);

    // Section index: local delta for nested, root delta for top-level.
    $section_index = $nested_context !== NULL
      ? $nested_context['localDelta']
      : $tree_path[0];

    return new ElementContext(
      type: ElementContext::TYPE_LB_COMPONENT,
      target: $component,
      host: $host,
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
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to search.
   * @param \Drupal\layout_builder\SectionStorageInterface $sectionStorage
   *   The tempstore-aware section storage.
   * @param string $uuid
   *   The component UUID to find.
   *
   * @return \Drupal\daedalus_blueprint\ElementContext|null
   *   The component context, or NULL if not found.
   */
  protected function locateElementFlat(HostInterface $host, SectionStorageInterface $sectionStorage, string $uuid): ?ElementContext {
    foreach ($this->walkComponents($sectionStorage) as $path => [$section_index, $section, $component]) {
      if ($component->getUuid() === $uuid) {
        return new ElementContext(
          type: ElementContext::TYPE_LB_COMPONENT,
          target: $component,
          host: $host,
          parentEntity: $host->fields(),
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
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   *
   * @return \Generator
   *   Yields arrays with 'entity' (FieldableEntityInterface),
   *   'component_uuid' (string), and 'path' (string) keys.
   */
  public function getBlockContentEntities(HostInterface $host): \Generator {
    $sectionStorage = $host->sectionStorage();
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
   *   The segment, such as "layout[0].components[1]".
   */
  protected function componentPath(int $section_index, int $component_index): string {
    return "layout[{$section_index}].components[{$component_index}]";
  }

  /**
   * Builds a path string from a TreeIndex path array.
   *
   * Converts the alternating [sectionDelta, blockUuid, ...] array into
   * a dotted path like "layout[0].components[1]".
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
