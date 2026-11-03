<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Transcript;

use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutputInterface;

/**
 * One tool call an assistant turn made: its id, function name and arguments.
 *
 * The id is what the tool result answers to (ChatMessage::getToolsId()); the
 * name and arguments are what the result key is minted from.
 */
final class ToolCall {

  /**
   * Constructs a tool call.
   *
   * @param string $id
   *   The call id the provider minted, which the result carries back.
   * @param string $name
   *   The function name the model called.
   * @param array<string, mixed> $arguments
   *   The arguments by name, as the model passed them.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $name,
    public readonly array $arguments = [],
  ) {
  }

  /**
   * The call as drupal/ai carries it on an assistant ChatMessage.
   */
  public static function fromFunctionOutput(ToolsFunctionOutputInterface $function): self {
    $arguments = [];
    foreach ($function->getArguments() as $argument) {
      $arguments[$argument->getName()] = $argument->getValue();
    }
    return new self($function->getToolId(), $function->getName(), $arguments);
  }

  /**
   * The call as a serializable array; fromArray() reads it back.
   *
   * @return array{id: string, name: string, arguments: array<string, mixed>}
   *   The call.
   */
  public function toArray(): array {
    return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
  }

  /**
   * A call from its array form.
   *
   * @param array $values
   *   What toArray() produced.
   *
   * @throws \InvalidArgumentException
   *   When the id or name is not a string or the arguments are not an array.
   */
  public static function fromArray(array $values): self {
    if (!is_string($values['id'] ?? NULL) || !is_string($values['name'] ?? NULL)) {
      throw new \InvalidArgumentException('A transcript tool call needs a string id and name.');
    }
    $arguments = $values['arguments'] ?? [];
    if (!is_array($arguments)) {
      throw new \InvalidArgumentException('Transcript tool call arguments must be an array.');
    }
    return new self($values['id'], $values['name'], $arguments);
  }

}
