<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\AiLogTail;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders one ai_log row to the console.
 *
 * The shape is the same in both modes: a header line, the tags, the
 * optional SYSTEM PROMPT, then PROMPT and RESPONSE, then the optional
 * TOOLS, CONFIGURATION and EXTRA DATA sections. What differs is the PROMPT
 * body and the bytes: by default a follow-on row prints only the messages
 * its thread delta added (with a note naming the row they follow) and every
 * base64 blob collapses to one line; in full mode the row prints the whole
 * prompt as the model received it and nothing is collapsed. A NULL delta
 * (a standalone row, or full mode) prints the whole transcript either way.
 */
final class EntryFormatter {

  /**
   * The heavy rule that opens a row.
   */
  private const RULE = "\u{2501}";

  /**
   * The light rule that follows a section title.
   */
  private const SUB_RULE = "\u{2500}";

  /**
   * How many rule characters make a separator line.
   */
  private const WIDTH = 80;

  /**
   * The json_encode() flags for the JSON sections.
   */
  private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

  /**
   * The collapser for default mode.
   */
  private readonly Base64Collapser $collapser;

  /**
   * Constructs an EntryFormatter.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   Where the rows print.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\FormatOptions $options
   *   How they print.
   */
  public function __construct(
    private readonly OutputInterface $output,
    private readonly FormatOptions $options,
  ) {
    $this->collapser = new Base64Collapser();
  }

  /**
   * Prints one row.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry $entry
   *   The row.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\Transcript $transcript
   *   The row's parsed prompt (parsed once by the caller, shared with the
   *   tracker).
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\TranscriptDelta|null $delta
   *   What the row adds to its thread, or NULL to print the whole prompt
   *   (a standalone row, or full mode, which ignores a delta anyway).
   */
  public function format(LogEntry $entry, Transcript $transcript, ?TranscriptDelta $delta = NULL): void {
    $this->header($entry);
    if ($this->options->systemPrompt) {
      $this->systemPromptSection($entry);
    }
    $this->promptSection($entry, $transcript, $this->options->full ? NULL : $delta);
    $this->responseSection($entry);
    if ($this->options->tools) {
      $this->jsonSection('TOOLS', $entry->chatTools(), 'No tools recorded.');
    }
    if ($this->options->configuration) {
      $this->jsonSection('CONFIGURATION', $entry->configuration, 'No configuration recorded.');
    }
    if ($this->options->extraData) {
      $this->jsonSection('EXTRA DATA', $entry->extraData, 'No extra data recorded.');
    }
  }

  /**
   * Prints the row's header line and tags.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry $entry
   *   The row.
   */
  private function header(LogEntry $entry): void {
    $date = date('Y-m-d H:i:s', $entry->created);
    $provider = $entry->provider !== '' ? $entry->provider : 'unknown';
    $model = $entry->model !== '' ? $entry->model : 'unknown';
    $operation = $entry->operationType !== '' ? $entry->operationType : 'unknown';

    $this->output->writeln('');
    $this->output->writeln(str_repeat(self::RULE, self::WIDTH));
    $this->output->writeln("<info>#{$entry->id}</info> | {$date} | {$provider} / {$model} | {$operation}");
    if ($entry->tags !== []) {
      $this->output->writeln('Tags: ' . implode(', ', $entry->tags));
    }
  }

  /**
   * Prints the SYSTEM PROMPT section.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry $entry
   *   The row.
   */
  private function systemPromptSection(LogEntry $entry): void {
    $this->section('SYSTEM PROMPT');
    $systemPrompt = $entry->systemPrompt();
    if ($systemPrompt === '') {
      $this->comment('No system prompt recorded.');
      return;
    }
    $this->output->writeln($this->truncate($this->collapseText($systemPrompt)));
  }

  /**
   * Prints the PROMPT section.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry $entry
   *   The row.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\Transcript $transcript
   *   The whole parsed prompt.
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\TranscriptDelta|null $delta
   *   The thread delta to print instead of the whole prompt, or NULL.
   */
  private function promptSection(LogEntry $entry, Transcript $transcript, ?TranscriptDelta $delta): void {
    $this->section('PROMPT');
    foreach ($this->deltaNotes($delta) as $note) {
      $this->comment($note);
    }
    if ($transcript->count() === 0) {
      $this->comment('No prompt recorded.');
      return;
    }

    $printable = $delta?->transcript ?? $transcript;
    if ($printable->count() === 0) {
      return;
    }
    $sliced = $printable->slice($this->options->messageLimit);
    if ($sliced->count() < $printable->count()) {
      $hidden = $printable->count() - $sliced->count();
      $this->comment("[last {$sliced->count()} of {$printable->count()} messages; {$hidden} earlier hidden by --messages]");
    }
    $this->output->writeln($this->truncate($this->collapseText($sliced->toString())));
  }

  /**
   * The notes a thread delta earns above its messages.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\TranscriptDelta|null $delta
   *   The delta.
   *
   * @return string[]
   *   The notes, none on a thread's first row or without a delta.
   */
  private function deltaNotes(?TranscriptDelta $delta): array {
    if ($delta === NULL || $delta->isFirst()) {
      return [];
    }
    $notes = [];
    if ($delta->diverged) {
      $notes[] = "[transcript diverged from #{$delta->sinceId}; printed whole]";
    }
    else {
      $count = $delta->transcript->count();
      $word = $count === 1 ? 'message' : 'messages';
      $notes[] = "[+{$count} {$word} since #{$delta->sinceId}, {$delta->hiddenCount} already printed]";
    }
    if ($delta->systemPromptChanged) {
      $notes[] = "[system prompt changed since #{$delta->sinceId}]";
    }
    if ($delta->toolsChanged) {
      $notes[] = "[tools changed since #{$delta->sinceId}]";
    }
    return $notes;
  }

  /**
   * Prints the RESPONSE section.
   *
   * @param \Drupal\daedalus_blueprint_ai\AiLogTail\LogEntry $entry
   *   The row.
   */
  private function responseSection(LogEntry $entry): void {
    $this->section('RESPONSE');
    $decoded = $entry->decodedOutput();
    if ($decoded === NULL) {
      $this->comment('No output recorded.');
      return;
    }
    $this->output->writeln($this->render($decoded));
  }

  /**
   * Prints one of the optional JSON sections.
   *
   * @param string $title
   *   The section title.
   * @param array $data
   *   The decoded data.
   * @param string $empty
   *   The comment to print when there is none.
   */
  private function jsonSection(string $title, array $data, string $empty): void {
    $this->section($title);
    if ($data === []) {
      $this->comment($empty);
      return;
    }
    $this->output->writeln($this->render($data));
  }

  /**
   * Renders a decoded value for printing.
   *
   * @param mixed $value
   *   A decoded JSON document, or the raw string when the column was not
   *   JSON.
   *
   * @return string
   *   Pretty JSON for an array, the string itself, or the JSON form of any
   *   other scalar; base64 collapsed unless in full mode.
   */
  private function render(mixed $value): string {
    if (!$this->options->full) {
      $value = $this->collapser->collapse($value);
    }
    if (is_string($value)) {
      return $value;
    }
    return json_encode($value, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
  }

  /**
   * Collapses base64 in a text body unless in full mode.
   *
   * @param string $text
   *   The text.
   *
   * @return string
   *   The text, collapsed by default.
   */
  private function collapseText(string $text): string {
    return $this->options->full ? $text : $this->collapser->collapse($text);
  }

  /**
   * Cuts a body past the truncate option.
   *
   * @param string $text
   *   The body, after any collapse.
   *
   * @return string
   *   The body, or its head plus a note of the whole length.
   */
  private function truncate(string $text): string {
    $limit = $this->options->truncate;
    if ($limit <= 0 || strlen($text) <= $limit) {
      return $text;
    }
    return substr($text, 0, $limit) . "\n... [truncated, " . strlen($text) . ' bytes total]';
  }

  /**
   * Prints a section title line.
   *
   * @param string $title
   *   The title.
   */
  private function section(string $title): void {
    $this->output->writeln('');
    $this->output->writeln(self::SUB_RULE . self::SUB_RULE . " {$title} " . str_repeat(self::SUB_RULE, self::WIDTH));
  }

  /**
   * Prints a line in the comment style.
   *
   * @param string $text
   *   The line.
   */
  private function comment(string $text): void {
    $this->output->writeln("<comment>{$text}</comment>");
  }

}
