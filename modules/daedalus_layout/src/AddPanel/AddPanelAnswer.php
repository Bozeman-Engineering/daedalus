<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\AddPanel;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * What a canvas can place, as one list: the Add panel's server answer.
 *
 * Three parts, each the shape the panel's client model takes as it is
 * (AddPanelFaces documents how each is computed):
 * - $faces: one face per placeable block, in the restrictions' order with
 *   the Container first; each
 *   `{id, kind, label, description, icon, groups, favorite, editable}`.
 * - $groups: the site's group vocabulary in declaration order, a list of
 *   `{id, label, description}` (a list, not the catalog's map, so the
 *   order survives JSON and a digits-only id is never re-keyed).
 * - $favorites: the editor's own marks, block id (a plugin id or
 *   `blueprint:ID`) to TRUE or FALSE; [] when none (a consumer encoding
 *   it as JSON casts an empty map to an object).
 *
 * The cacheability is what the answer depends on beyond the roster: the
 * actor (the marks are theirs, and `editable` is their permission), the
 * block types (their faces), the host's display (the restrictions it
 * governs) and the declarations (the vocabulary).
 */
final class AddPanelAnswer {

  /**
   * Constructs the answer.
   *
   * @param array<int, array{id: string, kind: string, label: string, description: string, icon: string|null, groups: string[], favorite: bool, editable: bool}> $faces
   *   The faces.
   * @param array<int, array{id: string, label: string, description: string}> $groups
   *   The vocabulary.
   * @param array<string, bool> $favorites
   *   The editor's marks.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   What the answer depends on.
   */
  public function __construct(
    public readonly array $faces,
    public readonly array $groups,
    public readonly array $favorites,
    private readonly CacheableMetadata $cacheability,
  ) {}

  /**
   * What the answer depends on, for the render that attaches it.
   */
  public function cacheMetadata(): CacheableMetadata {
    return $this->cacheability;
  }

  /**
   * The answer as the settings the panel attaches.
   *
   * @return array{faces: array, groups: array, favorites: object}
   *   The three parts; the marks as an object so an empty map encodes as
   *   `{}` and the client reads one shape.
   */
  public function toArray(): array {
    return [
      'faces' => $this->faces,
      'groups' => $this->groups,
      'favorites' => (object) $this->favorites,
    ];
  }

}
