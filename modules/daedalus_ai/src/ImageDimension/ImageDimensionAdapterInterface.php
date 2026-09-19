<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\ImageDimension;

/**
 * Owns one text_to_image provider's sizing vocabulary, end to end.
 *
 * Different image providers describe an image's dimensions differently: most
 * (Grok, Imagen) accept an aspect ratio under an "aspect_ratio" key, while
 * Gemini accepts an aspect ratio under its own camelCase "aspectRatio" key.
 * Rather than translate between a neutral abstraction and each
 * provider, an adapter speaks a single provider's real API vocabulary on both
 * sides: the guidance it gives the AI and the configuration it builds from the
 * AI's response are expressed in the same terms, so there is nothing to
 * translate or guess.
 *
 * Adapters are tagged services (daedalus_ai.image_dimension_adapter) collected by
 * ImageDimensionAdapterManager, which selects the one matching the configured
 * provider.
 */
interface ImageDimensionAdapterInterface {

  /**
   * Whether this adapter handles the given text_to_image provider.
   *
   * @param string $provider_id
   *   The configured text_to_image provider id (e.g. "gemini").
   *
   * @return bool
   *   TRUE if this adapter speaks the given provider's vocabulary.
   */
  public function supports(string $provider_id): bool;

  /**
   * How to choose this provider's sizing value from the image's role.
   *
   * Door-neutral: names the values and the heuristic, never the key or an
   * attribute, so the JSON door and the page language each spell the write
   * their own way around it (getKey() and getValues() carry the grammar).
   *
   * @return string
   *   A sentence (or two) on the valid values and how to choose one.
   */
  public function getGuidance(): string;

  /**
   * The sizing key this provider reads on a generation item.
   *
   * @return string
   *   The provider's own key, e.g. "aspect_ratio" or "aspectRatio".
   */
  public function getKey(): string;

  /**
   * The sizing values this provider accepts, in the order offered.
   *
   * @return string[]
   *   The values, e.g. ['1:1', '4:3', '16:9'].
   */
  public function getValues(): array;

  /**
   * Builds the provider configuration from an AI-supplied _generate item.
   *
   * Reads this provider's sizing key from the item, validates it, and returns
   * the array to pass to AiProviderInterface::setConfiguration(). An empty
   * array means "no sizing supplied or recognized": the provider uses its
   * own default.
   *
   * @param array $item
   *   The _generate item from the deserialized field value (carries _generate,
   *   alt_text, and an optional sizing key such as aspect_ratio or size).
   * @param string $model_id
   *   The model the image will be generated with (valid sizes can be
   *   model-specific).
   *
   * @return array
   *   Configuration for setConfiguration(), or an empty array to use the
   *   provider's default.
   */
  public function extractConfiguration(array $item, string $model_id): array;

}
