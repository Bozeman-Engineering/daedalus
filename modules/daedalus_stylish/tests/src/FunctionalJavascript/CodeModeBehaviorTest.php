<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the code-mode island postures (printed/live) on a real page.
 *
 * The capability under test is the printed/live posture model: in code
 * mode every island renders as static printed markup from the shared
 * line-marks model, and exactly one island, the one holding the cursor,
 * is a live CodeMirror EditorView. The marks model itself is node-tested
 * (line-marks.test.mjs); everything here is wiring that only exists where
 * the printed DOM, the CodeMirror view, the stitching registry, and the
 * browser's caret and focus machinery meet:
 * - Wake at the click point (seam: caretPositionFromPoint resolving to a
 *   printed offset that becomes the EditorState selection): a pointerdown
 *   mid-value on a printed line must synchronously replace the print with
 *   exactly one live editor whose selection head equals the clicked
 *   character's offset. The offset mapping crosses the printed text node,
 *   the caret-from-point API, and CodeMirror's state construction; no
 *   lower tier sees all three.
 * - Metric parity on swap (seam: daedalus_stylish's restated metrics in
 *   inspector.css versus CodeMirror's base theme, in a real layout): the
 *   island's box height and its line's rect (measured container-relative,
 *   so reflow above the island cannot fake or mask drift) must be
 *   unchanged by the swap. The parity contract is CSS on two different
 *   DOM shapes; only a browser computes it.
 * - Stitching across postures (seam: the registry's focus handler waking
 *   a printed neighbor): Tab past the last stop of the live island must
 *   wake the NEXT island at its first tab stop and park the previous one
 *   back to print, keeping the one-live-editor invariant while the chain
 *   crosses a posture boundary.
 * - Park on blur (seam: the live view's blur handler behind the
 *   document.hasFocus() gate): a real click on the canvas must destroy
 *   the live editor and re-print it, leaving zero editors. The gate's
 *   other half (a WINDOW blur must NOT park, so Cmd-Tab keeps the
 *   cursor) has no cheap tier in this harness and is recorded as test
 *   debt in the working notes.
 * - The satellite chrome split (seam: library attachment by route): the
 *   leader page in edit mode must not carry css/satellite.css (its
 *   body-level :has() rules were split out of inspector.css precisely so
 *   the leader never pays their style-invalidation cost), while the
 *   pop-out route /daedalus_stylish/inspector-popout carries it and its body
 *   background resolves to --daedalus-background (the satellite
 *   library's daedalus_ui/variables dependency arriving in a window
 *   without the sidebar chrome).
 * - The materials relay (FJS-2; seam: a gesture response -> the op
 *   queue's response path -> the materials store's delta -> the
 *   BroadcastChannel relay -> the satellite's own record set): a variant
 *   minted on the leader page shows up in the satellite's wear bar by
 *   label, with block A wearing it, no fetch and no reload.
 * - A refused satellite gesture (seam: the satellite's apply intent -> the
 *   leader's op queue -> the server's refusal -> the `settled` frame ->
 *   the satellite source's settle -> notify -> the satellite document's
 *   own messenger): the variant deleted behind both windows' backs, the
 *   satellite's stale offer is refused and the error toasts inside the
 *   pop-out's own [data-drupal-messages] wrapper; the leader reports the
 *   same failure in its window, so the shared history holds two entries.
 * - The code scope projection (seam: the scope preference store through
 *   page-model.js scopeNodes() to the rendered rows): with the section
 *   selected, Page prints the entity root above it, Subtree prints the
 *   container's row and its two blocks' rows and nothing above, Element
 *   prints the container's row alone (the satellite leaves first, its
 *   bye returning the in-page panel); a satellite mounted afterwards
 *   reads the same store, its segments and tree at Element with no relay.
 * - The floor line (seam: the canvas tag read through skinDefaultEntry
 *   into islandLines()'s floor and out as a printed line): the selected
 *   container's default island prints guide_example's skin gap in the
 *   floor tint, winning; a typed own gap strikes it with the "this
 *   element" chip; deleting the typed line lifts the override through
 *   islandDelta's UNSET rule and the skin line stands unstruck.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site, as
 * daedalus_editor) switched to the strict reference design system by
 * setUp: the recipe's olivero system ships open, and the scenario's
 * claims about closed spaces need law, so the scenario activates
 * daedalus_stylish_guide_example's guide_example system (strict as shipped, its
 * own skin, its own guide) through
 * DesignSystemActivationTrait::activateSkin() rather than forcing a
 * mode onto a guide written to be open. Two formatted_text blocks are
 * placed through the blueprint op spine and each is given a literal
 * gradient background through the same update_style ops the widgets
 * commit (guide_example binds color and border-color to its color scale
 * but leaves background-image unbound, so a literal gradient is legal
 * under its strict law; GradientBehaviorTest locks the gradient
 * vocabulary separately), so the code-mode page tree holds two styled
 * rows whose islands the scenario walks. One fixture, one scenario: the
 * site install dominates the run cost, so all behavior lives in the
 * single test method.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class CodeModeBehaviorTest extends WebDriverTestBase {

  use DesignSystemActivationTrait;
  use EditModeTrait;
  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * The strict reference design system's package: see the class docblock.
   *
   * @var string[]
   */
  protected static $modules = ['daedalus_stylish_guide_example'];

  /**
   * {@inheritdoc}
   *
   * The scenario picks its law by picking a design system: the recipe's
   * olivero ships open, and the claims below need strict law, so after
   * the fixture install the site switches to guide_example, the strict
   * reference the package above ships (the activation syncs the
   * package's skin and members under its own law, the way a recipe's
   * activation action does). The mode is the system's own, never set
   * by the test.
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
    $this->activateSkin('guide_example', 'guide_example');
  }

  /**
   * The code-mode island scenario: printed by default, one live editor.
   *
   * The scripted scenario, in order:
   * - Fixture: a page node, Edit Mode via the cookie path, one section
   *   with two formatted_text blocks placed through the op spine, and a
   *   distinct literal gradient committed to each block's background
   *   through update_style ops (the map entries are awaited in the
   *   blueprint mirror, the same authority the islands print from).
   * - Entry: the inspector's code segment (a real click) renders the code
   *   body at its default Element scope; the Page segment (a real click)
   *   prints the page tree, both islands print and ZERO editors exist.
   *   Printed-by-default is itself the first assertion of the posture
   *   model.
   * - Wake at the click point: a synthetic pointerdown (real coordinates
   *   from a character Range rect, because printed lines wrap) lands
   *   mid-value on the first island; the wake is synchronous, so the same
   *   script tick already sees one live editor where the print was, and
   *   the caret sits at the clicked character.
   * - Metric parity on swap: box height and container-relative line rect,
   *   captured while printed in that same tick, equal the live editor's.
   * - Stitching across postures: Tab from past the value stop offers the
   *   new-declaration line, and Tab again exits to the second island,
   *   which wakes at its first tab stop while the first island prints
   *   again; still exactly one editor.
   * - Park on blur: a real click on the canvas parks the live editor to
   *   zero editors, both islands printed.
   * - The satellite split: the leader carries inspector.css but never
   *   satellite.css; the pop-out route carries both, and its body
   *   background equals the resolved --daedalus-background.
   * - The materials relay and the refused satellite gesture, as the
   *   class docblock tells them.
   * - The scope projection: the satellite iframe removed (its bye returns
   *   the in-page panel), the section selected, the code segment clicked
   *   again (the relay steps left the shared mode at Basic), the tree
   *   read at Page, Subtree and Element in turn.
   * - The floor line: in Element scope the container's default island
   *   prints the skin gap line tinted, titled and unstruck; woken at the
   *   end of its last line, a typed `gap: var(--space-4);` (a space
   *   token: the strict law binds gap to the scale) commits and strikes
   *   it with the "this element" chip; 21 Backspaces delete the typed
   *   line, the UNSET commits, the skin line stands unstruck; a satellite
   *   mounted again reads the store at Element (its segment pressed, its
   *   one mirrored row); the scope store rests at Element.
   */
  public function testCodeModeIslandScenario(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // The scenario's own commits ride update_style ops under the law of
    // the system setUp activated; assert the posture and the skin so a
    // loosened package (or a fixture left on olivero) cannot silently
    // weaken the claim.
    $this->assertSame(
      'strict',
      \Drupal::service('daedalus_stylish.style_guide')->enforcement(),
      'The activated guide_example design system ships strict enforcement: the scenario runs under the strict reference guide\'s own law.',
    );
    $this->assertSame(
      'guide_example',
      \Drupal::service('daedalus_stylish.skin_manager')->activeSkinName(),
      'The guide_example skin is active: the strict reference system\'s own minted vocabulary is what the islands\' values are judged against.',
    );

    // A real Layout Builder page: the recipe enables styling for the page
    // bundle, so its daedalus_stylish_styles field hosts the style map the islands
    // print from.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Code mode behavior page',
    ]);
    $node->save();

    $this->drupalGet($node->toUrl());

    // A wide window: the editing compound follows the measured stage
    // width, and a squeezed stage would land every write condition-keyed
    // under `narrow` instead of `default`.
    $session->resizeWindow(1920, 1200);

    // The server-rendered cookie path renders the full Edit Mode chrome:
    // the inspector sidebar, every tool's JS, the blueprint mirror boot.
    $this->enterEditModeViaCookies($node->toUrl(), 'selector');
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted.',
    );

    // Place one section holding two formatted_text blocks, B anchored
    // after A so the islands' document order (which the stitching
    // registry sorts by) is deterministic.
    $uuid_service = \Drupal::service('uuid');
    $section_uuid = $uuid_service->generate();
    $block_a_uuid = $uuid_service->generate();
    $block_b_uuid = $uuid_service->generate();
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
              uuid: '$block_a_uuid',
              block_type: 'formatted_text',
              properties: {
                field_formatted_text_text: {
                  value: '<p>Island A canvas text</p>',
                  format: 'daedalus_edit_formatted_text',
                },
              },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
            {
              op: 'add_element',
              uuid: '$block_b_uuid',
              block_type: 'formatted_text',
              properties: {
                field_formatted_text_text: {
                  value: '<p>Island B canvas text</p>',
                  format: 'daedalus_edit_formatted_text',
                },
              },
              location: { anchor: '$block_a_uuid', placement: 'after' },
            },
          ],
        })).catch((error) => {
          window.__placementError = error.message;
        });
      })();
      JS);
    $block_a_key = "block:$block_a_uuid";
    $block_b_key = "block:$block_b_uuid";
    $this->assertJsCondition(
      "window.__placementError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$block_b_uuid')",
      20000,
      'The two-block placement resolved.',
    );
    $placement_error = $session->evaluateScript('window.__placementError ?? null');
    $this->assertNull($placement_error, 'Block placement failed: ' . ($placement_error ?? ''));
    $this->assertNotNull(
      $assert_session->waitForElement('css', "[data-selectable='$block_b_key']"),
      'Both formatted text blocks rendered on the canvas.',
    );

    // Style both blocks through the op spine, exactly the update_style
    // shape the widgets commit: distinct literal gradients, so each
    // island's printed text carries an unmistakable needle to find it by.
    $gradient_a = 'linear-gradient(135deg, #e94560, #16213e)';
    $gradient_b = 'linear-gradient(90deg, #112233, #445566)';
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'update_style', key: '$block_a_key', properties: { 'background-image': '$gradient_a' } },
            { op: 'update_style', key: '$block_b_key', properties: { 'background-image': '$gradient_b' } },
          ],
        })).catch((error) => {
          window.__styleError = error.message;
        });
      })();
      JS);
    $map = "(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles ?? {})";
    $this->assertJsCondition(
      "window.__styleError !== undefined"
      . " || ((" . $map . "[" . json_encode($block_a_key) . "]?.default?.['background-image'] ?? '').includes('135deg')"
      . " && (" . $map . "[" . json_encode($block_b_key) . "]?.default?.['background-image'] ?? '').includes('90deg'))",
      20000,
      'The two update_style commits resolved.',
    );
    $style_error = $session->evaluateScript('window.__styleError ?? null');
    $this->assertNull($style_error, 'The update_style commit failed: ' . ($style_error ?? ''));

    // Open the inspector (the cookie entry can render it either way) and
    // switch to code mode with a real click on the panel-global segment.
    $assert_session->waitForElement('css', '#daedalus_stylish_inspector');
    $session->executeScript(<<<JS
      (() => {
        const panel = document.getElementById('daedalus_stylish_inspector');
        if (panel && panel.classList.contains('daedalus-ui-hidden')) {
          Drupal.Daedalus.SidebarManager.openSidebar('daedalus_stylish_inspector');
        }
      })();
      JS);
    $code_button = $assert_session->waitForElement('css', '.daedalus-stylish-inspector__ui-mode-button--code');
    $this->assertNotNull($code_button, 'The code mode segment rendered in the inspector header.');
    $code_button->click();

    // The code body opens at the Element scope (the store's default),
    // which prints one row at most, and the posture steps below read
    // block A's and block B's islands at once: the tree reads at Page
    // through a real click on the scope segment. The scope step at the
    // end flips through all three and rests the store at Element again.
    $page_segment = $assert_session->waitForElement(
      'xpath',
      '//div[contains(@class, "daedalus-stylish-inspector__scope")]/button[normalize-space(.)="Page"]',
    );
    $this->assertNotNull($page_segment, 'The code body opened with its scope segments.');
    $page_segment->click();

    // The scenario's DOM helpers. Islands are found by their printed (or
    // live) text, because the tree can also hold rows the shipped section
    // variants style; the selection head is read through the DOM
    // selection by summing .cm-line TEXT NODES, because the production
    // bundle exposes no view object and widget chips inflate textContent.
    // The tree rows are read by their header's key, the pressed scope
    // segment by its text, and the floor line by its printed (or live)
    // text prefix, because the same line is a .daedalus-stylish-island__line
    // while printed and a .cm-line while live.
    $session->executeScript(<<<'JS'
      window.__st = {
        island(needle) {
          return Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .daedalus-stylish-island__editor'))
            .find((editor) => (editor.textContent ?? '').includes(needle)) ?? null;
        },
        editors() {
          return document.querySelectorAll('.daedalus-stylish-page-tree .cm-editor').length;
        },
        rows() {
          return Array.from(document.querySelectorAll('.daedalus-stylish-page-tree [data-daedalus-stylish-page-key]'))
            .map((header) => header.dataset.daedalusStylishPageKey);
        },
        scope() {
          return document.querySelector('.daedalus-stylish-inspector__scope button[aria-pressed="true"]')?.textContent.trim() ?? null;
        },
        clickScope(label) {
          Array.from(document.querySelectorAll('.daedalus-stylish-inspector__scope button'))
            .find((button) => button.textContent.trim() === label)
            .click();
        },
        floorLine() {
          const line = Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .daedalus-stylish-island__line, .daedalus-stylish-page-tree .cm-line'))
            .find((candidate) => (candidate.textContent ?? '').startsWith('gap: var(--space-gutter);')) ?? null;
          if (!line) {
            return null;
          }
          return {
            floor: line.classList.contains('daedalus-stylish-island__line--floor'),
            lost: line.classList.contains('daedalus-stylish-island__line--lost'),
            hue: line.style.getPropertyValue('--daedalus-stylish-line-hue').trim(),
            title: line.title,
            chip: line.querySelector('.daedalus-stylish-island__winner')?.textContent.trim() ?? null,
          };
        },
        head() {
          const content = document.querySelector('.daedalus-stylish-page-tree .cm-editor .cm-content');
          const selection = window.getSelection();
          if (!content || !selection.focusNode || !content.contains(selection.focusNode)) {
            return -1;
          }
          let base = 0;
          for (const line of content.querySelectorAll('.cm-line')) {
            let length = 0;
            const walker = document.createTreeWalker(line, NodeFilter.SHOW_TEXT);
            let node;
            while ((node = walker.nextNode())) {
              if (node === selection.focusNode) {
                return base + length + selection.focusOffset;
              }
              length += node.data.length;
            }
            if (line === selection.focusNode || line.contains(selection.focusNode)) {
              let within = 0;
              Array.from(selection.focusNode.childNodes)
                .slice(0, selection.focusOffset)
                .forEach((child) => { within += (child.textContent ?? '').length; });
              return base + Math.min(within, length);
            }
            base += length + 1;
          }
          return -1;
        },
      };
      JS);

    // ——— Printed by default. ———
    // Both styled blocks' islands render as prints; not a single editor
    // exists before any pointer touches the tree. This is the posture
    // model's ground state, asserted before any wake.
    $this->assertJsCondition(
      "window.__st.island('135deg') !== null && window.__st.island('90deg') !== null",
      20000,
      'Both styled blocks print their islands in the code-mode page tree.',
    );
    $this->assertSame(
      0,
      (int) $session->evaluateScript('window.__st.editors()'),
      'No CodeMirror editor exists while every island is printed.',
    );

    // ——— Wake at the click point, capturing printed metrics first. ———
    // One script tick: measure the printed box and line rect
    // (container-relative), compute the click point from a character
    // Range rect two characters into the first hex stop (printed lines
    // wrap, so a line-rect-plus-x-offset point would lie), dispatch the
    // pointerdown, and measure again. The wake is synchronous inside the
    // event, so the post-dispatch reads in the SAME tick already see the
    // live editor; that synchronicity is part of the contract (focus and
    // caret placement cannot wait for a render).
    $wake = json_decode((string) $session->evaluateScript(<<<'JS'
      (() => {
        const island = window.__st.island('135deg');
        island.scrollIntoView({ block: 'center' });
        const printedBox = island.getBoundingClientRect();
        const line = island.querySelector('.daedalus-stylish-island__line[data-line="0"]');
        const printedLine = line.getBoundingClientRect();
        const textNode = line.firstChild;
        const target = textNode.data.indexOf('#e94560') + 2;
        const range = document.createRange();
        range.setStart(textNode, target);
        range.setEnd(textNode, target + 1);
        const glyph = range.getClientRects()[0];
        island.querySelector('.daedalus-stylish-island__print').dispatchEvent(new PointerEvent('pointerdown', {
          bubbles: true,
          cancelable: true,
          button: 0,
          clientX: glyph.left + 0.5,
          clientY: glyph.top + glyph.height / 2,
        }));
        const liveBox = island.getBoundingClientRect();
        const liveLine = island.querySelector('.cm-line');
        const liveLineRect = liveLine ? liveLine.getBoundingClientRect() : null;
        return JSON.stringify({
          expected: target,
          editorsInIsland: island.querySelectorAll('.cm-editor').length,
          editorsTotal: window.__st.editors(),
          printGone: !island.querySelector('.daedalus-stylish-island__print'),
          boxHeightPrinted: printedBox.height,
          boxHeightLive: liveBox.height,
          lineTopPrinted: printedLine.top - printedBox.top,
          lineTopLive: liveLineRect ? liveLineRect.top - liveBox.top : -1,
          lineHeightPrinted: printedLine.height,
          lineHeightLive: liveLineRect ? liveLineRect.height : -1,
        });
      })()
      JS), TRUE);
    $this->assertIsArray($wake, 'The wake step returned its measurements.');
    $this->assertSame(1, $wake['editorsInIsland'], 'The pointerdown on the print woke a live editor inside that island.');
    $this->assertSame(1, $wake['editorsTotal'], 'Exactly one live editor exists in the whole tree after the wake.');
    $this->assertTrue($wake['printGone'], 'The woken island no longer holds its printed markup.');

    // The DOM selection is CodeMirror's own sync of the placed caret;
    // waiting on it (rather than reading in the wake tick) keeps the
    // assertion on the browser-visible fact.
    $this->assertJsCondition(
      'window.__st.head() === ' . (int) $wake['expected'],
      10000,
      'The live selection head sits at the clicked character offset (caret-from-point mapped through the printed text).',
    );

    // ——— Metric parity on swap. ———
    // The restated metrics in inspector.css are the parity contract:
    // the swap must not move a single pixel. Container-relative rects,
    // so sidebar reflow above the island cannot fake or mask drift.
    $this->assertEqualsWithDelta(
      $wake['boxHeightPrinted'],
      $wake['boxHeightLive'],
      0.1,
      'The island box height is unchanged by the wake.',
    );
    $this->assertEqualsWithDelta(
      $wake['lineTopPrinted'],
      $wake['lineTopLive'],
      0.1,
      'The line sits at the same container-relative top in both postures.',
    );
    $this->assertEqualsWithDelta(
      $wake['lineHeightPrinted'],
      $wake['lineHeightLive'],
      0.1,
      'The line rect height is identical in both postures.',
    );

    // ——— Stitching across postures. ———
    // The caret sits mid-value, past the island's last tab stop, so the
    // first Tab offers the new-declaration line (appending it) and the
    // second exits the island: the registry's focus handler must wake the
    // printed neighbor at its FIRST tab stop while the blur of the dying
    // view parks the first island back to print. The keydown rides
    // document.activeElement (CodeMirror's contentDOM), the suite's
    // established synthetic-key seam.
    $tab = "document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', keyCode: 9, bubbles: true, cancelable: true }));";
    $session->executeScript($tab);
    $this->assertSame(
      1,
      (int) $session->evaluateScript("window.__st.island('135deg').querySelectorAll('.cm-editor').length"),
      'The first Tab (the new-declaration offer) stays inside the live island.',
    );
    $session->executeScript($tab);
    $this->assertJsCondition(
      "window.__st.editors() === 1"
      . " && window.__st.island('90deg').querySelectorAll('.cm-editor').length === 1"
      . " && !!window.__st.island('135deg').querySelector('.daedalus-stylish-island__print')",
      10000,
      'The second Tab crossed the posture boundary: the next island is the one live editor and the previous island is printed again.',
    );
    $this->assertJsCondition(
      'window.__st.head() === 0',
      10000,
      'The stitched arrival placed the caret at the neighbor\'s first tab stop.',
    );

    // ——— Park on blur, not on window blur. ———
    // A real click on the canvas moves focus out of the live view; the
    // blur handler commits and, because document.hasFocus() is still
    // true, parks. Zero editors, both islands printed, is the whole
    // posture model at rest. (The gate's window-blur half, where a parked
    // cursor would be a regression, stays manual test debt.)
    $assert_session->elementExists('css', "[data-selectable='$block_a_key']")->click();
    $this->assertJsCondition(
      "window.__st.editors() === 0"
      . " && !!window.__st.island('135deg').querySelector('.daedalus-stylish-island__print')"
      . " && !!window.__st.island('90deg').querySelector('.daedalus-stylish-island__print')",
      10000,
      'The canvas click parked the live editor: zero editors, every island printed.',
    );

    // ——— The satellite chrome split, leader half. ———
    // The leader page in Edit Mode carries the inspector's stylesheet but
    // never the satellite's: the body-level :has() rules were split into
    // css/satellite.css so the leader page never pays their style
    // invalidation cost on every sidebar mutation. (Aggregation is off in
    // the test environment, so the stylesheet links are honest.)
    $this->assertTrue(
      (bool) $session->evaluateScript("!!document.querySelector('link[rel=\"stylesheet\"][href*=\"daedalus_stylish/css/inspector.css\"]')"),
      'The leader page carries the inspector stylesheet (the link-presence control).',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("!!document.querySelector('link[rel=\"stylesheet\"][href*=\"daedalus_stylish/css/satellite.css\"]')"),
      'The leader page does NOT carry the satellite stylesheet: its :has() rules never reach the leader.',
    );

    // ——— The satellite chrome split, pop-out half. ———
    // The pop-out route is the ONE place the satellite chrome attaches;
    // its body background must resolve --daedalus-background through
    // the satellite library's explicit daedalus_ui/variables
    // dependency (the pop-out renders without the sidebar chrome that
    // carries the tokens on the leader). The satellite mounts as a
    // same-origin iframe of the leader page rather than a popup: the
    // pairing rides BroadcastChannel, which spans every browsing context
    // of the origin, and headless Chrome blocks script-opened popups.
    $session->executeScript(<<<'JS'
      (() => {
        const frame = document.createElement('iframe');
        frame.name = 'daedalus_stylish_satellite';
        frame.src = drupalSettings.daedalusStylish.popout.url;
        frame.style.cssText = 'position:fixed;right:0;top:0;width:420px;height:900px;z-index:99999;background:#fff;';
        document.body.append(frame);
      })();
      JS);
    $session->switchToIFrame('daedalus_stylish_satellite');
    $this->assertNotNull(
      $assert_session->waitForElement('css', '.daedalus-stylish-inspector-satellite'),
      'The pop-out route rendered the satellite shell.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript("!!document.querySelector('link[rel=\"stylesheet\"][href*=\"daedalus_stylish/css/satellite.css\"]')"),
      'The pop-out carries the satellite stylesheet.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript("!!document.querySelector('link[rel=\"stylesheet\"][href*=\"daedalus_stylish/css/inspector.css\"]')"),
      'The pop-out carries the inspector stylesheet beside it.',
    );
    $satellite = json_decode((string) $session->evaluateScript(<<<'JS'
      (() => {
        const probe = document.createElement('div');
        probe.style.backgroundColor = 'var(--daedalus-background)';
        document.body.append(probe);
        const resolved = getComputedStyle(probe).backgroundColor;
        probe.remove();
        return JSON.stringify({
          body: getComputedStyle(document.body).backgroundColor,
          resolved,
        });
      })()
      JS), TRUE);
    $this->assertIsArray($satellite, 'The pop-out background probe returned its measurements.');
    $this->assertNotSame(
      'rgba(0, 0, 0, 0)',
      $satellite['resolved'],
      'The pop-out resolves --daedalus-background (the variables dependency arrived).',
    );
    $this->assertSame(
      $satellite['resolved'],
      $satellite['body'],
      'The pop-out body wears --daedalus-background: the satellite chrome applies on its own route.',
    );

    // ----- The materials relay (FJS-2): a leader mint reaches the satellite.
    // Seam: the save_material op (kind variant, the client's own capture
    // as key + styles: exactly what the panel's Save variant submits) ->
    // the op queue's response path -> the materials store's delta -> the
    // BroadcastChannel relay -> the satellite's own record set -> its wear
    // bar. The
    // satellite paired over the hello/snapshot handshake (the banner
    // clears) and mirrors the leader's selection (block A, clicked
    // above); the wear bar is a widget-mode surface, so the shared UI
    // mode flips to Basic here (localStorage-backed: both windows follow).
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-stylish-satellite__banner')",
      20000,
      'The satellite paired with the leader (the connecting banner cleared).',
    );
    $session->executeScript(<<<'JS'
      (() => {
        const basic = Array.from(document.querySelectorAll('.daedalus-stylish-inspector__ui-mode button'))
          .find((button) => button.textContent.trim() === 'Basic');
        basic.click();
      })();
      JS);
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-stylish-wear-bar')",
      20000,
      'The satellite renders the wearing rows for the mirrored selection in Basic mode.',
    );
    // Before the mint: nothing offers or wears the variant-to-be, in any
    // window. The offer is the variant picker the add row opens (the
    // satellite has no hotkey dispatcher; the add row is its one apply
    // gesture); a second press on the add row closes it again.
    $session->executeScript("document.querySelector('.daedalus-stylish-wear-bar__add').click();");
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker [role=\"option\"]')",
      20000,
      'The satellite\'s add row opened the variant picker over its offer.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("Array.from(document.querySelectorAll('.daedalus-stylish-variant-picker__label')).some((label) => label.textContent.trim() === 'Relay smoke')"),
      'The satellite offers no "Relay smoke" variant before the mint.',
    );
    $session->executeScript("document.querySelector('.daedalus-stylish-wear-bar__add').click();");
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker')",
      20000,
      'The second press on the add row closed the satellite\'s picker.',
    );

    // The mint, on the LEADER page: the save_material op (block A's own
    // look, the gradient the islands committed above, as the client's key
    // + styles capture) through the op queue, whose response path lands
    // the minted record; then the save-as re-attach op.
    $session->switchToIFrame(NULL);
    // Select block A through the facade (the canvas click above parked
    // the editor; the mirrored selection must be the wearer-to-be).
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_a_key');");
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'save_material',
            kind: 'variant',
            label: 'Relay smoke',
            key: '$block_a_key',
            styles: { '': { default: { 'background-image': '$gradient_a' } } },
          }],
          label: 'Saved variant Relay smoke',
        }))
          .then((payload) => {
            const minted = (payload.materials?.upsert ?? []).find((record) => record.kind === 'variant');
            if (!minted) {
              throw new Error(JSON.stringify(payload));
            }
            window.__fjs2 = minted.id;
            return Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
              operations: [{ op: 'apply_variant', key: '$block_a_key', variant: minted.id }],
            }));
          })
          .catch((error) => {
            window.__fjs2Error = error.message;
          });
      })();
      JS);
    $this->assertJsCondition(
      "window.__fjs2Error !== undefined || (typeof window.__fjs2 === 'string' && Drupal.Daedalus.Materials.has('variant', window.__fjs2))",
      20000,
      'The leader minted the variant and its store learned the record.',
    );
    $this->assertNull($session->evaluateScript('window.__fjs2Error ?? null'), 'The mint failed: ' . ($session->evaluateScript('window.__fjs2Error ?? null') ?? ''));
    $variant_id = (string) $session->evaluateScript('window.__fjs2');
    $this->assertSame('relay_smoke', $variant_id, 'The variant minted under its derived machine name.');
    $this->assertJsCondition(
      "JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles?.[" . json_encode($block_a_key) . "]?.variants ?? []).includes('relay_smoke')",
      20000,
      'Block A wears the new variant on the leader.',
    );

    // The satellite: its own record set learned the mint over the wire
    // (the worn row's swap trigger reads the LABEL, never a stale
    // reference row) and the mirrored map shows block A wearing it.
    $session->switchToIFrame('daedalus_stylish_satellite');
    $probe = <<<'JS'
      (() => {
        const rows = Array.from(document.querySelectorAll('.daedalus-stylish-wear-bar__row'));
        const worn = rows.map((row) => [
          row.querySelector('.daedalus-stylish-wear-bar__label')?.textContent.trim() ?? null,
          row.querySelector('.daedalus-stylish-wear-bar__variant-label')?.textContent.trim() ?? null,
          row.classList.contains('daedalus-stylish-wear-bar__row--stale'),
        ]);
        return JSON.stringify({
          ok: worn.some(([, label, stale]) => label === 'Relay smoke' && !stale),
          rows: worn,
          wearBar: document.querySelector('.daedalus-stylish-wear-bar')?.textContent ?? null,
          banner: !!document.querySelector('.daedalus-stylish-satellite__banner'),
        });
      })()
      JS;
    $relayed = NULL;
    for ($attempt = 0; $attempt < 40; $attempt++) {
      $relayed = json_decode((string) $session->evaluateScript($probe), TRUE);
      if (!empty($relayed['ok'])) {
        break;
      }
      usleep(500000);
    }
    $this->assertTrue(
      (bool) ($relayed['ok'] ?? FALSE),
      'The satellite\'s wear bar lists the leader\'s new variant by label (the materials delta relayed over the BroadcastChannel). Satellite state: ' . json_encode($relayed),
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("!!document.querySelector('.daedalus-stylish-wear-bar__row--stale')"),
      'No stale row: the satellite resolved the wearing through the relayed record.',
    );
    $session->switchToIFrame(NULL);

    // ——— A refused satellite gesture toasts in the satellite's own window. ———
    // Seam: the satellite's apply intent -> the leader's op queue -> the
    // server's refusal -> the leader's `settled` frame (ok: false) -> the
    // satellite source's settle -> notify -> the satellite document's own
    // messenger. The satellite hosts daedalus_ui/messenger itself, so
    // the toast lands where the hand is, in the pop-out's own
    // [data-drupal-messages] wrapper, never relayed to the leader to be
    // shown over there; the leader reports the same failure in ITS window
    // (it owns the queue and flashes the element), so the shared history
    // holds two entries: the honest count of two reports. The refusal is
    // real and server-side: the variant is deleted behind both windows'
    // backs (another editor's delete, say), so the satellite still offers
    // it and the server is the law.
    $variant_storage = \Drupal::entityTypeManager()->getStorage('daedalus_variant');
    $variant_storage->resetCache();
    $variant_storage->delete($variant_storage->loadByProperties(['machine_name' => 'relay_smoke']));
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_b_key');");
    $session->switchToIFrame('daedalus_stylish_satellite');
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-stylish-wear-bar')"
      . " && !Array.from(document.querySelectorAll('.daedalus-stylish-wear-bar__variant-label')).some((label) => label.textContent.trim() === 'Relay smoke')",
      20000,
      'The satellite mirrors the leader\'s new selection (block B, wearing nothing).',
    );
    $session->executeScript("document.querySelector('.daedalus-stylish-wear-bar__add').click();");
    $relay_option = "Array.from(document.querySelectorAll('.daedalus-ui-popover .daedalus-stylish-variant-picker [role=\"option\"]'))"
      . ".find((option) => option.querySelector('.daedalus-stylish-variant-picker__label')?.textContent.trim() === 'Relay smoke')";
    $this->assertJsCondition(
      "!!$relay_option",
      20000,
      'The satellite still offers "Relay smoke" (its record set has not heard of the delete): the stale offer the server will refuse.',
    );
    $session->executeScript("$relay_option.click();");
    $not_found = '"relay_smoke" is not a saved style variant.';
    $error_toasts = "[...document.querySelectorAll('[data-drupal-messages].daedalus-ui-messenger [data-drupal-message-id]')]"
      . ".filter((message) => message.getAttribute('data-drupal-message-type') === 'error')";
    $this->assertJsCondition(
      "$error_toasts.some((message) => message.textContent.includes(" . json_encode($not_found) . "))",
      20000,
      'The refused apply toasted inside the satellite window\'s own [data-drupal-messages] wrapper (settled frame → satellite notify → local messenger).',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript(
        "!!document.querySelector('.daedalus-stylish-inspector__variant-message, .daedalus-stylish-wear-bar [class*=\"error\"]')"
      ),
      'No inline strip renders in the satellite beside the toast.',
    );
    $session->switchToIFrame(NULL);
    $this->assertJsCondition(
      "$error_toasts.some((message) => message.textContent.includes(" . json_encode($not_found) . "))",
      20000,
      'The leader reported the same refusal in its own window (it owns the queue): each window reports once.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.messenger.history().filter((entry) => entry.type === 'error' && entry.message.includes(" . json_encode($not_found) . ")).length === 2",
      20000,
      'The shared history holds two entries for the one refusal: the leader\'s report and the satellite\'s, by design.',
    );

    // ——— The code scope projection. ———
    // Seam: the scope preference store (code-scope.js, localStorage-backed
    // and storage-synced exactly like the UI mode) through page-model.js
    // scopeNodes() to the rendered rows. The section placed above is the
    // anchor: a container wearing its stamped band variants, holding the
    // two styled blocks. With it selected, Page prints the entity root
    // above it (the whole filtered page), Subtree prints the container's
    // row and its two blocks' rows and nothing above, and Element prints
    // the container's row alone. The satellite and the in-page panel are
    // alternatives, not layers (the leader closed its sidebar when the
    // satellite paired), so the satellite leaves first: removing its
    // iframe fires its pagehide, which posts bye, and the leader reopens
    // the panel it displaced. The shared UI mode is Basic here (the
    // satellite flipped it above), so the returned panel's code segment
    // takes a real click; the store still reads Page from the entry
    // click.
    $container_key = "container:$section_uuid";
    $entity_key = 'entity:' . $node->uuid();
    $session->executeScript("document.querySelector('iframe[name=\"daedalus_stylish_satellite\"]').remove();");
    $code_button = $assert_session->waitForElement('css', '.daedalus-stylish-inspector__ui-mode-button--code');
    $this->assertNotNull($code_button, 'The satellite\'s leave (its pagehide posts bye) returned the in-page panel the leader displaced, with its mode segments.');
    $session->executeScript("Drupal.Daedalus.Selection.select('$container_key');");
    $code_button->click();
    $this->assertJsCondition(
      "window.__st.scope() === 'Page'"
      . " && window.__st.rows().includes(" . json_encode($entity_key) . ")"
      . " && window.__st.rows().includes(" . json_encode($container_key) . ")"
      . " && document.querySelector('.daedalus-stylish-page-tree__header--selected')?.dataset.daedalusStylishPageKey === " . json_encode($container_key),
      20000,
      'Page scope: the tree prints the entity root above the selected container (the whole filtered page), the container\'s row selected.',
    );
    $session->executeScript("window.__st.clickScope('Subtree');");
    $subtree_rows = json_encode([$container_key, $block_a_key, $block_b_key]);
    $this->assertJsCondition(
      "window.__st.scope() === 'Subtree'"
      . " && JSON.stringify(window.__st.rows()) === " . json_encode($subtree_rows),
      20000,
      'Subtree scope: the tree prints exactly the selected container\'s row and its two blocks\' rows, in layout order, nothing above (the entity root is gone).',
    );
    $this->assertSame(
      'subtree',
      $session->evaluateScript("localStorage.getItem('daedalusStylishCodeScope')"),
      'The Subtree click wrote through to the scope store (localStorage daedalusStylishCodeScope), the preference the satellite reads.',
    );
    $session->executeScript("window.__st.clickScope('Element');");
    $this->assertJsCondition(
      "window.__st.scope() === 'Element'"
      . " && JSON.stringify(window.__st.rows()) === " . json_encode(json_encode([$container_key])),
      20000,
      'Element scope: the tree prints the selected container\'s row alone.',
    );

    // ——— The floor line. ———
    // Seam: the canvas tag read (source.readCanvasTag over the container's
    // rendered element) through skinDefaultEntry into islandLines()'s
    // floor and out as a printed line. guide_example's skin declares
    // `kind:container` gap: var(--space-gutter) (an in-fixture skin line;
    // the parent topic's basic-mode step had to pose the canvas for one),
    // and the stamped band variants set no gap, so the selected container's
    // default island prints the skin's gap line in the floor tint
    // (SKIN_COLOR, layers.js), titled by its tier, winning. Typing an own
    // gap into the same island strikes it with the "this element" chip
    // (the map's own layer beats the floor), and deleting the typed line
    // again lifts the override through islandDelta's UNSET rule: the skin
    // line stands unstruck and the map never held the skin's value.
    $this->assertJsCondition(
      "(() => { const line = window.__st.floorLine(); return !!line && line.floor && !line.lost && line.chip === null; })()",
      20000,
      'The selected container\'s default island prints guide_example\'s skin gap line (gap: var(--space-gutter)) in the floor tint, unstruck, no winner chip: the skin wins.',
    );
    $floor = json_decode((string) $session->evaluateScript('JSON.stringify(window.__st.floorLine())'), TRUE);
    $this->assertSame(
      'hsl(172 66% 50%)',
      $floor['hue'],
      'The skin line carries --daedalus-stylish-line-hue in the floor\'s hue (SKIN_COLOR).',
    );
    $this->assertSame(
      'Supplied by the active skin.',
      $floor['title'],
      'The skin line\'s hover title names its tier.',
    );

    // Wake the island at the end of its last printed line (the click
    // point from the last glyph's rect, as the wake step above), so the
    // typed declaration lands on a new line of its own.
    $floor_wake = json_decode((string) $session->evaluateScript(<<<'JS'
      (() => {
        const island = window.__st.island('gap: var(--space-gutter);');
        island.scrollIntoView({ block: 'center' });
        const lines = island.querySelectorAll('.daedalus-stylish-island__line');
        const last = lines[lines.length - 1];
        const textNode = last.firstChild;
        const range = document.createRange();
        range.setStart(textNode, textNode.data.length - 1);
        range.setEnd(textNode, textNode.data.length);
        const glyph = range.getClientRects()[0];
        island.querySelector('.daedalus-stylish-island__print').dispatchEvent(new PointerEvent('pointerdown', {
          bubbles: true,
          cancelable: true,
          button: 0,
          clientX: glyph.right - 0.5,
          clientY: glyph.top + glyph.height / 2,
        }));
        const expected = Array.from(lines).reduce((length, line) => length + line.firstChild.data.length, 0) + lines.length - 1;
        return JSON.stringify({
          expected,
          editorsTotal: window.__st.editors(),
        });
      })()
      JS), TRUE);
    $this->assertIsArray($floor_wake, 'The floor wake step returned its measurements.');
    $this->assertSame(1, $floor_wake['editorsTotal'], 'The pointerdown at the end of the container island\'s last line woke it as the one live editor.');
    $this->assertJsCondition(
      'window.__st.head() === ' . (int) $floor_wake['expected'],
      10000,
      'The caret sits at the end of the container island\'s document, after its last line.',
    );

    // The own gap, typed as a user types it: a space token, because the
    // strict law binds gap to the space scale (a literal other than 0 is
    // refused at the validator and never reaches the map); the semicolon
    // is the settle boundary, so the SET commits at once through the op
    // queue and the authoritative refresh re-marks the live island.
    $container_default = "((" . $map . ")[" . json_encode($container_key) . "]?.default ?? {})";
    $session->executeScript("document.execCommand('insertText', false, '\\ngap: var(--space-4);');");
    $this->assertJsCondition(
      $container_default . "['gap'] === 'var(--space-4)'"
      . " && (() => { const line = window.__st.floorLine(); return !!line && line.floor && line.lost && line.chip === 'this element'; })()",
      20000,
      'Typing gap: var(--space-4) into the container island commits the own layer (the map holds it) and strikes the skin line with the "this element" chip: the own layer beats the floor.',
    );

    // Delete the typed line the way a user does: Backspace over its 20
    // characters and the newline before it (CodeMirror's own keymap, the
    // suite's synthetic-key seam). The buffer's gap mentions shrink to
    // exactly the floor's text beneath, which islandDelta reads as an
    // UNSET; the settle timer commits it and the refresh reprints the
    // skin line unstruck.
    $session->executeScript(<<<'JS'
      (() => {
        for (let i = 0; i < 21; i++) {
          document.activeElement.dispatchEvent(new KeyboardEvent('keydown', {
            key: 'Backspace',
            keyCode: 8,
            bubbles: true,
            cancelable: true,
          }));
        }
      })();
      JS);
    $this->assertJsCondition(
      "!('gap' in " . $container_default . ")"
      . " && (() => { const line = window.__st.floorLine(); return !!line && line.floor && !line.lost && line.chip === null; })()"
      . " && !Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .cm-line')).some((line) => line.textContent.startsWith('gap: var(--space-4)'))",
      20000,
      'Deleting the typed line lifts the override (islandDelta\'s UNSET): the map holds no gap for the container and the skin line stands unstruck, no chip, still supplied by the floor.',
    );

    // A satellite mounted now reads the same store, with nothing relayed
    // but the selection it mirrors: its pressed segment and its tree (the
    // mirrored container, one row) say Element too. The mount is the one
    // above (the same-origin iframe); its hello closes the leader's panel
    // again, which nothing below needs.
    $session->executeScript(<<<'JS'
      (() => {
        const frame = document.createElement('iframe');
        frame.name = 'daedalus_stylish_satellite';
        frame.src = drupalSettings.daedalusStylish.popout.url;
        frame.style.cssText = 'position:fixed;right:0;top:0;width:420px;height:900px;z-index:99999;background:#fff;';
        document.body.append(frame);
      })();
      JS);
    $session->switchToIFrame('daedalus_stylish_satellite');
    $this->assertJsCondition(
      "localStorage.getItem('daedalusStylishCodeScope') === 'element'"
      . " && document.querySelector('.daedalus-stylish-inspector__scope button[aria-pressed=\"true\"]')?.textContent.trim() === 'Element'"
      . " && JSON.stringify(Array.from(document.querySelectorAll('.daedalus-stylish-page-tree [data-daedalus-stylish-page-key]')).map((header) => header.dataset.daedalusStylishPageKey)) === " . json_encode(json_encode([$container_key])),
      20000,
      'A satellite mounted after the scope clicks reads the same scope store: its Element segment is pressed and its tree prints the mirrored container\'s row alone, no relay involved.',
    );
    $session->switchToIFrame(NULL);

    // The scope rests at Element, the store's default, where the scope
    // step drove it: the scenario leaves the preference as it found it.
    $this->assertSame(
      'element',
      $session->evaluateScript("localStorage.getItem('daedalusStylishCodeScope')"),
      'The scope store rests at Element (its default) at the end of the scenario.',
    );
  }

}
