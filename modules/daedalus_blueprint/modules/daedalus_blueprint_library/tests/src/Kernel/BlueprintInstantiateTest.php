<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\daedalus_blueprint\Event\ElementCreatedEvent;
use Drupal\daedalus_blueprint\Event\SectionCreatedEvent;
use Drupal\daedalus_blueprint_library\BlueprintInterface;
use Drupal\daedalus_blueprint_library\BlueprintOperationHandler;
use Drupal\daedalus_blueprint_library_instantiate_test\StyleOpSpy;
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
 * Tests the place_blueprint operation.
 *
 * Instantiation expands a blueprint's element tree into the existing batch
 * grammar (add_section born as the node's layout, update_element,
 * add_element, and daedalus_stylish's style operations) and runs it as a nested
 * batch. A section-rooted blueprint rides add_section's ONE placement
 * grammar, so the same blueprint lands as a top-level band or nested inside
 * any region. The style operations are captured by a spy handler standing
 * in for daedalus_stylish, which keeps the assertions on exactly what the blueprint
 * handler emits: the operations and their translated selectable keys.
 */
#[CoversClass(BlueprintOperationHandler::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintInstantiateTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_blueprint_library_instantiate_test',
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
    // the field the blueprint leaves absent carries one.
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
   * Asserts the CTA blueprint's subtree shape on a placed section.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The placed root section.
   *
   * @return array{section_uuid: string, text_uuid: string, inner_uuid: string}
   *   The minted uuids the style assertions need.
   */
  protected function assertCtaSubtree(Section $section): array {
    $this->assertSame('layout_twocol_section', $section->getLayoutId());
    $this->assertSame('33-67', $section->getLayoutSettings()['column_widths'], "The blueprint's layout_settings are patched over the plugin defaults.");
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
    // all the way down, straight from the blueprint definition.
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
    $this->assertNull($inner->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint'));

    return [
      'section_uuid' => $section_uuid,
      'text_uuid' => $text_component->getUuid(),
      'inner_uuid' => $inner->getThirdPartySetting('daedalus_layout', 'uuid'),
    ];
  }

  /**
   * The style operations the CTA blueprint expands to, in tree order.
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
   * Tests stamping a section-rooted blueprint as a top-level band.
   */
  public function testInstantiateAsBand(): void {
    ['entity' => $entity] = $this->createHost();

    // The client-minted root UUID (the Add panel's optimistic seam): the
    // placement is addressable before the response arrives, like any create.
    $client_uuid = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'cta',
        'uuid' => $client_uuid,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    // The blueprint appended a band: a SECOND top-level row, no wrapper, no
    // client-side add_section hack.
    $sections = $this->sections($entity);
    $this->assertCount(2, $sections);
    $section = $sections[1];
    ['section_uuid' => $section_uuid, 'text_uuid' => $text_uuid, 'inner_uuid' => $inner_uuid] = $this->assertCtaSubtree($section);
    $this->assertSame($client_uuid, $section_uuid, 'The root section honors the client-provided uuid.');

    // Provenance: the placed ROOT section is stamped with the source
    // blueprint ID in its third-party settings (informational only; the
    // Save/Update verb reads it through BlueprintManager::
    // placementProvenance()).
    $this->assertSame('cta', $section->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint'));
    $this->assertSame('cta', \Drupal::service('daedalus_blueprint_library.manager')->placementProvenance($section));

    // The serialization spine surfaces the stamp as the section node's
    // read-only `blueprint` key, so every delivered blueprint carries it.
    // That is how the canvas verb UI reads provenance off the client
    // mirror. The nested inner section carries no key at all.
    $blueprint = \Drupal::service('daedalus_blueprint.serializer')->serialize($this->host($entity));
    $serialized_root = $blueprint['layout'][1];
    $this->assertSame('cta', $serialized_root['blueprint']);
    $second = array_values(array_filter($serialized_root['children'], static fn (array $child): bool => $child['region'] === 'second'))[0];
    $serialized_inner = $second['children'][1];
    $this->assertSame('layout_onecol', $serialized_inner['layout_id']);
    $this->assertArrayNotHasKey('blueprint', $serialized_inner);

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
    $this->assertSame('cta', $this->sections($reloaded)[1]->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint'));

    // Duplicating a blueprint placement keeps the stamp (the section value
    // clones whole), so the copy still resolves to the same blueprint and the
    // canvas verb keeps offering "Update blueprint 'X'".
    $copy_uuid = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($reloaded), [
      ['op' => 'duplicate_element', 'element' => $section_uuid, 'uuid' => $copy_uuid],
    ]);
    $this->assertBatchSuccess($result);
    foreach ($this->sections($reloaded) as $candidate) {
      if ($candidate->getThirdPartySetting('daedalus_layout', 'uuid') === $copy_uuid) {
        $this->assertSame('cta', $candidate->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint'));
        return;
      }
    }
    $this->fail('The duplicated section was not found.');
  }

  /**
   * Tests nesting the SAME blueprint inside a region: place-anywhere parity.
   */
  public function testInstantiateNested(): void {
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'cta',
        'location' => ['anchor' => $root_section, 'placement' => 'inside', 'region' => 'content'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    // One top-level row still; the blueprint landed as a nested section (a
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
    $this->assertSame('cta', $section->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint'));
  }

  /**
   * Tests the expansion runs dressed: each creation is a total statement.
   *
   * The nested batch declares ['dressed' => TRUE], so the section-created
   * and element-created events of the root, its nested container and
   * every sampled block answer dressed() TRUE (birth subscribers stamp no
   * defaults under them), while a plain add_section beside the blueprint in
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

    $blueprint_uuid = \Drupal::service('uuid')->generate();
    $plain_uuid = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'cta',
        'uuid' => $blueprint_uuid,
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
    $this->assertSame([$blueprint_uuid => TRUE, $inner_uuid => TRUE, $plain_uuid => FALSE], $sections, 'The blueprint root and its nested container are dressed; the plain add beside them is not.');
    $this->assertCount(3, $elements, 'Every block of the expansion dispatched its created event.');
    $this->assertSame([TRUE], array_values(array_unique($elements)), 'Every sampled block of the expansion is dressed.');
  }

  /**
   * Tests the smallest blueprint: one container holding one styled block.
   *
   * Roots are always containers, so the client-minted root uuid names the
   * placed section and its provenance rides the section; the block inside
   * is minted and its own style lands on the minted key.
   */
  public function testInstantiateSmallestBlueprint(): void {
    ['entity' => $entity] = $this->createHost();

    $client_uuid = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'solo',
        'uuid' => $client_uuid,
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertBatchSuccess($result);

    $placed = $this->sections($entity)[1];
    $this->assertSame($client_uuid, $placed->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertSame('solo', $placed->getThirdPartySetting(BlueprintOperationHandler::PROVENANCE_KEY, 'blueprint'));
    $components = $placed->getComponents();
    $this->assertCount(1, $components);
    $component = reset($components);
    $this->assertNotSame($client_uuid, $component->getUuid(), 'The block inside is minted.');
    $this->assertSame('basic', $this->blockContent($component)->bundle());
    $this->assertNull($component->get(BlueprintOperationHandler::PROVENANCE_KEY), 'Provenance rides the root container, never a nested block.');
    $this->assertSame([
      [
        'op' => 'update_style',
        'key' => 'block:' . $component->getUuid(),
        'condition' => 'default',
        'properties' => ['margin' => '1rem'],
      ],
    ], \Drupal::state()->get(StyleOpSpy::STATE_KEY, []));
  }

  /**
   * Tests that an unknown blueprint ID fails with the available IDs listed.
   */
  public function testUnknownBlueprint(): void {
    ['entity' => $entity] = $this->createHost();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'does_not_exist',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('BLUEPRINT_NOT_FOUND', $error->getCode());
    $this->assertContains('cta', $error->getContext()['available']);
  }

  /**
   * Tests that an empty blueprint is refused, naming its canvas.
   *
   * A saved blueprint composed from scratch and not yet holding a root
   * (the fit passes vacuously, the instantiator would be handed nothing)
   * is refused BLUEPRINT_EMPTY after the lookup and before anything is
   * staged; the dialect's <pb-blueprint> rides the same door and answers
   * the same code.
   */
  public function testEmptyBlueprintRefused(): void {
    $blueprint = $this->createBlueprint(['label' => 'Uncomposed', 'machine_name' => 'uncomposed'], []);
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'uncomposed',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $error = $errors[0];
    $this->assertSame('BLUEPRINT_EMPTY', $error->getCode());
    $this->assertSame('blueprint', $error->getPath());
    $this->assertSame('Blueprint "uncomposed" is empty: its document holds no root container, so there is nothing to place.', $error->getMessage());
    $this->assertSame(['blueprint' => 'uncomposed', 'provider' => NULL], $error->getContext());
    $this->assertSame(sprintf('Compose the blueprint first, on its own canvas at %s or through the page tools with entity_type: blueprint and entity_id: %s, then place it; or place a blueprint that holds a root.', $blueprint->toUrl()->toString(), $blueprint->id()), $error->getHint());
    $sections = $this->sections($entity);
    $this->assertCount(1, $sections, 'Nothing was staged.');
    $this->assertCount(0, $sections[0]->getComponents());

    // The page language's spelling of the same placement.
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'insert',
        'html' => '<pb-blueprint name="uncomposed" />',
        'at' => ['ref' => $root_section, 'position' => 'afterend'],
      ],
    ], $this->dialectOptions(with_roster: TRUE));
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['BLUEPRINT_EMPTY'], array_map(static fn ($e) => $e->getCode(), $result->getErrors()));
    $this->assertSame('uncomposed', $result->getErrors()[0]->getContext()['blueprint']);
    $this->assertCount(1, $this->sections($entity), 'Nothing was staged through the dialect either.');
  }

  /**
   * Tests that a page blueprint is refused as a placement, naming the start.
   *
   * A page blueprint is a whole page's start (page_create's start, the
   * spine's PageStart) and never a placement into a page: refused
   * BLUEPRINT_WRONG_SCOPE after the lookup and before the empty check and
   * the fit (an empty page blueprint is still the start's to refuse), with
   * nothing staged; the dialect's <pb-blueprint> rides the same door and
   * answers the same code.
   */
  public function testPageBlueprintRefusedAsPlacement(): void {
    $this->createBlueprint([
      'label' => 'Whole page',
      'machine_name' => 'whole_page',
      'description' => 'A whole page: a new page\'s start.',
      'scope' => BlueprintInterface::SCOPE_PAGE,
    ]);
    $this->createBlueprint([
      'label' => 'Uncomposed page',
      'machine_name' => 'uncomposed_page',
      'description' => 'A page blueprint with no root yet.',
      'scope' => BlueprintInterface::SCOPE_PAGE,
    ], []);
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'whole_page',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('semantic', $result->getPhase());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $error = $errors[0];
    $this->assertSame('BLUEPRINT_WRONG_SCOPE', $error->getCode());
    $this->assertSame('blueprint', $error->getPath());
    $this->assertSame('Blueprint "whole_page" is a page blueprint: a whole page, never a placement into one.', $error->getMessage());
    $this->assertSame(['blueprint' => 'whole_page', 'provider' => NULL, 'scope' => 'page'], $error->getContext());
    $this->assertStringContainsString('page_create', $error->getHint());
    $this->assertStringContainsString('start: whole_page', $error->getHint());
    $this->assertStringContainsString('no layout', $error->getHint());
    $sections = $this->sections($entity);
    $this->assertCount(1, $sections, 'Nothing was staged.');
    $this->assertCount(0, $sections[0]->getComponents());

    // The scope is judged before emptiness: an empty page blueprint is the
    // start's to refuse, never the placement's.
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'uncomposed_page',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertSame(['BLUEPRINT_WRONG_SCOPE'], array_map(static fn ($e) => $e->getCode(), $result->getErrors()));

    // The page language's spelling of the same placement.
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'insert',
        'html' => '<pb-blueprint name="whole_page" />',
        'at' => ['ref' => $root_section, 'position' => 'afterend'],
      ],
    ], $this->dialectOptions(with_roster: TRUE));
    $this->assertFalse($result->isSuccess());
    $this->assertSame(['BLUEPRINT_WRONG_SCOPE'], array_map(static fn ($e) => $e->getCode(), $result->getErrors()));
    $this->assertSame('whole_page', $result->getErrors()[0]->getContext()['blueprint']);
    $this->assertStringContainsString('page_create', $result->getErrors()[0]->getHint());
    $this->assertCount(1, $this->sections($entity), 'Nothing was staged through the dialect either.');
  }

  /**
   * Tests a client-minted blueprint root uuid that is already in use.
   *
   * The placement expands into ordinary add_section/add_element operations,
   * so the root uuid meets the same minted-identity gate any create does —
   * no separate uniqueness rule for blueprints. Proving it here keeps that
   * inheritance from silently lapsing.
   */
  public function testRootUuidInUseRefused(): void {
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'cta',
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

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'bad_styles',
        'location' => ['anchor' => $root_section, 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_BLUEPRINT', $error->getCode());
    $this->assertSame('layout.children[0].children[0].styles.region:first', $error->getPath());
    $this->assertSame('bad_styles', $error->getContext()['blueprint']);
    $this->assertSame('daedalus_blueprint_library_instantiate_test', $error->getContext()['provider']);
    $this->assertCount(0, $this->sections($entity)[0]->getComponents());
  }

  /**
   * Tests that the retired region:NAME relative form is refused loudly.
   *
   * A section node still spelling region-relative styles (a module-shipped
   * blueprint file predating the container model) gets the container-vocabulary
   * corrective instead of an op-time failure on an unaddressable key.
   */
  public function testRetiredRegionRelativeStylesRefused(): void {
    ['entity' => $entity] = $this->createHost();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'region_styles',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('INVALID_BLUEPRINT', $error->getCode());
    $this->assertSame('layout.styles.region:first', $error->getPath());
    $this->assertStringContainsString('retired', $error->getMessage());
    $this->assertStringContainsString('regions are not addressable', $error->getMessage());
    $this->assertCount(0, $this->sections($entity)[0]->getComponents());
  }

  /**
   * Tests that a mid-execution semantic failure rolls the entity back.
   *
   * The exploding blueprint's add_element succeeds before its style
   * operation fails, so the rollback (not the pre-flight grammar check) is
   * what restores the entity.
   */
  public function testSemanticFailureRollsBack(): void {
    ['entity' => $entity, 'root_section' => $root_section] = $this->createHost();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'exploding',
        'location' => ['anchor' => $root_section, 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $error = $result->getErrors()[0];
    $this->assertSame('STYLE_VARIANT_NOT_FOUND', $error->getCode());
    // The nested batch's error is re-rooted onto the blueprint definition's
    // own coordinate space.
    $this->assertSame('layout.children[0].children[0].styles.variants.0.variant', $error->getPath());
    $this->assertSame('exploding', $error->getContext()['blueprint']);
    $this->assertCount(0, $this->sections($entity)[0]->getComponents());
  }

}
