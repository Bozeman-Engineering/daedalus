<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai_log_repair\EventSubscriber;

use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rebuilds unserializable chat raw output before subscribers log it.
 *
 * Core's Renderer::executeInRenderContext() wraps every controller in a
 * \Fiber, and OpenAiBasedProviderClientBase::chat() reacts to any active
 * Fiber by switching to streamed transport even for non-streamed calls. That
 * branch consumes the stream and reconstructs a proper ChatMessage, but hands
 * ChatOutput the raw OpenAI StreamResponse object, which json_encode()s to
 * "{}". ai_logging then stores "{}" as output_text, and because the
 * normalized output is a plain ChatMessage (not an iterator) the
 * post-streaming update that would repair the row never registers.
 *
 * This subscriber runs before ai_logging and swaps in a ChatOutput whose raw
 * output is a chat.completion-shaped array rebuilt from the reconstructed
 * message, so logs carry real content. The consumed stream object holds
 * nothing recoverable, so no information is lost by replacing it.
 */
final class StreamedRawOutputRepairSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Priority above ai_logging's default 0 so the repaired output is what
    // gets logged.
    return [
      PostGenerateResponseEvent::EVENT_NAME => ['repairRawOutput', 100],
    ];
  }

  /**
   * Replaces object raw output with a serializable reconstruction.
   *
   * @param \Drupal\ai\Event\PostGenerateResponseEvent $event
   *   The post generate response event.
   */
  public function repairRawOutput(PostGenerateResponseEvent $event): void {
    $output = $event->getOutput();
    if (!$output instanceof ChatOutput) {
      return;
    }
    $normalized = $output->getNormalized();
    $raw = $output->getRawOutput();
    // Only the fiber-consumed case: a reconstructed ChatMessage paired with a
    // raw output object. Arrays and scalars serialize fine; a still-unconsumed
    // streamed response has an iterator as its normalized output and is
    // handled by the ai_logging post-streaming update instead.
    if (!$normalized instanceof ChatMessage || !is_object($raw) || $raw instanceof \JsonSerializable) {
      return;
    }

    $message = [
      'role' => $normalized->getRole() ?: 'assistant',
      'content' => $normalized->getText(),
    ];
    $tool_calls = [];
    foreach ($normalized->getTools() ?? [] as $tool) {
      $tool_calls[] = $tool->getOutputRenderArray();
    }
    if ($tool_calls) {
      $message['tool_calls'] = $tool_calls;
    }

    $usage = $output->getTokenUsage();
    $rebuilt = [
      'object' => 'chat.completion',
      'model' => $event->getModelId(),
      'choices' => [
        [
          'index' => 0,
          'message' => $message,
          'finish_reason' => $tool_calls ? 'tool_calls' : 'stop',
        ],
      ],
      'usage' => [
        'prompt_tokens' => $usage->input,
        'completion_tokens' => $usage->output,
        'total_tokens' => $usage->total,
      ],
      '_reconstructed' => 'Rebuilt from the streamed response consumed inside a Fiber; the original ' . get_class($raw) . ' cannot be serialized.',
    ];

    $repaired = new ChatOutput($normalized, $rebuilt, $output->getMetadata(), $usage);
    $repaired->setRateLimits($output->getRateLimits());
    $event->setOutput($repaired);
  }

}
