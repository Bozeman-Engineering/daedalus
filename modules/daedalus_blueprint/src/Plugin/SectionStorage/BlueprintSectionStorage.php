<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Plugin\SectionStorage;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\layout_builder\Attribute\SectionStorage;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * The section storage of a saved blueprint.
 *
 * Core's overrides storage over the blueprint entity's override field,
 * same field, same tempstore key shape, same lifecycle for inline blocks,
 * differing in what the document is FOR: a blueprint is material for hosts
 * of another kind (its subject: the entity type and bundle stamped on it),
 * so the sections render and are offered against the subject, not the
 * blueprint. Three answers change. The preview contexts carry the sample
 * entity of the subject bundle as layout_builder.entity, so the Add
 * panel's plugin roster (PlacementRestrictions::populatedContexts) and
 * every block render on the canvas see the subject's fields, never
 * field_block:blueprint:blueprint:*. The default section storage is the
 * subject bundle's display, the ROOT display whose placement restrictions
 * govern the canvas. And the storage is applicable to the TYPE and always
 * overridden: a blueprint always renders and edits its own document, empty
 * or not, where core's overrides storage would fall through to the
 * display's defaults for an empty override at render and copy them in
 * when the canvas is prepared.
 *
 * Ahead of core's overrides storage (weight -20) so core's findByContext
 * answers this plugin for a blueprint; the entity context's type keeps it
 * off every other entity. The blueprint's canvas is its canonical route,
 * so no layout routes or local tasks are built here (the library module
 * removes core's for the type: one canvas, one storage).
 *
 * @see \Drupal\daedalus_blueprint\Host\BlueprintHost
 *
 * @internal
 *   Plugin classes are internal.
 */
#[SectionStorage(
  id: BlueprintHost::STORAGE_TYPE,
  weight: -30,
  context_definitions: [
    'entity' => new EntityContextDefinition(
      data_type: 'entity:' . BlueprintHost::ENTITY_TYPE_ID,
      label: new TranslatableMarkup('Blueprint'),
    ),
    'view_mode' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('View mode'),
      default_value: 'default',
    ),
  ],
  handles_permission_check: TRUE,
)]
class BlueprintSectionStorage extends OverridesSectionStorage {

  /**
   * The blueprint's host, memoized: its subject is one sample entity.
   */
  protected ?BlueprintHost $host = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    SectionStorageManagerInterface $section_storage_manager,
    EntityRepositoryInterface $entity_repository,
    AccountInterface $current_user,
    protected HostResolver $hostResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $entity_field_manager, $section_storage_manager, $entity_repository, $current_user);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.layout_builder.section_storage'),
      $container->get('entity.repository'),
      $container->get('current_user'),
      $container->get('daedalus_blueprint.host_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * The host memo is request state and never travels: the layout tempstore
   * serializes this plugin with every staged edit, and a host is not a
   * service, so the serialization trait would store it whole, services and
   * all, and the database connection they reach refuses. Dropped here, it
   * is rebuilt from the entity context on the next host() call.
   */
  public function __sleep(): array {
    return array_values(array_diff(parent::__sleep(), ['host']));
  }

  /**
   * The blueprint host over the entity in context.
   *
   * The one spelling of the subject and its display is the host's;
   * the storage asks it rather than reading the fields again.
   */
  protected function host(): BlueprintHost {
    if ($this->host === NULL || $this->host->entity() !== $this->getEntity()) {
      $host = $this->hostResolver->fromEntity($this->getEntity());
      assert($host instanceof BlueprintHost);
      $this->host = $host;
    }
    return $this->host;
  }

  /**
   * {@inheritdoc}
   *
   * The subject bundle's display: the ROOT display the canvas's placement
   * restrictions and default section settings come from, since the
   * blueprint's material is placed on pages of that bundle.
   */
  public function getDefaultSectionStorage() {
    return $this->sectionStorageManager->load('defaults', [
      'display' => EntityContext::fromEntity($this->host()->display()),
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * The subject as the entity being laid out: field blocks render against
   * it and the block roster is filtered by its entity type and bundle.
   */
  public function getContextsDuringPreview() {
    $contexts = parent::getContextsDuringPreview();
    $contexts['layout_builder.entity'] = EntityContext::fromEntity(
      $this->host()->subject(),
      new TranslatableMarkup('@entity being laid out', ['@entity' => $this->host()->subject()->getEntityType()->getSingularLabel()]),
    );
    return $contexts;
  }

  /**
   * {@inheritdoc}
   *
   * Applicable to the type: a blueprint renders its own document whether
   * or not a section stands in it, so an empty blueprint is an empty
   * canvas, never the subject display's template.
   */
  public function isApplicable(RefinableCacheableDependencyInterface $cacheability) {
    $cacheability->addCacheableDependency($this);
    return $this->getEntity()->getEntityTypeId() === BlueprintHost::ENTITY_TYPE_ID;
  }

  /**
   * {@inheritdoc}
   *
   * Always: a blueprint's document is its own, empty or not. Core reads
   * "not overridden" as "still the default storage's layout" and its
   * PrepareLayout subscriber then copies the default storage's sections in,
   * which for a blueprint is the subject bundle's template (and only its
   * sections: the template's styles stay on the display).
   */
  public function isOverridden() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * Core's permission ladder over the blueprint type (configure any layout,
   * the type's all and editable override permissions), then the
   * blueprint's OWN display carrying Layout Builder with overrides allowed.
   * Core asks the default section storage here, which for a blueprint is
   * the subject bundle's display: whether that bundle's pages may be
   * overridden says nothing about editing a blueprint for them.
   */
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account ??= $this->currentUser;
    $entity = $this->getEntity();

    $result = AccessResult::allowedIfHasPermission($account, 'configure any layout')
      ->orIf(AccessResult::allowedIfHasPermission($account, "configure all {$entity->bundle()} {$entity->getEntityTypeId()} layout overrides"))
      ->orIf(AccessResult::allowedIfHasPermission($account, "configure editable {$entity->bundle()} {$entity->getEntityTypeId()} layout overrides")->andIf($entity->access('update', $account, TRUE)));

    $own_display = LayoutBuilderEntityViewDisplay::collectRenderDisplay($entity, $this->getContextValue('view_mode'));
    $overridable = $own_display instanceof LayoutEntityDisplayInterface && $own_display->isLayoutBuilderEnabled() && $own_display->isOverridable();
    $result = $result->andIf(AccessResult::allowedIf($overridable)->addCacheableDependency($own_display));
    $result = $this->handleTranslationAccess($result, $operation, $account);
    return $return_as_object ? $result : $result->isAllowed();
  }

  /**
   * {@inheritdoc}
   *
   * The canvas is the canonical route (in Edit Mode); there is no other
   * layout page for a blueprint.
   */
  public function getLayoutBuilderUrl($rel = 'view') {
    return $this->getEntity()->toUrl('canonical');
  }

  /**
   * {@inheritdoc}
   *
   * No layout routes: the canonical route is the canvas. Core's generic
   * section storage routes (the dialogs) still address this storage by
   * type and id through deriveContextsFromRoute().
   */
  public function buildRoutes(RouteCollection $collection) {
  }

  /**
   * {@inheritdoc}
   */
  public function buildLocalTasks($base_plugin_definition) {
    return [];
  }

}
