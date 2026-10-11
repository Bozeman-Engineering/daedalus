<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Slot;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\layout_builder\Section;

/**
 * The innermost filler of a slot: the host and the section of its own.
 *
 * Answered by InnerRender::slotFiller() for a slot id and the subject a
 * render shows: the host (a page, later a template filling a frame slot)
 * whose own top-level section is tagged with the slot id, and that
 * section. The section is the host's own document fragment, read through
 * the host's doors, so it is the staged copy when the host stages.
 */
final class SlotFill {

  public function __construct(
    public readonly HostInterface $host,
    public readonly Section $section,
  ) {}

}
