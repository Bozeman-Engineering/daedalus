<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\field\Entity\FieldConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a blueprint type's style store opens by itself.
 *
 * A blueprint type is a page-builder bundle by construction: it answers
 * daedalus_ui's `enabled` bundle gate as on (BlueprintType, no stored
 * setting and no form to switch it), so daedalus_stylish's gate (Layout
 * Builder AND the page builder on for the bundle) passes on the type's
 * shipped Layout Builder display, which is what opens the store, one per
 * type. Nothing here calls the field manager's enable(): the field exists
 * because the display was imported on a site with daedalus_stylish, the
 * sweep recreates it when it is missing without an opt-out, and the
 * per-bundle style permission the recipe grants follows the field.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintStylingTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Merged with the base class's list by the kernel test bootstrapper.
   */
  protected static $modules = ['daedalus_stylish'];

  /**
   * The field manager.
   */
  protected DaedalusStylishFieldManager $fieldManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fieldManager = $this->container->get('daedalus_stylish.field_manager');
  }

  /**
   * The display import opened the store; the sweep converges it back.
   */
  public function testDisplayImportOpensTheStyleStore(): void {
    foreach (['section', 'site_chrome'] as $type) {
      $this->assertTrue($this->fieldManager->editModeEnabled('blueprint', $type), "A blueprint type ($type) answers the Edit Mode gate as on by construction.");
      $this->assertTrue($this->fieldManager->layoutBuilderEnabled('blueprint', $type), 'The shipped full display is Layout Builder enabled.');
      $this->assertTrue($this->fieldManager->isEnabled('blueprint', $type), 'Importing the display created the style map field.');
      $this->assertFalse($this->fieldManager->isOptedOut('blueprint', $type));
    }

    $permissions = $this->container->get('user.permissions')->getPermissions();
    $this->assertArrayHasKey('style section blueprint elements', $permissions, 'The per-bundle style permission follows the field.');
    $this->assertArrayHasKey('style site_chrome blueprint elements', $permissions);
    $this->assertSame(['field.field.blueprint.section.' . DaedalusStylishFieldManager::FIELD_NAME], $permissions['style section blueprint elements']['dependencies']['config']);

    // A field missing without an opt-out is not a memory: the gate check a
    // display save or a sweep runs recreates it.
    FieldConfig::loadByName('blueprint', 'section', DaedalusStylishFieldManager::FIELD_NAME)->delete();
    $this->assertFalse($this->fieldManager->isEnabled('blueprint', 'section'));
    $this->fieldManager->maybeAutoEnable('blueprint', 'section');
    $this->assertTrue($this->fieldManager->isEnabled('blueprint', 'section'), 'maybeAutoEnable() creates the field with nothing but the display on the type.');

    // A deliberate opt-out is the one thing that keeps it closed.
    $this->fieldManager->disable('blueprint', 'section');
    $this->fieldManager->maybeAutoEnable('blueprint', 'section');
    $this->assertFalse($this->fieldManager->isEnabled('blueprint', 'section'));
    $this->assertArrayNotHasKey('style section blueprint elements', $this->container->get('user.permissions')->getPermissions());
  }

}
