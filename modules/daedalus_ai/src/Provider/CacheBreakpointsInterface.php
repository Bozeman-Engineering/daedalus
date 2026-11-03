<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Provider;

/**
 * A provider plugin that places the cache breakpoints the spine asks for.
 *
 * The context spine renders a conversation into a ChatInput and describes,
 * on the RenderingPlan it attaches, where explicit cache breakpoints belong
 * (the stable prefix and chosen message indexes) and with which TTL. A
 * provider implementing this interface reads the plan and emits the
 * breakpoints in its own request shape (Anthropic's block-level
 * cache_control). A provider without it gets the floor: whatever automatic
 * caching the provider does on its own, and the spine places nothing.
 *
 * @see \Drupal\daedalus_ai\Provider\RenderingPlan
 */
interface CacheBreakpointsInterface {

  /**
   * The cache TTLs the provider can honor on a breakpoint for a model.
   *
   * @param string $model_id
   *   The model id the request will run on.
   *
   * @return string[]
   *   RenderingPlan::TTL_5M and/or RenderingPlan::TTL_1H; an empty list when
   *   the model caches nothing through explicit breakpoints.
   */
  public function supportedCacheTtls(string $model_id): array;

}
