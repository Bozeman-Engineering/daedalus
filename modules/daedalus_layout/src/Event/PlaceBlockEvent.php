<?php

namespace Drupal\daedalus_layout\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Block\BlockPluginInterface;

/**
 * Place block event.
 *
 * When a block is placed on the page we auto-generate content for the block
 * using field_sample_value plugins. We then dispatch this event so other
 * modules can tweak it.
 */
class PlaceBlockEvent extends Event {

  /**
   * The placed block plugin.
   *
   * @var \Drupal\Core\Block\BlockPluginInterface
   */
  private BlockPluginInterface $blockPlugin;

  /**
   * The base block plugin ID, without any derivative suffix.
   *
   * @var string
   */
  private string $blockPluginId;

  /**
   * The derivative ID (the bundle for inline blocks), if any.
   *
   * @var string|null
   */
  private ?string $bundle;

  public function __construct(BlockPluginInterface $block_plugin) {
    $this->blockPlugin = $block_plugin;
    $block_plugin_id = $block_plugin->getPluginId();
    if (str_contains($block_plugin_id, ':')) {
      [$block_plugin_id, $bundle] = explode(':', $block_plugin_id);
    }
    else {
      $bundle = NULL;
    }

    $this->blockPluginId = $block_plugin_id;
    $this->bundle = $bundle;
  }

  /**
   * Gets the placed block plugin.
   *
   * @return \Drupal\Core\Block\BlockPluginInterface
   *   The block plugin.
   */
  public function getBlockPlugin() {
    return $this->blockPlugin;
  }

  /**
   * Gets the base block plugin ID, without any derivative suffix.
   *
   * @return string
   *   The block plugin ID.
   */
  public function getBlockPluginId(): string {
    return $this->blockPluginId;
  }

  /**
   * Gets the derivative ID, which is the bundle for inline blocks.
   *
   * @return string|null
   *   The bundle, or NULL when the plugin ID has no derivative.
   */
  public function getBundle(): ?string {
    return $this->bundle;
  }

}
