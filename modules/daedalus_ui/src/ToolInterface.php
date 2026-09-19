<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Core\Entity\EntityInterface;

/**
 * Interface for tool plugins.
 */
interface ToolInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Get icon data.
   *
   * Returns the icon pack and icon ID for rendering with Drupal's icon system.
   * Can optionally return mouse cursor CSS.
   *
   * @return array
   *   An array with the following structure:
   *   - icon_id: (string) The icon ID within the pack
   *   - pack_id: (string) The icon pack ID (defaults to 'daedalus_ui')
   *   - mouse_icon: (string|null) Optional CSS cursor value
   */
  public function getIconsPath(): array;

  /**
   * Build global top bar buttons.
   *
   * @param array $global_top_bar
   *   The global top bar render array.
   *
   * @return array
   *   A render array of buttons to include on the right side of the top bar.
   */
  public function buildGlobalTopBarButtons(array &$global_top_bar): array;

  /**
   * Build tool top bar buttons.
   *
   * @return array
   *   A render array of buttons to include on the left side of the top bar
   *   when the tool is active.
   */
  public function buildToolTopBarButtons(): array;

  /**
   * Build settings.
   *
   * @return array
   *   A render array of form elements for the edit mode settings right sidebar.
   */
  public function buildSettings(): array;

  /**
   * Gets the optional sub tools.
   *
   * @return array
   *   The sub tool icon paths and ids.
   */
  public function subTools(): array;

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
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity this tool would apply to.
   *
   * @return bool
   *   Whether the tool plugin works on this type of entity.
   */
  public function applies(EntityInterface $entity): bool;

}
