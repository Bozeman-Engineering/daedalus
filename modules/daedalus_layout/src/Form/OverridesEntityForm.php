<?php

namespace Drupal\daedalus_layout\Form;

use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_ui\ModeState;
use Drupal\layout_builder\Form\OverridesEntityForm as LayoutBuilderOverridesEntityForm;
use Drupal\layout_builder\SectionStorageInterface;

/**
 * Trims the Layout Builder override and defaults forms.
 */
class OverridesEntityForm implements ContainerInjectionInterface {

  public function __construct(
    protected ModeState $modeState,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('daedalus_ui.mode_state'),
      $container->get('daedalus_blueprint.host_resolver'),
    );
  }

  /**
   * Hides the revision information, the message, and core's writes.
   *
   * Core's Save layout and Revert to defaults write the storage beside
   * the spine (the entity saved off the storage, the override field
   * emptied), so for a storage Daedalus hosts they leave the form in every
   * mode: a hosted document is committed through the top bar's Save
   * (daedalus_edit's tempstore routes, one host each). Discard changes
   * stays: it drops the staged copy through the tempstore repository
   * daedalus_tempstore provides, the top bar's own Discard. In Edit Mode
   * the actions leave the form altogether, the top bar owning them; a
   * storage with no host (core's navigation layout) keeps core's actions.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form ID.
   */
  public function formAlter(&$form, FormStateInterface $form_state, $form_id) {
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof LayoutBuilderOverridesEntityForm || $form_id === 'entity_view_display_layout_builder_form') {
      // Clean up the LB UI by hiding a few things.
      if (!empty($form['revision_information']['#access'])) {
        $form['revision_information']['#access'] = FALSE;
      }
      if (!empty($form['layout_builder_message'])) {
        unset($form['layout_builder_message']);
      }
      if ($this->hostResolver->fromSectionStorage($this->sectionStorage($form_state)) !== NULL) {
        $form['actions']['submit']['#access'] = FALSE;
        if (isset($form['actions']['revert'])) {
          $form['actions']['revert']['#access'] = FALSE;
        }
      }
      if ($this->modeState->getMode() === 'edit' && isset($form['actions'])) {
        $form['actions']['#access'] = FALSE;
      }
    }
  }

  /**
   * The storage the form was built on: core's first build argument.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\layout_builder\SectionStorageInterface
   *   The section storage.
   */
  protected function sectionStorage(FormStateInterface $form_state): SectionStorageInterface {
    $storage = $form_state->getBuildInfo()['args'][0] ?? NULL;
    if (!$storage instanceof SectionStorageInterface) {
      throw new \LogicException('Daedalus Layout: a Layout Builder entity form was built without its section storage.');
    }
    return $storage;
  }

}
