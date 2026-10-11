<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\daedalus_blueprint\Hook\MaterialsSchemaHooks;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;

/**
 * Projects a bundle's blueprint schema into the page language's view.
 *
 * BlueprintSchemaBuilder describes a bundle for the JSON door (the API,
 * the CLI, eb_create_entity/eb_edit): the layout node
 * shape, the style map's key grammar, the anatomy trees. A writer on the
 * dialect door authors none of that: the layout is ONE page-language
 * document, styles ride style="" and a <style> block, parts are the
 * catalog's declared parts, and the stylable rosters, conditions, and
 * tokens are already in the cached "Page language" section. Handing that
 * writer the JSON schema is worse than wasteful: its `stylable` SETS
 * ("spacing") land right before the write and outrank the catalog's
 * closed roster ("container: minus padding") on recency, and the writer
 * pads the cards.
 *
 * This projection keeps what the dialect door cannot get elsewhere:
 * - the entity-level fields (they ride page_create's JSON envelope, so
 *   their JSON guidance is right there), minus the styling field;
 * - the bundle's guidance;
 * - `elements`: per dialect tag, the type's design guidance (what the
 *   block is FOR, from the site's context declarations), the attributes
 *   whose grammar the catalog line does not spell (links, references,
 *   generation and aspect ratio in the image field handler's own words,
 *   body), the tags the active skin dresses on a tag attribute, and the
 *   variants the palette's placements of the element are born wearing
 *   (the catalog's birth defaults; a document states them itself);
 * - `materials`: the roster's schema fragment (every kind, full text;
 *   the favorite block blueprints the bundle's host is offered, with the
 *   group index of the rest, or one group in full when the read named
 *   one), the schema's own top-level key, carried through unchanged,
 *   plus the style guide's remaining prose (bindings, deviations,
 *   guidance);
 * - `starts`: a page bundle's page blueprints (what a page of it may
 *   start as, the ids page_create's `start` takes), carried through as
 *   Hook\MaterialsSchemaHooks landed it, the structure line naming it;
 *
 * And drops what contradicts or duplicates the catalog: the layout node
 * grammar, the style-map guidance and key grammar, `stylable` sets,
 * `conditions`, `current_values`, and every anatomy tree. One grammar
 * per door; the schema builder stays the JSON door's spec.
 */
final class DialectSchemaProjector {

  /**
   * Style-guide keys the cached catalog already teaches, dropped here.
   *
   * Rich_text is the fragment's spelling of the instance vocabulary in
   * the JSON door's key grammar; on the dialect door the cached Page
   * language section teaches the same vocabulary as selectors.
   */
  private const CATALOG_TAUGHT = ['stylable', 'conditions', 'current_values', 'anatomy', 'rich_text'];

  /**
   * The schema's materials key, rendered by core from the roster.
   */
  public const MATERIALS = 'materials';

  /**
   * The note beside an element's axis attributes.
   */
  private const AXES_NOTE = 'Each axis attribute value names the saved variant the element wears on that axis (described under materials.variants).';

  /**
   * The guidance beside the container's name attribute.
   */
  private const SLOT_GUIDANCE = 'A slot section: a top-level container that fills a slot of the document around this one (a locked bundle\'s template, the site frame) instead of standing in the page\'s own flow. Its children are the page\'s own content, shown where the slot sits; everything a <pb-container> takes, it takes, with name being the slot id.';

  private const LINKED_GUIDANCE = 'A linked placement: a saved block blueprint shown by reference as one block, its document rendered live where the element sits under this page\'s own fields (a field block inside it shows this page\'s field). Editing the blueprint changes every page linking it; the element takes no children and no content of its own (edit the blueprint through page_read and page_edit with entity_type blueprint), only its own box styles and axes. A copy to adapt is <pb-blueprint name="id"/> instead.';

  private const NAME_GUIDANCE = 'The container\'s name: what the editor\'s page tree, canvas chips and panels call it ("Hero", "Pricing"). Prints on a named container, set {name} renames it (null clears); an unnamed container reads by its kind (Section at the top level, Container nested).';

  /**
   * Whether a built schema has a page-language view.
   *
   * A bundle with a layout field on a site whose catalog knows the
   * container layout: without a container there is no document to write.
   */
  public function applies(array $schema, DialectCatalog $catalog): bool {
    return isset($schema['layout']) && $catalog->containerLayoutId() !== '';
  }

  /**
   * Projects the JSON-door schema into the dialect door's view.
   *
   * @param array $schema
   *   The schema as BlueprintSchemaBuilder::getSchema() built it.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The site's dialect catalog.
   *
   * @return array
   *   The projected schema.
   */
  public function project(array $schema, DialectCatalog $catalog): array {
    $out = [];
    foreach (['entity_type', 'bundle', 'label'] as $key) {
      if (isset($schema[$key])) {
        $out[$key] = $schema[$key];
      }
    }
    $out['_structure'] = sprintf('This bundle carries a page-builder layout, authored in the page language: page_create takes the entity field values below in "fields" and the whole layout as ONE page-language document in "layout"; page_edit takes ops on the current page. The Page language section of your instructions carries the element vocabulary, the per-kind stylable rosters, the conditions, and the design system brief (every material by its first sentence); this schema carries what it does not: the entity fields, what each element is for, and the materials in full (%s, each with its whole description).', implode(', ', array_map(static fn (MaterialKind $kind): string => $kind->plural(), MaterialKind::cases())));

    // The style map is never authored on this door: styles ride the
    // document. Its style guide's remaining prose is hoisted.
    $style_guide = $schema['styles'][MaterialsSchemaHooks::STYLE_GUIDE] ?? NULL;
    $out['fields'] = $schema['fields'] ?? [];
    if (isset($schema['guidance'])) {
      $out['guidance'] = $schema['guidance'];
    }

    $layout = $schema['layout'] ?? [];
    $out['elements'] = $this->elements($layout, $catalog);

    if (isset($schema[self::MATERIALS])) {
      $out[self::MATERIALS] = $schema[self::MATERIALS];
    }
    // A page bundle's starts (the page blueprints page_create's `start`
    // takes) carry through as the hook landed them, and the structure
    // line says what the key is while it rides.
    if (isset($schema[MaterialsSchemaHooks::STARTS])) {
      $out[MaterialsSchemaHooks::STARTS] = $schema[MaterialsSchemaHooks::STARTS];
      $out['_structure'] .= ' "starts" lists the page blueprints a page of this bundle may start as (label, description, a saved one\'s entity_id for page_read): page_create\'s start takes one of their ids, blank or template, instead of a layout.';
    }
    if (is_array($style_guide)) {
      $rest = array_diff_key($style_guide, array_flip(self::CATALOG_TAUGHT));
      if ($rest !== []) {
        $out['style_guide'] = $rest;
      }
    }
    return $out;
  }

  /**
   * The elements section: one entry per dialect tag.
   */
  private function elements(array $layout, DialectCatalog $catalog): array {
    $elements = [];

    $container = ['guidance' => $this->containerGuidance($layout, $catalog)];
    $axes = $this->axisAttributes($catalog->axesFor(TRUE, NULL, NULL));
    // The container's name leads its attributes: the one renamable slot
    // of the element-naming spine, the same word the editor shows.
    $container['attributes'] = [DialectWalk::NAME_ATTR => ['guidance' => self::NAME_GUIDANCE]] + $axes;
    if ($axes !== []) {
      $container['attributes_note'] = self::AXES_NOTE;
    }
    $born = $this->bornWearing($catalog, Address::layout($catalog->containerLayoutId()), $catalog->axesFor(TRUE, NULL, NULL));
    if ($born !== NULL) {
      $container['born_wearing'] = $born;
    }
    $elements['pb-container'] = $container;
    // The slot form: the same container, filling a slot the document
    // around this one placed. Top level only; name is the slot id.
    $elements[DialectWalk::SLOT_TAG] = [
      'guidance' => self::SLOT_GUIDANCE,
      'attributes' => [DialectWalk::NAME_ATTR => ['guidance' => 'Required. The id of the slot this section fills (the slot block the template or the site frame placed); a slot fills once per document, and the id is fixed for the section\'s life.']] + $axes,
    ];
    // The linked placement: a block whose content is a blueprint's live
    // document. Its one attribute names the blueprint; its axes are the
    // block kind's.
    $elements[DialectWalk::LINKED_TAG] = [
      'guidance' => self::LINKED_GUIDANCE,
      'attributes' => [DialectWalk::LINKED_ATTR => ['guidance' => 'Required. The machine name of a published block blueprint saved on this site (materials.blueprints); fixed for the element\'s life, and never a shipped file.']] + $this->axisAttributes($catalog->axesFor(FALSE, NULL, LinkedBlueprintBlock::PLUGIN_ID)),
    ];

    $declared = $catalog->blockTypes();
    foreach ($layout['available_block_types'] ?? [] as $type => $type_schema) {
      $type = (string) $type;
      if (isset($declared[$type])) {
        $elements[DialectCatalog::tagForBlockType($type)] = $this->declaredElement($type, is_array($type_schema) ? $type_schema : [], $declared[$type], $catalog);
      }
      else {
        // Undeclared on the dialect: the generic form carries the field
        // values as <pb-field> children, so the field schemas stay.
        $entry = ['form' => sprintf('<pb-block type="%s"><pb-field name="field_x">value</pb-field></pb-block>', $type)];
        if (isset($type_schema['guidance'])) {
          $entry['guidance'] = $type_schema['guidance'];
        }
        if (isset($type_schema['block_fields'])) {
          $entry['fields'] = $type_schema['block_fields'];
        }
        $elements['pb-block type="' . $type . '"'] = $entry;
      }
    }
    // Declared types the schema did not list (placement-restricted away in
    // this view mode) stay out: the schema is the placement spine's answer.
    return $elements;
  }

  /**
   * The container's guidance: the container layout's own prose if any.
   */
  private function containerGuidance(array $layout, DialectCatalog $catalog): string {
    foreach ($layout['available_layouts'] ?? [] as $entry) {
      if (($entry['id'] ?? NULL) === $catalog->containerLayoutId() && isset($entry['guidance'])) {
        return (string) $entry['guidance'];
      }
    }
    return 'The one grouping element: an ordered flow of children, nestable; a top-level container is a page band.';
  }

  /**
   * A declared block type's entry: guidance plus attribute grammar.
   */
  private function declaredElement(string $type, array $type_schema, array $spec, DialectCatalog $catalog): array {
    $entry = [];
    if (isset($type_schema['label'])) {
      $entry['label'] = $type_schema['label'];
    }
    if (isset($type_schema['guidance'])) {
      $entry['guidance'] = $type_schema['guidance'];
    }
    $attributes = $this->axisAttributes($catalog->axesFor(FALSE, $type, NULL));
    if ($attributes !== []) {
      $entry['attributes_note'] = self::AXES_NOTE;
    }
    foreach ($spec['attributes'] ?? [] as $name => $attr_spec) {
      $attr = ['field' => $attr_spec['field']];
      if (isset($attr_spec['values'])) {
        $attr['values'] = $attr_spec['values'];
      }
      $guidance = $this->attributeGuidance((string) $name, $attr_spec, $type_schema);
      if ($guidance !== NULL) {
        $attr['guidance'] = $guidance;
      }
      $dressed = $this->dressedTagsGuidance((string) $name, $attr_spec, $catalog);
      if ($dressed !== NULL) {
        $attr['dressed'] = $dressed;
      }
      $attributes[$name] = $attr;
    }
    if ($attributes !== []) {
      $entry['attributes'] = $attributes;
    }
    $born = $this->bornWearing($catalog, Address::blockType($type), $catalog->axesFor(FALSE, $type, NULL));
    if ($born !== NULL) {
      $entry['born_wearing'] = $born;
    }
    if (isset($spec['body'])) {
      $entry['body'] = [
        'field' => $spec['body']['field'],
        'guidance' => ($spec['body']['kind'] ?? 'plain') === 'rich'
          ? 'The element body: HTML in the declared format (paragraphs, lists, emphasis, inline links).'
          : 'The element body: plain text, one run.',
      ];
    }
    $class_variants = $catalog->classRosterFor(FALSE, $type, NULL);
    if ($class_variants !== []) {
      $entry['class'] = [
        'values' => array_values($class_variants),
        'guidance' => 'Saved looks worn via class (described under materials.variants).',
      ];
    }
    return $entry;
  }

  /**
   * The axis attributes of one element's axis view, as schema entries.
   *
   * @return array
   *   attr => ['values' => [attr value => variant id], 'applies' =>
   *   'top-level containers only' when the axis is root-only].
   */
  private function axisAttributes(array $axes): array {
    $attributes = [];
    foreach ($axes as $attr => $axis) {
      $entry = ['values' => $axis['values'] ?? []];
      if (!empty($axis['top_level_only'])) {
        $entry['applies'] = 'top-level containers only';
      }
      $attributes[$attr] = $entry;
    }
    return $attributes;
  }

  /**
   * The variants the palette's placements of an element are born wearing.
   *
   * The catalog's birth defaults for the placement scope, spelled as the
   * element's tag speaks them (axis attribute = value); a document is a
   * total statement and states them itself. NULL when the scope stamps
   * nothing.
   *
   * @param \Drupal\daedalus_blueprint\Dialect\DialectCatalog $catalog
   *   The dialect catalog.
   * @param \Drupal\daedalus_blueprint\Materials\Address $scope
   *   The placement scope (the container layout, a block type).
   * @param array $axes
   *   The element's axis view (DialectCatalog::axesFor()), to spell each
   *   default by its attribute and value.
   */
  private function bornWearing(DialectCatalog $catalog, Address $scope, array $axes): ?string {
    $worn = [];
    foreach ($catalog->birthDefaults($scope) as $attr => $variant) {
      $value = array_search($variant, $axes[$attr]['values'] ?? [], TRUE);
      $worn[] = $value === FALSE ? $variant : sprintf('%s="%s"', $attr, $value);
    }
    if ($worn === []) {
      return NULL;
    }
    return sprintf('A placement from the palette is born wearing %s; a document states what it wears itself (an omitted axis is not worn).', implode(' ', $worn));
  }

  /**
   * The tags the active skin dresses among a tag attribute's values.
   *
   * "Pick the tag, the skin sizes it" as data: on an attribute whose
   * values are tag names (the text element's tag), the values the
   * catalog's dressed tags cover. NULL off a tag attribute or when the
   * skin dresses none of its values.
   */
  private function dressedTagsGuidance(string $name, array $attr_spec, DialectCatalog $catalog): ?string {
    if ($name !== 'tag' || !isset($attr_spec['values']) || !is_array($attr_spec['values'])) {
      return NULL;
    }
    $dressed = array_values(array_intersect($attr_spec['values'], $catalog->dressedTags()));
    if ($dressed === []) {
      return NULL;
    }
    return sprintf('The skin dresses %s: pick the tag, the skin sizes it.', implode(', ', $dressed));
  }

  /**
   * The grammar of an attribute the catalog line does not spell out.
   *
   * Generation speaks in the image field handler's own door-neutral words
   * (the 'generation' entry the schema builder carried in from
   * MediaGenerationInterface, the one home of the sizing heuristic too),
   * never the JSON door's guidance and never a copy here; the sizing
   * attribute lists its values and leans on that text. File placement
   * speaks the same way (the 'file' entry, from MediaFromFileInterface).
   *
   * @param string $name
   *   The attribute name.
   * @param array $attr_spec
   *   The attribute's catalog entry.
   * @param array $type_schema
   *   The block type's JSON-door schema entry (block_fields carry each
   *   field handler's guidance and generation vocabulary).
   */
  private function attributeGuidance(string $name, array $attr_spec, array $type_schema): ?string {
    $generation = $type_schema['block_fields'][$attr_spec['field'] ?? '']['generation'] ?? NULL;
    if (isset($attr_spec['key'], $generation['dimension_key']) && $attr_spec['key'] === $generation['dimension_key']) {
      return 'Chosen with generate (its guidance carries the choice).';
    }
    switch ($attr_spec['key'] ?? NULL) {
      case '_generate':
        $guidance = $generation['guidance'] ?? NULL;
        return is_string($guidance) && $guidance !== '' ? $guidance : NULL;

      case '_file':
        $guidance = $type_schema['block_fields'][$attr_spec['field'] ?? '']['file']['guidance'] ?? NULL;
        return is_string($guidance) && $guidance !== '' ? $guidance : NULL;

      case 'alt_text':
        return 'The accessible description of the image.';

      case 'format':
        return 'The text format the body is rendered through.';
    }
    switch ($attr_spec['kind'] ?? NULL) {
      case 'link':
        return 'A path or URL as a person would type it: "/about", "?page=2", "#top", "<front>", "<nolink>" (a link with no destination), "entity:node/42", or a full URL with its scheme.';

      case 'ref':
        return $name === 'media' || str_ends_with($name, '-media')
          ? 'An existing media item, by id or exact label.'
          : 'Existing content, by id or exact label; reference only what already exists.';
    }
    return NULL;
  }

}
