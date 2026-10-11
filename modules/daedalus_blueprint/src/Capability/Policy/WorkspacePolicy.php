<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability\Policy;

use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\CapabilityPolicyInterface;
use Drupal\daedalus_blueprint\Capability\Refusal;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * A host that lives in workspaces is frozen while none is active.
 *
 * The site's rule (the workspaces module installed, no active workspace)
 * applies to a workspace-aware host only: a config host (a template) never
 * lives in workspaces. Published in advance as a host-level refusal so the
 * client freezes everything with one notice; the storage handler's assert
 * at persist time stays, since it guards commits, which are not ops.
 */
final class WorkspacePolicy implements CapabilityPolicyInterface {

  public function __construct(
    private readonly EntityStorageHandler $storageHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function judge(HostInterface $host, AccountInterface $actor, Capability $capability, string $target): ?Refusal {
    if (!$host->persistence()->workspaceAware || !$this->storageHandler->isWorkspaceRequired()) {
      return NULL;
    }
    return new Refusal(
      code: 'WORKSPACE_REQUIRED',
      message: 'Content changes require an active workspace.',
      hint: 'Activate a workspace and make the change there.',
    );
  }

}
