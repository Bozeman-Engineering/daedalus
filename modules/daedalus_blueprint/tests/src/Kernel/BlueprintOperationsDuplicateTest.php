<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\Event\ElementDuplicatedEvent;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests BlueprintOperations::duplicateElement().
 *
 * Covers component-level clone semantics without daedalus_layout, and section
 * duplication (a uniform verb at every depth: a nested section clones into
 * a sibling container, a top-level section into a new layout row, the whole
 * subtree with fresh identity) with the real daedalus_layout stack.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsDuplicateTest extends BlueprintKernelTestBase {

  use EntityReferenceRevisionsTrait;

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
   * Sets up an LB entity with an inline block and a sibling component.
   *
   * @param bool $save_block
   *   TRUE to save the block_content so the component carries a
   *   block_revision_id instead of block_serialized.
   *
   * @return object
   *   The host entity.
   */
  protected function createEntityWithInlineBlock(bool $save_block = FALSE): object {
    $this->enableLayoutBuilder();
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->createBlockContentType('text_section', 'Text Section');
    $this->createBlockField('field_heading', 'string', 'text_section');

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'text_section',
      'reusable' => FALSE,
      'info' => 'Original',
      'field_heading' => 'Hello',
    ]);
    if ($save_block) {
      $block->save();
    }

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => '6ec8ab53-89ae-974c-2315-a0f710ad9ff3',
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('text_section', $block, 'Block'),
      ],
      [
        'uuid' => '4607044b-72e5-cd3f-19a0-a19786c4f48e',
        'region' => 'content',
        'weight' => 1,
        'configuration' => ['id' => 'system_powered_by_block'],
      ],
    ]);

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Test',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();
    return $entity;
  }

  /**
   * Tests duplicating an inline block component.
   */
  public function testDuplicateInlineBlock(): void {
    $entity = $this->createEntityWithInlineBlock();
    $this->container->get('event_dispatcher')->addListener(
      ElementDuplicatedEvent::class,
      static function (ElementDuplicatedEvent $event): void {
        $event->addTarget($event->getEntity()->uuid(), 'field_listener');
      },
    );

    $result = $this->operations->duplicateElement($entity, '6ec8ab53-89ae-974c-2315-a0f710ad9ff3');

    $this->assertTrue($result->isSuccess());
    $this->assertSame($entity, $result->getEntity());

    // The clone lands directly after the original, before the sibling.
    $section = $entity->get('layout_builder__layout')->first()->section;
    $uuids = array_keys($section->getComponentsByRegion('content'));
    $this->assertCount(3, $uuids);
    $this->assertSame('6ec8ab53-89ae-974c-2315-a0f710ad9ff3', $uuids[0]);
    $this->assertSame('4607044b-72e5-cd3f-19a0-a19786c4f48e', $uuids[2]);
    $clone_uuid = $uuids[1];
    $this->assertNotSame('6ec8ab53-89ae-974c-2315-a0f710ad9ff3', $clone_uuid);

    // The clone is reported as an added target first; the listener's
    // declared target follows it.
    $targets = $result->getTargets();
    $this->assertCount(2, $targets);
    $this->assertSame($clone_uuid, $targets[0]['uuid']);
    $this->assertSame(TargetType::ADDED, $targets[0]['field_name']);
    $this->assertSame(
      ['uuid' => $entity->uuid(), 'field_name' => 'field_listener'],
      $targets[1],
      'The listener target follows the ADDED target.',
    );

    // The cloned block content is a distinct entity with the same values.
    $adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');
    $original_block = $adapter->extractBlockContent($section->getComponent('6ec8ab53-89ae-974c-2315-a0f710ad9ff3'));
    $clone_block = $adapter->extractBlockContent($section->getComponent($clone_uuid));
    $this->assertSame('Hello', $clone_block->get('field_heading')->value);
    $this->assertNotSame($original_block, $clone_block);
    $this->assertNotSame($original_block->uuid(), $clone_block->uuid());

    // Editing the clone must not touch the original.
    $clone_block->set('field_heading', 'Changed');
    $this->assertSame('Hello', $original_block->get('field_heading')->value);
  }

  /**
   * Tests duplicating a saved inline block re-points at the duplicate.
   *
   * A saved block's component carries block_revision_id; the clone must
   * carry the duplicated entity serialized instead, or both placements
   * would edit the same saved block.
   */
  public function testDuplicateSavedInlineBlock(): void {
    $entity = $this->createEntityWithInlineBlock(TRUE);

    $result = $this->operations->duplicateElement($entity, '6ec8ab53-89ae-974c-2315-a0f710ad9ff3');
    $this->assertTrue($result->isSuccess());

    $section = $entity->get('layout_builder__layout')->first()->section;
    $clone_uuid = $result->getTargets()[0]['uuid'];
    $configuration = $section->getComponent($clone_uuid)->get('configuration');
    $this->assertNotEmpty($configuration['block_serialized']);
    $this->assertArrayNotHasKey('block_revision_id', $configuration);

    // The duplicated entity is unsaved: a new block, not the old revision.
    $clone_block = $this->unserializeBlock($configuration['block_serialized']);
    $this->assertTrue($clone_block->isNew());

    // The original component still points at its saved revision.
    $original_configuration = $section->getComponent('6ec8ab53-89ae-974c-2315-a0f710ad9ff3')->get('configuration');
    $this->assertNotEmpty($original_configuration['block_revision_id']);
  }

  /**
   * Tests duplicating a plain (non-inline) block component.
   */
  public function testDuplicatePlainBlock(): void {
    $entity = $this->createEntityWithInlineBlock();

    $result = $this->operations->duplicateElement($entity, '4607044b-72e5-cd3f-19a0-a19786c4f48e');
    $this->assertTrue($result->isSuccess());

    $section = $entity->get('layout_builder__layout')->first()->section;
    $clone_uuid = $result->getTargets()[0]['uuid'];
    $configuration = $section->getComponent($clone_uuid)->get('configuration');
    $this->assertSame('system_powered_by_block', $configuration['id']);
    $uuids = array_keys($section->getComponentsByRegion('content'));
    $this->assertSame(['6ec8ab53-89ae-974c-2315-a0f710ad9ff3', '4607044b-72e5-cd3f-19a0-a19786c4f48e', $clone_uuid], $uuids);
  }

  /**
   * Tests the client-provided UUID is honored.
   */
  public function testDuplicateWithClientUuid(): void {
    $entity = $this->createEntityWithInlineBlock();

    $result = $this->operations->duplicateElement($entity, '6ec8ab53-89ae-974c-2315-a0f710ad9ff3', 'c31b92db-9558-b11d-d41b-a911875246e9');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('c31b92db-9558-b11d-d41b-a911875246e9', $result->getTargets()[0]['uuid']);
    $section = $entity->get('layout_builder__layout')->first()->section;
    $this->assertNotNull($section->getComponent('c31b92db-9558-b11d-d41b-a911875246e9'));
  }

  /**
   * Tests duplicating an unknown UUID fails structurally.
   */
  public function testDuplicateNotFound(): void {
    $entity = $this->createEntityWithInlineBlock();

    $result = $this->operations->duplicateElement($entity, '266985a2-5059-ae4c-d8ac-2dd69846853e');

    $this->assertFalse($result->isSuccess());
    $this->assertSame('ELEMENT_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests paragraphs are rejected (duplicate is LB-only for now).
   */
  public function testDuplicateParagraphRejected(): void {
    $this->enableEntityReferenceRevisions();
    $this->createRevField('field_heading', 'string', 'entity_test_rev');
    $this->createEntityReferenceRevisionsField(
      'field_paragraphs',
      'entity_test',
      'entity_test',
      'entity_test_rev',
    );

    $paragraph = $this->createRevEntity([
      'name' => 'Para',
      'field_heading' => 'First',
    ]);
    $entity = $this->createTestEntity([
      'name' => 'Page',
      'field_paragraphs' => [['entity' => $paragraph]],
    ]);

    $result = $this->operations->duplicateElement($entity, $paragraph->uuid());

    $this->assertFalse($result->isSuccess());
    $this->assertSame('NOT_DUPLICABLE', $result->getErrors()[0]->getCode());
  }

  /**
   * Sets up an daedalus_layout entity holding a nested-section container.
   *
   * Structure: root section (daedalus_layout UUID) > container component (uuid ==
   * embedded section uuid) > embedded section (daedalus_layout UUID) > basic inline
   * block, so the duplicated subtree has every identity kind: section
   * UUIDs, a component UUID, and a block_content UUID.
   *
   * @return array{entity: object, root: string, nested: string, child: string, block: object}
   *   The saved root entity plus the fixture identities.
   */
  protected function createEntityWithNestedSection(): array {
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
    $this->operations = $this->container->get('daedalus_blueprint.operations');
    $this->createBlockContentType('basic', 'Basic');

    $uuid = new PhpUuid();
    $nested_uuid = $uuid->generate();
    $root_uuid = $uuid->generate();
    $child_uuid = $uuid->generate();

    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'basic',
      'reusable' => FALSE,
      'info' => 'Child',
    ]);

    $nested_section = $this->createSection('layout_onecol', [
      [
        'uuid' => $child_uuid,
        'region' => 'content',
        'weight' => 0,
        'configuration' => $this->createInlineBlockConfiguration('basic', $block, 'Child'),
      ],
    ]);
    $nested_section->setThirdPartySetting('daedalus_layout', 'uuid', $nested_uuid);

    $section = $this->createSection('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $root_uuid);
    $section->appendComponent(SectionBlock::createComponent($nested_section, 'content'));

    $entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'layout_builder__layout' => [$section],
    ]);
    $entity->save();

    return [
      'entity' => $entity,
      'root' => $root_uuid,
      'nested' => $nested_uuid,
      'child' => $child_uuid,
      'block' => $block,
    ];
  }

  /**
   * Tests duplicating a nested section clones the subtree beside it.
   *
   * The duplicated event carries the complete old-to-new UUID map: the map
   * is how modules keying data by placement and section UUIDs (style maps,
   * per-placement metadata) follow the clone's fresh identity. For sections
   * getClone() is NULL (the section is the identity, the container wrapper
   * is plumbing).
   */
  public function testDuplicateNestedSection(): void {
    $fixture = $this->createEntityWithNestedSection();
    $entity = $fixture['entity'];
    $adapter = $this->container->get('daedalus_blueprint.daedalus_layout_adapter');

    /** @var \Drupal\daedalus_blueprint\Event\ElementDuplicatedEvent[] $received */
    $received = [];
    $this->container->get('event_dispatcher')->addListener(
      ElementDuplicatedEvent::class,
      static function (ElementDuplicatedEvent $event) use (&$received): void {
        $received[] = $event;
        $event->addTarget($event->getEntity()->uuid(), 'field_listener');
      },
    );

    $result = $this->operations->duplicateElement($entity, $fixture['nested']);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $clone_uuid = $result->getTargets()[0]['uuid'];
    $this->assertSame(TargetType::ADDED, $result->getTargets()[0]['field_name']);
    $this->assertNotSame($fixture['nested'], $clone_uuid);
    // The section's own targets (ADDED clone, RESTRUCTURED root) come first;
    // the listener's declared target follows.
    $this->assertSame(
      [
        ['uuid' => $clone_uuid, 'field_name' => TargetType::ADDED],
        ['uuid' => $entity->uuid(), 'field_name' => TargetType::RESTRUCTURED],
        ['uuid' => $entity->uuid(), 'field_name' => 'field_listener'],
      ],
      $result->getTargets(),
      'The listener target follows the ADDED and RESTRUCTURED targets.',
    );

    $this->assertCount(1, $received);
    $event = $received[0];
    $this->assertSame($entity, $event->getEntity());
    $this->assertSame($fixture['nested'], $event->getSourceUuid());
    $this->assertNull($event->getClone());

    // The clone landed as a sibling container right after the original.
    $root = $entity->get('layout_builder__layout')->first()->section;
    $this->assertSame(
      [$fixture['nested'], $clone_uuid],
      array_keys($root->getComponentsByRegion('content')),
    );

    $map = $event->getUuidMap();
    // The duplicated section itself (== the clone's component uuid).
    $this->assertSame($clone_uuid, $map[$fixture['nested']]);
    $clone_section = SectionBlock::embeddedSection($root->getComponent($clone_uuid));
    $this->assertSame($clone_uuid, $clone_section->getThirdPartySetting('daedalus_layout', 'uuid'));
    // The child component inside the duplicated subtree.
    $this->assertArrayHasKey($fixture['child'], $map);
    $clone_child = $clone_section->getComponent($map[$fixture['child']]);
    $this->assertNotNull($clone_child);
    // The duplicated inline block_content entity.
    $clone_block = $adapter->extractBlockContent($clone_child);
    $this->assertSame($clone_block->uuid(), $map[$fixture['block']->uuid()]);
    // Every mapped identity is fresh: no old UUID maps to itself.
    foreach ($map as $old => $new) {
      $this->assertNotSame($old, $new);
    }
    // The original subtree is untouched.
    $original_section = SectionBlock::embeddedSection($root->getComponent($fixture['nested']));
    $this->assertNotNull($original_section->getComponent($fixture['child']));
  }

  /**
   * Tests duplicating a top-level section inserts a fresh row after it.
   */
  public function testDuplicateTopLevelSection(): void {
    $fixture = $this->createEntityWithNestedSection();
    $entity = $fixture['entity'];

    $result = $this->operations->duplicateElement($entity, $fixture['root']);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $clone_uuid = $result->getTargets()[0]['uuid'];
    $this->assertNotSame($fixture['root'], $clone_uuid);

    $list = $entity->get('layout_builder__layout');
    $this->assertCount(2, $list);
    $this->assertSame($fixture['root'], $list->get(0)->section->getThirdPartySetting('daedalus_layout', 'uuid'));
    $clone_row = $list->get(1)->section;
    $this->assertSame($clone_uuid, $clone_row->getThirdPartySetting('daedalus_layout', 'uuid'));

    // The whole subtree got fresh identity: the cloned row's container and
    // its embedded section are new UUIDs, not the originals.
    $clone_containers = array_keys($clone_row->getComponentsByRegion('content'));
    $this->assertCount(1, $clone_containers);
    $this->assertNotSame($fixture['nested'], $clone_containers[0]);
    $clone_nested = SectionBlock::embeddedSection($clone_row->getComponent($clone_containers[0]));
    $this->assertSame($clone_containers[0], $clone_nested->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertArrayNotHasKey($fixture['child'], $clone_nested->getComponents());
    $this->assertCount(1, $clone_nested->getComponents());
  }

  /**
   * Tests the duplicate_element batch operation.
   */
  public function testBatchDuplicateElement(): void {
    $entity = $this->createEntityWithInlineBlock();

    $result = $this->operations->batchOperations($entity, [
      [
        'op' => 'duplicate_element',
        'element' => '6ec8ab53-89ae-974c-2315-a0f710ad9ff3',
        'uuid' => '86b55147-93bb-31ae-8767-28fbb581bb20',
      ],
    ]);

    $this->assertTrue($result->isSuccess());
    $section = $entity->get('layout_builder__layout')->first()->section;
    $this->assertNotNull($section->getComponent('86b55147-93bb-31ae-8767-28fbb581bb20'));
    $this->assertCount(3, $section->getComponents());
  }

}
