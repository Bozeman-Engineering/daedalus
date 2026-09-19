<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\DeferredProcessor;

/**
 * Optional interface for processors that support retry from failures.
 */
interface RetryableProcessorInterface {

  /**
   * Rebuilds jobs from failures and returns the media uuids to re-process.
   *
   * Implementations re-register a job for each failure they can retry (e.g.
   * with a rewritten prompt, keyed by the same media uuid; the identity
   * never changes) and return those media uuids.
   *
   * @param array $failures
   *   Failure entries as returned by the controller: each has keys
   *   media_uuid, reason, retryable, data, type, target.
   *
   * @return string[]
   *   Media uuids whose jobs were re-registered for another attempt. Empty
   *   when retry is not possible.
   */
  public function buildRetryJobs(array $failures): array;

}
