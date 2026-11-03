/**
 * The shared rule move battery: one record both twins must answer.
 *
 * Each case is an owner's relative keys, the rule's selector `from`, the
 * selector `to` it becomes, and the one `answer` both sides must give:
 * `{moves, refusal}` (RuleMove::toArray(), planRuleMove()'s return) or
 * `{error: true}` where `from` is outside the grammar (the PHP side
 * throws InvalidArgumentException, the client TypeError). The node suite
 * tests/js/rule-move.test.mjs runs the client twin over every case;
 * RuleMoveTest (the PHP unit test) runs this file to read the cases
 * (stdout: {cases: [...]}) and runs RuleMove::plan() over every one. Both
 * compare to the same recorded answer, so the two can never drift and a
 * case neither side agrees with fails on both. Importing this file reads
 * nothing: the print runs only as a script.
 */
import { fileURLToPath } from 'node:url';

export const CASES = [
  {
    name: 'a plain move: the rule alone, no family',
    keys: ['> d-text', 'p'],
    from: '> d-text',
    to: '> d-text:has(a)',
    answer: { moves: { '> d-text': '> d-text:has(a)' }, refusal: null },
  },
  {
    name: 'the family follows: a part, the own box and the children continue; a longer type, a sibling rule and a tag stay',
    keys: ['> d-text', '> d-text .link', '> d-text::before', '> d-text > *', '> d-textual', '> d-container .link', 'p'],
    from: '> d-text',
    to: '> d-text:not(:has(a))',
    answer: {
      moves: {
        '> d-text': '> d-text:not(:has(a))',
        '> d-text .link': '> d-text:not(:has(a)) .link',
        '> d-text::before': '> d-text:not(:has(a))::before',
        '> d-text > *': '> d-text:not(:has(a)) > *',
      },
      refusal: null,
    },
  },
  {
    name: 'the rule absent from the list still heads the mapping; its continuations follow',
    keys: ['> d-text .link'],
    from: '> d-text',
    to: '> d-image',
    answer: { moves: { '> d-text': '> d-image', '> d-text .link': '> d-image .link' }, refusal: null },
  },
  {
    name: 'no keys at all: the rule maps alone',
    keys: [],
    from: '> d-text',
    to: '> d-image',
    answer: { moves: { '> d-text': '> d-image' }, refusal: null },
  },
  {
    name: 'the root entry and a key outside the grammar continue nothing',
    keys: ['', 'p:has(:has(a))', '> d-text'],
    from: '> d-text',
    to: '> d-image',
    answer: { moves: { '> d-text': '> d-image' }, refusal: null },
  },
  {
    name: 'a prefix across a qualifier or a glued word is no continuation',
    keys: ['> d-text', '> d-text:has(a) .link', '> d-text-ual', '> d-textual'],
    from: '> d-text',
    to: '> d-image',
    answer: { moves: { '> d-text': '> d-image' }, refusal: null },
  },
  {
    name: 'a qualified rule carries its chain continuation',
    keys: ['> d-text:has(a)', '> d-text:has(a) .link ~ .link'],
    from: '> d-text:has(a)',
    to: '> d-text:has(> a)',
    answer: {
      moves: {
        '> d-text:has(a)': '> d-text:has(> a)',
        '> d-text:has(a) .link ~ .link': '> d-text:has(> a) .link ~ .link',
      },
      refusal: null,
    },
  },
  {
    name: 'a part rule moves with its box and its children',
    keys: ['.quote', '.quote::after', '.quote > *'],
    from: '.quote',
    to: '.callout',
    answer: {
      moves: { '.quote': '.callout', '.quote::after': '.callout::after', '.quote > *': '.callout > *' },
      refusal: null,
    },
  },
  {
    name: 'the owner\'s own box moves to its other box',
    keys: ['&::before'],
    from: '&::before',
    to: '&::after',
    answer: { moves: { '&::before': '&::after' }, refusal: null },
  },
  {
    name: 'a rule ending in a pseudo has no family: nothing continues a box',
    keys: ['> d-text::before', '> d-text .link'],
    from: '> d-text::before',
    to: '> d-text::after',
    answer: { moves: { '> d-text::before': '> d-text::after' }, refusal: null },
  },
  {
    name: 'a rule becoming its own box when nothing continues it',
    keys: ['> d-text'],
    from: '> d-text',
    to: '> d-text::before',
    answer: { moves: { '> d-text': '> d-text::before' }, refusal: null },
  },
  {
    name: '`to` is read leniently and lands canonical',
    keys: ['> d-text'],
    from: '> d-text',
    to: '>d-image',
    answer: { moves: { '> d-text': '> d-image' }, refusal: null },
  },
  {
    name: '`to` outside the grammar refuses, naming the text',
    keys: ['> d-text'],
    from: '> d-text',
    to: 'p:has(:has(a))',
    answer: { moves: null, refusal: { reason: 'grammar', key: 'p:has(:has(a))' } },
  },
  {
    name: 'an empty `to` is outside the grammar',
    keys: ['> d-text'],
    from: '> d-text',
    to: '',
    answer: { moves: null, refusal: { reason: 'grammar', key: '' } },
  },
  {
    name: 'a `to` ending in a pseudo carries no continuation: the key that cannot follow is named',
    keys: ['> d-text', '> d-text .link'],
    from: '> d-text',
    to: '> d-text::after',
    answer: { moves: null, refusal: { reason: 'continuation', key: '> d-text .link' } },
  },
  {
    name: 'the first continuation in list order that cannot follow is named',
    keys: ['> d-text', '> d-text .link', '> d-text > *'],
    from: '> d-text',
    to: 'p::before',
    answer: { moves: null, refusal: { reason: 'continuation', key: '> d-text .link' } },
  },
  {
    name: 'the rule\'s own target already present refuses, naming the present key',
    keys: ['> d-text', '> d-text:has(a)'],
    from: '> d-text',
    to: '> d-text:has(a)',
    answer: { moves: null, refusal: { reason: 'target_exists', key: '> d-text:has(a)' } },
  },
  {
    name: 'a family member\'s target already present refuses',
    keys: ['> d-text', '> d-text .link', '> d-image .link'],
    from: '> d-text',
    to: '> d-image',
    answer: { moves: null, refusal: { reason: 'target_exists', key: '> d-image .link' } },
  },
  {
    name: 'a present family member as the target is a collision too',
    keys: ['> d-text', '> d-text .link'],
    from: '> d-text',
    to: '> d-text .link',
    answer: { moves: null, refusal: { reason: 'target_exists', key: '> d-text .link' } },
  },
  {
    name: 'an unchanged selector is its own collision',
    keys: ['> d-text'],
    from: '> d-text',
    to: '> d-text',
    answer: { moves: null, refusal: { reason: 'target_exists', key: '> d-text' } },
  },
  {
    name: 'the rule\'s own collision is judged before any continuation',
    keys: ['> d-text', '> d-text .link', '> d-text::after'],
    from: '> d-text',
    to: '> d-text::after',
    answer: { moves: null, refusal: { reason: 'target_exists', key: '> d-text::after' } },
  },
  {
    name: 'a collision later in the list is found after the continuations before it',
    keys: ['> d-text', '> d-text::before', '> d-image .link', '> d-text .link'],
    from: '> d-text',
    to: '> d-image',
    answer: { moves: null, refusal: { reason: 'target_exists', key: '> d-image .link' } },
  },
  {
    name: '`from` outside the grammar is a caller bug',
    keys: ['> d-text'],
    from: 'p:has(:has(a))',
    to: 'p',
    answer: { error: true },
  },
];

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  process.stdout.write(JSON.stringify({ cases: CASES }));
}
