<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Functional;

use Drupal\Core\Form\FormState;
use Drupal\daedalus_ai\Form\SettingsForm;
use Drupal\daedalus_ai\ModelChoice;
use Drupal\daedalus_ai_test\Plugin\AiProvider\StubProvider;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The AI chat settings form: assistant, image generation, the curated models.
 *
 * The form is the one place an administrator chooses the chat's assistant,
 * turns image generation on or off, names the placeholder image, and curates
 * the models users may choose, so what it offers and saves is the list's
 * grammar: the select holds the managed provider's live chat models that can
 * call tools (the provider the chat runs on without a choice: the recipe's
 * assistant runs on the site default, which this test points at the stub
 * provider, so its two models and nothing of the recipe's Anthropic
 * provider), a row saves as {provider, model, label} in drupal/ai's
 * provider__model option grammar, a row whose select is blank is dropped,
 * and a hand-posted key on another provider is refused by name, since a
 * choice switches the model, never the provider. The site is the AI umbrella
 * recipe's product with daedalus_ai's test module on top for the stub
 * provider. Admin by explicit exception: the form is an admin-only subject
 * ("administer site configuration").
 */
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
final class ModelChoicesFormTest extends BrowserTestBase {

  use DaedalusFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'daedalus_theme';

  /**
   * The form's path.
   */
  private const PATH = '/admin/config/daedalus/ai/chat';

  /**
   * The stub provider's plugin id.
   */
  private const STUB = 'daedalus_ai_test_stub';

  /**
   * The stub provider's option key.
   */
  private const STUB_KEY = self::STUB . '__' . StubProvider::MODEL_ID;

  /**
   * A key on the recipe's Anthropic provider, not the managed one here.
   */
  private const FOREIGN_KEY = 'daedalus_anthropic__claude-opus-5-5';

  /**
   * {@inheritdoc}
   */
  protected function installRecipePath(): string {
    return dirname($this->root) . '/recipes/daedalus_recipe/ai';
  }

  /**
   * The form shows its fields, a row saves with its label, a blank drops it.
   */
  public function testModelChoicesTable(): void {
    // The stub provider lives in daedalus_ai's test module, which the recipe
    // does not enable.
    \Drupal::service('module_installer')->install(['daedalus_ai_test']);
    // The managed provider: the recipe's assistant runs on the site default
    // (llm_provider "__default__"), which the Anthropic ingredient points at
    // daedalus_anthropic; the stub is the chat's provider here.
    $this->config('ai.settings')
      ->set('default_providers.chat', ['provider_id' => self::STUB, 'model_id' => StubProvider::MODEL_ID])
      ->save();
    $assert = $this->assertSession();

    // The form is gated by core's generic site-configuration permission.
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));

    // The assistant and image fields show the recipe's install state: the
    // recipe's assistant chosen, generation off (the daedalus_ai ingredient
    // turns the module's shipped default off), no placeholder of the site's
    // own. The shipped list is empty: the table carries the one blank row,
    // whose select offers the managed provider's models and no other
    // provider's.
    $this->assertSame([], $this->config(ModelChoice::SETTINGS)->get(ModelChoice::CHOICES_KEY));
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $assert->elementTextContains('css', 'title', 'AI chat');
    $assert->optionExists('assistant_id', 'daedalus');
    $assert->fieldValueEquals('assistant_id', 'daedalus');
    $assert->checkboxNotChecked('image_generation_enabled');
    $assert->fieldValueEquals('placeholder_image', '');
    $assert->pageTextContains('Models users may choose');
    $assert->pageTextContains('The list holds models of the provider the chat runs on, ' . self::STUB);
    $assert->fieldExists('model_choices[0][key]');
    $assert->fieldNotExists('model_choices[1][key]');
    $assert->optionExists('model_choices[0][key]', self::STUB_KEY);
    $assert->optionExists('model_choices[0][key]', self::STUB . '__' . StubProvider::ALT_MODEL_ID);
    $offered = array_map(
      static fn ($option): string => (string) $option->getAttribute('value'),
      $this->getSession()->getPage()->findField('model_choices[0][key]')->findAll('css', 'option'),
    );
    $this->assertSame(['', self::STUB_KEY, self::STUB . '__' . StubProvider::ALT_MODEL_ID], $offered, 'The select is confined to the managed provider.');

    // One row choosing the stub model with a label saves as a config row;
    // generation on and a placeholder of the site's own ride the same save.
    $this->submitForm([
      'image_generation_enabled' => TRUE,
      'placeholder_image' => ' public://pending.png ',
      'model_choices[0][key]' => self::STUB_KEY,
      'model_choices[0][label]' => 'Stub (fast)',
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $settings = $this->config(ModelChoice::SETTINGS);
    $this->assertSame([
      ['provider' => self::STUB, 'model' => StubProvider::MODEL_ID, 'label' => 'Stub (fast)'],
    ], $settings->get(ModelChoice::CHOICES_KEY));
    $this->assertSame('daedalus', $settings->get('assistant_id'), 'The assistant rode the save untouched.');
    $this->assertTrue($settings->get('image_generation_enabled'));
    $this->assertSame('public://pending.png', $settings->get('placeholder_image'), 'The placeholder is saved trimmed.');

    // The form reads everything back and offers a second blank row.
    $this->drupalGet(self::PATH);
    $assert->fieldValueEquals('assistant_id', 'daedalus');
    $assert->checkboxChecked('image_generation_enabled');
    $assert->fieldValueEquals('placeholder_image', 'public://pending.png');
    $assert->fieldValueEquals('model_choices[0][key]', self::STUB_KEY);
    $assert->fieldValueEquals('model_choices[0][label]', 'Stub (fast)');
    $assert->fieldExists('model_choices[1][key]');

    // A key on another provider cannot come through the select (it is not
    // offered), so it is hand-posted through the form builder: the form
    // refuses it on that row (the select's own illegal-choice check lands
    // first and a name keeps its first error) and nothing is saved; the
    // form's own validation, the guard behind the select, refuses the same
    // key naming both providers.
    $values = [
      'assistant_id' => 'daedalus',
      'image_generation_enabled' => 0,
      'placeholder_image' => '',
      ModelChoice::CHOICES_KEY => [
        ['key' => self::STUB_KEY, 'label' => 'Stub (fast)'],
        ['key' => self::FOREIGN_KEY, 'label' => ''],
      ],
    ];
    $form_state = (new FormState())->setValues($values);
    \Drupal::formBuilder()->submitForm(SettingsForm::class, $form_state);
    $this->assertArrayHasKey(ModelChoice::CHOICES_KEY . '][1][key', $form_state->getErrors(), 'The hand-posted foreign key is refused on its row.');
    $this->assertSame([
      ['provider' => self::STUB, 'model' => StubProvider::MODEL_ID, 'label' => 'Stub (fast)'],
    ], $this->config(ModelChoice::SETTINGS)->get(ModelChoice::CHOICES_KEY), 'A refused submission saves nothing.');
    $form = [];
    $form_state = (new FormState())->setValues($values);
    \Drupal::classResolver()->getInstanceFromDefinition(SettingsForm::class)->validateForm($form, $form_state);
    $errors = array_map(static fn ($error): string => strip_tags((string) $error), $form_state->getErrors());
    $this->assertSame(
      [ModelChoice::CHOICES_KEY . '][1][key' => self::FOREIGN_KEY . ' names the provider daedalus_anthropic where the chat runs on ' . self::STUB . '; a choice switches the model, never the provider.'],
      $errors,
      'The form refuses the foreign key by name and accepts the managed row.',
    );

    // Blanking the select drops the row.
    $this->drupalGet(self::PATH);
    $this->submitForm(['model_choices[0][key]' => ''], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $this->assertSame([], $this->config(ModelChoice::SETTINGS)->get(ModelChoice::CHOICES_KEY));
  }

}
