<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Segment;

/**
 * The prompt-size budget a segment runs under, and where it came from.
 *
 * The effective budget is the smallest of the bounds that apply: the
 * configured budget (the default, or the model's override) clamped up to
 * the configured minimum, the ceiling the provider declares for the model
 * (a pricing cliff or its own limit) less the headroom, and the model's
 * maximum input less the same headroom. The headroom is the room a turn
 * needs under the provider's window after the last measurement: the legs
 * that grow the prompt before the budget is read again, and the carry-over
 * call that closes the segment, which re-sends the whole prompt. The bound
 * that won is named so a close can be logged with its reason.
 *
 * The hard limit is the provider's side of the same arithmetic: the
 * smallest bound the provider declares (ceiling, maximum input) less the
 * headroom, NULL when it declares neither. The effective budget never
 * exceeds it, and a turn that measures past it is ended before the
 * provider refuses the next leg (SegmentPolicy::pastLimit()).
 *
 * The minimum is a floor under the configured value only, never under the
 * provider's facts: a budget set under the floor (a settings form refuses
 * one, but `drush config:set` does not) is raised to it before the ceiling
 * and the maximum-input cuts apply, so a budget under the prompt's own
 * floor (tools, system prompt, catalog) cannot close every finished turn
 * and pay a carry-over call each time; a ceiling under the minimum still
 * bounds, since the window is the provider's fact.
 */
final class SegmentBudget {

  public const SOURCE_CONFIGURED = 'configured';

  public const SOURCE_CEILING = 'ceiling';

  public const SOURCE_MAX_INPUT = 'max_input';

  public const SOURCE_MINIMUM = 'minimum';

  /**
   * Constructs a budget.
   *
   * @param int $configured
   *   The configured budget in tokens: the default or the model's override.
   * @param int|null $ceiling
   *   The provider's ceiling for the model, or NULL when it declares none.
   * @param int|null $maxInput
   *   The model's maximum input in tokens, or NULL when unknown.
   * @param int $headroom
   *   The tokens kept free below the provider's bounds (the ceiling and
   *   the maximum input).
   * @param int $minimum
   *   The floor the configured budget is clamped up to; 0 for none.
   */
  public function __construct(
    public readonly int $configured,
    public readonly ?int $ceiling,
    public readonly ?int $maxInput,
    public readonly int $headroom,
    public readonly int $minimum,
  ) {
    if ($configured < 1) {
      throw new \InvalidArgumentException(sprintf('A segment budget must be at least 1 token, got %d.', $configured));
    }
    if ($ceiling !== NULL && $ceiling < 1) {
      throw new \InvalidArgumentException(sprintf('A context ceiling must be at least 1 token, got %d.', $ceiling));
    }
    if ($maxInput !== NULL && $maxInput < 1) {
      throw new \InvalidArgumentException(sprintf('A maximum input must be at least 1 token, got %d.', $maxInput));
    }
    if ($headroom < 0) {
      throw new \InvalidArgumentException(sprintf('A budget headroom must not be negative, got %d.', $headroom));
    }
    if ($minimum < 0) {
      throw new \InvalidArgumentException(sprintf('A budget minimum must not be negative, got %d.', $minimum));
    }
  }

  /**
   * The budget in effect: the smallest bound that applies.
   */
  public function effective(): int {
    return min(array_values($this->bounds()));
  }

  /**
   * The hard limit: the smallest bound the provider declares, less headroom.
   *
   * @return int|null
   *   The limit in tokens, or NULL when the provider declares no bound
   *   (or none above the headroom).
   */
  public function hardLimit(): ?int {
    $limits = array_intersect_key($this->bounds(), [self::SOURCE_CEILING => TRUE, self::SOURCE_MAX_INPUT => TRUE]);
    return $limits === [] ? NULL : min(array_values($limits));
  }

  /**
   * The name of the bound the effective budget comes from.
   *
   * The configured budget (or the minimum, when it raised the configured
   * value) wins a tie: a ceiling equal to it is not what bounds the
   * segment.
   */
  public function source(): string {
    $bounds = $this->bounds();
    $effective = min(array_values($bounds));
    foreach ($bounds as $source => $bound) {
      if ($bound === $effective) {
        return $source;
      }
    }
    throw new \LogicException('A budget always has a bound.');
  }

  /**
   * The bounds that apply, by source, the configured one first.
   *
   * The configured budget enters clamped up to the minimum, under the
   * minimum's name when the clamp bound. The provider's bounds enter less
   * the headroom, and only when above it: a provider that never overrode
   * drupal/ai's ChatTrait answers its 1024 placeholder as the maximum
   * input, which is no fact about the model and would starve the segment,
   * and a bound the headroom swallows whole would leave nothing to run in.
   *
   * @return array<string, int>
   *   The bounds by source.
   */
  private function bounds(): array {
    $bounds = $this->configured < $this->minimum
      ? [self::SOURCE_MINIMUM => $this->minimum]
      : [self::SOURCE_CONFIGURED => $this->configured];
    if ($this->ceiling !== NULL && $this->ceiling > $this->headroom) {
      $bounds[self::SOURCE_CEILING] = $this->ceiling - $this->headroom;
    }
    if ($this->maxInput !== NULL && $this->maxInput > $this->headroom) {
      $bounds[self::SOURCE_MAX_INPUT] = $this->maxInput - $this->headroom;
    }
    return $bounds;
  }

}
