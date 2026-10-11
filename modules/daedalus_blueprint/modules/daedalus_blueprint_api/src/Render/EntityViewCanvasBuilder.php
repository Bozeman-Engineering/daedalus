<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Render;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * The entity view as a host's canvas.
 *
 * A content host's canvas is its entity's own view build: on a canonical
 * page that build is the canvas root, and the editing UI (when installed)
 * takes it over from inside the entity view pipeline, so rendering the
 * view IS rendering the canvas. Applies to every host whose entity type
 * has a view builder; a display has none and is another builder's.
 */
final class EntityViewCanvasBuilder implements CanvasBuilderInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function applies(HostInterface $host): bool {
    return $this->entityTypeManager->hasHandler($host->entityTypeId(), 'view_builder');
  }

  /**
   * {@inheritdoc}
   */
  public function build(HostInterface $host, string $view_mode): array {
    return $this->entityTypeManager->getViewBuilder($host->entityTypeId())->view($host->entity(), $view_mode);
  }

}
