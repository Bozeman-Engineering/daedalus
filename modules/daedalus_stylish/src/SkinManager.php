<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\SkinValidationException;

/**
 * The active skin: which skin the site wears.
 *
 * The site activates a DESIGN SYSTEM (daedalus_blueprint.settings
 * active_design_system, through ActiveDesignSystem), and the skin it
 * wears is that system's: the site's CHOICE for the system
 * (daedalus_stylish.settings skin_choice, keyed by system id) while the
 * choice names a published, conforming member of the system, else the
 * unit's default_skin, the package's answer. The ONE reader of that
 * derivation, so no consumer (delivery, validation, admin UI, AI
 * surface, the switch subscriber) asks the design system itself. The
 * skin is named by machine name, not id: skins ship as YAML and sync
 * across environments, and machine names are the stable identity
 * content ids cannot provide.
 *
 * The choice is a site setting beside the activation, never a write to
 * the unit: on the unit a tenant's choice would edit config the package
 * ships. It is keyed per system so switching systems and back returns
 * to the skin the site chose, and it is judged on every read rather
 * than at choose time alone: a chosen skin later unpublished, moved to
 * another system or left nonconforming by a law change falls back to
 * the default, never bricking delivery. (A site-level active_skin
 * setting once existed and was retired when design systems became the
 * activated unit; this is not its return, because the choice is only
 * ever among the ACTIVE system's conforming members.)
 *
 * The activation is config while skins are content, a deliberate split
 * (decided): EDITS to the active skin are revisioned and workspace-staged
 * via the entity, while SWITCHING design systems, and choosing a skin,
 * is a live admin action, like changing the default theme. Staged
 * switching is deferred and needs no re-architecture: it would wrap the
 * activation, not replace it.
 *
 * Also the ONE answer to which LAYER a site's write to a skin lands in
 * (writesSiteLayer()): the token gestures and the site defaults op both
 * read it here, so the two doors cannot disagree about the lock. And the
 * one revisioned save those gestures take (saveRevision()): every canvas
 * write to a skin is a new revision carrying the gesture's log line, one
 * restore away from undo, staged inside a workspace.
 *
 * The skin's lifecycle gestures live here too, the variant manager's
 * pattern for the look: choose, copy as the site's own skin (any skin
 * on the site, into the active system), the revision history and
 * restore, and the site layer's revert, each an ordinary revisioned
 * save or a site setting, behind the skin routes (SkinApiController)
 * that adapt onto them. The skin card's read (card()) is the one
 * projection of what the active system may wear and what may be
 * copied into it.
 */
class SkinManager {

  /**
   * The daedalus_stylish.settings key holding the site's choices.
   *
   * A map {design system id: skin machine name}; a system absent from
   * it wears its default_skin.
   */
  public const CHOICE_KEY = 'skin_choice';

  /**
   * The config object the choice lives in.
   */
  public const CONFIG_NAME = 'daedalus_stylish.settings';

  public function __construct(
    protected ActiveDesignSystem $activeDesignSystem,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DistroLock $distroLock,
    protected AccountProxyInterface $currentUser,
    protected TimeInterface $time,
    protected ConfigFactoryInterface $configFactory,
    protected SkinConformance $skinConformance,
  ) {}

  /**
   * Whether a site's writes to a skin land in its site layer.
   *
   * TRUE for a package's skin on a consuming site (it has a provider and
   * the distro lock holds): the site's additions and changes live in
   * site_tokens and site_defaults over what the package ships, so an
   * update to the package shows through. FALSE for the site's own skin
   * (no provider: one layer, the package's, edited directly) and under
   * the developer gate (DistroLock lifted: the authoring site edits the
   * package layer it ships back to disk).
   */
  public function writesSiteLayer(DaedalusStylishSkinInterface $skin): bool {
    return $skin->getProvider() !== NULL && $this->distroLock->holds();
  }

  /**
   * Saves a skin as a new revision carrying a gesture's log line.
   *
   * The token gestures (TokenManager) and update_default
   * (DaedalusStylishOperationHandler) end here: an ordinary revisioned
   * save by the current user, so a bad edit is one restore away and a
   * save inside a workspace stages.
   */
  public function saveRevision(DaedalusStylishSkinInterface $skin, string $log): void {
    $skin->setNewRevision(TRUE);
    $skin->setRevisionUserId((int) $this->currentUser->id());
    $skin->setRevisionCreationTime($this->time->getRequestTime());
    $skin->setRevisionLogMessage($log);
    $skin->save();
  }

  /**
   * The active skin's machine name, or NULL when no skin is active.
   *
   * The skin the active design system wears (skinNameFor()); NULL with
   * no active system or a system that carries no skin (blueprints and
   * doctrine only).
   */
  public function activeSkinName(): ?string {
    $id = $this->activeDesignSystem->activeId();
    return $id === NULL ? NULL : $this->skinNameFor($id);
  }

  /**
   * Loads the active skin's live revision.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishSkinInterface|null
   *   The active skin, or NULL when no skin is active or the active
   *   system's default_skin names a skin that does not exist (a recipe's
   *   activation can land before the skins lane syncs; a deleted skin
   *   leaves its system pointing at nothing); delivery treats both as
   *   "no skin", never an error.
   */
  public function loadActiveSkin(): ?DaedalusStylishSkinInterface {
    $id = $this->activeDesignSystem->activeId();
    return $id === NULL ? NULL : $this->loadSkinFor($id);
  }

  /**
   * The machine name of the skin a design system wears on this site.
   *
   * The site's choice for the system when it names a skin the system may
   * wear (refusal() answers NULL for it), else the unit's default_skin.
   * NULL for a system that is not on the site or carries no skin.
   */
  public function skinNameFor(string $system_id): ?string {
    $system = $this->loadSystem($system_id);
    return $system === NULL ? NULL : $this->resolveChoice($system, $this->choice($system_id));
  }

  /**
   * Loads the live revision of the skin a design system wears.
   *
   * NULL when the system wears none or the name resolves to no skin
   * (see loadActiveSkin()).
   */
  public function loadSkinFor(string $system_id): ?DaedalusStylishSkinInterface {
    $name = $this->skinNameFor($system_id);
    return $name === NULL ? NULL : $this->loadByMachineName($name);
  }

  /**
   * The site's stored choice for a design system, judged by nobody.
   *
   * The raw setting: what the site asked for, whether or not the system
   * may wear it right now. NULL when the site has chosen nothing for
   * the system.
   */
  public function choice(string $system_id): ?string {
    $choice = $this->configFactory->get(self::CONFIG_NAME)->get(self::CHOICE_KEY . '.' . $system_id);
    return is_string($choice) && $choice !== '' ? $choice : NULL;
  }

  /**
   * The one rule: a choice the system may wear, else its default_skin.
   *
   * Public because the settings subscriber judges the ORIGINAL choice of
   * a config save against the same rule, to learn which skin the site
   * wore before the save.
   *
   * @param \Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface $system
   *   The design system.
   * @param string|null $choice
   *   The site's choice for it, or NULL for none.
   *
   * @return string|null
   *   The machine name of the skin worn, or NULL for none.
   */
  public function resolveChoice(DesignSystemInterface $system, ?string $choice): ?string {
    if ($choice !== NULL && $this->refusal($system, $choice) === NULL) {
      return $choice;
    }
    $name = $system->getDefaultSkin();
    return is_string($name) && $name !== '' ? $name : NULL;
  }

  /**
   * Why a design system may not wear a skin, or NULL when it may.
   *
   * A skin is choosable for a system while it exists, is published, is a
   * MEMBER of the system (its design_system names it; a global skin is
   * offered under every system's roster but is nobody's look) and mints
   * every name the guide's token contract promises (SkinConformance).
   * The same judge serves the choose gesture (a refusal is its error)
   * and the read (a refused choice falls back to the default_skin).
   */
  public function refusal(DesignSystemInterface $system, string $name): ?BlueprintError {
    $skin = $this->loadByMachineName($name);
    if ($skin === NULL) {
      return new BlueprintError('id', 'STYLE_SKIN_NOT_FOUND', sprintf('"%s" is not a skin on this site.', $name), [], 'Name a skin by its machine name.');
    }
    if ($skin->getDesignSystem() !== $system->id()) {
      return new BlueprintError('id', 'STYLE_SKIN_NOT_MEMBER', sprintf('The "%s" skin is not a member of the "%s" design system.', $name, $system->id()), ['design_system' => $skin->getDesignSystem()], sprintf('A design system wears only its own members; the "%s" skin belongs to %s.', $name, $skin->getDesignSystem() === NULL ? 'no system (it is global)' : 'the "' . $skin->getDesignSystem() . '" design system'));
    }
    if (!$skin->isPublished()) {
      return new BlueprintError('id', 'STYLE_SKIN_UNPUBLISHED', sprintf('The "%s" skin is unpublished.', $name), [], 'Publish the skin, then choose it.');
    }
    $lacks = $this->skinConformance->lacks($skin);
    if ($lacks !== []) {
      $missing = [];
      foreach ($lacks as $scale => $names) {
        $missing[] = $scale . ': ' . implode(', ', $names);
      }
      return new BlueprintError('id', 'STYLE_SKIN_NONCONFORMING', sprintf('The "%s" skin does not mint every token the design system\'s contract promises (%s).', $name, implode('; ', $missing)), ['lacks' => $lacks], 'Mint the missing tokens on the skin (save_material with kind token), then choose it.');
    }
    return NULL;
  }

  /**
   * Chooses the skin the active design system wears on this site.
   *
   * Writes daedalus_stylish.settings skin_choice.{active system id}; the
   * settings subscriber (DefaultsSettingsSubscriber) recompiles the
   * defaults file and records both skins' token ids on the materials
   * delta, so every writer of the setting (this gesture, drush
   * config-set, config sync) restyles the site the same way.
   *
   * @param string $name
   *   The skin's machine name.
   *
   * @return string
   *   The id of the design system the choice was recorded for.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinValidationException
   *   STYLE_DESIGN_SYSTEM_NONE_ACTIVE with no active system, else the
   *   refusal() error.
   */
  public function choose(string $name): string {
    $system = $this->activeDesignSystem->load();
    if ($system === NULL) {
      throw new SkinValidationException([
        new BlueprintError('', 'STYLE_DESIGN_SYSTEM_NONE_ACTIVE', 'No design system is active: a skin is chosen for the active design system.', [], 'Activate a design system, then choose one of its member skins.'),
      ]);
    }
    $refusal = $this->refusal($system, $name);
    if ($refusal !== NULL) {
      throw new SkinValidationException([$refusal]);
    }
    $this->configFactory->getEditable(self::CONFIG_NAME)
      ->set(self::CHOICE_KEY . '.' . $system->id(), $name)
      ->save();
    return (string) $system->id();
  }

  /**
   * A design system's member skins, keyed by machine name, by label.
   *
   * Every skin whose design_system names the system, published or not
   * (the unit's default_skin may name an unpublished one; the choice
   * judge is refusal()).
   *
   * @return array<string, \Drupal\daedalus_stylish\DaedalusStylishSkinInterface>
   *   The members.
   */
  public function memberSkins(string $system_id): array {
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('design_system', $system_id)
      ->sort('label')
      ->execute();
    $members = [];
    foreach ($storage->loadMultiple($ids) as $skin) {
      if ($skin instanceof DaedalusStylishSkinInterface) {
        $members[$skin->getMachineName()] = $skin;
      }
    }
    return $members;
  }

  /**
   * The skin card's read: the active system's skins and what may be worn.
   *
   * One live read behind the Palette's skin card (the skins route): the
   * active design system, the skin it wears right now (skinNameFor()),
   * the site's stored choice, the unit's default_skin, and every member
   * skin by label with the facts the card's gestures key on: whether it
   * is published, its provider, whether the site has a layer of its own
   * over the package's (the revert gesture's subject), whether the
   * package layer is locked here (a restore keeps to the site layer
   * while it is), and the refusal that keeps the system from wearing it
   * (NULL for a choosable member). Beside the members, `sources`: every
   * skin on the site by label, whatever its membership (another
   * system's member, a global skin, a member of the active one), each
   * with its design system (id and label; NULL for a global skin) and
   * provider, since the copy gesture takes any of them as its source and
   * lands the copy in the active system (copyAsMySkin()). Nothing here
   * rides the guide payload: which skin is worn changes with a choice
   * and a copy adds a member, so the card fetches on open and after
   * every gesture.
   *
   * @return array{design_system: ?string, worn: ?string, choice: ?string, default: ?string, skins: list<array<string, mixed>>, sources: list<array<string, mixed>>}
   *   The read; with no active system every fact is NULL and the
   *   members empty, while the sources still list the site's skins (a
   *   copy needs an active system to land in, so the card can say what
   *   there is to copy once one is active).
   */
  public function card(): array {
    $system = $this->activeDesignSystem->load();
    if ($system === NULL) {
      return [
        'design_system' => NULL,
        'worn' => NULL,
        'choice' => NULL,
        'default' => NULL,
        'skins' => [],
        'sources' => $this->sources(),
      ];
    }
    $id = (string) $system->id();
    $default = $system->getDefaultSkin();
    $skins = [];
    foreach ($this->memberSkins($id) as $name => $skin) {
      $refusal = $this->refusal($system, $name);
      $skins[] = [
        'machine_name' => $name,
        'label' => (string) $skin->label(),
        'published' => $skin->isPublished(),
        'provider' => $skin->getProvider(),
        'site_layer' => $this->hasSiteLayer($skin),
        'locked' => $this->writesSiteLayer($skin),
        'refusal' => $refusal?->toArray(),
      ];
    }
    return [
      'design_system' => $id,
      'worn' => $this->resolveChoice($system, $this->choice($id)),
      'choice' => $this->choice($id),
      'default' => is_string($default) && $default !== '' ? $default : NULL,
      'skins' => $skins,
      'sources' => $this->sources(),
    ];
  }

  /**
   * Every skin on the site by label: what the copy gesture may copy.
   *
   * @return list<array{machine_name: string, label: string, design_system: ?string, design_system_label: ?string, provider: ?string}>
   *   One entry per skin; design_system and its label are NULL for a
   *   global skin, and the label falls back to the id for a member of a
   *   unit that has not landed on the site.
   */
  protected function sources(): array {
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->sort('label')
      ->execute();
    $systems = $this->entityTypeManager->getStorage(DesignSystem::ENTITY_TYPE_ID)->loadMultiple();
    $sources = [];
    foreach ($storage->loadMultiple($ids) as $skin) {
      if (!$skin instanceof DaedalusStylishSkinInterface) {
        continue;
      }
      $system_id = $skin->getDesignSystem();
      $sources[] = [
        'machine_name' => $skin->getMachineName(),
        'label' => (string) $skin->label(),
        'design_system' => $system_id,
        'design_system_label' => $system_id === NULL ? NULL : (string) ($systems[$system_id]?->label() ?? $system_id),
        'provider' => $skin->getProvider(),
      ];
    }
    return $sources;
  }

  /**
   * Whether the site holds a layer of its own over the skin's package layer.
   */
  public function hasSiteLayer(DaedalusStylishSkinInterface $skin): bool {
    return $skin->siteTokens() !== [] || $skin->siteDefaults() !== [];
  }

  /**
   * Copies a skin into the active design system as the site's own skin.
   *
   * The variant copy's twin for the look, and the one door through which
   * a design system gets a skin it did not ship: a NEW skin under a new
   * machine name (minted from the label at save), owned by the current
   * user, with NO provider, so every gesture the package's lock refuses
   * on the source is open on the copy (its one layer is the package
   * layer, edited directly; see writesSiteLayer()). The copy's package
   * layer is the source's MERGE: every token record and every default
   * the site sees, the site's changes folded in, so the copy looks
   * exactly as the source does on this site the moment it is worn; its
   * site layer starts empty. It saves published, so it is choosable at
   * once where it conforms.
   *
   * The copy is a member of the ACTIVE design system whatever the
   * source's membership: the source may be a member of the active
   * system, another system's member or a global skin (the route takes
   * no target; every target is reachable by activating it first, and a
   * copy that kept a global source's membership could never be chosen).
   * With no active system there is nothing to land in and the gesture
   * refuses, the error choose() speaks.
   *
   * A site's own unit takes its first copy as its default_skin: when the
   * active unit has no package and no default_skin, the copy's machine
   * name is written to the unit (the config entity's own save), so the
   * copy is worn at once and the unit ships naming a skin. The unit's
   * save is what restyles the site (the daedalus_stylish entity hooks on
   * a design system: the roster drops, the copy's token ids ride the
   * request's materials delta and the defaults file recompiles), which
   * has to follow the copy's save, since the machine name mints there
   * and the skin hook sees a skin that is not yet worn. A packaged unit
   * is never written (a tenant's write would edit config the package
   * ships), nor is one that already names a default: there a copy wears
   * nothing and choosing is the next gesture. Whether the copy is worn
   * is activeSkinName() afterwards: the unit's default it became, unless
   * a standing site choice for the unit outranks it, as always.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin
   *   The skin to copy (shipped or not; forking a site skin is harmless
   *   and the gesture is uniform).
   * @param string|null $label
   *   The copy's label; NULL or empty derives '<source label> (copy)'.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishSkinInterface
   *   The saved copy.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinValidationException
   *   STYLE_DESIGN_SYSTEM_NONE_ACTIVE with no active system.
   */
  public function copyAsMySkin(DaedalusStylishSkinInterface $skin, ?string $label = NULL): DaedalusStylishSkinInterface {
    $system = $this->activeDesignSystem->load();
    if ($system === NULL) {
      throw new SkinValidationException([
        new BlueprintError('', 'STYLE_DESIGN_SYSTEM_NONE_ACTIVE', 'No design system is active: a copy lands in the active design system.', [], 'Activate a design system, then copy a skin into it.'),
      ]);
    }
    $label = trim((string) $label);
    if ($label === '') {
      $label = sprintf('%s (copy)', (string) $skin->label());
    }
    $copy = $this->entityTypeManager->getStorage('daedalus_skin')->create([
      'label' => $label,
      'uid' => $this->currentUser->id(),
      'design_system' => $system->id(),
      'status' => TRUE,
    ]);
    assert($copy instanceof DaedalusStylishSkinInterface);
    $copy->setPackageTokens($skin->tokens());
    $copy->setPackageDefaults($skin->defaults());
    $copy->setRevisionUserId((int) $this->currentUser->id());
    $copy->setRevisionCreationTime($this->time->getRequestTime());
    $copy->setRevisionLogMessage(sprintf('Copied from the "%s" skin.', $skin->getMachineName()));
    $copy->save();
    $default = $system->getDefaultSkin();
    if ($system->getPackage() === NULL && ($default === NULL || $default === '')) {
      $system->set('default_skin', $copy->getMachineName())->save();
    }
    return $copy;
  }

  /**
   * A skin's revision history, newest first: the restore gesture's menu.
   *
   * @return array[]
   *   {revision_id, label, created, user, log_message, current} per
   *   revision, the variant lifecycle card's exact shape. current marks
   *   the default revision restore is measured against.
   */
  public function revisionsList(DaedalusStylishSkinInterface $skin): array {
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $ids = $storage->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('id', $skin->id())
      ->sort('revision_id', 'DESC')
      ->execute();
    $current = (int) $skin->getRevisionId();
    $list = [];
    foreach (array_keys($ids) as $revision_id) {
      $revision = $storage->loadRevision($revision_id);
      if (!$revision instanceof DaedalusStylishSkinInterface) {
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
   * The standard revision-revert shape, deliberately never a rollback:
   * the restored state lands as a fresh revision on top, so a restore is
   * itself one restore away from undo and stages inside a workspace,
   * exactly as the token gestures and update_default do. What comes back
   * is the layer the site writes (writesSiteLayer()): on a consuming site
   * the old revision's SITE layer (site_tokens, site_defaults) lands over
   * the package layer as it is now, since the package layer is the disk's
   * and a revision from before a sync would rewind what the package
   * shipped, the write the distro lock exists to refuse; on the site's
   * own skin and under the developer gate the old revision's revisionable
   * fields come back whole (label, both layers). Identity fields were
   * never revisioned and cannot drift. Stored content passed validation
   * when it was saved, so it restores without re-validation even where
   * the law has tightened since, like a revision revert anywhere.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinValidationException
   *   STYLE_SKIN_REVISION_NOT_FOUND for a revision id that is not this
   *   skin's.
   */
  public function restoreRevision(DaedalusStylishSkinInterface $skin, int $revision_id): DaedalusStylishSkinInterface {
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $revision = $storage->loadRevision($revision_id);
    if (!$revision instanceof DaedalusStylishSkinInterface || (int) $revision->id() !== (int) $skin->id()) {
      throw new SkinValidationException([
        new BlueprintError('revision', 'STYLE_SKIN_REVISION_NOT_FOUND', sprintf('The "%s" skin has no revision %d.', $skin->getMachineName(), $revision_id), [], "List the skin's revisions and restore one of those ids."),
      ]);
    }
    if ($this->writesSiteLayer($skin)) {
      $skin->setSiteTokens($revision->siteTokens());
      $skin->setSiteDefaults($revision->siteDefaults());
      $this->saveRevision($skin, sprintf('Restored the site layer of revision %d.', $revision_id));
      return $skin;
    }
    $revision->setNewRevision(TRUE);
    $revision->isDefaultRevision(TRUE);
    $this->saveRevision($revision, sprintf('Restored revision %d.', $revision_id));
    return $revision;
  }

  /**
   * Reverts the site layer: the skin wears what its package ships, whole.
   *
   * The per-token revert's whole-skin twin: one revisioned save emptying
   * site_tokens and site_defaults, so every site addition and override
   * goes and the package layer shows through as synced. Only a skin with
   * a provider has a package to reveal (the site's own skin has one
   * layer, edited directly: STYLE_SKIN_NOT_SHIPPED). Under the developer
   * gate the site layer is the same layer, so the gesture clears it there
   * too (a package-layer write under the gate leaves an older site record
   * in place, and this is what clears it). Nothing to clear saves
   * nothing and mints no revision.
   *
   * @return bool
   *   TRUE when a revision landed, FALSE when the site layer was empty.
   *
   * @throws \Drupal\daedalus_stylish\Exception\SkinValidationException
   */
  public function revertSiteLayer(DaedalusStylishSkinInterface $skin): bool {
    $provider = $skin->getProvider();
    if ($provider === NULL) {
      throw new SkinValidationException([
        new BlueprintError('id', 'STYLE_SKIN_NOT_SHIPPED', sprintf('The "%s" skin is one this site made; it has no package layer to reveal.', $skin->getMachineName()), [], 'Only a module-shipped skin carries a site layer over its package layer; restore one of this skin\'s revisions instead.'),
      ]);
    }
    if (!$this->hasSiteLayer($skin)) {
      return FALSE;
    }
    $skin->setSiteTokens([]);
    $skin->setSiteDefaults([]);
    $this->saveRevision($skin, sprintf('Reverted the site layer: the skin wears what the %s module ships.', $provider));
    return TRUE;
  }

  /**
   * Loads a skin by its stable machine name.
   */
  public function loadByMachineName(string $machine_name): ?DaedalusStylishSkinInterface {
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $machine_name)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $skin = $storage->load(reset($ids));
    return $skin instanceof DaedalusStylishSkinInterface ? $skin : NULL;
  }

  /**
   * Loads a design system by id, NULL for one not on the site.
   */
  protected function loadSystem(string $id): ?DesignSystemInterface {
    $system = $this->entityTypeManager->getStorage(DesignSystem::ENTITY_TYPE_ID)->load($id);
    return $system instanceof DesignSystemInterface ? $system : NULL;
  }

}
