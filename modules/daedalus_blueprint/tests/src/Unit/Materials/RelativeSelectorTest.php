<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\Materials;

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
 * behind the four combinators, each compound narrowed by `:has()` and
 * `:not()` qualifiers in CSS's own recursive shape. The client twin
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
    return ['name' => 'not', 'argument' => ['combinator' => NULL, 'kind' => $kind, 'name' => $name, 'qualifiers' => $qualifiers]];
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
    return [
      'part' => ['.cls', [$c('descendant', 'class', '.cls')], TRUE, FALSE],
      'tag' => ['p', [$c('descendant', 'tag', 'p')], FALSE, FALSE],
      'children' => ['> *', [$c('child', 'universal', '*')], FALSE, TRUE],
      'part children' => ['.cls > *', [$c('descendant', 'class', '.cls'), $c('child', 'universal', '*')], TRUE, TRUE],
      'tag children' => ['p > *', [$c('descendant', 'tag', 'p'), $c('child', 'universal', '*')], FALSE, TRUE],
      'child type' => ['> pb-text', [$c('child', 'type', 'pb-text')], FALSE, TRUE],
      'descendant type' => ['pb-text', [$c('descendant', 'type', 'pb-text')], FALSE, TRUE],
      'type part' => ['> pb-text .link', [$c('child', 'type', 'pb-text'), $c('descendant', 'class', '.link')], FALSE, TRUE],
      'child part' => ['> .cls', [$c('child', 'class', '.cls')], TRUE, TRUE],
      'part tag' => ['.cls p', [$c('descendant', 'class', '.cls'), $c('descendant', 'tag', 'p')], TRUE, FALSE],
      'grandchildren' => ['pb-container > * > *', [$c('descendant', 'type', 'pb-container'), $c('child', 'universal', '*'), $c('child', 'universal', '*')], FALSE, TRUE],
      'type tag' => ['> pb-formatted-text p', [$c('child', 'type', 'pb-formatted-text'), $c('descendant', 'tag', 'p')], FALSE, TRUE],
      'next sibling' => ['> p + p', [$c('child', 'tag', 'p'), $c('next', 'tag', 'p')], FALSE, TRUE],
      'subsequent sibling' => ['p ~ p', [$c('descendant', 'tag', 'p'), $c('subsequent', 'tag', 'p')], FALSE, TRUE],
      'next universal' => ['> p + *', [$c('child', 'tag', 'p'), $c('next', 'universal', '*')], FALSE, TRUE],
      'part sibling' => ['> pb-text .link ~ .link', [$c('child', 'type', 'pb-text'), $c('descendant', 'class', '.link'), $c('subsequent', 'class', '.link')], FALSE, TRUE],
      'has' => ['> pb-text:has(a)', [$c('child', 'type', 'pb-text', [$has([$c('descendant', 'tag', 'a')])])], FALSE, TRUE],
      'has next' => ['> pb-text:has(+ pb-image)', [$c('child', 'type', 'pb-text', [$has([$c('next', 'type', 'pb-image')])])], FALSE, TRUE],
      'has child then children' => ['> pb-container:has(> pb-image) > *', [$c('child', 'type', 'pb-container', [$has([$c('child', 'type', 'pb-image')])]), $c('child', 'universal', '*')], FALSE, TRUE],
      'two has' => ['> pb-text:has(a):has(img)', [$c('child', 'type', 'pb-text', [$has([$c('descendant', 'tag', 'a')]), $has([$c('descendant', 'tag', 'img')])])], FALSE, TRUE],
      'not' => ['> *:not(pb-image)', [$c('child', 'universal', '*', [$not('type', 'pb-image')])], FALSE, TRUE],
      'two nots' => ['> *:not(pb-image):not(pb-video)', [$c('child', 'universal', '*', [$not('type', 'pb-image'), $not('type', 'pb-video')])], FALSE, TRUE],
      'not has' => ['> pb-text:not(:has(a))', [$c('child', 'type', 'pb-text', [$not(NULL, NULL, [$has([$c('descendant', 'tag', 'a')])])])], FALSE, TRUE],
      'has then not' => ['> pb-text:has(a):not(.featured)', [$c('child', 'type', 'pb-text', [$has([$c('descendant', 'tag', 'a')]), $not('class', '.featured')])], FALSE, TRUE],
      'qualified part first' => ['.cls:not(.x)', [$c('descendant', 'class', '.cls', [$not('class', '.x')])], TRUE, FALSE],
      'qualifier argument reaches nothing' => ['p:has(> a)', [$c('descendant', 'tag', 'p', [$has([$c('child', 'tag', 'a')])])], FALSE, FALSE],
      // The terminal pseudo-element rides the selector, never a compound;
      // the owner's own has no compounds at all.
      'own before' => ['&::before', [], FALSE, FALSE, 'before'],
      'own after' => ['&::after', [], FALSE, FALSE, 'after'],
      'part after' => ['.quote::after', [$c('descendant', 'class', '.quote')], TRUE, FALSE, 'after'],
      'tag before' => ['p::before', [$c('descendant', 'tag', 'p')], FALSE, FALSE, 'before'],
      'child type before' => ['> pb-text::before', [$c('child', 'type', 'pb-text')], FALSE, TRUE, 'before'],
      'children before' => ['> *::before', [$c('child', 'universal', '*')], FALSE, TRUE, 'before'],
      'sibling after' => ['> p + p::after', [$c('child', 'tag', 'p'), $c('next', 'tag', 'p')], FALSE, TRUE, 'after'],
      'qualified before' => ['> pb-text:has(a)::before', [$c('child', 'type', 'pb-text', [$has([$c('descendant', 'tag', 'a')])])], FALSE, TRUE, 'before'],
      'type part before' => ['> pb-text .link::before', [$c('child', 'type', 'pb-text'), $c('descendant', 'class', '.link')], FALSE, TRUE, 'before'],
    ];
  }

  /**
   * Whitespace is free on the way in; the print is the one spelling.
   */
  public function testCanonicalization(): void {
    $this->assertSame('> *', (string) RelativeSelector::parse('>*'));
    $this->assertSame('.cls > *', (string) RelativeSelector::parse('  .cls>*  '));
    $this->assertSame('> pb-text .link', (string) RelativeSelector::parse(">pb-text\n  .link"));
    $this->assertSame('> p + p', (string) RelativeSelector::parse('>p+p'));
    $this->assertSame('> pb-text:has(a)', (string) RelativeSelector::parse('> pb-text:has( a )'));
    $this->assertSame('> pb-text:has(+ pb-image)', (string) RelativeSelector::parse('>pb-text:has(+pb-image)'));
    $this->assertSame('> pb-text:has(a):not(.featured)', (string) RelativeSelector::parse('> pb-text:not(.featured):has(a)'));
    $this->assertSame('> *:not(pb-image):not(pb-video)', (string) RelativeSelector::parse('> *:not(pb-video):not( pb-image )'));
    $this->assertSame('&::before', (string) RelativeSelector::parse('  &::before '));
    $this->assertSame('> pb-text::after', (string) RelativeSelector::parse(">pb-text::after\n"));
    $this->assertSame('> pb-text:has(a)::before', (string) RelativeSelector::parse('>pb-text:has( a )::before'));
    $this->assertFalse(RelativeSelector::isCanonical('>*'));
    $this->assertFalse(RelativeSelector::isCanonical('.cls  > *'));
    $this->assertFalse(RelativeSelector::isCanonical('> pb-text:not(.featured):has(a)'));
    $this->assertFalse(RelativeSelector::isCanonical(' &::before'));
    $this->assertTrue(RelativeSelector::isCanonical('.cls > *'));
    $this->assertTrue(RelativeSelector::isCanonical('> pb-text:has(a):not(.featured)'));
    $this->assertTrue(RelativeSelector::isCanonical('&::before'));
    $this->assertTrue(RelativeSelector::isCanonical('.quote::after'));
  }

  /**
   * The relations: three canonical prints and three refusals, both twins.
   */
  public function testRelations(): void {
    $this->assertSame('> p + p', (string) RelativeSelector::parse('> p + p'));
    $this->assertSame('> *:not(pb-image)', (string) RelativeSelector::parse('> *:not(pb-image)'));
    $this->assertSame('> pb-text:has(a):not(.featured)', (string) RelativeSelector::parse('> pb-text:not(.featured):has(a)'));
    $this->assertNull(RelativeSelector::parse('+ p'));
    $this->assertNull(RelativeSelector::parse('> *:not(*)'));
    $this->assertNull(RelativeSelector::parse('> pb-text:has(:has(a))'));
  }

  /**
   * Strings outside the grammar never parse: the retired spellings included.
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
      'retired children pseudo-part' => ['::>'],
      'class after class' => ['.a .b'],
      'class after tag' => ['p .a'],
      'class after children' => ['> * .a'],
      'class after tag by sibling' => ['p + .cls'],
      'doubled combinator' => ['> >'],
      'trailing combinator' => ['p >'],
      'trailing sibling combinator' => ['> p +'],
      'leading next sibling' => ['+ p'],
      'leading subsequent sibling' => ['~ p'],
      'compound classes' => ['.a.b'],
      'pseudo-class' => ['p:hover'],
      'selector list' => ['div, p'],
      'attribute' => ['[x]'],
      'uppercase tag' => ['P'],
      'digit-first tag' => ['2x'],
      'bare type prefix' => ['pb-'],
      'double-colon inside' => ['.a::>'],
      'nested has' => ['> pb-text:has(:has(a))'],
      'has inside a has through not' => ['> p:has(a:not(:has(b)))'],
      'nested not' => ['> p:not(:not(p))'],
      'universal in not' => ['> *:not(*)'],
      'combinator in not' => ['> p:not(> p)'],
      'chain in not' => ['> p:not(p + p)'],
      'qualifier-only compound' => ['pb-text :has(a)'],
      'empty has' => ['> pb-text:has()'],
      'empty not' => ['> p:not()'],
      // The pseudo-element: two names, terminal, glued, and the anchor
      // stands only as its own subject.
      'other pseudo-element' => ['::marker'],
      'other pseudo-element on a part' => ['.quote::first-letter'],
      'bare pseudo-element' => ['::before'],
      'anchor alone' => ['&'],
      'anchor in a chain' => ['& > p'],
      'anchor after a compound' => ['> pb-text &::before'],
      'pseudo-element not terminal' => ['> pb-text::before .x'],
      'pseudo-element before a combinator' => ['> pb-text::before > *'],
      'pseudo-element inside has' => ['> pb-text:has(a::before)'],
      'pseudo-element inside not' => ['> *:not(pb-image::after)'],
      'pseudo-element on the anchor inside has' => ['> pb-text:has(&::before)'],
      'doubled pseudo-element' => ['.quote::before::after'],
      'pseudo-element behind a space' => ['> pb-text ::before'],
      'anchor pseudo-element behind a space' => ['& ::before'],
      'uppercase pseudo-element' => ['&::Before'],
      'pseudo-class spelled pseudo-element' => ['p:before'],
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
    [$root, $selector] = RelativeSelector::splitKey('container:x::> pb-text:has(a):not(.featured)');
    $this->assertSame('container:x', $root);
    $this->assertSame('> pb-text:has(a):not(.featured)', (string) $selector);
    // A pseudo-element key still splits at its FIRST "::": the anchor or
    // a compound always precedes the pseudo's own.
    [$root, $selector] = RelativeSelector::splitKey('container:x::&::before');
    $this->assertSame('container:x', $root);
    $this->assertSame('&::before', (string) $selector);
    $this->assertSame('before', $selector->pseudo);
    [$root, $selector] = RelativeSelector::splitKey('block(text,1)::.quote::after');
    $this->assertSame('block(text,1)', $root);
    $this->assertSame('.quote::after', (string) $selector);
    // The canonical spelling only: a stored key has one shape.
    $this->assertNull(RelativeSelector::splitKey('container:x::>'));
    $this->assertNull(RelativeSelector::splitKey('container:x::.a::>'));
    $this->assertNull(RelativeSelector::splitKey('container:x::>*'));
    $this->assertNull(RelativeSelector::splitKey('container:x::'));
    $this->assertNull(RelativeSelector::splitKey('container:x::> pb-text:not(.featured):has(a)'));
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
    $this->assertFalse(RelativeSelector::fromString('> *:not(pb-image)')->isChildren());
    $this->assertTrue(RelativeSelector::fromString('.cls')->isPart());
    $this->assertFalse(RelativeSelector::fromString('.cls > *')->isPart());
    $this->assertFalse(RelativeSelector::fromString('.cls:has(a)')->isPart());
    $this->assertTrue(RelativeSelector::fromString('p')->isTag());
    $this->assertFalse(RelativeSelector::fromString('> p')->isTag());
    $this->assertFalse(RelativeSelector::fromString('p:has(a)')->isTag());
    $this->assertSame(['.s-a3'], RelativeSelector::fromString('> pb-text .s-a3')->classes());
    $this->assertSame(['.s-a3', '.featured', '.link'], RelativeSelector::fromString('> pb-text:has(.s-a3):not(.featured) .link')->classes());
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
    $this->assertSame('before', RelativeSelector::fromString('> pb-text::before')->pseudo);
    $this->assertNull(RelativeSelector::fromString('> pb-text')->pseudo);
    $this->assertSame(self::compound('child', 'type', 'pb-text'), RelativeSelector::fromString('> pb-text::before')->last());

    $this->assertSame('block_type:text', (string) RelativeSelector::typeAddress('pb-text'));
    $this->assertSame('block_type:formatted_text', (string) RelativeSelector::typeAddress('pb-formatted-text'));
    $this->assertSame('kind:container', (string) RelativeSelector::typeAddress('pb-container'));
    $this->assertSame('kind:block', (string) RelativeSelector::typeAddress('pb-block'));
    $this->assertSame('pb-formatted-text', RelativeSelector::typeName(Address::blockType('formatted_text')));
    $this->assertSame('pb-container', RelativeSelector::typeName(Address::kind('container')));
    $this->assertSame('pb-block', RelativeSelector::typeName(Address::kind('block')));
    $this->assertNull(RelativeSelector::typeName(Address::layout('x')));
    $this->assertNull(RelativeSelector::typeName(Address::blockPlugin('menu')));
    $this->assertNull(RelativeSelector::typeName(Address::fromString('block_type:text::p')));
    $this->expectException(\InvalidArgumentException::class);
    RelativeSelector::typeAddress('text');
  }

  /**
   * under() continues a rule by descent and lets the grammar refuse.
   */
  public function testUnder(): void {
    $under = static fn (string $rule, string $suffix): ?string => RelativeSelector::under(RelativeSelector::fromString($rule), RelativeSelector::fromString($suffix))?->__toString();
    $this->assertSame('> pb-text .link', $under('> pb-text', '.link'));
    $this->assertSame('> pb-text > *', $under('> pb-text', '> *'));
    $this->assertSame('> pb-text p', $under('> pb-text', 'p'));
    $this->assertSame('a p', $under('a', 'p'));
    $this->assertSame('> pb-text:has(a) .link ~ .link', $under('> pb-text:has(a)', '.link ~ .link'));
    // A class after a tag or the children is outside the grammar: the
    // suffix lands nowhere under that rule.
    $this->assertNull($under('a', '.cls'));
    $this->assertNull($under('> *', '.cls'));
    // A pseudo binds to the rule's landing: the anchor is the rule's own
    // targets, a compound's pseudo continues by descent, and a rule
    // already ending in a pseudo admits nothing after it.
    $this->assertSame('> pb-text::before', $under('> pb-text', '&::before'));
    $this->assertSame('.cls::after', $under('.cls', '&::after'));
    $this->assertSame('> *::before', $under('> *', '&::before'));
    $this->assertSame('> pb-text:has(a)::after', $under('> pb-text:has(a)', '&::after'));
    $this->assertSame('> pb-text .link::before', $under('> pb-text', '.link::before'));
    $this->assertSame('> pb-text p::after', $under('> pb-text', 'p::after'));
    $this->assertNull($under('a', '.cls::before'));
    $this->assertNull($under('> pb-text::before', '&::after'));
    $this->assertNull($under('> pb-text::before', '.link'));
    $this->assertNull($under('&::before', 'p'));
  }

  /**
   * continues() reads under() back: a rule's continuations by descent.
   */
  public function testContinues(): void {
    $continues = static fn (string $rule, string $candidate): bool => RelativeSelector::continues(RelativeSelector::fromString($rule), RelativeSelector::fromString($candidate));
    $this->assertTrue($continues('> pb-text', '> pb-text .link'));
    $this->assertTrue($continues('> pb-text', '> pb-text > *'));
    $this->assertTrue($continues('> pb-text', '> pb-text p'));
    $this->assertTrue($continues('> pb-text', '> pb-text .link::before'));
    $this->assertTrue($continues('> pb-text:has(a)', '> pb-text:has(a) .link ~ .link'));
    // The rule's own box continues it (the anchor binds to its landing).
    $this->assertTrue($continues('> pb-text', '> pb-text::before'));
    $this->assertTrue($continues('.cls', '.cls::after'));
    // The rule itself, a longer name sharing its prefix, a sibling rule
    // and anything a pseudo-terminated rule would host do not.
    $this->assertFalse($continues('> pb-text', '> pb-text'));
    $this->assertFalse($continues('> pb-text', '> pb-textual'));
    $this->assertFalse($continues('> pb-text', '> pb-container .link'));
    $this->assertFalse($continues('> pb-text', '.link'));
    $this->assertFalse($continues('&::before', 'p'));
    $this->assertFalse($continues('> pb-text::before', '> pb-text .link'));
  }

  /**
   * The PHP grammar and its client twin answer the same battery.
   */
  public function testClientTwinEquivalence(): void {
    $selectors = [
      '.cls', 'p', '> *', '.cls > *', 'p > *', '> pb-text', 'pb-text', '> pb-text .link', '> .cls', '.cls p',
      'pb-container > * > *', '>*', '  .cls>*  ', ">pb-text\n  .link", '', '>', '*', '::>', '.a .b', 'p .a',
      '> * .a', '> >', 'p >', '.a.b', 'p:hover', 'div, p', '[x]', 'P', '2x', 'pb-', 'a + b', '> pb-text .s-a3',
      '> p + p', 'p ~ p', '> p + *', '> pb-text:has(a)', '> pb-text:has(+ pb-image)', '> pb-container:has(> pb-image) > *',
      '> pb-text .link ~ .link', '> *:not(pb-image)', '> *:not(pb-image):not(pb-video)', '> pb-text:not(:has(a))',
      '> pb-text:has(a):has(img)', '> pb-text:has(a):not(.featured)', '>p+p', '> pb-text:has( a )',
      '> pb-text:not(.featured):has(a)', '>pb-text:has(+pb-image)', '.cls:not(.x)', 'p:has(> a)',
      '> pb-text:has(.s-a3):not(.featured) .link', '+ p', '~ p', '> pb-text:has(:has(a))', '> p:has(a:not(:has(b)))',
      '> p:not(:not(p))', '> *:not(*)', '> p:not(> p)', '> p:not(p + p)', 'p + .cls', 'pb-text :has(a)', '> p +',
      '> pb-text:has()', '> p:not()', '.a::>',
      // The pseudo-element: the accepted shapes, the lenient spellings
      // and every refusal, both twins.
      '&::before', '&::after', '.quote::after', 'p::before', '> pb-text::before', '> *::before', '> p + p::after',
      '> pb-text:has(a)::before', '> pb-text .link::before', '  &::before ', ">pb-text::after\n", '>pb-text:has( a )::before',
      '::marker', '.quote::first-letter', '::before', '&', '& > p', '> pb-text &::before', '> pb-text::before .x',
      '> pb-text::before > *', '> pb-text:has(a::before)', '> *:not(pb-image::after)', '> pb-text:has(&::before)',
      '.quote::before::after', '> pb-text ::before', '& ::before', '&::Before', 'p:before',
    ];
    $keys = [
      'container:x', 'container:x::> *', 'block(text,1)::.quote > *', 'container:x::>', 'container:x::.a::>',
      'container:x::>*', 'container:x::', '', '::.cls', '::> pb-text .link', 'container:x::> p + p',
      'container:x::> pb-text:has(a):not(.featured)', 'container:x::> pb-text:not(.featured):has(a)', 'container:x::+ p',
      'container:x::&::before', 'block(text,1)::.quote::after', '::&::after', 'container:x::> pb-text::before',
      'container:x::::before', 'container:x::&', 'container:x:: &::before', 'container:x::.quote::before::after',
    ];
    $unders = [
      ['> pb-text', '.link'], ['> pb-text', '> *'], ['> pb-text', 'p'], ['a', 'p'], ['> pb-text:has(a)', '.link ~ .link'],
      ['a', '.cls'], ['> *', '.cls'], ['.cls', '> pb-text .link'], ['> pb-container', '.cls > *'],
      ['> pb-text', '&::before'], ['.cls', '&::after'], ['> *', '&::before'], ['> pb-text:has(a)', '&::after'],
      ['> pb-text', '.link::before'], ['> pb-text', 'p::after'], ['a', '.cls::before'],
      ['> pb-text::before', '&::after'], ['> pb-text::before', '.link'], ['&::before', 'p'],
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
    ];
    $stdout = $this->runNodeScript('tests/js/relative-selector-cases.mjs', json_encode(['selectors' => $selectors, 'keys' => $keys, 'unders' => $unders]));
    $this->assertSame($expected, json_decode($stdout, TRUE), 'The client twin answers the battery exactly as the grammar does.');
  }

}
