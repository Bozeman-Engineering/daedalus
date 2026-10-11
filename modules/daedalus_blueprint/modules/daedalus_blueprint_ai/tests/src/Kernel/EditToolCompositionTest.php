<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests eb_edit's identity contract and the collapsed tool roster.
 *
 * Eb_edit is the one mutation tool: its op grammar advertises writer-minted
 * identity (an add_element op's uuid becomes the placed element's identity).
 * These tests prove the tool actually forwards the minted uuid to the spine,
 * so the guidance never advertises a parameter the tool would silently drop,
 * and that the collapse is total: the retired single-purpose mutation tools
 * (add/update/remove element, reorder_field, the
 * batch_operations spelling, and config_ai's manage_plugin_collection) are
 * gone from the roster, not merely deprecated beside it.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class EditToolCompositionTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
    'daedalus_blueprint_config_ai',
  ];

  /**
   * The daedalus_layout UUID of the root section elements are placed into.
   */
  protected string $rootSectionUuid;

  /**
   * The host entity holding the root section.
   */
  protected object $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

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
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }

    $this->enableLayoutBuilder();

    $this->createBlockContentType('basic', 'Basic');
    $this->createBlockField('field_heading', 'string', 'basic', ['max_length' => 255]);

    $this->rootSectionUuid = (new PhpUuid())->generate();
    $section = $this->createSection('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->rootSectionUuid);
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'layout_builder__layout' => [$section],
    ]);
    $this->entity->save();
  }

  /**
   * Runs eb_edit against the host entity and returns the output.
   *
   * @param array $operations
   *   The operations batch, passed natively as the primary structured
   *   transport delivers it.
   *
   * @return string
   *   The tool's readable output.
   */
  protected function editOutput(array $operations): string {
    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->getFunctionCallFromFunctionName('eb_edit');
    $this->assertNotNull($tool, 'The eb_edit tool is available.');
    $tool->setContextValue('entity_type', 'entity_test');
    $tool->setContextValue('entity_id', (string) $this->entity->id());
    $tool->setContextValue('save', TRUE);
    $tool->setContextValue('operations', $operations);
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * Tests the tool forwards the writer-minted uuid to the spine.
   */
  public function testMintedIdentityForwarded(): void {
    $element_uuid = (new PhpUuid())->generate();

    $output = $this->editOutput([
      [
        'op' => 'add_element',
        'block_type' => 'basic',
        'uuid' => $element_uuid,
        'location' => ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
        'properties' => ['field_heading' => 'Try Daedalus Stylish today'],
      ],
    ]);
    $this->assertStringNotContainsString('Error', $output);

    $reloaded = $this->entityTypeManager->getStorage('entity_test')
      ->loadUnchanged($this->entity->id());
    $adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $storage = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($reloaded)->sectionStorage();
    $component = NULL;
    foreach ($storage->getSections() as $section) {
      foreach ($section->getComponents() as $candidate) {
        if ($candidate->getUuid() === $element_uuid) {
          $component = $candidate;
        }
      }
    }
    $this->assertNotNull($component, 'The minted uuid IS the placed element identity.');
    $block = $adapter->extractBlockContent($component);
    $this->assertSame('Try Daedalus Stylish today', $block->get('field_heading')->value);
  }

  /**
   * Tests the retired single-purpose mutation tools are gone.
   *
   * The collapse is total: one mutation grammar, one tool carrying it. A
   * surviving single would be a second doc surface over the same spine —
   * exactly the drift the collapse removed.
   */
  public function testRetiredMutationToolsGone(): void {
    $definitions = $this->container->get('plugin.manager.ai.function_calls')->getDefinitions();
    $this->assertArrayHasKey('daedalus_blueprint:edit', $definitions);
    foreach ([
      'daedalus_blueprint:add_element',
      'daedalus_blueprint:update_element',
      'daedalus_blueprint:remove_element',
      'daedalus_blueprint:reorder_field',
      'daedalus_blueprint:batch_operations',
      'daedalus_blueprint_config:manage_plugin_collection',
    ] as $retired_id) {
      $this->assertArrayNotHasKey($retired_id, $definitions, "The retired tool $retired_id is gone from the roster.");
    }
  }

}
