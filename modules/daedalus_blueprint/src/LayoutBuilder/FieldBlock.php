<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

/**
 * Core's field block plugin id, spelled once.
 *
 * Core places a host's field as the block plugin
 * `field_block:{entity_type}:{bundle}:{field}`, a derivative per field of
 * every bundle. The page document never spells that derivative: a field
 * block node names its FIELD (`{kind: block, field: body}`), so a tree
 * read from one host instantiates onto any host whose subject carries the
 * field, and the destination host's subject (its entity type and bundle)
 * supplies the derivative at placement. This class is the one place the
 * derivative is built and taken apart; every reader of a component's
 * plugin id and every writer of one goes through it.
 */
final class FieldBlock {

  /**
   * The base plugin id every field block derives from.
   *
   * Also the base a field block styles and wears under
   * (`block_plugin:field_block`): derivatives share one template, so one
   * declaration covers every field.
   */
  public const PLUGIN = 'field_block';

  /**
   * The derivative placing one field of one bundle.
   *
   * @param string $entity_type
   *   The subject's entity type id.
   * @param string $bundle
   *   The subject's bundle.
   * @param string $field
   *   The field name.
   *
   * @return string
   *   `field_block:{entity_type}:{bundle}:{field}`.
   */
  public static function pluginId(string $entity_type, string $bundle, string $field): string {
    return self::prefix($entity_type, $bundle) . $field;
  }

  /**
   * The derivative prefix of one bundle's field blocks.
   *
   * @param string $entity_type
   *   The subject's entity type id.
   * @param string $bundle
   *   The subject's bundle.
   *
   * @return string
   *   `field_block:{entity_type}:{bundle}:`.
   */
  public static function prefix(string $entity_type, string $bundle): string {
    return sprintf('%s:%s:%s:', self::PLUGIN, $entity_type, $bundle);
  }

  /**
   * Whether a plugin id is a field block derivative.
   */
  public static function isFieldBlock(string $plugin_id): bool {
    return str_starts_with($plugin_id, self::PLUGIN . ':');
  }

  /**
   * The field a field block derivative names, or NULL for any other plugin.
   *
   * @param string $plugin_id
   *   A block plugin id.
   *
   * @return string|null
   *   The field name (the derivative's fourth part), or NULL when the id is
   *   not a well-formed field block derivative.
   */
  public static function fieldOf(string $plugin_id): ?string {
    $parts = explode(':', $plugin_id);
    if (count($parts) !== 4 || $parts[0] !== self::PLUGIN || $parts[3] === '') {
      return NULL;
    }
    return $parts[3];
  }

  /**
   * The field a derivative names when it was derived for one subject.
   *
   * @param string $plugin_id
   *   A block plugin id.
   * @param string $entity_type
   *   The subject's entity type id.
   * @param string $bundle
   *   The subject's bundle.
   *
   * @return string|null
   *   The field name when the derivative is that bundle's, else NULL.
   */
  public static function fieldFor(string $plugin_id, string $entity_type, string $bundle): ?string {
    $prefix = self::prefix($entity_type, $bundle);
    return str_starts_with($plugin_id, $prefix) ? substr($plugin_id, strlen($prefix)) : NULL;
  }

  /**
   * The placeable fields of one bundle among a list of block definitions.
   *
   * A field is placeable exactly when core derived a field block for it
   * (a field type with a formatter), and the definitions handed in decide
   * the universe: every definition for the whole site, or the placement
   * spine's allowed definitions for one display.
   *
   * @param array $definitions
   *   Block plugin definitions keyed by plugin id.
   * @param string $entity_type
   *   The subject's entity type id.
   * @param string $bundle
   *   The subject's bundle.
   *
   * @return array
   *   The field block definitions keyed by FIELD name, in definition order.
   */
  public static function fieldsIn(array $definitions, string $entity_type, string $bundle): array {
    $fields = [];
    foreach ($definitions as $plugin_id => $definition) {
      $field = self::fieldFor((string) $plugin_id, $entity_type, $bundle);
      if ($field !== NULL && $field !== '') {
        $fields[$field] = $definition;
      }
    }
    return $fields;
  }

}
