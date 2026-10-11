<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_tempstore\Kernel;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
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
   * Timelines are per user: another user's history is invisible.
   */
  public function testPerUserIsolation(): void {
    $this->stage('one');
    $this->assertNotEmpty($this->manager->metadata($this->host)['entries']);

    $this->setUpCurrentUser();
    $this->assertSame([], $this->manager->metadata($this->host)['entries']);
  }

}
