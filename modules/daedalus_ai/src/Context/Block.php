<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Context;

/**
 * One per-turn block: a kind and the text its producer stated.
 */
final class Block {

  /**
   * Constructs a block.
   *
   * @param \Drupal\daedalus_ai\Context\BlockKind $kind
   *   The kind.
   * @param string $text
   *   The text the producer stated, without any wrapper the renderer adds.
   */
  public function __construct(
    public readonly BlockKind $kind,
    public readonly string $text,
  ) {
  }

  /**
   * The block as a serializable array; fromArray() reads it back.
   *
   * @return array{kind: string, text: string}
   *   The block.
   */
  public function toArray(): array {
    return ['kind' => $this->kind->value, 'text' => $this->text];
  }

  /**
   * A block from its array form.
   *
   * @param array $values
   *   What toArray() produced.
   *
   * @throws \InvalidArgumentException
   *   When the kind is unknown or the text is not a string.
   */
  public static function fromArray(array $values): self {
    $kind = BlockKind::tryFrom(is_string($values['kind'] ?? NULL) ? $values['kind'] : '');
    if ($kind === NULL) {
      throw new \InvalidArgumentException(sprintf('Unknown transcript block kind "%s".', is_scalar($values['kind'] ?? NULL) ? (string) $values['kind'] : gettype($values['kind'] ?? NULL)));
    }
    if (!is_string($values['text'] ?? NULL)) {
      throw new \InvalidArgumentException('A transcript block text must be a string.');
    }
    return new self($kind, $values['text']);
  }

}
