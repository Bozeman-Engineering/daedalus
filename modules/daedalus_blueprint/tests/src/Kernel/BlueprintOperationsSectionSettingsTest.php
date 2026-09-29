<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests update_element on a section: label and layout_settings.
 *
 * A section's layout plugin settings (column widths and the like) patch
 * through `layout_settings` with the same contract add_section's
 * layout_settings and a block's configuration keys use: a given key is set,
 * an explicit empty value clears it, an omitted key keeps its value. The
 * label keeps its own property; it is not writable inside layout_settings.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsSectionSettingsTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   */
  protected BlueprintOperations $operations;

  /**
   * The fixture's section uuid.
   */
  protected string $sectionUuid;

  /**
   * The fixture entity: one two-column section at 50-50, labeled "Hero".
   */
  protected object $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Section uuids are daedalus_layout's third-party stamp; the locator
    // resolves them only with that module (and its dependencies) installed.
    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'file',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_layout',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->enableLayoutBuilder();
    $this->operations = $this->container->get('daedalus_blueprint.operations');

    $this->sectionUuid = (new PhpUuid())->generate();
    $section = $this->createSection('layout_twocol_section', [], [
      'label' => 'Hero',
      'column_widths' => '50-50',
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->sectionUuid);
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'layout_builder__layout' => [$section],
    ]);
    $this->entity->save();
  }

  /**
   * Runs one update_element op on the fixture section.
   */
  protected function update(array $properties): object {
    return $this->operations->batchOperations($this->entity, [
      ['op' => 'update_element', 'element' => $this->sectionUuid, 'properties' => $properties],
    ]);
  }

  /**
   * The fixture section's current layout settings.
   */
  protected function settings(): array {
    return $this->entity->get('layout_builder__layout')->get(0)->section->getLayoutSettings();
  }

  /**
   * A layout setting patches in; the label and other keys keep their values.
   */
  public function testLayoutSettingsPatched(): void {
    $result = $this->update(['layout_settings' => ['column_widths' => '33-67']]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame(['label' => 'Hero', 'column_widths' => '33-67'], $this->settings());
    $this->assertSame([['uuid' => $this->sectionUuid, 'field_name' => 'layout_settings']], $result->getTargets());
  }

  /**
   * Label and layout_settings write together, the label through its property.
   */
  public function testLabelAndLayoutSettingsTogether(): void {
    $result = $this->update([
      'label' => 'Intro',
      'layout_settings' => ['column_widths' => '67-33'],
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame(['label' => 'Intro', 'column_widths' => '67-33'], $this->settings());
    $this->assertSame([
      ['uuid' => $this->sectionUuid, 'field_name' => 'label'],
      ['uuid' => $this->sectionUuid, 'field_name' => 'layout_settings'],
    ], $result->getTargets());
  }

  /**
   * An explicit empty value clears a key; the plugin's default then rules.
   *
   * Section::getLayoutSettings() is the plugin's configuration, defaults
   * merged, so a cleared key reads as the layout's default.
   */
  public function testExplicitNullClearsKey(): void {
    $this->update(['layout_settings' => ['column_widths' => '33-67']]);
    $this->assertSame('33-67', $this->settings()['column_widths']);

    $result = $this->update(['layout_settings' => ['column_widths' => NULL]]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame(['label' => 'Hero', 'column_widths' => '50-50'], $this->settings());
  }

  /**
   * An empty layout_settings map changes nothing and still succeeds.
   */
  public function testEmptyLayoutSettingsIsNoOp(): void {
    $result = $this->update(['layout_settings' => []]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
    $this->assertSame(['label' => 'Hero', 'column_widths' => '50-50'], $this->settings());
  }

  /**
   * The label is not a layout setting: one spelling, the label property.
   */
  public function testLabelInsideLayoutSettingsRefused(): void {
    $result = $this->update(['layout_settings' => ['label' => 'Sneaky', 'column_widths' => '25-75']]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_LAYOUT_SETTINGS', $errors[0]->getCode());
    $this->assertStringEndsWith('properties.layout_settings.label', $errors[0]->getPath());
    $this->assertSame(['label' => 'Hero', 'column_widths' => '50-50'], $this->settings(), 'Nothing of a refused op lands.');
  }

  /**
   * The layout_settings property is a map, never a list or a scalar.
   */
  public function testNonMapLayoutSettingsRefused(): void {
    foreach ([['33-67'], '33-67'] as $bad) {
      $result = $this->update(['layout_settings' => $bad]);

      $this->assertFalse($result->isSuccess());
      $errors = $result->getErrors();
      $this->assertCount(1, $errors);
      $this->assertSame('INVALID_LAYOUT_SETTINGS', $errors[0]->getCode());
      $this->assertStringEndsWith('properties.layout_settings', $errors[0]->getPath());
    }
    $this->assertSame(['label' => 'Hero', 'column_widths' => '50-50'], $this->settings());
  }

  /**
   * An unknown section property names both properties a section takes.
   */
  public function testUnknownPropertyListsBoth(): void {
    $result = $this->update(['layout' => 'layout_onecol']);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('UNKNOWN_PROPERTY', $errors[0]->getCode());
    $this->assertSame(['label', 'layout_settings'], $errors[0]->getContext()['available']);
  }

}
