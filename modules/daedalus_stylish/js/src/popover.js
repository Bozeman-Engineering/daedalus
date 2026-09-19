import { h, render } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';

/**
 * The popover posture behind a trigger: the inspector's adapter over the
 * shared floating surface (Drupal.Daedalus.popover, library
 * daedalus_ui/popover).
 *
 * Every popover in the inspector (the swatch and choice wells, the
 * color field, the media grid, the gradient builder's stop palette,
 * the variant picker) floats over the rows below it on the one surface
 * every pop-up in the chrome rides. The surface owns placement
 * (anchored under the trigger, end aligned, flipping above when short,
 * clamped into the viewport), dismissal (an outside press, scroll,
 * resize, blur), Escape (closes, consumed before the edit-mode hotkey
 * dispatcher on document sees the press, focus back onto the opener)
 * and the one-surface-per-window rule. What the popover shows is the
 * consumer's render, portaled into the surface's root: the inspector is
 * a separately built bundle, and a Preact tree cannot cross into the
 * plain-DOM host any other way. The satellite window declares the
 * library too and so runs its own copy of the surface; `Drupal` is read
 * at call time so each window reaches its own.
 *
 * A hook, not a component, for the two-slot reason: the trigger sits
 * inline on its row while the popover renders elsewhere (under body),
 * and preact renders one subtree per component. The trigger stays the
 * consumer's element; it spreads `triggerProps` (ref, toggle,
 * aria-haspopup / aria-expanded) so the surface knows its anchor, and
 * the consumer wraps its popover vnode in `portal()` wherever its JSX
 * places it. A consumer with several triggers over one popover (the
 * wear bar's add row and swap triggers) points `triggerRef.current` at
 * the trigger that opens as it opens, instead of spreading
 * `triggerProps`; placement, dismissal and focus return then address
 * the opener. The trigger is focused as the surface opens, so Escape's
 * focus return lands on it whichever way it was pressed (a pointer, a
 * hotkey, a synthetic click).
 *
 * @return {{open: boolean, setOpen: Function, triggerRef: object,
 *   triggerProps: object, portal: Function}}
 *   `setOpen(next)` takes a boolean or an updater over the current open
 *   state; `portal(vnode)` renders the vnode into the open surface (null
 *   while closed).
 */
export const usePopover = () => {
  // The surface root while this consumer's popover is the open one.
  // State for the render (the portal target), a ref for the handlers
  // (the surface's onClose and the toggle read the latest without a
  // re-render between).
  const [root, setRoot] = useState(null);
  const rootRef = useRef(null);
  const triggerRef = useRef(null);

  const close = () => {
    // Only this consumer's surface: the surface is one per window, and
    // a second open() elsewhere has already fired onClose for ours.
    if (rootRef.current !== null) {
      Drupal.Daedalus.popover.close();
    }
  };

  const open = () => {
    if (rootRef.current !== null) {
      return;
    }
    const trigger = triggerRef.current;
    if (!trigger) {
      throw new Error(
        'A popover opens under its trigger; point triggerRef at it before opening.',
      );
    }
    trigger.focus();
    const surface = Drupal.Daedalus.popover.open({
      anchor: trigger,
      align: 'end',
      onClose: () => {
        rootRef.current = null;
        setRoot(null);
      },
    });
    rootRef.current = surface;
    setRoot(surface);
  };

  const setOpen = (next) => {
    const resolved =
      typeof next === 'function'
        ? next(rootRef.current !== null)
        : Boolean(next);
    if (resolved) {
      open();
    } else {
      close();
    }
  };

  // A consumer leaving the tree takes its popover with it: the portal's
  // closures would otherwise outlive the row they act on.
  useEffect(() => close, []);

  const triggerProps = {
    ref: triggerRef,
    'aria-haspopup': 'listbox',
    'aria-expanded': root !== null,
    onClick: () => setOpen((current) => !current),
  };

  const portal = (vnode) =>
    root === null ? null : <Portal root={root}>{vnode}</Portal>;

  return { open: root !== null, setOpen, triggerRef, triggerProps, portal };
};

/**
 * Renders its children into the surface root as a second preact root.
 *
 * Not preact/compat's createPortal: importing compat installs its
 * React-parity option hooks for the WHOLE bundle (onBlur becomes
 * focusout, onChange on a text input becomes input), which rewrites
 * every field's settle semantics behind the inspector's back. Core
 * render() into the root is the portal without the side effects: the
 * nested render runs inside this component's own render (the shape
 * compat's Portal takes), so the content re-renders whenever its
 * consumer does and unmounts with it. The inspector passes no context
 * down, so the second root loses nothing.
 */
function Portal({ root, children }) {
  const rendered = useRef(null);
  if (rendered.current !== null && rendered.current !== root) {
    // The surface was replaced under this consumer (a re-open): the old
    // root is already off the document; its tree unmounts so its effects
    // clean up.
    render(null, rendered.current);
  }
  rendered.current = root;
  useEffect(
    () => () => {
      render(null, rendered.current);
      rendered.current = null;
    },
    [],
  );
  render(children, root);
  return null;
}
