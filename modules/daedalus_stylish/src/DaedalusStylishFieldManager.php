<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;

/**
 * Auto-manages the style map field per bundle.
 *
 * The Layout Builder overrides pattern: a bundle's "Enable styling" setting
 * creates or removes the daedalus_stylish_style_map field, so the field never appears
 * in Field UI and enablement is queryable (the inspector can tell "styling
 * isn't enabled here" apart from "no styles yet"). The field's existence IS
 * the enablement state — there is no parallel setting to drift from it.
 *
 * Styling defaults ON for page-builder bundles: whenever a bundle has both
 * Layout Builder and Edit Mode enabled (the gate; a bundle-less type has
 * no Edit Mode carrier and passes on Layout Builder alone, see
 * editModeEnabled()), the field is created automatically, on any save of a
 * gate carrier and on syncAll() sweeps (install, update, every applied
 * recipe), not just on the save that opened the gate. What makes a
 * deliberate opt-out stick is an explicit opt-out record in
 * daedalus_stylish.settings (`opt_outs`, a list of "entity_type_id.bundle"
 * keys): disable() records it, enable() clears it, and auto-enablement
 * never re-defaults a recorded bundle. Field absence alone is NOT a memory
 * of anything: a bundle whose field is missing without a record simply
 * hasn't been enabled yet and will default ON at the next gate check.
 */
class DaedalusStylishFieldManager {

  /**
   * The auto-managed style map field name.
   */
  public const FIELD_NAME = 'daedalus_stylish_styles';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether styling is enabled for a bundle.
   */
  public function isEnabled(string $entity_type_id, string $bundle): bool {
    return FieldConfig::loadByName($entity_type_id, $bundle, self::FIELD_NAME) !== NULL;
  }

  /**
   * How many of a bundle's entities store styles today.
   *
   * What disable() deletes: the entities of the bundle whose style map
   * field holds a value. Zero where styling is not enabled (no field, so
   * nothing stored).
   */
  public function countStyled(string $entity_type_id, string $bundle): int {
    if (!$this->isEnabled($entity_type_id, $bundle)) {
      return 0;
    }
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()
      ->accessCheck(FALSE)
      ->exists(self::FIELD_NAME)
      ->count();
    if ($bundle_key = $this->entityTypeManager->getDefinition($entity_type_id)->getKey('bundle')) {
      $query->condition($bundle_key, $bundle);
    }
    return (int) $query->execute();
  }

  /**
   * Whether a bundle deliberately opted out of styling.
   *
   * The record only gates auto-enablement; it never overrides an existing
   * field. A stale record next to an explicitly (re)created field is inert.
   */
  public function isOptedOut(string $entity_type_id, string $bundle): bool {
    $opt_outs = $this->configFactory->get('daedalus_stylish.settings')->get('opt_outs') ?? [];
    return in_array("$entity_type_id.$bundle", $opt_outs, TRUE);
  }

  /**
   * Enables styling for a bundle by creating the style map field.
   *
   * Clears any opt-out record: an explicit enable is the admin changing
   * their mind, so auto-enablement may default the bundle ON again later.
   */
  public function enable(string $entity_type_id, string $bundle): void {
    $this->clearOptOut($entity_type_id, $bundle);
    if ($this->isEnabled($entity_type_id, $bundle)) {
      return;
    }
    $field_storage = FieldStorageConfig::loadByName($entity_type_id, self::FIELD_NAME);
    if (!$field_storage) {
      $field_storage = FieldStorageConfig::create([
        'entity_type' => $entity_type_id,
        'field_name' => self::FIELD_NAME,
        'type' => 'daedalus_stylish_style_map',
        // Keep Field UI away from a field whose lifecycle we own.
        'locked' => TRUE,
      ]);
      // Untranslatable, pinned: the map keys into the layout tree, and
      // core's layout model is ONE structure shared by all translations
      // (the layout field itself is created untranslatable) — a
      // per-language style map would address a tree the language cannot
      // own. Per-language styling is a possible future feature, decided
      // separately, never an accidental default.
      $field_storage->setTranslatable(FALSE);
      $field_storage->save();
    }
    $field = FieldConfig::create([
      'field_storage' => $field_storage,
      'bundle' => $bundle,
      'label' => 'Styles',
    ]);
    $field->setTranslatable(FALSE);
    $field->save();
  }

  /**
   * Enables styling for a bundle when the default-on gate is open.
   *
   * The gate: a Layout Builder-enabled view display AND Edit Mode enabled on
   * the bundle. Callers invoke this whenever the gate may be open (every
   * save of a gate carrier, install-time sweeps) and it defaults styling ON
   * unless the bundle carries an opt-out record. Both gates are re-checked
   * fresh so the call order of the two enablements doesn't matter.
   */
  public function maybeAutoEnable(string $entity_type_id, string $bundle): void {
    if ($this->isEnabled($entity_type_id, $bundle) || $this->isOptedOut($entity_type_id, $bundle)) {
      return;
    }
    if (!$this->editModeEnabled($entity_type_id, $bundle) || !$this->layoutBuilderEnabled($entity_type_id, $bundle)) {
      return;
    }
    $this->enable($entity_type_id, $bundle);
  }

  /**
   * Defaults styling ON for every bundle currently passing the gate.
   *
   * The install-time, update-time and recipe-applied sweep: walks all Layout
   * Builder-enabled view displays and auto-enables each bundle (opt-out
   * records respected). Covers bundles whose gate opened while Daedalus
   * Stylish's hooks weren't around to see the save: a site gaining Daedalus
   * Stylish after the fact, or a hook suppressed by an install pipeline
   * running under the syncing flag (RecipeAppliedSubscriber).
   */
  public function syncAll(): void {
    $displays = $this->entityTypeManager->getStorage('entity_view_display')->loadMultiple();
    $seen = [];
    foreach ($displays as $display) {
      if (!$display instanceof LayoutBuilderEntityViewDisplay || !$display->isLayoutBuilderEnabled()) {
        continue;
      }
      $key = $display->getTargetEntityTypeId() . '.' . $display->getTargetBundle();
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $this->maybeAutoEnable($display->getTargetEntityTypeId(), $display->getTargetBundle());
    }
  }

  /**
   * Whether Edit Mode is enabled for a bundle.
   *
   * Reads daedalus_ui's per-bundle `enabled` third-party setting ("the page
   * builder is on for this bundle", the bundle form's leading control),
   * which lives on the bundle CONFIG entity. A type with no bundle entity
   * type (the blueprint type, entity_test, user) has no carrier for the
   * setting and therefore no gate: Edit Mode counts as enabled, so Layout
   * Builder alone opens styling for it. That is the rule the other reader
   * of the same setting, daedalus_ui's ShouldNotEditMode, applies on the
   * canvas (no bundle entity type, no veto), and the two readers must agree
   * or a bundle could be edited with no style store to write to.
   */
  public function editModeEnabled(string $entity_type_id, string $bundle): bool {
    $bundle_entity_type = $this->entityTypeManager->getDefinition($entity_type_id)->getBundleEntityType();
    if (!$bundle_entity_type) {
      return TRUE;
    }
    $bundle_entity = $this->entityTypeManager->getStorage($bundle_entity_type)->load($bundle);
    if (!$bundle_entity instanceof ConfigEntityInterface) {
      return FALSE;
    }
    return (bool) $bundle_entity->getThirdPartySetting('daedalus_ui', 'enabled', FALSE);
  }

  /**
   * Whether any view display of the bundle has Layout Builder enabled.
   */
  public function layoutBuilderEnabled(string $entity_type_id, string $bundle): bool {
    $displays = $this->entityTypeManager->getStorage('entity_view_display')->loadByProperties([
      'targetEntityType' => $entity_type_id,
      'bundle' => $bundle,
    ]);
    foreach ($displays as $display) {
      if ($display instanceof LayoutBuilderEntityViewDisplay && $display->isLayoutBuilderEnabled()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Disables styling for a bundle by removing the style map field.
   *
   * Records the opt-out first, so the gate check fired by the config deletes
   * (and every later one) knows the absence is deliberate and never
   * re-defaults the bundle ON. Deletes the bundle's style data. The shared
   * storage is removed with the last bundle so a fully disabled entity type
   * carries no schema.
   */
  public function disable(string $entity_type_id, string $bundle): void {
    $this->recordOptOut($entity_type_id, $bundle);
    $field = FieldConfig::loadByName($entity_type_id, $bundle, self::FIELD_NAME);
    if ($field) {
      $field->delete();
    }
    $field_storage = FieldStorageConfig::loadByName($entity_type_id, self::FIELD_NAME);
    if ($field_storage && empty($field_storage->getBundles())) {
      $field_storage->delete();
    }
  }

  /**
   * Removes a bundle's opt-out record.
   *
   * Also the bundle-delete cleanup: a deleted bundle's record must not
   * ambush a future bundle reusing the same machine name.
   */
  public function clearOptOut(string $entity_type_id, string $bundle): void {
    $config = $this->configFactory->getEditable('daedalus_stylish.settings');
    $opt_outs = $config->get('opt_outs') ?? [];
    $remaining = array_values(array_diff($opt_outs, ["$entity_type_id.$bundle"]));
    if ($remaining !== $opt_outs) {
      $config->set('opt_outs', $remaining)->save();
    }
  }

  /**
   * Records a bundle's deliberate opt-out.
   */
  protected function recordOptOut(string $entity_type_id, string $bundle): void {
    $config = $this->configFactory->getEditable('daedalus_stylish.settings');
    $opt_outs = $config->get('opt_outs') ?? [];
    if (!in_array("$entity_type_id.$bundle", $opt_outs, TRUE)) {
      $opt_outs[] = "$entity_type_id.$bundle";
      sort($opt_outs);
      $config->set('opt_outs', $opt_outs)->save();
    }
  }

}
