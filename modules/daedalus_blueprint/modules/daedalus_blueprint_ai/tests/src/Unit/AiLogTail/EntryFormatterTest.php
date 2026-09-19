<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Unit\AiLogTail;

use Drupal\daedalus_blueprint_ai\AiLogTail\EntryFormatter;
use Drupal\daedalus_blueprint_ai\AiLogTail\FormatOptions;
use Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry;
use Drupal\daedalus_blueprint_ai\AiLogTail\Message;
use Drupal\daedalus_blueprint_ai\AiLogTail\Transcript;
use Drupal\daedalus_blueprint_ai\AiLogTail\TranscriptDelta;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests the row printer behind the ai-tail command.
 */
#[CoversClass(EntryFormatter::class)]
#[CoversClass(FormatOptions::class)]
#[Group('daedalus_blueprint')]
class EntryFormatterTest extends UnitTestCase {

  /**
   * A three-message conversation, the shape of a thread's earlier rows.
   */
  private const HISTORY = "user\nBuild me a landing page\nassistant\nWhat is it for?\nuser\nA bakery\n";

  /**
   * The two messages a follow-on row adds to HISTORY.
   */
  private const ADDED = "tool\n{\"ok\":true}\nuser\nNow add a hero\n";

  /**
   * A base64 payload past the collapser's floor, mixing cases and digits.
   */
  private const PAYLOAD = 'aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3aB3';

  /**
   * Builds a row the way the command hands it to the formatter.
   *
   * @param int $id
   *   The row id.
   * @param string $prompt
   *   The prompt column.
   * @param string|null $output
   *   The output_text column.
   * @param array $extraData
   *   The decoded extra_data column.
   * @param array $configuration
   *   The decoded configuration column.
   * @param string[]|null $tags
   *   The tags; NULL for a thread row tagged with runner run-a.
   *
   * @return \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry
   *   The entry.
   */
  private function entry(int $id, string $prompt = self::HISTORY, ?string $output = '{"text":"Sure"}', array $extraData = [], array $configuration = [], ?array $tags = NULL): LogEntry {
    return new LogEntry(
      id: $id,
      created: 1757400000 + $id,
      provider: 'anthropic',
      model: 'claude-sonnet-4',
      operationType: 'chat',
      prompt: $prompt,
      outputText: $output,
      configuration: $configuration,
      extraData: $extraData,
      tags: $tags ?? ['ai_agents', 'ai_agents_runner_run-a'],
    );
  }

  /**
   * A follow-on delta over HISTORY as the tracker would answer it.
   *
   * @param string $added
   *   The added messages, as prompt text.
   * @param int $sinceId
   *   The thread's previous row id.
   * @param bool $diverged
   *   Whether the row diverged.
   * @param bool $systemPromptChanged
   *   Whether the system prompt changed.
   * @param bool $toolsChanged
   *   Whether the tools changed.
   *
   * @return \Drupal\daedalus_blueprint_ai\AiLogTail\TranscriptDelta
   *   The delta.
   */
  private function delta(string $added = self::ADDED, int $sinceId = 11, bool $diverged = FALSE, bool $systemPromptChanged = FALSE, bool $toolsChanged = FALSE): TranscriptDelta {
    return new TranscriptDelta(Transcript::parse($added), 3, $sinceId, $diverged, FALSE, $systemPromptChanged, $toolsChanged);
  }

  /**
   * Prints one row and answers the text.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\FormatOptions $options
   *   The options.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry $entry
   *   The row.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\TranscriptDelta|null $delta
   *   The delta.
   *
   * @return string
   *   The printed text, tags stripped.
   */
  private function print(FormatOptions $options, LogEntry $entry, ?TranscriptDelta $delta = NULL): string {
    $output = new BufferedOutput();
    (new EntryFormatter($output, $options))->format($entry, $entry->transcript(), $delta);
    return $output->fetch();
  }

  /**
   * A follow-on row prints its header, a since note and only the new messages.
   */
  public function testFollowOnRowPrintsOnlyTheDelta(): void {
    $entry = $this->entry(12, self::HISTORY . self::ADDED);
    $text = $this->print(new FormatOptions(), $entry, $this->delta());

    $date = date('Y-m-d H:i:s', $entry->created);
    $this->assertStringContainsString("#12 | {$date} | anthropic / claude-sonnet-4 | chat\n", $text);
    $this->assertStringContainsString("Tags: ai_agents, ai_agents_runner_run-a\n", $text);
    $this->assertStringContainsString("\n[+2 messages since #11, 3 already printed]\n", $text);
    $this->assertStringContainsString(self::ADDED, $text);
    $this->assertStringNotContainsString('Build me a landing page', $text);
    $this->assertStringNotContainsString('A bakery', $text);
    $this->assertStringContainsString("RESPONSE", $text);
    $this->assertStringContainsString("{\n    \"text\": \"Sure\"\n}\n", $text);
    $this->assertStringNotContainsString('SYSTEM PROMPT', $text);
    $this->assertStringNotContainsString('TOOLS', $text);
    $this->assertStringNotContainsString('CONFIGURATION', $text);
    $this->assertStringNotContainsString('EXTRA DATA', $text);
    $this->assertStringNotContainsString('<', $text, 'Console tags are not printed as text.');

    $one = $this->print(new FormatOptions(), $entry, $this->delta("user\nNow add a hero\n"));
    $this->assertStringContainsString('[+1 message since #11, 3 already printed]', $one);

    $retry = $this->print(new FormatOptions(), $entry, $this->delta(''));
    $this->assertStringContainsString('[+0 messages since #11, 3 already printed]', $retry);
    $this->assertStringNotContainsString('Build me a landing page', $retry);
    $this->assertStringNotContainsString('No prompt recorded', $retry);
  }

  /**
   * A thread's first row prints the whole transcript with no note.
   */
  public function testFirstRowPrintsWholeTranscript(): void {
    $first = new TranscriptDelta(Transcript::parse(self::HISTORY), 0, NULL, FALSE, FALSE, FALSE, FALSE);
    $text = $this->print(new FormatOptions(), $this->entry(11), $first);

    $this->assertStringContainsString(self::HISTORY, $text);
    $this->assertStringNotContainsString('since #', $text);
    $this->assertStringNotContainsString('[', $text);
  }

  /**
   * A standalone row (no delta) prints the whole prompt, collapsed.
   */
  public function testStandaloneRowPrintsWholePrompt(): void {
    $prompt = "A bakery hero image\n";
    $entry = $this->entry(13, $prompt, NULL, tags: ['daedalus_ai']);
    $text = $this->print(new FormatOptions(), $entry);

    $this->assertStringContainsString("Tags: daedalus_ai\n", $text);
    $this->assertStringContainsString($prompt, $text);
    $this->assertStringNotContainsString('since #', $text);
    $this->assertStringContainsString("No output recorded.\n", $text);
  }

  /**
   * Full mode prints the raw prompt, whole, even when a delta is handed over.
   */
  public function testFullModePrintsTheRawPrompt(): void {
    $entry = $this->entry(12, self::HISTORY . self::ADDED);
    $text = $this->print(new FormatOptions(full: TRUE), $entry, $this->delta());

    $this->assertStringContainsString("PROMPT", $text);
    $this->assertStringContainsString("\n" . self::HISTORY . self::ADDED . "\n", $text);
    $this->assertStringNotContainsString('since #', $text);
    $this->assertStringContainsString("{\n    \"text\": \"Sure\"\n}\n", $text);
  }

  /**
   * Base64 collapses in the prompt and the response by default, never in full.
   */
  public function testCollapseAppliesOnlyInDefaultMode(): void {
    $uri = 'data:image/png;base64,' . self::PAYLOAD;
    $prompt = "user\nHere is the logo: {$uri}\n";
    $output = json_encode(['inlineData' => ['data' => self::PAYLOAD]]);
    $entry = $this->entry(14, $prompt, $output, tags: ['daedalus_ai']);

    $default = $this->print(new FormatOptions(), $entry);
    $this->assertStringNotContainsString(self::PAYLOAD, $default);
    $this->assertStringContainsString('Here is the logo: data:image/png;base64,', $default);
    $this->assertStringContainsString('... [210 bytes base64]', $default);
    $this->assertStringContainsString('"data": "' . substr(self::PAYLOAD, 0, 64) . '... [210 bytes base64]"', $default);

    $full = $this->print(new FormatOptions(full: TRUE), $entry);
    $this->assertStringContainsString($uri, $full);
    $this->assertStringContainsString('"data": "' . self::PAYLOAD . '"', $full);
    $this->assertStringNotContainsString('bytes base64', $full);
  }

  /**
   * The message limit slices what would print: the delta, else the whole.
   */
  public function testMessageLimitAppliesAfterTheDiff(): void {
    $added = "tool\n{\"ok\":true}\nuser\nNow add a hero\nassistant\nAdding it\n";
    $entry = $this->entry(12, self::HISTORY . $added);
    $text = $this->print(new FormatOptions(messageLimit: 2), $entry, $this->delta($added));

    $this->assertStringContainsString("[+3 messages since #11, 3 already printed]\n[last 2 of 3 messages; 1 earlier hidden by --messages]\n", $text);
    $this->assertStringContainsString("user\nNow add a hero\nassistant\nAdding it\n", $text);
    $this->assertStringNotContainsString('{"ok":true}', $text);

    $whole = $this->print(new FormatOptions(messageLimit: 2), $entry);
    $this->assertStringContainsString("[last 2 of 6 messages; 4 earlier hidden by --messages]\n", $whole);
    $this->assertStringNotContainsString('Build me a landing page', $whole);

    $full = $this->print(new FormatOptions(full: TRUE, messageLimit: 2), $entry, $this->delta($added));
    $this->assertStringContainsString("[last 2 of 6 messages; 4 earlier hidden by --messages]\n", $full);

    $within = $this->print(new FormatOptions(messageLimit: 5), $entry, $this->delta($added));
    $this->assertStringNotContainsString('hidden by --messages', $within);
    $this->assertStringContainsString($added, $within);

    $image = $this->entry(13, "A bakery hero image\n", NULL, tags: ['daedalus_ai']);
    $this->assertStringNotContainsString('hidden by --messages', $this->print(new FormatOptions(messageLimit: 1), $image));
  }

  /**
   * The optional sections print when included, with a comment when empty.
   */
  public function testIncludedSections(): void {
    $extraData = [
      'system_prompt' => 'You build pages.',
      'chat_tools' => [['name' => 'page_create']],
    ];
    $configuration = ['temperature' => 0.2];
    $entry = $this->entry(12, self::HISTORY, extraData: $extraData, configuration: $configuration);
    $all = new FormatOptions(systemPrompt: TRUE, configuration: TRUE, tools: TRUE, extraData: TRUE);
    $text = $this->print($all, $entry);

    $this->assertStringContainsString("SYSTEM PROMPT", $text);
    $this->assertStringContainsString("You build pages.\n", $text);
    $this->assertStringContainsString("TOOLS", $text);
    $this->assertStringContainsString("\"name\": \"page_create\"", $text);
    $this->assertStringContainsString("CONFIGURATION", $text);
    $this->assertStringContainsString("\"temperature\": 0.2", $text);
    $this->assertStringContainsString("EXTRA DATA", $text);
    $this->assertStringContainsString("\"system_prompt\": \"You build pages.\"", $text);
    $this->assertLessThan(strpos($text, 'PROMPT ' . "\u{2500}"), strpos($text, 'SYSTEM PROMPT'));
    $this->assertLessThan(strpos($text, 'TOOLS'), strpos($text, 'RESPONSE'));
    $this->assertLessThan(strpos($text, 'CONFIGURATION'), strpos($text, 'TOOLS'));
    $this->assertLessThan(strpos($text, 'EXTRA DATA'), strpos($text, 'CONFIGURATION'));

    $empty = $this->print($all, $this->entry(12));
    $this->assertStringContainsString("No system prompt recorded.\n", $empty);
    $this->assertStringContainsString("No tools recorded.\n", $empty);
    $this->assertStringContainsString("No configuration recorded.\n", $empty);
    $this->assertStringContainsString("No extra data recorded.\n", $empty);

    $only = $this->print(new FormatOptions(tools: TRUE), $entry);
    $this->assertStringContainsString('TOOLS', $only);
    $this->assertStringNotContainsString('SYSTEM PROMPT', $only);
    $this->assertStringNotContainsString('CONFIGURATION', $only);
    $this->assertStringNotContainsString('EXTRA DATA', $only);
  }

  /**
   * Base64 collapses in every optional section (extra data repeats two).
   */
  public function testCollapseReachesTheOptionalSections(): void {
    $extraData = [
      'system_prompt' => 'Logo: ' . self::PAYLOAD,
      'chat_tools' => [['example' => self::PAYLOAD]],
    ];
    $entry = $this->entry(12, extraData: $extraData, configuration: ['seed' => self::PAYLOAD]);
    $all = new FormatOptions(systemPrompt: TRUE, configuration: TRUE, tools: TRUE, extraData: TRUE);

    $default = $this->print($all, $entry);
    $this->assertStringNotContainsString(self::PAYLOAD, $default);
    $this->assertSame(5, substr_count($default, '... [210 bytes base64]'));

    $full = $this->print(new FormatOptions(full: TRUE, systemPrompt: TRUE, configuration: TRUE, tools: TRUE, extraData: TRUE), $entry);
    $this->assertSame(5, substr_count($full, self::PAYLOAD));
  }

  /**
   * Divergence and change flags print as notes above the messages.
   */
  public function testDeltaNotes(): void {
    $entry = $this->entry(12, self::HISTORY . self::ADDED);
    $whole = new TranscriptDelta($entry->transcript(), 0, 11, TRUE, FALSE, TRUE, TRUE);
    $text = $this->print(new FormatOptions(), $entry, $whole);

    $this->assertStringContainsString("[transcript diverged from #11; printed whole]\n[system prompt changed since #11]\n[tools changed since #11]\n", $text);
    $this->assertStringNotContainsString('since #11,', $text);
    $this->assertStringContainsString(self::HISTORY . self::ADDED, $text);

    $prompt = $this->print(new FormatOptions(), $entry, $this->delta(systemPromptChanged: TRUE));
    $this->assertStringContainsString("[+2 messages since #11, 3 already printed]\n[system prompt changed since #11]\n", $prompt);
    $this->assertStringNotContainsString('tools changed', $prompt);
  }

  /**
   * Truncation cuts the prompt and system prompt bodies after any collapse.
   */
  public function testTruncate(): void {
    $entry = $this->entry(12, self::HISTORY, extraData: ['system_prompt' => str_repeat('x', 50)]);
    $text = $this->print(new FormatOptions(truncate: 20, systemPrompt: TRUE), $entry);

    $this->assertStringContainsString(str_repeat('x', 20) . "\n... [truncated, 50 bytes total]\n", $text);
    $this->assertStringContainsString(substr(self::HISTORY, 0, 20) . "\n... [truncated, " . strlen(self::HISTORY) . " bytes total]\n", $text);
    $this->assertStringNotContainsString('A bakery', $text);

    $collapsed = $this->entry(13, "user\n" . self::PAYLOAD . "\n", NULL, tags: ['daedalus_ai']);
    $short = $this->print(new FormatOptions(truncate: 200), $collapsed);
    $this->assertStringNotContainsString('truncated', $short);
    $this->assertStringContainsString('... [210 bytes base64]', $short);
  }

  /**
   * Empty columns print their comments and a raw string output prints as is.
   */
  public function testEmptyAndRawColumns(): void {
    $text = $this->print(new FormatOptions(), $this->entry(12, '', NULL));
    $this->assertStringContainsString("No prompt recorded.\n", $text);
    $this->assertStringContainsString("No output recorded.\n", $text);

    $raw = $this->print(new FormatOptions(), $this->entry(12, self::HISTORY, 'plain answer'));
    $this->assertStringContainsString("RESPONSE", $raw);
    $this->assertStringContainsString("\nplain answer\n", $raw);

    $unknown = new LogEntry(1, 1757400001, '', '', '', '', NULL, [], [], []);
    $header = $this->print(new FormatOptions(), $unknown);
    $this->assertStringContainsString('#1 | ' . date('Y-m-d H:i:s', 1757400001) . " | unknown / unknown | unknown\n", $header);
    $this->assertStringNotContainsString('Tags:', $header);
  }

  /**
   * The options know when the command must fetch the extra columns.
   */
  public function testNeedsExtras(): void {
    $this->assertFalse((new FormatOptions())->needsExtras());
    $this->assertFalse((new FormatOptions(full: TRUE, truncate: 10, messageLimit: 3))->needsExtras());
    $this->assertTrue((new FormatOptions(systemPrompt: TRUE))->needsExtras());
    $this->assertTrue((new FormatOptions(configuration: TRUE))->needsExtras());
    $this->assertTrue((new FormatOptions(tools: TRUE))->needsExtras());
    $this->assertTrue((new FormatOptions(extraData: TRUE))->needsExtras());
  }

  /**
   * The printed messages are exactly the delta's, role lines and all.
   */
  public function testMessagesRoundTrip(): void {
    $messages = [new Message('user', "two\nlines"), new Message('tool', '')];
    $delta = new TranscriptDelta(Transcript::fromMessages($messages), 3, 11, FALSE, FALSE, FALSE, FALSE);
    $text = $this->print(new FormatOptions(), $this->entry(12, self::HISTORY . "user\ntwo\nlines\ntool\n\n"), $delta);

    $this->assertStringContainsString("already printed]\nuser\ntwo\nlines\ntool\n\n", $text);
  }

}
