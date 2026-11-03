<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_stylish\SkinStylesheet;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\daedalus_stylish\VariantStylesheet;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Recompiles delivery when daedalus_stylish's own settings change.
 *
 * Both compiled files depend on daedalus_stylish.settings: the
 * breakpoints thresholds are baked into the @container wrappers of
 * width-conditioned blocks, container_roots gates the page-container
 * rule (both files), and skin_choice names the skin the active design
 * system wears (SkinManager: the site's choice for the system while the
 * system may wear it, else its default_skin), so a choice swaps the
 * whole token set the skin file prints and the verdict every variant is
 * judged under. (The two config inputs of the LAW, which design system
 * is active (daedalus_blueprint.settings) and which theme is the default
 * (system.theme), are DesignSystemSwitchSubscriber's: each runs the full
 * sweep, the sync included, and recompiles both files among the rest.
 * The active unit's OWN save changing its default_skin or its
 * enforcement is the third way the law changes;
 * _daedalus_design_system_change() reacts to that.) Reacting at the
 * config save event covers every writer (admin form, the choose route
 * and command, drush config-set, config sync) through one seam.
 * Recompiling both files on any daedalus_stylish.settings save is
 * deliberately broad: the compiles are cheap and a byte-identical result
 * touches nothing but cache tags.
 *
 * A choice change does what a switch does for the skin alone: the
 * memoized roster drops (it carries the active skin's tokens and the
 * conformance verdict, and the compiles below read it), every token id
 * the skin worn before and the skin worn now carry ride the response's
 * materials delta, resolved against the fresh roster (the old skin's to
 * removes, the new skin's to upserts; a token both mint resolves to one
 * upsert), and every variant rides beside them (the chosen skin's mints
 * flip verdicts; DaedalusStylishMaterialsProvider::touchVariants()). The
 * skin worn before is judged from the save's ORIGINAL choice through the
 * same rule the read applies (SkinManager::resolveChoice()), so an
 * invalid old choice reads as the default it fell back to. Choices for
 * inactive systems change nothing worn and touch nothing.
 *
 * Both files compile here on every save of this config, the variants
 * file included: the module's entity types are installed before its
 * default config imports, so the compiles survive install order, and
 * with no design system active they load no skin and judge no law.
 */
final class DefaultsSettingsSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected SkinStylesheet $defaultsStylesheet,
    protected VariantStylesheet $variantStylesheet,
    protected ActiveDesignSystem $activeDesignSystem,
    protected SkinManager $skinManager,
    protected DaedalusStylishMaterialsProvider $materialsProvider,
    protected MaterialsRosterBuilder $materialsRosterBuilder,
    protected MaterialsChangeSet $materialsChangeSet,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Recompiles both files when daedalus_stylish.settings changes.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($config->getName() !== SkinManager::CONFIG_NAME) {
      return;
    }
    if ($event->isChanged(SkinManager::CHOICE_KEY)) {
      $this->onChoiceChange($event);
    }
    $this->defaultsStylesheet->compile();
    $this->variantStylesheet->compile();
  }

  /**
   * Drops the roster and records both skins' tokens and every variant.
   *
   * A choice change: the outgoing and incoming skins' tokens flip and the
   * verdict re-judges every variant under the incoming skin's mints.
   */
  protected function onChoiceChange(ConfigCrudEvent $event): void {
    $id = $this->activeDesignSystem->activeId();
    if ($id === NULL) {
      return;
    }
    $system = $this->entityTypeManager->getStorage(DesignSystem::ENTITY_TYPE_ID)->load($id);
    if (!$system instanceof DesignSystemInterface) {
      return;
    }
    $original = $event->getConfig()->getOriginal(SkinManager::CHOICE_KEY . '.' . $id);
    $before = $this->skinManager->resolveChoice($system, is_string($original) && $original !== '' ? $original : NULL);
    $after = $this->skinManager->skinNameFor($id);
    if ($before === $after) {
      return;
    }
    $this->materialsRosterBuilder->reset();
    foreach (array_filter([$before, $after]) as $name) {
      $skin = $this->skinManager->loadByMachineName($name);
      if ($skin !== NULL) {
        $this->materialsChangeSet->touch(MaterialKind::Token, ...$this->materialsProvider->tokenIds($skin));
      }
    }
    $this->materialsProvider->touchVariants($this->materialsChangeSet);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onConfigSave',
    ];
  }

}
