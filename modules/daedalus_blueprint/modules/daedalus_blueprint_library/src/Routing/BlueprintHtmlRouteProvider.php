<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Routing;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\daedalus_blueprint_library\Access\BlueprintTypeAccess;
use Drupal\daedalus_blueprint_library\BlueprintPermissions;
use Symfony\Component\Routing\Route;

/**
 * The blueprint entity's routes, with a listing gated per type.
 *
 * Core's provider builds a collection route only behind an entity type's
 * admin permission, and the blueprint has none: every grant is per type
 * (BlueprintPermissions), so the listing opens to an actor who may
 * create, edit or delete blueprints of ANY type (BlueprintTypeAccess) and
 * its rows and operations judge each blueprint by its own type through
 * the access handler. The add page, the per-type add form, the canvas
 * (canonical), the details and delete forms are core's.
 */
class BlueprintHtmlRouteProvider extends AdminHtmlRouteProvider {

  /**
   * {@inheritdoc}
   */
  protected function getCollectionRoute(EntityTypeInterface $entity_type) {
    /** @var \Drupal\Core\StringTranslation\TranslatableMarkup $label */
    $label = $entity_type->getCollectionLabel();
    $route = new Route($entity_type->getLinkTemplate('collection'));
    $route
      ->addDefaults([
        '_entity_list' => $entity_type->id(),
        '_title' => $label->getUntranslatedString(),
        '_title_arguments' => $label->getArguments(),
        '_title_context' => $label->getOption('context'),
      ])
      ->setRequirement(BlueprintTypeAccess::REQUIREMENT, implode(',', BlueprintPermissions::OPERATIONS))
      ->setOption('_admin_route', TRUE);
    return $route;
  }

}
