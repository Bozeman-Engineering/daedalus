<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the commit door of the storage handler.
 *
 * The storage handler owns both ends of staging. persist() stages the working
 * copy into daedalus_tempstore; commit() saves that working copy to the
 * database as-is (no field filter, no copy onto a fresh load), applies the
 * bundle's revision policy, clears the stage and fires the persisted hook
 * with saved TRUE. The AI tools' save context (persist with force_save) and
 * the create route (persist with is_new) go through the same door, so they
 * clear the stage and honor the revision policy without knowing about
 * either.
 *
 * Fixture: a node bundle whose type says new_revision (the default) and one
 * that does not, daedalus_tempstore installed so persist() stages, and the
 * daedalus_blueprint_test module recording every persisted-hook invocation
 * into state.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(EntityStorageHandler::class)]
#[Group('daedalus_blueprint')]
class EntityStorageHandlerCommitTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'daedalus_blueprint_test',
  ];

  /**
   * The storage handler under test.
   */
  protected EntityStorageHandler $handler;

  /**
   * The entity tempstore repository, the stage the handler writes.
   */
  protected EntityTempstoreRepository $tempstore;

  /**
   * The user every node is created by, distinct from the committer.
   */
  protected int $authorId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'page', 'name' => 'Page', 'new_revision' => TRUE])->save();
    NodeType::create(['type' => 'note', 'name' => 'Note', 'new_revision' => FALSE])->save();

    // daedalus_tempstore's container needs layout_builder; with the
    // repository present the handler stages instead of saving.
    $this->enableLayoutBuilder();
    $this->enableModules(['daedalus_tempstore']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');

    $this->handler = $this->container->get('daedalus_blueprint.storage_handler');
    $this->tempstore = $this->container->get('daedalus_tempstore.entity_tempstore_repository');
    $this->authorId = (int) $this->createUser()->id();
    \Drupal::state()->set('daedalus_blueprint_test.persisted', []);
  }

  /**
   * Tests that commit() saves the staged working copy and ends the stage.
   *
   * Stage a title through persist(), commit the working copy: the stored row
   * carries the staged title, a load afterwards answers the row with no
   * stage left, the persisted hook saw saved TRUE with the element UUIDs, and
   * because the bundle says new_revision the row is a new revision stamped
   * to the committer with the log cleared.
   */
  public function testCommitSavesTheWorkingCopyAndEndsTheStage(): void {
    $node = $this->createNode('page');
    $first_vid = $node->getRevisionId();

    $working = $this->handler->load('node', $node->id());
    $working->setTitle('Staged');
    $this->assertSame('tempstore', $this->handler->persist($working, element_uuids: ['uuid-stage']));

    // The stage holds the title, the row does not, and a load answers the
    // stage.
    $this->assertTrue($this->tempstore->has($node));
    $this->assertSame('Before', $this->loadStored($node)->getTitle());
    $this->assertSame('Staged', $this->handler->load('node', $node->id())->getTitle());
    $log = \Drupal::state()->get('daedalus_blueprint_test.persisted', []);
    $this->assertCount(1, $log);
    $this->assertFalse($log[0]['saved']);
    \Drupal::state()->set('daedalus_blueprint_test.persisted', []);

    $this->handler->commit($working, ['uuid-commit']);

    // The stored row carries the staged title and the stage is gone: a load
    // through the handler answers the row itself (same revision as stored).
    $stored = $this->loadStored($node);
    $this->assertSame('Staged', $stored->getTitle());
    $this->assertFalse($this->tempstore->has($node), 'commit() cleared the stage.');
    $loaded = $this->handler->load('node', $node->id());
    $this->assertSame('Staged', $loaded->getTitle());
    $this->assertSame($stored->getRevisionId(), $loaded->getRevisionId());

    // The persisted hook saw the commit as a save with the element UUIDs.
    $log = \Drupal::state()->get('daedalus_blueprint_test.persisted', []);
    $this->assertCount(1, $log);
    $this->assertSame('node', $log[0]['entity_type']);
    $this->assertSame($node->id(), $log[0]['entity_id']);
    $this->assertTrue($log[0]['saved']);
    $this->assertSame(['uuid-commit'], $log[0]['element_uuids']);

    // new_revision on: a second revision, attributed to the committer (the
    // current user, not the node's author), at the request time, with no
    // log carried forward from the first revision.
    $this->assertCount(2, $this->revisionIds($node));
    $this->assertGreaterThan($first_vid, $stored->getRevisionId());
    $this->assertSame((int) $this->container->get('current_user')->id(), (int) $stored->getRevisionUserId());
    $this->assertNotSame($this->authorId, (int) $stored->getRevisionUserId());
    $this->assertSame($this->container->get('datetime.time')->getRequestTime(), (int) $stored->getRevisionCreationTime());
    $this->assertSame('', (string) $stored->getRevisionLogMessage());
  }

  /**
   * Tests the revision policy off, and the persist routes through the door.
   *
   * A bundle without new_revision commits onto the current revision. A
   * persist with force_save on a staged entity is a commit: the row carries
   * the stage, the stage is cleared, the revision policy applied, the hook
   * saw saved TRUE. A persist with is_new commits the new entity and leaves
   * no stage behind.
   */
  public function testRevisionPolicyOffAndPersistRoutesThroughCommit(): void {
    // new_revision off: the commit writes the current revision.
    $note = $this->createNode('note');
    $note_vid = $note->getRevisionId();
    $working = $this->handler->load('node', $note->id());
    $working->setTitle('Staged');
    $this->handler->persist($working);
    $this->handler->commit($working);
    $stored = $this->loadStored($note);
    $this->assertSame('Staged', $stored->getTitle());
    $this->assertSame($note_vid, $stored->getRevisionId());
    $this->assertCount(1, $this->revisionIds($note));
    $this->assertFalse($this->tempstore->has($note));

    // force_save on a staged entity routes through commit(): the stage is
    // cleared and the bundle's revision policy applies.
    $page = $this->createNode('page');
    $page_vid = $page->getRevisionId();
    $working = $this->handler->load('node', $page->id());
    $working->setTitle('Staged');
    $this->handler->persist($working);
    $this->assertTrue($this->tempstore->has($page));
    \Drupal::state()->set('daedalus_blueprint_test.persisted', []);

    $this->assertSame('database', $this->handler->persist($working, force_save: TRUE, element_uuids: ['uuid-force']));

    $stored = $this->loadStored($page);
    $this->assertSame('Staged', $stored->getTitle());
    $this->assertFalse($this->tempstore->has($page), 'force_save cleared the stage.');
    $this->assertGreaterThan($page_vid, $stored->getRevisionId());
    $this->assertCount(2, $this->revisionIds($page));
    $this->assertSame((int) $this->container->get('current_user')->id(), (int) $stored->getRevisionUserId());
    $log = \Drupal::state()->get('daedalus_blueprint_test.persisted', []);
    $this->assertCount(1, $log);
    $this->assertTrue($log[0]['saved']);
    $this->assertSame(['uuid-force'], $log[0]['element_uuids']);

    // is_new routes through commit() too: the entity is saved, announced as
    // saved, and no stage exists for it.
    \Drupal::state()->set('daedalus_blueprint_test.persisted', []);
    $new = Node::create(['type' => 'page', 'title' => 'New', 'uid' => $this->authorId]);
    $this->assertSame('database', $this->handler->persist($new, is_new: TRUE, element_uuids: ['uuid-new']));
    $this->assertNotNull($new->id());
    $this->assertSame('New', $this->loadStored($new)->getTitle());
    $this->assertFalse($this->tempstore->has($new));
    $this->assertCount(1, $this->revisionIds($new));
    $log = \Drupal::state()->get('daedalus_blueprint_test.persisted', []);
    $this->assertCount(1, $log);
    $this->assertTrue($log[0]['saved']);
    $this->assertSame(['uuid-new'], $log[0]['element_uuids']);
  }

  /**
   * Creates and saves a node titled "Before", authored by the fixture user.
   *
   * The first revision is attributed to the author with a log message, so
   * the commit's stamping is observable as a change.
   */
  protected function createNode(string $type): NodeInterface {
    $node = Node::create([
      'type' => $type,
      'title' => 'Before',
      'uid' => $this->authorId,
      'revision_uid' => $this->authorId,
      'revision_log' => 'The first revision',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Loads the stored row of a node, bypassing every cache and the stage.
   */
  protected function loadStored(NodeInterface $node): NodeInterface {
    return $this->entityTypeManager->getStorage('node')->loadUnchanged($node->id());
  }

  /**
   * The revision IDs stored for a node.
   *
   * @return int[]
   *   The revision IDs.
   */
  protected function revisionIds(NodeInterface $node): array {
    return array_values($this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->allRevisions()
      ->condition('nid', $node->id())
      ->execute());
  }

}
