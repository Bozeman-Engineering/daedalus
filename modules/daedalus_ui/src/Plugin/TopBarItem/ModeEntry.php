<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\TopBarItem;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\navigation\Attribute\TopBarItem;
use Drupal\navigation\TopBarItemBase;
use Drupal\navigation\TopBarRegion;
use Drupal\daedalus_ui\ModePluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the mode entry buttons in the core navigation top bar.
 *
 * The editing chrome takes over the window while a mode is active, so the way
 * in lives in core's chrome: each applicable mode renders its toggle button as
 * a page action ("Edit Mode" on page-builder pages), featured ahead of the
 * More-actions dropdown that the entity form's Edit task returns to (see
 * daedalus_ui_preprocess_top_bar_page_actions()). The way out is the same
 * button rendered in the mode's own top bar.
 */
#[TopBarItem(
  id: 'daedalus_ui_mode_entry',
  region: TopBarRegion::Actions,
  label: new TranslatableMarkup('Mode entry'),
  weight: -1,
)]
final class ModeEntry extends TopBarItemBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ModePluginManager $modeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.modes'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $build = [
      '#cache' => [
        // Mode applicability is per-route and permission-vetoed
        // (ShouldNotEditMode); the button's active state reads the cookie.
        // url.path matters beyond the route: the front page and its node
        // alias share one route, but edit_front_page applies only on "/" —
        // without the context, whichever alias renders first would fix the
        // button set for all of them.
        'contexts' => ['route', 'url.path', 'user.permissions', 'daedalus_ui_mode'],
      ],
    ];
    foreach ($this->modeManager->getModePlugins() as $mode_id => $mode) {
      $build["{$mode_id}_button"] = $mode->buildModeButton();
    }
    return $build;
  }

}
