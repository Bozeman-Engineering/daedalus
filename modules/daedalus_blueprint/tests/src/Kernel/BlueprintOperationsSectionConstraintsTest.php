<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the structural semantics of nested-section container operations.
 *
 * Under the one-container model a nested section is an ordinary component
 * whose configuration embeds exactly ONE Section value (the cardinality is
 * structural: there is no constraint to validate because the container
 * cannot hold more or fewer). The section-list operations therefore express
 * every gesture uniformly at any depth:
 * - add_section places before/after ANY sibling in a flow (block and
 *   section anchors alike: a region is one mixed flow, and in it the new
 *   section arrives as a container component) or 'inside' a section or the
 *   root entity; 'inside' a block is refused (a block has no interior
 *   flow), and a block beside a ROOT section is refused (the root list
 *   holds only sections).
 * - remove_element on a nested section removes its container component.
 * - move_element on sections is a pure value move: unwrapping a nested
 *   section into a top-level row and wrapping a row into a container both
 *   preserve the section's identity and content.
 *
 * Runs the real daedalus_layout stack: nested section lists only resolve through
 * NestedAwareSectionStorage, and sections are only addressable by UUID (an
 * daedalus_layout third-party setting) when daedalus_layout is installed.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsSectionConstraintsTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   */
  protected BlueprintOperations $operations;

  /**
   * The component UUID of the basic block inside the nested section.
   */
  protected string $childBlockUuid;

  /**
   * The UUID of the nested section (and its container component).
   */
  protected string $nestedSectionUuid;

  /**
   * The daedalus_layout UUID of the root section holding the container.
   */
  protected string $rootSectionUuid;

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
  }

  /**
   * Creates the fixture: an entity holding a nested-section container.
   *
   * Structure: root section (daedalus_layout UUID) > container component (uuid ==
   * embedded section uuid) > embedded section (daedalus_layout UUID) > basic inline
   * block, so the nested section is non-trivial and value moves can prove
   * content rides along.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The saved root entity.
   */
  protected function createEntityWithNestedSection(): object {
    $uuid = new PhpUuid();
    $this->nestedSectionUuid = $uuid->generate();
    $this->rootSectionUuid = $uuid->generate();
    $this->childBlockUuid = $uuid->generate();

    $child = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'basic',
      'reusable' => FALSE,
      'info' => 'Child',
    ]);

    $nested_section = $this->createSection('layout_onecol', [
      [
        'uuid' => $this->childBlockUuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('basic', $child, 'Child'),
      ],
    ]);
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
   * Hydrates the section a container component embeds, asserting it is one.
   *
   * @param object $component
   *   The SectionComponent to hydrate from.
   *
   * @return \Drupal\layout_builder\Section
   *   The embedded section.
   */
  protected function embeddedSection(object $component): Section {
    $this->assertTrue(SectionBlock::isSectionComponent($component));
    $section = SectionBlock::embeddedSection($component);
    $this->assertNotNull($section);
    return $section;
  }

  /**
   * Tests 'inside' on a block anchor is refused: no interior flow.
   */
  public function testAddSectionInsideBlockRefused(): void {
    $entity = $this->createEntityWithNestedSection();

    $result = $this->operations->addSection($this->host($entity), [
      'anchor' => $this->childBlockUuid,
      'placement' => 'inside',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $this->assertSame('INVALID_PLACEMENT_ANCHOR', $result->getErrors()[0]->getCode());

    // Nothing landed anywhere.
    $section = $entity->get('layout_builder__layout')->first()->section;
    $this->assertCount(1, $section->getComponentsByRegion('content'));
    $this->assertCount(1, $entity->get('layout_builder__layout'));
  }

  /**
   * Tests add_section anchored on a block creates a container sibling.
   *
   * The mixed-flow grammar: before/after name the position beside the
   * anchor in the flow it lives in, block anchors included — the new
   * section arrives as a container component in the block's region.
   */
  public function testAddSectionBesideBlockCreatesSibling(): void {
    $entity = $this->createEntityWithNestedSection();

    $sibling_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($this->host($entity), [
      'anchor' => $this->childBlockUuid,
      'placement' => 'after',
    ], $sibling_uuid);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    // The sibling landed beside the block INSIDE the nested section's flow.
    $section = $entity->get('layout_builder__layout')->first()->section;
    $nested = $this->embeddedSection($section->getComponent($this->nestedSectionUuid));
    $uuids = array_keys($nested->getComponentsByRegion('content'));
    $this->assertSame([$this->childBlockUuid, $sibling_uuid], $uuids);
    $embedded = $this->embeddedSection($nested->getComponent($sibling_uuid));
    $this->assertSame($sibling_uuid, $embedded->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

  /**
   * Tests a block placed before a nested container lands as its sibling.
   *
   * A nested section is an ordinary weighted component of its parent
   * region's mixed flow, so a block anchored on it with before/after lands
   * beside the container in that flow.
   */
  public function testAddBlockBesideNestedContainer(): void {
    $entity = $this->createEntityWithNestedSection();

    $block_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addElement($this->host($entity), [
      'anchor' => $this->nestedSectionUuid,
      'placement' => 'before',
    ], [
      'block_type' => 'basic',
      'uuid' => $block_uuid,
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $section = $entity->get('layout_builder__layout')->first()->section;
    $this->assertSame([$block_uuid, $this->nestedSectionUuid], array_keys($section->getComponentsByRegion('content')));
  }

  /**
   * Tests a block anchored on a ROOT section with before/after is refused.
   *
   * The root flow holds only sections; the corrective teaches the wrap
   * composition (add_section beside the anchor, then place inside).
   */
  public function testAddBlockBesideRootSectionRefused(): void {
    $entity = $this->createEntityWithNestedSection();

    $result = $this->operations->addElement($this->host($entity), [
      'anchor' => $this->rootSectionUuid,
      'placement' => 'before',
    ], [
      'block_type' => 'basic',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $this->assertSame('INVALID_PLACEMENT_ANCHOR', $result->getErrors()[0]->getCode());
    $this->assertStringContainsString('add_section', $result->getErrors()[0]->getHint());
  }

  /**
   * Tests moving a block to before a nested container in the parent flow.
   */
  public function testMoveBlockBesideNestedContainer(): void {
    $entity = $this->createEntityWithNestedSection();

    $result = $this->operations->moveElement($this->host($entity), $this->childBlockUuid, [
      'anchor' => $this->nestedSectionUuid,
      'placement' => 'before',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $section = $entity->get('layout_builder__layout')->first()->section;
    $this->assertSame([$this->childBlockUuid, $this->nestedSectionUuid], array_keys($section->getComponentsByRegion('content')));
    // The nested section no longer holds the block.
    $nested = $this->embeddedSection($section->getComponent($this->nestedSectionUuid));
    $this->assertCount(0, $nested->getComponentsByRegion('content'));
  }

  /**
   * Tests moving a section to after a block anchor in a region flow.
   */
  public function testMoveSectionBesideBlock(): void {
    $entity = $this->createEntityWithNestedSection();

    // A sibling block beside the container in the root section's flow, so
    // the container can move relative to a block anchor in the same flow.
    $block_uuid = (new PhpUuid())->generate();
    $add = $this->operations->addElement($this->host($entity), [
      'anchor' => $this->nestedSectionUuid,
      'placement' => 'after',
    ], [
      'block_type' => 'basic',
      'uuid' => $block_uuid,
    ]);
    $this->assertTrue($add->isSuccess(), print_r($add->toArray()['errors'] ?? [], TRUE));

    $result = $this->operations->moveElement($this->host($entity), $this->nestedSectionUuid, [
      'anchor' => $block_uuid,
      'placement' => 'after',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $section = $entity->get('layout_builder__layout')->first()->section;
    $this->assertSame([$block_uuid, $this->nestedSectionUuid], array_keys($section->getComponentsByRegion('content')));
    // Identity and content rode along.
    $moved = $this->embeddedSection($section->getComponent($this->nestedSectionUuid));
    $this->assertSame($this->nestedSectionUuid, $moved->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertNotNull($moved->getComponent($this->childBlockUuid));
  }

  /**
   * Tests a sibling beside a nested section is a new container at its depth.
   */
  public function testAddSectionBesideNestedSectionCreatesSibling(): void {
    $entity = $this->createEntityWithNestedSection();

    $sibling_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($this->host($entity), [
      'anchor' => $this->nestedSectionUuid,
      'placement' => 'after',
    ], $sibling_uuid);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    // Still one root row; the sibling landed in the parent section's region.
    $this->assertCount(1, $entity->get('layout_builder__layout'));
    $section = $entity->get('layout_builder__layout')->first()->section;
    $uuids = array_keys($section->getComponentsByRegion('content'));
    $this->assertSame([$this->nestedSectionUuid, $sibling_uuid], $uuids);

    // Both entries are containers whose embedded section carries the same
    // identity as the component (one identity, no mapping table).
    foreach ($uuids as $component_uuid) {
      $embedded = $this->embeddedSection($section->getComponent($component_uuid));
      $this->assertSame($component_uuid, $embedded->getThirdPartySetting('daedalus_layout', 'uuid'));
    }
  }

  /**
   * Tests 'inside' a section nests a new container into the named region.
   */
  public function testAddSectionInsideNestsContainer(): void {
    $entity = $this->createEntityWithNestedSection();

    // Nest at depth 2: inside the already-nested section.
    $grandchild_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($this->host($entity), [
      'anchor' => $this->nestedSectionUuid,
      'placement' => 'inside',
      'region' => 'content',
    ], $grandchild_uuid);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $section = $entity->get('layout_builder__layout')->first()->section;
    $nested = $this->embeddedSection($section->getComponent($this->nestedSectionUuid));
    $inner_uuids = array_keys($nested->getComponentsByRegion('content'));
    $this->assertContains($grandchild_uuid, $inner_uuids);
    $grandchild = $this->embeddedSection($nested->getComponent($grandchild_uuid));
    $this->assertSame($grandchild_uuid, $grandchild->getThirdPartySetting('daedalus_layout', 'uuid'));
    // The original child block is untouched beside the new container.
    $this->assertContains($this->childBlockUuid, $inner_uuids);
  }

  /**
   * Tests an unknown region on the anchor section is refused.
   */
  public function testAddSectionInsideUnknownRegionRefused(): void {
    $entity = $this->createEntityWithNestedSection();

    $result = $this->operations->addSection($this->host($entity), [
      'anchor' => $this->rootSectionUuid,
      'placement' => 'inside',
      'region' => 'sidebar',
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_REGION', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests removing a nested section removes its container.
   */
  public function testRemoveNestedSectionRemovesContainer(): void {
    $entity = $this->createEntityWithNestedSection();

    $result = $this->operations->removeElement($this->host($entity), $this->nestedSectionUuid);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $section = $entity->get('layout_builder__layout')->first()->section;
    $this->assertCount(0, $section->getComponentsByRegion('content'));
    $this->assertArrayNotHasKey($this->nestedSectionUuid, $section->getComponents());
  }

  /**
   * Tests moving a nested section out unwraps it into a top-level row.
   *
   * A pure value move: the section keeps its UUID and its content; only the
   * container plumbing changes (removed on the way out).
   */
  public function testMoveNestedSectionOutUnwraps(): void {
    $entity = $this->createEntityWithNestedSection();

    $result = $this->operations->moveElement($this->host($entity), $this->nestedSectionUuid, [
      'anchor' => $this->rootSectionUuid,
      'placement' => 'after',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));

    $list = $entity->get('layout_builder__layout');
    $this->assertCount(2, $list);
    $root = $list->get(0)->section;
    $moved = $list->get(1)->section;
    $this->assertSame($this->rootSectionUuid, $root->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertSame($this->nestedSectionUuid, $moved->getThirdPartySetting('daedalus_layout', 'uuid'));
    // The child block rode along with the value.
    $this->assertNotNull($moved->getComponent($this->childBlockUuid));
    // The container is gone from the old parent.
    $this->assertCount(0, $root->getComponentsByRegion('content'));
  }

  /**
   * Tests moving a top-level section inside a section wraps it.
   */
  public function testMoveRootSectionInsideWraps(): void {
    $entity = $this->createEntityWithNestedSection();

    $extra_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($this->host($entity), [
      'anchor' => $entity->uuid(),
      'placement' => 'inside',
    ], $extra_uuid);
    $this->assertTrue($result->isSuccess());
    $this->assertCount(2, $entity->get('layout_builder__layout'));

    $result = $this->operations->moveElement($this->host($entity), $extra_uuid, [
      'anchor' => $this->rootSectionUuid,
      'placement' => 'inside',
      'region' => 'content',
    ]);

    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $list = $entity->get('layout_builder__layout');
    $this->assertCount(1, $list);
    $root = $list->first()->section;
    $uuids = array_keys($root->getComponentsByRegion('content'));
    $this->assertSame([$this->nestedSectionUuid, $extra_uuid], $uuids);
    $wrapped = $this->embeddedSection($root->getComponent($extra_uuid));
    $this->assertSame($extra_uuid, $wrapped->getThirdPartySetting('daedalus_layout', 'uuid'));
  }

  /**
   * Tests root-level section operations stay unaffected.
   */
  public function testRootSectionOperationsUnaffected(): void {
    $entity = $this->createEntityWithNestedSection();

    $extra_uuid = (new PhpUuid())->generate();
    $result = $this->operations->addSection($this->host($entity), [
      'anchor' => $entity->uuid(),
      'placement' => 'inside',
    ], $extra_uuid);
    $this->assertTrue($result->isSuccess());
    $this->assertCount(2, $entity->get('layout_builder__layout'));

    $result = $this->operations->removeElement($this->host($entity), $extra_uuid);
    $this->assertTrue($result->isSuccess());
    $this->assertCount(1, $entity->get('layout_builder__layout'));
  }

  /**
   * Tests batch semantics roll the root entity back on a failure.
   *
   * The batch path is the real public surface (HTTP API and AI tools); a
   * failure mid-batch must leave the entity exactly as it was, valid
   * earlier operations included.
   */
  public function testBatchRollsBackOnFailure(): void {
    $entity = $this->createEntityWithNestedSection();
    $before = $entity->get('layout_builder__layout')->getValue();

    $result = $this->operations->batchOperations($this->host($entity), [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_section',
        'location' => ['anchor' => $this->childBlockUuid, 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('structural', $result->getPhase());
    $codes = array_map(fn($error) => $error->getCode(), $result->getErrors());
    $this->assertContains('INVALID_PLACEMENT_ANCHOR', $codes);
    // The first (valid) operation rolled back with the failing one.
    $this->assertCount(1, $entity->get('layout_builder__layout'));
    $this->assertEquals($before, $entity->get('layout_builder__layout')->getValue());
  }

}
