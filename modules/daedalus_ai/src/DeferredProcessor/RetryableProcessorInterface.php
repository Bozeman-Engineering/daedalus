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
   * never changes) and return those media uuids. A failure comes from the
   * request, so it only names which job failed and why: what the job
   * generates from (prompt, alt text, provider configuration) is read from
   * the job the server stored, and a failure with no stored job is not
   * retried.
   *
   * @param array $failures
   *   Failure entries as the retry route read them: each has keys type,
   *   media_uuid, reason, target.
   *
   * @return string[]
   *   Media uuids whose jobs were re-registered for another attempt. Empty
   *   when retry is not possible.
   */
  public function buildRetryJobs(array $failures): array;

}
