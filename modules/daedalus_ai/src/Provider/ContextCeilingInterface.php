<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Provider;

/**
 * A provider plugin that declares a prompt-size ceiling for a model.
 *
 * The ceiling is the provider's own fact about where a prompt stops being
 * economical or legal: the model's maximum input, or a pricing cliff below
 * it (xAI bills every token at double rate from 200k prompt tokens). The
 * spine's segment budget never exceeds it. A provider without this
 * interface declares no ceiling and the configured budget alone bounds the
 * segment.
 */
interface ContextCeilingInterface {

  /**
   * The prompt-size ceiling for a model, in tokens.
   *
   * @param string $model_id
   *   The model id the request will run on.
   *
   * @return int|null
   *   The ceiling, or NULL when the provider knows none for the model.
   */
  public function contextCeiling(string $model_id): ?int;

}
