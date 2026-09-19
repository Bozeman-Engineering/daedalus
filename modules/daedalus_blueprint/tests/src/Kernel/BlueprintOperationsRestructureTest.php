<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests BlueprintOperations restructure and reorder methods.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsRestructureTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperations
   */
  protected BlueprintOperations $operations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * Refreshes the operations service from the rebuilt container.
   */
  protected function refreshOperations(): void {
    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * Tests restructureLayout reorders sections.
   */
  public function testRestructureLayoutReorderSections(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block1 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block A',
    ]);
    $block2 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block B',
    ]);

    $section1 = $this->createSection('layout_onecol', [
      [
        'uuid' => '33540321-8948-9ff1-6e0e-b625b9a082a4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block1, 'A'),
      ],
    ]);
    $section2 = $this->createSection('layout_onecol', [
      [
        'uuid' => '1685df88-728a-709d-23ca-a012a93c4c2f',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block2, 'B'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section1, $section2],
    ]);
    $entity->save();

    // Reverse the section order.
    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['1685df88-728a-709d-23ca-a012a93c4c2f'],
          ],
        ],
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['33540321-8948-9ff1-6e0e-b625b9a082a4'],
          ],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());

    // Verify new order.
    $field = $entity->get('layout_builder__layout');
    $this->assertCount(2, $field);

    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');

    $s0_components = $field->get(0)->section->getComponents();
    $comp0 = reset($s0_components);
    $this->assertEquals('1685df88-728a-709d-23ca-a012a93c4c2f', $comp0->getUuid());
    $b0 = $daedalus_layout_adapter->extractBlockContent($comp0);
    $this->assertEquals('Block B', $b0->get('field_heading')->value);

    $s1_components = $field->get(1)->section->getComponents();
    $comp1 = reset($s1_components);
    $this->assertEquals('33540321-8948-9ff1-6e0e-b625b9a082a4', $comp1->getUuid());
    $b1 = $daedalus_layout_adapter->extractBlockContent($comp1);
    $this->assertEquals('Block A', $b1->get('field_heading')->value);
  }

  /**
   * Tests restructureLayout moves a component between sections.
   */
  public function testRestructureLayoutMoveComponentBetweenSections(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block_a = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block A',
    ]);
    $block_b = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block B',
    ]);

    $section1 = $this->createSection('layout_onecol', [
      [
        'uuid' => '33540321-8948-9ff1-6e0e-b625b9a082a4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block_a, 'A'),
      ],
      [
        'uuid' => '1685df88-728a-709d-23ca-a012a93c4c2f',
        'region' => 'content',
        'weight' => 1,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block_b, 'B'),
      ],
    ]);
    $section2 = $this->createSection('layout_onecol');

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section1, $section2],
    ]);
    $entity->save();

    // Move comp-b to section 2.
    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['33540321-8948-9ff1-6e0e-b625b9a082a4'],
          ],
        ],
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['1685df88-728a-709d-23ca-a012a93c4c2f'],
          ],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $field = $entity->get('layout_builder__layout');
    $s0_components = $field->get(0)->section->getComponents();
    $this->assertCount(1, $s0_components);
    $this->assertEquals('33540321-8948-9ff1-6e0e-b625b9a082a4', reset($s0_components)->getUuid());

    $s1_components = $field->get(1)->section->getComponents();
    $this->assertCount(1, $s1_components);
    $this->assertEquals('1685df88-728a-709d-23ca-a012a93c4c2f', reset($s1_components)->getUuid());
  }

  /**
   * Tests restructureLayout preserves component field content.
   */
  public function testRestructureLayoutComponentContentPreserved(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Important Heading',
      'field_body' => ['value' => '<p>Important content</p>', 'format' => 'plain_text'],
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Restructure to a different region/weight.
    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['faf95c53-35e7-74e1-184e-041152031df4'],
          ],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify field content is preserved.
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $comp = reset($components);
    $updated_block = $daedalus_layout_adapter->extractBlockContent($comp);
    $this->assertEquals('Important Heading', $updated_block->get('field_heading')->value);
    $this->assertEquals('<p>Important content</p>', $updated_block->get('field_body')->value);

    // Weight is derived from array position (0 = first in region).
    $comp_array = $comp->toArray();
    $this->assertEquals(0, $comp_array['weight']);
  }

  /**
   * Tests restructureLayout with a nonexistent component UUID.
   */
  public function testRestructureLayoutInvalidComponentUuid(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();

    $section = $this->createSection('layout_onecol');
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['c68c9561-d36d-b4fc-77ac-50039f354800'],
          ],
        ],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('ELEMENT_NOT_FOUND', $errors[0]->getCode());
    $this->assertStringContainsString('c68c9561-d36d-b4fc-77ac-50039f354800', $errors[0]->getMessage());
  }

  /**
   * Tests restructureLayout on entity without layout field.
   */
  public function testRestructureLayoutNoLayoutField(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $this->assertEquals('NO_LAYOUT', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests restructureLayout preserves component additional data.
   */
  public function testRestructureLayoutPreservesAdditional(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Hello',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block'),
        'additional' => ['my_custom_key' => 'my_value'],
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['faf95c53-35e7-74e1-184e-041152031df4'],
          ],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify additional data is preserved.
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $comp = reset($components);
    $comp_array = $comp->toArray();
    $this->assertEquals('my_value', $comp_array['additional']['my_custom_key']);
  }

  /**
   * Tests reorderField reorders paragraph items.
   */
  public function testReorderFieldParagraphs(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para1 = $this->createRevEntity(['name' => 'P1', 'field_heading' => 'First']);
    $para2 = $this->createRevEntity(['name' => 'P2', 'field_heading' => 'Second']);
    $para3 = $this->createRevEntity(['name' => 'P3', 'field_heading' => 'Third']);

    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [
        ['entity' => $para1],
        ['entity' => $para2],
        ['entity' => $para3],
      ],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $uuid1 = $entity->get('field_paragraphs')->get(0)->entity->uuid();
    $uuid2 = $entity->get('field_paragraphs')->get(1)->entity->uuid();
    $uuid3 = $entity->get('field_paragraphs')->get(2)->entity->uuid();

    // Reverse order.
    $result = $this->operations->reorderField($entity, [
      'field' => 'field_paragraphs',
      'items' => [
        ['uuid' => $uuid3],
        ['uuid' => $uuid2],
        ['uuid' => $uuid1],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());

    // Verify new order.
    $items = $entity->get('field_paragraphs');
    $this->assertCount(3, $items);
    $this->assertEquals('Third', $items->get(0)->entity->get('field_heading')->value);
    $this->assertEquals('Second', $items->get(1)->entity->get('field_heading')->value);
    $this->assertEquals('First', $items->get(2)->entity->get('field_heading')->value);
  }

  /**
   * Tests reorderField preserves field content.
   */
  public function testReorderFieldPreservesContent(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createRevField('field_text', 'text_long', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para1 = $this->createRevEntity([
      'name' => 'P1',
      'field_heading' => 'Alpha',
      'field_text' => ['value' => '<p>Alpha content</p>', 'format' => 'plain_text'],
    ]);
    $para2 = $this->createRevEntity([
      'name' => 'P2',
      'field_heading' => 'Beta',
      'field_text' => ['value' => '<p>Beta content</p>', 'format' => 'plain_text'],
    ]);

    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [
        ['entity' => $para1],
        ['entity' => $para2],
      ],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $uuid1 = $entity->get('field_paragraphs')->get(0)->entity->uuid();
    $uuid2 = $entity->get('field_paragraphs')->get(1)->entity->uuid();

    $result = $this->operations->reorderField($entity, [
      'field' => 'field_paragraphs',
      'items' => [
        ['uuid' => $uuid2],
        ['uuid' => $uuid1],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify all field data preserved after reorder.
    $items = $entity->get('field_paragraphs');
    $this->assertEquals('Beta', $items->get(0)->entity->get('field_heading')->value);
    $this->assertEquals('<p>Beta content</p>', $items->get(0)->entity->get('field_text')->value);
    $this->assertEquals('Alpha', $items->get(1)->entity->get('field_heading')->value);
    $this->assertEquals('<p>Alpha content</p>', $items->get(1)->entity->get('field_text')->value);
  }

  /**
   * Tests reorderField within a parent paragraph (nested).
   */
  public function testReorderFieldNested(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
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

    $child1 = $this->createRevEntity(['name' => 'C1', 'field_heading' => 'Child A']);
    $child2 = $this->createRevEntity(['name' => 'C2', 'field_heading' => 'Child B']);
    $parent = $this->createRevEntity([
      'name' => 'Parent',
      'field_items' => [
        ['entity' => $child1],
        ['entity' => $child2],
      ],
    ]);
    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [['entity' => $parent]],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $parent_entity = $entity->get('field_paragraphs')->first()->entity;
    $parent_uuid = $parent_entity->uuid();
    $child_uuid1 = $parent_entity->get('field_items')->get(0)->entity->uuid();
    $child_uuid2 = $parent_entity->get('field_items')->get(1)->entity->uuid();

    // Reverse children within parent.
    $result = $this->operations->reorderField($entity, [
      'parent_uuid' => $parent_uuid,
      'field' => 'field_items',
      'items' => [
        ['uuid' => $child_uuid2],
        ['uuid' => $child_uuid1],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify reorder.
    $updated_parent = $entity->get('field_paragraphs')->first()->entity;
    $nested_items = $updated_parent->get('field_items');
    $this->assertCount(2, $nested_items);
    $this->assertEquals('Child B', $nested_items->get(0)->entity->get('field_heading')->value);
    $this->assertEquals('Child A', $nested_items->get(1)->entity->get('field_heading')->value);
  }

  /**
   * Tests reorderField with a nonexistent parent UUID.
   */
  public function testReorderFieldParentNotFound(): void {
    $this->enableEntityReferenceRevisions();
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $entity = $this->createTestEntity(['name' => 'Page']);

    $result = $this->operations->reorderField($entity, [
      'parent_uuid' => 'c68c9561-d36d-b4fc-77ac-50039f354800',
      'field' => 'field_paragraphs',
      'items' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $this->assertEquals('PARENT_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests reorderField with a nonexistent item UUID.
   */
  public function testReorderFieldItemNotFound(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para = $this->createRevEntity(['name' => 'P1', 'field_heading' => 'A']);
    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [['entity' => $para]],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->operations->reorderField($entity, [
      'field' => 'field_paragraphs',
      'items' => [
        ['uuid' => 'c68c9561-d36d-b4fc-77ac-50039f354800'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('ITEM_NOT_FOUND', $errors[0]->getCode());
    $this->assertStringContainsString('c68c9561-d36d-b4fc-77ac-50039f354800', $errors[0]->getMessage());
  }

  /**
   * Tests reorderField with an invalid field (not ERR type).
   */
  public function testReorderFieldInvalidField(): void {
    $entity = $this->createTestEntity(['name' => 'Page']);

    $result = $this->operations->reorderField($entity, [
      'field' => 'field_subtitle',
      'items' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $this->assertEquals('INVALID_FIELD', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests restructureLayout drops components not included in the structure.
   */
  public function testRestructureLayoutDropsOmittedComponents(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block1 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Keep',
    ]);
    $block2 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Drop',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '53909034-a60d-6832-7e37-bcb3a5ad945f',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block1, 'Keep'),
      ],
      [
        'uuid' => '120104fe-e39f-5fc0-b9e8-d6a3102f10aa',
        'region' => 'content',
        'weight' => 1,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block2, 'Drop'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Only include comp-keep in the restructure — comp-drop is dropped.
    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['53909034-a60d-6832-7e37-bcb3a5ad945f'],
          ],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $this->assertCount(1, $components);
    $this->assertEquals('53909034-a60d-6832-7e37-bcb3a5ad945f', reset($components)->getUuid());
  }

  /**
   * Tests restructureLayout adds a new empty section.
   */
  public function testRestructureLayoutAddEmptySection(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();

    $section = $this->createSection('layout_onecol');
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [],
        ],
        [
          'layout_id' => 'layout_twocol_section',
          'regions' => [],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $field = $entity->get('layout_builder__layout');
    $this->assertCount(2, $field);
    $this->assertSame('layout_onecol', $field->get(0)->section->getLayoutId());
    $this->assertSame('layout_twocol_section', $field->get(1)->section->getLayoutId());
    $this->assertEmpty($field->get(1)->section->getComponents());
  }

  /**
   * Tests restructureLayout adds a section with new inline blocks.
   */
  public function testRestructureLayoutAddSectionWithBlocks(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$this->createSection('layout_onecol')],
    ]);
    $entity->save();

    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => '5f8ae8cb-1ac9-4ba0-47cf-6c0d626cbf90',
                'block_type' => 'text_section',
                'label' => 'Hero Block',
                'block_fields' => [
                  'field_heading' => 'Welcome',
                  'field_body' => [
                    'value' => '<p>Hello world</p>',
                    'format' => 'plain_text',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $field = $entity->get('layout_builder__layout');
    $this->assertCount(1, $field);
    $section = $field->first()->section;
    $components = $section->getComponents();
    $this->assertCount(1, $components);

    $component = $section->getComponent('5f8ae8cb-1ac9-4ba0-47cf-6c0d626cbf90');
    $this->assertSame('content', $component->getRegion());

    $config = $component->get('configuration');
    $this->assertSame('inline_block:text_section', $config['id']);
    $this->assertSame('Hero Block', $config['label']);
    $this->assertNotEmpty($config['block_serialized']);

    $block = $this->unserializeBlock($config['block_serialized']);
    $this->assertEquals('Welcome', $block->get('field_heading')->value);
    $this->assertEquals('<p>Hello world</p>', $block->get('field_body')->value);
  }

  /**
   * Tests restructureLayout with mixed relocate and new block creation.
   */
  public function testRestructureLayoutMixedRelocateAndCreate(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Existing Block',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Existing'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Relocate existing block to section 1, add new block to section 2.
    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['a0bd9075-abce-9a83-7b79-d4db72ff887b'],
          ],
        ],
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => '330f09a5-3422-d1af-1dfd-2fa33594cd19',
                'block_type' => 'text_section',
                'label' => 'New Block',
                'block_fields' => [
                  'field_heading' => 'Fresh Content',
                ],
              ],
            ],
          ],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $field = $entity->get('layout_builder__layout');
    $this->assertCount(2, $field);

    // Section 1: relocated existing block.
    $s0_components = $field->get(0)->section->getComponents();
    $this->assertCount(1, $s0_components);
    $comp0 = reset($s0_components);
    $this->assertEquals('a0bd9075-abce-9a83-7b79-d4db72ff887b', $comp0->getUuid());
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $existing_block = $daedalus_layout_adapter->extractBlockContent($comp0);
    $this->assertEquals('Existing Block', $existing_block->get('field_heading')->value);

    // Section 2: newly created block.
    $s1_components = $field->get(1)->section->getComponents();
    $this->assertCount(1, $s1_components);
    $comp1 = reset($s1_components);
    $this->assertEquals('330f09a5-3422-d1af-1dfd-2fa33594cd19', $comp1->getUuid());
    $config = $comp1->get('configuration');
    $new_block = $this->unserializeBlock($config['block_serialized']);
    $this->assertEquals('Fresh Content', $new_block->get('field_heading')->value);

    // Verify targets include both components.
    $targets = $result->getTargets();
    $target_uuids = array_column($targets, 'uuid');
    $this->assertContains('a0bd9075-abce-9a83-7b79-d4db72ff887b', $target_uuids);
    $this->assertContains('330f09a5-3422-d1af-1dfd-2fa33594cd19', $target_uuids);
  }

  /**
   * Tests restructureLayout fails with invalid block_type in new block.
   */
  public function testRestructureLayoutInvalidBlockType(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$this->createSection('layout_onecol')],
    ]);
    $entity->save();

    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => '5f8ae8cb-1ac9-4ba0-47cf-6c0d626cbf90',
                'block_type' => 'nonexistent_type',
                'block_fields' => [],
              ],
            ],
          ],
        ],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('nonexistent_type', $errors[0]->getMessage());
  }

  /**
   * Tests restructureLayout preserves section layout settings when omitted.
   *
   * A structural restructure that does not declare layout_settings must leave
   * the section's existing managed styling untouched. This is the
   * restructure_layout styling-wipe bug: previously, omitting
   * layout_settings cleared every managed key (background, width, …).
   */
  public function testRestructureLayoutPreservesLayoutSettingsWhenOmitted(): void {
    $this->enableLayoutBuilder();
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'section_layout_settings' => [
        'layout_onecol' => [
          'width' => ['label' => 'Width', 'schema' => ['type' => 'string']],
          'background' => ['label' => 'Background', 'schema' => ['type' => 'string']],
        ],
      ],
    ]);
    $this->container->get('cache.default')->deleteAll();
    $this->refreshOperations();

    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Hello',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block'),
      ],
    ], [
      // Managed (width, background) + non-managed (custom_non_managed)
      // settings.
      'width' => 'max-w-screen-lg',
      'background' => 'bg-primary',
      'custom_non_managed' => 'keep-me',
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Restructure declares arrangement only — no layout_settings key at all.
    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['faf95c53-35e7-74e1-184e-041152031df4'],
          ],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $settings = $entity->get('layout_builder__layout')->first()->section->getLayoutSettings();
    // Managed keys preserved (the bug fix) and non-managed key preserved.
    $this->assertSame('max-w-screen-lg', $settings['width']);
    $this->assertSame('bg-primary', $settings['background']);
    $this->assertSame('keep-me', $settings['custom_non_managed']);
  }

  /**
   * Tests the managed-key PATCH contract on a declared layout_settings scope.
   *
   * When the incoming section declares layout_settings, each managed key
   * follows the same PATCH contract as fields: a declared value updates, an
   * explicit NULL clears, and an omitted key keeps its previous value.
   */
  public function testRestructureLayoutPatchesManagedLayoutSettings(): void {
    $this->enableLayoutBuilder();
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'section_layout_settings' => [
        'layout_onecol' => [
          'width' => ['label' => 'Width', 'schema' => ['type' => 'string']],
          'background' => ['label' => 'Background', 'schema' => ['type' => 'string']],
          'padding' => ['label' => 'Padding', 'schema' => ['type' => 'string']],
        ],
      ],
    ]);
    $this->container->get('cache.default')->deleteAll();
    $this->refreshOperations();

    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Hello',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block'),
      ],
    ], [
      'width' => 'max-w-screen-lg',
      'background' => 'bg-primary',
      'padding' => 'p-4',
      'custom_non_managed' => 'keep-me',
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Scope present: background declared (update), width explicit NULL
    // (clear), padding omitted (preserve).
    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'layout_settings' => [
            'background' => 'bg-secondary',
            'width' => NULL,
          ],
          'regions' => [
            'content' => ['faf95c53-35e7-74e1-184e-041152031df4'],
          ],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $settings = $entity->get('layout_builder__layout')->first()->section->getLayoutSettings();
    // Declared managed key updated.
    $this->assertSame('bg-secondary', $settings['background']);
    // Managed key set to explicit NULL is cleared.
    $this->assertArrayNotHasKey('width', $settings);
    // Managed key omitted within the declared scope is preserved.
    $this->assertSame('p-4', $settings['padding']);
    // Non-managed key is always preserved.
    $this->assertSame('keep-me', $settings['custom_non_managed']);
  }

  /**
   * Tests restructureLayout preserves managed inline config when omitted.
   *
   * Re-declaring an existing component as an object (block_type present)
   * without a configuration key must not wipe its managed inline-block config.
   * Same bug class as the section styling wipe, on the
   * inline_block_configuration scope.
   */
  public function testRestructureLayoutPreservesInlineConfigWhenOmitted(): void {
    $this->enableLayoutBuilder();
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'text_section' => [
          'title_size' => ['label' => 'Title size', 'schema' => ['type' => 'string']],
        ],
      ],
    ]);
    $this->container->get('cache.default')->deleteAll();
    $this->refreshOperations();

    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Hello',
    ]);

    // Seed a managed inline-config key on the existing component.
    $config = $this->createInlineBlockConfiguration('text_section', $block, 'Block');
    $config['title_size'] = 'text-2xl';

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $config,
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    // Re-declare comp-1 as an object with block_fields but no configuration
    // key.
    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
                'block_type' => 'text_section',
                'block_fields' => [
                  'field_heading' => 'Updated',
                ],
              ],
            ],
          ],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $component = $entity->get('layout_builder__layout')->first()->section->getComponent('faf95c53-35e7-74e1-184e-041152031df4');
    $component_config = $component->get('configuration');
    // Managed inline config preserved through the structural re-declaration.
    $this->assertSame('text-2xl', $component_config['title_size']);
    // The field change still applied.
    $block = $this->unserializeBlock($component_config['block_serialized']);
    $this->assertEquals('Updated', $block->get('field_heading')->value);
  }

  /**
   * Tests a restructure payload repeating one uuid on two nodes is refused.
   *
   * Placing the same existing component into two sections would duplicate
   * one identity across the tree (doppelganger elements), so the repeat is
   * refused with the same corrective as an op-level uuid collision.
   */
  public function testRestructureLayoutRepeatedUuidRefused(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block A',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '33540321-8948-9ff1-6e0e-b625b9a082a4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'A'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['33540321-8948-9ff1-6e0e-b625b9a082a4'],
          ],
        ],
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => ['33540321-8948-9ff1-6e0e-b625b9a082a4'],
          ],
        ],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = array_values(array_filter(
      $result->getErrors(),
      fn($error) => $error->getCode() === 'UUID_IN_USE',
    ));
    $this->assertCount(1, $errors, 'Only the repeat errors; the first occurrence claims the uuid.');
    $this->assertSame('layout_builder__layout[1].regions.content[0]', $errors[0]->getPath());
  }

  /**
   * Tests restructure re-using existing uuids once each still relocates.
   *
   * Matching a uuid that exists on the entity is the legitimate
   * relocate/update path; the within-payload repeat check must not touch
   * it. One payload exercises both forms: a bare-string relocation and an
   * object re-declaration, each re-using its entity uuid exactly once.
   */
  public function testRestructureLayoutExistingUuidsStillRelocate(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block_a = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block A',
    ]);
    $block_b = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block B',
    ]);

    $section1 = $this->createSection('layout_onecol', [
      [
        'uuid' => '33540321-8948-9ff1-6e0e-b625b9a082a4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block_a, 'A'),
      ],
    ]);
    $section2 = $this->createSection('layout_onecol', [
      [
        'uuid' => '1685df88-728a-709d-23ca-a012a93c4c2f',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block_b, 'B'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section1, $section2],
    ]);
    $entity->save();

    // Collapse into one section: A relocated as a bare uuid, B re-declared
    // as an object (update path), each existing uuid used exactly once.
    $result = $this->operations->restructureLayout($entity, [
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              '33540321-8948-9ff1-6e0e-b625b9a082a4',
              [
                'uuid' => '1685df88-728a-709d-23ca-a012a93c4c2f',
                'block_type' => 'text_section',
                'block_fields' => [
                  'field_heading' => 'Block B updated',
                ],
              ],
            ],
          ],
        ],
      ],
    ]);

    $this->assertTrue($result->isSuccess(), 'Expected success. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $field = $entity->get('layout_builder__layout');
    $this->assertCount(1, $field);

    $components = $field->get(0)->section->getComponents();
    $this->assertCount(2, $components);
    $this->assertSame(
      ['33540321-8948-9ff1-6e0e-b625b9a082a4', '1685df88-728a-709d-23ca-a012a93c4c2f'],
      array_keys($components),
    );

    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $relocated = $daedalus_layout_adapter->extractBlockContent($components['33540321-8948-9ff1-6e0e-b625b9a082a4']);
    $this->assertEquals('Block A', $relocated->get('field_heading')->value);
    $updated = $this->unserializeBlock($components['1685df88-728a-709d-23ca-a012a93c4c2f']->get('configuration')['block_serialized']);
    $this->assertEquals('Block B updated', $updated->get('field_heading')->value);
  }

}
