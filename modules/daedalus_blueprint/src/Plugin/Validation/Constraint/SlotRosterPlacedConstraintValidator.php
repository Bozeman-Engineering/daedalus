<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\daedalus_blueprint\Slot\SlotPlacements;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Reports the placed slot ids a roster drops, one violation each.
 *
 * The value is the roster as written (slot id to its label and tier). The
 * validator never reads the config it judges: it is handed the roster and asks
 * SlotPlacements which placed ids that roster leaves out, over the
 * active storage, the one a validating writer is about to change.
 */
final class SlotRosterPlacedConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(protected SlotPlacements $slotPlacements) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('daedalus_blueprint.slot_placements'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof SlotRosterPlacedConstraint);
    // The sequence type's own constraints say what the roster must be; a
    // value of another shape is theirs to refuse.
    if (!is_array($value)) {
      return;
    }
    foreach ($this->slotPlacements->missing($value) as $slot => $placers) {
      $this->context->addViolation($constraint->message, [
        '@slot' => $slot,
        '@placers' => implode(', ', $placers),
      ]);
    }
  }

}
