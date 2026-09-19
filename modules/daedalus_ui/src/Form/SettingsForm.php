<?php

namespace Drupal\daedalus_ui\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Daedalus settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'daedalus_ui_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['daedalus_ui.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $colors = $this->config('daedalus_ui.settings')->get('colors');

    $form['daedalus_ui'] = [
      '#type' => 'details',
      '#title' => $this->t('Daedalus UI'),
      '#open' => TRUE,
    ];
    $form['daedalus_ui']['colors'] = [
      '#tree' => TRUE,
    ];
    $form['daedalus_ui']['colors']['main'] = [
      '#type' => 'color',
      '#title' => $this->t('Main color'),
      '#default_value' => $colors['main'] ?? '#4b9ae4',
      '#description' => $this->t('This is used for main items like toolbars, sidebars, top bars, etc.'),
      '#attributes' => [
        'css-rule' => '--daedalus-ui-main-color',
      ],
    ];
    $form['daedalus_ui']['colors']['secondary'] = [
      '#type' => 'color',
      '#title' => $this->t('Secondary color'),
      '#default_value' => $colors['secondary'] ?? '#4b9ae4',
      '#description' => $this->t('This is used for top bar accents like focus and active states.'),
      '#attributes' => [
        'css-rule' => '--daedalus-ui-secondary-color',
      ],
    ];
    $form['daedalus_ui']['colors']['highlight'] = [
      '#type' => 'color',
      '#title' => $this->t('Highlight color'),
      '#default_value' => $colors['highlight'] ?? '#4b9ae4',
      '#description' => $this->t('This is used for calling attention to something.'),
      '#attributes' => [
        'css-rule' => '--daedalus-ui-highlight-color',
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('daedalus_ui.settings')
      ->set('colors', $form_state->getValue('colors'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
