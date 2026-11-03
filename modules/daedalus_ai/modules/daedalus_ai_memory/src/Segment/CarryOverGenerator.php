<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Segment;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\daedalus_ai_memory\Transcript\EntryType;
use Drupal\daedalus_ai_memory\Transcript\Rendering;

/**
 * Generates a closing segment's carry-over through the provider.
 *
 * The carry-over is what the next segment opens with: the user's intent,
 * the decisions and constraints they stated, their standing preferences,
 * what was tried and rejected, draft text still in the chat, the page in
 * hand, the files the conversation holds, and which keyed facts were
 * loaded, worded for the model. It is produced by one chat call that
 * reuses the closing segment's exact prefix (the tools and system prompt
 * as the last leg sent them, then the segment's rendered messages), so
 * the whole conversation is a cache read and only the instruction and the
 * answer are paid in full. The tools stay on the request for that reason
 * (they render first, and the system block's cache marker covers them),
 * and the instruction tells the model to answer in text alone.
 *
 * When the rendered messages end on an assistant message with tool calls
 * still unanswered (a reframe close lands mid-turn, after the skill's
 * result and the model's next call), that last message is left off the
 * request: a user message after unanswered tool calls is refused by the
 * providers, and the calls are re-sent with the current turn in the new
 * segment, where the wrapper answers them. Everything before it is still
 * the cached prefix.
 */
final class CarryOverGenerator {

  /**
   * The request tag naming a carry-over call, beside the runner's tags.
   */
  public const TAG = 'daedalus_ai_carry_over';

  /**
   * The instruction the carry-over call ends with.
   */
  public const INSTRUCTION = <<<'TEXT'
<system-reminder>
The working transcript is about to be reset at this point; the user's chat continues unbroken and they will not see this message or your answer to it. Write the carry-over that the next part of the conversation opens with, so that nothing the user established is lost. Answer in plain text only, with no tool call, and address the model that will read it, not the user.

Cover, under short headings, only what applies:
- Intent: what the user is making and the outcome they want.
- Decisions and constraints: what they decided, approved, ruled out or asked you to keep, including anything about the page's structure, content or styling.
- Preferences: standing preferences on voice, style, tone and how they like to work with you.
- Tried and rejected: what was attempted and why it did not stand.
- Draft text: any text still being worked on in the chat that has not landed on the page, verbatim.
- The page in hand: which entity is being worked on, by type and id, and the state it is in; the newest print in the conversation is its current state, so do not reproduce the print.
- Files: the files the conversation holds, by the names the ATTACHMENTS blocks gave them.
- Loaded facts: the line below, verbatim, so the next part knows what was consulted.

Be complete on what matters and terse on everything else; a few hundred words at most.
</system-reminder>
TEXT;

  /**
   * The carry-over request: the closing segment's prefix plus the ask.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $template
   *   The last leg's input, for the system prompt, the tools and the
   *   hostname filter exactly as the wrapper sent them.
   * @param \Drupal\daedalus_ai_memory\Transcript\Rendering $rendering
   *   The closing segment rendered for the same provider and model.
   * @param string[] $loadedKeys
   *   The keys of the facts loaded in the segment
   *   (Transcript::loadedKeys()).
   *
   * @throws \LogicException
   *   When the rendering holds no message to carry over from.
   */
  public static function request(ChatInput $template, Rendering $rendering, array $loadedKeys): ChatInput {
    $messages = $rendering->messages();
    $last = $rendering->last();
    if ($last !== NULL && $last->getRole() === EntryType::Assistant->role() && ($last->getTools() ?? []) !== []) {
      array_pop($messages);
    }
    if ($messages === []) {
      throw new \LogicException('A carry-over needs a segment with at least one message to carry over from.');
    }
    foreach ($loadedKeys as $key) {
      if (!is_string($key) || $key === '') {
        throw new \InvalidArgumentException('Loaded keys are non-empty strings.');
      }
    }
    $messages[] = new ChatMessage(EntryType::User->role(), self::INSTRUCTION . "\n\n" . self::loadedLine($loadedKeys));

    $request = new ChatInput($messages);
    $request->setSystemPrompt($template->getSystemPrompt());
    if (($tools = $template->getChatTools()) !== NULL) {
      $request->setChatTools($tools);
    }
    if (($filter = $template->getHostnameFilter()) !== NULL) {
      $request->setHostnameFilter($filter);
    }
    $request->setStreamedOutput(FALSE);
    $rendering->plan()->attachTo($request);
    return $request;
  }

  /**
   * Runs the carry-over request and answers the carry-over text.
   *
   * @param object $provider
   *   The provider plugin (or its proxy) the segment's legs ran on.
   * @param string $modelId
   *   The model id the segment's legs ran on.
   * @param \Drupal\ai\OperationType\Chat\ChatInput $request
   *   The request from self::request().
   * @param string[] $tags
   *   The runner's tags for the leg (the runner id and the closing
   *   segment, so the ledger attributes the call); self::TAG is added.
   *
   * @throws \RuntimeException
   *   When the model answered with a tool call or with no text: the
   *   segment cannot close on that answer, and the caller decides whether
   *   to keep it open for now.
   */
  public function generate(object $provider, string $modelId, ChatInput $request, array $tags): string {
    $output = $provider->chat($request, $modelId, [...$tags, self::TAG]);
    if (!$output instanceof ChatOutput) {
      throw new \RuntimeException(sprintf('The carry-over call answered a %s, not a chat output.', get_debug_type($output)));
    }
    $message = $output->getNormalized();
    if (!$message instanceof ChatMessage) {
      throw new \RuntimeException('The carry-over call streamed its answer although the request asked for none.');
    }
    if (($message->getTools() ?? []) !== []) {
      throw new \RuntimeException('The carry-over call answered with a tool call instead of the carry-over text.');
    }
    $text = trim($message->getText());
    if ($text === '') {
      throw new \RuntimeException('The carry-over call answered with no text.');
    }
    return $text;
  }

  /**
   * The line naming the facts loaded in the closing segment.
   *
   * @param string[] $loadedKeys
   *   The keys, in load order.
   */
  private static function loadedLine(array $loadedKeys): string {
    return 'Loaded facts: ' . ($loadedKeys === [] ? 'none.' : implode(', ', $loadedKeys) . '.');
  }

}
