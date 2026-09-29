<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\LayoutBuilder;

use Drupal\daedalus_blueprint\LayoutBuilder\LayoutDeserializer;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests native nested-section serialization and deserialization.
 *
 * Sections nest natively: a nested section is a container component
 * (daedalus_layout_section) whose configuration embeds one Section::toArray() value,
 * and it serializes as a section node with the exact same shape as a
 * top-level section, recursively (storage shape == sugar shape). The
 * container component is invisible plumbing: component uuid == embedded
 * section uuid, and no block facade ever appears.
 */
#[CoversClass(LayoutDeserializer::class)]
#[CoversClass(LayoutSerializer::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class NestedLayoutTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'entity_test',
    'daedalus_blueprint',
    'language',
    'content_translation',
  ];

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
   * The daedalus_layout uuid of the nested section in the fixture.
   */
  protected string $nestedSectionUuid = 'aaaa1111-1b5a-4186-a39b-c22158578490';

  /**
   * The component uuid of the text block inside the nested section.
   */
  protected string $nestedBlockUuid = 'acab1f60-1b5a-4186-a39b-c2215857849e';

  /**
   * The daedalus_layout uuid of the root section in the fixture.
   */
  protected string $rootSectionUuid = 'bbbb2222-fe17-4746-8fe1-7946f4bbab8e';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

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
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }
    $this->enableLayoutBuilder();

    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('body', 'text_with_summary', 'text_section');
    $this->createBlockContentType('card', 'Card');
    $this->createBlockField('field_heading', 'string', 'card', ['max_length' => 255]);

    $this->layoutDeserializer = $this->container->get('daedalus_blueprint.layout_deserializer');
    $this->layoutSerializer = $this->container->get('daedalus_blueprint.layout_serializer');
  }

  /**
   * Tests serializing an entity with a nested section.
   *
   * A container component serializes as a section node (layout_id, uuid,
   * label, regions) inside its parent region, not as a block node.
   */
  public function testSerializeNestedSection(): void {
    $entity = $this->createEntityWithNestedSection();
    $result = $this->layoutSerializer->serialize($entity);

    $this->assertCount(1, $result);
    $this->assertSame($this->rootSectionUuid, $result[0]['uuid']);

    $node = $result[0]['regions']['content'][0];
    $this->assertSame('layout_onecol', $node['layout_id']);
    $this->assertSame($this->nestedSectionUuid, $node['uuid']);
    $this->assertSame('Layout Container', $node['label']);
    $this->assertArrayNotHasKey('block_type', $node);
    $this->assertArrayNotHasKey('plugin_id', $node);
    $this->assertArrayNotHasKey('section_index', $node);

    $nested_comp = $node['regions']['content'][0];
    $this->assertSame($this->nestedBlockUuid, $nested_comp['uuid']);
    $this->assertSame('text_section', $nested_comp['block_type']);
    $this->assertSame('<p>Nested content</p>', $nested_comp['block_fields']['body']['value']);
    $this->assertSame('plain_text', $nested_comp['block_fields']['body']['format']);
  }

  /**
   * Tests deserializing a nested section node into a container component.
   */
  public function testDeserializeNestedSectionCreate(): void {
    $entity = $this->createTestEntity();

    $nested_uuid = '98ecbc05-28b3-48c5-895e-86dfa276af6c';
    $left_uuid = 'cc544f3a-04ed-4129-9b53-a527929550ca';
    $right_uuid = 'dd544f3a-04ed-4129-9b53-a527929550cb';
    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'layout_id' => 'layout_twocol_section',
              'uuid' => $nested_uuid,
              'label' => 'Split',
              'regions' => [
                'first' => [
                  [
                    'uuid' => $left_uuid,
                    'block_type' => 'text_section',
                    'label' => 'Left',
                    'block_fields' => [
                      'body' => [
                        'value' => '<p>Left column</p>',
                        'format' => 'plain_text',
                      ],
                    ],
                  ],
                ],
                'second' => [
                  [
                    'uuid' => $right_uuid,
                    'block_type' => 'card',
                    'label' => 'Right',
                    'block_fields' => [
                      'field_heading' => 'A heading',
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);
    $this->assertFalse($result->hasErrors(), 'Expected no errors. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);

    // One identity: the container component is addressed by the section
    // uuid, and its configuration embeds the Section value natively.
    $container = $sections[0]->getComponent($nested_uuid);
    $config = $container->get('configuration');
    $this->assertSame(SectionBlock::PLUGIN_ID, $config['id']);
    $this->assertSame(SectionBlock::CONTEXT_MAPPING, $config['context_mapping']);
    $this->assertIsArray($config[SectionBlock::SECTION_KEY]);
    $this->assertSame('layout_twocol_section', $config[SectionBlock::SECTION_KEY]['layout_id']);

    $embedded = SectionBlock::embeddedSection($container);
    $this->assertInstanceOf(Section::class, $embedded);
    $this->assertSame($nested_uuid, $embedded->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertSame('Split', $embedded->getLayoutSettings()['label']);

    $left = $embedded->getComponent($left_uuid);
    $this->assertSame('first', $left->getRegion());
    $left_block = $this->unserializeBlock($left->get('configuration')['block_serialized']);
    $this->assertSame('<p>Left column</p>', $left_block->get('body')->value);
    $this->assertSame('Left', $left_block->get('info')->value);

    $right = $embedded->getComponent($right_uuid);
    $this->assertSame('second', $right->getRegion());
    $right_block = $this->unserializeBlock($right->get('configuration')['block_serialized']);
    $this->assertSame('A heading', $right_block->get('field_heading')->value);
  }

  /**
   * Tests nested round-trip: serialize, modify nested content, deserialize.
   *
   * Storage shape == sugar shape: what the serializer emits deserializes
   * back with no reshaping, and a nested edit lands in the embedded value.
   */
  public function testNestedSectionRoundTrip(): void {
    $entity = $this->createEntityWithNestedSection();

    $serialized = $this->layoutSerializer->serialize($entity);
    $serialized[0]['regions']['content'][0]['regions']['content'][0]['block_fields']['body'] = [
      'value' => '<p>Modified nested content</p>',
      'format' => 'plain_text',
    ];

    $result = $this->layoutDeserializer->deserialize($serialized, $entity);
    $this->assertFalse($result->hasErrors(), 'Expected no errors. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $sections = $entity->get('layout_builder__layout')->getSections();
    $container = $sections[0]->getComponent($this->nestedSectionUuid);
    $embedded = SectionBlock::embeddedSection($container);
    $nested_block = $this->unserializeBlock($embedded->getComponent($this->nestedBlockUuid)->get('configuration')['block_serialized']);

    $this->assertSame('<p>Modified nested content</p>', $nested_block->get('body')->value);
    $this->assertSame('Layout Container', $embedded->getLayoutSettings()['label']);

    // Re-serializing yields the identical structural node.
    $again = $this->layoutSerializer->serialize($entity);
    $node = $again[0]['regions']['content'][0];
    $this->assertSame('layout_onecol', $node['layout_id']);
    $this->assertSame($this->nestedSectionUuid, $node['uuid']);
    $this->assertSame('Layout Container', $node['label']);
  }

  /**
   * Tests opaque data is preserved on matched nested sections.
   *
   * The recursive previous-state walk matches nested sections and their
   * container components by uuid: third-party settings on the embedded
   * section and additional data on the container (stencil provenance)
   * survive an echo-back deserialize, and the provenance surfaces as the
   * read-only stencil key on the serialized node.
   */
  public function testNestedSectionOpaquePreservation(): void {
    $entity = $this->createEntityWithNestedSection(
      section_tps: ['daedalus_stylish' => ['tone' => 'bold']],
      additional: [LayoutSerializer::STENCIL_PROVENANCE_KEY => 'hero_split'],
    );

    $serialized = $this->layoutSerializer->serialize($entity);
    $node = $serialized[0]['regions']['content'][0];
    $this->assertSame('hero_split', $node['stencil']);

    $result = $this->layoutDeserializer->deserialize($serialized, $entity);
    $this->assertFalse($result->hasErrors(), 'Expected no errors. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $sections = $entity->get('layout_builder__layout')->getSections();
    $container = $sections[0]->getComponent($this->nestedSectionUuid);
    $this->assertSame('hero_split', $container->toArray()['additional'][LayoutSerializer::STENCIL_PROVENANCE_KEY]);

    $embedded = SectionBlock::embeddedSection($container);
    $this->assertSame(['tone' => 'bold'], $embedded->getThirdPartySettings('daedalus_stylish'));
    $this->assertSame($this->nestedSectionUuid, $embedded->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

  /**
   * Tests addElement places a block inside a nested section by its uuid.
   */
  public function testAddComponentIntoNestedSection(): void {
    $entity = $this->createEntityWithNestedSection();
    $operations = $this->container->get('daedalus_blueprint.operations');

    $card_uuid = '763163bd-a9d4-43ac-9bee-280ecae80ed6';
    $result = $operations->addElement(
      $entity,
      ['anchor' => $this->nestedSectionUuid, 'placement' => 'inside'],
      [
        'uuid' => $card_uuid,
        'block_type' => 'card',
        'label' => 'Nested Card',
        'block_fields' => ['field_heading' => 'New Card Title'],
      ],
    );
    $this->assertTrue($result->isSuccess(), 'Expected success. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $serialized = $this->layoutSerializer->serialize($entity);
    $node = $serialized[0]['regions']['content'][0];
    $this->assertCount(2, $node['regions']['content']);
    $this->assertSame($card_uuid, $node['regions']['content'][1]['uuid']);
    $this->assertSame('card', $node['regions']['content'][1]['block_type']);
    $this->assertSame('New Card Title', $node['regions']['content'][1]['block_fields']['field_heading']);
  }

  /**
   * Tests removeElement on a nested section removes its container.
   *
   * One section per container structurally, so removing the section IS
   * removing the container component from the parent region.
   */
  public function testRemoveNestedSection(): void {
    $entity = $this->createEntityWithNestedSection();
    $operations = $this->container->get('daedalus_blueprint.operations');

    $result = $operations->removeElement($entity, $this->nestedSectionUuid);
    $this->assertTrue($result->isSuccess(), 'Expected success. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $this->assertSame([], $sections[0]->getComponents());
  }

  /**
   * Tests a block node carrying layout_builder__layout is refused.
   */
  public function testBlockNodeWithNestedLayoutRefused(): void {
    $entity = $this->createTestEntity();

    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'uuid' => 'e205fed4-94ae-4238-b6f7-33b3a89bf137',
              'block_type' => 'text_section',
              'label' => 'Text Block',
              'block_fields' => [],
              'layout_builder__layout' => [
                ['layout_id' => 'layout_onecol', 'regions' => []],
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertTrue($result->hasErrors());
    $error = $result->getErrors()[0];
    $this->assertSame('NO_LAYOUT', $error->getCode());
    $this->assertSame('layout_builder__layout[0].regions.content[0].layout_builder__layout', $error->getPath());
  }

  /**
   * Tests error paths inside nested sections carry the nested prefix.
   */
  public function testNestedSectionErrorPaths(): void {
    $entity = $this->createTestEntity();

    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'regions' => [
          'content' => [
            [
              'layout_id' => 'layout_twocol_section',
              'uuid' => '98ecbc05-28b3-48c5-895e-86dfa276af6c',
              'regions' => [
                'first' => [
                  [
                    'uuid' => 'not-a-uuid',
                    'block_type' => 'text_section',
                    'block_fields' => [],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);

    $this->assertTrue($result->hasErrors());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_UUID', $error->getCode());
    $this->assertSame('layout_builder__layout[0].regions.content[0].regions.first[0].uuid', $error->getPath());
  }

  /**
   * Tests the full blueprint pipeline round-trips nested sections.
   */
  public function testBlueprintPipelineNestedRoundTrip(): void {
    $entity = $this->createEntityWithNestedSection();

    $serializer = $this->container->get('daedalus_blueprint.serializer');
    $deserializer = $this->container->get('daedalus_blueprint.deserializer');

    $blueprint = $serializer->serialize($entity);
    $this->assertArrayHasKey('layout_builder__layout', $blueprint);
    $node = $blueprint['layout_builder__layout'][0]['regions']['content'][0];
    $this->assertSame('layout_onecol', $node['layout_id']);
    $this->assertSame($this->nestedSectionUuid, $node['uuid']);

    $blueprint['layout_builder__layout'][0]['regions']['content'][0]['regions']['content'][0]['block_fields']['body'] = [
      'value' => '<p>Modified via pipeline</p>',
      'format' => 'plain_text',
    ];

    $result = $deserializer->deserialize($blueprint);
    $this->assertTrue($result->isSuccess(), 'Deserialization should succeed. Errors: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $updated = $result->getEntity();
    $sections = $updated->get('layout_builder__layout')->getSections();
    $container = $sections[0]->getComponent($this->nestedSectionUuid);
    $embedded = SectionBlock::embeddedSection($container);
    $nested_block = $this->unserializeBlock($embedded->getComponent($this->nestedBlockUuid)->get('configuration')['block_serialized']);

    $this->assertSame('<p>Modified via pipeline</p>', $nested_block->get('body')->value);
  }

  /**
   * Tests layout writes on non-default translations are refused.
   *
   * The layout is one structure shared by every translation; a
   * langcode-scoped write would silently mutate it for all languages, so
   * the deserializer refuses it, mirroring core's access rule.
   */
  public function testTranslationGuardRefusesLayoutWrites(): void {
    $this->installEntitySchema('entity_test_mul');
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->enableLayoutBuilder('entity_test_mul', 'entity_test_mul');

    $storage = $this->entityTypeManager->getStorage('entity_test_mul');
    $entity = $storage->create(['name' => 'Host', 'langcode' => 'en']);
    $entity->save();

    $deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $layout = [['layout_id' => 'layout_onecol', 'regions' => []]];

    $blueprint = [
      'entity_type' => 'entity_test_mul',
      'bundle' => 'entity_test_mul',
      'langcode' => 'es',
      '_root_entity' => ['field' => 'id', 'value' => (int) $entity->id()],
      'fields' => [],
      'layout_builder__layout' => $layout,
    ];

    $result = $deserializer->deserialize($blueprint);
    $this->assertFalse($result->isSuccess());
    $codes = array_map(fn($e) => $e->getCode(), $result->getErrors());
    $this->assertContains('LAYOUT_NOT_TRANSLATABLE', $codes);

    // The same write through the default translation succeeds.
    $blueprint['langcode'] = 'en';
    $result = $deserializer->deserialize($blueprint);
    $this->assertTrue($result->isSuccess(), 'Default-translation layout write should succeed. Errors: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));
  }

  /**
   * Creates an entity whose root section holds one nested section.
   *
   * Structure: root section (layout_onecol, daedalus_layout uuid) > container
   * component (uuid == nested section uuid) embedding a layout_onecol
   * section labeled "Layout Container" holding one text_section block.
   *
   * @param array $section_tps
   *   Extra third-party settings for the nested section, keyed by provider.
   * @param array $additional
   *   Additional data for the container component.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved entity, reloaded from storage.
   */
  protected function createEntityWithNestedSection(array $section_tps = [], array $additional = []): object {
    $entity = $this->createTestEntity();
    $block_storage = $this->entityTypeManager->getStorage('block_content');

    $nested_block = $block_storage->create([
      'type' => 'text_section',
      'info' => 'Nested Text',
      'reusable' => FALSE,
      'body' => ['value' => '<p>Nested content</p>', 'format' => 'plain_text'],
    ]);

    $nested_section = $this->createSection('layout_onecol', [
      [
        'uuid' => $this->nestedBlockUuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $nested_block, 'Nested Text'),
      ],
    ], ['label' => 'Layout Container']);
    $nested_section->setThirdPartySetting('daedalus_layout', 'uuid', $this->nestedSectionUuid);
    foreach ($section_tps as $provider => $settings) {
      foreach ($settings as $key => $value) {
        $nested_section->setThirdPartySetting($provider, $key, $value);
      }
    }

    $root_section = $this->createSection('layout_onecol');
    $root_section->setThirdPartySetting('daedalus_layout', 'uuid', $this->rootSectionUuid);
    $root_section->appendComponent(SectionBlock::createComponent($nested_section, 'content', 0, $additional));

    $entity->get('layout_builder__layout')->appendSection($root_section);
    $entity->save();

    return $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
  }

}
