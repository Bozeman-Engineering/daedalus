<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The Mode attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Mode extends AttributeBase {

  /**
   * Constructs a new Mode instance.
   *
   * @param string $id
   *   The name of the module.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The human-readable name of the module.
   * @param int $weight
   *   The weight or order the tool shows in the toolbar.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label,
    public readonly int $weight,
  ) {}

}
