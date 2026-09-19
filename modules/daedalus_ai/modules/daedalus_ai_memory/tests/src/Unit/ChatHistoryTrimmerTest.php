<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Unit;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\daedalus_ai_memory\Service\ChatHistoryTrimmer;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests chat history trimming around the configured turn limit.
 */
#[CoversClass(ChatHistoryTrimmer::class)]
#[Group('daedalus_ai_memory')]
class ChatHistoryTrimmerTest extends UnitTestCase {

  /**
   * Creates a trimmer with the given config values.
   *
   * @param int $maxMessages
   *   The max history messages setting.
   * @param int $keepRecentTurns
   *   The keep recent turns setting.
   *
   * @return \Drupal\daedalus_ai_memory\Service\ChatHistoryTrimmer
   *   The configured trimmer.
   */
  protected function createTrimmer(int $maxMessages = 100, int $keepRecentTurns = 5): ChatHistoryTrimmer {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['max_history_messages', $maxMessages],
        ['keep_recent_turns', $keepRecentTurns],
      ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('daedalus_ai_memory.settings')
      ->willReturn($config);

    return new ChatHistoryTrimmer($configFactory);
  }

  /**
   * Creates a ChatMessage.
   *
   * @param string $role
   *   The role.
   * @param string $text
   *   The text.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage
   *   The message.
   */
  protected function msg(string $role, string $text): ChatMessage {
    return new ChatMessage($role, $text);
  }

  /**
   * Tests that history under the limit is not trimmed.
   */
  public function testUnderLimitNoTrimming(): void {
    $trimmer = $this->createTrimmer(100, 5);
    $history = [
      $this->msg('user', 'Hello'),
      $this->msg('assistant', 'Hi there'),
      $this->msg('user', 'Do something'),
      $this->msg('assistant', 'Done'),
    ];

    $result = $trimmer->trim($history);
    $this->assertCount(4, $result);
  }

  /**
   * Tests that history over the limit drops middle turns.
   */
  public function testOverLimitDropsMiddleTurns(): void {
    // Max 6 messages, keep 1 recent turn.
    $trimmer = $this->createTrimmer(6, 1);
    // 4 turns: user+assistant each = 8 messages total.
    $history = [
      $this->msg('user', 'Turn 1'),
      $this->msg('assistant', 'Response 1'),
      $this->msg('user', 'Turn 2'),
      $this->msg('assistant', 'Response 2'),
      $this->msg('user', 'Turn 3'),
      $this->msg('assistant', 'Response 3'),
      $this->msg('user', 'Turn 4'),
      $this->msg('assistant', 'Response 4'),
    ];

    $result = $trimmer->trim($history);
    // Should keep first turn (2 msgs) + last 1 turn (2 msgs) = 4 msgs.
    $this->assertCount(4, $result);
    $this->assertEquals('Turn 1', $result[0]->getText());
    $this->assertEquals('Response 1', $result[1]->getText());
    $this->assertEquals('Turn 4', $result[2]->getText());
    $this->assertEquals('Response 4', $result[3]->getText());
  }

  /**
   * Tests that tool call/result pairs stay together within a turn.
   */
  public function testToolPairsStayTogether(): void {
    $trimmer = $this->createTrimmer(8, 1);
    $history = [
      $this->msg('user', 'Turn 1'),
      $this->msg('assistant', 'Response 1'),
      $this->msg('user', 'Turn 2'),
      $this->msg('assistant', 'Tool call'),
      $this->msg('tool', 'Tool result'),
      $this->msg('user', 'Turn 3'),
      $this->msg('assistant', 'Tool call 2'),
      $this->msg('tool', 'Tool result 2'),
      $this->msg('assistant', 'Final answer'),
      $this->msg('user', 'Turn 4'),
      $this->msg('assistant', 'Response 4'),
    ];

    $result = $trimmer->trim($history);
    // First turn (2) + last turn (2) = 4 messages.
    $this->assertCount(4, $result);
    $this->assertEquals('Turn 1', $result[0]->getText());
    $this->assertEquals('Turn 4', $result[2]->getText());
  }

  /**
   * Tests exactly at the limit — no trimming.
   */
  public function testExactlyAtLimit(): void {
    $trimmer = $this->createTrimmer(4, 5);
    $history = [
      $this->msg('user', 'Hello'),
      $this->msg('assistant', 'Hi'),
      $this->msg('user', 'Bye'),
      $this->msg('assistant', 'Goodbye'),
    ];

    $result = $trimmer->trim($history);
    $this->assertCount(4, $result);
  }

  /**
   * Tests a single turn is never trimmed.
   */
  public function testSingleTurnNotTrimmed(): void {
    $trimmer = $this->createTrimmer(1, 1);
    $history = [
      $this->msg('user', 'Hello'),
      $this->msg('assistant', 'Hi'),
    ];

    $result = $trimmer->trim($history);
    $this->assertCount(2, $result);
  }

  /**
   * Tests that when turns count equals keep_recent + 1, nothing is trimmed.
   */
  public function testTurnsEqualKeepRecentPlusOne(): void {
    // 3 turns total, keep_recent_turns = 2 => first + 2 recent = 3 = all.
    $trimmer = $this->createTrimmer(2, 2);
    $history = [
      $this->msg('user', 'Turn 1'),
      $this->msg('assistant', 'R1'),
      $this->msg('user', 'Turn 2'),
      $this->msg('assistant', 'R2'),
      $this->msg('user', 'Turn 3'),
      $this->msg('assistant', 'R3'),
    ];

    $result = $trimmer->trim($history);
    // All 3 turns preserved (first + 2 recent = all 3).
    $this->assertCount(6, $result);
  }

}
