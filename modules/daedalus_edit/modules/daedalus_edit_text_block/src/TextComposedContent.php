<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit_text_block;

use Drupal\block_content\BlockContentInterface;
use Drupal\Component\Serialization\Json;
use Drupal\daedalus_ui\Render\ComposedBlockContentInterface;

/**
 * The text block's content for the generative composed render.
 *
 * Structure is the declaration's (the tag root, the conditional anchor
 * part); this provider supplies what only the module knows: the escaped
 * text value, the typing-surface marker, and the editor attribution.
 *
 * data-daedalus-edit-text always marks the contenteditable typing surface, and
 * it rides the DECLARED element the text mounts in (no editing-only
 * elements): the anchor while the text is a link (the anchor claims the
 * text in the declaration), the root itself while it is not — the mounting
 * rule resolves the placement, so the marker follows the text with no
 * branch spelling here.
 *
 * The editor attribution (data-daedalus-edit-editor + -settings) is identity
 * and settings only, never values (the editor reads values from the
 * blueprint mirror), and only prints on edit-capable renders: live visitor
 * markup stays clean.
 */
final class TextComposedContent implements ComposedBlockContentInterface {

  /**
   * {@inheritdoc}
   */
  public function blockType(): string {
    return 'text';
  }

  /**
   * {@inheritdoc}
   */
  public function content(BlockContentInterface $block_content, array $resolved, bool $edit_capable): array {
    // The element the text mounts in: the anchor when it claims the slot
    // (linked), the root when the claim is gone (unlinked).
    $mount = $resolved['slots']['field:field_text_text'] ?? '';
    $content = [
      'children' => [
        'field:field_text_text' => [
          '#plain_text' => $block_content->get('field_text_text')->value ?? '',
        ],
      ],
      'attributes' => [
        $mount => ['data-daedalus-edit-text' => ''],
      ],
    ];
    if ($edit_capable) {
      $storage = $block_content->get('field_text_tag')->getFieldDefinition()->getFieldStorageDefinition();
      $content['attributes'][''] = ($content['attributes'][''] ?? []) + [
        'data-daedalus-edit-editor' => 'text_composite',
        'data-daedalus-edit-editor-settings' => Json::encode([
          'tag_allowed_values' => options_allowed_values($storage, $block_content),
        ]),
      ];
    }
    return $content;
  }

}
