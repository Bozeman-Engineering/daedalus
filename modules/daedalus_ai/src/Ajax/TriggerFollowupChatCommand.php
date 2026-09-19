<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * AJAX command that triggers a follow-up deep-chat turn for deferred work.
 *
 * The browser receives the rendered LLM-facing message plus the entity
 * context. The payload is stashed in sessionStorage so it survives the
 * navigation hop in the create-entity flow; the JS behavior picks it up and
 * runs the turn. The turn's page refresh needs no plumbing here: whatever
 * the follow-up turn mutates rides the chat response as mutation-response
 * payloads (blueprint_responses), applied through the app's applyResponse
 * pipeline like any other turn.
 */
class TriggerFollowupChatCommand implements CommandInterface {

  /**
   * Constructs a TriggerFollowupChatCommand.
   *
   * @param string $batchId
   *   A unique id for this batch of deferred work.
   * @param string $messageText
   *   The wire-format message body sent to the LLM as the follow-up turn.
   * @param string $entityType
   *   The entity type id.
   * @param string|int $entityId
   *   The entity id.
   */
  public function __construct(
    protected string $batchId,
    protected string $messageText,
    protected string $entityType,
    protected string|int $entityId,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'daedalusAiTriggerFollowup',
      'batchId' => $this->batchId,
      'messageText' => $this->messageText,
      'entityType' => $this->entityType,
      'entityId' => (string) $this->entityId,
    ];
  }

}
