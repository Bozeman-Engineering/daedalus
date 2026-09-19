<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_ui\ElementModel\AnatomyExamples;
use Drupal\daedalus_ui\ElementModel\ClientAnatomy;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;

/**
 * Feeds the element model's anatomy into the AI-facing blueprint schema.
 *
 * The AI is a schema-time consumer: it has no per-instance `_element`
 * annotations and no client anatomy payload, so the markup shells reach it
 * as a bundle-schema fragment through daedalus_blueprint's context seam, the
 * same seam every schema-side fact rides (the blueprint DATA stays
 * annotation-free; anatomy is delivery). The fragment is the ClientAnatomy
 * projection (labels, contains: slots, nested elements) plus, per composed
 * block type, the compose fact and auto-rendered example markup, and per
 * declared layout, example markup per nesting depth (AnatomyExamples:
 * the schema readers are small models, which follow concrete examples over
 * abstract grammar). Compose is stripped from the client payload because
 * `_element` already carries it there, but at schema time this fragment IS
 * the only channel, and a model composing markup from the shells must know
 * a composed block renders as a single element with no shells at all.
 *
 * Theme resolution is PageBuilderDeclarations::siteTheme() (the site
 * default theme), ClientAnatomy's own: the schema is bundle-level and
 * cached, describing the site's rendered pages to an off-page consumer,
 * and the client payload describes the same pages to the editing session,
 * so the two read one anatomy from the same declarations validation and
 * the style guide do.
 */
class DaedalusBlueprintHooks {

  public function __construct(
    protected ClientAnatomy $clientAnatomy,
    protected PageBuilderDeclarations $declarations,
    protected AnatomyExamples $anatomyExamples,
  ) {}

  /**
   * Implements hook_daedalus_blueprint_context_alter().
   */
  #[Hook('daedalus_blueprint_context_alter')]
  public function contextAlter(array &$context, string $entity_type, string $bundle): void {
    $theme = $this->declarations->siteTheme();
    $anatomy = $this->clientAnatomy->build();

    if ($anatomy['kinds'] !== [] || $anatomy['blockPlugins'] !== []) {
      $context['anatomy'] = [
        'guidance' => 'How to read the anatomy trees: "elements" lists the wrapper parts an element renders between its root and its children, keyed by class selector; a part\'s "contains" names the model children that mount inside it, and children no part claims mount at the element root. Block types carry their effective tree in available_block_types[*].anatomy, plugin block placements in anatomy.block_plugins keyed by BASE plugin id (any :derivative suffix stripped), sections in their layout\'s available_layouts entry; anything unlisted uses its kind\'s tree here. anatomy.composed: true means the type renders as ONE element whose declared elements are interior parts, no wrapper shells at all. A "present" condition on a part or root form is a render gate: while the entity data does not satisfy it, that element does not exist, so compose nothing for it. "root.forms" is ordered, and the first form whose condition holds is the element\'s root. A part may declare "forms" the same way instead of one tag: the first form whose condition holds supplies the part\'s tag, attributes, and label (a form\'s "label" is its name in that state; the part-level label is the fallback), and a part none of whose forms match does not render. A root form may compose several conditions in one "present" map (all must hold), and a {depth: "root"|"nested"} condition on a container\'s root form picks its tag by nesting depth (the shipped container: section at root depth, div nested); {empty: true} on a container holds while the section has no children. Trust anatomy.example over reasoning about the tree: it is the markup the type actually renders, auto-rendered from sample data in the read modes\' key-annotated print format (UUID stands in for the real uuid; a part the sample data does not render is absent exactly as it would be on a page). anatomy.example_nested is the nested shape when it differs from the root-depth example.',
        'kinds' => $anatomy['kinds'],
      ];
      // Plugin blocks have no available_block_types row to carry their
      // fragment (they are placements, not placeable types), so their
      // anatomy rides the top-level fragment, which the schema builder
      // merges whole.
      if ($anatomy['blockPlugins'] !== []) {
        $context['anatomy']['block_plugins'] = $anatomy['blockPlugins'];
      }
    }

    foreach ($anatomy['blockTypes'] as $block_type => $fragment) {
      $context['block_types'][$block_type]['anatomy'] = $fragment;
    }
    foreach ($anatomy['layouts'] as $layout_id => $fragment) {
      $context['layouts'][$layout_id]['anatomy'] = $fragment;
    }

    // Compose rides the effective block-type declaration (`composed:
    // true`); surface it on the anatomy fragment so the compose fact, the
    // shells it voids, and the example markup arrive together (a composed
    // type with no shells still gets a fragment: the flag is the anatomy).
    // The example is the resolved markup of a sample-populated entity,
    // auto-rendered through the element model, never hand-written.
    $examples = $this->anatomyExamples->blockTypeExamples($theme);
    foreach ($this->declarations->getBlockTypes($theme) as $block_type) {
      $declaration = $this->declarations->getBlockType($block_type, $theme);
      if (($declaration['composed'] ?? FALSE) === TRUE) {
        $context['block_types'][$block_type]['anatomy']['composed'] = TRUE;
        if (isset($examples[$block_type])) {
          $context['block_types'][$block_type]['anatomy']['example'] = $examples[$block_type];
        }
      }
    }

    // Layout examples resolve from declaration + depth alone, one per
    // depth when the declaration's depth conditions make the nested
    // shape differ.
    foreach ($this->anatomyExamples->layoutExamples($theme) as $layout_id => $example) {
      $context['layouts'][$layout_id]['anatomy']['example'] = $example['root'];
      if (isset($example['nested'])) {
        $context['layouts'][$layout_id]['anatomy']['example_nested'] = $example['nested'];
      }
    }

    // The fragment derives from the layered declarations: rebuild the
    // context/schema caches when the declaration spine does.
    $context['cache_tags'][] = 'daedalus_ui_page_builder';
  }

}
