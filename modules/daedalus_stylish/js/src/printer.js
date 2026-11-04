import { conditionForPseudoClass, parseKey } from './style-guide.js';
import { hasControlChar } from './control-chars.js';
import { parseSlotKey, rootKindForScope, fragmentStyles } from './slot-key.js';
import { cascadeOrder } from './condition-cascade.js';
import {
  CONTAINER,
  PSEUDO,
  UNKNOWN,
  clausePosable,
  clauseStamp,
  parseCompound,
} from '../../../daedalus_ui/js/edit_mode/materials/condition-clause.js';
import { CLAIMS_CONTENT_ATTRIBUTE } from '../../../daedalus_ui/js/edit_mode/blueprint/element-resolver.js';
import {
  ANCHOR_KIND,
  CHILD,
  DESCENDANT,
  HAS,
  IS,
  NEXT,
  NOT,
  SUBSEQUENT,
  SUFFIX,
  TYPE,
  UNIVERSAL,
  isPartFirst,
  parseSelector,
  printQualifier,
  splitKey,
  typeSelector,
  under as rebase,
} from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

/**
 * Prints a style map as CSS text with @scope roots — the client printer.
 *
 * The JS half of the printer pair: CssPrinter.php is the persisted render
 * backend, this powers the live-preview <style> tag (and, later, the code
 * editor view). Both are deliberately dumb-deterministic and their output is
 * byte-identical for the same map — CssPrinterTest's exact-text expectations
 * are the shared contract, and the live-verify recipe asserts the preview
 * tag's text equals the authoritative tag's text after an op settles. Change
 * one printer and you change both.
 *
 * The condition wrapper table is not duplicated here: it arrives in the
 * style guide payload (guide.conditions, each {label, wrapper, axis})
 * straight from the server's ConditionTypePluginManager, live
 * thresholds included, so the two printers dispatch on the same data.
 * Compound "a+b" condition keys compose their parts' wrappers exactly like
 * the server (a state as an :is() with the force-state dual). Like the
 * PHP printer this is
 * an emit gate — the text lands inside a <style> element, so unsafe pieces
 * are dropped, never emitted.
 */

// The underscore is a legal CSS ident character, present in a theme's own
// custom property names (--my_theme-accent); this is a charset
// hygiene gate, not a vocabulary check. Mirrors CssPrinter.php's
// printDeclarations() gate, byte for byte.
const PROPERTY_PATTERN = /^-{0,2}[A-Za-z][A-Za-z0-9_-]*$/;
const VALUE_FORBIDDEN = /[;{}<>]/;
// The control characters a value may carry: tab, line feed, carriage
// return (the gate's \x09, \x0A and \x0D gaps).
const VALUE_WHITESPACE = '\t\n\r';

const isEntryObject = (value) => value !== null && typeof value === 'object';

// The keyframes name (a custom-ident) and keyframe offset shapes the emit
// gate admits: charset hygiene for text landing in an at-rule prelude and
// body, never vocabulary. Mirror CssPrinter.php's KEYFRAMES_NAME_PATTERN
// and KEYFRAME_OFFSET_PATTERN, byte for byte.
const KEYFRAMES_NAME_PATTERN = /^-?[A-Za-z_][A-Za-z0-9_-]*$/;
const KEYFRAME_OFFSET_PATTERN = /^(?:from|to|(?:\d+(?:\.\d+)?|\.\d+)%)$/;

/**
 * The marker custom property printed beside each motion trigger property
 * (CssPrinter::TRIGGER_MARKERS mirror).
 *
 * Browsers without the Animation Triggers draft drop `timeline-trigger` /
 * `animation-trigger` at parse time, so each prints a custom-property twin
 * beside it, in whatever block it lands; the motion-triggers runtime reads
 * the effective pair off computed style. The server printer owns the full
 * rationale; the texts must stay byte-identical.
 */
export const TRIGGER_MARKERS = {
  'timeline-trigger': '--daedalus-stylish-timeline-trigger',
  'animation-trigger': '--daedalus-stylish-animation-trigger',
};

/**
 * The attribute the motion-triggers runtime stamps once a trigger fired
 * (CssPrinter::TRIGGERED_ATTRIBUTE mirror). The hold pauses every target
 * NOT carrying it.
 */
export const TRIGGERED_ATTRIBUTE = 'data-daedalus-stylish-triggered';

/**
 * The feature query wrapping the polyfill hold
 * (CssPrinter::TRIGGER_HOLD_SUPPORTS mirror).
 *
 * A block binding an animation to a trigger (`animation-trigger` other
 * than `none`) gets, beside its rule and inside the same condition
 * wrappers, this at-rule holding the block's targets
 * `:not([data-daedalus-stylish-triggered])` at `animation-play-state: paused`:
 * native browsers skip it, every other browser holds until the runtime
 * stamps the attribute. The server printer owns the full rationale; the
 * texts must stay byte-identical.
 */
export const TRIGGER_HOLD_SUPPORTS = '@supports not (animation-trigger: none)';

/**
 * An entry's condition blocks in emission order (CssPrinter mirror).
 *
 * Emission orders structurally, never by stored key order — the shared
 * cascade vocabulary (condition-cascade.js) owns the rule and the
 * rationale; the server mirror is CssPrinter::orderedConditions() and
 * the texts must stay byte-identical.
 *
 * @param {object} entry
 *   The style entry: condition key => property map.
 * @param {object} conditions
 *   The condition table (guide.conditions), for cascade order.
 *
 * @return {Array<[string, object]>}
 *   The [condition, properties] pairs in emission order.
 */
const orderedConditions = (entry, conditions) =>
  cascadeOrder(Object.keys(entry), conditions).map((key) => [key, entry[key]]);

/**
 * The @scope limit for container-rooted blocks: the donut (CssPrinter
 * mirror).
 *
 * Nested containers repeat the outer container's own markup, so a
 * container's ROOT entry and its part-first selectors are donuts: they
 * stop at nested container roots — and at nested BLOCK roots, whose
 * interiors belong to the block's own key family. Every other selector
 * (a tag, a type, `> *`) reaches its whole subtree (donutLimit). The
 * server printer owns the rationale (CssPrinter::CONTAINER_SCOPE_LIMIT);
 * the texts must stay byte-identical.
 */
export const CONTAINER_SCOPE_LIMIT =
  ':scope [data-selectable^="container:"], :scope [data-selectable^="block:"]';

/**
 * The donut a container-rooted entry takes, by its selector's shape
 * (CssPrinter::donutLimit mirror): the root entry and a bare part take
 * the standard limit; a part reaching by `>` shifts it one child level
 * per compound (`.cls > *` stops at the nested roots' children, so the
 * roots themselves stay stylable as the part's children); a part
 * continuing by a descendant combinator, and every other shape, takes
 * none. The server printer owns the rationale.
 *
 * @param {?string} selector
 *   The key's canonical relative selector, or null for the root.
 *
 * @return {?string}
 *   The @scope limit selector list, or null for no donut.
 */
export const donutLimit = (selector) => {
  if (selector === null) {
    return CONTAINER_SCOPE_LIMIT;
  }
  if (!isPartFirst(selector)) {
    return null;
  }
  // The part stands first, or right behind the qualified anchor (the
  // owner itself, no level of its own).
  const { compounds } = parseSelector(selector);
  const rest = compounds.slice(compounds[0].kind === ANCHOR_KIND ? 2 : 1);
  if (rest.some((compound) => compound.combinator !== CHILD)) {
    return null;
  }
  if (!rest.length) {
    return CONTAINER_SCOPE_LIMIT;
  }
  return CONTAINER_SCOPE_LIMIT.split(',')
    .map((member) => `${member.trim()}${' > *'.repeat(rest.length)}`)
    .join(', ');
};

/**
 * The index of the last weighted (non-universal) compound from `from`, or -1.
 *
 * The subject alone weighs: a qualifier takes its argument's weight, and
 * every argument compound prints inside :where().
 *
 * @param {Array<object>} compounds
 *   The parsed selector's compounds.
 * @param {number} from
 *   The index the search stops at, inclusive.
 *
 * @return {number}
 *   The index of the last weighted compound at or after `from`, or -1.
 */
const lastWeighted = (compounds, from) => {
  for (let i = compounds.length - 1; i >= from; i -= 1) {
    if (compounds[i].kind !== UNIVERSAL) {
      return i;
    }
  }
  return -1;
};

/**
 * Whether a compound is reached by a sibling combinator (`+`, `~`).
 *
 * @param {object} compound
 *   A parsed compound.
 *
 * @return {boolean}
 *   True when its combinator is `+` or `~`.
 */
const isSibling = (compound) =>
  [NEXT, SUBSEQUENT].includes(compound.combinator);

/**
 * The symbols of the spelled combinators (the descendant one is a space).
 */
const COMBINATOR_SYMBOLS = { [CHILD]: '>', [NEXT]: '+', [SUBSEQUENT]: '~' };

/**
 * Prints the combinator reaching a compound: ` > `, ` + ` or ` ~ `, the one
 * space for a descendant.
 *
 * @param {object} compound
 *   A parsed compound.
 *
 * @return {string}
 *   The combinator text, spaces included.
 */
const renderCombinator = (compound) =>
  compound.combinator === DESCENDANT
    ? ' '
    : ` ${COMBINATOR_SYMBOLS[compound.combinator]} `;

/**
 * The force-state dual of a posable state (CssPrinter::forceStateDual
 * mirror): the pseudo-class OR the editor's data-force-state stamp, so
 * the stored styles render under a pose without the real interaction,
 * at the pseudo-class's own weight. The ONE spelling behind the
 * condition suffix on an entry's target (printEntry) and a pseudo-class
 * qualifier at its compound (renderQualifiers). The server printer owns
 * the rationale; the texts must stay byte-identical.
 *
 * @param {string} wrapper
 *   The pseudo-class spelling (`:hover`).
 * @param {string} condition
 *   The published state's condition name.
 *
 * @return {string}
 *   The `:is()` of the pseudo-class and the force-state stamp.
 */
const forceStateDual = (wrapper, condition) =>
  `:is(${wrapper}, [data-force-state~="${condition}"])`;

/**
 * Prints a compound's qualifiers, each at its compound and at zero weight
 * (CssPrinter mirror): every `:has()` / `:not()` argument compound inside
 * :where(), the argument's combinators bare, its lead glued to its space;
 * every other qualifier inside its own :where() in the grammar's
 * canonical spelling (`:where(.active)`, `:where([aria-expanded="true"])`,
 * `:where(:first-child)`, `:not(:where(:last-child))`), a pseudo-class
 * spelling a published state as the force-state dual inside the :where()
 * (`:where(:is(:hover, [data-force-state~="hover"]))`), any other
 * pseudo-class bare; an `:is()` inside its own :where() with its members
 * bare inside it, each by the same rules (`:where(:is(:is(:focus-within,
 * […]), :is(:hover, […])))`, `*:where(:is(h1, h2))`). Inside such a
 * member (`inWhere`) nothing wraps: the one :where() has zeroed it all.
 * The server printer owns the rationale.
 *
 * @param {object} compound
 *   The parsed compound whose qualifiers print.
 * @param {object} conditions
 *   The condition table (guide.conditions), for the force-state dual.
 * @param {boolean} inWhere
 *   True inside an `:is()` member, where nothing wraps.
 *
 * @return {string}
 *   The qualifiers' selector text, glued together.
 */
const renderQualifiers = (compound, conditions, inWhere = false) =>
  compound.qualifiers
    .map((qualifier) => {
      if (qualifier.name === HAS) {
        return `:has(${renderArgument(qualifier.argument, conditions, inWhere)})`;
      }
      if (qualifier.name === NOT) {
        return `:not(${renderCompound(qualifier.argument, false, conditions, inWhere)})`;
      }
      return inWhere
        ? renderQualifier(qualifier, conditions)
        : `:where(${renderQualifier(qualifier, conditions)})`;
    })
    .join('');

/**
 * The zero-weight print's inside for one qualifier that is no `:has()` or
 * `:not()` (CssPrinter::renderQualifier mirror): `:is()` over its members
 * printed bare inside the :where(), the force-state dual for a posable
 * state, else the grammar's own spelling. Only a bare pseudo-class can
 * spell a state: a class and an attribute carry their argument, and so
 * does the nth family.
 *
 * @param {object} qualifier
 *   The parsed qualifier (name and argument).
 * @param {object} conditions
 *   The condition table (guide.conditions), for the force-state dual.
 *
 * @return {string}
 *   The qualifier's selector text, unwrapped.
 */
const renderQualifier = (qualifier, conditions) => {
  if (qualifier.name === IS) {
    return `:is(${qualifier.argument.map((member) => renderCompound(member, true, conditions, true)).join(', ')})`;
  }
  if (qualifier.argument === null) {
    const condition = conditionForPseudoClass(conditions, qualifier.name);
    if (condition !== null) {
      return forceStateDual(`:${qualifier.name}`, condition);
    }
  }
  return printQualifier(qualifier);
};

/**
 * Prints a `:has()` argument: its lead, then each compound in :where()
 * (bare inside an `:is()` member, `inWhere`).
 *
 * @param {Array<object>} compounds
 *   The argument's parsed compounds.
 * @param {object} conditions
 *   The condition table (guide.conditions), for the force-state dual.
 * @param {boolean} inWhere
 *   True inside an `:is()` member, where nothing wraps.
 *
 * @return {string}
 *   The argument's selector text.
 */
const renderArgument = (compounds, conditions, inWhere = false) => {
  const lead =
    compounds[0].combinator === DESCENDANT
      ? ''
      : `${COMBINATOR_SYMBOLS[compounds[0].combinator]} `;
  return compounds
    .map(
      (compound, i) =>
        `${i === 0 ? lead : renderCombinator(compound)}${renderCompound(compound, false, conditions, inWhere)}`,
    )
    .join('');
};

/**
 * Prints one compound: its subject, bare when it carries the weight, else
 * in :where(), then its qualifiers. `*` prints bare (it weighs nothing);
 * an implied subject (inside `:not()` or an `:is()` member) prints
 * nothing; the qualified anchor is the scoping root (`:scope`). Inside a
 * :where() already (`inWhere`: an `:is()` member and everything it holds)
 * the subject prints bare and the qualifiers unwrapped.
 *
 * @param {object} compound
 *   The parsed compound.
 * @param {boolean} bare
 *   True when the compound carries the chain's weight.
 * @param {object} conditions
 *   The condition table (guide.conditions), for the force-state dual.
 * @param {boolean} inWhere
 *   True inside an `:is()` member, where nothing wraps.
 *
 * @return {string}
 *   The compound's selector text.
 */
const renderCompound = (compound, bare, conditions, inWhere = false) => {
  if (compound.kind === null) {
    return renderQualifiers(compound, conditions, inWhere);
  }
  if (compound.kind === UNIVERSAL) {
    return `*${renderQualifiers(compound, conditions, inWhere)}`;
  }
  let printed = compound.name;
  if (compound.kind === TYPE) {
    printed = typeSelector(compound.name);
  } else if (compound.kind === ANCHOR_KIND) {
    printed = ':scope';
  }
  return `${bare || inWhere ? printed : `:where(${printed})`}${renderQualifiers(compound, conditions, inWhere)}`;
};

/**
 * The `:scope` link of a chain (CssPrinter::scopeLink mirror): bare when
 * it carries the weight, else inside :where(); the qualified anchor's
 * print when the owner stands first, the plain scoping root otherwise.
 *
 * @param {Array<object>} compounds
 *   The parsed selector's compounds.
 * @param {boolean} bare
 *   True when the link carries the chain's weight.
 * @param {object} conditions
 *   The condition table (guide.conditions), for the force-state dual.
 *
 * @return {string}
 *   The link's selector text.
 */
const scopeLink = (compounds, bare, conditions) => {
  if (compounds[0].kind === ANCHOR_KIND) {
    return renderCompound(compounds[0], bare, conditions);
  }
  return bare ? ':scope' : ':where(:scope)';
};

/**
 * Prints the chain from one compound on, each behind its combinator.
 *
 * @param {Array<object>} compounds
 *   The parsed selector's compounds.
 * @param {number} carrier
 *   The index of the compound carrying the weight (lastWeighted), or -1.
 * @param {number} from
 *   The index the print starts at.
 * @param {object} conditions
 *   The condition table (guide.conditions), for the force-state dual.
 *
 * @return {string}
 *   The chain's selector text from `from` on.
 */
const renderTail = (compounds, carrier, from, conditions) =>
  compounds
    .map((compound, i) =>
      i < from
        ? ''
        : `${renderCombinator(compound)}${renderCompound(compound, i === carrier, conditions)}`,
    )
    .join('');

/**
 * The terminal pseudo-element a selector prints, as selector text
 * (CssPrinter::pseudoTail mirror): '::before' / '::after', or '' for an
 * element (null included).
 *
 * @param {?string} selector
 *   The key's canonical relative selector, or null for the root.
 *
 * @return {string}
 *   The pseudo-element text, or '' for an element.
 */
const pseudoTail = (selector) => {
  const pseudo = selector === null ? null : parseSelector(selector).pseudo;
  return pseudo === null ? '' : `${SUFFIX}${pseudo}`;
};

/**
 * The @scope targets of a selector's HOSTS (CssPrinter::hostTargets
 * mirror): scopeTargets without the pseudo-element, the selectors a
 * condition suffix appends to. The owner's anchor hosts on the scoping
 * root itself. The canvas reads through these (computed-read.js
 * resolveTargets): a generated box has no node, so the DOM twin of a
 * box's rule is its hosts, and the box rides the computed read as
 * getComputedStyle's pseudo argument.
 *
 * @param {?string} selector
 *   The key's canonical relative selector, or null for the root.
 * @param {object} conditions
 *   The condition table, as scopeTargets takes it.
 *
 * @return {string[]}
 *   The host selectors, one per @scope target.
 */
export const hostTargets = (selector, conditions = {}) => {
  if (selector === null) {
    return [':scope'];
  }
  const { compounds } = parseSelector(selector);
  if (!compounds.length) {
    return [':scope'];
  }
  const carrier = lastWeighted(compounds, 0);
  // A descendant-led chain, or the qualified anchor (which prints as the
  // :scope link, renderCompound).
  const main =
    compounds[0].combinator === CHILD
      ? `${scopeLink(compounds, carrier === -1, conditions)}${renderTail(compounds, carrier, 0, conditions)}`
      : `${renderCompound(compounds[0], carrier === 0, conditions)}${renderTail(compounds, carrier, 1, conditions)}`;
  if (!isPartFirst(selector)) {
    return [main];
  }
  // The part stands first, or right behind the qualified anchor.
  const part = compounds[0].kind === ANCHOR_KIND ? 1 : 0;
  if (compounds.length === part + 1) {
    return [
      `${scopeLink(compounds, true, conditions)}${compounds[part].name}${renderQualifiers(compounds[part], conditions)}`,
      main,
    ];
  }
  if (isSibling(compounds[part + 1])) {
    return [main];
  }
  const restCarrier = lastWeighted(compounds, part + 1);
  const dual = `${scopeLink(compounds, restCarrier === -1, conditions)}:where(${compounds[part].name})${renderQualifiers(compounds[part], conditions)}${renderTail(compounds, restCarrier, part + 1, conditions)}`;
  return [dual, main];
};

/**
 * The @scope targets a relative selector prints to: the discipline
 * (CssPrinter::scopeTargets mirror, byte for byte).
 *
 * The chain is `:scope` followed by the selector's compounds; exactly ONE
 * link carries specificity (the last non-universal one, `*` never
 * weighs), every other prints inside :where(), so an ancestor's rule
 * weighs what the addressed element's own rule weighs and loses to it on
 * proximity. A descendant-first chain omits `:scope` from the print. A
 * part-first selector prints the hoisted dual first (`:scope.cls`, the
 * bare part keeping 0-2-0 on the owner's own root; a longer chain puts
 * the root's weight on `:scope` when nothing after the class weighs, else
 * on the last weighted compound; the dual is skipped when the part's next
 * compound is a sibling, the scope root having no sibling inside itself).
 * A sibling combinator prints like `>`; a compound's qualifiers print on
 * it at zero weight (renderQualifiers: a class, an attribute, a position
 * or a pseudo-class each inside its own :where(), a published state as
 * the force-state dual). The qualified anchor
 * (`&:first-child`, `&:not(:last-child) > *`) is the owner standing
 * first: it prints as the `:scope` link itself with its qualifiers glued
 * on, bare or in :where() by the same discipline, and a part behind it
 * keeps its hoisted dual on the anchor's print
 * (`:scope:where(:first-child).quote`). A terminal pseudo-element glues
 * onto every target after the last compound's qualifiers (the part dual
 * on both halves), the owner's anchor (`&::before`) being the scoping
 * root's own box (`:scope::before`); :where() cannot wrap a
 * pseudo-element, so every rule addressing a box weighs its host's
 * weight plus 0-0-1, own and ancestor alike. A printed entry puts the
 * condition suffixes BEFORE the pseudo (printEntry), so these are the
 * whole selectors of an unconditional rule and the hosts are
 * hostTargets'. The server printer owns the rationale.
 *
 * @param {?string} selector
 *   The key's canonical relative selector, or null for the root.
 * @param {object} conditions
 *   The condition table (guide.conditions): a pseudo-class qualifier that
 *   spells one of its states prints the force-state dual. Without a table
 *   no pseudo-class is a published state and every one prints bare.
 *
 * @return {string[]}
 *   The whole target selectors, the pseudo-element glued on.
 */
export const scopeTargets = (selector, conditions = {}) => {
  const pseudo = pseudoTail(selector);
  return hostTargets(selector, conditions).map((host) => `${host}${pseudo}`);
};

/**
 * The properties a scope-root entry co-targets onto text-claiming parts
 * (CssPrinter mirror).
 *
 * An INDEPENDENT list, deliberately NOT derived from the Typography
 * property group. The server printer owns the full rationale
 * (CssPrinter::INHERITED_TEXT_PROPERTIES: direct skin defaults like
 * base:a beat root styles that reach a claiming interior element only by
 * inheritance, so the printers re-target this subset at delivery); the
 * lists must stay byte-identical.
 */
export const INHERITED_TEXT_PROPERTIES = [
  'font-family',
  'font-size',
  'font-weight',
  'font-style',
  'line-height',
  'letter-spacing',
  'text-align',
  'text-transform',
  'text-decoration-line',
  'color',
];

/**
 * The selector matching declared text-claiming elements, at zero weight
 * (CssPrinter::CLAIMS_CONTENT_SELECTOR mirror).
 *
 * The attribute name is the element model's anatomy fact, consumed from
 * its one owner (daedalus_ui's element resolver, both twins), never
 * re-hardcoded. The server printer owns the cascade rationale; the texts
 * must stay byte-identical.
 */
export const CLAIMS_CONTENT_SELECTOR = `:where([${CLAIMS_CONTENT_ATTRIBUTE}])`;

/**
 * The declarations of a property map that pass the emit gate
 * (CssPrinter::admittedDeclarations mirror): property => trimmed value,
 * stored order kept.
 *
 * @param {object} properties
 *   The property map (property => stored value).
 *
 * @return {object}
 *   The admitted declarations, property => trimmed value.
 */
const admittedDeclarations = (properties) => {
  const admitted = {};
  Object.entries(properties).forEach(([property, value]) => {
    if (
      value === null ||
      (typeof value !== 'string' &&
        typeof value !== 'number' &&
        typeof value !== 'boolean')
    ) {
      return;
    }
    value = String(value).trim();
    if (
      value === '' ||
      !PROPERTY_PATTERN.test(property) ||
      VALUE_FORBIDDEN.test(value) ||
      hasControlChar(value, VALUE_WHITESPACE)
    ) {
      return;
    }
    admitted[property] = value;
  });
  return admitted;
};

/**
 * Prints a property map as declaration lines, dropping unsafe entries.
 *
 * A motion trigger property (TRIGGER_MARKERS) prints its marker custom
 * property on the next line with the same value, wherever the property
 * prints (CssPrinter::printDeclarations mirror).
 *
 * @param {object} properties
 *   The property map (property => stored value).
 * @param {string} indent
 *   The whitespace each line starts with.
 *
 * @return {string}
 *   The declaration lines, newline-joined.
 */
const printDeclarations = (properties, indent) => {
  const lines = [];
  Object.entries(admittedDeclarations(properties)).forEach(
    ([property, value]) => {
      lines.push(`${indent}${property}: ${value};`);
      if (Object.hasOwn(TRIGGER_MARKERS, property)) {
        lines.push(`${indent}${TRIGGER_MARKERS[property]}: ${value};`);
      }
    },
  );
  return lines.join('\n');
};

/**
 * Whether a declaration block binds an animation to a trigger
 * (CssPrinter::holdsAnimation mirror): its admitted `animation-trigger`
 * is present and not `none`.
 *
 * @param {object} properties
 *   The property map (property => stored value).
 *
 * @return {boolean}
 *   True when the block binds an animation to a trigger.
 */
const holdsAnimation = (properties) => {
  const trigger = admittedDeclarations(properties)['animation-trigger'] ?? null;
  return trigger !== null && trigger.toLowerCase() !== 'none';
};

/**
 * Prints one style entry as an @scope block.
 *
 * @param {string} scopeRoot
 *   The @scope prelude selector, e.g. '[data-selectable="block:{uuid}"]'.
 * @param {?string} selector
 *   The key's canonical relative selector ('.quote', 'p', '> *',
 *   '> d-text .link', '&::before', '> d-text::after'), or null when
 *   the entry styles the scope root itself. Its targets are
 *   scopeTargets()'s, the condition suffixes composed on the hosts
 *   before the pseudo-element.
 * @param {object} entry
 *   The entry: condition keys mapping properties to values.
 * @param {object} conditions
 *   The condition table (guide.conditions): name => {label, wrapper}.
 * @param {?string} scopeLimit
 *   An @scope "to" limit selector bounding the scope from below (the
 *   donut, donutLimit()), or null for an unbounded scope.
 *
 * @return {string}
 *   The @scope block, or '' when nothing inside it printed.
 */
export const printEntry = (
  scopeRoot,
  selector,
  entry,
  conditions,
  scopeLimit = null,
) => {
  const targets = hostTargets(selector, conditions);
  // The terminal pseudo-element prints LAST on every target: after the
  // condition suffixes (the state holds on the host) and after the
  // hold's :not() (the runtime stamps the host); nothing composes behind
  // it (CssPrinter::entryPieces() mirror).
  const pseudo = pseudoTail(selector);
  // Only the scope root's own entry co-targets its inherited text
  // properties onto declared text-claiming elements: a selector entry
  // styles concrete interior elements (claiming parts included — a real
  // part style must WIN over the co-target, not feed it), and the owner's
  // own generated box ('&::before') is a selector entry like any other
  // (PHP printer mirror, CssPrinter::printEntry()).
  const coTarget = selector === null;
  const pieces = [];

  orderedConditions(entry, conditions).forEach(([condition, value]) => {
    // The "variants" reference list prints nothing here: referenced
    // variants deliver via the compiled shared stylesheet and stamped
    // classes. The entry's own condition blocks — the overrides layered
    // over its references — print normally (PHP printer mirror).
    if (condition === 'variants') {
      return;
    }
    if (!isEntryObject(value)) {
      return;
    }
    // "default" applies unconditionally; every other condition key — a
    // single clause or an "a+b" compound — reads through the clause
    // grammar (condition-clause.js over the guide payload's table) and
    // COMPOSES its clauses' CSS: a pseudo-class clause appends to the
    // target selector, an at-rule clause nests around the rule in clause
    // order (outermost first); a reference prints its published wrapper,
    // a literal its own canonical text. A key with any unknown clause
    // prints nothing — same emit-gate posture as the PHP printer.
    let suffixes = '';
    const atRules = [];
    if (condition !== 'default') {
      let unknown = false;
      parseCompound(condition, conditions ?? {}).forEach((clause) => {
        if (clause.kind === UNKNOWN) {
          unknown = true;
        } else if (clause.form === PSEUDO) {
          // A pseudo-class clause is a state (position is selector
          // grammar, printed at its compound): a posable one prints the
          // force-state dual with the word the stamp carries, an
          // unposable one bare (forceStateDual; the PHP printer owns the
          // rationale, the texts must stay byte-identical).
          suffixes += clausePosable(clause)
            ? forceStateDual(clause.css, clauseStamp(clause))
            : clause.css;
        } else {
          atRules.push(clause.css);
        }
      });
      if (unknown) {
        return;
      }
    }
    const targetIndent = '  '.repeat(atRules.length + 1);
    const declarations = printDeclarations(value, `${targetIndent}  `);
    if (declarations === '') {
      return;
    }
    // Pseudo-class suffixes append to EVERY selector in the target list,
    // the pseudo-element after them.
    const selector = targets
      .map((target) => `${target}${suffixes}${pseudo}`)
      .join(', ');
    let piece = `${targetIndent}${selector} {\n${declarations}\n${targetIndent}}`;
    if (coTarget) {
      // The companion declarations are the block's INHERITED_TEXT_PROPERTIES
      // subset in stored order, through the same emit gate (a subset of the
      // declarations that just printed, so an empty main rule above never
      // reaches here with a non-empty companion). It appends AFTER the rule
      // and INSIDE the same at-rule wrappers — one condition block holds
      // both rules — and pseudo-class suffixes stay on :scope: the
      // condition holds on the styled element, the claiming element merely
      // receives (CssPrinter::entryPieces() mirror).
      const inherited = Object.fromEntries(
        Object.entries(value).filter(([property]) =>
          INHERITED_TEXT_PROPERTIES.includes(property),
        ),
      );
      const companion = printDeclarations(inherited, `${targetIndent}  `);
      if (companion !== '') {
        piece += `\n${targetIndent}:scope${suffixes} ${CLAIMS_CONTENT_SELECTOR} {\n${companion}\n${targetIndent}}`;
      }
    }
    if (holdsAnimation(value)) {
      // The polyfill hold: a sibling of the rule after the companion,
      // inside the same condition wrappers, over exactly what the rule
      // targets (the same selector list, the same pseudo-class suffixes,
      // the same pseudo-element last), each member extended with
      // :not([data-daedalus-stylish-triggered]) on the host
      // (CssPrinter::entryPieces() mirror).
      const held = targets
        .map(
          (target) =>
            `${target}${suffixes}:not([${TRIGGERED_ATTRIBUTE}])${pseudo}`,
        )
        .join(', ');
      piece += `\n${targetIndent}${TRIGGER_HOLD_SUPPORTS} {\n${targetIndent}  ${held} {\n${targetIndent}    animation-play-state: paused;\n${targetIndent}  }\n${targetIndent}}`;
    }
    for (let i = atRules.length - 1; i >= 0; i--) {
      const indent = '  '.repeat(i + 1);
      piece = `${indent}${atRules[i]} {\n${piece}\n${indent}}`;
    }
    pieces.push(piece);
  });

  if (!pieces.length) {
    return '';
  }
  const prelude =
    scopeLimit === null
      ? `@scope (${scopeRoot})`
      : `@scope (${scopeRoot}) to (${scopeLimit})`;
  return `${prelude} {\n${pieces.join('\n')}\n}`;
};

/**
 * Prints ONE fragment of a variant's element-relative styles.
 *
 * CssPrinter::printFragmentStyles() mirror, locked to it byte for byte by
 * the round-trip equivalence test. A {variant, fragment} reference
 * delivers exactly its fragment's styles — the fragment's own entry at
 * the scope root, its '::.class' part entries at their class selectors —
 * rooted wherever the caller stamps the reference; deeper slot paths
 * under the fragment are OTHER fragments, delivered by the elements
 * carrying their own references. No positional selector compilation
 * exists anymore: position resolved to identity when apply distributed
 * the references. The container donut (CONTAINER_SCOPE_LIMIT) applies
 * whenever the fragment ADDRESSES a container — the '' fragment of a
 * container-family variant, or any container(n) fragment — because the
 * scope root then sits on a container's root element. The server printer owns
 * the full rationale.
 *
 * @param {string} scopeRoot
 *   The @scope prelude selector, e.g. '.daedalus-stylish-variant--{ref}' (possibly a
 *   comma list of class roots).
 * @param {string} scope
 *   The variant's scope string; picks the slot grammar's root kind and the
 *   fragment's addressed element kind.
 * @param {object} styles
 *   The variant's element-relative styles.
 * @param {string} fragment
 *   The fragment to print ('' for the root element). An unparseable
 *   fragment prints nothing; an unresolvable scope degrades to the
 *   path-less key space, where only the '' fragment can print.
 * @param {object} conditions
 *   The condition table (guide.conditions).
 * @param {?string} under
 *   The rule the fragment is worn at, or null at an element: each suffix
 *   continues the rule by descent (relative-selector.js under(); the ''
 *   suffix prints at the rule's own targets), and a suffix the grammar
 *   refuses under the rule prints nothing.
 *
 * @return {string}
 *   CSS text, '' when nothing printed.
 */
export const printFragmentStyles = (
  scopeRoot,
  scope,
  styles,
  fragment,
  conditions,
  under = null,
) => {
  const rootKind = rootKindForScope(scope);
  const slot = parseSlotKey(fragment, rootKind);
  if (slot === null) {
    return '';
  }
  const kind = slot.segments.length
    ? slot.segments[slot.segments.length - 1].type
    : rootKind;
  const blocks = [];
  // The fragment lookup is slot-key.js's fragmentStyles — the same read
  // the edit-mode resolver uses, so delivery and the panel never disagree.
  Object.entries(fragmentStyles(styles, fragment)).forEach(
    ([suffix, entry]) => {
      // The suffix is '' or '::selector'; the selector prints into
      // selectors, so the grammar (canonical spelling) is the emit gate,
      // the same gate authored keys pass.
      const split = splitKey(suffix);
      if (split === null) {
        return;
      }
      let selector = split.selector;
      if (under !== null) {
        selector = selector === null ? under : rebase(under, selector);
        if (selector === null) {
          return;
        }
      }
      const block = printEntry(
        scopeRoot,
        selector,
        entry,
        conditions,
        kind === 'container' ? donutLimit(selector) : null,
      );
      if (block !== '') {
        blocks.push(block);
      }
    },
  );
  return blocks.join('\n');
};

/**
 * Prints one animation as a named @keyframes at-rule.
 *
 * CssPrinter::printKeyframes() mirror, locked to it byte for byte by the
 * round-trip equivalence test. The named at-rule lane: a keyframes body is
 * offset-keyed and sits outside any @scope, so it prints as its own
 * top-level rule with the emit gate reused: the name must be a
 * custom-ident, each offset an offset, and each step's declarations pass
 * the declaration hygiene; a step with nothing printable is dropped, and an
 * animation with no printable step prints ''. Powers the keyframes editor's
 * live preview tag, re-printed per settle. The server printer owns the
 * full rationale.
 *
 * @param {string} name
 *   The keyframes name as it delivers (motion-{machine_name}).
 * @param {object} steps
 *   Offsets mapping property names to values.
 *
 * @return {string}
 *   The @keyframes block, or '' when nothing printed.
 */
export const printKeyframes = (name, steps) => {
  if (!KEYFRAMES_NAME_PATTERN.test(name)) {
    return '';
  }
  const pieces = [];
  Object.entries(steps ?? {}).forEach(([offset, declarations]) => {
    if (!isEntryObject(declarations) || !KEYFRAME_OFFSET_PATTERN.test(offset)) {
      return;
    }
    const printed = printDeclarations(declarations, '    ');
    if (printed === '') {
      return;
    }
    pieces.push(`  ${offset} {\n${printed}\n  }`);
  });
  if (!pieces.length) {
    return '';
  }
  return `@keyframes ${name} {\n${pieces.join('\n')}\n}`;
};

/**
 * Prints a whole style map.
 *
 * @param {object} map
 *   The style map.
 * @param {object} conditions
 *   The condition table (guide.conditions).
 *
 * @return {string}
 *   CSS text, one @scope block per printable key; '' when nothing prints.
 */
export const printMap = (map, conditions) => {
  const blocks = [];
  Object.entries(map ?? {}).forEach(([key, entry]) => {
    if (!isEntryObject(entry)) {
      return;
    }
    const parsed = parseKey(key);
    if (parsed === null) {
      return;
    }
    const pos = key.indexOf('::');
    const root = pos === -1 ? key : key.slice(0, pos);
    const block = printEntry(
      `[data-selectable="${root}"]`,
      parsed.selector,
      entry,
      conditions,
      parsed.kind === 'container' ? donutLimit(parsed.selector) : null,
    );
    if (block !== '') {
      blocks.push(block);
    }
  });
  return blocks.join('\n');
};

/**
 * Whether a style map styles anything under a container query condition.
 *
 * Mirrors ConditionTypePluginManager::mapUsesContainerConditions().
 *
 * @param {?object} map
 *   The style map.
 * @param {?object} conditions
 *   The condition table (guide.conditions), for compound parsing.
 *
 * @return {boolean}
 *   True when any entry's condition carries a container clause.
 */
export const mapUsesContainerConditions = (map, conditions) =>
  Object.values(map ?? {}).some((entry) => {
    if (!isEntryObject(entry)) {
      return false;
    }
    return Object.entries(entry).some(([condition]) => {
      if (condition === 'variants') {
        return false;
      }
      // Compound-aware, like the server: "narrow+dark" uses containers,
      // and so does a literal container clause.
      return parseCompound(condition, conditions ?? {}).some(
        (clause) => clause.form === CONTAINER,
      );
    });
  });

/**
 * The page-level width-band query root's selector (CssPrinter mirror).
 *
 * Width bands measure ONE page-level query container everywhere; the server
 * owns the rationale (CssPrinter::PAGE_CONTAINER_SELECTOR: poor man's
 * media query, canvas-not-body, nearest-ancestor hands edit mode to the
 * posed stage). The texts must stay byte-identical.
 */
export const PAGE_CONTAINER_SELECTOR = '.dialog-off-canvas-main-canvas';

/**
 * CSS establishing the page-level width-band query root.
 *
 * The client counterpart of CssStyleBackend::containerRootsCss(): the rule
 * is emitted OUTSIDE printMap on the server (backend-level), so the live
 * preview replicates it the moment a container condition first appears
 * client-side; otherwise the very keystroke that adds a "narrow" style
 * would preview dead until the op settled. One constant rule
 * (CssPrinter::PAGE_CONTAINER_RULE mirror): no per-element enumeration;
 * in edit mode the posed stage wins by nearest-ancestor resolution anyway.
 *
 * @param {object} map
 *   The (merged preview) style map.
 * @param {object} guide
 *   The style guide payload ({conditions, containerRoots}).
 *
 * @return {string}
 *   The page-container rule, or '' when the map demands no container.
 */
export const containerRootsCss = (map, guide) => {
  if (
    !guide?.containerRoots ||
    !mapUsesContainerConditions(map, guide?.conditions)
  ) {
    return '';
  }
  return `${PAGE_CONTAINER_SELECTOR} {\n  container-type: inline-size;\n}`;
};
