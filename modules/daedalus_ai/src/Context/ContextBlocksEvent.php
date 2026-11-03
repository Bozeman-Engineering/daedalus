<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Context;

use Drupal\ai\OperationType\GenericType\FileBaseInterface;
use Drupal\Component\EventDispatcher\Event;

/**
 * Asks the block producers what to state beside the user's words this turn.
 *
 * The memory runner dispatches it once per user turn with the turn's
 * context (what the chat client sent as `contexts`: the current route, the
 * selected elements, the attachment names, the view mode), the
 * conversation id the attachment store keys on, and whether the
 * turn opens a transcript segment. A producer answers with blocks of a
 * kind; the spine owns their wrapper text and placement, so a producer
 * states the fact and nothing else. A producer that delivers bytes with
 * the turn (an attached image, a PDF) adds the file here too; the renderer
 * puts it on the current user message, and the transcript never holds it.
 *
 * Blocks are kept in the order stated; the renderer places them by kind.
 * A producer never reads another's blocks and never reaches the agent: the
 * event is the one road from the turn's context to the model.
 */
final class ContextBlocksEvent extends Event {

  public const EVENT_NAME = 'daedalus_ai.context_blocks';

  /**
   * The blocks stated, in order.
   *
   * @var \Drupal\daedalus_ai\Context\Block[]
   */
  private array $blocks = [];

  /**
   * The files delivered with the turn, in order.
   *
   * @var \Drupal\ai\OperationType\GenericType\FileBaseInterface[]
   */
  private array $files = [];

  /**
   * Constructs the event.
   *
   * @param array $context
   *   The turn's context as the assistant passed it.
   * @param string $conversationId
   *   The conversation's id (the agent runner id, the request's thread).
   * @param bool $opensSegment
   *   Whether this turn opens a transcript segment (the first turn, or the
   *   first after a close): the blocks stated once per segment (the skills
   *   list) are asked for only then.
   */
  public function __construct(
    private readonly array $context,
    private readonly string $conversationId,
    private readonly bool $opensSegment,
  ) {
  }

  /**
   * The turn's context as the assistant passed it.
   */
  public function context(): array {
    return $this->context;
  }

  /**
   * The conversation's id.
   */
  public function conversationId(): string {
    return $this->conversationId;
  }

  /**
   * Whether this turn opens a transcript segment.
   */
  public function opensSegment(): bool {
    return $this->opensSegment;
  }

  /**
   * States a block for the turn.
   */
  public function addBlock(Block $block): void {
    $this->blocks[] = $block;
  }

  /**
   * The blocks stated, in order.
   *
   * @return \Drupal\daedalus_ai\Context\Block[]
   *   The blocks.
   */
  public function blocks(): array {
    return $this->blocks;
  }

  /**
   * Delivers a file's bytes with the turn.
   */
  public function addFile(FileBaseInterface $file): void {
    $this->files[] = $file;
  }

  /**
   * The files delivered with the turn, in order.
   *
   * @return \Drupal\ai\OperationType\GenericType\FileBaseInterface[]
   *   The files.
   */
  public function files(): array {
    return $this->files;
  }

}
