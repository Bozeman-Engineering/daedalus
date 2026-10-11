<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Markup;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * The markup-projection socket: collects and fronts tagged projectors.
 *
 * Consumers (the AI read tools' markup mode, write-result markup echoes)
 * always ask this service; the element model's owner registers its
 * projector with the `daedalus_blueprint.markup_projector` service tag.
 * Projectors answer in registration (priority) order and the first
 * non-NULL projection wins. With no projector installed every projection
 * is NULL — consumers surface that honestly (markup mode reports itself
 * unavailable) rather than inventing markup the model does not own.
 */
final class MarkupProjection implements MarkupProjectorInterface {

  /**
   * The collected projectors, in priority order.
   *
   * @var \Drupal\daedalus_blueprint\Markup\MarkupProjectorInterface[]
   */
  private array $projectors = [];

  /**
   * Collects a tagged projector (service_collector).
   */
  public function addProjector(MarkupProjectorInterface $projector): void {
    $this->projectors[] = $projector;
  }

  /**
   * Whether any projector is plugged into the socket.
   */
  public function hasProjector(): bool {
    return $this->projectors !== [];
  }

  /**
   * {@inheritdoc}
   */
  public function projectHost(HostInterface $host, ?string $langcode = NULL): ?string {
    foreach ($this->projectors as $projector) {
      $html = $projector->projectHost($host, $langcode);
      if ($html !== NULL) {
        return $html;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function projectElement(HostInterface $host, string $uuid, ?string $langcode = NULL): ?string {
    foreach ($this->projectors as $projector) {
      $html = $projector->projectElement($host, $uuid, $langcode);
      if ($html !== NULL) {
        return $html;
      }
    }
    return NULL;
  }

}
