<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;

/**
 * The blueprint entity's render template.
 *
 * Core's view builder renders a content entity through the theme hook named
 * after its type (`#theme => 'blueprint'`), and everything stamped on the
 * build's #attributes reaches the page only through a template that prints
 * `{{ attributes }}` on its outermost element: without one the renderer
 * drops the hook and prints the children bare. The page builder's canvas
 * root rides those attributes (daedalus_ui's entity_view hook stamps the
 * route entity's wrapper, selection identity and main-entity marks there,
 * and daedalus_layout's editable UI builder relies on the entity view
 * supplying that root), so a saved blueprint's canonical route is a canvas
 * only because this template exists.
 */
class BlueprintThemeHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'blueprint' => [
        'render element' => 'elements',
        'initial preprocess' => static::class . ':preprocessBlueprint',
      ],
    ];
  }

  /**
   * Prepares variables for blueprint templates.
   *
   * Default template: blueprint.html.twig.
   *
   * @param array $variables
   *   An associative array containing:
   *   - elements: An array of elements to display in view mode.
   *   - blueprint: The blueprint entity.
   *   - view_mode: The view mode.
   */
  public function preprocessBlueprint(array &$variables): void {
    $variables['view_mode'] = $variables['elements']['#view_mode'];
    $variables['blueprint'] = $variables['elements']['#blueprint'];
    $variables['content'] = [];
    foreach (Element::children($variables['elements']) as $key) {
      $variables['content'][$key] = $variables['elements'][$key];
    }
  }

}
