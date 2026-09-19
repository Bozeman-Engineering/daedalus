<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Interface for tool plugins.
 */
interface ModeInterface extends ConfigurableInterface {

  /**
   * Gets the summary shown on the bundle form's mode operations.
   */
  public function getSummary(): string|TranslatableMarkup;

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Add attachments.
   *
   * @param array $attachments
   *   The #attached array from $variables.
   */
  public function addAttachments(array &$attachments): void;

  /**
   * Applies.
   *
   * @return bool
   *   Whether the tool plugin works on this type of entity.
   */
  public function applies(): bool;

  /**
   * Get icon path.
   *
   * @return string
   *   The absolute path to the mode's toggle button icon.
   */
  public function getIconPath(): string;

  /**
   * Build top and side bars.
   *
   * @param array $page_top
   *   The page top render array.
   * @param \Drupal\daedalus_ui\ModeInterface $mode
   *   The mode plugin.
   */
  public function buildBars(array &$page_top, ModeInterface $mode): void;

  /**
   * Build mode button.
   *
   * @return array
   *   A render array of the mode button that will show up on the bottom of the
   *   Navigation menu.
   */
  public function buildModeButton(): array;

}
