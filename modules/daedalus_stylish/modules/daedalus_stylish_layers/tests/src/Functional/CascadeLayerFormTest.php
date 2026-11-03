<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish_layers\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\daedalus_stylish_layers\Layers;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The cascade layer settings form: two keys, one door under Styling.
 *
 * The form (route daedalus_stylish_layers.settings) is the one place a site
 * builder sets daedalus_stylish_layers.settings: whether the pipeline's CSS
 * is wrapped in the cascade layer and what the layer is called. What this
 * test pins is that contract: the page is gated by core's `administer site
 * configuration` (anonymous is refused), it is titled "Cascade layer" and
 * shows both keys at the module's shipped defaults, a name the pipeline
 * would refuse (Layers::isValidLayerName()) is refused with nothing saved, a
 * save round-trips both keys and the page's layer-order statement follows
 * them (gone when disabled, renamed when the name changes), and the Styling
 * sub-category of the Daedalus config category lists the form. The site is a
 * plain install of daedalus_stylish_layers: the form reads and writes one
 * config object and the module depends on the root module alone.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
final class CascadeLayerFormTest extends BrowserTestBase {

  /**
   * The form's path.
   */
  private const PATH = '/admin/config/daedalus/styling/cascade-layer';

  /**
   * The config object the form edits.
   */
  private const SETTINGS = 'daedalus_stylish_layers.settings';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish_layers'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Anonymous is refused; the holder sees the defaults and saves new values.
   */
  public function testSettingsRoundTrip(): void {
    $assert = $this->assertSession();

    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser([
      'administer site configuration',
      'access administration pages',
    ]));

    // The form at its path, titled for its outcome, both keys carrying the
    // module's shipped defaults; the page itself wears the shipped statement.
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $assert->elementTextContains('css', 'title', 'Cascade layer');
    $assert->checkboxChecked('enabled');
    $assert->fieldValueEquals('wrapper_layer', 'site');
    $this->assertLayerStatement('@layer daedalus_stylish_force, site;');

    // A name the pipeline would refuse is refused here and nothing is saved:
    // a dot names a sub-layer, a leading digit is no identifier.
    foreach (['site.theme', '1site', 'my layer'] as $invalid) {
      $this->submitForm(['wrapper_layer' => $invalid], 'Save configuration');
      $assert->pageTextContains($invalid . ' is not a valid cascade layer name.');
      $assert->pageTextNotContains('The configuration options have been saved.');
      $this->assertSame('site', $this->config(self::SETTINGS)->get('wrapper_layer'));
    }
    $this->assertFalse(Layers::isValidLayerName('site.theme'));

    // A save lands both keys, typed as the schema says, and the form reads
    // them back; with layering off the page carries no statement at all.
    $this->submitForm([
      'enabled' => FALSE,
      'wrapper_layer' => ' theme-base ',
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $settings = $this->config(self::SETTINGS);
    $this->assertFalse($settings->get('enabled'));
    $this->assertSame('theme-base', $settings->get('wrapper_layer'));
    $assert->checkboxNotChecked('enabled');
    $assert->fieldValueEquals('wrapper_layer', 'theme-base');
    $this->assertNoLayerStatement();

    // Back on under the new name: the page's statement names it, so what the
    // form stores is what the pipeline emits.
    $this->submitForm(['enabled' => TRUE], 'Save configuration');
    $this->assertTrue($this->config(self::SETTINGS)->get('enabled'));
    $this->assertLayerStatement('@layer daedalus_stylish_force, theme-base;');

    // The one door: the Styling sub-category of the Daedalus config category
    // lists the form.
    $this->drupalGet('/admin/config/daedalus/styling');
    $assert->statusCodeEquals(200);
    $assert->linkByHrefExists(self::PATH);
    $assert->elementExists('xpath', '//a[@href[contains(., "' . self::PATH . '")] and normalize-space() = "Cascade layer"]');
  }

  /**
   * Asserts the current page's head carries the given layer-order statement.
   */
  private function assertLayerStatement(string $statement): void {
    $this->assertSession()->elementExists('xpath', '//head/style[normalize-space() = "' . $statement . '"]');
  }

  /**
   * Asserts the current page's head carries no layer-order statement.
   */
  private function assertNoLayerStatement(): void {
    $this->assertSession()->elementNotExists('xpath', '//head/style[contains(., "@layer ' . Layers::FORCE_LAYER . '")]');
  }

}
