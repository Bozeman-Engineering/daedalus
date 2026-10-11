<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\AddPanel;

use Drupal\block_content\BlockContentTypeInterface;

/**
 * A block type's face: its settings' names, and the one way to write them.
 *
 * The face is stored under `block_content.type.*.third_party.daedalus_layout`
 * (`icon`, `favorite`, `groups`; the schema in config/schema). Each key's
 * absence is the one spelling of its default (no icon, not a favorite, no
 * group), so a writer unsets a key left at its default rather than writing
 * an empty value that would ship in config and read as configured. Two
 * writers share that rule through here: the block type form
 * (Hook\BlockContentTypeFormAlter) and the Add panel's "Favorite for
 * everyone" (Controller\BlockTypeFavorite). AddPanelFaces reads the keys.
 */
final class BlockTypeFace {

  /**
   * The third-party settings namespace on the block type.
   */
  public const NAMESPACE = 'daedalus_layout';

  /**
   * The icon setting, in the icon value grammar (IconResolver).
   */
  public const ICON_SETTING = 'icon';

  /**
   * The site's default favorite, TRUE or absent.
   */
  public const FAVORITE_SETTING = 'favorite';

  /**
   * The group ids the type is filed under, a non-empty list or absent.
   */
  public const GROUPS_SETTING = 'groups';

  /**
   * Writes the site's default favorite on the block type; the caller saves.
   *
   * @param \Drupal\block_content\BlockContentTypeInterface $block_type
   *   The block type.
   * @param bool $favorite
   *   TRUE lists it under Favorites for everyone; FALSE unsets the key.
   */
  public static function setFavorite(BlockContentTypeInterface $block_type, bool $favorite): void {
    if ($favorite) {
      $block_type->setThirdPartySetting(self::NAMESPACE, self::FAVORITE_SETTING, TRUE);
    }
    else {
      $block_type->unsetThirdPartySetting(self::NAMESPACE, self::FAVORITE_SETTING);
    }
  }

}
