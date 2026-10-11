<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostPersistence;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Entity\SampleEntityGeneratorInterface;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * A Layout Builder enabled entity view display as the host of its template.
 *
 * The display's default layout is a live template (every entity of the
 * bundle without an override renders it) and the seed of a page's first
 * override. As a host it answers: identity is the display's own (entity
 * type entity_view_display, its config id and uuid); sections are its
 * defaults storage; styles live in its daedalus_stylish.styles third-party
 * setting (config, with schema the styling module declares;
 * ConfigStyleMapTrait, shared with the frame host); it has no fields of its
 * own; its subject is core's sample entity for the target bundle, shown and
 * never edited; and it persists as a config save, with no revisions, no
 * workspaces and its inline blocks stored as values.
 */
class DisplayHost implements HostInterface {

  use ConfigStyleMapTrait;

  public function __construct(
    protected LayoutEntityDisplayInterface $display,
    protected ModuleHandlerInterface $moduleHandler,
    protected SectionStorageManagerInterface $sectionStorageManager,
    protected LayoutTempstoreRepositoryInterface $layoutTempstoreRepository,
    protected SampleEntityGeneratorInterface $sampleEntityGenerator,
    protected FieldTypePluginManagerInterface $fieldTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function entity(): EntityInterface {
    return $this->display;
  }

  /**
   * {@inheritdoc}
   */
  public function entityTypeId(): string {
    return $this->display->getEntityTypeId();
  }

  /**
   * {@inheritdoc}
   */
  public function id(): int|string|null {
    return $this->display->id();
  }

  /**
   * {@inheritdoc}
   */
  public function uuid(): string {
    return (string) $this->display->uuid();
  }

  /**
   * {@inheritdoc}
   *
   * A config entity's bundle is its entity type; the document's kind (the
   * target entity type, bundle and view mode) is what display() answers.
   */
  public function bundle(): string {
    return $this->display->bundle();
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) ($this->display->label() ?? $this->display->id());
  }

  /**
   * {@inheritdoc}
   *
   * The display IS the display: the one resolution rule answers itself
   * (getTargetEntityTypeId(), getTargetBundle() and getMode() are the
   * document's kind).
   */
  public function display(): EntityViewDisplayInterface {
    return $this->display;
  }

  /**
   * {@inheritdoc}
   */
  public function viewMode(): string {
    return $this->display->getMode();
  }

  /**
   * {@inheritdoc}
   */
  public function hasDocument(): bool {
    return $this->display->isLayoutBuilderEnabled();
  }

  /**
   * {@inheritdoc}
   *
   * The defaults storage over this display object, with the tempstore copy
   * preferred only when it stages this same object, wrapped nested-aware
   * when daedalus_layout is installed: the same rules as the content host's
   * overrides storage, for the same reasons.
   */
  public function sectionStorage(): ?SectionStorageInterface {
    if (!$this->hasDocument()) {
      return NULL;
    }
    $storage = $this->sectionStorageManager->load('defaults', [
      'display' => EntityContext::fromEntity($this->display),
      'view_mode' => new Context(new ContextDefinition('string'), $this->viewMode()),
    ]);
    if ($storage === NULL) {
      return NULL;
    }
    if ($this->layoutTempstoreRepository->has($storage)) {
      $staged = $this->layoutTempstoreRepository->get($storage);
      if ($staged->getContext('display')->getContextValue() === $this->display) {
        $storage = $staged;
      }
    }
    if ($this->moduleHandler->moduleExists('daedalus_layout')) {
      return NestedAwareSectionStorage::wrap($storage);
    }
    return $storage;
  }

  /**
   * {@inheritdoc}
   */
  public function ownSections(): array {
    $storage = $this->sectionStorage();
    if ($storage === NULL) {
      return [];
    }
    $sections = $storage->getSections();
    return DaedalusLayoutAdapter::isBlankSectionList($sections) ? [] : $sections;
  }

  /**
   * {@inheritdoc}
   *
   * A template has nothing to fall back to: its own sections are the
   * document.
   */
  public function sections(): array {
    return $this->ownSections();
  }

  /**
   * {@inheritdoc}
   *
   * A template is never seeded: nothing to decline.
   */
  public function declineSeed(): void {
  }

  /**
   * {@inheritdoc}
   */
  public function fields(): ?FieldableEntityInterface {
    return NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Core's sample entity for the target bundle (what the defaults storage
   * renders in preview): shown, never edited, never saved.
   */
  public function subject(): FieldableEntityInterface {
    return $this->sampleEntityGenerator->get($this->display->getTargetEntityTypeId(), $this->display->getTargetBundle());
  }

  /**
   * {@inheritdoc}
   */
  public function persistence(): HostPersistence {
    return HostPersistence::config();
  }

}
