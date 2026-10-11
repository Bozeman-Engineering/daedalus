<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui_stand_in_test\Plugin\Block;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A block forbidden to every account whose build is empty.
 *
 * The stand-in's VISITORS_ONLY leg: in the editing posture core bypasses
 * the access check and gives the empty build its placeholder; daedalus_ui
 * asks the block itself and prints "Shown to visitors only". In the
 * visitor posture core drops it on access alone.
 */
#[Block(
  id: 'daedalus_ui_stand_in_test_forbidden',
  admin_label: new TranslatableMarkup('Forbidden block'),
  category: new TranslatableMarkup('Testing'),
)]
final class ForbiddenEmptyBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account): AccessResult {
    return AccessResult::forbidden('Forbidden on purpose.');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [];
  }

}
