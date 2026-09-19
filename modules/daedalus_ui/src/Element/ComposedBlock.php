<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Element;

use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;

/**
 * The composed block render element: one declared tree, rendered.
 *
 * The shared render element for every `composed: true` block type. No
 * per-type theme hook, no template: the bundle's page_builder declaration
 * is generative, and the renderer prints the tree the element resolver
 * computes from entity data (see ComposedBlockRenderer for the mechanism
 * and ComposedBlockContentInterface for how block type owners plug their
 * content in).
 *
 * Properties:
 * - #block_content: the block_content entity to render.
 * - #hoisted_attributes: the editing-attribute bag for the composed root
 *   (the selection key, the component uuid, the presentation). The
 *   component render path (ComposedBlockRender) fills this from the
 *   block build's stamped attributes; standalone consumers (tests, sample
 *   renders) pass what the context calls for — an empty bag renders the
 *   clean live markup.
 *
 * Usage:
 * @code
 * $build = [
 *   '#type' => 'composed_block',
 *   '#block_content' => $block_content,
 * ];
 * @endcode
 */
#[RenderElement('composed_block')]
final class ComposedBlock extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#pre_render' => [
        [static::class, 'preRenderComposedBlock'],
      ],
      '#block_content' => NULL,
      '#hoisted_attributes' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return array_merge(parent::trustedCallbacks(), ['preRenderComposedBlock']);
  }

  /**
   * Builds the composed render at render time.
   */
  public static function preRenderComposedBlock(array $element): array {
    $element['composed'] = \Drupal::service('daedalus_ui.composed_block_renderer')
      ->build($element['#block_content'], $element['#hoisted_attributes']);
    return $element;
  }

}
