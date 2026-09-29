<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\FieldHandler\LinkFieldHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;

/**
 * Prints the lean node tree as a canonical dialect document.
 *
 * The print IS the read surface: what a model reads is exactly what it may
 * write back, so the printer emits only the authored grammar (spec v0.2)
 * and print(parse(x)) is byte-identical for canonical documents. Canonical
 * form: two-space indent per depth; attribute order id, axis attributes in
 * declared order, class, field attributes in catalog order, style last;
 * one line per block; rich bodies verbatim; the <style> block printed
 * last, rules grouped per node in document order.
 *
 * Server residue never prints: info, form-only stubs, block labels,
 * label_rendered, layout_settings. What the model cannot write, it does
 * not read (the spec section 3 ruling).
 *
 * Provenance prints: a placement root carries stencil="id", the
 * stencil it was placed from, right after its identity, so the model can
 * see what a band IS and keep that fact when it rewrites the element (the
 * parser takes the attribute back as an identity fact, never as a request
 * to re-instantiate). Only a stamp naming a rostered (published) stencil
 * prints: a dead stamp is residue the model could not write back.
 *
 * A container's name prints: a section carrying a label (the word the
 * editor's tree, chips and panels call it by, the element-naming spine's
 * one renamable slot) prints name="..." after its provenance, and the
 * parser takes it back onto the same slot (DialectWalk::NAME_ATTR), so
 * the model reads "Welcome", refers to the band by it and renames it
 * through set {name}. A block's label stays residue: its name is derived
 * from its content and is not renamable.
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
 * class otherwise. Canonical attribute order: id, data-alias, stencil,
 * name (containers), axis attributes, class, field attributes, style.
 *
 * The printer is loud about spec v0.2's known gaps (DialectPrintException)
 * rather than printing something a parse could not round-trip: region
 * nodes (multi-region layouts), variant references outside the axis/class
 * rosters or carrying fragments, structured link values with title or
 * options, field-scoped style locals (':field_name'), and structured
 * values of fields with no attribute contract. A '::' local prints as
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
   */
  public function print(array $tree, DialectCatalog $catalog, array $aliases = []): string {
    $lines = [];
    $rules = [];
    foreach ($tree as $node) {
      $this->printNode($node, $catalog, 0, TRUE, $lines, $rules, $aliases);
    }
    if ($rules !== []) {
      $lines[] = '<style>';
      foreach ($rules as $rule) {
        $lines[] = $rule;
      }
      $lines[] = '</style>';
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * Prints a subtree as an op-payload fragment (no <style> block).
   *
   * Fragments carry conditions and parts on nothing: only style="" rides,
   * so a node with non-default style entries is refused loudly.
   */
  public function printFragment(array $nodes, DialectCatalog $catalog, array $aliases = []): string {
    $lines = [];
    $rules = [];
    foreach ($nodes as $node) {
      $this->printNode($node, $catalog, 0, TRUE, $lines, $rules, $aliases);
    }
    if ($rules !== []) {
      throw new DialectPrintException('a fragment cannot carry <style> rules; conditions and parts ride the style op');
    }
    return implode("\n", $lines) . "\n";
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
   * `_element` type and name, which the lean tree carries when built over
   * daedalus_ui's annotated sections; a bare tree prints no words), and
   * its own wear (`/* wears tone="loud" class="button" *\/`, the tag's
   * attribute spelling, variantAttrs()), because the dialect has no rule
   * spelling for an element's own wear: the parser refuses it as
   * WEAR_ON_ELEMENT, so it rides as a note, never as a declaration. A
   * rule's wear prints inside its rule as print() does.
   *
   * Region nodes are transparent (their children print); an element
   * with neither styles nor wear prints nothing.
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
   * @return string
   *   The <style> content, newline-terminated; '' when nothing prints.
   */
  public function printStyles(array $nodes, DialectCatalog $catalog, array $aliases = []): string {
    $lines = [];
    foreach ($nodes as $node) {
      $this->collectStyleLines($node, $catalog, $lines);
    }
    return $lines === [] ? '' : implode("\n", $lines) . "\n";
  }

  /**
   * Collects one node's CSS print lines, then its children's.
   */
  protected function collectStyleLines(array $node, DialectCatalog $catalog, array &$lines): void {
    $kind = $node['kind'] ?? NULL;
    if ($kind === 'region') {
      foreach ($node['children'] ?? [] as $child) {
        $this->collectStyleLines($child, $catalog, $lines);
      }
      return;
    }
    if ($kind !== 'container' && $kind !== 'block') {
      throw new DialectPrintException(sprintf('unknown node kind "%s"; the CSS print covers container and block nodes', (string) $kind));
    }
    $rules = [];
    $this->collectRules($node, $catalog, $kind === 'container', $rules, TRUE);
    $wear = $this->variantAttrs($node, $catalog, $this->axesOf($node, $catalog), $this->tagOf($node, $catalog));
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
      $this->collectStyleLines($child, $catalog, $lines);
    }
  }

  /**
   * The element's words from the naming spine's annotation, if carried.
   *
   * `_element.type` and `_element.name` are daedalus_ui's annotator's
   * facts (the type word "Section", the renamable or derived name), which
   * ride the lean tree verbatim when it is built over annotated sections.
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
    if (isset($node['plugin_id'])) {
      return $catalog->axesFor(FALSE, NULL, (string) $node['plugin_id']);
    }
    return $catalog->axesFor(FALSE, (string) ($node['block_type'] ?? ''), NULL);
  }

  /**
   * The element's dialect tag, for the wear print's exception.
   */
  protected function tagOf(array $node, DialectCatalog $catalog): string {
    if (($node['kind'] ?? '') === 'container') {
      return 'pb-container';
    }
    $block_type = (string) ($node['block_type'] ?? '');
    if (isset($node['plugin_id']) || $block_type === '' || $catalog->blockType($block_type) === NULL) {
      return 'pb-block';
    }
    return DialectCatalog::tagForBlockType($block_type);
  }

  /**
   * Prints one node and collects its <style> rules.
   */
  protected function printNode(array $node, DialectCatalog $catalog, int $depth, bool $top_level, array &$lines, array &$rules, array $aliases = []): void {
    $indent = str_repeat('  ', $depth);
    $kind = $node['kind'] ?? NULL;
    switch ($kind) {
      case 'container':
        $attrs = $this->identityAttr($node, $aliases) + $this->provenanceAttr($node, $catalog) + $this->nameAttr($node) + $this->variantAttrs($node, $catalog, $catalog->axesFor(TRUE, NULL, NULL), 'pb-container') + $this->styleAttr($node, $catalog);
        $lines[] = sprintf('%s<pb-container%s>', $indent, $this->attrString($attrs));
        $this->collectRules($node, $catalog, TRUE, $rules);
        foreach ($node['children'] ?? [] as $child) {
          $this->printNode($child, $catalog, $depth + 1, FALSE, $lines, $rules, $aliases);
        }
        $lines[] = $indent . '</pb-container>';
        return;

      case 'block':
        $this->printBlock($node, $catalog, $indent, $lines, $rules, $aliases);
        return;

      case 'stencil':
        $attrs = $this->identityAttr($node, $aliases) + ['name' => (string) ($node['name'] ?? '')];
        $lines[] = sprintf('%s<pb-stencil%s />', $indent, $this->attrString($attrs));
        return;

      case 'region':
        throw new DialectPrintException('multi-region layouts have no dialect print (spec v0.2 covers container layouts); region nodes cannot be printed');

      default:
        throw new DialectPrintException(sprintf('unknown node kind "%s"; the dialect prints container, block, and stencil nodes', (string) $kind));
    }
  }

  /**
   * Prints one block node.
   */
  protected function printBlock(array $node, DialectCatalog $catalog, string $indent, array &$lines, array &$rules, array $aliases = []): void {
    $this->collectRules($node, $catalog, FALSE, $rules);

    if (isset($node['plugin_id'])) {
      $plugin_id = (string) $node['plugin_id'];
      $attrs = $this->identityAttr($node, $aliases)
        + $this->provenanceAttr($node, $catalog)
        + ['plugin' => $plugin_id]
        + $this->variantAttrs($node, $catalog, $catalog->axesFor(FALSE, NULL, $plugin_id), 'pb-block')
        + $this->styleAttr($node, $catalog);
      $lines[] = sprintf('%s<pb-block%s />', $indent, $this->attrString($attrs));
      return;
    }

    $block_type = (string) ($node['block_type'] ?? '');
    $spec = $catalog->blockType($block_type);
    if ($spec === NULL) {
      $this->printGenericBlock($node, $block_type, $catalog, $indent, $lines, $aliases);
      return;
    }

    $tag = DialectCatalog::tagForBlockType($block_type);
    $fields = $node['block_fields'] ?? [];
    $axes = $catalog->axesFor(FALSE, $block_type, NULL);
    $attrs = $this->identityAttr($node, $aliases)
      + $this->provenanceAttr($node, $catalog)
      + $this->variantAttrs($node, $catalog, $axes, $tag)
      + $this->fieldAttrs($fields, $spec, $tag)
      + $this->styleAttr($node, $catalog);
    // Canonical order: id, data-alias, stencil, axis attributes in catalog
    // order, class, field attributes in catalog order, style last.
    $ordered = [];
    foreach (array_merge(['id', 'data-alias', 'stencil'], array_keys($axes), ['class'], array_keys($spec['attributes'] ?? []), ['style']) as $name) {
      if (array_key_exists($name, $attrs)) {
        $ordered[$name] = $attrs[$name];
      }
    }

    $body = $spec['body'] ?? NULL;
    $body_value = $body === NULL ? NULL : ($fields[$body['field']] ?? NULL);
    if ($this->isStub($body_value)) {
      $body_value = NULL;
    }
    $this->assertNoLeftovers($fields, $spec, $tag);

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
  protected function printGenericBlock(array $node, string $block_type, DialectCatalog $catalog, string $indent, array &$lines, array $aliases = []): void {
    $attrs = $this->identityAttr($node, $aliases)
      + $this->provenanceAttr($node, $catalog)
      + ['type' => $block_type]
      + $this->variantAttrs($node, $catalog, $catalog->axesFor(FALSE, $block_type, NULL), 'pb-block')
      + $this->styleAttr($node, $catalog);
    $fields = [];
    foreach ($node['block_fields'] ?? [] as $name => $value) {
      if ($name === 'info' || $this->isStub($value)) {
        continue;
      }
      if (!is_scalar($value)) {
        throw new DialectPrintException(sprintf('field %s of undeclared block type %s holds a structured value; the generic <pb-field> form prints scalars only (per-handler contracts are the follow-up)', $name, $block_type));
      }
      $fields[$name] = (string) $value;
    }
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
   * The stencil attribute from the node's placement provenance, if any.
   *
   * The node's `stencil` key is the serializer's read-only surface of the
   * stamp instantiate_stencil wrote at the root's birth (a section's
   * third-party settings, a block's additional data). It prints only while
   * the stamp names a rostered stencil: the print teaches the write, and
   * the parser refuses a name no published stencil spells, so a stamp
   * whose stencil was deleted, unpublished or never existed is residue.
   */
  protected function provenanceAttr(array $node, DialectCatalog $catalog): array {
    $stencil = $node['stencil'] ?? NULL;
    if (!is_string($stencil) || $stencil === '') {
      return [];
    }
    if ($catalog->roster()->get(MaterialKind::Stencil, $stencil) === NULL) {
      return [];
    }
    return ['stencil' => $stencil];
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
   * spelling as neither is loud: nothing the model could write back.
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
   *   The element's tag, for the exception.
   */
  protected function variantAttrs(array $node, DialectCatalog $catalog, array $axes, string $tag): array {
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
        throw new DialectPrintException(sprintf('variant "%s" on <%s> spells as neither an axis attribute of the element nor a published style class, so it has no print', $variant, $tag));
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
   */
  protected function fieldAttrs(array $fields, array $spec, string $tag): array {
    $attrs = [];
    foreach ($spec['attributes'] ?? [] as $name => $attr_spec) {
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
        if ($meaningful !== []) {
          throw new DialectPrintException(sprintf('link field %s carries %s; the dialect prints bare uris only (structured link print is a follow-up)', $attr_spec['field'], implode(', ', $meaningful)));
        }
        if (isset($value['uri'])) {
          $attrs[$name] = LinkFieldHandler::getUriAsAuthorableString((string) $value['uri']);
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
        throw new DialectPrintException(sprintf('field %s holds a structured value the %s attribute contract cannot print', $attr_spec['field'], $name));
      }
      $attrs[$name] = (string) $value;
    }
    return $attrs;
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
   *   Collector for the printed rules (by reference).
   * @param bool $own_default_as_rule
   *   Whether the element's own default-condition entry prints as a
   *   `#id { }` rule too (the CSS print, which has no tag for a style="")
   *   instead of riding the tag alone (the document print).
   */
  protected function collectRules(array $node, DialectCatalog $catalog, bool $is_container, array &$rules, bool $own_default_as_rule = FALSE): void {
    $identity = $node['uuid'] ?? $node['id'] ?? NULL;
    $styles = $node['styles'] ?? [];
    if ($styles === []) {
      return;
    }
    if ($identity === NULL) {
      foreach ($styles as $local => $entry) {
        if ($local !== '' || array_diff(array_keys((array) $entry), ['variants', 'default']) !== []) {
          throw new DialectPrintException('a node without an id cannot print conditions or parts; only style="" and variant attributes ride the element');
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
        throw new DialectPrintException(sprintf('style local "%s" on #%s is field-scoped; field-scoped styles have no dialect print yet', $local, $identity));
      }
      // A rule's references print as wear declarations in its plain
      // block, before its own properties (the element's attributes print
      // before its style="" the same way); the element's own ('') ride
      // its tag as attributes (variantAttrs()).
      $wear = $local === '' ? [] : $this->ruleWear($entry['variants'] ?? [], $catalog, $identity, $local);
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
        $rules[] = $this->rule($identity, $local, $condition, $properties, $catalog, $condition === 'default' ? $wear : []);
      }
      if ($wear !== [] && !$default_printed) {
        $rules[] = $this->rule($identity, $local, 'default', [], $catalog, $wear);
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
   * no print and is refused loudly; a fragment or an origin-bearing
   * reference cannot sit on a rule (the validator refuses compositions
   * there), so none is skipped. The parser reads the declarations back
   * (DialectCssParser::peelWear()), axis wear first, then class wear, so
   * print(parse(x)) holds byte for byte.
   *
   * @param array $references
   *   The rule entry's reference list.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param string $identity
   *   The owner's id, for the exception.
   * @param string $local
   *   The rule's style local ('::' plus the selector), for the exception.
   *
   * @return string[]
   *   The declarations, `name: value`, in print order.
   */
  protected function ruleWear(array $references, DialectCatalog $catalog, string $identity, string $local): array {
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
        throw new DialectPrintException(sprintf('variant "%s" on the rule #%s %s spells as neither an axis attribute nor a published style class, so it has no print', $variant, $identity, substr($local, 2)));
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
   * @param string[] $wear
   *   The rule's wear declarations (ruleWear()), printed before the
   *   properties; only the default condition carries any.
   */
  protected function rule(string $identity, string $local, string $condition, array $properties, DialectCatalog $catalog, array $wear = []): string {
    $wrappers = [];
    $states = '';
    if ($condition !== 'default') {
      foreach (DialectCatalog::compoundParts($condition) as $part) {
        $wrapper = $catalog->conditions()[$part] ?? NULL;
        if ($wrapper === NULL) {
          throw new DialectPrintException(sprintf('condition "%s" on #%s is not in the catalog vocabulary', $part, $identity));
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
      $relative = RelativeSelector::parse(substr($local, strlen(RelativeSelector::SUFFIX)));
      if ($relative === NULL) {
        throw new DialectPrintException(sprintf('style local "%s" on #%s is outside the relative selector grammar, so it has no dialect print', $local, $identity));
      }
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

  /**
   * Guards against silently dropping fields outside the print contract.
   */
  protected function assertNoLeftovers(array $fields, array $spec, string $tag): void {
    $printable = [];
    foreach ($spec['attributes'] ?? [] as $attr_spec) {
      $printable[$attr_spec['field']] = TRUE;
    }
    if (isset($spec['body']['field'])) {
      $printable[$spec['body']['field']] = TRUE;
    }
    foreach ($fields as $name => $value) {
      if ($name === 'info' || isset($printable[$name]) || $this->isStub($value)) {
        continue;
      }
      throw new DialectPrintException(sprintf('field %s on <%s> has no attribute or body contract in the catalog; refusing a lossy print', $name, $tag));
    }
  }

}
