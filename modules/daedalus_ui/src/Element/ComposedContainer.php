<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Element;

use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\RenderElementBase;

/**
 * The composed container render element: one declared tree, rendered.
 *
 * The shared render element for every `composed: true` layout. No
 * template: the layout's effective page_builder declaration is generative,
 * and the renderer prints the tree the element resolver computes from the
 * section's instance facts (see ComposedLayoutRenderer for the mechanism;
 * ComposedLayout is the layout plugin class that builds this element).
 *
 * The element IS the section build: the layout plugin returns it with the
 * regions as children, and everything daedalus_layout stamps on the section build
 * after the plugin returns — the section attribute bag
 * (getSectionAttributes) and the nested marker
 * (NestedSectionRenderArray::NESTED_BUILD_KEY) — rides the same array.
 * Only at render time is that set complete, so the swap happens in
 * pre_render: the regions move into the composed tree as its children,
 * the attribute bag hoists onto the composed root (the root IS the
 * section element), and the nested marker decides the depth the
 * declaration resolves at.
 *
 * Properties:
 * - #layout_id: the layout plugin id whose effective declaration renders.
 * - #attributes: the section's stamped attribute bag, hoisted onto the
 *   composed root. This bag is the one seam for anything that must land
 *   on every section root regardless of how the section renders: a
 *   module stamping it (daedalus_stylish's container defaults stamp) prepends its
 *   own pre_render through hook_element_info_alter() and writes here,
 *   ahead of the hoist, the pre_render twin of a layout template's
 *   preprocess.
 */
#[RenderElement('composed_container')]
final class ComposedContainer extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#pre_render' => [
        [static::class, 'preRenderComposedContainer'],
      ],
      '#layout_id' => NULL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return array_merge(parent::trustedCallbacks(), ['preRenderComposedContainer']);
  }

  /**
   * Builds the composed render at render time.
   */
  public static function preRenderComposedContainer(array $element): array {
    $regions = [];
    foreach (Element::children($element) as $key) {
      $regions[$key] = $element[$key];
      unset($element[$key]);
    }
    $element['composed'] = \Drupal::service('daedalus_ui.composed_layout_renderer')->build(
      (string) $element['#layout_id'],
      $regions,
      $element['#attributes'] ?? [],
      // daedalus_layout's nested marker, read by its literal string so this module
      // keeps no daedalus_layout dependency (the same seam daedalus_stylish_layouts read;
      // without daedalus_layout no nesting exists and the marker is never
      // present). See NestedSectionRenderArray::NESTED_BUILD_KEY.
      !empty($element['#daedalus_layout_nested']),
    );
    return $element;
  }

}
