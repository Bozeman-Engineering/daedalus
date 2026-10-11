<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_blueprint\LayoutBuilder\InlineBlockValues;

/**
 * Declares the value-block key on core's inline block settings schema.
 */
final class ConfigSchemaAlter {

  /**
   * Implements hook_config_schema_info_alter().
   *
   * An inline block component on a display host stores its block as
   * values (block_values: the block's fields in the field grammar,
   * references by uuid) instead of naming a block entity. The key joins
   * core's inline_block settings mapping so a templated display exports
   * and imports with typed config. The values themselves are opaque to
   * typed config: their grammar is the field handlers', judged when they
   * are written (InlineBlockValues).
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(array &$definitions): void {
    if (!isset($definitions['inline_block']['mapping'])) {
      return;
    }
    $definitions['inline_block']['mapping'][InlineBlockValues::KEY] = [
      'type' => 'sequence',
      'label' => 'Block values: the block content fields in the blueprint field grammar, keyed by field name, references by uuid',
      'sequence' => [
        'type' => 'ignore',
        'label' => 'Field value',
      ],
    ];
  }

}
