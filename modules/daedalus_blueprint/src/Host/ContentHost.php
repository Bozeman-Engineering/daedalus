<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Host;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;

/**
 * A fieldable content entity as the host of its own document.
 *
 * The one storage mapping of the content host: the document's sections
 * live in core's Layout Builder override field and its styles in the
 * field of the style map type (daedalus_stylish's, referenced by type so
 * the spine stays styling-module-agnostic). Nothing else in the spine
 * names either field: readers and writers go through sectionStorage(),
 * styleMap() and setStyleMap().
 *
 * With no override storage on the entity the host is a plain host: no
 * document, its own fields and (when the bundle has one) its style map.
 * A host can own a document and still be rendered inside a foreign one:
 * a page of a locked bundle (its display Layout Builder enabled, not
 * overridable) carries the override field and owns what it holds, while
 * the template around it belongs to the display's host
 * (HostResolver::foreignOwner()); such a page is never seeded.
 *
 * A page's own document is seeded at its doors: the first time EITHER the
 * override storage or the style map is read while the override is empty
 * (or holds core's blank placeholder) and the display carries a template,
 * the template's document is instantiated into the override with fresh
 * identity (uuids derived from this entity's uuid, styles re-keyed into
 * this entity's map, value blocks as unsaved blocks), in memory. The seed
 * writes both halves of the document at once, so a reader that takes the
 * map first answers the whole seed exactly as one that takes the sections
 * first: every read and write goes through these doors, so a reader, the
 * canvas and an operation see one seeded document, and the same page
 * reads the same identity on every request until it stages. Nothing is
 * persisted here: staging or saving is the caller's. A reader that must
 * not author (delivery, a presave sweep, a usage tracker) reads a
 * disposable clone or declines the seed (declineSeed()).
 */
class ContentHost implements HostInterface {

  /**
   * Whether the seed is running: its own operations read the raw storage.
   */
  protected bool $seeding = FALSE;

  /**
   * Whether a creator declined the seed: the document is authored.
   */
  protected bool $seedDeclined = FALSE;

  /**
   * Core's Layout Builder override field (OverridesSectionStorage::FIELD_NAME).
   *
   * Spelled here rather than through the constant so a host resolves on a
   * site without layout_builder: the class is unloadable there, and a
   * plain host still answers for fields and styles.
   */
  public const LAYOUT_FIELD = 'layout_builder__layout';

  /**
   * The field type carrying a host's style map.
   *
   * daedalus_stylish's type id, referenced as a string so the spine stays
   * styling-module-agnostic (the entity_reference_revisions precedent):
   * with no styling module installed no field of this type exists and the
   * host simply has no style store.
   */
  public const STYLE_MAP_FIELD_TYPE = 'daedalus_stylish_style_map';

  /**
   * Constructs a content host.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface|null $sectionStorageManager
   *   The section storage manager, or NULL without layout_builder.
   * @param \Drupal\layout_builder\LayoutTempstoreRepositoryInterface|null $layoutTempstoreRepository
   *   The layout tempstore repository, or NULL without layout_builder.
   * @param \Closure|null $instantiator
   *   Service closure resolving the document instantiator that seeds the
   *   first override from the display's template, or NULL when no seed
   *   can run (a host built outside the resolver).
   * @param \Drupal\daedalus_blueprint\Host\HostResolver|null $resolver
   *   The resolver, through which a plain host reads the document its
   *   display renders for it (the foreign document), or NULL when a host
   *   is built outside the resolver (it then reads no foreign document).
   */
  public function __construct(
    protected FieldableEntityInterface $entity,
    protected ModuleHandlerInterface $moduleHandler,
    protected ?SectionStorageManagerInterface $sectionStorageManager = NULL,
    protected ?LayoutTempstoreRepositoryInterface $layoutTempstoreRepository = NULL,
    protected ?\Closure $instantiator = NULL,
    protected ?HostResolver $resolver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function entity(): EntityInterface {
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function entityTypeId(): string {
    return $this->entity->getEntityTypeId();
  }

  /**
   * {@inheritdoc}
   */
  public function id(): int|string|null {
    return $this->entity->id();
  }

  /**
   * {@inheritdoc}
   */
  public function uuid(): string {
    return (string) $this->entity->uuid();
  }

  /**
   * {@inheritdoc}
   */
  public function bundle(): string {
    return $this->entity->bundle();
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->entity->label();
  }

  /**
   * {@inheritdoc}
   */
  public function display(): EntityViewDisplayInterface {
    return EntityViewDisplay::collectRenderDisplay($this->entity, 'full');
  }

  /**
   * {@inheritdoc}
   */
  public function viewMode(): string {
    return $this->display()->getMode();
  }

  /**
   * {@inheritdoc}
   */
  public function hasDocument(): bool {
    return $this->sectionStorageManager !== NULL
      && $this->layoutTempstoreRepository !== NULL
      && $this->entity->hasField(self::LAYOUT_FIELD);
  }

  /**
   * {@inheritdoc}
   */
  public function sectionStorage(): ?SectionStorageInterface {
    if (!$this->hasDocument()) {
      return NULL;
    }

    $contexts = [
      'entity' => EntityContext::fromEntity($this->entity),
      'view_mode' => new Context(new ContextDefinition('string'), $this->viewMode()),
    ];
    $storage = $this->sectionStorageManager->load($this->storageType(), $contexts);
    if ($storage === NULL) {
      return NULL;
    }

    // Prefer the tempstore storage (in-progress edits) only when it stages
    // THIS entity instance: then its request-shared wrapper (TreeIndex and
    // embedded-list registry included) is the one everything else in the
    // request mutates. The staged storage carries its own entity object,
    // so for any other instance it is a parallel world: locates through it
    // cannot see what operations just did to the passed entity (a nested
    // section minted mid-batch resolved nowhere: ANCHOR_NOT_FOUND on
    // identity that plainly existed).
    if ($this->layoutTempstoreRepository->has($storage)) {
      $staged = $this->layoutTempstoreRepository->get($storage);
      if ($staged->getContext('entity')->getContextValue() === $this->entity) {
        $storage = $staged;
      }
    }

    $this->seed();

    if ($this->moduleHandler->moduleExists('daedalus_layout')) {
      return NestedAwareSectionStorage::wrap($storage);
    }
    return $storage;
  }

  /**
   * The section storage plugin the host's own document is stored by.
   *
   * Core's overrides storage over the entity's override field; a blueprint
   * host (BlueprintHost) answers its own plugin, which stores the same
   * field under the same key shape and differs only in what its sections
   * render and are offered against (the subject, not the entity).
   */
  protected function storageType(): string {
    return 'overrides';
  }

  /**
   * The seed, run once from whichever document door is read first.
   *
   * A truly empty override (the field holds no section at all: the page
   * was never started) takes the display's template as its own document,
   * once: sectionStorage() and styleMap() both call here before they
   * answer, so the first read of either door seeds both halves (the seed
   * writes the sections and the re-keyed styles in one batch) and a reader
   * taking the map first never sees half a seed. Core's blank section
   * (one layout_builder_blank section, SectionListTrait::hasBlankSection())
   * is NOT emptiness: core writes it when a layout "has purposefully had
   * all sections removed", so a page holding it was started and is empty,
   * and it is left alone. It is the persisted fact behind a blank start
   * (the start writes it through removeAllSections(TRUE)) and behind a
   * page an editor emptied by hand (core's removeSection() writes it when
   * the last root section goes), and such a page stays empty, renders its
   * own document (core's overrides storage counts the marker as a
   * section: OverridesSectionStorage::isOverridden()) and never follows
   * its template again. The override field is the one emptiness fact: the
   * overrides storage and a staged copy of THIS entity instance read the
   * same field, so the check needs no storage. The seed's own operations
   * come back through the doors and read the raw storage and map they
   * append to. Section identity is daedalus_layout's, and a template's
   * elements place by anchoring it, so without daedalus_layout there is
   * no seed: nothing on a template could be addressed anyway. A creator
   * authoring the whole document declined the seed ahead of its first
   * read: its document lands on the empty override, and once one section
   * stands no door seeds.
   *
   * The same door is where a lock and an unlock meet the page, each a
   * merge run per page on its next read, never at the display save. A
   * page whose own document holds only slot sections (the fills of a
   * locked page: LayoutSerializer::allSlotSections(), the empty field
   * included) and whose bundle is overridable was unlocked: the seed lays
   * the template around its fills, each dropped into its slot's place
   * (DocumentInstantiator::seed()). A page rendered inside a foreign
   * document (a page of a locked bundle) never takes the template as its
   * own, the template IS its render, and its own document is only what it
   * owns inside it: whatever else the field holds (the whole document it
   * owned before the lock, or core's blank marker) is dropped
   * (DocumentInstantiator::narrowToSlots()). Core's marker on an
   * overridable page is neither shape and is left alone.
   */
  protected function seed(): void {
    if ($this->seeding || $this->seedDeclined || $this->instantiator === NULL
      || !$this->hasDocument() || !$this->moduleHandler->moduleExists('daedalus_layout')) {
      return;
    }
    $owner = $this->resolver?->foreignOwner($this);
    $fills_only = LayoutSerializer::allSlotSections($this->entity->get(self::LAYOUT_FIELD)->getSections());
    if ($owner === NULL ? !$fills_only : $fills_only) {
      return;
    }
    $this->seeding = TRUE;
    try {
      $instantiator = ($this->instantiator)();
      if ($owner === NULL) {
        $instantiator->seed($this);
      }
      else {
        $instantiator->narrowToSlots($this);
      }
    }
    finally {
      $this->seeding = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * On a host rendered inside a foreign document the own sections are the
   * slot sections alone, whatever the field holds: the door narrows the
   * field to them on the first read after a lock (seed()), and a reader
   * that declined the seed, or reads while it runs, answers the same law
   * without writing.
   */
  public function ownSections(): array {
    $storage = $this->sectionStorage();
    if ($storage === NULL) {
      return [];
    }
    $sections = $storage->getSections();
    if (DaedalusLayoutAdapter::isBlankSectionList($sections)) {
      return [];
    }
    if ($this->resolver?->foreignOwner($this) !== NULL) {
      return array_values(array_filter($sections, static fn (Section $section): bool => LayoutSerializer::slotOf($section) !== NULL));
    }
    return $sections;
  }

  /**
   * {@inheritdoc}
   *
   * A content host that owns the whole document it renders reads its
   * own: its first read seeds it from the display's template when it is
   * empty, so there is nothing to fall back to. A host rendered inside a
   * foreign document (HostResolver::foreignOwner() names the owner: a
   * page of a locked bundle) reads what its render shows, the display's
   * document, every element of which the owner edits and this host only
   * reads, followed by its own sections (the slots it owns inside that
   * document, when it carries the override field), one list: the owner's
   * first, so the template's nodes keep their order and the host's own
   * stand after them as top-level containers.
   */
  public function sections(): array {
    $owner = $this->resolver?->foreignOwner($this);
    if ($owner === NULL) {
      return $this->ownSections();
    }
    return array_merge($owner->ownSections(), $this->ownSections());
  }

  /**
   * {@inheritdoc}
   */
  public function declineSeed(): void {
    $this->seedDeclined = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function hasStyleStore(): bool {
    return $this->styleMapDefinition() !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function styleMapDefinition(): ?FieldDefinitionInterface {
    foreach ($this->entity->getFieldDefinitions() as $definition) {
      if ($definition->getType() === self::STYLE_MAP_FIELD_TYPE) {
        return $definition;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function styleMap(): array {
    $this->seed();
    $definition = $this->styleMapDefinition();
    if ($definition === NULL) {
      return [];
    }
    $value = $this->entity->get($definition->getName())->value;
    if (!is_string($value) || $value === '') {
      return [];
    }
    $map = json_decode($value, TRUE);
    return is_array($map) ? $map : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setStyleMap(array $map): void {
    $definition = $this->styleMapDefinition();
    if ($definition === NULL) {
      throw new \LogicException(sprintf('%s %s has no style store.', $this->entityTypeId(), $this->id() ?? 'new'));
    }
    // Canonical storage: JSON text, and an empty map is no value at all.
    $this->entity->set(
      $definition->getName(),
      $map === [] ? NULL : json_encode($map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function fields(): ?FieldableEntityInterface {
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function subject(): FieldableEntityInterface {
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  public function persistence(): HostPersistence {
    return HostPersistence::content();
  }

}
