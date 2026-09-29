import { h, Fragment } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { allCollapsed, allExpanded, collapseAll, expandAll } from './collapse';
import { collapseAllIcon, expandAllIcon } from './collapse-icons';
import { collapseHotkeyIds } from './collapse-hotkeys';
import { describeBinding } from '../edit_mode/hotkeys/binding';

/**
 * @file
 * The collapse controls: expand all and collapse all over one outline.
 *
 * Two kit icon buttons (panel-controls.css `.daedalus-ui-icon-button`,
 * the muted variant, like the picker toggle beside them on the page
 * elements tree's root row) an adapter renders wherever its outline's
 * chrome sits: the tree seats them at the end of its root row, code
 * mode in its showing row, the Advanced sections at the list head. The
 * control owns no state: it reads the adapter's posture and
 * outline, disables each button when its gesture would do nothing (the
 * model's two all-predicates, so the pair always reads true and the
 * disabled one tells the user which posture the outline is in), and
 * hands the gesture's next posture to the adapter's `apply`.
 *
 * Each tooltip names the gesture and its CURRENT key through the
 * registry (a remap re-words it; the registrar's ids by the outline's
 * id) and drops the key when the binding is unbound or the outline
 * registered none (the sections). The buttons conceal at clean density
 * only, like the picker and the close, and register with the reveal
 * engine for their lifetime, since Preact-rendered chrome never meets
 * the declarative auto-register.
 *
 * The registry and the reveal engine are reached through their runtime
 * facades (Drupal.Daedalus.Hotkeys, Drupal.Daedalus.reveal), never by
 * importing the app's modules: js/outline/ is compiled into other
 * bundles (daedalus_stylish's), and an import of hotkeys.js would give
 * that bundle its own registry and its own store, whose mode-slice
 * globals overwrite the app's at load. A pop-out document carries no
 * registry (the leader alone resolves keys; popout/keyboard.js relays
 * strokes), so the pair rendered there names no key.
 */

/**
 * The leader document's hotkey registry, or null in a document that
 * publishes none (a pop-out).
 */
const registry = () => Drupal.Daedalus.Hotkeys ?? null;

/**
 * The radius the reveal engine's auto-register gives declaratively
 * concealed chrome (reveal.js DEFAULT_NEAR), so the pair reveals at the
 * distance the rest of the chrome does.
 */
const REVEAL_NEAR = 80;

/**
 * The gesture's tooltip: its label with the binding's current key when
 * one is bound.
 */
const tooltipFor = (label, bindingId) => {
  const binding = registry()?.getBindings().find(({ id }) => id === bindingId);
  const key = describeBinding(binding?.key);
  return key ? Drupal.t('@label (@key)', { '@label': label, '@key': key }) : label;
};

/**
 * One of the pair: a kit icon button carrying its glyph, tooltip and
 * disabled state, registered with the reveal engine while mounted.
 */
const ControlButton = ({ className, label, tooltip, icon, disabled, onClick }) => {
  const buttonRef = useRef(null);
  useEffect(() => {
    const el = buttonRef.current;
    Drupal.Daedalus.reveal.register(el, { near: REVEAL_NEAR });
    return () => Drupal.Daedalus.reveal.unregister(el);
  }, []);
  return (
    <button
      type="button"
      className={`${className} daedalus-ui-icon-button daedalus-ui-icon-button--muted`}
      aria-label={label}
      data-daedalus-ui-tooltip={tooltip}
      data-daedalus-ui-conceal="clean"
      disabled={disabled}
      ref={buttonRef}
      onClick={onClick}
      dangerouslySetInnerHTML={{ __html: icon }}
    />
  );
};

/**
 * The pair, expand all first.
 *
 * @param {object} props
 *   - id: the outline's id, the registrar's; omitted when the outline
 *     registers no hotkeys, so the tooltips carry no key.
 *   - folds: the adapter's current posture.
 *   - outline: the adapter's outline, in the model's shape.
 *   - apply: function(next, gesture) receiving the gesture's next
 *     posture and its name ('collapse_all' or 'expand_all'), the same
 *     signature the registrar's actions use.
 */
export const CollapseControls = ({ id, folds, outline, apply }) => {
  // The bindings' keys change under a remap (the settings tray) and the
  // registry fills across scripts; re-word the tooltips on changes. No
  // registry (a pop-out), nothing to follow.
  const [, bump] = useState(0);
  useEffect(() => registry()?.onChange(() => bump((count) => count + 1)), []);
  const ids = collapseHotkeyIds(id ?? '');
  const collapseLabel = Drupal.t('Collapse all');
  const expandLabel = Drupal.t('Expand all');
  return (
    <Fragment>
      <ControlButton
        className="daedalus-ui-expand-all"
        label={expandLabel}
        tooltip={id ? tooltipFor(expandLabel, ids.expandAll) : expandLabel}
        icon={expandAllIcon}
        disabled={allExpanded(folds, outline)}
        onClick={() => apply(expandAll(folds, outline), 'expand_all')}
      />
      <ControlButton
        className="daedalus-ui-collapse-all"
        label={collapseLabel}
        tooltip={id ? tooltipFor(collapseLabel, ids.collapseAll) : collapseLabel}
        icon={collapseAllIcon}
        disabled={allCollapsed(folds, outline)}
        onClick={() => apply(collapseAll(folds, outline), 'collapse_all')}
      />
    </Fragment>
  );
};
