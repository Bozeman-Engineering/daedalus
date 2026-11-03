<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Functional;

use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Edit Mode top bar's server render: the quiet bar.
 *
 * Edit::buildTopBar() renders three zones. Left: the Daedalus menu trigger
 * (the mark, the label, a caret; a real button with aria-haspopup, hidden
 * at clean density like a panel toggle), then the left-docked panels'
 * toggles. Middle: the document sentence (the host's label, and a kind
 * chip only when the document is not a page: a template, a blueprint, the
 * site frame) and Save, the one unsaved-changes indicator, whose
 * server-rendered state reads the tempstore. Right: the right-docked
 * toggles, then the exit control, "Done", rendered by
 * ModePluginBase::buildModeExitButton() beside the entry core's bar keeps.
 * Refresh, Discard and the Save caret are gone from the bar: they are rows
 * of the menu. The menu's boot data rides drupalSettings.daedalusUi.topBar:
 * the panels of the mode and the settings door, delivered only to a user
 * who may reach it. This tier sees all of it in the served html; no
 * browser is needed.
 */
#[Group('daedalus_ui')]
class TopBarTest extends BrowserTestBase {

  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * Core's manage-display layout route for the Page type: a template host.
   */
  private const TEMPLATE_PATH = '/admin/structure/types/manage/page/display/default/layout';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
    // The template route is field_ui's; the recipe does not enable it.
    \Drupal::service('module_installer')->install(['field_ui']);
    $this->rebuildContainer();
  }

  /**
   * The bar's zones, Save's state, the settings door and the removed ids.
   */
  public function testTopBarRender(): void {
    $assert_session = $this->assertSession();
    $bar = '#edit-mode-top-bar';

    $node = Node::create([
      'type' => 'page',
      'title' => 'Top bar page',
    ]);
    $node->save();
    $tempstore = \Drupal::service('daedalus_tempstore.entity_tempstore_repository');
    $switcher = \Drupal::service('account_switcher');

    // ——— An administrator on a page, in mode (cookie-rendered arrival). ———
    $admin = $this->drupalCreateUser([], NULL, TRUE);
    $this->drupalLogin($admin);
    $this->getSession()->setCookie('navigationMode', 'edit');
    $this->drupalGet($node->toUrl());
    $assert_session->elementExists('css', 'html[data-daedalus-ui-mode="edit"]');

    // The menu trigger leads the left zone: a button that announces its
    // menu, wearing the mark, the label and the caret, concealing at clean.
    $trigger = $assert_session->elementExists('css', "$bar #edit-mode-left-toggles > button#daedalus-ui-menu:first-child");
    $this->assertSame('menu', $trigger->getAttribute('aria-haspopup'));
    $this->assertSame('false', $trigger->getAttribute('aria-expanded'));
    $this->assertSame('clean', $trigger->getAttribute('data-daedalus-ui-conceal'));
    $this->assertSame('Daedalus', $trigger->getAttribute('data-daedalus-ui-tooltip'));
    $assert_session->elementExists('css', 'svg.toolbar-button__icon', $trigger);
    $assert_session->elementExists('css', 'svg.toolbar-button__caret', $trigger);
    $assert_session->elementTextEquals('css', '#daedalus-ui-menu .toolbar-button__label', 'Daedalus');
    // The left toggles follow it, still concealing at clean.
    $assert_session->elementExists('css', "$bar #edit-mode-left-toggles > #daedalus-ui-menu ~ [data-sidebar-button-for=\"#page_elements\"][data-daedalus-ui-conceal=\"clean\"]");

    // The document sentence: the page's title and no kind chip (a page is
    // almost always the kind, so it says nothing).
    $assert_session->elementTextEquals('css', "$bar #edit-mode-global-top-bar #daedalus-ui-document .daedalus-ui-document__title", 'Top bar page');
    $assert_session->elementNotExists('css', "$bar #daedalus-ui-document .daedalus-ui-kind");

    // Save: a plain button with the id every test drives, never concealing,
    // reading saved on a page nobody has changed. The seam: the canvas
    // render itself staged the page (core's PrepareLayout seeds the layout
    // tempstore on first sight), so the tempstore's has() is already true;
    // the bar reads the user's history timeline, which that bookkeeping
    // write never enters.
    $save = $assert_session->elementExists('css', "$bar #edit-mode-global-top-bar > button#daedalus-ui-save");
    $this->assertSame('true', $save->getAttribute('data-daedalus-ui-saved'));
    $this->assertSame('Saved', trim($save->getText()));
    $this->assertSame('Commit the staged changes', $save->getAttribute('data-daedalus-ui-tooltip'));
    $this->assertNull($save->getAttribute('data-daedalus-ui-conceal'));
    $switcher->switchTo($admin);
    $this->assertTrue($tempstore->has($node), 'The in-mode render staged the page without a user change.');
    $switcher->switchBack();

    // Done: the exit control closes the right zone, carrying the pair's
    // class and data-mode (active, like the entry, in mode), never
    // concealing; the entry in core's bar still says what you enter.
    $done = $assert_session->elementExists('css', "$bar #edit-mode-right-toggles > button.daedalus-ui-mode-button.toolbar-button:last-child");
    $this->assertSame('edit', $done->getAttribute('data-mode'));
    $this->assertSame('Done', trim($done->getText()));
    $this->assertSame('Exit Edit Mode', $done->getAttribute('aria-label'));
    $this->assertTrue($done->hasClass('active'));
    $this->assertNull($done->getAttribute('data-daedalus-ui-conceal'));
    $entry = $assert_session->elementExists('css', 'a.daedalus-ui-mode-button[data-mode="edit"]');
    $this->assertSame('Edit Mode', trim($entry->getText()));

    // The controls the menu absorbed are gone from the bar.
    $gone = [
      '#daedalus-ui-refresh',
      '#daedalus-ui-discard-changes',
      '#daedalus-ui-save-menu',
      "$bar .daedalus-ui-split-button",
    ];
    foreach ($gone as $selector) {
      $assert_session->elementNotExists('css', $selector);
    }

    // The menu's boot data: every panel of the mode with its side, and the
    // settings door for a user who may walk through it.
    $top_bar = $this->getDrupalSettings()['daedalusUi']['topBar'];
    $this->assertStringEndsWith('/admin/config/daedalus', $top_bar['settingsUrl']);
    $panels = array_column($top_bar['panels'], NULL, 'id');
    $this->assertSame([
      'id' => 'page_elements',
      'label' => 'Page elements',
      'side' => 'left',
    ], $panels['page_elements']);
    $this->assertSame('right', $panels['edit_mode_settings']['side']);
    foreach ($top_bar['panels'] as $panel) {
      $this->assertSame(['id', 'label', 'side'], array_keys($panel));
    }

    // ——— The same administrator on the template route. ———
    // The kind chip names the document a template; Save carries its state.
    $this->drupalGet(self::TEMPLATE_PATH);
    $assert_session->elementExists('css', 'html[data-daedalus-ui-mode="edit"]');
    $assert_session->elementTextEquals('css', "$bar #daedalus-ui-document .daedalus-ui-kind", 'Template');
    $assert_session->elementExists('css', "$bar #daedalus-ui-save[data-daedalus-ui-saved]");
    $this->getSession()->setCookie('navigationMode', NULL);

    // ——— The editor: no settings door; the changed page reads unsaved. ———
    $editor = $this->loginAsEditor();
    // Stage a change as every mutation path lands one: a strategy set()
    // (the history tee records it) and the request-end flush the blueprint
    // endpoint and the response subscriber perform. The timeline and the
    // tempstore owner are the current user, so the write happens as the
    // editor.
    $switcher->switchTo($editor);
    $tempstore->set($node);
    \Drupal::service('daedalus_tempstore.history_recorder')->flush();
    $switcher->switchBack();

    $this->getSession()->setCookie('navigationMode', 'edit');
    $this->drupalGet($node->toUrl());
    $assert_session->elementExists('css', 'html[data-daedalus-ui-mode="edit"]');
    $save = $assert_session->elementExists('css', "$bar #daedalus-ui-save");
    $this->assertSame('false', $save->getAttribute('data-daedalus-ui-saved'));
    $this->assertSame('Save changes', trim($save->getText()));
    $top_bar = $this->getDrupalSettings()['daedalusUi']['topBar'];
    $this->assertArrayHasKey('settingsUrl', $top_bar);
    $this->assertNull($top_bar['settingsUrl']);
    $this->assertSame('left', array_column($top_bar['panels'], 'side', 'id')['page_elements']);
  }

}
