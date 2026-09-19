<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Render;

use Drupal\Core\Entity\EntityInterface;

/**
 * Answers whether a selectable key must reach the live (non-edit) page.
 *
 * Selection identity (data-selectable, see SelectableKey) is stamped for
 * editors only: it exists to make the page editable, and unconditionally
 * attributing anonymous renders would change cache-shared markup for no
 * consumer. Some modules, though, publish artifacts that target selectable
 * keys on the live page — Daedalus Stylish's CSS uses them as @scope roots — so the
 * markup a visitor sees must carry exactly the keys those artifacts target,
 * and no more.
 *
 * A provider answers that demand from its own data (e.g. "this entity's
 * style map has entries for this key"). The attribution seams consult the
 * collector (daedalus_ui.live_attribution_demand) when the editing
 * permission/mode gate does not already apply, and stamp the key when any
 * provider demands it.
 *
 * Providers receive the entity visible at the render seam — for nested
 * Layout Builder content that is the block_content entity, not the host the
 * demand data lives on — and are responsible for resolving the entity that
 * actually answers the question.
 *
 * Register providers with the daedalus_ui.live_attribution_provider
 * service tag.
 */
interface LiveAttributionProviderInterface {

  /**
   * Whether a selectable key needs live attribution.
   *
   * Implementations should also answer TRUE when a demanded key EXTENDS the
   * asked key with the sub-element separator (e.g. "block:{uuid}::.quote"
   * demands attribution of its parent "block:{uuid}", because the parent
   * element is the sub-element's scope root).
   *
   * @param string $key
   *   The selectable key as it would be stamped (see SelectableKey).
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity visible at the render seam.
   *
   * @return bool
   *   TRUE when the key must be stamped on the live render.
   */
  public function demandsKey(string $key, EntityInterface $entity): bool;

  /**
   * Whether any selectable key starting with a prefix needs live attribution.
   *
   * Used where one stamped element serves a family of keys — e.g. a block
   * wrapper is stamped when the block itself, its sub-elements, or its
   * fields ("block:{uuid}" + ":{field}") have demand.
   *
   * @param string $prefix
   *   A selectable key prefix.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity visible at the render seam.
   *
   * @return bool
   *   TRUE when any demanded key starts with the prefix.
   */
  public function demandsKeyPrefix(string $prefix, EntityInterface $entity): bool;

}
