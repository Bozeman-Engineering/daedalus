<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_stylish\FactConditionConvergence;
use Drupal\daedalus_stylish\ShippedSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the whole fact-condition convergence on demand.
 *
 * The fact_condition_000N post_update sequence (ConvergenceCommandBase)
 * over FactConditionConvergence.
 */
#[AsCommand(
  name: 'daedalus_stylish:converge-fact-conditions',
  description: 'Moves every retired position-fact condition ("first-child", "not-last-child", "odd", …) onto its qualified selector key ("::&:first-child", "::> *:not(:last-child)", "::> *:nth-child(odd)") across every style store.',
)]
final class ConvergeFactConditionsCommand extends ConvergenceCommandBase {

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.shipped_sync')]
    ShippedSync $shippedSync,
    #[Autowire(service: 'daedalus_stylish.fact_condition_convergence')]
    FactConditionConvergence $convergence,
  ) {
    parent::__construct($shippedSync, $convergence);
  }

  /**
   * {@inheritdoc}
   */
  protected function convergenceLabel(): string {
    return 'Fact condition convergence';
  }

}
