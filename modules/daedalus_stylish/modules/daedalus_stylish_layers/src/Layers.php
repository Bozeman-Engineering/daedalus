<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layers;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Names and emits the cascade layers that make unlayered Daedalus Stylish CSS win.
 *
 * The contract (see README.md):
 * - All pipeline CSS (core, contrib, theme) is wrapped in the WRAPPER layer.
 * - Daedalus Stylish's own emitted CSS stays UNLAYERED, so for normal declarations it
 *   beats every wrapped rule regardless of specificity.
 * - The FORCE layer is declared FIRST and ships EMPTY. Importance reverses
 *   layer order, so `!important` in the earliest layer beats `!important`
 *   everywhere else — the force layer is the escape hatch Daedalus Stylish routes
 *   must-win `!important` declarations into. Nothing else may write to it.
 * - The layer-order statement below must be the FIRST occurrence of either
 *   layer name in the document, otherwise a later occurrence would establish
 *   a different order. It is emitted as a html_head element, which renders
 *   in head-placeholder — before css-placeholder where all pipeline CSS
 *   (and any wrapper-layer occurrence) lands.
 *
 * Config (enabled/wrapper name) reaches the asset pipeline exclusively as a
 * per-asset STAMP written by hook_css_alter() via stampAssets(). The stamp is
 * the single discriminator the grouper/optimizer/renderer decorators act on,
 * and because item-level asset keys feed core's aggregate filename hash
 * (AssetGroupSetHashTrait::generateHash()), any config or exemption change
 * yields new aggregate URLs on its own — no stale-content-at-same-URL window.
 * hook_css_alter fires identically on page render and in the asset controller
 * (both go through AssetResolver::getCssAssets()), so the stamps a URL was
 * hashed from are reproduced when the aggregate is generated.
 */
final class Layers {

  /**
   * Asset key carrying the wrapper layer name; written ONLY by stampAssets().
   */
  public const STAMP_KEY = 'daedalus_stylish_layers';

  /**
   * Asset key opting a libraries.yml CSS entry out of layering.
   *
   * Extra keys on CSS entries survive library parsing and land on the asset
   * arrays verbatim, so libraries declare exemption in place:
   * @code
   * css:
   *   component:
   *     css/chrome.css: { daedalus_stylish_layers_exempt: true }
   * @endcode
   */
  public const EXEMPT_KEY = 'daedalus_stylish_layers_exempt';

  /**
   * The always-first, ships-empty `!important` escape-hatch layer.
   */
  public const FORCE_LAYER = 'daedalus_stylish_force';

  /**
   * Default name of the layer wrapping all pipeline CSS.
   */
  public const DEFAULT_WRAPPER_LAYER = 'site';

  /**
   * Valid CSS layer name (a CSS identifier; no dots — no sub-layer here).
   */
  private const LAYER_NAME_PATTERN = '/^-?[a-zA-Z_][a-zA-Z0-9_-]*$/';

  /**
   * Whether the renderer's link rewrite is suppressed (AJAX delivery).
   */
  private bool $linkRewriteSuppressed = FALSE;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Whether layering is enabled (runtime kill-switch; install is the default).
   */
  public function isEnabled(): bool {
    return (bool) $this->configFactory->get('daedalus_stylish_layers.settings')->get('enabled');
  }

  /**
   * The wrapper layer name, validated with fallback to the default.
   */
  public function getWrapperLayer(): string {
    $name = $this->configFactory->get('daedalus_stylish_layers.settings')->get('wrapper_layer');
    if (!is_string($name) || !preg_match(self::LAYER_NAME_PATTERN, $name)) {
      return self::DEFAULT_WRAPPER_LAYER;
    }
    return $name;
  }

  /**
   * The layer-order statement whose first occurrence fixes layer precedence.
   */
  public function getOrderStatement(): string {
    return sprintf('@layer %s, %s;', self::FORCE_LAYER, $this->getWrapperLayer());
  }

  /**
   * Stamps CSS asset arrays with the wrapper layer they belong in.
   *
   * Called from hook_css_alter(). Owns STAMP_KEY outright: a stamp injected
   * by a library definition is discarded, so layering can never be triggered
   * (or an arbitrary layer name smuggled in) from a libraries.yml file.
   */
  public function stampAssets(array &$css): void {
    $stamp = $this->isEnabled() ? $this->getWrapperLayer() : NULL;
    foreach ($css as &$options) {
      unset($options[self::STAMP_KEY]);
      if ($stamp !== NULL && empty($options[self::EXEMPT_KEY])) {
        $options[self::STAMP_KEY] = $stamp;
      }
    }
  }

  /**
   * Whether a stamp read back off an asset or group is a usable layer name.
   */
  public static function isValidLayerName(mixed $name): bool {
    return is_string($name) && preg_match(self::LAYER_NAME_PATTERN, $name) === 1;
  }

  /**
   * Runs $callable with the renderer's link → @import rewrite suppressed.
   *
   * Core's AJAX asset delivery cannot carry a layered <style> element: the
   * add_css command is built by array_column()ing the rendered collection's
   * #attributes (AjaxResponseAttachmentsProcessor), and the client rebuilds
   * each entry as a <link>. A rewritten <style>@import ... layer()> element
   * has no #attributes, so it is silently dropped from the command, and a
   * response whose new CSS is ALL layered produces an add_css command with
   * empty data, which never resolves client-side (loadjs.ready([]) never
   * fires its callback) and hangs the whole command queue behind it,
   * including every markup swap.
   *
   * The AJAX attachments processor decorator wraps its inner call in this,
   * so stamped assets render as core's plain links for AJAX delivery: the
   * stylesheet loads unlayered until the next full page render. Aggregated
   * responses lose nothing: aggregates carry the layer inside the file and
   * always render as links (see LayeredCssCollectionOptimizer).
   */
  public function withLinkRewriteSuppressed(callable $callable): mixed {
    $previous = $this->linkRewriteSuppressed;
    $this->linkRewriteSuppressed = TRUE;
    try {
      return $callable();
    }
    finally {
      $this->linkRewriteSuppressed = $previous;
    }
  }

  /**
   * Whether the link rewrite is currently suppressed.
   *
   * Read by LayeredCssCollectionRenderer; TRUE only inside a
   * withLinkRewriteSuppressed() call.
   */
  public function isLinkRewriteSuppressed(): bool {
    return $this->linkRewriteSuppressed;
  }

}
