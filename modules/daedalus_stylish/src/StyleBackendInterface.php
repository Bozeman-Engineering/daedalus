<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Translates an entity's style map into rendered output.
 *
 * The style map always stores semantic CSS values; backends translate at
 * render time, so switching backends is a service swap
 * (the daedalus_stylish.style_backend alias), never a data migration. The shipped
 * backend prints @scope CSS into an inline <style> element; a utility-class
 * backend (e.g. Tailwind) would instead stamp classes on elements through
 * other seams and return nothing here.
 */
interface StyleBackendInterface {

  /**
   * Builds the render addition that delivers an entity's styles.
   *
   * Called from hook_entity_view(), and the result is embedded in the
   * entity's own build — so it rides the entity's render cache (invalidated
   * by ordinary entity tags on style changes) and travels with subtree swaps,
   * which is how every mutation path (widgets, code editor, AI) delivers
   * updated CSS without a dedicated field in the response contract.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The styled entity being viewed.
   * @param array $map
   *   Its decoded, non-empty style map.
   *
   * @return array
   *   A render array, or [] when this backend delivers styles another way
   *   (or nothing in the map is printable).
   */
  public function build(FieldableEntityInterface $entity, array $map): array;

}
