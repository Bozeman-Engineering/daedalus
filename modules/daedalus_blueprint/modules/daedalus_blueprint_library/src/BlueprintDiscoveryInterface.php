<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library;

/**
 * Discovers blueprint definitions from both sources: files and content.
 *
 * A blueprint is a saved, reusable page document: the definition IS the
 * palette entry, and placement instantiates a fresh copy (a placement
 * never back-references its blueprint). Definitions come from two sources
 * served uniformly here: module-shipped YAML files (read-only in every UI,
 * the distribution lane) and published blueprint content entities (saved
 * on the site, edited on their own canvas). Consumers such as the Add
 * panel list blueprints from here; the placement operation reads the
 * document from here.
 *
 * Both sources share ONE flat ID namespace where every collision is a loud
 * error, never a merge or a shadow: content saves validate their machine
 * name against the combined vocabulary, and discovery rejects a file whose
 * ID an existing content blueprint already uses, exactly like a
 * file-vs-file conflict.
 */
interface BlueprintDiscoveryInterface {

  /**
   * The source marker for a module-shipped blueprint file.
   */
  public const SOURCE_MODULE = 'module';

  /**
   * The source marker for a blueprint content entity.
   */
  public const SOURCE_CONTENT = 'content';

  /**
   * Gets every blueprint: module-shipped files plus published content.
   *
   * @return array
   *   Blueprint definitions keyed by blueprint ID (the filename suffix for
   *   file blueprints, the machine name for content blueprints). Every
   *   definition carries the same shape regardless of source: the authored
   *   label and description, an icon in the icon value grammar (the file's
   *   module-relative icon resolved to a root-relative path, `media:<uuid>`
   *   for a content blueprint's icon media, NULL when unset), the resolved
   *   design_system membership (NULL for global), the resolved AI opt-out
   *   under `offered` (FALSE when the file says `ai: false` or the content
   *   blueprint's flag is off), the scope (block or page), the subject
   *   (`entity_type:bundle`), the allow-list as `bundles` ([] for a file,
   *   which carries none), the group ids as `groups` (filter categories,
   *   [] when the blueprint is in no group), the site favorite as
   *   `favorite` (TRUE when the file says `favorite: true` or the content
   *   blueprint's flag is on), the type as `type` (`section` when a file
   *   names none) with its frame-only rule as `frame_only` (TRUE when the
   *   type keeps its blueprints on the site frame, resolved from the type
   *   entity; FALSE for a type the site lacks), the document as `layout`
   *   (the lean tree: a
   *   list of root container nodes in the shape NodeTreeBuilder::build()
   *   answers) and `styles` (the node-less style keys, [] when none), plus
   *   an injected source marker (self::SOURCE_MODULE or
   *   self::SOURCE_CONTENT) and provider (the shipping module's name, NULL
   *   for content blueprints, which carry their `origin` instead).
   *   Unpublished content blueprints are not served, but their IDs stay
   *   reserved.
   *
   * @throws \Drupal\daedalus_blueprint_library\Exception\BlueprintDefinitionException
   *   When a shipped file violates the blueprint grammar, or any two
   *   definitions collide on an ID: file-vs-file, file-vs-content, or
   *   content-vs-content. A broken shipped file is a developer error in
   *   the shipping module and a collision is a conflict to resolve, never
   *   skippable.
   */
  public function getBlueprints(): array;

  /**
   * Gets one blueprint definition by ID.
   *
   * @param string $id
   *   The blueprint ID.
   *
   * @return array|null
   *   The definition (see getBlueprints() for its shape), or NULL when
   *   neither source carries the ID.
   */
  public function getBlueprint(string $id): ?array;

}
