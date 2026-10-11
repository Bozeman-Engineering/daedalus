<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemMemberInterface;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\media\MediaInterface;

/**
 * The blueprint gestures and the variant-ownership lifecycle.
 *
 * Save-as-blueprint, update (push and the palette face), adopt, release
 * and transfer: the one implementation behind the blueprint kind of the
 * material gesture family (save_material / update_material /
 * remove_material, dispatched to BlueprintMaterialsProvider) and the
 * ownership routes (BlueprintApiController): a blueprint is a site-wide
 * content entity, so its reversibility nets are content nets (revisions on
 * plain sites, workspace staging on workspace sites).
 *
 * Capture and push are the ONE machinery every road shares: the source
 * host's lean tree (NodeTreeBuilder::build(), staged state included, so a
 * subtree composed earlier in the same batch captures exactly as staged)
 * is read at the element (NodeTreeBuilder::find(), or the whole document
 * for the host's own uuid), and the spine's whole-document copy
 * (DocumentInstantiator::instantiateDocument()) writes it into the
 * blueprint's own host with fresh identity, the page's own root entries
 * re-addressed to the blueprint's, exactly as a placement writes a
 * blueprint into a page. A blueprint is a page of its
 * own, so what it stores is a document (core's override field plus the
 * style map), never a tree blob: media and entity references are
 * preserved because the copy is a copy, derived wears (a composition's
 * distribution) drop because the instantiator re-creates them from the
 * root's own apply, and the saved document is read back by discovery
 * through the same tree builder. The capture gestures mutate no staged
 * page; they only READ it, and the write lands on the blueprint through
 * EntityStorageHandler::commit().
 *
 * A blueprint's roots are containers: capturing a block refuses
 * (BLUEPRINT_ROOT_NOT_CONTAINER) and points at the block's container. The
 * host's own uuid captures the whole document as a page-scope blueprint;
 * any container captures as a block-scope one. The subject (the kind of
 * host the blueprint is for) is stamped from the source host's display
 * target at capture.
 *
 * Update targets CONTENT blueprints only. A file-shipped blueprint has no
 * entity to update: it is a file in its module, read-only in every UI and
 * op (no shadowing), and resolveUpdatable() refuses it loudly so the client
 * can say why instead of guessing at a 404. The distro lock (DistroLock)
 * decides what the refusal points at. While the lock holds (a consuming
 * site) the site forks: save the composed element as a NEW blueprint.
 * Under the developer gate (`$settings['daedalus_blueprint_developer'] =
 * TRUE`, the package author's site) the update lane is the ship command,
 * which rewrites the file in its module; the refusal names that lane. The
 * record flag `updatable` stays FALSE for file blueprints either way (the
 * op never writes them), so the UI verbs degrade the same under both.
 *
 * Membership: a saved blueprint joins the ACTIVE design system unless the
 * gesture opts out (global), the rule every mint shares; the variants a
 * blueprint adopts or takes by transfer carry the blueprint's membership
 * from then on (an owned look is offered where its owner is, so it belongs
 * where its owner belongs).
 */
class BlueprintManager {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BlueprintDiscoveryInterface $discovery,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected ActiveDesignSystem $activeDesignSystem,
    protected DistroLock $distroLock,
    protected HostResolver $hostResolver,
    protected NodeTreeBuilder $nodeTreeBuilder,
    protected DocumentInstantiator $instantiator,
    protected EntityStorageHandler $storageHandler,
    protected BlueprintSubjects $subjects,
  ) {}

  /**
   * Saves a staged subtree, or a whole document, as a new content blueprint.
   *
   * The machine name auto-generates from the label at presave, unique in
   * the combined (file + content) vocabulary, so a save never collides.
   * The new blueprint is published immediately: the gesture's outcome is
   * a palette entry, and unpublishing is an admin-listing decision.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host carrying the element. Through the ops spine this is the
   *   batch's live host, so a subtree composed by earlier operations in
   *   the SAME batch captures exactly as staged.
   * @param string $element_uuid
   *   UUID of the root to capture: a container (top-level band or nested)
   *   for a block-scope blueprint, or the host's own uuid for the whole
   *   document as a page-scope blueprint. A block is refused: capture its
   *   container.
   * @param string $label
   *   The human label shown in the palette.
   * @param string $description
   *   What the blueprint is for. Required: the description is half the
   *   palette entry's face, whichever source a blueprint comes from.
   * @param string|null $icon
   *   The palette icon as a media reference in the icon value grammar's
   *   media spelling (`media:<uuid>`), or NULL/'' for no icon (the palette
   *   renders its default blueprint icon). Optional and media-only:
   *   content blueprints store the icon as a media reference, so the path
   *   spelling belongs to module-shipped blueprint files alone, and
   *   referencing EXISTING media is the whole vocabulary (the AI shares
   *   this gesture, and it never uploads).
   * @param string $origin
   *   Who minted it, MaterialRecord::ORIGIN_USER or ORIGIN_AI: the lane's
   *   stamp (the AI tools pass ai), carried on the roster record for the
   *   life of the site.
   * @param bool $global
   *   TRUE saves a global blueprint, a member of no design system, offered
   *   under every one. FALSE (the default every door shares) makes it a
   *   member of the active design system: a composition is saved against
   *   the vocabulary the editor is looking at. Global either way when no
   *   design system is active.
   * @param bool $offered
   *   The AI opt-out: TRUE (the default) shares the blueprint with the AI,
   *   FALSE keeps it for the palette only (BlueprintInterface::isOffered()).
   * @param bool $strip_values
   *   TRUE skeletonizes the capture: every inline block's pinned fields and
   *   display label are dropped so they sample-populate at placement;
   *   structure, layout settings, plugin configuration and styles stay.
   * @param string $type
   *   The blueprint type the mint is born as (its bundle, the axis its
   *   permissions run on): `section` unless the gesture named another. The
   *   caller has checked the type exists and the actor may create it
   *   (BlueprintMaterialsProvider::typeOf(), requirePermission()).
   *
   * @return \Drupal\daedalus_blueprint_library\BlueprintGestureResult
   *   The saved blueprint and any capture warnings.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  public function saveAsBlueprint(HostInterface $host, string $element_uuid, string $label, string $description, ?string $icon = NULL, string $origin = MaterialRecord::ORIGIN_USER, bool $global = FALSE, bool $offered = TRUE, bool $strip_values = FALSE, string $type = BlueprintInterface::TYPE_SECTION): BlueprintGestureResult {
    $errors = [];
    if (trim($label) === '') {
      $errors[] = new BlueprintError('label', 'MISSING_PARAMETER', 'A blueprint needs a label.', [], 'Name the blueprint the way editors should find it in the palette.');
    }
    if (trim($description) === '') {
      $errors[] = new BlueprintError('description', 'MISSING_PARAMETER', 'A blueprint needs a description.', [], 'Describe what the blueprint is for; the palette and the AI both read it.');
    }
    $icon_media = NULL;
    try {
      $icon_media = $this->resolveIconMedia($icon);
    }
    catch (BlueprintGestureException $exception) {
      $errors = array_merge($errors, $exception->getErrors());
    }
    if ($errors !== []) {
      throw new BlueprintGestureException($errors);
    }

    // The subject is the kind of host the material came from: the display
    // the source renders with names the entity type and bundle (a
    // blueprint's display is its subject's, so capturing from a blueprint
    // canvas keeps the subject). A host with no display (the site frame)
    // renders no kind, and a blueprint is material FOR a kind: nothing is
    // captured from it.
    $display = $host->display();
    if ($display === NULL) {
      throw new BlueprintGestureException([
        new BlueprintError('element', 'BLUEPRINT_NO_SUBJECT', sprintf('A blueprint is material for a kind of page, and the %s "%s" renders no kind: nothing can be captured from it.', $host->entityTypeId(), $host->label()), [], 'Capture from a page, a template or a blueprint canvas.'),
      ]);
    }

    $capture = $this->capture($host, $element_uuid, $strip_values);

    $blueprint = $this->entityTypeManager->getStorage(BlueprintInterface::ENTITY_TYPE_ID)->create([
      'type' => $type,
      'label' => trim($label),
      'description' => trim($description),
      'uid' => $this->currentUser->id(),
      'ai_offered' => $offered,
      'scope' => $capture['scope'],
      'status' => TRUE,
    ]);
    assert($blueprint instanceof BlueprintInterface);
    $blueprint->setSubject($display->getTargetEntityTypeId(), $display->getTargetBundle());
    $blueprint->setIcon($icon_media);
    $blueprint->setOrigin($origin);
    $blueprint->setDesignSystem($global ? NULL : $this->activeDesignSystem->activeId());

    $target = $this->hostResolver->fromEntity($blueprint);
    $warnings = $this->place($target, $capture, FALSE);
    $this->storageHandler->commit($target);

    return new BlueprintGestureResult($blueprint, $warnings);
  }

  /**
   * Updates a content blueprint in place: a fresh capture, the palette face.
   *
   * The one write behind update_material (kind blueprint) and the details
   * route: whatever the changes carry lands in ONE new revision, so a bad
   * update is one revert away, and workspace-staged inside a workspace.
   * Keys:
   * - element: the uuid of a staged element whose subtree is captured
   *   (push), or the host's own uuid for the whole document. The
   *   blueprint's document (sections and style map) is emptied and the
   *   capture instantiated in; a capture whose scope differs from the
   *   blueprint's refuses (a container into a block blueprint, a whole
   *   page into a page blueprint). Placements never re-sync; only FUTURE
   *   placements stamp the pushed document. Needs the host.
   * - strip_values: with element, skeletonize the capture (see
   *   saveAsBlueprint()).
   * - label, description: the palette face; both required non-empty when
   *   present (the description is half the palette entry's face, the admin
   *   edit form's grammar).
   * - icon: PRESENT writes (the `media:<uuid>` spelling, or NULL / '' to
   *   clear back to the palette default); ABSENT leaves the stored icon
   *   standing.
   * - offered: the AI opt-out, a boolean (TRUE shares the blueprint with
   *   the AI, FALSE keeps it for the palette only); ABSENT leaves the
   *   stored flag alone.
   * - subject: the kind of host the blueprint is material for, spelled
   *   `entity_type:bundle` (BlueprintSubjects::parse(): a fieldable bundle
   *   on this site); the canvas renders against a sample of it from the
   *   next read on. The scope is identity and never changes here.
   * - bundles: the allow-list over duck-typed fit, a list of
   *   `entity_type:bundle` strings; [] (or NULL) clears it, meaning any
   *   host the blueprint fits.
   * - groups: the group ids the blueprint is filed under, a list of
   *   machine names (BlueprintInterface::GROUP_ID_PATTERN, the keys of
   *   the site's group vocabulary, the dialect catalog's groups; filter
   *   categories on an offering surface and nothing else); [] (or NULL)
   *   clears it.
   *   Shape-checked only, like the allow-list: a surface ignores an id the
   *   site does not declare.
   * - favorite: the site favorite, a boolean (TRUE lists the blueprint
   *   under Favorites for everyone where blueprints are offered, the
   *   default each editor keeps their own marks over; FALSE unlists it);
   *   ABSENT leaves the stored flag alone. A listing fact and nothing
   *   else.
   * The type, like the scope and the machine name, is identity and has no
   * key here.
   *
   * @param \Drupal\daedalus_blueprint_library\BlueprintInterface $blueprint
   *   The content blueprint to update (see resolveUpdatable()).
   * @param array $changes
   *   The keys above; anything else is ignored.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The host carrying the element, when `element` is present.
   *
   * @return \Drupal\daedalus_blueprint_library\BlueprintGestureResult
   *   The updated blueprint and any capture warnings.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  public function update(BlueprintInterface $blueprint, array $changes, ?HostInterface $host = NULL): BlueprintGestureResult {
    $errors = [];
    if (array_key_exists('label', $changes) && trim((string) $changes['label']) === '') {
      $errors[] = new BlueprintError('label', 'MISSING_PARAMETER', 'A blueprint needs a label.', [], 'Name the blueprint the way editors should find it in the palette.');
    }
    if (array_key_exists('description', $changes) && trim((string) $changes['description']) === '') {
      $errors[] = new BlueprintError('description', 'MISSING_PARAMETER', 'A blueprint needs a description.', [], 'Describe what the blueprint is for; the palette and the AI both read it.');
    }
    $icon_media = NULL;
    if (array_key_exists('icon', $changes)) {
      try {
        $icon_media = $this->resolveIconMedia($changes['icon']);
      }
      catch (BlueprintGestureException $exception) {
        $errors = array_merge($errors, $exception->getErrors());
      }
    }
    $subject = NULL;
    if (array_key_exists('subject', $changes)) {
      try {
        $subject = $this->subjects->parse($changes['subject']);
      }
      catch (BlueprintGestureException $exception) {
        $errors = array_merge($errors, $exception->getErrors());
      }
    }
    $bundles = NULL;
    if (array_key_exists('bundles', $changes)) {
      try {
        $bundles = $this->subjects->parseBundles($changes['bundles']);
      }
      catch (BlueprintGestureException $exception) {
        $errors = array_merge($errors, $exception->getErrors());
      }
    }
    $groups = NULL;
    if (array_key_exists('groups', $changes)) {
      try {
        $groups = $this->parseGroups($changes['groups']);
      }
      catch (BlueprintGestureException $exception) {
        $errors = array_merge($errors, $exception->getErrors());
      }
    }
    if (array_key_exists('favorite', $changes) && !is_bool($changes['favorite'])) {
      $errors[] = new BlueprintError('favorite', 'INVALID_PARAMETER', 'favorite must be true or false.', [], 'Pass "favorite": true to list the blueprint under Favorites for everyone where blueprints are offered, false to unlist it, or omit the key to leave it.');
    }
    if ($errors !== []) {
      throw new BlueprintGestureException($errors);
    }

    $log = [];
    $warnings = [];
    $target = NULL;
    if (isset($changes['element'])) {
      if ($host === NULL) {
        throw new BlueprintGestureException([
          new BlueprintError('element', 'MISSING_PARAMETER', 'A push captures an element of a page; this lane has no page behind it.', [], 'Run update_material as a page_edit op on the page the element sits on.'),
        ]);
      }
      $capture = $this->capture($host, (string) $changes['element'], !empty($changes['strip_values']));
      if ($capture['scope'] !== $blueprint->getScope()) {
        throw new BlueprintGestureException([
          new BlueprintError('element', 'BLUEPRINT_SCOPE_MISMATCH', sprintf('The "%s" blueprint is a %s blueprint; the capture is a %s.', $blueprint->getMachineName(), $blueprint->getScope(), $capture['scope'] === BlueprintInterface::SCOPE_PAGE ? 'whole page' : 'container'), ['scope' => $blueprint->getScope()], $blueprint->getScope() === BlueprintInterface::SCOPE_PAGE ? 'Push the whole page (its own uuid as the element) into a page blueprint.' : 'Push one container (a section uuid) into a block blueprint.'),
        ]);
      }
      $target = $this->hostResolver->fromEntity($blueprint);
      $warnings = $this->place($target, $capture, TRUE);
      $log[] = sprintf('Document pushed from %s %s.', $host->entityTypeId(), $host->id() ?? $host->uuid());
    }
    if (array_key_exists('label', $changes)) {
      $blueprint->set('label', trim((string) $changes['label']));
    }
    if (array_key_exists('description', $changes)) {
      $blueprint->setDescription(trim((string) $changes['description']));
    }
    if (array_key_exists('icon', $changes)) {
      $blueprint->setIcon($icon_media);
    }
    if ($subject !== NULL && [$blueprint->getSubjectType(), $blueprint->getSubjectBundle()] !== $subject) {
      $blueprint->setSubject($subject[0], $subject[1]);
      $log[] = sprintf('Subject changed to %s.', BlueprintSubjects::spell($subject[0], $subject[1]));
    }
    if ($bundles !== NULL && $blueprint->getBundles() !== $bundles) {
      $blueprint->setBundles($bundles);
      $log[] = $bundles === [] ? 'Allowed hosts cleared.' : sprintf('Allowed hosts: %s.', implode(', ', $bundles));
    }
    if ($groups !== NULL && $blueprint->getGroups() !== $groups) {
      $blueprint->setGroups($groups);
      $log[] = $groups === [] ? 'Groups cleared.' : sprintf('Groups: %s.', implode(', ', $groups));
    }
    if (array_intersect(['label', 'description', 'icon'], array_keys($changes)) !== []) {
      $log[] = 'Blueprint details updated.';
    }
    if (array_key_exists('offered', $changes) && (bool) $changes['offered'] !== $blueprint->isOffered()) {
      $blueprint->setOffered((bool) $changes['offered']);
      $log[] = $blueprint->isOffered() ? 'Offered to the AI.' : 'Withheld from the AI.';
    }
    if (array_key_exists('favorite', $changes) && $changes['favorite'] !== $blueprint->isFavorite()) {
      $blueprint->setFavorite($changes['favorite']);
      $log[] = $blueprint->isFavorite() ? 'Marked a favorite.' : 'No longer a favorite.';
    }
    $blueprint->setNewRevision(TRUE);
    $blueprint->setRevisionUserId((int) $this->currentUser->id());
    $blueprint->setRevisionCreationTime($this->time->getRequestTime());
    $blueprint->setRevisionLogMessage(implode(' ', $log));
    // A document write commits through the host's one door (which also
    // ends any stage of the blueprint: the push is the newer document); a
    // face-only change is an ordinary save of the entity.
    if ($target !== NULL) {
      $this->storageHandler->commit($target);
    }
    else {
      $blueprint->save();
    }

    return new BlueprintGestureResult($blueprint, $warnings);
  }

  /**
   * Parses a gesture's group list, loudly, shape only.
   *
   * @param mixed $groups
   *   The gesture value: a list of group ids
   *   (BlueprintInterface::GROUP_ID_PATTERN); [] (or NULL) clears the
   *   list, which files the blueprint under no group.
   *
   * @return string[]
   *   The ids, de-duplicated, in order.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  protected function parseGroups(mixed $groups, string $path = 'groups'): array {
    if ($groups === NULL) {
      return [];
    }
    if (!is_array($groups) || !array_is_list($groups) || array_filter($groups, static fn ($group): bool => !is_string($group) || !preg_match(BlueprintInterface::GROUP_ID_PATTERN, $group)) !== []) {
      throw new BlueprintGestureException([
        new BlueprintError($path, 'INVALID_PARAMETER', sprintf('%s must be a list of group ids (machine names).', $path), [], 'List the ids of the site\'s groups (the schema\'s materials.groups) the blueprint is filed under, e.g. ["heroes"], or pass an empty list for no group; groups are filters on the surfaces that offer blueprints and change nothing else.'),
      ]);
    }
    return array_values(array_unique($groups));
  }

  /**
   * The variant references a capture of an element would list for adoption.
   *
   * The save and update dialogs' adoption LISTING (what the checkboxes
   * offer): the authored wearings on every node of the subtree a capture
   * would read, in first-seen order. Listing curates; it never validates
   * (adoption's one invariant is one-owner-max, not "the subtree wears
   * it"). A capture that would refuse (an unknown element, a block root)
   * refuses here the same way.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host carrying the element.
   * @param string $element_uuid
   *   The element a capture would read (see saveAsBlueprint()).
   *
   * @return string[]
   *   The unique variant references, in first-seen order.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  public function capturedVariants(HostInterface $host, string $element_uuid): array {
    return BlueprintLayout::referencedVariants($this->capture($host, $element_uuid, FALSE)['nodes']);
  }

  /**
   * Adopts style variants into a blueprint's ownership.
   *
   * The ownership half of the blueprint save and update gestures: the
   * dialogs list candidate variants (the capture's references, checked by
   * default) and the op passes the picked ones here; they become the
   * blueprint's own, offered only on its placements from now on.
   * Ownership is CURATION, not provenance: a blueprint's owned variants
   * are the set of switchable looks it offers, independent of what its
   * document currently wears, so adopting a look the subtree does not
   * (yet) reference is legitimate curation, not an error.
   *
   * Loud by design, in order: adoption requires daedalus_stylish (the
   * field this module provides only exists on daedalus_variant); every
   * reference must resolve to a variant; shipped (disk-lane) variants
   * refuse (release and transfer refuse them too, so a shipped adoption
   * would be irreversible by gesture, and the module's variants file stays
   * authoritative); and a variant another blueprint already owns ON EITHER
   * LANE (content reference or blueprint_file machine name) is refused:
   * one owner max is the ONE adoption invariant (soft offering never means
   * silent re-owning; release or transfer it instead).
   *
   * An adopted variant takes the blueprint's membership: an owned look is
   * offered only on its owner's placements, so it belongs to the design
   * system its owner belongs to (global under a global blueprint).
   *
   * @param \Drupal\daedalus_blueprint_library\BlueprintInterface $blueprint
   *   The freshly saved (or existing) content blueprint.
   * @param string[] $references
   *   Variant references (machine names or uuids) to adopt.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  public function adoptVariants(BlueprintInterface $blueprint, array $references): void {
    if (!$this->entityTypeManager->hasDefinition('daedalus_variant')) {
      throw new BlueprintGestureException([
        new BlueprintError('adopt_variants', 'BLUEPRINT_ADOPTION_UNAVAILABLE', 'Adopting variants requires the daedalus_stylish module.', [], 'Enable daedalus_stylish, or save the blueprint without adopt_variants.'),
      ]);
    }
    foreach ($references as $reference) {
      $variant = $this->loadVariantByReference($reference);
      if ($variant === NULL) {
        throw new BlueprintGestureException([
          new BlueprintError('adopt_variants', 'BLUEPRINT_ADOPT_UNKNOWN_VARIANT', sprintf('"%s" is not a saved style variant.', $reference), [], 'Reference variants by machine name or uuid.'),
        ]);
      }
      $this->refuseShipped($variant, 'adopt_variants');
      $machine_name = (string) $variant->get('machine_name')->value;
      $owner_id = $variant->get(BlueprintInterface::OWNER_FIELD)->target_id;
      $file_owner = (string) ($variant->get(BlueprintInterface::FILE_OWNER_FIELD)->value ?? '');
      if ($file_owner !== '' || ($owner_id !== NULL && (int) $owner_id !== (int) $blueprint->id())) {
        throw new BlueprintGestureException([
          new BlueprintError('adopt_variants', 'BLUEPRINT_ADOPT_ALREADY_OWNED', sprintf('The "%s" variant already belongs to another blueprint.', $machine_name), ['owner' => $file_owner !== '' ? $file_owner : $owner_id], 'A variant has one owner; release it there first (or transfer it), or leave it out of adopt_variants.'),
        ]);
      }
      $variant->set(BlueprintInterface::OWNER_FIELD, $blueprint->id());
      $this->stampMembership($variant, $blueprint);
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
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  public function releaseVariant(string $reference): ContentEntityInterface {
    $variant = $this->resolveOwnableVariant($reference);
    if ($variant->get(BlueprintInterface::OWNER_FIELD)->isEmpty() && (string) ($variant->get(BlueprintInterface::FILE_OWNER_FIELD)->value ?? '') === '') {
      throw new BlueprintGestureException([
        new BlueprintError('variant', 'BLUEPRINT_RELEASE_NOT_OWNED', sprintf('The "%s" variant is not owned by any blueprint.', $variant->get('machine_name')->value), [], 'Only owned variants release; this one already offers by its scope everywhere.'),
      ]);
    }
    $variant->set(BlueprintInterface::OWNER_FIELD, NULL);
    $variant->set(BlueprintInterface::FILE_OWNER_FIELD, NULL);
    $variant->save();
    return $variant;
  }

  /**
   * Transfers a variant's ownership to a content blueprint, atomically.
   *
   * Release + adopt composed server-side as ONE gesture (a single save):
   * the route exists precisely for the owned-by-another case, where
   * composing the two manually would expose the intermediate unowned
   * state. The variant's previous state never gates it: owned by another
   * blueprint, orphaned, or unowned (a transfer onto an unowned variant is
   * adopt-later by route), the outcome is the same single owner.
   *
   * The target is a CONTENT blueprint (resolveUpdatable(): file-shipped
   * targets refuse loudly; only the disk lane writes file-blueprint
   * ownership). Shipped variants refuse for the same reason release does:
   * the disk lane re-asserts its owner key at the next import.
   *
   * @param string $reference
   *   A variant machine name or uuid.
   * @param \Drupal\daedalus_blueprint_library\BlueprintInterface $blueprint
   *   The content blueprint taking ownership.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The transferred variant.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  public function transferVariant(string $reference, BlueprintInterface $blueprint): ContentEntityInterface {
    $variant = $this->resolveOwnableVariant($reference);
    $variant->set(BlueprintInterface::OWNER_FIELD, $blueprint->id());
    $variant->set(BlueprintInterface::FILE_OWNER_FIELD, NULL);
    $this->stampMembership($variant, $blueprint);
    $variant->save();
    return $variant;
  }

  /**
   * Gives a variant its new owner's membership (adoption and transfer).
   *
   * The variant is typed loosely because this module never requires
   * daedalus_stylish; every daedalus_variant is a member entity, so the
   * guard only documents the seam.
   */
  protected function stampMembership(ContentEntityInterface $variant, BlueprintInterface $blueprint): void {
    if ($variant instanceof DesignSystemMemberInterface) {
      $variant->setDesignSystem($blueprint->getDesignSystem());
    }
  }

  /**
   * The variants a blueprint currently owns, on either lane.
   *
   * The ownership question asked from the blueprint's side: every variant
   * whose content-lane reference or file-lane machine name resolves to
   * this blueprint. An ownership QUERY, never a capture walk: ownership is
   * curation, so the owned set is independent of what the blueprint's
   * document currently wears (an adopted-but-unworn look is owned all the
   * same, and the update dialog's roster must say so).
   *
   * @param \Drupal\daedalus_blueprint_library\BlueprintInterface $blueprint
   *   A content blueprint.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   The owned daedalus_variant entities, sorted by label. Empty without
   *   daedalus_stylish (the ownership fields only exist on daedalus_variant).
   */
  public function ownedVariants(BlueprintInterface $blueprint): array {
    if (!$this->entityTypeManager->hasDefinition('daedalus_variant')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $query->condition($query->orConditionGroup()
      ->condition(BlueprintInterface::OWNER_FIELD, $blueprint->id())
      ->condition(BlueprintInterface::FILE_OWNER_FIELD, $blueprint->getMachineName()));
    $query->sort('label');
    return array_values($storage->loadMultiple($query->execute()));
  }

  /**
   * A variant's ownership state as the client speaks it.
   *
   * The ownership routes' response shape: the owner is the blueprint
   * MACHINE NAME (the one vocabulary provenance stamps and payloads
   * speak), or NULL when unowned. Deliberately thinner than
   * daedalus_stylish's variantPayload(): the ownership gestures mutate only
   * ownership, so they echo only ownership.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $variant
   *   A daedalus_variant entity.
   *
   * @return array
   *   {machine_name, uuid, blueprint}.
   */
  public function variantOwnershipPayload(ContentEntityInterface $variant): array {
    $owner = NULL;
    if (!$variant->get(BlueprintInterface::OWNER_FIELD)->isEmpty()) {
      $entity = $variant->get(BlueprintInterface::OWNER_FIELD)->entity;
      $owner = $entity instanceof BlueprintInterface ? $entity->getMachineName() : NULL;
    }
    elseif (($file = (string) ($variant->get(BlueprintInterface::FILE_OWNER_FIELD)->value ?? '')) !== '') {
      $owner = $file;
    }
    return [
      'machine_name' => (string) $variant->get('machine_name')->value,
      'uuid' => $variant->uuid(),
      'blueprint' => $owner,
    ];
  }

  /**
   * Resolves an ownership gesture's variant, loudly, refusing shipped.
   *
   * The shared front door of release and transfer: daedalus_stylish must
   * be installed (the ownership fields only exist on daedalus_variant), the
   * reference must resolve, and shipped (disk-lane) variants refuse: their
   * variants file owns their ownership, and a database-side change would
   * silently un-do itself at the next import.
   *
   * @param string $reference
   *   A variant machine name or uuid.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The variant, site-created and mutable.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  protected function resolveOwnableVariant(string $reference): ContentEntityInterface {
    if (!$this->entityTypeManager->hasDefinition('daedalus_variant')) {
      throw new BlueprintGestureException([
        new BlueprintError('variant', 'BLUEPRINT_ADOPTION_UNAVAILABLE', 'Variant ownership requires the daedalus_stylish module.', [], 'Enable daedalus_stylish to use owned variants.'),
      ]);
    }
    $variant = $this->loadVariantByReference($reference);
    if ($variant === NULL) {
      throw new BlueprintGestureException([
        new BlueprintError('variant', 'BLUEPRINT_VARIANT_NOT_FOUND', sprintf('"%s" is not a saved style variant.', $reference), [], 'Reference variants by machine name or uuid.'),
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
   * content: a database-side release or transfer would silently un-do
   * itself at the next import, and a shipped ADOPTION would be
   * irreversible by gesture (release refuses right back, the importer's
   * absent owner key never clears it).
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $variant
   *   A daedalus_variant entity.
   * @param string $field
   *   The gesture parameter the refusal points at ('variant' for the
   *   release/transfer routes, 'adopt_variants' for the capture ops).
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  protected function refuseShipped(ContentEntityInterface $variant, string $field): void {
    $provider = (string) ($variant->get('provider')->value ?? '');
    if ($provider !== '') {
      throw new BlueprintGestureException([
        new BlueprintError($field, 'BLUEPRINT_VARIANT_SHIPPED', sprintf('The "%s" variant is shipped by the "%s" module; its ownership follows the module\'s variants file.', $variant->get('machine_name')->value, $provider), ['provider' => $provider], 'Change the owner key in the module\'s variants file, or copy it as your own variant and curate the copy.'),
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
   * Loudly resolves an update or removal target to a content blueprint.
   *
   * Content blueprints resolve by machine name or uuid regardless of
   * publication state: an unpublished blueprint is its author's draft and
   * push is how the draft evolves. A reference naming a FILE blueprint is
   * refused with its own error, never a bare not-found: a file blueprint
   * has no entity to update, and the client should say so. The hint
   * follows the distro lock: while it holds the site forks (save a NEW
   * blueprint); under the developer gate it names the file's update lane,
   * the ship command. Errors point at "id", the gesture family's parameter
   * for the material.
   *
   * @param string $reference
   *   A blueprint machine name or entity uuid.
   *
   * @return \Drupal\daedalus_blueprint_library\BlueprintInterface
   *   The content blueprint.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  public function resolveUpdatable(string $reference): BlueprintInterface {
    $storage = $this->entityTypeManager->getStorage(BlueprintInterface::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $reference)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      $by_uuid = $storage->loadByProperties(['uuid' => $reference]);
      $blueprint = reset($by_uuid) ?: NULL;
    }
    else {
      $blueprint = $storage->load(reset($ids));
    }
    if ($blueprint instanceof BlueprintInterface) {
      return $blueprint;
    }

    $definition = $this->discovery->getBlueprint($reference);
    if ($definition !== NULL && $definition['source'] === BlueprintDiscoveryInterface::SOURCE_MODULE) {
      $hint = $this->distroLock->holds()
        ? 'Save the composed element as a NEW blueprint instead; shipped blueprints never change on a consuming site.'
        : sprintf('Edit the file in the "%s" module by hand, or save the composition as a NEW blueprint and ship it there: `drush daedalus-blueprint:blueprint-ship BLUEPRINT %s`.', $definition['provider'], $definition['provider']);
      throw new BlueprintGestureException([
        new BlueprintError('id', 'BLUEPRINT_READ_ONLY', sprintf('The "%s" blueprint is shipped by the "%s" module; file blueprints are read-only.', $reference, $definition['provider']), ['provider' => $definition['provider']], $hint),
      ]);
    }
    throw new BlueprintGestureException([
      new BlueprintError('id', 'BLUEPRINT_NOT_FOUND', sprintf('"%s" is not a saved blueprint.', $reference), [], 'Reference a content blueprint by its machine name or uuid.'),
    ]);
  }

  /**
   * Reads a placement's provenance stamp: the blueprint ID that stamped it.
   *
   * The place_blueprint operation writes the stamp onto the placed root: a
   * section root's third-party settings, or a block root's additional data
   * on a document that predates the container-only rule
   * (BlueprintOperationHandler::PROVENANCE_KEY). Informational only: the
   * verb UI reads it to offer "Update blueprint 'X'" over "Save as
   * blueprint", and whether the ID still resolves to an updatable content
   * blueprint is resolveUpdatable()'s question. A missing stamp, a deleted
   * blueprint, or a file-shipped one all just mean "not updatable";
   * nothing here errors.
   *
   * @param \Drupal\layout_builder\SectionComponent|\Drupal\layout_builder\Section $element
   *   A placed component or section (typically the selected element's root).
   *
   * @return string|null
   *   The source blueprint ID, or NULL when the placement carries no stamp.
   */
  public function placementProvenance(SectionComponent|Section $element): ?string {
    $id = $element instanceof Section
      ? $element->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint')
      : $element->get(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY);
    return is_string($id) && $id !== '' ? $id : NULL;
  }

  /**
   * A blueprint as the client speaks it (the gesture-response shape).
   *
   * The icon rides in the icon value grammar's media spelling
   * (`media:<uuid>`, NULL for none), exactly as the discovery catalog
   * serves it, so gesture responses and catalog entries stay one
   * vocabulary for the client's optimistic palette patches.
   *
   * @return array
   *   {machine_name, uuid, type, label, description, icon, design_system,
   *   offered, published, scope, subject}.
   */
  public function blueprintPayload(BlueprintInterface $blueprint): array {
    $icon = $blueprint->getIcon();
    return [
      'machine_name' => $blueprint->getMachineName(),
      'uuid' => $blueprint->uuid(),
      'type' => $blueprint->getType(),
      'label' => (string) $blueprint->label(),
      'description' => $blueprint->getDescription(),
      'icon' => $icon !== NULL ? 'media:' . $icon->uuid() : NULL,
      'design_system' => $blueprint->getDesignSystem(),
      'offered' => $blueprint->isOffered(),
      'published' => $blueprint->isPublished(),
      'scope' => $blueprint->getScope(),
      'subject' => $blueprint->getSubjectType() . ':' . $blueprint->getSubjectBundle(),
    ];
  }

  /**
   * Resolves an icon parameter to its media entity, loudly.
   *
   * The gesture lane of the icon value grammar: content blueprints store
   * the icon as a MEDIA REFERENCE, so the gestures accept only the
   * grammar's media spelling (`media:<uuid>`); the path spelling is the
   * blueprint FILES' lane, resolved at discovery, and a path here would
   * have nowhere to live. Unknown media refuses loudly at write time (the
   * write is the moment the reference can be wrong on purpose); a stored
   * icon whose media is deleted LATER degrades quietly at render instead,
   * which is IconResolver's documented job.
   *
   * @param string|null $icon
   *   The gesture's icon parameter; NULL or '' means "no icon".
   *
   * @return \Drupal\media\MediaInterface|null
   *   The referenced media, or NULL for no icon.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  protected function resolveIconMedia(?string $icon): ?MediaInterface {
    $icon = trim((string) $icon);
    if ($icon === '') {
      return NULL;
    }
    if (!str_starts_with($icon, 'media:')) {
      throw new BlueprintGestureException([
        new BlueprintError('icon', 'INVALID_PARAMETER', sprintf('"%s" is not a media icon reference.', $icon), [], 'Reference an existing media item as "media:<uuid>"; path icons belong to module-shipped blueprint files.'),
      ]);
    }
    $matches = $this->entityTypeManager->getStorage('media')->loadByProperties([
      'uuid' => strtolower(substr($icon, strlen('media:'))),
    ]);
    $media = reset($matches) ?: NULL;
    if (!$media instanceof MediaInterface) {
      throw new BlueprintGestureException([
        new BlueprintError('icon', 'BLUEPRINT_ICON_UNKNOWN_MEDIA', sprintf('"%s" does not reference a saved media item.', $icon), [], 'Reference existing media by uuid in the "media:<uuid>" spelling; the icon gesture never creates media.'),
      ]);
    }
    return $media;
  }

  /**
   * Reads the subtree a capture copies: the nodes, the scope, the root keys.
   *
   * The source host's lean tree, read at the element: the whole tree for
   * the host's own uuid (a page-scope capture, with the node-less style
   * entries of the host's root, which the whole-document copy re-addresses
   * to the blueprint's), one container node for a section uuid (block
   * scope). A block is refused: roots are containers. The nodes come back
   * as a definition, never as a placement: no identity (the instantiator
   * mints fresh) and no provenance stamp (a blueprint is not a placement,
   * so the stamp of the placement it was captured from never travels),
   * skeletonized when asked.
   *
   * @return array{nodes: array, scope: string, styles: array}
   *   The root nodes, the blueprint scope they make, and the node-less
   *   style entries as the source stores them.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  protected function capture(HostInterface $host, string $element_uuid, bool $strip_values): array {
    if ($element_uuid === $host->uuid()) {
      // The host's OWN document: on a page of a locked bundle its slot
      // fills alone (each root tagged with its slot), never the template
      // it is rendered inside, so the capture is a page blueprint the
      // locked bundle takes as a start (PageStart's fillsSlots()).
      $nodes = $this->nodeTreeBuilder->buildOwn($host);
      $scope = BlueprintInterface::SCOPE_PAGE;
      $styles = $host->hasStyleStore() ? NodeTreeBuilder::nodelessStyles($nodes, $host->styleMap()) : [];
    }
    else {
      $tree = $this->nodeTreeBuilder->build($host);
      $node = NodeTreeBuilder::find($tree, $element_uuid);
      if ($node === NULL) {
        throw new BlueprintGestureException([
          new BlueprintError('element', 'BLUEPRINT_CAPTURE_FAILED', sprintf('No Layout Builder element "%s" exists on %s %s.', $element_uuid, $host->entityTypeId(), $host->id() ?? $host->uuid()), [], 'Capture a placed container by its uuid, or the page itself (its own uuid) as a page blueprint.'),
        ]);
      }
      if (($node['kind'] ?? NULL) !== 'container') {
        $container = BlueprintLayout::parentOf($tree, $element_uuid);
        throw new BlueprintGestureException([
          new BlueprintError('element', 'BLUEPRINT_ROOT_NOT_CONTAINER', sprintf('"%s" is a block; a blueprint\'s root is a container.', $element_uuid), $container === NULL ? [] : ['container' => $container], $container === NULL ? 'Capture its container instead.' : sprintf('Capture its container instead: "%s".', $container)),
        ]);
      }
      $nodes = [$node];
      $scope = BlueprintInterface::SCOPE_BLOCK;
      $styles = [];
    }
    $nodes = BlueprintLayout::withoutProvenance(BlueprintLayout::withoutUuids($nodes));
    if ($strip_values) {
      $nodes = BlueprintLayout::skeleton($nodes);
    }
    return ['nodes' => $nodes, 'scope' => $scope, 'styles' => $styles];
  }

  /**
   * Writes a capture into a blueprint's host: the one placement machinery.
   *
   * The nodes instantiate at the blueprint's root with fresh identity, as
   * a system batch (the capture acts for the site: the gesture's own gate
   * is the blueprint's create or update access, asked by the provider, and
   * what the source page held is admitted whatever the subject bundle's
   * placement restrictions say). A replace empties the document first
   * (sections and style map), so a push is the new document and nothing
   * of the old one survives beside it. A page-scope capture's node-less
   * style entries (the source root's own keys) land re-addressed to the
   * blueprint's root in the same batch, the spine's whole-document copy.
   * Nothing is persisted here: the caller commits.
   *
   * @return string[]
   *   The capture's warnings.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException
   */
  protected function place(HostInterface $target, array $capture, bool $replace): array {
    if ($replace) {
      /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $list */
      $list = $target->entity()->get(ContentHost::LAYOUT_FIELD);
      $list->removeAllSections();
      if ($target->hasStyleStore()) {
        $target->setStyleMap([]);
      }
    }
    $result = $this->instantiator->instantiateDocument($target, $capture['nodes'], $capture['styles'], [
      'path' => 'element',
      'indexed' => count($capture['nodes']) > 1,
      'batch' => ['system' => TRUE, 'admitted' => TRUE],
    ]);
    if (!$result->isSuccess()) {
      throw new BlueprintGestureException(array_map(
        static fn (BlueprintError $error): BlueprintError => new BlueprintError($error->getPath(), $error->getCode(), $error->getMessage(), $error->getContext(), $error->getHint()),
        $result->getErrors(),
      ));
    }
    return array_map(static fn (BlueprintWarning $warning): string => $warning->getMessage(), $result->getWarnings());
  }

}
