<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Unit;

use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Executes the pure-JS node suites so they have PHPUnit backpressure.
 *
 * The page-elements projection, markup composition, the drag and gap
 * models and the rest of the client's pure functions are locked by
 * node:test suites under tests/js/. Those suites only count if something
 * runs them: this test executes every one (NodeSuitesTrait globs them, so a
 * new suite is registered by existing) and fails on a non-zero exit, so a
 * vocabulary drift in the `_element` annotation or a composition regression
 * fails the module's PHPUnit run, not just a manual `node --test`.
 */
#[Group('daedalus_ui')]
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
