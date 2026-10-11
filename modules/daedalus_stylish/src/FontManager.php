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
use Drupal\daedalus_stylish\Exception\FontValidationException;

/**
 * The font gestures: mint, update and delete.
 *
 * The animation gesture manager's twin at family scope. Mint, update and
 * delete are the font kind of the material gesture family
 * (DaedalusStylishMaterialsProvider dispatches save_material /
 * update_material / remove_material here; the Fonts tab's routes are
 * adapters over the same provider methods); the font itself is a
 * site-wide content entity, so its reversibility nets are content nets
 * (revisions on plain sites, workspace staging on workspace sites), never
 * the page's tempstore. The family and faces validate through
 * FontValidator, the same gate the disk lane converges on; a delete of a
 * font some value names runs the rewrite-then-delete batch behind the
 * in-use grade (FontDeleteBatch), and a shipped font with a living disk
 * source is read-only under the distro lock.
 */
class FontManager {

  use StringTranslationTrait;

  public function __construct(
    protected FontValidator $validator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FontRepository $repository,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected ActiveDesignSystem $activeDesignSystem,
    protected DistroLock $distroLock,
    protected FontImporter $importer,
    protected FontUsage $usage,
  ) {}

  /**
   * How many source entities name a font's family in their saved values.
   *
   * The usage table's count (see FontUsage): host style maps, variant
   * styles, skin defaults and the skin's font-bound tokens, distinct per
   * source entity.
   */
  public function usages(DaedalusStylishFontInterface $font): int {
    return $this->usage->countReferences($font);
  }

  /**
   * Loads a font by reference (machine name or uuid).
   */
  public function loadFont(string $reference): ?DaedalusStylishFontInterface {
    return $this->repository->resolve($reference);
  }

  /**
   * A font as the client speaks it (the management-read shape).
   *
   * @return array
   *   {machine_name, uuid, label, family, spelling, faces, description,
   *   provider, shipped_state}. spelling is the quoted family as a
   *   font-family value writes it, served resolved so no client
   *   re-derives the rule. shipped_state is the variants vocabulary
   *   ('unmodified', 'modified' or 'defaults_changed'), or NULL when the
   *   font has no living disk source (site-minted, or its provider
   *   stopped shipping it).
   */
  public function fontPayload(DaedalusStylishFontInterface $font): array {
    return [
      'machine_name' => $font->getMachineName(),
      'uuid' => $font->uuid(),
      'label' => (string) $font->label(),
      'family' => $font->getFamily(),
      'spelling' => StyleGuide::fontFamily($font->getFamily()),
      'faces' => $font->getFaces(),
      'description' => $font->getDescription(),
      'provider' => $font->getProvider(),
      'shipped_state' => $this->shippedState($font),
    ];
  }

  /**
   * Computes a font's shipped state against the CURRENT disk payload.
   *
   * The animation manager's rule: one stored hash, two comparisons, and
   * 'defaults_changed' wins because it is the actionable signal (the
   * provider ships something newer than the last sync). The payload is
   * content-addressed on both sides (FontImporter::shippedPayload()), so
   * a byte change in a module's asset reads as a change too.
   */
  protected function shippedState(DaedalusStylishFontInterface $font): ?string {
    $shipped = $this->importer->getShippedData($font);
    if ($shipped === NULL) {
      return NULL;
    }
    if ($font->hasNewDefaults($shipped)) {
      return 'defaults_changed';
    }
    return $font->isModified() ? 'modified' : 'unmodified';
  }

  /**
   * Mints a new font: the Fonts tab's save gesture.
   *
   * The family and faces validate before anything persists (the family
   * grammar, the faces grammar, every face's file resolving to a font
   * file), then store canonical. The machine name is minted from the
   * label; collisions get a numeric suffix, so minting never fails on a
   * name.
   *
   * @param string $label
   *   The human label ("Inter").
   * @param mixed $family
   *   The family name font-family values reference.
   * @param mixed $faces
   *   The faces: a list of {weight?, style?, source: {file: uuid}}.
   * @param string $description
   *   When to reach for this family (the AI face on the roster record).
   * @param string $origin
   *   Who minted it, MaterialRecord::ORIGIN_USER or ORIGIN_AI.
   * @param bool $global
   *   The design-system opt-out: a mint joins the ACTIVE design system;
   *   TRUE mints a global font, offered under every system.
   * @param bool $offered
   *   The AI opt-out: TRUE (the default) shares the font with the AI.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishFontInterface
   *   The saved font, published and live.
   *
   * @throws \Drupal\daedalus_stylish\Exception\FontValidationException
   */
  public function mint(string $label, mixed $family, mixed $faces, string $description = '', string $origin = MaterialRecord::ORIGIN_USER, bool $global = FALSE, bool $offered = TRUE): DaedalusStylishFontInterface {
    if (trim($label) === '') {
      throw new FontValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'A font needs a label.', [], 'Name the font the way editors should find it in the Materials section.'),
      ]);
    }
    $this->validate($family, $faces);
    $font = $this->entityTypeManager->getStorage('daedalus_font')->create([
      'label' => trim($label),
      'uid' => $this->currentUser->id(),
      'design_system' => $global ? NULL : $this->activeDesignSystem->activeId(),
    ]);
    assert($font instanceof DaedalusStylishFontInterface);
    $font->setFamily((string) $family);
    $font->setFaces($faces);
    $font->setDescription(trim($description));
    $font->setOffered($offered);
    $font->setOrigin($origin);
    $font->save();
    return $font;
  }

  /**
   * Updates a font in place: label, family, faces, description, any of them.
   *
   * The one write behind update_material (kind font) and the tab's edit
   * gestures: whatever is given lands in ONE new default revision (a bad
   * edit is one restore away, workspace-staged inside a workspace).
   * Machine name and uuid are identity (non-revisionable, never changed).
   * The faces ARE the delivered at-rules and the family IS the reference:
   * one save restyles every element set in the family. Shipped fonts
   * refuse (the distro lock: the provider's fonts file is their one
   * author).
   *
   * @throws \Drupal\daedalus_stylish\Exception\FontValidationException
   */
  public function update(DaedalusStylishFontInterface $font, ?string $label = NULL, mixed $family = NULL, mixed $faces = NULL, ?string $description = NULL, ?bool $offered = NULL): DaedalusStylishFontInterface {
    $this->checkShippedLock($font);
    if ($label === NULL && $family === NULL && $faces === NULL && $description === NULL && $offered === NULL) {
      throw new FontValidationException([
        new BlueprintError('', 'MISSING_PARAMETER', 'The update changes nothing: pass a label, a family, faces, a description, offered, or any combination.', [], 'Pass "label" to rename the font, "family" to change the name stacks reference, "faces" to change its files, "description" to change when to reach for it, "offered" to share it with the AI or not.'),
      ]);
    }
    $label = $label === NULL ? NULL : trim($label);
    if ($label === '') {
      throw new FontValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'A font needs a label.', [], 'Name the font the way editors should find it in the Materials section.'),
      ]);
    }
    $errors = [];
    if ($family !== NULL) {
      array_push($errors, ...$this->validator->validateFamily($family));
    }
    if ($faces !== NULL) {
      array_push($errors, ...$this->validator->validateFaces($faces));
    }
    if ($errors !== []) {
      throw new FontValidationException($errors);
    }
    $log = [];
    $previous = (string) $font->label();
    if ($label !== NULL && $label !== $previous) {
      $font->set('label', $label);
      $log[] = sprintf('Renamed from "%s" to "%s".', $previous, $label);
    }
    if ($family !== NULL && trim((string) $family) !== $font->getFamily()) {
      $font->setFamily((string) $family);
      $log[] = sprintf('Changed the family to "%s".', trim((string) $family));
    }
    if ($description !== NULL && trim($description) !== $font->getDescription()) {
      $font->setDescription(trim($description));
      $log[] = 'Updated the description.';
    }
    if ($offered !== NULL && $offered !== $font->isOffered()) {
      $font->setOffered($offered);
      $log[] = $offered ? 'Offered to the AI.' : 'Withheld from the AI.';
    }
    if ($faces !== NULL) {
      $font->setFaces($faces);
      $log[] = 'Updated the faces.';
    }
    if ($log === []) {
      return $font;
    }
    $font->setNewRevision(TRUE);
    $font->setRevisionUserId((int) $this->currentUser->id());
    $font->setRevisionCreationTime($this->time->getRequestTime());
    $font->setRevisionLogMessage(implode(' ', $log));
    $font->save();
    return $font;
  }

  /**
   * Deletes a font: unnamed-immediate, or the rewrite-then-delete batch.
   *
   * Lazy repair is impossible in principle (after deletion there is no
   * font-face at-rule to name), so a font some value NAMES always has its family
   * removed from every font-family stack first, per source entity
   * (FontDeleteBatch), and "delete anyway" does not exist: leaving N
   * stacks naming a family that no longer loads is not a button. The work
   * list is the usage table's (workspace publishes resync it, see
   * FontWorkspaceSubscriber, so the list is exact).
   *
   * Two permission grades gate the gesture, the animation posture
   * exactly: the route admits both, and the CONTEXTUAL gate lives here:
   * deleting a named font needs "delete fonts in use", because base
   * "delete fonts" only ever authorizes the zero-reference case. Shipped
   * fonts refuse whatever the grade: the importer would re-import on the
   * next sync, so their removal belongs to the provider's file. The
   * font's files are released from its usage by the entity hooks.
   *
   * A named font is NOT deleted in this request: the Batch API job is
   * staged (batch_set()) and the caller drives it (a route returns its
   * URL). Only a caller that drives the batch may ask for it: on the op
   * spine (a page_edit batch, the AI's door) nothing drives a core Batch,
   * so a named font refuses there with its count and the owner decides in
   * the Materials section.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishFontInterface $font
   *   The font.
   * @param bool $stage_batch
   *   TRUE when the caller drives the staged rewrite-then-delete batch.
   *
   * @return int
   *   0 when the font was deleted immediately (nothing names it);
   *   otherwise the number of source entities the staged batch will
   *   rewrite before deleting.
   *
   * @throws \Drupal\daedalus_stylish\Exception\FontValidationException
   */
  public function deleteFont(DaedalusStylishFontInterface $font, bool $stage_batch = TRUE): int {
    $this->checkShippedLock($font);
    $references = $this->usage->references($font);
    if ($references === []) {
      $font->delete();
      return 0;
    }
    if (!$this->currentUser->hasPermission('delete fonts in use') && !$this->currentUser->hasPermission('administer fonts')) {
      throw new FontValidationException([
        new BlueprintError('font', 'STYLE_FONT_IN_USE', sprintf('The "%s" font is named by %d place(s); deleting it removes the family from each font-family stack first, which needs the "delete fonts in use" permission.', $font->getMachineName(), count($references)), ['usages' => count($references)], 'Ask an administrator, or remove the references first.'),
      ]);
    }
    if (!$stage_batch) {
      throw new FontValidationException([
        new BlueprintError('id', 'STYLE_FONT_IN_USE', sprintf('The "%s" font is named by %d place(s); it cannot be removed from here.', $font->getMachineName(), count($references)), ['usages' => count($references)], 'Remove the references first (set those elements in another family), or delete it from the Materials section, which removes the family from every stack before deleting.'),
      ]);
    }
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Deleting the %label font', ['%label' => (string) $font->label()]))
      ->setInitMessage($this->t('Removing %label from font-family stacks in @count places…', [
        '%label' => (string) $font->label(),
        '@count' => count($references),
      ]))
      ->setFinishCallback([FontDeleteBatch::class, 'finished']);
    foreach ($references as $reference) {
      $batch->addOperation([
        FontDeleteBatch::class,
        'rewriteSource',
      ], [(int) $font->id(), $reference['source'], $reference['entity_type'], $reference['entity_id']]);
    }
    $batch->addOperation([FontDeleteBatch::class, 'deleteFont'], [(int) $font->id()]);
    batch_set($batch->toArray());
    return count($references);
  }

  /**
   * The distro lock: a font with a living disk source is read-only.
   *
   * A shipped font is the provider's type vocabulary, synced from its
   * fonts file; letting a site edit it forks the content away from the
   * file and turns every future sync into a conflict. So the disk stays
   * the one author: rename, edit and delete are refused, and changing the
   * shipped faces means changing the module's file and assets. The lock
   * reads the LIVING disk source (the provider ships the entry right
   * now), not the provider field alone: a font whose provider stopped
   * shipping it lives on as ordinary site content and stays editable, the
   * animation precedent.
   *
   * The lock is the distro lock (DistroLock): it holds on a consuming site
   * and lifts on a development site whose settings.php names the package
   * author (`$settings['daedalus_blueprint_developer'] = TRUE`), where the
   * site is the authoring canvas and the ship lane carries the result to
   * disk.
   *
   * @throws \Drupal\daedalus_stylish\Exception\FontValidationException
   */
  protected function checkShippedLock(DaedalusStylishFontInterface $font): void {
    if (!$this->distroLock->holds() || $this->importer->getShippedData($font) === NULL) {
      return;
    }
    throw new FontValidationException([
      new BlueprintError('font', 'STYLE_FONT_SHIPPED_LOCKED', sprintf('The "%s" font is shipped by the "%s" module; shipped fonts are read-only.', $font->getMachineName(), (string) $font->getProvider()), ['provider' => (string) $font->getProvider()], 'Mint your own font and use that instead, or change the shipped defaults in the module\'s fonts file.'),
    ]);
  }

  /**
   * Validates a family and faces, throwing the gesture shape.
   *
   * @throws \Drupal\daedalus_stylish\Exception\FontValidationException
   */
  protected function validate(mixed $family, mixed $faces): void {
    $errors = $this->validator->validate($family, $faces);
    if ($errors !== []) {
      throw new FontValidationException($errors);
    }
  }

}
