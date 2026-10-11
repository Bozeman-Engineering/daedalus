<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Plugin\Sidebar;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Capability\Capability;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_layout\AddPanel\AddPanelFaces;
use Drupal\daedalus_ui\Attribute\Sidebar;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\SidebarPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Add panel: one list of everything the canvas can place.
 *
 * The panel is one list of placeables, each with one face, shown as
 * folding sets: Favorites, each group that holds anything, then All,
 * which holds everything once nested by kind. The server renders a mount
 * and no item markup: it attaches the faces service's answer for the
 * route host (AddPanelFaces: one face per placeable block, the site's
 * group vocabulary, the editor's own marks) as drupalSettings, and the
 * bundled Preact app (js/src/, built to js/dist/add-panel.bundle.js)
 * draws every row from that answer and from the materials store, whose
 * blueprint records carry the same face keys. A blueprint saved by a
 * person, the AI or another window appears in the open panel the moment
 * its delta lands, with no server round trip.
 *
 * A plain, tool-independent panel (there is no place_block TOOL): its
 * rows are always-armed caret drag sources, so dragging out of it works
 * whatever the cursor is doing, and the Insert verb opens it with the
 * selection armed as the pending click-to-place anchor. Present (hidden
 * or open) whenever the route host has a document the actor may insert
 * into somewhere; opened by its top-bar toggle, the Insert verb or the
 * blank-page affordance. The plugin id keeps the historical 'place_block'
 * name: it is the panel's DOM id and cookie identity; renaming is
 * chrome-theme territory.
 *
 * The server's one blueprint contribution is the create grant the client
 * gestures gate on (drupalSettings.daedalusLayout.canSaveBlueprints: may
 * the actor save a blueprint of SOME type, the library's any-type gate;
 * honest chrome, the ops and routes enforce by the blueprint's type for
 * real).
 *
 * The client's hooks are stable data attributes
 * (`data-daedalus-layout-add-panel-*`): the mount, the header's tools
 * slot, the search box, each set heading (its set id, `aria-expanded`),
 * each row (its face id and the set it sits in), the star and the empty
 * sentences. Tests key on those, never on ids or classes.
 */
#[Sidebar(
  id: 'place_block',
  label: new TranslatableMarkup('Blocks'),
  side: 'left',
  // After the page-elements tree (weight 10): the persistent tree owns the
  // first left-rail toggle; the transient Insert panel follows it.
  weight: 12,
  mode: 'edit',
  // The Add panel shares the left side with the page-elements tree
  // (left/top) without evicting it: two slots, both can be open at once. The
  // persistent tree anchors the top and is never displaced; the transient
  // Add panel opens beneath it, nearer the bottom tool pill, and the tree
  // takes the full height whenever the panel is closed.
  slot: 'bottom',
  // Shares its letter with the Insert VERB deliberately: with a selection
  // the verb wins (selection context outranks global) and opens the Add panel
  // anchored there; without one this binding opens the panel. One key, one
  // meaning: insert.
  hot_key: 'i',
)]
final class PlaceBlock extends SidebarPluginBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;
  use ButtonBuilderTrait;

  /**
   * The mount's id: the element the bundle renders the panel into.
   */
  public const MOUNT_ID = 'daedalus-layout-add-panel';

  /**
   * The header slot's id: where the bundle seats the collapse controls.
   */
  public const TOOLS_ID = 'daedalus-layout-add-panel-tools';

  /**
   * The library's any-type gate, or NULL without daedalus_blueprint_library.
   *
   * Untyped on purpose: this module does not depend on the library.
   *
   * @var \Drupal\daedalus_blueprint_library\Access\BlueprintTypeAccess|null
   */
  protected readonly ?object $blueprintTypeAccess;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected DaedalusUi $daedalusUi,
    protected ModuleExtensionList $moduleList,
    protected AccountProxyInterface $currentUser,
    protected VerdictStamper $verdictStamper,
    protected AddPanelFaces $faces,
    ?object $blueprint_type_access = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->blueprintTypeAccess = $blueprint_type_access;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('daedalus_ui.ui'),
      $container->get('extension.list.module'),
      $container->get('current_user'),
      $container->get('daedalus_blueprint.verdict_stamper'),
      $container->get('daedalus_layout.add_panel_faces'),
      $container->get('daedalus_blueprint_library.type_access', ContainerInterface::NULL_ON_INVALID_REFERENCE),
    );
  }

  /**
   * {@inheritdoc}
   *
   * The Add panel places into the route host's document, so it exists
   * only where the host has one (a content entity with its own override
   * storage, a template on its manage-layout route, a saved blueprint's
   * canvas) AND where the actor may insert somewhere: the capability
   * spine's verdict set allows insert on the root or on some element.
   * A plain host renders a foreign document (ownership refuses insert
   * everywhere), and an actor without the bundle's override permission
   * (the role policy) has nowhere to place: neither mounts the panel.
   */
  public function applies(): bool {
    $host = $this->daedalusUi->deriveHostFromRoute();
    return $host !== NULL
      && $host->hasDocument()
      && $this->verdictStamper->allowsAnywhere($host, Capability::Insert);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // The panel owns its assets (the bundle: drag sources, the Insert
    // verb's click-to-place, the rows): sidebar plugins have no attachments
    // seam, and no tool attaches this library. The panel renders
    // present-but-hidden whenever it applies, so the bundle (the Insert
    // verb included) is live before the panel first opens.
    $build['#attached']['library'][] = 'daedalus_layout/add_panel';

    // The shared header grammar (SidebarPluginBase::buildPanelHeader): the
    // panel's one header action is a slot the bundle seats the collapse
    // controls in (daedalus_ui's expand-all and collapse-all pair), so
    // they share the corner with the close the grammar appends.
    $build['header'] = $this->buildPanelHeader($this->t('Blocks'), [
      'tools' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'id' => self::TOOLS_ID,
          'class' => ['daedalus-ui-panel__slot'],
          'data-daedalus-layout-add-panel-tools' => '',
        ],
      ],
    ]);

    // The mount: the bundle renders the search box, the sets and the rows
    // into it. Any wrapper between the panel and its scroll body passes
    // the height chain through (daedalus_ui's sidebar.css), so the mount
    // needs no anatomy of its own.
    $build['mount'] = [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#attributes' => [
        'id' => self::MOUNT_ID,
        'class' => ['daedalus-layout-add-panel'],
        'data-daedalus-layout-add-panel-mount' => '',
      ],
    ];

    // The faces: what this canvas can place, the vocabulary and the
    // editor's marks, as the settings the bundle reads. The answer
    // carries its own cacheability (the actor, the block types, the host's
    // display, the declarations), merged into the build.
    $host = $this->daedalusUi->deriveHostFromRoute();
    $answer = $this->faces->answer($host);
    $answer->cacheMetadata()->applyTo($build);
    $build['#attached']['drupalSettings']['daedalusLayout']['addPanel'] = $answer->toArray() + [
      // The icon a row paints when its face carries none: one default for
      // a block's face and a blueprint's record alike.
      'defaultIcon' => '/' . $this->moduleList->getPath('daedalus_layout') . '/assets/default-block-icon.svg',
    ];

    // With the library module installed the panel attaches the create grant
    // the blueprint gestures (js/verbs/blueprint.js, the panel's row menu)
    // gate on as chrome: may the actor save a blueprint of SOME type (the
    // library's any-type gate; the ops judge the type for real). Without
    // the library there is no gate and no gesture, whatever the account's
    // permissions say (user 1 holds every permission, defined or not).
    if ($this->blueprintTypeAccess !== NULL) {
      $access = $this->blueprintTypeAccess->anyType($this->currentUser, 'create');
      $build['#attached']['drupalSettings']['daedalusLayout']['canSaveBlueprints'] = $access->isAllowed();
      CacheableMetadata::createFromRenderArray($build)->addCacheableDependency($access)->applyTo($build);
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * The panel's user-facing opener (no tool opens it): a standard top-bar
   * toggle beside the page-elements tree's, wired through the shared
   * data-sidebar-button-for behavior.
   */
  public function buildToggleButton(): array {
    return $this->buildTopBarButton(
      'daedalus-layout-add',
      $this->t('Blocks'),
      $this->t('Insert blocks and containers into the page'),
      'daedalus_layout',
      'block',
      ['data-sidebar-button-for' => '#place_block'],
    );
  }

}
