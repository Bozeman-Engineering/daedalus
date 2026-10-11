<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_tempstore\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests staging a page of a non-overridable bundle beside its template.
 *
 * Such a page renders its display's document and owns none, so it stages as
 * an entity while the display stages as its defaults storage: neither stage
 * answers for, or overwrites, the other.
 */
#[Group('daedalus_tempstore')]
#[RunTestsInSeparateProcesses]
class PlainHostStagingTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    'daedalus_tempstore',
    'entity_test',
  ];

  /**
   * Tests that a page and its staged template keep separate stages.
   */
  public function testPageStagesApartFromItsTemplate(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->setUpCurrentUser();
    $repository = $this->container->get('daedalus_tempstore.entity_tempstore_repository');

    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->save();
    $entity = EntityTest::create(['name' => 'saved']);
    $entity->save();

    // The template is staged, as a developer's unsaved edit would leave it.
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $saved_sections = count($display->getSections());
    $display->appendSection(new Section('layout_onecol'));
    $repository->set($display);

    $this->assertFalse($repository->has($entity), 'A staged template is not a stage of its pages.');
    $this->assertSame($entity, $repository->get($entity), 'A get by page answers the page, never the staged display.');

    $edited = clone $entity;
    $edited->set('name', 'staged');
    $repository->set($edited);

    $staged = $repository->get(EntityTest::load($entity->id()));
    $this->assertInstanceOf(EntityTest::class, $staged);
    $this->assertSame('staged', $staged->label(), 'The page stages its own edit.');
    $staged_display = $repository->get(LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default'));
    $this->assertCount($saved_sections + 1, $staged_display->getSections(), 'Staging the page leaves the staged template as it was.');
  }

}
