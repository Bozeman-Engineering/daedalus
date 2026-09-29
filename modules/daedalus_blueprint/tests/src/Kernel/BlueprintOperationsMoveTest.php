<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\Event\ElementMovedEvent;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the move_element operation.
 *
 * Moves reuse the anchor-relative placement grammar. This kernel test covers
 * component moves within and across root-level sections, the grammar's
 * error cases, and the root-anchored section move (the root entity with
 * 'inside' appends a section to the end of the top-level flow), which
 * needs daedalus_layout section UUIDs and so runs the daedalus_layout stack for those
 * cases only. Anchor-relative section moves and the FieldBlock context
 * remap are covered by BlueprintOperationsSectionEventsTest and the
 * functional assembled-stack tests.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsMoveTest extends BlueprintKernelTestBase {

  /**
   * Fixture component uuids, addressable by readable index.
   */
  protected const COMP = [
    1 => 'faf95c53-35e7-74e1-184e-041152031df4',
    2 => '9bc043d4-072e-d2ff-9909-5b1e2e3c9826',
    3 => '61516c9e-4914-5ac8-27df-826d6f878369',
    4 => 'b03e1710-5c9b-4458-604c-c9e1fc111ab0',
  ];

  /**
   * The blueprint operations service.
   *
   * @var \Drupal\daedalus_blueprint\BlueprintOperations
   */
  protected BlueprintOperations $operations;

  /**
   * The daedalus_layout UUID of the nested section (and its container component).
   */
  protected string $nestedSectionUuid;

  /**
   * The daedalus_layout UUID of the root section holding the container.
   */
  protected string $rootSectionUuid;

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

    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * Creates an daedalus_layout entity holding a nested-section container.
   *
   * Runs the real daedalus_layout stack: sections are only addressable by UUID (an
   * daedalus_layout third-party setting) and nested section lists only resolve
   * through NestedAwareSectionStorage when daedalus_layout is installed. Structure:
   * root section (daedalus_layout UUID) > container component (uuid == embedded
   * section uuid) > embedded section (daedalus_layout UUID). Moved events are
   * collected on $this->movedEvents.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved root entity.
   */
  protected function createDaedalusLayoutEntityWithNestedSection(): object {
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
    $this->container->get('event_dispatcher')->addListener(
      ElementMovedEvent::class,
      function (ElementMovedEvent $event): void {
        $this->movedEvents[] = $event;
      },
    );

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
   * Returns the ordered daedalus_layout UUIDs of the entity's root sections.
   *
   * @param object $entity
   *   The root entity.
   *
   * @return string[]
   *   Section UUIDs in delta order.
   */
  protected function rootSectionUuids(object $entity): array {
    $uuids = [];
    foreach ($entity->get('layout_builder__layout') as $item) {
      $uuids[] = $item->section->getThirdPartySetting('daedalus_layout', 'uuid');
    }
    return $uuids;
  }

  /**
   * Asserts exactly one moved event fired for a section, with its depths.
   *
   * @param string $uuid
   *   The moved section's UUID.
   * @param bool $was_nested
   *   The depth before the move.
   * @param bool $is_nested
   *   The depth after the move.
   */
  protected function assertOneSectionMovedEvent(string $uuid, bool $was_nested, bool $is_nested): void {
    $this->assertCount(1, $this->movedEvents, 'A move dispatches exactly one moved event.');
    $event = $this->movedEvents[0];
    $this->assertSame($uuid, $event->getUuid());
    $this->assertTrue($event->isSection());
    $this->assertSame($was_nested, $event->wasNested(), 'wasNested');
    $this->assertSame($is_nested, $event->isNested(), 'isNested');
  }

  /**
   * Creates an entity with one section holding three inline blocks.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved entity, with components self::COMP[1], self::COMP[2], and
   *   self::COMP[3] in the 'content' region.
   */
  protected function createLbEntityWithThreeComponents() {
    $this->enableLayoutBuilder();
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $components = [];
    foreach ([1, 2, 3] as $i) {
      $block = $this->entityTypeManager->getStorage('block_content')->create([
        'type' => 'text_section',
        'reusable' => FALSE,
        'field_heading' => "Block $i",
      ]);
      $components[] = [
        'uuid' => self::COMP[$i],
        'region' => 'content',
        'weight' => $i - 1,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, "Block $i"),
      ];
    }

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => [$this->createSection('layout_onecol', $components)],
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Creates an entity with two sections of two inline blocks each.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved entity: self::COMP[1]/self::COMP[2] in section 0,
   *   self::COMP[3]/self::COMP[4] in section 1, all in the 'content' region.
   */
  protected function createLbEntityWithTwoSections() {
    $this->enableLayoutBuilder();
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $sections = [];
    foreach ([[1, 2], [3, 4]] as $section_blocks) {
      $components = [];
      foreach ($section_blocks as $weight => $i) {
        $block = $this->entityTypeManager->getStorage('block_content')->create([
          'type' => 'text_section',
          'reusable' => FALSE,
          'field_heading' => "Block $i",
        ]);
        $components[] = [
          'uuid' => self::COMP[$i],
          'region' => 'content',
          'weight' => $weight,
          'configuration' => $this->createInlineBlockConfiguration('text_section', $block, "Block $i"),
        ];
      }
      $sections[] = $this->createSection('layout_onecol', $components);
    }

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => $sections,
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Returns the ordered component UUIDs of a section's region.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param int $section_delta
   *   The section delta.
   * @param string $region
   *   The region name.
   *
   * @return string[]
   *   Component UUIDs in weight order.
   */
  protected function regionOrder($entity, int $section_delta, string $region = 'content'): array {
    $section = $entity->get('layout_builder__layout')->get($section_delta)->section;
    return array_keys($section->getComponentsByRegion($region));
  }

  /**
   * Tests moving a component after a later sibling.
   *
   * The resolved index counts the element still in place, so the removal
   * shift is exercised here.
   */
  public function testMoveAfterComponent(): void {
    $entity = $this->createLbEntityWithThreeComponents();

    $result = $this->operations->moveElement($entity, self::COMP[1], [
      'anchor' => self::COMP[2],
      'placement' => 'after',
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame([self::COMP[2], self::COMP[1], self::COMP[3]], $this->regionOrder($entity, 0));
    $this->assertContains(['uuid' => self::COMP[1], 'field_name' => TargetType::ADDED], $result->getTargets());
  }

  /**
   * Tests moving a component before an earlier sibling.
   */
  public function testMoveBeforeComponent(): void {
    $entity = $this->createLbEntityWithThreeComponents();

    $result = $this->operations->moveElement($entity, self::COMP[3], [
      'anchor' => self::COMP[1],
      'placement' => 'before',
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame([self::COMP[3], self::COMP[1], self::COMP[2]], $this->regionOrder($entity, 0));
  }

  /**
   * Tests moving a component to the end of its region.
   */
  public function testMoveAfterLastComponent(): void {
    $entity = $this->createLbEntityWithThreeComponents();

    $result = $this->operations->moveElement($entity, self::COMP[1], [
      'anchor' => self::COMP[3],
      'placement' => 'after',
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame([self::COMP[2], self::COMP[3], self::COMP[1]], $this->regionOrder($entity, 0));
  }

  /**
   * Tests moving a component into another section via a component anchor.
   */
  public function testMoveAcrossSections(): void {
    $entity = $this->createLbEntityWithTwoSections();

    $result = $this->operations->moveElement($entity, self::COMP[1], [
      'anchor' => self::COMP[3],
      'placement' => 'after',
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame([self::COMP[2]], $this->regionOrder($entity, 0));
    $this->assertSame([self::COMP[3], self::COMP[1], self::COMP[4]], $this->regionOrder($entity, 1));
    // Without daedalus_layout the source section has no UUID; the root-entity
    // fallback covers the source side's re-render.
    $this->assertContains(['uuid' => self::COMP[1], 'field_name' => TargetType::ADDED], $result->getTargets());
    $this->assertContains(['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED], $result->getTargets());
  }

  /**
   * Tests a same-region move produces no source-side restructure target.
   */
  public function testSameSectionMoveTargets(): void {
    $entity = $this->createLbEntityWithThreeComponents();

    $result = $this->operations->moveElement($entity, self::COMP[1], [
      'anchor' => self::COMP[2],
      'placement' => 'after',
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame([['uuid' => self::COMP[1], 'field_name' => TargetType::ADDED]], $result->getTargets());
  }

  /**
   * Tests a cross-region move within one section emits a source-side target.
   *
   * The ADDED target maps to the destination region only, so without a
   * source-side target the source region's markup keeps a stale copy of the
   * moved block.
   */
  public function testMoveAcrossRegionsEmitsSourceTarget(): void {
    $this->enableLayoutBuilder();
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $components = [];
    foreach ([1 => 'first', 2 => 'first', 3 => 'second'] as $i => $region) {
      $block = $this->entityTypeManager->getStorage('block_content')->create([
        'type' => 'text_section',
        'reusable' => FALSE,
        'field_heading' => "Block $i",
      ]);
      $components[] = [
        'uuid' => self::COMP[$i],
        'region' => $region,
        'weight' => $i - 1,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, "Block $i"),
      ];
    }

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test Page',
      'layout_builder__layout' => [$this->createSection('layout_twocol', $components)],
    ]);
    $entity->save();

    $result = $this->operations->moveElement($entity, self::COMP[1], [
      'anchor' => self::COMP[3],
      'placement' => 'after',
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame([self::COMP[2]], $this->regionOrder($entity, 0, 'first'));
    $this->assertSame([self::COMP[3], self::COMP[1]], $this->regionOrder($entity, 0, 'second'));
    // Without daedalus_layout the section has no UUID; the root-entity fallback
    // covers the source region's re-render.
    $this->assertContains(['uuid' => self::COMP[1], 'field_name' => TargetType::ADDED], $result->getTargets());
    $this->assertContains(['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED], $result->getTargets());
  }

  /**
   * Tests a move without an anchor errors.
   */
  public function testMoveMissingAnchor(): void {
    $entity = $this->createLbEntityWithThreeComponents();

    $result = $this->operations->moveElement($entity, self::COMP[1], [
      'placement' => 'after',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('MISSING_ANCHOR', $result->getErrors()[0]->getCode());
    $this->assertSame([self::COMP[1], self::COMP[2], self::COMP[3]], $this->regionOrder($entity, 0));
  }

  /**
   * Tests anchoring a move on the element itself errors.
   */
  public function testMoveSelfAnchor(): void {
    $entity = $this->createLbEntityWithThreeComponents();

    $result = $this->operations->moveElement($entity, self::COMP[1], [
      'anchor' => self::COMP[1],
      'placement' => 'after',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_PLACEMENT_ANCHOR', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests moving a nonexistent element errors.
   */
  public function testMoveElementNotFound(): void {
    $entity = $this->createLbEntityWithThreeComponents();

    $result = $this->operations->moveElement($entity, 'c68c9561-d36d-b4fc-77ac-50039f354800', [
      'anchor' => self::COMP[1],
      'placement' => 'after',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('ELEMENT_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests moving to a nonexistent anchor errors.
   */
  public function testMoveAnchorNotFound(): void {
    $entity = $this->createLbEntityWithThreeComponents();

    $result = $this->operations->moveElement($entity, self::COMP[1], [
      'anchor' => 'c68c9561-d36d-b4fc-77ac-50039f354800',
      'placement' => 'after',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('ANCHOR_NOT_FOUND', $result->getErrors()[0]->getCode());
    $this->assertSame([self::COMP[1], self::COMP[2], self::COMP[3]], $this->regionOrder($entity, 0));
  }

  /**
   * Tests 'inside' placement on a component anchor is rejected.
   */
  public function testMoveInsideComponentAnchorRejected(): void {
    $entity = $this->createLbEntityWithThreeComponents();

    $result = $this->operations->moveElement($entity, self::COMP[1], [
      'anchor' => self::COMP[2],
      'placement' => 'inside',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_PLACEMENT_ANCHOR', $result->getErrors()[0]->getCode());
    $this->assertSame([self::COMP[1], self::COMP[2], self::COMP[3]], $this->regionOrder($entity, 0));
  }

  /**
   * Tests paragraphs are rejected with a reorder_field hint.
   */
  public function testMoveParagraphRejected(): void {
    $entity = $this->createParagraphEntity();
    $paragraph_uuid = $entity->get('field_paragraphs')->get(0)->entity->uuid();
    $anchor_uuid = $entity->get('field_paragraphs')->get(1)->entity->uuid();

    $result = $this->operations->moveElement($entity, $paragraph_uuid, [
      'anchor' => $anchor_uuid,
      'placement' => 'after',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('NOT_MOVABLE', $result->getErrors()[0]->getCode());
    $this->assertStringContainsString('reorder_field', $result->getErrors()[0]->getHint());
  }

  /**
   * Tests moving a block relative to a paragraph anchor is rejected.
   */
  public function testMoveBlockToParagraphAnchorRejected(): void {
    $this->enableEntityReferenceRevisions();
    // The field must exist before the entity object is instantiated, or the
    // entity's field definitions won't know it.
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );
    $entity = $this->createLbEntityWithThreeComponents();
    $paragraph = $this->createRevEntity(['name' => 'Para']);
    $entity->set('field_paragraphs', [['entity' => $paragraph]]);

    $result = $this->operations->moveElement($entity, self::COMP[1], [
      'anchor' => $paragraph->uuid(),
      'placement' => 'after',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_PLACEMENT_ANCHOR', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests a root section anchored on the root entity 'inside' lands last.
   *
   * The root entity is the one location the panel's entity row and the
   * dialect's root beforeend both resolve to; a root row moved there goes
   * to the end of the top-level list, its own nested content riding along.
   */
  public function testMoveRootSectionToRootInsideLandsLast(): void {
    $entity = $this->createDaedalusLayoutEntityWithNestedSection();
    $sibling_uuid = $this->addRootSibling($entity);
    $this->assertSame([$this->rootSectionUuid, $sibling_uuid], $this->rootSectionUuids($entity));

    $result = $this->operations->moveElement($entity, $this->rootSectionUuid, [
      'anchor' => $entity->uuid(),
      'placement' => 'inside',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$sibling_uuid, $this->rootSectionUuid], $this->rootSectionUuids($entity));
    $moved = $entity->get('layout_builder__layout')->get(1)->section;
    $this->assertSame([$this->nestedSectionUuid], array_keys($moved->getComponentsByRegion('content')), 'The nested section rides along with its row.');
    $this->assertContains(['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED], $result->getTargets());
    $this->assertOneSectionMovedEvent($this->rootSectionUuid, FALSE, FALSE);
  }

  /**
   * Tests a nested section anchored on the root entity unwraps and lands last.
   *
   * The depth crossing is the event's fact: nested before, root after.
   */
  public function testMoveNestedSectionToRootInsideUnwrapsAndLandsLast(): void {
    $entity = $this->createDaedalusLayoutEntityWithNestedSection();

    $result = $this->operations->moveElement($entity, $this->nestedSectionUuid, [
      'anchor' => $entity->uuid(),
      'placement' => 'inside',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $this->assertSame([$this->rootSectionUuid, $this->nestedSectionUuid], $this->rootSectionUuids($entity));
    $root = $entity->get('layout_builder__layout')->first()->section;
    $this->assertSame([], $root->getComponentsByRegion('content'), 'The container component is gone from the source region.');
    $this->assertContains(['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED], $result->getTargets());
    $this->assertOneSectionMovedEvent($this->nestedSectionUuid, TRUE, FALSE);
  }

  /**
   * Tests a block anchored on the root entity is refused with the wrap hint.
   *
   * The root flow holds only sections; clients wrap the block in a new
   * section, and the hint names that composition.
   */
  public function testMoveBlockToRootInsideRejected(): void {
    $entity = $this->createLbEntityWithThreeComponents();

    $result = $this->operations->moveElement($entity, self::COMP[1], [
      'anchor' => $entity->uuid(),
      'placement' => 'inside',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_PLACEMENT_ANCHOR', $result->getErrors()[0]->getCode());
    $this->assertStringContainsString('add_section', $result->getErrors()[0]->getHint());
    $this->assertSame([self::COMP[1], self::COMP[2], self::COMP[3]], $this->regionOrder($entity, 0));
  }

  /**
   * Tests before/after on the root entity is refused: the page has no outside.
   */
  public function testMoveSectionBesideRootRejected(): void {
    $entity = $this->createDaedalusLayoutEntityWithNestedSection();

    $result = $this->operations->moveElement($entity, $this->nestedSectionUuid, [
      'anchor' => $entity->uuid(),
      'placement' => 'after',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertEquals('INVALID_PLACEMENT_ANCHOR', $result->getErrors()[0]->getCode());
    $this->assertSame("The root entity only supports 'inside' placement.", $result->getErrors()[0]->getMessage());
    $this->assertSame([$this->rootSectionUuid], $this->rootSectionUuids($entity));
    $this->assertSame([], $this->movedEvents);
  }

  /**
   * Tests move_element through batchOperations.
   */
  public function testMoveViaBatch(): void {
    $entity = $this->createLbEntityWithThreeComponents();

    $result = $this->operations->batchOperations($entity, [
      [
        'op' => 'move_element',
        'element' => self::COMP[3],
        'location' => ['anchor' => self::COMP[1], 'placement' => 'before'],
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertSame([self::COMP[3], self::COMP[1], self::COMP[2]], $this->regionOrder($entity, 0));
  }

  /**
   * Creates an entity with two paragraphs.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The reloaded entity.
   */
  protected function createParagraphEntity() {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $para1 = $this->createRevEntity(['name' => 'Para 1', 'field_heading' => 'First']);
    $para2 = $this->createRevEntity(['name' => 'Para 2', 'field_heading' => 'Second']);

    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [
        ['entity' => $para1],
        ['entity' => $para2],
      ],
    ]);
    return $this->entityTypeManager->getStorage('entity_test')->load($entity->id());
  }

}
