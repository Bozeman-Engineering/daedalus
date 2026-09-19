<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\AnimationValidationException;

/**
 * The animation gestures: mint, update, restore, revert and delete.
 *
 * Mint, update (steps, label, description), revision restore,
 * revert-to-shipped, and delete. The list is the materials roster's
 * (daedalus_blueprint's MaterialsDelivery boots every client with it).
 *
 * The swatch gesture manager's twin at structure scope. Mint, update and
 * delete are the animation kind of the material gesture family
 * (DaedalusStylishMaterialsProvider dispatches save_material / update_material /
 * remove_material here; the animation routes are adapters over the same
 * provider methods); the animation itself is a site-wide content entity,
 * so its reversibility nets are content nets (revisions on plain sites,
 * workspace staging on workspace sites), never the page's tempstore.
 * Steps validate through the steps seam
 * (StyleMapValidator::validateAnimationSteps()), the same gate the disk
 * lane and blueprint writes converge on (AnimationStepsFieldHandler).
 */
class AnimationManager {

  use StringTranslationTrait;

  public function __construct(
    protected StyleMapValidator $validator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AnimationRepository $repository,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected AnimationImporter $importer,
    protected AnimationUsage $usage,
    protected ActiveDesignSystem $activeDesignSystem,
    protected DistroLock $distroLock,
  ) {}

  /**
   * How many source entities wear an animation in their saved values.
   *
   * The usage table's count (see AnimationUsage): host style maps, variant
   * styles and skin defaults, distinct per source entity.
   */
  public function usages(DaedalusStylishAnimationInterface $animation): int {
    return $this->usage->countReferences($animation);
  }

  /**
   * An animation as the client speaks it (the management-read shape).
   *
   * @return array
   *   {machine_name, uuid, label, description, steps, keyframes, provider,
   *   shipped_state}. keyframes is the delivered @keyframes name
   *   (motion-{machine_name}), served resolved so no client re-derives the
   *   namespace rule. steps is the stored map verbatim (canonical offsets).
   *   shipped_state is the variants vocabulary ('unmodified', 'modified' or
   *   'defaults_changed'), or NULL when the animation has no living disk
   *   source (site-minted, or its provider stopped shipping it), in which
   *   case no revert is ever offered.
   */
  public function animationPayload(DaedalusStylishAnimationInterface $animation): array {
    return [
      'machine_name' => $animation->getMachineName(),
      'uuid' => $animation->uuid(),
      'label' => (string) $animation->label(),
      'description' => $animation->getDescription(),
      'steps' => $animation->getSteps(),
      'keyframes' => StyleGuide::animationName($animation->getMachineName()),
      'provider' => $animation->getProvider(),
      'shipped_state' => $this->shippedState($animation),
    ];
  }

  /**
   * Computes an animation's shipped state against the CURRENT disk steps.
   *
   * One stored hash, two comparisons (see ShippedSourceTrait):
   * 'defaults_changed' wins because it is the actionable signal: the
   * provider ships something newer than the last sync, and for a modified
   * animation the auto-sync deliberately kept the user's steps, so the
   * revert button IS the ask.
   */
  protected function shippedState(DaedalusStylishAnimationInterface $animation): ?string {
    $shipped = $this->importer->getShippedData($animation);
    if ($shipped === NULL) {
      return NULL;
    }
    if ($animation->hasNewDefaults($shipped)) {
      return 'defaults_changed';
    }
    return $animation->isModified() ? 'modified' : 'unmodified';
  }

  /**
   * Loads an animation by reference (machine name or uuid).
   */
  public function loadAnimation(string $reference): ?DaedalusStylishAnimationInterface {
    return $this->repository->resolve($reference);
  }

  /**
   * Mints a new animation: the Materials section's save gesture.
   *
   * The steps validate through the steps seam before anything persists
   * (offset grammar, one canonical spelling per offset, every declaration
   * through the universal vocabulary, references), then store with
   * canonical sorted offsets, exactly like the blueprint lane. The machine
   * name is minted from the label (it becomes the keyframes ident tail, so
   * authored names are the disk lane's business); collisions get a numeric
   * suffix, so minting never fails on a name.
   *
   * @param string $label
   *   The human label ("Fade in", "Rise").
   * @param mixed $steps
   *   The keyframe steps: offsets (`from`, `to`, `N%`) to property maps.
   * @param string $description
   *   When to reach for this motion (the AI face on the roster record).
   * @param string $origin
   *   Who minted it, MaterialRecord::ORIGIN_USER or ORIGIN_AI: the lane's
   *   stamp, carried on the roster record for the life of the site.
   * @param bool $global
   *   The design-system opt-out: a mint joins the ACTIVE design system
   *   and validates against its default skin; TRUE mints a global
   *   animation, offered under every system (see
   *   VariantManager::saveAsVariant()).
   * @param bool $offered
   *   The AI opt-out: TRUE (the default) shares the animation with the
   *   AI, FALSE keeps it for UI use only
   *   (DaedalusStylishAnimationInterface::isOffered()).
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishAnimationInterface
   *   The saved animation, published and live.
   *
   * @throws \Drupal\daedalus_stylish\Exception\AnimationValidationException
   */
  public function mint(string $label, mixed $steps, string $description = '', string $origin = MaterialRecord::ORIGIN_USER, bool $global = FALSE, bool $offered = TRUE): DaedalusStylishAnimationInterface {
    if (trim($label) === '') {
      throw new AnimationValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'An animation needs a label.', [], 'Name the animation the way editors should find it in the Materials section.'),
      ]);
    }
    $design_system = $global ? NULL : $this->activeDesignSystem->activeId();
    $this->validateSteps($steps, $design_system);
    $animation = $this->entityTypeManager->getStorage('daedalus_animation')->create([
      'label' => trim($label),
      'uid' => $this->currentUser->id(),
      'design_system' => $design_system,
    ]);
    assert($animation instanceof DaedalusStylishAnimationInterface);
    $animation->setDescription(trim($description));
    $animation->setOffered($offered);
    $animation->setSteps($steps);
    $animation->setOrigin($origin);
    $animation->save();
    return $animation;
  }

  /**
   * Updates an animation in place: label, description, steps, any of them.
   *
   * The one write behind update_material (kind animation), the card's
   * rename gesture and the keyframes editor's commit: whatever is given
   * lands in ONE new default revision (a bad edit is one restore away,
   * workspace-staged inside a workspace). Machine name and uuid are
   * identity (non-revisionable, never changed), so every motion-{name}
   * reference survives a rename untouched. The steps ARE the delivered
   * `@keyframes` body: one save re-animates every wearer site-wide. Shipped
   * animations refuse (the distro lock: the provider's animations file is
   * their one author).
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishAnimationInterface $animation
   *   The animation.
   * @param string|null $label
   *   The new label, or NULL to leave it.
   * @param mixed $steps
   *   The new steps, or NULL to leave them.
   * @param string|null $description
   *   The new description, or NULL to leave it.
   * @param bool|null $offered
   *   The AI opt-out (TRUE offers the animation to the AI, FALSE keeps
   *   it for UI use only), or NULL to leave it.
   *
   * @throws \Drupal\daedalus_stylish\Exception\AnimationValidationException
   */
  public function update(DaedalusStylishAnimationInterface $animation, ?string $label = NULL, mixed $steps = NULL, ?string $description = NULL, ?bool $offered = NULL): DaedalusStylishAnimationInterface {
    $this->checkShippedLock($animation);
    if ($label === NULL && $steps === NULL && $description === NULL && $offered === NULL) {
      throw new AnimationValidationException([
        new BlueprintError('', 'MISSING_PARAMETER', 'The update changes nothing: pass a label, steps, a description, offered, or any combination.', [], 'Pass "label" to rename the animation, "steps" to change its keyframes, "description" to change when to reach for it, "offered" to share it with the AI or not.'),
      ]);
    }
    $label = $label === NULL ? NULL : trim($label);
    if ($label === '') {
      throw new AnimationValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'An animation needs a label.', [], 'Name the animation the way editors should find it in the Materials section.'),
      ]);
    }
    if ($steps !== NULL) {
      $this->validateSteps($steps, $animation->getDesignSystem());
    }
    $log = [];
    $previous = (string) $animation->label();
    if ($label !== NULL && $label !== $previous) {
      $animation->set('label', $label);
      $log[] = sprintf('Renamed from "%s" to "%s".', $previous, $label);
    }
    if ($description !== NULL && trim($description) !== $animation->getDescription()) {
      $animation->setDescription(trim($description));
      $log[] = 'Updated the description.';
    }
    if ($offered !== NULL && $offered !== $animation->isOffered()) {
      $animation->setOffered($offered);
      $log[] = $offered ? 'Offered to the AI.' : 'Withheld from the AI.';
    }
    if ($steps !== NULL) {
      $animation->setSteps($steps);
      $log[] = 'Updated the steps.';
    }
    if ($log === []) {
      return $animation;
    }
    $animation->setNewRevision(TRUE);
    $animation->setRevisionUserId((int) $this->currentUser->id());
    $animation->setRevisionCreationTime($this->time->getRequestTime());
    $animation->setRevisionLogMessage(implode(' ', $log));
    $animation->save();
    return $animation;
  }

  /**
   * An animation's revision history, newest first: the restore menu.
   *
   * @return array[]
   *   {revision_id, label, created, user, log_message, current} per
   *   revision, the swatch card's exact shape. current marks the default
   *   revision restore is measured against.
   */
  public function revisionsList(DaedalusStylishAnimationInterface $animation): array {
    $storage = $this->entityTypeManager->getStorage('daedalus_animation');
    $ids = $storage->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('id', $animation->id())
      ->sort('revision_id', 'DESC')
      ->execute();
    $current = (int) $animation->getRevisionId();
    $list = [];
    foreach (array_keys($ids) as $revision_id) {
      $revision = $storage->loadRevision($revision_id);
      if (!$revision instanceof DaedalusStylishAnimationInterface) {
        continue;
      }
      $list[] = [
        'revision_id' => (int) $revision_id,
        'label' => (string) $revision->label(),
        'created' => (int) $revision->getRevisionCreationTime(),
        'user' => $revision->getRevisionUser()?->getDisplayName(),
        'log_message' => (string) $revision->getRevisionLogMessage(),
        'current' => (int) $revision_id === $current,
      ];
    }
    return $list;
  }

  /**
   * Restores an old revision: a NEW default revision with its content.
   *
   * The standard revision-revert shape, deliberately never a rollback: the
   * restored state lands as a fresh revision on top, so a restore is
   * itself one restore away from undo and stages workspace-wide inside a
   * workspace. The old revision's REVISIONABLE fields (label, description,
   * steps) come back, re-animating every wearer at once; identity fields
   * (machine name) were never revisioned and cannot drift. Like a revision
   * revert anywhere, the steps are stored content that passed the seam
   * when saved, so they restore without re-validation even where the guide
   * has tightened since. Shipped animations refuse; revert-to-shipped is
   * their one restore.
   *
   * @throws \Drupal\daedalus_stylish\Exception\AnimationValidationException
   */
  public function restoreRevision(DaedalusStylishAnimationInterface $animation, int $revision_id): DaedalusStylishAnimationInterface {
    $this->checkShippedLock($animation);
    $storage = $this->entityTypeManager->getStorage('daedalus_animation');
    $revision = $storage->loadRevision($revision_id);
    if (!$revision instanceof DaedalusStylishAnimationInterface || (int) $revision->id() !== (int) $animation->id()) {
      throw new AnimationValidationException([
        new BlueprintError('revision', 'STYLE_ANIMATION_REVISION_NOT_FOUND', sprintf('The "%s" animation has no revision %d.', $animation->getMachineName(), $revision_id), [], "List the animation's revisions and restore one of those ids."),
      ]);
    }
    $revision->setNewRevision(TRUE);
    $revision->isDefaultRevision(TRUE);
    $revision->setRevisionUserId((int) $this->currentUser->id());
    $revision->setRevisionCreationTime($this->time->getRequestTime());
    $revision->setRevisionLogMessage(sprintf('Restored revision %d.', $revision_id));
    $revision->save();
    return $revision;
  }

  /**
   * Reverts a shipped animation to its current disk steps.
   *
   * An ORDINARY revisioned save of the steps as the provider ships them
   * right now, so it is one revision revert away from undo on plain sites
   * and stages workspace-wide inside a workspace (unlike the importer's
   * sync, which deliberately escapes the workspace: shipped defaults land
   * live, but a REVERT is a user gesture and belongs in the user's net).
   * The disk steps ride the same seam every other surface validates
   * through: a modified animation skipped the importer's auto-sync (KEPT),
   * so the current disk steps may never have met the spine on this site.
   * The revert re-stamps the shipped source hash, so afterwards the
   * animation reads unmodified against what disk ships. Label and
   * description stay as they are: the hash covers the steps only, and
   * metadata drift rides the importer's next sync.
   *
   * @throws \Drupal\daedalus_stylish\Exception\AnimationValidationException
   */
  public function revert(DaedalusStylishAnimationInterface $animation): DaedalusStylishAnimationInterface {
    $provider = $animation->getProvider();
    if ($provider === NULL) {
      throw new AnimationValidationException([
        new BlueprintError('animation', 'STYLE_ANIMATION_NOT_SHIPPED', sprintf('The "%s" animation was minted on this site; it has no shipped defaults.', $animation->getMachineName()), [], 'Only module-shipped animations can revert to their disk defaults.'),
      ]);
    }
    $shipped = $this->importer->getShippedData($animation);
    if ($shipped === NULL) {
      throw new AnimationValidationException([
        new BlueprintError('animation', 'SHIPPED_DEFAULTS_MISSING', sprintf('The "%s" module no longer ships defaults for "%s".', $provider, $animation->getMachineName()), [], 'The animation lives on as ordinary site content; rename or edit it instead.'),
      ]);
    }
    $this->validateSteps($shipped, $animation->getDesignSystem());

    // Steps and hash both come from the SAME disk read, the importer's
    // own invariant, so the animation reads unmodified afterwards.
    $animation->setSteps($shipped);
    $animation->setShippedSource($provider, $shipped);
    $animation->setNewRevision(TRUE);
    $animation->setRevisionUserId((int) $this->currentUser->id());
    $animation->setRevisionCreationTime($this->time->getRequestTime());
    $animation->setRevisionLogMessage(sprintf('Reverted to the shipped defaults of the %s module.', $provider));
    $animation->save();
    return $animation;
  }

  /**
   * Deletes an animation: unworn-immediate, or the rewrite-then-delete batch.
   *
   * Lazy repair is impossible in principle (after deletion there is no
   * keyframes to name), so a WORN animation always has every motion-{name}
   * reference rewritten to `none` first, per source entity
   * (AnimationDeleteBatch), and "delete anyway" does not exist: leaving N
   * values naming a keyframes that no longer delivers is not a button.
   * The work list is the usage table's (workspace publishes resync it, see
   * AnimationWorkspaceSubscriber, so the list is exact).
   *
   * Two permission grades gate the gesture, the swatch posture exactly:
   * the route admits both, and the CONTEXTUAL gate lives here: deleting a
   * worn animation needs "delete animations in use", because base "delete
   * animations" only ever authorizes the zero-reference case. Shipped
   * animations refuse whatever the grade: the importer would re-import on
   * the next sync, so their removal belongs to the provider's file.
   *
   * A worn animation is NOT deleted in this request: the Batch API job is
   * staged (batch_set()) and the caller drives it (the controller returns
   * its URL). Only a caller that drives the batch may ask for it: on the
   * op spine (a page_edit batch, the AI's door) nothing drives a core
   * Batch, so a worn animation refuses there with its count and the owner
   * decides in the Materials section.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishAnimationInterface $animation
   *   The animation.
   * @param bool $stage_batch
   *   TRUE when the caller drives the staged rewrite-then-delete batch.
   *
   * @return int
   *   0 when the animation was deleted immediately (unworn); otherwise the
   *   number of source entities the staged batch will rewrite before
   *   deleting.
   *
   * @throws \Drupal\daedalus_stylish\Exception\AnimationValidationException
   */
  public function deleteAnimation(DaedalusStylishAnimationInterface $animation, bool $stage_batch = TRUE): int {
    $this->checkShippedLock($animation);
    $references = $this->usage->references($animation);
    if ($references === []) {
      $animation->delete();
      return 0;
    }
    if (!$this->currentUser->hasPermission('delete animations in use') && !$this->currentUser->hasPermission('administer animations')) {
      throw new AnimationValidationException([
        new BlueprintError('animation', 'STYLE_ANIMATION_IN_USE', sprintf('The "%s" animation is worn by %d place(s); deleting it rewrites each reference to "none" first, which needs the "delete animations in use" permission.', $animation->getMachineName(), count($references)), ['usages' => count($references)], 'Ask an administrator, or remove the references first.'),
      ]);
    }
    if (!$stage_batch) {
      throw new AnimationValidationException([
        new BlueprintError('id', 'STYLE_ANIMATION_IN_USE', sprintf('The "%s" animation is worn by %d place(s); it cannot be removed from here.', $animation->getMachineName(), count($references)), ['usages' => count($references)], 'Remove the references first (restyle those elements with another animation or none), or delete it from the Materials section, which rewrites every reference to "none" before deleting.'),
      ]);
    }
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Deleting the %label animation', ['%label' => (string) $animation->label()]))
      ->setInitMessage($this->t('Rewriting %label references in @count places…', [
        '%label' => (string) $animation->label(),
        '@count' => count($references),
      ]))
      ->setFinishCallback([AnimationDeleteBatch::class, 'finished']);
    foreach ($references as $reference) {
      $batch->addOperation([
        AnimationDeleteBatch::class,
        'rewriteSource',
      ], [(int) $animation->id(), $reference['source'], $reference['entity_type'], $reference['entity_id']]);
    }
    $batch->addOperation([AnimationDeleteBatch::class, 'deleteAnimation'], [(int) $animation->id()]);
    batch_set($batch->toArray());
    return count($references);
  }

  /**
   * The distro lock: an animation with a living disk source is read-only.
   *
   * A shipped animation is the provider's motion vocabulary, synced from
   * its animations file; letting a site edit it forks the content away
   * from the file and turns every future sync into a conflict. So the disk
   * stays the one author: rename, edit, restore and delete are refused,
   * and changing the shipped steps means changing the module's file. The
   * lock reads the LIVING disk source (the provider ships the entry right
   * now), not the provider field alone: an animation whose provider
   * stopped shipping it lives on as ordinary site content and stays
   * editable, the exact complement of revert()'s SHIPPED_DEFAULTS_MISSING
   * hint. Revert stays open on locked animations (it converges TOWARD the
   * disk, never away).
   *
   * The lock is the distro lock (DistroLock): it holds on a consuming site
   * and lifts on a development site whose settings.php names the package
   * author (`$settings['daedalus_blueprint_developer'] = TRUE`), where the
   * site is the authoring canvas and drush carries the result to disk.
   *
   * @throws \Drupal\daedalus_stylish\Exception\AnimationValidationException
   */
  protected function checkShippedLock(DaedalusStylishAnimationInterface $animation): void {
    if (!$this->distroLock->holds() || $this->importer->getShippedData($animation) === NULL) {
      return;
    }
    throw new AnimationValidationException([
      new BlueprintError('animation', 'STYLE_ANIMATION_SHIPPED_LOCKED', sprintf('The "%s" animation is shipped by the "%s" module; shipped animations are read-only.', $animation->getMachineName(), (string) $animation->getProvider()), ['provider' => (string) $animation->getProvider()], 'Mint your own animation and wear that instead, or change the shipped defaults in the module\'s animations file.'),
    ]);
  }

  /**
   * Validates steps through the steps seam, throwing the gesture shape.
   *
   * Converges on StyleMapValidator::validateAnimationSteps(), the one gate
   * the disk lane and blueprint writes run, whose error paths ('steps',
   * 'steps.{offset}', 'steps.{offset}.{property}') are already the
   * gesture's parameter space, so nothing re-roots. The universe is the
   * member's: its own design system's, or the active system's for a
   * global animation.
   *
   * @throws \Drupal\daedalus_stylish\Exception\AnimationValidationException
   */
  protected function validateSteps(mixed $steps, ?string $design_system): void {
    $errors = $this->validator->validateAnimationSteps($steps, ['design_system' => $design_system]);
    if ($errors !== []) {
      throw new AnimationValidationException($errors);
    }
  }

}
