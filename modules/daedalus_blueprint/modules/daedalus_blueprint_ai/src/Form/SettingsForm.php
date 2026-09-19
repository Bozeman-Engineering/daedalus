<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Form;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for AI-visible entity types and bundles.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity type bundle info service.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'daedalus_blueprint_ai_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['daedalus_blueprint_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('daedalus_blueprint_ai.settings');
    $hidden_entity_types = $config->get('hidden_entity_types') ?? [];
    $hidden_bundles = $config->get('hidden_bundles') ?? [];

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Uncheck entity types or bundles to hide them from the AI bundle discovery list. Hidden items will not appear when the AI asks what entity types are available to create.') . '</p>',
    ];

    $form['entity_types'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    foreach ($this->entityTypeManager->getDefinitions() as $type_id => $definition) {
      if (!$definition->entityClassImplements(FieldableEntityInterface::class)) {
        continue;
      }

      $bundles = $this->bundleInfo->getBundleInfo($type_id);
      if (empty($bundles)) {
        continue;
      }

      $type_label = (string) $definition->getLabel();
      $type_hidden = in_array($type_id, $hidden_entity_types, TRUE);

      $form['entity_types'][$type_id] = [
        '#type' => 'details',
        '#title' => "{$type_label} ({$type_id})",
        '#open' => !$type_hidden || !empty($hidden_bundles[$type_id]),
      ];

      $form['entity_types'][$type_id]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show this entity type to AI'),
        '#default_value' => !$type_hidden,
      ];

      $bundle_options = [];
      foreach ($bundles as $bundle_id => $bundle_info) {
        $bundle_options[$bundle_id] = (string) ($bundle_info['label'] ?? $bundle_id);
      }

      $hidden_for_type = $hidden_bundles[$type_id] ?? [];
      $visible_bundles = array_diff(array_keys($bundle_options), $hidden_for_type);

      $form['entity_types'][$type_id]['bundles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Visible bundles'),
        '#options' => $bundle_options,
        '#default_value' => array_combine($visible_bundles, $visible_bundles),
        '#states' => [
          'visible' => [
            ':input[name="entity_types[' . $type_id . '][enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entity_types = $form_state->getValue('entity_types') ?? [];

    $hidden_entity_types = [];
    $hidden_bundles = [];

    foreach ($entity_types as $type_id => $type_values) {
      if (empty($type_values['enabled'])) {
        $hidden_entity_types[] = $type_id;
        continue;
      }

      $bundles = $type_values['bundles'] ?? [];
      $hidden_for_type = [];
      foreach ($bundles as $bundle_id => $value) {
        if (empty($value)) {
          $hidden_for_type[] = $bundle_id;
        }
      }
      if ($hidden_for_type) {
        $hidden_bundles[$type_id] = $hidden_for_type;
      }
    }

    $this->config('daedalus_blueprint_ai.settings')
      ->set('hidden_entity_types', $hidden_entity_types)
      ->set('hidden_bundles', $hidden_bundles)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
