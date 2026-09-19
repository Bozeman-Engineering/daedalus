<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layers\Asset;

use Drupal\Core\Asset\AssetCollectionGroupOptimizerInterface;
use Drupal\daedalus_stylish_layers\Layers;

/**
 * Decorates asset.css.collection_optimizer to layer aggregated CSS.
 *
 * The aggregation-ON seam: optimizeGroup() output is the exact string dumped
 * for the browser. Wrapping happens here (re-extracting the hoisted leading
 * `@import` rules as `@import ... layer(<wrapper>);` first, then wrapping the
 * remainder in `@layer <wrapper> { ... }`).
 *
 * Whether a group gets wrapped — and in which layer — is decided solely by
 * the stamp hook_css_alter() put on it (group-level via the grouper
 * decorator), NOT by reading config here: the stamp fed the aggregate
 * filename hash, so keying the content on the same stamp keeps URL and
 * content coupled on both the page-render and asset-controller paths.
 */
final class LayeredCssCollectionOptimizer implements AssetCollectionGroupOptimizerInterface {

  public function __construct(
    private readonly AssetCollectionGroupOptimizerInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function optimize(array $assets, array $libraries) {
    return $this->inner->optimize($assets, $libraries);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    return $this->inner->deleteAll();
  }

  /**
   * {@inheritdoc}
   */
  public function optimizeGroup(array $group): string {
    $data = $this->inner->optimizeGroup($group);
    $wrapper = $group[Layers::STAMP_KEY] ?? NULL;
    if (!Layers::isValidLayerName($wrapper)) {
      return $data;
    }

    // The inner optimizer already inlined every @import it could and hoisted
    // the survivors (external and media-/supports-qualified imports) to the
    // top of the aggregate. @import is invalid inside a block statement and
    // would be dropped by browsers, so pull the survivors back out and
    // re-emit each as `@import <url> layer(<wrapper>) <conditions>;` before
    // the wrapper — the spec-blessed way to put a whole imported file into
    // the layer (per the grammar, layer() sits between the URL and the
    // import conditions). This is core's hoisting regexp
    // (CssCollectionOptimizerLazy::optimizeGroup) with the URL token and the
    // trailing import conditions captured.
    $regexp = <<<'REGEXP'
/@import\s*('(?:\\'|.)*'|"(?:\\"|.)*"|url\(\s*(?:\\[\)\'\"]|[^'")])*\s*\)|url\(\s*'(?:\'|.)*'\s*\)|url\(\s*"(?:\"|.)*"\s*\))(.*);/iU
REGEXP;
    $imports = '';
    if (preg_match_all($regexp, $data, $matches, PREG_SET_ORDER)) {
      $data = preg_replace($regexp, '', $data);
      foreach ($matches as $match) {
        $conditions = trim($match[2]);
        // An import that already targets a layer becomes a sublayer of the
        // wrapper — a sublayer can never outrank unlayered (Daedalus Stylish) styles,
        // and emitting two layer() tokens would be invalid CSS.
        if (preg_match('/^layer(?:\(\s*([-\w.]+)\s*\))?(?:\s+|$)/i', $conditions, $layer_match)) {
          $sublayer = isset($layer_match[1]) && $layer_match[1] !== '' ? '.' . $layer_match[1] : '';
          $conditions = trim(substr($conditions, strlen($layer_match[0])));
          $layer = 'layer(' . $wrapper . $sublayer . ')';
        }
        else {
          $layer = 'layer(' . $wrapper . ')';
        }
        $imports .= '@import ' . $match[1] . ' ' . $layer . ($conditions === '' ? '' : ' ' . $conditions) . ";\n";
      }
    }

    return $imports . '@layer ' . $wrapper . " {\n" . $data . "\n}\n";
  }

}
