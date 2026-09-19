<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the node-tree stencil grammar migration.
 *
 * Content stencils saved under the pre-tree LB-shaped grammar (kind-less
 * section nodes with a `regions` map, kind-less block nodes) stopped
 * instantiating when stencils moved onto the spine's lean node tree; the
 * post update converts stored elements in place: kind discriminators, one
 * children axis, region nodes only for multi-region layouts (a container
 * layout's sole region flattens into direct children).
 *
 * @group daedalus_blueprint
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilNodeTreeMigrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The stencil entity's icon field references media, so installing the
    // stencil schema needs the media entity type (and its field types).
    'field',
    'file',
    'image',
    'media',
    // Layout plugins: the conversion consults each layout's definition to
    // decide direct children (container layouts, eb_test_container) vs
    // region nodes (everything else).
    'layout_discovery',
    'daedalus_blueprint',
    'daedalus_blueprint_test',
    'daedalus_blueprint_stencil',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('stencil');
    require_once $this->container->get('extension.list.module')->getPath('daedalus_blueprint_stencil') . '/daedalus_blueprint_stencil.post_update.php';
  }

  /**
   * Creates and saves a stencil with the given element.
   */
  protected function createStencil(string $label, array $element): Stencil {
    $stencil = Stencil::create(['label' => $label, 'description' => 'Test stencil.']);
    $stencil->setElement($element);
    $stencil->save();
    return $stencil;
  }

  /**
   * LB-shaped elements convert to lean tree nodes at any depth.
   */
  public function testElementsConvert(): void {
    // A multi-region root holding a block and a nested CONTAINER-layout
    // section (whose sole region must flatten into direct children), with
    // styles and pinned fields riding along untouched.
    $legacy = $this->createStencil('Legacy card', [
      'layout_id' => 'layout_twocol_section',
      'label' => 'Card',
      'layout_settings' => ['column_widths' => '33-67'],
      'styles' => ['' => ['default' => ['gap' => '2rem']]],
      'regions' => [
        'first' => [
          [
            'block_type' => 'text',
            'block_fields' => ['label' => 'Hello'],
            'styles' => ['::.inner' => ['default' => ['padding' => '1rem']]],
          ],
        ],
        'second' => [
          [
            'layout_id' => 'eb_test_container',
            'regions' => ['content' => [['block_type' => 'basic']]],
          ],
        ],
      ],
    ]);

    // A block root converts by gaining its kind.
    $block = $this->createStencil('Legacy block', [
      'block_type' => 'basic',
      'styles' => ['' => ['default' => ['margin' => '1rem']]],
    ]);

    // An already-tree stencil is untouched (identical stored value).
    $current_element = [
      'kind' => 'container',
      'layout_id' => 'eb_test_container',
      'label' => 'Modern',
      'children' => [['kind' => 'block', 'block_type' => 'text']],
    ];
    $current = $this->createStencil('Modern', $current_element);

    $message = daedalus_blueprint_stencil_post_update_node_tree_elements();
    $this->assertStringContainsString('Converted 2 of 3 stencils', $message);

    $storage = $this->container->get('entity_type.manager')->getStorage('stencil');

    /** @var \Drupal\daedalus_blueprint_stencil\Entity\Stencil $legacy */
    $legacy = $storage->loadUnchanged($legacy->id());
    $this->assertEquals([
      'kind' => 'container',
      'layout_id' => 'layout_twocol_section',
      'label' => 'Card',
      'layout_settings' => ['column_widths' => '33-67'],
      'styles' => ['' => ['default' => ['gap' => '2rem']]],
      'children' => [
        [
          'kind' => 'region',
          'region' => 'first',
          'children' => [
            [
              'kind' => 'block',
              'block_type' => 'text',
              'block_fields' => ['label' => 'Hello'],
              'styles' => ['::.inner' => ['default' => ['padding' => '1rem']]],
            ],
          ],
        ],
        [
          'kind' => 'region',
          'region' => 'second',
          'children' => [
            [
              'kind' => 'container',
              'layout_id' => 'eb_test_container',
              // The container layout's sole region is a storage vestige:
              // its flow flattened into direct children, no region node.
              'children' => [['kind' => 'block', 'block_type' => 'basic']],
            ],
          ],
        ],
      ],
    ], $legacy->getElement());

    /** @var \Drupal\daedalus_blueprint_stencil\Entity\Stencil $block */
    $block = $storage->loadUnchanged($block->id());
    $this->assertEquals([
      'kind' => 'block',
      'block_type' => 'basic',
      'styles' => ['' => ['default' => ['margin' => '1rem']]],
    ], $block->getElement());

    /** @var \Drupal\daedalus_blueprint_stencil\Entity\Stencil $current */
    $current = $storage->loadUnchanged($current->id());
    $this->assertSame($current_element, $current->getElement());
  }

}
