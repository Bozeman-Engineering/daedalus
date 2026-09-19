<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Unit;

use Drupal\daedalus_stylish\SlotKey;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the slot-key grammar spine (composition variants).
 *
 * The one canonical parser for element-relative style keys: the degenerate
 * pre-composition key space ('' and '::.class'), slash-separated slot
 * paths, root-kind-parameterized structural rules, and the scope-to-root
 * mapping. The JS mirror (js/src/slot-key.js) locks the same cases in
 * tests/js/slot-key.test.mjs; PHP is authoritative.
 */
#[Group('daedalus_stylish')]
class SlotKeyTest extends UnitTestCase {

  /**
   * The '' and '::.class' keys parse for every root kind, NULL included.
   */
  public function testDegenerateKeys(): void {
    foreach ([...SlotKey::ROOT_KINDS, NULL] as $root_kind) {
      $root = SlotKey::parse('', $root_kind);
      $this->assertNotNull($root);
      $this->assertTrue($root->isRoot());
      $this->assertSame([], $root->segments);
      $this->assertNull($root->part);
      $this->assertSame('', $root->format());

      $part = SlotKey::parse('::.cta__button', $root_kind);
      $this->assertNotNull($part);
      $this->assertFalse($part->isRoot());
      $this->assertSame([], $part->segments);
      $this->assertSame('.cta__button', $part->part);
      $this->assertSame('::.cta__button', $part->format());
    }
  }

  /**
   * Container roots: child-first paths, recursion, part suffixes.
   */
  public function testContainerRootPaths(): void {
    $slot = SlotKey::parse('block(text,2)', 'container');
    $this->assertSame([['type' => 'block', 'name' => 'text', 'index' => 2]], $slot->segments);
    $this->assertNull($slot->part);

    $slot = SlotKey::parse('block(text,12)::.quote', 'container');
    $this->assertSame(12, $slot->segments[0]['index']);
    $this->assertSame('.quote', $slot->part);

    $slot = SlotKey::parse('block(image,1)/field(field_media)', 'container');
    $this->assertSame('field', $slot->segments[1]['type']);
    $this->assertSame('field_media', $slot->segments[1]['name']);
    $this->assertArrayNotHasKey('index', $slot->segments[1]);

    // container(n) is the recursion point back into a child flow — a
    // nested container is an ordinary container (containers all the way
    // down).
    $slot = SlotKey::parse('container(2)', 'container');
    $this->assertSame([['type' => 'container', 'index' => 2]], $slot->segments);
    $slot = SlotKey::parse('container(1)/container(2)/block(text,1)', 'container');
    $this->assertCount(3, $slot->segments);

    // A container root never opens at a field segment.
    $this->assertNull(SlotKey::parse('field(field_media)', 'container'));
  }

  /**
   * Block roots: field-first paths, no index, no container entry.
   */
  public function testBlockRootPaths(): void {
    $slot = SlotKey::parse('field(field_image)', 'block');
    $this->assertSame([['type' => 'field', 'name' => 'field_image']], $slot->segments);
    $this->assertNotNull(SlotKey::parse('field(field_image)::.caption', 'block'));

    $this->assertNull(SlotKey::parse('container(1)', 'block'));
    $this->assertNull(SlotKey::parse('block(text,1)', 'block'));
    // One field per name: an index on a field segment never parses.
    $this->assertNull(SlotKey::parse('field(field_image,1)', 'block'));
  }

  /**
   * Field, entity and region roots carry no paths; neither does NULL.
   */
  public function testPartsOnlyRoots(): void {
    foreach (['field', 'entity', 'region', NULL] as $root_kind) {
      $this->assertNull(SlotKey::parse('container(1)', $root_kind));
      $this->assertNull(SlotKey::parse('field(field_image)', $root_kind));
      $this->assertNull(SlotKey::parse('container(1)/block(text,1)', $root_kind));
    }
  }

  /**
   * Segment ordering rules hold at every depth, not just the first.
   */
  public function testStructuralOrdering(): void {
    // The retired region and section segments are gone from the grammar.
    $this->assertNull(SlotKey::parse('region(first)', 'container'));
    $this->assertNull(SlotKey::parse('region(first)/block(text,1)', 'container'));
    $this->assertNull(SlotKey::parse('section(1)', 'container'));
    // Container is followed by container or block segments only.
    $this->assertNull(SlotKey::parse('container(1)/field(field_x)', 'container'));
    // Blocks never recurse into containers; only containers do.
    $this->assertNull(SlotKey::parse('block(text,1)/container(1)', 'container'));
    $this->assertNull(SlotKey::parse('block(text,1)/block(text,2)', 'container'));
    // Field terminates the path.
    $this->assertNull(SlotKey::parse('field(field_a)/field(field_b)', 'block'));
    $this->assertNull(SlotKey::parse('block(text,1)/field(field_a)/field(field_b)', 'container'));
  }

  /**
   * Malformed keys never parse, whatever the root kind.
   */
  public function testMalformedKeys(): void {
    $bad = [
      'container()',
      'block(text)',
      'block(text,0)',
      'block(text,01)',
      'block(text, 1)',
      'block(Text,1)',
      'container(0)',
      'container(01)',
      'container(x)',
      'container(x,1)',
      'container(1)/',
      '/container(1)',
      'container(1)//block(text,1)',
      'container (1)',
      ' container(1)',
      'container(1) ',
      'region(first)',
      'section(1)',
      'container(1)::card',
      'container(1)::.a::.b',
      'container(1)::.a/block(text,1)',
      '::.',
      '::',
      '::.bad{selector}',
      '::.café',
      'not a key',
    ];
    foreach ($bad as $key) {
      $this->assertNull(SlotKey::parse($key, 'container'), "\"$key\" must not parse.");
    }
    // The part charset is the class charset: hyphens and case are legal.
    $this->assertNotNull(SlotKey::parse('::.Card-9_x', 'container'));
  }

  /**
   * The '::>' children pseudo-part suffix terminates any key.
   */
  public function testChildrenPseudoPart(): void {
    // Bare root children, for every root kind (unknown included: the
    // path-less key space grows the same suffix).
    foreach ([...SlotKey::ROOT_KINDS, NULL] as $root_kind) {
      $slot = SlotKey::parse('::>', $root_kind);
      $this->assertNotNull($slot);
      $this->assertTrue($slot->children);
      $this->assertFalse($slot->isRoot());
      $this->assertSame([], $slot->segments);
      $this->assertNull($slot->part);
      $this->assertSame('::>', $slot->format());
    }

    // A part's children: the part suffix keeps parsing, children rides
    // last.
    $slot = SlotKey::parse('::.daedalus-stylish-layout-container::>', 'container');
    $this->assertNotNull($slot);
    $this->assertSame('.daedalus-stylish-layout-container', $slot->part);
    $this->assertTrue($slot->children);

    // Slot paths compose too.
    $slot = SlotKey::parse('container(1)::>', 'container');
    $this->assertNotNull($slot);
    $this->assertSame([['type' => 'container', 'index' => 1]], $slot->segments);
    $this->assertTrue($slot->children);
    $this->assertTrue(SlotKey::parse('container(1)/block(text,1)::.card::>', 'container')->children);

    // Keys without the suffix stay children-free.
    $this->assertFalse(SlotKey::parse('', 'container')->children);
    $this->assertFalse(SlotKey::parse('::.card', 'container')->children);

    // The children suffix is the LAST suffix and never doubles.
    $this->assertNull(SlotKey::parse('::>::.card', 'container'));
    $this->assertNull(SlotKey::parse('::>::>', 'container'));
    $this->assertNull(SlotKey::parse('::>x', 'container'));
    $this->assertNull(SlotKey::parse('::.card::>::>', 'container'));
  }

  /**
   * The parse(format()) round-trips every canonical key losslessly.
   */
  public function testFormatRoundTrip(): void {
    $keys = [
      '',
      '::.cta__button',
      'container(1)',
      'container(1)::.card',
      'block(text,2)',
      'container(1)/container(2)/block(image,3)/field(field_media)::.caption',
      '::>',
      '::.daedalus-stylish-layout-container::>',
      'container(1)::>',
      'container(1)/block(text,1)::.card::>',
    ];
    foreach ($keys as $key) {
      $slot = SlotKey::parse($key, 'container');
      $this->assertNotNull($slot, "\"$key\" must parse.");
      $this->assertSame($key, $slot->format());
    }
    $this->assertSame('field(field_image)::.caption', SlotKey::parse('field(field_image)::.caption', 'block')->format());
  }

  /**
   * Scopes map to structural root kinds.
   */
  public function testRootKindForScope(): void {
    $this->assertSame('container', SlotKey::rootKindForScope('kind:container'));
    $this->assertSame('container', SlotKey::rootKindForScope('layout:daedalus_stylish_container'));
    // The retired kind name maps to nothing.
    $this->assertNull(SlotKey::rootKindForScope('kind:section'));
    $this->assertSame('block', SlotKey::rootKindForScope('kind:block'));
    $this->assertSame('block', SlotKey::rootKindForScope('block_type:text'));
    $this->assertSame('field', SlotKey::rootKindForScope('kind:field'));
    $this->assertSame('entity', SlotKey::rootKindForScope('kind:entity'));
    $this->assertSame('region', SlotKey::rootKindForScope('kind:region'));
    $this->assertNull(SlotKey::rootKindForScope('kind:bogus'));
    $this->assertNull(SlotKey::rootKindForScope('block_type:'));
    $this->assertNull(SlotKey::rootKindForScope('layout:Bad-Name'));
    $this->assertNull(SlotKey::rootKindForScope('bogus'));
    $this->assertNull(SlotKey::rootKindForScope(''));
  }

  /**
   * An unknown non-NULL root kind is a caller bug and throws.
   */
  public function testUnknownRootKindThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    SlotKey::parse('', 'bogus');
  }

}
