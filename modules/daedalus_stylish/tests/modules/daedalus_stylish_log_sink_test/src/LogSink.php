<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_log_sink_test;

use Drupal\Core\Logger\RfcLoggerTrait;
use Psr\Log\LoggerInterface;

/**
 * Keeps what the daedalus_stylish channel logged, across container rebuilds.
 *
 * Registered as a tagged logger by this test module's services.yml, so
 * every container built while the module is enabled carries one; the
 * entries live in a static array because the sync entries under test run
 * inside module and theme installs, which rebuild the container between
 * the test's call and the hook that logs.
 */
final class LogSink implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * The logged entries: [RfcLogLevel, rendered message] pairs, in order.
   *
   * @var array<int, array{0: int, 1: string}>
   */
  public static array $sink = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    if (($context['channel'] ?? NULL) === 'daedalus_stylish') {
      $placeholders = array_filter($context, static fn (string $key): bool => str_starts_with($key, '@'), ARRAY_FILTER_USE_KEY);
      self::$sink[] = [$level, strtr((string) $message, array_map('strval', $placeholders))];
    }
  }

}
