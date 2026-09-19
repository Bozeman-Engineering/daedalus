<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Unit;

use Drupal\daedalus_stylish\MediaToken;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Locks the JS media token mirror to MediaToken.php (the client half).
 *
 * The client resolves media tokens as a pre-print transform on the preview
 * path (js/src/media-token.js); PHP is authoritative for the grammar. A
 * battery of CSS values runs through both sides' substitute with the same
 * marker replacement and must come out identical, or the preview would
 * resolve tokens the server grammar would not (or leak ones it resolves).
 *
 * Separate from PrinterEquivalenceTest on purpose: the printer pair's
 * round-trip contract is untouched by media resolution (the printers never
 * see a token), and this needs no Drupal container at all: MediaToken is
 * pure string logic, so the parity lock runs as a unit test. The client's
 * own node suites (media-token, media-picker) run from JsSuitesTest.
 */
#[Group('daedalus_stylish')]
class MediaClientMirrorTest extends UnitTestCase {

  use NodeSuitesTrait;

  /**
   * The PHP and JS token grammars substitute identically.
   */
  public function testTokenGrammarParity(): void {
    $uuid = '123e4567-e89b-12d3-a456-426614174000';
    // The retired double-quoted spelling (pre-0.4 canonical) must stay
    // readable forever — stored values never migrate — but the literal is
    // banned from the vocabulary, so the legacy variants are constructed.
    $legacy = static fn (string $css): string => str_replace("'", '"', $css);

    $values = [
      // The three quote shapes, and case-insensitivity of url()/the scheme.
      $legacy("url('media:$uuid')"),
      "url('media:$uuid')",
      "url(media:$uuid)",
      $legacy("URL('MEDIA:" . strtoupper($uuid) . "')"),
      // Whitespace tolerance inside url().
      $legacy("url(  'media:$uuid'  )"),
      // Composition: gradients and comma layers survive around the token.
      "linear-gradient(120deg, #1a1a2e, #16213e), url('media:$uuid'), url(/real.png)",
      // Multiple tokens in one value, mixed quoting (legacy beside bare).
      $legacy("url('media:$uuid')") . ", url(media:$uuid)",
      // Non-uuid payloads still extract (validation owns the judgment).
      "url('media:not-a-uuid')",
      "url('media:')",
      // Malformed references never substitute on either side: bare scheme
      // outside url(), unclosed url(), mismatched quotes, payload split by
      // whitespace.
      'media:' . $uuid,
      "url('media:$uuid",
      $legacy("url('media:$uuid") . "')",
      "url('media:a b')",
      // No token at all.
      'linear-gradient(red, blue)',
      '',
    ];

    $php_output = array_map(
      static fn (string $value): string => MediaToken::substitute($value, static fn (string $payload): string => "<<$payload>>"),
      $values,
    );

    $js_output = json_decode($this->runNodeScript('tests/js/media-cases.mjs', json_encode(['values' => $values])), TRUE);
    $this->assertIsArray($js_output['values'] ?? NULL);
    $this->assertSameSize($php_output, $js_output['values']);
    foreach ($php_output as $index => $expected) {
      $this->assertSame($expected, $js_output['values'][$index], "Value $index diverged between the PHP and JS media token grammars.");
    }
    // Guard against a silently inert battery: substitutions happened, and
    // the malformed cases really did stay untouched.
    $this->assertSame("<<$uuid>>", $php_output[0]);
    $this->assertSame('media:' . $uuid, $php_output[9]);
  }

}
