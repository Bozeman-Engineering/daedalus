<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\Materials;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The styling address grammar: parse, print, pairing, kind.
 */
#[Group('daedalus_blueprint')]
class AddressTest extends UnitTestCase {

  /**
   * Every family parses and prints back byte-identical, selectors included.
   */
  #[DataProvider('printed')]
  public function testParseAndPrint(string $printed, string $family, string $id, ?string $selector): void {
    $address = Address::parse($printed);
    $this->assertNotNull($address, "$printed parses.");
    $this->assertSame($family, $address->family);
    $this->assertSame($id, $address->id);
    $this->assertSame($selector, $address->selector?->__toString());
    $this->assertSame($printed, (string) $address, 'The print is the parse\'s inverse.');
    $this->assertSame($printed, (string) Address::fromString($printed));
    $this->assertSame($selector === NULL, $address->isRoot());
    $this->assertSame($family . ':' . $id, (string) $address->root());
    $this->assertSame($printed, (string) $address->root()->withSelector($address->selector));
  }

  /**
   * Printed addresses and their parts.
   */
  public static function printed(): array {
    return [
      'kind' => ['kind:container', 'kind', 'container', NULL],
      'block type' => ['block_type:text', 'block_type', 'text', NULL],
      'block plugin' => ['block_plugin:system_menu_block', 'block_plugin', 'system_menu_block', NULL],
      'layout' => ['layout:layout_onecol', 'layout', 'layout_onecol', NULL],
      'base' => ['base:h2', 'base', 'h2', NULL],
      'class part' => ['kind:container::.inner', 'kind', 'container', '.inner'],
      'tag' => ['block_type:text::p', 'block_type', 'text', 'p'],
      'children' => ['layout:layout_onecol::> *', 'layout', 'layout_onecol', '> *'],
      'part children' => ['kind:block::.Card_1 > *', 'kind', 'block', '.Card_1 > *'],
      'type chain' => ['kind:container::> pb-text .link', 'kind', 'container', '> pb-text .link'],
    ];
  }

  /**
   * Strings outside the grammar parse to NULL and refuse fromString().
   */
  #[DataProvider('malformed')]
  public function testMalformed(string $printed): void {
    $this->assertNull(Address::parse($printed), "$printed is not an address.");
    $this->expectException(\InvalidArgumentException::class);
    Address::fromString($printed);
  }

  /**
   * Strings that are not addresses.
   */
  public static function malformed(): array {
    return [
      'empty' => [''],
      'no family' => ['container'],
      'unknown family' => ['bogus:container'],
      'empty id' => ['kind:'],
      'bad id charset' => ['layout:Bad-Name'],
      'uuid element key' => ['container:aaaaaaaa-1111-2222-3333-444444444444'],
      'bad part' => ['kind:container::.a .b'],
      'retired children spelling' => ['kind:container::>'],
      'retired part children spelling' => ['kind:container::.a::>'],
      'doubled children' => ['kind:container::>::>'],
      'two parts' => ['kind:container::.a::.b'],
      'non-canonical spelling' => ['kind:container::>*'],
    ];
  }

  /**
   * The named constructors print the family they name and refuse bad ids.
   */
  public function testConstructors(): void {
    $this->assertSame('kind:block', (string) Address::kind('block'));
    $this->assertSame('block_type:cta', (string) Address::blockType('cta'));
    $this->assertSame('block_plugin:menu', (string) Address::blockPlugin('menu'));
    $this->assertSame('layout:layout_twocol_section', (string) Address::layout('layout_twocol_section'));
    $this->assertSame('base:a', (string) Address::base('a'));
    $this->assertSame('kind:container::.inner > *', (string) Address::of('kind', 'container', '.inner > *'));
    // A selector given as text canonicalizes; as an object it rides as is.
    $this->assertSame('kind:container::.inner > *', (string) Address::of('kind', 'container', '.inner>*'));
    $this->assertSame('kind:container::> pb-text', (string) Address::of('kind', 'container', RelativeSelector::fromString('> pb-text')));
    $this->assertTrue(Address::kind('block')->equals(Address::fromString('kind:block')));
    $this->assertFalse(Address::kind('block')->equals(Address::blockType('block')));
    foreach ([['kind', 'Bad-Name'], ['nope', 'x'], ['layout', '']] as [$family, $id]) {
      try {
        Address::of($family, $id);
        $this->fail("$family:$id was accepted.");
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
    $this->expectException(\InvalidArgumentException::class);
    Address::of('kind', 'container', 'a b c!');
  }

  /**
   * The element kind each family belongs to.
   */
  public function testKind(): void {
    $this->assertSame('container', Address::kind('container')->elementKind());
    $this->assertSame('container', Address::layout('layout_onecol')->elementKind());
    $this->assertSame('block', Address::blockType('text')->elementKind());
    $this->assertSame('block', Address::blockPlugin('menu')->elementKind());
    $this->assertSame('field', Address::kind('field')->elementKind());
    $this->assertSame('entity', Address::fromString('kind:entity::.title')->elementKind());
    $this->assertNull(Address::base('a')->elementKind());
    $this->assertTrue(Address::kind('field')->isKind());
    $this->assertFalse(Address::blockType('text')->isKind());
    $this->assertTrue(Address::base('a')->isBase());
  }

  /**
   * The family rule, on the object (MaterialsRosterTest::scopePairs' battery).
   */
  #[DataProvider('pairs')]
  public function testPairsWith(string $variant, string $element, bool $expected): void {
    $this->assertSame($expected, Address::fromString($variant)->pairsWith(Address::fromString($element)));
  }

  /**
   * Variant and element addresses and their verdicts.
   */
  public static function pairs(): array {
    return [
      'exact' => ['block_type:text', 'block_type:text', TRUE],
      'kind pairs with its family refinement' => ['kind:container', 'layout:layout_onecol', TRUE],
      'refinement pairs with its kind' => ['layout:layout_onecol', 'kind:container', TRUE],
      'two layouts never pair' => ['layout:layout_onecol', 'layout:layout_twocol_section', FALSE],
      'block kind pairs with a block type' => ['kind:block', 'block_type:cta', TRUE],
      'block type pairs with the block kind' => ['block_type:cta', 'kind:block', TRUE],
      'block plugin pairs with the block kind' => ['block_plugin:menu', 'kind:block', TRUE],
      'block type and block plugin never pair' => ['block_type:cta', 'block_plugin:menu', FALSE],
      'families never cross' => ['block_type:cta', 'kind:container', FALSE],
      'container kind never pairs with a block' => ['kind:container', 'kind:block', FALSE],
      'base tag pairs with everything' => ['base:a', 'block_type:cta', TRUE],
      'base tag pairs with a container' => ['base:h2', 'layout:layout_onecol', TRUE],
      'an element scope is never a base wildcard' => ['block_type:cta', 'base:a', FALSE],
      'unknown families pair only exactly' => ['kind:field', 'kind:entity', FALSE],
      'unknown family exact' => ['kind:field', 'kind:field', TRUE],
      'selectors ride their element' => ['kind:container', 'layout:layout_onecol::> pb-text', TRUE],
      'a part never pairs across families' => ['kind:block::.title', 'kind:container', FALSE],
    ];
  }

}
