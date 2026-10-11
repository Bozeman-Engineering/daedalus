<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_ui\DaedalusUi;

/**
 * The site frame's doors on the admin pages where a theme is managed.
 *
 * A frame is a theme fact (one per theme, keyed by the theme's machine
 * name) with no page of its own: its canonical link is its canvas, a
 * front-end route nothing in the admin UI would otherwise name. The frame
 * must stay reachable regardless of what it happens to render (a portal
 * chip on a frame-owned node is the only door a framed page offers, and an
 * empty or slot-only frame offers none), so this class puts a door on the
 * three admin pages where a site builder looks for a theme's own things:
 *
 * - the theme list (/admin/appearance): a "Site frame" operation beside
 *   Settings and Set as default on the row of every theme with a frame;
 * - the theme settings form (/admin/appearance/settings/{theme}): an item
 *   at the top saying the theme's header, footer and page chrome are
 *   edited in its site frame, with the link;
 * - block layout (/admin/structure/block and /list/{theme}): a warning at
 *   the top of the form. For a framed theme the block module's page
 *   variant is replaced by the frame (FramePageVariant), so block layout
 *   is never consulted and a block placed there never renders; the page
 *   would lie without the warning. Placement stays enabled (the page is
 *   shared with the admin theme, which still runs on block layout) and the
 *   warning carries the link when the actor may open the frame, the plain
 *   sentence when not.
 *
 * Every door derives its URL through DaedalusUi::getEditingUrl(), the one
 * derivation of "where this entity is edited" the AI, the portal stamp and
 * the Open blueprint verb share, and a door shows only when the actor may
 * update the frame and DaedalusUi::hasEditingUrl() holds (the canvas route
 * is daedalus_layout's; the frame entity is daedalus_blueprint's). A theme
 * without a frame gets nothing: there is no create path for a frame here.
 * The fourth door, the Appearance menu link, is a menu link's and not a
 * hook's, so it is not this class's.
 */
final class FrameDoors {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RouteMatchInterface $routeMatch,
    private readonly DaedalusUi $daedalusUi,
  ) {}

  /**
   * Implements hook_system_themes_page_alter().
   *
   * Appends a "Site frame" operation to the row of every theme whose frame
   * the actor may open; core builds the row's own operations before the
   * alter, so the door renders after Set as default.
   */
  #[Hook('system_themes_page_alter')]
  public function themeList(array &$theme_groups): void {
    foreach ($theme_groups as $themes) {
      foreach ($themes as $theme) {
        $url = $this->door($theme->getName());
        if ($url === NULL) {
          continue;
        }
        $theme->operations[] = [
          'title' => $this->t('Site frame'),
          'url' => $url,
          'attributes' => ['title' => $this->t('Edit the site frame of @theme', ['@theme' => $theme->info['name']])],
        ];
      }
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for system_theme_settings.
   *
   * The theme is the form's one build argument (empty on the global
   * settings form, which has no theme and so no frame).
   */
  #[Hook('form_system_theme_settings_alter')]
  public function themeSettings(array &$form, FormStateInterface $form_state): void {
    $theme = (string) ($form_state->getBuildInfo()['args'][0] ?? '');
    $url = $theme === '' ? NULL : $this->door($theme);
    if ($url === NULL) {
      return;
    }
    $form['daedalus_frame'] = [
      '#type' => 'item',
      '#title' => $this->t('Site frame'),
      '#markup' => $this->t('The header, footer and page chrome of @theme are edited in its site frame: <a href=":url">Edit the site frame</a>.', [
        '@theme' => $this->themeHandler->getName($theme),
        ':url' => $url->toString(),
      ]),
      '#weight' => -100,
    ];
    $this->addFrameCacheTags($form);
  }

  /**
   * Implements hook_form_FORM_ID_alter() for block_admin_display_form.
   *
   * The theme is the `theme` route parameter (block.admin_display_theme),
   * else the default theme, the rule core's BlockListController uses for
   * block.admin_display. The warning shows for every theme with a frame,
   * whether or not the actor may open it; only the link is gated.
   */
  #[Hook('form_block_admin_display_form_alter')]
  public function blockLayout(array &$form, FormStateInterface $form_state): void {
    $theme = (string) ($this->routeMatch->getParameter('theme') ?? $this->configFactory->get('system.theme')->get('default'));
    $frame = $this->frame($theme);
    if ($frame === NULL) {
      return;
    }
    $name = $this->themeHandler->getName($theme);
    $url = $this->door($theme);
    $message = $url === NULL
      ? $this->t('@theme renders its site frame instead of block layout. Blocks placed here do not appear on its pages.', ['@theme' => $name])
      : $this->t('@theme renders its site frame instead of block layout. Blocks placed here do not appear on its pages. <a href=":url">Edit the site frame</a>.', [
        '@theme' => $name,
        ':url' => $url->toString(),
      ]);
    $form['daedalus_frame'] = [
      '#theme' => 'status_messages',
      '#message_list' => ['warning' => [$message]],
      '#status_headings' => ['warning' => $this->t('Warning message')],
      '#weight' => -100,
    ];
    $this->addFrameCacheTags($form);
  }

  /**
   * The frame of a theme, or NULL when the theme has none.
   */
  private function frame(string $theme): ?FrameInterface {
    $frame = $this->entityTypeManager->getStorage(FrameInterface::ENTITY_TYPE_ID)->load($theme);
    return $frame instanceof FrameInterface ? $frame : NULL;
  }

  /**
   * The canvas URL of a theme's frame when the actor may open it, else NULL.
   *
   * The one place a theme's openable frame is resolved: the theme has a
   * frame, the actor may update it, and the canvas route exists.
   */
  private function door(string $theme): ?Url {
    $frame = $this->frame($theme);
    if ($frame === NULL || !$frame->access('update') || !$this->daedalusUi->hasEditingUrl($frame)) {
      return NULL;
    }
    return $this->daedalusUi->getEditingUrl($frame);
  }

  /**
   * Makes a form follow the frames that exist.
   */
  private function addFrameCacheTags(array &$form): void {
    $form['#cache']['tags'] = array_merge(
      $form['#cache']['tags'] ?? [],
      $this->entityTypeManager->getDefinition(FrameInterface::ENTITY_TYPE_ID)->getListCacheTags(),
    );
  }

}
