<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * A plugin block's configuration as a property bag, from its config schema.
 *
 * A plugin block (a field block, a view, a menu, a site's own block plugin)
 * is config: its plugin id plus its settings. Core already spells those
 * settings once, as the plugin's config schema (`block.settings.{id}`
 * through typed config: property names, types, constraints), so the spine
 * derives the block's property bag from that schema and nothing else: the
 * properties are the schema's mapping keys, validation is typed-data
 * validation of the resulting settings, and read_schema/read_element
 * advertise the same keys. A plugin whose settings fall back to core's
 * generic `block.settings.*` has declared nothing about itself and is not
 * configurable through the spine; adding config schema is how it joins.
 *
 * The bag is flat and top-level: every key of the plugin's resolved
 * mapping except the structural keys the spine owns (`id`, `provider`,
 * `context_mapping`, defaulted at placement) and the base keys core gives
 * every block that are not settings (`status`, `info`). `label` and
 * `label_display` are properties on every configurable plugin block, the
 * same two words an inline block's bag uses; the plugin's OWN keys (a field
 * block's `formatter`, a menu block's `level` and `depth`) are the rest. A
 * key is set to the value given, an explicit empty value (NULL, '', [])
 * clears it so the plugin's default rules, an omitted key keeps its value.
 */
class PluginBlockConfiguration {

  /**
   * Configuration keys the spine owns: never properties, never advertised.
   *
   * `id` and `provider` are the plugin's identity; `context_mapping` is
   * defaulted at placement (BlueprintOperations::defaultContextMapping) and
   * remapped by nothing else.
   *
   * @var string[]
   */
  public const STRUCTURAL_KEYS = ['id', 'provider', 'context_mapping'];

  /**
   * Base keys of core's `block_settings` type that are not a block's settings.
   *
   * @var string[]
   */
  protected const INERT_BASE_KEYS = ['status', 'info'];

  /**
   * The base properties every configurable plugin block carries.
   *
   * @var string[]
   */
  public const BASE_PROPERTIES = ['label', 'label_display'];

  /**
   * Core's generic block settings schema, every plugin's last fallback.
   */
  protected const GENERIC_SCHEMA = 'block.settings.*';

  public function __construct(
    protected BlockManagerInterface $blockManager,
    protected TypedConfigManagerInterface $typedConfigManager,
  ) {}

  /**
   * The config schema name a plugin's settings are spelled under.
   *
   * @param string $plugin_id
   *   The block plugin id.
   *
   * @return string
   *   The schema name (`block.settings.{id}`).
   */
  public function schemaName(string $plugin_id): string {
    return 'block.settings.' . $plugin_id;
  }

  /**
   * Whether a plugin declares config schema of its own.
   *
   * Typed config resolves `block.settings.{id}` by name, then by wildcard
   * fallback (`block.settings.field_block:*:*:*`, `block.settings.*`). Only
   * the last, core's generic mapping, means the plugin declared nothing.
   *
   * @param string $plugin_id
   *   The block plugin id.
   *
   * @return bool
   *   TRUE when the plugin's settings resolve to schema more specific than
   *   `block.settings.*`.
   */
  public function hasSchema(string $plugin_id): bool {
    // The compiled definition's `type` is the schema name typed config
    // resolved to: the exact name, a wildcard sibling, or the generic one.
    $resolved = $this->typedConfigManager->getDefinition($this->schemaName($plugin_id))['type'] ?? 'undefined';
    return $resolved !== 'undefined' && $resolved !== static::GENERIC_SCHEMA;
  }

  /**
   * The corrective for a plugin with no config schema of its own.
   *
   * @param string $plugin_id
   *   The block plugin id.
   * @param string $path
   *   The error path.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError
   *   The NO_PLUGIN_SCHEMA error, naming the missing schema.
   */
  public function missingSchemaError(string $plugin_id, string $path): BlueprintError {
    $schema = $this->schemaName($plugin_id);
    return new BlueprintError(
      path: $path,
      code: 'NO_PLUGIN_SCHEMA',
      message: "Block plugin '$plugin_id' declares no configuration schema ($schema), so it is not configurable.",
      context: ['schema' => $schema],
      hint: "A plugin block's properties come from its config schema. Declare $schema (type: block_settings plus the plugin's own settings keys) in the providing module's config/schema to make it configurable; place it without properties meanwhile.",
    );
  }

  /**
   * The plugin's own settings keys: its mapping minus core's base keys.
   *
   * @param string $plugin_id
   *   The block plugin id.
   *
   * @return string[]
   *   The keys, in schema order. Empty for a plugin whose schema adds
   *   nothing to `block_settings`.
   */
  public function ownKeys(string $plugin_id): array {
    $mapping = $this->typedConfigManager->getDefinition($this->schemaName($plugin_id))['mapping'] ?? [];
    $base = $this->typedConfigManager->getDefinition('block_settings')['mapping'] ?? [];
    return array_values(array_diff(array_keys($mapping), array_keys($base), static::STRUCTURAL_KEYS, static::INERT_BASE_KEYS));
  }

  /**
   * Every property the plugin's bag takes.
   *
   * @param string $plugin_id
   *   The block plugin id.
   *
   * @return string[]
   *   `label`, `label_display`, then the plugin's own keys.
   */
  public function propertyKeys(string $plugin_id): array {
    return array_merge(static::BASE_PROPERTIES, $this->ownKeys($plugin_id));
  }

  /**
   * Validates a property bag against the plugin's schema and applies it.
   *
   * The bag patches the component's configuration at the top level (set,
   * clear on an explicit empty value, keep when omitted), the plugin
   * normalizes the result with its defaults (a cleared key falls back to
   * the plugin's default, a nested map the caller sent partially is
   * completed), and typed config validates the whole resulting settings
   * with the schema's constraints.
   *
   * @param string $plugin_id
   *   The block plugin id.
   * @param array $configuration
   *   The component's current configuration (`id`, `provider`,
   *   `context_mapping` and the settings), or the placement defaults for a
   *   new component.
   * @param array $properties
   *   The flat property bag.
   * @param string $path
   *   The error path prefix for the bag (`properties`).
   *
   * @return array{settings: array, structural: \Drupal\daedalus_blueprint\Result\BlueprintError[], errors: \Drupal\daedalus_blueprint\Result\BlueprintError[]}
   *   The resulting settings (complete, plugin-normalized) on success;
   *   structural errors for a schema-less plugin or an unknown property,
   *   semantic errors for a constraint violation.
   */
  public function apply(string $plugin_id, array $configuration, array $properties, string $path): array {
    if (!$this->hasSchema($plugin_id)) {
      return [
        'settings' => $configuration,
        'structural' => [$this->missingSchemaError($plugin_id, $path)],
        'errors' => [],
      ];
    }

    $available = $this->propertyKeys($plugin_id);
    $structural = [];
    foreach (array_keys($properties) as $key) {
      if (!in_array($key, $available, TRUE)) {
        $structural[] = new BlueprintError(
          path: "$path.$key",
          code: 'UNKNOWN_PROPERTY',
          message: "Property '$key' does not exist on block plugin '$plugin_id'.",
          context: ['available' => $available],
          hint: 'A plugin block takes label, label_display and the settings its config schema declares; read_schema (available_block_plugins, available_fields for a field block) and read_element list them.',
        );
      }
    }
    if ($structural !== []) {
      return ['settings' => $configuration, 'structural' => $structural, 'errors' => []];
    }

    $settings = $configuration;
    foreach ($properties as $key => $value) {
      if (OpaqueDataMerger::isEmptyValue($value)) {
        unset($settings[$key]);
      }
      else {
        $settings[$key] = $value;
      }
    }
    $settings['id'] = $plugin_id;
    // The plugin's own normalization: base defaults, the plugin's defaults,
    // then the settings, merged deep, so a cleared or partial key is whole.
    $settings = $this->blockManager->createInstance($plugin_id, $settings)->getConfiguration();

    $errors = [];
    $typed = $this->typedConfigManager->createFromNameAndData($this->schemaName($plugin_id), $settings);
    foreach ($typed->validate() as $violation) {
      $property_path = (string) $violation->getPropertyPath();
      $errors[] = new BlueprintError(
        path: $property_path === '' ? $path : "$path.$property_path",
        code: 'INVALID_CONFIGURATION',
        message: (string) $violation->getMessage(),
        context: ['schema' => $this->schemaName($plugin_id)],
        hint: 'The value must satisfy the plugin\'s config schema; read_element on the block shows each property\'s type and options.',
      );
    }

    return ['settings' => $settings, 'structural' => [], 'errors' => $errors];
  }

  /**
   * The plugin's own settings as the component carries them.
   *
   * @param string $plugin_id
   *   The block plugin id.
   * @param array $configuration
   *   The component's configuration.
   *
   * @return array
   *   The own keys present in the configuration, by key.
   */
  public function ownValues(string $plugin_id, array $configuration): array {
    return array_intersect_key($configuration, array_flip($this->ownKeys($plugin_id)));
  }

  /**
   * Describes the plugin's own settings from its schema definition alone.
   *
   * The static shape a writer needs before placing the block (read_schema):
   * each own key with its type, label and options, nested mappings walked
   * where their type is static, a dynamic type (`field.formatter.settings.
   * [%parent.type]`) left as its name, which the placed block's values
   * resolve. No plugin is instantiated.
   *
   * @param string $plugin_id
   *   The block plugin id.
   *
   * @return array
   *   Property descriptions keyed by own key.
   */
  public function describe(string $plugin_id): array {
    $mapping = $this->typedConfigManager->getDefinition($this->schemaName($plugin_id))['mapping'] ?? [];
    $described = [];
    foreach ($this->ownKeys($plugin_id) as $key) {
      $described[$key] = $this->describeDefinition($mapping[$key] ?? []);
    }
    return $described;
  }

  /**
   * Describes one schema definition array, recursively for static mappings.
   */
  protected function describeDefinition(array $definition): array {
    $type = (string) ($definition['type'] ?? 'undefined');
    $entry = ['type' => $type];
    if (isset($definition['label'])) {
      $entry['description'] = (string) $definition['label'];
    }
    $options = $this->choices($definition['constraints'] ?? []);
    if ($options !== NULL) {
      $entry['options'] = $options;
    }
    if (isset($definition['nullable']) && $definition['nullable']) {
      $entry['nullable'] = TRUE;
    }
    $mapping = $definition['mapping'] ?? NULL;
    if ($mapping === NULL && $type !== 'undefined' && !str_contains($type, '[') && $this->typedConfigManager->hasDefinition($type)) {
      $resolved = $this->typedConfigManager->getDefinition($type);
      $mapping = $resolved['mapping'] ?? NULL;
      if (!isset($entry['description']) && isset($resolved['label'])) {
        $entry['description'] = (string) $resolved['label'];
      }
      $options ??= $this->choices($resolved['constraints'] ?? []);
      if ($options !== NULL) {
        $entry['options'] = $options;
      }
    }
    if (is_array($mapping)) {
      $entry['properties'] = [];
      foreach ($mapping as $key => $child) {
        $entry['properties'][$key] = $this->describeDefinition(is_array($child) ? $child : []);
      }
    }
    return $entry;
  }

  /**
   * The Choice constraint's choices, when a definition carries one.
   */
  protected function choices(array $constraints): ?array {
    $choice = $constraints['Choice'] ?? NULL;
    if (!is_array($choice)) {
      return NULL;
    }
    $choices = $choice['choices'] ?? (array_is_list($choice) ? $choice : NULL);
    return is_array($choices) ? array_values($choices) : NULL;
  }

}
