<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * A convergence that rewrites the style stores entities own, in place.
 *
 * A convergence retires one stored spelling across every store that
 * carries style data: host style maps ride daedalus_layout's transform
 * spine through a StyleMapSweepBase subclass, and the two entity stores
 * are walked directly through these two methods. The post_update hooks
 * and the on-demand drush commands (ConvergenceCommandBase) speak to a
 * convergence through this contract only, so the sequence that runs one
 * is written once.
 */
interface StyleStoreConvergenceInterface {

  /**
   * Converges every daedalus_variant's styles, across ALL revisions.
   *
   * Workspace-staged and draft revisions included, in place (a grammar
   * migration is not an editorial change). Shipped-unmodified variants
   * are left to the sync lane.
   *
   * @return string[]
   *   Report lines, each also logged to the daedalus_stylish channel.
   */
  public function convergeVariants(): array;

  /**
   * Converges every daedalus_skin's defaults, across ALL revisions.
   *
   * Shipped-unmodified package layers are left to the sync lane; the site
   * layer is the site's and converges either way.
   *
   * @return string[]
   *   Report lines, each also logged to the daedalus_stylish channel.
   */
  public function convergeSkins(): array;

}
