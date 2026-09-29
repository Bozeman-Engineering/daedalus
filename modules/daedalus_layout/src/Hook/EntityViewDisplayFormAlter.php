<?php

namespace Drupal\daedalus_layout\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Alters Entity View Display Form for Layout Builder preserved fields.
 */
class EntityViewDisplayFormAlter {

  use StringTranslationTrait;

  /**
   * Constructs a new EntityViewDisplayFormAlter.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   */
  public function __construct(
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for entity_view_display_edit_form.
   */
  #[Hook('form_entity_view_display_edit_form_alter')]
  public function formAlter(&$form, FormStateInterface $form_state, $form_id): void {
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $entity */
    $entity = $form_state->getFormObject()->getEntity();

    if (!$entity->isLayoutBuilderEnabled()) {
      return;
    }

    $entity_type_id = $entity->getTargetEntityTypeId();
    $bundle = $entity->getTargetBundle();

    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);

    $field_options = [];
    foreach ($field_definitions as $field_name => $field_definition) {
      if ($field_definition->isDisplayConfigurable('view') || $entity->getComponent($field_name)) {
        $field_options[$field_name] = $field_definition->getLabel();
      }
    }

    $preserved_fields = $entity->getThirdPartySetting('daedalus_layout', 'preserved_fields', []);

    $form['daedalus_layout'] = [
      '#type' => 'details',
      '#title' => $this->t('Preserved Fields'),
      '#description' => $this->t("By default, Layout Builder removes all entity fields from the render array since they are typically managed as field blocks within the layout. Select fields below to preserve them in the render array outside of Layout Builder sections. Preserved fields can be edited in place in Edit Mode and will appear in your theme's content variable, allowing you to position them independently from the Layout Builder UI for greater design flexibility."),
      '#open' => !empty($preserved_fields),
      '#tree' => TRUE,
      '#weight' => 99,
    ];

    $form['daedalus_layout']['preserved_fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Fields to preserve in render array'),
      '#options' => $field_options,
      '#default_value' => $preserved_fields,
    ];

    $form['#entity_builders'][] = [static::class, 'entityFormEntityBuild'];
  }

  /**
   * Entity builder for daedalus_layout preserved fields settings.
   */
  public static function entityFormEntityBuild($entity_type, $entity, &$form, FormStateInterface $form_state): void {
    $preserved_fields = $form_state->getValue(['daedalus_layout', 'preserved_fields']);
    $preserved_fields = array_filter($preserved_fields);

    if (!empty($preserved_fields)) {
      $entity->setThirdPartySetting('daedalus_layout', 'preserved_fields', array_values($preserved_fields));
    }
    else {
      $entity->unsetThirdPartySetting('daedalus_layout', 'preserved_fields');
    }
  }

}
