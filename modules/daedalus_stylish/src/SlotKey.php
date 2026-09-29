<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;

/**
 * The slot-key grammar: one parser for element-relative style keys.
 *
 * Variant styles are stored element-relative. Historically the key space was
 * '' (the root element) and '::.class' (a declared part); composition
 * variants extend it with slash-separated slot paths that address the root's
 * structural children semantically and positionally, never by uuid or markup
 * class, and the relative selector grammar (RelativeSelector, the one
 * suffix grammar every key speaks) refines any slot into its subtree:
 *
 *   key       = path? ("::" selector)?
 *   path      = segment ("/" segment)*
 *   segment   = container(index) | block(type,index) | field(name)
 *   name/type = machine name [a-z0-9_]+
 *   index     = 1-based [1-9][0-9]*
 *   selector  = a canonical RelativeSelector (".class", "p", "> *",
 *               "> pb-text .link", ...)
 *
 * Structural rules are parameterized by the ROOT KIND, because a root
 * carries exactly the paths its kind structurally contains:
 * - container roots start paths at container(index) or block(type,index) —
 *   a container's children are ONE ordered flow (no addressable regions),
 *   containers all the way down: nesting is position, not kind, so a
 *   nested container is addressed as container(n) and opens at its own
 *   children again (the recursion into nested compositions);
 * - block roots start paths at field(name) (no index: one field per name);
 * - field, entity and region roots carry no paths, parts only;
 * - container(...) is followed by container(index) or block(type,index);
 *   field(...) follows any block segment and terminates the path;
 * - the '::' selector suffix may terminate any key, the bare root
 *   included (the pre-composition key space is the degenerate case); it
 *   is admitted in its canonical spelling only, and since a selector never
 *   contains '::' the first '::' always splits path from selector.
 *
 * This class is the grammar's single home: validation, delivery, capture
 * and the client mirror (js/src/slot-key.js, PHP authoritative) all consume
 * it rather than re-deriving key shapes. The charset gates double as the
 * emit-side injection guard, since every piece of a key eventually prints
 * into CSS selectors.
 */
final class SlotKey {

  /**
   * The root kinds the structural rules are parameterized by.
   *
   * The same vocabulary as PageBuilderDeclarations::KINDS: a variant root
   * is an element, and elements come in the declarations' kinds. Must stay
   * in sync with the FIRST_SEGMENTS table.
   */
  public const ROOT_KINDS = ['entity', 'container', 'region', 'field', 'block'];

  /**
   * Segment types each root kind may START a path with.
   *
   * Empty means the root carries no paths (parts only). Container roots
   * open at their children (nested containers and inline blocks); block
   * roots open at their fields; nothing else has structural children the
   * grammar addresses.
   */
  private const FIRST_SEGMENTS = [
    'entity' => [],
    'container' => ['container', 'block'],
    'region' => [],
    'field' => [],
    'block' => ['field'],
  ];

  /**
   * One path segment: container(index), block(type,index) or field(name).
   */
  private const SEGMENT = '/^(?:(field)\(([a-z0-9_]+)\)|(block)\(([a-z0-9_]+),([1-9][0-9]*)\)|(container)\(([1-9][0-9]*)\))$/';

  /**
   * Constructs a slot key.
   *
   * @param array $segments
   *   The slot path, outermost first: each segment is
   *   ['type' => 'container'|'block'|'field'] with a machine 'name' on
   *   block/field segments and a 1-based integer 'index' on container and
   *   block segments (containers are anonymous: their identity is
   *   positional; blocks disambiguate same-type siblings). Empty for the
   *   root element and for bare part keys.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector|null $selector
   *   The relative selector when the key carries a '::' suffix, NULL for
   *   the addressed element itself.
   */
  private function __construct(
    public readonly array $segments,
    public readonly ?RelativeSelector $selector = NULL,
  ) {}

  /**
   * Parses an element-relative style key.
   *
   * @param string $key
   *   The key ('' is the root element).
   * @param string|null $root_kind
   *   The root element's kind, one of ROOT_KINDS. NULL when the root's kind
   *   is unknown (an unresolvable scope), which permits only the path-less
   *   keys ('' and '::selector'): no structural rule can hold for an
   *   unknown root, and the degenerate keys are the only root-independent
   *   ones.
   *
   * @return self|null
   *   The parsed key, or NULL when it does not parse: bad charset, a
   *   malformed segment, or a path the root kind does not structurally
   *   contain.
   *
   * @throws \InvalidArgumentException
   *   When $root_kind is neither NULL nor a known root kind. A caller bug,
   *   not bad data; data problems return NULL.
   */
  public static function parse(string $key, ?string $root_kind): ?self {
    if ($root_kind !== NULL && !array_key_exists($root_kind, self::FIRST_SEGMENTS)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a slot-key root kind (expected one of: %s).', $root_kind, implode(', ', self::ROOT_KINDS)));
    }

    // The first '::' splits the path from its selector (the one split
    // every key grammar shares); the selector is admitted canonical only.
    $split = RelativeSelector::splitKey($key);
    if ($split === NULL) {
      return NULL;
    }
    [$path, $selector] = $split;

    $segments = [];
    if ($path !== '') {
      $allowed = $root_kind !== NULL ? self::FIRST_SEGMENTS[$root_kind] : [];
      foreach (explode('/', $path) as $raw) {
        if (!preg_match(self::SEGMENT, $raw, $matches)) {
          return NULL;
        }
        if ($matches[1] !== '') {
          $segment = ['type' => $matches[1], 'name' => $matches[2]];
        }
        elseif ($matches[3] !== '') {
          $segment = ['type' => 'block', 'name' => $matches[4], 'index' => (int) $matches[5]];
        }
        else {
          $segment = ['type' => 'container', 'index' => (int) $matches[7]];
        }
        if (!in_array($segment['type'], $allowed, TRUE)) {
          return NULL;
        }
        $segments[] = $segment;
        $allowed = match ($segment['type']) {
          'container' => ['container', 'block'],
          'block' => ['field'],
          'field' => [],
        };
      }
    }

    return new self($segments, $selector);
  }

  /**
   * Whether this key is the bare root element ('').
   */
  public function isRoot(): bool {
    return $this->segments === [] && $this->selector === NULL;
  }

  /**
   * The canonical string form; parse(format()) round-trips losslessly.
   */
  public function format(): string {
    $path = implode('/', array_map(
      static fn (array $segment): string => match (TRUE) {
        !isset($segment['name']) => sprintf('%s(%d)', $segment['type'], $segment['index']),
        isset($segment['index']) => sprintf('%s(%s,%d)', $segment['type'], $segment['name'], $segment['index']),
        default => sprintf('%s(%s)', $segment['type'], $segment['name']),
      },
      $this->segments,
    ));
    return $path . ($this->selector !== NULL ? '::' . $this->selector : '');
  }

  /**
   * A variant's styles at one fragment, keyed by part suffix.
   *
   * The shape a single {variant, fragment} reference delivers to its
   * carrying element, root ('' fragment) and distributed fragment alike:
   * the fragment's own entry reads as '' and its suffixed entries (every
   * '::selector' relative of the fragment) as their suffix, so
   * "{key}{suffix}" is the map key the styles belong to on the carrier. Deeper slot paths under the fragment
   * are OTHER fragments (their elements carry their own references) and
   * never read here. A fragment the variant does not hold reads empty —
   * valid but inert. The ONE fragment-lookup semantics: detach
   * materialization (DaedalusStylishOperationHandler) and delivery (CssPrinter)
   * both read through here, so the two can never disagree about what a
   * reference delivers.
   *
   * @param array $styles
   *   The variant's element-relative styles.
   * @param string $fragment
   *   The fragment slot key ('' for the root element).
   *
   * @return array
   *   Style entries keyed by '' or '::selector' suffix, in stored
   *   order. Non-array and empty entries are dropped, and each entry
   *   reads values-only: a nested "variants" reference list is stripped
   *   (it distributes at apply time, never through the carrier's lookup).
   */
  public static function fragmentStyles(array $styles, string $fragment): array {
    $matched = [];
    foreach ($styles as $relative => $entry) {
      $relative = (string) $relative;
      if (!is_array($entry)) {
        continue;
      }
      // Nested variant references are not values: an entry-shaped
      // fragment's "variants" list distributes onto the resolved elements
      // at APPLY time (bind-once), so nothing may deliver, materialize, or
      // resolve it through the carrier's fragment lookup. The read is
      // values-only; entryRefs() is the one structured read for the list.
      $entry = array_diff_key($entry, array_flip(StyleMapValidator::RESERVED_ENTRY_KEYS));
      if ($entry === []) {
        continue;
      }
      if ($relative === $fragment) {
        $matched[''] = $entry;
      }
      elseif (str_starts_with($relative, $fragment . '::')) {
        $matched[substr($relative, strlen($fragment))] = $entry;
      }
    }
    return $matched;
  }

  /**
   * The structural root kind a variant scope implies.
   *
   * The scope is a printed root address (Address, the grammar
   * StyleMapValidator::captureScope() captures into). Every layout scope
   * is a container root; every bundle is a block root; kinds map to
   * themselves. block_plugin scopes deliberately resolve NULL: plugin
   * blocks carry no blueprint fields and no slot space, so their variants
   * speak the path-less key space ('' and '::selector' keys) only, the
   * same posture as base:{tag} scopes.
   *
   * @return string|null
   *   One of ROOT_KINDS, or NULL when the string is not a variant scope or
   *   the scope's variants are path-less. Callers pass the result straight
   *   to parse(): NULL degrades to the path-less key space rather than
   *   failing the whole variant.
   */
  public static function rootKindForScope(string $scope): ?string {
    $address = Address::parse($scope);
    if ($address === NULL || !$address->isRoot()) {
      return NULL;
    }
    return match ($address->family) {
      Address::FAMILY_LAYOUT => 'container',
      Address::FAMILY_BLOCK_TYPE => 'block',
      Address::FAMILY_KIND => array_key_exists($address->id, self::FIRST_SEGMENTS) ? $address->id : NULL,
      default => NULL,
    };
  }

}
