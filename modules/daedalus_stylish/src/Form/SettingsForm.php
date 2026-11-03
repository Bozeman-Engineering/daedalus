<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The styling settings: how styling meets the AI, the theme and the media.
 *
 * One form over the four site-mechanics keys of daedalus_stylish.settings
 * (ai_minting, container_roots, media_image_style, breakpoints), at
 * /admin/config/daedalus/styling/settings under the Daedalus config
 * category's Styling sub-category, its one door (SETTINGS.md at the daedalus
 * repo root rosters it). The object's other two keys are never on this form:
 * skin_choice is the canvas's and drush's (the skin card,
 * `daedalus_stylish:skin:choose`, each validating the choice against the
 * active design system), opt_outs is the bundle form's Page builder tab's
 * (the styling box writes it as a bundle opts out). Each has an owner that
 * validates it; a free edit here would be a second, unvalidated road.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The config object the form edits.
   */
  public const SETTINGS = 'daedalus_stylish.settings';

  /**
   * The entity type manager, for the image styles a site may pick.
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
    return 'daedalus_stylish_settings';
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
    $breakpoints = $config->get('breakpoints') ?? [];

    // The AI minting gate is fail-open: only a stored FALSE closes it, so a
    // site predating the key reads as open here too.
    $form['ai_minting'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Let the AI mint new materials'),
      '#description' => $this->t('Whether the AI may create new design tokens, animations and fonts through the material gestures and the blueprint create lane. Its own governance axis: humans stay governed by the "create skin materials" permission, and the active design system\'s enforcement mode governs what consumers may wear, not what the AI may mint.'),
      '#default_value' => $config->get('ai_minting') !== FALSE,
    ];

    $form['container_roots'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Emit the page-level query container'),
      '#description' => $this->t('Emit the width-band query container rule (<code>container-type: inline-size</code> on the page canvas) wherever container breakpoint conditions are styled. Turn off when the theme manages its own query container.'),
      '#default_value' => (bool) $config->get('container_roots'),
    ];

    $form['media_image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Image style for media in style values'),
      '#description' => $this->t('The image style media referenced from style values (backgrounds, generated content) resolves through when no style guide constraint names one.'),
      '#options' => $this->imageStyleOptions(),
      '#empty_option' => $this->t('- Original file -'),
      '#empty_value' => '',
      '#default_value' => (string) $config->get('media_image_style'),
    ];

    // The two band ceilings; their meaning is the schema's, read from it so
    // the form and the schema cannot drift.
    $typed = $this->typedConfigManager->get(self::SETTINGS);
    $form['breakpoints'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Container breakpoints'),
      '#description' => $this->t('The ceilings, in px, of the two published width references, "narrow" and "medium": a style keyed by a reference follows a change here, since the name stands for the CSS the site owns, where a literal query typed in a style (<code>@container (max-width: 600px)</code>) keeps its own numbers. The unconditioned base is the full-width look.'),
      '#tree' => TRUE,
    ];
    $bands = [
      'narrow' => ['title' => $this->t('Narrow threshold'), 'shipped' => 767],
      'medium' => ['title' => $this->t('Medium threshold'), 'shipped' => 1023],
    ];
    foreach ($bands as $band => $info) {
      $form['breakpoints'][$band] = [
        '#type' => 'number',
        '#title' => $info['title'],
        '#description' => $typed->get('breakpoints.' . $band)->getDataDefinition()->getLabel(),
        '#default_value' => (int) ($breakpoints[$band] ?? $info['shipped']),
        '#min' => 1,
        '#step' => 1,
        '#required' => TRUE,
        '#field_suffix' => 'px',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // The medium band starts at narrow + 1, so it needs room above narrow.
    $narrow = (int) $form_state->getValue(['breakpoints', 'narrow']);
    $medium = (int) $form_state->getValue(['breakpoints', 'medium']);
    if ($medium <= $narrow) {
      $form_state->setErrorByName('breakpoints][medium', $this->t('The medium threshold must be greater than the narrow threshold (@narrow px).', ['@narrow' => $narrow]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::SETTINGS)
      ->set('ai_minting', (bool) $form_state->getValue('ai_minting'))
      ->set('container_roots', (bool) $form_state->getValue('container_roots'))
      ->set('media_image_style', (string) $form_state->getValue('media_image_style'))
      ->set('breakpoints', [
        'narrow' => (int) $form_state->getValue(['breakpoints', 'narrow']),
        'medium' => (int) $form_state->getValue(['breakpoints', 'medium']),
      ])
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * The site's image styles, keyed by machine name, labelled.
   *
   * The module does not depend on the image module (media styles are one
   * consumer of the style model, not its spine), so a site without it has
   * no styles to offer and the select carries the original-file option
   * alone.
   *
   * @return array<string, string>
   *   Image style labels keyed by id.
   */
  protected function imageStyleOptions(): array {
    if (!$this->entityTypeManager->hasDefinition('image_style')) {
      return [];
    }
    $options = [];
    foreach ($this->entityTypeManager->getStorage('image_style')->loadMultiple() as $id => $style) {
      $options[$id] = (string) $style->label();
    }
    asort($options);
    return $options;
  }

}
