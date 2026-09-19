<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\FieldHandler;

// cspell:ignore Newz
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the field handler result value object.
 */
#[CoversClass(FieldHandlerResult::class)]
#[Group('daedalus_blueprint')]
class FieldHandlerResultTest extends UnitTestCase {

  /**
   * Tests a successful result with a scalar value.
   */
  public function testSuccessfulScalar(): void {
    $result = new FieldHandlerResult('My Title');

    $this->assertSame('My Title', $result->getValue());
    $this->assertEmpty($result->getErrors());
    $this->assertEmpty($result->getWarnings());
    $this->assertFalse($result->hasErrors());
  }

  /**
   * Tests a successful result with an array value.
   */
  public function testSuccessfulArray(): void {
    $value = ['value' => '<p>HTML body</p>', 'format' => 'full_html'];
    $result = new FieldHandlerResult($value);

    $this->assertSame($value, $result->getValue());
    $this->assertFalse($result->hasErrors());
  }

  /**
   * Tests a result with NULL value and an error.
   */
  public function testErrorWithNullValue(): void {
    $error = new BlueprintError(
      'fields.field_tags[1]',
      'REFERENCE_NOT_FOUND',
      "No taxonomy_term with label 'Newz' found.",
      ['similar' => ['News']],
      'Check spelling.',
    );
    $result = new FieldHandlerResult(NULL, [$error]);

    $this->assertNull($result->getValue());
    $this->assertTrue($result->hasErrors());
    $this->assertCount(1, $result->getErrors());
    $this->assertSame('REFERENCE_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

  /**
   * Tests a result with a value and warnings.
   */
  public function testValueWithWarnings(): void {
    $warning = new BlueprintWarning(
      'fields.field_tags[0]',
      'LABEL_MISMATCH',
      "Resolved by target_id 5, but _label 'Newz' does not match.",
    );
    $result = new FieldHandlerResult(['target_id' => 5], [], [$warning]);

    $this->assertSame(['target_id' => 5], $result->getValue());
    $this->assertFalse($result->hasErrors());
    $this->assertCount(1, $result->getWarnings());
    $this->assertSame('LABEL_MISMATCH', $result->getWarnings()[0]->getCode());
  }

  /**
   * Tests a result with multiple errors.
   */
  public function testMultipleErrors(): void {
    $errors = [
      new BlueprintError('fields.field_refs[0]', 'REFERENCE_NOT_FOUND', 'Not found.'),
      new BlueprintError('fields.field_refs[1]', 'REFERENCE_AMBIGUOUS', 'Ambiguous.'),
    ];
    $result = new FieldHandlerResult(NULL, $errors);

    $this->assertTrue($result->hasErrors());
    $this->assertCount(2, $result->getErrors());
  }

  /**
   * Tests a result with both errors and warnings.
   */
  public function testErrorsAndWarnings(): void {
    $error = new BlueprintError('fields.field_refs[0]', 'REFERENCE_NOT_FOUND', 'Not found.');
    $warning = new BlueprintWarning('fields.field_refs[1]', 'LABEL_MISMATCH', 'Mismatch.');
    $result = new FieldHandlerResult(NULL, [$error], [$warning]);

    $this->assertTrue($result->hasErrors());
    $this->assertCount(1, $result->getErrors());
    $this->assertCount(1, $result->getWarnings());
  }

  /**
   * Tests that an empty errors array means no errors.
   */
  public function testEmptyErrorsArray(): void {
    $result = new FieldHandlerResult('value', []);
    $this->assertFalse($result->hasErrors());
  }

}
