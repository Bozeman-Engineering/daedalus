<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * The container-family defaults stamp: one decision, two render seams.
 *
 * A section root wears the kind:container family class whenever either
 * defaults tier holds any element defaults (the selector the skin's and
 * the declared container defaults deliver on, and the donut boundary; see
 * DefaultsStylesheet::hasElementDefaults()), plus the per-layout
 * refinement class when the layout's declaration or the skin defaults
 * that layout. Sections reach the page two ways and the stamp must land
 * on both: a TEMPLATED layout's root is stamped in
 * daedalus_stylish_preprocess_layout(); a COMPOSED layout has no template (its
 * declaration is generative and daedalus_ui's composed_container
 * element prints it, see ComposedLayoutRenderer), so its root is stamped
 * in preRenderComposedContainer(), prepended to that element's pre_render
 * chain by daedalus_stylish_element_info_alter(): the classes land in the
 * element's #attributes bag before the element hoists the bag onto the
 * composed root (the root IS the section element). Both seams read
 * classes(), so the demand rule cannot drift between them.
 *
 * Neither preprocess nor pre_render can bubble cache metadata; the demand
 * decision is covered by the host entity fragment's stamp tags (see
 * _daedalus_stylish_stamp_defaults_scope_classes()), inside which live sections
 * render.
 */
final class ContainerDefaultsStamp implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRenderComposedContainer'];
  }

  /**
   * The scope classes a section root wears, in stamp order.
   *
   * @param string[] $scopes
   *   The compiled defaults' scopes (DefaultsStylesheet::scopes()).
   * @param string|null $layout_id
   *   The section's layout plugin id, when the seam knows it.
   *
   * @return string[]
   *   The family class, then the layout refinement class when that layout
   *   is defaulted; [] while no tier holds element defaults.
   */
  public static function classes(array $scopes, ?string $layout_id): array {
    if (!DefaultsStylesheet::hasElementDefaults($scopes)) {
      return [];
    }
    $classes = [DefaultsStylesheet::scopeClass('kind:container')];
    if ($layout_id !== NULL && in_array("layout:$layout_id", $scopes, TRUE)) {
      $class = DefaultsStylesheet::scopeClass("layout:$layout_id");
      if ($class !== NULL) {
        $classes[] = $class;
      }
    }
    return $classes;
  }

  /**
   * Stamps a composed section's root: pre_render, ahead of the hoist.
   *
   * Runs before ComposedContainer::preRenderComposedContainer(), which
   * hands #attributes to the renderer as the composed root's hoisted bag;
   * #layout_id is the section's layout plugin id, the same fact the
   * templated seam reads from the layout definition.
   */
  public static function preRenderComposedContainer(array $element): array {
    $scopes = \Drupal::service('daedalus_stylish.defaults_stylesheet')->scopes();
    $layout_id = isset($element['#layout_id']) ? (string) $element['#layout_id'] : NULL;
    foreach (self::classes($scopes, $layout_id) as $class) {
      $element['#attributes']['class'][] = $class;
    }
    return $element;
  }

}
