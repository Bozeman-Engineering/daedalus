<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\EventSubscriber;

use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes pending history capture at the end of every request.
 *
 * The fallback half of the capture contract: mutation paths that know their
 * gesture (the blueprint endpoint) annotate and flush the recorder
 * themselves so their response can carry fresh timeline metadata; every
 * other path that writes tempstore (form-swap submits, AI tools, core
 * Layout Builder forms) is caught here. flush() is a no-op when nothing is
 * pending, so both halves can run in one request.
 */
class HistoryResponseSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected HistoryRecorder $recorder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Late, so capture reflects everything the request wrote.
    return [
      KernelEvents::RESPONSE => ['onResponse', -900],
    ];
  }

  /**
   * Persists any tempstore writes this request staged as history.
   */
  public function onResponse(ResponseEvent $event): void {
    if ($event->isMainRequest()) {
      $this->recorder->flush();
    }
  }

}
