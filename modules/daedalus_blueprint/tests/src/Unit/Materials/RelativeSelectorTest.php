<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\Materials;

// cspell:ignore childx
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The relative selector grammar: parse, canonicalize, print, the twins.
 *
 * The one suffix grammar every styling key speaks: chains of compounds
 * behind the four combinators, each compound narrowed by classes,
 * attributes, pseudo-classes, `:has()` and `:not()` in CSS's own
 * recursive shape, the owner itself addressable as the qualified anchor,
 * read leniently on case, spacing and quoting. The client twin
 * (daedalus_ui's relative-selector.js) is locked to this class by the
 * shared battery testClientTwinEquivalence pipes through
 * tests/js/relative-selector-cases.mjs, the PrinterEquivalenceTest
 * pattern.
 */
#[Group('daedalus_blueprint')]
class RelativeSelectorTest extends UnitTestCase {

  use NodeSuitesTrait;

  /**
   * The twin lives beside daedalus_ui's roster twin, so node runs there.
   */
  protected static function nodeModuleRoot(): string {
    return dirname((string) (new \ReflectionClass(static::class))->getFileName(), 6) . '/daedalus_ui';
  }

  /**
   * A compound record.
   */
  private static function compound(string $combinator, ?string $kind, ?string $name, array $qualifiers = []): array {
    return ['combinator' => $combinator, 'kind' => $kind, 'name' => $name, 'qualifiers' => $qualifiers];
  }

  /**
   * A `:has()` qualifier over a chain of compounds.
   */
  private static function has(array $argument): array {
    return ['name' => 'has', 'argument' => $argument];
  }

  /**
   * A `:not()` qualifier over one compound (kind and name NULL when implied).
   */
  private static function not(?string $kind, ?string $name, array $qualifiers = []): array {
    return [
      'name' => 'not',
      'argument' => ['combinator' => NULL, 'kind' => $kind, 'name' => $name, 'qualifiers' => $qualifiers],
    ];
  }

  /**
   * An `:is()` qualifier over two or more member compounds.
   */
  private static function is(array ...$members): array {
    return ['name' => 'is', 'argument' => $members];
  }

  /**
   * An `:is()` member: a predicate compound (kind and name NULL when implied).
   */
  private static function member(?string $kind, ?string $name, array $qualifiers = []): array {
    return ['combinator' => NULL, 'kind' => $kind, 'name' => $name, 'qualifiers' => $qualifiers];
  }

  /**
   * A pseudo-class qualifier (the argument NULL, or the canonical `An+B`).
   */
  private static function at(string $name, ?string $argument = NULL): array {
    return ['name' => $name, 'argument' => $argument];
  }

  /**
   * A class qualifier (the name without its dot).
   */
  private static function cls(string $name): array {
    return ['name' => 'class', 'argument' => $name];
  }

  /**
   * An attribute qualifier.
   */
  private static function attr(string $attribute, ?string $operator = NULL, ?string $value = NULL, ?string $flag = NULL): array {
    return [
      'name' => 'attribute',
      'argument' => ['attribute' => $attribute, 'operator' => $operator, 'value' => $value, 'flag' => $flag],
    ];
  }

  /**
   * The qualified anchor compound.
   */
  private static function anchor(array $qualifiers): array {
    return ['combinator' => 'descendant', 'kind' => 'anchor', 'name' => '&', 'qualifiers' => $qualifiers];
  }

  /**
   * Every selector shape parses, prints canonical, and reads its compounds.
   */
  #[DataProvider('shapes')]
  public function testParseAndPrint(string $selector, array $compounds, bool $part_first, bool $reaches_children, ?string $pseudo = NULL): void {
    $parsed = RelativeSelector::parse($selector);
    $this->assertNotNull($parsed, "$selector parses.");
    $this->assertSame($compounds, $parsed->compounds);
    $this->assertSame($pseudo, $parsed->pseudo);
    $this->assertSame($selector, (string) $parsed, 'The print is the parse\'s inverse.');
    $this->assertSame($selector, $parsed->print());
    $this->assertTrue(RelativeSelector::isCanonical($selector));
    $this->assertSame($part_first, $parsed->isPartFirst());
    $this->assertSame($reaches_children, $parsed->reachesChildren());
    $this->assertSame(count($compounds), $parsed->count());
    $this->assertTrue($parsed->equals(RelativeSelector::fromString($selector)));
    if ($compounds === []) {
      // The owner's own pseudo-element: no compound to read, and a
      // consumer asking for one is refused rather than handed nothing.
      $this->expectException(\LogicException::class);
      $parsed->last();
    }
    $this->assertSame($compounds[0], $parsed->first());
    $this->assertSame($compounds[array_key_last($compounds)], $parsed->last());
  }

  /**
   * Selector shapes and their compounds.
   */
  public static function shapes(): array {
    $c = self::compound(...);
    $has = self::has(...);
    $not = self::not(...);
    $at = self::at(...);
    $cls = self::cls(...);
    $attr = self::attr(...);
    $anchor = self::anchor(...);
    $is = self::is(...);
    $member = self::member(...);
    return [
      // `:is()`: any of several compounds at the compound it qualifies,
      // the members sorted by printed form, each a predicate compound
      // (the implied subject prints nothing), on any subject kind, the
      // anchor included, inside `:has()`, among other qualifiers, before
      // the box.
      'is over tags' => [
        '*:is(h1, h2)',
        [$c('descendant', 'universal', '*', [$is($member('tag', 'h1'), $member('tag', 'h2'))])],
        FALSE,
        FALSE,
      ],
      'is over states then child' => [
        '.menu-item:is(:focus-within, :hover) > ul',
        [
          $c('descendant', 'class', '.menu-item', [$is($member(NULL, NULL, [$at('focus-within')]), $member(NULL, NULL, [$at('hover')]))]),
          $c('child', 'tag', 'ul'),
        ],
        TRUE,
        TRUE,
      ],
      'is over parts' => [
        '*:is(.a, .b)',
        [$c('descendant', 'universal', '*', [$is($member('class', '.a'), $member('class', '.b'))])],
        FALSE,
        FALSE,
      ],
      'children is over tags' => [
        '> *:is(div, p)',
        [$c('child', 'universal', '*', [$is($member('tag', 'div'), $member('tag', 'p'))])],
        FALSE,
        TRUE,
      ],
      'is over a negation and a tag' => [
        '*:is(:not(.b), a)',
        [$c('descendant', 'universal', '*', [$is($member(NULL, NULL, [$not('class', '.b')]), $member('tag', 'a'))])],
        FALSE,
        FALSE,
      ],
      'is inside has' => [
        '> d-text:has(*:is(a, button))',
        [$c('child', 'type', 'd-text', [$has([$c('descendant', 'universal', '*', [$is($member('tag', 'a'), $member('tag', 'button'))])])])],
        FALSE,
        TRUE,
      ],
      'is over types by descent' => [
        '*:is(d-image, d-text)',
        [$c('descendant', 'universal', '*', [$is($member('type', 'd-image'), $member('type', 'd-text'))])],
        FALSE,
        TRUE,
      ],
      'is on the anchor then child' => [
        '&:is(.a, .b) > ul',
        [$anchor([$is($member('class', '.a'), $member('class', '.b'))]), $c('child', 'tag', 'ul')],
        FALSE,
        TRUE,
      ],
      'is among qualifiers' => [
        'li:hover:is(.a, .b):not(.c)',
        [
          $c('descendant', 'tag', 'li', [
            $at('hover'),
            $is($member('class', '.a'), $member('class', '.b')),
            $not('class', '.c'),
          ]),
        ],
        FALSE,
        FALSE,
      ],
      'is over positions on a type then descendant' => [
        '> d-text:is(:first-child, :last-child) a',
        [
          $c('child', 'type', 'd-text', [$is($member(NULL, NULL, [$at('first-child')]), $member(NULL, NULL, [$at('last-child')]))]),
          $c('descendant', 'tag', 'a'),
        ],
        FALSE,
        TRUE,
      ],
      'is member with has' => [
        '*:is(a:has(img), button)',
        [$c('descendant', 'universal', '*', [$is($member('tag', 'a', [$has([$c('descendant', 'tag', 'img')])]), $member('tag', 'button'))])],
        FALSE,
        FALSE,
      ],
      'is over a class and an attribute' => [
        'a:is(.x, [href])',
        [$c('descendant', 'tag', 'a', [$is($member('class', '.x'), $member(NULL, NULL, [$attr('href')]))])],
        FALSE,
        FALSE,
      ],
      'is then box' => [
        '*:is(h1, h2)::before',
        [$c('descendant', 'universal', '*', [$is($member('tag', 'h1'), $member('tag', 'h2'))])],
        FALSE,
        FALSE,
        'before',
      ],
      // Position pseudo-classes qualify any compound: the seven without
      // an argument, the four `An+B` forms canonical as CSS serializes them,
      // inside `:not()` (the implied subject) and inside `:has()`.
      'children not last' => [
        '> *:not(:last-child)',
        [$c('child', 'universal', '*', [$not(NULL, NULL, [$at('last-child')])])],
        FALSE,
        TRUE,
      ],
      'first child' => ['> *:first-child', [$c('child', 'universal', '*', [$at('first-child')])], FALSE, TRUE],
      'two positions sorted' => [
        '> *:first-child:last-child',
        [$c('child', 'universal', '*', [$at('first-child'), $at('last-child')])],
        FALSE,
        TRUE,
      ],
      'tag nth-child' => [
        'li:nth-child(2n+1)',
        [$c('descendant', 'tag', 'li', [$at('nth-child', '2n+1')])],
        FALSE,
        FALSE,
      ],
      'tag nth-child odd' => [
        'li:nth-child(odd)',
        [$c('descendant', 'tag', 'li', [$at('nth-child', 'odd')])],
        FALSE,
        FALSE,
      ],
      'type nth-of-type' => [
        '> d-text:nth-of-type(2)',
        [$c('child', 'type', 'd-text', [$at('nth-of-type', '2')])],
        FALSE,
        TRUE,
      ],
      'type nth-last-child' => [
        '> d-text:nth-last-child(-n+3)',
        [$c('child', 'type', 'd-text', [$at('nth-last-child', '-n+3')])],
        FALSE,
        TRUE,
      ],
      'type nth-last-of-type' => [
        '> d-text:nth-last-of-type(even)',
        [$c('child', 'type', 'd-text', [$at('nth-last-of-type', 'even')])],
        FALSE,
        TRUE,
      ],
      'part only child' => ['.cls:only-child', [$c('descendant', 'class', '.cls', [$at('only-child')])], TRUE, FALSE],
      'type empty' => ['> d-text:empty', [$c('child', 'type', 'd-text', [$at('empty')])], FALSE, TRUE],
      'type first-of-type last-of-type only-of-type' => [
        '> d-text:first-of-type:last-of-type:only-of-type',
        [$c('child', 'type', 'd-text', [$at('first-of-type'), $at('last-of-type'), $at('only-of-type')])],
        FALSE,
        TRUE,
      ],
      'position among qualifiers' => [
        '> d-text:first-child:has(a):not(.featured):nth-child(n)',
        [
          $c('child', 'type', 'd-text', [
            $at('first-child'),
            $has([$c('descendant', 'tag', 'a')]),
            $not('class', '.featured'),
            $at('nth-child', 'n'),
          ]),
        ],
        FALSE,
        TRUE,
      ],
      'position inside has' => [
        '> d-text:has(a:first-child)',
        [$c('child', 'type', 'd-text', [$has([$c('descendant', 'tag', 'a', [$at('first-child')])])])],
        FALSE,
        TRUE,
      ],
      'position on a not subject' => [
        '> d-text:not(p:first-child)',
        [$c('child', 'type', 'd-text', [$not('tag', 'p', [$at('first-child')])])],
        FALSE,
        TRUE,
      ],
      'position then box' => [
        '> *:not(:last-child)::after',
        [$c('child', 'universal', '*', [$not(NULL, NULL, [$at('last-child')])])],
        FALSE,
        TRUE,
        'after',
      ],
      // The qualified anchor: the owner itself as the leading compound,
      // alone, with its subtree behind it, with its box.
      'anchor first child' => ['&:first-child', [$anchor([$at('first-child')])], FALSE, FALSE],
      'anchor not last then children' => [
        '&:not(:last-child) > *',
        [$anchor([$not(NULL, NULL, [$at('last-child')])]), $c('child', 'universal', '*')],
        FALSE,
        TRUE,
      ],
      'anchor nth then part' => [
        '&:nth-child(2n) .cls',
        [$anchor([$at('nth-child', '2n')]), $c('descendant', 'class', '.cls')],
        TRUE,
        FALSE,
      ],
      'anchor then child part' => [
        '&:first-child > .cls',
        [$anchor([$at('first-child')]), $c('child', 'class', '.cls')],
        TRUE,
        TRUE,
      ],
      'anchor then tag' => [
        '&:last-child p',
        [$anchor([$at('last-child')]), $c('descendant', 'tag', 'p')],
        FALSE,
        FALSE,
      ],
      'anchor then type' => [
        '&:only-child > d-text .link',
        [$anchor([$at('only-child')]), $c('child', 'type', 'd-text'), $c('descendant', 'class', '.link')],
        FALSE,
        TRUE,
      ],
      'anchor two positions' => [
        '&:first-child:last-child',
        [$anchor([$at('first-child'), $at('last-child')])],
        FALSE,
        FALSE,
      ],
      'anchor has' => [
        '&:has(a) p',
        [$anchor([$has([$c('descendant', 'tag', 'a')])]), $c('descendant', 'tag', 'p')],
        FALSE,
        FALSE,
      ],
      'anchor not part' => [
        '&:not(.x) > *',
        [$anchor([$not('class', '.x')]), $c('child', 'universal', '*')],
        FALSE,
        TRUE,
      ],
      'anchor before' => ['&:first-child::before', [$anchor([$at('first-child')])], FALSE, FALSE, 'before'],
      'anchor chain after' => [
        '&:first-child > *:not(:last-child)::after',
        [$anchor([$at('first-child')]), $c('child', 'universal', '*', [$not(NULL, NULL, [$at('last-child')])])],
        FALSE,
        TRUE,
        'after',
      ],
      // A. A state (any pseudo-class) on a non-terminal compound, the
      // anchor included; a bare pseudo-class is the universal's.
      'state on a tag then child' => [
        'li:hover > ul',
        [$c('descendant', 'tag', 'li', [$at('hover')]), $c('child', 'tag', 'ul')],
        FALSE,
        TRUE,
      ],
      'position on a tag then child' => [
        'li:first-child > a',
        [$c('descendant', 'tag', 'li', [$at('first-child')]), $c('child', 'tag', 'a')],
        FALSE,
        TRUE,
      ],
      'state on a tag then descendant' => [
        'a:hover span',
        [$c('descendant', 'tag', 'a', [$at('hover')]), $c('descendant', 'tag', 'span')],
        FALSE,
        FALSE,
      ],
      'state on a part then descendant' => [
        '.card:hover img',
        [$c('descendant', 'class', '.card', [$at('hover')]), $c('descendant', 'tag', 'img')],
        TRUE,
        FALSE,
      ],
      'state mid chain' => [
        '> ul li:hover > ul',
        [$c('child', 'tag', 'ul'), $c('descendant', 'tag', 'li', [$at('hover')]), $c('child', 'tag', 'ul')],
        FALSE,
        TRUE,
      ],
      'state on the anchor then child' => [
        '&:hover > ul',
        [$anchor([$at('hover')]), $c('child', 'tag', 'ul')],
        FALSE,
        TRUE,
      ],
      'state on the universal then child' => [
        '*:hover > ul',
        [$c('descendant', 'universal', '*', [$at('hover')]), $c('child', 'tag', 'ul')],
        FALSE,
        TRUE,
      ],
      'state on a type then descendant' => [
        '> d-text:hover a',
        [$c('child', 'type', 'd-text', [$at('hover')]), $c('descendant', 'tag', 'a')],
        FALSE,
        TRUE,
      ],
      // B. A state on the last compound (whether it peels into a
      // condition is the digester's; the grammar carries a qualifier).
      'state on a tag' => ['a:hover', [$c('descendant', 'tag', 'a', [$at('hover')])], FALSE, FALSE],
      'state on a type' => ['> d-text:hover', [$c('child', 'type', 'd-text', [$at('hover')])], FALSE, TRUE],
      'state on the anchor' => ['&:hover', [$anchor([$at('hover')])], FALSE, FALSE],
      'state on the universal' => ['*:hover', [$c('descendant', 'universal', '*', [$at('hover')])], FALSE, FALSE],
      'unpublished state' => ['a:visited', [$c('descendant', 'tag', 'a', [$at('visited')])], FALSE, FALSE],
      'input state' => ['input:checked', [$c('descendant', 'tag', 'input', [$at('checked')])], FALSE, FALSE],
      'position and state sorted' => [
        'li:first-child:hover',
        [$c('descendant', 'tag', 'li', [$at('first-child'), $at('hover')])],
        FALSE,
        FALSE,
      ],
      'two states sorted' => [
        'p:active:hover',
        [$c('descendant', 'tag', 'p', [$at('active'), $at('hover')])],
        FALSE,
        FALSE,
      ],
      'state then box' => ['&:hover::before', [$anchor([$at('hover')])], FALSE, FALSE, 'before'],
      // E. A negated state. F. A state inside a qualifier.
      'negated state' => [
        'li:not(:hover)',
        [$c('descendant', 'tag', 'li', [$not(NULL, NULL, [$at('hover')])])],
        FALSE,
        FALSE,
      ],
      'negated focus-visible' => [
        'a:not(:focus-visible)',
        [$c('descendant', 'tag', 'a', [$not(NULL, NULL, [$at('focus-visible')])])],
        FALSE,
        FALSE,
      ],
      'state inside has' => [
        '> d-text:has(a:hover)',
        [$c('child', 'type', 'd-text', [$has([$c('descendant', 'tag', 'a', [$at('hover')])])])],
        FALSE,
        TRUE,
      ],
      'state on a has child' => [
        'li:has(> ul:hover)',
        [$c('descendant', 'tag', 'li', [$has([$c('child', 'tag', 'ul', [$at('hover')])])])],
        FALSE,
        FALSE,
      ],
      'state alone inside has' => [
        '*:has(*:focus-within)',
        [$c('descendant', 'universal', '*', [$has([$c('descendant', 'universal', '*', [$at('focus-within')])])])],
        FALSE,
        FALSE,
      ],
      // G. A class on any compound: a tag, a type, the anchor; a second
      // class on a part is the part's qualifier; a class after a tag or
      // the children is a compound of its own.
      'class on a tag then child' => [
        'li.active > ul',
        [$c('descendant', 'tag', 'li', [$cls('active')]), $c('child', 'tag', 'ul')],
        FALSE,
        TRUE,
      ],
      'class on a tag' => ['li.open', [$c('descendant', 'tag', 'li', [$cls('open')])], FALSE, FALSE],
      'class on the anchor then child' => [
        '&.open > ul',
        [$anchor([$cls('open')]), $c('child', 'tag', 'ul')],
        FALSE,
        TRUE,
      ],
      'class on the anchor then part' => [
        '&.open .menu',
        [$anchor([$cls('open')]), $c('descendant', 'class', '.menu')],
        TRUE,
        FALSE,
      ],
      'class on the anchor' => ['&.open', [$anchor([$cls('open')])], FALSE, FALSE],
      'class on a link' => ['a.button', [$c('descendant', 'tag', 'a', [$cls('button')])], FALSE, FALSE],
      'part then child tag' => [
        '.is-open > ul',
        [$c('descendant', 'class', '.is-open'), $c('child', 'tag', 'ul')],
        TRUE,
        TRUE,
      ],
      'class on a type' => ['> d-text.featured', [$c('child', 'type', 'd-text', [$cls('featured')])], FALSE, TRUE],
      'two classes on a part' => ['.a.b', [$c('descendant', 'class', '.a', [$cls('b')])], TRUE, FALSE],
      'two classes on a tag' => ['li.a.b', [$c('descendant', 'tag', 'li', [$cls('a'), $cls('b')])], FALSE, FALSE],
      'two classes on the anchor' => ['&.a.b', [$anchor([$cls('a'), $cls('b')])], FALSE, FALSE],
      'class and state on a tag' => [
        'li.active:hover > ul',
        [$c('descendant', 'tag', 'li', [$cls('active'), $at('hover')]), $c('child', 'tag', 'ul')],
        FALSE,
        TRUE,
      ],
      'two classes inside not' => [
        'p:not(.a.b)',
        [$c('descendant', 'tag', 'p', [$not('class', '.a', [$cls('b')])])],
        FALSE,
        FALSE,
      ],
      'part after part' => ['.a .b', [$c('descendant', 'class', '.a'), $c('descendant', 'class', '.b')], TRUE, FALSE],
      'part after tag' => ['p .a', [$c('descendant', 'tag', 'p'), $c('descendant', 'class', '.a')], FALSE, FALSE],
      'part after children' => ['> * .a', [$c('child', 'universal', '*'), $c('descendant', 'class', '.a')], FALSE, TRUE],
      'part after tag by sibling' => [
        'p + .cls',
        [$c('descendant', 'tag', 'p'), $c('next', 'class', '.cls')],
        FALSE,
        TRUE,
      ],
      // H. Attribute predicates: the six operators, the flag, several on
      // one compound, inside `:not()`, on the anchor, leading.
      'attribute prefix' => [
        'a[href^="http"]',
        [$c('descendant', 'tag', 'a', [$attr('href', '^=', 'http')])],
        FALSE,
        FALSE,
      ],
      'attribute equals' => [
        'input[type="checkbox"]',
        [$c('descendant', 'tag', 'input', [$attr('type', '=', 'checkbox')])],
        FALSE,
        FALSE,
      ],
      'attribute leading then child' => [
        '*[aria-expanded="true"] > ul',
        [$c('descendant', 'universal', '*', [$attr('aria-expanded', '=', 'true')]), $c('child', 'tag', 'ul')],
        FALSE,
        TRUE,
      ],
      'attribute on a tag' => [
        'li[aria-current="page"]',
        [$c('descendant', 'tag', 'li', [$attr('aria-current', '=', 'page')])],
        FALSE,
        FALSE,
      ],
      'attribute underscore value' => [
        'a[target="_blank"]',
        [$c('descendant', 'tag', 'a', [$attr('target', '=', '_blank')])],
        FALSE,
        FALSE,
      ],
      'attribute presence' => ['*[x]', [$c('descendant', 'universal', '*', [$attr('x')])], FALSE, FALSE],
      'attribute presence on children' => [
        '> *[hidden]',
        [$c('child', 'universal', '*', [$attr('hidden')])],
        FALSE,
        TRUE,
      ],
      'attribute on the anchor' => [
        '&[aria-expanded="true"] > ul',
        [$anchor([$attr('aria-expanded', '=', 'true')]), $c('child', 'tag', 'ul')],
        FALSE,
        TRUE,
      ],
      'attribute dash match' => [
        '*[lang|="en"]',
        [$c('descendant', 'universal', '*', [$attr('lang', '|=', 'en')])],
        FALSE,
        FALSE,
      ],
      'attribute word match' => [
        '*[class~="x"]',
        [$c('descendant', 'universal', '*', [$attr('class', '~=', 'x')])],
        FALSE,
        FALSE,
      ],
      'attribute suffix insensitive' => [
        '*[data-x$="y" i]',
        [$c('descendant', 'universal', '*', [$attr('data-x', '$=', 'y', 'i')])],
        FALSE,
        FALSE,
      ],
      'attribute substring sensitive' => [
        '*[data-x*="y" s]',
        [$c('descendant', 'universal', '*', [$attr('data-x', '*=', 'y', 's')])],
        FALSE,
        FALSE,
      ],
      'two attributes' => [
        'a[href^="http"][target="_blank"]',
        [$c('descendant', 'tag', 'a', [$attr('href', '^=', 'http'), $attr('target', '=', '_blank')])],
        FALSE,
        FALSE,
      ],
      'attribute inside not' => [
        '*:not([hidden])',
        [$c('descendant', 'universal', '*', [$not(NULL, NULL, [$attr('hidden')])])],
        FALSE,
        FALSE,
      ],
      'attribute empty value' => ['*[x=""]', [$c('descendant', 'universal', '*', [$attr('x', '=', '')])], FALSE, FALSE],
      'attribute escaped quote' => [
        '*[x="say \"hi\""]',
        [$c('descendant', 'universal', '*', [$attr('x', '=', 'say "hi"')])],
        FALSE,
        FALSE,
      ],
      'attribute escaped backslash' => [
        '*[x="a\\\\b"]',
        [$c('descendant', 'universal', '*', [$attr('x', '=', 'a\\b')])],
        FALSE,
        FALSE,
      ],
      'attribute control character' => [
        '*[x="tab\9 "]',
        [$c('descendant', 'universal', '*', [$attr('x', '=', "tab\t")])],
        FALSE,
        FALSE,
      ],
      'attribute value with a double colon' => [
        '*[data-x="a::b"]',
        [$c('descendant', 'universal', '*', [$attr('data-x', '=', 'a::b')])],
        FALSE,
        FALSE,
      ],
      'attribute value with a paren inside has' => [
        '> d-text:has(a[data-x=")"])',
        [$c('child', 'type', 'd-text', [$has([$c('descendant', 'tag', 'a', [$attr('data-x', '=', ')')])])])],
        FALSE,
        TRUE,
      ],
      'class state attribute sorted' => [
        'li.active:hover[aria-current="page"]',
        [$c('descendant', 'tag', 'li', [$cls('active'), $at('hover'), $attr('aria-current', '=', 'page')])],
        FALSE,
        FALSE,
      ],
      'attribute then box' => [
        'a[href^="http"]::after',
        [$c('descendant', 'tag', 'a', [$attr('href', '^=', 'http')])],
        FALSE,
        FALSE,
        'after',
      ],
      'class then box' => [
        'li.active::before',
        [$c('descendant', 'tag', 'li', [$cls('active')])],
        FALSE,
        FALSE,
        'before',
      ],
      // The implied subject is the universal in a chain.
      'position on the universal' => [
        '*:first-child',
        [$c('descendant', 'universal', '*', [$at('first-child')])],
        FALSE,
        FALSE,
      ],
      'qualified universal by descent' => [
        'd-text *:has(a)',
        [$c('descendant', 'type', 'd-text'), $c('descendant', 'universal', '*', [$has([$c('descendant', 'tag', 'a')])])],
        FALSE,
        TRUE,
      ],
      'part' => ['.cls', [$c('descendant', 'class', '.cls')], TRUE, FALSE],
      'tag' => ['p', [$c('descendant', 'tag', 'p')], FALSE, FALSE],
      'children' => ['> *', [$c('child', 'universal', '*')], FALSE, TRUE],
      'part children' => ['.cls > *', [$c('descendant', 'class', '.cls'), $c('child', 'universal', '*')], TRUE, TRUE],
      'tag children' => ['p > *', [$c('descendant', 'tag', 'p'), $c('child', 'universal', '*')], FALSE, TRUE],
      'child type' => ['> d-text', [$c('child', 'type', 'd-text')], FALSE, TRUE],
      'descendant type' => ['d-text', [$c('descendant', 'type', 'd-text')], FALSE, TRUE],
      'type part' => [
        '> d-text .link',
        [$c('child', 'type', 'd-text'), $c('descendant', 'class', '.link')],
        FALSE,
        TRUE,
      ],
      'child part' => ['> .cls', [$c('child', 'class', '.cls')], TRUE, TRUE],
      'part tag' => ['.cls p', [$c('descendant', 'class', '.cls'), $c('descendant', 'tag', 'p')], TRUE, FALSE],
      'grandchildren' => [
        'd-container > * > *',
        [$c('descendant', 'type', 'd-container'), $c('child', 'universal', '*'), $c('child', 'universal', '*')],
        FALSE,
        TRUE,
      ],
      'type tag' => [
        '> d-formatted-text p',
        [$c('child', 'type', 'd-formatted-text'), $c('descendant', 'tag', 'p')],
        FALSE,
        TRUE,
      ],
      'next sibling' => ['> p + p', [$c('child', 'tag', 'p'), $c('next', 'tag', 'p')], FALSE, TRUE],
      'subsequent sibling' => ['p ~ p', [$c('descendant', 'tag', 'p'), $c('subsequent', 'tag', 'p')], FALSE, TRUE],
      'next universal' => ['> p + *', [$c('child', 'tag', 'p'), $c('next', 'universal', '*')], FALSE, TRUE],
      'part sibling' => [
        '> d-text .link ~ .link',
        [$c('child', 'type', 'd-text'), $c('descendant', 'class', '.link'), $c('subsequent', 'class', '.link')],
        FALSE,
        TRUE,
      ],
      'has' => ['> d-text:has(a)', [$c('child', 'type', 'd-text', [$has([$c('descendant', 'tag', 'a')])])], FALSE, TRUE],
      'has next' => [
        '> d-text:has(+ d-image)',
        [$c('child', 'type', 'd-text', [$has([$c('next', 'type', 'd-image')])])],
        FALSE,
        TRUE,
      ],
      'has child then children' => [
        '> d-container:has(> d-image) > *',
        [$c('child', 'type', 'd-container', [$has([$c('child', 'type', 'd-image')])]), $c('child', 'universal', '*')],
        FALSE,
        TRUE,
      ],
      'two has' => [
        '> d-text:has(a):has(img)',
        [$c('child', 'type', 'd-text', [$has([$c('descendant', 'tag', 'a')]), $has([$c('descendant', 'tag', 'img')])])],
        FALSE,
        TRUE,
      ],
      'not' => ['> *:not(d-image)', [$c('child', 'universal', '*', [$not('type', 'd-image')])], FALSE, TRUE],
      'two nots' => [
        '> *:not(d-image):not(d-video)',
        [$c('child', 'universal', '*', [$not('type', 'd-image'), $not('type', 'd-video')])],
        FALSE,
        TRUE,
      ],
      'not has' => [
        '> d-text:not(:has(a))',
        [$c('child', 'type', 'd-text', [$not(NULL, NULL, [$has([$c('descendant', 'tag', 'a')])])])],
        FALSE,
        TRUE,
      ],
      'has then not' => [
        '> d-text:has(a):not(.featured)',
        [$c('child', 'type', 'd-text', [$has([$c('descendant', 'tag', 'a')]), $not('class', '.featured')])],
        FALSE,
        TRUE,
      ],
      'qualified part first' => [
        '.cls:not(.x)',
        [$c('descendant', 'class', '.cls', [$not('class', '.x')])],
        TRUE,
        FALSE,
      ],
      'qualifier argument reaches nothing' => [
        'p:has(> a)',
        [$c('descendant', 'tag', 'p', [$has([$c('child', 'tag', 'a')])])],
        FALSE,
        FALSE,
      ],
      // The terminal pseudo-element rides the selector, never a compound;
      // any name the browser spells (K): the two generated boxes, the five
      // with a vocabulary of their own, any other, the legacy single
      // colon for the four it still names.
      'tag marker' => ['li::marker', [$c('descendant', 'tag', 'li')], FALSE, FALSE, 'marker'],
      'attribute placeholder' => [
        'input[type="text"]::placeholder',
        [$c('descendant', 'tag', 'input', [$attr('type', '=', 'text')])],
        FALSE,
        FALSE,
        'placeholder',
      ],
      'own selection' => ['&::selection', [], FALSE, FALSE, 'selection'],
      'child type first letter' => [
        '> d-text::first-letter',
        [$c('child', 'type', 'd-text')],
        FALSE,
        TRUE,
        'first-letter',
      ],
      'tag first line' => ['p::first-line', [$c('descendant', 'tag', 'p')], FALSE, FALSE, 'first-line'],
      'own backdrop' => ['&::backdrop', [], FALSE, FALSE, 'backdrop'],
      'state then marker' => [
        'li:hover::marker',
        [$c('descendant', 'tag', 'li', [$at('hover')])],
        FALSE,
        FALSE,
        'marker',
      ],
      'single-colon marker is a pseudo-class' => [
        'p:marker',
        [$c('descendant', 'tag', 'p', [$at('marker')])],
        FALSE,
        FALSE,
      ],
      // The owner's own has no compounds at all.
      'own before' => ['&::before', [], FALSE, FALSE, 'before'],
      'own after' => ['&::after', [], FALSE, FALSE, 'after'],
      'part after' => ['.quote::after', [$c('descendant', 'class', '.quote')], TRUE, FALSE, 'after'],
      'tag before' => ['p::before', [$c('descendant', 'tag', 'p')], FALSE, FALSE, 'before'],
      'child type before' => ['> d-text::before', [$c('child', 'type', 'd-text')], FALSE, TRUE, 'before'],
      'children before' => ['> *::before', [$c('child', 'universal', '*')], FALSE, TRUE, 'before'],
      'sibling after' => ['> p + p::after', [$c('child', 'tag', 'p'), $c('next', 'tag', 'p')], FALSE, TRUE, 'after'],
      'qualified before' => [
        '> d-text:has(a)::before',
        [$c('child', 'type', 'd-text', [$has([$c('descendant', 'tag', 'a')])])],
        FALSE,
        TRUE,
        'before',
      ],
      'type part before' => [
        '> d-text .link::before',
        [$c('child', 'type', 'd-text'), $c('descendant', 'class', '.link')],
        FALSE,
        TRUE,
        'before',
      ],
    ];
  }

  /**
   * Whitespace, case and quoting are free on the way in.
   *
   * The print is the one spelling.
   */
  public function testCanonicalization(): void {
    $this->assertSame('> *', (string) RelativeSelector::parse('>*'));
    $this->assertSame('.cls > *', (string) RelativeSelector::parse('  .cls>*  '));
    $this->assertSame('> d-text .link', (string) RelativeSelector::parse(">d-text\n  .link"));
    $this->assertSame('> p + p', (string) RelativeSelector::parse('>p+p'));
    $this->assertSame('> d-text:has(a)', (string) RelativeSelector::parse('> d-text:has( a )'));
    $this->assertSame('> d-text:has(+ d-image)', (string) RelativeSelector::parse('>d-text:has(+d-image)'));
    $this->assertSame('> d-text:has(a):not(.featured)', (string) RelativeSelector::parse('> d-text:not(.featured):has(a)'));
    $this->assertSame('> *:not(d-image):not(d-video)', (string) RelativeSelector::parse('> *:not(d-video):not( d-image )'));
    $this->assertSame('&::before', (string) RelativeSelector::parse('  &::before '));
    $this->assertSame('> d-text::after', (string) RelativeSelector::parse(">d-text::after\n"));
    $this->assertSame('> d-text:has(a)::before', (string) RelativeSelector::parse('>d-text:has( a )::before'));
    // Positions: sorted among the qualifiers by printed form, the
    // `An+B` canonical as CSS serializes it, whitespace free inside the
    // parens and around B's sign.
    $this->assertSame('> *:first-child:last-child', (string) RelativeSelector::parse('> *:last-child:first-child'));
    $this->assertSame('> *:has(a):not(:last-child)', (string) RelativeSelector::parse('> *:not( :last-child ):has(a)'));
    $this->assertSame('li:nth-child(2n+1)', (string) RelativeSelector::parse('li:nth-child( 2n + 1 )'));
    $this->assertSame('> *:nth-child(n)', (string) RelativeSelector::parse('> *:nth-child(+1n+0)'));
    $this->assertSame('> *:nth-child(3)', (string) RelativeSelector::parse('> *:nth-child(0n+3)'));
    $this->assertSame('> *:nth-child(3)', (string) RelativeSelector::parse('> *:nth-child(+3)'));
    $this->assertSame('> *:nth-child(-n+3)', (string) RelativeSelector::parse('> *:nth-child(-n + 3)'));
    $this->assertSame('> *:nth-child(2n-1)', (string) RelativeSelector::parse('> *:nth-child(2n -1)'));
    $this->assertSame('> *:nth-child(2n)', (string) RelativeSelector::parse('> *:nth-child(2n+0)'));
    $this->assertSame('&:first-child:last-child', (string) RelativeSelector::parse(' &:last-child:first-child '));
    $this->assertSame('&:not(:last-child) > *', (string) RelativeSelector::parse('&:not(:last-child)>*'));
    $this->assertFalse(RelativeSelector::isCanonical('> *:last-child:first-child'));
    $this->assertFalse(RelativeSelector::isCanonical('li:nth-child(2n + 1)'));
    $this->assertFalse(RelativeSelector::isCanonical('> *:nth-child(0n+3)'));
    $this->assertTrue(RelativeSelector::isCanonical('> *:not(:last-child)'));
    $this->assertTrue(RelativeSelector::isCanonical('li:nth-child(odd)'));
    $this->assertTrue(RelativeSelector::isCanonical('&:first-child'));
    $this->assertFalse(RelativeSelector::isCanonical('>*'));
    $this->assertFalse(RelativeSelector::isCanonical('.cls  > *'));
    $this->assertFalse(RelativeSelector::isCanonical('> d-text:not(.featured):has(a)'));
    $this->assertFalse(RelativeSelector::isCanonical(' &::before'));
    $this->assertTrue(RelativeSelector::isCanonical('.cls > *'));
    $this->assertTrue(RelativeSelector::isCanonical('> d-text:has(a):not(.featured)'));
    $this->assertTrue(RelativeSelector::isCanonical('&::before'));
    $this->assertTrue(RelativeSelector::isCanonical('.quote::after'));
    // D. Case: tags, types, pseudo-class, pseudo-element and attribute
    // names in any case print lowercase; classes and attribute values
    // keep theirs; the legacy single-colon pseudo-element reads.
    $this->assertSame('li:hover > ul', (string) RelativeSelector::parse('Li:hover > UL'));
    $this->assertSame('li > ul', (string) RelativeSelector::parse('LI>UL'));
    $this->assertSame('a:hover', (string) RelativeSelector::parse('a:HOVER'));
    $this->assertSame('li > ul', (string) RelativeSelector::parse('li>ul'));
    $this->assertSame('d-text .Link', (string) RelativeSelector::parse('D-Text .Link'));
    $this->assertSame('&:hover > ul', (string) RelativeSelector::parse('&:HOVER > UL'));
    $this->assertSame('p:active:hover', (string) RelativeSelector::parse('p:HOVER:Active'));
    $this->assertSame('li:nth-child(2n+1)', (string) RelativeSelector::parse('li:NTH-CHILD(2N+1)'));
    $this->assertSame('> *:nth-child(2n)', (string) RelativeSelector::parse('> *:nth-child(2N)'));
    $this->assertSame('> *:nth-child(odd)', (string) RelativeSelector::parse('> *:nth-child(ODD)'));
    $this->assertSame('p', (string) RelativeSelector::parse('P'));
    $this->assertSame('.Card', (string) RelativeSelector::parse('.Card'));
    $this->assertSame('*[type="Checkbox"]', (string) RelativeSelector::parse('[TYPE=Checkbox]'));
    $this->assertSame('*[data-x]', (string) RelativeSelector::parse('[Data-X]'));
    $this->assertSame('*[type="checkbox" i]', (string) RelativeSelector::parse('[ type = "checkbox" I ]'));
    $this->assertSame('&::before', (string) RelativeSelector::parse('&::Before'));
    $this->assertSame('p::after', (string) RelativeSelector::parse('p::AFTER'));
    $this->assertSame('p::before', (string) RelativeSelector::parse('p:before'));
    $this->assertSame('&::after', (string) RelativeSelector::parse('&:after'));
    $this->assertSame('&:hover::before', (string) RelativeSelector::parse('&:hover:before'));
    $this->assertSame('p::first-line', (string) RelativeSelector::parse('p:first-line'));
    $this->assertSame('p::first-letter', (string) RelativeSelector::parse('P:First-Letter'));
    $this->assertSame('&::selection', (string) RelativeSelector::parse('&::Selection'));
    $this->assertSame('&::markr', (string) RelativeSelector::parse('&::markr'), 'the grammar spells any name; the tier is the validator\'s');
    $this->assertFalse(RelativeSelector::isCanonical('Li:hover > UL'));
    $this->assertFalse(RelativeSelector::isCanonical('p:before'));
    // G. Classes: `*.cls` is `.cls`; the classes of a part or universal
    // compound pool into the subject, the first by name; on a tag they
    // sort among the qualifiers.
    $this->assertSame('> .cls', (string) RelativeSelector::parse('> *.cls'));
    $this->assertSame('.a.b', (string) RelativeSelector::parse('*.a.b'));
    $this->assertSame('.a.b', (string) RelativeSelector::parse('.b.a'));
    $this->assertSame('li.a.b', (string) RelativeSelector::parse('li.b.a'));
    $this->assertSame('&.x:first-child', (string) RelativeSelector::parse('&:first-child.x'));
    $this->assertSame('*:not(.x)', (string) RelativeSelector::parse(':not(*.x)'));
    $this->assertSame('li.active:hover[aria-current="page"]', (string) RelativeSelector::parse('li:hover[aria-current="page"].active'));
    // H. Attributes: ident values quote, single quotes become double,
    // the escapes decode and re-encode, the flag follows an ident after
    // whitespace only.
    $this->assertSame('input[type="checkbox"]', (string) RelativeSelector::parse('input[type=checkbox]'));
    $this->assertSame('input[type="checkbox"]', (string) RelativeSelector::parse("input[type='checkbox']"));
    $this->assertSame('*[aria-expanded="true"] > ul', (string) RelativeSelector::parse('[aria-expanded="true"] > ul'));
    $this->assertSame('li[aria-current="page"]', (string) RelativeSelector::parse('li[aria-current=page]'));
    $this->assertSame('a[target="_blank"]', (string) RelativeSelector::parse('a[target=_blank]'));
    $this->assertSame('*[x]', (string) RelativeSelector::parse('[x]'));
    $this->assertSame('*[lang|="en"]', (string) RelativeSelector::parse('[lang|=en]'));
    $this->assertSame('a[title="say \"hi\""]', (string) RelativeSelector::parse("a[title='say \"hi\"']"));
    $this->assertSame('*[x="a\'b"]', (string) RelativeSelector::parse("[x='a\\'b']"));
    $this->assertSame('*[x="Abc"]', (string) RelativeSelector::parse('[x="\41 bc"]'));
    $this->assertSame('*[x="ab"]', (string) RelativeSelector::parse("[x=\"a\\\nb\"]"));
    $this->assertSame('*[x="a" i]', (string) RelativeSelector::parse('[x=a i]'));
    $this->assertSame('*[x="ai"]', (string) RelativeSelector::parse('[x=ai]'));
    $this->assertSame('*[x="a" i]', (string) RelativeSelector::parse('[x="a"i]'));
    $this->assertSame('*[x="a" s]', (string) RelativeSelector::parse('[x="a" S]'));
    $this->assertSame('*[x]', (string) RelativeSelector::parse('[ x ]'));
    // The implied subject: the universal in a chain, nothing in `:not()`.
    $this->assertSame('*:hover > ul', (string) RelativeSelector::parse(':hover > ul'));
    $this->assertSame('*:hover', (string) RelativeSelector::parse(':hover'));
    $this->assertSame('*:first-child', (string) RelativeSelector::parse(':first-child'));
    $this->assertSame('> *:not(:last-child)', (string) RelativeSelector::parse('> :not(:last-child)'));
    $this->assertSame('*:has(*:focus-within)', (string) RelativeSelector::parse(':has(:focus-within)'));
    $this->assertSame('*:not(:first-child)', (string) RelativeSelector::parse(':not(*:first-child)'));
    $this->assertSame('d-text *:has(a)', (string) RelativeSelector::parse('d-text :has(a)'));
    $this->assertSame('li *:hover', (string) RelativeSelector::parse('li :hover'));
    $this->assertSame('*:not([hidden])', (string) RelativeSelector::parse(':not([hidden])'));
    $this->assertSame('li:hover > ul', (string) RelativeSelector::parse('li:hover>ul'));
    // `:is()`: members sorted by printed form and distinct, `:where()`
    // read as `:is()`, the implied subject the universal in a chain and
    // nothing in a member, a single member merged into its host (its
    // subject taken when the host's is `*` or implied, a class member
    // pooled, its qualifiers sorted in), the qualifier sorted by its
    // printed form among the host's.
    $this->assertSame('*:is(h1, h2)', (string) RelativeSelector::parse(':is(h1, h2)'));
    $this->assertSame('*:is(.a, .b)', (string) RelativeSelector::parse(':is(.b, .a)'));
    $this->assertSame('*:is(h1, h2)', (string) RelativeSelector::parse(':is(h2,h1,h2)'));
    $this->assertSame('*:is(h1, h2)', (string) RelativeSelector::parse(':IS( H1 , h2 )'));
    $this->assertSame('*:is(h1, h2)', (string) RelativeSelector::parse(':where(h1, h2)'));
    $this->assertSame('.menu-item:is(:focus-within, :hover) > ul', (string) RelativeSelector::parse('.menu-item:is(:hover, :focus-within) > ul'));
    $this->assertSame('> *:is(div, p)', (string) RelativeSelector::parse('> *:is(p, div)'));
    $this->assertSame('*:is(:not(.b), a)', (string) RelativeSelector::parse(':is(a, :not(.b))'));
    $this->assertSame('> d-text:has(*:is(a, button))', (string) RelativeSelector::parse('> d-text:has(:is(a, button))'));
    $this->assertSame('a.x', (string) RelativeSelector::parse('a:where(.x)'));
    $this->assertSame('h1', (string) RelativeSelector::parse('*:is(h1)'));
    $this->assertSame('h1', (string) RelativeSelector::parse(':is(h1)'));
    $this->assertSame('> p', (string) RelativeSelector::parse('> *:is(p)'));
    $this->assertSame('.x', (string) RelativeSelector::parse(':where(.x)'));
    $this->assertSame('h1.x', (string) RelativeSelector::parse('*:is(h1):is(.x)'));
    $this->assertSame('.a.b', (string) RelativeSelector::parse('*:is(.b):is(.a)'));
    $this->assertSame('h1', (string) RelativeSelector::parse('h1:is(h1)'));
    $this->assertSame('h1.x', (string) RelativeSelector::parse('h1:is(h1.x)'));
    $this->assertSame('a:hover', (string) RelativeSelector::parse('a:is(:hover)'));
    $this->assertSame('d-text', (string) RelativeSelector::parse('d-text:is(d-text)'));
    $this->assertSame('&.x', (string) RelativeSelector::parse('&:is(.x)'));
    $this->assertSame('&.open > ul', (string) RelativeSelector::parse('&:where(.open) > ul'));
    $this->assertSame('li:hover:is(.a, .b)', (string) RelativeSelector::parse('li:is(.a, .b):hover'));
    $this->assertSame('*:is(h1, h2)', (string) RelativeSelector::parse(':is(h1, h1, h2)'));
    $this->assertTrue(RelativeSelector::isCanonical('*:is(h1, h2)'));
    $this->assertTrue(RelativeSelector::isCanonical('.menu-item:is(:focus-within, :hover) > ul'));
    $this->assertFalse(RelativeSelector::isCanonical(':is(h1, h2)'));
    $this->assertFalse(RelativeSelector::isCanonical('*:is(h2, h1)'));
    $this->assertFalse(RelativeSelector::isCanonical('*:where(h1, h2)'));
    $this->assertFalse(RelativeSelector::isCanonical('*:is(h1)'));
    $this->assertFalse(RelativeSelector::isCanonical('*:is(h1,h2)'));
    $this->assertTrue(RelativeSelector::isCanonical('li:hover > ul'));
    $this->assertTrue(RelativeSelector::isCanonical('*[aria-expanded="true"] > ul'));
    $this->assertFalse(RelativeSelector::isCanonical('[aria-expanded="true"] > ul'));
    $this->assertFalse(RelativeSelector::isCanonical('input[type=checkbox]'));
  }

  /**
   * The relations: three canonical prints and three refusals, both twins.
   */
  public function testRelations(): void {
    $this->assertSame('> p + p', (string) RelativeSelector::parse('> p + p'));
    $this->assertSame('> *:not(d-image)', (string) RelativeSelector::parse('> *:not(d-image)'));
    $this->assertSame('> d-text:has(a):not(.featured)', (string) RelativeSelector::parse('> d-text:not(.featured):has(a)'));
    $this->assertNull(RelativeSelector::parse('+ p'));
    $this->assertNull(RelativeSelector::parse('> *:not(*)'));
    $this->assertNull(RelativeSelector::parse('> d-text:has(:has(a))'));
  }

  /**
   * The `An+B` serializer: CSS's own, read leniently on case and whitespace.
   */
  public function testCanonicalNth(): void {
    $this->assertSame('odd', RelativeSelector::canonicalNth('odd'));
    $this->assertSame('even', RelativeSelector::canonicalNth(' even '));
    $this->assertSame('2n+1', RelativeSelector::canonicalNth('2n+1'));
    $this->assertSame('2n+1', RelativeSelector::canonicalNth(' 2n + 1 '));
    $this->assertSame('2n+1', RelativeSelector::canonicalNth('+2n+1'));
    $this->assertSame('n', RelativeSelector::canonicalNth('n'));
    $this->assertSame('n', RelativeSelector::canonicalNth('+n'));
    $this->assertSame('n', RelativeSelector::canonicalNth('1n+0'));
    $this->assertSame('-n', RelativeSelector::canonicalNth('-n'));
    $this->assertSame('-n+3', RelativeSelector::canonicalNth('-1n+3'));
    $this->assertSame('2n', RelativeSelector::canonicalNth('2n'));
    $this->assertSame('2n-1', RelativeSelector::canonicalNth('2n-1'));
    $this->assertSame('-2n-1', RelativeSelector::canonicalNth('-2n - 1'));
    $this->assertSame('3', RelativeSelector::canonicalNth('3'));
    $this->assertSame('3', RelativeSelector::canonicalNth('+3'));
    $this->assertSame('3', RelativeSelector::canonicalNth('0n+3'));
    $this->assertSame('7', RelativeSelector::canonicalNth('007'));
    $this->assertSame('-1', RelativeSelector::canonicalNth('-1'));
    $this->assertSame('0', RelativeSelector::canonicalNth('0'));
    $this->assertSame('0', RelativeSelector::canonicalNth('0n'));
    $this->assertSame('0', RelativeSelector::canonicalNth('-0n+0'));
    $this->assertSame('2n', RelativeSelector::canonicalNth('2N'));
    $this->assertSame('odd', RelativeSelector::canonicalNth('ODD'));
    $this->assertSame('even', RelativeSelector::canonicalNth('Even'));
    $this->assertSame('-n+3', RelativeSelector::canonicalNth('-N+3'));
    foreach (['', ' ', 'foo', '2 n', '- n', 'n+', '+', '2n+1 of .x', '2n+-1', '2n++1', '1.5n', 'n n', 'odd+1'] as $argument) {
      $this->assertNull(RelativeSelector::canonicalNth($argument), json_encode($argument) . ' is not An+B.');
    }
  }

  /**
   * Strings outside the grammar never parse.
   */
  #[DataProvider('malformed')]
  public function testMalformed(string $selector): void {
    $this->assertNull(RelativeSelector::parse($selector), json_encode($selector) . ' is not a selector.');
    $this->assertFalse(RelativeSelector::isCanonical($selector));
    $this->expectException(\InvalidArgumentException::class);
    RelativeSelector::fromString($selector);
  }

  /**
   * Strings that are not selectors.
   */
  public static function malformed(): array {
    return [
      'empty' => [''],
      'bare combinator' => ['>'],
      'universal without child' => ['*'],
      'universal by descent' => ['d-text *'],
      'retired children pseudo-part' => ['::>'],
      'doubled combinator' => ['> >'],
      'trailing combinator' => ['p >'],
      'trailing sibling combinator' => ['> p +'],
      'leading next sibling' => ['+ p'],
      'leading subsequent sibling' => ['~ p'],
      'digit-first tag' => ['2x'],
      'bare type prefix' => ['d-'],
      'double-colon inside' => ['.a::>'],
      'trailing comma' => ['p,'],
      'anchor glued behind a tag' => ['p&'],
      'nested has' => ['> d-text:has(:has(a))'],
      'has inside a has through not' => ['> p:has(a:not(:has(b)))'],
      'nested not' => ['> p:not(:not(p))'],
      'universal in not' => ['> *:not(*)'],
      'bare universal in not' => [':not(*)'],
      'combinator in not' => ['> p:not(> p)'],
      'chain in not' => ['> p:not(p + p)'],
      'empty has' => ['> d-text:has()'],
      'empty not' => ['> p:not()'],
      // I. A selector list is the digester's to fold into `:is()`: the
      // grammar refuses the comma at the top level, with every
      // functional pseudo-class it does not own.
      'selector list' => ['div, p'],
      'heading list' => ['h1, h2, h3'],
      'state list' => ['a:hover, a:focus'],
      'lang' => [':lang(en)'],
      'dir' => [':dir(rtl)'],
      'state with parens' => ['p:hover()'],
      // `:is()`: never inside `:not()` (`:not(a):not(b)` is the spelling)
      // or inside `:is()`, through `:has()` and `:not()` too; never the
      // anchor; no empty member, no `*` alone, no chain, no box; a
      // member subject against the host's; the names need their parens.
      'is inside not' => [':not(:is(a, b))'],
      'is inside not on a tag' => ['li:not(:is(a, b))'],
      'is inside is' => [':is(:is(a))'],
      'is inside is through not' => [':is(a:not(:is(b)))'],
      'is inside is through has' => [':is(a:has(:is(b, c)))'],
      'is inside not through has' => [':not(a:has(:is(b, c)))'],
      'where inside is' => [':is(:where(a))'],
      'is inside where' => [':where(:is(a))'],
      'anchor inside is' => [':is(&)'],
      'qualified anchor inside is' => [':is(&:hover, a)'],
      'empty is' => [':is()'],
      'empty where' => [':where()'],
      'trailing comma in is' => [':is(a,)'],
      'leading comma in is' => [':is(,a)'],
      'empty middle member' => [':is(a,,b)'],
      'universal alone in is' => [':is(*)'],
      'universal among members' => [':is(*, a)'],
      'member subject against the host' => ['h1:is(h2)'],
      'member type against the host' => ['h1:is(d-text)'],
      'member subject against the anchor' => ['&:is(h1)'],
      'chain in is' => [':is(a b)'],
      'combinator in is' => [':is(> a)'],
      'pseudo-element in is' => [':is(a::before)'],
      'has inside is inside has' => ['> d-text:has(:is(a:has(b)))'],
      'is without parens' => [':is'],
      'where without parens' => [':where'],
      'is without parens on a tag' => ['p:is'],
      // The pseudo-element: a bare name, terminal, glued, the anchor
      // stands only as its own subject, and the legacy single-colon
      // spelling is never a pseudo-class.
      'functional pseudo-element' => ['.quote::part(x)'],
      'functional pseudo-element on the anchor' => ['&::slotted(span)'],
      'digit-led pseudo-element' => ['&::1x'],
      'vendor-prefixed pseudo-element' => ['&::-webkit-scrollbar'],
      'other pseudo-element not terminal' => ['li::marker .x'],
      'other pseudo-element then a box' => ['li::marker::before'],
      'legacy first-letter before a combinator' => ['p:first-letter > a'],
      'bare pseudo-element' => ['::before'],
      'bare legacy pseudo-element' => [':before'],
      'anchor alone' => ['&'],
      'anchor in a chain' => ['& > p'],
      'anchor then child tag' => ['& > ul'],
      'anchor then next universal' => ['& + *'],
      'anchor after a compound' => ['> d-text &::before'],
      'pseudo-element not terminal' => ['> d-text::before .x'],
      'legacy pseudo-element not terminal' => ['p:before .x'],
      'legacy pseudo-element before a combinator' => ['p:after > *'],
      'pseudo-element before a combinator' => ['> d-text::before > *'],
      'pseudo-element inside has' => ['> d-text:has(a::before)'],
      'legacy pseudo-element inside has' => ['> d-text:has(a:after)'],
      'pseudo-element inside not' => ['> *:not(d-image::after)'],
      'legacy pseudo-element inside not' => ['> *:not(:before)'],
      'pseudo-element on the anchor inside has' => ['> d-text:has(&::before)'],
      'doubled pseudo-element' => ['.quote::before::after'],
      'legacy pseudo-element then state' => ['&:before:hover'],
      'pseudo-element behind a space' => ['> d-text ::before'],
      'anchor pseudo-element behind a space' => ['& ::before'],
      // Positions: the `An+B` whitespace free but around B's sign, the
      // `of` form refused; a functional name needs its parens; the
      // record names are no pseudo-classes.
      'position with an empty argument' => ['> *:nth-child()'],
      'position with the of form' => ['> *:nth-child(2n+1 of .x)'],
      'position with a word argument' => ['> *:nth-child(foo)'],
      'argument-less position with parens' => ['> *:first-child()'],
      'nth without parens' => ['> *:nth-child'],
      'nth with a space before n' => ['> *:nth-child(2 n)'],
      'nth with a space after the sign' => ['> *:nth-child(- n)'],
      'position inside a pseudo-element' => ['> *::before:first-child'],
      'has without parens' => [':has'],
      'not without parens' => [':not'],
      'has without parens on a tag' => ['p:has'],
      'class as a pseudo-class' => [':class'],
      'attribute as a pseudo-class' => [':attribute'],
      // Attributes: a value is an ident or a string, after an operator,
      // one flag after the value, the brackets closed.
      'attribute digit-led ident' => ['[x=1]'],
      'attribute empty value' => ['[x=]'],
      'attribute without a name' => ['[=x]'],
      'attribute unterminated string' => ['[x="unterminated'],
      'attribute two names' => ['[x y]'],
      'attribute bad flag' => ['[x="a" x]'],
      'attribute two flags' => ['[x="a"i i]'],
      'attribute both flags' => ['[x="a" i s]'],
      'attribute flag without a value' => ['[x i]'],
      'attribute empty' => ['[]'],
      'attribute unclosed' => ['[x'],
      'attribute newline in a string' => ["[x=\"a\nb\"]"],
      'attribute doubled operator' => ['[x=="a"]'],
      'attribute doubled close' => ['[x="a"]]'],
      'attribute class name' => ['[.x]'],
      'attribute dotted ident' => ['[x=a.b]'],
      // The anchor: first, qualified, at the top level; only descent
      // behind it.
      'anchor then next sibling' => ['&:first-child + p'],
      'anchor then subsequent sibling' => ['&:first-child ~ *'],
      'anchor class then next sibling' => ['&.open + p'],
      'anchor then universal by descent' => ['&:first-child *'],
      'anchor inside has' => ['> d-text:has(&:first-child)'],
      'anchor inside not' => ['> *:not(&:first-child)'],
      'anchor twice' => ['&:first-child & > p'],
      'anchor behind a lead' => ['> &:first-child'],
      'anchor after a tag' => ['p &:first-child'],
      'anchor with a nested has' => ['&:first-child:has(:has(a))'],
      'anchor with a bare colon' => ['&:'],
      'anchor box behind a space' => ['&:first-child ::before'],
    ];
  }

  /**
   * The key split: the first "::" separates root from selector.
   */
  public function testSplitKey(): void {
    $this->assertSame(['container:x', NULL], RelativeSelector::splitKey('container:x'));
    [$root, $selector] = RelativeSelector::splitKey('container:x::> *');
    $this->assertSame('container:x', $root);
    $this->assertSame('> *', (string) $selector);
    [$root, $selector] = RelativeSelector::splitKey('block(text,1)::.quote > *');
    $this->assertSame('block(text,1)', $root);
    $this->assertSame('.quote > *', (string) $selector);
    [$root, $selector] = RelativeSelector::splitKey('container:x::> d-text:has(a):not(.featured)');
    $this->assertSame('container:x', $root);
    $this->assertSame('> d-text:has(a):not(.featured)', (string) $selector);
    // A pseudo-element key still splits at its FIRST "::": the anchor or
    // a compound always precedes the pseudo's own.
    [$root, $selector] = RelativeSelector::splitKey('container:x::&::before');
    $this->assertSame('container:x', $root);
    $this->assertSame('&::before', (string) $selector);
    $this->assertSame('before', $selector->pseudo);
    [$root, $selector] = RelativeSelector::splitKey('block(text,1)::.quote::after');
    $this->assertSame('block(text,1)', $root);
    $this->assertSame('.quote::after', (string) $selector);
    // The qualified anchor and a position carry no `::` of their own:
    // the first "::" still splits, the anchor's box included.
    [$root, $selector] = RelativeSelector::splitKey('container:x::&:first-child');
    $this->assertSame('container:x', $root);
    $this->assertSame('&:first-child', (string) $selector);
    [$root, $selector] = RelativeSelector::splitKey('container:x::&:not(:last-child) > *');
    $this->assertSame('container:x', $root);
    $this->assertSame('&:not(:last-child) > *', (string) $selector);
    [$root, $selector] = RelativeSelector::splitKey('container:x::&:first-child::before');
    $this->assertSame('container:x', $root);
    $this->assertSame('&:first-child::before', (string) $selector);
    $this->assertSame('before', $selector->pseudo);
    [$root, $selector] = RelativeSelector::splitKey('block(text,1)::li:nth-child(2n+1)');
    $this->assertSame('block(text,1)', $root);
    $this->assertSame('li:nth-child(2n+1)', (string) $selector);
    $this->assertNull(RelativeSelector::splitKey('container:x::> *:last-child:first-child'));
    $this->assertNull(RelativeSelector::splitKey('container:x::li:nth-child(2n + 1)'));
    // States, classes and attributes are selector text; a `::` inside a
    // quoted value is no split; the lenient spellings are not keys.
    [$root, $selector] = RelativeSelector::splitKey('container:x::li:hover > ul');
    $this->assertSame('container:x', $root);
    $this->assertSame('li:hover > ul', (string) $selector);
    [$root, $selector] = RelativeSelector::splitKey('container:x::&.open > ul');
    $this->assertSame('&.open > ul', (string) $selector);
    [$root, $selector] = RelativeSelector::splitKey('container:x::*[aria-expanded="true"] > ul');
    $this->assertSame('*[aria-expanded="true"] > ul', (string) $selector);
    [$root, $selector] = RelativeSelector::splitKey('container:x::a[href^="http"]::after');
    $this->assertSame('a[href^="http"]::after', (string) $selector);
    $this->assertSame('after', $selector->pseudo);
    [$root, $selector] = RelativeSelector::splitKey('container:x::*[data-x="a::b"]');
    $this->assertSame('container:x', $root);
    $this->assertSame('*[data-x="a::b"]', (string) $selector);
    $this->assertNull(RelativeSelector::splitKey('container:x::Li:hover > UL'));
    $this->assertNull(RelativeSelector::splitKey('container:x::[aria-expanded="true"] > ul'));
    $this->assertNull(RelativeSelector::splitKey('container:x::input[type=checkbox]'));
    $this->assertNull(RelativeSelector::splitKey('container:x::li:hover.active'));
    // The canonical spelling only: a stored key has one shape.
    $this->assertNull(RelativeSelector::splitKey('container:x::>'));
    $this->assertNull(RelativeSelector::splitKey('container:x::.a::>'));
    $this->assertNull(RelativeSelector::splitKey('container:x::>*'));
    $this->assertNull(RelativeSelector::splitKey('container:x::'));
    $this->assertNull(RelativeSelector::splitKey('container:x::> d-text:not(.featured):has(a)'));
    $this->assertNull(RelativeSelector::splitKey('container:x::::before'));
    $this->assertNull(RelativeSelector::splitKey('container:x::&'));
    $this->assertNull(RelativeSelector::splitKey('container:x:: &::before'));
  }

  /**
   * The compound reads and the type mapping.
   */
  public function testReads(): void {
    $this->assertTrue(RelativeSelector::fromString('> *')->isChildren());
    $this->assertFalse(RelativeSelector::fromString('> * > *')->isChildren());
    $this->assertFalse(RelativeSelector::fromString('> *:not(d-image)')->isChildren());
    $this->assertTrue(RelativeSelector::fromString('.cls')->isPart());
    $this->assertFalse(RelativeSelector::fromString('.cls > *')->isPart());
    $this->assertFalse(RelativeSelector::fromString('.cls:has(a)')->isPart());
    $this->assertTrue(RelativeSelector::fromString('p')->isTag());
    $this->assertFalse(RelativeSelector::fromString('> p')->isTag());
    $this->assertFalse(RelativeSelector::fromString('p:has(a)')->isTag());
    $this->assertSame(['.s-a3'], RelativeSelector::fromString('> d-text .s-a3')->classes());
    $this->assertSame(['.s-a3', '.featured', '.link'], RelativeSelector::fromString('> d-text:has(.s-a3):not(.featured) .link')->classes());
    $this->assertSame([], RelativeSelector::fromString('> *')->classes());
    // A pseudo behind the one compound makes it a rule of its own: not
    // the part, not the tag tier, not the children.
    $this->assertFalse(RelativeSelector::fromString('.cls::before')->isPart());
    $this->assertTrue(RelativeSelector::fromString('.cls::before')->isPartFirst());
    $this->assertFalse(RelativeSelector::fromString('p::after')->isTag());
    $this->assertFalse(RelativeSelector::fromString('> *::before')->isChildren());
    $this->assertFalse(RelativeSelector::fromString('&::before')->isPart());
    $this->assertFalse(RelativeSelector::fromString('&::before')->isPartFirst());
    $this->assertFalse(RelativeSelector::fromString('&::before')->isTag());
    $this->assertFalse(RelativeSelector::fromString('&::before')->isChildren());
    $this->assertSame(0, RelativeSelector::fromString('&::before')->count());
    $this->assertSame(['.quote'], RelativeSelector::fromString('.quote::after')->classes());
    $this->assertSame('before', RelativeSelector::fromString('> d-text::before')->pseudo);
    $this->assertNull(RelativeSelector::fromString('> d-text')->pseudo);
    $this->assertSame(self::compound('child', 'type', 'd-text'), RelativeSelector::fromString('> d-text::before')->last());
    // A position qualifier makes a rule of its own; the qualified anchor
    // is the owner itself: its part is part-first, its children are not
    // the children tier, and alone it has one compound, the anchor, as
    // first and last.
    $this->assertFalse(RelativeSelector::fromString('> *:not(:last-child)')->isChildren());
    $this->assertFalse(RelativeSelector::fromString('.cls:first-child')->isPart());
    $this->assertFalse(RelativeSelector::fromString('p:nth-child(2)')->isTag());
    $this->assertTrue(RelativeSelector::fromString('.cls:first-child')->isPartFirst());
    $this->assertTrue(RelativeSelector::fromString('&:first-child .cls')->isPartFirst());
    $this->assertTrue(RelativeSelector::fromString('&:first-child > .cls::before')->isPartFirst());
    $this->assertFalse(RelativeSelector::fromString('&:first-child')->isPartFirst());
    $this->assertFalse(RelativeSelector::fromString('&:first-child p')->isPartFirst());
    $this->assertFalse(RelativeSelector::fromString('&:not(:last-child) > *')->isChildren());
    $this->assertFalse(RelativeSelector::fromString('&:first-child .cls')->isPart());
    $this->assertFalse(RelativeSelector::fromString('&:first-child p')->isTag());
    $this->assertFalse(RelativeSelector::fromString('&:first-child')->reachesChildren());
    $this->assertTrue(RelativeSelector::fromString('&:first-child > *')->reachesChildren());
    $this->assertFalse(RelativeSelector::fromString('&:has(> a)')->reachesChildren());
    $this->assertSame(1, RelativeSelector::fromString('&:first-child')->count());
    $this->assertSame(self::anchor([self::at('first-child')]), RelativeSelector::fromString('&:first-child')->first());
    $this->assertSame(self::anchor([self::at('first-child')]), RelativeSelector::fromString('&:first-child')->last());
    $this->assertSame(self::anchor([self::at('first-child')]), RelativeSelector::fromString('&:first-child::before')->last());
    $this->assertSame(self::compound('child', 'universal', '*'), RelativeSelector::fromString('&:first-child > *')->last());
    $this->assertSame([], RelativeSelector::fromString('> *:not(:last-child)')->classes());
    $this->assertSame(['.x'], RelativeSelector::fromString('&:not(.x) > *:first-child')->classes());
    $this->assertSame(['.featured', '.link'], RelativeSelector::fromString('&:first-child > d-text:not(.featured):nth-child(2n) .link')->classes());
    // A state, class or attribute makes a rule of its own; a class
    // qualifying a tag is the tag's compound, not a part first; the
    // class qualifiers count among the selector's classes in print
    // order; a qualified universal by descent reaches like a tag.
    $this->assertFalse(RelativeSelector::fromString('a:hover')->isTag());
    $this->assertFalse(RelativeSelector::fromString('a.button')->isTag());
    $this->assertFalse(RelativeSelector::fromString('a[href]')->isTag());
    $this->assertFalse(RelativeSelector::fromString('.a.b')->isPart());
    $this->assertFalse(RelativeSelector::fromString('.cls:hover')->isPart());
    $this->assertFalse(RelativeSelector::fromString('> *:hover')->isChildren());
    $this->assertFalse(RelativeSelector::fromString('> *[hidden]')->isChildren());
    $this->assertTrue(RelativeSelector::fromString('.a.b')->isPartFirst());
    $this->assertTrue(RelativeSelector::fromString('.card:hover img')->isPartFirst());
    $this->assertFalse(RelativeSelector::fromString('li.active > ul')->isPartFirst());
    $this->assertTrue(RelativeSelector::fromString('&.open .menu')->isPartFirst());
    $this->assertFalse(RelativeSelector::fromString('&.open')->isPartFirst());
    $this->assertFalse(RelativeSelector::fromString('*[x] .cls')->isPartFirst());
    $this->assertFalse(RelativeSelector::fromString('*:hover')->reachesChildren());
    $this->assertTrue(RelativeSelector::fromString('*[aria-expanded="true"] > ul')->reachesChildren());
    $this->assertTrue(RelativeSelector::fromString('li:hover > ul')->reachesChildren());
    $this->assertFalse(RelativeSelector::fromString('&:hover')->reachesChildren());
    $this->assertFalse(RelativeSelector::fromString('&.open .menu')->reachesChildren());
    $this->assertSame(['.active'], RelativeSelector::fromString('li.active > ul')->classes());
    $this->assertSame(['.a', '.b'], RelativeSelector::fromString('.a.b')->classes());
    $this->assertSame(['.open', '.menu'], RelativeSelector::fromString('&.open .menu')->classes());
    $this->assertSame(['.featured', '.c', '.a', '.b', '.link'], RelativeSelector::fromString('> d-text.featured:not(.a.b):has(li.c) .link')->classes());
    $this->assertSame([], RelativeSelector::fromString('li:hover[aria-current="page"] > ul')->classes());
    $this->assertSame(self::anchor([self::cls('open')]), RelativeSelector::fromString('&.open > ul')->first());
    $this->assertSame(self::compound('descendant', 'tag', 'li', [self::at('hover')]), RelativeSelector::fromString('li:hover > ul')->first());
    // `:is()` makes a rule of its own; the host's subject decides the
    // part-first read; a type among the members reaches as the type
    // does (the fold of `d-text, d-image` keeps `d-text`'s reach); the
    // members' classes count among the selector's in print order.
    $this->assertFalse(RelativeSelector::fromString('*:is(h1, h2)')->isTag());
    $this->assertFalse(RelativeSelector::fromString('> *:is(div, p)')->isChildren());
    $this->assertFalse(RelativeSelector::fromString('*:is(.a, .b)')->isPart());
    $this->assertFalse(RelativeSelector::fromString('*:is(.a, .b)')->isPartFirst());
    $this->assertTrue(RelativeSelector::fromString('.menu-item:is(:focus-within, :hover) > ul')->isPartFirst());
    $this->assertFalse(RelativeSelector::fromString('*:is(h1, h2)')->reachesChildren());
    $this->assertTrue(RelativeSelector::fromString('> *:is(div, p)')->reachesChildren());
    $this->assertTrue(RelativeSelector::fromString('*:is(d-image, d-text)')->reachesChildren());
    $this->assertTrue(RelativeSelector::fromString('*:is(a, d-text) .link')->reachesChildren());
    $this->assertFalse(RelativeSelector::fromString('*:not(d-image)')->reachesChildren());
    $this->assertFalse(RelativeSelector::fromString('*:is(a:has(d-text), b)')->reachesChildren());
    $this->assertSame(['.a', '.b'], RelativeSelector::fromString('*:is(.a, .b)')->classes());
    $this->assertSame(['.a', '.b', '.c'], RelativeSelector::fromString('*:is(.a, li.b):not(.c)')->classes());
    $this->assertSame(['.menu-item'], RelativeSelector::fromString('.menu-item:is(:focus-within, :hover) > ul')->classes(), 'the host part counts, the state members name no class');
    $this->assertSame(self::compound('descendant', 'universal', '*', [self::is(self::member('tag', 'h1'), self::member('tag', 'h2'))]), RelativeSelector::fromString('*:is(h1, h2) a')->first());

    $this->assertSame('block_type:text', (string) RelativeSelector::typeAddress('d-text'));
    $this->assertSame('block_type:formatted_text', (string) RelativeSelector::typeAddress('d-formatted-text'));
    $this->assertSame('kind:container', (string) RelativeSelector::typeAddress('d-container'));
    $this->assertSame('kind:block', (string) RelativeSelector::typeAddress('d-block'));
    $this->assertSame('d-formatted-text', RelativeSelector::typeName(Address::blockType('formatted_text')));
    $this->assertSame('d-container', RelativeSelector::typeName(Address::kind('container')));
    $this->assertSame('d-block', RelativeSelector::typeName(Address::kind('block')));
    $this->assertNull(RelativeSelector::typeName(Address::layout('x')));
    $this->assertNull(RelativeSelector::typeName(Address::blockPlugin('menu')));
    $this->assertNull(RelativeSelector::typeName(Address::fromString('block_type:text::p')));
    $this->expectException(\InvalidArgumentException::class);
    RelativeSelector::typeAddress('text');
  }

  /**
   * The prefix: the chain truncated after a compound, no pseudo.
   */
  public function testPrefix(): void {
    $prefix = static fn (string $selector, int $index): ?string => RelativeSelector::fromString($selector)->prefix($index)?->__toString();
    // A state qualifier's compound: the targets the stamp lands on.
    $this->assertSame('li:hover', $prefix('li:hover > ul', 0));
    $this->assertSame('li:hover > ul', $prefix('li:hover > ul', 1));
    $this->assertSame('> d-text:hover', $prefix('> d-text:hover a', 0));
    $this->assertSame('> d-text:hover a', $prefix('> d-text:hover a', 1));
    // The qualified anchor alone is the owner.
    $this->assertSame('&:hover', $prefix('&:hover > ul', 0));
    $this->assertSame('&:hover > ul', $prefix('&:hover > ul', 1));
    // The pseudo never rides a prefix, the full chain included.
    $this->assertSame('> d-text:has(a:hover)', $prefix('> d-text:has(a:hover) .link::before', 0));
    $this->assertSame('> d-text:has(a:hover) .link', $prefix('> d-text:has(a:hover) .link::before', 1));
    // A sibling step keeps its lead.
    $this->assertSame('> p', $prefix('> p + p:focus-within', 0));
    $this->assertSame('> p + p:focus-within', $prefix('> p + p:focus-within', 1));
    // Out of range, and the owner's own pseudo-element (no compounds).
    $this->assertNull($prefix('li:hover > ul', 2));
    $this->assertNull($prefix('li:hover > ul', -1));
    $this->assertNull($prefix('&::before', 0));
  }

  /**
   * The under() call continues a rule by descent and lets the grammar refuse.
   */
  public function testUnder(): void {
    $under = static fn (string $rule, string $suffix): ?string => RelativeSelector::under(RelativeSelector::fromString($rule), RelativeSelector::fromString($suffix))?->__toString();
    $this->assertSame('> d-text .link', $under('> d-text', '.link'));
    $this->assertSame('> d-text > *', $under('> d-text', '> *'));
    $this->assertSame('> d-text p', $under('> d-text', 'p'));
    $this->assertSame('a p', $under('a', 'p'));
    $this->assertSame('> d-text:has(a) .link ~ .link', $under('> d-text:has(a)', '.link ~ .link'));
    // A class after a tag or the children continues by descent like any
    // compound (a markup class is selector text).
    $this->assertSame('a .cls', $under('a', '.cls'));
    $this->assertSame('> * .cls', $under('> *', '.cls'));
    // A pseudo binds to the rule's landing: the anchor is the rule's own
    // targets, a compound's pseudo continues by descent, and a rule
    // already ending in a pseudo admits nothing after it.
    $this->assertSame('> d-text::before', $under('> d-text', '&::before'));
    $this->assertSame('.cls::after', $under('.cls', '&::after'));
    $this->assertSame('> *::before', $under('> *', '&::before'));
    $this->assertSame('> d-text:has(a)::after', $under('> d-text:has(a)', '&::after'));
    $this->assertSame('> d-text .link::before', $under('> d-text', '.link::before'));
    $this->assertSame('> d-text p::after', $under('> d-text', 'p::after'));
    $this->assertSame('a .cls::before', $under('a', '.cls::before'));
    $this->assertNull($under('> d-text::before', '&::after'));
    $this->assertNull($under('> d-text::before', '.link'));
    $this->assertNull($under('&::before', 'p'));
    // The qualified anchor binds to the rule's landing: its qualifiers
    // sort in with the last compound's own, the chain behind it
    // continues by descent, and a position continues like any suffix.
    $this->assertSame('> d-text:first-child', $under('> d-text', '&:first-child'));
    $this->assertSame('> *:nth-child(2n)', $under('> *', '&:nth-child(2n)'));
    $this->assertSame('> d-text:has(a):not(:last-child) > *', $under('> d-text:has(a)', '&:not(:last-child) > *'));
    $this->assertSame('> d-text:has(a):not(.x)', $under('> d-text:not(.x)', '&:has(a)'));
    $this->assertSame('> d-text:first-child .link::before', $under('> d-text', '&:first-child .link::before'));
    $this->assertSame('> d-text:first-child::after', $under('> d-text', '&:first-child::after'));
    $this->assertSame('> d-text > *:not(:last-child)', $under('> d-text', '> *:not(:last-child)'));
    $this->assertSame('&:first-child .cls', $under('&:first-child', '.cls'));
    $this->assertSame('&:first-child > *', $under('&:first-child', '> *'));
    $this->assertSame('&:first-child:last-child', $under('&:first-child', '&:last-child'));
    $this->assertSame('&:first-child::before', $under('&:first-child', '&::before'));
    $this->assertSame('&:first-child > d-text .link', $under('&:first-child > d-text', '.link'));
    $this->assertNull($under('> d-text::before', '&:first-child'));
    $this->assertSame('a:first-child .cls', $under('a', '&:first-child .cls'));
    $this->assertNull($under('&:first-child::before', '.cls'));
    // A state, a class and an attribute on the anchor bind the same way,
    // onto the rule's last compound, in with its own qualifiers; a class
    // onto a part pools into the part.
    $this->assertSame('li:hover > ul', $under('li', '&:hover > ul'));
    $this->assertSame('li.open > ul', $under('li', '&.open > ul'));
    $this->assertSame('> d-text[aria-expanded="true"] a', $under('> d-text', '&[aria-expanded="true"] a'));
    $this->assertSame('> d-text.featured:has(a):hover', $under('> d-text:has(a)', '&.featured:hover'));
    $this->assertSame('.cls.x', $under('.cls', '&.x'));
    $this->assertSame('.a.x', $under('.x', '&.a'));
    $this->assertSame('> .cls', $under('> *', '&.cls'));
    $this->assertSame('li:hover > ul', $under('li:hover', '> ul'));
    $this->assertSame('&.open .menu', $under('&.open', '.menu'));
    // An `:is()` on the anchor binds like any qualifier; a single member
    // merges before the rebase (`&:is(.a)` is `&.a`, and `&:is(h1)` is
    // no suffix at all: a member subject against the anchor).
    $this->assertSame('> *:is(.a, .b)', $under('> *', '&:is(.a, .b)'));
    $this->assertSame('> d-text:is(:focus-within, :hover) a', $under('> d-text', '&:is(:focus-within, :hover) a'));
    $this->assertSame('> .a', $under('> *', '&:is(.a)'));
    $this->assertSame('*:is(h1, h2) a', $under('*:is(h1, h2)', 'a'));
  }

  /**
   * The continues() call reads under() back: a rule's continuations by descent.
   */
  public function testContinues(): void {
    $continues = static fn (string $rule, string $candidate): bool => RelativeSelector::continues(RelativeSelector::fromString($rule), RelativeSelector::fromString($candidate));
    $this->assertTrue($continues('> d-text', '> d-text .link'));
    $this->assertTrue($continues('> d-text', '> d-text > *'));
    $this->assertTrue($continues('> d-text', '> d-text p'));
    $this->assertTrue($continues('> d-text', '> d-text .link::before'));
    $this->assertTrue($continues('> d-text:has(a)', '> d-text:has(a) .link ~ .link'));
    // The rule's own box continues it (the anchor binds to its landing).
    $this->assertTrue($continues('> d-text', '> d-text::before'));
    $this->assertTrue($continues('.cls', '.cls::after'));
    // The rule itself, a longer name sharing its prefix, a sibling rule
    // and anything a pseudo-terminated rule would host do not.
    $this->assertFalse($continues('> d-text', '> d-text'));
    $this->assertFalse($continues('> d-text', '> d-textual'));
    $this->assertFalse($continues('> d-text', '> d-container .link'));
    $this->assertFalse($continues('> d-text', '.link'));
    $this->assertFalse($continues('&::before', 'p'));
    $this->assertFalse($continues('> d-text::before', '> d-text .link'));
    // A qualified anchor rule has a family by descent and its own box;
    // a qualifier added to the rule's last compound (a position, a
    // `:has()`, a class, a state) is a refinement that rebases under
    // the rule, not a continuation of it.
    $this->assertTrue($continues('&:first-child', '&:first-child .cls'));
    $this->assertTrue($continues('&:first-child', '&:first-child > *'));
    $this->assertTrue($continues('&:first-child', '&:first-child::before'));
    $this->assertTrue($continues('&:first-child > d-text', '&:first-child > d-text .link'));
    $this->assertTrue($continues('> d-text', '> d-text > *:not(:last-child)'));
    $this->assertTrue($continues('li:hover', 'li:hover > ul'));
    $this->assertTrue($continues('&.open', '&.open .menu'));
    $this->assertTrue($continues('li', 'li .active'));
    $this->assertFalse($continues('&:first-child', '&:first-child:last-child'));
    $this->assertFalse($continues('&:first-child', '&:last-child .cls'));
    $this->assertFalse($continues('> d-text', '> d-text:first-child'));
    $this->assertFalse($continues('> d-text', '> d-text:has(a)'));
    $this->assertFalse($continues('> d-text', '> d-text:first-child .link'));
    $this->assertFalse($continues('&:first-child::before', '&:first-child .cls'));
    $this->assertFalse($continues('li', 'li.active'));
    $this->assertFalse($continues('li', 'li:hover > ul'));
    $this->assertFalse($continues('.cls', '.cls.x'));
    $this->assertFalse($continues('a', 'a[href]'));
  }

  /**
   * The PHP grammar and its client twin answer the same battery.
   */
  public function testClientTwinEquivalence(): void {
    $selectors = [
      '.cls', 'p', '> *', '.cls > *', 'p > *', '> d-text', 'd-text', '> d-text .link', '> .cls', '.cls p',
      'd-container > * > *', '>*', '  .cls>*  ', ">d-text\n  .link", '', '>', '*', '::>', '.a .b', 'p .a',
      '> * .a', '> >', 'p >', '.a.b', 'p:hover', 'div, p', '[x]', 'P', '2x', 'd-', 'a + b', '> d-text .s-a3',
      '> p + p', 'p ~ p', '> p + *', '> d-text:has(a)', '> d-text:has(+ d-image)', '> d-container:has(> d-image) > *',
      '> d-text .link ~ .link', '> *:not(d-image)', '> *:not(d-image):not(d-video)', '> d-text:not(:has(a))',
      '> d-text:has(a):has(img)', '> d-text:has(a):not(.featured)', '>p+p', '> d-text:has( a )',
      '> d-text:not(.featured):has(a)', '>d-text:has(+d-image)', '.cls:not(.x)', 'p:has(> a)',
      '> d-text:has(.s-a3):not(.featured) .link', '+ p', '~ p', '> d-text:has(:has(a))', '> p:has(a:not(:has(b)))',
      '> p:not(:not(p))', '> *:not(*)', '> p:not(> p)', '> p:not(p + p)', 'p + .cls', 'd-text :has(a)', '> p +',
      '> d-text:has()', '> p:not()', '.a::>',
      // The pseudo-element: the accepted shapes, the lenient spellings
      // and every refusal, both twins.
      '&::before', '&::after', '.quote::after', 'p::before', '> d-text::before', '> *::before', '> p + p::after',
      '> d-text:has(a)::before', '> d-text .link::before', '  &::before ', ">d-text::after\n", '>d-text:has( a )::before',
      'li::marker', 'input[type="text"]::placeholder', '&::selection', '> d-text::first-letter', 'p::first-line', '&::backdrop',
      'li:hover::marker', 'p:marker', 'p:first-line', 'P:First-Letter', '&::Selection', '&::markr', '.quote::part(x)', '&::slotted(span)',
      '&::1x', '&::-webkit-scrollbar', 'li::marker .x', 'li::marker::before', 'p:first-letter > a',
      '::marker', '.quote::first-letter', '::before', '&', '& > p', '> d-text &::before', '> d-text::before .x',
      '> d-text::before > *', '> d-text:has(a::before)', '> *:not(d-image::after)', '> d-text:has(&::before)',
      '.quote::before::after', '> d-text ::before', '& ::before', '&::Before', 'p:before', 'p::AFTER', '&:after',
      '&:hover:before', ':before', 'p:before .x', 'p:after > *', '> *:not(:before)', '> d-text:has(a:after)',
      '&:before:hover', 'p::before::after',
      // Positions and the qualified anchor: the accepted shapes, the
      // lenient spellings and every refusal, both twins.
      '> *:not(:last-child)', '> *:first-child', '> *:first-child:last-child', 'li:nth-child(2n+1)', 'li:nth-child(odd)',
      '> d-text:nth-of-type(2)', '> d-text:nth-last-child(-n+3)', '> d-text:nth-last-of-type(even)', '.cls:only-child',
      '> d-text:empty', '> d-text:first-of-type:last-of-type:only-of-type', '> d-text:first-child:has(a):not(.featured):nth-child(n)',
      '> d-text:has(a:first-child)', '> d-text:not(p:first-child)', '> *:not(:last-child)::after',
      '&:first-child', '&:not(:last-child) > *', '&:nth-child(2n) .cls', '&:first-child > .cls', '&:last-child p',
      '&:only-child > d-text .link', '&:first-child:last-child', '&:has(a) p', '&:not(.x) > *', '&:first-child::before',
      '&:first-child > *:not(:last-child)::after', '&:first-child > d-text:not(.featured):nth-child(2n) .link',
      '> *:last-child:first-child', '> *:not( :last-child ):has(a)', 'li:nth-child( 2n + 1 )', '> *:nth-child(+1n+0)',
      '> *:nth-child(0n+3)', '> *:nth-child(+3)', '> *:nth-child(-n + 3)', '> *:nth-child(2n -1)', '> *:nth-child(2n+0)',
      '> *:nth-child(0)', '> *:nth-child(-2n)', ' &:last-child:first-child ', '&:not(:last-child)>*',
      '> *:hover', 'p:first-child p:hover', ':first-child', '> *:nth-child()', '> *:nth-child(2n+1 of .x)',
      '> *:nth-child(foo)', '> *:first-child()', '> *:nth-child', '> *:nth-child(2 n)', '> *:nth-child(- n)',
      '> *:nth-child(2N)', '> *:nth-child(ODD)', '> *:first-childx', '> *:first-child2', '> *::before:first-child',
      '&:first-child + p', '&:first-child ~ *', '&:first-child.x', '&:first-child *', '> d-text:has(&:first-child)',
      '> *:not(&:first-child)', '&:first-child & > p', '> &:first-child', 'p &:first-child', '&:first-child:has(:has(a))',
      '&:', '&:first-child ::before',
      // A to I: states on any compound, classes, attributes, leniency,
      // lists; the accepted shapes, the lenient spellings and every
      // refusal, both twins.
      'li:hover > ul', 'li:first-child > a', 'a:hover span', '.card:hover img', '> ul li:hover > ul', '&:hover > ul',
      ':hover > ul', '*:hover > ul', '> d-text:hover a', 'a:hover', '> d-text:hover', '&:hover', ':hover', '*:hover',
      'a:visited', 'input:checked', 'li:first-child:hover', 'li:hover:first-child', 'p:active:hover', 'p:HOVER:Active',
      '&:hover::before', '& > ul', '& + *', 'Li:hover > UL', 'LI>UL', 'a:HOVER', 'li>ul', 'D-Text .Link', '&:HOVER > UL',
      'li:NTH-CHILD(2N+1)', '.Card', '[TYPE=Checkbox]', '[Data-X]', '[ type = "checkbox" I ]',
      'li:not(:hover)', 'a:not(:focus-visible)', '> d-text:has(a:hover)', 'li:has(> ul:hover)', ':has(:focus-within)',
      '*:has(*:focus-within)', 'li.active > ul', 'li.open', '&.open > ul', '&.open .menu', '&.open', 'a.button',
      '.is-open > ul', '> d-text.featured', 'li.a.b', 'li.b.a', '&.a.b', '.b.a', '> *.cls', '*.a.b', 'li.active:hover > ul',
      'p:not(.a.b)', ':not(*.x)', 'li:hover[aria-current="page"].active', 'li.active:hover[aria-current="page"]',
      'a[href^="http"]::after', 'a[href^="http"]', 'input[type=checkbox]', "input[type='checkbox']", 'input[type="checkbox"]',
      '[aria-expanded="true"] > ul', '*[aria-expanded="true"] > ul', 'li[aria-current=page]', 'li[aria-current="page"]',
      'a[target=_blank]', '*[x]', '> *[hidden]', '&[aria-expanded="true"] > ul', '[lang|=en]', '[class~=x]',
      '[data-x$="y" i]', '[data-x*="y" s]', 'a[href^="http"][target="_blank"]', ':not([hidden])', '*[x=""]',
      "a[title='say \"hi\"']", '*[x="say \"hi\""]', "[x='a\\'b']", '*[x="a\\\\b"]', '[x="\41 bc"]', "[x=\"a\\\nb\"]",
      '*[x="tab\9 "]', '*[data-x="a::b"]', '[data-x=")"]:has(a)', '> d-text:has(a[data-x=")"])', '[x=a i]', '[x=ai]',
      '[x="a"i]', '[x="a" S]', '[ x ]', '[x="\\0 "]', "[x=\"a\0b\"]", '[x="\\110000 "]', '[x="\\d800 "]', '[x="\\e9"]',
      '[x="caf\\e9 "]', '[x="\\"]',
      'h1, h2, h3', 'a:hover, a:focus', ':is(h1, h2)', ':where(a)', ':lang(en)', ':dir(rtl)', 'p:hover()',
      '[x=1]', '[x=]', '[=x]', '[x="unterminated', '[x y]', '[x="a" x]', '[x="a"i i]', '[x="a" i s]', '[x i]', '[]', '[x',
      "[x=\"a\nb\"]", '[x=="a"]', '[x="a"]]', '[.x]', '[x=a.b]', ':class', ':attribute', ':has', ':not', 'p:has',
      'd-text *', ':not(*)', '&.open + p', 'a:first-child()', '*:first-child', '> :not(:last-child)', ':not(*:first-child)',
      'li :hover', 'li:hover>ul', 'p,', 'p&', '.cls:hover', '*[x] .cls',
      // `:is()`: the accepted shapes, the lenient spellings, the merges
      // and every refusal, both twins.
      '*:is(h1, h2)', ':is(h1, h2)', '.menu-item:is(:hover, :focus-within) > ul', '.menu-item:is(:focus-within, :hover) > ul',
      ':is(.b, .a)', '*:is(.a, .b)', 'a:where(.x)', '*:is(h1)', ':is(h1)', '> *:is(p, div)', '> *:is(div, p)', ':is(a, :not(.b))',
      '*:is(:not(.b), a)', '> d-text:has(:is(a, button))', '> d-text:has(*:is(a, button))', '*:is(d-image, d-text)',
      '&:is(.a, .b) > ul', 'li:hover:is(.a, .b):not(.c)', 'li:is(.a, .b):hover', '> d-text:is(:first-child, :last-child) a',
      '*:is(a:has(img), button)', 'a:is(.x, [href])', '*:is(h1, h2)::before', ':is(h2,h1,h2)', ':IS( H1 , h2 )', ':where(h1, h2)',
      '> *:is(p)', ':where(.x)', '*:is(h1):is(.x)', '*:is(.b):is(.a)', 'h1:is(h1)', 'h1:is(h1.x)', 'a:is(:hover)', 'd-text:is(d-text)',
      '&:is(.x)', '&:where(.open) > ul', '*:is(h2, h1)', '*:where(h1, h2)', '*:is(h1,h2)', '*:is(.a, li.b):not(.c)', '*:is(a, d-text) .link',
      '*:is(a:has(d-text), b)', '*:is(h1, h2) a', 'p:is(a, b)',
      ':not(:is(a, b))', 'li:not(:is(a, b))', ':is(:is(a))', ':is(a:not(:is(b)))', ':is(a:has(:is(b, c)))', ':not(a:has(:is(b, c)))',
      ':is(:where(a))', ':where(:is(a))', ':is(&)', ':is(&:hover, a)', ':is()', ':where()', ':is(a,)', ':is(,a)', ':is(a,,b)', ':is(*)',
      ':is(*, a)', 'h1:is(h2)', 'h1:is(d-text)', '&:is(h1)', ':is(a b)', ':is(> a)', ':is(a::before)', '> d-text:has(:is(a:has(b)))',
      ':is', ':where', 'p:is',
    ];
    $keys = [
      'container:x', 'container:x::> *', 'block(text,1)::.quote > *', 'container:x::>', 'container:x::.a::>',
      'container:x::>*', 'container:x::', '', '::.cls', '::> d-text .link', 'container:x::> p + p',
      'container:x::> d-text:has(a):not(.featured)', 'container:x::> d-text:not(.featured):has(a)', 'container:x::+ p',
      'container:x::&::before', 'block(text,1)::.quote::after', '::&::after', 'container:x::> d-text::before',
      'container:x::::before', 'container:x::&', 'container:x:: &::before', 'container:x::.quote::before::after',
      'container:x::&:first-child', 'container:x::&:not(:last-child) > *', 'container:x::&:first-child::before',
      'block(text,1)::li:nth-child(2n+1)', 'container:x::> *:last-child:first-child', 'container:x::li:nth-child(2n + 1)',
      '::&:first-child', 'container:x::&:',
      'container:x::li:hover > ul', 'container:x::&.open > ul', 'container:x::*[aria-expanded="true"] > ul',
      'container:x::a[href^="http"]::after', 'container:x::*[data-x="a::b"]', 'container:x::Li:hover > UL',
      'container:x::[aria-expanded="true"] > ul', 'container:x::input[type=checkbox]', 'container:x::li:hover.active',
      'container:x::li.active:hover', 'container:x::p:before',
      'container:x::*:is(h1, h2)', 'container:x::*:is(h2, h1)', 'container:x::.menu-item:is(:focus-within, :hover) > ul',
      'container:x::*:where(h1, h2)', 'container:x::*:is(h1)', 'container:x::h1, h2',
    ];
    $unders = [
      ['> d-text', '.link'], ['> d-text', '> *'], ['> d-text', 'p'], ['a', 'p'], ['> d-text:has(a)', '.link ~ .link'],
      ['a', '.cls'], ['> *', '.cls'], ['.cls', '> d-text .link'], ['> d-container', '.cls > *'],
      ['> d-text', '&::before'], ['.cls', '&::after'], ['> *', '&::before'], ['> d-text:has(a)', '&::after'],
      ['> d-text', '.link::before'], ['> d-text', 'p::after'], ['a', '.cls::before'],
      ['> d-text::before', '&::after'], ['> d-text::before', '.link'], ['&::before', 'p'],
      ['> d-text', '&:first-child'], ['> *', '&:nth-child(2n)'], ['> d-text:has(a)', '&:not(:last-child) > *'],
      ['> d-text:not(.x)', '&:has(a)'], ['> d-text', '&:first-child .link::before'],
      ['> d-text', '&:first-child::after'],
      ['> d-text', '> *:not(:last-child)'], ['&:first-child', '.cls'], ['&:first-child', '> *'],
      ['&:first-child', '&:last-child'],
      ['&:first-child', '&::before'], ['&:first-child > d-text', '.link'], ['> d-text::before', '&:first-child'],
      ['a', '&:first-child .cls'], ['&:first-child::before', '.cls'],
      ['li', '&:hover > ul'], ['li', '&.open > ul'], ['> d-text', '&[aria-expanded="true"] a'],
      ['> d-text:has(a)', '&.featured:hover'],
      ['.cls', '&.x'], ['.x', '&.a'], ['> *', '&.cls'], ['li:hover', '> ul'], ['&.open', '.menu'],
      ['a:hover', '&::before'],
      ['*[x]', '&.cls'], ['li.active', '&:hover > ul'],
      ['> *', '&:is(.a, .b)'], ['> d-text', '&:is(:focus-within, :hover) a'], ['> *', '&:is(.a)'], ['*:is(h1, h2)', 'a'],
    ];
    $nths = [
      'odd', ' even ', '2n+1', ' 2n + 1 ', '+2n+1', 'n', '+n', '1n+0', '-n', '-1n+3', '2n', '2n-1', '-2n - 1', '3', '+3',
      '0n+3', '007', '-1', '0', '0n', '-0n+0', '', ' ', 'foo', '2 n', '- n', '2N', 'ODD', 'Even', '-N+3', 'n+', '+',
      '2n+1 of .x', '2n+-1', '2n++1', '1.5n', 'n n', 'odd+1',
    ];
    $prefixes = [
      ['li:hover > ul', 0], ['li:hover > ul', 1], ['li:hover > ul', 2], ['li:hover > ul', -1],
      ['> d-text:hover a', 0], ['> d-text:hover a', 1], ['&:hover > ul', 0], ['&:hover > ul', 1],
      ['> d-text:has(a:hover) .link::before', 0], ['> d-text:has(a:hover) .link::before', 1],
      ['&::before', 0], ['> p + p:focus-within', 1], ['> p + p:focus-within', 0], ['Li:HOVER > UL', 0],
      ['.menu-item:is(:focus-within, :hover) > ul', 0], ['.menu-item:is(:focus-within, :hover) > ul', 1],
    ];
    $expected = [
      'selectors' => array_map(static function (string $text): ?array {
        $parsed = RelativeSelector::parse($text);
        return $parsed === NULL ? NULL : [
          'canonical' => (string) $parsed,
          'compounds' => $parsed->compounds,
          'pseudo' => $parsed->pseudo,
          'partFirst' => $parsed->isPartFirst(),
          'reachesChildren' => $parsed->reachesChildren(),
          'classes' => $parsed->classes(),
        ];
      }, $selectors),
      'keys' => array_map(static function (string $key): ?array {
        $split = RelativeSelector::splitKey($key);
        return $split === NULL ? NULL : ['root' => $split[0], 'selector' => $split[1]?->__toString()];
      }, $keys),
      'unders' => array_map(
        static fn (array $case): ?string => RelativeSelector::under(RelativeSelector::fromString($case[0]), RelativeSelector::fromString($case[1]))?->__toString(),
        $unders,
      ),
      'nths' => array_map(RelativeSelector::canonicalNth(...), $nths),
      'prefixes' => array_map(
        static fn (array $case): ?string => RelativeSelector::fromString($case[0])->prefix($case[1])?->__toString(),
        $prefixes,
      ),
    ];
    $stdout = $this->runNodeScript('tests/js/relative-selector-cases.mjs', json_encode([
      'selectors' => $selectors,
      'keys' => $keys,
      'unders' => $unders,
      'nths' => $nths,
      'prefixes' => $prefixes,
    ]));
    $this->assertSame($expected, json_decode($stdout, TRUE), 'The client twin answers the battery exactly as the grammar does.');
  }

}
