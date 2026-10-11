<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Where a refused gesture can be made instead: another host.
 *
 * A refusal carries a portal only when the actor may act there (an editor
 * who cannot open the template gets no door to a locked room). The spine
 * names the host; a surface that renders links derives the URL from it.
 */
final class Portal {

  public function __construct(
    public readonly string $entityTypeId,
    public readonly string $id,
    public readonly string $label,
  ) {}

  /**
   * The portal to a host.
   */
  public static function toHost(HostInterface $host): self {
    return new self($host->entityTypeId(), (string) $host->id(), $host->label());
  }

  /**
   * The portal as error context.
   *
   * @return array{entity_type: string, entity_id: string, label: string}
   *   The host's identity.
   */
  public function toArray(): array {
    return [
      'entity_type' => $this->entityTypeId,
      'entity_id' => $this->id,
      'label' => $this->label,
    ];
  }

}
