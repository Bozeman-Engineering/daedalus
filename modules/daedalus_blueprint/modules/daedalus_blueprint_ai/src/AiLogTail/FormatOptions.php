<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\AiLogTail;

/**
 * How the tail prints its rows, as the command's options resolve to.
 *
 * The one mode switch is $full: friendly by default (only what a row adds
 * to its thread, base64 collapsed), the raw dump on --full (the whole
 * prompt as the model received it, every byte). The other members narrow
 * or widen the print in either mode.
 */
final readonly class FormatOptions {

  /**
   * Constructs FormatOptions.
   *
   * @param bool $full
   *   TRUE prints the raw dump: no thread dedupe, no base64 collapse.
   * @param int $truncate
   *   Cut the prompt and system prompt bodies past this many bytes; 0 keeps
   *   them whole.
   * @param int $messageLimit
   *   Print only the last N messages of what would print; 0 prints them all.
   *   Applies after the thread diff, so it rarely bites on a follow-on row
   *   but still caps a thread's first row, a diverged row and a standalone
   *   one.
   * @param bool $systemPrompt
   *   Include the SYSTEM PROMPT section (from the debug data).
   * @param bool $configuration
   *   Include the CONFIGURATION section.
   * @param bool $tools
   *   Include the TOOLS section (from the debug data).
   * @param bool $extraData
   *   Include the EXTRA DATA section (the whole debug data).
   */
  public function __construct(
    public bool $full = FALSE,
    public int $truncate = 0,
    public int $messageLimit = 0,
    public bool $systemPrompt = FALSE,
    public bool $configuration = FALSE,
    public bool $tools = FALSE,
    public bool $extraData = FALSE,
  ) {}

  /**
   * Whether any section needs the configuration or extra_data columns.
   *
   * The command fetches both columns only when a section reads them; the
   * tracker's system prompt and tool change flags stay FALSE otherwise.
   *
   * @return bool
   *   TRUE when at least one optional section is included.
   */
  public function needsExtras(): bool {
    return $this->systemPrompt || $this->configuration || $this->tools || $this->extraData;
  }

}
