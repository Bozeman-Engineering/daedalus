<?php

namespace Drupal\daedalus_ui\Hooks;

use Drupal\Core\Render\Element;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_ui\BlueprintMirror;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_tempstore\ParamConverter\EntityConverter;
use Drupal\daedalus_ui\DaedalusUi;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\daedalus_ui\MainEntityWrapperTrait;
use Drupal\daedalus_ui\Event\EditableUiBuilder;
use Drupal\daedalus_ui\LoadEditablePageResponseTrait;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Replaces an entity's build with the Edit Mode UI (hook_entity_view_alter).
 */
class EntityViewAlter {

  use LoadEditablePageResponseTrait;
  use MainEntityWrapperTrait;

  public function __construct(
    private readonly DaedalusUi $daedalusUi,
    protected readonly EventDispatcherInterface $eventDispatcher,
    private readonly RequestStack $requestStack,
    private readonly BlueprintMirror $blueprintMirror,
    private readonly AccountProxyInterface $currentUser,
    private readonly EntityConverter $entityConverter,
  ) {}

  /**
   * Entity view alter.
   *
   * Replaces the entity build with an editable UI.
   *
   * LoadEditablePage is the AJAX version.
   *
   * @param array $build
   *   The entity render array.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being rendered.
   * @param \Drupal\Core\Entity\Display\EntityViewDisplayInterface $display
   *   The display mode.
   *
   * @return array|void
   *   A render array for the workspace violation or void if returning early.
   */
  public function alter(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display) {
    $mode = $this->daedalusUi->getMode();

    if ($mode === 'preview') {
      // Preview mode renders the staged tempstore entity through the normal,
      // chrome-free build. PreviewRenderCacheBypass keeps that render out of
      // the render cache; this max-age bubbles to the RESPONSE so the page
      // and dynamic page caches never store a preview page either (staged
      // content changes with no cache tag invalidation).
      if ($this->isRouteEntityBuild($build)) {
        $build['#cache']['max-age'] = 0;
      }
      return;
    }

    if (!$this->currentUser->hasPermission('use daedalus ui edit mode')) {
      return;
    }

    if ($mode !== 'edit') {
      return;
    }

    if (!$this->isRouteEntityBuild($build)) {
      return;
    }

    $violation = $this->ensureWorkspace($entity);
    if ($violation) {
      // #theme_wrappers included: a root block_content build carries a
      // container wrapper with the full entity UI attribute set
      // (DaedalusUiHooks::entityView()); left in place it would render
      // around the violation's own main-entity wrapper as a duplicate
      // data-main-entity root.
      unset($build['#theme'], $build['#theme_wrappers'], $build['#pre_render'], $build['_layout_builder']);
      foreach (Element::children($build) as $key) {
        unset($build[$key]);
      }
      $build['workspace_violation']['message']['#markup'] = $violation;
      $this->mainEntityWrapper($entity, $build['#view_mode'], $build['workspace_violation']);
      $build['#cache']['max-age'] = 0;

      return $build;
    }
    else {
      $this->buildEditModeUi($mode, $entity, $build, $display);
    }

  }

  /**
   * Is this build the entity the route is about?
   *
   * There could be multiple potentially editable entities on the page; the
   * edit and preview paths only act on the requested one.
   */
  private function isRouteEntityBuild(array $build): bool {
    $route_entity = $this->daedalusUi->deriveEntityFromRoute();
    if (empty($route_entity)) {
      return FALSE;
    }
    $entity_type_id = $route_entity->getEntityTypeId();
    if (empty($build["#$entity_type_id"])) {
      return FALSE;
    }
    return $build["#$entity_type_id"]->id() === $route_entity->id();
  }

  /**
   * Builds the Edit Mode UI around the entity build.
   */
  public function buildEditModeUi(string $mode, EntityInterface $entity, array &$build, EntityViewDisplayInterface $display) {

    $entity = $this->entityConverter()->loadEntityFromTempstore($entity);

    // The editable elements need attributes and wrappers to let the JS know
    // what is editable. When this entity is rendered via a page request or
    // LoadEditablePage, dispatch a builder event and let Daedalus Layout
    // replace the page with its UI. We don't need to worry about Daedalus Edit because
    // it adds its field attributes based on whether we are in Edit mode.
    $event = $this->eventDispatcher->dispatch(new EditableUiBuilder($build, $mode, $entity, $build['#view_mode']), EditableUiBuilder::class);
    $build = $event->getBuild();

    // The boot delivery of the client blueprint mirror; BlueprintMirror
    // documents the full seam family (boot, form pipeline, spine responses)
    // and stands down for the blueprint half inside a spine mutation render.
    $this->blueprintMirror->attach($build, $entity, $build['#view_mode']);
  }

  /**
   * Gets the tempstore-aware entity converter.
   */
  public function entityConverter() {
    return $this->entityConverter;
  }

}
