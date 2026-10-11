<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Host;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\layout_builder\Entity\SampleEntityGeneratorInterface;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;

/**
 * A saved blueprint as the host of its own document.
 *
 * The third host: a `blueprint` content entity (daedalus_blueprint_library
 * owns the type; the spine knows it by its entity type id and two field
 * names, so a blueprint resolves without the library's classes) holds a
 * page document the way a page does, in core's override field and the
 * style map field, and is edited on its own canvas. What differs from a
 * page is WHAT the document is for: a blueprint is material for hosts of
 * another kind (its subject: the entity type and bundle stamped on it), so
 * its render display is the subject's, its subject is core's sample entity
 * of that bundle (shown, never saved) and its section storage is the
 * `blueprint` plugin (BlueprintSectionStorage), core's overrides storage
 * answering the subject as the preview entity so field blocks render and
 * are offered against the subject's fields instead of the blueprint's. It
 * is never seeded: a blueprint's document is authored (captured from a
 * page, composed from scratch), never a copy of a template.
 *
 * The view mode is the blueprint's own render display's (`full`, the
 * module's install config), the one the canonical route renders and the
 * tempstore keys on; the subject's display is resolved in that same mode
 * through core's fallback, so the host's display rule and the storage's
 * default storage name one display.
 */
class BlueprintHost extends ContentHost {

  /**
   * The entity type id of a saved blueprint.
   */
  public const ENTITY_TYPE_ID = 'blueprint';

  /**
   * The section storage plugin a blueprint's document is stored by.
   */
  public const STORAGE_TYPE = 'blueprint';

  /**
   * The field carrying the blueprint's machine name.
   *
   * The name a slot's default and a linked placement name a blueprint by
   * (InnerRender::blueprintHost() loads by it).
   */
  public const MACHINE_NAME_FIELD = 'machine_name';

  /**
   * The field naming the subject's entity type.
   */
  public const SUBJECT_TYPE_FIELD = 'subject_type';

  /**
   * The field naming the subject's bundle.
   */
  public const SUBJECT_BUNDLE_FIELD = 'subject_bundle';

  /**
   * The field naming the blueprint's scope (block or page).
   */
  public const SCOPE_FIELD = 'scope';

  /**
   * The entity's field carrying the `entity_type:bundle` allow-list.
   */
  public const BUNDLES_FIELD = 'bundles';

  /**
   * The blueprint TYPE's key saying its blueprints are the frame's material.
   *
   * A property of the bundle config entity (the library's blueprint type),
   * not of the blueprint: a frame-only type's blueprints (the site chrome)
   * need the site frame as their host, a requirement kind the fit judges
   * (BlueprintRequirements::FRAME). Base owns the spelling as it owns the
   * entity's field names, so the spine reads it from the bundle entity
   * without knowing the library's class.
   */
  public const TYPE_FRAME_ONLY_KEY = 'frame_only';

  /**
   * The subject, memoized: one sample entity per host instance.
   */
  protected ?FieldableEntityInterface $subject = NULL;

  /**
   * Constructs a blueprint host.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The blueprint entity.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface $sectionStorageManager
   *   The section storage manager.
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface $layoutTempstoreRepository
   *   The layout tempstore repository.
   * @param \Drupal\layout_builder\Entity\SampleEntityGeneratorInterface $sampleEntityGenerator
   *   Core's sample entity generator: the subject is the sample of the
   *   subject bundle.
   * @param \Closure|null $instantiator
   *   The instantiator closure the content host takes; a blueprint never
   *   seeds, so it is carried for the signature only.
   * @param \Drupal\daedalus_blueprint\Host\HostResolver|null $resolver
   *   The resolver.
   */
  public function __construct(
    FieldableEntityInterface $entity,
    ModuleHandlerInterface $moduleHandler,
    SectionStorageManagerInterface $sectionStorageManager,
    LayoutTempstoreRepositoryInterface $layoutTempstoreRepository,
    protected SampleEntityGeneratorInterface $sampleEntityGenerator,
    ?\Closure $instantiator = NULL,
    ?HostResolver $resolver = NULL,
  ) {
    parent::__construct($entity, $moduleHandler, $sectionStorageManager, $layoutTempstoreRepository, $instantiator, $resolver);
  }

  /**
   * {@inheritdoc}
   *
   * The subject's render display: the display core renders the subject
   * bundle with in the host's view mode. The default sections, the
   * default-section setting and the placement restrictions a blueprint's
   * canvas obeys are the subject bundle's, because its material is placed
   * on pages of that bundle.
   */
  public function display(): EntityViewDisplayInterface {
    return EntityViewDisplay::collectRenderDisplay($this->subject(), $this->viewMode());
  }

  /**
   * {@inheritdoc}
   *
   * The blueprint's own render display's mode (`full`): the mode the
   * canonical route renders, the storage context carries and the tempstore
   * keys on. Not display()->getMode(): the subject's display may fall back
   * to `default` while the blueprint's own is `full`, and the key must be
   * the blueprint's.
   */
  public function viewMode(): string {
    return EntityViewDisplay::collectRenderDisplay($this->entity, 'full')->getMode();
  }

  /**
   * {@inheritdoc}
   */
  protected function storageType(): string {
    return self::STORAGE_TYPE;
  }

  /**
   * {@inheritdoc}
   *
   * A blueprint is never seeded: an empty blueprint is an empty canvas.
   */
  protected function seed(): void {
  }

  /**
   * {@inheritdoc}
   *
   * Core's sample entity of the subject bundle: what the document renders
   * against and what the instantiator resolves a field block's derivative
   * against, shown and never saved.
   */
  public function subject(): FieldableEntityInterface {
    if ($this->subject === NULL) {
      $this->subject = $this->sampleEntityGenerator->get(
        (string) $this->entity->get(self::SUBJECT_TYPE_FIELD)->value,
        (string) $this->entity->get(self::SUBJECT_BUNDLE_FIELD)->value,
      );
    }
    return $this->subject;
  }

  /**
   * The blueprint's machine name: what a door's default names it by.
   *
   * Read here so the render (InnerRender::routeSlot(): which frame door
   * defaults to the blueprint a route edits) names it without the
   * library's classes.
   */
  public function machineName(): string {
    return (string) $this->entity->get(self::MACHINE_NAME_FIELD)->value;
  }

  /**
   * The blueprint's scope: what the document stands in for.
   *
   * `block` (one root container, placed into a page) or `page` (a whole
   * page document); the entity's own vocabulary, read here so the spine
   * and the AI surfaces name it without the library's classes.
   */
  public function scope(): string {
    return (string) $this->entity->get(self::SCOPE_FIELD)->value;
  }

  /**
   * The blueprint's `entity_type:bundle` allow-list; [] for any host.
   *
   * The author's statement of where the blueprint may be placed, read
   * here so the fit (BlueprintRequirements::BUNDLES) is judged from the
   * entity without the library's classes.
   *
   * @return string[]
   *   The allow-list.
   */
  public function bundles(): array {
    return array_values(array_map(static fn (array $item): string => (string) $item['value'], $this->entity->get(self::BUNDLES_FIELD)->getValue()));
  }

  /**
   * Whether the blueprint's type keeps it on the site frame.
   *
   * The blueprint's bundle entity (the library's blueprint type, config)
   * carries the key (TYPE_FRAME_ONLY_KEY); read here so the fit
   * (BlueprintRequirements::FRAME) is judged from the entity without the
   * library's classes. A blueprint whose type is gone, or whose type was
   * written before the key existed, reads FALSE.
   */
  public function frameOnly(): bool {
    $type = $this->entity->get($this->entity->getEntityType()->getKey('bundle'))->entity;
    return $type instanceof ConfigEntityInterface && (bool) $type->get(self::TYPE_FRAME_ONLY_KEY);
  }

}
