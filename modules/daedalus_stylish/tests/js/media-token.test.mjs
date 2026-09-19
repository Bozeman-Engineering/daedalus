/**
 * Self-asserting tests for the media token client mirror: the preview-side
 * resolution transform (resolveMapMedia) and its table semantics. Grammar
 * PARITY with MediaToken.php is MediaClientMirrorTest's battery
 * (tests/js/media-cases.mjs); this covers what only the client does.
 *
 * Run directly (`node tests/js/media-token.test.mjs`) or via
 * MediaClientMirrorTest, which executes this file inside the PHPUnit suite.
 * Exits non-zero on failure.
 */
import { strict as assert } from 'node:assert';
import { mentionsMedia, substituteMediaTokens, resolveMapMedia, firstMediaUuid, mediaTokenUuid } from '../../js/src/media-token.js';

const UUID = '123e4567-e89b-12d3-a456-426614174000';
const TOKEN = `url('media:${UUID}')`;
const URLS = { [UUID]: '/files/hero.png' };

const tests = [];
const test = (name, run) => tests.push([name, run]);

test('mentionsMedia is a cheap case-insensitive scheme scan', () => {
  assert.equal(mentionsMedia(TOKEN), true);
  assert.equal(mentionsMedia('url("MEDIA:abc")'), true);
  assert.equal(mentionsMedia('linear-gradient(red, blue)'), false);
  assert.equal(mentionsMedia(42), false);
  assert.equal(mentionsMedia(null), false);
});

test('substitution replaces every token, quoted or not, and only tokens', () => {
  const value = `linear-gradient(red, blue), ${TOKEN}, url('media:${UUID}'), url(media:${UUID}), url(/real.png)`;
  const out = substituteMediaTokens(value, (uuid) => `[${uuid}]`);
  assert.equal(out, `linear-gradient(red, blue), [${UUID}], [${UUID}], [${UUID}], url(/real.png)`);
});

test('firstMediaUuid reads the first token layer, lowercased, or null', () => {
  assert.equal(firstMediaUuid(TOKEN), UUID);
  assert.equal(firstMediaUuid(`linear-gradient(red, blue), url('MEDIA:${UUID.toUpperCase()}'), ${TOKEN}`), UUID);
  assert.equal(firstMediaUuid('linear-gradient(red, blue)'), null);
  assert.equal(firstMediaUuid(''), null);
  assert.equal(firstMediaUuid(null), null);
  // The shared pattern's lastIndex must survive the read (matchAll clones).
  assert.equal(firstMediaUuid(TOKEN), UUID);
});

test('mediaTokenUuid answers only a value that IS exactly one token', () => {
  // Quote styles, spacing and scheme case all read; the payload lowers.
  assert.equal(mediaTokenUuid(TOKEN), UUID);
  assert.equal(mediaTokenUuid(`  url('MEDIA:${UUID.toUpperCase()}')  `), UUID);
  assert.equal(mediaTokenUuid(`url(media:${UUID})`), UUID);
  // Anchored: layers, trailing content, and non-token images are all
  // occupancy the pair state must keep reading as custom, so they answer
  // null even though firstMediaUuid would read their first token layer.
  assert.equal(mediaTokenUuid(`linear-gradient(red, blue), ${TOKEN}`), null);
  assert.equal(mediaTokenUuid(`${TOKEN}, ${TOKEN}`), null);
  assert.equal(mediaTokenUuid(`${TOKEN} no-repeat`), null);
  assert.equal(mediaTokenUuid('url(/real.png)'), null);
  assert.equal(mediaTokenUuid(''), null);
  assert.equal(mediaTokenUuid(null), null);
  assert.equal(mediaTokenUuid(42), null);
});

test('a resolvable token substitutes to its table URL', () => {
  const map = { 'entity:a': { default: { 'background-image': `linear-gradient(red, blue), ${TOKEN}` } } };
  const resolved = resolveMapMedia(map, URLS);
  assert.equal(
    resolved['entity:a'].default['background-image'],
    'linear-gradient(red, blue), url("/files/hero.png")',
  );
});

test('a uuid the table cannot answer degrades to none', () => {
  const map = { 'entity:a': { default: { 'background-image': "url('media:ffffffff-0000-0000-0000-000000000000')" } } };
  assert.equal(resolveMapMedia(map, URLS)['entity:a'].default['background-image'], 'none');
  assert.equal(resolveMapMedia(map, {})['entity:a'].default['background-image'], 'none');
  assert.equal(resolveMapMedia(map, undefined)['entity:a'].default['background-image'], 'none');
});

test('table lookup lowercases the payload (server keys are lowercased)', () => {
  const map = { 'entity:a': { default: { 'background-image': `url('media:${UUID.toUpperCase()}')` } } };
  assert.equal(resolveMapMedia(map, URLS)['entity:a'].default['background-image'], 'url("/files/hero.png")');
});

test('variants stay untouched, mirroring the server walk', () => {
  const map = {
    'entity:a': {
      variants: [{ variant: 'hero_bg', fragment: '' }],
      default: { 'background-image': TOKEN },
    },
  };
  const resolved = resolveMapMedia(map, URLS);
  assert.deepEqual(resolved['entity:a'].variants, [{ variant: 'hero_bg', fragment: '' }]);
  assert.equal(resolved['entity:a'].default['background-image'], 'url("/files/hero.png")');
});

test('copy-on-write: the input map is never mutated', () => {
  const map = { 'entity:a': { default: { 'background-image': TOKEN, color: 'red' } } };
  const resolved = resolveMapMedia(map, URLS);
  assert.notEqual(resolved, map);
  assert.equal(map['entity:a'].default['background-image'], TOKEN);
  assert.equal(resolved['entity:a'].default.color, 'red');
});

test('a token-free map returns the same reference', () => {
  const map = { 'entity:a': { default: { color: 'red' } }, 'block:b': 'not-an-entry' };
  assert.equal(resolveMapMedia(map, URLS), map);
});

let failures = 0;
for (const [name, run] of tests) {
  try {
    run();
    console.log(`ok - ${name}`);
  }
  catch (error) {
    failures += 1;
    console.error(`not ok - ${name}`);
    console.error(error?.message ?? error);
  }
}
if (failures > 0) {
  console.error(`${failures} of ${tests.length} media-token tests failed.`);
  process.exit(1);
}
console.log(`${tests.length} media-token tests passed.`);
