/**
 * The shared emit gate battery: one record both twins must answer.
 *
 * Each case is a `text`, whether it is read as a `selector` (the child
 * combinator admitted) and the one `answer` both sides must give
 * (EmitGate::admits() / admitsSelector() and the client twin's). The
 * node suite daedalus_ui/tests/js/emit-gate.test.mjs runs the twin over
 * every case; EmitGateTest (the PHP unit test) runs this file to read
 * the cases (stdout: {cases}) and runs the PHP class over every one. Both
 * compare to the same recorded answer, so the two can never drift.
 * Importing this file reads nothing: the print runs only as a script.
 */
import { fileURLToPath } from 'node:url';

const UUID = 'aaaaaaaa-1111-2222-3333-444444444444';
const yes = (name, text, selector = false) => ({ name, text, selector, answer: true });
const no = (name, text, selector = false) => ({ name, text, selector, answer: false });

export const CASES = [
  // Plain values.
  yes('a length', '2rem'),
  yes('a shorthand', '1px solid var(--color-border)'),
  yes('a function with commas', 'cubic-bezier(0.4, 0, 0.2, 1)'),
  yes('nested functions', 'calc(100% - min(2rem, var(--gap, 4px)))'),
  yes('grid line names', '[full-start] 1fr [content-start] minmax(0, 60ch) [content-end] 1fr [full-end]'),
  yes('a quoted string', '"\\201C"'),
  yes('a font stack', '"Helvetica Neue", Arial, sans-serif'),
  yes('a string holding brackets and a call', '"a (b] url(x) c"'),
  yes('a string holding the other quote', '"it\'s"'),
  yes('an escaped quote in a string', '"say \\"hi\\""'),
  yes('an escaped line break continues a string', '"a\\\nb"'),
  yes('a value over two lines', 'linear-gradient(red, blue),\n  linear-gradient(blue, red)'),
  yes('a division', 'calc(1rem / 2)'),
  yes('the empty text', ''),

  // The characters no text carries.
  no('a semicolon', 'red; color: blue'),
  no('an opening brace', 'red { color: blue'),
  no('a closing brace', 'red } body { color: blue'),
  no('a closing tag', '</style><script>alert(1)</script>'),
  no('an opening angle in a string', '"</style>"'),
  no('a closing angle', 'a > b'),
  no('a NUL', 'a\u0000b'),
  no('a form feed', 'a\fb'),
  no('an escape character', 'a\u001bb'),

  // Strings close.
  no('an unclosed string', '"abc'),
  no('an unclosed single-quoted string', "'abc"),
  no('a string broken by a line', '"a\nb"'),
  no('a string ending on its escape', '"abc\\'),

  // Brackets close, in order.
  no('an unclosed parenthesis', 'calc(1px + 2px'),
  no('a stray closing parenthesis', '1px) 2px'),
  no('an unclosed bracket', '[full-start 1fr'),
  no('a stray closing bracket', 'full-start] 1fr'),
  no('crossed partners', 'calc([1px)]'),
  no('a parenthesis closed only inside a string', 'calc(1px ")"'),

  // Nothing that reads differently to the browser.
  no('a backslash outside a string', 'u\\72l(//example.com/x)'),
  no('an escaped parenthesis', 'calc(1px \\) '),
  no('a comment', 'red /* ) */'),
  no('a comment opening', 'calc(1px /* + 2px)'),

  // url(): the media token.
  yes('the canonical media token', `url('media:${UUID}')`),
  yes('a double-quoted media token', `url("media:${UUID}")`),
  yes('an unquoted media token', `url(media:${UUID})`),
  yes('a spaced, upper-case media token', `URL( 'MEDIA:${UUID.toUpperCase()}' )`),
  yes('a media token under a gradient', `linear-gradient(rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.4)), url('media:${UUID}')`),
  yes('a media token as a var() fallback', `var(--hero, url('media:${UUID}'))`),
  no('a media token with mismatched quotes', `url('media:${UUID}")`),
  no('a media token with no payload', "url('media:')"),
  no('a media token with a path for a payload', "url('media:../x')"),

  // url(): the resolved file.
  yes('a resolved file', 'url("/sites/default/files/hero.png")'),
  yes('a resolved derivative with a query', 'url("/sites/default/files/styles/wide/public/hero.png?itok=aB3_x-9&h=1")'),
  yes('a resolved file under a gradient', 'linear-gradient(red, blue), url("/files/hero.png")'),
  no('a single-quoted path', "url('/files/hero.png')"),
  no('an unquoted path', 'url(/files/hero.png)'),
  no('a spaced resolved file', 'url( "/files/hero.png" )'),
  no('a relative path', 'url("files/hero.png")'),
  no('a protocol-relative host', 'url("//example.com/x.png")'),
  no('a backslash host', 'url("/\\\\example.com/x.png")'),
  no('an absolute URL', 'url("https://example.com/x.png")'),
  no('an unquoted absolute URL', 'url(https://example.com/x.png)'),
  no('a data URL', 'url("data:image/svg+xml,%3Csvg%3E")'),
  no('a script URL', 'url("javascript:alert(1)")'),
  no('a fragment', 'url(#clip)'),
  no('an empty call', 'url()'),
  no('an unclosed call', 'url("/files/hero.png"'),
  no('a path holding a space', 'url("/files/a b.png")'),

  // url() wherever it stands.
  no('another host inside var()', 'var(--x, url(//example.com/x))'),
  no('another host inside calc()', 'calc(1px + url(//example.com/x))'),
  no('another host beside a media token', `url('media:${UUID}'), url(//example.com/x)`),
  no('another host in upper case', 'URL(//example.com/x)'),
  no('another host in a custom property', 'var(--a) url("//example.com/x")'),

  // The other functions that fetch.
  no('image-set() with a string', 'image-set("//example.com/x.png" 1x)'),
  no('the prefixed image-set()', '-webkit-image-set("//example.com/x.png" 1x)'),
  no('image()', 'image("//example.com/x.png")'),
  no('src()', 'src("//example.com/x.woff2")'),
  yes('a function whose name only ends alike', 'my-image-set(1px)'),

  // Condition text.
  yes('a media query', '@media (prefers-color-scheme: dark)'),
  yes('a container query', '@container page (min-width: 768px) and (max-width: 1023px)'),
  yes('a supports test', '@supports (display: grid) and (not (display: inline-grid))'),
  yes('a supports selector test', '@supports selector(:has(a))'),
  no('a supports test that closes the rule', '@supports (display: grid){}</style><script>alert(1)</script>'),
  no('a supports test that opens a block', '@supports (display: grid) { * { color: red'),
  no('a supports test left open', '@supports (display: grid'),
  no('a range query', '@media (width < 600px)'),

  // Selectors.
  yes('a child combinator', ':scope > :where(p)', true),
  yes('an attribute root', '[data-selectable="block:aaaaaaaa-1111-2222-3333-444444444444"]', true),
  yes('a state dual', ':scope:is(:hover, [data-force-state~="hover"])', true),
  yes('an attribute string with its marks escaped', ':scope:where([title="\\3c /style\\3e "])', true),
  yes('an attribute string holding brackets', ':scope:where([title="a)]("])', true),
  no('an attribute string holding a tag', ':scope:where([title="</style><script>"])', true),
  no('a selector that opens a block', ':scope { color: red', true),
  no('a selector left open', ':scope:is(:hover', true),
  no('a selector with an unclosed string', ':scope:where([title="a])', true),

  // Font family names.
  yes('a quoted family', '"Inter Tight"'),
  no('a family holding a tag', '"Inter</style>"'),
  no('a family holding a block', '"Inter"; } body { display: none'),
];

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  process.stdout.write(JSON.stringify({ cases: CASES }));
}
