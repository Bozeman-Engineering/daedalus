<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Core\Entity\EntityInterface;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Derives the edit-session scope: entity uuid + langcode + workspace.
 *
 * The scope names the BroadcastChannel that pairs a secondary window
 * (daedalus_stylish's popped-out inspector, the responsive preview) with the editing
 * page (js/broadcast/channel.js prefixes it per consumer). Deterministic
 * derivation is what lets either window refresh and reconnect; the scope
 * boundary is what keeps two different edit sessions (another entity,
 * another translation, another workspace) from cross-talking.
 */
final class EditSessionScope {

  public function __construct(
    private readonly ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Derives the scope for an entity's edit session.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The main entity being edited.
   *
   * @return string|null
   *   The scope string, or NULL when the entity has no uuid (nothing
   *   deterministic to pair on).
   */
  public function derive(EntityInterface $entity): ?string {
    $uuid = $entity->uuid();
    if ($uuid === NULL) {
      return NULL;
    }
    $active_workspace = $this->workspaceManager?->getActiveWorkspace();
    return implode(':', [
      $uuid,
      $entity->language()->getId(),
      $active_workspace ? $active_workspace->id() : 'live',
    ]);
  }

}
