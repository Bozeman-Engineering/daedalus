<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Result;

/**
 * Structured error value object.
 *
 * Contains enough context for an AI to fix the problem without guessing.
 */
class BlueprintError {

  /**
   * Context key marking a hint whose content may be shared across errors.
   *
   * Some correctives carry a large roster that is identical for every
   * error of their class in a batch (the stylable-property roster on
   * STYLE_PROPERTY_NOT_DECLARED repeats ~80 names per miss). A producer
   * that wants the batch channel to emit that roster ONCE marks the error
   * with this context key: ['key' => a string identifying the shared
   * content, 'context_keys' => context keys holding the duplicated
   * payload (optional)]. The batch aggregator keeps the first error per
   * key intact and rewrites later ones to reference it (dropping the
   * named context keys); the marker itself is bookkeeping and never
   * serializes (toArray() strips it).
   */
  public const SHARED_HINT = '_shared_hint';

  /**
   * Constructs a new BlueprintError.
   *
   * @param string $path
   *   JSON-path-style location mapping back to the submitted JSON.
   * @param string $code
   *   Machine-readable error code.
   * @param string $message
   *   Human-readable description of the problem.
   * @param array $context
   *   Structured data relevant to fixing the problem.
   * @param string $hint
   *   Suggested corrective action.
   * @param int|null $operationIndex
   *   The batch operation index, or NULL for non-batch errors.
   * @param string|null $element
   *   The page element the error addresses, in the author's own terms (the
   *   alias they chose, else the uuid; a part suffix when the error is on a
   *   part), or NULL when the error is not element-addressed.
   */
  public function __construct(
    protected readonly string $path,
    protected readonly string $code,
    protected readonly string $message,
    protected readonly array $context = [],
    protected readonly string $hint = '',
    protected readonly ?int $operationIndex = NULL,
    protected readonly ?string $element = NULL,
  ) {}

  /**
   * Gets the JSON path where the error occurred.
   *
   * @return string
   *   The JSON path.
   */
  public function getPath(): string {
    return $this->path;
  }

  /**
   * Gets the machine-readable error code.
   *
   * @return string
   *   The error code.
   */
  public function getCode(): string {
    return $this->code;
  }

  /**
   * Gets the human-readable error message.
   *
   * @return string
   *   The error message.
   */
  public function getMessage(): string {
    return $this->message;
  }

  /**
   * Gets structured context data for fixing the problem.
   *
   * @return array
   *   The context data.
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * Gets the suggested corrective action.
   *
   * @return string
   *   The hint text.
   */
  public function getHint(): string {
    return $this->hint;
  }

  /**
   * Gets the batch operation index.
   *
   * @return int|null
   *   The operation index, or NULL for non-batch errors.
   */
  public function getOperationIndex(): ?int {
    return $this->operationIndex;
  }

  /**
   * Gets the element the error addresses, in the author's terms.
   *
   * @return string|null
   *   The alias or uuid (plus any part suffix), or NULL when the error is
   *   not element-addressed.
   */
  public function getElement(): ?string {
    return $this->element;
  }

  /**
   * Returns a copy with a different path.
   *
   * @param string $path
   *   The new JSON path.
   *
   * @return static
   *   A new BlueprintError with the updated path.
   */
  public function withPath(string $path): static {
    return new static(
      path: $path,
      code: $this->code,
      message: $this->message,
      context: $this->context,
      hint: $this->hint,
      operationIndex: $this->operationIndex,
      element: $this->element,
    );
  }

  /**
   * Returns a copy with a batch operation index.
   *
   * @param int $operation_index
   *   The operation index.
   *
   * @return static
   *   A new BlueprintError with the operation index set.
   */
  public function withOperationIndex(int $operation_index): static {
    return new static(
      path: $this->path,
      code: $this->code,
      message: $this->message,
      context: $this->context,
      hint: $this->hint,
      operationIndex: $operation_index,
      element: $this->element,
    );
  }

  /**
   * Returns a copy addressed to a page element, in the author's terms.
   *
   * The dialect door's rewrite for errors raised by TRANSLATED spine ops:
   * a document or fragment expands into spine ops the author never
   * numbered, so the nested batch's operation index is meaningless to
   * them and is dropped here; the element (their alias, else the uuid)
   * is the address they can act on. An enclosing batch of the author's
   * own ops re-tags the outer index afterwards, so both can coexist.
   *
   * @param string $element
   *   The alias or uuid, with any part suffix.
   * @param string|null $path
   *   An optional path restated in the author's grammar.
   *
   * @return static
   *   A new BlueprintError addressed to the element.
   */
  public function withElement(string $element, ?string $path = NULL): static {
    return new static(
      path: $path ?? $this->path,
      code: $this->code,
      message: $this->message,
      context: $this->context,
      hint: $this->hint,
      operationIndex: NULL,
      element: $element,
    );
  }

  /**
   * The shared-hint key, when this error's hint content is shareable.
   *
   * @return string|null
   *   The key identifying the shared content, or NULL when the error does
   *   not participate in shared-hint deduplication.
   */
  public function getSharedHintKey(): ?string {
    $marker = $this->context[self::SHARED_HINT] ?? NULL;
    return is_array($marker) && is_string($marker['key'] ?? NULL) ? $marker['key'] : NULL;
  }

  /**
   * Returns a copy referencing an earlier error that carries the payload.
   *
   * The batch aggregator's rewrite for every shared-hint error after the
   * first with the same key: the hint becomes the given reference text and
   * the marker's named context keys (the duplicated payload) are dropped
   * along with the marker itself.
   *
   * @param string $hint
   *   The reference hint pointing at the earlier error.
   *
   * @return static
   *   A new BlueprintError with the deduplicated hint.
   */
  public function withSharedHintReference(string $hint): static {
    $context = $this->context;
    foreach (($context[self::SHARED_HINT]['context_keys'] ?? []) as $key) {
      unset($context[$key]);
    }
    unset($context[self::SHARED_HINT]);
    return new static(
      path: $this->path,
      code: $this->code,
      message: $this->message,
      context: $context,
      hint: $hint,
      operationIndex: $this->operationIndex,
      element: $this->element,
    );
  }

  /**
   * Builds the canonical malformed-uuid error for writer-minted identity.
   *
   * Every operation that accepts a caller-supplied uuid rejects a malformed
   * value with this one error, so the corrective reads the same at every
   * entry point.
   *
   * @param string $path
   *   JSON path of the offending parameter.
   * @param mixed $value
   *   The submitted value.
   *
   * @return static
   *   The INVALID_UUID error.
   */
  public static function invalidUuid(string $path, mixed $value): static {
    $display = is_scalar($value) ? (string) $value : gettype($value);
    return new static(
      path: $path,
      code: 'INVALID_UUID',
      message: "'$display' is not a valid UUID.",
      hint: 'Mint an RFC 4122 UUID (8-4-4-4-12 hexadecimal digits, e.g. UUID v4); human-readable identifiers are not valid element identity. Omit the uuid to have one generated.',
    );
  }

  /**
   * Builds the canonical collision error for writer-minted identity.
   *
   * Format is not the only thing a minted uuid has to get right: the value
   * also has to be free. A writer whose context is full of uuids it just
   * read can mint one that already identifies an element, and key-collision
   * semantics downstream are silently destructive (a component uuid equal
   * to a sibling's replaces it) or silently ambiguous (two live elements,
   * one identity). The value is never regenerated server-side: sibling
   * operations and style map keys reference it literally, so the whole
   * batch is refused and the writer re-mints.
   *
   * @param string $path
   *   JSON path of the offending parameter.
   * @param string $value
   *   The submitted value.
   *
   * @return static
   *   The UUID_IN_USE error.
   */
  public static function uuidInUse(string $path, string $value): static {
    return new static(
      path: $path,
      code: 'UUID_IN_USE',
      message: "'$value' already identifies an element on this entity.",
      context: ['uuid' => $value],
      hint: 'Mint a FRESH UUID: never reuse one read from the blueprint, and never repeat a UUID minted earlier in the same batch. Omit the uuid to have one generated.',
    );
  }

  /**
   * Serializes the error for JSON output.
   *
   * @return array
   *   The error as an associative array.
   */
  public function toArray(): array {
    $result = [
      'path' => $this->path,
      'code' => $this->code,
      'message' => $this->message,
    ];
    $context = $this->context;
    // The shared-hint marker is aggregator bookkeeping, never wire format
    // (single-op paths that skip batch deduplication must not leak it).
    unset($context[self::SHARED_HINT]);
    if (!empty($context)) {
      $result['context'] = $context;
    }
    if ($this->hint !== '') {
      $result['hint'] = $this->hint;
    }
    if ($this->element !== NULL) {
      $result['element'] = $this->element;
    }
    if ($this->operationIndex !== NULL) {
      $result['operation_index'] = $this->operationIndex;
    }
    return $result;
  }

}
