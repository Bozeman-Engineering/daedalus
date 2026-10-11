<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_blueprint\Host\PageHosts;
use Drupal\daedalus_blueprint\Host\PageStart;
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
 * worn as class and axis attributes there, blueprints place there) or a
 * field whose schema carries a style guide (its values are style maps or
 * declarations that reference named values). Any other schema (a media
 * item, a term) says nothing about materials, so it carries none.
 *
 * What the model is shown follows scope and bundle: the alter context
 * names the bundle the schema is read for, and its unsaved host
 * (PageHosts::bundleHost()) is the host every offer is judged against.
 * The fragment's blueprints are the favorites among the block blueprints
 * that host is offered (the Add panel's answer for the same bundle), its
 * groups the index of all of them, and a PAGE bundle's schema gains
 * `starts`: the page blueprints a page of it may
 * start as (PageStart::starts(), the chooser's answer), the ids
 * page_create's `start` takes. A schema with no bundle host (a type
 * nothing hosts, a hand-built schema) keeps block scope unfiltered by fit
 * and no starts. Scopes never mix on a surface: a page blueprint is in
 * `starts` or nowhere.
 */
final class MaterialsSchemaHooks {

  /**
   * The field-schema key that marks a field whose values reference materials.
   */
  public const STYLE_GUIDE = 'style_guide';

  /**
   * The schema key carrying a page bundle's starts.
   */
  public const STARTS = 'starts';

  public function __construct(
    protected MaterialsSchema $materialsSchema,
    protected PageHosts $pageHosts,
    protected PageStart $pageStart,
  ) {}

  /**
   * Implements hook_daedalus_blueprint_schema_alter().
   */
  #[Hook('daedalus_blueprint_schema_alter')]
  public function schemaAlter(array &$schema, array &$context): void {
    if (!self::referencesMaterials($schema)) {
      return;
    }
    $host = $this->pageHosts->bundleHost($context['entity_type'], $context['bundle']);
    $schema['materials'] = $this->materialsSchema->fragment($host);
    // A start lands a page's first document, so only a bundle whose pages
    // carry one (PageStart::takesStart()) has starts: the whole page
    // blueprints on an overridable bundle, the fills of the template's
    // slots on a locked one (the offer's judgement). Grows-by-data: a
    // bundle offered no page blueprint carries no key, and page_create's
    // description names the two reserved starts.
    if ($host !== NULL && $this->pageStart->takesStart($host) && $this->pageHosts->isPage($host)) {
      $starts = $this->materialsSchema->starts($this->pageStart->starts($host));
      if ($starts !== []) {
        $schema[self::STARTS] = $starts;
      }
    }
    $context['cache_tags'] = array_merge($context['cache_tags'] ?? [], $this->materialsSchema->cacheTags());
  }

  /**
   * Whether a built schema's values can reference materials.
   */
  public static function referencesMaterials(array $schema): bool {
    return isset($schema['layout']) || isset($schema['styles'][self::STYLE_GUIDE]);
  }

}
