<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_blueprint\BundleListBuilder;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for AI-visible entity types and bundles, and the budget.
 *
 * The form lists exactly the catalog BundleListBuilder::getCatalog() offers
 * the AI, so a type the catalog never carries (an internal entity type)
 * never shows up here as something to hide.
 *
 * It also edits the AI context budget, which lives on the spine's
 * settings object (daedalus_blueprint.settings: MaterialsBudget::KEY_BUDGET
 * and ::KEY_RATIO), not on this module's: the guard at the materials
 * providers reads the spine, and this form is its one consumer with a
 * field. The two fields save through #config_target, so the fully
 * validatable object is validated whole (the Range constraints on both
 * keys refuse a zero) before anything is written.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected BundleListBuilder $bundleListBuilder,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('daedalus_blueprint.bundle_list_builder'),
    );
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
    return ['daedalus_blueprint_ai.settings', MaterialsBudget::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('daedalus_blueprint_ai.settings');
    $hidden_entity_types = $config->get('hidden_entity_types') ?? [];
    $hidden_bundles = $config->get('hidden_bundles') ?? [];

    $form['ai_context'] = [
      '#type' => 'details',
      '#title' => $this->t('AI context budget'),
      '#description' => $this->t("What the site shares with the AI as materials (the design system brief and the schema's materials fragment) is measured locally in characters and shown as an estimate in tokens; past the budget, offering more to the AI is refused. What the AI holds, by material kind, is at <a href=':url'>the usage display</a>.", [':url' => MaterialsBudget::DIALOG_PATH]),
      '#open' => TRUE,
    ];
    $form['ai_context'][MaterialsBudget::KEY_BUDGET] = [
      '#type' => 'number',
      '#title' => $this->t('Budget (estimated tokens)'),
      '#description' => $this->t("The ceiling on what the site shares, for every model the site may use; a provider's per-model context window is the segment budget's concern, not this one's."),
      '#config_target' => MaterialsBudget::CONFIG_NAME . ':' . MaterialsBudget::KEY_BUDGET,
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];
    $form['ai_context'][MaterialsBudget::KEY_RATIO] = [
      '#type' => 'number',
      '#title' => $this->t('Characters per token'),
      '#description' => $this->t('The ratio the estimate divides by. Tune it once against the billed prompt sizes in the usage ledger; 4 is the usual figure for English prose and code.'),
      '#config_target' => MaterialsBudget::CONFIG_NAME . ':' . MaterialsBudget::KEY_RATIO,
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Uncheck entity types or bundles to hide them from the AI bundle discovery list. Hidden items will not appear when the AI asks what entity types are available to create.') . '</p>',
    ];

    $form['entity_types'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    foreach ($this->bundleListBuilder->getCatalog() as $type_id => $type_data) {
      $type_hidden = in_array($type_id, $hidden_entity_types, TRUE);

      $form['entity_types'][$type_id] = [
        '#type' => 'details',
        '#title' => "{$type_data['label']} ({$type_id})",
        '#open' => !$type_hidden || !empty($hidden_bundles[$type_id]),
      ];

      $form['entity_types'][$type_id]['enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Show this entity type to AI'),
        '#default_value' => !$type_hidden,
      ];

      $bundle_options = [];
      foreach ($type_data['bundles'] as $bundle_id => $bundle_data) {
        $bundle_options[$bundle_id] = $bundle_data['label'];
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
