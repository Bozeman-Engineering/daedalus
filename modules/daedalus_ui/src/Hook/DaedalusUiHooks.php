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
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_ui\ModePluginManager;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\Core\StringTranslation\StringTranslationTrait;

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
  ) {
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
