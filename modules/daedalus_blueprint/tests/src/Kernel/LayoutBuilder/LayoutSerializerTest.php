<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\LayoutBuilder;

use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the LayoutSerializer service.
 */
#[CoversClass(LayoutSerializer::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class LayoutSerializerTest extends BlueprintKernelTestBase {

  /**
   * The layout serializer.
   *
   * @var \Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer
   */
  protected LayoutSerializer $layoutSerializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('body', 'text_with_summary', 'text_section');
    $this->createBlockContentType('card', 'Card');
    $this->createBlockField('field_heading', 'string', 'card', ['max_length' => 255]);
    $this->createBlockField('field_text', 'text_long', 'card');

    $this->layoutSerializer = $this->container->get('daedalus_blueprint.layout_serializer');
  }

  /**
   * Tests serializing an entity with no layout.
   *
   * No override and a display with no sections is an empty page: the
   * serializer invents no section, since the canvas renders none and a
   * section a reader can name must be one a write can find.
   */
  public function testSerializeEmptyLayout(): void {
    $entity = $this->createTestEntity();
    $this->assertSame([], $this->layoutSerializer->serialize($entity));
  }

  /**
   * Tests core's blank placeholder serializes as no sections.
   *
   * The Page type's shipped display carries one layout_builder_blank
   * section; core copies it into a fresh override on the first Edit Mode
   * render and persists it on save. Core hides it behind count(), so the
   * canvas renders the blank-page prompt; the serializer must agree, or
   * the page elements tree lists a section nothing on the page is.
   */
  public function testBlankPlaceholderSerializesAsNoSections(): void {
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $display->removeAllSections(TRUE)->save();

    $entity = $this->createTestEntity();
    $this->assertSame([], $this->layoutSerializer->serialize($entity), 'A blank-only display default reads as no sections.');

    $entity->set('layout_builder__layout', [new Section('layout_builder_blank')]);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $this->assertSame('layout_builder_blank', $entity->get('layout_builder__layout')->getSections()[0]->getLayoutId());
    $this->assertSame([], $this->layoutSerializer->serialize($entity), 'A persisted blank placeholder reads as no sections.');
  }

  /**
   * Tests serializing an entity with a single section and no components.
   */
  public function testSerializeEmptySection(): void {
    $entity = $this->createTestEntity();

    $section = new Section('layout_onecol');
    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->layoutSerializer->serialize($entity);

    $this->assertCount(1, $result);
    $this->assertSame('layout_onecol', $result[0]['layout_id']);
    $this->assertSame(['content' => []], $result[0]['regions']);
    $this->assertArrayNotHasKey('layout_settings', $result[0]);
    $this->assertArrayNotHasKey('third_party_settings', $result[0]);
    $this->assertArrayNotHasKey('uuid', $result[0]);
  }

  /**
   * Tests serializing a section with an inline block component.
   */
  public function testSerializeInlineBlock(): void {
    $entity = $this->createTestEntity();

    // Create block_content entity.
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Welcome Text',
      'reusable' => FALSE,
      'body' => [
        'value' => '<p>Hello world</p>',
        'format' => 'plain_text',
      ],
    ]);
    $block->save();

    $configuration = $this->createInlineBlockConfiguration(
      'text_section',
      $block,
      'Welcome Text',
    );

    $comp_uuid = 'test-comp-uuid-123';
    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => $comp_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $configuration,
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->layoutSerializer->serialize($entity);

    $this->assertCount(1, $result);
    $component = $result[0]['regions']['content'][0];

    $this->assertSame($comp_uuid, $component['uuid']);
    $this->assertArrayNotHasKey('region', $component);
    $this->assertArrayNotHasKey('weight', $component);
    $this->assertSame('text_section', $component['block_type']);
    $this->assertSame('Welcome Text', $component['label']);
    $this->assertSame('full', $component['view_mode']);
    $this->assertIsInt($component['block_revision_id']);
    $this->assertArrayHasKey('block_fields', $component);
    $this->assertSame('<p>Hello world</p>', $component['block_fields']['body']['value']);
    $this->assertSame('plain_text', $component['block_fields']['body']['format']);
    // No plugin_id on inline blocks.
    $this->assertArrayNotHasKey('plugin_id', $component);
  }

  /**
   * Tests serializing multiple components sorted by weight.
   */
  public function testSerializeComponentsSortedByWeight(): void {
    $entity = $this->createTestEntity();

    $block1 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'First',
      'reusable' => FALSE,
      'body' => ['value' => 'First', 'format' => 'plain_text'],
    ]);
    $block1->save();

    $block2 = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Second',
      'reusable' => FALSE,
      'body' => ['value' => 'Second', 'format' => 'plain_text'],
    ]);
    $block2->save();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '1685df88-728a-709d-23ca-a012a93c4c2f',
        'region' => 'content',
        'weight' => 5,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block2, 'Second'),
      ],
      [
        'uuid' => '33540321-8948-9ff1-6e0e-b625b9a082a4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block1, 'First'),
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->layoutSerializer->serialize($entity);
    $components = $result[0]['regions']['content'];

    $this->assertCount(2, $components);
    // Verify order: components are sorted by weight (appendComponent order).
    $this->assertSame('1685df88-728a-709d-23ca-a012a93c4c2f', $components[0]['uuid']);
    $this->assertSame('33540321-8948-9ff1-6e0e-b625b9a082a4', $components[1]['uuid']);
  }

  /**
   * Tests serializing multiple sections.
   */
  public function testSerializeMultipleSections(): void {
    $entity = $this->createTestEntity();

    $section1 = new Section('layout_onecol');
    $section2 = new Section('layout_twocol_section');

    $entity->get('layout_builder__layout')->appendSection($section1);
    $entity->get('layout_builder__layout')->appendSection($section2);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->layoutSerializer->serialize($entity);

    $this->assertCount(2, $result);
    $this->assertSame('layout_onecol', $result[0]['layout_id']);
    $this->assertSame('layout_twocol_section', $result[1]['layout_id']);
  }

  /**
   * Tests non-managed opaque data is excluded from output.
   */

  /**
   * Tests opaque data is excluded from serialization.
   *
   * Third party settings and additional are fully opaque and never shown.
   */
  public function testOpaqueDataExcludedByDefault(): void {
    $entity = $this->createTestEntity();

    // Create section with third_party_settings and layout_settings.
    $section = new Section('layout_onecol', ['some_setting' => 'value'], [], [
      'some_module' => ['key' => 'val'],
    ]);

    // Create component with additional data.
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Test',
      'reusable' => FALSE,
    ]);
    $block->save();

    $config = $this->createInlineBlockConfiguration('text_section', $block, 'Test');
    $component = new SectionComponent('faf95c53-35e7-74e1-184e-041152031df4', 'content', $config, [
      'layout_builder_styles_style' => ['accent' => 'blue'],
    ]);
    $section->appendComponent($component);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->layoutSerializer->serialize($entity);

    // Non-managed opaque data should not appear.
    $this->assertArrayNotHasKey('layout_settings', $result[0]);
    $this->assertArrayNotHasKey('third_party_settings', $result[0]);
    $this->assertArrayNotHasKey('additional', $result[0]['regions']['content'][0]);
  }

  /**
   * Tests managed layout settings are included in output.
   */
  public function testManagedLayoutSettingsIncluded(): void {
    // Enable the test module to register managed keys.
    $this->enableModules(['daedalus_blueprint_test']);

    // Declare managed keys via state.
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'section_layout_settings' => [
        'layout_onecol' => [
          'width' => [
            'label' => 'Layout width',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
    ]);

    // Clear managed keys cache.
    \Drupal::cache()->deleteAll();

    // Re-get layout serializer from rebuilt container.
    $layout_serializer = $this->container->get('daedalus_blueprint.layout_serializer');

    $entity = $this->createTestEntity();

    $section = new Section('layout_onecol', [
      'width' => 'full-width',
      'internal_setting' => 'hidden',
    ], [], [
      'some_module' => ['key' => 'val'],
    ]);

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Test',
      'reusable' => FALSE,
    ]);
    $block->save();

    $config = $this->createInlineBlockConfiguration('text_section', $block, 'Test');
    $component = new SectionComponent('faf95c53-35e7-74e1-184e-041152031df4', 'content', $config, [
      'some_additional' => 'hidden',
    ]);
    $section->appendComponent($component);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $layout_serializer->serialize($entity);

    // Managed layout_settings visible, non-managed excluded.
    $this->assertArrayHasKey('layout_settings', $result[0]);
    $this->assertArrayHasKey('width', $result[0]['layout_settings']);
    $this->assertSame('full-width', $result[0]['layout_settings']['width']);
    $this->assertArrayNotHasKey('internal_setting', $result[0]['layout_settings']);

    // Third party settings and additional are always hidden.
    $this->assertArrayNotHasKey('third_party_settings', $result[0]);
    $this->assertArrayNotHasKey('additional', $result[0]['regions']['content'][0]);
  }

  /**
   * Tests block_fields serialization for multiple field types.
   */
  public function testBlockFieldsSerialization(): void {
    $entity = $this->createTestEntity();

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'card',
      'info' => 'My Card',
      'reusable' => FALSE,
      'field_heading' => 'Card Title',
      'field_text' => [
        'value' => '<p>Card body</p>',
        'format' => 'plain_text',
      ],
    ]);
    $block->save();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'd874430b-a2a5-0cb2-93ff-7d6a2958e3fd',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('card', $block, 'My Card'),
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->layoutSerializer->serialize($entity);
    $block_fields = $result[0]['regions']['content'][0]['block_fields'];

    $this->assertSame('Card Title', $block_fields['field_heading']);
    $this->assertSame('<p>Card body</p>', $block_fields['field_text']['value']);
    $this->assertSame('plain_text', $block_fields['field_text']['format']);
  }

  /**
   * Tests serialization is included in BlueprintSerializer output.
   */
  public function testBlueprintSerializerIncludesLayout(): void {
    $entity = $this->createTestEntity(['field_subtitle' => 'Test subtitle']);

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Block',
      'reusable' => FALSE,
      'body' => ['value' => '<p>Content</p>', 'format' => 'plain_text'],
    ]);
    $block->save();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block'),
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $serializer = $this->container->get('daedalus_blueprint.serializer');
    $result = $serializer->serialize($entity);

    // Should have both fields and layout.
    $this->assertArrayHasKey('fields', $result);
    $this->assertArrayHasKey('layout_builder__layout', $result);
    $this->assertCount(1, $result['layout_builder__layout']);
    $this->assertSame('layout_onecol', $result['layout_builder__layout'][0]['layout_id']);
  }

  /**
   * Tests entity without LB field does not get layout key.
   */
  public function testBlueprintSerializerNoLayoutField(): void {
    // entity_test_rev does not have layout_builder__layout.
    $entity = $this->createRevEntity();

    $serializer = $this->container->get('daedalus_blueprint.serializer');
    $result = $serializer->serialize($entity);

    $this->assertArrayNotHasKey('layout_builder__layout', $result);
  }

  /**
   * Tests serializing unsaved (block_serialized) inline block.
   */
  public function testSerializeUnsavedInlineBlock(): void {
    $entity = $this->createTestEntity();

    // Create unsaved block_content.
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Unsaved Block',
      'reusable' => FALSE,
      'body' => ['value' => '<p>Unsaved</p>', 'format' => 'plain_text'],
    ]);
    // Do NOT save — will be serialized via PHP serialize().
    $config = [
      'id' => 'inline_block:text_section',
      'provider' => 'layout_builder',
      'label' => 'Unsaved Block',
      'label_display' => 'visible',
      'view_mode' => 'full',
      'block_serialized' => serialize($block),
    ];

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '49b7eeba-40de-e9bf-0acc-918a04396d2d',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $config,
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    // Save the entity — this persists the section with the serialized block.
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->layoutSerializer->serialize($entity);
    $component = $result[0]['regions']['content'][0];

    $this->assertSame('text_section', $component['block_type']);
    $this->assertArrayHasKey('block_fields', $component);
    // block_revision_id should be set after save handled by LB.
    $this->assertArrayHasKey('body', $component['block_fields']);
  }

  /**
   * Tests that inline block fields exclude internal fields.
   */
  public function testBlockFieldsSkipInternalFields(): void {
    $entity = $this->createTestEntity();

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Test',
      'reusable' => FALSE,
      'body' => ['value' => '<p>Test</p>', 'format' => 'plain_text'],
    ]);
    $block->save();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Test'),
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->layoutSerializer->serialize($entity);
    $block_fields = $result[0]['regions']['content'][0]['block_fields'];

    // Internal/entity key fields should not appear.
    $this->assertArrayNotHasKey('id', $block_fields);
    $this->assertArrayNotHasKey('uuid', $block_fields);
    $this->assertArrayNotHasKey('type', $block_fields);
    $this->assertArrayNotHasKey('revision_id', $block_fields);
    $this->assertArrayNotHasKey('langcode', $block_fields);
    $this->assertArrayNotHasKey('default_langcode', $block_fields);
    $this->assertArrayNotHasKey('revision_default', $block_fields);
  }

  /**
   * Tests inline block managed configuration keys are serialized.
   */
  public function testInlineBlockManagedConfigSerialized(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'text_section' => [
          'title_size' => [
            'label' => 'Title size',
            'schema' => ['type' => 'string'],
          ],
          'label_display' => [
            'label' => 'Label display',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
    ]);
    \Drupal::cache()->deleteAll();

    $layout_serializer = $this->container->get('daedalus_blueprint.layout_serializer');
    $entity = $this->createTestEntity();

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Test Heading',
      'reusable' => FALSE,
      'body' => ['value' => '<p>Test</p>', 'format' => 'plain_text'],
    ]);
    $block->save();

    // Include managed config keys in the inline block configuration.
    $config = $this->createInlineBlockConfiguration('text_section', $block, 'Test Heading');
    $config['title_size'] = 'text-large1';
    $config['label_display'] = 'visible';
    $config['non_managed_key'] = 'should_not_appear';

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'b89b36d5-94b4-3153-b2fb-9566368d45e6',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $config,
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $layout_serializer->serialize($entity);
    $component = $result[0]['regions']['content'][0];

    // Managed config keys should appear in configuration.
    $this->assertArrayHasKey('configuration', $component);
    $this->assertSame('text-large1', $component['configuration']['title_size']);
    $this->assertSame('visible', $component['configuration']['label_display']);
    // Non-managed keys should not appear.
    $this->assertArrayNotHasKey('non_managed_key', $component['configuration']);
  }

  /**
   * Tests inline block without managed keys has no configuration key.
   */
  public function testInlineBlockNoManagedConfigOmitted(): void {
    $entity = $this->createTestEntity();

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Test',
      'reusable' => FALSE,
    ]);
    $block->save();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Test'),
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->layoutSerializer->serialize($entity);
    $component = $result[0]['regions']['content'][0];

    // Without managed keys, no configuration key.
    $this->assertArrayNotHasKey('configuration', $component);
  }

}
