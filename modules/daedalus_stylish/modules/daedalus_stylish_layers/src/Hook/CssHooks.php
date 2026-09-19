<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layers\Hook;

use Drupal\Core\Asset\AttachedAssetsInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Language\LanguageInterface;
use Drupal\daedalus_stylish_layers\Layers;

/**
 * CSS asset hook implementations.
 */
final class CssHooks {

  public function __construct(
    private readonly Layers $layers,
  ) {}

  /**
   * Implements hook_css_alter().
   *
   * Stamps every non-exempt CSS asset with the wrapper layer name. The stamp
   * — not config reads inside the asset decorators — decides what gets
   * layered: it flows into core's aggregate filename hash, so config and
   * exemption changes produce new aggregate URLs instead of new content at
   * old URLs, and this hook fires on both the page-render and
   * asset-controller paths, keeping the two in lockstep.
   *
   * Ordered LAST so any module can exempt any asset (its own or another's)
   * by setting the exempt key from its own hook_css_alter(). Theme-side css
   * alters still run later; a theme exempting dynamically must unset the
   * stamp too — the libraries.yml key needs no such care.
   */
  #[Hook('css_alter', order: Order::Last)]
  public function cssAlter(array &$css, AttachedAssetsInterface $assets, LanguageInterface $language): void {
    $this->layers->stampAssets($css);
  }

}
