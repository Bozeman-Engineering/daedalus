<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests BlueprintOperations::updateElement().
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsElementTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
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
   * Tests updateElement on root entity fields via entity UUID.
   */
  public function testUpdateElementRootFields(): void {
    $entity = $this->createTestEntity([
      'name' => 'Original',
      'field_subtitle' => 'Original Sub',
      'field_count' => 10,
    ]);

    $result = $this->operations->updateElement($entity, $entity->uuid(), [
      'name' => 'Updated',
      'field_subtitle' => 'Updated Sub',
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());
    $this->assertEquals('Updated', $entity->label());
    $this->assertEquals('Updated Sub', $entity->get('field_subtitle')->value);
    $this->assertEquals(10, $entity->get('field_count')->value);

    // Verify targets.
    $targets = $result->getTargets();
    $this->assertCount(2, $targets);
    $uuids = array_unique(array_column($targets, 'uuid'));
    $this->assertSame([$entity->uuid()], $uuids);
    $field_names = array_column($targets, 'field_name');
    $this->assertContains('name', $field_names);
    $this->assertContains('field_subtitle', $field_names);
  }

  /**
   * Tests updateElement with null UUID falls back to entity UUID.
   */
  public function testUpdateElementNullUuid(): void {
    $entity = $this->createTestEntity([
      'name' => 'Original',
      'field_subtitle' => 'Test',
    ]);

    $result = $this->operations->updateElement($entity, NULL, [
      'field_subtitle' => 'From Null UUID',
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertEquals('From Null UUID', $entity->get('field_subtitle')->value);

    $targets = $result->getTargets();
    $this->assertCount(1, $targets);
    $this->assertSame($entity->uuid(), $targets[0]['uuid']);
    $this->assertSame('field_subtitle', $targets[0]['field_name']);
  }

  /**
   * Tests updateElement on an LB inline block with flat properties.
   */
  public function testUpdateElementLbComponent(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');
    $this->createBlockField('field_body', 'text_long', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'field_heading' => 'Original Heading',
      'field_body' => ['value' => '<p>Original</p>', 'format' => 'plain_text'],
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'My Block'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'field_heading' => 'Updated Heading',
      'field_body' => ['value' => '<p>Updated</p>', 'format' => 'plain_text'],
    ]);

    $this->assertTrue($result->isSuccess());

    // Verify block_content fields were updated.
    $daedalus_layout_adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $updated_block = $daedalus_layout_adapter->extractBlockContent(reset($components));
    $this->assertEquals('Updated Heading', $updated_block->get('field_heading')->value);
    $this->assertEquals('<p>Updated</p>', $updated_block->get('field_body')->value);

    // Verify targets point to the component UUID.
    $targets = $result->getTargets();
    $this->assertNotEmpty($targets);
    $uuids = array_unique(array_column($targets, 'uuid'));
    $this->assertSame(['faf95c53-35e7-74e1-184e-041152031df4'], $uuids);
    $field_names = array_column($targets, 'field_name');
    $this->assertContains('field_heading', $field_names);
    $this->assertContains('field_body', $field_names);
  }

  /**
   * Tests updateElement with label on an LB component.
   */
  public function testUpdateElementLbComponentLabel(): void {
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
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Old Label'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'label' => 'New Label',
      'field_heading' => 'Updated',
    ]);

    $this->assertTrue($result->isSuccess());

    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $component = reset($components);
    $config = $component->get('configuration');
    $this->assertEquals('New Label', $config['label']);

    // Label should appear in targets.
    $field_names = array_column($result->getTargets(), 'field_name');
    $this->assertContains('label', $field_names);
  }

  /**
   * Tests updateElement patches managed configuration keys.
   *
   * Same PATCH contract as fields: a declared value updates, an explicit
   * NULL clears, and an omitted managed key keeps its previous value.
   */
  public function testUpdateElementPatchesManagedConfiguration(): void {
    $this->enableLayoutBuilder();
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'text_section' => [
          'style_variant' => ['label' => 'Style Variant', 'schema' => ['type' => 'string']],
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

    $config = $this->createInlineBlockConfiguration('text_section', $block, 'Block');
    $config['style_variant'] = 'hero';
    $config['background'] = 'bg-primary';
    $config['padding'] = 'p-4';
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

    // Background declared (update), style_variant explicit NULL (clear),
    // padding omitted (preserve).
    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'background' => 'bg-secondary',
      'style_variant' => NULL,
    ]);

    $this->assertTrue($result->isSuccess());

    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $component = reset($components);
    $updated_config = $component->get('configuration');
    $this->assertSame('bg-secondary', $updated_config['background']);
    $this->assertArrayNotHasKey('style_variant', $updated_config);
    $this->assertSame('p-4', $updated_config['padding']);
  }

  /**
   * Tests updateElement on a paragraph via UUID.
   */
  public function testUpdateElementParagraph(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para = $this->createRevEntity([
      'name' => 'Para',
      'field_heading' => 'Original',
    ]);

    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [['entity' => $para]],
    ]);
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $para_uuid = $entity->get('field_paragraphs')->first()->entity->uuid();

    $result = $this->operations->updateElement($entity, $para_uuid, [
      'field_heading' => 'Updated',
    ]);

    $this->assertTrue($result->isSuccess());
    $updated_para = $entity->get('field_paragraphs')->first()->entity;
    $this->assertEquals('Updated', $updated_para->get('field_heading')->value);

    // Verify targets point to the paragraph UUID.
    $targets = $result->getTargets();
    $this->assertCount(1, $targets);
    $this->assertSame($para_uuid, $targets[0]['uuid']);
    $this->assertSame('field_heading', $targets[0]['field_name']);
  }

  /**
   * Tests updateElement with a nonexistent UUID.
   */
  public function testUpdateElementNotFound(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->operations->updateElement($entity, 'c68c9561-d36d-b4fc-77ac-50039f354800', [
      'field_subtitle' => 'value',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertEquals('ELEMENT_NOT_FOUND', $errors[0]->getCode());
  }

  /**
   * Tests updateElement with unknown property on LB component.
   */
  public function testUpdateElementUnknownProperty(): void {
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
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'field_nonexistent' => 'value',
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
   * Tests updateElement with unknown field on root entity.
   */
  public function testUpdateElementRootFieldUnknown(): void {
    $entity = $this->createTestEntity(['name' => 'Test']);

    $result = $this->operations->updateElement($entity, $entity->uuid(), [
      'field_nonexistent' => 'value',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('structural', $result->getPhase());
    $this->assertEquals('UNKNOWN_FIELD', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests updateElement returns unsaved entity.
   */
  public function testUpdateElementReturnsUnsavedEntity(): void {
    $entity = $this->createTestEntity([
      'name' => 'Original',
      'field_subtitle' => 'Original Sub',
    ]);

    $result = $this->operations->updateElement($entity, $entity->uuid(), [
      'field_subtitle' => 'Updated',
    ]);

    $this->assertTrue($result->isSuccess());

    $reloaded = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $this->assertEquals('Original Sub', $reloaded->get('field_subtitle')->value);
  }

  /**
   * Tests updateElement with non-inline block returns error.
   */
  public function testUpdateElementNonInlineBlock(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'ed939717-cc05-fbd1-619e-ec86816e3045',
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
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->updateElement($entity, 'ed939717-cc05-fbd1-619e-ec86816e3045', [
      'field_heading' => 'Test',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('NOT_INLINE_BLOCK', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests updateElement on root entity rejects layout_section fields.
   */
  public function testUpdateElementRejectsLayoutField(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
    ]);
    $entity->save();

    $result = $this->operations->updateElement($entity, $entity->uuid(), [
      'layout_builder__layout' => [],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('FIELD_TYPE_NOT_ALLOWED', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests that updating 'info' does not change the component display label.
   *
   * `info` (the block_content admin label) and the component display `label`
   * are decoupled: writing one never writes the other. The `info` update is
   * applied through the gated field pipeline. See RF2 decisions in
   * notes/entity-access.md.
   */
  public function testUpdateElementInfoDoesNotChangeDisplayLabel(): void {
    $this->enableLayoutBuilder();
    $this->refreshOperations();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'info' => 'Old Info',
      'field_heading' => 'Hello',
    ]);

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Old Label'),
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    $result = $this->operations->updateElement($entity, 'faf95c53-35e7-74e1-184e-041152031df4', [
      'info' => 'New Info',
    ]);

    $this->assertTrue($result->isSuccess());

    $updated_section = $entity->get('layout_builder__layout')->first()->section;
    $components = $updated_section->getComponents();
    $component = reset($components);
    $config = $component->get('configuration');
    // The display label is untouched by an `info` update.
    $this->assertEquals('Old Label', $config['label']);
    // The `info` field was applied through the gated field pipeline.
    $updated_block = $this->unserializeBlock($config['block_serialized']);
    $this->assertSame('New Info', $updated_block->get('info')->value);
  }

}
