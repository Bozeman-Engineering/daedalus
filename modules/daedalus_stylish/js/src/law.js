import { h, Fragment } from 'preact';
import { useEffect, useRef } from 'preact/hooks';
import {
  bindingWords,
  lawTree,
  lawTypes,
  nodeSections,
  readingSentences,
  scaleRows,
} from './law-model.js';

/**
 * The Law tab: the Materials place's read-only view of the design
 * system's law.
 *
 * What the style guide declares, each fact with its origin, read under
 * the active design system's enforcement mode: the payload's law
 * projection (ClientStyleGuide::build() `law`) through law-model.js.
 * The law is authored in files and shown here; nothing on this face
 * writes, and no gesture minted here ever will (the guide's layering
 * rules and the reasoning its comments carry need a file, not a form:
 * the topic's decision). The tab follows the selection to the selected
 * element's type unless a declared doorway (a value "from the text block
 * type declaration": provenance-model.js declaredDoorway, riding
 * openDocument as a law document) or the type picker named a scope;
 * the doorway's property marks its rows and scrolls them into view.
 */

const Origin = ({ origin }) => (
  <span
    className="daedalus-stylish-law__origin"
    title={origin.title ?? undefined}
  >
    {origin.words}
  </span>
);

// A grant's origins: every layer whose facts survive under it, or the
// built-in word when no layer wrote any.
const Origins = ({ origins }) =>
  origins.length ? (
    origins.map((origin, index) => (
      <Fragment key={origin.words}>
        {index ? ', ' : ''}
        <Origin origin={origin} />
      </Fragment>
    ))
  ) : (
    <Origin origin={{ words: Drupal.t('built in'), title: null }} />
  );

const Row = ({ row }) => (
  <li
    className={`daedalus-stylish-law__row${row.focus ? ' daedalus-stylish-law__row--focus' : ''}`}
    data-law-focus={row.focus ? '' : undefined}
  >
    <span className="daedalus-stylish-law__label">{row.label}</span>
    {row.facts ? (
      <ul className="daedalus-stylish-law__facts">
        {row.facts.map((fact) => (
          <li
            key={fact.fact}
            className={`daedalus-stylish-law__fact${fact.curated ? ' daedalus-stylish-law__fact--curated' : ''}`}
          >
            <span className="daedalus-stylish-law__fact-name">
              {fact.curated ? Drupal.t('curated stops') : fact.fact}
            </span>
            <span className="daedalus-stylish-law__value">{fact.value}</span>
            <Origin origin={fact.origin} />
          </li>
        ))}
      </ul>
    ) : (
      <span className="daedalus-stylish-law__value">{row.value}</span>
    )}
    {row.origins ? (
      <span className="daedalus-stylish-law__origins">
        <Origins origins={row.origins} />
      </span>
    ) : row.origin && !row.facts ? (
      <Origin origin={row.origin} />
    ) : null}
    {row.source === 'site' && (
      <span className="daedalus-stylish-law__note">
        {Drupal.t("the site's binding")}
      </span>
    )}
  </li>
);

const Section = ({ section }) => (
  <section className="daedalus-stylish-law__section">
    <h5 className="daedalus-stylish-law__heading">{section.label}</h5>
    <ul className="daedalus-stylish-law__rows">
      {section.rows.map((row) => (
        <Row key={row.id} row={row} />
      ))}
    </ul>
  </section>
);

// One node of the declaration tree: its name, then its sections.
const Node = ({ entry, law, focus }) => {
  const sections = nodeSections(entry.node, law, { focus, root: entry.root });
  return (
    <div
      className={`daedalus-stylish-law__node${entry.root ? ' daedalus-stylish-law__node--root' : ''}`}
      style={{ '--daedalus-stylish-law-depth': entry.depth }}
    >
      <h4 className="daedalus-stylish-law__node-label">{entry.label}</h4>
      {sections.length ? (
        sections.map((section) => <Section key={section.id} section={section} />)
      ) : (
        <p className="daedalus-stylish-law__empty">
          {Drupal.t('Nothing declared here.')}
        </p>
      )}
    </div>
  );
};

const Scales = ({ law }) => {
  const rows = scaleRows(law);
  if (!rows.length) {
    return null;
  }
  return (
    <details className="daedalus-stylish-law__scales">
      <summary className="daedalus-stylish-law__heading">
        {Drupal.t('Scales and what binds to them')}
      </summary>
      <ul className="daedalus-stylish-law__rows">
        {rows.map((row) => (
          <li key={row.scale} className="daedalus-stylish-law__row">
            <span className="daedalus-stylish-law__label">
              {row.label}{' '}
              <code className="daedalus-stylish-law__code">
                --{row.prefix}-*
              </code>
            </span>
            <span className="daedalus-stylish-law__value">{row.shape}</span>
            <span className="daedalus-stylish-law__origins">
              <Origins origins={row.origins} />
            </span>
            {row.contract.length > 0 && (
              <span className="daedalus-stylish-law__contract">
                {Drupal.t('Contract: !names', {
                  '!names': row.contract.join(', '),
                })}{' '}
                <Origin origin={row.contractOrigin} />
              </span>
            )}
            {row.bindings.length > 0 && (
              <ul className="daedalus-stylish-law__facts">
                {row.bindings.map((binding) => (
                  <li
                    key={`${binding.scope ?? 'site'}|${binding.path}|${binding.property}`}
                    className="daedalus-stylish-law__fact"
                  >
                    {bindingWords(binding)}
                  </li>
                ))}
              </ul>
            )}
          </li>
        ))}
      </ul>
    </details>
  );
};

/**
 * @param {object} props
 * @param {object} props.guide
 *   The style guide payload; its `law` is the projection.
 * @param {?string} props.scope
 *   The declaring scope shown (the selection's type, a doorway's or the
 *   picker's), null when nothing is selected and nothing was picked.
 * @param {boolean} props.following
 *   Whether the scope is the selection's (the tab follows it).
 * @param {?{scope: string, property: ?string}} props.focus
 *   The doorway's focus; its property marks the rows.
 * @param {Function} props.onScope
 *   (scope): the type picker's choice.
 */
export const LawView = ({ guide, scope, following, focus, onScope }) => {
  const law = guide?.law ?? null;
  const body = useRef(null);
  // Reveal the focused rows once they render (a doorway lands here with
  // the tab freshly mounted).
  useEffect(() => {
    body.current
      ?.querySelector('[data-law-focus]')
      ?.scrollIntoView?.({ block: 'nearest' });
  }, [focus, scope]);

  if (!law) {
    return (
      <div className="daedalus-stylish-law">
        <p className="daedalus-stylish-law__empty">
          {Drupal.t('The style guide payload carries no law projection.')}
        </p>
      </div>
    );
  }
  const types = lawTypes(law);
  const families = [];
  for (const type of types) {
    const family = families.find((entry) => entry.family === type.family);
    if (family) {
      family.types.push(type);
    } else {
      families.push({ family: type.family, types: [type] });
    }
  }
  const node = scope ? (law.nodes?.[scope] ?? null) : null;

  return (
    <div className="daedalus-stylish-law" ref={body}>
      <p className="daedalus-stylish-law__reading">
        {readingSentences(law).join(' ')}
      </p>
      <p className="daedalus-stylish-law__honesty">
        {Drupal.t(
          'The law is authored in the declaration files a module or theme ships; this tab shows it and where each fact comes from.',
        )}
      </p>
      <Scales law={law} />
      <label className="daedalus-stylish-law__type">
        <span className="daedalus-stylish-law__type-word">
          {Drupal.t('Type')}
        </span>
        <select
          className="daedalus-ui-input daedalus-ui-input--select"
          value={scope ?? ''}
          title={Drupal.t('The declaration shown')}
          onChange={(event) => onScope(event.currentTarget.value)}
        >
          <option value="" disabled>
            {Drupal.t('Pick a type…')}
          </option>
          {families.map((family) => (
            <optgroup key={family.family} label={family.family}>
              {family.types.map((type) => (
                <option key={type.scope} value={type.scope}>
                  {type.label}
                </option>
              ))}
            </optgroup>
          ))}
        </select>
        {following && scope && (
          <span className="daedalus-stylish-law__following">
            {Drupal.t('follows the selection')}
          </span>
        )}
      </label>
      {node ? (
        lawTree(node, scope).map((entry) => (
          <Node key={entry.path} entry={entry} law={law} focus={focus} />
        ))
      ) : (
        <p className="daedalus-stylish-law__empty">
          {scope
            ? Drupal.t('The law declares nothing for !scope.', {
                '!scope': scope,
              })
            : Drupal.t('Select an element, or pick a type.')}
        </p>
      )}
    </div>
  );
};
