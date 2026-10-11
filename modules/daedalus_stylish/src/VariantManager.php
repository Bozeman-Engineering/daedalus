<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\VariantValidationException;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;

/**
 * The variant gestures: save-as, push, details, usage and lifecycle.
 *
 * Lifecycle is revision restore, revert, copy, and delete
 * (unworn-immediate or the detach-then-delete batch).
 *
 * Save-as, push/details and delete are the variant kind of the material
 * gesture family (DaedalusStylishMaterialsProvider dispatches save_material /
 * update_material / remove_material here; the variant routes are adapters
 * over the same provider methods). A variant is a site-wide content
 * entity, so its reversibility nets are content nets (revisions on plain
 * sites, workspace staging on workspace sites), never the page's
 * tempstore. The RE-ATTACH after a save or push is a spine op
 * (apply_variant), issued by the client through the ordinary op queue;
 * push-ok + re-attach-fail leaves the element detached-but-identical,
 * which is harmless and self-heals on retry.
 *
 * Styles arrive element-RELATIVE ('' for the root element, '::.class-name'
 * for sub-elements, slot-key paths like "container(1)/block(text,2)" for
 * a composition's children: the variant entity's storage shape), captured
 * by the client from the staged style map (the subtree walk lives in
 * variant-scope.js's captureEntries; slot keys ride the styles array, the
 * key parameter always names the root element). They are validated
 * against the vocabulary roster of the variant's scope exactly like any
 * other write (variants are saved VALUES within what the roster
 * grants), through the validator's one door (StyleMapValidator::
 * validateAt() at the stored scope parsed as an Address): the same path
 * disk-shipped and AI-authored variant styles validate through, so a
 * variant validates wherever it is worn, at every placement depth.
 */
class VariantManager {

  use StringTranslationTrait;

  /**
   * The blueprint discovery service, when daedalus_blueprint_library exists.
   *
   * Deliberately untyped (its interface lives in an optional module): the
   * resolver for the blueprint_file ownership lane: a file-shipped blueprint
   * has no entity to reference, so its owned variants store its machine
   * name and orphanhood is "discovery no longer serves the id".
   *
   * @var object|null
   */
  protected ?object $blueprintDiscovery;

  public function __construct(
    protected StyleMapValidator $validator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected VariantUsage $usage,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected VariantImporter $importer,
    protected ActiveDesignSystem $activeDesignSystem,
    protected DistroLock $distroLock,
    protected HostResolver $hostResolver,
    protected ?EntityTempstoreRepository $tempstore = NULL,
    ?object $blueprintDiscovery = NULL,
  ) {
    $this->blueprintDiscovery = $blueprintDiscovery;
  }

  /**
   * A variant as the gesture routes speak it (the response and usage shape).
   *
   * Not a roster shape: the published set reaches the client as materials
   * records (DaedalusStylishMaterialsProvider); this is the per-gesture payload a
   * write answers with beside the materials delta, and the management
   * facts the usage route reads per open.
   *
   * @return array
   *   {machine_name, uuid, label, scope, axis, styles, provider,
   *   shipped_state}. axis is the variant axis the variant competes on
   *   within its scope (the client replaces within an axis and composes
   *   across axes). shipped_state is one of 'unmodified', 'modified' or
   *   'defaults_changed', or NULL when the variant has no
   *   living disk source (site-created, or its provider stopped shipping
   *   it), in which case no revert is ever offered. OWNED variants (the
   *   `blueprint` reference and `blueprint_file` machine-name fields
   *   daedalus_blueprint_library provides — read here as data, never a
   *   dependency) additionally carry `blueprint` (the owning blueprint's
   *   machine name, matching the placement provenance stamps the client
   *   filters offering on) and `blueprint_orphaned` (TRUE when the owner is
   *   gone, the content blueprint deleted or the file blueprint no longer
   *   discovered: offered nowhere, existing wearers keep rendering).
   *   Unowned variants carry neither key.
   */
  public function variantPayload(DaedalusStylishVariantInterface $variant): array {
    $payload = [
      'machine_name' => $variant->getMachineName(),
      'uuid' => $variant->uuid(),
      'label' => (string) $variant->label(),
      'scope' => $variant->getScope(),
      'axis' => $variant->getAxis(),
      'styles' => $variant->getStyles(),
      'provider' => $variant->getProvider(),
      'shipped_state' => $this->shippedState($variant),
    ];
    if ($variant->hasField('blueprint') && !$variant->get('blueprint')->isEmpty()) {
      $owner = $variant->get('blueprint')->entity;
      $payload['blueprint'] = $owner !== NULL ? (string) $owner->get('machine_name')->value : NULL;
      $payload['blueprint_orphaned'] = $owner === NULL;
    }
    elseif ($variant->hasField('blueprint_file') && ($file_owner = (string) ($variant->get('blueprint_file')->value ?? '')) !== '') {
      // The FILE lane: a module-shipped blueprint owns by machine name. Alive
      // means discovery still serves the id; mirroring the reference lane,
      // an orphaned owner reads as NULL (the annotation says "owned but the
      // owner is gone", never a name the site cannot resolve).
      $alive = is_array($this->blueprintDiscovery?->getBlueprint($file_owner));
      $payload['blueprint'] = $alive ? $file_owner : NULL;
      $payload['blueprint_orphaned'] = !$alive;
    }
    return $payload;
  }

  /**
   * Computes a variant's shipped state against the CURRENT disk styles.
   *
   * One stored hash, two comparisons (see DaedalusStylishVariantInterface):
   * 'defaults_changed' wins because it is the actionable signal: the
   * provider ships something newer than the last sync, and for a modified
   * variant the auto-sync deliberately kept the user's styles, so the revert
   * button IS the ask. A modified variant whose disk is unchanged is plain
   * 'modified' (revert = undo the drift back to the shipped defaults).
   */
  protected function shippedState(DaedalusStylishVariantInterface $variant): ?string {
    $shipped = $this->importer->getShippedStyles($variant);
    if ($shipped === NULL) {
      return NULL;
    }
    if ($variant->hasNewDefaults($shipped)) {
      return 'defaults_changed';
    }
    return $variant->isModified() ? 'modified' : 'unmodified';
  }

  /**
   * Loads a variant by reference (machine name or uuid).
   */
  public function loadVariant(string $reference): ?DaedalusStylishVariantInterface {
    return $this->validator->loadVariant($reference);
  }

  /**
   * Counts a variant's uses: host entities whose saved map references it.
   *
   * The number behind the push gesture's loud "updates N uses" UI and the
   * library's "worn on N": WEARERS (one per host entity, however many of
   * its entries reference the variant), read from the daedalus_variant_usage
   * table the map field's save chokepoint maintains (VariantUsage). The
   * table tracks default revisions, so per-user tempstore stages are
   * invisible here, which matches what a push updates — the compiled
   * stylesheet live pages load.
   */
  public function usages(DaedalusStylishVariantInterface $variant): int {
    return $this->usage->countWearers($variant);
  }

  /**
   * Lists a variant's wearers: the work list for site-wide batch gestures.
   *
   * @return array[]
   *   ['entity_type' => string, 'entity_id' => string] per wearing host
   *   entity (VariantUsage::wearers()).
   */
  public function wearers(DaedalusStylishVariantInterface $variant): array {
    return $this->usage->wearers($variant);
  }

  /**
   * Saves an element's styles as a new variant (save-as-variant).
   *
   * The scope is captured server-side from the source element, never
   * from the client's claim — and the styles validate against the style
   * guide through the source element's keys before anything persists.
   *
   * The captured VALUES are overrides-only: the client sends the
   * element's own layers — never the effective styles with cross-axis
   * variants flattened in, which would bake another axis's values into the
   * new variant and silently break the original's push propagation (only
   * the wearings the re-attach replaces fold, client-side:
   * variant-scope.js saveStyles()). Child entries may additionally carry a
   * nested "variants" reference list (refs stay refs: a child's worn root
   * references travel verbatim as entry-shaped fragments the apply
   * re-distributes), and the styles seam validates each nested reference
   * (dangling, scope pairing, cycles). The axis names which of the scope's
   * variant axes the new variant competes on, defaulting to the built-in
   * "style" axis every scope has.
   *
   * @param string $label
   *   The human label ("Testimonial — bold").
   * @param string $key
   *   The source element's selectable key (a ROOT element key; a variant is
   *   saved from an element, and its sub-element rules travel inside it).
   * @param array $styles
   *   Element-relative styles: '' => root entry, '::.class' => sub entries.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The host, for per-block-type scope capture and validation.
   * @param string|null $axis
   *   The variant axis picked at save, validated against the captured
   *   scope's vocabulary. NULL lands on the built-in "style" axis.
   * @param string|null $blueprint
   *   The source element's blueprint provenance (the placed root's stamp),
   *   when the client has one: a variant minted on a blueprint's placement
   *   defaults to that blueprint's ownership. Resolution is content-blueprint
   *   by machine name through the field daedalus_blueprint_library provides;
   *   dead or file-shipped provenance degrades to unowned, mirroring the
   *   client's updatable-blueprint posture (never an error).
   * @param string $origin
   *   Who minted it, MaterialRecord::ORIGIN_USER or ORIGIN_AI: the lane's
   *   stamp, carried on the roster record for the life of the site.
   * @param string $description
   *   When to reach for the look (the AI-facing face); '' for none.
   * @param int $weight
   *   The roster's sort position within the scope's family.
   * @param bool $global
   *   The design-system opt-out. A mint joins the ACTIVE design system
   *   (the vocabulary the editor is looking at is the affiliation they
   *   mean) and validates against that system's default skin; TRUE mints
   *   a global variant, a member of no system, offered under every one
   *   and validated against the active system's vocabulary.
   * @param bool $offered
   *   The AI opt-out: TRUE (the default) shares the variant with the AI,
   *   FALSE keeps it for UI use only (DaedalusStylishVariantInterface::isOffered()).
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishVariantInterface
   *   The saved variant.
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   */
  public function saveAsVariant(string $label, string $key, array $styles, ?HostInterface $host = NULL, ?string $axis = NULL, ?string $blueprint = NULL, string $origin = MaterialRecord::ORIGIN_USER, string $description = '', int $weight = 0, bool $global = FALSE, bool $offered = TRUE): DaedalusStylishVariantInterface {
    if (trim($label) === '') {
      throw new VariantValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'A variant needs a label.', [], 'Name the variant the way editors should find it in the library.'),
      ]);
    }
    $context = $host !== NULL ? ['host' => $host] : [];
    $scope = (string) $this->sourceScope($key, $context);
    $this->validateAxis($axis, $scope);
    $normalized = $this->normalizeStyles($styles);
    $design_system = $global ? NULL : $this->activeDesignSystem->activeId();
    $this->validateStyles($normalized, $scope, NULL, $axis, $design_system);

    $values = [
      'label' => trim($label),
      'scope' => $scope,
      'uid' => $this->currentUser->id(),
      'description' => trim($description),
      'weight' => $weight,
      'ai_offered' => $offered,
      'origin' => $origin,
      'design_system' => $design_system,
    ];
    if ($axis !== NULL && $axis !== '') {
      $values['axis'] = $axis;
    }
    $variant = $this->entityTypeManager->getStorage('daedalus_variant')->create($values);
    assert($variant instanceof DaedalusStylishVariantInterface);
    $variant->setStyles($normalized);
    if ($blueprint !== NULL && $blueprint !== '' && $variant->hasField('blueprint')) {
      $ids = $this->entityTypeManager->getStorage('blueprint')->getQuery()
        ->accessCheck(FALSE)
        ->condition('machine_name', $blueprint)
        ->range(0, 1)
        ->execute();
      if ($ids !== []) {
        $variant->set('blueprint', reset($ids));
      }
    }
    $variant->save();
    return $variant;
  }

  /**
   * Pushes an element's styles back into a variant ("update variant").
   *
   * The one deliberate propagation gesture: every use of the variant updates
   * site-wide. REVISIONED — a new default revision, so a bad push is one
   * revert away, and workspace-staged when saved inside a workspace.
   * Recompilation of the shared stylesheet rides the ordinary entity-update
   * hook.
   *
   * The styles are the variant's NEW styles wholesale. Under the layered
   * entry model the client computes them from its capture (overrides-only
   * values, children's worn root references riding as refs): pushing from
   * an element wearing the variant merges the capture OVER the variant's
   * current styles (so properties the editor never touched survive the
   * push), pushing from an unattached element replaces them
   * (variant-scope.js pushStyles()). The overrides the push bakes into the
   * variant are then retired from the source element by the client's
   * re-attach batch (apply_variant + shadow-clearing unsets through the op
   * spine — the single writer of staged maps, so the blueprint mirror stays
   * in sync), leaving the element following the variant again.
   *
   * An EMPTY key is the master push (the variant master surface: the
   * variant edited in isolation, no source element at all): there is no
   * source to scope-match, so the source checks are skipped and the
   * styles validate against the variant's own scope alone — which they
   * always do anyway (see the comment at the validation call).
   *
   * Shipped variants refuse every push — the distro lock; see
   * checkShippedLock(). "Copy as my variant" is the fork gesture.
   *
   * @return int
   *   The number of uses the push updated (for the confirmation message).
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   */
  public function push(DaedalusStylishVariantInterface $variant, string $key, array $styles, ?HostInterface $host = NULL): int {
    $this->checkShippedLock($variant);
    $context = $host !== NULL ? ['host' => $host] : [];
    if ($key !== '') {
      $this->sourceScope($key, $context);
      if (!$this->validator->scopeMatches($variant->getScope(), $key, $context)) {
        throw new VariantValidationException([
          new BlueprintError('variant', 'STYLE_VARIANT_SCOPE_MISMATCH', sprintf('The "%s" variant is scoped to "%s"; styles from "%s" cannot update it.', $variant->getMachineName(), $variant->getScope(), $key), ['variant_scope' => $variant->getScope()], 'Push styles into a variant saved from the same kind of element, or save them as a new variant.'),
        ]);
      }
    }
    $normalized = $this->normalizeStyles($styles);
    // Validate against the VARIANT's scope, not the captured one: the push
    // updates every use of the variant, and the variant's own scope names
    // the declaration those uses live under. A degraded capture (kind:block
    // for an unresolvable placement) would validate against the weaker
    // universal block declaration.
    $this->validateStyles($normalized, $variant->getScope(), $variant);

    $variant->setStyles($normalized);
    $variant->setNewRevision(TRUE);
    $variant->setRevisionUserId((int) $this->currentUser->id());
    $variant->setRevisionCreationTime($this->time->getRequestTime());
    $variant->setRevisionLogMessage($key === '' ? 'Styles edited in the variant code view.' : sprintf('Styles pushed from %s.', $key));
    $variant->save();

    return $this->usages($variant);
  }

  /**
   * Reverts a shipped variant to its current disk defaults.
   *
   * An ORDINARY revisioned save of the styles as the provider ships them
   * right now, so it is one revision revert away from undo on plain sites
   * and stages workspace-wide inside a workspace for free (unlike the
   * importer's sync, which deliberately escapes the workspace: shipped
   * defaults land live, but a REVERT is a user gesture and belongs in the
   * user's net). The disk styles ride the same scope-only seam every other
   * surface validates through: a modified variant skipped the importer's
   * auto-sync (KEPT), so the current disk styles may never have met the
   * spine on this site.
   *
   * The revert also re-stamps the shipped source hash, so afterwards the
   * variant reads unmodified against what disk ships. Label and scope stay
   * as they are: the hash covers styles only, and label/scope drift rides
   * the importer's next styles sync.
   *
   * @return int
   *   The number of uses the revert updated (for the confirmation message).
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   */
  public function revert(DaedalusStylishVariantInterface $variant): int {
    $provider = $variant->getProvider();
    if ($provider === NULL) {
      throw new VariantValidationException([
        new BlueprintError('variant', 'STYLE_VARIANT_NOT_SHIPPED', sprintf('The "%s" variant was created on this site; it has no shipped defaults.', $variant->getMachineName()), [], 'Only module-shipped variants can revert to their disk defaults.'),
      ]);
    }
    $shipped = $this->importer->getShippedStyles($variant);
    if ($shipped === NULL) {
      throw new VariantValidationException([
        new BlueprintError('variant', 'SHIPPED_DEFAULTS_MISSING', sprintf('The "%s" module no longer ships defaults for "%s".', $provider, $variant->getMachineName()), [], 'The variant lives on as ordinary site content; edit or push styles into it instead.'),
      ]);
    }
    // Validate against the variant's own scope, exactly like push(): the
    // revert updates every use of the variant site-wide.
    $this->validateStyles($shipped, $variant->getScope(), $variant);

    // Styles and hash both come from the SAME disk read, the importer's own
    // invariant, so the variant reads unmodified afterwards.
    $variant->setStyles($shipped);
    $variant->setShippedSource($provider, $shipped);
    $variant->setNewRevision(TRUE);
    $variant->setRevisionUserId((int) $this->currentUser->id());
    $variant->setRevisionCreationTime($this->time->getRequestTime());
    $variant->setRevisionLogMessage(sprintf('Reverted to the shipped defaults of the %s module.', $provider));
    $variant->save();

    return $this->usages($variant);
  }

  /**
   * The distro lock: a variant with a living disk source is read-only.
   *
   * A shipped variant is the provider's design vocabulary, synced from its
   * variants file; letting a site push into it forks the content away from
   * the file and turns every future sync into a conflict. So the disk stays
   * the one author: pushes are refused, "Copy as my variant"
   * (copyAsMyVariant()) is the fork gesture, and changing the shipped look
   * itself means changing the module's variants file. The lock reads the
   * LIVING disk source (the provider ships the entry right now), not the
   * provider field alone: a variant whose provider stopped shipping it
   * lives on as ordinary site content and stays editable — the exact
   * complement of revert()'s SHIPPED_DEFAULTS_MISSING hint. Revert stays
   * open on locked variants (it converges TOWARD the disk, never away).
   *
   * The lock is the distro lock (DistroLock): it holds on a consuming site
   * and lifts on a development site whose settings.php names the package
   * author (`$settings['daedalus_blueprint_developer'] = TRUE`), where the
   * site is the authoring canvas and `drush daedalus-blueprint:design-system:ship`
   * carries the variant back to disk.
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   */
  protected function checkShippedLock(DaedalusStylishVariantInterface $variant): void {
    if (!$this->distroLock->holds() || $this->importer->getShippedStyles($variant) === NULL) {
      return;
    }
    throw new VariantValidationException([
      new BlueprintError('variant', 'STYLE_VARIANT_SHIPPED_LOCKED', sprintf('The "%s" variant is shipped by the "%s" module; shipped variants are read-only.', $variant->getMachineName(), (string) $variant->getProvider()), ['provider' => (string) $variant->getProvider()], 'Copy it as your own variant and edit the copy, or change the shipped defaults in the module\'s variants file.'),
    ]);
  }

  /**
   * Copies a variant as a site-owned variant: the distro lock's fork gesture.
   *
   * A verbatim copy of the source's scope, axis and styles under a new
   * machine name (minted from the label at save), owned by the current
   * user with NO shipped source — so the copy is editable everywhere the
   * original is locked. The styles are stored content that already passed
   * the scope-only seam when the source was saved, so they copy without
   * re-validation, exactly like a revision revert: a fork must preserve
   * the look verbatim even where the guide has drifted since. Blueprint
   * ownership deliberately does not travel: the copy is the user's own,
   * offered wherever its scope matches.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant
   *   The variant to copy (shipped or not; forking a site variant is
   *   harmless and the gesture is uniform).
   * @param string|null $label
   *   The copy's label; NULL or empty derives '<source label> (copy)'.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishVariantInterface
   *   The saved copy.
   */
  public function copyAsMyVariant(DaedalusStylishVariantInterface $variant, ?string $label = NULL): DaedalusStylishVariantInterface {
    $label = trim((string) $label);
    if ($label === '') {
      $label = sprintf('%s (copy)', (string) $variant->label());
    }
    // The copy keeps the original's membership: its styles speak the same
    // vocabulary, so they resolve in the same design system.
    $copy = $this->entityTypeManager->getStorage('daedalus_variant')->create([
      'label' => $label,
      'scope' => $variant->getScope(),
      'axis' => $variant->getAxis(),
      'uid' => $this->currentUser->id(),
      'design_system' => $variant->getDesignSystem(),
    ]);
    assert($copy instanceof DaedalusStylishVariantInterface);
    $copy->setStyles($variant->getStyles());
    $copy->save();
    return $copy;
  }

  /**
   * Updates a variant's face: label, description, weight, in one revision.
   *
   * The metadata half of update_material (kind variant) beside push()'s
   * styles half, and the lifecycle card's rename gesture. Machine name and
   * uuid are identity fields (non-revisionable, never changed), so every
   * style map reference survives a rename untouched; only what pickers,
   * the library and the AI roster display changes. Shipped variants refuse
   * (the distro lock: the importer's sync owns their face the same way it
   * owns their styles, and a renamed shipped variant would fight every
   * future sync).
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant
   *   The variant.
   * @param string|null $label
   *   The new label, or NULL to leave it.
   * @param string|null $description
   *   The new description ('' clears it), or NULL to leave it.
   * @param int|null $weight
   *   The new weight, or NULL to leave it.
   * @param bool|null $offered
   *   The AI opt-out (TRUE offers the variant to the AI, FALSE keeps it
   *   for UI use only), or NULL to leave it.
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   */
  public function updateDetails(DaedalusStylishVariantInterface $variant, ?string $label = NULL, ?string $description = NULL, ?int $weight = NULL, ?bool $offered = NULL): DaedalusStylishVariantInterface {
    $this->checkShippedLock($variant);
    if ($label === NULL && $description === NULL && $weight === NULL && $offered === NULL) {
      throw new VariantValidationException([
        new BlueprintError('', 'MISSING_PARAMETER', 'The update changes nothing: pass a label, a description, a weight, offered, or styles.', [], 'Pass "label" / "description" / "weight" / "offered" for the face, or "element" / "styles" to push a new look.'),
      ]);
    }
    $label = $label === NULL ? NULL : trim($label);
    if ($label === '') {
      throw new VariantValidationException([
        new BlueprintError('label', 'MISSING_PARAMETER', 'A variant needs a label.', [], 'Name the variant the way editors should find it in the library.'),
      ]);
    }
    $log = [];
    $previous = (string) $variant->label();
    if ($label !== NULL && $label !== $previous) {
      $variant->set('label', $label);
      $log[] = sprintf('Renamed from "%s" to "%s".', $previous, $label);
    }
    if ($description !== NULL && trim($description) !== $variant->getDescription()) {
      $variant->setDescription(trim($description));
      $log[] = 'Description updated.';
    }
    if ($weight !== NULL && $weight !== $variant->getWeight()) {
      $variant->setWeight($weight);
      $log[] = 'Weight updated.';
    }
    if ($offered !== NULL && $offered !== $variant->isOffered()) {
      $variant->setOffered($offered);
      $log[] = $offered ? 'Offered to the AI.' : 'Withheld from the AI.';
    }
    if ($log === []) {
      return $variant;
    }
    $variant->setNewRevision(TRUE);
    $variant->setRevisionUserId((int) $this->currentUser->id());
    $variant->setRevisionCreationTime($this->time->getRequestTime());
    $variant->setRevisionLogMessage(implode(' ', $log));
    $variant->save();
    return $variant;
  }

  /**
   * A variant's revision history, newest first: the restore gesture's menu.
   *
   * @return array[]
   *   {revision_id, label, created, user, log_message, current} per
   *   revision. current marks the default revision restore is measured
   *   against.
   */
  public function revisionsList(DaedalusStylishVariantInterface $variant): array {
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $ids = $storage->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('id', $variant->id())
      ->sort('revision_id', 'DESC')
      ->execute();
    $current = (int) $variant->getRevisionId();
    $list = [];
    foreach (array_keys($ids) as $revision_id) {
      $revision = $storage->loadRevision($revision_id);
      if (!$revision instanceof DaedalusStylishVariantInterface) {
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
   * workspace, exactly like push. The old revision's REVISIONABLE fields
   * (label, styles) come back; identity fields were never revisioned and
   * cannot drift. Like a revision revert anywhere (and like
   * copyAsMyVariant()), the styles are stored content that passed the
   * scope-only seam when they were saved, so they restore without
   * re-validation even where the guide has tightened since. Shipped
   * variants refuse — restoring old content diverges from the disk, which
   * is exactly what the distro lock exists to prevent; revert-to-shipped
   * is their one restore.
   *
   * @return int
   *   The number of uses the restore updated (the loud count).
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   */
  public function restoreRevision(DaedalusStylishVariantInterface $variant, int $revision_id): int {
    $this->checkShippedLock($variant);
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $revision = $storage->loadRevision($revision_id);
    if (!$revision instanceof DaedalusStylishVariantInterface || (int) $revision->id() !== (int) $variant->id()) {
      throw new VariantValidationException([
        new BlueprintError('revision', 'STYLE_VARIANT_REVISION_NOT_FOUND', sprintf('The "%s" variant has no revision %d.', $variant->getMachineName(), $revision_id), [], "List the variant's revisions and restore one of those ids."),
      ]);
    }
    $revision->setNewRevision(TRUE);
    $revision->isDefaultRevision(TRUE);
    $revision->setRevisionUserId((int) $this->currentUser->id());
    $revision->setRevisionCreationTime($this->time->getRequestTime());
    $revision->setRevisionLogMessage(sprintf('Restored revision %d.', $revision_id));
    $revision->save();
    return $this->usages($variant);
  }

  /**
   * Deletes a variant: unworn-immediate, or the detach-then-delete batch.
   *
   * Lazy detach is impossible in principle — detach materializes the
   * variant's delivered styles, and after deletion there is nothing to
   * materialize from — so a WORN variant is always detached first, per
   * wearer, and "delete anyway" does not exist: an action whose only
   * effect is visibly breaking N pages is not a button. The wearer work
   * list is the usage table's (workspace publishes resync it, see
   * VariantWorkspaceSubscriber, so the list is exact); each wearer's
   * detach is the op spine's own detach math
   * (DaedalusStylishOperationHandler::detachVariantEverywhere() via
   * VariantDeleteBatch), an ordinary host save, then the entity delete.
   *
   * Two permission grades gate the gesture: the route admits both,
   * and the CONTEXTUAL gate lives here — deleting a worn variant needs
   * "delete variants in use", because base "delete variants" only ever
   * authorizes the zero-wearer case. Shipped variants refuse whatever the
   * grade: the importer would re-import on the next sync, so their
   * removal belongs to the provider's variants file.
   *
   * A worn variant is NOT deleted in this request: the Batch API job is
   * staged (batch_set()) and the caller drives it (the controller returns
   * its URL; Drush would call drush_backend_batch_process()). Only a caller
   * that drives the batch may ask for it: on the op spine (a page_edit
   * batch, the AI's door) nothing drives a core Batch, so a worn variant
   * refuses there with its count and the owner decides in the library.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant
   *   The variant.
   * @param bool $stage_batch
   *   TRUE when the caller drives the staged detach-then-delete batch.
   *
   * @return int
   *   0 when the variant was deleted immediately (unworn); otherwise the
   *   number of wearers the staged batch will detach before deleting.
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   */
  public function deleteVariant(DaedalusStylishVariantInterface $variant, bool $stage_batch = TRUE): int {
    $this->checkShippedLock($variant);
    $wearers = $this->usage->wearers($variant);
    if ($wearers === []) {
      $variant->delete();
      return 0;
    }
    if (!$this->currentUser->hasPermission('delete variants in use') && !$this->currentUser->hasPermission('administer variants')) {
      throw new VariantValidationException([
        new BlueprintError('variant', 'STYLE_VARIANT_IN_USE', sprintf('The "%s" variant is worn by %d piece(s) of content; deleting it detaches each first, which needs the "delete variants in use" permission.', $variant->getMachineName(), count($wearers)), ['usages' => count($wearers)], 'Ask an administrator, or remove the variant from its wearers first.'),
      ]);
    }
    if (!$stage_batch) {
      throw new VariantValidationException([
        new BlueprintError('id', 'STYLE_VARIANT_IN_USE', sprintf('The "%s" variant is worn by %d piece(s) of content; it cannot be removed from here.', $variant->getMachineName(), count($wearers)), ['usages' => count($wearers)], 'Detach it from its wearers first, or delete it from the library, which detaches every wearer (keeping its look as their own styles) before deleting.'),
      ]);
    }
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Deleting the %label style variant', ['%label' => (string) $variant->label()]))
      ->setInitMessage($this->t('Detaching %label from @count pieces of content…', [
        '%label' => (string) $variant->label(),
        '@count' => count($wearers),
      ]))
      ->setFinishCallback([VariantDeleteBatch::class, 'finished']);
    foreach ($wearers as $wearer) {
      $batch->addOperation([
        VariantDeleteBatch::class,
        'detachWearer',
      ], [(int) $variant->id(), $wearer['entity_type'], $wearer['entity_id']]);
    }
    $batch->addOperation([VariantDeleteBatch::class, 'deleteVariant'], [(int) $variant->id()]);
    batch_set($batch->toArray());
    return count($wearers);
  }

  /**
   * Resolves the host a gesture names, preferring its staged copy.
   *
   * The styles being saved were read from the STAGED map (the tempstore
   * session the editor is looking at), so block-bundle resolution should see
   * the staged layout too: a just-placed block exists only there. Without
   * daedalus_tempstore (or with nothing staged) the saved entity serves, and an
   * unresolvable block degrades to the universal block scope exactly as
   * validation does.
   */
  public function resolveHost(?string $entity_type, ?string $id): ?HostInterface {
    if ($entity_type === NULL || $id === NULL || !$this->entityTypeManager->hasDefinition($entity_type)) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $entity = $storage->load($id);
    if ($entity === NULL) {
      $by_uuid = $storage->loadByProperties(['uuid' => $id]);
      $entity = reset($by_uuid) ?: NULL;
    }
    if (!$entity instanceof FieldableEntityInterface) {
      return NULL;
    }
    $staged = $this->tempstore?->get($entity);
    return $this->hostResolver->fromEntity($staged instanceof FieldableEntityInterface ? $staged : $entity);
  }

  /**
   * Captures the source element's address, rejecting keys a variant can't own.
   *
   * The variant entity stores the address printed (its scope field).
   */
  protected function sourceScope(string $key, array $context): Address {
    if (str_contains($key, '::')) {
      throw new VariantValidationException([
        new BlueprintError('key', 'INVALID_STYLE_KEY', 'A variant is saved from an element, not a sub-element.', [], 'Use the element\'s own key; its sub-element rules travel inside the variant.'),
      ]);
    }
    $scope = $this->validator->captureScope($key, $context);
    if ($scope === NULL) {
      throw new VariantValidationException([
        new BlueprintError('key', 'INVALID_STYLE_KEY', sprintf('"%s" is not a valid style map key.', $key), [], 'Keys are selectable keys: entity:{uuid}, container:{uuid}, block:{component_uuid}, …'),
      ]);
    }
    return $scope;
  }

  /**
   * Validates a save-time axis choice against the scope's vocabulary.
   *
   * The gesture-lane twin of VariantStylesFieldHandler::checkSubmittedAxis()
   * (the blueprint lane's corrective) ahead of the save-time backstop in
   * DaedalusStylishVariant::preSave(): same code, same valid_axes context, so both
   * lanes speak one corrective language. NULL/empty means the built-in
   * "style" axis and always passes.
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   */
  protected function validateAxis(?string $axis, string $scope): void {
    if ($axis === NULL || $axis === '') {
      return;
    }
    $axes = $this->validator->scopeAxes($scope);
    if ($axes === NULL || in_array($axis, $axes, TRUE)) {
      return;
    }
    throw new VariantValidationException([
      new BlueprintError('axis', 'STYLE_VARIANT_AXIS_INVALID', sprintf('"%s" is not an axis of the "%s" scope.', $axis, $scope), ['valid_axes' => $axes], sprintf('Valid axes for this scope: %s. Omit the axis for the built-in "style" axis.', implode(', ', $axes))),
    ]);
  }

  /**
   * Normalizes element-relative styles: string keys, empty entries dropped.
   *
   * Shape and grammar checks live in the validator's address door
   * (StyleMapValidator::validateAt()) — one grammar, every surface — so
   * this only normalizes and catches the nothing-to-save case.
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   */
  protected function normalizeStyles(array $styles): array {
    $normalized = [];
    foreach ($styles as $relative => $entry) {
      if ($entry !== []) {
        $normalized[(string) $relative] = $entry;
      }
    }
    if ($normalized === []) {
      throw new VariantValidationException([
        new BlueprintError('styles', 'MISSING_PARAMETER', 'The variant has no styles.', [], 'Style the element first, then save it as a variant.'),
      ]);
    }
    return $normalized;
  }

  /**
   * Validates relative styles at the scope's address.
   *
   * Converges on the validator's address door — the validation disk
   * import and AI authoring run — and re-roots its map-relative error
   * paths onto the gesture's styles parameter. The scope is a print the
   * grammar already admitted (captured from the source element, or the
   * variant's stored scope), so it parses straight through.
   *
   * @param array $normalized
   *   The normalized element-relative styles.
   * @param string $scope
   *   The variant scope string.
   * @param \Drupal\daedalus_stylish\DaedalusStylishVariantInterface|null $variant
   *   The variant the styles are being written INTO (push/revert), or NULL
   *   for a save-as (the variant does not exist yet). Feeds the seam's
   *   cycle walk the saved variant's identity, so a nested reference chain
   *   leading back to it fails at save.
   * @param string|null $axis
   *   The axis picked at save-as (already validated by validateAxis()),
   *   feeding the seam's root-entry axis gate. NULL reads the variant's
   *   own axis (push/revert), falling back to the built-in "style" axis
   *   exactly as the save-time default does.
   * @param string|null $design_system
   *   The design system the styles are judged under at save-as, or NULL for the
   *   active one.
   *
   * @throws \Drupal\daedalus_stylish\Exception\VariantValidationException
   */
  protected function validateStyles(array $normalized, string $scope, ?DaedalusStylishVariantInterface $variant = NULL, ?string $axis = NULL, ?string $design_system = NULL): void {
    // The universe is the member's: an existing variant validates in its
    // own design system, a new one in the membership the mint resolved
    // (NULL, global, means the active system's vocabulary).
    $context = [
      'variant_axis' => ($axis !== NULL && $axis !== '' ? $axis : NULL) ?? $variant?->getAxis() ?? StyleGuide::STYLE_AXIS,
      'design_system' => $variant !== NULL ? $variant->getDesignSystem() : $design_system,
    ];
    if ($variant !== NULL) {
      $context['variant_identity'] = array_filter([$variant->getMachineName(), (string) $variant->uuid()]);
    }
    $errors = $this->validator->validateAt($normalized, Address::fromString($scope), $context);
    if ($errors !== []) {
      throw new VariantValidationException(array_map(
        static function (BlueprintError $error): BlueprintError {
          $path = $error->getPath();
          return $error->withPath(str_starts_with($path, '::') ? "styles$path" : "styles.$path");
        },
        $errors,
      ));
    }
  }

}
