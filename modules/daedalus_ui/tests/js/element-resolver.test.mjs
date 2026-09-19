/**
 * Self-asserting tests for the element resolver's client twin — the
 * facade-side semantics the round-trip lock cannot see (the equivalence
 * test drives both resolvers with well-formed cases; these lock how the
 * twin treats the CLIENT's own input shapes).
 *
 * Run with `node --test tests/js/*.test.mjs` from the module root.
 */
import { strict as assert } from 'node:assert';
import { test } from 'node:test';
import { resolveElement, DEPTH_NESTED, CLAIMS_CONTENT_ATTRIBUTE } from '../../js/edit_mode/blueprint/element-resolver.js';

const TEXT_DECLARATION = {
  composed: true,
  root: {
    forms: [
      { tag: { field: 'field_text_tag' }, present: { field: 'field_text_tag' } },
      { tag: 'p' },
    ],
  },
  elements: {
    '.text-block-link': {
      label: 'Link',
      tag: 'a',
      present: { field: 'field_text_url' },
      attributes: { href: { field: 'field_text_url' } },
      contains: 'field:field_text_text',
    },
  },
};

test('null data: every data condition is false, data-decided values resolve null', () => {
  const resolved = resolveElement(TEXT_DECLARATION, null);
  // The residual form still matches (it declares no condition).
  assert.equal(resolved.tag, 'p');
  assert.deepEqual(resolved.parts, {}, 'no data can vouch for the anchor');
  assert.deepEqual(resolved.slots, {}, 'the absent anchor claims nothing');
});

test('field presence is key existence; the value is what prints', () => {
  const resolved = resolveElement(TEXT_DECLARATION, {
    fields: { field_text_tag: 'h3', field_text_url: 'https://example.com/', field_text_text: 'Hello' },
  });
  assert.equal(resolved.tag, 'h3');
  assert.deepEqual(resolved.parts['.text-block-link'].attributes, {
    href: 'https://example.com/',
    [CLAIMS_CONTENT_ATTRIBUTE]: '',
  }, 'the field-claiming anchor wears the content-claim marker');
  assert.deepEqual(resolved.slots, { 'field:field_text_text': '.text-block-link' });
});

test('the content-claim marker stamps field claims only', () => {
  const declaration = {
    elements: {
      '.layout__container': { tag: 'div', contains: 'regions' },
    },
  };
  const resolved = resolveElement(declaration, null);
  assert.deepEqual(resolved.parts['.layout__container'].attributes, {},
    'a non-field claim (regions) is structure, not content: no marker');
});

test('an unprintable field value (null) vouches presence but prints nothing', () => {
  // A present key with a null value is "non-empty but no printable
  // string" (a media reference): presence conditions hold, but a tag or
  // attribute reading the field resolves to nothing.
  const resolved = resolveElement(TEXT_DECLARATION, {
    fields: { field_text_tag: null, field_text_text: 'Hello' },
  });
  assert.equal(resolved.tag, null, 'the matched tag-from-field form resolved no printable tag');
});

test('{empty: true} derives from the facade field inventory', () => {
  const declaration = {
    root: { forms: [{ tag: 'div', present: { empty: true } }, { tag: 'span' }] },
  };
  assert.equal(resolveElement(declaration, { fields: {} }).tag, 'div');
  assert.equal(resolveElement(declaration, { fields: { field_image: null } }).tag, 'span', 'a null-valued key still counts as content');
  assert.equal(resolveElement(declaration, null).tag, 'span', 'no data means empty cannot be vouched for');
});

const IMAGE_DECLARATION = {
  composed: true,
  root: {
    forms: [
      { tag: 'a', present: { field: 'field_image_url' }, attributes: { href: { field: 'field_image_url' } } },
      { tag: 'img', present: { media: 'raster' } },
      { tag: 'svg', present: { media: 'vector' } },
      { tag: 'div', present: { empty: true } },
    ],
  },
  elements: {
    '.image-block-image': {
      label: 'Image',
      contains: 'field:field_image',
      forms: [
        { tag: 'img', present: { media: 'raster' }, label: 'Raster' },
        { tag: 'svg', present: { media: 'vector' }, label: 'Vector' },
      ],
    },
  },
};

test('a multi-form part resolves through its matched form: tag and label', () => {
  const resolved = resolveElement(IMAGE_DECLARATION, {
    fields: { field_image: null, field_image_url: 'https://example.com/' },
    mediaKind: 'vector',
  });
  assert.equal(resolved.tag, 'a', 'the anchor form wins first-match');
  const part = resolved.parts['.image-block-image'];
  assert.equal(part.tag, 'svg');
  assert.equal(part.label, 'Vector', 'the matched form\'s own name rides the resolved node');
  assert.deepEqual(part.attributes, { [CLAIMS_CONTENT_ATTRIBUTE]: '' });
  assert.deepEqual(resolved.slots, { 'field:field_image': '.image-block-image' });
});

test('coincidence is decided between matched forms: the vector lane hoists', () => {
  const resolved = resolveElement(IMAGE_DECLARATION, {
    fields: { field_image: null },
    mediaKind: 'vector',
  });
  assert.equal(resolved.tag, 'svg');
  assert.deepEqual(resolved.parts, {}, 'the svg is the root, not a nested part');
  assert.deepEqual(resolved.classes, ['image-block-image']);
  assert.deepEqual(resolved.hoisted, ['.image-block-image']);
  assert.deepEqual(resolved.slots, {}, 'the hoisted part claims nothing; the image mounts at the root');
});

test('a part none of whose forms match is absent, never hoisted', () => {
  // Degenerate media (no resolvable kind): no root media form, no part
  // form, so both honestly resolve nothing.
  const resolved = resolveElement(IMAGE_DECLARATION, {
    fields: { field_image: null },
    mediaKind: null,
  });
  assert.equal(resolved.tag, null, 'no root form matched: honestly undeclared');
  assert.deepEqual(resolved.parts, {});
  assert.deepEqual(resolved.hoisted, []);
  assert.deepEqual(resolved.slots, {}, 'an absent part claims nothing');
});

test('an unknown depth is a caller bug, refused loudly', () => {
  assert.throws(() => resolveElement({}, null, 'sideways'));
});

// The one-box container: a multi-form root swapping on depth and on the
// facade's explicit emptiness fact, the empty-at-root form composing both
// conditions in one present map (the root-form AND).
const CONTAINER_DECLARATION = {
  root: {
    forms: [
      {
        tag: 'section',
        present: { depth: 'root', empty: true },
        attributes: { 'data-container-empty': '' },
      },
      { tag: 'div', present: { empty: true }, attributes: { 'data-container-empty': '' } },
      { tag: 'section', present: { depth: 'root' } },
      { tag: 'div' },
    ],
  },
};

test('depth is a root-form condition: section as a page band, div nested', () => {
  assert.equal(resolveElement(CONTAINER_DECLARATION, { empty: false }).tag, 'section');
  assert.equal(resolveElement(CONTAINER_DECLARATION, { empty: false }, DEPTH_NESTED).tag, 'div');
});

test('the facade\'s explicit empty fact wins over the field inventory', () => {
  // A section has no fields; its emptiness is the caller's fact (the PHP
  // side's emptiness input). An empty fields map would otherwise claim
  // emptiness the caller can deny, and vice versa.
  const empty = resolveElement(CONTAINER_DECLARATION, { empty: true });
  assert.equal(empty.tag, 'section');
  assert.deepEqual(empty.attributes, { 'data-container-empty': '' }, 'the empty form carries its declared drop-affordance attribute');
  const populated = resolveElement(CONTAINER_DECLARATION, { fields: {}, empty: false });
  assert.deepEqual(populated.attributes, {}, 'empty: false denies what the bare inventory would claim');
});

test('a multi-condition root form is an AND: every condition must hold', () => {
  // Nested and empty: the {depth: root, empty: true} form fails on depth,
  // so the div-shaped empty form matches instead.
  const resolved = resolveElement(CONTAINER_DECLARATION, { empty: true }, DEPTH_NESTED);
  assert.equal(resolved.tag, 'div');
  assert.deepEqual(resolved.attributes, { 'data-container-empty': '' });
});

test('null data cannot vouch for emptiness: the populated forms match', () => {
  assert.equal(resolveElement(CONTAINER_DECLARATION, null).tag, 'section');
  assert.deepEqual(resolveElement(CONTAINER_DECLARATION, null).attributes, {});
});
