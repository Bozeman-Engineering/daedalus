<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Block content edit.
 *
 * The standalone editing surface for a block_content entity whose display is
 * managed by Layout Builder, e.g. a footer placed in a theme region. The
 * canvas is the entity view itself (the same shape as node editing), so
 * entity param conversion (tempstore hydration), entity access, and
 * mutation-response markup all work the standard content entity way.
 */
class BlockContentEdit extends ControllerBase {

  /**
   * Render the block content entity as the editing canvas.
   */
  public function render(Request $request, EntityInterface $block_content, string $view_mode = 'full') {
    $session = $request->getSession();
    $flag = "block_content_edit_mode:{$block_content->id()}";
    if (!$session->get($flag, FALSE)) {
      // Enter Edit Mode on the first visit only, so users are still able to
      // exit Edit Mode afterward. Two halves: mutating the request cookie bag
      // makes THIS response render the edit chrome (page_top builds after the
      // controller runs), and the queued path-scoped Set-Cookie (emitted by
      // InitialMode::setResponseCookies) arms the client boot gate and every
      // subsequent request. The request bag mutation alone never reaches the
      // browser.
      $request->cookies->set('navigationMode', 'edit');
      $session->set('daedalus_ui_set_mode_cookie', [
        // Generated without the trailing default {view_mode} segment, so the
        // cookie path prefix-matches explicit view mode variants too.
        'path' => Url::fromRoute('daedalus_ui.block_content.edit', ['block_content' => $block_content->id()])->toString(),
        'mode' => 'edit',
      ]);
      $session->set($flag, TRUE);
    }

    return $this->entityTypeManager()->getViewBuilder($block_content->getEntityTypeId())->view($block_content, $view_mode);
  }

  /**
   * Page title callback.
   *
   * The bare label, nothing else: PageTitleEditing targets the page title as
   * the entity label's inline editing surface, so the title text must be
   * exactly the label.
   */
  public function title(Request $request, EntityInterface $block_content, string $view_mode = 'full') {
    return $block_content->label();
  }

}
