<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Unit;

use Drupal\daedalus_stylish\KeyframesNames;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the keyframes-name grammar and locks its client twin.
 *
 * KeyframesNames::inValue() is the property-scoped half of the animation
 * reference grammar: only animation-name and the animation shorthand
 * carry keyframes names, and inside the shorthand a keyword another
 * longhand claims (easing, direction, fill, play state, `infinite`,
 * `auto`, `none`) is never the name. PHP is authoritative; the client
 * twin (js/src/keyframes-names.js) answers the same battery through
 * tests/js/keyframes-cases.mjs and must agree exactly, or the lint and
 * the completions would read a value differently from what the server
 * validates. Pure string logic, so the lock runs as a unit test.
 */
#[Group('daedalus_stylish')]
final class KeyframesNamesTest extends UnitTestCase {

  use NodeSuitesTrait;

  /**
   * The battery: [property, value, expected names].
   *
   * @return array<int, array{string, string, string[]}>
   *   The cases.
   */
  public static function cases(): array {
    return [
      // animation-name: a comma list of names; none and CSS-wide keywords
      // are not names, var() and non-idents are the grammar's business.
      ['animation-name', 'motion-fade_in', ['motion-fade_in']],
      ['animation-name', ' motion-fade_in , spin,none', ['motion-fade_in', 'spin']],
      ['animation-name', 'none', []],
      ['animation-name', 'NONE', []],
      ['animation-name', 'inherit', []],
      ['animation-name', 'var(--x)', []],
      ['animation-name', '2fast', []],
      ['animation-name', '', []],
      ['animation-name', 'motion-fade_in, motion-fade_in', ['motion-fade_in']],
      // Quoted strings are names (spec: <custom-ident> | <string>).
      ['animation-name', '"quoted name"', ['quoted name']],
      ['animation-name', "'motion-fade_in'", ['motion-fade_in']],
      ['animation-name', '""', []],
      // The shorthand: one name per layer, whichever position it takes;
      // times, counts, functions and other longhands' keywords never are.
      ['animation', 'motion-fade_in 600ms ease-out both', ['motion-fade_in']],
      ['animation', '600ms ease-out both motion-fade_in', ['motion-fade_in']],
      ['animation', 'spin 1s infinite linear, motion-rise 300ms', ['spin', 'motion-rise']],
      ['animation', 'none', []],
      ['animation', '1s cubic-bezier(0.4, 0, 0.2, 1) motion-fade_in', ['motion-fade_in']],
      ['animation', 'steps(4, end) 2s motion-x', ['motion-x']],
      ['animation', 'var(--motion) 1s', []],
      ['animation', 'motion-fade_in var(--dur)', ['motion-fade_in']],
      ['animation', 'ease 1s', []],
      ['animation', 'linear', []],
      ['animation', 'alternate reverse both paused running infinite auto', []],
      ['animation', '-1s .5s motion-fade_in', ['motion-fade_in']],
      ['animation', '"quoted name" 1s', ['quoted name']],
      ['animation', 'motion-a 1s motion-b', ['motion-a']],
      ['animation', 'motion-fade_in 1s, ', ['motion-fade_in']],
      ['animation', 'initial', []],
      ['animation', '', []],
      // Every other property carries no keyframes name.
      ['animation-duration', 'motion-fade_in', []],
      ['transition', 'motion-fade_in 1s', []],
      ['transition-property', 'motion-fade_in', []],
    ];
  }

  /**
   * The PHP grammar answers the battery.
   */
  public function testInValue(): void {
    foreach (self::cases() as [$property, $value, $expected]) {
      $this->assertSame($expected, KeyframesNames::inValue($property, $value), "$property: $value");
    }
    $this->assertSame(['animation-name', 'animation'], KeyframesNames::PROPERTIES);
  }

  /**
   * The PHP grammar and its client twin answer the battery identically.
   */
  public function testClientTwinEquivalence(): void {
    $cases = array_map(static fn (array $case): array => ['property' => $case[0], 'value' => $case[1]], self::cases());
    $php = array_map(static fn (array $case): array => KeyframesNames::inValue($case['property'], $case['value']), $cases);
    $js = json_decode($this->runNodeScript('tests/js/keyframes-cases.mjs', json_encode(['cases' => $cases], JSON_THROW_ON_ERROR)), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($js['cases'] ?? NULL);
    $this->assertSameSize($php, $js['cases']);
    foreach ($php as $index => $expected) {
      $this->assertSame($expected, $js['cases'][$index], sprintf('Case %d (%s: %s) diverged between the PHP grammar and the client twin.', $index, $cases[$index]['property'], $cases[$index]['value']));
    }
    // Guard against a silently inert battery: names were found.
    $this->assertNotEmpty(array_filter($php));
  }

}
