/**
 * The document tree the selector matcher specs read: the shape
 * documentTree() derives from a delivered blueprint (one entity node,
 * the host above its sections, with the blueprint's `layout`,
 * NodeTreeBuilder's kinds carrying daedalus_ui's `_element` annotations,
 * as its children), built by hand so the facts are legible.
 * SelectorMatcherTest (PHP) carries the same document; the parity lock
 * pipes the PHP copy through selector-matcher-cases.mjs, this copy pins
 * the client-side reads on their own.
 *
 * The root is the page "Welcome" (uuid `page`, a tagless entity markup,
 * as the annotator usually stamps it), holding four sections.
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
 * renders an anchor. Section G (a container layout, "Section"): an ordered
 * section of two paragraph text blocks (the second with a link part
 * rendering an anchor), a heading text block wearing a hoisted class,
 * an image block, and a nested section H holding an image block.
 *
 * KEYLESS is a second, small document whose root carries no uuid (a
 * template host, the site frame): a section holding a paragraph text
 * block, and an empty section. The root is structure nothing addresses.
 */
const markup = (tag, tags = null) => ({ markup: tags ? { tag, tags } : { tag } });

// The blueprint's `layout`: the sections, as the serializer emits them.
const LAYOUT = [
  {
    kind: 'container',
    uuid: 'sec-a',
    layout_id: 'daedalus_stylish_container',
    label: 'Hero',
    // The annotator's minted words ride the node beside its markup.
    _element: { name: 'Hero', type: 'Section', icon: '/icons/section.svg', ...markup('section') },
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
    label: 'Section',
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

export const DOCUMENT = [
  {
    kind: 'entity',
    uuid: 'page',
    _element: { name: 'Welcome', type: 'Page', icon: '/icons/page.svg', markup: {} },
    children: LAYOUT,
  },
];

export const KEYLESS = [
  {
    kind: 'entity',
    uuid: null,
    _element: { name: 'Template', type: 'Template', icon: null, markup: {} },
    children: [
      {
        kind: 'container',
        uuid: 'sec-x',
        layout_id: 'daedalus_stylish_container',
        _element: markup('section'),
        children: [{ kind: 'block', uuid: 'tx', block_type: 'text', _element: markup('p', ['a']) }],
      },
      { kind: 'container', uuid: 'sec-y', layout_id: 'daedalus_stylish_container', _element: markup('div'), children: [] },
    ],
  },
];

/**
 * The style map the document is styled by, for the wear: the hero's first
 * text block and the Section's heading wear Featured (the root
 * fragment's plain variant class), the Section's second paragraph wears
 * a distributed fragment of Card (the slot-encoded class) beside a
 * reference that cannot be a class name (stamps nothing), the nested
 * section H wears Featured too, the open render's body field
 * (`block:t6:body`, a field record, not a node) wears Featured, and a
 * RULE key wears Featured (no class: rule wear delivers inside the
 * rule's own @scope). SelectorMatcherTest carries the same map.
 */
export const MAP = {
  'block:t1': { variants: [{ variant: 'featured', fragment: '', via: null }], default: { color: 'red' } },
  'block:t6:body': { variants: [{ variant: 'featured', fragment: '' }] },
  'block:t10': {
    variants: [
      { variant: 'card', fragment: 'region(first)/block(text,1)', via: 'card' },
      { variant: 'bad name', fragment: '' },
    ],
  },
  'block:t11': { variants: [{ variant: 'featured', fragment: '' }] },
  'container:sec-h': { variants: [{ variant: 'featured', fragment: '' }] },
  'container:sec-g::> d-text': { variants: [{ variant: 'featured', fragment: '' }], default: { margin: '0' } },
};
