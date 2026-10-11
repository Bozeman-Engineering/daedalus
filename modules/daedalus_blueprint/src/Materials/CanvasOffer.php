<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;

/**
 * Whether a host offers a material at a scope: the one "offered here" answer.
 *
 * Every offering surface asks the same question with its own scope, and
 * scopes never mix on a surface. The Add panel asks for BLOCK scope on a
 * canvas ("what can I place here"): a blueprint that is a container placed
 * into a page. The page library (the chooser in front of an add form, its
 * preview, a bundle's schema read) asks for PAGE scope for a bundle's host
 * ("what can this page start as"): a blueprint that is a whole page's
 * document. At either scope the blueprint must have a root (a blueprint
 * composed from scratch and not yet holding one places or starts nothing,
 * and the op door refuses it) and fit the host (BlueprintRequirements: the
 * subject can place every field, the site has every plugin, block type and
 * layout, the allow-list admits the subject). At page scope the host's
 * shape adds one condition (fillsSlots()): a page of a locked bundle owns
 * only the slots its template places, so it starts as a page blueprint
 * only when every root of that blueprint is a fill of one of those slots;
 * a whole page document is offered to the pages that own one whole. Every
 * other kind is offered on every host at every scope: tokens, swatches,
 * variants and animations ride whole, and their applicability is judged
 * where they apply.
 *
 * One predicate, both halves of the Add panel's delivery contract: the
 * page-load payload keeps only what the canvas offers at block scope
 * (MaterialsDelivery::payload()), and the mid-session delta lists a
 * touched blueprint the canvas does not offer as a remove
 * (MaterialsChangeSet::delta()), so a filter the boot applied is never
 * undone by the next upsert. The model's surfaces ask the same question:
 * a bundle's schema read lists the block blueprints its host is offered
 * under materials.blueprints and the page blueprints under starts
 * (MaterialsSchema); the always-on prefix, which has no bundle
 * dimension, prints the site's block blueprints unjudged, and the
 * model's doors refuse at placement (BLUEPRINT_DOES_NOT_FIT,
 * BLUEPRINT_EMPTY).
 */
final class CanvasOffer {

  public function __construct(
    private readonly BlueprintRequirements $requirements,
    private readonly HostResolver $hostResolver,
    private readonly DaedalusLayoutAdapter $layoutAdapter,
  ) {}

  /**
   * Whether the host offers the record at the scope asked for.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord $record
   *   A roster record of any kind.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the surface serves: the canvas for block scope, a bundle's
   *   host (or a page's) for page scope.
   * @param string $scope
   *   The blueprint scope the surface offers: MaterialRecord::SCOPE_BLOCK
   *   or MaterialRecord::SCOPE_PAGE.
   *
   * @throws \InvalidArgumentException
   *   When the scope is not one a blueprint can have.
   */
  public function offered(MaterialRecord $record, HostInterface $host, string $scope): bool {
    if (!in_array($scope, MaterialRecord::SCOPES, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown blueprint scope "%s" asked of the offer; one of %s.', $scope, implode(', ', MaterialRecord::SCOPES)));
    }
    if ($record->kind !== MaterialKind::Blueprint) {
      return TRUE;
    }
    return $record->blueprintScope === $scope
      && ($record->tree ?? []) !== []
      && $this->requirements->fits($record->requires, $host)
      && ($scope !== MaterialRecord::SCOPE_PAGE || $this->fillsSlots($record, $host));
  }

  /**
   * Whether a page blueprint's roots all fill slots the host's template places.
   *
   * A host that owns the whole document it renders (no foreign owner,
   * HostResolver::foreignOwner()) takes any page document, so every
   * record passes. A host rendered inside a foreign document (a page of
   * a locked bundle) owns only its slot sections there, so a page
   * blueprint fits it only when each of its roots carries `slot` (the
   * tree's spelling of a slot section) naming a slot the owner's
   * document declares (DaedalusLayoutAdapter::declaredSlots(): the slot
   * blocks the template places). A fill of a slot the template does not
   * place would render nowhere, and a root without a slot would need the
   * page's own root flow, which the lock keeps as the template's.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord $record
   *   A page blueprint's roster record.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host a start would land on: a bundle's unsaved host, or a page's.
   *
   * @return bool
   *   TRUE when the record may start a page of the host.
   */
  public function fillsSlots(MaterialRecord $record, HostInterface $host): bool {
    if ($this->hostResolver->foreignOwner($host) === NULL) {
      return TRUE;
    }
    $declared = $this->declaredSlots($host);
    foreach ($record->tree ?? [] as $root) {
      $slot = is_array($root) ? ($root['slot'] ?? NULL) : NULL;
      if (!is_string($slot) || !in_array($slot, $declared, TRUE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The slots a host may fill: those the foreign document around it declares.
   *
   * The slot blocks the owner's document places
   * (DaedalusLayoutAdapter::declaredSlots() over the owner's own
   * sections); [] for a host that owns the whole document it renders,
   * which fills no slot of anything.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   *
   * @return string[]
   *   The slot ids in the owner's document order.
   */
  public function declaredSlots(HostInterface $host): array {
    $owner = $this->hostResolver->foreignOwner($host);
    return $owner === NULL ? [] : $this->layoutAdapter->declaredSlots($owner->ownSections());
  }

  /**
   * What the judgement depends on beyond the roster, for a cached render.
   *
   * The scope and the tree are the roster's own facts; the fit adds the
   * site lists it reads. The slot condition reads the host's display,
   * which every surface that renders a page-scope offer already depends
   * on (the chooser's form, the preview's response, the schema's cache).
   */
  public function cacheMetadata(): CacheableMetadata {
    return $this->requirements->cacheMetadata();
  }

}
