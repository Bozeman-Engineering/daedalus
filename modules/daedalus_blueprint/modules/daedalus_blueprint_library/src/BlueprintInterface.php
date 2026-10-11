<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemMemberInterface;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\PageStart;
use Drupal\media\MediaInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A saved blueprint: reusable page material, saved as content, that is a page.
 *
 * A blueprint is a content host like a page (BlueprintHost): its document
 * is core's Layout Builder override field and the style map field on this
 * entity, edited on its own canvas (the canonical route) and read by the
 * one tree builder. What it adds is what the material is FOR: a scope
 * (a container placed on a page, or a whole page), a subject (the entity
 * type and bundle it is material for, which its canvas renders against)
 * and an optional allow-list of bundles it may be placed on. Field blocks
 * in its document name the field, so it fits any host whose subject has
 * the fields it asks for.
 *
 * Content, not config (the daedalus_variant precedent): revisionable and
 * publishable, which is exactly what makes it workspace-aware, and every
 * commit is a new revision (a type-less entity is its own revision
 * policy), so a bad update is one revision revert away.
 *
 * A blueprint is a member of a design system, or global
 * (DesignSystemMemberInterface): compositions belong to the design system
 * whose vocabulary they wear, so the roster offers a member only while its
 * system is active, and the variants a blueprint owns carry its membership.
 *
 * Its bundle is a blueprint TYPE (BlueprintTypeInterface): the axis every
 * permission over it runs on, and the roster's grouping of blueprints.
 */
interface BlueprintInterface extends ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, RevisionLogInterface, EntityOwnerInterface, DesignSystemMemberInterface {

  /**
   * The entity type id (the spine knows it too: BlueprintHost::ENTITY_TYPE_ID).
   */
  public const ENTITY_TYPE_ID = BlueprintHost::ENTITY_TYPE_ID;

  /**
   * The ownership field this module provides on daedalus_variant.
   *
   * An entity reference to the owning CONTENT blueprint
   * (BlueprintVariantFieldHooks); the file lane is FILE_OWNER_FIELD.
   */
  public const OWNER_FIELD = 'blueprint';

  /**
   * The file-lane ownership field: a module-shipped blueprint's machine name.
   */
  public const FILE_OWNER_FIELD = 'blueprint_file';

  /**
   * The type the library ships for the compositions editors save and place.
   *
   * The default a capture mints when the gesture names no type.
   */
  public const TYPE_SECTION = 'section';

  /**
   * The type the library ships for headers and footers: the site frame's.
   */
  public const TYPE_SITE_CHROME = 'site_chrome';

  /**
   * The block scope: a container placed into a page.
   */
  public const SCOPE_BLOCK = 'block';

  /**
   * The page scope: a whole page's document.
   */
  public const SCOPE_PAGE = 'page';

  /**
   * The scopes a blueprint can have.
   */
  public const SCOPES = [self::SCOPE_BLOCK, self::SCOPE_PAGE];

  /**
   * The shape of a group id: a machine name.
   *
   * A blueprint carries zero or more group ids (getGroups()); the ids
   * are the keys of the site's group vocabulary, the dialect catalog's
   * groups (DialectCatalog::groups(): declared under the reserved `site`
   * key of the `PROVIDER.page_builder*.yml` family as `groups`, parsed
   * and contributed by daedalus_layout's SiteGroups). A group is a
   * filter category and nothing else: no operation, fit or placement rule
   * reads it, so the library checks the shape alone, as it does the
   * allow-list's entries, and a surface ignores an id the site does not
   * declare (a shipped blueprint from a package whose groups this site
   * lacks).
   */
  public const GROUP_ID_PATTERN = DialectCatalog::GROUP_ID_PATTERN;

  /**
   * The ids no blueprint can have: the two starts that are not blueprints.
   *
   * A page's first document has one source, picked once: a page blueprint,
   * the bundle's template, or nothing (blank). The chooser in front of an
   * add form and page_create's `start` name all three in one slot, a
   * blueprint id beside the words `template` and `blank`, so those words
   * are reserved in the flat site-wide vocabulary: a content blueprint
   * cannot be saved under either (the presave check, and the generator
   * steps past them), and a shipped file named for one is refused at
   * discovery (INVALID_BLUEPRINT). The words are the spine's (PageStart,
   * the verb that applies them); this is the one spelling.
   */
  public const RESERVED_IDS = PageStart::RESERVED;

  /**
   * The blueprint type: the bundle, the axis its permissions run on.
   *
   * Identity like the machine name: a blueprint never changes type.
   */
  public function getType(): string;

  /**
   * Returns the stable blueprint ID.
   *
   * The machine name IS the blueprint's ID in discovery: one flat,
   * site-wide namespace shared with file-shipped blueprint IDs, where any
   * collision is a loud error, never a merge. Content ids are
   * environment-local and uuids are opaque, so references use this name.
   */
  public function getMachineName(): string;

  /**
   * Returns the palette description.
   */
  public function getDescription(): string;

  /**
   * Sets the palette description.
   */
  public function setDescription(string $description): static;

  /**
   * Whether the blueprint is offered to the AI.
   *
   * TRUE (the default every material has) shares it: the roster's offered
   * view carries it, so the brief and the schema advertise it. FALSE keeps
   * it for the palette only; it still places and a placement still
   * validates, since legality never reads the flag.
   */
  public function isOffered(): bool;

  /**
   * Sets whether the blueprint is offered to the AI.
   */
  public function setOffered(bool $offered): static;

  /**
   * Whether the blueprint is a site favorite.
   *
   * The default an offering surface lists under Favorites for everyone
   * (the Add panel; the chooser lists favorites first), over which each
   * editor keeps their own marks. FALSE by default. A listing fact and
   * nothing else: no operation, fit or placement rule reads it.
   */
  public function isFavorite(): bool;

  /**
   * Sets whether the blueprint is a site favorite.
   */
  public function setFavorite(bool $favorite): static;

  /**
   * Who minted the blueprint: MaterialRecord::ORIGIN_USER or ORIGIN_AI.
   */
  public function getOrigin(): string;

  /**
   * Stamps who minted the blueprint (the lane's fact, set once at save).
   */
  public function setOrigin(string $origin): static;

  /**
   * Returns the palette icon media, when one is set.
   *
   * The catalog serves it as `media:<uuid>` in the icon value grammar
   * (daedalus_blueprint IconResolver); NULL is the legitimate unset state,
   * for which consumers render their default blueprint icon.
   */
  public function getIcon(): ?MediaInterface;

  /**
   * Sets or clears the palette icon media.
   */
  public function setIcon(?MediaInterface $media): static;

  /**
   * The scope: SCOPE_BLOCK (a container placed on a page) or SCOPE_PAGE.
   *
   * Identity, never revisioned: what the material stands in for does not
   * change with its content.
   */
  public function getScope(): string;

  /**
   * The entity type the blueprint is material for.
   *
   * With getSubjectBundle(), the kind of host its canvas renders against
   * (core's sample entity of the bundle) and a field block's derivative
   * resolves against. Stamped at capture from the source host's display
   * target; editable in the details.
   */
  public function getSubjectType(): string;

  /**
   * The bundle the blueprint is material for.
   */
  public function getSubjectBundle(): string;

  /**
   * Sets the subject: the entity type and bundle the blueprint is for.
   */
  public function setSubject(string $entity_type_id, string $bundle): static;

  /**
   * The allow-list of hosts the blueprint may be placed on.
   *
   * Each entry is `entity_type:bundle`. Empty means any host the
   * blueprint fits (its requirements decide); set, a host must also be
   * listed. An admin's narrowing on top of duck-typed compatibility.
   *
   * @return string[]
   *   The `entity_type:bundle` pairs, in stored order.
   */
  public function getBundles(): array;

  /**
   * Sets the allow-list of hosts (`entity_type:bundle` pairs).
   *
   * @param string[] $bundles
   *   The pairs; an empty list clears the allow-list.
   */
  public function setBundles(array $bundles): static;

  /**
   * The ids of the groups the blueprint is filed under.
   *
   * Filter categories on an offering surface (GROUP_ID_PATTERN); empty
   * means the blueprint is in no group.
   *
   * @return string[]
   *   The group ids, in stored order.
   */
  public function getGroups(): array;

  /**
   * Sets the group ids.
   *
   * @param string[] $groups
   *   The ids; an empty list files the blueprint under no group.
   */
  public function setGroups(array $groups): static;

}
