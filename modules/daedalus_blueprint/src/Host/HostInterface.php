<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Host;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * The host of a page document.
 *
 * A page document is a section tree plus its style map: one unit, because
 * style keys address elements of that tree and mean nothing without it. A
 * host owns one document and answers six questions: identity (it is always
 * an entity, so every address shape fits), sections (a core section
 * storage, always: nothing above the host reads a field), styles (the
 * document's style map, wherever the host keeps it), fields (the host's
 * own editable fields, or none), subject (the entity a render shows) and
 * persistence (how a commit lands).
 *
 * A content host (ContentHost) serves every fieldable entity, with or
 * without a document (a bundle with no layout storage is a content host
 * whose hasDocument() is FALSE). A saved blueprint is a content host whose
 * document is material for hosts of another kind (BlueprintHost): its
 * subject is core's sample entity of the bundle it is for, its display that
 * subject's, and it is never seeded. A Layout Builder enabled entity view
 * display is the template host (LayoutBuilder\DisplayHost): its defaults
 * storage is the document, its subject is core's sample entity, it has no
 * fields, its styles are a third-party setting and its commit is a config
 * save. A theme's site frame is the frame host (LayoutBuilder\FrameHost),
 * the display host's sibling: a config entity that is its own section
 * list, rendered around every route of the theme by the page display
 * variant, so it belongs to no display and shows no subject (the two
 * answers a host may give as NULL). The spine reads and writes the
 * document through the host at every depth; public signatures take the
 * host, resolved at the doors through HostResolver.
 */
interface HostInterface {

  /**
   * The host entity.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity whose identity addresses the document.
   */
  public function entity(): EntityInterface;

  /**
   * The host entity type id.
   */
  public function entityTypeId(): string;

  /**
   * The host entity id, or NULL for an unsaved host.
   */
  public function id(): int|string|null;

  /**
   * The host uuid.
   */
  public function uuid(): string;

  /**
   * The host bundle.
   */
  public function bundle(): string;

  /**
   * The host label.
   */
  public function label(): string;

  /**
   * The render display the document belongs to.
   *
   * The ONE display resolution rule of the spine: the display core would
   * render the subject with in the 'full' view mode
   * (EntityViewDisplay::collectRenderDisplay(), which falls back to the
   * default display when 'full' is not customized). Every reader that
   * needs the bundle's display facts (default sections, the default-section
   * setting, the view mode a tempstore key carries) reads it here.
   *
   * A frame host has none: its document is rendered by the page display
   * variant, not through an entity view display, so there is no bundle, no
   * template to seed from and no display-level restriction over it.
   *
   * @return \Drupal\Core\Entity\Display\EntityViewDisplayInterface|null
   *   The display, or NULL for a host whose document belongs to no display.
   */
  public function display(): ?EntityViewDisplayInterface;

  /**
   * The resolved view mode, what display()->getMode() answers.
   *
   * The same rule daedalus_tempstore keys tempstore on, so the storage a
   * host loads and the storage the editor stages are one key.
   */
  public function viewMode(): string;

  /**
   * Whether the host can hold a document of its own.
   *
   * A content host answers TRUE when its entity carries Layout Builder
   * override storage; a plain fieldable entity answers FALSE and still
   * answers for its fields and styles.
   */
  public function hasDocument(): bool;

  /**
   * The host's OWN section storage.
   *
   * For a content host: the overrides storage over this entity object,
   * with the tempstore copy preferred only when it stages this same object
   * (a staged storage carries its own entity, so for any other instance it
   * is a parallel world), wrapped nested-aware when daedalus_layout is
   * installed. A FRESH wrapper per call, never memoized: separate wrappers
   * do not share nested Section instances, so one operation resolves its
   * storage once and passes it along to every locate it makes.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface|null
   *   The storage, or NULL when the host holds no document.
   */
  public function sectionStorage(): ?SectionStorageInterface;

  /**
   * The sections the host's own storage holds.
   *
   * Core's blank section list (one layout_builder_blank section, core's
   * marker of a layout that was started and purposefully emptied) reads as
   * empty: no client can name, anchor or style it, so no reader sees it.
   * On a page the marker means the page owns an empty document (a blank
   * start, or an editor removing the last section); it is never "never
   * instantiated", which is the truly empty field alone.
   *
   * A host rendered inside a foreign document (a page of a locked bundle)
   * owns only its slot sections, the fills of that document: the door
   * narrows its field to them on the first read after a lock, and the
   * first read after an unlock lays the template around them (the lock
   * and unlock merge, DocumentInstantiator::narrowToSlots() and seed()).
   *
   * @return \Drupal\layout_builder\Section[]
   *   The own sections, or an empty array.
   */
  public function ownSections(): array;

  /**
   * The sections a reader sees.
   *
   * A host that owns the whole document it renders reads its own: a
   * content host whose override was empty had it seeded from the
   * display's template on the first read of either document door, this
   * or styleMap() (fresh identity derived from the host's uuid, styles
   * re-keyed into its map, in memory), and a display host's document is
   * its defaults. A host rendered inside a foreign document (a page of a
   * locked bundle: HostResolver::foreignOwner() names the host that edits
   * it) reads what its render shows, the display's document, which this
   * host only reads (an operation naming one of its elements answers
   * FOREIGN_DOCUMENT), followed by its own sections, the ones it owns
   * inside that document. A blank placeholder list is no document.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The sections as delivered.
   */
  public function sections(): array;

  /**
   * Declines the template seed: this host instance does not seed on read.
   *
   * A page's starting layout has exactly one source. A content host whose
   * creator writes the whole document (a blueprint carrying a layout, a
   * page_create document) calls this before the first read of either
   * door, so the empty override is not seeded from the display's template
   * under the authored document. A reader of the store as it STANDS
   * declines too: a presave sweep or a usage tracker reads what is saved,
   * and a seed run there would be persisted by the save in progress or
   * counted as the page's own authorship. A host created without a layout
   * (the node form) is seeded as before, and delivery, which needs the
   * seeded keys, reads a disposable clone instead of declining
   * (daedalus_stylish's RenderStyles). A display host has no seed to
   * decline.
   */
  public function declineSeed(): void;

  /**
   * Whether the host keeps a style map.
   */
  public function hasStyleStore(): bool;

  /**
   * The grammar the host's style map is validated against.
   *
   * The style map's one validation seam is its field handler (the module
   * that declares the style map field type registers it); the handler
   * dispatches on this definition. A host with no style store answers
   * NULL.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface|null
   *   The definition, or NULL.
   */
  public function styleMapDefinition(): ?FieldDefinitionInterface;

  /**
   * The document's flat style map: selectable keys to entries.
   *
   * A document door like sectionStorage(): a content host whose override
   * is empty seeds it from the display's template on the first read of
   * either, so the map answered here carries the seed's re-keyed styles
   * whichever door a reader takes first.
   *
   * @return array
   *   The decoded map; empty without a style store or with no styles.
   */
  public function styleMap(): array;

  /**
   * Replaces the document's style map.
   *
   * An empty map empties the store: "no styles" and "no value" are one
   * state. A host without a style store ignores nothing: it throws, since
   * writing styles nowhere is a caller's mistake.
   *
   * @param array $map
   *   The validated map.
   */
  public function setStyleMap(array $map): void;

  /**
   * The entity whose fields are the host's own editable fields.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The fields entity, or NULL for a host with no fields of its own.
   */
  public function fields(): ?FieldableEntityInterface;

  /**
   * The entity a render of the document shows.
   *
   * A content host is its own subject. A template host's subject is a
   * preview entity, shown and never edited; so is a blueprint host's (the
   * sample entity of the bundle the blueprint is for). The render subject
   * at every depth: the canvas renders the sections against it, the Add
   * panel offers its fields, and a field node resolves its derivative
   * against its entity type and bundle.
   *
   * A frame host has none: it renders around every route, with or without
   * an entity, so no field of any kind is placeable in its document and no
   * fit is judged against a subject (the route's entity reaches the
   * frame's slots as the outer render's context, not as the frame's own).
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The subject, or NULL for a host that shows no entity.
   */
  public function subject(): ?FieldableEntityInterface;

  /**
   * How a commit of the host lands.
   */
  public function persistence(): HostPersistence;

}
