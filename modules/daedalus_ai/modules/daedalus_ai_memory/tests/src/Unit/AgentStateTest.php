<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Unit;

use Drupal\daedalus_ai_memory\Enum\AgentState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests agent lifecycle state detection from persisted runner data.
 */
#[CoversClass(AgentState::class)]
#[Group('daedalus_ai_memory')]
class AgentStateTest extends UnitTestCase {

  /**
   * Tests that NULL persisted data yields Fresh.
   */
  public function testNullPersistedIsFresh(): void {
    $this->assertSame(AgentState::Fresh, AgentState::detect(NULL, 0));
    $this->assertSame(AgentState::Fresh, AgentState::detect(NULL, 5));
  }

  /**
   * Tests stale detection: finished with no new messages.
   */
  public function testFinishedNoNewMessagesIsStale(): void {
    $persisted = [
      'finished' => TRUE,
      'processed_message_count' => 3,
    ];
    $this->assertSame(AgentState::Stale, AgentState::detect($persisted, 3));
    $this->assertSame(AgentState::Stale, AgentState::detect($persisted, 2));
  }

  /**
   * Tests cross-turn: finished with new messages arrived.
   */
  public function testFinishedWithNewMessagesIsCrossTurn(): void {
    $persisted = [
      'finished' => TRUE,
      'processed_message_count' => 3,
    ];
    $this->assertSame(AgentState::CrossTurn, AgentState::detect($persisted, 4));
    $this->assertSame(AgentState::CrossTurn, AgentState::detect($persisted, 10));
  }

  /**
   * Tests same-turn polling: not finished, regardless of message count.
   */
  public function testNotFinishedIsSameTurn(): void {
    $persisted = [
      'finished' => FALSE,
      'processed_message_count' => 3,
    ];
    $this->assertSame(AgentState::SameTurn, AgentState::detect($persisted, 3));
    $this->assertSame(AgentState::SameTurn, AgentState::detect($persisted, 5));
    $this->assertSame(AgentState::SameTurn, AgentState::detect($persisted, 0));
  }

  /**
   * Tests that missing 'finished' key is treated as not finished.
   */
  public function testMissingFinishedKeyIsSameTurn(): void {
    $persisted = [
      'processed_message_count' => 3,
    ];
    $this->assertSame(AgentState::SameTurn, AgentState::detect($persisted, 3));
  }

  /**
   * Tests that missing 'processed_message_count' defaults to zero.
   */
  public function testMissingProcessedCountDefaultsToZero(): void {
    $persisted = [
      'finished' => TRUE,
    ];
    // Any messages > 0 means new messages arrived.
    $this->assertSame(AgentState::CrossTurn, AgentState::detect($persisted, 1));
    // Zero messages = stale.
    $this->assertSame(AgentState::Stale, AgentState::detect($persisted, 0));
  }

}
