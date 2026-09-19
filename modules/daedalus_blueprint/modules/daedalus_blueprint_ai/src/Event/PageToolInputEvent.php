<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched with the raw input a page tool received.
 *
 * The spine emits a fact, nothing more: what page_create / page_edit /
 * page_read was handed, exactly as it arrived, before any guard or parse
 * touches it. Diagnostic consumers (daedalus_blueprint_ai_dev_tools' capture
 * subscriber) subscribe to it; the production path carries no dev
 * dependency and no behavior rides on this event.
 *
 * Lifecycle: the tool dispatches the event at the very top of execute()
 * with the raw context values. When the tool later reaches a parse verdict
 * on that input (page_create's document parse, page_edit's spine batch),
 * it sets the verdict on the SAME instance and dispatches it again, so a
 * subscriber keyed on the received timestamp can complete its record. A
 * tool that never parses (page_read) dispatches only once and the verdict
 * stays NULL — as it does when the tool dies before parsing.
 */
final class PageToolInputEvent extends Event {

  /**
   * The parse verdict, once the tool has one. NULL until then.
   *
   * @var array|null
   */
  private ?array $verdict = NULL;

  /**
   * Unix timestamp (microsecond float) of when the input was received.
   *
   * Captured at construction, so both dispatches of the one execution
   * carry the same moment and map to the same capture record.
   *
   * @var float
   */
  private readonly float $receivedTime;

  /**
   * Constructs a PageToolInputEvent.
   *
   * @param string $toolId
   *   The tool id as the AI surface speaks it: the function name the model
   *   called (page_create, page_edit, page_read).
   * @param string $conversation
   *   The conversation id the tool ran under (the runner's job id, via
   *   ConversationContext; empty string when none is set).
   * @param array $contextValues
   *   The tool's raw context values as received, keyed by parameter name.
   *   Unset optional parameters are NULL.
   */
  public function __construct(
    private readonly string $toolId,
    private readonly string $conversation,
    private readonly array $contextValues,
  ) {
    $this->receivedTime = microtime(TRUE);
  }

  /**
   * Gets the tool id (the function name the model called).
   */
  public function getToolId(): string {
    return $this->toolId;
  }

  /**
   * Gets the conversation id ('' when none is set).
   */
  public function getConversation(): string {
    return $this->conversation;
  }

  /**
   * Gets the raw context values as received, keyed by parameter name.
   */
  public function getContextValues(): array {
    return $this->contextValues;
  }

  /**
   * Gets the moment the input was received (Unix timestamp, microseconds).
   */
  public function getReceivedTime(): float {
    return $this->receivedTime;
  }

  /**
   * Gets the parse verdict, or NULL when the input was not (yet) parsed.
   *
   * @return array|null
   *   ['valid' => bool, 'errors' => array] once set.
   */
  public function getVerdict(): ?array {
    return $this->verdict;
  }

  /**
   * Sets the parse verdict the tool reached on this input.
   *
   * @param array $verdict
   *   ['valid' => bool, 'errors' => array]: the errors are JSON-safe
   *   scalars/arrays (dialect errors as strings, spine errors as their
   *   toArray() shape).
   */
  public function setVerdict(array $verdict): void {
    $this->verdict = $verdict;
  }

}
