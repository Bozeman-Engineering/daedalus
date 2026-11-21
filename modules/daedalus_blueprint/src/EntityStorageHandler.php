<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableEntityBundleInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Error;
use Drupal\daedalus_blueprint\Exception\CommitRefusedException;
use Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutTempstoreHelper;
use Drupal\daedalus_blueprint\Validator\SemanticValidator;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Drupal\workspaces\WorkspaceManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Owns both ends of staging: the stage and the commit.
 *
 * Every blueprint write persists through this handler. A write either STAGES
 * (persist(): the working copy goes to tempstore, the database row is
 * untouched) or COMMITS (commit(): the working copy is saved to the database
 * and its stage is cleared). A stage that is not committed ends through
 * discard(). The working copy is the truth on both roads: the
 * spine judged every field on the way in (access, grammar, constraints), so
 * nothing here filters or copies fields. The commit takes one last look at
 * the whole: it refuses (CommitRefusedException) a working copy whose stored
 * entity was saved elsewhere since the copy was taken, and one whole-entity
 * validation finds violations in. Loads answer the staged copy when one
 * exists, the stored row otherwise.
 *
 * The handler speaks hosts: a load answers the host of the staged or stored
 * entity, a persist or commit takes the host and lands it under the host's
 * own persistence policy (HostPersistence: whether the bundle's revision
 * rule applies, whether the host lives in workspaces).
 *
 * Which backend stages is decided by the available module configuration:
 * daedalus_tempstore, layout_builder only, or none (in which case every
 * persist is a commit). Workspace requirements are enforced when the
 * workspaces module is enabled and the host is workspace aware.
 *
 * The Layout Builder path is delegated to LayoutTempstoreHelper, which is
 * injected only when layout_builder is installed (see
 * DaedalusBlueprintServiceProvider). This keeps EntityStorageHandler loadable
 * without layout_builder: all class-load-time coupling to layout_builder lives
 * in the helper, never here.
 */
class EntityStorageHandler {

  use StringTranslationTrait;

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
   * @param \Drupal\daedalus_blueprint\Host\HostResolver $hostResolver
   *   The host resolver: a load answers the host of what it found.
   * @param \Drupal\daedalus_blueprint\Validator\SemanticValidator $semanticValidator
   *   The spine's validator: the commit asks it for the whole entity's
   *   violations.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger: a storage exception at commit is logged whole, the caller
   *   is told only that the save failed.
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
    protected HostResolver $hostResolver,
    protected SemanticValidator $semanticValidator,
    protected LoggerInterface $logger,
    protected ?EntityTempstoreRepository $entityTempstoreRepository = NULL,
    protected ?LayoutTempstoreHelper $layoutTempstoreHelper = NULL,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Loads a host's working copy: the unsaved copy while one exists.
   *
   * The door of whoever edits the host (the write routes, the commit, the
   * editing canvas). A reader that has not already established that its
   * actor may edit the host loads through loadReadable() instead.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int|string $entity_id
   *   The entity ID.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The host of the entity with any tempstore edits applied, or NULL if
   *   not found or not hostable (nothing hosts a config entity other than
   *   a Layout Builder enabled display).
   */
  public function load(string $entity_type, int|string $entity_id): ?HostInterface {
    $entity = $this->loadStored($entity_type, $entity_id);
    return $entity === NULL ? NULL : $this->workingCopy($entity);
  }

  /**
   * Loads the host a reader is shown.
   *
   * The unsaved copy is for editors: it is answered only when the current
   * user has update access to the stored entity. Anyone else is answered
   * the stored entity, as its ordinary page shows it. Update access is
   * asked of the stored entity, never of the unsaved copy, whose own
   * values (its published state, its author) could answer differently.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int|string $entity_id
   *   The entity ID.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The working copy for a user who may update the host, the stored host
   *   for anyone else, or NULL if not found or not hostable. View access
   *   stays the caller's question.
   */
  public function loadReadable(string $entity_type, int|string $entity_id): ?HostInterface {
    $entity = $this->loadStored($entity_type, $entity_id);
    if ($entity === NULL) {
      return NULL;
    }
    return $entity->access('update', $this->currentUser)
      ? $this->workingCopy($entity)
      : $this->hostResolver->fromEntity($entity);
  }

  /**
   * Loads the stored entity of a host.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param int|string $entity_id
   *   The entity ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The stored entity, or NULL if not found or not hostable.
   */
  private function loadStored(string $entity_type, int|string $entity_id): ?EntityInterface {
    try {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    }
    catch (\Exception $e) {
      return NULL;
    }
    return $entity !== NULL && $this->hostResolver->hosts($entity) ? $entity : NULL;
  }

  /**
   * The working copy of a stored entity: its unsaved copy, or itself.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The stored entity, hostable.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface
   *   The host of the unsaved copy while one exists, else of the entity.
   */
  private function workingCopy(EntityInterface $entity): HostInterface {
    // daedalus_tempstore handles every hostable entity transparently (a
    // display stages through the layout strategy, as its defaults storage).
    if ($this->entityTempstoreRepository !== NULL) {
      $tempstore_entity = $this->entityTempstoreRepository->get($entity);
      if ($tempstore_entity instanceof EntityInterface && $this->hostResolver->hosts($tempstore_entity)) {
        return $this->hostResolver->fromEntity($tempstore_entity);
      }
      return $this->hostResolver->fromEntity($entity);
    }

    $host = $this->hostResolver->fromEntity($entity);

    // Layout builder only: check LB tempstore for a host with a document.
    if ($this->layoutTempstoreHelper !== NULL && $host->hasDocument()) {
      return $this->layoutTempstoreHelper->loadFromLayoutTempstore($host);
    }

    return $host;
  }

  /**
   * Persists a host: stages it, or commits it when staging is not the ask.
   *
   * Stage is the default: the working copy goes to the available tempstore
   * (daedalus_tempstore, else Layout Builder's for a host with a document)
   * and the database row is untouched. Three cases commit through
   * commit() instead: a new host ($is_new, there is nothing to stage
   * against), an explicit $force_save (the AI tools' save context), and a
   * site with no tempstore at all. There is no second save road: every
   * database write this handler makes goes through commit().
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host to persist.
   * @param bool $is_new
   *   TRUE if this is a newly created host.
   * @param bool $force_save
   *   TRUE to commit to the database instead of staging.
   * @param string[] $element_uuids
   *   UUIDs of elements affected by the operation.
   *
   * @return string
   *   Where the host was persisted: 'database' or 'tempstore'.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException
   *   When workspace is required but no workspace is active.
   */
  public function persist(HostInterface $host, bool $is_new = FALSE, bool $force_save = FALSE, array $element_uuids = []): string {
    $this->assertWorkspaceAllowed($host);

    if ($is_new || $force_save) {
      $this->commit($host, $element_uuids);
      return 'database';
    }

    // daedalus_tempstore handles all entity types transparently.
    if ($this->entityTempstoreRepository !== NULL) {
      $this->entityTempstoreRepository->set($host->entity());
      $this->firePersistHook($host, FALSE, $element_uuids);
      return 'tempstore';
    }

    // Layout builder only: stage a host with a document in LB tempstore.
    if ($this->layoutTempstoreHelper !== NULL && $host->hasDocument()) {
      $this->layoutTempstoreHelper->persistToLayoutTempstore($host);
      $this->firePersistHook($host, FALSE, $element_uuids);
      return 'tempstore';
    }

    // No tempstore available: nothing can stage, so the write commits.
    $this->commit($host, $element_uuids);
    return 'database';
  }

  /**
   * Commits a host: saves the working copy and ends its stage.
   *
   * The one door from a working copy to the database. The host's entity is
   * saved as-is (no field filter, no copy onto a fresh load: the spine
   * judged every field on the way in, and core sets the original from the
   * unchanged row at save when none is set).
   *
   * Two checks stand before the save, and either refuses with a
   * CommitRefusedException carrying messages, the stage left as it was:
   * - The stored entity must not have moved on (assertNotMoved()). A stage
   *   holds the whole entity, so a working copy taken before someone
   *   else's save would put every field back as it was then.
   * - The whole entity must validate (assertValid()): every field, not only
   *   the ones the staged writes touched, as a stock entity form would
   *   insist. A page carrying an old invalid value cannot be saved until it
   *   is fixed.
   * A storage exception during the save is the same refusal (logged whole,
   * reported plainly), so a caller has one thing to catch.
   *
   * For an existing host whose
   * persistence revisions, the bundle's revision policy applies first,
   * mirroring core's entity form (ContentEntityForm::getNewRevisionDefault):
   * a bundle whose config says so gets a new revision, stamped to the
   * committer and the request time with the log cleared, so history
   * attributes the save to the editor, not to the author of the revision it
   * grew from. A new host has no stage to clear and no revision to grow
   * from, so it is saved and announced only. After the save the entity
   * tempstore entry is deleted (which also ends the history timeline) and
   * the persisted hook fires with saved TRUE.
   *
   * The stage cleared is the host's one stage, whichever translation of
   * the host is handed in: the tempstore key is computed from the host's
   * own object.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The working copy to commit.
   * @param string[] $element_uuids
   *   UUIDs of elements affected by the operation, for the persisted hook.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException
   *   When workspace is required but no workspace is active.
   * @throws \Drupal\daedalus_blueprint\Exception\CommitRefusedException
   *   When the stored entity has moved on, the entity does not validate, or
   *   storage threw; nothing was saved and the stage is as it was.
   */
  public function commit(HostInterface $host, array $element_uuids = []): void {
    $this->assertWorkspaceAllowed($host);

    $entity = $host->entity();
    $is_new = $entity->isNew();
    if (!$is_new) {
      $this->assertNotMoved($entity);
    }
    $this->assertValid($entity);
    if (!$is_new && $host->persistence()->revisions) {
      $this->applyRevisionPolicy($entity);
    }
    try {
      $entity->save();
    }
    catch (EntityStorageException $e) {
      // The exception is the site's to read, not the editor's: it can carry
      // a query. It is logged whole and the caller hears that the save
      // failed.
      Error::logException($this->logger, $e);
      // The refusal's messages are for the editor, so they are translated.
      $message = (string) $this->t('The save failed and nothing was saved. The unsaved changes are still here; the site log has the details.');
      throw new CommitRefusedException(CommitRefusedException::STORAGE, [$message], $e);
    }
    if (!$is_new && $this->entityTempstoreRepository !== NULL) {
      $this->entityTempstoreRepository->delete($entity);
    }
    $this->firePersistHook($host, TRUE, $element_uuids);
  }

  /**
   * Discards a host's unsaved copy: the stage ends, nothing is saved.
   *
   * The one door out of a stage that is not a commit. The host's one stage
   * is deleted (which also ends its history timeline), whichever
   * translation of the host is handed in, and the persisted hook fires for
   * the stored host with saved FALSE: the working copy changed (it is the
   * stored row again), so listeners see a discard like any staged write.
   * A host with no stage is discarded to no effect beyond the hook.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose unsaved copy is dropped; only its entity's identity
   *   is used.
   *
   * @throws \LogicException
   *   On a site without daedalus_tempstore: nothing there holds a stage
   *   this door can end.
   */
  public function discard(HostInterface $host): void {
    if ($this->entityTempstoreRepository === NULL) {
      throw new \LogicException('Discarding an unsaved copy needs daedalus_tempstore: nothing else holds a stage this door can end.');
    }
    $entity = $host->entity();
    $this->entityTempstoreRepository->delete($entity);
    $stored = $this->load($entity->getEntityTypeId(), $entity->id());
    if ($stored !== NULL) {
      $this->firePersistHook($stored, FALSE, []);
    }
  }

  /**
   * Refuses a working copy whose stored entity has moved on.
   *
   * A working copy records where it was taken from by being the whole
   * entity: a revisionable entity carries the revision it was loaded from
   * (through staging and back, however many requests the stage lives), any
   * other entity that tracks changes carries the changed time it was
   * loaded with. The stored entity is read fresh and compared:
   * - Revisionable: the stored default revision must still be the one the
   *   copy was loaded from. Any save in between made a newer one, or (a
   *   bundle that does not revision) is caught by the changed time.
   * - Changed time: the stored entity must not be newer than the copy.
   *   Later, not different: an entity form's submit stamps the copy with
   *   the request time, which is not a save.
   * A host that tracks neither (a display, a frame: config) cannot be
   * compared and is not checked. A stored entity that is gone refuses too.
   *
   * Each sentence is plain text, as every refusal message is: the entity's
   * label stands in it character for character, and whoever shows it (a
   * notification, the form's message area) escapes it once.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The working copy about to be saved; not new.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\CommitRefusedException
   *   When the stored entity is gone or was saved after the copy was taken.
   */
  private function assertNotMoved(EntityInterface $entity): void {
    $revisionable = $entity instanceof RevisionableInterface && $entity->getEntityType()->isRevisionable();
    $changed = $entity instanceof EntityChangedInterface;
    if (!$revisionable && !$changed) {
      return;
    }

    $stored = $this->entityTypeManager->getStorage($entity->getEntityTypeId())->loadUnchanged($entity->id());
    if ($stored === NULL) {
      $message = PlainTextOutput::renderFromHtml($this->t('@label was deleted after these changes were started, so there is nothing to save them to.', ['@label' => $this->nameOf($entity)]));
      throw new CommitRefusedException(CommitRefusedException::MOVED, [$message]);
    }

    $moved = FALSE;
    if ($revisionable && $entity->getLoadedRevisionId() !== NULL) {
      assert($stored instanceof RevisionableInterface);
      $moved = (string) $stored->getRevisionId() !== (string) $entity->getLoadedRevisionId();
    }
    if (!$moved && $changed) {
      assert($stored instanceof EntityChangedInterface);
      $moved = $stored->getChangedTimeAcrossTranslations() > $entity->getChangedTimeAcrossTranslations();
    }
    if ($moved) {
      $message = PlainTextOutput::renderFromHtml($this->t('@label was saved elsewhere after these changes were started, so saving now would overwrite that save. Discard the unsaved changes and make them again on the current version.', ['@label' => $this->nameOf($entity)]));
      throw new CommitRefusedException(CommitRefusedException::MOVED, [$message]);
    }
  }

  /**
   * Refuses a working copy the whole-entity validation finds violations in.
   *
   * Only a fieldable entity validates; a config host has no constraints to
   * ask here.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The working copy about to be saved.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\CommitRefusedException
   *   When there are violations, one message each.
   */
  private function assertValid(EntityInterface $entity): void {
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    $violations = $this->semanticValidator->wholeEntityViolations($entity);
    if ($violations === []) {
      return;
    }
    throw new CommitRefusedException(
      CommitRefusedException::INVALID,
      array_values(array_unique(array_map(
        fn (ConstraintViolationInterface $violation): string => $this->violationMessage($entity, $violation),
        $violations,
      ))),
    );
  }

  /**
   * One violation as a plain sentence: the field's label, then the message.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The validated entity.
   * @param \Symfony\Component\Validator\ConstraintViolationInterface $violation
   *   The violation.
   *
   * @return string
   *   Plain text; a violation on the entity itself carries no label.
   */
  private function violationMessage(FieldableEntityInterface $entity, ConstraintViolationInterface $violation): string {
    // Core's messages carry placeholder markup; the refusal is plain text.
    $message = trim(html_entity_decode(strip_tags((string) $violation->getMessage()), ENT_QUOTES | ENT_HTML5));
    $field_name = explode('.', $violation->getPropertyPath())[0];
    if ($field_name === '' || !$entity->hasField($field_name)) {
      return $message;
    }
    return sprintf('%s: %s', $entity->getFieldDefinition($field_name)->getLabel(), $message);
  }

  /**
   * How a refusal names the entity: its label, or its type and id.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return string
   *   Plain text.
   */
  private function nameOf(EntityInterface $entity): string {
    $label = (string) $entity->label();
    return $label !== '' ? sprintf('"%s"', $label) : sprintf('The %s %s', $entity->getEntityType()->getSingularLabel(), $entity->id());
  }

  /**
   * Marks a revisionable entity's pending save as a new revision.
   *
   * A programmatic $entity->save() keeps writing the CURRENT revision, so a
   * commit of a revisionable entity (nodes, block_content roots, blueprints)
   * would silently rewrite history. The bundle config entity decides (node
   * types' new_revision, block_content types' revision, blueprint types
   * always), and a bundle that cannot answer defaults to no new revision.
   * Revision metadata is stamped fresh so the revision log attributes the
   * save to the committer. A caller that marked the revision itself (the
   * blueprint manager's push, with its own log message) keeps its stamp:
   * the policy decides whether history is written, never what it says.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The host entity about to be saved.
   */
  private function applyRevisionPolicy(object $entity): void {
    if (!$entity instanceof RevisionableInterface || !$entity->getEntityType()->isRevisionable() || $entity->isNewRevision()) {
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
   * Asserts that the current workspace context allows persisting a host.
   *
   * A host that does not live in workspaces (a config host) is never held
   * to one.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host about to persist.
   *
   * @throws \Drupal\daedalus_blueprint\Exception\WorkspaceRequiredException
   *   When workspace is required but no workspace is active.
   */
  private function assertWorkspaceAllowed(HostInterface $host): void {
    if ($host->persistence()->workspaceAware && $this->isWorkspaceRequired()) {
      throw new WorkspaceRequiredException(
        'Content changes require an active workspace.'
      );
    }
  }

  /**
   * Invokes the daedalus_blueprint_entity_persisted hook.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The persisted host.
   * @param bool $saved
   *   TRUE if saved to database, FALSE if written to tempstore.
   * @param string[] $element_uuids
   *   UUIDs of affected elements.
   */
  private function firePersistHook(HostInterface $host, bool $saved, array $element_uuids): void {
    $this->moduleHandler->invokeAll('daedalus_blueprint_entity_persisted', [
      $host,
      $saved,
      $element_uuids,
    ]);
  }

}
