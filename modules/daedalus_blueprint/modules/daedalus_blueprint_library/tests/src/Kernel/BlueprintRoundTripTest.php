<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_blueprint_library\BlueprintManager;
use Drupal\daedalus_blueprint_library\BlueprintOperationHandler;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\layout_builder\SectionListInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Round-trip test: capture, place, capture equivalent.
 *
 * Capture and placement are one machinery seen from two ends: the manager
 * reads a placed subtree into a blueprint's document through the
 * instantiator, and place_blueprint stamps that document back into a page
 * through the same instantiator. This locks the two together (the
 * printer-equivalence precedent from daedalus_stylish): a styled
 * container-rooted reference layout is captured, the blueprint is placed
 * through the REAL placement path onto a fresh host, and a capture of the
 * copy must equal the first as a definition. Everything real is in play
 * (the batch operation spine, daedalus_layout nested sections, and
 * daedalus_stylish's actual update_style / apply_variant handlers writing
 * the hosts' style maps), so a drift on either end (a style entry that
 * lands on the wrong element, a preserved reference that fails to re-pin,
 * a derived wear re-created as authored) breaks the equality.
 */
#[CoversClass(BlueprintManager::class)]
#[CoversClass(BlueprintOperationHandler::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintRoundTripTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    // Provides the eb_test_container layout: the container-first default
    // ladder then mints the reference layout's INNER section as a
    // container, which is what gives the composition variant below a slot
    // space to distribute over.
    'daedalus_blueprint_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    // Material references resolve through the materials roster, whose
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

    // The variants the reference layout references. Real: the apply_variant
    // operations on both sides of the trip resolve them through
    // StyleMapValidator, scope check included. Panel is slot-less (its
    // wearer, the multi-region root, carries no slot space); "composed" is
    // the COMPOSITION variant, worn by the container-layout inner section,
    // whose slot path distributes a fragment reference AND a nested "card"
    // wear (stamped "via" composed) onto the inner's child block on BOTH
    // sides of the trip. The capture deliberately drops both (derived
    // data: position resolves to identity at apply time), so the equality
    // proves placement's apply_variant re-distributes them, and the
    // switch step proves the re-distributed nested wear stayed derived:
    // "recomposed" is the switch target, a second composition on the same
    // scope whose apply must reap what "composed" put on the child.
    $panel = DaedalusStylishVariant::create([
      'label' => 'Panel',
      'machine_name' => 'panel',
      'scope' => 'layout:layout_twocol_section',
    ]);
    $panel->setStyles([
      '' => ['default' => ['gap' => '1rem']],
    ]);
    $panel->save();
    DaedalusStylishVariant::create([
      'label' => 'Card',
      'machine_name' => 'card',
      'scope' => 'kind:block',
    ])->save();
    $composed = DaedalusStylishVariant::create([
      'label' => 'Composed',
      'machine_name' => 'composed',
      'scope' => 'layout:eb_test_container',
    ]);
    $composed->setStyles([
      '' => ['default' => ['gap' => '1rem']],
      'block(basic,1)' => [
        'variants' => [['variant' => 'card', 'fragment' => '']],
        'default' => ['padding' => '4px'],
      ],
    ]);
    $composed->save();
    $recomposed = DaedalusStylishVariant::create([
      'label' => 'Recomposed',
      'machine_name' => 'recomposed',
      'scope' => 'layout:eb_test_container',
    ]);
    $recomposed->setStyles([
      '' => ['default' => ['gap' => '2rem']],
      'block(basic,1)' => ['default' => ['padding' => '8px']],
    ]);
    $recomposed->save();
  }

  /**
   * The blueprint manager service.
   */
  protected function manager(): BlueprintManager {
    return \Drupal::service('daedalus_blueprint_library.manager');
  }

  /**
   * Builds and styles the reference layout on a fresh host, via the spine.
   *
   * The reference section (labeled two-column, pinned text with a PRESERVED
   * entity reference, basic block, nested inner section) plus real styles:
   * a layout-scoped variant on the root section, a block variant on the
   * text block, custom styles on the section, a block, and a sub-element
   * (regions carry no keys under the container model).
   *
   * @return array{entity: \Drupal\entity_test\Entity\EntityTest, section: string}
   *   The host entity and the root section's UUID.
   */
  protected function buildStyledReferenceLayout(): array {
    ['entity' => $entity, 'section' => $section, 'text' => $text, 'inner' => $inner] = $this->buildReferenceSection();

    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'apply_variant', 'key' => "container:$section", 'variant' => 'panel'],
      [
        'op' => 'update_style',
        'key' => "container:$section",
        'properties' => ['background-color' => '#f5f5f5'],
      ],
      ['op' => 'apply_variant', 'key' => "block:$text", 'variant' => 'card'],
      [
        'op' => 'update_style',
        'key' => "block:$text::.quote",
        'properties' => ['padding' => '2rem'],
      ],
      ['op' => 'apply_variant', 'key' => "container:$inner", 'variant' => 'composed'],
      [
        'op' => 'update_style',
        'key' => "container:$inner",
        'properties' => ['background-color' => 'aliceblue'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    return ['entity' => $entity, 'section' => $section];
  }

  /**
   * Places a blueprint onto a fresh host via place_blueprint.
   *
   * @return array{entity: \Drupal\entity_test\Entity\EntityTest, section: string}
   *   The fresh host and the stamped copy's root section UUID.
   */
  protected function place(Blueprint $blueprint): array {
    ['entity' => $entity] = $this->createHost();
    $section = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => $blueprint->getMachineName(),
        'uuid' => $section,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    return ['entity' => $entity, 'section' => $section];
  }

  /**
   * Capture, place, capture: the two captures are equivalent.
   */
  public function testRoundTrip(): void {
    ['entity' => $original, 'section' => $section] = $this->buildStyledReferenceLayout();

    $first = $this->manager()->saveAsBlueprint($this->host($original), $section, 'Round Trip', 'The reference layout, round-tripped.');
    $this->assertSame([], $first->warnings);
    $definition = $this->definitionOf($first->blueprint);

    // Guard against vacuous equivalence: the first capture must carry the
    // full grammar surface the trip is supposed to preserve: the section
    // root with label and layout settings, the PRESERVED entity reference,
    // variant references AND custom styles on the section, a block, a
    // sub-element, and the nested section.
    $root = $definition[0];
    $this->assertSame('Call to Action', $root['label']);
    $this->assertSame('layout_twocol_section', $root['layout_id']);
    $this->assertSame([
      '' => [
        'variants' => [['variant' => 'panel', 'fragment' => '']],
        'default' => ['background-color' => '#f5f5f5'],
      ],
    ], $root['styles']);
    $text_node = $root['children'][0]['children'][0];
    $this->assertSame((int) \Drupal::currentUser()->id(), $text_node['block_fields']['field_ref']['target_id']);
    $this->assertSame([
      '' => ['variants' => [['variant' => 'card', 'fragment' => '']]],
      '::.quote' => ['default' => ['padding' => '2rem']],
    ], $text_node['styles']);
    // The inner section is a container here (the container-first ladder
    // picked eb_test_container), wearing the composition variant: a
    // container layout's flow is its DIRECT children, no region node.
    // Its child carries nothing: the distributed fragment reference and
    // the nested "card" wear stamped via composed are both derived data the
    // capture drops, because the root's apply re-distributes them.
    $inner_node = $root['children'][1]['children'][1];
    $this->assertSame('eb_test_container', $inner_node['layout_id']);
    $this->assertSame([
      '' => [
        'variants' => [['variant' => 'composed', 'fragment' => '']],
        'default' => ['background-color' => 'aliceblue'],
      ],
    ], $inner_node['styles']);
    $this->assertSame('basic', $inner_node['children'][0]['block_type']);
    // The blueprint's own document is a page too: the composition the
    // capture re-applied on the inner container distributed onto the
    // child, so the child reads the DERIVED references (a via, a
    // fragment) and nothing authored; the next capture drops them again.
    $this->assertSame([
      ['variant' => 'card', 'fragment' => '', 'via' => 'composed'],
      ['variant' => 'composed', 'fragment' => 'block(basic,1)'],
    ], $inner_node['children'][0]['styles']['']['variants']);

    ['entity' => $copy_host, 'section' => $copy_section] = $this->place($first->blueprint);

    // The copy's root carries the provenance stamp (the copy IS a
    // placement, unlike the hand-built original), which makes the equality
    // below a non-vacuous proof that the capture excludes it: a captured
    // blueprint never inherits the stamp of the placement it was captured
    // from.
    $list = $copy_host->get('layout_builder__layout');
    $this->assertInstanceOf(SectionListInterface::class, $list);
    $this->assertSame('round_trip', $list->getSection(1)->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint'));

    // The copy's root wears panel through daedalus_stylish's real
    // apply_variant handler, in the canonical structured reference form,
    // with the blueprint's custom override layered beside it.
    $copy_map = json_decode((string) $copy_host->get(DaedalusStylishFieldManager::FIELD_NAME)->value, TRUE);
    $this->assertSame(
      [
        'variants' => [['variant' => 'panel', 'fragment' => '']],
        'default' => ['background-color' => '#f5f5f5'],
      ],
      $copy_map["container:$copy_section"],
    );

    // The composition variant re-distributed over the stamped copy: the
    // copy's inner container wears the root reference and its child block
    // carries the nested "card" wear, stamped with its origin, beside the
    // slot fragment: fresh identities, fresh resolution. The origin is
    // the proof the capture kept the wear derived: had the blueprint held
    // "card" on the child, placement's tree-order apply would have
    // re-placed it as an authored wear with no origin.
    $copy_inner_component = array_values($list->getSection(1)->getComponentsByRegion('second'))[1];
    $copy_inner = \Drupal::service('daedalus_blueprint.daedalus_layout_adapter')->embeddedSection($copy_inner_component);
    $copy_inner_uuid = $copy_inner->getThirdPartySetting('daedalus_layout', 'uuid');
    $copy_child_uuid = array_key_first($copy_inner->getComponents());
    $this->assertSame([['variant' => 'composed', 'fragment' => '']], $copy_map["container:$copy_inner_uuid"]['variants']);
    $this->assertSame(
      [
        ['variant' => 'card', 'fragment' => '', 'via' => 'composed'],
        ['variant' => 'composed', 'fragment' => 'block(basic,1)'],
      ],
      $copy_map["block:$copy_child_uuid"]['variants'],
    );

    $second = $this->manager()->saveAsBlueprint($this->host($copy_host), $copy_section, 'Round Trip', 'The reference layout, round-tripped.');
    $this->assertSame([], $second->warnings);
    $second_definition = $this->definitionOf($second->blueprint);
    $this->assertArrayNotHasKey('blueprint', $second_definition[0], 'The copy\'s stamp never travels into the blueprint.');
    // A placement is a palette drop: its inline blocks sample-populate and
    // take the bundle's existing render display (`default`, since no
    // block_content bundle defines `full`), which the copy's tree then
    // spells; the hand-built original renders the same way through the
    // same fallback and spells nothing. The view mode aside, the two
    // captures are one definition.
    $this->assertSame('default', $second_definition[0]['children'][0]['children'][0]['view_mode']);
    $this->assertEquals(self::withoutViewModes($definition), self::withoutViewModes($second_definition), 'Capturing the stamped copy reproduces the first capture.');

    // Switching the copy's composition reaps what "composed" distributed
    // onto the child, the nested "card" wear included, and distributes the
    // newcomer's own fragment in its place: the placed blueprint behaves
    // exactly like the hand-built original on a switch.
    $result = $this->operations()->batchOperations($this->host($copy_host), [
      ['op' => 'apply_variant', 'key' => "container:$copy_inner_uuid", 'variant' => 'recomposed'],
    ]);
    $this->assertBatchSuccess($result);
    $switched_map = json_decode((string) $copy_host->get(DaedalusStylishFieldManager::FIELD_NAME)->value, TRUE);
    $this->assertSame([['variant' => 'recomposed', 'fragment' => '']], $switched_map["container:$copy_inner_uuid"]['variants']);
    $this->assertSame([['variant' => 'recomposed', 'fragment' => 'block(basic,1)']], $switched_map["block:$copy_child_uuid"]['variants'], 'The child no longer wears the nested variant the leaving composition put there.');
  }

  /**
   * The skeleton form round-trips too: strip, place, strip.
   *
   * A skeletonized capture pins nothing, so every field on the stamped
   * copy sample-populates with nondeterministic content; stripping the
   * copy's capture again must land back on the identical skeleton. This
   * proves the structural half of the grammar (nesting, labels, layout
   * settings, styles) is closed under the trip independently of pinned
   * values.
   */
  public function testSkeletonRoundTrip(): void {
    ['entity' => $original, 'section' => $section] = $this->buildStyledReferenceLayout();

    $first = $this->manager()->saveAsBlueprint($this->host($original), $section, 'Skeleton', 'The reference layout, skeletonized.', strip_values: TRUE);
    $this->assertSame([], $first->warnings);
    $definition = $this->definitionOf($first->blueprint);
    $this->assertNotSame('Pinned heading', $definition[0]['children'][0]['children'][0]['block_fields']['field_heading'] ?? NULL, 'Skeletonizing pinned nothing.');
    $this->assertArrayHasKey('styles', $definition[0], 'Skeletonizing kept the styles.');

    ['entity' => $copy_host, 'section' => $copy_section] = $this->place($first->blueprint);
    $second = $this->manager()->saveAsBlueprint($this->host($copy_host), $copy_section, 'Skeleton', 'The reference layout, skeletonized.', strip_values: TRUE);

    $this->assertSame([], $second->warnings);
    $this->assertEquals(self::withoutViewModes($definition), self::withoutViewModes($this->definitionOf($second->blueprint)), 'Skeleton captures are equivalent across the trip.');
  }

  /**
   * A tree without its blocks' view modes (see testRoundTrip()).
   */
  protected static function withoutViewModes(array $nodes): array {
    return array_map(static function ($node) {
      if (!is_array($node)) {
        return $node;
      }
      unset($node['view_mode']);
      if (isset($node['children']) && is_array($node['children'])) {
        $node['children'] = self::withoutViewModes($node['children']);
      }
      return $node;
    }, $nodes);
  }

}
