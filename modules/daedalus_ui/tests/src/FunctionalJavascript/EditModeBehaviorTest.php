<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\block\Entity\Block;
use Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives daedalus_ui's Edit Mode session on a real page.
 *
 * Navigation_plus is the UI framework the rest of the Daedalus plugs into:
 * a mode system, a tool system, and the chrome (toolbar, sidebars, top bar)
 * that hosts them. This test carries the framework's wiring end to end: the
 * two doors into Edit Mode (the toggle button's enable/persist/disable cycle
 * and the cookie-arrival render), the hotkey system (a headline feature:
 * per-tool keyboard shortcuts), tool *switching* between an already-
 * registered set of tools, selection, the keyboard model, history, and the
 * canvas vector.
 *
 * The scenario is deliberately cross-module: it switches by hotkey between
 * daedalus_ui's own 'selector' tool ('v') and daedalus_edit's 'edit' tool
 * ('e'). A keypress dispatching to a tool owned by a different module, with the
 * active-tool cookie and the toolbar's active button both following, is exactly
 * what daedalus_ui exists to provide: a framework that routes input to
 * whichever module contributed the tool. (Those two are the tools the JS-entry
 * path registers; daedalus_layout's tools need the server-rendered cookie path, which
 * does not reliably activate Edit Mode in the JS runtime on a cold load, so it
 * is unsuitable for a hotkey test that must drive live JS.)
 *
 * The fixture is DaedalusFixtureTrait: the site the daedalus recipe
 * installs (no profile), daedalus_edit_test_fixture's non-LB 'plain' bundle
 * installed on top, and the whole scenario run as daedalus_editor. The
 * recipe is daedalus_ui's require-dev dependency, the point of the
 * per-module harness: a contributor's merge request exercises the real
 * recipe site with the machinery-derived fixture bundle (whose toolbar
 * registers the daedalus_edit tool). daedalus_ui is a page-builder module,
 * so it requires the base daedalus, not the AI superset.
 *
 * One fixture, one scenario. A full site install from the recipe is by far
 * the most expensive part of a run (the framework installs and tears down
 * per method), so all behavior lives in the single test method. The recipe
 * ships its footer layout block in daedalus_theme's footer region: a
 * foreign, fully attributed host on the node page, for the editing-universe
 * step (the armed edit session ignores what the root blueprint's projection
 * does not contain).
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
   * the footer's second root section (01c25804) in the daedalus recipe's
   * footer content. The test installs from the recipe on disk, so a recipe
   * content change that drops this component fails the editing-universe
   * step below: re-pin it to the footer's LAST attributed block leaf,
   * because the Tab step and the chip-face step both depend on that
   * position.
   */
  private const FOOTER_COMPONENT_UUID = '1832e9b4-502a-4711-a5df-4c62de02d13f';

  /**
   * The Edit Mode session: entry, hotkeys, tools, selection, history.
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
   *   (drupalSettings.daedalusUi.hotKeys) is populated from each applicable
   *   tool's hot_key attribute.
   * - Baseline: the Edit tool (daedalus_edit's) is activated, so it is the active
   *   tool and its toolbar button carries the active state.
   * - Hotkey to daedalus_ui's own tool: pressing 'v' (the Selector tool's
   *   hot_key) routes through daedalus_ui's keydown handler to changeTool,
   *   so the active-tool cookie becomes selector and the Selector toolbar
   *   button becomes active while the Edit tool's clears.
   * - Hotkey to a contributed tool: pressing 'e' (the Edit tool's hot_key)
   *   switches to a tool owned by a different module, proving the handler is
   *   general and routes across module boundaries, not a one-way jump.
   * - The live-edit dress: on a page-built page (a real Layout Builder
   *   section and text block added through the mutation spine), every
   *   armed selection with no OPEN edit is a plain selection (solid
   *   stroke, verbs on the chip face) — a container, and an editable
   *   leaf alike; the dash and the name-only chip appear only while a
   *   live edit lands on the primary (the store's editingKey, written by
   *   the engine's editChanged report), and Escape's cancel restores the
   *   solid verb-carrying chip.
   * - The cookie-arrival render: a page arriving with the navigationMode
   *   and activeTool cookies (what daedalus_ui's InitialMode subscriber
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

    // The fixture's 'plain' type registers both daedalus_ui's
    // Selector tool and daedalus_edit's Edit tool in its Edit Mode toolbar: the
    // two-tool surface this hotkey test switches between.
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Hotkey tool-switching test node',
      'body' => [
        'value' => 'Body text.',
        'format' => 'daedalus_edit_formatted_text',
      ],
    ]);
    $node->save();

    // A foreign host on the same page: the recipe ships its footer layout
    // block (a layout_block block_content whose own layout carries an
    // attributed "Pro tip" text component) placed in daedalus_theme's
    // footer region. It renders on the node page fully attributed but
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
      "Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.title === 'Hotkey tool-switching test node'",
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

    // The binding map is rendered server-side from the applicable tools'
    // hot_key attributes (merged with any user remaps). Asserting it carries
    // both keys confirms both tools registered (and gives the keypresses below
    // something to match); a missing entry would mean the toolbar did not
    // register the tool, not a hotkey bug.
    $this->assertJsCondition(
      "drupalSettings.daedalusUi && drupalSettings.daedalusUi.hotKeys && drupalSettings.daedalusUi.hotKeys.selector.key === 'v' && drupalSettings.daedalusUi.hotKeys.edit.key === 'e'",
      20000,
    );

    // Baseline: activate the Edit tool through the mode manager (the proven,
    // deterministic path), so the starting active tool is known.
    $this->activateTool('edit');
    $this->assertTrue(
      $assert_session->elementExists('css', '#edit-mode-toolbar [data-tool="edit"]')->hasClass('active'),
      'The Edit tool button is active before any hotkey is pressed.',
    );

    // --- The editing universe: a foreign host is inert (off-canvas). ---
    // The footer placed in setUp renders its "Pro tip" text component on
    // this page fully attributed (selectable key, editable item) because
    // attribution is host-blind by design: the same render is cached and
    // shared across hosts, and daedalus_stylish's styling identity must land for
    // visitors too. So attribution is an address, never authorization. The
    // armed edit session's universe is the ROOT blueprint's projection (the
    // same Map the Selector resolves within), enforced at one seam
    // (keyForEditableItem, node-tested), and this step locks its WIRING into
    // the three doors only a real page with both trees can exercise: hover
    // names no key, click opens nothing (and throws nothing: core's
    // console-error check would fail the run on the mirror-miss error this
    // used to raise) and sends no request, and Tab from the last in-universe
    // leaf never lands in the foreign host. Its editing surface is its own
    // root (the block_content edit route).
    $footer_leaf = "[data-selectable='block:" . self::FOOTER_COMPONENT_UUID . "'][data-daedalus-edit-editor]";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $footer_leaf),
      'The footer "Pro tip" text component rendered attributed in the theme'
      . ' region.',
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
      'Hovering an in-universe leaf with the armed Edit tool names its key.',
    );
    $this->getSession()->executeScript($hover($footer_leaf));
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getHovered() === null',
      20000,
      'Hovering the foreign host\'s leaf names no key: it is outside the editing universe.',
    );
    // Click: dispatched, because a real pointer click at this viewport can
    // land on the floating toolbar pill instead of the leaf.
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
    // traversal has no next stop and leaves the session where it was.
    $this->getSession()->executeScript(
      "const leaves = Array.from(document.querySelectorAll('[data-main-entity] :is([data-daedalus-edit-id], [data-daedalus-edit-editor])'));"
      . "window.__lastKey = leaves[leaves.length - 1].closest('[data-selectable]').getAttribute('data-selectable');"
      . "Drupal.Daedalus.Selection.select(window.__lastKey);"
    );
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === window.__lastKey',
      20000,
      'The last in-universe leaf is selected as the Tab origin.',
    );
    $this->pressHotkey('Tab');
    $this->getSession()->wait(1500);
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement === null'
      . ' && Drupal.Daedalus.Selection.getSelected() === window.__lastKey',
      20000,
      'Tab past the last in-universe leaf never enters the foreign host.',
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // --- The portal: the foreign host's bridge to its own root. ---
    // The footer is inert here by design (above), and its editing surface
    // is the block_content edit route. The bridge is a portal: the server
    // stamps the placement root with the entity's label and editing URL
    // (DaedalusUiHooks::entityView, the URL from the one derivation,
    // DaedalusUi::getEditingUrl()), and the client's Portal component
    // reads those two attributes to paint a hover chip beside the selection
    // frame: the label and one Edit link. This step locks the seam (the
    // stamp to the chip) and the two ways through: the link's href and the
    // double-click drill applied across the entity boundary. The chip is a
    // Selector-tool affordance (a portal has no key, so the armed Edit tool
    // has nothing to do with it); the Edit tool is restored at the end so
    // the hotkey step below starts from the baseline it asserts.
    $footer_block_content = \Drupal::service('entity.repository')->loadEntityByUuid(
      'block_content',
      Block::load('daedalus_theme_footer')->getPlugin()->getDerivativeId(),
    );
    $footer_editing_url = \Drupal::service('daedalus_ui.ui')->getEditingUrl($footer_block_content)->toString();
    $footer_portal = '#block-daedalus-theme-footer[data-daedalus-ui-portal-url]';
    $this->activateTool('selector');
    $this->assertJsCondition(
      sprintf(
        "document.querySelector(\"%s\")?.getAttribute('data-daedalus-ui-portal-url') === %s"
        . " && document.querySelector(\"%s\").getAttribute('data-daedalus-ui-portal-label') === %s"
        . " && document.querySelector(\"%s\").contains(document.querySelector(\"%s\"))",
        $footer_portal,
        json_encode($footer_editing_url),
        $footer_portal,
        json_encode($footer_block_content->label()),
        $footer_portal,
        $footer_leaf,
      ),
      20000,
      'The footer placement root carries the portal stamp: the entity label and the editing URL, wrapping the inert leaf.',
    );
    $this->getSession()->executeScript($hover($footer_leaf));
    $portal_chip = '#daedalus-root .daedalus-ui-frame__chip--portal';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $portal_chip),
      'Hovering inside the portal painted the portal chip.',
    );
    $this->assertJsCondition(
      sprintf(
        "document.querySelector(\"%s\").querySelector('.daedalus-ui-frame__name').textContent === %s"
        . " && document.querySelector(\"%s\").querySelector('a.daedalus-ui-frame__verb').getAttribute('href') === %s"
        . " && Drupal.Daedalus.Selection.getHovered() === null",
        $portal_chip,
        json_encode($footer_block_content->label()),
        $portal_chip,
        json_encode($footer_editing_url),
      ),
      20000,
      'The portal chip reads the stamp: the footer\'s label and an Edit link to its editing route, and the selection hover stays clear.',
    );
    // Double-click inside the portal: the drill gesture crosses the entity
    // boundary and leaves for the editing route in this tab.
    $this->getSession()->executeScript(sprintf(
      "document.querySelector(\"%s\").dispatchEvent(new MouseEvent('dblclick', { bubbles: true, cancelable: true }));",
      $footer_leaf,
    ));
    $this->assertJsCondition(
      sprintf('window.location.pathname === %s', json_encode($footer_editing_url)),
      20000,
      'Double-clicking inside the portal navigated to the footer\'s editing route.',
    );
    // On its own root the footer IS the main entity: the same leaf that was
    // outside the universe on the node page now sits inside it.
    $this->assertNotNull(
      $assert_session->waitForElement('css', "[data-main-entity] $footer_leaf"),
      'The editing route renders the footer as the main entity, its leaf inside the universe.',
    );
    // Back to the node: Edit Mode persists across the reload (its cookie is
    // not path-scoped to the editing route), and the Edit tool is restored
    // for the hotkey step below.
    $this->drupalGet($node->toUrl());
    $this->assertJsCondition("document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'", 20000, 'Returning to the node keeps Edit Mode.');
    $this->assertJsCondition(
      "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Hotkeys && typeof Drupal.Daedalus.Hotkeys.register === 'function'",
      20000,
    );
    $this->activateTool('edit');
    $this->assertTrue(
      $assert_session->elementExists('css', '#edit-mode-toolbar [data-tool="edit"]')->hasClass('active'),
      'The Edit tool is active again before the hotkey step.',
    );

    // Press 'v': daedalus_ui's keydown handler matches it to its own
    // Selector tool and routes through changeTool, switching the active tool.
    $this->pressHotkey('v');
    $this->assertJsCondition("Drupal.Daedalus.getCookieValue('activeTool') === 'selector'", 20000);
    $this->assertTrue(
      $assert_session->elementExists('css', '#edit-mode-toolbar [data-tool="selector"]')->hasClass('active'),
      'Pressing the Selector hotkey activated the Selector tool.',
    );
    $this->assertFalse(
      $assert_session->elementExists('css', '#edit-mode-toolbar [data-tool="edit"]')->hasClass('active'),
      'Switching tools cleared the Edit tool button\'s active state.',
    );

    // Press 'e': the same handler switches to daedalus_edit's Edit tool, a tool
    // owned by a different module, proving the routing crosses module
    // boundaries rather than being a one-way jump.
    $this->pressHotkey('e');
    $this->assertJsCondition("Drupal.Daedalus.getCookieValue('activeTool') === 'edit'", 20000);
    $this->assertTrue(
      $assert_session->elementExists('css', '#edit-mode-toolbar [data-tool="edit"]')->hasClass('active'),
      'Pressing the Edit tool hotkey switched the active tool back to edit.',
    );

    // Selector behavior: the same fixture is the selection surface (see the
    // class doc for why this test carries it). With the Selector tool active,
    // hovering an attributed field draws the shared hover outline, clicking
    // commits the selection — readable through the public Selection facade,
    // the same API non-app consumers use — and Escape clears it.
    $this->pressHotkey('v');
    $this->assertJsCondition("Drupal.Daedalus.getCookieValue('activeTool') === 'selector'", 20000);
    $body_field = $assert_session->elementExists('css', sprintf('[data-selectable="field:%s:body"]', $node->uuid()));

    $body_field->mouseOver();
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-frame__stroke--hover') && !document.querySelector('.daedalus-ui-frame__stroke--hover').hidden",
      20000,
      'Hovering an attributed field with the Selector tool draws the hover outline.',
    );

    $body_field->click();
    $this->assertJsCondition(
      sprintf("Drupal.Daedalus.Selection.getSelected() === 'field:%s:body'", $node->uuid()),
      20000,
      'Clicking with the Selector tool commits the field as the selection.',
    );
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-frame__stroke--primary') && !document.querySelector('.daedalus-ui-frame__stroke--primary').hidden",
      20000,
      'The committed selection draws the selected outline.',
    );

    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === null',
      20000,
      'Escape clears the selection.',
    );

    // The pointer twin of that Escape: a KEYLESS click inside the canvas.
    // The canvas — the data-main-entity carrier — never resolves as a
    // selection (the doc'd resolver invariant), so a click landing on it
    // resolves no key, and the Selector treats a keyless click inside the
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
    // own, so the drill below lands on a part before the first field.
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
    // Right drills into the root's first row. With daedalus_layout installed that
    // row is the part tier's: the entity kind declares the layout root
    // (div.daedalus-layout-layout, daedalus_layout.page_builder.yml, since daedalus_layout 5c211b4)
    // as an unconditional shell part, so the index seats it first under
    // the root, ahead of the fields, and the step commits the part FACET on
    // the root key (getSelected() stays the root, getSelectedPart() names
    // the shell) exactly as Right from a linked Image lands on its Raster.
    // The shell claims the section list (`contains: sections`, since
    // daedalus_layout 31cce42), so the section rows are the shell's own rows and
    // the fields, unclaimed, stay its siblings in the root's rows: Down
    // from it reaches the first field row, never a section.
    $layout_root_part = '.' . LayoutBuilderEntityViewDisplay::LAYOUT_ROOT_CLASS;
    $this->pressHotkey('ArrowRight');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$root_key'"
      . " && Drupal.Daedalus.Selection.getSelectedPart() === '$layout_root_part'",
      20000,
      'ArrowRight drills to the root\'s first row: the entity kind\'s declared layout-root shell, a part facet on the root key.',
    );
    $this->pressHotkey('ArrowDown');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$first_child'"
      . ' && Drupal.Daedalus.Selection.getSelectedPart() === null',
      20000,
      'ArrowDown from the shell part steps to the first field row, its sibling in the root\'s rows.',
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
    // Enter on a focused row commits it as the selection, the keyboard
    // twin of the row click. Dispatched on the focused row, not document:
    // the handler is the row's.
    $this->pressHotkey('F6');
    $this->assertJsCondition(
      "document.activeElement?.getAttribute('data-tree-key') === '$root_key'",
      20000,
      'With no selection, F6 focuses the root row.',
    );
    $this->getSession()->executeScript(
      "document.activeElement.dispatchEvent(new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true}));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$root_key'",
      20000,
      'Enter on a focused tree row commits it as the selection.',
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
    // the same step-up shape ArrowLeft and Escape commit.
    $this->getSession()->executeScript("document.querySelector('button.daedalus-ui-breadcrumb__crumb').click();");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$root_key'",
      20000,
      'Clicking an ancestor crumb climbs the selection to it.',
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
    // A single block at CLEAN density: the face is the registry's own
    // tab partition (the flagged verbs as the chip's buttons), the
    // chevron opens the shared menu with the rest, and nothing appears
    // in both. The seam: the partition and the face are visible only
    // where both render. Density is set explicitly because the DEFAULT
    // tier is full, whose face is every verb (asserted below).
    $this->getSession()->executeScript(
      "document.documentElement.setAttribute('data-daedalus-ui-chrome-density', 'clean');"
    );
    $footer_key = 'block:' . self::FOOTER_COMPONENT_UUID;
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('$footer_key');");
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
    // is the Selector's clear-selection hotkey, which would empty the
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
    // element, so the sidebar does not need to be opened.
    $this->assertJsCondition(
      "document.querySelector('.hotkeys-list .configured-hotkey[data-binding-id=\"selector\"]')?.textContent === 'V'",
      20000,
      'The settings tray lists the Selector binding from the registry.',
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

    // Move off the Selector tool so the remapped key can prove a switch.
    $this->pressHotkey('e');
    $this->assertJsCondition("Drupal.Daedalus.getCookieValue('activeTool') === 'edit'", 20000);

    // Click the Selector row and press 'm' in the capture input. The input
    // is a typing surface, so this also exercises the typing guard: the
    // keystroke must rebind, not dispatch.
    $this->getSession()->executeScript(
      "const display = document.querySelector('.hotkeys-list .configured-hotkey[data-binding-id=\"selector\"]');"
      . "display.click();"
      . "display.nextElementSibling.dispatchEvent(new KeyboardEvent('keydown', { key: 'm', bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "document.querySelector('.hotkeys-list .configured-hotkey[data-binding-id=\"selector\"]').textContent === 'M'"
      . " && drupalSettings.daedalusUi.hotKeys.selector.key === 'm'",
      20000,
      'Capturing a key rebinds the binding client-side.',
    );

    // The rebind applies immediately: 'm' now switches to the Selector tool.
    $this->pressHotkey('m');
    $this->assertJsCondition("Drupal.Daedalus.getCookieValue('activeTool') === 'selector'", 20000);

    // Wait out the save round-trip, then prove persistence: a fresh page
    // render (the edit cookie is still set, so the chrome and its binding
    // map render server-side) serves the remapped key from the user entity.
    $this->assertJsCondition('jQuery.active === 0', 20000);
    $this->drupalGet($node->toUrl());
    $this->assertJsCondition(
      "drupalSettings.daedalusUi && drupalSettings.daedalusUi.hotKeys && drupalSettings.daedalusUi.hotKeys.selector.key === 'm'",
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
      fetch('/daedalus-ui/load-editable-page/node/{$node->id()}/nope', { credentials: 'same-origin' })
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

    // --- The live-edit dress on a page-built page. ---
    // The frame's dash and name-only chip follow the LIVE edit, never a
    // prediction: the store's editingKey (written only by the engine's
    // editChanged report through the one setter) IS the primary, or the
    // selection is plain — solid stroke, verbs on the face — no matter
    // the tool. A CONTAINER under the armed session is plain because no
    // edit can land on it; an EDITABLE leaf under the same tool is plain
    // too until its edit actually opens. This leg runs on a Layout
    // Builder page with a section and a text block added through the same
    // mutation batch a palette drop enqueues (client-named UUIDs, so the
    // assertions never race the response).
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
    $uuid_service = \Drupal::service('uuid');
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
    // The Selector is current, so the click is a selection gesture on the
    // clone and never opens an edit that would outlive this leg.
    $this->activateTool('selector');
    $session->executeScript(
      "(() => { const link = $second_clone_link;"
      . " window.__linkClickHref = link.getAttribute('href');"
      . " window.__linkClickPrevented = !link.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window })); })()"
    );
    $lb_path = $lb_node->toUrl()->toString();
    $this->assertJsCondition(
      "window.__linkClickPrevented === true && window.location.pathname === '$lb_path'",
      20000,
      'Clicking the link (href /user) inside the duplicated block does not navigate in Edit Mode.',
    );
    $this->assertSame('/user', $session->evaluateScript('window.__linkClickHref'), "The clone's link carries the placed URL as its href.");
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');
    $this->activateTool('edit');
    // The armed session with a container primary: no armed edit can land
    // on a container, so it is a plain selection: no dash, and the face
    // carries the container's tab verbs.
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('container:$section_uuid');");
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " const labels = Array.from(document.querySelectorAll('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verb:not(.daedalus-ui-frame__more)'))"
      . ".map((button) => button.getAttribute('aria-label'));"
      . " return frame && !frame.classList.contains('daedalus-ui-frame--editing')"
      . " && labels.includes('Duplicate') && labels.includes('Remove'); })()",
      20000,
      'An armed container selection is a plain selection: no dash, verbs on the face.',
    );
    // The same tool with an editable primary but NO open edit: still a
    // plain selection — the dash never predicts from the tool plus
    // editability; it waits for the live edit.
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('block:$component_uuid');");
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " return frame && !frame.classList.contains('daedalus-ui-frame--editing')"
      . " && document.querySelectorAll('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verb').length > 0; })()",
      20000,
      'An armed editable selection with no open edit stays a plain selection: no dash, verbs on the face.',
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
    // The tool defines the gesture on every surface: an armed plain click
    // on a CONTAINER row falls through to plain selection (no editor)...
    $this->getSession()->executeScript(
      "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"container:$section_uuid\"]').click();"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'container:$section_uuid'"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement === null",
      20000,
      'An armed click on a container row falls through to plain selection.',
    );
    // ...while an armed plain click on an EDITABLE row opens its editor,
    // the same gesture a canvas click commits (editItemForKey, the armed
    // gesture's programmatic twin the row activation routes through).
    $this->getSession()->executeScript(
      "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"block:$component_uuid\"]').click();"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'",
      20000,
      'An armed click on an editable tree row opens its editor.',
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
    // The session's click handler is the single decider; chrome that keeps
    // an edit declares data-keeps-edit on its own root, everything else
    // commits it. The seam — a chrome-declared keep vs the session's
    // commit default — is visible only where both surfaces render with an
    // edit open, read through the facade's isEditing (the session's own
    // answer, not daedalus_edit's global).
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === true',
      20000,
      'The facade reports the tree-opened edit as an open edit.',
    );
    // A press on the Edit Mode toolbar (its data-keeps-edit root, not a
    // tool button) keeps the edit open.
    $this->getSession()->executeScript(
      "document.getElementById('edit-mode-toolbar')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === true',
      20000,
      'A press on the Edit Mode toolbar keeps the open edit.',
    );
    // A press on the page-elements panel's NON-ROW surface (armed row
    // clicks open editors) declares nothing, so it commits the edit; the
    // press is outside the canvas, so the selection stays.
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
    // Re-open the edit for the Escape leg.
    $this->getSession()->executeScript(
      "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"block:$component_uuid\"]').click();"
    );
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === true',
      20000,
      'The editable row re-opened its editor for the Escape leg.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement === null',
      20000,
      'Escape ends the tree-opened edit.',
    );
    // The seam: the setter's null report reaching the frame on a path
    // the session did not initiate through the frame — Escape's cancel
    // runs inside the engine's blur, whose one setter reports the end,
    // the store's editingKey clears, and the still-armed, still-selected
    // primary gets its solid chip and selection verbs back (the old
    // armed-and-editable prediction kept it name-only forever).
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " return frame && !frame.classList.contains('daedalus-ui-frame--editing')"
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'"
      . " && document.querySelectorAll('.daedalus-ui-frame__chip--primary .daedalus-ui-frame__verbs .daedalus-ui-frame__verb').length > 0; })()",
      20000,
      'Escape out of an open edit leaves a solid chip carrying selection verbs.',
    );
    $this->getSession()->executeScript('Drupal.Daedalus.Selection.clear();');

    // --- The cookie-arrival render. ---
    // Last, because the cookies are domain-wide (see the class doc). A page
    // arriving with the navigationMode/activeTool cookies renders the Edit
    // Mode chrome server-side and, because the cookie says 'edit', the html
    // element carries the mode attribute that reveals it: what a new node's
    // post-creation redirect gives a real user.
    $chrome_node = Node::create([
      'type' => 'page',
      'title' => 'Edit mode chrome test page',
    ]);
    $chrome_node->save();
    $this->enterEditModeViaCookies($chrome_node->toUrl(), 'selector');
    $assert_session->elementExists('css', '#edit-mode-top-bar');
    $this->assertJsCondition("document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'", 20000, 'The Edit Mode chrome arrived visible from the cookie render.');
  }

}
