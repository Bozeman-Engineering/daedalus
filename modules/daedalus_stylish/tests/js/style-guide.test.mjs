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
  parseKey, declarationForKey, findInstanceDeclaration, findBaseTagDeclaration,
  baseTags, instanceTags, isInstancePartKey, animationReference, swatchReference,
} from '../../js/src/style-guide.js';
import { partLabel } from '../../js/src/parts.js';

const UUID_A = 'aaaaaaaa-1111-2222-3333-444444444444';
const UUID_B = 'bbbbbbbb-1111-2222-3333-444444444444';

// Served nodes: the roster's grants at each address (group id => the
// granted names), the shape ClientStyleGuide serves.
const SPACING = { spacing: ['padding', 'gap'] };
const TYPOGRAPHY = { typography: ['font-size', 'color'] };

const guide = {
  kinds: {
    block: { grants: SPACING },
    field: {
      grants: TYPOGRAPHY,
      elements: {
        '.field__label': { label: 'Label', grants: TYPOGRAPHY },
      },
    },
  },
  blockTypes: {
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

test('parseKey accepts a dotless tag-tier sub (charset only, PHP mirror)', () => {
  // The tag tier: '{key}::p' parses with the bare tag as the sub, and the
  // '::>' children suffix still strips first ('::ul::>' is the tag's
  // children). Grammar is charset only — the vocabulary is semantic,
  // enforced at resolution.
  assert.deepEqual(parseKey(`block:${UUID_A}::p`), {
    form: 'block', kind: 'block', uuid: UUID_A, sub: 'p', children: false,
  });
  assert.deepEqual(parseKey(`container:${UUID_A}::ul::>`), {
    form: 'container', kind: 'container', uuid: UUID_A, sub: 'ul', children: true,
  });
  assert.equal(parseKey(`block:${UUID_A}::h2`)?.sub, 'h2');
  // The charset is the spine's tag-name shape: lowercase start, no
  // uppercase, no leading digit, no empty sub.
  assert.equal(parseKey(`block:${UUID_A}::P`), null);
  assert.equal(parseKey(`block:${UUID_A}::2x`), null);
  assert.equal(parseKey(`block:${UUID_A}::`), null);
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

test('a tag-tier key is not an instance part (advanced-surface routing)', () => {
  // The basic panel's routing predicate answers false for tag keys: the
  // tag tier is an advanced-surface concept, never a selectable part.
  assert.equal(isInstancePartKey(guide, parseKey(`block:${UUID_A}::p`), null), false);
});

test('partLabel labels a tag sub from the declared vocabulary', () => {
  assert.equal(partLabel(guide, `block:${UUID_A}`, 'p', blockTypes), 'Paragraph');
  assert.equal(partLabel(guide, `block:${UUID_A}`, 'h2', blockTypes), 'Heading 2');
  // An undeclared tag falls through to the selector itself.
  assert.equal(partLabel(guide, `block:${UUID_A}`, 'div', blockTypes), 'div');
});

test('partLabel degrades to the namespace label without an annotation', () => {
  // The satellite path: no markup annotation in reach, no owner pattern
  // declaration, so the instance part labels from the site namespace.
  assert.equal(partLabel(guide, `block:${UUID_A}`, '.s-a3', blockTypes), 'Text element');
  // An owner pattern's label still wins where one is declared.
  assert.equal(partLabel(guide, `block:${UUID_B}`, '.s-a3', blockTypes), 'Owner namespace');
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
