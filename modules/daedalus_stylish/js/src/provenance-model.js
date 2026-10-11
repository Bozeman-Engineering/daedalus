import { compoundLabel, propertyValues } from './condition-cascade.js';
import {
  ELEMENT_LAYER,
  TIER_ANCESTOR,
  TIER_DECLARED,
  TIER_SKIN,
  TIER_VARIANT,
  ancestorLayerId,
  ancestorRule,
  ancestorVariant,
  layerColor,
} from './code/layers.js';
import {
  TIER_ELEMENT,
  deliveredEntry,
  editableLayer,
  layerAt,
} from './style-stack.js';
import { addressKey, addressLabel } from './write-address.js';
import {
  displayAt,
  magneticCapture,
  valuesEqual,
  wearing,
} from './value-space.js';
import { tagWord } from './style-guide.js';

/**
 * @file
 * The property row's provenance model (pure; the rows render it).
 *
 * One origin per row: the value a row shows has exactly one provenance,
 * a (layer, condition) pair: the style stack's winner at the pose
 * (style-stack.js stackAt: the topmost layer whose block reaches the
 * pose through the condition cascade) and the condition of that
 * delivering block. Reading the layer at the delivering condition,
 * never the posed one, is what keeps the WHO when a value crosses a
 * condition (a variant setting padding at Base, viewed at Medium, is
 * still the variant's).
 *
 * The defaults tier has two suppliers and one hue: the active skin's
 * defaults (data, the skin session is their doorway) over the declaration's
 * own defaults (code: a block type, block plugin, or layout shipping the
 * box facts its markup needs; one inert doorway). Both render lit in
 * the skin hue (the display rule: the shown value is the winner's); the
 * origin sentence names which one supplies, and the delivering
 * condition when it is not the pose. The tier's layers are read through
 * the same cascade as every other layer (stackAt over each layer's
 * styles): the printer emits both tiers by condition order, so a skin
 * or declared `default` block delivers at every pose and the lens must
 * say so at every pose.
 *
 * An ancestor's rule reaching the element (an `ancestor:{owner}::
 * {selector}` layer: a container's `> pb-text` color landing on this
 * text block, the row variant's `> *` fill on this column) renders like
 * the floor: lit in the floor hue, the origin sentence naming the
 * ancestor and its rule, and one doorway that edits AT the rule: it
 * makes `{owner, selector}` the panel's styling target (write-address.js)
 * while the selection stays where it is.
 *
 * A variant an ancestor's rule WEARS, reaching the element (a worn
 * ancestor layer, `ancestor:{owner}::{position}|{variant}`, style-stack.js
 * ancestorLayers; the tier keeps every "this element wears" reader
 * honest, the identity is read through layers.js ancestorVariant)
 * renders as a variant: lit in the variant's hue with the variant as
 * the mark's reference, the origin sentence naming the variant and the
 * rule it arrives through in the switcher's words ("Button at Text
 * blocks in the section"; the layer's `carrier`, never the id's
 * position: a value reaching a part through `pb-text .text-block-link`
 * is worn by `pb-text`), and the doorways: open the variant (the
 * session anchored at the carrier, where the variant is worn), edit at
 * the carrier (the rule as the styling target), drive when the
 * delivering condition is not the pose. A carrier with no selector is
 * the owner's own root wearing a variant whose sub-entry reaches the
 * position (a container look's `::> pb-text` block): no rule to seat,
 * the sentence names the owner, and opening the variant selects the
 * owner (the wearer) the way the library card's Edit styles does.
 *
 * The origin has two independent axes: WHO supplies the shown value
 * (`layer`, `tier`, `reference`, `color`: the supplying layer's identity
 * and hue) and WHETHER the supplying block is AT the pose (`atPose`) or
 * cascades in from another condition. The pose is a filter the editor
 * set on purpose, so "set here, at this pose" is the signal: the row's
 * mark fills when `atPose`, wears the hue hollow when the value crosses
 * a condition, and rests faint only when nothing authored exists for
 * the property at all. A property nobody delivers at the pose but some
 * layer authors elsewhere (a hover-only value while posed at Base)
 * keeps that layer's hue with no winner (`delivers` false), so the row
 * reads "authored elsewhere, not here" without a fourth glyph.
 *
 * The origin renders as ONE mark in the row's leading slot and as ONE
 * right-click menu on the row: the origin in words as the header (every
 * origin has a sentence, "Not set" included), the doorways (drive the
 * canvas, open the variant, unset, edit the skin, go to the winner), the
 * other conditions carrying a value, then the token items the dimension
 * field used to open on its own. Everything here is data-in/data-out for
 * node-test backpressure; the row binds handlers.
 */

/**
 * A condition key as display vocabulary: 'default' is Base, singles wear
 * their site label, compound parts join with ' + ' ("Narrow + Dark").
 */
export const conditionText = (name, conditions) =>
  name === 'default' ? Drupal.t('Base') : compoundLabel(name, conditions);

/**
 * A declaring scope as display vocabulary: the captured scope in words
 * ("image block type", "system_menu_block block plugin", "layout_onecol
 * layout", "container kind"), the name the origin sentences give a
 * declared default's supplier. Declarations carry no human label (the
 * guide serves them by machine name, as the validator and the AI
 * explain name them), so the scope itself is the honest name. A kind
 * names itself for the elements whose scope IS the kind (the entity, an
 * unrefined section, an unresolved bundle): under the ownership law a
 * kind declares defaults for the markup it renders (a composed kind's
 * root, its declared parts). A refined element names its refinement,
 * whose effective declaration carries the kind's defaults merged
 * beneath its own (the stack carries them as two declared layers, the
 * kind's beneath the refinement's, and a sentence names the delivering
 * layer's own scope). Null for a base tag or an unparsed scope.
 */
export const declarationLabel = (scope) => {
  const pos = typeof scope === 'string' ? scope.indexOf(':') : -1;
  if (pos === -1) {
    return null;
  }
  const id = scope.slice(pos + 1);
  switch (scope.slice(0, pos)) {
    case 'kind':
      return Drupal.t('!id kind', { '!id': id });
    case 'block_type':
      return Drupal.t('!id block type', { '!id': id });
    case 'block_plugin':
      return Drupal.t('!id block plugin', { '!id': id });
    case 'layout':
      return Drupal.t('!id layout', { '!id': id });
    default:
      return null;
  }
};

/**
 * A skin document's scope in words: the base tag's word for a base:{tag}
 * scope (style-guide.js tagWord: the guide's tagLabels, "Heading 2"),
 * else the declaring scope in words (declarationLabel, "text block
 * type"), the scope itself when neither parses.
 *
 * The one name the skin session speaks for its document: the doorway's
 * hint, the session header's label, the push bar's name and the leave
 * prompt read here, so the document never has two names on one panel.
 *
 * @param {?string} scope
 *   The declaring scope ("base:h2", "block_type:text").
 * @param {?object} [guide]
 *   The style guide payload, for the tag words.
 *
 * @return {string}
 */
export const skinScopeLabel = (scope, guide = null) => {
  if (typeof scope !== 'string') {
    return '';
  }
  if (scope.startsWith('base:')) {
    return tagWord(guide, scope.slice('base:'.length));
  }
  return declarationLabel(scope) ?? scope;
};

const driveDoorway = (condition, conditions) => ({
  id: 'drive',
  label: Drupal.t('Go to !condition', {
    '!condition': conditionText(condition, conditions),
  }),
  hint: Drupal.t('drives the canvas'),
  condition,
});

// The variant doorway: open the reference's editing session, driving
// the canvas to the delivering condition first. `anchor` names the
// wearer the session anchors at when it is not the row's own target (a
// worn ancestor value: the carrier rule, or the owner wearing it at its
// root); null leaves the host's wear key as the anchor.
const documentDoorway = (reference, label, condition, conditions, anchor = null) => ({
  id: 'document',
  label: Drupal.t('Edit !label at !condition…', {
    '!label': label,
    '!condition': conditionText(condition, conditions),
  }),
  hint: Drupal.t('opens the variant'),
  reference,
  condition,
  anchor,
});

// The skin doorway: open the editing session on the active skin's
// document at the delivering scope (code/layers.js skinDocument: the
// scope's skin layer id is the document's reference; use-row-menu.js
// hands it to the one openDocument the variant doorway takes), driving
// the canvas to the delivering condition first, as the variant doorway
// does. The scope is the layer's (a base:{tag} block, the family kind's,
// the refinement's), so a value the kind block supplies edits the kind
// block, never the refinement over it.
const skinDoorway = (scope, condition, conditions, guide) => [
  {
    id: 'skin',
    label: Drupal.t('Edit skin…'),
    hint: Drupal.t("opens the skin's !scope default", {
      '!scope': skinScopeLabel(scope, guide),
    }),
    scope,
    condition,
  },
];

// The declared tier's doorway: a declaration is code beside its
// templates and nothing on the site edits it, so the doorway SHOWS it,
// the Materials place's Law tab at the declaring scope with the
// property's rows revealed (law.js, law-model.js). It rides the one
// openDocument road the skin doorway rides, as a law document reference
// (code/layers.js lawDocument: the declared layer id with the property
// as its fragment; use-row-menu.js dispatches it, the Inspector's
// openDocument routes it to the tab before any session logic).
const declaredDoorway = (scope, property, declaration) => ({
  id: 'declared',
  label: Drupal.t('See the declaration…'),
  hint: Drupal.t('opens the Law tab at the !declaration declaration', {
    '!declaration': declaration,
  }),
  scope,
  property,
});

/**
 * An ancestor layer's owner for the sentences: the owner's name the
 * layer carries (`owner`, the tree node's minted `_element.name`, set on
 * the own and the worn ancestor layer alike by style-stack.js; a worn
 * layer's `label` is the variant's), else a generic word when the tree
 * carried no node for the owner.
 */
export const ancestorName = (layer) =>
  layer?.owner ?? Drupal.t('the parent element');

// The ancestor's doorway: edit AT a rule (the owner key with the
// selector), by making it the panel's write address (the spine's
// `address`, write-address.js) while the selection and the subject stay
// put: the rows keep reading this element and their writes land on that
// rule. For a rule's own value the rule is
// the layer's position; for a value a rule's worn variant delivers it
// is the CARRIER, the rule that wears the variant (where the wear bar
// shows it), never the position the value lands at. The subject
// line's address chip remains the road to the ancestor itself. `label` is the rule in
// words, already built through Drupal.t (the switcher's address label,
// or the owner with its selector), so it rides an unescaped
// placeholder: the menu renders labels as text, and `@` would show a
// `>` as its entity or double-escape an owner's name. `preview` is the
// pair the canvas paints while the entry is the menu's active item
// (rowMenu hands it to onPreview): the rule's reach, seen before the
// pick, the same preview the address chip's entries give.
const ancestorDoorway = (rule, label) => ({
  id: 'ancestor',
  label: Drupal.t('Edit at !rule', { '!rule': label }),
  hint: Drupal.t('writes land on that rule; the selection stays'),
  key: rule.key,
  selector: rule.selector,
  preview:
    typeof rule.key === 'string' && typeof rule.selector === 'string'
      ? { key: rule.key, selector: rule.selector }
      : null,
});

// An own ancestor layer's doorway: the rule at the layer's position.
const ownRuleDoorway = (layer) => {
  const rule = ancestorRule(layer?.id ?? '') ?? { key: null, selector: null };
  return ancestorDoorway(
    rule,
    Drupal.t('!owner (!selector)', {
      '!owner': ancestorName(layer),
      '!selector': rule.selector ?? '',
    }),
  );
};

// The element's doorway under a write address: the element's own block
// sits ABOVE the address's editable layer (style-stack.js: the rule's
// ancestor layer takes the editable mark while the selection stays), so
// a value the element itself styles wins over anything the rule says,
// and the way to edit it is to drop the address (address(null): the
// subject stays, writes land at its own key again). `key` is the
// stack's own key, the selected element.
const elementDoorway = (key) => ({
  id: 'element',
  label: Drupal.t('Edit on the element'),
  hint: Drupal.t('drops the address; writes land on the element'),
  key,
});

/**
 * A layer's name in a sentence that names its supplier without a
 * value's doorway story (the "not here" sentence, the family row's
 * split), in the words the delivering sentences use for the same
 * layer: a variant's label, a worn ancestor's variant, an own ancestor
 * rule as "!owner (!selector)", "the active skin", "the !declaration
 * declaration", "this element".
 *
 * @param {object} layer
 *   A stack layer (id, tier, label, variant, scope).
 *
 * @return {string}
 */
export const supplierName = (layer) => {
  switch (layer.tier) {
    case TIER_VARIANT:
      return layer.label ?? layer.variant ?? layer.id;
    case TIER_ANCESTOR:
      return ancestorVariant(layer.id) !== null
        ? (layer.label ?? ancestorVariant(layer.id))
        : Drupal.t('!owner (!selector)', {
            '!owner': ancestorName(layer),
            '!selector': ancestorRule(layer.id)?.selector ?? '',
          });
    case TIER_SKIN:
      return Drupal.t('the active skin');
    case TIER_DECLARED:
      return Drupal.t('the !declaration declaration', {
        '!declaration': declarationLabel(layer.scope ?? null) ?? Drupal.t('element'),
      });
    default:
      return Drupal.t('this element');
  }
};

/**
 * A property row's origin from a stack read: who delivers the shown
 * value, and where.
 *
 * The projection of stackAt() (style-stack.js) into the row's
 * provenance: the shown value is always the winner's, lit; the
 * identity, hue, sentence and doorways come from the winner's layer
 * against the editable one. The two axes, the sentences, the doorways
 * and the "Also set at" roster are read off ONE model, never off side
 * tables each surface reconstructs a winner from.
 *
 * The identity axis (`layer`, `tier`, `reference`, `color`) is the
 * WINNER's, whatever the sentence tells: an own value beating a layer
 * beneath is the element's (the beaten story lives in the sentence and
 * the Unset doorway), a document value in a session is the variant's.
 * With no winner it is the topmost layer authoring the property at ANY
 * condition (a hover-only block while posed at Base), and null-hued
 * only when no layer authors the property at all.
 *
 * The pose axis (`atPose`) is whether the winner's delivering block is
 * the posed compound; `delivers` whether a winner exists. The sentences
 * follow the same two facts:
 * - set here: the editable layer delivers at the pose with nothing
 *   beneath ("!value set here at !condition", "!value set here" at
 *   Base).
 * - inherited: another layer's or another condition's value shows:
 *   the editable layer's own block from another condition (the drive
 *   doorway), a worn variant beneath (open the variant there, drive when
 *   the condition differs), an ancestor's rule (the doorway selects the
 *   rule), the active skin's block (the skin session), a
 *   declaration's block (the inert doorway), or a layer ABOVE the
 *   editable one: in a session the wearer's own entry or a higher
 *   reference, with the winner doorway to where that value lives; under
 *   a write address the element's own block, with the element doorway
 *   (drop the target: the element is edited again).
 * - overriding: the editable layer at the pose beats `beneath[0]`: the
 *   sentence names the beaten value, Unset returns it, plus the beaten
 *   layer's own doorway.
 * - overridden: the editable layer says something here (its block, a
 *   draft, a pending line: `staged`) and a higher layer wins: the
 *   winner doorway (a session) or the element doorway (a write address
 *   the element's own value beats).
 * - not here: nothing delivers at the pose but a layer authors the
 *   property at other conditions ("Set by !label at !condition, not
 *   here"; "Set at !condition, not here" for the editable layer's own).
 * - not set: no layer authors the property anywhere ("Not set").
 *
 * @param {{winner: ?object, beneath: Array<object>, own: ?object}} read
 *   stackAt(stack, property, pose, conditions).
 * @param {object} input
 * @param {object} input.stack
 *   The buildStack() result the read came from: the editable layer, the
 *   layers' labels and scopes, and the "Also set at" roster read off it.
 * @param {string} input.property
 *   The row's property.
 * @param {string} input.pose
 *   The editing compound the read was made at.
 * @param {object} [input.conditions]
 *   The condition table (guide.conditions), for display vocabulary.
 * @param {?object} [input.guide]
 *   The style guide payload, for the words of a rule an ancestor's
 *   worn variant arrives through (write-address.js addressLabel: "Text
 *   blocks in the section"); without it the rule reads as its selector.
 * @param {object} [input.blockTypes]
 *   component uuid → bundle, for the same words.
 * @param {object} [input.sectionLayouts]
 *   section uuid → layout id, for the same words.
 * @param {Function} [input.faceOf]
 *   value → its display face (the field's own ref→display read at the
 *   pose); the sentences and the roster speak faces, never var()
 *   spellings. Identity by default.
 * @param {boolean} [input.staged]
 *   Whether the editable layer says something here beyond its stored
 *   block (a draft being typed, a pending line): a higher layer then
 *   reads as overriding it even while the block itself is empty.
 *
 * @return {{layer: string, tier: string, reference: ?string, color: ?string, atPose: boolean, delivers: boolean, title: string, header: string, doorways: Array<object>, alsoAt: Array<object>, alsoOn: ?{label: string, value: string}}}
 *   The origin: `layer` the supplying layer's id (the winner's; the
 *   topmost authoring layer's with no winner; the editable layer's when
 *   nothing is authored), `tier` its tier, `reference` a variant
 *   supplier's reference (an own wearing's, or the variant an ancestor's
 *   rule wears), `color` the supplier's hue (null only when nothing is
 *   authored), `atPose` whether the winner's block is the posed
 *   compound, `delivers` whether a winner exists, `header` the origin
 *   sentence (the menu's header, never empty), `title` the mark's hover
 *   text (the sentence with "Also set at …" appended from `alsoAt`),
 *   `doorways` {id, label, hint} plus the datum the row's handler needs
 *   (condition, reference, url, winner), `alsoAt` every OTHER stored
 *   condition the stack delivers the property at ({condition, label,
 *   value}), the winner's delivering condition excluded, `alsoOn` the
 *   address rule's second authored value on another of the binding's
 *   boxes ({label, value}: the Button's link background over a root
 *   band, read from `read.alsoOn`, controls.js resolveCandidates), null
 *   when there is none or the read carries no address fact.
 */
export const originOf = (
  read,
  {
    stack,
    property,
    pose,
    conditions = {},
    guide = null,
    blockTypes = {},
    sectionLayouts = {},
    faceOf = (value) => value,
    staged = false,
  },
) => {
  const editable = editableLayer(stack);
  const editableId = layerAt(stack, editable)?.id ?? ELEMENT_LAYER;
  // What made the layer editable: a session (the document layer, a
  // variant reference's or the skin's at a scope) or a write address (an
  // ancestor rule's layer); the element's own layer otherwise.
  const editableTier = layerAt(stack, editable)?.tier ?? null;
  const session = editableTier === TIER_VARIANT || editableTier === TIER_SKIN;
  const { winner, beneath, own } = read;
  const alsoAt = propertyValues(deliveredEntry(stack), property, conditions)
    .filter((entry) => entry.condition !== winner?.condition)
    .map((entry) => ({
      condition: entry.condition,
      label: conditionText(entry.condition, conditions),
      value: faceOf(entry.value),
    }));
  const labelOf = (entry) =>
    layerAt(stack, entry.layer)?.label ?? entry.variant ?? entry.id;
  const declarationOf = (entry) =>
    declarationLabel(layerAt(stack, entry.layer)?.scope ?? null) ??
    Drupal.t('element');
  // A sentence's "at !condition" tail: the delivering condition when it
  // differs from the pose, nothing when it is the pose.
  const at = (entry) =>
    entry.condition !== pose
      ? { '!condition': conditionText(entry.condition, conditions) }
      : null;
  // The identity axis of a layer (a read entry or a stack layer: both
  // carry id, tier and variant). The mark reads as a variant for an own
  // wearing and for a variant an ancestor's rule wears alike (the
  // reference and the hue are the variant's); an own ancestor value,
  // the floor and the element carry no reference.
  const identity = (entry) => ({
    layer: entry.id,
    tier: entry.tier,
    reference:
      entry.tier === TIER_VARIANT ? entry.variant : ancestorVariant(entry.id),
    color: layerColor(entry.id),
  });
  const hued = (entry, sentence, doorways) => ({
    ...identity(entry),
    title: sentence,
    header: sentence,
    doorways,
  });
  // The editable layer delivers at the pose and nothing beneath it tells
  // a story: the value is set here. Base needs no "at".
  const setHere = (entry) =>
    hued(
      entry,
      pose !== 'default'
        ? Drupal.t('!value set here at !condition', {
            '!value': faceOf(entry.value),
            '!condition': conditionText(pose, conditions),
          })
        : Drupal.t('!value set here', { '!value': faceOf(entry.value) }),
      [],
    );
  // Nothing delivers at the pose. The topmost layer authoring the
  // property at any condition (the layers run bottom to top) lends its
  // identity and names its conditions ("not here"); with no author
  // anywhere the origin is the editable layer's, hueless, "Not set".
  const unset = () => {
    for (let index = stack.layers.length - 1; index >= 0; index -= 1) {
      const layer = stack.layers[index];
      const named = propertyValues(layer.styles, property, conditions).map(
        (entry) => entry.condition,
      );
      if (named.length) {
        const args = {
          '!condition': named
            .map((condition) => conditionText(condition, conditions))
            .join(', '),
        };
        const sentence =
          index === editable
            ? Drupal.t('Set at !condition, not here', args)
            : Drupal.t('Set by !label at !condition, not here', {
                ...args,
                '!label': supplierName(layer),
              });
        return { ...hued(layer, sentence, []), named };
      }
    }
    const sentence = Drupal.t('Not set');
    return {
      layer: editableId,
      tier: layerAt(stack, editable)?.tier ?? TIER_ELEMENT,
      reference: null,
      color: null,
      title: sentence,
      header: sentence,
      doorways: [],
    };
  };
  // A worn ancestor layer's identity for the sentences and doorways:
  // the variant (its label is the layer's own), the owner named from
  // the group's own layer (the next layer up at the same position,
  // whose label is the owner's), the carrier read from the layer's
  // `carrier` field, and the carrier rule in the switcher's words
  // (null for a root carrier: the owner wears it at its root). Null for
  // any other layer.
  const wornOf = (entry) => {
    const variant = ancestorVariant(entry.id);
    if (variant === null) {
      return null;
    }
    const layer = layerAt(stack, entry.layer);
    const position = ancestorRule(entry.id);
    const ownLayer =
      stack.layers.find(
        (candidate) => candidate.id === ancestorLayerId(position.key, position.selector),
      ) ?? layer;
    const owner = ancestorName(ownLayer);
    const carrier = layer?.carrier ?? null;
    const rule =
      carrier !== null && carrier.selector !== null
        ? { key: carrier.key, selector: carrier.selector }
        : null;
    return {
      variant,
      label: layer?.label ?? variant,
      owner,
      anchor: carrier === null ? null : addressKey(carrier),
      rule,
      words: rule === null ? null : addressLabel(guide, rule, owner, blockTypes, sectionLayouts),
    };
  };
  // A worn ancestor origin's doorways: open the variant at its carrier,
  // edit at the carrier rule when there is one, then whatever follows.
  const wornDoorways = (worn, condition, rest = []) => [
    documentDoorway(worn.variant, worn.label, condition, conditions, worn.anchor),
    ...(worn.rule === null ? [] : [ancestorDoorway(worn.rule, worn.words)]),
    ...rest,
  ];
  const origin = (() => {
    if (!winner) {
      return unset();
    }
    const value = faceOf(winner.value);
    // A layer above the editable one exists in two states. In a session
    // the document layer is editable and the wearer's own entry or a
    // higher reference beats it: the winner doorway navigates to where
    // that value is edited (the sheet's). Under a write address the
    // rule's ancestor layer is editable and the element's own block sits
    // above it: the element doorway drops the address, since the element
    // is where that value is edited. Any other layer above an address's
    // (a nearer ancestor's rule, a variant the element wears) reads
    // through the tier switch below like every other delivering layer.
    if (!session && winner.tier === TIER_ELEMENT && winner.layer !== editable) {
      const doorway = elementDoorway(stack.key);
      if (own || staged) {
        return hued(
          winner,
          Drupal.t('Overridden by this element'),
          [doorway],
        );
      }
      const tail = at(winner);
      const sentence = tail
        ? Drupal.t('!value from this element at !condition', {
            '!value': value,
            ...tail,
          })
        : Drupal.t('!value from this element', { '!value': value });
      return hued(winner, sentence, [
        doorway,
        ...(tail ? [driveDoorway(winner.condition, conditions)] : []),
      ]);
    }
    if (winner.layer > editable && session) {
      const label = winner.tier === TIER_VARIANT ? labelOf(winner) : null;
      const doorway = {
        id: 'winner',
        label: label
          ? Drupal.t('Edit !label…', { '!label': label })
          : Drupal.t('Go to element'),
        hint: label
          ? Drupal.t('opens the variant')
          : Drupal.t('where the winning value lives'),
        winner: { variant: winner.tier === TIER_VARIANT ? winner.variant : null },
      };
      if (own || staged) {
        const sentence = label
          ? Drupal.t('Overridden by !label on this page', { '!label': label })
          : Drupal.t('Overridden by this element on this page');
        return hued(winner, sentence, [doorway]);
      }
      const tail = at(winner);
      const sentence = label
        ? tail
          ? Drupal.t('!value from !label at !condition', {
              '!value': value,
              '!label': label,
              ...tail,
            })
          : Drupal.t('!value from !label', { '!value': value, '!label': label })
        : tail
          ? Drupal.t('!value from this element at !condition', {
              '!value': value,
              ...tail,
            })
          : Drupal.t('!value from this element', { '!value': value });
      return hued(winner, sentence, [doorway]);
    }
    if (winner.layer !== editable) {
      // A layer beneath the editable one delivers: inherited from it.
      const tail = at(winner);
      switch (winner.tier) {
        case TIER_VARIANT: {
          const label = labelOf(winner);
          const sentence = tail
            ? Drupal.t('!value from !label at !condition', {
                '!value': value,
                '!label': label,
                ...tail,
              })
            : Drupal.t('!value from !label', { '!value': value, '!label': label });
          return hued(winner, sentence, [
            documentDoorway(winner.variant, label, winner.condition, conditions),
            ...(tail ? [driveDoorway(winner.condition, conditions)] : []),
          ]);
        }
        case TIER_ANCESTOR: {
          const worn = wornOf(winner);
          if (worn !== null) {
            // A variant the rule wears delivers: named as the variant,
            // at the rule in the switcher's words, or worn by the owner
            // at its root.
            const args = { '!value': value, '!label': worn.label };
            const sentence =
              worn.rule !== null
                ? tail
                  ? Drupal.t('!value from !label at !rule, set at !condition', {
                      ...args,
                      '!rule': worn.words,
                      ...tail,
                    })
                  : Drupal.t('!value from !label at !rule', {
                      ...args,
                      '!rule': worn.words,
                    })
                : tail
                  ? Drupal.t('!value from !label worn by !owner, set at !condition', {
                      ...args,
                      '!owner': worn.owner,
                      ...tail,
                    })
                  : Drupal.t('!value from !label worn by !owner', {
                      ...args,
                      '!owner': worn.owner,
                    });
            return hued(
              winner,
              sentence,
              wornDoorways(
                worn,
                winner.condition,
                tail ? [driveDoorway(winner.condition, conditions)] : [],
              ),
            );
          }
          const layer = layerAt(stack, winner.layer);
          const rule = ancestorRule(winner.id) ?? { selector: '' };
          // The selector rides unescaped, as the doorway's does: the
          // sentence is a title attribute (text), and `@` would show a
          // `>` as its entity.
          const args = {
            '!value': value,
            '!owner': ancestorName(layer),
            '!selector': rule.selector,
          };
          const sentence = tail
            ? Drupal.t(
                '!value inherited from !owner (!selector) at !condition',
                { ...args, ...tail },
              )
            : Drupal.t('!value inherited from !owner (!selector)', args);
          return hued(winner, sentence, [
            ownRuleDoorway(layer),
            ...(tail ? [driveDoorway(winner.condition, conditions)] : []),
          ]);
        }
        case TIER_SKIN: {
          const sentence = tail
            ? Drupal.t('!value from the active skin at !condition', {
                '!value': value,
                ...tail,
              })
            : Drupal.t('!value from the active skin', { '!value': value });
          return hued(
            winner,
            sentence,
            skinDoorway(
              layerAt(stack, winner.layer)?.scope ?? null,
              winner.condition,
              conditions,
              guide,
            ),
          );
        }
        case TIER_DECLARED: {
          const declaration = declarationOf(winner);
          const sentence = tail
            ? Drupal.t('!value from the !declaration declaration at !condition', {
                '!value': value,
                '!declaration': declaration,
                ...tail,
              })
            : Drupal.t('!value from the !declaration declaration', {
                '!value': value,
                '!declaration': declaration,
              });
          return hued(winner, sentence, [
            declaredDoorway(
              layerAt(stack, winner.layer)?.scope ?? null,
              property,
              declaration,
            ),
          ]);
        }
        default: {
          // An element-tier layer beneath the editable one (no layer
          // editable at all): the element's value, named as such.
          const sentence = tail
            ? Drupal.t('!value from this element at !condition', {
                '!value': value,
                ...tail,
              })
            : Drupal.t('!value from this element', { '!value': value });
          return hued(
            winner,
            sentence,
            tail ? [driveDoorway(winner.condition, conditions)] : [],
          );
        }
      }
    }
    // The editable layer delivers.
    if (winner.condition !== pose) {
      const sentence = Drupal.t('!value from !condition', {
        '!value': value,
        '!condition': conditionText(winner.condition, conditions),
      });
      return hued(winner, sentence, [
        driveDoorway(winner.condition, conditions),
      ]);
    }
    const beaten = beneath[0] ?? null;
    if (!beaten) {
      return setHere(winner);
    }
    const beneathValue = faceOf(beaten.value);
    const tail = at(beaten);
    switch (beaten.tier) {
      case TIER_VARIANT: {
        const label = labelOf(beaten);
        const sentence = Drupal.t("Your !value overrides !label's !beneath", {
          '!value': value,
          '!label': label,
          '!beneath': beneathValue,
        });
        return hued(winner, sentence, [
          {
            id: 'unset',
            label: Drupal.t('Unset'),
            hint: Drupal.t("!label's !beneath returns", {
              '!label': label,
              '!beneath': beneathValue,
            }),
          },
          documentDoorway(beaten.variant, label, beaten.condition, conditions),
        ]);
      }
      case TIER_ANCESTOR: {
        const worn = wornOf(beaten);
        if (worn !== null) {
          const args = {
            '!value': value,
            '!label': worn.label,
            '!beneath': beneathValue,
          };
          const sentence =
            worn.rule !== null
              ? tail
                ? Drupal.t(
                    "Your !value overrides !label's !beneath at !rule, set at !condition",
                    { ...args, '!rule': worn.words, ...tail },
                  )
                : Drupal.t("Your !value overrides !label's !beneath at !rule", {
                    ...args,
                    '!rule': worn.words,
                  })
              : tail
                ? Drupal.t(
                    "Your !value overrides !label's !beneath worn by !owner, set at !condition",
                    { ...args, '!owner': worn.owner, ...tail },
                  )
                : Drupal.t("Your !value overrides !label's !beneath worn by !owner", {
                    ...args,
                    '!owner': worn.owner,
                  });
          return hued(winner, sentence, [
            {
              id: 'unset',
              label: Drupal.t('Unset'),
              hint: Drupal.t("!label's !beneath returns", {
                '!label': worn.label,
                '!beneath': beneathValue,
              }),
            },
            ...wornDoorways(worn, beaten.condition),
          ]);
        }
        const layer = layerAt(stack, beaten.layer);
        const rule = ancestorRule(beaten.id) ?? { selector: '' };
        const args = {
          '!value': value,
          '!owner': ancestorName(layer),
          '!selector': rule.selector,
          '!beneath': beneathValue,
        };
        const sentence = tail
          ? Drupal.t(
              "Your !value overrides !owner's !beneath (!selector) at !condition",
              { ...args, ...tail },
            )
          : Drupal.t("Your !value overrides !owner's !beneath (!selector)", args);
        return hued(winner, sentence, [
          {
            id: 'unset',
            label: Drupal.t('Unset'),
            hint: Drupal.t("!owner's !beneath returns", {
              '!owner': ancestorName(layer),
              '!beneath': beneathValue,
            }),
          },
          ownRuleDoorway(layer),
        ]);
      }
      case TIER_SKIN: {
        const sentence = tail
          ? Drupal.t(
              "Your !value overrides the active skin's !skin at !condition",
              { '!value': value, '!skin': beneathValue, ...tail },
            )
          : Drupal.t("Your !value overrides the active skin's !skin", {
              '!value': value,
              '!skin': beneathValue,
            });
        return hued(winner, sentence, [
          {
            id: 'unset',
            label: Drupal.t('Unset'),
            hint: Drupal.t("the skin's !skin returns", { '!skin': beneathValue }),
          },
          ...skinDoorway(
            layerAt(stack, beaten.layer)?.scope ?? null,
            beaten.condition,
            conditions,
            guide,
          ),
        ]);
      }
      case TIER_DECLARED: {
        const declaration = declarationOf(beaten);
        const sentence = tail
          ? Drupal.t(
              "Your !value overrides the !declaration declaration's !declared at !condition",
              {
                '!value': value,
                '!declaration': declaration,
                '!declared': beneathValue,
                ...tail,
              },
            )
          : Drupal.t(
              "Your !value overrides the !declaration declaration's !declared",
              {
                '!value': value,
                '!declaration': declaration,
                '!declared': beneathValue,
              },
            );
        return hued(winner, sentence, [
          {
            id: 'unset',
            label: Drupal.t('Unset'),
            hint: Drupal.t("the !declaration declaration's !declared returns", {
              '!declaration': declaration,
              '!declared': beneathValue,
            }),
          },
        ]);
      }
      default: {
        // The wearer's own entry beneath a session document at the same
        // pose cannot happen (the own layer sits above); a same-tier
        // layer beneath (a composition pair's other half) reads as the
        // document's own value.
        return setHere(winner);
      }
    }
  })();
  // The pose axis over the identity the branch chose, and the title:
  // the sentence with the roster appended, minus the conditions the
  // "not here" sentence already named.
  const { named = [], ...base } = origin;
  const also = alsoAt.filter((entry) => !named.includes(entry.condition));
  return {
    layer: base.layer,
    tier: base.tier,
    reference: base.reference,
    color: base.color,
    atPose: winner !== null && winner.condition === pose,
    delivers: winner !== null,
    title: also.length
      ? Drupal.t('!sentence. Also set at !conditions', {
          '!sentence': base.title,
          '!conditions': also.map((entry) => entry.label).join(', '),
        })
      : base.title,
    header: base.header,
    doorways: base.doorways,
    alsoAt,
    alsoOn:
      read.alsoOn == null
        ? null
        : { label: read.alsoOn.label, value: faceOf(read.alsoOn.value) },
  };
};

/**
 * The family row's sentence (the padding row's mark title and menu
 * header) over box-model.js sidesOrigin's facts, in the property rows'
 * vocabulary: one group reads as any row would ("!value set here at
 * !condition", "!value from !condition", "!value from !label at
 * !condition"; the value is the field's uniform face, or the sentence
 * opens on the story when the sides differ), a split names each group's
 * sides and story and points at the diagram ("Top, bottom set here at
 * Hover; left, right from Base. Open the diagram for each side"), no set
 * side reads "Set at !condition, not here" / "Set by !label at
 * !condition, not here" for the topmost author, or "Not set".
 *
 * @param {object} origin
 *   sidesOrigin()'s facts.
 * @param {object} input
 * @param {string} input.pose
 *   The editing compound.
 * @param {object} [input.conditions]
 *   The condition table (guide.conditions).
 * @param {?string} [input.value]
 *   The row's uniform value face, null when the sides differ.
 *
 * @return {string}
 */
export const sidesSentence = (origin, { pose, conditions = {}, value = null }) => {
  const posed = { '!condition': conditionText(pose, conditions) };
  // One group's story, lowercase: it follows a value or a side list.
  const story = (group) => {
    const tail =
      group.condition !== pose
        ? { '!condition': conditionText(group.condition, conditions) }
        : null;
    if (group.state === 'authored') {
      if (group.atPose) {
        return pose !== 'default'
          ? Drupal.t('set here at !condition', posed)
          : Drupal.t('set here');
      }
      return Drupal.t('from !condition', tail);
    }
    const label = { '!label': supplierName(group.supplier) };
    return tail
      ? Drupal.t('from !label at !condition', { ...label, ...tail })
      : Drupal.t('from !label', label);
  };
  const capitalize = (text) => text.charAt(0).toUpperCase() + text.slice(1);
  if (!origin.delivers) {
    if (origin.supplier === null) {
      return Drupal.t('Not set');
    }
    const named = {
      '!condition': origin.named
        .map((condition) => conditionText(condition, conditions))
        .join(', '),
    };
    return origin.supplier.tier === ELEMENT_LAYER
      ? Drupal.t('Set at !condition, not here', named)
      : Drupal.t('Set by !label at !condition, not here', {
          ...named,
          '!label': supplierName(origin.supplier),
        });
  }
  if (origin.groups.length === 1) {
    const words = story(origin.groups[0]);
    return value !== null
      ? Drupal.t('!value !story', { '!value': value, '!story': words })
      : capitalize(words);
  }
  const sideWords = {
    top: Drupal.t('top'),
    right: Drupal.t('right'),
    bottom: Drupal.t('bottom'),
    left: Drupal.t('left'),
  };
  const split = origin.groups
    .map(
      (group) =>
        `${group.sides.map((side) => sideWords[side]).join(', ')} ${story(group)}`,
    )
    .join('; ');
  return Drupal.t('!split. Open the diagram for each side', {
    '!split': capitalize(split),
  });
};

/**
 * A token item's tooltip: the reference's condition-keyed resolutions,
 * so twins colliding here show where they honestly separate ("Dark:
 * 1.25rem"). Undefined when the token is one value everywhere.
 */
const resolutionTitle = (guide, materials, ref, condition) => {
  const conditions = guide?.conditions ?? {};
  const base = displayAt(guide, materials, ref, condition);
  const rows = Object.keys(conditions)
    .map((name) => ({
      label: conditions[name]?.label ?? name,
      value: displayAt(guide, materials, ref, name),
    }))
    .filter((row) => row.value !== null && row.value !== base);
  return rows.length
    ? rows.map((row) => `${row.label}: ${row.value}`).join(' · ')
    : undefined;
};

const tokenLabel = (guide, token) =>
  token.scale === null
    ? token.name
    : `${guide?.tokenScales?.[token.scale]?.label ?? token.scale} · ${token.name}`;

/**
 * The token half of provenance: the shown value's collision set (checked
 * on the worn token) and, where the space leaves the literal legal
 * (`escape`), the explicit "Custom value" detach.
 *
 * Offered only while the face sits on a stop or wears a token: a closed
 * space's stop is the worn one or the value-match (a scrub preview
 * echoing back, a legacy literal); an open detented space's the worn one
 * or the value-equal stop, a blessed-but-stopless reference still
 * offering the escape. A free space has no provenance to steer.
 *
 * @param {?object} guide
 * @param {?object} materials
 * @param {string} condition
 *   The editing compound (reference displays resolve here).
 * @param {?object} space
 *   The property's value space (value-space.js valueSpaceFor).
 * @param {string} value
 *   The shown value (the committed spelling or the draft echo).
 * @param {object} options
 * @param {boolean} [options.escape]
 *   Whether "Custom value" is offered; defaults to the detented kind.
 * @param {Function} options.onPreview
 *   (text) — paint a display value.
 * @param {Function} options.onSettle
 *   (text) — commit a spelling (a reference on a token pick, the face
 *   on the escape).
 *
 * @return {Array<object>}
 *   Menu items ({label, hint, title, checked, run}, a separator before
 *   the escape), [] when there is nothing to steer.
 */
export const tokenItems = (
  guide,
  materials,
  condition,
  space,
  value,
  { escape = space?.kind === 'detented', onPreview, onSettle } = {},
) => {
  if (!space || space.kind === 'free') {
    return [];
  }
  const { face, token, stop } = wearing(
    guide,
    materials,
    space,
    value,
    condition,
  );
  const anchor =
    space.kind === 'closed'
      ? (stop ?? magneticCapture(space.stops, face)?.stop ?? null)
      : (stop ??
        space.stops.find((candidate) => valuesEqual(candidate.value, face)) ??
        null);
  if (
    space.kind === 'closed'
      ? !anchor?.tokens?.length
      : anchor === null && token === null
  ) {
    return [];
  }
  const items = (anchor?.tokens ?? []).map((candidate) => ({
    label: tokenLabel(guide, candidate),
    hint: Drupal.t('follows the design system'),
    title: resolutionTitle(guide, materials, candidate.ref, condition),
    checked: token?.ref === candidate.ref,
    run: () => {
      onPreview(anchor.value);
      onSettle(candidate.ref);
    },
  }));
  if (escape) {
    if (items.length) {
      items.push({ separator: true });
    }
    items.push({
      label: Drupal.t('Custom value'),
      hint: Drupal.t('frozen, ignores skin changes'),
      checked: token === null,
      run: () => {
        // The explicit literal escape, per-settle: the shown value
        // commits as itself and stops tracking the system; any later
        // capture re-attaches.
        onPreview(face);
        onSettle(face);
      },
    });
  }
  return items;
};

/**
 * The row's right-click menu: the origin's doorways, the "Also set at"
 * group (with "Also set on !candidate" beside it when the address rule
 * found a second authored value on another of the binding's boxes: an
 * inert line, since the way to that box is advanced mode), then the
 * token items, separated.
 *
 * @param {object} input
 * @param {?object} input.origin
 *   originOf(), or null for a row with no origin story (the padding
 *   row's shorthand field: four sides can have four origins).
 * @param {Array<object>} input.tokens
 *   tokenItems().
 * @param {string} [input.face]
 *   The shown display value: the header when there is no origin (the
 *   padding row) and the tokens are the whole menu.
 * @param {Function} [input.onDoorway]
 *   (doorway) — the row dispatches by doorway.id.
 * @param {Function} [input.onPreview]
 *   (pair, active) — a doorway carrying a `preview` pair (the ancestor
 *   doorway's rule) is the menu's active item (true) or stopped being
 *   it (false); the row paints the pair's reach on the canvas.
 * @param {Function} [input.onCondition]
 *   (condition) — drive the canvas to an "Also set at" entry.
 *
 * @return {?{header: ?string, items: Array<object>}}
 *   menu.js useMenu().open() spec parts (the header is the origin's
 *   sentence whenever there is an origin), or null when there is
 *   nothing to show (the native menu rides through).
 */
export const rowMenu = ({
  origin = null,
  tokens = [],
  face = '',
  onDoorway = null,
  onPreview = null,
  onCondition = null,
}) => {
  const groups = [];
  const doorways = (origin?.doorways ?? []).map((doorway) =>
    doorway.disabled
      ? {
          label: doorway.label,
          hint: doorway.hint,
          checked: null,
          disabled: true,
          reason: doorway.reason,
        }
      : {
          label: doorway.label,
          hint: doorway.hint,
          checked: null,
          ...(doorway.preview
            ? { preview: (active) => onPreview?.(doorway.preview, active) }
            : {}),
          run: () => onDoorway?.(doorway),
        },
  );
  if (doorways.length) {
    groups.push(doorways);
  }
  const alsoAt = origin?.alsoAt ?? [];
  const alsoOn = origin?.alsoOn ?? null;
  const also = [
    ...(alsoAt.length
      ? [
          { label: Drupal.t('Also set at'), checked: null, disabled: true },
          ...alsoAt.map((entry) => ({
            label: entry.label,
            hint: entry.value,
            checked: null,
            title: Drupal.t('Drive the canvas to !condition', {
              '!condition': entry.label,
            }),
            run: () => onCondition?.(entry.condition),
          })),
        ]
      : []),
    ...(alsoOn
      ? [
          {
            label: Drupal.t('Also set on !candidate', {
              '!candidate': alsoOn.label,
            }),
            hint: alsoOn.value,
            checked: null,
            disabled: true,
            reason: Drupal.t(
              'The !candidate is styled in Advanced or code mode.',
              { '!candidate': alsoOn.label },
            ),
          },
        ]
      : []),
  ];
  if (also.length) {
    groups.push(also);
  }
  if (tokens.length) {
    groups.push(tokens);
  }
  if (!groups.length) {
    return null;
  }
  return {
    header: origin
      ? origin.header
      : tokens.length && face
        ? Drupal.t('Where should !value come from?', { '!value': face })
        : null,
    items: groups.flatMap((group, index) =>
      index ? [{ separator: true }, ...group] : group,
    ),
  };
};
