<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\daedalus_blueprint\Entity\FrameInterface;

/**
 * Keeps the Appearance menu's frame links in step with the frames.
 *
 * A frame's menu link derives per frame entity (FrameMenuLink), and menu
 * link definitions are written to the menu tree storage only on a rebuild
 * (core rebuilds them after a router rebuild or a cache flush, never on an
 * entity save), so a frame's birth and its deletion rebuild the links here
 * or the toolbar would name a frame that is gone and miss one that is
 * new. A frame's update changes nothing the link carries (its title is the
 * theme's name, its route the frame's id).
 */
final class FrameMenuLinkHooks {

  public function __construct(private readonly MenuLinkManagerInterface $menuLinkManager) {}

  /**
   * Rebuilds the menu links after a frame is born or deleted.
   */
  #[Hook('entity_insert')]
  #[Hook('entity_delete')]
  public function frameChanged(EntityInterface $entity): void {
    if ($entity->getEntityTypeId() === FrameInterface::ENTITY_TYPE_ID) {
      $this->menuLinkManager->rebuild();
    }
  }

}
