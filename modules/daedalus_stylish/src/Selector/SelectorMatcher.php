<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Selector;

use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_ui\Render\ElementAddress;

/**
 * Answers which elements of a blueprint tree a relative selector reaches.
 *
 * The one reading of the selector grammar against STRUCTURE (the printer
 * is its reading against the DOM): given the delivered lean node tree
 * (NodeTreeBuilder's kind-discriminated containers, regions and blocks,
 * annotated with daedalus_ui's `_element` facts), it evaluates a rule
 * `{owner}::{selector}` the way the browser evaluates the printed
 * `@scope ([data-selectable="{owner}"]) { targets }`: the SUBJECT (the
 * last compound) must be in scope (the owner or a descendant), every
 * compound before it is an ancestor or sibling constraint the scope does
 * not bound (CSS scopes the subject alone), a leading `>` anchors to the
 * owner (`:scope >`), and a chain's combinators walk the tree exactly as
 * they walk the DOM: `>` and descent along the parent axis, `+` and `~`
 * along the parent's ordered children. The tree facts each compound
 * reads:
 * - `*` matches every node, region wrappers included (a multi-region
 *   layout's regions are real elements between the section and its
 *   blocks, so `> *` on such a section reaches the regions and `> * > *`
 *   the blocks, as the browser sees them);
 * - a type compound matches by the element ADDRESS the stamp carries
 *   (`layout:{id}`, `block_type:{bundle}`, `block_plugin:{base id}`,
 *   derived from the node exactly as ElementAddress derives the stamp),
 *   `pb-container` and `pb-block` by family prefix (CssPrinter's
 *   addressMembers, structurally);
 * - a tag compound matches a node whose resolved root tag
 *   (`_element.markup.tag`) is the tag;
 * - a part class matches NO node: anatomy inside a root is not a tree
 *   fact, so a selector carrying a class before its subject reaches
 *   nothing here (never over-claim), and a class as the subject is the
 *   type's anatomy, answered by reaches() for the part it names;
 * - a compound's qualifiers narrow it on the node: `:has()` holds when
 *   its argument, anchored at the node (a descendant lead strictly
 *   inside it, `>` a child, `+` the next sibling, `~` a later one),
 *   matches some node of the tree, or, for one unqualified tag reached
 *   by descent, when the node carries the tag inside (the bag below);
 *   `:not()` holds when its compound does not match the node, a part
 *   class there judged by the node's own anatomy (`parts`).
 *
 * A node's anatomy is a fact of the node, not of the tree: `parts` is
 * the annotation's declared part tree flattened to `selector => tag`
 * (`_element.markup.parts`, nested parts with their own tags, a hoisted
 * part at the root's tag, a part declaring no tag kept with NULL, the
 * unknown), then the minted instance elements the node's rich text
 * carries (`_element.markup.instances` on a composed render, each
 * `_element.fields.NAME.markup.instances` on an open one, rows nesting
 * through `children`; each an identity class => its tag, never shadowing
 * a declared part), and `tags` (the "is there a {tag} inside" bag)
 * carries every part's tag beside the rich text's, so the tag tier, the
 * completion's inner roster and reaches() all see the anchor a link part
 * renders and the paragraph a minted instance is. The bag has no order
 * or depth, so nothing sibling-led, child-led or qualified is ever
 * answered INSIDE a node: `> p + p` matches p-rooted blocks after a
 * p-rooted sibling, never a rich text's inner paragraphs, and
 * `:has(> a)` on a block is FALSE where `:has(a)` reads the bag.
 *
 * reaches() is the stack's question: does an ancestor's rule land on
 * this element's ROOT (its own entry's targets), INSIDE it on a tag
 * (the element's `::{tag}` tier: an unqualified tag subject reached by
 * descent from a prefix matching the element or one of its ancestors,
 * when the element carries that tag inside, in its rich text or as a
 * part), or on one of its PARTS (a class subject right after a type
 * compound that matches the element; or a TAG subject equal to the
 * part's rendered tag, reached by descent exactly as the tag tier is:
 * the container's `a` rule styles the link part's anchor)? Anatomy is
 * told from identity at the class subject: a declared part class is the
 * type's anatomy, so the chain reaches whether or not this node renders
 * the part (a legal address either way); an identity class
 * (StyleMapValidator::INSTANCE_PART, minted onto one element in the
 * world) reaches only a node whose parts carry it. A `>`, `+` or `~`
 * into a tag subject, or a qualified one, cannot be backed by the
 * tree (the annotation carries no depth under a root), so it never
 * reaches inside, parts included. A terminal pseudo-element is a box
 * with no node (it exists iff the cascade sets `content`), so the tree
 * never sees it: matches() and matching() answer a pseudo rule's HOSTS
 * (`> pb-text::before` matches the text blocks, `&::before` the owner
 * itself), and reaches() compares the pseudo by name (the rule's and
 * the position's must agree) before answering the host as it would the
 * pseudo-free position. Structural facts (the WHEN axis's
 * `:first-child` and kin) evaluate against the sibling index by their
 * printed wrapper (satisfies()), the twin of the browser's own matching
 * for the parameterless pseudo-classes.
 *
 * Pure over the tree it is built from; the client twin is
 * js/src/selector-matcher.js, locked by SelectorMatcherTest's shared
 * battery through tests/js/selector-matcher-cases.mjs.
 */
final class SelectorMatcher {

  /**
   * The node facts in document order (depth-first, pre-order).
   *
   * Each: ['key' => ?string, 'kind' => string, 'address' => ?string,
   * 'tag' => ?string, 'tags' => string[], 'parts' => array<string,
   * ?string>, 'name' => ?string, 'type' => ?string,
   * 'index' => int, 'count' => int,
   * 'childCount' => int, 'parent' => ?int, 'children' => int[]].
   *
   * @var array<int, array>
   */
  private array $nodes = [];

  /**
   * Selectable key => node index, for the addressable nodes.
   *
   * @var array<string, int>
   */
  private array $byKey = [];

  /**
   * The top-level node indexes in order (the root nodes' sibling list).
   *
   * @var int[]
   */
  private array $roots = [];

  private function __construct() {}

  /**
   * Builds a matcher over a lean node tree.
   *
   * @param array $tree
   *   The ordered top-level container nodes (NodeTreeBuilder::build(), or
   *   the delivered blueprint's `_tree`), `_element` annotations riding
   *   the nodes where daedalus_ui delivered them.
   */
  public static function fromTree(array $tree): self {
    $matcher = new self();
    $matcher->index($tree, NULL);
    return $matcher;
  }

  /**
   * Indexes one sibling list, recursively.
   */
  private function index(array $nodes, ?int $parent): void {
    $siblings = array_values(array_filter($nodes, 'is_array'));
    $count = count($siblings);
    foreach ($siblings as $position => $node) {
      $index = count($this->nodes);
      $facts = $this->nodeFacts($node);
      $facts['index'] = $position;
      $facts['count'] = $count;
      $facts['parent'] = $parent;
      $facts['children'] = [];
      $children = is_array($node['children'] ?? NULL) ? array_values(array_filter($node['children'], 'is_array')) : [];
      $facts['childCount'] = count($children);
      $this->nodes[$index] = $facts;
      if ($facts['key'] !== NULL) {
        $this->byKey[$facts['key']] = $index;
      }
      if ($parent === NULL) {
        $this->roots[] = $index;
      }
      else {
        $this->nodes[$parent]['children'][] = $index;
      }
      $this->index($children, $index);
    }
  }

  /**
   * The facts one node carries, before its place among its siblings.
   */
  private function nodeFacts(array $node): array {
    $kind = is_string($node['kind'] ?? NULL) ? $node['kind'] : (array_key_exists('layout_id', $node) ? 'container' : 'block');
    $uuid = is_string($node['uuid'] ?? NULL) && $node['uuid'] !== '' ? $node['uuid'] : NULL;
    $key = NULL;
    $address = NULL;
    if ($kind === 'container') {
      $key = $uuid === NULL ? NULL : 'container:' . $uuid;
      $address = is_string($node['layout_id'] ?? NULL) && $node['layout_id'] !== '' ? ElementAddress::layout($node['layout_id']) : NULL;
    }
    elseif ($kind === 'block') {
      $key = $uuid === NULL ? NULL : 'block:' . $uuid;
      if (is_string($node['block_type'] ?? NULL) && $node['block_type'] !== '') {
        $address = ElementAddress::blockType($node['block_type']);
      }
      elseif (is_string($node['plugin_id'] ?? NULL) && $node['plugin_id'] !== '') {
        $address = ElementAddress::blockPlugin($node['plugin_id']);
      }
    }
    $markup = is_array($node['_element']['markup'] ?? NULL) ? $node['_element']['markup'] : [];
    $tag = is_string($markup['tag'] ?? NULL) && $markup['tag'] !== '' ? $markup['tag'] : NULL;
    // The tags the node's rich text carries: on the block's own markup
    // for a composed render, per field for an open one; both render
    // inside the root, so the union answers "is there a {tag} inside".
    $tags = [];
    $collect = static function (mixed $list) use (&$tags): void {
      foreach (is_array($list) ? $list : [] as $name) {
        if (is_string($name) && $name !== '' && !in_array($name, $tags, TRUE)) {
          $tags[] = $name;
        }
      }
    };
    $collect($markup['tags'] ?? NULL);
    foreach (is_array($node['_element']['fields'] ?? NULL) ? $node['_element']['fields'] : [] as $field) {
      $collect($field['markup']['tags'] ?? NULL);
    }
    // The anatomy: the declared part tree flattened, a nested part right
    // after its parent with its own tag, then the hoisted parts (the
    // root's own element wearing the class) at the root's tag. A part
    // declaring no tag is kept with NULL: the part exists, its tag is
    // unknown, and an unknown never matches a rule.
    $parts = [];
    $flatten = static function (mixed $list) use (&$flatten, &$parts): void {
      foreach (is_array($list) ? $list : [] as $selector => $part) {
        if (!is_string($selector) || $selector === '' || !is_array($part)) {
          continue;
        }
        $parts[$selector] = is_string($part['tag'] ?? NULL) && $part['tag'] !== '' ? $part['tag'] : NULL;
        $flatten($part['parts'] ?? NULL);
      }
    };
    $flatten($markup['parts'] ?? NULL);
    foreach (is_array($markup['hoisted'] ?? NULL) ? $markup['hoisted'] : [] as $selector) {
      if (is_string($selector) && $selector !== '') {
        $parts[$selector] = $tag;
      }
    }
    // The identity: the minted instance elements the rich text carries,
    // each row's class as the part selector at the row's tag, nested
    // carriers after their parent in document order; riding the composed
    // block's own markup or, for an open render, each field's. An
    // instance never shadows a declared part: declared anatomy is the
    // type's word, and it was folded first.
    $fold = static function (mixed $rows) use (&$fold, &$parts): void {
      foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
          continue;
        }
        $class = is_string($row['class'] ?? NULL) && $row['class'] !== '' ? '.' . $row['class'] : NULL;
        if ($class !== NULL && !array_key_exists($class, $parts)) {
          $parts[$class] = is_string($row['tag'] ?? NULL) && $row['tag'] !== '' ? $row['tag'] : NULL;
        }
        $fold($row['children'] ?? NULL);
      }
    };
    $fold($markup['instances'] ?? NULL);
    foreach (is_array($node['_element']['fields'] ?? NULL) ? $node['_element']['fields'] : [] as $field) {
      $fold($field['markup']['instances'] ?? NULL);
    }
    // A part's tag is inside the root as much as the prose's tags are.
    $collect(array_values($parts));
    $word = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : NULL;
    return [
      'key' => $key,
      'kind' => $kind,
      'address' => $address,
      'tag' => $tag,
      'tags' => $tags,
      'parts' => $parts,
      // The element-naming spine (BlueprintElementAnnotator's minted
      // words, riding the tree): what the element is called, what it is.
      'name' => $word($node['_element']['name'] ?? NULL),
      'type' => $word($node['_element']['type'] ?? NULL),
    ];
  }

  /**
   * Whether the tree carries an addressable node under the key.
   */
  public function has(string $key): bool {
    return isset($this->byKey[$key]);
  }

  /**
   * The facts of one addressable node, or NULL when the tree lacks it.
   *
   * @return array|null
   *   ['key', 'kind' ('container' | 'block'), 'address' (the element
   *   address or NULL), 'tag' (the resolved root tag or NULL), 'tags'
   *   (the tag names inside it: its rich text's and its parts'), 'parts'
   *   (its present declared parts and the minted instances inside,
   *   `selector => tag`, NULL for a part declaring no tag), 'name' and
   *   'type' (the annotator's minted
   *   element words, `_element.name` / `_element.type`, or NULL), 'index'
   *   (0-based place among its siblings, region wrappers being real
   *   siblings), 'count' (the sibling count), 'childCount' (its own
   *   child nodes), 'parent' (the nearest addressable ancestor's key or
   *   NULL)].
   */
  public function facts(string $key): ?array {
    $index = $this->byKey[$key] ?? NULL;
    if ($index === NULL) {
      return NULL;
    }
    $node = $this->nodes[$index];
    return [
      'key' => $node['key'],
      'kind' => $node['kind'],
      'address' => $node['address'],
      'tag' => $node['tag'],
      'tags' => $node['tags'],
      'parts' => $node['parts'],
      'name' => $node['name'],
      'type' => $node['type'],
      'index' => $node['index'],
      'count' => $node['count'],
      'childCount' => $node['childCount'],
      'parent' => $this->addressableParent($index),
    ];
  }

  /**
   * The nearest addressable ancestor's key (regions are transparent).
   */
  private function addressableParent(int $index): ?string {
    for ($parent = $this->nodes[$index]['parent']; $parent !== NULL; $parent = $this->nodes[$parent]['parent']) {
      if ($this->nodes[$parent]['key'] !== NULL) {
        return $this->nodes[$parent]['key'];
      }
    }
    return NULL;
  }

  /**
   * The addressable ancestors of a key, FARTHEST first.
   *
   * The order the stack layers them in: an outer rule delivers beneath an
   * inner one (the inner `@scope` root is closer), so the farthest
   * ancestor is the lowest layer. [] for a key the tree lacks.
   *
   * @return string[]
   *   The ancestors' selectable keys.
   */
  public function ancestors(string $key): array {
    $index = $this->byKey[$key] ?? NULL;
    if ($index === NULL) {
      return [];
    }
    $keys = [];
    for ($parent = $this->nodes[$index]['parent']; $parent !== NULL; $parent = $this->nodes[$parent]['parent']) {
      if ($this->nodes[$parent]['key'] !== NULL) {
        $keys[] = $this->nodes[$parent]['key'];
      }
    }
    return array_reverse($keys);
  }

  /**
   * Whether the owner's rule under the selector targets the candidate's root.
   *
   * A rule ending in a pseudo-element targets the generated boxes of its
   * hosts, and the host is what the tree can name: matches() answers
   * the host (`> pb-text::before` matches every text block child, the
   * box hanging on each), and the owner's own pseudo (`&::before`, no
   * compounds) matches the owner alone. Whether the rule DELIVERS at a
   * key is reaches()' question, where the pseudo must agree.
   *
   * @param string $owner
   *   The rule's key (the `@scope` root).
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector|string $selector
   *   The rule's relative selector.
   * @param string $candidate
   *   The element asked about.
   *
   * @throws \InvalidArgumentException
   *   When the selector text is outside the grammar.
   */
  public function matches(string $owner, RelativeSelector|string $selector, string $candidate): bool {
    $compounds = self::selector($selector)->compounds;
    $root = $this->byKey[$owner] ?? NULL;
    $node = $this->byKey[$candidate] ?? NULL;
    if ($root === NULL || $node === NULL || !$this->inScope($node, $root)) {
      return FALSE;
    }
    if ($compounds === []) {
      return $node === $root;
    }
    return $this->chainMatches($node, $compounds, count($compounds) - 1, $root, FALSE);
  }

  /**
   * The addressable elements the owner's rule targets, in document order.
   *
   * The hosts, for a rule ending in a pseudo-element (see matches()).
   *
   * @return string[]
   *   Selectable keys; [] when nothing matches or the owner is unknown.
   */
  public function matching(string $owner, RelativeSelector|string $selector): array {
    $compounds = self::selector($selector)->compounds;
    $root = $this->byKey[$owner] ?? NULL;
    if ($root === NULL) {
      return [];
    }
    if ($compounds === []) {
      return [$this->nodes[$root]['key']];
    }
    $keys = [];
    foreach ($this->nodes as $index => $node) {
      if ($node['key'] !== NULL && $this->inScope($index, $root) && $this->chainMatches($index, $compounds, count($compounds) - 1, $root, FALSE)) {
        $keys[] = $node['key'];
      }
    }
    return $keys;
  }

  /**
   * Whether the owner's rule reaches the candidate at one of its own keys.
   *
   * @param string $owner
   *   The rule's key.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector|string $selector
   *   The rule's relative selector.
   * @param string $candidate
   *   The element asked about.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector|string|null $sub
   *   The candidate's own key position the rule is asked about, as the
   *   relative selector the key's suffix spells (the grammar structures
   *   it: a host and, behind it, the terminal pseudo-element or none).
   *   NULL for the candidate's root (its own entry: matches()); a bare
   *   tag (`p`) for its tag tier (the rule's subject is that tag,
   *   unqualified, reached by descent from a prefix matching the
   *   candidate or one of its ancestors, and the candidate carries the
   *   tag inside, in its rich text or as a part); a part class (`.cls`)
   *   for that part (the rule's subject is the class, qualifying a type
   *   compound that matches the candidate; or the rule's subject is the
   *   TAG the part renders, reached by descent exactly as the tag tier
   *   is, since the part IS an element of that tag inside the candidate;
   *   a part declaring no tag is never reached by a tag subject). A tag
   *   subject reached by `>`, `+` or `~`, or carrying a qualifier, is
   *   never answered inside: the tag bag has no order or depth. A
   *   pseudo-element is a box with no node, so it is compared by name:
   *   the rule's terminal pseudo and the position's must agree (none on
   *   both, or the same one), and the host is then answered exactly as
   *   the pseudo-free position is: `&::before` (the owner's own box, no
   *   host compound) is the root's box, reached by a rule ending in
   *   `::before` whose chain matches the candidate; `.cls::after` the
   *   part's box; `a::before` the tag tier's box. A rule ending in a
   *   pseudo never reaches a pseudo-free position (its styles land on
   *   generated boxes alone), and a pseudo-free rule never reaches a
   *   pseudo position.
   *
   * @return bool
   *   FALSE for any other sub shape (a chain, the children, with or
   *   without a pseudo): those keys are rules of their own, not tiers of
   *   the element.
   *
   * @throws \InvalidArgumentException
   *   When the selector or the sub text is outside the grammar.
   */
  public function reaches(string $owner, RelativeSelector|string $selector, string $candidate, RelativeSelector|string|null $sub = NULL): bool {
    $rule = self::selector($selector);
    $tier = $sub === NULL ? NULL : self::selector($sub);
    // The generated box is a cascade fact, not a tree fact: existence
    // holds by name alone, and a rule for one box never lands on the
    // element itself or on the other box.
    if ($rule->pseudo !== $tier?->pseudo) {
      return FALSE;
    }
    // The root, or its own generated box (the anchor has no host compound).
    if ($tier === NULL || $tier->compounds === []) {
      return $this->matches($owner, $rule, $candidate);
    }
    // The `p` or `.cls` tier is ONE unqualified compound reached by
    // descent; anything else is a rule of its own.
    $position = $tier->compounds[0];
    if (count($tier->compounds) !== 1 || $position['combinator'] !== RelativeSelector::DESCENDANT || $position['qualifiers'] !== [] || !in_array($position['kind'], [RelativeSelector::TAG, RelativeSelector::CLASS_PART], TRUE)) {
      return FALSE;
    }
    $compounds = $rule->compounds;
    $root = $this->byKey[$owner] ?? NULL;
    $node = $this->byKey[$candidate] ?? NULL;
    if ($root === NULL || $node === NULL || !$this->inScope($node, $root) || $compounds === []) {
      return FALSE;
    }
    $last = $compounds[array_key_last($compounds)];
    $n = count($compounds);
    $name = $position['name'];
    // A tag subject sits INSIDE the node, where the annotation carries a
    // bag of tags without order or depth: only an unqualified tag
    // reached by descent can be answered there.
    $tag_subject = $last['kind'] === RelativeSelector::TAG && $last['combinator'] === RelativeSelector::DESCENDANT && $last['qualifiers'] === [];
    if ($position['kind'] === RelativeSelector::TAG) {
      if (!$tag_subject || $last['name'] !== $name) {
        return FALSE;
      }
      return in_array($name, $this->nodes[$node]['tags'], TRUE) && $this->descentReaches($node, $compounds, $root);
    }
    // A class subject after a type: a declared part is the type's anatomy,
    // a legal address on every node the type matches, whether or not this
    // one renders it; an identity class is minted onto one element in the
    // world, so it reaches only the node carrying it.
    if ($last['kind'] === RelativeSelector::CLASS_PART) {
      return $last['name'] === $name
        && $n >= 2
        && (!preg_match(StyleMapValidator::INSTANCE_PART, $name) || array_key_exists($name, $this->nodes[$node]['parts']))
        && $this->chainMatches($node, $compounds, $n - 2, $root, FALSE);
    }
    if (!$tag_subject) {
      return FALSE;
    }
    $part_tag = $this->nodes[$node]['parts'][$name] ?? NULL;
    return $part_tag !== NULL && $part_tag === $last['name'] && $this->descentReaches($node, $compounds, $root);
  }

  /**
   * Whether a descendant subject's prefix matches the node or an ancestor.
   *
   * The subject (the last compound) sits INSIDE the node, reached by
   * descent, so the chain before it must match the node itself or any
   * ancestor of it (the engine's walk from the subject's parent upward);
   * a lone subject has no prefix and reaches by scope alone.
   */
  private function descentReaches(int $node, array $compounds, int $root): bool {
    $n = count($compounds);
    if ($n === 1) {
      return TRUE;
    }
    for ($at = $node; $at !== NULL; $at = $this->nodes[$at]['parent']) {
      if ($this->chainMatches($at, $compounds, $n - 2, $root, FALSE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a structural pseudo-class holds for the element.
   *
   * @param string $key
   *   The element's key (a selector key reads its owner's place).
   * @param string $wrapper
   *   The condition's printed pseudo-class (`:first-child`,
   *   `:nth-child(odd)`, ...).
   *
   * @return bool
   *   FALSE for a key the tree lacks, or a wrapper outside the
   *   parameterless vocabulary (a site's `:nth-child(3n+1)` is unknown,
   *   never a promise).
   */
  public function satisfies(string $key, string $wrapper): bool {
    $pos = strpos($key, RelativeSelector::SUFFIX);
    $index = $this->byKey[$pos === FALSE ? $key : substr($key, 0, $pos)] ?? NULL;
    if ($index === NULL) {
      return FALSE;
    }
    $node = $this->nodes[$index];
    // A block's root holds its content, which the tree carries no nodes
    // for: a block is never empty; a container or region wrapper without
    // child nodes is.
    return self::structuralHolds($wrapper, $node['index'], $node['count'], $node['kind'] !== 'block' && $node['childCount'] === 0);
  }

  /**
   * Whether a structural pseudo-class holds at a sibling position.
   *
   * The tree twin of the browser's own matching for the parameterless
   * structural facts (plus odd and even, the two parameterless spellings
   * of :nth-child) and their :not() negations. The wrapper IS the
   * pseudo-class the printer emits, so evaluating it reads the same text
   * the browser will; each negation is spelled out rather than derived
   * from a :not() prefix so that an unknown wrapper inside :not() stays
   * unknown (never holds) instead of holding by negation.
   */
  public static function structuralHolds(string $wrapper, int $index, int $count, bool $empty): bool {
    return match ($wrapper) {
      ':first-child' => $index === 0,
      ':last-child' => $index === $count - 1,
      ':only-child' => $count === 1,
      ':empty' => $empty,
      ':nth-child(odd)' => $index % 2 === 0,
      ':nth-child(even)' => $index % 2 === 1,
      ':not(:first-child)' => $index !== 0,
      ':not(:last-child)' => $index !== $count - 1,
      ':not(:only-child)' => $count !== 1,
      ':not(:empty)' => !$empty,
      default => FALSE,
    };
  }

  /**
   * The selector record, refusing text outside the grammar.
   */
  private static function selector(RelativeSelector|string $selector): RelativeSelector {
    return $selector instanceof RelativeSelector ? $selector : RelativeSelector::fromString($selector);
  }

  /**
   * Whether a node is the scope root or one of its descendants.
   */
  private function inScope(int $node, int $root): bool {
    for ($at = $node; $at !== NULL; $at = $this->nodes[$at]['parent']) {
      if ($at === $root) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The node's ancestors, nearest first.
   *
   * @return int[]
   */
  private function ancestorsOf(int $node): array {
    $ancestors = [];
    for ($at = $this->nodes[$node]['parent']; $at !== NULL; $at = $this->nodes[$at]['parent']) {
      $ancestors[] = $at;
    }
    return $ancestors;
  }

  /**
   * The ordered sibling list a node belongs to.
   *
   * The parent's children, or the top-level list for a root node; the
   * node sits at its own `index` in it.
   *
   * @return int[]
   */
  private function siblings(int $node): array {
    $parent = $this->nodes[$node]['parent'];
    return $parent === NULL ? $this->roots : $this->nodes[$parent]['children'];
  }

  /**
   * The node's earlier siblings, nearest first ([] for a first child).
   *
   * @return int[]
   */
  private function earlierSiblings(int $node): array {
    return array_reverse(array_slice($this->siblings($node), 0, $this->nodes[$node]['index']));
  }

  /**
   * Whether the node matches compound $i with the chain satisfied before it.
   *
   * The engine's right-to-left walk: compound $i on the node, then its
   * combinator decides where compound $i - 1 must match (the parent for
   * `>`, any ancestor for descent, the previous sibling for `+`, any
   * earlier sibling for `~`, siblings read in the parent's ordered
   * children). The first compound's combinator is its lead, related to
   * the anchor: at the top level the anchor is the scope root, `>`
   * anchors the parent to it and the descendant lead anchors nothing (a
   * descendant-first chain prints without `:scope`; the scope bounds the
   * subject alone, inScope()); inside `:has()` the anchor is the
   * qualified node and every lead relates to it (descent strictly inside
   * it, `>` a child of it, `+` right after it, `~` anywhere after it).
   */
  private function chainMatches(int $node, array $compounds, int $i, int $anchor, bool $anchored): bool {
    if (!$this->compoundMatches($node, $compounds[$i])) {
      return FALSE;
    }
    $combinator = $compounds[$i]['combinator'];
    $parent = $this->nodes[$node]['parent'];
    if ($i === 0) {
      return match ($combinator) {
        RelativeSelector::DESCENDANT => !$anchored || ($node !== $anchor && $this->inScope($node, $anchor)),
        RelativeSelector::CHILD => $parent === $anchor,
        RelativeSelector::NEXT => ($this->earlierSiblings($node)[0] ?? NULL) === $anchor,
        RelativeSelector::SUBSEQUENT => in_array($anchor, $this->earlierSiblings($node), TRUE),
      };
    }
    $candidates = match ($combinator) {
      RelativeSelector::DESCENDANT => $this->ancestorsOf($node),
      RelativeSelector::CHILD => $parent === NULL ? [] : [$parent],
      RelativeSelector::NEXT => array_slice($this->earlierSiblings($node), 0, 1),
      RelativeSelector::SUBSEQUENT => $this->earlierSiblings($node),
    };
    foreach ($candidates as $at) {
      if ($this->chainMatches($at, $compounds, $i - 1, $anchor, $anchored)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether one node matches one compound: its subject, then every qualifier.
   *
   * `:has()` holds by hasHolds(); `:not()` holds when its compound does
   * not match the node.
   */
  private function compoundMatches(int $node, array $compound): bool {
    if (!$this->subjectMatches($node, $compound)) {
      return FALSE;
    }
    foreach ($compound['qualifiers'] as $qualifier) {
      $holds = $qualifier['name'] === RelativeSelector::HAS
        ? $this->hasHolds($node, $qualifier['argument'])
        : !$this->compoundMatches($node, $qualifier['argument']);
      if (!$holds) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Whether the node's facts match a compound's subject.
   *
   * `*` and an implied subject (a `:not()` argument of qualifiers alone)
   * match every node; a tag the node's root tag; a type the node's
   * address by family. A part class matches no node in a chain (anatomy
   * inside a root is not a tree fact, never over-claimed) but IS judged
   * as a `:not()` argument, the one compound carrying no combinator: a
   * node whose parts carry the class, hoisted onto its root or nested
   * inside it, wears the class and is what `:not(.cls)` excludes (the
   * flattened parts do not tell the two apart, and excluding both is the
   * reading that never over-claims a reach).
   */
  private function subjectMatches(int $node, array $compound): bool {
    $facts = $this->nodes[$node];
    return match ($compound['kind']) {
      NULL, RelativeSelector::UNIVERSAL => TRUE,
      RelativeSelector::TAG => $facts['tag'] !== NULL && $facts['tag'] === $compound['name'],
      RelativeSelector::TYPE => $facts['address'] !== NULL && self::typeMatches($compound['name'], $facts['address']),
      RelativeSelector::CLASS_PART => $compound['combinator'] === NULL && array_key_exists($compound['name'], $facts['parts']),
      default => FALSE,
    };
  }

  /**
   * Whether a `:has()` argument holds at a node.
   *
   * The argument is a relative selector anchored at the node: it holds
   * when any node of the tree matches it with its lead related to this
   * node (chainMatches() anchored). The tree stops at element roots, so
   * an argument reaching INSIDE a block is answered by the annotation's
   * tag bag instead, which carries neither order nor depth: only one
   * unqualified tag reached by descent can be read there (`:has(a)` on
   * a block whose link part renders an anchor holds; `:has(> a)` never
   * does).
   */
  private function hasHolds(int $node, array $argument): bool {
    $n = count($argument);
    $lead = $argument[0];
    if ($n === 1 && $lead['kind'] === RelativeSelector::TAG && $lead['combinator'] === RelativeSelector::DESCENDANT && $lead['qualifiers'] === [] && in_array($lead['name'], $this->nodes[$node]['tags'], TRUE)) {
      return TRUE;
    }
    foreach (array_keys($this->nodes) as $candidate) {
      if ($this->chainMatches($candidate, $argument, $n - 1, $node, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether an element address is a member of a type compound's family.
   *
   * The structural twin of CssPrinter::addressMembers(): `pb-container`
   * is the layout family, `pb-block` both block placement families, any
   * other type its block type's address exactly.
   */
  public static function typeMatches(string $type, string $address): bool {
    return match ($type) {
      RelativeSelector::TYPE_CONTAINER => str_starts_with($address, ElementAddress::LAYOUT_PREFIX),
      RelativeSelector::TYPE_BLOCK => str_starts_with($address, ElementAddress::BLOCK_TYPE_PREFIX) || str_starts_with($address, ElementAddress::BLOCK_PLUGIN_PREFIX),
      default => $address === (string) RelativeSelector::typeAddress($type),
    };
  }

}
