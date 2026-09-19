<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Unit;

use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Executes the pure-JS node suites so they have PHPUnit backpressure.
 *
 * Daedalus Stylish's client models (the island engine, the element/page/variant
 * models, keyboard stitching, the inspector's projections, the printer's
 * pure halves) are locked by node:test suites under tests/js/. This test
 * executes every one (NodeSuitesTrait globs them, so a new suite is
 * registered by existing) and fails on a non-zero exit. The two stdin
 * mirrors (print-cases, media-cases) are not suites: PrinterEquivalenceTest
 * and MediaClientMirrorTest feed them a PHP-printed battery and compare.
 */
#[Group('daedalus_stylish')]
final class JsSuitesTest extends UnitTestCase {

  use NodeSuitesTrait;

  /**
   * Each node:test suite passes on its own.
   */
  #[DataProvider('nodeSuiteProvider')]
  public function testSuite(string $script): void {
    $this->runNodeSuite($script);
  }

}
