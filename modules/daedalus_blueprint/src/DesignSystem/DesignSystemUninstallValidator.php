<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;

/**
 * Refuses to uninstall a package whose design system is still in use.
 *
 * A packaged design system leaves with its module (it depends on it), so
 * the module may not go while the system is active (the pointer would
 * name nothing) or while members still reference the system (they would
 * be stranded on a system that no longer exists). Activate another
 * system and remove or reassign the members first. The same rule
 * DesignSystem::preDelete() applies to deleting the entity by hand.
 */
final class DesignSystemUninstallValidator implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  public function __construct(
    protected ActiveDesignSystem $activeDesignSystem,
    protected MaterialsRosterBuilder $rosterBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate($module) {
    $reasons = [];
    $active = $this->activeDesignSystem->activeId();
    foreach ($this->activeDesignSystem->packages() as $id => $package) {
      if ($package !== $module) {
        continue;
      }
      if ($id === $active) {
        $reasons[] = $this->t('The design system %id it packages is active; activate another design system first.', ['%id' => $id]);
        continue;
      }
      $members = count($this->rosterBuilder->members($id));
      if ($members > 0) {
        $reasons[] = $this->formatPlural($members, 'The design system %id it packages still has 1 member; remove the member or make it global first.', 'The design system %id it packages still has @count members; remove them or make them global first.', ['%id' => $id]);
      }
    }
    return $reasons;
  }

}
