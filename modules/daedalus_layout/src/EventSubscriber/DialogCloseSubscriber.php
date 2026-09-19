<?php

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\Ajax\AjaxResponse;
use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Fixes core Layout Builder form responses for Edit Mode dialogs.
 *
 * Core's LayoutRebuildTrait closes `#drupal-off-canvas` when a Layout
 * Builder form submits, but Edit Mode opens those forms in ordinary dialogs
 * (`.ui-dialog-content`), so the close command must be retargeted or the
 * dialog stays open.
 *
 * The alternative to this is overriding all the layout builder forms which
 * proved brittle.
 */
class DialogCloseSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a DialogCloseSubscriber.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(
    protected RequestStack $requestStack,
  ) {}

  /**
   * Retargets off-canvas close commands to the Edit Mode dialog.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$response instanceof AjaxResponse) {
      return;
    }

    $request = $this->requestStack->getCurrentRequest();
    $sectionStorage = $request->attributes->get('section_storage');
    if (!$sectionStorage instanceof SectionStorageInterface) {
      return;
    }

    $commands = $response->getCommands();
    $modified = FALSE;

    foreach ($commands as $index => $command) {
      if ($this->isOffCanvasCloseCommand($command)) {
        $commands[$index]['selector'] = '.ui-dialog-content';
        $modified = TRUE;
      }
    }

    if ($modified) {
      $this->setCommands($response, $commands);
    }
  }

  /**
   * Checks if command is a CloseDialogCommand for off-canvas.
   *
   * @param array $command
   *   The AJAX command array.
   *
   * @return bool
   *   TRUE if this is a CloseDialogCommand targeting #drupal-off-canvas.
   */
  protected function isOffCanvasCloseCommand(array $command): bool {
    return ($command['command'] ?? '') === 'closeDialog'
      && ($command['selector'] ?? '') === '#drupal-off-canvas';
  }

  /**
   * Sets commands on an AjaxResponse using reflection.
   *
   * AjaxResponse doesn't provide a public method to modify commands after
   * they've been added, so we use reflection to update the internal array.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The AJAX response.
   * @param array $commands
   *   The modified commands array.
   */
  protected function setCommands(AjaxResponse $response, array $commands): void {
    $reflection = new \ReflectionProperty(AjaxResponse::class, 'commands');
    $reflection->setValue($response, $commands);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run before core's AjaxResponseSubscriber (-100).
    return [KernelEvents::RESPONSE => ['onResponse', -50]];
  }

}
