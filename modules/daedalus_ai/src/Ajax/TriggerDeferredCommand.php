<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Ajax;

/**
 * AJAX command that triggers client-side processing of deferred jobs.
 *
 * The browser receives the batch ID and entity context needed to POST to the
 * DeferredProcessingController. For the create flow (page navigation), the
 * payload is stashed in localStorage and picked up on the destination page.
 *
 * End-of-turn: deferred processing owns the chat panel (loading bubble,
 * disabled submit) and must not race the continuation loop's remaining
 * legs; the batch data itself is safe in tempstore until the command fires.
 * It sorts BEFORE OpenLinkCommand so the localStorage stash exists when a
 * created page's navigation lands and daedalusAiDeferredCheck runs there.
 */
class TriggerDeferredCommand implements EndOfTurnCommandInterface {

  /**
   * Constructs a TriggerDeferredCommand.
   *
   * @param string $batchId
   *   The PrivateTempStore key for this batch of generation jobs.
   * @param string $entityType
   *   The entity type ID.
   * @param string|int $entityId
   *   The entity ID.
   * @param string $viewMode
   *   The view mode for rendering refreshed components.
   * @param string[] $targetKeys
   *   Selectable keys of the elements being processed (for pending visual
   *   effects) — the same spine keys the mutation response's markup swaps
   *   use.
   * @param array $messages
   *   User-facing messages keyed by: loading, retry, success,
   *   partial_success, failure, error.
   */
  public function __construct(
    protected string $batchId,
    protected string $entityType,
    protected string|int $entityId,
    protected string $viewMode,
    protected array $targetKeys = [],
    protected array $messages = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'daedalusAiTriggerDeferred',
      'batchId' => $this->batchId,
      'entityType' => $this->entityType,
      'entityId' => (string) $this->entityId,
      'viewMode' => $this->viewMode,
      'targetKeys' => $this->targetKeys,
      'messages' => $this->messages,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function endOfTurnOrder(): int {
    return 0;
  }

}
