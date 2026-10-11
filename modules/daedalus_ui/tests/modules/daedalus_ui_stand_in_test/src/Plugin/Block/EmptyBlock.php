<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui_stand_in_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A block anyone may see whose build is empty.
 *
 * The stand-in's EMPTY leg: in the editing posture core gives it the
 * placeholder, which daedalus_ui replaces with "Nothing to show here yet".
 * In the visitor posture core drops it. The build carries cache metadata
 * and nothing else, the shape a menu block with no links builds (core's
 * placeholder markup lands beside that metadata, not alone).
 */
#[Block(
  id: 'daedalus_ui_stand_in_test_empty',
  admin_label: new TranslatableMarkup('Empty block'),
  category: new TranslatableMarkup('Testing'),
)]
final class EmptyBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return ['#cache' => ['tags' => ['daedalus_ui_stand_in_test'], 'max-age' => 0]];
  }

}
