<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Materials;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_blueprint\IconResolver;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialGestureResult;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsProviderInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint_stencil\StencilDiscovery;
use Drupal\daedalus_blueprint_stencil\StencilDiscoveryInterface;
use Drupal\daedalus_blueprint_stencil\StencilElementTree;
use Drupal\daedalus_blueprint_stencil\StencilInterface;
use Drupal\daedalus_blueprint_stencil\StencilManager;
use Drupal\daedalus_blueprint_stencil\StencilOperationHandler;

/**
 * The stencils as roster records, and the stencil gestures.
 *
 * The ONE place stencils are read into material records. Discovery
 * already serves the published set across both sources (module-shipped
 * files and published content stencils) in one flat id namespace, so a
 * record is a straight projection of a definition: the label and
 * description (the palette face and the AI's when-to-reach-for-this
 * text), the icon RESOLVED to a URL (the catalog carries the icon value
 * grammar; the client palette paints the record and cannot resolve a
 * media reference, so the provider resolves once through the grammar's
 * one seam and the media's tags ride the roster), the root kind (section
 * for a container-rooted subtree, block for a bare block: what a
 * placement lands as), updatable for content stencils only (a file
 * stencil has no entity to update, under the developer gate too: drush
 * stencil-ship from a saved placement is its lane, so the record stays
 * `updatable: !$shipped` whatever the distro lock says), origin shipped
 * for file stencils and the
 * content stencil's own origin field (user or ai, stamped by the lane
 * that minted it), and the membership discovery resolved (a design system
 * id or NULL for global; the roster partitions on it, this provider
 * filters nothing), and the root's captured wearings (the variant ids in
 * the root node's own styles entry, MaterialRecord::APPLIES_WEARS): a
 * capture fact only, since this module knows no axis; the roster derives
 * "page band" from it against the variant records it holds
 * (MaterialsRoster::isBand()), the element tree in the printer's
 * shape (StencilElementTree::dialectNodes(): what the schema prints as
 * the stencil's page-language fragment, and page_read on demand), and
 * the AI opt-out discovery resolved (`offered`, from the file's
 * `ai: false` or the content stencil's own field: the roster's offered
 * view omits a withheld stencil, so it leaves the brief and the schema
 * while the palette still places it). The stencil slot spelling is the
 * id itself: <pb-stencil name="id">.
 *
 * The stencil gestures (save_material / update_material / remove_material
 * with kind stencil) dispatch here from the op spine; the palette's Save
 * as stencil and Update stencil verbs submit the same ops, and the
 * details and delete routes (StencilApiController) are adapters over the
 * same methods. Capture is a SPINE OP so the AI can compose a subtree with
 * ordinary operations and save it as a stencil in the SAME batch: the
 * capture reads the batch's live entity, exactly what the preceding
 * operations staged. Every verb needs the same "save stencils" permission
 * the UI always required (capability parity never means validation
 * exemption): stencils are site-wide content, a stricter surface than the
 * staged-entity mutations the batch permission covers, and the entity's
 * own access handler stays the single authority on update and delete.
 */
final class StencilMaterialsProvider implements MaterialsProviderInterface {

  /**
   * The keys update_material may change on a stencil.
   */
  private const UPDATE_KEYS = ['element', 'label', 'description', 'icon', 'offered', 'adopt_variants'];

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
    protected StencilDiscoveryInterface $discovery,
    protected IconResolver $iconResolver,
    protected \Closure $manager,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * The stencil manager, the one implementation behind every verb.
   */
  protected function manager(): StencilManager {
    return ($this->manager)();
  }

  /**
   * {@inheritdoc}
   */
  public function records(): array {
    $records = [];
    $this->iconCacheTags = [];
    foreach ($this->discovery->getStencils() as $id => $stencil) {
      $shipped = $stencil['source'] === StencilDiscoveryInterface::SOURCE_MODULE;
      // NULL (unset, or a media reference that no longer resolves to a
      // file) means "the consumer's default icon"; the media's tags merge
      // even then, so the media's edits invalidate every projection of
      // the roster that decided "default" from its state.
      $icon = $this->iconResolver->resolve($stencil['icon'] ?? NULL, $this->iconCacheTags);
      $wears = StencilElementTree::wears($stencil['element']);
      $records[] = new MaterialRecord(
        kind: MaterialKind::Stencil,
        id: (string) $id,
        label: (string) $stencil['label'],
        description: (string) $stencil['description'],
        spellings: [MaterialRecord::SLOT_STENCIL => (string) $id],
        applies: $wears === [] ? [] : [MaterialRecord::APPLIES_WEARS => $wears],
        icon: $icon,
        rootKind: StencilOperationHandler::isContainerNode($stencil['element']) ? MaterialRecord::ROOT_SECTION : MaterialRecord::ROOT_BLOCK,
        origin: $shipped ? MaterialRecord::ORIGIN_SHIPPED : (string) ($stencil['origin'] ?? MaterialRecord::ORIGIN_USER),
        updatable: !$shipped,
        designSystem: $stencil['design_system'],
        offered: $stencil['offered'],
        tree: StencilElementTree::dialectNodes($stencil['element']),
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
    // The single "the stencil vocabulary changed" signal: file changes
    // through cache rebuilds, content CRUD through StencilCacheHooks.
    return (new CacheableMetadata())->addCacheTags([StencilDiscovery::CACHE_TAG, ...$this->iconCacheTags]);
  }

  /**
   * {@inheritdoc}
   *
   * The one place the capture and update keys are taught (the brief's
   * MINT line renders it): the capture keys, then the update gesture
   * with the keys update() accepts, derived from UPDATE_KEYS so the line
   * cannot drift from what the gesture takes. The placement rules are
   * the cached stencil sentence's (DialectCatalogPrompt), not this
   * line's.
   */
  public function gestureLines(): array {
    return [
      MaterialKind::Stencil->value => sprintf('element (the composed root, same batch), description, icon? ("media:<uuid>" of an existing item), adopt_variants? (style variants that become its own curated looks, worn only inside its placements), offered? (false keeps it out of your roster, for the palette only); update_material {kind: stencil, id} takes any of %s (element pushes a fresh capture; placements are independent copies and never re-sync)', implode(', ', self::UPDATE_KEYS)),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Save_material {kind: stencil, element, label, description, icon?,
   * adopt_variants?, global?}: mints a stencil from the element's subtree.
   * The icon is an EXISTING media item in the icon value grammar's media
   * spelling; adopt_variants lists style variants that become the
   * stencil's own curated looks (adoption runs AFTER the save, so an
   * adoption failure follows the op's documented side-effect semantics:
   * the batch fails but the saved stencil remains, one delete away);
   * `global` opts the stencil out of the active design system (the
   * membership rule every capture shares, see globalOptOut()); `offered`
   * is the AI opt-out (offeredFlag(): false keeps the stencil for the
   * palette only).
   */
  public function capture(MaterialKind $kind, array $operation, ?FieldableEntityInterface $entity, array $options): MaterialGestureResult {
    $this->requirePermission();
    $element = $this->requireElement($operation, $entity);
    $adopt = $this->adoptList($operation);
    $global = $this->globalOptOut($operation);
    $icon = $operation['icon'] ?? NULL;
    if ($icon !== NULL && !is_string($icon)) {
      throw new MaterialGestureException([
        new BlueprintError('icon', 'INVALID_PARAMETER', 'icon must be a media reference string.', [], 'Pass an existing media item as "media:<uuid>", or omit the key for the default stencil icon.'),
      ]);
    }
    $result = $this->manager()->saveAsStencil(
      $entity,
      $element,
      is_scalar($operation['label'] ?? NULL) ? (string) $operation['label'] : '',
      is_scalar($operation['description'] ?? NULL) ? (string) $operation['description'] : '',
      $icon,
      $options[MaterialGestureOperationHandler::ORIGIN_OPTION] ?? MaterialRecord::ORIGIN_USER,
      $global,
      $this->offeredFlag($operation) ?? TRUE,
    );
    if ($adopt !== []) {
      $this->manager()->adoptVariants($result->stencil, $adopt);
    }
    return new MaterialGestureResult(MaterialKind::Stencil, $result->stencil->getMachineName(), $result->warnings, $result->stencil);
  }

  /**
   * {@inheritdoc}
   *
   * Update_material {kind: stencil, id, element?, label?, description?, icon?,
   * offered?, adopt_variants?}: one revisioned write of whatever is present (a
   * bad update is one revert away, workspace-staged inside a workspace).
   * `element` pushes a fresh capture of the subtree (future placements stamp
   * it; existing placements never re-sync); label, description and icon rewrite
   * the palette face (icon PRESENT writes, NULL or '' clearing back to the
   * default; ABSENT leaves the stored icon standing); offered flips the AI
   * opt-out (offeredFlag()). Only content stencils update: file-shipped
   * stencils refuse with their own error, never a bare not-found.
   */
  public function update(MaterialKind $kind, string $id, array $operation, ?FieldableEntityInterface $entity, array $options): MaterialGestureResult {
    $stencil = $this->manager()->resolveUpdatable($id);
    $this->requireEntityAccess($stencil, 'update');
    $changes = array_intersect_key($operation, array_flip(self::UPDATE_KEYS));
    if ($changes === []) {
      throw new MaterialGestureException([
        new BlueprintError('', 'MISSING_PARAMETER', 'update_material changes nothing: pass at least one key.', ['keys' => self::UPDATE_KEYS], 'Pass "element" to push a fresh capture, "label" / "description" / "icon" to rewrite the palette face, "offered" to flip the AI opt-out, or "adopt_variants" to curate looks.'),
      ]);
    }
    if (array_key_exists('element', $changes)) {
      $changes['element'] = $this->requireElement($operation, $entity);
    }
    if (array_key_exists('offered', $changes)) {
      $changes['offered'] = $this->offeredFlag($changes);
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
      $warnings = $this->manager()->update($stencil, $changes, $entity)->warnings;
    }
    if ($adopt !== []) {
      $this->manager()->adoptVariants($stencil, $adopt);
    }
    return new MaterialGestureResult(MaterialKind::Stencil, $stencil->getMachineName(), $warnings, $stencil);
  }

  /**
   * {@inheritdoc}
   *
   * Remove_material {kind: stencil, id}: an ordinary entity delete (no
   * bespoke path to keep in step): StencilCacheHooks invalidates
   * discovery and records the removal on the materials delta, and the
   * palette entry disappears with the entity. Placements are copies, so
   * nothing references a stencil and nothing is rewritten first.
   */
  public function remove(MaterialKind $kind, string $id, array $options): MaterialGestureResult {
    $stencil = $this->manager()->resolveUpdatable($id);
    $this->requireEntityAccess($stencil, 'delete');
    $machine_name = $stencil->getMachineName();
    $stencil->delete();
    return new MaterialGestureResult(MaterialKind::Stencil, $machine_name);
  }

  /**
   * The "save stencils" gate on capture (no entity exists yet to ask).
   *
   * Update and remove ask the stencil entity's own access handler instead
   * (requireEntityAccess()), the single authority on stencil mutation.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function requirePermission(): void {
    if (!$this->currentUser->hasPermission('save stencils')) {
      throw new MaterialGestureException([
        new BlueprintError('', 'PERMISSION_DENIED', 'Saving stencils requires the "save stencils" permission.', [], 'Ask an administrator for the permission, or place elements without saving them as stencils.'),
      ]);
    }
  }

  /**
   * The stencil entity's own access handler, the single mutation authority.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function requireEntityAccess(StencilInterface $stencil, string $operation): void {
    if (!$stencil->access($operation)) {
      throw new MaterialGestureException([
        new BlueprintError('id', 'STENCIL_FORBIDDEN', sprintf('You do not have permission to %s this stencil.', $operation), [], 'Stencil mutations require the "save stencils" permission.'),
      ]);
    }
  }

  /**
   * The element a capture reads, with the page it lives on.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   */
  protected function requireElement(array $operation, ?FieldableEntityInterface $entity): string {
    $element = $operation['element'] ?? NULL;
    if (!is_string($element) || $element === '') {
      throw new MaterialGestureException([
        new BlueprintError('element', 'MISSING_PARAMETER', 'A non-empty element reference is required.', [], 'Name the placed section or inline block to capture: its id in the page print, or the alias you gave it.'),
      ]);
    }
    if ($entity === NULL) {
      throw new MaterialGestureException([
        new BlueprintError('element', 'MISSING_PARAMETER', 'A stencil captures an element of a page; this lane has no page behind it.', [], 'Run the gesture as a page_edit op on the page the element sits on.'),
      ]);
    }
    return $element;
  }

  /**
   * The `global` opt-out: TRUE mints a stencil that belongs to no system.
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
        new BlueprintError('global', 'INVALID_PARAMETER', 'global must be true or false.', [], 'Pass "global": true to save a stencil that belongs to no design system (offered under every one), or omit the key to join the active design system.'),
      ]);
    }
    return $global;
  }

  /**
   * The AI opt-out an operation carries, or NULL when it names none.
   *
   * The same spelling daedalus_stylish's material ops read: "offered": false keeps
   * the stencil for the palette only (the roster's offered view omits it;
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
        new BlueprintError('offered', 'INVALID_PARAMETER', 'offered must be true or false.', [], 'Pass "offered": false to keep the stencil out of the AI\'s roster (the palette still places it), or omit the key to share it.'),
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
        new BlueprintError('adopt_variants', 'INVALID_PARAMETER', 'adopt_variants must be a list of variant references.', [], 'Pass machine names (or uuids) of style variants to make the stencil\'s own, or omit the key.'),
      ]);
    }
    return array_values($adopt);
  }

}
