<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests BlueprintOperations add and remove methods.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsAddRemoveTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperations
   */
  protected BlueprintOperations $operations;

  /**
   * The seed component LB placements anchor beside.
   *
   * Section anchors require daedalus_layout section UUIDs (absent in this kernel
   * env), so LB fixtures ship one component and the anchor grammar places
   * after it.
   */
  protected const SEED_UUID = '52d6ac74-31e5-4b78-9d1c-2a86f0c53b6e';

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
   * Tests addElement adds an LB inline block to a section.
   */
  public function testAddComponentLbSection(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => self::SEED_UUID,
        'region' => 'content',
        'weight' => 0,
        'configuration' => [
          'id' => 'system_powered_by_block',
          'provider' => 'system',
          'label' => 'Anchor seed',
          'label_display' => '0',
        ],
      ],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->addElement($entity, [
      'anchor' => self::SEED_UUID,
      'placement' => 'after',
    ], [
      'block_type' => 'text_section',
      'label' => 'New Block',
      'block_fields' => [
        'field_heading' => 'Hello World',
        'field_body' => ['value' => '<p>Content</p>', 'format' => 'plain_text'],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());

    // Verify the component was added to the section beside the seed.
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    unset($components[self::SEED_UUID]);
    $this->assertCount(1, $components);

    // Verify the block_content fields.
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $component = reset($components);
    $block = $daedalus_layout_adapter->extractBlockContent($component);
    $this->assertEquals('Hello World', $block->get('field_heading')->value);
    $this->assertEquals('<p>Content</p>', $block->get('field_body')->value);

    // Verify the config.
    $config = $component->get('configuration');
    $this->assertEquals('inline_block:text_section', $config['id']);
    $this->assertEquals('New Block', $config['label']);
  }

  /**
   * Tests addElement with a client-provided UUID.
   */
  public function testAddComponentLbWithClientUuid(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => self::SEED_UUID,
        'region' => 'content',
        'weight' => 0,
        'configuration' => [
          'id' => 'system_powered_by_block',
          'provider' => 'system',
          'label' => 'Anchor seed',
          'label_display' => '0',
        ],
      ],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->addElement($entity, [
      'anchor' => self::SEED_UUID,
      'placement' => 'after',
    ], [
      'block_type' => 'text_section',
      'uuid' => 'd1e473a7-8eb1-e770-e7f6-021fdbaaf8d5',
      'label' => 'Custom Block',
      'block_fields' => [
        'field_heading' => 'Test',
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify custom UUID was assigned.
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $this->assertArrayHasKey('d1e473a7-8eb1-e770-e7f6-021fdbaaf8d5', $components);
  }

  /**
   * Tests addElement's opaque additional metadata passthrough.
   *
   * The additional map lands on the SectionComponent's additional slot
   * (never in plugin configuration), and a non-map fails loudly before
   * anything is placed. Stencil provenance stamping rides this seam.
   */
  public function testAddComponentLbAdditional(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => self::SEED_UUID,
        'region' => 'content',
        'weight' => 0,
        'configuration' => [
          'id' => 'system_powered_by_block',
          'provider' => 'system',
          'label' => 'Anchor seed',
          'label_display' => '0',
        ],
      ],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $location = ['anchor' => self::SEED_UUID, 'placement' => 'after'];
    $result = $this->operations->addElement($entity, $location, [
      'block_type' => 'text_section',
      'uuid' => '83b529a6-15f8-4c9c-b48a-4f7e0d10c1d2',
      'additional' => ['my_module' => ['marker' => 'value']],
    ]);

    $this->assertTrue($result->isSuccess());
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $component = $components['83b529a6-15f8-4c9c-b48a-4f7e0d10c1d2'];
    $this->assertSame(['marker' => 'value'], $component->get('my_module'));
    $this->assertArrayNotHasKey('my_module', $component->get('configuration'));

    // A non-map additional is a grammar error, refused before placement.
    $result = $this->operations->addElement($entity, $location, [
      'block_type' => 'text_section',
      'additional' => ['not', 'a', 'map'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_ADDITIONAL', $result->getErrors()[0]->getCode());
    $this->assertCount(2, $updated_section->getComponents());
  }

  /**
   * Tests addElement with an invalid block type.
   */
  public function testAddComponentLbInvalidBlockType(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => self::SEED_UUID,
        'region' => 'content',
        'weight' => 0,
        'configuration' => [
          'id' => 'system_powered_by_block',
          'provider' => 'system',
          'label' => 'Anchor seed',
          'label_display' => '0',
        ],
      ],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->addElement($entity, [
      'anchor' => self::SEED_UUID,
      'placement' => 'after',
    ], [
      'block_type' => 'nonexistent_type',
      'block_fields' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('INVALID_BLOCK_TYPE', $errors[0]->getCode());
  }

  /**
   * Tests addElement with a nonexistent anchor.
   */
  public function testAddComponentLbAnchorNotFound(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$this->createSection('layout_onecol')],
    ]);
    $entity->save();

    $result = $this->operations->addElement($entity, [
      'anchor' => '0d1d6dcd-2f11-45f5-9db1-f0d6b8f3a2ce',
      'placement' => 'after',
    ], [
      'block_type' => 'text_section',
      'block_fields' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $this->assertEquals('ANCHOR_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests addElement with unknown block field.
   */
  public function testAddComponentLbUnknownBlockField(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => self::SEED_UUID,
        'region' => 'content',
        'weight' => 0,
        'configuration' => [
          'id' => 'system_powered_by_block',
          'provider' => 'system',
          'label' => 'Anchor seed',
          'label_display' => '0',
        ],
      ],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->addElement($entity, [
      'anchor' => self::SEED_UUID,
      'placement' => 'after',
    ], [
      'block_type' => 'text_section',
      'block_fields' => [
        'field_nonexistent' => 'value',
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('UNKNOWN_PROPERTY', $errors[0]->getCode());
    $this->assertEquals('properties.field_nonexistent', $errors[0]->getPath());
    $this->assertArrayHasKey('available', $errors[0]->getContext());
  }

  /**
   * Tests addElement preserves existing components in the section.
   */
  public function testAddComponentLbPreservesExisting(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $existing_block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Existing Block',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $existing_block, 'Existing'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->addElement($entity, [
      'anchor' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b',
      'placement' => 'after',
    ], [
      'block_type' => 'text_section',
      'label' => 'New Block',
      'block_fields' => [
        'field_heading' => 'New Block',
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify both components exist.
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $this->assertCount(2, $components);

    // Verify existing is untouched.
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $comp_map = [];
    foreach ($components as $comp) {
      $comp_map[$comp->getUuid()] = $comp;
    }
    $existing_block_content = $daedalus_layout_adapter->extractBlockContent($comp_map['a0bd9075-abce-9a83-7b79-d4db72ff887b']);
    $this->assertEquals('Existing Block', $existing_block_content->get('field_heading')->value);
  }

  /**
   * Tests addElement adds a paragraph to an ERR field.
   */
  public function testAddComponentParagraph(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $existing_para = $this->createRevEntity([
      'name' => 'Existing',
      'field_heading' => 'First',
    ]);

    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [['entity' => $existing_para]],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->operations->addElement($entity, [
      'field' => 'field_paragraphs',
      'position' => 0,
    ], [
      'type' => 'entity_test_rev',
      'fields' => [
        'field_heading' => 'Inserted',
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());

    // Verify insertion at position 0.
    $items = $entity->get('field_paragraphs');
    $this->assertCount(2, $items);
    $this->assertEquals('Inserted', $items->get(0)->entity->get('field_heading')->value);
    $this->assertEquals('First', $items->get(1)->entity->get('field_heading')->value);
  }

  /**
   * Tests addElement appends a paragraph when no position specified.
   */
  public function testAddComponentParagraphAppend(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $existing_para = $this->createRevEntity([
      'name' => 'Existing',
      'field_heading' => 'First',
    ]);

    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [['entity' => $existing_para]],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->operations->addElement($entity, [
      'field' => 'field_paragraphs',
    ], [
      'type' => 'entity_test_rev',
      'fields' => [
        'field_heading' => 'Appended',
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify appended at end.
    $items = $entity->get('field_paragraphs');
    $this->assertCount(2, $items);
    $this->assertEquals('First', $items->get(0)->entity->get('field_heading')->value);
    $this->assertEquals('Appended', $items->get(1)->entity->get('field_heading')->value);
  }

  /**
   * Tests addElement on a nested paragraph field.
   */
  public function testAddComponentParagraphNested(): void {
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

    $child = $this->createRevEntity([
      'name' => 'Child',
      'field_heading' => 'Existing Child',
    ]);
    $parent = $this->createRevEntity([
      'name' => 'Parent',
      'field_items' => [['entity' => $child]],
    ]);
    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [['entity' => $parent]],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $parent_uuid = $entity->get('field_paragraphs')->first()->entity->uuid();

    $result = $this->operations->addElement($entity, [
      'parent_uuid' => $parent_uuid,
      'field' => 'field_items',
      'position' => 0,
    ], [
      'type' => 'entity_test_rev',
      'fields' => [
        'field_heading' => 'New Child',
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify nested insertion.
    $parent_entity = $entity->get('field_paragraphs')->first()->entity;
    $nested_items = $parent_entity->get('field_items');
    $this->assertCount(2, $nested_items);
    $this->assertEquals('New Child', $nested_items->get(0)->entity->get('field_heading')->value);
    $this->assertEquals('Existing Child', $nested_items->get(1)->entity->get('field_heading')->value);
  }

  /**
   * Tests addElement with a nonexistent parent UUID.
   */
  public function testAddComponentParagraphParentNotFound(): void {
    $this->enableEntityReferenceRevisions();
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $entity = $this->createTestEntity(['name' => 'Page']);

    $result = $this->operations->addElement($entity, [
      'parent_uuid' => 'c68c9561-d36d-b4fc-77ac-50039f354800',
      'field' => 'field_paragraphs',
    ], [
      'type' => 'entity_test_rev',
      'fields' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $this->assertEquals('PARENT_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests addElement with an invalid target field.
   */
  public function testAddComponentParagraphFieldNotFound(): void {
    $entity = $this->createTestEntity(['name' => 'Page']);

    $result = $this->operations->addElement($entity, [
      'field' => 'field_nonexistent',
    ], [
      'type' => 'entity_test_rev',
      'fields' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $this->assertEquals('INVALID_FIELD', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests addElement with an invalid paragraph type.
   */
  public function testAddComponentParagraphInvalidType(): void {
    $this->enableEntityReferenceRevisions();
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $entity = $this->createTestEntity(['name' => 'Page']);

    $result = $this->operations->addElement($entity, [
      'field' => 'field_paragraphs',
    ], [
      'type' => 'nonexistent_bundle',
      'fields' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('INVALID_TYPE', $errors[0]->getCode());
    $this->assertArrayHasKey('available_types', $errors[0]->getContext());
  }

  /**
   * Tests addElement with unknown paragraph field.
   */
  public function testAddComponentParagraphUnknownField(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $entity = $this->createTestEntity(['name' => 'Page']);

    $result = $this->operations->addElement($entity, [
      'field' => 'field_paragraphs',
    ], [
      'type' => 'entity_test_rev',
      'fields' => [
        'field_nonexistent' => 'value',
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('UNKNOWN_FIELD', $errors[0]->getCode());
    $this->assertEquals('fields.field_nonexistent', $errors[0]->getPath());
  }

  /**
   * Tests removeElement on an LB inline block.
   */
  public function testRemoveElementLb(): void {
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
        'uuid' => '474ec695-fe40-d152-f511-3878a0d103ef',
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

    $result = $this->operations->removeElement($entity, '474ec695-fe40-d152-f511-3878a0d103ef');

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());

    // Verify the component is gone.
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $this->assertCount(0, $updated_section->getComponents());
  }

  /**
   * Tests removeElement preserves sibling LB components.
   */
  public function testRemoveElementLbSiblingsPreserved(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block1 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block 1',
    ]);
    $block2 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Block 2',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block1, 'Block 1'),
      ],
      [
        'uuid' => '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
        'region' => 'content',
        'weight' => 1,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block2, 'Block 2'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->removeElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4');

    $this->assertTrue($result->isSuccess());

    // Verify comp-2 is untouched.
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $this->assertCount(1, $components);
    $remaining = reset($components);
    $this->assertEquals('9bc043d4-072e-d2ff-9909-5b1e2e3c9826', $remaining->getUuid());

    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $block2_content = $daedalus_layout_adapter->extractBlockContent($remaining);
    $this->assertEquals('Block 2', $block2_content->get('field_heading')->value);
  }

  /**
   * Tests removeElement on a paragraph.
   */
  public function testRemoveElementParagraph(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para1 = $this->createRevEntity(['name' => 'Para 1', 'field_heading' => 'First']);
    $para2 = $this->createRevEntity(['name' => 'Para 2', 'field_heading' => 'Second']);

    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [
        ['entity' => $para1],
        ['entity' => $para2],
      ],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $para1Uuid = $entity->get('field_paragraphs')->get(0)->entity->uuid();

    $result = $this->operations->removeElement($entity, $para1Uuid);

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());

    // Verify only para2 remains.
    $items = $entity->get('field_paragraphs');
    $this->assertCount(1, $items);
    $this->assertEquals('Second', $items->get(0)->entity->get('field_heading')->value);
  }

  /**
   * Tests removeElement preserves sibling paragraphs.
   */
  public function testRemoveElementParagraphSiblingsPreserved(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para1 = $this->createRevEntity(['name' => 'Para 1', 'field_heading' => 'A']);
    $para2 = $this->createRevEntity(['name' => 'Para 2', 'field_heading' => 'B']);
    $para3 = $this->createRevEntity(['name' => 'Para 3', 'field_heading' => 'C']);

    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [
        ['entity' => $para1],
        ['entity' => $para2],
        ['entity' => $para3],
      ],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Remove the middle one.
    $para2Uuid = $entity->get('field_paragraphs')->get(1)->entity->uuid();
    $result = $this->operations->removeElement($entity, $para2Uuid);

    $this->assertTrue($result->isSuccess());

    // Verify para1 and para3 remain in order.
    $items = $entity->get('field_paragraphs');
    $this->assertCount(2, $items);
    $this->assertEquals('A', $items->get(0)->entity->get('field_heading')->value);
    $this->assertEquals('C', $items->get(1)->entity->get('field_heading')->value);
  }

  /**
   * Tests removeElement with a nonexistent UUID.
   */
  public function testRemoveElementNotFound(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->operations->removeElement($entity, 'c68c9561-d36d-b4fc-77ac-50039f354800');

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('ELEMENT_NOT_FOUND', $errors[0]->getCode());
  }

  /**
   * Tests addElement with a client-provided UUID on a paragraph.
   */
  public function testAddComponentParagraphWithClientUuid(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $entity = $this->createTestEntity(['name' => 'Page']);

    $result = $this->operations->addElement($entity, [
      'field' => 'field_paragraphs',
    ], [
      'type' => 'entity_test_rev',
      'uuid' => '522b3069-e6bf-ec6e-8c30-01c830f1c4a5',
      'fields' => [
        'field_heading' => 'Custom UUID Para',
      ],
    ]);

    $this->assertTrue($result->isSuccess());

    $items = $entity->get('field_paragraphs');
    $this->assertCount(1, $items);
    $this->assertEquals('522b3069-e6bf-ec6e-8c30-01c830f1c4a5', $items->get(0)->entity->uuid());
    $this->assertEquals('Custom UUID Para', $items->get(0)->entity->get('field_heading')->value);
  }

}
