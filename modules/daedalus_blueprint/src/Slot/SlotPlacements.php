<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Slot;

use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\layout_builder\Section;

/**
 * Which slot ids the site's documents place, and which a roster would drop.
 *
 * The one judgement behind the slot roster guard. A slot block derives per
 * roster id (SlotDeriver, through SlotRoster, the roster's one reader), so
 * a slot id a frame or a template places as daedalus_slot:{id} must stay
 * in the roster (daedalus_blueprint.settings `slots`): a placed id the
 * roster no longer names resolves to core's broken block, a door nothing
 * renders into and nothing can fill. The roster's validation constraint
 * (SlotRosterPlacedConstraintValidator) and the config import door
 * (SlotRosterSubscriber) both ask here and only format the answer into
 * their own error channel.
 *
 * The placers are read as raw config records from a storage, the active
 * one by default, so the same reader serves an import's source storage:
 * every frame and every Layout Builder enabled entity view display, the
 * only two kinds of document that place a slot
 * (PlacementRestrictions::slotDestinationTier()), each section through
 * Section::fromArray() and the adapter's nested-aware component walk.
 * The slot id is read off the plugin id string on purpose: the question
 * is what the document places, not what the block manager can build
 * (DaedalusLayoutAdapter::declaredSlots() answers that, and would hide
 * exactly the dropped ids this judgement exists to name). The roster
 * itself is never read here; the caller hands over the roster it judges.
 */
final class SlotPlacements {

  /**
   * The entity type of a template: a Layout Builder enabled view display.
   */
  private const DISPLAY_ENTITY_TYPE_ID = 'entity_view_display';

  public function __construct(
    protected StorageInterface $configStorage,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DaedalusLayoutAdapter $adapter,
  ) {}

  /**
   * The placed slot ids a roster does not name, each to its placers.
   *
   * @param array<string, mixed> $roster
   *   The roster to judge, keyed by slot id; the ids alone are read.
   * @param \Drupal\Core\Config\StorageInterface|null $storage
   *   The storage to read the placers from, or NULL for the active one.
   *
   * @return array<string, string[]>
   *   Each missing slot id to the names of the config records placing it,
   *   frames before displays, each in its storage's listing order; empty
   *   when every placed id is rostered.
   */
  public function missing(array $roster, ?StorageInterface $storage = NULL): array {
    return array_diff_key($this->placed($storage), $roster);
  }

  /**
   * Every placed slot id, each to the config records placing it.
   *
   * @param \Drupal\Core\Config\StorageInterface|null $storage
   *   The storage to read the placers from, or NULL for the active one.
   *
   * @return array<string, string[]>
   *   Each placed slot id to the names of the config records placing it.
   */
  public function placed(?StorageInterface $storage = NULL): array {
    $placed = [];
    foreach ($this->placers($storage ?? $this->configStorage) as $name => $sections) {
      foreach ($this->adapter->components($sections) as $component) {
        $slot = SlotBlock::slotIdOf($component->getPluginId());
        if ($slot !== NULL && !in_array($name, $placed[$slot] ?? [], TRUE)) {
          $placed[$slot][] = $name;
        }
      }
    }
    return $placed;
  }

  /**
   * Whether a config name is a document that may place slots.
   *
   * A frame's or an entity view display's record; whether the display has
   * Layout Builder on is the record's to say, not the name's.
   */
  public function isPlacer(string $name): bool {
    foreach ([FrameInterface::ENTITY_TYPE_ID, self::DISPLAY_ENTITY_TYPE_ID] as $entity_type_id) {
      $prefix = $this->prefix($entity_type_id);
      if ($prefix !== NULL && str_starts_with($name, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The documents that place slots: each config name to its sections.
   *
   * @return \Generator<string, \Drupal\layout_builder\Section[]>
   *   Every frame, then every Layout Builder enabled view display.
   */
  private function placers(StorageInterface $storage): \Generator {
    foreach ($this->records($storage, FrameInterface::ENTITY_TYPE_ID) as $name => $record) {
      yield $name => $this->sections($record['sections'] ?? []);
    }
    foreach ($this->records($storage, self::DISPLAY_ENTITY_TYPE_ID) as $name => $record) {
      $layout_builder = $record['third_party_settings']['layout_builder'] ?? [];
      if (!empty($layout_builder['enabled'])) {
        yield $name => $this->sections($layout_builder['sections'] ?? []);
      }
    }
  }

  /**
   * A storage's records of a config entity type, by config name.
   *
   * @return array[]
   *   The records; none for a type the site does not define.
   */
  private function records(StorageInterface $storage, string $entity_type_id): array {
    $prefix = $this->prefix($entity_type_id);
    return $prefix === NULL ? [] : $storage->readMultiple($storage->listAll($prefix));
  }

  /**
   * The config name prefix of a config entity type, dot included.
   *
   * @return string|null
   *   The prefix, or NULL for a type the site does not define. The frame
   *   type exists only with Layout Builder (its class reads that module's
   *   section list), and a kind of document the site does not have places
   *   no slot: the roster of a site without it validates on its own keys.
   */
  private function prefix(string $entity_type_id): ?string {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    return $definition === NULL ? NULL : $definition->getConfigPrefix() . '.';
  }

  /**
   * Hydrates stored section arrays, the mapping FrameStorage reads with.
   *
   * @param array[] $records
   *   The stored sections.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections.
   */
  private function sections(array $records): array {
    return array_map([Section::class, 'fromArray'], $records);
  }

}
