<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the update guard: a site predating the blueprint library halts.
 *
 * The blueprint library landed with no update path, so
 * daedalus_blueprint_update_requirements() (the hook update.php and
 * `drush updb` consult) answers an error naming the reinstall when the
 * site still carries either retired artifact, the retired module in
 * `core.extension` or its entity table, and nothing on a site that
 * carries neither.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
final class UpdateRequirementsGuardTest extends KernelTestBase {

  /**
   * The key the guard's requirement is reported under.
   */
  private const REQUIREMENT = 'daedalus_blueprint_library_predates';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'filter',
    'daedalus_blueprint',
  ];

  /**
   * A site installed after the library reports nothing.
   */
  public function testCleanSiteReportsNothing(): void {
    $this->assertArrayNotHasKey(self::REQUIREMENT, $this->updateRequirements());
  }

  /**
   * The retired entity table alone halts the update.
   */
  public function testRetiredTableHaltsTheUpdate(): void {
    $schema = $this->container->get('database')->schema();
    $schema->createTable('stencil', [
      'fields' => [
        'id' => ['type' => 'serial', 'not null' => TRUE],
      ],
      'primary key' => ['id'],
    ]);
    $this->assertGuardRaised();

    $schema->dropTable('stencil');
    $this->assertArrayNotHasKey(self::REQUIREMENT, $this->updateRequirements(), 'Dropping the table lifts the guard.');
  }

  /**
   * The retired module listed in core.extension alone halts the update.
   */
  public function testRetiredModuleHaltsTheUpdate(): void {
    $extension = $this->config('core.extension');
    $extension->set('module.daedalus_blueprint_stencil', 0)->save();
    $this->assertGuardRaised();

    $extension->clear('module.daedalus_blueprint_stencil')->save();
    $this->assertArrayNotHasKey(self::REQUIREMENT, $this->updateRequirements(), 'Removing the module from core.extension lifts the guard.');
  }

  /**
   * Asserts the guard's requirement is an error naming the reinstall.
   */
  private function assertGuardRaised(): void {
    $requirements = $this->updateRequirements();
    $this->assertArrayHasKey(self::REQUIREMENT, $requirements);
    $requirement = $requirements[self::REQUIREMENT];
    $this->assertSame(RequirementSeverity::Error, $requirement['severity']);
    $this->assertSame('This site predates the blueprint library; there is no update path across it: reinstall from the recipe.', (string) $requirement['description']);
  }

  /**
   * Invokes hook_update_requirements() as update_check_requirements() does.
   */
  private function updateRequirements(): array {
    return $this->container->get('module_handler')->invokeAll('update_requirements');
  }

}
