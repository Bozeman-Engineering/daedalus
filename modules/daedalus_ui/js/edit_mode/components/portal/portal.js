import { h } from 'preact';
import { useSelector } from 'react-redux';
import { useEffect, useRef, useState } from 'preact/hooks';
import store from '../../store/store';
import { createGeometryTracker, documentPosition } from '../../utilities/geometry-tracker';
import { resolve, elementForKey } from '../../selection/resolver';
import { isDoubleClick } from '../../selection/double-click';
import { chipPlacement } from '../selection-frame/selection-frame-model';
import { pencilIcon } from '../../verbs/rename-verb';
import {
  portalChipLabel,
  portalForTarget,
  portalIdentity,
  portalKindWord,
  portalNavigation,
  portalShowing,
  portalUnder,
} from './portal-model';
import { portalActionsFor } from './portal-actions';

/**
 * The portal chip: the hover affordance on a placed document that is inert
 * here and edited at its own root (a linked blueprint such as the site
 * header and footer, a node of the site frame around the page, a
 * template's node on a locked page; portal-model.js for the concept).
 *
 * Lives BESIDE the selection frame, not in it. The frame draws the
 * selection universe (the projection) and a portal is outside that
 * universe by design: nothing inside it resolves, so it can never be
 * hovered, selected, or edited on this page. This component owns its own
 * pointer listeners instead, gated the way the selection grammar is
 * (selector-events.js: Edit Mode is on), and reads only the
 * attributes the server stamped on the placement root
 * (LayoutBuilderBlockAttributes::stampShowing): the editing URL, the
 * label, the kind word of the document the portal opens, and a slot
 * door's claim.
 *
 * Hover paints a stroke and a chip with the frame's own primitives: the
 * shared geometry tracker (the same createTracker contrib gets as
 * app.geometry.createTracker, with a resolver that tracks the hovered
 * element itself), chipPlacement for where the chip sits, and the
 * .daedalus-ui-frame__* classes so it reads as the frame's chip. The chip holds the
 * portal's label and, beside it, its kind ("Header · blueprint",
 * portalChipLabel), an Edit link when the portal has an editing URL, and
 * the registered doorways that apply (portal-actions.js: a module's
 * gesture that is this page's, made on what the portal shows, such as
 * claiming a slot door's default). The registry takes the spine's showing
 * and the door's component uuid, so the chip reads the stamps back into
 * the showing's shape (portalShowing) and hands the placement root's
 * data-layout-builder-block-uuid along; the tree's shown-document row
 * calls the same run from the annotation. The Edit link is a real anchor, so
 * Cmd-click opens the editing surface in a new tab natively, and a plain
 * click navigates in this tab (the editing route arms Edit Mode with a
 * path-scoped cookie; staged edits on this page survive in tempstore).
 *
 * Double-click anywhere in the portal navigates too: it is the canvas's
 * existing drill gesture applied across the entity boundary, taken
 * through Drupal.Daedalus.Portal.leave() (selection-facade.js), the one
 * leave every portal gesture shares. Single click stays inert (the
 * selector's keyless-click rule only clears inside the main entity, and a
 * portal sits outside it), so selection semantics do not change and no
 * accidental click leaves the page.
 */
const Portal = () => {
  const mode = useSelector((state) => state.mode.mode);
  const active = mode === 'edit';
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
      // Leaving Edit Mode drops the transient hover, as the selection's
      // own hover does.
      setPortal(null);
      return undefined;
    }

    const onMouseMove = (event) => {
      const candidate = portalForTarget(event.target, {
        chrome: rootRef.current,
        current: portalRef.current,
      });
      let next = candidate;
      if (candidate) {
        // A slot door's fill is the page's own: a key resolved inside the
        // portal sets it aside (portalUnder), as the double-click's gate
        // does; the chip's own chrome resolves nothing and keeps it.
        const state = store.getState().selection;
        const resolvedKey = resolve(event.target, {
          drillKey: state.drillKey,
          selectedKeys: state.selectedKeys,
        });
        next = portalUnder({
          portal: candidate,
          resolvedKey,
          resolvedElement: elementForKey(resolvedKey),
        });
      }
      if (next !== portalRef.current) {
        setPortal(next);
      }
    };

    const onMouseLeave = () => {
      if (portalRef.current) {
        setPortal(null);
      }
    };

    // Capture phase `click` like the Selector's drill listener, reading the
    // double-click off the click's OS count the same way (double-click.js:
    // the even click closes the pair). Both sit on the document, so the
    // drill's stopPropagation never reaches this one; the resolver gate is
    // what yields to the drill (portalNavigation), and it needs the
    // resolved key's ELEMENT to place the key against the portal: a key
    // resolved above the portal (a block of the page holding the
    // placement) is the page's and the gesture still leaves.
    const onDoubleClick = (event) => {
      if (!isDoubleClick(event)) {
        return;
      }
      const target = portalForTarget(event.target);
      if (!target) {
        return;
      }
      const state = store.getState().selection;
      const resolvedKey = resolve(event.target, {
        drillKey: state.drillKey,
        selectedKeys: state.selectedKeys,
      });
      const url = portalNavigation({
        portal: target,
        resolvedKey,
        resolvedElement: elementForKey(resolvedKey),
      });
      if (!url) {
        return;
      }
      // Not a text-selection gesture: leave the page through the one
      // leave (selection-facade.js), which waits for writes still on the
      // wire and carries this page as the way back.
      event.preventDefault();
      event.stopPropagation();
      Drupal.Daedalus.Portal.leave(url);
    };

    document.addEventListener('mousemove', onMouseMove, { passive: true });
    document.documentElement.addEventListener('mouseleave', onMouseLeave);
    document.addEventListener('click', onDoubleClick, true);
    return () => {
      document.removeEventListener('mousemove', onMouseMove);
      document.documentElement.removeEventListener('mouseleave', onMouseLeave);
      document.removeEventListener('click', onDoubleClick, true);
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
  const showing = portalShowing(identity);
  // The placement root is the block build the server stamped; its component
  // uuid is the join the registry's run() is told about.
  const uuid = portal?.getAttribute('data-layout-builder-block-uuid') ?? null;
  const actions = portalActionsFor(showing);

  return (
    <div ref={rootRef} className="daedalus-ui-portal">
      <div ref={strokeRef} className="daedalus-ui-frame__stroke daedalus-ui-frame__stroke--hover" aria-hidden="true" hidden />
      <div ref={anchorRef} className="daedalus-ui-frame__anchor daedalus-ui-frame__anchor--portal" hidden>
        {identity && (
          <div
            className="daedalus-ui-frame__chip daedalus-ui-frame__chip--portal"
            role="group"
            aria-label={portalChipLabel(identity)}
            data-portal-url={identity.url ?? undefined}
            data-portal-type={identity.type ?? undefined}
          >
            <span className="daedalus-ui-frame__name">{identity.label}</span>
            {identity.type && (
              <span className="daedalus-ui-frame__type">{` · ${portalKindWord(identity.type)}`}</span>
            )}
            <div className="daedalus-ui-frame__verbs">
              {identity.url && (
                <a
                  className="daedalus-ui-frame__verb"
                  href={identity.url}
                  data-daedalus-ui-tooltip={editLabel}
                  data-daedalus-ui-tooltip-position="top"
                >
                  <span className="daedalus-ui-frame__icon" aria-hidden="true" dangerouslySetInnerHTML={{ __html: pencilIcon }} />
                  <span className="daedalus-ui-frame__label">{editLabel}</span>
                </a>
              )}
              {actions.map((action) => (
                <button
                  key={action.id}
                  type="button"
                  className="daedalus-ui-frame__verb"
                  data-portal-action={action.id}
                  data-daedalus-ui-tooltip={action.label}
                  data-daedalus-ui-tooltip-position="top"
                  onClick={(event) => {
                    event.preventDefault();
                    action.run(showing, uuid);
                  }}
                >
                  {action.icon && (
                    <span className="daedalus-ui-frame__icon" aria-hidden="true" dangerouslySetInnerHTML={{ __html: action.icon }} />
                  )}
                  <span className="daedalus-ui-frame__label">{action.label}</span>
                </button>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default Portal;
