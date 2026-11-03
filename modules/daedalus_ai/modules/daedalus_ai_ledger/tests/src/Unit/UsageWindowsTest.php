<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_ledger\Unit;

use Drupal\daedalus_ai_ledger\UsageWindows;
use Drupal\daedalus_ai_ledger\Window;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the window math the gate rests on.
 *
 * What a rolling window holds (the legs younger than its period, an
 * unpriced leg weighing nothing) and when a full one clears (the moment
 * the oldest legs whose removal brings the sum under the cap have aged
 * out); what an anchored window holds (the legs since its latest
 * boundary) and that it clears at the next.
 */
#[Group('daedalus_ai_ledger')]
class UsageWindowsTest extends UnitTestCase {

  private const NOW = 1_000_000;

  /**
   * A rolling user window of so many seconds in UTC.
   */
  private static function rolling(string $period, float $usd): Window {
    return new Window(Window::SCOPE_USER, $period, $usd, NULL, new \DateTimeZone('UTC'));
  }

  /**
   * A window holds the legs younger than its period, nothing older.
   */
  public function testSpentCountsOnlyTheWindow(): void {
    $window = self::rolling('PT1H', 10.0);
    $legs = [
      ['created' => self::NOW - 3601, 'cost' => 100.0],
      ['created' => self::NOW - 3600, 'cost' => 100.0],
      ['created' => self::NOW - 3599, 'cost' => 1.5],
      ['created' => self::NOW - 10, 'cost' => 2.25],
      ['created' => self::NOW, 'cost' => 0.25],
    ];

    $this->assertSame(4.0, UsageWindows::spent($legs, $window, self::NOW));
    $this->assertSame(0.0, UsageWindows::spent([], $window, self::NOW));
  }

  /**
   * An unpriced leg weighs nothing in a window.
   */
  public function testUnpricedLegWeighsNothing(): void {
    $legs = [
      ['created' => self::NOW - 1, 'cost' => NULL],
      ['created' => self::NOW - 2, 'cost' => 3.0],
    ];

    $this->assertSame(3.0, UsageWindows::spent($legs, self::rolling('PT1M', 1.0), self::NOW));
  }

  /**
   * The windows read in any order: the legs need no sorting.
   */
  public function testOrderDoesNotMatter(): void {
    $window = self::rolling('PT100S', 1.0);
    $legs = [
      ['created' => self::NOW - 5, 'cost' => 1.0],
      ['created' => self::NOW - 50, 'cost' => 1.0],
      ['created' => self::NOW - 20, 'cost' => 1.0],
    ];
    $reversed = array_reverse($legs);

    $this->assertSame(3.0, UsageWindows::spent($reversed, $window, self::NOW));
    $this->assertSame(UsageWindows::resetAt($legs, $window, self::NOW), UsageWindows::resetAt($reversed, $window, self::NOW));
  }

  /**
   * A window under its cap has no reset.
   */
  public function testNoResetUnderCap(): void {
    $legs = [['created' => self::NOW - 1, 'cost' => 0.5]];

    $this->assertNull(UsageWindows::resetAt($legs, self::rolling('PT1M', 1.0), self::NOW));
  }

  /**
   * A full window clears when enough of its oldest legs have aged out.
   *
   * Three legs of 1.0 against a cap of 2.5: the sum is 3.0, dropping the
   * oldest leaves 2.0 under the cap, so the window clears when that
   * oldest leg leaves it.
   */
  public function testResetWhenOldestLegsAgeOut(): void {
    $window = self::rolling('PT1H', 2.5);
    $legs = [
      ['created' => self::NOW - 3000, 'cost' => 1.0],
      ['created' => self::NOW - 2000, 'cost' => 1.0],
      ['created' => self::NOW - 1000, 'cost' => 1.0],
    ];

    $this->assertSame(self::NOW - 3000 + 3600, UsageWindows::resetAt($legs, $window, self::NOW));
  }

  /**
   * The reset walks as many legs as the cap needs; equality reaches the cap.
   *
   * Four legs of 1.0 against a cap of 2.0: dropping one leaves 3.0, two
   * leaves 2.0 (still at the cap), three leaves 1.0, so the window clears
   * when the third oldest leg leaves it.
   */
  public function testResetWalksPastEquality(): void {
    $window = self::rolling('PT10M', 2.0);
    $legs = [
      ['created' => self::NOW - 400, 'cost' => 1.0],
      ['created' => self::NOW - 300, 'cost' => 1.0],
      ['created' => self::NOW - 200, 'cost' => 1.0],
      ['created' => self::NOW - 100, 'cost' => 1.0],
    ];

    $this->assertTrue($window->reached(2.0), 'A sum at the cap has reached it.');
    $this->assertFalse($window->reached(1.999));
    $this->assertSame(self::NOW - 200 + 600, UsageWindows::resetAt($legs, $window, self::NOW));
  }

  /**
   * A cap of zero is reached by nothing at all and never clears by time.
   */
  public function testZeroCapNeverClears(): void {
    $window = self::rolling('PT1M', 0.0);

    $this->assertTrue($window->reached(0.0));
    $this->assertNull(UsageWindows::resetAt([['created' => self::NOW - 1, 'cost' => 1.0]], $window, self::NOW));
    $this->assertNull(UsageWindows::resetAt([], $window, self::NOW));

    $anchored = Window::fromRow(['scope' => 'site', 'period' => 'P1D', 'usd' => 0.0, 'anchor' => '1970-01-01'], new \DateTimeZone('UTC'));
    $this->assertNull(UsageWindows::resetAt([], $anchored, self::NOW), 'An anchored window at a cap of zero is full again at every boundary.');
  }

  /**
   * The oldest weighing leg inside a window is where the meter next rises.
   *
   * None inside answers NULL; one answers its time; of several, the
   * oldest, whatever the order they come in; a leg outside the window, an
   * unpriced leg and a leg priced at zero are passed over, since none of
   * them changes the sum when it leaves.
   */
  public function testOldestInside(): void {
    $window = self::rolling('PT1H', 10.0);

    $this->assertNull(UsageWindows::oldestInside([], $window, self::NOW), 'Nothing inside, no moment.');
    $this->assertNull(UsageWindows::oldestInside([['created' => self::NOW - 3600, 'cost' => 1.0]], $window, self::NOW), 'A leg exactly as old as the period has aged out.');
    $this->assertSame(self::NOW - 10, UsageWindows::oldestInside([['created' => self::NOW - 10, 'cost' => 1.0]], $window, self::NOW));

    $legs = [
      ['created' => self::NOW - 10, 'cost' => 1.0],
      ['created' => self::NOW - 3599, 'cost' => NULL],
      ['created' => self::NOW - 3000, 'cost' => 0.0],
      ['created' => self::NOW - 2000, 'cost' => 0.5],
      ['created' => self::NOW - 4000, 'cost' => 1.0],
      ['created' => self::NOW - 1000, 'cost' => 1.0],
    ];
    $this->assertSame(self::NOW - 2000, UsageWindows::oldestInside($legs, $window, self::NOW), 'The oldest leg that weighs something inside the window.');
    $this->assertSame(self::NOW - 2000, UsageWindows::oldestInside(array_reverse($legs), $window, self::NOW), 'Whatever the order.');
    $this->assertSame(self::NOW - 2000 + 3600, $window->agedOutAt(UsageWindows::oldestInside($legs, $window, self::NOW)), 'The meter rises when that leg leaves.');
  }

  /**
   * An anchored window holds the legs since its boundary, clears at the next.
   *
   * A daily window anchored at the epoch in UTC: judged at NOW
   * (1,000,000 = day 11 at 13:46:40) its boundary is 950,400 (day 11,
   * midnight) and the next is 1,036,800. A leg before the boundary is
   * outside, one at the boundary moment is inside, and a full window
   * resets at the next boundary whatever the legs' ages.
   */
  public function testAnchoredWindow(): void {
    $window = Window::fromRow(['scope' => 'site', 'period' => 'P1D', 'usd' => 2.0, 'anchor' => '1970-01-01'], new \DateTimeZone('UTC'));
    $boundary = 950_400;
    $this->assertSame($boundary, $window->since(self::NOW));
    $legs = [
      ['created' => $boundary - 1, 'cost' => 100.0],
      ['created' => $boundary, 'cost' => 1.0],
      ['created' => self::NOW - 10, 'cost' => 0.5],
    ];

    $this->assertSame(1.5, UsageWindows::spent($legs, $window, self::NOW), 'The leg before the boundary is outside; the one at the boundary is inside.');
    $this->assertNull(UsageWindows::resetAt($legs, $window, self::NOW));

    $legs[] = ['created' => self::NOW, 'cost' => 0.5];
    $this->assertSame(2.0, UsageWindows::spent($legs, $window, self::NOW));
    $this->assertSame(1_036_800, UsageWindows::resetAt($legs, $window, self::NOW), 'A full anchored window clears at the next boundary.');
  }

}
