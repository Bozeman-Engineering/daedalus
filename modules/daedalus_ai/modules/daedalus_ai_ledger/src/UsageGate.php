<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Allows or refuses a turn against the configured spend windows.
 *
 * Checked by the runner before any model call. The windows are config
 * (`caps`, each {scope, period, usd, anchor}; none means unlimited),
 * summed from the ledger's priced rows: a site window over every row on
 * the site, a user window over the account's own. Site windows are
 * checked first, in config order, then user windows; the first at its
 * cap refuses the turn with a message naming the window and when it
 * clears. `bypass ai usage caps` skips the user windows only: a site
 * window refuses everyone, and raising it is the operator's act through
 * config, since the site's own administrators are who the budget is for.
 * windowsFor() is the one definition of which windows apply to an
 * account; check() judges by them and status() reads the same sums for
 * display (the chat's meter) as the fraction of each window left and when
 * that changes, never in USD, so a bypassing account is refused by a site
 * window and sees it on the meter alike.
 */
final class UsageGate {

  use StringTranslationTrait;

  public const BYPASS_PERMISSION = 'bypass ai usage caps';

  public const CAPS_KEY = 'caps';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UsageLedger $ledger,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * The windows the caps config describes.
   *
   * @return list<\Drupal\daedalus_ai_ledger\Window>
   *   The windows, in config order.
   */
  public function windows(): array {
    return Window::fromConfig($this->configFactory->get(CostResolver::SETTINGS)->get(self::CAPS_KEY), $this->siteTimezone());
  }

  /**
   * The windows that apply to an account.
   *
   * The one definition check() and status() share, and what any surface
   * showing the meter decides by: the site windows in config order, then
   * the user windows unless the account holds the bypass permission. A
   * site window applies to everyone; the bypass is only that account's
   * way past the user windows. Empty when no window applies.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   *
   * @return list<\Drupal\daedalus_ai_ledger\Window>
   *   The windows that apply, site windows first.
   */
  public function windowsFor(AccountInterface $account): array {
    $windows = $this->windows();
    return [
      ...self::ofScope($windows, Window::SCOPE_SITE),
      ...($account->hasPermission(self::BYPASS_PERMISSION) ? [] : self::ofScope($windows, Window::SCOPE_USER)),
    ];
  }

  /**
   * The timezone the windows run in: the site's default.
   */
  public function siteTimezone(): \DateTimeZone {
    $name = $this->configFactory->get('system.date')->get('timezone.default');
    return new \DateTimeZone(is_string($name) && $name !== '' ? $name : date_default_timezone_get());
  }

  /**
   * Whether an account may run a turn now.
   *
   * One query per scope that has windows: the site's legs back to the
   * earliest site window's start, the account's likewise.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the turn runs as.
   * @param int|null $now
   *   The moment to judge at; the request time when NULL.
   */
  public function check(AccountInterface $account, ?int $now = NULL): GateVerdict {
    $windows = $this->windowsFor($account);
    if ($windows === []) {
      return GateVerdict::allow();
    }
    $now ??= $this->time->getRequestTime();
    foreach ($this->scoped($account, $windows, $now) as [$set, $legs]) {
      foreach ($set as $window) {
        $spent = UsageWindows::spent($legs, $window, $now);
        if (!$window->reached($spent)) {
          continue;
        }
        $reset_at = UsageWindows::resetAt($legs, $window, $now);
        return GateVerdict::refuse($window, $spent, $reset_at, $this->refusalMessage($window, $reset_at));
      }
    }
    return GateVerdict::allow();
  }

  /**
   * Every window as the account sees it, for display.
   *
   * The same sums check() judges by, read as fractions over the windows
   * windowsFor() answers: site windows first in config order, then the
   * user's unless the account holds the bypass permission. Empty when no
   * window applies (budgeting and everything that shows it are off until
   * an administrator configures a cap; a bypassing account over user
   * windows alone has nothing to see, but a site window is theirs to see
   * as it is theirs to be refused by). No USD figure is carried.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account the meter is for.
   * @param int|null $now
   *   The moment to judge at; the request time when NULL.
   *
   * @return list<\Drupal\daedalus_ai_ledger\WindowStatus>
   *   One status per window, site windows first.
   */
  public function status(AccountInterface $account, ?int $now = NULL): array {
    $windows = $this->windowsFor($account);
    if ($windows === []) {
      return [];
    }
    $now ??= $this->time->getRequestTime();
    $statuses = [];
    foreach ($this->scoped($account, $windows, $now) as [$set, $legs]) {
      foreach ($set as $window) {
        $spent = UsageWindows::spent($legs, $window, $now);
        $statuses[] = WindowStatus::of($window, $spent, self::changesAt($legs, $window, $now, $window->reached($spent)));
      }
    }
    return $statuses;
  }

  /**
   * The windows of each scope with the legs they read, site scope first.
   *
   * One ledger query per scope that has windows: the site's legs back to
   * the earliest site window's start, the account's likewise. Which
   * windows apply is windowsFor()'s answer; this only groups them.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param list<\Drupal\daedalus_ai_ledger\Window> $windows
   *   The windows that apply to the account (windowsFor()).
   * @param int $now
   *   The moment the windows end.
   *
   * @return list<array{list<\Drupal\daedalus_ai_ledger\Window>, list<array{created: int, cost: float|null}>}>
   *   Per scope with windows: its windows in config order and the legs
   *   they sum.
   */
  private function scoped(AccountInterface $account, array $windows, int $now): array {
    $by_scope = [
      Window::SCOPE_SITE => self::ofScope($windows, Window::SCOPE_SITE),
      Window::SCOPE_USER => self::ofScope($windows, Window::SCOPE_USER),
    ];
    $scoped = [];
    foreach ($by_scope as $scope => $set) {
      if ($set === []) {
        continue;
      }
      // An anchored window holds the boundary moment itself, and the
      // ledger's read is exclusive, so the read starts a second earlier.
      $since = min(array_map(static fn (Window $window): int => $window->since($now), $set)) - 1;
      $legs = $scope === Window::SCOPE_SITE
        ? $this->ledger->legsForSite($since)
        : $this->ledger->legsForUser((int) $account->id(), $since);
      $scoped[] = [$set, $legs];
    }
    return $scoped;
  }

  /**
   * The next moment a window's remaining fraction changes.
   *
   * An anchored window clears whole at its next boundary, reached or not.
   * A reached rolling window changes when it clears
   * (UsageWindows::resetAt()); an open one when its oldest weighing leg
   * ages out, NULL when none is inside.
   *
   * @param list<array{created: int, cost: float|null}> $legs
   *   The legs the window reads.
   * @param \Drupal\daedalus_ai_ledger\Window $window
   *   The window.
   * @param int $now
   *   The moment the window ends.
   * @param bool $reached
   *   Whether the window's cap is reached.
   */
  private static function changesAt(array $legs, Window $window, int $now, bool $reached): ?int {
    if (!$window->rolling()) {
      return $window->nextBoundary($now);
    }
    if ($reached) {
      return UsageWindows::resetAt($legs, $window, $now);
    }
    $oldest = UsageWindows::oldestInside($legs, $window, $now);
    return $oldest === NULL ? NULL : $window->agedOutAt($oldest);
  }

  /**
   * The windows of one scope, in config order.
   *
   * @param list<\Drupal\daedalus_ai_ledger\Window> $windows
   *   Every window.
   * @param string $scope
   *   The scope to keep.
   *
   * @return list<\Drupal\daedalus_ai_ledger\Window>
   *   The windows of that scope.
   */
  private static function ofScope(array $windows, string $scope): array {
    return array_values(array_filter($windows, static fn (Window $window): bool => $window->scope === $scope));
  }

  /**
   * The sentence a refusal answers with.
   */
  private function refusalMessage(Window $window, ?int $reset_at): string {
    $label = $window->label($this->dateFormatter);
    $reset = $reset_at === NULL ? NULL : $this->dateFormatter->format($reset_at, 'short');
    if ($window->scope === Window::SCOPE_SITE) {
      if ($reset === NULL) {
        return (string) $this->t('This site has reached its AI usage limit for @label.', ['@label' => $label]);
      }
      return (string) $this->t('This site has reached its AI usage limit for @label. It resets at @reset.', [
        '@label' => $label,
        '@reset' => $reset,
      ]);
    }
    if ($reset === NULL) {
      return (string) $this->t('You have reached the AI usage limit for the last @window.', ['@window' => $label]);
    }
    return (string) $this->t('You have reached the AI usage limit for the last @window. It resets at @reset.', [
      '@window' => $label,
      '@reset' => $reset,
    ]);
  }

}
