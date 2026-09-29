<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\PageEdit;
use Drupal\daedalus_blueprint_test\TestFileDeclarationHandler;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Page_edit admits exactly the page language's op set.
 *
 * The gate reads the registry (every handler's declared page ops), the
 * same source the cached catalog teaches from, so a JSON-door op such as
 * add_section is refused with the whole set named and the batch never
 * reaches the spine. Without the stencil module the set is the six DOM
 * ops and the file declaration; StencilGestureAliasTest pins the set with
 * the stencil gestures beside them.
 */
#[CoversClass(PageEdit::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class PageEditGateTest extends BlueprintKernelTestBase {

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
   * The host page.
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
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'file',
      'daedalus_ui',
      'daedalus_layout',
      'daedalus_blueprint_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();

    $section = $this->createSection('eb_test_container');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', (new PhpUuid())->generate());
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'layout_builder__layout' => [$section],
    ]);
    $this->entity->save();
  }

  /**
   * Runs an AI tool by function name and returns its readable output.
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
   * A JSON-door op is refused with the page language's whole op set.
   */
  public function testJsonDoorOpIsRefusedWithTheOpSet(): void {
    $output = $this->runTool('page_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => $this->entity->id(),
      'ops' => [
        ['op' => 'set', 'ref' => 'root', 'attrs' => ['name' => 'Renamed']],
        [
          'op' => 'add_section',
          'location' => ['anchor' => $this->entity->uuid(), 'placement' => 'inside'],
          'layout' => 'eb_test_container',
        ],
      ],
    ]);

    $this->assertStringContainsString('op 1 ("add_section") is not in the page edit grammar', $output);
    $this->assertStringContainsString('insert, replace, remove, move, set, style, file', $output);
    $this->assertStringContainsString('detach', $output);
    // The batch never ran: op 0's rename did not land.
    $this->entityTypeManager->getStorage('entity_test')->resetCache();
    $this->assertSame('Host', $this->entityTypeManager->getStorage('entity_test')->load($this->entity->id())->get('name')->value);
  }

  /**
   * The file declaration is a page-language op: the gate admits it.
   *
   * The batch reaches the spine with the tool's conversation, the seam
   * receives the declaration, and the rename beside it lands.
   */
  public function testFileOpIsAdmitted(): void {
    TestFileDeclarationHandler::$declared = [];
    $this->container->get('daedalus_blueprint_ai.conversation_context')->set('thread-gate');
    $output = $this->runTool('page_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => $this->entity->id(),
      'ops' => [
        [
          'op' => 'file',
          'name' => 'logo.svg',
          'type' => 'image/svg+xml',
          'content' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        ],
        ['op' => 'set', 'ref' => 'root', 'attrs' => ['name' => 'Renamed']],
      ],
      'save' => TRUE,
    ]);

    $this->assertStringNotContainsString('is not in the page edit grammar', $output);
    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertSame([['thread-gate', 'logo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>']], TestFileDeclarationHandler::$declared);
    $this->entityTypeManager->getStorage('entity_test')->resetCache();
    $this->assertSame('Renamed', $this->entityTypeManager->getStorage('entity_test')->load($this->entity->id())->label());
  }

}
