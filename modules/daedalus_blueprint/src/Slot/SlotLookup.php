<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Slot;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * The answer of a slot lookup: the fill, if any, and what the walk read.
 *
 * Answered by InnerRender::slotFiller(). The walk beneath a slot's door
 * reads the subject's host and then each foreign owner up to the outer,
 * and the door's answer (the fill, else its default, else nothing)
 * depends on every document it read: a page that gains its first fill of
 * a frame slot, or a template that gains one for its bundle, flips the
 * door from default to fill, so the door must re-render when either
 * changes. The cacheability names each host walked, the one found
 * filling included (whether or not anything showed); the outer is never
 * among them, since the walk stops before it and the outer's own render
 * carries it. The door applies this to whatever it returns.
 */
final class SlotLookup {

  public function __construct(
    public readonly ?SlotFill $fill,
    public readonly CacheableMetadata $cacheability,
  ) {}

}
