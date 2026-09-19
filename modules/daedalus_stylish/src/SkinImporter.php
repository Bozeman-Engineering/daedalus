<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Exception\ShippedSkinException;

/**
 * Imports and syncs module-shipped skins: the skins disk lane.
 *
 * A module ships default skins in a MODULE.daedalus_stylish.skins.yml at its root,
 * keyed by machine name:
 * @code
 * aurora:
 *   label: 'Aurora'
 *   tokens:
 *     color:
 *       accent:
 *         default: '#6941c6'
 *         dark: '#9b7fe0'
 *     space:
 *       gutter: '2rem'
 *   defaults:
 *     'base:h2':
 *       '':
 *         default:
 *           font-size: 'var(--text-hero)'
 * @endcode
 * tokens and defaults are both optional; the token scales and base-tag
 * vocabulary the values validate against are the SITE's declaration (the
 * page_builder.yml family's site: section), so a module shipping a skin
 * couples to the theme/module declaring those scales.
 *
 * Every definition rides the blueprint spine exactly like an AI- or
 * UI-authored skin (SkinTokensFieldHandler / SkinDefaultsFieldHandler,
 * converging on StyleMapValidator::validateTokens()/validateDefaults()),
 * and the importer saves live and published (see the base class for the
 * workspace escape and admin account rationale).
 *
 * Sync semantics are the variants lane's "one hash, two comparisons", with
 * the hash over the WHOLE shipped payload, the keyed {tokens, defaults}
 * shape (DaedalusStylishSkin::shippedData()), so shipping a defaults tier later
 * reads as new defaults, and a user editing either tier reads as modified.
 * The importer never touches the activation: which skin a site wears is the
 * active design system's default_skin (daedalus_blueprint.settings
 * active_design_system, recipe- or admin-set), a shipped skin is just
 * available.
 */
class SkinImporter extends ShippedDefinitionsImporter {

  /**
   * The keys a shipped skin definition must carry.
   */
  protected const REQUIRED_KEYS = ['label'];

  /**
   * The optional definition keys: the two data tiers a skin hosts.
   */
  protected const OPTIONAL_KEYS = ['tokens', 'defaults'];

  /**
   * The optional metadata key: the membership (see the base class).
   *
   * A package's skin is a member of the package's design system like
   * every other shipped material; the system's default_skin names it.
   */
  protected const MEMBERSHIP_KEYS = ['design_system'];

  /**
   * {@inheritdoc}
   */
  public function kind(): string {
    return 'skin';
  }

  /**
   * {@inheritdoc}
   */
  public function fileSuffix(): string {
    return 'daedalus_stylish.skins.yml';
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedException(string $module, string $name, array $errors): ShippedSkinException {
    return new ShippedSkinException($module, $name, $errors);
  }

  /**
   * Syncs one shipped skin; see the class doc for the semantics.
   *
   * @return string
   *   One of the CREATED/UPDATED/UNCHANGED/KEPT constants.
   */
  protected function syncDefinition(string $module, string $name, mixed $definition, int $uid): string {
    $this->checkDefinitionShape($module, $name, $definition);
    // The shipped payload in the entity's own keyed shape
    // (DaedalusStylishSkin::shippedData()): the one hash covers both tiers.
    $shipped = [
      'tokens' => $definition['tokens'] ?? [],
      'defaults' => $definition['defaults'] ?? [],
    ];

    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $existing = $storage->loadByProperties(['machine_name' => $name]);
    $skin = reset($existing) ?: NULL;
    assert($skin === NULL || $skin instanceof DaedalusStylishSkinInterface);
    $design_system = $this->designSystemFor($module, $name, $definition);

    if ($skin !== NULL && $skin->getProvider() !== $module) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'SHIPPED_SKIN_CONFLICT', sprintf('The machine name "%s" already belongs to %s.', $name, $skin->getProvider() === NULL ? 'a skin created on this site' : sprintf('a skin shipped by the "%s" module', $skin->getProvider())), [], 'Machine names are the site-wide skin vocabulary; rename the shipped skin.'),
      ]);
    }

    if ($skin === NULL) {
      $skin = $this->deserialize($module, $name, [
        'entity_type' => 'daedalus_skin',
        'bundle' => 'daedalus_skin',
        'fields' => [
          'label' => $definition['label'],
          'machine_name' => $name,
          'design_system' => $design_system,
          'tokens' => $shipped['tokens'],
          'defaults' => $shipped['defaults'],
        ],
      ]);
      $skin->setShippedSource($module, $shipped);
      $skin->setPublished();
      $skin->save();
      return self::CREATED;
    }

    // Membership is part of the shipped face and syncs independently of
    // the payload hash (see the base class).
    if (!$skin->hasNewDefaults($shipped)) {
      if ($this->syncMembership($skin, $design_system)) {
        $skin->save();
      }
      return self::UNCHANGED;
    }
    if ($skin->isModified()) {
      if ($this->syncMembership($skin, $design_system)) {
        $skin->save();
      }
      return self::KEPT;
    }

    // Unmodified skin, new payload on disk: auto-sync as a new revision.
    // Both tiers are written wholesale: a tier the file no longer ships is
    // cleared, matching what the hash compares.
    $skin = $this->deserialize($module, $name, [
      'entity_type' => 'daedalus_skin',
      'bundle' => 'daedalus_skin',
      '_root_entity' => ['field' => 'machine_name', 'value' => $name],
      'fields' => [
        'label' => $definition['label'],
        'design_system' => $design_system,
        'tokens' => $shipped['tokens'],
        'defaults' => $shipped['defaults'],
      ],
    ]);
    $skin->setNewRevision(TRUE);
    $skin->setRevisionUserId($uid);
    $skin->setRevisionCreationTime($this->time->getRequestTime());
    $skin->setRevisionLogMessage(sprintf('Synced new shipped defaults from the %s module.', $module));
    $skin->setShippedSource($module, $shipped);
    $skin->setPublished();
    $skin->save();
    return self::UPDATED;
  }

  /**
   * Deserializes a shipped definition through the blueprint spine, loudly.
   */
  protected function deserialize(string $module, string $name, array $blueprint): DaedalusStylishSkinInterface {
    $result = $this->deserializer->deserialize($blueprint);
    if (!$result->isSuccess()) {
      throw new ShippedSkinException($module, $name, $result->getErrors());
    }
    $skin = $result->getEntity();
    assert($skin instanceof DaedalusStylishSkinInterface);
    return $skin;
  }

  /**
   * Enforces the skins-file grammar: the keys and their PHP types.
   *
   * The FILE grammar only; the tokens and defaults VALUES are validated by
   * the spine (the skin field handlers) during deserialization.
   */
  public function checkDefinitionShape(string $module, string $name, mixed $definition): void {
    if (!is_array($definition) || array_is_list($definition)) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('The "%s" entry is not a skin definition.', $name), [], 'Each entry maps a machine name to a label key, plus optional tokens and defaults.'),
      ]);
    }
    if ($unknown = array_diff(array_keys($definition), self::REQUIRED_KEYS, self::OPTIONAL_KEYS, self::MEMBERSHIP_KEYS)) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('Unknown key(s) %s in the "%s" entry.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $name), [], 'A shipped skin definition consists of label, plus optional tokens, defaults, and design_system.'),
      ]);
    }
    if ($missing = array_diff(self::REQUIRED_KEYS, array_keys($definition))) {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('The "%s" entry is missing the %s key(s).', $name, implode(', ', array_map(static fn ($key) => "\"$key\"", $missing))), [], 'A shipped skin definition consists of label, plus optional tokens and defaults.'),
      ]);
    }
    if (!is_string($definition['label']) || trim($definition['label']) === '') {
      throw new ShippedSkinException($module, $name, [
        new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('The "%s" entry has the wrong shape.', $name), [], 'label is a non-empty string.'),
      ]);
    }
    foreach (self::OPTIONAL_KEYS as $tier) {
      if (array_key_exists($tier, $definition) && (!is_array($definition[$tier]) || (array_is_list($definition[$tier]) && $definition[$tier] !== []))) {
        throw new ShippedSkinException($module, $name, [
          new BlueprintError($name, 'INVALID_SHIPPED_SKIN', sprintf('The "%s" entry has the wrong shape.', $name), [], sprintf('%s, when given, is a map (%s).', $tier, $tier === 'tokens' ? '{scale: {name: value}}' : '{scope: element-relative styles}')),
        ]);
      }
    }
    $this->checkDesignSystemShape($module, $name, $definition, 'INVALID_SHIPPED_SKIN');
  }

}
