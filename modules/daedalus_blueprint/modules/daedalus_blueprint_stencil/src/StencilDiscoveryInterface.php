<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil;

/**
 * Discovers stencil definitions from both sources: files and content.
 *
 * A stencil is a reusable section: the definition IS the palette entry,
 * and placement stamps a fresh copy (a placement never back-references its
 * stencil). Definitions come from two sources served uniformly here:
 * module-shipped YAML files (read-only in every UI, the distribution lane)
 * and published stencil content entities (the save-as-stencil lane).
 * Consumers such as the Add panel list stencils from here; the instantiate
 * operation reads the element subtree from here.
 *
 * Both sources share ONE flat ID namespace where every collision is a loud
 * error, never a merge or a shadow: content saves validate their machine
 * name against the combined vocabulary, and discovery rejects a file whose
 * ID an existing content stencil already uses, exactly like a file-vs-file
 * conflict.
 */
interface StencilDiscoveryInterface {

  /**
   * The source marker for a module-shipped stencil file.
   */
  public const SOURCE_MODULE = 'module';

  /**
   * The source marker for a stencil content entity.
   */
  public const SOURCE_CONTENT = 'content';

  /**
   * Gets every stencil: module-shipped files plus published content stencils.
   *
   * @return array
   *   Stencil definitions keyed by stencil ID (the filename suffix for file
   *   stencils, the machine name for content stencils). Every definition
   *   carries the same shape regardless of source: the authored label,
   *   description, and element keys, an icon in the icon value grammar
   *   (the file's module-relative icon resolved to a root-relative path,
   *   `media:<uuid>` for a content stencil's icon media, NULL when unset),
   *   the resolved design_system membership (NULL for global) and the
   *   resolved AI opt-out under `offered` (FALSE when the file says
   *   `ai: false` or the content stencil's flag is off),
   *   plus an injected source marker (self::SOURCE_MODULE or
   *   self::SOURCE_CONTENT) and provider (the shipping module's name, NULL
   *   for content stencils). Unpublished content stencils are not served,
   *   but their IDs stay reserved.
   *
   * @throws \Drupal\daedalus_blueprint_stencil\Exception\StencilDefinitionException
   *   When a shipped file violates the stencil grammar, or any two
   *   definitions collide on an ID: file-vs-file, file-vs-content, or
   *   content-vs-content. A broken shipped file is a developer error in the
   *   shipping module and a collision is a conflict to resolve, never
   *   skippable.
   */
  public function getStencils(): array;

  /**
   * Gets one stencil definition by ID.
   *
   * @param string $id
   *   The stencil ID.
   *
   * @return array|null
   *   The definition (see getStencils() for its shape), or NULL when neither
   *   source carries the ID.
   */
  public function getStencil(string $id): ?array;

}
