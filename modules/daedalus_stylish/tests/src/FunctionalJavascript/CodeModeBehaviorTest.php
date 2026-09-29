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
 * - The satellite chrome split (seam: library attachment by route, and the
 *   chromeless document at daedalus_ui): the leader page in edit mode
 *   carries the page chrome but neither css/satellite.css nor daedalus_ui's
 *   css/popout.css (the pop-out document's body-anchored :has() never
 *   reaches the leader), while the pop-out route
 *   /daedalus_stylish/inspector-popout carries both, no navigation control
 *   bar, no right sidebar and one "Styles" heading, its body wears
 *   --daedalus-background and the inspector root's computed color is the
 *   resolved --daedalus-text (the fact only a browser sees: the theme's
 *   wrappers between the body and the mount inherit the chrome instead of
 *   re-painting it).
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
 *   page-model.js scopeNodes() to the rendered rows, every element of
 *   the scoped tree a row, styled or not): with the section selected,
 *   Page prints the entity root above it (the whole page), Subtree
 *   prints the container's row and ALL its blocks' rows and nothing
 *   above (an unstyled block's row bare, the bare-rows leg below),
 *   Element prints the container's row alone (the satellite leaves
 *   first, its bye returning the in-page panel); a satellite mounted
 *   afterwards reads the same store, its showing row and tree at Element
 *   with no relay.
 * - The selector rows (seam: the code view's selector gesture, from the
 *   typed selector through element-model.js selectorGestureOptions over
 *   the blueprint tree's matcher, the CodeMirror completion pick or the
 *   swallowed `{`, the drafted row and its auto-focused island, the
 *   update_style commit at the selector key, CssPrinter's @scope print,
 *   to the browser's cascade on three real blocks): with three text
 *   blocks placed before the formatted ones, typing `> pb-text` on the
 *   container's island offers `> *` and `> pb-text` labelled by the
 *   bundle, picking mints the row (label "Text blocks", the canonical
 *   selector in its code face with a `>` chip) with the one live editor
 *   inside it, and `color: var(--color-accent);` lands on all three text
 *   blocks' computed color and on nothing else; one block's own color
 *   then wins by @scope proximity; `p {` typed on the container island
 *   mints the `p` row without a pick (the brace swallowed), and its
 *   color reaches the paragraph inside the formatted text block (doctrine
 *   B: a tag rule crosses a child's root) while the text blocks keep the
 *   heavier `> pb-text` rule; a `first-child` fact row minted on the
 *   `> pb-text` row through the `&` gesture uppercases the first text
 *   block alone (the fact prints bare, no force-state dual). Four layers
 *   agree only in a browser: the completion DOM, the printed @scope
 *   rule, the element stamps, and the cascade's specificity and
 *   proximity order.
 * - The selector relations (seam: the code view's swallowed brace
 *   through element-model.js bracedSelector and the recursive grammar,
 *   the update_style commit at a relational key, CssPrinter's
 *   per-compound :where() print, the browser's cascade over four
 *   ordered siblings, the matcher's sibling walk into the selected
 *   block's stack, and originOf's ancestor sentence on the Advanced
 *   mark): on a fresh band of p, p and h3 text roots (the second
 *   linked) with an image block last, `> p + p {` typed on the band's
 *   island mints the row (a chip per combinator) and its margin token
 *   lands on the second paragraph alone; `> pb-text:has(a) {` colors
 *   the linked block alone; `> *:not(pb-image) {` sizes the three text
 *   blocks and not the image; a `not-last-child` fact row on the `> *`
 *   row through the `&` gesture reaches the first three children and
 *   not the last; the second paragraph's Advanced Margin top row then
 *   reads the sibling rule with its mark titled by the section and the
 *   `> p + p` literal.
 * - The floor lines (seam: the canvas tag read into the selected
 *   element's style stack, buildStack's floor layers per declaring
 *   scope, through stackLines() out as printed lines titled by tier
 *   AND scope): the selected container's default island prints
 *   guide_example's skin gap as a delivered line, winning, titled with
 *   its `kind:container` scope, above the container kind declaration's
 *   `gap: 0px` line struck by the skin's chip; a typed own gap strikes
 *   the skin line with the "this element" chip; deleting the typed
 *   line lifts the override through islandDelta's UNSET rule and the
 *   skin line stands unstruck.
 * - A rule's worn variant on the Page lens (seam: ONE apply_variant at
 *   the band's `pb-text` rule through the op spine, the rule-wear lane
 *   delivering the variant's link fragment by descent inside the band's
 *   @scope, and the code view's roster reading the stack's enumeration,
 *   elementIslands over selectorSuffixes, so a position no map entry
 *   holds rows because a variant delivers there): guide_example's
 *   Primary (block_type:text, its only fragment `::.text-block-link`)
 *   worn at `container::pb-text` colors the linked paragraph's anchor;
 *   with nothing selected the Page lens lists the band's row with the
 *   continued position `pb-text .text-block-link` as a part row whose
 *   every line is delivered in Primary's hue, titled
 *   'Supplied by the variant "Primary".', printed, no editor live.
 * - A generated box typed on the root island, born with its seed (seam:
 *   the `&::before {` gesture on the element's own island,
 *   element-model.js bracedSelector through the completion machinery,
 *   the drafted box row with its empty island live and focused, the
 *   first declaration's commit at the box key, the op spine's birth
 *   seed `content: ""` landing in the same op, the authoritative
 *   refresh splicing that server-born own line into the LIVE buffer
 *   above the typed text (island.js unseenOwnLines) so the park's
 *   settle diffs an honest buffer, and the box computing on the band):
 *   on the band at Element scope the gesture mints the "Before" row
 *   (the anchor's code face, no chip, nothing in the map), a typed
 *   accent background lands with the seed beneath it, the live island
 *   reads `content: "";` first, the band's ::before computes the
 *   accent, and the wake of the root island parks the box island to a
 *   two-line print with the seed still in the map (before the splice
 *   the missing line read as a deletion and the box vanished on park).
 *   The system is opened server-side first: guide_example declares no
 *   `pseudo:` section, the offer is universal, the grant the server's.
 * - The paste door (seam: the ClipboardEvent on the live island's
 *   content -> paste-door.js structuralChunks refusing the insertion ->
 *   the source's parseCss POST to the parse-css route, the one parser
 *   under the island's key -> the answered entries as ONE update_style
 *   batch through commitBatch, one labelled history entry -> the
 *   refresh materializing the `&:hover` condition row with its printed
 *   island beside the live root): a pasted bare declaration plus a
 *   nested state block lands at the band's default and hover
 *   conditions, the chunk never stays as text. Four layers only a
 *   browser joins: the clipboard event, the fetch, the op queue, the
 *   render.
 * - Copy CSS (seam: the header gesture -> the source's readCss GET of
 *   the read route at mode=css -> the promise-backed ClipboardItem ->
 *   the clipboard write inside the gesture -> the "CSS copied" flash):
 *   with navigator.clipboard.write stubbed by injected script and the
 *   fetch real, the band's print reaches the stub as flat dialect rules
 *   under `#<uuid>`, its own entry and its rules among them.
 * - A run label's doorway (seam: the run widget's contextmenu bubbling
 *   out of the print to the island's handler -> the shared menu headed
 *   by the run's word -> the Inspector's openDocument -> the session
 *   frame over the variant's document): a variant minted from block B's
 *   own look and worn by it prints its run label on block B's island;
 *   right-clicking it opens the menu headed "Door smoke" with "Edit
 *   Door smoke", and the pick opens the session frame labelled by the
 *   variant over its document (the root slot's header, the variant's
 *   line printed), parked again through the frame's back control. The
 *   run menu offers no ancestor-rule seat: the tier never prints on an
 *   island (own islands drop it, ownIslandLines; a rule prints as a
 *   rule island whose header is its seat), so no leg drives one.
 * - A rule-carried variant's run (seam: the run label on a CONTINUED
 *   position -> line-menu.js runMenuItems reading the carrier off the
 *   position's stack layer record, the rule style-stack.js mapLayers
 *   names as the carrier of what it delivers -> openDocument anchored
 *   at the rule's key -> seatAnchor's rule road seating the rule as
 *   the target so it becomes the wear key -> the frame mounting over
 *   the rule's wearing): Primary, worn at the band's `pb-text` rule and
 *   printed on the `pb-text .text-block-link` part row, opens the menu
 *   headed "Primary" from its run label; "Edit Primary" seats the rule
 *   (the chip at container::pb-text, the selection still the band) and
 *   mounts the session frame labelled "Primary" with the built-in's
 *   read-only notice, parked again through the back control. The
 *   position itself wears nothing, so an anchor at it mounts no frame:
 *   the doorway anchors where the wear is.
 * - The satellite's row words (seam: style-guide.js layoutTree carrying
 *   the naming spine's `name` into the pared tree the pop-out reads,
 *   page-model.js rowWords over it, no relay): the first section
 *   renamed "Welcome" through update_element, a satellite mounted
 *   afterwards prints its row as "Section" + "Welcome" cold, and the
 *   leader's row reads the same.
 * - The bare rows (seam: page-model.js visibleTree's `styled` annotation
 *   through page-view.js to the rendered bare face, hasBody deciding the
 *   chevron): at Subtree over the band every child is a row, the three
 *   unstyled text blocks bare (the row's words alone, the chevron's
 *   inert spacer in its place, no island and no editor beneath), the
 *   band's row and the image block's (born wearing guide_image_rounded:
 *   a worn reference is styling) not; selecting a bare block mounts its
 *   real islands (printed, still no editor until a wake).
 * - The reveal (seam: the one reveal rule, daedalus_ui's
 *   Drupal.Daedalus.scrollReveal, through the browser's scrollports and
 *   the canvas's scroll-margin, a fact only a browser computes): with
 *   the window shortened so the code view overflows and both panels
 *   scrolled to their top, selecting the LAST styled element (the band)
 *   leaves its row's rect fully inside the code view's scroller (it sat
 *   below the fold before) and the page elements panel's row fully
 *   inside its scroller; the window scrolled to its top again, a click on the code
 *   view's row leaves the band's canvas element in view with its top
 *   edge below the fixed top bar (the code view never revealed the
 *   canvas before the rule).
 * - The pop-out keyboard wire (seam: a keydown in the pop-out document
 *   through the keyboard sensor the pop-out runs, its synchronous swallow
 *   from the claim set the leader published on hello, the stroke over the
 *   daedalus_ui:<scope> channel, the leader's dispatch and action, the
 *   state broadcast back to the satellite's rows; a popped-out panel is
 *   the same app in another window, and only a browser joins two
 *   documents, two channels and a preventDefault that lands before the
 *   leader's verdict): a shadow level committed on block B, a satellite
 *   mounted afterwards showing the level chip selected in its Shadow row,
 *   mod+z pressed on that focused chip is swallowed in the pop-out and
 *   undone on the leader (the map entry loses box-shadow, the history can
 *   redo) and the satellite's chip unselects; shift+v pressed on the
 *   satellite's add button is swallowed there, opens the satellite's own
 *   variant picker, and the leader's displaced sidebar stays closed with
 *   no picker of its own.
 * - The collapse controls over the popped-out outline (seam: daedalus_ui's
 *   one collapse model under code mode's page view, the shared control
 *   pair in the showing row, and the pop-out intent road: the pair's
 *   hotkeys registered on the leader, a press in the satellite document
 *   swallowed from the relayed claim set and resolved on the leader, whose
 *   outline is displaced, so the gesture routes back over stylish's
 *   protocol and lands on the satellite's own posture; only a browser
 *   joins two documents, the keyboard wire and the intent channel): the
 *   pair bound to 5 and 6 through the registry, 5 pressed in the
 *   satellite collapsing its outline to the Page row with the pair
 *   flipped, the chevrons printing the section and block A collapsed to
 *   its summary count, and a selection from the leader revealing the
 *   band's text block's chain in the satellite while block A stays
 *   collapsed.
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
   * - The satellite split: the leader carries inspector.css and the page
   *   chrome but neither satellite.css nor popout.css; the pop-out route
   *   carries all three sheets, no chrome, one "Styles" heading, its body
   *   background equals the resolved --daedalus-background and the
   *   inspector root's color the resolved --daedalus-text.
   * - The materials relay and the refused satellite gesture, as the
   *   class docblock tells them.
   * - The scope projection: the satellite iframe removed (its bye returns
   *   the in-page panel), the section selected, the code segment clicked
   *   again (the relay steps left the shared mode at Basic), the tree
   *   read at Page, Subtree and Element in turn.
   * - The floor line: in Element scope the container's default island
   *   prints the skin gap line delivered, titled and unstruck; woken at the
   *   end of its last line, a typed `gap: var(--space-4);` (a space
   *   token: the strict law binds gap to the scale) commits and strikes
   *   it with the "this element" chip; 21 Backspaces delete the typed
   *   line, the UNSET commits, the skin line stands unstruck; a satellite
   *   mounted again reads the store at Element (its segment pressed, its
   *   one mirrored row); the scope store rests at Element.
   * - The relations: a second section (p, p and h3 text roots, the
   *   second linked, an image block last) placed through the op spine;
   *   on its island `> p + p {`, `> pb-text:has(a) {`,
   *   `> *:not(pb-image) {` and `> * {` each mint by the swallowed brace,
   *   their declarations commit at the relational keys and land on
   *   exactly the children each relation names; `&not-last-child` on the
   *   `> *` row mints the condition row whose value reaches every child but
   *   the last; the second paragraph selected in Advanced mode reads the
   *   sibling rule's margin token on its Margin top row and mark.
   * - The worn rule: ONE apply_variant of Primary at the band's `pb-text`
   *   rule through the op spine lands the reference on the rule's entry
   *   and no entry at `pb-text .text-block-link`; the linked paragraph's
   *   anchor computes the accent background; the selection cleared, the
   *   code segment clicked and the Page scope picked, the band's row
   *   lists the continued position as a part row (its code face the
   *   canonical selector) whose lines are all delivered in
   *   Primary's hue titled by the variant, the accent background and
   *   contrast color among them in declaration order, no editor live.
   * - The seams appended after the box leg, in order: the paste door
   *   on the band's live root island (a bare declaration and a nested
   *   `&:hover` block, one history entry "Pasted 2 declarations", the
   *   hover row materialized); Copy CSS through a stubbed clipboard
   *   write and the real route; a variant minted from block B's look
   *   and worn by it, its run label right-clicked, "Edit Door smoke"
   *   opening the session frame over the document and the back control
   *   parking it; the first section renamed
   *   "Welcome" and a satellite mounted afterwards reading "Section"
   *   + "Welcome" on its row, the iframe removed again; the band
   *   selected and Primary's run label on its continued position
   *   right-clicked, "Edit Primary" seating the `pb-text` rule as the
   *   target and mounting the frame labelled "Primary" with the
   *   built-in's read-only notice, the back control parking it; the
   *   bare rows and the reveal; last, the pop-out keyboard wire: a
   *   shadow level committed on block B, the satellite mounted again in
   *   Basic mode, mod+z on its focused Shadow chip undoing the commit on
   *   the leader with the chip following, shift+v on its add button
   *   opening the satellite's picker while the leader's sidebar stays
   *   closed; last, the collapse controls over the popped-out outline:
   *   the pair bound to 5 and 6, 5 pressed in the satellite collapsing
   *   its outline to the Page row, the chevrons printing block A's
   *   summary count, and a leader selection revealing the band's text
   *   block's chain there with block A still collapsed.
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
    $this->enterEditModeViaCookies($node->toUrl());
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
    // through the showing row's real menu (the helpers below open it
    // and click the item). The scope step at the end flips through all
    // three and rests the store at Element again.
    $showing = $assert_session->waitForElement('css', '.daedalus-stylish-page-tree__showing .daedalus-stylish-page-tree__scope');
    $this->assertNotNull($showing, 'The code body opened with its showing row (the scope sentence).');

    // The scenario's DOM helpers. Islands are found by their printed (or
    // live) text, because the tree can also hold rows the shipped section
    // variants style; the selection head is read through the DOM
    // selection by summing .cm-line TEXT NODES, because the production
    // bundle exposes no view object and widget chips inflate textContent.
    // The tree rows are read by their header's key, the scope by the
    // showing row's data-scope (the vocabulary's value, not the
    // sentence's words) and set through the showing row's menu (the
    // shared primitive mounts synchronously; the items sit in
    // vocabulary order, so the value's index is the item), and the
    // floor line by its printed (or live) text prefix, because the same
    // line is a .daedalus-stylish-island__line while printed and a
    // .cm-line while live.
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
          return document.querySelector('.daedalus-stylish-page-tree__scope')?.dataset.scope ?? null;
        },
        clickScope(value) {
          document.querySelector('.daedalus-stylish-page-tree__scope').click();
          const index = ['element', 'subtree', 'page'].indexOf(value);
          document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item')[index].click();
        },
        floorLine(needle = 'gap: var(--space-gutter);') {
          const line = Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .daedalus-stylish-island__line, .daedalus-stylish-page-tree .cm-line'))
            .find((candidate) => (candidate.textContent ?? '').startsWith(needle)) ?? null;
          if (!line) {
            return null;
          }
          return {
            delivered: line.classList.contains('daedalus-stylish-island__line--delivered'),
            lost: line.classList.contains('daedalus-stylish-island__line--lost'),
            hue: line.style.getPropertyValue('--daedalus-stylish-line-hue').trim(),
            title: line.title,
            chip: line.querySelector('.daedalus-stylish-island__winner')?.textContent.trim() ?? null,
          };
        },
        // The live selection head as a document offset, read from the DOM.
        // Line chrome (the winner chip on a struck line, the pending mark)
        // is a CodeMirror widget inside the .cm-line, contenteditable=false,
        // whose text is not document text: skipped, or a chip on an earlier
        // line shifts every later offset by its label's length.
        head() {
          const content = document.querySelector('.daedalus-stylish-page-tree .cm-editor .cm-content');
          const selection = window.getSelection();
          if (!content || !selection.focusNode || !content.contains(selection.focusNode)) {
            return -1;
          }
          const chrome = '[contenteditable="false"], .daedalus-stylish-island__winner, .daedalus-stylish-island__pending-mark';
          const isChrome = (node) => {
            const element = node.nodeType === Node.TEXT_NODE ? node.parentElement : node;
            return Boolean(element?.closest?.(chrome));
          };
          let base = 0;
          for (const line of content.querySelectorAll('.cm-line')) {
            let length = 0;
            const walker = document.createTreeWalker(line, NodeFilter.SHOW_TEXT);
            let node;
            while ((node = walker.nextNode())) {
              if (isChrome(node)) {
                continue;
              }
              if (node === selection.focusNode) {
                return base + length + selection.focusOffset;
              }
              length += node.data.length;
            }
            if (line === selection.focusNode || line.contains(selection.focusNode)) {
              let within = 0;
              Array.from(selection.focusNode.childNodes)
                .slice(0, selection.focusOffset)
                .filter((child) => !isChrome(child))
                .forEach((child) => { within += (child.textContent ?? '').length; });
              return base + Math.min(within, length);
            }
            base += length + 1;
          }
          return -1;
        },
      };
      JS);
    $session->executeScript("window.__st.clickScope('page');");

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
    // The leader page in Edit Mode carries the inspector's stylesheet and
    // the page chrome the pop-out must not (core navigation's control bar,
    // daedalus_ui's right sidebar), but never the satellite's column sheet
    // nor the pop-out document's chrome sheet: both attach by route (the
    // satellite library on the stylish pop-out route, daedalus_ui/popout on
    // every _daedalus_ui.popout route through DaedalusUiHooks::
    // pageAttachments), so the leader never pays popout.css's body-anchored
    // :has() on its every mutation. (Aggregation is off in the test
    // environment, so the stylesheet links are honest.)
    $this->assertTrue(
      (bool) $session->evaluateScript("!!document.querySelector('link[rel=\"stylesheet\"][href*=\"daedalus_stylish/css/inspector.css\"]')"),
      'The leader page carries the inspector stylesheet (the link-presence control).',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript("!!document.querySelector('.admin-toolbar-control-bar') && !!document.querySelector('#daedalus-ui-right-sidebar')"),
      'The leader page carries the page chrome (the control bar and the right sidebar): the chrome-presence control.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("!!document.querySelector('link[rel=\"stylesheet\"][href*=\"daedalus_stylish/css/satellite.css\"]')"),
      'The leader page does NOT carry the satellite stylesheet: the column layout never reaches the leader.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("!!document.querySelector('link[rel=\"stylesheet\"][href*=\"daedalus_ui/css/popout.css\"]') || document.documentElement.classList.contains('daedalus-ui-popout')"),
      'The leader page is no pop-out document: neither popout.css nor the html.daedalus-ui-popout stamp reaches the leader.',
    );

    // ——— The satellite chrome split, pop-out half. ———
    // The pop-out route is a chromeless document (Chromeless, keyed on the
    // _daedalus_ui.popout route option): the page_top chrome is dropped at
    // preprocess_html, the pop-out display variant prints no page title,
    // and the pop-out document stylesheet makes the body wear the chrome and
    // every wrapper down to the mount inherit it, so the inspector root's
    // computed color is the resolved --daedalus-text (the one fact only a
    // browser sees: the theme's floor rule on the token scope used to flip
    // it). The satellite mounts as a same-origin iframe of the leader page
    // rather than a popup: the pairing rides BroadcastChannel, which spans
    // every browsing context of the origin, and headless Chrome blocks
    // script-opened popups.
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
    // The computed-style probes below read the cascade, so wait for the
    // document's stylesheets to have loaded, not only its markup.
    $this->assertJsCondition("document.readyState === 'complete'", 10000, 'The pop-out document finished loading.');
    $this->assertTrue(
      (bool) $session->evaluateScript("document.documentElement.classList.contains('daedalus-ui-popout')"),
      'The pop-out document wears the html.daedalus-ui-popout stamp.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript("!!document.querySelector('link[rel=\"stylesheet\"][href*=\"daedalus_ui/css/popout.css\"]')"),
      'The pop-out carries the pop-out document stylesheet (daedalus_ui/popout).',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript("!!document.querySelector('link[rel=\"stylesheet\"][href*=\"daedalus_stylish/css/satellite.css\"]')"),
      'The pop-out carries the satellite stylesheet (the column layout).',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript("!!document.querySelector('link[rel=\"stylesheet\"][href*=\"daedalus_stylish/css/inspector.css\"]')"),
      'The pop-out carries the inspector stylesheet beside it.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("!!document.querySelector('#admin-toolbar, .admin-toolbar-control-bar')"),
      'The pop-out carries no core navigation chrome (no rail, no control bar): page_top dropped at preprocess_html.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("!!document.querySelector('#daedalus-ui-right-sidebar')"),
      'The pop-out carries no right sidebar: the editing chrome stays on the leader.',
    );
    // One "Styles" heading: the inspector's own client-rendered header. The
    // pop-out display variant prints no page title, so the route title
    // lands only in <title>.
    $this->assertJsCondition(
      "Array.from(document.querySelectorAll('h1, h2, h3, h4, h5, h6')).filter((heading) => heading.textContent.trim() === 'Styles').length === 1",
      10000,
      'The pop-out shows exactly one "Styles" heading (the inspector\'s own).',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("!!document.querySelector('h1.page-title')"),
      'The pop-out prints no page title: the pop-out display variant owns the document.',
    );
    $satellite = json_decode((string) $session->evaluateScript(<<<'JS'
      (() => {
        const probe = document.createElement('div');
        probe.style.backgroundColor = 'var(--daedalus-background)';
        probe.style.color = 'var(--daedalus-text)';
        document.body.append(probe);
        const resolved = getComputedStyle(probe).backgroundColor;
        const resolvedText = getComputedStyle(probe).color;
        probe.remove();
        return JSON.stringify({
          body: getComputedStyle(document.body).backgroundColor,
          resolved,
          resolvedText,
          root: getComputedStyle(document.querySelector('#daedalus-stylish-inspector-root')).color,
        });
      })()
      JS), TRUE);
    $this->assertIsArray($satellite, 'The pop-out chrome probe returned its measurements.');
    $this->assertNotSame(
      'rgba(0, 0, 0, 0)',
      $satellite['resolved'],
      'The pop-out resolves --daedalus-background (daedalus_ui/variables arrived).',
    );
    $this->assertSame(
      $satellite['resolved'],
      $satellite['body'],
      'The pop-out body wears --daedalus-background: the pop-out document chrome applies on the route.',
    );
    $this->assertNotSame(
      'rgba(0, 0, 0, 0)',
      $satellite['resolvedText'],
      'The pop-out resolves --daedalus-text.',
    );
    $this->assertSame(
      $satellite['resolvedText'],
      $satellite['root'],
      'The inspector root\'s computed color is the resolved --daedalus-text: every wrapper between the body and the mount inherits the chrome, none re-paints it.',
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
    // pointer's apply gesture; the keyboard's, shift+v forwarded over the
    // pop-out keyboard wire, is the last leg's); a second press on the add
    // row closes it again.
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
    // above it (the whole page, every element a row), Subtree prints the container's
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
      "window.__st.scope() === 'page'"
      . " && window.__st.rows().includes(" . json_encode($entity_key) . ")"
      . " && window.__st.rows().includes(" . json_encode($container_key) . ")"
      . " && document.querySelector('.daedalus-stylish-page-tree__header--selected')?.dataset.daedalusStylishPageKey === " . json_encode($container_key),
      20000,
      'Page scope: the tree prints the entity root above the selected container (the whole page, every element a row), the container\'s row selected.',
    );
    $session->executeScript("window.__st.clickScope('subtree');");
    $subtree_rows = json_encode([$container_key, $block_a_key, $block_b_key]);
    $this->assertJsCondition(
      "window.__st.scope() === 'subtree'"
      . " && JSON.stringify(window.__st.rows()) === " . json_encode($subtree_rows),
      20000,
      'Subtree scope: the tree prints exactly the selected container\'s row and its two blocks\' rows, in layout order, nothing above (the entity root is gone).',
    );
    $this->assertSame(
      'subtree',
      $session->evaluateScript("localStorage.getItem('daedalusStylishCodeScope')"),
      'The Subtree click wrote through to the scope store (localStorage daedalusStylishCodeScope), the preference the satellite reads.',
    );
    $session->executeScript("window.__st.clickScope('element');");
    $this->assertJsCondition(
      "window.__st.scope() === 'element'"
      . " && JSON.stringify(window.__st.rows()) === " . json_encode(json_encode([$container_key])),
      20000,
      'Element scope: the tree prints the selected container\'s row alone.',
    );

    // ——— The floor lines. ———
    // Seam: the selected element's style stack over the blueprint tree
    // (buildStack's floor layers, one per declaring scope, ids
    // declared:{scope} / skin:{scope}) through stackLines() and out as
    // printed lines. guide_example's skin declares `kind:container`
    // gap: var(--space-gutter) (an in-fixture skin line; the parent
    // topic's basic-mode step had to pose the canvas for one), the
    // container kind's own declaration (daedalus_stylish_layouts) gap: 0px
    // beneath it, and the stamped band variants set no gap, so the
    // selected container's default island prints the skin's gap line in
    // the floor hue (SKIN_COLOR, layers.js), titled by its tier and
    // scope, winning, and the declaration's gap line struck by the
    // skin's chip. Typing an own gap into the same island strikes the
    // skin line with the "this element" chip (the map's own layer beats
    // the floor), and deleting the typed line again lifts the override
    // through islandDelta's UNSET rule: the skin line stands unstruck
    // and the map never held the skin's value.
    $this->assertJsCondition(
      "(() => { const line = window.__st.floorLine(); return !!line && line.delivered && !line.lost && line.chip === null; })()",
      20000,
      'The selected container\'s default island prints guide_example\'s skin gap line (gap: var(--space-gutter)) as a delivered line, unstruck, no winner chip: the skin wins.',
    );
    $floor = json_decode((string) $session->evaluateScript('JSON.stringify(window.__st.floorLine())'), TRUE);
    $this->assertSame(
      'hsl(172 66% 50%)',
      $floor['hue'],
      'The skin line carries --daedalus-stylish-line-hue in the floor\'s hue (SKIN_COLOR).',
    );
    $this->assertSame(
      'Supplied by the active skin (kind:container).',
      $floor['title'],
      'The skin line\'s hover title names its tier and its declaring scope: the floor layer id is per scope (skin:kind:container).',
    );
    // The declaration's line beneath it: the container kind declares
    // gap: 0px, a floor layer of its own (declared:kind:container) that
    // the skin's block beats, so the line prints delivered in the floor hue,
    // struck, with the skin tier's chip and its own scope in the title.
    $declared = json_decode((string) $session->evaluateScript("JSON.stringify(window.__st.floorLine('gap: 0px;'))"), TRUE);
    $this->assertIsArray($declared, 'The selected container\'s default island prints the container kind declaration\'s gap: 0px line (the declared floor layer beneath the skin\'s).');
    $this->assertTrue($declared['delivered'], 'The declaration\'s gap line prints as a delivered line.');
    $this->assertTrue($declared['lost'], 'The declaration\'s gap line is struck: the skin\'s kind:container block beats it.');
    $this->assertSame('the active skin', $declared['chip'], 'The declaration\'s gap line wears the winner chip naming the skin tier.');
    $this->assertSame('hsl(172 66% 50%)', $declared['hue'], 'The declaration\'s gap line carries the floor hue (SKIN_COLOR serves both floor tiers).');
    $this->assertSame(
      "Supplied by the declaration's defaults (kind:container).",
      $declared['title'],
      'The declaration\'s gap line\'s hover title names its tier and its declaring scope (declared:kind:container).',
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
      . " && (() => { const line = window.__st.floorLine(); return !!line && line.delivered && line.lost && line.chip === 'this element'; })()",
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
      . " && (() => { const line = window.__st.floorLine(); return !!line && line.delivered && !line.lost && line.chip === null; })()"
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
      . " && document.querySelector('.daedalus-stylish-page-tree__scope')?.dataset.scope === 'element'"
      . " && JSON.stringify(Array.from(document.querySelectorAll('.daedalus-stylish-page-tree [data-daedalus-stylish-page-key]')).map((header) => header.dataset.daedalusStylishPageKey)) === " . json_encode(json_encode([$container_key])),
      20000,
      'A satellite mounted after the scope clicks reads the same scope store: its showing row reads this element and its tree prints the mirrored container\'s row alone, no relay involved.',
    );
    $session->switchToIFrame(NULL);

    // The scope rests at Element, the store's default, where the scope
    // step drove it: the scenario leaves the preference as it found it.
    $this->assertSame(
      'element',
      $session->evaluateScript("localStorage.getItem('daedalusStylishCodeScope')"),
      'The scope store rests at Element (its default) at the end of the scenario.',
    );

    // ——— Selector rows: the container styles its children once. ———
    // The satellite leaves (its bye returns the in-page panel), then three
    // text blocks land BEFORE block A through the op spine, so the first
    // of them is the container's first child (the fact row below reads
    // the browser's :first-child) and the container holds three
    // `block_type:text` roots (paragraphs) beside the two formatted ones.
    $session->executeScript("document.querySelector('iframe[name=\"daedalus_stylish_satellite\"]').remove();");
    $code_button = $assert_session->waitForElement('css', '.daedalus-stylish-inspector__ui-mode-button--code');
    $this->assertNotNull($code_button, 'The satellite\'s leave returned the in-page panel for the selector steps.');
    $text_uuids = [$uuid_service->generate(), $uuid_service->generate(), $uuid_service->generate()];
    [$text_1, $text_2, $text_3] = $text_uuids;
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_element',
              uuid: '$text_1',
              block_type: 'text',
              properties: { field_text_text: 'First text block', field_text_tag: 'p' },
              location: { anchor: '$block_a_uuid', placement: 'before' },
            },
            {
              op: 'add_element',
              uuid: '$text_2',
              block_type: 'text',
              properties: { field_text_text: 'Second text block', field_text_tag: 'p' },
              location: { anchor: '$text_1', placement: 'after' },
            },
            {
              op: 'add_element',
              uuid: '$text_3',
              block_type: 'text',
              properties: { field_text_text: 'Third text block', field_text_tag: 'p' },
              location: { anchor: '$text_2', placement: 'after' },
            },
          ],
        })).catch((error) => {
          window.__textPlacementError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__textPlacementError !== undefined"
      . " || (!!document.querySelector(\"[data-selectable='block:$text_3']\") && JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$text_3'))",
      20000,
      'The three text blocks placed and rendered.',
    );
    $this->assertNull($session->evaluateScript('window.__textPlacementError ?? null'), 'Text block placement failed: ' . ($session->evaluateScript('window.__textPlacementError ?? null') ?? ''));
    $this->assertSame(
      [$text_1, $text_2, $text_3, $block_a_uuid, $block_b_uuid],
      json_decode((string) $session->evaluateScript(
        "JSON.stringify(Array.from(document.querySelector(\"[data-selectable='$container_key']\").querySelectorAll(':scope > [data-element]')).map((el) => el.dataset.selectable.replace('block:', '')))"
      ), TRUE),
      'The container\'s direct children are the three text blocks then the two formatted text blocks, each stamped with its element address.',
    );
    $this->assertSame(
      'block_type:text',
      $session->evaluateScript("document.querySelector(\"[data-selectable='block:$text_1']\").dataset.element"),
      'A text block root wears its data-element address (what a type compound prints against).',
    );

    // The container selected at Element scope, code mode: its island
    // prints (the floor line beneath), the one row in the tree.
    $session->executeScript("Drupal.Daedalus.Selection.select('$container_key');");
    $code_button->click();
    $session->executeScript("window.__st.clickScope('element');");
    $this->assertJsCondition(
      "window.__st.scope() === 'element' && window.__st.floorLine() !== null && window.__st.editors() === 0",
      20000,
      'The container\'s island prints at Element scope with the skin\'s gap floor line, no editor live.',
    );

    // The selector-step helpers: the container's root island (found by
    // the floor line its print carries), a wake at the end of its last
    // printed line, the selector row header by owner and selector, the
    // open completion's labels and details, and computed reads.
    $session->executeScript(<<<JS
      window.__sel = {
        rootIsland() {
          return Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .daedalus-stylish-islands > .daedalus-stylish-island .daedalus-stylish-island__editor'))
            .find((editor) => (editor.textContent ?? '').includes('gap: var(--space-gutter);')) ?? null;
        },
        wakeAtEnd(island) {
          island.scrollIntoView({ block: 'center' });
          const lines = island.querySelectorAll('.daedalus-stylish-island__line');
          const line = lines[lines.length - 1];
          const textNode = line.firstChild;
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
        },
        header(selector) {
          return Array.from(document.querySelectorAll('.daedalus-stylish-page-tree [data-daedalus-stylish-part-owner="$container_key"]'))
            .find((header) => header.dataset.daedalusStylishPart === selector) ?? null;
        },
        headerFacts(selector) {
          const header = this.header(selector);
          if (!header) {
            return null;
          }
          const body = header.nextElementSibling;
          return {
            label: header.querySelector('.daedalus-stylish-islands__part-label')?.textContent ?? null,
            code: header.querySelector('.daedalus-stylish-islands__prelude')?.textContent ?? null,
            chips: header.querySelectorAll('.daedalus-stylish-islands__combinator').length,
            liveInBody: body ? body.querySelectorAll('.cm-editor').length : -1,
            focusedInBody: body ? body.contains(document.activeElement) : false,
          };
        },
        completions() {
          return Array.from(document.querySelectorAll('.cm-tooltip-autocomplete li'))
            .map((item) => [item.querySelector('.cm-completionLabel')?.textContent ?? '', item.querySelector('.cm-completionDetail')?.textContent ?? '']);
        },
        color(selector) {
          const element = document.querySelector(selector);
          return element ? getComputedStyle(element).color : null;
        },
        token(name) {
          const probe = document.createElement('span');
          probe.style.color = 'var(' + name + ')';
          document.querySelector('[data-selectable="$container_key"]').append(probe);
          const resolved = getComputedStyle(probe).color;
          probe.remove();
          return resolved;
        },
      };
      JS);
    $accent = (string) $session->evaluateScript("window.__sel.token('--color-accent')");
    $muted = (string) $session->evaluateScript("window.__sel.token('--color-muted')");
    $this->assertNotSame('rgba(0, 0, 0, 0)', $accent, 'The skin\'s --color-accent resolves on the canvas.');
    $this->assertNotSame($accent, $muted, 'The two skin colors the steps use differ, so each computed read is unambiguous.');
    $text_selector = fn (string $uuid) => "[data-selectable='block:$uuid']";
    $this->assertNotSame($accent, $session->evaluateScript("window.__sel.color(\"{$text_selector($text_1)}\")"), 'Before any selector rule the first text block does not wear the accent color (the control).');

    // Type `>` on the container's island: the selector gesture opens the
    // one completion, built from the container's actual children: the
    // universal child first, then the two block types present, each
    // labelled in the reader's words. The editor shows the options
    // matching the typed text, so the whole offer is read at the bare
    // combinator and the type's spelling narrows it to one.
    $session->executeScript("window.__sel.wakeAtEnd(window.__sel.rootIsland());");
    $this->assertJsCondition('window.__st.editors() === 1', 10000, 'The pointerdown at the end of the container island woke it.');
    $session->executeScript("document.execCommand('insertText', false, '\\n>');");
    $this->assertJsCondition(
      "window.__sel.completions().some(([label]) => label === '> pb-text')",
      20000,
      'Typing `>` on the container island offered the `> pb-text` selector.',
    );
    $offered = json_decode((string) $session->evaluateScript('JSON.stringify(window.__sel.completions())'), TRUE);
    $labels = array_column($offered, 0);
    $details = array_combine($labels, array_column($offered, 1));
    $this->assertSame('> *', $labels[0], 'The universal child is offered first.');
    $this->assertContains('> pb-formatted-text', $labels, 'The other type among the container\'s children is offered too (built from the matcher\'s actual children).');
    $this->assertNotContains('> pb-image', $labels, 'A type absent from the container\'s children is not offered.');
    $this->assertSame('Every text block here', $details['> pb-text'], 'The type option is labelled by the bundle\'s served label.');
    $this->assertSame('Children', $details['> *'], 'The universal child reads "Children".');
    // The editor's own fuzzy filter keeps `> pb-formatted-text` beside the
    // exact match (its letters occur in order), ranked below it.
    $session->executeScript("document.execCommand('insertText', false, ' pb-text');");
    $this->assertJsCondition(
      "(() => { const labels = window.__sel.completions().map(([label]) => label); return labels[0] === '> pb-text' && !labels.includes('> *'); })()",
      20000,
      'Typing the type\'s spelling narrows the offer: `> pb-text` ranks first and the universal child drops out.',
    );

    // Pick it with a real click on the option: the typed text is removed,
    // the row mints with its human label and canonical code face (one
    // `>` chip), and its island is the one live editor, focused.
    $option = $session->getPage()->find('xpath', '//div[contains(@class, "cm-tooltip-autocomplete")]//li[.//span[contains(@class, "cm-completionLabel")][normalize-space(.)="> pb-text"]]');
    $this->assertNotNull($option, 'The `> pb-text` completion option is present to click.');
    $option->click();
    $this->assertJsCondition(
      "(() => { const facts = window.__sel.headerFacts('> pb-text'); return !!facts && facts.liveInBody === 1 && facts.focusedInBody && window.__st.editors() === 1; })()",
      20000,
      'Picking `> pb-text` minted its row with the one live editor inside it, focused (the island took the cursor).',
    );
    $facts = json_decode((string) $session->evaluateScript("JSON.stringify(window.__sel.headerFacts('> pb-text'))"), TRUE);
    $this->assertSame('Text blocks', $facts['label'], 'The row header reads the bundle\'s label, plural.');
    $this->assertSame('> pb-text', $facts['code'], 'The row header\'s code face reads the canonical selector.');
    $this->assertSame(1, $facts['chips'], 'The direct-child combinator wears its chip.');
    $this->assertJsCondition(
      "!Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .cm-line, .daedalus-stylish-page-tree .daedalus-stylish-island__line')).some((line) => line.textContent.includes('> pb-text') && !line.closest('.daedalus-stylish-islands__part-body'))",
      10000,
      'The typed selector text left the container island: structure is chrome, never characters.',
    );

    // A color typed into the new island commits at the selector key and
    // lands on the three text blocks' computed color, on nothing else.
    $rule_key = "$container_key::> pb-text";
    $session->executeScript("document.execCommand('insertText', false, 'color: var(--color-accent);');");
    $this->assertJsCondition(
      "(" . $map . ")[" . json_encode($rule_key) . "]?.default?.color === 'var(--color-accent)'",
      20000,
      'The declaration committed at the selector key (container::> pb-text).',
    );
    foreach ($text_uuids as $index => $uuid) {
      $this->assertJsCondition(
        "window.__sel.color(\"{$text_selector($uuid)}\") === " . json_encode($accent),
        20000,
        'Text block ' . ($index + 1) . ' renders the container\'s `> pb-text` color.',
      );
    }
    $this->assertNotSame($accent, $session->evaluateScript("window.__sel.color(\"{$text_selector($block_a_uuid)}\")"), 'The formatted text block beside them does not: the type compound addresses text blocks only.');

    // The nearest key wins: one text block's own color beats the
    // container's rule by @scope proximity (equal specificity by the
    // printer\'s discipline), the other two keep the inherited rule.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{ op: 'update_style', key: 'block:$text_1', properties: { color: 'var(--color-muted)' } }],
        })).catch((error) => {
          window.__ownColorError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__ownColorError !== undefined || window.__sel.color(\"{$text_selector($text_1)}\") === " . json_encode($muted),
      20000,
      'The first text block\'s own color wins over the container\'s `> pb-text` rule (the closest key\'s rule wins).',
    );
    $this->assertNull($session->evaluateScript('window.__ownColorError ?? null'), 'The own color commit failed: ' . ($session->evaluateScript('window.__ownColorError ?? null') ?? ''));
    $this->assertSame($accent, $session->evaluateScript("window.__sel.color(\"{$text_selector($text_2)}\")"), 'The second text block still wears the container\'s rule.');

    // `p {` typed on the container island mints the paragraph row with
    // no pick: the brace is swallowed with the selector. The row's code
    // face reads `p` with no chip (a descendant rule), and its color
    // reaches the paragraph INSIDE the formatted text block (doctrine B:
    // a tag rule crosses a child\'s root) while the text blocks keep the
    // heavier `> pb-text` rule (0-1-0 over the tag tier\'s 0-0-1).
    $session->executeScript("window.__sel.wakeAtEnd(window.__sel.rootIsland());");
    $this->assertJsCondition(
      "window.__st.editors() === 1 && window.__sel.rootIsland().querySelectorAll('.cm-editor').length === 1",
      10000,
      'The container island woke again for the paragraph rule.',
    );
    $session->executeScript("document.execCommand('insertText', false, '\\np ');");
    $session->executeScript("document.execCommand('insertText', false, '{');");
    $this->assertJsCondition(
      "(() => { const facts = window.__sel.headerFacts('p'); return !!facts && facts.liveInBody === 1 && facts.focusedInBody && facts.code === 'p' && facts.chips === 0; })()",
      20000,
      'Typing `p {` minted the paragraph row (the brace swallowed with the selector), its island live and focused, the code face reading `p` with no chip.',
    );
    $this->assertNotSame('', json_decode((string) $session->evaluateScript("JSON.stringify(window.__sel.headerFacts('p'))"), TRUE)['label'], 'The paragraph row carries a label.');
    $session->executeScript("document.execCommand('insertText', false, 'color: var(--color-muted);');");
    $this->assertJsCondition(
      "(" . $map . ")[" . json_encode("$container_key::p") . "]?.default?.color === 'var(--color-muted)'",
      20000,
      'The paragraph declaration committed at the tag key (container::p).',
    );
    $this->assertJsCondition(
      "window.__sel.color(\"{$text_selector($block_a_uuid)} p\") === " . json_encode($muted),
      20000,
      'The container\'s `p` rule reaches the paragraph inside the formatted text block (a tag rule crosses a child\'s root).',
    );
    $this->assertSame($accent, $session->evaluateScript("window.__sel.color(\"{$text_selector($text_2)}\")"), 'A text block\'s paragraph root keeps the `> pb-text` color: the type rule outweighs the tag tier.');

    // A `first-child` fact row on the `> pb-text` row: the `&` gesture
    // offers the structural facts under Position; picking one mints the
    // condition row whose island commits under the condition, printed bare
    // (`:scope > [data-element="block_type:text"]:first-child`, no
    // force-state dual), so the browser\'s own :first-child picks the
    // first text block alone.
    $session->executeScript(<<<'JS'
      (() => {
        const island = Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .daedalus-stylish-islands__part-body .daedalus-stylish-island__editor'))
          .find((editor) => (editor.textContent ?? '').includes('var(--color-accent)'));
        window.__sel.wakeAtEnd(island);
      })();
      JS);
    $this->assertJsCondition(
      "window.__st.editors() === 1 && !!document.activeElement.closest('.daedalus-stylish-islands__part-body')",
      10000,
      'The `> pb-text` row\'s island woke for the fact row.',
    );
    $session->executeScript("document.execCommand('insertText', false, '\\n&first-child');");
    $this->assertJsCondition(
      "window.__sel.completions().some(([label]) => label === 'first-child')",
      20000,
      'The `&` gesture offered the first-child fact on the selector row.',
    );
    $fact_option = $session->getPage()->find('xpath', '//div[contains(@class, "cm-tooltip-autocomplete")]//li[.//span[contains(@class, "cm-completionLabel")][normalize-space(.)="first-child"]]');
    $this->assertNotNull($fact_option, 'The first-child option is present to click.');
    $fact_option->click();
    // The condition island must hold the focus itself before the declaration
    // is typed: the row's header renders a tick before its island wakes,
    // and until that wake blurs the `> pb-text` island (parked on blur)
    // the ONE live editor is still the old one, so a check on the count
    // alone passes early and the declaration would land at the default
    // condition instead.
    $this->assertJsCondition(
      "(() => { const header = window.__sel.header('> pb-text'); const when = header?.nextElementSibling?.querySelector('.daedalus-stylish-islands__condition'); const island = when?.nextElementSibling;"
      . " return !!when && when.querySelector('.daedalus-stylish-islands__prelude')?.textContent === '&:first-child' && window.__st.editors() === 1"
      . " && !!island && island.classList.contains('daedalus-stylish-island') && !!island.querySelector('.cm-editor') && island.contains(document.activeElement); })()",
      20000,
      'Picking first-child minted the `&:first-child` condition row under the `> pb-text` row with its own island live and focused (the one editor on the tree).',
    );
    $session->executeScript("document.execCommand('insertText', false, 'text-transform: uppercase;');");
    $this->assertJsCondition(
      "(" . $map . ")[" . json_encode($rule_key) . "]?.['first-child']?.['text-transform'] === 'uppercase'",
      20000,
      'The declaration committed under the first-child condition at the selector key.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"{$text_selector($text_1)}\")).textTransform === 'uppercase'"
      . " && getComputedStyle(document.querySelector(\"{$text_selector($text_2)}\")).textTransform === 'none'",
      20000,
      'The fact row uppercases the first text block alone: the browser\'s :first-child on the printed bare wrapper.',
    );

    // ——— Selector relations: siblings, :has(), :not(), a negated fact. ———
    // A second section is its own band: three text blocks rooted p, p
    // and h3 (the second carrying a URL, so its link part renders an
    // anchor) and an image block last, placed through the op spine so
    // each relation below has exactly one true answer among four ordered
    // children. The image block carries no media: its composed root's
    // empty form is a plain div, stamped like any block.
    $band_uuid = $uuid_service->generate();
    $band_key = "container:$band_uuid";
    $band_p1 = $uuid_service->generate();
    $band_p2 = $uuid_service->generate();
    $band_h3 = $uuid_service->generate();
    $band_img = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_section',
              uuid: '$band_uuid',
              location: { anchor: Blueprint.getRoot().uuid, placement: 'inside' },
            },
            {
              op: 'add_element',
              uuid: '$band_p1',
              block_type: 'text',
              properties: { field_text_text: { value: 'Band first paragraph' }, field_text_tag: 'p' },
              location: { anchor: '$band_uuid', placement: 'inside' },
            },
            {
              op: 'add_element',
              uuid: '$band_p2',
              block_type: 'text',
              properties: { field_text_text: { value: 'Band linked paragraph' }, field_text_tag: 'p', field_text_url: { uri: 'https://example.com/band' } },
              location: { anchor: '$band_p1', placement: 'after' },
            },
            {
              op: 'add_element',
              uuid: '$band_h3',
              block_type: 'text',
              properties: { field_text_text: { value: 'Band heading' }, field_text_tag: 'h3' },
              location: { anchor: '$band_p2', placement: 'after' },
            },
            {
              op: 'add_element',
              uuid: '$band_img',
              block_type: 'image',
              properties: {},
              location: { anchor: '$band_h3', placement: 'after' },
            },
          ],
        })).catch((error) => {
          window.__bandPlacementError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__bandPlacementError !== undefined"
      . " || (!!document.querySelector(\"[data-selectable='block:$band_img']\") && !!document.querySelector(\"[data-selectable='block:$band_p2'] a.text-block-link\") && JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$band_img'))",
      20000,
      'The band placed and rendered: the image block last, the second text block with its anchor.',
    );
    $band_placement_error = $session->evaluateScript('window.__bandPlacementError ?? null');
    $this->assertNull($band_placement_error, 'Band placement failed: ' . ($band_placement_error ?? ''));
    $this->assertSame(
      [$band_p1, $band_p2, $band_h3, $band_img],
      json_decode((string) $session->evaluateScript(
        "JSON.stringify(Array.from(document.querySelector(\"[data-selectable='$band_key']\").querySelectorAll(':scope > [data-element]')).map((el) => el.dataset.selectable.replace('block:', '')))"
      ), TRUE),
      'The band\'s direct children are the three text blocks then the image block, each stamped.',
    );
    $this->assertSame(
      ['P', 'P', 'H3', 'block_type:image'],
      json_decode((string) $session->evaluateScript(
        "JSON.stringify([document.querySelector(\"{$text_selector($band_p1)}\").tagName, document.querySelector(\"{$text_selector($band_p2)}\").tagName, document.querySelector(\"{$text_selector($band_h3)}\").tagName, document.querySelector(\"{$text_selector($band_img)}\").dataset.element])"
      ), TRUE),
      'The text roots are the p, p and h3 the sibling combinator reads, and the image root wears the type stamp :not() prints against.',
    );

    // The band selected at Element scope, code mode: its island prints
    // alone. The relation helpers: the band's row headers by selector,
    // a part-body island by needle, a computed read, and a length token
    // resolved to pixels inside the band (a probe, removed at once).
    $session->executeScript("Drupal.Daedalus.Selection.select('$band_key');");
    $this->assertJsCondition(
      "window.__st.scope() === 'element' && window.__st.floorLine() !== null && window.__st.editors() === 0"
      . " && document.querySelector('.daedalus-stylish-page-tree [data-daedalus-stylish-page-key=\"$band_key\"]') !== null",
      20000,
      'The band\'s island prints at Element scope, no editor live.',
    );
    $session->executeScript(<<<JS
      window.__rel = {
        header(selector) {
          return Array.from(document.querySelectorAll('.daedalus-stylish-page-tree [data-daedalus-stylish-part-owner="$band_key"]'))
            .find((header) => header.dataset.daedalusStylishPart === selector) ?? null;
        },
        facts(selector) {
          const header = this.header(selector);
          if (!header) {
            return null;
          }
          const body = header.nextElementSibling;
          return {
            code: header.querySelector('.daedalus-stylish-islands__prelude')?.textContent ?? null,
            chips: header.querySelectorAll('.daedalus-stylish-islands__combinator').length,
            liveInBody: body ? body.querySelectorAll('.cm-editor').length : -1,
            focusedInBody: body ? body.contains(document.activeElement) : false,
          };
        },
        island(needle) {
          return Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .daedalus-stylish-islands__part-body .daedalus-stylish-island__editor'))
            .find((editor) => (editor.textContent ?? '').includes(needle)) ?? null;
        },
        computed(selector, property) {
          const element = document.querySelector(selector);
          return element ? getComputedStyle(element)[property] : null;
        },
        px(property, value) {
          const probe = document.createElement('div');
          probe.style[property] = value;
          document.querySelector('[data-selectable="$band_key"]').append(probe);
          const resolved = getComputedStyle(probe)[property];
          probe.remove();
          return resolved;
        },
      };
      JS);
    $space_8 = (string) $session->evaluateScript("window.__rel.px('marginTop', 'var(--space-8)')");
    $this->assertMatchesRegularExpression('/^[1-9][0-9]*(\.[0-9]+)?px$/', $space_8, 'The skin\'s --space-8 resolves to a non-zero length inside the band (the value the sibling rule is read against).');
    $band_selectors = [
      'p1' => $text_selector($band_p1),
      'p2' => $text_selector($band_p2),
      'h3' => $text_selector($band_h3),
      'img' => $text_selector($band_img),
    ];
    $band_computed = fn (string $property) => json_decode((string) $session->evaluateScript(
      "JSON.stringify(Object.fromEntries(Object.entries(" . json_encode($band_selectors) . ").map(([name, selector]) => [name, window.__rel.computed(selector, " . json_encode($property) . ")])))"
    ), TRUE);
    $before = $band_computed('marginTop');
    $this->assertNotSame($space_8, $before['p2'], 'Before any sibling rule the second paragraph does not wear the --space-8 margin (the control).');

    // `> p + p {` typed on the band's island mints the sibling row by the
    // swallowed brace (a relation line offers no completion): the row's
    // code face reads the canonical selector with a chip per combinator,
    // and its margin token (the strict law binds margin to the space
    // scale) commits at the relational key and lands on the paragraph
    // right after a paragraph alone: the printed
    // `:where(:scope) > :where(p) + p` leaves the first paragraph (no
    // paragraph before it) and the heading (not a p) untouched.
    $session->executeScript("window.__sel.wakeAtEnd(window.__sel.rootIsland());");
    $this->assertJsCondition('window.__st.editors() === 1', 10000, 'The band island woke for the sibling rule.');
    $session->executeScript("document.execCommand('insertText', false, '\\n> p + p ');");
    $session->executeScript("document.execCommand('insertText', false, '{');");
    $this->assertJsCondition(
      "(() => { const facts = window.__rel.facts('> p + p'); return !!facts && facts.liveInBody === 1 && facts.focusedInBody && facts.code === '> p + p' && facts.chips === 2; })()",
      20000,
      'Typing `> p + p {` minted the sibling row (the brace swallowed), its island live and focused, the code face reading the canonical selector with a `>` chip and a `+` chip.',
    );
    $sibling_key = "$band_key::> p + p";
    $session->executeScript("document.execCommand('insertText', false, 'margin-top: var(--space-8);');");
    $this->assertJsCondition(
      "(" . $map . ")[" . json_encode($sibling_key) . "]?.default?.['margin-top'] === 'var(--space-8)'",
      20000,
      'The margin declaration committed at the sibling key (container::> p + p).',
    );
    $this->assertJsCondition(
      "window.__rel.computed(\"{$band_selectors['p2']}\", 'marginTop') === " . json_encode($space_8),
      20000,
      'The second paragraph (a p right after a p) computes the sibling rule\'s margin.',
    );
    $after_sibling = $band_computed('marginTop');
    $this->assertNotSame($space_8, $after_sibling['p1'], 'The first paragraph does not: no paragraph precedes it.');
    $this->assertNotSame($space_8, $after_sibling['h3'], 'The heading does not: `+ p` names a paragraph, and the heading root is an h3.');
    $this->assertNotSame($space_8, $after_sibling['img'], 'The image block does not: the sibling rule addresses paragraphs.');

    // `> pb-text:has(a) {` mints the qualified type row; its color lands
    // on the linked text block alone: the printed
    // `[data-element="block_type:text"]:has(:where(a))` reads the anchor
    // the second block's link part renders, and the other two text
    // blocks hold no anchor.
    $session->executeScript("window.__sel.wakeAtEnd(window.__sel.rootIsland());");
    $this->assertJsCondition(
      "window.__st.editors() === 1 && window.__sel.rootIsland().querySelectorAll('.cm-editor').length === 1",
      10000,
      'The band island woke again for the :has() rule.',
    );
    $session->executeScript("document.execCommand('insertText', false, '\\n> pb-text:has(a) ');");
    $session->executeScript("document.execCommand('insertText', false, '{');");
    $this->assertJsCondition(
      "(() => { const facts = window.__rel.facts('> pb-text:has(a)'); return !!facts && facts.liveInBody === 1 && facts.focusedInBody && facts.code === '> pb-text:has(a)' && facts.chips === 1; })()",
      20000,
      'Typing `> pb-text:has(a) {` minted the qualified row, its island live and focused, the code face reading the canonical selector with one `>` chip (the qualifier is code text).',
    );
    $has_key = "$band_key::> pb-text:has(a)";
    $session->executeScript("document.execCommand('insertText', false, 'color: var(--color-accent);');");
    $this->assertJsCondition(
      "(" . $map . ")[" . json_encode($has_key) . "]?.default?.color === 'var(--color-accent)'",
      20000,
      'The color declaration committed at the :has() key (container::> pb-text:has(a)).',
    );
    $this->assertJsCondition(
      "window.__rel.computed(\"{$band_selectors['p2']}\", 'color') === " . json_encode($accent),
      20000,
      'The linked text block\'s root computes the :has() rule\'s color.',
    );
    $after_has = $band_computed('color');
    $this->assertNotSame($accent, $after_has['p1'], 'The unlinked first paragraph does not: it holds no anchor.');
    $this->assertNotSame($accent, $after_has['h3'], 'The unlinked heading does not.');
    $this->assertNotSame($accent, $after_has['img'], 'The image block does not: the type compound names text blocks.');

    // `> *:not(pb-image) {` mints the negated universal row, landing on
    // the container's declared children (layout and sizing groups, so a
    // sizing literal is the write the strict law admits there); the
    // printed `:scope > *:not(:where([data-element="block_type:image"]))`
    // reaches the three text blocks and skips the image block.
    $session->executeScript("window.__sel.wakeAtEnd(window.__sel.rootIsland());");
    $this->assertJsCondition(
      "window.__st.editors() === 1 && window.__sel.rootIsland().querySelectorAll('.cm-editor').length === 1",
      10000,
      'The band island woke again for the :not() rule.',
    );
    $session->executeScript("document.execCommand('insertText', false, '\\n> *:not(pb-image) ');");
    $session->executeScript("document.execCommand('insertText', false, '{');");
    $this->assertJsCondition(
      "(() => { const facts = window.__rel.facts('> *:not(pb-image)'); return !!facts && facts.liveInBody === 1 && facts.focusedInBody && facts.code === '> *:not(pb-image)' && facts.chips === 1; })()",
      20000,
      'Typing `> *:not(pb-image) {` minted the negated row, its island live and focused, the code face reading the canonical selector with one `>` chip.',
    );
    $not_key = "$band_key::> *:not(pb-image)";
    // The empty image block's edit-mode placeholder carries its own
    // min-height (6rem), so the rule's literal is one nothing else
    // delivers and the image block is read against its own prior value.
    $before_not = $band_computed('minHeight');
    $session->executeScript("document.execCommand('insertText', false, 'min-height: 77px;');");
    $this->assertJsCondition(
      "(" . $map . ")[" . json_encode($not_key) . "]?.default?.['min-height'] === '77px'",
      20000,
      'The min-height declaration committed at the :not() key (container::> *:not(pb-image)).',
    );
    $this->assertJsCondition(
      "window.__rel.computed(\"{$band_selectors['h3']}\", 'minHeight') === '77px'",
      20000,
      'The heading block computes the :not() rule\'s min-height.',
    );
    $after_not = $band_computed('minHeight');
    $this->assertSame('77px', $after_not['p1'], 'The first paragraph computes it too.');
    $this->assertSame('77px', $after_not['p2'], 'The linked paragraph computes it too: every child but the image.');
    $this->assertSame($before_not['img'], $after_not['img'], 'The image block keeps its prior min-height: :not(pb-image) excludes it.');
    $this->assertNotSame('77px', $after_not['img'], 'The image block does not compute the rule\'s literal.');

    // `> * {` mints the universal children row; a `not-last-child` fact
    // row minted on it through the `&` gesture commits under the
    // negated fact, printed bare (`:scope > *:not(:last-child)`), so the
    // browser reaches the first three children and never the last.
    $session->executeScript("window.__sel.wakeAtEnd(window.__sel.rootIsland());");
    $this->assertJsCondition(
      "window.__st.editors() === 1 && window.__sel.rootIsland().querySelectorAll('.cm-editor').length === 1",
      10000,
      'The band island woke again for the universal children rule.',
    );
    $session->executeScript("document.execCommand('insertText', false, '\\n> * ');");
    $session->executeScript("document.execCommand('insertText', false, '{');");
    $this->assertJsCondition(
      "(() => { const facts = window.__rel.facts('> *'); return !!facts && facts.liveInBody === 1 && facts.focusedInBody && facts.code === '> *' && facts.chips === 1; })()",
      20000,
      'Typing `> * {` minted the universal children row, its island live and focused.',
    );
    $children_key = "$band_key::> *";
    $session->executeScript("document.execCommand('insertText', false, 'max-width: 100%;');");
    $this->assertJsCondition(
      "(" . $map . ")[" . json_encode($children_key) . "]?.default?.['max-width'] === '100%'",
      20000,
      'The universal row\'s own declaration committed at the children key (container::> *).',
    );
    // The `> *` island is still live after its settle (nothing parked
    // it), so the sigil is typed at the end of its last line: the DOM
    // selection collapsed there, which the editor observes as its caret.
    $session->executeScript(<<<'JS'
      (() => {
        const island = window.__rel.island('max-width: 100%');
        island.querySelector('.cm-content').focus();
        const lines = island.querySelectorAll('.cm-line');
        const selection = document.getSelection();
        selection.selectAllChildren(lines[lines.length - 1]);
        selection.collapseToEnd();
      })();
      JS);
    $this->assertJsCondition(
      "window.__st.editors() === 1 && !!window.__rel.island('max-width: 100%')?.querySelector('.cm-editor') && !!document.activeElement.closest('.daedalus-stylish-islands__part-body')",
      10000,
      'The `> *` row\'s island is live and focused for the fact row.',
    );
    $session->executeScript("document.execCommand('insertText', false, '\\n&not-last-child');");
    $this->assertJsCondition(
      "window.__sel.completions().some(([label]) => label === 'not-last-child')",
      20000,
      'The `&` gesture offered the not-last-child fact on the universal row.',
    );
    $negated_option = $session->getPage()->find('xpath', '//div[contains(@class, "cm-tooltip-autocomplete")]//li[.//span[contains(@class, "cm-completionLabel")][normalize-space(.)="not-last-child"]]');
    $this->assertNotNull($negated_option, 'The not-last-child option is present to click.');
    $negated_option->click();
    $this->assertJsCondition(
      "(() => { const header = window.__rel.header('> *'); const when = header?.nextElementSibling?.querySelector('.daedalus-stylish-islands__condition'); const island = when?.nextElementSibling;"
      . " return !!when && when.querySelector('.daedalus-stylish-islands__prelude')?.textContent === '&:not(:last-child)' && window.__st.editors() === 1"
      . " && !!island && island.classList.contains('daedalus-stylish-island') && !!island.querySelector('.cm-editor') && island.contains(document.activeElement); })()",
      20000,
      'Picking not-last-child minted the `&:not(:last-child)` condition row under the `> *` row with its own island live and focused (the one editor on the tree; the first-child leg says why the focus is checked on the island itself).',
    );
    $before_fact = $band_computed('minWidth');
    $session->executeScript("document.execCommand('insertText', false, 'min-width: 313px;');");
    $this->assertJsCondition(
      "(" . $map . ")[" . json_encode($children_key) . "]?.['not-last-child']?.['min-width'] === '313px'",
      20000,
      'The declaration committed under the not-last-child condition at the children key.',
    );
    $this->assertJsCondition(
      "window.__rel.computed(\"{$band_selectors['h3']}\", 'minWidth') === '313px'",
      20000,
      'The third child computes the negated fact row\'s min-width.',
    );
    $after_fact = $band_computed('minWidth');
    $this->assertSame('313px', $after_fact['p1'], 'The first child computes it.');
    $this->assertSame('313px', $after_fact['p2'], 'The second child computes it.');
    $this->assertSame($before_fact['img'], $after_fact['img'], 'The last child keeps its prior min-width: the browser\'s :not(:last-child) on the printed bare wrapper.');
    $this->assertNotSame('313px', $after_fact['img'], 'The last child does not compute the fact row\'s literal.');

    // The second paragraph selected in Advanced mode: its Spacing
    // section's Margin top row reads the sibling rule through the
    // block's stack (the matcher's sibling walk lights the ancestor
    // layer on this block alone), the token as the row's value and the
    // mark titled by originOf's ancestor sentence: the token's face, the
    // label-less section's name and the selector literal (`>` unescaped,
    // the sentence being a title attribute).
    $session->executeScript("Drupal.Daedalus.Selection.select('block:$band_p2');");
    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-inspector__ui-mode")]/button[normalize-space() = "Advanced"]');
    $this->assertJsCondition(
      "(() => { const active = document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]');"
      . " return !!active && active.textContent.trim() === 'Advanced'; })()",
      20000,
      'The inspector is in Advanced mode on the second paragraph.',
    );
    $margin_top_field = "#daedalus_stylish_inspector details.daedalus-stylish-inspector__section [aria-label='Margin top']";
    $margin_top_row = 'document.querySelector(' . json_encode($margin_top_field) . ")?.closest('.daedalus-stylish-inspector__property')";
    $this->assertJsCondition(
      "(() => { const field = document.querySelector(" . json_encode($margin_top_field) . "); return !!field && field.value === 'var(--space-8)'; })()",
      20000,
      'The Margin top row reads the sibling rule\'s token as its value: the block\'s stack carries the section\'s `> p + p` layer.',
    );
    $space_8_face = (string) $session->evaluateScript("document.querySelector(" . json_encode($margin_top_field) . ").selectedOptions?.[0]?.textContent.trim() ?? ''");
    $mark_title = (string) $session->evaluateScript("($margin_top_row)?.querySelector('.daedalus-stylish-inspector__mark')?.title ?? ''");
    $this->assertSame(
      "$space_8_face inherited from Section (> p + p)",
      $mark_title,
      'The Margin top mark is titled by the ancestor sentence: the token\'s face (the row\'s own option text), the label-less section, and the `> p + p` literal with its `>` unescaped.',
    );
    $this->assertNotSame(
      '',
      (string) $session->evaluateScript("($margin_top_row)?.style.getPropertyValue('--daedalus-stylish-origin').trim() ?? ''"),
      'The Margin top row is hued for the ancestor layer.',
    );

    // ----- A rule's worn variant on the Page lens. -----
    // Seam: ONE apply_variant at the band's `pb-text` rule (the op
    // spine; the landing pairing and the delivery are Kernel-pinned)
    // -> the rule-wear lane printing the variant's link fragment inside
    // the band's @scope (the linked paragraph's anchor computes it) ->
    // the code view's roster reading the stack's enumeration
    // (element-model.js elementIslands over selectorSuffixes), so the
    // band's tree row gains the continued position
    // `pb-text .text-block-link` that no map entry holds: it exists
    // because the variant delivers there. Its lines print delivered
    // in the variant's hue, each titled by the variant
    // (on the owner's own continued position the layer is the
    // rule-carried variant tier), and the band is NOT selected while
    // the Page lens lists it: the roster is the map's, never the
    // selection's. The rule position itself, `pb-text`, holds the
    // reference and no line of its own.
    $worn_rule_key = "$band_key::pb-text";
    $worn_position = 'pb-text .text-block-link';
    $band_anchor = "{$text_selector($band_p2)} a.text-block-link";
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'apply_variant', key: '$worn_rule_key', variant: 'guide_button_primary' },
          ],
        })).catch((error) => {
          window.__wearError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__wearError !== undefined"
      . ' || JSON.stringify((' . $map . ')[' . json_encode($worn_rule_key) . "]?.variants ?? []) === " . json_encode(json_encode([['variant' => 'guide_button_primary', 'fragment' => '']])),
      20000,
      'The apply_variant at the band\'s pb-text rule resolved: the map carries Primary\'s reference on the RULE\'s entry.',
    );
    $wear_error = $session->evaluateScript('window.__wearError ?? null');
    $this->assertNull($wear_error, 'Wearing Primary at the rule failed: ' . ($wear_error ?? ''));
    $this->assertJsCondition(
      "window.__rel.computed(\"$band_anchor\", 'backgroundColor') === " . json_encode($accent),
      20000,
      'The linked paragraph\'s anchor computes Primary\'s accent background: the rule delivers the variant\'s link fragment by descent inside the band\'s @scope.',
    );
    $this->assertSame(
      NULL,
      json_decode((string) $session->evaluateScript('JSON.stringify((' . $map . ')[' . json_encode("$band_key::$worn_position") . '] ?? null)'), TRUE),
      'The map holds NO entry at the continued position: the row below exists because the variant delivers there, not because the map does.',
    );
    $session->executeScript('Drupal.Daedalus.Selection.clear();');
    $this->clickPanelXpath('//button[contains(@class, "daedalus-stylish-inspector__ui-mode-button--code")]');
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === null && document.querySelector('.daedalus-stylish-page-tree__showing') !== null",
      20000,
      'The code body opened with nothing selected.',
    );
    $session->executeScript("window.__st.clickScope('page');");
    $worn_header_js = 'document.querySelector(' . json_encode(".daedalus-stylish-page-tree [data-daedalus-stylish-part-owner=\"$band_key\"][data-daedalus-stylish-part=\"$worn_position\"]") . ')';
    $worn_lines_js = "Array.from(($worn_header_js)?.nextElementSibling?.querySelectorAll('.daedalus-stylish-island__line') ?? [])";
    $this->assertJsCondition(
      "window.__st.scope() === 'page' && Drupal.Daedalus.Selection.getSelected() === null"
      . " && window.__st.rows().includes(" . json_encode($band_key) . ')'
      . " && !!$worn_header_js"
      . " && ($worn_header_js).querySelector('.daedalus-stylish-islands__prelude')?.textContent === " . json_encode($worn_position)
      . " && ($worn_header_js).nextElementSibling?.classList.contains('daedalus-stylish-islands__part-body')",
      20000,
      'The Page lens lists the band\'s row with the continued position `pb-text .text-block-link` as a part row (the rule-wear continuation, first in the roster\'s delivery order), the band unselected, its body beneath the header.',
    );
    $this->assertJsCondition(
      "(() => { const lines = $worn_lines_js;"
      . ' return lines.length >= 15'
      . " && lines.every((line) => line.classList.contains('daedalus-stylish-island__line--delivered')"
      . "   && line.style.getPropertyValue('--daedalus-stylish-line-hue').trim() === 'hsl(304 70% 60%)'"
      . "   && line.title === 'Supplied by the variant \"Primary\".'); })()",
      20000,
      'Every line under the continued position prints as a delivered line carrying Primary\'s hue (the same djb2 hue an own wearing of it gets), titled by the variant: the position\'s stack lists the rule-carried reference as the variant tier.',
    );
    $this->assertSame(
      ['background-color: var(--color-accent);', 'color: var(--color-accent-contrast);', 'background-color: var(--color-accent-hover);'],
      json_decode((string) $session->evaluateScript(
        "JSON.stringify(($worn_lines_js).map((line) => line.textContent.trim()).filter((text) => text.startsWith('background-color:') || text.startsWith('color:')).map((text) => text.slice(0, text.indexOf(';') + 1)))"
      ), TRUE),
      'The printed lines are Primary\'s link fragment in the variant\'s own order: the default island\'s accent background and contrast color, then the hover condition island\'s accent-hover background (each line read up to the semicolon: a chip may follow). The position\'s every condition is an island of the row, as it is for an element\'s own wear.',
    );
    $this->assertSame(
      0,
      (int) $session->evaluateScript("($worn_header_js).nextElementSibling.querySelectorAll('.cm-editor').length"),
      'The worn position prints, no editor live: a delivered row reads like any other until woken.',
    );

    // ----- An ancestor rule seated from the chip, in code mode. -----
    // Seam: the subject line's chip (the write-address switcher, one
    // spine target) -> the code view printing the seated rule as an
    // editable island of its own under the selected element's rows
    // (DevTools' rule: the element's own islands, then one island per
    // ancestor rule reaching it), lit under the inspector's address ->
    // a typed declaration landing at the ANCESTOR key -> the own islands
    // carrying no line from that rule (the ancestor tier prints as rule
    // islands, never as floor lines of the own islands). The linked
    // paragraph selected at Element scope: its row alone lists, the chip
    // reads "This element" at its bare key, and its menu offers the
    // band's mintable `> pb-text` rule as "Text blocks directly in the
    // section" (the band carries no label), hinted "new rule": no map
    // entry holds it yet.
    $p2_key = "block:$band_p2";
    $seated_rule_key = "$band_key::> pb-text";
    $address_chip = '#daedalus_stylish_inspector .daedalus-stylish-inspector__address';
    $menu_items_js = "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".map((item) => [item.querySelector('.daedalus-ui-menu__label')?.textContent.trim(), item.querySelector('.daedalus-ui-menu__hint')?.textContent.trim() ?? null, item.getAttribute('aria-checked')])";
    $own_lines_js = "Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .cm-line, .daedalus-stylish-page-tree .daedalus-stylish-island__line')).filter((line) => !line.closest('.daedalus-stylish-islands__part-body'))";
    $session->executeScript("Drupal.Daedalus.Selection.select('$p2_key');");
    $session->executeScript("window.__st.clickScope('element');");
    $this->assertJsCondition(
      "window.__st.scope() === 'element' && JSON.stringify(window.__st.rows()) === " . json_encode(json_encode([$p2_key]))
      . " && window.__st.editors() === 0"
      . " && (() => { const chip = document.querySelector('$address_chip'); return !!chip"
      . '   && chip.dataset.daedalusStylishAddress === ' . json_encode($p2_key)
      . "   && chip.querySelector('.daedalus-stylish-inspector__address-label')?.textContent.trim() === 'This element'"
      . "   && !chip.classList.contains('daedalus-stylish-chip--lit'); })()",
      20000,
      'The linked paragraph lists alone at Element scope in code mode, no editor live, the chip at its bare key reading "This element", unlit.',
    );
    $this->assertNull(
      $session->evaluateScript("window.__rel.header('> pb-text')"),
      'Before the seat no `> pb-text` rule row prints under the paragraph: the rule exists nowhere yet.',
    );
    $this->clickPanelXpath('//button[contains(@class, "daedalus-stylish-inspector__address")]');
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu') && document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')?.textContent.trim() === 'Style this at'",
      20000,
      'The chip opened the shared menu headed "Style this at" in code mode.',
    );
    $menu_items = json_decode((string) $session->evaluateScript("JSON.stringify($menu_items_js)"), TRUE);
    $this->assertSame(['This element', NULL, 'true'], $menu_items[0], 'The menu leads with the own key, checked.');
    $this->assertContains(['Text blocks directly in Section', 'new rule', 'false'], $menu_items, 'The menu offers the band\'s mintable `> pb-text` rule, hinted "new rule".');
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Text blocks directly in Section').click();"
    );
    // The pick seats the rule as the styling target (the selection stays
    // on the paragraph), lights the chip at the rule's key, and the code
    // view mounts the rule's row under the paragraph's own rows: a
    // header carrying the band's key and the rule's selector, lit
    // (--active, aria-pressed), worded as the chip's entry, its body
    // holding the mintable rule's empty island live and focused (the one
    // editor on the tree), so the seat is one move from the first
    // declaration.
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu')"
      . " && Drupal.Daedalus.Selection.getSelected() === " . json_encode($p2_key)
      . " && Drupal.Daedalus.Selection.getTarget()?.key === " . json_encode($band_key)
      . " && Drupal.Daedalus.Selection.getTarget()?.selector === '> pb-text'"
      . " && (() => { const chip = document.querySelector('$address_chip'); return !!chip"
      . '   && chip.dataset.daedalusStylishAddress === ' . json_encode($seated_rule_key)
      . "   && chip.querySelector('.daedalus-stylish-inspector__address-label')?.textContent.trim() === 'Text blocks directly in Section'"
      . "   && chip.classList.contains('daedalus-stylish-chip--lit'); })()",
      20000,
      'The pick closed the menu, seated the band\'s `> pb-text` rule as the styling target with the selection still on the paragraph, and lit the chip at container::> pb-text.',
    );
    $this->assertJsCondition(
      "(() => { const header = window.__rel.header('> pb-text'); const facts = window.__rel.facts('> pb-text'); if (!header || !facts) { return false; }"
      . " return header.classList.contains('daedalus-stylish-islands__part--rule') && header.classList.contains('daedalus-stylish-islands__part--active')"
      . "   && header.querySelector('.daedalus-stylish-islands__part-select')?.getAttribute('aria-pressed') === 'true'"
      . "   && header.querySelector('.daedalus-stylish-islands__part-label')?.textContent === 'Text blocks directly in Section'"
      . "   && facts.code === '> pb-text' && facts.chips === 1"
      . "   && facts.liveInBody === 1 && facts.focusedInBody && window.__st.editors() === 1; })()",
      20000,
      'The seated rule mounted as a lit rule row under the paragraph (the band\'s key and `> pb-text` on the header, --active and aria-pressed, worded "Text blocks directly in Section" with the one `>` chip) with its empty island live and focused, the one editor on the tree.',
    );
    $this->assertSame(
      NULL,
      json_decode((string) $session->evaluateScript('JSON.stringify((' . $map . ')[' . json_encode($seated_rule_key) . '] ?? null)'), TRUE),
      'The map holds no entry at the seated rule yet: the island is the placement\'s virtual layer, minted by its first write.',
    );
    // A color typed into the seated island commits at the ANCESTOR key
    // (the rule's own key, the selection untouched) and lands on the
    // band's text blocks: the unlinked first paragraph and the heading
    // compute the muted color (the linked paragraph is not read: the
    // printer flattens specificity, so between its `:has(a)` sibling
    // rule and this one the emission order decides, not this seam), and
    // the image block keeps its color, the type compound naming text
    // blocks alone. The line prints in the rule's island alone: the
    // paragraph's own islands carry no line from the rule, since T4 the
    // ancestor tier is rule islands, never floor lines.
    $before_seated = $band_computed('color');
    $this->assertNotSame($muted, $before_seated['p1'], 'Before the write the first paragraph does not wear the muted color (the control).');
    $session->executeScript("document.execCommand('insertText', false, 'color: var(--color-muted);');");
    $this->assertJsCondition(
      "(" . $map . ")[" . json_encode($seated_rule_key) . "]?.default?.color === 'var(--color-muted)'",
      20000,
      'The declaration committed at the ancestor key (container::> pb-text), minting the rule\'s entry.',
    );
    $this->assertSame(
      NULL,
      json_decode((string) $session->evaluateScript('JSON.stringify((' . $map . ')[' . json_encode($p2_key) . '] ?? null)'), TRUE),
      'The paragraph\'s own entry is untouched by the write at the rule.',
    );
    $this->assertJsCondition(
      "window.__rel.computed(\"{$band_selectors['p1']}\", 'color') === " . json_encode($muted),
      20000,
      'The rule lands on the band: the first paragraph computes the muted color typed at container::> pb-text.',
    );
    $after_seated = $band_computed('color');
    $this->assertSame($muted, $after_seated['h3'], 'The heading computes it too: the rule reaches every text block directly in the band.');
    $this->assertSame($before_seated['img'], $after_seated['img'], 'The image block keeps its prior color: the type compound names text blocks alone.');
    $this->assertJsCondition(
      "(() => { const header = window.__rel.header('> pb-text'); if (!header) { return false; }"
      . " const own = ($own_lines_js).filter((line) => line.textContent.includes('color: var(--color-muted)'));"
      . " const inRule = Array.from(header.nextElementSibling.querySelectorAll('.cm-line, .daedalus-stylish-island__line')).filter((line) => line.textContent.includes('color: var(--color-muted)'));"
      . " return own.length === 0 && inRule.length === 1 && Drupal.Daedalus.Selection.getTarget()?.selector === '> pb-text'; })()",
      20000,
      'The declaration prints once, in the seated rule\'s island; the paragraph\'s own islands carry no line from the rule, and the target still stands on the rule after the write.',
    );

    // ----- A generated box typed on the root island, born with its seed. -----
    // Seam: the CodeMirror gesture on the element's own island (`&::before`
    // then the swallowed brace, element-model.js bracedSelector) -> the
    // drafted box row with its empty island live and focused -> the first
    // typed declaration committing ONE update_style at the box key -> the
    // op spine's birth seed landing `content: ""` beneath it in the same
    // op -> the authoritative refresh splicing the server-born line into
    // the LIVE buffer above the user's text (island.js unseenOwnLines),
    // so the seed prints as the island's first line and the park's settle
    // diffs an honest buffer instead of unsetting it -> the box computing
    // on the band. The node specs pin the gesture, the mint and the
    // splice's model; only a browser runs the gesture through the
    // completion machinery, the commit, the refresh under the cursor and
    // the blur's settle in order. The band's island is the root island
    // here (the anchor is the owner's own box). guide_example declares no
    // `pseudo:` section, so under its strict law the first write is the
    // server's STYLE_PSEUDO_NOT_DECLARED refusal (Kernel-pinned; the code
    // view's offer is universal and never reads the law): the system is
    // opened server-side first, no reload needed, since the grant is the
    // op's alone.
    $this->enforce('open');
    $this->assertSame(
      'open',
      \Drupal::service('daedalus_stylish.style_guide')->enforcement(),
      'The active system is open for the box leg: the server grants the undeclared box.',
    );
    $box_key = "$band_key::&::before";
    $band_before_js = "getComputedStyle(document.querySelector('[data-selectable=\"$band_key\"]'), '::before')";
    $box_lines_js = "Array.from(window.__rel.header('&::before')?.nextElementSibling?.querySelectorAll('.cm-line, .daedalus-stylish-island__line') ?? []).map((line) => line.textContent.trim())";
    $box_lines = json_encode(json_encode(['content: "";', 'background-color: var(--color-accent);']));
    // A user reaches the band by a canvas click, whose blur parks the
    // live rule island before the selection moves; through the facade
    // the park is explicit. Without it the focused island's cursor is
    // remembered under its id (container::> pb-text|default) and the
    // band's own `> pb-text` row, mounting under the same id, claims it
    // and wakes live (the stitching registry's re-render seam), which is
    // not the posture this leg starts from.
    $session->executeScript('document.activeElement.blur();');
    $this->assertJsCondition(
      "window.__st.editors() === 0 && Drupal.Daedalus.Selection.getTarget()?.selector === '> pb-text'",
      10000,
      'The blur parked the seated rule\'s island: zero editors, the target still standing on the rule.',
    );
    $session->executeScript("Drupal.Daedalus.Selection.select('$band_key');");
    $this->assertJsCondition(
      "window.__st.scope() === 'element' && JSON.stringify(window.__st.rows()) === " . json_encode(json_encode([$band_key]))
      . " && Drupal.Daedalus.Selection.getTarget() === null && window.__st.editors() === 0"
      . " && window.__rel.header('&::before') === null && !!window.__sel.rootIsland()",
      20000,
      'The band lists alone at Element scope with no target (the select dropped the rule) and no editor live, no box row yet, its root island printing the skin gap floor line.',
    );
    $session->executeScript("window.__sel.wakeAtEnd(window.__sel.rootIsland());");
    $this->assertJsCondition(
      "window.__st.editors() === 1 && window.__sel.rootIsland().querySelectorAll('.cm-editor').length === 1",
      10000,
      'The band\'s root island woke for the box gesture.',
    );
    $session->executeScript("document.execCommand('insertText', false, '\\n&::before ');");
    $session->executeScript("document.execCommand('insertText', false, '{');");
    $this->assertJsCondition(
      "(() => { const facts = window.__rel.facts('&::before'); if (!facts) { return false; }"
      . " const label = window.__rel.header('&::before').querySelector('.daedalus-stylish-islands__part-label')?.textContent;"
      . " return label === 'Before' && facts.code === '&::before' && facts.chips === 0"
      . "   && facts.liveInBody === 1 && facts.focusedInBody && window.__st.editors() === 1; })()",
      20000,
      'Typing `&::before {` on the root island minted the box row (the brace swallowed with the anchor): labelled "Before", its code face the anchor spelling with no combinator chip, its empty island live and focused as the one editor.',
    );
    $this->assertSame(
      NULL,
      json_decode((string) $session->evaluateScript('JSON.stringify((' . $map . ')[' . json_encode($box_key) . '] ?? null)'), TRUE),
      'The mint wrote nothing: the map holds no entry at the box key until the first declaration.',
    );
    $this->assertJsCondition(
      "!($own_lines_js).some((line) => line.textContent.includes('&::before'))",
      10000,
      'The typed anchor left the root island with the brace: no `&::before` text remains among the own lines.',
    );
    $session->executeScript("document.execCommand('insertText', false, 'background-color: var(--color-accent);');");
    $this->assertJsCondition(
      '(' . $map . ')[' . json_encode($box_key) . "]?.default?.['background-color'] === 'var(--color-accent)'"
      . ' && (' . $map . ')[' . json_encode($box_key) . "]?.default?.content === '\"\"'",
      20000,
      'The declaration committed at the box key, and the entry was born with the op spine\'s seed, `content: ""` at default, in the same op.',
    );
    $this->assertJsCondition(
      "window.__st.editors() === 1 && window.__rel.facts('&::before')?.liveInBody === 1"
      . " && JSON.stringify($box_lines_js) === $box_lines",
      20000,
      'The seed joined the LIVE buffer above the typed line: the box island, still the one editor, reads `content: "";` then the background (the refresh spliced the server-born own line in under the cursor).',
    );
    $this->assertJsCondition(
      "($band_before_js).content === '\"\"' && ($band_before_js).backgroundColor === " . json_encode($accent),
      20000,
      'The band\'s generated box computes on the canvas: the seeded content makes it exist and it wears the accent.',
    );
    // The park (a wake on the root island blurs the box island): the
    // blur's settle diffs the spliced buffer against the truth, so
    // nothing commits, the seed stands, and the row prints both lines.
    $session->executeScript("window.__sel.wakeAtEnd(window.__sel.rootIsland());");
    $this->assertJsCondition(
      "window.__st.editors() === 1 && window.__rel.facts('&::before')?.liveInBody === 0"
      . " && JSON.stringify($box_lines_js) === $box_lines",
      20000,
      'The root island\'s wake parked the box island back to print with both lines, the one editor on the root.',
    );
    $this->assertSame(
      ['default' => ['content' => '""', 'background-color' => 'var(--color-accent)']],
      json_decode((string) $session->evaluateScript('JSON.stringify((' . $map . ')[' . json_encode($box_key) . '] ?? null)'), TRUE),
      'The park\'s settle unset nothing: the seed survived the blur (before the splice the missing line read as a deletion and the box vanished on park).',
    );
    $this->assertJsCondition(
      "($band_before_js).content === '\"\"'",
      10000,
      'The box still exists on the canvas after the park.',
    );

    // ----- The paste door, end to end. -----
    // Seam: the ClipboardEvent on the live island's content ->
    // paste-door.js structuralChunks refusing the insertion (the text
    // has structure, so it never enters the buffer) -> the source's
    // parseCss POST to the parse-css route (one parser, parseStyleBlock,
    // under the island's key at its condition) -> the answered entries
    // as ONE update_style batch through commitBatch (one history entry,
    // labelled by the count) -> the authoritative refresh splicing the
    // bare declaration into the LIVE root buffer and materializing the
    // `&:hover` condition row with its printed island beside it. The
    // band's root island is the one live editor from the box leg's
    // wake; the text pairs a bare declaration with a nested state block,
    // so the answer carries an entry at the island's own condition and
    // one at hover, and the chunk never stays as text.
    $band_map = "((" . $map . ")[" . json_encode($band_key) . "] ?? {})";
    $history_js = 'Drupal.Daedalus.History.getState()';
    $pasted = json_decode((string) $session->evaluateScript(<<<'JS'
      (() => {
        const island = window.__sel.rootIsland();
        const content = island.querySelector('.cm-content');
        const transfer = new DataTransfer();
        transfer.setData('text/plain', 'padding-top: var(--space-4);\n&:hover { color: var(--color-accent); }');
        const event = new ClipboardEvent('paste', { bubbles: true, cancelable: true, clipboardData: transfer });
        const before = Drupal.Daedalus.History.getState().entries.length;
        content.dispatchEvent(event);
        return JSON.stringify({ taken: event.defaultPrevented, before, live: !!island.querySelector('.cm-editor') });
      })()
      JS), TRUE);
    $this->assertIsArray($pasted, 'The paste step returned its facts.');
    $this->assertTrue($pasted['live'], 'The band\'s root island was live for the paste (the box leg\'s wake).');
    $this->assertTrue($pasted['taken'], 'The door took the paste: the ClipboardEvent was prevented, so the structured text never entered the buffer.');
    $this->assertJsCondition(
      $band_map . "?.default?.['padding-top'] === 'var(--space-4)'"
      . " && " . $band_map . "?.hover?.color === 'var(--color-accent)'",
      20000,
      'The door\'s answer landed as update_style ops: the bare declaration at the band\'s default condition and the nested block\'s color at hover.',
    );
    $this->assertJsCondition(
      "$history_js.entries.length === " . ((int) $pasted['before'] + 1)
      . " && $history_js.entries[$history_js.entries.length - 1].label === 'Pasted 2 declarations'",
      20000,
      'The two entries committed as ONE batch: exactly one history entry more, labelled "Pasted 2 declarations".',
    );
    $hover_row_js = "Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .daedalus-stylish-islands__condition'))"
      . ".filter((row) => !row.closest('.daedalus-stylish-islands__part-body'))"
      . ".find((row) => row.querySelector('.daedalus-stylish-islands__prelude')?.textContent === '&:hover') ?? null";
    $this->assertJsCondition(
      "(() => { const row = $hover_row_js; const island = row?.nextElementSibling; if (!row || !island) { return false; }"
      . " const lines = Array.from(island.querySelectorAll('.daedalus-stylish-island__line')).map((line) => line.textContent.trim());"
      . " const run = island.querySelector('.daedalus-stylish-island__run');"
      . " return island.classList.contains('daedalus-stylish-island') && !!island.querySelector('.daedalus-stylish-island__print')"
      . "   && lines.some((text) => text.startsWith('color: var(--color-accent);'))"
      . "   && !!run && run.dataset.layer === 'element' && run.textContent.trim() === 'this element'; })()",
      20000,
      'The paste materialized the `&:hover` condition row under the band\'s root (its prelude spelled as nested CSS) with its island printed: the pasted color on a line under the "this element" run label.',
    );
    $this->assertJsCondition(
      "(() => { const island = window.__sel.rootIsland(); const text = island?.textContent ?? '';"
      . " return !!island && window.__st.editors() === 1 && text.includes('padding-top: var(--space-4);') && !text.includes('&:hover') && !text.includes('{'); })()",
      20000,
      'The root island stayed the one live editor, its buffer holding the spliced padding line and no trace of the nested block: structure is chrome, the chunk left as a gesture.',
    );

    // ----- Copy CSS: the route's print reaches the clipboard in the gesture. -----
    // Seam: the header's Copy CSS -> the source's readCss GET of the read
    // route at mode=css for the write target -> a promise-backed
    // ClipboardItem handed to navigator.clipboard.write inside the click
    // -> the "CSS copied" flash on the button's own tooltip. Webdriver
    // grants no clipboard permission, so the write is stubbed by
    // injected script; the fetch and the print are real. The test origin
    // is plain http (no secure context), where the browser exposes
    // neither navigator.clipboard nor ClipboardItem, so the stub mounts
    // the clipboard itself with both roads: writeText, the fallback the
    // gesture takes without ClipboardItem (the road this run drives),
    // and write, resolving the item's text as the platform would (the
    // secure-origin road, a hand pass). The band is the selected element
    // with no target, so the write key is its own and the print carries
    // its own entry beside its rules.
    $session->executeScript(<<<'JS'
      (() => {
        window.__copied = null;
        const clipboard = navigator.clipboard ?? {};
        clipboard.writeText = (text) => {
          window.__copied = text;
          return Promise.resolve();
        };
        clipboard.write = (items) => items[0].getType('text/plain')
          .then((blob) => blob.text())
          .then((text) => { window.__copied = text; });
        if (!navigator.clipboard) {
          Object.defineProperty(navigator, 'clipboard', { value: clipboard, configurable: true });
        }
      })();
      JS);
    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-inspector__actions")]//button[@aria-label="Copy CSS"]');
    $this->assertJsCondition(
      "typeof window.__copied === 'string'"
      . " && document.querySelector('.daedalus-stylish-inspector__actions .daedalus-ui-tooltip-host--show .daedalus-ui-tooltip')?.textContent.trim() === 'CSS copied'",
      20000,
      'The Copy CSS gesture wrote the route\'s text to the (stubbed) clipboard and flashed "CSS copied" on its own button.',
    );
    $copied = (string) $session->evaluateScript('window.__copied');
    $this->assertStringContainsString("#$band_uuid", $copied, 'The copied text addresses the band by its uuid: the dialect\'s id, never an alias.');
    $this->assertStringContainsString('padding-top: var(--space-4);', $copied, 'The band\'s own default entry prints as a rule (the pasted padding among its declarations).');
    $this->assertStringContainsString('margin-top: var(--space-8);', $copied, 'The band\'s `> p + p` rule prints flat beside the element\'s own rule.');
    $this->assertStringContainsString(':hover', $copied, 'The hover condition prints as a real CSS state on its rule.');
    $this->assertStringContainsString('/* Section', $copied, 'The print leads with the element\'s words as a comment (the naming spine, annotated on the read).');
    $this->assertStringNotContainsString('&', $copied, 'The server prints flat rules: nesting is sugar on parse only.');

    // ----- A run label's doorway: the menu opens the variant's session. -----
    // Seam: the run widget's contextmenu (the label is a block outside
    // CodeMirror's event ownership, so it bubbles out of the print to the
    // island's handler) -> the shared menu headed by the run's word with
    // the layer's doorways -> the Inspector's openDocument -> the session
    // frame over the variant's document (VariantDocumentView: the root
    // slot's header and the variant's line printed in its island). The
    // variant is minted here from block B's own look (save_material, the
    // panel's Save variant capture) and worn by block B itself, because
    // the frame mounts only over an honest wearing of the anchor
    // (frameMounted needs editedWearing): block B's island then prints
    // the variant's line struck by its own equal value, under the run
    // label worded by the variant. Not driven: the skin item opens a
    // window; the declared item is inert by design; the ancestor-rule
    // seat has no run to hang on (own islands drop the ancestor tier,
    // sparse rows build no tree), recorded in the working notes for a
    // ruling. A variant a RULE carries (Primary on the band's continued
    // position) is the scenario's last leg: its doorway anchors at the
    // carrier rule.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'save_material',
            kind: 'variant',
            label: 'Door smoke',
            key: '$block_b_key',
            styles: { '': { default: { 'background-image': '$gradient_b' } } },
          }],
          label: 'Saved variant Door smoke',
        }))
          .then((payload) => {
            const minted = (payload.materials?.upsert ?? []).find((record) => record.kind === 'variant');
            if (!minted) {
              throw new Error(JSON.stringify(payload));
            }
            return Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
              operations: [{ op: 'apply_variant', key: '$block_b_key', variant: minted.id }],
            }));
          })
          .catch((error) => {
            window.__doorSmokeError = error.message;
          });
      })();
      JS);
    $this->assertJsCondition(
      "window.__doorSmokeError !== undefined || JSON.stringify((" . $map . ")[" . json_encode($block_b_key) . "]?.variants ?? []).includes('door_smoke')",
      20000,
      'Block B wears the variant minted from its own look ("Door smoke", machine name door_smoke).',
    );
    $this->assertNull($session->evaluateScript('window.__doorSmokeError ?? null'), 'Minting or wearing Door smoke failed: ' . ($session->evaluateScript('window.__doorSmokeError ?? null') ?? ''));
    $door_run_js = "Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .daedalus-stylish-island__run'))"
      . ".find((run) => run.dataset.layer === 'variant:door_smoke') ?? null";
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_b_key');");
    $this->assertJsCondition(
      "window.__st.scope() === 'element' && JSON.stringify(window.__st.rows()) === " . json_encode(json_encode([$block_b_key]))
      . " && window.__st.editors() === 0 && ($door_run_js)?.textContent.trim() === 'Door smoke'",
      20000,
      'Block B lists alone at Element scope, no editor live, its island printing the worn variant\'s run label worded "Door smoke".',
    );
    // The scroll rides its own round trip and the dispatch waits two
    // rendered frames: the floating surface leaves on an outside scroll,
    // and headless Chrome may dispatch the scroll event of round trip 1
    // at a rendering opportunity AFTER round trip 2 opened the menu,
    // closing it at once (seen twice before the frame gate was added).
    $session->executeScript(
      "($door_run_js).scrollIntoView({ block: 'center', behavior: 'instant' });"
      . " window.__scrollSettled = false; requestAnimationFrame(() => requestAnimationFrame(() => { window.__scrollSettled = true; }));"
    );
    $this->assertJsCondition('window.__scrollSettled === true', 10000, 'Two frames rendered after the scroll to the run label (its scroll event has fired).');
    $session->executeScript(<<<JS
      (() => {
        const run = $door_run_js;
        const rect = run.getBoundingClientRect();
        run.dispatchEvent(new MouseEvent('contextmenu', {
          bubbles: true,
          cancelable: true,
          clientX: rect.left + 4,
          clientY: rect.top + rect.height / 2,
        }));
      })();
      JS);
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu') && document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')?.textContent.trim() === 'Door smoke'"
      . " && ($menu_items_js).some(([label]) => label === 'Edit Door smoke')",
      20000,
      'Right-clicking the run label opened the shared menu headed by the run\'s word, "Door smoke", offering the variant doorway "Edit Door smoke".',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Edit Door smoke').click();"
    );
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu')"
      . " && !!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector--session')"
      . " && document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-session__label')?.textContent.trim() === 'Door smoke'"
      . " && document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-document .daedalus-stylish-document__header .daedalus-stylish-document__kind')?.textContent === 'Root'"
      . " && Array.from(document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-document .daedalus-stylish-island__line')).some((line) => line.textContent.startsWith('background-image: linear-gradient(90deg'))",
      20000,
      'The pick closed the menu and opened the session frame labelled "Door smoke" over the variant\'s document: the root slot\'s header and the variant\'s own line printed in its island. The run label is a handle to the variant\'s document.',
    );
    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-session__top")]//button[contains(@class, "daedalus-stylish-session__back")]');
    $this->assertJsCondition(
      "!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector--session')"
      . " && !!document.querySelector('.daedalus-stylish-page-tree__showing')",
      20000,
      'The session\'s back control parked the frame and the code body is back with its showing row.',
    );

    // ----- The satellite's row words, cold. -----
    // Seam: the naming spine's `name` (the section's label, minted by
    // the annotator) -> style-guide.js layoutTree carrying it into the
    // pared tree -> page-model.js rowWords -> the row's kind and name
    // spans, in the leader and in a satellite mounted AFTER the rename
    // with no relay of it (its pared tree arrives with the snapshot, so
    // the words are a pure function of broadcastable data). The first
    // section is renamed through the op spine's update_element (its
    // label property), then read in both windows.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{ op: 'update_element', element: '$section_uuid', properties: { label: 'Welcome' } }],
        })).catch((error) => {
          window.__renameError = error.message;
        });
      })();
      JS);
    $row_words_js = 'Array.from(document.querySelectorAll(' . json_encode(".daedalus-stylish-page-tree [data-daedalus-stylish-page-key=\"$container_key\"]") . '))'
      . ".map((header) => [header.querySelector('.daedalus-stylish-page-tree__kind')?.textContent.trim() ?? null, header.querySelector('.daedalus-stylish-page-tree__name')?.textContent.trim() ?? null])";
    $section_words = json_encode(json_encode([['Section', 'Welcome']]));
    $session->executeScript("Drupal.Daedalus.Selection.select('$container_key');");
    $this->assertJsCondition(
      "window.__renameError !== undefined || (window.__st.scope() === 'element' && JSON.stringify($row_words_js) === $section_words)",
      20000,
      'The leader\'s tree row reads the renamed section as "Section" + "Welcome" at Element scope: the type word and the minted name, no machine id.',
    );
    $this->assertNull($session->evaluateScript('window.__renameError ?? null'), 'The rename failed: ' . ($session->evaluateScript('window.__renameError ?? null') ?? ''));
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
      'The pop-out mounted again for the row-words read.',
    );
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-stylish-satellite__banner') && JSON.stringify($row_words_js) === $section_words",
      20000,
      'The satellite\'s row reads "Section" + "Welcome" cold: layoutTree carries the naming spine\'s name into the pared tree the pop-out renders from, no relay of the rename involved.',
    );
    $session->switchToIFrame(NULL);
    $session->executeScript("document.querySelector('iframe[name=\"daedalus_stylish_satellite\"]').remove();");

    // ----- A rule-carried run anchors its doorway at the carrier. -----
    // Seam: the run label on a CONTINUED position (Primary, worn at the
    // band's `pb-text` rule, printed on the `pb-text .text-block-link`
    // part row) -> line-menu.js runMenuItems reading the layer record's
    // carrier off the position's stack (style-stack.js mapLayers names
    // the rule as the carrier of every reference it delivers) -> the
    // Inspector's openDocument anchored at the rule's key -> seatAnchor's
    // rule road (select the root, target the rule) making the rule the
    // wear key -> the session frame mounting over the rule's honest
    // wearing (frameMounted needs editedWearing at the wear key, and
    // the position itself wears nothing). Primary is guide_example's
    // built-in, so the frame carries the read-only notice, not a
    // document: the doorway is what is under test, the document's
    // print is the Door smoke leg's. Before the fix the run's anchor
    // was null, the session anchored at the selected key, which does
    // not wear Primary, and the pick did nothing visible.
    $session->executeScript("Drupal.Daedalus.Selection.select('$band_key');");
    $primary_run_js = "($worn_header_js)?.nextElementSibling?.querySelector('.daedalus-stylish-island__run[data-layer=\"variant:guide_button_primary\"]') ?? null";
    $this->assertJsCondition(
      "window.__st.scope() === 'element' && JSON.stringify(window.__st.rows()) === " . json_encode(json_encode([$band_key]))
      . " && !document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector--session')"
      . " && ($primary_run_js)?.textContent.trim() === 'Primary'",
      20000,
      'The band lists alone at Element scope with no frame mounted, its continued position `pb-text .text-block-link` printing Primary\'s run label worded by the variant.',
    );
    $session->executeScript(
      "($primary_run_js).scrollIntoView({ block: 'center', behavior: 'instant' });"
      . " window.__scrollSettled = false; requestAnimationFrame(() => requestAnimationFrame(() => { window.__scrollSettled = true; }));"
    );
    $this->assertJsCondition('window.__scrollSettled === true', 10000, 'Two frames rendered after the scroll to Primary\'s run label (its scroll event has fired).');
    $session->executeScript(<<<JS
      (() => {
        const run = $primary_run_js;
        const rect = run.getBoundingClientRect();
        run.dispatchEvent(new MouseEvent('contextmenu', {
          bubbles: true,
          cancelable: true,
          clientX: rect.left + 4,
          clientY: rect.top + rect.height / 2,
        }));
      })();
      JS);
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu') && document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')?.textContent.trim() === 'Primary'"
      . " && ($menu_items_js).some(([label]) => label === 'Edit Primary')",
      20000,
      'Right-clicking Primary\'s run label on the continued position opened the shared menu headed "Primary", offering the variant doorway "Edit Primary".',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Edit Primary').click();"
    );
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu')"
      . " && Drupal.Daedalus.Selection.getSelected() === " . json_encode($band_key)
      . " && Drupal.Daedalus.Selection.getTarget()?.key === " . json_encode($band_key)
      . " && Drupal.Daedalus.Selection.getTarget()?.selector === 'pb-text'"
      . " && !!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector--session')"
      // The header's document control is the switcher (a select, its
      // option "Primary · Button") when the selection wears more than
      // one document, else the plain label: read the name off whichever
      // renders, the control's choice being switcherRoster's business.
      . " && (document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-session__document select')?.selectedOptions[0]?.textContent.split(' · ')[0]"
      . "   ?? document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-session__label')?.textContent.trim()) === 'Primary'"
      . " && (document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector--session .daedalus-stylish-inspector__empty')?.textContent ?? '').includes('\"Primary\" is built-in and read-only')"
      . " && document.querySelector('$address_chip')?.dataset.daedalusStylishAddress === " . json_encode($worn_rule_key),
      20000,
      'The pick seated the band\'s `pb-text` rule as the target (the selection still the band, the chip at container::pb-text) and mounted the session frame labelled "Primary" over the rule\'s wearing, carrying the built-in\'s read-only notice: the run\'s doorway anchors at the carrier rule, never at the position the value lands on.',
    );
    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-session__top")]//button[contains(@class, "daedalus-stylish-session__back")]');
    $this->assertJsCondition(
      "!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector--session')"
      . " && !!document.querySelector('.daedalus-stylish-page-tree__showing')",
      20000,
      'The session\'s back control parked the frame opened from the rule-carried run and the code body is back with its showing row.',
    );

    // ——— Every element is a row: the bare rows. ———
    // Seam: page-model.js visibleTree annotates every node of the scoped
    // tree with `styled` and prunes nothing (the styled-only rule retired
    // 2026-09-27), page-view.js renders the flag as the row's BODY: a
    // styled row mounts its islands, an unstyled one is bare (the header
    // alone, the chevron's inert spacer where a foldable row has its
    // chevron, no island and no editor beneath) until it is selected,
    // when the honest edit surface mounts. The band's three text blocks
    // (p, p, h3) have no entry of their own (every write above landed at
    // the band's relational keys), so at Subtree over the band they are
    // the bare rows; the image block is born wearing guide_image_rounded
    // (guide_example's image block type default), and a worn reference IS
    // styling (isStyledElement's entryRefs half), so its row is not bare
    // and mounts its island, the band's row likewise.
    $band_children = json_encode([$band_key, "block:$band_p1", $p2_key, "block:$band_h3", "block:$band_img"]);
    $session->executeScript(<<<'JS'
      window.__st.rowFaces = () => Array.from(document.querySelectorAll('.daedalus-stylish-page-tree [data-daedalus-stylish-page-key]'))
        .map((header) => {
          const node = header.parentElement;
          return {
            key: header.dataset.daedalusStylishPageKey,
            bare: node.classList.contains('daedalus-stylish-page-tree__node--bare'),
            words: (header.querySelector('.daedalus-stylish-page-tree__kind')?.textContent ?? '').trim(),
            spacer: !!header.querySelector('.daedalus-stylish-page-tree__chevron-spacer'),
            chevron: !!header.querySelector('.daedalus-stylish-page-tree__chevron'),
            islands: node.querySelectorAll(':scope > .daedalus-stylish-page-tree__islands .daedalus-stylish-island').length,
            placeholders: node.querySelectorAll(':scope > .daedalus-stylish-page-tree__islands .daedalus-stylish-island__line--placeholder').length,
            editors: node.querySelectorAll('.cm-editor').length,
          };
        });
      JS);
    $session->executeScript("window.__st.clickScope('subtree');");
    $this->assertJsCondition(
      "window.__st.scope() === 'subtree'"
      . " && JSON.stringify(window.__st.rows()) === " . json_encode($band_children)
      . " && (() => { const faces = window.__st.rowFaces();"
      . "   const styled = [faces[0], faces[4]]; const bare = faces.slice(1, 4);"
      . "   return styled.every((face) => !face.bare && face.chevron && !face.spacer && face.islands > 0)"
      . "     && bare.every((face) => face.bare && face.words === 'Text' && face.spacer && !face.chevron && face.islands === 0 && face.editors === 0); })()",
      20000,
      'Subtree over the band prints the band\'s row and ALL four of its blocks\' rows in layout order: the band and the worn image block styled (chevron, islands), each unstyled text block a bare row reading its words with the chevron\'s spacer in its place and neither an island nor an editor beneath it.',
    );
    $session->executeScript("Drupal.Daedalus.Selection.select('block:$band_p1');");
    $this->assertJsCondition(
      "window.__st.scope() === 'subtree'"
      . " && JSON.stringify(window.__st.rows()) === " . json_encode(json_encode(["block:$band_p1"]))
      . " && (() => { const face = window.__st.rowFaces()[0];"
      . "   return !face.bare && face.chevron && !face.spacer && face.islands > 0 && face.editors === 0; })()"
      . " && document.querySelector('.daedalus-stylish-page-tree__header--selected')?.dataset.daedalusStylishPageKey === " . json_encode("block:$band_p1"),
      20000,
      'Selecting the bare first paragraph mounts its real islands (the Subtree now anchored at it: its row alone, no longer bare, the chevron back, the root island printed with the skin\'s kind:block floor lines delivered) with still no editor live: selection mounts islands, only a wake mounts an editor.',
    );

    // ——— The reveal. ———
    // Seam: the one reveal rule (daedalus_ui's Drupal.Daedalus.scrollReveal,
    // scroll-reveal-model.js revealBlock: the whole element in view,
    // centered in its nearest scrollport when it fits, its top edge under
    // the fixed chrome when it does not, unmoved when already in view)
    // through the browser's real scrollports and the canvas's computed
    // scroll-margin, facts only a browser computes. Three surfaces read
    // it: the code view's row (page-view.js's armed scroll on selection),
    // the page elements panel's row (its isSelected effect) and the
    // canvas from the code view's row click (leader-source.js select, a
    // reveal the code view never ran before the rule). The window is
    // shortened so the code view overflows at Page scope (the page
    // elements panel's twelve rows fit; its row is read inside its
    // scroller all the same); the selection is a
    // programmatic select (a canvas click's selection lands on the same
    // slice, and the two panel reveals key off the slice, not the
    // gesture), the canvas reveal a real click on the row's select.
    $session->resizeWindow(1920, 640);
    $session->executeScript(
      "Drupal.Daedalus.Selection.clear(); window.__st.clickScope('page');"
      . " if (document.getElementById('page_elements').classList.contains('daedalus-ui-hidden')) { Drupal.Daedalus.SidebarManager.openSidebar('page_elements'); }"
    );
    $band_row_js = "document.querySelector('.daedalus-stylish-page-tree [data-daedalus-stylish-page-key=" . json_encode($band_key) . "]')";
    $band_tree_row_js = "document.querySelector('#page-elements-tree .daedalus-ui-tree__row[data-tree-key=" . json_encode($band_key) . "]')";
    $this->assertJsCondition(
      "window.__st.scope() === 'page' && Drupal.Daedalus.Selection.getSelected() === null"
      . " && !!($band_row_js) && !!($band_tree_row_js)",
      20000,
      'Page scope with nothing selected: the band\'s row prints in the code view and in the page elements panel, the two rows the reveal will move.',
    );
    $session->executeScript(<<<JS
      window.__rv = {
        inside(element, scroller) {
          const r = element.getBoundingClientRect();
          const s = scroller.getBoundingClientRect();
          return r.top >= s.top && r.bottom <= s.bottom;
        },
        codeRow() { return $band_row_js; },
        codeScroller() { return document.querySelector('.daedalus-stylish-inspector__body'); },
        treeRow() { return $band_tree_row_js; },
        treeScroller() { return this.treeRow().closest('.daedalus-ui-panel__body'); },
        toTop() {
          this.codeScroller().scrollTop = 0;
          this.treeScroller().scrollTop = 0;
          window.scrollTo(0, 0);
        },
      };
      window.__rv.toTop();
      JS);
    $this->assertJsCondition(
      "window.__rv.codeScroller().scrollTop === 0 && window.__rv.codeScroller().scrollHeight > window.__rv.codeScroller().clientHeight"
      . " && !window.__rv.inside(window.__rv.codeRow(), window.__rv.codeScroller())",
      20000,
      'The precondition: at the shortened window the code view overflows and, scrolled to its top, the band\'s row sits below the fold (outside the scroller\'s rect), so the reveal has a distance to cover.',
    );
    $session->executeScript("Drupal.Daedalus.Selection.select('$band_key');");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === " . json_encode($band_key)
      . " && window.__rv.inside(window.__rv.codeRow(), window.__rv.codeScroller())"
      . " && window.__rv.inside(window.__rv.treeRow(), window.__rv.treeScroller())",
      20000,
      'Selecting the last styled element leaves its row\'s rect fully inside the code view\'s scroller (the row was below the fold) and the page elements panel\'s row fully inside its scroller: the two panel rows reveal by the one rule.',
    );
    $session->executeScript("window.scrollTo(0, 0);");
    $this->assertJsCondition('window.scrollY === 0', 10000, 'The window is back at its top before the row click.');
    $session->executeScript("window.__rv.codeRow().querySelector('.daedalus-stylish-page-tree__select').click();");
    $this->assertJsCondition(
      "(() => { const canvas = document.querySelector(\"[data-selectable='$band_key']\");"
      . "  const rect = canvas.getBoundingClientRect();"
      . "  const bar = document.getElementById('edit-mode-top-bar').getBoundingClientRect();"
      . "  return Drupal.Daedalus.Selection.getSelected() === " . json_encode($band_key)
      . "    && rect.top >= bar.bottom && rect.top < window.innerHeight; })()",
      20000,
      'A click on the code view\'s row reveals the canvas: the band\'s element scrolls into view with its top edge below the fixed top bar (the scroll-margin\'s clearance), a reveal the code view never ran before the rule.',
    );

    // ——— The pop-out keyboard wire. ———
    // Seam: a keydown in the pop-out document -> the keyboard sensor the
    // pop-out runs (daedalus_ui/popout keyboard.js, attached to the route
    // by DaedalusUiHooks::pageAttachments) -> its synchronous swallow from
    // the claim set the leader published on hello (popout-relay.js
    // 'claims', hotkeys.js claimSet()) -> the stroke over the
    // daedalus_ui:<scope> BroadcastChannel -> the leader's dispatch() ->
    // the action on the leader (history.undo's op-queue restore) -> the
    // state broadcast back to the satellite's rows. A popped-out panel is
    // the same app in another window: no pop-out resolves or acts. The
    // node specs prove the sensor, the resolution and the claim matching
    // as models; only a browser joins two documents, two channels and
    // the preventDefault that must land before the leader's verdict. The
    // committed style is one shadow level (box-shadow binds to the shadow
    // scale under the strict law, so the token is the legal value) on
    // block B, the newest history entry; the satellite mounts after it
    // and the Basic mode's Shadow row shows the level chip selected. The
    // press lands on that focused chip (a button: non-typing focus, and
    // mod+z is no bare Space or Enter, so the focused-control rule yields
    // nothing). The claim set arrives asynchronously after the pairing,
    // so the press is retried until the sensor swallows it: an
    // unclaimed press is neither swallowed nor forwarded, so a retry
    // cannot double-undo.
    $session->resizeWindow(1920, 1200);
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_b_key');");
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{ op: 'update_style', key: '$block_b_key', properties: { 'box-shadow': 'var(--shadow-md)' } }],
        })).catch((error) => {
          window.__shadowError = error.message;
        });
      })();
      JS);
    $block_b_shadow_js = "(" . $map . "[" . json_encode($block_b_key) . "]?.default?.['box-shadow'] ?? null)";
    $this->assertJsCondition(
      "window.__shadowError !== undefined || $block_b_shadow_js === 'var(--shadow-md)'",
      20000,
      'The shadow commit on block B resolved: its map entry carries box-shadow at the md level, the newest history entry.',
    );
    $this->assertNull($session->evaluateScript('window.__shadowError ?? null'), 'The shadow commit failed: ' . ($session->evaluateScript('window.__shadowError ?? null') ?? ''));
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
      'The pop-out mounted again for the keyboard leg.',
    );
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-stylish-satellite__banner')",
      20000,
      'The satellite paired with the leader for the keyboard leg (the connecting banner cleared).',
    );
    // The shared UI mode rests at code from the scope legs; the Shadow
    // row is a Basic surface, so the satellite flips the mode (both
    // windows follow through the storage-synced store).
    $session->executeScript(<<<'JS'
      (() => {
        Array.from(document.querySelectorAll('.daedalus-stylish-inspector__ui-mode button'))
          .find((button) => button.textContent.trim() === 'Basic').click();
      })();
      JS);
    // The pop-out's keyboard probe: a press is a keydown then a keyup on
    // the focused element, bubbling to the document where the sensor
    // listens, and reports whether the keydown's default was taken (the
    // sensor's swallow). 'mod' resolves as binding.js resolves it, by
    // the platform: the meta key on a Mac, ctrl elsewhere.
    $session->executeScript(<<<'JS'
      window.__kb = {
        mod: /mac/i.test(navigator.platform) ? { metaKey: true } : { ctrlKey: true },
        press(target, key, init) {
          target.focus();
          const options = Object.assign({ key, bubbles: true, cancelable: true }, init);
          const swallowed = !target.dispatchEvent(new KeyboardEvent('keydown', options));
          target.dispatchEvent(new KeyboardEvent('keyup', options));
          return swallowed;
        },
        shadowRow() {
          return document.querySelector('.daedalus-stylish-inspector__widget--elevation');
        },
        shadowChip() {
          return this.shadowRow()?.querySelector('button[aria-pressed="true"]') ?? null;
        },
        shadowState() {
          const row = this.shadowRow();
          return JSON.stringify({
            row: !!row,
            wearBar: !!document.querySelector('.daedalus-stylish-wear-bar'),
            selected: this.shadowChip()?.getAttribute('aria-label') ?? null,
            chips: Array.from(row?.querySelectorAll('button[aria-pressed]') ?? []).map((chip) => chip.getAttribute('aria-label')),
          });
        },
      };
      JS);
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-stylish-wear-bar') && !!window.__kb.shadowChip()",
      20000,
      'The satellite renders block B\'s Basic rows with the Shadow row\'s level chip pressed (the committed md level), the focus target for the press. Row state: ' . $session->evaluateScript('window.__kb.shadowState()'),
    );
    $this->assertSame(
      'md',
      json_decode((string) $session->evaluateScript('window.__kb.shadowState()'), TRUE)['selected'],
      'The pressed chip faces the shadow token by its name, md (the segments surface: one button per level).',
    );
    $undo_swallowed = FALSE;
    for ($attempt = 0; $attempt < 40; $attempt++) {
      $undo_swallowed = (bool) $session->evaluateScript("window.__kb.press(window.__kb.shadowChip(), 'z', window.__kb.mod)");
      if ($undo_swallowed) {
        break;
      }
      usleep(250000);
    }
    $this->assertTrue(
      $undo_swallowed,
      'mod+z on the satellite\'s focused shadow chip was swallowed: the leader\'s claim set reached the pop-out over the wire (hello -> claims) and names undo, so the sensor took the browser default and forwarded the stroke.',
    );
    $session->switchToIFrame(NULL);
    $this->assertJsCondition(
      "$block_b_shadow_js === null && Drupal.Daedalus.History.getState().canRedo === true",
      20000,
      'The leader undid the shadow commit from the satellite\'s keystroke: block B\'s map entry lost box-shadow and the history can redo. The pop-out sensed, the leader resolved and acted.',
    );
    $session->switchToIFrame('daedalus_stylish_satellite');
    $this->assertJsCondition(
      "!!window.__kb.shadowRow() && window.__kb.shadowChip() === null",
      20000,
      'The satellite\'s Shadow row followed the undo: no level chip is pressed any more. Row state: ' . $session->evaluateScript('window.__kb.shadowState()'),
    );

    // shift+v resolves on the leader (the apply-variant binding, in the
    // selection context) and, with the panel popped out, routes the open
    // to the satellite over stylish's own protocol (open-variant-picker)
    // instead of reopening the leader's sidebar over it: the picker opens
    // where the panel is. The press lands on the wear bar's add button,
    // a non-typing focus; the claim set names shift+v while block B is
    // selected, the set the undo above already proved delivered.
    $this->assertTrue(
      (bool) $session->evaluateScript("window.__kb.press(document.querySelector('.daedalus-stylish-wear-bar__add'), 'V', { shiftKey: true })"),
      'shift+v on the satellite\'s focused add button was swallowed: the apply-variant binding is in the claim set with a selection live.',
    );
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker [role=\"option\"]')",
      20000,
      'The satellite\'s variant picker opened from the forwarded shift+v: the leader routed the open back to the window that holds the panel (open-variant-picker -> the satellite\'s wear bar opener).',
    );
    $session->switchToIFrame(NULL);
    $this->assertTrue(
      (bool) $session->evaluateScript(
        "document.getElementById('daedalus_stylish_inspector').classList.contains('daedalus-ui-hidden')"
        . " && !document.querySelector('.daedalus-stylish-variant-picker')"
      ),
      'The leader\'s inspector sidebar stayed closed and shows no picker: the routed press never reopened the displaced panel over the satellite.',
    );

    // ——— The collapse controls over the popped-out outline. ———
    // Seam: daedalus_ui's one collapse model (outline/collapse.js) under
    // code mode's page view, the shared control pair in the showing row,
    // and the pop-out intent road: the pair's hotkeys register on the
    // leader (collapse-seam.js, unbound by default, bound here through
    // the registry's rebind as the tray would), a press in the satellite
    // document is swallowed there from the relayed claim set and resolved
    // on the leader, whose own outline is displaced while the panel is
    // popped out, so the GESTURE routes back over stylish's protocol
    // (collapse-outline) and lands on the satellite's own posture. Node
    // tests pin the posture math; only a browser joins two documents,
    // the keyboard wire and the intent channel. Then a selection from
    // the leader reveals the selected row's chain in the satellite and
    // nothing else.
    $session->executeScript(<<<'JS'
      Drupal.Daedalus.Hotkeys.rebind('daedalus_stylish.code.collapse_all', '5');
      Drupal.Daedalus.Hotkeys.rebind('daedalus_stylish.code.expand_all', '6');
      JS);
    $this->assertSame(
      ['5', '6'],
      json_decode((string) $session->evaluateScript(
        "JSON.stringify(Drupal.Daedalus.Hotkeys.getBindings().filter((binding) => binding.section === 'Panels' && binding.id.startsWith('daedalus_stylish.code.')).map((binding) => binding.key))"
      ), TRUE),
      'The code mode outline registered its collapse-all and expand-all pair in the Panels section at leader boot (unbound by default), and the rebind bound them to 5 and 6 for the press.',
    );
    $session->switchToIFrame('daedalus_stylish_satellite');
    $session->executeScript("document.querySelector('.daedalus-stylish-inspector__ui-mode-button--code').click();");
    $session->executeScript(<<<'JS'
      window.__co = {
        faces() {
          return Array.from(document.querySelectorAll('.daedalus-stylish-page-tree [data-daedalus-stylish-page-key]'))
            .map((header) => ({
              key: header.dataset.daedalusStylishPageKey,
              collapsed: header.parentElement.classList.contains('daedalus-stylish-page-tree__node--collapsed'),
              chevron: header.querySelector('.daedalus-stylish-page-tree__chevron')?.getAttribute('aria-expanded') ?? null,
              count: Number(header.querySelector('.daedalus-stylish-page-tree__summary .daedalus-stylish-page-tree__count')?.textContent ?? 0),
              islands: !!header.parentElement.querySelector(':scope > .daedalus-stylish-page-tree__islands'),
            }));
        },
        face(key) {
          return this.faces().find((face) => face.key === key) ?? null;
        },
        pair() {
          const collapse = document.querySelector('.daedalus-stylish-page-tree__showing .daedalus-ui-collapse-all');
          const expand = document.querySelector('.daedalus-stylish-page-tree__showing .daedalus-ui-expand-all');
          return collapse && expand ? { collapse: collapse.disabled, expand: expand.disabled } : null;
        },
        chevron(key) {
          document.querySelector('.daedalus-stylish-page-tree [data-daedalus-stylish-page-key="' + key + '"] .daedalus-stylish-page-tree__chevron').click();
        },
        state() {
          return JSON.stringify({ faces: this.faces(), pair: this.pair() });
        },
      };
      JS);
    $this->assertJsCondition(
      "window.__co.faces().length > 1 && window.__co.faces().every((face) => !face.collapsed)"
      . " && JSON.stringify(window.__co.pair()) === '{\"collapse\":false,\"expand\":true}'",
      20000,
      'The satellite\'s code view arrived at the Page scope with every row open and the shared pair in its showing row reading the posture: collapse all enabled, expand all disabled.',
    );
    $collapse_swallowed = FALSE;
    for ($attempt = 0; $attempt < 40; $attempt++) {
      $collapse_swallowed = (bool) $session->evaluateScript("window.__kb.press(document.querySelector('.daedalus-stylish-page-tree__scope'), '5')");
      if ($collapse_swallowed) {
        break;
      }
      usleep(250000);
    }
    $this->assertTrue(
      $collapse_swallowed,
      'The bound collapse-all key (5) pressed on the satellite\'s focused scope button was swallowed: the rebind reached the pop-out\'s claim set over the wire.',
    );
    $this->assertJsCondition(
      "JSON.stringify(window.__co.faces().map((face) => [face.key, face.collapsed, face.chevron])) === " . json_encode(json_encode([[$entity_key, TRUE, 'false']]))
      . " && JSON.stringify(window.__co.pair()) === '{\"collapse\":true,\"expand\":false}'",
      20000,
      'The leader resolved the press and routed the gesture back: the satellite\'s outline collapsed to the Page row alone (its chevron closed, every descendant row unmounted), collapse all disabled and expand all enabled. State: ' . $session->evaluateScript('window.__co.state()'),
    );
    // The chevrons are the model's toggle in this window: opening the
    // Page row prints its sections collapsed (collapse all reached every
    // foldable row, not just the top), and opening the fixture's section
    // prints block A collapsed to its summary line, the count of its own
    // declarations (the gradient background) where its islands were.
    $session->executeScript("window.__co.chevron(" . json_encode($entity_key) . ");");
    $this->assertJsCondition(
      "window.__co.face(" . json_encode("container:$section_uuid") . ")?.collapsed === true",
      20000,
      'Opening the Page row by its chevron printed the fixture\'s section, still collapsed from the all-gesture.',
    );
    $session->executeScript("window.__co.chevron(" . json_encode("container:$section_uuid") . ");");
    $this->assertJsCondition(
      "(() => { const a = window.__co.face(" . json_encode($block_a_key) . "); return !!a && a.collapsed && a.chevron === 'false' && a.count >= 1 && !a.islands; })()",
      20000,
      'Block A prints collapsed to its summary line: the chevron closed, its own declaration count shown, no islands mounted. State: ' . $session->evaluateScript('window.__co.state()'),
    );
    // The selection rule over the collapsed outline, from the leader: the
    // band's first text block, under rows the all-gesture collapsed.
    // reveal opens its ancestors and expand opens the row itself (the
    // selection mounts its full body); block A, off the path, keeps its
    // collapse.
    $session->switchToIFrame(NULL);
    $session->executeScript("Drupal.Daedalus.Selection.select('block:$band_p1');");
    $this->assertTrue(
      (bool) $session->evaluateScript("!document.querySelector('.daedalus-stylish-page-tree')"),
      'The leader holds no code outline while the panel is popped out (the placeholder stands in for it), so the routed gesture had nowhere to land but the satellite.',
    );
    $session->switchToIFrame('daedalus_stylish_satellite');
    $this->assertJsCondition(
      "(() => {"
      . " const header = document.querySelector('.daedalus-stylish-page-tree [data-daedalus-stylish-page-key=\"block:$band_p1\"]');"
      . " if (!header) { return false; }"
      . " let node = header.parentElement; const chain = [];"
      . " while (node && node.classList.contains('daedalus-stylish-page-tree__node')) { chain.push(node.classList.contains('daedalus-stylish-page-tree__node--collapsed')); node = node.parentElement?.closest('.daedalus-stylish-page-tree__node') ?? null; }"
      . " const a = window.__co.face(" . json_encode($block_a_key) . ");"
      . " return chain.length >= 2 && chain.every((collapsed) => !collapsed)"
      . "   && !!header.parentElement.querySelector(':scope > .daedalus-stylish-page-tree__islands')"
      . "   && !!a && a.collapsed"
      . "   && JSON.stringify(window.__co.pair()) === '{\"collapse\":false,\"expand\":false}'; })()",
      20000,
      'The leader\'s selection revealed the band\'s text block in the satellite: its row and every row above it open with its islands mounted, block A off the path still collapsed, and the pair reading the mixed posture (both enabled). State: ' . $session->evaluateScript('window.__co.state()'),
    );
    $session->switchToIFrame(NULL);
  }

  /**
   * Clicks a panel element found by xpath, scrolled into the viewport.
   */
  private function clickPanelXpath(string $xpath): void {
    $element = $this->assertSession()->waitForElement('xpath', $xpath);
    $this->assertNotNull($element, sprintf('The panel element %s is present to click.', $xpath));
    $this->getSession()->executeScript(
      'document.evaluate(' . json_encode($xpath) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null)'
      . ".singleNodeValue.scrollIntoView({ block: 'center' });"
    );
    $element->click();
  }

}
