<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\Materials;

use Drupal\daedalus_blueprint\Materials\ConditionClause;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The condition clause grammar, and its client twin.
 *
 * ConditionClause reads a condition key as clauses: references to the published
 * conditions, literal feature queries, supports tests and pseudo-classes,
 * unknown words; it derives the band and preference the canvas poses from a
 * clause's CSS, and spells a compound canonically. The client twin
 * (daedalus_ui/js/edit_mode/materials/condition-clause.js) is locked to the
 * class by the shared battery daedalus_ui/tests/js/condition-clause-cases.mjs:
 * the node suite answers every case through the twin, this test answers every
 * case through the class, and both compare to the one recorded answer, so the
 * two can never drift.
 */
#[Group('daedalus_blueprint')]
class ConditionClauseTest extends UnitTestCase {

  use NodeSuitesTrait;

  /**
   * The twin lives beside daedalus_ui's grammar twins, so node runs there.
   */
  protected static function nodeModuleRoot(): string {
    return dirname((string) (new \ReflectionClass(static::class))->getFileName(), 6) . '/daedalus_ui';
  }

  /**
   * The record's shape, read off the class.
   */
  public function testShape(): void {
    $conditions = [
      'hover' => ['wrapper' => ':hover', 'axis' => 'state'],
      'narrow' => ['wrapper' => '@container (max-width: 767px)', 'axis' => 'width'],
      'dark' => ['wrapper' => '@media (prefers-color-scheme: dark)', 'axis' => 'scheme'],
    ];
    $this->assertSame([
      'kind' => ConditionClause::REFERENCE,
      'text' => 'narrow',
      'form' => ConditionClause::CONTAINER,
      'axis' => 'width',
      'css' => '@container (max-width: 767px)',
      'band' => ['max' => 767],
      'preference' => NULL,
    ], ConditionClause::clause('narrow', $conditions));
    $this->assertSame([
      'kind' => ConditionClause::LITERAL,
      'text' => '@media (hover: hover)',
      'form' => ConditionClause::MEDIA,
      'axis' => 'hover',
      'css' => '@media (hover: hover)',
      'band' => NULL,
      'preference' => NULL,
    ], ConditionClause::clause('@media (HOVER: hover)', $conditions));
    $this->assertSame(ConditionClause::UNKNOWN, ConditionClause::clause('bogus', $conditions)['kind']);
    $this->assertSame(['dark', 'narrow'], ConditionClause::split('dark+narrow'), 'split keeps spelled order');
    $this->assertSame('hover+narrow+dark', ConditionClause::canonical('dark+narrow+hover', $conditions));
    $this->assertSame(['state', 'width', 'scheme'], ConditionClause::axisOrder($conditions));
    $this->assertSame(['feature' => 'prefers-color-scheme', 'value' => 'dark'], ConditionClause::preference('@media (prefers-color-scheme: dark)'));
    $this->assertNull(ConditionClause::band('@media (max-width: 767px)'), 'A viewport band is not posable.');
    $this->assertSame(ConditionClause::PSEUDO, ConditionClause::form(':hover'));
    $this->assertNull(ConditionClause::form('::before'));
  }

  /**
   * The PHP side answers the shared battery exactly as recorded.
   */
  public function testSharedBattery(): void {
    $stdout = $this->runNodeScript('tests/js/condition-clause-cases.mjs', '');
    $battery = json_decode($stdout, TRUE, 512, JSON_THROW_ON_ERROR);
    $tables = $battery['tables'] ?? NULL;
    $this->assertIsArray($tables, 'The battery prints its tables.');
    foreach (['splits', 'clauses', 'canonicals', 'axes', 'poses'] as $section) {
      $this->assertNotEmpty($battery[$section] ?? [], "The battery carries $section cases.");
    }
    $table = static fn (array $case): array => $tables[$case['table'] ?? 'default']['conditions'];
    foreach ($battery['splits'] as $case) {
      $this->assertSame($case['answer'], ConditionClause::split($case['text']), $case['name']);
    }
    foreach ($battery['clauses'] as $case) {
      $this->assertSame($case['answer'], ConditionClause::clause($case['text'], $table($case)), $case['name']);
    }
    foreach ($battery['canonicals'] as $case) {
      $this->assertSame($case['answer'], ConditionClause::canonical($case['text'], $table($case)), $case['name']);
      $this->assertSame($case['answer'], ConditionClause::canonical($case['answer'], $table($case)), $case['name'] . ' (idempotent)');
    }
    foreach ($battery['axes'] as $case) {
      $this->assertSame($case['answer'], ConditionClause::axisOrder($table($case)), $case['name']);
    }
    foreach ($battery['poses'] as $case) {
      $clause = ConditionClause::clause($case['text'], $table($case));
      $this->assertSame($case['answer'], [
        'posable' => ConditionClause::posable($clause),
        'stamp' => ConditionClause::stamp($clause),
        'features' => ConditionClause::features($clause['css'] ?? $clause['text']),
      ], $case['name']);
    }
  }

}
