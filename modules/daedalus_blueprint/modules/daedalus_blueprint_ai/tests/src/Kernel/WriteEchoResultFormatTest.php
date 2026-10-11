<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the write echo on the mutating AI tools' result format.
 *
 * Mutating tool results carry the resolved markup of affected targets,
 * projected through the markup-projection socket, so the model verifies
 * its write in the same turn instead of opening a read-back loop. The
 * page builder's real projector answers here (daedalus_ui rides in
 * with daedalus_layout, as in the sibling composition test), so these tests lock
 * the RESULT FORMAT end to end: component targets echo their own box,
 * root-entity targets echo the page, removed targets echo a comment
 * naming the UUID, and nested targets echo once inside their containing
 * projection. The projection's content details are locked by the page
 * builder's own suite; the no-projector silence is locked by
 * WriteEchoWithoutProjectorTest, where the socket really stands empty.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class WriteEchoResultFormatTest extends BlueprintKernelTestBase {

  /**
   * The legend's first words, present exactly when an echo was emitted.
   */
  protected const ECHO_LEGEND = 'Key-annotated markup';

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
   * The daedalus_layout UUID of the root section components are placed into.
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
   * Runs an AI tool by function name and returns its readable output.
   *
   * @param string $function_name
   *   The tool's function name (e.g. eb_edit).
   * @param array $contexts
   *   Tool parameters. Array values pass natively, matching the primary
   *   structured transport.
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
   * Places a basic block into the root section, saved to the database.
   *
   * @param string $uuid
   *   The writer-minted component UUID.
   * @param string $heading
   *   The heading field value.
   */
  protected function placeBlock(string $uuid, string $heading): void {
    $output = $this->runTool('eb_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $this->entity->id(),
      'save' => TRUE,
      'operations' => [
        [
          'op' => 'add_element',
          'block_type' => 'basic',
          'uuid' => $uuid,
          'location' => ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
          'properties' => ['field_heading' => $heading],
        ],
      ],
    ]);
    $this->assertStringNotContainsString('Error', $output);
  }

  /**
   * Tests a component write echoes that component's markup, page excluded.
   */
  public function testUpdateElementEchoesComponentMarkup(): void {
    $component_uuid = (new PhpUuid())->generate();
    $this->placeBlock($component_uuid, 'First draft');

    $output = $this->runTool('eb_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $this->entity->id(),
      'save' => TRUE,
      'operations' => [
        [
          'op' => 'update_element',
          'element' => $component_uuid,
          'properties' => ['field_heading' => 'Fresh heading'],
        ],
      ],
    ]);

    $this->assertStringContainsString('success: true', $output);
    $this->assertStringContainsString(self::ECHO_LEGEND, $output);
    // The echoed markup is the affected box, carrying the written value.
    $this->assertStringContainsString('data-key="block:' . $component_uuid . '"', $output);
    $this->assertStringContainsString('Fresh heading', $output);
    // A component write echoes the component, never the whole page.
    $this->assertStringNotContainsString('data-key="entity:', $output);
  }

  /**
   * Tests a root-entity write echoes the whole page's projection.
   */
  public function testRootFieldUpdateEchoesEntityProjection(): void {
    $output = $this->runTool('eb_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $this->entity->id(),
      'save' => TRUE,
      'operations' => [
        [
          'op' => 'update_element',
          'properties' => ['name' => 'Renamed host'],
        ],
      ],
    ]);

    $this->assertStringContainsString('success: true', $output);
    $this->assertStringContainsString(self::ECHO_LEGEND, $output);
    $this->assertStringContainsString('data-key="entity:' . $this->entity->uuid() . '"', $output);
    $this->assertStringContainsString('data-key="container:' . $this->rootSectionUuid . '"', $output);
  }

  /**
   * Tests a removal echoes a comment naming the UUID, never markup.
   */
  public function testRemoveElementEchoesRemovalComment(): void {
    $component_uuid = (new PhpUuid())->generate();
    $this->placeBlock($component_uuid, 'Doomed');

    $output = $this->runTool('eb_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $this->entity->id(),
      'save' => TRUE,
      'operations' => [
        [
          'op' => 'remove_element',
          'element' => $component_uuid,
        ],
      ],
    ]);

    $this->assertStringContainsString('success: true', $output);
    $this->assertStringContainsString(self::ECHO_LEGEND, $output);
    $this->assertStringContainsString(
      '<!-- ' . $component_uuid . ': removed, no longer on the page -->',
      $output,
    );
    $this->assertStringNotContainsString('data-key="block:' . $component_uuid . '"', $output);
  }

  /**
   * Tests nested targets echo once, inside their containing projection.
   *
   * A batch touching the root entity and a component inside it must not
   * print the component twice: the root projection already carries it
   * where it mounted.
   */
  public function testNestedTargetsEchoOnce(): void {
    $component_uuid = (new PhpUuid())->generate();

    $output = $this->runTool('eb_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $this->entity->id(),
      'save' => TRUE,
      'operations' => [
        [
          'op' => 'add_element',
          'block_type' => 'basic',
          'uuid' => $component_uuid,
          'location' => ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
          'properties' => ['field_heading' => 'Composed heading'],
        ],
        [
          'op' => 'update_element',
          'properties' => ['name' => 'Composed host'],
        ],
      ],
    ]);

    $this->assertStringContainsString('success: true', $output);
    $this->assertStringContainsString(self::ECHO_LEGEND, $output);
    $this->assertSame(1, substr_count($output, 'data-key="entity:'));
    $this->assertSame(1, substr_count($output, 'data-key="block:' . $component_uuid . '"'));
    $this->assertStringContainsString('Composed heading', $output);
  }

  /**
   * Tests entity creation echoes the whole entity: it IS the affected target.
   *
   * The JSON create is the non-page door (a page bundle is refused toward
   * page_create, pinned in PageLanguageDoorsTest), so the entity born here
   * is entity_test_rev, whose display has no Layout Builder; the whole
   * entity's projection is still the echo of what the tool created.
   */
  public function testCreateEntityEchoesWholePage(): void {
    $output = $this->runTool('eb_create_entity', [
      'blueprint' => [
        'entity_type' => 'entity_test_rev',
        'bundle' => 'entity_test_rev',
        'fields' => ['name' => 'Fresh entity'],
      ],
    ]);

    $this->assertStringContainsString('success: true', $output);
    $this->assertStringContainsString(self::ECHO_LEGEND, $output);
    $this->assertStringContainsString('data-key="entity:', $output);
  }

}
