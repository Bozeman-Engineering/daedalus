<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemMemberInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint_stencil\Exception\StencilExportException;
use Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\media\MediaInterface;

/**
 * The stencil gestures and the variant-ownership lifecycle.
 *
 * Save-as-stencil, update (push and the palette face), adopt, release and
 * transfer: the one implementation behind the stencil kind of the material
 * gesture family (save_material / update_material / remove_material,
 * dispatched to StencilMaterialsProvider) and the ownership routes
 * (StencilApiController): a stencil is a site-wide content entity, so its
 * reversibility nets are content nets (revisions on plain sites, workspace
 * staging on workspace sites). The capture gestures mutate no staged
 * entity; they only READ the staged subtree, captured through
 * StencilExporter exactly as the ship lane captures it (media
 * and entity references preserved, inexpressible style keys dropped WITH
 * warnings).
 *
 * Update targets CONTENT stencils only. A file-shipped stencil has no
 * entity to update: it is a file in its module, read-only in every UI and
 * op (no shadowing), and resolveUpdatable() refuses it loudly so the
 * client can say why instead of guessing at a 404. The distro lock
 * (DistroLock) decides what the refusal points at. While the lock holds
 * (a consuming site) the site forks: save the composed element as a NEW
 * stencil. Under the developer gate (`$settings['daedalus_blueprint_developer']
 * = TRUE`, the package author's site) the update lane is drush
 * `daedalus-blueprint:stencil-ship` from a SAVED placement, which rewrites
 * the file in its module; the refusal names that lane. The record flag
 * `updatable` stays FALSE for file stencils either way (the op never
 * writes them), so the UI verbs degrade the same under both.
 *
 * Membership: a saved stencil joins the ACTIVE design system unless the
 * gesture opts out (global), the rule every mint shares; the variants a
 * stencil adopts or takes by transfer carry the stencil's membership from
 * then on (an owned look is offered where its owner is, so it belongs
 * where its owner belongs).
 */
class StencilManager {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StencilExporterInterface $exporter,
    protected StencilDiscoveryInterface $discovery,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected ActiveDesignSystem $activeDesignSystem,
    protected DistroLock $distroLock,
  ) {}

  /**
   * Saves a staged element subtree as a new content stencil.
   *
   * The machine name auto-generates from the label at presave, unique in
   * the combined (file + content) vocabulary, so a save never collides.
   * The new stencil is published immediately: the gesture's outcome is a
   * palette entry, and unpublishing is an admin-listing decision.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The host entity carrying the element. Through the ops spine this is
   *   the batch's live entity, so a subtree composed by earlier operations
   *   in the SAME batch captures exactly as staged.
   * @param string $element_uuid
   *   UUID of the root element to capture: a section (top-level band or
   *   nested) or an inline-block component.
   * @param string $label
   *   The human label shown in the palette.
   * @param string $description
   *   What the stencil is for. Required: the description is half the
   *   palette entry's face, whichever source a stencil comes from.
   * @param string|null $icon
   *   The palette icon as a media reference in the icon value grammar's
   *   media spelling (`media:<uuid>`), or NULL/'' for no icon (the palette
   *   renders its default stencil icon). Optional and media-only: content
   *   stencils store the icon as a media reference, so the path spelling
   *   belongs to module-shipped stencil files alone, and referencing
   *   EXISTING media is the whole vocabulary (the AI shares this gesture,
   *   and it never uploads).
   * @param string $origin
   *   Who minted it, MaterialRecord::ORIGIN_USER or ORIGIN_AI: the lane's
   *   stamp (the AI tools pass ai), carried on the roster record for the
   *   life of the site.
   * @param bool $global
   *   TRUE saves a global stencil, a member of no design system, offered
   *   under every one. FALSE (the default every door shares) makes it a
   *   member of the active design system: a composition is saved against
   *   the vocabulary the editor is looking at. Global either way when no
   *   design system is active.
   * @param bool $offered
   *   The AI opt-out: TRUE (the default) shares the stencil with the AI,
   *   FALSE keeps it for the palette only (StencilInterface::isOffered()).
   *
   * @return \Drupal\daedalus_blueprint_stencil\StencilGestureResult
   *   The saved stencil and any capture warnings.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException
   */
  public function saveAsStencil(FieldableEntityInterface $entity, string $element_uuid, string $label, string $description, ?string $icon = NULL, string $origin = MaterialRecord::ORIGIN_USER, bool $global = FALSE, bool $offered = TRUE): StencilGestureResult {
    $errors = [];
    if (trim($label) === '') {
      $errors[] = new BlueprintError('label', 'MISSING_PARAMETER', 'A stencil needs a label.', [], 'Name the stencil the way editors should find it in the palette.');
    }
    if (trim($description) === '') {
      $errors[] = new BlueprintError('description', 'MISSING_PARAMETER', 'A stencil needs a description.', [], 'Describe what the stencil is for; the palette and the AI both read it.');
    }
    $icon_media = NULL;
    try {
      $icon_media = $this->resolveIconMedia($icon);
    }
    catch (StencilGestureException $exception) {
      $errors = array_merge($errors, $exception->getErrors());
    }
    if ($errors !== []) {
      throw new StencilGestureException($errors);
    }

    $export = $this->capture($entity, $element_uuid);

    $stencil = $this->entityTypeManager->getStorage('stencil')->create([
      'label' => trim($label),
      'description' => trim($description),
      'uid' => $this->currentUser->id(),
      'ai_offered' => $offered,
    ]);
    assert($stencil instanceof StencilInterface);
    $stencil->setElement($export->definition['element']);
    $stencil->setIcon($icon_media);
    $stencil->setOrigin($origin);
    $stencil->setDesignSystem($global ? NULL : $this->activeDesignSystem->activeId());
    $stencil->save();

    return new StencilGestureResult($stencil, $export->warnings);
  }

  /**
   * Updates a content stencil in place: a fresh capture, the palette face.
   *
   * The one write behind update_material (kind stencil) and the details
   * route: whatever the changes carry lands in ONE new default revision, so
   * a bad update is one revert away, and workspace-staged inside a
   * workspace. Keys:
   * - element: the uuid of a staged element whose subtree is captured
   *   (push). Placements never re-sync; only FUTURE placements stamp the
   *   pushed subtree. Needs the host entity.
   * - label, description: the palette face; both required non-empty when
   *   present (the description is half the palette entry's face, the admin
   *   edit form's grammar).
   * - icon: PRESENT writes (the `media:<uuid>` spelling, or NULL / '' to
   *   clear back to the palette default); ABSENT leaves the stored icon
   *   standing.
   * - offered: the AI opt-out, a boolean (TRUE shares the stencil with
   *   the AI, FALSE keeps it for the palette only); ABSENT leaves the
   *   stored flag alone.
   *
   * @param \Drupal\daedalus_blueprint_stencil\StencilInterface $stencil
   *   The content stencil to update (see resolveUpdatable()).
   * @param array $changes
   *   The keys above; anything else is ignored.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The host entity carrying the element, when `element` is present.
   *
   * @return \Drupal\daedalus_blueprint_stencil\StencilGestureResult
   *   The updated stencil and any capture warnings.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException
   */
  public function update(StencilInterface $stencil, array $changes, ?FieldableEntityInterface $entity = NULL): StencilGestureResult {
    $errors = [];
    if (array_key_exists('label', $changes) && trim((string) $changes['label']) === '') {
      $errors[] = new BlueprintError('label', 'MISSING_PARAMETER', 'A stencil needs a label.', [], 'Name the stencil the way editors should find it in the palette.');
    }
    if (array_key_exists('description', $changes) && trim((string) $changes['description']) === '') {
      $errors[] = new BlueprintError('description', 'MISSING_PARAMETER', 'A stencil needs a description.', [], 'Describe what the stencil is for; the palette and the AI both read it.');
    }
    $icon_media = NULL;
    if (array_key_exists('icon', $changes)) {
      try {
        $icon_media = $this->resolveIconMedia($changes['icon']);
      }
      catch (StencilGestureException $exception) {
        $errors = array_merge($errors, $exception->getErrors());
      }
    }
    if ($errors !== []) {
      throw new StencilGestureException($errors);
    }

    $log = [];
    $warnings = [];
    if (isset($changes['element'])) {
      if ($entity === NULL) {
        throw new StencilGestureException([
          new BlueprintError('element', 'MISSING_PARAMETER', 'A push captures an element of a page; this lane has no page behind it.', [], 'Run update_material as a page_edit op on the page the element sits on.'),
        ]);
      }
      $export = $this->capture($entity, (string) $changes['element']);
      $stencil->setElement($export->definition['element']);
      $warnings = $export->warnings;
      $log[] = sprintf('Element pushed from %s %s.', $entity->getEntityTypeId(), $entity->id() ?? $entity->uuid());
    }
    if (array_key_exists('label', $changes)) {
      $stencil->set('label', trim((string) $changes['label']));
    }
    if (array_key_exists('description', $changes)) {
      $stencil->setDescription(trim((string) $changes['description']));
    }
    if (array_key_exists('icon', $changes)) {
      $stencil->setIcon($icon_media);
    }
    if (array_intersect(['label', 'description', 'icon'], array_keys($changes)) !== []) {
      $log[] = 'Stencil details updated.';
    }
    if (array_key_exists('offered', $changes) && (bool) $changes['offered'] !== $stencil->isOffered()) {
      $stencil->setOffered((bool) $changes['offered']);
      $log[] = $stencil->isOffered() ? 'Offered to the AI.' : 'Withheld from the AI.';
    }
    $stencil->setNewRevision(TRUE);
    $stencil->setRevisionUserId((int) $this->currentUser->id());
    $stencil->setRevisionCreationTime($this->time->getRequestTime());
    $stencil->setRevisionLogMessage(implode(' ', $log));
    $stencil->save();

    return new StencilGestureResult($stencil, $warnings);
  }

  /**
   * Adopts style variants into a stencil's ownership.
   *
   * The ownership half of the stencil save and update gestures:
   * the dialogs list candidate variants (the capture's references, checked
   * by default) and the op passes the picked ones here — they become the
   * stencil's own, offered only on its placements from now on. Ownership
   * is CURATION, not provenance: a stencil's owned variants are the set of
   * switchable looks it offers, independent of what its element
   * currently wears, so adopting a look the subtree does not (yet)
   * reference is legitimate curation, not an error.
   *
   * Loud by design, in order: adoption requires daedalus_stylish (the field this
   * module provides only exists on daedalus_variant); every reference must
   * resolve to a variant; shipped (disk-lane) variants refuse — release and
   * transfer refuse them too, so a shipped adoption would be irreversible by
   * gesture, and the module's variants file stays authoritative; and a
   * variant another stencil already owns ON EITHER LANE (content reference
   * or stencil_file machine name) is refused — one owner max is the ONE
   * adoption invariant (soft offering never means silent re-owning; release
   * or transfer it instead).
   *
   * An adopted variant takes the stencil's membership: an owned look is
   * offered only on its owner's placements, so it belongs to the design
   * system its owner belongs to (global under a global stencil).
   *
   * @param \Drupal\daedalus_blueprint_stencil\StencilInterface $stencil
   *   The freshly saved (or existing) content stencil.
   * @param string[] $references
   *   Variant references (machine names or uuids) to adopt.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException
   */
  public function adoptVariants(StencilInterface $stencil, array $references): void {
    if (!$this->entityTypeManager->hasDefinition('daedalus_variant')) {
      throw new StencilGestureException([
        new BlueprintError('adopt_variants', 'STENCIL_ADOPTION_UNAVAILABLE', 'Adopting variants requires the daedalus_stylish module.', [], 'Enable daedalus_stylish, or save the stencil without adopt_variants.'),
      ]);
    }
    foreach ($references as $reference) {
      $variant = $this->loadVariantByReference($reference);
      if ($variant === NULL) {
        throw new StencilGestureException([
          new BlueprintError('adopt_variants', 'STENCIL_ADOPT_UNKNOWN_VARIANT', sprintf('"%s" is not a saved style variant.', $reference), [], 'Reference variants by machine name or uuid.'),
        ]);
      }
      $this->refuseShipped($variant, 'adopt_variants');
      $machine_name = (string) $variant->get('machine_name')->value;
      $owner_id = $variant->get('stencil')->target_id;
      $file_owner = (string) ($variant->get('stencil_file')->value ?? '');
      if ($file_owner !== '' || ($owner_id !== NULL && (int) $owner_id !== (int) $stencil->id())) {
        throw new StencilGestureException([
          new BlueprintError('adopt_variants', 'STENCIL_ADOPT_ALREADY_OWNED', sprintf('The "%s" variant already belongs to another stencil.', $machine_name), ['owner' => $file_owner !== '' ? $file_owner : $owner_id], 'A variant has one owner; release it there first (or transfer it), or leave it out of adopt_variants.'),
        ]);
      }
      $variant->set('stencil', $stencil->id());
      $this->stampMembership($variant, $stencil);
      $variant->save();
    }
  }

  /**
   * Releases an owned variant back to general-purpose.
   *
   * The undo of adoption: both ownership lanes clear (the content-lane
   * entity reference and the file-lane machine name), so the variant
   * offers by its scope everywhere again. Releasing an ORPHANED variant
   * (owner deleted or undiscoverable) is the cleanup gesture that makes it
   * usable again, so orphanhood never refuses here.
   *
   * Shipped (disk-lane) variants refuse: their variants file re-adopts at
   * the next import, so a database-side release would silently un-do
   * itself. The disk stays authoritative for shipped content.
   *
   * @param string $reference
   *   A variant machine name or uuid.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The released variant.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException
   */
  public function releaseVariant(string $reference): ContentEntityInterface {
    $variant = $this->resolveOwnableVariant($reference);
    if ($variant->get('stencil')->isEmpty() && (string) ($variant->get('stencil_file')->value ?? '') === '') {
      throw new StencilGestureException([
        new BlueprintError('variant', 'STENCIL_RELEASE_NOT_OWNED', sprintf('The "%s" variant is not owned by any stencil.', $variant->get('machine_name')->value), [], 'Only owned variants release; this one already offers by its scope everywhere.'),
      ]);
    }
    $variant->set('stencil', NULL);
    $variant->set('stencil_file', NULL);
    $variant->save();
    return $variant;
  }

  /**
   * Transfers a variant's ownership to a content stencil, atomically.
   *
   * Release + adopt composed server-side as ONE gesture (a single save):
   * the route exists precisely for the owned-by-another case, where
   * composing the two manually would expose the intermediate unowned
   * state. The variant's previous state never gates it — owned by another
   * stencil, orphaned, or unowned (a transfer onto an unowned variant is
   * adopt-later by route), the outcome is the same single owner.
   *
   * The target is a CONTENT stencil (resolveUpdatable(): file-shipped
   * targets refuse loudly — only the disk lane writes file-stencil
   * ownership). Shipped variants refuse for the same reason release does:
   * the disk lane re-asserts its stencil key at the next import.
   *
   * @param string $reference
   *   A variant machine name or uuid.
   * @param \Drupal\daedalus_blueprint_stencil\StencilInterface $stencil
   *   The content stencil taking ownership.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The transferred variant.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException
   */
  public function transferVariant(string $reference, StencilInterface $stencil): ContentEntityInterface {
    $variant = $this->resolveOwnableVariant($reference);
    $variant->set('stencil', $stencil->id());
    $variant->set('stencil_file', NULL);
    $this->stampMembership($variant, $stencil);
    $variant->save();
    return $variant;
  }

  /**
   * Gives a variant its new owner's membership (adoption and transfer).
   *
   * The variant is typed loosely because this module never requires
   * daedalus_stylish; every daedalus_variant is a member entity, so the guard only
   * documents the seam.
   */
  protected function stampMembership(ContentEntityInterface $variant, StencilInterface $stencil): void {
    if ($variant instanceof DesignSystemMemberInterface) {
      $variant->setDesignSystem($stencil->getDesignSystem());
    }
  }

  /**
   * The variants a stencil currently owns, on either lane.
   *
   * The ownership question asked from the stencil's side: every variant
   * whose content-lane reference or file-lane machine name resolves to
   * this stencil. An ownership QUERY, never a capture walk — ownership is
   * curation, so the owned set is independent of what the stencil's
   * element currently wears (an adopted-but-unworn look is owned all the
   * same, and the update dialog's roster must say so).
   *
   * @param \Drupal\daedalus_blueprint_stencil\StencilInterface $stencil
   *   A content stencil.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   The owned daedalus_variant entities, sorted by label. Empty without
   *   daedalus_stylish (the ownership fields only exist on daedalus_variant).
   */
  public function ownedVariants(StencilInterface $stencil): array {
    if (!$this->entityTypeManager->hasDefinition('daedalus_variant')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition($query->orConditionGroup()
      ->condition('stencil', $stencil->id())
      ->condition('stencil_file', $stencil->getMachineName()));
    $query->sort('label');
    return array_values($storage->loadMultiple($query->execute()));
  }

  /**
   * A variant's ownership state as the client speaks it.
   *
   * The ownership routes' response shape: the owner is the stencil
   * MACHINE NAME (the one vocabulary provenance stamps and payloads
   * speak), or NULL when unowned. Deliberately thinner than daedalus_stylish's
   * variantPayload(): the ownership gestures mutate only ownership, so
   * they echo only ownership.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $variant
   *   A daedalus_variant entity.
   *
   * @return array
   *   {machine_name, uuid, stencil}.
   */
  public function variantOwnershipPayload(ContentEntityInterface $variant): array {
    $owner = NULL;
    if (!$variant->get('stencil')->isEmpty()) {
      $entity = $variant->get('stencil')->entity;
      $owner = $entity instanceof StencilInterface ? $entity->getMachineName() : NULL;
    }
    elseif (($file = (string) ($variant->get('stencil_file')->value ?? '')) !== '') {
      $owner = $file;
    }
    return [
      'machine_name' => (string) $variant->get('machine_name')->value,
      'uuid' => $variant->uuid(),
      'stencil' => $owner,
    ];
  }

  /**
   * Resolves an ownership gesture's variant, loudly, refusing shipped.
   *
   * The shared front door of release and transfer: daedalus_stylish must be
   * installed (the ownership fields only exist on daedalus_variant), the
   * reference must resolve, and shipped (disk-lane) variants refuse —
   * their variants file owns their ownership, and a database-side change
   * would silently un-do itself at the next import.
   *
   * @param string $reference
   *   A variant machine name or uuid.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The variant, site-created and mutable.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException
   */
  protected function resolveOwnableVariant(string $reference): ContentEntityInterface {
    if (!$this->entityTypeManager->hasDefinition('daedalus_variant')) {
      throw new StencilGestureException([
        new BlueprintError('variant', 'STENCIL_ADOPTION_UNAVAILABLE', 'Variant ownership requires the daedalus_stylish module.', [], 'Enable daedalus_stylish to use owned variants.'),
      ]);
    }
    $variant = $this->loadVariantByReference($reference);
    if ($variant === NULL) {
      throw new StencilGestureException([
        new BlueprintError('variant', 'STENCIL_VARIANT_NOT_FOUND', sprintf('"%s" is not a saved style variant.', $reference), [], 'Reference variants by machine name or uuid.'),
      ]);
    }
    $this->refuseShipped($variant, 'variant');
    return $variant;
  }

  /**
   * Refuses a shipped (disk-lane) variant for an ownership gesture, loudly.
   *
   * The ONE shipped refusal every ownership gesture shares: release and
   * transfer reach it through resolveOwnableVariant(), adoption calls it
   * directly. The module's variants file is authoritative for shipped
   * content — a database-side release or transfer would silently un-do
   * itself at the next import, and a shipped ADOPTION would be irreversible
   * by gesture (release refuses right back, the importer's absent stencil
   * key never clears it).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $variant
   *   A daedalus_variant entity.
   * @param string $field
   *   The gesture parameter the refusal points at ('variant' for the
   *   release/transfer routes, 'adopt_variants' for the capture ops).
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException
   */
  protected function refuseShipped(ContentEntityInterface $variant, string $field): void {
    $provider = (string) ($variant->get('provider')->value ?? '');
    if ($provider !== '') {
      throw new StencilGestureException([
        new BlueprintError($field, 'STENCIL_VARIANT_SHIPPED', sprintf('The "%s" variant is shipped by the "%s" module; its ownership follows the module\'s variants file.', $variant->get('machine_name')->value, $provider), ['provider' => $provider], 'Change the stencil key in the module\'s variants file, or copy it as your own variant and curate the copy.'),
      ]);
    }
  }

  /**
   * Loads a daedalus_variant by machine name or uuid, or NULL.
   *
   * The one reference vocabulary every ownership gesture speaks, matching
   * how the client and the ops reference variants everywhere else.
   */
  protected function loadVariantByReference(string $reference): ?ContentEntityInterface {
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $matches = $storage->loadByProperties(['machine_name' => $reference])
      ?: $storage->loadByProperties(['uuid' => $reference]);
    $variant = reset($matches) ?: NULL;
    return $variant instanceof ContentEntityInterface ? $variant : NULL;
  }

  /**
   * The variant references a stencil element's styles carry.
   *
   * The one element-tree walk lives on the exporter (the stencil shipper's
   * roster is built from it there); this delegation keeps the manager's gesture
   * API stable for the save/update dialogs' adoption LISTING (what the
   * checkboxes offer). Listing curates; it never validates — adoption's
   * one invariant is one-owner-max, not "the subtree wears it".
   *
   * @param array $element
   *   A stencil element tree (StencilInterface::getElement()).
   *
   * @return string[]
   *   The unique references, in first-seen order.
   */
  public function referencedVariants(array $element): array {
    return $this->exporter->referencedVariants($element);
  }

  /**
   * Loudly resolves an update or removal target to a content stencil.
   *
   * Content stencils resolve by machine name or uuid regardless of
   * publication state: an unpublished stencil is its author's draft and
   * push is how the draft evolves. A reference naming a FILE stencil is
   * refused with its own error, never a bare not-found: a file stencil has
   * no entity to update, and the client should say so. The hint follows
   * the distro lock: while it holds the site forks (save a NEW stencil);
   * under the developer gate it names the file's update lane, drush
   * `daedalus-blueprint:stencil-ship` from a saved placement. Errors point
   * at "id", the gesture family's parameter for the material.
   *
   * @param string $reference
   *   A stencil machine name or entity uuid.
   *
   * @return \Drupal\daedalus_blueprint_stencil\StencilInterface
   *   The content stencil.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException
   */
  public function resolveUpdatable(string $reference): StencilInterface {
    $storage = $this->entityTypeManager->getStorage('stencil');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $reference)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      $by_uuid = $storage->loadByProperties(['uuid' => $reference]);
      $stencil = reset($by_uuid) ?: NULL;
    }
    else {
      $stencil = $storage->load(reset($ids));
    }
    if ($stencil instanceof StencilInterface) {
      return $stencil;
    }

    $definition = $this->discovery->getStencil($reference);
    if ($definition !== NULL && $definition['source'] === StencilDiscoveryInterface::SOURCE_MODULE) {
      $hint = $this->distroLock->holds()
        ? 'Save the composed element as a NEW stencil instead; shipped stencils never change on a consuming site.'
        : sprintf('Save the page, then rewrite the file from the saved placement: `drush daedalus-blueprint:stencil-ship ENTITY_TYPE ID ROOT_UUID %s`.', $definition['provider']);
      throw new StencilGestureException([
        new BlueprintError('id', 'STENCIL_READ_ONLY', sprintf('The "%s" stencil is shipped by the "%s" module; file stencils are read-only.', $reference, $definition['provider']), ['provider' => $definition['provider']], $hint),
      ]);
    }
    throw new StencilGestureException([
      new BlueprintError('id', 'STENCIL_NOT_FOUND', sprintf('"%s" is not a saved stencil.', $reference), [], 'Reference a content stencil by its machine name or uuid.'),
    ]);
  }

  /**
   * Reads a placement's provenance stamp: the stencil ID that stamped it.
   *
   * The instantiate_stencil operation writes the stamp onto the placed
   * root — a block root's additional data or a section root's
   * third-party settings (StencilOperationHandler::PROVENANCE_KEY).
   * Informational only: the verb UI reads it to offer "Update stencil 'X'"
   * over "Save as stencil", and whether the ID still resolves to an
   * updatable content stencil is resolveUpdatable()'s question. A missing
   * stamp, a deleted stencil, or a file-shipped one all just mean "not
   * updatable"; nothing here errors.
   *
   * @param \Drupal\layout_builder\SectionComponent|\Drupal\layout_builder\Section $element
   *   A placed component or section (typically the selected element's root).
   *
   * @return string|null
   *   The source stencil ID, or NULL when the placement carries no stamp.
   */
  public function placementProvenance(SectionComponent|Section $element): ?string {
    $id = $element instanceof Section
      ? $element->getThirdPartySetting(StencilOperationHandler::PROVENANCE_KEY, 'stencil')
      : $element->get(LayoutSerializer::STENCIL_PROVENANCE_KEY);
    return is_string($id) && $id !== '' ? $id : NULL;
  }

  /**
   * A stencil as the client speaks it (the gesture-response shape).
   *
   * The icon rides in the icon value grammar's media spelling
   * (`media:<uuid>`, NULL for none), exactly as the discovery catalog
   * serves it, so gesture responses and catalog entries stay one
   * vocabulary for the client's optimistic palette patches.
   *
   * @return array
   *   {machine_name, uuid, label, description, icon, design_system,
   *   offered, published}.
   */
  public function stencilPayload(StencilInterface $stencil): array {
    $icon = $stencil->getIcon();
    return [
      'machine_name' => $stencil->getMachineName(),
      'uuid' => $stencil->uuid(),
      'label' => (string) $stencil->label(),
      'description' => $stencil->getDescription(),
      'icon' => $icon !== NULL ? 'media:' . $icon->uuid() : NULL,
      'design_system' => $stencil->getDesignSystem(),
      'offered' => $stencil->isOffered(),
      'published' => $stencil->isPublished(),
    ];
  }

  /**
   * Resolves an icon parameter to its media entity, loudly.
   *
   * The gesture lane of the icon value grammar: content stencils store the
   * icon as a MEDIA REFERENCE, so the gestures accept only the grammar's
   * media spelling (`media:<uuid>`); the path spelling is the stencil
   * FILES' lane, resolved at discovery, and a path here would have nowhere
   * to live. Unknown media refuses loudly at write time (the write is the
   * moment the reference can be wrong on purpose); a stored icon whose
   * media is deleted LATER degrades quietly at render instead, which is
   * IconResolver's documented job.
   *
   * @param string|null $icon
   *   The gesture's icon parameter; NULL or '' means "no icon".
   *
   * @return \Drupal\media\MediaInterface|null
   *   The referenced media, or NULL for no icon.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException
   */
  protected function resolveIconMedia(?string $icon): ?MediaInterface {
    $icon = trim((string) $icon);
    if ($icon === '') {
      return NULL;
    }
    if (!str_starts_with($icon, 'media:')) {
      throw new StencilGestureException([
        new BlueprintError('icon', 'INVALID_PARAMETER', sprintf('"%s" is not a media icon reference.', $icon), [], 'Reference an existing media item as "media:<uuid>"; path icons belong to module-shipped stencil files.'),
      ]);
    }
    $matches = $this->entityTypeManager->getStorage('media')->loadByProperties([
      'uuid' => strtolower(substr($icon, strlen('media:'))),
    ]);
    $media = reset($matches) ?: NULL;
    if (!$media instanceof MediaInterface) {
      throw new StencilGestureException([
        new BlueprintError('icon', 'STENCIL_ICON_UNKNOWN_MEDIA', sprintf('"%s" does not reference a saved media item.', $icon), [], 'Reference existing media by uuid in the "media:<uuid>" spelling; the icon gesture never creates media.'),
      ]);
    }
    return $media;
  }

  /**
   * Captures the staged subtree, translating export failures to the shape.
   *
   * The capture IS the ship lane's capture: same exporter, same reference
   * preservation, same inexpressible-key warnings. An export failure (a
   * plugin block in the subtree, an unknown element) is the gesture's
   * validation failure, re-spoken as a blueprint error.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilGestureException
   */
  protected function capture(FieldableEntityInterface $entity, string $element_uuid): StencilExport {
    try {
      return $this->exporter->export($entity, $element_uuid);
    }
    catch (StencilExportException $exception) {
      throw new StencilGestureException([
        new BlueprintError('element', 'STENCIL_CAPTURE_FAILED', $exception->getMessage(), [], 'Capture a placed section or inline-block component by its uuid.'),
      ]);
    }
  }

}
