<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostPersistence;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Drupal\layout_builder\SectionStorage\SectionStorageManagerInterface;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * A theme's site frame as the host of its document.
 *
 * The outermost layer of the document stack: the frame is rendered around
 * every route of its theme, linked for everything beneath it, and leaves
 * slots the route's output and the inner documents fill. As a host it
 * answers: identity is the frame entity's (type daedalus_frame, id the
 * theme); sections are the `frame` section storage over the entity's own
 * list; styles live in its daedalus_stylish third-party setting; it has no
 * fields of its own; it has NO display and NO subject (it belongs to no
 * entity view display and shows no entity: a view page or a form has none,
 * and the page variant hands the route's entity to its slots at render);
 * and it persists as a config save, with no revisions, no workspaces and
 * its inline blocks stored as values, the display host's persistence.
 *
 * Never seeded: a frame's document is authored (shipped by the theme's
 * recipe ingredient, edited on the frame canvas), and an empty frame
 * renders nothing around the route.
 */
class FrameHost implements HostInterface {

  use ConfigStyleMapTrait;

  /**
   * The section storage plugin a frame's document is stored by.
   */
  public const STORAGE_TYPE = 'frame';

  /**
   * The one view mode a frame renders in.
   *
   * A frame has no display to resolve a mode from; the storage's contexts
   * and the canvas carry this value where a view mode is required.
   */
  public const VIEW_MODE = 'default';

  /**
   * The id of the frame's main slot: where the route's output lands.
   *
   * One id of the site's slot roster is the frame's main content:
   * `daedalus_slot:main` placed in the frame takes what the route
   * produced (InnerRender::renderFrame(), RouteOutput) instead of asking
   * which document fills it. A page's own section tagged `main` is not a
   * fill of this slot: the page IS the route's output.
   */
  public const MAIN_SLOT = 'main';

  /**
   * The render context naming the frame whose document is being rendered.
   *
   * Set by the frame render alone (InnerRender::renderFrame()), an entity
   * context of the frame: the slot block reads it as its outer host, and
   * every consumer of the render can tell a frame render from a page's.
   */
  public const CONTEXT_ID = 'daedalus_blueprint.frame';

  public function __construct(
    protected FrameInterface $frame,
    protected ModuleHandlerInterface $moduleHandler,
    protected SectionStorageManagerInterface $sectionStorageManager,
    protected LayoutTempstoreRepositoryInterface $layoutTempstoreRepository,
    protected FieldTypePluginManagerInterface $fieldTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function entity(): EntityInterface {
    return $this->frame;
  }

  /**
   * {@inheritdoc}
   */
  public function entityTypeId(): string {
    return $this->frame->getEntityTypeId();
  }

  /**
   * {@inheritdoc}
   */
  public function id(): int|string|null {
    return $this->frame->id();
  }

  /**
   * {@inheritdoc}
   */
  public function uuid(): string {
    return (string) $this->frame->uuid();
  }

  /**
   * {@inheritdoc}
   *
   * A config entity's bundle is its entity type; the frame's kind is its
   * theme, the id.
   */
  public function bundle(): string {
    return $this->frame->bundle();
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->frame->label();
  }

  /**
   * {@inheritdoc}
   *
   * None: the frame belongs to no entity view display. It is rendered by
   * the page display variant, not by core's entity render path, so no
   * display's defaults, default-section setting or placement restrictions
   * govern it.
   */
  public function display(): ?EntityViewDisplayInterface {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function viewMode(): string {
    return self::VIEW_MODE;
  }

  /**
   * {@inheritdoc}
   *
   * Always: a frame IS a document, empty or not.
   */
  public function hasDocument(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * The frame storage over this frame object, with the tempstore copy
   * preferred only when it stages this same object, wrapped nested-aware
   * when daedalus_layout is installed: the same rules as the display
   * host's defaults storage, for the same reasons.
   */
  public function sectionStorage(): ?SectionStorageInterface {
    $storage = $this->sectionStorageManager->load(self::STORAGE_TYPE, [
      'entity' => EntityContext::fromEntity($this->frame),
    ]);
    if ($storage === NULL) {
      return NULL;
    }
    if ($this->layoutTempstoreRepository->has($storage)) {
      $staged = $this->layoutTempstoreRepository->get($storage);
      if ($staged->getContext('entity')->getContextValue() === $this->frame) {
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
   * The outermost layer has nothing around it: its own sections are the
   * document.
   */
  public function sections(): array {
    return $this->ownSections();
  }

  /**
   * {@inheritdoc}
   *
   * A frame is never seeded: nothing to decline.
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
   * None: the frame shows no entity of its own. The route's entity, when
   * the route has one, reaches the frame's slots as the outer render's
   * context, never as the frame's subject.
   */
  public function subject(): ?FieldableEntityInterface {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function persistence(): HostPersistence {
    return HostPersistence::config();
  }

}
