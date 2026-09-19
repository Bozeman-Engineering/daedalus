<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Form;

use Drupal\daedalus_layout\NewMedia;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\daedalus_layout\DaedalusLayoutSettingsTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Ajax\CloseModalDialogCommand;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Media block file association form.
 *
 * The block-type chooser for a dragged file with no stored association.
 * Submit does NOT place the block: it creates the media, optionally saves
 * the association, and hands the resolution back to the client (via an
 * InvokeCommand), which places the block through the blueprint mutation
 * endpoint — the same path as every other placement.
 */
final class MediaBlockFileAssociationForm extends FormBase {

  use DaedalusLayoutSettingsTrait;

  public function __construct(
    protected NewMedia $newMedia,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected DaedalusUi $ui,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('daedalus_layout.new_media'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('daedalus_ui.ui'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'daedalus_layout_media_block_file_association';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form_state->set('workspace_safe', TRUE);
    $file_extension = $form_state->get('file_extension');
    $file_extension = strtolower($file_extension);

    $compatible_media_types = $this->newMedia->getCompatibleMediaTypes($file_extension);
    $relevant_block_content_types = $this->getCompatibleBlockContentTypes($compatible_media_types, $form_state);

    if (empty($relevant_block_content_types)) {
      $this->messenger()->addWarning($this->t('No media types available for %file_extension files.', ['%file_extension' => ".$file_extension"]));

      return new AjaxResponse();
    }

    $file_extension_label = ucfirst($file_extension);
    $form['description']['#markup'] = $this->t("@file_extension's can be placed in @block_types block types. Which block type would you like to place?", [
      '@file_extension' => $file_extension_label,
      '@block_types' => daedalus_ui_implode(array_values($relevant_block_content_types)),
    ]);
    $form['file_extension_association'] = [
      '#type' => 'select',
      '#options' => $relevant_block_content_types,
    ];
    $form['remember'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Remember this file association'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
        '#attributes' => [
          'id' => 'file-association-submit',
        ],
        '#ajax' => [
          'callback' => [$this, 'ajaxSubmit'],
          'selector' => '#file-association-submit',
        ],
      ],
    ];

    return $form;
  }

  /**
   * Ajax submission handler.
   *
   * Hands the resolution to the awaiting drop pipeline BEFORE closing the
   * dialog: the client's pending-choice promise treats a close without a
   * choice as cancel, so command order is load-bearing.
   */
  public function ajaxSubmit(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(new InvokeCommand(NULL, 'daedalusUiFileAssociationChosen', [$form_state->get('resolution')]));
    if ($form_state->get('update_settings_sidebar')) {
      // Re-render the settings panel and reveal the file-association settings.
      $panel = $this->ui->buildSidebarPanel('edit_mode_settings');
      $response->addCommand(new ReplaceCommand('#edit_mode_settings', $panel));
      $response->addCommand(new InvokeCommand(NULL, 'DaedalusUiOpenFileAssociationSettings'));
    }
    $response->addCommand(new CloseModalDialogCommand());
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $association = $form_state->getValue('file_extension_association');
    $remember = $form_state->getValue('remember');
    if ($remember) {
      $file_extension = $form_state->get('file_extension');
      $file_extension = strtolower($file_extension);
      $this->saveUserFileAssociationPreference($association, $file_extension);
      $form_state->set('update_settings_sidebar', TRUE);
      $this->messenger()->addStatus($this->t('Associated @extension with the %block_type block type.', [
        '@extension' => $file_extension,
        '%block_type' => $association,
      ]));
    }
    $form_state->set('resolution', $this->newMedia->buildResolution($association, $form_state));
  }

  /**
   * Saves the user's file association preference.
   *
   * @param string $association
   *   The block content type ID to associate with the extension.
   * @param string $file_extension
   *   The lower-cased file extension.
   */
  public function saveUserFileAssociationPreference(string $association, string $file_extension): void {
    $account = $this->currentUser();
    // Save the user file association preference.
    if ($account->isAuthenticated()) {
      $user = $this->entityTypeManager->getStorage('user')->load($account->id());
      $settings = $user->daedalus_ui_settings->getValue();
      $settings[0]['file_associations'][$file_extension] = $association;
      $user->set('daedalus_ui_settings', $settings[0]);
      daedalus_ui_save_outside_workspace($user);
    }
  }

  /**
   * Gets the block content types that can reference a compatible media type.
   *
   * @param array $compatible_media_types
   *   An array of MediaType entities compatible with a file extension.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state carrying the section storage details.
   *
   * @return array
   *   An array of BlockContentType entities that have a media reference field
   *   allowing at least one of the compatible media types.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function getCompatibleBlockContentTypes(array $compatible_media_types, FormStateInterface $form_state): array {
    // What block types are allowed to be placed?
    [$section_storage] = $this->newMedia->getSectionStorageDetails($form_state);

    $promoted_block_plugin_ids = $this->getDaedalusLayoutSetting($section_storage, 'promoted_blocks');
    $promoted_block_content_ids = array_map(function ($block_plugin_id) {
      return explode(':', $block_plugin_id)[1];
    }, $promoted_block_plugin_ids);

    $compatible_media_type_ids = array_map(function ($media_type) {
      return $media_type->id();
    }, $compatible_media_types);

    $block_content_types = $this->entityTypeManager->getStorage('block_content_type')->loadMultiple();
    $relevant_block_content_types = [];

    foreach ($block_content_types as $block_content_type) {
      if (!in_array($block_content_type->id(), $promoted_block_content_ids)) {
        continue;
      }
      $fields = $this->entityFieldManager->getFieldDefinitions('block_content', $block_content_type->id());

      foreach ($fields as $field) {
        if ($field->getType() === 'entity_reference' && $field->getSetting('target_type') === 'media') {
          $handler_settings = $field->getSetting('handler_settings');
          $allowed_media_types = $handler_settings['target_bundles'] ?? [];

          // No target bundles means every media type is allowed; otherwise an
          // overlap with the compatible media types qualifies the block type.
          if (empty($allowed_media_types) || array_intersect($allowed_media_types, $compatible_media_type_ids)) {
            $relevant_block_content_types[$block_content_type->id()] = $block_content_type->label();
            break;
          }
        }
      }
    }
    return $relevant_block_content_types;
  }

}
