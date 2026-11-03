<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\daedalus_stylish\Attribute\ConditionType;
use Drupal\daedalus_blueprint\Materials\ConditionClause;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;

/**
 * Condition type plugin manager.
 *
 * The authority on the condition vocabulary: the flattened name → definition
 * table drives the printer's wrappers, the validator's membership check, and
 * the AI schema's condition list, so all three can never disagree. "default"
 * is not a condition — it is the reserved always-applies entry key handled
 * by the printer and validator directly.
 *
 * The COMPOUND grammar is ConditionClause's, read over this table:
 * conditions live on axes (state/width/scheme/motion, declared per
 * condition type), a style entry may key on an "a+b" compound of clauses
 * holding at most one clause per axis (a clause a published name or a
 * literal the browser knows), and the canonical clause order is
 * axis-declaration order (canonicalizeCondition()) — accepted in any order
 * on write, normalized server-side, exactly the posture variant references
 * take toward axis order. A single condition is the compound of one. The
 * methods here are the manager's adapters over the grammar, handing it the
 * table; the client twin reads the guide payload's table the same way.
 */
final class ConditionTypePluginManager extends DefaultPluginManager {

  /**
   * The delimiter joining a compound condition's parts.
   */
  public const COMPOUND_DELIMITER = ConditionClause::DELIMITER;

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
   * The published condition a pseudo-class name is the CSS of, or NULL.
   *
   * The roster's read of "is this pseudo-class a state this site
   * publishes": a qualifier ":hover" on any compound names the condition
   * whose wrapper is ":hover" (the twin of DialectCatalog::
   * pseudoClassCondition() on the AI side). The name is bare ("hover",
   * never ":hover"), as the grammar records a pseudo-class qualifier.
   *
   * @param string $name
   *   The bare pseudo-class name.
   *
   * @return string|null
   *   The condition name, or NULL when no published condition is spelled
   *   by that pseudo-class.
   */
  public function conditionForPseudoClass(string $name): ?string {
    return static::conditionSpelledBy($this->getConditions(), $name);
  }

  /**
   * The condition a pseudo-class name spells, over a condition table.
   *
   * The one lookup behind conditionForPseudoClass(), static so a reader
   * holding the table rather than the manager (CssPrinter's static target
   * seams, handed getConditions() by their caller) asks the same question
   * the same way; the client twin is style-guide.js conditionForPseudoClass
   * over the guide payload's `conditions`.
   *
   * @param array $conditions
   *   A condition table: name => definition with a `wrapper` key
   *   (getConditions()'s shape, or the client payload's).
   * @param string $name
   *   The bare pseudo-class name.
   *
   * @return string|null
   *   The condition name, or NULL when no condition in the table is
   *   spelled by that pseudo-class.
   */
  public static function conditionSpelledBy(array $conditions, string $name): ?string {
    foreach ($conditions as $condition => $definition) {
      if (($definition['wrapper'] ?? NULL) === ':' . $name) {
        return (string) $condition;
      }
    }
    return NULL;
  }

  /**
   * Gets the axis a condition name lives on.
   *
   * @return string|null
   *   The axis ("state", "width", "scheme", "motion"), or
   *   NULL for an unknown condition.
   */
  public function getAxis(string $name): ?string {
    return $this->getConditions()[$name]['axis'] ?? NULL;
  }

  /**
   * The condition axes, in axis-declaration order.
   *
   * The canonical compound clause order: the order axes first appear
   * across the flattened condition table (itself (weight, id)-ordered
   * across the types), ConditionClause::axisOrder() over the table. One
   * vocabulary point, so the validator's one-per-axis check,
   * canonicalizeCondition(), and any UI grouping can never disagree.
   *
   * @return string[]
   *   The axis names, each once, in first-appearance order.
   */
  public function conditionAxes(): array {
    return ConditionClause::axisOrder($this->getConditions());
  }

  /**
   * Splits a condition entry key into its compound clauses.
   *
   * The ONE compound split (ConditionClause::split()): clauses joined by
   * "+" at the top level, a "+" inside a literal's parentheses being the
   * literal's own. A single condition is the compound of one — every
   * consumer (validator membership, printer wrapper composition, container
   * detection) iterates clauses through here so the grammar cannot fork.
   *
   * @return string[]
   *   The clause texts, in spelled order.
   */
  public static function compoundParts(string $condition): array {
    return ConditionClause::split($condition);
  }

  /**
   * Canonicalizes a compound condition's spelling.
   *
   * Write paths accept clauses in any order (AI-friendly: "dark+narrow" and
   * "narrow+dark" name the same condition) and normalize the stored
   * spelling here (ConditionClause::canonical() over the table): clauses
   * sort by their axis's position in conditionAxes(), the
   * orderedEntryRefs() posture applied to conditions, a literal prints its
   * canonical text and a literal spelling a published wrapper prints the
   * published name. Unknown words sort after every placed clause, keeping
   * spelled order among themselves, so canonicalization never invents or
   * drops anything; validity is the validator's job. "default" and the
   * reserved entry keys are unknown words of one, so they pass through
   * untouched.
   */
  public function canonicalizeCondition(string $condition): string {
    return ConditionClause::canonical($condition, $this->getConditions());
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
   * The tokens shape is {scale: {name: record}} with the value inside the
   * record ({value, description, offered}, SkinTokensItem), so each
   * record's value normalizes through normalizeValueConditions():
   * unconditional string values pass through untouched, condition-keyed
   * values get canonical compound spellings. Entries that are not records
   * pass through verbatim (the validator has refused them already).
   *
   * @see self::normalizeValueConditions()
   */
  public function normalizeTokensConditions(array $tokens): array {
    foreach ($tokens as $scale => $names) {
      if (!is_array($names) || array_is_list($names)) {
        continue;
      }
      foreach ($names as $name => $record) {
        if (SkinTokensItem::isRecord($record) && (is_string($record['value']) || is_array($record['value']))) {
          $tokens[$scale][$name]['value'] = $this->normalizeValueConditions($record['value']);
        }
      }
    }
    return $tokens;
  }

  /**
   * Canonicalizes the condition keys of a single named VALUE, for storage.
   *
   * The one-value slice of the token grammar ({condition: css} with
   * a 'default' entry): plain strings pass through untouched, condition
   * keys get canonical compound spellings — the write-side posture every
   * value store shares (token values are the one caller).
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
   * Compound-aware: a compound uses containers when ANY of its clauses is
   * of the container form ("narrow+dark" nests an @container wrapper; so
   * does a literal "@container (min-width: 500px)").
   */
  public function isContainerCondition(string $name): bool {
    foreach (ConditionClause::parse($name, $this->getConditions()) as $clause) {
      if ($clause['form'] === ConditionClause::CONTAINER) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a condition tests the user's color scheme preference.
   *
   * Keyed on the preference the clause grammar derives from the clause's
   * CSS (ConditionClause: preference.feature is "prefers-color-scheme"),
   * the same read the canvas emulation matches a forced scheme against.
   * Compound-aware like isContainerCondition(): "narrow+dark" is a scheme
   * condition because one of its clauses is. The defaults file reads this
   * to decide whether the skin's values follow the visitor's scheme and so
   * whether the UA's own chrome must too (CssPrinter::COLOR_SCHEME_RULE).
   */
  public function isSchemeCondition(string $name): bool {
    foreach (ConditionClause::parse($name, $this->getConditions()) as $clause) {
      if (($clause['preference']['feature'] ?? NULL) === 'prefers-color-scheme') {
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
