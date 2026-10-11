<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_blueprint_library\BlueprintOperationHandler;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A blueprint placement is a total statement against daedalus_stylish's birth defaults.
 *
 * The placement expansion runs as a DRESSED nested batch, so the styling
 * layer's birth subscriber stamps nothing under it: the placed root wears
 * exactly the variants the blueprint's document wears (no house default
 * beneath them), and its sampled blocks wear none even when their block
 * type declares default_variants. The same adds through the op door (a
 * plain add_section, a plain add_element) are born wearing those defaults,
 * which proves the fixtures would have stamped had the batch been
 * undressed.
 */
#[CoversClass(BlueprintOperationHandler::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DressedBlueprintInstantiateTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    // layout_onecol declares {style: onecol_default, width: onecol_wide,
    // flow: onecol_row (root only)}: three satisfiable section defaults.
    'daedalus_stylish_section_defaults_test',
    // The testimonial block type declares {style: testimonial_default,
    // emphasis: testimonial_emphatic}: a satisfiable block default.
    'daedalus_stylish_guide_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    // Variant references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->createBlockContentType('testimonial');

    // Every declared default is satisfiable, so a stamp WOULD land on an
    // undressed creation.
    $this->createVariant('onecol_default', 'layout:layout_onecol');
    $this->createVariant('onecol_wide', 'layout:layout_onecol', 'width');
    $this->createVariant('onecol_row', 'layout:layout_onecol', 'flow');
    $this->createVariant('testimonial_default', 'block_type:testimonial');
    // The look the blueprint's root wears.
    $this->createVariant('card_look', 'kind:container');
  }

  /**
   * Creates a published variant.
   */
  protected function createVariant(string $machine_name, string $scope, ?string $axis = NULL): DaedalusStylishVariant {
    $variant = DaedalusStylishVariant::create([
      'label' => $machine_name,
      'machine_name' => $machine_name,
      'scope' => $scope,
      'styles' => ['' => ['default' => ['padding' => '1rem']]],
    ] + ($axis === NULL ? [] : ['axis' => $axis]));
    $variant->save();
    return $variant;
  }

  /**
   * Reads the host's style map back.
   */
  protected function styleMap($entity): array {
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item ? $item->getStyleMap() : [];
  }

  /**
   * The placed root wears exactly its blueprint's wearing; sampled blocks none.
   */
  public function testInstantiatedRootWearsExactlyItsCapture(): void {
    // The blueprint's own document is a dressed statement too: its root
    // wears card_look alone, its block nothing.
    $this->createBlueprintFromTree([
      'label' => 'Dressed band',
      'machine_name' => 'dressed_band',
      'description' => 'A onecol band wearing card_look over a sampled testimonial.',
    ], [
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'styles' => ['' => ['variants' => ['card_look']]],
        'children' => [
          [
            'kind' => 'region',
            'region' => 'content',
            'children' => [
              ['kind' => 'block', 'block_type' => 'testimonial'],
            ],
          ],
        ],
      ],
    ]);

    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $root = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'dressed_band',
        'uuid' => $root,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    // The root wears exactly the captured variant: no onecol_default,
    // onecol_wide or onecol_row stamped beneath it.
    $map = $this->styleMap($entity);
    $this->assertSame(['variants' => [['variant' => 'card_look', 'fragment' => '']]], $map["container:$root"] ?? NULL);

    // The sampled block wears none: no testimonial_default stamped.
    $placed = $entity->get('layout_builder__layout')->getSection(1);
    $components = array_values($placed->getComponentsByRegion('content'));
    $this->assertCount(1, $components);
    $block_uuid = $components[0]->getUuid();
    $this->assertArrayNotHasKey("block:$block_uuid", $map);
    $this->assertSame(["container:$root"], array_keys($map), 'The placement wrote exactly one map entry: the captured root wearing.');

    // The op door control: the same adds undressed are born wearing the
    // declared defaults, so the fixtures would have stamped.
    $plain_section = \Drupal::service('uuid')->generate();
    $plain_block = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'add_section',
        'uuid' => $plain_section,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'block_type' => 'testimonial',
        'uuid' => $plain_block,
        'populate' => 'sample',
        'location' => ['anchor' => $root_section, 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);
    $map = $this->styleMap($entity);
    $this->assertSame(
      [
        'variants' => [
          ['variant' => 'onecol_default', 'fragment' => ''],
          ['variant' => 'onecol_wide', 'fragment' => ''],
          ['variant' => 'onecol_row', 'fragment' => ''],
        ],
      ],
      $map["container:$plain_section"] ?? NULL,
    );
    $this->assertSame(['variants' => [['variant' => 'testimonial_default', 'fragment' => '']]], $map["block:$plain_block"] ?? NULL);
  }

  /**
   * A nested placement is dressed the same way: no default at any depth.
   */
  public function testNestedInstantiateWearsExactlyItsCapture(): void {
    $this->createBlueprintFromTree([
      'label' => 'Dressed card',
      'machine_name' => 'dressed_card',
      'description' => 'A bare onecol container.',
    ], [
      ['kind' => 'container', 'layout_id' => 'layout_onecol'],
    ]);

    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();
    $root = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'dressed_card',
        'uuid' => $root,
        'location' => ['anchor' => $root_section, 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    // A blueprint wearing nothing places wearing none: the nested
    // onecol_default the op door would stamp is absent.
    $this->assertSame([], $this->styleMap($entity));
  }

}
