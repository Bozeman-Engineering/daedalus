<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Render;

use Drupal\Core\Render\Element;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_ui\ElementModel\ElementResolver;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_ui\ModeState;

/**
 * Renders a composed layout's declared element tree — generatively.
 *
 * The container-family half of the composed model: a layout whose
 * effective declaration says `composed: true` has no template — the
 * declaration's root forms ARE the render, resolved by the element
 * resolver (depth and emptiness are the instance facts; sections have no
 * entity) and printed through the same shared tree builder composed
 * blocks print through (ComposedTree), so the section markup the page
 * shows, the blueprint annotation, and the AI projection are one
 * computation.
 *
 * The entry point differs from composed blocks by necessity, not choice:
 * a block is swapped at the component build event, but NO event fires for
 * a section render (Section::toRenderArray() calls the layout plugin
 * directly), so the swap lives in the layout's `class:` — ComposedLayout
 * builds the composed_container element, whose pre_render lands here once
 * daedalus_layout has stamped the section's identity attributes on the build.
 *
 * A container needs no content provider: its children ARE the regions
 * build handed to the layout plugin, its depth is daedalus_layout's nested marker,
 * and its emptiness is the child count — all facts the render path already
 * holds.
 *
 * The empty-container drop affordance rides the declaration: the matched
 * {empty: true} form carries the declared marker attributes (the resolver
 * stamps them on every surface, live markup included — CSS keys the
 * affordance presentation to edit mode), and this renderer stamps only the
 * translated hint attribute, edit mode only, because a stylesheet cannot
 * carry a translatable string (the hint paints via
 * `content: attr(data-container-empty-hint)`; a real hint element would be
 * flexed by children styles).
 */
final class ComposedLayoutRenderer {

  use StringTranslationTrait;

  /**
   * The edit-only attribute carrying the empty container's hint label.
   *
   * Stamped on the composed root when the matched root form is an
   * {empty: true} form and the render is edit mode; the affordance CSS
   * (the layout owner's) paints it via `content: attr(...)`.
   */
  public const EMPTY_HINT_ATTRIBUTE = 'data-container-empty-hint';

  public function __construct(
    protected PageBuilderDeclarations $declarations,
    protected ElementResolver $resolver,
    protected ComposedTree $tree,
    protected ModeState $modeState,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Builds the composed render for one section.
   *
   * @param string $layout_id
   *   The section's layout plugin id.
   * @param array $regions
   *   The section's regions build (region name => the region's component
   *   render arrays), as handed to the layout plugin.
   * @param array $section_attributes
   *   The section's stamped attribute bag (the selectable key, the LB
   *   delta attributes), hoisted onto the composed root: the root IS the
   *   section element. Region wrappers do not render, so region-level
   *   attributes never print — a container's children are one flow.
   * @param bool $nested
   *   Whether the section renders embedded in another section (daedalus_layout's
   *   nested marker); decides the depth the declaration resolves at.
   *
   * @return array
   *   The render array: the resolved tree as markup, or cache metadata
   *   only for an undeclared root (degenerate declarations; exhaustive
   *   root forms make this unreachable for valid ones).
   */
  public function build(string $layout_id, array $regions, array $section_attributes = [], bool $nested = FALSE): array {
    $declaration = $this->declarations->getLayout($layout_id);
    if (($declaration['composed'] ?? FALSE) !== TRUE) {
      // A layout wired to the composed render path without declaring the
      // generative model is a configuration bug; fail loud rather than
      // invent markup for it.
      throw new \LogicException(sprintf('The "%s" layout renders through the composed container element, but its effective declaration does not say `composed: true`.', $layout_id));
    }

    $depth = $nested ? ElementResolver::DEPTH_NESTED : ElementResolver::DEPTH_ROOT;
    // Emptiness comes from the regions build: the same child-count fact
    // the annotator answers from the serialized node, answered here from
    // what actually renders.
    $empty = TRUE;
    foreach ($regions as $region) {
      if (is_array($region) && Element::children($region) !== []) {
        $empty = FALSE;
        break;
      }
    }

    $build = [
      '#cache' => [
        // The declarations decide the grammar; the active theme decides
        // the declaration layering; the hint below varies on the editing
        // permission and the effective mode.
        'tags' => ['daedalus_ui_page_builder'],
        'contexts' => ['theme', 'user.permissions', 'daedalus_ui_mode'],
      ],
    ];
    $resolved = $this->resolver->resolve($declaration, NULL, $depth, $empty);
    if ($resolved['tag'] === NULL) {
      return $build;
    }

    $attributes = $section_attributes;
    $form = $this->resolver->matchedRootForm($declaration, NULL, $depth, $empty);
    if (($form['present']['empty'] ?? NULL) === TRUE && $this->editMode()) {
      // The affordance is declared, never guessed: the hint stamps exactly
      // when the declaration's own empty form matched.
      $attributes[self::EMPTY_HINT_ATTRIBUTE] = (string) $this->t('Empty container');
    }

    // Children mount by the mounting rule: a region a part claims by name
    // (region:NAME) mounts in that part, the rest ride the generic regions
    // token — at the part claiming it, or at the root when no part does
    // (the one-box container: the root IS the flow).
    $children = [];
    foreach ($regions as $name => $region) {
      if (isset($resolved['slots']["region:$name"])) {
        $children["region:$name"] = $region;
      }
      else {
        $children['regions'][$name] = $region;
      }
    }

    $build['element'] = $this->tree->build($resolved, $attributes, $children);
    return $build;
  }

  /**
   * Whether this render is an edit-mode render.
   *
   * The same two facts daedalus_layout's section stamping reads: the editing
   * permission and the effective mode.
   */
  private function editMode(): bool {
    return $this->currentUser->hasPermission('use daedalus ui edit mode')
      && $this->modeState->getMode() === 'edit';
  }

}
