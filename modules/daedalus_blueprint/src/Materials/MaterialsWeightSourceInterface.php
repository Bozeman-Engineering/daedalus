<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * A renderer that weighs what it shows the AI, attributed by record and kind.
 *
 * Registered with the daedalus_blueprint.materials_weight_source service
 * tag and collected by MaterialsBudget. A source is one of the renderers
 * that already produce every material line the model reads (the schema's
 * materials fragment, MaterialsSchema; the cached design system brief,
 * daedalus_blueprint_ai's DialectCatalogPrompt): it attributes its own
 * output, line by line or key by key, to the record that put it there,
 * to a kind's shared (what stands over several records of one kind and
 * none owns) or to the system (the grammar and guidance no material
 * toggle shrinks), and answers the characters. Never a second renderer and never a
 * re-render per material: the weights are a by-product of the one
 * render the model is served, so the gauge and the prompt cannot drift.
 *
 * Characters, not tokens: the budget estimates tokens at the site's
 * fixed characters-per-token ratio and labels the result an estimate.
 * No provider call, no tokenizer.
 */
interface MaterialsWeightSourceInterface {

  /**
   * The characters this source contributes: by record, a kind's shared, the system.
   */
  public function weights(): MaterialsWeights;

  /**
   * What weights() derives from, answered without rendering.
   *
   * The roster's metadata at least (MaterialsRosterBuilder::cacheMetadata()
   * answers it before any roster is built): the budget caches the summed
   * weights under the merged tags and varies the entry by the merged
   * contexts, so a cached reading costs no render.
   */
  public function cacheMetadata(): CacheableMetadata;

}
