<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * A command that must reach the client only on the turn's FINAL response.
 *
 * A chat turn spans several HTTP requests (the daedalus_ai_memory continuation
 * loop polls once per LLM leg), and DeepChatResponseListener flushes the
 * UiCommandQueue into EVERY poll response. Most commands want exactly that:
 * a toolbar refresh belongs on the response of the leg that changed the
 * workspace. But a command with turn-level consequences (navigating away
 * from the polling document, kicking off deferred processing that owns the
 * chat panel) fired mid-turn would strand the remaining legs: the final
 * response would land in a dead document.
 *
 * Commands implementing this interface are therefore held by the listener
 * across the turn's intermediate poll responses and released together on the
 * final one (the response whose body carries no truthy should_continue).
 *
 * endOfTurnOrder() sorts the released commands: lower runs first. The
 * load-bearing ordering is TriggerDeferredCommand (0) before
 * OpenLinkCommand (10): the trigger's client handler stashes its batch to
 * localStorage, and that stash must exist before open-link navigates so the
 * destination page's daedalusAiDeferredCheck behavior finds it and runs the
 * deferred generations there.
 */
interface EndOfTurnCommandInterface extends CommandInterface {

  /**
   * Sort position among the turn's released end-of-turn commands.
   *
   * @return int
   *   Lower values are delivered (and executed) first. Ties keep queue
   *   order.
   */
  public function endOfTurnOrder(): int;

}
