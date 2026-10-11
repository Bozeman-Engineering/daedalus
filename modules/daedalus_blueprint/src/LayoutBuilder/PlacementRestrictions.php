<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Block\MainContentBlockPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Plugin\SectionStorage\FrameSectionStorage;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Placement restrictions answered through core's filtered definitions.
 *
 * See PlacementRestrictionsInterface for the model. The heavy lifting is
 * core's FilteredPluginManagerTrait: this service only assembles the right
 * $extra for the 'layout_builder' consumer and projects the results.
 *
 * By-region providers derive the governing layout from
 * $extra['section_storage']->getSection($extra['delta'])->getLayoutId().
 * For nested sections the storage passed along is the nested-aware wrapper
 * SCOPED to the destination section (delta 0 is the section itself); for
 * top-level sections it is the unscoped wrapper with the real delta. Either
 * way getDefaultSectionStorage() resolves the ROOT display, so one display's
 * restrictions govern every depth.
 */
class PlacementRestrictions implements PlacementRestrictionsInterface {

  /**
   * Per-request memo of populated contexts, keyed by storage object id.
   *
   * @var array<int, \Drupal\Core\Plugin\Context\ContextInterface[]>
   */
  protected array $contextsMemo = [];

  /**
   * Constructs a PlacementRestrictions service.
   *
   * @param \Drupal\Core\Block\BlockManagerInterface $blockManager
   *   The block plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Plugin\Context\ContextRepositoryInterface $contextRepository
   *   The context repository, for context-aware definition filtering.
   * @param \Drupal\daedalus_blueprint\Slot\SlotRoster $slotRoster
   *   The slot roster: which tier of the stack declares each slot.
   * @param \Drupal\Core\Layout\LayoutPluginManagerInterface|null $layoutPluginManager
   *   The layout plugin manager (NULL when layout_discovery is not installed).
   * @param object|null $sectionStorageManager
   *   The section storage manager (NULL when layout_builder is not
   *   installed).
   */
  public function __construct(
    protected BlockManagerInterface $blockManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ContextRepositoryInterface $contextRepository,
    protected SlotRoster $slotRoster,
    protected ?LayoutPluginManagerInterface $layoutPluginManager = NULL,
    protected ?object $sectionStorageManager = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function allowedBlockDefinitions(?SectionStorageInterface $storage, ?Section $section = NULL, ?string $region = NULL): array {
    // List => 'inline_blocks' keeps core from stripping the inline_block
    // derivatives; browse => TRUE keeps restriction providers filtering
    // despite the list request (layout_builder_restrictions returns early on
    // a list WITHOUT browse — the layout_builder_browser convention). One
    // call therefore answers for inline AND plugin blocks.
    $extra = ['list' => 'inline_blocks', 'browse' => TRUE];
    $contexts = [];
    if ($storage !== NULL) {
      $extra['section_storage'] = $storage;
      $contexts = $this->populatedContexts($storage);
      if ($section !== NULL && $region !== NULL) {
        $coordinates = $this->sectionCoordinates($storage, $section);
        if ($coordinates !== NULL) {
          // By-region providers require BOTH delta and region and read the
          // governing layout from section_storage->getSection(delta).
          [$extra['section_storage'], $extra['delta']] = $coordinates;
          $extra['region'] = $region;
        }
      }
    }
    $definitions = $this->blockManager->getFilteredDefinitions('layout_builder', $contexts, $extra);
    // The fallback plugin core instantiates for a missing block is a
    // definition like any other, so the filter leaves it in; core's UI hides
    // it in BlockManager::getSortedDefinitions(), which this roster does not
    // go through. Dropped here, at the spine, so no consumer (the Add panel,
    // the schema, the validator) ever offers "Broken/Missing" as a block to
    // place.
    unset($definitions['broken']);
    // A slot is declared by exactly one tier of the render stack, the site
    // frame (the chrome's slots) or a template (the areas a locked page
    // fills), never both: a slot door asks the documents beneath it for
    // the section tagged with its id, door by door, so one page section
    // would fill two doors and render twice. The roster names each slot's
    // tier (SlotRoster), and a slot block is offered where the destination
    // is a document of that tier (the frame's storage for the frame tier,
    // a template's defaults storage for the template tier) and nowhere
    // else: a page and a blueprint declare none. Decided here, at the
    // spine, so the Add panel, the schema and the op door agree, and the
    // verdict reads the same tier (OwnershipPolicy::doorOf()).
    $destination_tier = $this->slotDestinationTier($storage);
    foreach (array_keys($definitions) as $plugin_id) {
      $slot = SlotBlock::slotIdOf($plugin_id);
      if ($slot !== NULL && ($destination_tier === NULL || $this->slotRoster->tier($slot) !== $destination_tier)) {
        unset($definitions[$plugin_id]);
      }
    }
    // The site frame is the page's chrome, so it offers the blocks that
    // make a page and core's Layout Builder filter drops from every
    // document (LayoutBuilderHooks::pluginFilterBlockLayoutBuilderAlter():
    // the title, the messages, help, local tasks and actions, placed by
    // the page display variant's contract rather than a document's), and
    // never the main content: the frame's main slot (FrameHost::MAIN_SLOT)
    // is where the variant drops what the route produced, and a block that
    // would show it a second time (core's system_main_block, any
    // MainContentBlockPluginInterface) is refused. Decided here for the
    // same reason as the slot.
    if ($this->isFrameDestination($storage)) {
      foreach (self::FRAME_PAGE_BLOCKS as $plugin_id) {
        if (!isset($definitions[$plugin_id]) && $this->blockManager->hasDefinition($plugin_id)) {
          $definitions[$plugin_id] = $this->blockManager->getDefinition($plugin_id);
        }
      }
      foreach ($definitions as $plugin_id => $definition) {
        if (isset($definition['class']) && is_a($definition['class'], MainContentBlockPluginInterface::class, TRUE)) {
          unset($definitions[$plugin_id]);
        }
      }
    }
    return $definitions;
  }

  /**
   * The roster tier a destination storage declares slots for, or NULL.
   *
   * A template (a defaults storage) and the site frame (the frame storage)
   * are the outer layers of the document stack, the ones a page's or a
   * template's own sections fill: the frame declares the frame tier's
   * slots, a template the template tier's. A page's own storage and a
   * blueprint's declare none. The nested-aware wrapper is unwrapped to its
   * root: a slot placed in a nested section of a template is still placed
   * in the template.
   */
  protected function slotDestinationTier(?SectionStorageInterface $storage): ?string {
    if ($storage === NULL) {
      return NULL;
    }
    if (method_exists($storage, 'getRootStorage')) {
      $storage = $storage->getRootStorage();
    }
    if ($storage instanceof FrameSectionStorage) {
      return SlotRoster::TIER_FRAME;
    }
    return $storage instanceof DefaultsSectionStorageInterface ? SlotRoster::TIER_TEMPLATE : NULL;
  }

  /**
   * The page-level blocks the site frame offers.
   *
   * What core's Layout Builder filter removes from a document's offer
   * because a document is never a whole page; the frame is one. The main
   * content block is not among them: the frame's main slot is the main
   * content.
   */
  public const FRAME_PAGE_BLOCKS = [
    'page_title_block',
    'system_messages_block',
    'help_block',
    'local_tasks_block',
    'local_actions_block',
  ];

  /**
   * Whether a destination storage is the site frame's.
   */
  protected function isFrameDestination(?SectionStorageInterface $storage): bool {
    if ($storage === NULL) {
      return FALSE;
    }
    if (method_exists($storage, 'getRootStorage')) {
      $storage = $storage->getRootStorage();
    }
    return $storage instanceof FrameSectionStorage;
  }

  /**
   * {@inheritdoc}
   */
  public function blockAllowed(string $plugin_id, ?SectionStorageInterface $storage, ?Section $section = NULL, ?string $region = NULL): bool {
    return isset($this->allowedBlockDefinitions($storage, $section, $region)[$plugin_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function blockExists(string $plugin_id): bool {
    return $this->blockManager->hasDefinition($plugin_id);
  }

  /**
   * {@inheritdoc}
   */
  public function allowedInlineBlockBundles(?SectionStorageInterface $storage, ?Section $section = NULL, ?string $region = NULL): array {
    $bundles = [];
    foreach ($this->allowedBlockDefinitions($storage, $section, $region) as $plugin_id => $definition) {
      if (str_starts_with($plugin_id, 'inline_block:')) {
        $bundles[] = substr($plugin_id, strlen('inline_block:'));
      }
    }
    sort($bundles);
    return $bundles;
  }

  /**
   * {@inheritdoc}
   */
  public function allowedLayoutDefinitions(?SectionStorageInterface $storage): array {
    if ($this->layoutPluginManager === NULL) {
      return [];
    }
    $extra = [];
    $contexts = [];
    if ($storage !== NULL) {
      $extra['section_storage'] = $storage;
      $contexts = $this->populatedContexts($storage);
    }
    return $this->layoutPluginManager->getFilteredDefinitions('layout_builder', $contexts, $extra);
  }

  /**
   * {@inheritdoc}
   */
  public function layoutAllowed(string $layout_id, ?SectionStorageInterface $storage): bool {
    if ($this->layoutPluginManager === NULL) {
      // No layout system — nothing to enforce against.
      return TRUE;
    }
    return isset($this->allowedLayoutDefinitions($storage)[$layout_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultsStorage(string $entity_type, string $bundle, string $view_mode = 'full'): ?SectionStorageInterface {
    if ($this->sectionStorageManager === NULL) {
      return NULL;
    }

    // Candidate displays the way core's collectRenderDisplays() resolves
    // them: the requested view mode, then 'default'.
    $candidate_ids = [];
    if ($view_mode !== 'default') {
      $candidate_ids[] = "{$entity_type}.{$bundle}.{$view_mode}";
    }
    $candidate_ids[] = "{$entity_type}.{$bundle}.default";

    $displays = $this->entityTypeManager->getStorage('entity_view_display')->loadMultiple($candidate_ids);
    foreach ($candidate_ids as $id) {
      if (isset($displays[$id])) {
        return $this->sectionStorageManager->load('defaults', [
          'display' => EntityContext::fromEntity($displays[$id]),
          'view_mode' => new Context(new ContextDefinition('string'), $displays[$id]->getMode()),
        ]);
      }
    }

    return NULL;
  }

  /**
   * Populated contexts for definition filtering, per core's LB trait.
   *
   * Mirrors LayoutBuilderContextTrait::getPopulatedContexts() (globally
   * available contexts with values, plus the storage's preview contexts) —
   * not used as a trait because this class must be loadable when
   * layout_builder is absent.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $storage
   *   The section storage.
   *
   * @return \Drupal\Core\Plugin\Context\ContextInterface[]
   *   The contexts.
   */
  protected function populatedContexts(SectionStorageInterface $storage): array {
    $key = spl_object_id($storage);
    if (!isset($this->contextsMemo[$key])) {
      $available_ids = array_keys($this->contextRepository->getAvailableContexts());
      $contexts = array_filter(
        $this->contextRepository->getRuntimeContexts($available_ids),
        fn ($context) => $context->hasContextValue(),
      );
      $this->contextsMemo[$key] = $contexts + $storage->getContextsDuringPreview();
    }
    return $this->contextsMemo[$key];
  }

  /**
   * Resolves the (storage, delta) pair addressing a destination section.
   *
   * The pair per-region providers dereference via getSection(delta): for a
   * nested section, the nested-aware storage scoped to the section itself
   * (its local delta is always 0); for a top-level section, the unscoped
   * storage with the row delta.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $storage
   *   The section storage.
   * @param \Drupal\layout_builder\Section $section
   *   The destination section.
   *
   * @return array{0: \Drupal\layout_builder\SectionStorageInterface, 1: int}|null
   *   The pair, or NULL when the section cannot be located in the storage
   *   (per-region precision then degrades to the display-level answer).
   */
  protected function sectionCoordinates(SectionStorageInterface $storage, Section $section): ?array {
    $uuid = SectionIdentity::uuid($section);

    if ($uuid !== NULL && method_exists($storage, 'forSection') && method_exists($storage, 'getNestedContext')) {
      if ($storage->getNestedContext($uuid) !== NULL) {
        return [$storage->forSection($uuid), 0];
      }
      $root = $storage->forSection(NULL);
      foreach ($root->getSections() as $delta => $candidate) {
        if (SectionIdentity::uuid($candidate) === $uuid) {
          return [$root, $delta];
        }
      }
      return NULL;
    }

    // Plain storage (no nesting available): match by identity, then uuid.
    foreach ($storage->getSections() as $delta => $candidate) {
      if ($candidate === $section || ($uuid !== NULL && SectionIdentity::uuid($candidate) === $uuid)) {
        return [$storage, $delta];
      }
    }
    return NULL;
  }

}
