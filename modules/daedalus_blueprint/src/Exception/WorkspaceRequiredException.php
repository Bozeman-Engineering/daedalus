<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Exception;

/**
 * Thrown when persistence is attempted outside an active workspace.
 *
 * This exception is raised by EntityStorageHandler when the workspaces module
 * is enabled and no workspace is currently active.
 */
class WorkspaceRequiredException extends \RuntimeException {

}
