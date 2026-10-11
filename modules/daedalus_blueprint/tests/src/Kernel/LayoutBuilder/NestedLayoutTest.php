<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\LayoutBuilder;

use Drupal\daedalus_blueprint\BlueprintDeserializer;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests native nested-section serialization and the nested tree write.
 *
 * Sections nest natively: a nested section is a container component
 * (daedalus_layout_section) whose configuration embeds one Section::toArray() value,
 * and it serializes as a section node with the exact same shape as a
 * top-level section, recursively (storage shape == sugar shape). The
 * container component is invisible plumbing: component uuid == embedded
 * section uuid, and no block facade ever appears. On the write side a
 * nested container is a container node in a created entity's layout
 * tree, at any depth, and a read's tree creates an equal entity.
 */
#[CoversClass(BlueprintDeserializer::class)]
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
  ];

  /**
   * The blueprint deserializer.
   */
  protected BlueprintDeserializer $deserializer;

  /**
   * The layout serializer.
   *
   * @var \Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer
   */
  protected LayoutSerializer $layoutSerializer;

  /**
   * The lean node tree builder: the read of the tree.
   */
  protected NodeTreeBuilder $treeBuilder;

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

    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $this->layoutSerializer = $this->container->get('daedalus_blueprint.layout_serializer');
    $this->treeBuilder = $this->container->get('daedalus_blueprint.node_tree_builder');
  }

  /**
   * Tests serializing an entity with a nested section.
   *
   * A container component serializes as a container node (layout_id, uuid,
   * label, children) inside its parent's region node, not as a block node.
   */
  public function testSerializeNestedSection(): void {
    $entity = $this->createEntityWithNestedSection();
    $result = $this->layoutSerializer->serialize($this->host($entity));

    $this->assertCount(1, $result);
    $this->assertSame($this->rootSectionUuid, $result[0]['uuid']);

    $node = $result[0]['children'][0]['children'][0];
    $this->assertSame('container', $node['kind']);
    $this->assertSame('layout_onecol', $node['layout_id']);
    $this->assertSame($this->nestedSectionUuid, $node['uuid']);
    $this->assertSame('Layout Container', $node['label']);
    $this->assertArrayNotHasKey('block_type', $node);
    $this->assertArrayNotHasKey('plugin_id', $node);
    $this->assertArrayNotHasKey('section_index', $node);
    $this->assertArrayNotHasKey('regions', $node);

    $nested_comp = $node['children'][0]['children'][0];
    $this->assertSame($this->nestedBlockUuid, $nested_comp['uuid']);
    $this->assertSame('text_section', $nested_comp['block_type']);
    $this->assertSame('<p>Nested content</p>', $nested_comp['block_fields']['body']['value']);
    $this->assertSame('plain_text', $nested_comp['block_fields']['body']['format']);
  }

  /**
   * Tests a nested container node lands as a container component.
   */
  public function testCreateWithNestedContainerNode(): void {
    $nested_uuid = '98ecbc05-28b3-48c5-895e-86dfa276af6c';
    $left_uuid = 'cc544f3a-04ed-4129-9b53-a527929550ca';
    $right_uuid = 'dd544f3a-04ed-4129-9b53-a527929550cb';
    $result = $this->create([
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'children' => [
          [
            'kind' => 'region',
            'region' => 'content',
            'children' => [
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
                  ],
                  [
                    'kind' => 'region',
                    'region' => 'second',
                    'children' => [
                      [
                        'kind' => 'block',
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
        ],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), 'Expected no errors. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));
    $entity = $result->getEntity();

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
   * Tests a read's tree creates an equal entity, identity included.
   *
   * The tree of a nested document (the one shape) is the write grammar:
   * a nested edit made on the tree lands in the embedded value of the
   * created copy, under the same identity.
   */
  public function testReadTreeCreatesAnEqualEntity(): void {
    $entity = $this->createEntityWithNestedSection();

    $tree = $this->treeBuilder->build($this->host($entity));
    $this->assertSame($this->rootSectionUuid, $tree[0]['uuid']);
    $nested = &$tree[0]['children'][0]['children'][0];
    $this->assertSame('container', $nested['kind']);
    $this->assertSame($this->nestedSectionUuid, $nested['uuid']);
    $nested['children'][0]['children'][0]['block_fields']['body'] = [
      'value' => '<p>Modified nested content</p>',
      'format' => 'plain_text',
    ];
    unset($nested);

    $result = $this->create($tree);
    $this->assertTrue($result->isSuccess(), 'Expected no errors. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));
    $copy = $result->getEntity();

    $sections = $copy->get('layout_builder__layout')->getSections();
    $this->assertSame($this->rootSectionUuid, $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $container = $sections[0]->getComponent($this->nestedSectionUuid);
    $embedded = SectionBlock::embeddedSection($container);
    $nested_block = $this->unserializeBlock($embedded->getComponent($this->nestedBlockUuid)->get('configuration')['block_serialized']);
    $this->assertSame('<p>Modified nested content</p>', $nested_block->get('body')->value);
    $this->assertSame('Layout Container', $embedded->getLayoutSettings()['label']);

    // The copy reads the tree it was written from.
    $again = $this->treeBuilder->build($this->host($copy));
    $node = $again[0]['children'][0]['children'][0];
    $this->assertSame('layout_onecol', $node['layout_id']);
    $this->assertSame($this->nestedSectionUuid, $node['uuid']);
    $this->assertSame('Layout Container', $node['label']);
    $this->assertSame('<p>Modified nested content</p>', $node['children'][0]['children'][0]['block_fields']['body']['value']);
  }

  /**
   * Tests addElement places a block inside a nested section by its uuid.
   */
  public function testAddComponentIntoNestedSection(): void {
    $entity = $this->createEntityWithNestedSection();
    $operations = $this->container->get('daedalus_blueprint.operations');

    $card_uuid = '763163bd-a9d4-43ac-9bee-280ecae80ed6';
    $result = $operations->addElement(
      $this->host($entity),
      ['anchor' => $this->nestedSectionUuid, 'placement' => 'inside'],
      [
        'uuid' => $card_uuid,
        'block_type' => 'card',
        'label' => 'Nested Card',
        'block_fields' => ['field_heading' => 'New Card Title'],
      ],
    );
    $this->assertTrue($result->isSuccess(), 'Expected success. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $serialized = $this->layoutSerializer->serialize($this->host($entity));
    $node = $serialized[0]['children'][0]['children'][0];
    $nested_blocks = $node['children'][0]['children'];
    $this->assertCount(2, $nested_blocks);
    $this->assertSame($card_uuid, $nested_blocks[1]['uuid']);
    $this->assertSame('card', $nested_blocks[1]['block_type']);
    $this->assertSame('New Card Title', $nested_blocks[1]['block_fields']['field_heading']);
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

    $result = $operations->removeElement($this->host($entity), $this->nestedSectionUuid);
    $this->assertTrue($result->isSuccess(), 'Expected success. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $this->assertSame([], $sections[0]->getComponents());
  }

  /**
   * Tests a block node carrying layout is refused.
   */
  public function testBlockNodeWithNestedLayoutRefused(): void {
    $result = $this->create([
      $this->onecol([
        [
          'kind' => 'block',
          'uuid' => 'e205fed4-94ae-4238-b6f7-33b3a89bf137',
          'block_type' => 'text_section',
          'label' => 'Text Block',
          'block_fields' => [],
          'layout' => [
            ['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []],
          ],
        ],
      ]),
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_NODE', $error->getCode());
    $this->assertSame('layout[0].children[0].children[0].layout', $error->getPath());
    $this->assertStringContainsString('kind: container', $error->getHint());
  }

  /**
   * Tests error paths inside nested containers carry the nested prefix.
   */
  public function testNestedSectionErrorPaths(): void {
    $result = $this->create([
      $this->onecol([
        [
          'kind' => 'container',
          'layout_id' => 'layout_twocol_section',
          'uuid' => '98ecbc05-28b3-48c5-895e-86dfa276af6c',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'first',
              'children' => [
                [
                  'kind' => 'block',
                  'uuid' => 'not-a-uuid',
                  'block_type' => 'text_section',
                  'block_fields' => [],
                ],
              ],
            ],
          ],
        ],
      ]),
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_NODE', $error->getCode());
    $this->assertSame('layout[0].children[0].children[0].children[0].children[0].uuid', $error->getPath());
  }

  /**
   * Creates an entity_test from a layout tree through the deserializer.
   *
   * @param array $layout
   *   The tree.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result.
   */
  protected function create(array $layout): object {
    return $this->deserializer->deserialize([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Nested'],
      'layout' => $layout,
    ]);
  }

  /**
   * A onecol container node holding nodes in its content region.
   */
  protected function onecol(array $children): array {
    return [
      'kind' => 'container',
      'layout_id' => 'layout_onecol',
      'children' => [
        ['kind' => 'region', 'region' => 'content', 'children' => $children],
      ],
    ];
  }

  /**
   * Creates an entity whose root section holds one nested section.
   *
   * Structure: root section (layout_onecol, daedalus_layout uuid) > container
   * component (uuid == nested section uuid) embedding a layout_onecol
   * section labeled "Layout Container" holding one text_section block.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved entity, reloaded from storage.
   */
  protected function createEntityWithNestedSection(): object {
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

    $root_section = $this->createSection('layout_onecol');
    $root_section->setThirdPartySetting('daedalus_layout', 'uuid', $this->rootSectionUuid);
    $root_section->appendComponent(SectionBlock::createComponent($nested_section, 'content', 0));

    $entity->get('layout_builder__layout')->appendSection($root_section);
    $entity->save();

    return $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
  }

}
