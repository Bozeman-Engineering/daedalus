<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_blueprint\Materials\MaterialsSchema;

/**
 * Lands the roster's `materials` fragment on every schema that can use it.
 *
 * One projection of the roster for both doors: the JSON door reads
 * `materials` beside `fields` (a variant's styles, a skin's defaults, a
 * swatch's value all reference named values), and the dialect projector
 * carries the same key through unchanged. Rides the schema alter seam
 * rather than the builder's own body so the fragment lands on any schema
 * shape a consumer builds (the field handlers' tests alter hand-built
 * schemas) and declares the roster's invalidation domain the same way
 * every other contributor does.
 *
 * A schema references materials when it carries a layout (variants are
 * worn as class and axis attributes there, stencils place there) or a
 * field whose schema carries a style guide (its values are style maps or
 * declarations that reference named values). Any other schema (a media
 * item, a term) says nothing about materials, so it carries none.
 */
final class MaterialsSchemaHooks {

  /**
   * The field-schema key that marks a field whose values reference materials.
   */
  public const STYLE_GUIDE = 'style_guide';

  public function __construct(
    protected MaterialsSchema $materialsSchema,
  ) {}

  /**
   * Implements hook_daedalus_blueprint_schema_alter().
   */
  #[Hook('daedalus_blueprint_schema_alter')]
  public function schemaAlter(array &$schema, array &$context): void {
    if (!self::referencesMaterials($schema)) {
      return;
    }
    $schema['materials'] = $this->materialsSchema->fragment();
    $context['cache_tags'] = array_merge($context['cache_tags'] ?? [], $this->materialsSchema->cacheTags());
  }

  /**
   * Whether a built schema's values can reference materials.
   */
  public static function referencesMaterials(array $schema): bool {
    if (isset($schema['layout_builder__layout'])) {
      return TRUE;
    }
    foreach ($schema['fields'] ?? [] as $field_schema) {
      if (is_array($field_schema) && isset($field_schema[self::STYLE_GUIDE])) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
