<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Hook;

use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Attribute\RemoveHook;
use Drupal\daedalus_layout\ComponentTransform\PresaveSafetyNet;
use Drupal\daedalus_layout\InlineBlockEntityOperations;
use Drupal\layout_builder\Hook\LayoutBuilderHooks;

/**
 * The one presave of every layout store: replaces layout_builder's.
 *
 * Core's presave saves inline blocks one level deep and as entities on
 * every host; daedalus_layout's is depth-aware and stores a display's
 * blocks as values, so core's must not run at all (it ran first and saved
 * an entity the conversion then orphaned). hook_module_implements_alter
 * cannot remove an OOP implementation on this core, so the removal is the
 * attribute core itself uses for the same job.
 */
final class EntityPresave {

  public function __construct(
    protected PresaveSafetyNet $presaveSafetyNet,
    protected ModuleHandlerInterface $moduleHandler,
    protected ClassResolverInterface $classResolver,
  ) {}

  /**
   * Implements hook_entity_presave().
   *
   * The deprecation-window safety net runs before the inline block
   * machinery so a block_serialized replacement written by a component
   * transform is saved (a content host) or stored as values (a display)
   * within this same save.
   *
   * @see \Drupal\daedalus_layout\ComponentTransform\PresaveSafetyNet
   */
  #[Hook('entity_presave')]
  #[RemoveHook('entity_presave', class: LayoutBuilderHooks::class, method: 'entityPresave')]
  public function entityPresave(EntityInterface $entity): void {
    $this->presaveSafetyNet->apply($entity);
    if ($this->moduleHandler->moduleExists('block_content')) {
      $this->classResolver->getInstanceFromDefinition(InlineBlockEntityOperations::class)->handlePreSave($entity);
    }
  }

}
