<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layers\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_stylish_layers\Layers;

/**
 * The cascade layer settings: whether the site's CSS is layered, and as what.
 *
 * One form over both keys of daedalus_stylish_layers.settings (enabled,
 * wrapper_layer), at /admin/config/daedalus/styling/cascade-layer under the
 * Daedalus config category's Styling sub-category, its one door (SETTINGS.md
 * at the daedalus repo root rosters it). The two keys reach the asset
 * pipeline only through Layers (the stamp hook_css_alter() writes), and the
 * name is validated here by the same Layers::isValidLayerName() the pipeline
 * reads stamps back with, so what the form stores is what the pipeline
 * emits: Layers' fallback to the default name is for a hand-edited config,
 * never a saved form.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The config object the form edits.
   */
  public const SETTINGS = 'daedalus_stylish_layers.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'daedalus_stylish_layers_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::SETTINGS);

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Wrap the site's CSS in a cascade layer"),
      '#description' => $this->t("Wrap every stylesheet the asset pipeline delivers (core, contrib, the theme) in one CSS cascade layer, so the unlayered styles Daedalus Stylish emits win the cascade structurally, whatever their specificity. Turn off to deliver the site's CSS unlayered; styling then competes on specificity alone. Installing the module is the switch; this is its runtime override."),
      '#default_value' => (bool) $config->get('enabled'),
    ];

    $form['wrapper_layer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Wrapper layer name'),
      '#description' => $this->t("The name of the cascade layer the site's CSS is wrapped in, a CSS identifier: letters, digits, hyphens and underscores, not starting with a digit, and no dots (it is a top-level layer, never a sub-layer). A stylesheet declaring its own layers nests inside it. Rename only if escaped CSS the pipeline never wraps declares a layer of the same name. The default is %default.", ['%default' => Layers::DEFAULT_WRAPPER_LAYER]),
      '#default_value' => (string) $config->get('wrapper_layer'),
      '#required' => TRUE,
      '#size' => 40,
      '#maxlength' => 128,
      '#field_prefix' => '@layer ',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // The one validator: the pipeline reads a stamp back through the same
    // test, so a name it refuses would never be emitted and the site would
    // silently wear the default instead.
    $name = trim((string) $form_state->getValue('wrapper_layer'));
    $form_state->setValue('wrapper_layer', $name);
    if (!Layers::isValidLayerName($name)) {
      $form_state->setErrorByName('wrapper_layer', $this->t('%name is not a valid cascade layer name. Use letters, digits, hyphens and underscores, not starting with a digit, and no dots.', ['%name' => $name]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::SETTINGS)
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('wrapper_layer', (string) $form_state->getValue('wrapper_layer'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
