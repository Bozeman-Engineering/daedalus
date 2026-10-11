<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\PageRead;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The page_read tool: a page's fields before its document; a blueprint is one.
 *
 * One read tool, one print grammar. A page read heads the document with
 * the page's own fields as one block (the serializer's fields map in the
 * tools' array format, form-only stubs stripped), so nothing a page knows
 * is unreadable through the page tools. A saved blueprint is a page of
 * its own: the same read, addressed as entity_type blueprint and its
 * entity id, prints its face (label, description, scope, subject) as the
 * fields block and its composition as the document WITH ids, the way the
 * canvas edits it; there is no blueprint-shaped read, and the roster's
 * id-less fragment is the schema's to print. A call naming no host is
 * refused with the one shape.
 */
#[CoversClass(PageRead::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class PageReadTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency;
   * daedalus_blueprint_test ships the eb_test_container layout the real
   * catalog builder derives containerLayoutId from; the materials test
   * provider serves the hero_glow class variant the fixture reference
   * records wear; the blueprint entity's icon references media and its
   * scope is a list_string, so the library needs the media stack and
   * options installed.
   */
  protected static $modules = [
    'key',
    'ai',
    'options',
    'file',
    'image',
    'media',
    'daedalus_blueprint_ai',
    'daedalus_blueprint_test',
    'daedalus_blueprint_materials_test',
    'daedalus_blueprint_library',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The real blueprint provider teaches the blueprint gesture here; the
    // fixture keeps its swatch gesture only (two providers teaching one
    // kind is refused by the roster builder).
    TestMaterialsProvider::$gestureLines = [MaterialKind::Swatch->value => 'value'];
    $this->enableLayoutBuilder();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['image', 'file', 'media', 'daedalus_blueprint_library']);

    // A declared text block type: tag (list_string) and a plain body, so
    // the print carries <pb-text tag="h2">...</pb-text>.
    $this->createBlockContentType('text', 'Text');
    $this->createBlockField('field_text_tag', 'list_string', 'text', ['allowed_values' => ['h2' => 'h2', 'h3' => 'h3']]);
    $this->createBlockField('field_text_text', 'string_long', 'text');

    // A saved blueprint for entity_test hosts whose document, as a capture
    // leaves it, is one container-layout section holding two text blocks
    // (saved block_content entities, the content persistence a blueprint
    // shares with a page).
    $title = BlockContent::create(['type' => 'text', 'info' => 'Text', 'field_text_tag' => 'h2', 'field_text_text' => 'Card title']);
    $title->save();
    $link = BlockContent::create(['type' => 'text', 'info' => 'Text', 'field_text_text' => 'Read more & learn']);
    $link->save();
    $section = $this->createSection('eb_test_container', [
      ['configuration' => $this->createInlineBlockConfiguration('text', $title), 'weight' => 0],
      ['configuration' => $this->createInlineBlockConfiguration('text', $link), 'weight' => 1],
    ], ['label' => 'Card'], ['daedalus_layout' => ['uuid' => $this->container->get('uuid')->generate()]]);
    Blueprint::create([
      'type' => 'section',
      'label' => 'Card',
      'machine_name' => 'card',
      'description' => 'A titled card with a link.',
      'scope' => 'block',
      'subject_type' => 'entity_test',
      'subject_bundle' => 'entity_test',
      'status' => 1,
      'layout_builder__layout' => [$section],
    ])->save();
  }

  /**
   * Runs page_read with the given contexts and returns its output.
   */
  protected function read(array $contexts): string {
    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->getFunctionCallFromFunctionName('page_read');
    $this->assertNotNull($tool, 'The page_read tool is available.');
    foreach ($contexts as $name => $value) {
      $tool->setContextValue($name, $value);
    }
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * A page that owns a document and carries field values beside empty fields.
   *
   * The name, subtitle and count hold values; every other test field is
   * empty, so FULL serialization gives it a form-only stub.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved page.
   */
  protected function createFieldedPage(): FieldableEntityInterface {
    $section = $this->createSection('eb_test_container');
    $page = $this->createTestEntity([
      'name' => 'Fielded page',
      'field_subtitle' => 'A subtitle',
      'field_count' => 3,
      'layout_builder__layout' => [$section],
    ]);
    $this->assertTrue($this->host($page)->hasDocument(), 'The page owns its document.');
    $this->assertTrue($page->get('field_email')->isEmpty(), 'An empty field stands beside the filled ones.');
    return $page;
  }

  /**
   * A page's own fields ride as one block before the document print.
   *
   * The block is the serializer's fields map in the tools' array format,
   * the map page_create takes, so what is read is what is written; the
   * document follows it as the per-turn print spells it.
   */
  public function testPageFieldsPrecedeThePrint(): void {
    $page = $this->createFieldedPage();
    $output = $this->read(['entity_type' => 'entity_test', 'entity_id' => (string) $page->id()]);

    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringStartsWith("fields:\n", $output, 'The fields block heads the read.');
    $this->assertStringContainsString("\n  name: 'Fielded page'\n", $output);
    $this->assertStringContainsString("\n  field_subtitle: 'A subtitle'\n", $output);
    $this->assertStringContainsString("\n  field_count: '3'\n", $output);
    $this->assertMatchesRegularExpression('/^fields:\n(  .*\n)+<pb-container>\n/', $output, 'The block ends where the document begins.');
    $this->assertStringEndsWith("</pb-container>\n", $output);
    $this->assertSame(1, substr_count($output, 'fields:'), 'One block; the fields do not ride inside the print.');
  }

  /**
   * Form-only stubs are stripped: the model never reads an unwritable field.
   *
   * An empty field serializes as an identity-only stub in FULL mode (for
   * the panel); the block drops it, with its marker, its label and its
   * emptiness flag (a reference's _label annotation is not a stub's and
   * stays), and never carries the override field the document is printed
   * from.
   */
  public function testPageFieldsStripStubs(): void {
    $page = $this->createFieldedPage();
    $output = $this->read(['entity_type' => 'entity_test', 'entity_id' => (string) $page->id()]);

    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringContainsString("\n  name: 'Fielded page'\n", $output);
    $this->assertStringNotContainsString('field_email', $output, 'An empty field is not read.');
    $this->assertStringNotContainsString('field_description', $output);
    $this->assertStringNotContainsString('_label: Email', $output, 'No stub label rides.');
    $this->assertStringNotContainsString(FormOnlyStub::KEY, $output, 'No stub marker rides.');
    $this->assertStringNotContainsString(FormOnlyStub::EMPTY_KEY, $output, 'No emptiness flag rides.');
    $this->assertStringNotContainsString('layout_builder__layout', $output, 'The document is printed, never carried as a field.');
  }

  /**
   * A placed field block reads as the field it names, never a plugin id.
   *
   * Core stores the placement as field_block:{type}:{bundle}:{field}; the
   * read spells <pb-block field="name"/>, the one spelling page_create
   * takes back on any bundle that has the field.
   */
  public function testFieldBlockReadsAsItsField(): void {
    $uuid = '7a1e2b3c-0000-4000-8000-000000000001';
    $section = $this->createSection('eb_test_container', [
      [
        'uuid' => $uuid,
        'configuration' => [
          'id' => 'field_block:entity_test:entity_test:name',
          'label' => 'Name',
          'label_display' => '0',
          'context_mapping' => ['entity' => 'layout_builder.entity'],
          'formatter' => ['type' => 'string', 'label' => 'hidden', 'settings' => ['link_to_entity' => FALSE], 'third_party_settings' => []],
        ],
      ],
    ]);
    $page = $this->createTestEntity(['name' => 'Fielded page', 'layout_builder__layout' => [$section]]);
    $output = $this->read(['entity_type' => 'entity_test', 'entity_id' => (string) $page->id()]);

    $this->assertStringNotContainsString('Error', $output);
    $this->assertMatchesRegularExpression('/<pb-block id="' . preg_quote($uuid, '/') . '"( data-can="[^"]*")? field="name" \/>/', $output);
    $this->assertStringNotContainsString('plugin=', $output);
    $this->assertStringNotContainsString('field_block:', $output);
  }

  /**
   * A page the language cannot spell whole still reads whole, notes first.
   *
   * A two-column section (a layout the site refuses to create, so built
   * on the storage) holding an undeclared block whose multi-value field
   * has no spelling, and a declared text block in its second region. The
   * read is total: the page's fields, then the print's reading notes once
   * (the layout sentence teaching at: {ref, position, region} and both
   * refusal codes, the unprinted-field sentence; no partial-field sentence
   * since nothing is partial), then the document with its read-only
   * facts: data-layout and data-regions on the section, data-region on
   * each child, data-unprinted on the block, every one of them a
   * spelling the parser refuses written back.
   */
  public function testUnspellablePageReadsWholeWithNotesFirst(): void {
    $this->createBlockContentType('card', 'Card');
    $this->createBlockField('field_card_items', 'string', 'card', ['max_length' => 255], ['cardinality' => -1]);
    $card = BlockContent::create(['type' => 'card', 'info' => 'Card', 'field_card_items' => ['Plans', 'Pricing']]);
    $card->save();
    $right = BlockContent::create(['type' => 'text', 'info' => 'Text', 'field_text_tag' => 'h2', 'field_text_text' => 'Right']);
    $right->save();
    $card_uuid = '7a1e2b3c-0000-4000-8000-000000000011';
    $right_uuid = '7a1e2b3c-0000-4000-8000-000000000012';
    $section = $this->createSection('layout_twocol_section', [
      ['uuid' => $card_uuid, 'region' => 'first', 'configuration' => $this->createInlineBlockConfiguration('card', $card)],
      ['uuid' => $right_uuid, 'region' => 'second', 'configuration' => $this->createInlineBlockConfiguration('text', $right)],
    ], [], ['daedalus_layout' => ['uuid' => $this->container->get('uuid')->generate()]]);
    $page = $this->createTestEntity(['name' => 'Two columns', 'layout_builder__layout' => [$section]]);
    $output = $this->read(['entity_type' => 'entity_test', 'entity_id' => (string) $page->id()]);

    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringStartsWith("fields:\n", $output, 'The fields block still heads the read.');
    $this->assertMatchesRegularExpression('/^fields:\n(  .*\n)+Reading notes:\n- /', $output, 'The reading notes follow the fields and head the document.');
    $this->assertStringContainsString("\n- A <pb-container> carrying data-layout is a section on that layout rather than the container: its children print directly under it, data-regions lists its regions and each child's data-region names the one it sits in. An insert or move into such a section names the region as at: {ref, position, region}; afterbegin or beforeend on it without a region is refused (REGION_REQUIRED), a region anywhere else is refused too (REGION_NOT_APPLICABLE), and nothing creates such a section.\n", $output, 'The layout sentence teaches the region address and both refusals.');
    $this->assertStringContainsString("\n- data-unprinted names fields the element holds that the page language cannot spell: a set on the element keeps them, a replace rebuilds the element and loses them.\n\n<pb-container", $output, 'The unprinted-field sentence closes the notes; a blank line separates them from the document.');
    $this->assertStringNotContainsString('data-partial names', $output, 'No partial field, no partial-field sentence.');
    $this->assertStringNotContainsString('/* comment */', $output, 'No style gap, no unprinted-style sentence.');
    $this->assertSame(1, substr_count($output, 'Reading notes:'), 'The notes head the print once.');
    $this->assertSame(1, substr_count($output, 'at: {ref, position, region}'));
    $this->assertMatchesRegularExpression('/\n\n<pb-container( id="[^"]*")?( data-can="[^"]*")? data-layout="layout_twocol_section" data-regions="first second">\n/', $output, 'The section carries its layout and region names as facts.');
    $this->assertMatchesRegularExpression('/\n  <pb-block id="' . preg_quote($card_uuid, '/') . '"( data-can="[^"]*")? data-region="first" data-unprinted="field_card_items" type="card" \/>\n/', $output, 'The undeclared block names its unspellable field and its region.');
    $this->assertMatchesRegularExpression('/\n  <pb-text id="' . preg_quote($right_uuid, '/') . '"( data-can="[^"]*")? data-region="second" tag="h2">Right<\/pb-text>\n/', $output, 'The declared block in the second region prints as itself, its region a fact.');
    $this->assertStringNotContainsString('Plans', $output, 'The unspellable value is named, never printed.');
    $this->assertStringEndsWith("</pb-container>\n", $output);
  }

  /**
   * A saved blueprint reads as a page of its own: its face, then its body.
   *
   * The host notice heads the read as on any page (the spine's own
   * sentence for the root: a block-scope blueprint holding its one root
   * refuses a second, BlueprintScopePolicy), then the fields block is the
   * blueprint's own fields (its label, description, scope and subject: what
   * page_create takes to author one), and the document prints WITH ids,
   * since this is the read before a page_edit on the blueprint itself (the
   * container's own id is daedalus_layout's third-party setting, which
   * this daedalus_blueprint-only site does not read; the blocks carry
   * theirs); the roster's record keeps the id-less fragment the schema
   * prints.
   */
  public function testBlueprintReadsAsItsOwnPage(): void {
    $blueprint = $this->container->get('entity_type.manager')->getStorage('blueprint')->loadByProperties(['machine_name' => 'card']);
    $blueprint = reset($blueprint);
    $output = $this->read(['entity_type' => 'blueprint', 'entity_id' => (string) $blueprint->id()]);

    $this->assertStringNotContainsString('Error', $output);
    $this->assertStringStartsWith('The block blueprint "card" holds one root container; it already has one.', $output, 'The root verdict heads the read: no second root on a block-scope blueprint.');
    $this->assertStringContainsString("\nfields:\n  label: Card\n", $output, 'The blueprint\'s face follows the notice.');
    $this->assertStringContainsString("\n  description: 'A titled card with a link.'\n", $output);
    $this->assertStringContainsString("\n  scope: block\n", $output);
    $this->assertStringContainsString("\n  subject_type: entity_test\n", $output);
    $this->assertStringContainsString("\n  subject_bundle: entity_test\n", $output);
    $this->assertStringNotContainsString('layout_builder__layout', $output, 'The document is printed, never carried as a field.');
    $this->assertStringContainsString("\n<pb-container name=\"Card\">\n", $output);
    $this->assertMatchesRegularExpression('/<pb-text id="[0-9a-f-]{36}" tag="h2">Card title<\/pb-text>/', $output, 'The body prints with ids: it is edited in place.');
    $this->assertMatchesRegularExpression('/<pb-text id="[0-9a-f-]{36}">Read more &amp; learn<\/pb-text>/', $output);
    $this->assertStringEndsWith("</pb-container>\n", $output);
    $this->assertStringNotContainsString('<style>', $output);

    // The record carries the same document as an id-less fragment (the
    // schema prints it as the blueprint's fragment) and the page the read
    // addressed.
    $record = $this->container->get('daedalus_blueprint.materials_roster_builder')->build()
      ->get(MaterialKind::Blueprint, 'card');
    $this->assertSame((string) $blueprint->id(), $record->entityId, 'The record names the page the read addresses.');
    $this->assertCount(2, $record->tree[0]['children']);
    $this->assertArrayNotHasKey('uuid', $record->tree[0]);
    $this->assertArrayNotHasKey('uuid', $record->tree[0]['children'][0]);
  }

  /**
   * A blueprint for a hidden bundle is as unreachable as the bundle's pages.
   *
   * The document's kind decides visibility (the subject's bundle, which the
   * blueprint's display is), and the refusal names the subject, since the
   * blueprint's own bundle is never the hidden one.
   */
  public function testBlueprintForHiddenSubjectIsRefused(): void {
    \Drupal::configFactory()
      ->getEditable('daedalus_blueprint_ai.settings')
      ->set('hidden_bundles', ['entity_test' => ['entity_test']])
      ->save();
    $blueprint = $this->container->get('entity_type.manager')->getStorage('blueprint')->loadByProperties(['machine_name' => 'card']);
    $blueprint = reset($blueprint);
    $output = $this->read(['entity_type' => 'blueprint', 'entity_id' => (string) $blueprint->id()]);
    $this->assertSame(sprintf('Unknown or hidden bundle: entity_test/entity_test (the subject of blueprint %s).', $blueprint->id()), $output);
  }

  /**
   * A blueprint id the site does not hold is refused as any page would be.
   */
  public function testUnknownBlueprintIsRefused(): void {
    $this->assertSame('Entity blueprint:999 not found.', $this->read(['entity_type' => 'blueprint', 'entity_id' => '999']));
  }

  /**
   * No host named: the one shape is the corrective.
   */
  public function testEmptyAddressIsRefused(): void {
    $output = $this->read(['entity_type' => '', 'entity_id' => '']);
    $this->assertStringStartsWith('Error: page_read reads one host of a page document by entity_type and entity_id', $output);

    $output = $this->read(['entity_type' => 'entity_test', 'entity_id' => '']);
    $this->assertStringStartsWith('Error: page_read reads one host', $output);
  }

}
