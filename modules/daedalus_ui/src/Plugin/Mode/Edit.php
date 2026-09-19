<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Plugin\Mode;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Url;
use Drupal\daedalus_ui\EditSessionScope;
use Drupal\daedalus_ui\ModeInterface;
use Drupal\daedalus_ui\ModePluginBase;
use Drupal\daedalus_ui\Attribute\Mode;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\daedalus_ui\ToolPluginManager;
use Drupal\daedalus_ui\SidebarPluginManager;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_ui\Event\ShouldNotEditModeEvent;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\ButtonBuilderTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Edit mode plugin.
 */
#[Mode(
  id: 'edit',
  label: new TranslatableMarkup('Edit Mode'),
  weight: 100,
)]
final class Edit extends ModePluginBase implements PluginFormInterface {

  use StringTranslationTrait;
  use ButtonBuilderTrait;

  /**
   * The entity being edited.
   */
  public EntityInterface $entity;

  public function __construct(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ModuleExtensionList $extensionList,
    protected ToolPluginManager $toolManager,
    protected SidebarPluginManager $sidebarManager,
    protected RouteMatchInterface $routeMatch,
    protected DaedalusUi $ui,
    protected EventDispatcherInterface $eventDispatcher,
    protected EditSessionScope $editSessionScope,
    protected ModuleHandlerInterface $moduleHandler,
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
      $container->get('plugin.manager.tools'),
      $container->get('plugin.manager.daedalus_ui_sidebars'),
      $container->get('current_route_match'),
      $container->get('daedalus_ui.ui'),
      $container->get('event_dispatcher'),
      $container->get('daedalus_ui.edit_session_scope'),
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function applies(): bool {
    $entity = $this->ui->deriveEntityFromRoute();
    $event = $this->eventDispatcher->dispatch(new ShouldNotEditModeEvent($entity), ShouldNotEditModeEvent::class);
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
   * Build the tool cluster for the floating tool pill.
   *
   * The tool cluster renders in a bottom-center pill floating over the
   * canvas (see buildToolPill): icon-only buttons whose label and hotkey
   * ride the tooltip. This is also what attaches every applicable tool's JS
   * library and settings, so it must run whenever the mode applies, not only
   * while the mode is active — entering Edit Mode client-side does not
   * re-render the page.
   *
   * @param array $page_top
   *   The page_top render array, the attachment target.
   *
   * @return array
   *   A render array of tool buttons.
   */
  protected function buildToolButtons(array &$page_top): array {
    $tools = $this->ui->getToolPlugins();
    if (empty($tools)) {
      return [];
    }
    $page_top['#attached'] ??= [];
    $buttons = [];

    foreach ($tools as $id => $tool) {
      $tool->addAttachments($page_top['#attached']);

      $icon_data = $tool->getIconsPath();
      $style = "";

      // Add styles for mouse cursors.
      if (!empty($icon_data['mouse_icon'])) {
        $style = "<style>.$id,\n.$id a {\n  cursor: {$icon_data['mouse_icon']}, auto;\n}</style>";
      }

      // Build icon data for core's icon system.
      $pack_id = $icon_data['pack_id'] ?? 'daedalus_ui';
      $icon_id = $icon_data['icon_id'] ?? $id;
      $icon_class = 'toolbar-button__icon';
      $icon_size = 20;

      // Add a button for the tool using inline template with icon() function.
      $buttons[$id] = [
        '#type' => 'inline_template',
        '#template' => "<a href='javascript:void(0)' data-daedalus-ui-tooltip='{{title}}' data-daedalus-ui-tooltip-position='top' aria-label='{{title}}' data-tool='{{id}}' class='toolbar-button daedalus-ui-button daedalus-ui-tool-button toolbar-button--icon--{{icon_id}}'>{{ icon(pack_id, icon_id, { class: icon_class, size: icon_size }) }}</a>$style",
        '#context' => [
          'pack_id' => $pack_id,
          'icon_id' => $icon_id,
          'icon_class' => $icon_class,
          'icon_size' => $icon_size,
          'id' => $id,
          'title' => $tool->label() . ' ' . strtoupper($tool->hotKey()),
        ],
      ];
    }

    // Set the initial mode and active tool when visiting a page for the first
    // time.
    $entity = $this->ui->deriveEntityFromRoute();

    if ($bundle_entity_type = $entity->getEntityType()->getBundleEntityType()) {
      $entity_type = \Drupal::entityTypeManager()->getStorage($bundle_entity_type)->load($entity->bundle());
      $initial_mode = $entity_type->getThirdPartySetting('daedalus_ui', 'initial_mode', 'none');
      if ($initial_mode !== 'none') {
        $page_top['#attached']['drupalSettings']['daedalusUi']['initialMode'] = $initial_mode;
      }
      if ($initial_mode === 'edit') {
        $configured_modes = $entity_type->getThirdPartySetting('daedalus_ui', 'modes', []);
        $mode_settings = $configured_modes[$initial_mode] ?? $this->getConfiguration();
        if (!empty($mode_settings['default_tool'])) {
          $page_top['#attached']['drupalSettings']['daedalusUi']['defaultTool'] = $mode_settings['default_tool'];
        }
      }
    }

    return $buttons;
  }

  /**
   * {@inheritdoc}
   */
  public function buildBars(array &$page_top, ModeInterface $mode): void {
    $this->buildTopBar($page_top, $mode);
    $this->buildToolPill($page_top);
    $page_top['#markup'] = '<div id="daedalus-root"></div>';
  }

  /**
   * Build the floating tool pill.
   *
   * The tool cluster floats in a bottom-center pill over the canvas rather
   * than docking in the top bar: tools live near where the mouse works, and
   * with verbs offering themselves at the selection the top bar stays
   * reserved for panel toggles and document actions. The container keeps the
   * #edit-mode-toolbar id — tool click bindings (edit-mode-plugin.js),
   * registration diagnostics (registration.js), and FJS assertions all key
   * on it. It deliberately carries NO data-offset-* attribute: it floats
   * over the canvas and must never displace it.
   *
   * @param array $page_top
   *   The page top render array.
   */
  protected function buildToolPill(array &$page_top): void {
    $tools = $this->buildToolButtons($page_top);
    if (empty($tools)) {
      return;
    }
    $page_top['daedalus_ui_tool_pill'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'edit-mode-toolbar',
        'class' => ['daedalus-ui-tool-pill'],
        'data-drupal-admin-styles' => '',
        // Chrome contract with the armed edit session's press decision: a
        // press on the toolbar keeps the current edit open (switching tools
        // runs its own disable path).
        'data-keeps-edit' => '',
        // The one piece of chrome floating OVER content: at clean density it
        // conceals as a unit until the pointer nears the bottom-center;
        // tools stay reachable by hotkey throughout.
        'data-daedalus-ui-conceal' => 'clean',
        'data-daedalus-ui-reveal-near' => '120',
      ],
      // Visibility is CSS-driven off the html element's
      // data-daedalus-ui-mode attribute, same as the top bar.
      '#cache' => ['contexts' => ['user.permissions', 'daedalus_ui_mode']],
      'tools' => $tools,
    ];
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
    // middle. The tool cluster is not here — it floats in the bottom-center
    // tool pill (buildToolPill).
    $top_bar['left_toggles'] = [];
    $right_toggles = [];

    // Add a global area on the right side of the page for buttons that work
    // with all tools.
    $top_bar['global'] = [
      'refresh' => $this->buildTopBarButton(
        'daedalus-ui-refresh',
        $this->t('Refresh'),
        $this->t('Refresh the editing UI'),
        'daedalus_ui',
        'refresh'
      ),
      'save' => $this->buildTopBarButton(
        'daedalus-ui-save',
        $this->t('Save'),
        $this->t('Commit the tempstore changes'),
        'daedalus_ui',
        'save'
      ),
      'discard-changes' => $this->buildTopBarButton(
        'daedalus-ui-discard-changes',
        $this->t('Discard changes'),
        $this->t('Discard the tempstore changes'),
        'daedalus_ui',
        'discard-changes'
      ),
    ];

    // The canvas state vector spine (width / scheme / motion). Headless:
    // the posing UI is the daedalus_stylish inspector's chip row, driving the
    // CanvasVector facade; this attach only delivers the store, the media
    // emulation, the drag handles, the ruler, and the ?canvas* boot
    // parsing. It needs a channel scope, which needs a main entity;
    // without one there is no canvas to pose. The responsivePreview
    // settings feed the "Open preview window" opener in the chip row's
    // width menu (the edited page's path is appended by the opener at
    // click time: the same page can be reached at several URLs; the click
    // knows the real one).
    $entity = $this->ui->deriveEntityFromRoute();
    $scope = $entity !== NULL ? $this->editSessionScope->derive($entity) : NULL;
    if ($scope !== NULL) {
      $page_top['#attached']['library'][] = 'daedalus_ui/canvas_vector';
      $page_top['#attached']['drupalSettings']['daedalusUi']['responsivePreview'] = [
        'scope' => $scope,
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
        $tool = $definition['tool'] ?? NULL;
        if ($this->ui->isSidebarVisible($id, $definition['side'], $slot, $sidebar_mode, $tool, $definition['default_open'] ?? FALSE)) {
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

    // Add tool specific buttons.
    foreach ($this->ui->getToolPlugins() as $id => $tool) {
      $tool->buildGlobalTopBarButtons($top_bar['global']);
      $tool_top_bar = $tool->buildToolTopBarButtons();
      if (!empty($tool_top_bar)) {
        $top_bar[$id] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => "$id-top-bar",
            'class' => ['top-bar__content', 'top-bar__left'],
          ],
          'top_bar' => $tool_top_bar,
        ];
        if ($this->ui->getActiveTool() !== $id) {
          $top_bar[$id]['#attributes']['class'][] = 'daedalus-ui-hidden';
        }
      }
    }

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

  /**
   * {@inheritdoc}
   */
  public function getSummary(): string|TranslatableMarkup {
    $config = $this->getConfiguration();
    $tool = $this->toolManager->getDefinition($config['default_tool'], FALSE);
    if ($tool) {
      $label = $tool['label'];
    }
    else {
      $label = $config['default_tool'];
    }
    return new TranslatableMarkup('Default to tool: @tool', ['@tool' => $label]);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    if ($this->moduleHandler->moduleExists('daedalus_edit')) {
      $default_tool_default_value = 'edit';
    }
    else {
      $default_tool_default_value = 'last';
    }
    return ['default_tool' => $default_tool_default_value];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, ?string $entity_type_id = NULL, ?string $entity_bundle_id = NULL) {
    $bundled_entity = $this->entityTypeManager->getDefinition($entity_type_id);
    $bundle_entity_type = $bundled_entity->getBundleEntityType();
    if ($bundle_entity_type) {
      /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $bundle_entity */
      $bundle_entity = $this->entityTypeManager
        ->getStorage($bundle_entity_type)
        ->load($entity_bundle_id);
      if (!$bundle_entity) {
        return [];
      }
    }

    $options = [
      'last' => $this->t('The last tool used or Preview'),
    ];
    foreach ($this->toolManager->getDefinitions() as $tool => $definition) {
      $options[$tool] = $definition['label'];
    }

    $configured_modes = $bundle_entity->getThirdPartySetting('daedalus_ui', 'modes', []);
    $values = $configured_modes[$this->getPluginId()] ?? $this->getConfiguration();
    $form['default_tool'] = [
      '#title' => $this->t('Default tool'),
      '#type' => 'radios',
      '#options' => $options,
      '#default_value' => $values['default_tool'],
      '#description' => $this->t("Choose the tool that will be activated when @type's are initially edited. Subsequent edits of the same page will reactivate the last tool that was used.", ['@type' => $bundle_entity->label()]),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration([
      'default_tool' => $form_state->getValue('default_tool'),
    ]);
  }

}
