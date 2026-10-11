<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_layout\Plugin\Block\InlineBlock;

/**
 * Serves every inline block derivative from the value-block aware class.
 */
final class BlockAlter {

  /**
   * Implements hook_block_alter().
   *
   * The inline_block:* derivatives keep core's plugin definition and get
   * daedalus_layout's class, which hydrates a value block (a display
   * host's stored shape) beside core's serialized and saved shapes.
   */
  #[Hook('block_alter')]
  public function inlineBlockClass(array &$definitions): void {
    foreach ($definitions as $plugin_id => &$definition) {
      if (str_starts_with((string) $plugin_id, 'inline_block:')) {
        $definition['class'] = InlineBlock::class;
      }
    }
  }

}
