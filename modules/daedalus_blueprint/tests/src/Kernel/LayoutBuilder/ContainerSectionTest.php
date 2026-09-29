<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\LayoutBuilder;

use Drupal\daedalus_blueprint\LayoutBuilder\ContainerLayout;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutDeserializer;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Validator\StructuralValidator;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the children-list serialization shape of container sections.
 *
 * A container layout (`container: true`, exactly one region — see
 * ContainerLayout) has a vestigial storage region, so its section nodes
 * serialize one ordered `children` list instead of the `regions` map, at
 * every depth. Multi-region sections keep the regions shape; the stored
 * regions spelling stays accepted on input for containers.
 */
#[CoversClass(ContainerLayout::class)]
#[CoversClass(LayoutSerializer::class)]
#[CoversClass(LayoutDeserializer::class)]
#[CoversClass(StructuralValidator::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class ContainerSectionTest extends BlueprintKernelTestBase {

  /**
   * The layout serializer.
   */
  protected LayoutSerializer $layoutSerializer;

  /**
   * The layout deserializer.
   */
  protected LayoutDeserializer $layoutDeserializer;

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
      'daedalus_blueprint_test',
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

    $this->layoutSerializer = $this->container->get('daedalus_blueprint.layout_serializer');
    $this->layoutDeserializer = $this->container->get('daedalus_blueprint.layout_deserializer');
  }

  /**
   * Creates an unsaved text block.
   */
  protected function createTextBlock(string $info, string $body): object {
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'info' => $info,
      'reusable' => FALSE,
      'body' => ['value' => $body, 'format' => 'plain_text'],
    ]);
    $block->save();
    return $block;
  }

  /**
   * Tests container sections serialize an ordered children list.
   *
   * No regions key, component order preserved, and the storage region name
   * appears nowhere in the node.
   */
  public function testSerializeContainerChildren(): void {
    $entity = $this->createTestEntity();

    $first = $this->createTextBlock('First', '<p>First</p>');
    $second = $this->createTextBlock('Second', '<p>Second</p>');
    $section = $this->createSection('eb_test_container', [
      [
        'uuid' => 'aaaaaaaa-1111-4444-8888-000000000001',
        'region' => 'content',
        'configuration' => $this->createInlineBlockConfiguration('text_section', $first, 'First'),
      ],
      [
        'uuid' => 'aaaaaaaa-1111-4444-8888-000000000002',
        'region' => 'content',
        'configuration' => $this->createInlineBlockConfiguration('text_section', $second, 'Second'),
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->layoutSerializer->serialize($entity);

    $this->assertSame('eb_test_container', $result[0]['layout_id']);
    $this->assertArrayNotHasKey('regions', $result[0]);
    $this->assertSame(
      ['aaaaaaaa-1111-4444-8888-000000000001', 'aaaaaaaa-1111-4444-8888-000000000002'],
      array_column($result[0]['children'], 'uuid'),
    );
    $this->assertSame('<p>First</p>', $result[0]['children'][0]['block_fields']['body']['value']);

    // The summary path emits the same shape.
    $summary = $this->layoutSerializer->serializeSummary($entity);
    $this->assertArrayNotHasKey('regions', $summary[0]);
    $this->assertCount(2, $summary[0]['children']);
  }

  /**
   * Tests a NESTED container node carries children.
   *
   * The parent keeps its regions, and an empty container serializes an
   * empty list.
   */
  public function testSerializeNestedContainerChildren(): void {
    $entity = $this->createTestEntity();
    $nested_uuid = 'bbbbbbbb-2222-4444-8888-000000000001';
    $block_uuid = 'bbbbbbbb-2222-4444-8888-000000000002';

    $block = $this->createTextBlock('Nested', '<p>Nested</p>');
    $nested = $this->createSection('eb_test_container', [
      [
        'uuid' => $block_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Nested'),
      ],
    ]);
    $nested->setThirdPartySetting('daedalus_layout', 'uuid', $nested_uuid);

    $empty_uuid = 'bbbbbbbb-2222-4444-8888-000000000003';
    $empty = $this->createSection('eb_test_container');
    $empty->setThirdPartySetting('daedalus_layout', 'uuid', $empty_uuid);

    $root = $this->createSection('layout_onecol');
    $root->appendComponent(SectionBlock::createComponent($nested, 'content', 0));
    $root->appendComponent(SectionBlock::createComponent($empty, 'content', 1));

    $entity->get('layout_builder__layout')->appendSection($root);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $result = $this->layoutSerializer->serialize($entity);

    // The multi-region-capable parent keeps the regions map.
    $node = $result[0]['regions']['content'][0];
    $this->assertSame('eb_test_container', $node['layout_id']);
    $this->assertSame($nested_uuid, $node['uuid']);
    $this->assertArrayNotHasKey('regions', $node);
    $this->assertSame($block_uuid, $node['children'][0]['uuid']);

    $empty_node = $result[0]['regions']['content'][1];
    $this->assertSame([], $empty_node['children']);
    $this->assertArrayNotHasKey('regions', $empty_node);
  }

  /**
   * Tests deserializing a children list into the vestigial storage region.
   */
  public function testDeserializeChildren(): void {
    $entity = $this->createTestEntity();
    $nested_uuid = 'cccccccc-3333-4444-8888-000000000001';
    $block_uuid = 'cccccccc-3333-4444-8888-000000000002';

    $new_layout = [
      [
        'layout_id' => 'eb_test_container',
        'uuid' => 'cccccccc-3333-4444-8888-000000000000',
        'children' => [
          [
            'uuid' => $block_uuid,
            'block_type' => 'text_section',
            'label' => 'Text',
            'block_fields' => [
              'body' => ['value' => '<p>Hello</p>', 'format' => 'plain_text'],
            ],
          ],
          [
            'layout_id' => 'eb_test_container',
            'uuid' => $nested_uuid,
            'label' => 'Inner',
            'children' => [],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);
    $this->assertFalse($result->hasErrors(), 'Expected no errors. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);

    // Children landed in the vestigial storage region, in list order.
    $block_component = $sections[0]->getComponent($block_uuid);
    $this->assertSame('content', $block_component->getRegion());
    $this->assertSame(0, $block_component->getWeight());
    $block = $this->unserializeBlock($block_component->get('configuration')['block_serialized']);
    $this->assertSame('<p>Hello</p>', $block->get('body')->value);

    $container_component = $sections[0]->getComponent($nested_uuid);
    $this->assertSame('content', $container_component->getRegion());
    $this->assertSame(1, $container_component->getWeight());
    $embedded = SectionBlock::embeddedSection($container_component);
    $this->assertSame('Inner', $embedded->getLayoutSettings()['label']);

    // Round trip: what the serializer emits deserializes back unchanged.
    $again = $this->layoutSerializer->serialize($entity);
    $this->assertArrayNotHasKey('regions', $again[0]);
    $this->assertSame([$block_uuid, $nested_uuid], array_column($again[0]['children'], 'uuid'));
    $result = $this->layoutDeserializer->deserialize($again, $entity);
    $this->assertFalse($result->hasErrors(), 'Round trip should succeed. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));
  }

  /**
   * Tests the stored regions spelling stays accepted for containers.
   *
   * Storage shape, stencil definitions, and old payloads key the container's
   * components by its storage region; input compatibility holds until the
   * migration retires the spelling.
   */
  public function testDeserializeContainerRegionsSpelling(): void {
    $entity = $this->createTestEntity();
    $block_uuid = 'dddddddd-4444-4444-8888-000000000001';

    $new_layout = [
      [
        'layout_id' => 'eb_test_container',
        'regions' => [
          'content' => [
            [
              'uuid' => $block_uuid,
              'block_type' => 'text_section',
              'block_fields' => [
                'body' => ['value' => '<p>Stored spelling</p>', 'format' => 'plain_text'],
              ],
            ],
          ],
        ],
      ],
    ];

    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);
    $this->assertFalse($result->hasErrors(), 'Expected no errors. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $sections = $entity->get('layout_builder__layout')->getSections();
    $this->assertNotNull($sections[0]->getComponent($block_uuid));

    // Output canonicalizes to children regardless of the input spelling.
    $serialized = $this->layoutSerializer->serialize($entity);
    $this->assertArrayNotHasKey('regions', $serialized[0]);
    $this->assertSame($block_uuid, $serialized[0]['children'][0]['uuid']);
  }

  /**
   * Tests bare-UUID relocation entries work inside children lists.
   */
  public function testRelocationInsideChildren(): void {
    $entity = $this->createTestEntity();
    $first_uuid = 'eeeeeeee-5555-4444-8888-000000000001';
    $second_uuid = 'eeeeeeee-5555-4444-8888-000000000002';

    $first = $this->createTextBlock('First', '<p>First</p>');
    $second = $this->createTextBlock('Second', '<p>Second</p>');
    $section = $this->createSection('eb_test_container', [
      [
        'uuid' => $first_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $first, 'First'),
      ],
      [
        'uuid' => $second_uuid,
        'region' => 'content',
        'weight' => 1,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $second, 'Second'),
      ],
    ]);
    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    // Reorder by bare UUID strings.
    $new_layout = [
      [
        'layout_id' => 'eb_test_container',
        'children' => [$second_uuid, $first_uuid],
      ],
    ];
    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);
    $this->assertFalse($result->hasErrors(), 'Expected no errors. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));

    $serialized = $this->layoutSerializer->serialize($entity);
    $this->assertSame([$second_uuid, $first_uuid], array_column($serialized[0]['children'], 'uuid'));
  }

  /**
   * Tests children on a non-container layout is refused loudly.
   */
  public function testChildrenRefusedForMultiRegionLayout(): void {
    $entity = $this->createTestEntity();

    $new_layout = [
      [
        'layout_id' => 'layout_onecol',
        'children' => [],
      ],
    ];
    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);
    $this->assertTrue($result->hasErrors());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_CHILDREN', $error->getCode());
    $this->assertSame('layout_builder__layout[0].children', $error->getPath());
  }

  /**
   * Tests a node carrying both children and regions is refused.
   */
  public function testBothShapesRefused(): void {
    $entity = $this->createTestEntity();

    $new_layout = [
      [
        'layout_id' => 'eb_test_container',
        'children' => [],
        'regions' => ['content' => []],
      ],
    ];
    $result = $this->layoutDeserializer->deserialize($new_layout, $entity);
    $this->assertTrue($result->hasErrors());
    $this->assertSame('INVALID_CHILDREN', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests structural validation of the children shape.
   */
  public function testStructuralValidation(): void {
    $validator = $this->container->get('daedalus_blueprint.structural_validator');
    $base = ['entity_type' => 'entity_test', 'bundle' => 'entity_test'];

    // A valid container node: block and nested container children validate
    // through the one component grammar.
    $errors = $validator->validate($base + [
      'layout_builder__layout' => [
        [
          'layout_id' => 'eb_test_container',
          'children' => [
            [
              'block_type' => 'text_section',
              'block_fields' => ['body' => ['value' => '<p>Ok</p>', 'format' => 'plain_text']],
            ],
            ['layout_id' => 'eb_test_container', 'children' => []],
          ],
        ],
      ],
    ]);
    $this->assertSame([], $errors);

    // An invalid block type inside children carries the children path.
    $errors = $validator->validate($base + [
      'layout_builder__layout' => [
        [
          'layout_id' => 'eb_test_container',
          'children' => [
            ['block_type' => 'nonexistent_type', 'block_fields' => []],
          ],
        ],
      ],
    ]);
    $this->assertCount(1, $errors);
    $this->assertSame('layout_builder__layout[0].children[0].block_type', $errors[0]->getPath());

    // Children on a multi-region layout is refused with the legal regions.
    $errors = $validator->validate($base + [
      'layout_builder__layout' => [
        ['layout_id' => 'layout_twocol_section', 'children' => []],
      ],
    ]);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_CHILDREN', $errors[0]->getCode());
    $this->assertSame(['first', 'second'], $errors[0]->getContext()['available']);

    // Both shapes on one node is ambiguous.
    $errors = $validator->validate($base + [
      'layout_builder__layout' => [
        [
          'layout_id' => 'eb_test_container',
          'children' => [],
          'regions' => ['content' => []],
        ],
      ],
    ]);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_CHILDREN', $errors[0]->getCode());

    // A children map (not a list) is refused.
    $errors = $validator->validate($base + [
      'layout_builder__layout' => [
        ['layout_id' => 'eb_test_container', 'children' => ['content' => []]],
      ],
    ]);
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_CHILDREN', $errors[0]->getCode());
  }

  /**
   * Tests the schema advertises the container fact and the children key.
   */
  public function testSchemaAdvertisesContainer(): void {
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    $lb = $schema['layout_builder__layout'];
    $this->assertArrayHasKey('children', $lb['section_structure']);

    $by_id = array_column($lb['available_layouts'], NULL, 'id');
    $this->assertTrue($by_id['eb_test_container']['container']);
    $this->assertArrayNotHasKey('container', $by_id['layout_onecol']);

    // A container has no addressable regions, so its entry carries none of
    // the region vocabulary a multi-region layout advertises.
    foreach (['regions', 'default_region', 'columns', 'arrangement'] as $key) {
      $this->assertArrayNotHasKey($key, $by_id['eb_test_container']);
      $this->assertArrayHasKey($key, $by_id['layout_onecol']);
    }
  }

}
