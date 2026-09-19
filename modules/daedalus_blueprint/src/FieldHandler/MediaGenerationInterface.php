<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

/**
 * A media reference field handler that can mint an image from a description.
 *
 * The one generation vocabulary, stated by the handler and read by every
 * door: the JSON door composes its {_generate, alt_text, <sizing key>}
 * grammar around it, the page language its generate="" attribute and the
 * sizing attribute (the key in kebab case). The guidance is door-neutral
 * on purpose: it says what a description is and how to size the image,
 * never how either door spells the write.
 */
interface MediaGenerationInterface {

  /**
   * What to write as the generation description, and the sizing choice.
   *
   * Door-neutral: names no key and no attribute. Ends with the deferred
   * rule (the reference is set at once, the image renders behind it).
   */
  public function getGenerationGuidance(): string;

  /**
   * The sizing key the active image provider reads on a generation item.
   *
   * @return string|null
   *   The provider's own key (e.g. aspectRatio, aspect_ratio, size), or
   *   NULL when no provider is configured or it takes no sizing.
   */
  public function getDimensionKey(): ?string;

  /**
   * The sizing values the active image provider accepts.
   *
   * @return string[]
   *   The values, empty when getDimensionKey() is NULL.
   */
  public function getDimensionValues(): array;

}
