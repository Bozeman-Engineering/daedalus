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
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\SkinStylesheet;
use Drupal\daedalus_stylish\ShippedSync;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\VariantStylesheet;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Restyles the site when the law changes through config.
 *
 * The site's law has two config inputs: the active design system and the
 * default theme (the guide layers the active package's declarations and
 * the default theme's chain). Each change runs the one sweep below.
 *
 * A switch swaps the whole vocabulary and every member with it: the old
 * system's skin and variants leave the roster and the new
 * system's join it (global materials stay). The active system is the one
 * the pointer (daedalus_blueprint.settings) names AND whose entity has
 * landed (ActiveDesignSystem::load()), so two config saves change it:
 * the pointer's, and the insert of the design_system entity the pointer
 * already names. The second is config import's order (an install from
 * exported config, `drush cim`): the pointer is simple config, written
 * as its module installs, and the entity imports afterwards, so the
 * landing is the activation there. Reacting at the config save event
 * covers every writer (the activate op and admin form through
 * ActiveDesignSystem::activate(), drush config-set, config sync, a
 * recipe's config import) through one seam.
 *
 * A default theme change (system.theme's default key) changes which
 * theme chain layers the guide; the skins kind waits while the named
 * default is not installed (SkinImporter::themeAwaited()), so the save
 * that names an installed theme is what lands a package's skin on an
 * install from exported config (the installer's stark stands in until the
 * config phase imports system.theme, after the package's system has
 * landed). The same sweep runs, without the systems' delta touches (the
 * skin landing's own hooks ride the delta). The other half of that
 * landing, the named theme being installed, is hook_themes_installed()'s
 * (daedalus_stylish_themes_installed()).
 *
 * Four things follow a switch, in this order:
 *
 * - The memoized roster and the style guide drop, so everything below
 *   and any validation later in the request read the newly active set
 *   and the newly active law. The guide reset reaches daedalus_ui's
 *   layered declarations (the active package is a layer of its own, so a
 *   switch changes the provider set), which makes this subscriber
 *   independent of the order the other subscribers on this event run in
 *   (daedalus_blueprint's drops the roster too, daedalus_ui's the
 *   declarations; none of the three is prioritized).
 * - Every enabled module's shipped definitions re-sync (ShippedSync::
 *   onDesignSystemActivated()) under the incoming system's law: the
 *   package's members, deferred whole while their system was inactive,
 *   land now, before the delta and the compiles below read the roster,
 *   so a recipe's activation action is what lands a fresh site's package
 *   (the globals landed with their install batches; the ones the old law
 *   withheld are simply re-judged by the roster's verdict under the new
 *   one). A pointer naming a system whose config has not landed syncs
 *   nothing (there is no law to sync under until it lands); the landing
 *   is the switch then, and syncs.
 * - Both systems' ids ride the response's materials delta: every token id
 *   the skin each system wears carries (SkinManager::loadSkinFor(): the
 *   site's choice for the system, else its default skin) and every
 *   member record each system has (MaterialsRosterBuilder::members(),
 *   the unpartitioned read). The delta resolves each id against the
 *   fresh roster, so the old system's ids resolve to removes and the new
 *   system's to upserts; a worn skin that does not exist yet contributes
 *   no token ids. Every published variant rides too, once, after the
 *   sync (DaedalusStylishMaterialsProvider::touchVariants()): the sweep
 *   is a law change and the conformance verdict a projection of the
 *   law, so a global the old law withheld and the new one offers (or
 *   the reverse) reaches an open editor on the same delta as the
 *   members; the ids resolve the same way, offered to upserts, withheld
 *   or inactive to removes.
 * - The defaults and variants files recompile: each prints the
 *   roster's (partitioned) set, so both change on a switch (the
 *   defaults file twice over: its declared tier follows the guide reset
 *   above, since a package's declarations layer only while its system
 *   is active). The animations file prints the repository's published
 *   set and is unaffected. Compiling here is install-order safe: the setting's
 *   install default is NULL and isChanged() is false for NULL to NULL,
 *   so the only saves that reach the compiles are real switches, by
 *   which time the daedalus_stylish storages exist.
 */
final class DesignSystemSwitchSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected SkinStylesheet $defaultsStylesheet,
    protected VariantStylesheet $variantStylesheet,
    protected MaterialsRosterBuilder $materialsRosterBuilder,
    protected MaterialsChangeSet $materialsChangeSet,
    protected SkinManager $skinManager,
    protected DaedalusStylishMaterialsProvider $materialsProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StyleGuide $styleGuide,
    protected ShippedSync $shippedSync,
    protected ActiveDesignSystem $activeDesignSystem,
  ) {}

  /**
   * Syncs the shipped definitions, recompiles delivery and records the delta.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($config->getName() === ActiveDesignSystem::CONFIG_NAME) {
      if ($event->isChanged(ActiveDesignSystem::KEY)) {
        $this->switch((string) $config->getOriginal(ActiveDesignSystem::KEY), (string) $config->get(ActiveDesignSystem::KEY));
      }
      return;
    }
    if ($config->getName() === 'system.theme') {
      if ($event->isChanged('default')) {
        $this->sweep(fn () => $this->shippedSync->onDefaultThemeChanged(), []);
      }
      return;
    }
    // The pointer's system landing: nothing was active (the pointer named
    // a system that did not exist), now this one is. An update to a landed
    // system is not a switch: its id is not changed past the insert.
    $id = $this->activeDesignSystem->activeId();
    if ($id !== NULL && $config->getName() === $this->systemConfigName($id) && $event->isChanged('id')) {
      $this->switch('', $id);
    }
  }

  /**
   * Restyles the site from one active system to another ('' for none).
   */
  protected function switch(string $outgoing, string $incoming): void {
    $this->sweep(
      $this->loadSystem($incoming) !== NULL ? fn () => $this->shippedSync->onDesignSystemActivated() : NULL,
      array_filter(array_unique([$outgoing, $incoming]), static fn (string $id): bool => $id !== ''),
    );
  }

  /**
   * The one sweep a law change runs: reset, sync, touch, recompile.
   *
   * @param callable|null $sync
   *   The ShippedSync entry to run under the fresh law, or NULL when there
   *   is no law to sync under yet (a pointer at a system not landed).
   * @param string[] $touch
   *   The design system ids whose token and member ids ride the delta.
   */
  protected function sweep(?callable $sync, array $touch): void {
    $this->materialsRosterBuilder->reset();
    $this->styleGuide->resetCache();
    if ($sync !== NULL) {
      $sync();
    }
    foreach ($touch as $id) {
      $this->touchSystem($id);
    }
    $this->materialsProvider->touchVariants($this->materialsChangeSet);
    $this->defaultsStylesheet->compile();
    $this->variantStylesheet->compile();
  }

  /**
   * The config name a design system entity saves under.
   */
  protected function systemConfigName(string $id): string {
    return $this->entityTypeManager->getDefinition(DesignSystem::ENTITY_TYPE_ID)->getConfigPrefix() . '.' . $id;
  }

  /**
   * Loads a design system by id; NULL for no id or a system not landed.
   *
   * The pointer may name a system whose config has not arrived yet (a
   * recipe's activation action ahead of its config import, config sync's
   * own order): nothing to sync or touch for it until it lands.
   */
  protected function loadSystem(string $id): ?DesignSystemInterface {
    if ($id === '') {
      return NULL;
    }
    $system = $this->entityTypeManager->getStorage(DesignSystem::ENTITY_TYPE_ID)->load($id);
    return $system instanceof DesignSystemInterface ? $system : NULL;
  }

  /**
   * Records a system's token and member ids on the request's change set.
   */
  protected function touchSystem(string $id): void {
    $skin = $this->skinManager->loadSkinFor($id);
    if ($skin !== NULL) {
      $this->materialsChangeSet->touch(MaterialKind::Token, ...$this->materialsProvider->tokenIds($skin));
      $this->materialsChangeSet->touch(MaterialKind::Animation, ...array_keys($skin->animations()));
      $this->materialsChangeSet->touch(MaterialKind::Font, ...array_keys($skin->fonts()));
    }
    foreach ($this->materialsRosterBuilder->members($id) as $record) {
      $this->materialsChangeSet->touch($record->kind, $record->id);
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
