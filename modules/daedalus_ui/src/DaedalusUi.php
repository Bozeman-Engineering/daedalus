<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Core\Url;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_ui\Event\EditableFieldAttributes;
use Drupal\daedalus_ui\Presentation\Presentation;
use Drupal\daedalus_ui\ElementModel\ElementModel;
use Drupal\daedalus_ui\Render\ElementAddress;
use Drupal\daedalus_ui\Render\LiveAttributionDemand;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_layout\RootEntityRegistry;
use Drupal\layout_builder\SectionStorageInterface;
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
   * Whether the route host has been derived for the current route.
   *
   * @see deriveHostFromRoute()
   */
  private bool $hostDerived = FALSE;

  /**
   * The host derived from the current route, once derived.
   */
  private ?HostInterface $host = NULL;

  public function __construct(
    private readonly RequestStack $requestStack,
    private readonly ModeState $modeState,
    private readonly ModePluginManager $modeManager,
    private readonly SidebarPluginManager $sidebarManager,
    private readonly RouteMatchInterface $routeMatch,
    private readonly AdminContext $routerAdminContext,
    private readonly AccountProxyInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly ExtensionPathResolver $extensionPathResolver,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
    private readonly LiveAttributionDemand $liveAttribution,
    private readonly ElementModel $elementModel,
    private readonly HostResolver $hostResolver,
    private readonly RouteProviderInterface $routeProvider,
    private readonly DaedalusLayoutAdapter $layoutAdapter,
    private readonly ?RootEntityRegistry $rootEntityRegistry = NULL,
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
   * We add a wrapper around the field and let the editing modules add the
   * attributes their grammars need.
   *
   * @param array $variables
   *   The variables from hook_preprocess_field.
   */
  public function wrapAndAttributeFieldsForEditing(&$variables) {
    // Only attribute the page elements when in edit mode.
    $variables['#cache']['contexts'][] = 'daedalus_ui_mode';
    $element = $variables['element'] ?? [];
    $entity = $element['#object'] ?? NULL;
    // The field root's element address, in every mode and for every
    // visitor: the same field renders the selectable key below stamp (or
    // would stamp, for an editor) carry it. Core's page-title inline label
    // render (#is_page_title) is not a field root on the page (the title
    // element is the carrier, see below) and never wears it.
    if ($entity instanceof FieldableEntityInterface && !empty($element['#field_name']) && empty($element['#is_page_title'])) {
      $variables['attributes'][ElementAddress::ATTRIBUTE] = ElementAddress::FIELD;
    }
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

    // Editable attribution follows the host: only a field of the route
    // host's own fields entity, or of a block placed in the host's document,
    // is stamped (ownsFields()). Any other rendered entity (core's sample
    // entity on a template canvas, a media entity a formatter renders, a
    // foreign document's blocks on a plain host) is inert by construction:
    // no selection identity, no load metadata, no editor attributes.
    if (!$entity instanceof FieldableEntityInterface || empty($element['#field_name']) || !$this->ownsFields($entity)) {
      return;
    }

    // Stamp the field's selection identity (selectable key) and the entity
    // metadata the form based editing path loads entities by. These ride the
    // field template's attributes; SelectableFieldDelivery guarantees they
    // reach the page even when a theme's field template does not print
    // {{ attributes }}.
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
   * re-rendered on a mode switch. An <aside> is emitted only for a side
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
          if ($this->isSidebarVisible($id, $definition['side'], $slot, NULL, $definition['default_open'] ?? FALSE)) {
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

    if ($this->isSidebarVisible($id, $side, $slot, $mode, $default_open)) {
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

    return $panel;
  }

  /**
   * Decide whether a present sidebar panel is currently shown.
   *
   * Visibility is the AND of two conditions: the panel's mode binding matches
   * the active mode (or it is mode-independent), and its (side, slot)
   * open-panel cookie names this panel.
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
  public function isSidebarVisible(string $id, string $side, string $slot, ?string $mode, bool $default_open = FALSE): bool {
    if ($mode !== NULL && $this->getMode() !== $mode) {
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
    $contexts = ['cookies:left_sidebar_width', 'cookies:right_sidebar_width'];
    foreach ($this->sidebarManager->getSidebars() as $id => $sidebar) {
      $definition = $sidebar->getPluginDefinition();
      $side = $definition['side'];
      $slot = $definition['slot'] ?? 'top';
      $contexts[] = "cookies:{$side}_{$slot}_sidebar";
      if ($widths[$side] === NULL && $this->isSidebarVisible($id, $side, $slot, $definition['mode'] ?? NULL, $definition['default_open'] ?? FALSE)) {
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
   * Whether a view mode is one the host's canvas can render.
   *
   * A content host renders in any of its entity type's view modes; a
   * template host renders in the one view mode its display is for.
   *
   * @return bool
   *   Whether the view_mode is a valid view mode for the host.
   */
  public function isValidViewMode(HostInterface $host, string $view_mode): bool {
    if ($host->fields() === NULL) {
      return $view_mode === $host->viewMode();
    }
    $valid_view_modes = $this->entityDisplayRepository->getViewModes($host->entityTypeId());
    $valid_view_modes['default'] = TRUE;
    return !empty($valid_view_modes[$view_mode]);
  }

  /**
   * The host the route is about.
   *
   * The route host is the owner of the canvas: a content entity on its
   * canonical page (a node, a saved blueprint), the entity an overrides
   * storage on the route belongs to (core's manage-layout route of an
   * entity), or the display a defaults storage on the route belongs to
   * (core's manage-display layout route, `layout_builder.defaults.*.view`).
   * Route parameters that host nothing
   * (a bundle config entity beside the storage, a view mode name) are
   * passed over; two different hosts on one route derive none.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface|null $route_match
   *   (optional) A route match to derive from instead of the current route,
   *   e.g. one built from a client-supplied path (the AI chat resolves the
   *   page the user is on this way). Only the current-route result is
   *   memoized; an explicit route match is derived fresh every call.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The route host, or NULL if there is none or more than one.
   */
  public function deriveHostFromRoute(?RouteMatchInterface $route_match = NULL): ?HostInterface {
    if ($route_match !== NULL) {
      return $this->doDeriveHostFromRoute($route_match);
    }
    if (!$this->hostDerived) {
      $this->host = $this->doDeriveHostFromRoute($this->routeMatch);
      $this->hostDerived = TRUE;
    }
    return $this->host;
  }

  /**
   * Derives the single host a route match is about.
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match to inspect.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The host, or NULL if there is none or more than one.
   */
  private function doDeriveHostFromRoute(RouteMatchInterface $route_match): ?HostInterface {
    $host = NULL;
    foreach ($route_match->getParameters()->all() as $parameter) {
      $candidate = NULL;
      if ($parameter instanceof EntityInterface && $this->hostResolver->hosts($parameter)) {
        $candidate = $this->hostResolver->fromEntity($parameter);
      }
      elseif ($parameter instanceof SectionStorageInterface) {
        $candidate = $this->hostResolver->fromSectionStorage($parameter);
      }
      if ($candidate === NULL) {
        continue;
      }
      if ($host === NULL) {
        $host = $candidate;
        continue;
      }
      if ($candidate->entityTypeId() === $host->entityTypeId() && $candidate->id() === $host->id()) {
        // The same host twice (an entity and its own storage). That's okay.
        continue;
      }
      // Two different hosts on one route: it is not obvious which one is
      // the canvas, so none is.
      return NULL;
    }
    return $host;
  }

  /**
   * Whether the route host's canvas edits an entity's fields in place.
   *
   * Editable attribution follows the host: a rendered entity's fields are
   * stamped editable only when it is the host's own fields entity or a
   * block placed in the host's own document. A render is a stack of
   * hosts (the template around a locked page, the page's own slot
   * sections inside it), and every inline block in it renders under the
   * page as the root (daedalus_layout's root entity registry forwards the
   * outer subject through every join), so the root alone says only that
   * the block is on this canvas: which document holds it is the host's
   * own sections' answer. A block in the host's own document edits in
   * place; a block of the template it renders inside is foreign, like the
   * template's other elements; a host with no document places nothing.
   * Core's sample entity on a template canvas, a media entity a formatter
   * renders and a linked blueprint's content are inert by construction,
   * not by a preview flag.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   A rendered entity.
   *
   * @return bool
   *   TRUE when the entity's fields edit on this canvas.
   */
  public function ownsFields(FieldableEntityInterface $entity): bool {
    $host = $this->deriveHostFromRoute();
    if ($host === NULL) {
      return FALSE;
    }
    $fields = $host->fields();
    if ($fields !== NULL && $fields->uuid() === $entity->uuid()) {
      return TRUE;
    }
    if (!$host->hasDocument() || $this->rootEntityRegistry === NULL) {
      return FALSE;
    }
    $root = $this->rootEntityRegistry->getRoot($entity);
    $subject = $host->subject();
    if ($root === NULL || $subject === NULL || $root->uuid() !== $subject->uuid()) {
      return FALSE;
    }
    if ($this->hostResolver->foreignOwner($host) === NULL) {
      // The host owns the whole document it renders: every block rooted
      // on it is its own.
      return TRUE;
    }
    // The host is rendered inside a foreign document: of the blocks rooted
    // on it, only those its own document holds (its slot sections, at any
    // depth) are its own. Identity is the block's uuid, since an inline
    // block is a fresh instance at every extraction.
    foreach ($this->layoutAdapter->getBlockContentEntities($host) as $placed) {
      if ($placed['entity']->uuid() === $entity->uuid()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The URL where an entity is edited with the page builder.
   *
   * A content entity is edited in place on its canonical route (the edit
   * mode chrome renders over the live page; a saved blueprint's canonical
   * route IS its canvas); a template on core's manage-display layout
   * route; a site frame on its canonical route too, the frame canvas
   * (FrameInterface::CANVAS_ROUTE, daedalus_layout's). Consumers that send
   * users "to where this entity is edited" (AI new-entity open, the portal
   * stamp, the Open blueprint verb) must go through this one derivation
   * rather than hand-rolling toUrl().
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to edit.
   *
   * @return \Drupal\Core\Url
   *   The editing surface URL.
   */
  public function getEditingUrl(EntityInterface $entity): Url {
    if ($entity instanceof LayoutEntityDisplayInterface) {
      // A template is edited on core's manage-display layout route, the
      // defaults storage's own URL (field_ui builds the route).
      $storage = $this->hostResolver->fromEntity($entity)->sectionStorage();
      if ($storage === NULL) {
        throw new \LogicException(sprintf('%s has no template to edit.', $entity->id()));
      }
      return $storage->getLayoutBuilderUrl();
    }
    return $entity->toUrl();
  }

  /**
   * Whether an entity has an editing surface getEditingUrl() can name.
   *
   * A page (an entity with a canonical route) does; a non-page entity (a
   * swatch, a variant, a skin: site-wide material with no page of its own)
   * does not, and a caller that would navigate to it asks here first instead
   * of catching the missing link template. A site frame's canonical link
   * names daedalus_layout's canvas route, so a frame has an editing
   * surface exactly when that route exists.
   */
  public function hasEditingUrl(EntityInterface $entity): bool {
    if ($entity instanceof LayoutEntityDisplayInterface) {
      return $entity->isLayoutBuilderEnabled()
        && $this->routeProvider->getRoutesByNames(["layout_builder.defaults.{$entity->getTargetEntityTypeId()}.view"]) !== [];
    }
    if ($entity instanceof FrameInterface) {
      return $this->routeProvider->getRoutesByNames([FrameInterface::CANVAS_ROUTE]) !== [];
    }
    return $entity->hasLinkTemplate('canonical');
  }

  /**
   * The word for what a host's document is to the editor.
   *
   * One rule for every surface that names a document's kind: the element
   * annotation's root type (BlueprintElementAnnotator::annotate()) and the
   * type word a portal stamp carries for the chip
   * (LayoutBuilderBlockAttributes::stampShowing()). A saved blueprint is a
   * "Blueprint" (what it IS to the editor: a document shown by reference
   * elsewhere), never its blueprint type's bundle label, which words the
   * kind of blueprint in the library's listing; the site frame is the
   * "Site frame", a Layout Builder display the "Template" it hosts, and
   * a content host its bundle's label ("Page").
   */
  public function hostKind(HostInterface $host): string {
    if ($host instanceof BlueprintHost) {
      return (string) $this->t('Blueprint');
    }
    if ($host instanceof FrameHost) {
      return (string) $this->t('Site frame');
    }
    if ($host instanceof DisplayHost) {
      return (string) $this->t('Template');
    }
    return $this->bundleLabel($host->entityTypeId(), $host->bundle());
  }

  /**
   * A bundle's label as a type word, translated config cast to a string.
   *
   * A bundle the info service no longer knows (its type deleted under a
   * placed block) still needs a word: the machine name, the one fact left.
   */
  public function bundleLabel(string $entity_type_id, string $bundle): string {
    $label = $this->bundleInfo->getBundleInfo($entity_type_id)[$bundle]['label'] ?? NULL;
    $label = $label === NULL ? '' : (string) $label;
    return $label !== '' ? $label : $bundle;
  }

  /**
   * Forgets the host derived from the route so the next read derives again.
   */
  public function clearDerivedHost(): void {
    $this->host = NULL;
    $this->hostDerived = FALSE;
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
