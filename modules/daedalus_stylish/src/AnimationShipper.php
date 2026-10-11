<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_stylish\Entity\DaedalusStylishAnimation;
use Drupal\daedalus_stylish\Exception\ShippedAnimationException;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\AnimationStepsItem;

/**
 * Ships site animations to disk: the animation kind of the shipper base.
 *
 * AnimationImporter brings MODULE.daedalus_stylish.animations*.yml INTO a site
 * (sync); this service takes a site's animations OUT to a module (ship),
 * writing MODULE.daedalus_stylish.animations.FILE_ID.yml and adopting each
 * animation in place with the written steps as its source hash. The
 * lane's mechanics (the whole-file rewrite, the shipped-elsewhere
 * refusal, the workspace escape, the adoption) live in
 * ShippedDefinitionsShipper; this class supplies the animation facts:
 * definition() in AnimationImporter's grammar, the canonical steps map as
 * the hashed slice (exactly what AnimationImporter::syncDefinition()
 * stamps, so a sync right after a ship reads UNCHANGED),
 * DaedalusStylishAnimation's hash, and ShippedAnimationException.
 *
 * The distro lock guards a shipped animation's steps on a consuming site
 * (AnimationManager::checkShippedLock()); the package author works with
 * the lock lifted (the developer gate), edits the animation in place, and
 * this lane carries the edit back to disk.
 */
class AnimationShipper extends ShippedDefinitionsShipper {

  /**
   * Builds an animation's shipped definition, in the importer's grammar.
   *
   * @return array
   *   label, description (only when set), ai: false (only when withheld
   *   from the AI), design_system (always: an omitted key would mean the
   *   target module's own system on the next sync, and the animation's
   *   membership is a fact to carry, ~ for global), then steps as stored
   *   (canonical offsets: a machine-written file never spells `from`).
   */
  public function definition(DaedalusStylishAnimationInterface $animation): array {
    $definition = [
      'label' => (string) $animation->label(),
    ];
    if ($animation->getDescription() !== '') {
      $definition['description'] = $animation->getDescription();
    }
    // The AI opt-out's one spelling: offered is the default, so the key
    // is written only to withhold (the importer refuses any other value).
    if (!$animation->isOffered()) {
      $definition['ai'] = FALSE;
    }
    $definition['design_system'] = $animation->getDesignSystem();
    $definition['steps'] = $animation->getSteps();
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'daedalus_animation';
  }

  /**
   * {@inheritdoc}
   *
   * The canonical steps map: what AnimationImporter stamps on sync
   * (DaedalusStylishAnimation::shippedData()), canonicalized here as the
   * importer does so a spelling is never a change. The module and the
   * name are unused: an animation's whole payload is in its file entry.
   */
  protected function shippedData(array $definition, string $module, string $name): array {
    return AnimationStepsItem::canonicalizeSteps($definition['steps']);
  }

  /**
   * {@inheritdoc}
   */
  protected function hash(array $data): string {
    return DaedalusStylishAnimation::hashData($data);
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedAnimationException {
    return new ShippedAnimationException($module, $name, $errors);
  }

}
