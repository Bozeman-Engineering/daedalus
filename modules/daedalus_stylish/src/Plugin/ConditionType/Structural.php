<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ConditionType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ConditionType;
use Drupal\daedalus_stylish\ConditionTypeBase;

/**
 * Structural position: where the addressed element sits among its siblings.
 *
 * Every condition here is a FACT (ConditionTypeInterface::getConditions()
 * `fact`): a structural pseudo-class holds or not by the element's place
 * in the markup, so the editor can neither force it (no force-state
 * dual) nor pose the canvas into it. Combined with a selector entry it
 * qualifies the targets ("{key}::> *" under first-child styles the first
 * child); on an element's own entry it qualifies that element. The axis
 * sits between state and width so a canonical compound reads
 * hover+first-child+narrow. Parameterized pseudo-classes
 * (:nth-child(3n+1)) are not conditions; odd and even are the two
 * parameterless spellings the doctrine admits. A fact's negation is a
 * fact of the same shape, identified by its :not() wrapper ("margin
 * below every child but the last" is "{key}::> *" under
 * not-last-child); odd and even are each other's complement and need
 * none. Facts on one axis are alternatives, never combined.
 */
#[ConditionType(
  id: 'structural',
  label: new TranslatableMarkup('Position'),
  axis: 'structural',
  weight: 15,
)]
final class Structural extends ConditionTypeBase {

  /**
   * {@inheritdoc}
   */
  public function getConditions(): array {
    return [
      'first-child' => ['label' => $this->t('First child'), 'wrapper' => ':first-child', 'fact' => TRUE],
      'last-child' => ['label' => $this->t('Last child'), 'wrapper' => ':last-child', 'fact' => TRUE],
      'only-child' => ['label' => $this->t('Only child'), 'wrapper' => ':only-child', 'fact' => TRUE],
      'empty' => ['label' => $this->t('Empty'), 'wrapper' => ':empty', 'fact' => TRUE],
      'odd' => ['label' => $this->t('Odd'), 'wrapper' => ':nth-child(odd)', 'fact' => TRUE],
      'even' => ['label' => $this->t('Even'), 'wrapper' => ':nth-child(even)', 'fact' => TRUE],
      'not-first-child' => ['label' => $this->t('Not first child'), 'wrapper' => ':not(:first-child)', 'fact' => TRUE],
      'not-last-child' => ['label' => $this->t('Not last child'), 'wrapper' => ':not(:last-child)', 'fact' => TRUE],
      'not-only-child' => ['label' => $this->t('Not only child'), 'wrapper' => ':not(:only-child)', 'fact' => TRUE],
      'not-empty' => ['label' => $this->t('Not empty'), 'wrapper' => ':not(:empty)', 'fact' => TRUE],
    ];
  }

}
