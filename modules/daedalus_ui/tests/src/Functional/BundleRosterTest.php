<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Functional;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\daedalus_ui\Controller\BundleRoster;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The bundle roster: the page builder's state on every bundle, read-only.
 *
 * The roster (route daedalus_ui.bundle_roster) is the second door to the
 * bundle form's Page builder tab, under the Daedalus config category's UI
 * sub-category and the module's own gate (anonymous is refused). It lists
 * one table per bundle config type the tab serves, one row per bundle,
 * with what the tab would show: on or off, whether new pages open in the
 * page builder (a content type) or how the block is selected (a block
 * type), the columns other modules contribute through BundleRosterEvent,
 * and operations into the bundle form's tab and the manage-display page,
 * only where the actor may go. The contributed columns are the tab's
 * contributed controls mirrored: daedalus_layout's "Starting designs"
 * (On or Off as stored, "n/a" where the bundle's pages carry no layout of
 * their own, no column on a block type, which has no creation group) and
 * daedalus_stylish's "Styling" (whether the style map field exists, on
 * every row); a fixture module answers a third, for the bundles that are
 * on, so a row with no answer gets an empty cell. Nothing on the page
 * writes: the bundle form stays the one write path. The site is a plain
 * install of daedalus_ui, node, field_ui and the two contributing
 * modules: the roster reads bundle config entities and their displays and
 * links to their forms, and the recipe's product would add nothing the
 * subject needs.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
final class BundleRosterTest extends BrowserTestBase {

  /**
   * The roster's path.
   */
  private const PATH = '/admin/config/daedalus/ui/content-types';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'daedalus_ui',
    'node',
    'field_ui',
    'daedalus_layout',
    'daedalus_stylish',
    // daedalus_stylish's inspector sidebar types its constructor on the
    // media library opener, a class implementing media_library's interface,
    // so a plain install without media_library fatals the moment the
    // sidebar plugin is reflected (every page render); the module does not
    // declare the dependency.
    'media_library',
    'daedalus_ui_roster_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Built: on, opens in the page builder, its pages own their layout
    // (Layout Builder overrides on the default display), starting designs
    // offered, styling on.
    NodeType::create([
      'type' => 'built',
      'name' => 'Built',
      'third_party_settings' => [
        'daedalus_ui' => ['enabled' => TRUE, 'initial_mode' => 'edit'],
        'daedalus_layout' => ['offer_starts' => TRUE],
      ],
    ])->save();
    // Owned: its pages own their layout too, but starting designs are not
    // offered: the roster tells Off from n/a.
    NodeType::create([
      'type' => 'owned',
      'name' => 'Owned',
      'third_party_settings' => [
        'daedalus_ui' => ['enabled' => TRUE, 'initial_mode' => 'none'],
        'daedalus_layout' => ['offer_starts' => FALSE],
      ],
    ])->save();
    // Plain: off, no layout of its own, so starting designs are n/a and
    // styling never enabled.
    NodeType::create([
      'type' => 'plain',
      'name' => 'Plain',
      'third_party_settings' => ['daedalus_ui' => ['enabled' => FALSE, 'initial_mode' => 'none']],
    ])->save();
    BlockContentType::create([
      'id' => 'promo',
      'label' => 'Promo',
      'third_party_settings' => ['daedalus_ui' => ['enabled' => TRUE, 'presentation' => 'merged']],
    ])->save();
    $displays = $this->container->get('entity_display.repository');
    foreach (['built', 'owned'] as $type) {
      $displays->getViewDisplay('node', $type)->enableLayoutBuilder()->setOverridable()->save();
    }
    $this->container->get('daedalus_stylish.field_manager')->enable('node', 'built');
  }

  /**
   * Anonymous is refused; the holder sees every bundle's state and its doors.
   */
  public function testRosterListsTheBundles(): void {
    $assert = $this->assertSession();

    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(403);

    // The roster's own gate, plus the structure permissions the operations
    // lead to.
    $this->drupalLogin($this->drupalCreateUser([
      'administer Daedalus UI configuration',
      'access administration pages',
      'administer content types',
      'administer node display',
      'administer block types',
    ]));
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $assert->elementTextContains('css', 'title', 'Page builder on content types');
    $assert->elementNotExists('css', 'form');

    // One table per bundle config type the tab serves, headed by its
    // collection label; the content types' third column is whether new
    // pages open in the page builder, the block types' how the block is
    // selected.
    $assert->elementExists('xpath', '//h2[normalize-space() = "Content types"]');
    $assert->elementExists('xpath', '//h2[normalize-space() = "Block types"]');
    $assert->elementExists('xpath', $this->headerXpath('Content types', 'Opens in page builder'));
    $assert->elementExists('xpath', $this->headerXpath('Block types', 'How the block is selected'));

    // A content type on and one off, each row stating its settings as the
    // tab would.
    $this->assertRow('Built', ['On', 'Yes']);
    $this->assertRow('Owned', ['On', 'No']);
    $this->assertRow('Plain', ['Off', 'No']);
    $this->assertRow('Promo', ['On', 'As one element, merged']);

    // daedalus_layout's column: the stored setting where the bundle's
    // pages own their layout, n/a where they do not, and no column at all
    // on the block types (no creation group on their tab).
    $assert->elementExists('xpath', $this->headerXpath('Content types', 'Starting designs'));
    $assert->elementNotExists('xpath', $this->headerXpath('Block types', 'Starting designs'));
    $assert->elementTextEquals('xpath', $this->contributedCellXpath('Content types', 'Built', 'Starting designs'), 'On');
    $assert->elementTextEquals('xpath', $this->contributedCellXpath('Content types', 'Owned', 'Starting designs'), 'Off');
    $assert->elementTextEquals('xpath', $this->contributedCellXpath('Content types', 'Plain', 'Starting designs'), 'n/a');

    // daedalus_stylish's column, on every row: the style map field exists
    // for Built alone.
    $assert->elementExists('xpath', $this->headerXpath('Content types', 'Styling'));
    $assert->elementExists('xpath', $this->headerXpath('Block types', 'Styling'));
    $assert->elementTextEquals('xpath', $this->contributedCellXpath('Content types', 'Built', 'Styling'), 'On');
    $assert->elementTextEquals('xpath', $this->contributedCellXpath('Content types', 'Plain', 'Styling'), 'Off');
    $assert->elementTextEquals('xpath', $this->contributedCellXpath('Block types', 'Promo', 'Styling'), 'Off');

    // The fixture's column: it answers for the bundles that are on, so its
    // header is on the content types' table, the Built row carries its
    // cell and the Plain row an empty one.
    $assert->elementExists('xpath', $this->headerXpath('Content types', 'Fixture'));
    $assert->elementTextEquals('xpath', $this->contributedCellXpath('Content types', 'Built', 'Fixture'), 'Fixture built');
    $assert->elementTextEquals('xpath', $this->contributedCellXpath('Content types', 'Plain', 'Fixture'), '');
    $assert->elementTextEquals('xpath', $this->contributedCellXpath('Block types', 'Promo', 'Fixture'), 'Fixture promo');

    // Operations: the bundle form's Page builder tab (the one write path,
    // reached by the tab's fragment) and the manage-display page.
    $built = NodeType::load('built');
    $promo = BlockContentType::load('promo');
    $assert->linkByHrefExists($built->toUrl('edit-form')->toString() . '#' . BundleRoster::TAB_FRAGMENT);
    $assert->linkByHrefExists(NodeType::load('plain')->toUrl('edit-form')->toString() . '#' . BundleRoster::TAB_FRAGMENT);
    $assert->linkByHrefExists('/admin/structure/types/manage/built/display');
    $assert->linkByHrefExists($promo->toUrl('edit-form')->toString() . '#' . BundleRoster::TAB_FRAGMENT);

    // The door: the UI sub-category lists the roster beside the chrome
    // form.
    $this->drupalGet('/admin/config/daedalus/ui');
    $assert->statusCodeEquals(200);
    $assert->linkExists('Page builder on content types');
    $assert->linkByHrefExists(self::PATH);
    $assert->linkExists('Editor chrome');

    // The roster's gate alone reads the states but reaches no form: the
    // operations show only where the actor may go.
    $this->drupalLogin($this->drupalCreateUser([
      'administer Daedalus UI configuration',
      'access administration pages',
    ]));
    $this->drupalGet(self::PATH);
    $assert->statusCodeEquals(200);
    $this->assertRow('Built', ['On', 'Yes']);
    $assert->linkByHrefNotExists($built->toUrl('edit-form')->toString());
    $assert->linkByHrefNotExists('/admin/structure/types/manage/built/display');
    $assert->linkByHrefNotExists($promo->toUrl('edit-form')->toString());
  }

  /**
   * Asserts a row's state cells, after its label.
   *
   * @param string $label
   *   The bundle label in the row's first cell.
   * @param string[] $cells
   *   The expected texts of the cells that follow it.
   */
  private function assertRow(string $label, array $cells): void {
    foreach ($cells as $offset => $text) {
      $this->assertSession()->elementTextEquals('xpath', $this->cellXpath($label, $offset + 2), $text);
    }
  }

  /**
   * The xpath of a cell in the row whose first cell is the label.
   */
  private function cellXpath(string $label, int $position): string {
    return sprintf('//table//tr[td[1][normalize-space() = "%s"]]/td[%d]', $label, $position);
  }

  /**
   * The xpath of a header cell in the table under a bundle type's heading.
   */
  private function headerXpath(string $table, string $header): string {
    return sprintf('//h2[normalize-space() = "%s"]/following-sibling::table//th[normalize-space() = "%s"]', $table, $header);
  }

  /**
   * The xpath of a contributed cell, by its table, row label and header.
   *
   * The contributed columns come in the subscribers' order, which no test
   * pins, so a cell is found by its header's position in its own table.
   */
  private function contributedCellXpath(string $table, string $label, string $header): string {
    return sprintf(
      '//h2[normalize-space() = "%s"]/following-sibling::table//tr[td[1][normalize-space() = "%s"]]/td[position() = count(ancestor::table//th[normalize-space() = "%s"]/preceding-sibling::th) + 1]',
      $table,
      $label,
      $header,
    );
  }

}
