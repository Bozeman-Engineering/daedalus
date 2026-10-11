<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Entity;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\layout_builder\SectionListInterface;

/**
 * The site frame of a theme: a page document rendered around every route.
 *
 * One frame per theme, keyed by the theme's machine name. The frame is a
 * config entity that IS its own section list (as a Layout Builder enabled
 * display is), so its document is config: its inline blocks are value
 * blocks, it has no revisions and lives in no workspace, and it ships with
 * the theme. Its style map lives in its daedalus_stylish third-party
 * settings, the display host's pattern. The spine reads the document
 * through the frame host (LayoutBuilder\FrameHost) and the `frame`
 * section storage plugin; nothing else names the section list.
 */
interface FrameInterface extends ConfigEntityInterface, SectionListInterface {

  /**
   * The entity type id of a site frame.
   */
  public const ENTITY_TYPE_ID = 'daedalus_frame';

  /**
   * The one permission over the frame: the site builder's.
   *
   * Editing, styling and deleting the frame, and the frame's canvas, all
   * ask it; a visitor needs nothing to see the frame rendered.
   */
  public const PERMISSION = 'configure the site frame';

  /**
   * The route of the frame's canvas: its canonical link.
   *
   * A frame has no public page; its canonical route IS its editing surface
   * (the shape a saved blueprint's canonical route has), so every road to
   * "where the frame is edited" (the portal from any page in Edit Mode, the
   * AI's link, the address bar) is a plain navigation to this route, and
   * daedalus_ui's one editing-url derivation (DaedalusUi::getEditingUrl())
   * answers the entity's canonical url for a frame as for any content
   * entity. The route itself is daedalus_layout's (the canvas is the
   * layout_builder_plus element over the frame storage); without that
   * module the link template names a route that does not exist, which
   * DaedalusUi::hasEditingUrl() reports.
   */
  public const CANVAS_ROUTE = 'entity.' . self::ENTITY_TYPE_ID . '.canonical';

  /**
   * The machine name of the theme the frame is for: the entity id.
   */
  public function theme(): string;

}
