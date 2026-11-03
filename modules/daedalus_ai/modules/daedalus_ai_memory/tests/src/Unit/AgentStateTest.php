<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Unit;

use Drupal\daedalus_ai_memory\Enum\AgentState;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the state detection by the last user message's identity.
 *
 * The detection used to compare the session's message count with the
 * count the record had processed; the user-visible chat memory caps its
 * history (20 pairs on the shipped assistant), so after twenty turns the
 * count stopped growing and every later turn read as stale, wiping the
 * conversation. Identity has no such ceiling: a new user message is a new
 * turn however long the chat is.
 */
#[CoversClass(AgentState::class)]
#[Group('daedalus_ai_memory')]
class AgentStateTest extends UnitTestCase {

  /**
   * A finished record with a transcript, answered for one message.
   */
  private static function record(bool $finished, ?string $lastUser = 'answered'): array {
    return [
      'transcript' => ['version' => 1, 'segments' => []],
      'agent_data' => [],
      'last_user' => $lastUser,
      'finished' => $finished,
    ];
  }

  /**
   * No record means a fresh start, whatever the session carries.
   */
  public function testFreshWhenNoPersistedState(): void {
    $this->assertSame(AgentState::Fresh, AgentState::detect(NULL, NULL));
    $this->assertSame(AgentState::Fresh, AgentState::detect(NULL, 'anything'));
  }

  /**
   * A new user message is a cross turn, finished or not.
   *
   * An unfinished record with a newer message is a turn the user moved on
   * from (the last one crashed before persisting, or they typed again): a
   * new turn, not a poll of the old one.
   */
  public function testCrossTurnWhenTheLastUserMessageIsNew(): void {
    $this->assertSame(AgentState::CrossTurn, AgentState::detect(self::record(TRUE), 'newer'));
    $this->assertSame(AgentState::CrossTurn, AgentState::detect(self::record(FALSE), 'newer'));
  }

  /**
   * The same message on an unfinished record is the front end polling.
   */
  public function testSameTurnWhenUnfinishedAndTheMessageIsTheSame(): void {
    $this->assertSame(AgentState::SameTurn, AgentState::detect(self::record(FALSE), 'answered'));
    $this->assertSame(AgentState::SameTurn, AgentState::detect(self::record(FALSE), NULL));
  }

  /**
   * The same message on a finished record is a cross turn, not a discard.
   *
   * The user sent the same message again (or a caller re-sent the session
   * unchanged): the record is the agent's memory and is kept, and the
   * message is appended as a new turn. A session with no user message at
   * all reads the same way; the runner then refuses the turn with the
   * record intact.
   */
  public function testCrossTurnWhenFinishedAndTheMessageIsTheSame(): void {
    $this->assertSame(AgentState::CrossTurn, AgentState::detect(self::record(TRUE), 'answered'));
    $this->assertSame(AgentState::CrossTurn, AgentState::detect(self::record(TRUE), NULL));
  }

  /**
   * A record without a transcript is from before the spine: stale.
   *
   * The one state that discards a record: nothing in it can be read, so
   * the runner's reader refuses it and the request runs fresh.
   */
  public function testStaleWhenTheRecordCarriesNoTranscript(): void {
    $old = ['agent_data' => ['chat_history' => []], 'finished' => FALSE];
    $this->assertSame(AgentState::Stale, AgentState::detect($old, 'anything'));
    $this->assertSame(AgentState::Stale, AgentState::detect($old, NULL));
  }

  /**
   * The identity is the last user message's timestamp and text, or NULL.
   *
   * The same text sent again at another time is another message (the
   * user repeating themselves is a new turn); the same text at the same
   * time is the same message (a poll re-sends it). Assistant messages
   * after the user's do not change it, and a session without a user
   * message has none.
   */
  public function testIdentityIsTheLastUserMessage(): void {
    $this->assertNull(AgentState::identity([]));
    $this->assertNull(AgentState::identity([['role' => 'assistant', 'message' => 'Hello']]));

    $first = AgentState::identity([['role' => 'user', 'message' => 'Hi', 'timestamp' => 100]]);
    $polled = AgentState::identity([
      ['role' => 'user', 'message' => 'Hi', 'timestamp' => 100],
      ['role' => 'assistant', 'message' => 'Hello'],
    ]);
    $repeated = AgentState::identity([
      ['role' => 'user', 'message' => 'Hi', 'timestamp' => 100],
      ['role' => 'assistant', 'message' => 'Hello'],
      ['role' => 'user', 'message' => 'Hi', 'timestamp' => 160],
    ]);
    $this->assertSame($first, $polled, 'An assistant reply after the user message leaves its identity alone.');
    $this->assertNotSame($first, $repeated, 'The same words at another time are another message.');
    $this->assertNotSame($first, AgentState::identity([['role' => 'user', 'message' => 'Hi there', 'timestamp' => 100]]));

    // A session from a caller that carries no timestamps still identifies
    // by text.
    $this->assertSame(
      AgentState::identity([['role' => 'user', 'message' => 'Hi']]),
      AgentState::identity([['role' => 'user', 'message' => 'Hi']]),
    );
  }

  /**
   * Twenty-plus turns never read as stale while the messages keep coming.
   *
   * The capped chat memory hands the runner a window of the newest 41
   * messages; the count stays 41 from the twentieth turn on, but each turn
   * ends on a different user message.
   */
  public function testLongConversationNeverGoesStale(): void {
    $record = NULL;
    for ($turn = 1; $turn <= 30; $turn++) {
      $session = [];
      for ($i = max(1, $turn - 20); $i <= $turn; $i++) {
        $session[] = ['role' => 'user', 'message' => "Message $i", 'timestamp' => 1000 + $i];
        if ($i < $turn) {
          $session[] = ['role' => 'assistant', 'message' => "Reply $i"];
        }
      }
      $identity = AgentState::identity($session);
      $this->assertSame($turn === 1 ? AgentState::Fresh : AgentState::CrossTurn, AgentState::detect($record, $identity), "Turn $turn is a new turn.");
      $record = self::record(TRUE, $identity);
    }
  }

}
