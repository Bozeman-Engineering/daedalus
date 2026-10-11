<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability;

/**
 * What an operation requires of one of its targets.
 *
 * A registered operation handler declares its requirements as shapes
 * (OperationHandlerInterface::getRequirements()): the capability and WHICH
 * operation parameter names the target, resolved against the actual
 * operation by the op door before the resolver is asked. The shapes cover
 * every target an operation can name:
 * - root(): the host itself (its fields, its own style key, its top-level
 *   flow).
 * - element(): the element whose uuid an operation parameter carries
 *   ('element' by default).
 * - styleKey(): the element a style map key addresses ('key' by default);
 *   a rule key or a field key resolves to its element.
 * - destination(): insert on the container a placement grammar location
 *   resolves to ('location' by default): the anchor for 'inside', the
 *   anchor's parent for before/after, the host for the root.
 *
 * A resolved requirement (on()) names its target uuid outright; the
 * built-in operations resolve theirs directly.
 */
final class Requirement {

  public const ROOT = 'root';
  public const ELEMENT = 'element';
  public const STYLE_KEY = 'style_key';
  public const DESTINATION = 'destination';

  private function __construct(
    public readonly Capability $capability,
    public readonly string $kind,
    public readonly string $parameter,
    public readonly string $path,
    public readonly ?string $target = NULL,
  ) {}

  /**
   * A capability of the host itself.
   *
   * @param \Drupal\daedalus_blueprint\Capability\Capability $capability
   *   The capability.
   * @param string $path
   *   The operation parameter a refusal is reported at.
   */
  public static function root(Capability $capability, string $path = 'op'): self {
    return new self($capability, self::ROOT, '', $path);
  }

  /**
   * A capability of the element an operation parameter names by uuid.
   */
  public static function element(Capability $capability, string $parameter = 'element'): self {
    return new self($capability, self::ELEMENT, $parameter, $parameter);
  }

  /**
   * Style on the element a style map key (an operation parameter) addresses.
   */
  public static function styleKey(string $parameter = 'key'): self {
    return new self(Capability::Style, self::STYLE_KEY, $parameter, $parameter);
  }

  /**
   * Insert on the destination a placement location (an operation parameter) resolves to.
   */
  public static function destination(string $parameter = 'location'): self {
    return new self(Capability::Insert, self::DESTINATION, $parameter, "$parameter.anchor");
  }

  /**
   * A resolved requirement: the capability on a known target.
   *
   * @param \Drupal\daedalus_blueprint\Capability\Capability $capability
   *   The capability.
   * @param string $target
   *   The element uuid, or the host uuid for the root.
   * @param string $path
   *   The operation parameter a refusal is reported at.
   */
  public static function on(Capability $capability, string $target, string $path): self {
    return new self($capability, self::ELEMENT, '', $path, $target);
  }

  /**
   * This requirement resolved onto a target.
   */
  public function at(string $target): self {
    return new self($this->capability, $this->kind, $this->parameter, $this->path, $target);
  }

  /**
   * Whether the requirement names its target.
   */
  public function isResolved(): bool {
    return $this->target !== NULL;
  }

}
