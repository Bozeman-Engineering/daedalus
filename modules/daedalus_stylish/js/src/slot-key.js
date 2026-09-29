/**
 * The slot-key grammar: client mirror of \Drupal\daedalus_stylish\SlotKey.
 *
 * PHP is authoritative (the server re-validates every write); this mirror
 * exists so the client surfaces (capture walking the staged subtree, the
 * preview printer, the picker's fit ranking) can speak the grammar without
 * a round trip. One key space:
 *
 *   key       = path? ("::" selector)?
 *   path      = segment ("/" segment)*
 *   segment   = container(index) | block(type,index) | field(name)
 *   name/type = machine name [a-z0-9_]+
 *   index     = 1-based [1-9][0-9]*
 *   selector  = a canonical relative selector (relative-selector.js)
 *
 * Structural rules are parameterized by the ROOT KIND: container roots
 * start paths at container(index) or block(type,index) — a container's
 * children are ONE ordered flow, no region segments; block roots at
 * field(name); field, entity and region roots carry no paths, selectors
 * only. container(...) is followed by container(index) or
 * block(type,index) — container(n), the nth nested container, is the
 * recursion into nested compositions (containers all the way down);
 * field(...) follows any block segment and terminates the path; the '::'
 * selector suffix may terminate any key, admitted canonical only, and
 * since a selector never contains '::' the first '::' always splits path
 * from selector.
 */
import { splitKey } from '../../../daedalus_ui/js/edit_mode/materials/relative-selector.js';

/**
 * Segment types each root kind may START a path with (empty: parts only).
 */
const FIRST_SEGMENTS = {
  entity: [],
  container: ['container', 'block'],
  region: [],
  field: [],
  block: ['field'],
};

/**
 * The root kinds the structural rules are parameterized by.
 */
export const ROOT_KINDS = Object.keys(FIRST_SEGMENTS);

const SEGMENT =
  /^(?:(field)\(([a-z0-9_]+)\)|(block)\(([a-z0-9_]+),([1-9][0-9]*)\)|(container)\(([1-9][0-9]*)\))$/;

/**
 * Parses an element-relative style key.
 *
 * @param {string} key
 *   The key ('' is the root element).
 * @param {?string} rootKind
 *   The root element's kind, one of ROOT_KINDS. null when unknown (an
 *   unresolvable scope), which permits only the path-less keys ('' and
 *   '::selector'). An unknown non-null kind throws: a caller bug, not
 *   bad data.
 *
 * @return {?{segments: Array<{type: string, name?: string, index?: number}>, selector: ?string}}
 *   The parsed key (segments outermost first; name on block/field
 *   segments, index on container and block segments; selector the
 *   canonical relative selector after the '::', null for the element
 *   itself), or null when it does not parse.
 */
export const parseSlotKey = (key, rootKind = null) => {
  if (rootKind !== null && !Object.hasOwn(FIRST_SEGMENTS, rootKind)) {
    throw new Error(
      `"${rootKind}" is not a slot-key root kind (expected one of: ${ROOT_KINDS.join(', ')}).`,
    );
  }
  // The first '::' splits the path from its selector (the one split every
  // key grammar shares); the selector is admitted canonical only (PHP
  // mirror: SlotKey::parse()).
  const split = splitKey(key);
  if (split === null) {
    return null;
  }
  const { root: path, selector } = split;

  const segments = [];
  if (path !== '') {
    let allowed = rootKind !== null ? FIRST_SEGMENTS[rootKind] : [];
    for (const raw of path.split('/')) {
      const match = raw.match(SEGMENT);
      if (!match) {
        return null;
      }
      let segment;
      if (match[1] !== undefined) {
        segment = { type: match[1], name: match[2] };
      } else if (match[3] !== undefined) {
        segment = { type: 'block', name: match[4], index: Number(match[5]) };
      } else {
        segment = { type: 'container', index: Number(match[7]) };
      }
      if (!allowed.includes(segment.type)) {
        return null;
      }
      segments.push(segment);
      if (segment.type === 'container') {
        allowed = ['container', 'block'];
      } else if (segment.type === 'block') {
        allowed = ['field'];
      } else {
        allowed = [];
      }
    }
  }

  return { segments, selector };
};

/**
 * The canonical string form; parseSlotKey(formatSlotKey(...)) round-trips.
 *
 * The serializer for key BUILDERS (capture walking a staged subtree);
 * parseSlotKey stays the one validator, so builders producing structural
 * nonsense find out at the parse/validate seam, not here.
 */
export const formatSlotKey = (segments, selector = null) => {
  const path = (segments ?? [])
    .map((segment) => {
      if (segment.name === undefined) {
        return `${segment.type}(${segment.index})`;
      }
      return segment.index !== undefined
        ? `${segment.type}(${segment.name},${segment.index})`
        : `${segment.type}(${segment.name})`;
    })
    .join('/');
  return (
    path + (selector !== null && selector !== undefined ? `::${selector}` : '')
  );
};

/**
 * Segment-wise tree order: parents before children, siblings by index.
 *
 * Lexicographic over parsed slot segments with the shorter prefix first,
 * which IS layout tree order for slot paths (a container precedes its
 * blocks, block(text,1) precedes block(text,2)). Positional segments
 * compare by index then name; field segments by name. The one comparator
 * behind every surface that walks a variant's key space as a tree
 * (pending.js payloadRows, the variant document's slot tree), so they can
 * never disagree about the order the tree reads.
 */
export const compareSegments = (a, b) => {
  const length = Math.min(a.length, b.length);
  for (let i = 0; i < length; i += 1) {
    const indexA = a[i].index ?? 0;
    const indexB = b[i].index ?? 0;
    if (indexA !== indexB) {
      return indexA - indexB;
    }
    const nameA = a[i].name ?? '';
    const nameB = b[i].name ?? '';
    if (nameA !== nameB) {
      return nameA < nameB ? -1 : 1;
    }
  }
  return a.length - b.length;
};

/**
 * A variant's styles at one fragment, keyed by part suffix (PHP mirror).
 *
 * The shape a single {variant, fragment} reference delivers to its
 * carrying element (SlotKey::fragmentStyles): the fragment's own entry
 * reads as '' and its '::selector' entries as their '::selector' suffix,
 * so "{key}{suffix}" is the map key the styles belong to on the carrier.
 * Deeper slot paths under the fragment are OTHER fragments (their
 * elements carry their own references) and never read here. A fragment
 * the variant does not hold reads empty — valid but inert. The ONE
 * client fragment-lookup semantics: the printer, the style stack
 * (buildStack's reference layers) and the wear bar all read through
 * here, so panel and canvas can never disagree about what a reference
 * delivers.
 *
 * @param {object} styles
 *   The variant's element-relative styles.
 * @param {string} fragment
 *   The fragment slot key ('' for the root element).
 *
 * @return {object}
 *   Style entries keyed by '' or '::selector' suffix, in stored order.
 *   Non-object and empty entries are dropped, and each entry reads
 *   values-only: a nested "variants" reference list is stripped (it
 *   distributes at apply time, never through the carrier's lookup).
 */
export const fragmentStyles = (styles, fragment) => {
  const matched = {};
  Object.entries(styles ?? {}).forEach(([relative, entry]) => {
    if (entry === null || typeof entry !== 'object') {
      return;
    }
    // Nested variant references are not values: an entry-shaped fragment's
    // "variants" list distributes onto the resolved elements at APPLY time
    // (bind-once), so nothing may deliver, materialize, or resolve it
    // through the carrier's fragment lookup. The read is values-only;
    // entryRefs() is the one structured read for the list (PHP mirror:
    // SlotKey::fragmentStyles strips RESERVED_ENTRY_KEYS the same way).
    const { variants, ...values } = entry;
    if (!Object.keys(values).length) {
      return;
    }
    if (relative === fragment) {
      matched[''] = values;
    } else if (relative.startsWith(`${fragment}::`)) {
      matched[relative.slice(fragment.length)] = values;
    }
  });
  return matched;
};

/**
 * The structural root kind a variant scope implies (PHP mirror).
 *
 * Layout scopes are container roots; every bundle is a block root; kinds
 * map to themselves.
 *
 * @return {?string}
 *   One of ROOT_KINDS, or null when the string is not a variant scope
 *   (parseSlotKey then degrades to the path-less key space).
 */
export const rootKindForScope = (scope) => {
  if (typeof scope !== 'string') {
    return null;
  }
  if (/^layout:[a-z0-9_]+$/.test(scope)) {
    return 'container';
  }
  if (/^block_type:[a-z0-9_]+$/.test(scope)) {
    return 'block';
  }
  const kind = scope.match(/^kind:([a-z0-9_]+)$/);
  return kind && Object.hasOwn(FIRST_SEGMENTS, kind[1]) ? kind[1] : null;
};
