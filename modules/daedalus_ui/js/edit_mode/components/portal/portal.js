import { h } from 'preact';
import { useSelector } from 'react-redux';
import { useEffect, useRef, useState } from 'preact/hooks';
import store from '../../store/store';
import { createGeometryTracker, documentPosition } from '../../utilities/geometry-tracker';
import { resolve } from '../../selection/resolver';
import { chipPlacement } from '../selection-frame/selection-frame-model';
import { pencilIcon } from '../../verbs/rename-verb';
import { portalForTarget, portalIdentity, portalNavigation } from './portal-model';

/**
 * The portal chip: the hover affordance on a placed entity that is inert
 * here and edited at its own root (the site header and footer, page-builder
 * block_content placed in theme regions; portal-model.js for the concept).
 *
 * Lives BESIDE the selection frame, not in it. The frame draws the
 * selection universe (the projection) and a portal is outside that
 * universe by design: nothing inside it resolves, so it can never be
 * hovered, selected, or edited on this page. This component owns its own
 * pointer listeners instead, gated the way the Selector's grammar is
 * (selector-events.js: the Selector tool is current), and reads only the
 * two attributes the server stamped on the placement root
 * (DaedalusUiHooks::entityView): the editing URL and the label.
 *
 * Hover paints a stroke and a chip with the frame's own primitives: the
 * shared geometry tracker (the same createTracker contrib gets as
 * app.geometry.createTracker, with a resolver that tracks the hovered
 * element itself), chipPlacement for where the chip sits, and the
 * .daedalus-ui-frame__* classes so it reads as the frame's chip. The chip holds the
 * portal's label and ONE control: an Edit link. A real anchor, so
 * Cmd-click opens the editing surface in a new tab natively, and a plain
 * click navigates in this tab (the editing route arms Edit Mode with a
 * path-scoped cookie; staged edits on this page survive in tempstore).
 *
 * Double-click anywhere in the portal navigates too: it is the canvas's
 * existing drill gesture applied across the entity boundary. Single click
 * stays inert (the selector's keyless-click rule only clears inside the
 * main entity, and a portal sits outside it), so selection semantics do
 * not change and no accidental click leaves the page.
 */
const Portal = () => {
  const currentTool = useSelector((state) => state.tool.currentTool);
  const active = currentTool === 'selector';
  // The hovered portal's placement root; identity is the element itself,
  // never a key, because a portal has no key in the selection universe.
  const [portal, setPortal] = useState(null);

  const rootRef = useRef(null);
  const strokeRef = useRef(null);
  const anchorRef = useRef(null);
  // The listeners and the tracker read the hovered element through a ref
  // so they never close over a stale render.
  const portalRef = useRef(null);
  portalRef.current = portal;

  // One tracker per component instance (its element cache survives effect
  // restarts). The resolver is the hovered element while it is still in
  // the document; a markup swap that drops it hides the chip until the
  // next pointer move resolves a portal again.
  const trackerRef = useRef(null);
  if (!trackerRef.current) {
    trackerRef.current = createGeometryTracker({
      resolve: () => (portalRef.current?.isConnected ? portalRef.current : null),
      validate: (element) => element === portalRef.current,
    });
  }

  useEffect(() => {
    if (!active) {
      // Leaving the Selector tool drops the transient hover, as the
      // selector's own hover does.
      setPortal(null);
      return undefined;
    }

    const onMouseMove = (event) => {
      const next = portalForTarget(event.target, {
        chrome: rootRef.current,
        current: portalRef.current,
      });
      if (next !== portalRef.current) {
        setPortal(next);
      }
    };

    const onMouseLeave = () => {
      if (portalRef.current) {
        setPortal(null);
      }
    };

    // Capture phase like the Selector's drill listener. Both sit on the
    // document, so the drill's stopPropagation never reaches this one; the
    // resolver gate is what yields to the drill (portalNavigation).
    const onDoubleClick = (event) => {
      const target = portalForTarget(event.target);
      if (!target) {
        return;
      }
      const state = store.getState().selection;
      const url = portalNavigation({
        portal: target,
        resolvedKey: resolve(event.target, {
          drillKey: state.drillKey,
          selectedKeys: state.selectedKeys,
        }),
      });
      if (!url) {
        return;
      }
      // Not a text-selection gesture: leave the page.
      event.preventDefault();
      event.stopPropagation();
      window.location.assign(url);
    };

    document.addEventListener('mousemove', onMouseMove, { passive: true });
    document.documentElement.addEventListener('mouseleave', onMouseLeave);
    document.addEventListener('dblclick', onDoubleClick, true);
    return () => {
      document.removeEventListener('mousemove', onMouseMove);
      document.documentElement.removeEventListener('mouseleave', onMouseLeave);
      document.removeEventListener('dblclick', onDoubleClick, true);
    };
  }, [active]);

  useEffect(() => {
    const tracker = trackerRef.current;
    const identity = portalIdentity(portal);

    const hide = () => {
      if (strokeRef.current) {
        strokeRef.current.hidden = true;
      }
      if (anchorRef.current) {
        anchorRef.current.hidden = true;
      }
    };

    if (!identity) {
      hide();
      return undefined;
    }

    tracker.start(() => {
      const stroke = strokeRef.current;
      const anchor = anchorRef.current;
      const measured = tracker.measure('portal', identity.url);
      if (!measured || !stroke || !anchor) {
        hide();
        return;
      }
      const { rect } = measured;

      stroke.hidden = false;
      const strokePosition = documentPosition(rect);
      stroke.style.transform = `translate(${strokePosition.left}px, ${strokePosition.top}px)`;
      stroke.style.width = `${rect.width}px`;
      stroke.style.height = `${rect.height}px`;

      // Unhidden before measuring, or the chip's box reads as zero.
      anchor.hidden = false;
      const placed = chipPlacement({
        rect,
        chip: { width: anchor.offsetWidth, height: anchor.offsetHeight },
        viewport: { width: window.innerWidth, height: window.innerHeight },
        offsets: Drupal.displace?.offsets ?? {},
      });
      anchor.classList.toggle('daedalus-ui-frame__anchor--below', placed.below);
      const chipPosition = documentPosition(placed);
      anchor.style.transform = `translate(${chipPosition.left}px, ${chipPosition.top}px)`;
    });

    return () => tracker.stop();
  }, [portal]);

  if (!active) {
    return null;
  }

  const identity = portalIdentity(portal);
  const editLabel = Drupal.t('Edit');

  return (
    <div ref={rootRef} className="daedalus-ui-portal">
      <div ref={strokeRef} className="daedalus-ui-frame__stroke daedalus-ui-frame__stroke--hover" aria-hidden="true" hidden />
      <div ref={anchorRef} className="daedalus-ui-frame__anchor daedalus-ui-frame__anchor--portal" hidden>
        {identity && (
          <div
            className="daedalus-ui-frame__chip daedalus-ui-frame__chip--portal"
            role="group"
            aria-label={identity.label}
            data-portal-url={identity.url}
          >
            <span className="daedalus-ui-frame__name">{identity.label}</span>
            <div className="daedalus-ui-frame__verbs">
              <a
                className="daedalus-ui-frame__verb"
                href={identity.url}
                data-daedalus-ui-tooltip={editLabel}
                data-daedalus-ui-tooltip-position="top"
              >
                <span className="daedalus-ui-frame__icon" aria-hidden="true" dangerouslySetInnerHTML={{ __html: pencilIcon }} />
                <span className="daedalus-ui-frame__label">{editLabel}</span>
              </a>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default Portal;
