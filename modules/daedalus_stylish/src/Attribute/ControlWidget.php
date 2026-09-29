<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\ControlSection;

/**
 * The ControlWidget attribute.
 *
 * A control widget is a curated, semantic styling control (Figma-style
 * alignment buttons, a spacing scale, a width picker) the basic inspector
 * mode renders INSTEAD of raw property rows. The contract splits like
 * property groups do: this PHP plugin declares — id, label, and the CSS
 * properties the widget OWNS — while a JS component registry keyed by the
 * same plugin id owns rendering and the value mapping (the "center" button
 * writes "justify-content: center").
 *
 * The PHP half is not ceremony: owning the property list here is what lets
 * the style guide validate a controls: binding at guide build time — a
 * control bound to a part whose declared property groups cannot cover the
 * widget's properties fails loudly when the guide is built, instead of
 * shipping a control whose writes the validator bounces at runtime. The
 * section is the widget's default place in basic mode's four headers
 * (ControlSection); a binding may move it with a `section:` key.
 *
 * @see \Drupal\daedalus_stylish\StyleGuide
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ControlWidget extends AttributeBase {

  /**
   * Constructs a new ControlWidget instance.
   *
   * @param string $id
   *   The plugin ID — the id block-type YAML names under controls: and the
   *   id the client component registers under.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The widget's human label, the default control label when the controls:
   *   entry does not set one.
   * @param \Drupal\daedalus_stylish\ControlSection $section
   *   The basic-mode section the widget files under (Layout, Text, Surface,
   *   Motion) when the controls: entry does not move it with a section key.
   * @param string[] $properties
   *   The CSS property names this widget owns — the complete set of
   *   properties its writes may touch. Guide-build validation requires the
   *   bound part's property groups to cover every one of them.
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ControlSection $section,
    public readonly array $properties = [],
  ) {}

}
