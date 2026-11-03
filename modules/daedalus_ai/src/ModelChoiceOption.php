<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

/**
 * One model an administrator curated for users to choose.
 *
 * A row of daedalus_ai.settings model_choices: the provider plugin id, the
 * model id the provider knows it by, and an optional label (the hint the
 * chat's menu shows beside the row). The key is drupal/ai's own simple
 * option grammar, provider__model, which is what the per-user preference
 * stores and what the admin form's select offers.
 */
final class ModelChoiceOption {

  /**
   * The separator of drupal/ai's simple option grammar.
   */
  public const SEPARATOR = '__';

  public function __construct(
    public readonly string $provider,
    public readonly string $model,
    public readonly ?string $label = NULL,
  ) {
    if ($provider === '' || $model === '') {
      throw new \InvalidArgumentException('A model choice needs a provider id and a model id.');
    }
  }

  /**
   * Builds an option from a config row.
   *
   * @param mixed $row
   *   A {provider, model, label} row as stored in config.
   *
   * @return self
   *   The option.
   *
   * @throws \InvalidArgumentException
   *   When the row does not carry string provider and model ids.
   */
  public static function fromConfig(mixed $row): self {
    if (!is_array($row) || !is_string($row['provider'] ?? NULL) || !is_string($row['model'] ?? NULL)) {
      throw new \InvalidArgumentException('A model_choices row needs a provider id and a model id.');
    }
    $label = $row['label'] ?? NULL;
    if ($label !== NULL && !is_string($label)) {
      throw new \InvalidArgumentException('A model_choices label must be a string.');
    }
    return new self($row['provider'], $row['model'], $label === '' ? NULL : $label);
  }

  /**
   * The option's key in drupal/ai's simple option grammar.
   *
   * @return string
   *   provider__model.
   */
  public function key(): string {
    return $this->provider . self::SEPARATOR . $this->model;
  }

  /**
   * Splits a simple option key into its provider and model ids.
   *
   * The split is at the first separator: a provider plugin id never carries
   * one, a model id might.
   *
   * @param string $key
   *   A provider__model key.
   *
   * @return array{0: string, 1: string}|null
   *   The provider and model ids, or NULL when the key is not two non-empty
   *   parts.
   */
  public static function splitKey(string $key): ?array {
    $parts = explode(self::SEPARATOR, $key, 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
      return NULL;
    }
    return [$parts[0], $parts[1]];
  }

  /**
   * The config row form.
   *
   * @return array{provider: string, model: string, label: string}
   *   The row as daedalus_ai.settings stores it (an absent label is '').
   */
  public function toConfig(): array {
    return [
      'provider' => $this->provider,
      'model' => $this->model,
      'label' => $this->label ?? '',
    ];
  }

}
