<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\daedalus_edit\Form\PanelUpdateBlockForm;
use Drupal\daedalus_edit\Plugin\Sidebar\EntityFormSidebar;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds the Layout Builder block form for the given placement.
 *
 * The block-form endpoint kind of the form path; the entity form endpoint
 * is MultipleEntityFormController. Which kind a leaf fetches is declared
 * by its attribution (see BlockFormEndpoint); entity-form.js routes here
 * for leaves whose placement declares the block-form kind.
 *
 * The route carries placement identity only (storage + component uuid):
 * the section delta and region are derived from the storage, so the URL
 * cannot disagree with where the component actually lives. For nested
 * placements daedalus_layout's NestedRouteEnhancer has already scoped the storage
 * before this controller runs, so the uuid resolves within the scoped
 * sections at its local delta.
 *
 * Registered via DaedalusLayoutRouteSubscriber only when daedalus_layout is available.
 */
final class BlockFormController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function blockForm(Request $request, string $section_storage_type, SectionStorageInterface $section_storage, string $uuid) {
    $delta = NULL;
    $region = NULL;
    foreach ($section_storage->getSections() as $section_delta => $section) {
      $component = $section->getComponents()[$uuid] ?? NULL;
      if ($component !== NULL) {
        $delta = $section_delta;
        $region = $component->getRegion();
        break;
      }
    }
    if ($delta === NULL) {
      throw new NotFoundHttpException(sprintf('Daedalus Edit: no component "%s" in section storage "%s".', $uuid, $section_storage->getStorageId()));
    }

    $form = $this->formBuilder()->getForm(PanelUpdateBlockForm::class, $section_storage, $delta, $region, $uuid);

    if (empty($request->request->get('ajaxReturnForm'))) {
      // The form is being submitted (AJAX submits re-enter this route and
      // resolve through the form builder above) or rendered as a page.
      return $form;
    }

    // The entity-form panel's open contract, shared with the entity form
    // endpoint: the wrapped form (see DaedalusEditFormTrait::wrapForm, applied
    // by PanelUpdateBlockForm::wrapFormAfterBuild) is appended into
    // the EntityFormSidebar plugin's form-cache mount, where it is cached
    // for reuse. The client-side subject model then makes this form the
    // panel's subject and opens the panel.
    $response = new AjaxResponse();
    $response->addCommand(new AppendCommand('#' . EntityFormSidebar::FORM_CACHE_ID, $form));

    return $response;
  }

}
