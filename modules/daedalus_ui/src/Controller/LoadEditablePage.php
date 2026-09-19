<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\MainEntityWrapperTrait;
use Drupal\daedalus_ui\Event\LoadEditablePageEvent;
use Drupal\daedalus_tempstore\ParamConverter\EntityConverter;
use Drupal\daedalus_ui\LoadEditablePageResponseTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Load editable page.
 *
 * An AJAX callback that rebuilds the page with the attributes and markup needed
 * to make the page an editing UI.
 */
final class LoadEditablePage extends ControllerBase {

  use LoadEditablePageResponseTrait;
  use MainEntityWrapperTrait;

  public function __construct(
    protected readonly DaedalusUi $daedalusUi,
    protected readonly EntityConverter $entityConverter,
    protected readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('daedalus_ui.ui'),
      $container->get('daedalus_tempstore.param_converter.entity'),
      $container->get('event_dispatcher'),
    );
  }

  /**
   * Load editable page.
   *
   * When the user initially views a node there are no UI attributes in the
   * markup. When the user enables Edit Mode an AJAX call to this controller
   * is made to reload the page with UI attributes in the markup. Tool plugins
   * should check $this->daedalusUi->getMode() before adding markup
   * to the page.
   */
  public function __invoke(EntityInterface $entity, string $view_mode) {
    $mode = $this->daedalusUi->getMode();
    $build = $this->getEmptyContent($entity, $view_mode);

    if (!$this->daedalusUi->isValidViewMode($entity, $view_mode)) {
      $build['#markup'] = $this->t('Daedalus Edit: Invalid view mode for @label', ['@label' => $entity->label()]);
    }

    if (empty($build['#markup']) && !$entity->access('update')) {
      $build['#markup'] = $this->t("You don't have permission to edit this.");
    }

    // Lock editing the entity down to one workspace at a time.
    // @see daedalus_ui_entity_view_alter for the non-AJAX version.
    if (empty($build['#markup'])) {
      $build['#markup'] = $this->ensureWorkspace($entity);
    }

    if (empty($build['#markup'])) {
      if ($this->moduleHandler()->moduleExists('daedalus_tempstore') && $mode === 'edit') {
        $entity = $this->entityConverter->loadEntityFromTempstore($entity);
      }
      $build = $this->entityTypeManager()->getViewBuilder($entity->getEntityTypeId())->view($entity, $view_mode);
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
