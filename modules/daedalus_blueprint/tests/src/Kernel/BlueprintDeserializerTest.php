<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintDeserializer;
use Drupal\daedalus_blueprint\BlueprintSerializer;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the BlueprintDeserializer service.
 *
 * The whole-layout write: a created entity's `layout` is the lean node
 * tree, expanded through the instantiator with the author's uuids kept
 * and landed as the ordinary operations; node styles and the top-level
 * map merge into one map before the style store's handler; a layout for
 * an existing entity is refused with the ops road named; a created entity
 * carrying a layout is not seeded from its template; the translation
 * guard stays at the door; validate_only judges the tree on the unsaved
 * host and lands nothing.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintDeserializer::class)]
#[Group('daedalus_blueprint')]
class BlueprintDeserializerTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'content_translation',
  ];

  /**
   * The deserializer under test.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintDeserializer
   */
  protected BlueprintDeserializer $deserializer;

  /**
   * The serializer for round-trip tests.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintSerializer
   */
  protected BlueprintSerializer $serializer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
  }

  /**
   * Tests creating a new entity from a blueprint.
   */
  public function testDeserializeCreatesNewEntity(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Created via blueprint',
        'field_subtitle' => 'A subtitle',
        'field_count' => 10,
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('hydrated', $result->getPhase());
    $this->assertEmpty($result->getErrors());

    $entity = $result->getEntity();
    $this->assertNotNull($entity);
    $this->assertSame('entity_test', $entity->getEntityTypeId());
    $this->assertSame('entity_test', $entity->bundle());
    $this->assertSame('Created via blueprint', $entity->get('name')->value);
    $this->assertSame('A subtitle', $entity->get('field_subtitle')->value);
    $this->assertSame(10, (int) $entity->get('field_count')->value);

    // Entity should NOT be saved yet.
    $this->assertNull($entity->id());
  }

  /**
   * Tests updating an existing entity via _root_entity.
   */
  public function testDeserializeUpdatesExistingEntity(): void {
    $entity = $this->createTestEntity([
      'name' => 'Original name',
      'field_subtitle' => 'Original subtitle',
      'field_email' => 'original@example.com',
    ]);
    $entity_id = (int) $entity->id();

    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => $entity_id],
      'fields' => [
        'name' => 'Updated name',
        'field_subtitle' => 'Updated subtitle',
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $updated = $result->getEntity();

    // Submitted fields are updated.
    $this->assertSame('Updated name', $updated->get('name')->value);
    $this->assertSame('Updated subtitle', $updated->get('field_subtitle')->value);

    // Non-submitted fields retain their original values.
    $this->assertSame('original@example.com', $updated->get('field_email')->value);
  }

  /**
   * Tests round-trip: serialize → deserialize → compare field values.
   */
  public function testRoundTrip(): void {
    $entity = $this->createTestEntity([
      'name' => 'Round-trip entity',
      'field_subtitle' => 'A subtitle',
      'field_count' => 99,
      'field_email' => 'round@trip.com',
      'field_published' => TRUE,
      'field_tags_text' => ['Tag1', 'Tag2'],
      'field_description' => [
        'value' => '<p>Description</p>',
        'format' => 'plain_text',
      ],
      'field_body' => [
        'value' => '<p>Body content</p>',
        'format' => 'plain_text',
        'summary' => 'Summary',
      ],
    ]);

    // Serialize the entity.
    $blueprint = $this->serializer->serialize($this->host($entity));

    // Create a new entity from the serialized blueprint (remove _root_entity).
    unset($blueprint['_root_entity']);
    $result = $this->deserializer->deserialize($blueprint);

    $this->assertTrue($result->isSuccess(), 'Round-trip deserialization failed: ' . print_r($result->getErrors(), TRUE));

    $new_entity = $result->getEntity();

    // Compare field values.
    $this->assertSame('Round-trip entity', $new_entity->get('name')->value);
    $this->assertSame('A subtitle', $new_entity->get('field_subtitle')->value);
    $this->assertSame(99, (int) $new_entity->get('field_count')->value);
    $this->assertSame('round@trip.com', $new_entity->get('field_email')->value);
    $this->assertTrue((bool) $new_entity->get('field_published')->value);
    $this->assertSame('<p>Description</p>', $new_entity->get('field_description')->value);
    $this->assertSame('plain_text', $new_entity->get('field_description')->format);
    $this->assertSame('<p>Body content</p>', $new_entity->get('field_body')->value);
    $this->assertSame('plain_text', $new_entity->get('field_body')->format);
    $this->assertSame('Summary', $new_entity->get('field_body')->summary);

    // Multi-value field.
    $tags = [];
    foreach ($new_entity->get('field_tags_text') as $item) {
      $tags[] = $item->value;
    }
    $this->assertSame(['Tag1', 'Tag2'], $tags);
  }

  /**
   * Tests serialize → modify → deserialize update workflow.
   */
  public function testRoundTripUpdate(): void {
    $entity = $this->createTestEntity([
      'name' => 'Original',
      'field_subtitle' => 'Original subtitle',
      'field_count' => 5,
    ]);

    // Serialize.
    $blueprint = $this->serializer->serialize($this->host($entity));

    // Modify.
    $blueprint['fields']['name'] = 'Modified';
    $blueprint['fields']['field_count'] = 10;

    // Deserialize back (with _root_entity for update).
    $result = $this->deserializer->deserialize($blueprint);

    $this->assertTrue($result->isSuccess());
    $updated = $result->getEntity();
    $this->assertSame('Modified', $updated->get('name')->value);
    $this->assertSame(10, (int) $updated->get('field_count')->value);
    $this->assertSame('Original subtitle', $updated->get('field_subtitle')->value);
  }

  /**
   * Tests validate_only mode returns validated result without entity.
   */
  public function testValidateOnlyMode(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test',
      ],
    ];

    $result = $this->deserializer->deserialize($data, ['validate_only' => TRUE]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame('validated', $result->getPhase());
    $this->assertNull($result->getEntity());
    $this->assertEmpty($result->getErrors());
  }

  /**
   * A validate_only update judges a clone: the loaded entity is untouched.
   */
  public function testValidateOnlyUpdateLeavesTheLoadedEntityAlone(): void {
    $entity = $this->createTestEntity(['name' => 'Loaded', 'field_subtitle' => 'Kept']);

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => ['field_subtitle' => 'Judged only'],
    ], ['validate_only' => TRUE]);

    $this->assertTrue($result->isSuccess(), print_r($result->getErrors(), TRUE));
    $this->assertSame('validated', $result->getPhase());
    $this->assertNull($result->getEntity());
    $loaded = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
    $this->assertSame('Kept', $loaded->get('field_subtitle')->value, 'The request\'s shared object carries no dry-run write.');
  }

  /**
   * Tests that structural errors return structural failure.
   */
  public function testStructuralErrorsReturnFailure(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'nonexistent_field' => 'value',
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $this->assertNull($result->getEntity());

    $errors = $result->getErrors();
    $this->assertNotEmpty($errors);
    $this->assertSame('UNKNOWN_FIELD', $errors[0]->getCode());
  }

  /**
   * Tests that _root_entity with nonexistent entity returns error.
   */
  public function testEntityNotFound(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => 99999],
      'fields' => [
        'name' => 'Test',
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());

    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ENTITY_NOT_FOUND', $errors[0]->getCode());
    $this->assertSame('_root_entity', $errors[0]->getPath());
  }

  /**
   * Tests that the deserialized entity is not saved.
   */
  public function testDeserializeReturnsUnsavedEntity(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Unsaved test',
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertNull($entity->id());
    $this->assertTrue($entity->isNew());
  }

  /**
   * Tests that only submitted fields are changed on update.
   */
  public function testPartialUpdatePreservesUnsubmittedFields(): void {
    $entity = $this->createTestEntity([
      'name' => 'Keep this',
      'field_subtitle' => 'Keep this too',
      'field_count' => 42,
      'field_email' => 'keep@example.com',
    ]);
    $entity_id = (int) $entity->id();

    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => $entity_id],
      'fields' => [
        'field_count' => 100,
      ],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $updated = $result->getEntity();

    // Only field_count changed.
    $this->assertSame(100, (int) $updated->get('field_count')->value);

    // Everything else preserved.
    $this->assertSame('Keep this', $updated->get('name')->value);
    $this->assertSame('Keep this too', $updated->get('field_subtitle')->value);
    $this->assertSame('keep@example.com', $updated->get('field_email')->value);
  }

  /**
   * Tests that empty fields map still creates a valid entity.
   */
  public function testDeserializeWithEmptyFields(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [],
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertNotNull($entity);
    $this->assertTrue($entity->isNew());
  }

  /**
   * Tests deserialization with no fields key at all.
   */
  public function testDeserializeWithNoFieldsKey(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ];

    $result = $this->deserializer->deserialize($data);

    $this->assertTrue($result->isSuccess());
    $entity = $result->getEntity();
    $this->assertNotNull($entity);
    $this->assertTrue($entity->isNew());
  }

  /**
   * A created entity's layout is the lean tree, landed with its identity.
   *
   * A onecol root holds a region node with a block (its uuid kept) and a
   * nested two-column container (its uuid kept) whose first slot holds a
   * block naming no uuid (minted). The document lands as the ordinary
   * operations would land it: the nested container is a container
   * component embedding its section, the blocks carry their fields and
   * labels, and a read of the tree answers the input apart from minted
   * identity.
   */
  public function testLayoutCreatesTheDocument(): void {
    $this->setUpLayoutPayloads();
    $block_uuid = '5a1c7de1-9d0c-4f7e-8f57-8f6c0e0a2ab1';
    $nested_uuid = '98ecbc05-28b3-48c5-895e-86dfa276af6c';

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Authored'],
      'layout' => [
        [
          'kind' => 'container',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                $this->headingBlock($block_uuid, 'First'),
                [
                  'kind' => 'container',
                  'layout_id' => 'layout_twocol_section',
                  'uuid' => $nested_uuid,
                  'label' => 'Split',
                  'children' => [
                    [
                      'kind' => 'region',
                      'region' => 'first',
                      'children' => [
                        [
                          'kind' => 'block',
                          'block_type' => 'text_section',
                          'label' => 'Minted',
                          'label_rendered' => TRUE,
                          'block_fields' => ['field_heading' => 'Minted'],
                        ],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $entity = $result->getEntity();
    $this->assertTrue($entity->isNew(), 'The caller decides to save.');

    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $this->assertSame('layout_onecol', $sections[0]->getLayoutId());
    $this->assertSame([$block_uuid, $nested_uuid], array_keys($sections[0]->getComponentsByRegion('content')));

    $block = $this->unserializeBlock($sections[0]->getComponent($block_uuid)->get('configuration')['block_serialized']);
    $this->assertSame('First', $block->get('field_heading')->value);
    $this->assertSame('0', $sections[0]->getComponent($block_uuid)->get('configuration')['label_display']);

    $container = $sections[0]->getComponent($nested_uuid);
    $this->assertSame(SectionBlock::PLUGIN_ID, $container->getPluginId());
    $embedded = SectionBlock::embeddedSection($container);
    $this->assertSame('layout_twocol_section', $embedded->getLayoutId());
    $this->assertSame($nested_uuid, $embedded->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertSame('Split', $embedded->getLayoutSettings()['label']);
    $minted = $embedded->getComponentsByRegion('first');
    $this->assertCount(1, $minted);
    $minted = reset($minted);
    $this->assertSame('visible', $minted->get('configuration')['label_display']);
    $this->assertSame('Minted', $this->unserializeBlock($minted->get('configuration')['block_serialized'])->get('field_heading')->value);

    // The read answers the same tree: identity kept where given.
    $tree = $this->container->get('daedalus_blueprint.node_tree_builder')->build($this->host($entity));
    $this->assertSame($block_uuid, $tree[0]['children'][0]['children'][0]['uuid']);
    $this->assertSame($nested_uuid, $tree[0]['children'][0]['children'][1]['uuid']);
    $this->assertSame('first', $tree[0]['children'][0]['children'][1]['children'][0]['region']);
  }

  /**
   * A created entity carrying a layout is not seeded from its template.
   *
   * With a template on the display, a create without a layout reads the
   * seeded document (the template's section under derived identity); a
   * create with a layout reads exactly what it authored.
   */
  public function testLayoutDeclinesTheTemplateSeed(): void {
    $this->setUpLayoutPayloads();
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $template = $this->createSection('layout_onecol', [
      [
        'uuid' => 'aaaaaaaa-1111-4444-8888-000000000001',
        'region' => 'content',
        'configuration' => [
          'id' => 'system_powered_by_block',
          'provider' => 'system',
          'label' => 'Template',
          'label_display' => '0',
        ],
      ],
    ]);
    $template->setThirdPartySetting('daedalus_layout', 'uuid', 'aaaaaaaa-1111-4444-8888-000000000000');
    $display->appendSection($template)->save();

    $seeded = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Seeded'],
    ]);
    $this->assertTrue($seeded->isSuccess());
    $sections = $seeded->getHost()->sections();
    $this->assertCount(1, $sections);
    $components = $sections[0]->getComponents();
    $this->assertCount(1, $components);
    $this->assertSame('system_powered_by_block', reset($components)->getPluginId(), 'Without a layout the template seeds the page.');

    $container_uuid = '0b9de2c4-6a4f-4c3b-9d8e-1f2a3b4c5d6e';
    $authored = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Authored'],
      'layout' => [
        ['kind' => 'container', 'layout_id' => 'layout_onecol', 'uuid' => $container_uuid, 'children' => []],
      ],
    ]);
    $this->assertTrue($authored->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $authored->getErrors()), TRUE));
    $sections = $authored->getHost()->sections();
    $this->assertCount(1, $sections);
    $this->assertSame($container_uuid, $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertSame([], $sections[0]->getComponents(), 'The authored document is the page\'s only starting layout.');
    // A fresh host over the same entity reads the same document.
    $this->assertCount(1, $this->host($authored->getEntity())->sections());
  }

  /**
   * Node styles and the top-level map merge into one validated map.
   *
   * A container node's styles land under its absolute key beside the
   * top-level map's entries (a block addressed by the uuid the node kept);
   * a key stated in both places is refused.
   */
  public function testStylesOnNodesAndAtTheTopMerge(): void {
    $this->setUpLayoutPayloads();
    $this->setUpStyleStore();
    $container_uuid = '7c2e4f61-3b5a-4d8c-9e0f-2a1b3c4d5e6f';
    $block_uuid = '5a1c7de1-9d0c-4f7e-8f57-8f6c0e0a2ab1';
    $layout = [
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'uuid' => $container_uuid,
        'styles' => ['' => ['default' => ['padding' => '2rem']]],
        'children' => [
          [
            'kind' => 'region',
            'region' => 'content',
            'children' => [
              $this->headingBlock($block_uuid, 'Styled') + ['styles' => ['::p' => ['default' => ['margin' => '0']]]],
            ],
          ],
        ],
      ],
    ];

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Styled'],
      'styles' => ["block:$block_uuid" => ['default' => ['margin' => '1rem']]],
      'layout' => $layout,
    ]);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $map = $result->getEntity()->get(DaedalusStylishFieldManager::FIELD_NAME)->first()?->getStyleMap() ?? [];
    $this->assertSame([
      "block:$block_uuid" => ['default' => ['margin' => '1rem']],
      "container:$container_uuid" => ['default' => ['padding' => '2rem']],
      "block:$block_uuid::p" => ['default' => ['margin' => '0']],
    ], $map);

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Twice'],
      'styles' => ["container:$container_uuid" => ['default' => ['padding' => '1rem']]],
      'layout' => $layout,
    ]);
    $this->assertFalse($result->isSuccess());
    $errors = array_filter($result->getErrors(), fn ($error) => $error->getCode() === 'DUPLICATE_STYLE_KEY');
    $this->assertCount(1, $errors);
    $this->assertSame("styles.container:$container_uuid", reset($errors)->getPath());
  }

  /**
   * A layout for an existing entity is refused; a fields-only update lands.
   */
  public function testLayoutRefusedOnExistingEntity(): void {
    $this->setUpLayoutPayloads();
    $entity = $this->createTestEntity(['name' => 'Existing', 'field_subtitle' => 'Before']);

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => ['field_subtitle' => 'After'],
      'layout' => [
        ['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('LAYOUT_ON_EXISTING_ENTITY', $errors[0]->getCode());
    $this->assertSame('layout', $errors[0]->getPath());
    $this->assertStringContainsString('move_element', $errors[0]->getHint());
    $this->assertStringContainsString('page_edit', $errors[0]->getHint());

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => ['field_subtitle' => 'After'],
    ]);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $this->assertSame('After', $result->getEntity()->get('field_subtitle')->value);
  }

  /**
   * The translation guard stays at the door.
   *
   * A create naming a non-default langcode carries its layout onto a
   * translation, and the document is one structure every translation
   * shares: refused as LAYOUT_NOT_TRANSLATABLE. The default language
   * lands.
   */
  public function testTranslationGuardStaysAtTheDoor(): void {
    $this->setUpLayoutPayloads();
    $this->installEntitySchema('entity_test_mul');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->enableLayoutBuilder('entity_test_mul', 'entity_test_mul');
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');

    $blueprint = [
      'entity_type' => 'entity_test_mul',
      'bundle' => 'entity_test_mul',
      'langcode' => 'es',
      'fields' => ['name' => 'Hola'],
      'layout' => [
        ['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []],
      ],
    ];
    $result = $this->deserializer->deserialize($blueprint);
    $this->assertFalse($result->isSuccess());
    $this->assertContains('LAYOUT_NOT_TRANSLATABLE', array_map(fn ($e) => $e->getCode(), $result->getErrors()));

    $blueprint['langcode'] = 'en';
    $result = $this->deserializer->deserialize($blueprint);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $this->assertCount(1, $result->getHost()->sections());
  }

  /**
   * A validate_only create judges the tree on the unsaved host, landing nothing.
   */
  public function testValidateOnlyJudgesTheLayout(): void {
    $this->setUpLayoutPayloads();
    $tree = [
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'children' => [
          [
            'kind' => 'region',
            'region' => 'content',
            'children' => [$this->headingBlock('5a1c7de1-9d0c-4f7e-8f57-8f6c0e0a2ab1', 'Judged')],
          ],
        ],
      ],
    ];
    $blueprint = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Judged'],
      'layout' => $tree,
    ];

    $result = $this->deserializer->deserialize($blueprint, ['validate_only' => TRUE]);
    $this->assertTrue($result->isSuccess(), print_r(array_map(fn ($e) => $e->toArray(), $result->getErrors()), TRUE));
    $this->assertSame('validated', $result->getPhase());
    $this->assertNull($result->getEntity());
    $this->assertSame([], $this->entityTypeManager->getStorage('entity_test')->loadMultiple());

    // The ops' own verdicts ride the tree's coordinates: an unknown block
    // type deep in the tree.
    $blueprint['layout'][0]['children'][0]['children'][0]['block_type'] = 'no_such_type';
    $result = $this->deserializer->deserialize($blueprint, ['validate_only' => TRUE]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_BLOCK_TYPE', $result->getErrors()[0]->getCode());
    $this->assertSame('layout[0].children[0].children[0].block_type', $result->getErrors()[0]->getPath());

    // The tree grammar is judged the same way: the dead regions map.
    $blueprint['layout'] = [['kind' => 'container', 'layout_id' => 'layout_onecol', 'regions' => ['content' => []]]];
    $result = $this->deserializer->deserialize($blueprint, ['validate_only' => TRUE]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_NODE', $result->getErrors()[0]->getCode());
    $this->assertSame('layout[0]', $result->getErrors()[0]->getPath());
    $this->assertStringContainsString('"regions"', $result->getErrors()[0]->getMessage());

    // A layout that is no list is refused in Phase A.
    $blueprint['layout'] = ['kind' => 'container'];
    $result = $this->deserializer->deserialize($blueprint, ['validate_only' => TRUE]);
    $this->assertSame('structural', $result->getPhase());
    $this->assertSame('INVALID_LAYOUT', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests a payload repeating one block uuid on two nodes is refused.
   */
  public function testLayoutRepeatedComponentUuidRefused(): void {
    $this->setUpLayoutPayloads();
    $uuid = '5a1c7de1-9d0c-4f7e-8f57-8f6c0e0a2ab1';

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Repeated component uuid'],
      'layout' => [
        $this->onecol([$this->headingBlock($uuid, 'First')]),
        $this->onecol([$this->headingBlock($uuid, 'Second')]),
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = array_values(array_filter(
      $result->getErrors(),
      fn($error) => $error->getCode() === 'UUID_IN_USE',
    ));
    $this->assertCount(1, $errors, 'Only the repeat errors; the first occurrence claims the uuid.');
    $this->assertSame('layout[1].children[0].children[0].uuid', $errors[0]->getPath());
  }

  /**
   * Tests a payload repeating one container uuid on two nodes is refused.
   */
  public function testLayoutRepeatedSectionUuidRefused(): void {
    $this->setUpLayoutPayloads();
    $uuid = '0b9de2c4-6a4f-4c3b-9d8e-1f2a3b4c5d6e';

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Repeated section uuid'],
      'layout' => [
        ['kind' => 'container', 'layout_id' => 'layout_onecol', 'uuid' => $uuid, 'children' => []],
        ['kind' => 'container', 'layout_id' => 'layout_onecol', 'uuid' => $uuid, 'children' => []],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = array_values(array_filter(
      $result->getErrors(),
      fn($error) => $error->getCode() === 'UUID_IN_USE',
    ));
    $this->assertCount(1, $errors);
    $this->assertSame('layout[1].uuid', $errors[0]->getPath());
  }

  /**
   * Tests container and block uuids share one payload namespace.
   *
   * Containers bridge the two namespaces (component uuid == section uuid),
   * so a block claiming a uuid a container node already claimed is a
   * repeat.
   */
  public function testLayoutSectionUuidReusedByComponentRefused(): void {
    $this->setUpLayoutPayloads();
    $uuid = '7c2e4f61-3b5a-4d8c-9e0f-2a1b3c4d5e6f';

    $result = $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Cross-namespace repeat'],
      'layout' => [
        ['kind' => 'container', 'layout_id' => 'layout_onecol', 'uuid' => $uuid, 'children' => []],
        $this->onecol([$this->headingBlock($uuid, 'Bridge')]),
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = array_values(array_filter(
      $result->getErrors(),
      fn($error) => $error->getCode() === 'UUID_IN_USE',
    ));
    $this->assertCount(1, $errors);
    $this->assertSame('layout[1].children[0].children[0].uuid', $errors[0]->getPath());
  }

  /**
   * Enables Layout Builder, section identity and a block type for layouts.
   */
  protected function setUpLayoutPayloads(): void {
    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'navigation',
      'twig_events',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      // Section identity (the uuid the tree addresses containers by) and
      // the template seed are daedalus_layout's.
      'daedalus_layout',
    ]);
    $this->enableLayoutBuilder();
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');
    // enableModules() rebuilt the container; re-fetch the services.
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
  }

  /**
   * Gives entity_test a style store (daedalus_stylish, unbound).
   */
  protected function setUpStyleStore(): void {
    $this->enableModules(['daedalus_stylish', 'daedalus_stylish_unbound_test']);
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $this->serializer = $this->container->get('daedalus_blueprint.serializer');
  }

  /**
   * A onecol container node holding blocks in its content region.
   *
   * @param array $blocks
   *   The block nodes.
   *
   * @return array
   *   The container node.
   */
  protected function onecol(array $blocks): array {
    return [
      'kind' => 'container',
      'layout_id' => 'layout_onecol',
      'children' => [
        ['kind' => 'region', 'region' => 'content', 'children' => $blocks],
      ],
    ];
  }

  /**
   * Builds an inline-block node for layout payloads.
   *
   * @param string $uuid
   *   The block uuid.
   * @param string $heading
   *   The heading text (also the label).
   *
   * @return array
   *   The block node.
   */
  protected function headingBlock(string $uuid, string $heading): array {
    return [
      'kind' => 'block',
      'uuid' => $uuid,
      'block_type' => 'text_section',
      'label' => $heading,
      'block_fields' => ['field_heading' => $heading],
    ];
  }

  /**
   * Tests that toArray on the result provides expected structure.
   */
  public function testResultToArray(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => [
        'name' => 'Test',
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $array = $result->toArray();

    $this->assertTrue($array['success']);
    $this->assertSame('hydrated', $array['phase']);
    $this->assertSame('entity_test', $array['entity_type']);
    $this->assertSame('entity_test', $array['bundle']);
    $this->assertArrayNotHasKey('errors', $array);
  }

  /**
   * Tests error result toArray includes structured errors.
   */
  public function testErrorResultToArray(): void {
    $data = [
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      '_root_entity' => ['field' => 'id', 'value' => 99999],
      'fields' => [
        'name' => 'Test',
      ],
    ];

    $result = $this->deserializer->deserialize($data);
    $array = $result->toArray();

    $this->assertFalse($array['success']);
    $this->assertSame('semantic', $array['phase']);
    $this->assertNotEmpty($array['errors']);
    $this->assertSame('ENTITY_NOT_FOUND', $array['errors'][0]['code']);
  }

}
