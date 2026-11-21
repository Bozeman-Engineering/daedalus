<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\Materials;

use Drupal\daedalus_blueprint\Materials\EmitGate;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The emit gate, and its client twin.
 *
 * EmitGate judges text bound for a style element: a declaration value, a
 * condition's at-rule, a selector, a font family name. The client twin
 * (daedalus_ui/js/edit_mode/materials/emit-gate.js) is locked to the class
 * by the shared battery daedalus_ui/tests/js/emit-gate-cases.mjs: the node
 * suite answers every case through the twin, this test answers every case
 * through the class, and both compare to the one recorded answer, so the
 * two can never drift.
 */
#[Group('daedalus_blueprint')]
class EmitGateTest extends UnitTestCase {

  use NodeSuitesTrait;

  /**
   * The twin lives beside daedalus_ui's grammar twins, so node runs there.
   */
  protected static function nodeModuleRoot(): string {
    return dirname((string) (new \ReflectionClass(static::class))->getFileName(), 6) . '/daedalus_ui';
  }

  /**
   * The PHP side answers the shared battery exactly as recorded.
   */
  public function testSharedBattery(): void {
    $stdout = $this->runNodeScript('tests/js/emit-gate-cases.mjs', '');
    $cases = json_decode($stdout, TRUE, 512, JSON_THROW_ON_ERROR)['cases'] ?? [];
    $this->assertNotEmpty($cases, 'The battery carries cases.');
    foreach ($cases as $case) {
      $answer = $case['selector'] ? EmitGate::admitsSelector($case['text']) : EmitGate::admits($case['text']);
      $this->assertSame($case['answer'], $answer, $case['name']);
    }
  }

}
