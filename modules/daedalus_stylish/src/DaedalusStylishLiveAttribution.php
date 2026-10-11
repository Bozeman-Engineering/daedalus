<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_ui\Render\LiveAttributionProviderInterface;

/**
 * Answers live-attribution demand from the style maps a render shows.
 *
 * A styled element's CSS uses its selectable key as the @scope root,
 * so on live renders the key must be stamped for exactly the keys the
 * delivered maps hold. Demand is answered against the render's hosts
 * (RenderStyles): the rendered entity's own map and the map of the
 * document it renders (its display's template when it has no override),
 * resolved for nested Layout Builder content through the page's root
 * entity.
 */
final class DaedalusStylishLiveAttribution implements LiveAttributionProviderInterface {

  public function __construct(
    private readonly RenderStyles $renderStyles,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function demandsKey(string $key, EntityInterface $entity): bool {
    $map_keys = $this->renderStyles->keys($entity);
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
    $map_keys = $this->renderStyles->keys($entity);
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

}
