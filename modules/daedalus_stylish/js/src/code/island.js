import { parse as parseCss, lexer } from 'css-tree';
import { validateValue } from './validate-value.js';
import { isWideProperty } from './wide-tier.js';
import {
  constraintsAreLaw,
  constraintsFor,
  declaredSets,
  propertiesClosed,
  propertyGranted,
} from '../style-guide.js';
import { findVariant, orderedEntryRefs } from '../variant-scope.js';
import {
  DECLARED_LAYER,
  ELEMENT_LAYER,
  SKIN_LAYER,
  variantLayerId,
} from './layers.js';

/**
 * The island engine: the pure core of the islands code view.
 *
 * In the islands architecture ALL structure is chrome (selectable keys,
 * part rows, condition rows), so every editable buffer is a FLAT DECLARATION
 * LIST scoped to one (key, condition) pair. That flatness is what this
 * module cashes in: csstree parses an island whole (declarationList
 * context; no homegrown segmentation), validation is per declaration with a
 * statically known key (validate-value.js, the server mirror), and the diff
 * emits ONE update_style op per settled declaration (one op = one history
 * entry; an invalid line holds only itself).
 *
 * The print side reads the LAYERED document: an island's lines are every
 * layer's declarations for its condition in delivery order (the defaults
 * floor at the bottom when the caller hands one: ElementIslands hands it
 * for the SELECTED element's islands only, in every code scope, and the
 * tree's sparse rows print the map alone; variants above it; the
 * element's own layer on top), exactly how the CSS cascade reads a
 * duplicate: the last declaration of a property wins. Each line carries
 * its layer id (layers.js vocabulary) plus the shadowing facts
 * (lost/winner) the view renders as in-place strikethrough with a winner
 * dot. The diff runs against those same lines, so a formatting-only edit
 * diffs to nothing, editing the winning value commits an override (the
 * layered entry model; references stay worn, the floor stays where it
 * is), deleting a variant's or floor line honestly no-ops (there is no
 * override to remove; only detach removes a variant's value, and the floor
 * is not in the map at all), and deleting the element's own winning line
 * unsets the override so the layer beneath resurfaces.
 */

const isEntryObject = (value) =>
  value !== null && typeof value === 'object' && !Array.isArray(value);

/**
 * Collects identifier tokens as held property names.
 *
 * Anything an island cannot commit (raw chunks, structural nodes, invalid
 * declarations) still MENTIONS the identifiers it contains. Held names are
 * exempt from deletion detection so a half-typed or mid-repair line never
 * reads as "the user deleted this property" (a conservative superset is
 * safe: it can only delay an unset until the island is clean).
 */
const holdTokens = (slice, held) => {
  (slice.match(/[A-Za-z-][A-Za-z0-9-]*/g) ?? []).forEach((token) =>
    held.add(token),
  );
};

/**
 * Parses and validates one island's buffer.
 *
 * The buffer is a flat declaration list (structure is chrome; nothing here
 * ever creates keys, parts, or conditions). Every declaration gets the same
 * constraint checks the server applies on commit: same codes, same
 * style-guide hints (validate-value.js mirrors PropertyGroupBase), plus
 * csstree grammar advice as warnings (the permissive server would accept
 * grammar misses, so they never block). Token references get the same
 * split: an unminted var() in a declared scale's namespace is a warning on
 * permissive sites (inert, the server accepts) and an error on strict ones
 * (the server rejects). Properties outside the guide
 * payload's groups fall to the WIDE TIER (wide-tier.js): recognized by
 * csstree's embedded tables (the same pinned mdn-data the server's
 * generated groups derive from), hygiene-checked, grammar-linted — the
 * grammar is the client's whole value story there, the server's typed
 * definitions being server-only. Structural nodes (a pasted rule or
 * at-rule) are errors: selectors and condition wrappers are chrome rows,
 * not island text. Unparseable chunks are warnings; mid-typing is normal
 * life under per-declaration commit and holds only itself.
 *
 * @param {string} text
 *   The island's buffer text.
 * @param {object} options
 *   - guide: the style guide payload (drupalSettings.daedalusStylish.guide).
 *   - declaration: the island key's served node (the element's
 *     declarationForKey result, or the part's node for a '::.class'
 *     island): the roster whose grants and value spaces are the law
 *     mirrored here.
 *
 * @return {{declarations: Array<object>, held: Set<string>, diagnostics: Array<object>}}
 *   declarations: {property, value, important, from, to, valueFrom,
 *   valueTo, valid} per parsed declaration, in buffer order (value is the
 *   trimmed raw slice, ' !important' folded in). held: property names
 *   mentioned by content that cannot commit (see holdTokens). diagnostics:
 *   {from, to, severity, message, code?} spans, CM6-lint-shaped, sorted.
 */
export const analyzeIsland = (text, { guide, materials, declaration } = {}) => {
  const closed = propertiesClosed(guide);
  // The enforcement mirror's law switch: on an open-enforcement site the
  // server demotes every authored constraint to offer-shaping, so the
  // lint must not raise blocking constraint errors either (never block
  // free entry client-side) — grammar and recognition stay the whole law.
  const law = constraintsAreLaw(guide);
  const groups = guide?.groups ?? {};
  const groupIds = Object.keys(groups);
  const ownerOf = (property) =>
    groupIds.find((id) => groups[id]?.properties?.[property]) ?? null;
  // The materials context validate-value's scale and reference checks
  // read: the guide's scale declarations (the namespaces), the materials
  // roster (what is minted and published), and the closed world (which
  // decides whether an unminted or unpublished reference errors or warns).
  const tokens = { guide, materials, closed };

  const declarations = [];
  const held = new Set();
  const diagnostics = [];
  const addDiagnostic = (from, to, severity, message, code = null) => {
    diagnostics.push({
      from,
      to,
      severity,
      message,
      ...(code ? { code } : {}),
    });
  };

  let ast;
  try {
    ast = parseCss(text, { context: 'declarationList', positions: true });
  } catch {
    holdTokens(text, held);
    addDiagnostic(
      0,
      text.length,
      'warning',
      'Does not parse as CSS declarations; nothing here will save until it does.',
    );
    return { declarations, held, diagnostics };
  }

  ast.children.forEach((node) => {
    const from = node.loc.start.offset;
    const to = node.loc.end.offset;

    if (node.type !== 'Declaration') {
      holdTokens(text.slice(from, to), held);
      if (node.type === 'Atrule') {
        // Structure is chrome, never characters — and at-rules split only
        // on which honest message applies: a known condition belongs to a
        // WHEN row (the `@` gesture materializes it), while an at-rule
        // outside the condition vocabulary is inexpressible in the model
        // (there is no raw-CSS escape hatch).
        if (
          Object.prototype.hasOwnProperty.call(
            guide?.conditions ?? {},
            node.name,
          )
        ) {
          addDiagnostic(
            from,
            to,
            'error',
            `Conditions are their own rows here, not island text. Type "@" on an empty line and pick "${node.name}" to add its WHEN row.`,
          );
        } else {
          addDiagnostic(
            from,
            to,
            'error',
            `"@${node.name}" is not a condition this site declares; at-rules cannot be styled here.`,
          );
        }
      } else if (node.type === 'Rule') {
        // A typed or pasted selector cannot live in an island
        // (materialization gestures create part/condition rows before text
        // ever lands here).
        addDiagnostic(
          from,
          to,
          'error',
          'Selectors and condition blocks cannot live here; this island holds plain declarations. Conditions and parts are added as their own rows.',
        );
      } else {
        addDiagnostic(
          from,
          to,
          'warning',
          'Does not parse as a CSS declaration; it will not save until it does.',
        );
      }
      return;
    }

    const property = node.property;
    const valueFrom = node.value.loc.start.offset;
    const valueTo = node.value.loc.end.offset;
    const value = (
      text.slice(valueFrom, valueTo).trim() +
      (node.important ? ' !important' : '')
    ).trim();
    const record = {
      property,
      value,
      important: Boolean(node.important),
      from,
      to,
      valueFrom,
      valueTo,
      valid: true,
    };
    declarations.push(record);

    const fail = (spanFrom, spanTo, message, code) => {
      record.valid = false;
      addDiagnostic(spanFrom, spanTo, 'error', message, code);
    };
    // Violation router: warning-severity violations (an unminted token
    // reference on a permissive site) surface as warning diagnostics and
    // never block the commit — the server accepts the value; an unresolved
    // var is inert, never unsafe.
    const report = (violation) => {
      if (violation.severity === 'warning') {
        addDiagnostic(
          valueFrom,
          valueTo,
          'warning',
          `${violation.message} ${violation.hint}`,
          violation.code,
        );
      } else {
        fail(
          valueFrom,
          valueTo,
          `${violation.message} ${violation.hint}`,
          violation.code,
        );
      }
    };

    // csstree grammar advice: warnings only: the server is deliberately
    // permissive here, so grammar misses must not block. For the wide tier
    // this IS the client's value check (the typed definitions are
    // server-only), still a warning: full grammar matching is client UX,
    // never the line of defense, and a safe-but-ungrammatical value is
    // inert in the browser.
    const grammarAdvice = (node, value) => {
      if (value.includes('var(') || node.important) {
        return;
      }
      try {
        const match = lexer.matchProperty(node.property, node.value);
        if (
          match.error &&
          match.error.rawMessage !== undefined &&
          !/^Unknown property|not supported/.test(match.error.rawMessage ?? '')
        ) {
          addDiagnostic(
            node.value.loc.start.offset,
            node.value.loc.end.offset,
            'warning',
            `"${value}" does not parse as a ${node.property} value.`,
          );
        }
      } catch {
        // Unknown to csstree's data: no grammar advice available.
      }
    };

    const sets = declaredSets(declaration);
    const owner = property.startsWith('--') ? null : ownerOf(property);
    if (owner === null) {
      // Wide tier: a property outside the guide payload's groups that
      // csstree's embedded tables recognize is recognized server-side by
      // the generated PropertyGroups (same pinned mdn-data; the
      // wide-properties battery locks the parity). The server owns the
      // typed validation for it; the client checks hygiene plus any
      // declared "allowed" constraint and lints the real grammar. The
      // grant is the roster's: a generated group's granted names ride
      // the node's grants like a curated group's, so the closed-world
      // gate is one read for both tiers.
      if (isWideProperty(property)) {
        if (closed && !propertyGranted(declaration, property)) {
          const declared = sets.join(', ') || '(none)';
          fail(
            from,
            from + property.length,
            `The style guide does not declare "${property}" as stylable on this element. Declared property sets: ${declared}.`,
            'STYLE_PROPERTY_NOT_DECLARED',
          );
          return;
        }
        const constraints = law ? constraintsFor(declaration, property) : null;
        validateValue(
          property,
          value,
          { type: 'any' },
          constraints,
          tokens,
        ).forEach(report);
        if (!record.valid) {
          holdTokens(text.slice(from, to), held);
          return;
        }
        grammarAdvice(node, value);
        return;
      }
      fail(
        from,
        from + property.length,
        closed
          ? `"${property}" is not a recognized style property. This site enforces its style guide; only recognized, declared properties may be styled.`
          : `"${property}" is not a recognized style property; it cannot save here. Check the spelling.`,
        'INVALID_STYLE_PROPERTY',
      );
      holdTokens(text.slice(from, to), held);
      return;
    }

    if (closed && !propertyGranted(declaration, property)) {
      // The roster's grant read, the same answer the server's
      // validateProperty gives (a narrowed grant leaves its excluded
      // names ungranted), at every placement depth (a grant is a
      // function of the address alone).
      const declared = sets.join(', ') || '(none)';
      fail(
        from,
        from + property.length,
        `The style guide does not declare "${property}" as stylable on this element. Declared property sets: ${declared}.`,
        'STYLE_PROPERTY_NOT_DECLARED',
      );
      return;
    }

    // Enforcement mirror: the same grant/constraint checks the server
    // runs on commit, surfaced at the :/; boundary with the style guide's
    // own hints. The roster's value space (the node's own block, else the
    // site's authored default, exactly what validateProperty binds), and
    // only while constraints are law: the payload's site-wide
    // constraintDefaults map is an offer layer, so the lint never reads
    // it.
    const constraints = law ? constraintsFor(declaration, property) : null;
    const definition = groups[owner]?.properties?.[property] ?? null;
    validateValue(property, value, definition, constraints, tokens).forEach(
      report,
    );
    if (!record.valid) {
      // An invalid value may be a mid-repair merge of neighboring lines
      // (a lost semicolon swallows the next declaration into this value);
      // hold everything it mentions so nothing reads as deleted.
      holdTokens(text.slice(from, to), held);
      return;
    }

    grammarAdvice(node, value);
  });

  return {
    declarations,
    held,
    diagnostics: diagnostics.sort((a, b) => a.from - b.from),
  };
};

/**
 * One island's lines: the layered document with per-line provenance.
 *
 * Every layer's declarations for the (key, condition) pair, in DELIVERY
 * order: the defaults floor first when the caller hands one (the
 * declaration's own defaults, then the active skin's, the order delivery
 * prints them), then each reference's fragment styles (orderedEntryRefs
 * order), the element's own layer last — so the buffer reads like the
 * cascade: a property set by several layers prints once per layer and the
 * LAST declaration wins. Each line carries its layer id (`layer`:
 * ELEMENT_LAYER, SKIN_LAYER, DECLARED_LAYER, or variantLayerId()), the
 * supplying reference (`variant`: the reference string on a variant line,
 * null on every other layer's line) and the shadowing facts: `lost` marks
 * a declaration a higher layer beats, and `winner` names that layer
 * ({layer, variant}, null on winning lines). The view renders lost lines
 * as in-place strikethrough with a dot naming the winner, the shadowing
 * display that replaced ghost rows.
 *
 * The floor is read at the island's EXACT condition, the way the map is
 * (never the cascade: the widget modes' ghost is the cascade reading, the
 * code view is the exact document), and it sits at the bottom, so a floor
 * line can never win over a map line: the skin beats the declaration, and
 * every worn or own line beats both.
 *
 * @param {object} map
 *   The style map (mirror view).
 * @param {string} key
 *   The island's full style-map key (root, '::.class' part, or block-field
 *   form).
 * @param {string} condition
 *   'default' or a named condition. Never 'variants' (the reference list
 *   is not a condition layer).
 * @param {?Array<object>} variants
 *   The store's variant records.
 * @param {?Array<string>} axes
 *   The root scope's axis vocabulary for delivery-order resolution; null
 *   keeps stored-list order.
 * @param {?{skinEntry: ?object, declaredEntry: ?object}} floor
 *   The defaults floor for this island's key: the condition-keyed entries
 *   skinDefaultEntry() and declaredDefaultEntry() build (the same entries
 *   provenanceFor assembles), or null to print the map's lines only (the
 *   tree scopes' sparse rows, the roster's occupancy read).
 *
 * @return {Array<{property: string, value: string, layer: string,
 *   variant: ?string, lost: boolean, winner: ?{layer: string,
 *   variant: ?string}}>}
 *   The island's lines in delivery order, empty when the pair holds
 *   nothing.
 */
export const islandLines = (
  map,
  key,
  condition,
  variants,
  axes = null,
  floor = null,
) => {
  const pos = key.indexOf('::');
  const rootKey = pos === -1 ? key : key.slice(0, pos);
  const relative = key.slice(rootKey.length);
  const refs = orderedEntryRefs(map?.[rootKey], variants, axes);
  const own = isEntryObject(map?.[key]?.[condition]) ? map[key][condition] : {};
  const floorValues = (entry) =>
    isEntryObject(entry) && isEntryObject(entry[condition])
      ? entry[condition]
      : {};

  const layers = [
    {
      layer: DECLARED_LAYER,
      variant: null,
      values: floorValues(floor?.declaredEntry),
    },
    { layer: SKIN_LAYER, variant: null, values: floorValues(floor?.skinEntry) },
  ];
  refs.forEach((ref) => {
    const styles = findVariant(variants, ref.variant)?.value?.[
      ref.fragment + relative
    ];
    const values =
      isEntryObject(styles) && isEntryObject(styles[condition])
        ? styles[condition]
        : {};
    layers.push({
      layer: variantLayerId(ref.variant),
      variant: ref.variant,
      values,
    });
  });
  layers.push({ layer: ELEMENT_LAYER, variant: null, values: own });

  // Per property, the LAST defining layer wins (delivery order; the own
  // layer sits on top). Indexed, not by layer id: a composition pair
  // (root + fragment ref of the same variant on one axis) is two distinct
  // layers with one id.
  const winnerIndex = new Map();
  layers.forEach((layer, index) => {
    Object.entries(layer.values).forEach(([property, value]) => {
      if (value !== null && value !== undefined) {
        winnerIndex.set(property, index);
      }
    });
  });

  const lines = [];
  layers.forEach((layer, index) => {
    Object.entries(layer.values).forEach(([property, value]) => {
      if (value === null || value === undefined) {
        return;
      }
      const lost = winnerIndex.get(property) !== index;
      const winning = lost ? layers[winnerIndex.get(property)] : null;
      lines.push({
        property,
        value: String(value).trim(),
        layer: layer.layer,
        variant: layer.variant,
        lost,
        winner: winning
          ? { layer: winning.layer, variant: winning.variant }
          : null,
      });
    });
  });
  return lines;
};

/**
 * Prints an island's buffer text from its lines.
 *
 * analyzeIsland(printIsland(lines)) reproduces the lines' properties and
 * values (the island half of the round-trip contract), so an untouched
 * (or formatting-only edited) buffer diffs to zero ops.
 */
export const printIsland = (lines) =>
  lines.map((line) => `${line.property}: ${line.value};`).join('\n');

/**
 * Diffs one island's buffer against its layered lines into a delta.
 *
 * The cascade is the comparator: per property, the buffer's EFFECTIVE
 * value is its last valid declaration (later duplicates win, exactly how
 * the printed layered document reads), and it compares against the lines'
 * winning value. From that:
 * - A differing (or new) effective value is a SET — an override; the
 *   references stay worn. Editing a LOST line alone changes nothing
 *   effective and honestly diffs to nothing (the cascade still shows the
 *   winner).
 * - A property whose buffer declarations shrank to exactly the layers'
 *   text beneath the own layer (the element's own winning line deleted,
 *   the struck lines beneath still standing) is an UNSET: the override
 *   lifts and the layer beneath (a variant's, or the floor's) resurfaces.
 * - A property the buffer no longer mentions at all unsets ONLY the
 *   element's own layer. A variant's or floor line vanishing is a no-op:
 *   there is no override to remove (shouldRestoreTinted re-prints them;
 *   removing a variant's value takes a detach, not an island edit, and
 *   the floor is not in the map at all).
 * - Invalid or unparseable content holds only itself: its mentions
 *   (analysis.held plus invalid declarations' properties) are exempt from
 *   deletion detection.
 *
 * The own test is the line's layer id (ELEMENT_LAYER); every other layer,
 * variant or floor, is a non-own line under the same rules.
 *
 * @param {{declarations: Array<object>, held: Set<string>}} analysis
 *   analyzeIsland()'s result for the buffer.
 * @param {Array<{property: string, value: string, layer: string,
 *   lost: boolean}>} lines
 *   islandLines()'s result the buffer was printed from.
 *
 * @return {{sets: Array<{property: string, value: string}>,
 *   unsets: Array<string>}}
 *   Sets in buffer order, unsets in detection order.
 */
export const islandDelta = (analysis, lines) => {
  const baseline = new Map();
  lines.forEach((line) => {
    let group = baseline.get(line.property);
    if (!group) {
      group = { count: 0, own: null, effective: null, beneath: null };
      baseline.set(line.property, group);
    }
    group.count += 1;
    if (line.layer === ELEMENT_LAYER) {
      group.own = line.value;
    } else {
      // Delivery order: the last non-own line (a variant's or the floor's)
      // is the winner beneath the own layer, what the property falls
      // back to when the own line unsets.
      group.beneath = line.value;
    }
    if (!line.lost) {
      group.effective = line.value;
    }
  });

  const settled = new Map();
  const mentioned = new Set(analysis.held);
  analysis.declarations.forEach((declaration) => {
    mentioned.add(declaration.property);
    if (declaration.valid) {
      const values = settled.get(declaration.property) ?? [];
      values.push(declaration.value);
      settled.set(declaration.property, values);
    }
  });

  const sets = [];
  const unsets = [];
  settled.forEach((values, property) => {
    const group = baseline.get(property);
    const last = values[values.length - 1];
    if (!group) {
      sets.push({ property, value: last });
      return;
    }
    if (last === group.effective) {
      return;
    }
    // The own winning line was deleted while the text beneath still
    // stands: the follow-the-layer-beneath-again gesture, an unset, not
    // an override set to that layer's own value.
    const deletedOwn =
      group.own !== null &&
      !analysis.held.has(property) &&
      values.length < group.count &&
      !values.includes(group.own) &&
      group.beneath !== null &&
      last === group.beneath;
    if (deletedOwn) {
      unsets.push(property);
    } else {
      sets.push({ property, value: last });
    }
  });
  lines.forEach((line) => {
    if (line.layer !== ELEMENT_LAYER || mentioned.has(line.property)) {
      return;
    }
    unsets.push(line.property);
  });
  return { sets, unsets };
};

/**
 * Diffs one settled island against its printed lines into update_style ops.
 *
 * islandDelta()'s result as single-property operations (one op = one
 * history entry): sets in buffer order, then unsets.
 *
 * @param {string} key
 *   The island's full style-map key.
 * @param {string} condition
 *   The island's condition ('default' omits the op parameter).
 * @param {{declarations: Array<object>, held: Set<string>}} analysis
 *   analyzeIsland()'s result for the settled buffer.
 * @param {Array<object>} lines
 *   islandLines()'s result the buffer was printed from.
 *
 * @return {Array<object>}
 *   Single-property update_style operations; empty when the buffer says
 *   what the layered document says.
 */
export const islandOps = (key, condition, analysis, lines) => {
  const makeOp = (properties) => ({
    op: 'update_style',
    key,
    ...(condition !== 'default' ? { condition } : {}),
    properties,
  });
  const delta = islandDelta(analysis, lines);
  return [
    ...delta.sets.map(({ property, value }) => makeOp({ [property]: value })),
    ...delta.unsets.map((property) => makeOp({ [property]: null })),
  ];
};

/**
 * Pairs buffer declarations with the baseline lines they still say.
 *
 * First-fit in order over (property, value): each declaration claims the
 * first unclaimed line with its exact property and value, so duplicates
 * (a lost line and its winner) pair positionally. An unmatched declaration
 * (a user edit in progress) maps to null — the view prints it plain, the
 * override-in-the-making.
 *
 * @param {Array<object>} declarations
 *   analyzeIsland()'s declarations, buffer order.
 * @param {Array<object>} lines
 *   islandLines()'s result the buffer was printed from.
 *
 * @return {Array<?object>}
 *   Per declaration: the matched line, or null.
 */
export const matchLines = (declarations, lines) => {
  const claimed = new Array(lines.length).fill(false);
  return declarations.map((declaration) => {
    const index = lines.findIndex(
      (line, i) =>
        !claimed[i] &&
        line.property === declaration.property &&
        line.value === declaration.value,
    );
    if (index === -1) {
      return null;
    }
    claimed[index] = true;
    return lines[index];
  });
};
