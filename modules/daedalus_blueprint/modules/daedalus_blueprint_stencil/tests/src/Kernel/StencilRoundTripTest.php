<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\daedalus_blueprint_stencil\StencilExport;
use Drupal\daedalus_blueprint_stencil\StencilExporterInterface;
use Drupal\daedalus_blueprint_stencil\StencilOperationHandler;
use Drupal\daedalus_blueprint_stencil_round_trip_test\StateStencilDiscovery;
use Drupal\layout_builder\SectionListInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Round-trip test: export, instantiate, export equivalent.
 *
 * The stencil grammar has two ends: the exporter walks a placed subtree into
 * the definition grammar and the instantiate operation stamps a definition
 * back into a placed subtree. This locks the two together (the
 * printer-equivalence precedent from daedalus_stylish): a styled section-rooted
 * reference layout is exported, the export is fed back through the REAL
 * instantiate path onto a fresh host, and the copy's export must equal the
 * original's. Everything real is in play — the batch operation spine,
 * daedalus_layout nested sections, and daedalus_stylish's actual update_style /
 * apply_variant handlers writing the host's style map — so a drift on
 * either end of the grammar (a key the exporter relativizes but the handler
 * cannot translate back, a preserved reference that fails to re-pin, a
 * style entry that lands on the wrong element) breaks the equality.
 *
 * The exporter-produced definition reaches discovery through the round-trip
 * test module's state-backed decorator: files cannot be written into module
 * directories at test runtime.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilRoundTripTest extends StencilKernelTestBase {

  /**
   * The export options both exports share.
   *
   * The label/description defaults name the source entity, which differs
   * between the original and the copy by design; pinning them keeps the
   * assertion on the element grammar, where equivalence is the contract.
   */
  protected const EXPORT_OPTIONS = [
    'label' => 'Round Trip',
    'description' => 'The reference layout, round-tripped.',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    'daedalus_blueprint_stencil_round_trip_test',
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
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    $this->createReferenceFields();

    // Styling enabled the product way: the field manager owns the map field.
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');

    // The variants the reference layout references. Real: the apply_variant
    // operations on both sides of the trip resolve them through
    // StyleMapValidator, scope check included. Panel is slot-less (its
    // wearer, the multi-region root, carries no slot space); "composed" is
    // the COMPOSITION variant, worn by the container-layout inner section,
    // whose slot path distributes a fragment reference AND a nested "card"
    // wear (stamped "via" composed) onto the inner's child block on BOTH
    // sides of the trip. The exporter deliberately drops both (derived
    // data: position resolves to identity at apply time), so the equality
    // proves instantiate's apply_variant re-distributes them, and the
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
   * The stencil exporter service.
   */
  protected function exporter(): StencilExporterInterface {
    return \Drupal::service('daedalus_blueprint_stencil.exporter');
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

    $result = $this->operations()->batchOperations($entity, [
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
   * Stamps an exported definition onto a fresh host via instantiate_stencil.
   *
   * @param \Drupal\daedalus_blueprint_stencil\StencilExport $export
   *   The export to feed back through the real instantiate path.
   *
   * @return array{entity: \Drupal\entity_test\Entity\EntityTest, section: string}
   *   The fresh host and the stamped copy's root section UUID.
   */
  protected function instantiate(StencilExport $export): array {
    \Drupal::state()->set(StateStencilDiscovery::STATE_KEY, [
      'round_trip' => $export->definition + ['provider' => 'daedalus_blueprint_stencil_round_trip_test'],
    ]);

    ['entity' => $entity] = $this->createHost();
    $section = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'round_trip',
        'uuid' => $section,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    return ['entity' => $entity, 'section' => $section];
  }

  /**
   * Export, instantiate, export: the two exports are equivalent.
   */
  public function testRoundTrip(): void {
    ['entity' => $original, 'section' => $section] = $this->buildStyledReferenceLayout();

    $first = $this->exporter()->export($original, $section, self::EXPORT_OPTIONS);
    $this->assertSame([], $first->warnings);

    // Guard against vacuous equivalence: the first export must carry the
    // full grammar surface the trip is supposed to preserve — the section
    // root with label and layout settings, the PRESERVED entity reference,
    // variant references AND custom styles on the section, a block, a
    // sub-element, and the nested section.
    $expected = $this->expectedReferenceElement();
    $expected['styles'] = [
      '' => [
        'variants' => ['panel'],
        'default' => ['background-color' => '#f5f5f5'],
      ],
    ];
    $expected['children'][0]['children'][0]['styles'] = [
      '' => ['variants' => ['card']],
      '::.quote' => ['default' => ['padding' => '2rem']],
    ];
    // The inner section is a container here (the container-first ladder
    // picked eb_test_container), wearing the composition variant: a
    // container layout's flow exports as DIRECT children, no region node.
    // Its child carries nothing: the distributed fragment reference and
    // the nested "card" wear stamped via composed are both derived data the
    // exporter drops, because the root's apply re-distributes them.
    $expected['children'][1]['children'][1] = [
      'kind' => 'container',
      'layout_id' => 'eb_test_container',
      'styles' => [
        '' => [
          'variants' => ['composed'],
          'default' => ['background-color' => 'aliceblue'],
        ],
      ],
      'children' => [
        [
          'kind' => 'block',
          'block_type' => 'basic',
          'block_fields' => ['info' => 'basic'],
        ],
      ],
    ];
    $this->assertEquals($expected, $first->definition['element']);

    ['entity' => $copy_host, 'section' => $copy_section] = $this->instantiate($first);

    // The copy's root carries the provenance stamp (the copy IS a stencil
    // placement, unlike the hand-built original), which makes the equality
    // below a non-vacuous proof that export excludes it: a captured stencil
    // never inherits the stamp of the placement it was captured from.
    $list = $copy_host->get('layout_builder__layout');
    $this->assertInstanceOf(SectionListInterface::class, $list);
    $this->assertSame('round_trip', $list->getSection(1)->getThirdPartySetting(StencilOperationHandler::PROVENANCE_KEY, 'stencil'));

    // The copy's root wears panel through daedalus_stylish's real apply_variant
    // handler, in the canonical structured reference form, with the
    // stencil's custom override layered beside it.
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
    // slot fragment - fresh identities, fresh resolution. The origin is
    // the proof the export kept the wear derived: had the stencil shipped
    // "card" on the child, instantiate's tree-order apply would have
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

    $second = $this->exporter()->export($copy_host, $copy_section, self::EXPORT_OPTIONS);

    $this->assertSame([], $second->warnings);
    $this->assertEquals($first->definition, $second->definition, 'Exporting the stamped copy reproduces the original export.');

    // Switching the copy's composition reaps what "composed" distributed
    // onto the child, the nested "card" wear included, and distributes the
    // newcomer's own fragment in its place: the placed stencil behaves
    // exactly like the hand-built original on a switch.
    $result = $this->operations()->batchOperations($copy_host, [
      ['op' => 'apply_variant', 'key' => "container:$copy_inner_uuid", 'variant' => 'recomposed'],
    ]);
    $this->assertBatchSuccess($result);
    $switched_map = json_decode((string) $copy_host->get(DaedalusStylishFieldManager::FIELD_NAME)->value, TRUE);
    $this->assertSame([['variant' => 'recomposed', 'fragment' => '']], $switched_map["container:$copy_inner_uuid"]['variants']);
    $this->assertSame([['variant' => 'recomposed', 'fragment' => 'block(basic,1)']], $switched_map["block:$copy_child_uuid"]['variants'], 'The child no longer wears the nested variant the leaving composition put there.');
  }

  /**
   * The skeleton form round-trips too: strip, instantiate, strip.
   *
   * A skeletonized export pins nothing, so every field on the stamped copy
   * sample-populates with nondeterministic content; stripping the copy's
   * export again must land back on the identical skeleton. This proves the
   * structural half of the grammar (nesting, labels, layout settings,
   * styles) is closed under the trip independently of pinned values.
   */
  public function testSkeletonRoundTrip(): void {
    ['entity' => $original, 'section' => $section] = $this->buildStyledReferenceLayout();

    $options = self::EXPORT_OPTIONS + ['strip_values' => TRUE];
    $first = $this->exporter()->export($original, $section, $options);
    $this->assertSame([], $first->warnings);
    $this->assertArrayNotHasKey('block_fields', $first->definition['element']['children'][0]['children'][0], 'Skeletonizing pinned nothing.');
    $this->assertArrayHasKey('styles', $first->definition['element'], 'Skeletonizing kept the styles.');

    ['entity' => $copy_host, 'section' => $copy_section] = $this->instantiate($first);
    $second = $this->exporter()->export($copy_host, $copy_section, $options);

    $this->assertSame([], $second->warnings);
    $this->assertEquals($first->definition, $second->definition, 'Skeleton exports are equivalent across the trip.');
  }

}
