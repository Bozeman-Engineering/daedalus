<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the legacy layout-block stencil grammar migration.
 *
 * Content stencils saved under the pre-D grammar carried block nodes with a
 * `layout` list; the current grammar refuses those loudly at instantiate.
 * The post update converts stored elements to section nodes in place.
 *
 * @group daedalus_blueprint
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilLegacyGrammarMigrationTest extends KernelTestBase {

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
    'daedalus_blueprint',
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
   * Legacy layout-block nodes convert to section nodes at any depth.
   */
  public function testLegacyElementsConvert(): void {
    // A layout-block ROOT whose layout holds one section, which itself
    // contains a nested legacy layout block beside a plain text block. The
    // wrapper's label and styles migrate onto the resulting section node.
    $legacy = $this->createStencil('Legacy card', [
      'block_type' => 'layout_block',
      'block_fields' => ['info' => 'Card row'],
      'styles' => ['default' => ['gap' => '2rem']],
      'layout' => [
        [
          'layout_id' => 'layout_twocol_section',
          'styles' => ['region:first' => ['default' => ['display' => 'flex']]],
          'regions' => [
            'first' => [
              ['block_type' => 'text', 'block_fields' => ['label' => 'Hello']],
              [
                'block_type' => 'layout_block',
                'layout' => [
                  ['layout_id' => 'layout_onecol', 'regions' => ['content' => [['block_type' => 'text']]]],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);

    // A multi-section legacy root wraps in a one-column section.
    $multi = $this->createStencil('Legacy stack', [
      'block_type' => 'layout_block',
      'layout' => [
        ['layout_id' => 'layout_onecol'],
        ['layout_id' => 'layout_onecol'],
      ],
    ]);

    // An already-current stencil is untouched (identical stored value).
    $current_element = [
      'layout_id' => 'layout_onecol',
      'label' => 'Modern',
      'regions' => ['content' => [['block_type' => 'text']]],
    ];
    $current = $this->createStencil('Modern', $current_element);

    $message = daedalus_blueprint_stencil_post_update_migrate_layout_block_nodes();
    $this->assertStringContainsString('Converted 2 of 3 stencils', $message);

    $storage = $this->container->get('entity_type.manager')->getStorage('stencil');

    /** @var \Drupal\daedalus_blueprint_stencil\Entity\Stencil $legacy */
    $legacy = $storage->loadUnchanged($legacy->id());
    $element = $legacy->getElement();
    $this->assertSame('layout_twocol_section', $element['layout_id']);
    $this->assertArrayNotHasKey('layout', $element);
    $this->assertArrayNotHasKey('block_type', $element);
    $this->assertSame('Card row', $element['label'], 'The wrapper label moved to the section node.');
    $this->assertEquals([
      'region:first' => ['default' => ['display' => 'flex']],
      'default' => ['gap' => '2rem'],
    ], $element['styles'], 'The wrapper styles merged onto the section node.');
    $first_region = $element['regions']['first'];
    $this->assertSame('text', $first_region[0]['block_type']);
    $this->assertSame('layout_onecol', $first_region[1]['layout_id'], 'The nested legacy block became a section node in place.');
    $this->assertSame('text', $first_region[1]['regions']['content'][0]['block_type']);

    /** @var \Drupal\daedalus_blueprint_stencil\Entity\Stencil $multi */
    $multi = $storage->loadUnchanged($multi->id());
    $element = $multi->getElement();
    $this->assertSame('layout_onecol', $element['layout_id']);
    $this->assertCount(2, $element['regions']['content'], 'Both sections nested under the one-column wrapper.');

    /** @var \Drupal\daedalus_blueprint_stencil\Entity\Stencil $current */
    $current = $storage->loadUnchanged($current->id());
    $this->assertSame($current_element, $current->getElement());
  }

}
