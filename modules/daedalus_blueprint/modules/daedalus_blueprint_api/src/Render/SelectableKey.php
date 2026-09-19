<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Render;

/**
 * Builds selectable keys, the page builder's element identity scheme.
 *
 * A selectable key names one addressable page element. The same key appears in
 * three places and ties them together: stamped on the element's controlled
 * wrapper in the `data-selectable` attribute, used as the markup key in the
 * mutation-response contract, and used by the editing client as its selection
 * identity. Canvas and panel stay in sync by string equality on these keys.
 *
 * The formats are uuid-qualified prefix keys. The prefix says what the
 * qualifier means:
 * - entity:{entity_uuid} — a rendered entity root
 * - field:{entity_uuid}:{field_name} — a field of an entity (the root entity
 *   or a paragraph)
 * - container:{section_uuid} — a Layout Builder section (UUIDs via daedalus_layout).
 *   The UI word is "container": arrangement is styling on the one element,
 *   grouping is nesting, so a section IS a container at every depth. Regions
 *   have no keys — a container's children are one ordered flow, and a
 *   multi-region layout's named slots are markup, not addressable elements
 * - block:{component_uuid} — a Layout Builder placement
 * - block:{component_uuid}:{property_name} — a named property of an inline
 *   block placement: a block_content field, or `label` (the placement's
 *   display label, which heading-style blocks render as page content). The
 *   property namespace is the blueprint updateElement contract's flat bag
 *   (fields, managed keys, label); a property is only stamped when it renders
 *   as distinct page content. The component UUID is the qualifier because
 *   both the server and the blueprint address inline-block content by
 *   component UUID
 * - {parent_key}::.{css_class} — a presentation sub-element: a piece of a
 *   selectable element's markup addressed by CSS class (Daedalus Stylish style-map
 *   keys and @scope roots). Sub-elements are stylable but never selectable —
 *   they never appear in data-selectable attributes or selection state
 *
 * This is the single producer of the format: both the attribution side
 * (daedalus_ui, daedalus_layout, daedalus_edit stamping wrappers) and the
 * mutation-response side build keys through these methods, never by
 * concatenating strings.
 */
final class SelectableKey {

  /**
   * The attribute carrying a selectable key on an element's controlled wrapper.
   */
  public const ATTRIBUTE = 'data-selectable';

  /**
   * Builds the key for a rendered entity root.
   */
  public static function entity(string $entity_uuid): string {
    return "entity:$entity_uuid";
  }

  /**
   * Builds the key for a field of an entity (root entity or paragraph).
   */
  public static function field(string $entity_uuid, string $field_name): string {
    return "field:$entity_uuid:$field_name";
  }

  /**
   * Builds the key for a Layout Builder section (a container).
   */
  public static function container(string $section_uuid): string {
    return "container:$section_uuid";
  }

  /**
   * Builds the key for a Layout Builder placement.
   */
  public static function block(string $component_uuid): string {
    return "block:$component_uuid";
  }

  /**
   * Builds the key for a named property of an inline block placement.
   *
   * Usually a block_content field; also `label` for the placement's display
   * label when it renders as page content.
   */
  public static function blockField(string $component_uuid, string $field_name): string {
    return "block:$component_uuid:$field_name";
  }

  /**
   * Builds the key for a presentation sub-element of a selectable element.
   *
   * Sub-elements address stylable markup inside a selectable element by a
   * single CSS class. They exist for style maps and CSS @scope roots only:
   * they are never stamped in data-selectable attributes and never enter
   * selection state.
   *
   * @param string $parent_key
   *   A selectable key built by one of the other builders.
   * @param string $css_class
   *   The CSS class naming the sub-element, without the leading dot.
   */
  public static function subElement(string $parent_key, string $css_class): string {
    return "$parent_key::.$css_class";
  }

}
