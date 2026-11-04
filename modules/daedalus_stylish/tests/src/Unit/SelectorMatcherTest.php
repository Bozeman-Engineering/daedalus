<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Unit;

use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_stylish\Selector\SelectorMatcher;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The selector matcher over a fixture document, and its client twin.
 *
 * The fixture is the document tree (NodeTreeBuilder::document()'s
 * shape: one entity node, the host above its sections, the delivered
 * blueprint's `layout` as its children), built by hand: the page
 * "Welcome" (uuid `page`, a tagless entity markup, as the annotator
 * usually stamps it) holding a container section with two text blocks
 * (a paragraph root whose prose links and emphasizes, a heading root), a
 * formatted text block (a div root holding paragraphs and links, three
 * minted instances: a paragraph, a heading, a list carrying a list
 * item), a nested section (a text block, an image block, an empty nested
 * section) and a plugin block; a two-column section holding a text block
 * alone in its first region and two in its second, the last an open
 * render whose body field links and carries a minted paragraph; a
 * container section holding a heading-rooted text
 * block whose anatomy is declared (a link part rendering an anchor with
 * a nested icon part, a note part declaring no tag, a hoisted part) and
 * a nested section holding a paragraph text block with a link part; a
 * container section holding an ordered section (two paragraph text blocks,
 * the second with a link part, a heading text block wearing a hoisted
 * class, an image block) and a nested section holding an image block.
 * The position qualifiers (testPositions) are judged by node (`> *`, a
 * type, the implied subject of `:not()`, the qualified anchor against
 * the owner), with the `-of-type` forms over the siblings sharing the
 * root tag and the An+B forms over the 1-based position; a position on
 * a tag compound is reach-neutral. `:is()` (testIs) holds when any
 * member compound matches the node, each member judged as `:not()`'s
 * compound is. The entity root (testEntityRoot) is
 * the farthest ancestor, the owner whose `> *` is the sections, keyless
 * (structure nothing addresses) when the host has no uuid. A block's
 * field (testFieldRecords) is a side-table record inside its block:
 * reached through the block's place, never a node a rule targets.
 * The client twin (js/src/selector-matcher.js) is
 * locked to this class by the battery testClientTwinEquivalence pipes
 * through tests/js/selector-matcher-cases.mjs, the RelativeSelectorTest
 * pattern; tests/js/selector-matcher-fixture.mjs carries the same
 * documents for the client's own spec.
 */
#[Group('daedalus_stylish')]
class SelectorMatcherTest extends UnitTestCase {

  use NodeSuitesTrait;

  /**
   * The node markup an annotation carries: the root tag, the tags inside.
   */
  protected static function markup(string $tag, ?array $tags = NULL): array {
    return ['markup' => $tags === NULL ? ['tag' => $tag] : ['tag' => $tag, 'tags' => $tags]];
  }

  /**
   * The fixture document: the page "Welcome" above its four sections.
   */
  protected static function tree(): array {
    return [
      [
        'kind' => 'entity',
        'uuid' => 'page',
        '_element' => ['name' => 'Welcome', 'type' => 'Page', 'icon' => '/icons/page.svg', 'markup' => []],
        'children' => static::layout(),
      ],
    ];
  }

  /**
   * A second, small document whose root carries no uuid.
   *
   * A template host or the site frame: a section holding a paragraph
   * text block, and an empty section. The root is structure nothing
   * addresses.
   */
  protected static function keyless(): array {
    $markup = static::markup(...);
    return [
      [
        'kind' => 'entity',
        'uuid' => NULL,
        '_element' => ['name' => 'Template', 'type' => 'Template', 'icon' => NULL, 'markup' => []],
        'children' => [
          [
            'kind' => 'container',
            'uuid' => 'sec-x',
            'layout_id' => 'daedalus_stylish_container',
            '_element' => $markup('section'),
            'children' => [
              ['kind' => 'block', 'uuid' => 'tx', 'block_type' => 'text', '_element' => $markup('p', ['a'])],
            ],
          ],
          [
            'kind' => 'container',
            'uuid' => 'sec-y',
            'layout_id' => 'daedalus_stylish_container',
            '_element' => $markup('div'),
            'children' => [],
          ],
        ],
      ],
    ];
  }

  /**
   * A rootless document whose open render annotates three fields.
   *
   * One with prose (a record with its anatomy), one presenting as a unit
   * (a record with an empty bag: it exists), one with no element in any
   * view (`element: false`: no record); beside it a keyless block, still
   * a node (it counts among the siblings) but carrying no records.
   */
  protected static function openRender(): array {
    return [
      [
        'kind' => 'container',
        'uuid' => 'sec-o',
        'layout_id' => 'daedalus_stylish_container',
        '_element' => static::markup('section'),
        'children' => [
          [
            'kind' => 'block',
            'uuid' => 'o1',
            'block_type' => 'text',
            '_element' => [
              'markup' => ['tag' => 'div'],
              'fields' => [
                'body' => [
                  'markup' => [
                    'tags' => ['em'],
                    'instances' => [['class' => 's-o1', 'tag' => 'p', 'label' => 'Paragraph', 'children' => []]],
                  ],
                ],
                'title' => ['presentation' => 'unit'],
                'hidden' => ['element' => FALSE],
              ],
            ],
          ],
          [
            'kind' => 'block',
            'uuid' => NULL,
            'block_type' => 'text',
            '_element' => ['fields' => ['body' => ['markup' => ['tags' => ['p']]]]],
          ],
        ],
      ],
    ];
  }

  /**
   * The blueprint's `layout`: the sections, as the serializer emits them.
   */
  protected static function layout(): array {
    $markup = static::markup(...);
    return [
      [
        'kind' => 'container',
        'uuid' => 'sec-a',
        'layout_id' => 'daedalus_stylish_container',
        'label' => 'Hero',
        // The annotator's minted words ride the node beside its markup.
        '_element' => ['name' => 'Hero', 'type' => 'Section', 'icon' => '/icons/section.svg'] + $markup('section'),
        'children' => [
          [
            'kind' => 'block',
            'uuid' => 't1',
            'block_type' => 'text',
            'label' => 'Intro',
            '_element' => $markup('p', ['a', 'strong']),
          ],
          ['kind' => 'block', 'uuid' => 't2', 'block_type' => 'text', '_element' => $markup('h2')],
          [
            'kind' => 'block',
            'uuid' => 'f',
            'block_type' => 'formatted_text',
            '_element' => [
              'markup' => [
                'tag' => 'div',
                'tags' => ['p', 'a'],
                'instances' => [
                  ['class' => 's-p1', 'tag' => 'p', 'label' => 'Paragraph', 'children' => []],
                  ['class' => 's-h1', 'tag' => 'h2', 'label' => 'Heading 2', 'children' => []],
                  [
                    'class' => 's-ul',
                    'tag' => 'ul',
                    'label' => 'Bulleted list',
                    'children' => [['class' => 's-li', 'tag' => 'li', 'label' => 'List item', 'children' => []]],
                  ],
                ],
              ],
            ],
          ],
          [
            'kind' => 'container',
            'uuid' => 'sec-b',
            'layout_id' => 'daedalus_stylish_container',
            '_element' => $markup('div'),
            'children' => [
              ['kind' => 'block', 'uuid' => 't3', 'block_type' => 'text', '_element' => $markup('p', ['em'])],
              ['kind' => 'block', 'uuid' => 'img', 'block_type' => 'image', '_element' => $markup('figure')],
              [
                'kind' => 'container',
                'uuid' => 'sec-d',
                'layout_id' => 'daedalus_stylish_container',
                '_element' => $markup('div'),
                'children' => [],
              ],
            ],
          ],
          ['kind' => 'block', 'uuid' => 'p', 'plugin_id' => 'system_menu_block:main'],
        ],
      ],
      [
        'kind' => 'container',
        'uuid' => 'sec-c',
        'layout_id' => 'layout_twocol_section',
        '_element' => $markup('div'),
        'children' => [
          [
            'kind' => 'region',
            'region' => 'first',
            'children' => [['kind' => 'block', 'uuid' => 't4', 'block_type' => 'text', '_element' => $markup('p')]],
          ],
          [
            'kind' => 'region',
            'region' => 'second',
            'children' => [
              ['kind' => 'block', 'uuid' => 't5', 'block_type' => 'text', '_element' => $markup('p')],
              [
                'kind' => 'block',
                'uuid' => 't6',
                'block_type' => 'text',
                '_element' => [
                  'markup' => ['tag' => 'h3'],
                  'fields' => [
                    'body' => [
                      'markup' => [
                        'tags' => ['a'],
                        'instances' => [['class' => 's-q1', 'tag' => 'p', 'label' => 'Paragraph', 'children' => []]],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      [
        'kind' => 'container',
        'uuid' => 'sec-e',
        'layout_id' => 'daedalus_stylish_container',
        'label' => 'Links',
        '_element' => $markup('section'),
        'children' => [
          [
            'kind' => 'block',
            'uuid' => 't7',
            'block_type' => 'text',
            'label' => 'Call',
            '_element' => [
              'markup' => [
                'tag' => 'h6',
                'hoisted' => ['.text-block'],
                'parts' => [
                  '.text-block-link' => [
                    'tag' => 'a',
                    'parts' => ['.text-block-link__icon' => ['tag' => 'svg', 'parts' => []]],
                  ],
                  '.text-block-note' => ['tag' => NULL, 'parts' => []],
                ],
              ],
            ],
          ],
          [
            'kind' => 'container',
            'uuid' => 'sec-f',
            'layout_id' => 'daedalus_stylish_container',
            '_element' => $markup('div'),
            'children' => [
              [
                'kind' => 'block',
                'uuid' => 't8',
                'block_type' => 'text',
                '_element' => [
                  'markup' => ['tag' => 'p', 'tags' => ['strong'], 'parts' => ['.text-block-link' => ['tag' => 'a']]],
                ],
              ],
            ],
          ],
        ],
      ],
      [
        'kind' => 'container',
        'uuid' => 'sec-g',
        'layout_id' => 'daedalus_stylish_container',
        'label' => 'Section',
        '_element' => $markup('section'),
        'children' => [
          ['kind' => 'block', 'uuid' => 't9', 'block_type' => 'text', '_element' => $markup('p')],
          [
            'kind' => 'block',
            'uuid' => 't10',
            'block_type' => 'text',
            '_element' => ['markup' => ['tag' => 'p', 'parts' => ['.text-block-link' => ['tag' => 'a']]]],
          ],
          [
            'kind' => 'block',
            'uuid' => 't11',
            'block_type' => 'text',
            '_element' => ['markup' => ['tag' => 'h3', 'hoisted' => ['.featured']]],
          ],
          ['kind' => 'block', 'uuid' => 'img2', 'block_type' => 'image', '_element' => $markup('figure')],
          [
            'kind' => 'container',
            'uuid' => 'sec-h',
            'layout_id' => 'daedalus_stylish_container',
            '_element' => $markup('div'),
            'children' => [
              ['kind' => 'block', 'uuid' => 'img3', 'block_type' => 'image', '_element' => $markup('figure')],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * The style map the tree is styled by, for the wear.
   *
   * The hero's first text block and the Section's heading wear Featured
   * (the root fragment's plain variant class), the Section's second
   * paragraph wears a distributed fragment of Card (the slot-encoded
   * class) beside a reference that cannot be a class name (stamps
   * nothing), the nested section H wears Featured too, the open render's
   * body field (`block:t6:body`, a field record, not a node) wears
   * Featured, and a RULE key wears Featured (no class: rule wear
   * delivers inside the rule's own scope block).
   * tests/js/selector-matcher-fixture.mjs carries the same map.
   */
  protected static function map(): array {
    return [
      'block:t1' => [
        'variants' => [['variant' => 'featured', 'fragment' => '', 'via' => NULL]],
        'default' => ['color' => 'red'],
      ],
      'block:t6:body' => ['variants' => [['variant' => 'featured', 'fragment' => '']]],
      'block:t10' => [
        'variants' => [
          ['variant' => 'card', 'fragment' => 'region(first)/block(text,1)', 'via' => 'card'],
          ['variant' => 'bad name', 'fragment' => ''],
        ],
      ],
      'block:t11' => ['variants' => [['variant' => 'featured', 'fragment' => '']]],
      'container:sec-h' => ['variants' => [['variant' => 'featured', 'fragment' => '']]],
      'container:sec-g::> d-text' => [
        'variants' => [['variant' => 'featured', 'fragment' => '']],
        'default' => ['margin' => '0'],
      ],
    ];
  }

  /**
   * The tree facts: key, kind, address, tag, the tags inside, parts, place.
   */
  public function testFacts(): void {
    $matcher = SelectorMatcher::fromTree(static::tree());
    $this->assertSame([
      'key' => 'block:t3',
      'kind' => 'block',
      'address' => 'block_type:text',
      'tag' => 'p',
      'tags' => ['em'],
      'parts' => [],
      'wear' => [],
      'name' => NULL,
      'type' => NULL,
      'icon' => NULL,
      'index' => 0,
      'count' => 3,
      'childCount' => 0,
      'parent' => 'container:sec-b',
    ], $matcher->facts('block:t3'));
    $this->assertSame([
      'key' => 'container:sec-a',
      'kind' => 'container',
      'address' => 'layout:daedalus_stylish_container',
      'tag' => 'section',
      'tags' => [],
      'parts' => [],
      'wear' => [],
      'name' => 'Hero',
      'type' => 'Section',
      'icon' => '/icons/section.svg',
      'index' => 0,
      'count' => 4,
      'childCount' => 5,
      'parent' => 'entity:page',
    ], $matcher->facts('container:sec-a'));
    // The anatomy: the declared part tree flattened (a nested part right
    // after its parent, with its own tag), the hoisted part at the root's
    // tag, the tagless part kept with NULL; every part tag joins the tags
    // inside, so the heading-rooted block carries an anchor.
    $this->assertSame([
      'key' => 'block:t7',
      'kind' => 'block',
      'address' => 'block_type:text',
      'tag' => 'h6',
      'tags' => ['a', 'svg', 'h6'],
      'parts' => [
        '.text-block-link' => 'a',
        '.text-block-link__icon' => 'svg',
        '.text-block-note' => NULL,
        '.text-block' => 'h6',
      ],
      'wear' => [],
      'name' => NULL,
      'type' => NULL,
      'icon' => NULL,
      'index' => 0,
      'count' => 2,
      'childCount' => 0,
      'parent' => 'container:sec-e',
    ], $matcher->facts('block:t7'));
    $this->assertSame(['strong', 'a'], $matcher->facts('block:t8')['tags']);
    $this->assertSame(['.text-block-link' => 'a'], $matcher->facts('block:t8')['parts']);
    // The identity: the minted instances the rich text carries fold into
    // the parts as class => tag, a nested carrier right after its parent,
    // and their tags join the tags inside (the heading and the list are
    // known only through their instances here).
    $this->assertSame(['.s-p1' => 'p', '.s-h1' => 'h2', '.s-ul' => 'ul', '.s-li' => 'li'], $matcher->facts('block:f')['parts']);
    $this->assertSame(['p', 'a', 'h2', 'ul', 'li'], $matcher->facts('block:f')['tags']);
    // A plugin block's address strips the derivative; unannotated, it has
    // no tag. An open render's field tags and field instances count as
    // inside the block. A region wrapper is a real sibling level,
    // transparent as a parent.
    $this->assertSame('block_plugin:system_menu_block', $matcher->facts('block:p')['address']);
    $this->assertNull($matcher->facts('block:p')['tag']);
    // A field node names the page's field, never a per-bundle plugin id:
    // its address is the field_block base plugin, read off the node alone.
    $fielded = SelectorMatcher::fromTree([
      [
        'kind' => 'container',
        'uuid' => 'sec-f',
        'layout_id' => 'daedalus_stylish_container',
        'children' => [['kind' => 'block', 'uuid' => 'fb', 'field' => 'body']],
      ],
    ]);
    $this->assertSame('block_plugin:field_block', $fielded->facts('block:fb')['address']);
    $this->assertSame(['a', 'p'], $matcher->facts('block:t6')['tags']);
    $this->assertSame(['.s-q1' => 'p'], $matcher->facts('block:t6')['parts']);
    $this->assertSame('container:sec-c', $matcher->facts('block:t4')['parent']);
    $this->assertSame(1, $matcher->facts('block:t4')['count']);
    $this->assertNull($matcher->facts('block:ghost'));
    $this->assertTrue($matcher->has('container:sec-d'));
    $this->assertFalse($matcher->has('entity:root'));
    // Ancestors farthest first, the entity farthest; regions transparent.
    $this->assertSame(['entity:page', 'container:sec-a', 'container:sec-b'], $matcher->ancestors('block:t3'));
    $this->assertSame(['entity:page', 'container:sec-c'], $matcher->ancestors('block:t5'));
    $this->assertSame(['entity:page'], $matcher->ancestors('container:sec-a'));
    $this->assertSame([], $matcher->ancestors('entity:page'));
    $this->assertSame([], $matcher->ancestors('block:ghost'));
  }

  /**
   * The entity root: the one root, the bare kind, its own markup alone.
   */
  public function testEntityRoot(): void {
    $matcher = SelectorMatcher::fromTree(static::tree(), static::map());
    $page = 'entity:page';
    $this->assertTrue($matcher->has($page));
    // The root's facts: the entity kind's address, the minted words, a
    // tagless markup (no base tag, nothing inside), the sections its
    // children, nothing above it.
    $this->assertSame([
      'key' => $page,
      'kind' => 'entity',
      'address' => 'kind:entity',
      'tag' => NULL,
      'tags' => [],
      'parts' => [],
      'wear' => [],
      'name' => 'Welcome',
      'type' => 'Page',
      'icon' => '/icons/page.svg',
      'index' => 0,
      'count' => 1,
      'childCount' => 4,
      'parent' => NULL,
    ], $matcher->facts($page));
    // The host's fields render outside the canvas root: a root annotated
    // with field markup folds none of it into its bag.
    $fielded = SelectorMatcher::fromTree([
      [
        'kind' => 'entity',
        'uuid' => 'host',
        '_element' => [
          'markup' => ['tag' => 'article', 'tags' => ['span']],
          'fields' => [
            'title' => [
              'markup' => ['tags' => ['h1'], 'instances' => [['class' => 's-t1', 'tag' => 'h1', 'children' => []]]],
            ],
          ],
        ],
        'children' => [],
      ],
    ]);
    $this->assertSame(['span'], $fielded->facts('entity:host')['tags']);
    $this->assertSame([], $fielded->facts('entity:host')['parts']);
    $this->assertSame('article', $fielded->facts('entity:host')['tag']);
    // The ancestor walk ends at the entity (farthest first), so the stack
    // layers the page's rules beneath every section's.
    $this->assertSame([$page, 'container:sec-a'], $matcher->ancestors('block:t1'));
    // The page's `p` rule reaches the formatted text block's tag tier and
    // its minted paragraph (the symptom this node exists for), never the
    // block's div root; by the chain through its first section too; a
    // `>` into the tag is never backed by the tree (the paragraph is
    // inside the block, not the section's child).
    $this->assertTrue($matcher->reaches($page, 'p', 'block:f', 'p'));
    $this->assertTrue($matcher->reaches($page, 'p', 'block:f', '.s-p1'));
    $this->assertFalse($matcher->reaches($page, 'p', 'block:f'));
    $this->assertTrue($matcher->reaches($page, 'p', 'block:t1'));
    $this->assertTrue($matcher->reaches($page, '> d-container p', 'block:f', '.s-p1'));
    $this->assertFalse($matcher->reaches($page, '> d-container > d-container p', 'block:f', '.s-p1'));
    $this->assertFalse($matcher->reaches($page, '> d-container > p', 'block:f', '.s-p1'));
    $this->assertTrue($matcher->reaches($page, '> d-container > p', 'block:t1'));
    $this->assertTrue($matcher->reaches($page, 'p', 'block:t6', '.s-q1'));
    // The sections are the root's children, in order; a position
    // qualifier on a top-level section reads its place among them.
    $sections = ['container:sec-a', 'container:sec-c', 'container:sec-e', 'container:sec-g'];
    $this->assertSame($sections, $matcher->matching($page, '> *'));
    $this->assertSame($sections, $matcher->matching($page, '> d-container'));
    $this->assertSame(['container:sec-a', 'container:sec-e', 'container:sec-g'], $matcher->matching($page, '> section'));
    $this->assertSame(['block:t1', 'block:t2', 'block:t7', 'block:t9', 'block:t10', 'block:t11'], $matcher->matching($page, '> * > d-text'));
    $this->assertTrue($matcher->matches($page, '> *:first-child', 'container:sec-a'));
    $this->assertFalse($matcher->matches($page, '> *:first-child', 'container:sec-g'));
    $this->assertTrue($matcher->matches($page, '> *:last-child', 'container:sec-g'));
    $this->assertSame(['container:sec-c', 'container:sec-g'], $matcher->matching($page, '> *:nth-child(even)'));
    $this->assertSame($sections, $matcher->matching($page, '&:only-child > *'));
    // The entity is no section: a section's `d-container` prefix never
    // matches it, no owner below it has it in scope, and it is never its
    // own child.
    $this->assertFalse($matcher->matches('container:sec-a', 'd-container', $page));
    $this->assertNotContains($page, $matcher->matching('container:sec-a', 'd-container > *'));
    $this->assertFalse($matcher->matches($page, '> *', $page));
    // A keyless root is structure nothing addresses: no key, no
    // ancestor, the sections still its children.
    $keyless = SelectorMatcher::fromTree(static::keyless());
    $this->assertFalse($keyless->has('entity:null'));
    $this->assertFalse($keyless->has('entity:'));
    $this->assertNull($keyless->facts('container:sec-x')['parent']);
    $this->assertSame(['container:sec-x'], $keyless->ancestors('block:tx'));
    $this->assertSame([], $keyless->ancestors('container:sec-x'));
    $this->assertSame(['container:sec-y'], $keyless->matching('container:sec-y', '&:last-child'));
    $this->assertSame(['block:tx'], $keyless->matching('container:sec-x', '&:first-child > *'));
    $this->assertTrue($keyless->matches('container:sec-x', 'p', 'block:tx'));
  }

  /**
   * A block's field: a side-table record inside its block, never a node.
   */
  public function testFieldRecords(): void {
    $matcher = SelectorMatcher::fromTree(static::tree(), static::map());
    $body = 'block:t6:body';
    $c = 'container:sec-c';
    // The open render's body field is a record: has() answers it, facts()
    // the field's own anatomy (the tags and parts of the field's markup
    // alone, the field root's stamp as its address, no tag, no words, no
    // place, its wear from the map, its block as parent).
    $this->assertTrue($matcher->has($body));
    $this->assertSame([
      'key' => $body,
      'kind' => 'field',
      'address' => 'kind:field',
      'tag' => NULL,
      'tags' => ['a', 'p'],
      'parts' => ['.s-q1' => 'p'],
      'wear' => ['daedalus-stylish-variant--featured'],
      'name' => NULL,
      'type' => NULL,
      'icon' => NULL,
      'index' => NULL,
      'count' => NULL,
      'childCount' => 0,
      'parent' => 'block:t6',
    ], $matcher->facts($body));
    $this->assertSame([], SelectorMatcher::fromTree(static::tree())->facts($body)['wear']);
    // The block keeps folding its fields' markup into its own bag: the
    // record sits beside the fold, it does not replace it.
    $this->assertSame(['a', 'p'], $matcher->facts('block:t6')['tags']);
    $this->assertSame(['.s-q1' => 'p'], $matcher->facts('block:t6')['parts']);
    // The field's ancestors are the block's ancestors plus the block,
    // farthest first: its rows inherit the block's rules and its
    // ancestors'.
    $this->assertSame(['entity:page', $c, 'block:t6'], $matcher->ancestors($body));
    // reaches() judges the field's tags and parts with the block's node
    // as its place: the block's own `p` rule lands on the field's minted
    // paragraph, a section's `p` on the field's tag tier (and the
    // page's), a chain through the region to the block then by descent,
    // never a chain the block does not satisfy, never from out of scope,
    // never the root or its box (a field is not an element a rule
    // targets).
    $this->assertTrue($matcher->reaches('block:t6', 'p', $body, '.s-q1'));
    $this->assertTrue($matcher->reaches($c, 'p', $body, 'p'));
    $this->assertTrue($matcher->reaches($c, 'a', $body, 'a'));
    $this->assertTrue($matcher->reaches('entity:page', 'p', $body, '.s-q1'));
    $this->assertTrue($matcher->reaches($c, '> * > d-text p', $body, '.s-q1'));
    $this->assertTrue($matcher->reaches($c, '> * > d-text .s-q1', $body, '.s-q1'));
    $this->assertFalse($matcher->reaches($c, '> d-text p', $body, 'p'));
    $this->assertFalse($matcher->reaches($c, 'em', $body, 'em'));
    $this->assertFalse($matcher->reaches('container:sec-a', 'p', $body, 'p'));
    $this->assertFalse($matcher->reaches($c, 'p', $body));
    $this->assertFalse($matcher->reaches('block:t6', '&::before', $body, '&::before'));
    // Never in the matching universe: the block's children are unchanged
    // by the record, no owner lists or targets it, and as an owner it
    // targets nothing.
    $this->assertSame([], $matcher->matching('block:t6', '> *'));
    $this->assertSame(['block:t4', 'block:t5', 'block:t6'], $matcher->matching($c, '> * > *'));
    $this->assertSame(['block:t4', 'block:t5', 'block:t6'], $matcher->matching($c, 'd-text'));
    $this->assertFalse($matcher->matches($c, '> *', $body));
    $this->assertFalse($matcher->matches($c, '> * > *', $body));
    $this->assertFalse($matcher->matches($c, 'p', $body));
    $this->assertSame([], $matcher->matching($body, '> *'));
    $this->assertFalse($matcher->matches($body, 'p', $body));
    $this->assertFalse($matcher->reaches($body, 'p', $body, 'p'));
    // A field the block does not annotate, a block annotating no fields,
    // and a malformed key are nothing.
    $this->assertFalse($matcher->has('block:t6:title'));
    $this->assertFalse($matcher->has('block:t1:body'));
    $this->assertFalse($matcher->has('block:t6:'));
    $this->assertNull($matcher->facts('block:t6:title'));
    $this->assertSame([], $matcher->ancestors('block:t6:title'));
    // The open render document: a prose field's record, a unit field's
    // empty record, no record for a field with no element, none for a
    // keyless block (still a node among the siblings).
    $open = SelectorMatcher::fromTree(static::openRender());
    $this->assertTrue($open->has('block:o1:body'));
    $this->assertTrue($open->has('block:o1:title'));
    $this->assertFalse($open->has('block:o1:hidden'));
    $this->assertFalse($open->has('block:o1:ghost'));
    $this->assertFalse($open->has('block:null:body'));
    $this->assertFalse($open->has('block::body'));
    $this->assertSame(['em', 'p'], $open->facts('block:o1:body')['tags']);
    $this->assertSame(['.s-o1' => 'p'], $open->facts('block:o1:body')['parts']);
    $this->assertSame([], $open->facts('block:o1:title')['tags']);
    $this->assertSame([], $open->facts('block:o1:title')['parts']);
    $this->assertSame('block:o1', $open->facts('block:o1:title')['parent']);
    $this->assertSame(['em', 'p'], $open->facts('block:o1')['tags']);
    $this->assertSame(2, $open->facts('block:o1')['count']);
    $this->assertSame(['container:sec-o', 'block:o1'], $open->ancestors('block:o1:body'));
    $this->assertTrue($open->reaches('container:sec-o', 'em', 'block:o1:body', 'em'));
    $this->assertTrue($open->reaches('container:sec-o', 'p', 'block:o1:body', '.s-o1'));
    $this->assertFalse($open->reaches('container:sec-o', 'p', 'block:o1:title', 'p'));
    $this->assertFalse($open->reaches('block:o1', '> d-text p', 'block:o1:body', 'p'));
    $this->assertSame(['block:o1'], $open->matching('container:sec-o', '> *'));
    $this->assertSame([], $open->matching('container:sec-o', '> *:last-child'));
  }

  /**
   * Child and descendant combinators, type and tag compounds, regions.
   */
  public function testMatching(): void {
    $matcher = SelectorMatcher::fromTree(static::tree());
    $a = 'container:sec-a';
    // The child combinator anchors to the owner; a type or tag filters it.
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:p'], $matcher->matching($a, '> *'));
    $this->assertSame(['block:t1', 'block:t2'], $matcher->matching($a, '> d-text'));
    $this->assertSame(['block:t1'], $matcher->matching($a, '> p'));
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'block:p'], $matcher->matching($a, '> d-block'));
    $this->assertSame(['container:sec-b'], $matcher->matching($a, '> d-container'));
    $this->assertSame(['block:t3', 'block:img', 'container:sec-d'], $matcher->matching($a, '> * > *'));
    $this->assertSame(['block:t3'], $matcher->matching($a, RelativeSelector::fromString('> d-container > d-text')));
    // Descendant compounds reach the whole subtree; the owner is in scope
    // for a non-subject compound (`d-container > *` prints without
    // :scope, so the owner's own children are targets).
    $this->assertSame(['block:t1', 'block:t2', 'block:t3'], $matcher->matching($a, 'd-text'));
    $this->assertSame(['block:t1', 'block:t3'], $matcher->matching($a, 'p'));
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'block:t3', 'block:img', 'block:p'], $matcher->matching($a, 'd-block'));
    $this->assertSame([
      'block:t1',
      'block:t2',
      'block:f',
      'container:sec-b',
      'block:t3',
      'block:img',
      'container:sec-d',
      'block:p',
    ], $matcher->matching($a, 'd-container > *'));
    $this->assertTrue($matcher->matches($a, 'd-container', $a));
    $this->assertFalse($matcher->matches($a, '> d-text', 'block:t3'));
    $this->assertTrue($matcher->matches('container:sec-b', '> d-text', 'block:t3'));
    $this->assertFalse($matcher->matches('block:t1', '> *', 'block:t2'));
    $this->assertFalse($matcher->matches('block:ghost', '> *', 'block:t2'));
    // Region wrappers are the direct children of a multi-region section.
    $this->assertSame([], $matcher->matching('container:sec-c', '> *'));
    $this->assertSame([], $matcher->matching('container:sec-c', '> d-text'));
    $this->assertSame(['block:t4', 'block:t5', 'block:t6'], $matcher->matching('container:sec-c', '> * > *'));
    $this->assertSame(['block:t4', 'block:t5', 'block:t6'], $matcher->matching('container:sec-c', 'd-text'));
    // A part class is not a tree fact: it matches no node, and a tag
    // subject a part renders matches no ROOT either (the part is inside).
    $this->assertSame([], $matcher->matching($a, '.cls'));
    $this->assertSame([], $matcher->matching($a, '.cls > *'));
    $this->assertSame([], $matcher->matching('container:sec-e', '> d-text .text-block-link'));
    $this->assertSame([], $matcher->matching('container:sec-e', 'a'));
    $this->assertSame(['block:t7', 'container:sec-f'], $matcher->matching('container:sec-e', '> *'));
  }

  /**
   * Reaches: the root, a tag inside, a part after a type or by its tag.
   */
  public function testReachesAndFacts(): void {
    $matcher = SelectorMatcher::fromTree(static::tree());
    $a = 'container:sec-a';
    $e = 'container:sec-e';
    $this->assertTrue($matcher->reaches($a, 'p', 'block:t1'));
    $this->assertFalse($matcher->reaches($a, 'p', 'block:f'));
    $this->assertTrue($matcher->reaches($a, 'p', 'block:f', 'p'));
    // A `>` into the tag cannot be backed by the tree.
    $this->assertFalse($matcher->reaches($a, '> p', 'block:f', 'p'));
    $this->assertTrue($matcher->reaches($a, 'a', 'block:t1', 'a'));
    $this->assertFalse($matcher->reaches($a, 'a', 'block:t2', 'a'));
    $this->assertTrue($matcher->reaches($a, 'd-text a', 'block:t1', 'a'));
    $this->assertFalse($matcher->reaches($a, '> d-text em', 'block:t3', 'em'));
    $this->assertTrue($matcher->reaches($a, 'd-text em', 'block:t3', 'em'));
    $this->assertTrue($matcher->reaches($a, 'd-formatted-text p', 'block:f', 'p'));
    $this->assertFalse($matcher->reaches('container:sec-b', 'p', 'block:f', 'p'));
    $this->assertTrue($matcher->reaches('container:sec-c', 'a', 'block:t6', 'a'));
    // A part after a type: the class subject reaches the part of the
    // element the type matches (a direct child by `>`, a nested one by
    // descent); a lone class is the owner's own anatomy, never a part
    // of a descendant.
    $this->assertTrue($matcher->reaches($e, '> d-text .text-block-link', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> d-text .text-block-link', 'block:t8', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, 'd-text > .text-block-link', 'block:t8', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '.text-block-link', 'block:t7', '.text-block-link'));
    // A part by its tag: the container's `a` rule reaches the link part
    // (the anchor it renders) by descent, from a prefix matching the
    // element or an ancestor; never through a child combinator (the
    // annotation carries no depth under a root); never a part declaring
    // no tag (unknown is not a match); a nested part and a hoisted part
    // are parts like any other; the rule must scope the element.
    $this->assertTrue($matcher->reaches($e, 'a', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> a', 'block:t7', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, '> d-text a', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> d-text > a', 'block:t7', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, 'a', 'block:t8', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> d-text a', 'block:t8', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, 'd-container a', 'block:t8', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, '> d-container a', 'block:t8', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> d-container a', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches('container:sec-f', 'a', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, 'small', 'block:t7', '.text-block-note'));
    $this->assertFalse($matcher->reaches($e, 'a', 'block:t7', '.text-block-note'));
    $this->assertFalse($matcher->reaches($e, 'svg', 'block:t7', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, 'svg', 'block:t7', '.text-block-link__icon'));
    $this->assertTrue($matcher->reaches($e, 'h6', 'block:t7', '.text-block'));
    $this->assertFalse($matcher->reaches($e, 'a', 'block:t7', '.ghost'));
    // The tag tier counts the parts' tags beside the prose's.
    $this->assertTrue($matcher->reaches($e, 'a', 'block:t7', 'a'));
    $this->assertTrue($matcher->reaches($e, 'd-text a', 'block:t8', 'a'));
    $this->assertFalse($matcher->reaches($e, '> d-text a', 'block:t8', 'a'));
    $this->assertFalse($matcher->reaches($e, 'em', 'block:t7', 'em'));
    $this->assertFalse($matcher->reaches($a, '> *', 'block:t1', '> *'));
    // The identity: a minted instance is reached by the tag it is, by
    // descent, exactly as a declared part is (the nested list item by
    // its own tag, an open render's field instance too); a class subject
    // that is an identity class reaches only the node whose parts carry
    // it, never another node the type matches (the class is worn by one
    // element in the world) and never a class the node lacks, while a
    // declared class keeps the anatomy posture: `> d-text
    // .text-block-link` reaches a text block that renders no link. The
    // tree still matches no class in a chain; `:not()` judges it on the
    // node's own parts, an identity class included.
    $this->assertTrue($matcher->reaches($a, 'p', 'block:f', '.s-p1'));
    $this->assertTrue($matcher->reaches($a, 'd-formatted-text p', 'block:f', '.s-p1'));
    $this->assertFalse($matcher->reaches($a, 'h2', 'block:f', '.s-p1'));
    $this->assertTrue($matcher->reaches($a, 'li', 'block:f', '.s-li'));
    $this->assertFalse($matcher->reaches($a, 'ul', 'block:f', '.s-li'));
    $this->assertTrue($matcher->reaches('container:sec-c', 'p', 'block:t6', '.s-q1'));
    $this->assertTrue($matcher->reaches($a, '> d-formatted-text .s-p1', 'block:f', '.s-p1'));
    $this->assertFalse($matcher->reaches($a, '> d-formatted-text .s-p1', 'block:t1', '.s-p1'));
    $this->assertFalse($matcher->reaches($a, '> d-block .s-p1', 'block:t1', '.s-p1'));
    $this->assertFalse($matcher->reaches($a, '> d-formatted-text .s-p9', 'block:f', '.s-p9'));
    $this->assertTrue($matcher->reaches($a, '> d-formatted-text .s-li', 'block:f', '.s-li'));
    $this->assertTrue($matcher->reaches($a, '> d-text .text-block-link', 'block:t2', '.text-block-link'));
    $this->assertSame([], $matcher->matching($a, '> d-formatted-text .s-p1'));
    $this->assertSame(['block:t1', 'block:t2', 'block:p'], $matcher->matching($a, '> d-block:not(.s-p1)'));
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'block:p'], $matcher->matching($a, '> d-block:not(.s-p9)'));

    $this->assertTrue(SelectorMatcher::typeMatches('d-container', 'layout:x'));
    $this->assertTrue(SelectorMatcher::typeMatches('d-block', 'block_plugin:x'));
    $this->assertFalse(SelectorMatcher::typeMatches('d-block', 'layout:x'));
    $this->assertTrue(SelectorMatcher::typeMatches('d-formatted-text', 'block_type:formatted_text'));

    $this->expectException(\InvalidArgumentException::class);
    $matcher->matching($a, 'p:has(:has(a))');
  }

  /**
   * Sibling steps, `:has()` with every lead, `:not()`, and the reach limits.
   */
  public function testRelations(): void {
    $matcher = SelectorMatcher::fromTree(static::tree());
    $g = 'container:sec-g';
    // A sibling combinator steps along the parent's ordered children:
    // `+` the previous sibling, `~` any earlier one; the section's second
    // paragraph is the only p-root after a p-root, the heading is the
    // next sibling of a p-root too.
    $this->assertSame(['block:t10'], $matcher->matching($g, '> p + p'));
    $this->assertSame(['block:t10'], $matcher->matching($g, '> p ~ p'));
    $this->assertSame(['block:t10', 'block:t11'], $matcher->matching($g, '> p + *'));
    $this->assertSame(['block:t10', 'block:t11', 'block:img2', 'container:sec-h'], $matcher->matching($g, '> p ~ *'));
    $this->assertSame(['block:img2'], $matcher->matching($g, 'd-text ~ d-image'));
    $this->assertTrue($matcher->matches($g, '> p + p', 'block:t10'));
    $this->assertFalse($matcher->matches($g, '> p + p', 'block:t9'));
    // `:has()` anchors its argument at the qualified node: a tag by
    // descent reads the tag bag (the linked block's part renders an
    // anchor), a child lead a child (the bag has no depth, so `> a` on
    // a block is never backed), `+` the next sibling (the heading is the
    // text block right before the image), `~` any later one.
    $this->assertSame(['block:t10'], $matcher->matching($g, '> d-text:has(a)'));
    $this->assertSame([], $matcher->matching($g, '> d-text:has(> a)'));
    $this->assertSame(['block:t11'], $matcher->matching($g, '> d-text:has(+ d-image)'));
    $this->assertSame(['block:t9', 'block:t10', 'block:t11'], $matcher->matching($g, '> d-text:has(~ d-image)'));
    $this->assertSame(['container:sec-h'], $matcher->matching($g, '> d-container:has(> d-image)'));
    $this->assertSame(['container:sec-h'], $matcher->matching($g, '> *:has(d-image)'));
    $this->assertSame(['block:img3'], $matcher->matching($g, '> d-container:has(> d-image) > *'));
    $this->assertSame(['container:sec-b'], $matcher->matching('container:sec-a', '> d-container:has(> d-image)'));
    // A region wrapper anchors a `:has()` like any node: the two-column
    // section's regions hold text blocks, though the regions themselves
    // are never listed (unaddressable).
    $this->assertSame([], $matcher->matching('container:sec-c', '> *:has(> d-text)'));
    $this->assertSame(['block:t4', 'block:t5', 'block:t6'], $matcher->matching('container:sec-c', '> *:has(> d-text) > *'));
    // `:not()` excludes what its compound matches on the node: a type,
    // a `:has()` (the two unlinked text blocks), and a part class judged
    // on the node's own anatomy: a node whose parts carry the class
    // (hoisted onto its root like the heading's `.featured`, or nested
    // inside it like the link part) is excluded; the flattened parts do
    // not tell the two apart, and excluding both never over-claims.
    $this->assertSame(['block:t9', 'block:t10', 'block:t11', 'container:sec-h'], $matcher->matching($g, '> *:not(d-image)'));
    $this->assertSame(['container:sec-h'], $matcher->matching($g, '> *:not(d-image):not(d-text)'));
    $this->assertSame(['block:t9', 'block:t11'], $matcher->matching($g, '> d-text:not(:has(a))'));
    $this->assertSame(['block:t9', 'block:t10'], $matcher->matching($g, '> d-text:not(.featured)'));
    $this->assertSame(['block:t9', 'block:t11'], $matcher->matching($g, '> d-text:not(.text-block-link)'));
    $this->assertSame(['block:t10'], $matcher->matching($g, '> d-text:has(a):not(.featured)'));
    // The reach limits: a tag subject reached by a sibling step or
    // carrying a qualifier is never answered INSIDE a block (the bag has
    // no order or depth), while the root and the parts of a block a
    // relational prefix matches are reached as before.
    $this->assertFalse($matcher->reaches($g, '> p + p', 'block:t10', 'p'));
    $this->assertFalse($matcher->reaches($g, 'p:has(a)', 'block:t10', 'p'));
    $this->assertTrue($matcher->reaches($g, '> p + p', 'block:t10'));
    $this->assertFalse($matcher->reaches($g, '> p + p', 'block:t9'));
    $this->assertTrue($matcher->reaches($g, '> p + p a', 'block:t10', '.text-block-link'));
    $this->assertFalse($matcher->reaches($g, '> p + p a', 'block:t9', '.text-block-link'));
    $this->assertTrue($matcher->reaches($g, '> p + p a', 'block:t10', 'a'));
    $this->assertTrue($matcher->reaches($g, '> d-text:has(a) .text-block-link', 'block:t10', '.text-block-link'));
    $this->assertFalse($matcher->reaches($g, '> d-text:not(:has(a)) .text-block-link', 'block:t10', '.text-block-link'));
    $this->assertTrue($matcher->reaches($g, '> d-text:has(a) a', 'block:t10', 'a'));
    $this->assertFalse($matcher->reaches($g, '> d-text:has(a) a', 'block:t9', 'a'));
  }

  /**
   * A terminal pseudo-element: hosts for matching, agreement for reaches.
   */
  public function testPseudoElements(): void {
    $matcher = SelectorMatcher::fromTree(static::tree());
    $a = 'container:sec-a';
    $e = 'container:sec-e';
    $g = 'container:sec-g';
    // The tree never sees a generated box: a pseudo rule matches its
    // HOSTS, and the owner's own pseudo (the anchor, no compounds) the
    // owner alone.
    $this->assertSame(['block:t1', 'block:t2'], $matcher->matching($a, '> d-text::before'));
    $this->assertSame(['block:t1'], $matcher->matching('block:t1', '&::before'));
    $this->assertSame([], $matcher->matching('block:ghost', '&::before'));
    $this->assertTrue($matcher->matches('block:t1', '&::after', 'block:t1'));
    $this->assertFalse($matcher->matches('block:t1', '&::after', 'block:t2'));
    $this->assertFalse($matcher->matches($a, '&::after', 'block:t1'));
    $this->assertTrue($matcher->matches($a, '> d-text::before', 'block:t1'));
    // The own pseudo: the element's own rule reaches its own box, and
    // only that box; a rule for one box never lands on the root or on
    // the other box, and a pseudo-free rule never lands on a box.
    $this->assertTrue($matcher->reaches('block:t1', '&::before', 'block:t1', '&::before'));
    $this->assertFalse($matcher->reaches('block:t1', '&::before', 'block:t1', '&::after'));
    $this->assertFalse($matcher->reaches('block:t1', '&::before', 'block:t1'));
    $this->assertFalse($matcher->reaches('block:t1', '&::before', 'block:t2', '&::before'));
    // An ancestor's `> d-text::before` reaches a text block child's
    // `&::before`, not its `&::after`, not its root, and not a nested
    // text block; by descent it reaches the nested one too.
    $this->assertTrue($matcher->reaches($a, '> d-text::before', 'block:t1', '&::before'));
    $this->assertFalse($matcher->reaches($a, '> d-text::before', 'block:t1', '&::after'));
    $this->assertFalse($matcher->reaches($a, '> d-text::before', 'block:t1'));
    $this->assertFalse($matcher->reaches($a, '> d-text', 'block:t1', '&::before'));
    $this->assertFalse($matcher->reaches($a, '> d-text::before', 'block:t3', '&::before'));
    $this->assertTrue($matcher->reaches($a, 'd-text::after', 'block:t3', '&::after'));
    $this->assertFalse($matcher->reaches($a, '> d-text::before', 'block:f', '&::before'));
    $this->assertFalse($matcher->reaches('container:sec-b', '> d-text::before', 'block:t1', '&::before'));
    // A part's pseudo: the class subject's box, or the box of the tag
    // the part renders, reached exactly as the part is; the pseudo must
    // agree, and the part alone is not its box.
    $this->assertTrue($matcher->reaches($e, '> d-text .text-block-link::before', 'block:t7', '.text-block-link::before'));
    $this->assertFalse($matcher->reaches($e, '> d-text .text-block-link::before', 'block:t7', '.text-block-link::after'));
    $this->assertFalse($matcher->reaches($e, '> d-text .text-block-link::before', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> d-text .text-block-link', 'block:t7', '.text-block-link::before'));
    $this->assertFalse($matcher->reaches($e, '> d-text .text-block-link::before', 'block:t8', '.text-block-link::before'));
    $this->assertTrue($matcher->reaches($e, 'd-text > .text-block-link::after', 'block:t8', '.text-block-link::after'));
    $this->assertTrue($matcher->reaches($e, 'a::before', 'block:t7', '.text-block-link::before'));
    $this->assertFalse($matcher->reaches($e, '> a::before', 'block:t7', '.text-block-link::before'));
    $this->assertFalse($matcher->reaches($e, 'a::before', 'block:t7', '.text-block-note::before'));
    $this->assertTrue($matcher->reaches($e, 'h6::after', 'block:t7', '.text-block::after'));
    // The tag tier's pseudo: the box of every anchor inside.
    $this->assertTrue($matcher->reaches($a, 'a::before', 'block:t1', 'a::before'));
    $this->assertFalse($matcher->reaches($a, 'a::before', 'block:t1', 'a'));
    $this->assertFalse($matcher->reaches($a, 'a', 'block:t1', 'a::before'));
    $this->assertFalse($matcher->reaches($a, 'a::before', 'block:t2', 'a::before'));
    $this->assertFalse($matcher->reaches($a, '> d-text a::before', 'block:t3', 'a::before'));
    // A chain's pseudo: the sibling step and the qualifier hold on the
    // host exactly as they do without the pseudo.
    $this->assertTrue($matcher->reaches($g, '> p + p::after', 'block:t10', '&::after'));
    $this->assertFalse($matcher->reaches($g, '> p + p::after', 'block:t9', '&::after'));
    $this->assertTrue($matcher->reaches($g, '> d-text:has(a)::before', 'block:t10', '&::before'));
    $this->assertFalse($matcher->reaches($g, '> d-text:has(a)::before', 'block:t9', '&::before'));
    $this->assertTrue($matcher->reaches($g, '> p + p a::before', 'block:t10', '.text-block-link::before'));
    $this->assertFalse($matcher->reaches($g, '> p + p::before', 'block:t10', 'p::before'));
    // A position that is a rule of its own, pseudo or not, is never a
    // tier; a selector key with a pseudo reads its owner's place.
    $this->assertFalse($matcher->reaches($a, '> *::before', 'block:t1', '> *::before'));
    $this->assertFalse($matcher->reaches($a, '> d-text::before', 'block:t1', '> d-text::before'));
    // The qualified anchor's box: the owner's own box when the owner
    // stands at the position.
    $this->assertSame(['block:t1'], $matcher->matching('block:t1', '&:first-child::before'));
    $this->assertSame([], $matcher->matching('block:t2', '&:first-child::before'));
    $this->assertTrue($matcher->reaches('block:t1', '&:first-child::before', 'block:t1', '&::before'));
    $this->assertFalse($matcher->reaches('block:t1', '&:first-child::before', 'block:t1', '&::after'));
    $this->assertFalse($matcher->reaches('block:t2', '&:first-child::before', 'block:t2', '&::before'));
    $this->assertTrue($matcher->reaches('block:t2', '&:nth-child(even)::before', 'block:t2', '&::before'));

    // Any pseudo-element the browser spells reaches by name.
    $this->assertTrue($matcher->reaches($a, 'a::marker', 'block:t1', 'a::marker'));
    $this->assertFalse($matcher->reaches($a, 'a::marker', 'block:t1', 'a::before'));

    $this->expectException(\InvalidArgumentException::class);
    $matcher->reaches($a, 'a', 'block:t1', 'a::part(x)');
  }

  /**
   * Position qualifiers: judged by node, reach-neutral on a tag, the anchor.
   */
  public function testPositions(): void {
    $matcher = SelectorMatcher::fromTree(static::tree());
    $a = 'container:sec-a';
    $b = 'container:sec-b';
    $g = 'container:sec-g';
    // The hero section's five children: the argument-less positions,
    // then An+B over the 1-based position (odd and even as CSS reads
    // them, a step with an offset, a negative step, a bare integer, the
    // -last- forms from the end, an argument nothing satisfies).
    $this->assertSame(['block:t1'], $matcher->matching($a, '> *:first-child'));
    $this->assertSame(['block:p'], $matcher->matching($a, '> *:last-child'));
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'container:sec-b'], $matcher->matching($a, '> *:not(:last-child)'));
    $this->assertSame(['block:t2', 'block:f', 'container:sec-b', 'block:p'], $matcher->matching($a, '> *:not(:first-child)'));
    $this->assertSame(['block:t4'], $matcher->matching('container:sec-c', '> * > *:only-child'));
    $this->assertSame(['block:t5', 'block:t6'], $matcher->matching('container:sec-c', '> * > *:not(:only-child)'));
    $this->assertSame(['block:t1', 'block:f', 'block:p'], $matcher->matching($a, '> *:nth-child(odd)'));
    $this->assertSame(['block:t2', 'container:sec-b'], $matcher->matching($a, '> *:nth-child(even)'));
    $this->assertSame(['block:t1', 'block:f', 'block:p'], $matcher->matching($a, '> *:nth-child(2n+1)'));
    $this->assertSame(['block:t1', 'container:sec-b'], $matcher->matching($a, '> *:nth-child(3n+1)'));
    $this->assertSame(['block:t1', 'block:t2'], $matcher->matching($a, '> *:nth-child(-n+2)'));
    $this->assertSame(['container:sec-b', 'block:p'], $matcher->matching($a, '> *:nth-child(n+4)'));
    $this->assertSame(['block:f'], $matcher->matching($a, '> *:nth-child(3)'));
    $this->assertSame([], $matcher->matching($a, '> *:nth-child(0)'));
    $this->assertSame(['block:p'], $matcher->matching($a, '> *:nth-last-child(1)'));
    $this->assertSame(['container:sec-b'], $matcher->matching($a, '> *:nth-last-child(2)'));
    $this->assertSame(['container:sec-b', 'block:p'], $matcher->matching($a, '> *:nth-last-child(-n+2)'));
    // A type compound is judged at the node's place among ALL its
    // siblings, as the browser judges it.
    $this->assertSame([], $matcher->matching($a, '> d-text:last-child'));
    $this->assertSame(['block:t2'], $matcher->matching($a, '> d-text:nth-child(2)'));
    $this->assertSame(['block:p'], $matcher->matching($a, '> d-block:last-child'));
    $this->assertSame(['block:t1', 'block:t3'], $matcher->matching($a, 'd-text:first-child'));
    // A block's root holds its content, which the tree carries no nodes
    // for: a block is never empty; a container without children is.
    $this->assertSame(['container:sec-d'], $matcher->matching($a, 'd-container:empty'));
    $this->assertSame([], $matcher->matching($a, '> *:empty'));
    $this->assertSame(['container:sec-d'], $matcher->matching($b, '> *:empty'));
    $this->assertSame(['block:t3', 'block:img'], $matcher->matching($b, '> *:not(:empty)'));
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:p'], $matcher->matching($a, '> *:not(:empty)'));
    // Inside :has() the argument's compounds are judged the same way.
    $this->assertSame(['container:sec-h'], $matcher->matching($g, '> d-container:has(> *:only-child)'));
    $this->assertSame(['container:sec-b'], $matcher->matching($a, '> *:has(> *:nth-child(3))'));
    $this->assertSame([], $matcher->matching($a, '> *:has(> *:nth-child(4))'));
    // The -of-type positions read the place among the siblings sharing
    // the root tag (the section: two paragraphs, a heading, a figure, a
    // div); the hero's unannotated plugin block has no tag, so it is
    // never first of its type (and :not() of that holds).
    $this->assertSame(['block:t9', 'block:t11', 'block:img2', 'container:sec-h'], $matcher->matching($g, '> *:first-of-type'));
    $this->assertSame(['block:t10', 'block:t11', 'block:img2', 'container:sec-h'], $matcher->matching($g, '> *:last-of-type'));
    $this->assertSame(['block:t11', 'block:img2', 'container:sec-h'], $matcher->matching($g, '> *:only-of-type'));
    $this->assertSame(['block:t10'], $matcher->matching($g, '> *:nth-of-type(2)'));
    $this->assertSame(['block:t9'], $matcher->matching($g, '> *:nth-last-of-type(2)'));
    $this->assertSame(['block:t9', 'block:t11', 'block:img2', 'container:sec-h'], $matcher->matching($g, '> *:nth-of-type(odd)'));
    $this->assertSame(['block:t9', 'block:t11'], $matcher->matching($g, '> d-text:first-of-type'));
    $this->assertSame(['block:t10'], $matcher->matching($g, '> d-text:not(:first-of-type)'));
    $this->assertSame(['block:t1', 'block:t2', 'block:f'], $matcher->matching($a, '> *:first-of-type'));
    $this->assertSame(['block:t1', 'block:t2', 'container:sec-b'], $matcher->matching($a, '> *:last-of-type'));
    $this->assertSame(['container:sec-b', 'block:p'], $matcher->matching($a, '> *:not(:first-of-type)'));
    // A position qualifier on a tag compound is reach-neutral (the bag
    // has no tag nodes): the tag matches its roots as if unqualified,
    // inside :not() too, and a qualified tag subject is still never
    // answered inside a block.
    $this->assertSame(['block:t1'], $matcher->matching($a, '> p:last-child'));
    $this->assertSame(['block:t9', 'block:t10'], $matcher->matching($g, '> p:first-child'));
    $this->assertSame(['block:t9', 'block:t10'], $matcher->matching($g, '> p:nth-child(2)'));
    $this->assertSame([], $matcher->matching($g, '> p:not(:first-child)'));
    $this->assertSame(['block:t11', 'block:img2', 'container:sec-h'], $matcher->matching($g, '> *:not(p:first-child)'));
    $this->assertFalse($matcher->reaches($a, 'p:first-child', 'block:f', 'p'));
    $this->assertTrue($matcher->reaches($a, 'p:first-child', 'block:t1'));
    // The qualified anchor matches the owner alone (never a descendant),
    // its positions judged against the owner node; a chain behind it
    // walks from the owner.
    $this->assertSame(['block:t1'], $matcher->matching('block:t1', '&:first-child'));
    $this->assertSame([], $matcher->matching('block:t2', '&:first-child'));
    $this->assertSame(['block:t2'], $matcher->matching('block:t2', '&:nth-child(even)'));
    $this->assertSame(['block:p'], $matcher->matching('block:p', '&:last-child'));
    $this->assertSame(['block:t4'], $matcher->matching('block:t4', '&:only-child'));
    $this->assertSame(['block:t5'], $matcher->matching('block:t5', '&:not(:only-child)'));
    $this->assertSame(['container:sec-d'], $matcher->matching('container:sec-d', '&:empty'));
    $this->assertSame(['container:sec-d'], $matcher->matching('container:sec-d', '&:last-child'));
    $this->assertSame([], $matcher->matching($b, '&:empty'));
    $this->assertSame([], $matcher->matching('block:t1', '&:empty'));
    $this->assertSame(['block:t1'], $matcher->matching('block:t1', '&:not(:empty)'));
    $this->assertSame(['block:t1'], $matcher->matching('block:t1', '&:nth-child(3n+1)'));
    $this->assertSame([], $matcher->matching('block:t1', '&:not(:nth-child(3n+1))'));
    $this->assertSame(['block:t8'], $matcher->matching('block:t8', '&:first-of-type'));
    $this->assertSame([], $matcher->matching('block:ghost', '&:first-child'));
    $this->assertTrue($matcher->matches('block:t1', '&:first-child', 'block:t1'));
    $this->assertFalse($matcher->matches('block:t2', '&:first-child', 'block:t2'));
    $this->assertFalse($matcher->matches($a, '&:first-child', 'block:t1'));
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:p'], $matcher->matching($a, '&:not(:last-child) > *'));
    $this->assertSame([], $matcher->matching($g, '&:not(:last-child) > *'));
    $this->assertSame(['block:t1', 'block:t2', 'block:t3'], $matcher->matching($a, '&:first-child d-text'));
    $this->assertSame([], $matcher->matching($b, '&:first-child > *'));
    $this->assertSame(['block:t3', 'block:img', 'container:sec-d'], $matcher->matching($b, '&:nth-child(4) > *'));
    $this->assertSame(['block:t3', 'block:img'], $matcher->matching($b, '&:nth-child(4) > *:not(:last-child)'));
    $this->assertTrue($matcher->matches($a, '&:not(:last-child) > *', 'block:t1'));
    $this->assertFalse($matcher->matches($g, '&:not(:last-child) > *', 'block:t9'));
    // reaches: the tag tier and a part behind the qualified anchor.
    $this->assertTrue($matcher->reaches($a, '&:first-child p', 'block:f', 'p'));
    $this->assertTrue($matcher->reaches($a, '&:first-child p', 'block:t1'));
    $this->assertFalse($matcher->reaches($g, '&:first-child p', 'block:t9'));
    $this->assertTrue($matcher->reaches($g, '&:not(:first-child) p', 'block:t9'));
    $this->assertTrue($matcher->reaches('block:t7', '&:first-child .text-block-link', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches('block:t7', '&:last-child .text-block-link', 'block:t7', '.text-block-link'));
    $this->assertTrue($matcher->reaches('block:t8', '&:only-child .text-block-link', 'block:t8', '.text-block-link'));
    $this->assertTrue($matcher->reaches('container:sec-e', '&:nth-child(3) a', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches('container:sec-e', '&:nth-child(2) a', 'block:t7', '.text-block-link'));
    // structuralHolds and nthHolds over a place.
    $place = ['index' => 3, 'count' => 5, 'empty' => FALSE, 'typeIndex' => 1, 'typeCount' => 2];
    $untyped = ['index' => 0, 'count' => 1, 'empty' => FALSE, 'typeIndex' => NULL, 'typeCount' => NULL];
    $this->assertTrue(SelectorMatcher::structuralHolds(['name' => 'nth-child', 'argument' => '3n+1'], $place));
    $this->assertFalse(SelectorMatcher::structuralHolds(['name' => 'nth-child', 'argument' => '-n+3'], $place));
    $this->assertTrue(SelectorMatcher::structuralHolds(['name' => 'nth-last-child', 'argument' => '2'], $place));
    $this->assertTrue(SelectorMatcher::structuralHolds(['name' => 'last-of-type', 'argument' => NULL], $place));
    $this->assertFalse(SelectorMatcher::structuralHolds(['name' => 'first-of-type', 'argument' => NULL], $place));
    $this->assertTrue(SelectorMatcher::structuralHolds(['name' => 'nth-of-type', 'argument' => 'even'], $place));
    $this->assertTrue(SelectorMatcher::structuralHolds(['name' => 'nth-last-of-type', 'argument' => '1'], $place));
    $this->assertFalse(SelectorMatcher::structuralHolds(['name' => 'only-of-type', 'argument' => NULL], $place));
    foreach (['first-of-type', 'last-of-type', 'only-of-type'] as $name) {
      $this->assertFalse(SelectorMatcher::structuralHolds(['name' => $name, 'argument' => NULL], $untyped), $name);
    }
    $this->assertFalse(SelectorMatcher::structuralHolds(['name' => 'nth-of-type', 'argument' => '1'], $untyped));
    $this->assertTrue(SelectorMatcher::structuralHolds(['name' => 'only-child', 'argument' => NULL], $untyped));
    $positions = [1, 2, 3, 4, 5];
    $holds = static fn (string $argument): array => array_map(static fn (int $p): bool => SelectorMatcher::nthHolds($argument, $p), $positions);
    $this->assertSame([TRUE, FALSE, TRUE, FALSE, TRUE], $holds('odd'));
    $this->assertSame([FALSE, TRUE, FALSE, TRUE, FALSE], $holds('even'));
    $this->assertSame([TRUE, TRUE, TRUE, TRUE, TRUE], $holds('n'));
    $this->assertSame([TRUE, TRUE, TRUE, FALSE, FALSE], $holds('-n+3'));
    $this->assertSame([FALSE, TRUE, FALSE, TRUE, FALSE], $holds('2n'));
    $this->assertSame([TRUE, TRUE, TRUE, TRUE, TRUE], $holds('n-1'));
    $this->assertSame([TRUE, FALSE, TRUE, FALSE, TRUE], $holds('-2n+5'));
    $this->assertSame([FALSE, FALSE, TRUE, FALSE, FALSE], $holds('3'));
    $this->assertSame([FALSE, FALSE, FALSE, FALSE, FALSE], $holds('0'));
    $this->assertSame([FALSE, FALSE, FALSE, FALSE, FALSE], $holds('-n'));
    // A name or argument outside the grammar is a caller bug.
    foreach ([
      static fn () => SelectorMatcher::structuralHolds(['name' => 'hover', 'argument' => NULL], $place),
      static fn () => SelectorMatcher::nthHolds('3n + 1', 4),
      static fn () => SelectorMatcher::nthHolds('foo', 1),
      static fn () => $matcher->matching('block:t1', '&'),
      static fn () => $matcher->matching($a, '> *:nth-child(foo)'),
    ] as $bug) {
      try {
        $bug();
        $this->fail('A position outside the grammar is refused.');
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * Wear: a variant class judged on a type compound; the rest neutral.
   */
  public function testWear(): void {
    $matcher = SelectorMatcher::fromTree(static::tree(), static::map());
    $a = 'container:sec-a';
    $g = 'container:sec-g';
    $featured = 'daedalus-stylish-variant--featured';
    // The wear facts: the root fragment's plain variant class, a
    // distributed fragment's slot-encoded one, a reference that cannot
    // be a class name stamping nothing, a rule key's wear stamping
    // nothing, and no map wearing nothing.
    $this->assertSame([$featured], $matcher->facts('block:t11')['wear']);
    $this->assertSame(['daedalus-stylish-variant--card--region-first--block-text-1'], $matcher->facts('block:t10')['wear']);
    $this->assertSame([], $matcher->facts('block:t9')['wear']);
    $this->assertSame([], SelectorMatcher::fromTree(static::tree())->facts('block:t11')['wear']);
    // The text blocks wearing Featured: the Section's heading wears it,
    // its two paragraphs do not; the family type reads the same wear; a
    // variant nobody wears reaches nothing; by descent from the hero,
    // its first text block.
    $this->assertSame(['block:t11'], $matcher->matching($g, "> d-text.$featured"));
    $this->assertSame(['block:t11'], $matcher->matching($g, "> d-block.$featured"));
    $this->assertSame(['block:t10'], $matcher->matching($g, '> d-text.daedalus-stylish-variant--card--region-first--block-text-1'));
    $this->assertSame([], $matcher->matching($g, '> d-text.daedalus-stylish-variant--ghost'));
    $this->assertSame(['block:t1'], $matcher->matching($a, "d-text.$featured"));
    // `:not()` of a variant class excludes the wearers (a node fact, like
    // a part on the node's own anatomy); `:has()` reads wear the same way.
    $this->assertSame(['block:t9', 'block:t10'], $matcher->matching($g, "> d-text:not(.$featured)"));
    $this->assertSame(['container:sec-a'], $matcher->matching($a, "d-container:has(> d-text.$featured)"));
    // A markup class is no tree fact: reach-neutral on a type compound;
    // a variant class on a TAG compound is reach-neutral too (the bag
    // has no tag nodes); an attribute and a state are reach-neutral
    // everywhere.
    $this->assertSame(['block:t9', 'block:t10', 'block:t11'], $matcher->matching($g, '> d-text.active'));
    $this->assertSame(['block:t9', 'block:t10'], $matcher->matching($g, "> p.$featured"));
    $this->assertSame(['block:t9', 'block:t10', 'block:t11'], $matcher->matching($g, '> d-text[aria-current="page"]'));
    $this->assertSame(['block:t9', 'block:t10', 'block:t11'], $matcher->matching($g, '> d-text:hover'));
    $this->assertSame(['block:t9', 'block:t10', 'block:t11'], $matcher->matching($g, '> d-text:visited'));
    // A neutral qualifier HOLDS, so its negation never does (the reading
    // T2 recorded for `> p:not(:first-child)`: `:not()` never over-claims).
    $this->assertSame([], $matcher->matching($g, '> *:not(:hover)'));
    $this->assertSame([], $matcher->matching($g, '> d-text:hover a'));
    // The anchor: the owner wears the class or does not; its state is
    // reach-neutral like any state.
    $this->assertSame(['block:img3'], $matcher->matching('container:sec-h', "&.$featured > *"));
    $this->assertSame([], $matcher->matching($g, "&.$featured > *"));
    $this->assertSame(['block:img2'], $matcher->matching($g, '&:hover > d-image'));
    $this->assertSame(['block:img2'], $matcher->matching($g, '&.open > d-image'));
    // A lone variant class is the grammar's PART record (a class pooled
    // into the subject) and keeps the anatomy reading: it matches no
    // node in a chain. Targeting by worn variant is spelled on a type.
    $this->assertSame([], $matcher->matching($g, "> .$featured"));
    // reaches: a wear-qualified prefix reaches the wearer's root and its
    // tag tier, never a non-wearer's; a state-qualified prefix reaches
    // as if unqualified.
    $this->assertTrue($matcher->reaches($g, "> d-text.$featured", 'block:t11'));
    $this->assertFalse($matcher->reaches($g, "> d-text.$featured", 'block:t10'));
    $this->assertFalse($matcher->reaches($g, "> d-text.$featured a", 'block:t10', 'a'));
    $this->assertTrue($matcher->reaches($g, '> d-text:hover a', 'block:t10', 'a'));
    $this->assertTrue($matcher->reaches($g, '> d-text:hover', 'block:t11'));
    $this->assertSame([], SelectorMatcher::fromTree(static::tree())->matching($g, "> d-text.$featured"));
  }

  /**
   * The `:is()` qualifier: any member matches the node, judged as `:not()`'s.
   */
  public function testIs(): void {
    $matcher = SelectorMatcher::fromTree(static::tree(), static::map());
    $a = 'container:sec-a';
    $g = 'container:sec-g';
    $page = 'entity:page';
    $featured = 'daedalus-stylish-variant--featured';
    $card = 'daedalus-stylish-variant--card--region-first--block-text-1';
    // Types: the fold of `d-text, d-image` reaches both kinds; tags by
    // root tag; a type member's position is judged by node where a tag
    // member's is reach-neutral (the `:not()` rule, member by member).
    $this->assertSame(['block:t9', 'block:t10', 'block:t11', 'block:img2'], $matcher->matching($g, '> *:is(d-text, d-image)'));
    $this->assertSame(['block:t9', 'block:t10', 'block:t11', 'block:img2'], $matcher->matching($g, '> *:is(d-image, d-text)'));
    $this->assertSame(['block:t9', 'block:t10', 'block:t11'], $matcher->matching($g, '> *:is(p, h3)'));
    $this->assertSame(['block:t9', 'block:img2'], $matcher->matching($g, '> *:is(d-text:first-child, d-image)'));
    $this->assertSame(['block:t9', 'block:t10', 'block:img2'], $matcher->matching($g, '> *:is(p:first-child, d-image)'));
    $this->assertSame(['block:t1', 'block:t2', 'block:t3', 'block:img'], $matcher->matching($a, '*:is(d-text, d-image)'));
    // States are reach-neutral inside `:is()` as outside it: Lily's
    // compound reaches as `> d-text` does, and a member of states alone
    // holds as if absent (so, like `:not(:hover)`, nothing is excluded).
    $this->assertSame(['block:t9', 'block:t10', 'block:t11'], $matcher->matching($g, '> d-text:is(:hover, :focus-within)'));
    $this->assertSame([], $matcher->matching($g, '> d-text:is(:hover, :focus-within) a'));
    $this->assertTrue($matcher->reaches($g, '> d-text:is(:hover, :focus-within) a', 'block:t10', 'a'));
    $this->assertFalse($matcher->reaches($g, '> d-text:is(:hover, :focus-within) a', 'block:t9', 'a'));
    $this->assertTrue($matcher->reaches($g, '> d-text:is(:hover, :focus-within)', 'block:t11'));
    $this->assertTrue($matcher->reaches($g, '> *:is(d-text, d-image) a', 'block:t10', 'a'));
    $this->assertTrue($matcher->reaches($g, '> *:is(d-text, d-image)', 'block:img2'));
    $this->assertFalse($matcher->reaches($g, '> *:is(d-text, d-image)', 'container:sec-h'));
    // Classes: a class member is judged as a `:not()` argument is, by
    // the node's anatomy (a hoisted or declared part) or its wear (a
    // variant class); a class QUALIFIER on a type member by wear.
    $this->assertSame(['block:t10', 'block:t11', 'container:sec-h'], $matcher->matching($g, "> *:is(.$featured, .$card)"));
    $this->assertSame(['block:t11', 'block:img2'], $matcher->matching($g, '> *:is(.featured, d-image)'));
    $this->assertSame(['block:t10', 'block:img2'], $matcher->matching($g, '> *:is(.text-block-link, d-image)'));
    $this->assertSame(['block:t11', 'block:img2'], $matcher->matching($g, "> *:is(d-text.$featured, d-image)"));
    $this->assertSame(['block:t9', 'block:t10', 'block:t11', 'block:img2'], $matcher->matching($g, '> *:is(d-text.active, d-image)'));
    // The host's by-node reading governs the members: on a tag host a
    // member's position is reach-neutral (`p:is(:first-child, :last-child)`
    // is as neutral as `p:last-child`); on `*` the positions are judged.
    $this->assertSame(['block:t9', 'block:t10'], $matcher->matching($g, '> p:is(:first-child, :last-child)'));
    $this->assertSame(['block:t9', 'container:sec-h'], $matcher->matching($g, '> *:is(:first-child, :last-child)'));
    $this->assertSame(['block:img2', 'container:sec-h'], $matcher->matching($g, '> *:is(:has(> d-image), :not(d-text))'));
    // Inside `:has()`: the argument's compounds carry `:is()` like any
    // qualifier; a member compound never reads the bag (one unqualified
    // tag by descent is all the bag answers).
    $this->assertSame(['container:sec-h'], $matcher->matching($g, '> d-container:has(> *:is(d-image, d-text))'));
    $this->assertSame(['container:sec-b'], $matcher->matching($a, '> d-container:has(> *:is(d-image, d-text))'));
    $this->assertSame(['container:sec-a', 'container:sec-e', 'container:sec-g'], $matcher->matching($page, '> *:has(> *:is(d-image, d-container))'));
    $this->assertSame([], $matcher->matching($g, '> d-text:has(:is(a, em))'));
    // On the anchor: the owner judged by node against its own place.
    $this->assertSame(['block:t9', 'block:t10', 'block:t11', 'block:img2', 'container:sec-h'], $matcher->matching($g, '&:is(:first-child, :last-child) > *'));
    $this->assertSame([], $matcher->matching('container:sec-c', '&:is(:first-child, :last-child) > *'));
    $this->assertSame(['block:img3'], $matcher->matching('container:sec-h', "&:is(.$featured, .$card) > *"));
    $this->assertTrue($matcher->matches($g, '> *:is(d-text, d-image)', 'block:img2'));
    $this->assertFalse($matcher->matches($g, '> *:is(d-text, d-image)', 'container:sec-h'));
  }

  /**
   * The client twin answers the battery exactly as this class does.
   */
  public function testClientTwinEquivalence(): void {
    $a = 'container:sec-a';
    $e = 'container:sec-e';
    $g = 'container:sec-g';
    $page = 'entity:page';
    $queries = [
      ['op' => 'has', 'args' => ['container:sec-d']],
      ['op' => 'has', 'args' => ['entity:root']],
      // The entity root: its facts, the ancestor walk ending at it, its
      // `p` rule reaching the formatted text block's tier and its minted
      // paragraph, the chain through a section, the sections as its
      // children and their positions among them.
      ['op' => 'has', 'args' => [$page]],
      ['op' => 'facts', 'args' => [$page]],
      ['op' => 'ancestors', 'args' => ['block:t1']],
      ['op' => 'ancestors', 'args' => [$page]],
      ['op' => 'reaches', 'args' => [$page, 'p', 'block:f', 'p']],
      ['op' => 'reaches', 'args' => [$page, 'p', 'block:f', '.s-p1']],
      ['op' => 'reaches', 'args' => [$page, 'p', 'block:f', NULL]],
      ['op' => 'reaches', 'args' => [$page, 'p', 'block:t1', NULL]],
      ['op' => 'reaches', 'args' => [$page, '> d-container p', 'block:f', '.s-p1']],
      ['op' => 'reaches', 'args' => [$page, '> d-container > d-container p', 'block:f', '.s-p1']],
      ['op' => 'reaches', 'args' => [$page, '> d-container > p', 'block:f', '.s-p1']],
      ['op' => 'reaches', 'args' => [$page, '> d-container > p', 'block:t1', NULL]],
      ['op' => 'reaches', 'args' => [$page, 'p', 'block:t6', '.s-q1']],
      ['op' => 'matching', 'args' => [$page, '> *']],
      ['op' => 'matching', 'args' => [$page, '> d-container']],
      ['op' => 'matching', 'args' => [$page, '> section']],
      ['op' => 'matching', 'args' => [$page, '> * > d-text']],
      ['op' => 'matching', 'args' => [$page, '> *:nth-child(even)']],
      ['op' => 'matching', 'args' => [$page, '&:only-child > *']],
      ['op' => 'matches', 'args' => [$page, '> *:first-child', 'container:sec-a']],
      ['op' => 'matches', 'args' => [$page, '> *:first-child', 'container:sec-g']],
      ['op' => 'matches', 'args' => [$page, '> *:last-child', 'container:sec-g']],
      ['op' => 'matches', 'args' => ['container:sec-a', 'd-container', $page]],
      ['op' => 'matches', 'args' => [$page, '> *', $page]],
      ['op' => 'facts', 'args' => ['block:t3']],
      ['op' => 'facts', 'args' => ['container:sec-a']],
      ['op' => 'facts', 'args' => ['block:p']],
      ['op' => 'facts', 'args' => ['block:t4']],
      ['op' => 'facts', 'args' => ['block:t6']],
      ['op' => 'facts', 'args' => ['block:t7']],
      ['op' => 'facts', 'args' => ['block:t8']],
      ['op' => 'facts', 'args' => ['block:f']],
      ['op' => 'facts', 'args' => ['block:ghost']],
      ['op' => 'ancestors', 'args' => ['block:t3']],
      ['op' => 'ancestors', 'args' => ['block:t5']],
      ['op' => 'ancestors', 'args' => ['container:sec-a']],
      ['op' => 'ancestors', 'args' => ['block:ghost']],
      ['op' => 'matches', 'args' => [$a, 'd-container', $a]],
      ['op' => 'matches', 'args' => [$a, '> d-text', 'block:t3']],
      ['op' => 'matches', 'args' => ['container:sec-b', '> d-text', 'block:t3']],
      ['op' => 'matches', 'args' => ['block:t1', '> *', 'block:t2']],
      ['op' => 'matches', 'args' => ['block:ghost', '> *', 'block:t2']],
      ['op' => 'matches', 'args' => [$a, 'p:has(:has(a))', 'block:t1']],
    ];
    foreach ([
      '> *', '> d-text', '> p', '> d-block', '> d-container', '> * > *', '> d-container > d-text',
      'd-text', 'p', 'd-block', 'd-container > *', '.cls', '.cls > *',
      '> d-formatted-text .s-p1', '> d-block:not(.s-p1)', '> d-block:not(.s-p9)',
      // The position qualifiers: by node, An+B, -of-type (the tagless
      // plugin block), reach-neutral on a tag, inside :has(), the anchor.
      '> *:first-child', '> *:last-child', '> *:not(:last-child)', '> *:not(:first-child)',
      '> *:nth-child(odd)', '> *:nth-child(even)', '> *:nth-child(2n+1)', '> *:nth-child(3n+1)', '> *:nth-child(-n+2)',
      '> *:nth-child(n+4)', '> *:nth-child(3)', '> *:nth-child(0)', '> *:nth-last-child(1)', '> *:nth-last-child(2)', '> *:nth-last-child(-n+2)',
      '> d-text:last-child', '> d-text:nth-child(2)', '> d-block:last-child', 'd-text:first-child',
      'd-container:empty', '> *:empty', '> *:not(:empty)', '> *:has(> *:nth-child(3))', '> *:has(> *:nth-child(4))',
      '> *:first-of-type', '> *:last-of-type', '> *:not(:first-of-type)', '> p:last-child',
      '&:not(:last-child) > *', '&:first-child d-text', '&:first-child', '&', '> *:nth-child(foo)',
    ] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => [$a, $selector]];
    }
    foreach ([
      '> *:empty',
      '> *:not(:empty)',
      '&:empty',
      '&:first-child > *',
      '&:nth-child(4) > *',
      '&:nth-child(4) > *:not(:last-child)',
    ] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => ['container:sec-b', $selector]];
    }
    foreach (['> * > *:only-child', '> * > *:not(:only-child)'] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => ['container:sec-c', $selector]];
    }
    foreach ([
      ['block:t1', '&:first-child'],
      ['block:t2', '&:first-child'],
      ['block:t2', '&:nth-child(even)'],
      ['block:p', '&:last-child'],
      ['block:t4', '&:only-child'],
      ['block:t5', '&:not(:only-child)'],
      ['container:sec-d', '&:empty'],
      ['container:sec-d', '&:last-child'],
      ['block:t1', '&:empty'],
      ['block:t1', '&:not(:empty)'],
      ['block:t1', '&:nth-child(3n+1)'],
      ['block:t1', '&:not(:nth-child(3n+1))'],
      ['block:t8', '&:first-of-type'],
      ['block:ghost', '&:first-child'],
      ['block:t1', '&:first-child::before'],
      ['block:t2', '&:first-child::before'],
    ] as $args) {
      $queries[] = ['op' => 'matching', 'args' => $args];
    }
    foreach ([
      ['block:t1', '&:first-child', 'block:t1'],
      ['block:t2', '&:first-child', 'block:t2'],
      [$a, '&:first-child', 'block:t1'],
      [$a, '&:not(:last-child) > *', 'block:t1'],
      [$g, '&:not(:last-child) > *', 'block:t9'],
    ] as $args) {
      $queries[] = ['op' => 'matches', 'args' => $args];
    }
    foreach (['> *', '> d-text', '> * > *', 'd-text', 'p'] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => ['container:sec-c', $selector]];
    }
    foreach (['> *', 'a', '> d-text .text-block-link'] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => [$e, $selector]];
    }
    foreach ([
      '> p + p', '> p ~ p', '> p + *', '> p ~ *', 'd-text ~ d-image',
      '> d-text:has(a)', '> d-text:has(> a)', '> d-text:has(+ d-image)', '> d-text:has(~ d-image)',
      '> d-container:has(> d-image)', '> *:has(d-image)', '> d-container:has(> d-image) > *',
      '> *:not(d-image)', '> *:not(d-image):not(d-text)', '> d-text:not(:has(a))', '> d-text:not(.featured)',
      '> d-text:not(.text-block-link)', '> d-text:has(a):not(.featured)',
      '> d-container:has(> *:only-child)', '> *:first-of-type', '> *:last-of-type', '> *:only-of-type', '> *:nth-of-type(2)',
      '> *:nth-last-of-type(2)', '> *:nth-of-type(odd)', '> d-text:first-of-type', '> d-text:not(:first-of-type)',
      '> p:first-child', '> p:nth-child(2)', '> p:not(:first-child)', '> *:not(p:first-child)', '&:not(:last-child) > *',
    ] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => [$g, $selector]];
    }
    foreach (['> d-container:has(> d-image)', '> *:has(> d-text) > *'] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => [$a, $selector]];
      $queries[] = ['op' => 'matching', 'args' => ['container:sec-c', $selector]];
    }
    $queries[] = ['op' => 'matches', 'args' => [$g, '> p + p', 'block:t10']];
    $queries[] = ['op' => 'matches', 'args' => [$g, '> p + p', 'block:t9']];
    foreach ([
      [$a, 'p', 'block:t1', NULL],
      [$a, 'p', 'block:f', NULL],
      [$a, 'p', 'block:f', 'p'],
      [$a, '> p', 'block:f', 'p'],
      [$a, 'a', 'block:t1', 'a'],
      [$a, 'a', 'block:t2', 'a'],
      [$a, 'd-text a', 'block:t1', 'a'],
      [$a, '> d-text em', 'block:t3', 'em'],
      [$a, 'd-text em', 'block:t3', 'em'],
      [$a, 'd-formatted-text p', 'block:f', 'p'],
      ['container:sec-b', 'p', 'block:f', 'p'],
      ['container:sec-c', 'a', 'block:t6', 'a'],
      [$e, '> d-text .text-block-link', 'block:t7', '.text-block-link'],
      [$e, '> d-text .text-block-link', 'block:t8', '.text-block-link'],
      [$e, 'd-text > .text-block-link', 'block:t8', '.text-block-link'],
      [$e, '.text-block-link', 'block:t7', '.text-block-link'],
      [$e, 'a', 'block:t7', '.text-block-link'],
      [$e, '> a', 'block:t7', '.text-block-link'],
      [$e, '> d-text a', 'block:t7', '.text-block-link'],
      [$e, '> d-text > a', 'block:t7', '.text-block-link'],
      [$e, 'a', 'block:t8', '.text-block-link'],
      [$e, '> d-text a', 'block:t8', '.text-block-link'],
      [$e, 'd-container a', 'block:t8', '.text-block-link'],
      [$e, '> d-container a', 'block:t8', '.text-block-link'],
      [$e, '> d-container a', 'block:t7', '.text-block-link'],
      ['container:sec-f', 'a', 'block:t7', '.text-block-link'],
      [$e, 'small', 'block:t7', '.text-block-note'],
      [$e, 'a', 'block:t7', '.text-block-note'],
      [$e, 'svg', 'block:t7', '.text-block-link'],
      [$e, 'svg', 'block:t7', '.text-block-link__icon'],
      [$e, 'h6', 'block:t7', '.text-block'],
      [$e, 'a', 'block:t7', '.ghost'],
      [$e, 'a', 'block:t7', 'a'],
      [$e, 'd-text a', 'block:t8', 'a'],
      [$e, '> d-text a', 'block:t8', 'a'],
      [$e, 'em', 'block:t7', 'em'],
      [$a, '> *', 'block:t1', '> *'],
      // The identity: a minted instance by its tag, an identity class by
      // presence on the node, a declared class by anatomy.
      [$a, 'p', 'block:f', '.s-p1'],
      [$a, 'd-formatted-text p', 'block:f', '.s-p1'],
      [$a, 'h2', 'block:f', '.s-p1'],
      [$a, 'li', 'block:f', '.s-li'],
      [$a, 'ul', 'block:f', '.s-li'],
      ['container:sec-c', 'p', 'block:t6', '.s-q1'],
      [$a, '> d-formatted-text .s-p1', 'block:f', '.s-p1'],
      [$a, '> d-formatted-text .s-p1', 'block:t1', '.s-p1'],
      [$a, '> d-block .s-p1', 'block:t1', '.s-p1'],
      [$a, '> d-formatted-text .s-p9', 'block:f', '.s-p9'],
      [$a, '> d-formatted-text .s-li', 'block:f', '.s-li'],
      [$a, '> d-text .text-block-link', 'block:t2', '.text-block-link'],
      [$g, '> p + p', 'block:t10', 'p'],
      [$g, 'p:has(a)', 'block:t10', 'p'],
      [$g, '> p + p', 'block:t10', NULL],
      [$g, '> p + p', 'block:t9', NULL],
      [$g, '> p + p a', 'block:t10', '.text-block-link'],
      [$g, '> p + p a', 'block:t9', '.text-block-link'],
      [$g, '> p + p a', 'block:t10', 'a'],
      [$g, '> d-text:has(a) .text-block-link', 'block:t10', '.text-block-link'],
      [$g, '> d-text:not(:has(a)) .text-block-link', 'block:t10', '.text-block-link'],
      [$g, '> d-text:has(a) a', 'block:t10', 'a'],
      [$g, '> d-text:has(a) a', 'block:t9', 'a'],
      // The terminal pseudo-element: agreement by name, then the host.
      ['block:t1', '&::before', 'block:t1', '&::before'],
      ['block:t1', '&::before', 'block:t1', '&::after'],
      ['block:t1', '&::before', 'block:t1', NULL],
      ['block:t1', '&::before', 'block:t2', '&::before'],
      [$a, '> d-text::before', 'block:t1', '&::before'],
      [$a, '> d-text::before', 'block:t1', '&::after'],
      [$a, '> d-text::before', 'block:t1', NULL],
      [$a, '> d-text', 'block:t1', '&::before'],
      [$a, '> d-text::before', 'block:t3', '&::before'],
      [$a, 'd-text::after', 'block:t3', '&::after'],
      [$a, '> d-text::before', 'block:f', '&::before'],
      ['container:sec-b', '> d-text::before', 'block:t1', '&::before'],
      [$e, '> d-text .text-block-link::before', 'block:t7', '.text-block-link::before'],
      [$e, '> d-text .text-block-link::before', 'block:t7', '.text-block-link::after'],
      [$e, '> d-text .text-block-link::before', 'block:t7', '.text-block-link'],
      [$e, '> d-text .text-block-link', 'block:t7', '.text-block-link::before'],
      [$e, '> d-text .text-block-link::before', 'block:t8', '.text-block-link::before'],
      [$e, 'd-text > .text-block-link::after', 'block:t8', '.text-block-link::after'],
      [$e, 'a::before', 'block:t7', '.text-block-link::before'],
      [$e, '> a::before', 'block:t7', '.text-block-link::before'],
      [$e, 'a::before', 'block:t7', '.text-block-note::before'],
      [$e, 'h6::after', 'block:t7', '.text-block::after'],
      [$a, 'a::before', 'block:t1', 'a::before'],
      [$a, 'a::before', 'block:t1', 'a'],
      [$a, 'a', 'block:t1', 'a::before'],
      [$a, 'a::before', 'block:t2', 'a::before'],
      [$a, '> d-text a::before', 'block:t3', 'a::before'],
      [$g, '> p + p::after', 'block:t10', '&::after'],
      [$g, '> p + p::after', 'block:t9', '&::after'],
      [$g, '> d-text:has(a)::before', 'block:t10', '&::before'],
      [$g, '> d-text:has(a)::before', 'block:t9', '&::before'],
      [$g, '> p + p a::before', 'block:t10', '.text-block-link::before'],
      [$g, '> p + p::before', 'block:t10', 'p::before'],
      [$a, '> *::before', 'block:t1', '> *::before'],
      [$a, '> d-text::before', 'block:t1', '> d-text::before'],
      [$a, 'a', 'block:t1', 'a::part(x)'],
      // The position qualifiers: a qualified tag subject never inside,
      // the anchor's box, the tag tier and a part behind the anchor.
      [$a, 'p:first-child', 'block:f', 'p'],
      [$a, 'p:first-child', 'block:t1', NULL],
      ['block:t1', '&:first-child::before', 'block:t1', '&::before'],
      ['block:t1', '&:first-child::before', 'block:t1', '&::after'],
      ['block:t2', '&:first-child::before', 'block:t2', '&::before'],
      ['block:t2', '&:nth-child(even)::before', 'block:t2', '&::before'],
      [$a, '&:first-child p', 'block:f', 'p'],
      [$a, '&:first-child p', 'block:t1', NULL],
      [$g, '&:first-child p', 'block:t9', NULL],
      [$g, '&:not(:first-child) p', 'block:t9', NULL],
      ['block:t7', '&:first-child .text-block-link', 'block:t7', '.text-block-link'],
      ['block:t7', '&:last-child .text-block-link', 'block:t7', '.text-block-link'],
      ['block:t8', '&:only-child .text-block-link', 'block:t8', '.text-block-link'],
      [$e, '&:nth-child(3) a', 'block:t7', '.text-block-link'],
      [$e, '&:nth-child(2) a', 'block:t7', '.text-block-link'],
    ] as $args) {
      $queries[] = ['op' => 'reaches', 'args' => $args];
    }
    foreach ([[$a, '> d-text::before'], ['block:t1', '&::before'], ['block:ghost', '&::before']] as $args) {
      $queries[] = ['op' => 'matching', 'args' => $args];
    }
    foreach ([
      ['block:t1', '&::after', 'block:t1'],
      ['block:t1', '&::after', 'block:t2'],
      [$a, '&::after', 'block:t1'],
      [$a, '> d-text::before', 'block:t1'],
    ] as $args) {
      $queries[] = ['op' => 'matches', 'args' => $args];
    }
    // The wear: the facts both twins derive from the map, a variant
    // class judged by wear on a type compound (and inside :not(),
    // :has() and on the anchor), markup classes, attributes and states
    // reach-neutral, a lone variant class the part record.
    $featured = 'daedalus-stylish-variant--featured';
    foreach (['block:t11', 'block:t10', 'block:t9', 'container:sec-h'] as $key) {
      $queries[] = ['op' => 'facts', 'args' => [$key]];
    }
    foreach ([
      [$g, "> d-text.$featured"],
      [$g, "> d-block.$featured"],
      [$g, '> d-text.daedalus-stylish-variant--card--region-first--block-text-1'],
      [$g, '> d-text.daedalus-stylish-variant--ghost'],
      [$a, "d-text.$featured"],
      [$g, "> d-text:not(.$featured)"],
      [$a, "d-container:has(> d-text.$featured)"],
      [$g, '> d-text.active'],
      [$g, "> p.$featured"],
      [$g, '> d-text[aria-current="page"]'],
      [$g, '> d-text:hover'],
      [$g, '> d-text:visited'],
      [$g, '> *:not(:hover)'],
      [$g, '> d-text:hover a'],
      ['container:sec-h', "&.$featured > *"],
      [$g, "&.$featured > *"],
      [$g, '&:hover > d-image'],
      [$g, '&.open > d-image'],
      [$g, "> .$featured"],
      [$g, "> d-text:not(d-text.$featured)"],
      [$g, '> d-text:not(.active)'],
    ] as $args) {
      $queries[] = ['op' => 'matching', 'args' => $args];
    }
    foreach ([
      [$g, "> d-text.$featured", 'block:t11', NULL],
      [$g, "> d-text.$featured", 'block:t10', NULL],
      [$g, "> d-text.$featured a", 'block:t10', 'a'],
      [$g, '> d-text:hover a', 'block:t10', 'a'],
      [$g, '> d-text:hover', 'block:t11', NULL],
    ] as $args) {
      $queries[] = ['op' => 'reaches', 'args' => $args];
    }
    // `:is()`: types, tags, a position on a type or tag member, states,
    // classes by anatomy and wear, the host's by-node reading, inside
    // :has(), on the anchor.
    $card = 'daedalus-stylish-variant--card--region-first--block-text-1';
    foreach ([
      [$g, '> *:is(d-text, d-image)'],
      [$g, '> *:is(p, h3)'],
      [$g, '> *:is(d-text:first-child, d-image)'],
      [$g, '> *:is(p:first-child, d-image)'],
      [$a, '*:is(d-text, d-image)'],
      [$g, '> d-text:is(:hover, :focus-within)'],
      [$g, '> d-text:is(:hover, :focus-within) a'],
      [$g, "> *:is(.$featured, .$card)"],
      [$g, '> *:is(.featured, d-image)'],
      [$g, '> *:is(.text-block-link, d-image)'],
      [$g, "> *:is(d-text.$featured, d-image)"],
      [$g, '> *:is(d-text.active, d-image)'],
      [$g, '> p:is(:first-child, :last-child)'],
      [$g, '> *:is(:first-child, :last-child)'],
      [$g, '> *:is(:has(> d-image), :not(d-text))'],
      [$g, '> d-container:has(> *:is(d-image, d-text))'],
      [$a, '> d-container:has(> *:is(d-image, d-text))'],
      [$page, '> *:has(> *:is(d-image, d-container))'],
      [$g, '> d-text:has(:is(a, em))'],
      [$g, '&:is(:first-child, :last-child) > *'],
      ['container:sec-c', '&:is(:first-child, :last-child) > *'],
      ['container:sec-h', "&:is(.$featured, .$card) > *"],
    ] as $args) {
      $queries[] = ['op' => 'matching', 'args' => $args];
    }
    foreach ([
      [$g, '> d-text:is(:hover, :focus-within) a', 'block:t10', 'a'],
      [$g, '> d-text:is(:hover, :focus-within) a', 'block:t9', 'a'],
      [$g, '> d-text:is(:hover, :focus-within)', 'block:t11', NULL],
      [$g, '> *:is(d-text, d-image) a', 'block:t10', 'a'],
      [$g, '> *:is(d-text, d-image)', 'block:img2', NULL],
      [$g, '> *:is(d-text, d-image)', 'container:sec-h', NULL],
    ] as $args) {
      $queries[] = ['op' => 'reaches', 'args' => $args];
    }
    foreach ([[$g, '> *:is(d-text, d-image)', 'block:img2'], [$g, '> *:is(d-text, d-image)', 'container:sec-h']] as $args) {
      $queries[] = ['op' => 'matches', 'args' => $args];
    }

    // A block's field: the record's facts (its wear from the map), its
    // ancestors, reach through the block's place, and its absence from
    // the matching universe on both sides.
    $body = 'block:t6:body';
    $c = 'container:sec-c';
    foreach ([$body, 'block:t6:title', 'block:t1:body'] as $key) {
      $queries[] = ['op' => 'has', 'args' => [$key]];
      $queries[] = ['op' => 'facts', 'args' => [$key]];
      $queries[] = ['op' => 'ancestors', 'args' => [$key]];
    }
    foreach ([
      ['block:t6', 'p', $body, '.s-q1'],
      [$c, 'p', $body, 'p'],
      [$c, 'a', $body, 'a'],
      [$page, 'p', $body, '.s-q1'],
      [$c, '> * > d-text p', $body, '.s-q1'],
      [$c, '> * > d-text .s-q1', $body, '.s-q1'],
      [$c, '> d-text p', $body, 'p'],
      [$c, 'em', $body, 'em'],
      [$a, 'p', $body, 'p'],
      [$c, 'p', $body, NULL],
      ['block:t6', '&::before', $body, '&::before'],
      [$body, 'p', $body, 'p'],
    ] as $args) {
      $queries[] = ['op' => 'reaches', 'args' => $args];
    }
    foreach ([['block:t6', '> *'], [$c, '> * > *'], [$c, 'd-text'], [$body, '> *']] as $args) {
      $queries[] = ['op' => 'matching', 'args' => $args];
    }
    foreach ([[$c, '> *', $body], [$c, '> * > *', $body], [$c, 'p', $body], [$body, 'p', $body]] as $args) {
      $queries[] = ['op' => 'matches', 'args' => $args];
    }

    $this->assertTwinAnswers(static::tree(), static::map(), $queries, 'The client twin answers the battery exactly as the matcher does.');

    // The open render: a prose field's record, a unit field's empty
    // record, no record for a field with no element or a keyless block.
    $this->assertTwinAnswers(static::openRender(), [], [
      ['op' => 'has', 'args' => ['block:o1:body']],
      ['op' => 'has', 'args' => ['block:o1:title']],
      ['op' => 'has', 'args' => ['block:o1:hidden']],
      ['op' => 'has', 'args' => ['block:null:body']],
      ['op' => 'facts', 'args' => ['block:o1:body']],
      ['op' => 'facts', 'args' => ['block:o1:title']],
      ['op' => 'facts', 'args' => ['block:o1']],
      ['op' => 'ancestors', 'args' => ['block:o1:body']],
      ['op' => 'reaches', 'args' => ['container:sec-o', 'em', 'block:o1:body', 'em']],
      ['op' => 'reaches', 'args' => ['container:sec-o', 'p', 'block:o1:body', '.s-o1']],
      ['op' => 'reaches', 'args' => ['container:sec-o', 'p', 'block:o1:title', 'p']],
      ['op' => 'reaches', 'args' => ['block:o1', '> d-text p', 'block:o1:body', 'p']],
      ['op' => 'matching', 'args' => ['container:sec-o', '> *']],
      ['op' => 'matching', 'args' => ['container:sec-o', '> *:last-child']],
    ], 'The client twin answers the open render exactly as the matcher does.');

    // The keyless root: no key, no ancestor, the sections its children.
    $this->assertTwinAnswers(static::keyless(), [], [
      ['op' => 'has', 'args' => ['entity:null']],
      ['op' => 'has', 'args' => ['entity:']],
      ['op' => 'facts', 'args' => ['container:sec-x']],
      ['op' => 'ancestors', 'args' => ['block:tx']],
      ['op' => 'ancestors', 'args' => ['container:sec-x']],
      ['op' => 'matching', 'args' => ['container:sec-y', '&:last-child']],
      ['op' => 'matching', 'args' => ['container:sec-x', '&:first-child > *']],
      ['op' => 'matches', 'args' => ['container:sec-x', 'p', 'block:tx']],
    ], 'The client twin answers the keyless document exactly as the matcher does.');
  }

  /**
   * Asserts the client twin answers a battery as this matcher does.
   *
   * @param array $document
   *   The document tree both twins are built over.
   * @param array $map
   *   The style map both twins are built with.
   * @param array $queries
   *   The battery: [['op' => string, 'args' => array], ...].
   * @param string $message
   *   The assertion message.
   */
  private function assertTwinAnswers(array $document, array $map, array $queries, string $message): void {
    $matcher = SelectorMatcher::fromTree($document, $map);
    $expected = array_map(static function (array $query) use ($matcher): mixed {
      try {
        return $matcher->{$query['op']}(...$query['args']);
      }
      catch (\InvalidArgumentException) {
        return ['error' => TRUE];
      }
    }, $queries);
    $stdout = $this->runNodeScript('tests/js/selector-matcher-cases.mjs', json_encode([
      'tree' => $document,
      'map' => $map,
      'queries' => $queries,
    ], JSON_THROW_ON_ERROR));
    $this->assertSame($expected, json_decode($stdout, TRUE, 512, JSON_THROW_ON_ERROR)['answers'] ?? NULL, $message);
  }

}
