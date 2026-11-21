<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_tempstore\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\Entity\EntityTestMul;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
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
    'filter',
    'daedalus_tempstore',
    'entity_test',
    'language',
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

  /**
   * Tests that every translation of a host addresses the host's one stage.
   *
   * A writer stages the entity as storage loads it; a route hands the
   * entity in the language of its URL. Both find, replace and delete the
   * same unsaved copy, and a get() answers it in the language asked with.
   * The same holds for a host that stages as its overrides storage.
   */
  public function testTranslationsShareOneStage(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test_mul');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->setUpCurrentUser();
    $repository = $this->container->get('daedalus_tempstore.entity_tempstore_repository');
    $selector = $this->container->get('daedalus_tempstore.strategy_selector');

    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_mul',
      'bundle' => 'entity_test_mul',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->save();

    $strategies = [];
    foreach (['plain', 'overridable'] as $kind) {
      if ($kind === 'overridable') {
        $display->setOverridable()->save();
      }
      $entity = EntityTestMul::create(['name' => 'saved']);
      $entity->addTranslation('fr', ['name' => 'enregistré']);
      $entity->save();
      $stored = EntityTestMul::load($entity->id());
      $french = $stored->getTranslation('fr');

      $strategy = $selector->getStrategy($stored);
      $strategies[$kind] = $strategy::class;
      $this->assertSame($strategy->getKey($stored), $strategy->getKey($french), "$kind: a translation keys the stage its host keys.");

      // The writer's road: the entity as storage loads it, both languages
      // edited on the one working copy.
      $edited = clone $stored;
      $edited->set('name', 'staged');
      $edited->getTranslation('fr')->set('name', 'en attente');
      $repository->set($edited);

      $this->assertTrue($repository->has($french), "$kind: the translation finds the host's stage.");
      $staged = $repository->get($french);
      $this->assertSame('fr', $staged->language()->getId(), "$kind: the unsaved copy is answered in the language asked with.");
      $this->assertSame('en attente', $staged->label());
      $this->assertSame('staged', $repository->get($stored)->label());

      // The route's road: a write and a discard by the translation land on
      // the same stage.
      $staged->set('name', 'encore');
      $repository->set($staged);
      $this->assertSame('encore', $repository->get($stored)->getTranslation('fr')->label(), "$kind: a stage by the translation replaces the host's stage.");
      $this->assertSame('staged', $repository->get($stored)->label());

      $repository->delete($french);
      $this->assertFalse($repository->has($stored), "$kind: a discard by the translation ends the host's stage.");
      $this->assertSame('saved', $repository->get($stored)->label());
    }
    $this->assertNotSame($strategies['plain'], $strategies['overridable'], 'Both strategies were walked.');
  }

}
