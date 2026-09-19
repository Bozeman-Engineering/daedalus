<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\Sidebar;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Drupal\daedalus_ui\ElementModel\ClientAnatomy;
use Drupal\daedalus_ui\SidebarPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The page-elements panel: the explicit face of the selection spine.
 *
 * A tree of every addressable element on the page (sections, blocks, fields,
 * nested layouts), projected client-side from the blueprint mirror and kept
 * in two-way selection sync with the canvas. It reaches elements with no
 * render seam — the always-available selection path beside canvas click.
 *
 * Tool-independent (tool: NULL): the tree is the persistent face of the
 * page's structure, like Figma's layers panel, so it stays put while tools
 * change. It shares the left rail with the Place Block palette without
 * competing: the persistent tree anchors the top slot and reads
 * top-to-bottom like the document it mirrors; the transient palette opens
 * beneath it (nearer the bottom tool pill) without displacing it, and the
 * tree stretches full height whenever the palette is closed.
 *
 * The build is only a mount point. The client app renders the tree from the
 * blueprint store slice; there is no server-rendered row markup to drift
 * from the model.
 */
#[Sidebar(
  id: 'page_elements',
  label: new TranslatableMarkup('Page elements'),
  side: 'left',
  weight: 10,
  mode: 'edit',
  slot: 'top',
  default_open: TRUE,
)]
final class PageElementsSidebar extends SidebarPluginBase implements ContainerFactoryPluginInterface {

  use ButtonBuilderTrait;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly ClientAnatomy $clientAnatomy,
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
      $container->get('daedalus_ui.client_anatomy'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      // The Show kebab (show-menu.js) is the tree's, rendered client-side;
      // the header lends it a slot in the action cluster so it shares the
      // corner with the close instead of being positioned into it.
      'header' => $this->buildPanelHeader($this->t('Page elements'), [
        'show_menu' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => [
            'id' => 'page-elements-show-menu',
            'class' => ['daedalus-ui-panel__slot'],
          ],
        ],
      ]),
      'tree' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'page-elements-tree',
          'class' => ['daedalus-ui-panel__body'],
        ],
      ],
      '#attached' => [
        // The anatomy payload the markup view composes shells from:
        // declaration-shaped, up front, so the view follows the model
        // without a round trip (the blueprint mirror pattern).
        'drupalSettings' => [
          'daedalusUi' => [
            'anatomy' => $this->clientAnatomy->build(),
          ],
        ],
      ],
      '#cache' => [
        // Anatomy follows the site default theme's declarations (one
        // resolution with the AI schema and the style guide); the tags
        // rebuild the payload when the layered declarations or the
        // default theme change.
        'tags' => ClientAnatomy::CACHE_TAGS,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildToggleButton(): array {
    return $this->buildTopBarButton(
      'daedalus-ui-page-elements',
      $this->t('Page elements'),
      $this->t('Browse and select everything on the page'),
      'daedalus_ui',
      'tree',
      ['data-sidebar-button-for' => '#page_elements'],
    );
  }

}
