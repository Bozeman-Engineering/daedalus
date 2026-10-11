<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\Core\Recipe\RecipeAppliedEvent;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sweeps the styling gate after every applied recipe.
 *
 * A recipe is an install pipeline that runs under the config syncing flag:
 * RecipeRunner::installModule() installs modules with
 * config.installer syncing on, so hook_install() receives $is_syncing TRUE
 * and daedalus_stylish's install-time syncAll() declines (a real config
 * import must not create config outside the sync set), and a display or
 * bundle an earlier ingredient imported before daedalus_stylish was
 * installed had no gate hook to fire. The blueprint types are the case
 * that found it: their Layout Builder displays land with the
 * daedalus_blueprint ingredient, daedalus_stylish installs in the next,
 * and nothing between them saves a gate carrier, so a recipe site had no
 * style store for saved blueprints and no `style section blueprint
 * elements` permission for the roles ingredient to grant. The sweep after each applied recipe is
 * the same convergence syncAll() runs at install and update, for the one
 * pipeline that suppresses both: idempotent (a bundle carrying the field
 * is skipped), opt-outs respected, and ordered so the field exists before
 * a later ingredient grants its permission.
 */
final class RecipeAppliedSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly DaedalusStylishFieldManager $fieldManager,
  ) {}

  /**
   * Defaults styling ON for every bundle whose gate the recipe opened.
   */
  public function onRecipeApplied(RecipeAppliedEvent $event): void {
    $this->fieldManager->syncAll();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      RecipeAppliedEvent::class => ['onRecipeApplied'],
    ];
  }

}
