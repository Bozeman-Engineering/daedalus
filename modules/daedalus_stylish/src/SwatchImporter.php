<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\ShippedSwatchException;

/**
 * Imports and syncs module-shipped swatches: the palette's disk lane.
 *
 * A module ships default swatches in a MODULE.daedalus_stylish.swatches.yml at its
 * root (next to its style guide YAML), keyed by machine name:
 * @code
 * brand_red:
 *   label: 'Brand red'
 *   kind: color
 *   value: '#e94560'
 *   description: 'The one accent: the call to action and nothing else.'
 * hero_fade:
 *   label: 'Hero fade'
 *   kind: gradient
 *   design_system: ~       # optional; omitted means the module's own system
 *   value:
 *     default: 'linear-gradient(160deg, #1a1a2e, #e94560)'
 *     dark: 'linear-gradient(160deg, #0a0a14, #712235)'
 * @endcode
 * The machine name IS the custom property tail (--swatch-{name}), so it
 * must fit DaedalusStylishSwatch::MACHINE_NAME. Every definition rides the
 * blueprint spine exactly like an AI- or UI-authored swatch (the swatch
 * value field handler, converging on
 * StyleMapValidator::validateSwatchValue()), and the importer saves live
 * and published (see the base class for the workspace escape and admin
 * account rationale).
 *
 * Sync semantics are the variants lane's "one hash, two comparisons", with
 * the hash over the WHOLE shipped payload — the keyed {kind, value} shape
 * (DaedalusStylishSwatch::shippedData()) — so a kind change on disk reads as new
 * defaults and a user editing the value reads as modified. The optional
 * description is the shipped face, not the payload: it syncs whenever
 * the key is authored and the stored text differs, independently of the
 * hash (the variants lane's rule, see syncDescription()).
 */
class SwatchImporter extends ShippedDefinitionsImporter {

  /**
   * The keys a shipped swatch definition must carry.
   *
   * The FILE grammar (which keys exist, their PHP types) is the importer's;
   * the kind vocabulary and the value's shape are validated by the spine
   * and the entity.
   */
  protected const REQUIRED_KEYS = ['label', 'kind', 'value'];

  /**
   * The optional definition keys: the description and the membership.
   */
  protected const OPTIONAL_KEYS = ['description', 'ai', 'design_system'];

  /**
   * {@inheritdoc}
   */
  public function kind(): string {
    return 'swatch';
  }

  /**
   * {@inheritdoc}
   */
  public function fileSuffix(): string {
    return 'daedalus_stylish.swatches.yml';
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedSwatchException {
    return new ShippedSwatchException($module, $name, $errors);
  }

  /**
   * Reads a shipped swatch's current disk payload (the revert source).
   *
   * The read behind the shipped-state comparisons, the distro lock
   * (SwatchManager::checkShippedLock()), and the revert gesture: all three
   * need "what does the provider ship RIGHT NOW", not what was shipped at
   * the last sync (the stored hash covers that).
   *
   * @return array|null
   *   The shipped payload ({kind, value}) exactly as authored on disk, or
   *   NULL when the swatch has no disk source or its provider no longer
   *   ships an entry under its machine name (sync never deletes, so such
   *   swatches live on as ordinary site content: no shipped state, no
   *   revert, no lock).
   *
   * @throws \Drupal\daedalus_stylish\Exception\ShippedSwatchException
   *   When the shipped entry is malformed; a broken shipped file is a
   *   developer error, never skippable.
   */
  public function getShippedData(DaedalusStylishSwatchInterface $swatch): ?array {
    $provider = $swatch->getProvider();
    if ($provider === NULL) {
      return NULL;
    }
    $name = $swatch->getMachineName();
    $definitions = $this->getShippedDefinitions($provider);
    if (!array_key_exists($name, $definitions)) {
      return NULL;
    }
    $this->checkDefinitionShape($provider, $name, $definitions[$name]);
    return [
      'kind' => $definitions[$name]['kind'],
      'value' => $definitions[$name]['value'],
    ];
  }

  /**
   * Syncs one shipped swatch; see the class doc for the semantics.
   *
   * @return string
   *   One of the CREATED/UPDATED/UNCHANGED/KEPT constants.
   */
  protected function syncDefinition(string $module, string $name, mixed $definition, int $uid): string {
    $this->checkDefinitionShape($module, $name, $definition);
    // The shipped payload in the entity's own keyed shape
    // (DaedalusStylishSwatch::shippedData()): the one hash covers both halves of
    // what the swatch is.
    $shipped = [
      'kind' => $definition['kind'],
      'value' => $definition['value'],
    ];

    $storage = $this->entityTypeManager->getStorage('daedalus_swatch');
    $existing = $storage->loadByProperties(['machine_name' => $name]);
    $swatch = reset($existing) ?: NULL;
    assert($swatch === NULL || $swatch instanceof DaedalusStylishSwatchInterface);
    $design_system = $this->designSystemFor($module, $name, $definition);

    if ($swatch !== NULL && $swatch->getProvider() !== $module) {
      throw new ShippedSwatchException($module, $name, [
        new BlueprintError($name, 'SHIPPED_SWATCH_CONFLICT', sprintf('The machine name "%s" already belongs to %s.', $name, $swatch->getProvider() === NULL ? 'a swatch minted on this site' : sprintf('a swatch shipped by the "%s" module', $swatch->getProvider())), [], 'Machine names are the site-wide palette vocabulary (each is the --swatch-{name} custom property tail); rename the shipped swatch.'),
      ]);
    }

    if ($swatch === NULL) {
      $swatch = $this->deserialize($module, $name, [
        'entity_type' => 'daedalus_swatch',
        'bundle' => 'daedalus_swatch',
        'fields' => [
          'label' => $definition['label'],
          'machine_name' => $name,
          'kind' => $shipped['kind'],
          'design_system' => $design_system,
          'value' => $shipped['value'],
        ],
      ]);
      $this->syncDescription($swatch, $definition);
      $this->syncOffered($swatch, $definition);
      $swatch->setShippedSource($module, $shipped);
      $swatch->setPublished();
      $swatch->save();
      return self::CREATED;
    }

    // The description and the membership are part of the shipped face and
    // sync independently of the payload hash (see the base class): a
    // description: key added later must land even when the value never
    // changed. The UNCHANGED / KEPT vocabulary keeps describing the
    // payload sync.
    if (!$swatch->hasNewDefaults($shipped)) {
      $changed = $this->syncDescription($swatch, $definition);
      $changed = $this->syncOffered($swatch, $definition) || $changed;
      if ($this->syncMembership($swatch, $design_system) || $changed) {
        $swatch->save();
      }
      return self::UNCHANGED;
    }
    if ($swatch->isModified()) {
      $changed = $this->syncDescription($swatch, $definition);
      $changed = $this->syncOffered($swatch, $definition) || $changed;
      if ($this->syncMembership($swatch, $design_system) || $changed) {
        $swatch->save();
      }
      return self::KEPT;
    }

    // Unmodified swatch, new payload on disk: auto-sync as a new revision.
    // The update rides the deserializer too, the same spine validation as
    // the create, with the existing entity addressed by machine name. Kind
    // is written wholesale like the value: disk is the author, and kind is
    // non-revisionable identity, so the change lands on every revision.
    $swatch = $this->deserialize($module, $name, [
      'entity_type' => 'daedalus_swatch',
      'bundle' => 'daedalus_swatch',
      '_root_entity' => ['field' => 'machine_name', 'value' => $name],
      'fields' => [
        'label' => $definition['label'],
        'kind' => $shipped['kind'],
        'design_system' => $design_system,
        'value' => $shipped['value'],
      ],
    ]);
    $this->syncDescription($swatch, $definition);
    $this->syncOffered($swatch, $definition);
    $swatch->setNewRevision(TRUE);
    $swatch->setRevisionUserId($uid);
    $swatch->setRevisionCreationTime($this->time->getRequestTime());
    $swatch->setRevisionLogMessage(sprintf('Synced new shipped defaults from the %s module.', $module));
    $swatch->setShippedSource($module, $shipped);
    $swatch->setPublished();
    $swatch->save();
    return self::UPDATED;
  }

  /**
   * Syncs a definition's description onto the swatch.
   *
   * The variants lane's rule: part of the shipped face, synced whenever
   * the key is authored and the stored text differs (disk is the author
   * of a shipped swatch's teaching text). An absent key leaves the stored
   * description alone, so a site-authored description on a shipped swatch
   * survives as long as the file stays silent. The caller decides when
   * the assignment saves.
   *
   * @return bool
   *   Whether the description changed.
   */
  protected function syncDescription(DaedalusStylishSwatchInterface $swatch, array $definition): bool {
    if (!array_key_exists('description', $definition) || $swatch->getDescription() === $definition['description']) {
      return FALSE;
    }
    $swatch->setDescription($definition['description']);
    return TRUE;
  }

  /**
   * Deserializes a shipped definition through the blueprint spine, loudly.
   */
  protected function deserialize(string $module, string $name, array $blueprint): DaedalusStylishSwatchInterface {
    // shipped_sync: disk is the one author the distro lock protects, so the
    // importer's own writes pass the value field handler's read-only gate
    // (SwatchValueFieldHandler); every other blueprint write refuses.
    // A shipped sync acts for the site (files on disk are the author), so
    // the capability gate is not asked: the system posture.
    $result = $this->deserializer->deserialize($blueprint, ['shipped_sync' => TRUE, 'system' => TRUE]);
    if (!$result->isSuccess()) {
      throw new ShippedSwatchException($module, $name, $result->getErrors());
    }
    $swatch = $result->getEntity();
    assert($swatch instanceof DaedalusStylishSwatchInterface);
    return $swatch;
  }

  /**
   * Enforces the swatches-file grammar: the keys and their PHP types.
   *
   * The FILE grammar only; the kind vocabulary and the value's CSS shape
   * are validated by the spine (the swatch value field handler) during
   * deserialization.
   */
  public function checkDefinitionShape(string $module, string $name, mixed $definition): void {
    if (!is_array($definition) || array_is_list($definition)) {
      throw new ShippedSwatchException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SWATCH', sprintf('The "%s" entry is not a swatch definition.', $name), [], 'Each entry maps a machine name to label, kind, and value keys.'),
      ]);
    }
    if ($unknown = array_diff(array_keys($definition), self::REQUIRED_KEYS, self::OPTIONAL_KEYS)) {
      throw new ShippedSwatchException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SWATCH', sprintf('Unknown key(s) %s in the "%s" entry.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $name), [], 'A shipped swatch definition consists of label, kind, and value, plus an optional description, design_system, and ai: false (the AI opt-out).'),
      ]);
    }
    if ($missing = array_diff(self::REQUIRED_KEYS, array_keys($definition))) {
      throw new ShippedSwatchException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SWATCH', sprintf('The "%s" entry is missing the %s key(s).', $name, implode(', ', array_map(static fn ($key) => "\"$key\"", $missing))), [], 'A shipped swatch definition consists of label, kind, and value.'),
      ]);
    }
    if (!is_string($definition['label']) || trim($definition['label']) === '' || !is_string($definition['kind']) || trim($definition['kind']) === '') {
      throw new ShippedSwatchException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SWATCH', sprintf('The "%s" entry has the wrong shape.', $name), [], 'label and kind are non-empty strings.'),
      ]);
    }
    $value = $definition['value'];
    if (!(is_string($value) && trim($value) !== '') && !(is_array($value) && !array_is_list($value) && $value !== [])) {
      throw new ShippedSwatchException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SWATCH', sprintf('The "%s" entry has the wrong shape.', $name), [], 'value is a non-empty CSS string, or a condition-keyed map with a "default" entry ({"default": "#1a1a2e", "dark": "#e8e8f0"}).'),
      ]);
    }
    if (array_key_exists('description', $definition) && (!is_string($definition['description']) || trim($definition['description']) === '')) {
      throw new ShippedSwatchException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SWATCH', sprintf('The "%s" entry has the wrong shape.', $name), [], 'description, when given, is a non-empty string saying when to reach for this value (the AI reads it in the design system brief); omit it rather than shipping an empty one.'),
      ]);
    }
    $this->checkOfferedShape($module, $name, $definition, 'INVALID_SHIPPED_SWATCH');
    $this->checkDesignSystemShape($module, $name, $definition, 'INVALID_SHIPPED_SWATCH');
  }

}
