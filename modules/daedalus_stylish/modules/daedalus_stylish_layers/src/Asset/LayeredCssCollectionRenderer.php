<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layers\Asset;

use Drupal\Core\Asset\AssetCollectionRendererInterface;
use Drupal\daedalus_stylish_layers\Layers;

/**
 * Decorates asset.css.collection_renderer to layer non-aggregated CSS.
 *
 * The aggregation-OFF / preprocess=FALSE / external seam: no PHP reads these
 * file bodies, so the only content-agnostic layering is rewriting each
 * stamped `<link>` into `<style>@import url("...") layer(<wrapper>);
 * </style>` at render time. The stamp hook_css_alter() put on the asset —
 * not a config read here — decides the rewrite and names the layer; unstamped
 * (disabled/exempt) assets render as core links.
 */
final class LayeredCssCollectionRenderer implements AssetCollectionRendererInterface {

  public function __construct(
    private readonly AssetCollectionRendererInterface $inner,
    private readonly Layers $layers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(array $assets) {
    $elements = [];
    // Render one asset at a time so each element stays paired with the asset
    // metadata that decides whether it may be layered, while the inner
    // renderer keeps owning URL generation and the cache-busting query string.
    foreach ($assets as $key => $asset) {
      foreach ($this->inner->render([$key => $asset]) as $element) {
        $elements[] = $this->isLayerable($asset, $element)
          ? $this->toLayeredImport($element, $asset[Layers::STAMP_KEY])
          : $element;
      }
    }
    return $elements;
  }

  /**
   * Whether an asset's stylesheet link may be rewritten into a layered import.
   */
  private function isLayerable(array $asset, array $element): bool {
    // AJAX delivery can only carry attribute-bearing <link> elements: the
    // add_css command drops a <style> element silently, and an all-layered
    // response hangs the client's command queue on an empty command (see
    // Layers::withLinkRewriteSuppressed()). The AJAX attachments processor
    // decorator suppresses the rewrite around its inner call.
    if ($this->layers->isLinkRewriteSuppressed()) {
      return FALSE;
    }
    // With aggregation on, assets here are group-shaped and carry the
    // group-level stamp; off, they are raw assets with item stamps.
    if (!Layers::isValidLayerName($asset[Layers::STAMP_KEY] ?? NULL)) {
      return FALSE;
    }
    // Aggregates were already wrapped in the layer by the collection
    // optimizer decorator; their links must load them untouched.
    if (!empty($asset['preprocessed'])) {
      return FALSE;
    }
    // Extra link attributes (integrity, crossorigin, ...) have no @import
    // equivalent. Dropping them is worse than losing the layer guarantee for
    // one stylesheet, so these links pass through unlayered.
    if (!empty($asset['attributes'])) {
      return FALSE;
    }
    return ($element['#tag'] ?? NULL) === 'link' && isset($element['#attributes']['href']);
  }

  /**
   * Converts a stylesheet link element into a layered @import style element.
   */
  private function toLayeredImport(array $element, string $wrapper): array {
    $media = $element['#attributes']['media'] ?? '';
    return [
      '#type' => 'html_tag',
      '#tag' => 'style',
      '#value' => sprintf(
        '@import url("%s") layer(%s)%s;',
        addcslashes($element['#attributes']['href'], "\"\\"),
        $wrapper,
        $media !== '' && $media !== 'all' ? ' ' . $media : '',
      ),
    ];
  }

}
