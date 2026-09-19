<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

/**
 * An id that names no design system on this site.
 *
 * Thrown by ActiveDesignSystem where a caller hands it an id to act on
 * (activate a system, resolve a shipped member's key); the lanes translate
 * it into their own error grammar (a blueprint error naming the known
 * systems). An \InvalidArgumentException, so a caller that only cares
 * about "bad id" catches it as before.
 */
class UnknownDesignSystemException extends \InvalidArgumentException {

  /**
   * Constructs the exception from the unknown id and the site's ids.
   *
   * @param string $designSystem
   *   The id that names no system.
   * @param string[] $known
   *   The ids the site does have, in id order.
   */
  public function __construct(
    public readonly string $designSystem,
    public readonly array $known,
  ) {
    parent::__construct(sprintf('No design system "%s"; %s.', $designSystem, $known === [] ? 'the site has none' : 'the site has ' . implode(', ', $known)));
  }

}
