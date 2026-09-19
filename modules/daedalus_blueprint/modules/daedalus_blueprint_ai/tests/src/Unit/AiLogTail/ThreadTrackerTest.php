<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Unit\AiLogTail;

// cspell:ignore toolu
use Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry;
use Drupal\daedalus_blueprint_ai\AiLogTail\ThreadTracker;
use Drupal\daedalus_blueprint_ai\AiLogTail\Transcript;
use Drupal\daedalus_blueprint_ai\AiLogTail\TranscriptDelta;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the per-thread transcript diff behind the ai-tail command.
 */
#[CoversClass(ThreadTracker::class)]
#[CoversClass(TranscriptDelta::class)]
#[Group('daedalus_blueprint')]
class ThreadTrackerTest extends UnitTestCase {

  /**
   * The first row of a page-build thread: one user message.
   */
  private const FIRST = "user\nBuild me a landing page\n";

  /**
   * Builds a row the way the command hands it to the tracker.
   *
   * @param int $id
   *   The row id.
   * @param string $prompt
   *   The prompt column.
   * @param string|null $runner
   *   The runner id for the ai_agents_runner_ tag; NULL for a standalone row.
   * @param string|null $output
   *   The output_text column.
   * @param array $extraData
   *   The decoded extra_data column.
   *
   * @return \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry
   *   The entry.
   */
  private function entry(int $id, string $prompt, ?string $runner = 'run-a', ?string $output = NULL, array $extraData = []): LogEntry {
    return new LogEntry(
      id: $id,
      created: 1757400000 + $id,
      provider: 'anthropic',
      model: 'claude-sonnet-4',
      operationType: 'chat',
      prompt: $prompt,
      outputText: $output,
      configuration: [],
      extraData: $extraData,
      tags: $runner === NULL ? ['daedalus_ai'] : ['ai_agents', 'ai_agents_runner_' . $runner],
    );
  }

  /**
   * Feeds a row to a tracker and answers its delta.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\ThreadTracker $tracker
   *   The tracker.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry $entry
   *   The row.
   *
   * @return \Drupal\daedalus_blueprint_ai\AiLogTail\TranscriptDelta|null
   *   The delta.
   */
  private function advance(ThreadTracker $tracker, LogEntry $entry): ?TranscriptDelta {
    return $tracker->advance($entry, $entry->transcript());
  }

  /**
   * The role and text pairs of a delta's messages.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\TranscriptDelta $delta
   *   The delta.
   *
   * @return array
   *   [role, text] per message.
   */
  private function pairs(TranscriptDelta $delta): array {
    return array_map(fn($m) => [$m->role, $m->text], $delta->transcript->messages);
  }

  /**
   * An Anthropic-shaped raw output with the given text blocks and a tool use.
   *
   * @param string ...$texts
   *   The text blocks.
   *
   * @return string
   *   The output_text column.
   */
  private function anthropicOutput(string ...$texts): string {
    $content = [];
    foreach ($texts as $text) {
      $content[] = ['type' => 'text', 'text' => $text];
      $content[] = ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'page_create', 'input' => ['title' => 'Landing']];
    }
    return json_encode([
      'id' => 'msg_1',
      'role' => 'assistant',
      'model' => 'claude-sonnet-4',
      'content' => $content,
      'stop_reason' => 'tool_use',
    ]);
  }

  /**
   * Tests that a thread's first row prints whole and carries no flags.
   */
  public function testFirstRowIsPrintedWhole(): void {
    $delta = $this->advance(new ThreadTracker(), $this->entry(1, self::FIRST, output: '{}', extraData: ['system_prompt' => 'Be brief.']));

    $this->assertInstanceOf(TranscriptDelta::class, $delta);
    $this->assertTrue($delta->isFirst());
    $this->assertNull($delta->sinceId);
    $this->assertSame([['user', 'Build me a landing page']], $this->pairs($delta));
    $this->assertSame(0, $delta->hiddenCount);
    $this->assertFalse($delta->diverged);
    $this->assertFalse($delta->echoDropped);
    $this->assertFalse($delta->systemPromptChanged);
    $this->assertFalse($delta->toolsChanged);
  }

  /**
   * Tests that a row extending the previous one yields only the extension.
   */
  public function testExtension(): void {
    $tracker = new ThreadTracker();
    $this->advance($tracker, $this->entry(1, self::FIRST));
    $delta = $this->advance($tracker, $this->entry(2, self::FIRST . "tool\n{\"ok\":true}\nuser\nNow add a footer\n"));

    $this->assertFalse($delta->isFirst());
    $this->assertSame(1, $delta->sinceId);
    $this->assertSame([['tool', '{"ok":true}'], ['user', 'Now add a footer']], $this->pairs($delta));
    $this->assertSame(1, $delta->hiddenCount);
    $this->assertFalse($delta->diverged);
    $this->assertFalse($delta->echoDropped);

    $retry = $this->advance($tracker, $this->entry(3, self::FIRST . "tool\n{\"ok\":true}\nuser\nNow add a footer\n"));
    $this->assertSame([], $this->pairs($retry), 'An identical transcript (a retry) adds nothing.');
    $this->assertSame(3, $retry->hiddenCount);
    $this->assertSame(2, $retry->sinceId);
    $this->assertFalse($retry->diverged);
  }

  /**
   * Tests that the previous response echoed as an assistant message drops.
   */
  public function testAssistantEchoIsDropped(): void {
    $tracker = new ThreadTracker();
    $this->advance($tracker, $this->entry(1, self::FIRST, output: $this->anthropicOutput("I'll create the page.\nOne moment.")));
    $delta = $this->advance($tracker, $this->entry(2, self::FIRST . "assistant\nI'll create the page.\nOne moment.\ntool\n{\"created\":1}\n"));

    $this->assertSame([['tool', '{"created":1}']], $this->pairs($delta));
    $this->assertSame(2, $delta->hiddenCount, 'The prefix and the echo are both hidden.');
    $this->assertTrue($delta->echoDropped);
    $this->assertFalse($delta->diverged);
  }

  /**
   * Tests the echo rule over the output shapes the log actually holds.
   */
  public function testEchoRuleOverOutputShapes(): void {
    $joined = "Creating the page.Now the footer.";
    $streamed = json_encode([
      ['delta' => ['text' => 'Hel']],
      ['delta' => ['text' => 'lo there']],
    ]);
    $openAi = json_encode([
      'choices' => [['message' => ['role' => 'assistant', 'content' => 'Done.']]],
    ]);
    $overlapping = json_encode([
      'content' => [['type' => 'text', 'text' => 'AB'], ['type' => 'text', 'text' => 'A']],
    ]);
    $nullContent = json_encode(['choices' => [['message' => ['content' => NULL]]]]);

    $this->assertEchoDropped($this->anthropicOutput('Creating the page.', 'Now the footer.'), $joined, 'Anthropic joins its text blocks into one assistant text.');
    $this->assertEchoDropped($streamed, 'Hello there', 'A streamed row stores chunks that join in order.');
    $this->assertEchoDropped($openAi, 'Done.', 'A single leaf anywhere in the document matches.');
    $this->assertEchoDropped('plain text output', 'plain text output', 'A non-JSON column is one leaf.');
    $this->assertEchoDropped($this->anthropicOutput(), '', 'An empty assistant turn echoes a tool-calls-only response.');
    $this->assertEchoDropped($overlapping, 'AB', 'Whole-leaf equality wins over a greedy partial match.');

    $this->assertEchoKept($this->anthropicOutput('I will create the page.'), 'I will create the page. And a footer.', 'Text beyond the response is new.');
    $this->assertEchoKept($this->anthropicOutput('I will create the page.'), 'Something else entirely.', 'A different assistant message is new.');
    $this->assertEchoKept(NULL, 'Hello', 'No recorded output matches nothing.');
    $this->assertEchoKept($nullContent, 'Hello', 'A null content matches nothing.');
  }

  /**
   * Tests that a rewritten or compacted history is reported, never guessed.
   */
  public function testDivergence(): void {
    $tracker = new ThreadTracker();
    $this->advance($tracker, $this->entry(1, self::FIRST . "tool\n{\"ok\":true}\n"));

    $rewritten = $this->advance($tracker, $this->entry(2, "user\nBuild me a landing page, in blue\ntool\n{\"ok\":true}\nuser\nMore\n"));
    $this->assertTrue($rewritten->diverged);
    $this->assertSame(1, $rewritten->sinceId);
    $this->assertSame(0, $rewritten->hiddenCount);
    $this->assertCount(3, $rewritten->transcript->messages, 'A diverged row is handed back whole.');
    $this->assertFalse($rewritten->echoDropped);

    $compacted = $this->advance($tracker, $this->entry(3, "user\nSummary of the conversation so far\n"));
    $this->assertTrue($compacted->diverged, 'A shorter transcript cannot extend the previous one.');
    $this->assertSame(2, $compacted->sinceId, 'The diverged row became the thread\'s baseline.');
    $this->assertSame(0, $compacted->hiddenCount);

    $extended = $this->advance($tracker, $this->entry(4, "user\nSummary of the conversation so far\nuser\nAnd then?\n"));
    $this->assertFalse($extended->diverged, 'The compacted transcript is the new baseline to extend.');
    $this->assertSame([['user', 'And then?']], $this->pairs($extended));
  }

  /**
   * Tests that a changed system prompt or tool set is flagged, once.
   */
  public function testSystemPromptAndToolsChangeFlags(): void {
    $tracker = new ThreadTracker();
    $brief = [
      'system_prompt' => 'Be brief.',
      'chat_tools' => [['name' => 'page_create'], ['name' => 'page_edit']],
    ];
    $thorough = [
      'system_prompt' => 'Be thorough.',
      'chat_tools' => [['name' => 'page_create']],
    ];
    $this->advance($tracker, $this->entry(1, self::FIRST, extraData: $brief));

    $same = $this->advance($tracker, $this->entry(2, self::FIRST . "user\nGo on\n", extraData: $brief));
    $this->assertFalse($same->systemPromptChanged);
    $this->assertFalse($same->toolsChanged);

    $changed = $this->advance($tracker, $this->entry(3, self::FIRST . "user\nGo on\nuser\nMore\n", extraData: $thorough));
    $this->assertTrue($changed->systemPromptChanged);
    $this->assertTrue($changed->toolsChanged);
    $this->assertFalse($changed->diverged, 'A prompt or tool change is not a transcript divergence.');

    $settled = $this->advance($tracker, $this->entry(4, self::FIRST . "user\nGo on\nuser\nMore\nuser\nDone\n", extraData: $thorough));
    $this->assertFalse($settled->systemPromptChanged, 'The change is flagged on the row that made it, not after.');
    $this->assertFalse($settled->toolsChanged);

    $unfetched = new ThreadTracker();
    $this->advance($unfetched, $this->entry(1, self::FIRST));
    $delta = $this->advance($unfetched, $this->entry(2, self::FIRST . "user\nGo on\n"));
    $this->assertFalse($delta->systemPromptChanged, 'Rows fetched without extras never flag.');
    $this->assertFalse($delta->toolsChanged);
  }

  /**
   * Tests that two interleaved threads each diff against their own history.
   */
  public function testTwoInterleavedThreads(): void {
    $tracker = new ThreadTracker();
    $a1 = $this->advance($tracker, $this->entry(1, "user\nA one\n", 'run-a'));
    $b1 = $this->advance($tracker, $this->entry(2, "user\nB one\n", 'run-b'));
    $a2 = $this->advance($tracker, $this->entry(3, "user\nA one\nuser\nA two\n", 'run-a'));
    $b2 = $this->advance($tracker, $this->entry(4, "user\nB one\nuser\nB two\nuser\nB three\n", 'run-b'));

    $this->assertTrue($a1->isFirst());
    $this->assertTrue($b1->isFirst());
    $this->assertSame(1, $a2->sinceId);
    $this->assertSame([['user', 'A two']], $this->pairs($a2));
    $this->assertSame(2, $b2->sinceId);
    $this->assertSame([['user', 'B two'], ['user', 'B three']], $this->pairs($b2));
    $this->assertFalse($a2->diverged);
    $this->assertFalse($b2->diverged);

    $nested = $this->advance($tracker, $this->entry(5, "user\nSub-agent task\n", 'run-a-child'));
    $this->assertTrue($nested->isFirst(), 'A nested runner is its own thread even though it shares the thread tag.');
  }

  /**
   * Tests that a standalone row gets no delta and disturbs no thread.
   */
  public function testStandaloneRow(): void {
    $tracker = new ThreadTracker();
    $this->advance($tracker, $this->entry(1, self::FIRST));

    $this->assertNull($this->advance($tracker, $this->entry(2, 'A sunset over the mountains', NULL, '{"inlineData":{"data":"..."}}')));
    $this->assertNull($this->advance($tracker, $this->entry(3, self::FIRST, NULL)), 'A standalone chat row is not remembered as a thread.');

    $delta = $this->advance($tracker, $this->entry(4, self::FIRST . "user\nGo on\n"));
    $this->assertSame(1, $delta->sinceId, 'The thread still diffs against its own last row.');
    $this->assertSame([['user', 'Go on']], $this->pairs($delta));
  }

  /**
   * Asserts that an assistant message is dropped as the echo of an output.
   */
  private function assertEchoDropped(?string $output, string $text, string $message): void {
    $delta = $this->echoDelta($output, $text);
    $this->assertTrue($delta->echoDropped, $message);
    $this->assertSame([['tool', '{}']], $this->pairs($delta), $message);
  }

  /**
   * Asserts that an assistant message is kept as new against an output.
   */
  private function assertEchoKept(?string $output, string $text, string $message): void {
    $delta = $this->echoDelta($output, $text);
    $this->assertFalse($delta->echoDropped, $message);
    $this->assertSame([['assistant', $text], ['tool', '{}']], $this->pairs($delta), $message);
  }

  /**
   * Runs a two-row thread whose second row opens with an assistant message.
   *
   * @param string|null $output
   *   The first row's output_text.
   * @param string $text
   *   The assistant message's text.
   *
   * @return \Drupal\daedalus_blueprint_ai\AiLogTail\TranscriptDelta
   *   The second row's delta.
   */
  private function echoDelta(?string $output, string $text): TranscriptDelta {
    $tracker = new ThreadTracker();
    $this->advance($tracker, $this->entry(1, self::FIRST, output: $output));
    $prompt = self::FIRST . Transcript::ASSISTANT . "\n" . $text . "\ntool\n{}\n";
    return $this->advance($tracker, $this->entry(2, $prompt));
  }

}
