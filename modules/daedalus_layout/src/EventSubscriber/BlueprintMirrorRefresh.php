<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\SettingsCommand;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_ui\BlueprintMirror;
use Drupal\daedalus_ui\ModeState;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Rides a fresh blueprint mirror envelope on core Layout Builder responses.
 *
 * Core's Layout Builder forms (configure block, configure section, remove,
 * move) are a write path that bypasses both the daedalus_blueprint op spine
 * and daedalus_edit's form pipeline, so their AJAX responses carry no blueprint
 * half. Without this, the client mirror — and the page elements panel
 * projected from it — keeps stale labels and structure until a reload
 * re-boots it.
 *
 * Same seam as DialogCloseSubscriber: adapting the response beats overriding
 * every Layout Builder form. The gate is identical (an AjaxResponse on a
 * section-storage route) plus Edit Mode, since these forms also serve the
 * stock Layout Builder admin UI where no mirror exists. The settings command
 * is PREPENDED so the rebuild's attachBehaviors — which triggers the
 * mirror's settings re-sync — runs after the merge has landed.
 */
class BlueprintMirrorRefresh implements EventSubscriberInterface {

  public function __construct(
    protected RequestStack $requestStack,
    protected ModeState $modeState,
    protected EntityStorageHandler $storageHandler,
    protected BlueprintMirror $blueprintMirror,
  ) {}

  /**
   * Prepends the staged root entity's envelope to the response settings.
   */
  public function onResponse(ResponseEvent $event): void {
    $response = $event->getResponse();
    if (!$response instanceof AjaxResponse) {
      return;
    }
    if ($this->modeState->getMode() !== 'edit') {
      return;
    }
    $storage = $this->requestStack->getCurrentRequest()->attributes->get('section_storage');
    if (!$storage instanceof SectionStorageInterface) {
      return;
    }
    // A path that already delivered the envelope (daedalus_edit's form pipeline
    // attaches it to the UpdateElement content) needs no second build.
    if (isset($response->getAttachments()['drupalSettings']['daedalusUi']['blueprint'])) {
      return;
    }

    if ($storage instanceof NestedSectionStorageInterface) {
      $storage = $storage->getRootStorage();
    }
    $contexts = $storage->getContexts();
    $entity = isset($contexts['entity']) ? $contexts['entity']->getContextValue() : NULL;
    if ($entity === NULL) {
      return;
    }
    // The staged root, wherever the submit that just ran wrote it (entity
    // tempstore, LB tempstore, or untouched in the db).
    $staged = $this->storageHandler->load($entity->getEntityTypeId(), $entity->id());
    if ($staged === NULL) {
      return;
    }

    $view_mode = isset($contexts['view_mode']) ? $contexts['view_mode']->getContextValue() : NULL;
    $view_mode = is_string($view_mode) && $view_mode !== '' ? $view_mode : 'full';
    $envelope = $this->blueprintMirror->envelope($staged, $view_mode);
    if ($envelope === NULL) {
      return;
    }
    $response->addCommand(new SettingsCommand(['daedalusUi' => ['blueprint' => $envelope]], TRUE), TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run before core's AjaxResponseSubscriber (-100), like
    // DialogCloseSubscriber.
    return [KernelEvents::RESPONSE => ['onResponse', -50]];
  }

}
