<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Unit;

use Drupal\daedalus_stylish\Selector\RuleMove;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The rule move over an owner's keys, and its client twin.
 *
 * RuleMove plans what rekeying a rule's selector does to the rule and
 * its family (the keys continuing it): the mapping, or a refusal naming
 * the first offending key. The client twin (js/src/rule-move.js) is
 * locked to the class by the shared battery tests/js/rule-move-cases.mjs:
 * the node suite answers every case through the twin, this test answers
 * every case through RuleMove::plan(), and both compare to the one
 * recorded answer, so the two can never drift.
 */
#[Group('daedalus_stylish')]
class RuleMoveTest extends UnitTestCase {

  use NodeSuitesTrait;

  /**
   * The plan's shape: the mapping or the refusal, read off the object.
   */
  public function testPlan(): void {
    $planned = RuleMove::plan(['> d-text', '> d-text .link', '> d-text::before', 'p'], '> d-text', '> d-text:has(a)');
    $this->assertFalse($planned->refused());
    $this->assertNull($planned->refusal);
    $this->assertSame([
      '> d-text' => '> d-text:has(a)',
      '> d-text .link' => '> d-text:has(a) .link',
      '> d-text::before' => '> d-text:has(a)::before',
    ], $planned->moves);

    $refused = RuleMove::plan(['> d-text', '> d-text .link'], '> d-text', '> d-text::after');
    $this->assertTrue($refused->refused());
    $this->assertNull($refused->moves);
    $this->assertSame(['reason' => RuleMove::REFUSED_CONTINUATION, 'key' => '> d-text .link'], $refused->refusal);
    $this->assertSame(['moves' => NULL, 'refusal' => ['reason' => 'continuation', 'key' => '> d-text .link']], $refused->toArray());

    $this->assertSame(RuleMove::REFUSED_GRAMMAR, RuleMove::plan([], '> d-text', 'p:has(:has(a))')->refusal['reason']);
    $this->assertSame(RuleMove::REFUSED_TARGET_EXISTS, RuleMove::plan(['> d-image'], '> d-text', '> d-image')->refusal['reason']);

    $this->expectException(\InvalidArgumentException::class);
    RuleMove::plan([], 'p:has(:has(a))', 'p');
  }

  /**
   * The PHP side answers the shared battery exactly as recorded.
   */
  public function testSharedBattery(): void {
    $stdout = $this->runNodeScript('tests/js/rule-move-cases.mjs', '');
    $cases = json_decode($stdout, TRUE, 512, JSON_THROW_ON_ERROR)['cases'] ?? NULL;
    $this->assertIsArray($cases, 'The battery prints its cases.');
    $this->assertNotEmpty($cases, 'The battery carries cases.');
    foreach ($cases as $case) {
      try {
        $answer = RuleMove::plan($case['keys'], $case['from'], $case['to'])->toArray();
      }
      catch (\InvalidArgumentException) {
        $answer = ['error' => TRUE];
      }
      $this->assertSame($case['answer'], $answer, $case['name']);
    }
  }

}
