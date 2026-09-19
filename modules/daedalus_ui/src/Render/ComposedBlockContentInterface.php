<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Render;

use Drupal\block_content\BlockContentInterface;

/**
 * Supplies a composed block type's content and editing attribution.
 *
 * The generative renderer (ComposedBlockRenderer) owns STRUCTURE: it
 * renders the declared element tree the resolver computed from entity data
 * — tags, part classes, declared attributes, the hoisted editing bag on
 * the root. What it cannot know is CONTENT: what actually mounts inside
 * the tree (a text value, a derived img source, filtered rich text) and
 * the editor attribution the block type's editing surface needs. That is
 * the block type owner's knowledge, and this seam is where it plugs in.
 *
 * Register with the daedalus_ui.composed_block_content service tag;
 * the renderer collects providers and asks the one whose blockType()
 * matches the rendering bundle (the service_collector pattern, so owners
 * feed without a dependency cycle).
 *
 * Editing attributes ride the DECLARED elements (no editing-only
 * elements): a provider addresses the element a field's content mounts in
 * through the resolved tree's slots map and puts its editing attribute
 * there, so the attribute follows the mounting rule — onto the claiming
 * part while it is present, onto the root while it is not.
 */
interface ComposedBlockContentInterface {

  /**
   * The block_content bundle this provider serves.
   */
  public function blockType(): string;

  /**
   * The bundle's content for one resolved instance tree.
   *
   * @param \Drupal\block_content\BlockContentInterface $block_content
   *   The block being rendered.
   * @param array $resolved
   *   The resolved element tree (ElementResolver::resolve()): tag,
   *   attributes, classes, hoisted, parts, slots.
   * @param bool $edit_capable
   *   Whether this render carries editing attribution (the hoisted bag
   *   holds the component uuid). Editor identity and settings only print
   *   on edit-capable renders; live visitor markup stays clean.
   *
   * @return array
   *   The content, all keys optional:
   *   - children: renderable children keyed by mount token. A token a
   *     present part claims (the resolved slots map) mounts inside that
   *     part; unclaimed tokens mount at the root (the mounting rule).
   *   - attributes: attribute maps keyed by resolved element: '' for the
   *     root, a part key for that part's element. A key the resolver
   *     hoisted addresses the root (the part key still addresses its
   *     element, and that element is the root).
   *   - attached: an #attached array for the render (editor libraries).
   *   - cache: a #cache array merged onto the render (tags, contexts,
   *     max-age) for content read outside the entity (referenced media,
   *     per-session editor settings).
   *   - suppress: TRUE renders nothing (cache metadata only). The
   *     provider's "this state has no live markup" answer, e.g. an empty
   *     image block: its declared placeholder root is editing chrome, so
   *     a live render is invisible.
   */
  public function content(BlockContentInterface $block_content, array $resolved, bool $edit_capable): array;

}
