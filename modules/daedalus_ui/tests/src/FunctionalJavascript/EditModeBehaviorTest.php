<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\block_content\Entity\BlockContent;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives daedalus_ui's Edit Mode session on a real page.
 *
 * daedalus_ui is the UI framework the rest of the Daedalus plugs into: a
 * mode system and the chrome (sidebars, top bar) that hosts it, over ONE
 * pointer and ONE keyboard grammar on the one selection. This test carries
 * the framework's wiring end to end: the two doors into Edit Mode (the
 * toggle button's enable/persist/disable cycle and the cookie-arrival
 * render), the hotkey system (the binding registry, remapping, the settings
 * tray), selection, the keyboard model, editing as a state of the
 * selection, history, and the canvas vector.
 *
 * The scenario is deliberately cross-module: editing is daedalus_edit's
 * engine (registered through the app's edit-session facade), while the
 * gestures that open, walk and end an edit are daedalus_ui's: Enter on the
 * selection, a double-click at a leaf, Escape's one ladder, Cmd+Enter's
 * commit, the tree row's Enter (a landing, a placement included, never
 * opens an editor; only these gestures do). A key
 * pressed in daedalus_ui's dispatcher reaching daedalus_edit's editor is
 * exactly what daedalus_ui exists to provide.
 *
 * The fixture is DaedalusFixtureTrait: the site the daedalus recipe
 * installs (no profile), daedalus_edit_test_fixture's non-LB 'plain' bundle
 * installed on top, and the whole scenario run as daedalus_editor. The
 * recipe is daedalus_ui's require-dev dependency, the point of the
 * per-module harness: a contributor's merge request exercises the real
 * recipe site with the machinery-derived fixture bundle (which installs
 * daedalus_edit's editing engine). daedalus_ui is a page-builder module,
 * so it requires the base daedalus, not the AI superset.
 *
 * One fixture, one scenario. A full site install from the recipe is by far
 * the most expensive part of a run (the framework installs and tears down
 * per method), so all behavior lives in the single test method. The recipe
 * ships its footer as a site_chrome blueprint shown by daedalus_theme's
 * site frame (the footer slot's default): a foreign, attributed host rendered around
 * the node page, for the editing-universe step (the editor door and the Tab
 * walk ignore what the root blueprint's projection does not contain). The
 * portal step links a section blueprint of the test's own into a page-built
 * node, the one foreign host the editor role may open.
 *
 * Step order is cookie-driven. The toggle cycle runs FIRST: its enable half
 * needs a page arriving outside Edit Mode, which an API-created node gives
 * (no post-creation redirect, so no InitialMode cookies) as long as no
 * domain-wide cookie has been set yet. The cookie-arrival render runs LAST
 * for the same reason: enterEditModeViaCookies() sets domain-wide cookies
 * (Mink's setCookie has no path), which would put every later page load
 * into Edit Mode on arrival.
 *
 * @group daedalus_ui
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class EditModeBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait;

  /**
   * The footer's "Pro tip" text component's UUID inside the footer's layout.
   *
   * The inline_block:text ("Pro tip: to edit things quickly, use tab to move
   * through elements.", inline block 42ed7027) that is the only component of
   * the Pro tip band (01c25804), the second band nested under the footer's
   * one root, in the daedalus recipe's site chrome content (the `footer`
   * blueprint the frame places linked after the main slot; a block blueprint
   * holds one root and its bands nest under it). The test installs from the
   * recipe on disk, so a
   * recipe content change that drops this component fails the
   * editing-universe step below: re-pin it to the footer's LAST attributed
   * block leaf, because the Tab step depends on that position. (The
   * chip-face legs run on the page-built page's own block: the footer is
   * outside the editing universe, so the one selection gate never lands
   * it.)
   */
  private const FOOTER_COMPONENT_UUID = '1832e9b4-502a-4711-a5df-4c62de02d13f';

  /**
   * The Edit Mode session: entry, hotkeys, selection, editing, history.
   *
   * One fixture (the applied base recipe) drives daedalus_ui's Edit Mode
   * wiring end to end:
   * - The toggle: clicking the Edit Mode toggle button AJAX-enables Edit
   *   Mode on a page that arrived outside it, the choice persists across a
   *   reload (server-rendered from the path-scoped cookie), and clicking the
   *   toggle again AJAX-disables it.
   * - Edit Mode + hotkeys: a node is opened in Edit Mode through the mode
   *   manager (the JS-entry path, which reliably activates Edit Mode in the JS
   *   runtime and enables the keydown hotkey listener). The hotkey map
   *   (drupalSettings.daedalusUi.hotKeys) is populated from the sidebar
   *   plugins' hotkeys, and a panel binding remapped in the settings tray
   *   applies live and persists per user.
   * - The editing universe and the Tab walk: a foreign host's leaf hovers,
   *   clicks and Tabs to nothing; from the last in-universe leaf's open
   *   edit, Tab lands on the subject's own panel widget.
   * - The one keyboard grammar over the one selection, on a page-built page
   *   (a real Layout Builder section and text block added through the
   *   mutation spine): a selection is plain until a gesture opens its
   *   editor. (a) Enter on a selected leaf opens its editor through the
   *   engine (daedalus_ui's dispatcher reaching daedalus_edit's editor);
   *   (b) Escape cancels the open edit and the next Escape clears the
   *   selection, the mode and its chrome unchanged; (c) Cmd+Enter commits
   *   and the selection survives with no edit open; (d) the arrows after a
   *   cancel move the selection and open nothing; (g) the tree row's Enter
   *   opens the primary's edit, while a row click only selects.
   * - The live-edit dress rides the same legs: every selection with no OPEN
   *   edit is a plain selection (solid stroke, verbs on the chip face), a
   *   container and an editable leaf alike; the dash and the edit's widget
   *   verbs appear only while a live edit lands on the primary (the store's
   *   editingKey, written by the engine's editChanged report).
   * - The selection door, the press decision's second road: with the text
   *   block's edit open and text typed, a block placed through the
   *   mutation spine with `select` ends the edit (a commit: the typed text
   *   reaches the mirror), lands the selection on the placed block and
   *   leaves the frame plain, the same end a press on keep-nothing chrome
   *   decides, through the door that owns every selection write that is
   *   not a press.
   * - A multi-region section on the page-built page: the selection index
   *   draws its region rows from the mirror's one lean tree, and a block
   *   dropped through a tree row lands in the anchor's region (the second,
   *   then moved to the first), the panel's region row, the mirror's
   *   region node and the canvas's region wrapper agreeing after each
   *   gesture.
   * - The element picker: the page elements root row's picker toggle (and
   *   its mod+shift+c hotkey) arms the depth policy, so the next canvas
   *   hover resolves the deepest presented element as a Cmd/Ctrl gesture
   *   does; (e) the picker followed by a double-click on a nested leaf
   *   edits it (the click consumes the arming, the deepen rule keeps the
   *   leaf through the second click, the even click is the door), and
   *   Cmd+double-click does the same in one gesture; a press on the toggle
   *   over an open edit commits the edit and arms; Escape drops the picker
   *   first.
   * - The stationary second double-click: the gesture is the even OS click
   *   count on `click` (the browser fires `dblclick` only at count 2, and
   *   macOS keeps counting fast stationary clicks past it), so a pair at a
   *   block inside a section selects the section and drills to the block,
   *   and a second pair at the same spot arriving as counts 3 and 4
   *   advances again exactly as a fresh pair would: at the leaf, it opens
   *   the editor.
   * - The collapse controls: the page elements root row's collapse-all
   *   and expand-all pair and the `4` hotkey act on the tree's one outline
   *   at once, and selecting a deep leaf over a collapsed outline reveals
   *   its path only, the row clear of the pinned root row.
   * - A row drop onto a section's start: the page elements panel's drop
   *   unit is the gap line between rows, so a block row dragged down onto
   *   a sibling section row's bottom band is offered the section's start
   *   (the caret's chip names the section, never a new container in the
   *   page) and the drop lands the block as the section's first child in
   *   the mirror, the panel and the canvas.
   * - The Page form's toggle presents the root through the editor door:
   *   the panel opens on the top-level form with its first revealed widget
   *   as the open edit.
   * - The cookie-arrival render: a page arriving with the navigationMode
   *   cookie (what daedalus_ui's InitialMode subscriber
   *   writes onto a new node's post-creation redirect) renders the Edit Mode
   *   chrome server-side, visible on arrival.
   */
  public function testEditModeSession(): void {
    $this->loginAsEditor();

    $assert_session = $this->assertSession();

    // --- The toggle: enable, persist across a reload, disable. ---
    // First, on its own fresh page node: API-created, so it arrives outside
    // Edit Mode (see the class doc for the cookie ordering).
    $toggle_node = Node::create([
      'type' => 'page',
      'title' => 'Edit mode toggle test page',
    ]);
    $toggle_node->save();
    $this->drupalGet($toggle_node->toUrl());

    // Outside Edit Mode: the toggle button renders inactive and the editing
    // chrome is present but hidden. Visibility is CSS-driven off the html
    // element's data-daedalus-ui-mode attribute, which the server stamps
    // from the (absent) cookie.
    $toggle = $assert_session->elementExists('css', '.daedalus-ui-mode-button[data-mode="edit"]');
    $this->assertFalse(
      $toggle->hasClass('active'),
      'The Edit Mode toggle is inactive before entering Edit Mode.',
    );
    $assert_session->elementExists('css', '#edit-mode-top-bar');
    $this->assertJsCondition("document.documentElement.getAttribute('data-daedalus-ui-mode') !== 'edit'", 20000, 'The Edit Mode chrome is hidden before entering Edit Mode.');

    // Click the toggle. The mode-button handler AJAX-enables Edit Mode and,
    // once the reload resolves, adds html.edit_mode. (A real click also
    // proves the button is visible: WebDriver refuses to click a hidden
    // element.)
    $this->clickEditModeToggle();
    $this->assertJsCondition("document.documentElement.classList.contains('edit_mode')", 20000, 'Clicking the toggle enabled Edit Mode.');

    // Reload: the path-scoped navigationMode=edit cookie persists, so the
    // server re-renders Edit Mode: the toggle is active and the mode
    // attribute stamped. These are the deterministic SSR markers;
    // html.edit_mode is JS-only and does not reliably re-appear on a cold
    // load, so it is not the persistence signal.
    $this->drupalGet($toggle_node->toUrl());
    $this->assertTrue(
      $assert_session->elementExists('css', '.daedalus-ui-mode-button[data-mode="edit"]')->hasClass('active'),
      'Edit Mode persists across a reload: the toggle is rendered active.',
    );
    $this->assertJsCondition("document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'", 20000, 'Edit Mode persists across a reload: the mode attribute is server-rendered.');

    // Click the toggle again: the handler sees the edit cookie and
    // AJAX-disables Edit Mode, so the button loses its active state and the
    // chrome is hidden again.
    $this->clickEditModeToggle();
    $this->assertJsCondition("!document.querySelector('.daedalus-ui-mode-button[data-mode=\\'edit\\']').classList.contains('active')", 20000, 'Clicking the toggle again cleared the button\'s active state.');
    $this->assertJsCondition("document.documentElement.getAttribute('data-daedalus-ui-mode') !== 'edit'", 20000, 'Clicking the toggle again disabled Edit Mode: the mode attribute is gone.');

    // The fixture's 'plain' type: a node whose body edits inline with no
    // Layout Builder, the simplest editing surface. The body is stored as
    // the paragraph CKEditor normalizes to, so a commit that changes
    // nothing (the Tab walk below commits the open edit at its edge)
    // diffs empty and posts no mutation: the history step further down
    // counts exactly two entries over the baseline.
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Keyboard grammar test node',
      'body' => [
        'value' => '<p>Body text.</p>',
        'format' => 'daedalus_edit_formatted_text',
      ],
    ]);
    $node->save();

    // A foreign host on the same page: the recipe ships its footer as a
    // site_chrome blueprint (its own layout carries an attributed "Pro tip"
    // text component) shown by daedalus_theme's site frame as its footer
    // slot's default, after the main slot. It renders around the node page attributed but
    // OUTSIDE the node's blueprint: the editing-universe step below drives
    // it.
    $this->drupalGet($node->toUrl());
    $this->enterEditMode();

    // Selectable-key attribution: entering Edit Mode stamps every rendered
    // entity root and field wrapper with its data-selectable selection identity
    // and the entity metadata editing loads entities by. Asserted here rather
    // than in a dedicated test because the applied-recipe fixture is the
    // dominant cost of a run (see the class doc): the same rendered node is
    // this fixture's attribution surface.
    $assert_session->elementExists('css', sprintf(
      '[data-selectable="entity:%s"][data-entity-type="node"][data-entity-id="%s"]',
      $node->uuid(),
      $node->id(),
    ));
    $assert_session->elementExists('css', sprintf('[data-selectable="field:%s:body"]', $node->uuid()));

    // The hotkey dispatcher boots with the app bundle and announces itself
    // through the public facade. Wait for it before dispatching, so the single
    // synthetic keypress is not lost to a race with the deferred bundle.
    $this->assertJsCondition(
      "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Hotkeys && typeof Drupal.Daedalus.Hotkeys.register === 'function'",
      20000,
    );

    // Blueprint mirror: the edit-mode render delivers the root entity's full
    // blueprint as a drupalSettings JSON string (never fetched on boot), and
    // the app boots its store mirror from it. Entering Edit Mode via the
    // JS-entry path means the settings landed through the LoadEditablePage
    // AJAX merge AFTER the bundle initialized — the attach-time re-sync is
    // what this asserts, alongside the envelope's root identity and the
    // blueprint content read through the public facade.
    $this->assertJsCondition(
      "typeof drupalSettings.daedalusUi?.blueprint === 'string'",
      20000,
      'The edit-mode render attached the blueprint envelope as a JSON string.',
    );
    $this->assertJsCondition(
      sprintf("Drupal.Daedalus.Blueprint.getRoot()?.uuid === '%s'", $node->uuid()),
      20000,
      'The mirror booted with the root identity envelope.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.title === 'Keyboard grammar test node'",
      20000,
      'The mirror holds the serialized blueprint of the routed entity.',
    );

    // The page-elements panel: default-open on Edit Mode entry (left/bottom,
    // no cookie has ever touched the slot), its tree projected from the
    // mirror, and its rows driving selection — a row click commits the same
    // selectable key a canvas click resolves, the panel↔canvas sync being
    // string equality on the selection slice.
    $this->assertJsCondition(
      "document.getElementById('page_elements') && !document.getElementById('page_elements').classList.contains('daedalus-ui-hidden')",
      20000,
      'The page-elements panel opened by default on Edit Mode entry.',
    );
    $this->assertJsCondition(
      "document.querySelectorAll('#page-elements-tree .daedalus-ui-tree__row').length >= 3",
      20000,
      'The tree projected rows from the blueprint mirror.',
    );
    $this->getSession()->executeScript(sprintf(
      "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"field:%s:body\"]').click();",
      $node->uuid(),
    ));
    $this->assertJsCondition(
      sprintf("Drupal.Daedalus.Selection.getSelected() === 'field:%s:body'", $node->uuid()),
      20000,
      'Clicking a tree row commits the row key as the selection.',
    );
    // Hover-scope regression: a panel row's hover must survive pointer
    // movement. The document-level canvas resolver used to see every
    // mousemove over the panel, resolve no selectable, and clear the hover
    // the row's mouseenter had just set — the canvas outline flashed for a
    // split second. Rows sit in a data-selection-hover-scope the resolver
    // now stays out of.
    $this->getSession()->executeScript(sprintf(
      "const row = document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"field:%s:body\"]');"
      . "const r = row.getBoundingClientRect();"
      . "row.dispatchEvent(new MouseEvent('mouseenter'));"
      . "row.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: r.left + 8, clientY: r.top + 4 }));",
      $node->uuid(),
    ));
    $this->assertJsCondition(
      sprintf("Drupal.Daedalus.Selection.getHovered() === 'field:%s:body'", $node->uuid()),
      20000,
      'Panel-row hover persists under pointer movement.',
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // The Show kebab at the inline end of the panel header opens the
    // shared menu as a rider of the floating surface (.daedalus-ui-popover), end
    // aligned: the menu's right edge sits at the kebab's right edge and
    // hangs inward over the panel, never outward over the canvas. The
    // seam: the kebab's `align: 'end'` intent reaching real layout through
    // the surface (node proves the placement math; only a browser proves
    // the kebab passes the intent and the list rides the surface).
    $this->getSession()->executeScript("document.querySelector('.daedalus-ui-show-menu__button').click();");
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-popover > .daedalus-ui-menu') !== null",
      20000,
      'The Show kebab opens the menu as the floating surface\'s content.',
    );
    $this->assertJsCondition(
      "(() => { const kebab = document.querySelector('.daedalus-ui-show-menu__button').getBoundingClientRect();"
      . " const surface = document.querySelector('.daedalus-ui-popover').getBoundingClientRect();"
      . " return Math.abs(surface.right - kebab.right) <= 1 && surface.left < kebab.left; })()",
      20000,
      "The end-aligned Show menu's right edge sits at the kebab's right edge, hanging inward.",
    );
    $this->getSession()->executeScript(
      "document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));"
    );
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover') && document.querySelector('.daedalus-ui-show-menu__button').getAttribute('aria-expanded') === 'false'",
      20000,
      'The outside pointerdown closed the Show menu and its surface.',
    );

    // Default-open is per mode session: a deliberate close (the toggle path)
    // keeps the panel closed for the rest of this session, but leaving Edit
    // Mode expires the slot cookie (even for a closed panel) and re-entry
    // opens default-open panels unconditionally — a close never outlives the
    // session it happened in.
    $this->getSession()->executeScript(
      "Drupal.Daedalus.SidebarManager.closeSidebar('page_elements');"
    );
    $this->assertJsCondition(
      "document.getElementById('page_elements').classList.contains('daedalus-ui-hidden')",
      20000,
      'A deliberate close hides the page-elements panel for the current session.',
    );
    $this->getSession()->executeScript(
      "Drupal.behaviors.DaedalusUiModes.DisableMode(Drupal.Daedalus.ModeManager.getPlugin('edit')).then(() => {"
      . "Drupal.behaviors.DaedalusUiModes.EnableMode(Drupal.Daedalus.ModeManager.getPlugin('edit'));"
      . "});"
    );
    $this->assertJsCondition("document.documentElement.classList.contains('edit_mode')", 20000);
    $this->assertJsCondition(
      "document.getElementById('page_elements') && !document.getElementById('page_elements').classList.contains('daedalus-ui-hidden')",
      20000,
      'Re-entering Edit Mode re-opened the deliberately closed default-open panel.',
    );

    // The binding map is rendered server-side from the sidebar plugins'
    // hotkeys (merged with any user remaps).
    $this->assertJsCondition(
      "drupalSettings.daedalusUi && drupalSettings.daedalusUi.hotKeys && 'sidebar.page_elements' in drupalSettings.daedalusUi.hotKeys",
      20000,
    );

    // --- The editing universe: a foreign host is inert (off-canvas). ---
    // The frame's footer renders its "Pro tip" text component on this page
    // attributed (its selectable key: Layout Builder placements are stamped
    // for editors on every host of a render) because attribution is
    // host-blind by design: the same render is cached and shared across
    // hosts, and daedalus_stylish's styling identity must land for
    // visitors too. So attribution is an address, never authorization. The
    // editing universe is the ROOT blueprint's projection (the same Map the
    // pointer resolves within), enforced at one seam (keyForEditableItem,
    // node-tested), and this step locks its WIRING into the three doors
    // only a real page with both trees can exercise: hover names no key, a
    // click opens nothing (and throws nothing: core's console-error check
    // would fail the run on the mirror-miss error this used to raise) and
    // sends no request, and Tab from the last in-universe leaf's open
    // edit never lands in the foreign host. Its editing surface is its own
    // root (the footer blueprint's canvas, the site builder's: the editor
    // role holds no site_chrome grant, so it wears no portal here).
    $footer_leaf = "[data-selectable='block:" . self::FOOTER_COMPONENT_UUID . "']";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $footer_leaf),
      'The footer "Pro tip" text component rendered attributed in the site'
      . ' frame.',
    );
    $this->assertJsCondition(
      sprintf(
        "document.querySelector(\"%s\").closest('[data-main-entity]') === null"
        . " && !JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('%s')",
        $footer_leaf,
        self::FOOTER_COMPONENT_UUID,
      ),
      20000,
      'The footer component is outside the canvas and absent from the root blueprint.',
    );
    $body_wrapper = sprintf("[data-selectable='field:%s:body']", $node->uuid());
    // Selectors carry single quotes; the JS string literal is double-quoted.
    $hover = static fn (string $selector): string => sprintf(
      "(() => { const el = document.querySelector(\"%s\"); el.scrollIntoView({ block: 'center' });"
      . " const r = el.getBoundingClientRect();"
      . " el.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: r.left + 4, clientY: r.top + 4 })); })();",
      $selector,
    );
    // Hover: an in-universe leaf names its key first, so the foreign leaf's
    // null is the resolver clearing it, not the idle default.
    $this->getSession()->executeScript($hover($body_wrapper));
    $this->assertJsCondition(
      sprintf("Drupal.Daedalus.Selection.getHovered() === 'field:%s:body'", $node->uuid()),
      20000,
      'Hovering an in-universe leaf names its key.',
    );
    $this->getSession()->executeScript($hover($footer_leaf));
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getHovered() === null',
      20000,
      'Hovering the foreign host\'s leaf names no key: it is outside the editing universe.',
    );
    // Click: a SINGLE click, dispatched because a real pointer at this
    // viewport can land on the fixed chrome instead of the leaf. The click
    // resolves no key (nothing to select, and the keyless clear applies
    // inside the canvas only), so no editor door is reached. Not a
    // double-click: that is the drill gesture, and crossing the entity
    // boundary through a portal is pinned by the portal step below on a
    // linked blueprint the editor may open.
    $this->getSession()->executeScript(sprintf(
      "performance.clearResourceTimings();"
      . "document.querySelector(\"%s\").dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));",
      $footer_leaf,
    ));
    $this->getSession()->wait(1500);
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement === null'
      . ' && Drupal.Daedalus.Selection.getSelected() === null'
      . " && performance.getEntriesByType('resource').length === 0",
      20000,
      'Clicking the foreign host\'s leaf opened no editor, committed no selection, and sent no request.',
    );
    // Tab: from the LAST in-universe leaf (document order, the footer sits
    // after the canvas) the next stop would be the foreign leaf; the gated
    // traversal has no next stop. The origin is selected through the
    // facade, which lands a PLAIN selection (no edit opens from a
    // selection write: a landing never opens an editor), and Enter, the
    // editor door, opens the leaf's edit, waited
    // for until its editor is live (the door's editItem is asynchronous).
    // The Tab walk starts from an OPEN edit only (with no edit open Tab is
    // the browser's), and Tab at the edit's edge commits it: the fixture
    // stores the body as the paragraph CKEditor normalizes to, so the
    // commit diffs empty and posts nothing, and the history step below (a
    // baseline plus exactly two entries) reads what it was written
    // against.
    $this->getSession()->executeScript(
      "const leaves = Array.from(document.querySelectorAll('[data-main-entity] :is([data-daedalus-edit-id], [data-daedalus-edit-editor])'));"
      . "window.__lastKey = leaves[leaves.length - 1].closest('[data-selectable]').getAttribute('data-selectable');"
      . "Drupal.Daedalus.Selection.select(window.__lastKey);"
    );
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === window.__lastKey'
      . ' && Drupal.Daedalus.Edit.CurrentlyEditingElement === null'
      . ' && Drupal.Daedalus.EditSession.isEditing() === false',
      20000,
      'Selecting the last in-universe leaf through the facade lands a plain selection: no edit opens from a selection write.',
    );
    $this->pressHotkey('Enter');
    $this->assertJsCondition(
      "(() => { const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;"
      . " const wrapper = document.querySelector(\"[data-selectable='\" + window.__lastKey + \"']\");"
      . " const leaf = editing?.getPageElement() ?? null;"
      . " return Drupal.Daedalus.Selection.getSelected() === window.__lastKey"
      . " && !!wrapper && !!leaf && wrapper.contains(leaf)"
      . " && (leaf.classList.contains('daedalus-edit-inline-editing')"
      . " || leaf.classList.contains('daedalus-edit-text-editing')"
      . " || wrapper.contains(document.activeElement)); })()",
      20000,
      'Enter on the selected last leaf opened its edit through the editor door, live: the Tab origin.',
    );
    $this->pressHotkey('Tab');
    $this->getSession()->wait(1500);
    // The door's edit showed the subject's entity-form panel, and its
    // revealed widgets (this node's fields with no canvas leaf) are
    // traversal stops after the last canvas leaf, so the press commits
    // the edit at its edge and lands on the panel's first widget: an edit
    // INSIDE the panel, on the node's own form. The foreign leaf is
    // untouched and never selected.
    $this->assertJsCondition(
      sprintf(
        "(() => { const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;"
        . " const footer = document.querySelector(\"%s\");"
        . " const landed = editing?.getPageElement() ?? null;"
        . " return !!editing && !!landed"
        . " && document.getElementById('daedalus_edit_entity_form').contains(landed)"
        . " && !footer.contains(landed) && !footer.classList.contains('daedalus-edit-editing')"
        . " && Drupal.Daedalus.Selection.getSelected() !== '%s'; })()",
        $footer_leaf,
        'block:' . self::FOOTER_COMPONENT_UUID,
      ),
      20000,
      'Tab past the last in-universe leaf never enters the foreign host: it lands on the subject\'s own panel widget.',
    );
    // Escape cancels the panel widget's edit so the portal step below
    // inherits no open edit.
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement === null",
      20000,
      'Escape cancelled the panel widget edit the Tab opened.',
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // --- The portal: a foreign host's bridge to its own root. ---
    // A linked blueprint is a document shown by reference: inert where it
    // is placed, edited on the blueprint's own canvas. The bridge is a
    // portal: the server stamps the placement root with the blueprint's
    // label, kind and editing URL (LayoutBuilderBlockAttributes::
    // stampShowing, from the spine's showing carrying the portal for an
    // actor who may update the blueprint, the URL from the one derivation,
    // DaedalusUi::getEditingUrl()), and the client's Portal component reads
    // those two attributes to paint a hover chip beside the selection
    // frame: the label and one Edit link. This step locks the seam (the
    // stamp to the chip) and the two ways through: the link's href and the
    // double-click drill applied across the entity boundary. The fixture
    // is a page-built node linking a `section` blueprint of this test's
    // own: the editor role edits section blueprints, while the recipe's
    // footer is site chrome (the site builder's) and wears no portal for
    // an editor.
    $uuid_service = \Drupal::service('uuid');
    $linked_uuid = $uuid_service->generate();
    $inner_uuid = $uuid_service->generate();
    $inner_block = BlockContent::create([
      'type' => 'text',
      'info' => 'Linked copy',
      'reusable' => FALSE,
      'field_text_text' => 'Linked copy, edited on its own canvas.',
      'field_text_tag' => 'p',
    ]);
    $inner_block->save();
    $blueprint_section = new Section('daedalus_stylish_container');
    $blueprint_section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid_service->generate());
    $blueprint_section->appendComponent(new SectionComponent($inner_uuid, 'content', [
      'id' => 'inline_block:text',
      'label' => 'Text',
      'label_display' => '0',
      'view_mode' => 'full',
      'block_revision_id' => $inner_block->getRevisionId(),
      'block_serialized' => NULL,
    ]));
    $blueprint = Blueprint::create([
      'type' => 'section',
      'label' => 'Portal fixture',
      'machine_name' => 'portal_fixture',
      'description' => 'A linked composition for the portal step.',
      'scope' => 'block',
      'subject_type' => 'node',
      'subject_bundle' => 'page',
      'status' => 1,
      'layout_builder__layout' => [$blueprint_section],
    ]);
    $blueprint->save();
    $page_section_uuid = $uuid_service->generate();
    $page_section = new Section('daedalus_stylish_container');
    $page_section->setThirdPartySetting('daedalus_layout', 'uuid', $page_section_uuid);
    $page_section->appendComponent(new SectionComponent($linked_uuid, 'content', [
      'id' => 'daedalus_blueprint_linked:portal_fixture',
      'label' => 'Portal fixture',
      'label_display' => '0',
      'context_mapping' => ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
    ]));
    $portal_node = Node::create([
      'type' => 'page',
      'title' => 'Portal test page',
      'layout_builder__layout' => [$page_section],
    ]);
    $portal_node->save();
    $this->drupalGet($portal_node->toUrl());
    $this->enterEditMode();
    $blueprint_editing_url = \Drupal::service('daedalus_ui.ui')->getEditingUrl($blueprint)->toString();
    $portal_root = "[data-selectable='block:$linked_uuid'][data-daedalus-ui-portal-url]";
    $inner_leaf = "[data-selectable='block:$inner_uuid']";
    $this->assertJsCondition(
      sprintf(
        "document.querySelector(\"%s\")?.getAttribute('data-daedalus-ui-portal-url') === %s"
        . " && document.querySelector(\"%s\").getAttribute('data-daedalus-ui-portal-label') === %s"
        . " && document.querySelector(\"%s\").contains(document.querySelector(\"%s\"))",
        $portal_root,
        json_encode($blueprint_editing_url),
        $portal_root,
        json_encode($blueprint->label()),
        $portal_root,
        $inner_leaf,
      ),
      20000,
      'The linked placement root carries the portal stamp: the blueprint label and its editing URL, wrapping the inert inner leaf.',
    );
    // The inner leaf is outside the universe (the blueprint's document is
    // not in the page's projection), so the chain under the pointer holds
    // only the page's own keys: the placement and the container holding
    // it. The boundary policy names the outermost of them (the container,
    // as for any block under the page root: resolver.js), never the inner
    // key.
    $this->getSession()->executeScript($hover($inner_leaf));
    $this->assertJsCondition(
      sprintf("Drupal.Daedalus.Selection.getHovered() === 'container:%s'", $page_section_uuid),
      20000,
      'Hovering inside the linked placement names the page\'s own container holding it (the boundary stop), never a key inside the foreign document.',
    );
    $portal_chip = '#daedalus-root .daedalus-ui-frame__chip--portal';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $portal_chip),
      'Hovering inside the portal painted the portal chip.',
    );
    $this->assertJsCondition(
      sprintf(
        "document.querySelector(\"%s\").querySelector('.daedalus-ui-frame__name').textContent === %s"
        . " && document.querySelector(\"%s\").querySelector('a.daedalus-ui-frame__verb').getAttribute('href') === %s",
        $portal_chip,
        json_encode($blueprint->label()),
        $portal_chip,
        json_encode($blueprint_editing_url),
      ),
      20000,
      'The portal chip reads the stamp: the blueprint\'s label and an Edit link to its canvas.',
    );
    // Double-click inside the portal, as a pointer sends it (two clicks
    // carrying the OS click count; the portal reads the even one off
    // `click` exactly as the canvas drill does): the odd click commits the
    // page's own container holding the placement, and the even click
    // crosses the entity boundary (the key it resolved is the page's, above
    // the portal, so nothing inside the portal keeps the drill) and leaves
    // for the blueprint's canvas in this tab.
    $this->dispatchDoubleClick($inner_leaf);
    $this->assertJsCondition(
      sprintf('window.location.pathname === %s', json_encode($blueprint_editing_url)),
      20000,
      'Double-clicking inside the portal navigated to the blueprint\'s canvas.',
    );
    // On its own canvas the blueprint IS the main entity: the same leaf
    // that was outside the universe on the page now sits inside it.
    $this->assertNotNull(
      $assert_session->waitForElement('css', "[data-main-entity] $inner_leaf"),
      'The blueprint canvas renders the blueprint as the main entity, its leaf inside the universe.',
    );
    // Back to the node: Edit Mode persists across the reload (its cookie is
    // path-scoped to the node, untouched by the canvas visits).
    $this->drupalGet($node->toUrl());
    $this->assertJsCondition("document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'", 20000, 'Returning to the node keeps Edit Mode.');
    $this->assertJsCondition(
      "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Hotkeys && typeof Drupal.Daedalus.Hotkeys.register === 'function'",
      20000,
    );

    // Selection behavior: the same fixture is the selection surface (see the
    // class doc for why this test carries it). Hovering an attributed field
    // draws the shared hover outline, clicking commits the selection —
    // readable through the public Selection facade, the same API non-app
    // consumers use — and Escape clears it.
    $body_field = $assert_session->elementExists('css', sprintf('[data-selectable="field:%s:body"]', $node->uuid()));

    $body_field->mouseOver();
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-frame__stroke--hover') && !document.querySelector('.daedalus-ui-frame__stroke--hover').hidden",
      20000,
      'Hovering an attributed field draws the hover outline.',
    );

    $body_field->click();
    $this->assertJsCondition(
      sprintf("Drupal.Daedalus.Selection.getSelected() === 'field:%s:body'", $node->uuid()),
      20000,
      'Clicking commits the field as the selection.',
    );
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === false',
      20000,
      'A single click is a selection gesture: it opens no edit.',
    );
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-frame__stroke--primary') && !document.querySelector('.daedalus-ui-frame__stroke--primary').hidden",
      20000,
      'The committed selection draws the selected outline.',
    );

    // Escape presses with a drill context standing: the field's parent (the
    // page's main entity) seated through the facade's drill(), the same
    // slice write every ArrowLeft/ArrowRight step and a double-click make.
    // The global Escape reads that slice, and the seam under test is
    // that a standing context never keeps the press from clearing: one
    // press drops the selection AND the context (clearSelection nulls
    // both), never climbing to the context's key. The earlier arrow steps
    // land on or under the root, whose parent is null, so this is the one
    // Escape in the class that presses with a non-null context.
    $entity_key = sprintf('entity:%s', $node->uuid());
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.drill('$entity_key');");
    $this->assertJsCondition(
      sprintf(
        "Drupal.Daedalus.Selection.getDrill() === '%s' && Drupal.Daedalus.Selection.getSelected() === 'field:%s:body'",
        $entity_key,
        $node->uuid(),
      ),
      20000,
      'A drill context stands on the field\'s parent with the field still selected.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === null && Drupal.Daedalus.Selection.getDrill() === null',
      20000,
      'Escape clears the selection and the standing drill context in one press.',
    );

    // The pointer twin of that Escape: a KEYLESS click inside the canvas.
    // The canvas — the data-main-entity carrier — never resolves as a
    // selection (the doc'd resolver invariant), so a click landing on it
    // resolves no key, and the pointer grammar treats a keyless click inside the
    // canvas as "select nothing" while leaving clicks outside the canvas
    // (toolbar, sidebars) alone. Nothing else exercises this clear path.
    $this->getSession()->executeScript(sprintf(
      "Drupal.Daedalus.Selection.select('field:%s:body');",
      $node->uuid(),
    ));
    $this->assertJsCondition(
      sprintf("Drupal.Daedalus.Selection.getSelected() === 'field:%s:body'", $node->uuid()),
      20000,
      'The selection was re-committed for the keyless-click leg.',
    );
    $this->getSession()->executeScript(
      "document.querySelector('[data-main-entity]')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === null',
      20000,
      'A keyless click inside the canvas cleared the selection.',
    );

    // --- The keyboard model. ---
    // Arrow traversal drives the ONE selection slice from anywhere: Up/Down
    // walk siblings of the projection tree (the panel's model — the same
    // node set its rows render), Left selects the parent, Right drills to
    // the first ROW. The canvas needs no DOM focus — the bindings ride
    // the document-level dispatcher in the selection context. The tree
    // walked is the two-tier selection index (projection-index.js): the
    // panel's data rows (the [data-tree-key] rows read below) AND its
    // markup part rows, which carry no tree key but are stops of their
    // own, gated by REACH (reach.js): a stop is offered only while the
    // panel draws its row, and a shell row rides the Markup view.
    $tree_rows = 'Array.from(document.querySelectorAll(".daedalus-ui-tree [data-tree-key]")).map((row) => row.getAttribute("data-tree-key"))';
    $row_keys = $this->getSession()->evaluateScript($tree_rows);
    $this->assertGreaterThanOrEqual(3, count($row_keys), 'The tree renders the root and at least two field rows.');
    [$root_key, $first_child, $second_child] = $row_keys;
    // Entry from nothing: with no selection the arrows ENTER the tree at
    // the projection root instead of falling through to page scroll — the
    // keyboard's way into the selection loop.
    $this->pressHotkey('ArrowDown');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$root_key'",
      20000,
      'ArrowDown with no selection enters the tree at the projection root.',
    );
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('$first_child');");
    $this->pressHotkey('ArrowDown');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$second_child'",
      20000,
      'ArrowDown selects the next sibling in the projection tree.',
    );
    $this->pressHotkey('ArrowUp');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$first_child'",
      20000,
      'ArrowUp selects the previous sibling.',
    );
    $this->pressHotkey('ArrowLeft');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$root_key'",
      20000,
      'ArrowLeft climbs to the parent (the root entity row).',
    );
    // Right drills into the root's first DRAWN row. With daedalus_layout
    // installed the index's first row under the root is the part tier's:
    // the entity kind declares the layout root (div.daedalus-layout-layout,
    // daedalus_layout.page_builder.yml, since daedalus_layout 5c211b4) as an
    // unconditional shell part, seated first under the root, ahead of the
    // fields. A shell row's source is the Markup view's, and the view
    // slice seeds every view OFF (absent = off, no localStorage in a
    // fresh session), so the panel is not drawing the shell here and the
    // walk passes through it (reachableRows hoists an undrawn row's own
    // rows into its place): the shell claims the section list
    // (`contains: sections`, since daedalus_layout 31cce42), the fixture has
    // no sections, so Right lands on the first field row, bare.
    // Seam: the keyboard's reach gate, the same predicate the tree draws
    // through (the tree rows read above hold no shell row).
    $layout_root_part = '.' . LayoutBuilderEntityViewDisplay::LAYOUT_ROOT_CLASS;
    $shell_row_js = "document.querySelector('.daedalus-ui-tree [data-tree-address=\"$root_key::$layout_root_part\"]')";
    $this->assertJsCondition(
      "$shell_row_js === null",
      20000,
      'With the Markup view off the panel draws no layout-root shell row.',
    );
    $this->pressHotkey('ArrowRight');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$first_child'"
      . ' && Drupal.Daedalus.Selection.getTarget() === null',
      20000,
      'ArrowRight with the Markup view off drills past the undrawn layout-root shell to the root\'s first field row.',
    );
    // The Markup view on, through the Show menu's real toggle (the view
    // slice's setViewVisibility, driven by EditModeTrait::setMarkupView(),
    // which asserts the item's aria-checked flip and the menu's close):
    // the panel draws the shell row and the same Right from the root now
    // lands on it, a styling target on the root key (getSelected() stays
    // the root, getTarget() names the shell's selector under it) exactly
    // as Right from a linked Image lands on its Raster. Down from it
    // reaches the first field row, its sibling in the root's rows, never
    // a section. Then the toggle back off: the rest of the class runs
    // over the default tree.
    $this->setMarkupView(TRUE);
    $this->assertJsCondition(
      "$shell_row_js !== null",
      20000,
      'With the Markup view on the panel drew the layout-root shell row.',
    );
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('$root_key');");
    $this->pressHotkey('ArrowRight');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$root_key'"
      . " && Drupal.Daedalus.Selection.getTarget()?.key === '$root_key'"
      . " && Drupal.Daedalus.Selection.getTarget()?.selector === '$layout_root_part'",
      20000,
      'ArrowRight with the Markup view on drills to the root\'s first row: the entity kind\'s declared layout-root shell, a styling target on the root key.',
    );
    $this->pressHotkey('ArrowDown');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$first_child'"
      . ' && Drupal.Daedalus.Selection.getTarget() === null',
      20000,
      'ArrowDown from the shell part steps to the first field row, its sibling in the root\'s rows.',
    );
    $this->setMarkupView(FALSE);
    $this->assertJsCondition(
      "$shell_row_js === null",
      20000,
      'With the Markup view off again the panel dropped the layout-root shell row.',
    );

    // The panel is THE accessible surface: a real ARIA tree with
    // focusable rows; the treeitem is the row div, roving tabindex marks
    // the primary selection as the tree's one Tab stop.
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-tree')?.getAttribute('role') === 'tree'"
      . " && document.querySelector('.daedalus-ui-tree__row[data-tree-key]')?.getAttribute('role') === 'treeitem'",
      20000,
      'The panel renders as an ARIA tree of treeitem rows.',
    );
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"$first_child\"]')?.tabIndex === 0"
      . " && document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"$second_child\"]')?.tabIndex === -1",
      20000,
      'Roving tabindex: the primary selection is the tree\'s Tab stop.',
    );

    // F6 toggles DOM focus between the panel and the canvas — a FOCUS
    // toggle, not a selection transfer: the selection is shared state and
    // survives on both sides.
    $this->pressHotkey('F6');
    $this->assertJsCondition(
      "document.activeElement?.getAttribute('data-tree-key') === '$first_child'",
      20000,
      'F6 focuses the primary selection\'s panel row.',
    );
    // While focus is IN the panel, traversal moves focus with the selection
    // (the row-by-row read-out screen readers need).
    $this->pressHotkey('ArrowDown');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$second_child'"
      . " && document.activeElement?.getAttribute('data-tree-key') === '$second_child'",
      20000,
      'Traversal in the panel moves focus with the selection.',
    );
    $this->pressHotkey('F6');
    $this->assertJsCondition(
      "!document.getElementById('page-elements-tree').contains(document.activeElement)"
      . " && Drupal.Daedalus.Selection.getSelected() === '$second_child'",
      20000,
      'F6 hands focus back to the canvas; the selection stays.',
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // The panel's own keyboard entry: with no selection F6 falls back to
    // the first row (the root — also the roving-tabindex Tab stop), and
    // Space on a focused row commits it as the selection, the ARIA
    // treeitem activation key and the keyboard twin of the row click.
    // Dispatched on the focused row, not document: the handler is the
    // row's. (Enter is NOT the row's: it is the dispatcher's editor door,
    // proven on the page-built page below, so a focused-but-unselected
    // row's Enter opens nothing and selects nothing.)
    $this->pressHotkey('F6');
    $this->assertJsCondition(
      "document.activeElement?.getAttribute('data-tree-key') === '$root_key'",
      20000,
      'With no selection, F6 focuses the root row.',
    );
    $this->pressKeyOnFocused('Enter');
    $this->getSession()->wait(500);
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === null'
      . ' && Drupal.Daedalus.EditSession.isEditing() === false',
      20000,
      'Enter on a focused, unselected tree row neither selects it nor opens an edit: Enter is the editor door, not the row activation.',
    );
    $this->pressKeyOnFocused(' ');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$root_key'",
      20000,
      'Space on a focused tree row commits it as the selection.',
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // --- Selection indicator: breadcrumb + chips. ---
    // The breadcrumb renders the PRIMARY selection's ancestry from the same
    // projection index that labels the tree and the chat chips: for a field
    // selection on this fixture, the root entity crumb then the field crumb,
    // the current one marked and the ancestors clickable.
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('$first_child');");
    $this->assertJsCondition(
      "document.querySelectorAll('.daedalus-ui-breadcrumb .daedalus-ui-breadcrumb__crumb').length === 2",
      20000,
      'The breadcrumb renders the field selection\'s two-level ancestry.',
    );
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-breadcrumb__crumb--current')?.textContent"
      . " === Drupal.Daedalus.Blueprint.labelForKey('$first_child')",
      20000,
      'The current crumb carries the selection\'s projected label.',
    );
    $this->assertJsCondition(
      "document.querySelector('button.daedalus-ui-breadcrumb__crumb')?.textContent"
      . " === Drupal.Daedalus.Blueprint.labelForKey('$root_key')",
      20000,
      'The ancestor crumb is a button labeled from the same projection.',
    );
    // No real set, no chips: a single selection's indicator is the
    // breadcrumb plus the outline.
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-chips') === null",
      20000,
      'A single selection renders no chips.',
    );
    // Crumbs are clickable to climb: clicking the root crumb selects it —
    // the same step-up shape ArrowLeft commits.
    $this->getSession()->executeScript("document.querySelector('button.daedalus-ui-breadcrumb__crumb').click();");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$root_key'",
      20000,
      'Clicking an ancestor crumb climbs the selection to it.',
    );
    // The target crumb: a styling target the index does not place (a tag
    // tier seated through the public facade, the road the styling
    // module's switcher and Cmd-click take) sits on the selected element
    // and ends the trail with one more crumb, a button worded through the
    // part behavior's label seam (Blueprint.targetLabel, the same words
    // the AI chat's chip reads) that drops the target and keeps the
    // selection. Escape drops the target first, the selection staying,
    // and only a second press clears it.
    $target_crumb = '.daedalus-ui-breadcrumb .daedalus-ui-breadcrumb__crumb--target';
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('$first_child'); Drupal.Daedalus.Selection.target('$first_child', 'a');");
    $this->assertJsCondition(
      "(() => { const crumb = document.querySelector('$target_crumb');"
      . " const crumbs = document.querySelectorAll('.daedalus-ui-breadcrumb .daedalus-ui-breadcrumb__crumb');"
      . " return Drupal.Daedalus.Selection.getSelected() === '$first_child'"
      . " && Drupal.Daedalus.Selection.getTarget()?.key === '$first_child'"
      . " && Drupal.Daedalus.Selection.getTarget()?.selector === 'a'"
      . " && crumbs.length === 3 && !!crumb && crumb.tagName === 'BUTTON' && crumbs[2] === crumb"
      . " && crumb.textContent.trim() !== ''"
      . " && crumb.textContent.trim() === Drupal.Daedalus.Blueprint.targetLabel('$first_child', 'a'); })()",
      20000,
      'A tag target seated on the field ends the breadcrumb with a third crumb, a target button worded through the label seam, the selection unchanged.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$first_child'"
      . ' && Drupal.Daedalus.Selection.getTarget() === null'
      . " && !document.querySelector('$target_crumb')"
      . " && document.querySelectorAll('.daedalus-ui-breadcrumb .daedalus-ui-breadcrumb__crumb').length === 2",
      20000,
      'Escape drops the target first: the field stays selected and the target crumb is gone.',
    );
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.target('$first_child', 'a');");
    $this->assertJsCondition(
      "!!document.querySelector('$target_crumb')",
      20000,
      'The target was re-seated for the crumb-click leg.',
    );
    $this->getSession()->executeScript("document.querySelector('$target_crumb').click();");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$first_child'"
      . ' && Drupal.Daedalus.Selection.getTarget() === null'
      . " && !document.querySelector('$target_crumb')",
      20000,
      'Clicking the target crumb drops the target and keeps the selection: back to the whole element.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === null',
      20000,
      'A second Escape, with no target left, clears the selection.',
    );

    // Chips are the multi-select set: one chip per member, in
    // selection order, the primary accented, the same data the AI chat's
    // chips render, read from the same slice.
    $this->getSession()->executeScript(
      "Drupal.Daedalus.Selection.selectSet(['$first_child', '$second_child']);"
    );
    $this->assertJsCondition(
      "document.querySelectorAll('.daedalus-ui-chips .daedalus-ui-chip').length === 2"
      . " && document.querySelector('.daedalus-ui-chip[data-selection-key=\"$second_child\"]')?.classList.contains('daedalus-ui-chip--primary')",
      20000,
      'A multi-select set renders one chip per member with the primary accented.',
    );
    // Chip dismiss IS deselection: the set shrinks to one, so the chips row
    // leaves and the outline set follows (one slice, four renderings).
    $this->getSession()->executeScript(
      "document.querySelector('.daedalus-ui-chip[data-selection-key=\"$first_child\"] .daedalus-ui-chip__dismiss').click();"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelectedSet().length === 1"
      . " && Drupal.Daedalus.Selection.getSelected() === '$second_child'"
      . " && document.querySelector('.daedalus-ui-chips') === null",
      20000,
      'Dismissing a chip deselects its key; a set of one renders no chips.',
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-selection-indicator') === null",
      20000,
      'Clearing the selection removes the indicator.',
    );

    // --- The selection frame: numbered chips and the verb face. ---
    // Every member of a real set wears a canvas chip reading "N Type":
    // the same ordered set the indicator chips and the AI chat chips
    // render, numbered by selection order, named by the one TYPE
    // resolver (the canvas shows the content, so its chip speaks type),
    // the primary's chip accented and painted by the frame's per-frame
    // loop.
    $this->getSession()->executeScript(
      "Drupal.Daedalus.Selection.selectSet(['$first_child', '$second_child']);"
    );
    $this->assertJsCondition(
      "document.querySelectorAll('.daedalus-ui-frame .daedalus-ui-frame__chip').length === 2"
      . " && document.querySelector('.daedalus-ui-frame__chip[data-selection-key=\"$first_child\"] .daedalus-ui-frame__number')?.textContent === '1'"
      . " && document.querySelector('.daedalus-ui-frame__chip[data-selection-key=\"$second_child\"] .daedalus-ui-frame__number')?.textContent === '2'"
      . " && document.querySelector('.daedalus-ui-frame__chip[data-selection-key=\"$second_child\"]')?.classList.contains('daedalus-ui-frame__chip--primary')"
      . " && !document.querySelector('.daedalus-ui-frame__chip[data-selection-key=\"$second_child\"]').closest('.daedalus-ui-frame__anchor').hidden"
      . " && document.querySelector('.daedalus-ui-frame__chip[data-selection-key=\"$second_child\"] .daedalus-ui-frame__name')?.textContent"
      . " === Drupal.Daedalus.Blueprint.typeForKey('$second_child')",
      20000,
      'A real set wears one numbered canvas chip per member, named by the shared type resolver.',
    );
    // The set face offers only multi-capable verbs applying to EVERY
    // member; no single-only verb leaks onto a set (this fixture's field
    // pair admits none at all).
    $this->assertJsCondition(
      "document.querySelectorAll('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verb').length === 0",
      20000,
      "No single-selection verb leaks onto a multi-select set's chip.",
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // Dispatcher capabilities the old flat hotkey map could not express,
    // driven through the same public facade verbs and contrib bindings use
    // (same fixture; see the class doc for why this test carries them).
    // A modifier combo: 'mod+k' resolves to ctrl+k on the Linux test runner.
    // Strict matching means the bare key must NOT fire it.
    $this->getSession()->executeScript(
      "window.__comboFired = 0;"
      . "Drupal.Daedalus.Hotkeys.register({ id: 'test_combo', key: 'mod+k', action: () => { window.__comboFired++; } });"
    );
    $this->pressHotkey('k');
    $this->pressHotkey('k', ['ctrlKey' => TRUE]);
    $this->assertJsCondition(
      'window.__comboFired === 1',
      20000,
      'A mod+key combo binding fires on the modifier combo and not on the bare key.',
    );

    // A hold binding on a bare modifier: pressed on keydown, released on
    // keyup.
    $this->getSession()->executeScript(
      "window.__holdState = null;"
      . "Drupal.Daedalus.Hotkeys.register({ id: 'test_hold', key: 'shift', kind: 'hold', action: (e, pressed) => { window.__holdState = pressed; } });"
    );
    $this->pressHotkey('Shift', ['shiftKey' => TRUE]);
    $this->assertJsCondition(
      'window.__holdState === true',
      20000,
      'A bare-modifier hold binding activates on keydown.',
    );
    $this->releaseHotkey('Shift');
    $this->assertJsCondition(
      'window.__holdState === false',
      20000,
      'Releasing the modifier releases the hold binding.',
    );

    // Remapping end to end: the settings tray renders its rows client-side
    // from the binding registry, clicking a row's key enters capture mode,
    // and the captured key rebinds live and persists per-user. Synthetic
    // events reach the capture input regardless of the collapsed details
    // element, so the sidebar does not need to be opened. The subject is
    // the Page elements panel's toggle binding: a server-declared panel
    // binding (HotkeySettings lists every sidebar definition, bound or
    // not) that ships UNBOUND, so the remap is what gives it a key, and
    // the panel is on this page, so the remapped key has a visible effect
    // (the toggle hides and shows the panel).
    $this->assertJsCondition(
      "!!document.querySelector('.hotkeys-list .configured-hotkey[data-binding-id=\"sidebar.page_elements\"]')"
      . " && drupalSettings.daedalusUi.hotKeys['sidebar.page_elements'].key === ''",
      20000,
      'The settings tray lists the Page elements panel binding from the registry, unbound.',
    );

    // The tray follows the registry, not a one-time snapshot: verb hotkeys
    // ride other modules' scripts, whose AJAX/big_pipe delivery order is not
    // guaranteed, so a binding registered AFTER the tray first rendered
    // (like daedalus_layout's Insert verb on the AJAX Edit Mode entry) must still
    // get a row.
    $this->getSession()->executeScript(
      "Drupal.Daedalus.Hotkeys.register({ id: 'test_late', key: 'mod+9', label: 'Late binding', remappable: true, action: () => {} });"
    );
    $this->assertJsCondition(
      "document.querySelector('.hotkeys-list .configured-hotkey[data-binding-id=\"test_late\"]')?.textContent === 'MOD+9'",
      20000,
      'A binding registered after the tray rendered re-renders the list with its row.',
    );

    // Click the Page elements row and press 'm' in the capture input. The
    // input is a typing surface, so this also exercises the typing guard:
    // the keystroke must rebind, not dispatch.
    $this->getSession()->executeScript(
      "const display = document.querySelector('.hotkeys-list .configured-hotkey[data-binding-id=\"sidebar.page_elements\"]');"
      . "display.click();"
      . "display.nextElementSibling.dispatchEvent(new KeyboardEvent('keydown', { key: 'm', bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "document.querySelector('.hotkeys-list .configured-hotkey[data-binding-id=\"sidebar.page_elements\"]').textContent === 'M'"
      . " && drupalSettings.daedalusUi.hotKeys['sidebar.page_elements'].key === 'm'",
      20000,
      'Capturing a key rebinds the binding client-side.',
    );

    // The rebind applies immediately: 'm' now toggles the Page elements
    // panel (the binding's action clicks the panel's own toggle button,
    // so the press rides the exact pointer path). Pressed twice, so the
    // panel is open again for the legs below.
    $this->pressHotkey('m');
    $this->assertJsCondition(
      "document.getElementById('page_elements').classList.contains('daedalus-ui-hidden')",
      20000,
      'The remapped key toggled the Page elements panel closed.',
    );
    $this->pressHotkey('m');
    $this->assertJsCondition(
      "!document.getElementById('page_elements').classList.contains('daedalus-ui-hidden')",
      20000,
      'The remapped key toggled the Page elements panel open again.',
    );

    // Wait out the save round-trip, then prove persistence: a fresh page
    // render (the edit cookie is still set, so the chrome and its binding
    // map render server-side) serves the remapped key from the user entity.
    $this->assertJsCondition('jQuery.active === 0', 20000);
    $this->drupalGet($node->toUrl());
    $this->assertJsCondition(
      "drupalSettings.daedalusUi && drupalSettings.daedalusUi.hotKeys && drupalSettings.daedalusUi.hotKeys['sidebar.page_elements'].key === 'm'",
      20000,
      'The remap persisted per-user and survives a fresh page render.',
    );

    // --- History: timeline, sidebar, undo/redo. ---
    // The same fixture drives the history spine end to end on the entity
    // tempstore strategy (the layout strategy leg lives in daedalus_layout's suite).
    // Two mutations through the public mutate facade produce a baseline plus
    // two timeline entries — one entry per request, captured at the strategy
    // write tee — with the client-sent label naming the first gesture and
    // the second falling back to a target-derived label.
    $session = $this->getSession();
    $this->assertJsCondition("typeof Drupal.Daedalus.History === 'object'", 20000);
    $mutate_body = <<<JS
      const ctx = Drupal.Daedalus.Blueprint.rootContext();
      window.__mut = null;
      Drupal.Daedalus.Blueprint.mutate({
        entityType: ctx.entityType, entityId: ctx.entityId, viewMode: ctx.viewMode,
        operations: [{ op: 'update_element', properties: { body: { value: '%s', format: 'daedalus_edit_formatted_text' } } }],
        %s
      }).then((payload) => { window.__mut = payload; });
    JS;
    $session->executeScript(sprintf($mutate_body, 'First body.', "label: 'Rewrote the body',"));
    $this->assertJsCondition(
      "window.__mut && window.__mut.history && window.__mut.history.pointer === 1 && window.__mut.history.entries.length === 2",
      20000,
      'The first mutation response carried a timeline of baseline + one entry.',
    );
    $this->assertJsCondition(
      "document.body.innerText.includes('First body.')",
      20000,
      'The mutation swapped the re-rendered body into the canvas.',
    );
    $session->executeScript(sprintf($mutate_body, 'Second body.', ''));
    $this->assertJsCondition(
      "window.__mut && window.__mut.history && window.__mut.history.pointer === 2",
      20000,
      'The second mutation moved the pointer to a third entry.',
    );

    // The History sidebar: its toggle opens the panel, which fills from the
    // timeline — newest first, the current entry marked, labels from the
    // client annotation, the derived fallback, and the baseline.
    $session->executeScript("document.querySelector('#daedalus-ui-history').click();");
    $assert_session->waitForElementVisible('css', '#history');
    $this->assertJsCondition(
      "document.querySelectorAll('#history .daedalus-ui-panel-item').length === 3",
      20000,
      'The history panel lists the baseline and both entries.',
    );
    $this->assertJsCondition(
      "document.querySelector('#history .daedalus-ui-panel-item')?.classList.contains('history-item--current')",
      20000,
      'The newest entry is marked current.',
    );
    $labels = $session->evaluateScript(
      "Array.from(document.querySelectorAll('#history .daedalus-ui-panel-item__label')).map((el) => el.textContent)",
    );
    $this->assertSame(['Edited body', 'Rewrote the body', 'Initial state'], $labels);
    $this->assertJsCondition(
      "!document.querySelector('#history-undo').disabled && document.querySelector('#history-redo').disabled",
      20000,
      'At the head of the timeline undo is enabled and redo is not.',
    );

    // Undo by hotkey ('mod' resolves to Ctrl on the Linux runner): the
    // restore rides the op queue, the response swaps the restored body back
    // into the canvas, and the sidebar re-renders around the moved pointer.
    $this->pressHotkey('z', ['ctrlKey' => TRUE]);
    $this->assertJsCondition(
      "document.body.innerText.includes('First body.') && !document.body.innerText.includes('Second body.')",
      20000,
      'Undo restored the previous staged body on the canvas.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.History.getState().pointer === 1 && Drupal.Daedalus.History.getState().canRedo",
      20000,
      'Undo moved the pointer and opened the redo direction.',
    );
    $this->assertJsCondition(
      "document.querySelector('#history .history-item--future') !== null",
      20000,
      'The undone entry renders as the dimmed future.',
    );

    // Jumping straight to the baseline restores the state before any staged
    // change: the tempstore entry is deleted and the canvas shows the saved
    // body again.
    $session->executeScript("document.querySelector('#history [data-history-seq=\"0\"]').click();");
    $this->assertJsCondition(
      "document.body.innerText.includes('Body text.') && !document.body.innerText.includes('First body.')",
      20000,
      'Jumping to the baseline restored the saved body.',
    );
    $this->assertJsCondition(
      "document.querySelector('#history-undo').disabled && !document.querySelector('#history-redo').disabled",
      20000,
      'At the baseline undo is disabled and redo is enabled.',
    );

    // Redo steps forward through the same timeline.
    $this->pressHotkey('z', ['ctrlKey' => TRUE, 'shiftKey' => TRUE]);
    $this->assertJsCondition(
      "document.body.innerText.includes('First body.')",
      20000,
      'Redo re-applied the first staged body.',
    );
    $this->assertJsCondition(
      "typeof Drupal.Daedalus === 'object'",
      20000,
      'The restore responses left the client app intact.',
    );

    // The panel header's close control (the minus glyph every rail panel
    // wears, appended last in its action cluster by buildPanelHeader()):
    // a press rides the [data-sidebar-close] behavior through
    // SidebarManager.closeSidebar(), the same door the toggle uses, so the
    // panel hides and its (side, slot) cookie empties; the toggle then
    // reopens it. The History panel stands for the class: one round trip
    // through the wrapper is the seam the kernel header test cannot see.
    $this->assertJsCondition(
      "Drupal.Daedalus.getCookieValue('right_bottom_sidebar') === 'history'",
      20000,
      'The open History panel is recorded in its slot cookie.',
    );
    $close = $assert_session->waitForElementVisible('css', '#history .daedalus-ui-panel__header .daedalus-ui-panel__actions .daedalus-ui-panel__close[data-sidebar-close]');
    $this->assertNotNull($close, 'The History header carries the shared close control.');
    $this->assertSame('clean', $close->getAttribute('data-daedalus-ui-conceal'), 'The close control conceals at clean only.');
    $close->click();
    $this->assertJsCondition(
      "document.getElementById('history').classList.contains('daedalus-ui-hidden')",
      20000,
      'The header close hid the History panel.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.getCookieValue('right_bottom_sidebar') === null",
      20000,
      'The header close emptied the slot cookie.',
    );
    $session->executeScript("document.querySelector('#daedalus-ui-history').click();");
    $assert_session->waitForElementVisible('css', '#history');
    $this->assertJsCondition(
      "!document.getElementById('history').classList.contains('daedalus-ui-hidden')"
      . " && Drupal.Daedalus.getCookieValue('right_bottom_sidebar') === 'history'"
      . " && document.querySelectorAll('#history .daedalus-ui-panel-item').length === 3",
      20000,
      'The toggle reopened the closed panel with its timeline intact.',
    );

    // --- The fake-canvas wrap (MainEntityWrapperTrait). ---
    // When Edit Mode content cannot be built — here a nonexistent view
    // mode — LoadEditablePage wraps its error message AS IF it were the
    // main entity: the same render-array container the workspace-switcher
    // and workspace-violation paths build, carrying the canonical wrapper
    // id and data-main-entity so the client can still target and act on
    // the page. Fetch the AJAX route directly and assert the
    // ReplaceCommand: its selector is the wrapper-id + view-mode grammar
    // and its payload is the wrapped error. This pins the fake-canvas
    // machinery those workspace paths share (the workspace switcher itself
    // needs entity_workflow_content, which is not part of this fixture).
    $session->executeScript(<<<JS
      window.__errorWrapProbe = null;
      fetch(Drupal.url('daedalus-ui/load-editable-page/node/{$node->id()}/nope'), { credentials: 'same-origin' })
        .then((response) => response.json())
        .then((commands) => { window.__errorWrapProbe = commands; })
        .catch((error) => { window.__errorWrapProbe = { error: String(error) }; });
      JS);
    $this->assertJsCondition('window.__errorWrapProbe !== null', 20000);
    $probe = $session->evaluateScript('window.__errorWrapProbe');
    $probe_debug = var_export($probe, TRUE);
    $this->assertArrayNotHasKey('error', $probe, $probe_debug);
    $wrapper_id = sprintf('node::%s::%s', $node->id(), $node->bundle());
    $replace = current(array_filter($probe, static fn (array $command) =>
      ($command['command'] ?? '') === 'insert'
      && ($command['method'] ?? '') === 'replaceWith'
      && ($command['selector'] ?? '') === "[data-daedalus-ui-entity-wrapper='$wrapper_id'][data-daedalus-ui-view-mode='nope']"
    ));
    $this->assertNotEmpty($replace, 'The response replaces the entity by its canonical wrapper id + view mode. ' . $probe_debug);
    $this->assertStringContainsString('Invalid view mode', $replace['data'], 'The wrapped content carries the error message.');
    $this->assertStringContainsString('data-main-entity="true"', $replace['data'], 'The error content is wrapped as the main entity (fake canvas).');
    $this->assertStringContainsString(
      'data-daedalus-ui-entity-wrapper="' . $wrapper_id . '"',
      $replace['data'],
      'The fake canvas carries the canonical wrapper id.',
    );

    // --- The canvas state vector (headless since the chip row). ---
    // The vector is addressable state, not buried UI state: the public
    // facade below is the same surface the daedalus_stylish inspector's chip row
    // and the AI's render tool drive. There is no top-bar UI of its own —
    // the old chip cluster is gone, and the spine must work without it.
    $this->assertJsCondition(
      "Drupal.Daedalus.CanvasVector && typeof Drupal.Daedalus.CanvasVector.set === 'function'",
      20000,
      'The canvas vector facade registered.',
    );
    $assert_session->elementNotExists('css', '#daedalus-ui-canvas-vector');
    // The stage wraps the page and is a query container in edit mode.
    $this->assertJsCondition(
      "getComputedStyle(document.getElementById('daedalus-ui-stage')).containerType === 'inline-size'",
      20000,
      'The stage is a query container in edit mode.',
    );
    // ...and it wraps the page INSIDE the off-canvas canvas. Core displaces
    // the page off its chrome through sibling combinators, so a root that
    // wrapped the canvas instead would unmatch them and slide the page under
    // the toolbar — the second assertion is core's own selector, run against
    // the live document, so the pin fails on the cause and not on a symptom.
    $this->assertJsCondition(
      "document.getElementById('daedalus-ui-stage').parentElement.matches('.dialog-off-canvas-main-canvas')",
      20000,
      'The stage sits inside the off-canvas main canvas.',
    );
    $this->assertJsCondition(
      "document.querySelector('.dialog-off-canvas-main-canvas').matches('.admin-toolbar ~ .dialog-off-canvas-main-canvas')",
      20000,
      "Core's toolbar displacement selectors still match the canvas.",
    );
    // Probes: a container query against the stage (named, so nearer
    // unnamed containers cannot shadow it) and the two @media emulation
    // axes. Injected BEFORE the vector moves so each set() applies to them
    // synchronously.
    $session->executeScript(<<<JS
      const probe = document.createElement('style');
      probe.textContent = `
        @container canvas (max-width: 640px) { [data-main-entity] { --canvas-probe-width: narrow; } }
        @media (prefers-color-scheme: dark) { body { --canvas-probe-scheme: dark; } }
        @media (prefers-reduced-motion: reduce) { body { --canvas-probe-motion: reduce; } }
      `;
      document.head.append(probe);
      JS);
    // The band ruler is constraint chrome: it shows while the canvas is
    // constrained in any way (a width posed at all, or the unposed stage
    // squeezed below base's floor) and rests when the stage sits at its
    // natural full width in the bandless base. This env contributes no
    // bands of its own, so push a band set through the public queue and
    // widen the window until the unposed stage measures into the
    // bandless base: nothing constrains, no ruler. (1920, not something
    // modest: the edit-mode rails displace the stage, so the window must
    // out-earn both panels plus base's 1024 floor.)
    $session->executeScript(
      "(window.DaedalusUiCanvasStops ||= []).push("
      . "{ max: 767, label: 'Narrow', name: 'narrow' },"
      . "{ min: 768, max: 1023, label: 'Medium', name: 'medium' });"
    );
    $session->resizeWindow(1920, 900);
    $this->assertJsCondition(
      "Drupal.Daedalus.CanvasVector.get().measured >= 1024"
      . " && getComputedStyle(document.getElementById('daedalus-ui-canvas-ruler')).display === 'none'",
      20000,
      'Unconstrained (natural full width in the bandless base), the ruler rests.',
    );
    // Width: posing narrows the stage, mirrors onto the html element,
    // updates the chip, and re-evaluates the container query with no
    // document boundary.
    $session->executeScript("Drupal.Daedalus.CanvasVector.set({ width: 375 });");
    $this->assertJsCondition(
      "document.documentElement.getAttribute('data-canvas-width') === '375'"
      . " && document.getElementById('daedalus-ui-stage').style.width === '375px'",
      20000,
      'Setting the width axis narrows the stage and mirrors the state.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.getElementById('daedalus-ui-canvas-ruler')).display === 'block'",
      20000,
      'Posing a width constrains the canvas: the ruler shows.',
    );
    // The scrub readout tracks the actual canvas width, but only a live
    // handle drag (the canvas-vector-resizing mark) makes it visible.
    $this->assertJsCondition(
      "document.querySelector('.canvas-vector-ruler__readout').textContent === '375px'"
      . " && getComputedStyle(document.querySelector('.canvas-vector-ruler__readout')).opacity === '0'",
      20000,
      'The readout carries the effective width but hides outside a drag.',
    );
    $session->executeScript("document.documentElement.classList.add('canvas-vector-resizing');");
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector('.canvas-vector-ruler__readout')).opacity === '1'",
      20000,
      'A live drag reveals the readout.',
    );
    $session->executeScript("document.documentElement.classList.remove('canvas-vector-resizing');");
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector('[data-main-entity]')).getPropertyValue('--canvas-probe-width').trim() === 'narrow'",
      20000,
      'A container query re-evaluated against the posed canvas width.',
    );
    // Scheme: forcing dark rewrites the page's prefers-color-scheme
    // conditions (the probe rule applies) and mirrors the state; back to
    // auto restores the authored condition (headless Chrome prefers light).
    $session->executeScript("Drupal.Daedalus.CanvasVector.set({ scheme: 'dark' });");
    $this->assertJsCondition(
      "getComputedStyle(document.body).getPropertyValue('--canvas-probe-scheme').trim() === 'dark'"
      . " && document.documentElement.getAttribute('data-canvas-scheme') === 'dark'",
      20000,
      'Forcing the scheme axis applies the page\'s dark-scheme styles.',
    );
    $session->executeScript("Drupal.Daedalus.CanvasVector.set({ scheme: 'auto' });");
    $this->assertJsCondition(
      "getComputedStyle(document.body).getPropertyValue('--canvas-probe-scheme').trim() === ''",
      20000,
      'Returning the scheme axis to auto restores the authored conditions.',
    );
    // Motion: same emulation mechanics on the reduced-motion axis.
    $session->executeScript("Drupal.Daedalus.CanvasVector.set({ motion: 'reduce' });");
    $this->assertJsCondition(
      "getComputedStyle(document.body).getPropertyValue('--canvas-probe-motion').trim() === 'reduce'",
      20000,
      'Forcing the motion axis applies the page\'s reduced-motion styles.',
    );
    $session->executeScript("Drupal.Daedalus.CanvasVector.set({ motion: 'auto' });");
    // Releasing the width through the facade restores the full canvas.
    $session->executeScript("Drupal.Daedalus.CanvasVector.set({ width: null });");
    $this->assertJsCondition(
      "document.documentElement.getAttribute('data-canvas-width') === null"
      . " && document.getElementById('daedalus-ui-stage').style.width === ''",
      20000,
      'Releasing the width axis restores the full canvas.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.getElementById('daedalus-ui-canvas-ruler')).display === 'none'",
      20000,
      'Releasing the width unconstrains the canvas: the ruler rests again.',
    );
    // A pose landing in the bandless base space still counts as
    // constrained: a scrub through base's floor must not blink the
    // ruler away just because the width crossed 1023.
    $session->executeScript("Drupal.Daedalus.CanvasVector.set({ width: 1200 });");
    $this->assertJsCondition(
      "getComputedStyle(document.getElementById('daedalus-ui-canvas-ruler')).display === 'block'",
      20000,
      'A posed width in the bandless base space still constrains: the ruler shows.',
    );
    // ...and while a handle drag is live the drag mark bridges even a
    // full release, so the moment a scrub crosses the release edge (the
    // pose lifts, nothing constrains) cannot thrash the display.
    $session->executeScript(<<<JS
      document.documentElement.classList.add('canvas-vector-resizing');
      Drupal.Daedalus.CanvasVector.set({ width: null });
      JS);
    $this->assertJsCondition(
      "getComputedStyle(document.getElementById('daedalus-ui-canvas-ruler')).display === 'block'",
      20000,
      'Mid-drag, a released width keeps the ruler up (the drag mark bridges it).',
    );
    $session->executeScript("document.documentElement.classList.remove('canvas-vector-resizing');");
    $this->assertJsCondition(
      "getComputedStyle(document.getElementById('daedalus-ui-canvas-ruler')).display === 'none'",
      20000,
      'The drag over with nothing posed, the ruler rests.',
    );
    // The other constraint arm: squeeze the window so the unposed stage
    // itself measures inside a band (below base's 1024 floor).
    $session->resizeWindow(900, 900);
    $this->assertJsCondition(
      "Drupal.Daedalus.CanvasVector.get().measured <= 1023"
      . " && getComputedStyle(document.getElementById('daedalus-ui-canvas-ruler')).display === 'block'",
      20000,
      'An unposed stage squeezed below base\'s floor constrains: the ruler shows.',
    );
    $session->resizeWindow(1920, 900);
    $this->assertJsCondition(
      "Drupal.Daedalus.CanvasVector.get().measured >= 1024"
      . " && getComputedStyle(document.getElementById('daedalus-ui-canvas-ruler')).display === 'none'",
      20000,
      'Back at natural full width in the bandless base, the ruler rests.',
    );

    // --- Boot params, chipless. ---
    // ?canvas* is the vector's out-of-band address: a fresh server render
    // (the edit cookie is still path-scoped to this node) must come up
    // already posed, with no chip cluster anywhere in the chrome — the
    // spine boots headless from the URL alone.
    $this->drupalGet($node->toUrl('canonical', [
      'query' => ['canvasWidth' => '414', 'canvasScheme' => 'dark', 'canvasMotion' => 'reduce'],
    ]));
    $this->assertJsCondition(
      "document.documentElement.getAttribute('data-canvas-width') === '414'"
      . " && document.getElementById('daedalus-ui-stage')?.style.width === '414px'"
      . " && document.documentElement.getAttribute('data-canvas-scheme') === 'dark'"
      . " && document.documentElement.getAttribute('data-canvas-motion') === 'reduce'",
      20000,
      'The ?canvas* boot params posed the fresh page load.',
    );
    // A booted pose is a constraint like any other: the ruler is up (so
    // the mode-exit assertion below observes a real transition).
    $this->assertJsCondition(
      "getComputedStyle(document.getElementById('daedalus-ui-canvas-ruler')).display === 'block'",
      20000,
      'The booted pose constrains the canvas: the ruler shows.',
    );
    $assert_session->elementNotExists('css', '#daedalus-ui-canvas-vector');
    // A style landing AFTER boot is still rewritten while axes are forced
    // (the CSSOM observer, exercised here without any chip UI in play).
    $session->executeScript(<<<JS
      const probe = document.createElement('style');
      probe.textContent = "@media (prefers-color-scheme: dark) { body { --canvas-probe-scheme: dark; } }";
      document.head.append(probe);
      JS);
    $this->assertJsCondition(
      "getComputedStyle(document.body).getPropertyValue('--canvas-probe-scheme').trim() === 'dark'",
      20000,
      'The booted scheme pose rewrites styles that arrive later.',
    );

    // --- Mode-exit reset, chipless. ---
    // Leaving edit mode must return the canvas to reality. The reset rides
    // the html data-daedalus-ui-mode attribute — the one signal both
    // the client toggle (DisableMode) and the server-rendered cookie path
    // write — so removing it is the exact exit edge the observer watches.
    $session->executeScript("document.documentElement.removeAttribute('data-daedalus-ui-mode');");
    $this->assertJsCondition(
      "document.documentElement.getAttribute('data-canvas-width') === null"
      . " && document.getElementById('daedalus-ui-stage')?.style.width === ''"
      . " && document.documentElement.getAttribute('data-canvas-scheme') === 'auto'"
      . " && document.documentElement.getAttribute('data-canvas-motion') === 'auto'",
      20000,
      'Leaving edit mode reset the whole vector.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.getElementById('daedalus-ui-canvas-ruler')).display === 'none'",
      20000,
      'The band ruler leaves with edit mode.',
    );

    // --- A multi-region section: the index's region slots against the
    // mounted page's gaps. ---
    // The selection index composes its rows from the mirror's one lean
    // tree (the blueprint's `layout`, compose.js), where a multi-region layout's named
    // slots are region nodes; the panel draws them as keyless region
    // rows and the canvas renders core's region wrappers. The client's
    // drag layer speaks {anchor, placement} alone (a block's region is the
    // flow its anchor sits in), so a drop lands in a named region by
    // anchoring beside a block that already sits there. The seam only a
    // browser proves: after a row drop, the region row the dropped block
    // sits under, the region node the mirror places it in and the region
    // wrapper the page renders it in all agree, and agree again after the
    // move to the section's other region. The page is built in PHP: the
    // recipe's add road offers the stylish container layout alone
    // (daedalus_stylish_layouts filters the picker and the placement
    // restrictions alike), while a section already carrying core's
    // layout_twocol_section still renders and still takes moves. One
    // stylish container holding the block to move, then the twocol
    // section with one text block in each region, so both regions stay
    // addressable.
    $uuid_service = \Drupal::service('uuid');
    $band_uuid = $uuid_service->generate();
    $twocol_uuid = $uuid_service->generate();
    $first_block_uuid = $uuid_service->generate();
    $second_block_uuid = $uuid_service->generate();
    $hopper_uuid = $uuid_service->generate();
    $text_component = function (string $uuid, string $region, string $text): SectionComponent {
      $block = BlockContent::create([
        'type' => 'text',
        'info' => $text,
        'reusable' => FALSE,
        'field_text_text' => $text,
        'field_text_tag' => 'p',
      ]);
      $block->save();
      return new SectionComponent($uuid, $region, [
        'id' => 'inline_block:text',
        'label' => 'Text',
        'label_display' => '0',
        'view_mode' => 'full',
        'block_revision_id' => $block->getRevisionId(),
        'block_serialized' => NULL,
      ]);
    };
    $band = new Section('daedalus_stylish_container');
    $band->setThirdPartySetting('daedalus_layout', 'uuid', $band_uuid);
    $band->appendComponent($text_component($hopper_uuid, 'content', 'Column hopper'));
    $twocol = new Section('layout_twocol_section');
    $twocol->setThirdPartySetting('daedalus_layout', 'uuid', $twocol_uuid);
    $twocol->appendComponent($text_component($first_block_uuid, 'first', 'First column'));
    $twocol->appendComponent($text_component($second_block_uuid, 'second', 'Second column'));
    $region_node = Node::create([
      'type' => 'page',
      'title' => 'Region slots test page',
      'layout_builder__layout' => [$band, $twocol],
    ]);
    $region_node->save();
    $this->drupalGet($region_node->toUrl());
    $this->enterEditMode();
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted on the region slots page.',
    );
    // The region a block sits in, read off the mirror's tree: the twocol
    // container's region node holding the uuid, or null.
    $region_of_js = "((uuid) => { const section = (Drupal.Daedalus.Blueprint.getBlueprint().layout ?? []).find((node) => node.uuid === '$twocol_uuid');"
      . " const region = (section?.children ?? []).find((child) => child.kind === 'region' && (child.children ?? []).some((node) => node.uuid === uuid));"
      . " return region ? region.region : null; })";
    // The region row a block's panel row sits under (the row before its
    // group, a keyless region row), by its label; null when its parent
    // row is no region row.
    $region_row_of_js = "((uuid) => { const row = document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"block:' + uuid + '\"]');"
      . " const parent = row?.parentElement.closest('ul[role=\"group\"]')?.previousElementSibling;"
      . " return parent?.classList.contains('daedalus-ui-tree__row--region') ? parent.querySelector('.daedalus-ui-outline__name')?.textContent : null; })";
    // The region wrapper a block renders in on the canvas (core's
    // layout__region--{name} class on the twocol's region), or null.
    $canvas_region_of_js = "((uuid) => { const el = document.querySelector(\"[data-main-entity] [data-selectable='container:$twocol_uuid'] [data-selectable='block:\" + uuid + \"']\");"
      . " const wrapper = el?.closest('.layout__region');"
      . " return wrapper ? ['first', 'second'].find((name) => wrapper.classList.contains('layout__region--' + name)) ?? null : null; })";
    // The index's region slots: the panel draws the two region rows from
    // the tree's region nodes, one block under each, and the hopper sits
    // under the container band with no region row (a lone region
    // collapses into its section row).
    $this->assertJsCondition(
      "$region_of_js('$first_block_uuid') === 'first' && $region_of_js('$second_block_uuid') === 'second' && $region_of_js('$hopper_uuid') === null"
      . " && $region_row_of_js('$first_block_uuid') === 'First' && $region_row_of_js('$second_block_uuid') === 'Second'"
      . " && $region_row_of_js('$hopper_uuid') === null"
      . " && $canvas_region_of_js('$first_block_uuid') === 'first' && $canvas_region_of_js('$second_block_uuid') === 'second'",
      20000,
      'The panel draws the twocol section\'s region rows from the tree\'s region nodes, each holding its block, and the canvas renders each block in its region wrapper. Tree: '
      . (string) $session->evaluateScript('JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()?.layout ?? null)'),
    );

    // Drop into the second region: the hopper's row, selected (a live
    // drag source), dragged onto the second block's row and dropped on its
    // 'after' zone. The tree-row target reads {anchor: second block,
    // after} off the index; the server places the hopper in the anchor's
    // flow, the second region.
    $hopper_row_js = "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"block:$hopper_uuid\"]')";
    $first_row_js = "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"block:$first_block_uuid\"]')";
    $second_row_js = "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"block:$second_block_uuid\"]')";
    $this->assertJsCondition(
      "(() => { const row = $hopper_row_js; return !!row && !row.hasAttribute('data-tree-inert') && row.getAttribute('draggable') === 'true'; })()",
      20000,
      'The hopper\'s row is live and armed as a drag source.',
    );
    $session->executeScript("$hopper_row_js.click();");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'block:$hopper_uuid'",
      20000,
      'Clicking the hopper\'s row selects it.',
    );
    $started = $this->startDrag($hopper_row_js, $second_row_js);
    $this->assertTrue($started, 'The row drag started: arrange is open on the hopper.');
    $this->finishDrag($hopper_row_js, $second_row_js);
    $this->assertJsCondition(
      "$region_of_js('$hopper_uuid') === 'second'",
      20000,
      'The drop landed the hopper in the twocol section\'s second region (the mirror\'s region node).',
    );
    $this->assertJsCondition(
      "$region_row_of_js('$hopper_uuid') === 'Second' && $canvas_region_of_js('$hopper_uuid') === 'second'"
      . " && $region_row_of_js('$second_block_uuid') === 'Second' && $region_row_of_js('$first_block_uuid') === 'First'",
      20000,
      'The hopper sits under the Second region row in the panel and inside the second region wrapper on the canvas; the other two blocks stayed put.',
    );
    // Rows re-project from the fresh mirror: the hopper's row is a new
    // element, so it is re-read before the next drag.
    $this->assertJsCondition(
      "(() => { const row = $hopper_row_js; return !!row && row.getAttribute('draggable') === 'true'; })()",
      20000,
      'The hopper\'s re-projected row is a drag source.',
    );

    // Move to the first region of the same section: the same gesture
    // onto the first block's row. One section, two regions, the block
    // crossing between them.
    $session->executeScript("$hopper_row_js.click();");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'block:$hopper_uuid'",
      20000,
      'The hopper is selected again for the second drag.',
    );
    $started = $this->startDrag($hopper_row_js, $first_row_js);
    $this->assertTrue($started, 'The second row drag started.');
    $this->finishDrag($hopper_row_js, $first_row_js);
    $this->assertJsCondition(
      "$region_of_js('$hopper_uuid') === 'first'",
      20000,
      'The move landed the hopper in the twocol section\'s first region.',
    );
    $this->assertJsCondition(
      "$region_row_of_js('$hopper_uuid') === 'First' && $canvas_region_of_js('$hopper_uuid') === 'first'"
      . " && $region_row_of_js('$second_block_uuid') === 'Second' && $region_row_of_js('$first_block_uuid') === 'First'"
      . " && (() => { const first = document.querySelector(\"[data-main-entity] [data-selectable='container:$twocol_uuid'] .layout__region--first\");"
      . " const order = Array.from(first.querySelectorAll(\"[data-selectable^='block:']\")).map((el) => el.getAttribute('data-selectable'));"
      . " return order[0] === 'block:$first_block_uuid' && order[1] === 'block:$hopper_uuid'; })()",
      20000,
      'The hopper sits under the First region row and after the first block inside the first region wrapper; the second region keeps its block.',
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // --- The one keyboard grammar on a page-built page. ---
    // Editing is a state of the ONE selection, entered by a gesture that
    // means "edit this" (Enter, a double-click at a leaf, the tree row's
    // Enter) and never by the selection landing. The frame's dash and the
    // edit's widget verbs follow the LIVE edit, never a prediction: the
    // store's editingKey (written only by the engine's editChanged report
    // through the one setter) IS the primary, or the selection is plain,
    // solid stroke, verbs on the face. A CONTAINER is plain because no
    // edit can land on it; an EDITABLE leaf is plain too until its edit
    // actually opens. This leg runs on a Layout Builder page with a
    // section and a text block added through the same mutation batch a
    // palette drop enqueues (client-named UUIDs, so the assertions never
    // race the response).
    $lb_node = Node::create([
      'type' => 'page',
      'title' => 'Editability gate test page',
    ]);
    $lb_node->save();
    $this->drupalGet($lb_node->toUrl());
    $this->enterEditMode();
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted on the page-built page.',
    );
    $section_uuid = $uuid_service->generate();
    $component_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_section',
              uuid: '$section_uuid',
              location: { anchor: Blueprint.getRoot().uuid, placement: 'inside' },
            },
            {
              op: 'add_element',
              uuid: '$component_uuid',
              block_type: 'text',
              properties: { field_text_text: 'Gate probe', field_text_tag: 'h3' },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__gatePlacementError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__gatePlacementError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$component_uuid')",
      20000,
      'The section and text block placement resolved.',
    );
    $gate_placement_error = $session->evaluateScript('window.__gatePlacementError ?? null');
    $this->assertNull($gate_placement_error, 'Placement failed: ' . ($gate_placement_error ?? ''));

    // A single block at CLEAN density: the face is the registry's own
    // tab partition (the flagged verbs as the chip's buttons), the
    // chevron opens the shared menu with the rest, and nothing appears
    // in both. The seam: the partition and the face are visible only
    // where both render. Density is set explicitly because the DEFAULT
    // tier is full, whose face is every verb (asserted below).
    $this->getSession()->executeScript(
      "document.documentElement.setAttribute('data-daedalus-ui-chrome-density', 'clean');"
    );
    $gate_block_key = 'block:' . $component_uuid;
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('$gate_block_key');");
    $face_labels = "Array.from(document.querySelectorAll('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verb:not(.daedalus-ui-frame__more)'))"
      . ".map((button) => button.getAttribute('aria-label'))";
    $this->assertJsCondition(
      "(() => { const labels = $face_labels;"
      . " return labels.includes('Duplicate') && labels.includes('Remove') && !labels.includes('Insert'); })()",
      20000,
      "A block's chip face carries the tab-flagged verbs and never an unflagged one.",
    );
    $this->getSession()->executeScript("document.querySelector('.daedalus-ui-frame__more').click();");
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-menu') !== null",
      20000,
      'The chevron opens the menu.',
    );
    $menu_labels = $this->getSession()->evaluateScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__label')).map((label) => label.textContent)"
    );
    // Configure registers in the same file as Duplicate and Remove (whose
    // face presence just proved that file loaded), so its listing is
    // deterministic where Insert's is not (add-panel's library does not
    // ride this fixture's page).
    $this->assertContains('Configure', $menu_labels, 'The chevron menu lists the unflagged verbs. Labels: ' . implode(', ', $menu_labels));
    $this->assertNotContains('Duplicate', $menu_labels, 'The chevron menu never repeats a face verb. Labels: ' . implode(', ', $menu_labels));
    $this->assertNotContains('Remove', $menu_labels, 'The chevron menu never repeats a face verb. Labels: ' . implode(', ', $menu_labels));
    // pressHotkey dispatches on document, so the menu's focus-scoped
    // keydown handler never sees an Escape from it; on document the key
    // is the global clear-selection binding, which would empty the
    // chip the next assertion reads. Dismiss the menu on its real
    // outside-pointerdown path instead; the selection survives.
    $this->getSession()->executeScript(
      "document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));"
    );
    // Density tiers on the chip (the chrome-density spine), a strictly
    // shrinking ladder: MINIMAL puts every applicable verb on the face
    // in menu order as icons only and unrenders the chevron, so
    // Configure, chevron-only at clean, surfaces as a face button; FULL
    // is the same face with text labels beside the icons. The frame
    // re-renders on the html attribute mutation, the same live mirror
    // the settings select writes; the full step also restores the
    // fixture's default tier.
    $this->getSession()->executeScript(
      "document.documentElement.setAttribute('data-daedalus-ui-chrome-density', 'minimal');"
    );
    $this->assertJsCondition(
      "(() => { const labels = $face_labels;"
      . " return document.querySelector('.daedalus-ui-frame__more') === null"
      . " && labels.includes('Configure') && labels.includes('Duplicate')"
      . " && document.querySelector('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__label') === null; })()",
      20000,
      'Minimal density lists every verb on the face, icons only, and unrenders the chevron.',
    );
    // The chrome tooltip spine (data-daedalus-ui-tooltip + the delegated engine):
    // below full density a top bar button's inline label is hidden, so
    // hovering it shows the singleton pill carrying the label. The seam:
    // the engine serving server-rendered chrome through nothing but the
    // attribute the button declares.
    $this->getSession()->executeScript(
      "document.querySelector('#edit-mode-top-bar [data-daedalus-ui-tooltip]')"
      . ".dispatchEvent(new PointerEvent('pointerover', { bubbles: true }));"
    );
    $this->assertJsCondition(
      "(() => { const pill = document.querySelector('.daedalus-ui-tooltip--floating');"
      . " const trigger = document.querySelector('#edit-mode-top-bar [data-daedalus-ui-tooltip]');"
      . " return pill !== null && pill.classList.contains('daedalus-ui-tooltip--visible')"
      . " && pill.getClientRects().length > 0"
      . " && pill.textContent === trigger.getAttribute('data-daedalus-ui-tooltip'); })()",
      20000,
      "Hovering an icon-only top bar button shows the tooltip pill with the button's label.",
    );
    $this->getSession()->executeScript(
      "document.querySelector('#edit-mode-top-bar [data-daedalus-ui-tooltip]')"
      . ".dispatchEvent(new PointerEvent('pointerout', { bubbles: true, relatedTarget: document.body }));"
    );
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-tooltip--floating').classList.contains('daedalus-ui-tooltip--visible') === false",
      20000,
      'Leaving the button hides the tooltip pill.',
    );
    // The same spine serves the preact-rendered chip face (the second
    // rendering model, opted in by nothing but the attribute): at minimal
    // density a verb button is icon-only, so hovering it shows its label
    // in the pill.
    $this->getSession()->executeScript(
      "document.querySelector('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verb')"
      . ".dispatchEvent(new PointerEvent('pointerover', { bubbles: true }));"
    );
    $this->assertJsCondition(
      "(() => { const pill = document.querySelector('.daedalus-ui-tooltip--floating');"
      . " const verb = document.querySelector('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verb');"
      . " return pill.classList.contains('daedalus-ui-tooltip--visible')"
      . " && pill.textContent === verb.getAttribute('data-daedalus-ui-tooltip'); })()",
      20000,
      "Hovering an icon-only chip face verb shows the tooltip pill with the verb's label.",
    );
    $this->getSession()->executeScript(
      "document.querySelector('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verb')"
      . ".dispatchEvent(new PointerEvent('pointerout', { bubbles: true, relatedTarget: document.body }));"
    );
    $this->getSession()->executeScript(
      "document.documentElement.setAttribute('data-daedalus-ui-chrome-density', 'full');"
    );
    $this->assertJsCondition(
      "(() => { const labels = $face_labels;"
      . " return document.querySelector('.daedalus-ui-frame__more') === null"
      . " && labels.includes('Configure') && labels.includes('Duplicate')"
      . " && document.querySelector('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__label') !== null; })()",
      20000,
      'Full density lists every verb on the face with its text label and unrenders the chevron.',
    );
    // Full density is "show everything" in the top bar too — on viewports
    // wide enough to afford it (labeled, the bar overflows a laptop width
    // and pushes the right toggles off-screen, so labels gate on 90rem):
    // the button's inline label is visible (the density mirror needs no
    // reload), and the engine never shows a pill over a visible label, so
    // the same hover that produced a tooltip at minimal now produces none.
    $this->getSession()->resizeWindow(1600, 800);
    $this->assertJsCondition(
      "document.querySelector('#edit-mode-top-bar [data-daedalus-ui-tooltip] .toolbar-button__label').getClientRects().length > 0",
      20000,
      "Full density on a wide viewport shows the top bar button's inline label.",
    );
    $this->getSession()->executeScript(
      "document.querySelector('#edit-mode-top-bar [data-daedalus-ui-tooltip]')"
      . ".dispatchEvent(new PointerEvent('pointerover', { bubbles: true }));"
    );
    $this->assertFalse(
      $this->getSession()->evaluateScript(
        "document.querySelector('.daedalus-ui-tooltip--floating').classList.contains('daedalus-ui-tooltip--visible')"
      ),
      'Hovering a labeled top bar button shows no tooltip pill.',
    );
    $this->getSession()->resizeWindow(1024, 768);
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // daedalus_layout's interaction guard surviving a mutation swap (the behavior
    // under test is daedalus_layout's js/settings/disable-interactive-elements.js;
    // the seam is cross-module: a block the canvas gains through a
    // daedalus_ui mutation response). A linked text block is placed
    // and duplicated TWICE, so the second clone's link arrived on the page
    // through two swaps after boot: its click never navigates (one
    // delegated capture-phase listener, registered once, covers links the
    // canvas gains later), its tabindex is -1 (the page-wide sweep ran on
    // the swapped subtree), and no per-link handler accrued on either
    // clone's link across the two mutations.
    $link_uuid = $uuid_service->generate();
    $clone_uuid = $uuid_service->generate();
    $second_clone_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        const mutate = (operations) => Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), { operations }));
        mutate([
          {
            op: 'add_element',
            uuid: '$link_uuid',
            block_type: 'text',
            properties: { field_text_text: 'Link probe', field_text_tag: 'p', field_text_url: '/user' },
            location: { anchor: '$section_uuid', placement: 'inside' },
          },
        ])
          .then(() => mutate([{ op: 'duplicate_element', element: '$link_uuid', uuid: '$clone_uuid' }]))
          .then(() => mutate([{ op: 'duplicate_element', element: '$clone_uuid', uuid: '$second_clone_uuid' }]))
          .then(() => { window.__linkGuardPlaced = true; })
          .catch((error) => {
            window.__linkGuardError = error.message;
          });
      })();
      JS);
    $this->assertJsCondition(
      "window.__linkGuardError !== undefined || window.__linkGuardPlaced === true",
      20000,
      'The linked text block and its two duplicates resolved.',
    );
    $link_guard_error = $session->evaluateScript('window.__linkGuardError ?? null');
    $this->assertNull($link_guard_error, 'Linked block placement failed: ' . ($link_guard_error ?? ''));
    $second_clone_link = "document.querySelector('[data-layout-builder-block-uuid=\"$second_clone_uuid\"] a[href]')";
    $this->assertJsCondition(
      "(() => { const link = $second_clone_link; return !!link && link.getAttribute('tabindex') === '-1'; })()",
      20000,
      "The second clone's link left the tabbing order (tabindex -1) after its mutation swap.",
    );
    $this->assertJsCondition(
      "(() => { const links = [document.querySelector('[data-layout-builder-block-uuid=\"$clone_uuid\"] a[href]'), $second_clone_link];"
      . " return links.every((link) => !!link && (!window.jQuery || jQuery._data(link, 'events') === undefined)); })()",
      20000,
      'No per-link click handler accrued on the clones\' links across two mutations: the guard is one delegated listener.',
    );
    // The click is a selection gesture on the clone and never opens an
    // edit that would outlive this leg.
    $session->executeScript(
      "(() => { const link = $second_clone_link;"
      . " window.__linkClickHref = link.getAttribute('href');"
      . " window.__linkClickPrevented = !link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window })); })()"
    );
    $lb_path = $lb_node->toUrl()->toString();
    $this->assertJsCondition(
      "window.__linkClickPrevented === true && window.location.pathname === '$lb_path'",
      20000,
      'Clicking the link (placed as /user) inside the duplicated block does not navigate in Edit Mode.',
    );
    // The placed value is root-relative; the rendered href carries the site's
    // base path (d.o CI serves the site at /web).
    $this->assertSame(Url::fromUserInput('/user')->toString(), $session->evaluateScript('window.__linkClickHref'), "The clone's link carries the placed URL as its href.");
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');
    // A container primary: no edit can land on a container, so it is a
    // plain selection: no dash, and the face carries the container's tab
    // verbs.
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('container:$section_uuid');");
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " const labels = Array.from(document.querySelectorAll('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verb:not(.daedalus-ui-frame__more)'))"
      . ".map((button) => button.getAttribute('aria-label'));"
      . " return frame && !frame.classList.contains('daedalus-ui-frame--editing')"
      . " && labels.includes('Duplicate') && labels.includes('Remove'); })()",
      20000,
      'A container selection is a plain selection: no dash, verbs on the face.',
    );
    // An editable primary landed by a selection write (the facade, the
    // popped-out inspector's road) is a plain selection too: a landing
    // never opens an editor, so the block sits
    // selected with the selection verbs (the Edit verb among them) on its
    // face and no dash.
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('block:$component_uuid');");
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " const labels = Array.from(document.querySelectorAll('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verb:not(.daedalus-ui-frame__more)'))"
      . ".map((button) => button.getAttribute('aria-label'));"
      . " return Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . " && Drupal.Daedalus.EditSession.isEditing() === false"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && frame && !frame.classList.contains('daedalus-ui-frame--editing')"
      . " && labels.includes('Edit'); })()",
      20000,
      'A programmatic selection of an editable block lands a plain selection: no edit, no dash, the Edit verb on the face.',
    );
    // (a) Enter on the selected leaf opens its editor through the engine:
    // daedalus_ui's dispatcher (the `edit.enter` binding, selection
    // context) calls the one editor door, which finds the merged block's
    // canvas leaf and asks daedalus_edit's engine to edit it. The LIVE
    // edit dresses the frame: the setter's report drove the store's
    // editingKey onto the primary, so the stroke dashes and the current
    // edit sits on this block's leaf.
    $this->pressHotkey('Enter');
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;"
      . " return Drupal.Daedalus.EditSession.isEditing() === true"
      . " && frame && frame.classList.contains('daedalus-ui-frame--editing')"
      . " && !!editing && !!document.querySelector(\"[data-selectable='block:$component_uuid']\")?.contains(editing.getPageElement()); })()",
      20000,
      'Enter on the selected block opened its edit through the engine: the frame wears the dash and the current edit sits on the block\'s leaf.',
    );
    // (b) Escape cancels the open edit and only that: the selection
    // stands, the frame is plain again with the selection verbs on its
    // face (the dash never predicts from editability; it follows the live
    // edit), and the mode is untouched.
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " return Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && Drupal.Daedalus.EditSession.isEditing() === false"
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . " && frame && !frame.classList.contains('daedalus-ui-frame--editing')"
      . " && document.querySelectorAll('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verb').length > 0; })()",
      20000,
      'Escape cancels the open edit: the block stays selected as a plain selection, no dash, verbs on the face.',
    );
    // (d) The arrows after a cancel move the selection and open nothing:
    // the traversal bindings are global, so they work whether or not an
    // edit was just open (the bug this topic started from: arrows dead
    // after an edit). Down steps to the next placed block in the section
    // (the linked block, placed right after this one), Up steps back.
    $this->pressHotkey('ArrowDown');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'block:$link_uuid'"
      . ' && Drupal.Daedalus.EditSession.isEditing() === false',
      20000,
      'ArrowDown after the cancel moved the selection to the next block and opened nothing.',
    );
    $this->pressHotkey('ArrowUp');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . ' && Drupal.Daedalus.EditSession.isEditing() === false',
      20000,
      'ArrowUp moved the selection back to the first block and opened nothing.',
    );
    // (b), the next rung: with no edit open, Escape clears the selection.
    // The mode and its chrome are unchanged: Escape never switches
    // anything.
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === null'
      . ' && Drupal.Daedalus.EditSession.isEditing() === false'
      . " && document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'"
      . " && document.documentElement.classList.contains('edit_mode')"
      . " && !document.getElementById('page_elements').classList.contains('daedalus-ui-hidden')",
      20000,
      'The next Escape clears the selection with Edit Mode and its chrome unchanged.',
    );
    // (c) Cmd+Enter commits and STAYS: the edit closes, the selection
    // survives, the frame is plain. Ctrl on the Linux runner ('mod'). The
    // press lands in the editing context (nothing typing: the key is
    // dispatched on document), the twin the edit registers for the typing
    // context is InlineEditBehaviorTest's.
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('block:$component_uuid');");
    $this->pressHotkey('Enter');
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === true',
      20000,
      'Enter re-opened the block\'s edit for the Cmd+Enter leg.',
    );
    $this->pressHotkey('Enter', ['ctrlKey' => TRUE]);
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " return Drupal.Daedalus.EditSession.isEditing() === false"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . " && frame && !frame.classList.contains('daedalus-ui-frame--editing'); })()",
      20000,
      'Cmd+Enter committed the edit and stayed: no edit open, the block still selected, the frame plain.',
    );
    // Two named reads of one projection (the seam, visible only where both
    // render): the merged text block's TREE row reads its content string
    // (labelForKey), while its CANVAS chip reads its type (typeForKey: the
    // bundle) — the canvas already shows what the block says, so the chip
    // names what it is.
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-frame__chip[data-selection-key=\"block:$component_uuid\"] .daedalus-ui-frame__name')?.textContent === 'Text'"
      . " && document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"block:$component_uuid\"]')?.textContent.includes('Gate probe')",
      20000,
      "A text block's canvas chip reads its type while its tree row reads its content.",
    );
    // The page-elements tree is the same grammar on another surface: a row
    // click is a plain selection, a container row and an editable row
    // alike (no editor opens from a click), and Enter on the focused
    // primary row is the editor door (g).
    $this->getSession()->executeScript(
      "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"container:$section_uuid\"]').click();"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'container:$section_uuid'"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement === null",
      20000,
      'A click on a container row is a plain selection.',
    );
    $this->getSession()->executeScript(
      "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"block:$component_uuid\"]').click();"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . ' && Drupal.Daedalus.Edit.CurrentlyEditingElement === null'
      . ' && Drupal.Daedalus.EditSession.isEditing() === false',
      20000,
      'A click on an editable tree row is a plain selection too: no editor opens from a click.',
    );
    // --- Block rename, round trip. ---
    // Seam: the inline input inside a treeitem (page-elements-tree.js
    // renders rename-commit.js's grammar, which admits a block beside a
    // section), the op (update_element {label} on an inline block's
    // component configuration), and the response re-minting the row's
    // words agree only in a browser. The row's NAME is the annotator's one
    // rule, unchanged: a merged text block names itself by its content, so
    // the row and the breadcrumb keep reading "Gate probe" after the rename
    // while the mirror's lean node carries the new label (a block whose
    // label renders as page content would show it on the canvas instead;
    // this block's label is hidden). The input seeds from the STORED label
    // (the lean node's `label`, absent when nothing was configured), never
    // from the shown name, and seeds from the renamed label on the next
    // rename: the round trip.
    $block_row = ".daedalus-ui-tree__row[data-tree-key=\"block:$component_uuid\"]";
    $rename_input_js = "document.querySelector('$block_row .daedalus-ui-tree__rename')";
    $stored_label_js = "(Drupal.Daedalus.Blueprint.componentForUuid(Drupal.Daedalus.Blueprint.getBlueprint(), '$component_uuid')?.label ?? '')";
    $this->dispatchDoubleClick("$block_row .daedalus-ui-outline__name");
    $this->assertJsCondition(
      "(() => { const input = $rename_input_js;"
      . " return !!input && document.activeElement === input"
      . " && input.value === $stored_label_js"
      . " && input.getAttribute('aria-label') === 'Block name'"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement === null; })()",
      20000,
      'Double-clicking a text block\'s row name opens the inline rename input, focused and seeded with the block\'s stored label, no editor opened.',
    );
    $session->executeScript("$rename_input_js.value = 'Hero copy';");
    $this->pressKeyOnFocused('Enter');
    $this->assertJsCondition(
      "(() => { const row = document.querySelector('$block_row');"
      . " return !!row && !$rename_input_js"
      . " && $stored_label_js === 'Hero copy'"
      . " && row.querySelector('.daedalus-ui-outline__name')?.textContent.includes('Gate probe')"
      . " && document.querySelector('.daedalus-ui-breadcrumb__crumb--current')?.textContent.includes('Gate probe')"
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'; })()",
      20000,
      'Enter committed update_element {label} on the block: the mirror\'s lean node carries "Hero copy", the input is gone, and the row and the breadcrumb keep the merged block\'s content as its name.',
    );
    $this->dispatchDoubleClick("$block_row .daedalus-ui-outline__name");
    $this->assertJsCondition(
      "(() => { const input = $rename_input_js; return !!input && document.activeElement === input && input.value === 'Hero copy'; })()",
      20000,
      'The next rename seeds its input from the renamed label: the round trip through the response.',
    );
    $this->pressKeyOnFocused('Escape');
    $this->assertJsCondition(
      "!$rename_input_js && $stored_label_js === 'Hero copy'"
      . " && document.activeElement?.getAttribute('data-tree-key') === 'block:$component_uuid'"
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'",
      20000,
      'Escape cancels the rename with the label untouched and the row focused and selected.',
    );
    // (g) The tree row's Enter opens the edit. The row is a treeitem in a
    // role=tree widget, which the keyboard sensor's focused-control rule
    // exempts, so a real press on the focused row reaches the dispatcher,
    // where Enter in the selection context is the editor door; the row
    // handles no Enter of its own. Dispatched on the focused row, as a
    // keyboard sends it.
    $this->getSession()->executeScript(
      "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"block:$component_uuid\"]').focus();"
    );
    $this->assertJsCondition(
      "document.activeElement?.getAttribute('data-tree-key') === 'block:$component_uuid'",
      20000,
      'The primary block\'s tree row holds focus for the Enter press.',
    );
    $this->pressKeyOnFocused('Enter');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . " && !!document.querySelector(\"[data-selectable='block:$component_uuid']\")?.contains(Drupal.Daedalus.Edit.CurrentlyEditingElement.getPageElement())",
      20000,
      'Enter on the focused tree row opened the primary block\'s editor through the dispatcher.',
    );
    // The LIVE edit dresses the frame: the setter's report drove the
    // store's editingKey onto the primary, so NOW the stroke dashes and
    // the face SWAPS to the edit's own verbs instead of hiding — the
    // registry's applies gate flips selection verbs out and edit-scoped
    // verbs in, so the text block's edit renders its widget entries (the
    // Tag and Link controls) on the chip.
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-frame')?.classList.contains('daedalus-ui-frame--editing')"
      . " && document.querySelectorAll('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verbs .daedalus-ui-frame__widget').length >= 2",
      20000,
      "The open edit wears the dash and the face swaps to the edit's widget verbs.",
    );
    // --- The press decision: one owner for "does this click end the edit".
    // The open edit's click handler is the single decider; chrome that
    // keeps an edit declares data-keeps-edit on its own root, everything
    // else commits it. The seam — a chrome-declared keep vs the commit
    // default — is visible only where both surfaces render with an edit
    // open, read through the facade's isEditing (the edit's own answer,
    // not daedalus_edit's global).
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === true',
      20000,
      'The facade reports the tree-opened edit as an open edit.',
    );
    // A press on the page-elements panel's NON-ROW surface declares
    // nothing, so it commits the edit; the press is outside the canvas, so
    // the selection stays. There is no session to leave.
    $this->getSession()->executeScript(
      "document.getElementById('page_elements')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === false'
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'",
      20000,
      "A press on the panel's non-row surface commits the edit and keeps the selection.",
    );
    // Re-open the edit for the Escape leg through the door: Enter on the
    // still-selected block.
    $this->pressHotkey('Enter');
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === true',
      20000,
      'Enter with the block still selected re-opened its editor for the Escape leg.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement === null',
      20000,
      'Escape ends the re-opened edit.',
    );
    // The seam: the setter's null report reaching the frame on a path
    // the edit did not initiate through the frame — Escape's cancel runs
    // inside the engine's blur, whose one setter reports the end, the
    // store's editingKey clears, and the still-selected primary gets its
    // solid chip and selection verbs back (the old armed-and-editable
    // prediction kept it name-only forever).
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " return frame && !frame.classList.contains('daedalus-ui-frame--editing')"
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . " && document.querySelectorAll('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verbs .daedalus-ui-frame__verb').length > 0; })()",
      20000,
      'Escape out of an open edit leaves a solid chip carrying selection verbs.',
    );
    // --- The selection door: the second road into the same engine. ---
    // The press decision owns a click; the selection door
    // (selection/select-door.js) owns every selection write that is not
    // one: a placement's `select` option (op-queue.js applySelect, what a
    // drag from the Add panel lands through), the facade's select, the
    // tree's row menu, the inspector's parent and wearer selects. With the
    // text block's edit open and text typed on its surface, a second text
    // block placed through Blueprint.mutate with `select` must END the
    // edit (a commit, never a cancel: the typed text is what the user
    // meant), land the selection on the placed block and leave the frame
    // plain on it, the same end a press decides. The seam only a browser
    // runs end to end: the queue applies the response (the region swap
    // captures and resumes the draft), then applySelect asks the door, the
    // door asks the engine (canEndEdit, then endEdit through the plugin's
    // blur, which enqueues the update_element behind the placement) and
    // the selection lands; the typed text reaches the mirror on the blur's
    // own round trip. Enter re-opens the edit on the still-selected block,
    // as the Escape leg did.
    $this->pressHotkey('Enter');
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === true'
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'",
      20000,
      'Enter re-opened the text block\'s editor for the selection door leg.',
    );
    // The typed text keeps "Gate probe" at its head: the picker leg below
    // finds the block's deepest text leaf by that content.
    $door_placed_uuid = $uuid_service->generate();
    $door_typed_text = 'Gate probe typed before a placement';
    $session->executeScript(<<<JS
      (() => {
        const text = document.querySelector(
          "[data-selectable='block:$component_uuid'][data-daedalus-edit-text], [data-selectable='block:$component_uuid'] [data-daedalus-edit-text]",
        );
        text.textContent = '$door_typed_text';
        text.dispatchEvent(new Event('input', { bubbles: true }));
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'add_element',
            uuid: '$door_placed_uuid',
            block_type: 'text',
            properties: { field_text_text: 'Placed over an open edit', field_text_tag: 'p' },
            location: { anchor: '$section_uuid', placement: 'inside' },
          }],
          select: '$door_placed_uuid',
        })).catch((error) => {
          window.__doorPlacementError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        if (window.__doorPlacementError !== undefined) {
          return true;
        }
        const Blueprint = Drupal.Daedalus.Blueprint;
        const edited = Blueprint.componentForUuid(Blueprint.getBlueprint(), '$component_uuid');
        const frame = document.querySelector('.daedalus-ui-frame');
        return Drupal.Daedalus.EditSession.isEditing() === false
          && Drupal.Daedalus.Selection.getSelected() === 'block:$door_placed_uuid'
          && !!frame && !frame.classList.contains('daedalus-ui-frame--editing')
          && JSON.stringify(edited).includes('$door_typed_text');
      })()
      JS,
      20000,
      'A placement with select over the open text block edit ended the edit, landed the selection on the placed block under a plain frame, and the typed text reached the mirror through the commit.',
    );
    $door_placement_error = $session->evaluateScript('window.__doorPlacementError ?? null');
    $this->assertNull($door_placement_error, 'The mid-edit placement failed: ' . ($door_placement_error ?? ''));
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // --- The element picker: the DevTools "select an element" toggle. ---
    // The page elements tree's picker button (page-elements/
    // picker-toggle.js, last of the tools at the end of the pinned root
    // row) arms the selection slice's pickerArmed, the
    // SECOND source of the one depth policy (selection/depth-policy.js):
    // armed, canvas hover and click resolve the deepest presented element
    // exactly as holding Cmd/Ctrl does, and the first canvas click the
    // pointer grammar handles disarms it. The button exists for
    // discoverability (nothing else in the chrome names the modifier), so
    // the proof walks the user's path through the button, never the
    // facade. The seams, each visible only where the panel, the canvas
    // and the open edit render together: the flag on the slice
    // (aria-pressed follows it), the policy reading the flag (the same
    // hover resolves the section bare and the block armed), (e) the
    // picker followed by a double-click on the nested leaf editing it (the
    // click consumes the arming and deep-selects, the deepen rule keeps
    // the leaf through the second click, the even click at chain index 0
    // is the editor door: no rule of its own, the resolver's), the press on
    // the toggle over an open edit (the toggle keeps nothing, so the edit
    // commits and the picker arms), Escape's first rung (the picker drops,
    // the selection stays), and Cmd+double-click editing the same nested
    // leaf in one gesture with the picker never involved.
    $picker_button = "document.querySelector('.daedalus-ui-tree__row--root .daedalus-ui-tree__tools .daedalus-ui-picker-toggle')";
    // The pointer lands on the deepest text-carrying element inside the
    // placed text block: its chain carries the block, then the section, so
    // the BOUNDARY policy stops at the section and only a deep gesture
    // reaches the block.
    $session->executeScript(<<<JS
      window.__pickerLeaf = () => {
        const block = document.querySelector('[data-layout-builder-block-uuid="$component_uuid"]');
        const leaves = Array.from(block.querySelectorAll('*'))
          .filter((el) => el.childElementCount === 0 && el.textContent.includes('Gate probe'));
        return leaves[leaves.length - 1] ?? block;
      };
      window.__pickerPointer = (type, init = {}) => {
        const el = window.__pickerLeaf();
        el.scrollIntoView({ block: 'center' });
        const r = el.getBoundingClientRect();
        el.dispatchEvent(new MouseEvent(type, Object.assign({ bubbles: true, cancelable: true, clientX: r.left + 4, clientY: r.top + 4 }, init)));
      };
      // A double-click as a pointer sends it: two clicks on the same point
      // carrying the OS click count as detail (1, then 2); Edit Mode reads
      // the even one as the double-click (selection/double-click.js), and
      // nothing listens to dblclick. A synthetic click's detail defaults
      // to 0, a single click, so the count is set on purpose.
      window.__pickerDoubleClick = (init = {}) => {
        [1, 2].forEach((detail) => window.__pickerPointer('click', Object.assign({}, init, { detail })));
      };
      JS);
    $this->assertJsCondition(
      "$picker_button?.getAttribute('aria-pressed') === 'false'",
      20000,
      'The picker button renders among the root row\'s tools, unpressed.',
    );
    // The contrast: a bare hover on the leaf stops at the section, the
    // boundary policy's outermost presented stop.
    $session->executeScript("window.__pickerPointer('mousemove');");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getHovered() === 'container:$section_uuid'",
      20000,
      'A bare hover inside the text block stops at its section: the boundary policy.',
    );
    // (a) Press the picker: the flag arms and the button shows it.
    $session->executeScript("$picker_button.click();");
    $this->assertJsCondition(
      "$picker_button.getAttribute('aria-pressed') === 'true'",
      20000,
      'Pressing the picker button arms it: aria-pressed follows the slice flag.',
    );
    // (b) The same hover now resolves the leaf: the policy reads the flag.
    $session->executeScript("window.__pickerPointer('mousemove');");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getHovered() === 'block:$component_uuid'",
      20000,
      'With the picker armed the same hover resolves the deepest presented element, the block.',
    );
    // (c) (e) The picker then a double-click on the nested leaf edits it:
    // the first click commits the leaf (resolved at picker depth) and
    // consumes the arming; the second click, the even one, is the
    // double-click: it finds the leaf at chain index 0 (the resolver's
    // deepen rule: pointing at the selection means the selection) and
    // calls the editor door.
    $session->executeScript('window.__pickerDoubleClick();');
    $this->assertJsCondition(
      "(() => { const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;"
      . " return Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . " && $picker_button.getAttribute('aria-pressed') === 'false'"
      . " && Drupal.Daedalus.EditSession.isEditing() === true"
      . " && !!editing && !!document.querySelector(\"[data-selectable='block:$component_uuid']\")?.contains(editing.getPageElement()); })()",
      20000,
      'The picker then a double-click on the nested leaf selected it, disarmed the picker and opened its edit.',
    );
    // (d) From an open inline edit the button still works: the toggle
    // lives in the page-elements panel, which keeps no edit, so the press
    // commits the edit (the selection stays), and the toggle arms.
    $session->executeScript("$picker_button.click();");
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === false'
      . " && $picker_button.getAttribute('aria-pressed') === 'true'"
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'",
      20000,
      'Pressing the picker over an open edit commits the edit, keeps the selection and arms the picker.',
    );
    // (e) Escape's first rung drops the picker and nothing else.
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "$picker_button.getAttribute('aria-pressed') === 'false'"
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'",
      20000,
      'Escape disarms the picker first and keeps the selection.',
    );
    // The hotkey is the same toggle: mod+shift+c (ctrl on the Linux
    // runner, DevTools' binding) arms, and a second press disarms.
    $this->pressHotkey('c', ['ctrlKey' => TRUE, 'shiftKey' => TRUE]);
    $this->assertJsCondition(
      "$picker_button.getAttribute('aria-pressed') === 'true'",
      20000,
      'The selector.picker hotkey arms the picker.',
    );
    $this->pressHotkey('c', ['ctrlKey' => TRUE, 'shiftKey' => TRUE]);
    $this->assertJsCondition(
      "$picker_button.getAttribute('aria-pressed') === 'false'",
      20000,
      'A second press of the hotkey disarms the picker.',
    );
    // (e) Cmd+double-click is the same one gesture with the modifier as
    // the depth signal: from nothing selected, the deep clicks resolve the
    // leaf under the pointer directly and the even click edits it.
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');
    $session->executeScript('window.__pickerDoubleClick({ metaKey: true });');
    $this->assertJsCondition(
      "(() => { const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;"
      . " return Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . " && $picker_button.getAttribute('aria-pressed') === 'false'"
      . " && Drupal.Daedalus.EditSession.isEditing() === true"
      . " && !!editing && !!document.querySelector(\"[data-selectable='block:$component_uuid']\")?.contains(editing.getPageElement()); })()",
      20000,
      'Cmd+double-click on the nested leaf deep-selected it and opened its edit in one gesture.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === false'
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'",
      20000,
      'Escape cancelled the Cmd+double-click edit with the block still selected.',
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // --- The stationary second double-click: the OS click count on click. ---
    // Seam: the browser fires `dblclick` only on the click whose OS count
    // is exactly 2, and macOS keeps counting fast stationary clicks past
    // it (3, 4, 5...) until the pointer moves past the slop or the
    // double-click interval lapses, so drilling section, block, editor at
    // one spot stalled after the first pair: the second pair arrived as
    // clicks 3 and 4 and no `dblclick` ever fired. Edit Mode reads the
    // gesture off `click` through `event.detail` instead, every even count
    // a double-click (selection/double-click.js). The trait sends that
    // grammar, two clicks with the count, and its third argument is the
    // first count, so the failing sequence is dispatched as a pointer
    // would: counts 1 and 2, then 3 and 4 at the same element with nothing
    // between (no mousemove, no pause). The chain here is two deep (the
    // text block's field is merged into its row), so the second pair's
    // even click is the drill's shape at a leaf: the editor opens.
    $stationary_block = "[data-main-entity] [data-selectable='container:$section_uuid'] [data-selectable='block:$component_uuid']";
    $this->dispatchDoubleClick($stationary_block);
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . " && Drupal.Daedalus.Selection.getDrill() === 'container:$section_uuid'"
      . ' && Drupal.Daedalus.EditSession.isEditing() === false',
      20000,
      'A double-click pair (OS click counts 1 and 2 on click) at a block inside a section selected the section and drilled to the block: the block selected, the section the drill context.',
    );
    $this->dispatchDoubleClick($stationary_block, [], 3);
    $this->assertJsCondition(
      "(() => { const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;"
      . " return Drupal.Daedalus.EditSession.isEditing() === true"
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . " && Drupal.Daedalus.Selection.getDrill() === 'container:$section_uuid'"
      . " && !!editing && !!document.querySelector(\"[data-selectable='block:$component_uuid']\")?.contains(editing.getPageElement()); })()",
      20000,
      'A second pair at the same spot arriving as OS click counts 3 and 4 on click advanced the gesture as a fresh pair would: the even click at the block (chain index 0) opened its editor with the selection and the drill context unchanged.',
    );
    // The Escape ladder back to nothing: the open edit cancels first (the
    // block stays selected under its drill context), then the selection
    // clears and the drill context with it.
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === false'
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . " && Drupal.Daedalus.Selection.getDrill() === 'container:$section_uuid'",
      20000,
      'Escape cancelled the edit the stationary second double-click opened, the block still selected under its drill context.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === null'
      . ' && Drupal.Daedalus.Selection.getDrill() === null',
      20000,
      'The next Escape cleared the selection and the drill context the stationary double-clicks built.',
    );

    // --- The collapse controls over the page elements outline. ---
    // The tree's rows collapse and expand through daedalus_ui's one
    // collapse model (outline/collapse.js): the pair of controls at the
    // end of the pinned root row and the `3`/`4` hotkeys act on the whole
    // outline at once, and the selection rule reveals the selected row's
    // path over a collapsed outline (reading B: the picker's road, no
    // third button). Node tests pin the posture math; the seam only a
    // browser proves is the root row's controls reaching the Outline's
    // posture and out to the rows' aria-expanded, a press on a control
    // staying the control's (the root row under it never selects), and
    // the dispatcher's `4` reaching an outline registered from inside a
    // Preact mount. The
    // page-built page holds the section, its block and the block's field
    // rows under the root, so a leaf sits at least three levels down.
    $collapse_all = "document.querySelector('.daedalus-ui-tree__row--root .daedalus-ui-tree__tools .daedalus-ui-collapse-all')";
    $expand_all = "document.querySelector('.daedalus-ui-tree__row--root .daedalus-ui-tree__tools .daedalus-ui-expand-all')";
    $foldable_rows = "Array.from(document.querySelectorAll('.daedalus-ui-tree [role=\"treeitem\"][aria-expanded]'))";
    // From everything open: expand all renders every row, so the deepest
    // keyed row and the chain above it can be read off the DOM (a row's
    // group follows its row inside the parent item).
    $session->executeScript("$expand_all.click();");
    $this->assertJsCondition(
      "$foldable_rows.length > 0"
      . " && $foldable_rows.every((row) => row.getAttribute('aria-expanded') === 'true')"
      . " && $expand_all.disabled && !$collapse_all.disabled"
      . " && Drupal.Daedalus.Selection.getSelected() === null",
      20000,
      'Expand all opened every foldable row through the root row\'s control: expand all disables, collapse all enables, and the press selected nothing.',
    );
    $session->executeScript(<<<JS
      (() => {
        const rows = Array.from(document.querySelectorAll('.daedalus-ui-tree [data-tree-key]'));
        const level = (row) => Number(row.getAttribute('aria-level'));
        const leaf = rows.reduce((deepest, row) => (level(row) > level(deepest) ? row : deepest), rows[0]);
        const chain = [];
        for (let group = leaf.parentElement.closest('ul[role="group"]'); group; group = group.parentElement.closest('ul[role="group"]')) {
          chain.push(group.previousElementSibling.getAttribute('data-tree-key'));
        }
        window.__collapseLeaf = leaf.getAttribute('data-tree-key');
        window.__collapseLeafLevel = level(leaf);
        window.__collapseChain = chain;
      })();
      JS);
    $this->assertGreaterThanOrEqual(3, (int) $session->evaluateScript('window.__collapseLeafLevel'), 'The page-built page renders a row at least three levels under the root for the reveal leg.');
    // Collapse all through the control: every foldable row closes, so no
    // grandchild row renders (the root never collapses, its children
    // stay), and the pair flips.
    $session->executeScript("$collapse_all.click();");
    $this->assertJsCondition(
      "$foldable_rows.length > 0"
      . " && $foldable_rows.every((row) => row.getAttribute('aria-expanded') === 'false')"
      . " && !document.querySelector('.daedalus-ui-tree [role=\"treeitem\"][aria-level=\"3\"]')"
      . " && $collapse_all.disabled && !$expand_all.disabled",
      20000,
      'Collapse all closed every foldable row through the root row\'s control: no grandchild row renders, collapse all disables and expand all enables.',
    );
    // The selection rule over the collapsed outline: selecting the deep
    // leaf (the facade, the picker's road) opens its ancestors and nothing
    // else, and the one reveal rule scrolls the row into the panel's
    // scrollport, under the pinned root row (the rows' scroll margin).
    $session->executeScript('Drupal.Daedalus.Selection.select(window.__collapseLeaf);');
    $this->assertJsCondition(
      "(() => {"
      . " const leaf = document.querySelector('.daedalus-ui-tree [data-tree-key=\"' + window.__collapseLeaf + '\"]');"
      . " if (!leaf) { return false; }"
      . " const chain = window.__collapseChain;"
      . " const rows = $foldable_rows;"
      . " const onPath = rows.filter((row) => chain.includes(row.getAttribute('data-tree-key')));"
      . " const offPath = rows.filter((row) => !chain.includes(row.getAttribute('data-tree-key')));"
      . " const port = leaf.closest('.daedalus-ui-panel__body').getBoundingClientRect();"
      . " const head = document.querySelector('.daedalus-ui-tree__row--root').getBoundingClientRect();"
      . " const box = leaf.getBoundingClientRect();"
      . " return onPath.every((row) => row.getAttribute('aria-expanded') === 'true')"
      . " && offPath.every((row) => row.getAttribute('aria-expanded') === 'false')"
      . " && head.top >= port.top - 1 && box.top >= head.bottom - 1 && box.bottom <= port.bottom + 1;"
      . " })()",
      20000,
      'Selecting a deep leaf over the collapsed outline reveals its path only: the ancestors open, every foldable row off the path stays collapsed, and the row sits inside the panel\'s scrollport, below the pinned root row.',
    );
    // The hotkey wire: `4` (expand all, the Panels section, remappable)
    // reaches the outline the tree registered from inside its mount.
    $this->pressHotkey('4');
    $this->assertJsCondition(
      "$foldable_rows.every((row) => row.getAttribute('aria-expanded') === 'true') && $expand_all.disabled",
      20000,
      'The expand-all hotkey (4) reached the outline registered from inside the tree mount: every row open, expand all disabled.',
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // --- A row drop onto a section's start: the gap line, not the row. ---
    // The page elements panel's drop unit is the gap LINE between rows
    // (drag/tree-gaps.js; the node spec owns the model, including the
    // depth the cursor's x picks where one line means several drops). The
    // seam only a browser proves is the surface end to end: a dragover on
    // a container row's bottom band resolves the START of that container
    // (before its first child), the caret's chip names the section (never
    // "New container in <page>", the root-flow gap after the section that
    // the row model offered on the same pixel line when the cursor came
    // from above), and the drop lands the block as the section's first
    // child in the mirror, the panel and the canvas alike. A second
    // section holding one text block is appended through the mutation
    // spine the first was built with (client-named UUIDs), then the first
    // section's probe block is dragged DOWN onto the second section's
    // row: arriving from above is the direction the row model got wrong.
    $second_section_uuid = $uuid_service->generate();
    $second_section_block_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_section',
              uuid: '$second_section_uuid',
              location: { anchor: Blueprint.getRoot().uuid, placement: 'inside' },
            },
            {
              op: 'add_element',
              uuid: '$second_section_block_uuid',
              block_type: 'text',
              properties: { field_text_text: 'Second section text', field_text_tag: 'p' },
              location: { anchor: '$second_section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__secondSectionError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__secondSectionError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$second_section_block_uuid')",
      20000,
      'The second section and its text block placement resolved.',
    );
    $second_section_error = $session->evaluateScript('window.__secondSectionError ?? null');
    $this->assertNull($second_section_error, 'Second section placement failed: ' . ($second_section_error ?? ''));
    // A root section's flow children, read off the mirror's one lean tree
    // in order (a region node, when the layout renders one, is
    // transparent: its blocks are the section's).
    $section_children_js = "((uuid) => { const section = (Drupal.Daedalus.Blueprint.getBlueprint().layout ?? []).find((node) => node.uuid === uuid);"
      . " const flat = (nodes) => (nodes ?? []).flatMap((node) => (node.kind === 'region' ? flat(node.children) : [node.uuid]));"
      . " return flat(section?.children); })";
    $probe_row_js = "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"block:$component_uuid\"]')";
    $second_section_row_js = "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"container:$second_section_uuid\"]')";
    $caret_chip_js = "document.querySelector('.daedalus-ui-caret__chip')";
    // The section row must SHOW its block for its bottom band to be its
    // start (a collapsed container offers no start line), so the outline
    // is opened through the root row's control (a no-op when nothing is
    // folded: the control is disabled).
    $session->executeScript("$expand_all.click();");
    $this->assertJsCondition(
      "(() => { const row = $second_section_row_js; const probe = $probe_row_js;"
      . " return !!row && row.getAttribute('aria-expanded') === 'true'"
      . " && !!probe && probe.getAttribute('draggable') === 'true'"
      . " && $section_children_js('$second_section_uuid')[0] === '$second_section_block_uuid'"
      . " && $section_children_js('$section_uuid').includes('$component_uuid'); })()",
      20000,
      'The second section\'s row shows its one block, and the probe block\'s row in the first section is armed as a drag source.',
    );
    $session->executeScript("$probe_row_js.click();");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'",
      20000,
      'Clicking the probe block\'s row selects it.',
    );
    // The y the gesture names: a container row's bottom band (8px,
    // tree-gaps.js) is the container's START. Two pixels above the edge
    // sits inside the band however the row's height rounds; the helper's
    // default would too, but the step says which band it means.
    $start_band_y = 'rect.bottom - 2';
    $started = $this->startDrag($probe_row_js, $second_section_row_js, $start_band_y);
    $this->assertTrue($started, 'The row drag started: arrange is open on the probe block.');
    $this->assertJsCondition(
      "(() => { const chip = $caret_chip_js; const label = $second_section_row_js.querySelector('.daedalus-ui-outline__name').textContent;"
      . " return !!chip && !chip.hidden && chip.textContent === label && !chip.textContent.startsWith('New container'); })()",
      20000,
      'Over the second section row\'s bottom band the caret\'s chip names the section (the drop is its start), not a new container in the page.',
    );
    $this->finishDrag($probe_row_js, $second_section_row_js, $start_band_y);
    $this->assertJsCondition(
      "(() => { const second = $section_children_js('$second_section_uuid');"
      . " return second[0] === '$component_uuid' && second[1] === '$second_section_block_uuid'"
      . " && !$section_children_js('$section_uuid').includes('$component_uuid'); })()",
      20000,
      'The drop landed the probe block as the second section\'s first child in the mirror, ahead of the block it held, and out of the first section.',
    );
    $this->assertJsCondition(
      "(() => { const row = $probe_row_js; const group = row?.parentElement.closest('ul[role=\"group\"]');"
      . " const owner = group?.previousElementSibling; const first = group?.querySelector('[data-tree-key]');"
      . " const canvas = document.querySelector(\"[data-main-entity] [data-selectable='container:$second_section_uuid']\");"
      . " const order = Array.from(canvas?.querySelectorAll(\"[data-selectable^='block:']\") ?? [])"
      . " .map((el) => el.getAttribute('data-selectable')).filter((key) => key.split(':').length === 2);"
      . " return owner?.getAttribute('data-tree-key') === 'container:$second_section_uuid' && first === row"
      . " && order[0] === 'block:$component_uuid' && order[1] === 'block:$second_section_block_uuid'; })()",
      20000,
      'The probe block\'s row is the first row under the second section\'s row, and its canvas element the first block inside the section\'s container.',
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // --- The Page form presents the root through the editor door. ---
    // The root entity's editor is the entity-form panel (daedalus_edit's
    // "Page form" icon), never a canvas leaf: opening it selects the root
    // row and presents the root through the one editor door, which, with
    // no canvas leaf to edit, asks the engine to present the root's
    // subject (this panel) and opens its first revealed widget as the
    // edit, so the selection moves to that widget's field key (as the Tab
    // walk into the panel always did) and the rest of the panel joins the
    // walk. The seam: the sidebar plugin's open() into the door and the
    // engine's presentSubject, which only a browser runs end to end. The
    // canvas-panel Tab seam itself is daedalus_edit's
    // InlineEditBehaviorTest's.
    $this->getSession()->executeScript(
      "document.querySelector('[data-sidebar-button-for=\"#daedalus_edit_entity_form\"]')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "(() => {"
      . " const panel = document.getElementById('daedalus_edit_entity_form');"
      . " const root = Drupal.Daedalus.Blueprint.getRoot();"
      . " const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;"
      . " return !!panel && !panel.classList.contains('daedalus-ui-hidden')"
      . " && !!root && (Drupal.Daedalus.Selection.getSelected() ?? '').startsWith('field:' + root.uuid + ':')"
      . " && Drupal.Daedalus.EditSession.isEditing() === true"
      . " && !!editing && editing.plugin.id === 'panel_widget'"
      . " && panel.contains(editing.getPageElement());"
      . " })()",
      20000,
      'Opening the Page form presented the root through the door: the panel shows the top-level form with its first revealed widget as the open edit, the selection on that field.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === false',
      20000,
      'Escape cancelled the panel widget edit the Page form opened.',
    );
    $this->getSession()->executeScript(
      "Drupal.Daedalus.SidebarManager.closeSidebar('daedalus_edit_entity_form');"
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // --- The cookie-arrival render. ---
    // Last, because the cookies are domain-wide (see the class doc). A page
    // arriving with the navigationMode cookie renders the Edit
    // Mode chrome server-side and, because the cookie says 'edit', the html
    // element carries the mode attribute that reveals it: what a new node's
    // post-creation redirect gives a real user.
    $chrome_node = Node::create([
      'type' => 'page',
      'title' => 'Edit mode chrome test page',
    ]);
    $chrome_node->save();
    $this->enterEditModeViaCookies($chrome_node->toUrl());
    $assert_session->elementExists('css', '#edit-mode-top-bar');
    $this->assertJsCondition("document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'", 20000, 'The Edit Mode chrome arrived visible from the cookie render.');
  }

}
