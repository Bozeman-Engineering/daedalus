<?php

namespace Drupal\daedalus_ui\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\daedalus_ui\DaedalusUi;
use Symfony\Component\HttpFoundation\Request;
use Drupal\daedalus_ui\MainEntityWrapperTrait;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\daedalus_ui\LoadEditablePageResponseTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\entity_workflow_content\Controller\WorkspaceSwitcherController;

/**
 * Workspace Switcher.
 *
 * Wraps the switcher form in data-daedalus-ui-entity-wrapper and
 * conditionally returns it as an AJAX response.
 */
class WorkspaceSwitcher extends WorkspaceSwitcherController {

  use LoadEditablePageResponseTrait;
  use MainEntityWrapperTrait;

  public function __construct(
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
    FormBuilderInterface $form_builder,
    private readonly DaedalusUi $daedalusUi,
  ) {
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_display.repository'),
      $container->get('form_builder'),
      $container->get('daedalus_ui.ui'),
    );
  }

  /**
   * Switcher Ajax.
   *
   * When a user enters edit mode they need to be in a workspace.
   * BeforeEntityWorkflowEnhancer and AfterEntityWorkflowEnhancer ties both
   * daedalus_ui.load_editable_page (ajax) and edit.node.canonical (full
   * response) routes into the workspace checker flow when edit mode is enabled.
   */
  public function switcherAjax(Request $request, ?EntityInterface $entity = NULL, ?string $view_mode = NULL) {
    $content = $this->getEmptyContent($entity, $view_mode);

    if (!$this->daedalusUi->isValidViewMode($entity, $view_mode)) {
      $content['#markup'] = $this->t('Daedalus Edit: Invalid view mode for @label', ['@label' => $entity->label()]);
    }

    if (!$entity->access('edit')) {
      $content['#markup'] = $this->t("You don't have permission to edit @label.", ['@label' => $entity->label()]);
    }
    else {
      $content = parent::switcher($request);
      $this->mainEntityWrapper($entity, $view_mode, $content);
    }

    return $this->getAjaxReplaceResponse($entity, $view_mode, $content);
  }

  /**
   * Switcher response.
   *
   * When a user enters edit mode they need to be in a workspace.
   * BeforeEntityWorkflowEnhancer and AfterEntityWorkflowEnhancer ties both the
   * daedalus_ui.load_editable_page (ajax) and edit.node.canonical (full
   * response) routes into the workspace checker flow when edit mode is enabled.
   */
  public function switcherResponse(Request $request, EntityInterface $entity) {
    $content = parent::switcher($request);
    // Let's derive the entity as this could be a Block config entity.
    $derived_entity = $this->daedalusUi->deriveHostFromRoute()?->entity();
    $this->mainEntityWrapper($derived_entity, 'full', $content);
    return $content;
  }

}
