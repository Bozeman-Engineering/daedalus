<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\block\Entity\Block;
use Drupal\Core\Url;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_ui\Event\EditableFieldAttributes;
use Drupal\daedalus_ui\Presentation\Presentation;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Drupal\daedalus_ui\Render\LiveAttributionDemand;
use Drupal\layout_builder\OverridesSectionStorageInterface;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Daedalus UI UI.
 */
final class DaedalusUi implements TrustedCallbackInterface {

  use StringTranslationTrait;

  /**
   * The rail width floor, mirroring MIN_WIDTH in sidebar-resize.js.
   */
  private const RAIL_MIN_WIDTH = 220;

  /**
   * The rail width ceiling, approximating the client's 50vw clamp.
   *
   * The client re-clamps on drag.
   */
  private const RAIL_MAX_WIDTH = 1200;

  /**
   * The default rail width (sidebar.css --daedalus-ui-sidebar-width fallback).
   *
   * A panel overriding it seeds slightly off until displace.js measures.
   */
  private const RAIL_DEFAULT_WIDTH = '17rem';

  /**
   * The entity derived from the route.
   *
   * @var \Drupal\Core\Entity\EntityInterface|null
   *
   * @see deriveEntityFromRoute()
   */
  private ?EntityInterface $entity = NULL;

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly ModeState $modeState,
    private readonly ModePluginManager $modeManager,
    private readonly ToolPluginManager $toolManager,
    private readonly SidebarPluginManager $sidebarManager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly AdminContext $routerAdminContext,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ExtensionPathResolver $extensionPathResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
    private readonly LiveAttributionDemand $liveAttribution,
    private readonly ElementModel $elementModel,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['buildSidebarPanel'];
  }

  /**
   * Wrap and attribute fields for editing.
   *
   * We add a wrapper around the field and let tools add the attributes needed
   * to make the tools work.
   *
   * @param array $variables
   *   The variables from hook_preprocess_field.
   */
  public function wrapAndAttributeFieldsForEditing(&$variables) {
    // Only attribute the page elements when in edit mode.
    $variables['#cache']['contexts'][] = 'daedalus_ui_mode';
    $element = $variables['element'] ?? [];
    $entity = $element['#object'] ?? NULL;
    $mode = $this->getMode();
    if ($mode !== 'edit') {
      // Live render: stamp only the selection identity, and only when a
      // provider demands it — a styled field's CSS scopes to this key, so
      // the key must reach the visitor's markup (bytes on the field's own
      // template root, no structure change).
      if ($entity instanceof FieldableEntityInterface && !empty($element['#field_name'])) {
        $key = SelectableKey::field($entity->uuid(), $element['#field_name']);
        if ($this->liveAttribution->demandsKey($key, $entity)) {
          $variables['attributes'][SelectableKey::ATTRIBUTE] = $key;
        }
      }
      return;
    }

    // Core's EntityViewController::buildTitle() inlines a label-field render
    // inside the theme's page-title <h1> (#is_page_title), outside the
    // entity. That surface's ONE carrier is the page-title element itself
    // (daedalus_edit attributes it at hook_preprocess_page_title as the root's
    // edit-target leaf), so attributing this inner render would put
    // a SECOND element on the page answering to the field's key and a
    // duplicate editable leaf inside the first.
    if (!empty($element['#is_page_title'])) {
      return;
    }

    // Stamp the field's selection identity (selectable key) and the entity
    // metadata the form based editing path loads entities by. These ride the
    // field template's attributes; SelectableFieldDelivery guarantees they
    // reach the page even when a theme's field template does not print
    // {{ attributes }}.
    if ($entity instanceof FieldableEntityInterface && !empty($element['#field_name'])) {
      $variables['attributes'][SelectableKey::ATTRIBUTE] = SelectableKey::field($entity->uuid(), $element['#field_name']);
      $variables['attributes']['data-entity-type'] = $entity->getEntityTypeId();
      $variables['attributes']['data-entity-id'] = daedalus_ui_entity_identifier($entity);
      // The field tier of the presentation chain: the ONE element
      // model decides. A media reference renders a whole entity whose
      // parts the user means as ONE thing ("the image"), so selection and
      // armed editing stop at the reference field rather than the media
      // entity's inner fields; a FieldConfig third-party setting overrides
      // the declaration tier and the field-type default. OPEN stamps
      // nothing. Modules can mark additional fields via the
      // EditableFieldAttributes event below.
      $definition = $entity->get($element['#field_name'])->getFieldDefinition();
      $field_mode = $this->elementModel->fieldPresentation($definition);
      if ($field_mode !== Presentation::OPEN) {
        $variables['attributes'][Presentation::ATTRIBUTE] = $field_mode;
      }
    }

    if (!empty($variables['items'])) {
      foreach ($variables['items'] as &$item) {
        $item['content'] = [
          'edit_mode_wrapper' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['daedalus-edit-field-value'],
            ],
            'content' => $item['content'],
          ],
        ];
      }
    }

    $this->eventDispatcher->dispatch(new EditableFieldAttributes($variables), EditableFieldAttributes::class);
  }

  /**
   * Build page top.
   *
   * Let modes add top and side bars.
   *
   * @param array $page_top
   *   The page_top render array.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function buildPageTop(array &$page_top) {
    if (isset($page_top['navigation']['#cache'])) {
      $page_top['navigation']['#cache']['contexts'][] = 'daedalus_ui_mode';
    }
    $modes = $this->modeManager->getModePlugins();
    if (!empty($modes)) {
      $page_top['#attached'] ??= [];
      $page_top['#attached']['library'][] = 'daedalus_ui/modes';
      $page_top['#cache']['contexts'][] = 'user.permissions';
      $page_top['#cache']['contexts'][] = 'daedalus_ui_mode';
      $this->setThemeColors($page_top);
    }
    foreach ($modes as $mode) {
      $mode->addAttachments($page_top['#attached']);
      $mode->buildBars($page_top, $mode);
    }
    $this->buildSidebars($page_top, array_keys($modes));
  }

  /**
   * Collect sidebar panels into the page-level sidebar wrappers.
   *
   * Loops every sidebar plugin and applies the two axes that govern a panel.
   * Presence: a panel exists in the DOM when its plugin applies() and -- if it
   * is mode-bound -- when its bound mode is available on this request. An
   * unavailable mode means the panel is omitted entirely rather than rendered
   * hidden on every page. Visibility: a present panel is shown or hidden per
   * isSidebarVisible(), then flipped client-side, so the wrappers are never
   * re-rendered on a mode or tool switch. An <aside> is emitted only for a side
   * that has at least one present panel. A no-mode (always-on) panel also gets
   * a standalone fixed-position toggle button, since -- unlike a mode-bound
   * panel, whose toggle lives in that mode's top bar -- it has no top bar to
   * host one (anonymous and non-edit routes render none).
   *
   * The "daedalus-ui-{side}-sidebar" wrapper id is a stable cross-module
   * contract: modules whose panels are dynamic and per-request (e.g.
   * daedalus_edit's per-entity Change forms) cannot be statically-discovered
   * #[Sidebar] plugins, so they append their panels as siblings into this
   * wrapper by id at runtime. Such a consumer must run only where a collected
   * panel keeps its side non-empty, since the wrapper is omitted for an empty
   * side.
   *
   * @param array $page_top
   *   The page_top render array.
   * @param string[] $available_modes
   *   The ids of the modes that apply to this request, used to presence-gate
   *   mode-bound panels.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  private function buildSidebars(array &$page_top, array $available_modes): void {
    // The shared sidebar library is attached to the page-level #attached below,
    // which is not yet present when no mode populated it (the anonymous,
    // all-pages chat case). Panel-specific assets travel with each build().
    $page_top['#attached'] ??= [];
    $sides = [];
    $fab_buttons = [];
    foreach ($this->sidebarManager->getSidebars() as $id => $sidebar) {
      $definition = $sidebar->getPluginDefinition();

      // A mode-bound panel is only present when its mode is available.
      $mode = $definition['mode'] ?? NULL;
      if ($mode !== NULL && !in_array($mode, $available_modes, TRUE)) {
        continue;
      }

      // The panel wrapping + visibility lives in buildSidebarPanel() so the
      // same markup serves both page-load and AJAX panel replacement.
      //
      // A panel may opt into a lazy builder (#[Sidebar(use_lazy_builder:
      // TRUE)]): it renders behind a #lazy_builder placeholder so that, under
      // BigPipe, its markup and attached assets stream in after the initial
      // response rather than rendering inline. Most panels render inline (the
      // default) for flicker-free SSR.
      if ($definition['use_lazy_builder'] ?? FALSE) {
        $sides[$definition['side']][$id] = [
          '#lazy_builder' => ['daedalus_ui.ui:buildSidebarPanel', [$id]],
          '#create_placeholder' => TRUE,
        ];
      }
      else {
        $sides[$definition['side']][$id] = $this->buildSidebarPanel($id);
      }

      // A mode-bound panel's toggle is collected into that mode's top bar (see
      // Edit::buildTopBar). A no-mode (always-on) panel has no top bar to live
      // in -- anonymous and non-edit routes render none -- so its toggle is
      // emitted below as a standalone fixed-position button. Active state
      // mirrors the panel via isSidebarVisible(), exactly as the top bar does.
      if ($mode === NULL) {
        $button = $sidebar->buildToggleButton();
        if (!empty($button)) {
          $slot = $definition['slot'] ?? 'top';
          $button['#cache']['contexts'][] = "cookies:{$definition['side']}_{$slot}_sidebar";
          if ($this->isSidebarVisible($id, $definition['side'], $slot, NULL, $definition['tool'] ?? NULL, $definition['default_open'] ?? FALSE)) {
            $button['wrapper']['#attributes']['class'][] = 'active';
          }
          $fab_buttons[$id] = $button;
        }
      }
    }

    // Emit a wrapper only for sides that have at least one present panel.
    foreach ($sides as $side => $panels) {
      $attributes = [
        'id' => "daedalus-ui-$side-sidebar",
        'class' => ['daedalus-ui-sidebar-wrapper'],
      ];
      // Stamp the persisted rail width (sidebar-resize.js cookie) back onto
      // the wrapper so page loads render at the user's width, no flash.
      $width = $this->getRailWidthCookie($side);
      if ($width !== NULL) {
        $attributes['style'] = "--daedalus-ui-sidebar-width: $width;";
      }
      $page_top["daedalus_ui_{$side}_sidebar"] = [
        '#type' => 'html_tag',
        '#tag' => 'aside',
        '#attributes' => $attributes,
        '#cache' => [
          'contexts' => [
            'user.permissions',
            'daedalus_ui_mode',
            'cookies:activeTool',
            "cookies:{$side}_sidebar_width",
          ],
        ],
        'panels' => $panels,
      ];
    }

    // Standalone floating toggles for the always-on panels.
    if (!empty($fab_buttons)) {
      $page_top['daedalus_ui_sidebar_fabs'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['daedalus-ui-sidebar-fabs']],
        '#cache' => ['contexts' => ['user.permissions']],
        'buttons' => $fab_buttons,
      ];
    }

    // Load the shared sidebar JS only when a panel is actually present.
    if (!empty($sides)) {
      $page_top['#attached']['library'][] = 'daedalus_ui/sidebar';
    }
  }

  /**
   * Build a single sidebar panel, fully wrapped and ready for replacement.
   *
   * Renders one sidebar plugin in the same panel container the page-top
   * collector uses (id, classes, data-sidebar-type, and the isSidebarVisible()
   * open/closed flip), so the result is a drop-in for an AJAX ReplaceCommand on
   * "#{id}". Unlike the collector this ignores applies()/presence-gating -- the
   * caller has explicitly asked for this panel. Visibility still reflects the
   * current cookie state; the caller reveals it (e.g. via an InvokeCommand).
   *
   * @param string $id
   *   The sidebar plugin id; also its verbatim DOM id and the value its
   *   "{side}_{slot}_sidebar" cookie holds while this panel is the open one.
   *
   * @return array
   *   The wrapped panel render array.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   *   If no sidebar plugin with the given id exists.
   */
  public function buildSidebarPanel(string $id): array {
    $sidebar = $this->sidebarManager->createInstance($id);
    $definition = $sidebar->getPluginDefinition();
    $side = $definition['side'];
    $slot = $definition['slot'] ?? 'top';
    $mode = $definition['mode'] ?? NULL;
    $tool = $definition['tool'] ?? NULL;
    $default_open = $definition['default_open'] ?? FALSE;

    // The plugin id is the verbatim DOM id; the side carries the offset. The
    // slot is an attribute, not a container: layout CSS and the client-side
    // manager read it off the panel, and a panel without it defaults to 'top'.
    //
    // A plugin's build() may contribute attributes to the panel ROOT (e.g. a
    // chrome contract like data-keeps-edit that must ride the outermost
    // element): its #attributes merge under the panel's own — classes are
    // combined, while the identity attributes this collector owns (id, the
    // sidebar type and slot) always win.
    $build = $sidebar->build();
    $plugin_attributes = $build['#attributes'] ?? [];
    unset($build['#attributes']);
    $panel = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $id,
        'class' => array_merge(['daedalus-ui-sidebar', "$side-sidebar"], $plugin_attributes['class'] ?? []),
        'data-sidebar-type' => $definition['sidebar_type'],
        'data-sidebar-slot' => $slot,
      ] + $plugin_attributes,
    ] + $build;

    // Always-on panels (no mode binding) persist across Edit-Mode toggles --
    // the exit sweep (closeEphemeral) leaves them in place. Mark them so the
    // client can distinguish them from mode-bound panels.
    if ($mode === NULL) {
      $panel['#attributes']['data-sidebar-persistent'] = '';
    }

    // Mark default-open panels so the client can open them on every mode
    // entry and reset their (side, slot) cookie on mode exit (the server
    // handles the page-load half in isSidebarVisible()).
    if ($default_open) {
      $panel['#attributes']['data-sidebar-default-open'] = '';
    }

    if ($this->isSidebarVisible($id, $side, $slot, $mode, $tool, $default_open)) {
      $panel['#attributes']["data-offset-$side"] = '';
    }
    else {
      $panel['#attributes']['class'][] = 'daedalus-ui-hidden';
    }

    // Cache per user (panels may carry user-specific content, e.g. the chat
    // history) and vary by exactly the inputs isSidebarVisible() reads, so a
    // panel's open/closed state is never served stale or bled across users.
    $panel['#cache']['contexts'][] = 'user';
    $panel['#cache']['contexts'][] = "cookies:{$side}_{$slot}_sidebar";
    if ($mode !== NULL) {
      $panel['#cache']['contexts'][] = 'daedalus_ui_mode';
    }
    if ($tool !== NULL) {
      $panel['#cache']['contexts'][] = 'cookies:activeTool';
    }

    return $panel;
  }

  /**
   * Decide whether a present sidebar panel is currently shown.
   *
   * Visibility is the AND of three conditions: the panel's mode binding matches
   * the active mode (or it is mode-independent), its tool binding matches the
   * active tool (or it is not tool-bound), and its (side, slot) open-panel
   * cookie names this panel. The active tool is read straight from the
   * activeTool cookie to keep the heavy tool plugins off this render path.
   *
   * @param string $id
   *   The sidebar plugin id; also the value its "{side}_{slot}_sidebar" cookie
   *   holds while this panel is the open one.
   * @param string $side
   *   The side the panel sits on ('left' or 'right').
   * @param string $slot
   *   The slot within the side ('top' or 'bottom'); together with $side it
   *   selects the "{side}_{slot}_sidebar" cookie to read.
   * @param string|null $mode
   *   The mode the panel is bound to, or NULL when mode-independent.
   * @param string|null $tool
   *   The tool the panel is bound to, or NULL when not tool-bound.
   * @param bool $default_open
   *   Whether the panel shows when its (side, slot) cookie has never been
   *   set. An absent cookie (NULL) shows the panel; an empty cookie means the
   *   user deliberately closed it during the current mode session (the JS
   *   close path), which keeps it closed across in-mode page loads — mode
   *   exit expires the cookie and mode entry opens the panel client-side, so
   *   the close never outlives its session.
   *
   * @return bool
   *   TRUE when the panel should be shown.
   */
  public function isSidebarVisible(string $id, string $side, string $slot, ?string $mode, ?string $tool, bool $default_open = FALSE): bool {
    if ($mode !== NULL && $this->getMode() !== $mode) {
      return FALSE;
    }
    if ($tool !== NULL && $this->getActiveTool() !== $tool) {
      return FALSE;
    }
    $cookie = $this->requestStack->getCurrentRequest()->cookies->get("{$side}_{$slot}_sidebar");
    if ($cookie === NULL && $default_open) {
      return TRUE;
    }
    return $cookie === $id;
  }

  /**
   * Reads a rail's persisted width cookie, clamped to the client's bounds.
   *
   * @param string $side
   *   The rail side, 'left' or 'right'.
   *
   * @return string|null
   *   The width as a CSS px length, or NULL when no valid cookie exists.
   */
  private function getRailWidthCookie(string $side): ?string {
    $width = (int) $this->requestStack->getCurrentRequest()->cookies->get("{$side}_sidebar_width");
    return $width >= self::RAIL_MIN_WIDTH ? min($width, self::RAIL_MAX_WIDTH) . 'px' : NULL;
  }

  /**
   * Computes the rail widths knowable at SSR, for displace seeding.
   *
   * The offsets the open rails will produce are derivable from the same
   * cookies that render the panels, so first paint can agree with them
   * instead of paying the 0px var fallback until displace.js first runs.
   *
   * @return array
   *   'widths': CSS lengths keyed by 'left'/'right', NULL when the side
   *   has no visible panel. 'cache_contexts': the cookie contexts used.
   */
  public function getRailWidths(): array {
    $widths = ['left' => NULL, 'right' => NULL];
    $contexts = ['cookies:activeTool', 'cookies:left_sidebar_width', 'cookies:right_sidebar_width'];
    foreach ($this->sidebarManager->getSidebars() as $id => $sidebar) {
      $definition = $sidebar->getPluginDefinition();
      $side = $definition['side'];
      $slot = $definition['slot'] ?? 'top';
      $contexts[] = "cookies:{$side}_{$slot}_sidebar";
      if ($widths[$side] === NULL && $this->isSidebarVisible($id, $side, $slot, $definition['mode'] ?? NULL, $definition['tool'] ?? NULL, $definition['default_open'] ?? FALSE)) {
        $widths[$side] = $this->getRailWidthCookie($side) ?? self::RAIL_DEFAULT_WIDTH;
      }
    }
    return ['widths' => $widths, 'cache_contexts' => array_unique($contexts)];
  }

  /**
   * Preprocess top bar.
   */

  /**
   * Get mode.
   *
   * Delegates to the lightweight ModeState service so the request-derived mode
   * lookup has a single source of truth that cycle-sensitive consumers can
   * depend on without pulling in this heavy UI service.
   *
   * @param string $cookie_name
   *   The name of the cookie.
   *
   * @return string
   *   Whether edit mode is enabled (Whether the toolbar is open or closed).
   */
  public function getMode(string $cookie_name = 'navigationMode'): string {
    return $this->modeState->getMode($cookie_name);
  }

  /**
   * Get active tool.
   *
   * @return string
   *   The active tool.
   */
  public function getActiveTool(): string {
    return $this->requestStack->getCurrentRequest()->cookies->get('activeTool') ?? 'selector';
  }

  /**
   * Get tool plugins.
   *
   * @return \Drupal\daedalus_ui\ToolInterface[]
   *   An array of tool plugins that apply to the routed entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function getToolPlugins(): array {
    $tool_definitions = $this->toolManager->getDefinitions();
    $tools = [];
    if (!empty($tool_definitions)) {
      $entity = $this->deriveEntityFromRoute();
      foreach ($tool_definitions as $tool_definition) {
        $tool = $this->toolManager->createInstance($tool_definition['id']);
        if ($entity && $tool->applies($entity)) {
          $tools[$tool_definition['id']] = $tool;
        }
      }
    }
    return $tools;
  }

  /**
   * Is valid view mode.
   *
   * @return bool
   *   Whether the view_mode is a valid view mode.
   */
  public function isValidViewMode(EntityInterface $entity, string $view_mode): bool {
    $valid_view_modes = $this->entityDisplayRepository->getViewModes($entity->getEntityTypeId());
    $valid_view_modes['default'] = TRUE;
    return !empty($valid_view_modes[$view_mode]);
  }

  /**
   * Derive entity from route.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface|null $route_match
   *   (optional) A route match to derive from instead of the current route,
   *   e.g. one built from a client-supplied path (the AI chat resolves the
   *   page the user is on this way). Only the current-route result is
   *   memoized; an explicit route match is derived fresh every call.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   Returns the entity for this route or NULL if there isn't one or there is
   *   more than one.
   */
  public function deriveEntityFromRoute(?RouteMatchInterface $route_match = NULL) {
    if ($route_match !== NULL) {
      return $this->doDeriveEntityFromRoute($route_match);
    }
    if (empty($this->entity)) {
      $this->entity = $this->doDeriveEntityFromRoute($this->routeMatch);
    }
    return $this->entity;
  }

  /**
   * Derives the single top-level entity a route match is about.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match to inspect.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The entity, or NULL if there isn't one or there is more than one.
   */
  private function doDeriveEntityFromRoute(RouteMatchInterface $route_match): ?EntityInterface {
    $parameters = $route_match->getParameters()->all();
    $top_level_entity = NULL;
    foreach ($parameters as $parameter) {
      $entity = NULL;
      if ($parameter instanceof EntityInterface) {
        $entity = $parameter;
      }
      if ($parameter instanceof OverridesSectionStorageInterface) {
        $entity = $parameter->getContextValue('entity');
      }
      if ($parameter instanceof NestedAwareSectionStorage && $parameter->getRootStorage() instanceof OverridesSectionStorageInterface) {
        $entity = $parameter->getContextValue('entity');
      }
      if (!empty($entity) && is_null($top_level_entity)) {
        $top_level_entity = $entity;
      }
      elseif (!empty($entity) && !is_null($top_level_entity)) {
        if (
          $top_level_entity instanceof EntityInterface &&
          $entity instanceof EntityInterface &&
          $entity->bundle() === $top_level_entity->bundle() &&
          $entity->id() === $top_level_entity->id()
        ) {
          // These are the same entity. That's okay.
          continue;
        }
        // If there is more than one entity it's not obvious which one is the
        // parent, so we should probably not try to edit.
        return NULL;
      }
    }

    // If the entity is a Block Config Entity, load its Block Content Entity.
    if ($top_level_entity instanceof Block) {
      $top_level_entity = $this->getBlockPluginEntity($top_level_entity) ?? $top_level_entity;
    }

    return $top_level_entity;
  }

  /**
   * The content entity a block config entity's plugin wraps, if any.
   *
   * Derivative block plugin IDs carry the wrapped entity as
   * {entity_type}:{uuid} (e.g. block_content:{uuid}); a base plugin ID wraps
   * no entity.
   *
   * @param \Drupal\block\Entity\Block $block
   *   The block config entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The wrapped content entity, or NULL when the plugin does not wrap one.
   */
  public function getBlockPluginEntity(Block $block): ?EntityInterface {
    $plugin_id = $block->getPluginId();
    if (!str_contains($plugin_id, ':')) {
      return NULL;
    }
    [$entity_type, $uuid] = explode(':', $plugin_id);
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      return NULL;
    }
    $entities = $this->entityTypeManager->getStorage($entity_type)->loadByProperties(['uuid' => $uuid]);
    return !empty($entities) ? reset($entities) : NULL;
  }

  /**
   * The URL where an entity is edited with the page builder.
   *
   * Most content entities are edited in place on their canonical route (the
   * edit mode chrome renders over the live page). A block_content entity has
   * no public page of its own; its editing surface is the standalone
   * daedalus_ui.block_content.edit canvas. Consumers that send users "to
   * where this entity is edited" (AI new-entity open, listing operations)
   * must go through this one derivation rather than hand-rolling toUrl().
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to edit.
   *
   * @return \Drupal\Core\Url
   *   The editing surface URL.
   */
  public function getEditingUrl(EntityInterface $entity): Url {
    if ($entity->getEntityTypeId() === 'block_content') {
      return Url::fromRoute('daedalus_ui.block_content.edit', ['block_content' => $entity->id()]);
    }
    return $entity->toUrl();
  }

  /**
   * Whether a block_content entity is a page-builder entity the user may edit.
   *
   * A page-builder block_content carries the Layout Builder overrides field
   * (its bundle's display has overrides enabled), so it has a layout of its
   * own and an editing surface (getEditingUrl()); the current user must also
   * be allowed to update it. Every consumer that offers a way into that
   * surface (the block layout listing's "Edit layout" operation, the portal
   * stamp on a theme-region placement) asks here, so the one definition of
   * "editable page-builder block_content" never forks.
   *
   * @param \Drupal\Core\Entity\EntityInterface $block_content
   *   The block_content entity a placement wraps.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   Allowed when the entity carries the layout field and the user may
   *   update it, with the update check's cacheability; neutral otherwise.
   */
  public function pageBuilderBlockContentAccess(EntityInterface $block_content): AccessResultInterface {
    if (
      $block_content->getEntityTypeId() !== 'block_content' ||
      !$block_content instanceof FieldableEntityInterface ||
      !$block_content->hasField(OverridesSectionStorage::FIELD_NAME)
    ) {
      return AccessResult::neutral();
    }
    return $block_content->access('update', NULL, TRUE);
  }

  /**
   * Whether an entity has an editing surface getEditingUrl() can name.
   *
   * A page (an entity with a canonical route, or a block_content item with
   * the standalone editing route) does; a non-page entity (a swatch, a
   * variant, a skin: site-wide material with no page of its own) does not,
   * and a caller that would navigate to it asks here first instead of
   * catching the missing link template.
   */
  public function hasEditingUrl(EntityInterface $entity): bool {
    return $entity->getEntityTypeId() === 'block_content' || $entity->hasLinkTemplate('canonical');
  }

  /**
   * Forgets the entity derived from the route so the next read derives again.
   */
  public function clearDerivedEntity() {
    $this->entity = NULL;
  }

  /**
   * Add theme colors.
   *
   * @param array $page_top
   *   The page_top render array.
   */
  private function setThemeColors(array &$page_top) {
    // Add theme specific colors.
    $colors = $this->configFactory->get('daedalus_ui.settings')->get('colors');
    $rules = '';
    if (!empty($colors)) {
      foreach ($colors as $color => $hex) {
        $rules .= "--daedalus-ui-$color-color: {$hex};\n";
      }
    }
    else {
      // Unconfigured sites follow the chrome's scheme-aware selection
      // color (variables.css); the hex fallback covers pages where the
      // palette library is absent.
      $rules = "--daedalus-ui-main-color: var(--daedalus-selection, #4b9ae4);\n";
    }
    if (!empty($rules)) {
      $page_top['daedalus_ui_theme_colors'] = [
        '#type' => 'html_tag',
        '#tag' => 'style',
        '#value' => ":root {\n$rules}\n",
      ];
    }
  }

}
