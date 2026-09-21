<?php

declare(strict_types=1);

namespace Drupal\daedalus\Migrate\PlusSuite;

/**
 * The rename mapping: the old suite's vocabulary translated to daedalus's.
 *
 * Reads migrate/plus_suite/mapping.tsv, the one table the code-rename script beside the
 * parent topic note and the site migration share, and applies its rows with
 * the script's boundary rules so a stored value and a source file translate
 * the same way. Rows are `kind<TAB>old<TAB>new<TAB>note`; the kinds and the
 * boundaries each one puts around its literal `old` are documented at the
 * top of the table. Literal rows are tried at every position id/prefix/lcamel
 * first, then camel, longest first within each (ties in table order); regex
 * rows run before the literal pass; protect and keep rows are masked before
 * the passes and restored after; and every literal refuses a `daedalus` or
 * `Daedalus` immediately before it, which makes translation idempotent.
 * floor rows carry the oldest schema version served per old module; forbid
 * and skip rows are the script's and are ignored here.
 */
final class Mapping {

  /**
   * The table the package ships.
   */
  public const DEFAULT_PATH = __DIR__ . '/../../../migrate/plus_suite/mapping.tsv';

  /**
   * The delimiter regex rows are compiled with; a row may not contain it.
   */
  private const DELIMITER = '~';

  private const GUARD = '(?<!daedalus_)(?<!daedalus-)(?<!daedalus)(?<!Daedalus)(?<!Daedalus )(?<!Daedalus\.)(?<!Daedalus\?\.)';

  private const BOUNDS = [
    'id' => ['(?<![A-Za-z0-9])', '(?![A-Za-z0-9])'],
    'prefix' => ['(?<![A-Za-z0-9])', ''],
    'lcamel' => ['(?<![A-Za-z0-9])', '(?![a-z])'],
    'camel' => ['(?<![A-Z])', '(?![a-z])'],
  ];

  private const GROUP_RANK = ['id' => 0, 'prefix' => 0, 'lcamel' => 0, 'camel' => 1];

  private const KINDS = ['id', 'prefix', 'camel', 'lcamel', 'regex', 'protect', 'keep', 'forbid', 'floor', 'skip'];

  /**
   * The literal pass: one alternation over every literal row, guarded.
   */
  private readonly string $literalRegex;

  /**
   * Literal rows by their `old`, in group-then-length-then-table order.
   *
   * @var array<string, list<array{kind: string, old: string, new: string, note: string}>>
   */
  private readonly array $byOld;

  /**
   * The regex rows compiled: [pattern, replacement] pairs in table order.
   *
   * @var list<array{string, string}>
   */
  private readonly array $regexRows;

  /**
   * The protect and keep rows compiled, in table order.
   *
   * @var list<string>
   */
  private readonly array $masks;

  /**
   * Constructs the mapping from parsed rows; fromFile() is the usual door.
   *
   * @param list<array{kind: string, old: string, new: string, note: string, order: int}> $rows
   *   The table's rows in table order.
   */
  public function __construct(private readonly array $rows) {
    $literal = [];
    $regex_rows = [];
    $masks = [];
    foreach ($rows as $row) {
      match ($row['kind']) {
        'id', 'prefix', 'camel', 'lcamel' => $literal[] = $row,
        'regex' => $regex_rows[] = [$this->compile($row['old']), $row['new']],
        'protect', 'keep' => $masks[] = $this->compile($row['old']),
        default => NULL,
      };
    }
    usort($literal, static fn(array $a, array $b): int =>
      [self::GROUP_RANK[$a['kind']], strlen($b['old']), $a['order']]
      <=> [self::GROUP_RANK[$b['kind']], strlen($a['old']), $b['order']]
    );
    $branches = [];
    $by_old = [];
    foreach ($literal as $row) {
      [$left, $right] = self::BOUNDS[$row['kind']];
      $branches[$left][] = preg_quote($row['old'], self::DELIMITER) . $right;
      $by_old[$row['old']][] = $row;
    }
    $groups = [];
    foreach ([self::BOUNDS['id'][0], self::BOUNDS['camel'][0]] as $left) {
      if (isset($branches[$left])) {
        $groups[] = $left . '(?:' . implode('|', $branches[$left]) . ')';
      }
    }
    $this->literalRegex = self::DELIMITER . self::GUARD . '(?:' . implode('|', $groups) . ')' . self::DELIMITER;
    $this->byOld = $by_old;
    $this->regexRows = $regex_rows;
    $this->masks = $masks;
  }

  /**
   * Parses a mapping table in the script's grammar.
   *
   * @param string|null $path
   *   The table; the package's own by default.
   */
  public static function fromFile(?string $path = NULL): self {
    $path ??= self::DEFAULT_PATH;
    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new \RuntimeException("No mapping table at $path.");
    }
    $rows = [];
    foreach (explode("\n", $contents) as $number => $line) {
      if (preg_match('/^\s*(#|$)/', $line)) {
        continue;
      }
      $cells = explode("\t", $line, 4);
      if (count($cells) < 3) {
        throw new \UnexpectedValueException(sprintf('Bad table line %d in %s: %s', $number + 1, $path, $line));
      }
      [$kind, $old, $new] = $cells;
      if (!in_array($kind, self::KINDS, TRUE)) {
        throw new \UnexpectedValueException(sprintf("Unknown kind '%s' on line %d in %s.", $kind, $number + 1, $path));
      }
      $rows[] = ['kind' => $kind, 'old' => $old, 'new' => $new, 'note' => $cells[3] ?? '', 'order' => count($rows)];
    }
    return new self($rows);
  }

  /**
   * The table's rows, optionally those of one kind, in table order.
   *
   * @return list<array{kind: string, old: string, new: string, note: string, order: int}>
   *   The rows.
   */
  public function rows(?string $kind = NULL): array {
    return $kind === NULL ? $this->rows : array_values(array_filter($this->rows, static fn(array $row): bool => $row['kind'] === $kind));
  }

  /**
   * The oldest schema version served per old module, from the floor rows.
   *
   * @return array<string, int>
   *   Old module name to the oldest schema version served.
   */
  public function floor(): array {
    $floor = [];
    foreach ($this->rows('floor') as $row) {
      $floor[$row['old']] = (int) $row['new'];
    }
    return $floor;
  }

  /**
   * Translates one string with the script's passes and boundary rules.
   */
  public function translate(string $text): string {
    $store = [];
    foreach ($this->masks as $mask) {
      $text = preg_replace_callback($mask, static function (array $match) use (&$store): string {
        $store[] = $match[0];
        return "\x00" . (count($store) - 1) . "\x00";
      }, $text);
    }
    foreach ($this->regexRows as [$pattern, $replacement]) {
      $text = preg_replace($pattern, $replacement, $text);
    }
    $subject = $text;
    preg_match_all($this->literalRegex, $subject, $matches, PREG_OFFSET_CAPTURE);
    $text = '';
    $last = 0;
    foreach ($matches[0] as [$literal, $start]) {
      $end = $start + strlen($literal);
      $text .= substr($subject, $last, $start - $last) . $this->attribute($subject, $start, $end)['new'];
      $last = $end;
    }
    $text .= substr($subject, $last);
    if ($store) {
      $text = preg_replace_callback('/\x00(\d+)\x00/', static fn(array $match): string => $store[(int) $match[1]], $text);
    }
    return $text;
  }

  /**
   * The old literals the id, prefix, camel and lcamel rows find in a text.
   *
   * The audit's grep over a site's custom code: the same masks and boundary
   * rules as translate(), without the regex rows, so a hit is a literal the
   * table would rename. Distinct, in order of first appearance.
   *
   * @return list<string>
   *   The old literals found.
   */
  public function hits(string $text): array {
    foreach ($this->masks as $mask) {
      $text = preg_replace($mask, "\x00", $text);
    }
    preg_match_all($this->literalRegex, $text, $matches);
    return array_values(array_unique($matches[0]));
  }

  /**
   * Translates a value by walking it: strings, array keys and values, objects.
   *
   * Arrays come back by value with their keys translated too (a config key
   * naming an old module is a name too). An object is copied and every
   * property of the copy, private ones of parent classes included, is
   * walked by reflection, so an unserialized
   * \Drupal\layout_builder\Section round-trips with its third-party settings
   * renamed and its components untouched. Enums and closures pass through.
   * Each object of the graph is copied once and every reference to it, a
   * shared one or a circular one, lands on that one copy: a stored
   * \Drupal\Core\Field\BaseFieldDefinition and its item definition point at
   * each other, and the walk ends where serialization does. A serialized
   * string inside a value (an inline block component's block_serialized) is
   * unserialized, walked and re-serialized.
   */
  public function translateDeep(mixed $value): mixed {
    return $this->walk($value, new \SplObjectStorage());
  }

  /**
   * The walk behind translateDeep(), carrying the copies made so far.
   *
   * @param mixed $value
   *   The value to translate.
   * @param \SplObjectStorage<object, object> $clones
   *   Every object seen (originals and their copies) to its copy.
   */
  private function walk(mixed $value, \SplObjectStorage $clones): mixed {
    if (is_string($value)) {
      return self::isSerialized($value) ? $this->walkSerialized($value, $clones) : $this->translate($value);
    }
    if (is_array($value)) {
      $translated = [];
      foreach ($value as $key => $item) {
        $translated[is_string($key) ? $this->translate($key) : $key] = $this->walk($item, $clones);
      }
      return $translated;
    }
    if (!is_object($value) || $value instanceof \UnitEnum || $value instanceof \Closure) {
      return $value;
    }
    if (isset($clones[$value])) {
      return $clones[$value];
    }
    $reflection = new \ReflectionObject($value);
    // A userland object is rebuilt from an uninitialized instance, property
    // by property from the original, never cloned: a class's __clone does
    // what serialization never carries (an entity's loads its field
    // definitions and the services behind them, which cannot be cloned),
    // and a readonly property (a stored core Checkpoint's) is initialized
    // once, so a clone could not take a translated value. An internal
    // class keeps its state through clone, which reflection cannot copy.
    $rebuild = !$reflection->isInternal();
    $clone = $rebuild ? $reflection->newInstanceWithoutConstructor() : clone $value;
    $source = $rebuild ? $value : $clone;
    $clones[$value] = $clone;
    $clones[$clone] = $clone;
    for ($class = $reflection; $class; $class = $class->getParentClass()) {
      foreach ($class->getProperties() as $property) {
        if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName() || !$property->isInitialized($source)) {
          continue;
        }
        $property->setValue($clone, $this->walk($property->getValue($source), $clones));
      }
    }
    return $clone;
  }

  /**
   * Translates a serialized string inside a value.
   *
   * Unserialized, walked and re-serialized, so the string lengths inside it
   * are right.
   *
   * A Layout Builder inline block component stores its unsaved block as
   * serialized PHP in its configuration (block_serialized), a string inside
   * the stored section: translating it as text would leave the lengths
   * inside it wrong. Only a value the walk changes is re-serialized; an
   * untouched one keeps its bytes.
   */
  private function walkSerialized(string $serialized, \SplObjectStorage $clones): string {
    $value = self::unserializeStored($serialized, 'A serialized string inside a stored value');
    $translated = serialize($this->walk($value, $clones));
    return $translated === serialize($value) ? $serialized : $translated;
  }

  /**
   * Whether a string is a serialized PHP array or object.
   */
  public static function isSerialized(string $value): bool {
    return preg_match('/^(?:a:\d+:\{|O:\d+:"[^"]+":\d+:\{)/', $value) === 1;
  }

  /**
   * Unserializes a stored value the walk can carry, or fails naming it.
   *
   * A value holding an object of a class no longer on disk cannot be walked
   * (the walk needs the class) and would not survive a round trip as
   * anything but an incomplete object, so it stops the phase with the value
   * named rather than being carried blind.
   *
   * @param string $serialized
   *   The stored text.
   * @param string $where
   *   What the text is, for the failure message (a row, a key).
   */
  public static function unserializeStored(string $serialized, string $where): mixed {
    preg_match_all('/[OC]:\d+:"([^"]+)":/', $serialized, $matches);
    foreach (array_unique($matches[1]) as $class) {
      if (!class_exists($class)) {
        throw new \RuntimeException("$where holds an object of the class $class, which is not on disk; the rename cannot carry it.");
      }
    }
    // The classes were just checked to be on disk; a list would have to name
    // every class a site may store (core's definitions and markup included).
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize
    $value = unserialize($serialized, ['allowed_classes' => TRUE]);
    if ($value === FALSE && $serialized !== serialize(FALSE)) {
      throw new \RuntimeException("$where does not unserialize.");
    }
    return $value;
  }

  /**
   * The row a literal match belongs to.
   *
   * The rows sharing that literal, in group-then-table order, the first
   * whose boundaries hold at this spot (the id row of a human name before the
   * camel row of the same literal).
   */
  private function attribute(string $text, int $start, int $end): array {
    $candidates = $this->byOld[substr($text, $start, $end - $start)];
    if (count($candidates) === 1) {
      return $candidates[0];
    }
    $previous = $start > 0 ? $text[$start - 1] : '';
    $next = $end < strlen($text) ? $text[$end] : '';
    foreach ($candidates as $row) {
      [$left, $right] = self::BOUNDS[$row['kind']];
      if ($left === '(?<![A-Za-z0-9])' && self::isAlnum($previous)) {
        continue;
      }
      if ($left === '(?<![A-Z])' && self::isUpper($previous)) {
        continue;
      }
      if ($right === '(?![A-Za-z0-9])' && self::isAlnum($next)) {
        continue;
      }
      if ($right === '(?![a-z])' && self::isLower($next)) {
        continue;
      }
      return $row;
    }
    throw new \LogicException(sprintf("No row matched '%s'.", substr($text, $start, $end - $start)));
  }

  /**
   * Whether a byte is in [A-Za-z0-9]; an empty string (no byte) is not.
   */
  private static function isAlnum(string $byte): bool {
    return $byte !== '' && preg_match('/[A-Za-z0-9]/', $byte) === 1;
  }

  /**
   * Whether a byte is in [A-Z].
   */
  private static function isUpper(string $byte): bool {
    return $byte !== '' && preg_match('/[A-Z]/', $byte) === 1;
  }

  /**
   * Whether a byte is in [a-z].
   */
  private static function isLower(string $byte): bool {
    return $byte !== '' && preg_match('/[a-z]/', $byte) === 1;
  }

  /**
   * Compiles a regex row, a protect row or a keep row.
   */
  private function compile(string $pattern): string {
    if (str_contains($pattern, self::DELIMITER)) {
      throw new \UnexpectedValueException(sprintf("A regex row may not contain '%s': %s", self::DELIMITER, $pattern));
    }
    return self::DELIMITER . $pattern . self::DELIMITER;
  }

}
