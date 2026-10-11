<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\StyleGuide;

/**
 * Serializes each layout's declared default variants onto its schema entry.
 *
 * Section birth has two doors with two semantics, and the schema teaches
 * both from guide DATA, never authored prose: the add_section op stamps
 * the section layout's declared default_variants as references at birth
 * (ElementStylesSubscriber), while a section authored in a page document
 * (a page_create document, a page_edit fragment, an eb_create_entity
 * blueprint) is never stamped: the dialect runs dressed and the style map
 * grammar is declarative (an absent reference means wears NONE), so a
 * document is a total statement and stamping defaults under it would make
 * a deliberately stacked or full-bleed section unauthorable. Every
 * available_layouts entry whose layout declares defaults gains one
 * generated designer-facing line naming the stamped variants (with their
 * top-level-only limits, whether the AXIS is root-only or only the
 * DEFAULT is depth-qualified) and the door split, so a writer on either
 * door knows exactly what a new section will wear.
 */
final class LayoutDefaultsSchemaHooks {

  public function __construct(
    protected StyleGuide $styleGuide,
    protected PageBuilderDeclarations $declarations,
  ) {}

  /**
   * Implements hook_daedalus_blueprint_schema_alter().
   */
  #[Hook('daedalus_blueprint_schema_alter')]
  public function schemaAlter(array &$schema, array &$context): void {
    if (empty($schema['layout']['available_layouts'])) {
      return;
    }

    // The declaration theme matches validation and the other schema
    // surfaces: the site default theme (the schema is bundle-level and
    // describes rendered pages, not the editing request's admin theme).
    $theme = $this->declarations->siteTheme();
    $touched = FALSE;
    foreach ($schema['layout']['available_layouts'] as &$layout) {
      $id = $layout['id'] ?? NULL;
      if (!is_string($id)) {
        continue;
      }
      $line = $this->defaultsLine($this->styleGuide->getLayout($id, $theme));
      if ($line !== NULL) {
        $layout['default_variants'] = $line;
        $touched = TRUE;
      }
    }
    unset($layout);

    if ($touched) {
      // The line derives from the layered guide declarations; rebuild the
      // schema cache when the declaration spine changes.
      $context['cache_tags'][] = 'daedalus_ui_page_builder';
    }
  }

  /**
   * The declaration's stamp-on-add fact as one designer-facing line.
   *
   * The concrete variants, axes, and depth limits are guide data
   * (StyleGuide::defaultVariants() / rootOnlyAxes(), the same projections
   * the stamping subscriber consumes), so the line cannot drift from what
   * add_section actually writes. An entry is marked top-level-only when
   * its axis is root-only OR its default entry is depth-qualified — the
   * two declaration forms with the same birth consequence.
   *
   * @param array $declaration
   *   The layout's effective style guide declaration.
   *
   * @return string|null
   *   The generated line, or NULL when the layout declares no defaults.
   */
  protected function defaultsLine(array $declaration): ?string {
    $root = StyleGuide::defaultVariants($declaration);
    if ($root === []) {
      return NULL;
    }
    $nested = StyleGuide::defaultVariants($declaration, TRUE);
    $root_only_axes = StyleGuide::rootOnlyAxes($declaration);

    $parts = [];
    foreach ($root as $axis => $variant) {
      $part = "$axis: $variant";
      if (in_array($axis, $root_only_axes, TRUE) || !isset($nested[$axis])) {
        $part .= ' (top-level sections only)';
      }
      $parts[] = $part;
    }

    return 'add_section stamps these variant references on the new section: '
      . implode(', ', $parts)
      . '. A section authored in a page document (page_create, page_edit, eb_create_entity) is never stamped: it wears exactly what the document states, so state wanted defaults explicitly there.';
  }

}
