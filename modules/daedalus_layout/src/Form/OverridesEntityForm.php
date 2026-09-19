<?php

namespace Drupal\daedalus_layout\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\layout_builder\Form\OverridesEntityForm as LayoutBuilderOverridesEntityForm;

/**
 * Trims the Layout Builder override and defaults forms.
 */
class OverridesEntityForm implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    protected ModuleHandlerInterface $moduleHandler,
    protected ClassResolverInterface $classResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('class_resolver'),
    );
  }

  /**
   * Hides the revision information and the Layout Builder message.
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
    }
  }

}
