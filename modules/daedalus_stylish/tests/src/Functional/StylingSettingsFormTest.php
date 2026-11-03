<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The styling settings form: four keys, one door under Styling.
 *
 * The form (route daedalus_stylish.settings) is the one place a site builder
 * sets the site mechanics of daedalus_stylish.settings: the AI minting gate,
 * the page-level query container, the image style media in style values
 * resolves through, and the two container breakpoints. What this test pins
 * is that contract: the page is gated by core's `administer site
 * configuration` (anonymous is refused), it is titled "Styling" and shows the
 * four keys with the module's shipped defaults and the site's image styles,
 * a save round-trips every key and leaves the object's other two keys
 * (skin_choice, opt_outs, owned elsewhere) exactly as stored, a medium
 * threshold at or below narrow is refused, and the Styling sub-category of
 * the Daedalus config category lists the form. The site is a plain install
 * of daedalus_stylish (with media_library beside it, see $modules): the form
 * reads and writes one config object, and the recipe would add nothing the
 * subject needs.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
final class StylingSettingsFormTest extends BrowserTestBase {

  /**
   * The form's path.
   */
  private const PATH = '/admin/config/daedalus/styling/settings';

  /**
   * The config object the form edits.
   */
  private const SETTINGS = 'daedalus_stylish.settings';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_stylish',
    // daedalus_stylish's inspector sidebar types its constructor on the
    // media library opener, a class implementing media_library's interface,
    // so a plain install without media_library fatals the moment the
    // sidebar plugin is reflected (the module does not declare the
    // dependency; a gap outside the settings topic). media_library also
    // brings the image module, whose shipped styles fill the select.
    'media_library',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Anonymous is refused; the holder sees the defaults and saves new values.
   */
  public function testSettingsRoundTrip(): void {
    $assert = $this->assertSession();

    // The two keys the form never carries, stored so a save proves it leaves
    // them alone.
    $this->config(self::SETTINGS)
      ->set('skin_choice', ['aurora' => 'dusk'])
      ->set('opt_outs', ['node.article'])
      ->save();

    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser([
      'administer site configuration',
      'access administration pages',
    ]));

    // The form at its path, titled for its outcome, the four keys carrying
    // the module's shipped defaults; the image style select offers the
    // original file and the image module's shipped styles, nothing of
    // skin_choice or opt_outs.
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $assert->elementTextContains('css', 'title', 'Styling');
    $assert->checkboxChecked('ai_minting');
    $assert->checkboxChecked('container_roots');
    $assert->fieldValueEquals('media_image_style', '');
    $assert->optionExists('media_image_style', '- Original file -');
    $assert->optionExists('media_image_style', 'thumbnail');
    $assert->optionExists('media_image_style', 'large');
    $assert->fieldValueEquals('breakpoints[narrow]', '767');
    $assert->fieldValueEquals('breakpoints[medium]', '1023');
    $assert->pageTextContains('Container width at or below which "narrow" styles apply');
    $assert->pageTextContains('the band starts at narrow + 1, so the bands tile exactly');
    $assert->fieldNotExists('skin_choice');
    $assert->fieldNotExists('opt_outs');
    $assert->pageTextNotContains('graphite');
    $assert->pageTextNotContains('node.article');

    // A medium threshold that leaves no room above narrow is refused and
    // nothing is saved.
    $this->submitForm([
      'breakpoints[narrow]' => '900',
      'breakpoints[medium]' => '900',
    ], 'Save configuration');
    $assert->pageTextContains('The medium threshold must be greater than the narrow threshold (900 px).');
    $assert->pageTextNotContains('The configuration options have been saved.');
    $this->assertSame(['narrow' => 767, 'medium' => 1023], $this->config(self::SETTINGS)->get('breakpoints'));

    // A save lands all four keys, typed as the schema says, and the form
    // reads them back; the two keys it never carries survive untouched.
    $this->submitForm([
      'ai_minting' => FALSE,
      'container_roots' => FALSE,
      'media_image_style' => 'thumbnail',
      'breakpoints[narrow]' => '600',
      'breakpoints[medium]' => '900',
    ], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $settings = $this->config(self::SETTINGS);
    $this->assertFalse($settings->get('ai_minting'));
    $this->assertFalse($settings->get('container_roots'));
    $this->assertSame('thumbnail', $settings->get('media_image_style'));
    $this->assertSame(['narrow' => 600, 'medium' => 900], $settings->get('breakpoints'));
    $this->assertSame(['aurora' => 'dusk'], $settings->get('skin_choice'));
    $this->assertSame(['node.article'], $settings->get('opt_outs'));
    $assert->checkboxNotChecked('ai_minting');
    $assert->checkboxNotChecked('container_roots');
    $assert->fieldValueEquals('media_image_style', 'thumbnail');
    $assert->fieldValueEquals('breakpoints[narrow]', '600');
    $assert->fieldValueEquals('breakpoints[medium]', '900');

    // Back to the original file: the empty option stores the empty string
    // the schema and the media resolver read as "no style".
    $this->submitForm(['media_image_style' => ''], 'Save configuration');
    $this->assertSame('', $this->config(self::SETTINGS)->get('media_image_style'));

    // The one door: the Styling sub-category of the Daedalus config category
    // lists the form.
    $this->drupalGet('/admin/config/daedalus/styling');
    $assert->statusCodeEquals(200);
    $assert->linkByHrefExists(self::PATH);
    $assert->elementExists('xpath', '//a[@href[contains(., "' . self::PATH . '")] and normalize-space() = "Styling"]');
  }

}
