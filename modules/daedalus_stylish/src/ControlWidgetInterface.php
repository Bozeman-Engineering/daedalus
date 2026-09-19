<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A semantic basic-mode styling control: the server half of the contract.
 *
 * The plugin declares what a control IS — its label, the basic-mode section it
 * files under, and the CSS properties it owns — so the style guide can validate
 * controls: bindings at build time and basic mode can group them. Rendering and
 * value mapping live in the client widget registry under the same plugin id; a
 * widget's writes travel the ordinary preview/commit path against the bound
 * element's full key, so there is no server-side behavior to implement here.
 */
interface ControlWidgetInterface {

  /**
   * The widget's human label.
   */
  public function label(): TranslatableMarkup;

  /**
   * The basic-mode section the widget files under by default.
   *
   * A controls: binding may override it per control with a section key;
   * the client model resolves binding over widget.
   */
  public function getSection(): ControlSection;

  /**
   * The CSS property names this widget owns.
   *
   * @return string[]
   *   The complete set of properties the widget's writes may touch.
   */
  public function getProperties(): array;

}
