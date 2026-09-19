<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;

/**
 * What one element wears and where it was placed from, as records.
 *
 * The one read behind every "what is this element using" surface (the
 * ACTIVE SELECTION block, read-back tools): the element's style-map entry
 * names variants by machine name, its lean node carries the placement's
 * stencil stamp, and the roster knows their labels and spellings, so a
 * consumer gets records and renders them however its surface speaks
 * (MaterialsRoster::describe()). Reads the entity it is handed: callers
 * decide whether that is the saved or the staged copy.
 */
class ElementMaterials {

  public function __construct(
    protected NodeTreeBuilder $treeBuilder,
    protected MaterialsRosterBuilder $rosterBuilder,
  ) {}

  /**
   * The variants an element wears, in the entry's reference order.
   *
   * A composition fragment reference counts as wearing the variant (the
   * element carries a slice of it); a reference the roster no longer
   * resolves (a deleted or unpublished variant) delivers nothing and is
   * not worn.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The host entity whose style map answers for the element.
   * @param string $key
   *   The element's selectable key (a style-map key).
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The worn variant records, each once.
   */
  public function worn(FieldableEntityInterface $entity, string $key): array {
    $entry = $this->treeBuilder->styleMap($entity)[$key] ?? NULL;
    if (!is_array($entry) || !is_array($entry['variants'] ?? NULL)) {
      return [];
    }
    $roster = $this->rosterBuilder->build();
    $worn = [];
    foreach ($entry['variants'] as $reference) {
      $id = is_array($reference) ? (string) ($reference['variant'] ?? '') : '';
      if ($id === '' || isset($worn[$id])) {
        continue;
      }
      $record = $roster->get(MaterialKind::Variant, $id);
      if ($record !== NULL) {
        $worn[$id] = $record;
      }
    }
    return array_values($worn);
  }

  /**
   * The stencil an element was placed from, or NULL.
   *
   * Reads the lean node's `stencil` key (the serializer's read-only surface
   * of the instantiate stamp on a section's third-party settings or a
   * component's additional data) and resolves it against the roster. Only a
   * bare element key (`container:{uuid}` / `block:{uuid}`) names a
   * placement; a field or part key is not one. A stamp the roster no longer
   * serves (a deleted or unpublished stencil) is residue, exactly as the
   * printer treats it: never named.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The host entity whose layout carries the element.
   * @param string $key
   *   The element's selectable key.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord|null
   *   The stencil record, or NULL when the element is not a placement or
   *   its stencil is gone.
   */
  public function placedFrom(FieldableEntityInterface $entity, string $key): ?MaterialRecord {
    if (!preg_match('/^(?:container|block):([^:]+)$/', $key, $match)) {
      return NULL;
    }
    $node = $this->findNode($this->treeBuilder->build($entity), $match[1]);
    $stencil = $node['stencil'] ?? NULL;
    if (!is_string($stencil) || $stencil === '') {
      return NULL;
    }
    return $this->rosterBuilder->build()->get(MaterialKind::Stencil, $stencil);
  }

  /**
   * The style key of a placed element, or NULL when the page lacks it.
   *
   * The page language names an element by uuid; the style map keys it by
   * kind (`container:{uuid}` for a section at any depth, `block:{uuid}`
   * for a component, `entity:{uuid}` for the page itself). The one read
   * behind every gesture that takes a page-language element and needs its
   * style entry (a swatch captured from what it wears, a variant saved
   * from its look).
   */
  public function keyFor(FieldableEntityInterface $entity, string $uuid): ?string {
    if ($entity->uuid() === $uuid) {
      return 'entity:' . $uuid;
    }
    $node = $this->findNode($this->treeBuilder->build($entity), $uuid);
    return match ($node['kind'] ?? NULL) {
      'container' => 'container:' . $uuid,
      'block' => 'block:' . $uuid,
      default => NULL,
    };
  }

  /**
   * Finds the lean node with a uuid at any depth (regions are transparent).
   */
  protected function findNode(array $nodes, string $uuid): ?array {
    foreach ($nodes as $node) {
      if (!is_array($node)) {
        continue;
      }
      if (($node['uuid'] ?? NULL) === $uuid) {
        return $node;
      }
      $found = $this->findNode($node['children'] ?? [], $uuid);
      if ($found !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

}
