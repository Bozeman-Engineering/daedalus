<?php

namespace Drupal\daedalus_ui\Hook;

use Drupal\block\BlockInterface;
use Drupal\block\Entity\Block;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_ui\Chromeless;
use Drupal\daedalus_ui\EditorSettings;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_ui\ModePluginManager;
use Drupal\daedalus_ui\ModeState;
use Drupal\daedalus_ui\Render\ElementAddress;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Hook implementations for daedalus_ui.
 */
class DaedalusUiHooks {

  use StringTranslationTrait;

  public function __construct(
    protected DaedalusUi $ui,
    protected ModePluginManager $modeManager,
    protected PageBuilderDeclarations $declarations,
    protected AccountInterface $currentUser,
    protected ModeState $modeState,
    protected EditorSettings $editorSettings,
    protected Chromeless $chromeless,
    protected RequestStack $requestStack,
  ) {
  }

  /**
   * Implements hook_page_attachments().
   *
   * What a pop-out document carries, in one place: the daedalus_ui/popout
   * library (the pop-out document chrome, anchored on the html class
   * preprocessHtml stamps, and the keyboard half that senses every Edit
   * Mode shortcut pressed in the pop-out for the editing page's
   * dispatcher) and the pop-out's session identity,
   * drupalSettings.daedalusUi.popout.scope. The scope is the route
   * contract's one input (Chromeless: a pop-out is opened with
   * ?scope=<edit session scope>), read here once server-side and delivered
   * as a setting so every script in the document (the preview shell, the
   * stylish satellite, the keyboard half) reads the same value and none
   * parses the URL; the editing page delivers the same identity as
   * daedalusUi.editSession.scope. A hand-typed pop-out URL without a scope
   * carries null: no edit session to pair with, so the document renders
   * static. Preview mode is a site page with the editor gone and gets
   * neither; the chromeless drop itself stays in preprocessHtml (this seam
   * only adds to a page; Chromeless says why the drop lives there).
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    if (!$this->chromeless->isPopout()) {
      return;
    }
    $attachments['#attached']['library'][] = 'daedalus_ui/popout';
    $attachments['#attached']['drupalSettings']['daedalusUi']['popout'] = [
      'scope' => $this->requestStack->getCurrentRequest()->query->get('scope'),
    ];
    $attachments['#cache']['contexts'][] = 'url.query_args:scope';
  }

  /**
   * Implements hook_preprocess_HOOK() for html.
   *
   * Three things ride the html element, all stamped server-side so the
   * first paint is right without JS.
   *
   * The active mode, as data-daedalus-ui-mode. All mode-dependent chrome
   * visibility hangs off this one attribute: CSS hides the core navigation
   * sidebar and top bar and reveals the mode's own top bar when it is
   * present. The server stamps it from the (path-scoped) cookie so the
   * initial render is flicker-free; the client-side mode toggle flips the
   * same attribute.
   *
   * The chrome density preference, as data-daedalus-ui-chrome-density, for
   * the same reason: CSS and the reveal engine read only this attribute, so
   * stamping it SSR means clean mode never flashes its hidden chrome before
   * JS boots. It is stamped whenever the Edit mode is AVAILABLE on the
   * request, the same predicate under which the edit chrome itself renders
   * (present but hidden until the mode is active, see
   * DaedalusUi::buildPageTop), not only while the mode is active: the
   * client-side toggle flips the mode stamp alone, so a density stamp tied
   * to the active mode is missing after an AJAX entry and every consumer
   * reads the absence as full density until a reload. The preference is
   * per-user (EditorSettings), hence the user cache context; the response
   * already varies per user wherever the chrome renders (every sidebar
   * panel carries that context), so the stamp adds no new cache variation.
   *
   * The chromeless drop: a chromeless document (Chromeless: a pop-out
   * route, a preview-mode page) prints no page_top and no page_bottom.
   * Core's ThemePreprocess::preprocessHtml hands both regions to the
   * template as unrendered arrays and Twig renders them at print, so
   * nulling them here keeps the markup AND its #attached (bundles, the AI
   * chat client, drupalSettings) out of the document, whatever order the
   * page_top hooks ran in. This is the one spine for every pop-out's and
   * the preview iframe's chrome-freeness; no route carries its own.
   *
   * A pop-out additionally wears the daedalus-ui-popout class on the html
   * element, the anchor of the pop-out document chrome (the daedalus_ui/popout
   * stylesheet pageAttachments delivers: the body wears the chrome, the
   * theme's wrappers between body and mount inherit, its header and footer
   * stay out). The class is a template variable, so it is stamped here;
   * everything else a pop-out document carries rides pageAttachments.
   * Preview mode gets none of it: it IS a site page with the editor gone.
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    $variables['#cache']['contexts'][] = 'daedalus_ui_mode';
    $variables['#cache']['contexts'][] = 'route';
    if ($this->chromeless->applies()) {
      $variables['page_top'] = NULL;
      $variables['page_bottom'] = NULL;
      if ($this->chromeless->isPopout()) {
        $variables['html_attributes']->addClass('daedalus-ui-popout');
      }
    }

    $mode = $this->modeState->getMode();
    if ($mode !== 'none') {
      $variables['html_attributes']->setAttribute('data-daedalus-ui-mode', $mode);
      // In a mode the page is the user's own editing session (staged
      // tempstore content), so it varies per user regardless of any
      // preference.
      $variables['#cache']['contexts'][] = 'user';
      if ($mode === 'edit') {
        // Seed the displace offsets the open rails will produce so first
        // paint agrees with the SSR-rendered panels: consumers of
        // --drupal-displace-offset-left/right otherwise pay the 0px fallback
        // until displace.js first runs and flash full-width. displace.js
        // writes measured values over these same inline properties,
        // correcting drift.
        $rails = $this->ui->getRailWidths();
        array_push($variables['#cache']['contexts'], ...$rails['cache_contexts']);
        $seed = '';
        foreach ($rails['widths'] as $side => $width) {
          if ($width !== NULL) {
            $seed .= "--drupal-displace-offset-$side: $width;";
          }
        }
        if ($seed !== '') {
          $variables['html_attributes']->setAttribute('style', $seed);
        }
      }
    }

    // The mode plugins are memoized; hook_page_top already resolved them
    // for this request when it built the chrome.
    $modes = $this->modeManager->getModePlugins();
    if (isset($modes['edit'])) {
      $variables['#cache']['contexts'][] = 'user';
      $density = $this->editorSettings->getChromeDensity();
      $variables['html_attributes']->setAttribute('data-daedalus-ui-chrome-density', $density);
    }

    $variables['#cache']['contexts'][] = 'user.permissions';
  }

  /**
   * Implements hook_modules_installed().
   *
   * The page_builder declaration discovery caches permanently, and module
   * install clears only plugin caches (core flushes every bin on uninstall,
   * but not on install), so an entry written while a declaring module was
   * not yet enabled would live forever. A batched recipe apply does exactly
   * that: discovery can run mid-batch, before later modules land, and the
   * stale layered result then misses their declarations (a collapse fact, a
   * style guide section) until someone happens to rebuild caches.
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    $this->declarations->resetCache();
  }

  /**
   * Implements hook_themes_installed().
   *
   * Themes layer their declarations over the modules' (base themes before
   * the active theme), so the layered result is stale the moment a theme
   * carrying a THEME.page_builder.yml lands. Same permanence problem as
   * modules_installed above.
   */
  #[Hook('themes_installed')]
  public function themesInstalled(array $theme_list): void {
    $this->declarations->resetCache();
  }

  /**
   * Implements hook_themes_uninstalled().
   *
   * Theme uninstall does not flush every bin the way module uninstall does,
   * so the layered per-theme entries are reset here explicitly.
   */
  #[Hook('themes_uninstalled')]
  public function themesUninstalled(array $themes): void {
    $this->declarations->resetCache();
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for design_system.
   *
   * A module becomes a design-system package the moment a system naming
   * it lands, and a package layers only while active: on a recipe install
   * the package's files were already read as an ordinary module's before
   * its config imported (the same request, so the memo, not only the
   * tagged entry, is stale). Landing, leaving, and a package edit all
   * change the provider set, so each resets the layered result.
   */
  #[Hook('design_system_insert')]
  public function designSystemInsert(EntityInterface $system): void {
    $this->declarations->resetCache();
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for design_system.
   */
  #[Hook('design_system_update')]
  public function designSystemUpdate(EntityInterface $system): void {
    $this->declarations->resetCache();
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for design_system.
   */
  #[Hook('design_system_delete')]
  public function designSystemDelete(EntityInterface $system): void {
    $this->declarations->resetCache();
  }

  /**
   * Implements hook_library_info_alter().
   *
   * Attaches the cosmetic tier to the core libraries it skins, so core's
   * Navigation rail and top bar wear navigation_theme on every route and
   * any jQuery UI dialog (the media library included) wears dialog-skin.
   * Both are ungated on purpose: sites re-skin through the
   * daedalus_ui/variables tokens, not by withholding the libraries.
   */
  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension === 'navigation' && isset($libraries['internal.navigation'])) {
      $libraries['internal.navigation']['dependencies'][] = 'daedalus_ui/navigation_theme';
    }
    if ($extension === 'core' && isset($libraries['drupal.dialog'])) {
      $libraries['drupal.dialog']['dependencies'][] = 'daedalus_ui/dialog-skin';
    }
  }

  /**
   * Implements hook_page_top().
   */
  #[Hook('page_top', order: Order::Last)]
  public function pageTop(array &$page_top): void {
    $this->ui->buildPageTop($page_top);
  }

  /**
   * Implements hook_entity_view().
   *
   * Stamps the entity UI attributes on the entity build so they ride the
   * entity template's own root element: core merges a render element's
   * #attributes into the template's {{ attributes }}
   * (\Drupal\Core\Theme\ThemeManager::render()). Attributes are inert bytes
   * on an existing element, so editors see the exact markup structure
   * visitors do, with no wrapper div changing the tree.
   *
   * The attributes carried:
   * - data-element: the entity root's element address (ElementAddress),
   *   in every mode and for every visitor; everything below is an editor's.
   * - data-daedalus-ui-entity-wrapper: the canonical wrapper id AJAX
   *   refreshes target (daedalus_ui_entity_wrapper_id()).
   * - data-daedalus-ui-view-mode: the view mode the entity was built
   *   with, disambiguating the same entity rendered twice.
   * - data-selectable + data-entity-type/id: the entity root's selection
   *   identity and load metadata.
   * - data-main-entity + data-entity-uuid: marks the route's main entity,
   *   the stage for the editing UI.
   *
   * Content blocks are normally attributed at their Layout Builder placement
   * instead (LayoutBuilderBlockAttributes): their canonical selection identity
   * is the placement (block:{component_uuid}) and their markup root is the
   * block template. The exception is a block_content that IS the route's
   * entity: on its standalone editing surface (the
   * daedalus_ui.block_content.edit route) the entity is the page builder
   * ROOT, its selection identity is the entity itself, and it carries the
   * full attribute set. Core renders block_content without an entity template
   * (BlockContentViewBuilder unsets #theme; the block template that would
   * normally wrap it is absent when the entity view is the canvas), so a
   * container wrapper is added as the attribute carrier for that one
   * template-less root shape.
   *
   * Delivery depends on the template printing {{ attributes }} on its
   * outermost element; EntityUiWrapper verifies that after render and wraps
   * as a fallback when a template fails to.
   *
   * A block_content that is NOT the route's entity (a footer placed in a
   * theme region, rendered on a node page) gets none of that: it stays out
   * of the page's selection universe and edits at its own root. What it gets
   * instead is the portal stamp: the placement carries the label and URL of
   * that root so the canvas can offer the way there (the portal chip).
   */
  #[Hook('entity_view')]
  public function entityView(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, string $view_mode): void {
    $is_block_content = $entity->getEntityTypeId() === 'block_content';
    if ($is_block_content && !$this->isRouteEntity($entity)) {
      $this->stampPortal($build, $entity);
      return;
    }
    $build['#attributes'][ElementAddress::ATTRIBUTE] = ElementAddress::ENTITY;
    if (!$this->modeManager->createInstance('edit')->applies()) {
      return;
    }

    $build['#attributes']['class'][] = 'daedalus-ui-entity-wrapper';
    $build['#attributes']['data-daedalus-ui-entity-wrapper'] = daedalus_ui_entity_wrapper_id($entity);
    $build['#attributes']['data-daedalus-ui-view-mode'] = $view_mode;
    if ($entity->uuid()) {
      $build['#attributes'][SelectableKey::ATTRIBUTE] = SelectableKey::entity($entity->uuid());
      $build['#attributes']['data-entity-type'] = $entity->getEntityTypeId();
      $build['#attributes']['data-entity-id'] = daedalus_ui_entity_identifier($entity);
    }

    // Is this the main entity for the page? Same route-derived check the
    // wrapper always made; its route variance has no matching cache context
    // (pre-existing exposure, unchanged by moving the stamping site).
    if ($this->isRouteEntity($entity)) {
      $build['#attributes']['data-main-entity'] = 'true';
      $build['#attributes']['data-entity-uuid'] = $entity->uuid();
    }

    if ($is_block_content) {
      // The template-less root: nothing merges this build's #attributes into
      // markup (no entity template, no wrapping block template), so the build
      // itself must supply the element they ride. The container is the canvas
      // root the mutation-response contract swaps at, and it renders
      // identically in the page render and SubtreeRenderer's re-render (both
      // are the entity view through this same hook), so root-scoped swap
      // markup always matches an element on the page.
      $build['#theme_wrappers'][] = 'container';
    }
  }

  /**
   * Stamps the portal attributes on a foreign block_content's build.
   *
   * A portal is a placed entity that is inert on this page and edited at its
   * own root. The stamp names that root: data-daedalus-ui-portal-url is
   * the one editing-surface derivation (DaedalusUi::getEditingUrl())
   * and data-daedalus-ui-portal-label is the entity's own label (the
   * block description: "Header", "Footer"). The build's #attributes reach the
   * theme placement's block template root (BlockViewBuilder::preRender()
   * hoists the content's #attributes onto the block build), the same seam
   * Daedalus Stylish's entity root key rides; no wrapper is added because the block
   * template already supplies the element.
   *
   * Gated on the edit-mode permission, not the mode, the way daedalus_layout stamps
   * section keys: the attributes are bytes that ride for editors in every
   * mode, so a cached theme block varies only by user.permissions (plus the
   * update-access result's own cacheability), never by mode. A user without
   * the permission or update access gets the plain render.
   */
  private function stampPortal(array &$build, EntityInterface $entity): void {
    $access = $this->ui->pageBuilderBlockContentAccess($entity);
    $cacheability = BubbleableMetadata::createFromRenderArray($build)
      ->addCacheContexts(['user.permissions'])
      ->addCacheableDependency($access);
    if ($access->isAllowed() && $this->currentUser->hasPermission('use daedalus ui edit mode')) {
      $url = $this->ui->getEditingUrl($entity)->toString(TRUE);
      $cacheability->addCacheableDependency($url);
      $build['#attributes']['data-daedalus-ui-portal-url'] = $url->getGeneratedUrl();
      $build['#attributes']['data-daedalus-ui-portal-label'] = (string) $entity->label();
    }
    $cacheability->applyTo($build);
  }

  /**
   * Is this entity the one the current route is about?
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being rendered.
   *
   * @return bool
   *   TRUE when the route's derived entity is this entity.
   */
  private function isRouteEntity(EntityInterface $entity): bool {
    $route_entity = $this->ui->deriveEntityFromRoute();
    return !empty($route_entity) &&
      $entity->getEntityTypeId() === $route_entity->getEntityTypeId() &&
      (
        daedalus_ui_entity_identifier($entity) === $route_entity->id() ||
        daedalus_ui_entity_identifier($entity) === $route_entity->uuid()
      );
  }

  /**
   * Implements hook_entity_operation().
   *
   * The block layout listing (/admin/structure/block) is where a theme-region
   * block placement lives, so it is also the entry point to the standalone
   * editing surface of the block_content the placement wraps: an "Edit
   * layout" operation on placements whose plugin wraps a Layout
   * Builder-enabled block_content the user may update (the one predicate,
   * DaedalusUi::pageBuilderBlockContentAccess(), shared with the
   * portal stamp). The URL comes from the one editing-surface derivation
   * (DaedalusUi::getEditingUrl()); access mirrors the editing route's
   * gate (block_content update).
   */
  #[Hook('entity_operation')]
  public function entityOperation(EntityInterface $entity): array {
    if (!$entity instanceof Block) {
      return [];
    }
    if (!str_starts_with($entity->getPluginId(), 'block_content:')) {
      return [];
    }
    $block_content = $this->ui->getBlockPluginEntity($entity);
    if (!$block_content || !$this->ui->pageBuilderBlockContentAccess($block_content)->isAllowed()) {
      return [];
    }
    return [
      'edit-layout' => [
        'title' => $this->t('Edit layout'),
        'url' => $this->ui->getEditingUrl($block_content),
        'weight' => 15,
      ],
    ];
  }

  /**
   * Implements hook_block_access().
   *
   * A block_content's standalone editing surface renders the entity itself as
   * the canvas. When that same entity is also placed in a theme region (the
   * whole point of a footer block), the placement would render a second copy
   * of the entity on its own editing page: in edit mode a second Layout
   * Builder + canvas and a duplicate data-main-entity root, breaking every
   * first-match main-entity lookup and mutation-response swap target. The
   * placement is hidden while its content entity is the route's entity; it
   * renders normally everywhere else. The outcome varies by route, so the
   * route cache context rides every result this hook forms an opinion on
   * (block_content placements), keeping a cached allowed render from leaking
   * onto the editing surface.
   */
  #[Hook('block_access')]
  public function blockAccess(BlockInterface $block, string $operation, AccountInterface $account): AccessResultInterface {
    if ($operation !== 'view') {
      return AccessResult::neutral();
    }
    $plugin_id = $block->getPluginId();
    if (!str_starts_with($plugin_id, 'block_content:')) {
      return AccessResult::neutral();
    }
    $route_entity = $this->ui->deriveEntityFromRoute();
    $wraps_route_entity = $route_entity instanceof EntityInterface
      && $route_entity->getEntityTypeId() === 'block_content'
      && $plugin_id === 'block_content:' . $route_entity->uuid();
    return AccessResult::forbiddenIf($wraps_route_entity, "The placed block's content entity is this route's editing canvas.")
      ->addCacheContexts(['route']);
  }

}
