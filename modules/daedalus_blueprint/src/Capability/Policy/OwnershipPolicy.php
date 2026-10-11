<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability\Policy;

use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\CapabilityPolicyInterface;
use Drupal\daedalus_blueprint\Capability\Portal;
use Drupal\daedalus_blueprint\Capability\Refusal;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Host\InnerRender;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotDoor;
use Drupal\daedalus_blueprint\Slot\SlotFill;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\layout_builder\SectionComponent;

/**
 * An element of a document the host does not own is refused everything.
 *
 * A host rendered inside a foreign document (a page of a bundle whose
 * display is Layout Builder enabled but not overridable) renders its
 * display's template around whatever it owns. Every element of the
 * template is foreign: readable on the host's canvas, never mutable
 * there, styling included (a document is the section tree plus its style
 * map, one unit, so a page that does not own the tree does not own style
 * entries addressed into it). What the host owns inside it is judged by
 * nobody here: its own sections (the slots its override field holds) are
 * its own document, every capability open on them as on any owned
 * document. The host's root stays its own for content (its fields) and
 * style (its own root key); the root's structure (what may be inserted
 * into or arranged in its top-level flow) is the template's. One foreign
 * element keeps one capability: a field block presenting one of the
 * host's own fields carries the content verdict of that field (the page's
 * editable fields stay editable through the block that shows them), and
 * nothing else. The owner is the display's host, where the gesture
 * belongs: the refusal names it as the cause and offers it as the portal
 * only when the actor may open it; the corrective says so either way.
 *
 * The one door through the lock is the slot: a slot block the owner
 * placed (daedalus_slot:{id}) is the place the host's own document fills,
 * so insert on it is the host's, not the template's (the op door lands a
 * placement anchored inside it in the host's slot section, minted when
 * absent), and the slot block's insert verdict is exactly "this document
 * may fill this slot". The other way round never holds: a document does
 * not fill its own slot, so insert on a slot block of the host's OWN
 * document is refused (SLOT_OWN_DOCUMENT); a placement beside it is the
 * ordinary insert on the container around it, untouched here. A slot is
 * declared by exactly one tier of the render stack, the frame or a
 * template, and the roster says which (SlotRoster): a slot block placed
 * under the other tier (a template holding a frame slot, written before
 * the offer read the tier) is no door, an ordinary foreign element refused
 * with the rest of its document (doorOf()). The frame's main slot
 * (FrameHost::MAIN_SLOT) is no door anywhere: it is the place the route's
 * output lands, and it asks no filler.
 *
 * The other foreign document inside a host's own is a linked placement
 * (daedalus_blueprint_linked:{machine_name}): a node of the host's own
 * document whose content is a blueprint's document, shown by reference.
 * The node is the host's (where it sits, its own box styles, its removal
 * are the host's gestures), its content is the blueprint's: `content` on
 * it is refused FOREIGN_DOCUMENT naming the blueprint, with the portal to
 * the blueprint's canvas when the actor may update it. The blueprint's
 * elements themselves are never targets here: they are not in this
 * document, and a gesture aimed at one is the operation's own answer.
 *
 * A render is a stack of hosts, and a canvas also shows documents that
 * are nowhere in the host's own stack: the site frame around every route,
 * the document of a blueprint a placement links. A node of such a
 * document is no target of judge() (the host's tree never holds it); the
 * render, which knows which host's document holds the node, asks
 * foreignNode() through the verdict stamper (VerdictStamper::verdictsOn()),
 * and the answer is the same FOREIGN_DOCUMENT refusal with the same
 * portal rule: edit it where it is owned, when the actor may.
 */
final class OwnershipPolicy implements CapabilityPolicyInterface {

  public function __construct(
    private readonly HostResolver $hostResolver,
    private readonly DaedalusLayoutAdapter $layoutAdapter,
    private readonly BlueprintAccessChecker $accessChecker,
    private readonly InnerRender $innerRender,
    private readonly SlotRoster $slotRoster,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function judge(HostInterface $host, AccountInterface $actor, Capability $capability, string $target): ?Refusal {
    $owner = $this->hostResolver->foreignOwner($host);
    $host_name = $host->entityTypeId();
    if ($target === $host->uuid()) {
      if ($owner === NULL || !$capability->isDocumentWrite() || $capability === Capability::Style) {
        return NULL;
      }
      $owner_name = $owner->entityTypeId() . ' ' . $owner->id();
      // The root's corrective is the one a reader of the whole page is
      // headed with (page_read's notice, the canvas's explanation), so it
      // names the doors the lock leaves open: the slots the template
      // places, which this host fills by inserting inside their blocks.
      return $this->refusal(
        $owner,
        $actor,
        "The layout of this $host_name belongs to the template $owner_name, which it renders but does not own.",
        $host_name,
        $owner_name,
        $this->layoutAdapter->declaredSlots($owner->ownSections()),
      );
    }
    // A slot block of the host's own document is no door for itself.
    if ($capability === Capability::Insert && ($slot = $this->slotOf($host, $target)) !== NULL) {
      return new Refusal(
        code: 'SLOT_OWN_DOCUMENT',
        message: "Slot '$slot' is a slot of this document: it is filled by the documents rendered inside it, never by this one.",
        hint: "Place beside the slot block (before/after it) to add to this document; a page of this template fills slot '$slot' from its own canvas.",
        context: ['slot' => $slot],
      );
    }
    // A linked placement of the host's own document shows a blueprint by
    // reference: its content is the blueprint's, edited on the blueprint.
    if ($capability === Capability::Content && ($linked = $this->linkedOf($host, $target)) !== NULL) {
      return $this->linkedRefusal($linked, $actor, $target);
    }
    // The site frame's slot blocks are doors of every page of the theme:
    // insert on one is the host's own, as on the template's doors below.
    if ($capability === Capability::Insert && $this->frameDoorOf($host, $target) !== NULL) {
      return NULL;
    }
    if ($owner === NULL) {
      return NULL;
    }
    // The host's own sections are its own document: nothing to refuse.
    if (array_key_exists($target, $this->layoutAdapter->parentIndex($host->ownSections()))) {
      return NULL;
    }
    $owner_name = $owner->entityTypeId() . ' ' . $owner->id();
    // Only an element of the owner's document is foreign; a target nowhere
    // on the page is the operation's own answer (ANCHOR_NOT_FOUND).
    if (!array_key_exists($target, $this->layoutAdapter->parentIndex($owner->ownSections()))) {
      return NULL;
    }
    if ($capability === Capability::Content && $this->layoutAdapter->presentedHostField($host, $target) !== NULL) {
      return NULL;
    }
    // The owner's slot door is the door this host fills: insert on it is
    // the host's own (a slot block of the wrong tier the owner placed is
    // no door, refused below with the rest of the template). Everything
    // else on a door showing its default is the default blueprint's,
    // edited there.
    $door = $this->doorOf($owner, $target);
    if ($door !== NULL) {
      if ($capability === Capability::Insert) {
        return NULL;
      }
      if ($this->showsDefault($door, $host)) {
        $refusal = $this->linkedRefusal($door->default(), $actor, $target);
        if ($refusal->portal !== NULL) {
          return $refusal;
        }
      }
    }
    return $this->refusal(
      $owner,
      $actor,
      "Element '$target' belongs to the template $owner_name, which this $host_name renders but does not own.",
      $host_name,
      $owner_name,
    );
  }

  /**
   * The refusal of a node of another host's document, shown on a canvas.
   *
   * For a node the canvas host's stack does not hold: the site frame's
   * nodes on any page of its theme, the nodes of a blueprint document a
   * linked placement shows. Every capability is refused FOREIGN_DOCUMENT.
   * A linked placement of the holder's document (the frame's linked
   * header) names the blueprint it shows, with the portal to the
   * blueprint's canvas, when the actor may update that blueprint: its
   * content is the blueprint's, and that is where "edit the header" lands
   * from any page. Every other node, and a linked one whose blueprint the
   * actor may not update, names the holder, with the portal to the
   * holder's canvas when the actor may update the holder (the frame for an
   * actor with `configure the site frame`); an actor who may open neither
   * gets the cause and no door. A slot door of the holder showing its
   * default (nothing beneath the canvas fills the slot) reads as the
   * linked placement it is: the default blueprint's, with that portal.
   *
   * Insert is the one capability a foreign node may allow, and it is not
   * judged here: a slot door of the frame is the canvas host's own door
   * (judge() allows insert on it), which the verdict stamper asks the
   * resolver about (fillsFrameDoor()).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $holder
   *   The host whose document holds the node.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   The actor.
   * @param string $target
   *   The node's uuid.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas host the node is seen from, or NULL on a route with none:
   *   what fills the holder's slots, when it fills any.
   *
   * @return \Drupal\daedalus_blueprint\Capability\Refusal
   *   The refusal.
   */
  public function foreignNode(HostInterface $holder, AccountInterface $actor, string $target, ?HostInterface $canvas = NULL): Refusal {
    $linked = $this->linkedOf($holder, $target);
    if ($linked === NULL) {
      $door = $this->doorOf($holder, $target);
      $linked = $door !== NULL && $this->showsDefault($door, $canvas) ? $door->default() : NULL;
    }
    if ($linked !== NULL) {
      $refusal = $this->linkedRefusal($linked, $actor, $target);
      if ($refusal->portal !== NULL) {
        return $refusal;
      }
    }
    $holder_name = $holder->entityTypeId() . ' ' . $holder->id();
    $portal = $this->portalTo($holder, $actor);
    return new Refusal(
      code: 'FOREIGN_DOCUMENT',
      message: "Element '$target' belongs to $holder_name, which this page shows but does not own.",
      hint: $portal !== NULL
        ? "Edit it where it is owned: run the operation against $holder_name, and every page showing it follows."
        : "It is part of $holder_name, which you may not edit.",
      context: ['owner' => ['entity_type' => $holder->entityTypeId(), 'entity_id' => $holder->id()]],
      portal: $portal,
    );
  }

  /**
   * The portal to a host, for an actor who may update it; NULL otherwise.
   *
   * The one rule every door of the spine offers a portal by: a refusal's
   * portal (where the refused gesture belongs) and a showing's (where the
   * shown blueprint is edited) both name the host only to an actor who
   * may open it, so no surface draws a door to a room the actor cannot
   * enter.
   */
  public function portalTo(HostInterface $host, AccountInterface $actor): ?Portal {
    return $this->accessChecker->hostAccess($host, 'update', $actor) ? Portal::toHost($host) : NULL;
  }

  /**
   * The blueprint a linked placement of the host's own document shows.
   *
   * NULL for any element that is not a linked block and for a uuid the
   * host's own document does not hold (a linked block of the OWNER's
   * document is the owner's element, refused as the template's). A door
   * fact the showing reads (VerdictStamper::showing()): the machine name
   * is what the placement names, published or not.
   */
  public function linkedOf(HostInterface $host, string $uuid): ?string {
    $context = $this->layoutAdapter->locateElement($host, $uuid);
    if ($context === NULL || !$context->isLbComponent()) {
      return NULL;
    }
    /** @var \Drupal\layout_builder\SectionComponent $component */
    $component = $context->target;
    $name = LinkedBlueprintBlock::blueprintOf($component->getPluginId());
    if ($name === NULL || !array_key_exists($uuid, $this->layoutAdapter->parentIndex($host->ownSections()))) {
      return NULL;
    }
    return $name;
  }

  /**
   * The FOREIGN_DOCUMENT refusal of a linked placement's content.
   *
   * The portal is the blueprint's canvas, offered only to an actor who may
   * update the blueprint; a name that resolves to no published blueprint
   * (unpublished or deleted since the placement) names the blueprint and
   * offers no door.
   */
  private function linkedRefusal(string $name, AccountInterface $actor, string $target): Refusal {
    $blueprint = $this->innerRender->blueprintHost($name);
    $portal = $blueprint === NULL ? NULL : $this->portalTo($blueprint, $actor);
    return new Refusal(
      code: 'FOREIGN_DOCUMENT',
      message: "Element '$target' shows the blueprint '$name' by reference: its content is the blueprint's document, which this page renders but does not own.",
      hint: $portal !== NULL
        ? "Edit the blueprint on its own canvas: run the operation against blueprint {$blueprint->id()}, and every page linking it follows. The placement itself (where it sits, its own styles) is this page's."
        : "Its content is the blueprint's ('$name'), which you may not edit. The placement itself (where it sits, its own styles) is this page's.",
      context: ['blueprint' => $name] + ($blueprint === NULL ? [] : [
        'owner' => ['entity_type' => $blueprint->entityTypeId(), 'entity_id' => $blueprint->id()],
      ]),
      portal: $portal,
    );
  }

  /**
   * The slot block a component of a host's document is, or NULL.
   *
   * NULL for any element that is not a slot block (a section, an ordinary
   * block) and for a uuid the host's document does not hold.
   */
  private function slotBlockOf(HostInterface $host, string $uuid): ?SectionComponent {
    $context = $this->layoutAdapter->locateElement($host, $uuid);
    if ($context === NULL || !$context->isLbComponent()) {
      return NULL;
    }
    /** @var \Drupal\layout_builder\SectionComponent $component */
    $component = $context->target;
    return SlotBlock::slotIdOf($component->getPluginId()) === NULL ? NULL : $component;
  }

  /**
   * The slot id a component of a host's document declares, or NULL.
   *
   * The one question about a slot block that is not about a door: a
   * document's own slot block, main or not, is no door for itself
   * (SLOT_OWN_DOCUMENT). Every door question asks doorOf().
   */
  private function slotOf(HostInterface $host, string $uuid): ?string {
    $component = $this->slotBlockOf($host, $uuid);
    return $component === NULL ? NULL : SlotBlock::slotIdOf($component->getPluginId());
  }

  /**
   * The slot a component of a holder's document declares, or NULL.
   *
   * A slot block of the holder's document whose slot the holder's tier
   * declares (SlotRoster::declaredBy(): the frame's frame-tier slot blocks,
   * a template's template-tier ones; a slot block placed under the other
   * tier, older config such as a template holding a frame slot, or whose
   * id left the roster declares nothing, as the offer
   * (PlacementRestrictions) never places one). The frame's main slot
   * (FrameHost::MAIN_SLOT) is among them: it is a join of the render stack
   * like any door (the showing says what it shows: the route's output, or
   * nothing while a door hosts the route), though never a door (doorOf()).
   * NULL for any other element and for a uuid the holder's document does
   * not hold.
   */
  public function declaredSlotOf(HostInterface $holder, string $uuid): ?SlotDoor {
    $component = $this->slotBlockOf($holder, $uuid);
    if ($component === NULL) {
      return NULL;
    }
    $slot = SlotBlock::slotIdOf($component->getPluginId());
    return $this->slotRoster->declaredBy($holder, $slot) ? new SlotDoor($holder, $component) : NULL;
  }

  /**
   * The door a component of a holder's document is, or NULL.
   *
   * A slot the holder declares (declaredSlotOf()) other than the frame's
   * main slot: a door asks the documents beneath it for the section tagged
   * with its id, and main is where the page display variant drops what the
   * route produced, asking no filler, the render rule the filler walk keeps
   * by name. The one rule the door questions read (the template door in
   * judge(), fillsFrameDoor(), frameDoorOf(), foreignNode()'s shown
   * default, the showing's claim), so the verdict, the op door and the
   * filler walk (BlueprintOperations::slotDoor(), InnerRender::slotDoor())
   * agree on every door. NULL for any other element and for a uuid the
   * holder's document does not hold.
   */
  public function doorOf(HostInterface $holder, string $uuid): ?SlotDoor {
    $door = $this->declaredSlotOf($holder, $uuid);
    return $door === NULL || $door->slot() === FrameHost::MAIN_SLOT ? NULL : $door;
  }

  /**
   * Whether a node of a foreign holder is a slot door the canvas may fill.
   *
   * The site frame's doors (doorOf(): its frame-tier slot blocks, the main
   * slot excepted) are doors of every page of the theme, as a template's
   * are of the pages it renders;
   * the frame is in no page's stack, so the verdict stamper asks here
   * before treating a frame node as refused everything, and asks the
   * resolver for insert on the door when this answers TRUE (judge() allows
   * it for the ownership axis; the role axis judges the canvas's own
   * permissions).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas host, or NULL on a route with none (nothing fills then).
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $holder
   *   The host whose document holds the node.
   * @param string $target
   *   The node's uuid.
   */
  public function fillsFrameDoor(?HostInterface $canvas, HostInterface $holder, string $target): bool {
    if ($canvas === NULL || !$holder instanceof FrameHost || !InnerRender::fillsSlots($canvas)) {
      return FALSE;
    }
    return $this->doorOf($holder, $target) !== NULL;
  }

  /**
   * The frame door a target is, when it is one the host may fill.
   *
   * NULL unless the host fills slots (a page, a template), the active
   * theme has a frame, and the target is one of that frame's doors
   * (doorOf(): its frame-tier slot blocks other than its main slot).
   */
  private function frameDoorOf(HostInterface $host, string $target): ?SlotDoor {
    if (!InnerRender::fillsSlots($host)) {
      return NULL;
    }
    $frame = $this->innerRender->frameHost();
    if ($frame === NULL) {
      return NULL;
    }
    return $this->doorOf($frame, $target);
  }

  /**
   * What fills a slot the holder declares, seen from a canvas, or NULL.
   *
   * The innermost document beneath the canvas filling the slot for the
   * canvas's subject (InnerRender::slotFiller()); nothing fills with no
   * canvas (a host-less route) or from a canvas that fills no slots (the
   * frame's own, a blueprint's: their sections are never fills). The one
   * door fact behind "does this door show its default": the verdict's
   * content refusal on a door (judge(), foreignNode()) and the showing
   * (VerdictStamper::showing()) read it, so the chip, the row and the
   * refusal's portal agree on a filled door.
   *
   * @param \Drupal\daedalus_blueprint\Slot\SlotDoor $slot
   *   The slot block, as declaredSlotOf() answers it.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas host beneath the slot, or NULL on a route with none.
   *
   * @return \Drupal\daedalus_blueprint\Slot\SlotFill|null
   *   The filler and its section, or NULL.
   */
  public function fillOf(SlotDoor $slot, ?HostInterface $canvas): ?SlotFill {
    if ($canvas === NULL || !InnerRender::fillsSlots($canvas)) {
      return NULL;
    }
    return $this->innerRender->slotFiller($slot->slot(), $slot->holder, $canvas->subject());
  }

  /**
   * Whether a door shows its default for a canvas: it has one, nothing fills.
   */
  private function showsDefault(SlotDoor $door, ?HostInterface $canvas): bool {
    return $door->default() !== NULL && $this->fillOf($door, $canvas) === NULL;
  }

  /**
   * The FOREIGN_DOCUMENT refusal, its portal only when the owner is openable.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $owner
   *   The foreign document's owner.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   The actor.
   * @param string $message
   *   The refusal's sentence.
   * @param string $host_name
   *   The host's entity type id, as the corrective names it.
   * @param string $owner_name
   *   The owner's entity type id and id, as the corrective names it.
   * @param string[] $slots
   *   The slots the owner's document places, named by the corrective as
   *   the host's own to fill; [] names none (an element's refusal, or a
   *   template without slots).
   */
  private function refusal(HostInterface $owner, AccountInterface $actor, string $message, string $host_name, string $owner_name, array $slots = []): Refusal {
    $portal = $this->portalTo($owner, $actor);
    $own = $slots === []
      ? "Edit this {$host_name}'s own fields here"
      : sprintf("Edit this {$host_name}'s own fields here and fill its slots (%s) by inserting inside their slot blocks", implode(', ', $slots));
    return new Refusal(
      code: 'FOREIGN_DOCUMENT',
      message: $message,
      hint: $portal !== NULL
        ? "$own; edit its layout on the template: run the operation against $owner_name."
        : "$own; its layout is the template's ($owner_name), which you may not edit.",
      context: ['owner' => ['entity_type' => $owner->entityTypeId(), 'entity_id' => $owner->id()]] + ($slots === [] ? [] : ['slots' => $slots]),
      portal: $portal,
    );
  }

}
