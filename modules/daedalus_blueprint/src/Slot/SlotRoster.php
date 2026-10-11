<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Slot;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;

/**
 * The site's slot roster: every slot id, its label and its tier.
 *
 * Slot ids are one namespace per site, the `slots` roster of
 * daedalus_blueprint.settings (SlotBlock::CONFIG_NAME, SlotBlock::ROSTER_KEY),
 * a mapping of `{label, tier}` per id. The tier names the one document of
 * the render stack that declares the slot: the site frame (TIER_FRAME: the
 * chrome's slots, `main` for the route's output among them) or a template
 * (TIER_TEMPLATE: the areas a locked page fills). A render is a stack of
 * frame, template and page, and a slot door asks the documents beneath it
 * for the section tagged with its id, door by door; if two tiers placed
 * the same id, one page section would fill both doors and render twice.
 * So a slot id is declared by exactly one tier, and the roster says which.
 *
 * This is the roster's one reader: the slot block's deriver (one block per
 * id, the label its admin label), the offer (PlacementRestrictions: a slot
 * destination is offered the slots of its own tier) and the verdict
 * (OwnershipPolicy::doorOf(): a slot block placed under the wrong tier is
 * no door) all read the roster here, so three config reads are one road.
 * A roster save rebuilds the block definitions (SlotRosterSubscriber).
 */
final class SlotRoster {

  /**
   * The tier of the site frame: the chrome's slots around every route.
   */
  public const TIER_FRAME = 'frame';

  /**
   * The tier of a template: the areas a locked page fills inside it.
   */
  public const TIER_TEMPLATE = 'template';

  public function __construct(protected ConfigFactoryInterface $configFactory) {}

  /**
   * The roster: every slot id to its label and tier.
   *
   * @return array<string, array{label: string, tier: string}>
   *   Slot id to its entry, in the roster's order.
   */
  public function entries(): array {
    return $this->configFactory->get(SlotBlock::CONFIG_NAME)->get(SlotBlock::ROSTER_KEY) ?? [];
  }

  /**
   * The tier declaring a slot, or NULL for an id the roster does not name.
   *
   * A slot block whose id left the roster has no derivative and is placed
   * by no tier: no door, offered nowhere.
   */
  public function tier(string $id): ?string {
    return $this->entries()[$id]['tier'] ?? NULL;
  }

  /**
   * Whether a holder's tier of the render stack declares a slot.
   *
   * The frame declares the frame-tier slots and every other holder (a
   * template) the template-tier ones, because a slot is declared by
   * exactly one tier: a door asks the documents beneath it for the section
   * tagged with its id, and one page section would fill two doors. A slot
   * block placed under the other tier (older config: a template holding a
   * frame slot) shows the block and declares nothing; an id that left the
   * roster is declared by nobody. The one spelling of the rule, read by
   * the verdict (OwnershipPolicy::doorOf()) and the render
   * (InnerRender::routeSlot()) so they agree on every door.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $holder
   *   The host whose document holds the slot block.
   * @param string $slot
   *   The slot id.
   */
  public function declaredBy(HostInterface $holder, string $slot): bool {
    return $this->tier($slot) === ($holder instanceof FrameHost ? self::TIER_FRAME : self::TIER_TEMPLATE);
  }

  /**
   * The slot ids a tier declares, in the roster's order.
   *
   * @param string $tier
   *   TIER_FRAME or TIER_TEMPLATE.
   *
   * @return string[]
   *   The ids.
   */
  public function ids(string $tier): array {
    $ids = [];
    foreach ($this->entries() as $id => $entry) {
      if ($entry['tier'] === $tier) {
        $ids[] = (string) $id;
      }
    }
    return $ids;
  }

}
