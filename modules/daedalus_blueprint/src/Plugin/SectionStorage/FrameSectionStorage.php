<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Plugin\SectionStorage;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\layout_builder\Attribute\SectionStorage;
use Drupal\layout_builder\Plugin\SectionStorage\SectionStorageBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * The section storage of a site frame.
 *
 * The frame entity is its own section list, so this storage is the
 * defaults storage's shape over a frame instead of a display: the section
 * list is the entity in the `entity` context, the storage id and the
 * tempstore key are the theme's machine name, a save is the frame's config
 * save, and access is the one frame permission. It is neither a defaults
 * nor an overrides storage: nothing is overridden (the frame is the
 * outermost layer of the document stack) and nothing falls back to it
 * (the frame fills no display), so core's defaults-to-overrides ladder
 * never sees it; core's findByContext answers it for a frame entity alone
 * (the context's type keeps it off every other entity), ahead of the
 * overrides storage like the blueprint storage.
 *
 * The frame's preview contexts carry no subject: the frame renders around
 * every route, with or without an entity, so no field block is offered on
 * its canvas and a block that needs an entity context stays off the roster
 * (the page variant forwards the route's entity to the frame's slots at
 * render). No layout routes: the frame's canvas is its own route.
 *
 * @see \Drupal\daedalus_blueprint\LayoutBuilder\FrameHost
 *
 * @internal
 *   Plugin classes are internal.
 */
#[SectionStorage(
  id: FrameHost::STORAGE_TYPE,
  weight: -30,
  context_definitions: [
    'entity' => new EntityContextDefinition(
      data_type: 'entity:' . FrameInterface::ENTITY_TYPE_ID,
      label: new TranslatableMarkup('Site frame'),
    ),
  ],
  handles_permission_check: TRUE,
)]
final class FrameSectionStorage extends SectionStorageBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getSectionList() {
    return $this->getContextValue('entity');
  }

  /**
   * The frame in context.
   */
  protected function getFrame(): FrameInterface {
    $frame = $this->getSectionList();
    assert($frame instanceof FrameInterface);
    return $frame;
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageId() {
    return $this->getFrame()->id();
  }

  /**
   * {@inheritdoc}
   *
   * The frame is seen on every route; the front page stands for them.
   */
  public function getRedirectUrl() {
    return Url::fromRoute('<front>');
  }

  /**
   * {@inheritdoc}
   *
   * The frame has no Layout Builder page: its canvas is a route of its
   * own (daedalus_layout's frame canvas), reached through the frame
   * entity's links, never through core's layout routes.
   */
  public function getLayoutBuilderUrl($rel = 'view') {
    throw new \LogicException(sprintf('The site frame of %s has no Layout Builder page; its canvas is the frame canvas route.', $this->getStorageId()));
  }

  /**
   * {@inheritdoc}
   *
   * No layout routes: core's generic section storage routes still address
   * this storage by type and id through deriveContextsFromRoute().
   */
  public function buildRoutes(RouteCollection $collection) {
  }

  /**
   * {@inheritdoc}
   */
  public function deriveContextsFromRoute($value, $definition, $name, array $defaults) {
    $frame = is_string($value) && $value !== ''
      ? $this->entityTypeManager->getStorage(FrameInterface::ENTITY_TYPE_ID)->load($value)
      : NULL;
    return $frame instanceof FrameInterface ? ['entity' => EntityContext::fromEntity($frame)] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->getFrame()->label();
  }

  /**
   * {@inheritdoc}
   */
  public function save() {
    return $this->getFrame()->save();
  }

  /**
   * {@inheritdoc}
   *
   * Applicable to the type: a frame always renders its own document.
   */
  public function isApplicable(RefinableCacheableDependencyInterface $cacheability) {
    $cacheability->addCacheableDependency($this);
    return $this->getContextValue('entity') instanceof FrameInterface;
  }

  /**
   * {@inheritdoc}
   *
   * The one frame permission, whatever the operation: the frame is the
   * site builder's document (as core's defaults storage answers
   * `configure any layout` for a template).
   */
  public function access($operation, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account ??= $this->currentUser;
    $result = AccessResult::allowedIfHasPermission($account, FrameInterface::PERMISSION);
    return $return_as_object ? $result : $result->isAllowed();
  }

}
