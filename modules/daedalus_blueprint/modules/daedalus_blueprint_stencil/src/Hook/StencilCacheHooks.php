<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Hook;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsChangeSet;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint_stencil\StencilDiscovery;
use Drupal\daedalus_blueprint_stencil\StencilInterface;

/**
 * Keeps stencil discovery and the materials spine in step with stencil CRUD.
 */
final class StencilCacheHooks {

  public function __construct(
    protected MaterialsRosterBuilder $materialsRosterBuilder,
    protected MaterialsChangeSet $materialsChangeSet,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert/update/delete() for stencil.
   *
   * CACHE_TAG is the single "the stencil vocabulary changed" signal:
   * discovery's parsed set and every downstream consumer (the palette's
   * render cache keys on it) refresh through this one tag, so content CRUD
   * invalidates it here instead of consumers having to know that content
   * stencils also carry an entity list tag. The materials roster memoizes
   * discovery's set per request, so the same write drops that memo: a
   * gesture that saves a stencil and references it in one request never
   * sees the pre-write roster. The write is recorded on the request's
   * change set by id (the stencil's machine name IS its roster id), so the
   * response that ends this request carries the stencil as an upsert or a
   * remove, whichever lane saved it (the save_material op, the details and
   * delete routes, the admin form, an AI tool).
   */
  #[Hook('stencil_insert')]
  #[Hook('stencil_update')]
  #[Hook('stencil_delete')]
  public function invalidateDiscovery(StencilInterface $stencil): void {
    Cache::invalidateTags([StencilDiscovery::CACHE_TAG]);
    $this->materialsRosterBuilder->reset();
    $this->materialsChangeSet->touch(MaterialKind::Stencil, $stencil->getMachineName());
  }

}
