<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Render;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint_api\Render\CanvasBuilderInterface;
use Drupal\daedalus_ui\Event\EditableUiBuilder;
use Drupal\daedalus_ui\ModeState;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The editable UI as the canvas of a host with no entity view.
 *
 * A content host's canvas is its entity view (the entity view pipeline
 * runs the editable UI builder from inside, EntityViewAlter). A host
 * whose entity type has no view builder (a Layout Builder display, the
 * template host) has no such pipeline, so its canvas is built directly:
 * the EditableUiBuilder event over an empty build, which daedalus_layout
 * answers with the layout_builder_plus element as the canvas root (the
 * root wrapper and the blueprint mirror ride the element there). Plugged
 * into daedalus_blueprint_api's canvas-builder socket, so the mutation
 * re-render and LoadEditablePage reach it through the one door.
 */
final class EditableUiCanvasBuilder implements CanvasBuilderInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ModeState $modeState,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(HostInterface $host): bool {
    return !$this->entityTypeManager->hasHandler($host->entityTypeId(), 'view_builder');
  }

  /**
   * {@inheritdoc}
   */
  public function build(HostInterface $host, string $view_mode): array {
    $event = $this->eventDispatcher->dispatch(new EditableUiBuilder([], $this->modeState->getMode(), $host, $view_mode), EditableUiBuilder::class);
    $build = $event->getBuild();
    // The canvas is the user's own editing session: never a shared cache
    // entry.
    $build['#cache']['max-age'] = 0;
    return $build;
  }

}
