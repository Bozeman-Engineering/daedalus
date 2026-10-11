<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\block_content\Entity\BlockContent;
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
 * reaches the spine. Without the blueprint module the set is the six DOM
 * ops and the file declaration; BlueprintGestureAliasTest pins the set with
 * the blueprint gestures beside them.
 *
 * The echo of an admitted batch is the read's envelope: the document
 * always, and the page's own fields before it when the batch wrote them.
 *
 * A set names its fields, so a write beside a value the page language
 * cannot spell leaves that value where it is: the page tools write every
 * page, which is what keeps the JSON doors closed on one.
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
   * The uuid of the host page's one container.
   */
  protected string $sectionUuid;

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
      // A declared text block type's tag is a list_string and its link a
      // link field; neither type exists without its module.
      'options',
      'link',
    ]);
    $this->installEntitySchema('block_content');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();

    $section = $this->createSection('eb_test_container');
    $this->sectionUuid = (new PhpUuid())->generate();
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->sectionUuid);
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

  /**
   * A root set echoes the page's own fields before the document.
   *
   * The document is unchanged by a write to the page's own fields, so
   * without the block the echo gives the model nothing to verify the
   * write against. The block is the one page_read prints.
   */
  public function testRootSetEchoesThePagesFields(): void {
    $output = $this->runTool('page_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => $this->entity->id(),
      'ops' => [
        ['op' => 'set', 'ref' => 'root', 'attrs' => ['name' => 'Renamed']],
      ],
    ]);

    $this->assertStringNotContainsString('Error', $output);
    $this->assertMatchesRegularExpression('/\n\nUpdated page:\nfields:\n(  .*\n)+<pb-container/', $output, 'The fields block stands between the heading and the document.');
    $this->assertStringContainsString("\n  name: Renamed\n", $output);
    $this->assertSame(1, substr_count($output, 'fields:'));
  }

  /**
   * A batch that leaves the page's own fields alone echoes the document only.
   */
  public function testElementEditEchoesNoFields(): void {
    $output = $this->runTool('page_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => $this->entity->id(),
      'ops' => [
        ['op' => 'set', 'ref' => $this->sectionUuid, 'attrs' => ['name' => 'Band']],
      ],
    ]);

    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString("\n\nUpdated page:\n<pb-container", $output);
    $this->assertStringNotContainsString('fields:', $output);
  }

  /**
   * A set beside what the print cannot spell leaves it on the block.
   *
   * Run, not read. A declared text block whose link holds a title beside
   * its uri (the print spells the bare uri and names the attribute in
   * data-partial) and an undeclared card block whose multi-value field
   * has no spelling at all (data-unprinted) share one page. A set of the
   * text block's tag, saved, reports success and the database, echoes the
   * reading notes once and both facts still on their blocks after the
   * write, and the text block loaded back from the database holds the new
   * tag with the link's uri and title intact; the card block's component
   * still points at the revision holding both of its values.
   *
   * With saved content only these two forms carry an unspelled value: a
   * declared type cannot hold a multi-value field (deriveContract() leaves
   * the whole type generic, and a generic block's set spells no field),
   * and a link with a title and no uri is an empty item core drops on save.
   */
  public function testSetKeepsWhatThePrintCannotSpell(): void {
    $this->createBlockContentType('text', 'Text');
    $this->createBlockField('field_text_tag', 'list_string', 'text', ['allowed_values' => ['h2' => 'h2', 'h3' => 'h3']]);
    $this->createBlockField('field_text_text', 'string_long', 'text');
    $this->createBlockField('field_text_link', 'link', 'text');
    $this->createBlockContentType('card', 'Card');
    $this->createBlockField('field_card_items', 'string', 'card', ['max_length' => 255], ['cardinality' => -1]);
    $text = BlockContent::create([
      'type' => 'text',
      'info' => 'Text',
      'field_text_tag' => 'h2',
      'field_text_text' => 'About',
      'field_text_link' => ['uri' => 'internal:/about', 'title' => 'About us'],
    ]);
    $text->save();
    $card = BlockContent::create(['type' => 'card', 'info' => 'Card', 'field_card_items' => ['Plans', 'Pricing']]);
    $card->save();
    $text_uuid = '7a1e2b3c-0000-4000-8000-000000000021';
    $card_uuid = '7a1e2b3c-0000-4000-8000-000000000022';
    $section = $this->createSection('eb_test_container', [
      ['uuid' => $text_uuid, 'weight' => 0, 'configuration' => $this->createInlineBlockConfiguration('text', $text)],
      ['uuid' => $card_uuid, 'weight' => 1, 'configuration' => $this->createInlineBlockConfiguration('card', $card)],
    ], [], ['daedalus_layout' => ['uuid' => (new PhpUuid())->generate()]]);
    $page = $this->createTestEntity(['name' => 'Unspellable', 'layout_builder__layout' => [$section]]);

    $output = $this->runTool('page_edit', [
      'entity_type' => 'entity_test',
      'entity_id' => (string) $page->id(),
      'save' => TRUE,
      'ops' => [
        ['op' => 'set', 'ref' => $text_uuid, 'attrs' => ['tag' => 'h3']],
      ],
    ]);

    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString('success: true', $output);
    $this->assertStringContainsString('persisted: database', $output);
    $this->assertStringNotContainsString('fields:', $output, 'The batch left the page\'s own fields alone.');
    $this->assertStringContainsString("\n\nUpdated page:\nReading notes:\n- ", $output, 'The echo heads with the reading notes.');
    $this->assertSame(1, substr_count($output, 'Reading notes:'), 'The notes head the echo once.');
    $this->assertStringContainsString("\n- data-unprinted names fields the element holds that the page language cannot spell: a set on the element keeps them, a replace rebuilds the element and loses them.\n", $output);
    $this->assertStringContainsString("\n- data-partial names printed attributes whose stored value holds more than the print shows (a link's title or options): a set on that attribute replaces the whole stored value with what you write.\n\n<pb-container", $output, 'The partial-field sentence closes the notes; a blank line separates them from the document.');
    $this->assertMatchesRegularExpression('/\n  <pb-text id="' . preg_quote($text_uuid, '/') . '"( data-can="[^"]*")? data-partial="link"( [a-z]+="[^"]*")*>About<\/pb-text>\n/', $output, 'The written block still names its partial attribute.');
    preg_match('/<pb-text id="' . preg_quote($text_uuid, '/') . '"[^>]*>/', $output, $element);
    $this->assertStringContainsString(' tag="h3"', $element[0], 'The echo shows the new tag.');
    $this->assertStringContainsString(' link="/about"', $element[0], 'The link still prints as its bare uri.');
    $this->assertStringNotContainsString('About us', $output, 'The title is named, never printed.');
    $this->assertMatchesRegularExpression('/\n  <pb-block id="' . preg_quote($card_uuid, '/') . '"( data-can="[^"]*")? data-unprinted="field_card_items" type="card" \/>\n/', $output, 'The undeclared block still names its unspellable field.');
    $this->assertStringNotContainsString('Plans', $output, 'The unspellable values are named, never printed.');

    $storage = $this->entityTypeManager->getStorage('entity_test');
    $storage->resetCache();
    $section = $storage->load($page->id())->get('layout_builder__layout')->getSections()[0];
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    $block_storage->resetCache();
    $configuration = $section->getComponent($text_uuid)->get('configuration');
    $this->assertNotEmpty($configuration['block_revision_id'], 'The written block was saved as an entity.');
    $this->assertArrayNotHasKey('block_serialized', array_filter($configuration), 'Nothing is left staged on the component.');
    $written = $block_storage->loadRevision($configuration['block_revision_id']);
    $this->assertSame('h3', $written->get('field_text_tag')->value, 'The set landed.');
    $this->assertSame('About', $written->get('field_text_text')->value);
    $this->assertSame('internal:/about', $written->get('field_text_link')->uri, 'The unnamed field keeps its uri.');
    $this->assertSame('About us', $written->get('field_text_link')->title, 'The unnamed field keeps the title the print cannot spell.');
    $card_configuration = $section->getComponent($card_uuid)->get('configuration');
    $this->assertNotEmpty($card_configuration['block_revision_id']);
    $this->assertSame(['Plans', 'Pricing'], array_column($block_storage->loadRevision($card_configuration['block_revision_id'])->get('field_card_items')->getValue(), 'value'), 'The block beside the write keeps the values the print cannot spell.');
  }

}
