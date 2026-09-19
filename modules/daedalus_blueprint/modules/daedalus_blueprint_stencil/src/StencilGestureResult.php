<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

/**
 * The result of a stencil gesture: the persisted stencil plus capture noise.
 *
 * Warnings come straight from the capture (StencilExport): styling the
 * stencil grammar cannot express was dropped, never silently. The gesture
 * still stands; the author should know new placements will differ there.
 */
final class StencilGestureResult {

  /**
   * Constructs a StencilGestureResult.
   *
   * @param \Drupal\daedalus_blueprint_stencil\StencilInterface $stencil
   *   The saved stencil entity.
   * @param string[] $warnings
   *   Human-readable warnings about styling the grammar cannot carry.
   */
  public function __construct(
    public readonly StencilInterface $stencil,
    public readonly array $warnings,
  ) {}

}
