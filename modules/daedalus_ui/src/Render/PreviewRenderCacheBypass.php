<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Render;

use Drupal\Core\Render\RenderCacheInterface;
use Drupal\daedalus_ui\ModeState;

/**
 * Bypasses the render cache entirely on preview-mode requests.
 *
 * Preview mode (?navigationMode=preview) renders the user's staged tempstore
 * state through the normal render pipeline. That output must not touch the
 * shared render cache in either direction:
 *
 * - A stored preview render would leak staged content into slots that live
 *   or edit-mode requests read. The daedalus_ui_mode cache context only
 *   protects elements that declare it: the entity swap changes what ANY
 *   sub-build renders (a page title block, a nested field), and those
 *   elements don't vary on mode, so their slots are shared across modes.
 *   Staged state also has no cache tag invalidation (tempstore writes never
 *   invalidate entity tags), so even a preview-only slot would go stale on
 *   the next mutation.
 * - A cache hit would serve saved content (or another request's staged
 *   content) where this request's staged content belongs, for the same
 *   reasons.
 *
 * Skipping reads and writes for the whole preview request closes both
 * directions at every level of the render tree (nested blocks included),
 * which per-element max-age handling cannot. Preview traffic is rare and
 * per-editor, so the lost caching is negligible.
 */
final class PreviewRenderCacheBypass implements RenderCacheInterface {

  public function __construct(
    private readonly RenderCacheInterface $inner,
    private readonly ModeState $modeState,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get(array $elements) {
    if ($this->isPreview()) {
      return FALSE;
    }
    return $this->inner->get($elements);
  }

  /**
   * {@inheritdoc}
   */
  public function getMultiple(array $multiple_elements): array {
    if ($this->isPreview()) {
      return [];
    }
    return $this->inner->getMultiple($multiple_elements);
  }

  /**
   * {@inheritdoc}
   */
  public function set(array &$elements, array $pre_bubbling_elements) {
    if ($this->isPreview()) {
      return FALSE;
    }
    return $this->inner->set($elements, $pre_bubbling_elements);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableRenderArray(array $elements) {
    return $this->inner->getCacheableRenderArray($elements);
  }

  /**
   * Whether the current request renders the staged preview.
   */
  private function isPreview(): bool {
    return $this->modeState->getMode() === 'preview';
  }

}
