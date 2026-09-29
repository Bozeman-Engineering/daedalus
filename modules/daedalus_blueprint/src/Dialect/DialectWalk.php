<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\ValueKind;
use Drupal\daedalus_blueprint\Materials\ValueReference;

/**
 * One walk of a raw parse tree into lean nodes and structural errors.
 *
 * Stateful per parse (collected ids, errors); created by DialectParser for
 * each document or fragment. The walk enforces the structural grammar and
 * builds nodes; the CSS surface is DialectCssParser's. Every material a
 * document names (a class, an axis attribute value, a stencil name)
 * resolves through the catalog's value grammar, so an unpublished
 * spelling is refused here with the roster's did-you-mean, never deep in
 * the translated batch. Variant axes are attributes on every tag (the
 * container's width/layout, a block type's own axes, the block kind's on
 * every block form), read by one rule (axisAttr()) against the element's
 * axis view.
 */
final class DialectWalk {

  /**
   * The attributes every placeable element takes (globalAttr()).
   *
   * Id is identity, class and style are wear and look, stencil is
   * placement provenance (the stencil the element was placed from, an
   * identity fact the print carries and the write keeps). <pb-stencil>
   * takes id and name only: a reference is not a placement.
   */
  public const GLOBAL_ATTRS = ['id', 'class', 'style', 'stencil'];

  /**
   * The container's own attribute: its name, the section's label.
   *
   * The element-naming spine's one writable slot on the dialect door: the
   * word the editor's tree, chips and panels call the section by
   * (layout_settings.label, the same slot update_element {label} takes).
   * Container-only: a block's name is derived from its content and is
   * not renamable, so <pb-text name="..."> is ILLEGAL_ATTR. On
   * <pb-stencil> the same spelling names the stencil to place, a
   * different attribute (stencilReference()).
   */
  public const NAME_ATTR = 'name';

  /**
   * The structural errors found while walking.
   *
   * @var \Drupal\daedalus_blueprint\Dialect\DialectError[]
   */
  public array $errors = [];

  /**
   * Collected element ids, for duplicate detection.
   *
   * Id => ['container' => bool, 'block_type' => ?string]. The selector
   * grammar judges against DialectCssParser::knownIds() over the parsed
   * tree instead (the one derivation both doors share).
   */
  public array $ids = [];

  /**
   * The CSS parser, for style attributes.
   */
  private readonly DialectCssParser $css;

  public function __construct(
    private readonly DialectCatalog $catalog,
  ) {
    $this->css = new DialectCssParser($catalog);
  }

  /**
   * Walks one element into a lean node, or NULL when it is not one.
   *
   * @param \Drupal\daedalus_blueprint\Dialect\RawElement $el
   *   The raw element.
   * @param int $depth
   *   Zero at the page's top level; containment and axis legality depend on it.
   */
  public function element(RawElement $el, int $depth): ?array {
    $tag = $el->tag;
    if ($tag === 'style') {
      $this->errors[] = new DialectError('ILLEGAL_CONTAINMENT', '<style> belongs at the top level of the document, after the elements', $el->line);
      return NULL;
    }
    if ($tag === DialectParser::FILE_TAG) {
      $this->errors[] = new DialectError('ILLEGAL_CONTAINMENT', sprintf('<%s> belongs at the top level of the document, beside <style>; the element that places the file refers to it by name (file="name")', DialectParser::FILE_TAG), $el->line);
      return NULL;
    }
    if (($corrective = DialectParser::sugarCorrective($tag, $this->catalog)) !== NULL) {
      $this->errors[] = new DialectError('SUGAR_TAG', sprintf('<%s> is not a dialect element; %s', $tag, $corrective), $el->line);
      return NULL;
    }
    if (($pb_form = DialectParser::unprefixedTarget($tag)) !== NULL) {
      $this->errors[] = new DialectError('UNPREFIXED_TAG', sprintf('<%s> is missing the dialect prefix; write <%s>', $tag, $pb_form), $el->line);
      return NULL;
    }
    if ($tag === 'pb-container') {
      return $this->container($el, $depth);
    }
    if ($tag === 'pb-block') {
      return $this->pluginOrGenericBlock($el, $depth);
    }
    if ($tag === 'pb-stencil') {
      return $this->stencilReference($el, $depth);
    }
    if ($tag === 'pb-field') {
      $this->errors[] = new DialectError('ILLEGAL_CONTAINMENT', '<pb-field> is legal only inside <pb-block type=...> or a structured field context', $el->line);
      return NULL;
    }
    $block_type = DialectCatalog::blockTypeForTag($tag);
    if ($block_type === NULL || $this->catalog->blockType($block_type) === NULL) {
      $tags = array_map([DialectCatalog::class, 'tagForBlockType'], array_keys($this->catalog->blockTypes()));
      $this->errors[] = new DialectError('UNKNOWN_TAG', sprintf('<%s> is not in the catalog', $tag), $el->line, [
        'tag' => $tag,
        'elements' => array_merge(['pb-container'], $tags, ['pb-block', 'pb-stencil']),
      ], sprintf('Elements: <pb-container>, %s, <pb-block>, <pb-stencil>.', implode(', ', array_map(static fn (string $t): string => '<' . $t . '>', $tags))));
      return NULL;
    }
    return $this->declaredBlock($el, $block_type, $depth);
  }

  /**
   * Builds a container node.
   */
  private function container(RawElement $el, int $depth): array {
    $node = ['kind' => 'container', 'layout_id' => $this->catalog->containerLayoutId()];
    $worn = [];
    $axis_values = [];
    $default = [];

    $axes = $this->catalog->axesFor(TRUE, NULL, NULL);
    $roster = $this->catalog->classRosterFor(TRUE, NULL, NULL);
    $legal = [...self::GLOBAL_ATTRS, self::NAME_ATTR, ...array_keys($axes)];
    $cascade = $this->earlyClosedAttr($el, $legal);
    foreach ($el->attributes as $name => $value) {
      if ($this->cascadeGuard($el, (string) $name, $cascade)) {
        continue;
      }
      $value = $value === NULL ? '' : (string) $value;
      if ($this->globalAttr($el, $name, $value, $node, $default, $worn, $roster)) {
        continue;
      }
      if ($name === self::NAME_ATTR) {
        // The node carries the name under the serializer's key, so a
        // parsed tree, a stored tree (a stencil's element) and the print
        // share one shape; an empty name is no name, as the section's
        // label slot holds none.
        if ($value !== '') {
          $node['label'] = $value;
        }
        continue;
      }
      if (isset($axes[$name])) {
        $this->axisAttr($el, $name, $value, $axes[$name], $depth, $axis_values);
        continue;
      }
      $this->errors[] = self::illegalAttr($name, 'pb-container', $legal, $el->line);
    }

    $children = [];
    foreach ($el->children as $child) {
      if (!$child instanceof RawElement) {
        if (trim($child['#text']) !== '') {
          $this->errors[] = new DialectError('STRAY_TEXT', sprintf('bare text "%s" inside <pb-container>: wrap copy in <pb-text> or <pb-formatted-text>', DialectParser::excerpt($child['#text'])), $child['#line']);
        }
        continue;
      }
      $child_node = $this->element($child, $depth + 1);
      if ($child_node !== NULL) {
        $children[] = $child_node;
      }
    }
    $node['children'] = $children;

    $this->attachStyles($node, self::references($axes, $axis_values, $worn), $default);
    return $node;
  }

  /**
   * Builds a block node for a declared block type.
   */
  private function declaredBlock(RawElement $el, string $block_type, int $depth): array {
    $spec = $this->catalog->blockType($block_type);
    $tag = DialectCatalog::tagForBlockType($block_type);
    $node = ['kind' => 'block'];
    $fields = [];
    $worn = [];
    $axis_values = [];
    $default = [];
    $axes = $this->catalog->axesFor(FALSE, $block_type, NULL);
    $roster = $this->catalog->classRosterFor(FALSE, $block_type, NULL);
    $legal = array_merge(self::GLOBAL_ATTRS, array_keys($axes), array_keys($spec['attributes'] ?? []));
    $cascade = $this->earlyClosedAttr($el, $legal);

    foreach ($el->attributes as $name => $value) {
      if ($this->cascadeGuard($el, (string) $name, $cascade)) {
        continue;
      }
      $value = $value === NULL ? '' : (string) $value;
      if ($tag === 'pb-image' && $name === 'src') {
        $this->errors[] = new DialectError('ILLEGAL_SRC', 'src is not writable on <pb-image>: reference existing media with media="id or label", mint one with generate="prompt" + alt, or place a file this conversation holds with file="name" + alt', $el->line);
        continue;
      }
      if ($this->globalAttr($el, $name, $value, $node, $default, $worn, $roster)) {
        continue;
      }
      if (isset($axes[$name])) {
        $this->axisAttr($el, $name, $value, $axes[$name], $depth, $axis_values);
        continue;
      }
      $attr_spec = $spec['attributes'][$name] ?? NULL;
      if ($attr_spec === NULL) {
        $this->errors[] = self::illegalAttr($name, $tag, $legal, $el->line);
        continue;
      }
      if (isset($attr_spec['values']) && !in_array($value, $attr_spec['values'], TRUE)) {
        $this->errors[] = new DialectError('BAD_ATTR_VALUE', sprintf('%s="%s" on <%s> is not an allowed value', $name, $value, $tag), $el->line, [
          'attribute' => $name,
          'value' => $value,
          'allowed' => $attr_spec['values'],
        ], sprintf('Allowed values: %s.', implode(', ', $attr_spec['values'])));
        continue;
      }
      $this->applyFieldAttr($fields, $attr_spec, $value);
    }

    $body = $spec['body'] ?? NULL;
    if ($body !== NULL && $body['kind'] === 'plain') {
      foreach ($el->elementChildren() as $child) {
        $this->errors[] = new DialectError('ILLEGAL_CONTAINMENT', sprintf('<%s> takes text only; <%s> found. A link is url="..." on <pb-text>, not a child element', $tag, $child->tag), $child->line);
      }
      $text = trim(preg_replace('/\s+/', ' ', $el->text()));
      if ($text !== '') {
        $fields[$body['field']] = $text;
      }
    }
    elseif ($body !== NULL && $body['kind'] === 'rich') {
      // The rich body is opaque to the dialect: preserved verbatim here,
      // governed by the text format filter downstream, never re-validated
      // against this grammar. A format="" attribute (mapped by the
      // catalog onto the same field) already sits in $fields. Its
      // instance identities are NOT opaque: the body rides the ids entry
      // so a document's <style> block can existence-check `#id .s-x`
      // against the authored content, and an authored identity claimed
      // by two mintable elements is refused loudly: the identity minter
      // would silently re-mint the later carrier, orphaning every style
      // this same batch wrote against the class.
      $raw = $el->text();
      if ($raw !== '') {
        $existing = $fields[$body['field']] ?? NULL;
        $fields[$body['field']] = is_array($existing) ? ['value' => $raw] + $existing : $raw;
        $identity = $node['uuid'] ?? $node['id'] ?? NULL;
        if ($identity !== NULL && isset($this->ids[$identity])) {
          $this->ids[$identity]['body'] = $raw;
        }
        foreach (DialectTextInstances::collisions($raw, $this->catalog) as $class) {
          $this->errors[] = new DialectError('IDENTITY_COLLISION', sprintf('class "%s" identifies more than one element in <%s>\'s body; an identity names ONE element. Give each element its own class from the identity namespace, or drop the duplicate and let one be minted', $class, $tag), $el->line);
        }
      }
    }
    else {
      foreach ($el->children as $child) {
        if ($child instanceof RawElement) {
          $this->errors[] = new DialectError('ILLEGAL_CONTAINMENT', sprintf('<%s> takes no children; <%s> found', $tag, $child->tag), $child->line);
        }
        elseif (trim($child['#text']) !== '') {
          $this->errors[] = new DialectError('STRAY_TEXT', sprintf('bare text "%s" in <%s>: wrap copy in <pb-text> or <pb-formatted-text>', DialectParser::excerpt($child['#text']), $tag), $child['#line']);
        }
      }
    }

    $node['block_type'] = $block_type;
    if ($fields !== []) {
      $node['block_fields'] = $fields;
    }
    $this->attachStyles($node, self::references($axes, $axis_values, $worn), $default);
    return $node;
  }

  /**
   * Builds a plugin-block or undeclared-type (generic form) block node.
   */
  private function pluginOrGenericBlock(RawElement $el, int $depth): array {
    $node = ['kind' => 'block'];
    $worn = [];
    $axis_values = [];
    $default = [];
    $plugin = NULL;
    $type = NULL;
    // The class roster and the axes depend on what the block is, and class
    // or an axis may precede plugin/type in source order, so the identity
    // attributes are read ahead of the walk.
    $axes = $this->catalog->axesFor(FALSE, $el->attr('type'), $el->attr('plugin'));
    $roster = $this->catalog->classRosterFor(FALSE, $el->attr('type'), $el->attr('plugin'));
    $legal = [...self::GLOBAL_ATTRS, 'plugin', 'type', ...array_keys($axes)];
    $cascade = $this->earlyClosedAttr($el, $legal);

    foreach ($el->attributes as $name => $value) {
      if ($this->cascadeGuard($el, (string) $name, $cascade)) {
        continue;
      }
      $value = $value === NULL ? '' : (string) $value;
      if ($this->globalAttr($el, $name, $value, $node, $default, $worn, $roster)) {
        continue;
      }
      if ($name === 'plugin') {
        $plugin = $value;
        continue;
      }
      if ($name === 'type') {
        $type = $value;
        continue;
      }
      if (isset($axes[$name])) {
        $this->axisAttr($el, $name, $value, $axes[$name], $depth, $axis_values);
        continue;
      }
      $this->errors[] = self::illegalAttr($name, 'pb-block', $legal, $el->line);
    }
    if ($plugin === NULL && $type === NULL) {
      $this->errors[] = new DialectError('ILLEGAL_ATTR', '<pb-block> needs plugin="..." (plugin block) or type="..." (undeclared block type)', $el->line);
    }

    $fields = [];
    foreach ($el->children as $child) {
      if (!$child instanceof RawElement) {
        if (trim($child['#text']) !== '') {
          $this->errors[] = new DialectError('STRAY_TEXT', sprintf('bare text "%s" in <pb-block>: field values ride <pb-field name="...">', DialectParser::excerpt($child['#text'])), $child['#line']);
        }
        continue;
      }
      if ($child->tag !== 'pb-field') {
        $this->errors[] = new DialectError('ILLEGAL_CONTAINMENT', sprintf('<pb-block> takes <pb-field> children only; <%s> found', $child->tag), $child->line);
        continue;
      }
      $field_name = $child->attr('name');
      if ($field_name === NULL || $field_name === '') {
        $this->errors[] = new DialectError('ILLEGAL_ATTR', '<pb-field> needs name="..."', $child->line);
        continue;
      }
      $fields[$field_name] = $child->text();
    }

    if ($plugin !== NULL) {
      $node['plugin_id'] = $plugin;
    }
    else {
      $node['block_type'] = $type;
    }
    if ($fields !== []) {
      $node['block_fields'] = $fields;
    }
    $this->attachStyles($node, self::references($axes, $axis_values, $worn), $default);
    return $node;
  }

  /**
   * Reads one axis attribute: the value names a variant of that axis.
   *
   * The same read on every tag (the container's axes and a block's): the
   * value resolves through the grammar's attribute slot for the scope the
   * axis view names (never site-wide: a block's fade_in and a container's
   * are two variants), a miss answers with the axis's allowed values and
   * the roster's did-you-mean, and a root-only variant is refused below
   * the top level.
   *
   * @param \Drupal\daedalus_blueprint\Dialect\RawElement $el
   *   The raw element carrying the attribute.
   * @param string $name
   *   The attribute name (the axis).
   * @param string $value
   *   The attribute value as written.
   * @param array $axis
   *   The axis view entry (DialectCatalog::axesFor()).
   * @param int $depth
   *   The element's depth; zero at the page's top level.
   * @param array $axis_values
   *   Collector: attr => variant id (by reference).
   */
  private function axisAttr(RawElement $el, string $name, string $value, array $axis, int $depth, array &$axis_values): void {
    $slot = MaterialRecord::attrSlot($axis['scope'], $name);
    $reference = $this->catalog->resolve($value, $slot);
    if ($reference->kind !== ValueKind::Variant) {
      $allowed = array_keys($axis['values']);
      $this->errors[] = DialectCssParser::unknownReference('BAD_ATTR_VALUE', sprintf('%s="%s" on <%s> names no published variant', $name, $value, $el->tag), $reference, $slot, $this->catalog, sprintf('Allowed values: %s.', implode(', ', $allowed)), $el->line, [
        'attribute' => $name,
        'allowed' => $allowed,
      ]);
      return;
    }
    if ($depth > 0 && $reference->record->rootOnly()) {
      $this->errors[] = new DialectError('AXIS_NOT_TOP_LEVEL', sprintf('%s applies to top-level containers only, never nested inside another container', $name), $el->line, [
        'attribute' => $name,
        'variant' => $reference->id,
      ], 'Set it on the outermost container.');
      return;
    }
    $axis_values[$name] = $reference->id;
  }

  /**
   * Builds a stencil reference node.
   *
   * The name resolves through the stencil slot: a placement names a
   * published stencil or is refused here, with the roster's candidates,
   * instead of failing as STENCIL_NOT_FOUND deep in the translated batch.
   * A page band (a stencil whose root wears a root-only variant, the
   * roster's one predicate) is refused below the top level in
   * AXIS_NOT_TOP_LEVEL's words, naming the wearing, instead of failing
   * as VARIANT_ROOT_ONLY on the root's own apply_variant deep in the
   * expansion.
   */
  private function stencilReference(RawElement $el, int $depth): array {
    $node = ['kind' => 'stencil'];
    $worn = [];
    $default = [];
    $named = FALSE;
    $cascade = $this->earlyClosedAttr($el, ['id', 'class', 'style', 'name']);
    foreach ($el->attributes as $name => $value) {
      if ($this->cascadeGuard($el, (string) $name, $cascade)) {
        continue;
      }
      $value = $value === NULL ? '' : (string) $value;
      if ($name === 'name') {
        $named = TRUE;
        $reference = $this->catalog->resolve($value, MaterialRecord::SLOT_STENCIL);
        if ($reference->kind !== ValueKind::Stencil) {
          $stencils = $this->catalog->roster()->stencils();
          $this->errors[] = DialectCssParser::unknownReference('UNKNOWN_STENCIL', sprintf('"%s" is not a published stencil', $value), $reference, MaterialRecord::SLOT_STENCIL, $this->catalog, $stencils === [] ? 'No stencil is published on this site: build the elements directly, or save some as a stencil first.' : sprintf('Published stencils: %s.', MaterialsRoster::describe(array_values($stencils))), $el->line, ['available' => array_keys($stencils)]);
          continue;
        }
        $roster = $this->catalog->roster();
        if ($depth > 0 && !$roster->applicable($reference->record, Address::kind($reference->record->rootKind === MaterialRecord::ROOT_BLOCK ? Address::KIND_BLOCK : Address::KIND_CONTAINER), top_level: FALSE)) {
          $wears = $roster->rootOnlyWears($reference->record);
          $wearing = reset($wears);
          $described = MaterialsRoster::describe([$wearing], FALSE);
          $this->errors[] = new DialectError('STENCIL_NOT_TOP_LEVEL', sprintf('"%s" is a page band (its root wears %s): it applies to top-level containers only, never nested inside another container', $reference->id, $described), $el->line, [
            'stencil' => $reference->id,
            'variant' => $wearing->id,
            'axis' => $wearing->group,
          ], 'Place it at the top level of the document, outside every container.');
          continue;
        }
        $node['name'] = $reference->id;
        continue;
      }
      if ($this->globalAttr($el, $name, $value, $node, $default, $worn, [])) {
        continue;
      }
      $this->errors[] = self::illegalAttr($name, 'pb-stencil', ['id', 'name'], $el->line);
    }
    if (!$named) {
      $this->errors[] = new DialectError('ILLEGAL_ATTR', '<pb-stencil> needs name="..."', $el->line, [], 'Name a published stencil: <pb-stencil name="stencil_id" />.');
    }
    $this->attachStyles($node, [], $default);
    return $node;
  }

  /**
   * The ILLEGAL_ATTR error, the element's legal attributes in context.
   *
   * @param string $name
   *   The attribute name as written.
   * @param string $tag
   *   The element's tag.
   * @param string[] $legal
   *   The attribute names the element accepts.
   * @param int|null $line
   *   The element's source line, or NULL when unknown.
   */
  private static function illegalAttr(string $name, string $tag, array $legal, ?int $line): DialectError {
    sort($legal);
    return new DialectError('ILLEGAL_ATTR', sprintf('%s is not an attribute of <%s>', $name, $tag), $line, [
      'attribute' => $name,
      'legal' => $legal,
    ], sprintf('Legal attributes of <%s>: %s.', $tag, implode(', ', $legal)));
  }

  /**
   * The element's variant references: axes in catalog order, then class wear.
   *
   * Axis references ride in the element's axis order so the print is
   * stable; class wear (the style axis) follows in wear order.
   *
   * @param array $axes
   *   The element's axis view (DialectCatalog::axesFor()).
   * @param array $axis_values
   *   Map of attr => variant id, as read.
   * @param string[] $worn
   *   The class list's variant ids, in wear order.
   */
  private static function references(array $axes, array $axis_values, array $worn): array {
    $variants = [];
    foreach (array_keys($axes) as $axis_name) {
      if (isset($axis_values[$axis_name])) {
        $variants[] = $axis_values[$axis_name];
      }
    }
    return array_map(static fn (string $variant): array => ['variant' => $variant, 'fragment' => ''], [
      ...$variants,
      ...$worn,
    ]);
  }

  /**
   * Detects an attribute value closed early by an embedded double quote.
   *
   * The failure shape: url("...") written with raw double quotes inside
   * style="" closes the attribute at the first embedded quote, and the
   * HTML5 tokenizer turns the rest of the value into a run of junk
   * attribute names (one ILLEGAL_ATTR per word, none naming the cause).
   * The tell is an attribute value left holding an unbalanced "(",
   * followed by at least one attribute name the element does not declare.
   * When it fires, the whole cascade collapses to ONE UNTERMINATED_ATTR
   * naming the truncated attribute and the single-quote fix, and every
   * shadowed junk attribute is swallowed (cascadeGuard()).
   *
   * @param \Drupal\daedalus_blueprint\Dialect\RawElement $el
   *   The raw element.
   * @param string[] $legal
   *   The element's declared attribute names; a shadowed name in this
   *   list still counts as junk once the cascade is established, but at
   *   least one ILLEGAL name must follow the truncated value for the
   *   cascade to be diagnosed at all (an unbalanced "(" with only legal
   *   attributes after it is an ordinary value error, not a truncation).
   *
   * @return array|null
   *   ['attr' => the truncated attribute's name, 'shadowed' => the names
   *   after it], or NULL when no cascade is present.
   */
  private function earlyClosedAttr(RawElement $el, array $legal): ?array {
    $names = array_map('strval', array_keys($el->attributes));
    foreach (array_values($el->attributes) as $i => $value) {
      if (!is_string($value) || substr_count($value, '(') <= substr_count($value, ')')) {
        continue;
      }
      $shadowed = array_slice($names, $i + 1);
      if ($shadowed === [] || array_diff($shadowed, $legal) === []) {
        continue;
      }
      return ['attr' => $names[$i], 'shadowed' => $shadowed];
    }
    return NULL;
  }

  /**
   * Emits the one cascade error, or swallows a shadowed junk attribute.
   *
   * @return bool
   *   TRUE when the attribute was consumed here (truncated source or
   *   shadowed junk); the caller skips its normal handling, including the
   *   truncated value's own declaration parse (its errors would only
   *   restate the truncation).
   */
  private function cascadeGuard(RawElement $el, string $name, ?array $cascade): bool {
    if ($cascade === NULL) {
      return FALSE;
    }
    if ($name === $cascade['attr']) {
      $this->errors[] = new DialectError('UNTERMINATED_ATTR', sprintf('%s="" on <%s> was closed early by a double quote inside its value (an unbalanced "(" is left behind, and the rest of the value parsed as junk attributes); use single quotes inside attribute values: url(\'media:<uuid>\'), url(\'generate:<prompt>\')', $name, $el->tag), $el->line);
      return TRUE;
    }
    return in_array($name, $cascade['shadowed'], TRUE);
  }

  /**
   * Handles the global attributes: id, class, style, stencil, data-*, residue.
   *
   * @param \Drupal\daedalus_blueprint\Dialect\RawElement $el
   *   The raw element.
   * @param string $name
   *   The attribute name.
   * @param string $value
   *   The attribute value as written.
   * @param array $node
   *   The lean node under construction (by reference).
   * @param array $default
   *   Collector for the style attribute's parsed declarations (by reference).
   * @param string[] $worn
   *   Collector for the variant ids the class list wears (by reference).
   * @param array $roster
   *   The element's declared class roster (DialectCatalog::classRosterFor());
   *   a class outside it still resolves against every published style
   *   variant (the grammar's class slot), the spine judging scope; the
   *   roster is what the corrective names.
   *
   * @return bool
   *   TRUE when the attribute was consumed (or rejected) here.
   */
  private function globalAttr(RawElement $el, string $name, string $value, array &$node, array &$default, array &$worn, array $roster): bool {
    if (str_starts_with($name, 'data-')) {
      $this->errors[] = new DialectError('READONLY_ATTR', sprintf('%s is a server print annotation and never authored', $name), $el->line);
      return TRUE;
    }
    if (in_array($name, DialectParser::residueAttrs(), TRUE)) {
      $this->errors[] = new DialectError('SERVER_DERIVED_ATTR', sprintf('%s is server-derived and never authored', $name), $el->line);
      return TRUE;
    }
    if ($name === 'id') {
      if (!preg_match(DialectParser::ID_RE, $value)) {
        $this->errors[] = new DialectError('BAD_ID', sprintf('id "%s" must match [A-Za-z][A-Za-z0-9_-]*', $value), $el->line);
        return TRUE;
      }
      if (isset($this->ids[$value])) {
        $this->errors[] = new DialectError('DUPLICATE_ID', sprintf('duplicate id "%s"', $value), $el->line);
        return TRUE;
      }
      $is_container = $el->tag === 'pb-container';
      $this->ids[$value] = [
        'container' => $is_container,
        'block_type' => $is_container ? NULL : DialectCatalog::blockTypeForTag($el->tag),
      ];
      $node[DialectParser::isUuid($value) ? 'uuid' : 'id'] = $value;
      return TRUE;
    }
    if ($name === 'class') {
      foreach ($this->catalog->references($value, MaterialRecord::SLOT_CLASS) as $reference) {
        if ($reference->kind !== ValueKind::Variant) {
          $this->errors[] = self::unknownClass($reference, $el->tag, $roster, $this->catalog, $el->line);
          continue;
        }
        $worn[] = $reference->id;
      }
      return TRUE;
    }
    if ($name === 'style') {
      $id = $el->attr('id');
      $context = sprintf('style="" on <%s%s>', $el->tag, $id === NULL || $id === '' ? '' : sprintf(' id="%s"', $id));
      $default = $this->css->parseDeclarations($value, $el->line, $context, $this->errors);
      return TRUE;
    }
    if ($name === 'stencil' && $el->tag !== 'pb-stencil') {
      $this->provenance($el, $value, $node);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Reads stencil="id": the placement provenance the print carried.
   *
   * An identity fact, never a request to re-instantiate: the element's
   * authored children ARE its content, and the stamp says which stencil
   * placed it (so the update gesture and the owned-variant wear rule can
   * target it after a rewrite). Refused when no published stencil spells
   * the name (UNKNOWN_STENCIL, the roster's did-you-mean) or when the
   * stencil places the other kind of element (STENCIL_MISMATCH: a
   * section-rooted stencil claimed on a block, or a block-rooted one on
   * <pb-container>). A fresh copy is <pb-stencil name="id" />.
   */
  private function provenance(RawElement $el, string $value, array &$node): void {
    $reference = $this->catalog->resolve($value, MaterialRecord::SLOT_STENCIL);
    if ($reference->kind !== ValueKind::Stencil) {
      $stencils = $this->catalog->roster()->stencils();
      $this->errors[] = DialectCssParser::unknownReference('UNKNOWN_STENCIL', sprintf('stencil="%s" on <%s> names no published stencil', $value, $el->tag), $reference, MaterialRecord::SLOT_STENCIL, $this->catalog, $stencils === [] ? 'No stencil is published on this site; an element placed from none carries no stencil attribute.' : sprintf('stencil="" is placement provenance (the stencil an element was placed from). Published stencils: %s.', MaterialsRoster::describe(array_values($stencils))), $el->line, [
        'attribute' => 'stencil',
        'available' => array_keys($stencils),
      ]);
      return;
    }
    $is_container = $el->tag === 'pb-container';
    $root_kind = $reference->record->rootKind;
    $expected = $is_container ? MaterialRecord::ROOT_SECTION : MaterialRecord::ROOT_BLOCK;
    if ($root_kind !== $expected) {
      $this->errors[] = new DialectError(
        'STENCIL_MISMATCH',
        sprintf('stencil="%s" on <%s>: that stencil places a %s, not a %s', $reference->id, $el->tag, $root_kind === MaterialRecord::ROOT_SECTION ? 'container' : 'block', $is_container ? 'container' : 'block'),
        $el->line,
        ['attribute' => 'stencil', 'stencil' => $reference->id, 'root_kind' => $root_kind, 'element' => $el->tag],
        sprintf('stencil="" is placement provenance and stays on the element the stencil placed; to place a fresh copy write <pb-stencil name="%s" />.', $reference->id),
      );
      return;
    }
    $node['stencil'] = $reference->id;
  }

  /**
   * The UNKNOWN_CLASS error: the roster's candidates, the element's roster.
   *
   * Shared with the set op's class patch (one corrective for one miss on
   * both entry points).
   *
   * @param \Drupal\daedalus_blueprint\Materials\ValueReference $reference
   *   The unresolved class reference.
   * @param string $tag
   *   The element's tag, for the corrective.
   * @param array $roster
   *   The element's declared class roster (class => variant id).
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog, for the did-you-mean.
   * @param int|null $line
   *   The element's source line, or NULL when unknown.
   */
  public static function unknownClass(ValueReference $reference, string $tag, array $roster, DialectCatalog $catalog, ?int $line): DialectError {
    $declares = sprintf('<%s> declares: %s (any other published style variant applies where its scope fits). Utility classes do not exist here: write the styles in style="" with the token roster.', $tag, implode(', ', array_keys($roster)) ?: '(none)');
    $error = DialectCssParser::unknownReference('UNKNOWN_CLASS', sprintf('class "%s" is not a published style variant', $reference->id), $reference, MaterialRecord::SLOT_CLASS, $catalog, $declares, $line, ['declared' => array_keys($roster)]);
    if ($error->hint === $declares) {
      return $error;
    }
    return new DialectError($error->code, $error->message, $error->line, $error->context, $error->hint . ' ' . $declares);
  }

  /**
   * Applies one field attribute onto the block_fields under its contract.
   *
   * Public static because the contract is shared: the parser maps authored
   * attributes here, and the DOM `set` op maps attribute patches through
   * the same contract (one grammar, two entry points).
   */
  public static function applyFieldAttr(array &$fields, array $spec, string $value): void {
    $field = $spec['field'];
    if (isset($spec['key'])) {
      $existing = is_array($fields[$field] ?? NULL) ? $fields[$field] : [];
      $existing[$spec['key']] = $value;
      $fields[$field] = $existing;
      return;
    }
    if (($spec['kind'] ?? NULL) === 'ref') {
      $existing = is_array($fields[$field] ?? NULL) ? $fields[$field] : [];
      $fields[$field] = $existing + (ctype_digit($value) ? ['target_id' => (int) $value] : ['_label' => $value]);
      return;
    }
    // Scalars and bare-uri links carry the attribute string verbatim.
    $fields[$field] = $value;
  }

  /**
   * Attaches the element's own style entry from variants and style="".
   */
  private function attachStyles(array &$node, array $variants, array $default): void {
    $entry = [];
    if ($variants !== []) {
      $entry['variants'] = $variants;
    }
    if ($default !== []) {
      $entry['default'] = $default;
    }
    if ($entry !== []) {
      $node['styles'][''] = $entry;
    }
  }

}
