<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Segment;

/**
 * Why a segment closes.
 *
 * Budget: the last leg's measured prompt size passed the effective budget.
 * Decided at a finished turn only, so a turn is never cut in half: the
 * carry-over is generated from the whole closing segment and the next user
 * turn opens the new one.
 *
 * Reframe: a reframing skill was invoked past the minimum segment size.
 * Decided at the leg boundary where the skill's result landed; the current
 * turn is re-sent after the carry-over so the doctrine sits near the
 * request and the model keeps the turn it is in.
 *
 * Ceiling: the last leg's measured prompt size passed the hard limit (the
 * smallest bound the provider declares, less the headroom), so the next
 * leg would be refused or the carry-over call would not fit. Decided at a
 * finished turn like Budget, and named before it; a turn that has not
 * finished is ended by the runner first (the headroom being the margin the
 * carry-over call runs in), so the close still lands on a finished turn
 * with the prefix cached.
 */
enum CloseReason: string {

  case Budget = 'budget';

  case Reframe = 'reframe';

  case Ceiling = 'ceiling';

}
