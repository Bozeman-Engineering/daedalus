<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Exception\CommitRefusedException;
use Drupal\daedalus_blueprint\Host\HostInterface;
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
 * The door refuses, with a CommitRefusedException carrying messages and the
 * stage left as it was, a working copy whose stored entity was saved
 * elsewhere after the copy was taken, one that does not validate as a whole
 * entity, and one storage throws on.
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
    $this->workingNode($working)->setTitle('Staged');
    $this->assertSame('tempstore', $this->handler->persist($working, element_uuids: ['uuid-stage']));

    // The stage holds the title, the row does not, and a load answers the
    // stage.
    $this->assertTrue($this->tempstore->has($node));
    $this->assertSame('Before', $this->loadStored($node)->getTitle());
    $this->assertSame('Staged', $this->workingNode($this->handler->load('node', $node->id()))->getTitle());
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
    $this->assertSame('Staged', $this->workingNode($loaded)->getTitle());
    $this->assertSame($stored->getRevisionId(), $this->workingNode($loaded)->getRevisionId());

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
    $this->workingNode($working)->setTitle('Staged');
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
    $this->workingNode($working)->setTitle('Staged');
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
    $this->assertSame('database', $this->handler->persist($this->host($new), is_new: TRUE, element_uuids: ['uuid-new']));
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
   * Tests that a page saved elsewhere after staging refuses the commit.
   *
   * The staged copy carries the revision it was taken from through the
   * tempstore and back. A save made elsewhere in between (a new revision on
   * a bundle that revisions; the same revision with a later changed time on
   * one that does not) makes the door refuse: the other save stands, the
   * stage is still there, nothing was announced as saved. A copy taken from
   * the current row afterwards commits.
   */
  public function testCommitRefusesPageSavedElsewhere(): void {
    foreach (['page', 'note'] as $type) {
      $node = $this->createNode($type);
      $first_vid = $node->getRevisionId();

      $working = $this->handler->load('node', $node->id());
      $this->workingNode($working)->setTitle('Staged');
      $this->handler->persist($working);

      // The working copy a later request commits is the staged one, read
      // back out of the tempstore; it still says where it was taken from.
      $staged = $this->handler->load('node', $node->id());
      $this->assertNotSame($working->entity(), $staged->entity());
      $this->assertSame('Staged', $this->workingNode($staged)->getTitle());
      $this->assertEquals($first_vid, $this->workingNode($staged)->getLoadedRevisionId());

      // Someone saves the page elsewhere (the stock edit form, another
      // tool): a later second, and a new revision where the bundle says so.
      $elsewhere = $this->loadStored($node);
      $elsewhere->setTitle('Saved elsewhere');
      $elsewhere->setChangedTime($elsewhere->getChangedTime() + 60);
      if ($type === 'page') {
        $elsewhere->setNewRevision();
      }
      $elsewhere->save();
      \Drupal::state()->set('daedalus_blueprint_test.persisted', []);

      try {
        $this->handler->commit($staged);
        $this->fail("The $type commit of a stale stage was not refused.");
      }
      catch (CommitRefusedException $refusal) {
        $this->assertSame(CommitRefusedException::MOVED, $refusal->reason());
        $this->assertCount(1, $refusal->messages());
        $this->assertStringContainsString('was saved elsewhere', $refusal->messages()[0]);
        $this->assertSame($refusal->messages()[0], $refusal->getMessage());
      }

      // The other save stands, the unsaved changes are still staged, and
      // nothing was announced.
      $this->assertSame('Saved elsewhere', $this->loadStored($node)->getTitle(), $type);
      $this->assertTrue($this->tempstore->has($node), $type);
      $this->assertSame([], \Drupal::state()->get('daedalus_blueprint_test.persisted', []), $type);

      // The force_save road meets the same door.
      try {
        $this->handler->persist($this->handler->load('node', $node->id()), force_save: TRUE);
        $this->fail("The $type force_save of a stale stage was not refused.");
      }
      catch (CommitRefusedException $refusal) {
        $this->assertSame(CommitRefusedException::MOVED, $refusal->reason());
      }

      // Discard, take a fresh copy from the current row, and it commits.
      $this->tempstore->delete($node);
      $fresh = $this->handler->load('node', $node->id());
      $this->assertSame('Saved elsewhere', $this->workingNode($fresh)->getTitle());
      $this->workingNode($fresh)->setTitle('Staged again');
      $this->handler->persist($fresh);
      $this->handler->commit($this->handler->load('node', $node->id()));
      $this->assertSame('Staged again', $this->loadStored($node)->getTitle(), $type);
      $this->assertFalse($this->tempstore->has($node), $type);
    }

    // A page deleted elsewhere has nothing to save to.
    $node = $this->createNode('page');
    $working = $this->handler->load('node', $node->id());
    $this->workingNode($working)->setTitle('Staged');
    $this->handler->persist($working);
    $this->loadStored($node)->delete();
    try {
      $this->handler->commit($working);
      $this->fail('The commit of a deleted page was not refused.');
    }
    catch (CommitRefusedException $refusal) {
      $this->assertSame(CommitRefusedException::MOVED, $refusal->reason());
      $this->assertStringContainsString('was deleted', $refusal->messages()[0]);
    }
    $this->assertNull($this->loadStored($node));
  }

  /**
   * Tests that a refusal names the page by its label as plain text.
   *
   * The sentence is text for whoever shows it, a notification or the form's
   * message area, each of which escapes once: the label's quotes and
   * ampersand stand in it as themselves.
   */
  public function testMovedRefusalNamesThePageInPlainText(): void {
    $title = 'Tim\'s "Q&A"';

    $node = $this->createNode('page');
    $node->setTitle($title);
    $node->save();
    $working = $this->handler->load('node', $node->id());
    $this->handler->persist($working);
    $staged = $this->handler->load('node', $node->id());
    $elsewhere = $this->loadStored($node);
    $elsewhere->setChangedTime($elsewhere->getChangedTime() + 60);
    $elsewhere->setNewRevision();
    $elsewhere->save();
    try {
      $this->handler->commit($staged);
      $this->fail('The commit of a stale stage was not refused.');
    }
    catch (CommitRefusedException $refusal) {
      $this->assertStringStartsWith('"' . $title . '" was saved elsewhere', $refusal->messages()[0]);
      $this->assertDoesNotMatchRegularExpression('/&[#\w]+;/', $refusal->messages()[0]);
    }

    $this->loadStored($node)->delete();
    try {
      $this->handler->commit($staged);
      $this->fail('The commit of a deleted page was not refused.');
    }
    catch (CommitRefusedException $refusal) {
      $this->assertStringStartsWith('"' . $title . '" was deleted', $refusal->messages()[0]);
      $this->assertDoesNotMatchRegularExpression('/&[#\w]+;/', $refusal->messages()[0]);
    }
  }

  /**
   * Tests that whole-entity violations refuse the commit, with messages.
   *
   * The stage holds a value the entity's constraints refuse (an empty
   * required title): the door answers one plain-text message per violation
   * naming the field, saves nothing and keeps the stage. Fixing the value
   * lets it through. A new entity meets the same check.
   */
  public function testCommitRefusesInvalidEntity(): void {
    $node = $this->createNode('page');
    $working = $this->handler->load('node', $node->id());
    $this->workingNode($working)->setTitle('');
    $this->handler->persist($working);
    \Drupal::state()->set('daedalus_blueprint_test.persisted', []);

    $staged = $this->handler->load('node', $node->id());
    try {
      $this->handler->commit($staged);
      $this->fail('The commit of an invalid entity was not refused.');
    }
    catch (CommitRefusedException $refusal) {
      $this->assertSame(CommitRefusedException::INVALID, $refusal->reason());
      $this->assertCount(1, $refusal->messages());
      $this->assertStringStartsWith('Title: ', $refusal->messages()[0]);
      $this->assertSame(strip_tags($refusal->messages()[0]), $refusal->messages()[0]);
    }
    $this->assertSame('Before', $this->loadStored($node)->getTitle());
    $this->assertCount(1, $this->revisionIds($node));
    $this->assertTrue($this->tempstore->has($node));
    $this->assertSame([], \Drupal::state()->get('daedalus_blueprint_test.persisted', []));

    // Fixed in the stage, the same page commits.
    $this->workingNode($staged)->setTitle('Fixed');
    $this->handler->persist($staged);
    $this->handler->commit($this->handler->load('node', $node->id()));
    $this->assertSame('Fixed', $this->loadStored($node)->getTitle());
    $this->assertFalse($this->tempstore->has($node));

    // A new entity is validated too: nothing is created.
    $new = Node::create(['type' => 'page', 'title' => '', 'uid' => $this->authorId]);
    try {
      $this->handler->persist($this->host($new), is_new: TRUE);
      $this->fail('The commit of an invalid new entity was not refused.');
    }
    catch (CommitRefusedException $refusal) {
      $this->assertSame(CommitRefusedException::INVALID, $refusal->reason());
    }
    $this->assertTrue($new->isNew());
  }

  /**
   * Tests that a storage exception is the same refusal.
   *
   * Storage throws during the save of a valid, current working copy: the
   * door answers the typed refusal with a plain message (the exception's
   * own text stays out of it, and rides along as the previous exception),
   * nothing was saved and the stage is still there.
   */
  public function testStorageExceptionRefuses(): void {
    $node = $this->createNode('page');
    $working = $this->handler->load('node', $node->id());
    $this->workingNode($working)->setTitle('Staged');
    $this->handler->persist($working);
    \Drupal::state()->set('daedalus_blueprint_test.persisted', []);

    \Drupal::state()->set('daedalus_blueprint_test.fail_save', TRUE);
    try {
      $this->handler->commit($this->handler->load('node', $node->id()));
      $this->fail('A storage exception at commit was not a refusal.');
    }
    catch (CommitRefusedException $refusal) {
      $this->assertSame(CommitRefusedException::STORAGE, $refusal->reason());
      $this->assertCount(1, $refusal->messages());
      $this->assertStringContainsString('The save failed', $refusal->messages()[0]);
      $this->assertStringNotContainsString('The test made storage fail.', $refusal->getMessage());
      $this->assertInstanceOf(EntityStorageException::class, $refusal->getPrevious());
    }
    \Drupal::state()->delete('daedalus_blueprint_test.fail_save');

    $this->assertSame('Before', $this->loadStored($node)->getTitle());
    $this->assertCount(1, $this->revisionIds($node));
    $this->assertTrue($this->tempstore->has($node));
    $this->assertSame([], \Drupal::state()->get('daedalus_blueprint_test.persisted', []));

    // The stage is intact: the same working copy commits once storage does.
    $this->handler->commit($this->handler->load('node', $node->id()));
    $this->assertSame('Staged', $this->loadStored($node)->getTitle());
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
   * The node a working copy holds.
   */
  protected function workingNode(HostInterface $host): NodeInterface {
    $node = $host->entity();
    assert($node instanceof NodeInterface);
    return $node;
  }

  /**
   * Loads the stored row of a node, bypassing every cache and the stage.
   */
  protected function loadStored(NodeInterface $node): ?NodeInterface {
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
