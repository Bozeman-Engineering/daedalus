<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Every slot id a frame or a template places must stay in the roster.
 *
 * Declared on the slot roster (daedalus_blueprint.settings `slots`) so
 * that every validating writer meets it through typed config validation:
 * a recipe's config action and a recipe's config install (the object is
 * FullyValidatable for this), a config form, any future door onto simple
 * config. The judgement is SlotPlacements'; the validator only reports
 * it, one violation per dropped id naming the documents placing it.
 */
#[Constraint(
  id: 'DaedalusSlotRosterPlaced',
  label: new TranslatableMarkup('Every placed slot is rostered', [], ['context' => 'Validation']),
)]
final class SlotRosterPlacedConstraint extends SymfonyConstraint {

  /**
   * The message, one per slot id the roster drops while a document places it.
   */
  public string $message = "The slot '@slot' is placed by @placers and must stay in the roster.";

}
