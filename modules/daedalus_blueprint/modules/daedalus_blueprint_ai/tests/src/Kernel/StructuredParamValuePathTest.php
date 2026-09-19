<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Locks the value path of the structured (map) parameters end to end.
 *
 * The schema side is pinned by ToolSchemaEmissionTest; this test pins what
 * happens to VALUES on their way in, through setContextValue() + execute(),
 * for the three transports a model can actually send:
 * - Native array: the primary transport. The provider decodes the arguments
 *   JSON, the tool receives a nested PHP array, and the data layer hands it
 *   back raw.
 * - Stringified JSON: the compatibility fallback. Upstream drupal/ai's
 *   JsonDeserializer decodes any JSON string sent to a map param before the
 *   Map ever sees it, so old string-shaped values (e.g. replayed
 *   daedalus_ai_memory threads) keep working with no tool-side code.
 * - Garbage string: neither JSON nor a structure. Map::setValue() would
 *   throw, and the agent loop hydrates tools with no try/catch, so
 *   DaedalusBlueprintToolBase::setContextValue() converts the throw into a
 *   corrective tool result the model can re-send from. The exception must
 *   never escape.
 *
 * Both structured shapes are exercised: the object-shaped blueprint params
 * and the array-shaped eb_edit.operations (an indexed array riding the same
 * map data type). The mutation assertions reload the entity from storage:
 * transport is only proven when the value came out the other end.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class StructuredParamValuePathTest extends BlueprintKernelTestBase {

  /**
   * The map parameter of every structured tool, keyed by function name.
   *
   * The guard seam is shared (DaedalusBlueprintToolBase), but each tool must
   * call requireValidParams() first in execute() for the corrective to be
   * emitted instead of a ContextException later, so the garbage-string case
   * covers every tool individually.
   */
  protected const STRUCTURED_PARAMS = [
    'eb_create_entity' => 'blueprint',
    'eb_edit' => 'operations',
    'eb_validate' => 'blueprint',
    'eb_config_create' => 'blueprint',
  ];

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency. The
   * config stack rides in for eb_config_create, the fourth structured
   * parameter.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
    'daedalus_blueprint_config',
    'daedalus_blueprint_config_ai',
  ];

  /**
   * Tests the primary transport: native arrays travel untouched.
   */
  public function testNativeArrayIsThePrimaryTransport(): void {
    // Object-shaped param: a blueprint validates as a nested PHP array.
    $output = $this->runTool('eb_validate', [
      'blueprint' => $this->blueprint('Native host'),
    ]);
    $this->assertStringContainsString('success: true', $output);

    // Array-shaped param: an indexed operations array mutates the entity.
    $entity = $this->createTestEntity();
    $output = $this->runTool('eb_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
      'save' => TRUE,
      'operations' => [
        [
          'op' => 'update_element',
          'properties' => ['name' => 'Renamed natively'],
        ],
      ],
    ]);
    $this->assertStringContainsString('success: true', $output);
    $this->assertSame('Renamed natively', $this->reloadName($entity->id()));
  }

  /**
   * Tests the fallback transport: JSON strings decode before the tool.
   *
   * Upstream JsonDeserializer owns this branch; no daedalus_blueprint code
   * touches strings anymore. The blueprint case asserts the two transports
   * are indistinguishable by comparing whole outputs.
   */
  public function testStringifiedJsonFallsBackToTheSameResult(): void {
    $blueprint = $this->blueprint('Fallback host');
    $native_output = $this->runTool('eb_validate', ['blueprint' => $blueprint]);
    $string_output = $this->runTool('eb_validate', ['blueprint' => Json::encode($blueprint)]);
    $this->assertStringContainsString('success: true', $native_output);
    $this->assertSame($native_output, $string_output, 'A stringified blueprint validates identically to a native one.');

    $entity = $this->createTestEntity();
    $output = $this->runTool('eb_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
      'save' => TRUE,
      'operations' => Json::encode([
        [
          'op' => 'update_element',
          'properties' => ['name' => 'Renamed via string'],
        ],
      ]),
    ]);
    $this->assertStringContainsString('success: true', $output);
    $this->assertSame('Renamed via string', $this->reloadName($entity->id()));
  }

  /**
   * Tests a garbage string yields a corrective result, not an exception.
   *
   * The string is deliberately colon-free: with a colon it would YAML-parse
   * into a map and slip through as structure. As sent, it survives both
   * deserializers as a plain string, Map::setValue() throws, and the guard
   * must convert that into a corrective naming the parameter.
   */
  public function testGarbageStringEmitsCorrectiveNotException(): void {
    foreach (self::STRUCTURED_PARAMS as $function_name => $param) {
      $output = $this->runTool($function_name, [
        $param => 'rename the heading to Hello',
      ]);
      $this->assertStringContainsString(
        "Invalid value for the '$param' parameter",
        $output,
        "$function_name emits a corrective for a garbage string.",
      );
      $this->assertStringNotContainsString('success', $output, "$function_name did not proceed past the guard.");
    }
  }

  /**
   * Tests the corrective path leaves the target entity untouched.
   *
   * RequireValidParams() runs before any getContextValue(), so a malformed
   * operations value must bail before the batch pipeline ever loads the
   * entity.
   */
  public function testGarbageOperationsMutateNothing(): void {
    $entity = $this->createTestEntity(['name' => 'Untouched host']);
    $output = $this->runTool('eb_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
      'save' => TRUE,
      'operations' => 'rename the heading to Hello',
    ]);
    $this->assertStringContainsString("Invalid value for the 'operations' parameter", $output);
    $this->assertSame('Untouched host', $this->reloadName($entity->id()));
  }

  /**
   * Builds a minimal valid entity_test blueprint.
   *
   * @param string $name
   *   The entity name field value.
   *
   * @return array
   *   The blueprint array.
   */
  protected function blueprint(string $name): array {
    return [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => $name],
    ];
  }

  /**
   * Runs an AI tool by function name and returns its readable output.
   *
   * Values pass to setContextValue() exactly as given: arrays stay native,
   * strings stay strings. Each transport under test is spelled out at the
   * call site.
   *
   * @param string $function_name
   *   The tool's function name (e.g. eb_edit).
   * @param array $contexts
   *   Tool parameter values, verbatim.
   *
   * @return string
   *   The tool's readable output.
   */
  protected function runTool(string $function_name, array $contexts): string {
    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->getFunctionCallFromFunctionName($function_name);
    $this->assertNotNull($tool, "The $function_name tool is available.");
    foreach ($contexts as $name => $value) {
      $tool->setContextValue($name, $value);
    }
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * Reloads an entity_test entity from storage and returns its name.
   *
   * @param string|int $id
   *   The entity ID.
   *
   * @return string
   *   The persisted name field value.
   */
  protected function reloadName(string|int $id): string {
    $entity = $this->entityTypeManager->getStorage('entity_test')->loadUnchanged($id);
    return (string) $entity->get('name')->value;
  }

}
