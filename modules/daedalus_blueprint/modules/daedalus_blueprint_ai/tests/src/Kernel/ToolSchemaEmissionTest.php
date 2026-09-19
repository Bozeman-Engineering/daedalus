<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Locks the emitted tool schema for the structured (map) parameters.
 *
 * The eb tools' JSON-carrying parameters travel as native structured
 * values, not JSON-in-a-string: the generic map-to-object rewrite
 * (daedalus_blueprint_ai_ai_tools_property_alter()) declares each
 * blueprint parameter as an open object, and eb_edit's plugin-local
 * normalize() override reshapes operations as an array of open objects.
 * These assertions pin the normalize() output (the exact array shipped
 * to providers) so a regression back to string transport, a dropped
 * additionalProperties, or a broken description-append seam fails
 * loudly instead of resurfacing as provider-side "Invalid JSON" churn.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class ToolSchemaEmissionTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency.
   * daedalus_blueprint_test registers the test_set_name operation handler,
   * so the description-append seam has a module-registered op to emit.
   * The config stack rides in for eb_config_create, the fourth
   * structured parameter.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
    'daedalus_blueprint_test',
    'daedalus_blueprint_config',
    'daedalus_blueprint_config_ai',
  ];

  /**
   * Tests that the blueprint parameters emit as open objects.
   *
   * Covers eb_create_entity.blueprint, eb_validate.blueprint, and
   * eb_config_create.blueprint: each must reach the wire as
   * type: object with additionalProperties: true (the interior stays
   * free-form; the doctrine lives in the description), and stay
   * required.
   */
  public function testBlueprintParametersEmitAsOpenObjects(): void {
    foreach (['eb_create_entity', 'eb_validate', 'eb_config_create'] as $function_name) {
      $parameters = $this->getEmittedParameters($function_name);
      $this->assertArrayHasKey('blueprint', $parameters['properties'], "$function_name emits a blueprint parameter.");
      $blueprint = $parameters['properties']['blueprint'];
      $this->assertSame('object', $blueprint['type'], "$function_name.blueprint is a native object on the wire.");
      $this->assertTrue($blueprint['additionalProperties'], "$function_name.blueprint stays an open object.");
      $this->assertContains('blueprint', $parameters['required'], "$function_name.blueprint stays required.");
    }
  }

  /**
   * Tests that eb_edit.operations emits as an array of open objects.
   *
   * The plugin-local normalize() override reshapes the map param:
   * type array, items an open object, and the object-level
   * additionalProperties the generic alter added is removed (it is
   * meaningless on an array and some providers reject it there).
   */
  public function testOperationsEmitsAsArrayOfOpenObjects(): void {
    $parameters = $this->getEmittedParameters('eb_edit');
    $operations = $parameters['properties']['operations'];
    $this->assertSame('array', $operations['type'], 'eb_edit.operations is a native array on the wire.');
    $this->assertSame(['type' => 'object', 'additionalProperties' => TRUE], $operations['items'], 'Each operation is an open object.');
    $this->assertArrayNotHasKey('additionalProperties', $operations, 'The object-level additionalProperties does not leak onto the array.');
    $this->assertContains('operations', $parameters['required'], 'eb_edit.operations stays required.');
  }

  /**
   * Tests that the description-append seam survives into the emission.
   *
   * Entity_blueprint_ai_ai_function_call_info_alter() appends the
   * module-registered op roll-call to the operations description; the
   * structured reshape must not detach it from what providers see.
   */
  public function testDescriptionAppendReachesTheEmission(): void {
    $parameters = $this->getEmittedParameters('eb_edit');
    $description = $parameters['properties']['operations']['description'];
    $this->assertStringContainsString('Module-registered ops: ', $description, 'The module-registered roll-call rides the emitted operations description.');
    $this->assertStringContainsString('test_set_name (', $description, 'The registered op arrives in "op (router line)" form. (Membership, not leading position: the DOM ops precede it in the registry.)');
  }

  /**
   * Tests the page-language site shape reaches the wire slimmed.
   *
   * With a container layout discoverable (daedalus_blueprint_test's
   * eb_test_container via layout_discovery), the alter recomposes the
   * operations description: the emission keeps the array-of-open-objects
   * transport and the description-append seam, but carries the root-field
   * grammar and config ops instead of the layout grammar, and the
   * roll-call drops the page-only DOM ops while keeping test_set_name.
   */
  public function testPageLanguageSiteEmissionDropsTheLayoutGrammar(): void {
    $this->enableModules(['layout_discovery']);
    $this->container->get('plugin.manager.ai.function_calls')->clearCachedDefinitions();

    $parameters = $this->getEmittedParameters('eb_edit');
    $operations = $parameters['properties']['operations'];
    $this->assertSame('array', $operations['type'], 'eb_edit.operations stays a native array on the wire.');
    $this->assertSame(['type' => 'object', 'additionalProperties' => TRUE], $operations['items'], 'Each operation stays an open object.');
    $this->assertContains('operations', $parameters['required'], 'eb_edit.operations stays required.');

    $description = $operations['description'];
    $this->assertStringContainsString('Config ops: update_properties, add_plugin, update_plugin, remove_plugin, reorder_plugins.', $description);
    $this->assertStringContainsString('update_element', $description);
    $this->assertStringNotContainsString('add_section', $description, 'The layout grammar left the emitted description.');
    $this->assertStringNotContainsString('placement: before|after|inside', $description, 'The placement grammar left the emitted description.');
    $this->assertStringContainsString('Module-registered ops: ', $description, 'The append seam still reaches the emission.');
    $this->assertStringContainsString('test_set_name (', $description, 'A generic registered op still reaches the emission.');
    $this->assertStringNotContainsString('a page-language op', $description, 'The page-only DOM router lines left the emission.');
  }

  /**
   * Returns a tool's emitted parameters block, exactly as shipped.
   *
   * @param string $function_name
   *   The tool's function name (e.g. eb_edit).
   *
   * @return array
   *   The 'parameters' slice of renderFunctionArray(): the normalized
   *   JSON-Schema array providers receive.
   */
  protected function getEmittedParameters(string $function_name): array {
    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->getFunctionCallFromFunctionName($function_name);
    $this->assertNotNull($tool, "The $function_name tool is available.");
    return $tool->normalize()->renderFunctionArray()['parameters'];
  }

}
