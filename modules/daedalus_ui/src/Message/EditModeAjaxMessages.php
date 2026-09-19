<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Message;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\daedalus_ui\DaedalusUi;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Drupal\daedalus_ui\Ajax\EditModeMessageCommand;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles Edit Mode message delivery via AJAX commands.
 *
 * Provides both:
 * - Generic message delivery method for AJAX responses
 * - Automatic delivery via event subscriber for AJAX responses.
 *
 * NOTE: HTML responses are handled by EditModeHtmlMessages
 * lazy builder during the render phase, not here.
 */
final class EditModeAjaxMessages implements EventSubscriberInterface {

  public function __construct(
    protected DaedalusUi $daedalusUi,
    protected EditModeMessenger $messenger,
  ) {}

  /**
   * Delivers messages to an AJAX response.
   *
   * This can be called programmatically (e.g., from forms that need to
   * pass element_id for error highlighting) or automatically via the
   * event subscriber (for general AJAX responses).
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The AJAX response to add commands to.
   * @param string|null $element_id
   *   Optional element ID for form field association.
   */
  public function deliver(AjaxResponse $response, ?string $element_id = NULL): void {
    $messages = $this->messenger->getStoredMessages();

    if (empty($messages)) {
      return;
    }

    foreach ($messages as $type => $type_messages) {
      foreach ($type_messages as $message) {
        $response->addCommand(new EditModeMessageCommand((string) $message, $type, $element_id));
      }
    }

    $this->messenger->clearStoredMessages();
  }

  /**
   * Delivers stored messages to AJAX responses.
   *
   * Event subscriber callback that runs on all responses.
   */
  public function onResponse(ResponseEvent $event): void {
    if ($this->daedalusUi->getMode() !== 'edit') {
      return;
    }

    if (!$event->isMainRequest()) {
      return;
    }

    $response = $event->getResponse();

    // Only handle AJAX responses
    // HTML responses are handled by EditModeHtmlMessages lazy builder.
    if (!$response instanceof AjaxResponse) {
      return;
    }

    $this->deliver($response);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Run at -50 (after most processing, before final rendering)
      KernelEvents::RESPONSE => ['onResponse', -50],
    ];
  }

}
