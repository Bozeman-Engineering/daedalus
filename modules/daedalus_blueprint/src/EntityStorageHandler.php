<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableEntityBundleInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutTempstoreHelper;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Owns both ends of staging: the stage and the commit.
 *
 * Every blueprint write persists through this handler. A write either STAGES
 * (persist(): the working copy goes to tempstore, the database row is
 * untouched) or COMMITS (commit(): the working copy is saved to the database
 * and its stage is cleared). The working copy is the truth on both roads: the
 * spine judged every field on the way in (access, grammar, constraints), so
 * nothing here filters, copies or re-judges fields. Loads answer the staged
 * copy when one exists, the stored row otherwise.
 *
 * Which backend stages is decided by the available module configuration:
 * daedalus_tempstore, layout_builder only, or none (in which case every
 * persist is a commit). Workspace requirements are enforced when the
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
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, stamped as the revision author at commit.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service, stamped as the revision time at commit.
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
    protected AccountInterface $currentUser,
    protected TimeInterface $time,
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
   * Persists an entity: stages it, or commits it when staging is not the ask.
   *
   * Stage is the default: the working copy goes to the available tempstore
   * (daedalus_tempstore, else Layout Builder's for a layout-compatible
   * entity) and the database row is untouched. Three cases commit through
   * commit() instead: a new entity ($is_new, there is nothing to stage
   * against), an explicit $force_save (the AI tools' save context), and a
   * site with no tempstore at all. There is no second save road: every
   * database write this handler makes goes through commit().
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to persist.
   * @param bool $is_new
   *   TRUE if this is a newly created entity.
   * @param bool $force_save
   *   TRUE to commit to the database instead of staging.
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
      $this->commit($entity, $element_uuids);
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

    // No tempstore available: nothing can stage, so the write commits.
    $this->commit($entity, $element_uuids);
    return 'database';
  }

  /**
   * Commits an entity: saves the working copy and ends its stage.
   *
   * The one door from a working copy to the database. The entity handed in
   * is saved as-is (no field filter, no copy onto a fresh load: the spine
   * judged every field on the way in, and core sets the original from the
   * unchanged row at save when none is set). For an existing revisionable
   * entity the bundle's revision policy applies first, mirroring core's
   * entity form (ContentEntityForm::getNewRevisionDefault): a bundle whose
   * config says so gets a new revision, stamped to the committer and the
   * request time with the log cleared, so history attributes the save to
   * the editor, not to the author of the revision it grew from. A new entity
   * has no stage to clear and no revision to grow from, so it is saved and
   * announced only. After the save the entity tempstore entry is deleted
   * (which also ends the history timeline) and the persisted hook fires with
   * saved TRUE.
   *
   * Callers hand the default-language object: the tempstore key carries the
   * langcode of the object handed in, and a load through this handler
   * answers that object, so the stage cleared is the one the load found.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The working copy to commit.
   * @param string[] $element_uuids
   *   UUIDs of elements affected by the operation, for the persisted hook.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException
   *   When workspace is required but no workspace is active.
   */
  public function commit(FieldableEntityInterface $entity, array $element_uuids = []): void {
    $this->assertWorkspaceAllowed();

    $is_new = $entity->isNew();
    if (!$is_new) {
      $this->applyRevisionPolicy($entity);
    }
    $entity->save();
    if (!$is_new && $this->entityTempstoreRepository !== NULL) {
      $this->entityTempstoreRepository->delete($entity);
    }
    $this->firePersistHook($entity, TRUE, $element_uuids);
  }

  /**
   * Marks a revisionable entity's pending save as a new revision.
   *
   * A programmatic $entity->save() keeps writing the CURRENT revision, so a
   * commit of a revisionable entity (nodes, block_content roots) would
   * silently rewrite history. The bundle config entity decides (node types'
   * new_revision, block_content types' revision), and a bundle that cannot
   * answer defaults to no new revision. Revision metadata is stamped fresh so
   * the revision log attributes the save to the committer.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity about to be saved.
   */
  private function applyRevisionPolicy(FieldableEntityInterface $entity): void {
    if (!$entity instanceof RevisionableInterface || !$entity->getEntityType()->isRevisionable()) {
      return;
    }
    $bundle_entity_type = $entity->getEntityType()->getBundleEntityType();
    $bundle_entity = $bundle_entity_type
      ? $this->entityTypeManager->getStorage($bundle_entity_type)->load($entity->bundle())
      : NULL;
    if (!$bundle_entity instanceof RevisionableEntityBundleInterface || !$bundle_entity->shouldCreateNewRevision()) {
      return;
    }
    $entity->setNewRevision();
    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionUserId((int) $this->currentUser->id());
      $entity->setRevisionCreationTime($this->time->getRequestTime());
      // Do not carry the previous revision's log message forward.
      $entity->setRevisionLogMessage('');
    }
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
