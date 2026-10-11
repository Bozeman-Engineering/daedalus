<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Render;

use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The stand-in a block that rendered nothing wears on the editing canvas.
 *
 * In the editing posture (core's preview flag on the section render) every
 * placed block has a body: core bypasses block access and, for a block
 * whose build is empty, prints its own placeholder ("Placeholder for the
 * "X" block", a TranslatableMarkup carrying the `@preview_fallback`
 * argument) as the `#markup` of that empty build with the label stamped as
 * `data-layout-content-preview-placeholder-label`. Core's stylesheet for
 * that text never loads under Daedalus and the sentence names no reason,
 * so LayoutBuilderBlockAttributes replaces that content with this
 * stand-in: the block's label and one reason sentence, the reason also
 * stamped as a state attribute on the block root (ATTRIBUTE) beside the
 * selectable key, the presentation and core's label attribute, which
 * stays.
 *
 * Two reasons for a block that built nothing: the block's own access
 * answer for the editor is forbidden (VISITORS_ONLY, "Shown to visitors
 * only": a login block for a signed-in editor), else the build was
 * simply empty (EMPTY, "Nothing to show here yet": a menu with no
 * links). "Needs configuration" is not a reason: nothing can know a
 * block's defaults cannot render without building its form, and
 * Configure is offered on every block regardless. A slot block that
 * nothing fills is not a block that failed to build but a door standing
 * open or shut, and wears its own two states: SLOT_OPEN where the route
 * host may fill the slot from this canvas (the spine's insert verdict on
 * the door: a page of the template, with the permission), SLOT where it
 * may not (the template's own canvas, an editor without the road).
 *
 * Detection is structural (isPlaceholder), never by the English string:
 * the string is a translation, the argument is core's API shape for that
 * build. The visitor posture never meets this class: there core drops an
 * empty or forbidden block as a cache-only stub before the subscriber's
 * empty skip.
 */
final class BlockStandIn {

  /**
   * The DOM state attribute carrying the stand-in's reason.
   *
   * Stamped on the block template root beside the selectable key and
   * Presentation::ATTRIBUTE, carrying VISITORS_ONLY or EMPTY; its
   * presence means "this block's content is the stand-in, not the block's
   * own build". Editor chrome, never a visitor's byte: the editing posture
   * alone produces it.
   */
  public const ATTRIBUTE = 'data-block-stand-in';

  /**
   * The reason when the block's own access answer for the editor is no.
   */
  public const VISITORS_ONLY = 'visitors-only';

  /**
   * The reason when the block is reachable but built nothing.
   */
  public const EMPTY = 'empty';

  /**
   * The state of an empty slot the route host may fill from this canvas.
   */
  public const SLOT_OPEN = 'slot-open';

  /**
   * The state of an empty slot the route host may not fill from here.
   */
  public const SLOT = 'slot';

  /**
   * The stand-in's root class: daedalus_stylish's CHROME_SELECTOR names it.
   */
  public const CLASS_NAME = 'daedalus-ui-block-stand-in';

  /**
   * Whether a block build's content is core's preview placeholder.
   *
   * Core's BlockComponentRenderArray sets `#markup` on the block's own
   * empty build, a TranslatableMarkup whose arguments carry
   * `@preview_fallback`, beside whatever cache metadata the block built
   * (a menu block with no links builds `#cache` alone): so the shape is
   * that markup over content that is otherwise empty by core's own
   * emptiness test, the one that admitted it to the placeholder branch.
   * Anything else (a block's own markup, a built tree, a plain string) is
   * the block's content and stays.
   *
   * @param array $content
   *   The build's `content` child.
   */
  public static function isPlaceholder(array $content): bool {
    $markup = $content['#markup'] ?? NULL;
    return $markup instanceof TranslatableMarkup
      && array_key_exists('@preview_fallback', $markup->getArguments())
      && Element::isEmpty(array_diff_key($content, ['#markup' => TRUE]));
  }

  /**
   * The stand-in render array for a block.
   *
   * @param string $label
   *   The block's label (the placement's configured label, else the
   *   plugin's admin label), printed escaped.
   * @param string $reason
   *   VISITORS_ONLY, EMPTY, SLOT_OPEN or SLOT.
   */
  public static function build(string $label, string $reason): array {
    $sentence = match ($reason) {
      self::VISITORS_ONLY => new TranslatableMarkup('Shown to visitors only'),
      self::SLOT_OPEN => new TranslatableMarkup('Empty slot. Drag a block here, or select the slot and insert.'),
      self::SLOT => new TranslatableMarkup('Empty slot, filled by each page.'),
      default => new TranslatableMarkup('Nothing to show here yet'),
    };
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="{{ class }}"><span class="{{ class }}__label">{{ label }}</span><span class="{{ class }}__reason">{{ reason }}</span></div>',
      '#context' => [
        'class' => self::CLASS_NAME,
        'label' => $label,
        'reason' => $sentence,
      ],
    ];
  }

}
