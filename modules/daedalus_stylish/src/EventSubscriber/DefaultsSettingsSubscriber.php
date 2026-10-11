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
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\SkinManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Recompiles the defaults stylesheet when config it bakes in changes.
 *
 * The compiled file depends on two config objects. daedalus_stylish.settings,
 * three ways: the breakpoints thresholds are baked into the @container
 * wrappers of width-conditioned blocks, container_roots gates the
 * page-container rule, and skin_choice names the skin the active design
 * system wears (SkinManager: the site's choice for the system while the
 * system may wear it, else its default_skin), so a choice swaps the
 * whole token set the file prints. system.theme, one way: the declared
 * tier compiles for the site's DEFAULT theme (declarations layer per
 * theme, and the file is one per site; see DefaultsStylesheet), so a
 * default-theme change swaps the layering the file serves. (WHICH
 * design system is active is daedalus_blueprint.settings;
 * DesignSystemSwitchSubscriber reacts to that. The active unit's OWN
 * save changing its default_skin is the third way the worn skin
 * changes; _daedalus_design_system_change() reacts to that.) Reacting
 * at the config save event covers every writer (admin form, the choose
 * route and command, drush config-set, config sync) through one seam.
 * Recompiling
 * on any daedalus_stylish.settings save is deliberately broad: the
 * compile is cheap and a byte-identical result touches nothing but
 * cache tags; the system.theme reaction is narrowed to the default key
 * because that config saves on unrelated admin-theme edits too.
 *
 * A choice change does what a switch does for the skin alone: the
 * memoized roster drops (it carries the active skin's tokens, and the
 * compile below reads it), and every token id the skin worn before and
 * the skin worn now carry ride the response's materials delta, resolved
 * against the fresh roster (the old skin's to removes, the new skin's
 * to upserts; a token both mint resolves to one upsert). The skin worn
 * before is judged from the save's ORIGINAL choice through the same
 * rule the read applies (SkinManager::resolveChoice()), so an invalid
 * old choice reads as the default it fell back to. Choices for
 * inactive systems change nothing worn and touch nothing.
 *
 * The VARIANTS and SWATCHES files share the daedalus_stylish.settings
 * dependencies (breakpoint thresholds and the container_roots gate bake
 * into them too) but are NOT recompiled here: their compiles always
 * query their entity storage (daedalus_variant, daedalus_swatch), which
 * does not exist yet when default config imports at module install. A
 * settings change therefore leaves those files stale until the next
 * variant/swatch save (or the next design-system switch), the accepted
 * staleness class breakpoints edits always had for the variants file.
 * (The DEFAULTS compile survives install-order: daedalus_stylish's
 * entity types are installed before its default config imports, and
 * with no design system active it loads no skin; the choice reaction is
 * install-order safe the same way, since nothing is active when the
 * default config lands.)
 */
final class DefaultsSettingsSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected DefaultsStylesheet $defaultsStylesheet,
    protected ActiveDesignSystem $activeDesignSystem,
    protected SkinManager $skinManager,
    protected DaedalusStylishMaterialsProvider $materialsProvider,
    protected MaterialsRosterBuilder $materialsRosterBuilder,
    protected MaterialsChangeSet $materialsChangeSet,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Recompiles when daedalus_stylish.settings or the default theme changes.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    $recompile = match ($config->getName()) {
      SkinManager::CONFIG_NAME => TRUE,
      'system.theme' => $event->isChanged('default'),
      default => FALSE,
    };
    if (!$recompile) {
      return;
    }
    if ($config->getName() === SkinManager::CONFIG_NAME && $event->isChanged(SkinManager::CHOICE_KEY)) {
      $this->onChoiceChange($event);
    }
    $this->defaultsStylesheet->compile();
  }

  /**
   * Drops the roster and records both worn skins' token ids on a choice.
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
