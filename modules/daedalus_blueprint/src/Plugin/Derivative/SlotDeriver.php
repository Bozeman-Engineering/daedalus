<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives one slot block per slot id the site declares.
 *
 * Slot ids are one namespace per site, the `slots` roster of
 * daedalus_blueprint.settings read through SlotRoster: a slot block exists
 * for every id the roster names (`daedalus_slot:{id}`), its admin label
 * the roster's label with the kind's word ("Header slot"): the word the
 * Add panel's Slots category offers and the door's name in the Page
 * elements tree (BlueprintElementAnnotator), where the type beside it
 * reads "Slot". The roster's tier is not the deriver's concern:
 * the block exists for every id, and where it may be placed is the offer's
 * question (PlacementRestrictions). A save of the roster rebuilds the
 * block definitions (SlotRosterSubscriber), so a new id is placeable at
 * once.
 */
final class SlotDeriver extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(protected SlotRoster $slotRoster) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static($container->get('daedalus_blueprint.slot_roster'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    foreach ($this->slotRoster->entries() as $id => $entry) {
      $this->derivatives[$id] = [
        'admin_label' => new TranslatableMarkup('@label slot', ['@label' => (string) $entry['label']]),
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
