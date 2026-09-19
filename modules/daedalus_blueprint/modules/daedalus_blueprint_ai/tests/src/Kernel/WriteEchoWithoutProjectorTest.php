<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the write echo stays silently absent with the socket empty.
 *
 * With no markup projector installed (no page builder), a mutating tool's
 * result is the plain YAML it always was: no markup framing and no
 * unavailability corrective, because a write result is not the place to
 * advertise a missing markup mode. The read tools' markup mode owns that
 * corrective. The echo itself is locked by WriteEchoResultFormatTest,
 * where the page builder's projector answers.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class WriteEchoWithoutProjectorTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency. No
   * page builder: the markup-projection socket stands empty.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
  ];

  /**
   * Tests a successful write emits no echo and no corrective.
   */
  public function testEchoAbsentWithoutProjector(): void {
    $entity = $this->container->get('entity_type.manager')
      ->getStorage('entity_test')
      ->create(['name' => 'Host']);
    $entity->save();

    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->getFunctionCallFromFunctionName('eb_edit');
    $this->assertNotNull($tool, 'The eb_edit tool is available.');
    $tool->setContextValue('entity_type', 'entity_test');
    $tool->setContextValue('entity_id', (string) $entity->id());
    $tool->setContextValue('save', TRUE);
    $tool->setContextValue('operations', '[{"op": "update_element", "properties": {"name": "Renamed host"}}]');
    $tool->execute();
    $output = $tool->getReadableOutput();

    $this->assertStringContainsString('success: true', $output);
    $this->assertStringNotContainsString('Key-annotated markup', $output);
    $this->assertStringNotContainsString('Markup mode is unavailable', $output);
  }

}
