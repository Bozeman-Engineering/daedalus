/**
 * The variant picker's pure half: the offer as grouped, searchable
 * options, and the roving-focus roster the listbox walks. No JSX and no
 * Drupal.t here so node tests import it directly
 * (variant-picker-model.test.mjs); the rendering half (the search field,
 * the listbox, the popover posture) lives in variant-picker.js.
 *
 * The picker derives no offer of its own: it consumes the fit-ranked
 * applicable roster the wear bar already computes
 * (rankVariants(applicableVariants(...)), scope, ownership and tag
 * gates included) and only regroups, marks and filters it. The listbox
 * navigation map is the palette grid's (listboxHome / listboxTarget in
 * palette-picker-model.js): one roving-focus contract per inspector
 * listbox.
 */

import { axisLabel } from './variant-scope.js';
import { stencilLabel } from './library-model.js';
import { listboxHome } from './palette-picker-model.js';

/**
 * Whether a variant answers a search needle.
 *
 * Type-to-filter over the things a user knows a variant by: its label,
 * its axis's label, its owner's label when a stencil owns it (the
 * heading it sits under), and its description (the when-to-reach-for-
 * this text the AI reads from the same record). Case-insensitive; every
 * whitespace-separated token of the needle must land somewhere in that
 * text, so "hover lift" narrows the way a user expects and an empty
 * needle matches everything.
 *
 * @param {object} variant
 *   The variant record (label, description).
 * @param {string} axisLabelText
 *   The display label of the variant's axis.
 * @param {string} needle
 *   The search text, raw.
 * @param {string} [ownerLabelText]
 *   The display label of the owning stencil, '' when unowned.
 *
 * @return {boolean}
 */
export const matchesNeedle = (
  variant,
  axisLabelText,
  needle,
  ownerLabelText = '',
) => {
  const tokens = String(needle ?? '')
    .toLowerCase()
    .split(/\s+/)
    .filter(Boolean);
  if (!tokens.length) {
    return true;
  }
  const haystack = [
    variant.label ?? variant.id,
    axisLabelText,
    ownerLabelText,
    variant.description ?? '',
  ]
    .join(' ')
    .toLowerCase();
  return tokens.every((token) => haystack.includes(token));
};

/**
 * The picker's option groups: per axis with something to offer, the
 * owner's curated looks ahead of the axis's generic ones.
 *
 * Groups follow axis-declaration order (the vocabulary) and never leave
 * it: an applicable variant on an axis the vocabulary does not speak is
 * not offered, because the vocabulary is where depth enters (a root-only
 * axis at nested depth: variant-scope.js axisVocabulary) and an offer the
 * scope cannot wear would dress an axis it has no row for. Within an
 * axis, ownership is the heading: a stencil-owned variant (the offer
 * already gated it to the owner's placements) lands in a leading group
 * labeled by its stencil (library-model.js stencilLabel, either
 * ownership lane since the roster names both by one machine name), the
 * unowned stay in the axis's own group after it. Ownership names the
 * curated set; the axis stays the competition rule (Form A), so an
 * owned look and a generic one still replace each other. Within a
 * group the ranked order holds (fit-ranked: the roster's order). A worn
 * variant is marked, never hidden, because picking it in a worn axis IS
 * the swap's identity case and hiding it would make the axis look
 * empty. An `axis` narrows the offer to that one axis (the swap
 * trigger's preset); a `search` narrows it further (matchesNeedle, the
 * owner label included). Groups left with no option do not appear, so
 * the view never renders an empty header.
 *
 * @param {object} context
 * @param {Array<{variant: object, resolved: number, total: number}>} context.ranked
 *   The fit-ranked applicable roster (rankVariants' shape).
 * @param {string[]} context.vocabulary
 *   The scope's axis vocabulary (axisVocabulary()).
 * @param {Array<{variant: string, fragment: string}>} context.refs
 *   The element's worn references (entryRefs()).
 * @param {?object} context.guide
 *   The style guide (axis labels).
 * @param {object} context.materials
 *   The materials roster (the owners' stencil records).
 * @param {string} [context.search]
 *   The search text.
 * @param {?string} [context.axis]
 *   The one axis to offer, or null for every axis.
 *
 * @return {Array<{key: string, axis: string, owner: ?string, label:
 *   string, options: Array<{id: string, label: string, hint: ?{resolved:
 *   number, total: number}, worn: boolean, description: string}>}>}
 *   `key` is unique per group (the axis, or "axis:owner"): the view's
 *   identity for it. `owner` is the owning stencil's id on an owner
 *   group, null on an axis group. `hint` carries the fit facts when the
 *   variant fits fewer slots than it addresses (the view phrases "fits N
 *   of M slots"), null on a full fit; `description` is '' when the
 *   record has none.
 */
export const pickerGroups = ({
  ranked,
  vocabulary,
  refs,
  guide,
  materials,
  search = '',
  axis = null,
}) => {
  const worn = new Set((refs ?? []).map((ref) => ref.variant));
  // Per axis: its owner groups in first-seen (ranked) order, then the
  // axis group itself.
  const byAxis = new Map();
  vocabulary.forEach((name) => {
    if (axis === null || axis === name) {
      byAxis.set(name, {
        owned: new Map(),
        generic: {
          key: name,
          axis: name,
          owner: null,
          label: axisLabel(guide, name),
          options: [],
        },
      });
    }
  });
  (ranked ?? []).forEach(({ variant, resolved, total }) => {
    const slot = byAxis.get(variant.group ?? 'style');
    if (!slot) {
      return;
    }
    const owner = variant.owned_by ?? null;
    if (owner !== null && !slot.owned.has(owner)) {
      slot.owned.set(owner, {
        key: `${slot.generic.axis}:${owner}`,
        axis: slot.generic.axis,
        owner,
        label: stencilLabel(owner, materials),
        options: [],
      });
    }
    const group = owner === null ? slot.generic : slot.owned.get(owner);
    if (
      !matchesNeedle(
        variant,
        slot.generic.label,
        search,
        owner === null ? '' : group.label,
      )
    ) {
      return;
    }
    group.options.push({
      id: variant.id,
      label: variant.label ?? variant.id,
      hint: resolved === total ? null : { resolved, total },
      worn: worn.has(variant.id),
      description:
        typeof variant.description === 'string' ? variant.description : '',
    });
  });
  return [...byAxis.values()]
    .flatMap((slot) => [...slot.owned.values(), slot.generic])
    .filter((group) => group.options.length > 0);
};

/**
 * The option ids in listbox order: the roving-focus roster
 * (listboxTarget walks it across group boundaries, so ArrowDown from a
 * group's last option lands on the next group's first).
 *
 * @param {Array<{options: Array<{id: string}>}>} groups
 *   pickerGroups' result.
 *
 * @return {string[]}
 */
export const pickerIds = (groups) =>
  groups.flatMap((group) => group.options.map((option) => option.id));

/**
 * The option the picker lands on before arrows move: the best match.
 *
 * Without a search it is the listbox home (the worn option when the
 * picker opened on one axis, the native select's posture of landing on
 * the current value; else the first). With a search, the match quality
 * decides, not the group order: an option whose LABEL starts with the
 * needle first, then one whose label contains it, then the first option
 * left (a description-only hit). So typing a variant's name lands on
 * that variant even when an earlier group's description also says the
 * word, and Enter applies what was typed.
 *
 * @param {Array<{options: Array<{id: string, label: string}>}>} groups
 *   pickerGroups' result.
 * @param {string} search
 *   The search text, raw.
 * @param {?string} worn
 *   The worn option's id to prefer without a search, or null.
 *
 * @return {?string}
 */
export const pickerHome = (groups, search = '', worn = null) => {
  const options = groups.flatMap((group) => group.options);
  const needle = String(search ?? '')
    .trim()
    .toLowerCase();
  if (needle === '') {
    return listboxHome(
      options.map((option) => option.id),
      worn,
    );
  }
  const labels = options.map((option) => option.label.toLowerCase());
  const index = [
    labels.findIndex((label) => label.startsWith(needle)),
    labels.findIndex((label) => label.includes(needle)),
    options.length ? 0 : -1,
  ].find((candidate) => candidate !== -1);
  return index === undefined ? null : options[index].id;
};
