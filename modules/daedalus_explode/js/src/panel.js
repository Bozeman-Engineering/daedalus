/**
 * The Explode panel: the controls over the exploded view's settings.
 *
 * A thin adapter over the settings store (settings.js): every control
 * writes settings.set(...) and the host (host.js) re-prints the sheet on
 * the store's change notification, so the panel never talks to the
 * canvas. It renders from the store's current value and re-renders on
 * the same notification, which keeps a reset, a second panel or a
 * console write in step with what the sliders show.
 *
 * The depth cap's top is the page's own deepest plane (plane-model.js's
 * maxDepth over the live index and reach, so a Markup flip that adds the
 * part planes raises it), read at render and again on every blueprint
 * and view-visibility change; one step above it reads "All", the store's
 * null. The index is null before the mirror boots (a panel rendered open
 * at boot), which reads as a page with no depth: the cap slider waits,
 * disabled, and wakes on the first blueprint change.
 *
 * There is no Parts toggle: Explode lifts what the Page elements panel
 * depicts, and the Markup view's Show-menu toggle is the one switch for
 * the part tier (daedalus_ui's reach, app.tree.reachable).
 *
 * Panel-kit classes throughout (daedalus_ui/panel_controls); the
 * tooltips ride the chrome tooltip spine (data-daedalus-ui-tooltip), so
 * the panel owns no tooltip markup.
 */
import { h } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { DEFAULTS, RANGES } from './settings.js';
import { maxDepth } from './plane-model.js';

/**
 * The numeric controls, in panel order: the store key, the label, the
 * unit the readout wears, the slider's step, and the tooltip.
 */
const SLIDERS = [
  {
    name: 'tilt',
    label: () => Drupal.t('Tilt'),
    unit: '°',
    step: 1,
    tooltip: () => Drupal.t('How far the page leans back, in degrees'),
  },
  {
    name: 'turn',
    label: () => Drupal.t('Turn'),
    unit: '°',
    step: 1,
    tooltip: () => Drupal.t('How far the page turns on the spot, in degrees'),
  },
  {
    name: 'gap',
    label: () => Drupal.t('Gap'),
    unit: 'px',
    step: 1,
    tooltip: () => Drupal.t('The space between one layer and the next, in pixels'),
  },
];

/**
 * What one press of a Zoom stepper adds to or takes from the scale (a
 * factor, so 0.1 is ten percentage points). The slider itself scrubs at
 * 0.01; the store clamps either to RANGES.scale.
 */
const ZOOM_STEP = 0.1;

/**
 * The scale a stepper press lands on, held to the slider's hundredths so
 * a run of presses stays on whole percents (0.62 + 0.1 is 0.72 in
 * binary floating point today, 0.52 - 0.1 is not 0.42).
 */
const steppedScale = (scale, direction) =>
  Math.round((scale + direction * ZOOM_STEP) * 100) / 100;

/**
 * The toggles, in panel order.
 */
const TOGGLES = [
  {
    name: 'labels',
    label: () => Drupal.t('Labels'),
    tooltip: () => Drupal.t('Name each layer on its top edge'),
  },
  {
    name: 'isolate',
    label: () => Drupal.t('Isolate selection'),
    tooltip: () =>
      Drupal.t('Lift only the selection, its ancestors and its descendants; everything else stays flat'),
  },
];

/**
 * Whether the settings are already the defaults (Reset has nothing to do).
 */
const atDefaults = (view) =>
  Object.keys(DEFAULTS).every((name) => view[name] === DEFAULTS[name]);

/**
 * A labeled slider with its readout.
 *
 * With `steppers` ({decrease, increase, canDecrease, canIncrease,
 * decreaseLabel, increaseLabel}) the slider sits between a minus and a
 * plus pill in the same row, for a control whose useful moves are coarse
 * steps as often as a scrub.
 */
const Slider = ({
  id,
  label,
  tooltip,
  unit,
  min,
  max,
  step,
  value,
  readout,
  disabled,
  onInput,
  steppers,
}) => {
  const range = (
    <input
      id={id}
      className="daedalus-explode__range"
      type="range"
      min={min}
      max={max}
      step={step}
      value={value}
      disabled={disabled}
      data-daedalus-ui-tooltip={tooltip}
      onInput={(event) => onInput(Number(event.target.value))}
    />
  );
  return (
    <div className="daedalus-explode__control daedalus-explode__control--slider">
      <div className="daedalus-explode__row">
        <label className="daedalus-explode__label" htmlFor={id}>
          {label}
        </label>
        <output className="daedalus-explode__readout daedalus-ui-chip daedalus-ui-chip--muted" htmlFor={id}>
          {readout ?? `${value}${unit}`}
        </output>
      </div>
      {steppers ? (
        <div className="daedalus-explode__scrub">
          <button
            type="button"
            className="daedalus-explode__step daedalus-explode__step--minus daedalus-ui-pill-button"
            disabled={disabled || !steppers.canDecrease}
            aria-label={steppers.decreaseLabel}
            data-daedalus-ui-tooltip={steppers.decreaseLabel}
            onClick={steppers.decrease}
          >
            &minus;
          </button>
          {range}
          <button
            type="button"
            className="daedalus-explode__step daedalus-explode__step--plus daedalus-ui-pill-button"
            disabled={disabled || !steppers.canIncrease}
            aria-label={steppers.increaseLabel}
            data-daedalus-ui-tooltip={steppers.increaseLabel}
            onClick={steppers.increase}
          >
            +
          </button>
        </div>
      ) : (
        range
      )}
    </div>
  );
};

/**
 * A labeled on/off segmented control.
 */
const Toggle = ({ id, label, tooltip, on, onChange }) => (
  <div className="daedalus-explode__control daedalus-explode__control--toggle">
    <div className="daedalus-explode__row">
      <span className="daedalus-explode__label" id={`${id}-label`}>
        {label}
      </span>
      <div
        className="daedalus-explode__segments daedalus-ui-segments"
        role="group"
        aria-labelledby={`${id}-label`}
        data-daedalus-ui-tooltip={tooltip}
      >
        <button
          type="button"
          className={`daedalus-explode__segment daedalus-ui-segment${on ? '' : ' daedalus-ui-segment--active'}`}
          aria-pressed={!on}
          onClick={() => onChange(false)}
        >
          {Drupal.t('Off')}
        </button>
        <button
          type="button"
          className={`daedalus-explode__segment daedalus-ui-segment${on ? ' daedalus-ui-segment--active' : ''}`}
          aria-pressed={on}
          onClick={() => onChange(true)}
        >
          {Drupal.t('On')}
        </button>
      </div>
    </div>
  </div>
);

/**
 * The panel.
 *
 * @param {object} props
 * @param {{get: Function, set: Function, reset: Function, subscribe: Function}} props.settings
 *   The settings store.
 * @param {object} props.app
 *   The app API (app.tree.index(), app.tree.root(), app.tree.reachable,
 *   app.tree.onViewVisibilityChange, app.onBlueprintChange).
 */
export const ExplodePanel = ({ settings, app }) => {
  const [view, setView] = useState(settings.get());
  // Bumped on every blueprint and view-visibility change so the depth
  // cap's top re-reads the index and reach; both are read at render,
  // never held.
  const [, setEpoch] = useState(0);

  useEffect(() => settings.subscribe(setView), [settings]);
  useEffect(() => {
    const bump = () => setEpoch((epoch) => epoch + 1);
    const offBlueprint = app.onBlueprintChange(bump);
    const offViews = app.tree.onViewVisibilityChange(bump);
    return () => {
      offBlueprint();
      offViews();
    };
  }, [app]);

  const deepest = maxDepth(app.tree.index(), app.tree.root(), app.tree.reachable);
  // One step past the deepest plane is All (the store's null); a cap
  // deeper than the page also reads All, since that is what it does.
  const capAll = deepest + 1;
  const capValue =
    view.depthCap === null || view.depthCap > deepest ? capAll : view.depthCap;

  return (
    <div className="daedalus-explode">
      {SLIDERS.map(({ name, label, unit, step, tooltip }) => (
        <Slider
          key={name}
          id={`daedalus-explode-${name}`}
          label={label()}
          tooltip={tooltip()}
          unit={unit}
          min={RANGES[name][0]}
          max={RANGES[name][1]}
          step={step}
          value={view[name]}
          onInput={(value) => settings.set({ [name]: value })}
        />
      ))}
      <Slider
        id="daedalus-explode-scale"
        label={Drupal.t('Zoom')}
        tooltip={Drupal.t('How large the exploded stack draws, as a percentage of the flat page')}
        unit="%"
        min={RANGES.scale[0]}
        max={RANGES.scale[1]}
        step={0.01}
        value={view.scale}
        readout={`${Math.round(view.scale * 100)}%`}
        onInput={(value) => settings.set({ scale: value })}
        steppers={{
          decrease: () => settings.set({ scale: steppedScale(view.scale, -1) }),
          increase: () => settings.set({ scale: steppedScale(view.scale, 1) }),
          canDecrease: view.scale > RANGES.scale[0],
          canIncrease: view.scale < RANGES.scale[1],
          decreaseLabel: Drupal.t('Zoom out'),
          increaseLabel: Drupal.t('Zoom in'),
        }}
      />
      <Slider
        id="daedalus-explode-depthCap"
        label={Drupal.t('Depth cap')}
        tooltip={Drupal.t('Layers deeper than this settle onto the last lifted one')}
        unit=""
        min={1}
        max={capAll}
        step={1}
        value={capValue}
        readout={capValue === capAll ? Drupal.t('All') : String(capValue)}
        disabled={deepest === 0}
        onInput={(value) =>
          settings.set({ depthCap: value >= capAll ? null : value })
        }
      />
      {TOGGLES.map(({ name, label, tooltip }) => (
        <Toggle
          key={name}
          id={`daedalus-explode-${name}`}
          label={label()}
          tooltip={tooltip()}
          on={view[name]}
          onChange={(on) => settings.set({ [name]: on })}
        />
      ))}
      <div className="daedalus-explode__control daedalus-explode__control--actions">
        <button
          type="button"
          className="daedalus-explode__reset daedalus-ui-pill-button"
          disabled={atDefaults(view)}
          data-daedalus-ui-tooltip={Drupal.t('Return every control to its default')}
          onClick={() => settings.reset()}
        >
          {Drupal.t('Reset')}
        </button>
      </div>
    </div>
  );
};
