<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\Event\ElementMovedEvent;
use Drupal\daedalus_blueprint\Event\SectionCreatedEvent;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the section lifecycle events: add_section and move_element.
 *
 * The add_section op dispatches SectionCreatedEvent from every anchor branch
 * (root entity 'inside', sibling section 'before'/'after' at any depth,
 * section 'inside' to nest), carrying the root entity and the inserted
 * section. Sections at every depth are values on the root entity; there is
 * no entity boundary inside the tree, so the root entity is the one entity
 * the events carry.
 *
 * A section is born with its REAL layout — the default ladder's container
 * pick, or an explicitly named layout — so this one event is the seam for
 * layout-derived state; there is no layout-change event or operation.
 *
 * The move_element op dispatches exactly one ElementMovedEvent per move,
 * for sections and components alike, carrying the depth before and after:
 * root-only wear is a fact of depth, and a subscriber that strips or
 * redresses it needs both facts the operation knew while it moved.
 *
 * Runs the real daedalus_layout stack: sections are only addressable by UUID (an
 * daedalus_layout third-party setting) and nested section lists only resolve through
 * NestedAwareSectionStorage when daedalus_layout is installed.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsSectionEventsTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   */
  protected BlueprintOperations $operations;

  /**
   * The UUID of the nested section (and its container component).
   */
  protected string $nestedSectionUuid;

  /**
   * The daedalus_layout UUID of the root section holding the container.
   */
  protected string $rootSectionUuid;

  /**
   * The section-created events received in dispatch order.
   *
   * @var \Drupal\daedalus_blueprint\Event\SectionCreatedEvent[]
   */
  protected array $createdEvents = [];

  /**
   * The moved events received in dispatch order.
   *
   * @var \Drupal\daedalus_blueprint\Event\ElementMovedEvent[]
   */
  protected array $movedEvents = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'file',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_layout',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }

    $this->enableLayoutBuilder();
    $this->createBlockContentType('basic', 'Basic');

    $this->operations = $this->container->get('daedalus_blueprint.operations');

    $dispatcher = $this->container->get('event_dispatcher');
    $dispatcher->addListener(
      SectionCreatedEvent::class,
      function (SectionCreatedEvent $event): void {
        $this->createdEvents[] = $event;
      },
    );
    $dispatcher->addListener(
      ElementMovedEvent::class,
      function (ElementMovedEvent $event): void {
        $this->movedEvents[] = $event;
      },
    );
  }

  /**
   * Creates the fixture: an entity holding a nested-section container.
   *
   * Structure: root section (daedalus_layout UUID) > container component (uuid ==
   * embedded section uuid) > embedded section (daedalus_layout UUID).
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved root entity.
   */
  protected function createEntityWithNestedSection(): object {
    $uuid = new PhpUuid();
    $this->nestedSectionUuid = $uuid->generate();
    $this->rootSectionUuid = $uuid->generate();

    $nested_section = $this->createSection('layout_onecol');
    $nested_section->setThirdPartySetting('daedalus_layout', 'uuid', $this->nestedSectionUuid);

    $section = $this->createSection('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->rootSectionUuid);
    $section->appendComponent(SectionBlock::createComponent($nested_section, 'content'));

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Asserts an event carries the root entity.
   *
   * Sections at every depth are values on the root entity, so this holds
   * for every branch, nested targets included.
   *
   * @param object $event
   *   A SectionCreatedEvent.
   * @param object $entity
   *   The root entity.
   */
  protected function assertOwnerIsRoot(object $event, object $entity): void {
    $this->assertSame('entity_test', $event->getEntity()->getEntityTypeId());
    $this->assertSame($entity->uuid(), $event->getEntity()->uuid());
  }

  /**
   * Tests the root-entity branch: owner is the root entity itself.
   */
  public function testCreatedEventRootBranch(): void {
    $entity = $this->createEntityWithNestedSection();

    $section_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($entity, [
      'anchor' => $entity->uuid(),
      'placement' => 'inside',
    ], $section_uuid);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertCount(1, $this->createdEvents);
    $event = $this->createdEvents[0];
    $this->assertOwnerIsRoot($event, $entity);
    $this->assertSame($section_uuid, $event->getSectionUuid());
    $this->assertSame($section_uuid, $event->getSection()->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

  /**
   * Tests a target a listener adds to the event lands in the result.
   *
   * The operation's own targets (ADDED section, RESTRUCTURED root) come
   * first; the listener's follows, deduplicated when declared twice.
   */
  public function testCreatedEventTargetsMergeIntoResult(): void {
    $entity = $this->createEntityWithNestedSection();
    $this->container->get('event_dispatcher')->addListener(
      SectionCreatedEvent::class,
      static function (SectionCreatedEvent $event): void {
        $event->addTarget($event->getEntity()->uuid(), 'field_listener');
        $event->addTarget($event->getEntity()->uuid(), 'field_listener');
      },
    );

    $section_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($entity, [
      'anchor' => $entity->uuid(),
      'placement' => 'inside',
    ], $section_uuid);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(
      [
        ['uuid' => $section_uuid, 'field_name' => TargetType::ADDED],
        ['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED],
        ['uuid' => $entity->uuid(), 'field_name' => 'field_listener'],
      ],
      $result->getTargets(),
      'The listener target follows the operation targets once, despite being declared twice.',
    );
  }

  /**
   * Tests the sibling branch beside a root section: owner is the root.
   */
  public function testCreatedEventSiblingBranchRootList(): void {
    $entity = $this->createEntityWithNestedSection();

    $section_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($entity, [
      'anchor' => $this->rootSectionUuid,
      'placement' => 'after',
    ], $section_uuid);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertCount(1, $this->createdEvents);
    $event = $this->createdEvents[0];
    $this->assertOwnerIsRoot($event, $entity);
    $this->assertSame($section_uuid, $event->getSectionUuid());
  }

  /**
   * Tests the sibling branch beside a nested section: owner is still root.
   *
   * The sibling lands as a new container beside the anchor's container, but
   * the section is a value on the root entity like any other, so the event
   * carries the root as owner.
   */
  public function testCreatedEventSiblingBranchNestedList(): void {
    $entity = $this->createEntityWithNestedSection();

    $section_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($entity, [
      'anchor' => $this->nestedSectionUuid,
      'placement' => 'after',
    ], $section_uuid);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertCount(1, $this->createdEvents);
    $event = $this->createdEvents[0];
    $this->assertOwnerIsRoot($event, $entity);
    $this->assertSame($section_uuid, $event->getSectionUuid());

    // The sibling really is a container at the anchor's depth.
    $root = $entity->get('layout_builder__layout')->first()->section;
    $this->assertSame(
      [$this->nestedSectionUuid, $section_uuid],
      array_keys($root->getComponentsByRegion('content')),
    );
  }

  /**
   * Tests the nesting branch ('inside' a section): owner is still root.
   */
  public function testCreatedEventNestedInsideBranch(): void {
    $entity = $this->createEntityWithNestedSection();

    $section_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($entity, [
      'anchor' => $this->nestedSectionUuid,
      'placement' => 'inside',
    ], $section_uuid);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertCount(1, $this->createdEvents);
    $event = $this->createdEvents[0];
    $this->assertOwnerIsRoot($event, $entity);
    $this->assertSame($section_uuid, $event->getSectionUuid());
  }

  /**
   * Tests failed adds dispatch nothing.
   */
  public function testNoCreatedEventOnFailure(): void {
    $entity = $this->createEntityWithNestedSection();

    $result = $this->operations->addSection($entity, [
      'anchor' => '266985a2-5059-ae4c-d8ac-2dd69846853e',
      'placement' => 'after',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertCount(0, $this->createdEvents);
  }

  /**
   * Tests the event carries the REAL layout for an explicit-layout add.
   *
   * Sections are born with their layout, so subscribers keying on the
   * layout (daedalus_stylish's per-layout default variants) see the truth at the
   * one and only seam.
   */
  public function testCreatedEventCarriesExplicitLayout(): void {
    $entity = $this->createEntityWithNestedSection();

    $section_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection(
      $entity,
      ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      $section_uuid,
      'layout_twocol_section',
    );

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertCount(1, $this->createdEvents);
    $event = $this->createdEvents[0];
    $this->assertSame($section_uuid, $event->getSectionUuid());
    $this->assertSame('layout_twocol_section', $event->getSection()->getLayoutId());
  }

  /**
   * Adds a second root section after the fixture's root section.
   *
   * @param object $entity
   *   The root entity.
   *
   * @return string
   *   The new section's daedalus_layout UUID.
   */
  protected function addRootSibling(object $entity): string {
    $section_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($entity, [
      'anchor' => $this->rootSectionUuid,
      'placement' => 'after',
    ], $section_uuid);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    return $section_uuid;
  }

  /**
   * Asserts exactly one moved event fired, with the given facts.
   *
   * @param string $uuid
   *   The moved element's UUID.
   * @param bool $is_section
   *   Whether the event names a section.
   * @param bool $was_nested
   *   The depth before the move.
   * @param bool $is_nested
   *   The depth after the move.
   * @param object $entity
   *   The root entity.
   */
  protected function assertOneMovedEvent(string $uuid, bool $is_section, bool $was_nested, bool $is_nested, object $entity): void {
    $this->assertCount(1, $this->movedEvents, 'A move dispatches exactly one moved event.');
    $event = $this->movedEvents[0];
    $this->assertOwnerIsRoot($event, $entity);
    $this->assertSame($uuid, $event->getUuid());
    $this->assertSame($is_section, $event->isSection());
    $this->assertSame($was_nested, $event->wasNested(), 'wasNested');
    $this->assertSame($is_nested, $event->isNested(), 'isNested');
    $this->assertFalse($event->dressed(), 'A move is not a creation; it carries no posture.');
  }

  /**
   * Tests a root section moved into a region flow: root to nested.
   *
   * Before/after a container anchor lands in the parent section's region
   * flow, wrapping the row into a container on the way in.
   */
  public function testMovedEventSectionRootToNested(): void {
    $entity = $this->createEntityWithNestedSection();
    $section_uuid = $this->addRootSibling($entity);

    $result = $this->operations->moveElement($entity, $section_uuid, [
      'anchor' => $this->nestedSectionUuid,
      'placement' => 'after',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertOneMovedEvent($section_uuid, TRUE, FALSE, TRUE, $entity);
    $root = $entity->get('layout_builder__layout')->first()->section;
    $this->assertSame(
      [$this->nestedSectionUuid, $section_uuid],
      array_keys($root->getComponentsByRegion('content')),
      'The moved section is a container beside the anchor.',
    );
  }

  /**
   * Tests a nested section moved beside a root section: nested to root.
   */
  public function testMovedEventSectionNestedToRoot(): void {
    $entity = $this->createEntityWithNestedSection();

    $result = $this->operations->moveElement($entity, $this->nestedSectionUuid, [
      'anchor' => $this->rootSectionUuid,
      'placement' => 'after',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertOneMovedEvent($this->nestedSectionUuid, TRUE, TRUE, FALSE, $entity);
    $this->assertCount(2, $entity->get('layout_builder__layout'), 'The section unwrapped into a root row.');
  }

  /**
   * Tests a root section reordered among root sections: root to root.
   */
  public function testMovedEventSectionRootToRoot(): void {
    $entity = $this->createEntityWithNestedSection();
    $section_uuid = $this->addRootSibling($entity);

    $result = $this->operations->moveElement($entity, $section_uuid, [
      'anchor' => $this->rootSectionUuid,
      'placement' => 'before',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertOneMovedEvent($section_uuid, TRUE, FALSE, FALSE, $entity);
    $this->assertSame(
      $section_uuid,
      $entity->get('layout_builder__layout')->first()->section->getThirdPartySetting('daedalus_layout', 'uuid'),
    );
  }

  /**
   * Tests a nested section moved inside another section: nested to nested.
   *
   * The sibling container beside the fixture's container moves 'inside'
   * it, so both depth facts read nested and the 'inside' branch is the
   * one that answers the destination.
   */
  public function testMovedEventSectionNestedToNested(): void {
    $entity = $this->createEntityWithNestedSection();

    $sibling_uuid = (new PhpUuid())->generate();
    $add = $this->operations->addSection($entity, [
      'anchor' => $this->nestedSectionUuid,
      'placement' => 'after',
    ], $sibling_uuid);
    $this->assertTrue($add->isSuccess(), print_r($add->toArray()['errors'] ?? [], TRUE));

    $result = $this->operations->moveElement($entity, $sibling_uuid, [
      'anchor' => $this->nestedSectionUuid,
      'placement' => 'inside',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertOneMovedEvent($sibling_uuid, TRUE, TRUE, TRUE, $entity);
    $root = $entity->get('layout_builder__layout')->first()->section;
    $this->assertSame([$this->nestedSectionUuid], array_keys($root->getComponentsByRegion('content')));
    $nested = SectionBlock::embeddedSection($root->getComponent($this->nestedSectionUuid));
    $this->assertSame([$sibling_uuid], array_keys($nested->getComponentsByRegion('content')));
  }

  /**
   * Tests a component move carries its sections' depth: root to nested.
   */
  public function testMovedEventComponent(): void {
    $entity = $this->createEntityWithNestedSection();

    $block_uuid = (new PhpUuid())->generate();
    $add = $this->operations->addElement($entity, [
      'anchor' => $this->rootSectionUuid,
      'placement' => 'inside',
    ], [
      'block_type' => 'basic',
      'uuid' => $block_uuid,
    ]);
    $this->assertTrue($add->isSuccess(), print_r($add->toArray()['errors'] ?? [], TRUE));

    $result = $this->operations->moveElement($entity, $block_uuid, [
      'anchor' => $this->nestedSectionUuid,
      'placement' => 'inside',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertOneMovedEvent($block_uuid, FALSE, FALSE, TRUE, $entity);
    $root = $entity->get('layout_builder__layout')->first()->section;
    $nested = SectionBlock::embeddedSection($root->getComponent($this->nestedSectionUuid));
    $this->assertSame([$block_uuid], array_keys($nested->getComponentsByRegion('content')));
  }

  /**
   * Tests a target a listener adds to the moved event lands in the result.
   *
   * The operation's own target (RESTRUCTURED root) comes first; the
   * listener's follows.
   */
  public function testMovedEventTargetsMergeIntoResult(): void {
    $entity = $this->createEntityWithNestedSection();
    $this->container->get('event_dispatcher')->addListener(
      ElementMovedEvent::class,
      static function (ElementMovedEvent $event): void {
        $event->addTarget($event->getEntity()->uuid(), 'field_listener');
      },
    );

    $result = $this->operations->moveElement($entity, $this->nestedSectionUuid, [
      'anchor' => $this->rootSectionUuid,
      'placement' => 'after',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame(
      [
        ['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED],
        ['uuid' => $entity->uuid(), 'field_name' => 'field_listener'],
      ],
      $result->getTargets(),
    );
  }

  /**
   * Tests failed moves dispatch nothing.
   */
  public function testNoMovedEventOnFailure(): void {
    $entity = $this->createEntityWithNestedSection();

    $result = $this->operations->moveElement($entity, $this->nestedSectionUuid, [
      'anchor' => '266985a2-5059-ae4c-d8ac-2dd69846853e',
      'placement' => 'after',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertCount(0, $this->movedEvents);
  }

}
