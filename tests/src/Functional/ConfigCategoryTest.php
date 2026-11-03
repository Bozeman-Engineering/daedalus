<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The Daedalus config category: one front door, three sub-categories.
 *
 * The root module ships the door (/admin/config/daedalus) and its AI, UI
 * and Styling sub-categories on core's own category controllers, and
 * nothing else: every settings form joins by parenting a menu link under
 * a sub-category. Core's overview controller shows a sub-category only
 * once it lists a link the actor may reach, so the fixture module hangs
 * one link under each; what this test pins is the door's contract. The
 * door is gated by `access administration pages` alone (each listed form
 * keeps its own gate), so anonymous is refused at every category path and
 * a holder of that one permission sees the door from core's Configuration
 * page, the three sub-categories on it with their children, and each
 * sub-category page listing its own. The site is a plain install of the
 * root module: the door has no dependency, and the recipe's product
 * would add forms this task does not list yet.
 */
#[Group('daedalus')]
#[RunTestsInSeparateProcesses]
final class ConfigCategoryTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus', 'daedalus_config_category_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The sub-categories: route name to the title the door shows.
   */
  private const SUB_CATEGORIES = [
    'daedalus.admin_config_ai' => 'AI',
    'daedalus.admin_config_ui' => 'UI',
    'daedalus.admin_config_styling' => 'Styling',
  ];

  /**
   * The fixture child each sub-category lists, by sub-category route.
   */
  private const CHILDREN = [
    'daedalus.admin_config_ai' => 'AI fixture',
    'daedalus.admin_config_ui' => 'UI fixture',
    'daedalus.admin_config_styling' => 'Styling fixture',
  ];

  /**
   * Anonymous is refused at the door and every sub-category.
   */
  public function testAnonymousIsRefused(): void {
    $assert = $this->assertSession();
    $this->drupalGet(Url::fromRoute('daedalus.admin_config'));
    $assert->statusCodeEquals(403);
    foreach (array_keys(self::SUB_CATEGORIES) as $route) {
      $this->drupalGet(Url::fromRoute($route));
      $assert->statusCodeEquals(403);
    }
  }

  /**
   * A holder of `access administration pages` reaches the door and its parts.
   */
  public function testDoorListsTheSubCategories(): void {
    $assert = $this->assertSession();
    $this->drupalLogin($this->drupalCreateUser(['access administration pages']));

    // Core's Configuration page renders the category as a panel whose
    // links are the three sub-categories, the shape System and Content
    // authoring take there.
    $this->drupalGet(Url::fromRoute('system.admin_config'));
    $assert->statusCodeEquals(200);
    $assert->elementExists('xpath', '//div[contains(@class, "panel")]/h3[contains(@class, "panel__title")][normalize-space() = "Daedalus"]');
    foreach (self::SUB_CATEGORIES as $route => $title) {
      $assert->linkExists($title);
      $assert->linkByHrefExists(Url::fromRoute($route)->toString());
    }

    // The door answers at its path, titled, with the three sub-categories
    // as blocks and each block's child linked.
    $this->drupalGet(Url::fromRoute('daedalus.admin_config'));
    $assert->statusCodeEquals(200);
    $assert->addressEquals('/admin/config/daedalus');
    $assert->titleEquals('Daedalus | Drupal');
    foreach (self::SUB_CATEGORIES as $route => $title) {
      $assert->elementExists('xpath', '//div[contains(@class, "panel")]/h3[contains(@class, "panel__title")][normalize-space() = "' . $title . '"]');
      $assert->linkExists(self::CHILDREN[$route]);
    }
    $assert->pageTextNotContains('You do not have any administrative items.');

    // Each sub-category page lists its own child and none of the others.
    foreach (self::SUB_CATEGORIES as $route => $title) {
      $this->drupalGet(Url::fromRoute($route));
      $assert->statusCodeEquals(200);
      $assert->titleEquals("$title | Drupal");
      $assert->linkExists(self::CHILDREN[$route]);
      foreach (array_diff_key(self::CHILDREN, [$route => NULL]) as $other) {
        $assert->linkNotExists($other);
      }
    }
  }

}
