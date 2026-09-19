<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_memory\Unit;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\daedalus_ai_memory\Service\ChatMessageFileStripper;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests stripping file attachments from persisted chat messages.
 */
#[CoversClass(ChatMessageFileStripper::class)]
#[Group('daedalus_ai_memory')]
class ChatMessageFileStripperTest extends UnitTestCase {

  /**
   * The file stripper under test.
   *
   * @var \Drupal\daedalus_ai_memory\Service\ChatMessageFileStripper
   */
  protected ChatMessageFileStripper $stripper;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->stripper = new ChatMessageFileStripper();
  }

  /**
   * Tests that messages with files get files stripped.
   */
  public function testMessagesWithFilesGetStripped(): void {
    $msg = new ChatMessage('user', 'Check this image');
    $msg->setImageFromBinary('fake-binary-data', 'image/png');
    $this->assertNotEmpty($msg->getFiles());

    $history = [$msg];
    $agent = $this->createMock(MockAgentInterface::class);
    $agent->method('getChatHistory')->willReturn($history);
    $agent->expects($this->once())->method('setChatHistory');

    $this->stripper->stripFiles($agent);
    $this->assertEmpty($msg->getFiles());
  }

  /**
   * Tests that messages without files are unchanged.
   */
  public function testMessagesWithoutFilesUnchanged(): void {
    $msg = new ChatMessage('user', 'Hello');
    $history = [$msg];

    $agent = $this->createMock(MockAgentInterface::class);
    $agent->method('getChatHistory')->willReturn($history);
    $agent->expects($this->never())->method('setChatHistory');

    $this->stripper->stripFiles($agent);
    $this->assertEmpty($msg->getFiles());
  }

  /**
   * Tests mixed messages — only file-bearing ones are touched.
   */
  public function testMixedMessages(): void {
    $plain = new ChatMessage('user', 'Hello');
    $withFile = new ChatMessage('assistant', 'Here is a file');
    $withFile->setImageFromBinary('binary', 'image/jpeg');

    $history = [$plain, $withFile];
    $agent = $this->createMock(MockAgentInterface::class);
    $agent->method('getChatHistory')->willReturn($history);
    $agent->expects($this->once())->method('setChatHistory');

    $this->stripper->stripFiles($agent);
    $this->assertEmpty($plain->getFiles());
    $this->assertEmpty($withFile->getFiles());
  }

  /**
   * Tests empty history.
   */
  public function testEmptyHistory(): void {
    $agent = $this->createMock(MockAgentInterface::class);
    $agent->method('getChatHistory')->willReturn([]);
    $agent->expects($this->never())->method('setChatHistory');

    $this->stripper->stripFiles($agent);
  }

}

/**
 * Minimal interface for mocking agents in file stripper tests.
 */
interface MockAgentInterface {

  /**
   * Gets the chat history.
   *
   * @return array
   *   The chat history.
   */
  public function getChatHistory(): array;

  /**
   * Sets the chat history.
   *
   * @param array $history
   *   The chat history.
   */
  public function setChatHistory(array $history): void;

}
