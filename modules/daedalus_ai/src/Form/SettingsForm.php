<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\Enum\AiModelCapability;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_ai\ModelChoice;
use Drupal\daedalus_ai\ModelChoiceOption;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The AI chat settings: its assistant, image generation, the curated models.
 *
 * One form over daedalus_ai.settings, at /admin/config/daedalus/ai/chat. Its
 * first door is Configuration » Daedalus » AI; its second is drupal/ai's
 * Configuration » AI » Tools & Automation, beside the memory, ledger and
 * upstream forms (daedalus_ai.links.menu.yml carries both links, and
 * SETTINGS.md at the daedalus repo root names them).
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager, for the assistants an administrator may pick.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The provider plugin manager, for every installed provider's chat models.
   */
  protected AiProviderPluginManager $aiProvider;

  /**
   * The curated model list and the managed provider it is confined to.
   */
  protected ModelChoice $modelChoice;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->aiProvider = $container->get('ai.provider');
    $instance->modelChoice = $container->get(ModelChoice::class);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'daedalus_ai_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [ModelChoice::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(ModelChoice::SETTINGS);
    $options = [];
    foreach ($this->entityTypeManager->getStorage('ai_assistant')->loadMultiple() as $id => $assistant) {
      $options[$id] = $assistant->label();
    }

    $form[ModelChoice::ASSISTANT_KEY] = [
      '#type' => 'select',
      '#title' => $this->t('AI Assistant'),
      '#options' => $options,
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $config->get(ModelChoice::ASSISTANT_KEY) ?: '',
      '#description' => $this->t('The AI Assistant the chat sidebar talks to. The module ships no default; until one is chosen the sidebar is not shown.'),
    ];
    $form['image_generation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI image generation'),
      '#default_value' => $config->get('image_generation_enabled') ?? TRUE,
      '#description' => $this->t('When enabled, the AI can generate new images for image fields. When disabled, AI-minted media keep the placeholder image as their final image and no images are generated. Users can also turn generation off for themselves from the Edit Mode toolbar.'),
    ];
    $form['placeholder_image'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder image'),
      '#default_value' => $config->get('placeholder_image') ?? '',
      '#description' => $this->t('Stream URI (e.g. public://placeholder.png) or app-root-relative path of the image AI-minted media carry while their generated image is pending. Leave empty to use the neutral image shipped with Daedalus AI.'),
    ];
    $form[ModelChoice::CHOICES_KEY] = $this->modelChoicesTable();

    return parent::buildForm($form, $form_state);
  }

  /**
   * The "Models users may choose" table: one row per entry plus one blank.
   *
   * The select offers the managed provider's live chat models that can call
   * tools (the provider the chat runs on without a choice, the assistant's
   * or the site's default: ModelChoice::managedProvider()), in drupal/ai's
   * provider__model option grammar, filtered from the list the AI settings
   * form offers for "Chat with Tools": a choice switches the model, never
   * the provider. A curated model its provider no longer lists stays on the
   * form, named as such, so the administrator sees it rather than losing it
   * silently. With no chat provider configured at all there is nothing to
   * curate, and the table gives way to a line saying so: this form is also
   * where the assistant is chosen, so it must render on that site.
   *
   * @return array
   *   The table element, or the item that stands in for it.
   */
  private function modelChoicesTable(): array {
    try {
      $managed = $this->modelChoice->managedProvider();
    }
    catch (\RuntimeException $e) {
      return [
        '#type' => 'item',
        '#title' => $this->t('Models users may choose'),
        '#markup' => $this->t(
          'No chat provider is configured, so there is nothing to curate: set a default chat provider on the AI settings page, or a provider on the assistant, first. (@why)',
          ['@why' => $e->getMessage()],
        ),
      ];
    }
    $options = array_filter(
      $this->aiProvider->getSimpleProviderModelOptions('chat', TRUE, TRUE, [AiModelCapability::ChatTools]),
      static fn (string $key): bool => $key === '' || (ModelChoiceOption::splitKey($key)[0] ?? NULL) === $managed,
      ARRAY_FILTER_USE_KEY,
    );
    $table = [
      '#type' => 'table',
      '#caption' => $this->t('Models users may choose'),
      '#header' => [
        $this->t('Model'),
        $this->t('Label'),
      ],
      '#prefix' => '<p>' . $this->t(
        'Users with the "Choose the AI model the chat runs on" permission pick the model the chat runs on from this list, in the chat\'s menu. The list holds models of the provider the chat runs on, %provider: a choice switches the model, never the provider. An empty list hides the choice. The site\'s default chat model stays the fallback for everyone who has not chosen, and the label is what the menu shows beside each model. Clear a model to drop its row.',
        ['%provider' => $managed],
      ) . '</p>',
    ];
    $rows = $this->modelChoice->choices();
    $rows[] = NULL;
    foreach ($rows as $delta => $option) {
      $row_options = $options;
      $key = $option?->key() ?? '';
      if ($key !== '' && !isset($row_options[$key])) {
        $row_options[$key] = $this->t('@key (not listed by its provider)', ['@key' => $key]);
      }
      $table[$delta]['key'] = [
        '#type' => 'select',
        '#title' => $this->t('Model'),
        '#title_display' => 'invisible',
        '#options' => $row_options,
        '#default_value' => $key,
      ];
      $table[$delta]['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#title_display' => 'invisible',
        '#default_value' => $option?->label ?? '',
        '#size' => 32,
        '#maxlength' => 128,
      ];
    }
    return $table;
  }

  /**
   * {@inheritdoc}
   *
   * The curated model table's guard. The select already refuses a value
   * outside its options; what is left is the grammar (two non-empty parts),
   * a model listed twice, and a hand-posted key on a provider other than the
   * managed one (the select lists only the managed provider's models, so
   * this is a value the form never offered).
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $seen = [];
    $managed = NULL;
    foreach ($form_state->getValue(ModelChoice::CHOICES_KEY) ?? [] as $delta => $row) {
      $key = (string) ($row['key'] ?? '');
      if ($key === '') {
        continue;
      }
      $name = ModelChoice::CHOICES_KEY . "][$delta][key";
      $split = ModelChoiceOption::splitKey($key);
      if ($split === NULL) {
        $form_state->setErrorByName($name, $this->t('%key is not a provider__model option.', ['%key' => $key]));
        continue;
      }
      try {
        $managed ??= $this->modelChoice->managedProvider();
      }
      catch (\RuntimeException $e) {
        $form_state->setErrorByName($name, $this->t('%key cannot be curated: @why', [
          '%key' => $key,
          '@why' => $e->getMessage(),
        ]));
        continue;
      }
      if ($split[0] !== $managed) {
        $form_state->setErrorByName($name, $this->t('%key names the provider %provider where the chat runs on %managed; a choice switches the model, never the provider.', [
          '%key' => $key,
          '%provider' => $split[0],
          '%managed' => $managed,
        ]));
        continue;
      }
      if (isset($seen[$key])) {
        $form_state->setErrorByName($name, $this->t('%key is listed twice.', ['%key' => $key]));
      }
      $seen[$key] = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config(ModelChoice::SETTINGS)
      ->set(ModelChoice::ASSISTANT_KEY, (string) $form_state->getValue(ModelChoice::ASSISTANT_KEY))
      ->set('image_generation_enabled', (bool) $form_state->getValue('image_generation_enabled'))
      ->set('placeholder_image', trim((string) $form_state->getValue('placeholder_image')));

    // The table's value is absent when the table gave way to the "nothing
    // to curate" line; the list is then left as it is.
    $rows = $form_state->getValue(ModelChoice::CHOICES_KEY);
    if (is_array($rows)) {
      $choices = [];
      foreach ($rows as $row) {
        $key = (string) ($row['key'] ?? '');
        if ($key === '') {
          continue;
        }
        [$provider, $model] = ModelChoiceOption::splitKey($key);
        $label = trim((string) ($row['label'] ?? ''));
        $choices[] = (new ModelChoiceOption($provider, $model, $label === '' ? NULL : $label))->toConfig();
      }
      $config->set(ModelChoice::CHOICES_KEY, $choices);
    }
    $config->save();

    parent::submitForm($form, $form_state);
  }

}
