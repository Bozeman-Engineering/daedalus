<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_edit\EditorSeed;
use Drupal\editor\Entity\Editor;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the HTML an inline editor is seeded with.
 *
 * One function produces it (EditorSeed::seed()): the value passes the text
 * editor's filter for the format the editor opens on, and is handed back
 * as it is where core applies none (no text editor on the format, or a
 * format that restricts no HTML). The hosted form's textarea carries
 * exactly that seed (InlineEditorElement), and the format object handed in
 * is only read.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_edit')]
class EditorSeedTest extends KernelTestBase implements FormInterface {

  use UserCreationTrait;

  /**
   * A stored value with markup an editor's filter drops.
   */
  protected const STORED = '<p onclick="window.marked = 1">Kept <strong>text</strong></p><script>window.marked = 2</script>';

  /**
   * {@inheritdoc}
   *
   * The daedalus_ui hard dependencies (daedalus_blueprint +
   * daedalus_blueprint_api) are invisible to kernel environments and must be
   * listed explicitly or the container fails to compile.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'editor',
    'ckeditor5',
    'file',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
  ];

  /**
   * The service under test.
   */
  protected EditorSeed $editorSeed;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter']);

    // A format that restricts HTML, with a text editor.
    FilterFormat::create([
      'format' => 'restricted',
      'name' => 'Restricted',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => ['allowed_html' => '<p> <strong>'],
        ],
      ],
    ])->save();
    $this->createEditor('restricted');

    // A format that restricts HTML, with no text editor.
    FilterFormat::create([
      'format' => 'no_editor',
      'name' => 'No editor',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => ['allowed_html' => '<p> <strong>'],
        ],
      ],
    ])->save();

    // A format that restricts nothing, with a text editor.
    FilterFormat::create([
      'format' => 'unrestricted',
      'name' => 'Unrestricted',
    ])->save();
    $this->createEditor('unrestricted');

    $this->editorSeed = $this->container->get('daedalus_edit.editor_seed');
  }

  /**
   * Gives a text format a CKEditor 5 text editor.
   */
  protected function createEditor(string $format): void {
    Editor::create([
      'format' => $format,
      'editor' => 'ckeditor5',
      'settings' => ['toolbar' => ['items' => ['bold']]],
      'image_upload' => ['status' => FALSE],
    ])->save();
  }

  /**
   * A format with an editor that restricts HTML seeds the filtered value.
   */
  public function testSeedIsFilteredForTheEditor(): void {
    $seed = $this->editorSeed->seed(self::STORED, FilterFormat::load('restricted'));

    $this->assertStringNotContainsString('onclick', $seed, 'An event handler attribute is not seeded.');
    $this->assertStringNotContainsString('<script', $seed, 'A script element is not seeded.');
    $this->assertStringContainsString('Kept <strong>text</strong>', $seed, 'The content itself is seeded.');
  }

  /**
   * Where core applies no editor filter the value is handed back as it is.
   */
  public function testSeedIsTheValueWhereNoFilterApplies(): void {
    $this->assertSame(
      self::STORED,
      $this->editorSeed->seed(self::STORED, FilterFormat::load('no_editor')),
      'A format with no text editor has no canvas editor to seed: the value stays for the escaped textarea.',
    );
    $this->assertSame(
      self::STORED,
      $this->editorSeed->seed(self::STORED, FilterFormat::load('unrestricted')),
      'A format that restricts no HTML prints the value as it is anyway.',
    );
    $this->assertSame('', $this->editorSeed->seed('', FilterFormat::load('restricted')), 'An empty value seeds empty.');
  }

  /**
   * The hosted form's textarea carries the seed.
   */
  public function testFormTextareaCarriesTheSeed(): void {
    $element = $this->container->get('element.daedalus_edit')
      ->preRenderTextFormat($this->textFormatElement('restricted'));

    $this->assertSame(
      $this->editorSeed->seed(self::STORED, FilterFormat::load('restricted')),
      $element['value']['#value'],
      'The textarea of a format with an editor holds what the one seed function answers.',
    );
    $this->assertStringNotContainsString('onclick', $element['value']['#value']);
    $this->assertSame(
      self::STORED,
      $element['value']['#attributes']['data-editor-value-original'],
      'The value as stored rides an attribute, which is escaped text.',
    );

    $element = $this->container->get('element.daedalus_edit')
      ->preRenderTextFormat($this->textFormatElement('no_editor'));
    $this->assertSame(
      self::STORED,
      $element['value']['#value'],
      'A format with no editor keeps the value in the textarea, where it is edited as text.',
    );
  }

  /**
   * A value whose stored format is gone still builds the hosted form.
   *
   * Core hands a user who administers formats the format selector with
   * nothing selected, to assign a new one. No format is open, so there is
   * no editor to seed and the textarea keeps the value as text.
   */
  public function testFormTextareaOfMissingFormatKeepsTheValue(): void {
    $this->installEntitySchema('user');
    // Deleting a format asks Layout Builder's inline block usage table.
    $this->installSchema('layout_builder', ['inline_block_usage']);
    FilterFormat::create(['format' => 'gone', 'name' => 'Gone'])->save();
    $this->createEditor('gone');
    FilterFormat::load('gone')->delete();
    $this->assertNull(FilterFormat::load('gone'), 'The stored format no longer loads.');
    $this->setUpCurrentUser([], ['administer filters', 'use text format restricted']);

    $form = $this->container->get('form_builder')->getForm($this);
    $this->assertTrue($form['body']['format']['format']['#access'], 'The format selector is offered.');
    $this->assertEmpty($form['body']['format']['format']['#value'], 'No format is selected.');

    $element = $this->container->get('element.daedalus_edit')->preRenderTextFormat($form['body']);

    $this->assertSame(
      self::STORED,
      $element['value']['#value'],
      'With no format open the textarea keeps the value, where it is escaped text.',
    );
    $this->assertSame(self::STORED, $element['value']['#attributes']['data-editor-value-original']);

    $this->assertSame(
      self::STORED,
      $this->editorSeed->seed(self::STORED, NULL),
      'The seed of a value with no format is the value, as core applies no editor filter.',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'daedalus_edit_editor_seed_test';
  }

  /**
   * {@inheritdoc}
   *
   * One hosted form element holding a value stored on the "gone" format.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['body'] = [
      '#type' => 'inline_textarea',
      '#title' => 'Body',
      '#default_value' => self::STORED,
      '#format' => 'gone',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * A processed text_format element holding the stored value.
   *
   * The shape \Drupal\filter\Element\TextFormat::processFormat() leaves for
   * the pre-render: the value child and the format selector offering all
   * three formats, the given one selected.
   */
  protected function textFormatElement(string $selected): array {
    return [
      '#base_type' => 'textarea',
      'value' => [
        '#id' => 'edit-body-0-value',
        '#value' => self::STORED,
      ],
      'format' => [
        'format' => [
          '#name' => 'body[0][format]',
          '#options' => [
            'restricted' => 'Restricted',
            'no_editor' => 'No editor',
            'unrestricted' => 'Unrestricted',
          ],
          '#value' => $selected,
          '#access' => TRUE,
        ],
      ],
    ];
  }

}
