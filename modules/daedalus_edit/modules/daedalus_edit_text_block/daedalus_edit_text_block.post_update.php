<?php

/**
 * @file
 * Post update functions for daedalus_edit_text_block.
 */

use Drupal\field\Entity\FieldStorageConfig;

/**
 * Allow h1 as a text block tag.
 *
 * A text block may be the page's one h1: the theme's page title does not
 * own the tag (daedalus_edit's PageTitleEditing one-render rule is about the
 * label field's identity, not the h1 element). The shipped install config
 * carries h1 for new installs; existing sites widen the installed storage
 * here. h1 is prepended so the tag picker and the AI catalog read the
 * heading ladder in order (h1..h6, span, p).
 */
function daedalus_edit_text_block_post_update_allow_h1_tag(): ?string {
  $storage = FieldStorageConfig::loadByName('block_content', 'field_text_tag');
  if (!$storage) {
    return t('No field_text_tag storage on this site; nothing to widen.');
  }
  $allowed_values = $storage->getSetting('allowed_values');
  if (isset($allowed_values['h1'])) {
    return t('The text block already allows the h1 tag.');
  }
  $storage->setSetting('allowed_values', ['h1' => 'H1'] + $allowed_values)->save();

  return t('The text block now allows the h1 tag.');
}
