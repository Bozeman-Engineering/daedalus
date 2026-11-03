<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Traits;

use Drupal\daedalus_blueprint\Materials\BudgetReading;
use Drupal\daedalus_blueprint\Materials\MaterialGestureException;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * Asserts the AI context budget's one refusal, on whichever door it lands.
 *
 * The guard speaks one wording everywhere (MaterialsBudget::assertRoom()):
 * one AI_CONTEXT_FULL error at the sharing flag's path, the reading as
 * its context, the hint naming "Offer to the AI" and the usage dialog's
 * path. A provider test catches it as the gesture exception; an op-door
 * test reads it off the batch result; both pin the same shape here.
 */
trait AiContextRefusalTrait {

  /**
   * Runs a gesture that must be refused as full, and pins the refusal.
   *
   * @param callable $gesture
   *   The provider call.
   * @param \Drupal\daedalus_blueprint\Materials\BudgetReading $reading
   *   The reading the refusal must carry.
   * @param string $path
   *   The sharing flag the refusal names: `offered` or `favorite`.
   */
  protected function assertRefusedAsFull(callable $gesture, BudgetReading $reading, string $path = 'offered'): void {
    try {
      $gesture();
    }
    catch (MaterialGestureException $exception) {
      $errors = $exception->getErrors();
      $this->assertCount(1, $errors, 'A full site refuses with the one error.');
      $this->assertErrorIsFull($errors[0], $reading, $path);
      return;
    }
    $this->fail(sprintf('A gesture turning "%s" on was accepted while the site is full.', $path));
  }

  /**
   * Pins one error as the budget's refusal.
   */
  protected function assertErrorIsFull(BlueprintError $error, BudgetReading $reading, string $path = 'offered'): void {
    $this->assertSame(MaterialsBudget::ERROR_CODE, $error->getCode());
    $this->assertSame($path, $error->getPath());
    $this->assertStringContainsString('The AI context budget is full', $error->getMessage());
    $this->assertStringContainsString('Offer to the AI', $error->getHint());
    $this->assertStringContainsString(MaterialsBudget::DIALOG_PATH, $error->getHint());
    $this->assertSame($reading->toArray(), $error->getContext());
  }

}
