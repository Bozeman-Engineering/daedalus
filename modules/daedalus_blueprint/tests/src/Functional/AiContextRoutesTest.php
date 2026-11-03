<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Form\AiContextForm;
use Drupal\daedalus_blueprint\Materials\BudgetReading;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The AI context usage's two routes: the JSON reading and the form.
 *
 * Both are doors over MaterialsBudget::reading(), so the contract is
 * what each answers and to whom: the JSON route's body is exactly
 * BudgetReading::toArray(); the form (the design system size) draws one
 * segment per material kind the site shares (characters above zero)
 * plus the system and the free remainder, states the budget, the active
 * design system's name and the word "estimated", lists under the bar
 * one collapsed section per kind the roster carries with a box per
 * record (offered ones checked, a record the update gesture may not
 * write disabled) and a Save, and opens in core's modal when asked for
 * as one; the form's path is MaterialsBudget::DIALOG_PATH, the string
 * the refusal hint names; both routes require MaterialsBudget::PERMISSION,
 * which the recipe's editor role does not carry as shipped (the grant
 * is the recipe's follow-up), so anonymous and the editor are refused
 * and a user holding the permission is answered. The site is the
 * daedalus recipe's product (Olivero active, daedalus_blueprint_ai
 * absent, so the schema is the one weight source); one install serves
 * the whole scenario.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
final class AiContextRoutesTest extends BrowserTestBase {

  use DaedalusFixtureTrait;

  /**
   * The JSON route's name.
   */
  private const READING_ROUTE = 'daedalus_blueprint.ai_context_reading';

  /**
   * The dialog route's name.
   */
  private const DIALOG_ROUTE = 'daedalus_blueprint.ai_context';

  /**
   * The routes declare the spine's path and permission, and answer the reading.
   */
  public function testRoutes(): void {
    $assert = $this->assertSession();
    $routes = $this->container->get('router.route_provider');
    $dialog = $routes->getRouteByName(self::DIALOG_ROUTE);
    $this->assertSame(MaterialsBudget::DIALOG_PATH, $dialog->getPath(), 'The dialog route declares the path the refusal hint names.');
    $this->assertSame(MaterialsBudget::PERMISSION, $dialog->getRequirement('_permission'));
    $this->assertSame(MaterialsBudget::PERMISSION, $routes->getRouteByName(self::READING_ROUTE)->getRequirement('_permission'));
    $dialogPath = Url::fromRoute(self::DIALOG_ROUTE)->getInternalPath();
    $readingPath = Url::fromRoute(self::READING_ROUTE)->getInternalPath();

    // Anonymous never reaches either door.
    $this->drupalGet($readingPath);
    $assert->statusCodeEquals(403);
    $this->drupalGet($dialogPath);
    $assert->statusCodeEquals(403);

    // The recipe's editor does not hold the permission as shipped.
    $editor = $this->loginAsEditor();
    $this->assertFalse($editor->hasPermission(MaterialsBudget::PERMISSION));
    $this->drupalGet($readingPath);
    $assert->statusCodeEquals(403);
    $this->drupalGet($dialogPath);
    $assert->statusCodeEquals(403);

    // A user holding the permission reads the JSON: the reading, whole.
    $this->drupalLogin($this->drupalCreateUser([MaterialsBudget::PERMISSION]));
    $reading = $this->reading();
    $this->assertGreaterThan(0, $reading->total, 'The recipe\'s product shares materials with the AI.');
    $this->assertFalse($reading->over);
    $this->drupalGet($readingPath);
    $assert->statusCodeEquals(200);
    $this->assertStringStartsWith('application/json', (string) $this->getSession()->getResponseHeader('Content-Type'));
    $answer = Json::decode($this->getSession()->getPage()->getContent());
    $expected = $reading->toArray();
    $this->assertSame(array_keys($expected), array_keys($answer), 'Exactly the keys BudgetReading::toArray() states, in its order.');
    $this->assertEqualsWithDelta($expected['fraction'], $answer['fraction'], 1e-9);
    unset($expected['fraction'], $answer['fraction']);
    $this->assertSame($expected, $answer);

    // The dialog as a page: the bar, one segment per kind present plus
    // the system, the free remainder, the legend's figures, the budget,
    // the active design system and the word "estimated".
    $this->drupalGet($dialogPath);
    $assert->statusCodeEquals(200);
    $assert->elementExists('css', '.daedalus-ai-context .daedalus-ai-context__bar');
    $assert->elementNotExists('css', '.daedalus-ai-context--over');
    $this->assertSegmentsMatch($reading);
    $assert->elementExists('css', '.daedalus-ai-context__bar [data-kind="free"]');
    $assert->elementExists('css', '.daedalus-ai-context__legend [data-kind="free"]');
    $assert->pageTextContains('estimated');
    $assert->pageTextContains(number_format($reading->budgetTokens) . ' tokens');
    $assert->pageTextContains(number_format($reading->estimatedTokens));
    $this->assertSame('olivero', $this->container->get('daedalus_blueprint.active_design_system')->activeId(), 'The recipe activates Olivero.');
    $assert->pageTextContains('Under the Olivero design system');
    $assert->pageTextNotContains('Over budget');
    $assert->responseContains('daedalus_blueprint/css/ai-context.css');
    $this->assertFormListsTheRoster();

    // Asked for as core's modal (Drupal.ajax with dialogType 'modal'
    // sends _wrapper_format=drupal_modal): an openDialog command on the
    // modal's selector, titled by the route, carrying the bar and the
    // kind sections.
    $this->drupalGet($dialogPath, ['query' => [MainContentViewSubscriber::WRAPPER_FORMAT => 'drupal_modal']]);
    $assert->statusCodeEquals(200);
    $commands = Json::decode($this->getSession()->getPage()->getContent());
    $open = array_values(array_filter($commands, static fn (array $command): bool => $command['command'] === 'openDialog'));
    $this->assertCount(1, $open);
    $this->assertSame('#drupal-modal', $open[0]['selector']);
    $this->assertSame('Design system size', (string) $open[0]['dialogOptions']['title']);
    $this->assertStringContainsString('daedalus-ai-context__bar', $open[0]['data']);
    $this->assertStringContainsString('daedalus-ai-context-form__kind', $open[0]['data']);
    $this->assertStringContainsString('id="' . AiContextForm::WRAPPER_ID . '"', $open[0]['data']);

    // A budget the site already exceeds: the reading flips to over and
    // both doors say so at once (the reading is cached on the settings).
    $this->config(MaterialsBudget::CONFIG_NAME)->set(MaterialsBudget::KEY_BUDGET, 1)->save();
    $reading = $this->reading();
    $this->assertTrue($reading->over);
    $this->drupalGet($readingPath);
    $this->assertTrue(Json::decode($this->getSession()->getPage()->getContent())['over']);
    $this->drupalGet($dialogPath);
    $assert->elementExists('css', '.daedalus-ai-context--over');
    $assert->elementNotExists('css', '.daedalus-ai-context__bar [data-kind="free"]');
    $assert->pageTextContains('Over budget by ' . number_format($reading->estimatedTokens - 1) . ' tokens');
    $this->assertSegmentsMatch($reading);

    // Logged out again, refused again.
    $this->drupalLogout();
    $this->drupalGet($dialogPath);
    $assert->statusCodeEquals(403);
  }

  /**
   * The current reading, from the spine.
   */
  private function reading(): BudgetReading {
    return $this->container->get(MaterialsBudget::class)->reading();
  }

  /**
   * The form under the bar lists the full roster: a section per kind, a box per record.
   *
   * A kind with no record has no section; a kind's section is titled by
   * its plural and count and carries one checkbox per record, checked
   * when the record is offered, disabled when the update gesture may not
   * write it (MaterialRecord::$updatable FALSE, or a kind no provider
   * teaches a gesture for); and the form carries its Save.
   */
  private function assertFormListsTheRoster(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $builder = $this->container->get(MaterialsRosterBuilder::class);
    $roster = $builder->build();
    $assert->elementExists('css', '#' . AiContextForm::WRAPPER_ID . ' form.daedalus-ai-context-form');
    $assert->buttonExists('Save');
    $sections = 0;
    foreach (MaterialKind::cases() as $kind) {
      $records = $roster->ofKind($kind);
      $section = '.daedalus-ai-context-form__kind[data-kind="' . $kind->value . '"]';
      if ($records === []) {
        $assert->elementNotExists('css', $section);
        continue;
      }
      $sections++;
      $assert->elementTextContains('css', $section . ' > summary', sprintf('%s (%d)', ucfirst($kind->plural()), count($records)));
      $assert->elementsCount('css', $section . ' input[type="checkbox"]', count($records));
      $writable = $builder->gestureProvider($kind) !== NULL;
      foreach ($records as $record) {
        $box = $page->find('css', $section . ' input[type="checkbox"][value="' . $record->id . '"]');
        $this->assertNotNull($box, sprintf('The %s "%s" has a box.', $kind->noun(), $record->id));
        $this->assertSame($record->offered, $box->isChecked(), sprintf('The box of %s "%s" reads its offered flag.', $kind->noun(), $record->id));
        $this->assertSame(!$writable || !$record->updatable, $box->hasAttribute('disabled'), sprintf('The box of %s "%s" is enabled exactly when the update gesture may write it.', $kind->noun(), $record->id));
      }
    }
    $this->assertGreaterThan(0, $sections, 'The recipe\'s product carries materials.');
  }

  /**
   * The bar and the legend carry one segment per kind present, and the system.
   */
  private function assertSegmentsMatch(BudgetReading $reading): void {
    $assert = $this->assertSession();
    $present = [];
    foreach (MaterialKind::cases() as $kind) {
      $characters = $reading->of($kind);
      if ($characters > 0) {
        $present[] = $kind->value;
        $assert->elementsCount('css', '.daedalus-ai-context__bar [data-kind="' . $kind->value . '"]', 1);
        $assert->elementTextContains('css', '.daedalus-ai-context__legend [data-kind="' . $kind->value . '"]', number_format($characters) . ' characters');
      }
      else {
        $assert->elementNotExists('css', '.daedalus-ai-context [data-kind="' . $kind->value . '"]');
      }
    }
    $this->assertNotEmpty($present, 'At least one kind is shared.');
    $this->assertGreaterThan(0, $reading->system);
    $assert->elementsCount('css', '.daedalus-ai-context__bar [data-kind="system"]', 1);
    $assert->elementTextContains('css', '.daedalus-ai-context__legend [data-kind="system"]', number_format($reading->system) . ' characters');
  }

}
