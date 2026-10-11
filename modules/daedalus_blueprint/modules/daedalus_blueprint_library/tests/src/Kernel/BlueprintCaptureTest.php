<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_blueprint_library\BlueprintManager;
use Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests capturing a placed container as a blueprint, on the manager door.
 *
 * The layout under test is built through the normal batch operations (the
 * same spine a UI or AI build rides), styles are written to the host's
 * style map (the map-host rule: every element's styles live on the ROOT
 * entity), and the manager captures the subtree into a new blueprint
 * whose document is read back through the one tree builder: capture over
 * section VALUES (the root is a section, nested sections in regions
 * travel as container nodes, media and entity references are PRESERVED),
 * styles on the nodes, derived wears dropped, and the two reductions the
 * capture offers (skeletonizing, the whole page).
 */
#[CoversClass(BlueprintManager::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintCaptureTest extends BlueprintKernelTestBase {

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
    $this->installEntitySchema('daedalus_variant');
    // Variant references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->createReferenceFields();

    // The looks the source map references: the capture re-applies them on
    // the blueprint through the real apply_variant handler, so they exist
    // and match the wearers' scopes.
    foreach (['card', 'panel', 'glow', 'card_left'] as $machine_name) {
      DaedalusStylishVariant::create([
        'label' => ucfirst($machine_name),
        'machine_name' => $machine_name,
        'scope' => 'kind:container',
        'styles' => ['' => ['default' => ['padding' => '1rem']]],
      ])->save();
    }
  }

  /**
   * The blueprint manager service.
   */
  protected function manager(): BlueprintManager {
    return \Drupal::service('daedalus_blueprint_library.manager');
  }

  /**
   * Tests the full capture: structure, styles, labels, preserved references.
   */
  public function testCapture(): void {
    ['entity' => $entity, 'section' => $section, 'text' => $text, 'inner' => $inner] = $this->buildReferenceSection();

    // The host's map styles the subtree plus elements outside it; only the
    // subtree's entries travel, relativized onto their nodes by the tree's
    // local remainder: the section's variant and override, a block field's
    // entry, the nested section's variant. A distributed fragment
    // reference is derived data (re-created by the root's apply_variant
    // at placement) and drops.
    $entity->set('daedalus_stylish_styles', json_encode([
      "container:$section" => [
        'variants' => [['variant' => 'card', 'fragment' => '']],
        'default' => ['background-color' => 'aliceblue'],
      ],
      "block:$text" => [
        'variants' => [['variant' => 'card', 'fragment' => 'block(text,1)']],
        'default' => ['font-size' => '1.25rem'],
      ],
      "container:$inner" => ['variants' => [['variant' => 'panel', 'fragment' => '']]],
      "block:$text:field_heading" => ['default' => ['color' => 'tomato']],
      'entity:' . $entity->uuid() => ['default' => ['margin' => '1rem']],
      'block:11111111-2222-3333-4444-555555555555' => [
        'variants' => [['variant' => 'glow', 'fragment' => '']],
      ],
    ]));

    $result = $this->manager()->saveAsBlueprint($this->host($entity), $section, 'Call to Action', 'Two-column card.');
    $this->assertSame([], $result->warnings);
    $blueprint = $result->blueprint;
    $this->assertSame('call_to_action', $blueprint->getMachineName());
    $this->assertSame('block', $blueprint->getScope());
    $this->assertSame(['entity_test', 'entity_test'], [$blueprint->getSubjectType(), $blueprint->getSubjectBundle()]);

    // The document equals the captured subtree, identity aside: structure,
    // the PRESERVED reference, the display label, the layout settings.
    $root = $this->definitionOf($blueprint)[0];
    $this->assertSame('container', $root['kind']);
    $this->assertSame('layout_twocol_section', $root['layout_id']);
    $this->assertSame('Call to Action', $root['label']);
    // Layout settings travel as the tree carries them: the managed keys of
    // the layout (none registered for core's two-column layout on this
    // site), never every stored key.
    $this->assertArrayNotHasKey('layout_settings', $root);
    $text_node = $root['children'][0]['children'][0];
    $this->assertSame('text', $text_node['block_type']);
    $this->assertSame('My heading', $text_node['label']);
    $this->assertSame('Pinned heading', $text_node['block_fields']['field_heading']);
    $this->assertSame((int) \Drupal::currentUser()->id(), $text_node['block_fields']['field_ref']['target_id'], 'References are PRESERVED: the firm capture requirement.');
    $this->assertSame('basic', $root['children'][1]['children'][0]['block_type']);
    $this->assertSame('layout_onecol', $root['children'][1]['children'][1]['layout_id']);
    $this->assertSame('basic', $root['children'][1]['children'][1]['children'][0]['children'][0]['block_type']);

    // The styles ride the nodes in the canonical structured form, the
    // derived fragment reference gone, nothing from outside the subtree.
    $this->assertSame([
      '' => ['variants' => [['variant' => 'card', 'fragment' => '']], 'default' => ['background-color' => 'aliceblue']],
    ], $root['styles']);
    $this->assertSame([
      '' => ['default' => ['font-size' => '1.25rem']],
      ':field_heading' => ['default' => ['color' => 'tomato']],
    ], $text_node['styles']);
    $this->assertSame(['' => ['variants' => [['variant' => 'panel', 'fragment' => '']]]], $root['children'][1]['children'][1]['styles']);
    $map = $this->host($blueprint)->styleMap();
    $this->assertCount(4, $map, 'Exactly the subtree\'s four keyed entries landed (the root, the text block, its field, the inner container), re-keyed onto the minted identity.');
    foreach (array_keys($map) as $key) {
      $this->assertStringStartsNotWith('entity:', $key);
      $this->assertStringNotContainsString('11111111', $key);
    }

    // Capturing the same element again is a second blueprint, its own
    // identity, equal as a definition.
    $again = $this->manager()->saveAsBlueprint($this->host($entity), $section, 'Call to Action', 'Two-column card.');
    $this->assertSame('call_to_action_2', $again->blueprint->getMachineName());
    $this->assertEquals($this->definitionOf($blueprint), $this->definitionOf($again->blueprint));
    $this->assertNotEquals($this->builtLayout($blueprint), $this->builtLayout($again->blueprint), 'Each capture mints its own identity.');
  }

  /**
   * A composition's nested wears are derived data and drop like fragments.
   *
   * The card scenario as the map records it after a composition apply:
   * the root wears "card_left", whose slot entries nested "glow" onto the
   * inner container and the text block. Distribution stamped those
   * references with their origin ("via": card_left) beside the
   * composition's own fragment references. Both shapes are what the
   * root's apply_variant re-creates at placement, so neither travels: the
   * text block (nothing but the derived wear) carries no styles at all,
   * and the inner container keeps only its authored "panel" (a ''
   * reference with no origin) and its own override.
   */
  public function testDerivedWearsDrop(): void {
    ['entity' => $entity, 'section' => $section, 'text' => $text, 'inner' => $inner] = $this->buildReferenceSection();

    $entity->set('daedalus_stylish_styles', json_encode([
      "container:$section" => [
        'variants' => [['variant' => 'card_left', 'fragment' => '']],
      ],
      "container:$inner" => [
        'variants' => [
          ['variant' => 'panel', 'fragment' => ''],
          ['variant' => 'glow', 'fragment' => '', 'via' => 'card_left'],
          ['variant' => 'card_left', 'fragment' => 'container(1)'],
        ],
        'default' => ['margin' => '1px'],
      ],
      "block:$text" => [
        'variants' => [['variant' => 'glow', 'fragment' => '', 'via' => 'card_left']],
      ],
    ]));

    $result = $this->manager()->saveAsBlueprint($this->host($entity), $section, 'Card', 'A composed card.');
    $root = $this->definitionOf($result->blueprint)[0];
    $this->assertSame(['' => ['variants' => [['variant' => 'card_left', 'fragment' => '']]]], $root['styles']);
    $this->assertSame([
      '' => ['variants' => [['variant' => 'panel', 'fragment' => '']], 'default' => ['margin' => '1px']],
    ], $root['children'][1]['children'][1]['styles']);
    $this->assertArrayNotHasKey('styles', $root['children'][0]['children'][0], 'A child wearing only what the composition distributed carries no styles.');
    $this->assertSame([], $result->warnings, 'Derived wears drop silently: nothing inexpressible was lost.');
  }

  /**
   * Tests skeletonizing: structure, labels and layout settings stay, values go.
   */
  public function testStripValues(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $result = $this->manager()->saveAsBlueprint($this->host($entity), $section, 'Call to Action', 'Two-column card.', strip_values: TRUE);
    $root = $this->definitionOf($result->blueprint)[0];
    $this->assertSame('Call to Action', $root['label']);
    $this->assertSame('layout_twocol_section', $root['layout_id']);
    $text_node = $root['children'][0]['children'][0];
    $this->assertSame('text', $text_node['block_type']);
    $this->assertNotSame('My heading', $text_node['label'] ?? NULL, 'The display label is content and goes: the copy is born with the type\'s default.');
    // The tree spells an empty field as a form-only stub (the panel's
    // read), never as the pinned value.
    $this->assertNotSame('Pinned heading', $text_node['block_fields']['field_heading'] ?? NULL, 'No value is pinned: the field sample-populates at placement.');
    $this->assertArrayNotHasKey('target_id', $text_node['block_fields']['field_ref'] ?? []);
    $this->assertSame('layout_onecol', $root['children'][1]['children'][1]['layout_id'], 'Structure stays.');

    // The default capture PRESERVES the values (the firm requirement).
    $kept = $this->manager()->saveAsBlueprint($this->host($entity), $section, 'Kept', 'With content.');
    $this->assertSame('Pinned heading', $this->definitionOf($kept->blueprint)[0]['children'][0]['children'][0]['block_fields']['field_heading']);
  }

  /**
   * Tests capturing a top-level band by its section uuid.
   */
  public function testTopLevelBandCapture(): void {
    ['entity' => $entity, 'root_section' => $root_section] = $this->buildReferenceSection();

    $result = $this->manager()->saveAsBlueprint($this->host($entity), $root_section, 'Band', 'An empty band.');

    // The band is the host's original empty onecol row; the CTA section was
    // added as a SIBLING band, so this one captures empty: the point is
    // that a top-level row captures by its section uuid at all.
    $root = $this->definitionOf($result->blueprint)[0];
    $this->assertSame('container', $root['kind']);
    $this->assertSame('layout_onecol', $root['layout_id']);
    $this->assertSame([['kind' => 'region', 'region' => 'content', 'children' => []]], $root['children'], 'An empty multi-region layout keeps its (empty) slots.');
    $this->assertCount(1, $this->definitionOf($result->blueprint));
  }

  /**
   * A block is not a root: the capture refuses toward its container.
   */
  public function testBlockRootRefused(): void {
    ['entity' => $entity, 'section' => $section, 'text' => $text] = $this->buildReferenceSection();

    try {
      $this->manager()->saveAsBlueprint($this->host($entity), $text, 'Heading', 'One block.');
      $this->fail('A block root was captured.');
    }
    catch (BlueprintGestureException $exception) {
      $error = $exception->getErrors()[0];
      $this->assertSame('BLUEPRINT_ROOT_NOT_CONTAINER', $error->getCode());
      $this->assertSame('element', $error->getPath());
      $this->assertSame($section, $error->getContext()['container']);
      $this->assertStringContainsString('Capture its container', $error->getHint());
    }
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('blueprint')->loadMultiple(), 'Nothing was saved.');

    // The adoption listing refuses the same way.
    $this->expectException(BlueprintGestureException::class);
    $this->manager()->capturedVariants($this->host($entity), $text);
  }

  /**
   * Plugin blocks in the subtree travel as plugin nodes.
   *
   * A plugin block with schema (a field block, which names its field so
   * the destination host supplies the derivative) travels with its label,
   * whether the label renders, and its own configuration keys; a
   * schema-less plugin travels bare.
   */
  public function testPluginBlockCapture(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'second'],
        'plugin_id' => 'system_powered_by_block',
      ],
      [
        'op' => 'add_element',
        'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'second'],
        'plugin_id' => 'field_block:entity_test:entity_test:name',
        'properties' => ['label' => 'The name', 'label_display' => 'visible', 'formatter' => ['type' => 'string', 'label' => 'inline']],
      ],
    ]);
    $this->assertBatchSuccess($result);

    $blueprint = $this->manager()->saveAsBlueprint($this->host($entity), $section, 'Call to Action', 'With plugins.')->blueprint;
    $children = $this->definitionOf($blueprint)[0]['children'][1]['children'];
    $this->assertSame(['kind' => 'block', 'plugin_id' => 'system_powered_by_block'], $children[2]);
    $field_block = $children[3];
    $this->assertSame('name', $field_block['field'], 'A field block names its field; the host a blueprint lands on supplies the derivative.');
    $this->assertArrayNotHasKey('plugin_id', $field_block);
    $this->assertSame('The name', $field_block['label']);
    $this->assertTrue($field_block['label_rendered']);
    $this->assertSame('string', $field_block['configuration']['formatter']['type']);
    $this->assertSame('inline', $field_block['configuration']['formatter']['label']);
    $this->assertArrayNotHasKey('id', $field_block['configuration']);
    $this->assertArrayNotHasKey('context_mapping', $field_block['configuration']);
    $this->assertArrayNotHasKey('block_type', $field_block);
  }

  /**
   * The adoption listing reads the authored wearings of the subtree.
   */
  public function testCapturedVariants(): void {
    ['entity' => $entity, 'section' => $section, 'text' => $text, 'inner' => $inner] = $this->buildReferenceSection();
    $entity->set('daedalus_stylish_styles', json_encode([
      "container:$section" => ['variants' => [['variant' => 'card', 'fragment' => '']]],
      "block:$text" => ['variants' => [['variant' => 'glow', 'fragment' => '', 'via' => 'card']]],
      "container:$inner" => ['variants' => [['variant' => 'panel', 'fragment' => ''], ['variant' => 'card', 'fragment' => '']]],
    ]));
    $this->assertSame(['card', 'panel'], $this->manager()->capturedVariants($this->host($entity), $section), 'Authored wearings, unique, first seen first; derived wears never list.');
    $this->assertSame(['panel', 'card'], $this->manager()->capturedVariants($this->host($entity), $inner), 'A nested capture lists its own subtree.');
  }

  /**
   * Tests that an unknown element fails the capture loudly.
   */
  public function testUnknownElementFails(): void {
    ['entity' => $entity] = $this->createHost();

    try {
      $this->manager()->saveAsBlueprint($this->host($entity), '11111111-2222-3333-4444-555555555555', 'X', 'Y.');
      $this->fail('An unknown element was captured.');
    }
    catch (BlueprintGestureException $exception) {
      $this->assertSame('BLUEPRINT_CAPTURE_FAILED', $exception->getErrors()[0]->getCode());
    }
  }

}
