<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\daedalus_blueprint_stencil\Exception\StencilExportException;
use Drupal\daedalus_blueprint_stencil\StencilExporter;
use Drupal\daedalus_blueprint_stencil\StencilExporterInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests exporting a placed element as a stencil definition.
 *
 * The layout under test is built through the normal batch operations (the
 * same spine a UI or AI build rides), styles are written to the host's
 * daedalus_stylish_styles map (the map-host rule: every element's styles live on the
 * ROOT entity), and the exporter walks it all back into the definition
 * grammar — capture over section VALUES: the root is a section, nested
 * sections in regions export as section nodes, media/entity references are
 * PRESERVED. daedalus_stylish is enabled for its field type only; no style
 * operations run, so the styling validation stack stays out of the test.
 */
#[CoversClass(StencilExporter::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilExportTest extends StencilKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Merged with the base class's list by the kernel test bootstrapper.
   */
  protected static $modules = ['daedalus_stylish'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createReferenceFields();
    $this->createStyleMapField();
  }

  /**
   * The stencil exporter service.
   */
  protected function exporter(): StencilExporterInterface {
    return \Drupal::service('daedalus_blueprint_stencil.exporter');
  }

  /**
   * Tests the full export: structure, styles, labels, preserved references.
   */
  public function testExport(): void {
    ['entity' => $entity, 'section' => $section, 'text' => $text, 'inner' => $inner] = $this->buildReferenceSection();

    // The host's map styles the subtree plus elements outside it; only
    // subtree entries export, relativized onto their nodes by the tree's
    // local remainder — the FULL layered entries: variant references and
    // condition overrides alike, for the section, its blocks, their
    // fields, and the nested section (regions carry no keys under the
    // container model, so nothing region-keyed exists to export).
    // Structured references normalize into the grammar's string form: a
    // '' fragment is a root wearing and flattens, a distributed fragment
    // reference is derived data (re-created by the root's apply_variant
    // at instantiate) and drops — silently, even where the bare key
    // would otherwise warn as inexpressible.
    $entity->set('daedalus_stylish_styles', json_encode([
      "container:$section" => [
        'variants' => [['variant' => 'card', 'fragment' => '']],
        'default' => ['background-color' => 'aliceblue'],
      ],
      "block:$text" => [
        'variants' => [['variant' => 'card', 'fragment' => 'block(text,1)']],
        'default' => ['font-size' => '1.25rem'],
      ],
      "block:$text::.quote" => ['default' => ['padding' => '2rem']],
      "container:$inner" => ['variants' => [['variant' => 'panel', 'fragment' => '']]],
      "block:$text:field_heading" => ['default' => ['color' => 'tomato']],
      "block:$text:field_body" => [
        'variants' => [['variant' => 'card', 'fragment' => 'block(text,1)/field(field_body)']],
      ],
      'entity:' . $entity->uuid() => ['default' => ['margin' => '1rem']],
      'block:11111111-2222-3333-4444-555555555555' => [
        'variants' => [['variant' => 'elsewhere', 'fragment' => '']],
      ],
    ]));

    $export = $this->exporter()->export($entity, $section);

    $this->assertSame('Call to Action', $export->definition['label'], 'The label defaults to the section label.');
    $this->assertStringContainsString('entity_test 1', $export->definition['description']);

    $expected = $this->expectedReferenceElement();
    $expected['styles'] = [
      '' => ['variants' => ['card'], 'default' => ['background-color' => 'aliceblue']],
    ];
    $expected['children'][0]['children'][0]['styles'] = [
      '' => ['default' => ['font-size' => '1.25rem']],
      '::.quote' => ['default' => ['padding' => '2rem']],
      // A block field's entry rides the tree's ':{field}' local — the
      // key the pre-tree grammar could not express and warning-dropped.
      ':field_heading' => ['default' => ['color' => 'tomato']],
    ];
    $expected['children'][1]['children'][1]['styles'] = [
      '' => ['variants' => ['panel']],
    ];
    $this->assertEquals($expected, $export->definition['element']);

    // Nothing warned: every subtree key has a tree-local form now. The
    // field entry holding only a distributed fragment reference normalized
    // away entirely, so it does not ship either.
    $this->assertSame([], $export->warnings);
  }

  /**
   * A composition's nested wears are derived data and drop like fragments.
   *
   * The card scenario as the map records it after a composition apply:
   * the root wears "card_left", whose slot entries nested "row" onto the
   * wrapper and the text block. Distribution stamped those references with
   * their origin ("via": card_left) beside the composition's own fragment
   * references. Both shapes are what the root's apply_variant re-creates at
   * instantiate, so neither ships: the text block (nothing but the derived
   * wear) exports with no styles at all, and the wrapper keeps only its
   * authored "glow" (a '' reference with no origin) and its own override.
   * Shipping the nested wear as a bare name would have instantiate re-apply
   * it on the child AFTER the root's distribution, as an authored wear no
   * later switch of the root could reap.
   */
  public function testDerivedWearsDrop(): void {
    ['entity' => $entity, 'section' => $section, 'text' => $text, 'inner' => $inner] = $this->buildReferenceSection();

    $entity->set('daedalus_stylish_styles', json_encode([
      "container:$section" => [
        'variants' => [['variant' => 'card_left', 'fragment' => '']],
      ],
      "container:$inner" => [
        'variants' => [
          ['variant' => 'glow', 'fragment' => ''],
          ['variant' => 'row', 'fragment' => '', 'via' => 'card_left'],
          ['variant' => 'card_left', 'fragment' => 'container(1)'],
        ],
        'default' => ['margin' => '1px'],
      ],
      "block:$text" => [
        'variants' => [['variant' => 'row', 'fragment' => '', 'via' => 'card_left']],
      ],
    ]));

    $export = $this->exporter()->export($entity, $section);

    $expected = $this->expectedReferenceElement();
    $expected['styles'] = ['' => ['variants' => ['card_left']]];
    $expected['children'][1]['children'][1]['styles'] = [
      '' => ['variants' => ['glow'], 'default' => ['margin' => '1px']],
    ];
    $this->assertEquals($expected, $export->definition['element']);
    $this->assertArrayNotHasKey('styles', $export->definition['element']['children'][0]['children'][0], 'A child wearing only what the composition distributed ships no styles.');
    $this->assertSame([], $export->warnings, 'Derived wears drop silently: nothing inexpressible was lost.');
  }

  /**
   * Tests skeletonizing and the label/description options.
   */
  public function testStripValues(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $export = $this->exporter()->export($entity, $section, [
      'label' => 'Call to Action',
      'description' => 'Two-column card.',
      'strip_values' => TRUE,
    ]);

    $this->assertSame('Call to Action', $export->definition['label']);
    $this->assertSame('Two-column card.', $export->definition['description']);
    $this->assertEquals([
      'kind' => 'container',
      'layout_id' => 'layout_twocol_section',
      'label' => 'Call to Action',
      'layout_settings' => ['column_widths' => '33-67'],
      'children' => [
        [
          'kind' => 'region',
          'region' => 'first',
          'children' => [['kind' => 'block', 'block_type' => 'text']],
        ],
        [
          'kind' => 'region',
          'region' => 'second',
          'children' => [
            ['kind' => 'block', 'block_type' => 'basic'],
            [
              'kind' => 'container',
              'layout_id' => 'layout_onecol',
              'children' => [
                [
                  'kind' => 'region',
                  'region' => 'content',
                  'children' => [['kind' => 'block', 'block_type' => 'basic']],
                ],
              ],
            ],
          ],
        ],
      ],
    ], $export->definition['element'], 'Skeletonizing keeps structure, labels, and layout settings; drops every pinned value.');
  }

  /**
   * Tests the disk-shipping strategy: strip_references prunes references.
   */
  public function testStripReferences(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $export = $this->exporter()->export($entity, $section, ['strip_references' => TRUE]);

    $text_fields = $export->definition['element']['children'][0]['children'][0]['block_fields'];
    $this->assertArrayNotHasKey('field_ref', $text_fields, 'strip_references prunes reference fields: site-local target IDs never ship in files.');
    $this->assertSame('Pinned heading', $text_fields['field_heading'], 'Non-reference pins survive.');

    // The default capture PRESERVES references (the firm requirement).
    $kept = $this->exporter()->export($entity, $section);
    $this->assertSame((int) \Drupal::currentUser()->id(), $kept->definition['element']['children'][0]['children'][0]['block_fields']['field_ref']['target_id']);
  }

  /**
   * Tests capturing a top-level band by its section uuid.
   */
  public function testTopLevelBandExport(): void {
    ['entity' => $entity, 'root_section' => $root_section] = $this->buildReferenceSection();

    $export = $this->exporter()->export($entity, $root_section);

    // The band is the host's original empty onecol row; the CTA section was
    // added as a SIBLING band, so this one exports empty — the point is
    // that a top-level row captures by its section uuid at all.
    $this->assertSame('container', $export->definition['element']['kind']);
    $this->assertSame('layout_onecol', $export->definition['element']['layout_id']);
    $this->assertArrayNotHasKey('children', $export->definition['element']);
    $this->assertSame('One column', $export->definition['label'], 'An unlabeled section falls back to its layout name.');
  }

  /**
   * Tests capturing an inline block: block-rooted stencils stay supported.
   */
  public function testBlockRootExport(): void {
    ['entity' => $entity, 'text' => $text] = $this->buildReferenceSection();

    $export = $this->exporter()->export($entity, $text);

    $this->assertSame('My heading', $export->definition['label']);
    $this->assertSame('block', $export->definition['element']['kind']);
    $this->assertSame('text', $export->definition['element']['block_type']);
    $this->assertSame('Pinned heading', $export->definition['element']['block_fields']['field_heading']);
  }

  /**
   * Tests that a plugin block in the subtree fails the export loudly.
   */
  public function testPluginBlockFails(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'add_element',
        'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'second'],
        'plugin_id' => 'system_powered_by_block',
      ],
    ]);
    $this->assertBatchSuccess($result);

    $this->expectException(StencilExportException::class);
    $this->expectExceptionMessageMatches('/system_powered_by_block/');
    $this->exporter()->export($entity, $section);
  }

  /**
   * Tests that an unknown element fails the export loudly.
   */
  public function testUnknownElementFails(): void {
    ['entity' => $entity] = $this->createHost();

    $this->expectException(StencilExportException::class);
    $this->exporter()->export($entity, '11111111-2222-3333-4444-555555555555');
  }

}
