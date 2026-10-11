<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Slot;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\layout_builder\SectionComponent;

/**
 * A slot's door: the slot block declaring it, in the document that holds it.
 *
 * Answered by InnerRender::slotDoor() for a slot id and the host beneath
 * it: the document around the host that places the slot block (the
 * template the host renders inside, or the site frame around the route),
 * the block itself, and the default it carries (a published blueprint's
 * machine name, or NULL). The mirror image of SlotFill: a fill is what a
 * document beneath puts INTO a slot, a door is where the document above
 * DECLARES it. Claiming a slot (detach_blueprint with `slot`) reads the
 * default here; the op door lands a placement anchored inside the block
 * in the host's own slot section.
 */
final class SlotDoor {

  public function __construct(
    public readonly HostInterface $holder,
    public readonly SectionComponent $component,
  ) {}

  /**
   * The slot id the door declares.
   */
  public function slot(): string {
    return (string) SlotBlock::slotIdOf($this->component->getPluginId());
  }

  /**
   * The door's default: a blueprint machine name, or NULL for an empty slot.
   */
  public function default(): ?string {
    $default = $this->component->get('configuration')[SlotBlock::DEFAULT_KEY] ?? NULL;
    return is_string($default) && $default !== '' ? $default : NULL;
  }

}
