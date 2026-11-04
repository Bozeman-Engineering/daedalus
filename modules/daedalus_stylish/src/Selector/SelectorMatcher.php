<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Selector;

use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\VariantStylesheet;
use Drupal\daedalus_ui\Render\ElementAddress;

/**
 * Answers which elements of a blueprint tree a relative selector reaches.
 *
 * The one reading of the selector grammar against STRUCTURE (the printer
 * is its reading against the DOM): given the DOCUMENT tree
 * (NodeTreeBuilder::document(); documentTree() in daedalus_ui's
 * js/edit_mode/blueprint/document-tree.js on the client), one top-level
 * node of kind `entity`, the host above its sections exactly as the
 * canvas root wrapper (`data-selectable="entity:{uuid}"`) stands above
 * them in the DOM, with the delivered lean node tree (NodeTreeBuilder's
 * kind-discriminated containers, regions and blocks, annotated with
 * daedalus_ui's `_element` facts) as its children, it evaluates a rule
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
 *   `d-container` and `d-block` by family prefix (CssPrinter's
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
 *   class there judged by the node's own anatomy (`parts`); `:is()`
 *   holds when any of its member compounds matches the node, each
 *   member judged exactly as `:not()`'s compound is (`> *:is(d-text,
 *   d-image)` is the two kinds; `> d-text:is(:hover, :focus-within) a`
 *   reaches as `> d-text a`, states being reach-neutral); a POSITION
 *   qualifier (`:first-child`, `:nth-child(2n+1)`, `:last-of-type`,
 *   `:empty` and kin, RelativeSelector::POSITIONS / NTH_POSITIONS) is
 *   judged by structuralHolds() over the node's place among its tree
 *   siblings (its index and count, its index and count among the
 *   siblings sharing its root tag for the `-of-type` forms, and whether
 *   it is empty) on a compound the tree matches by node (`*`, the
 *   implied subject, a type, the anchor), and is reach-neutral on a tag
 *   or class compound (holds as if absent: a tag's position may be a
 *   position inside a root, where the tag bag below has no order);
 * - a CLASS qualifier (`li.active`, `> d-text.daedalus-stylish-variant--
 *   featured`) is judged by the node's WEAR when the class is a variant
 *   class (VariantStylesheet::CLASS_PREFIX: the class a worn variant
 *   delivers on its element, a fact of the host's style map the matcher
 *   is built with, so "the text blocks wearing Featured" is a tree
 *   question), on a compound the tree matches by node; a markup class
 *   (the page's JavaScript toggles it, the tree never sees it) and any
 *   class on a tag or class compound are reach-neutral; an ATTRIBUTE
 *   qualifier and a pseudo-class that is no position (a state such as
 *   `:hover`, an unpublished `:visited`) are reach-neutral everywhere:
 *   reach is structural, and the pose lights the state;
 * - the anchor `&`, the leading compound of a qualified-anchor selector
 *   (`&:first-child`, `&:not(:last-child) > *`), matches the scope root
 *   alone, its qualifiers judged against the owner node itself.
 *
 * The entity is the one root: the farthest ancestor of every element,
 * the owner whose `> *` is the sections (so a position qualifier on a
 * top-level section reads its place among them), the node whose minted
 * words name the page. Its key is `entity:{uuid}`, its address the bare
 * entity kind (ElementAddress::ENTITY), its facts the blueprint root's
 * own annotation (`_element.name/type/icon`, the entity kind's markup).
 * The host's fields render OUTSIDE the canvas root, so no field markup
 * folds into the root's bag. A document whose root carries no uuid (a
 * template host, the site frame) keeps the node as structure nothing
 * addresses: the sections' ancestry holds, nothing names the root. A
 * bare layout is a document with no root node.
 *
 * A block's FIELD is inside its block, in a side table, never in the
 * tree: an open render's field root (`block:{uuid}:{name}`,
 * StyleMapValidator::parseKey()'s `field` form, the key an open render's
 * prose rows hang under) is a record built from the block node's
 * `_element.fields` (one per annotated field, kind `field`, address
 * ElementAddress::FIELD as the field root's stamp carries, no root tag,
 * its tags and parts from the field's own markup, its wear from the map,
 * no minted words; a field annotated `element: false` has no element in
 * any view and gets no record; a keyless block carries none). has() and
 * facts() answer it, ancestors() answers the block's ancestors plus the
 * block (the field's rows inherit the block's rules and its ancestors'),
 * and reaches() judges its tags and parts with the block's node as its
 * place. A record is never a node: matching() never lists it, matches()
 * is FALSE for it (a field is not an element a rule targets; `> *` from
 * the block never sees it), no chain or `:has()` walks through it, and
 * as an owner it targets nothing (a field's own rules land inside it,
 * where the tree has no nodes).
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
 * (`> d-text::before` matches the text blocks, `&::before` the owner
 * itself), and reaches() compares the pseudo by name (the rule's and
 * the position's must agree) before answering the host as it would the
 * pseudo-free position. A reader asking whether the OWNER itself stands
 * at a position (a stored fact condition's printed wrapper, until the
 * fact convergence rewrites it into the key) asks matches() with the
 * wrapper as the owner's anchor qualifier (`matches($key, '&' .
 * $wrapper, $key)`): one judge, structuralHolds(), for the anchor and
 * for every compound.
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
   * ?string>, 'wear' => string[], 'name' => ?string, 'type' => ?string,
   * 'icon' => ?string, 'index' => int, 'count' => int,
   * 'childCount' => int, 'parent' => ?int, 'children' => int[]].
   *
   * @var array<int, array>
   */
  private array $nodes = [];

  /**
   * The host's style map: the wear each addressable node's entry carries.
   */
  private array $map = [];

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

  /**
   * The side table: field key => record.
   *
   * Each as fieldRecords() builds it, plus 'wear' and 'parent' (the
   * block's node index). Never a node: nothing here is in a children
   * list, in $roots, or walked by a chain.
   *
   * @var array<string, array>
   */
  private array $fields = [];

  private function __construct() {}

  /**
   * Builds a matcher over a document tree.
   *
   * @param array $document
   *   The document tree (NodeTreeBuilder::document()): the one entity
   *   node, the blueprint's `layout` as its children, `_element`
   *   annotations riding the nodes where daedalus_ui delivered them. A
   *   bare layout is a document with no root node: the sections are then
   *   the roots and nothing stands above them.
   * @param array $map
   *   The host's style map, for the wear: an element entry's variant
   *   references (`{key: {variants: [{variant, fragment, via}]}}`) are
   *   the classes the element is stamped with, which a class qualifier
   *   naming one is judged against. Without a map no node wears
   *   anything, and a variant-class qualifier matches no node.
   */
  public static function fromTree(array $document, array $map = []): self {
    $matcher = new self();
    $matcher->map = $map;
    $matcher->index($document, NULL);
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
      $facts['wear'] = $this->wear($facts['key']);
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
      if ($facts['kind'] === 'block' && $facts['key'] !== NULL) {
        foreach (self::fieldRecords($node, $facts['key']) as $record) {
          $record['wear'] = $this->wear($record['key']);
          $record['parent'] = $index;
          $this->fields[$record['key']] = $record;
        }
      }
      $this->index($children, $index);
    }
  }

  /**
   * The field records a keyed block node carries, before their wear.
   *
   * One per annotated field (`_element.fields[name]`) that has an
   * element: a field annotated `element: false` answers to its key in no
   * view, so it gets no record. Each is keyed `{block key}:{name}`
   * (StyleMapValidator::parseKey()'s `field` form), kind `field`,
   * addressed ElementAddress::FIELD (the field root's stamp), with no
   * root tag (the tree never knows the field wrapper's), its tags and
   * parts from the field's own markup, and no minted words.
   *
   * @return array<int, array>
   *   The records: ['key', 'kind', 'address', 'tag', 'tags', 'parts',
   *   'name', 'type', 'icon'].
   */
  private static function fieldRecords(array $node, string $key): array {
    $records = [];
    foreach (is_array($node['_element']['fields'] ?? NULL) ? $node['_element']['fields'] : [] as $name => $field) {
      if (!is_string($name) || $name === '' || !is_array($field) || ($field['element'] ?? NULL) === FALSE) {
        continue;
      }
      $records[] = [
        'key' => $key . ':' . $name,
        'kind' => 'field',
        'address' => ElementAddress::FIELD,
        'tag' => NULL,
      ] + self::anatomy(is_array($field['markup'] ?? NULL) ? $field['markup'] : [], []) + [
        'name' => NULL,
        'type' => NULL,
        'icon' => NULL,
      ];
    }
    return $records;
  }

  /**
   * The facts one node carries, before its place among its siblings.
   */
  private function nodeFacts(array $node): array {
    $kind = is_string($node['kind'] ?? NULL) ? $node['kind'] : (array_key_exists('layout_id', $node) ? 'container' : 'block');
    $uuid = is_string($node['uuid'] ?? NULL) && $node['uuid'] !== '' ? $node['uuid'] : NULL;
    $key = NULL;
    $address = NULL;
    if ($kind === 'entity') {
      // The document's root: the host entity, addressed by the bare
      // entity kind (the stamp the canvas root wrapper carries); keyless
      // without a uuid.
      $key = $uuid === NULL ? NULL : 'entity:' . $uuid;
      $address = ElementAddress::ENTITY;
    }
    elseif ($kind === 'container') {
      $key = $uuid === NULL ? NULL : 'container:' . $uuid;
      $address = is_string($node['layout_id'] ?? NULL) && $node['layout_id'] !== '' ? ElementAddress::layout($node['layout_id']) : NULL;
    }
    elseif ($kind === 'block') {
      $key = $uuid === NULL ? NULL : 'block:' . $uuid;
      if (is_string($node['block_type'] ?? NULL) && $node['block_type'] !== '') {
        $address = ElementAddress::blockType($node['block_type']);
      }
      elseif (($plugin = NodeTreeBuilder::pluginBase($node)) !== NULL) {
        // The base plugin (a field node's is field_block): the one
        // derivation the clients' twin shares.
        $address = ElementAddress::blockPlugin($plugin);
      }
    }
    $markup = is_array($node['_element']['markup'] ?? NULL) ? $node['_element']['markup'] : [];
    $tag = is_string($markup['tag'] ?? NULL) && $markup['tag'] !== '' ? $markup['tag'] : NULL;
    // The annotated fields whose markup is inside the root: a block's (an
    // open render's prose renders inside the block). The entity root's
    // are NOT: the host's fields render outside the canvas root wrapper,
    // so an entity rule never reaches them in the browser and the root's
    // bag is its own markup alone.
    $fields = $kind === 'entity' || !is_array($node['_element']['fields'] ?? NULL) ? [] : $node['_element']['fields'];
    $word = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : NULL;
    return [
      'key' => $key,
      'kind' => $kind,
      'address' => $address,
      'tag' => $tag,
    ] + self::anatomy($markup, $fields) + [
      // The element-naming spine (BlueprintElementAnnotator's minted
      // words, riding the tree): what the element is called, what it is.
      'name' => $word($node['_element']['name'] ?? NULL),
      'type' => $word($node['_element']['type'] ?? NULL),
      'icon' => $word($node['_element']['icon'] ?? NULL),
    ];
  }

  /**
   * The anatomy a markup annotation carries, with field markups folded in.
   *
   * The one derivation behind a node's bag and a field record's.
   *
   * @param array $markup
   *   The annotation's markup (`_element.markup`, or a field's
   *   `fields[name].markup`): `tag`, `tags`, `parts`, `hoisted`,
   *   `instances`.
   * @param array $fields
   *   The annotated fields whose markup renders inside the same root (a
   *   block's, for an open render); [] for a record over one field's
   *   markup.
   *
   * @return array
   *   ['tags' => string[], 'parts' => array<string, ?string>].
   */
  private static function anatomy(array $markup, array $fields): array {
    $tag = is_string($markup['tag'] ?? NULL) && $markup['tag'] !== '' ? $markup['tag'] : NULL;
    // The tags the rich text carries: on the block's own markup for a
    // composed render, per field for an open one; both render inside the
    // root, so the union answers "is there a {tag} inside".
    $tags = [];
    $collect = static function (mixed $list) use (&$tags): void {
      foreach (is_array($list) ? $list : [] as $name) {
        if (is_string($name) && $name !== '' && !in_array($name, $tags, TRUE)) {
          $tags[] = $name;
        }
      }
    };
    $collect($markup['tags'] ?? NULL);
    foreach ($fields as $field) {
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
    foreach ($fields as $field) {
      $fold($field['markup']['instances'] ?? NULL);
    }
    // A part's tag is inside the root as much as the prose's tags are.
    $collect(array_values($parts));
    return ['tags' => $tags, 'parts' => $parts];
  }

  /**
   * The classes a node's worn variants deliver on it.
   *
   * The element entry's references, each as the class the stamp carries
   * (VariantStylesheet::fragmentClass(): the plain variant class for a
   * root fragment, the slot-encoded one for a distributed fragment; a
   * pair that cannot be a class name stamps nothing). Order is the
   * delivery's business (orderedEntryRefs() loads to sort); matching
   * needs the set. A rule key's wear stamps no class and is not here.
   *
   * @return string[]
   *   The class names, each once, in reference order.
   */
  private function wear(?string $key): array {
    $classes = [];
    foreach ($key === NULL ? [] : StyleMapValidator::entryRefs($this->map[$key] ?? NULL) as $ref) {
      $class = VariantStylesheet::fragmentClass($ref['variant'], $ref['fragment']);
      if ($class !== NULL && !in_array($class, $classes, TRUE)) {
        $classes[] = $class;
      }
    }
    return $classes;
  }

  /**
   * Whether the tree carries an addressable node or a block's field at the key.
   */
  public function has(string $key): bool {
    return isset($this->byKey[$key]) || isset($this->fields[$key]);
  }

  /**
   * The facts of one addressable node or field, or NULL when the tree lacks it.
   *
   * @return array|null
   *   ['key', 'kind' ('entity' | 'container' | 'block' | 'field'),
   *   'address' (the element address, `kind:entity` for the root,
   *   `kind:field` for a block's field, or NULL), 'tag' (the
   *   resolved root tag or NULL), 'tags'
   *   (the tag names inside it: its rich text's and its parts'), 'parts'
   *   (its present declared parts and the minted instances inside,
   *   `selector => tag`, NULL for a part declaring no tag), 'wear' (the
   *   classes its worn variants deliver on it, from the style map the
   *   matcher was built with), 'name',
   *   'type' and 'icon' (the annotator's minted element words and the
   *   glyph it wears, `_element.name` / `_element.type` /
   *   `_element.icon`, or NULL), 'index'
   *   (0-based place among its siblings, region wrappers being real
   *   siblings), 'count' (the sibling count), 'childCount' (its own
   *   child nodes), 'parent' (the nearest addressable ancestor's key or
   *   NULL)]. A field record has NULL words and tag, no place ('index'
   *   and 'count' NULL, 'childCount' 0: the tree carries no sibling list
   *   for it) and its block as 'parent'.
   */
  public function facts(string $key): ?array {
    $index = $this->byKey[$key] ?? NULL;
    if ($index !== NULL) {
      $node = $this->nodes[$index];
      $facts = [
        'index' => $node['index'],
        'count' => $node['count'],
        'childCount' => $node['childCount'],
      ];
      return self::publicFacts($node, $facts, $this->addressableParent($index));
    }
    $record = $this->fields[$key] ?? NULL;
    return $record === NULL ? NULL : self::publicFacts($record, ['index' => NULL, 'count' => NULL, 'childCount' => 0], $this->nodes[$record['parent']]['key']);
  }

  /**
   * The facts a key answers: the entry's own, its place, its parent key.
   */
  private static function publicFacts(array $entry, array $place, ?string $parent): array {
    return [
      'key' => $entry['key'],
      'kind' => $entry['kind'],
      'address' => $entry['address'],
      'tag' => $entry['tag'],
      'tags' => $entry['tags'],
      'parts' => $entry['parts'],
      'wear' => $entry['wear'],
      'name' => $entry['name'],
      'type' => $entry['type'],
      'icon' => $entry['icon'],
    ] + $place + ['parent' => $parent];
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
   * ancestor is the lowest layer. A field's ancestors are its block's,
   * then the block itself (the field's rows inherit the block's rules
   * and its ancestors'). [] for a key the tree lacks.
   *
   * @return string[]
   *   The ancestors' selectable keys.
   */
  public function ancestors(string $key): array {
    $index = $this->byKey[$key] ?? NULL;
    if ($index !== NULL) {
      return $this->ancestorKeys($index);
    }
    $record = $this->fields[$key] ?? NULL;
    return $record === NULL ? [] : [...$this->ancestorKeys($record['parent']), $this->nodes[$record['parent']]['key']];
  }

  /**
   * A node's addressable ancestors' keys, farthest first.
   *
   * @return string[]
   *   The ancestors' keys, farthest first.
   */
  private function ancestorKeys(int $index): array {
    $keys = [];
    for ($parent = $this->nodes[$index]['parent']; $parent !== NULL; $parent = $this->nodes[$parent]['parent']) {
      if ($this->nodes[$parent]['key'] !== NULL) {
        $keys[] = $this->nodes[$parent]['key'];
      }
    }
    return array_reverse($keys);
  }

  /**
   * What reaches() judges a candidate by: its anatomy and its place.
   *
   * A node's own tags and parts at its own index; a field record's tags
   * and parts at its block's node (the field is inside the block, so a
   * rule reaching inside the block reaches inside the field).
   *
   * @return array|null
   *   ['place' => int, 'tags' => string[], 'parts' => array], or NULL
   *   for a key the tree lacks.
   */
  private function subjectOf(string $key): ?array {
    $index = $this->byKey[$key] ?? NULL;
    if ($index !== NULL) {
      return ['place' => $index, 'tags' => $this->nodes[$index]['tags'], 'parts' => $this->nodes[$index]['parts']];
    }
    $record = $this->fields[$key] ?? NULL;
    if ($record === NULL) {
      return NULL;
    }
    return [
      'place' => $record['parent'],
      'tags' => $record['tags'],
      'parts' => $record['parts'],
    ];
  }

  /**
   * Whether the owner's rule under the selector targets the candidate's root.
   *
   * A rule ending in a pseudo-element targets the generated boxes of its
   * hosts, and the host is what the tree can name: matches() answers
   * the host (`> d-text::before` matches every text block child, the
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
    $subject_kinds = [RelativeSelector::TAG, RelativeSelector::CLASS_PART];
    if (count($tier->compounds) !== 1 || $position['combinator'] !== RelativeSelector::DESCENDANT || $position['qualifiers'] !== [] || !in_array($position['kind'], $subject_kinds, TRUE)) {
      return FALSE;
    }
    $compounds = $rule->compounds;
    $root = $this->byKey[$owner] ?? NULL;
    // The candidate: a node, or a field record judged at its block.
    $subject = $this->subjectOf($candidate);
    if ($root === NULL || $subject === NULL || !$this->inScope($subject['place'], $root) || $compounds === []) {
      return FALSE;
    }
    ['place' => $place, 'tags' => $tags, 'parts' => $parts] = $subject;
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
      return in_array($name, $tags, TRUE) && $this->descentReaches($place, $compounds, $root);
    }
    // A class subject after a type: a declared part is the type's anatomy,
    // a legal address on every node the type matches, whether or not this
    // one renders it; an identity class is minted onto one element in the
    // world, so it reaches only the node carrying it.
    if ($last['kind'] === RelativeSelector::CLASS_PART) {
      return $last['name'] === $name
        && $n >= 2
        && (!preg_match(StyleMapValidator::INSTANCE_PART, $name) || array_key_exists($name, $parts))
        && $this->chainMatches($place, $compounds, $n - 2, $root, FALSE);
    }
    if (!$tag_subject) {
      return FALSE;
    }
    $part_tag = $parts[$name] ?? NULL;
    return $part_tag !== NULL && $part_tag === $last['name'] && $this->descentReaches($place, $compounds, $root);
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
   * Whether a position qualifier holds at a place among siblings.
   *
   * The tree twin of the browser's own matching for CSS's tree-structural
   * pseudo-classes, over the grammar's record (RelativeSelector's
   * `{name, argument}`: a POSITIONS name with a NULL argument, an
   * NTH_POSITIONS name with its canonical `An+B`). `:nth-child(An+B)`
   * holds at the 1-based position p when p = An + B for some n >= 0
   * (`odd` and `even` as CSS reads them); the `-last-` forms count from
   * the end; the `-of-type` forms read the place among the siblings
   * sharing the node's root tag, and a node whose tag the tree does not
   * know is of no type: never first, last or nth of it.
   *
   * @param array $qualifier
   *   The grammar's record: ['name' => string, 'argument' => ?string].
   * @param array $place
   *   The place: ['index' => int (0-based among the siblings), 'count'
   *   => int (the siblings), 'empty' => bool, 'typeIndex' => ?int
   *   (0-based among the siblings sharing the tag; NULL for an unknown
   *   tag), 'typeCount' => ?int].
   *
   * @throws \InvalidArgumentException
   *   When the qualifier is not a position qualifier of the grammar.
   */
  public static function structuralHolds(array $qualifier, array $place): bool {
    $index = $place['index'];
    $count = $place['count'];
    $type_index = $place['typeIndex'];
    $type_count = $place['typeCount'];
    $argument = $qualifier['argument'] ?? NULL;
    return match ($qualifier['name']) {
      'first-child' => $index === 0,
      'last-child' => $index === $count - 1,
      'only-child' => $count === 1,
      'empty' => $place['empty'],
      'first-of-type' => $type_index === 0,
      'last-of-type' => $type_index !== NULL && $type_index === $type_count - 1,
      'only-of-type' => $type_count === 1,
      'nth-child' => self::nthHolds((string) $argument, $index + 1),
      'nth-last-child' => self::nthHolds((string) $argument, $count - $index),
      'nth-of-type' => $type_index !== NULL && self::nthHolds((string) $argument, $type_index + 1),
      'nth-last-of-type' => $type_index !== NULL && self::nthHolds((string) $argument, $type_count - $type_index),
      default => throw new \InvalidArgumentException(sprintf('":%s" is not a position qualifier.', $qualifier['name'])),
    };
  }

  /**
   * Whether a canonical `An+B` argument admits a 1-based position.
   *
   * The argument is the grammar's canonical spelling
   * (RelativeSelector::canonicalNth(): `odd`, `even`, `n`, `-n`, `2n+1`,
   * `-n+3`, `n-1`, `3`, `0`); p is admitted when p = An + B for an
   * integer n >= 0.
   *
   * @throws \InvalidArgumentException
   *   When the argument is not a canonical `An+B`.
   */
  public static function nthHolds(string $argument, int $position): bool {
    if ($argument === 'odd') {
      return $position % 2 === 1;
    }
    if ($argument === 'even') {
      return $position % 2 === 0;
    }
    if (preg_match('/^(-?\d+)$/', $argument, $m)) {
      return $position === (int) $m[1];
    }
    if (!preg_match('/^(-?)(\d*)n([+-]\d+)?$/', $argument, $m)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a canonical An+B.', $argument));
    }
    $a = ($m[2] === '' ? 1 : (int) $m[2]) * ($m[1] === '-' ? -1 : 1);
    $b = isset($m[3]) ? (int) $m[3] : 0;
    if ($a === 0) {
      return $position === $b;
    }
    $steps = $position - $b;
    return $steps % $a === 0 && intdiv($steps, $a) >= 0;
  }

  /**
   * A node's place among its siblings, as structuralHolds() reads it.
   *
   * A block's root holds its content, which the tree carries no nodes
   * for: a block is never empty; a container or region wrapper without
   * child nodes is. The type place is the node's place among the
   * siblings sharing its root tag, NULL when the tree does not know the
   * node's tag.
   */
  private function place(int $node): array {
    $facts = $this->nodes[$node];
    $type_index = NULL;
    $type_count = NULL;
    if ($facts['tag'] !== NULL) {
      $type_count = 0;
      foreach ($this->siblings($node) as $sibling) {
        if ($this->nodes[$sibling]['tag'] !== $facts['tag']) {
          continue;
        }
        if ($sibling === $node) {
          $type_index = $type_count;
        }
        $type_count++;
      }
    }
    return [
      'index' => $facts['index'],
      'count' => $facts['count'],
      'empty' => $facts['kind'] !== 'block' && $facts['childCount'] === 0,
      'typeIndex' => $type_index,
      'typeCount' => $type_count,
    ];
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
   *   The ancestors' indexes, nearest first.
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
   *   The siblings' indexes in document order.
   */
  private function siblings(int $node): array {
    $parent = $this->nodes[$node]['parent'];
    return $parent === NULL ? $this->roots : $this->nodes[$parent]['children'];
  }

  /**
   * The node's earlier siblings, nearest first ([] for a first child).
   *
   * @return int[]
   *   The earlier siblings' indexes, nearest first.
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
    if (!$this->compoundMatches($node, $compounds[$i], $anchor)) {
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
   * not match the node; `:is()` holds when any of its member compounds
   * matches the node (isHolds(), each member judged as `:not()`'s
   * compound is); a position qualifier holds by structuralHolds()
   * over the node's place on a compound the tree matches by node (`*`,
   * the implied subject, a type, the anchor), and is reach-neutral on a
   * tag or class compound: a tag's position may be a position inside a
   * root, where the bag has no order, so the qualifier holds as if
   * absent rather than promising an order the tree cannot see. A class
   * qualifier naming a variant class holds by the node's wear on a
   * compound read by node (reach-neutral on a tag or class compound,
   * like a position); a markup class, an attribute and a pseudo-class
   * that is no position hold as if absent everywhere (the tree carries
   * no markup classes or attributes, and a state is the pose's to
   * light, never reach).
   *
   * @param int $node
   *   The node's index.
   * @param array $compound
   *   The compound record.
   * @param int $anchor
   *   The node the chain's lead relates to: the scope root at the top
   *   level (the one node the anchor compound `&` matches), the
   *   qualified node inside `:has()`.
   * @param bool $by_node
   *   Whether the compound is read by node: FALSE inside a `:not()` or
   *   an `:is()` whose host compound is a tag or a class, so
   *   `p:not(:last-child)` and `p:is(:first-child, :last-child)` are as
   *   reach-neutral as `p:last-child`.
   */
  private function compoundMatches(int $node, array $compound, int $anchor, bool $by_node = TRUE): bool {
    if (!$this->subjectMatches($node, $compound, $anchor)) {
      return FALSE;
    }
    $by_node = $by_node && !in_array($compound['kind'], [RelativeSelector::TAG, RelativeSelector::CLASS_PART], TRUE);
    foreach ($compound['qualifiers'] as $qualifier) {
      $holds = match (TRUE) {
        $qualifier['name'] === RelativeSelector::HAS => $this->hasHolds($node, $qualifier['argument']),
        $qualifier['name'] === RelativeSelector::NOT => !$this->compoundMatches($node, $qualifier['argument'], $anchor, $by_node),
        $qualifier['name'] === RelativeSelector::IS => $this->isHolds($node, $qualifier['argument'], $anchor, $by_node),
        $qualifier['name'] === RelativeSelector::CLASS_QUALIFIER => !$by_node || self::classHolds($qualifier['argument'], $this->nodes[$node]['wear']),
        self::isPosition($qualifier['name']) => !$by_node || self::structuralHolds($qualifier, $this->place($node)),
        default => TRUE,
      };
      if (!$holds) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Whether an `:is()` qualifier holds at a node: any member matches it.
   *
   * Each member is a compound carrying no combinator (the shape of a
   * `:not()` argument; the grammar never records fewer than two) and is
   * judged exactly as `:not()`'s is, by compoundMatches() under the
   * host's own by-node reading: a type or `*` member's positions and
   * wear are judged by node, a tag member matches by root tag with its
   * own qualifiers reach-neutral, a class member by the node's anatomy
   * or wear, and a member of states alone (`:is(:hover, :focus-within)`)
   * holds as if absent, so `> d-text:is(:hover, :focus-within) a`
   * reaches as `> d-text a` does.
   *
   * @param int $node
   *   The node's index.
   * @param array $members
   *   The qualifier's argument: the member compounds.
   * @param int $anchor
   *   The node the enclosing chain's lead relates to.
   * @param bool $by_node
   *   The host compound's by-node reading (see compoundMatches()).
   */
  private function isHolds(int $node, array $members, int $anchor, bool $by_node): bool {
    foreach ($members as $member) {
      if ($this->compoundMatches($node, $member, $anchor, $by_node)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether a qualifier name is one of the grammar's position pseudo-classes.
   */
  private static function isPosition(string $name): bool {
    return in_array($name, RelativeSelector::POSITIONS, TRUE) || in_array($name, RelativeSelector::NTH_POSITIONS, TRUE);
  }

  /**
   * Whether a class qualifier holds against a node's wear.
   *
   * A variant class (VariantStylesheet::CLASS_PREFIX) holds when the
   * node wears it; a markup class is no tree fact and holds as if absent.
   *
   * @param string $class
   *   The qualifier's argument (the bare class name).
   * @param string[] $wear
   *   The node's worn variant classes.
   */
  private static function classHolds(string $class, array $wear): bool {
    return !str_starts_with($class, VariantStylesheet::CLASS_PREFIX) || in_array($class, $wear, TRUE);
  }

  /**
   * Whether the node's facts match a compound's subject.
   *
   * `*` and an implied subject (a `:not()` argument or an `:is()` member
   * of qualifiers alone) match every node; a tag the node's root tag; a
   * type the node's address by family; the anchor `&` the scope root
   * alone. A part class matches no node in a chain (anatomy inside a
   * root is not a tree fact, never over-claimed) but IS judged as a
   * `:not()` argument or an `:is()` member, the compounds carrying no
   * combinator: a node whose parts carry
   * the class, hoisted onto its root or nested inside it, wears the
   * class and is what `:not(.cls)` excludes (the flattened parts do not
   * tell the two apart, and excluding both is the reading that never
   * over-claims a reach); a node wearing the class as a variant class
   * is excluded the same way.
   */
  private function subjectMatches(int $node, array $compound, int $anchor): bool {
    $facts = $this->nodes[$node];
    return match ($compound['kind']) {
      NULL, RelativeSelector::UNIVERSAL => TRUE,
      RelativeSelector::TAG => $facts['tag'] !== NULL && $facts['tag'] === $compound['name'],
      RelativeSelector::TYPE => $facts['address'] !== NULL && self::typeMatches($compound['name'], $facts['address']),
      RelativeSelector::CLASS_PART => $compound['combinator'] === NULL && (array_key_exists($compound['name'], $facts['parts']) || in_array(substr($compound['name'], 1), $facts['wear'], TRUE)),
      RelativeSelector::ANCHOR_KIND => $node === $anchor,
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
   * The structural twin of CssPrinter::addressMembers(): `d-container`
   * is the layout family, `d-block` both block placement families, any
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
