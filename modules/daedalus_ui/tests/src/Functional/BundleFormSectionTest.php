<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Functional;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The bundle form's Page builder section, as one section named by outcome.
 *
 * Daedalus UI owns the vertical tab: a leading switch (the bundle's
 * `enabled` boolean, saved with the form) and groups named by what a
 * setting does, never by the module that owns it. On a content type the
 * groups are the layout (a fact with a link), when a page is created (the
 * initial mode as one checkbox) and styling (daedalus_stylish's box, with
 * the count of what clearing it deletes); on a block type the Add panel
 * (daedalus_layout's icon), the page (the presentation) and styling. Every
 * group hides while the switch is off (core's #states, pinned here as the
 * attribute it rides; the hiding itself is core's). A bundle of a third
 * entity type gets the same section because its bundle config type names
 * the settings schema. This tier sees the served form and the stored
 * settings; no browser is needed because every control saves with the
 * form.
 *
 * Runs as uid 1 by explicit exception: the subject is the structure forms
 * (content types, block types, vocabularies), which the editor role the
 * recipe ships has no business reaching.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class BundleFormSectionTest extends BrowserTestBase {

  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
    // A third bundle entity type (vocabularies) and the manage-display
    // routes the layout fact links to; neither is part of the recipe.
    \Drupal::service('module_installer')->install(['taxonomy', 'field_ui']);
    $this->rebuildContainer();
    $this->drupalLogin($this->rootUser);
  }

  /**
   * A content type: the switch, the three groups, each control round trips.
   */
  public function testContentTypeSection(): void {
    $assert_session = $this->assertSession();
    $field_manager = $this->fieldManager();
    $this->assertTrue($field_manager->isEnabled('node', 'page'), 'The recipe opens styling on the Page bundle.');
    foreach (['Styled one', 'Styled two'] as $title) {
      $node = Node::create(['type' => 'page', 'title' => $title]);
      $node->get(DaedalusStylishFieldManager::FIELD_NAME)->appendItem()->setStyleMap([
        'block:a1b2c3' => ['default' => ['padding' => '2rem']],
      ]);
      $node->save();
    }
    Node::create(['type' => 'page', 'title' => 'Unstyled'])->save();
    // The recipe's demo page stores styles too: the form shows the
    // manager's count, whatever the site holds.
    $styled = $field_manager->countStyled('node', 'page');
    $this->assertGreaterThanOrEqual(2, $styled);

    $this->drupalGet('/admin/structure/types/manage/page');
    $assert_session->statusCodeEquals(200);

    // No section named after a module.
    $assert_session->elementNotExists('css', '#edit-page-builder details');
    $assert_session->pageTextNotContains('Daedalus UI');
    $assert_session->pageTextNotContains('Daedalus Stylish');

    // The leading switch, checked as the recipe ships it, then the groups
    // in the mock's order, each hidden while the switch is off.
    $assert_session->checkboxChecked('daedalus_ui_enabled');
    $assert_session->elementTextContains('css', 'label[for="edit-daedalus-ui-enabled"]', 'Build Page content in the page builder');
    $this->assertSame(['Layout', 'When a page is created', 'Styling'], $this->groupLegends());
    foreach (['layout', 'creation', 'styling'] as $group) {
      $fieldset = $assert_session->elementExists('css', "#edit-page-builder fieldset[data-daedalus-ui-group=\"$group\"]");
      $this->assertStringContainsString('daedalus_ui_enabled', (string) $fieldset->getAttribute('data-drupal-states'));
    }

    // The layout is a fact with a link: the recipe's pages own their layout.
    $assert_session->elementTextContains('css', '[data-daedalus-ui-group="layout"]', 'Each page has its own layout, starting from the template.');
    $template_link = $assert_session->elementExists('named', ['link', 'Edit the template']);
    $this->assertStringContainsString('/admin/structure/types/manage/page/display/default/layout', $template_link->getAttribute('href'));
    $assert_session->elementNotExists('css', '[data-daedalus-ui-group="layout"] input');

    // The initial mode is one checkbox, in the creation group.
    $initial_mode = $assert_session->elementExists('css', '[data-daedalus-ui-group="creation"] input[name="initial_mode"]');
    $this->assertTrue($initial_mode->isChecked());
    $assert_session->elementTextContains('css', 'label[for="edit-initial-mode"]', 'Open it in the page builder');
    $assert_session->fieldNotExists('initial_mode[edit]');

    // Styling's box, in its group, and the warning with its count, shown
    // only when the box is cleared.
    $styling = $assert_session->elementExists('css', '[data-daedalus-ui-group="styling"] input[name="daedalus_stylish_enabled"]');
    $this->assertTrue($styling->isChecked());
    $this->assertSame('Styling on', $styling->getAttribute('data-daedalus-ui-summary'));
    $warning = $assert_session->elementExists('css', '[data-daedalus-ui-group="styling"] .messages--warning');
    $this->assertStringContainsString("Saving with this off deletes the styles stored on $styled existing content items. This cannot be undone.", $warning->getText());
    $this->assertStringContainsString('daedalus_stylish_enabled', (string) $warning->getAttribute('data-drupal-states'));
    $this->assertStringContainsString('"checked":false', (string) $warning->getAttribute('data-drupal-states'));

    // The summary script rides the section.
    $assert_session->responseContains('daedalus_ui/js/bundle-form.js');

    // Clearing the creation box and the styling box, saved with the form:
    // the mode is stored as none, the style field is gone with its data.
    $this->submitForm(['initial_mode' => FALSE, 'daedalus_stylish_enabled' => FALSE], 'Save');
    $assert_session->statusCodeEquals(200);
    $node_type = NodeType::load('page');
    $this->assertTrue($node_type->getThirdPartySetting('daedalus_ui', 'enabled'));
    $this->assertSame('none', $node_type->getThirdPartySetting('daedalus_ui', 'initial_mode'));
    $this->assertFalse($field_manager->isEnabled('node', 'page'));
    $this->assertSame(0, $field_manager->countStyled('node', 'page'));

    // Off: the switch saved off, and the form shows it unchecked with the
    // groups still riding the states attribute that hides them.
    $this->drupalGet('/admin/structure/types/manage/page');
    $assert_session->checkboxNotChecked('initial_mode');
    $assert_session->checkboxNotChecked('daedalus_stylish_enabled');
    $assert_session->elementNotExists('css', '[data-daedalus-ui-group="styling"] .messages--warning');
    $this->submitForm(['daedalus_ui_enabled' => FALSE], 'Save');
    $this->assertFalse(NodeType::load('page')->getThirdPartySetting('daedalus_ui', 'enabled'));
    $this->drupalGet('/admin/structure/types/manage/page');
    $assert_session->checkboxNotChecked('daedalus_ui_enabled');
    $this->assertSame(['Layout', 'When a page is created', 'Styling'], $this->groupLegends());

    // Back on, every control at once: the mode id, the boolean, the field.
    $this->submitForm([
      'daedalus_ui_enabled' => TRUE,
      'initial_mode' => TRUE,
      'daedalus_stylish_enabled' => TRUE,
    ], 'Save');
    $node_type = NodeType::load('page');
    $this->assertTrue($node_type->getThirdPartySetting('daedalus_ui', 'enabled'));
    $this->assertSame('edit', $node_type->getThirdPartySetting('daedalus_ui', 'initial_mode'));
    $this->assertTrue($field_manager->isEnabled('node', 'page'));
    // Clearing a box without styled content shows no warning: nothing is
    // lost by the save.
    $this->drupalGet('/admin/structure/types/manage/page');
    $assert_session->elementNotExists('css', '[data-daedalus-ui-group="styling"] .messages--warning');
  }

  /**
   * A block type: the Add panel, the page and styling; no creation group.
   */
  public function testBlockTypeSection(): void {
    $assert_session = $this->assertSession();
    $this->drupalGet('/admin/structure/block-content/manage/text');
    $assert_session->statusCodeEquals(200);

    $assert_session->elementNotExists('css', '#edit-page-builder details');
    $assert_session->pageTextNotContains('Daedalus Layout');
    $assert_session->elementTextContains('css', 'label[for="edit-daedalus-ui-enabled"]', 'Use Text blocks in the page builder');
    $this->assertSame(['In the Add panel', 'On the page', 'Styling'], $this->groupLegends());
    $assert_session->fieldNotExists('initial_mode');

    // The Add panel group's controls: the icon picker and the favorite
    // box; the recipe declares no group and the type stores none, so no
    // groups control renders.
    $assert_session->elementExists('css', '[data-daedalus-ui-group="add_panel"] .daedalus-layout-icon-picker__preview');
    $assert_session->elementTextContains('css', '[data-daedalus-ui-group="add_panel"]', 'Icon');
    $assert_session->elementExists('css', '[data-daedalus-ui-group="add_panel"] input[name="daedalus_layout_favorite"]');
    $assert_session->elementNotExists('css', '[data-daedalus-ui-group="add_panel"] input[name^="daedalus_layout_groups"]');

    // The presentation, as the shipped block type stores it, in the page
    // group with the mock's four choices.
    $assert_session->elementExists('css', '[data-daedalus-ui-group="on_page"] input[name="presentation"]');
    $assert_session->elementTextContains('css', '[data-daedalus-ui-group="on_page"]', 'How the block is selected');
    $this->assertSame('merged', BlockContentType::load('text')->getThirdPartySetting('daedalus_ui', 'presentation'));
    $assert_session->fieldValueEquals('presentation', 'merged');
    foreach (['Decide automatically', 'As one element', 'As one element, merged', 'Show its structure'] as $choice) {
      $assert_session->elementTextContains('css', '[data-daedalus-ui-group="on_page"]', $choice);
    }
    $this->assertSame('Selected as one element, merged', $assert_session->elementExists('css', 'input[name="presentation"][value="merged"]')->getAttribute('data-daedalus-ui-summary'));
    $assert_session->elementExists('css', '[data-daedalus-ui-group="styling"] input[name="daedalus_stylish_enabled"]');

    // Each control saved with the form and read back.
    $this->submitForm(['daedalus_ui_enabled' => TRUE, 'presentation' => 'unit'], 'Save');
    $block_type = BlockContentType::load('text');
    $this->assertTrue($block_type->getThirdPartySetting('daedalus_ui', 'enabled'));
    $this->assertSame('unit', $block_type->getThirdPartySetting('daedalus_ui', 'presentation'));
    $this->assertNull($block_type->getThirdPartySetting('daedalus_ui', 'initial_mode'), 'A block type never gains an initial mode it has no control for.');
    $this->assertStringContainsString('text.svg', $block_type->getThirdPartySetting('daedalus_layout', 'icon'), 'The shipped icon survives a save of the form.');

    $this->drupalGet('/admin/structure/block-content/manage/text');
    $this->submitForm(['daedalus_ui_enabled' => FALSE], 'Save');
    $this->assertFalse(BlockContentType::load('text')->getThirdPartySetting('daedalus_ui', 'enabled'));
  }

  /**
   * A block type's face: favorite and groups save and read back.
   *
   * The favorite box and the group checkboxes sit in the Add panel group
   * beside the icon, rendered as the blueprint details dialog renders
   * them: the declared groups in declaration order, plus any stored id
   * the site no longer declares, checked and labelled by its id, so a
   * save of the form never drops it. Each key's absence is its default.
   */
  public function testBlockTypeFace(): void {
    $assert_session = $this->assertSession();
    // The site declares heroes and bands; the type stores one of them
    // beside an id nothing declares any more.
    \Drupal::service('module_installer')->install(['daedalus_layout_site_groups_test']);
    $this->rebuildContainer();
    BlockContentType::load('text')
      ->setThirdPartySetting('daedalus_layout', 'groups', ['heroes', 'retired'])
      ->save();

    $this->drupalGet('/admin/structure/block-content/manage/text');
    $assert_session->statusCodeEquals(200);
    $this->assertTrue(BlockContentType::load('text')->getThirdPartySetting('daedalus_layout', 'favorite'), 'The recipe ships the text block type as a favorite.');
    $assert_session->checkboxChecked('daedalus_layout_favorite');
    $assert_session->elementTextContains('css', 'label[for="edit-daedalus-layout-favorite"]', 'Favorite');
    $assert_session->elementTextContains('css', '[data-daedalus-ui-group="add_panel"]', 'Groups');
    $assert_session->checkboxChecked('daedalus_layout_groups[heroes]');
    $assert_session->checkboxNotChecked('daedalus_layout_groups[bands]');
    $assert_session->checkboxChecked('daedalus_layout_groups[retired]');
    $assert_session->elementTextContains('css', 'label[for="edit-daedalus-layout-groups-heroes"]', 'Heroes');
    $assert_session->elementTextContains('css', 'label[for="edit-daedalus-layout-groups-bands"]', 'Bands');
    $assert_session->elementTextContains('css', 'label[for="edit-daedalus-layout-groups-retired"]', 'retired');
    $this->assertSame(
      ['daedalus_layout_groups[heroes]', 'daedalus_layout_groups[bands]', 'daedalus_layout_groups[retired]'],
      array_map(static fn($input) => $input->getAttribute('name'), $this->getSession()->getPage()->findAll('css', '[data-daedalus-ui-group="add_panel"] input[name^="daedalus_layout_groups"]')),
      'The declared groups lead in declaration order; the undeclared stored id follows.',
    );

    // Clearing the favorite and filing under a second group: the
    // undeclared id survives the save untouched.
    $this->submitForm(['daedalus_layout_favorite' => FALSE, 'daedalus_layout_groups[bands]' => TRUE], 'Save');
    $assert_session->statusCodeEquals(200);
    $block_type = BlockContentType::load('text');
    $this->assertNull($block_type->getThirdPartySetting('daedalus_layout', 'favorite'), 'Not a favorite is the key\'s absence.');
    $this->assertSame(['heroes', 'bands', 'retired'], $block_type->getThirdPartySetting('daedalus_layout', 'groups'));
    $this->assertStringContainsString('text.svg', $block_type->getThirdPartySetting('daedalus_layout', 'icon'), 'The shipped icon survives.');

    // Back to a favorite, out of every group: the list is the key's absence.
    $this->drupalGet('/admin/structure/block-content/manage/text');
    $assert_session->checkboxNotChecked('daedalus_layout_favorite');
    $assert_session->checkboxChecked('daedalus_layout_groups[bands]');
    $this->submitForm([
      'daedalus_layout_favorite' => TRUE,
      'daedalus_layout_groups[heroes]' => FALSE,
      'daedalus_layout_groups[bands]' => FALSE,
      'daedalus_layout_groups[retired]' => FALSE,
    ], 'Save');
    $block_type = BlockContentType::load('text');
    $this->assertTrue($block_type->getThirdPartySetting('daedalus_layout', 'favorite'));
    $this->assertNull($block_type->getThirdPartySetting('daedalus_layout', 'groups'));

    // With nothing stored and the vocabulary still declared, the control
    // offers the declared groups alone.
    $this->drupalGet('/admin/structure/block-content/manage/text');
    $assert_session->checkboxChecked('daedalus_layout_favorite');
    $assert_session->fieldExists('daedalus_layout_groups[heroes]');
    $assert_session->fieldNotExists('daedalus_layout_groups[retired]');
  }

  /**
   * A bundle of a third entity type: a vocabulary gets the same section.
   */
  public function testThirdEntityTypeBundle(): void {
    $assert_session = $this->assertSession();
    Vocabulary::create(['vid' => 'topics', 'name' => 'Topics'])->save();
    $this->drupalGet('/admin/structure/taxonomy/manage/topics');
    $assert_session->statusCodeEquals(200);

    $assert_session->checkboxNotChecked('daedalus_ui_enabled');
    $assert_session->elementTextContains('css', 'label[for="edit-daedalus-ui-enabled"]', 'Build Topics content in the page builder');
    $this->assertSame(['Layout', 'When a page is created', 'Styling'], $this->groupLegends());
    // Its terms have no Layout Builder display: the fact says so and links
    // to the display, where Layout Builder is turned on.
    $assert_session->elementTextContains('css', '[data-daedalus-ui-group="layout"]', 'Pages of this type have no layout to build: Layout Builder is off on their display.');
    $display_link = $assert_session->elementExists('named', ['link', 'Manage the display']);
    $this->assertStringContainsString('/admin/structure/taxonomy/manage/topics/overview/display', $display_link->getAttribute('href'));
    $assert_session->checkboxNotChecked('initial_mode');

    $this->submitForm(['daedalus_ui_enabled' => TRUE, 'initial_mode' => TRUE], 'Save');
    $vocabulary = Vocabulary::load('topics');
    $this->assertTrue($vocabulary->getThirdPartySetting('daedalus_ui', 'enabled'));
    $this->assertSame('edit', $vocabulary->getThirdPartySetting('daedalus_ui', 'initial_mode'));
  }

  /**
   * The legends of the section's groups, in page order.
   *
   * @return string[]
   *   The legend texts.
   */
  protected function groupLegends(): array {
    $legends = $this->getSession()->getPage()->findAll('css', '#edit-page-builder fieldset.daedalus-ui-bundle-group > legend');
    return array_map(static fn($legend) => trim($legend->getText()), $legends);
  }

  /**
   * The style field manager, from the current container.
   */
  protected function fieldManager(): DaedalusStylishFieldManager {
    return \Drupal::service('daedalus_stylish.field_manager');
  }

}
