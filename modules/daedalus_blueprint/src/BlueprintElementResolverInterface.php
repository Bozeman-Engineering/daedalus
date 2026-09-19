<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Resolves any addressable page element with full context.
 *
 * Single entry point for locating blocks, paragraphs, fields, sections,
 * and regions, enriched with guidance and defaults from context YAML.
 */
interface BlueprintElementResolverInterface {

  /**
   * Resolves any addressable page element with full context.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity containing the element.
   * @param string $identifier
   *   UUID (for blocks, paragraphs, sections) or field name (for fields).
   * @param string|null $parentUuid
   *   Parent component UUID — required when identifier is a field name on
   *   an inline block or a region name in a section.
   *
   * @return \Drupal\daedalus_blueprint\ResolvedElement|null
   *   The resolved element with identity, data, guidance, and defaults.
   *   NULL if the element cannot be found.
   */
  public function resolve(
    FieldableEntityInterface $entity,
    string $identifier,
    ?string $parentUuid = NULL,
  ): ?ResolvedElement;

}
