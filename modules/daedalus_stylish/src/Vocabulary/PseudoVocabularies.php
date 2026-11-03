<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Vocabulary;

use Drupal\Core\Extension\ModuleExtensionList;

/**
 * The per-pseudo-element vocabularies: what the browser styles on a box.
 *
 * The ONE server reader of data/pseudo-vocabularies.json, a hand-maintained
 * table (no package carries it; each entry cites its specification) of
 * the properties the browser applies to a pseudo-element whose
 * vocabulary is not its host's: `::marker` (CSS Lists / CSS Pseudo-
 * Elements 4), `::placeholder`, `::selection`, `::first-letter` and
 * `::first-line`. The client twin is js/src/pseudo-vocabularies.js, which
 * bundles the same file; tests/js/pseudo-vocabularies.test.mjs guards the
 * file's shape, and VocabularyRosterTest locks the table to the property
 * universe the manager knows.
 *
 * Two readers, two meanings. VocabularyRosterBuilder::pseudoNode() grants
 * a table pseudo-element from its allowlist (the curated groups the panel
 * offers; a declared `pseudo:` section still wins, and a pseudo-element
 * the table does not carry derives its host's groups as `::before` and
 * `::after` do). StyleMapValidator reads the table as a law of the
 * browser, in every enforcement mode: a property outside a table
 * pseudo-element's allowlist is refused (STYLE_PROPERTY_NOT_ON_PSEUDO)
 * because the browser would ignore it, exactly as `content` off a
 * generated box is. Admission by mode (is this box styled on THIS site)
 * stays the roster's: declared-or-derived, strict refusing the
 * undeclared.
 *
 * A missing or malformed file fails loudly: a silently empty table would
 * grant every marker its host's padding.
 */
class PseudoVocabularies {

  /**
   * The decoded file, once read.
   *
   * @var array<string, array{spec: string, properties: string[]}>|null
   */
  protected ?array $table = NULL;

  public function __construct(protected ModuleExtensionList $moduleList) {}

  /**
   * Whether the table carries a vocabulary for a pseudo-element.
   *
   * @param string $pseudo
   *   The bare name ("marker", never "::marker").
   */
  public function has(string $pseudo): bool {
    return isset($this->table()[$pseudo]);
  }

  /**
   * The properties the browser styles on a pseudo-element, or NULL.
   *
   * @param string $pseudo
   *   The bare name.
   *
   * @return string[]|null
   *   The allowlist in the table's order, NULL for a pseudo-element the
   *   table does not carry (its vocabulary is its host's).
   */
  public function properties(string $pseudo): ?array {
    return $this->table()[$pseudo]['properties'] ?? NULL;
  }

  /**
   * The specification a pseudo-element's allowlist is read from, or NULL.
   */
  public function spec(string $pseudo): ?string {
    return $this->table()[$pseudo]['spec'] ?? NULL;
  }

  /**
   * Every pseudo-element the table carries, in the table's order.
   *
   * @return string[]
   *   The bare names.
   */
  public function names(): array {
    return array_keys($this->table());
  }

  /**
   * The committed file, decoded once.
   */
  protected function table(): array {
    if ($this->table === NULL) {
      $path = $this->moduleList->getPath('daedalus_stylish') . '/data/pseudo-vocabularies.json';
      $table = json_decode(file_get_contents($path), TRUE, flags: JSON_THROW_ON_ERROR);
      if (!is_array($table) || $table === []) {
        throw new \RuntimeException(sprintf('%s carries no pseudo-element vocabularies.', $path));
      }
      foreach ($table as $pseudo => $entry) {
        if (!is_array($entry['properties'] ?? NULL) || $entry['properties'] === [] || !is_string($entry['spec'] ?? NULL)) {
          throw new \RuntimeException(sprintf('%s: the ::%s entry must carry a spec url and a non-empty properties list.', $path, $pseudo));
        }
      }
      $this->table = $table;
    }
    return $this->table;
  }

}
