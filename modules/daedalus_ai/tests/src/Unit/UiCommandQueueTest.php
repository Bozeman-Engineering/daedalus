<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Unit;

use Drupal\daedalus_ai\Ajax\OpenLinkCommand;
use Drupal\daedalus_ai\Ajax\TriggerDeferredCommand;
use Drupal\daedalus_ai\UiCommandQueue;
use Drupal\Core\Ajax\CommandInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the flush contract of the UI command queue.
 *
 * The queue is the single hand-off point between the server-side AI event
 * subscribers (which enqueue AJAX commands during a chat request) and
 * DeepChatResponseListener (which flushes them into the JSON response as
 * 'drupal_commands'). Three behaviors are load-bearing for that hand-off and
 * are NOT covered anywhere else:
 *
 * - flush() renders pending CommandInterface objects (calling ->render()) so
 *   the listener never has to know how to render a command itself.
 * - Pre-rendered arrays added via addRendered() come out FIRST, before the
 *   rendered pending commands. Subscribers rely on this ordering (a workspace
 *   toolbar replacement queued as a rendered array must precede a follow-up
 *   open-link), so a reordering regression would silently change client-side
 *   execution order.
 * - flush() clears BOTH internal queues, so a second flush in the same request
 *   returns nothing. A double-flush regression would replay every command into
 *   a later response, duplicating UI side effects.
 *
 * This is a pure unit test: the queue has no Drupal dependencies, and a tiny
 * anonymous CommandInterface is enough of a test double to observe render()
 * being called. It deliberately does not assert anything about the listener,
 * which is covered at the kernel tier.
 */
#[Group('daedalus_ai')]
class UiCommandQueueTest extends UnitTestCase {

  /**
   * Flush() returns rendered-then-pending once, then empties.
   *
   * Queues one pre-rendered array and one pending command whose render()
   * returns a recognizable marker. The first flush must surface the
   * pre-rendered array first, the rendered pending command second, and a
   * second flush must return nothing because both queues were cleared.
   */
  public function testFlushOrdersRenderedFirstAndClears(): void {
    $queue = new UiCommandQueue();

    // A pending command: flush() must call its render() to materialize it.
    $pending = new class() implements CommandInterface {

      /**
       * {@inheritdoc}
       */
      public function render(): array {
        return ['command' => 'pendingMarker', 'value' => 42];
      }

    };

    // Order matters: the pre-rendered array is added second but must still
    // come out first, proving the ordering is "all rendered, then all pending"
    // rather than insertion order.
    $queue->add($pending);
    $queue->addRendered(['command' => 'renderedMarker', 'value' => 7]);

    $first = $queue->flush();

    $this->assertCount(2, $first);
    $this->assertSame(
      ['command' => 'renderedMarker', 'value' => 7],
      $first[0],
      'Pre-rendered commands flush before rendered pending commands.',
    );
    $this->assertSame(
      ['command' => 'pendingMarker', 'value' => 42],
      $first[1],
      'flush() renders the pending command via its render() method.',
    );

    // Both internal queues are cleared on flush, so a re-flush is empty: the
    // guard against replaying commands into a later response in the same
    // request.
    $this->assertSame([], $queue->flush());
  }

  /**
   * End-of-turn commands bypass flush() and drain via flushEndOfTurn().
   *
   * Subscribers call plain add() for every command; the command's CLASS
   * decides the bucket. An EndOfTurnCommandInterface command must never
   * come out of flush() — that is the whole mechanism keeping open-link and
   * the deferred trigger off intermediate poll responses — and
   * flushEndOfTurn() must return the commands rendered, in queue order,
   * tagged with their endOfTurnOrder() so the listener can sort a merged
   * multi-leg set once at release time (trigger 0 before open-link 10).
   */
  public function testEndOfTurnCommandsFlushSeparately(): void {
    $queue = new UiCommandQueue();
    $queue->add(new OpenLinkCommand('/node/3', 'Created page'));
    $queue->add(new TriggerDeferredCommand('node:3:abc', 'node', '3', 'full'));

    // flush() must not see either command.
    $this->assertSame([], $queue->flush(), 'End-of-turn commands never ride the immediate flush.');

    $entries = $queue->flushEndOfTurn();
    $this->assertCount(2, $entries);
    // Queue order is preserved (sorting is the listener's job at release);
    // each entry carries the command's declared order.
    $this->assertSame(10, $entries[0]['order']);
    $this->assertSame('daedalusAiOpenLink', $entries[0]['command']['command']);
    $this->assertSame(0, $entries[1]['order']);
    $this->assertSame('daedalusAiTriggerDeferred', $entries[1]['command']['command']);

    // The bucket clears on flush, same replay guard as the immediate one.
    $this->assertSame([], $queue->flushEndOfTurn());
  }

  /**
   * An untouched queue flushes to an empty array, not NULL.
   *
   * DeepChatResponseListener short-circuits on empty($commands); flush() must
   * return a value empty() treats as nothing so a request with no queued
   * commands leaves the JSON response untouched.
   */
  public function testFlushEmptyQueue(): void {
    $this->assertSame([], (new UiCommandQueue())->flush());
  }

}
