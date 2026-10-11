<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_blueprint\Materials\MaterialsDelivery;
use Drupal\daedalus_stylish\ClientStyleGuide;
use Drupal\daedalus_stylish\InspectorGates;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The popped-out inspector window.
 *
 * A minimal shell: one mount div and the satellite bundle. Everything the
 * window shows about the EDIT SESSION arrives over the BroadcastChannel
 * from the leader page — this page renders no entity data of its own, which
 * is why it needs no per-entity access checking beyond the edit-mode
 * permission. Intents the satellite sends are applied by the leader through
 * the ordinary op queue, where server-side access is enforced per mutation
 * as always.
 *
 * Two things are delivered here directly, both site truth rather than
 * session state, and both needed before (and regardless of whether) a
 * leader answers the hello: the style guide payload (site configuration)
 * and the materials roster envelope (daedalus_blueprint's MaterialsDelivery,
 * the same page-load half of the delivery contract BlueprintMirror attaches
 * on the editing page). The satellite boots its own record set from the
 * envelope and follows the leader's deltas over the materials wire; there
 * is no snapshot of materials in the pairing protocol.
 *
 * The window should read as a floating panel, not a page. The route
 * declares daedalus_ui's _daedalus_ui.popout option, and that one line is
 * its whole chrome story: daedalus_ui renders every pop-out as a
 * chromeless document (Chromeless; the pop-out display variant drops the
 * block chrome, DaedalusUiHooks::preprocessHtml drops the page_top and
 * page_bottom chrome and everything it attaches). Nothing here is
 * per-pop-out.
 */
final class InspectorPopout extends ControllerBase {

  public function __construct(
    private readonly ClientStyleGuide $clientStyleGuide,
    private readonly InspectorGates $gates,
    private readonly MaterialsDelivery $materialsDelivery,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('daedalus_stylish.client_style_guide'),
      $container->get('daedalus_stylish.inspector_gates'),
      $container->get('daedalus_blueprint.materials_delivery'),
    );
  }

  /**
   * Builds the satellite shell.
   */
  public function build(): array {
    $build = [
      'inspector' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => [
          'id' => 'daedalus-stylish-inspector-root',
          'class' => ['daedalus-stylish-inspector-satellite'],
        ],
      ],
      '#attached' => [
        'library' => ['daedalus_stylish/satellite'],
        'drupalSettings' => [
          'daedalusStylish' => [
            'guide' => $this->clientStyleGuide->build(),
            // The same gesture gates the in-page host attaches
            // (InspectorGates): the satellite renders the same variant,
            // swatch, token, animation and skin controls (fetches ride its
            // own session; spine ops relay to the leader as intents).
            'variants' => $this->gates->variants(),
            'swatches' => $this->gates->swatches(),
            'tokens' => $this->gates->tokens(),
            'animations' => $this->gates->animations(),
            'fonts' => $this->gates->fonts(),
            'skin' => $this->gates->skin(),
          ],
          'daedalusUi' => [
            'materials' => $this->materialsDelivery->envelope(),
          ],
        ],
      ],
    ];
    (new CacheableMetadata())
      ->addCacheContexts(['user.permissions'])
      ->addCacheTags(ClientStyleGuide::CACHE_TAGS)
      ->merge($this->materialsDelivery->cacheMetadata())
      ->applyTo($build);
    return $build;
  }

}
