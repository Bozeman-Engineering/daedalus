<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai_ledger\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\daedalus_ai_ledger\Window;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins a window's grammar and its calendar.
 *
 * What the caps config may say (scope, period, usd, anchor, each refused
 * by name when wrong), where a window opens judged at a moment (a rolling
 * window the period back by real calendar arithmetic, an anchored one at
 * the latest boundary stepping from the anchor), when an anchored one
 * next resets, the longest span either can cover, and the period in
 * words.
 */
#[Group('daedalus_ai_ledger')]
class WindowTest extends UnitTestCase {

  /**
   * The timezone the cases run in: one with DST, so a day is a calendar day.
   */
  private const TZ = 'America/New_York';

  /**
   * A moment in the timezone as a timestamp.
   */
  private static function at(string $datetime): int {
    return (new \DateTimeImmutable($datetime, new \DateTimeZone(self::TZ)))->getTimestamp();
  }

  /**
   * A window in the test timezone.
   */
  private static function window(string $period, ?string $anchor = NULL, string $scope = Window::SCOPE_USER, float $usd = 10.0): Window {
    return Window::fromRow(['scope' => $scope, 'period' => $period, 'usd' => $usd, 'anchor' => $anchor ?? ''], new \DateTimeZone(self::TZ));
  }

  /**
   * The config's four keys read into a window and back.
   */
  public function testFromConfigRoundTrip(): void {
    $tz = new \DateTimeZone(self::TZ);
    $this->assertSame([], Window::fromConfig([], $tz));
    $this->assertSame([], Window::fromConfig(NULL, $tz));

    $rows = [
      ['scope' => 'site', 'period' => 'P1M', 'usd' => 20, 'anchor' => '2026-01-01'],
      ['scope' => 'user', 'period' => 'PT5H', 'usd' => '2.5', 'anchor' => ''],
      ['scope' => 'user', 'period' => 'P7D', 'usd' => 5.0],
    ];
    $windows = Window::fromConfig($rows, $tz);
    $this->assertCount(3, $windows);

    $this->assertSame(Window::SCOPE_SITE, $windows[0]->scope);
    $this->assertSame('P1M', $windows[0]->periodSpec);
    $this->assertSame(1, $windows[0]->period->m);
    $this->assertSame(20.0, $windows[0]->usd);
    $this->assertFalse($windows[0]->rolling());
    $this->assertSame(self::at('2026-01-01 00:00:00'), $windows[0]->anchor->getTimestamp(), 'The anchor is midnight in the site timezone.');
    $this->assertSame(['scope' => 'site', 'period' => 'P1M', 'usd' => 20.0, 'anchor' => '2026-01-01'], $windows[0]->toArray());

    $this->assertSame(Window::SCOPE_USER, $windows[1]->scope);
    $this->assertSame(5, $windows[1]->period->h);
    $this->assertSame(2.5, $windows[1]->usd);
    $this->assertTrue($windows[1]->rolling());
    $this->assertNull($windows[1]->anchor);
    $this->assertSame(['scope' => 'user', 'period' => 'PT5H', 'usd' => 2.5, 'anchor' => ''], $windows[1]->toArray());

    $this->assertTrue($windows[2]->rolling(), 'An absent anchor rolls.');
    $this->assertSame('', $windows[2]->toArray()['anchor']);
  }

  /**
   * A malformed row refuses naming the field.
   */
  #[DataProvider('badRows')]
  public function testFromConfigRefusesNamingTheField(mixed $row, string $named): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage($named);
    Window::fromConfig([$row], new \DateTimeZone(self::TZ));
  }

  /**
   * The malformed rows and the word the message must carry.
   */
  public static function badRows(): iterable {
    yield 'scope missing' => [['period' => 'PT5H', 'usd' => 1.0], 'scope'];
    yield 'scope unknown' => [['scope' => 'role', 'period' => 'PT5H', 'usd' => 1.0], 'scope'];
    yield 'period missing' => [['scope' => 'user', 'usd' => 1.0], 'period'];
    yield 'period not a duration' => [['scope' => 'user', 'period' => '5 hours', 'usd' => 1.0], 'period'];
    yield 'period zero' => [['scope' => 'user', 'period' => 'PT0S', 'usd' => 1.0], 'period'];
    yield 'usd missing' => [['scope' => 'user', 'period' => 'PT5H'], 'usd'];
    yield 'usd not a number' => [['scope' => 'user', 'period' => 'PT5H', 'usd' => 'ten'], 'usd'];
    yield 'usd negative' => [['scope' => 'user', 'period' => 'PT5H', 'usd' => -1], 'usd'];
    yield 'anchor not a date' => [['scope' => 'user', 'period' => 'P1M', 'usd' => 1.0, 'anchor' => 'first'], 'anchor'];
    yield 'anchor overflowing' => [
      ['scope' => 'user', 'period' => 'P1M', 'usd' => 1.0, 'anchor' => '2026-02-30'],
      'anchor',
    ];
    yield 'anchor wrong format' => [
      ['scope' => 'user', 'period' => 'P1M', 'usd' => 1.0, 'anchor' => '01/02/2026'],
      'anchor',
    ];
    yield 'row not a mapping' => ['not a row', 'mapping'];
  }

  /**
   * A rolling window opens the period back, by calendar arithmetic.
   */
  public function testRollingSince(): void {
    $now = self::at('2026-03-15 12:00:00');

    $this->assertSame($now - 5 * 3600, self::window('PT5H')->since($now));
    $this->assertSame(self::at('2026-02-15 12:00:00'), self::window('P1M')->since($now), 'A month back from mid-March is mid-February: 28 days, a real month.');
    $this->assertSame(self::at('2026-03-08 12:00:00'), self::window('P7D')->since($now));
    // DST began 2026-03-08 02:00 in New York: a day back from that noon
    // is a calendar day, 23 hours long.
    $this->assertSame(self::at('2026-03-08 12:00:00') - 23 * 3600, self::window('P1D')->since(self::at('2026-03-08 12:00:00')));

    $this->assertNull(self::window('PT5H')->nextBoundary($now), 'A rolling window has no boundary.');
    $this->assertSame($now + 5 * 3600, self::window('PT5H')->agedOutAt($now));
    $this->assertSame(self::at('2026-04-15 12:00:00'), self::window('P1M')->agedOutAt($now), 'A leg ages out a real month after it.');
  }

  /**
   * A monthly window anchored on the 1st opens on the latest 1st.
   */
  public function testAnchoredMonth(): void {
    $window = self::window('P1M', '2026-01-01');

    $now = self::at('2026-03-15 12:00:00');
    $this->assertSame(self::at('2026-03-01 00:00:00'), $window->since($now));
    $this->assertSame(self::at('2026-04-01 00:00:00'), $window->nextBoundary($now));

    // Years past the anchor still land on the 1st: the boundary is the
    // anchor plus whole months, not a running sum of steps.
    $later = self::at('2031-07-20 08:00:00');
    $this->assertSame(self::at('2031-07-01 00:00:00'), $window->since($later));
    $this->assertSame(self::at('2031-08-01 00:00:00'), $window->nextBoundary($later));

    // At the boundary moment itself the new period has begun.
    $boundary = self::at('2026-04-01 00:00:00');
    $this->assertSame($boundary, $window->since($boundary));
    $this->assertSame(self::at('2026-05-01 00:00:00'), $window->nextBoundary($boundary));
    $this->assertSame(self::at('2026-03-01 00:00:00'), $window->since($boundary - 1), 'A second before the boundary is still the previous period.');

    // A billing day later in the month.
    $window = self::window('P1M', '2026-01-15');
    $this->assertSame(self::at('2026-02-15 00:00:00'), $window->since(self::at('2026-03-14 23:59:59')));
    $this->assertSame(self::at('2026-03-15 00:00:00'), $window->since(self::at('2026-03-15 00:00:00')));
  }

  /**
   * A weekly window anchored on a Monday opens on the latest Monday.
   */
  public function testAnchoredWeek(): void {
    $window = self::window('P7D', '2026-01-05');
    $now = self::at('2026-01-20 09:30:00');

    $this->assertSame(self::at('2026-01-19 00:00:00'), $window->since($now));
    $this->assertSame(self::at('2026-01-26 00:00:00'), $window->nextBoundary($now));

    // Across the DST change the boundary stays at midnight.
    $spring = self::at('2026-03-12 09:30:00');
    $this->assertSame(self::at('2026-03-09 00:00:00'), $window->since($spring));
    $this->assertSame(self::at('2026-03-16 00:00:00'), $window->nextBoundary($spring));
  }

  /**
   * An hourly window anchored to a date steps through the day.
   */
  public function testAnchoredHours(): void {
    $window = self::window('PT5H', '2026-01-01');
    $now = self::at('2026-01-02 13:00:00');

    // Boundaries at 00:00, 05:00, 10:00, 15:00, 20:00 on the 1st, then
    // 01:00, 06:00, 11:00, 16:00 on the 2nd.
    $this->assertSame(self::at('2026-01-02 11:00:00'), $window->since($now));
    $this->assertSame(self::at('2026-01-02 16:00:00'), $window->nextBoundary($now));
  }

  /**
   * A future anchor is its own boundary: nothing is inside yet.
   */
  public function testFutureAnchor(): void {
    $window = self::window('P1M', '2026-06-01');
    $now = self::at('2026-03-15 12:00:00');

    $this->assertSame(self::at('2026-06-01 00:00:00'), $window->since($now));
    $this->assertSame(self::at('2026-06-01 00:00:00'), $window->nextBoundary($now));
    $this->expectException(\LogicException::class);
    $window->agedOutAt($now);
  }

  /**
   * The longest span a window can cover.
   */
  public function testMaxSeconds(): void {
    $this->assertSame(5 * 3600, self::window('PT5H')->maxSeconds());
    $this->assertSame(7 * 86400, self::window('P7D')->maxSeconds());
    $this->assertSame(31 * 86400, self::window('P1M')->maxSeconds(), 'A rolling month is at most 31 days.');
    $this->assertSame(366 * 86400, self::window('P1Y')->maxSeconds());
    $this->assertSame(86400 + 12 * 3600, self::window('P1DT12H')->maxSeconds());

    $this->assertSame(2 * 5 * 3600, self::window('PT5H', '2026-01-01')->maxSeconds(), 'An anchored window covers two periods.');
    $this->assertSame(2 * 7 * 86400, self::window('P7D', '2026-01-01')->maxSeconds());
    $this->assertSame(2 * 31 * 86400, self::window('P1M', '2026-01-01')->maxSeconds());
  }

  /**
   * The period in words through the date formatter.
   */
  public function testLabel(): void {
    $formatter = $this->createMock(DateFormatterInterface::class);
    $formatter->method('formatInterval')->willReturnCallback(static fn (int $seconds, int $granularity): string => "$seconds/$granularity");

    $this->assertSame('18000/2', self::window('PT5H')->label($formatter), 'Five hours at two units.');
    $this->assertSame('604800/2', self::window('P7D')->label($formatter), 'Seven days at two units.');
    $this->assertSame('2592000/1', self::window('P1M')->label($formatter), 'A month is the formatter\'s month unit alone.');
    $this->assertSame('7776000/1', self::window('P3M')->label($formatter));
    $this->assertSame('129600/2', self::window('P1DT12H')->label($formatter));
    $this->assertSame('2592000/1', self::window('P1M', '2026-01-01')->label($formatter), 'An anchor does not change the words.');
  }

}
