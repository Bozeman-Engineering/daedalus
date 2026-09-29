<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\FunctionalJavascript;

use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives stencil placement through the Add panel's three doors on one page.
 *
 * A stencil is a page BAND when its root's captured wearings include a
 * variant the container declaration marks root-only (the Site width
 * variant, container_site); a band places at top level only. That fact
 * is derived once, at the materials roster's applicability predicate
 * (MaterialsRoster::applicable() and its client twin), and the Add panel
 * declares it as the acceptsTarget of its stencil drag sources. Three
 * doors then answer the one predicate: the pointer drag (the drag
 * controller consults acceptsTarget per resolved gap), the keyboard lift
 * (it prunes the enumerated gaps) and click-to-place (the Insert verb's
 * pending anchor, whose target is built from the anchor and asked of the
 * same spec). Placement curates OFFERS, never legality: a refused gap is
 * absent (no caret, no placement), never red; the spine's own refusal
 * (VARIANT_ROOT_ONLY at the apply seam) backstops raw ops.
 *
 * The seams only a browser sees: the drag controller's acceptsTarget
 * consult over the caret's real DOM (the caret stays hidden over a gap
 * the source refuses and appears over one it accepts) and the pending
 * anchor path in add-panel.js reaching the same spec before building
 * its ops. Node tests lock the predicate itself (daedalus_ui
 * tests/js/materials-grammar.test.mjs, the stencil case) and the
 * location's depth read (tests/js/gaps.test.mjs, isRootFlowLocation);
 * kernel tests lock the PHP side and the PHP/JS equivalence.
 *
 * The scenario, in dependency order:
 * - FIXTURE: two published content stencils, both section-rooted on the
 *   recipe's container layout: "Band" whose root wears container_site (a
 *   band by derivation), "Card" whose root wears nothing. A page with two
 *   top-level containers A and B, and a container C nested inside A.
 * - DRAG, REFUSED: dragging the Band item over the nested container's
 *   interior offers no caret (the only gap there is inside C, nested).
 * - DRAG, OFFERED: the same drag over A's bottom edge band offers the
 *   root-flow gap after A; the drop places one band there, top level,
 *   between A and B, wearing Site width.
 * - DRAG, CONTRAST: the Card item over the nested interior IS offered
 *   (the empty-container affordance), proving the refusal is the band
 *   fact and not a blanket one.
 * - CLICK, REFUSED: with C selected and the Insert verb armed, clicking
 *   the Band item places nothing (the anchor's target, after C inside A,
 *   is nested) and the session stays armed.
 * - CLICK, CONTRAST: clicking the Card item at the same anchor places
 *   the card after C, nested, in exactly one POST (so the refused click
 *   was no POST at all).
 * - CLICK, OFFERED: with B selected and Insert armed, clicking the Band
 *   item places a band after B, top level.
 *
 * One fixture, one scenario: DaedalusFixtureTrait (the daedalus recipe's
 * site, as daedalus_editor); a full site install from the recipe is the
 * dominant cost of a run, so all behavior lives in the single test method.
 *
 * @group daedalus_layout
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class StencilPlacementBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait;

  /**
   * The selector of every container on the page, in document order.
   */
  private const CONTAINERS_JS = "Array.from(document.querySelectorAll(\"[data-main-entity] [data-selectable^='container:']\"))";

  /**
   * Starts a palette item drag and moves it over a target.
   *
   * The native sequence the drag controller consumes: dragstart on the
   * item (arms the source through the item's own handler, the real
   * palette wiring), then dragover on the target at a position of its
   * rect: 'center', or 'bottom' (four pixels above the bottom edge, in
   * the enclosing container's edge band). The gesture stays open until
   * dropItemDrag() or endItemDrag().
   */
  private function startItemDrag(string $stencil_id, string $target_js, string $position): void {
    $y = $this->positionY($position);
    $this->getSession()->executeScript(<<<JS
      (() => {
        const dt = new DataTransfer();
        document.querySelector('[data-stencil-id="$stencil_id"]')
          .dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: dt }));
        const target = $target_js;
        target.scrollIntoView({ block: 'center' });
        const rect = target.getBoundingClientRect();
        target.dispatchEvent(new DragEvent('dragover', {
          bubbles: true,
          cancelable: true,
          dataTransfer: dt,
          clientX: rect.left + rect.width / 2,
          clientY: $y,
        }));
      })();
      JS);
  }

  /**
   * Drops an open item drag on a target, then closes it with dragend.
   */
  private function dropItemDrag(string $stencil_id, string $target_js, string $position): void {
    $y = $this->positionY($position);
    $this->getSession()->executeScript(<<<JS
      (() => {
        const dt = new DataTransfer();
        const target = $target_js;
        const rect = target.getBoundingClientRect();
        const x = rect.left + rect.width / 2;
        const y = $y;
        target.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt, clientX: x, clientY: y }));
        target.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt, clientX: x, clientY: y }));
        document.querySelector('[data-stencil-id="$stencil_id"]')
          .dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true, dataTransfer: dt }));
      })();
      JS);
  }

  /**
   * Closes an open item drag without dropping, as a release outside would.
   */
  private function endItemDrag(string $stencil_id): void {
    $this->getSession()->executeScript(<<<JS
      document.querySelector('[data-stencil-id="$stencil_id"]')
        .dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true, dataTransfer: new DataTransfer() }));
      JS);
  }

  /**
   * The JS expression for a position's y within a target's rect.
   */
  private function positionY(string $position): string {
    return $position === 'bottom' ? 'rect.bottom - 4' : 'rect.top + rect.height / 2';
  }

  /**
   * Whether any caret face is showing (line, empty affordance or container).
   */
  private function caretShowing(): bool {
    return (bool) $this->getSession()->evaluateScript(<<<'JS'
      (() => {
        const caret = document.querySelector('.daedalus-ui-caret');
        if (!caret) {
          return false;
        }
        return ['.daedalus-ui-caret__line', '.daedalus-ui-caret__empty', '.daedalus-ui-caret__container']
          .some((selector) => Array.from(caret.querySelectorAll(selector)).some((face) => !face.hidden));
      })()
      JS);
  }

  /**
   * The container keys on the page, in document order.
   *
   * @return string[]
   *   The selectable keys.
   */
  private function containerKeys(): array {
    return $this->getSession()->evaluateScript(self::CONTAINERS_JS . ".map((element) => element.getAttribute('data-selectable'))");
  }

  /**
   * The key of the container a container sits inside, or NULL at top level.
   */
  private function parentContainerKey(string $key): ?string {
    $parent = $this->getSession()->evaluateScript(<<<JS
      (() => {
        const element = document.querySelector("[data-main-entity] [data-selectable='$key']");
        const parent = element && element.parentElement.closest("[data-selectable^='container:']");
        return parent ? parent.getAttribute('data-selectable') : null;
      })()
      JS);
    return $parent === NULL ? NULL : (string) $parent;
  }

  /**
   * Places a stencil item through click-to-place at the pending anchor.
   */
  private function clickItem(string $stencil_id): void {
    $this->getSession()->executeScript("document.querySelector('[data-stencil-id=\"$stencil_id\"]').click();");
  }

  /**
   * Selects an element and arms the Insert verb on it.
   */
  private function armInsert(string $key): void {
    $this->getSession()->executeScript("Drupal.Daedalus.Selection.select('$key');");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$key'",
      10000,
      "The selection landed on $key.",
    );
    $this->pressHotkey('i');
    $this->assertJsCondition(
      "document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      "The Insert verb armed click-to-place on $key.",
    );
  }

  /**
   * The Add panel's three placement doors answer one stencil predicate.
   */
  public function testStencilPlacementDoors(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // ——— FIXTURE. ———
    //
    // Two content stencils on the recipe's container layout. The Band's
    // root wears container_site, the recipe's Site width variant, whose
    // axis the container declaration marks root-only: that wearing alone
    // makes it a band. The Card wears nothing. Both are global (no design
    // system), so the page's roster carries them.
    $band = Stencil::create([
      'label' => 'Band',
      'machine_name' => 'band',
      'description' => 'A page band wearing Site width.',
      'status' => 1,
    ]);
    $band->setElement([
      'kind' => 'container',
      'layout_id' => 'daedalus_stylish_container',
      'styles' => ['' => ['variants' => ['container_site']]],
      'children' => [],
    ]);
    $band->save();
    $card = Stencil::create([
      'label' => 'Card',
      'machine_name' => 'card',
      'description' => 'A bare container.',
      'status' => 1,
    ]);
    $card->setElement([
      'kind' => 'container',
      'layout_id' => 'daedalus_stylish_container',
      'children' => [],
    ]);
    $card->save();

    $node = Node::create([
      'type' => 'page',
      'title' => 'Stencil placement page',
    ]);
    $node->save();

    $this->drupalGet($node->toUrl());
    $session->resizeWindow(1600, 1200);
    $this->enterEditModeViaCookies($node->toUrl());
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Materials.isBooted() && !!Drupal.Daedalus.Materials.get('stencil', 'band') && !!Drupal.Daedalus.Materials.get('stencil', 'card')",
      20000,
      'The materials store delivered both content stencils.',
    );
    $this->assertTrue(
      $session->evaluateScript(<<<'JS'
        (() => {
          const Materials = Drupal.Daedalus.Materials;
          const site = Materials.get('variant', 'container_site');
          return !!site && site.applies && site.applies.depth === 'root'
            && Materials.getRoster().isBand(Materials.get('stencil', 'band'))
            && !Materials.getRoster().isBand(Materials.get('stencil', 'card'));
        })()
        JS),
      'The delivered roster derives the band fact: container_site is root-only, the Band wears it, the Card does not.',
    );

    // The POST counter: "one gesture, one blueprint POST" and "a refused
    // gesture, no POST" are wiring facts, not inferences from the page.
    $session->executeScript(<<<'JS'
      window.__blueprintPosts = 0;
      const originalFetch = window.fetch;
      window.fetch = function (url, options) {
        const target = url && url.url ? url.url : String(url);
        const method = (options && options.method) || (url && url.method) || 'GET';
        if (target.includes('daedalus-blueprint') && method === 'POST') {
          window.__blueprintPosts++;
        }
        return originalFetch.apply(this, arguments);
      };
      JS);

    // The page: two top-level containers A and B, a container C nested in
    // A, through the same mutation batch a palette drop enqueues.
    $uuid_service = \Drupal::service('uuid');
    $a = $uuid_service->generate();
    $b = $uuid_service->generate();
    $c = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        const root = Blueprint.getRoot().uuid;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'add_section', uuid: '$a', location: { anchor: root, placement: 'inside' } },
            { op: 'add_section', uuid: '$b', location: { anchor: '$a', placement: 'after' } },
            { op: 'add_section', uuid: '$c', location: { anchor: '$a', placement: 'inside' } },
          ],
        }));
      })();
      JS);
    $this->assertNotNull(
      $assert_session->waitForElement('css', "[data-main-entity] [data-selectable='container:$a'] [data-selectable='container:$c']", 20000),
      'The page renders container C nested inside container A.',
    );
    $this->assertSame(["container:$a", "container:$c", "container:$b"], $this->containerKeys(), 'The page holds A (with C inside) then B.');
    $this->assertJsCondition('window.__blueprintPosts === 1', 10000, 'The page structure was one blueprint POST.');
    $this->assertJsCondition(
      "Drupal.Daedalus.Drag.isRootFlowLocation({ anchor: '$a', placement: 'after' }) && !Drupal.Daedalus.Drag.isRootFlowLocation({ anchor: '$c', placement: 'inside' })",
      10000,
      'The mirror answers the depth read: after A is the root flow, inside C is nested.',
    );

    // The Add panel, with the stencil items armed as drag sources.
    $assert_session->waitForElement('css', '#place_block');
    $session->executeScript(<<<'JS'
      (() => {
        const panel = document.getElementById('place_block');
        if (panel && panel.classList.contains('daedalus-ui-hidden')) {
          Drupal.Daedalus.SidebarManager.openSidebar('place_block');
        }
      })();
      JS);
    $this->assertJsCondition(
      "(() => { const item = document.querySelector('[data-stencil-id=\"band\"]');"
      . " return !!item && item.classList.contains('draggable-section') && typeof item.ondragstart === 'function'; })()",
      20000,
      'The Add panel rendered the Band item as a section item and the drag layer armed it.',
    );
    $this->assertJsCondition(
      "typeof document.querySelector('[data-stencil-id=\"card\"]').ondragstart === 'function'",
      20000,
      'The Card item is armed too.',
    );

    // ——— DRAG, REFUSED: the Band over the nested container's interior. ———
    //
    // The only gap the cursor resolves there is inside C (an empty
    // container is one 'inside' gap): nested, so the Band's acceptsTarget
    // refuses it and the controller offers nothing. The caret stays
    // hidden: absent, not red.
    $nested_js = "document.querySelector(\"[data-main-entity] [data-selectable='container:$c']\")";
    $this->startItemDrag('band', $nested_js, 'center');
    $this->assertJsCondition('!!document.querySelector(".daedalus-ui-caret")', 10000, 'The Band drag armed the drag layer (the caret component mounted).');
    $this->assertFalse($this->caretShowing(), 'Over the nested container, no caret is offered to the Band: the nested gap is refused by the source, so it is absent.');
    $this->endItemDrag('band');

    // ——— DRAG, OFFERED: the Band over A's bottom edge band. ———
    //
    // The edge-zone rule resolves the sibling gap after A in the root
    // flow: top level, so the Band's acceptsTarget accepts it and the
    // caret line appears.
    $band_a_js = "document.querySelector(\"[data-main-entity] [data-selectable='container:$a']\")";
    $this->startItemDrag('band', $band_a_js, 'bottom');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-caret__line') && !document.querySelector('.daedalus-ui-caret__line').hidden",
      10000,
      'Over the root-flow gap after A, the caret line offers the Band its place between the bands.',
    );

    // The drop: one POST, one band placed at top level between A and B,
    // wearing exactly what it captured (Site width).
    $before = $this->containerKeys();
    $this->dropItemDrag('band', $band_a_js, 'bottom');
    $this->assertJsCondition('window.__blueprintPosts === 2', 20000, 'The Band drop was exactly one blueprint POST.');
    $this->assertJsCondition(self::CONTAINERS_JS . '.length === 4', 20000, 'The drop rendered a fourth container.');
    $after = $this->containerKeys();
    $placed = array_values(array_diff($after, $before));
    $this->assertCount(1, $placed, 'Exactly one container was placed by the drop.');
    $band_key = $placed[0];
    $this->assertSame(["container:$a", "container:$c", $band_key, "container:$b"], $after, 'The band landed between A and B.');
    $this->assertNull($this->parentContainerKey($band_key), 'The band sits at top level (inside no container).');
    // The inspector's wear bar reads the placed band's wearings: open the
    // inspector sidebar (the recipe's cookie entry renders it closed) and
    // select the band.
    $assert_session->waitForElement('css', '#daedalus_stylish_inspector');
    $session->executeScript(<<<'JS'
      (() => {
        const panel = document.getElementById('daedalus_stylish_inspector');
        if (panel && panel.classList.contains('daedalus-ui-hidden')) {
          Drupal.Daedalus.SidebarManager.openSidebar('daedalus_stylish_inspector');
        }
      })();
      JS);
    $session->executeScript("Drupal.Daedalus.Selection.select('$band_key');");
    $width_row_xpath = "//div[@id='daedalus_stylish_inspector']"
      . "//div[contains(@class,'daedalus-stylish-wear-bar__row')]"
      . "[.//span[contains(@class,'daedalus-stylish-wear-bar__label')][normalize-space(.)='Width']]";
    $width_label = $assert_session->waitForElement('xpath', "{$width_row_xpath}//span[contains(@class,'daedalus-stylish-wear-bar__variant-label')]", 20000);
    $this->assertNotNull($width_label, 'The wear bar renders a worn Width row for the placed band.');
    $this->assertSame('Site width', $width_label->getText(), 'The placed band wears Site width, the wearing it captured (a placement is a total statement).');
    $session->executeScript('Drupal.Daedalus.Selection.clear();');
    // Back to the Add panel (a same-slot inspector may have evicted it).
    $session->executeScript("Drupal.Daedalus.SidebarManager.openSidebar('place_block');");
    $this->assertJsCondition(
      "!document.getElementById('place_block').classList.contains('daedalus-ui-hidden')",
      10000,
      'The Add panel is open again for the remaining doors.',
    );

    // ——— DRAG, CONTRAST: the Card over the nested interior IS offered. ———
    $this->startItemDrag('card', $nested_js, 'center');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-caret__empty') && !document.querySelector('.daedalus-ui-caret__empty').hidden",
      10000,
      'Over the nested container, the Card (no root-only wearing) is offered the empty-container affordance: the refusal is the band fact, not a blanket one.',
    );
    $this->endItemDrag('card');
    // The caret's existence is gated by the drag store (active or
    // committing); a dragend without a drop deactivates the drag and the
    // component unmounts, so "withdrew" means gone, not merely hidden.
    $this->assertJsCondition(
      "(() => { const face = document.querySelector('.daedalus-ui-caret__empty'); return !face || face.hidden; })()",
      10000,
      'The dragend closed the Card gesture and the caret withdrew.',
    );

    // ——— CLICK, REFUSED: Insert armed on C, the Band item clicked. ———
    //
    // A section item places AFTER a container anchor: after C, inside A,
    // nested. Click-to-place builds that target from the anchor and asks
    // the Band's source spec, which refuses it; nothing is placed and the
    // session stays armed.
    $this->armInsert("container:$c");
    $this->clickItem('band');
    $this->assertJsCondition(
      "document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      'The refused click left the insert session armed on C.',
    );

    // ——— CLICK, CONTRAST: the Card at the same anchor places, nested. ———
    //
    // One POST for both clicks together: the Card's. The Band click was no
    // POST at all.
    $before = $this->containerKeys();
    $this->clickItem('card');
    $this->assertJsCondition('window.__blueprintPosts === 3', 20000, 'The Card click was one blueprint POST, and the refused Band click before it was none.');
    $this->assertJsCondition(self::CONTAINERS_JS . '.length === 5', 20000, 'The Card click rendered a fifth container.');
    $after = $this->containerKeys();
    $placed = array_values(array_diff($after, $before));
    $this->assertCount(1, $placed, 'Exactly one container was placed by the two clicks: the Card.');
    $card_key = $placed[0];
    $this->assertSame("container:$a", $this->parentContainerKey($card_key), 'The card landed nested inside A.');
    $this->assertSame(
      array_search("container:$c", $after, TRUE) + 1,
      array_search($card_key, $after, TRUE),
      'The card sits right after C, the anchor.',
    );
    $this->assertJsCondition(
      "document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      'The placement advanced the armed anchor (the session stays live for the next click).',
    );

    // ——— CLICK, OFFERED: Insert armed on B, the Band item clicked. ———
    //
    // After B is the root flow: the same spec accepts it and the Band
    // places at top level.
    $this->armInsert("container:$b");
    $before = $this->containerKeys();
    $this->clickItem('band');
    $this->assertJsCondition('window.__blueprintPosts === 4', 20000, 'The Band click at a top-level anchor was one blueprint POST.');
    $this->assertJsCondition(self::CONTAINERS_JS . '.length === 6', 20000, 'The Band click rendered a sixth container.');
    $after = $this->containerKeys();
    $placed = array_values(array_diff($after, $before));
    $this->assertCount(1, $placed, 'Exactly one container was placed by the Band click.');
    $this->assertNull($this->parentContainerKey($placed[0]), 'The clicked band sits at top level.');
    $this->assertSame($placed[0], end($after), 'The clicked band landed after B, the last band on the page.');
  }

}
