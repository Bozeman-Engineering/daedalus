<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai;

/**
 * Holds the current conversation's stable identity for the tool layer.
 *
 * The dialect alias layer persists author-chosen ids per entity PER
 * CONVERSATION (options['conversation'] on the op spine), and the page
 * tools run deep inside the agent loop where the runner's job id is not
 * reachable. The runner stamps the job id onto the agent (setRunnerId);
 * the pass-context subscriber copies it here; the page tools read it.
 * Request-scoped by construction: one agent run per request.
 */
final class ConversationContext {

  /**
   * The current conversation's id, '' until a run stamps one.
   */
  private string $conversation = '';

  /**
   * Records the current conversation's id for the page tools.
   */
  public function set(string $conversation): void {
    $this->conversation = $conversation;
  }

  /**
   * The current conversation's id, '' when no run has stamped one.
   */
  public function get(): string {
    return $this->conversation;
  }

}
