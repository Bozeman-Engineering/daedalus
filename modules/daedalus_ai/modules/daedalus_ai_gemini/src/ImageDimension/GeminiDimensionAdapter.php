<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_gemini\ImageDimension;

use Drupal\daedalus_ai\ImageDimension\ImageDimensionAdapterInterface;

/**
 * Gemini adapter: sizes an image by aspect ratio, Gemini's own way.
 *
 * Gemini's images API takes an "aspectRatio" (camelCase) configuration key,
 * distinct from the snake_case "aspect_ratio" the default adapter emits. Gemini
 * accepts a wider ratio set than the default adapter offers, so this adapter
 * advertises a curated layout-role subset in its guidance while accepting any
 * ratio Gemini's API actually supports.
 */
class GeminiDimensionAdapter implements ImageDimensionAdapterInterface {

  /**
   * Ratios Gemini's text_to_image API accepts.
   *
   * Mirrors gemini_provider's definitions/api_defaults.yml. Validation accepts
   * any of these so a legitimately valid ratio is never silently dropped.
   */
  protected const VALID_ASPECT_RATIOS = [
    '1:1', '2:3', '3:2', '3:4', '4:3', '9:16', '16:9',
  ];

  /**
   * {@inheritdoc}
   */
  public function supports(string $provider_id): bool {
    return $provider_id === 'gemini';
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): string {
    return 'The aspect ratio (default 16:9) is chosen from the image\'s role and layout: 16:9 for a full-width or single-column hero/banner (3:2 for a slightly squarer one); 4:3 for a standard image in a multi-column layout or medium content block (3:4 for its portrait); 1:1 for square thumbnails, avatars, or grid/gallery tiles; 9:16 (or 2:3) for a tall sidebar or full-height portrait image. Rule of thumb: fewer/wider columns favor wider ratios, more/narrower columns favor square or portrait; if unsure use 16:9.';
  }

  /**
   * {@inheritdoc}
   */
  public function getKey(): string {
    return 'aspectRatio';
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
    $value = $item['aspectRatio'] ?? NULL;
    if (is_string($value) && in_array($value, self::VALID_ASPECT_RATIOS, TRUE)) {
      return ['aspectRatio' => $value];
    }
    return [];
  }

}
