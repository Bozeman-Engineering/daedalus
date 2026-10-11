<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability;

/**
 * One capability of one actor on one target: allowed, or refused with why.
 */
final class Verdict {

  private function __construct(
    private readonly ?Refusal $refusal,
  ) {}

  /**
   * The gesture may be made.
   */
  public static function allowed(): self {
    return new self(NULL);
  }

  /**
   * The gesture is refused, for the given reason.
   */
  public static function refused(Refusal $refusal): self {
    return new self($refusal);
  }

  /**
   * Whether the gesture may be made.
   */
  public function isAllowed(): bool {
    return $this->refusal === NULL;
  }

  /**
   * The refusal, or NULL when allowed.
   */
  public function refusal(): ?Refusal {
    return $this->refusal;
  }

}
