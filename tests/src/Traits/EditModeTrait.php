<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Traits;

use Behat\Mink\Element\NodeElement;
use Drupal\Core\Url;

/**
 * Drives daedalus_ui's Edit Mode and hotkeys in a browser test.
 *
 * daedalus_ui is the UI framework the rest of the Daedalus plugs into, so
 * almost every page-builder behavior test first has to get a page into Edit
 * Mode. This trait gathers those shared moves so each test does not re-copy
 * them. It is meant for a WebDriverTestBase: the methods rely on
 * getSession(), assertJsCondition(), and drupalGet() from the using class.
 *
 * There are two ways into Edit Mode and they are NOT equivalent in the JS they
 * load, so both are provided:
 * - enterEditMode() drives the mode manager in the live JS runtime
 *   (EnableMode).
 *   It reliably activates Edit Mode and the keydown hotkey listener, but the
 *   chrome it renders carries no sidebar panel: the modules' Edit Mode assets
 *   ride the panels' build() arrays (daedalus_edit's editing library on the
 *   form panel, daedalus_layout's Add panel palette), so flows that need
 *   them cannot use it.
 * - enterEditModeViaCookies() reproduces the server-rendered cookie path a real
 *   landing_page takes (daedalus_ui's InitialMode subscriber). It renders
 *   the full Edit Mode chrome, which attaches every applicable sidebar panel
 *   (with its build()-attached assets), but does not reliably activate Edit
 *   Mode in the JS runtime on a cold load. Chrome-asset-dependent flows need
 *   this entry; live-JS flows (like the hotkey listener) need the other.
 */
trait EditModeTrait {

  /**
   * Waits for the page-builder JS to load, then drives Edit Mode on.
   *
   * The editing UI is delivered as deferred ES modules and a bundled edit-mode
   * app that load after Drupal's first attachBehaviors(), so a fresh page load
   * is not yet "in" Edit Mode even with the cookie set. This waits for the mode
   * manager to register, then drives Edit Mode on through it (the deterministic
   * equivalent of a user clicking the Edit Mode toggle). EnableMode() calls the
   * mode's enable(), which AJAX-reloads the entity with editing attribution
   * applied and, once that reload resolves, adds the html.edit_mode class and
   * enables hotkeys. Waiting on that class is therefore the signal that Edit
   * Mode is fully entered and the editing markup is in place.
   */
  protected function enterEditMode(): void {
    $session = $this->getSession();

    // Wait for the deferred modules to register the daedalus_ui managers.
    $session->wait(20000, "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.ModeManager && typeof Drupal.Daedalus.ModeManager.getPlugin === 'function'");

    // Enter Edit Mode through the mode manager (reveals the chrome, sets the
    // cookie, and AJAX-reloads the entity with editing attribution).
    $session->executeScript("Drupal.behaviors.DaedalusUiModes.EnableMode(Drupal.Daedalus.ModeManager.getPlugin('edit'));");

    // The edit_mode class is added only after the reload resolves, so it is the
    // signal that Edit Mode is fully entered and the editing markup is in
    // place.
    $this->assertJsCondition("document.documentElement.classList.contains('edit_mode')", 20000);
  }

  /**
   * Enters Edit Mode via the server-rendered cookie path.
   *
   * Reproduces how daedalus_ui's InitialMode subscriber starts a real
   * landing_page: it writes a path-scoped navigationMode=edit cookie onto
   * the post-creation redirect, and the next render of the page builds the
   * full Edit Mode chrome server-side. Setting that cookie and re-requesting
   * the page is the faithful equivalent.
   *
   * Unlike enterEditMode(), this renders the full Edit Mode chrome: the top
   * bar and the sidebar panels, whose build() arrays carry their own assets
   * (daedalus_edit's editing library and CKEditor attachments on the form
   * panel, daedalus_layout's Add panel palette JS). Flows that depend on any
   * of those must enter this way.
   *
   * The session must already be on the site's domain (e.g. after drupalLogin()
   * or a prior drupalGet()) so the cookies can be set; the caller is expected
   * to have navigated at least once before calling this.
   *
   * @param \Drupal\Core\Url|string $url
   *   The page to render in Edit Mode.
   */
  protected function enterEditModeViaCookies(Url|string $url): void {
    $session = $this->getSession();
    $session->setCookie('navigationMode', 'edit');
    $this->drupalGet($url);
  }

  /**
   * Clicks the daedalus_ui Edit Mode toggle button.
   *
   * Unlike enterEditMode(), which drives the mode manager directly, this
   * exercises the user-facing toggle: a real click on the visible mode
   * button, whose handler (the DaedalusUiModes behavior) enables or
   * disables Edit Mode depending on the current navigationMode cookie.
   *
   * The listener is bound by the product itself on every cold load (module
   * scripts execute before DOMContentLoaded; core attaches behaviors at
   * DOMContentLoaded), so this deliberately does NOT re-run
   * attachBehaviors(): a binding break must fail the test. It only waits
   * for the edit-mode app to boot (the handler looks the plugin up and
   * console-errors before boot, which would fail the test), then issues a
   * real WebDriver click, the same code path a user's click runs.
   */
  protected function clickEditModeToggle(): void {
    $session = $this->getSession();

    // Wait until the edit mode plugin is registered: the click handler looks
    // it up by the button's data-mode, and getPlugin() throws if it is
    // missing.
    $session->wait(20000, "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.ModeManager && (function () { try { return !!Drupal.Daedalus.ModeManager.getPlugin('edit'); } catch (e) { return false; } })()");

    // The mode button renders both in core's top bar (the entry) and inside
    // the edit chrome (the exit); out of mode the chrome's copy is
    // zero-boxed, so click whichever instance is currently visible.
    $clicked = FALSE;
    foreach ($session->getPage()->findAll('css', '.daedalus-ui-mode-button[data-mode="edit"]') as $button) {
      if ($button->isVisible()) {
        $button->click();
        $clicked = TRUE;
        break;
      }
    }
    $this->assertTrue($clicked, 'A visible Edit Mode toggle button was clicked.');
  }

  /**
   * Clicks a button and waits for the resulting full-page navigation.
   *
   * For gestures whose handler navigates (Save, a confirm dialog's submit):
   * the redirect can land on the SAME URL (e.g. Save returns to the page out
   * of Edit Mode), so waiting on the URL cannot see it, and DOM waits race
   * against the old document. A window variable is destroyed exactly when
   * the browser swaps documents, so stamping one before the click and
   * waiting for it to vanish is the deterministic navigation signal.
   *
   * @param \Behat\Mink\Element\NodeElement $button
   *   The element whose click navigates.
   */
  protected function clickAndWaitForReload(NodeElement $button): void {
    $session = $this->getSession();
    $session->executeScript('window.__preNavigation = true;');
    $button->click();
    $this->assertTrue(
      $session->wait(20000, "window.__preNavigation === undefined && document.readyState === 'complete'"),
      'The click landed a new document.',
    );
  }

  /**
   * Dispatches a double-click on an element, the two clicks a pointer sends.
   *
   * A browser's double-click is two click events on one target, each
   * carrying the OS click count as `detail` (1 then 2), and Edit Mode's one
   * pointer grammar reads the gesture off that count (selector-events.js,
   * selection/double-click.js): the odd click commits the selection the
   * depth policy resolves, and the even click drills one level into the
   * selection or, at a LEAF (the selection is the innermost presented key
   * under the pointer), opens its editor through the one editor door. No
   * `dblclick` is sent because nothing in Edit Mode listens to it: the
   * browser fires it only at count 2, and macOS keeps counting fast
   * stationary clicks past that (3, 4, 5...) until the pointer moves past
   * the slop or the double-click interval lapses, so a second double-click
   * at one spot arrives as counts 3 and 4 with no `dblclick` at all.
   * $first models that: the default 1 is a fresh pair, 3 is the fast
   * stationary second pair macOS reports after a first double-click, and
   * the gesture must advance on it exactly as on a fresh pair. With
   * ['metaKey' => TRUE] the gesture is deep (Cmd, the one depth signal):
   * both clicks carry it, so the odd click resolves the deepest presented
   * element under the pointer and the even click edits that leaf in one
   * gesture. Dispatched, not driven, for targets the fixed chrome overlaps
   * in the headless layout; where a real pointer matters (a link that must
   * not navigate) use Mink's NodeElement::doubleClick() instead, which
   * goes through WebDriver and arrives with real counts.
   *
   * @param string $selector
   *   A CSS selector for the target (document.querySelector).
   * @param array $init
   *   Extra MouseEvent init entries, e.g. ['metaKey' => TRUE].
   * @param int $first
   *   The OS click count of the first click; the second is $first + 1.
   */
  protected function dispatchDoubleClick(string $selector, array $init = [], int $first = 1): void {
    $selector = json_encode($selector);
    $init = json_encode(['bubbles' => TRUE, 'cancelable' => TRUE] + $init);
    $this->getSession()->executeScript(<<<JS
      (() => {
        const target = document.querySelector($selector);
        if (!target) {
          throw new Error('dispatchDoubleClick: no element matches ' + $selector);
        }
        target.scrollIntoView({ block: 'center' });
        [$first, $first + 1].forEach((detail) => {
          target.dispatchEvent(new MouseEvent('click', { ...$init, detail }));
        });
      })();
      JS);
  }

  /**
   * Hovers an element through the pointer grammar's mousemove.
   *
   * Edit Mode's hover is a document-level mousemove resolved by the same
   * pointer grammar a click runs (selector-events.js), and it is what arms
   * the canvas drag delegate: the hovered movable is the one the
   * `[data-main-entity]` wrapper's dragstart carries (drag/selector-drag.js).
   * A real pointer at the headless viewport can land on the fixed chrome
   * instead of the element, so the move is dispatched four pixels inside
   * the element's top-left corner. The target is a JS expression resolving
   * to the element (not a CSS selector), so a step can hover a leaf it
   * reached through its own walk.
   *
   * @param string $selector_js
   *   A JS expression resolving to the element to hover.
   */
  protected function dispatchHover(string $selector_js): void {
    $named = json_encode($selector_js);
    $this->getSession()->executeScript(<<<JS
      (() => {
        const target = $selector_js;
        if (!target) {
          throw new Error('dispatchHover: no element for ' + $named);
        }
        target.scrollIntoView({ block: 'center' });
        const rect = target.getBoundingClientRect();
        target.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: rect.left + 4, clientY: rect.top + 4 }));
      })();
      JS);
  }

  /**
   * Dispatches a single keydown for a hotkey binding.
   *
   * Fires the exact event daedalus_ui's hotkey dispatcher listens for (a
   * keydown on document, matched against the binding registry), which is the
   * real input path a user's keypress runs. WebDriver's own key synthesis
   * depends on element focus and does not reliably reach a document-level
   * listener, so the event is dispatched directly.
   *
   * @param string $key
   *   The key to press (e.g. 'escape', 'p' for the Page elements panel).
   * @param array $init
   *   Extra KeyboardEvent init entries, e.g. ['ctrlKey' => TRUE] for a
   *   modifier combo.
   */
  protected function pressHotkey(string $key, array $init = []): void {
    $this->dispatchKeyEvent('keydown', $key, $init);
  }

  /**
   * Dispatches a single keyup, releasing a held hotkey binding.
   *
   * @param string $key
   *   The key to release.
   * @param array $init
   *   Extra KeyboardEvent init entries.
   */
  protected function releaseHotkey(string $key, array $init = []): void {
    $this->dispatchKeyEvent('keyup', $key, $init);
  }

  /**
   * Dispatches a single keydown on the focused element, as a keyboard does.
   *
   * PressHotkey() targets document, where the leader's keyboard sensor
   * listens. A surface that takes a key at document CAPTURE and stops it
   * (the floating surface's Escape, Drupal.Daedalus.popover) can stop a
   * press that started below document, never one dispatched on document
   * itself (same-target listeners all run), so a leg expecting the
   * surface to consume the key starts it where a real press would: on
   * document.activeElement. Either way the session's own Escape binding
   * declines a press the surface consumed (the sensor reads
   * defaultPrevented into the stroke's consumed fact, edit-session.js
   * onEscape declines on it, and the surface's capture listener runs
   * first even at document), so closing a widget popover and cancelling
   * the edit are always two presses.
   *
   * @param string $key
   *   The key to press.
   * @param array $init
   *   Extra KeyboardEvent init entries.
   */
  protected function pressKeyOnFocused(string $key, array $init = []): void {
    $this->dispatchKeyEvent('keydown', $key, $init, 'document.activeElement');
  }

  /**
   * Opens a set of the Add panel by its heading, when it is folded.
   *
   * The Add panel (daedalus_layout's `place_block`, a bundled Preact
   * surface) is one list of folding sets, and a folded set draws no
   * rows, so a step that reaches a row first opens the set it sits in.
   * The set ids are the model's: `favorites`, `group:{id}`, `all`, and
   * `kind:{kind}` for All's kinds (`kind:basic` for the Container and
   * the block types, `kind:blueprint`, `kind:{core category}`). A kind's
   * heading exists only while All is open: open `all` first.
   *
   * @param string $set_id
   *   The set's id.
   */
  protected function openAddPanelSet(string $set_id): void {
    $heading = "document.querySelector('#place_block [data-daedalus-layout-add-panel-set=\"$set_id\"]')";
    $this->getSession()->executeScript(
      "(() => { const heading = $heading; if (heading && heading.getAttribute('aria-expanded') === 'false') { heading.click(); } })();"
    );
    $this->assertJsCondition(
      "($heading)?.getAttribute('aria-expanded') === 'true'",
      10000,
      "The Add panel's set $set_id is open.",
    );
  }

  /**
   * Reveals a face's row under All in the Add panel and answers its selector.
   *
   * A face is in the DOM once per set it belongs to (a row is keyed by
   * set and face), so a step names WHICH row it drives: this is the one
   * under All's kind for the face (Basics for the Container and the
   * block types, Blueprints for a `blueprint:ID`, core's category for any
   * other plugin), with All and the kind opened on the way. The kind is
   * read from the page's own attached faces, never guessed.
   *
   * @param string $face_id
   *   The face's id: a block plugin id, or `blueprint:ID`.
   *
   * @return string
   *   The CSS selector of the revealed row.
   */
  protected function revealAddPanelRow(string $face_id): string {
    $id = json_encode($face_id, JSON_THROW_ON_ERROR);
    $kind = (string) $this->getSession()->evaluateScript(<<<JS
      (() => {
        const id = $id;
        if (id.startsWith('blueprint:')) {
          return 'kind:blueprint';
        }
        const face = (drupalSettings.daedalusLayout?.addPanel?.faces ?? []).find((candidate) => candidate.id === id);
        return face ? 'kind:' + face.kind : '';
      })()
      JS);
    $this->assertNotSame('', $kind, "The Add panel's faces name $face_id.");
    $this->openAddPanelSet('all');
    $this->openAddPanelSet($kind);
    $selector = "#place_block [data-daedalus-layout-add-panel-in=\"$kind\"][data-daedalus-layout-add-panel-row=\"$face_id\"]";
    $this->assertNotNull(
      $this->assertSession()->waitForElement('css', $selector, 10000),
      "The Add panel draws $face_id under $kind.",
    );
    return $selector;
  }

  /**
   * Turns the page elements panel's Markup view on or off via the Show menu.
   *
   * The Markup view (daedalus_ui's built-in view toggle, the view slice's
   * setViewVisibility) decides whether the panel draws declared shell
   * part rows, and so what Cmd-click, the picker and the arrow keys can
   * reach (view-registry.js's reach gate). Every fresh session seeds it
   * OFF. The toggle is driven the way a user drives it, through the Show
   * kebab's real menu: the kebab opened, the Markup item found as a
   * menuitemcheckbox still wearing the state it is about to leave, the
   * item activated, and the floating surface gone (the menu's
   * close-then-run contract). The flip is then witnessed on the item
   * itself: the menu is reopened, the item reads the new state, and the
   * kebab closes it again without a toggle (the anchor counts as inside).
   * The panel's consequence (a shell row drawn or dropped) is the
   * caller's to assert, since which row it is depends on the fixture.
   *
   * The page elements panel must be mounted with its Show kebab before
   * the call; a step that is unsure waits for `.daedalus-ui-show-menu__button`.
   *
   * @param bool $on
   *   TRUE to turn the view on, FALSE to turn it off.
   */
  protected function setMarkupView(bool $on): void {
    $session = $this->getSession();
    $kebab_js = "document.querySelector('.daedalus-ui-show-menu__button')";
    $item_js = "Array.from(document.querySelectorAll('.daedalus-ui-popover > .daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent === 'Markup') ?? null";
    $closed_js = "!document.querySelector('.daedalus-ui-popover') && ($kebab_js).getAttribute('aria-expanded') === 'false'";
    $before = $on ? 'false' : 'true';
    $after = $on ? 'true' : 'false';
    $word = $on ? 'on' : 'off';

    $session->executeScript("($kebab_js).click();");
    $this->assertJsCondition(
      "(() => { const item = $item_js; return !!item && item.getAttribute('role') === 'menuitemcheckbox' && item.getAttribute('aria-checked') === '$before'; })()",
      20000,
      "The Show menu lists the Markup view as a menuitemcheckbox with aria-checked $before before turning it $word.",
    );
    $session->executeScript("($item_js).click();");
    $this->assertJsCondition(
      $closed_js,
      20000,
      "Activating the Markup item closed the Show menu and its surface (turning the view $word).",
    );
    $session->executeScript("($kebab_js).click();");
    $this->assertJsCondition(
      "(() => { const item = $item_js; return !!item && item.getAttribute('aria-checked') === '$after'; })()",
      20000,
      "The reopened Show menu shows the Markup view with aria-checked $after: the toggle flipped it $word.",
    );
    $session->executeScript("($kebab_js).click();");
    $this->assertJsCondition(
      $closed_js,
      20000,
      'The kebab closed the Show menu again without a toggle.',
    );
  }

  /**
   * Dispatches a keyboard event on a target (document by default).
   */
  private function dispatchKeyEvent(string $type, string $key, array $init, string $target = 'document'): void {
    $init = json_encode(['key' => $key, 'bubbles' => TRUE, 'cancelable' => TRUE] + $init);
    $this->getSession()->executeScript(
      "$target.dispatchEvent(new KeyboardEvent('$type', $init));"
    );
  }

  /**
   * A JS expression for the blueprint mirror's flat style map.
   *
   * The page document is the lean tree with each node's styles on the node
   * (keyed by the local remainder of the absolute key), and the blueprint's
   * top-level `styles` holds only the keys no node carries (`entity:` and
   * `field:` keys). The flat map a style commit assertion reads is derived
   * from both, the way the client's styleMapOf (daedalus_stylish's
   * style-guide.js) and the server's NodeTreeBuilder::styleMapOf() derive
   * it: the node-less keys, then each container and block node's entries
   * under `{kind}:{uuid}{local}` in document order. The expression reads the
   * live mirror each time it is evaluated, so it belongs inside the condition
   * an assertJsCondition polls, never in a value captured once.
   */
  protected function styleMapJs(): string {
    return <<<'JS'
      ((blueprint) => {
        const carried = {};
        const collect = (nodes) => (Array.isArray(nodes) ? nodes : []).forEach((node) => {
          if (node && typeof node === 'object') {
            if ((node.kind === 'container' || node.kind === 'block') && node.uuid) {
              Object.entries(node.styles ?? {}).forEach(([local, entry]) => { carried[node.kind + ':' + node.uuid + local] = entry; });
            }
            collect(node.children);
          }
        });
        collect(blueprint?.layout);
        const map = {};
        Object.entries(blueprint?.styles ?? {}).forEach(([key, entry]) => { if (!Object.hasOwn(carried, key)) { map[key] = entry; } });
        return Object.assign(map, carried);
      })(Drupal.Daedalus.Blueprint.getBlueprint())
      JS;
  }

  /**
   * Starts a native drag from a source, over a point on a target.
   *
   * The sequence the drag controller consumes: dragstart on the source
   * (its own handler, if any, arms the source), then dragover on the
   * target at the target's horizontal centre and the caller's y. The
   * gesture stays open until finishDrag(). The source and the target are
   * JS expressions resolving to the elements; $y_js is a JS expression
   * over `rect`, the target's bounding client rect, and defaults to four
   * pixels above its bottom edge.
   *
   * What the default y means depends on the surface. On the canvas it is
   * the enclosing container's edge band, the root-flow gap after it. In
   * the page elements panel the drop unit is the gap LINE between rows
   * (daedalus_ui drag/tree-gaps.js): a container-like row (the page row, a
   * container row, a region row) has an 8px band at each edge and a
   * middle meaning `inside` (append), so its bottom band is the
   * container's START (before its first child, `inside` when it is
   * empty), while a block row splits at its midpoint, its lower half the
   * line below it (after the block, or after an ancestor it closes, the
   * depth picked by the cursor's x; the centre x picks the deepest). A
   * step that needs another band passes its own y, e.g. 'rect.top + 4'
   * for the line above a container row or 'rect.top + rect.height / 2'
   * for its append.
   *
   * @return bool
   *   Whether the dragstart was NOT cancelled (dispatchEvent's answer: a
   *   refused drag calls preventDefault, a source with no handler neither
   *   starts nor cancels).
   */
  protected function startDrag(string $source_js, string $target_js, string $y_js = 'rect.bottom - 4'): bool {
    return (bool) $this->getSession()->evaluateScript(<<<JS
      (() => {
        const dt = new DataTransfer();
        window.__dragTransfer = dt;
        const source = $source_js;
        const target = $target_js;
        const started = source.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: dt }));
        target.scrollIntoView({ block: 'center' });
        const rect = target.getBoundingClientRect();
        target.dispatchEvent(new DragEvent('dragover', {
          bubbles: true,
          cancelable: true,
          dataTransfer: dt,
          clientX: rect.left + rect.width / 2,
          clientY: $y_js,
        }));
        return started;
      })()
      JS);
  }

  /**
   * Drops an open drag on a point of the target, then ends it.
   *
   * $y_js is the same expression over `rect` startDrag() takes, with the
   * same default; a step passes the y it started with so the drop lands
   * on the drag's own target.
   */
  protected function finishDrag(string $source_js, string $target_js, string $y_js = 'rect.bottom - 4'): void {
    $this->getSession()->executeScript(<<<JS
      (() => {
        const dt = window.__dragTransfer;
        const source = $source_js;
        const target = $target_js;
        const rect = target.getBoundingClientRect();
        const x = rect.left + rect.width / 2;
        const y = $y_js;
        target.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt, clientX: x, clientY: y }));
        target.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt, clientX: x, clientY: y }));
        source.dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true, dataTransfer: dt }));
      })();
      JS);
  }

}
