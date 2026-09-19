<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_stylish\CompiledStylesheetBase;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Replays a compiled stylesheet's save-chokepoint reactions on publish.
 *
 * Publishing a workspace makes staged revisions the default WITHOUT firing
 * per-entity update hooks, so everything an entity save would have done
 * for a compiled stylesheet's material must be replayed here, once per
 * material family (swatches, animations, variants, skins). The shape is
 * the same for all four:
 * - A material edited (or minted) inside a workspace is a staged revision:
 *   its saves fired the ordinary entity hooks, which recompiled against
 *   the LIVE revisions (no change), and the workspace preview read the
 *   staged CSS via the inline fallback (each stylesheet's
 *   workspaceInlineCss()). Publish is the moment the staged CSS must land
 *   in the shared compiled file: when the publish payload names the
 *   family's entity type, the identity map (resetRepository()) and the
 *   materials roster reset first, so the compile reads the post-publish
 *   live set, then the stylesheet compiles.
 * - A SOURCE entity that gained or lost references inside a workspace
 *   carried them in staged revisions the usage tables deliberately ignore
 *   (default revisions only). Publish promotes them, so the published
 *   sources resync (resyncUsage()) on every publish, whether or not a
 *   material itself was staged: the usage counts, and above all the
 *   delete batches' work lists, must see the new references.
 *
 * The event class is referenced by name: with the workspaces module absent
 * the event never fires and nothing here loads.
 */
abstract class StylesheetWorkspaceSubscriberBase implements EventSubscriberInterface {

  public function __construct(
    protected CompiledStylesheetBase $stylesheet,
    protected MaterialsRosterBuilder $rosterBuilder,
  ) {}

  /**
   * The material's entity type id in the publish payload.
   */
  abstract protected function entityTypeId(): string;

  /**
   * Forgets the material's request-lifetime identity map, if it keeps one.
   *
   * Publish changed the live revisions under the memo without a per-entity
   * hook: a compile reading references through it would otherwise still
   * see the pre-publish live set. Skins keep no identity map.
   */
  protected function resetRepository(): void {}

  /**
   * Resyncs the material's usage rows for the just-published sources.
   *
   * @param array $published
   *   Entity ids keyed by revision id, keyed by entity type id (the
   *   WorkspacePostPublishEvent payload shape). Materials without a usage
   *   table (skins) do nothing.
   */
  protected function resyncUsage(array $published): void {}

  /**
   * Recompiles and resyncs after a workspace publish.
   */
  public function onPostPublish(object $event): void {
    if (!method_exists($event, 'getPublishedRevisionIds')) {
      return;
    }
    $published = $event->getPublishedRevisionIds();
    if (!empty($published[$this->entityTypeId()])) {
      $this->resetRepository();
      $this->rosterBuilder->reset();
      $this->stylesheet->compile();
    }
    $this->resyncUsage($published);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'Drupal\workspaces\Event\WorkspacePostPublishEvent' => 'onPostPublish',
    ];
  }

}
