<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\daedalus_stylish\Attribute\ConditionType;

/**
 * Condition type plugin manager.
 *
 * The authority on the condition vocabulary: the flattened name → definition
 * table drives the printer's wrappers, the validator's membership check, and
 * the AI schema's condition list, so all three can never disagree. "default"
 * is not a condition — it is the reserved always-applies entry key handled
 * by the printer and validator directly.
 *
 * Also the authority on the COMPOUND grammar: conditions live on axes
 * (width/scheme/motion/state, declared per condition type), a style entry
 * may key on an "a+b" compound holding at most one condition per axis, and
 * the canonical part order is axis-declaration order
 * (canonicalizeCondition()) — accepted in any order on write, normalized
 * server-side, exactly the posture variant references take toward axis
 * order. A single condition is the compound of one.
 */
final class ConditionTypePluginManager extends DefaultPluginManager {

  /**
   * The delimiter joining a compound condition's parts.
   */
  public const COMPOUND_DELIMITER = '+';

  /**
   * Lazily built flattened map of condition name to definition.
   */
  private ?array $conditions = NULL;

  /**
   * Constructs the object.
   */
  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct('Plugin/ConditionType', $namespaces, $module_handler, ConditionTypeInterface::class, ConditionType::class);
    $this->alterInfo('daedalus_stylish_condition_type_info');
    $this->setCacheBackend($cache_backend, 'daedalus_stylish_condition_type_plugins');
  }

  /**
   * Gets all conditions the installed condition types provide.
   *
   * @return array
   *   Condition definitions keyed by condition name, each with label,
   *   wrapper (see ConditionTypeInterface::getConditions()), type (the
   *   providing plugin's ID), and axis (the type's declared axis). When two
   *   types declare the same name, the first in (weight, id) order wins,
   *   deterministically. Reserved entry keys ("default", "variants") are
   *   never conditions and are dropped if a plugin declares them; so is a
   *   name containing the compound delimiter — compounds are spelled from
   *   conditions, never declared as one.
   */
  public function getConditions(): array {
    if ($this->conditions === NULL) {
      $this->conditions = [];
      $definitions = $this->getDefinitions();
      uasort($definitions, static fn ($a, $b) => [$a['weight'], $a['id']] <=> [$b['weight'], $b['id']]);
      foreach ($definitions as $type_id => $type_definition) {
        foreach ($this->createInstance($type_id)->getConditions() as $name => $definition) {
          if ($name === 'default' || in_array($name, StyleMapValidator::RESERVED_ENTRY_KEYS, TRUE) || str_contains($name, self::COMPOUND_DELIMITER)) {
            continue;
          }
          $this->conditions[$name] ??= $definition + ['type' => $type_id, 'axis' => $type_definition['axis']];
        }
      }
    }
    return $this->conditions;
  }

  /**
   * Whether a condition name exists.
   */
  public function hasCondition(string $name): bool {
    return isset($this->getConditions()[$name]);
  }

  /**
   * All condition names, in declaration order.
   *
   * @return string[]
   *   Condition names, not including the reserved "default".
   */
  public function getConditionNames(): array {
    return array_keys($this->getConditions());
  }

  /**
   * Gets the CSS wrapper for a condition name.
   *
   * @return string|null
   *   The wrapper (":hover" pseudo suffix or "@..." at-rule prelude), or
   *   NULL for an unknown condition.
   */
  public function getWrapper(string $name): ?string {
    return $this->getConditions()[$name]['wrapper'] ?? NULL;
  }

  /**
   * Gets the axis a condition name lives on.
   *
   * @return string|null
   *   The axis ("width", "scheme", "motion", "state"), or NULL for an
   *   unknown condition.
   */
  public function getAxis(string $name): ?string {
    return $this->getConditions()[$name]['axis'] ?? NULL;
  }

  /**
   * The condition axes, in axis-declaration order.
   *
   * The canonical compound part order: the order axes first appear across
   * the flattened condition table (itself (weight, id)-ordered across the
   * types). One vocabulary point, so the validator's one-per-axis check,
   * canonicalizeCondition(), and any UI grouping can never disagree.
   *
   * @return string[]
   *   The axis names, each once, in first-appearance order.
   */
  public function conditionAxes(): array {
    $axes = [];
    foreach ($this->getConditions() as $definition) {
      $axes[$definition['axis']] = TRUE;
    }
    return array_keys($axes);
  }

  /**
   * Splits a condition entry key into its compound parts.
   *
   * The ONE compound grammar: parts joined by "+". A single condition is
   * the compound of one — every consumer (validator membership, printer
   * wrapper composition, container detection) iterates parts through here
   * so the grammar cannot fork.
   *
   * @return string[]
   *   The parts, in spelled order.
   */
  public static function compoundParts(string $condition): array {
    return explode(self::COMPOUND_DELIMITER, $condition);
  }

  /**
   * Canonicalizes a compound condition's part order.
   *
   * Write paths accept parts in any order (AI-friendly: "dark+narrow" and
   * "narrow+dark" name the same condition) and normalize the stored
   * spelling here — parts sort by their axis's position in
   * conditionAxes(), the orderedEntryRefs() posture applied to conditions.
   * Unknown parts sort after every placed part, keeping spelled order
   * among themselves, so canonicalization never invents or drops anything;
   * validity is the validator's job. Non-compound keys (single conditions,
   * "default", reserved entry keys) pass through untouched.
   */
  public function canonicalizeCondition(string $condition): string {
    if (!str_contains($condition, self::COMPOUND_DELIMITER)) {
      return $condition;
    }
    $order = array_flip($this->conditionAxes());
    $sortable = [];
    foreach (self::compoundParts($condition) as $index => $part) {
      $axis = $this->getAxis($part);
      // The index tiebreak keeps the sort stable across PHP versions.
      $sortable[] = [$axis !== NULL ? $order[$axis] : PHP_INT_MAX, $index, $part];
    }
    usort($sortable, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    return implode(self::COMPOUND_DELIMITER, array_column($sortable, 2));
  }

  /**
   * Canonicalizes every condition key of a style map, for storage.
   *
   * The write-side half of the any-order posture: validation accepted the
   * spelled order, storage keeps the canonical one, so identical compounds
   * can never fork into distinct keys. Works on both condition-keyed map
   * shapes — selectable-keyed style maps and element-relative variant
   * styles — because entries are condition-keyed in both. Non-array
   * entries and reserved keys ("variants") pass through untouched;
   * colliding spellings were already rejected by validation
   * (DUPLICATE_STYLE_CONDITION), so on the spine paths this is purely a
   * rename.
   */
  public function normalizeMapConditions(array $map): array {
    $normalized = [];
    foreach ($map as $key => $entry) {
      if (!is_array($entry)) {
        $normalized[$key] = $entry;
        continue;
      }
      $rebuilt = [];
      foreach ($entry as $condition => $value) {
        $rebuilt[$this->canonicalizeCondition((string) $condition)] = $value;
      }
      $normalized[$key] = $rebuilt;
    }
    return $normalized;
  }

  /**
   * Canonicalizes every condition key of a skin tokens map, for storage.
   *
   * The tokens shape is one level deeper than a style map ({scale: {name:
   * value-or-condition-map}}), so each scale's token map normalizes through
   * normalizeMapConditions(): unconditional string values pass through
   * untouched, condition-keyed values get canonical compound spellings.
   *
   * @see self::normalizeMapConditions()
   */
  public function normalizeTokensConditions(array $tokens): array {
    foreach ($tokens as $scale => $names) {
      if (is_array($names)) {
        $tokens[$scale] = $this->normalizeMapConditions($names);
      }
    }
    return $tokens;
  }

  /**
   * Canonicalizes the condition keys of a single named VALUE, for storage.
   *
   * The one-value slice of the token/swatch grammar ({condition: css} with
   * a 'default' entry): plain strings pass through untouched, condition
   * keys get canonical compound spellings — the write-side posture every
   * value store shares (swatch values are the first caller).
   *
   * @see self::normalizeMapConditions()
   */
  public function normalizeValueConditions(string|array $value): string|array {
    if (!is_array($value)) {
      return $value;
    }
    $rebuilt = [];
    foreach ($value as $condition => $conditioned) {
      $rebuilt[$this->canonicalizeCondition((string) $condition)] = $conditioned;
    }
    return $rebuilt;
  }

  /**
   * Whether a condition wraps any declarations in a container query.
   *
   * Compound-aware: a compound uses containers when ANY of its parts does
   * ("narrow+dark" nests an @container wrapper).
   */
  public function isContainerCondition(string $name): bool {
    foreach (self::compoundParts($name) as $part) {
      $wrapper = $this->getWrapper($part);
      if ($wrapper !== NULL && str_starts_with($wrapper, '@container')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a style map styles anything under a container query condition.
   *
   * Container queries simply never apply without an ancestor query
   * container (probed), so a map that uses one is what makes the CSS
   * backend ship the page-level width-band query root's rule
   * (CssPrinter::PAGE_CONTAINER_RULE, see CssStyleBackend). Also answers
   * for a variant's styles: same shape, same detection.
   */
  public function mapUsesContainerConditions(array $map): bool {
    foreach ($map as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      foreach ($entry as $condition => $value) {
        // The reference list is not a condition; the referenced variants'
        // own width conditions ship their query root with the CSS that
        // needs it (VariantStylesheet's compiled file and workspace inline
        // fallback carry their own page-container rule).
        if ($condition === 'variants') {
          continue;
        }
        if ($this->isContainerCondition((string) $condition)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Whether the page-container rule may be emitted at all (the kill switch).
   *
   * ON by default: without the page-level query container, container
   * breakpoint conditions silently never apply. A theme that manages its
   * own containment (or breaks under it: fixed-position elements are
   * contained by a query container) turns this off and declares a
   * container in its own CSS.
   */
  public function containerRootsEnabled(): bool {
    return (bool) ($this->configFactory->get('daedalus_stylish.settings')->get('container_roots') ?? TRUE);
  }

  /**
   * Whether a style map demands the page-level width-band query root.
   */
  public function demandsContainerRoots(array $map): bool {
    return $this->containerRootsEnabled() && $this->mapUsesContainerConditions($map);
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions(): void {
    parent::clearCachedDefinitions();
    $this->conditions = NULL;
  }

}
