<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Result;

/**
 * Structured warning value object.
 *
 * Non-blocking observations returned alongside errors or on success.
 * The AI can choose to act on these or ignore them.
 */
class BlueprintWarning {

  /**
   * Constructs a new BlueprintWarning.
   *
   * @param string $path
   *   JSON-path-style location mapping back to the submitted JSON.
   * @param string $code
   *   Machine-readable warning code.
   * @param string $message
   *   Human-readable description of the observation.
   */
  public function __construct(
    protected readonly string $path,
    protected readonly string $code,
    protected readonly string $message,
  ) {}

  /**
   * Gets the JSON path where the warning applies.
   *
   * @return string
   *   The JSON path.
   */
  public function getPath(): string {
    return $this->path;
  }

  /**
   * Gets the machine-readable warning code.
   *
   * @return string
   *   The warning code.
   */
  public function getCode(): string {
    return $this->code;
  }

  /**
   * Gets the human-readable warning message.
   *
   * @return string
   *   The warning message.
   */
  public function getMessage(): string {
    return $this->message;
  }

  /**
   * Serializes the warning for JSON output.
   *
   * @return array
   *   The warning as an associative array.
   */
  public function toArray(): array {
    return [
      'path' => $this->path,
      'code' => $this->code,
      'message' => $this->message,
    ];
  }

}
