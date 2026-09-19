<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_stylish\AnimationRepository;
use Drupal\daedalus_stylish\AnimationStylesheet;
use Drupal\daedalus_stylish\AnimationUsage;

/**
 * Reacts to a workspace publish for animations: recompile, resync usage.
 *
 * The animation instance of StylesheetWorkspaceSubscriberBase (which owns
 * the rationale): staged animation revisions land in the compiled
 * animations file, and the published sources' keyframes-name references
 * resync into the usage table.
 */
final class AnimationWorkspaceSubscriber extends StylesheetWorkspaceSubscriberBase {

  public function __construct(
    AnimationStylesheet $animationStylesheet,
    protected AnimationUsage $animationUsage,
    protected AnimationRepository $animationRepository,
    MaterialsRosterBuilder $rosterBuilder,
  ) {
    parent::__construct($animationStylesheet, $rosterBuilder);
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_animation';
  }

  /**
   * {@inheritdoc}
   */
  protected function resetRepository(): void {
    $this->animationRepository->reset();
  }

  /**
   * {@inheritdoc}
   */
  protected function resyncUsage(array $published): void {
    $this->animationUsage->resyncPublished($published);
  }

}
