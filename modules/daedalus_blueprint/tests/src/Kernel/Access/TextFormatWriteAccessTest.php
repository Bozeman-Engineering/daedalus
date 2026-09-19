<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Access;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that TextFieldHandler enforces 'use text format' access on write.
 *
 * The form paradigm enforces format access only in the text_format form
 * element; blueprint writes bypass forms entirely, so the spine
 * (TextFieldHandler::validateItemFormat()) must check it. Without this, any
 * write path (AI agents, endpoint verbs, blueprint editors) could store
 * content in a privileged format such as full_html and have it render
 * unfiltered.
 *
 * The base test class runs as an admin (access-transparent). Each test here
 * switches to a restricted user.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class TextFormatWriteAccessTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // A format that requires its own 'use text format restricted_html'
    // permission. plain_text (installed by filter config) is the site
    // fallback format and is usable by everyone.
    FilterFormat::create([
      'format' => 'restricted_html',
      'name' => 'Restricted HTML',
    ])->save();
  }

  /**
   * Gets the text field definition and handler for field_description.
   *
   * @return array
   *   A [definition, handler] pair.
   */
  protected function getTextFieldHandler(string $field_name = 'field_description'): array {
    $definition = $this->entityTypeManager->getStorage('entity_test')
      ->create([])
      ->getFieldDefinition($field_name);
    return [$definition, $this->fieldHandlerManager->getHandler($definition)];
  }

  /**
   * A user without the format's use permission cannot write with it.
   */
  public function testInaccessibleFormatDenied(): void {
    $this->setUpCurrentUser([], ['administer entity_test content']);
    [$definition, $handler] = $this->getTextFieldHandler();

    $result = $handler->deserialize([
      'value' => '<p>Sneaky</p>',
      'format' => 'restricted_html',
    ], $definition);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_TEXT_FORMAT', $errors[0]->getCode());
    $this->assertSame('fields.field_description', $errors[0]->getPath());
  }

  /**
   * A user with the format's use permission writes with it normally.
   */
  public function testAccessibleFormatAllowed(): void {
    $this->setUpCurrentUser([], [
      'administer entity_test content',
      'use text format restricted_html',
    ]);
    [$definition, $handler] = $this->getTextFieldHandler();

    $result = $handler->deserialize([
      'value' => '<p>Fine</p>',
      'format' => 'restricted_html',
    ], $definition);

    $this->assertFalse($result->hasErrors());
    $this->assertSame([], $result->getWarnings());
    $this->assertSame('restricted_html', $result->getValue()['format']);
  }

  /**
   * A nonexistent format falls back, and the fallback is access-checked too.
   *
   * The plain_text format sorts first among enabled formats and is the site
   * fallback format, so the restricted user may use it: warning, no error.
   */
  public function testNonexistentFormatFallsBackToAccessibleFormat(): void {
    $this->setUpCurrentUser([], ['administer entity_test content']);
    [$definition, $handler] = $this->getTextFieldHandler();

    $result = $handler->deserialize([
      'value' => 'Hello',
      'format' => 'does_not_exist',
    ], $definition);

    $this->assertFalse($result->hasErrors());
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('INVALID_TEXT_FORMAT', $warnings[0]->getCode());
    $this->assertSame('plain_text', $result->getValue()['format']);
  }

  /**
   * A fallback into a format the user cannot use is denied, not silently kept.
   */
  public function testFallbackIntoInaccessibleFormatDenied(): void {
    // A field whose allowed_formats pins the restricted format, so the
    // nonexistent-format fallback resolves to a format the user cannot use.
    FieldStorageConfig::create([
      'field_name' => 'field_pinned',
      'entity_type' => 'entity_test',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_pinned',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Pinned',
      'settings' => ['allowed_formats' => ['restricted_html']],
    ])->save();

    $this->setUpCurrentUser([], ['administer entity_test content']);
    [$definition, $handler] = $this->getTextFieldHandler('field_pinned');

    $result = $handler->deserialize([
      'value' => 'Hello',
      'format' => 'does_not_exist',
    ], $definition);

    $this->assertTrue($result->hasErrors());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_TEXT_FORMAT', $errors[0]->getCode());
    $this->assertSame('fields.field_pinned', $errors[0]->getPath());
    $warnings = $result->getWarnings();
    $this->assertCount(1, $warnings);
    $this->assertSame('INVALID_TEXT_FORMAT', $warnings[0]->getCode());
  }

}
