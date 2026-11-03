<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger\EventSubscriber;

use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PostStreamingResponseEvent;
use Drupal\ai\OperationType\Chat\StreamedChatMessageIteratorInterface;
use Drupal\daedalus_ai_ledger\UsageLedger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Feeds the ledger from drupal/ai's response events.
 *
 * Every provider call on the site passes through ProviderProxy, which
 * dispatches the post-response event with the output; a streamed chat
 * reply carries no usage there yet (the iterator has not run), so that
 * leg is written from the post-streaming event the iterator dispatches
 * once the stream ends, and the post-response event is skipped for it.
 */
final class UsageLedgerSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly UsageLedger $ledger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PostGenerateResponseEvent::EVENT_NAME => 'onPostGenerate',
      PostStreamingResponseEvent::EVENT_NAME => 'onPostStreaming',
    ];
  }

  /**
   * Records a finished, non-streamed leg.
   */
  public function onPostGenerate(PostGenerateResponseEvent $event): void {
    if ($event->getOutput()->getNormalized() instanceof StreamedChatMessageIteratorInterface) {
      return;
    }
    $this->ledger->record($event);
  }

  /**
   * Records a streamed leg once its stream has ended.
   */
  public function onPostStreaming(PostStreamingResponseEvent $event): void {
    $this->ledger->record($event);
  }

}
