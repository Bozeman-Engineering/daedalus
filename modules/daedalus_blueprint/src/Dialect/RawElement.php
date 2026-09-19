<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * One element in the raw parse tree the tokenizer events build.
 *
 * Grammar-free: just the tag, its attributes in source order, the 1-based
 * source line, and mixed children (RawElement or [text, line] pairs).
 * DialectParser walks this into the lean node tree and structural errors.
 */
final class RawElement {

  /**
   * Children: RawElement instances or ['#text' => string, '#line' => int].
   *
   * @var array
   */
  public array $children = [];

  /**
   * The enclosing element, NULL for the root.
   */
  public ?RawElement $parent = NULL;

  public function __construct(
    public readonly string $tag,
    public readonly array $attributes,
    public readonly int $line,
  ) {}

  /**
   * The attribute's value, or NULL when absent.
   */
  public function attr(string $name): ?string {
    if (!array_key_exists($name, $this->attributes)) {
      return NULL;
    }
    $value = $this->attributes[$name];
    return $value === NULL ? '' : (string) $value;
  }

  /**
   * The element children, in document order.
   *
   * @return \Drupal\daedalus_blueprint\Dialect\RawElement[]
   *   The child elements, text children skipped.
   */
  public function elementChildren(): array {
    return array_values(array_filter($this->children, static fn ($c) => $c instanceof self));
  }

  /**
   * The text children, in document order.
   *
   * @return array
   *   The text children: ['#text' => string, '#line' => int] pairs.
   */
  public function textChildren(): array {
    return array_values(array_filter($this->children, static fn ($c) => !$c instanceof self));
  }

  /**
   * The concatenated text content (raw for opaque bodies).
   */
  public function text(): string {
    return implode('', array_column($this->textChildren(), '#text'));
  }

}
