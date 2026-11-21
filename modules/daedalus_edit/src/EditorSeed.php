<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit;

use Drupal\editor\Element;
use Drupal\filter\FilterFormatInterface;

/**
 * The HTML an inline editor is seeded with.
 *
 * A canvas editor edits a field's value on the page itself, so what it is
 * seeded with becomes live markup in the viewer's browser before the
 * editor takes it over. This is the one place that markup is produced, for
 * every road to a canvas editor: the hosted form's textarea
 * (InlineEditorElement) and the editor settings a blueprint-edited block
 * stamps on its root. It is core's own rule for a text_format element: the
 * value passes the text editor's filter for the format the editor opens
 * on, and is handed back as it is where core applies none (the format has
 * no text editor, its editor guarantees safe markup itself, or the format
 * restricts no HTML and so prints the value as it is anyway). A value
 * whose format no longer loads has no editor to open, and is handed back
 * as it is too.
 *
 * Reading a seed changes nothing that is stored: the value keeps its bytes
 * until the viewer commits an edit.
 */
final class EditorSeed {

  public function __construct(
    protected Element $editorElement,
  ) {}

  /**
   * Produces the seed of a value for an editor on a text format.
   *
   * @param string $html
   *   The stored value.
   * @param \Drupal\filter\FilterFormatInterface|null $format
   *   The text format the editor opens on, or NULL when the value's format
   *   does not load (core's form then offers the selector with none chosen).
   *
   * @return string
   *   The HTML to seed the editor with.
   */
  public function seed(string $html, ?FilterFormatInterface $format): string {
    if (method_exists($this->editorElement, 'filterXss')) {
      $filtered = $this->editorElement->filterXss($html, $format);
    }
    else {
      // Core 11.3 compatibility: the function is deprecated on 11.4, where
      // this branch is never reached.
      // @phpstan-ignore function.deprecated
      $filtered = editor_filter_xss($html, $format);
    }
    return $filtered === FALSE ? $html : $filtered;
  }

}
