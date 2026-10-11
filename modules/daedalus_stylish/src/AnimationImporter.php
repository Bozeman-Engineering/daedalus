<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\ShippedAnimationException;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\AnimationStepsItem;

/**
 * Imports and syncs module-shipped animations: the motion disk lane.
 *
 * A module ships default animations in a MODULE.daedalus_stylish.animations.yml at
 * its root (next to its style guide YAML), keyed by machine name:
 * @code
 * fade_in:
 *   label: 'Fade in'
 *   description: 'A soft entrance for page bands.'
 *   steps:
 *     from: { opacity: '0' }
 *     to: { opacity: '1' }
 * rise:
 *   label: 'Rise'
 *   steps:
 *     '0%': { opacity: '0', transform: 'translateY(1rem)' }
 *     '100%': { opacity: '1', transform: 'none' }
 * @endcode
 * The machine name IS the keyframes ident tail (motion-{name}), so it must
 * fit DaedalusStylishAnimation::MACHINE_NAME. Every definition rides the blueprint
 * spine exactly like an AI- or UI-authored animation (the animation steps
 * field handler, converging on
 * StyleMapValidator::validateAnimationSteps()), and the importer saves
 * live and published (see the base class for the workspace escape and
 * admin account rationale).
 *
 * Sync semantics are the variants lane's "one hash, two comparisons", with
 * the hash over the STEPS map alone (DaedalusStylishAnimation::shippedData(), the
 * variant precedent: the structure is the shipped fact; label and
 * description are metadata the sync never fights the user over). The disk
 * steps are canonicalized before every comparison
 * (AnimationStepsItem::canonicalizeSteps(): `from` is `0%`), because the
 * entity stores canonical offsets and a spelling difference is not a
 * change.
 */
class AnimationImporter extends ShippedDefinitionsImporter {

  /**
   * The keys a shipped animation definition must carry.
   *
   * The FILE grammar (which keys exist, their PHP types) is the importer's;
   * the steps' shape is validated by the spine and the entity.
   */
  protected const REQUIRED_KEYS = ['label', 'steps'];

  /**
   * The keys a shipped animation definition may carry.
   */
  protected const OPTIONAL_KEYS = ['description', 'ai', 'design_system'];

  /**
   * {@inheritdoc}
   */
  public function kind(): string {
    return 'animation';
  }

  /**
   * {@inheritdoc}
   */
  public function fileSuffix(): string {
    return 'daedalus_stylish.animations.yml';
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedAnimationException {
    return new ShippedAnimationException($module, $name, $errors);
  }

  /**
   * Reads a shipped animation's current disk steps (the revert source).
   *
   * The read behind the shipped-state comparisons, the distro lock
   * (AnimationManager::checkShippedLock()), and the revert gesture: all
   * three need "what does the provider ship RIGHT NOW", not what was
   * shipped at the last sync (the stored hash covers that).
   *
   * @return array|null
   *   The shipped steps, canonical offsets, or NULL when the animation has
   *   no disk source or its provider no longer ships an entry under its
   *   machine name (sync never deletes, so such animations live on as
   *   ordinary site content: no shipped state, no revert, no lock).
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedAnimationException
   *   When the shipped entry is malformed; a broken shipped file is a
   *   developer error, never skippable.
   */
  public function getShippedData(DaedalusStylishAnimationInterface $animation): ?array {
    $provider = $animation->getProvider();
    if ($provider === NULL) {
      return NULL;
    }
    $name = $animation->getMachineName();
    $definitions = $this->getShippedDefinitions($provider);
    if (!array_key_exists($name, $definitions)) {
      return NULL;
    }
    $this->checkDefinitionShape($provider, $name, $definitions[$name]);
    return AnimationStepsItem::canonicalizeSteps($definitions[$name]['steps']);
  }

  /**
   * Syncs one shipped animation; see the class doc for the semantics.
   *
   * @return string
   *   One of the CREATED/UPDATED/UNCHANGED/KEPT constants.
   */
  protected function syncDefinition(string $module, string $name, mixed $definition, int $uid): string {
    $this->checkDefinitionShape($module, $name, $definition);
    // The shipped payload in the entity's own shape
    // (DaedalusStylishAnimation::shippedData()): the canonical steps map.
    $shipped = AnimationStepsItem::canonicalizeSteps($definition['steps']);

    $storage = $this->entityTypeManager->getStorage('daedalus_animation');
    $existing = $storage->loadByProperties(['machine_name' => $name]);
    $animation = reset($existing) ?: NULL;
    assert($animation === NULL || $animation instanceof DaedalusStylishAnimationInterface);
    $design_system = $this->designSystemFor($module, $name, $definition);

    if ($animation !== NULL && $animation->getProvider() !== $module) {
      throw new ShippedAnimationException($module, $name, [
        new BlueprintError($name, 'SHIPPED_ANIMATION_CONFLICT', sprintf('The machine name "%s" already belongs to %s.', $name, $animation->getProvider() === NULL ? 'an animation minted on this site' : sprintf('an animation shipped by the "%s" module', $animation->getProvider())), [], 'Machine names are the site-wide motion vocabulary (each is the motion-{name} keyframes ident tail); rename the shipped animation.'),
      ]);
    }

    if ($animation === NULL) {
      $animation = $this->deserialize($module, $name, [
        'entity_type' => 'daedalus_animation',
        'bundle' => 'daedalus_animation',
        'fields' => [
          'label' => $definition['label'],
          'machine_name' => $name,
          'description' => $definition['description'] ?? '',
          'design_system' => $design_system,
          'steps' => $shipped,
        ],
      ]);
      $this->syncOffered($animation, $definition);
      $animation->setShippedSource($module, $shipped);
      $animation->setPublished();
      $animation->save();
      return self::CREATED;
    }

    // Membership and the AI opt-out are part of the shipped face and sync
    // independently of the steps hash (see the base class).
    if (!$animation->hasNewDefaults($shipped)) {
      $changed = $this->syncOffered($animation, $definition);
      if ($this->syncMembership($animation, $design_system) || $changed) {
        $animation->save();
      }
      return self::UNCHANGED;
    }
    if ($animation->isModified()) {
      $changed = $this->syncOffered($animation, $definition);
      if ($this->syncMembership($animation, $design_system) || $changed) {
        $animation->save();
      }
      return self::KEPT;
    }

    // Unmodified animation, new steps on disk: auto-sync as a new
    // revision. The update rides the deserializer too, the same spine
    // validation as the create, with the existing entity addressed by
    // machine name.
    $animation = $this->deserialize($module, $name, [
      'entity_type' => 'daedalus_animation',
      'bundle' => 'daedalus_animation',
      '_root_entity' => ['field' => 'machine_name', 'value' => $name],
      'fields' => [
        'label' => $definition['label'],
        'description' => $definition['description'] ?? '',
        'design_system' => $design_system,
        'steps' => $shipped,
      ],
    ]);
    $animation->setNewRevision(TRUE);
    $animation->setRevisionUserId($uid);
    $animation->setRevisionCreationTime($this->time->getRequestTime());
    $animation->setRevisionLogMessage(sprintf('Synced new shipped defaults from the %s module.', $module));
    $this->syncOffered($animation, $definition);
    $animation->setShippedSource($module, $shipped);
    $animation->setPublished();
    $animation->save();
    return self::UPDATED;
  }

  /**
   * Deserializes a shipped definition through the blueprint spine, loudly.
   */
  protected function deserialize(string $module, string $name, array $blueprint): DaedalusStylishAnimationInterface {
    // shipped_sync: disk is the one author the distro lock protects, so the
    // importer's own writes pass the steps field handler's read-only gate
    // (AnimationStepsFieldHandler); every other blueprint write refuses.
    // A shipped sync acts for the site (files on disk are the author), so
    // the capability gate is not asked: the system posture.
    $result = $this->deserializer->deserialize($blueprint, ['shipped_sync' => TRUE, 'system' => TRUE]);
    if (!$result->isSuccess()) {
      throw new ShippedAnimationException($module, $name, $result->getErrors());
    }
    $animation = $result->getEntity();
    assert($animation instanceof DaedalusStylishAnimationInterface);
    return $animation;
  }

  /**
   * Enforces the animations-file grammar: the keys and their PHP types.
   *
   * The FILE grammar only; the steps' CSS shape is validated by the spine
   * (the animation steps field handler) during deserialization.
   */
  public function checkDefinitionShape(string $module, string $name, mixed $definition): void {
    if (!is_array($definition) || array_is_list($definition)) {
      throw new ShippedAnimationException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_ANIMATION', sprintf('The "%s" entry is not an animation definition.', $name), [], 'Each entry maps a machine name to label and steps keys, with an optional description.'),
      ]);
    }
    if ($unknown = array_diff(array_keys($definition), self::REQUIRED_KEYS, self::OPTIONAL_KEYS)) {
      throw new ShippedAnimationException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_ANIMATION', sprintf('Unknown key(s) %s in the "%s" entry.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $name), [], 'A shipped animation definition consists of label and steps, plus an optional description, design_system, and ai: false (the AI opt-out).'),
      ]);
    }
    if ($missing = array_diff(self::REQUIRED_KEYS, array_keys($definition))) {
      throw new ShippedAnimationException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_ANIMATION', sprintf('The "%s" entry is missing the %s key(s).', $name, implode(', ', array_map(static fn ($key) => "\"$key\"", $missing))), [], 'A shipped animation definition consists of label and steps, plus an optional description, design_system, and ai: false (the AI opt-out).'),
      ]);
    }
    if (!is_string($definition['label']) || trim($definition['label']) === '' || (isset($definition['description']) && !is_string($definition['description']))) {
      throw new ShippedAnimationException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_ANIMATION', sprintf('The "%s" entry has the wrong shape.', $name), [], 'label is a non-empty string; description, when given, a string.'),
      ]);
    }
    $steps = $definition['steps'];
    if (!is_array($steps) || array_is_list($steps) || $steps === []) {
      throw new ShippedAnimationException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_ANIMATION', sprintf('The "%s" entry has the wrong shape.', $name), [], 'steps is a non-empty map of keyframe offsets ("from", "to", "50%") to property maps ({"0%": {"opacity": "0"}, "100%": {"opacity": "1"}}).'),
      ]);
    }
    $this->checkOfferedShape($module, $name, $definition, 'INVALID_SHIPPED_ANIMATION');
    $this->checkDesignSystemShape($module, $name, $definition, 'INVALID_SHIPPED_ANIMATION');
  }

}
