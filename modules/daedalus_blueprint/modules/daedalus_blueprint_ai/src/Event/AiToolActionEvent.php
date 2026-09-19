<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when an AI tool performs a notable action.
 *
 * Subscribers can react to tool actions (e.g. entity persistence) without
 * coupling to specific tool implementations.
 */
class AiToolActionEvent extends Event {

  /**
   * Constructs an AiToolActionEvent.
   *
   * @param string $action
   *   The action that occurred (e.g. 'entity_persisted').
   * @param string $toolId
   *   The tool plugin ID (e.g. 'daedalus_blueprint:edit').
   * @param array $context
   *   Arbitrary payload keyed by action type. For 'entity_persisted': entity,
   *   is_new, targets.
   */
  public function __construct(
    protected readonly string $action,
    protected readonly string $toolId,
    protected readonly array $context = [],
  ) {}

  /**
   * Gets the action type.
   *
   * @return string
   *   The action string.
   */
  public function getAction(): string {
    return $this->action;
  }

  /**
   * Gets the tool plugin ID.
   *
   * @return string
   *   The plugin ID.
   */
  public function getToolId(): string {
    return $this->toolId;
  }

  /**
   * Gets the context payload.
   *
   * @return array
   *   The context array.
   */
  public function getContext(): array {
    return $this->context;
  }

}
