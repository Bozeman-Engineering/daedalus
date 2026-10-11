<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\layout_builder\Section;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\daedalus_stylish\VariantApplicabilityCheckerInterface;

/**
 * The ownership half of daedalus_stylish's variant applicability seam.
 *
 * A blueprint's owned variants are its CURATED switchable looks: they apply
 * only to elements inside that blueprint's placements. This checker answers
 * daedalus_stylish's "may this variant apply to this target?" question from the
 * server-readable facts — the ownership fields this module provides on
 * daedalus_variant (BlueprintVariantFieldHooks) and the placement provenance
 * stamps the create ops write (a section root's third-party settings, a
 * block root's additional data; LayoutSerializer surfaces them as
 * the read-only `blueprint` node key the client's offer filter walks). Same
 * walk, same precedence, same degraded posture as the client
 * (js/src/variant-scope.js blueprintProvenance): the nearest self-or-ancestor
 * stamp wins, nested stamps shadow, and a target the layout does not carry
 * reads as unprovenanced — owned variants then refuse, never over-permit.
 *
 * Only NEW applies land here (the seam's contract): existing wearers keep
 * validating and rendering wherever they are, and orphaned variants (owner
 * deleted or undiscoverable) refuse everywhere, the offer-nowhere parity.
 *
 * Registered by DaedalusBlueprintLibraryServiceProvider, and only when daedalus_stylish
 * is installed: this class cannot even LOAD without daedalus_stylish (the implemented
 * interface lives there), and container compilation reflects every registered
 * service class, so a plain services.yml entry fatals a daedalus-stylish-less
 * container. Lazy tag collection is no protection; compile-time reflection is
 * unconditional.
 */
class BlueprintVariantOwnershipChecker implements VariantApplicabilityCheckerInterface {

  public function __construct(
    protected LayoutSerializer $layoutSerializer,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
    protected BlueprintDiscoveryInterface $discovery,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function check(DaedalusStylishVariantInterface $variant, HostInterface $host, string $key): array {
    // Owner resolution mirrors VariantManager::variantPayload(): the
    // content lane's entity reference (a set target that no longer loads is
    // an orphan), then the file lane's machine name (alive iff discovery
    // still serves it). Neither field set = unowned, no opinion. The
    // content owner loads through storage by target id, never through the
    // reference item's cached entity object: the variant arrives from the
    // repository's request-lifetime identity map, whose item may still
    // hold a blueprint deleted later in the same request (a batch that
    // deletes then applies), and a deleted owner must read as an orphan.
    if ($variant->hasField(BlueprintInterface::OWNER_FIELD) && !$variant->get(BlueprintInterface::OWNER_FIELD)->isEmpty()) {
      $target_id = $variant->get(BlueprintInterface::OWNER_FIELD)->target_id;
      $owner_entity = $target_id !== NULL ? $this->entityTypeManager->getStorage(BlueprintInterface::ENTITY_TYPE_ID)->load($target_id) : NULL;
      $owner = $owner_entity instanceof BlueprintInterface ? $owner_entity->getMachineName() : NULL;
    }
    elseif ($variant->hasField(BlueprintInterface::FILE_OWNER_FIELD) && ($file_owner = (string) ($variant->get(BlueprintInterface::FILE_OWNER_FIELD)->value ?? '')) !== '') {
      $owner = is_array($this->discovery->getBlueprint($file_owner)) ? $file_owner : NULL;
    }
    else {
      return [];
    }

    $name = $variant->getMachineName();
    if ($owner === NULL) {
      return [
        new BlueprintError('variant', 'VARIANT_OWNER_ORPHANED', sprintf('The "%s" variant belongs to a blueprint that no longer exists.', $name), [], 'An orphaned owned variant applies nowhere new (existing wearers keep rendering). Copy its styles into a new variant to keep using the look.'),
      ];
    }
    if ($this->placementProvenance($host, $key) === $owner) {
      return [];
    }
    return [
      new BlueprintError('variant', 'VARIANT_OWNED_BY_BLUEPRINT', sprintf('The "%s" variant belongs to the "%s" blueprint and applies only inside its placements.', $name, $owner), ['blueprint' => $owner], sprintf('Target an element inside a "%s" blueprint placement, apply a variant the element\'s own scope offers, or release the variant from its blueprint to make it general-purpose again.', $owner)),
    ];
  }

  /**
   * A target key's blueprint provenance: the nearest self-or-ancestor stamp.
   *
   * The server twin of the client's blueprintProvenance() walk. Stamp
   * precedence per node follows what the serializer emits
   * (LayoutSerializer::serializeElement()): a wrapper component's
   * additional-data stamp beats its embedded section's third-party-settings
   * stamp beats the inherited ancestor stamp. NULL means unprovenanced —
   * a key with no placeable uuid (entity/field keys), an element the
   * layout does not carry, or no layout at all — and the caller refuses
   * owned variants on it (degraded never over-permits).
   */
  protected function placementProvenance(HostInterface $host, string $key): ?string {
    $uuid = $this->targetUuid($key);
    if ($uuid === NULL) {
      return NULL;
    }
    foreach ($this->layoutSerializer->getSections($host) as $section) {
      if (!$section instanceof Section) {
        continue;
      }
      [$found, $stamp] = $this->walkSection($section, $this->sectionStamp($section), $uuid);
      if ($found) {
        return $stamp;
      }
    }
    return NULL;
  }

  /**
   * Walks one section for the target uuid, carrying the effective stamp.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section to walk.
   * @param string|null $inherited
   *   The section's own effective stamp (its wrapper/TPS stamp, or the
   *   nearest ancestor's).
   * @param string $uuid
   *   The target element uuid.
   *
   * @return array{0: bool, 1: string|null}
   *   [TRUE, stamp] when the uuid lives in this section's subtree.
   */
  protected function walkSection(Section $section, ?string $inherited, string $uuid): array {
    if ($this->daedalusLayoutAdapter->getSectionUuid($section) === $uuid) {
      return [TRUE, $inherited];
    }
    foreach ($section->getComponents() as $component) {
      $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
      $stamp = $component->get(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY);
      $stamp = is_string($stamp) && $stamp !== '' ? $stamp : NULL;
      if ($stamp === NULL && $embedded !== NULL) {
        $stamp = $this->sectionStamp($embedded);
      }
      $effective = $stamp ?? $inherited;
      if ($component->getUuid() === $uuid) {
        return [TRUE, $effective];
      }
      if ($embedded !== NULL) {
        [$found, $result] = $this->walkSection($embedded, $effective, $uuid);
        if ($found) {
          return [TRUE, $result];
        }
      }
    }
    return [FALSE, NULL];
  }

  /**
   * A section's own third-party-settings provenance stamp, or NULL.
   */
  protected function sectionStamp(Section $section): ?string {
    $stamp = $section->getThirdPartySetting(LayoutSerializer::BLUEPRINT_PROVENANCE_KEY, 'blueprint');
    return is_string($stamp) && $stamp !== '' ? $stamp : NULL;
  }

  /**
   * The placeable uuid a selectable key targets, or NULL.
   *
   * Only block and container keys live in the layout tree (a block field
   * key rides its block); entity and entity-field keys are the host's own
   * surface, never a placement. A rule key ("{key}::{selector}") targets
   * its root: ownership is the placed subtree's, and a rule's matches
   * live inside the root's placement, so the rule is judged where its
   * owner is. A suffix outside the grammar is no key (NULL, and owned
   * variants are refused: degraded never over-permits).
   */
  protected function targetUuid(string $key): ?string {
    $split = RelativeSelector::splitKey($key);
    if ($split === NULL) {
      return NULL;
    }
    $parts = explode(':', $split[0]);
    if (count($parts) >= 2 && in_array($parts[0], ['block', 'container'], TRUE) && $parts[1] !== '') {
      return $parts[1];
    }
    return NULL;
  }

}
