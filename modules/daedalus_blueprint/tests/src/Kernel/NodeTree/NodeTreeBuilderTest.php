<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\NodeTree;

use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the lean node tree — the spine's canonical page representation.
 *
 * The tree is derived from LayoutSerializer's output: kind-discriminated
 * container/region/block nodes with ONE child axis at every depth (no
 * regions map, no section_index, no view_mode/block_revision_id/
 * label_display residue) and the entity's flat style map distributed onto
 * the nodes its keys address. It is what the model-facing dialect prints
 * from and what the blueprint mirror delivers, so the shape is locked
 * here at the spine.
 */
#[CoversClass(NodeTreeBuilder::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class NodeTreeBuilderTest extends BlueprintKernelTestBase {

  /**
   * The layout serializer.
   */
  protected LayoutSerializer $layoutSerializer;

  /**
   * The node tree builder under test.
   */
  protected NodeTreeBuilder $builder;

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
    $this->builder = $this->container->get('daedalus_blueprint.node_tree_builder');
  }

  /**
   * Creates a saved text block.
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
   * Nested containers become one kind-discriminated tree, styles on node.
   */
  public function testNestedContainerTreeWithStyles(): void {
    $entity = $this->createTestEntity();
    $root_uuid = 'aaaaaaaa-1111-4444-8888-000000000000';
    $block_uuid = 'aaaaaaaa-1111-4444-8888-000000000001';
    $nested_uuid = 'aaaaaaaa-1111-4444-8888-000000000002';
    $inner_uuid = 'aaaaaaaa-1111-4444-8888-000000000003';

    $inner = $this->createTextBlock('Inner', '<p>Inner</p>');
    $nested = $this->createSection('eb_test_container', [
      [
        'uuid' => $inner_uuid,
        'region' => 'content',
        'configuration' => $this->createInlineBlockConfiguration('text_section', $inner, 'Inner'),
      ],
    ], ['label' => 'Nested band']);
    $nested->setThirdPartySetting('daedalus_layout', 'uuid', $nested_uuid);

    $block = $this->createTextBlock('First', '<p>First</p>');
    $section = $this->createSection('eb_test_container', [
      [
        'uuid' => $block_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'First'),
      ],
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $root_uuid);
    $section->appendComponent(SectionBlock::createComponent($nested, 'content', 1));

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $style_map = [
      "container:$root_uuid" => [
        'default' => ['padding-block' => 'var(--space-16)'],
        'hover' => ['background-color' => '#eee'],
      ],
      "container:$root_uuid::> *" => ['default' => ['gap' => '1rem']],
      "block:$block_uuid" => [
        'default' => ['text-align' => 'center'],
        'variants' => [['variant' => 'button', 'fragment' => '']],
      ],
      "block:$block_uuid::.text-block-link" => ['default' => ['color' => 'red']],
      "block:$block_uuid:body" => ['narrow' => ['font-size' => 'var(--text-sm)']],
      // Root-entity and root-field keys have no layout node: they stay in
      // the flat map only, never on the tree.
      'entity:99999999-9999-4999-8999-999999999999' => ['default' => ['margin' => '0']],
    ];

    $tree = $this->builder->fromSections($this->layoutSerializer->serialize($entity), $style_map);

    // One top-level container node, kind-discriminated, no residue.
    $this->assertCount(1, $tree);
    $container = $tree[0];
    $this->assertSame('container', $container['kind']);
    $this->assertSame($root_uuid, $container['uuid']);
    $this->assertSame('eb_test_container', $container['layout_id']);
    $this->assertArrayNotHasKey('regions', $container);
    $this->assertArrayNotHasKey('section_index', $container);

    // Styles landed on the node, keyed by the local remainder: '' is the
    // element's own entry, '::> *' the children entry — the flat map's
    // grammar, minus the node identity.
    $this->assertSame([
      '' => $style_map["container:$root_uuid"],
      '::> *' => $style_map["container:$root_uuid::> *"],
    ], $container['styles']);

    // Ordered flow: the block, then the nested container — one child axis.
    $this->assertSame(['block', 'container'], array_column($container['children'], 'kind'));

    $block_node = $container['children'][0];
    $this->assertSame($block_uuid, $block_node['uuid']);
    $this->assertSame('text_section', $block_node['block_type']);
    $this->assertSame('First', $block_node['label']);
    $this->assertTrue($block_node['label_rendered']);
    $this->assertSame('<p>First</p>', $block_node['block_fields']['body']['value']);
    foreach (['label_display', 'view_mode', 'block_revision_id', 'regions'] as $residue) {
      $this->assertArrayNotHasKey($residue, $block_node);
    }
    $this->assertSame([
      '' => $style_map["block:$block_uuid"],
      '::.text-block-link' => $style_map["block:$block_uuid::.text-block-link"],
      ':body' => $style_map["block:$block_uuid:body"],
    ], $block_node['styles']);

    // The nested container recurses with the same node shape.
    $nested_node = $container['children'][1];
    $this->assertSame('container', $nested_node['kind']);
    $this->assertSame($nested_uuid, $nested_node['uuid']);
    $this->assertSame('Nested band', $nested_node['label']);
    $this->assertArrayNotHasKey('styles', $nested_node);
    $this->assertSame(['block'], array_column($nested_node['children'], 'kind'));
    $this->assertSame($inner_uuid, $nested_node['children'][0]['uuid']);
  }

  /**
   * A multi-region layout's named slots become region nodes, never a map.
   */
  public function testMultiRegionSectionsBecomeRegionNodes(): void {
    $entity = $this->createTestEntity();
    $block_uuid = 'bbbbbbbb-2222-4444-8888-000000000001';

    $block = $this->createTextBlock('Sidebar', '<p>Sidebar</p>');
    $section = $this->createSection('layout_twocol', [
      [
        'uuid' => $block_uuid,
        'region' => 'first',
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Sidebar'),
      ],
    ]);

    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $tree = $this->builder->fromSections($this->layoutSerializer->serialize($entity));

    $container = $tree[0];
    $this->assertSame('container', $container['kind']);
    $this->assertArrayNotHasKey('regions', $container);

    // Every child is a region node — real named slots as tree structure,
    // empty ones included (they are still drop targets), one child axis.
    $regions = [];
    foreach ($container['children'] as $child) {
      $this->assertSame('region', $child['kind']);
      $regions[$child['region']] = array_column($child['children'], 'uuid');
    }
    $this->assertSame([$block_uuid], $regions['first']);
    $this->assertContains('second', array_keys($regions));
    $this->assertSame([], $regions['second']);
  }

  /**
   * Build() serializes the entity itself; no style field means no styles.
   */
  public function testBuildFromEntityWithoutStyleField(): void {
    $entity = $this->createTestEntity();
    $block = $this->createTextBlock('Solo', '<p>Solo</p>');
    $section = $this->createSection('eb_test_container', [
      [
        'uuid' => 'cccccccc-3333-4444-8888-000000000001',
        'region' => 'content',
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Solo'),
      ],
    ]);
    $entity->get('layout_builder__layout')->appendSection($section);
    $entity->save();
    $entity = $this->entityTypeManager->getStorage('entity_test')->load($entity->id());

    $this->assertSame([], $this->builder->styleMap($entity));

    $tree = $this->builder->build($entity);
    $this->assertSame('container', $tree[0]['kind']);
    $this->assertSame('cccccccc-3333-4444-8888-000000000001', $tree[0]['children'][0]['uuid']);
    $this->assertArrayNotHasKey('styles', $tree[0]);
    $this->assertArrayNotHasKey('styles', $tree[0]['children'][0]);
  }

}
