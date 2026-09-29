/**
 * Self-asserting tests for the guide's part resolution rule.
 *
 * The client half of the one resolution rule (PHP is authoritative:
 * StyleMapValidator::resolveDeclaration()): exact part, then the owner's
 * own pattern part, then the guide's site instance namespaces
 * (guide.instances, the spine's `instances:` lane). Minted identity
 * carriers are content whose ownership varies by render shape — a
 * composed block's carriers style at `block:{uuid}::.s-x`, an open
 * render's at the owning field key — so both owner shapes must resolve
 * through the ONE instance declaration without either owner declaring
 * anything. Run directly (`node tests/js/style-guide.test.mjs`) or via
 * JsSuitesTest. Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import {
  parseKey, declarationForKey, landingForKey, declaredForKey, pseudoGroups, findInstanceDeclaration, findBaseTagDeclaration,
  baseTags, instanceTags, tagWord, isInstancePartKey, animationReference, swatchReference,
} from '../../js/src/style-guide.js';
import { selectorLabel } from '../../js/src/parts.js';

const UUID_A = 'aaaaaaaa-1111-2222-3333-444444444444';
const UUID_B = 'bbbbbbbb-1111-2222-3333-444444444444';

// Served nodes: the roster's grants at each address (group id => the
// granted names), the shape ClientStyleGuide serves.
const SPACING = { spacing: ['padding', 'gap'] };
const TYPOGRAPHY = { typography: ['font-size', 'color'] };
// The existence switch: the one pseudo-only group, flagged as served.
const GENERATED = { generated_content: ['content'] };

const guide = {
  groups: {
    spacing: { label: 'Spacing', properties: { padding: {}, gap: {} } },
    generated_content: { label: 'Generated content', properties: { content: {} }, pseudo: true },
  },
  kinds: {
    block: { grants: SPACING, valueSpaces: { padding: { max: '4rem' } } },
    // A container declaring its children section, one part and its
    // ::before box: the landings of `> p + *`, `> *:not(…)`, a
    // part-first sibling chain and the anchor.
    container: {
      grants: SPACING,
      elements: { '.band': { label: 'Band', grants: SPACING } },
      children: { grants: { spacing: ['margin-top', 'margin-bottom'] } },
      pseudo: { before: { grants: { sizing: ['width'], ...GENERATED }, valueSpaces: {} } },
    },
    field: {
      grants: TYPOGRAPHY,
      elements: {
        '.field__label': { label: 'Label', grants: TYPOGRAPHY },
      },
    },
  },
  blockTypes: {
    // The type compounds' landings: a text type declaring one part, an
    // image type declaring none.
    text: {
      grants: TYPOGRAPHY,
      elements: { '.link': { label: 'Link', grants: TYPOGRAPHY } },
    },
    image: { grants: SPACING },
    // An owner declaring its own pattern part over the same prefix: the
    // owner's declaration answers before the site namespace.
    annotated: {
      grants: TYPOGRAPHY,
      elements: {
        '.s-exact': { label: 'Exact wins', grants: TYPOGRAPHY },
        '.s-*': { label: 'Owner namespace', grants: TYPOGRAPHY },
      },
    },
  },
  // The site instance namespaces, served as roster nodes like every
  // other node; label and tags (identity) ride as authored.
  instances: {
    '.s-*': {
      label: 'Text element',
      tags: { p: 'Paragraph', h2: 'Heading 2' },
      grants: TYPOGRAPHY,
    },
  },
  // The site's base section: the one declaration of a prose tag's
  // styleability, the tag tier's vocabulary. `a` is styleable without
  // ever minting; `h2` mints without base declaring it here.
  base: {
    p: { grants: { ...TYPOGRAPHY, ...SPACING } },
    a: { grants: TYPOGRAPHY },
  },
};

const blockTypes = { [UUID_A]: null, [UUID_B]: 'annotated' };

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('findInstanceDeclaration matches by prefix extension only', () => {
  const declaration = findInstanceDeclaration(guide.instances, '.s-a3');
  assert.equal(declaration?.label, 'Text element');
  // The bare prefix, a non-class selector, and a foreign prefix miss.
  assert.equal(findInstanceDeclaration(guide.instances, '.s-'), null);
  assert.equal(findInstanceDeclaration(guide.instances, 'div > .s-a3'), null);
  assert.equal(findInstanceDeclaration(guide.instances, '.x-a3'), null);
  assert.equal(findInstanceDeclaration(undefined, '.s-a3'), null);
});

test('block-key instance part resolves through the site namespace', () => {
  // The composed-carrier shape: no block declaration carries a pattern
  // part, and the key still resolves to the namespace's declaration.
  const parsed = parseKey(`block:${UUID_A}::.s-a3`);
  const declaration = declarationForKey(guide, parsed, null);
  assert.equal(declaration.label, 'Text element');
  assert.deepEqual(declaration.grants, TYPOGRAPHY);
});

test('field-key instance part resolves through the same namespace', () => {
  // The open-render shape: the retired field-kind pattern part is gone
  // and the field key resolves through the one site declaration.
  const parsed = parseKey(`field:${UUID_A}:field_body::.s-a3`);
  assert.equal(declarationForKey(guide, parsed, null).label, 'Text element');
});

test('exact and owner pattern declarations win over the namespace', () => {
  const exact = parseKey(`block:${UUID_B}::.s-exact`);
  assert.equal(declarationForKey(guide, exact, 'annotated').label, 'Exact wins');
  const pattern = parseKey(`block:${UUID_B}::.s-a3`);
  assert.equal(declarationForKey(guide, pattern, 'annotated').label, 'Owner namespace');
});

test('a part outside every declaration resolves to {}', () => {
  const parsed = parseKey(`block:${UUID_A}::.mystery`);
  assert.deepEqual(declarationForKey(guide, parsed, null), {});
});

test('parseKey reads the relative selector suffix (charset only, PHP mirror)', () => {
  // The suffix after '::' is a relative selector in its canonical
  // spelling: a tag ('p'), a part, the children ('> *'), a type, their
  // compounds. Grammar is charset only — the vocabulary is semantic,
  // enforced at resolution.
  assert.deepEqual(parseKey(`block:${UUID_A}::p`), {
    form: 'block', kind: 'block', uuid: UUID_A, selector: 'p',
  });
  assert.deepEqual(parseKey(`container:${UUID_A}::ul > *`), {
    form: 'container', kind: 'container', uuid: UUID_A, selector: 'ul > *',
  });
  assert.equal(parseKey(`block:${UUID_A}::h2`)?.selector, 'h2');
  assert.equal(parseKey(`container:${UUID_A}::> pb-text .link`)?.selector, '> pb-text .link');
  assert.equal(parseKey(`container:${UUID_A}`)?.selector, null);
  // The charset is the grammar's: lowercase tags, no empty suffix, one
  // canonical spelling (the retired '::>' pseudo-part never parses).
  assert.equal(parseKey(`block:${UUID_A}::P`), null);
  assert.equal(parseKey(`block:${UUID_A}::2x`), null);
  assert.equal(parseKey(`block:${UUID_A}::`), null);
  assert.equal(parseKey(`container:${UUID_A}::>`), null);
  assert.equal(parseKey(`container:${UUID_A}::.a::>`), null);
  assert.equal(parseKey(`container:${UUID_A}::>*`), null);
});

test('a tag-tier key resolves through the base section only', () => {
  // The declared tag resolves to its base declaration — the tag tier
  // validates and models against the SAME styling vocabulary the skin's
  // base:{tag} defaults do.
  const parsed = parseKey(`block:${UUID_A}::p`);
  assert.deepEqual(declarationForKey(guide, parsed, null), { grants: { ...TYPOGRAPHY, ...SPACING } });
  // A base tag no instance namespace mints (links) still resolves: base
  // owns styleability, instances own identity.
  assert.deepEqual(declarationForKey(guide, parseKey(`block:${UUID_A}::a`), null), { grants: TYPOGRAPHY });
  // Owner exact/pattern parts and instance namespaces are never consulted
  // for tags: h2 carries identity in the instance lane but base does not
  // declare it, so it resolves to {} (the server rejects the write in
  // every enforcement mode; the client resolver has nothing to say).
  const owned = parseKey(`block:${UUID_B}::h2`);
  assert.deepEqual(declarationForKey(guide, owned, 'annotated'), {});
  assert.deepEqual(declarationForKey(guide, parseKey(`block:${UUID_A}::div`), null), {});
  assert.equal(findBaseTagDeclaration(guide.base, 'div'), null);
  assert.equal(findBaseTagDeclaration(guide.base, 'h2'), null);
  assert.equal(findBaseTagDeclaration(undefined, 'p'), null);
  // A prototype key is not a declared tag.
  assert.equal(findBaseTagDeclaration(guide.base, 'constructor'), null);
});

test('landingForKey walks sibling and qualified compounds (PHP mirror)', () => {
  // The roster walk (VocabularyRosterBuilder::walk()): a sibling compound
  // resolves where its predecessor did, so `> p + *` lands on the
  // container's children and `> pb-container > p + p` on base.p; a
  // qualifier is judged and never moves the landing, so `> pb-text:has(a)`
  // lands on the text type (not base.a) and `> *:not(pb-image)` on the
  // children; an undeclared compound anywhere answers null.
  const land = (selector) =>
    landingForKey(guide, parseKey(`container:${UUID_A}::${selector}`), null);
  assert.deepEqual(land('> p + *'), guide.kinds.container.children);
  assert.deepEqual(land('> p ~ p'), guide.base.p);
  assert.deepEqual(land('> pb-container > p + p'), guide.base.p);
  assert.deepEqual(land('> pb-text .link ~ .link'), guide.blockTypes.text.elements['.link']);
  assert.deepEqual(land('.band + .band'), guide.kinds.container.elements['.band']);
  assert.deepEqual(land('> pb-text:has(a)'), guide.blockTypes.text);
  assert.deepEqual(land('> pb-text:has(.link)'), guide.blockTypes.text);
  assert.deepEqual(land('> pb-text:has(+ pb-image)'), guide.blockTypes.text);
  assert.deepEqual(land('> pb-container:has(> pb-image)'), guide.kinds.container);
  assert.deepEqual(land('> pb-container:has(> *)'), guide.kinds.container);
  assert.deepEqual(land('> *:not(pb-image)'), guide.kinds.container.children);
  assert.deepEqual(land('> pb-text:not(:has(a))'), guide.blockTypes.text);
  assert.deepEqual(land('> pb-text:has(a):not(.band)'), guide.blockTypes.text);
  // A `:has()` argument's parts resolve against the qualified type; a
  // `:not()` compound is judged where the qualified compound stands (the
  // container owns `.band`, the text type does not).
  assert.equal(land('> pb-text:has(.nope)'), null);
  assert.equal(land('> pb-text:has(.band)'), null);
  assert.equal(land('> pb-text:not(.link)'), null);
  assert.equal(land('> *:not(pb-video)'), null);
  assert.equal(land('> pb-text:has(> *)'), null);
  assert.equal(land('> pb-text:has(a) + pb-text:has(em)'), null);
  assert.deepEqual(declarationForKey(guide, parseKey(`container:${UUID_A}::> pb-text:has(em)`), null), {});
});

test('a pseudo-element key lands on its host\'s box: served when declared, derived otherwise (PHP mirror)', () => {
  // VocabularyRosterBuilder::pseudoNode(): the host's served section
  // when it declares the box, else the host's grants and value spaces
  // plus the pseudo-only groups in full, last.
  assert.deepEqual(pseudoGroups(guide), ['generated_content']);
  assert.deepEqual(pseudoGroups({}), []);
  const land = (key, bundle = null) => landingForKey(guide, parseKey(key), bundle);
  assert.deepEqual(land(`container:${UUID_A}::&::before`), guide.kinds.container.pseudo.before);
  assert.deepEqual(land(`container:${UUID_A}::&::after`), { grants: { ...SPACING, ...GENERATED }, valueSpaces: {} });
  assert.deepEqual(land(`block:${UUID_A}::&::before`), {
    grants: { ...SPACING, ...GENERATED },
    valueSpaces: { padding: { max: '4rem' } },
  });
  // The derivation never touches the host's served node.
  assert.deepEqual(guide.kinds.block.grants, SPACING);
  // A part's, a type's, a tag's and the children's boxes derive from the
  // chain's landing; an undeclared host ends the walk before the box.
  assert.deepEqual(land(`container:${UUID_A}::.band::after`), { grants: { ...SPACING, ...GENERATED }, valueSpaces: {} });
  assert.deepEqual(land(`container:${UUID_A}::> pb-text::before`), { grants: { ...TYPOGRAPHY, ...GENERATED }, valueSpaces: {} });
  assert.deepEqual(land(`block:${UUID_A}::p::after`), { grants: { ...TYPOGRAPHY, ...SPACING, ...GENERATED }, valueSpaces: {} });
  assert.deepEqual(land(`container:${UUID_A}::> *::before`), { grants: { spacing: ['margin-top', 'margin-bottom'], ...GENERATED }, valueSpaces: {} });
  assert.equal(land(`container:${UUID_A}::.nope::before`), null);
  assert.equal(land(`block:${UUID_A}::em::after`), null);
  assert.deepEqual(declarationForKey(guide, parseKey(`container:${UUID_A}::.nope::before`), null), {});
  // A pseudo-only property takes the site's authored constraint default
  // when one names it, like any group declared without constraints.
  const bound = { ...guide, constraintDefaults: { content: { allowed: ['none'] }, gap: { scale: 'space' } } };
  assert.deepEqual(landingForKey(bound, parseKey(`block:${UUID_A}::&::after`), null).valueSpaces, {
    padding: { max: '4rem' },
    content: { allowed: ['none'] },
  });
  // A box of an instance part is a rule of its own, not the part.
  assert.equal(isInstancePartKey(guide, parseKey(`block:${UUID_A}::.s-a3::before`), null), false);
  assert.equal(isInstancePartKey(guide, parseKey(`block:${UUID_A}::.s-a3`), null), true);
});

test('declaredForKey is the roster\'s declared(): a derived box lands but is not declared', () => {
  const declared = (key, bundle = null) => declaredForKey(guide, parseKey(key), bundle);
  assert.equal(declared(`container:${UUID_A}`), true);
  assert.equal(declared(`block:${UUID_A}`, 'unknown'), true, 'a degraded refinement is a declared place');
  assert.equal(declared(`container:${UUID_A}::.band`), true);
  assert.equal(declared(`container:${UUID_A}::.nope`), false);
  assert.equal(declared(`block:${UUID_A}::em`), false);
  assert.equal(declared(`container:${UUID_A}::&::before`), true);
  assert.equal(declared(`container:${UUID_A}::&::after`), false);
  assert.equal(declared(`container:${UUID_A}::.band::after`), false);
  assert.equal(declared(`container:${UUID_A}::.nope::before`), false);
  assert.equal(declared(`block:${UUID_A}::p::after`), false);
});

test('baseTags is the styleability read, instanceTags the identity read', () => {
  // Two vocabularies, two reads: base's keys in authoring order say what
  // a tag tier may target; the instance tags map says what mints and
  // how it is labelled. They overlap without either owning the other.
  assert.deepEqual(baseTags(guide.base), ['p', 'a']);
  assert.deepEqual(baseTags(undefined), []);
  assert.deepEqual(baseTags({}), []);
  const instances = {
    '.s-*': { label: 'Text element', tags: { p: 'Paragraph', h2: 'Heading 2' } },
    '.t-*': { label: 'Other', tags: { p: 'Shadowed', ul: 'Bulleted list' } },
  };
  assert.deepEqual(instanceTags(instances), { p: 'Paragraph', h2: 'Heading 2', ul: 'Bulleted list' });
  assert.deepEqual(instanceTags(undefined), {});
});

test('tagWord is the one word for a tag: the base word, then identity, then the tag', () => {
  // The guide's tagLabels (PHP: StyleGuide::baseTagLabels(), every
  // closed tag with the site's declared labels applied) wins for the
  // tags it words: the tag tier resolves to the base declaration for
  // every owner, so its word beats the instance lane's identity word
  // for the same tag. A tag outside the closed set reads the identity
  // word; a tag no lane words spells itself.
  const worded = {
    ...guide,
    tagLabels: { p: 'Paragraph', a: 'Link', em: 'Emphasis' },
    instances: { '.s-*': { tags: { p: 'Body text', span: 'Span' } } },
  };
  assert.equal(tagWord(worded, 'a'), 'Link');
  assert.equal(tagWord(worded, 'p'), 'Paragraph');
  assert.equal(tagWord(worded, 'em'), 'Emphasis');
  assert.equal(tagWord(worded, 'span'), 'Span');
  assert.equal(tagWord(worded, 'div'), 'div');
  // No tagLabels served (or an empty PHP array): identity, then the tag.
  assert.equal(tagWord({ ...worded, tagLabels: [] }, 'p'), 'Body text');
  assert.equal(tagWord(guide, 'h2'), 'Heading 2');
  assert.equal(tagWord(guide, 'a'), 'a');
  assert.equal(tagWord(undefined, 'a'), 'a');
  // A prototype key is not a worded tag.
  assert.equal(tagWord(worded, 'constructor'), 'constructor');
});

test('a tag-tier key is not an instance part (advanced-surface routing)', () => {
  // The basic panel's routing predicate answers false for tag keys: the
  // tag tier is an advanced-surface concept, never a selectable part.
  assert.equal(isInstancePartKey(guide, parseKey(`block:${UUID_A}::p`), null), false);
});

test('selectorLabel labels a tag selector from the declared vocabulary', () => {
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, 'p', blockTypes), 'Paragraph');
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, 'h2', blockTypes), 'Heading 2');
  // An undeclared tag falls through to the selector itself.
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, 'div', blockTypes), 'div');
  // The children, a type, and a chain read their compounds' labels.
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, '> *', blockTypes), 'Children');
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, '> pb-container', blockTypes), 'Containers');
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, '> pb-text p', blockTypes), 'pb-text › Paragraph');
});

test('selectorLabel degrades to the namespace label without an annotation', () => {
  // The satellite path: no markup annotation in reach, no owner pattern
  // declaration, so the instance part labels from the site namespace.
  assert.equal(selectorLabel(guide, `block:${UUID_A}`, '.s-a3', blockTypes), 'Text element');
  // An owner pattern's label still wins where one is declared.
  assert.equal(selectorLabel(guide, `block:${UUID_B}`, '.s-a3', blockTypes), 'Owner namespace');
});

test('animationReference is the motion namespace twin of swatchReference', () => {
  // The reserved prefixes are namespace questions only (PHP is
  // authoritative: StyleGuide::animationReference / swatchReference):
  // prefix plus a name tail, nothing else.
  assert.equal(animationReference('motion-fade_in'), 'fade_in');
  assert.equal(animationReference('motion-a-b'), 'a-b', 'the tail is whatever follows the prefix');
  assert.equal(animationReference('motion-'), null, 'the bare prefix names nothing');
  assert.equal(animationReference('motion'), null);
  assert.equal(animationReference('spin'), null, 'a theme keyframes name is outside the namespace');
  assert.equal(animationReference('--swatch-motion-x'), null, 'a custom property is not a keyframes ident');
  assert.equal(animationReference(undefined), null);
  // The two namespaces never meet: a swatch variable is no animation and
  // a keyframes ident is no swatch.
  assert.equal(swatchReference('motion-fade_in'), null);
  assert.equal(animationReference('--swatch-fade_in'), null);
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  } catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error.message);
  }
}
if (failures) {
  console.error(`${failures} of ${tests.length} style-guide resolution tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} style-guide resolution tests passed.`);
