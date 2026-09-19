<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\Layout;

use Drupal\Core\Layout\LayoutDefault;

/**
 * The layout plugin class for composed layouts: no template, no theme hook.
 *
 * A layout whose effective page_builder declaration says `composed: true`
 * wires this class in its layouts.yml (`class:`), and its section builds
 * render through the composed_container element instead of a layout
 * template: the declaration's root forms ARE the render (see
 * ComposedLayoutRenderer). The swap must live in the plugin class because
 * sections have no build event — Section::toRenderArray() calls the layout
 * plugin directly — so there is no seam to substitute the render at, the
 * way composed blocks are swapped at their component build event.
 *
 * The element is returned WITH the regions as children and nothing else:
 * daedalus_layout stamps the section's identity attributes and nested marker onto
 * this same array after build() returns, and the element's pre_render
 * reads the completed set (see ComposedContainer).
 */
class ComposedLayout extends LayoutDefault {

  /**
   * {@inheritdoc}
   */
  public function build(array $regions) {
    // Only defined regions, in the definition's order (core's rule).
    $build = [];
    foreach ($this->getPluginDefinition()->getRegionNames() as $region_name) {
      if (array_key_exists($region_name, $regions)) {
        $build[$region_name] = $regions[$region_name];
      }
    }
    $build['#type'] = 'composed_container';
    $build['#layout_id'] = $this->getPluginId();
    $build['#in_preview'] = $this->inPreview;
    $build['#settings'] = $this->getConfiguration();
    $build['#layout'] = $this->getPluginDefinition();
    return $build;
  }

}
