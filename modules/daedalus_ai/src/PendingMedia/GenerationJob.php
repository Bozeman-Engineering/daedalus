<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\PendingMedia;

/**
 * A pending image-generation job, keyed by the media entity's uuid.
 *
 * Identity-first, content-deferred: the media entity (uuid, alt, placeholder
 * file) already exists and every reference to it is final. The job records
 * only what is needed to fill the file behind that uuid later: the prompt,
 * the alt text, and the provider-shaped dimension configuration.
 */
final class GenerationJob {

  /**
   * Constructs a GenerationJob.
   *
   * @param string $mediaUuid
   *   The uuid of the minted media entity whose file the job will fill.
   * @param string $prompt
   *   The image generation prompt.
   * @param string $altText
   *   The alt text for the generated image.
   * @param array $configuration
   *   Provider configuration expressing the requested image dimensions, built
   *   by the active provider's image-dimension adapter. Opaque to everything
   *   but the provider. Empty to use the provider's default.
   */
  public function __construct(
    public readonly string $mediaUuid,
    public readonly string $prompt,
    public readonly string $altText,
    public readonly array $configuration = [],
  ) {}

  /**
   * Serializes the job for tempstore.
   *
   * @return array
   *   The job as an associative array.
   */
  public function toArray(): array {
    return [
      'media_uuid' => $this->mediaUuid,
      'prompt' => $this->prompt,
      'alt_text' => $this->altText,
      'configuration' => $this->configuration,
    ];
  }

  /**
   * Recreates a job from its serialized form.
   *
   * @param array $values
   *   An array produced by toArray().
   *
   * @return self
   *   The job.
   */
  public static function fromArray(array $values): self {
    return new self(
      mediaUuid: $values['media_uuid'],
      prompt: $values['prompt'],
      altText: $values['alt_text'],
      configuration: $values['configuration'] ?? [],
    );
  }

}
