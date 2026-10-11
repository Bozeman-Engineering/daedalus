<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint_api\Render\CanvasBuilderInterface;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\Event\LoadEditablePageEvent;
use Drupal\daedalus_ui\LoadEditablePageResponseTrait;
use Drupal\daedalus_ui\MainEntityWrapperTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Load editable page.
 *
 * An AJAX callback that rebuilds a host's canvas with the attributes and
 * markup needed to make the page an editing UI. Any host: a content
 * entity (its entity view) or a Layout Builder display (its template
 * canvas), rendered through the canvas-builder socket.
 */
final class LoadEditablePage extends ControllerBase {

  use LoadEditablePageResponseTrait;
  use MainEntityWrapperTrait;

  public function __construct(
    protected readonly DaedalusUi $daedalusUi,
    protected readonly HostResolver $hostResolver,
    protected readonly EntityStorageHandler $storageHandler,
    protected readonly CanvasBuilderInterface $canvasBuilder,
    protected readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('daedalus_ui.ui'),
      $container->get('daedalus_blueprint.host_resolver'),
      $container->get('daedalus_blueprint.storage_handler'),
      $container->get('daedalus_blueprint_api.canvas_builder'),
      $container->get('event_dispatcher'),
    );
  }

  /**
   * Load editable page.
   *
   * When the user initially views a page there are no UI attributes in the
   * markup. When the user enables Edit Mode an AJAX call to this controller
   * is made to reload the canvas with UI attributes in the markup. The host
   * is loaded as its working copy (the staged state while a stage exists),
   * the same door the spine's endpoints load through.
   */
  public function __invoke(string $entity_type, EntityInterface $entity, string $view_mode) {
    if (!$this->hostResolver->hosts($entity)) {
      throw new NotFoundHttpException(sprintf('Nothing hosts %s %s.', $entity_type, $entity->id()));
    }
    $mode = $this->daedalusUi->getMode();
    $host = $mode === 'edit'
      ? ($this->storageHandler->load($entity_type, $entity->id()) ?? $this->hostResolver->fromEntity($entity))
      : $this->hostResolver->fromEntity($entity);
    $entity = $host->entity();
    $build = $this->getEmptyContent($entity, $view_mode);

    if (!$this->daedalusUi->isValidViewMode($host, $view_mode)) {
      $build['#markup'] = $this->t('Daedalus Edit: Invalid view mode for @label', ['@label' => $host->label()]);
    }

    if (empty($build['#markup']) && !$entity->access('update')) {
      $build['#markup'] = $this->t("You don't have permission to edit this.");
    }

    // Lock editing the entity down to one workspace at a time; a host that
    // is not workspace aware (config) is exempt.
    // @see daedalus_ui_entity_view_alter for the non-AJAX version.
    if (empty($build['#markup']) && $host->persistence()->workspaceAware) {
      $build['#markup'] = $this->ensureWorkspace($entity);
    }

    if (empty($build['#markup'])) {
      $build = $this->canvasBuilder->build($host, $view_mode);
    }
    else {
      $this->mainEntityWrapper($entity, $view_mode, $build);
      $build['#cache']['max-age'] = 0;
    }

    $response = $this->getAjaxReplaceResponse($entity, $view_mode, $build);

    $this->eventDispatcher->dispatch(
      new LoadEditablePageEvent($response, $entity, $view_mode, $mode)
    );

    return $response;
  }

}
