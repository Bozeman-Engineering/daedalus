<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Transcript;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\GenericType\FileBaseInterface;
use Drupal\daedalus_ai\Context\Block;
use Drupal\daedalus_ai\Context\BlockKind;
use Drupal\daedalus_ai\Provider\CacheBreakpointsInterface;
use Drupal\daedalus_ai\Provider\MidConversationSystemInterface;
use Drupal\daedalus_ai\Provider\OpaqueContentInterface;
use Drupal\daedalus_ai\Provider\ProviderCapabilities;
use Drupal\daedalus_ai\Provider\RenderingPlan;

/**
 * Renders a transcript's current segment for a provider.
 *
 * The transcript is provider-neutral; the renderer turns its current
 * segment into the wrapper's ChatMessage list and a RenderingPlan, reading
 * each shape's rendering off the provider's capabilities and falling back
 * to the floor, which needs no provider cooperation:
 *
 * - Every block (the segment-open carry-over and skills list, then the
 *   current page, print, selection and attachments) is text in a
 *   <system-reminder> wrapper prepended to the user's words of the message
 *   it belongs to, on every provider: the universal form, and the one the
 *   economics favor (a block kept in the history costs its cached-read
 *   price per later leg, a tenth of its uncached price; a form that clears
 *   it per turn saves only that tenth and, cleared at the next user-role
 *   message, is leg-scoped in a tool loop). The spine owns the header
 *   (worded for its own turn, so an earlier turn's block never claims to
 *   be current once it has accumulated) and the producer owns the body.
 * - A system entry (a reminder the wrapper appended mid-conversation) is a
 *   system message in place where MidConversationSystemInterface answers,
 *   and a user message carrying the reminder text otherwise (the contrib
 *   providers fold a system-role history message into the top-level
 *   prompt, which rewrites the cached prefix).
 * - An assistant entry's opaque content rides the plan by index where
 *   OpaqueContentInterface answers and the entry's opaque pair is the
 *   provider and model the leg will run on; a provider without the
 *   interface replays nothing, and content produced under another pair
 *   (the user switched models) or with no pair recorded (a record from
 *   before the pair was kept) is left off the plan, because a thinking
 *   signature is bound to the model that signed it: replayed under
 *   another it is dropped on Anthropic and undefined elsewhere, and a
 *   switch should cost one cache rewrite, not a degraded leg.
 * - Where CacheBreakpointsInterface answers, the plan asks for the prefix
 *   breakpoint and one on the last rendered message, at the TTL asked for
 *   when the model honors it (else the first TTL it does). The tail marker
 *   that moves with every leg inside the wrapper's loop is the provider's
 *   own to place.
 *
 * Tool calls are rebuilt as drupal/ai ToolsFunctionOutput objects carrying
 * id, name and arguments, which is what a provider renders and the wrapper
 * serializes; the function's input definition is not attached, since no
 * reader of a history message needs it. Building them runs each string
 * argument through the ai module's hostname filter (ToolsPropertyResult
 * does that in its constructor), so the runner scopes the filter around a
 * render the way it does around the wrapper's own rehydration.
 */
final class TranscriptRenderer {

  /**
   * The order blocks are rendered in on a user message, by kind.
   *
   * The segment-open facts first, then the page, what is selected on it,
   * what came with the message, and the user's words last.
   */
  private const ORDER = [
    BlockKind::CarryOver,
    BlockKind::Skills,
    BlockKind::CurrentPage,
    BlockKind::Print,
    BlockKind::Selection,
    BlockKind::Attachments,
  ];

  /**
   * The header per kind, worded for the message it rides.
   */
  private const HEADERS = [
    'carry_over' => 'Carried over from earlier in this conversation (the working transcript was reset here; the user\'s chat continues unbroken, and what follows is what had been established):',
    'skills' => 'The following skills are available for use with the Skill tool:',
    'current_page' => 'Current page (as of this message):',
    'print' => 'Current page print (as of this message; the page in the page language, complete; a later message\'s print supersedes it):',
    'selection' => 'ACTIVE SELECTION (as of this message; the user has selected the following element(s) on the page; a later message\'s selection supersedes it):',
    'attachments' => 'ATTACHMENTS (the user attached the following file(s) to this message; address a file by its name, and view_file("<name>") re-opens it later):',
  ];

  /**
   * Renders the transcript's current segment for a provider and model.
   *
   * @param \Drupal\daedalus_ai_memory\Transcript\Transcript $transcript
   *   The transcript; its current segment is rendered whole.
   * @param object $provider
   *   The provider plugin (or its proxy) the leg will run on.
   * @param string $modelId
   *   The model id the leg will run on.
   * @param string $ttl
   *   The cache TTL asked for, one of RenderingPlan::TTLS.
   * @param \Drupal\ai\OperationType\GenericType\FileBaseInterface[] $turnFiles
   *   The files delivered with the current turn (from the context blocks
   *   event), placed on the last user message; none on a leg that is not
   *   the turn's first.
   *
   * @throws \LogicException
   *   When the transcript has no open segment.
   */
  public function render(Transcript $transcript, object $provider, string $modelId, string $ttl = RenderingPlan::TTL_5M, array $turnFiles = []): Rendering {
    foreach ($turnFiles as $file) {
      if (!$file instanceof FileBaseInterface) {
        throw new \InvalidArgumentException('Turn files must be FileBaseInterface objects.');
      }
    }

    $segment = $transcript->currentSegment();
    $breakpoints = ProviderCapabilities::as($provider, CacheBreakpointsInterface::class);
    $mid_system = ProviderCapabilities::as($provider, MidConversationSystemInterface::class)?->supportsMidConversationSystem($modelId) ?? FALSE;
    // Opaque content is replayed only under the pair that produced it.
    $opaque_pair = ProviderCapabilities::has($provider, OpaqueContentInterface::class)
      ? Entry::pairOf((string) $provider->getPluginId(), $modelId)
      : NULL;

    $plan = RenderingPlan::empty();
    $messages = [];
    $first_user = TRUE;
    $last_user = NULL;
    foreach ($segment->entries() as $entry) {
      switch ($entry->type) {
        case EntryType::User:
          $blocks = $entry->blocks;
          if ($first_user) {
            if ($segment->carryOver() !== NULL) {
              array_unshift($blocks, new Block(BlockKind::CarryOver, $segment->carryOver()));
            }
            $first_user = FALSE;
          }
          $blocks = self::ordered($blocks);
          $text = $blocks === [] ? $entry->text : self::blocksText($blocks) . "\n\n" . $entry->text;
          $message = new ChatMessage(EntryType::User->role(), $text);
          $messages[] = $message;
          $last_user = $message;
          break;

        case EntryType::Assistant:
          $message = new ChatMessage(EntryType::Assistant->role(), $entry->text);
          if ($entry->toolCalls !== []) {
            $message->setTools(array_map([self::class, 'functionOutput'], $entry->toolCalls));
          }
          if ($opaque_pair !== NULL && $entry->opaque !== NULL && $entry->opaquePair === $opaque_pair) {
            $plan = $plan->withOpaque(count($messages), $entry->opaque);
          }
          $messages[] = $message;
          break;

        case EntryType::ToolResult:
          $message = new ChatMessage(EntryType::ToolResult->role(), $entry->text);
          if ($entry->toolId !== NULL) {
            $message->setToolsId($entry->toolId);
          }
          $messages[] = $message;
          break;

        case EntryType::System:
          $messages[] = $mid_system
            ? new ChatMessage(EntryType::System->role(), $entry->text)
            : new ChatMessage(EntryType::User->role(), self::reminder($entry->text));
          break;
      }
    }

    if ($turnFiles !== []) {
      if ($last_user === NULL) {
        throw new \LogicException('Turn files were given for a segment with no user turn to carry them.');
      }
      foreach ($turnFiles as $file) {
        $last_user->setFile($file);
      }
    }

    if ($breakpoints !== NULL) {
      $supported = $breakpoints->supportedCacheTtls($modelId);
      $resolved = in_array($ttl, $supported, TRUE) ? $ttl : ($supported[0] ?? NULL);
      if ($resolved !== NULL) {
        $plan = $plan->withPrefixTtl($resolved);
        if ($messages !== []) {
          $plan = $plan->withBreakpoint(count($messages) - 1, $resolved);
        }
      }
    }

    return new Rendering($messages, $plan);
  }

  /**
   * One block as the model reads it: the header, then the producer's body.
   */
  public static function blockText(Block $block): string {
    return self::reminder(self::HEADERS[$block->kind->value] . "\n" . $block->text);
  }

  /**
   * The blocks' texts, in order, as one run.
   *
   * @param \Drupal\daedalus_ai\Context\Block[] $blocks
   *   The blocks.
   */
  private static function blocksText(array $blocks): string {
    return implode("\n\n", array_map([self::class, 'blockText'], $blocks));
  }

  /**
   * Text in the reminder wrapper the Context Tags protocol names.
   */
  private static function reminder(string $text): string {
    return "<system-reminder>\n" . $text . "\n</system-reminder>";
  }

  /**
   * The blocks in rendering order, stable within a kind.
   *
   * @param \Drupal\daedalus_ai\Context\Block[] $blocks
   *   The blocks as stated.
   *
   * @return \Drupal\daedalus_ai\Context\Block[]
   *   The blocks by self::ORDER.
   */
  private static function ordered(array $blocks): array {
    $ordered = [];
    foreach (self::ORDER as $kind) {
      foreach ($blocks as $block) {
        if ($block->kind === $kind) {
          $ordered[] = $block;
        }
      }
    }
    return $ordered;
  }

  /**
   * A tool call as drupal/ai carries it on an assistant message.
   */
  private static function functionOutput(ToolCall $call): ToolsFunctionOutput {
    $output = new ToolsFunctionOutput(NULL, $call->id, $call->arguments);
    $output->setName($call->name);
    return $output;
  }

}
