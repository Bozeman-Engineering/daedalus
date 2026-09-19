<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\ImageDimension;

/**
 * Default adapter: providers that size an image by aspect ratio.
 *
 * Covers Grok, Imagen, and any other text_to_image provider whose API accepts
 * an "aspect_ratio" configuration key. Registered at the lowest priority with a
 * catch-all supports(), so it also handles providers that have no dedicated
 * adapter (a ratio is the most portable assumption). A provider that names or
 * sizes its dimensions differently (e.g. Gemini, which uses a camelCase
 * "aspectRatio" key) ships its own higher-priority adapter.
 */
class AspectRatioDimensionAdapter implements ImageDimensionAdapterInterface {

  /**
   * The aspect ratios offered to the AI.
   */
  protected const VALID_ASPECT_RATIOS = ['1:1', '4:3', '16:9'];

  /**
   * {@inheritdoc}
   */
  public function supports(string $provider_id): bool {
    // The portable default: any provider without a dedicated adapter.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): string {
    return 'The aspect ratio (default 16:9) is one of 1:1, 4:3, 16:9, chosen from the image\'s role and layout: 16:9 for a full-width or single-column hero/banner; 4:3 for a standard image in a multi-column layout or medium content block; 1:1 for square thumbnails, avatars, or grid/gallery tiles. Rule of thumb: fewer/wider columns favor wider ratios, more/narrower columns favor square; if unsure use 16:9.';
  }

  /**
   * {@inheritdoc}
   */
  public function getKey(): string {
    return 'aspect_ratio';
  }

  /**
   * {@inheritdoc}
   */
  public function getValues(): array {
    return self::VALID_ASPECT_RATIOS;
  }

  /**
   * {@inheritdoc}
   */
  public function extractConfiguration(array $item, string $model_id): array {
    $value = $item['aspect_ratio'] ?? NULL;
    if (is_string($value) && in_array($value, self::VALID_ASPECT_RATIOS, TRUE)) {
      return ['aspect_ratio' => $value];
    }
    return [];
  }

}
