<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\daedalus_blueprint\BlueprintSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests BlueprintSerializer::serializeSummary().
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintSerializer::class)]
#[Group('daedalus_blueprint')]
class BlueprintSerializerSummaryTest extends BlueprintKernelTestBase {

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
   *
   * Call after enableLayoutBuilder() to ensure the serializer uses
   * up-to-date service references.
   */
  protected function refreshSerializer(): void {
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
  }

  /**
   * Tests summary includes simple scalar fields but omits large text fields.
   */
  public function testSummaryIncludesSimpleFieldsOmitsLargeText(): void {
    $entity = $this->createTestEntity([
      'name' => 'Test Page',
      'field_subtitle' => 'A subtitle',
      'field_count' => 42,
      'field_published' => TRUE,
      'field_email' => 'test@example.com',
      'field_description' => [
        'value' => '<p>Long description text</p>',
        'format' => 'plain_text',
      ],
      'field_body' => [
        'value' => '<p>Full body text</p>',
        'format' => 'plain_text',
        'summary' => 'Short summary',
      ],
    ]);

    $result = $this->serializer->serializeSummary($entity);

    $this->assertEquals('entity_test', $result['entity_type']);
    $this->assertEquals('entity_test', $result['bundle']);
    $this->assertArrayHasKey('_root_entity', $result);
    $this->assertArrayHasKey('fields', $result);

    $fields = $result['fields'];

    // Simple scalars should be present.
    $this->assertArrayHasKey('name', $fields);
    $this->assertEquals('Test Page', $fields['name']);
    $this->assertArrayHasKey('field_subtitle', $fields);
    $this->assertEquals('A subtitle', $fields['field_subtitle']);
    $this->assertArrayHasKey('field_count', $fields);
    $this->assertEquals(42, $fields['field_count']);
    $this->assertArrayHasKey('field_published', $fields);
    $this->assertArrayHasKey('field_email', $fields);

    // Large text fields should NOT be present.
    $this->assertArrayNotHasKey('field_description', $fields);
    $this->assertArrayNotHasKey('field_body', $fields);
  }

  /**
   * Tests summary of entity with entity_reference fields shows labels.
   */
  public function testSummaryEntityReferenceShowsLabels(): void {
    $this->installEntitySchema('entity_test_label');
    $this->enableModules(['entity_test']);

    // Create an entity reference field.
    FieldStorageConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'entity_test'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Reference',
      'settings' => [
        'handler' => 'default:entity_test',
        'handler_settings' => [],
      ],
    ])->save();

    // Create a referenced entity.
    $referenced = $this->createTestEntity(['name' => 'Referenced Entity']);

    // Create the parent entity.
    $entity = $this->createTestEntity([
      'name' => 'Parent',
      'field_ref' => ['target_id' => $referenced->id()],
    ]);

    $result = $this->serializer->serializeSummary($entity);

    // Entity reference should be present in summary with label.
    $this->assertArrayHasKey('field_ref', $result['fields']);
    $ref_value = $result['fields']['field_ref'];
    $this->assertArrayHasKey('target_id', $ref_value);
    $this->assertArrayHasKey('_label', $ref_value);
    $this->assertEquals('Referenced Entity', $ref_value['_label']);
  }

  /**
   * Tests summary of LB entity shows structure without block_fields.
   */
  public function testSummaryLbEntityNoBlockFields(): void {
    $this->enableLayoutBuilder();
    $this->refreshSerializer();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_body' => ['value' => '<p>Content here</p>', 'format' => 'plain_text'],
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

    $result = $this->serializer->serializeSummary($entity);

    $this->assertArrayHasKey('layout_builder__layout', $result);
    $layout = $result['layout_builder__layout'];
    $this->assertCount(1, $layout);

    $section_data = $layout[0];
    $this->assertEquals('layout_onecol', $section_data['layout_id']);
    $this->assertArrayHasKey('regions', $section_data);
    $this->assertCount(1, $section_data['regions']['content']);

    $component = $section_data['regions']['content'][0];
    $this->assertEquals('152d9378-ad8f-1c16-1c35-cd161a184ec5', $component['uuid']);
    $this->assertEquals('text_section', $component['block_type']);
    $this->assertEquals('Hero Text', $component['label']);

    // block_fields should NOT be present in summary.
    $this->assertArrayNotHasKey('block_fields', $component);
    // view_mode and block_revision_id should NOT be present in summary.
    $this->assertArrayNotHasKey('view_mode', $component);
    $this->assertArrayNotHasKey('block_revision_id', $component);
  }

  /**
   * Tests summary of paragraph entity shows structure without field values.
   */
  public function testSummaryParagraphEntityNoFieldValues(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_text', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para = $this->createRevEntity([
      'name' => 'Introduction',
      'field_text' => 'Intro text content',
    ]);

    $entity = $this->createTestEntity([
      'name' => 'My Article',
      'field_paragraphs' => [['entity' => $para]],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->serializer->serializeSummary($entity);

    $this->assertArrayHasKey('fields', $result);
    $fields = $result['fields'];
    $this->assertArrayHasKey('field_paragraphs', $fields);

    $paragraph_summary = $fields['field_paragraphs'];
    $this->assertIsArray($paragraph_summary);

    // Since cardinality is unlimited, it's a list.
    $first = $paragraph_summary[0];
    $this->assertArrayHasKey('target_id', $first);
    $this->assertArrayHasKey('uuid', $first);
    $this->assertArrayHasKey('type', $first);
    $this->assertArrayHasKey('label', $first);
    $this->assertEquals('entity_test_rev', $first['type']);

    // Field values should NOT be present.
    $this->assertArrayNotHasKey('fields', $first);
    $this->assertArrayNotHasKey('field_text', $first);
  }

  /**
   * Tests summary with nested paragraphs shows children recursively.
   */
  public function testSummaryNestedParagraphsRecurse(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_text', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );
    $this->createEntityReferenceRevisionsField(
      'field_children',
      'entity_test_rev',
      'entity_test_rev',
      'entity_test_rev',
    );

    $child1 = $this->createRevEntity(['name' => 'Question 1']);
    $child2 = $this->createRevEntity(['name' => 'Question 2']);

    $parent = $this->createRevEntity([
      'name' => 'FAQ Section',
      'field_children' => [
        ['entity' => $child1],
        ['entity' => $child2],
      ],
    ]);

    $entity = $this->createTestEntity([
      'name' => 'My Page',
      'field_paragraphs' => [['entity' => $parent]],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->serializer->serializeSummary($entity);

    $paragraph_summary = $result['fields']['field_paragraphs'][0];

    // Parent paragraph should have children.
    $this->assertArrayHasKey('children', $paragraph_summary);
    $this->assertCount(1, $paragraph_summary['children']);

    $child_group = $paragraph_summary['children'][0];
    $this->assertEquals('field_children', $child_group['field']);
    $this->assertCount(2, $child_group['items']);
    $this->assertEquals('Question 1', $child_group['items'][0]['label']);
    $this->assertEquals('Question 2', $child_group['items'][1]['label']);

    // No field values on children.
    $this->assertArrayNotHasKey('fields', $child_group['items'][0]);
  }

  /**
   * Tests summary with deeply nested paragraphs (5 levels).
   */
  public function testSummaryDeeplyNestedParagraphs(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_text', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );
    $this->createEntityReferenceRevisionsField(
      'field_children',
      'entity_test_rev',
      'entity_test_rev',
      'entity_test_rev',
    );

    // Build from deepest to shallowest.
    $level5 = $this->createRevEntity(['name' => 'Level 5']);
    $level4 = $this->createRevEntity([
      'name' => 'Level 4',
      'field_children' => [['entity' => $level5]],
    ]);
    $level3 = $this->createRevEntity([
      'name' => 'Level 3',
      'field_children' => [['entity' => $level4]],
    ]);
    $level2 = $this->createRevEntity([
      'name' => 'Level 2',
      'field_children' => [['entity' => $level3]],
    ]);
    $level1 = $this->createRevEntity([
      'name' => 'Level 1',
      'field_children' => [['entity' => $level2]],
    ]);

    $entity = $this->createTestEntity([
      'name' => 'Deep Page',
      'field_paragraphs' => [['entity' => $level1]],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->serializer->serializeSummary($entity);

    // Navigate the hierarchy.
    $para = $result['fields']['field_paragraphs'][0];
    $this->assertEquals('Level 1', $para['label']);
    $this->assertArrayHasKey('children', $para);

    $l2 = $para['children'][0]['items'][0];
    $this->assertEquals('Level 2', $l2['label']);
    $this->assertArrayHasKey('children', $l2);

    $l3 = $l2['children'][0]['items'][0];
    $this->assertEquals('Level 3', $l3['label']);
    $this->assertArrayHasKey('children', $l3);

    $l4 = $l3['children'][0]['items'][0];
    $this->assertEquals('Level 4', $l4['label']);
    $this->assertArrayHasKey('children', $l4);

    $l5 = $l4['children'][0]['items'][0];
    $this->assertEquals('Level 5', $l5['label']);
    // Level 5 has no children.
    $this->assertArrayNotHasKey('children', $l5);
  }

  /**
   * Tests summary with nested LB layout summarizes recursively.
   */
  public function testSummaryNestedSectionRecursion(): void {
    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_layout',
    ]);
    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_body', 'text_long', 'text_section');
    $this->refreshSerializer();

    // Build a nested section holding a text block.
    $nested_block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Nested Block',
      'reusable' => FALSE,
      'field_body' => ['value' => 'Nested content', 'format' => 'plain_text'],
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
      'name' => 'Nested Layout Page',
      'layout_builder__layout' => [$root_section],
    ]);
    $entity->save();

    $result = $this->serializer->serializeSummary($entity);

    // The nested section summarizes as a section node with the same shape
    // as a top-level section, recursively; no block facade.
    $layout = $result['layout_builder__layout'];
    $node = $layout[0]['regions']['content'][0];
    $this->assertEquals('layout_onecol', $node['layout_id']);
    $this->assertEquals('98ecbc05-28b3-48c5-895e-86dfa276af6c', $node['uuid']);
    $this->assertEquals('Band', $node['label']);
    $this->assertArrayNotHasKey('block_type', $node);
    $this->assertArrayNotHasKey('block_fields', $node);

    $nested_comp = $node['regions']['content'][0];
    $this->assertEquals('acab1f60-1b5a-4186-a39b-c2215857849e', $nested_comp['uuid']);
    $this->assertEquals('Nested Block', $nested_comp['label']);
    $this->assertArrayNotHasKey('block_fields', $nested_comp);
  }

  /**
   * Tests summary header matches full serialize header.
   */
  public function testSummaryHeaderMatchesFullSerialize(): void {
    $entity = $this->createTestEntity([
      'name' => 'Test',
      'field_subtitle' => 'Sub',
    ]);

    $full = $this->serializer->serialize($entity);
    $summary = $this->serializer->serializeSummary($entity);

    // Headers should match.
    $this->assertEquals($full['entity_type'], $summary['entity_type']);
    $this->assertEquals($full['bundle'], $summary['bundle']);
    $this->assertEquals($full['_root_entity'], $summary['_root_entity']);
  }

  /**
   * Tests summary with multiple LB components shows all with correct order.
   */
  public function testSummaryMultipleComponents(): void {
    $this->enableLayoutBuilder();
    $this->refreshSerializer();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockContentType('image_section', 'Image Section');

    $block1 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
    ]);
    $block2 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'image_section',
      'reusable' => FALSE,
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '3698d964-d4d1-3aed-bdca-1e1e2505dc33',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block1, 'Text Block'),
      ],
      [
        'uuid' => '5fd771e2-359b-0994-977e-6c80989ad942',
        'region' => 'content',
        'weight' => 1,
        'configuration' => $this->createInlineBlockConfiguration('image_section', $block2, 'Image Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->serializer->serializeSummary($entity);

    $components = $result['layout_builder__layout'][0]['regions']['content'];
    $this->assertCount(2, $components);

    // Index components by UUID for order-independent assertions.
    $by_uuid = [];
    foreach ($components as $comp) {
      $by_uuid[$comp['uuid']] = $comp;
    }

    // Both components present with correct metadata.
    $this->assertEquals('text_section', $by_uuid['3698d964-d4d1-3aed-bdca-1e1e2505dc33']['block_type']);
    $this->assertEquals('Text Block', $by_uuid['3698d964-d4d1-3aed-bdca-1e1e2505dc33']['label']);
    $this->assertEquals('image_section', $by_uuid['5fd771e2-359b-0994-977e-6c80989ad942']['block_type']);
    $this->assertEquals('Image Block', $by_uuid['5fd771e2-359b-0994-977e-6c80989ad942']['label']);
  }

  /**
   * Tests summary of entity with no content fields or layout.
   */
  public function testSummaryEmptyEntity(): void {
    $entity = $this->createTestEntity(['name' => 'Empty']);

    $result = $this->serializer->serializeSummary($entity);

    $this->assertEquals('entity_test', $result['entity_type']);
    $this->assertEquals('entity_test', $result['bundle']);
    $this->assertArrayHasKey('_root_entity', $result);
    $this->assertArrayHasKey('fields', $result);
    // Should have the name field at minimum.
    $this->assertEquals('Empty', $result['fields']['name']);
  }

  /**
   * Tests summary with non-inline block component shows plugin_id.
   */
  public function testSummaryNonInlineBlockShowsPluginId(): void {
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

    $result = $this->serializer->serializeSummary($entity);

    $component = $result['layout_builder__layout'][0]['regions']['content'][0];
    $this->assertEquals('c578c767-7dac-a205-0c91-3fb82af82d46', $component['uuid']);
    $this->assertEquals('system_branding_block', $component['plugin_id']);
    $this->assertArrayNotHasKey('block_type', $component);
    $this->assertArrayNotHasKey('block_fields', $component);
    $this->assertArrayNotHasKey('configuration', $component);
  }

  /**
   * Tests summary of paragraph with no nested children has no children key.
   */
  public function testSummaryParagraphWithoutChildrenHasNoChildrenKey(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_text', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para = $this->createRevEntity([
      'name' => 'Simple paragraph',
      'field_text' => 'Just text',
    ]);

    $entity = $this->createTestEntity([
      'name' => 'Test',
      'field_paragraphs' => [['entity' => $para]],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->serializer->serializeSummary($entity);

    $paragraph_summary = $result['fields']['field_paragraphs'][0];
    $this->assertArrayNotHasKey('children', $paragraph_summary);
  }

}
