<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\BlueprintElementResolverInterface;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Materials\ElementMaterials;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\ResolvedElement;

/**
 * Resolves selected spine keys into structured element descriptions.
 *
 * The wire format is the selection spine's own identity: each selected
 * element arrives as {key, label, number}, where `key` is a selectable key
 * (the data-selectable vocabulary — the same string identity that syncs the
 * canvas, the panel, and the chat chips). This resolver parses the key and
 * resolves it against the ROOT entity's blueprint via the element resolver;
 * it never loads arbitrary entities from client-supplied identifiers, so a
 * crafted key can only ever describe what the root entity already reaches
 * (the element resolver access-gates field values itself). Keys that do not
 * resolve fall back to key-vocabulary descriptions with no entity metadata.
 *
 * Key formats (see daedalus_blueprint_api's SelectableKey):
 * - entity:{entity_uuid} — a rendered entity root
 * - field:{entity_uuid}:{name}[:{delta}] — a field of an entity
 * - container:{section_uuid} — a Layout Builder section (a container at any
 *   depth; regions have no keys, a container's children are one ordered flow)
 * - block:{component_uuid} — a Layout Builder placement
 * - block:{component_uuid}:{property} — an inline block field, or `label`:
 *   the placement's display label rendered as page content.
 *
 * Beside its identity, each description names the materials the element
 * WEARS (its worn variants by label and spelling) and the stencil it was
 * PLACED FROM (the placement's provenance stamp by id and label), both
 * read from the STAGED copy of the root entity, because the canvas shows
 * staged state: "the wide one", "what do these share" and "update the
 * stencil this came from" resolve through labels the print never carries
 * and without a print read. Per-turn facts, allowed on the message and
 * barred from the cached prefix.
 */
class ElementSelectionResolver {

  public function __construct(
    protected ?BlueprintElementResolverInterface $elementResolver = NULL,
    protected ?ElementMaterials $elementMaterials = NULL,
    protected ?EntityStorageHandler $storageHandler = NULL,
  ) {}

  /**
   * Resolves selected elements to description arrays.
   *
   * @param array $selectedElements
   *   Raw selection data from the client, each with 'key' (a selectable
   *   key), 'number', and optionally 'label'.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $rootEntity
   *   The root entity from the current route.
   *
   * @return array<int, array{description: string, materials?: string, placed_from?: string}>
   *   Resolved elements keyed by selection number.
   */
  public function resolve(array $selectedElements, ?FieldableEntityInterface $rootEntity): array {
    $resolved = [];
    $staged = $this->stagedCopy($rootEntity);

    foreach ($selectedElements as $element) {
      $key = $element['key'] ?? NULL;
      $number = $element['number'] ?? NULL;

      if (!is_string($key) || $key === '' || !$number) {
        continue;
      }

      $result = $this->resolveKey($key, $rootEntity);
      if ($result) {
        $materials = $this->wornMaterials($staged, $key);
        if ($materials !== '') {
          $result['materials'] = $materials;
        }
        $placed_from = $this->placedFrom($staged, $key);
        if ($placed_from !== '') {
          $result['placed_from'] = $placed_from;
        }
        $resolved[$number] = $result;
      }
    }

    ksort($resolved);
    return $resolved;
  }

  /**
   * The root entity's staged copy (the canvas state), or the entity itself.
   */
  protected function stagedCopy(?FieldableEntityInterface $entity): ?FieldableEntityInterface {
    if ($entity === NULL || $this->storageHandler === NULL) {
      return $entity;
    }
    $staged = $this->storageHandler->load($entity->getEntityTypeId(), (string) $entity->id());
    return $staged instanceof FieldableEntityInterface ? $staged : $entity;
  }

  /**
   * What the element wears, as prose: id "Label" (spelling); ...
   *
   * Only keys naming the root entity's own elements are read (the style
   * map is the root's), so a crafted key can describe nothing the root
   * does not already reach.
   */
  protected function wornMaterials(?FieldableEntityInterface $staged, string $key): string {
    if ($staged === NULL || $this->elementMaterials === NULL) {
      return '';
    }
    $worn = $this->elementMaterials->worn($staged, $key);
    return $worn === [] ? '' : MaterialsRoster::describe($worn, FALSE);
  }

  /**
   * The stencil the element was placed from, as prose: id "Label".
   *
   * Identity only, no spelling: provenance is a fact to target (update the
   * stencil by id), never a placement to write back, and a stamp the roster
   * no longer serves is not named (ElementMaterials::placedFrom()). Reads
   * the same staged copy the worn materials do.
   */
  protected function placedFrom(?FieldableEntityInterface $staged, string $key): string {
    if ($staged === NULL || $this->elementMaterials === NULL) {
      return '';
    }
    $stencil = $this->elementMaterials->placedFrom($staged, $key);
    return $stencil === NULL ? '' : sprintf('%s "%s"', $stencil->id, $stencil->label);
  }

  /**
   * Resolves a single selectable key.
   *
   * @param string $key
   *   The selectable key.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The root entity, or NULL when the route has none.
   *
   * @return array{description: string}|null
   *   The description array, or NULL for unusable keys.
   */
  protected function resolveKey(string $key, ?FieldableEntityInterface $entity): ?array {
    // Anatomy sub-element keys ({parent}::.class, the element model's
    // markup-shell parts) are stylable, never selectable; they cannot
    // legitimately appear in selection state.
    if (str_contains($key, '::')) {
      return NULL;
    }

    $segments = explode(':', $key);
    $prefix = $segments[0];

    return match ($prefix) {
      'entity' => isset($segments[1]) ? $this->resolveEntity($segments[1], $entity) : NULL,
      'field' => isset($segments[1], $segments[2]) ? $this->resolveField($segments[1], $segments[2], $entity) : NULL,
      'container' => isset($segments[1]) ? $this->resolveByUuid($segments[1], $entity, sprintf('Container (UUID: %s)', $segments[1])) : NULL,
      'block' => isset($segments[1]) ? $this->resolveBlock($segments[1], $segments[2] ?? NULL, $entity) : NULL,
      default => NULL,
    };
  }

  /**
   * Resolves an entity: key — the page root or a nested entity (paragraph).
   */
  protected function resolveEntity(string $uuid, ?FieldableEntityInterface $entity): ?array {
    if ($entity && $uuid === $entity->uuid()) {
      return [
        'description' => sprintf(
          'The whole %s "%s" (ID: %s) — the page itself.',
          $entity->getEntityTypeId(),
          $entity->label(),
          $entity->id(),
        ),
      ];
    }

    return $this->resolveByUuid($uuid, $entity, sprintf('Element (UUID: %s)', $uuid));
  }

  /**
   * Resolves a field: key — a field of the root entity or a paragraph.
   */
  protected function resolveField(string $entityUuid, string $fieldName, ?FieldableEntityInterface $entity): ?array {
    if ($entity && $entityUuid === $entity->uuid()) {
      return $this->resolveNamed($fieldName, NULL, $entity, sprintf('Field "%s" on this page', $fieldName));
    }

    // A paragraph's field: the qualifier is the paragraph's entity UUID,
    // which the locator resolves within the root entity.
    return $this->resolveNamed($fieldName, $entityUuid, $entity, sprintf('Field "%s" (parent UUID: %s)', $fieldName, $entityUuid));
  }

  /**
   * Resolves a block: key — a placement or one of its named properties.
   */
  protected function resolveBlock(string $componentUuid, ?string $property, ?FieldableEntityInterface $entity): ?array {
    if ($property === NULL) {
      return $this->resolveByUuid($componentUuid, $entity, $this->blockFallback($componentUuid, $entity));
    }

    // The placement's display label renders as page content (heading-style
    // blocks). It is component configuration, not a field, so describe
    // it against the resolved placement.
    if ($property === 'label') {
      return $this->resolveBlockLabel($componentUuid, $entity);
    }

    return $this->resolveNamed($property, $componentUuid, $entity, sprintf('Field "%s" on block (UUID: %s)', $property, $componentUuid));
  }

  /**
   * Resolves a UUID through the element resolver with a fallback description.
   */
  protected function resolveByUuid(string $uuid, ?FieldableEntityInterface $entity, string $fallback): array {
    if ($entity && $this->elementResolver) {
      $resolved = $this->elementResolver->resolve($entity, $uuid);
      if ($resolved) {
        return $this->formatResolvedElement($resolved);
      }
    }
    return ['description' => $fallback];
  }

  /**
   * Resolves a named element (field/region) with a fallback description.
   */
  protected function resolveNamed(string $name, ?string $parentUuid, ?FieldableEntityInterface $entity, string $fallback): array {
    if ($entity && $this->elementResolver) {
      $resolved = $this->elementResolver->resolve($entity, $name, $parentUuid);
      if ($resolved) {
        return $this->formatResolvedElement($resolved);
      }
    }
    return ['description' => $fallback];
  }

  /**
   * Describes a block placement's display label (block:{uuid}:label).
   */
  protected function resolveBlockLabel(string $componentUuid, ?FieldableEntityInterface $entity): array {
    if ($entity && $this->elementResolver) {
      $resolved = $this->elementResolver->resolve($entity, $componentUuid);
      if ($resolved) {
        return [
          'description' => sprintf(
            'The displayed label of %s "%s". entity_type: %s, entity_id: %s, uuid: %s.',
            $resolved->elementType,
            $resolved->label,
            $resolved->entityType,
            $resolved->entityId,
            $resolved->uuid,
          ),
        ];
      }
    }

    return ['description' => sprintf('The displayed label of block (UUID: %s)', $componentUuid)];
  }

  /**
   * Builds the fallback description for an unresolved block placement.
   */
  protected function blockFallback(string $blockUuid, ?FieldableEntityInterface $entity): string {
    if ($entity) {
      return sprintf(
        'Block (UUID: %s) on %s "%s" (ID: %s)',
        $blockUuid,
        $entity->getEntityTypeId(),
        $entity->label(),
        $entity->id(),
      );
    }
    return sprintf('Block (UUID: %s)', $blockUuid);
  }

  /**
   * Formats a ResolvedElement into the description array.
   */
  protected function formatResolvedElement(ResolvedElement $element): array {
    $parts = [
      sprintf('%s "%s"', ucfirst($element->elementType), $element->label),
    ];

    if ($element->typeId !== $element->label) {
      $parts[] = sprintf('(type: %s)', $element->typeId);
    }

    if ($element->parentLabel) {
      $uuidSuffix = $element->parentUuid ? ', uuid: ' . $element->parentUuid : '';
      if ($element->parentLabel === $element->parentTypeId) {
        $parts[] = sprintf('on block (type: %s%s)', $element->parentTypeId, $uuidSuffix);
      }
      else {
        $parts[] = sprintf('on block "%s" (type: %s%s)', $element->parentLabel, $element->parentTypeId ?? $element->parentLabel, $uuidSuffix);
      }
    }

    $parts[] = sprintf(
      'entity_type: %s, entity_id: %s',
      $element->entityType,
      $element->entityId,
    );

    if ($element->uuid) {
      $parts[] = sprintf('uuid: %s', $element->uuid);
    }
    if ($element->fieldName) {
      $parts[] = sprintf('field_name: %s', $element->fieldName);
    }

    return ['description' => implode('. ', $parts) . '.'];
  }

}
