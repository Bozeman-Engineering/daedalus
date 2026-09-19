<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_layout\RootEntityRegistry;
use Drupal\daedalus_ui\Render\LiveAttributionProviderInterface;

/**
 * Answers live-attribution demand from the style map.
 *
 * A styled element's CSS uses its selectable key as the @scope root,
 * so on live renders the key must be stamped for exactly the keys the map
 * holds. Demand is answered against the map HOST: the rendered entity when
 * it carries the style-map field, otherwise — for nested Layout Builder
 * content, whose block_content entities are what the render seams see — the
 * page's root entity via daedalus_layout's root-entity registry (optional; without
 * daedalus_layout there is no nesting to resolve).
 */
final class DaedalusStylishLiveAttribution implements LiveAttributionProviderInterface {

  public function __construct(
    private readonly ?RootEntityRegistry $rootEntityRegistry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function demandsKey(string $key, EntityInterface $entity): bool {
    $map_keys = $this->mapKeys($entity);
    foreach ($map_keys as $map_key) {
      if ($map_key === $key || str_starts_with($map_key, $key . '::')) {
        return TRUE;
      }
    }

    // Donut boundaries: container-rooted CSS is scope-limited at nested
    // container AND block roots (CssPrinter::CONTAINER_SCOPE_LIMIT), so the
    // moment the map styles ANY container every container and block root
    // must be stamped on live renders — the styled one is the @scope root,
    // the others are the boundaries that stop it from restyling nested
    // layout markup (an UNstamped nested container is a missing boundary:
    // ancestor container CSS would leak into its identical layout markup).
    // A root-only entry still demands boundaries: its variant styles may
    // reach descendants. Over-stamping unstyled elements is inert bytes.
    if (str_starts_with($key, 'container:') || str_starts_with($key, 'block:')) {
      foreach ($map_keys as $map_key) {
        if (str_starts_with($map_key, 'container:')) {
          return TRUE;
        }
      }
    }

    // Inline-block fields are stamped "field:{block_content_uuid}:{name}"
    // where they render (only the content entity is in scope there), while
    // the canonical map key is "block:{component_uuid}:{name}". The
    // component uuid is unknowable here, so demand is answered by field
    // name: any block-field entry with this name demands the stamp. That
    // can over-stamp a same-named field of another inline block — inert
    // bytes; the block-template rewrite produces the exact key.
    if ($entity->getEntityTypeId() === 'block_content' && preg_match('/^field:[^:]+:(.+)$/', $key, $matches)) {
      $field_pattern = '/^block:[^:]+:' . preg_quote($matches[1], '/') . '(::|$)/';
      foreach ($map_keys as $map_key) {
        if (preg_match($field_pattern, $map_key)) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function demandsKeyPrefix(string $prefix, EntityInterface $entity): bool {
    $map_keys = $this->mapKeys($entity);
    foreach ($map_keys as $map_key) {
      if (str_starts_with($map_key, $prefix)) {
        return TRUE;
      }
    }
    // The block-root donut boundary (see demandsKey): container-keyed CSS
    // is limited at block roots, and the block stamp seam asks by prefix
    // (LayoutBuilderBlockAttributes), so the same demand answers here.
    if (str_starts_with($prefix, 'block:')) {
      foreach ($map_keys as $map_key) {
        if (str_starts_with($map_key, 'container:')) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * The style-map keys governing a rendered entity's attribution.
   *
   * @return string[]
   *   The map keys, [] when no host with a non-empty map resolves.
   */
  private function mapKeys(EntityInterface $entity): array {
    return array_keys($this->map($entity));
  }

  /**
   * The style map governing a rendered entity's attribution.
   *
   * @return array
   *   The host's style map, [] when no host with a non-empty map resolves.
   */
  private function map(EntityInterface $entity): array {
    $host = $this->resolveHost($entity);
    if ($host === NULL) {
      return [];
    }
    $item = $host->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item?->getStyleMap() ?? [];
  }

  /**
   * The entity whose style map answers for a rendered entity.
   */
  private function resolveHost(EntityInterface $entity): ?FieldableEntityInterface {
    if ($entity instanceof FieldableEntityInterface && $entity->hasField(DaedalusStylishFieldManager::FIELD_NAME)) {
      return $entity;
    }
    $root = $this->rootEntityRegistry?->getRoot($entity);
    if ($root instanceof FieldableEntityInterface && $root->hasField(DaedalusStylishFieldManager::FIELD_NAME)) {
      return $root;
    }
    return NULL;
  }

}
