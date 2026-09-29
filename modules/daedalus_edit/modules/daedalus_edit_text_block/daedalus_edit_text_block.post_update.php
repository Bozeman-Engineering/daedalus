<?php

/**
 * @file
 * Post update functions for daedalus_edit_text_block.
 */

use Drupal\field\Entity\FieldConfig;
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

/**
 * Default the text block's tag to p.
 *
 * A fresh text block (a palette drop, an AI add_element with sample
 * population) is an unlinked paragraph: the sample ladder's placement
 * entrance fills a field from its default value, else its configured
 * sample policy, else nothing, and the tag field's p default is what makes
 * the tag explicit rather than the declaration's residual p. The shipped
 * install config carries the default for new installs; existing sites set
 * it here. A site that already chose a default keeps its choice.
 */
function daedalus_edit_text_block_post_update_default_tag_p(): ?string {
  $field = FieldConfig::loadByName('block_content', 'text', 'field_text_tag');
  if (!$field) {
    return t('No field_text_tag on the text block type; nothing to default.');
  }
  $default = $field->getDefaultValueLiteral();
  if ($default) {
    return t('The text block tag already defaults to @tag.', ['@tag' => $default[0]['value'] ?? '']);
  }
  $field->setDefaultValue([['value' => 'p']])->save();

  return t('The text block tag now defaults to p.');
}
