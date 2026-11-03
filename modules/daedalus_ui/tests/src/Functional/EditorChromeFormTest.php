<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The editor chrome form: three colors, one door under the UI sub-category.
 *
 * The form (route daedalus_ui.settings) is the one place a site builder sets
 * the colors of the page builder's chrome, and it lives under the Daedalus
 * config category's UI sub-category, the root module's door. What this
 * test pins is that contract: the page is gated by daedalus_ui's own
 * `administer Daedalus UI configuration` (anonymous is refused), it is
 * titled "Editor chrome" and shows the three colors at the top level with
 * the module's shipped defaults, a save round-trips all three into
 * daedalus_ui.settings, and the UI sub-category lists the form as its
 * child. The site is a plain install of daedalus_ui: the form reads and
 * writes one config object, and the recipe's product would add nothing the
 * subject needs.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
final class EditorChromeFormTest extends BrowserTestBase {

  /**
   * The form's path.
   */
  private const PATH = '/admin/config/daedalus/ui/chrome';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_ui'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Anonymous is refused; the holder sees the defaults and saves new colors.
   */
  public function testColorsRoundTrip(): void {
    $assert = $this->assertSession();

    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser([
      'administer Daedalus UI configuration',
      'access administration pages',
    ]));

    // The form at its path, titled for its outcome, the three colors at the
    // top level (no details wrapper: the page is one module's) carrying the
    // module's shipped defaults.
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $assert->elementTextContains('css', 'title', 'Editor chrome');
    $assert->elementNotExists('css', 'form details');
    $assert->fieldValueEquals('colors[main]', '#3a3a3c');
    $assert->fieldValueEquals('colors[secondary]', '#347efe');
    $assert->fieldValueEquals('colors[highlight]', '#16bd00');

    // A save lands all three in daedalus_ui.settings and the form reads them
    // back.
    $this->submitForm([
      'colors[main]' => '#112233',
      'colors[secondary]' => '#445566',
      'colors[highlight]' => '#778899',
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $this->assertSame([
      'main' => '#112233',
      'secondary' => '#445566',
      'highlight' => '#778899',
    ], $this->config('daedalus_ui.settings')->get('colors'));
    $assert->fieldValueEquals('colors[main]', '#112233');
    $assert->fieldValueEquals('colors[secondary]', '#445566');
    $assert->fieldValueEquals('colors[highlight]', '#778899');

    // The one door: the UI sub-category of the Daedalus config category lists
    // the form.
    $this->drupalGet('/admin/config/daedalus/ui');
    $assert->statusCodeEquals(200);
    $assert->linkExists('Editor chrome');
    $assert->linkByHrefExists(self::PATH);
  }

}
