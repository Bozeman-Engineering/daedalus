<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_blueprint\BlueprintSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests BlueprintSerializer::serializeElement().
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintSerializer::class)]
#[Group('daedalus_blueprint')]
class BlueprintSerializerElementTest extends BlueprintKernelTestBase {

  /**
   * The blueprint serializer.
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
   * Refreshes the serializer from the rebuilt container.
   */
  protected function refreshSerializer(): void {
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
  }

  /**
   * Tests serializeElement returns NULL for nonexistent UUID.
   */
  public function testSerializeComponentNonexistentUuid(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->serializer->serializeElement($entity, 'c68c9561-d36d-b4fc-77ac-50039f354800');

    $this->assertNull($result);
  }

  /**
   * Tests serializeElement on LB inline block with block_fields populated.
   */
  public function testSerializeComponentLbInlineBlock(): void {
    $this->enableLayoutBuilder();
    $this->refreshSerializer();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_body', 'text_long', 'text_section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_body' => ['value' => '<p>Full body content</p>', 'format' => 'plain_text'],
      'field_heading' => 'Welcome',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '152d9378-ad8f-1c16-1c35-cd161a184ec5',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Hero Text'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->serializer->serializeElement($entity, '152d9378-ad8f-1c16-1c35-cd161a184ec5');

    $this->assertNotNull($result);
    $this->assertEquals('152d9378-ad8f-1c16-1c35-cd161a184ec5', $result['uuid']);
    $this->assertEquals('content', $result['region']);
    $this->assertEquals(0, $result['weight']);
    $this->assertEquals('text_section', $result['block_type']);
    $this->assertEquals('Hero Text', $result['label']);
    $this->assertEquals('visible', $result['label_display']);
    $this->assertEquals('full', $result['view_mode']);

    // block_fields should be fully populated.
    $this->assertArrayHasKey('block_fields', $result);
    $block_fields = $result['block_fields'];
    $this->assertArrayHasKey('field_body', $block_fields);
    $this->assertEquals('<p>Full body content</p>', $block_fields['field_body']['value']);
    $this->assertEquals('plain_text', $block_fields['field_body']['format']);
    $this->assertArrayHasKey('field_heading', $block_fields);
    $this->assertEquals('Welcome', $block_fields['field_heading']);
  }

  /**
   * Tests label_display serializes as 'visible' when absent from config.
   *
   * Core's block plugin default (baseConfigurationDefaults) is 'visible',
   * and entity save materializes it into the stored component configuration
   * — either way, a config without the key renders the label.
   */
  public function testSerializeComponentLabelDisplayDefaultsToVisible(): void {
    $this->enableLayoutBuilder();
    $this->refreshSerializer();
    $this->createBlockContentType('text_section', 'Text Section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
    ]);

    $configuration = $this->createInlineBlockConfiguration('text_section', $block, 'Hidden Label');
    unset($configuration['label_display']);
    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '152d9378-ad8f-1c16-1c35-cd161a184ec5',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $configuration,
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->serializer->serializeElement($entity, '152d9378-ad8f-1c16-1c35-cd161a184ec5');

    $this->assertEquals('visible', $result['label_display']);
  }

  /**
   * Tests serializeElement on LB block with nested layout as summary.
   */
  public function testSerializeComponentNestedSection(): void {
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
    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_body', 'text_long', 'text_section');
    $this->refreshSerializer();

    // Build a nested section (a container component embedding one Section)
    // holding a text block.
    $nested_block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Nested Block',
      'reusable' => FALSE,
      'field_body' => ['value' => 'Nested body', 'format' => 'plain_text'],
    ]);
    $nested_section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'acab1f60-1b5a-4186-a39b-c2215857849e',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $nested_block, 'Nested Block'),
      ],
    ], ['label' => 'Band']);
    $nested_section->setThirdPartySetting('daedalus_layout', 'uuid', '98ecbc05-28b3-48c5-895e-86dfa276af6c');

    $root_section = $this->createSection('layout_onecol');
    $root_section->appendComponent(SectionBlock::createComponent($nested_section, 'content', 0));

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Nested Page',
      'layout_builder__layout' => [$root_section],
    ]);
    $entity->save();

    // A nested section resolves by its section uuid and serializes as a
    // section node (summarized components), never as a block.
    $result = $this->serializer->serializeElement($entity, '98ecbc05-28b3-48c5-895e-86dfa276af6c');

    $this->assertNotNull($result);
    $this->assertEquals('layout_onecol', $result['layout_id']);
    $this->assertEquals('98ecbc05-28b3-48c5-895e-86dfa276af6c', $result['uuid']);
    $this->assertEquals('Band', $result['label']);
    $this->assertArrayNotHasKey('block_type', $result);
    $this->assertArrayNotHasKey('plugin_id', $result);

    $nested_comp = $result['regions']['content'][0];
    $this->assertEquals('acab1f60-1b5a-4186-a39b-c2215857849e', $nested_comp['uuid']);
    $this->assertEquals('text_section', $nested_comp['block_type']);
    $this->assertEquals('Nested Block', $nested_comp['label']);
    // Summary: no block_fields, no view_mode, no block_revision_id.
    $this->assertArrayNotHasKey('block_fields', $nested_comp);
    $this->assertArrayNotHasKey('view_mode', $nested_comp);
    $this->assertArrayNotHasKey('block_revision_id', $nested_comp);

    // The leaf inline block serializes as a plain block component and never
    // carries a nested layout key.
    $leaf = $this->serializer->serializeElement($entity, 'acab1f60-1b5a-4186-a39b-c2215857849e');
    $this->assertNotNull($leaf);
    $this->assertEquals('text_section', $leaf['block_type']);
    $this->assertArrayNotHasKey('layout_builder__layout', $leaf);
    $this->assertEquals('Nested body', $leaf['block_fields']['field_body']['value']);
  }

  /**
   * Tests serializeElement on nested LB block without daedalus_layout.
   *
   * Without daedalus_layout, nested layout components cannot be found because
   * the flat section search does not recurse into nested block_content
   * layouts. Nested layouts are an daedalus_layout feature.
   */
  public function testSerializeComponentNestedLbBlock(): void {
    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    // Enable LB overrides on block_content.
    $this->enableLayoutBuilder('block_content', 'text_section');
    $this->refreshSerializer();

    $nested_block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_body' => ['value' => '<p>Deeply nested</p>', 'format' => 'plain_text'],
    ]);

    $nested_section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'bc0da432-05ef-6c60-d084-2e736c3d56ae',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $nested_block, 'Deep Block'),
      ],
    ]);

    $parent_block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'layout_builder__layout' => [$nested_section],
    ]);

    $root_section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'd91a843b-6f7c-b796-09c3-a5eac185a188',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $parent_block, 'Root Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Deep Page',
      'layout_builder__layout' => [$root_section],
    ]);
    $entity->save();

    // Without daedalus_layout, nested layout components are not reachable.
    $result = $this->serializer->serializeElement($entity, 'bc0da432-05ef-6c60-d084-2e736c3d56ae');
    $this->assertNull($result);

    // Root-level component should still be found.
    $result = $this->serializer->serializeElement($entity, 'd91a843b-6f7c-b796-09c3-a5eac185a188');
    $this->assertNotNull($result);
    $this->assertEquals('d91a843b-6f7c-b796-09c3-a5eac185a188', $result['uuid']);
    $this->assertEquals('Root Block', $result['label']);
  }

  /**
   * Tests serializeElement on a paragraph entity.
   */
  public function testSerializeComponentParagraph(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createRevField('field_text', 'text_long', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para = $this->createRevEntity([
      'name' => 'FAQ Section',
      'field_heading' => 'Frequently Asked Questions',
      'field_text' => ['value' => '<p>Below are common questions.</p>', 'format' => 'plain_text'],
    ]);

    $entity = $this->createTestEntity([
      'name' => 'My Page',
      'field_paragraphs' => [['entity' => $para]],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Get the paragraph UUID.
    $para_entity = $entity->get('field_paragraphs')->first()->entity;
    $para_uuid = $para_entity->uuid();

    $result = $this->serializer->serializeElement($entity, $para_uuid);

    $this->assertNotNull($result);
    $this->assertEquals($para_uuid, $result['uuid']);
    $this->assertEquals((int) $para_entity->id(), $result['target_id']);
    $this->assertEquals('entity_test_rev', $result['type']);

    // Fields should be fully populated, including text_long.
    $this->assertArrayHasKey('fields', $result);
    $this->assertEquals('Frequently Asked Questions', $result['fields']['field_heading']);
    $this->assertArrayHasKey('field_text', $result['fields']);
    $this->assertEquals('<p>Below are common questions.</p>', $result['fields']['field_text']['value']);
  }

  /**
   * Tests serializeElement on paragraph with nested children as summary.
   */
  public function testSerializeComponentParagraphWithNestedChildren(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createRevField('field_answer', 'text_long', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );
    $this->createEntityReferenceRevisionsField(
      'field_items',
      'entity_test_rev',
      'entity_test_rev',
      'entity_test_rev',
    );

    $child1 = $this->createRevEntity([
      'name' => 'Question 1',
      'field_answer' => ['value' => '<p>Answer 1</p>', 'format' => 'plain_text'],
    ]);
    $child2 = $this->createRevEntity([
      'name' => 'Question 2',
      'field_answer' => ['value' => '<p>Answer 2</p>', 'format' => 'plain_text'],
    ]);

    $parent = $this->createRevEntity([
      'name' => 'FAQ Section',
      'field_heading' => 'FAQ',
      'field_items' => [
        ['entity' => $child1],
        ['entity' => $child2],
      ],
    ]);

    $entity = $this->createTestEntity([
      'name' => 'My Page',
      'field_paragraphs' => [['entity' => $parent]],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $parent_entity = $entity->get('field_paragraphs')->first()->entity;
    $parent_uuid = $parent_entity->uuid();

    $result = $this->serializer->serializeElement($entity, $parent_uuid);

    $this->assertNotNull($result);
    $this->assertEquals($parent_uuid, $result['uuid']);
    $this->assertEquals('entity_test_rev', $result['type']);

    // Direct fields should be populated.
    $this->assertArrayHasKey('fields', $result);
    $this->assertEquals('FAQ', $result['fields']['field_heading']);

    // ERR field should be summary (no field values on children).
    $this->assertArrayHasKey('field_items', $result['fields']);
    $items = $result['fields']['field_items'];
    $this->assertCount(2, $items);

    $this->assertArrayHasKey('uuid', $items[0]);
    $this->assertArrayHasKey('target_id', $items[0]);
    $this->assertEquals('entity_test_rev', $items[0]['type']);
    $this->assertEquals('Question 1', $items[0]['label']);
    // No fields on the summary.
    $this->assertArrayNotHasKey('fields', $items[0]);

    $this->assertEquals('Question 2', $items[1]['label']);
    $this->assertArrayNotHasKey('fields', $items[1]);
  }

  /**
   * Tests serializeElement on nested paragraph (drill down).
   */
  public function testSerializeComponentNestedParagraph(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createRevField('field_answer', 'text_long', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );
    $this->createEntityReferenceRevisionsField(
      'field_items',
      'entity_test_rev',
      'entity_test_rev',
      'entity_test_rev',
    );

    $child = $this->createRevEntity([
      'name' => 'Question 1',
      'field_heading' => 'What is this?',
      'field_answer' => ['value' => '<p>This is the answer.</p>', 'format' => 'plain_text'],
    ]);

    $parent = $this->createRevEntity([
      'name' => 'FAQ Section',
      'field_items' => [['entity' => $child]],
    ]);

    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [['entity' => $parent]],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Get the nested child's UUID.
    $parent_entity = $entity->get('field_paragraphs')->first()->entity;
    $child_entity = $parent_entity->get('field_items')->first()->entity;
    $child_uuid = $child_entity->uuid();

    // Drill into the nested child paragraph.
    $result = $this->serializer->serializeElement($entity, $child_uuid);

    $this->assertNotNull($result);
    $this->assertEquals($child_uuid, $result['uuid']);
    $this->assertEquals('entity_test_rev', $result['type']);

    // Fields should be fully populated.
    $this->assertArrayHasKey('fields', $result);
    $this->assertEquals('What is this?', $result['fields']['field_heading']);
    $this->assertArrayHasKey('field_answer', $result['fields']);
    $this->assertEquals('<p>This is the answer.</p>', $result['fields']['field_answer']['value']);
  }

  /**
   * Tests serializeElement on non-inline block.
   */
  public function testSerializeComponentNonInlineBlock(): void {
    $this->enableLayoutBuilder();
    $this->refreshSerializer();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'c578c767-7dac-a205-0c91-3fb82af82d46',
        'region' => 'content',
        'weight' => 0,
        'configuration' => [
          'id' => 'system_branding_block',
          'label' => 'Site branding',
          'provider' => 'system',
        ],
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'System Block Page',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->serializer->serializeElement($entity, 'c578c767-7dac-a205-0c91-3fb82af82d46');

    $this->assertNotNull($result);
    $this->assertEquals('c578c767-7dac-a205-0c91-3fb82af82d46', $result['uuid']);
    $this->assertEquals('content', $result['region']);
    $this->assertEquals(0, $result['weight']);
    $this->assertEquals('system_branding_block', $result['plugin_id']);

    // Non-inline blocks should not have block_type, block_fields, etc.
    $this->assertArrayNotHasKey('block_type', $result);
    $this->assertArrayNotHasKey('block_fields', $result);
    $this->assertArrayNotHasKey('label', $result);
    $this->assertArrayNotHasKey('view_mode', $result);
  }

  /**
   * Tests serializeElement on LB block with ERR field as summary.
   *
   * When an inline block has an entity_reference_revisions field,
   * serializeElement should show those as summaries.
   */
  public function testSerializeComponentLbBlockWithErrField(): void {
    $this->enableLayoutBuilder();
    $this->refreshSerializer();
    $this->createBlockContentType('rich_section', 'Rich Section');
    $this->createBlockField('field_heading', 'string', 'rich_section');

    // Create ERR field on the block type.
    $this->enableEntityReferenceRevisions();
    $this->installEntitySchema('entity_test_rev');

    FieldStorageConfig::create([
      'field_name' => 'field_items',
      'entity_type' => 'block_content',
      'type' => 'entity_reference_revisions',
      'settings' => ['target_type' => 'entity_test_rev'],
      'cardinality' => -1,
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_items',
      'entity_type' => 'block_content',
      'bundle' => 'rich_section',
      'label' => 'Items',
      'settings' => [
        'handler' => 'default:entity_test_rev',
        'handler_settings' => ['target_bundles' => ['entity_test_rev' => 'entity_test_rev']],
      ],
    ])->save();

    $child = $this->createRevEntity(['name' => 'Item 1']);

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'rich_section',
      'reusable' => FALSE,
      'field_heading' => 'My Section',
      'field_items' => [['entity' => $child]],
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '5bf56270-dc3d-7dbe-4f57-34ec5eaf60b6',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('rich_section', $block, 'Rich Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->serializer->serializeElement($entity, '5bf56270-dc3d-7dbe-4f57-34ec5eaf60b6');

    $this->assertNotNull($result);
    $this->assertEquals('Rich Block', $result['label']);

    // Direct string field should be populated.
    $this->assertArrayHasKey('block_fields', $result);
    $this->assertEquals('My Section', $result['block_fields']['field_heading']);

    // ERR field should be summary.
    $this->assertArrayHasKey('field_items', $result['block_fields']);
    $items = $result['block_fields']['field_items'];
    $this->assertIsArray($items);
    $this->assertCount(1, $items);
    $this->assertArrayHasKey('uuid', $items[0]);
    $this->assertArrayHasKey('target_id', $items[0]);
    $this->assertEquals('entity_test_rev', $items[0]['type']);
    $this->assertEquals('Item 1', $items[0]['label']);
    // No fields on the summary.
    $this->assertArrayNotHasKey('fields', $items[0]);
  }

  /**
   * Tests serializeElement on entity with no LB or paragraph returns NULL.
   */
  public function testSerializeComponentOnSimpleEntity(): void {
    $entity = $this->createTestEntity([
      'name' => 'Simple',
      'field_subtitle' => 'Just text',
    ]);

    // Simple entity has no components to locate.
    $result = $this->serializer->serializeElement($entity, 'any-uuid');
    $this->assertNull($result);
  }

}
