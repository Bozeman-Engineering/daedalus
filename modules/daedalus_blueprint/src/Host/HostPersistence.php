<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Host;

/**
 * How a host's commit lands.
 *
 * The persistence facts the storage handler and the inline block machinery
 * consult: whether a commit applies the bundle's new-revision rule (a
 * content host: nodes and block_content roots revision by their bundle's
 * say-so), whether the host lives in workspaces (a content host does; a
 * config host never does, so the workspace-required check and the
 * workspace tempstore keys do not apply to it), and whether the inline
 * blocks of its document persist as values (a config host: a display is
 * config and ships through config export, which cannot carry a content
 * revision id, so its blocks are stored as their fields in the field
 * grammar) or as block_content entities (a content host).
 */
final class HostPersistence {

  public function __construct(
    public readonly bool $revisions,
    public readonly bool $workspaceAware,
    public readonly bool $valueBlocks,
  ) {}

  /**
   * A content entity: an entity save under the bundle's revision policy.
   */
  public static function content(): self {
    return new self(revisions: TRUE, workspaceAware: TRUE, valueBlocks: FALSE);
  }

  /**
   * A config entity: a config save, no revisions, no workspaces, value blocks.
   */
  public static function config(): self {
    return new self(revisions: FALSE, workspaceAware: FALSE, valueBlocks: TRUE);
  }

}
