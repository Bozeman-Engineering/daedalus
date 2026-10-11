<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Frame;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ui\Render\BlockStandIn;

/**
 * The stand-in for the route's output in the frame's main slot.
 *
 * The sentence saying where each page's content lands, in the block
 * stand-in's dress, printed where no route output reaches the main slot:
 * on the frame's own canvas (the frame is the page's content there, so
 * the canvas element hands it as the route output itself) and on a
 * blueprint's canvas rendered in situ (the door whose default is the
 * blueprint hosts the route output, InnerRender::routeSlot(), and the
 * page display variant hands this on RouteOutput for main to print). One
 * spelling for both roads, so the two canvases read the same sentence.
 */
final class MainContentStandIn {

  /**
   * Builds the stand-in.
   *
   * @return array
   *   The render array.
   */
  public static function build(): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="{{ class }} {{ class }}--main-content"><span class="{{ class }}__label">{{ label }}</span><span class="{{ class }}__reason">{{ reason }}</span></div>',
      '#context' => [
        'class' => BlockStandIn::CLASS_NAME,
        'label' => new TranslatableMarkup('Main content'),
        'reason' => new TranslatableMarkup("Each page's content shows here."),
      ],
    ];
  }

}
