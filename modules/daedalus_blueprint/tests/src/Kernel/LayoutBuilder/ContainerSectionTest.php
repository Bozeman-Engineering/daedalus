<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\LayoutBuilder;

use Drupal\daedalus_blueprint\BlueprintDeserializer;
use Drupal\daedalus_blueprint\LayoutBuilder\ContainerLayout;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the children-list shape of container sections.
 *
 * A container layout (`container: true`, exactly one region — see
 * ContainerLayout) has a vestigial storage region, so its section nodes
 * serialize one ordered `children` list instead of the `regions` map, at
 * every depth. Multi-region sections keep the regions shape on the read.
 * On the write side the tree is the one grammar: a container node's
 * children are element nodes directly (its flow) and a multi-region
 * container's children are region nodes; the stored regions spelling is
 * refused with the tree grammar named.
 */
#[CoversClass(ContainerLayout::class)]
#[CoversClass(LayoutSerializer::class)]
#[CoversClass(BlueprintDeserializer::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class ContainerSectionTest extends BlueprintKernelTestBase {

  /**
   * The layout serializer.
   */
  protected LayoutSerializer $layoutSerializer;

  /**
   * The blueprint deserializer.
   */
  protected BlueprintDeserializer $deserializer;

  /**
   * The lean node tree builder: the read of the tree.
   */
  protected NodeTreeBuilder $treeBuilder;

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
    $this->deserializer = $this->container->get('daedalus_blueprint.deserializer');
    $this->treeBuilder = $this->container->get('daedalus_blueprint.node_tree_builder');
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
      'fields' => ['name' => 'Container'],
      'layout' => $layout,
    ]);
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

    $result = $this->layoutSerializer->serialize($this->host($entity));

    $this->assertSame('eb_test_container', $result[0]['layout_id']);
    $this->assertArrayNotHasKey('regions', $result[0]);
    $this->assertSame(
      ['aaaaaaaa-1111-4444-8888-000000000001', 'aaaaaaaa-1111-4444-8888-000000000002'],
      array_column($result[0]['children'], 'uuid'),
    );
    $this->assertSame('<p>First</p>', $result[0]['children'][0]['block_fields']['body']['value']);

    // The summary path emits the same shape.
    $summary = $this->layoutSerializer->serializeSummary($this->host($entity));
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

    $result = $this->layoutSerializer->serialize($this->host($entity));

    // The multi-region-capable parent holds its slot as a region node; the
    // container layouts inside it hold their children directly.
    $this->assertSame('region', $result[0]['children'][0]['kind']);
    $node = $result[0]['children'][0]['children'][0];
    $this->assertSame('container', $node['kind']);
    $this->assertSame('eb_test_container', $node['layout_id']);
    $this->assertSame($nested_uuid, $node['uuid']);
    $this->assertSame('block', $node['children'][0]['kind']);
    $this->assertSame($block_uuid, $node['children'][0]['uuid']);

    $empty_node = $result[0]['children'][0]['children'][1];
    $this->assertSame([], $empty_node['children']);
  }

  /**
   * Tests a container node's children land in the vestigial storage region.
   */
  public function testCreateWithContainerChildren(): void {
    $nested_uuid = 'cccccccc-3333-4444-8888-000000000001';
    $block_uuid = 'cccccccc-3333-4444-8888-000000000002';

    $result = $this->create([
      [
        'kind' => 'container',
        'layout_id' => 'eb_test_container',
        'uuid' => 'cccccccc-3333-4444-8888-000000000000',
        'children' => [
          [
            'kind' => 'block',
            'uuid' => $block_uuid,
            'block_type' => 'text_section',
            'label' => 'Text',
            'block_fields' => [
              'body' => ['value' => '<p>Hello</p>', 'format' => 'plain_text'],
            ],
          ],
          [
            'kind' => 'container',
            'layout_id' => 'eb_test_container',
            'uuid' => $nested_uuid,
            'label' => 'Inner',
            'children' => [],
          ],
        ],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), 'Expected no errors. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));
    $entity = $result->getEntity();

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

    // The read of the created entity answers children, and that tree
    // creates another equal entity.
    $serialized = $this->layoutSerializer->serialize($this->host($entity));
    $this->assertArrayNotHasKey('regions', $serialized[0]);
    $this->assertSame([$block_uuid, $nested_uuid], array_column($serialized[0]['children'], 'uuid'));
    $tree = $this->treeBuilder->build($this->host($entity));
    $this->assertSame([$block_uuid, $nested_uuid], array_column($tree[0]['children'], 'uuid'));
    $result = $this->create($tree);
    $this->assertTrue($result->isSuccess(), 'Round trip should succeed. Got: ' . json_encode(array_map(fn($e) => $e->toArray(), $result->getErrors())));
    $this->assertSame([$block_uuid, $nested_uuid], array_keys($result->getEntity()->get('layout_builder__layout')->getSections()[0]->getComponents()));
  }

  /**
   * Tests the stored regions spelling is refused with the tree grammar.
   *
   * A container node has one children axis; the regions map is storage's
   * (and the read's until the flip), never the write grammar's, with or
   * without a children list beside it.
   */
  public function testRegionsSpellingRefused(): void {
    $regions = ['content' => [['kind' => 'block', 'block_type' => 'text_section']]];
    foreach ([
      ['kind' => 'container', 'layout_id' => 'eb_test_container', 'regions' => $regions],
      ['kind' => 'container', 'layout_id' => 'eb_test_container', 'children' => [], 'regions' => $regions],
    ] as $node) {
      $result = $this->create([$node]);
      $this->assertFalse($result->isSuccess());
      $error = $result->getErrors()[0];
      $this->assertSame('INVALID_NODE', $error->getCode());
      $this->assertSame('layout[0]', $error->getPath());
      $this->assertStringContainsString('"regions"', $error->getMessage());
      $this->assertStringContainsString('{kind: region, region, children}', $error->getHint());
    }
  }

  /**
   * Tests the one children axis: regions and elements never mix.
   *
   * A multi-region container's children are region nodes naming slots the
   * layout defines; element nodes beside them, or a slot the layout lacks,
   * are grammar errors on the tree's coordinates.
   */
  public function testChildAxisRefusals(): void {
    $result = $this->create([
      [
        'kind' => 'container',
        'layout_id' => 'layout_twocol_section',
        'children' => [
          ['kind' => 'region', 'region' => 'first', 'children' => []],
          ['kind' => 'block', 'block_type' => 'text_section'],
        ],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $codes = array_map(fn ($error) => [$error->getCode(), $error->getPath()], $result->getErrors());
    $this->assertContains(['INVALID_NODE', 'layout[0].children'], $codes);

    $result = $this->create([
      [
        'kind' => 'container',
        'layout_id' => 'layout_twocol_section',
        'children' => [
          ['kind' => 'region', 'region' => 'third', 'children' => []],
        ],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_NODE', $error->getCode());
    $this->assertSame('layout[0].children[0].region', $error->getPath());
    $this->assertSame(['first', 'second'], $error->getContext()['available']);

    // A children map (not a list) is refused.
    $result = $this->create([
      ['kind' => 'container', 'layout_id' => 'eb_test_container', 'children' => ['content' => []]],
    ]);
    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_NODE', $error->getCode());
    $this->assertSame('layout[0].children', $error->getPath());
  }

  /**
   * Tests the ops' own verdicts ride the children path.
   */
  public function testOperationVerdictsOnChildren(): void {
    $result = $this->create([
      [
        'kind' => 'container',
        'layout_id' => 'eb_test_container',
        'children' => [
          ['kind' => 'block', 'block_type' => 'nonexistent_type', 'block_fields' => []],
        ],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertCount(1, $result->getErrors());
    $this->assertSame('INVALID_BLOCK_TYPE', $result->getErrors()[0]->getCode());
    $this->assertSame('layout[0].children[0].block_type', $result->getErrors()[0]->getPath());
  }

  /**
   * Tests the schema advertises the container fact and the children key.
   */
  public function testSchemaAdvertisesContainer(): void {
    $schema_builder = $this->container->get('daedalus_blueprint.schema_builder');
    $schema = $schema_builder->getSchema('entity_test', 'entity_test');

    $lb = $schema['layout'];
    $this->assertArrayHasKey('children', $lb['section_structure']);
    $this->assertArrayNotHasKey('regions', $lb['section_structure']);
    $this->assertStringContainsString('"kind": "region"', $lb['section_structure']['children']);

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
