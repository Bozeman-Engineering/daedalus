<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\FieldHandler\LinkFieldHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;

/**
 * Prints the lean node tree as a canonical dialect document.
 *
 * The print serves two readers with different contracts, and spells them
 * in two registers. The AUTHORED register is the page language itself
 * (spec v0.2): every spelling in it is one the model may write back
 * verbatim, so print(parse(x)) is byte-identical for canonical documents
 * and the print teaches the write. The READ-ONLY register is the one the
 * dialect already had for server-derived facts: data-* attributes, which
 * the parser refuses authored (READONLY_ATTR), and CSS comments, which
 * both parsers blank. Whatever the tree holds that the authored grammar
 * cannot spell prints there, at the grain of the gap, never by failing
 * the document, so the print is total over data: nothing a page holds
 * costs its print. Canonical form: two-space indent per depth; attribute
 * order id, axis attributes in declared order, class, field attributes in
 * catalog order, style last; one line per block; rich bodies verbatim;
 * the <style> block printed last, rules grouped per node in document
 * order.
 *
 * Server residue never prints: info, form-only stubs, block labels,
 * label_rendered, layout_settings. The spec section 3 ruling, amended:
 * what the model cannot write, it does not read in the authored
 * register; it reads it only as a read-only fact.
 *
 * Provenance prints: a placement root carries blueprint="id", the
 * blueprint it was placed from, right after its identity, so the model can
 * see what a band IS and keep that fact when it rewrites the element (the
 * parser takes the attribute back as an identity fact, never as a request
 * to re-instantiate). Only a stamp naming a rostered (published) blueprint
 * prints: a dead stamp is residue the model could not write back.
 *
 * A container's name prints: a section carrying a label (the word the
 * editor's tree, chips and panels call it by, the element-naming spine's
 * one renamable slot) prints name="..." after its provenance, and the
 * parser takes it back onto the same slot (DialectWalk::NAME_ATTR), so
 * the model reads "Welcome", refers to the band by it and renames it
 * through set {name}. A block's label stays residue: its name is derived
 * from its content and is not renamable. A slot section (a top-level
 * container carrying `slot`) prints as <pb-slot name="id"> instead: name
 * is the slot it fills, a birth fact, and the parser takes it back as
 * the node's `slot`; such a section's label has no spelling (0.7).
 *
 * The print teaches the write (dialect 0.4): every spelling the model
 * reads must be one it can write back verbatim. Attribute values are
 * escaped to the HTML5 minimum only (& and "), so url="<nolink>" and
 * url="<front>" print as written, and every style value prints through
 * the catalog's value grammar (ValueGrammar::canonical(), the same
 * rewrite the parser applies on write), so media tokens read back as the
 * single-quoted url('media:<uuid>') spelling — the one form valid inside
 * a style="" attribute — wherever a style value prints (style="",
 * <style> rules, fragment prints), whatever quoting the stored value
 * carries. Variant wear prints by the record's spelling: an axis
 * attribute where the variant spells as one on the element's tag (0.6:
 * every tag has an axis view, the container's and each block form's),
 * class otherwise. Canonical attribute order: id, data-alias, data-can,
 * blueprint, name (containers), axis attributes, class, field attributes,
 * style.
 *
 * The verdict prints: an element the actor cannot fully edit (a node
 * stamped by VerdictStamper whose `can` map refuses anything) carries
 * data-can="", the capabilities still open on it in the vocabulary's
 * order (`data-can="content"` on a frozen translation's block, `data-can=""`
 * on a template element a page renders), so the model reads what it may
 * write before it writes. An unstamped tree (a blueprint definition, a
 * fragment) prints none. Server-derived like data-alias: the parser
 * refuses it authored (READONLY_ATTR).
 *
 * Every print answers a DialectPrint: the text and its notes. A note
 * (DialectPrintNote) is one place the text spells something in the
 * read-only register: its kind (DialectPrintNoteKind), the printed id of
 * the element it is about (NULL when the gap is a fragment's) and a
 * detail reading "{subject} {reason}", spelled here so every consumer
 * repeats it alike. A print with no notes is wholly authored
 * (isAuthored()), the promise a write template needs; a print with any
 * heads the model's read with its reading notes (forReader()). The gaps
 * and their spellings: a section on a layout other than the container
 * prints data-layout and, with more than one region, data-regions, its
 * region nodes transparent (the children print at the section's depth in
 * region order, each carrying data-region; a region is an address on an
 * op's `at`, never an element); a field the contract cannot spell (a
 * structured value under a scalar attribute, a field with no contract, a
 * generic block's non-scalar value) is named in the element's
 * data-unprinted and never printed; a link holding a title or options
 * prints its bare uri and names its attribute in data-partial; a
 * style-layer entry outside the grammar (a variant with no axis or class
 * spelling, a field-scoped local, a condition outside the catalog, a
 * local outside the relative selector grammar, conditions on an element
 * without an id) prints as one `/* ... *\/` line in the <style> block
 * naming the element and the reason, in printStyles() too; a fragment
 * over nodes carrying rules prints without them and notes the fragment.
 * On saved content the field gaps sort by block form: a declared
 * element's is a link's title or options (data-partial), because a field
 * deriveContract() cannot declare turns its whole type generic, and
 * data-unprinted is the generic block's; fieldAttrs()'s unprinted
 * branches are reached only by a tree built in memory. The parser
 * refuses every one of these annotations written back. What remains of
 * DialectPrintException is the unknown node kind, a programming error.
 * A '::' local prints as
 * its relative selector after #id (`#id > pb-text .link`), the one
 * grammar the parser reads back; states on the addressed element append
 * to the selector's last compound and its generated box after them
 * (`#id:hover::after` for the anchor's `::&::after` local, `#id >
 * pb-text::before` for a chain's); the rule's own variant references
 * print as wear declarations in its plain block (`#id > pb-text { tone:
 * loud; class: button; color: red; }`), the attribute's twin
 * (ruleWear()).
 */
class DialectPrinter {

  /**
   * Prints a tree (list of top-level nodes) as a dialect document.
   *
   * @param array $tree
   *   The lean node tree: an ordered list of top-level nodes.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog the tree speaks.
   * @param array $aliases
   *   The uuid => alias pairings for this entity and conversation (the
   *   DialectAliasMap's printAliases()); a node whose uuid has one prints
   *   `data-alias` beside its id so the author's name keeps working on
   *   later turns. Print-only: the parser rejects authored data-*.
   *
   * @return \Drupal\daedalus_blueprint\Dialect\DialectPrint
   *   The document, newline-terminated, and its notes.
   */
  public function print(array $tree, DialectCatalog $catalog, array $aliases = []): DialectPrint {
    $lines = [];
    $rules = [];
    $notes = [];
    foreach ($tree as $node) {
      $this->printNode($node, $catalog, 0, $lines, $rules, $notes, $aliases);
    }
    if ($rules !== []) {
      $lines[] = '<style>';
      foreach ($rules as $rule) {
        $lines[] = $rule;
      }
      $lines[] = '</style>';
    }
    return new DialectPrint(implode("\n", $lines) . "\n", $notes);
  }

  /**
   * Prints a subtree as an op-payload fragment (no <style> block).
   *
   * Fragments carry conditions and parts on nothing: only style="" rides.
   * A node holding rules prints without them, and the result carries one
   * unprinted-style note for the fragment (no element: a fragment has no
   * <style> block to comment in), so a consumer offering the fragment as
   * a write template sees it is less than the tree.
   *
   * @return \Drupal\daedalus_blueprint\Dialect\DialectPrint
   *   The fragment, newline-terminated, and its notes.
   */
  public function printFragment(array $nodes, DialectCatalog $catalog, array $aliases = []): DialectPrint {
    $lines = [];
    $rules = [];
    $notes = [];
    foreach ($nodes as $node) {
      $this->printNode($node, $catalog, 0, $lines, $rules, $notes, $aliases);
    }
    // The style-layer gaps already noted print as comments; a real rule
    // is what the fragment leaves out.
    $dropped = array_filter($rules, static fn (string $line): bool => !str_starts_with(ltrim($line), '/*'));
    if ($dropped !== []) {
      $notes[] = new DialectPrintNote(DialectPrintNoteKind::UnprintedStyle, NULL, 'the fragment leaves out the conditions and parts its elements carry; a fragment spells only style="", conditions and parts ride the style op');
    }
    return new DialectPrint(implode("\n", $lines) . "\n", $notes);
  }

  /**
   * Prints a subtree's styles as <style> content, every entry a rule.
   *
   * The one per-element CSS print (the panel's "Copy CSS", the api's
   * read mode css): what print() spells as the document's <style> block
   * for these nodes, walked in document order, plus the one entry
   * print() puts on the tag instead, the element's own default-condition
   * styles, printed here as a `#id { }` rule because a clipboard text has
   * no tag to carry a style="". Every rule is spelled exactly as print()
   * spells it (rule()), so what a designer copies is what the AI reads
   * and what the parser takes back: parseStyleBlock() over the print
   * yields the entries the nodes hold.
   *
   * Two comment lines head each styled element, both blanked by the
   * parser: its words (`/* Section: Welcome *\/`, the naming spine's
   * `_element` type and name, which the lean tree carries once
   * daedalus_ui's annotator has walked it; a bare tree prints no words), and
   * its own wear (`/* wears tone="loud" class="button" *\/`, the tag's
   * attribute spelling, variantAttrs()), because the dialect has no rule
   * spelling for an element's own wear: the parser refuses it as
   * WEAR_ON_ELEMENT, so it rides as a note, never as a declaration. A
   * rule's wear prints inside its rule as print() does.
   *
   * Region nodes are transparent (their children print); an element
   * with neither styles nor wear prints nothing. An entry the grammar
   * cannot spell (styleGap()) prints as a comment among the element's
   * lines, and the result notes it.
   *
   * @param array $nodes
   *   Lean nodes (NodeTreeBuilder's shape), a subtree or a pruned
   *   element.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog the tree speaks.
   * @param array $aliases
   *   The uuid => alias pairings (unused by rules, which print the uuid
   *   only; accepted for signature parity with print()).
   *
   * @return \Drupal\daedalus_blueprint\Dialect\DialectPrint
   *   The <style> content, newline-terminated ('' when nothing prints),
   *   and its notes.
   */
  public function printStyles(array $nodes, DialectCatalog $catalog, array $aliases = []): DialectPrint {
    $lines = [];
    $notes = [];
    foreach ($nodes as $node) {
      $this->collectStyleLines($node, $catalog, $lines, $notes);
    }
    return new DialectPrint($lines === [] ? '' : implode("\n", $lines) . "\n", $notes);
  }

  /**
   * Collects one node's CSS print lines, then its children's.
   */
  protected function collectStyleLines(array $node, DialectCatalog $catalog, array &$lines, array &$notes): void {
    $kind = $node['kind'] ?? NULL;
    if ($kind === 'region') {
      foreach ($node['children'] ?? [] as $child) {
        $this->collectStyleLines($child, $catalog, $lines, $notes);
      }
      return;
    }
    if ($kind !== 'container' && $kind !== 'block' && $kind !== LinkedBlueprintBlock::KIND) {
      throw new DialectPrintException(sprintf('unknown node kind "%s"; the CSS print covers container, block and linked nodes', (string) $kind));
    }
    $rules = [];
    $this->collectRules($node, $catalog, $kind === 'container', $rules, $notes, TRUE);
    $wear = $this->variantAttrs($node, $catalog, $this->axesOf($node, $catalog), $this->tagOf($node, $catalog), $rules, $notes);
    if ($rules !== [] || $wear !== []) {
      $words = $this->words($node);
      if ($words !== NULL) {
        $lines[] = '  /* ' . $words . ' */';
      }
      if ($wear !== []) {
        $lines[] = '  /* wears' . $this->attrString($wear) . ' */';
      }
      array_push($lines, ...$rules);
    }
    foreach ($node['children'] ?? [] as $child) {
      $this->collectStyleLines($child, $catalog, $lines, $notes);
    }
  }

  /**
   * The element's words from the naming spine's annotation, if carried.
   *
   * `_element.type` and `_element.name` are daedalus_ui's annotator's
   * facts (the type word "Section", the renamable or derived name), which
   * ride the lean tree's nodes once the annotator has walked it.
   * "Type: Name" when the name says more than the type, the type alone
   * when the element is called by its type, NULL when the tree carries no
   * annotation.
   */
  protected function words(array $node): ?string {
    $element = $node['_element'] ?? NULL;
    if (!is_array($element)) {
      return NULL;
    }
    $type = trim((string) ($element['type'] ?? ''));
    $name = trim((string) ($element['name'] ?? ''));
    if ($type === '' && $name === '') {
      return NULL;
    }
    if ($type === '' || $name === '' || $name === $type) {
      return $type !== '' ? $type : $name;
    }
    return $type . ': ' . $name;
  }

  /**
   * The element's axis view, the way its tag print reads it.
   */
  protected function axesOf(array $node, DialectCatalog $catalog): array {
    if (($node['kind'] ?? '') === 'container') {
      return $catalog->axesFor(TRUE, NULL, NULL);
    }
    $plugin = NodeTreeBuilder::pluginBase($node);
    if ($plugin !== NULL) {
      return $catalog->axesFor(FALSE, NULL, $plugin);
    }
    return $catalog->axesFor(FALSE, (string) ($node['block_type'] ?? ''), NULL);
  }

  /**
   * The element's dialect tag, for the wear print's exception.
   */
  protected function tagOf(array $node, DialectCatalog $catalog): string {
    if (($node['kind'] ?? '') === 'container') {
      return is_string($node['slot'] ?? NULL) && $node['slot'] !== '' ? DialectWalk::SLOT_TAG : 'pb-container';
    }
    if (($node['kind'] ?? '') === LinkedBlueprintBlock::KIND) {
      return DialectWalk::LINKED_TAG;
    }
    $block_type = (string) ($node['block_type'] ?? '');
    if (NodeTreeBuilder::pluginBase($node) !== NULL || $block_type === '' || $catalog->blockType($block_type) === NULL) {
      return 'pb-block';
    }
    return DialectCatalog::tagForBlockType($block_type);
  }

  /**
   * Prints one node and collects its <style> rules and the print's notes.
   *
   * @param array $node
   *   The lean node.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param int $depth
   *   The node's depth (two spaces of indent each).
   * @param string[] $lines
   *   Collector for the printed lines (by reference).
   * @param string[] $rules
   *   Collector for the <style> block's lines (by reference).
   * @param \Drupal\daedalus_blueprint\Dialect\DialectPrintNote[] $notes
   *   Collector for the print's notes (by reference).
   * @param array $aliases
   *   The uuid => alias pairings (print()).
   * @param string|null $region
   *   The region of its parent section the node sits in, when that
   *   section has more than one (printed as data-region); NULL otherwise.
   */
  protected function printNode(array $node, DialectCatalog $catalog, int $depth, array &$lines, array &$rules, array &$notes, array $aliases = [], ?string $region = NULL): void {
    $indent = str_repeat('  ', $depth);
    $kind = $node['kind'] ?? NULL;
    switch ($kind) {
      case 'container':
        $regions = $this->regionsOf($node);
        // A slot section prints as <pb-slot name="id">: name is the slot it
        // fills (the serializer's `slot` on a top-level container), the
        // one word it is called by, so its label has no spelling there.
        $tag = $this->tagOf($node, $catalog);
        $attrs = $this->identityAttr($node, $aliases)
          + $this->verdictAttr($node)
          + $this->layoutAttrs($node, $regions, $catalog, $notes)
          + $this->regionAttr($region)
          + $this->provenanceAttr($node, $catalog)
          + ($tag === DialectWalk::SLOT_TAG ? [DialectWalk::NAME_ATTR => (string) $node['slot']] : $this->nameAttr($node))
          + $this->variantAttrs($node, $catalog, $catalog->axesFor(TRUE, NULL, NULL), $tag, $rules, $notes)
          + $this->styleAttr($node, $catalog);
        $lines[] = sprintf('%s<%s%s>', $indent, $tag, $this->attrString($attrs));
        $this->collectRules($node, $catalog, TRUE, $rules, $notes);
        // Regions are transparent: their children print at the depth the
        // container's own children would, each naming its region when the
        // section has more than one (a single-region layout needs none).
        foreach ($node['children'] ?? [] as $child) {
          if (($child['kind'] ?? NULL) !== 'region') {
            $this->printNode($child, $catalog, $depth + 1, $lines, $rules, $notes, $aliases);
            continue;
          }
          $child_region = count($regions) > 1 ? (string) $child['region'] : NULL;
          foreach ($child['children'] ?? [] as $grandchild) {
            $this->printNode($grandchild, $catalog, $depth + 1, $lines, $rules, $notes, $aliases, $child_region);
          }
        }
        $lines[] = sprintf('%s</%s>', $indent, $tag);
        return;

      case 'block':
        $this->printBlock($node, $catalog, $indent, $lines, $rules, $notes, $aliases, $region);
        return;

      case 'blueprint':
        $attrs = $this->identityAttr($node, $aliases) + $this->regionAttr($region) + ['name' => (string) ($node['name'] ?? '')];
        $lines[] = sprintf('%s<pb-blueprint%s />', $indent, $this->attrString($attrs));
        return;

      case LinkedBlueprintBlock::KIND:
        // A blueprint shown by reference: one block whose content is the
        // blueprint's document. The tag names the blueprint and carries
        // the placement's own identity, verdict and box styles; it prints
        // no children, since the blueprint's elements are not this
        // document's.
        $this->collectRules($node, $catalog, FALSE, $rules, $notes);
        $attrs = $this->identityAttr($node, $aliases)
          + $this->verdictAttr($node)
          + $this->regionAttr($region)
          + [DialectWalk::LINKED_ATTR => (string) ($node[LinkedBlueprintBlock::BLUEPRINT_KEY] ?? '')]
          + $this->variantAttrs($node, $catalog, $catalog->axesFor(FALSE, NULL, LinkedBlueprintBlock::PLUGIN_ID), DialectWalk::LINKED_TAG, $rules, $notes)
          + $this->styleAttr($node, $catalog);
        $lines[] = sprintf('%s<%s%s />', $indent, DialectWalk::LINKED_TAG, $this->attrString($attrs));
        return;

      case 'region':
        // A region reached outside its section (a subtree print rooted
        // under one) is transparent, as in the CSS print.
        foreach ($node['children'] ?? [] as $child) {
          $this->printNode($child, $catalog, $depth, $lines, $rules, $notes, $aliases);
        }
        return;

      default:
        throw new DialectPrintException(sprintf('unknown node kind "%s"; the dialect prints container, block, linked and blueprint nodes', (string) $kind));
    }
  }

  /**
   * The region names of a section whose children are region nodes.
   *
   * The serializer emits a region node per region of a non-container
   * layout, empty ones included, in the layout's order; a container
   * layout's children are the elements themselves.
   *
   * @return string[]
   *   The region names in layout order; [] for a container layout.
   */
  protected function regionsOf(array $node): array {
    $regions = [];
    foreach ($node['children'] ?? [] as $child) {
      if (($child['kind'] ?? NULL) === 'region') {
        $regions[] = (string) ($child['region'] ?? '');
      }
    }
    return $regions;
  }

  /**
   * The layout facts of a section the authored grammar does not spell.
   *
   * A section on a layout other than the container prints its layout id
   * as data-layout and, when the layout has more than one region, the
   * region names as data-regions (space-separated, layout order), both
   * read-only; the result notes the section. A container-layout section
   * prints neither.
   */
  protected function layoutAttrs(array $node, array $regions, DialectCatalog $catalog, array &$notes): array {
    if ($regions === []) {
      return [];
    }
    $layout = (string) ($node['layout_id'] ?? '');
    $attrs = ['data-layout' => $layout];
    if (count($regions) > 1) {
      $attrs['data-regions'] = implode(' ', $regions);
      $reason = sprintf('is a section on the "%s" layout (regions %s), which the page language does not spell', $layout, implode(', ', $regions));
    }
    else {
      $reason = sprintf('is a section on the "%s" layout, which the page language does not spell', $layout);
    }
    $this->note(DialectPrintNoteKind::Layout, $node, $catalog, $reason, $notes, $regions);
    return $attrs;
  }

  /**
   * The data-region attribute of a child of a multi-region section.
   */
  protected function regionAttr(?string $region): array {
    return $region === NULL ? [] : ['data-region' => $region];
  }

  /**
   * Prints one block node.
   */
  protected function printBlock(array $node, DialectCatalog $catalog, string $indent, array &$lines, array &$rules, array &$notes, array $aliases = [], ?string $region = NULL): void {
    $this->collectRules($node, $catalog, FALSE, $rules, $notes);

    // A plugin block prints its plugin id; a field block the FIELD it names
    // (the page the print lands on supplies the derivative). Both wear
    // under the base plugin (NodeTreeBuilder::pluginBase).
    $plugin = NodeTreeBuilder::pluginBase($node);
    if ($plugin !== NULL) {
      $identity = isset($node['field'])
        ? ['field' => (string) $node['field']]
        : ['plugin' => (string) $node['plugin_id']];
      $attrs = $this->identityAttr($node, $aliases)
        + $this->verdictAttr($node)
        + $this->regionAttr($region)
        + $this->provenanceAttr($node, $catalog)
        + $identity
        + $this->variantAttrs($node, $catalog, $catalog->axesFor(FALSE, NULL, $plugin), 'pb-block', $rules, $notes)
        + $this->styleAttr($node, $catalog);
      $lines[] = sprintf('%s<pb-block%s />', $indent, $this->attrString($attrs));
      return;
    }

    $block_type = (string) ($node['block_type'] ?? '');
    $spec = $catalog->blockType($block_type);
    if ($spec === NULL) {
      $this->printGenericBlock($node, $block_type, $catalog, $indent, $lines, $rules, $notes, $aliases, $region);
      return;
    }

    $tag = DialectCatalog::tagForBlockType($block_type);
    $fields = $node['block_fields'] ?? [];
    $axes = $catalog->axesFor(FALSE, $block_type, NULL);
    $attrs = $this->identityAttr($node, $aliases)
      + $this->verdictAttr($node)
      + $this->regionAttr($region)
      + $this->provenanceAttr($node, $catalog)
      + $this->variantAttrs($node, $catalog, $axes, $tag, $rules, $notes)
      + $this->fieldAttrs($node, $fields, $spec, $catalog, $notes)
      + $this->styleAttr($node, $catalog);
    // Canonical order: id, data-alias, data-can, data-region,
    // data-unprinted, data-partial, blueprint, axis attributes in catalog
    // order, class, field attributes in catalog order, style last.
    $ordered = [];
    foreach (array_merge(['id', 'data-alias', 'data-can', 'data-region', 'data-unprinted', 'data-partial', 'blueprint'], array_keys($axes), ['class'], array_keys($spec['attributes'] ?? []), ['style']) as $name) {
      if (array_key_exists($name, $attrs)) {
        $ordered[$name] = $attrs[$name];
      }
    }

    $body = $spec['body'] ?? NULL;
    $body_value = $body === NULL ? NULL : ($fields[$body['field']] ?? NULL);
    if ($this->isStub($body_value)) {
      $body_value = NULL;
    }

    if ($body === NULL) {
      // Bodyless kinds print self-closing; the parser closes them either
      // way (the childless ones are void there).
      $lines[] = sprintf('%s<%s%s />', $indent, $tag, $this->attrString($ordered));
      return;
    }
    if ($body['kind'] === 'rich') {
      $raw = is_array($body_value) ? (string) ($body_value['value'] ?? '') : (string) ($body_value ?? '');
      $lines[] = sprintf('%s<%s%s>%s</%s>', $indent, $tag, $this->attrString($ordered), $raw, $tag);
      return;
    }
    $text = (string) ($body_value ?? '');
    $lines[] = sprintf('%s<%s%s>%s</%s>', $indent, $tag, $this->attrString($ordered), htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), $tag);
  }

  /**
   * Prints an undeclared block type in the generic pb-block form.
   */
  protected function printGenericBlock(array $node, string $block_type, DialectCatalog $catalog, string $indent, array &$lines, array &$rules, array &$notes, array $aliases = [], ?string $region = NULL): void {
    $fields = [];
    $unprinted = [];
    foreach ($node['block_fields'] ?? [] as $name => $value) {
      if ($name === 'info' || $this->isStub($value)) {
        continue;
      }
      if (!is_scalar($value)) {
        // The generic <pb-field> form spells scalars only; a structured
        // value is named, not printed.
        $unprinted[] = (string) $name;
        $this->note(DialectPrintNoteKind::UnprintedField, $node, $catalog, sprintf('holds field %s as a structured value, which the generic <pb-field> form does not spell', $name), $notes);
        continue;
      }
      $fields[$name] = (string) $value;
    }
    $attrs = $this->identityAttr($node, $aliases)
      + $this->verdictAttr($node)
      + $this->regionAttr($region)
      + ($unprinted === [] ? [] : ['data-unprinted' => implode(' ', $unprinted)])
      + $this->provenanceAttr($node, $catalog)
      + ['type' => $block_type]
      + $this->variantAttrs($node, $catalog, $catalog->axesFor(FALSE, $block_type, NULL), 'pb-block', $rules, $notes)
      + $this->styleAttr($node, $catalog);
    if ($fields === []) {
      $lines[] = sprintf('%s<pb-block%s />', $indent, $this->attrString($attrs));
      return;
    }
    $lines[] = sprintf('%s<pb-block%s>', $indent, $this->attrString($attrs));
    foreach ($fields as $name => $value) {
      $lines[] = sprintf('%s  <pb-field name="%s">%s</pb-field>', $indent, self::escapeAttr($name), $value);
    }
    $lines[] = $indent . '</pb-block>';
  }

  /**
   * The id attribute from the node's identity, when it has one.
   *
   * A uuid identity with a recorded alias also prints `data-alias`, the
   * read-only echo that keeps the author's chosen name resolvable across
   * turns (the alias layer over minted identity).
   */
  protected function identityAttr(array $node, array $aliases = []): array {
    $identity = $node['uuid'] ?? $node['id'] ?? NULL;
    if ($identity === NULL) {
      return [];
    }
    $attrs = ['id' => (string) $identity];
    if (isset($aliases[$identity])) {
      $attrs['data-alias'] = (string) $aliases[$identity];
    }
    return $attrs;
  }

  /**
   * The data-can attribute from the node's verdict stamp, when not whole.
   *
   * The stamp is VerdictStamper's `_verdict` (`can`: capability to bool);
   * an element the actor may do everything to prints nothing, one with a
   * refusal prints the capabilities still open on it, space-separated in
   * the vocabulary's order, `""` when none is.
   */
  protected function verdictAttr(array $node): array {
    $can = $node[VerdictStamper::KEY]['can'] ?? NULL;
    if (!is_array($can) || !in_array(FALSE, $can, TRUE)) {
      return [];
    }
    return ['data-can' => implode(' ', array_keys(array_filter($can)))];
  }

  /**
   * The blueprint attribute from the node's placement provenance, if any.
   *
   * The node's `blueprint` key is the serializer's read-only surface of the
   * stamp place_blueprint wrote at the root's birth (a section's
   * third-party settings, a block's additional data). It prints only while
   * the stamp names a rostered blueprint: the print teaches the write, and
   * the parser refuses a name no published blueprint spells, so a stamp
   * whose blueprint was deleted, unpublished or never existed is residue.
   */
  protected function provenanceAttr(array $node, DialectCatalog $catalog): array {
    $blueprint = $node['blueprint'] ?? NULL;
    if (!is_string($blueprint) || $blueprint === '') {
      return [];
    }
    if ($catalog->roster()->get(MaterialKind::Blueprint, $blueprint) === NULL) {
      return [];
    }
    return ['blueprint' => $blueprint];
  }

  /**
   * The name attribute from a container's label, when it carries one.
   *
   * The node's `label` key is the serializer's surface of the section's
   * layout_settings.label (the tree's rename lands there through
   * update_element {label}); the parser reads name="..." back onto the
   * same key, so the round trip is lossless and one slot names the
   * section on every door.
   */
  protected function nameAttr(array $node): array {
    $label = $node['label'] ?? NULL;
    if (!is_string($label) || $label === '') {
      return [];
    }
    return [DialectWalk::NAME_ATTR => $label];
  }

  /**
   * The element's axis attributes, then class, from its own references.
   *
   * Every reference prints by its record's spelling: an axis attribute the
   * element's axis view carries (in the view's order), else the class
   * spelling (the spine judged the wear when it was applied). A reference
   * spelling as neither has nothing the model could write back: it rides
   * as a <style> comment naming the element and the variant (styleGap()),
   * and the result notes it.
   *
   * A FRAGMENT reference (a composition variant's slot bound onto this
   * element when the variant was applied to an ancestor) prints nothing:
   * the ancestor's own root reference is the whole authored gesture, and
   * re-wearing it there re-distributes the fragments, so a child's
   * fragment is derived state with no spelling of its own. Printing it
   * as a class would invite the model to write it back by hand, which
   * the style map refuses (never write fragment references by hand).
   *
   * A '' reference carrying "via" (the map's provenance key: the
   * composition whose apply distributed this wear onto the element) is
   * the same derived state in its other shape and prints nothing by the
   * same rule. Printing it as the element's own axis attribute or class
   * would have every parse path that expands attributes into apply_variant
   * (page_create, fragment replaces) re-place it on the element as an
   * authored wear with no origin, which a later switch of the ancestor's
   * composition could no longer reap. The model sees the composition on
   * the ancestor and reads the element's layer stack, origin named,
   * through explain_element_styles.
   *
   * @param array $node
   *   The lean node.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param array $axes
   *   The element's axis view (DialectCatalog::axesFor()).
   * @param string $tag
   *   The element's tag, for the gap's wording.
   * @param string[] $rules
   *   Collector for the <style> lines (by reference): a gap's comment.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectPrintNote[] $notes
   *   Collector for the print's notes (by reference).
   */
  protected function variantAttrs(array $node, DialectCatalog $catalog, array $axes, string $tag, array &$rules, array &$notes): array {
    $values = [];
    $classes = [];
    foreach ($node['styles']['']['variants'] ?? [] as $reference) {
      $variant = (string) ($reference['variant'] ?? '');
      $fragment = (string) ($reference['fragment'] ?? '');
      if ($fragment !== '') {
        continue;
      }
      // The map lane's provenance key is a non-empty string when present
      // (StyleMapValidator's gate): an origin-bearing wear is derived.
      if (is_string($reference['via'] ?? NULL) && $reference['via'] !== '') {
        continue;
      }
      $axis = $catalog->axisForVariant($variant);
      if ($axis !== NULL && isset($axes[$axis[0]])) {
        $values[$axis[0]] = $axis[1];
        continue;
      }
      $class = $catalog->classForVariant($variant);
      if ($class === NULL) {
        $this->styleGap($node, $catalog, sprintf('wears variant "%s", which spells as neither an axis attribute of <%s> nor a published style class', $variant, $tag), $rules, $notes);
        continue;
      }
      $classes[] = $class;
    }
    $attrs = [];
    foreach (array_keys($axes) as $axis_name) {
      if (isset($values[$axis_name])) {
        $attrs[$axis_name] = $values[$axis_name];
      }
    }
    if ($classes !== []) {
      $attrs['class'] = implode(' ', $classes);
    }
    return $attrs;
  }

  /**
   * Field attributes under the catalog contracts, in catalog order.
   *
   * Total over the fields: a value the contract cannot spell (a
   * structured value under a scalar contract, a field with no attribute
   * or body contract at all) is named in data-unprinted instead of
   * printed, and a link printed as its bare uri while the stored value
   * also carries a title or options names its attribute in data-partial.
   * Both are read-only facts the parser refuses authored; each one is a
   * note on the result. Field names in data-unprinted follow the catalog
   * order, then the block's own order for fields outside the contract.
   */
  protected function fieldAttrs(array $node, array $fields, array $spec, DialectCatalog $catalog, array &$notes): array {
    $attrs = [];
    $unprinted = [];
    $partial = [];
    $contracted = isset($spec['body']['field']) ? [$spec['body']['field'] => TRUE] : [];
    foreach ($spec['attributes'] ?? [] as $name => $attr_spec) {
      $contracted[$attr_spec['field']] = TRUE;
      $value = $fields[$attr_spec['field']] ?? NULL;
      if ($value === NULL || $this->isStub($value)) {
        continue;
      }
      if (isset($attr_spec['key'])) {
        if (is_array($value) && isset($value[$attr_spec['key']]) && is_scalar($value[$attr_spec['key']])) {
          $attrs[$name] = (string) $value[$attr_spec['key']];
        }
        continue;
      }
      $kind = $attr_spec['kind'] ?? NULL;
      if ($kind === 'link') {
        // Storage uris ("route:<nolink>", "internal:/about") print in the
        // authorable grammar, so what is read is what is written.
        if (is_scalar($value)) {
          $attrs[$name] = LinkFieldHandler::getUriAsAuthorableString((string) $value);
          continue;
        }
        $meaningful = array_diff(array_keys(array_filter((array) $value, static fn ($v) => $v !== NULL && $v !== '' && $v !== [])), [
          'uri',
          '_displayable',
        ]);
        if (isset($value['uri'])) {
          $attrs[$name] = LinkFieldHandler::getUriAsAuthorableString((string) $value['uri']);
          if ($meaningful !== []) {
            // The bare uri is the one spelling; the rest of the link is
            // more than the print shows.
            $partial[] = $name;
            $this->note(DialectPrintNoteKind::PartialField, $node, $catalog, sprintf('prints %s as the bare uri; the stored link also carries %s, which the page language does not spell', $name, implode(', ', $meaningful)), $notes);
          }
        }
        elseif ($meaningful !== []) {
          $unprinted[] = $attr_spec['field'];
          $this->note(DialectPrintNoteKind::UnprintedField, $node, $catalog, sprintf('holds field %s as a link with no uri (%s), which the %s attribute does not spell', $attr_spec['field'], implode(', ', $meaningful), $name), $notes);
        }
        continue;
      }
      if ($kind === 'ref') {
        if (is_array($value)) {
          if (isset($value['target_id'])) {
            $attrs[$name] = (string) $value['target_id'];
          }
          elseif (isset($value['_label'])) {
            $attrs[$name] = (string) $value['_label'];
          }
        }
        else {
          $attrs[$name] = (string) $value;
        }
        continue;
      }
      if (!is_scalar($value)) {
        $unprinted[] = $attr_spec['field'];
        $this->note(DialectPrintNoteKind::UnprintedField, $node, $catalog, sprintf('holds field %s as a structured value, which the %s attribute does not spell', $attr_spec['field'], $name), $notes);
        continue;
      }
      $attrs[$name] = (string) $value;
    }
    // A field outside the print contract is kept by a set (a set names
    // its fields) and lost by a replace; the model is told it is there.
    foreach ($fields as $name => $value) {
      if ($name === 'info' || isset($contracted[$name]) || $this->isStub($value)) {
        continue;
      }
      $unprinted[] = (string) $name;
      $this->note(DialectPrintNoteKind::UnprintedField, $node, $catalog, sprintf('holds field %s, which has no attribute or body contract in the catalog', $name), $notes);
    }
    if ($unprinted !== []) {
      $attrs['data-unprinted'] = implode(' ', $unprinted);
    }
    if ($partial !== []) {
      $attrs['data-partial'] = implode(' ', $partial);
    }
    return $attrs;
  }

  /**
   * Records a note about one node, spelled as the node and its reason.
   *
   * The detail reads "{subject} {reason}": `#<id>` when the node has an
   * identity, else its tag in angle brackets (a definition tree prints
   * without ids), so a consumer repeating the detail names the element
   * either way. A layout note carries the section's region names too,
   * structured, so the reading notes never parse the detail.
   */
  protected function note(DialectPrintNoteKind $kind, array $node, DialectCatalog $catalog, string $reason, array &$notes, array $regions = []): string {
    $identity = $node['uuid'] ?? $node['id'] ?? NULL;
    $subject = $identity === NULL ? '<' . $this->tagOf($node, $catalog) . '>' : '#' . $identity;
    $detail = $subject . ' ' . $reason;
    $notes[] = new DialectPrintNote($kind, $identity === NULL ? NULL : (string) $identity, $detail, $regions);
    return $detail;
  }

  /**
   * Records a style-layer gap: a <style> comment line and its note.
   *
   * The comment is the read-only register of the <style> block (both
   * parsers blank comments), one line per element and reason, at the
   * block's rule indent; a `* /` sequence in the reason cannot close it
   * early.
   */
  protected function styleGap(array $node, DialectCatalog $catalog, string $reason, array &$rules, array &$notes): void {
    $detail = $this->note(DialectPrintNoteKind::UnprintedStyle, $node, $catalog, $reason, $notes);
    $rules[] = '  /* ' . str_replace('*/', '* /', $detail) . ' */';
  }

  /**
   * The style attribute from the own entry's default-condition block.
   */
  protected function styleAttr(array $node, DialectCatalog $catalog): array {
    $default = $node['styles']['']['default'] ?? [];
    if ($default === []) {
      return [];
    }
    return ['style' => $this->declarationList($default, $catalog)];
  }

  /**
   * Collects a node's <style> rules: conditions and parts, in real CSS.
   *
   * @param array $node
   *   The lean node.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param bool $is_container
   *   Whether the node is a container.
   * @param string[] $rules
   *   Collector for the printed rules (by reference); a style-layer gap
   *   lands here as a comment line (styleGap()).
   * @param \Drupal\daedalus_blueprint\Dialect\DialectPrintNote[] $notes
   *   Collector for the print's notes (by reference).
   * @param bool $own_default_as_rule
   *   Whether the element's own default-condition entry prints as a
   *   `#id { }` rule too (the CSS print, which has no tag for a style="")
   *   instead of riding the tag alone (the document print).
   */
  protected function collectRules(array $node, DialectCatalog $catalog, bool $is_container, array &$rules, array &$notes, bool $own_default_as_rule = FALSE): void {
    $identity = $node['uuid'] ?? $node['id'] ?? NULL;
    $styles = $node['styles'] ?? [];
    if ($styles === []) {
      return;
    }
    if ($identity === NULL) {
      // Only style="" and variant attributes ride an element with no id;
      // a rule needs an id to address.
      foreach ($styles as $local => $entry) {
        if ($local !== '' || array_diff(array_keys((array) $entry), ['variants', 'default']) !== []) {
          $this->styleGap($node, $catalog, 'carries conditions or parts, which have no print on an element without an id', $rules, $notes);
          break;
        }
      }
      return;
    }
    foreach ($styles as $local => $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $local = (string) $local;
      if ($local !== '' && !str_starts_with($local, '::')) {
        $this->styleGap($node, $catalog, sprintf('carries the field-scoped style local "%s", which the page language does not spell', $local), $rules, $notes);
        continue;
      }
      if ($local !== '' && RelativeSelector::parse(substr($local, strlen(RelativeSelector::SUFFIX))) === NULL) {
        $this->styleGap($node, $catalog, sprintf('carries the style local "%s", which is outside the relative selector grammar', $local), $rules, $notes);
        continue;
      }
      // A rule's references print as wear declarations in its plain
      // block, before its own properties (the element's attributes print
      // before its style="" the same way); the element's own ('') ride
      // its tag as attributes (variantAttrs()).
      $wear = $local === '' ? [] : $this->ruleWear($entry['variants'] ?? [], $catalog, $node, $local, $rules, $notes);
      $default_printed = FALSE;
      foreach ($entry as $condition => $properties) {
        $condition = (string) $condition;
        if ($condition === 'variants' || !is_array($properties) || $properties === []) {
          continue;
        }
        if ($local === '' && $condition === 'default' && !$own_default_as_rule) {
          continue;
        }
        $default_printed = $default_printed || $condition === 'default';
        $rules[] = $this->rule($node, $local, $condition, $properties, $catalog, $condition === 'default' ? $wear : [], $notes);
      }
      if ($wear !== [] && !$default_printed) {
        $rules[] = $this->rule($node, $local, 'default', [], $catalog, $wear, $notes);
      }
    }
  }

  /**
   * A rule's wear declarations: the attribute's twin, inside the rule.
   *
   * Each reference the rule's entry carries prints as the declaration its
   * record spells: `{axis}: {value}` for a variant that is an axis
   * attribute (in reference order), then one `class: a b` for the style
   * classes (in reference order), exactly as the same variants print as
   * attributes on an element's tag. A variant with neither spelling has
   * no print: it rides as a comment (styleGap()) and the rule prints
   * without it; a fragment or an origin-bearing reference cannot sit on a
   * rule (the validator refuses compositions there), so none is skipped.
   * The parser reads the declarations back (DialectCssParser::peelWear()),
   * axis wear first, then class wear, so print(parse(x)) holds byte for
   * byte.
   *
   * @param array $references
   *   The rule entry's reference list.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param array $node
   *   The owning node, for a gap's wording.
   * @param string $local
   *   The rule's style local ('::' plus the selector), for a gap's wording.
   * @param string[] $rules
   *   Collector for the <style> lines (by reference): a gap's comment.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectPrintNote[] $notes
   *   Collector for the print's notes (by reference).
   *
   * @return string[]
   *   The declarations, `name: value`, in print order.
   */
  protected function ruleWear(array $references, DialectCatalog $catalog, array $node, string $local, array &$rules, array &$notes): array {
    $declarations = [];
    $classes = [];
    foreach ($references as $reference) {
      $variant = (string) ($reference['variant'] ?? '');
      $axis = $catalog->axisForVariant($variant);
      if ($axis !== NULL) {
        $declarations[] = sprintf('%s: %s', $axis[0], $axis[1]);
        continue;
      }
      $class = $catalog->classForVariant($variant);
      if ($class === NULL) {
        $this->styleGap($node, $catalog, sprintf('wears variant "%s" on its rule %s, which spells as neither an axis attribute nor a published style class', $variant, substr($local, 2)), $rules, $notes);
        continue;
      }
      $classes[] = $class;
    }
    if ($classes !== []) {
      $declarations[] = 'class: ' . implode(' ', $classes);
    }
    return $declarations;
  }

  /**
   * Prints one rule, nested inside its condition wrappers.
   *
   * A rule the grammar cannot spell (a condition outside the catalog, a
   * local outside the relative selector grammar) prints as its gap
   * comment instead (styleGap()).
   *
   * @param array $node
   *   The owning node; its identity is the selector's anchor.
   * @param string $local
   *   The rule's style local ('' for the element's own entry).
   * @param string $condition
   *   The entry's condition key ('default' or a compound).
   * @param array $properties
   *   The declarations.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param string[] $wear
   *   The rule's wear declarations (ruleWear()), printed before the
   *   properties; only the default condition carries any.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectPrintNote[] $notes
   *   Collector for the print's notes (by reference).
   *
   * @return string
   *   The rule, or the comment line standing in for it.
   */
  protected function rule(array $node, string $local, string $condition, array $properties, DialectCatalog $catalog, array $wear, array &$notes): string {
    $identity = (string) ($node['uuid'] ?? $node['id']);
    $wrappers = [];
    $states = '';
    if ($condition !== 'default') {
      foreach (DialectCatalog::compoundParts($condition) as $part) {
        $wrapper = $catalog->conditions()[$part] ?? NULL;
        if ($wrapper === NULL) {
          $gap = [];
          $this->styleGap($node, $catalog, sprintf('carries the condition "%s"%s, which is not in the catalog vocabulary', $part, $local === '' ? '' : ' on its rule ' . substr($local, 2)), $gap, $notes);
          return $gap[0];
        }
        if (str_starts_with($wrapper, ':')) {
          $states .= $wrapper;
        }
        else {
          $wrappers[] = $wrapper;
        }
      }
    }

    // A '::' local is a relative selector in its canonical spelling
    // (the one grammar the parser reads back): #id, a space, the chain,
    // the addressed element's states, and its generated box last of all
    // (`#id:hover::after` for the anchor's `::&::after`, `#id > pb-text
    // ::before` for a chain's box), the order CSS itself demands.
    $selector = '#' . $identity;
    $pseudo = '';
    if ($local !== '') {
      // Judged once per local by collectRules(); a local reaching here
      // parses.
      $relative = RelativeSelector::parse(substr($local, strlen(RelativeSelector::SUFFIX)));
      $selector .= $relative->compounds === [] ? '' : ' ' . RelativeSelector::printCompounds($relative->compounds);
      $pseudo = $relative->pseudo === NULL ? '' : RelativeSelector::SUFFIX . $relative->pseudo;
    }
    $selector .= $states . $pseudo;

    $declarations = $wear;
    if ($properties !== []) {
      $declarations[] = $this->declarationList($properties, $catalog);
    }
    $rule = sprintf('%s { %s; }', $selector, implode('; ', $declarations));
    $depth = count($wrappers);
    $rule = str_repeat('  ', $depth + 1) . $rule;
    for ($i = $depth - 1; $i >= 0; $i--) {
      $indent = str_repeat('  ', $i + 1);
      $rule = sprintf("%s%s {\n%s\n%s}", $indent, $wrappers[$i], $rule, $indent);
    }
    return $rule;
  }

  /**
   * Prints a property map as a declaration list.
   *
   * Every value prints through the grammar's canonical rewrite, so media
   * tokens read back single-quoted whatever the stored quoting: the print
   * is the teaching surface, and the single-quoted spelling is the only
   * one writable inside a style="" attribute (the 0.4 canon).
   */
  protected function declarationList(array $properties, DialectCatalog $catalog): string {
    $declarations = [];
    foreach ($properties as $property => $value) {
      $declarations[] = sprintf('%s: %s', $property, $catalog->canonical((string) $value, MaterialRecord::SLOT_STYLE));
    }
    return implode('; ', $declarations);
  }

  /**
   * Serializes an attribute map, escaped, with a leading space.
   */
  protected function attrString(array $attrs): string {
    $out = '';
    foreach ($attrs as $name => $value) {
      $out .= sprintf(' %s="%s"', $name, self::escapeAttr((string) $value));
    }
    return $out;
  }

  /**
   * Escapes an attribute value to the HTML5 minimum: & and ".
   *
   * Only the two characters that would break a double-quoted attribute
   * are escaped, so url="<nolink>", url="<front>" and single-quoted
   * url('media:<uuid>') tokens read back exactly as written
   * (htmlspecialchars' default would print &lt;nolink&gt;, a spelling
   * the write grammar never takes). Element text keeps ENT_NOQUOTES.
   */
  protected static function escapeAttr(string $value): string {
    return strtr($value, ['&' => '&amp;', '"' => '&quot;']);
  }

  /**
   * Whether a field value is a form-only stub (identity, no value).
   */
  protected function isStub(mixed $value): bool {
    return is_array($value) && !empty($value['_form_only']);
  }

}
