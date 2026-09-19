<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * Identifies the flow a call runs in: the current fiber, or the main one.
 *
 * Drupal renders placeholders in fibers and core yields any fiber it runs
 * in whenever it can batch work across them (the alias manager, views
 * data and the theme registry all suspend from inside an entity load),
 * so a service's "for the duration of this call" state is only true of
 * the flow that set it: while one placeholder's validation is parked
 * mid-load, another placeholder's runs on the same service instance.
 * State scoped to a call keys on this id instead of living on the
 * service (StyleMapValidator's universe doors, VariantConformance's
 * recursion guard), which keeps a parked flow's state out of the flows
 * that run meanwhile and lets a guard tell recursion (the same flow
 * re-entering) from interleaving (another flow asking).
 */
final class FiberFlow {

  /**
   * The current flow's id: the running fiber's object id, 0 for the main flow.
   *
   * Object ids are stable for a fiber's lifetime and only reused after it
   * is destroyed, so a store keyed by them holds nothing stale as long as
   * every setter clears its key in a finally block.
   */
  public static function id(): int {
    $fiber = \Fiber::getCurrent();
    return $fiber === NULL ? 0 : spl_object_id($fiber);
  }

}
