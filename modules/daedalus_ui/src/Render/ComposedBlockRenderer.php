<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Render;

use Drupal\block_content\BlockContentInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\Cache;
use Drupal\daedalus_ui\ElementModel\ElementResolver;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;

/**
 * Renders a composed block's declared element tree — generatively.
 *
 * The declaration is GENERATIVE for composed blocks (`composed: true`):
 * the markup model in the bundle's page_builder declaration is not a
 * description of a template, it IS the render. One shared path resolves
 * the declaration against entity data (ElementResolver) and prints the
 * resolved tree through the shared tree builder (ComposedTree, the same
 * printer composed containers render with) — the matched root form's tag
 * and attributes, the present parts as nested elements wearing their part
 * classes, hoisted classes on the root, the hoisted editing-attribute bag
 * merged onto the root — so the markup every surface reasons about
 * (server render, blueprint annotation, AI projection) is one computation
 * that cannot drift.
 *
 * Content — what mounts INSIDE the tree — is the block type owner's
 * knowledge and plugs in through ComposedBlockContentInterface providers:
 * children keyed by mount token (placed by the mounting rule: a token a
 * present part claims mounts in that part, unclaimed tokens mount at the
 * root), per-element attributes (derived img sources, editing
 * attribution), attachments, and extra cacheability.
 *
 * Bespoke templates (plugin blocks, contrib, themes) remain the escape
 * hatch: an uncomposed declaration merely DESCRIBES markup and never
 * renders through here.
 */
final class ComposedBlockRenderer {

  /**
   * The hoisted-bag attribute that marks a render edit-capable.
   *
   * Only edit-mode renders carry the component uuid, so its presence is
   * the tell providers gate editor attribution on; live visitor markup
   * stays clean.
   */
  public const EDIT_CAPABLE_ATTRIBUTE = 'data-layout-builder-block-uuid';

  /**
   * The collected content providers, keyed by block_content bundle.
   *
   * @var \Drupal\daedalus_ui\Render\ComposedBlockContentInterface[]
   */
  private array $providers = [];

  public function __construct(
    protected PageBuilderDeclarations $declarations,
    protected ElementResolver $resolver,
    protected ComposedTree $tree,
  ) {}

  /**
   * Collects a block type's content provider (service_collector).
   */
  public function addContentProvider(ComposedBlockContentInterface $provider): void {
    $this->providers[$provider->blockType()] = $provider;
  }

  /**
   * Builds the composed render for one block.
   *
   * @param \Drupal\block_content\BlockContentInterface $block_content
   *   The block to render.
   * @param array $hoisted_attributes
   *   The editing-attribute bag hoisted onto the composed root (the
   *   selection key, the component uuid, the presentation), already
   *   filtered to what composed markup carries.
   *
   * @return array
   *   The render array: the resolved tree as markup, or cache metadata
   *   only when this state renders nothing (an undeclared root, or a
   *   provider-suppressed live state).
   */
  public function build(BlockContentInterface $block_content, array $hoisted_attributes = []): array {
    $declaration = $this->declarations->getBlockType($block_content->bundle());
    $resolved = $this->resolver->resolve($declaration, $block_content);
    $edit_capable = isset($hoisted_attributes[self::EDIT_CAPABLE_ATTRIBUTE]);

    $build = [
      '#cache' => [
        // The entity decides the tree; the declarations decide the
        // grammar; the active theme decides the declaration layering.
        'tags' => Cache::mergeTags($block_content->getCacheTags(), ['daedalus_ui_page_builder']),
        'contexts' => ['theme'],
      ],
    ];

    $content = [];
    $provider = $this->providers[$block_content->bundle()] ?? NULL;
    if ($provider !== NULL) {
      $content = $provider->content($block_content, $resolved, $edit_capable);
    }
    if (!empty($content['cache'])) {
      $build['#cache'] = NestedArray::mergeDeep($build['#cache'], $content['cache']);
    }
    if (!empty($content['attached'])) {
      $build['#attached'] = $content['attached'];
    }

    // An unresolvable root is honestly undeclared (degenerate data outside
    // the declared forms); a provider-suppressed state has no live markup
    // by its owner's judgment. Both render their cache metadata only.
    if ($resolved['tag'] === NULL || !empty($content['suppress'])) {
      return $build;
    }

    $build['element'] = $this->tree->build(
      $resolved,
      $hoisted_attributes,
      $content['children'] ?? [],
      $content['attributes'] ?? [],
    );
    return $build;
  }

}
