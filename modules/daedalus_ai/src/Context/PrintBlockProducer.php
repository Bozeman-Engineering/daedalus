<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Context;

use Drupal\daedalus_ai\RouteEntityResolver;
use Drupal\daedalus_blueprint\Dialect\HostPrinter;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * States the print block: the current page in the page language.
 *
 * The model's first write should need no read: the page the user is
 * looking at is the context of nearly every request, so its dialect print
 * (the same language page_edit writes and the cached catalog teaches) is
 * stated with every turn. Aliases the conversation has minted print back
 * as data-alias, keyed by the conversation id the event carries.
 *
 * The print reads the STAGED host (the storage handler routes to the
 * tempstore copy when one exists), because the canvas shows staged state,
 * not the saved revision. Access rides on RouteEntityResolver: a path the
 * current user cannot view resolves to NULL and nothing is stated. The
 * resolver answers a host, so a template route (core's manage-display
 * layout route) prints the display's document the same way a page prints
 * its own, headed by a note that its field blocks show sample content.
 *
 * The print is HostPrinter's, the one composition every reader of a page
 * shares: the tree stamped for the current user (an element the user
 * cannot fully edit carries data-can), printed against the live catalog
 * with the conversation's aliases. The host-level reason (a page
 * rendering its template, a frozen translation) rides the current page
 * block. The print is total: whatever the page language cannot spell
 * prints in the read-only register, and the print's own reading notes
 * head the document, once, to say what those annotations mean. The
 * reading conventions live in the Context Tags protocol on the cached
 * system prompt; the renderer's header says the print is this message's
 * and that a later message's print supersedes it, since the transcript
 * keeps every turn's print.
 */
final class PrintBlockProducer implements EventSubscriberInterface {

  /**
   * The first line of a template's print.
   */
  public const TEMPLATE_NOTE = 'This print is a template, not a page: its field blocks show sample content, never a real entity\'s.';

  public function __construct(
    private readonly RouteEntityResolver $routeEntityResolver,
    private readonly EntityStorageHandler $storageHandler,
    private readonly HostPrinter $hostPrinter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ContextBlocksEvent::EVENT_NAME => 'onContextBlocks',
    ];
  }

  /**
   * States the routed host's dialect print for the turn.
   */
  public function onContextBlocks(ContextBlocksEvent $event): void {
    $context = $event->context();
    if (empty($context['current_route'])) {
      return;
    }
    $host = $this->routeEntityResolver->resolve($context['current_route']);
    if (!$host) {
      return;
    }

    // The canvas shows the staged state; print that host, not the
    // last-saved one. The resolver already gated view access on the host.
    $staged = $this->storageHandler->load($host->entityTypeId(), (string) $host->id()) ?? $host;
    // A template prints as a page does, headed as a template: its field
    // blocks are placements whose content is sample, and editing it
    // reaches every entity of its kind without a layout of its own.
    $template = $staged->fields() === NULL;

    $print = $this->hostPrinter->print($staged, $event->conversationId());
    if (trim($print->text) === '') {
      $body = $template
        ? '(the template is empty: no layout content yet)'
        : '(the page is empty: no layout content yet)';
    }
    else {
      $body = $print->forReader();
    }
    if ($template) {
      $body = self::TEMPLATE_NOTE . "\n\n" . $body;
    }

    $event->addBlock(new Block(BlockKind::Print, $body));
  }

}
