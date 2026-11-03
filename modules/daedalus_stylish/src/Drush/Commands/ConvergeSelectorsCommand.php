<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Drush\Commands;

use Drupal\daedalus_stylish\RelativeSelectorConvergence;
use Drupal\daedalus_stylish\ShippedSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs the whole relative-selector convergence on demand.
 *
 * The relative_selector_000N post_update sequence (ConvergenceCommandBase)
 * over RelativeSelectorConvergence.
 */
#[AsCommand(
  name: 'daedalus_stylish:converge-selectors',
  description: 'Rewrites the retired "::>" children spelling to the relative selector grammar ("::> *") across every style store.',
)]
final class ConvergeSelectorsCommand extends ConvergenceCommandBase {

  public function __construct(
    #[Autowire(service: 'daedalus_stylish.shipped_sync')]
    ShippedSync $shippedSync,
    #[Autowire(service: 'daedalus_stylish.relative_selector_convergence')]
    RelativeSelectorConvergence $convergence,
  ) {
    parent::__construct($shippedSync, $convergence);
  }

  /**
   * {@inheritdoc}
   */
  protected function convergenceLabel(): string {
    return 'Relative selector convergence';
  }

}
