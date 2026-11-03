<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Transcript;

use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\daedalus_ai\Provider\RenderingPlan;

/**
 * What the renderer hands the runner: the messages and the plan over them.
 *
 * The messages are the wrapper's chat history for the leg, in wire order;
 * the plan speaks about them by index (breakpoints, opaque content to
 * replay) and rides the ChatInput's request metadata for a provider that
 * declares the matching capability.
 */
final class Rendering {

  /**
   * Constructs a rendering.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $messages
   *   The messages in wire order.
   * @param \Drupal\daedalus_ai\Provider\RenderingPlan $plan
   *   The plan over them.
   */
  public function __construct(
    private readonly array $messages,
    private readonly RenderingPlan $plan,
  ) {
    foreach ($messages as $message) {
      if (!$message instanceof ChatMessage) {
        throw new \InvalidArgumentException('A rendering holds ChatMessage objects.');
      }
    }
  }

  /**
   * The messages in wire order.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatMessage[]
   *   The messages.
   */
  public function messages(): array {
    return $this->messages;
  }

  /**
   * The plan over the messages.
   */
  public function plan(): RenderingPlan {
    return $this->plan;
  }

  /**
   * The last message, or NULL when the rendering is empty.
   */
  public function last(): ?ChatMessage {
    return $this->messages === [] ? NULL : $this->messages[array_key_last($this->messages)];
  }

}
