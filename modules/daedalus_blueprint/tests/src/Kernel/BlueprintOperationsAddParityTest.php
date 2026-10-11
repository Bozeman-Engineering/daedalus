<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\Event\ElementCreatedEvent;
use Drupal\daedalus_blueprint\Event\SectionCreatedEvent;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\field\Entity\FieldConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the add-side palette-parity operations.
 *
 * Covers plugin-block placement (plugin_id + context-mapping defaulting),
 * the populate: 'sample' enrichment option, the component-created event
 * with the batch's dressed posture, and the add_section operation. The
 * fixture section carries a seed
 * component so block placements can speak the ONE anchor grammar without
 * daedalus_layout (section anchors need daedalus_layout section UUIDs). daedalus_layout-dependent
 * paths (section UUIDs as add_section anchors, placement inside a nested
 * section, and the PlaceBlockEvent re-fire) need the assembled stack and
 * are covered by functional JS when the placement tools cut over (task
 * 9c).
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsAddParityTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The policy layer (field_sample_value) is daedalus_blueprint's hard
   * dependency on a site, so the placement contract is tested with it
   * present.
   */
  protected static $modules = ['field_sample_value'];

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperations
   */
  protected BlueprintOperations $operations;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * The seed component every placement anchors beside.
   *
   * Section anchors require daedalus_layout section UUIDs (absent here), so the
   * fixture ships one component and the anchor grammar places after it.
   */
  protected const SEED_UUID = '7a3f08ce-2f70-4c98-8f5b-3d1f6a9be0c4';

  /**
   * Creates a Layout Builder entity with one one-column section.
   *
   * The section holds the seed component placements anchor beside.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved entity.
   */
  protected function createLbEntity() {
    $this->enableLayoutBuilder();
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $entity = $this->createTestEntity([
      'name' => 'Add parity test',
      'layout_builder__layout' => [
        $this->createSection('layout_onecol', [
          [
            'uuid' => self::SEED_UUID,
            'region' => 'content',
            'weight' => 0,
            'configuration' => [
              'id' => 'system_powered_by_block',
              'provider' => 'system',
              'label' => 'Anchor seed',
              'label_display' => '0',
            ],
          ],
        ]),
      ],
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Returns the components of the entity's first section.
   *
   * @param object $entity
   *   The entity.
   *
   * @return \Drupal\layout_builder\SectionComponent[]
   *   The components.
   */
  protected function firstSectionComponents(object $entity): array {
    return $entity->get('layout_builder__layout')->getSection(0)->getComponents();
  }

  /**
   * Tests adding a plugin block by plugin_id.
   */
  public function testAddPluginBlock(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'plugin_id' => 'system_powered_by_block',
        'uuid' => 'f4c87353-6cb7-61be-f9ca-3af919404df7',
        'location' => ['anchor' => self::SEED_UUID, 'placement' => 'after'],
      ],
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $components = $this->firstSectionComponents($result->getEntity());
    $this->assertArrayHasKey('f4c87353-6cb7-61be-f9ca-3af919404df7', $components);
    $configuration = $components['f4c87353-6cb7-61be-f9ca-3af919404df7']->get('configuration');
    $this->assertSame('system_powered_by_block', $configuration['id']);
    $this->assertSame('0', $configuration['label_display']);
    $this->assertSame(
      [['uuid' => 'f4c87353-6cb7-61be-f9ca-3af919404df7', 'field_name' => TargetType::ADDED]],
      $result->getTargets(),
    );
  }

  /**
   * Tests context-mapping defaulting for context-aware plugin blocks.
   *
   * FieldBlock requires an entity context; without an explicit mapping it
   * must default to layout_builder.entity — the palette behavior FieldBlock
   * depends on to render.
   */
  public function testAddPluginBlockContextMapping(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->addElement($this->host($entity), ['anchor' => self::SEED_UUID, 'placement' => 'after'], [
      'plugin_id' => 'field_block:entity_test:entity_test:name',
      'uuid' => 'c6dc08ba-6496-2e1f-d981-f19d62e14d1b',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $components = $this->firstSectionComponents($result->getEntity());
    $configuration = $components['c6dc08ba-6496-2e1f-d981-f19d62e14d1b']->get('configuration');
    $this->assertSame('layout_builder.entity', $configuration['context_mapping']['entity']);
  }

  /**
   * Tests the plugin-block error cases.
   */
  public function testAddPluginBlockErrors(): void {
    $entity = $this->createLbEntity();
    $location = ['anchor' => self::SEED_UUID, 'placement' => 'after'];

    // Unknown plugin ID.
    $result = $this->operations->addElement($this->host($entity), $location, ['plugin_id' => 'does_not_exist']);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_PLUGIN_ID', $result->toArray()['errors'][0]['code']);

    // Inline-block plugin IDs are placed via block_type.
    $result = $this->operations->addElement($this->host($entity), $location, ['plugin_id' => 'inline_block:text_section']);
    $this->assertFalse($result->isSuccess());
    $error = $result->toArray()['errors'][0];
    $this->assertSame('INVALID_PLUGIN_ID', $error['code']);
    $this->assertStringContainsString('block_type', $error['hint']);

    // block_type and plugin_id are mutually exclusive.
    $result = $this->operations->addElement($this->host($entity), $location, [
      'block_type' => 'text_section',
      'plugin_id' => 'system_powered_by_block',
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('AMBIGUOUS_BLOCK', $result->toArray()['errors'][0]['code']);

    // A plugin without config schema of its own takes no properties.
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'plugin_id' => 'system_powered_by_block',
        'location' => ['anchor' => self::SEED_UUID, 'placement' => 'after'],
        'properties' => ['field_heading' => 'Nope'],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('NO_PLUGIN_SCHEMA', $result->toArray()['errors'][0]['code']);

    // Unknown populate mode.
    $result = $this->operations->addElement($this->host($entity), $location, [
      'block_type' => 'text_section',
      'populate' => 'everything',
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_POPULATE', $result->toArray()['errors'][0]['code']);
  }

  /**
   * Tests populate: 'sample' enrichment on inline blocks.
   *
   * Placement fills only what the site answers (SampleFiller's placement
   * entrance): an unprovided field with a configured field_sample_value
   * generator fills, an unprovided field with no policy stays empty (core's
   * type-level sample is not a placement rung, so a dropped block never
   * arrives with a made-up value), provided fields keep their explicit
   * values, and the component view_mode resolves to one that exists for
   * the block bundle instead of InlineBlock's 'full'.
   */
  public function testPopulateSample(): void {
    $entity = $this->createLbEntity();
    // A second string field beside field_heading, with no sample policy.
    $this->createBlockField('field_subheading', 'string', 'text_section');
    $heading = FieldConfig::loadByName('block_content', 'text_section', 'field_heading');
    $heading->setThirdPartySetting('field_sample_value', 'id', 'random_string');
    $heading->setThirdPartySetting('field_sample_value', 'configuration', [
      'prevent_save' => FALSE,
      'count' => 3,
    ]);
    $heading->save();

    // No fields provided: the configured generator fills field_heading and
    // the policy-less field_subheading stays empty.
    $result = $this->operations->addElement($this->host($entity), ['anchor' => self::SEED_UUID, 'placement' => 'after'], [
      'block_type' => 'text_section',
      'uuid' => 'f0f45ea4-c594-783b-9ccb-94c896ee4891',
      'populate' => 'sample',
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $components = $this->firstSectionComponents($result->getEntity());
    $configuration = $components['f0f45ea4-c594-783b-9ccb-94c896ee4891']->get('configuration');
    $block = $this->unserializeBlock($configuration['block_serialized']);
    $this->assertNotEmpty($block->get('field_heading')->value);
    $this->assertTrue($block->get('field_subheading')->isEmpty(), 'A field with no sample policy is left empty on placement.');
    // No 'full' display exists for the bundle, so view_mode falls back.
    $this->assertSame('default', $configuration['view_mode']);

    // A provided field wins over sample population.
    $result = $this->operations->addElement($this->host($entity), ['anchor' => self::SEED_UUID, 'placement' => 'after'], [
      'block_type' => 'text_section',
      'uuid' => '1b0c68f4-6d6b-7bf8-a809-82c138f35910',
      'block_fields' => ['field_heading' => 'Explicit heading'],
      'populate' => 'sample',
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $components = $this->firstSectionComponents($result->getEntity());
    $block = $this->unserializeBlock($components['1b0c68f4-6d6b-7bf8-a809-82c138f35910']->get('configuration')['block_serialized']);
    $this->assertSame('Explicit heading', $block->get('field_heading')->value);

    // Without the flag, nothing is populated (the AI path is untouched).
    $result = $this->operations->addElement($this->host($entity), ['anchor' => self::SEED_UUID, 'placement' => 'after'], [
      'block_type' => 'text_section',
      'uuid' => '9f54008f-23e5-e778-5a33-2bbfbc916244',
    ]);
    $this->assertTrue($result->isSuccess());
    $components = $this->firstSectionComponents($result->getEntity());
    $configuration = $components['9f54008f-23e5-e778-5a33-2bbfbc916244']->get('configuration');
    $block = $this->unserializeBlock($configuration['block_serialized']);
    $this->assertTrue($block->get('field_heading')->isEmpty());
    $this->assertSame('full', $configuration['view_mode']);
  }

  /**
   * Tests the component-created event fires for every add, undressed.
   *
   * The event is not gated on populate: 'sample' (that option means field
   * sampling only); an add outside any batch is the op door and its event
   * answers dressed() FALSE.
   */
  public function testElementCreatedEvent(): void {
    $entity = $this->createLbEntity();
    $received = [];
    $this->container->get('event_dispatcher')->addListener(
      ElementCreatedEvent::class,
      function (ElementCreatedEvent $event) use (&$received): void {
        $received[] = $event;
        // Subscribers may adjust configuration before insertion.
        $configuration = $event->getComponent()->get('configuration');
        $configuration['label'] = 'Adjusted by subscriber';
        $event->getComponent()->setConfiguration($configuration);
        // A subscriber that writes a root field declares it as a target.
        $event->addTarget($event->getHost()->uuid(), 'field_listener');
      },
    );

    // No populate flag: the event fires all the same, undressed.
    $result = $this->operations->addElement($this->host($entity), ['anchor' => self::SEED_UUID, 'placement' => 'after'], [
      'block_type' => 'text_section',
      'uuid' => '035ade4f-441b-c333-29e3-1dcf67a9dbf9',
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertCount(1, $received);
    $this->assertFalse($received[0]->dressed(), 'An add outside any batch is undressed.');
    $this->assertNotNull($received[0]->getBlockContent());
    $this->assertSame(
      [
        ['uuid' => '035ade4f-441b-c333-29e3-1dcf67a9dbf9', 'field_name' => TargetType::ADDED],
        ['uuid' => $entity->uuid(), 'field_name' => 'field_listener'],
      ],
      $result->getTargets(),
      'The inline block result carries the ADDED target first and the listener target after it.',
    );
    $components = $this->firstSectionComponents($result->getEntity());
    $this->assertSame('Adjusted by subscriber', $components['035ade4f-441b-c333-29e3-1dcf67a9dbf9']->get('configuration')['label']);

    // With the flag: the same event, inline blocks carry their block
    // content entity.
    $result = $this->operations->addElement($this->host($entity), ['anchor' => self::SEED_UUID, 'placement' => 'after'], [
      'block_type' => 'text_section',
      'uuid' => 'fdc3f116-9f99-dce6-3429-3eac7ddbba73',
      'populate' => 'sample',
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertCount(2, $received);
    $this->assertFalse($received[1]->dressed());
    $this->assertNotNull($received[1]->getBlockContent());

    // Plugin blocks dispatch too, without block content.
    $result = $this->operations->addElement($this->host($entity), ['anchor' => self::SEED_UUID, 'placement' => 'after'], [
      'plugin_id' => 'system_powered_by_block',
      'uuid' => '981f76e8-8247-22ae-e9db-598865bf6ba5',
    ]);
    $this->assertTrue($result->isSuccess());
    $this->assertCount(3, $received);
    $this->assertNull($received[2]->getBlockContent());
    $this->assertSame(
      [
        ['uuid' => '981f76e8-8247-22ae-e9db-598865bf6ba5', 'field_name' => TargetType::ADDED],
        ['uuid' => $entity->uuid(), 'field_name' => 'field_listener'],
      ],
      $result->getTargets(),
      'The plugin block result carries the ADDED target first and the listener target after it.',
    );
  }

  /**
   * Tests the dressed posture rides the batch onto every created event.
   *
   * A batch declaring ['dressed' => TRUE] dresses the section and element
   * events it dispatches; a batch without the option is undressed; and an
   * enclosing dressed batch dresses a nested batch that declares nothing
   * (the nested batch here is run by a listener, the only way to nest one
   * without a compound operation).
   */
  public function testDressedBatchPosture(): void {
    $entity = $this->createLbEntity();
    $dispatcher = $this->container->get('event_dispatcher');
    $element_events = [];
    $dispatcher->addListener(
      ElementCreatedEvent::class,
      function (ElementCreatedEvent $event) use (&$element_events): void {
        $element_events[$event->getComponent()->getUuid()] = $event->dressed();
      },
    );
    $section_events = [];
    $dispatcher->addListener(
      SectionCreatedEvent::class,
      function (SectionCreatedEvent $event) use (&$section_events): void {
        $section_events[] = $event->dressed();
      },
    );

    // An undressed batch: both event kinds answer FALSE.
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'plugin_id' => 'system_powered_by_block',
        'uuid' => '2c1d9a4e-5b6f-4a70-9c1d-0e2f3a4b5c6d',
        'location' => ['anchor' => self::SEED_UUID, 'placement' => 'after'],
      ],
      ['op' => 'add_section', 'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside']],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['2c1d9a4e-5b6f-4a70-9c1d-0e2f3a4b5c6d' => FALSE], $element_events);
    $this->assertSame([FALSE], $section_events);

    // A dressed batch: both event kinds answer TRUE.
    $element_events = [];
    $section_events = [];
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'plugin_id' => 'system_powered_by_block',
        'uuid' => '3d2e0b5f-6c70-4b81-8d2e-1f3a4b5c6d7e',
        'location' => ['anchor' => self::SEED_UUID, 'placement' => 'after'],
      ],
      ['op' => 'add_section', 'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside']],
    ], ['dressed' => TRUE]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['3d2e0b5f-6c70-4b81-8d2e-1f3a4b5c6d7e' => TRUE], $element_events);
    $this->assertSame([TRUE], $section_events);

    // An enclosing dressed batch dresses a nested batch that declares
    // nothing: the listener nests an undressed add_element inside the
    // dressed batch's section add, and the nested element arrives dressed.
    $element_events = [];
    $section_events = [];
    $nested = function (SectionCreatedEvent $event): void {
      $result = $this->operations->batchOperations($event->getHost(), [
        [
          'op' => 'add_element',
          'plugin_id' => 'system_powered_by_block',
          'uuid' => '4e3f1c60-7d81-4c92-9e3f-2a4b5c6d7e8f',
          'location' => ['anchor' => self::SEED_UUID, 'placement' => 'after'],
        ],
      ]);
      $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    };
    $dispatcher->addListener(SectionCreatedEvent::class, $nested);
    $result = $this->operations->batchOperations($this->host($entity), [
      ['op' => 'add_section', 'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside']],
    ], ['dressed' => TRUE]);
    $dispatcher->removeListener(SectionCreatedEvent::class, $nested);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['4e3f1c60-7d81-4c92-9e3f-2a4b5c6d7e8f' => TRUE], $element_events, 'The nested undressed batch inherits the enclosing dressed posture.');
    $this->assertSame([TRUE], $section_events);

    // The posture does not leak past the batch: the next undressed add is
    // undressed again.
    $element_events = [];
    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_element',
        'plugin_id' => 'system_powered_by_block',
        'uuid' => '5f402d71-8e92-4da3-af40-3b5c6d7e8f90',
        'location' => ['anchor' => self::SEED_UUID, 'placement' => 'after'],
      ],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(['5f402d71-8e92-4da3-af40-3b5c6d7e8f90' => FALSE], $element_events);
  }

  /**
   * Tests adding a section inside the root entity.
   *
   * Without daedalus_layout there is no default_section setting and no section
   * UUID, so the new section is a bare one-column layout and the targets
   * carry only the root RESTRUCTURED entry.
   */
  public function testAddSectionRootInside(): void {
    $entity = $this->createLbEntity();

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $sections = $result->getEntity()->get('layout_builder__layout')->getSections();
    $this->assertCount(2, $sections);
    $this->assertSame('layout_onecol', end($sections)->getLayoutId());
    $this->assertSame(
      [['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED]],
      $result->getTargets(),
    );
  }

  /**
   * Tests the add_section error cases.
   */
  public function testAddSectionErrors(): void {
    $entity = $this->createLbEntity();

    // Anchor is required.
    $result = $this->operations->addSection($this->host($entity), ['placement' => 'inside']);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('MISSING_ANCHOR', $result->toArray()['errors'][0]['code']);

    // Placement must be valid.
    $result = $this->operations->addSection($this->host($entity), ['anchor' => $entity->uuid(), 'placement' => 'around']);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_PLACEMENT', $result->toArray()['errors'][0]['code']);

    // The root entity only supports inside.
    $result = $this->operations->addSection($this->host($entity), ['anchor' => $entity->uuid(), 'placement' => 'before']);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_PLACEMENT_ANCHOR', $result->toArray()['errors'][0]['code']);

    // Unknown anchors fail loudly.
    $result = $this->operations->addSection($this->host($entity), [
      'anchor' => '266985a2-5059-ae4c-d8ac-2dd69846853e',
      'placement' => 'after',
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('ANCHOR_NOT_FOUND', $result->toArray()['errors'][0]['code']);

    // A section beside a block is legal in the grammar (the region flow
    // holds sections as container components), but containers require
    // daedalus_layout, absent here.
    $add = $this->operations->addElement($this->host($entity), ['anchor' => self::SEED_UUID, 'placement' => 'after'], [
      'block_type' => 'text_section',
      'uuid' => 'a44ea751-8f43-361c-8603-08d54ba84ab5',
    ]);
    $this->assertTrue($add->isSuccess());
    $entity = $add->getEntity();
    $result = $this->operations->addSection($this->host($entity), [
      'anchor' => 'a44ea751-8f43-361c-8603-08d54ba84ab5',
      'placement' => 'before',
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('NESTING_UNAVAILABLE', $result->toArray()['errors'][0]['code']);

    // A block has no interior flow: 'inside' cannot anchor on it, with or
    // without daedalus_layout.
    $result = $this->operations->addSection($this->host($entity), [
      'anchor' => 'a44ea751-8f43-361c-8603-08d54ba84ab5',
      'placement' => 'inside',
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_PLACEMENT_ANCHOR', $result->toArray()['errors'][0]['code']);

    // No Layout Builder, no sections (entity_test_rev has no LB display).
    $plain = $this->createRevEntity(['name' => 'Plain']);
    $plain->save();
    $result = $this->operations->addSection($this->host($plain), ['anchor' => $plain->uuid(), 'placement' => 'inside']);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('NO_LAYOUT', $result->toArray()['errors'][0]['code']);
  }

}
