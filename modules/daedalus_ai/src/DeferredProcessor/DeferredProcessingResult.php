<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\DeferredProcessor;

/**
 * Result of processing one pending generation job.
 */
class DeferredProcessingResult {

  public function __construct(
    public readonly bool $success,
    public readonly string $mediaUuid,
    public readonly array $data = [],
    public readonly ?string $reason = NULL,
    public readonly bool $retryable = FALSE,
  ) {}

  /**
   * Creates a successful result.
   *
   * @param string $media_uuid
   *   The uuid of the media entity whose content was filled.
   * @param array $data
   *   Arbitrary data for downstream consumers.
   *
   * @return static
   */
  public static function success(string $media_uuid, array $data = []): static {
    return new static(
      success: TRUE,
      mediaUuid: $media_uuid,
      data: $data,
    );
  }

  /**
   * Creates a failed result.
   *
   * @param string $media_uuid
   *   The uuid of the media entity whose job failed.
   * @param string $reason
   *   Human-readable failure reason.
   * @param bool $retryable
   *   Whether the job can be retried with a modified prompt.
   * @param array $data
   *   Additional context for the retry path (e.g. the job's prompt, alt
   *   text, and provider configuration).
   *
   * @return static
   */
  public static function failure(string $media_uuid, string $reason, bool $retryable = FALSE, array $data = []): static {
    return new static(
      success: FALSE,
      mediaUuid: $media_uuid,
      data: $data,
      reason: $reason,
      retryable: $retryable,
    );
  }

}
