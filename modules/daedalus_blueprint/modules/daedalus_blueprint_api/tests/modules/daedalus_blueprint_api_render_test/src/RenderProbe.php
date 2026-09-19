<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api_render_test;

use Drupal\daedalus_blueprint_api\Render\MutationRenderScope;

/**
 * Records the mutation-render flag as seen from inside an entity render.
 *
 * Static state, read and reset by the test in the same process: the probe
 * fires from hook_entity_view_alter, and a flag value cannot be asserted
 * after the render returns since the scope has left by then.
 */
final class RenderProbe {

  /**
   * The flag as observed by each render, in order.
   *
   * @var bool[]
   */
  public static array $seen = [];

  /**
   * Whether the next observed render throws.
   */
  public static bool $throw = FALSE;

  /**
   * Records the flag, then throws if the test asked for a failing render.
   */
  public static function observe(): void {
    self::$seen[] = MutationRenderScope::isActive(\Drupal::request());
    if (self::$throw) {
      self::$throw = FALSE;
      throw new \RuntimeException('The render failed on purpose.');
    }
  }

}
