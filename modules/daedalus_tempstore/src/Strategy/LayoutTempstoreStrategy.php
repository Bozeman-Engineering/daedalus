<?php

namespace Drupal\daedalus_tempstore\Strategy;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Drupal\daedalus_tempstore\SectionStorageHelper;
use Drupal\daedalus_tempstore\WorkspaceKeyTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\ComponentTransform\PresaveSafetyNet;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\LayoutEntityHelperTrait;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\layout_builder\TempStoreIdentifierInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;

/**
 * Layout Builder tempstore strategy.
 *
 * Provides tempstore storage for Layout Builder section storage objects
 * AND layout-compatible entities (which are converted to section storage):
 * a content entity stages as its overrides storage, a Layout Builder
 * enabled display as its defaults storage, a site frame as its frame
 * storage, so a host and its storage share one key wherever they are
 * staged, saved, discarded or undone.
 *
 * Key features:
 * - Handles both SectionStorageInterface and layout-compatible entities
 * - Converts entities to section storage before tempstore operations
 * - Converts DefaultsSectionStorage → OverridesSectionStorage when needed
 * - A display stages as its own defaults storage (the display host); a
 *   site frame as its own frame storage (the frame host)
 * - Static caching of loaded section storage
 * - Storage format: ['section_storage' => SectionStorageInterface]
 * - Collection per storage type: layout_builder.section_storage.{type}
 * - Workspace-aware keys when workspaces module is enabled
 * - Nested storage wrapping (when daedalus_layout is enabled)
 *
 * IMPORTANT: set() is the flush seam for embedded sections: it calls
 * bubbleChangesToRoot() on the (wrapped) storage before storing, which
 * flushes every hydrated embedded section back into its container component
 * unconditionally, deepest-first. Core forms mutate hydrated Sections in
 * place with no signal, so the flush must not depend on a dirty flag; and
 * because hydration is memoized (identity-stable), flushing what was read is
 * an idempotent no-op.
 *
 * @see \Drupal\layout_builder\LayoutTempstoreRepository
 * @see \Drupal\daedalus_layout\SectionStorage\EmbeddedSectionList
 */
class LayoutTempstoreStrategy implements TempstoreStrategyInterface {

  use LayoutEntityHelperTrait;
  use WorkspaceKeyTrait;

  /**
   * The static cache of loaded values.
   *
   * @var \Drupal\layout_builder\SectionStorageInterface[]
   */
  protected array $cache = [];

  /**
   * Constructs a LayoutTempstoreStrategy.
   *
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $tempStoreFactory
   *   The shared tempstore factory.
   * @param \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface $sectionStorageManager
   *   The section storage manager.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager, or NULL if workspaces module is not enabled.
   * @param \Drupal\daedalus_tempstore\History\HistoryRecorder|null $historyRecorder
   *   The history recorder capturing writes for undo.
   * @param \Drupal\daedalus_tempstore\SectionStorageHelper|null $sectionStorageHelper
   *   The shared defaults->overrides upgrade helper. Falls back to a local
   *   instance over the host resolver so direct construction (tests) keeps
   *   working.
   * @param \Drupal\daedalus_blueprint\Host\HostResolver|null $hostResolver
   *   The host resolver: a staged storage answers its host, and a get() by
   *   entity answers that host's entity. Required at runtime; nullable so
   *   direct construction keeps working.
   * @param \Drupal\daedalus_layout\ComponentTransform\PresaveSafetyNet|null $staleSessionNet
   *   daedalus_layout's deprecation-window safety net, or NULL when daedalus_layout is not
   *   enabled. get() runs its load-time half on every staged envelope it
   *   deserializes: a session staged before a structural migration ran may
   *   reference RETIRED plugins and would crash its own edit-mode render,
   *   never reaching the save-time net.
   */
  public function __construct(
    protected SharedTempStoreFactory $tempStoreFactory,
    SectionStorageManagerInterface $sectionStorageManager,
    protected $workspaceManager = NULL,
    protected ?HistoryRecorder $historyRecorder = NULL,
    protected ?SectionStorageHelper $sectionStorageHelper = NULL,
    protected ?HostResolver $hostResolver = NULL,
    protected ?PresaveSafetyNet $staleSessionNet = NULL,
  ) {
    // For LayoutEntityHelperTrait.
    $this->sectionStorageManager = $sectionStorageManager;
    $this->sectionStorageHelper ??= new SectionStorageHelper($sectionStorageManager, $hostResolver ?? \Drupal::service('daedalus_blueprint.host_resolver'));
  }

  /**
   * {@inheritdoc}
   */
  public function supports($subject): bool {
    // Handle wrapped storages by checking the root storage.
    if ($subject instanceof NestedSectionStorageInterface) {
      return $this->supports($subject->getRootStorage());
    }

    // Support section storage directly.
    if ($subject instanceof SectionStorageInterface) {
      return TRUE;
    }

    // A content entity stages as its overrides storage, so only one that
    // owns a document is supported. A page of a non-overridable bundle
    // resolves to its display's defaults storage, which is the display's to
    // stage: claiming the page here would answer a get() with the staged
    // display and a set() by staging the display over the page's own edit.
    // The page stages as an entity instead.
    if ($subject instanceof ContentEntityInterface) {
      $section_storage = $this->getSectionStorageForEntity($subject);
      return $section_storage !== NULL && !($section_storage instanceof DefaultsSectionStorageInterface);
    }

    // A config entity that is its own section list (a Layout Builder
    // enabled display, a site frame) stages as its own storage.
    if ($subject instanceof EntityInterface && SectionStorageHelper::isSectionListConfig($subject)) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function get($subject) {
    // Unwrap if nested to get the correct tempstore key.
    $unwrapped = $this->unwrapStorage($subject);

    // Convert entity to section storage if needed.
    $section_storage = $this->ensureSectionStorage($unwrapped);
    $key = $this->getKey($section_storage);

    // Check if the storage is present in the static cache.
    if (isset($this->cache[$key])) {
      $cached = $this->cache[$key];
      // If subject was an entity, extract and return the entity.
      if ($this->isEntity($unwrapped)) {
        return $this->hostOf($cached)?->entity();
      }
      return $this->wrapStorage($cached);
    }

    $tempstore = $this->getTempstore($section_storage)->get($key);
    if (!empty($tempstore['section_storage'])) {
      $storage_type = $section_storage->getStorageType();
      $stored_section_storage = $tempstore['section_storage'];

      if (!($stored_section_storage instanceof SectionStorageInterface)) {
        throw new \UnexpectedValueException(sprintf('The entry with storage type "%s" and ID "%s" is invalid', $storage_type, $key));
      }

      // The load-time half of daedalus_layout's deprecation-window safety net: a
      // session staged BEFORE a migration ran still holds retired shapes and
      // may not even render (PluginNotFoundException on a deleted layout),
      // so it must converge here, not just at save. When anything was
      // rewritten, the upgraded envelope is persisted in place so the
      // session is converged once. Deliberately NOT recorded in history:
      // undo must never restore a state that cannot render.
      if ($this->staleSessionNet?->convergeStagedStorage($stored_section_storage)) {
        $this->getTempstore($section_storage)
          ->set($key, ['section_storage' => $stored_section_storage]);
      }

      // Set the storage in the static cache.
      $this->cache[$key] = $stored_section_storage;

      // If subject was an entity, extract and return the entity.
      if ($this->isEntity($unwrapped)) {
        return $this->hostOf($stored_section_storage)?->entity();
      }

      return $this->wrapStorage($stored_section_storage);
    }

    // No tempstore found - return original subject (wrapped if applicable).
    // If it was an entity, return the entity; if section storage, return that.
    if ($this->isEntity($unwrapped)) {
      return $unwrapped;
    }
    return $this->wrapStorage($section_storage);
  }

  /**
   * {@inheritdoc}
   */
  public function set($subject): void {
    // An entity stages its EDITABLE storage: the host's own, seeded from
    // the display's template when it was empty, so any staged edit on a
    // page (a field, a title) stages the page's own document with it.
    if ($this->isEntity($subject)) {
      $subject = $this->sectionStorageHelper->getEditableSectionStorage($subject);
    }
    // The flush seam: wrap and bubble so every hydrated embedded section is
    // written back into its container component before the root storage is
    // stored (deepest-first, unconditional).
    $subject = $this->wrapStorage($this->ensureSectionStorage($subject));
    $subject->bubbleChangesToRoot();

    // Unwrap before storing to avoid serializing the wrapper.
    $unwrapped = $this->unwrapStorage($subject);

    // Convert entity to section storage if needed.
    $section_storage = $this->ensureSectionStorage($unwrapped);

    $key = $this->getKey($section_storage);
    $envelope = ['section_storage' => $section_storage];
    // The history tee: capture the envelope at the one place every mutation
    // path writes staged state, just before it lands.
    $this->historyRecorder?->recordWrite($this->getCollection($section_storage), $key, $envelope);
    $this->getTempstore($section_storage)->set($key, $envelope);
    // Update the storage in the static cache.
    $this->cache[$key] = $section_storage;
  }

  /**
   * {@inheritdoc}
   */
  public function has($subject): bool {
    // Unwrap for correct tempstore key lookup.
    $unwrapped = $this->unwrapStorage($subject);

    // Convert entity to section storage if needed.
    $section_storage = $this->ensureSectionStorage($unwrapped);
    $key = $this->getKey($section_storage);

    // Check if the storage is present in the static cache.
    if (isset($this->cache[$key])) {
      return TRUE;
    }

    $tempstore = $this->getTempstore($section_storage)->get($key);
    return !empty($tempstore['section_storage']);
  }

  /**
   * {@inheritdoc}
   */
  public function delete($subject): void {
    // Unwrap for correct tempstore key lookup.
    $unwrapped = $this->unwrapStorage($subject);

    // Convert entity to section storage if needed.
    $section_storage = $this->ensureSectionStorage($unwrapped);
    $key = $this->getKey($section_storage);

    // Staged state is ending (save or discard) — its history goes with it.
    $this->historyRecorder?->recordDelete($this->getCollection($section_storage), $key);
    $this->getTempstore($section_storage)->delete($key);
    // Remove the storage from the static cache.
    unset($this->cache[$key]);
  }

  /**
   * {@inheritdoc}
   *
   * Accepts any supported subject: entities and wrapped storages resolve to
   * their section storage first, so history/timeline lookups can key off an
   * entity the same way set() does. Already-resolved storages pass through.
   */
  public function getKey($subject): string {
    $section_storage = $this->ensureSectionStorage($this->unwrapStorage($subject));
    $key = ($section_storage instanceof TempStoreIdentifierInterface)
      ? $section_storage->getTempstoreKey()
      : $section_storage->getStorageId();

    // A host that does not live in workspaces (a display: config) keys
    // without the workspace suffix; its staged state is the same in every
    // workspace because its save is.
    $host = $this->hostOf($section_storage);
    if ($host !== NULL && !$host->persistence()->workspaceAware) {
      return $key;
    }
    return $this->appendWorkspaceToKey($key);
  }

  /**
   * {@inheritdoc}
   *
   * Accepts any supported subject; see getKey().
   */
  public function getCollection($subject): string {
    $section_storage = $this->ensureSectionStorage($this->unwrapStorage($subject));
    return 'layout_builder.section_storage.' . $section_storage->getStorageType();
  }

  /**
   * Gets the shared tempstore.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   *
   * @return \Drupal\Core\TempStore\SharedTempStore
   *   The tempstore.
   */
  protected function getTempstore(SectionStorageInterface $section_storage) {
    return $this->tempStoreFactory->get($this->getCollection($section_storage));
  }

  /**
   * Checks if subject is an entity (not section storage).
   *
   * @param mixed $subject
   *   The subject to check.
   *
   * @return bool
   *   TRUE if entity, FALSE otherwise.
   */
  protected function isEntity($subject): bool {
    return $subject instanceof EntityInterface && !($subject instanceof SectionStorageInterface);
  }

  /**
   * Ensures the subject is a section storage object.
   *
   * If subject is already section storage, returns it as-is.
   * If subject is a layout-compatible entity, converts it to section storage.
   *
   * @param mixed $subject
   *   The subject (entity or section storage).
   *
   * @return \Drupal\layout_builder\SectionStorageInterface
   *   The section storage.
   */
  protected function ensureSectionStorage($subject): SectionStorageInterface {
    // If already section storage, return as-is.
    if ($subject instanceof SectionStorageInterface) {
      return $subject;
    }

    // Must be a layout-compatible entity at this point: a content entity
    // (its overrides storage) or a config entity that is its own section
    // list (a display's defaults storage, a frame's frame storage).
    if (!($subject instanceof ContentEntityInterface) && !($subject instanceof EntityInterface && SectionStorageHelper::isSectionListConfig($subject))) {
      throw new \InvalidArgumentException('Subject must be SectionStorageInterface, ContentEntityInterface, or a config entity that is its own section list (a Layout Builder enabled display, a site frame)');
    }

    return $this->getSectionStorageForEntity($subject);
  }

  /**
   * Gets section storage for an entity.
   *
   * Delegates to the storage spine's one defaults->overrides upgrade helper
   * so the tempstore write path and the render path (daedalus_layout) resolve the
   * SAME storage and therefore the same tempstore key. The storage's
   * IDENTITY only: a key lookup, a has() or a get() seeds nothing; set()
   * stages the editable (seeded) storage.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The layout builder managed entity.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The section storage for the entity.
   */
  protected function getSectionStorageForEntity(EntityInterface $entity): ?SectionStorageInterface {
    return $this->sectionStorageHelper->resolveSectionStorage($entity);
  }

  /**
   * The host of a section storage: the owner of the storage core resolved.
   *
   * An overrides storage is hosted by the entity in its context, a
   * defaults storage by its display; a storage with no host (core's
   * navigation layout) answers NULL.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $section_storage
   *   The section storage.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The host, or NULL when the storage has none.
   */
  public function hostOf(SectionStorageInterface $section_storage): ?HostInterface {
    return $this->hostResolver?->fromSectionStorage($section_storage);
  }

  /**
   * Wraps a section storage with nested-aware capabilities.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $storage
   *   The storage to wrap.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface
   *   The wrapped storage.
   */
  protected function wrapStorage(SectionStorageInterface $storage): SectionStorageInterface {
    // Nested sections exist only with daedalus_layout, an optional integration.
    if (!class_exists(NestedAwareSectionStorage::class)) {
      return $storage;
    }
    return NestedAwareSectionStorage::wrap($storage);
  }

  /**
   * Unwraps a nested storage to get the root storage.
   *
   * @param mixed $subject
   *   The subject to unwrap.
   *
   * @return mixed
   *   The unwrapped subject (root storage or original).
   */
  protected function unwrapStorage($subject) {
    if ($subject instanceof NestedSectionStorageInterface) {
      return $subject->getRootStorage();
    }
    return $subject;
  }

}
