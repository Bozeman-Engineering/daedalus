<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\Mode;

use Drupal\Core\Render\Element;
use Drupal\Core\Url;
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
 * the top bar (panel toggles, document actions, the exit control) and the
 * mode-bound sidebar panels, whose build() arrays carry every module's
 * Edit Mode assets.
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
   * Build top bar.
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

    // Three zones: panel toggles pinned to the edges (a toggle lives on the
    // side its panel opens), the document actions spaced evenly in the
    // middle.
    $top_bar['left_toggles'] = [];
    $right_toggles = [];

    // The document actions, in the middle of the bar.
    $top_bar['global'] = [
      'refresh' => $this->buildTopBarButton(
        'daedalus-ui-refresh',
        $this->t('Refresh'),
        $this->t('Refresh the editing UI'),
        'daedalus_ui',
        'refresh'
      ),
      // Save is a split control: the button commits as it always has, and
      // the caret beside it opens the document menu, the top bar's face of
      // the verb registry (the root's verbs flagged `document`, see
      // tool_manager/top-bar.js). The bar names no gesture of its own:
      // whole-page gestures register as root verbs and show up here.
      'save' => $this->buildSplitButton(
        'daedalus-ui-save',
        $this->t('Save'),
        $this->t('Commit the tempstore changes'),
        'daedalus_ui',
        'save',
        'daedalus-ui-save-menu',
        $this->t('More save actions'),
      ),
      'discard-changes' => $this->buildTopBarButton(
        'daedalus-ui-discard-changes',
        $this->t('Discard changes'),
        $this->t('Discard the tempstore changes'),
        'daedalus_ui',
        'discard-changes'
      ),
    ];

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
    // responsivePreview url feeds the "Open preview window" opener in the
    // chip row's width menu (the edited page's path is appended by the
    // opener at click time: the same page can be reached at several URLs;
    // the click knows the real one).
    $host = $this->ui->deriveHostFromRoute();
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
        $button['#cache']['contexts'] = ['user.permissions', "cookies:{$definition['side']}_{$slot}_sidebar"];
        if ($this->ui->isSidebarVisible($id, $definition['side'], $slot, $sidebar_mode, $definition['default_open'] ?? FALSE)) {
          $button['wrapper']['#attributes']['class'][] = 'active';
        }
        if (($definition['side'] ?? 'right') === 'left') {
          $top_bar['left_toggles'][$id] = $button;
        }
        else {
          $right_toggles[$id] = $button;
        }
      }
    }

    $top_bar['left_toggles'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'edit-mode-left-toggles',
        'class' => ['top-bar__content', 'top-bar__left-toggles'],
      ],
    ] + $top_bar['left_toggles'];

    // The exit control: the mode toggle relocated from the navigation sidebar
    // footer we no longer render in. Clicking the active mode's button
    // disables it (daedalus-ui.js binds .daedalus-ui-mode-button).
    $right_toggles['exit_mode'] = $mode->buildModeButton();

    // Group the global buttons so they can spread evenly in the middle as a
    // unit, between the edge-pinned toggle groups. Each button conceals
    // individually (same treatment as the panel toggles): the container
    // stretches across the bar's whole middle, so concealing the cluster
    // would reveal it from anywhere along the bar rather than near the
    // buttons themselves.
    $top_bar['global'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'edit-mode-global-top-bar',
        'class' => ['top-bar__content', 'top-bar__global'],
      ],
    ] + $top_bar['global'];
    foreach (Element::children($top_bar['global']) as $key) {
      if (isset($top_bar['global'][$key]['wrapper'])) {
        $attributes = &$top_bar['global'][$key]['wrapper']['#attributes'];
      }
      else {
        $attributes = &$top_bar['global'][$key]['#attributes'];
      }
      $attributes['data-daedalus-ui-conceal'] = 'clean';
      $attributes['data-daedalus-ui-reveal-near'] = '120';
      unset($attributes);
    }

    // Right-docked panels' toggles, pinned to the far right (mirroring
    // left_toggles).
    $top_bar['right_toggles'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'edit-mode-right-toggles',
        'class' => ['top-bar__content', 'top-bar__right-toggles'],
      ],
    ] + $right_toggles;

    // Add the top bar. Visibility is CSS-driven off the html element's
    // data-daedalus-ui-mode attribute, so the client-side mode toggle and
    // the cookie-rendered initial state share one mechanism.
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
      '#cache' => ['contexts' => ['user.permissions', 'daedalus_ui_mode']],
      'tools' => $top_bar,
    ];
  }

}
