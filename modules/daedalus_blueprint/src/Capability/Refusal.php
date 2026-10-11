<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability;

use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * Why a capability is refused: a code, a sentence, a hint, and a portal.
 *
 * The code is the stable machine-readable name every surface keys on
 * (FOREIGN_DOCUMENT, LAYOUT_NOT_TRANSLATABLE, WORKSPACE_REQUIRED,
 * ACCESS_DENIED_ENTITY, ACCESS_DENIED_PERMISSION); the context carries the
 * cause (the owner of a foreign document, the permissions that would open
 * the gesture) for the surface that explains it; the portal, when present,
 * is where the actor may make the gesture instead.
 */
final class Refusal {

  /**
   * The refusal for a missing permission: the one spelling, whichever policy asks.
   *
   * @param string[] $permissions
   *   The permissions, any one of which opens the gesture.
   * @param string $gesture
   *   What was attempted, as the subject of the sentence ("Changing the
   *   layout of node:page").
   */
  public static function permission(array $permissions, string $gesture): self {
    $quoted = array_map(static fn (string $permission): string => "'$permission'", $permissions);
    return new self(
      code: 'ACCESS_DENIED_PERMISSION',
      message: sprintf('%s requires the permission %s.', $gesture, implode(' or ', $quoted)),
      hint: 'The acting user lacks the required permission.',
      context: ['permissions' => $permissions],
    );
  }

  /**
   * Constructs a refusal.
   *
   * @param string $code
   *   The refusal code.
   * @param string $message
   *   One sentence saying what is refused and why.
   * @param string $hint
   *   The corrective: what to do instead.
   * @param array $context
   *   Machine-readable facts behind the refusal (the owner, for one).
   * @param \Drupal\daedalus_blueprint\Capability\Portal|null $portal
   *   Where the gesture can be made instead, when the actor may go there.
   */
  public function __construct(
    public readonly string $code,
    public readonly string $message,
    public readonly string $hint = '',
    public readonly array $context = [],
    public readonly ?Portal $portal = NULL,
  ) {}

  /**
   * The refusal as data: the one spelling every read and error carries.
   *
   * @return array{code: string, message: string, hint: string, context: array}
   *   The code, the sentence, the corrective and the context, the portal
   *   inside the context (`context.portal`) when there is one.
   */
  public function toArray(): array {
    $context = $this->context;
    if ($this->portal !== NULL) {
      $context['portal'] = $this->portal->toArray();
    }
    return [
      'code' => $this->code,
      'message' => $this->message,
      'hint' => $this->hint,
      'context' => $context,
    ];
  }

  /**
   * The refusal as the error an op door answers with.
   *
   * @param string $path
   *   The operation parameter the refused target was named in.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError
   *   The error, its context carrying the portal when there is one.
   */
  public function toError(string $path): BlueprintError {
    $facts = $this->toArray();
    return new BlueprintError(
      path: $path,
      code: $facts['code'],
      message: $facts['message'],
      context: $facts['context'],
      hint: $facts['hint'],
    );
  }

}
