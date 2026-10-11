<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_ui\ModeState;

/**
 * The site frame's canvas: the frame's canonical route.
 *
 * A frame has no public page of its own (it is seen around every page of
 * its theme), so its canonical route is its editing surface, the shape a
 * saved blueprint's canonical route has and core's manage-display layout
 * route has for a template. The canvas is the one canvas element every
 * host is edited through (layout_builder_plus, the layout root over the
 * host's section storage, the canvas root wrapper and the blueprint
 * mirror riding it), built here exactly as EditableUiBuilder builds it
 * for the mutation re-render and LoadEditablePage, so the page load and
 * every swap agree. The frame's document renders in the canvas posture
 * under the frame render's own contexts (LayoutBuilderPlus::
 * renderContexts()), its main slot showing a stand-in for the route's
 * output, since on this route the frame is the page's content and no
 * route output exists.
 *
 * In Edit Mode the host is the working copy (the staged frame while a
 * stage exists), the same door the spine's endpoints load through; out of
 * it the saved frame renders, read-only, as a page out of Edit Mode shows
 * its saved content. The first visit in a session lands in Edit Mode
 * (FrameCanvasMode). Access is the frame's `update`, the one frame
 * permission; the frame's own theme renders the canvas
 * (FrameCanvasTheme), so the frame is edited in the skin it is seen in.
 */
final class FrameCanvas extends ControllerBase {

  use AutowireTrait;

  public function __construct(
    private readonly HostResolver $hostResolver,
    private readonly EntityStorageHandler $storageHandler,
    private readonly ModeState $modeState,
  ) {}

  /**
   * Builds the frame canvas.
   *
   * @param \Drupal\daedalus_blueprint\Entity\FrameInterface $daedalus_frame
   *   The frame, upcast by the route.
   *
   * @return array
   *   The canvas element.
   */
  public function build(FrameInterface $daedalus_frame): array {
    $host = $this->modeState->getMode() === 'edit'
      ? ($this->storageHandler->load(FrameInterface::ENTITY_TYPE_ID, (string) $daedalus_frame->id()) ?? $this->hostResolver->fromEntity($daedalus_frame))
      : $this->hostResolver->fromEntity($daedalus_frame);
    return [
      '#type' => 'layout_builder_plus',
      '#section_storage' => $host->sectionStorage(),
      '#host' => $host,
      '#canvas_root' => TRUE,
      // The canvas is the user's own editing session: never a shared cache
      // entry.
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * The route's title: the frame named by its theme.
   *
   * @param \Drupal\daedalus_blueprint\Entity\FrameInterface $daedalus_frame
   *   The frame.
   */
  public function title(FrameInterface $daedalus_frame): TranslatableMarkup {
    return $this->t('Site frame: @theme', ['@theme' => $daedalus_frame->label()]);
  }

}
