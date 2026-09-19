<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\LayoutBuilder;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutDeserializer;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the LayoutDeserializer service.
 */
#[CoversClass(LayoutDeserializer::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class LayoutDeserializerTest extends BlueprintKernelTestBase {

  /**
   * The layout deserializer.
   *
   * @var \Drupal\daedalus_blueprint\LayoutBuilder\LayoutDeserializer
   */
  protected LayoutDeserializer $layoutDeserializer;

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

    $this->layoutDeserializer = $this->container->get('daedalus_blueprint.layout_deserializer');
    $this->layoutSerializer = $this->container->get('daedalus_blueprint.layout_serializer');
  }

  /**
   * Tests creating a new inline block on a new entity (NULL previous state).
   */
  public function testCreateInlineBlockNewEntity(): void {
    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'New entity',
    ]);

    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => '031e0223-2594-2369-68b7-c520f9911bd7',
              'block_type' => 'text_section',
              'label' => 'Welcome Text',
              'view_mode' => 'full',
              'block_fields' => [
                'body' => [
                  'value' => '<p>Hello world</p>',
                  'format' => 'plain_text',
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());
    $this->assertCount(1, $result->getSections());

    // Verify entity's layout was updated.
    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $this->assertSame('layout_onecol', $sections[0]->getLayoutId());

    $component = $sections[0]->getComponent('031e0223-2594-2369-68b7-c520f9911bd7');
    $this->assertSame('content', $component->getRegion());
    $this->assertSame(0, $component->getWeight());

    // Verify block_content is serialized in configuration.
    $config = $component->get('configuration');
    $this->assertSame('inline_block:text_section', $config['id']);
    $this->assertSame('Welcome Text', $config['label']);
    $this->assertSame('full', $config['view_mode']);
    $this->assertNotEmpty($config['block_serialized']);

    // Extract and verify block_content fields.
    $block = $this->unserializeBlock($config['block_serialized']);
    $this->assertSame('<p>Hello world</p>', $block->get('body')->value);
    $this->assertSame('plain_text', $block->get('body')->format);
    $this->assertSame('Welcome Text', $block->get('info')->value);
  }

  /**
   * Tests updating an existing inline block's fields.
   */
  public function testUpdateInlineBlock(): void {
    // Create entity with existing layout.
    $entity = $this->createEntityWithInlineBlock(
      'faf95c53-35e7-74e1-184e-041152031df4',
      'text_section',
      'Original Text',
      ['value' => '<p>Original</p>', 'format' => 'plain_text'],
    );

    // Build incoming layout with updated body.
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
              'block_type' => 'text_section',
              'label' => 'Updated Text',
              'block_fields' => [
                'body' => [
                  'value' => '<p>Updated content</p>',
                  'format' => 'plain_text',
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    // Verify updated block_content.
    $sections = $entity->get('layout_builder__layout')->getSections();
    $component = $sections[0]->getComponent('faf95c53-35e7-74e1-184e-041152031df4');
    $config = $component->get('configuration');
    $block = $this->unserializeBlock($config['block_serialized']);

    $this->assertSame('<p>Updated content</p>', $block->get('body')->value);
    // `label` sets the component display label, not the block_content `info`
    // field. `info` is unchanged because it was not sent via block_fields
    // (full decouple; see RF2 decisions in notes/entity-access.md).
    $this->assertSame('Updated Text', $config['label']);
    $this->assertSame('Original Text', $block->get('info')->value);
  }

  /**
   * Tests deleting a component by omitting its UUID from incoming layout.
   */
  public function testDeleteInlineBlock(): void {
    $entity = $this->createTestEntity();
    $block_storage = $this->entityTypeManager->getStorage('block_content');

    // Create two blocks.
    $block1 = $block_storage->create([
      'type' => 'text_section',
      'info' => 'Keep',
      'reusable' => FALSE,
      'body' => ['value' => '<p>Keep</p>', 'format' => 'plain_text'],
    ]);
    $block1->save();

    $block2 = $block_storage->create([
      'type' => 'text_section',
      'info' => 'Delete',
      'reusable' => FALSE,
      'body' => ['value' => '<p>Delete</p>', 'format' => 'plain_text'],
    ]);
    $block2->save();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '68990fa2-7006-ff95-38d6-6fe8a7e0671e',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block1, 'Keep'),
      ],
      [
        'uuid' => 'ea34cb20-ee04-9124-1ff1-1dcdad413c89',
        'region' => 'content',
        'weight' => 1,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block2, 'Delete'),
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Only include keep-comp in incoming — delete-comp is dropped.
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => '68990fa2-7006-ff95-38d6-6fe8a7e0671e',
              'block_type' => 'text_section',
              'label' => 'Keep',
              'block_fields' => [
                'body' => ['value' => '<p>Keep</p>', 'format' => 'plain_text'],
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    // Only one component should remain.
    $sections = $entity->get('layout_builder__layout')->getSections();
    $components = $sections[0]->getComponents();
    $this->assertCount(1, $components);
    $this->assertArrayHasKey('68990fa2-7006-ff95-38d6-6fe8a7e0671e', $components);
  }

  /**
   * Tests mixed create and update in one operation.
   */
  public function testCreateAndUpdateMixed(): void {
    $entity = $this->createEntityWithInlineBlock(
      'a0bd9075-abce-9a83-7b79-d4db72ff887b',
      'text_section',
      'Existing',
      ['value' => '<p>Existing</p>', 'format' => 'plain_text'],
    );

    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'a0bd9075-abce-9a83-7b79-d4db72ff887b',
              'block_type' => 'text_section',
              'label' => 'Updated Existing',
              'block_fields' => [
                'body' => ['value' => '<p>Updated</p>', 'format' => 'plain_text'],
              ],
            ],
            [
              'uuid' => '7900c460-e311-ca39-03f4-74e02e35140a',
              'block_type' => 'card',
              'label' => 'New Card',
              'block_fields' => [
                'field_heading' => 'Card Title',
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $components = $sections[0]->getComponents();
    $this->assertCount(2, $components);

    // Verify updated existing component.
    $existing_block = $this->unserializeBlock($components['a0bd9075-abce-9a83-7b79-d4db72ff887b']->get('configuration')['block_serialized']);
    $this->assertSame('<p>Updated</p>', $existing_block->get('body')->value);

    // Verify new component.
    $new_block = $this->unserializeBlock($components['7900c460-e311-ca39-03f4-74e02e35140a']->get('configuration')['block_serialized']);
    $this->assertSame('Card Title', $new_block->get('field_heading')->value);
    $this->assertSame('New Card', $new_block->get('info')->value);
  }

  /**
   * Tests non-managed additional data is preserved on update.
   */
  public function testOpaqueDataPreservedOnUpdate(): void {
    $entity = $this->createTestEntity();
    $block_storage = $this->entityTypeManager->getStorage('block_content');

    $block = $block_storage->create([
      'type' => 'text_section',
      'info' => 'Test',
      'reusable' => FALSE,
      'body' => ['value' => '<p>Test</p>', 'format' => 'plain_text'],
    ]);
    $block->save();

    $config = $this->createInlineBlockConfiguration('text_section', $block, 'Test');
    $component = new SectionComponent('faf95c53-35e7-74e1-184e-041152031df4', 'content', $config, [
      'non_managed_key' => 'preserve_me',
      'another_hidden_key' => ['nested' => 'data'],
    ]);
    $section = new Section('layout_onecol');
    $section->appendComponent($component);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Incoming layout does not include additional at all.
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
              'block_type' => 'text_section',
              'label' => 'Test',
              'block_fields' => [
                'body' => ['value' => '<p>Updated</p>', 'format' => 'plain_text'],
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    // All additional data should be preserved (fully opaque).
    $sections = $entity->get('layout_builder__layout')->getSections();
    $updated_component = $sections[0]->getComponent('faf95c53-35e7-74e1-184e-041152031df4');
    $updated_array = $updated_component->toArray();

    $this->assertSame('preserve_me', $updated_array['additional']['non_managed_key']);
    $this->assertSame(['nested' => 'data'], $updated_array['additional']['another_hidden_key']);
  }

  /**
   * Tests managed inline block configuration override via incoming JSON.
   */
  public function testManagedKeyOverride(): void {
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'text_section' => [
          'style_variant' => [
            'label' => 'Style Variant',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
    ]);
    \Drupal::cache()->deleteAll();

    $this->layoutDeserializer = $this->container->get('daedalus_blueprint.layout_deserializer');
    $this->layoutSerializer = $this->container->get('daedalus_blueprint.layout_serializer');

    $entity = $this->createTestEntity();
    $block_storage = $this->entityTypeManager->getStorage('block_content');

    $block = $block_storage->create([
      'type' => 'text_section',
      'info' => 'Test',
      'reusable' => FALSE,
    ]);
    $block->save();

    $config = $this->createInlineBlockConfiguration('text_section', $block, 'Test');
    $config['style_variant'] = 'old_value';
    $config['non_managed_config'] = 'keep_me';
    $component = new SectionComponent('faf95c53-35e7-74e1-184e-041152031df4', 'content', $config);
    $section = new Section('layout_onecol');
    $section->appendComponent($component);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Incoming provides new managed key value.
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
              'block_type' => 'text_section',
              'label' => 'Test',
              'block_fields' => [],
              'configuration' => [
                'style_variant' => 'new_value',
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $updated_config = $sections[0]->getComponent('faf95c53-35e7-74e1-184e-041152031df4')->get('configuration');

    // Managed key overridden.
    $this->assertSame('new_value', $updated_config['style_variant']);
    // Non-managed config preserved.
    $this->assertSame('keep_me', $updated_config['non_managed_config']);
  }

  /**
   * Tests managed key clearing via an explicit NULL value.
   *
   * Same PATCH contract as fields: clearing a managed key requires an
   * explicit empty value (NULL, '', []). Omitting the key — or the whole
   * `configuration` scope — preserves the previous value (see
   * testManagedKeySubsetPreserved and testManagedKeyOmittedScopePreserved).
   */
  public function testManagedKeyClearing(): void {
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'text_section' => [
          'style_variant' => [
            'label' => 'Style Variant',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
    ]);
    \Drupal::cache()->deleteAll();

    $this->layoutDeserializer = $this->container->get('daedalus_blueprint.layout_deserializer');
    $this->layoutSerializer = $this->container->get('daedalus_blueprint.layout_serializer');

    $entity = $this->createTestEntity();
    $block_storage = $this->entityTypeManager->getStorage('block_content');

    $block = $block_storage->create([
      'type' => 'text_section',
      'info' => 'Test',
      'reusable' => FALSE,
    ]);
    $block->save();

    $config = $this->createInlineBlockConfiguration('text_section', $block, 'Test');
    $config['style_variant'] = 'should_be_cleared';
    $config['non_managed_config'] = 'keep_me';
    $component = new SectionComponent('faf95c53-35e7-74e1-184e-041152031df4', 'content', $config);
    $section = new Section('layout_onecol');
    $section->appendComponent($component);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Incoming sets the managed key to an explicit NULL → the managed key is
    // cleared, while a non-managed key sent alongside it is kept.
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
              'block_type' => 'text_section',
              'label' => 'Test',
              'block_fields' => [],
              'configuration' => [
                'style_variant' => NULL,
                'non_managed_config' => 'keep_me',
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $updated_config = $sections[0]->getComponent('faf95c53-35e7-74e1-184e-041152031df4')->get('configuration');

    // Managed key should be cleared (absent).
    $this->assertArrayNotHasKey('style_variant', $updated_config);
    // Non-managed config preserved.
    $this->assertSame('keep_me', $updated_config['non_managed_config']);
  }

  /**
   * Tests a declared configuration scope with a subset of managed keys.
   *
   * The safety win of the PATCH contract: a managed key omitted from a
   * present configuration scope keeps its previous value instead of being
   * silently cleared.
   */
  public function testManagedKeySubsetPreserved(): void {
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'text_section' => [
          'style_variant' => [
            'label' => 'Style Variant',
            'schema' => ['type' => 'string'],
          ],
          'background' => [
            'label' => 'Background',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
    ]);
    \Drupal::cache()->deleteAll();

    $this->layoutDeserializer = $this->container->get('daedalus_blueprint.layout_deserializer');
    $this->layoutSerializer = $this->container->get('daedalus_blueprint.layout_serializer');

    $entity = $this->createTestEntity();
    $block_storage = $this->entityTypeManager->getStorage('block_content');

    $block = $block_storage->create([
      'type' => 'text_section',
      'info' => 'Test',
      'reusable' => FALSE,
    ]);
    $block->save();

    $config = $this->createInlineBlockConfiguration('text_section', $block, 'Test');
    $config['style_variant'] = 'should_survive';
    $config['background'] = 'bg-primary';
    $component = new SectionComponent('faf95c53-35e7-74e1-184e-041152031df4', 'content', $config);
    $section = new Section('layout_onecol');
    $section->appendComponent($component);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Incoming declares the configuration scope with only one of the two
    // managed keys → the other keeps its previous value.
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
              'block_type' => 'text_section',
              'label' => 'Test',
              'block_fields' => [],
              'configuration' => [
                'background' => 'bg-secondary',
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $updated_config = $sections[0]->getComponent('faf95c53-35e7-74e1-184e-041152031df4')->get('configuration');

    // Declared managed key updated.
    $this->assertSame('bg-secondary', $updated_config['background']);
    // Omitted managed key preserved.
    $this->assertSame('should_survive', $updated_config['style_variant']);
  }

  /**
   * Tests an omitted configuration scope preserves managed styling wholesale.
   *
   * When the incoming component omits the `configuration` scope entirely, the
   * previous managed styling is preserved — the same PATCH contract that also
   * preserves individual omitted keys (testManagedKeySubsetPreserved). This
   * lets structural tools relocate or re-declare a block without re-sending
   * its styling. See OpaqueDataMerger::mergeOptionalScope.
   */
  public function testManagedKeyOmittedScopePreserved(): void {
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'text_section' => [
          'style_variant' => [
            'label' => 'Style Variant',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
    ]);
    \Drupal::cache()->deleteAll();

    $this->layoutDeserializer = $this->container->get('daedalus_blueprint.layout_deserializer');
    $this->layoutSerializer = $this->container->get('daedalus_blueprint.layout_serializer');

    $entity = $this->createTestEntity();
    $block_storage = $this->entityTypeManager->getStorage('block_content');

    $block = $block_storage->create([
      'type' => 'text_section',
      'info' => 'Test',
      'reusable' => FALSE,
    ]);
    $block->save();

    $config = $this->createInlineBlockConfiguration('text_section', $block, 'Test');
    $config['style_variant'] = 'should_survive';
    $config['non_managed_config'] = 'keep_me';
    $component = new SectionComponent('faf95c53-35e7-74e1-184e-041152031df4', 'content', $config);
    $section = new Section('layout_onecol');
    $section->appendComponent($component);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Incoming omits the configuration scope entirely → previous styling
    // (managed and non-managed) is preserved wholesale.
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
              'block_type' => 'text_section',
              'label' => 'Test',
              'block_fields' => [],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $updated_config = $sections[0]->getComponent('faf95c53-35e7-74e1-184e-041152031df4')->get('configuration');

    // Managed key preserved (scope omitted, not cleared).
    $this->assertSame('should_survive', $updated_config['style_variant']);
    // Non-managed config preserved.
    $this->assertSame('keep_me', $updated_config['non_managed_config']);
  }

  /**
   * Tests section opaque data preservation on update.
   */
  public function testSectionOpaqueDataPreservation(): void {
    $entity = $this->createTestEntity();

    // Create section with opaque data.
    $section = new Section('layout_onecol', ['internal_setting' => 'keep'], [], [
      'some_module' => ['key' => 'preserved_value'],
    ]);
    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Incoming layout doesn't include layout_settings or third_party_settings.
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $section_array = $sections[0]->toArray();

    // Non-managed opaque data should be preserved.
    $this->assertSame('keep', $section_array['layout_settings']['internal_setting']);
    $this->assertSame('preserved_value', $section_array['third_party_settings']['some_module']['key']);
  }

  /**
   * Tests section matching by positional index with layout_id confirmation.
   */
  public function testSectionMatchingByPosition(): void {
    $entity = $this->createTestEntity();

    // Two sections with opaque data.
    $section1 = new Section('layout_onecol', ['setting' => 'section1_data']);
    $section2 = new Section('layout_twocol_section', ['setting' => 'section2_data']);
    $entity->get('layout_builder__layout')->appendSection($section1);
    $entity->get('layout_builder__layout')->appendSection($section2);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Same positions, same layout_ids → should match.
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [],
      ],
      [
        'layout_id' => 'layout_twocol_section',
        'regions' => [],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    // Verify opaque data was preserved (proves matching worked).
    $this->assertSame('section1_data', $sections[0]->toArray()['layout_settings']['setting']);
    $this->assertSame('section2_data', $sections[1]->toArray()['layout_settings']['setting']);
  }

  /**
   * Tests new section (no match in previous) gets no preserved data.
   */
  public function testNewSectionNoPreservation(): void {
    $entity = $this->createTestEntity();

    // Existing section with opaque data.
    $section = new Section('layout_onecol', ['setting' => 'old_data']);
    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Incoming has a different layout_id at position 0 → no match.
    $new_layout = [
      [
        'layout_id' => 'layout_twocol_section',
        'regions' => [],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    // No preserved data since this is a new (unmatched) section.
    $this->assertSame('layout_twocol_section', $sections[0]->getLayoutId());
    $section_array = $sections[0]->toArray();
    // The previous section's 'setting' key should NOT be preserved.
    $this->assertArrayNotHasKey('setting', $section_array['layout_settings']);
  }

  /**
   * Tests non-inline (plugin) component handling.
   */
  public function testNonInlineComponent(): void {
    $entity = $this->createTestEntity();

    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'e8aa765e-c9fd-241d-71f6-f5013df6ef37',
              'plugin_id' => 'system_branding_block',
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $component = $sections[0]->getComponent('e8aa765e-c9fd-241d-71f6-f5013df6ef37');

    $config = $component->get('configuration');
    $this->assertSame('system_branding_block', $config['id']);
    $this->assertSame('content', $component->getRegion());
  }

  /**
   * Tests non-inline component preserves non-managed configuration on update.
   */
  public function testNonInlineComponentConfigPreservation(): void {
    $entity = $this->createTestEntity();

    // Create existing non-inline component with configuration.
    $existing_config = [
      'id' => 'system_branding_block',
      'provider' => 'system',
      'label' => 'Site branding',
      'use_site_logo' => TRUE,
      'use_site_name' => TRUE,
    ];
    $component = new SectionComponent('e8aa765e-c9fd-241d-71f6-f5013df6ef37', 'content', $existing_config, [
      'internal_data' => 'preserve_me',
    ]);
    $section = new Section('layout_onecol');
    $section->appendComponent($component);
    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Incoming only provides plugin_id — no configuration.
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'e8aa765e-c9fd-241d-71f6-f5013df6ef37',
              'plugin_id' => 'system_branding_block',
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $updated_component = $sections[0]->getComponent('e8aa765e-c9fd-241d-71f6-f5013df6ef37');
    $updated_config = $updated_component->get('configuration');

    // Non-managed config should be preserved.
    $this->assertSame('system_branding_block', $updated_config['id']);
    $this->assertTrue($updated_config['use_site_logo']);
    $this->assertTrue($updated_config['use_site_name']);

    // Non-managed additional should be preserved.
    $updated_array = $updated_component->toArray();
    $this->assertSame('preserve_me', $updated_array['additional']['internal_data']);
  }

  /**
   * Tests semantic errors within block_fields have correct nested paths.
   */
  public function testErrorPathsInBlockFields(): void {
    // Add entity_reference field to card block type targeting entity_test.
    FieldStorageConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'block_content',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'entity_test'],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_ref',
      'entity_type' => 'block_content',
      'bundle' => 'card',
      'label' => 'Reference',
      'settings' => [
        'handler' => 'default:entity_test',
        'handler_settings' => ['target_bundles' => NULL],
      ],
    ])->save();

    $entity = $this->createTestEntity();

    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'd874430b-a2a5-0cb2-93ff-7d6a2958e3fd',
              'block_type' => 'card',
              'label' => 'Card',
              'block_fields' => [
                'field_heading' => 'A Title',
                'field_ref' => 99999,
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();

    // Find the reference error.
    $ref_error = NULL;
    foreach ($errors as $error) {
      if (str_contains($error->getPath(), 'field_ref')) {
        $ref_error = $error;
        break;
      }
    }

    $this->assertNotNull($ref_error);
    // Verify full nested path.
    $this->assertSame(
      'layout_builder__layout[0].regions.content[0].block_fields.field_ref',
      $ref_error->getPath(),
    );
    $this->assertSame('REFERENCE_NOT_FOUND', $ref_error->getCode());
  }

  /**
   * Tests multiple sections with components in different regions.
   */
  public function testMultipleSectionsMultipleRegions(): void {
    $entity = $this->createTestEntity();

    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
              'block_type' => 'text_section',
              'label' => 'Section 1 Block',
              'block_fields' => [
                'body' => ['value' => '<p>S1</p>', 'format' => 'plain_text'],
              ],
            ],
          ],
        ],
      ],
      [
        'layout_id' => 'layout_twocol_section',
        'regions' => [
          'first' => [
            [
              'uuid' => '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
              'block_type' => 'card',
              'label' => 'Left Card',
              'block_fields' => [
                'field_heading' => 'Left',
              ],
            ],
          ],
          'second' => [
            [
              'uuid' => '61516c9e-4914-5ac8-27df-826d6f878369',
              'block_type' => 'card',
              'label' => 'Right Card',
              'block_fields' => [
                'field_heading' => 'Right',
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(2, $sections);
    $this->assertSame('layout_onecol', $sections[0]->getLayoutId());
    $this->assertSame('layout_twocol_section', $sections[1]->getLayoutId());

    // Verify regions.
    $this->assertSame('content', $sections[0]->getComponent('faf95c53-35e7-74e1-184e-041152031df4')->getRegion());
    $this->assertSame('first', $sections[1]->getComponent('9bc043d4-072e-d2ff-9909-5b1e2e3c9826')->getRegion());
    $this->assertSame('second', $sections[1]->getComponent('61516c9e-4914-5ac8-27df-826d6f878369')->getRegion());
  }

  /**
   * Tests integration with BlueprintDeserializer for full pipeline.
   */
  public function testBlueprintDeserializerIntegration(): void {
    // Create entity with existing layout.
    $entity = $this->createEntityWithInlineBlock(
      'faf95c53-35e7-74e1-184e-041152031df4',
      'text_section',
      'Original',
      ['value' => '<p>Original</p>', 'format' => 'plain_text'],
    );

    $serializer = $this->container->get('daedalus_blueprint.serializer');
    $deserializer = $this->container->get('daedalus_blueprint.deserializer');

    // Serialize to blueprint.
    $blueprint = $serializer->serialize($entity);
    $this->assertArrayHasKey('layout_builder__layout', $blueprint);

    // Modify block_fields in the blueprint.
    $blueprint['layout_builder__layout'][0]['regions']['content'][0]['block_fields']['body'] = [
      'value' => '<p>Updated via pipeline</p>',
      'format' => 'plain_text',
    ];

    // Deserialize back through full pipeline.
    $result = $deserializer->deserialize($blueprint);

    $this->assertTrue($result->isSuccess(), 'Deserialization should succeed. Errors: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    // Verify the entity has the updated layout.
    $updated_entity = $result->getEntity();
    $sections = $updated_entity->get('layout_builder__layout')->getSections();
    $component = $sections[0]->getComponent('faf95c53-35e7-74e1-184e-041152031df4');
    $block = $this->unserializeBlock($component->get('configuration')['block_serialized']);
    $this->assertSame('<p>Updated via pipeline</p>', $block->get('body')->value);
  }

  /**
   * Tests BlueprintDeserializer with layout on new entity.
   */
  public function testBlueprintDeserializerNewEntityWithLayout(): void {
    $deserializer = $this->container->get('daedalus_blueprint.deserializer');

    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'New with layout',
      ],
      'layout_builder__layout' => [
        [
          'layout_id' => 'layout_onecol',
          'regions' => [
            'content' => [
              [
                'uuid' => '7900c460-e311-ca39-03f4-74e02e35140a',
                'block_type' => 'text_section',
                'label' => 'Welcome',
                'block_fields' => [
                  'body' => [
                    'value' => '<p>Welcome</p>',
                    'format' => 'plain_text',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess(), 'Deserialization should succeed. Errors: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $entity = $result->getEntity();
    $this->assertTrue($entity->isNew());
    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $block = $this->unserializeBlock($sections[0]->getComponent('7900c460-e311-ca39-03f4-74e02e35140a')->get('configuration')['block_serialized']);
    $this->assertSame('<p>Welcome</p>', $block->get('body')->value);
  }

  /**
   * Tests empty layout replaces existing layout.
   */
  public function testEmptyLayoutReplacesExisting(): void {
    $entity = $this->createEntityWithInlineBlock(
      'faf95c53-35e7-74e1-184e-041152031df4',
      'text_section',
      'Block',
      ['value' => '<p>Content</p>', 'format' => 'plain_text'],
    );

    $previous_layout = $this->layoutSerializer->serialize($entity);
    $this->assertNotEmpty($previous_layout);

    // Empty layout — removes all sections.
    $result = $this->layoutDeserializer->deserialize([], $entity);

    $this->assertFalse($result->hasErrors());
    $this->assertEmpty($entity->get('layout_builder__layout')->getSections());
  }

  /**
   * Tests label_display defaults to hidden for new inline blocks.
   */
  public function testLabelDisplayDefaultsToHidden(): void {
    $entity = $this->createTestEntity();

    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => '7900c460-e311-ca39-03f4-74e02e35140a',
              'block_type' => 'text_section',
              'label' => 'Test Block',
              'block_fields' => [
                'body' => ['value' => '<p>Test</p>', 'format' => 'plain_text'],
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);
    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $config = $sections[0]->getComponent('7900c460-e311-ca39-03f4-74e02e35140a')->get('configuration');
    $this->assertSame('0', $config['label_display']);
  }

  /**
   * Tests managed inline block configuration on create.
   */
  public function testManagedInlineBlockConfigOnCreate(): void {
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
    $this->layoutDeserializer = $this->container->get('daedalus_blueprint.layout_deserializer');

    $entity = $this->createTestEntity();

    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => '7900c460-e311-ca39-03f4-74e02e35140a',
              'block_type' => 'text_section',
              'label' => 'My Heading',
              'block_fields' => [
                'body' => ['value' => '<p>Test</p>', 'format' => 'plain_text'],
              ],
              'configuration' => [
                'title_size' => 'text-large1',
                'label_display' => 'visible',
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);
    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $config = $sections[0]->getComponent('7900c460-e311-ca39-03f4-74e02e35140a')->get('configuration');

    // Managed keys should be merged into configuration.
    $this->assertSame('text-large1', $config['title_size']);
    $this->assertSame('visible', $config['label_display']);
  }

  /**
   * Tests managed inline block configuration on update preserves non-managed.
   */
  public function testManagedInlineBlockConfigOnUpdate(): void {
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'text_section' => [
          'title_size' => [
            'label' => 'Title size',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
    ]);
    \Drupal::cache()->deleteAll();
    $this->layoutDeserializer = $this->container->get('daedalus_blueprint.layout_deserializer');

    // Create entity with existing component that has non-managed config.
    $entity = $this->createTestEntity();
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => 'Test',
      'reusable' => FALSE,
      'body' => ['value' => '<p>Original</p>', 'format' => 'plain_text'],
    ]);
    $block->save();

    $config = $this->createInlineBlockConfiguration('text_section', $block, 'Test');
    $config['title_size'] = 'text-large1';
    $config['non_managed_config'] = 'preserve_this';

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $config,
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Update with new managed value.
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'faf95c53-35e7-74e1-184e-041152031df4',
              'block_type' => 'text_section',
              'label' => 'Test',
              'block_fields' => [
                'body' => ['value' => '<p>Updated</p>', 'format' => 'plain_text'],
              ],
              'configuration' => [
                'title_size' => 'text-jumbo1',
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);
    $this->assertFalse($result->hasErrors());

    $sections = $entity->get('layout_builder__layout')->getSections();
    $updated_config = $sections[0]->getComponent('faf95c53-35e7-74e1-184e-041152031df4')->get('configuration');

    // Managed key updated.
    $this->assertSame('text-jumbo1', $updated_config['title_size']);
    // Non-managed key preserved.
    $this->assertSame('preserve_this', $updated_config['non_managed_config']);
  }

  /**
   * Creates an entity_test entity with a single inline block in its layout.
   *
   * @param string $comp_uuid
   *   The component UUID.
   * @param string $block_type
   *   The block content type.
   * @param string $label
   *   The admin label.
   * @param array $body_value
   *   The body field value array.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved entity, reloaded from storage.
   */
  protected function createEntityWithInlineBlock(string $comp_uuid, string $block_type, string $label, array $body_value): object {
    $entity = $this->createTestEntity();
    $block_storage = $this->entityTypeManager->getStorage('block_content');

    $block = $block_storage->create([
      'type' => $block_type,
      'info' => $label,
      'reusable' => FALSE,
      'body' => $body_value,
    ]);
    $block->save();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => $comp_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration($block_type, $block, $label),
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();

    return $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
  }

}
