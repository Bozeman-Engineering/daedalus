<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\Materials;

use Drupal\daedalus_blueprint\Materials\SelectorDigest;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The selector digester, and its client twin.
 *
 * SelectorDigest reads typed or pasted selector text relative to an
 * owner: the nesting sugar, the peel of posable states into the
 * condition, the selector lists, the published at-rule preludes, the
 * refusals with the dialect's codes, and the reach for the canvas paint.
 * The client twin (daedalus_ui's selector-digest.js) is locked to the
 * class by the shared battery tests/js/selector-digest-cases.mjs: the
 * node suite answers every case through the twin, this test answers
 * every case through the class, and both compare to the one recorded
 * answer, so the two can never drift.
 */
#[Group('daedalus_blueprint')]
class SelectorDigestTest extends UnitTestCase {

  use NodeSuitesTrait;

  /**
   * The twin lives beside daedalus_ui's grammar twin, so node runs there.
   */
  protected static function nodeModuleRoot(): string {
    return dirname((string) (new \ReflectionClass(static::class))->getFileName(), 6) . '/daedalus_ui';
  }

  /**
   * The answer's shape, read off the class.
   */
  public function testShape(): void {
    $conditions = [
      'hover' => ['wrapper' => ':hover', 'axis' => 'state'],
      'narrow' => ['wrapper' => '@container (max-width: 767px)', 'axis' => 'width'],
    ];
    $this->assertSame(['selector' => '> d-text', 'condition' => 'hover', 'refusal' => NULL, 'folded' => FALSE], SelectorDigest::digest('> d-text:hover', ['conditions' => $conditions]));
    $this->assertSame(
      ['selector' => SelectorDigest::OWNER, 'condition' => 'hover', 'refusal' => NULL, 'folded' => FALSE],
      SelectorDigest::digest(':hover', ['conditions' => $conditions]),
    );
    $this->assertSame(['selector' => 'li:hover > ul', 'condition' => NULL, 'refusal' => NULL, 'folded' => FALSE], SelectorDigest::digest('li:hover > ul', ['conditions' => $conditions]));
    $this->assertSame(['selector' => NULL, 'condition' => 'narrow', 'refusal' => NULL, 'folded' => FALSE], SelectorDigest::digest('@container (max-width: 767px)', ['conditions' => $conditions]));
    $this->assertSame(['selector' => 'a', 'condition' => ':hover', 'refusal' => NULL, 'folded' => FALSE], SelectorDigest::digest('a:hover'), 'With no roster a posable pseudo-class peels as a literal clause.');
    $this->assertSame(['selector' => 'a:visited', 'condition' => NULL, 'refusal' => NULL, 'folded' => FALSE], SelectorDigest::digest('a:visited'), 'A state no stamp stands in for stays a qualifier.');
    $this->assertSame(['selector' => NULL, 'condition' => '@media (hover: hover)', 'refusal' => NULL, 'folded' => FALSE], SelectorDigest::digest('@media (hover: hover)', ['conditions' => $conditions]), 'An unpublished query is a literal clause.');
    $this->assertSame(['selector' => '*:is(a, button)', 'condition' => 'hover', 'refusal' => NULL, 'folded' => TRUE], SelectorDigest::digest('a:hover, button:hover', ['conditions' => $conditions]), 'A list folds into one :is() rule and reports the fold.');
    $refused = SelectorDigest::digest('+ p');
    $this->assertNull($refused['selector']);
    $this->assertNull($refused['condition']);
    $this->assertFalse($refused['folded']);
    $this->assertSame(SelectorDigest::BAD_SELECTOR, $refused['refusal']['code']);
    $this->assertSame(['code', 'message', 'hint'], array_keys($refused['refusal']));
    $this->assertSame('li > ul', SelectorDigest::reach('li:hover > ul', ['conditions' => $conditions]));
    $this->assertSame(SelectorDigest::OWNER, SelectorDigest::reach(SelectorDigest::OWNER));
  }

  /**
   * The PHP side answers the shared battery exactly as recorded.
   */
  public function testSharedBattery(): void {
    $stdout = $this->runNodeScript('tests/js/selector-digest-cases.mjs', '');
    $battery = json_decode($stdout, TRUE, 512, JSON_THROW_ON_ERROR);
    $tables = $battery['tables'] ?? NULL;
    $this->assertIsArray($tables, 'The battery prints its tables.');
    $this->assertNotEmpty($battery['digests'] ?? [], 'The battery carries digest cases.');
    $this->assertNotEmpty($battery['reaches'] ?? [], 'The battery carries reach cases.');
    foreach ($battery['digests'] as $case) {
      $this->assertSame($case['answer'], SelectorDigest::digest($case['text'], $tables[$case['table'] ?? 'default']), $case['name']);
    }
    foreach ($battery['reaches'] as $case) {
      $this->assertSame($case['answer'], SelectorDigest::reach($case['selector'], $tables[$case['table'] ?? 'default']), $case['name']);
    }
  }

}
