<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_blueprint_library\Capability\BlueprintScopePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A block-scope blueprint holds one root: the policy at the op door.
 *
 * Scope is enforced by a capability policy, never by a check on a
 * surface: a second top-level container on a block blueprint's canvas is
 * refused BLUEPRINT_ONE_ROOT by the resolver before the op runs, while
 * inserting below the root, a page blueprint's second root and an empty
 * block blueprint's first root are ordinary edits.
 */
#[CoversClass(BlueprintScopePolicy::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintScopePolicyTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static array $permissions = ['create section blueprint', 'edit any section blueprint', 'configure any layout'];

  /**
   * The root insert on a block blueprint refuses once it has a root.
   */
  public function testBlockBlueprintHoldsOneRoot(): void {
    $blueprint = $this->createBlueprint(['machine_name' => 'one_root']);
    $host = $this->host($blueprint);
    $root = $this->host($blueprint)->ownSections()[0]->getThirdPartySetting('daedalus_layout', 'uuid');

    // A second root: refused at the door, nothing lands.
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'add_section', 'location' => ['anchor' => $host->uuid(), 'placement' => 'inside']],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame(BlueprintScopePolicy::CODE, $error->getCode());
    $this->assertSame('one_root', $error->getContext()['blueprint']);
    $this->assertStringContainsString('one root container', $error->getMessage());
    $this->assertCount(1, $host->ownSections());

    // Beside the root (after it, at the top level) is the same refusal.
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'add_section', 'location' => ['anchor' => $root, 'placement' => 'after']],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame(BlueprintScopePolicy::CODE, $result->getErrors()[0]->getCode());

    // Below the root is ordinary editing: a nested container and a block.
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'add_section', 'location' => ['anchor' => $root, 'placement' => 'inside']],
      ['op' => 'add_element', 'block_type' => 'basic', 'location' => ['anchor' => $root, 'placement' => 'inside']],
    ]);
    $this->assertBatchSuccess($result);
    $this->assertCount(1, $host->ownSections());
    $this->assertCount(2, $host->ownSections()[0]->getComponents());
  }

  /**
   * An empty block blueprint takes its first root; a page blueprint any.
   */
  public function testFirstRootAndPageScopeAreOpen(): void {
    $empty = $this->createBlueprint(['machine_name' => 'empty'], []);
    $host = $this->host($empty);
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'add_section', 'location' => ['anchor' => $host->uuid(), 'placement' => 'inside']],
    ]);
    $this->assertBatchSuccess($result);
    $this->assertCount(1, $host->ownSections());

    $page = $this->createBlueprint(['machine_name' => 'whole_page', 'scope' => 'page']);
    $host = $this->host($page);
    $result = $this->operations()->batchOperations($host, [
      ['op' => 'add_section', 'location' => ['anchor' => $host->uuid(), 'placement' => 'inside']],
      ['op' => 'add_section', 'location' => ['anchor' => $host->uuid(), 'placement' => 'inside']],
    ]);
    $this->assertBatchSuccess($result);
    $this->assertCount(3, $host->ownSections());
  }

}
