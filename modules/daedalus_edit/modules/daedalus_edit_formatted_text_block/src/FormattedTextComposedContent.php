<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit_formatted_text_block;

use Drupal\block_content\BlockContentInterface;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_ui\Render\ComposedBlockContentInterface;

/**
 * The formatted text block's content for the generative composed render.
 *
 * Structure is the declaration's (one plain div root, because the filtered
 * output is a fragment of block-level elements with no single natural tag
 * of its own); this provider supplies what only the module knows: the
 * FILTERED (processed) output — the raw {value, format} pair never reaches
 * visitor markup — and the CKEditor binding.
 *
 * The editor attribution is identity and settings only, never values (the
 * editor reads the raw pair from the blueprint mirror), and only prints on
 * edit-capable renders: live visitor markup stays clean. The renderer
 * emits the root; the editor binding here is what makes that root the
 * surface the inline CKEditor attaches to in edit mode.
 */
final class FormattedTextComposedContent implements ComposedBlockContentInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected PluginManagerInterface $editorManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function blockType(): string {
    return 'formatted_text';
  }

  /**
   * {@inheritdoc}
   */
  public function content(BlockContentInterface $block_content, array $resolved, bool $edit_capable): array {
    $content = [];
    /** @var \Drupal\text\Plugin\Field\FieldType\TextLongItem $item */
    $item = $block_content->get('field_formatted_text_text')->first();
    if ($item !== NULL) {
      // processed_text owns the filter pipeline's cacheability and lazy
      // placeholders.
      $content['children']['field:field_formatted_text_text'] = [
        '#type' => 'processed_text',
        '#text' => $item->value,
        '#format' => $item->format,
        '#langcode' => $block_content->language()->getId(),
      ];
    }
    if (!$edit_capable) {
      return $content;
    }

    // The gesture's text format: the value's stored format, or the default
    // format the field would get for the acting user while the field is
    // still empty. The stamp is identity for the editor's fallback; the
    // value's own format (read from the mirror) wins when they disagree.
    $format = $item?->format ?? filter_default_format();
    // The format picker widget and a mid-gesture switch both work from
    // what is stamped here: every text format the acting user may use that
    // has a text editor configured, in the formats' weight order. Editor-less
    // formats are excluded from this widget by design (a loud failure beats a
    // secret degraded mode), and the blueprint spine rejects inaccessible
    // formats on write regardless of what any client sends.
    $formats = [];
    foreach (filter_formats() as $candidate) {
      if ($candidate->access('use')) {
        $formats[$candidate->id()] = $candidate->label();
      }
    }
    $editors = $this->entityTypeManager->getStorage('editor')->loadMultiple(array_keys($formats));
    $formats = array_intersect_key($formats, $editors);
    $content['attributes'][''] = [
      'data-daedalus-edit-editor' => 'formatted_text',
      'data-daedalus-edit-editor-settings' => Json::encode([
        'format' => $format,
        'formats' => $formats,
      ]),
    ];
    // The CKEditor libraries and per-format settings, attached form-free for
    // ALL the listed formats so a format switch needs no fetch. On the form
    // path the text_format element attaches these; the blueprint path has no
    // form, so the edit-capable render itself carries them, and every
    // mutation markup swap re-attaches them (AJAX merges attachments),
    // keeping the settings current when the stored format changes.
    $attachments = $this->editorManager->getAttachments(array_keys($formats));
    if ($attachments !== []) {
      $content['attached'] = $attachments;
    }
    // The editor settings carry per-session CSRF'd URLs (uploads, previews),
    // so an edit-capable render must never be served from a shared cache.
    $content['cache']['max-age'] = 0;
    return $content;
  }

}
