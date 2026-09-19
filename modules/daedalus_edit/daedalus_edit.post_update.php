<?php

/**
 * @file
 * Post update functions for the Daedalus Edit module.
 */

/**
 * Mint s-* identity classes onto existing formatted text content.
 */
function daedalus_edit_post_update_mint_identity_classes(&$sandbox = NULL) {
  /** @var \Drupal\daedalus_edit\IdentityBackfill $backfill */
  $backfill = \Drupal::service('daedalus_edit.identity_backfill');
  if (!isset($sandbox['types'])) {
    $sandbox['types'] = $backfill->entityTypeIds();
    $sandbox['after'] = NULL;
    $sandbox['saved'] = 0;
  }

  if ($sandbox['types'] !== []) {
    $type = reset($sandbox['types']);
    $result = $backfill->processChunk($type, $sandbox['after'], 50);
    $sandbox['saved'] += $result['saved'];
    if ($result['processed'] < 50) {
      array_shift($sandbox['types']);
      $sandbox['after'] = NULL;
    }
    else {
      $sandbox['after'] = $result['last_id'];
    }
  }

  $sandbox['#finished'] = $sandbox['types'] === [] ? 1 : 0;
  if ($sandbox['#finished'] === 1) {
    return t('Minted identity classes onto @count existing entities.', ['@count' => $sandbox['saved']]);
  }
}
