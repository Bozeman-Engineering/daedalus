<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_tempstore\Kernel;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\daedalus_tempstore\History\HistoryEntryRefusedException;
use Drupal\daedalus_tempstore\History\HistoryManager;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Drupal\daedalus_tempstore\History\HistoryStore;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests history capture, undo/redo restore, and timeline lifecycle.
 *
 * Exercises the entity-strategy path end to end: the strategy write tee →
 * recorder coalescing → baseline creation → pointer moves through the
 * manager → restore through the (suppressed) strategy. The layout-strategy
 * path shares the identical tee and restore code and is exercised for real
 * by the FJS suites; its defaults envelope (a display host staged as its
 * defaults storage) is pinned here, since no browser drives a template yet.
 */
#[Group('daedalus_tempstore')]
#[RunTestsInSeparateProcesses]
class HistoryTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    'filter',
    'daedalus_tempstore',
    'entity_test',
  ];

  /**
   * The Layout Builder enabled display a defaults envelope stages.
   */
  protected const DISPLAY_ID = 'entity_test.entity_test.default';

  /**
   * The entity tempstore repository.
   *
   * @var \Drupal\daedalus_tempstore\EntityTempstoreRepository
   */
  protected $repository;

  /**
   * The history recorder.
   *
   * @var \Drupal\daedalus_tempstore\History\HistoryRecorder
   */
  protected $recorder;

  /**
   * The history manager.
   *
   * @var \Drupal\daedalus_tempstore\History\HistoryManager
   */
  protected $manager;

  /**
   * The saved entity whose staged edits the tests undo and redo.
   *
   * @var \Drupal\entity_test\Entity\EntityTest
   */
  protected $entity;

  /**
   * The saved entity's host, what the manager keys the timeline by.
   */
  protected HostInterface $host;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->setUpCurrentUser();

    $this->repository = $this->container->get('daedalus_tempstore.entity_tempstore_repository');
    $this->recorder = $this->container->get('daedalus_tempstore.history_recorder');
    $this->manager = $this->container->get('daedalus_tempstore.history_manager');

    $this->entity = EntityTest::create(['name' => 'saved']);
    $this->entity->save();
    $this->host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($this->entity);
  }

  /**
   * Stages a rename in tempstore as one "request" (write + flush).
   */
  protected function stage(string $name, ?string $label = NULL): void {
    $entity = clone $this->entity;
    $entity->set('name', $name);
    if ($label !== NULL) {
      $this->recorder->annotate($label);
    }
    $this->repository->set($entity);
    $this->recorder->flush();
  }

  /**
   * The staged name as a fresh read sees it, or NULL when nothing is staged.
   */
  protected function stagedName(): ?string {
    if (!$this->repository->has($this->entity)) {
      return NULL;
    }
    return $this->repository->get($this->entity)->get('name')->value;
  }

  /**
   * Capture creates a baseline plus one coalesced entry per flush.
   */
  public function testCaptureCoalescesPerFlush(): void {
    // Two writes before one flush = one logical mutation (a batch).
    $entity = clone $this->entity;
    $entity->set('name', 'first');
    $this->repository->set($entity);
    $entity = clone $this->entity;
    $entity->set('name', 'second');
    $this->repository->set($entity);
    $this->recorder->flush();

    $metadata = $this->manager->metadata($this->host);
    $this->assertCount(2, $metadata['entries'], 'One baseline + one coalesced entry.');
    $this->assertTrue((bool) $metadata['entries'][0]['baseline']);
    $this->assertSame(1, $metadata['pointer']);
    $this->assertTrue($metadata['can_undo']);
    $this->assertFalse($metadata['can_redo']);
    // The coalesced entry holds the LAST write's state.
    $this->assertSame('second', $this->stagedName());
    $this->assertGreaterThan(0, $metadata['entries'][1]['bytes'], 'Snapshot size is measured.');
  }

  /**
   * Undo and redo walk the pointer and restore staged state faithfully.
   */
  public function testUndoRedoRoundTrip(): void {
    $this->stage('one');
    $this->stage('two');
    $this->assertSame('two', $this->stagedName());

    $result = $this->manager->undo($this->host);
    $this->assertSame(1, $result['pointer']);
    $this->assertSame('one', $this->stagedName());
    $this->assertNotEmpty($result['hosts'], 'Restore reports the affected host.');

    // Undo to the baseline: nothing was staged before the first edit, so
    // the tempstore entry disappears and reads fall back to the saved
    // entity.
    $result = $this->manager->undo($this->host);
    $this->assertSame(0, $result['pointer']);
    $this->assertNull($this->stagedName());
    $this->assertNull($this->manager->undo($this->host), 'Nothing further to undo.');

    $result = $this->manager->redo($this->host);
    $this->assertSame(1, $result['pointer']);
    $this->assertSame('one', $this->stagedName());

    $result = $this->manager->redo($this->host);
    $this->assertSame(2, $result['pointer']);
    $this->assertSame('two', $this->stagedName());
    $this->assertNull($this->manager->redo($this->host), 'Nothing further to redo.');
  }

  /**
   * A restore is not itself captured as history.
   */
  public function testRestoreIsNotRecorded(): void {
    $this->stage('one');
    $this->stage('two');
    $this->manager->undo($this->host);
    $this->recorder->flush();

    $metadata = $this->manager->metadata($this->host);
    $this->assertCount(3, $metadata['entries'], 'Baseline + two edits; the undo added nothing.');
    $this->assertSame(1, $metadata['pointer']);
  }

  /**
   * A suppressed write to a timeline with no history creates no timeline.
   *
   * Entering edit mode seeds tempstore without a user mutation (core's
   * PrepareLayout subscriber); suppressed, it must not manufacture a
   * baseline plus a phantom "Edited content" entry.
   */
  public function testSuppressedSeedCreatesNoTimeline(): void {
    $entity = clone $this->entity;
    $this->recorder->suppress(fn() => $this->repository->set($entity));
    $this->recorder->flush();

    $this->assertSame([], $this->manager->metadata($this->host)['entries']);
    $this->assertSame('saved', $this->stagedName(), 'The seed itself still landed in tempstore.');
  }

  /**
   * A suppressed write while undone never truncates the redo tail.
   *
   * The bug shape: undoing to an ABSENT baseline deletes the tempstore key,
   * the response render re-seeds it (PrepareLayout), and recording that
   * write would truncate every entry above the pointer at the fallback
   * flush — wiping the timeline the restore just moved.
   */
  public function testSuppressedWriteRecordsNothing(): void {
    $this->stage('one');
    $this->stage('two');
    $this->manager->restoreTo($this->host, 0);
    $this->assertNull($this->stagedName());

    // The restore response's render re-stages the page.
    $entity = clone $this->entity;
    $this->recorder->suppress(fn() => $this->repository->set($entity));
    $this->recorder->flush();

    $metadata = $this->manager->metadata($this->host);
    $this->assertCount(3, $metadata['entries'], 'Baseline + both edits survive.');
    $this->assertSame(0, $metadata['pointer']);
    $this->assertTrue($metadata['can_redo'], 'The future is still reachable.');
    $this->manager->redo($this->host);
    $this->assertSame('one', $this->stagedName());
  }

  /**
   * A new mutation while undone truncates the redo tail (linear history).
   */
  public function testMutationTruncatesRedoTail(): void {
    $this->stage('one');
    $this->stage('two');
    $this->manager->undo($this->host);

    $this->stage('three');
    $metadata = $this->manager->metadata($this->host);
    $this->assertFalse($metadata['can_redo'], 'The undone future is unreachable.');
    $this->assertSame(['Initial state', 'Edited content', 'Edited content'], array_column($metadata['entries'], 'label'));
    $this->assertSame([0, 1, 3], array_column($metadata['entries'], 'seq'), 'Seq 2 was truncated; sequence numbers never recycle.');
    $this->assertSame('three', $this->stagedName());
  }

  /**
   * Annotated labels win; un-annotated entries derive a fallback.
   */
  public function testEntryLabels(): void {
    $this->stage('one', 'Renamed the widget');
    $this->stage('two');

    $labels = array_column($this->manager->metadata($this->host)['entries'], 'label');
    $this->assertSame(['Initial state', 'Renamed the widget', 'Edited content'], $labels);
  }

  /**
   * A derived label is plain text: it holds the field's name as written.
   *
   * The History panel and the undo notice each escape the label once where
   * they show it, so the label itself carries no entity.
   */
  public function testDerivedLabelIsPlainText(): void {
    $field = 'Tim\'s "Q&A"';
    $entity = clone $this->entity;
    $entity->set('name', 'renamed');
    $this->recorder->annotate(NULL, [['uuid' => $this->entity->uuid(), 'field_name' => $field]]);
    $this->repository->set($entity);
    $this->recorder->flush();

    $label = $this->manager->metadata($this->host)['entries'][1]['label'];
    $this->assertSame('Edited ' . $field, $label);
    $this->assertDoesNotMatchRegularExpression('/&[#a-z0-9]+;/i', $label);
  }

  /**
   * Deleting staged state (save/discard) clears the timeline with it.
   */
  public function testDeleteClearsTimeline(): void {
    $this->stage('one');
    $this->assertNotEmpty($this->manager->metadata($this->host)['entries']);

    $this->repository->delete($this->entity);
    $metadata = $this->manager->metadata($this->host);
    $this->assertSame([], $metadata['entries']);
    $this->assertFalse($metadata['can_undo']);
  }

  /**
   * The ring stays bounded, keeps the baseline, drops the oldest entries.
   */
  public function testRingTrim(): void {
    $total = HistoryRecorder::MAX_ENTRIES + 5;
    for ($i = 1; $i <= $total; $i++) {
      $this->stage("edit $i");
    }

    $metadata = $this->manager->metadata($this->host);
    $this->assertCount(HistoryRecorder::MAX_ENTRIES, $metadata['entries']);
    $this->assertTrue((bool) $metadata['entries'][0]['baseline'], 'The baseline survives trimming.');
    $this->assertSame($total, $metadata['pointer'], 'The newest entry is current.');
    // The oldest non-baseline entries were dropped; the survivors restore.
    $sequence_numbers = array_column($metadata['entries'], 'seq');
    $this->assertNotContains(1, $sequence_numbers);
    $this->manager->undo($this->host);
    $this->assertSame('edit ' . ($total - 1), $this->stagedName());
    // Jumping straight to the baseline still works.
    $this->manager->restoreTo($this->host, 0);
    $this->assertNull($this->stagedName());
  }

  /**
   * A display stages as its defaults storage: one key for edits and history.
   *
   * The defaults envelope (['section_storage' => a defaults storage
   * carrying the display]) rides the layout strategy: the display host's
   * timeline is the storage's, an undo to the absent baseline ends the
   * stage, and a redo restores the display with its sections.
   */
  public function testDefaultsEnvelopeTimeline(): void {
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->save();
    $display = LayoutBuilderEntityViewDisplay::load(self::DISPLAY_ID);
    $host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display);
    $timeline = $this->manager->timelineFor($host);
    $this->assertSame('layout_builder.section_storage.defaults:' . self::DISPLAY_ID, $timeline, 'The timeline is the defaults storage\'s key, with no workspace suffix.');

    // Stage a section on the display, as one request.
    $display->appendSection(new Section('layout_onecol'));
    $this->repository->set($display);
    $this->recorder->flush();
    $this->assertTrue($this->repository->has($display));
    $staged = $this->repository->get(LayoutBuilderEntityViewDisplay::load(self::DISPLAY_ID));
    $this->assertInstanceOf(LayoutBuilderEntityViewDisplay::class, $staged, 'A get by display answers the staged display.');
    $this->assertCount(1, $staged->getSections());

    $metadata = $this->manager->metadata($host);
    $this->assertCount(2, $metadata['entries'], 'An absent baseline plus the staged edit.');
    $this->assertSame(1, $metadata['pointer']);

    $result = $this->manager->undo($host);
    $this->assertSame(0, $result['pointer']);
    $this->assertFalse($this->repository->has($display), 'Undo to the absent baseline ends the stage.');
    $this->assertInstanceOf(DisplayHost::class, $result['hosts'][0], 'The restore reports the display host.');

    $result = $this->manager->redo($host);
    $this->assertSame(1, $result['pointer']);
    $this->assertInstanceOf(DisplayHost::class, $result['hosts'][0]);
    $this->assertCount(1, $this->repository->get(LayoutBuilderEntityViewDisplay::load(self::DISPLAY_ID))->getSections(), 'Redo restores the staged display.');

    // Save or discard ends the timeline with the stage.
    $this->repository->delete($display);
    $this->assertSame([], $this->manager->metadata($host)['entries']);
  }

  /**
   * A page has one timeline: every editor sees it, and entries name who.
   */
  public function testTimelineIsSharedAndEntriesNameTheirAuthor(): void {
    $first = $this->setUpCurrentUser(['name' => 'first_editor']);
    $this->stage('one');

    $second = $this->setUpCurrentUser(['name' => 'second_editor']);
    $metadata = $this->manager->metadata($this->host);
    $this->assertCount(2, $metadata['entries'], 'The second editor sees the first editor\'s change.');
    $this->stage('two');

    $entries = $this->manager->metadata($this->host)['entries'];
    $this->assertSame([NULL, (int) $first->id(), (int) $second->id()], array_column($entries, 'uid'), 'The baseline is nobody\'s; each change records who made it.');
    $this->assertSame([NULL, 'first_editor', 'second_editor'], array_column($entries, 'author'));

    // Undo is "undo the last change to this page", whoever made it: the
    // first editor steps back over the second editor's change.
    $this->setCurrentUser($first);
    $result = $this->manager->undo($this->host);
    $this->assertSame(1, $result['pointer']);
    $this->assertSame('one', $this->stagedName());
    // The result names the change taken back (the entry the undo left),
    // not the entry it landed on.
    $this->assertSame('undo', $result['direction']);
    $this->assertSame(
      [['seq' => 2, 'label' => $entries[2]['label'], 'uid' => (int) $second->id(), 'author' => 'second_editor']],
      $result['changes'],
    );

    // A redo names the change it put back; a jump names every change it
    // crossed, in the order it took them.
    $result = $this->manager->redo($this->host);
    $this->assertSame('redo', $result['direction']);
    $this->assertSame([2], array_column($result['changes'], 'seq'));
    $this->assertSame(['second_editor'], array_column($result['changes'], 'author'));
    $result = $this->manager->restoreTo($this->host, 0);
    $this->assertSame('undo', $result['direction']);
    $this->assertSame([2, 1], array_column($result['changes'], 'seq'));
    $this->assertSame(['second_editor', 'first_editor'], array_column($result['changes'], 'author'));
    $result = $this->manager->restoreTo($this->host, 2);
    $this->assertSame('redo', $result['direction']);
    $this->assertSame([1, 2], array_column($result['changes'], 'seq'));
    $this->manager->undo($this->host);

    // The pointer moved for everyone.
    $this->setCurrentUser($second);
    $metadata = $this->manager->metadata($this->host);
    $this->assertSame(1, $metadata['pointer']);
    $this->assertTrue($metadata['can_redo']);
  }

  /**
   * One editor ending the stage (save or discard) ends everyone's history.
   */
  public function testDeleteClearsTheTimelineForEveryEditor(): void {
    $first = $this->setUpCurrentUser();
    $this->stage('one');
    $this->stage('two');

    $this->setUpCurrentUser();
    $this->repository->delete($this->entity);

    $this->setCurrentUser($first);
    $metadata = $this->manager->metadata($this->host);
    $this->assertSame([], $metadata['entries'], 'No entry of the ended stage is left to restore.');
    $this->assertNull($this->manager->undo($this->host));
    $this->assertNull($this->manager->restoreTo($this->host, 0));
    $this->assertNull($this->stagedName());
    $this->assertSame([], $this->container->get('keyvalue.expirable')->get(HistoryStore::COLLECTION)->getAll(), 'The index and every snapshot are gone.');
  }

  /**
   * An entry's author who no longer exists is answered as no name.
   */
  public function testDeletedAuthorHasNoName(): void {
    $author = $this->setUpCurrentUser();
    $this->stage('one');
    $this->setUpCurrentUser();
    $this->installSchema('user', ['users_data']);
    $author->delete();

    $entry = $this->manager->metadata($this->host)['entries'][1];
    $this->assertSame((int) $author->id(), $entry['uid']);
    $this->assertNull($entry['author']);

    // A restore over that change names it the same way.
    $change = $this->manager->undo($this->host)['changes'][0];
    $this->assertSame((int) $author->id(), $change['uid']);
    $this->assertNull($change['author']);
  }

  /**
   * A label is kept to the recorder's length ceiling.
   */
  public function testLabelIsCapped(): void {
    $this->stage('one', str_repeat('é', HistoryRecorder::MAX_LABEL_LENGTH * 4));

    $label = $this->manager->metadata($this->host)['entries'][1]['label'];
    $this->assertSame(HistoryRecorder::MAX_LABEL_LENGTH, mb_strlen($label));
    $this->assertStringStartsWith('ééé', $label);

    // A label within the ceiling is kept whole.
    $this->stage('two', 'Moved Heading');
    $this->assertSame('Moved Heading', $this->manager->metadata($this->host)['entries'][2]['label']);
  }

  /**
   * A write whose snapshot is over the byte ceiling is refused whole.
   */
  public function testOversizeEntryIsRefused(): void {
    $this->stage('one');

    // Random bytes as base64 do not compress below three quarters of their
    // length, so this snapshot is over the ceiling.
    $entity = clone $this->entity;
    $entity->set('name', base64_encode(random_bytes((int) (HistoryRecorder::MAX_ENTRY_BYTES * 1.2))));
    try {
      $this->repository->set($entity);
      $this->fail('The oversize write was staged.');
    }
    catch (HistoryEntryRefusedException $e) {
      $this->assertStringContainsString('too large', $e->getMessage());
    }
    $this->recorder->flush();

    $this->assertSame('one', $this->stagedName(), 'The staged state is what it was.');
    $metadata = $this->manager->metadata($this->host);
    $this->assertCount(2, $metadata['entries'], 'No entry was added.');
    $this->assertSame(1, $metadata['pointer']);
  }

  /**
   * Suppression nests: capture stays off until the outermost call returns.
   */
  public function testSuppressNests(): void {
    $entity = clone $this->entity;
    $entity->set('name', 'bookkeeping');
    $this->recorder->suppress(function () use ($entity): void {
      $this->recorder->suppress(fn() => NULL);
      // Still inside the outer call: this write must not be captured.
      $this->repository->set($entity);
    });
    $this->recorder->flush();
    $this->assertSame([], $this->manager->metadata($this->host)['entries']);

    // A callable that throws still gives its level back.
    try {
      $this->recorder->suppress(fn() => throw new \RuntimeException('thrown inside'));
    }
    catch (\RuntimeException) {
    }
    $this->stage('one');
    $this->assertCount(2, $this->manager->metadata($this->host)['entries'], 'Capture is on again.');
  }

  /**
   * Every index update holds the timeline's lock, and waits its turn.
   */
  public function testIndexUpdatesTakeTheTimelineLock(): void {
    $timeline = $this->manager->timelineFor($this->host);
    $name = HistoryStore::COLLECTION . ':' . sha1($timeline);
    // A kernel test's own lock service grants everything, so the store is
    // built on the database backend a site runs.
    $database = $this->container->get('database');
    $lock = new DatabaseLockBackend($database);
    $store = new HistoryStore($this->container->get('keyvalue.expirable'), $this->container->get('datetime.time'), $lock);
    $held = [];
    $probe = function () use ($lock, $name, &$held): void {
      // The backend answers "not available" for a name that is held, by
      // itself included.
      $held[] = !$lock->lockMayBeAvailable($name);
    };

    // The store's door holds the lock while the update runs and gives it
    // back after, also when the update throws.
    $store->locked($timeline, $probe);
    $this->assertSame([TRUE], $held);
    $this->assertTrue($lock->lockMayBeAvailable($name));
    try {
      $store->locked($timeline, fn() => throw new \RuntimeException('thrown inside'));
    }
    catch (\RuntimeException) {
    }
    $this->assertTrue($lock->lockMayBeAvailable($name));

    // Another request holding the lock: an append, a restore and a clear
    // each refuse and leave the timeline as it was.
    $this->stage('one');
    $this->stage('two');
    $before = $store->loadIndex($timeline);
    $other = new DatabaseLockBackend($database);
    $this->assertTrue($other->acquire($name));
    $busy = new HistoryStore($this->container->get('keyvalue.expirable'), $this->container->get('datetime.time'), new class($database) extends DatabaseLockBackend {

      /**
       * {@inheritdoc}
       */
      public function wait($name, $delay = 30) {
        // Do not sit out the delay in a test: the other holder stays.
        return TRUE;
      }

    });
    $recorder = new HistoryRecorder($busy, $this->container->get('tempstore.shared'), $this->container->get('current_user'), $this->container->get('datetime.time'), $this->container->get('string_translation'));
    $manager = new HistoryManager($busy, $recorder, $this->container->get('daedalus_tempstore.strategy_selector'), $this->container->get('tempstore.shared'), $this->container->get('entity_type.manager'), $this->container->get('daedalus_blueprint.host_resolver'));

    [$collection, $key] = explode(':', $timeline, 2);
    $recorder->recordWrite($collection, $key, ['entity' => $this->entity]);
    foreach ([
      'append' => fn() => $recorder->flush(),
      'restore' => fn() => $manager->undo($this->host),
      'clear' => fn() => $busy->clearTimeline($timeline),
    ] as $what => $update) {
      try {
        $update();
        $this->fail("The $what ran while another request held the timeline.");
      }
      catch (\RuntimeException $e) {
        $this->assertStringContainsString('another request', $e->getMessage());
      }
    }
    $this->assertSame($before, $store->loadIndex($timeline));
    $this->assertSame('two', $this->stagedName());

    // Released, the same updates go through.
    $other->release($name);
    $this->assertSame(1, $manager->undo($this->host)['pointer']);
  }

}
