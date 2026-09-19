<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\Form;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures which config entity types blueprint operations may create.
 *
 * Only config entity types that define no admin permission of their own are
 * listed: those are the types whose create access is neutral for everyone, so
 * an explicit opt-in is the only way blueprint creation can reach them. Types
 * with an admin permission (image styles, filter formats) already gate their
 * own creation and are deliberately omitted, so allow-listing can never weaken
 * an existing permission.
 *
 * @see daedalus_blueprint_config_entity_create_access()
 */
class ConfigCreateAccessForm extends ConfigFormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'daedalus_blueprint_config_create_access';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['daedalus_blueprint_config.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $allowed = $this->config('daedalus_blueprint_config.settings')->get('allowed_create_types') ?? [];

    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $type_id => $definition) {
      if (!$definition->entityClassImplements(ConfigEntityInterface::class)) {
        continue;
      }
      // Types with their own admin permission already gate creation; opting
      // them in here would override that gate, so leave them off the list.
      if ($definition->getAdminPermission()) {
        continue;
      }
      $options[$type_id] = $this->t('@label (@id)', [
        '@label' => $definition->getLabel(),
        '@id' => $type_id,
      ]);
    }
    asort($options);

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Check the config entity types that blueprint operations (including AI agents) may create. Creation is denied for everything else. The acting user must also hold the <em>Create config entities via Daedalus Blueprint</em> permission. Config entity types that define their own admin permission are not listed — they are already gated by that permission.') . '</p>',
    ];

    $form['allowed_create_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Creatable config entity types'),
      '#options' => $options,
      '#default_value' => array_values(array_intersect($allowed, array_keys($options))),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_values(array_filter($form_state->getValue('allowed_create_types')));

    $this->config('daedalus_blueprint_config.settings')
      ->set('allowed_create_types', $selected)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
