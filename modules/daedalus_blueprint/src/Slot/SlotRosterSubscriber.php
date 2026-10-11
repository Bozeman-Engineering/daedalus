<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Slot;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\StorageComparerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The slot roster's two config-event doors: the save and the import.
 *
 * The save door (ConfigEvents::SAVE) rebuilds the block definitions when
 * the roster changes. A slot block derives per roster id (SlotDeriver),
 * and the block plugin definitions are cached without config
 * dependencies, so a roster save (config sync, drush config-set, a future
 * settings form) must drop them for the new id to be placeable and a
 * removed one to vanish from the offer. Reacting at the config save event
 * covers every writer through one seam, the way the active design
 * system's pointer does.
 *
 * The import door (ConfigEvents::IMPORT_VALIDATE) is the slot roster
 * guard's refusal for config synchronization. Core's importer validates
 * modules, themes and dependencies and never a typed-data constraint, so
 * the DaedalusSlotRosterPlaced constraint on the roster, which a recipe's
 * config action and a config form meet, would let `drush config:import`
 * stage a roster that drops a slot id a frame or a template still places.
 * When the staged change list touches the roster or a placer, the
 * judgement (SlotPlacements) runs over the import's source storage, the
 * staged roster against the staged placers, and each dropped id is logged
 * as an import error in the constraint's own words, so the import is
 * refused before anything is written, the way core refuses a missing
 * module.
 */
final class SlotRosterSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    protected BlockManagerInterface $blockManager,
    protected SlotPlacements $slotPlacements,
  ) {}

  /**
   * Drops the block definitions when the roster changed.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() !== SlotBlock::CONFIG_NAME || !$event->isChanged(SlotBlock::ROSTER_KEY)) {
      return;
    }
    $this->blockManager->clearCachedDefinitions();
  }

  /**
   * Refuses an import whose staged roster drops a slot id a document places.
   */
  public function onImportValidate(ConfigImporterEvent $event): void {
    $importer = $event->getConfigImporter();
    $comparer = $importer->getStorageComparer();
    if (!$this->touchesRosterOrPlacers($comparer)) {
      return;
    }
    $source = $comparer->getSourceStorage();
    $settings = $source->read(SlotBlock::CONFIG_NAME);
    if ($settings === FALSE) {
      // The roster leaves with its module; what depends on the module is
      // core's dependency validation to settle.
      return;
    }
    foreach ($this->slotPlacements->missing($settings[SlotBlock::ROSTER_KEY] ?? [], $source) as $slot => $placers) {
      // The wording of SlotRosterPlacedConstraint::$message, kept in step by
      // hand because the translation sniff wants a literal here.
      $importer->logError($this->t("The slot '@slot' is placed by @placers and must stay in the roster.", [
        '@slot' => $slot,
        '@placers' => implode(', ', $placers),
      ]));
    }
  }

  /**
   * Whether an import's change list touches the roster or a placer.
   *
   * An import that stages neither can change nothing the judgement reads,
   * and is not refused for a roster the active storage already got wrong
   * through a writer core never validates.
   */
  private function touchesRosterOrPlacers(StorageComparerInterface $comparer): bool {
    foreach ($comparer->getChangelist() as $op => $names) {
      foreach ($names as $name) {
        $candidates = $op === 'rename' ? $comparer->extractRenameNames($name) : [$name];
        foreach ($candidates as $candidate) {
          if ($candidate === SlotBlock::CONFIG_NAME || $this->slotPlacements->isPlacer($candidate)) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onConfigSave',
      ConfigEvents::IMPORT_VALIDATE => 'onImportValidate',
    ];
  }

}
