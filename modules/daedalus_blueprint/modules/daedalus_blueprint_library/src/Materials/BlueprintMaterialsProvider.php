<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Materials;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\IconResolver;
use Drupal\daedalus_blueprint\Materials\BlueprintRequirements;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialGestureResult;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsProviderInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint_library\BlueprintDiscovery;
use Drupal\daedalus_blueprint_library\BlueprintDiscoveryInterface;
use Drupal\daedalus_blueprint_library\BlueprintLayout;
use Drupal\daedalus_blueprint_library\BlueprintInterface;
use Drupal\daedalus_blueprint_library\BlueprintManager;
use Drupal\daedalus_blueprint_library\BlueprintPermissions;
use Drupal\daedalus_blueprint_library\BlueprintTypeInterface;

/**
 * The blueprints as roster records, and the blueprint gestures.
 *
 * The ONE place blueprints are read into material records. Discovery
 * already serves the published set across both sources (module-shipped
 * files and published content blueprints) in one flat id namespace, so a
 * record is a straight projection of a definition: the label and
 * description (the palette face and the AI's when-to-reach-for-this
 * text), the icon RESOLVED to a URL (the catalog carries the icon value
 * grammar; the client palette paints the record and cannot resolve a
 * media reference, so the provider resolves once through the grammar's
 * one seam and the media's tags ride the roster), the blueprint's TYPE as
 * the record's group (the kind-local grouping every roster consumer sorts
 * under: `section`, `site_chrome`, or a type the site added; the
 * permission axis, so a surface can file blueprints by who works on
 * them), the blueprint's scope
 * (block: one container placed into a page; page: a whole page's
 * document), updatable for content blueprints only (a file
 * blueprint has no entity to update, under the developer gate too: editing
 * the file by hand or shipping a new blueprint is its lane, so the record stays
 * `updatable: !$shipped` whatever the distro lock says), the content
 * blueprint's canvas path as the record's url (its canonical route, the
 * one place a detached placement's Open blueprint verb leaves for; a file
 * has no canvas, so NULL), origin shipped
 * for file blueprints and the
 * content blueprint's own origin field (user or ai, stamped by the lane
 * that minted it), and the membership discovery resolved (a design system
 * id or NULL for global; the roster partitions on it, this provider
 * filters nothing), and a block-scope blueprint's root wearings (the
 * variant ids in its one root node's own styles entry,
 * MaterialRecord::APPLIES_WEARS): a capture fact only, since this module
 * knows no axis; the roster derives "page band" from it against the
 * variant records it holds (MaterialsRoster::isBand()), the definition's
 * layout as the record's tree (discovery serves it in the printer's
 * shape: what the schema prints as the blueprint's page-language fragment,
 * and page_read on demand), what the blueprint needs of a host as the
 * record's requires (BlueprintRequirements::derive() over the layout, the
 * allow-list and the type's frame-only rule; the delivery judges the fit,
 * never this provider), and
 * the definition's node-less `styles` as the record's styles (the
 * page-level entries a page's start copies beside the tree, PageStart),
 * the AI opt-out discovery resolved (`offered`, from the file's
 * `ai: false` or the content blueprint's own field: the roster's offered
 * view omits a withheld blueprint, so it leaves the brief and the schema
 * while the palette still places it). The blueprint slot spelling is the
 * id itself: <pb-blueprint name="id">.
 *
 * The blueprint gestures (save_material / update_material / remove_material
 * with kind blueprint) dispatch here from the op spine; the palette's Save
 * as blueprint and Update blueprint verbs submit the same ops, and the
 * details and delete routes (BlueprintApiController) are adapters over the
 * same methods. Capture is a SPINE OP so the AI can compose a subtree with
 * ordinary operations and save it as a blueprint in the SAME batch: the
 * capture reads the batch's live entity, exactly what the preceding
 * operations staged. Every verb needs the blueprint entity's own create or update access
 * (capability parity never means validation
 * exemption): blueprints are site-wide content, a stricter surface than the
 * staged-entity mutations the batch permission covers, and the entity's
 * own access handler stays the single authority, judging by the
 * blueprint's TYPE (a capture names its type, `section` unless told
 * otherwise, and needs that type's create permission; update and remove
 * ask the saved blueprint, whose type decides).
 */
final class BlueprintMaterialsProvider implements MaterialsProviderInterface {

  /**
   * The keys update_material may change on a blueprint.
   */
  private const UPDATE_KEYS = [
    'element',
    'strip_values',
    'label',
    'description',
    'icon',
    'offered',
    'subject',
    'bundles',
    'groups',
    'favorite',
    'adopt_variants',
  ];

  /**
   * The icon media tags the last records() read merged, for cacheMetadata().
   *
   * @var string[]
   */
  protected array $iconCacheTags = [];

  /**
   * Constructs the provider.
   *
   * The manager arrives as a closure: the dependency is circular by
   * construction (the manager's exporter reaches the field handlers, whose
   * validator reads the roster this provider feeds).
   */
  public function __construct(
    protected BlueprintDiscoveryInterface $discovery,
    protected IconResolver $iconResolver,
    protected \Closure $manager,
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The blueprint manager, the one implementation behind every verb.
   */
  protected function manager(): BlueprintManager {
    return ($this->manager)();
  }

  /**
   * {@inheritdoc}
   */
  public function records(): array {
    $records = [];
    $this->iconCacheTags = [];
    foreach ($this->discovery->getBlueprints() as $id => $blueprint) {
      $shipped = $blueprint['source'] === BlueprintDiscoveryInterface::SOURCE_MODULE;
      // NULL (unset, or a media reference that no longer resolves to a
      // file) means "the consumer's default icon"; the media's tags merge
      // even then, so the media's edits invalidate every projection of
      // the roster that decided "default" from its state.
      $icon = $this->iconResolver->resolve($blueprint['icon'] ?? NULL, $this->iconCacheTags);
      // The wearings are a block-scope blueprint's one root's: what a
      // placement lands wearing. A page-scope document has no single
      // root to place and no band verdict to derive; an empty canvas (a
      // blueprint composed from scratch, not yet holding its root) wears
      // nothing yet.
      $wears = $blueprint['scope'] === MaterialRecord::SCOPE_BLOCK && isset($blueprint['layout'][0]) ? BlueprintLayout::wears($blueprint['layout'][0]) : [];
      $records[] = new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: (string) $id,
        label: (string) $blueprint['label'],
        description: (string) $blueprint['description'],
        // The type is the roster's grouping of blueprints (the record's
        // kind-local group, as a token's scale or a variant's axis).
        group: (string) $blueprint['type'],
        spellings: [MaterialRecord::SLOT_BLUEPRINT => (string) $id],
        applies: $wears === [] ? [] : [MaterialRecord::APPLIES_WEARS => $wears],
        icon: $icon,
        blueprintScope: (string) $blueprint['scope'],
        origin: $shipped ? MaterialRecord::ORIGIN_SHIPPED : (string) ($blueprint['origin'] ?? MaterialRecord::ORIGIN_USER),
        updatable: !$shipped,
        designSystem: $blueprint['design_system'],
        offered: $blueprint['offered'],
        // The record's tree is a fragment: a content blueprint's document
        // identity stays on the discovery entry (placement maps it) and
        // never prints.
        tree: BlueprintLayout::withoutUuids($blueprint['layout']),
        // A saved blueprint is a page of its own; a file has none.
        entityId: $shipped ? NULL : (string) $blueprint['entity_id'],
        url: $shipped ? NULL : Url::fromRoute('entity.blueprint.canonical', ['blueprint' => $blueprint['entity_id']])->toString(),
        // What the blueprint needs of a host: the canvas offer judges the
        // fit by it, and the delivery keeps only what a canvas offers. A
        // frame-only type's blueprint (the site chrome) needs the frame,
        // so no page or template canvas is offered it.
        requires: BlueprintRequirements::derive($blueprint['layout'], $blueprint['bundles'], $blueprint['frame_only']),
        // Filter categories on an offering surface; nothing judges by them.
        groups: $blueprint['groups'],
        // The site favorite: a listing fact, nothing judges by it either.
        favorite: $blueprint['favorite'],
        // The page-level styles beside the tree: a page's start copies the
        // whole document, both halves; a placement takes the tree alone.
        styles: $blueprint['styles'],
      );
    }
    return $records;
  }

  /**
   * {@inheritdoc}
   *
   * The builder asks right after records(), so the icon media tags that
   * read merged ride along with the vocabulary tag.
   */
  public function cacheMetadata(): CacheableMetadata {
    // The single "the blueprint vocabulary changed" signal: file changes
    // through cache rebuilds, content CRUD through BlueprintCacheHooks.
    return (new CacheableMetadata())->addCacheTags([BlueprintDiscovery::CACHE_TAG, ...$this->iconCacheTags]);
  }

  /**
   * {@inheritdoc}
   *
   * The one place the capture and update keys are taught (the brief's
   * MINT line renders it): the capture keys, then the update gesture
   * with the keys update() accepts, derived from UPDATE_KEYS so the line
   * cannot drift from what the gesture takes. The placement rules are
   * the cached blueprint sentence's (DialectCatalogPrompt), not this
   * line's.
   */
  public function gestureLines(): array {
    return [
      MaterialKind::Blueprint->value => sprintf('element (the composed root, same batch), description, icon? ("media:<uuid>" of an existing item), type? (the blueprint type, "%s" unless named; the type decides who may work on it and files it in the roster), adopt_variants? (style variants that become its own curated looks, worn only inside its placements), offered? (false keeps it out of your roster, for the palette only), strip_values? (true saves the structure alone; fields sample-populate at placement); update_material {kind: blueprint, id} takes any of %s (element pushes a fresh capture; placements are independent copies and never re-sync; the type never changes; subject is "entity_type:bundle", bundles a list of them, groups a list of the site\'s group ids (the schema\'s materials.groups): filters only, favorite true lists it under BLUEPRINTS for everyone)', BlueprintInterface::TYPE_SECTION, implode(', ', self::UPDATE_KEYS)),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Save_material {kind: blueprint, element, label, description, icon?,
   * type?, adopt_variants?, global?}: mints a blueprint from the element's
   * subtree. `type` names the blueprint type (typeOf(): `section` unless
   * named; the type's create permission is the gate, requirePermission()).
   * The icon is an EXISTING media item in the icon value grammar's media
   * spelling; adopt_variants lists style variants that become the
   * blueprint's own curated looks (adoption runs AFTER the save, so an
   * adoption failure follows the op's documented side-effect semantics:
   * the batch fails but the saved blueprint remains, one delete away);
   * `global` opts the blueprint out of the active design system (the
   * membership rule every capture shares, see globalOptOut()); `offered`
   * is the AI opt-out (offeredFlag(): false keeps the blueprint for the
   * palette only).
   */
  public function capture(MaterialKind $kind, array $operation, ?HostInterface $host, array $options): MaterialGestureResult {
    $type = $this->typeOf($operation);
    $this->requirePermission($type);
    $element = $this->requireElement($operation, $host);
    $adopt = $this->adoptList($operation);
    $global = $this->globalOptOut($operation);
    $icon = $operation['icon'] ?? NULL;
    if ($icon !== NULL && !is_string($icon)) {
      throw new MaterialGestureException([
        new BlueprintError('icon', 'INVALID_PARAMETER', 'icon must be a media reference string.', [], 'Pass an existing media item as "media:<uuid>", or omit the key for the default blueprint icon.'),
      ]);
    }
    $result = $this->manager()->saveAsBlueprint(
      $host,
      $element,
      is_scalar($operation['label'] ?? NULL) ? (string) $operation['label'] : '',
      is_scalar($operation['description'] ?? NULL) ? (string) $operation['description'] : '',
      $icon,
      $options[MaterialGestureOperationHandler::ORIGIN_OPTION] ?? MaterialRecord::ORIGIN_USER,
      $global,
      $this->offeredFlag($operation) ?? TRUE,
      $this->stripValuesFlag($operation),
      $type,
    );
    if ($adopt !== []) {
      $this->manager()->adoptVariants($result->blueprint, $adopt);
    }
    return new MaterialGestureResult(MaterialKind::Blueprint, $result->blueprint->getMachineName(), $result->warnings, $result->blueprint);
  }

  /**
   * {@inheritdoc}
   *
   * Update_material {kind: blueprint, id, element?, label?, description?,
   * icon?, offered?, subject?, bundles?, groups?, favorite?,
   * adopt_variants?}: one
   * revisioned write of whatever is present (a bad update is one revert
   * away, workspace-staged inside a workspace).
   * `element` pushes a fresh capture of the subtree (future placements stamp
   * it; existing placements never re-sync); label, description and icon rewrite
   * the palette face (icon PRESENT writes, NULL or '' clearing back to the
   * default; ABSENT leaves the stored icon standing); offered flips the AI
   * opt-out (offeredFlag()); subject and bundles rewrite what the
   * blueprint is material for (BlueprintSubjects); groups refiles it under
   * the site's groups (filters only); favorite (a boolean) marks
   * or unmarks it as a site favorite (a listing fact). Only content blueprints
   * update: file-shipped blueprints refuse with their own error, never a bare not-found.
   */
  public function update(MaterialKind $kind, string $id, array $operation, ?HostInterface $host, array $options): MaterialGestureResult {
    $blueprint = $this->manager()->resolveUpdatable($id);
    $this->requireEntityAccess($blueprint, 'update');
    $changes = array_intersect_key($operation, array_flip(self::UPDATE_KEYS));
    if ($changes === []) {
      throw new MaterialGestureException([
        new BlueprintError('', 'MISSING_PARAMETER', 'update_material changes nothing: pass at least one key.', ['keys' => self::UPDATE_KEYS], 'Pass "element" to push a fresh capture, "label" / "description" / "icon" to rewrite the palette face, "offered" to flip the AI opt-out, "subject" / "bundles" to change what it is material for, "groups" to refile it under the site\'s groups, "favorite" to mark or unmark it as a site favorite, or "adopt_variants" to curate looks.'),
      ]);
    }
    if (array_key_exists('element', $changes)) {
      $changes['element'] = $this->requireElement($operation, $host);
    }
    if (array_key_exists('offered', $changes)) {
      $changes['offered'] = $this->offeredFlag($changes);
    }
    if (array_key_exists('strip_values', $changes)) {
      $changes['strip_values'] = $this->stripValuesFlag($changes);
    }
    $adopt = $this->adoptList($operation);
    unset($changes['adopt_variants']);
    if (array_key_exists('icon', $changes) && $changes['icon'] !== NULL && !is_string($changes['icon'])) {
      throw new MaterialGestureException([
        new BlueprintError('icon', 'INVALID_PARAMETER', 'icon must be a media reference string or null.', [], 'Pass an existing media item as "media:<uuid>", null to clear the icon, or omit the key to leave it unchanged.'),
      ]);
    }
    $warnings = [];
    if ($changes !== []) {
      $warnings = $this->manager()->update($blueprint, $changes, $host)->warnings;
    }
    if ($adopt !== []) {
      $this->manager()->adoptVariants($blueprint, $adopt);
    }
    return new MaterialGestureResult(MaterialKind::Blueprint, $blueprint->getMachineName(), $warnings, $blueprint);
  }

  /**
   * {@inheritdoc}
   *
   * Remove_material {kind: blueprint, id}: an ordinary entity delete (no
   * bespoke path to keep in step): BlueprintCacheHooks invalidates
   * discovery and records the removal on the materials delta, and the
   * palette entry disappears with the entity. Placements are copies, so
   * nothing references a blueprint and nothing is rewritten first.
   */
  public function remove(MaterialKind $kind, string $id, array $options): MaterialGestureResult {
    $blueprint = $this->manager()->resolveUpdatable($id);
    $this->requireEntityAccess($blueprint, 'delete');
    $machine_name = $blueprint->getMachineName();
    $blueprint->delete();
    return new MaterialGestureResult(MaterialKind::Blueprint, $machine_name);
  }

  /**
   * The type a capture mints: the `type` key, or `section` when absent.
   *
   * Identity, named at birth and never updated (update_material has no
   * type key). Must name a blueprint type the site has.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function typeOf(array $operation): string {
    $type = $operation['type'] ?? BlueprintInterface::TYPE_SECTION;
    $types = array_keys($this->entityTypeManager->getStorage(BlueprintTypeInterface::ENTITY_TYPE_ID)->loadMultiple());
    if (!is_string($type) || !in_array($type, $types, TRUE)) {
      throw new MaterialGestureException([
        new BlueprintError('type', 'INVALID_PARAMETER', sprintf('%s is not a blueprint type on this site.', is_scalar($type) ? sprintf('"%s"', $type) : 'The type'), ['types' => $types], sprintf('Name one of the site\'s blueprint types (%s), or omit the key to save a "%s" blueprint.', implode(', ', $types), BlueprintInterface::TYPE_SECTION)),
      ]);
    }
    return $type;
  }

  /**
   * The create gate on capture: the blueprint entity's own access handler.
   *
   * No entity exists yet to ask, so the handler's create access for the
   * capture's type answers (the type's `create {type} blueprint`
   * permission); update and remove ask the saved entity
   * (requireEntityAccess()). One authority on blueprint mutation, and the
   * same door the admin listing's add page uses.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function requirePermission(string $type): void {
    if (!$this->entityTypeManager->getAccessControlHandler(BlueprintInterface::ENTITY_TYPE_ID)->createAccess($type, $this->currentUser)) {
      throw new MaterialGestureException([
        new BlueprintError('', 'PERMISSION_DENIED', sprintf('Saving a %s blueprint requires the "%s" permission.', $type, BlueprintPermissions::permission($type, 'create')), ['type' => $type], 'Ask an administrator for the permission, save the blueprint as a type you may create, or place elements without saving them as blueprints.'),
      ]);
    }
  }

  /**
   * The `strip_values` capture option, FALSE when absent.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function stripValuesFlag(array $operation): bool {
    $strip = $operation['strip_values'] ?? FALSE;
    if (!is_bool($strip)) {
      throw new MaterialGestureException([
        new BlueprintError('strip_values', 'INVALID_PARAMETER', 'strip_values must be true or false.', [], 'Pass "strip_values": true to save the structure alone (every inline block\'s fields sample-populate at placement), or omit the key to keep the content.'),
      ]);
    }
    return $strip;
  }

  /**
   * The blueprint entity's own access handler, the single mutation authority.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function requireEntityAccess(BlueprintInterface $blueprint, string $operation): void {
    if (!$blueprint->access($operation)) {
      throw new MaterialGestureException([
        new BlueprintError('id', 'BLUEPRINT_FORBIDDEN', sprintf('You do not have permission to %s this %s blueprint.', $operation, $blueprint->getType()), ['type' => $blueprint->getType()], sprintf('This needs the "%s" permission.', BlueprintPermissions::permission($blueprint->getType(), $operation))),
      ]);
    }
  }

  /**
   * The element a capture reads, with the page it lives on.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function requireElement(array $operation, ?HostInterface $host): string {
    $element = $operation['element'] ?? NULL;
    if (!is_string($element) || $element === '') {
      throw new MaterialGestureException([
        new BlueprintError('element', 'MISSING_PARAMETER', 'A non-empty element reference is required.', [], 'Name the placed section or inline block to capture: its id in the page print, or the alias you gave it.'),
      ]);
    }
    if ($host === NULL) {
      throw new MaterialGestureException([
        new BlueprintError('element', 'MISSING_PARAMETER', 'A blueprint captures an element of a page; this lane has no page behind it.', [], 'Run the gesture as a page_edit op on the page the element sits on.'),
      ]);
    }
    return $element;
  }

  /**
   * The `global` opt-out: TRUE mints a blueprint that belongs to no system.
   *
   * The same spelling daedalus_stylish's capture ops read: absent means the mint
   * joins the active design system.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function globalOptOut(array $operation): bool {
    $global = $operation['global'] ?? FALSE;
    if (!is_bool($global)) {
      throw new MaterialGestureException([
        new BlueprintError('global', 'INVALID_PARAMETER', 'global must be true or false.', [], 'Pass "global": true to save a blueprint that belongs to no design system (offered under every one), or omit the key to join the active design system.'),
      ]);
    }
    return $global;
  }

  /**
   * The AI opt-out an operation carries, or NULL when it names none.
   *
   * The same spelling daedalus_stylish's material ops read: "offered": false keeps
   * the blueprint for the palette only (the roster's offered view omits it;
   * legality never reads it); true, or an absent key on a save, shares
   * it, the default every material has (MaterialRecord::$offered). An
   * update passes NULL through to leave the stored flag alone.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   *   When the key is present and not a boolean.
   */
  protected function offeredFlag(array $operation): ?bool {
    if (!array_key_exists('offered', $operation)) {
      return NULL;
    }
    if (!is_bool($operation['offered'])) {
      throw new MaterialGestureException([
        new BlueprintError('offered', 'INVALID_PARAMETER', 'offered must be true or false.', [], 'Pass "offered": false to keep the blueprint out of the AI\'s roster (the palette still places it), or omit the key to share it.'),
      ]);
    }
    return $operation['offered'];
  }

  /**
   * The validated adopt_variants list, [] when absent.
   *
   * @return string[]
   *   The variant references.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function adoptList(array $operation): array {
    $adopt = $operation['adopt_variants'] ?? [];
    if (!is_array($adopt) || array_filter($adopt, static fn ($reference) => !is_string($reference) || $reference === '') !== []) {
      throw new MaterialGestureException([
        new BlueprintError('adopt_variants', 'INVALID_PARAMETER', 'adopt_variants must be a list of variant references.', [], 'Pass machine names (or uuids) of style variants to make the blueprint\'s own, or omit the key.'),
      ]);
    }
    return array_values($adopt);
  }

}
