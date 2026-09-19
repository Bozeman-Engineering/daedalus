<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Unit\AiLogTail;

use Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the ai_log row value object behind the ai-tail command.
 */
#[CoversClass(LogEntry::class)]
#[Group('daedalus_blueprint')]
class LogEntryTest extends UnitTestCase {

  /**
   * Builds a row the way the database layer hands it over.
   *
   * @param array $overrides
   *   Column overrides; a NULL value unsets the column, as when the command
   *   did not fetch it.
   *
   * @return object
   *   The row.
   */
  private function row(array $overrides = []): object {
    $row = [
      'id' => '42',
      'created' => '1757400000',
      'provider' => 'anthropic',
      'model' => 'claude-sonnet-4',
      'operation_type' => 'chat',
      'prompt' => "user\nhello\n",
      'output_text' => '{"text":"hi"}',
    ];
    foreach ($overrides as $key => $value) {
      if ($value === NULL) {
        unset($row[$key]);
      }
      else {
        $row[$key] = $value;
      }
    }
    return (object) $row;
  }

  /**
   * Tests that fromRow casts columns and decodes the JSON extras.
   */
  public function testFromRow(): void {
    $entry = LogEntry::fromRow($this->row([
      'configuration' => '{"temperature":0.2}',
      'extra_data' => '{"system_prompt":"Be brief.","chat_tools":[{"name":"page_create"}]}',
    ]), ['ai_agents', 'ai_agents_runner_abc']);

    $this->assertSame(42, $entry->id);
    $this->assertSame(1757400000, $entry->created);
    $this->assertSame('anthropic', $entry->provider);
    $this->assertSame('claude-sonnet-4', $entry->model);
    $this->assertSame('chat', $entry->operationType);
    $this->assertSame("user\nhello\n", $entry->prompt);
    $this->assertSame('{"text":"hi"}', $entry->outputText);
    $this->assertSame(['temperature' => 0.2], $entry->configuration);
    $this->assertSame('Be brief.', $entry->systemPrompt());
    $this->assertSame([['name' => 'page_create']], $entry->chatTools());
    $this->assertSame(['ai_agents', 'ai_agents_runner_abc'], $entry->tags);
    $this->assertSame(['user'], array_map(fn($m) => $m->role, $entry->transcript()->messages));
  }

  /**
   * Tests a row whose extras were not fetched and whose text columns are NULL.
   */
  public function testFromRowWithoutExtras(): void {
    $entry = LogEntry::fromRow($this->row([
      'provider' => NULL,
      'output_text' => NULL,
      'prompt' => NULL,
    ]), []);

    $this->assertSame('', $entry->provider);
    $this->assertSame('', $entry->prompt);
    $this->assertNull($entry->outputText);
    $this->assertSame([], $entry->configuration);
    $this->assertSame([], $entry->extraData);
    $this->assertSame('', $entry->systemPrompt());
    $this->assertSame([], $entry->chatTools());
    $this->assertSame(0, $entry->transcript()->count());
  }

  /**
   * Tests that an empty JSON column decodes to nothing and a broken one throws.
   */
  public function testJsonColumns(): void {
    $entry = LogEntry::fromRow($this->row(['configuration' => '', 'extra_data' => '"just a string"']), []);
    $this->assertSame([], $entry->configuration);
    $this->assertSame([], $entry->extraData);

    $this->expectException(\JsonException::class);
    LogEntry::fromRow($this->row(['extra_data' => '{not json']), []);
  }

  /**
   * Tests that the output column decodes when it is JSON and stays otherwise.
   */
  public function testDecodedOutput(): void {
    $this->assertSame(['text' => 'hi'], LogEntry::fromRow($this->row(), [])->decodedOutput());
    $this->assertSame('not json', LogEntry::fromRow($this->row(['output_text' => 'not json']), [])->decodedOutput());
    $this->assertNull(LogEntry::fromRow($this->row(['output_text' => '']), [])->decodedOutput());
    $this->assertNull(LogEntry::fromRow($this->row(['output_text' => NULL]), [])->decodedOutput());
  }

  /**
   * Tests that the thread key is the runner id, never the thread or caller id.
   */
  public function testThreadKey(): void {
    $nested = LogEntry::fromRow($this->row(), [
      'ai_agents',
      'ai_agents_thread_shared-thread',
      'ai_agents_caller_runner_parent-runner',
      'ai_agents_runner_child-runner',
    ]);
    $this->assertSame('child-runner', $nested->threadKey());

    $microtime = LogEntry::fromRow($this->row(), ['ai_agents_runner_page_builder_0.51234500 1757400000']);
    $this->assertSame('page_builder_0.51234500 1757400000', $microtime->threadKey());

    $standalone = LogEntry::fromRow($this->row(), ['daedalus_ai', 'ai_agents_thread_shared-thread']);
    $this->assertNull($standalone->threadKey());

    $bare = LogEntry::fromRow($this->row(), ['ai_agents_runner_']);
    $this->assertNull($bare->threadKey(), 'A bare prefix names no runner.');

    $this->assertNull(LogEntry::fromRow($this->row(), [])->threadKey());
  }

}
