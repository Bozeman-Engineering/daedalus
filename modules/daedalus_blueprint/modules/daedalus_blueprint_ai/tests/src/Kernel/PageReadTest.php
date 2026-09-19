<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\PageRead;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The page_read stencil branch: a definition as a document without ids.
 *
 * One read tool, one print grammar. With `stencil` the tool prints the
 * definition's tree through the dialect printer: no id attributes, no
 * <style> block, wears spelled as the page language spells them (a
 * class here; an axis attribute where the catalog declares one), the
 * same fragment the schema's stencil entry prints. The roster
 * is the model's world, so an id it does not carry is refused with the
 * ids it does; a call naming neither a page nor a stencil is refused
 * with the two shapes.
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
   * provider serves the hero_glow class variant the fixture stencil wears;
   * the stencil entity's icon references media, so the stencil module
   * needs the media stack installed.
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
    'daedalus_blueprint_stencil',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The real stencil provider teaches the stencil gesture here; the
    // fixture keeps its swatch gesture only (two providers teaching one
    // kind is refused by the roster builder).
    TestMaterialsProvider::$gestureLines = [MaterialKind::Swatch->value => 'value'];
    $this->enableLayoutBuilder();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('stencil');
    $this->installConfig(['image', 'file', 'media']);

    // A declared text block type: tag (list_string) and a plain body, so
    // the print carries <pb-text tag="h2">...</pb-text>.
    $this->createBlockContentType('text', 'Text');
    $this->createBlockField('field_text_tag', 'list_string', 'text', ['allowed_values' => ['h2' => 'h2', 'h3' => 'h3']]);
    $this->createBlockField('field_text_text', 'string_long', 'text');

    $card = Stencil::create([
      'label' => 'Card',
      'machine_name' => 'card',
      'description' => 'A titled card with a link.',
      'status' => 1,
    ]);
    $card->setElement([
      'kind' => 'container',
      'layout_id' => 'eb_test_container',
      'label' => 'Card',
      'styles' => ['' => ['variants' => ['hero_glow'], 'default' => ['padding-top' => '2rem']]],
      'children' => [
        ['kind' => 'container', 'layout_id' => 'eb_test_container', 'label' => 'Image wrapper'],
        [
          'kind' => 'block',
          'block_type' => 'text',
          'block_fields' => ['info' => 'Text', 'field_text_tag' => 'h2', 'field_text_text' => 'Card title'],
        ],
        [
          'kind' => 'block',
          'block_type' => 'text',
          'block_fields' => ['info' => 'Text', 'field_text_text' => 'Read more & learn'],
        ],
      ],
    ]);
    $card->save();
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
   * A stencil prints as the definition's document: no ids, wears spelled.
   */
  public function testStencilPrintsAsDocumentWithoutIds(): void {
    $output = $this->read(['stencil' => 'card']);

    $expected = <<<HTML
<pb-container class="hero_glow" style="padding-top: 2rem">
  <pb-container>
  </pb-container>
  <pb-text tag="h2">Card title</pb-text>
  <pb-text>Read more &amp; learn</pb-text>
</pb-container>

HTML;
    $this->assertStringEndsWith("\n" . $expected, $output);
    $this->assertStringStartsWith('Stencil "card" (Card), root section:', $output);
    $this->assertStringContainsString('<pb-stencil name="card"/>', $output);
    $this->assertStringNotContainsString(' id="', $output);
    $this->assertStringNotContainsString('<style>', $output);
    $this->assertStringNotContainsString('Error', $output);

    // The record carries the same tree the print read, in the printer's
    // shape (the schema prints it as the stencil's fragment).
    $record = $this->container->get('daedalus_blueprint.materials_roster_builder')->build()
      ->get(MaterialKind::Stencil, 'card');
    $this->assertSame([['variant' => 'hero_glow']], $record->tree[0]['styles']['']['variants']);
    $this->assertCount(3, $record->tree[0]['children']);
  }

  /**
   * An id the roster does not carry is refused with the available ids.
   *
   * The materials fixture's "hero" is a record without a definition (no
   * discovery knows it), so it is refused too: a body needs both.
   */
  public function testUnknownStencilIsRefusedWithTheAvailableIds(): void {
    $output = $this->read(['stencil' => 'banner']);
    $this->assertStringStartsWith('Error: "banner" is not an available stencil.', $output);
    $this->assertStringContainsString('Available: ', $output);
    $this->assertStringContainsString('card', $output);
    $this->assertStringNotContainsString('<pb-container', $output);

    $this->assertStringStartsWith('Error: "hero" is not an available stencil.', $this->read(['stencil' => 'hero']));
  }

  /**
   * Neither a page nor a stencil named: the two shapes are the corrective.
   */
  public function testNeitherReadIsRefused(): void {
    $output = $this->read([]);
    $this->assertStringStartsWith('Error: page_read reads a page (entity_type and entity_id) or a stencil', $output);

    $output = $this->read(['entity_type' => 'entity_test']);
    $this->assertStringStartsWith('Error: page_read reads a page', $output);
  }

}
