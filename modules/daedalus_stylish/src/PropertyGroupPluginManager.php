<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;

/**
 * Property group plugin manager.
 */
final class PropertyGroupPluginManager extends DefaultPluginManager {

  /**
   * Lazily built map of CSS property name to owning group id.
   */
  private ?array $propertyMap = NULL;

  /**
   * Lazily built map of shorthand to the longhands it sets, every group.
   */
  private ?array $shorthands = NULL;

  /**
   * Memoized group instance carrying the shape-validation machinery.
   */
  private ?PropertyGroupBase $shapeValidator = NULL;

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/PropertyGroup', $namespaces, $module_handler, PropertyGroupInterface::class, PropertyGroup::class);
    $this->alterInfo('daedalus_stylish_property_group_info');
    $this->setCacheBackend($cache_backend, 'daedalus_stylish_property_group_plugins');
  }

  /**
   * Gets all property groups in inspector panel order.
   *
   * @return \Drupal\daedalus_stylish\PropertyGroupInterface[]
   *   Group instances keyed by plugin ID, sorted by weight then id.
   */
  public function getGroups(): array {
    $definitions = $this->getDefinitions();
    uasort($definitions, static fn ($a, $b) => [$a['weight'], $a['id']] <=> [$b['weight'], $b['id']]);
    $groups = [];
    foreach (array_keys($definitions) as $id) {
      $groups[$id] = $this->createInstance($id);
    }
    return $groups;
  }

  /**
   * Gets the curated groups: the authored, client-served surface.
   *
   * The generated wide-tier groups (data/wide-properties.json via the
   * 'wide' deriver) are server-side vocabulary only: the client lints
   * wide-tier properties through csstree's embedded mdn tables, which its
   * bundle already carries, so serving the generated definitions would
   * duplicate ~100KB into drupalSettings for nothing. Consumers presenting
   * groups to people or the client (the guide payload, guide scaffolding)
   * read this list; validation reads getGroups().
   *
   * @return \Drupal\daedalus_stylish\PropertyGroupInterface[]
   *   The non-generated group instances keyed by plugin ID, in getGroups()
   *   order.
   */
  public function getCuratedGroups(): array {
    return array_filter(
      $this->getGroups(),
      static fn (PropertyGroupInterface $group): bool => empty($group->getPluginDefinition()['generated']),
    );
  }

  /**
   * Gets the ids of the groups granted only at a pseudo-element.
   *
   * The groups whose definition carries the `pseudo` flag
   * (PropertyGroup::$pseudo): the vocabulary roster grants every one of
   * them wherever its walk lands on a pseudo-element ("&::before",
   * ".quote::after"), declared or derived, and the guide build refuses one
   * declared on a real element.
   *
   * @return string[]
   *   The plugin ids, in getGroups() order.
   */
  public function getPseudoGroups(): array {
    return array_keys(array_filter(
      $this->getGroups(),
      static fn (PropertyGroupInterface $group): bool => !empty($group->getPluginDefinition()['pseudo']),
    ));
  }

  /**
   * Gets the groups a declaration may name on a real element.
   *
   * Every group but the pseudo-only ones (getPseudoGroups()), which the
   * guide build refuses on a real element and the roster grants at every
   * pseudo-element by itself. Consumers that declare a part open to every
   * group (discovery's unconstrained parts, the guide scaffold) read this
   * list.
   *
   * @return \Drupal\daedalus_stylish\PropertyGroupInterface[]
   *   The group instances keyed by plugin ID, in getGroups() order.
   */
  public function getElementGroups(): array {
    return array_filter(
      $this->getGroups(),
      static fn (PropertyGroupInterface $group): bool => empty($group->getPluginDefinition()['pseudo']),
    );
  }

  /**
   * Whether a group is granted only at a pseudo-element.
   *
   * The validator's read behind STYLE_PROPERTY_PSEUDO_ONLY: a property
   * such a group owns is refused outside a pseudo-element in every mode.
   */
  public function isPseudoOnly(string $group_id): bool {
    return $this->hasDefinition($group_id) && !empty($this->getDefinition($group_id)['pseudo']);
  }

  /**
   * Gets the group that owns a CSS property.
   *
   * Every stylable property is owned by exactly one group. If two groups
   * declare the same property, the first in (weight, id) order wins,
   * deterministically.
   *
   * @return string|null
   *   The owning group's plugin ID, or NULL if no group declares the
   *   property (it cannot be styled at all).
   */
  public function getGroupIdForProperty(string $property): ?string {
    return $this->propertyMap()[$property] ?? NULL;
  }

  /**
   * Whether a property's values may carry media (url('media:<uuid>')).
   *
   * The owning group's definition carries the 'media' flag (see
   * PropertyGroupInterface::getProperties()); an unrecognized property is
   * not media-capable. The same flag PropertyGroupBase::validate() gates
   * tokens on, exposed at the manager so consumers (the style grammar's
   * input tier first among them) need not instantiate groups themselves.
   */
  public function isMediaCapable(string $property): bool {
    $group_id = $this->getGroupIdForProperty($property);
    if ($group_id === NULL) {
      return FALSE;
    }
    return !empty($this->createInstance($group_id)->getProperties()[$property]['media']);
  }

  /**
   * Gets every CSS property name any group declares.
   *
   * The complete recognized-property vocabulary, in (weight, id) group
   * order. This is the candidate pool corrective errors draw similarity
   * suggestions from when a map names a property no group owns.
   *
   * @return string[]
   *   The property names.
   */
  public function getPropertyNames(): array {
    return array_keys($this->propertyMap());
  }

  /**
   * Every shorthand family any group declares: shorthand => longhands.
   *
   * The groups' getShorthands() tables merged, in (weight, id) group
   * order; the vocabulary the family rule and the roster's corrective
   * read. A family whose shorthand or longhands the declaring group does
   * not own is a plugin bug and fails loudly.
   *
   * @return array<string, string[]>
   *   Shorthand => the longhands it sets.
   */
  public function getShorthands(): array {
    if ($this->shorthands === NULL) {
      $this->shorthands = [];
      foreach ($this->getGroups() as $id => $group) {
        $owned = array_keys($group->getProperties());
        foreach ($group->getShorthands() as $shorthand => $longhands) {
          if (!in_array($shorthand, $owned, TRUE) || array_diff($longhands, $owned) !== []) {
            throw new \LogicException(sprintf('The "%s" property group declares the "%s" shorthand family over names it does not own.', $id, $shorthand));
          }
          $this->shorthands[$shorthand] ??= array_values($longhands);
        }
      }
    }
    return $this->shorthands;
  }

  /**
   * Expands a declaration's property groups into the property names they own.
   *
   * The one derivation of "what may this declaration style" from a
   * property_groups map (the vocabulary roster's grants,
   * VocabularyRosterBuilder::vocabulary()): the group ids a guide declares
   * are internal vocabulary, but the property names inside them are
   * exactly what a strict-mode writer may use. A group declaring a
   * `properties` allowlist contributes only the allowed subset, the
   * expansion must never teach a property the grant excludes, and the
   * allowlist is stated over longhands: a shorthand of the group's
   * families (getShorthands()) is granted when every longhand it sets is
   * in the allowlist, never by its own name (the guide build refuses an
   * allowlist naming one, StyleGuide::validatePropertyAllowlists()). A
   * whole group grants its shorthands with everything else.
   *
   * @param array $declared_groups
   *   A declaration's property_groups map: group id => declared spec.
   *
   * @return string[]
   *   The stylable property names, in group order, deduplicated.
   */
  public function expandDeclaredProperties(array $declared_groups): array {
    $properties = [];
    foreach ($declared_groups as $group_id => $declared) {
      if (!$this->hasDefinition((string) $group_id)) {
        continue;
      }
      $group = $this->createInstance((string) $group_id);
      $owned = array_keys($group->getProperties());
      $allowed = is_array($declared) && is_array($declared['properties'] ?? NULL)
        && array_is_list($declared['properties']) && $declared['properties'] !== []
        ? array_map(strval(...), $declared['properties'])
        : NULL;
      if ($allowed === NULL) {
        array_push($properties, ...$owned);
        continue;
      }
      $families = $group->getShorthands();
      $granted = array_diff($allowed, array_keys($families));
      foreach ($families as $shorthand => $longhands) {
        if (array_diff($longhands, $granted) === []) {
          $granted[] = $shorthand;
        }
      }
      array_push($properties, ...array_values(array_intersect($owned, $granted)));
    }
    return array_values(array_unique($properties));
  }

  /**
   * Validates a single value against a bare shape, outside any property.
   *
   * The manager-level face of PropertyGroupBase::validateShape() (the
   * token-value seam): shape validation is plugin-independent — every group
   * carries the identical scalar machinery — so the manager hides the
   * arbitrary choice of instance from consumers (skin token validation
   * today, var() reverse-lookup checking later).
   *
   * @see \Drupal\daedalus_stylish\PropertyGroupBase::validateShape()
   */
  public function validateShape(string $path, string $value, string $shape, array $keywords = []): array {
    if ($this->shapeValidator === NULL) {
      $instance = $this->createInstance((string) array_key_first($this->getDefinitions()));
      assert($instance instanceof PropertyGroupBase);
      $this->shapeValidator = $instance;
    }
    return $this->shapeValidator->validateShape($path, $value, $shape, $keywords);
  }

  /**
   * Builds (lazily) the property-name-to-owning-group-id map.
   */
  private function propertyMap(): array {
    if ($this->propertyMap === NULL) {
      $this->propertyMap = [];
      foreach ($this->getGroups() as $id => $group) {
        foreach (array_keys($group->getProperties()) as $name) {
          $this->propertyMap[$name] ??= $id;
        }
      }
    }
    return $this->propertyMap;
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions(): void {
    parent::clearCachedDefinitions();
    $this->propertyMap = NULL;
    $this->shorthands = NULL;
    $this->shapeValidator = NULL;
  }

}
