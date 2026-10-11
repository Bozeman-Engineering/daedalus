<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\Utility\FormOnlyStub;
use Drupal\daedalus_blueprint\BlueprintSerializer;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the BlueprintSerializer service.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintSerializer::class)]
#[Group('daedalus_blueprint')]
class BlueprintSerializerTest extends BlueprintKernelTestBase {

  /**
   * The serializer under test.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintSerializer
   */
  protected BlueprintSerializer $serializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
  }

  /**
   * Tests serializing an entity with basic scalar fields.
   */
  public function testSerializeBasicEntity(): void {
    $entity = $this->createTestEntity([
      'name' => 'Test page',
      'field_subtitle' => 'A subtitle',
      'field_count' => 42,
      'field_published' => TRUE,
      'field_email' => 'test@example.com',
    ]);

    $result = $this->serializer->serialize($this->host($entity));

    $this->assertSame('entity_test', $result['entity_type']);
    $this->assertSame('entity_test', $result['bundle']);
    $this->assertSame('en', $result['langcode']);

    // _root_entity present for saved entities.
    $this->assertSame('id', $result['_root_entity']['field']);
    $this->assertSame((int) $entity->id(), $result['_root_entity']['value']);

    // Fields.
    $fields = $result['fields'];
    $this->assertSame('Test page', $fields['name']);
    $this->assertSame('A subtitle', $fields['field_subtitle']);
    $this->assertSame(42, $fields['field_count']);
    $this->assertTrue((bool) $fields['field_published']);
    $this->assertSame('test@example.com', $fields['field_email']);
  }

  /**
   * Tests serializing text fields produces {value, format} objects.
   */
  public function testSerializeTextFields(): void {
    $entity = $this->createTestEntity([
      'field_description' => [
        'value' => '<p>Description text</p>',
        'format' => 'plain_text',
      ],
      'field_body' => [
        'value' => '<p>Body content</p>',
        'format' => 'plain_text',
        'summary' => 'Summary text',
      ],
    ]);

    $result = $this->serializer->serialize($this->host($entity));
    $fields = $result['fields'];

    // text_long: {value, format}.
    $this->assertSame('<p>Description text</p>', $fields['field_description']['value']);
    $this->assertSame('plain_text', $fields['field_description']['format']);

    // text_with_summary: {value, format, summary}.
    $this->assertSame('<p>Body content</p>', $fields['field_body']['value']);
    $this->assertSame('plain_text', $fields['field_body']['format']);
    $this->assertSame('Summary text', $fields['field_body']['summary']);
  }

  /**
   * Tests serializing a multi-value field produces an array.
   */
  public function testSerializeMultiValueField(): void {
    $entity = $this->createTestEntity([
      'field_tags_text' => ['Alpha', 'Beta', 'Gamma'],
    ]);

    $result = $this->serializer->serialize($this->host($entity));
    $tags = $result['fields']['field_tags_text'];

    $this->assertIsArray($tags);
    $this->assertCount(3, $tags);
    $this->assertSame(['Alpha', 'Beta', 'Gamma'], $tags);
  }

  /**
   * Tests serializing a new (unsaved) entity omits _root_entity.
   */
  public function testSerializeNewEntity(): void {
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $entity = $storage->create([
      'name' => 'Unsaved entity',
      'type' => 'entity_test',
    ]);

    $result = $this->serializer->serialize($this->host($entity));

    $this->assertSame('entity_test', $result['entity_type']);
    $this->assertSame('entity_test', $result['bundle']);
    $this->assertArrayNotHasKey('_root_entity', $result);
    $this->assertSame('Unsaved entity', $result['fields']['name']);
  }

  /**
   * Tests that empty fields carry identity-only stubs, never values.
   *
   * FULL mode is structure-complete: every processable definition has
   * a node, and a field without a value gets a form-only stub. Sparse
   * output is the summary transport's job (see
   * BlueprintSerializerSummaryTest and FormOnlyStubTest).
   */
  public function testSerializeStubsEmptyFields(): void {
    $entity = $this->createTestEntity([
      'name' => 'Minimal entity',
    ]);

    $result = $this->serializer->serialize($this->host($entity));
    $fields = $result['fields'];

    $this->assertSame('Minimal entity', $fields['name']);
    foreach (['field_subtitle', 'field_description', 'field_body', 'field_tags_text'] as $empty_field) {
      $this->assertTrue(
        FormOnlyStub::isStub($fields[$empty_field]),
        "$empty_field carries an identity-only stub.",
      );
    }
  }

  /**
   * Tests that entity key fields are excluded from the fields map.
   */
  public function testSerializeOmitsEntityKeys(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->serializer->serialize($this->host($entity));
    $fields = $result['fields'];

    // Entity keys should NOT appear in fields.
    $this->assertArrayNotHasKey('id', $fields);
    $this->assertArrayNotHasKey('uuid', $fields);
    $this->assertArrayNotHasKey('type', $fields);
    $this->assertArrayNotHasKey('langcode', $fields);
    $this->assertArrayNotHasKey('default_langcode', $fields);

    // Label field SHOULD appear in fields.
    $this->assertArrayHasKey('name', $fields);
  }

  /**
   * Tests that langcode appears at the top level.
   */
  public function testSerializeIncludesLangcode(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->serializer->serialize($this->host($entity));

    $this->assertArrayHasKey('langcode', $result);
    $this->assertSame('en', $result['langcode']);
  }

  /**
   * Tests that _root_entity value is an integer for numeric IDs.
   */
  public function testRootEntityValueIsInteger(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->serializer->serialize($this->host($entity));

    $this->assertIsInt($result['_root_entity']['value']);
  }

  /**
   * A full read of a styled page creates an equal page: the read is a write.
   *
   * The document is one shape: the read's `layout` is the lean tree with
   * every node's styles on the node, its top-level `styles` holds only
   * the keys no node owns, and the write grammar takes exactly that. The
   * page is built through the ops (a styled container holding a shown
   * label block with managed configuration, a plugin block, a nested
   * styled container), read in full, and the read, its `_root_entity`
   * removed, is given to the create door: the copy reads back equal to
   * the source apart from its own identity.
   */
  public function testFullReadCreatesAnEqualPage(): void {
    $this->installDocumentSite();
    $operations = $this->container->get('daedalus_blueprint.operations');

    $section_uuid = '3c000000-0000-4000-8000-000000000001';
    $block_uuid = '3c000000-0000-4000-8000-000000000002';
    $nested_uuid = '3c000000-0000-4000-8000-000000000004';
    $source = $this->createTestEntity(['name' => 'Source']);
    $result = $operations->batchOperations($this->host($source), [
      [
        'op' => 'add_section',
        'uuid' => $section_uuid,
        'layout' => 'eb_test_container',
        'layout_settings' => ['label' => 'Band'],
        'location' => ['anchor' => $source->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => $block_uuid,
        'block_type' => 'yaml_test_block',
        'properties' => [
          'body' => ['value' => '<p>Ribbon</p>', 'format' => 'plain_text'],
          'yaml_setting' => 'ribbon',
          'label' => 'Ribbon',
          'label_display' => 'visible',
        ],
        'location' => ['anchor' => $section_uuid, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => '3c000000-0000-4000-8000-000000000003',
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => $section_uuid, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_section',
        'uuid' => $nested_uuid,
        'layout' => 'eb_test_container',
        'layout_settings' => ['label' => 'Inner'],
        'location' => ['anchor' => $section_uuid, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'uuid' => '3c000000-0000-4000-8000-000000000005',
        'block_type' => 'yaml_test_block',
        'properties' => ['body' => ['value' => '<p>Inner</p>', 'format' => 'plain_text']],
        'location' => ['anchor' => $nested_uuid, 'placement' => 'inside'],
      ],
      ['op' => 'update_style', 'key' => "container:$section_uuid", 'properties' => ['padding' => '2rem']],
      ['op' => 'update_style', 'key' => "container:$section_uuid::> *", 'properties' => ['gap' => '1rem']],
      ['op' => 'update_style', 'key' => "block:$block_uuid", 'properties' => ['text-align' => 'center']],
      ['op' => 'update_style', 'key' => "container:$nested_uuid", 'properties' => ['padding' => '1rem']],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $read = $this->serializer->serialize($this->host($source));
    // Styles ride the nodes; nothing is left for the top-level map.
    $this->assertArrayNotHasKey('styles', $read);
    $this->assertSame([
      '' => ['default' => ['padding' => '2rem']],
      '::> *' => ['default' => ['gap' => '1rem']],
    ], $read['layout'][0]['styles']);
    $this->assertSame(['default' => ['text-align' => 'center']], $read['layout'][0]['children'][0]['styles']['']);
    $this->assertSame(['yaml_setting' => 'ribbon'], $read['layout'][0]['children'][0]['configuration']);
    $this->assertTrue($read['layout'][0]['children'][0]['label_rendered']);
    $this->assertSame(['default' => ['padding' => '1rem']], $read['layout'][0]['children'][2]['styles']['']);
    $this->assertArrayHasKey('_verdict', $read['layout'][0]['children'][0]);

    // The read, as written.
    unset($read['_root_entity']);
    $result = $operations->createEntity($read);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $copy = $result->getEntity();
    $this->assertNotSame($source->id(), $copy->id());

    $copy_read = $this->serializer->serialize($this->host($copy));
    unset($copy_read['_root_entity']);
    $this->assertEquals($read, $copy_read, 'The copy reads as the source apart from its own identity.');
  }

  /**
   * A cold read of a never-edited page answers the whole seed, styles too.
   *
   * The serializer reads the style map before the layout. A content host
   * seeds an empty override from its display's template on the first read
   * of EITHER door, so the map read first already carries the template's
   * styles re-keyed onto the seeded identity, and the layout read next is
   * the document they address: one seed whatever a reader takes first,
   * and no reader reordered to compensate. The seed is in memory: the
   * saved entity is unchanged by the read.
   */
  public function testColdReadOfTheSeededPageCarriesItsStyles(): void {
    $this->installDocumentSite();
    $section_uuid = '5e000000-0000-4000-8000-000000000001';
    $block_uuid = '5e000000-0000-4000-8000-000000000002';
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $display->appendSection(new Section('layout_onecol', [], [
      new SectionComponent($block_uuid, 'content', ['id' => 'system_powered_by_block']),
    ], ['daedalus_layout' => ['uuid' => $section_uuid]]));
    $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display)->setStyleMap([
      "container:$section_uuid" => ['default' => ['padding' => '2rem']],
      "block:$block_uuid" => ['default' => ['color' => 'teal']],
    ]);
    $display->save();

    $entity = $this->createTestEntity(['name' => 'Fresh page']);
    $read = $this->serializer->serialize($this->host($entity));

    $seeded_section = DocumentInstantiator::derivedUuid($entity->uuid(), $section_uuid);
    $seeded_block = DocumentInstantiator::derivedUuid($entity->uuid(), $block_uuid);
    $this->assertSame($seeded_section, $read['layout'][0]['uuid'], 'The read answers the seeded document under derived identity.');
    $this->assertSame(['default' => ['padding' => '2rem']], $read['layout'][0]['styles'][''], "The template's container style rides the seeded container.");
    $block = NodeTreeBuilder::find($read['layout'], $seeded_block);
    $this->assertNotNull($block, 'The seeded block is in the read.');
    $this->assertSame(['default' => ['color' => 'teal']], $block['styles'][''], "The template's block style rides the seeded block.");
    $this->assertArrayNotHasKey('styles', $read, 'Every seeded style rides its node; nothing is node-less.');
    $this->assertStringNotContainsString($section_uuid, json_encode($read), "The template's identity is nowhere on the page.");

    // The seed is the host instance's, in memory: nothing reached storage.
    $this->entityTypeManager->getStorage('entity_test')->resetCache([$entity->id()]);
    $saved = EntityTest::load($entity->id());
    $this->assertTrue($saved->get(ContentHost::LAYOUT_FIELD)->isEmpty(), 'The read persisted no document.');
    $this->assertTrue($saved->get($this->host($saved)->styleMapDefinition()->getName())->isEmpty(), 'The read persisted no styles.');
  }

  /**
   * Installs the page document's site: layout, styles and the test block.
   *
   * The legs reading or writing a whole document need Layout Builder with
   * daedalus_layout (section identity), the style store and the test
   * module's managed block type.
   */
  protected function installDocumentSite(): void {
    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'file',
      'navigation',
      'twig_events',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_layout',
      'daedalus_stylish',
      'daedalus_stylish_unbound_test',
      'daedalus_blueprint_test',
    ]);
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['block_content', 'daedalus_stylish']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->enableLayoutBuilder();
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->createBlockContentType('yaml_test_block', 'YAML block');
    $this->createBlockField('body', 'text_with_summary', 'yaml_test_block');
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
  }

}
