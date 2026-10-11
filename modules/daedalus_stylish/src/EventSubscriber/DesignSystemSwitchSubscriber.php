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
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Drupal\daedalus_stylish\ShippedSync;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\SwatchStylesheet;
use Drupal\daedalus_stylish\VariantStylesheet;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Restyles the site when the active design system changes.
 *
 * A switch swaps the whole vocabulary and every member with it: the old
 * system's skin, variants and swatches leave the roster and the new
 * system's join it (global materials stay). Reacting at the config save
 * event of daedalus_blueprint.settings covers every writer (the activate
 * op and admin form through ActiveDesignSystem::activate(), drush
 * config-set, config sync) through one seam. Four things follow, in
 * this order:
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
 *   package's members that needed its own law were deferred while it was
 *   inactive, and a fresh install's batch waited for the installer
 *   (nothing landed); both land now, before the delta and the compiles
 *   below read the roster, so a recipe's activation action is what lands
 *   a fresh site whole. A pointer naming a system whose config has not
 *   landed syncs nothing (there is no law to sync under until it lands).
 * - Both systems' ids ride the response's materials delta: every token id
 *   the skin each system wears carries (SkinManager::loadSkinFor(): the
 *   site's choice for the system, else its default skin) and every
 *   member record each system has (MaterialsRosterBuilder::members(),
 *   the unpartitioned read). The delta resolves each id against the
 *   fresh roster, so the old system's ids resolve to removes and the new
 *   system's to upserts; a worn skin that does not exist yet contributes
 *   no token ids.
 * - The defaults, variants and swatches files recompile: each prints the
 *   roster's (partitioned) set, so all three change on a switch (the
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
    protected DefaultsStylesheet $defaultsStylesheet,
    protected VariantStylesheet $variantStylesheet,
    protected SwatchStylesheet $swatchStylesheet,
    protected MaterialsRosterBuilder $materialsRosterBuilder,
    protected MaterialsChangeSet $materialsChangeSet,
    protected SkinManager $skinManager,
    protected DaedalusStylishMaterialsProvider $materialsProvider,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected StyleGuide $styleGuide,
    protected ShippedSync $shippedSync,
  ) {}

  /**
   * Syncs the shipped definitions, recompiles delivery and records the delta.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if ($config->getName() !== ActiveDesignSystem::CONFIG_NAME || !$event->isChanged(ActiveDesignSystem::KEY)) {
      return;
    }
    $this->materialsRosterBuilder->reset();
    $this->styleGuide->resetCache();
    if ($this->loadSystem((string) $config->get(ActiveDesignSystem::KEY)) !== NULL) {
      $this->shippedSync->onDesignSystemActivated();
    }
    $ids = array_unique([
      (string) $config->getOriginal(ActiveDesignSystem::KEY),
      (string) $config->get(ActiveDesignSystem::KEY),
    ]);
    foreach (array_filter($ids, static fn (string $id): bool => $id !== '') as $id) {
      $this->touchSystem($id);
    }
    $this->defaultsStylesheet->compile();
    $this->variantStylesheet->compile();
    $this->swatchStylesheet->compile();
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
