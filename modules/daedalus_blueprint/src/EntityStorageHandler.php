<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutTempstoreHelper;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Handles entity loading and persistence with tempstore awareness.
 *
 * Routes load and persist operations to the correct storage backend based on
 * the available module configuration: daedalus_tempstore, layout_builder only,
 * or direct database save. Enforces workspace requirements when the
 * workspaces module is enabled.
 *
 * The Layout Builder path is delegated to LayoutTempstoreHelper, which is
 * injected only when layout_builder is installed (see
 * DaedalusBlueprintServiceProvider). This keeps EntityStorageHandler loadable
 * without layout_builder: all class-load-time coupling to layout_builder lives
 * in the helper, never here.
 */
class EntityStorageHandler {

  /**
   * Constructs an EntityStorageHandler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\daedalus_tempstore\EntityTempstoreRepository|null $entityTempstoreRepository
   *   The entity tempstore repository, or NULL if daedalus_tempstore is not
   *   installed.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\LayoutTempstoreHelper|null $layoutTempstoreHelper
   *   The Layout Builder tempstore helper, or NULL if layout_builder is not
   *   installed.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager, or NULL if workspaces is not installed.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ?EntityTempstoreRepository $entityTempstoreRepository = NULL,
    protected ?LayoutTempstoreHelper $layoutTempstoreHelper = NULL,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Loads an entity, checking tempstore for unsaved edits.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int|string $entity_id
   *   The entity ID.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The entity with any tempstore edits applied, or NULL if not found.
   */
  public function load(string $entity_type, int|string $entity_id): ?FieldableEntityInterface {
    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    }
    catch (\Exception $e) {
      return NULL;
    }

    if (!$entity instanceof FieldableEntityInterface) {
      return NULL;
    }

    // daedalus_tempstore handles all entity types transparently.
    if ($this->entityTempstoreRepository !== NULL) {
      $tempstore_entity = $this->entityTempstoreRepository->get($entity);
      if ($tempstore_entity instanceof FieldableEntityInterface) {
        return $tempstore_entity;
      }
      return $entity;
    }

    // Layout builder only: check LB tempstore for compatible entities.
    if ($this->layoutTempstoreHelper !== NULL && $this->layoutTempstoreHelper->isLayoutCompatible($entity)) {
      return $this->layoutTempstoreHelper->loadFromLayoutTempstore($entity);
    }

    return $entity;
  }

  /**
   * Persists an entity to the appropriate storage backend.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to persist.
   * @param bool $is_new
   *   TRUE if this is a newly created entity.
   * @param bool $force_save
   *   TRUE to force saving directly to the database, bypassing tempstore.
   * @param string[] $element_uuids
   *   UUIDs of elements affected by the operation.
   *
   * @return string
   *   Where the entity was persisted: 'database' or 'tempstore'.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException
   *   When workspace is required but no workspace is active.
   */
  public function persist(FieldableEntityInterface $entity, bool $is_new = FALSE, bool $force_save = FALSE, array $element_uuids = []): string {
    $this->assertWorkspaceAllowed();

    if ($is_new || $force_save) {
      $entity->save();
      $this->firePersistHook($entity, TRUE, $element_uuids);
      return 'database';
    }

    // daedalus_tempstore handles all entity types transparently.
    if ($this->entityTempstoreRepository !== NULL) {
      $this->entityTempstoreRepository->set($entity);
      $this->firePersistHook($entity, FALSE, $element_uuids);
      return 'tempstore';
    }

    // Layout builder only: save to LB tempstore for compatible entities.
    if ($this->layoutTempstoreHelper !== NULL && $this->layoutTempstoreHelper->isLayoutCompatible($entity)) {
      $this->layoutTempstoreHelper->persistToLayoutTempstore($entity);
      $this->firePersistHook($entity, FALSE, $element_uuids);
      return 'tempstore';
    }

    // No tempstore available — save directly to the database.
    $entity->save();
    $this->firePersistHook($entity, TRUE, $element_uuids);
    return 'database';
  }

  /**
   * Checks if a workspace is required but not currently active.
   *
   * @return bool
   *   TRUE if the workspaces module is enabled and no workspace is currently
   *   active.
   */
  public function isWorkspaceRequired(): bool {
    return $this->workspaceManager !== NULL
      && !$this->workspaceManager->hasActiveWorkspace();
  }

  /**
   * Asserts that the current workspace context allows persistence.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException
   *   When workspace is required but no workspace is active.
   */
  private function assertWorkspaceAllowed(): void {
    if ($this->isWorkspaceRequired()) {
      throw new WorkspaceRequiredException(
        'Content changes require an active workspace.'
      );
    }
  }

  /**
   * Invokes the daedalus_blueprint_entity_persisted hook.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The persisted entity.
   * @param bool $saved
   *   TRUE if saved to database, FALSE if written to tempstore.
   * @param string[] $element_uuids
   *   UUIDs of affected elements.
   */
  private function firePersistHook(FieldableEntityInterface $entity, bool $saved, array $element_uuids): void {
    $this->moduleHandler->invokeAll('daedalus_blueprint_entity_persisted', [
      $entity,
      $saved,
      $element_uuids,
    ]);
  }

}
