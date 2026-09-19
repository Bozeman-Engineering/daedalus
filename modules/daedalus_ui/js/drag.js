((Drupal) => {
  'use strict';

  Drupal.Daedalus = Drupal.Daedalus || {};

  let active = null;

  /**
   * Ends the live drag.
   *
   * @param {string} reason
   *   'up' if the pointer was released on the handle (a commit), 'cancel'
   *   for every other way the gesture died (an abort).
   */
  const finish = (reason) => {
    const drag = active;
    if (!drag) {
      return;
    }
    active = null;
    drag.listeners.forEach(([target, type, listener, options]) => {
      target.removeEventListener(type, listener, options);
    });
    // Capture is usually gone before we get here — the browser releases it
    // implicitly on pointerup, and losing it is itself one of the
    // terminators. releasePointerCapture() throws on a pointer that is no longer active.
    if (drag.handle.hasPointerCapture(drag.pointerId)) {
      drag.handle.releasePointerCapture(drag.pointerId);
    }
    try {
      // A commit lands the latest pointer position: a move still gated
      // behind the frame clock is delivered before the drag ends, while
      // the marks' CSS locks are still on. An abort discards it.
      drag.settle(reason === 'up');
    } finally {
      // Marks come off before onEnd, unconditionally: whatever the caller's
      // callbacks do (or throw), a mark can never outlive its drag.
      drag.marks.forEach(([element, className]) => {
        element.classList.remove(className);
      });
      drag.onEnd({ reason });
    }
  };

  Drupal.Daedalus.drag = {
    /**
     * Starts a pointer drag, if the gesture is one we can finish.
     *
     * @param {Element} handle
     *   The element the gesture started on; receives pointer capture.
     * @param {PointerEvent} event
     *   The pointerdown event.
     * @param {object} callbacks
     *   Callbacks object.
     * @param {function} [callbacks.onMove]
     *   Called with pointermoves from the captured pointer, coalesced by
     *   the spine to at most one call per animation frame: the first move
     *   of a burst is delivered immediately (the leading call), moves
     *   landing inside an already-consumed frame collapse to the latest
     *   one (the trailing call). During continuous motion every delivery
     *   runs synchronously inside a pointermove event, never from a frame
     *   callback, so synchronous caller work (the width write + displace
     *   coupling in sidebar-resize.js) is in the DOM before per-frame
     *   chrome (geometry-tracker.js) measures it; only a stream that
     *   pauses gets its resting position delivered from the frame clock.
     *   A release delivers a still-gated move before onEnd; an abort
     *   discards it. Apply state directly here: the spine paces, callers
     *   do not throttle.
     * @param {function} [callbacks.onEnd]
     *   Called once with ({reason}) when the drag ends, whichever terminator
     *   fired: 'up' for a release on the handle, 'cancel' for an abort
     *   (pointercancel, lost capture, window blur, Escape, context menu).
     * @param {Array} [callbacks.marks]
     *   [element, className] pairs held while the drag is live: added when
     *   the gesture is accepted, removed on every terminator before onEnd
     *   runs. Page-wide CSS with teeth (cursor and selection locks,
     *   transition suppression) belongs here, not in caller-managed
     *   classes, so a mark can never outlive its drag.
     *
     * @return {boolean}
     *   TRUE if the drag started. FALSE if the gesture was refused, in
     *   which case nothing was captured, nothing was prevented, no mark was
     *   applied, and no callback will ever fire.
     */
    begin(
      handle,
      event,
      { onMove = () => {}, onEnd = () => {}, marks = [] } = {},
    ) {
      if (active) {
        return false;
      }
      // Secondary buttons and secondary touch points are not drags. Letting
      // one through is the prime stranding path: a right-click (or macOS
      // ctrl+click, which some engines report as the primary button) takes
      // capture, opens the context menu, and never delivers its pointerup —
      // preventDefault() on pointerdown does not suppress contextmenu.
      if (event.button !== 0 || event.isPrimary === false || event.ctrlKey) {
        return false;
      }

      event.preventDefault();
      handle.setPointerCapture(event.pointerId);

      // Frame pacing. The spine owns it: every consumer needs the same
      // coalescing, so no caller throttles its own work. The frame clock
      // only gates WHICH pointermove gets through; during continuous
      // motion every delivery runs synchronously inside a pointermove
      // event. That matters: per-frame chrome (geometry-tracker.js)
      // re-registers its rAF callback every frame, so it always measures
      // BEFORE a frame callback registered mid-stream, and a move applied
      // from rAF would paint chrome one frame behind the drag (the lag
      // sidebar-resize.js's synchronous displace coupling exists to
      // avoid). The one rAF delivery below runs solely when the pointer
      // has paused, where a one-frame chrome catch-up on a scene that is
      // no longer changing is invisible.
      let clock = null; // rAF id while the pacing clock runs.
      let open = true; // A move arriving now may be delivered.
      let queued = null; // Latest undelivered move while the gate was closed.
      let held = false; // queued already survived one open frame.

      const tick = () => {
        if (queued === null) {
          // A full frame with nothing pending: the stream is idle. Stop
          // the clock; the next move is a fresh leading call.
          clock = null;
          open = true;
          held = false;
          return;
        }
        if (!held) {
          // Open the gate but hold the queued move one more frame: a
          // fresher move arriving now supersedes it synchronously.
          open = true;
          held = true;
          clock = requestAnimationFrame(tick);
          return;
        }
        // No fresher move for a full open frame: the held move is the
        // pointer's resting position. Deliver it from the clock (the
        // trailing call).
        const moveEvent = queued;
        queued = null;
        held = false;
        open = false;
        clock = requestAnimationFrame(tick);
        onMove(moveEvent);
      };

      const deliver = (moveEvent) => {
        open = false;
        queued = null;
        held = false;
        if (clock === null) {
          clock = requestAnimationFrame(tick);
        }
        onMove(moveEvent);
      };

      // Ends the pacing on a terminator: the clock stops, and the gated
      // move is either committed (delivered while the marks are still on)
      // or discarded.
      const settle = (deliverQueued) => {
        if (clock !== null) {
          cancelAnimationFrame(clock);
          clock = null;
        }
        const moveEvent = queued;
        queued = null;
        held = false;
        open = false;
        if (deliverQueued && moveEvent !== null) {
          onMove(moveEvent);
        }
      };

      const move = (moveEvent) => {
        // Capture retargets this pointer's moves to the handle, but a second
        // pointer landing on it directly is reported here too.
        if (moveEvent.pointerId !== event.pointerId) {
          return;
        }
        if (open) {
          deliver(moveEvent);
        } else {
          queued = moveEvent;
        }
      };
      const end = (reason) => () => finish(reason);
      const escape = (keyEvent) => {
        if (keyEvent.key === 'Escape') {
          finish('cancel');
        }
      };

      active = {
        handle,
        pointerId: event.pointerId,
        onEnd,
        settle,
        marks,
        listeners: [
          [handle, 'pointermove', move, undefined],
          // The commit.
          [handle, 'pointerup', end('up'), undefined],
          // The pointer itself was taken away (a touch became a scroll, a
          // device was disconnected).
          [handle, 'pointercancel', end('cancel'), undefined],
          // Capture released without us asking: the window lost activation,
          // the handle was detached, a native drag started. Everything after
          // this retargets by hit-test, so pointerup lands somewhere else.
          [handle, 'lostpointercapture', end('cancel'), undefined],
          // Released outside the window, which some browsers report as no
          // event at all.
          [window, 'blur', end('cancel'), undefined],
          // A context menu opened over a live drag (a guard was bypassed, or
          // the platform opened it unprompted): no pointerup is coming.
          [window, 'contextmenu', end('cancel'), undefined],
          // The universal abort. Capture phase so no other handler can
          // swallow it first.
          [window, 'keydown', escape, true],
        ],
      };
      active.listeners.forEach(([target, type, listener, options]) => {
        target.addEventListener(type, listener, options);
      });
      marks.forEach(([element, className]) => {
        element.classList.add(className);
      });

      return true;
    },
  };
})(Drupal);
