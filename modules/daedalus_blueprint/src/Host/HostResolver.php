<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Host;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\layout_builder\DefaultsSectionStorageInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Entity\SampleEntityGeneratorInterface;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Resolves the host of a document at the spine's doors.
 *
 * Four hosts exist: a fieldable entity is a content host, always; a saved
 * blueprint (the `blueprint` entity type, known here by its id so the spine
 * needs none of the library module's classes) is a blueprint host, a
 * content host whose document is for hosts of another kind; a Layout
 * Builder enabled entity view display is a display host, and a theme's
 * site frame (the `daedalus_frame` config entity) is a frame host, both
 * when layout_builder is installed. Any other entity (another config
 * entity, a display without Layout Builder) has no host and belongs to
 * another backend. The doors ask hosts() before fromEntity() when the
 * entity's kind is not already known.
 */
class HostResolver {

  /**
   * Constructs the resolver.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Field\FieldTypePluginManagerInterface $fieldTypeManager
   *   The field type plugin manager: a display host keeps a style map when
   *   the style map field type exists.
   * @param \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface|null $sectionStorageManager
   *   The section storage manager, or NULL without layout_builder.
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface|null $layoutTempstoreRepository
   *   The layout tempstore repository, or NULL without layout_builder.
   * @param \Drupal\layout_builder\Entity\SampleEntityGeneratorInterface|null $sampleEntityGenerator
   *   Core's sample entity generator (a display host's subject), or NULL
   *   without layout_builder.
   * @param \Closure|null $instantiator
   *   Service closure resolving daedalus_blueprint.document_instantiator,
   *   which seeds a content host's first override from its display's
   *   template. A closure because the instantiator reaches the operations,
   *   which resolve hosts through this resolver.
   */
  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected FieldTypePluginManagerInterface $fieldTypeManager,
    protected ?SectionStorageManagerInterface $sectionStorageManager = NULL,
    protected ?LayoutTempstoreRepositoryInterface $layoutTempstoreRepository = NULL,
    protected ?SampleEntityGeneratorInterface $sampleEntityGenerator = NULL,
    protected ?\Closure $instantiator = NULL,
  ) {}

  /**
   * Whether an entity has a host.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return bool
   *   TRUE for a fieldable entity, or a Layout Builder enabled display or a
   *   site frame on a site with layout_builder.
   */
  public function hosts(EntityInterface $entity): bool {
    return $entity instanceof FieldableEntityInterface || $this->isDisplayHost($entity) || $this->isFrameHost($entity);
  }

  /**
   * The host of an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   A fieldable entity (a content host), a Layout Builder enabled
   *   display (a display host) or a site frame (a frame host).
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface
   *   The host.
   *
   * @throws \InvalidArgumentException
   *   When nothing hosts the entity; ask hosts() first at a door that takes
   *   any entity.
   */
  public function fromEntity(EntityInterface $entity): HostInterface {
    if ($entity instanceof FieldableEntityInterface) {
      if ($entity->getEntityTypeId() === BlueprintHost::ENTITY_TYPE_ID) {
        if ($this->sectionStorageManager === NULL || $this->layoutTempstoreRepository === NULL || $this->sampleEntityGenerator === NULL) {
          throw new \LogicException('A blueprint is a host only with layout_builder installed: its document is core\'s override field and its subject is core\'s sample entity.');
        }
        return new BlueprintHost($entity, $this->moduleHandler, $this->sectionStorageManager, $this->layoutTempstoreRepository, $this->sampleEntityGenerator, $this->instantiator, $this);
      }
      return new ContentHost($entity, $this->moduleHandler, $this->sectionStorageManager, $this->layoutTempstoreRepository, $this->instantiator, $this);
    }
    if ($this->isDisplayHost($entity)) {
      /** @var \Drupal\layout_builder\Entity\LayoutEntityDisplayInterface $entity */
      return new DisplayHost(
        $entity,
        $this->moduleHandler,
        $this->sectionStorageManager,
        $this->layoutTempstoreRepository,
        $this->sampleEntityGenerator,
        $this->fieldTypeManager,
      );
    }
    if ($this->isFrameHost($entity)) {
      /** @var \Drupal\daedalus_blueprint\Entity\FrameInterface $entity */
      return new FrameHost(
        $entity,
        $this->moduleHandler,
        $this->sectionStorageManager,
        $this->layoutTempstoreRepository,
        $this->fieldTypeManager,
      );
    }
    throw new \InvalidArgumentException(sprintf('Nothing hosts %s %s: a host is a fieldable entity, a Layout Builder enabled display or a site frame.', $entity->getEntityTypeId(), $entity->id() ?? 'new'));
  }

  /**
   * The host of a section storage: the owner of the storage core resolved.
   *
   * A defaults storage (nested-aware wrapped or not) is owned by the
   * display in its context, whatever entity a render handed it as the
   * subject; an overrides storage by the entity in its. A storage with
   * neither (core's navigation layout) has no host.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $storage
   *   The storage.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The host, or NULL when the storage has none.
   */
  public function fromSectionStorage(SectionStorageInterface $storage): ?HostInterface {
    if (method_exists($storage, 'getRootStorage')) {
      $storage = $storage->getRootStorage();
    }
    $names = $storage instanceof DefaultsSectionStorageInterface
      ? ['display']
      : ['entity', 'layout_builder.entity'];
    foreach ($names as $name) {
      try {
        $context = $storage->getContext($name);
      }
      catch (ContextException) {
        continue;
      }
      if (!$context->hasContextValue()) {
        continue;
      }
      $value = $context->getContextValue();
      if ($value instanceof EntityInterface && $this->hosts($value)) {
        return $this->fromEntity($value);
      }
    }
    return NULL;
  }

  /**
   * The owner of the document a host is rendered inside without owning it.
   *
   * A host whose display is Layout Builder enabled but not overridable is
   * rendered inside its display's document: core's findByContext never
   * resolves its override storage there (OverridesSectionStorage::
   * isApplicable needs an overridable display), so the template is the
   * page, and every element of it is foreign to the host: readable and
   * selectable on its canvas, never mutable there. The owner is the
   * display's host, where the operation belongs.
   *
   * This keys on the display's overridability, not on hasDocument(): a
   * host can own a document AND be rendered inside a foreign one. A page
   * of a locked bundle carries the override field (daedalus_layout adds
   * it to every Layout Builder enabled bundle) and owns what that field
   * holds, its slot sections, while the template around them stays the
   * display's. A host with no document at all on an enabled display is
   * the same case with nothing of its own. A blueprint host renders
   * itself whatever its subject's display allows (its display is the
   * subject's, which it never falls back to), a display host is its own
   * display's owner, and a frame host, the outermost layer, has no display
   * at all and nothing around it.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The foreign document's owner, or NULL when the host owns the whole
   *   document it renders (its display allows overrides and it carries
   *   the field, its display renders no document, or it has no display).
   */
  public function foreignOwner(HostInterface $host): ?HostInterface {
    if ($host instanceof BlueprintHost) {
      return NULL;
    }
    $display = $host->display();
    if ($display === NULL || !$this->isDisplayHost($display) || $host->entity() === $display) {
      return NULL;
    }
    assert($display instanceof LayoutEntityDisplayInterface);
    if ($display->isOverridable() && $host->hasDocument()) {
      return NULL;
    }
    return $this->fromEntity($display);
  }

  /**
   * The host whose document a render of this host's subject shows.
   *
   * The one resolution rule: the host of any render is the owner of the
   * section storage core resolves for it (findByContext). Core's overrides
   * storage applies only when the rendered display is overridable AND the
   * entity's override holds sections (OverridesSectionStorage::isApplicable);
   * every other render of a Layout Builder enabled display shows the
   * display's template, whose sections and styles the display hosts. So an
   * overridden entity renders its own document, an un-overridden entity
   * (overridable or not) renders its display's, a display renders itself,
   * and an entity whose display has no Layout Builder renders its own
   * fields.
   *
   * Unlike foreignOwner(), this reads the override's CONTENT, not the
   * host's capability: a never-overridden entity of an overridable bundle
   * has a document door (hasDocument() is TRUE, the seed fills it on the
   * first edit) yet renders its template live until it is saved.
   *
   * A blueprint host renders itself, empty or not: its storage plugin is
   * applicable to the type (never to the override's content), so core
   * resolves the blueprint's own storage for every render of it, and its
   * display is the subject's, which it never falls back to. A frame host
   * renders itself too: it has no display to fall back to.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose subject is rendered.
   * @param \Drupal\Core\Entity\Display\EntityViewDisplayInterface|null $display
   *   The display rendering it (hook_entity_view's), or NULL for the
   *   host's own (the full view mode's).
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface
   *   The host owning the rendered document: the host itself or its
   *   display's host.
   */
  public function renderHost(HostInterface $host, ?EntityViewDisplayInterface $display = NULL): HostInterface {
    if ($host instanceof BlueprintHost) {
      return $host;
    }
    $display ??= $host->display();
    if ($display === NULL || !$this->isDisplayHost($display) || $host->entity() === $display) {
      return $host;
    }
    $entity = $host->entity();
    if ($entity instanceof FieldableEntityInterface
      && $display instanceof LayoutEntityDisplayInterface
      && $display->isOverridable()
      && $entity->hasField(ContentHost::LAYOUT_FIELD)
      && !$entity->get(ContentHost::LAYOUT_FIELD)->isEmpty()
    ) {
      return $host;
    }
    return $this->fromEntity($display);
  }

  /**
   * Whether an entity is a Layout Builder enabled display on a site that can host it.
   */
  protected function isDisplayHost(EntityInterface $entity): bool {
    return $this->sectionStorageManager !== NULL
      && $this->layoutTempstoreRepository !== NULL
      && $this->sampleEntityGenerator !== NULL
      && $entity instanceof LayoutEntityDisplayInterface
      && $entity->isLayoutBuilderEnabled();
  }

  /**
   * Whether an entity is a site frame on a site that can host it.
   *
   * The frame's document is Layout Builder sections, so without
   * layout_builder the entity is config nothing hosts.
   */
  protected function isFrameHost(EntityInterface $entity): bool {
    return $this->sectionStorageManager !== NULL
      && $this->layoutTempstoreRepository !== NULL
      && $entity instanceof FrameInterface;
  }

}
