<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Controller;

use Drupal\Core\Form\FormState;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\daedalus_edit\DaedalusEditFormTrait;
use Drupal\daedalus_edit\Plugin\Sidebar\EntityFormSidebar;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Controller\ArgumentResolverInterface;

/**
 * Builds the entity form for the given entity.
 */
final class MultipleEntityFormController extends ControllerBase {

  use DaedalusEditFormTrait;
  use StringTranslationTrait;

  public function __construct(
    protected Request $request,
    protected EventDispatcherInterface $dispatcher,
    protected ArgumentResolverInterface $argumentResolver,
    protected EntityTempstoreRepository $tempstoreRepository,
    protected EntityDisplayRepositoryInterface $entityDisplayRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('event_dispatcher'),
      $container->get('http_kernel.controller.argument_resolver'),
      $container->get('daedalus_tempstore.entity_tempstore_repository'),
      $container->get('entity_display.repository'),
    );
  }

  /**
   * Builds the response.
   */
  public function entityForm(Request $request, $entity_type, EntityInterface $entity) {
    // Build an entity form.
    $form_object = $this->entityTypeManager()->getFormObject($entity->getEntityTypeId(), 'default');
    $entity = $this->tempstoreRepository->get($entity);
    $form_object->setEntity($entity);

    // This is an excerpt from FormController.
    // Add the form and form_state to trick the getArguments method of the
    // controller resolver.
    $form_state = (new FormState())->setFormState(['daedalus_edit_form' => TRUE]);
    $request->attributes->set('form', []);
    $request->attributes->set('form_state', $form_state);
    $args = $this->argumentResolver->getArguments($request, [$form_object, 'buildForm']);
    $request->attributes->remove('form');
    $request->attributes->remove('form_state');

    // Remove $form and $form_state from the arguments, and re-index them.
    unset($args[0], $args[1]);
    $form_state->addBuildInfo('args', array_values($args));

    // @see InlineEntityFormAlter for modifications.
    $unwrapped_form = $this->formBuilder()->buildForm($form_object, $form_state);
    $form = $this->wrapForm($unwrapped_form, $entity);

    if (empty($request->request->get('ajaxReturnForm'))) {
      // The form is being built because the AJAX submit button has been
      // clicked by JS.
      // @see entity-form.js ajaxReturnForm
      return $form;
    }

    // The entity-form panel's open contract: the wrapped form (see
    // DaedalusEditFormTrait::wrapForm) is appended into the EntityFormSidebar
    // plugin's form-cache mount, where it is cached for reuse. The panel is
    // guaranteed present whenever the form path runs: the plugin is bound to
    // Edit Mode and always applies. The client-side subject model (the
    // 'daedalus_edit_form' JS sidebar plugin) then makes this form the panel's
    // subject and opens the panel.
    $response = new AjaxResponse();
    $response->addCommand(new AppendCommand('#' . EntityFormSidebar::FORM_CACHE_ID, $form));

    return $response;
  }

}
