<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_tempstore\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
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
 * by the FJS suites.
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
    'daedalus_tempstore',
    'entity_test',
  ];

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

    $metadata = $this->manager->metadata($this->entity);
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

    $result = $this->manager->undo($this->entity);
    $this->assertSame(1, $result['pointer']);
    $this->assertSame('one', $this->stagedName());
    $this->assertNotEmpty($result['entities'], 'Restore reports the affected entity.');

    // Undo to the baseline: nothing was staged before the first edit, so
    // the tempstore entry disappears and reads fall back to the saved
    // entity.
    $result = $this->manager->undo($this->entity);
    $this->assertSame(0, $result['pointer']);
    $this->assertNull($this->stagedName());
    $this->assertNull($this->manager->undo($this->entity), 'Nothing further to undo.');

    $result = $this->manager->redo($this->entity);
    $this->assertSame(1, $result['pointer']);
    $this->assertSame('one', $this->stagedName());

    $result = $this->manager->redo($this->entity);
    $this->assertSame(2, $result['pointer']);
    $this->assertSame('two', $this->stagedName());
    $this->assertNull($this->manager->redo($this->entity), 'Nothing further to redo.');
  }

  /**
   * A restore is not itself captured as history.
   */
  public function testRestoreIsNotRecorded(): void {
    $this->stage('one');
    $this->stage('two');
    $this->manager->undo($this->entity);
    $this->recorder->flush();

    $metadata = $this->manager->metadata($this->entity);
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

    $this->assertSame([], $this->manager->metadata($this->entity)['entries']);
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
    $this->manager->restoreTo($this->entity, 0);
    $this->assertNull($this->stagedName());

    // The restore response's render re-stages the page.
    $entity = clone $this->entity;
    $this->recorder->suppress(fn() => $this->repository->set($entity));
    $this->recorder->flush();

    $metadata = $this->manager->metadata($this->entity);
    $this->assertCount(3, $metadata['entries'], 'Baseline + both edits survive.');
    $this->assertSame(0, $metadata['pointer']);
    $this->assertTrue($metadata['can_redo'], 'The future is still reachable.');
    $this->manager->redo($this->entity);
    $this->assertSame('one', $this->stagedName());
  }

  /**
   * A new mutation while undone truncates the redo tail (linear history).
   */
  public function testMutationTruncatesRedoTail(): void {
    $this->stage('one');
    $this->stage('two');
    $this->manager->undo($this->entity);

    $this->stage('three');
    $metadata = $this->manager->metadata($this->entity);
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

    $labels = array_column($this->manager->metadata($this->entity)['entries'], 'label');
    $this->assertSame(['Initial state', 'Renamed the widget', 'Edited content'], $labels);
  }

  /**
   * Deleting staged state (save/discard) clears the timeline with it.
   */
  public function testDeleteClearsTimeline(): void {
    $this->stage('one');
    $this->assertNotEmpty($this->manager->metadata($this->entity)['entries']);

    $this->repository->delete($this->entity);
    $metadata = $this->manager->metadata($this->entity);
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

    $metadata = $this->manager->metadata($this->entity);
    $this->assertCount(HistoryRecorder::MAX_ENTRIES, $metadata['entries']);
    $this->assertTrue((bool) $metadata['entries'][0]['baseline'], 'The baseline survives trimming.');
    $this->assertSame($total, $metadata['pointer'], 'The newest entry is current.');
    // The oldest non-baseline entries were dropped; the survivors restore.
    $sequence_numbers = array_column($metadata['entries'], 'seq');
    $this->assertNotContains(1, $sequence_numbers);
    $this->manager->undo($this->entity);
    $this->assertSame('edit ' . ($total - 1), $this->stagedName());
    // Jumping straight to the baseline still works.
    $this->manager->restoreTo($this->entity, 0);
    $this->assertNull($this->stagedName());
  }

  /**
   * Timelines are per user: another user's history is invisible.
   */
  public function testPerUserIsolation(): void {
    $this->stage('one');
    $this->assertNotEmpty($this->manager->metadata($this->entity)['entries']);

    $this->setUpCurrentUser();
    $this->assertSame([], $this->manager->metadata($this->entity)['entries']);
  }

}
