<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\PendingMedia;

use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Drupal\media\MediaInterface;

/**
 * The outcome of minting a pending media entity.
 *
 * The media is always saved (identity is synchronous). Whether a generation
 * job was queued behind it depends on the image-generation gate: when the
 * gate is off, the placeholder file IS the final image, no job exists, and
 * a warning explains to the AI what happened and how the user can restore
 * generation.
 */
final class MintResult {

  /**
   * Constructs a MintResult.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The saved media:image entity carrying the placeholder file.
   * @param bool $jobQueued
   *   TRUE when a generation job was registered for the media's uuid.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning|null $warning
   *   The disabled-generation warning when the gate was off, NULL otherwise.
   *   Its path is empty; consumers that know a field path can rebuild it via
   *   PendingMediaMinter::buildDisabledWarning().
   */
  public function __construct(
    public readonly MediaInterface $media,
    public readonly bool $jobQueued,
    public readonly ?BlueprintWarning $warning = NULL,
  ) {}

}
