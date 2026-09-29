<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint\Materials\MaterialsDelivery;
use Drupal\daedalus_blueprint_api\Render\MutationRenderScope;
use Drupal\daedalus_ui\ElementModel\BlueprintElementAnnotator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the client blueprint mirror's drupalSettings envelope.
 *
 * The client app's blueprint mirror boots from drupalSettings instead of
 * fetching: the envelope rides the same render that produced the attributed
 * markup, so the mirror and the canvas are consistent by construction. The
 * envelope must be re-delivered from EVERY server path that changes what the
 * mirror should reflect, or the mirror (and the page elements panel projected
 * from it) goes stale until a full reload:
 * - the entity view build: boot and LoadEditablePage AJAX re-renders, where
 *   the settings merge refreshes it (EntityViewAlter),
 * - spine mutation responses (the endpoint's, and every form the edit mode
 *   hosts answering the same payload through daedalus_edit's FormCommit,
 *   the Configure dialogs included) carry the blueprint in the response BODY
 *   instead, replacing the mirror wholesale (the
 *   BlueprintElementAnnotations subscriber annotates that seam). Their
 *   whole-entity render runs through the same entity view build as boot,
 *   so attach() runs there too; under daedalus_blueprint_api's
 *   MutationRenderScope it delivers no blueprint envelope (the body's copy
 *   is the one the client applies, and a second copy in the response's
 *   settings command would re-boot the mirror mid-swap and, for an AI turn
 *   mutating another entity, clobber the route entity's mirror), and the
 *   subscriber strips the key from any settings command that still
 *   carries it.
 *
 * The site's MATERIALS roster (tokens, swatches, variants, stencils,
 * animations) rides
 * beside the mirror on the same seams, as `daedalusUi.materials`: the
 * page-load half of the materials delivery contract (daedalus_blueprint's
 * MaterialsDelivery), one record shape for every kind, so the client
 * materials store boots consistent with the canvas and every widget reads
 * its offers from it without a fetch. Mutation responses and gesture
 * routes carry the mid-session half, the `materials` {upsert, remove}
 * delta, in their bodies.
 *
 * Root identity travels beside the blueprint because the serializer does not
 * emit the root uuid and mutation responses carry only the blueprint.
 *
 * Both envelopes are JSON strings, not nested settings arrays: core's AJAX
 * settings merge is a jQuery deep $.extend that merges arrays element-wise,
 * so a refreshed blueprint object would mis-merge with the stale one
 * (removed sections linger). A string is replaced atomically, and the
 * client's change detection is string equality.
 *
 * The blueprint is annotated with the element model's decisions
 * (`_element`) and carries the spine's lean node tree (`_tree`, built by
 * daedalus_blueprint's NodeTreeBuilder over the annotated section nodes:
 * kind-discriminated container/region/block nodes with styles on the
 * node). Annotations ride only the DELIVERED blueprint; the stored data
 * model never carries them.
 */
class BlueprintMirror {

  public function __construct(
    protected readonly BlueprintBackendResolver $backendResolver,
    protected readonly BlueprintElementAnnotator $elementAnnotator,
    protected readonly MaterialsDelivery $materialsDelivery,
    protected readonly RequestStack $requestStack,
  ) {}

  /**
   * Attaches the entity's blueprint envelope and the materials roster.
   *
   * Inside a mutation render (the request attribute daedalus_blueprint_api's
   * SubtreeRenderer raises) the blueprint envelope is skipped: the response
   * body carries the blueprint, and the envelope's annotate and serialize
   * are the only work here worth a flag. The materials roster still rides,
   * since it heals material changes the response's delta does not record.
   *
   * @param array $build
   *   The render array whose attachments deliver the envelopes.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The blueprint root entity, in its staged state.
   * @param string $view_mode
   *   The view mode the client is displaying the entity with.
   */
  public function attach(array &$build, EntityInterface $entity, string $view_mode): void {
    if ($this->backendResolver->resolve($entity->getEntityTypeId()) === NULL) {
      return;
    }
    if (!MutationRenderScope::isActive($this->requestStack->getCurrentRequest())) {
      $build['#attached']['drupalSettings']['daedalusUi']['blueprint'] = $this->envelope($entity, $view_mode);
    }
    // The roster rides the same render, and the render carries the roster's
    // own invalidation (any material write) so a cached page never boots a
    // stale store.
    $build['#attached']['drupalSettings']['daedalusUi']['materials'] = $this->materialsDelivery->envelope();
    CacheableMetadata::createFromRenderArray($build)
      ->merge($this->materialsDelivery->cacheMetadata())
      ->applyTo($build);
  }

  /**
   * Builds the encoded envelope string.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The blueprint root entity, in its staged state.
   * @param string $view_mode
   *   The view mode the client is displaying the entity with.
   *
   * @return string|null
   *   The JSON envelope, or NULL when no blueprint backend answers for the
   *   entity type.
   */
  public function envelope(EntityInterface $entity, string $view_mode): ?string {
    $backend = $this->backendResolver->resolve($entity->getEntityTypeId());
    if ($backend === NULL) {
      return NULL;
    }
    return Json::encode([
      'entityType' => $entity->getEntityTypeId(),
      'entityId' => $entity->id(),
      'uuid' => $entity->uuid(),
      'label' => (string) $entity->label(),
      'langcode' => $entity->language()->getId(),
      'viewMode' => $view_mode,
      'blueprint' => $this->elementAnnotator->annotate($backend->serialize($entity), $entity, $view_mode),
    ]);
  }

}
