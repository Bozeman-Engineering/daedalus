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
   * Dispatches a double-click on an element, the three events a pointer sends.
   *
   * A browser's double-click is click, click, dblclick on one target, and
   * Edit Mode's one pointer grammar reads all three (selector-events.js):
   * the first click commits the selection the depth policy resolves, the
   * second keeps it by the deepen rule (pointing at the selection means the
   * selection), and the dblclick drills one level into the selection or,
   * at a LEAF (the selection is the innermost presented key under the
   * pointer), opens its editor through the one editor door. With
   * ['metaKey' => TRUE] the gesture is deep (Cmd, the one depth signal):
   * the clicks resolve the deepest presented element under the pointer
   * directly, so a nested leaf edits in one gesture. Dispatched, not
   * driven, for targets the fixed chrome overlaps in the headless layout;
   * where a real pointer matters (a link that must not navigate) use
   * Mink's NodeElement::doubleClick() instead, which sends the same three
   * events.
   *
   * @param string $selector
   *   A CSS selector for the target (document.querySelector).
   * @param array $init
   *   Extra MouseEvent init entries, e.g. ['metaKey' => TRUE].
   */
  protected function dispatchDoubleClick(string $selector, array $init = []): void {
    $selector = json_encode($selector);
    $init = json_encode(['bubbles' => TRUE, 'cancelable' => TRUE] + $init);
    $this->getSession()->executeScript(<<<JS
      (() => {
        const target = document.querySelector($selector);
        if (!target) {
          throw new Error('dispatchDoubleClick: no element matches ' + $selector);
        }
        target.scrollIntoView({ block: 'center' });
        ['click', 'click', 'dblclick'].forEach((type) => {
          target.dispatchEvent(new MouseEvent(type, $init));
        });
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
   * Dispatches a keyboard event on a target (document by default).
   */
  private function dispatchKeyEvent(string $type, string $key, array $init, string $target = 'document'): void {
    $init = json_encode(['key' => $key, 'bubbles' => TRUE, 'cancelable' => TRUE] + $init);
    $this->getSession()->executeScript(
      "$target.dispatchEvent(new KeyboardEvent('$type', $init));"
    );
  }

}
