<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * The outcome of StyleValueResolver's CHECK phase, holding the commit.
 *
 * Three faces, one per moment of a write:
 * - getErrors(): the input tier was unsound (unknown marker, handler shape
 *   errors, an illegal layers list). The lane returns these without
 *   validating or committing.
 * - getMap(): the stand-in map (every input-tier value replaced by plain
 *   CSS sound in its place) for StyleMapValidator to validate exactly as
 *   it would a plain write.
 * - commit(): invoked ONLY after validation succeeded. Handlers perform
 *   their effects (this is the one moment side effects are allowed) and
 *   the canonical resolved strings replace the stand-ins; the returned map
 *   is the storage form. Committing an errored resolution is a caller bug
 *   and throws. Non-blocking observations handlers make while committing
 *   are collected on the resolution (getWarnings()) for the lane to
 *   surface with the write's result.
 */
final class StyleValueResolution {

  /**
   * Warnings the handlers appended while committing.
   *
   * @var \Drupal\daedalus_blueprint\Result\BlueprintWarning[]
   */
  private array $warnings = [];

  /**
   * Constructs the resolution.
   *
   * @param array $map
   *   The stand-in-substituted map.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   Input-tier errors, path-rooted like the validator's.
   * @param array $pending
   *   The commits: {key, condition, property, layers} records whose layers
   *   are {literal} or {handler, value, context} in join order.
   */
  public function __construct(
    private readonly array $map,
    private readonly array $errors,
    private readonly array $pending,
  ) {}

  /**
   * Input-tier errors; when non-empty, do not validate or commit.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The errors.
   */
  public function getErrors(): array {
    return $this->errors;
  }

  /**
   * The stand-in map for StyleMapValidator.
   */
  public function getMap(): array {
    return $this->map;
  }

  /**
   * COMMIT phase: resolves handled values and returns the storage map.
   *
   * Each handled object's resolve() runs once, in map order, and its
   * canonical fragment joins its layers list with ", ". Only call after
   * the stand-in map validated: this is where side effects happen, and the
   * commit-after-validation ordering is what guarantees failed writes mint
   * nothing.
   */
  public function commit(): array {
    if ($this->errors !== []) {
      throw new \LogicException('An errored style-value resolution cannot commit; return its errors instead.');
    }
    $map = $this->map;
    foreach ($this->pending as $entry) {
      $map[$entry['key']][$entry['condition']][$entry['property']] = implode(', ', array_map(
        fn (array $layer): string => isset($layer['handler']) ? $layer['handler']->resolve($layer['value'], $layer['context'], $this->warnings) : $layer['literal'],
        $entry['layers'],
      ));
    }
    return $map;
  }

  /**
   * Warnings the handlers appended while committing.
   *
   * Empty before commit() ran; a lane that committed passes these through
   * with its (successful) result so the AI hears the observation without
   * the write failing.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintWarning[]
   *   The warnings, paths already located (handlers use the value's map
   *   path from their context).
   */
  public function getWarnings(): array {
    return $this->warnings;
  }

}
