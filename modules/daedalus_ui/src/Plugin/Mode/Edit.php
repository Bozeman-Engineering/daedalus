<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\Mode;

use Drupal\Core\Access\AccessManagerInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_tempstore\History\HistoryManager;
use Drupal\daedalus_ui\EditSessionScope;
use Drupal\daedalus_ui\ModeInterface;
use Drupal\daedalus_ui\ModePluginBase;
use Drupal\daedalus_ui\Attribute\Mode;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\daedalus_ui\SidebarPluginManager;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ui\Event\ShouldNotEditModeEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Edit mode plugin.
 *
 * Edit Mode has one pointer and one keyboard grammar over the one
 * selection (daedalus_ui's selection grammar); editing is a state of the
 * selection, opened by gesture. There are no tools: the mode's chrome is
 * the top bar (the Daedalus menu, panel toggles, the document sentence
 * with Save, the exit control) and the mode-bound sidebar panels, whose
 * build() arrays carry every module's Edit Mode assets.
 */
#[Mode(
  id: 'edit',
  label: new TranslatableMarkup('Edit Mode'),
  weight: 100,
)]
final class Edit extends ModePluginBase {

  use StringTranslationTrait;
  use ButtonBuilderTrait;

  public function __construct(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ModuleExtensionList $extensionList,
    protected SidebarPluginManager $sidebarManager,
    protected RouteMatchInterface $routeMatch,
    protected DaedalusUi $ui,
    protected EventDispatcherInterface $eventDispatcher,
    protected EditSessionScope $editSessionScope,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccessManagerInterface $accessManager,
    protected HistoryManager $historyManager,
  ) {
    parent::__construct($container, $configuration, $plugin_id, $plugin_definition, $this->extensionList, $ui);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('extension.list.module'),
      $container->get('plugin.manager.daedalus_ui_sidebars'),
      $container->get('current_route_match'),
      $container->get('daedalus_ui.ui'),
      $container->get('event_dispatcher'),
      $container->get('daedalus_ui.edit_session_scope'),
      $container->get('entity_type.manager'),
      $container->get('access_manager'),
      $container->get('daedalus_tempstore.history_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    $host = $this->ui->deriveHostFromRoute();
    $event = $this->eventDispatcher->dispatch(new ShouldNotEditModeEvent($host), ShouldNotEditModeEvent::class);
    return !$event->shouldNotEdit();
  }

  /**
   * {@inheritdoc}
   */
  public function addAttachments(array &$attachments): void {
    $attachments['library'][] = 'daedalus_ui/edit_mode';
    // The chrome density spine: any module's chrome (in any sidebar or the
    // top bar) may carry data-daedalus-ui-conceal, so the reveal engine and its
    // shared CSS ride the mode, not each registrant.
    $attachments['library'][] = 'daedalus_ui/reveal';
    // The responsive preview window's one opener
    // (Drupal.Daedalus.openPreviewWindow): the top bar menu's "Open preview
    // window" row and daedalus_stylish's chip row both call it, so the door
    // rides the mode; a surface without the mode declares it itself.
    $attachments['library'][] = 'daedalus_ui/preview_window';
  }

  /**
   * {@inheritdoc}
   */
  public function getIconPath(): string {
    $path = $this->extensionList->getPath('daedalus_ui');
    return "/$path/assets/code.svg";
  }

  /**
   * {@inheritdoc}
   */
  public function buildBars(array &$page_top, ModeInterface $mode): void {
    $this->buildTopBar($page_top, $mode);
    $this->attachInitialMode($page_top);
    $page_top['#markup'] = '<div id="daedalus-root"></div>';
  }

  /**
   * Delivers the bundle's initial mode to the client.
   *
   * A bundle configured to open new entities in a mode (the bundle form's
   * "Initial mode") tells the client so on every render of the mode's
   * chrome: this runs whenever the mode applies, not only while it is
   * active, since entering Edit Mode client-side does not re-render the
   * page.
   *
   * @param array $page_top
   *   The page_top render array, the attachment target.
   */
  protected function attachInitialMode(array &$page_top): void {
    $host = $this->ui->deriveHostFromRoute();
    if ($host === NULL) {
      return;
    }
    // The bundle is the DOCUMENT's kind (a template host's target bundle,
    // a content host's own), read through the host's display; a host with
    // no display (the site frame) has no bundle to carry the setting.
    $display = $host->display();
    if ($display === NULL) {
      return;
    }
    $bundle_entity_type = $this->entityTypeManager->getDefinition($display->getTargetEntityTypeId())->getBundleEntityType();
    if (!$bundle_entity_type) {
      return;
    }
    $bundle = $this->entityTypeManager->getStorage($bundle_entity_type)->load($display->getTargetBundle());
    $initial_mode = $bundle->getThirdPartySetting('daedalus_ui', 'initial_mode', 'none');
    if ($initial_mode !== 'none') {
      $page_top['#attached']['drupalSettings']['daedalusUi']['initialMode'] = $initial_mode;
    }
  }

  /**
   * Builds the top bar: the quiet bar's three zones.
   *
   * Left: the Daedalus menu trigger, then the left-docked panels' toggles.
   * Middle: the document sentence (the host's label, and its kind when the
   * document is not a page) and Save, the one unsaved-changes indicator.
   * Right: the right-docked panels' toggles, then the exit control. The
   * bar names no gesture of its own: everything it does not show (Refresh,
   * Discard, the root's `document` verbs, the preview window, the settings
   * door, the panels without a slot) is a row of the menu the trigger
   * opens (tool_manager/top-bar.js, riding the shared menu primitive over
   * drupalSettings.daedalusUi.topBar).
   *
   * @param array $page_top
   *   The page top render array.
   * @param \Drupal\daedalus_ui\ModeInterface $mode
   *   The mode plugin.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function buildTopBar(array &$page_top, ModeInterface $mode): void {
    $top_bar = [];
    $host = $this->ui->deriveHostFromRoute();

    // The menu trigger conceals at clean density like a panel toggle: at
    // clean the bar shows only orientation (which document, saved?) and the
    // way out. The document sentence, Save and the exit never conceal.
    $left_toggles = [
      'menu' => $this->buildMenuTrigger(
        'daedalus-ui-menu',
        $this->t('Daedalus'),
        $this->t('Open the Daedalus menu'),
        'daedalus_ui',
        'daedalus',
        ['data-daedalus-ui-conceal' => 'clean'],
      ),
    ];
    $right_toggles = [];
    // Every panel of this mode for the menu's Panels rows, toggle or not: a
    // panel that takes no bar slot (daedalus_explode) is still a panel.
    $panels = [];

    // The edit session's identity, delivered once: editSession.scope is
    // the BroadcastChannel scope (EditSessionScope: entity uuid + langcode
    // + workspace) every wire of this session pairs on (the materials
    // relay, the preview ping, the stylish pop-out leader, the pop-out
    // keyboard relay) and every pop-out opener forwards as ?scope. It needs
    // a route host; without one there is no session to pair with, and no
    // canvas to pose: the canvas state vector spine (width / scheme /
    // motion) rides the same gate. Headless: the posing UI is the
    // daedalus_stylish inspector's chip row, driving the CanvasVector
    // facade; this attach only delivers the store, the media emulation,
    // the drag handles, the ruler, and the ?canvas* boot parsing. The
    // responsivePreview url feeds the one preview window opener
    // (Drupal.Daedalus.openPreviewWindow, js/responsive_preview/open-window.js;
    // the chip row's width menu and the top bar menu call it), which
    // appends the edited page's path at click time: the same page can be
    // reached at several URLs; the click knows the real one.
    $scope = $host !== NULL ? $this->editSessionScope->derive($host->entity()) : NULL;
    if ($scope !== NULL) {
      $page_top['#attached']['library'][] = 'daedalus_ui/canvas_vector';
      $page_top['#attached']['drupalSettings']['daedalusUi']['editSession'] = [
        'scope' => $scope,
      ];
      $page_top['#attached']['drupalSettings']['daedalusUi']['responsivePreview'] = [
        'url' => Url::fromRoute('daedalus_ui.responsive_preview')->toString(),
      ];
    }

    // Pull the open/close toggle buttons from the sidebars bound to this mode,
    // plus the no-mode (always-on) panels: their standalone floating launcher
    // is hidden while a mode top bar is present, so the top bar hosts their
    // toggle here. Each button's active state mirrors its panel via
    // isSidebarVisible().
    foreach ($this->sidebarManager->getSidebars() as $id => $sidebar) {
      $definition = $sidebar->getPluginDefinition();
      $sidebar_mode = $definition['mode'] ?? NULL;
      if (!in_array($sidebar_mode, [$this->getPluginId(), NULL], TRUE)) {
        continue;
      }
      $side = $definition['side'] ?? 'right';
      $panels[] = [
        'id' => $id,
        'label' => (string) $definition['label'],
        'side' => $side,
      ];
      $button = $sidebar->buildToggleButton();
      if (!empty($button)) {
        // At clean density an inactive panel toggle conceals until the
        // pointer nears; a toggle whose panel is open keeps its `active`
        // class and stays visible through top-bar.css's exception (the open
        // panel's switch is orientation, not clutter). The exit control
        // below never conceals: it is the escape hatch and the one signal
        // that edit mode is on.
        $button['wrapper']['#attributes']['data-daedalus-ui-conceal'] = 'clean';
        $slot = $definition['slot'] ?? 'top';
        $button['#cache']['contexts'] = ['user.permissions', "cookies:{$side}_{$slot}_sidebar"];
        if ($this->ui->isSidebarVisible($id, $side, $slot, $sidebar_mode, $definition['default_open'] ?? FALSE)) {
          $button['wrapper']['#attributes']['class'][] = 'active';
        }
        if ($side === 'left') {
          $left_toggles[$id] = $button;
        }
        else {
          $right_toggles[$id] = $button;
        }
      }
    }

    // The menu's boot data: the panels it lists (checked rows that open and
    // close through the sidebar manager; the hotkey hints come from
    // daedalusUi.hotKeys) and the settings door, delivered only to a user
    // who may walk through it, so the menu never offers a row that lands on
    // a 403. The route owns its gate (`access administration pages`), so
    // the route's access check is the one asked here; forbidden when the
    // route is absent (a kernel test without the daedalus module).
    $page_top['#attached']['drupalSettings']['daedalusUi']['topBar'] = [
      'panels' => $panels,
      'settingsUrl' => $this->accessManager->checkNamedRoute('daedalus.admin_config')
        ? Url::fromRoute('daedalus.admin_config')->toString()
        : NULL,
    ];

    $top_bar['left_toggles'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'edit-mode-left-toggles',
        'class' => ['top-bar__content', 'top-bar__left-toggles'],
      ],
    ] + $left_toggles;

    // The document sentence and Save: both need a host (the sentence names
    // its document, Save reads its staged state). Without one there is no
    // document to speak of and nothing to commit.
    $global = [];
    if ($host !== NULL) {
      // A page is almost always the kind, so a page says nothing; a
      // template, a blueprint or the site frame names itself, in the one
      // word every surface uses for it (DaedalusUi::hostKind()).
      $kind = ($host instanceof DisplayHost || $host instanceof BlueprintHost || $host instanceof FrameHost)
        ? $this->ui->hostKind($host)
        : NULL;
      $global['document'] = [
        '#type' => 'inline_template',
        '#template' => "<div id='daedalus-ui-document' class='daedalus-ui-document'><span class='daedalus-ui-document__title'>{{ title }}</span>{% if kind %}<span class='daedalus-ui-kind'>{{ kind }}</span>{% endif %}</div>",
        '#context' => [
          'title' => $host->label(),
          'kind' => $kind,
        ],
      ];
      // Save is the unsaved-changes indicator: one control, one fact. Its
      // state has two sources and one attribute: the server reads it here
      // (staged changes outlive a reload, and only the server knows them
      // on arrival), and the client flips the attribute to unsaved on the
      // first blueprint change after the mirror boots
      // (tool_manager/top-bar.js). The server's fact is the page's
      // history timeline, not the tempstore's has(): the canvas render
      // itself stages a layout document on first sight (core's
      // PrepareLayout seed, daedalus_layout's tree-index re-save), so a
      // tempstore copy exists for every layout document in mode, committed
      // or not. The history recorder suppresses exactly those bookkeeping
      // writes, records every user mutation through both strategies, and
      // ends the timeline on save and discard: can_undo is true while the
      // page has an applied, uncommitted change, whoever made it (the
      // unsaved copy and its timeline are shared by the page's editors),
      // and Save then agrees with the History panel by construction.
      $saved = !$this->historyManager->metadata($host)['can_undo'];
      $global['save'] = [
        '#type' => 'inline_template',
        '#template' => "<button{{ attributes }}><span class='daedalus-ui-save__label'>{{ label }}</span></button>",
        '#context' => [
          'attributes' => new Attribute([
            'type' => 'button',
            'id' => 'daedalus-ui-save',
            'class' => ['toolbar-button', 'daedalus-ui-save'],
            // The label is the state; the tooltip names the gesture.
            'data-daedalus-ui-tooltip' => $this->t('Commit the staged changes'),
            'data-daedalus-ui-saved' => $saved ? 'true' : 'false',
          ]),
          'label' => $saved ? $this->t('Saved') : $this->t('Save changes'),
        ],
      ];
    }
    $top_bar['global'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'edit-mode-global-top-bar',
        'class' => ['top-bar__content', 'top-bar__global'],
      ],
    ] + $global;

    // The exit control, "Done": its own render beside the entry core's bar
    // keeps (ModeEntry), the same .daedalus-ui-mode-button[data-mode] pair
    // daedalus-ui.js binds and keeps in sync. It never conceals.
    $right_toggles['exit_mode'] = $mode->buildModeExitButton();
    $top_bar['right_toggles'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'edit-mode-right-toggles',
        'class' => ['top-bar__content', 'top-bar__right-toggles'],
      ],
    ] + $right_toggles;

    // Add the top bar. Visibility is CSS-driven off the html element's
    // data-daedalus-ui-mode attribute, so the client-side mode toggle and
    // the cookie-rendered initial state share one mechanism. The user
    // context: the settings door is the current user's; the route: the
    // document sentence and Save's staged state are the route host's.
    $page_top['daedalus_ui_top_bar'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'edit-mode-top-bar',
        'class' => ['top-bar', 'daedalus-ui-top-bar'],
        'data-drupal-admin-styles' => '',
        // In-mode this bar owns the top displacement (core's bar is hidden
        // and zero-boxed — see navigation.css); out of mode top-bar.css
        // zero-boxes THIS bar so displace.js measures it as nothing.
        'data-offset-top' => 'true',
      ],
      '#cache' => ['contexts' => ['user', 'user.permissions', 'daedalus_ui_mode', 'route']],
      'tools' => $top_bar,
    ];
  }

}
