/**
 * The lean node tree the selector matcher specs read: the shape the
 * delivered blueprint's `_tree` carries (NodeTreeBuilder's kinds with
 * daedalus_ui's `_element` annotations), built by hand so the facts are
 * legible. SelectorMatcherTest (PHP) carries the same tree; the parity
 * lock pipes the PHP copy through selector-matcher-cases.mjs, this copy
 * pins the client-side reads on their own.
 *
 * Section A (a container layout): two text blocks (a paragraph root
 * whose prose links and emphasizes, a heading root), a formatted text
 * block (a div root holding paragraphs and links, three minted
 * instances: a paragraph, a heading, a list carrying a list item), a
 * nested section B (a text block whose prose emphasizes, an image
 * block, an EMPTY nested section D) and a plugin block. Section C (a
 * two-column layout): a text block alone in the first region, two in
 * the second, the last an open render whose body field links and
 * carries a minted paragraph. Section E (a container layout,
 * "Links"): a heading-rooted text block whose anatomy is declared (a
 * link part rendering an anchor with a nested icon part, a note part
 * declaring no tag, a hoisted part), and a nested section F holding a
 * paragraph text block whose prose emphasizes and whose link part
 * renders an anchor. Section G (a container layout, "Band"): an ordered
 * band of two paragraph text blocks (the second with a link part
 * rendering an anchor), a heading text block wearing a hoisted class,
 * an image block, and a nested section H holding an image block.
 */
const markup = (tag, tags = null) => ({ markup: tags ? { tag, tags } : { tag } });

export const TREE = [
  {
    kind: 'container',
    uuid: 'sec-a',
    layout_id: 'daedalus_stylish_container',
    label: 'Hero',
    // The annotator's minted words ride the node beside its markup.
    _element: { name: 'Hero', type: 'Section', ...markup('section') },
    children: [
      { kind: 'block', uuid: 't1', block_type: 'text', label: 'Intro', _element: { name: 'Intro', type: 'Text', ...markup('p', ['a', 'strong']) } },
      { kind: 'block', uuid: 't2', block_type: 'text', _element: markup('h2') },
      {
        kind: 'block',
        uuid: 'f',
        block_type: 'formatted_text',
        _element: {
          markup: {
            tag: 'div',
            tags: ['p', 'a'],
            instances: [
              { class: 's-p1', tag: 'p', label: 'Paragraph', children: [] },
              { class: 's-h1', tag: 'h2', label: 'Heading 2', children: [] },
              { class: 's-ul', tag: 'ul', label: 'Bulleted list', children: [{ class: 's-li', tag: 'li', label: 'List item', children: [] }] },
            ],
          },
        },
      },
      {
        kind: 'container',
        uuid: 'sec-b',
        layout_id: 'daedalus_stylish_container',
        _element: markup('div'),
        children: [
          { kind: 'block', uuid: 't3', block_type: 'text', _element: markup('p', ['em']) },
          { kind: 'block', uuid: 'img', block_type: 'image', _element: markup('figure') },
          { kind: 'container', uuid: 'sec-d', layout_id: 'daedalus_stylish_container', _element: markup('div'), children: [] },
        ],
      },
      { kind: 'block', uuid: 'p', plugin_id: 'system_menu_block:main' },
    ],
  },
  {
    kind: 'container',
    uuid: 'sec-c',
    layout_id: 'layout_twocol_section',
    _element: markup('div'),
    children: [
      { kind: 'region', region: 'first', children: [{ kind: 'block', uuid: 't4', block_type: 'text', _element: markup('p') }] },
      {
        kind: 'region',
        region: 'second',
        children: [
          { kind: 'block', uuid: 't5', block_type: 'text', _element: markup('p') },
          {
            kind: 'block',
            uuid: 't6',
            block_type: 'text',
            _element: {
              markup: { tag: 'h3' },
              fields: { body: { markup: { tags: ['a'], instances: [{ class: 's-q1', tag: 'p', label: 'Paragraph', children: [] }] } } },
            },
          },
        ],
      },
    ],
  },
  {
    kind: 'container',
    uuid: 'sec-e',
    layout_id: 'daedalus_stylish_container',
    label: 'Links',
    _element: markup('section'),
    children: [
      {
        kind: 'block',
        uuid: 't7',
        block_type: 'text',
        label: 'Call',
        _element: {
          markup: {
            tag: 'h6',
            hoisted: ['.text-block'],
            parts: {
              '.text-block-link': { tag: 'a', parts: { '.text-block-link__icon': { tag: 'svg', parts: {} } } },
              '.text-block-note': { tag: null, parts: {} },
            },
          },
        },
      },
      {
        kind: 'container',
        uuid: 'sec-f',
        layout_id: 'daedalus_stylish_container',
        _element: markup('div'),
        children: [
          { kind: 'block', uuid: 't8', block_type: 'text', _element: { markup: { tag: 'p', tags: ['strong'], parts: { '.text-block-link': { tag: 'a' } } } } },
        ],
      },
    ],
  },
  {
    kind: 'container',
    uuid: 'sec-g',
    layout_id: 'daedalus_stylish_container',
    label: 'Band',
    _element: markup('section'),
    children: [
      { kind: 'block', uuid: 't9', block_type: 'text', _element: markup('p') },
      { kind: 'block', uuid: 't10', block_type: 'text', _element: { markup: { tag: 'p', parts: { '.text-block-link': { tag: 'a' } } } } },
      { kind: 'block', uuid: 't11', block_type: 'text', _element: { markup: { tag: 'h3', hoisted: ['.featured'] } } },
      { kind: 'block', uuid: 'img2', block_type: 'image', _element: markup('figure') },
      {
        kind: 'container',
        uuid: 'sec-h',
        layout_id: 'daedalus_stylish_container',
        _element: markup('div'),
        children: [
          { kind: 'block', uuid: 'img3', block_type: 'image', _element: markup('figure') },
        ],
      },
    ],
  },
];
