<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The tool attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Tool extends AttributeBase {

  /**
   * Constructs a new Tool instance.
   *
   * @param string $id
   *   The name of the module.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The human-readable name of the module.
   * @param string|null $hot_key
   *   *   The key to press to activate the tool.
   * @param int $weight
   *   The weight or order the tool shows in the toolbar.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label,
    public readonly ?string $hot_key,
    public readonly int $weight,
  ) {}

}
