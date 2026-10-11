<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

/**
 * The result of a blueprint gesture: the persisted blueprint plus capture noise.
 *
 * Warnings come straight from the capture's instantiation (the batch's
 * warning channel): what the copy could not carry was dropped, never
 * silently. The gesture still stands; the author should know new
 * placements will differ there.
 */
final class BlueprintGestureResult {

  /**
   * Constructs a BlueprintGestureResult.
   *
   * @param \Drupal\daedalus_blueprint_library\BlueprintInterface $blueprint
   *   The saved blueprint entity.
   * @param string[] $warnings
   *   Human-readable warnings about what the capture could not carry.
   */
  public function __construct(
    public readonly BlueprintInterface $blueprint,
    public readonly array $warnings,
  ) {}

}
