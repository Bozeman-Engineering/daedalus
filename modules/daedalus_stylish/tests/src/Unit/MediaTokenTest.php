<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Unit;

use Drupal\daedalus_stylish\MediaToken;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the media token grammar spine.
 *
 * The one canonical parser for url('media:<uuid>') tokens inside CSS
 * values: build/extract round trips, quote variants, composition with
 * gradients and comma layers, substitution, and malformed-reference
 * detection. PHP is authoritative; the client mirror locks the same cases.
 *
 * The canonical build is single-quoted (dialect 0.4); the double-quoted
 * spelling is retired from the vocabulary but must stay READABLE forever
 * (values stored before the flip resolve with no migration), so the
 * legacy variants below are constructed, never spelled as literals.
 */
#[Group('daedalus_stylish')]
class MediaTokenTest extends UnitTestCase {

  protected const UUID = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

  /**
   * The build() produces the canonical token; extract() reads it back.
   */
  public function testBuildExtractRoundTrip(): void {
    $token = MediaToken::build(self::UUID);
    $this->assertSame("url('media:" . self::UUID . "')", $token);
    $this->assertSame([self::UUID], MediaToken::extract($token));
    // build() canonicalizes case.
    $this->assertSame($token, MediaToken::build(strtoupper(self::UUID)));
  }

  /**
   * Extraction accepts double-quoted, single-quoted, and unquoted url().
   */
  public function testExtractQuoteVariants(): void {
    foreach ([
      "url('media:%s')",
      str_replace("'", '"', "url('media:%s')"),
      'url(media:%s)',
      str_replace("'", '"', "url( 'media:%s' )"),
      str_replace("'", '"', "URL('MEDIA:%s')"),
    ] as $format) {
      $value = sprintf($format, self::UUID);
      $this->assertSame([self::UUID], array_map('strtolower', MediaToken::extract($value)), $value);
    }
  }

  /**
   * Tokens compose with gradients and comma layers; order is source order.
   */
  public function testExtractComposition(): void {
    $other = '123e4567-e89b-12d3-a456-426614174000';
    $value = sprintf("linear-gradient(120deg, #1a1a2e, #16213e), url('media:%s'), url(media:%s)", self::UUID, $other);
    $this->assertSame([self::UUID, $other], MediaToken::extract($value));
    // Ordinary url() values are not tokens.
    $this->assertSame([], MediaToken::extract('url(/sites/default/files/hero.png)'));
    $this->assertSame([], MediaToken::extract('linear-gradient(#fff, #000)'));
  }

  /**
   * The substitute() maps each token through the callable, leaving the rest.
   */
  public function testSubstitute(): void {
    $value = sprintf("linear-gradient(#fff, #000), url('media:%s')", self::UUID);
    $resolved = MediaToken::substitute($value, static fn (string $uuid): string => "url(/files/$uuid.png)");
    $this->assertSame(sprintf('linear-gradient(#fff, #000), url(/files/%s.png)', self::UUID), $resolved);
    $blanked = MediaToken::substitute($value, static fn (): string => 'none');
    $this->assertSame('linear-gradient(#fff, #000), none', $blanked);
    // A token-free value passes through untouched.
    $this->assertSame('cover', MediaToken::substitute('cover', static fn (): string => 'none'));
  }

  /**
   * The mentions() and hasMalformedReference() classify the failure modes.
   */
  public function testMalformedDetection(): void {
    $this->assertFalse(MediaToken::mentions('url(hero.png)'));
    $this->assertTrue(MediaToken::mentions("url('media:x')"));

    // A well-formed token is fully accounted for.
    $this->assertFalse(MediaToken::hasMalformedReference(MediaToken::build(self::UUID)));
    // Bare scheme outside url(), unclosed url(), mismatched quotes: all
    // leave media-scheme text extraction cannot account for.
    $this->assertTrue(MediaToken::hasMalformedReference('media:' . self::UUID));
    $this->assertTrue(MediaToken::hasMalformedReference("url('media:" . self::UUID));
    $this->assertTrue(MediaToken::hasMalformedReference(sprintf('url(\'media:%s")', self::UUID)));
  }

}
