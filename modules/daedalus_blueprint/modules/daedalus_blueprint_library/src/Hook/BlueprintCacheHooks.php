<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Hook;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint_library\BlueprintDiscovery;
use Drupal\daedalus_blueprint_library\BlueprintInterface;

/**
 * Keeps discovery and the materials spine in step with blueprint CRUD.
 */
final class BlueprintCacheHooks {

  public function __construct(
    protected MaterialsRosterBuilder $materialsRosterBuilder,
    protected MaterialsChangeSet $materialsChangeSet,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert/update/delete() for blueprint.
   *
   * CACHE_TAG is the single "the blueprint vocabulary changed" signal:
   * discovery's parsed set (a content blueprint's document is built into it
   * at rebuild, so a commit of the blueprint's canvas is a vocabulary
   * change too) and every downstream consumer (the palette's render cache
   * keys on it) refresh through this one tag, so content CRUD invalidates
   * it here instead of consumers having to know that content blueprints
   * also carry an entity list tag. The materials roster memoizes
   * discovery's set per request, so the same write drops that memo: a
   * gesture that saves a blueprint and references it in one request never
   * sees the pre-write roster. The write is recorded on the request's
   * change set by id (the blueprint's machine name IS its roster id), so
   * the response that ends this request carries the blueprint as an upsert
   * or a remove, whichever lane saved it (the save_material op, the
   * details and delete routes, the admin form, a canvas commit, an AI
   * tool).
   */
  #[Hook('blueprint_insert')]
  #[Hook('blueprint_update')]
  #[Hook('blueprint_delete')]
  public function invalidateDiscovery(BlueprintInterface $blueprint): void {
    Cache::invalidateTags([BlueprintDiscovery::CACHE_TAG]);
    $this->materialsRosterBuilder->reset();
    $this->materialsChangeSet->touch(MaterialKind::Blueprint, $blueprint->getMachineName());
  }

}
