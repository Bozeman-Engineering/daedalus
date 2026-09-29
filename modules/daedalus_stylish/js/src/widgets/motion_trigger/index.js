import { h } from 'preact';
import { controlWidgets, litValues } from '../../controls.js';
import { triggerPairWrite } from '../../widget-values.js';
import { ChoiceRow } from '../lib/choice-row.js';
import { styleTarget } from '../lib/use-highlight.js';
import { widgetClasses } from '../lib/widget-classes.js';

/**
 * motion_trigger: the trigger of the selection's animation, as two rows
 * over the Motion group's trigger pair plus a Replay action.
 *
 * Start over timeline-trigger (how far into view the element scrolls
 * before its animation starts: On load, When visible, half, fully) and
 * Play over animation-trigger (Once, Every time, Forwards and back, Off),
 * each the shared basic-mode ChoiceRow over the trigger vocabulary the
 * advanced Motion rows render as text: the property's built-in stops
 * (widget-values.js FALLBACKS), a guide constraint narrowing the row
 * where one applies. The rows are text-shaped, so basic mode offers no
 * free entry here (that is advanced mode's); the spine surfaces the
 * vocabulary as chips or the well by its size and face length (the
 * built-in tables read as the well). The values are the layer stack's
 * winners at the pose (controls.js litValues over the model's reads: a
 * worn entrance's When visible / Once is lit as the rows' values; a
 * settle writes an element override at the posed compound, an unset
 * follows the variant again).
 *
 * The pair is ONE binding (every stop names the one `--in-view`
 * trigger), so a settle that would leave it half-formed writes both
 * properties as one gesture (triggerPairWrite: an explicit off on either
 * row clears a bound partner; a bound value completes a missing partner
 * with its default stop), previewing the completed half in the same
 * breath so the canvas paints the whole gesture. Changing the range keeps
 * the action and vice versa.
 *
 * Replay re-arms the trigger on the canvas through the source
 * (leader: the motion-triggers runtime on every element the target
 * resolves to; satellite: a motion-replay intent the leader resolves),
 * so an author sees the entrance again without scrolling it out and
 * back. The replayed target is where the trigger row WRITES
 * (model.keys['animation-trigger']: the binding's home box, or under a
 * write address the rule reaching it, whose every match replays) split
 * the way every canvas-chrome consumer splits it (styleTarget): the
 * selectable key plus the relative selector, so a control on a part
 * replays that part and a rule replays all its matches.
 *
 * No dynamic gate on purpose (the widget matrix): the motion grant alone
 * decides where the control appears.
 */
const ROWS = [
  { label: 'Start', property: 'timeline-trigger' },
  { label: 'Play', property: 'animation-trigger' },
];

const MotionTrigger = ({
  model,
  guide,
  materials,
  source,
  onPreview,
  onRelease,
  onCommit,
  onPickCondition = null,
  onOpenDocument = null,
}) => {
  const { key: targetKey, selector: targetSelector } = styleTarget(
    model.keys['animation-trigger'] ?? model.home.key,
  );
  const lit = litValues(model.reads);
  const commitPair = (properties) => {
    const write = triggerPairWrite(properties, lit);
    const partner = Object.fromEntries(
      Object.entries(write).filter(([property]) => !(property in properties)),
    );
    if (Object.keys(partner).length) {
      onPreview(partner);
    }
    return onCommit(write);
  };
  return (
    <div className={widgetClasses('motion-trigger', false)}>
      {ROWS.map(({ label, property }) => (
        <ChoiceRow
          key={property}
          guide={guide}
          materials={materials}
          source={source}
          highlight={styleTarget(model.keys[property])}
          condition={model.condition}
          label={Drupal.t(label)}
          property={property}
          value={lit[property]}
          definition={model.propertyDefinitions?.[property] ?? {}}
          constraints={model.constraints?.[property] ?? {}}
          stack={model.stacks[property]}
          read={model.reads[property]}
          disabled={Boolean(model.withheld?.[property])}
          disabledTitle={model.withheld?.[property] ?? null}
          onPickCondition={onPickCondition}
          onOpenDocument={onOpenDocument}
          onPreview={onPreview}
          onRelease={onRelease}
          onCommit={commitPair}
        />
      ))}
      <div className="daedalus-stylish-inspector__widget-row daedalus-stylish-inspector__widget-row--action">
        <span className="daedalus-stylish-inspector__widget-value">
          <button
            type="button"
            className="daedalus-stylish-inspector__widget-action daedalus-ui-pill-button"
            title={Drupal.t(
              'Play the animation again on the canvas, as if the element had just scrolled into view.',
            )}
            onClick={() => source.replayMotion(targetKey, targetSelector)}
          >
            {Drupal.t('Replay')}
          </button>
        </span>
      </div>
    </div>
  );
};

controlWidgets.register('motion_trigger', MotionTrigger);
