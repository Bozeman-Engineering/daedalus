<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layouts;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_stylish\StyleMapSweepBase;

/**
 * Repoints one retired variant name to its new one across a host's map.
 *
 * The VariantRename host walk's ride on daedalus_layout's transform spine.
 * Deliberately NOT a tagged host transform: VariantRename::walkHosts()
 * hands the walker a private registry holding one instance, so the sweep
 * runs exactly once per host revision in the rename post-update and never
 * on presave. A permanent old-to-new sweep would rewrite legitimate
 * wearings once the old name is minted again for another variant.
 */
class VariantRenameSweep extends StyleMapSweepBase {

  public function __construct(
    protected VariantRename $rename,
    protected string $from,
    protected string $to,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function sweep(array $map, HostInterface $host, string $context, array &$lines): ?array {
    return $this->rename->renameInStyleMap($map, $this->from, $this->to, $context, $lines);
  }

}
