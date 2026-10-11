<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\Requirement;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint\Dialect\DialectParser;
use Drupal\daedalus_blueprint\Dialect\PageLanguageOperationsInterface;
use Drupal\daedalus_blueprint\Dialect\PageOnlyOperationsInterface;
use Drupal\daedalus_blueprint\ElementLocator;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\InnerRender;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Materials\BlueprintRequirements;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\layout_builder\SectionComponent;

/**
 * Registers the place_blueprint and detach_blueprint batch operations.
 *
 * Placement has two modes (`mode`, MODE_DETACHED the default). Detached
 * STAMPS a blueprint: the definition's layout (the lean node
 * tree discovery serves for both lanes, a file's authored roots or a
 * content blueprint's built document) goes through the spine's one
 * placement machinery (DocumentInstantiator), which expands it into the
 * batch operations that already exist and runs them as a nested dressed
 * batch, so a blueprint placement IS a normal palette placement, just
 * scripted by a definition. Linked LINKS a saved blueprint: one node
 * (`{kind: linked, blueprint}`, the daedalus_blueprint_linked block) that
 * renders the blueprint's document live under the host's contexts and
 * follows every later edit of the blueprint; it goes through the same
 * instantiator as a one-node tree, carries no provenance, and is refused
 * for a shipped file (BLUEPRINT_NOT_LINKABLE), which has no live document.
 * Detach (`detach_blueprint {element}`) is the road from the second mode
 * to the first: the linked node becomes, in its own place, the copy a
 * detached placement would have made (the instantiator's in-place
 * expansion: the blueprint's tree lands after the node with fresh identity
 * and the provenance stamp, the node is removed, one dressed batch), so
 * from then on the page owns the composition and the capture and push-back
 * gestures (save_material, update_material) work on it as on any detached
 * placement. The node's own box styles are not carried: the copy's root is
 * the blueprint's root with the blueprint's styles. Detach has a second
 * spelling, `detach_blueprint {slot}`, the CLAIM: a slot block around the
 * host (a template's, the site frame's) shows its default blueprint by
 * reference until a document beneath it fills the slot; claiming detaches
 * that default into the host's own slot section (the copy, with
 * provenance, tagged with the slot id, landed through the slot door like
 * any placement anchored inside it), so this page (or this template, for
 * every page of its bundle) owns what the slot shows from then on while
 * every other page keeps the default. Releasing is removing the slot
 * section (remove_element): the slot shows its default again. The
 * instantiator owns the tree grammar and its
 * correctives; this handler owns what is the blueprint's: the definition
 * lookup, the placement's root uuid, the provenance stamp, sample-populated
 * absent fields, and the blueprint coordinates every error carries.
 *
 * The placed root carries the provenance stamp from birth: the source
 * blueprint ID rides the root create op (add_section's blueprint parameter;
 * roots are always containers), so the stamp exists the moment the root
 * does and the trailing style ops (a blueprint's own owned-variant applies)
 * already see it. A placement never re-syncs from its blueprint, and a
 * stamp whose blueprint was deleted or is file-shipped simply resolves to
 * nothing. The capture lane strips every stamp from the tree it reads, so
 * the stamp cannot leak into a saved blueprint (a blueprint is not a
 * placement).
 *
 * Where a placement may land is the materials roster's band rule (a
 * block-scope blueprint whose root wears a root-only variant lands at the
 * top level only), judged by the page language before the op and by the
 * root's own apply_variant inside the expansion. Whether a blueprint fits
 * the host at all is the spine's fit judgement (BlueprintRequirements:
 * the subject's fields, the block types, plugins, layouts and variants
 * the tree needs, the allow-list), refused here as BLUEPRINT_DOES_NOT_FIT
 * naming what is missing before anything is placed; a saved blueprint
 * not yet holding a root is refused first as BLUEPRINT_EMPTY naming its
 * canvas, since a placement that lands nothing is no placement; and a
 * page blueprint is refused before either as BLUEPRINT_WRONG_SCOPE, since
 * a whole page is a new page's start (page_create's start, the spine's
 * PageStart) and never a placement into one, nor a slot's default: a claim
 * whose door defaults to a page blueprint is refused with the same code,
 * since the claim copies one container into the host's one slot section
 * and a page is any number of roots. The canvas offer
 * (CanvasOffer) keeps the Add panel's roster to what the refusals would
 * let through. Discovery validated the
 * definition envelope; the instantiator validates the whole tree before
 * executing anything, and batch rollback makes a failed placement leave
 * the host untouched.
 */
class BlueprintOperationHandler implements PageOnlyOperationsInterface, PageLanguageOperationsInterface {

  /**
   * The operations.
   *
   * Placing is the page language's business (its <pb-blueprint> and
   * <pb-linked> elements supersede the op there: page-only); detaching has
   * no element spelling, so the page language admits the op itself, its
   * `element` a ref the page print spells (a uuid or a live alias).
   */
  public const PLACE = 'place_blueprint';
  public const DETACH = 'detach_blueprint';

  /**
   * The placement modes: a copy (the default) or a live reference.
   *
   * Detached stamps the blueprint's tree into the host with fresh
   * identity and provenance; linked lands one node
   * (daedalus_blueprint_linked:{machine_name}, the lean tree's
   * `{kind: linked}`) that renders the blueprint's document live and
   * follows every later edit of it. Only a saved ENTITY can be linked (a
   * shipped file has no live document), and the fit is judged for both
   * the same way: the blueprint's needs against the host (the op door's
   * linked fit gate judges a linked node for every road into the
   * document, this handler being one of them).
   */
  public const MODE_DETACHED = 'detached';
  public const MODE_LINKED = 'linked';
  public const MODES = [self::MODE_DETACHED, self::MODE_LINKED];

  /**
   * The key carrying a placement's provenance stamp.
   *
   * Aliases the base LayoutSerializer constant: base owns the key name so
   * the serialization spine can surface the stamp as the element's
   * read-only `blueprint` key without depending on this optional submodule.
   */
  public const PROVENANCE_KEY = LayoutSerializer::BLUEPRINT_PROVENANCE_KEY;

  /**
   * The code of a blueprint definition's grammar error.
   */
  public const GRAMMAR_CODE = 'INVALID_BLUEPRINT';

  /**
   * Constructs the handler.
   *
   * @param \Drupal\daedalus_blueprint_library\BlueprintDiscoveryInterface $discovery
   *   The blueprint discovery.
   * @param \Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator $instantiator
   *   The one placement machinery.
   * @param \Drupal\daedalus_blueprint\Materials\BlueprintRequirements $requirements
   *   The fit judgement.
   * @param \Drupal\daedalus_blueprint\ElementLocator $elementLocator
   *   The element locator: the component a detach names.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectAliasMap $aliasMap
   *   The alias map: a detach's element may be a page-language alias.
   * @param \Drupal\daedalus_blueprint\Host\InnerRender $innerRender
   *   The render primitive: the slot door a claim names, above the host.
   */
  public function __construct(
    protected BlueprintDiscoveryInterface $discovery,
    protected DocumentInstantiator $instantiator,
    protected BlueprintRequirements $requirements,
    protected ElementLocator $elementLocator,
    protected DialectAliasMap $aliasMap,
    protected InnerRender $innerRender,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getOperations(): array {
    return [self::PLACE, self::DETACH];
  }

  /**
   * {@inheritdoc}
   *
   * A blueprint lands somewhere: insert on the destination its location
   * resolves to. A detach rearranges the element it names (the copy takes
   * the node's place; the node goes). The spine ops either expands into
   * are judged again in their nested batch, element by element (the
   * copy's insert beside the node included). A claim names no element
   * (the requirement stays unresolved and is not asked); its gate is the
   * nested batch's insert on the slot door the copy lands through.
   */
  public function getRequirements(): array {
    return [
      self::PLACE => [Requirement::destination()],
      self::DETACH => [Requirement::element(Capability::Arrange)],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Placement targets the layout tree, and the page language stamps a
   * blueprint as <pb-blueprint> (or links one as <pb-linked>) inside an
   * insert fragment, so on a page-language site the JSON spelling leaves
   * eb_edit's roll-call.
   */
  public function getPageOnlyOperations(): array {
    return [self::PLACE];
  }

  /**
   * {@inheritdoc}
   *
   * A detach has no element spelling: page_edit admits the op, its
   * element a ref the page print spells.
   */
  public function getPageLanguageOperations(): array {
    return [self::DETACH];
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): array {
    return [
      self::PLACE => 'with "blueprint" ID from the schema\'s materials.blueprints, "location" anchor object, optional root "uuid", optional "mode" (detached, the default: a fresh independent copy, the page language\'s <pb-blueprint> element; linked: one node showing the saved blueprint live and following its later edits, the page language\'s <pb-linked> element)',
      self::DETACH => 'with "element" the ref of a <pb-linked> node: replaces it, in place, with an independent copy of the blueprint it shows (the page owns the copy from then on; the blueprint\'s later edits no longer reach it; save_material and update_material work on the copy); or with "slot" the id of a slot block around this page (the template\'s, the site frame\'s) that shows a default blueprint: claims the slot, landing an independent copy of that default as this page\'s own <pb-slot> section, so only this page shows the copy. Release a claimed slot with remove_element on its <pb-slot> section',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function apply(HostInterface $host, array $operation, array $options = []): BlueprintResult {
    if (($operation['op'] ?? NULL) === self::DETACH) {
      return array_key_exists('slot', $operation) && !array_key_exists('element', $operation)
        ? $this->claim($host, $operation)
        : $this->detach($host, $operation, $options);
    }
    $id = $operation['blueprint'] ?? NULL;
    if (!is_string($id) || $id === '') {
      return BlueprintResult::structuralFailure([
        new BlueprintError('blueprint', 'MISSING_PARAMETER', 'A non-empty blueprint is required.', [], 'Provide the ID of the blueprint to place.'),
      ]);
    }
    $mode = $operation['mode'] ?? self::MODE_DETACHED;
    if (!in_array($mode, self::MODES, TRUE)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('mode', 'INVALID_PARAMETER', sprintf('"%s" is not a placement mode.', is_scalar($mode) ? (string) $mode : gettype($mode)), ['modes' => self::MODES], 'mode is detached (a fresh independent copy, the default) or linked (the blueprint shown by reference, following its later edits).'),
      ]);
    }
    $definition = $this->discovery->getBlueprint($id);
    if ($definition === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('blueprint', 'BLUEPRINT_NOT_FOUND', sprintf('"%s" is not an available blueprint.', $id), [
          'available' => array_keys($this->discovery->getBlueprints()),
        ], 'Use one of the available blueprint IDs.'),
      ]);
    }
    $provider = $definition['provider'];

    // A page blueprint is a whole page's start, never a placement: scopes
    // do not mix on a door any more than on an offering surface. Refused
    // after the lookup and before anything else is judged (an empty page
    // blueprint is still the start's to refuse), with one code for a scope
    // mismatch in either direction (the start answers BLUEPRINT_WRONG_SCOPE
    // for a block blueprint) and the corrective naming the start's door.
    if ($definition['scope'] === BlueprintInterface::SCOPE_PAGE) {
      return BlueprintResult::semanticFailure([
        new BlueprintError('blueprint', 'BLUEPRINT_WRONG_SCOPE', sprintf('Blueprint "%s" is a page blueprint: a whole page, never a placement into one.', $id), [
          'blueprint' => $id,
          'provider' => $provider,
          'scope' => BlueprintInterface::SCOPE_PAGE,
        ], sprintf('A page blueprint starts a new page: pass it as page_create\'s start (start: %s), with the page\'s fields and no layout. To place something into this page, place a block blueprint (the schema\'s materials.blueprints) or compose the element here.', $id)),
      ]);
    }

    $refused = $this->emptyRefusal($id, $definition);
    if ($refused !== NULL) {
      return $refused;
    }

    // A linked placement shows the saved entity's document live: a shipped
    // file has none to show, so it is refused before the fit, which the
    // op door judges for the linked node itself (every road into the
    // document passes that one gate, this one included).
    if ($mode === self::MODE_LINKED && $provider !== NULL) {
      return BlueprintResult::semanticFailure([
        new BlueprintError('mode', 'BLUEPRINT_NOT_LINKABLE', sprintf('Blueprint "%s" is shipped by the "%s" module as a file: it has no live document to show by reference.', $id, $provider), [
          'blueprint' => $id,
          'provider' => $provider,
        ], 'Place it detached (a copy to adapt), or link a blueprint saved on this site.'),
      ]);
    }

    $refused = $this->fitRefusal($id, $definition, $host);
    if ($refused !== NULL) {
      return $refused;
    }

    $location = $operation['location'] ?? [];
    if (!is_array($location)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('location', 'INVALID_LOCATION', 'The location is not a location object.', [], 'A placement takes the ONE placement grammar: {anchor, placement: before|after|inside} with region only for multi-region layouts.'),
      ]);
    }

    // Creates honor client-generated UUIDs (the batch grammar's optimistic
    // seam); a placement is a create whose addressable element is the root
    // container, so an operation-level uuid names that root. Nested UUIDs
    // stay minted: the tree's internals are not addressable until the
    // response reports them.
    $root_uuid = $operation['uuid'] ?? NULL;
    if ($root_uuid !== NULL && (!is_string($root_uuid) || !Uuid::isValid($root_uuid))) {
      return BlueprintResult::structuralFailure([
        BlueprintError::invalidUuid('uuid', $root_uuid),
      ]);
    }

    // The definition's layout (its root containers; a content blueprint's
    // nodes carry the saved document's uuids, which the instantiator maps
    // to fresh identity), placed: absent block fields sample-populate
    // through the same populate: 'sample' path a palette drop uses; the
    // root op's own error paths (location.*) point at THIS operation's
    // parameter space, so the root keeps no prefix.
    // Linked: one node, no provenance (the node names the blueprint it
    // shows; a stamp records where a copy came from, and nothing is
    // copied). The same placement grammar, the same uuid seam.
    $nodes = $mode === self::MODE_LINKED
      ? [['kind' => LinkedBlueprintBlock::KIND, LinkedBlueprintBlock::BLUEPRINT_KEY => $id]]
      : $definition['layout'];
    $result = $this->instantiator->instantiate($host, $nodes, $location, [
      'root_uuid' => $root_uuid,
      'sample_fields' => TRUE,
      'path' => 'layout',
      'root_path' => '',
      'grammar_code' => self::GRAMMAR_CODE,
    ] + ($mode === self::MODE_LINKED ? [] : ['provenance' => $id]));
    if ($result->isSuccess()) {
      return $result;
    }

    return $this->decorated($result, $id, $provider);
  }

  /**
   * Detaches a linked placement: the node becomes the copy it showed.
   *
   * `detach_blueprint {element}`: the element is a linked node
   * (daedalus_blueprint_linked:{machine_name}; anything else is refused
   * BLUEPRINT_NOT_LINKED, an element the document lacks ELEMENT_NOT_FOUND),
   * naming a blueprint the site serves (an unpublished or deleted one is
   * BLUEPRINT_NOT_FOUND: the node shows nothing and has nothing to copy),
   * holding a root (BLUEPRINT_EMPTY) and fitting the host (BLUEPRINT_
   * DOES_NOT_FIT, judged as a placement's is: the blueprint may have grown
   * a need since the node was placed). The instantiator then expands the
   * blueprint's tree in the node's place (DocumentInstantiator::replace():
   * after the node, in its flow, fresh identity, the provenance stamp,
   * sample-populated absent fields as a detached placement has them) and
   * removes the node, one dressed batch. The op door judged arrange on the
   * node before this ran; the nested batch judges the copy's insert and
   * the node's removal again.
   */
  protected function detach(HostInterface $host, array $operation, array $options): BlueprintResult {
    $element = $operation['element'] ?? NULL;
    if (!is_string($element) || $element === '') {
      return BlueprintResult::structuralFailure([
        new BlueprintError('element', 'MISSING_PARAMETER', 'A non-empty element reference is required.', [], 'Name the linked node to detach: its id in the page print, or the alias you gave it.'),
      ]);
    }
    if (!DialectParser::isUuid($element)) {
      $resolved = $this->aliasMap->resolve($host, (string) ($options['conversation'] ?? ''), $element);
      if ($resolved === NULL) {
        return BlueprintResult::structuralFailure([
          new BlueprintError('element', 'UNKNOWN_REF', sprintf('"%s" is not a live alias or uuid.', $element), [], 'Refs are the ids in the page print (uuids or data-alias values); aliases are minted by inserting elements with ids.'),
        ]);
      }
      $element = $resolved;
    }

    $context = $this->elementLocator->locate($host, $element);
    if ($context === NULL) {
      return BlueprintResult::semanticFailure([
        new BlueprintError('element', 'ELEMENT_NOT_FOUND', sprintf('No element "%s" exists on %s %s.', $element, $host->entityTypeId(), $host->id() ?? $host->uuid()), [], 'Detach a linked node by its id in the page print.'),
      ]);
    }
    $name = $context->isLbComponent() && $context->target instanceof SectionComponent
      ? LinkedBlueprintBlock::blueprintOf($context->target->getPluginId())
      : NULL;
    if ($name === NULL) {
      return BlueprintResult::semanticFailure([
        new BlueprintError('element', 'BLUEPRINT_NOT_LINKED', sprintf('"%s" is not a linked blueprint placement: only a node showing a blueprint by reference detaches.', $element), [], 'Name a linked node (the page print\'s <pb-linked>); a detached placement already is its own copy.'),
      ]);
    }

    $definition = $this->discovery->getBlueprint($name);
    if ($definition === NULL) {
      return BlueprintResult::semanticFailure([
        new BlueprintError('element', 'BLUEPRINT_NOT_FOUND', sprintf('The node links blueprint "%s", which this site no longer serves (unpublished or deleted): it shows nothing and there is nothing to copy.', $name), [
          'blueprint' => $name,
          'available' => array_keys($this->discovery->getBlueprints()),
        ], 'Publish the blueprint again and detach, or remove the node.'),
      ]);
    }
    $provider = $definition['provider'];
    $refused = $this->emptyRefusal($name, $definition) ?? $this->fitRefusal($name, $definition, $host);
    if ($refused !== NULL) {
      return $refused;
    }

    $result = $this->instantiator->replace($host, $element, $definition['layout'], [
      'sample_fields' => TRUE,
      'path' => 'layout',
      'root_path' => '',
      'grammar_code' => self::GRAMMAR_CODE,
      'provenance' => $name,
    ]);
    if ($result->isSuccess()) {
      return $result;
    }
    return $this->decorated($result, $name, $provider);
  }

  /**
   * Claims a slot: its default becomes the host's own fill of it.
   *
   * `detach_blueprint {slot}`: the slot is one a document around the host
   * declares (InnerRender::slotDoor(): the template it renders inside or
   * the site frame; anything else is UNKNOWN_SLOT, a slot the host already
   * fills BLUEPRINT_SLOT_DUPLICATE, a door without a default
   * SLOT_NO_DEFAULT), and the default is a blueprint the site serves
   * (BLUEPRINT_NOT_FOUND otherwise), of block scope (BLUEPRINT_WRONG_SCOPE
   * for a page blueprint: a whole page of any number of roots, where the
   * claim copies one container into the host's one slot section; the
   * corrective names the slot block's configuration on the holder's
   * canvas, where the default is set), holding a root (BLUEPRINT_EMPTY) and
   * fitting the host (BLUEPRINT_DOES_NOT_FIT), judged as a placement's is.
   * The blueprint's tree then lands anchored inside the door, the one
   * landing every placement into a slot takes (the op door rewrites it
   * onto the host's own slot section, minted as the tree's root, tagged
   * with the slot id), with fresh identity, the provenance stamp and
   * sample-populated absent fields as a detached placement has them, so
   * the capture and push-back gestures work on the fill as on any detached
   * placement. The nested batch judges the insert on the door: the host's
   * own gesture (OwnershipPolicy), by the host's own permissions
   * (RolePolicy). Only a host that fills slots claims (a page, a
   * template): the frame and a blueprint have no door above them.
   */
  protected function claim(HostInterface $host, array $operation): BlueprintResult {
    $slot = $operation['slot'];
    if (!is_string($slot) || !preg_match(LayoutSerializer::SLOT_ID_PATTERN, $slot)) {
      return BlueprintResult::structuralFailure([
        new BlueprintError('slot', 'INVALID_SLOT', 'slot is a slot id: lowercase letters, digits and underscores.', ['slot' => $slot], 'Name the slot to claim: the id of a slot block around this page (the page print\'s slot blocks carry it as `slot`).'),
      ]);
    }
    $door = $this->innerRender->slotDoor($slot, $host);
    if ($door === NULL) {
      return BlueprintResult::semanticFailure([
        new BlueprintError('slot', 'UNKNOWN_SLOT', sprintf('No document around this %s declares slot "%s".', $host->entityTypeId(), $slot), ['slot' => $slot], 'Claim a slot the template around this page or the site frame places as a slot block; the page print names them.'),
      ]);
    }
    foreach ($host->ownSections() as $section) {
      if (LayoutSerializer::slotOf($section) === $slot) {
        return BlueprintResult::semanticFailure([
          new BlueprintError('slot', 'BLUEPRINT_SLOT_DUPLICATE', sprintf('Slot "%s" is already filled on this document.', $slot), [
            'slot' => $slot,
            'section' => $section->getThirdPartySetting('daedalus_layout', 'uuid'),
          ], 'The slot is claimed: edit its section, or release it (remove_element on the section) to show the default again.'),
        ]);
      }
    }
    $name = $door->default();
    if ($name === NULL) {
      return BlueprintResult::semanticFailure([
        new BlueprintError('slot', 'SLOT_NO_DEFAULT', sprintf('Slot "%s" shows no default: there is nothing to claim.', $slot), [
          'slot' => $slot,
          'owner' => ['entity_type' => $door->holder->entityTypeId(), 'entity_id' => $door->holder->id()],
        ], 'Fill the slot instead: insert inside its slot block.'),
      ]);
    }
    $definition = $this->discovery->getBlueprint($name);
    if ($definition === NULL) {
      return BlueprintResult::semanticFailure([
        new BlueprintError('slot', 'BLUEPRINT_NOT_FOUND', sprintf('Slot "%s" defaults to blueprint "%s", which this site no longer serves (unpublished or deleted): the slot shows nothing and there is nothing to claim.', $slot, $name), [
          'slot' => $slot,
          'blueprint' => $name,
          'available' => array_keys($this->discovery->getBlueprints()),
        ], 'Publish the blueprint again and claim, or fill the slot by inserting inside its slot block.'),
      ]);
    }
    $provider = $definition['provider'];
    // A slot's default is the one container the claim copies into the
    // host's one slot section; a page blueprint is a whole page of any
    // number of roots, and a claim of one would land its first root as the
    // slot section and every following root outside the slot, at the top
    // level of the host's own document. Refused with the placement's code
    // (a scope mismatch on a door is one fact), after the lookup and before
    // the empty and fit judgements, the corrective naming where the default
    // is set: the slot block's configuration on the holder's canvas.
    if ($definition['scope'] === BlueprintInterface::SCOPE_PAGE) {
      return BlueprintResult::semanticFailure([
        new BlueprintError('slot', 'BLUEPRINT_WRONG_SCOPE', sprintf('Slot "%s" defaults to blueprint "%s", a page blueprint: a whole page is never a slot\'s default, and a claim copies one container into the slot.', $slot, $name), [
          'slot' => $slot,
          'blueprint' => $name,
          'provider' => $provider,
          'scope' => BlueprintInterface::SCOPE_PAGE,
        ], sprintf('Set the slot block\'s default to a block blueprint, or clear it, in the block\'s configuration on the "%s" canvas (the page tools address it as entity_type: %s, entity_id: %s); then claim, or fill the slot by inserting inside its slot block.', $door->holder->label(), $door->holder->entityTypeId(), $door->holder->id())),
      ]);
    }
    // The claim is the frame's default reaching down into the page: the
    // chrome's frame-only rule is not asked of the page here.
    $refused = $this->emptyRefusal($name, $definition) ?? $this->fitRefusal($name, $definition, $host, claiming: TRUE);
    if ($refused !== NULL) {
      return $refused;
    }

    $result = $this->instantiator->instantiate($host, $definition['layout'], [
      'anchor' => $door->component->getUuid(),
      'placement' => 'inside',
    ], [
      'sample_fields' => TRUE,
      'path' => 'layout',
      'root_path' => '',
      'grammar_code' => self::GRAMMAR_CODE,
      'provenance' => $name,
    ]);
    if ($result->isSuccess()) {
      return $result;
    }
    return $this->decorated($result, $name, $provider);
  }

  /**
   * The refusal of a blueprint holding no root, or NULL.
   *
   * An empty blueprint places nothing, so it is refused before the fit
   * (which passes vacuously: nothing is required) and before anything is
   * staged. Only a saved blueprint can be empty (discovery refuses a file
   * whose layout holds no root): one composed from scratch through "Add
   * blueprint" and not yet holding a container. The corrective names its
   * own canvas, the page the page tools address as entity_type blueprint.
   */
  protected function emptyRefusal(string $id, array $definition): ?BlueprintResult {
    if ($definition['layout'] !== []) {
      return NULL;
    }
    $entity_id = $definition['entity_id'];
    $canvas = Url::fromRoute('entity.blueprint.canonical', ['blueprint' => $entity_id])->toString();
    return BlueprintResult::semanticFailure([
      new BlueprintError('blueprint', 'BLUEPRINT_EMPTY', sprintf('Blueprint "%s" is empty: its document holds no root container, so there is nothing to place.', $id), [
        'blueprint' => $id,
        'provider' => $definition['provider'],
      ], sprintf('Compose the blueprint first, on its own canvas at %s or through the page tools with entity_type: blueprint and entity_id: %s, then place it; or place a blueprint that holds a root.', $canvas, $entity_id)),
    ]);
  }

  /**
   * The refusal of a blueprint the host lacks the material for, or NULL.
   *
   * The fit, judged whole before anything is placed: a blueprint whose
   * subject fields, block types, plugins, layouts or variants this host
   * lacks, whose allow-list leaves the host out, or whose type keeps it on
   * the site frame (the chrome, off the frame), is refused naming what is
   * missing (the instantiator's FIELD_NOT_ON_HOST stays the field-level
   * backstop under it). A claim judges WITHOUT the frame kind: the slot
   * default it copies is the frame's own material reaching down into the
   * page, the one road by which the chrome enters a page's document.
   *
   * @param string $id
   *   The blueprint id.
   * @param array $definition
   *   The discovery definition.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the blueprint lands on.
   * @param bool $claiming
   *   TRUE for a slot claim, which strips the frame kind before judging.
   */
  protected function fitRefusal(string $id, array $definition, HostInterface $host, bool $claiming = FALSE): ?BlueprintResult {
    $requires = BlueprintRequirements::derive($definition['layout'], $definition['bundles'], $definition['frame_only']);
    if ($claiming) {
      unset($requires[BlueprintRequirements::FRAME]);
    }
    $missing = $this->requirements->missing($requires, $host);
    if ($missing === []) {
      return NULL;
    }
    $spelled = BlueprintRequirements::spellSubject($host);
    $hint = match (TRUE) {
      isset($missing[BlueprintRequirements::FRAME]) => 'The site chrome is the frame\'s material: place it on the site frame, where every page shows it; a page takes a slot\'s default as its own through a claim (detach_blueprint with slot), never a placement.',
      isset($missing[BlueprintRequirements::BUNDLES]) => 'Place it on a page of a bundle its allow-list names, or widen the allow-list in the blueprint\'s details.',
      default => 'Place a blueprint that fits this page (the Add panel lists them), or compose the element here and save it as a blueprint for this bundle.',
    };
    return BlueprintResult::semanticFailure([
      new BlueprintError('blueprint', 'BLUEPRINT_DOES_NOT_FIT', sprintf('Blueprint "%s" does not fit this %s: it needs %s.', $id, $spelled, BlueprintRequirements::describe($missing, $host)), [
        'blueprint' => $id,
        'provider' => $definition['provider'],
        'subject' => $spelled,
        'missing' => $missing,
      ], $hint),
    ]);
  }

  /**
   * A failed expansion's errors, each naming the blueprint.
   *
   * Placement errors surface far from the definition that caused them,
   * so every error names the blueprint and the module shipping it (NULL
   * for a content blueprint saved on this site).
   */
  protected function decorated(BlueprintResult $result, string $id, ?string $provider): BlueprintResult {
    $errors = array_map(
      static fn (BlueprintError $error) => new BlueprintError(
        $error->getPath(),
        $error->getCode(),
        $error->getMessage(),
        $error->getContext() + ['blueprint' => $id, 'provider' => $provider],
        $error->getHint(),
        $error->getOperationIndex(),
      ),
      $result->getErrors(),
    );
    return $result->getPhase() === 'semantic'
      ? BlueprintResult::semanticFailure($errors, $result->getWarnings())
      : BlueprintResult::structuralFailure($errors);
  }

}
