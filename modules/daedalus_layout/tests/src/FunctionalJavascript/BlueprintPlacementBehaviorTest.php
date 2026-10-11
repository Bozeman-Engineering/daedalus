<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\FunctionalJavascript;

use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives a blueprint from capture to placement to its own canvas.
 *
 * A saved blueprint is a page of its own: captured from a page container
 * ("Save as blueprint…"), offered in the Add panel the moment its record
 * lands, placed as a stamped copy through place_blueprint, and edited on
 * its canonical route like any page. The three FJS steps of the
 * blueprint-library landing ride one fixture:
 *
 * - F3, capture then place: "Save as blueprint…" on a page container
 *   opens the dialog, the save_material op mints the blueprint, and the
 *   mutation response's materials delta lands the new item in the open
 *   Add panel WITHOUT a reload (the seam: the delta reaching the store and
 *   the panel). The placement doors then place it through place_blueprint
 *   with provenance on the placed root (the mirror's `blueprint` key).
 * - The placement doors (the former placement scenario): a blueprint is a
 *   page BAND when its root's captured wearings include a variant the
 *   container declaration marks root-only (the Site width variant,
 *   container_site); a band places at top level only. That fact is
 *   derived once, at the materials roster's applicability predicate
 *   (MaterialsRoster::applicable() and its client twin), and the Add
 *   panel declares it as the acceptsTarget of its blueprint drag source.
 *   Three doors then answer the one predicate: the pointer drag (the drag
 *   controller consults acceptsTarget per resolved gap), the keyboard
 *   lift (it prunes the enumerated gaps) and click-to-place (the Insert
 *   verb's pending anchor, whose target is built from the anchor and
 *   asked of the same spec). Placement curates OFFERS, never legality: a
 *   refused gap is absent (no caret, no placement), never red; the
 *   spine's own refusal (VARIANT_ROOT_ONLY at the apply seam) backstops
 *   raw ops.
 * - F2, the subject on the canvas: the blueprint canvas's Add panel offers
 *   the subject bundle's field blocks (and none of the blueprint type's
 *   own) and a placed field block renders the subject sample's content
 *   (the seam: the `blueprint` storage's preview contexts reaching the
 *   placement restrictions filter and core's FieldBlock render; Kernel
 *   sees the storage's answer, only the browser sees the roster and the
 *   render).
 * - F1, the blueprint canvas round trip: on /blueprint/{id} in Edit Mode,
 *   a field block and a text block placed into the root container through
 *   click-to-place, Save, reload: both blocks are there and the entity has
 *   a new revision (the seam: the blueprint route rendering
 *   layout_builder_plus over the `blueprint` storage, the tempstore key
 *   and the commit door agreeing, four layers that only agree in a
 *   browser).
 * - Open blueprint lands armed (the blueprint-walk-finds landing): the
 *   placement's "Open blueprint" verb is a plain navigation to the
 *   blueprint's canonical URL, and the canvas route's first visit enters
 *   Edit Mode itself, planting the path-scoped cookie (the seam: a queued
 *   path-scoped cookie honoured by the next navigation, which only a
 *   browser performs).
 * - The empty canvas (the same landing): a blueprint with no document
 *   opens on an empty canvas wearing the blank-page affordance worded for
 *   a blueprint, and opening the Add panel from that affordance arms
 *   click-to-place against the root, so one click on Container places
 *   the first root (the seam: the arming is event plumbing between the
 *   blank-page button, the sidebar manager's open and the panel's
 *   observer of it; Kernel pins the hint's wording, LayoutRootTest).
 *
 * The seams only a browser sees: the drag controller's acceptsTarget
 * consult over the caret's real DOM (the caret stays hidden over a gap
 * the source refuses and appears over one it accepts), the pending anchor
 * path in js/src/insert-session.js reaching the same spec before building its ops,
 * the dialog's inert markup driven by js/verbs/blueprint.js into the op
 * spine and the panel repainting from the store. Node tests lock the
 * predicate itself (daedalus_ui tests/js/materials-grammar.test.mjs, the
 * blueprint case) and the location's depth read (tests/js/gaps.test.mjs,
 * isRootFlowLocation); kernel tests lock the PHP side, the PHP/JS
 * equivalence, the dialogs' markup and the routes' payloads.
 *
 * The scenario, in dependency order:
 * - FIXTURE: a page with two top-level containers A and B, and a
 *   container C nested inside A; B wears container_site (the recipe's
 *   Site width variant, root-only by the container declaration).
 * - CAPTURE (F3): "Save as blueprint…" on C mints "Card" (wearing
 *   nothing); the item appears in the open Add panel without a reload.
 * - THE CREATOR'S FAVORITE (the blueprint-verbs-and-dialogs landing, on
 *   the same capture): the Card's row sits under Favorites at once with
 *   its star on, the settings route took exactly one POST whose whole
 *   map carries `blueprint:card` TRUE, and the record's own default stays
 *   FALSE (the mark is this editor's, not the site's; a second editor's
 *   panel never sees it, which the Kernel AddPanelFacesTest::testMarks
 *   pins on the server). The seam: the save_material response's delta
 *   reaching the mounted panel's subscription, which tells a delta from
 *   a boot through Materials.onChange and writes the mark through the
 *   star's own path; the rule itself is node-tested (faces.test.mjs).
 * - CANVAS (F2, F1): the Card's canonical route in Edit Mode, the Add
 *   panel offering the page bundle's title field block and no blueprint
 *   field block, the title block placed into the root container and
 *   rendering sample content, a text block placed after it, Save, reload:
 *   both blocks stand and the blueprint has a second revision.
 * - BACK TO THE PAGE: the page boots again with the Card delivered from
 *   discovery's content lane (the item is in the Add panel with no
 *   capture this time).
 * - CAPTURE (F3), THE BAND: "Save as blueprint…" on B mints "Band" (a band
 *   by derivation: its root wears Site width, which the capture carries
 *   into the blueprint's own style store, so the blueprint bundle must
 *   have styling enabled on the recipe's site).
 * - DRAG, REFUSED: dragging the Band item over the nested container's
 *   interior offers no caret (the only gap there is inside C, nested).
 * - DRAG, OFFERED: the same drag over A's bottom edge band offers the
 *   root-flow gap after A; the drop places one band there, top level,
 *   between A and B, wearing Site width, stamped `blueprint: band`.
 * - THE CHIP (the blueprint-verbs-and-dialogs landing): with the placed
 *   band selected, its chip shows ONE split control, "Update blueprint
 *   'Band'" as the main half and a caret whose menu lists "Open blueprint
 *   'Band'" and "Save as new blueprint…" flat; picking the last opens the
 *   save dialog (cancelled, no POST); an unstamped container's chip keeps
 *   the plain "Save as blueprint…" button (the seam: the registry's group
 *   key through the chip's model into a control the menu primitive lists
 *   from; the fold and the model's entries are node-tested, the DOM only
 *   here).
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
 * - CLICK, THEN ENTER: a block placed inside the new band, its editor
 *   opened by Enter, closed by Escape (the insert session's key routing).
 * - PAGE CAPTURE: the top bar's Save caret opens the document menu, "Save
 *   page as blueprint…" opens the dialog worded for a page, and the commit
 *   mints "Landing", a page-scope blueprint of the whole document, while
 *   the open Add panel gains no item (the seam: a top bar menu item to a
 *   root verb to the dialog to save_material to the delta, which lists a
 *   page-scope blueprint as nothing for this canvas).
 * - OPEN BLUEPRINT: the Band's "Open blueprint" verb lands on its canvas
 *   in Edit Mode with the test's own site-wide mode cookie swept first.
 * - FROM THE ADD FORM TO THE CANVAS (the saved-blueprints landing, step
 *   1): with "Offer starting designs" on for Page content, the add form
 *   opens on the start chooser; the Landing card is picked, the title
 *   filled, the form saved, and the browser lands on the new page in
 *   Edit Mode with the blueprint's containers on the canvas, selectable,
 *   none of them a placement (no "Update blueprint" on a root). The seam:
 *   the entity builder's one save, the Edit Mode cookie queued at insert
 *   and the canvas mounting a document whose inline blocks were unsaved a
 *   request earlier agree only in a browser; the Functional class
 *   (StartChooserTest) sees the save, ModeCookieTest sees the cookie,
 *   neither sees the canvas mount.
 * - A CARD'S LIVE PREVIEW (the same landing, step 2, taken on the chooser
 *   before the Landing card is picked): the card's face is a lazily
 *   loaded frame on the preview route, and once scrolled into view it
 *   holds the preview document with the blueprint's text, in preview mode
 *   with no editor, laid out wide and scaled onto the face. The seam: a
 *   lazy frame, its document arriving and its scaling are browser facts;
 *   the Functional class sees the route answer and nothing of the frame.
 * - THE EMPTY CANVAS: a rootless block blueprint "Blank" saved server-side
 *   opens empty with the hint "Empty blueprint. …"; the Add panel closed,
 *   a click on the affordance opens it armed against the root, and one
 *   click on Container places the first container in one POST, the
 *   affordance gone and the session advanced onto the new container.
 * - THE SETS (saved-blueprints landing 4, steps 2 to 5, on the blank
 *   blueprint's canvas): a set folds and stays folded across a reload
 *   (the fold store is read at mount, before the first paint); a star
 *   under All puts the row under Favorites at once and the reload still
 *   finds it there (the click, the generic settings route, the user's
 *   field, the next page's delivery and the mount agree only in a
 *   browser); one block in two sets places from its row under Favorites
 *   by click and from its row under All by the keyboard lift (a row keyed
 *   by set and face feeding one drag source: the same face twice in the
 *   DOM); search from two characters answers one flat list, Down enters
 *   it and Enter lifts the row (the capture-phase keydown and the roving
 *   stop across a re-render).
 * - THE PEEK (the same landing, step 6, back on the page): resting the
 *   pointer on the Card's row posts the row's own drop batch to the peek
 *   door and a frame beside the panel holds the Card as it is, its saved
 *   text and its title field block rendering THIS page's title, in the
 *   skin with none of the page around it, inert; leaving the row removes
 *   the frame, and the canvas and the history are as they were (the peek
 *   persisted nothing). Resting on the title field block's row peeks the
 *   same way (every row peeks through the one mechanism) and Escape
 *   removes it. A second rest on the Card's row frames its held document
 *   at once and asks the door nothing (the answer is kept per row for the
 *   page load); one mutation through the op queue drops every held
 *   answer, so the rest after it asks the door again. The seam: the
 *   dwell, the request, the framed document and its scaling, and the
 *   held answer living in the panel's state across the mirror's change,
 *   are browser facts; the Functional class (PeekTest) sees the door's
 *   answer and nothing of the frame, and the node spec (peek-cache) sees
 *   the model and nothing of the panel.
 * - THE MOVE, UNDONE (the layout FJS debt, still on the page): a text
 *   block placed inside C through the op queue, selected, then hovered on
 *   the canvas, which arms the main entity wrapper as the ONE delegated
 *   drag handle; a dragstart on the block's root bubbles to the wrapper
 *   and is reinterpreted as the block's move (not cancelled: arrange is
 *   open), dragover B's interior offers the empty-container face (B's one
 *   inside gap), and the drop is one move_element POST whose response
 *   swaps BOTH ends: the block inside B exactly once on the whole canvas,
 *   C empty and stamped again, B unstamped. Ctrl+Z is one restore POST on
 *   the same queue: the block back inside C and nowhere else (no stale
 *   copy left in B), B stamped; Ctrl+Shift+Z moves it into B again. The
 *   seam: the canvas drag delegate (daedalus_ui drag/selector-drag.js,
 *   hover arming through the store's hoverKey and the wrapper's
 *   dragstart) to the move_element the controller builds, then the move's
 *   recorded targets (both containers) through
 *   HistoryApiController::restore(), TargetKeyMapper and the op queue's
 *   swapMarkup, two subtrees swapped in one response. The kernel tier
 *   pins the two targets (NestedStorageMoveBlockTest); a mapping that
 *   named the destination only through the block re-rendered the source
 *   alone and left the block's stale copy in the destination until a
 *   reload, a page the panel never showed. The drop lands IN B, not in
 *   the root flow: the root flow holds only sections, so a block dropped
 *   there wraps in a new section (gaps.js legalize, an [add_section,
 *   move_element] batch), another step's seam.
 *
 * One fixture, one scenario: DaedalusFixtureTrait (the daedalus recipe's
 * site, as daedalus_editor); a full site install from the recipe is the
 * dominant cost of a run, so all behavior lives in the single test method.
 * The editor persona relies on the recipe's grants for the blueprint doors
 * (the `section` type's create, edit and delete permissions for the
 * capture op and the details, and `configure editable section blueprint
 * layout overrides` for the canvas); a missing grant fails the capture or
 * the canvas step loudly, by design.
 *
 * @group daedalus_layout
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class BlueprintPlacementBehaviorTest extends WebDriverTestBase {

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
  private function startItemDrag(string $blueprint_id, string $target_js, string $position): void {
    $y = $this->positionY($position);
    $this->getSession()->executeScript(<<<JS
      (() => {
        const dt = new DataTransfer();
        document.querySelector('#place_block [data-daedalus-layout-add-panel-in="kind:blueprint"][data-daedalus-layout-add-panel-row="blueprint:$blueprint_id"]')
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
  private function dropItemDrag(string $blueprint_id, string $target_js, string $position): void {
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
        document.querySelector('#place_block [data-daedalus-layout-add-panel-in="kind:blueprint"][data-daedalus-layout-add-panel-row="blueprint:$blueprint_id"]')
          .dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true, dataTransfer: dt }));
      })();
      JS);
  }

  /**
   * Closes an open item drag without dropping, as a release outside would.
   */
  private function endItemDrag(string $blueprint_id): void {
    $this->getSession()->executeScript(<<<JS
      document.querySelector('#place_block [data-daedalus-layout-add-panel-in="kind:blueprint"][data-daedalus-layout-add-panel-row="blueprint:$blueprint_id"]')
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
   *
   * Top level is the page's: the site frame's section around the main
   * entity is a foreign document's container, not a parent of the page's
   * own, so the walk stops at the main entity.
   */
  private function parentContainerKey(string $key): ?string {
    $parent = $this->getSession()->evaluateScript(<<<JS
      (() => {
        const element = document.querySelector("[data-main-entity] [data-selectable='$key']");
        const parent = element && element.parentElement.closest("[data-selectable^='container:']");
        return parent && parent.closest('[data-main-entity]') ? parent.getAttribute('data-selectable') : null;
      })()
      JS);
    return $parent === NULL ? NULL : (string) $parent;
  }

  /**
   * The mirror node's placement provenance for a container key, or NULL.
   */
  private function provenanceOf(string $key): ?string {
    $uuid = explode(':', $key)[1];
    $stamp = $this->getSession()->evaluateScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        const node = Blueprint.componentForUuid(Blueprint.getBlueprint(), '$uuid');
        return node && typeof node.blueprint === 'string' ? node.blueprint : null;
      })()
      JS);
    return $stamp === NULL ? NULL : (string) $stamp;
  }

  /**
   * Places a blueprint item through click-to-place at the pending anchor.
   */
  private function clickItem(string $blueprint_id): void {
    $this->getSession()->executeScript("document.querySelector('#place_block [data-daedalus-layout-add-panel-in=\"kind:blueprint\"][data-daedalus-layout-add-panel-row=\"blueprint:$blueprint_id\"]').click();");
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
   * Opens the Add panel when the slot holds another panel or it is hidden.
   *
   * The blueprint rows the steps drive sit under All's Blueprints kind, a
   * set folded by default and absent while no blueprint is offered; the
   * opener opens All and, when the kind is there, the kind too, so a step
   * that follows finds the rows drawn (the fold store remembers both for
   * the rest of the session).
   */
  private function openAddPanel(): void {
    $this->getSession()->executeScript(<<<'JS'
      (() => {
        const panel = document.getElementById('place_block');
        if (panel && panel.classList.contains('daedalus-ui-hidden')) {
          Drupal.Daedalus.SidebarManager.openSidebar('place_block');
        }
      })();
      JS);
    $this->assertJsCondition(
      "!document.getElementById('place_block').classList.contains('daedalus-ui-hidden')",
      10000,
      'The Add panel is open.',
    );
    $this->revealBlueprints();
  }

  /**
   * Opens All and its Blueprints kind, when a blueprint is offered.
   */
  private function revealBlueprints(): void {
    $this->openAddPanelSet('all');
    $has_blueprints = (bool) $this->getSession()->evaluateScript(
      "document.querySelector('#place_block [data-daedalus-layout-add-panel-set=\"kind:blueprint\"]') !== null"
    );
    if ($has_blueprints) {
      $this->openAddPanelSet('kind:blueprint');
    }
  }

  /**
   * Rests the pointer on an Add panel row, the way a pointer can.
   *
   * The driver's move scrolls a row out of view into it and dispatches
   * the move in the same frame, so the row's mouseenter (which arms the
   * peek's dwell) can land before the list's scroll event (which ends a
   * pending peek), and no peek fires: an order no hand reaches, since a
   * wheel's last scroll event always precedes the pointer's next enter.
   * So: the pointer leaves the list (onto the search box), the row is
   * scrolled to where the driver would put it (its bottom at the list's
   * foot: the driver then finds it in view and scrolls nothing), the
   * scroll event passes (two frames), and only then does the pointer
   * arrive, with the enter the only event the row sees.
   *
   * @param string $row
   *   The row's CSS selector.
   */
  private function restOnRow(string $row): void {
    $session = $this->getSession();
    $session->getPage()->find('css', '#place_block [data-daedalus-layout-add-panel-search]')->mouseOver();
    $row_json = json_encode($row, JSON_THROW_ON_ERROR);
    $session->executeScript(
      "window.__rowSettled = false; document.querySelector($row_json).scrollIntoView({ block: 'end' });"
      . " requestAnimationFrame(() => requestAnimationFrame(() => { window.__rowSettled = true; }));"
    );
    $this->assertJsCondition('window.__rowSettled === true', 10000, 'The row scrolled into view and the scroll event passed.');
    $session->getPage()->find('css', $row)->mouseOver();
  }

  /**
   * Captures a container as a blueprint through the verb and its dialog.
   *
   * The real wiring end to end: the Save as blueprint… verb (applies on
   * the container key, under the editor's grant) opens the inert dialog,
   * the label and description go into its inputs, the commit button
   * submits the save_material op, and the response's delta lands the
   * record in the store, where the open Add panel paints the item from.
   *
   * @return string
   *   The machine name the save derived from the label.
   */
  private function captureAsBlueprint(string $key, string $label, string $description): string {
    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $session->executeScript("Drupal.Daedalus.Selection.select('$key');");
    $session->executeScript(<<<JS
      (() => {
        const verb = Drupal.Daedalus.Verbs.verbsFor('$key')
          .find((candidate) => candidate.id === 'daedalus_layout.save_material');
        if (!verb) {
          throw new Error('The Save as blueprint verb does not apply to $key (is the editor granted create section blueprint?).');
        }
        verb.run('$key');
      })();
      JS);
    $label_input = $assert_session->waitForElementVisible('css', '#layout-builder-modal #daedalus-layout-blueprint-label', 20000);
    $this->assertNotNull($label_input, 'The Save as blueprint dialog opened with its label input.');
    $session->executeScript(<<<JS
      (() => {
        document.getElementById('daedalus-layout-blueprint-label').value = {$this->jsString($label)};
        document.getElementById('daedalus-layout-blueprint-description').value = {$this->jsString($description)};
        document.querySelector('#layout-builder-modal [data-blueprint-save]').click();
      })();
      JS);
    $machine_name = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $label));
    $this->assertJsCondition(
      "!!Drupal.Daedalus.Materials.get('blueprint', '$machine_name')",
      20000,
      "The save_material response's delta landed the \"$label\" record in the store.",
    );
    $this->assertJsCondition(
      "!document.querySelector('#layout-builder-modal #daedalus-layout-blueprint-label')",
      10000,
      'The dialog closed on success.',
    );
    // The record landed in the store through the delta; the row is drawn
    // under All's Blueprints kind, which the first blueprint brings into
    // being folded, so it is opened here (a fold, not a reload).
    $this->revealBlueprints();
    $this->assertNotNull(
      $assert_session->waitForElement('css', "#place_block [data-daedalus-layout-add-panel-in=\"kind:blueprint\"][data-daedalus-layout-add-panel-row=\"blueprint:$machine_name\"][data-daedalus-layout-add-panel-kind=\"blueprint\"]", 20000),
      "The Add panel rendered the \"$label\" row from the store without a reload.",
    );
    return $machine_name;
  }

  /**
   * Installs the blueprint POST counter on the current page.
   *
   * "One gesture, one blueprint POST" and "a refused gesture, no POST" are
   * wiring facts, not inferences from the page.
   */
  private function installPostCounter(): void {
    $this->getSession()->executeScript(<<<'JS'
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
  }

  /**
   * Installs the favorites POST counter on the current page.
   *
   * The editor's marks reaching the server is a wiring fact: the settings
   * route's completed POSTs are counted (`window.__favoritePosts`) and
   * every posted body kept in order (`window.__favoriteBodies`), so a step
   * can read the whole map a gesture sent.
   */
  private function installFavoritePostCounter(): void {
    $this->getSession()->executeScript(<<<'JS'
      window.__favoritePosts = 0;
      window.__favoriteBodies = [];
      const favoritesFetch = window.fetch;
      window.fetch = function (url, options) {
        const target = url && url.url ? url.url : String(url);
        const method = (options && options.method) || (url && url.method) || 'GET';
        const promise = favoritesFetch.apply(this, arguments);
        if (target.includes('daedalus-ui/setting/daedalus_layout/favorites') && method === 'POST') {
          window.__favoriteBodies.push(options && options.body);
          promise.then((response) => { if (response.ok) { window.__favoritePosts++; } });
        }
        return promise;
      };
      JS);
  }

  /**
   * A PHP string as a JS string literal.
   */
  private function jsString(string $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR);
  }

  /**
   * Capture, the three placement doors, and the blueprint's own canvas.
   */
  public function testBlueprintCaptureAndPlacementDoors(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // ——— FIXTURE. ———
    $node = Node::create([
      'type' => 'page',
      'title' => 'Blueprint placement page',
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
      "Drupal.Daedalus.Materials.isBooted() && !!Drupal.Daedalus.Materials.get('variant', 'container_site')",
      20000,
      'The materials store delivered the recipe\'s Site width variant.',
    );
    $this->assertTrue(
      $session->evaluateScript("(() => { const site = Drupal.Daedalus.Materials.get('variant', 'container_site'); return !!site && site.applies && site.applies.depth === 'root'; })()"),
      'container_site is root-only: the fact a band derives from.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript('!!window.drupalSettings.daedalusLayout.canSaveBlueprints'),
      'The editor holds the blueprint grant the capture verbs gate on.',
    );

    // The POST counter: "one gesture, one blueprint POST" and "a refused
    // gesture, no POST" are wiring facts, not inferences from the page.
    $this->installPostCounter();

    // The page: two top-level containers A and B, a container C nested in
    // A, B wearing Site width, through the same mutation batch a palette
    // drop enqueues.
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
            { op: 'apply_variant', key: 'container:$b', variant: 'container_site' },
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

    // The Add panel, open before the captures so the item landing without
    // a reload is what the step proves.
    $assert_session->waitForElement('css', '#place_block');
    $this->openAddPanel();
    // The recipe's only blueprints are the site chrome (the `header` and
    // `footer` site_chrome entities the site frame shows), and the chrome
    // is the frame's material: its type is frame-only, so the one fit
    // judgement keeps it out of a page canvas's offer. Before anything is
    // saved there is no blueprint row and no Blueprints kind.
    $this->assertJsCondition(
      "document.querySelectorAll('#place_block [data-daedalus-layout-add-panel-kind=\"blueprint\"]').length === 0"
      . " && !document.querySelector('#place_block [data-daedalus-layout-add-panel-set=\"kind:blueprint\"]')",
      10000,
      'No blueprint row and no Blueprints kind yet: the recipe\'s chrome is frame-only and nothing is saved.',
    );

    // ——— CAPTURE (F3): C becomes "Card". ———
    //
    // A capture is one blueprint POST (the save_material op); the Card
    // wears nothing, so the roster derives no band fact for it. The item
    // paints from the store's record. The creator's mark reaching the
    // server is a wiring fact too: the settings route's POSTs are counted
    // and their bodies kept from before the capture.
    $this->installFavoritePostCounter();
    $card_id = $this->captureAsBlueprint("container:$c", 'Card', 'A bare container.');
    $this->assertJsCondition('window.__blueprintPosts === 2', 10000, 'The Card capture was exactly one blueprint POST.');
    $this->assertSame('card', $card_id);
    $this->assertTrue(
      $session->evaluateScript(<<<'JS'
        (() => {
          const Materials = Drupal.Daedalus.Materials;
          return !Materials.getRoster().isBand(Materials.get('blueprint', 'card'))
            && Materials.get('blueprint', 'card').updatable === true;
        })()
        JS),
      'The delivered record derives no band fact for the Card (it wears nothing) and marks it a content blueprint.',
    );

    // ——— THE CREATOR'S FAVORITE: the Card is this editor's favorite. ———
    //
    // The record landed through the delta, new to this session's store,
    // so the panel marked it through the star's own path: the row is
    // under Favorites with its star on, in both of its rows, the whole
    // map reached the settings route in one POST carrying the mark, and
    // the record's own default is still FALSE (the site favorite stays
    // opt-in; the mark is the editor's own).
    $card_all_row = "#place_block [data-daedalus-layout-add-panel-in=\"kind:blueprint\"][data-daedalus-layout-add-panel-row=\"blueprint:card\"]";
    $favorite_card_row = "#place_block [data-daedalus-layout-add-panel-in=\"favorites\"][data-daedalus-layout-add-panel-row=\"blueprint:card\"]";
    $this->assertNotNull($assert_session->waitForElement('css', $favorite_card_row, 10000), 'The Card sits under Favorites the moment its record lands.');
    $this->assertJsCondition(
      "document.querySelector('$favorite_card_row [data-daedalus-layout-add-panel-star]').getAttribute('aria-pressed') === 'true'"
      . " && document.querySelector('$card_all_row [data-daedalus-layout-add-panel-star]').getAttribute('aria-pressed') === 'true'",
      10000,
      'Both rows of the Card read the star on.',
    );
    $this->assertJsCondition('window.__favoritePosts === 1', 10000, 'The creator\'s mark reached the settings route in one POST.');
    $this->assertTrue(
      (bool) $session->evaluateScript("JSON.parse(window.__favoriteBodies[0])['blueprint:card'] === true"),
      'The posted map carries the Card\'s mark as TRUE.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("Drupal.Daedalus.Materials.get('blueprint', 'card').favorite"),
      'The record\'s own default stays FALSE: the favorite is the creator\'s mark, not the site\'s.',
    );
    // The capture committed a content blueprint with the captured document.
    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    $card_blueprints = $storage->loadByProperties(['machine_name' => 'card']);
    $card_blueprint = reset($card_blueprints);
    $this->assertInstanceOf(Blueprint::class, $card_blueprint);
    $this->assertSame('node', $card_blueprint->getSubjectType(), 'The subject is stamped from the source page.');
    $this->assertSame('page', $card_blueprint->getSubjectBundle());
    $this->assertSame('block', $card_blueprint->getScope());
    $this->assertCount(1, $card_blueprint->get('layout_builder__layout')->getSections(), 'The Card holds the captured container as its one root.');

    // ——— CANVAS (F2, F1): the Card's own canvas. ———
    //
    // A blueprint is a page: its canonical route renders the canvas in
    // Edit Mode over the `blueprint` section storage. A text block placed
    // into its one root container through click-to-place stages in the
    // host's tempstore; the top bar's Save commits it through the storage
    // handler as a new revision; the reloaded canvas shows the block.
    assert($storage instanceof RevisionableStorageInterface);
    $revisions_before = $storage->getQuery()->allRevisions()->condition('id', $card_blueprint->id())->accessCheck(FALSE)->execute();
    $this->drupalGet($card_blueprint->toUrl());
    $this->enterEditModeViaCookies($card_blueprint->toUrl());
    $this->assertJsCondition(
      "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()"
      . " && Drupal.Daedalus.Blueprint.rootContext().entityType === 'blueprint'",
      20000,
      'The blueprint canvas booted the mirror over the blueprint entity.',
    );
    $canvas_containers = $this->containerKeys();
    $this->assertCount(1, $canvas_containers, 'The Card\'s canvas renders its one root container.');
    $root_key = $canvas_containers[0];
    $assert_session->waitForElement('css', '#place_block');
    $this->openAddPanel();
    $this->armInsert($root_key);

    // ——— THE SUBJECT (F2): the subject's fields on the canvas. ———
    //
    // The blueprint storage's preview contexts carry the subject (core's
    // sample page node) as layout_builder.entity. The Add panel's roster
    // reads those contexts through the placement restrictions filter, so
    // it offers the page bundle's field blocks and none of the blueprint
    // type's own; the placed field block is core's FieldBlock rendering
    // against the sample, so the field shows sample content. Kernel sees
    // the storage's answer; only the browser sees the roster and the render.
    // The panel's faces are the page's attached answer (AddPanelFaces);
    // the row is drawn under All's category for the field once revealed.
    $this->assertTrue(
      (bool) $session->evaluateScript("drupalSettings.daedalusLayout.addPanel.faces.some((face) => face.id === 'field_block:node:page:title')"),
      "The Add panel lists the subject bundle's title field block on the blueprint canvas.",
    );
    $this->assertSame(
      0,
      (int) $session->evaluateScript("drupalSettings.daedalusLayout.addPanel.faces.filter((face) => face.id.startsWith('field_block:blueprint:')).length"),
      'The Add panel offers none of the blueprint type\'s own field blocks: the faces read the subject.',
    );
    $root_blocks_css = "[data-main-entity] [data-selectable='$root_key'] [data-selectable^='block:']";
    $title_row = $this->revealAddPanelRow('field_block:node:page:title');
    $session->executeScript("document.querySelector('$title_row').click();");
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$root_blocks_css [data-element=\"kind:field\"]", 20000),
      'The title field block rendered inside the blueprint\'s root container as a field, not an empty block.',
    );
    $field_block_key = (string) $session->evaluateScript("document.querySelector(\"$root_blocks_css\").getAttribute('data-selectable')");
    $this->assertNotSame(
      '',
      trim((string) $session->evaluateScript("document.querySelector(\"$root_blocks_css [data-element='kind:field']\").textContent")),
      'The field block renders the subject sample\'s title: the storage\'s preview contexts reached core\'s FieldBlock render.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$field_block_key' && document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      'The placement selected the field block and advanced the armed anchor to it.',
    );

    // The text block lands after the field block, at the advanced anchor.
    $text_row = $this->revealAddPanelRow('inline_block:text');
    $session->executeScript("document.querySelector('$text_row').click();");
    $this->assertJsCondition(
      "document.querySelectorAll(\"$root_blocks_css\").length === 2",
      20000,
      'The Text block rendered inside the blueprint\'s root container after the field block.',
    );
    // The text block's sample text, saved into the Card below: the peek
    // step reads it back out of the frame's document.
    $card_text = trim((string) $session->evaluateScript("document.querySelectorAll(\"$root_blocks_css\")[1].textContent"));
    $this->assertNotSame('', $card_text, 'The placed text block arrived with sample text.');
    $this->pressHotkey('Escape');
    $assert_session->elementExists('css', '#edit-mode-top-bar #daedalus-ui-save');
    $session->executeScript("window.__preSave = true; document.querySelector('#daedalus-ui-save').click();");
    $this->assertJsCondition('window.__preSave === undefined', 20000, 'The Save button navigated.');
    $this->assertTrue($session->wait(20000, "document.readyState === 'complete'"), 'The save landed a document.');

    // The commit: a second revision whose document holds the block.
    $storage->resetCache([$card_blueprint->id()]);
    $saved = $storage->loadUnchanged($card_blueprint->id());
    $revisions_after = $storage->getQuery()->allRevisions()->condition('id', $card_blueprint->id())->accessCheck(FALSE)->execute();
    $this->assertCount(count($revisions_before) + 1, $revisions_after, 'The canvas save committed a new revision of the blueprint.');
    $sections = $saved->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections, 'The blueprint still holds its one root.');
    $plugin_ids = array_map(static fn ($component) => $component->getPluginId(), array_values($sections[0]->getComponents()));
    sort($plugin_ids);
    $this->assertSame(
      ['field_block:node:page:title', 'inline_block:text'],
      $plugin_ids,
      'The root holds the placed field block (expanded against the subject bundle) and the text block.',
    );

    // The reloaded canvas shows both blocks where they were placed, the
    // field block rendering its field again. The save's redirect wrote a
    // page-scoped navigationMode=none cookie onto the blueprint's path,
    // which outranks the session-wide edit cookie (the landing is out of
    // Edit Mode by design); dropping it is the toggle a user would click.
    $session->setCookie('navigationMode', NULL);
    $this->enterEditModeViaCookies($card_blueprint->toUrl());
    $this->assertJsCondition(
      "document.querySelectorAll(\"$root_blocks_css\").length === 2",
      20000,
      'After the reload the blueprint\'s canvas renders the two saved blocks inside its root.',
    );
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$root_blocks_css [data-element=\"kind:field\"]", 20000),
      'After the reload the saved field block renders against the subject again.',
    );

    // ——— BACK TO THE PAGE: the saved blueprint is delivered at boot. ———
    //
    // The canvas navigated away. The page boots again with the Card in the
    // materials store from discovery's content lane (no capture this
    // time), and the POST counter starts over.
    $this->drupalGet($node->toUrl());
    $this->enterEditModeViaCookies($node->toUrl());
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The page booted the mirror again.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Materials.isBooted() && !!Drupal.Daedalus.Materials.get('blueprint', 'card')",
      20000,
      'The saved Card is delivered at boot: discovery serves the content blueprint.',
    );
    $this->assertSame(["container:$a", "container:$c", "container:$b"], $this->containerKeys(), 'The page still holds A (with C inside) then B.');
    $this->installPostCounter();
    $assert_session->waitForElement('css', '#place_block');
    $this->openAddPanel();
    $this->assertNotNull(
      $assert_session->waitForElement('css', '#place_block [data-daedalus-layout-add-panel-in="kind:blueprint"][data-daedalus-layout-add-panel-row="blueprint:card"][data-daedalus-layout-add-panel-kind="blueprint"]', 20000),
      'The Add panel lists the Card from the boot payload.',
    );

    // ——— CAPTURE (F3), THE BAND: B becomes "Band". ———
    //
    // The Band's root wears container_site, so the roster derives it a
    // band; the capture carries that wearing into the blueprint's own
    // style store, so the blueprint bundle must have styling enabled on
    // the recipe's site.
    $band_id = $this->captureAsBlueprint("container:$b", 'Band', 'A page band wearing Site width.');
    $this->assertJsCondition('window.__blueprintPosts === 1', 10000, 'The Band capture was exactly one blueprint POST.');
    $this->assertSame('band', $band_id);
    $this->assertTrue(
      $session->evaluateScript(<<<'JS'
        (() => {
          const Materials = Drupal.Daedalus.Materials;
          return Materials.getRoster().isBand(Materials.get('blueprint', 'band'))
            && !Materials.getRoster().isBand(Materials.get('blueprint', 'card'))
            && Materials.get('blueprint', 'band').updatable === true;
        })()
        JS),
      'The delivered records derive the band fact: the Band wears the root-only Site width, the Card does not; both are content blueprints.',
    );
    $band_blueprints = $storage->loadByProperties(['machine_name' => 'band']);
    $band_blueprint = reset($band_blueprints);
    $this->assertInstanceOf(Blueprint::class, $band_blueprint);
    $this->assertSame('node', $band_blueprint->getSubjectType(), 'The Band\'s subject is stamped from the source page too.');
    $this->assertSame('page', $band_blueprint->getSubjectBundle());
    $this->assertSame('block', $band_blueprint->getScope());
    $this->assertCount(1, $band_blueprint->get('layout_builder__layout')->getSections(), 'The Band holds the captured container as its one root.');
    $session->executeScript('Drupal.Daedalus.Selection.clear();');
    $this->openAddPanel();
    $this->assertJsCondition(
      "(() => { const item = document.querySelector('#place_block [data-daedalus-layout-add-panel-in=\"kind:blueprint\"][data-daedalus-layout-add-panel-row=\"blueprint:band\"]');"
      . " return !!item && item.getAttribute('data-daedalus-layout-add-panel-kind') === 'blueprint' && typeof item.ondragstart === 'function'; })()",
      20000,
      'The Add panel rendered the Band row as a blueprint (a section drop) and the drag layer armed it.',
    );
    $this->assertJsCondition(
      "typeof document.querySelector('#place_block [data-daedalus-layout-add-panel-in=\"kind:blueprint\"][data-daedalus-layout-add-panel-row=\"blueprint:card\"]').ondragstart === 'function'",
      20000,
      'The Card row is armed too.',
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
    // wearing exactly what it captured (Site width) and stamped with its
    // provenance.
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
    $this->assertSame('band', $this->provenanceOf($band_key), 'The placed root carries its provenance: the mirror\'s `blueprint` key names the blueprint it was placed from.');
    $this->assertTrue(
      (bool) $session->evaluateScript(
        "Drupal.Daedalus.Verbs.verbsFor('$band_key').some((verb) => verb.id === 'daedalus_layout.open_blueprint')"
        . " && Drupal.Daedalus.Verbs.verbsFor('$band_key').some((verb) => verb.id === 'daedalus_layout.update_material')"
      ),
      'A placement\'s menu offers Open blueprint and Update blueprint: the stamp resolves to a content blueprint the editor may update.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("Drupal.Daedalus.Verbs.verbsFor('container:$a').some((verb) => verb.id === 'daedalus_layout.open_blueprint')"),
      'An unstamped container offers no Open blueprint.',
    );

    // ——— THE CHIP: a placed blueprint's three verbs are one control. ———
    //
    // Open, Update and Save as share the registry group
    // daedalus_layout.blueprint (js/verbs/blueprint.js), and the selection
    // chip draws a group as ONE split control (daedalus_ui's
    // selection-frame.js GroupControl): the main half is the lightest
    // member, Update, named for its target; the caret opens the shared
    // menu over the rest, flat in weight order, where Save as reads "Save
    // as new blueprint…" because the container is a placement. On a
    // container that is no placement only Save as applies, and the
    // registry's fold renders a group of one as the plain button it was.
    // The seam: a registry key read by the chip's model into a control the
    // menu primitive then lists from, three layers that only meet in a
    // browser (verb-groups.test.mjs pins the fold, selection-frame.test.mjs
    // the model's entries, nothing but this the DOM they become). The
    // caret half wears the chip's verb skin too (a `__verb` button labelled
    // "More Update blueprint actions"), so the count of blueprint VERB
    // buttons leaves the caret out: it runs no verb, it opens the menu.
    $session->executeScript("Drupal.Daedalus.Selection.select('$band_key');");
    $chip = '.daedalus-ui-frame__chip--primary';
    $verb_buttons = "Array.from(document.querySelectorAll('$chip .daedalus-ui-frame__verb:not(.daedalus-ui-split-button__caret)')).filter((button) => /blueprint/.test(button.getAttribute('aria-label') ?? ''))";
    $this->assertJsCondition(
      "(() => { const groups = document.querySelectorAll('$chip .daedalus-ui-frame__group');"
      . " return groups.length === 1"
      . " && groups[0].querySelector('.daedalus-ui-split-button__main').getAttribute('aria-label') === \"Update blueprint 'Band'\""
      . " && groups[0].querySelector('.daedalus-ui-split-button__caret').getAttribute('aria-haspopup') === 'menu'"
      . " && $verb_buttons.length === 1; })()",
      20000,
      'The placed band\'s chip shows one split control led by "Update blueprint \'Band\'", and no other blueprint verb button on its face.',
    );
    $session->executeScript("document.querySelector('$chip .daedalus-ui-split-button__caret').click();");
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-popover > .daedalus-ui-menu') !== null",
      10000,
      'The caret opened the shared menu.',
    );
    $this->assertSame(
      ["Open blueprint 'Band'", 'Save as new blueprint…'],
      $session->evaluateScript(
        "Array.from(document.querySelectorAll('.daedalus-ui-popover > .daedalus-ui-menu .daedalus-ui-menu__item .daedalus-ui-menu__label')).map((label) => label.textContent)"
      ),
      'The caret menu lists the group\'s other members flat in weight order, Save as worded for a placement.',
    );
    $session->executeScript(<<<'JS'
      Array.from(document.querySelectorAll('.daedalus-ui-popover > .daedalus-ui-menu .daedalus-ui-menu__item'))
        .find((item) => item.querySelector('.daedalus-ui-menu__label').textContent === 'Save as new blueprint…')
        .click();
      JS);
    $this->assertNotNull(
      $assert_session->waitForElementVisible('css', '#layout-builder-modal #daedalus-layout-blueprint-label', 20000),
      '"Save as new blueprint…" from the caret menu opened the save dialog.',
    );
    $session->executeScript("document.querySelector('#layout-builder-modal [data-blueprint-cancel]').click();");
    $this->assertJsCondition(
      "!document.querySelector('#layout-builder-modal #daedalus-layout-blueprint-label')",
      10000,
      'Cancel closed the save dialog without a save.',
    );
    $this->assertSame(2, (int) $session->evaluateScript('window.__blueprintPosts'), 'The dialog round trip was no POST.');
    $session->executeScript("Drupal.Daedalus.Selection.select('container:$a');");
    $this->assertJsCondition(
      "(() => { const buttons = $verb_buttons;"
      . " return document.querySelectorAll('$chip .daedalus-ui-frame__group').length === 0"
      . " && buttons.length === 1 && buttons[0].getAttribute('aria-label') === 'Save as blueprint…'; })()",
      20000,
      'An unstamped container\'s chip keeps the plain "Save as blueprint…" button: a group of one is no split control.',
    );

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
    $this->openAddPanel();

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
    $this->assertSame('card', $this->provenanceOf($card_key), 'The clicked placement carries its provenance too.');
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
    $band_anchor = $placed[0];

    // ——— CLICK, THEN ENTER: the placed block's editor opens by request. ———
    //
    // The session's anchor advanced to the clicked band; a block item now
    // places inside it. The placement lands a plain selection (no landing
    // opens an editor) and the session stays armed, so its Enter binding
    // (daedalus_layout.insert.place, registered while armed, ahead of the
    // static edit.enter on the same key and context) is the stroke's
    // winner; with no panel item highlighted it PASSES the stroke on
    // through daedalus_ui's dispatcher, and edit.enter opens the placed
    // block's editor through daedalus_edit's engine. Three modules, one
    // keystroke: only a browser sees the path whole.
    $this->assertJsCondition(
      "document.documentElement.classList.contains('daedalus-layout-insert-pending')"
      . " && Drupal.Daedalus.Selection.getSelected() === '$band_anchor'",
      10000,
      'The band click advanced the armed anchor to the placed band.',
    );
    $text_row = $this->revealAddPanelRow('inline_block:text');
    $session->executeScript("document.querySelector('$text_row').click();");
    $this->assertJsCondition('window.__blueprintPosts === 5', 20000, 'The Text block click was one blueprint POST.');
    $this->assertNotNull(
      $assert_session->waitForElement('css', "[data-main-entity] [data-selectable='$band_anchor'] [data-selectable^='block:']", 20000),
      'The Text block rendered inside the band, the anchor.',
    );
    $block_key = $session->evaluateScript("document.querySelector(\"[data-main-entity] [data-selectable='$band_anchor'] [data-selectable^='block:']\").getAttribute('data-selectable')");
    // The placed block's sample text (the recipe's sample policy fills a
    // placed text block): the page capture below carries it into Landing,
    // and the live preview step reads it back out of a card's frame.
    $placed_text = trim((string) $session->evaluateScript("document.querySelector(\"[data-main-entity] [data-selectable='$block_key']\").textContent"));
    $this->assertNotSame('', $placed_text, 'The placed text block arrived with sample text.');
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " return Drupal.Daedalus.Selection.getSelected() === '$block_key'"
      . " && Drupal.Daedalus.EditSession.isEditing() === false"
      . " && frame && !frame.classList.contains('daedalus-ui-frame--editing')"
      . " && document.documentElement.classList.contains('daedalus-layout-insert-pending'); })()",
      20000,
      'The placed block landed a plain selection (no edit, no dash) and the insert session is still armed on it.',
    );
    $this->pressHotkey('Enter');
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;"
      . " return Drupal.Daedalus.EditSession.isEditing() === true"
      . " && frame && frame.classList.contains('daedalus-ui-frame--editing')"
      . " && !!editing && !!document.querySelector(\"[data-selectable='$block_key']\")?.contains(editing.getPageElement()); })()",
      20000,
      'Enter with the session armed and nothing highlighted passed through insert.place to edit.enter: the placed block\'s editor opened.',
    );
    $this->assertJsCondition(
      "document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      'Opening the editor left the insert session armed.',
    );
    // Escape with the edit open and the session armed cancels the edit and
    // only that: the open edit's cancel outranks the session's done
    // binding and does not pass the stroke on, so the panel stays open,
    // the session stays armed and the selection stands.
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('.daedalus-ui-frame');"
      . " return Drupal.Daedalus.EditSession.isEditing() === false"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && Drupal.Daedalus.Selection.getSelected() === '$block_key'"
      . " && frame && !frame.classList.contains('daedalus-ui-frame--editing'); })()",
      20000,
      'Escape cancelled the open edit only: the placed block stays selected as a plain selection.',
    );
    $this->assertJsCondition(
      "document.documentElement.classList.contains('daedalus-layout-insert-pending')"
      . " && !document.getElementById('place_block').classList.contains('daedalus-ui-hidden')",
      10000,
      'The consumed Escape never reached the session: it is still armed and the Add panel is still open.',
    );

    // ——— PAGE CAPTURE: the top bar's Save menu. ———
    //
    // The page root's own capture: "Save page as blueprint…" is a root
    // verb flagged for the document menu, so the Save caret lists it; the
    // item opens the save dialog worded for a page (the element is the
    // host's uuid), and the commit is one save_material POST whose capture
    // is the whole document, scope page. A page-scope blueprint is not
    // offered on any canvas, so the response's delta lands no record and
    // the open Add panel gains no item. Five layers (a top bar menu item,
    // a verb, a dialog, an op, the delta) that only meet in a browser.
    $session->executeScript('Drupal.Daedalus.Selection.clear();');
    $this->assertJsCondition(
      "(() => { const caret = document.querySelector('#edit-mode-top-bar #daedalus-ui-save-menu'); return !!caret && !caret.hidden; })()",
      10000,
      'The Save caret shows: the root offers a document verb to the editor.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript(
        "(() => { const root = Drupal.Daedalus.Blueprint.getRoot();"
        . " return Drupal.Daedalus.Verbs.verbsFor('entity:' + root.uuid).some((verb) => verb.id === 'daedalus_layout.save_page_material' && verb.document === true); })()"
      ),
      'The page capture verb applies to the root key and asks for the document menu.',
    );
    $session->executeScript("document.querySelector('#daedalus-ui-save-menu').click();");
    $menu_item = $assert_session->waitForElement('xpath', "//div[contains(@class,'daedalus-ui-menu')]//button[contains(@class,'daedalus-ui-menu__item')][.//span[contains(@class,'daedalus-ui-menu__label')][normalize-space(.)='Save page as blueprint…']]", 10000);
    $this->assertNotNull($menu_item, 'The Save caret opened the document menu with "Save page as blueprint…".');
    $menu_item->click();
    $label_input = $assert_session->waitForElementVisible('css', '#layout-builder-modal #daedalus-layout-blueprint-label', 20000);
    $this->assertNotNull($label_input, 'The menu item opened the save dialog.');
    $this->assertNotNull(
      $assert_session->waitForElement('css', '#layout-builder-modal [data-blueprint-scope="page"]', 10000),
      'The dialog is worded for a page: it states the page scope.',
    );
    // The count reads the blueprint SET: a blueprint this editor captured
    // is also under Favorites (the creator's mark), one record, two rows.
    $blueprint_set_rows = "document.querySelectorAll('#place_block [data-daedalus-layout-add-panel-in=\"kind:blueprint\"][data-daedalus-layout-add-panel-kind=\"blueprint\"]').length";
    $items_before = (int) $session->evaluateScript($blueprint_set_rows);
    $this->assertSame(2, $items_before, 'The Add panel\'s blueprint set lists the Card and the Band before the page capture (the recipe\'s chrome is frame-only, never offered here).');
    $session->executeScript(<<<JS
      (() => {
        document.getElementById('daedalus-layout-blueprint-label').value = 'Landing';
        document.getElementById('daedalus-layout-blueprint-description').value = 'A whole landing page.';
        document.querySelector('#layout-builder-modal [data-blueprint-save]').click();
      })();
      JS);
    $this->assertJsCondition(
      "!document.querySelector('#layout-builder-modal #daedalus-layout-blueprint-label')",
      20000,
      'The dialog closed on success.',
    );
    $this->assertJsCondition('window.__blueprintPosts === 6', 10000, 'The page capture was exactly one blueprint POST.');
    $landing_blueprints = $storage->loadByProperties(['machine_name' => 'landing']);
    $landing_blueprint = reset($landing_blueprints);
    $this->assertInstanceOf(Blueprint::class, $landing_blueprint);
    $this->assertSame('page', $landing_blueprint->getScope(), 'The root capture minted a page-scope blueprint.');
    $this->assertSame('node', $landing_blueprint->getSubjectType());
    $this->assertSame('page', $landing_blueprint->getSubjectBundle());
    $this->assertCount(
      count(array_filter($this->containerKeys(), fn (string $key) => $this->parentContainerKey($key) === NULL)),
      $landing_blueprint->get('layout_builder__layout')->getSections(),
      'The page blueprint holds every top-level container of the page as its roots.',
    );
    $this->assertNull(
      $session->evaluateScript("Drupal.Daedalus.Materials.get('blueprint', 'landing') ?? null"),
      'A page-scope blueprint is not offered on the canvas: the delta landed no record in the store.',
    );
    $this->assertSame(
      $items_before,
      (int) $session->evaluateScript($blueprint_set_rows),
      'The open Add panel gained no item from the page capture.',
    );

    // ——— OPEN BLUEPRINT: the placement's door lands armed. ———
    //
    // "Open blueprint" is a plain navigation to the blueprint's canonical
    // URL (the record's url, read through the placement's provenance), and
    // the navigationMode cookie is scoped
    // to the page it was set on, so nothing the navigation carries can arm
    // the canvas: the canvas route's own first visit enters Edit Mode
    // (BlueprintCanvasMode through daedalus_ui's one door,
    // ModeState::enterModeOnFirstVisit) and plants the path-scoped cookie
    // the client boot gate reads. The Band's canvas has not been visited
    // in this session, and the test's own session-wide edit cookie is
    // swept first so only the server's answer can arm the landing. The
    // seam: a queued path-scoped cookie honoured by the next navigation.
    $session->setCookie('navigationMode', NULL);
    $this->assertNull($session->getCookie('navigationMode'), 'No mode cookie of the test\'s own reaches the next navigation.');
    $session->executeScript(<<<JS
      (() => {
        const verb = Drupal.Daedalus.Verbs.verbsFor('$band_key')
          .find((candidate) => candidate.id === 'daedalus_layout.open_blueprint');
        if (!verb) {
          throw new Error('The Open blueprint verb does not apply to $band_key.');
        }
        verb.run('$band_key');
      })();
      JS);
    $band_path = $band_blueprint->toUrl()->toString();
    $this->assertTrue(
      $session->wait(20000, "document.readyState === 'complete' && window.location.pathname.endsWith('$band_path')"),
      'Open blueprint navigated to the Band\'s canvas.',
    );
    $this->assertJsCondition(
      "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()"
      . " && Drupal.Daedalus.Blueprint.rootContext().entityType === 'blueprint'",
      20000,
      'The Band\'s canvas booted the mirror over the blueprint entity: the landing is in Edit Mode.',
    );
    $assert_session->elementExists('css', '#edit-mode-top-bar #daedalus-ui-save');
    $this->assertSame(
      'edit',
      $session->evaluateScript("Drupal.Daedalus.getCookieValue('navigationMode')"),
      'The server planted the path-scoped edit cookie the client boot gate read.',
    );
    $this->assertCount(1, $this->containerKeys(), 'The Band\'s canvas renders its one root container.');

    // ——— FROM THE ADD FORM TO THE CANVAS: a start, then Edit Mode. ———
    //
    // The chooser is the add form itself: with the bundle's setting on and
    // the Landing (the page capture above) offered for Page content, the
    // form opens on the cards, a card is one link carrying ?start=, and
    // the form's own save applies the start (the entity builder on the
    // submit pass) so entity and document land in one save. The bundle's
    // initial mode queues the Edit Mode cookie at insert, so the redirect
    // lands on the new page's canvas with the blueprint's content on it,
    // selectable, and no root is a placement (a started page carries no
    // provenance, so "Update blueprint" never applies to it).
    NodeType::load('page')->setThirdPartySetting('daedalus_layout', 'offer_starts', TRUE)->save();
    $landing_roots = count($landing_blueprint->get('layout_builder__layout')->getSections());
    $this->assertGreaterThan(0, $landing_roots);
    $this->drupalGet('/node/add/page');
    $assert_session->elementExists('css', '[data-daedalus-layout-start-chooser]');
    $assert_session->fieldNotExists('title[0][value]');
    $landing_card = $assert_session->elementExists('css', 'a[data-daedalus-layout-start-card="landing"]');

    // ——— A CARD'S LIVE PREVIEW: the frame holds the start's dry run. ———
    //
    // The Landing card's face is a lazily loaded frame on the preview
    // route (a sample page with the start applied, unsaved, in preview
    // mode). The seam: a lazily loaded frame, its document arriving and
    // its scaling onto the face are browser facts; the Functional class
    // sees the route answer 200 and nothing of the frame. The card is
    // scrolled into view first, since a lazy frame below the fold never
    // loads.
    $frame_selector = 'a[data-daedalus-layout-start-card="landing"] iframe.daedalus-layout-start-card__frame';
    $frame = $assert_session->elementExists('css', $frame_selector);
    $this->assertSame('lazy', $frame->getAttribute('loading'));
    $this->assertStringContainsString('/daedalus-layout/start-preview/node/page/landing?navigationMode=preview', $frame->getAttribute('src'));
    $session->executeScript("document.querySelector('a[data-daedalus-layout-start-card=\"landing\"]').scrollIntoView();");
    $placed_text_json = json_encode($placed_text, JSON_THROW_ON_ERROR);
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('$frame_selector');"
      . " const doc = frame && frame.contentDocument;"
      . " return !!doc && doc.readyState === 'complete' && !!doc.body && doc.body.textContent.includes($placed_text_json); })()",
      20000,
      'The Landing card\'s frame holds the preview document, the blueprint\'s text in it.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript(
        "(() => { const doc = document.querySelector('$frame_selector').contentDocument;"
        . " return doc.documentElement.getAttribute('data-daedalus-ui-mode') === 'preview'"
        . " && !doc.getElementById('edit-mode-top-bar') && !doc.querySelector('[data-daedalus-layout-start-chooser]'); })()"
      ),
      'The framed document is the page in preview mode: no editor, and not the chooser framing itself.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript(
        "(() => { const frame = document.querySelector('$frame_selector');"
        . " const face = frame.parentElement; const box = frame.getBoundingClientRect(); const faceBox = face.getBoundingClientRect();"
        . " return getComputedStyle(frame).transform !== 'none' && frame.offsetWidth > faceBox.width * 2"
        . " && box.width <= faceBox.width + 1 && Math.abs(box.left - faceBox.left) < 1"
        . " && getComputedStyle(frame).pointerEvents === 'none'; })()"
      ),
      'The frame lays its document out wider than the face and scales it onto the face from the top left, taking no pointer.',
    );
    $landing_card->click();
    $this->assertTrue(
      $session->wait(20000, "document.readyState === 'complete' && document.querySelector('.daedalus-layout-start-line[data-daedalus-layout-start=\"landing\"]') !== null"),
      'The pick led to the form under the "Starting from" line.',
    );
    $assert_session->elementTextContains('css', '.daedalus-layout-start-line', 'Starting from Landing.');
    $page = $session->getPage();
    $page->fillField('title[0][value]', 'Started from Landing');
    $page->pressButton('Save');
    $this->assertTrue(
      $session->wait(20000, "document.readyState === 'complete' && /\\/node\\/\\d+$/.test(window.location.pathname)"),
      'The save landed on the new page.',
    );
    $started_nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['title' => 'Started from Landing']);
    $started_node = reset($started_nodes);
    $this->assertInstanceOf(Node::class, $started_node);
    $this->assertStringEndsWith($started_node->toUrl()->toString(), $session->evaluateScript('window.location.pathname'));
    $this->assertJsCondition(
      "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()"
      . " && Drupal.Daedalus.Blueprint.rootContext().entityType === 'node'",
      20000,
      'The new page booted the mirror: the initial mode queued at insert landed it in Edit Mode.',
    );
    $assert_session->elementExists('css', '#edit-mode-top-bar #daedalus-ui-save');
    $started_keys = array_values(array_filter($this->containerKeys(), fn (string $key) => $this->parentContainerKey($key) === NULL));
    $this->assertCount($landing_roots, $started_keys, 'The blueprint\'s roots are on the canvas, as many as the blueprint holds.');
    foreach ($started_keys as $key) {
      $this->assertNull($this->provenanceOf($key), "A started page is not a placement: $key carries no provenance.");
      $this->assertFalse(
        (bool) $session->evaluateScript("Drupal.Daedalus.Verbs.verbsFor('$key').some((verb) => verb.id === 'daedalus_layout.update_material')"),
        "No \"Update blueprint\" on the started root $key.",
      );
    }
    $session->executeScript("Drupal.Daedalus.Selection.select('$started_keys[0]');");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$started_keys[0]'",
      10000,
      'A started container is selectable on the canvas.',
    );

    // ——— THE EMPTY CANVAS: the root is the insert anchor. ———
    //
    // "Add blueprint" saves a blueprint with no document. Its canvas opens
    // empty (a blueprint's storage is always its own, never the subject's
    // template) wearing the blank-page affordance worded for a blueprint,
    // whose hint promises the Blocks key works; a keyboard user cannot
    // drag, so opening the Add panel from the affordance arms
    // click-to-place against the root entity (gated by the root's insert
    // capability, which a rootless block blueprint grants), and one click
    // on Container places the first root through the same op builders a
    // drop uses. The panel is closed first: the sidebar cookie may render
    // it open on landing, and the step proves the OPENING arms. The seam:
    // the arming is event plumbing between the blank-page button, the
    // sidebar manager's open and the panel's observer of it, three parties
    // that only meet in a browser; Kernel pins the hint's wording
    // (LayoutRootTest).
    $blank_blueprint = Blueprint::create([
      'type' => 'section',
      'label' => 'Blank',
      'machine_name' => 'uncomposed',
      'scope' => 'block',
      'subject_type' => 'node',
      'subject_bundle' => 'page',
      'status' => 1,
    ]);
    $blank_blueprint->save();
    $this->drupalGet($blank_blueprint->toUrl());
    $this->assertJsCondition(
      "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()"
      . " && Drupal.Daedalus.Blueprint.rootContext().entityType === 'blueprint'",
      20000,
      'The blank blueprint\'s canvas landed in Edit Mode on its first visit.',
    );
    $this->assertSame([], $this->containerKeys(), 'The blank blueprint renders no container: its canvas opened empty, not on the subject\'s template.');
    $hint = $assert_session->waitForElement('css', '#daedalus-layout-blank-page .daedalus-layout-blank-page__hint', 10000);
    $this->assertNotNull($hint, 'The empty canvas wears the blank-page affordance.');
    $this->assertStringStartsWith('Empty blueprint.', trim($hint->getText()), 'The hint names its host.');
    $assert_session->waitForElement('css', '#place_block');
    $session->executeScript("Drupal.Daedalus.SidebarManager.closeSidebar('place_block');");
    $this->assertJsCondition(
      "document.getElementById('place_block').classList.contains('daedalus-ui-hidden')"
      . " && !document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      'The Add panel is closed and nothing is armed before the affordance is clicked.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript("Drupal.Daedalus.Selection.capable('entity:' + Drupal.Daedalus.Blueprint.getRoot().uuid, 'insert')"),
      'The rootless block blueprint grants insert at its root: the gate the arming asks.',
    );
    $this->installPostCounter();
    $session->executeScript("document.getElementById('daedalus-layout-blank-page').click();");
    $this->assertJsCondition(
      "!document.getElementById('place_block').classList.contains('daedalus-ui-hidden')"
      . " && document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      'The affordance opened the Add panel, and the panel armed click-to-place against the root.',
    );
    // The Container's row under Favorites: the one set open by default,
    // and the Container is a favorite by the panel's own word.
    $container_row = '#place_block [data-daedalus-layout-add-panel-in="favorites"][data-daedalus-layout-add-panel-row="daedalus_layout_section"]';
    $this->assertNotNull($assert_session->waitForElement('css', $container_row, 10000), 'Favorites draws the Container.');
    $session->executeScript("document.querySelector('$container_row').click();");
    $this->assertNotNull(
      $assert_session->waitForElement('css', "[data-main-entity] [data-selectable^='container:']", 20000),
      'One click on Container placed the first container into the empty document.',
    );
    $blank_root_keys = $this->containerKeys();
    $this->assertCount(1, $blank_root_keys, 'The blank blueprint now holds its one root container.');
    $this->assertJsCondition('window.__blueprintPosts === 1', 10000, 'The click was exactly one blueprint POST.');
    $this->assertJsCondition(
      "!document.getElementById('daedalus-layout-blank-page')",
      10000,
      'The blank-page affordance left with the first placement.',
    );
    $this->assertJsCondition(
      "document.documentElement.classList.contains('daedalus-layout-insert-pending')"
      . " && Drupal.Daedalus.Selection.getSelected() === '$blank_root_keys[0]'",
      10000,
      'The placement selected the new container and the session stays armed, advanced onto it.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("Drupal.Daedalus.Selection.capable('entity:' + Drupal.Daedalus.Blueprint.getRoot().uuid, 'insert')"),
      'With its one root placed, the block blueprint refuses a second at the root (BlueprintScopePolicy): the gate reads the refreshed verdict.',
    );

    // ——— THE SETS (saved-blueprints landing 4, steps 2 to 5). ———
    //
    // The panel is one list of folding sets drawn by the bundle from the
    // attached faces and the materials store. Four steps on this canvas,
    // where the root container just placed takes blocks inside it.
    $root_container = $blank_root_keys[0];
    $root_blocks_css = "[data-main-entity] [data-selectable='$root_container'] [data-selectable^='block:']";
    $favorites_heading = "document.querySelector('#place_block [data-daedalus-layout-add-panel-set=\"favorites\"]')";
    $expanded = static fn (string $set_id): string => "document.querySelector('#place_block [data-daedalus-layout-add-panel-set=\"$set_id\"]')?.getAttribute('aria-expanded')";
    $booted = "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()"
      . " && !!document.querySelector('#place_block [data-daedalus-layout-add-panel-set=\"favorites\"]')";

    // Step 2: a set folds and stays folded. Favorites starts open; fold
    // it, open All and its Basics, reload: the panel opens as it was
    // left. The seam: the fold store is read at mount, before the first
    // paint, and only a browser has both the store and the paint.
    $this->openAddPanel();
    $this->assertJsCondition("{$expanded('favorites')} === 'true'", 10000, 'Favorites starts open.');
    $session->executeScript("$favorites_heading.click();");
    $this->assertJsCondition(
      "{$expanded('favorites')} === 'false' && !document.querySelector('#place_block [data-daedalus-layout-add-panel-in=\"favorites\"]')",
      10000,
      'Folded, Favorites draws none of its rows.',
    );
    $this->openAddPanelSet('all');
    $this->openAddPanelSet('kind:basic');
    $this->assertNotNull(
      $assert_session->waitForElement('css', '#place_block [data-daedalus-layout-add-panel-in="kind:basic"][data-daedalus-layout-add-panel-row="daedalus_layout_section"]', 10000),
      'Open, Basics draws the Container under All.',
    );
    $this->drupalGet($blank_blueprint->toUrl());
    $this->assertJsCondition($booted, 20000, 'The canvas booted again and the panel mounted.');
    $this->assertJsCondition(
      "{$expanded('favorites')} === 'false' && {$expanded('all')} === 'true' && {$expanded('kind:basic')} === 'true'",
      10000,
      'Reloaded, the panel opens as it was left: Favorites folded, All and Basics open.',
    );
    $this->openAddPanel();
    $session->executeScript("$favorites_heading.click();");
    $this->assertJsCondition("{$expanded('favorites')} === 'true'", 10000, 'Favorites is open again for the steps below.');

    // Step 3: a star, round trip. Star the title field block's row under
    // All: it is under Favorites at once; reload: it is still there. The
    // seam: the click, the generic settings route, the user's field, the
    // next page's delivery and the mount agree only in a browser.
    $title_id = 'field_block:node:page:title';
    $title_row = $this->revealAddPanelRow($title_id);
    $favorite_title_row = "#place_block [data-daedalus-layout-add-panel-in=\"favorites\"][data-daedalus-layout-add-panel-row=\"$title_id\"]";
    $this->assertFalse(
      (bool) $session->evaluateScript("drupalSettings.daedalusLayout.addPanel.favorites['$title_id'] === true"),
      'No mark on the title block yet.',
    );
    $this->assertNull($session->getPage()->find('css', $favorite_title_row), 'The title block is not under Favorites yet.');
    // The mark reaching the server is a wiring fact: count the settings
    // route's completed POSTs.
    $this->installFavoritePostCounter();
    $session->executeScript("document.querySelector('$title_row [data-daedalus-layout-add-panel-star]').click();");
    $this->assertNotNull($assert_session->waitForElement('css', $favorite_title_row, 10000), 'The starred block is under Favorites at once.');
    $this->assertJsCondition(
      "document.querySelector('$title_row [data-daedalus-layout-add-panel-star]').getAttribute('aria-pressed') === 'true'"
      . " && document.querySelector('$favorite_title_row [data-daedalus-layout-add-panel-star]').getAttribute('aria-pressed') === 'true'",
      10000,
      'Both rows of the one face read the star on.',
    );
    $this->assertJsCondition('window.__favoritePosts === 1', 10000, 'The whole map of marks reached the settings route in one POST.');
    $this->drupalGet($blank_blueprint->toUrl());
    $this->assertJsCondition($booted, 20000, 'The canvas booted again after the star.');
    $this->assertTrue(
      (bool) $session->evaluateScript("drupalSettings.daedalusLayout.addPanel.favorites['$title_id'] === true"),
      'The next page delivers the mark.',
    );
    $this->openAddPanel();
    $this->assertNotNull($assert_session->waitForElement('css', $favorite_title_row, 10000), 'Reloaded, the starred block is still under Favorites.');

    // Step 4: one block, two sets. The title block is now under Favorites
    // AND under All's category: place it from its Favorites row by click
    // (the Insert session armed on the root container) and from its All
    // row by the keyboard lift; both land inside the container. The seam:
    // a row keyed by set and face feeding one drag source; the same face
    // twice in the DOM is exactly what the old id-keyed hooks could not
    // have.
    $this->armInsert($root_container);
    $blocks_before = (int) $session->evaluateScript("document.querySelectorAll(\"$root_blocks_css\").length");
    $session->executeScript("document.querySelector('$favorite_title_row').click();");
    $this->assertJsCondition(
      "document.querySelectorAll(\"$root_blocks_css\").length === " . ($blocks_before + 1),
      20000,
      'The click on the Favorites row placed the title block inside the root container.',
    );
    $this->assertJsCondition(
      "document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      'The session stays armed, advanced onto the placed block.',
    );
    $title_row = $this->revealAddPanelRow($title_id);
    $session->executeScript("document.querySelector('$title_row').focus();");
    $this->assertJsCondition(
      "document.activeElement === document.querySelector('$title_row') && document.activeElement.tabIndex === 0",
      10000,
      'The All row took focus and the roving stop.',
    );
    $this->pressKeyOnFocused('Enter');
    $this->assertJsCondition('Drupal.Daedalus.Drag.isLifted() === true', 10000, 'Enter on the row lifted it into the caret walk.');
    $this->pressHotkey('Enter');
    $this->assertJsCondition(
      "document.querySelectorAll(\"$root_blocks_css\").length === " . ($blocks_before + 2) . " && Drupal.Daedalus.Drag.isLifted() === false",
      20000,
      'Enter dropped the lift: the same block landed a second time, from its All row.',
    );

    // Step 5: search. Two characters answer one flat list (no headings,
    // each face once, naming its kind); Down enters it; Enter lifts the
    // row. The seam: the capture-phase keydown and the roving stop across
    // a re-render.
    $search = "document.querySelector('#place_block [data-daedalus-layout-add-panel-search]')";
    $kind_label = (string) $session->evaluateScript("drupalSettings.daedalusLayout.addPanel.faces.find((face) => face.id === '$title_id').kind");
    $session->executeScript("(() => { const box = $search; box.focus(); box.value = 'ti'; box.dispatchEvent(new Event('input', { bubbles: true })); })();");
    $this->assertJsCondition(
      "!document.querySelector('#place_block [data-daedalus-layout-add-panel-set]')"
      . " && document.querySelectorAll('#place_block [data-daedalus-layout-add-panel-in=\"search\"]').length > 0"
      . " && document.querySelectorAll('#place_block [data-daedalus-layout-add-panel-row=\"$title_id\"]').length === 1",
      10000,
      'From two characters the panel answers one flat list: no heading, the title block once though it is in two sets.',
    );
    $this->assertSame(
      $kind_label,
      trim((string) $session->evaluateScript("document.querySelector('#place_block [data-daedalus-layout-add-panel-in=\"search\"][data-daedalus-layout-add-panel-row=\"$title_id\"] [data-daedalus-layout-add-panel-home]').textContent")),
      'A search hit names the kind it lives under.',
    );
    $this->pressKeyOnFocused('ArrowDown');
    $this->assertJsCondition(
      "document.activeElement?.getAttribute('data-daedalus-layout-add-panel-in') === 'search'",
      10000,
      'Down from the box entered the list on its first row.',
    );
    $this->pressKeyOnFocused('Enter');
    $this->assertJsCondition('Drupal.Daedalus.Drag.isLifted() === true', 10000, 'Enter lifted the focused search row.');
    $session->executeScript('Drupal.Daedalus.Drag.cancelLift();');
    $this->assertJsCondition('Drupal.Daedalus.Drag.isLifted() === false', 10000, 'The lift was cancelled, nothing placed.');
    $session->executeScript("(() => { const box = $search; box.value = ''; box.dispatchEvent(new Event('input', { bubbles: true })); })();");
    $this->assertJsCondition("{$expanded('favorites')} === 'true'", 10000, 'The box emptied, the sets are back as they were.');

    // ——— THE PEEK (saved-blueprints landing 4, step 6). ———
    //
    // Back on the page, whose root takes a drop at its end (a block
    // blueprint's canvas with its one root placed refuses one, so there
    // the peek asks nothing). Resting on the Card's row posts the row's
    // own drop batch (place_blueprint at the root's end) to the peek door
    // and frames the answer beside the panel: the Card as it is, its
    // saved text, and its title field block rendering THIS page's title
    // (a field block in a saved blueprint means the host's field). The
    // frame is a bare document: the skin's token scope, the host's style
    // tag, no frame slot door and no Edit Mode. The layer is
    // inert and hidden from AT, the frame never focusable. Leaving the
    // row removes it; the canvas and the history are as they were. The
    // title field block's row peeks the same way, and Escape removes it.
    // The answer is held per row for the page load: a second rest on the
    // Card's row frames the held document at once and asks the door
    // nothing; one mutation through the op queue (the mirror replaced)
    // drops every held answer, so the rest after it asks once more.
    $session->setCookie('navigationMode', NULL);
    $this->enterEditModeViaCookies($node->toUrl());
    $this->assertJsCondition(
      "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()"
      . " && Drupal.Daedalus.Materials.isBooted() && !!Drupal.Daedalus.Materials.get('blueprint', 'card')",
      20000,
      'The page booted again with the Card in the store.',
    );
    $this->installPostCounter();
    $session->executeScript(<<<'JS'
      window.__peekPosts = 0;
      const peekFetch = window.fetch;
      window.fetch = function (url, options) {
        const target = url && url.url ? url.url : String(url);
        const method = (options && options.method) || (url && url.method) || 'GET';
        // The peek carries `?navigationMode=` like every other client, so
        // the door is matched on the path, not the URL's end.
        if (new URL(target, location.origin).pathname.endsWith('/peek') && method === 'POST') {
          window.__peekPosts++;
        }
        return peekFetch.apply(this, arguments);
      };
      JS);
    $this->openAddPanel();
    $card_row = $this->revealAddPanelRow('blueprint:card');
    // The facts a peek must leave as they are. The history mirror is empty
    // after a boot (the sidebar's fill or a mutation response loads it),
    // so the step fills it the sidebar's way first: the mutation below is
    // then one more entry on a counted timeline (no undo has shortened
    // it, and it is well under the recorder's MAX_ENTRIES ring), not the
    // whole timeline arriving at once.
    $session->executeScript('Drupal.Daedalus.History.refresh();');
    $this->assertJsCondition('Drupal.Daedalus.History.getState().loaded === true', 10000, 'The history mirror holds the timeline.');
    $canvas_facts = "JSON.stringify({ containers: Array.from(document.querySelectorAll(\"[data-main-entity] [data-selectable^='container:']\")).map((c) => c.getAttribute('data-selectable')), layout: Drupal.Daedalus.Blueprint.getBlueprint().layout.length, history: Drupal.Daedalus.History.getState().entries.length })";
    $canvas_before = $session->evaluateScript($canvas_facts);
    $peek = "document.querySelector('[data-daedalus-layout-add-panel-peek]')";
    $frame_doc = "document.querySelector('[data-daedalus-layout-add-panel-peek-frame]')?.contentDocument";
    $this->assertNull($session->getPage()->find('css', '[data-daedalus-layout-add-panel-peek]'), 'Nothing peeks before a row is rested on.');
    $this->restOnRow($card_row);
    $this->assertJsCondition(
      "$peek?.getAttribute('data-daedalus-layout-add-panel-peek-for') === 'blueprint:card'",
      10000,
      'Resting on the Card\'s row opened its peek beside the panel.',
    );
    $card_text_json = json_encode($card_text, JSON_THROW_ON_ERROR);
    $page_title_json = json_encode($node->label(), JSON_THROW_ON_ERROR);
    $this->assertJsCondition(
      "(() => { const doc = $frame_doc; return $peek?.getAttribute('data-daedalus-layout-add-panel-peek') === 'ready'"
      . " && !!doc && doc.readyState === 'complete' && !!doc.body"
      . " && doc.body.textContent.includes($card_text_json) && doc.body.textContent.includes($page_title_json); })()",
      20000,
      'The frame holds the Card\'s document: its saved text, and its title field block rendering this page\'s title.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript(
        "(() => { const doc = $frame_doc; const layer = $peek; const frame = document.querySelector('[data-daedalus-layout-add-panel-peek-frame]');"
        . " return !!doc.querySelector('.daedalus-stylish-token-scope') && !!doc.querySelector('style[data-daedalus-stylish-entity]')"
        . " && !doc.querySelector('[data-daedalus-slot]') && !doc.querySelector('#edit-mode-top-bar')"
        . " && !!doc.querySelector('[data-selectable^=\"container:\"] [data-element=\"kind:field\"]')"
        . " && layer.inert === true && layer.getAttribute('aria-hidden') === 'true' && frame.tabIndex === -1 && frame.getAttribute('aria-hidden') === 'true'"
        . " && layer.getBoundingClientRect().left >= document.getElementById('place_block').getBoundingClientRect().right; })()"
      ),
      'The framed document is bare (the skin and the style tag, no frame slot door, no Edit Mode) with the field block inside the placed container, and the layer is inert, hidden from AT and beside the panel.',
    );
    $this->assertJsCondition('window.__peekPosts === 1 && window.__blueprintPosts === 1', 10000, 'One rest, one request to the peek door and nothing else.');
    // Leave: the pointer onto the search box.
    $session->getPage()->find('css', '#place_block [data-daedalus-layout-add-panel-search]')->mouseOver();
    $this->assertJsCondition("$peek === null", 10000, 'Leaving the row removed the frame.');
    $this->assertSame(
      $canvas_before,
      $session->evaluateScript($canvas_facts),
      'The canvas and the history are as they were: the peek persisted nothing.',
    );
    // A field block's row peeks through the same mechanism.
    $title_row = $this->revealAddPanelRow($title_id);
    $this->restOnRow($title_row);
    $this->assertJsCondition(
      "(() => { const doc = $frame_doc; return $peek?.getAttribute('data-daedalus-layout-add-panel-peek-for') === '$title_id'"
      . " && $peek?.getAttribute('data-daedalus-layout-add-panel-peek') === 'ready'"
      . " && !!doc && doc.readyState === 'complete' && doc.body.textContent.includes($page_title_json); })()",
      20000,
      'Resting on the title field block\'s row peeks it: the frame holds this page\'s title.',
    );
    $this->assertJsCondition('window.__peekPosts === 2', 10000, 'The second rest was one more request.');
    $this->pressHotkey('Escape');
    $this->assertJsCondition("$peek === null", 10000, 'Escape removed the frame.');
    $this->assertJsCondition('window.__peekPosts === 2 && window.__blueprintPosts === 2', 10000, 'Two rests, two requests to the peek door, no mutation.');
    // The hit: back on the Card's row, the frame is ready at once with the
    // document the door answered the first time, and the door is not
    // asked. The counter is read after the frame is ready: a request is
    // counted the moment it fires, before any answer, so a count still at
    // two once the frame holds the Card is the held answer, not a race.
    $card_row = $this->revealAddPanelRow('blueprint:card');
    $this->restOnRow($card_row);
    $this->assertJsCondition(
      "(() => { const doc = $frame_doc; return $peek?.getAttribute('data-daedalus-layout-add-panel-peek-for') === 'blueprint:card'"
      . " && $peek?.getAttribute('data-daedalus-layout-add-panel-peek') === 'ready'"
      . " && !!doc && doc.readyState === 'complete' && doc.body.textContent.includes($card_text_json) && doc.body.textContent.includes($page_title_json); })()",
      20000,
      'A second rest on the Card\'s row frames its held document: the saved text and this page\'s title again.',
    );
    $this->assertSame(2, (int) $session->evaluateScript('window.__peekPosts'), 'The second rest on the Card asked the door nothing: the answer was held for the page load.');
    $this->assertSame(2, (int) $session->evaluateScript('window.__blueprintPosts'), 'The hit was no blueprint POST of any kind.');
    $session->getPage()->find('css', '#place_block [data-daedalus-layout-add-panel-search]')->mouseOver();
    $this->assertJsCondition("$peek === null", 10000, 'Leaving the row removed the held frame.');
    // The drop: one mutation through the op queue, the way the page was
    // built (a container appended at the root's end), replaces the mirror
    // and with it every held answer; the next rest on the Card asks the
    // door again. The canvas facts move with the mutation, by exactly the
    // container and the history entry it adds, and the rests after it
    // leave them there.
    $before = json_decode($canvas_before, TRUE, flags: JSON_THROW_ON_ERROR);
    $peek_drop = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'add_section', uuid: '$peek_drop', location: { anchor: Blueprint.getRoot().uuid, placement: 'inside' } },
          ],
        }));
      })();
      JS);
    $this->assertNotNull(
      $assert_session->waitForElement('css', "[data-main-entity] [data-selectable='container:$peek_drop']", 20000),
      'The mutation landed: a container at the root\'s end.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.History.getState().entries.length === " . ($before['history'] + 1),
      10000,
      'The mutation is one more history entry.',
    );
    $this->assertJsCondition('window.__peekPosts === 2 && window.__blueprintPosts === 3', 10000, 'The mutation was one blueprint POST and no peek.');
    $canvas_after = $session->evaluateScript($canvas_facts);
    $after = json_decode($canvas_after, TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame(array_merge($before['containers'], ["container:$peek_drop"]), $after['containers'], 'The containers are as before plus the one appended.');
    $this->assertSame($before['layout'] + 1, $after['layout'], 'The layout grew by the one section.');
    $this->restOnRow($card_row);
    $this->assertJsCondition(
      "(() => { const doc = $frame_doc; return $peek?.getAttribute('data-daedalus-layout-add-panel-peek-for') === 'blueprint:card'"
      . " && $peek?.getAttribute('data-daedalus-layout-add-panel-peek') === 'ready'"
      . " && !!doc && doc.readyState === 'complete' && doc.body.textContent.includes($card_text_json) && doc.body.textContent.includes($page_title_json); })()",
      20000,
      'After the mutation, resting on the Card\'s row frames it again.',
    );
    $this->assertJsCondition('window.__peekPosts === 3', 10000, 'The mutation dropped the held answer: the rest after it asked the door once more.');
    $this->pressHotkey('Escape');
    $this->assertJsCondition("$peek === null", 10000, 'Escape removed the frame again.');
    $this->assertSame($canvas_after, $session->evaluateScript($canvas_facts), 'The canvas and the history are as the mutation left them: the two rests after it persisted nothing.');
    $this->assertJsCondition('window.__peekPosts === 3 && window.__blueprintPosts === 4', 10000, 'Four rests, three requests to the peek door (one a held answer), one mutation, nothing else.');

    // ——— THE MOVE, UNDONE: a block dragged out of C, and back. ———
    //
    // C has been empty for the whole scenario (the drag-contrast legs need
    // it); a text block is placed inside it through the op queue first, the
    // way the page was built. The gesture is the CANVAS drag, not a raw
    // move_element: a plain pointer over a nested block resolves the
    // boundary group around it (A), and pointing at the SELECTION means the
    // selection (resolver.js), so the block is selected first, as a tree-row
    // click or a deep select leaves it; hovering it then arms the main
    // entity wrapper as the one delegated drag handle (selector-drag.js),
    // and a dragstart on the block's root bubbles there and is
    // reinterpreted as the block's move. The drop target is B's interior:
    // an empty top-level container is one 'inside' gap, a plain placement.
    // (A root-flow gap would wrap the block in a new section, gaps.js
    // legalize, a two-op batch whose undo is another seam.) The response
    // swaps both ends; the undo swaps them back in one response, which only
    // a page shows: the panel reads the mirror and was right even when the
    // canvas kept a stale copy in the destination.
    //
    // The real pointer still rests on the Card's row from the last peek; it
    // leaves for the search box first, so no rest can fire a peek POST into
    // the counts below (the peek door is a blueprint POST too).
    $session->getPage()->find('css', '#place_block [data-daedalus-layout-add-panel-search]')->mouseOver();
    $this->assertJsCondition("$peek === null", 10000, 'No frame shows before the move step.');
    $history_before = $after['history'];
    $moved = $uuid_service->generate();
    $block_js = "document.querySelector(\"[data-main-entity] [data-selectable='block:$moved']\")";
    $c_js = "document.querySelector(\"[data-main-entity] [data-selectable='container:$c']\")";
    $b_js = "document.querySelector(\"[data-main-entity] [data-selectable='container:$b']\")";
    $copies_js = "document.querySelectorAll(\"[data-main-entity] [data-selectable='block:$moved']\").length";
    // The uuid of the container a block's node sits under in the mirror's
    // tree (region nodes are transparent grouping), or null when the mirror
    // holds no such block.
    $mirror_parent_js = "((uuid) => { const walk = (nodes, container) => {"
      . " for (const node of (Array.isArray(nodes) ? nodes : [])) {"
      . " if (node.uuid === uuid) { return container; }"
      . " const found = walk(node.children, node.kind === 'container' ? node.uuid : container);"
      . " if (found !== undefined) { return found; } } return undefined; };"
      . " return walk(Drupal.Daedalus.Blueprint.getBlueprint().layout, null) ?? null; })";
    $this->assertTrue(
      (bool) $session->evaluateScript("$c_js.hasAttribute('data-container-empty') && $b_js.hasAttribute('data-container-empty')"),
      'C and B are both empty before the step, each wearing the empty-container stamp.',
    );
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_element',
              uuid: '$moved',
              block_type: 'text',
              properties: { field_text_text: 'The block that moves', field_text_tag: 'p' },
              location: { anchor: '$c', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__moveStepError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__moveStepError !== undefined || $c_js.querySelector(\"[data-selectable='block:$moved']\") !== null",
      20000,
      'The text block placement inside C resolved.',
    );
    $this->assertNull($session->evaluateScript('window.__moveStepError ?? null'), 'The placement inside C raised no error.');
    $this->assertJsCondition('window.__blueprintPosts === 5 && window.__peekPosts === 3', 10000, 'The placement was one blueprint POST and no peek.');
    $this->assertJsCondition(
      "$copies_js === 1 && !$c_js.hasAttribute('data-container-empty') && $b_js.hasAttribute('data-container-empty')",
      20000,
      'The response rendered the block inside C once, C dropped its empty stamp, and B keeps its stamp.',
    );
    $this->assertSame("container:$c", $this->parentContainerKey("block:$moved"), 'The canvas places the block inside C, nested in A.');
    $this->assertSame($c, $session->evaluateScript("$mirror_parent_js('$moved')"), 'The mirror holds the block\'s node under C.');
    $this->assertJsCondition(
      "Drupal.Daedalus.History.getState().entries.length === " . ($history_before + 1),
      10000,
      'The placement is one more history entry.',
    );

    // The gesture: select, hover (the delegate arms), dragstart on the
    // block's root, dragover B's centre, drop.
    $session->executeScript("Drupal.Daedalus.Selection.select('block:$moved');");
    $this->assertJsCondition("Drupal.Daedalus.Selection.getSelected() === 'block:$moved'", 10000, 'The block is selected, so hovering it names it.');
    $this->dispatchHover($block_js);
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getHovered() === 'block:$moved'"
      . " && document.querySelector('[data-main-entity]').getAttribute('draggable') === 'true'",
      20000,
      'Hovering the selected block armed the canvas drag delegate on it: the main entity wrapper is the one draggable handle.',
    );
    $centre_y = 'rect.top + rect.height / 2';
    $started = $this->startDrag($block_js, $b_js, $centre_y);
    $this->assertTrue($started, 'The dragstart on the block\'s root bubbled to the delegate and was NOT cancelled: arrange is open on the page\'s own block, and the gesture is the block\'s move.');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-caret__empty') && !document.querySelector('.daedalus-ui-caret__empty').hidden",
      10000,
      'Over B\'s interior the caret offers the empty-container face: the move source resolved B\'s one inside gap and the controller accepted it (insert open on B, the gap outside the dragged block).',
    );
    $this->finishDrag($block_js, $b_js, $centre_y);
    $this->assertJsCondition('window.__blueprintPosts === 6 && window.__peekPosts === 3', 20000, 'The drop was exactly one blueprint POST (the move_element) and no peek.');
    $this->assertJsCondition(
      "$b_js.querySelector(\"[data-selectable='block:$moved']\") !== null && $copies_js === 1"
      . " && $c_js.hasAttribute('data-container-empty') && !$b_js.hasAttribute('data-container-empty')",
      20000,
      'The response swapped both ends: the block renders inside B and exactly once on the whole canvas, C is empty again wearing the stamp, and B dropped its stamp.',
    );
    $this->assertSame("container:$b", $this->parentContainerKey("block:$moved"), 'The canvas places the moved block inside B, top level.');
    $this->assertSame($b, $session->evaluateScript("$mirror_parent_js('$moved')"), 'The mirror agrees: the block\'s node sits under B.');
    $this->assertJsCondition("Drupal.Daedalus.Selection.getSelected() === 'block:$moved'", 10000, 'The drop landed a plain selection of the moved block.');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-caret') === null",
      10000,
      'The dragend closed the gesture and the caret unmounted.',
    );
    $this->assertJsCondition(
      "(() => { const history = Drupal.Daedalus.History.getState();"
      . " return history.entries.length === " . ($history_before + 2) . " && history.canUndo === true && history.canRedo === false; })()",
      10000,
      'The move is one more entry at the head of the timeline.',
    );

    // Undo by hotkey ('mod' is Ctrl on the Linux runner): one restore POST
    // on the same queue, the response re-rendering BOTH containers.
    $this->pressHotkey('z', ['ctrlKey' => TRUE]);
    $this->assertJsCondition('window.__blueprintPosts === 7 && window.__peekPosts === 3', 20000, 'Undo was exactly one restore POST and no peek.');
    $this->assertJsCondition(
      "$c_js.querySelector(\"[data-selectable='block:$moved']\") !== null && $copies_js === 1"
      . " && !$c_js.hasAttribute('data-container-empty') && $b_js.hasAttribute('data-container-empty')",
      20000,
      'Undo swapped both containers back in one response: the block renders inside C and nowhere else (no stale copy left in B), C dropped its stamp, B wears it again.',
    );
    $this->assertSame("container:$c", $this->parentContainerKey("block:$moved"), 'After undo the canvas places the block inside C again.');
    $this->assertSame($c, $session->evaluateScript("$mirror_parent_js('$moved')"), 'After undo the mirror holds the block\'s node under C again.');
    $this->assertJsCondition(
      "(() => { const history = Drupal.Daedalus.History.getState();"
      . " return history.entries.length === " . ($history_before + 2) . " && history.canRedo === true; })()",
      10000,
      'Undo kept the timeline and opened the redo direction.',
    );

    // Redo: the same path forward, the block in B once more.
    $this->pressHotkey('z', ['ctrlKey' => TRUE, 'shiftKey' => TRUE]);
    $this->assertJsCondition('window.__blueprintPosts === 8 && window.__peekPosts === 3', 20000, 'Redo was exactly one restore POST and no peek.');
    $this->assertJsCondition(
      "$b_js.querySelector(\"[data-selectable='block:$moved']\") !== null && $copies_js === 1"
      . " && $c_js.hasAttribute('data-container-empty') && !$b_js.hasAttribute('data-container-empty')",
      20000,
      'Redo re-applied the move: the block inside B exactly once, C empty and stamped, B unstamped.',
    );
    $this->assertSame("container:$b", $this->parentContainerKey("block:$moved"), 'After redo the canvas places the block inside B again.');
    $this->assertSame($b, $session->evaluateScript("$mirror_parent_js('$moved')"), 'After redo the mirror holds the block\'s node under B again.');
    $this->assertJsCondition(
      'Drupal.Daedalus.History.getState().canRedo === false && Drupal.Daedalus.History.getState().canUndo === true',
      10000,
      'Redo closed the redo direction: the move is the head of the timeline again.',
    );
  }

}
