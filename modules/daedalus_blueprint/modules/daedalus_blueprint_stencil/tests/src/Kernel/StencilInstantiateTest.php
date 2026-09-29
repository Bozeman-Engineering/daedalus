<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\daedalus_blueprint\Event\ElementCreatedEvent;
use Drupal\daedalus_blueprint\Event\SectionCreatedEvent;
use Drupal\daedalus_blueprint_stencil\StencilOperationHandler;
use Drupal\daedalus_blueprint_stencil_instantiate_test\StyleOpSpy;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionListInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the instantiate_stencil operation.
 *
 * Instantiation expands a stencil's element tree into the existing batch
 * grammar (add_section born as the node's layout, update_element,
 * add_element, and daedalus_stylish's style operations) and runs it as a nested
 * batch. A section-rooted stencil rides add_section's ONE placement
 * grammar, so the same stencil lands as a top-level band or nested inside
 * any region. The style operations are captured by a spy handler standing
 * in for daedalus_stylish, which keeps the assertions on exactly what the stencil
 * handler emits: the operations and their translated selectable keys.
 */
#[CoversClass(StencilOperationHandler::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilInstantiateTest extends StencilKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_blueprint_stencil_instantiate_test',
    'field_sample_value',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createBlockField('field_heading', 'text', 'string');
    $this->createBlockField('field_sub', 'text', 'string');
    // Placement fills only the fields the site has a sample policy for, so
    // the field the stencil leaves absent carries one.
    $sub = FieldConfig::loadByName('block_content', 'text', 'field_sub');
    $sub->setThirdPartySetting('field_sample_value', 'id', 'random_string');
    $sub->setThirdPartySetting('field_sample_value', 'configuration', [
      'prevent_save' => FALSE,
      'count' => 3,
    ]);
    $sub->save();
  }

  /**
   * Extracts the block_content entity behind an inline block component.
   */
  protected function blockContent(SectionComponent $component) {
    $block = NestedAwareSectionStorage::extractBlockContent($component->getPlugin());
    $this->assertNotNull($block);
    return $block;
  }

  /**
   * The host entity's sections.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The top-level sections.
   */
  protected function sections(EntityTest $entity): array {
    $list = $entity->get('layout_builder__layout');
    $this->assertInstanceOf(SectionListInterface::class, $list);
    return $list->getSections();
  }

  /**
   * Asserts the CTA stencil's subtree shape on a placed section.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The placed root section.
   *
   * @return array{section_uuid: string, text_uuid: string, inner_uuid: string}
   *   The minted uuids the style assertions need.
   */
  protected function assertCtaSubtree(Section $section): array {
    $this->assertSame('layout_twocol_section', $section->getLayoutId());
    $this->assertSame('33-67', $section->getLayoutSettings()['column_widths'], "The stencil's layout_settings are patched over the plugin defaults.");
    $this->assertSame('Call to Action', $section->getLayoutSettings()['label'], "The section node's label lands in layout_settings.label.");
    $section_uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    $this->assertNotEmpty($section_uuid);

    // Pinned fields arrive verbatim; absent fields sample-populate.
    $first = $section->getComponentsByRegion('first');
    $this->assertCount(1, $first);
    $text_component = reset($first);
    $text_block = $this->blockContent($text_component);
    $this->assertSame('text', $text_block->bundle());
    $this->assertSame('Pinned heading', $text_block->get('field_heading')->value);
    $this->assertNotEmpty($text_block->get('field_sub')->value, 'Absent fields sample-populate at placement.');

    // The second region holds a basic block AND a nested section — sections
    // all the way down, straight from the stencil definition.
    $second = array_values($section->getComponentsByRegion('second'));
    $this->assertCount(2, $second);
    $this->assertSame('basic', $this->blockContent($second[0])->bundle());
    $inner = \Drupal::service('daedalus_blueprint.daedalus_layout_adapter')->embeddedSection($second[1]);
    $this->assertInstanceOf(Section::class, $inner);
    $this->assertSame('layout_onecol', $inner->getLayoutId());
    $this->assertSame('Inner', $inner->getLayoutSettings()['label']);
    $inner_components = array_values($inner->getComponentsByRegion('content'));
    $this->assertCount(1, $inner_components);
    $this->assertSame('basic', $this->blockContent($inner_components[0])->bundle());

    // Provenance names the PLACEMENT root only, never its internals.
    $this->assertNull($inner->getThirdPartySetting(StencilOperationHandler::PROVENANCE_KEY, 'stencil'));

    return [
      'section_uuid' => $section_uuid,
      'text_uuid' => $text_component->getUuid(),
      'inner_uuid' => $inner->getThirdPartySetting('daedalus_layout', 'uuid'),
    ];
  }

  /**
   * The style operations the CTA stencil expands to, in tree order.
   */
  protected function expectedStyleOps(string $section, string $text, string $inner): array {
    return [
      ['op' => 'apply_variant', 'key' => "container:$section", 'variant' => 'card'],
      [
        'op' => 'update_style',
        'key' => "container:$section",
        'condition' => 'default',
        'properties' => ['background-color' => 'aliceblue'],
      ],
      // The ':field_heading' local translates onto the block FIELD key,
      // the tree grammar's field remainder.
      [
        'op' => 'update_style',
        'key' => "block:$text:field_heading",
        'condition' => 'default',
        'properties' => ['color' => 'tomato'],
      ],
      [
        'op' => 'update_style',
        'key' => "block:$text::.inner",
        'condition' => 'default',
        'properties' => ['padding' => '2rem'],
      ],
      ['op' => 'apply_variant', 'key' => "container:$inner", 'variant' => 'panel'],
    ];
  }

  /**
   * Tests stamping a section-rooted stencil as a top-level band.
   */
  public function testInstantiateAsBand(): void {
    ['entity' => $entity] = $this->createHost();

    // The client-minted root UUID (the Add panel's optimistic seam): the
    // placement is addressable before the response arrives, like any create.
    $client_uuid = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'cta',
        'uuid' => $client_uuid,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    // The stencil appended a band: a SECOND top-level row, no wrapper, no
    // client-side add_section hack.
    $sections = $this->sections($entity);
    $this->assertCount(2, $sections);
    $section = $sections[1];
    ['section_uuid' => $section_uuid, 'text_uuid' => $text_uuid, 'inner_uuid' => $inner_uuid] = $this->assertCtaSubtree($section);
    $this->assertSame($client_uuid, $section_uuid, 'The root section honors the client-provided uuid.');

    // Provenance: the placed ROOT section is stamped with the source
    // stencil ID in its third-party settings (informational only; the
    // Save/Update verb reads it through StencilManager::
    // placementProvenance()).
    $this->assertSame('cta', $section->getThirdPartySetting(StencilOperationHandler::PROVENANCE_KEY, 'stencil'));
    $this->assertSame('cta', \Drupal::service('daedalus_blueprint_stencil.manager')->placementProvenance($section));

    // The serialization spine surfaces the stamp as the section node's
    // read-only `stencil` key, so every delivered blueprint carries it.
    // That is how the canvas verb UI reads provenance off the client
    // mirror. The nested inner section carries no key at all.
    $blueprint = \Drupal::service('daedalus_blueprint.serializer')->serialize($entity);
    $serialized_root = $blueprint['layout_builder__layout'][1];
    $this->assertSame('cta', $serialized_root['stencil']);
    $serialized_inner = $serialized_root['regions']['second'][1];
    $this->assertSame('layout_onecol', $serialized_inner['layout_id']);
    $this->assertArrayNotHasKey('stencil', $serialized_inner);

    // The co-located relative style keys were translated onto the minted
    // UUIDs as daedalus_ui selectable keys and emitted through daedalus_stylish's
    // operations, in tree order — the full layered entries: variant
    // references AND overrides, re-keyed onto the fresh uuids.
    $this->assertSame(
      $this->expectedStyleOps($section_uuid, $text_uuid, $inner_uuid),
      \Drupal::state()->get(StyleOpSpy::STATE_KEY, []),
    );

    // The stamp rides the section's own serialization, so it survives a
    // save/reload round trip. The static cache would hand back the same
    // mutated object, so it is reset first.
    $entity->save();
    \Drupal::entityTypeManager()->getStorage('entity_test')->resetCache([$entity->id()]);
    $reloaded = EntityTest::load($entity->id());
    $this->assertSame('cta', $this->sections($reloaded)[1]->getThirdPartySetting(StencilOperationHandler::PROVENANCE_KEY, 'stencil'));

    // Duplicating a stencil placement keeps the stamp (the section value
    // clones whole), so the copy still resolves to the same stencil and the
    // canvas verb keeps offering "Update stencil 'X'".
    $copy_uuid = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($reloaded, [
      ['op' => 'duplicate_element', 'element' => $section_uuid, 'uuid' => $copy_uuid],
    ]);
    $this->assertBatchSuccess($result);
    foreach ($this->sections($reloaded) as $candidate) {
      if ($candidate->getThirdPartySetting('daedalus_layout', 'uuid') === $copy_uuid) {
        $this->assertSame('cta', $candidate->getThirdPartySetting(StencilOperationHandler::PROVENANCE_KEY, 'stencil'));
        return;
      }
    }
    $this->fail('The duplicated section was not found.');
  }

  /**
   * Tests nesting the SAME stencil inside a region: place-anywhere parity.
   */
  public function testInstantiateNested(): void {
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'cta',
        'location' => ['anchor' => $root_section, 'placement' => 'inside', 'region' => 'content'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    // One top-level row still; the stencil landed as a nested section (a
    // container component) in its content region.
    $sections = $this->sections($entity);
    $this->assertCount(1, $sections);
    $components = array_values($sections[0]->getComponentsByRegion('content'));
    $this->assertCount(1, $components);
    $section = \Drupal::service('daedalus_blueprint.daedalus_layout_adapter')->embeddedSection($components[0]);
    $this->assertInstanceOf(Section::class, $section);
    $this->assertCtaSubtree($section);

    // Provenance stamps the nested placement too, uniformly on the SECTION
    // value (bubbled into the container config).
    $this->assertSame('cta', $section->getThirdPartySetting(StencilOperationHandler::PROVENANCE_KEY, 'stencil'));
  }

  /**
   * Tests the expansion runs dressed: each creation is a total statement.
   *
   * The nested batch declares ['dressed' => TRUE], so the section-created
   * and element-created events of the root, its nested container and
   * every sampled block answer dressed() TRUE (birth subscribers stamp no
   * defaults under them), while a plain add_section beside the stencil in
   * the same undressed outer batch is born undressed.
   */
  public function testInstantiateIsDressed(): void {
    ['entity' => $entity] = $this->createHost();
    $dispatcher = \Drupal::service('event_dispatcher');
    $sections = [];
    $dispatcher->addListener(SectionCreatedEvent::class, function (SectionCreatedEvent $event) use (&$sections): void {
      $sections[$event->getSectionUuid()] = $event->dressed();
    });
    $elements = [];
    $dispatcher->addListener(ElementCreatedEvent::class, function (ElementCreatedEvent $event) use (&$elements): void {
      $elements[$event->getComponent()->getUuid()] = $event->dressed();
    });

    $stencil_uuid = \Drupal::service('uuid')->generate();
    $plain_uuid = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'cta',
        'uuid' => $stencil_uuid,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_section',
        'uuid' => $plain_uuid,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    ['inner_uuid' => $inner_uuid] = $this->assertCtaSubtree($this->sections($entity)[1]);
    $this->assertSame([$stencil_uuid => TRUE, $inner_uuid => TRUE, $plain_uuid => FALSE], $sections, 'The stencil root and its nested container are dressed; the plain add beside them is not.');
    $this->assertCount(3, $elements, 'Every block of the expansion dispatched its created event.');
    $this->assertSame([TRUE], array_values(array_unique($elements)), 'Every sampled block of the expansion is dressed.');
  }

  /**
   * Tests a block-rooted stencil: block roots stay supported.
   */
  public function testInstantiateBlockRoot(): void {
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $client_uuid = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'solo',
        'uuid' => $client_uuid,
        'location' => ['anchor' => $root_section, 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    $components = $this->sections($entity)[0]->getComponents();
    $this->assertCount(1, $components);
    $component = reset($components);
    $this->assertSame($client_uuid, $component->getUuid());
    $this->assertSame('basic', $this->blockContent($component)->bundle());
    // Block roots carry provenance in the component's additional data.
    $this->assertSame('solo', $component->get(StencilOperationHandler::PROVENANCE_KEY));
    $this->assertSame([
      [
        'op' => 'update_style',
        'key' => "block:$client_uuid",
        'condition' => 'default',
        'properties' => ['margin' => '1rem'],
      ],
    ], \Drupal::state()->get(StyleOpSpy::STATE_KEY, []));
  }

  /**
   * Tests that the dead block-holds-layout shape is refused loudly.
   */
  public function testBlockLayoutRefused(): void {
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'legacy',
        'location' => ['anchor' => $root_section, 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_STENCIL', $error->getCode());
    $this->assertSame('element.layout', $error->getPath());
    $this->assertStringContainsString('Blocks do not hold layouts', $error->getMessage());
    $this->assertCount(0, $this->sections($entity)[0]->getComponents());
  }

  /**
   * Tests that an unknown stencil ID fails with the available IDs listed.
   */
  public function testUnknownStencil(): void {
    ['entity' => $entity] = $this->createHost();

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'does_not_exist',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('STENCIL_NOT_FOUND', $error->getCode());
    $this->assertContains('cta', $error->getContext()['available']);
  }

  /**
   * Tests a client-minted stencil root uuid that is already in use.
   *
   * The placement expands into ordinary add_section/add_element operations,
   * so the root uuid meets the same minted-identity gate any create does —
   * no separate uniqueness rule for stencils. Proving it here keeps that
   * inheritance from silently lapsing.
   */
  public function testRootUuidInUseRefused(): void {
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'cta',
        'uuid' => $root_section,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('UUID_IN_USE', $result->getErrors()[0]->getCode());
    // Nothing was stamped: the host still has only its own section.
    $this->assertCount(1, $this->sections($entity));
  }

  /**
   * Tests that a bad relative style key fails loudly with coordinates.
   *
   * The whole tree is expanded before anything executes, so a grammar error
   * anywhere in the definition leaves the entity untouched.
   */
  public function testBadRelativeStyleKey(): void {
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'bad_styles',
        'location' => ['anchor' => $root_section, 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_STENCIL', $error->getCode());
    $this->assertSame('element.styles.region:first', $error->getPath());
    $this->assertSame('bad_styles', $error->getContext()['stencil']);
    $this->assertSame('daedalus_blueprint_stencil_instantiate_test', $error->getContext()['provider']);
    $this->assertCount(0, $this->sections($entity)[0]->getComponents());
  }

  /**
   * Tests that the retired region:NAME relative form is refused loudly.
   *
   * Stored stencils were rewritten by the container migration; a section
   * node still spelling region-relative styles (a module-shipped stencil
   * predating the container model) gets the container-vocabulary
   * corrective instead of an op-time failure on an unaddressable key.
   */
  public function testRetiredRegionRelativeStylesRefused(): void {
    ['entity' => $entity] = $this->createHost();

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'region_styles',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_STENCIL', $error->getCode());
    $this->assertSame('element.styles.region:first', $error->getPath());
    $this->assertStringContainsString('retired', $error->getMessage());
    $this->assertStringContainsString('regions are not addressable', $error->getMessage());
    $this->assertCount(0, $this->sections($entity)[0]->getComponents());
  }

  /**
   * Tests that a mid-execution semantic failure rolls the entity back.
   *
   * The exploding stencil's add_element succeeds before its style
   * operation fails, so the rollback (not the pre-flight grammar check) is
   * what restores the entity.
   */
  public function testSemanticFailureRollsBack(): void {
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'instantiate_stencil',
        'stencil' => 'exploding',
        'location' => ['anchor' => $root_section, 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('STYLE_VARIANT_NOT_FOUND', $error->getCode());
    // The nested batch's error is re-rooted onto the stencil definition's
    // own coordinate space.
    $this->assertSame('element.styles.variants.0.variant', $error->getPath());
    $this->assertSame('exploding', $error->getContext()['stencil']);
    $this->assertCount(0, $this->sections($entity)[0]->getComponents());
  }

}
