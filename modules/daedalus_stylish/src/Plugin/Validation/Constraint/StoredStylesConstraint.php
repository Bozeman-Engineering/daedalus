<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A stored style document is one the style map validator admits.
 *
 * Declared on the two style field types (the style map and a variant's
 * styles), so validation follows the data: every writer that validates
 * the entity meets it (the commit door, an entity form, a web service),
 * whether or not the write passed a field handler or an operation
 * handler. The judgement is StyleMapValidator's; the constraint's
 * validator only derives the context from the field's entity and reports
 * each error as a violation.
 *
 * The posture is the one that holds for every stored value whatever door
 * wrote it and whatever the site's law has become since: structure
 * (StyleMapValidator::withLandingLane()). The write doors keep the law of
 * the day (enforcement mode, authored constraints, minted tokens), which
 * judges the act of writing, never a value already at rest.
 */
#[Constraint(
  id: 'DaedalusStylishStoredStyles',
  label: new TranslatableMarkup('Stored styles are valid', [], ['context' => 'Validation']),
)]
final class StoredStylesConstraint extends SymfonyConstraint {

  /**
   * The message for a value that is not a JSON object.
   */
  public string $notAnObject = 'The stored styles are not a JSON object of style entries.';

  /**
   * The message for a variant whose scope names no address to validate at.
   */
  public string $noScope = 'The styles cannot be validated: @message';

  /**
   * The message for one validator error at a path inside the document.
   */
  public string $errorAt = '@path: @message';

  /**
   * The message for one validator error on the document itself.
   */
  public string $error = '@message';

}
