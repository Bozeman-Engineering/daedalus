<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that AI-hidden entity types are refused, not merely unlisted.
 *
 * Hiding a type or bundle from the AI (daedalus_blueprint_ai.settings or
 * hook_daedalus_blueprint_ai_bundle_list_alter()) must remove the AI's whole
 * surface for it: an AI told a hidden type's name directly (by the user, or
 * from stale context) would otherwise read and write it anyway, because the
 * bundle list only fed eb_list_bundles. These tests prove the enforcement
 * seam (BundleListBuilder::isHidden() via the tool base guards) refuses
 * hidden targets across the read, create, validate, and load paths, with
 * the same wording eb_list_bundles uses for unknown types.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class HiddenTypeToolGateTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
  ];

  /**
   * Runs a tool by function name and returns its readable output.
   *
   * @param string $function_name
   *   The AI function name (e.g. eb_read_entity).
   * @param array $contexts
   *   Context values; arrays pass natively as the primary structured
   *   transport delivers them.
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
   * A hidden entity type is refused on every tool path.
   */
  public function testHiddenEntityTypeRefused(): void {
    $entity = $this->createTestEntity(['name' => 'Reachable']);

    // Baseline: the type is visible and the read path works.
    $output = $this->runTool('eb_read_entity', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
    ]);
    $this->assertStringContainsString('Reachable', $output);

    \Drupal::configFactory()
      ->getEditable('daedalus_blueprint_ai.settings')
      ->set('hidden_entity_types', ['entity_test'])
      ->save();

    $refusal = 'Unknown or hidden entity type: entity_test.';

    // The load path (eb_read_entity, and through the same loadEntity guard
    // every element tool).
    $this->assertSame($refusal, $this->runTool('eb_read_entity', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
    ]));

    // The schema path: a hidden type's shape is as private as its content.
    $this->assertSame($refusal, $this->runTool('eb_read_schema', [
      'entity_type' => 'entity_test',
    ]));

    // The create and validate paths.
    $this->assertSame($refusal, $this->runTool('eb_create_entity', [
      'blueprint' => ['entity_type' => 'entity_test', 'fields' => ['name' => 'Nope']],
    ]));
    $this->assertSame($refusal, $this->runTool('eb_validate', [
      'blueprint' => ['entity_type' => 'entity_test', 'fields' => ['name' => 'Nope']],
    ]));

    // The mutation path (eb_edit) rides loadAnyEntity: same guard, same
    // refusal.
    $this->assertSame($refusal, $this->runTool('eb_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $entity->id(),
      'operations' => [['op' => 'update_element', 'properties' => ['name' => 'Nope']]],
    ]));
  }

  /**
   * A hidden bundle of a visible type is refused after load.
   */
  public function testHiddenBundleRefused(): void {
    $this->enableModules(['block_content']);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->createBlockContentType('hero', 'Hero');
    $this->createBlockContentType('plain', 'Plain');

    $storage = $this->entityTypeManager->getStorage('block_content');
    $hidden = $storage->create(['type' => 'plain', 'info' => 'Hidden one']);
    $hidden->save();
    $visible = $storage->create(['type' => 'hero', 'info' => 'Visible one']);
    $visible->save();

    \Drupal::configFactory()
      ->getEditable('daedalus_blueprint_ai.settings')
      ->set('hidden_bundles', ['block_content' => ['plain']])
      ->save();

    // The sibling bundle stays fully reachable: hiding is bundle-precise.
    $output = $this->runTool('eb_read_entity', [
      'entity_type' => 'block_content',
      'entity_id' => (string) $visible->id(),
    ]);
    $this->assertStringContainsString('Visible one', $output);

    $this->assertSame('Unknown or hidden bundle: block_content/plain.', $this->runTool('eb_read_entity', [
      'entity_type' => 'block_content',
      'entity_id' => (string) $hidden->id(),
    ]));

    // The schema path refuses the hidden bundle by name.
    $this->assertSame('Unknown or hidden bundle: block_content/plain.', $this->runTool('eb_read_schema', [
      'entity_type' => 'block_content',
      'bundle' => 'plain',
    ]));
  }

}
