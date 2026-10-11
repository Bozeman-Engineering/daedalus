<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api_render_test\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A block whose build throws an \Error.
 *
 * The door's failure pin: a placed block that breaks during a render with
 * an \Error rather than an \Exception (the shape a failed assertion takes,
 * which is what drupal/ai's chatbot block does with its default config).
 * An \AssertionError is thrown outright so the pin holds whatever the PHP
 * assertion settings of the site under test.
 */
#[Block(
  id: 'daedalus_blueprint_api_render_test_throwing',
  admin_label: new TranslatableMarkup('Throwing block'),
  category: new TranslatableMarkup('Testing'),
)]
final class ThrowingBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    throw new \AssertionError('The block build failed on purpose.');
  }

}
