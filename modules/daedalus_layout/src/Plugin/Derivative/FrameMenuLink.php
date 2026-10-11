<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_ui\DaedalusUi;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives one Appearance menu link per site frame.
 *
 * The frame's fourth door: the first three sit on the admin pages where a
 * theme is managed (FrameDoors), this one in the administration menu under
 * Appearance, beside Themes, Skins and Design systems, so the frame is
 * reachable from every admin page regardless of what it renders. One child
 * of system.themes_page per frame, keyed by the frame's id (the theme's
 * machine name), titled "Site frame: @theme" with the theme's human name
 * (the id when the theme is not installed). There is no collection route:
 * one frame per theme makes the theme list the collection.
 *
 * The link's route and parameters are read off DaedalusUi::getEditingUrl(),
 * the one derivation of "where this entity is edited" every door shares,
 * and nothing derives when hasEditingUrl() is false (the canvas route is
 * missing). Access is not derived here: the route's own requirement
 * (daedalus_frame.update) is what core's menu access check applies, so an
 * actor without the permission never sees the link. Menu link definitions
 * live in the menu tree storage and are re-discovered only on a rebuild,
 * so FrameMenuLinkHooks rebuilds them when a frame is born or deleted.
 */
final class FrameMenuLink extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly DaedalusUi $daedalusUi,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('theme_handler'),
      $container->get(DaedalusUi::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    // The deriver instance outlives a rebuild within one request (a frame's
    // delete rebuilds in the request that deleted it), so the list is
    // derived afresh each time rather than accumulated.
    $this->derivatives = [];
    $frames = $this->entityTypeManager->getStorage(FrameInterface::ENTITY_TYPE_ID)->loadMultiple();
    foreach ($frames as $id => $frame) {
      if (!$frame instanceof FrameInterface || !$this->daedalusUi->hasEditingUrl($frame)) {
        continue;
      }
      $url = $this->daedalusUi->getEditingUrl($frame);
      $theme = $this->themeHandler->themeExists($id) ? $this->themeHandler->getName($id) : $id;
      $this->derivatives[$id] = [
        'title' => new TranslatableMarkup('Site frame: @theme', ['@theme' => $theme]),
        'route_name' => $url->getRouteName(),
        'route_parameters' => $url->getRouteParameters(),
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
