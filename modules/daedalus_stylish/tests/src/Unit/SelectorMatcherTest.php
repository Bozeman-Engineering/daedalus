<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Unit;

use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_stylish\Selector\SelectorMatcher;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The selector matcher over a fixture tree, and its client twin.
 *
 * The fixture is the lean node tree the delivered blueprint carries
 * (the blueprint's `layout`), built by hand: a container section holding two text blocks
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
 * container section holding an ordered band (two paragraph text blocks,
 * the second with a link part, a heading text block wearing a hoisted
 * class, an image block) and a nested section holding an image block.
 * The client twin (js/src/selector-matcher.js) is
 * locked to this class by the battery testClientTwinEquivalence pipes
 * through tests/js/selector-matcher-cases.mjs, the RelativeSelectorTest
 * pattern; tests/js/selector-matcher-fixture.mjs carries the same tree
 * for the client's own spec.
 */
#[Group('daedalus_stylish')]
class SelectorMatcherTest extends UnitTestCase {

  use NodeSuitesTrait;

  /**
   * The fixture tree.
   */
  protected static function tree(): array {
    $markup = static fn (string $tag, ?array $tags = NULL): array => ['markup' => $tags === NULL ? ['tag' => $tag] : ['tag' => $tag, 'tags' => $tags]];
    return [
      [
        'kind' => 'container',
        'uuid' => 'sec-a',
        'layout_id' => 'daedalus_stylish_container',
        'label' => 'Hero',
        // The annotator's minted words ride the node beside its markup.
        '_element' => ['name' => 'Hero', 'type' => 'Section', 'icon' => '/icons/section.svg'] + $markup('section'),
        'children' => [
          ['kind' => 'block', 'uuid' => 't1', 'block_type' => 'text', 'label' => 'Intro', '_element' => $markup('p', ['a', 'strong'])],
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
              ['kind' => 'container', 'uuid' => 'sec-d', 'layout_id' => 'daedalus_stylish_container', '_element' => $markup('div'), 'children' => []],
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
          ['kind' => 'region', 'region' => 'first', 'children' => [['kind' => 'block', 'uuid' => 't4', 'block_type' => 'text', '_element' => $markup('p')]]],
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
                  '.text-block-link' => ['tag' => 'a', 'parts' => ['.text-block-link__icon' => ['tag' => 'svg', 'parts' => []]]],
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
              ['kind' => 'block', 'uuid' => 't8', 'block_type' => 'text', '_element' => ['markup' => ['tag' => 'p', 'tags' => ['strong'], 'parts' => ['.text-block-link' => ['tag' => 'a']]]]],
            ],
          ],
        ],
      ],
      [
        'kind' => 'container',
        'uuid' => 'sec-g',
        'layout_id' => 'daedalus_stylish_container',
        'label' => 'Band',
        '_element' => $markup('section'),
        'children' => [
          ['kind' => 'block', 'uuid' => 't9', 'block_type' => 'text', '_element' => $markup('p')],
          ['kind' => 'block', 'uuid' => 't10', 'block_type' => 'text', '_element' => ['markup' => ['tag' => 'p', 'parts' => ['.text-block-link' => ['tag' => 'a']]]]],
          ['kind' => 'block', 'uuid' => 't11', 'block_type' => 'text', '_element' => ['markup' => ['tag' => 'h3', 'hoisted' => ['.featured']]]],
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
      'name' => 'Hero',
      'type' => 'Section',
      'icon' => '/icons/section.svg',
      'index' => 0,
      'count' => 4,
      'childCount' => 5,
      'parent' => NULL,
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
      'parts' => ['.text-block-link' => 'a', '.text-block-link__icon' => 'svg', '.text-block-note' => NULL, '.text-block' => 'h6'],
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
    $this->assertSame(['container:sec-a', 'container:sec-b'], $matcher->ancestors('block:t3'));
    $this->assertSame(['container:sec-c'], $matcher->ancestors('block:t5'));
    $this->assertSame([], $matcher->ancestors('container:sec-a'));
    $this->assertSame([], $matcher->ancestors('block:ghost'));
  }

  /**
   * Child and descendant combinators, type and tag compounds, regions.
   */
  public function testMatching(): void {
    $matcher = SelectorMatcher::fromTree(static::tree());
    $a = 'container:sec-a';
    // The child combinator anchors to the owner; a type or tag filters it.
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:p'], $matcher->matching($a, '> *'));
    $this->assertSame(['block:t1', 'block:t2'], $matcher->matching($a, '> pb-text'));
    $this->assertSame(['block:t1'], $matcher->matching($a, '> p'));
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'block:p'], $matcher->matching($a, '> pb-block'));
    $this->assertSame(['container:sec-b'], $matcher->matching($a, '> pb-container'));
    $this->assertSame(['block:t3', 'block:img', 'container:sec-d'], $matcher->matching($a, '> * > *'));
    $this->assertSame(['block:t3'], $matcher->matching($a, RelativeSelector::fromString('> pb-container > pb-text')));
    // Descendant compounds reach the whole subtree; the owner is in scope
    // for a non-subject compound (`pb-container > *` prints without
    // :scope, so the owner's own children are targets).
    $this->assertSame(['block:t1', 'block:t2', 'block:t3'], $matcher->matching($a, 'pb-text'));
    $this->assertSame(['block:t1', 'block:t3'], $matcher->matching($a, 'p'));
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'block:t3', 'block:img', 'block:p'], $matcher->matching($a, 'pb-block'));
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:t3', 'block:img', 'container:sec-d', 'block:p'], $matcher->matching($a, 'pb-container > *'));
    $this->assertTrue($matcher->matches($a, 'pb-container', $a));
    $this->assertFalse($matcher->matches($a, '> pb-text', 'block:t3'));
    $this->assertTrue($matcher->matches('container:sec-b', '> pb-text', 'block:t3'));
    $this->assertFalse($matcher->matches('block:t1', '> *', 'block:t2'));
    $this->assertFalse($matcher->matches('block:ghost', '> *', 'block:t2'));
    // Region wrappers are the direct children of a multi-region section.
    $this->assertSame([], $matcher->matching('container:sec-c', '> *'));
    $this->assertSame([], $matcher->matching('container:sec-c', '> pb-text'));
    $this->assertSame(['block:t4', 'block:t5', 'block:t6'], $matcher->matching('container:sec-c', '> * > *'));
    $this->assertSame(['block:t4', 'block:t5', 'block:t6'], $matcher->matching('container:sec-c', 'pb-text'));
    // A part class is not a tree fact: it matches no node, and a tag
    // subject a part renders matches no ROOT either (the part is inside).
    $this->assertSame([], $matcher->matching($a, '.cls'));
    $this->assertSame([], $matcher->matching($a, '.cls > *'));
    $this->assertSame([], $matcher->matching('container:sec-e', '> pb-text .text-block-link'));
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
    $this->assertTrue($matcher->reaches($a, 'pb-text a', 'block:t1', 'a'));
    $this->assertFalse($matcher->reaches($a, '> pb-text em', 'block:t3', 'em'));
    $this->assertTrue($matcher->reaches($a, 'pb-text em', 'block:t3', 'em'));
    $this->assertTrue($matcher->reaches($a, 'pb-formatted-text p', 'block:f', 'p'));
    $this->assertFalse($matcher->reaches('container:sec-b', 'p', 'block:f', 'p'));
    $this->assertTrue($matcher->reaches('container:sec-c', 'a', 'block:t6', 'a'));
    // A part after a type: the class subject reaches the part of the
    // element the type matches (a direct child by `>`, a nested one by
    // descent); a lone class is the owner's own anatomy, never a part
    // of a descendant.
    $this->assertTrue($matcher->reaches($e, '> pb-text .text-block-link', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> pb-text .text-block-link', 'block:t8', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, 'pb-text > .text-block-link', 'block:t8', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '.text-block-link', 'block:t7', '.text-block-link'));
    // A part by its tag: the container's `a` rule reaches the link part
    // (the anchor it renders) by descent, from a prefix matching the
    // element or an ancestor; never through a child combinator (the
    // annotation carries no depth under a root); never a part declaring
    // no tag (unknown is not a match); a nested part and a hoisted part
    // are parts like any other; the rule must scope the element.
    $this->assertTrue($matcher->reaches($e, 'a', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> a', 'block:t7', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, '> pb-text a', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> pb-text > a', 'block:t7', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, 'a', 'block:t8', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> pb-text a', 'block:t8', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, 'pb-container a', 'block:t8', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, '> pb-container a', 'block:t8', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> pb-container a', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches('container:sec-f', 'a', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, 'small', 'block:t7', '.text-block-note'));
    $this->assertFalse($matcher->reaches($e, 'a', 'block:t7', '.text-block-note'));
    $this->assertFalse($matcher->reaches($e, 'svg', 'block:t7', '.text-block-link'));
    $this->assertTrue($matcher->reaches($e, 'svg', 'block:t7', '.text-block-link__icon'));
    $this->assertTrue($matcher->reaches($e, 'h6', 'block:t7', '.text-block'));
    $this->assertFalse($matcher->reaches($e, 'a', 'block:t7', '.ghost'));
    // The tag tier counts the parts' tags beside the prose's.
    $this->assertTrue($matcher->reaches($e, 'a', 'block:t7', 'a'));
    $this->assertTrue($matcher->reaches($e, 'pb-text a', 'block:t8', 'a'));
    $this->assertFalse($matcher->reaches($e, '> pb-text a', 'block:t8', 'a'));
    $this->assertFalse($matcher->reaches($e, 'em', 'block:t7', 'em'));
    $this->assertFalse($matcher->reaches($a, '> *', 'block:t1', '> *'));
    // The identity: a minted instance is reached by the tag it is, by
    // descent, exactly as a declared part is (the nested list item by
    // its own tag, an open render's field instance too); a class subject
    // that is an identity class reaches only the node whose parts carry
    // it, never another node the type matches (the class is worn by one
    // element in the world) and never a class the node lacks, while a
    // declared class keeps the anatomy posture: `> pb-text
    // .text-block-link` reaches a text block that renders no link. The
    // tree still matches no class in a chain; `:not()` judges it on the
    // node's own parts, an identity class included.
    $this->assertTrue($matcher->reaches($a, 'p', 'block:f', '.s-p1'));
    $this->assertTrue($matcher->reaches($a, 'pb-formatted-text p', 'block:f', '.s-p1'));
    $this->assertFalse($matcher->reaches($a, 'h2', 'block:f', '.s-p1'));
    $this->assertTrue($matcher->reaches($a, 'li', 'block:f', '.s-li'));
    $this->assertFalse($matcher->reaches($a, 'ul', 'block:f', '.s-li'));
    $this->assertTrue($matcher->reaches('container:sec-c', 'p', 'block:t6', '.s-q1'));
    $this->assertTrue($matcher->reaches($a, '> pb-formatted-text .s-p1', 'block:f', '.s-p1'));
    $this->assertFalse($matcher->reaches($a, '> pb-formatted-text .s-p1', 'block:t1', '.s-p1'));
    $this->assertFalse($matcher->reaches($a, '> pb-block .s-p1', 'block:t1', '.s-p1'));
    $this->assertFalse($matcher->reaches($a, '> pb-formatted-text .s-p9', 'block:f', '.s-p9'));
    $this->assertTrue($matcher->reaches($a, '> pb-formatted-text .s-li', 'block:f', '.s-li'));
    $this->assertTrue($matcher->reaches($a, '> pb-text .text-block-link', 'block:t2', '.text-block-link'));
    $this->assertSame([], $matcher->matching($a, '> pb-formatted-text .s-p1'));
    $this->assertSame(['block:t1', 'block:t2', 'block:p'], $matcher->matching($a, '> pb-block:not(.s-p1)'));
    $this->assertSame(['block:t1', 'block:t2', 'block:f', 'block:p'], $matcher->matching($a, '> pb-block:not(.s-p9)'));

    $this->assertTrue($matcher->satisfies('block:t1', ':first-child'));
    $this->assertTrue($matcher->satisfies('block:t1', ':nth-child(odd)'));
    $this->assertTrue($matcher->satisfies('block:t2', ':nth-child(even)'));
    $this->assertTrue($matcher->satisfies('block:p', ':last-child'));
    $this->assertTrue($matcher->satisfies('block:t4', ':only-child'));
    $this->assertTrue($matcher->satisfies('container:sec-d', ':empty'));
    $this->assertTrue($matcher->satisfies('container:sec-d', ':last-child'));
    $this->assertFalse($matcher->satisfies('container:sec-b', ':empty'));
    // A block is never empty: its content is not the tree's to see.
    $this->assertFalse($matcher->satisfies('block:t1', ':empty'));
    $this->assertTrue($matcher->satisfies('block:t1::> *', ':first-child'));
    $this->assertFalse($matcher->satisfies('block:t1', ':nth-child(3n+1)'));
    // The negated facts hold where their base does not: every child of
    // the hero section but the last is not the last child; a selector
    // key reads its owner's place here too.
    foreach (['block:t1', 'block:t2', 'block:f', 'container:sec-b', 'block:t1::> *'] as $key) {
      $this->assertTrue($matcher->satisfies($key, ':not(:last-child)'), $key);
    }
    $this->assertFalse($matcher->satisfies('block:p', ':not(:last-child)'));
    $this->assertFalse($matcher->satisfies('container:sec-d', ':not(:last-child)'));
    $this->assertFalse($matcher->satisfies('block:t1', ':not(:first-child)'));
    $this->assertTrue($matcher->satisfies('block:t2', ':not(:first-child)'));
    $this->assertFalse($matcher->satisfies('block:t4', ':not(:only-child)'));
    $this->assertTrue($matcher->satisfies('block:t5', ':not(:only-child)'));
    $this->assertTrue($matcher->satisfies('container:sec-b', ':not(:empty)'));
    $this->assertFalse($matcher->satisfies('container:sec-d', ':not(:empty)'));
    // A block is never empty, so it is always not empty.
    $this->assertTrue($matcher->satisfies('block:t1', ':not(:empty)'));
    // An unknown wrapper stays unknown inside :not(): never a promise.
    $this->assertFalse($matcher->satisfies('block:t1', ':not(:nth-child(3n+1))'));
    $this->assertFalse($matcher->satisfies('block:ghost', ':not(:last-child)'));
    $this->assertFalse($matcher->satisfies('block:ghost', ':first-child'));

    $this->assertTrue(SelectorMatcher::typeMatches('pb-container', 'layout:x'));
    $this->assertTrue(SelectorMatcher::typeMatches('pb-block', 'block_plugin:x'));
    $this->assertFalse(SelectorMatcher::typeMatches('pb-block', 'layout:x'));
    $this->assertTrue(SelectorMatcher::typeMatches('pb-formatted-text', 'block_type:formatted_text'));

    $this->expectException(\InvalidArgumentException::class);
    $matcher->matching($a, 'p:hover');
  }

  /**
   * Sibling steps, `:has()` with every lead, `:not()`, and the reach limits.
   */
  public function testRelations(): void {
    $matcher = SelectorMatcher::fromTree(static::tree());
    $g = 'container:sec-g';
    // A sibling combinator steps along the parent's ordered children:
    // `+` the previous sibling, `~` any earlier one; the band's second
    // paragraph is the only p-root after a p-root, the heading is the
    // next sibling of a p-root too.
    $this->assertSame(['block:t10'], $matcher->matching($g, '> p + p'));
    $this->assertSame(['block:t10'], $matcher->matching($g, '> p ~ p'));
    $this->assertSame(['block:t10', 'block:t11'], $matcher->matching($g, '> p + *'));
    $this->assertSame(['block:t10', 'block:t11', 'block:img2', 'container:sec-h'], $matcher->matching($g, '> p ~ *'));
    $this->assertSame(['block:img2'], $matcher->matching($g, 'pb-text ~ pb-image'));
    $this->assertTrue($matcher->matches($g, '> p + p', 'block:t10'));
    $this->assertFalse($matcher->matches($g, '> p + p', 'block:t9'));
    // `:has()` anchors its argument at the qualified node: a tag by
    // descent reads the tag bag (the linked block's part renders an
    // anchor), a child lead a child (the bag has no depth, so `> a` on
    // a block is never backed), `+` the next sibling (the heading is the
    // text block right before the image), `~` any later one.
    $this->assertSame(['block:t10'], $matcher->matching($g, '> pb-text:has(a)'));
    $this->assertSame([], $matcher->matching($g, '> pb-text:has(> a)'));
    $this->assertSame(['block:t11'], $matcher->matching($g, '> pb-text:has(+ pb-image)'));
    $this->assertSame(['block:t9', 'block:t10', 'block:t11'], $matcher->matching($g, '> pb-text:has(~ pb-image)'));
    $this->assertSame(['container:sec-h'], $matcher->matching($g, '> pb-container:has(> pb-image)'));
    $this->assertSame(['container:sec-h'], $matcher->matching($g, '> *:has(pb-image)'));
    $this->assertSame(['block:img3'], $matcher->matching($g, '> pb-container:has(> pb-image) > *'));
    $this->assertSame(['container:sec-b'], $matcher->matching('container:sec-a', '> pb-container:has(> pb-image)'));
    // A region wrapper anchors a `:has()` like any node: the two-column
    // section's regions hold text blocks, though the regions themselves
    // are never listed (unaddressable).
    $this->assertSame([], $matcher->matching('container:sec-c', '> *:has(> pb-text)'));
    $this->assertSame(['block:t4', 'block:t5', 'block:t6'], $matcher->matching('container:sec-c', '> *:has(> pb-text) > *'));
    // `:not()` excludes what its compound matches on the node: a type,
    // a `:has()` (the two unlinked text blocks), and a part class judged
    // on the node's own anatomy: a node whose parts carry the class
    // (hoisted onto its root like the heading's `.featured`, or nested
    // inside it like the link part) is excluded; the flattened parts do
    // not tell the two apart, and excluding both never over-claims.
    $this->assertSame(['block:t9', 'block:t10', 'block:t11', 'container:sec-h'], $matcher->matching($g, '> *:not(pb-image)'));
    $this->assertSame(['container:sec-h'], $matcher->matching($g, '> *:not(pb-image):not(pb-text)'));
    $this->assertSame(['block:t9', 'block:t11'], $matcher->matching($g, '> pb-text:not(:has(a))'));
    $this->assertSame(['block:t9', 'block:t10'], $matcher->matching($g, '> pb-text:not(.featured)'));
    $this->assertSame(['block:t9', 'block:t11'], $matcher->matching($g, '> pb-text:not(.text-block-link)'));
    $this->assertSame(['block:t10'], $matcher->matching($g, '> pb-text:has(a):not(.featured)'));
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
    $this->assertTrue($matcher->reaches($g, '> pb-text:has(a) .text-block-link', 'block:t10', '.text-block-link'));
    $this->assertFalse($matcher->reaches($g, '> pb-text:not(:has(a)) .text-block-link', 'block:t10', '.text-block-link'));
    $this->assertTrue($matcher->reaches($g, '> pb-text:has(a) a', 'block:t10', 'a'));
    $this->assertFalse($matcher->reaches($g, '> pb-text:has(a) a', 'block:t9', 'a'));
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
    $this->assertSame(['block:t1', 'block:t2'], $matcher->matching($a, '> pb-text::before'));
    $this->assertSame(['block:t1'], $matcher->matching('block:t1', '&::before'));
    $this->assertSame([], $matcher->matching('block:ghost', '&::before'));
    $this->assertTrue($matcher->matches('block:t1', '&::after', 'block:t1'));
    $this->assertFalse($matcher->matches('block:t1', '&::after', 'block:t2'));
    $this->assertFalse($matcher->matches($a, '&::after', 'block:t1'));
    $this->assertTrue($matcher->matches($a, '> pb-text::before', 'block:t1'));
    // The own pseudo: the element's own rule reaches its own box, and
    // only that box; a rule for one box never lands on the root or on
    // the other box, and a pseudo-free rule never lands on a box.
    $this->assertTrue($matcher->reaches('block:t1', '&::before', 'block:t1', '&::before'));
    $this->assertFalse($matcher->reaches('block:t1', '&::before', 'block:t1', '&::after'));
    $this->assertFalse($matcher->reaches('block:t1', '&::before', 'block:t1'));
    $this->assertFalse($matcher->reaches('block:t1', '&::before', 'block:t2', '&::before'));
    // An ancestor's `> pb-text::before` reaches a text block child's
    // `&::before`, not its `&::after`, not its root, and not a nested
    // text block; by descent it reaches the nested one too.
    $this->assertTrue($matcher->reaches($a, '> pb-text::before', 'block:t1', '&::before'));
    $this->assertFalse($matcher->reaches($a, '> pb-text::before', 'block:t1', '&::after'));
    $this->assertFalse($matcher->reaches($a, '> pb-text::before', 'block:t1'));
    $this->assertFalse($matcher->reaches($a, '> pb-text', 'block:t1', '&::before'));
    $this->assertFalse($matcher->reaches($a, '> pb-text::before', 'block:t3', '&::before'));
    $this->assertTrue($matcher->reaches($a, 'pb-text::after', 'block:t3', '&::after'));
    $this->assertFalse($matcher->reaches($a, '> pb-text::before', 'block:f', '&::before'));
    $this->assertFalse($matcher->reaches('container:sec-b', '> pb-text::before', 'block:t1', '&::before'));
    // A part's pseudo: the class subject's box, or the box of the tag
    // the part renders, reached exactly as the part is; the pseudo must
    // agree, and the part alone is not its box.
    $this->assertTrue($matcher->reaches($e, '> pb-text .text-block-link::before', 'block:t7', '.text-block-link::before'));
    $this->assertFalse($matcher->reaches($e, '> pb-text .text-block-link::before', 'block:t7', '.text-block-link::after'));
    $this->assertFalse($matcher->reaches($e, '> pb-text .text-block-link::before', 'block:t7', '.text-block-link'));
    $this->assertFalse($matcher->reaches($e, '> pb-text .text-block-link', 'block:t7', '.text-block-link::before'));
    $this->assertFalse($matcher->reaches($e, '> pb-text .text-block-link::before', 'block:t8', '.text-block-link::before'));
    $this->assertTrue($matcher->reaches($e, 'pb-text > .text-block-link::after', 'block:t8', '.text-block-link::after'));
    $this->assertTrue($matcher->reaches($e, 'a::before', 'block:t7', '.text-block-link::before'));
    $this->assertFalse($matcher->reaches($e, '> a::before', 'block:t7', '.text-block-link::before'));
    $this->assertFalse($matcher->reaches($e, 'a::before', 'block:t7', '.text-block-note::before'));
    $this->assertTrue($matcher->reaches($e, 'h6::after', 'block:t7', '.text-block::after'));
    // The tag tier's pseudo: the box of every anchor inside.
    $this->assertTrue($matcher->reaches($a, 'a::before', 'block:t1', 'a::before'));
    $this->assertFalse($matcher->reaches($a, 'a::before', 'block:t1', 'a'));
    $this->assertFalse($matcher->reaches($a, 'a', 'block:t1', 'a::before'));
    $this->assertFalse($matcher->reaches($a, 'a::before', 'block:t2', 'a::before'));
    $this->assertFalse($matcher->reaches($a, '> pb-text a::before', 'block:t3', 'a::before'));
    // A chain's pseudo: the sibling step and the qualifier hold on the
    // host exactly as they do without the pseudo.
    $this->assertTrue($matcher->reaches($g, '> p + p::after', 'block:t10', '&::after'));
    $this->assertFalse($matcher->reaches($g, '> p + p::after', 'block:t9', '&::after'));
    $this->assertTrue($matcher->reaches($g, '> pb-text:has(a)::before', 'block:t10', '&::before'));
    $this->assertFalse($matcher->reaches($g, '> pb-text:has(a)::before', 'block:t9', '&::before'));
    $this->assertTrue($matcher->reaches($g, '> p + p a::before', 'block:t10', '.text-block-link::before'));
    $this->assertFalse($matcher->reaches($g, '> p + p::before', 'block:t10', 'p::before'));
    // A position that is a rule of its own, pseudo or not, is never a
    // tier; a selector key with a pseudo reads its owner's place.
    $this->assertFalse($matcher->reaches($a, '> *::before', 'block:t1', '> *::before'));
    $this->assertFalse($matcher->reaches($a, '> pb-text::before', 'block:t1', '> pb-text::before'));
    $this->assertTrue($matcher->satisfies('block:t1::&::before', ':first-child'));
    $this->assertTrue($matcher->satisfies('block:t2::.text-block-link::after', ':nth-child(even)'));

    $this->expectException(\InvalidArgumentException::class);
    $matcher->reaches($a, 'a', 'block:t1', 'a::marker');
  }

  /**
   * The client twin answers the battery exactly as this class does.
   */
  public function testClientTwinEquivalence(): void {
    $a = 'container:sec-a';
    $e = 'container:sec-e';
    $g = 'container:sec-g';
    $queries = [
      ['op' => 'has', 'args' => ['container:sec-d']],
      ['op' => 'has', 'args' => ['entity:root']],
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
      ['op' => 'matches', 'args' => [$a, 'pb-container', $a]],
      ['op' => 'matches', 'args' => [$a, '> pb-text', 'block:t3']],
      ['op' => 'matches', 'args' => ['container:sec-b', '> pb-text', 'block:t3']],
      ['op' => 'matches', 'args' => ['block:t1', '> *', 'block:t2']],
      ['op' => 'matches', 'args' => ['block:ghost', '> *', 'block:t2']],
      ['op' => 'matches', 'args' => [$a, 'p:hover', 'block:t1']],
      ['op' => 'satisfies', 'args' => ['block:t1', ':first-child']],
      ['op' => 'satisfies', 'args' => ['block:t2', ':nth-child(even)']],
      ['op' => 'satisfies', 'args' => ['block:p', ':last-child']],
      ['op' => 'satisfies', 'args' => ['block:t4', ':only-child']],
      ['op' => 'satisfies', 'args' => ['container:sec-d', ':empty']],
      ['op' => 'satisfies', 'args' => ['block:t1', ':empty']],
      ['op' => 'satisfies', 'args' => ['block:t1::> *', ':first-child']],
      ['op' => 'satisfies', 'args' => ['block:t1', ':nth-child(3n+1)']],
      ['op' => 'satisfies', 'args' => ['block:ghost', ':first-child']],
      ['op' => 'satisfies', 'args' => ['block:t1', ':not(:last-child)']],
      ['op' => 'satisfies', 'args' => ['container:sec-b', ':not(:last-child)']],
      ['op' => 'satisfies', 'args' => ['block:p', ':not(:last-child)']],
      ['op' => 'satisfies', 'args' => ['block:t1', ':not(:first-child)']],
      ['op' => 'satisfies', 'args' => ['block:t2', ':not(:first-child)']],
      ['op' => 'satisfies', 'args' => ['block:t4', ':not(:only-child)']],
      ['op' => 'satisfies', 'args' => ['block:t5', ':not(:only-child)']],
      ['op' => 'satisfies', 'args' => ['container:sec-b', ':not(:empty)']],
      ['op' => 'satisfies', 'args' => ['container:sec-d', ':not(:empty)']],
      ['op' => 'satisfies', 'args' => ['block:t1', ':not(:empty)']],
      ['op' => 'satisfies', 'args' => ['block:t1', ':not(:nth-child(3n+1))']],
      ['op' => 'satisfies', 'args' => ['block:ghost', ':not(:last-child)']],
    ];
    foreach ([
      '> *', '> pb-text', '> p', '> pb-block', '> pb-container', '> * > *', '> pb-container > pb-text',
      'pb-text', 'p', 'pb-block', 'pb-container > *', '.cls', '.cls > *',
      '> pb-formatted-text .s-p1', '> pb-block:not(.s-p1)', '> pb-block:not(.s-p9)',
    ] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => [$a, $selector]];
    }
    foreach (['> *', '> pb-text', '> * > *', 'pb-text', 'p'] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => ['container:sec-c', $selector]];
    }
    foreach (['> *', 'a', '> pb-text .text-block-link'] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => [$e, $selector]];
    }
    foreach ([
      '> p + p', '> p ~ p', '> p + *', '> p ~ *', 'pb-text ~ pb-image',
      '> pb-text:has(a)', '> pb-text:has(> a)', '> pb-text:has(+ pb-image)', '> pb-text:has(~ pb-image)',
      '> pb-container:has(> pb-image)', '> *:has(pb-image)', '> pb-container:has(> pb-image) > *',
      '> *:not(pb-image)', '> *:not(pb-image):not(pb-text)', '> pb-text:not(:has(a))', '> pb-text:not(.featured)',
      '> pb-text:not(.text-block-link)', '> pb-text:has(a):not(.featured)',
    ] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => [$g, $selector]];
    }
    foreach (['> pb-container:has(> pb-image)', '> *:has(> pb-text) > *'] as $selector) {
      $queries[] = ['op' => 'matching', 'args' => [$a, $selector]];
      $queries[] = ['op' => 'matching', 'args' => ['container:sec-c', $selector]];
    }
    $queries[] = ['op' => 'matches', 'args' => [$g, '> p + p', 'block:t10']];
    $queries[] = ['op' => 'matches', 'args' => [$g, '> p + p', 'block:t9']];
    foreach ([
      [$a, 'p', 'block:t1', NULL], [$a, 'p', 'block:f', NULL], [$a, 'p', 'block:f', 'p'], [$a, '> p', 'block:f', 'p'],
      [$a, 'a', 'block:t1', 'a'], [$a, 'a', 'block:t2', 'a'], [$a, 'pb-text a', 'block:t1', 'a'],
      [$a, '> pb-text em', 'block:t3', 'em'], [$a, 'pb-text em', 'block:t3', 'em'], [$a, 'pb-formatted-text p', 'block:f', 'p'],
      ['container:sec-b', 'p', 'block:f', 'p'], ['container:sec-c', 'a', 'block:t6', 'a'],
      [$e, '> pb-text .text-block-link', 'block:t7', '.text-block-link'], [$e, '> pb-text .text-block-link', 'block:t8', '.text-block-link'],
      [$e, 'pb-text > .text-block-link', 'block:t8', '.text-block-link'], [$e, '.text-block-link', 'block:t7', '.text-block-link'],
      [$e, 'a', 'block:t7', '.text-block-link'], [$e, '> a', 'block:t7', '.text-block-link'],
      [$e, '> pb-text a', 'block:t7', '.text-block-link'], [$e, '> pb-text > a', 'block:t7', '.text-block-link'],
      [$e, 'a', 'block:t8', '.text-block-link'], [$e, '> pb-text a', 'block:t8', '.text-block-link'],
      [$e, 'pb-container a', 'block:t8', '.text-block-link'], [$e, '> pb-container a', 'block:t8', '.text-block-link'],
      [$e, '> pb-container a', 'block:t7', '.text-block-link'], ['container:sec-f', 'a', 'block:t7', '.text-block-link'],
      [$e, 'small', 'block:t7', '.text-block-note'], [$e, 'a', 'block:t7', '.text-block-note'],
      [$e, 'svg', 'block:t7', '.text-block-link'], [$e, 'svg', 'block:t7', '.text-block-link__icon'],
      [$e, 'h6', 'block:t7', '.text-block'], [$e, 'a', 'block:t7', '.ghost'],
      [$e, 'a', 'block:t7', 'a'], [$e, 'pb-text a', 'block:t8', 'a'], [$e, '> pb-text a', 'block:t8', 'a'], [$e, 'em', 'block:t7', 'em'],
      [$a, '> *', 'block:t1', '> *'],
      // The identity: a minted instance by its tag, an identity class by
      // presence on the node, a declared class by anatomy.
      [$a, 'p', 'block:f', '.s-p1'], [$a, 'pb-formatted-text p', 'block:f', '.s-p1'], [$a, 'h2', 'block:f', '.s-p1'],
      [$a, 'li', 'block:f', '.s-li'], [$a, 'ul', 'block:f', '.s-li'], ['container:sec-c', 'p', 'block:t6', '.s-q1'],
      [$a, '> pb-formatted-text .s-p1', 'block:f', '.s-p1'], [$a, '> pb-formatted-text .s-p1', 'block:t1', '.s-p1'],
      [$a, '> pb-block .s-p1', 'block:t1', '.s-p1'], [$a, '> pb-formatted-text .s-p9', 'block:f', '.s-p9'],
      [$a, '> pb-formatted-text .s-li', 'block:f', '.s-li'],
      [$a, '> pb-text .text-block-link', 'block:t2', '.text-block-link'],
      [$g, '> p + p', 'block:t10', 'p'], [$g, 'p:has(a)', 'block:t10', 'p'], [$g, '> p + p', 'block:t10', NULL], [$g, '> p + p', 'block:t9', NULL],
      [$g, '> p + p a', 'block:t10', '.text-block-link'], [$g, '> p + p a', 'block:t9', '.text-block-link'], [$g, '> p + p a', 'block:t10', 'a'],
      [$g, '> pb-text:has(a) .text-block-link', 'block:t10', '.text-block-link'], [$g, '> pb-text:not(:has(a)) .text-block-link', 'block:t10', '.text-block-link'],
      [$g, '> pb-text:has(a) a', 'block:t10', 'a'], [$g, '> pb-text:has(a) a', 'block:t9', 'a'],
      // The terminal pseudo-element: agreement by name, then the host.
      ['block:t1', '&::before', 'block:t1', '&::before'], ['block:t1', '&::before', 'block:t1', '&::after'], ['block:t1', '&::before', 'block:t1', NULL],
      ['block:t1', '&::before', 'block:t2', '&::before'],
      [$a, '> pb-text::before', 'block:t1', '&::before'], [$a, '> pb-text::before', 'block:t1', '&::after'], [$a, '> pb-text::before', 'block:t1', NULL],
      [$a, '> pb-text', 'block:t1', '&::before'], [$a, '> pb-text::before', 'block:t3', '&::before'], [$a, 'pb-text::after', 'block:t3', '&::after'],
      [$a, '> pb-text::before', 'block:f', '&::before'], ['container:sec-b', '> pb-text::before', 'block:t1', '&::before'],
      [$e, '> pb-text .text-block-link::before', 'block:t7', '.text-block-link::before'], [$e, '> pb-text .text-block-link::before', 'block:t7', '.text-block-link::after'],
      [$e, '> pb-text .text-block-link::before', 'block:t7', '.text-block-link'], [$e, '> pb-text .text-block-link', 'block:t7', '.text-block-link::before'],
      [$e, '> pb-text .text-block-link::before', 'block:t8', '.text-block-link::before'], [$e, 'pb-text > .text-block-link::after', 'block:t8', '.text-block-link::after'],
      [$e, 'a::before', 'block:t7', '.text-block-link::before'], [$e, '> a::before', 'block:t7', '.text-block-link::before'],
      [$e, 'a::before', 'block:t7', '.text-block-note::before'], [$e, 'h6::after', 'block:t7', '.text-block::after'],
      [$a, 'a::before', 'block:t1', 'a::before'], [$a, 'a::before', 'block:t1', 'a'], [$a, 'a', 'block:t1', 'a::before'],
      [$a, 'a::before', 'block:t2', 'a::before'], [$a, '> pb-text a::before', 'block:t3', 'a::before'],
      [$g, '> p + p::after', 'block:t10', '&::after'], [$g, '> p + p::after', 'block:t9', '&::after'],
      [$g, '> pb-text:has(a)::before', 'block:t10', '&::before'], [$g, '> pb-text:has(a)::before', 'block:t9', '&::before'],
      [$g, '> p + p a::before', 'block:t10', '.text-block-link::before'], [$g, '> p + p::before', 'block:t10', 'p::before'],
      [$a, '> *::before', 'block:t1', '> *::before'], [$a, '> pb-text::before', 'block:t1', '> pb-text::before'],
      [$a, 'a', 'block:t1', 'a::marker'],
    ] as $args) {
      $queries[] = ['op' => 'reaches', 'args' => $args];
    }
    foreach ([[$a, '> pb-text::before'], ['block:t1', '&::before'], ['block:ghost', '&::before']] as $args) {
      $queries[] = ['op' => 'matching', 'args' => $args];
    }
    foreach ([['block:t1', '&::after', 'block:t1'], ['block:t1', '&::after', 'block:t2'], [$a, '&::after', 'block:t1'], [$a, '> pb-text::before', 'block:t1']] as $args) {
      $queries[] = ['op' => 'matches', 'args' => $args];
    }
    $queries[] = ['op' => 'satisfies', 'args' => ['block:t1::&::before', ':first-child']];
    $queries[] = ['op' => 'satisfies', 'args' => ['block:t2::.text-block-link::after', ':nth-child(even)']];

    $matcher = SelectorMatcher::fromTree(static::tree());
    $expected = array_map(static function (array $query) use ($matcher): mixed {
      try {
        return $matcher->{$query['op']}(...$query['args']);
      }
      catch (\InvalidArgumentException) {
        return ['error' => TRUE];
      }
    }, $queries);
    $stdout = $this->runNodeScript('tests/js/selector-matcher-cases.mjs', json_encode(['tree' => static::tree(), 'queries' => $queries], JSON_THROW_ON_ERROR));
    $this->assertSame($expected, json_decode($stdout, TRUE, 512, JSON_THROW_ON_ERROR)['answers'] ?? NULL, 'The client twin answers the battery exactly as the matcher does.');
  }

}
