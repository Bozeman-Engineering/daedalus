<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\daedalus_ai\Ajax\EndOfTurnCommandInterface;
use Drupal\Core\Ajax\CommandInterface;

/**
 * In-memory queue for AJAX commands to include in chat responses.
 *
 * Event subscribers call add() during the request to queue commands.
 * The kernel response listener calls flush() to retrieve rendered
 * commands before injecting them into the JSON response.
 *
 * Commands implementing EndOfTurnCommandInterface land in their own bucket:
 * they must ride only the turn's FINAL chat response, so the listener
 * flushes them separately (flushEndOfTurn()) and holds them across the
 * continuation loop's intermediate poll responses. Subscribers keep calling
 * plain add(); the class of the command decides the bucket.
 */
class UiCommandQueue {

  /**
   * Commands queued during this request.
   *
   * @var \Drupal\Core\Ajax\CommandInterface[]
   */
  private array $pending = [];

  /**
   * Pre-rendered command arrays queued during this request.
   *
   * @var array[]
   */
  private array $rendered = [];

  /**
   * End-of-turn commands queued during this request.
   *
   * @var \Drupal\daedalus_ai\Ajax\EndOfTurnCommandInterface[]
   */
  private array $endOfTurn = [];

  /**
   * Queues an AJAX command.
   *
   * @param \Drupal\Core\Ajax\CommandInterface $command
   *   The AJAX command to queue. An EndOfTurnCommandInterface command joins
   *   the end-of-turn bucket and only reaches the client on the turn's
   *   final response.
   */
  public function add(CommandInterface $command): void {
    if ($command instanceof EndOfTurnCommandInterface) {
      $this->endOfTurn[] = $command;
      return;
    }
    $this->pending[] = $command;
  }

  /**
   * Queues a pre-rendered command array.
   *
   * @param array $command
   *   A rendered AJAX command array.
   */
  public function addRendered(array $command): void {
    $this->rendered[] = $command;
  }

  /**
   * Returns rendered immediate commands and clears their queues.
   *
   * End-of-turn commands are NOT included; the listener drains those via
   * flushEndOfTurn() so it can hold them until the turn completes.
   *
   * @return array[]
   *   Rendered command arrays, or empty if nothing queued.
   */
  public function flush(): array {
    $result = $this->rendered;
    foreach ($this->pending as $command) {
      $result[] = $command->render();
    }
    $this->pending = [];
    $this->rendered = [];
    return $result;
  }

  /**
   * Returns the queued end-of-turn commands, rendered, and clears them.
   *
   * The entries keep queue order and carry each command's endOfTurnOrder()
   * so the listener can merge them with commands held from earlier legs of
   * the same turn and sort the merged set once, at release time.
   *
   * @return array<int, array{order: int, command: array}>
   *   One entry per queued end-of-turn command, in queue order.
   */
  public function flushEndOfTurn(): array {
    $result = [];
    foreach ($this->endOfTurn as $command) {
      $result[] = [
        'order' => $command->endOfTurnOrder(),
        'command' => $command->render(),
      ];
    }
    $this->endOfTurn = [];
    return $result;
  }

}
