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
use Drupal\daedalus_stylish\Exception\SwatchValidationException;

/**
 * The swatch gestures: mint, update, restore, revert and delete.
 *
 * Mint, update (value and label), revision restore, revert-to-shipped, and
 * delete. The list is the materials roster's (daedalus_blueprint's
 * MaterialsDelivery boots every client with it).
 *
 * The variant gesture manager's twin at value scope. Mint, update and
 * delete are the swatch kind of the material gesture family
 * (DaedalusStylishMaterialsProvider dispatches save_material / update_material /
 * remove_material here; the swatch routes are adapters over the same
 * provider methods); the swatch itself is a site-wide content entity, so
 * its reversibility nets are content nets (revisions on plain sites,
 * workspace staging on workspace sites), never the page's tempstore.
 * Values validate through the
 * value seam (StyleMapValidator::validateSwatchValue()), the same gate the
 * disk lane and blueprint writes converge on (SwatchValueFieldHandler).
 */
class SwatchManager {

  use StringTranslationTrait;

  public function __construct(
    protected StyleMapValidator $validator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SwatchRepository $repository,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected SwatchImporter $importer,
    protected ConditionTypePluginManager $conditionTypes,
    protected SwatchUsage $usage,
    protected ActiveDesignSystem $activeDesignSystem,
    protected DistroLock $distroLock,
  ) {}

  /**
   * How many source entities reference a swatch in their saved values.
   *
   * The usage table's count (see SwatchUsage): host style maps, variant
   * styles, and skin tokens/defaults, distinct per source entity.
   */
  public function usages(DaedalusStylishSwatchInterface $swatch): int {
    return $this->usage->countReferences($swatch);
  }

  /**
   * A swatch as the client speaks it (the gesture-response shape).
   *
   * @return array
   *   {machine_name, uuid, label, kind, value, variable, provider,
   *   shipped_state}. variable is the delivered custom property name
   *   (--swatch-{machine_name}), served resolved so no client re-derives
   *   the namespace rule. value is the stored value verbatim (string or
   *   condition-keyed map): palette faces paint from this data, never from
   *   var() resolution (the inspector DOM may sit outside the token scope).
   *   shipped_state is the variants vocabulary ('unmodified', 'modified' or
   *   'defaults_changed'), or NULL when the swatch has no living disk
   *   source (site-minted, or its provider stopped shipping it), in which
   *   case no revert is ever offered.
   */
  public function swatchPayload(DaedalusStylishSwatchInterface $swatch): array {
    return [
      'machine_name' => $swatch->getMachineName(),
      'uuid' => $swatch->uuid(),
      'label' => (string) $swatch->label(),
      'kind' => $swatch->getKind(),
      'value' => $swatch->getSwatchValue(),
      'variable' => StyleGuide::swatchVariable($swatch->getMachineName()),
      'provider' => $swatch->getProvider(),
      'shipped_state' => $this->shippedState($swatch),
    ];
  }

  /**
   * Computes a swatch's shipped state against the CURRENT disk payload.
   *
   * One stored hash, two comparisons (see ShippedSourceTrait):
   * 'defaults_changed' wins because it is the actionable signal — the
   * provider ships something newer than the last sync, and for a modified
   * swatch the auto-sync deliberately kept the user's value, so the revert
   * button IS the ask.
   */
  protected function shippedState(DaedalusStylishSwatchInterface $swatch): ?string {
    $shipped = $this->importer->getShippedData($swatch);
    if ($shipped === NULL) {
      return NULL;
    }
    if ($swatch->hasNewDefaults($shipped)) {
      return 'defaults_changed';
    }
    return $swatch->isModified() ? 'modified' : 'unmodified';
  }

  /**
   * Loads a swatch by reference (machine name or uuid).
   */
  public function loadSwatch(string $reference): ?DaedalusStylishSwatchInterface {
    return $this->repository->resolve($reference);
  }

  /**
   * Mints a new swatch: the palette's save gesture.
   *
   * The value validates through the value seam before anything persists —
   * kind shape (color scalar, gradient grammar), condition keys on the
   * token axes, and every var() reference — then stores with canonical
   * compound condition spellings, exactly like the blueprint lane. The
   * machine name is minted from the label (it becomes the custom property
   * tail, so authored names are the disk lane's business); collisions get
   * a numeric suffix, so minting never fails on a name.
   *
   * @param string $label
   *   The human label ("Brand red", "Hero fade").
   * @param string $kind
   *   The SwatchKind plugin id ('color', 'gradient').
   * @param string|array $value
   *   The CSS value: a string, or a condition-keyed map with a 'default'
   *   entry on the width/scheme/motion axes.
   * @param string $origin
   *   Who minted it, MaterialRecord::ORIGIN_USER or ORIGIN_AI: the lane's
   *   stamp, carried on the roster record for the life of the site.
   * @param bool $global
   *   The design-system opt-out: a mint joins the ACTIVE design system
   *   and validates against its default skin; TRUE mints a global swatch,
   *   offered under every system (see VariantManager::saveAsVariant()).
   * @param string $description
   *   What the swatch is for and when to reach for it (the AI-facing
   *   face, served in the design system brief); '' for none.
   * @param bool $offered
   *   The AI opt-out: TRUE (the default) shares the swatch with the AI,
   *   FALSE keeps it for UI use only (DaedalusStylishSwatchInterface::isOffered()).
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishSwatchInterface
   *   The saved swatch, published and live.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SwatchValidationException
   */
  public function mint(string $label, string $kind, string|array $value, string $origin = MaterialRecord::ORIGIN_USER, bool $global = FALSE, string $description = '', bool $offered = TRUE): DaedalusStylishSwatchInterface {
    if (trim($label) === '') {
      throw new SwatchValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'A swatch needs a label.', [], 'Name the swatch the way editors should find it in the palette.'),
      ]);
    }
    $design_system = $global ? NULL : $this->activeDesignSystem->activeId();
    $this->validateValue($kind, $value, $design_system);
    $swatch = $this->entityTypeManager->getStorage('daedalus_swatch')->create([
      'label' => trim($label),
      'kind' => $kind,
      'uid' => $this->currentUser->id(),
      'design_system' => $design_system,
      'description' => trim($description),
      'ai_offered' => $offered,
    ]);
    assert($swatch instanceof DaedalusStylishSwatchInterface);
    $swatch->setSwatchValue($this->conditionTypes->normalizeValueConditions($value));
    $swatch->setOrigin($origin);
    $swatch->save();
    return $swatch;
  }

  /**
   * Updates a swatch in place: label, value, description, any of them.
   *
   * The one write behind update_material (kind swatch), the palette card's
   * rename and editor gestures, and the value route: whatever is given
   * lands in ONE new default revision (a bad edit is one restore away,
   * workspace-staged inside a workspace). Machine name and uuid are
   * identity (non-revisionable, never changed), so every var(--swatch-*)
   * reference survives a rename untouched. The value IS a delivered
   * custom property (the live-link decision made flesh): one save restyles
   * every referencing element site-wide instantly. It rides the same seam
   * every other write path converges on (validateSwatchValue against the
   * swatch's OWN kind: kind is identity, an edit never changes what a
   * swatch IS), and conditions store canonical compound spellings.
   * Shipped swatches refuse (the distro lock: the provider's swatches file
   * is their one author, of labels and values alike).
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSwatchInterface $swatch
   *   The swatch.
   * @param string|null $label
   *   The new label, or NULL to leave it.
   * @param string|array|null $value
   *   The new value, or NULL to leave it.
   * @param string|null $description
   *   The new description ('' clears it), or NULL to leave it.
   * @param bool|null $offered
   *   The AI opt-out (TRUE offers the swatch to the AI, FALSE keeps it
   *   for UI use only), or NULL to leave it.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SwatchValidationException
   */
  public function update(DaedalusStylishSwatchInterface $swatch, ?string $label = NULL, string|array|null $value = NULL, ?string $description = NULL, ?bool $offered = NULL): DaedalusStylishSwatchInterface {
    $this->checkShippedLock($swatch);
    if ($label === NULL && $value === NULL && $description === NULL && $offered === NULL) {
      throw new SwatchValidationException([
        new BlueprintError('', 'MISSING_PARAMETER', 'The update changes nothing: pass a label, a value, a description, offered, or several.', [], 'Pass "label" to rename the swatch, "value" to change what it delivers, "description" to say when to reach for it, "offered" to share it with the AI or not.'),
      ]);
    }
    $label = $label === NULL ? NULL : trim($label);
    if ($label === '') {
      throw new SwatchValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'A swatch needs a label.', [], 'Name the swatch the way editors should find it in the palette.'),
      ]);
    }
    if ($value !== NULL) {
      $this->validateValue($swatch->getKind(), $value, $swatch->getDesignSystem());
    }
    $log = [];
    $previous = (string) $swatch->label();
    if ($label !== NULL && $label !== $previous) {
      $swatch->set('label', $label);
      $log[] = sprintf('Renamed from "%s" to "%s".', $previous, $label);
    }
    if ($value !== NULL) {
      $swatch->setSwatchValue($this->conditionTypes->normalizeValueConditions($value));
      $log[] = 'Updated the value.';
    }
    if ($description !== NULL && trim($description) !== $swatch->getDescription()) {
      $swatch->setDescription(trim($description));
      $log[] = 'Updated the description.';
    }
    if ($offered !== NULL && $offered !== $swatch->isOffered()) {
      $swatch->setOffered($offered);
      $log[] = $offered ? 'Offered to the AI.' : 'Withheld from the AI.';
    }
    if ($log === []) {
      return $swatch;
    }
    $swatch->setNewRevision(TRUE);
    $swatch->setRevisionUserId((int) $this->currentUser->id());
    $swatch->setRevisionCreationTime($this->time->getRequestTime());
    $swatch->setRevisionLogMessage(implode(' ', $log));
    $swatch->save();
    return $swatch;
  }

  /**
   * A swatch's revision history, newest first: the restore gesture's menu.
   *
   * @return array[]
   *   {revision_id, label, created, user, log_message, current} per
   *   revision — the variant lifecycle card's exact shape. current marks
   *   the default revision restore is measured against.
   */
  public function revisionsList(DaedalusStylishSwatchInterface $swatch): array {
    $storage = $this->entityTypeManager->getStorage('daedalus_swatch');
    $ids = $storage->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('id', $swatch->id())
      ->sort('revision_id', 'DESC')
      ->execute();
    $current = (int) $swatch->getRevisionId();
    $list = [];
    foreach (array_keys($ids) as $revision_id) {
      $revision = $storage->loadRevision($revision_id);
      if (!$revision instanceof DaedalusStylishSwatchInterface) {
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
   * workspace. The old revision's REVISIONABLE fields (label, value) come
   * back — instantly restyling every element referencing the swatch,
   * because the value IS a live custom property; identity fields (machine
   * name, kind) were never revisioned and cannot drift. Like a revision
   * revert anywhere, the value is stored content that passed the value
   * seam when it was saved, so it restores without re-validation even
   * where the guide has tightened since. Shipped swatches refuse —
   * restoring old content diverges from the disk, which is exactly what
   * the distro lock exists to prevent; revert-to-shipped is their one
   * restore.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SwatchValidationException
   */
  public function restoreRevision(DaedalusStylishSwatchInterface $swatch, int $revision_id): DaedalusStylishSwatchInterface {
    $this->checkShippedLock($swatch);
    $storage = $this->entityTypeManager->getStorage('daedalus_swatch');
    $revision = $storage->loadRevision($revision_id);
    if (!$revision instanceof DaedalusStylishSwatchInterface || (int) $revision->id() !== (int) $swatch->id()) {
      throw new SwatchValidationException([
        new BlueprintError('revision', 'STYLE_SWATCH_REVISION_NOT_FOUND', sprintf('The "%s" swatch has no revision %d.', $swatch->getMachineName(), $revision_id), [], "List the swatch's revisions and restore one of those ids."),
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
   * Reverts a shipped swatch to its current disk defaults.
   *
   * An ORDINARY revisioned save of the payload as the provider ships it
   * right now, so it is one revision revert away from undo on plain sites
   * and stages workspace-wide inside a workspace (unlike the importer's
   * sync, which deliberately escapes the workspace: shipped defaults land
   * live, but a REVERT is a user gesture and belongs in the user's net).
   * The disk value rides the same value seam every other surface validates
   * through: a modified swatch skipped the importer's auto-sync (KEPT), so
   * the current disk payload may never have met the spine on this site.
   * Kind is written from disk too (non-revisionable identity; disk is the
   * author), and the revert re-stamps the shipped source hash, so
   * afterwards the swatch reads unmodified against what disk ships. The
   * label stays as it is: the hash covers {kind, value} only, and label
   * drift rides the importer's next sync.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SwatchValidationException
   */
  public function revert(DaedalusStylishSwatchInterface $swatch): DaedalusStylishSwatchInterface {
    $provider = $swatch->getProvider();
    if ($provider === NULL) {
      throw new SwatchValidationException([
        new BlueprintError('swatch', 'STYLE_SWATCH_NOT_SHIPPED', sprintf('The "%s" swatch was minted on this site; it has no shipped defaults.', $swatch->getMachineName()), [], 'Only module-shipped swatches can revert to their disk defaults.'),
      ]);
    }
    $shipped = $this->importer->getShippedData($swatch);
    if ($shipped === NULL) {
      throw new SwatchValidationException([
        new BlueprintError('swatch', 'SHIPPED_DEFAULTS_MISSING', sprintf('The "%s" module no longer ships defaults for "%s".', $provider, $swatch->getMachineName()), [], 'The swatch lives on as ordinary site content; rename or edit it instead.'),
      ]);
    }
    $this->validateValue($shipped['kind'], $shipped['value'], $swatch->getDesignSystem());

    // Payload and hash both come from the SAME disk read, the importer's
    // own invariant, so the swatch reads unmodified afterwards.
    $swatch->set('kind', $shipped['kind']);
    $swatch->setSwatchValue($this->conditionTypes->normalizeValueConditions($shipped['value']));
    $swatch->setShippedSource($provider, $shipped);
    $swatch->setNewRevision(TRUE);
    $swatch->setRevisionUserId((int) $this->currentUser->id());
    $swatch->setRevisionCreationTime($this->time->getRequestTime());
    $swatch->setRevisionLogMessage(sprintf('Reverted to the shipped defaults of the %s module.', $provider));
    $swatch->save();
    return $swatch;
  }

  /**
   * Deletes a swatch: unreferenced-immediate, or the rewrite-then-delete batch.
   *
   * Lazy repair is impossible in principle — the rewrite materializes the
   * swatch's literal value, and after deletion there is nothing to
   * materialize from — so a REFERENCED swatch always has every
   * var(--swatch-*) rewritten to the literal first, per source entity, and
   * "delete anyway" does not exist: leaving N values dangling is not a
   * button. The work list is the usage table's (workspace publishes resync
   * it, see SwatchWorkspaceSubscriber, so the list is exact); each source's
   * rewrite is an ordinary revisioned save (SwatchDeleteBatch), then the
   * entity delete.
   *
   * Two permission grades gate the gesture, the variant posture exactly:
   * the route admits both, and the CONTEXTUAL gate lives here — deleting a
   * referenced swatch needs "delete swatches in use", because base "delete
   * swatches" only ever authorizes the zero-reference case. Shipped
   * swatches refuse whatever the grade: the importer would re-import on
   * the next sync, so their removal belongs to the provider's swatches
   * file.
   *
   * A referenced swatch is NOT deleted in this request: the Batch API job
   * is staged (batch_set()) and the caller drives it (the controller
   * returns its URL; Drush would call drush_backend_batch_process()). Only
   * a caller that drives the batch may ask for it: on the op spine (a
   * page_edit batch, the AI's door) nothing drives a core Batch, so a
   * referenced swatch refuses there with its count and the owner decides
   * in the palette.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSwatchInterface $swatch
   *   The swatch.
   * @param bool $stage_batch
   *   TRUE when the caller drives the staged rewrite-then-delete batch.
   *
   * @return int
   *   0 when the swatch was deleted immediately (unreferenced); otherwise
   *   the number of source entities the staged batch will rewrite before
   *   deleting.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SwatchValidationException
   */
  public function deleteSwatch(DaedalusStylishSwatchInterface $swatch, bool $stage_batch = TRUE): int {
    $this->checkShippedLock($swatch);
    $references = $this->usage->references($swatch);
    if ($references === []) {
      $swatch->delete();
      return 0;
    }
    if (!$this->currentUser->hasPermission('delete swatches in use') && !$this->currentUser->hasPermission('administer swatches')) {
      throw new SwatchValidationException([
        new BlueprintError('swatch', 'STYLE_SWATCH_IN_USE', sprintf('The "%s" swatch is referenced by %d place(s); deleting it rewrites each reference to the literal value first, which needs the "delete swatches in use" permission.', $swatch->getMachineName(), count($references)), ['usages' => count($references)], 'Ask an administrator, or remove the references first.'),
      ]);
    }
    if (!$stage_batch) {
      throw new SwatchValidationException([
        new BlueprintError('id', 'STYLE_SWATCH_IN_USE', sprintf('The "%s" swatch is referenced by %d place(s); it cannot be removed from here.', $swatch->getMachineName(), count($references)), ['usages' => count($references)], 'Remove the references first (restyle those elements with another value), or delete it from the palette, which rewrites every reference to the literal value before deleting.'),
      ]);
    }
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Deleting the %label swatch', ['%label' => (string) $swatch->label()]))
      ->setInitMessage($this->t('Rewriting %label references in @count places…', [
        '%label' => (string) $swatch->label(),
        '@count' => count($references),
      ]))
      ->setFinishCallback([SwatchDeleteBatch::class, 'finished']);
    foreach ($references as $reference) {
      $batch->addOperation([
        SwatchDeleteBatch::class,
        'rewriteSource',
      ], [(int) $swatch->id(), $reference['source'], $reference['entity_type'], $reference['entity_id']]);
    }
    $batch->addOperation([SwatchDeleteBatch::class, 'deleteSwatch'], [(int) $swatch->id()]);
    batch_set($batch->toArray());
    return count($references);
  }

  /**
   * The distro lock: a swatch with a living disk source is read-only.
   *
   * A shipped swatch is the provider's design vocabulary, synced from its
   * swatches file; letting a site edit it forks the content away from the
   * file and turns every future sync into a conflict. So the disk stays
   * the one author: rename, restore, and delete are refused, and changing
   * the shipped value means changing the module's swatches file. The lock
   * reads the LIVING disk source (the provider ships the entry right now),
   * not the provider field alone: a swatch whose provider stopped shipping
   * it lives on as ordinary site content and stays editable — the exact
   * complement of revert()'s SHIPPED_DEFAULTS_MISSING hint. Revert stays
   * open on locked swatches (it converges TOWARD the disk, never away).
   *
   * The lock is the distro lock (DistroLock): it holds on a consuming site
   * and lifts on a development site whose settings.php names the package
   * author (`$settings['daedalus_blueprint_developer'] = TRUE`), where the
   * site is the authoring canvas and drush carries the result to disk.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SwatchValidationException
   */
  protected function checkShippedLock(DaedalusStylishSwatchInterface $swatch): void {
    if (!$this->distroLock->holds() || $this->importer->getShippedData($swatch) === NULL) {
      return;
    }
    throw new SwatchValidationException([
      new BlueprintError('swatch', 'STYLE_SWATCH_SHIPPED_LOCKED', sprintf('The "%s" swatch is shipped by the "%s" module; shipped swatches are read-only.', $swatch->getMachineName(), (string) $swatch->getProvider()), ['provider' => (string) $swatch->getProvider()], 'Mint your own swatch and reference that instead, or change the shipped defaults in the module\'s swatches file.'),
    ]);
  }

  /**
   * Validates a value through the value seam, throwing the gesture shape.
   *
   * Converges on StyleMapValidator::validateSwatchValue() — the one gate
   * the disk lane and blueprint writes run — whose error paths ('kind',
   * 'value', 'value.{condition}') are already the gesture's parameter
   * space, so nothing re-roots. The universe is the member's: its own
   * design system's, or the active system's for a global swatch.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SwatchValidationException
   */
  protected function validateValue(string $kind, string|array $value, ?string $design_system): void {
    $errors = $this->validator->validateSwatchValue($kind, $value, ['design_system' => $design_system]);
    if ($errors !== []) {
      throw new SwatchValidationException($errors);
    }
  }

}
