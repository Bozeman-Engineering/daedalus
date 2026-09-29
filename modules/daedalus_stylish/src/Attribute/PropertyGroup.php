<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The PropertyGroup attribute.
 *
 * A property group is a set of related CSS properties (Spacing, Typography,
 * ...) that the style guide can declare on an element. One plugin powers both
 * halves of the system: the server half (this plugin — structural validation,
 * constraint enforcement, AI schema fragments) and the client half (the
 * inspector section widget, registered separately in JS under the same group
 * id).
 *
 * Every stylable CSS property is owned by exactly one group; the style guide
 * YAML enables groups per element and optionally constrains individual
 * properties within them.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PropertyGroup extends AttributeBase {

  /**
   * Constructs a new PropertyGroup instance.
   *
   * @param string $id
   *   The plugin ID — the group id the style guide YAML enables under
   *   property_groups, and the id the client widget registers under.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $label
   *   The section heading shown in the inspector panel.
   * @param int $weight
   *   Default order of the group's section in the inspector panel.
   * @param class-string|null $deriver
   *   (optional) The deriver class (the generated wide tier derives one
   *   group per CSS spec area from data/wide-properties.json).
   * @param bool $pseudo
   *   (optional) Whether the group is granted only at a pseudo-element
   *   (the generated box a selector ends in: "&::before", ".quote::after").
   *   The roster walk grants every such group wherever it lands on a
   *   pseudo-element, declared or derived, and nowhere else; a declaration
   *   naming one on a real element fails the guide build, and a write of
   *   one of its properties outside a pseudo-element is refused in every
   *   enforcement mode (the property has no meaning on a real box).
   */
  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly int $weight = 0,
    public readonly ?string $deriver = NULL,
    public readonly bool $pseudo = FALSE,
  ) {}

}
