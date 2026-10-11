<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Capability;

use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\CapabilityPolicyInterface;
use Drupal\daedalus_blueprint\Capability\Refusal;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint_library\BlueprintInterface;

/**
 * A block-scope blueprint holds exactly one root.
 *
 * A block blueprint stands in for ONE container placed into a page, so
 * its document has one root; a second top-level insert (a new section at
 * the root, a container moved up to it) is refused as BLUEPRINT_ONE_ROOT,
 * on the blueprint's canvas as at every other door, because a reason to
 * refuse is a policy, never a check on a surface. A page blueprint is a
 * whole page's document and takes any number of roots; inserting below
 * the root is any blueprint's ordinary editing. The rule has two twins at
 * the doors no op passes through: discovery's envelope rule for the file
 * lane (a block-scope file has exactly one root) and Blueprint::preSave()
 * for storage (a block-scope entity saved with several sections is
 * refused, the door core's default-content importer writes through).
 */
final class BlueprintScopePolicy implements CapabilityPolicyInterface {

  /**
   * The refusal code.
   */
  public const CODE = 'BLUEPRINT_ONE_ROOT';

  /**
   * {@inheritdoc}
   */
  public function judge(HostInterface $host, AccountInterface $actor, Capability $capability, string $target): ?Refusal {
    if (!$host instanceof BlueprintHost || $capability !== Capability::Insert || $target !== $host->uuid()) {
      return NULL;
    }
    $blueprint = $host->entity();
    if (!$blueprint instanceof BlueprintInterface || $blueprint->getScope() !== BlueprintInterface::SCOPE_BLOCK || $host->ownSections() === []) {
      return NULL;
    }
    return new Refusal(
      code: self::CODE,
      message: sprintf('The block blueprint "%s" holds one root container; it already has one.', $blueprint->getMachineName()),
      hint: 'Insert into the existing root container instead, or save a page blueprint for a document with several top-level containers.',
      context: ['blueprint' => $blueprint->getMachineName(), 'scope' => BlueprintInterface::SCOPE_BLOCK],
    );
  }

}
