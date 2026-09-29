<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Render;

/**
 * The element address stamp: an element's identity in the design system.
 *
 * Every addressable element root carries `data-element="{family}:{id}"`,
 * the declaration the element resolved through (PageBuilderDeclarations'
 * four dimensions, spelled exactly as the styling roster, variant scopes
 * and skin defaults spell them): `block_type:{bundle}` for an entity-backed
 * block placement, `block_plugin:{base id}` for a plugin block (the
 * derivative suffix strips, as getBlockPlugin() strips it), `layout:{id}`
 * for a section, `kind:field` for a field and `kind:entity` for an entity
 * root. Stamped UNCONDITIONALLY in every mode, unlike the selectable key,
 * which is an editor's fact or a demanded one: the address is what a
 * type-addressed rule (`[data-element="block_type:text"]`) and a site
 * default (daedalus_stylish's compiled defaults tier) select by, so a
 * render that omitted it would silently fall out of the site's look.
 *
 * The four seams that stamp it, one per element shape, never a double
 * stamp: the Layout Builder placement (LayoutBuilderBlockAttributes; a
 * composed block hoists the same bag onto its composed root), the section
 * render array (daedalus_layout's getSectionAttributes, root and nested;
 * a composed section hoists it the same way), the field template
 * (DaedalusUi::wrapAndAttributeFieldsForEditing) and the entity view
 * (DaedalusUiHooks::entityView).
 */
final class ElementAddress {

  /**
   * The DOM attribute carrying the address.
   */
  public const ATTRIBUTE = 'data-element';

  /**
   * The entity root's address: the bare entity kind.
   */
  public const ENTITY = 'kind:entity';

  /**
   * A field root's address: the bare field kind.
   */
  public const FIELD = 'kind:field';

  /**
   * The address prefix every block placement family shares a kind under.
   */
  public const BLOCK_TYPE_PREFIX = 'block_type:';
  public const BLOCK_PLUGIN_PREFIX = 'block_plugin:';
  public const LAYOUT_PREFIX = 'layout:';

  /**
   * An entity-backed block placement's address: its bundle's declaration.
   */
  public static function blockType(string $bundle): string {
    return self::BLOCK_TYPE_PREFIX . $bundle;
  }

  /**
   * A plugin block placement's address: the base plugin's declaration.
   *
   * The derivative suffix strips ("system_menu_block:main" resolves through
   * "block_plugin:system_menu_block"), the same id
   * PageBuilderDeclarations::getBlockPlugin() layers a declaration under.
   */
  public static function blockPlugin(string $plugin_id): string {
    [$base_id] = explode(':', $plugin_id, 2);
    return self::BLOCK_PLUGIN_PREFIX . $base_id;
  }

  /**
   * A section's address: its layout plugin's declaration.
   */
  public static function layout(string $layout_id): string {
    return self::LAYOUT_PREFIX . $layout_id;
  }

}
