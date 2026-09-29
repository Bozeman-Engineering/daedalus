<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\FunctionalJavascript;

use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\daedalus_stylish\Traits\LayerColorTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the basic-mode value-space field rows on a real page.
 *
 * The capability under test is the client value-space spine's basic
 * surface: one scrubbable field (DimensionField, rendered by FieldRow)
 * adapts per property to the value space the guide derives, values on
 * every face, references under the hood. The models are node-tested
 * exhaustively (value-space.test.mjs owns the kind derivation, capture
 * and scrub math; dimension-value.test.mjs the field state machine;
 * controls.test.mjs the model plumbing) and the server contracts are
 * kernel-pinned (StyleGuideConstraintDefaultsTest the binding channel,
 * StyleMapValidatorTest the closed-vocabulary law). Everything here is
 * wiring that only exists where the payload, the widgets, the op spine
 * and the browser's CSS engine meet:
 * - Closed well commit lands var() (seam: a real pick in the choice
 *   well's popover through the gesture-to-op-to-mirror-to-canvas round
 *   trip): under strict enforcement a scale-bound Size row renders as
 *   the choice well (the type scale's rem faces outgrow the chip budget,
 *   so the spine answers the well, never a select or an input) whose
 *   popover lists the stops as display values, never var() syntax, and
 *   picking one commits the stop's reference spelling into the entity's
 *   style map, restyles the canvas via the token's CSS custom property
 *   without reload, and wears the value on the well's face. Node tests
 *   pin that valueSpaceFor yields token stops and surfaceFor answers the
 *   well; only a browser proves the rendered well's pick puts the REF in
 *   the map while every face keeps the value.
 * - A Base value posed at Medium is the row's lit value (seam: the
 *   control model's stack read, controls.js reads over stackAt's
 *   cascade at the pose, reaching the well's face, the row's mark and
 *   the Unset verb through one read): posed at Medium the Size row's
 *   own entry says nothing at the compound while its Base commit still
 *   delivers there, so the well wears the Base value LIT on the stop
 *   face (never a ghost or a faded stand-in), the row's mark wears the
 *   element layer's hue with "from Base" as its story, and the Unset
 *   gesture is absent: picking the lit stop again is refused (no op,
 *   the value stays) because nothing of the element's own delivers at
 *   the pose. Back at Base the same pick IS the unset (the map loses
 *   the value). style-stack.test.mjs pins the cascade and controls
 *   ownAtPose; the read reaching a rendered well, its listbox and the
 *   gesture gate only exists in a browser, and no shipped fixture skin
 *   sets a basic-owned property on a block scope, so the pose is the
 *   wire's proof.
 * - One Background row over the link-first candidate list (seam: the
 *   text block's restated `background` binding listing `.text-block-link`
 *   before the root, crossing the served list, controls.js's
 *   resolveCandidates and the pipeline's key routing to the rendered
 *   panel and the op spine): a linked text block wearing `button`
 *   renders exactly ONE Background row on the block's own basic panel,
 *   lit with the anchor's variant fill on the swatch well's face, the
 *   row hued in the button layer's color and its mark titled "#1a1a2e
 *   from Button", no part caption anywhere; a root background written
 *   through the op path leaves the row on the anchor and puts the inert
 *   "Also set on element" line in the row's menu; a Center alignment
 *   picked while posed at Medium fills the row's mark in the element
 *   hue ("center set here at Medium") and driving the canvas back to
 *   Base hollows it ("Set at Medium, not here"); after undo a pick
 *   from the row's well commits at the PART key (write where you read);
 *   the unlinked text block's Background row commits at the plain
 *   block key (the absent first candidate falls to the root); and a
 *   gradient pick on a fresh linked block whose root wears an authored
 *   band is ONE atomic batch of two update_style ops, the image placed
 *   on the anchor (the set's box as a group, controls.js resolveGroup)
 *   and the root's band cleared (a removal lands where the value
 *   lives), the anchor painting the gradient and the band gone.
 *   controls.test.mjs pins the address rule and its group form; the
 *   served list, the rendered row, the routed op and the multi-key
 *   batch through the op path only meet in a browser.
 * - Typed capture goes blessed (seam: native input events driving the
 *   typedRef settle guard and magnetic capture through the same commit
 *   path): under open enforcement the same binding shapes a detented
 *   row; typing a token's VALUE and blurring commits the token's
 *   reference (magneticCapture), and the field re-renders worn: the face
 *   shows the value, the map holds var(), the blessed chrome lights.
 *   The capture model is node-tested; the input-event wiring (a real
 *   'input' event arming the typed flag, blur settling) and the
 *   blessed round trip only exist in a browser.
 * - Empty-vocabulary row absent (seam: the authored site binding
 *   crossing server payload to client render): width is bound to a
 *   scale NO skin mints, so under strict enforcement its closed space is
 *   honestly empty and the basic Width row does not render at all, while
 *   under open enforcement (constraints no longer law) the same row
 *   returns as the free field. The hide substrate (closed + empty
 *   stops) is node-visible; the binding-to-absent-DOM channel is not.
 * - A literal color round-trips through the well and the chip (FJS-3,
 *   seam: op -> mirror -> well face -> chip -> op): a custom color typed
 *   into the Advanced Text color row's color field commits the literal
 *   and restyles the canvas; back in Basic the text_color well wears the
 *   painted LITERAL face (its own paint under the custom marker, never
 *   the "?" face) and the color chip beside it opens the same color
 *   field over the value, whose typed settle commits the new literal
 *   through the widget's pick path and repaints the well and the canvas.
 *   The face and state models are node-tested (color-field-model,
 *   swatch-well-model, widget-gradients); the two mounts of one field
 *   feeding one map, and the painted face reading back from the map, only
 *   exist in a browser.
 * - Duplicate lands a styled clone without reload (seam: the duplicate
 *   response's targets -> the whole-entity swap -> the clone's @scope rule
 *   in the browser cascade): daedalus_stylish's lifecycle subscriber copies the
 *   source's style map entry onto the clone and declares the root
 *   daedalus_stylish_styles field as a target beside the op's ADDED clone, which
 *   forces the response to swap the whole entity, the one subtree that
 *   holds the entity-level style tag. Kernel tests pin the declared target
 *   and the rendered markup; only a browser proves the clone WEARS the
 *   source's computed styles the moment it lands, with the page never
 *   reloading. Browser smoke caught the unstyled-until-reload clone, so
 *   the step accretes here (regression accretion).
 * - Every settled write released its preview (seam: the settle path's
 *   release bound at dispatch, preview.settling): the Size capture
 *   previews the stop's literal and commits the token's reference, and
 *   the release used to compare the two, stranding the literal in the
 *   preview sheet for the rest of the session (the sheet then printed
 *   the whole merged map, masking what the entity tag delivered). The
 *   model is node-tested (preview.test.mjs); the assertion before the
 *   duplicate step pins the sheet empty in the browser so the clone's
 *   computed claims read the entity tag alone.
 * - The creating posture's header fits the panel (seam: the save
 *   doorway -> the session frame's markup -> the panel's flex layout in
 *   the browser's box model): the name input and the axis select used to
 *   share the non-wrapping top row with the posture label, and at panel
 *   width the row overflowed and the body scrolled sideways. They now
 *   sit in the draft row beneath the label, the description input on
 *   its own line under them, and the session body's inset out-cascades
 *   the panel recipe's padding. The frame's model is node-tested
 *   (session-frame-model.test.mjs); whether the header widens the body
 *   past the panel is a layout fact only the browser can see. Browser
 *   smoke caught the overflow, so the step accretes here (regression
 *   accretion).
 * - The Font row wears the skin's stacks and commits a reference (seam:
 *   the literal face beside a reference commit, across the token scope
 *   boundary): the site binds font-family to the font scale, so the
 *   Text section's Font row is the skin's minted stacks and nothing
 *   else, surfaced as the choice well (a font stop faces by token name
 *   and guide_example's heading outgrows the chip budget); the panel sits
 *   OUTSIDE the token scope, so each listbox option and the well's lit
 *   face wear their stack through the stop's literal display value
 *   (ChoiceRow's faceStyle seam), never a var() they could not resolve,
 *   while a pick commits the token's reference. Node tests pin the
 *   offer, the display value and the surface budgets; only a browser
 *   can compare an option's computed font-family against the token's
 *   resolved stack on the canvas, then watch the reference restyle the
 *   block.
 * - A color pick completes an unset border in one op (seam: three
 *   sub-controls feeding one map entry through the stroke completion
 *   rule, op to mirror to canvas): the Border row is color well +
 *   width field + style well over one stroke, and a swatch pick on an
 *   unset border commits width, `border-style: solid` and the color
 *   together, the canvas wearing the whole stroke. strokeWrite is
 *   node-tested; the three mounts sharing one gesture and one dispatch
 *   only exist in a browser.
 * - The ring's right cell commits twelve longhands in one op (seam: the
 *   merged three-family patch through the op path, and the highlight
 *   claim stack painting the touched side): a keyboard step on the
 *   ring's right cell (the pointer scrub's twin through the same
 *   gesture, settle and patchFor path) expands all three own shorthands
 *   into their twelve side longhands in ONE op, the cell wears the
 *   authored state, and the canvas paints exactly one border band, the
 *   right side's, for the cell's claim over the side's three longhands.
 *   strokeSidesPatch is node-tested; the diagram's gesture wiring, the
 *   claim stack and the band renderer only agree in a browser.
 * - An outline write reveals the gated offset row (seam: the gated row
 *   crossing the settle path): a typed outline width commits
 *   `outline-width` and `outline-style: solid` in one op, the canvas
 *   wears the outline, and the Outline offset row, absent while nothing
 *   outlines, mounts. The gate is a one-line model read; that the row
 *   appears through the settle-to-mirror-to-re-render round trip is
 *   wiring.
 * - The page spaces its bands through the layout root (two seams). First,
 *   an element-bound kind control gating on the bound part's live child
 *   count: daedalus_edit binds the entity kind's `gap` control to
 *   `.daedalus-layout-layout`, the one element daedalus_layout renders around the root
 *   sections in every mode, and the widget's gate reads that part's OWN
 *   child boxes (self.partChildCount, the canvas's answer), never the
 *   entity root's (whose one child box is the layout root itself). The
 *   binding in the payload, the part's presence, the count read off the
 *   rendered wrapper and the registry's gate are four layers that only
 *   agree in a browser: with two bands on the page the Gap row is offered
 *   on the Page. Second, a part-keyed write landing through the
 *   entity's @scope tag onto a module-rendered element: a real stop
 *   pick commits `gap` at `entity:{uuid}::.daedalus-layout-layout` in one op,
 *   the entity root's own style tag prints the part rule, and the layout
 *   root's computed `row-gap` changes on the canvas without reload, the
 *   bands moving that far apart. The gate and the count are node-tested
 *   and the binding Kernel-pinned; the declared flex column laying the gap out
 *   on the edit canvas exists only here.
 * - The write address: one link, every link in its section (seam: the
 *   address switcher over addressSet -> the spine's styling target
 *   seated on the section's rule -> the canvas paint slot,
 *   controlModels' rebase and withheld verdicts as live and disabled
 *   rows, and a Basic widget's typed settle landing at the ADDRESS key
 *   while the selection stays put). First the reach gate (seam: the
 *   part resolver's injected reachable-row predicate over reach.js):
 *   a synthetic Cmd-click on the first link's anchor in Basic (Markup
 *   view off, no link row drawn) selects the block with no target; the
 *   Markup view on through the Show menu, the same click seats
 *   block::.text-block-link; off again, the row goes and the target
 *   stands as a doorway seat. Then with one link part targeted the
 *   target chip's switcher lists the link's address set ("This link",
 *   then the mintable "Every a in the section" and the two chained
 *   text-block-link shapes, hinted "new rule"); picking the tag rule
 *   moves the target to container::a, lights the chip there and paints
 *   every anchor in the section through the printers' own targets (the
 *   part's dashed box gone with the target); the link's
 *   rows are judged at `a` (Background and Corners disabled with the
 *   grant reason as their title, Size live); a typed Size commits ONE
 *   update_style at container::a, every anchor computes it (an
 *   h3-rooted block's included) and no root does; back on the own key
 *   the Size row reads the rule as the inherited origin, and a typed
 *   own value wins on that anchor alone. addressSet, the rebase rule
 *   and the editable-layer rule are node-tested (write-address,
 *   controls, style-stack); the chip, the menu, the injected paint
 *   computing on the canvas and the disabled posture over one real
 *   write exist only in a browser.
 * - Wear at the rule (seam: the wear bar and the Materials card over the
 *   styling target -> ONE apply_variant at the rule key -> the server's
 *   rule-wear lane, VariantStylesheet::ruleWearCss, printing the
 *   variant inside the section's @scope with no class on any element ->
 *   every anchor computing it): with container::a re-seated, the wear
 *   bar is live and its picker offers the two base:a variants the
 *   scenario minted and nothing else; picking one lands the reference
 *   on the RULE's entry (never on the link or its block) beside the
 *   rule's own font-size, the row names it, and all three anchors
 *   compute the variant's color and letter-spacing; on the Materials
 *   place the target chip still reads the rule, and the other variant's
 *   card applies at the same rule, replacing the first on its axis. The
 *   landing pairing, the reference placement and the printed lane are
 *   Kernel-pinned (StyleMapValidatorTest, VariantApplySubtreeTest,
 *   VariantStylesheetTest); the gestures aimed at the target and the
 *   delivered wear computing on the canvas exist only in a browser.
 * - The member reads the rule's wear (seam: the link part targeted again
 *   below the selection -> its stack's ancestor tier as a group per
 *   rule position, style-stack.js ancestorLayers, the worn layer
 *   `ancestor:{sec}::a|quiet_link` carrying the variant's identity and
 *   its carrier beneath the rule's own layer -> originOf's worn-ancestor
 *   branch on the Basic Text color row: the variant's hue through
 *   layerColor and the sentence naming the variant AND the carrier in
 *   the switcher's words -> the row's shared menu hook, use-row-menu.js,
 *   offering the variant's document and "Edit at" the CARRIER -> the
 *   target seated on the rule): the row's mark wears Quiet link's hue,
 *   its title starts "#993366 from Quiet link at Every link in the
 *   section", a right-click opens the provenance menu with "Edit Quiet
 *   link at …" (opens the variant) and "Edit at Every link in the
 *   section", and that pick seats container::a with the selection
 *   unchanged, the chip lit and the wear bar live naming Quiet link.
 *   The group, the words and the menu spec are node-pinned
 *   (style-stack, provenance-model, use-row-menu); the rendered mark,
 *   the real menu on a self-rendered row and the doorway's seat exist
 *   only here.
 * - Every choice row carries the source (seam: the widget contract's
 *   `source` -> ChoiceRow's own `source` prop -> use-row-menu.js
 *   dispatching the ancestor doorway through `source.target`): with
 *   the rule still seated, a typed Weight lands ONE update_style at
 *   container::a and every anchor computes it; back on the link's own
 *   address the Weight row reads the rule's own layer ("550 inherited
 *   from the section (a)"), its right-click offers "Edit at the
 *   section (a)", and the pick seats container::a with the selection
 *   unchanged. The doorway's words and dispatch are node-pinned
 *   (provenance-model, use-row-menu); the prop's road from the
 *   contract through the row into the hook exists only here, and
 *   before it every choice row's "Edit at" ran inert (the source rode
 *   inside the highlight bag only the two gap rows filled).
 * - Typing into the Padding field previews mid-gesture (seam: a native
 *   input event -> DimensionField's typedPreview -> useFieldGesture's
 *   preview handing its caller ONE object, {padding: value} -> the
 *   padding row's patchFor over that object -> basic-controls.js route
 *   -> source.preview -> the preview sheet's live slot on the canvas):
 *   with the band selected whole, a typed bare number in the open
 *   phase's detented Padding field paints every side of the band in
 *   the space's unit (the static rem label) before any settle, nothing
 *   dispatched, and the
 *   blur then commits ONE update_style with the same value and releases
 *   the slot. The hook's object shape and the row's patch are
 *   node-tested apart (use-gesture, padding-row), but the callback
 *   shape crosses from the hook to the component and only the browser
 *   runs both: a handler still destructuring the old (name, value)
 *   pair reads an undefined value and paints nothing until blur, which
 *   is the drift the step accretes against (the stroke widths' field
 *   and the ring's side editors ride the same hook and were ported in
 *   the same change).
 * - ArrowUp on an unset ring cell under rem commits rem seeded from the
 *   converted placeholder (seam: the canvas probe's px-resolved
 *   computed side and the document's measured bases (computed-read.js,
 *   readCanvas) -> the padding ring's cellFor (box-model.js
 *   sideMeasured: a supplied side outside the dimension grammar reads
 *   as its measurement; sideAnchor: the cell's unit through
 *   contextUnit, the space's rem, its number the placeholder converted
 *   through the MEASURED root, seedAnchor) -> stepKeyGesture's anchor
 *   -> sideStepValue over the detented space -> applyGesture's preview
 *   through the ring's patch -> settleGesture's settleSpelling and ONE
 *   commit): a band wearing no own padding shows its gutter as the left
 *   cell's placeholder converted into the space's rem (olivero's 2rem
 *   gutter reads 2rem, never 32px) beneath a Padding row whose static
 *   label says rem; ArrowUp previews the cell one diagram grain (a
 *   tenth) above that seed, 2.1rem (never 0.1rem from zero, never px
 *   from the placeholder), and its release commits that rem literal as
 *   ONE update_style of padding-left. The seed and the step are
 *   node-pinned (box-model, dimension-value); the placeholder's road
 *   from the canvas into the cell, and the unit the committed op
 *   actually carries, exist only here: before the seed rule the same
 *   cell read its px placeholder as its own anchor and wrote px beneath
 *   a row that said rem, and until the conversion rule it printed the
 *   placeholder's px whole beside a rem select.
 * - The ring's inline cells read the Site width variant (seam: the
 *   stack's `padding-inline` line, the worn Site width variant's
 *   `max(var(--space-gutter), calc(...))`, through resolveSides'
 *   logical-longhand read (LOGICAL_SIDES) into the right cell's variant
 *   hue, its hover words ("2rem from Site width") and its converted
 *   placeholder, then the side editor's `padding-right` write beating
 *   it on the canvas while the variant stays worn): the right cell of a
 *   band born by add_section reads the variant, not "computed from the
 *   theme", a real click opens its editor EMPTY over the measurement
 *   as a placeholder (2 beside the rem label), and a typed 3 previews
 *   and commits padding-right: 3rem as ONE update_style, the band
 *   computing 3rem on the right beside its own left while the map holds
 *   no padding-inline. The logical read and the measured-cell rule are
 *   node-pinned (box-model); the hue, the measurement and the write
 *   meet only in the browser.
 *
 * Scrub pointer math is deliberately NOT exercised here: it is a pure
 * model (value-space.test.mjs) and FJS pointer capture is brittle and
 * expensive. The ring's per-side step rides
 * the keyboard for the same reason: a synthetic pointer cannot take
 * pointer capture, and arrows run the same gesture path.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site as
 * daedalus_editor) plus daedalus_stylish_basic_styling_test, the declaration
 * layer a deployment's theme would add (width bound to the unminted
 * measure scale; the type trio, padding and gap are the suite's own
 * site-wide bindings). The scenario has two phases and rides one shipped
 * reference design system per posture, never forcing a mode onto a
 * guide not written for it: the closed phase activates guide_example
 * (daedalus_stylish_guide_example's strict reference, its skin minting the type
 * scale, the weight scale and the space ladder the closed rows offer)
 * in setUp, and the open phase switches back to the recipe's olivero
 * (open as shipped, its skin the vocabulary the detented rows offer).
 * One fixture, one scenario: the site install dominates the run cost,
 * so all behavior lives in the single test method.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class BasicStylingBehaviorTest extends WebDriverTestBase {

  use DesignSystemActivationTrait;
  use EditModeTrait;
  use LayerColorTrait;
  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * The value-space fixture layer and the strict reference's package.
   *
   * See the class docblock.
   *
   * @var string[]
   */
  protected static $modules = ['daedalus_stylish_basic_styling_test', 'daedalus_stylish_guide_example'];

  /**
   * {@inheritdoc}
   *
   * The scenario picks its law by picking a design system: the recipe's
   * olivero ships open, and the closed-phase claims need strict law, so
   * after the fixture install the site switches to guide_example, the
   * strict reference the package above ships (the activation syncs the
   * package's skin and members under its own law, the way a recipe's
   * activation action does). The open phase switches back to olivero
   * in-scenario. Neither system's mode is set by the test. The fixture
   * is whole as installed: the install batch waits for the installer and
   * every activation syncs every module's shipped definitions, so the
   * globals (daedalus_stylish_layouts' `row`, the Layout axis the nested-container
   * leg reads, among them) landed at the recipe's olivero activation.
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
    $this->activateSkin('guide_example', 'guide_example');
  }

  /**
   * The Size row's closed render (strict phase): the choice well's face.
   *
   * The face button lives in widgets/lib/choice-row.js.
   */
  private const SIZE_WELL = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--typography button.daedalus-stylish-choice-well[aria-label='Size']";

  /**
   * The Font row's closed render (strict phase).
   *
   * The same choice well over the skin's font stacks.
   */
  private const FONT_WELL = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--typography button.daedalus-stylish-choice-well[aria-label='Font']";

  /**
   * The rows of a choice well's popover listbox, one per stop.
   *
   * Each is faced "name · value" (value-space.js stopLabel). The listbox rides
   * the shared floating surface under body (never the widget's subtree); one
   * popover is open per window, so the surface's content is whichever well
   * opened it (the Size well's, then the Font well's) while it is up.
   */
  private const SIZE_OPTIONS = '.daedalus-ui-popover .daedalus-stylish-choice-popover .daedalus-stylish-choice-list__option';

  /**
   * The Size field's detented render (open phase).
   */
  private const SIZE_INPUT = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--typography input[aria-label='Size']";

  /**
   * The Size row's stop picker chevron (stop-menu.js).
   *
   * The button right after the detented input inside its value box.
   */
  private const SIZE_CHEVRON = self::SIZE_INPUT . ' + .daedalus-stylish-stop-menu__chevron';

  /**
   * The Advanced Text color row's color field text input.
   *
   * The Typography section's `color` row; color is bound to no scale, so the
   * field is the open render: face trigger | text, the native picker a hidden
   * input behind the face popover's color lane.
   */
  private const ADVANCED_COLOR_INPUT = "#daedalus_stylish_inspector details.daedalus-stylish-inspector__section .daedalus-stylish-color-field input[aria-label='Text color']";

  /**
   * The basic text_color widget.
   */
  private const TEXT_COLOR_WIDGET = '#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--text-color';

  /**
   * Opens the Styles inspector when the cookie entry rendered it closed.
   */
  private function openInspector(): void {
    $session = $this->getSession();
    $this->assertSession()->waitForElement('css', '#daedalus_stylish_inspector');
    $session->executeScript(<<<JS
      (() => {
        const panel = document.getElementById('daedalus_stylish_inspector');
        if (panel && panel.classList.contains('daedalus-ui-hidden')) {
          Drupal.Daedalus.SidebarManager.openSidebar('daedalus_stylish_inspector');
        }
      })();
      JS);
    $this->assertJsCondition(
      "(() => { const p = document.getElementById('daedalus_stylish_inspector');"
      . " return !!p && !p.classList.contains('daedalus-ui-hidden'); })()",
      20000,
      'The Styles inspector is open.',
    );
    // Every claim below is a basic-mode claim: assert the mode a fresh
    // session starts in so a drifted default cannot silently retarget
    // the scenario at the advanced rows.
    $this->assertJsCondition(
      "(() => { const active = document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]');"
      . " return !!active && active.textContent.trim() === 'Basic'; })()",
      20000,
      'The inspector is in Basic mode.',
    );
    // The header grammar's close control: the preact twin of the PHP
    // panels' control, last in the inspector's action cluster.
    $this->assertSession()->elementExists('css', '#daedalus_stylish_inspector .daedalus-stylish-inspector__actions .daedalus-ui-panel__close[data-sidebar-close]');
  }

  /**
   * Places a section holding one formatted_text block and waits for the render.
   *
   * The placement rides the op spine.
   *
   * @param string $section_uuid
   *   The new section's uuid.
   * @param string $block_uuid
   *   The new block's uuid.
   * @param string $sentinel
   *   A per-call window variable name for the placement error.
   */
  private function placeTextBlock(string $section_uuid, string $block_uuid, string $sentinel): void {
    $session = $this->getSession();
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
              uuid: '$block_uuid',
              block_type: 'formatted_text',
              properties: {
                field_formatted_text_text: {
                  value: '<p>Basic styling target text</p>',
                  format: 'daedalus_edit_formatted_text',
                },
              },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.$sentinel = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.$sentinel !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$block_uuid')",
      20000,
      'The block placement resolved.',
    );
    $placement_error = $session->evaluateScript("window.$sentinel ?? null");
    $this->assertNull($placement_error, 'Block placement failed: ' . ($placement_error ?? ''));
    $this->assertNotNull(
      $this->assertSession()->waitForElement('css', "[data-selectable='block:$block_uuid']"),
      'The formatted text block rendered on the canvas.',
    );
  }

  /**
   * Places a section holding one `text` block and waits for the render.
   *
   * The composed block of daedalus_edit_text_block: the anchor
   * (`.text-block-link`, the declared conditional part the shipped button
   * variant styles and the first candidate of the block's restated basic
   * bindings) renders only while field_text_url holds a value, so a block
   * born with a url has the part and a block born without one has only
   * its root.
   *
   * @param string $section_uuid
   *   The new section's uuid.
   * @param string $block_uuid
   *   The new block's uuid.
   * @param string $sentinel
   *   A per-call window variable name for the placement error.
   * @param string|null $url
   *   The link's uri, or NULL for an unlinked block.
   */
  private function placeTextTypeBlock(string $section_uuid, string $block_uuid, string $sentinel, ?string $url): void {
    $session = $this->getSession();
    $url_property = $url === NULL ? '' : "field_text_url: { uri: '$url' },";
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
              uuid: '$block_uuid',
              block_type: 'text',
              properties: {
                field_text_text: { value: 'Basic styling link' },
                $url_property
              },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.$sentinel = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.$sentinel !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$block_uuid')",
      20000,
      'The text block placement resolved.',
    );
    $placement_error = $session->evaluateScript("window.$sentinel ?? null");
    $this->assertNull($placement_error, 'Text block placement failed: ' . ($placement_error ?? ''));
    if ($url === NULL) {
      $this->assertNotNull(
        $this->assertSession()->waitForElement('css', "[data-selectable='block:$block_uuid']"),
        'The unlinked text block rendered on the canvas.',
      );
      $this->assertJsCondition(
        "!document.querySelector(\"[data-selectable='block:$block_uuid'] a.text-block-link\")",
        20000,
        'The unlinked text block renders no anchor: its link part is absent.',
      );
      return;
    }
    $this->assertNotNull(
      $this->assertSession()->waitForElement('css', "[data-selectable='block:$block_uuid'] a.text-block-link"),
      'The text block rendered on the canvas with its link part (the anchor the button variant styles).',
    );
  }

  /**
   * Types into a Preact-controlled panel input, without settling it.
   *
   * The native value setter plus a real 'input' event is the same wiring
   * a keystroke runs, and the input event is what arms the field's typed
   * flag (the typed-settle guard under test). Deliberately no settle
   * here: the blur is dispatched in a later call, like a real user's
   * separate tick (blurPanel).
   */
  private function typeIntoPanel(string $selector, string $text): void {
    $this->getSession()->executeScript(
      '(() => {'
      . ' const input = document.querySelector(' . json_encode($selector) . ');'
      . " const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;"
      . ' setter.call(input, ' . json_encode($text) . ');'
      . " input.dispatchEvent(new Event('input', { bubbles: true }));"
      . ' })();'
    );
  }

  /**
   * Settles a panel input (blur), in its own tick per typeIntoPanel.
   */
  private function blurPanel(string $selector): void {
    $this->getSession()->executeScript(
      'document.querySelector(' . json_encode($selector) . ").dispatchEvent(new FocusEvent('blur'));"
    );
  }

  /**
   * Settles a panel color input (change), in its own tick per typeIntoPanel.
   *
   * A color input's settle event is 'change', not blur: the native
   * chooser fires it with the final value when it closes, so dispatching
   * it after typeIntoPanel's input event runs exactly the picker path's
   * own wiring (the chooser itself cannot be driven headlessly).
   */
  private function changePanel(string $selector): void {
    $this->getSession()->executeScript(
      'document.querySelector(' . json_encode($selector) . ").dispatchEvent(new Event('change', { bubbles: true }));"
    );
  }

  /**
   * Clicks a panel element for real (scrolled to center so the pointer lands).
   *
   * The wiring under test is the element's own click handling.
   */
  private function clickPanel(string $selector): void {
    $element = $this->assertSession()->waitForElement('css', $selector);
    $this->assertNotNull($element, sprintf('The panel element %s is present to click.', $selector));
    $this->getSession()->executeScript(
      'document.querySelector(' . json_encode($selector) . ").scrollIntoView({ block: 'center' });"
    );
    $element->click();
  }

  /**
   * Clicks a panel element found by xpath.
   *
   * An element only its content names, like the color field around a given
   * text input.
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

  /**
   * Picks an option in a panel's closed dimension select by its face,
   * scrolled into the viewport.
   *
   * The driver's own selectOption: a real click on the option (the
   * browser selects it and fires 'change' on the select; ClosedField
   * settles on change, so the pick IS the commit, one tick, the way a
   * native select works for a user). Matched by face, not value: Preact
   * sets an option's value as a property with no attribute, so the
   * driver's match falls through to the text. Not Mink's click(): that
   * path moves the pointer onto the element first, and a collapsed
   * option has no box to move to.
   */
  private function pickPanelOption(string $selector, string $face): void {
    $this->getSession()->executeScript(
      'document.querySelector(' . json_encode($selector) . ").scrollIntoView({ block: 'center' });"
    );
    $this->assertSession()->elementExists('css', $selector)->selectOption($face);
  }

  /**
   * Flips the inspector's mode through the mode segment's real button.
   */
  private function switchMode(string $mode): void {
    $xpath = '//div[contains(@class, "daedalus-stylish-inspector__ui-mode")]/button[normalize-space() = "' . $mode . '"]';
    $element = $this->assertSession()->waitForElement('xpath', $xpath);
    $this->assertNotNull($element, sprintf('The %s mode button is present.', $mode));
    $this->getSession()->executeScript(
      'document.evaluate(' . json_encode($xpath) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null)'
      . ".singleNodeValue.scrollIntoView({ block: 'center' });"
    );
    $element->click();
    $this->assertJsCondition(
      "(() => { const active = document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]');"
      . " return !!active && active.textContent.trim() === " . json_encode($mode) . '; })()',
      20000,
      sprintf('The inspector flipped to %s mode.', $mode),
    );
  }

  /**
   * The page-side op log.
   *
   * Every operations list handed to the mutation spine since the last
   * recordOps() call.
   */
  private const OP_LOG = 'Drupal.Daedalus.Blueprint.__daedalusStylishOpLog';

  /**
   * Starts (or restarts) the op log for the "in one op" claims.
   *
   * The leader source dispatches every op through
   * Drupal.Daedalus.Blueprint.mutate, looked up at call time, so a
   * wrapper installed on the page records each dispatch's operations
   * without touching the source. Installed once per page load, cleared
   * on every call.
   */
  private function recordOps(): void {
    $this->getSession()->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        if (!Blueprint.__daedalusStylishOpLog) {
          const mutate = Blueprint.mutate;
          Blueprint.__daedalusStylishOpLog = [];
          Blueprint.mutate = (context) => {
            Blueprint.__daedalusStylishOpLog.push((context.operations ?? []).map((operation) => JSON.parse(JSON.stringify(operation))));
            return mutate.call(Blueprint, context);
          };
        }
        Blueprint.__daedalusStylishOpLog.length = 0;
      })();
      JS);
  }

  /**
   * Asserts the op log holds exactly one update_style op on the key.
   *
   * One dispatch of one op, carrying exactly the given properties (null =
   * unset).
   */
  private function assertOneOp(string $key, array $properties, string $message): void {
    $this->assertJsCondition(
      '(() => { const log = ' . self::OP_LOG . ';'
      . ' if (log.length !== 1 || log[0].length !== 1) { return false; }'
      . ' const [operation] = log[0];'
      . ' const sorted = (map) => JSON.stringify(Object.keys(map).sort().map((name) => [name, map[name]]));'
      . " return operation.op === 'update_style' && operation.key === " . json_encode($key)
      . ' && sorted(operation.properties ?? {}) === sorted(' . json_encode($properties) . '); })()',
      20000,
      $message,
    );
  }

  /**
   * Asserts the op log holds exactly one batch of the given update_style ops.
   *
   * One dispatch carrying exactly count($changes) ops, each change a
   * [key, properties] pair matched by one op with exactly those
   * properties (null = unset), in any order.
   */
  private function assertOneBatch(array $changes, string $message): void {
    $this->assertJsCondition(
      '(() => { const log = ' . self::OP_LOG . '; const changes = ' . json_encode($changes) . ';'
      . ' if (log.length !== 1 || log[0].length !== changes.length) { return false; }'
      . ' const sorted = (map) => JSON.stringify(Object.keys(map).sort().map((name) => [name, map[name]]));'
      . ' return changes.every(([key, properties]) => log[0].some((operation) =>'
      . " operation.op === 'update_style' && operation.key === key && sorted(operation.properties ?? {}) === sorted(properties))); })()",
      20000,
      $message,
    );
  }

  /**
   * The basic styling scenario: closed well, empty vocabulary, capture.
   *
   * The scripted scenario, in order:
   * - Fixture: a page node, Edit Mode via the cookie path, one section
   *   with one formatted_text block placed through the op spine, the
   *   block selected (the panel's basic controls target its root key).
   * - Closed well (strict): the Size row renders as the choice well, no
   *   select and no input, while its Weight sibling, closed too (the
   *   suite binds font-weight to the weight scale site-wide) but over
   *   four numeric faces within the chip budget, renders as the chips
   *   400, 500, 600, 700 (guide_example's regular, medium, semibold,
   *   bold): the surface is derived per property from its own space,
   *   never chosen per site; its popover lists the guide_example type
   *   scale's display values with var() nowhere on a face, and a real
   *   pick of 1.5rem commits var(--text-title-2) to the map, restyles
   *   the canvas block to the token's resolved size without reload, and
   *   wears the value on the well's face, the lit stop checked in the
   *   list.
   * - Lit value at a pose (strict): the width chip's real menu poses
   *   the canvas at Medium; the Size well wears the Base commit LIT on
   *   its stop face (1.5rem, never var(), no ghost text), the row's
   *   --daedalus-stylish-origin in the element layer's hue and its mark
   *   titled "1.5rem from Base"; picking the lit stop again in the
   *   reopened list is refused (no op, the map and the face unchanged:
   *   no Unset where nothing of the element's own delivers at the pose);
   *   the row menu's header names the value and Base with no Unset
   *   item, and its "Go to Base" doorway drives the canvas back, the
   *   well lit with 1.5rem; at Base the same pick IS the unset (the map
   *   loses font-size, the well rests unset) and a fresh pick of 1.5rem
   *   restores the value for the steps that follow.
   * - Font row (strict): the Text section's Font row is the choice WELL
   *   (guide_example mints three distinct stacks, body, heading and
   *   mono, and the heading face outgrows the chip budget), unset with
   *   its popover closed; a real click lists the three stops by token
   *   name with var() on no row, each option's computed font-family
   *   equal to the token's resolved stack measured inside the block
   *   (the system-ui stack for heading, the ui-monospace stack for
   *   mono); a real mono row pick commits `font-family: var(--font-mono)`
   *   alone in one op, the block's computed font-family becomes the
   *   mono stack, the well's face reads mono set in that stack, and the
   *   well's unset gesture, picking the checked row again in the
   *   reopened list, clears it.
   * - Sections (strict): the selected block's Basic tab reads exactly
   *   the section headers Layout, Text, Surface, Motion, in roster
   *   order, one per non-empty section.
   * - Empty vocabulary (strict): the Width control renders but holds NO
   *   field row, because width's bound measure scale mints nothing.
   * - Open flip: the site switches back to the recipe's olivero design
   *   system (open as shipped) server-side and the page reloads (still
   *   in Edit Mode via the cookies); the strict phase's guide_example
   *   references stay in the staged map, inert under open law. A fresh
   *   block is placed and selected.
   * - Detented return (open): the Size row is now a text input with the
   *   stop picker's chevron after it (no native option list: that one
   *   filters its options against the typed text), and the Width row is back as
   *   the free field, proving the strict-phase absence was the law-gated
   *   empty-closed hide, not a lost row.
   * - Stop pick (open): clicking the chevron opens the shared menu
   *   (daedalus_ui/menu, reached through the cross-library facade)
   *   listing the type scale's display values, headerless, nothing
   *   checked while the field is empty; picking 1.25rem commits
   *   var(--text-lead) through magnetic capture (pick -> preview ->
   *   settleValue -> op path), closes the menu, and the face wears the
   *   display value with the blessed chrome.
   * - Keyboard opener (open): Alt+ArrowDown in the input opens the same
   *   menu with the worn stop (lead) checked and no other; an outside
   *   pointerdown dismisses it.
   * - Typed capture (open): typing '.85rem' (the caption token's value,
   *   spelled differently) and blurring commits var(--text-caption) to the
   *   map while the face shows '0.85rem' and the blessed chrome
   *   lights: a typed value that matches a minted token lands the
   *   reference, never the literal.
   * - Literal color round trip (open, FJS-3): the inspector flips to
   *   Advanced through the real mode button; the Text color row's color
   *   field is face | text with a hidden native picker; the face opens
   *   the palette popover whose color lane closes it and opens the
   *   picker (its input previews '#22aa66' into the text and the face,
   *   its change commits the literal to the map: the native chooser's
   *   own two events, dispatched); typing '#ff8800' into the text
   *   and blurring commits the literal to
   *   the map and the canvas wears it; flipping back to Basic, the
   *   text_color well wears the painted literal face; the well IS the
   *   control: its popover's color lane closes it and opens the hidden
   *   picker, whose input event ('#0a55f0') repaints the well live from
   *   the staged draft before any commit and whose change event commits
   *   the new literal through the widget's pick path, repainting the
   *   canvas; reopening the well offers the mint row over the worn
   *   literal.
   * - Apply-variant hotkey (open): with block B selected and the panel
   *   open, shift+v (the keydown daedalus_ui's dispatcher listens
   *   for) opens the wear bar's variant picker with its search field
   *   focused and the add row expanded; Escape closes it with focus back
   *   on the add row.
   * - Claimed axis (open): the container holding B is selected; basic
   *   mode's Layout segment flips it to Columns (the shipped row variant
   *   worn on container_layout through apply_variant), and the wear bar
   *   shows NO Layout row beside the segment that is that wearing's
   *   surface; flipped to Advanced (no Layout section mounted) the
   *   Layout row reads Columns; back in Basic it yields again.
   * - Duplicate without reload (open): block B (wearing the captured
   *   font-size and the picker-committed color) is selected again and
   *   its selection chip's Duplicate verb (daedalus_layout's, on the chip face)
   *   is clicked for real; the mutation settles with the selection on the
   *   clone, the page never reloaded, the clone's map entry equals B's,
   *   the entity root's own style tag holds the clone's @scope block with
   *   both properties (read on the tag, so the preview sheet cannot stand
   *   in for it), and the clone's computed font-size and color equal B's.
   * - Creating posture header (open): with the styled clone selected the
   *   inspector's Save as variant doorway is offered and clicked for
   *   real; the session frame mounts with the name input, the axis select
   *   (the block kind's three axes) and the description input inside the
   *   draft row right after the top row, none of them in the top row; the
   *   session body wears its 0.5rem inset on every side and has no
   *   horizontal overflow (scrollWidth <= clientWidth); the push bar's
   *   Discard closes the draft, leaving the panel as the tail found it.
   * - Border stroke (open): the clone's Surface section renders the
   *   Border row as color well + width field + style well, every part
   *   unset, no chips inside the composite; a real pick of the accent
   *   swatch in the color well's popover commits `border-width: 1px`
   *   (the first width stop), `border-style: solid` and
   *   `border-color: var(--color-accent)` in ONE op, the canvas block
   *   wears the accent-coloured solid hairline on every side, and the
   *   row's wells light their stops with the width field reading 1.
   * - Ring per side (open): the Border row's pencil opens the border
   *   ring; its right cell reads 1px set here (the shorthand covers it);
   *   a real click selects the side (the side editor mounts as the
   *   stroke row over the right longhands); ArrowUp then its release on
   *   the focused cell commits ONE op unsetting the three shorthands and
   *   writing all twelve side longhands (right width 2px, the rest 1px,
   *   four solid, four accent); the cell reads 2px set here, the canvas
   *   wears a 2px right border beside 1px elsewhere, and exactly one
   *   border band paints on the canvas, 2px wide at the block's right
   *   edge: the selected cell's claim over the right side's longhands.
   * - Outline (open): the Outline row renders with no Outline offset row
   *   beside it; typing 2px and blurring commits `outline-width: 2px`
   *   and `outline-style: solid` in one op, the canvas block wears the
   *   solid outline, and the Outline offset row mounts.
   * - Page gap (open): the Page (the entity key) is selected; the edit
   *   canvas's layout root (daedalus_layout's #layout-builder wearing
   *   .daedalus-layout-layout, inside the entity root) holds exactly the two
   *   bands (A from the strict phase, staged in tempstore across the
   *   reload, and B) as its child boxes and computes the declared flex column;
   *   the Basic tab's Layout section renders the Gap row as the detented
   *   field with the stop picker's chevron (the suite binds gap to the
   *   space scale site-wide, the part included, olivero curates the
   *   stops, and under open law a bound dimension is detented, never
   *   closed);
   *   the chevron's menu lists the skin's space stops (0.5rem to 4rem)
   *   as display values, var() on no label, nothing checked while
   *   unset; picking 1rem (the md stop) commits `gap: var(--space-md)`
   *   at `entity:{uuid}::.daedalus-layout-layout` in ONE op through magnetic
   *   capture, the entity root's style tag holds the part rule, the
   *   layout root's computed row-gap reads the token's resolved length,
   *   the bands sit that much further apart than before, and the field
   *   wears 1 with rem as its static unit label and the blessed chrome.
   * - Background (open): a section with one LINKED text block (C) is
   *   placed and selected, and C wears the shipped `button` variant
   *   through apply_variant (offered under open law; strict
   *   guide_example withholds its literal hex and em padding); the
   *   block's Basic panel renders exactly ONE row whose axis reads
   *   "Background" (daedalus_edit_text_block restates the kind's
   *   `background` binding over `['.text-block-link', '']`), its swatch
   *   well in the literal face painted the button's #1a1a2e, the row
   *   hued in the button layer's color, its mark titled "#1a1a2e from
   *   Button", no part caption in the panel, and the map holding nothing
   *   at the part key; an update_style of background-color on C's ROOT
   *   through the op path leaves the row reading the anchor and puts
   *   the inert "Also set on element" line (hinted with the band's
   *   value) in the row's menu while both boxes paint on the canvas;
   *   undo clears the root entry and the line; a REAL Center pick on the
   *   Alignment row while the width chip poses the canvas at Medium
   *   fills the row's mark (`--here`, the element hue, a circle, "center
   *   set here at Medium") and the chip's Base entry hollows it (the hue
   *   kept, "Set at Medium, not here", the row menu's header the same
   *   with Medium as its drive); and a REAL accent pick from the row's
   *   well commits ONE
   *   update_style at `block:{uuid}::.text-block-link` carrying
   *   background-color alone, the anchor wearing the accent on the
   *   canvas and the root untouched; then a section with one UNLINKED
   *   text block (D) is placed and selected, its Basic panel renders one
   *   Background row over the root alone, and the same accent pick
   *   commits ONE update_style at the plain block key.
   * - Write address (open): a band with three LINKED text blocks (two
   *   `p` roots, one `h3`) is placed; the first block's link PART is
   *   selected; the target chip's switcher reads "This link" at the
   *   own address, unlit, no write paint; a real click opens the menu
   *   "Style this at" listing This link (checked), Every a in the
   *   section, Text block links directly in Section, Text block
   *   links in the section (each "new rule"); picking the tag rule lits
   *   the chip at container::a with the selection unchanged, the write
   *   paint slot holds the rule's @scope block with the bare `a`
   *   target, the two sibling anchors wear the dotted box and the
   *   selected one its dashed selection box; the Background well and
   *   Corners field are disabled with "… is not allowed at "a" on the
   *   section …" as their title while Size is live; typing 30px into
   *   Size and blurring commits ONE update_style at container::a,
   *   every anchor computes 30px and the roots keep their sizes; the
   *   reopened menu checks the tag rule with no "new rule" hint;
   *   picking This link clears the chip and the paint, the Size row
   *   reads 30 with its mark "30px inherited from Section (a)",
   *   the Background well is live again; typing 24px commits at the
   *   link's own key and the anchors compute 24px, 30px, 30px.
   * - Wear at the rule (open): container::a is re-seated through the
   *   facade (the switcher's road, already driven above); the wear bar
   *   is live (not read-only) with its add row enabled; the picker lists
   *   exactly the two base:a variants minted before the open flip
   *   ("Accent link", "Quiet link"); picking Accent link dispatches ONE
   *   apply_variant at container::a, the map carries the reference on
   *   the rule's entry beside its font-size and none on the link or the
   *   block, the bar renders one row naming Accent link, every anchor
   *   computes rgb(51, 102, 153) with letter-spacing 2px, and no
   *   anchor or root carries a variant class; the Materials place shows
   *   the chip lit at "Every link in Section", the Quiet link row
   *   unfolds its card with Apply enabled at the styling target, and
   *   the click lands ONE apply_variant at container::a replacing the
   *   axis's reference, every anchor now computing rgb(153, 51, 102)
   *   with letter-spacing 3px while the rule's font-size stays.
   * - Member wear (open): Basic mode again, the first link's part
   *   re-targeted below the selection (the chip back on "This link");
   *   the Text color row's origin is Quiet link's hue with its mark
   *   titled "#993366 from Quiet link at Every link in Section…";
   *   a synthetic right-click on the row opens the provenance menu
   *   offering "Edit Quiet link at …" (opens the variant) and "Edit at
   *   Every link in Section"; picking the latter seats container::a
   *   as the target with the selection still the first link, the chip
   *   lit and the wear bar live with its one row naming Quiet link.
   * - Row source (open): with the rule still seated, a typed 550 in the
   *   Weight field commits ONE update_style at container::a and every
   *   anchor computes font-weight 550; the link part re-targeted, the
   *   Weight field reads 550 with its mark titled "550 inherited from
   *   the section (a)", a synthetic right-click on its row offers "Edit
   *   at the section (a)", and the pick seats container::a with the
   *   selection still the first link and the chip lit.
   */
  public function testBasicStylingScenario(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // The closed-phase claims ride the law of the system setUp
    // activated; assert the posture and the skin so a loosened package
    // (or a fixture left on olivero) cannot silently weaken them.
    $this->assertSame(
      'strict',
      \Drupal::service('daedalus_stylish.style_guide')->enforcement(),
      'The activated guide_example design system ships strict enforcement: the closed phase runs under the strict reference guide\'s own law.',
    );
    // The vocabulary the closed well must offer: guide_example's skin,
    // whose minted type scale IS the Size row.
    $this->assertSame(
      'guide_example',
      \Drupal::service('daedalus_stylish.skin_manager')->activeSkinName(),
      'The guide_example skin is active: its type scale is the closed vocabulary under test.',
    );

    // A real Layout Builder page: the recipe enables styling for the
    // page bundle, so its daedalus_stylish_styles field hosts the style map.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Basic styling page',
    ]);
    $node->save();

    $this->drupalGet($node->toUrl());

    // A wide window: the editing compound follows the measured stage
    // width, and a squeezed stage would land every write condition-keyed
    // under `narrow` instead of `default`.
    $session->resizeWindow(1920, 1200);

    $this->enterEditModeViaCookies($node->toUrl());
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted.',
    );

    $uuid_service = \Drupal::service('uuid');
    $block_a_uuid = $uuid_service->generate();
    $section_a_uuid = $uuid_service->generate();
    $this->placeTextBlock($section_a_uuid, $block_a_uuid, '__placementError');
    $block_a_key = "block:$block_a_uuid";
    $canvas_a = "[data-selectable='$block_a_key']";

    $this->openInspector();

    // Select the block: the facade is the sanctioned programmatic face
    // of a canvas click, and the basic panel follows the committed
    // selection with the block root's controls.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_a_key');");

    // ----- Sections. -----
    // The block's controls group under the roster's four headers in
    // roster order (controls.js groupControls over the payload's
    // controlSections), one header per non-empty section and no
    // per-control header: the baseline binds position, spacing and
    // width (Layout), typography and text color (Text), background,
    // corners and shadow (Surface) and the motion trigger (Motion).
    // The grouping is node-tested; only the browser proves the
    // payload's roster reaches the rendered panel.
    $this->assertJsCondition(
      "(() => { const titles = [...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-inspector__control-section-title')]"
      . " .map((title) => title.textContent.trim());"
      . " return JSON.stringify(titles) === JSON.stringify(['Layout', 'Text', 'Surface', 'Motion']); })()",
      20000,
      'The Basic tab reads exactly the section headers Layout, Text, Surface, Motion for the block.',
    );

    // ----- Closed well commit lands var(). -----
    // The Size row adapted to its CLOSED space: the choice well (one
    // face wearing the current value, the roster behind it in a
    // popover), not an input and not a select. The type scale's rem
    // faces outgrow the chip budget, so the spine answers the well. Its
    // Weight sibling in the same widget is closed too (the suite's
    // constraint_defaults bind font-weight to the weight scale site-wide;
    // guide_example mints regular 400, medium 500, semibold 600, bold
    // 700), but a numeric stop faces as its value (stopFace) and four
    // three-character faces sit within CHIP_BUDGET and FACE_BUDGET, so
    // the same spine answers the chips there: the surface is the
    // per-property value space's answer, never a site-wide mode switch.
    $size_well = self::SIZE_WELL;
    $size_options = self::SIZE_OPTIONS;
    $this->assertNotNull(
      $assert_session->waitForElement('css', $size_well),
      'The scale-bound Size row renders as the choice well under strict enforcement.',
    );
    $weight_row_js = "[...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--typography .daedalus-stylish-inspector__widget-row')]"
      . ".find((row) => row.querySelector('.daedalus-stylish-inspector__widget-axis')?.textContent.trim() === 'Weight')";
    $this->assertJsCondition(
      '(() => { if (document.querySelector(' . json_encode(self::SIZE_INPUT) . ')'
      . " || document.querySelector(\"#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--typography select[aria-label='Size']\")) { return false; }"
      . " const row = $weight_row_js;"
      . " if (!row || row.querySelector('input, select, button.daedalus-stylish-choice-well')) { return false; }"
      . " const faces = [...row.querySelectorAll('button.daedalus-stylish-inspector__segment')].map((chip) => chip.getAttribute('aria-label')).sort();"
      . " return JSON.stringify(faces) === JSON.stringify(['400', '500', '600', '700']); })()",
      20000,
      'Size closed (no Size input, no Size select) while the weight-bound Weight row is the four chips 400, 500, 600, 700 (guide_example\'s weight scale; no input, no select, no well): the surface is derived per property from its own space.',
    );
    // Nothing is set yet: the well wears the unset face and its popover
    // stays closed until a click.
    $this->assertJsCondition(
      "(() => { const well = document.querySelector(" . json_encode($size_well) . ');'
      . " return !!well && well.classList.contains('daedalus-stylish-choice-well--unset')"
      . " && well.getAttribute('aria-expanded') !== 'true'"
      . ' && !document.querySelector(' . json_encode($size_options) . '); })()',
      20000,
      'The unset Size well wears the unset face with its popover closed.',
    );

    // A REAL click on the well opens the popover: the offer is the
    // minted ramp as display VALUES, one listbox row per stop (the caption
    // token faced as 0.8125rem, the title-2 token as 1.5rem), var() on
    // no row, nothing checked while the row is unset.
    $this->clickPanel($size_well);
    $this->assertJsCondition(
      "(() => { const options = Array.from(document.querySelectorAll(" . json_encode($size_options) . '));'
      . ' return options.length > 1'
      . " && options.some((option) => option.textContent.includes('0.8125rem'))"
      . " && options.some((option) => option.textContent.includes('1.5rem'))"
      . " && options.every((option) => !option.textContent.includes('var(') && option.getAttribute('aria-selected') !== 'true'); })()",
      20000,
      'The well lists guide_example\'s minted type scale as display values (caption as 0.8125rem, title-2 as 1.5rem), var() on no face, nothing checked while unset.',
    );

    // Pre-commit facts: the token's resolved size, measured by a probe
    // span in the block's own scope, differs from the block's current
    // font-size, so the restyle below is observable.
    $size_facts = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const block = document.querySelector("$canvas_a");
        const probe = document.createElement('span');
        probe.style.fontSize = 'var(--text-title-2)';
        block.append(probe);
        const tokenSize = getComputedStyle(probe).fontSize;
        probe.remove();
        return JSON.stringify({ tokenSize, before: getComputedStyle(block).fontSize });
      })()
      JS), TRUE);
    $this->assertIsArray($size_facts, 'The pre-commit font-size probe returned its measurements.');
    $this->assertNotSame(
      $size_facts['tokenSize'],
      $size_facts['before'],
      'The title-2 token resolves to a size the block does not already wear, so the commit is observable.',
    );

    // A REAL pick on the listbox row: the stop chosen by its display
    // value, the only name a basic-mode user ever reads (1.5rem is a
    // substring of no other face on the ramp).
    $title_option_xpath = '//div[contains(@class, "daedalus-ui-popover")]//div[contains(@class, "daedalus-stylish-choice-popover")]'
      . '//button[contains(@class, "daedalus-stylish-choice-list__option")][contains(normalize-space(.), "1.5rem")]';
    $this->clickPanelXpath($title_option_xpath);

    // The map read every commit asserts against: the root entity's
    // blueprint mirror, the same authority the canvas re-renders from.
    $map = "(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles ?? {})";
    $this->assertJsCondition(
      $map . '[' . json_encode($block_a_key) . "]?.default?.['font-size'] === 'var(--text-title-2)'",
      20000,
      'The pick committed the stop\'s REFERENCE spelling to the map (well, gesture, op, mirror).',
    );
    // The canvas restyled without reload, through the token's custom
    // property: the browser fact the whole chain only proves here.
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas_a\")).fontSize === " . json_encode($size_facts['tokenSize']),
      20000,
      'The canvas block wears the token\'s resolved font-size: the var() commit restyled it without reload.',
    );
    // The popover closed on the pick and the face keeps the VALUE: the
    // well wears the lit stop's face reading 1.5rem, the commit
    // spelling never surfacing outside code mode.
    $this->assertJsCondition(
      "(() => { const well = document.querySelector(" . json_encode($size_well) . ');'
      . " return !!well && well.classList.contains('daedalus-stylish-choice-well--stop')"
      . " && well.querySelector('.daedalus-stylish-choice-well__text')?.textContent.trim() === '1.5rem'"
      . " && well.getAttribute('aria-expanded') !== 'true'"
      . ' && !document.querySelector(' . json_encode($size_options) . '); })()',
      20000,
      'The popover closed on the pick and the committed reference wears its display value on the well face.',
    );
    // Reopened, the list checks the lit stop and no other; the well's
    // second click closes it again (the trigger toggles).
    $this->clickPanel($size_well);
    $this->assertJsCondition(
      "(() => { const options = Array.from(document.querySelectorAll(" . json_encode($size_options) . '));'
      . " const checked = options.filter((option) => option.getAttribute('aria-selected') === 'true');"
      . " return options.length > 1 && checked.length === 1 && checked[0].textContent.includes('1.5rem'); })()",
      20000,
      'Reopened, the well\'s list checks the committed title-2 stop and no other.',
    );
    $this->clickPanel($size_well);
    $this->assertJsCondition(
      '!document.querySelector(' . json_encode($size_options) . ')',
      20000,
      'The well\'s second click closed its popover.',
    );

    // ----- A Base value posed at Medium is the row's lit value. -----
    // Seam: the control model's stack read. controls.js builds the
    // block's stack once per control and reads each owned property
    // through stackAt's cascade at the pose; the shown value is the
    // WINNER's, whichever layer delivers it. Posed at Medium the Size
    // row's own entry says nothing at the compound while the Base commit
    // of var(--text-title-2) still delivers there, so the well wears
    // the Base value LIT on its stop face (1.5rem, never the var()
    // spelling, never a ghost), the row's mark wears the element layer's
    // hue with "from Base" as its story, and the Unset gesture is gone:
    // picking the lit stop again is refused (controls.js ownAtPose, the
    // one Unset gate) since nothing of the element's own delivers at
    // the pose. The skin branch of the same read is node-pinned (no
    // shipped fixture skin sets a basic-owned property on a block
    // scope), so the pose is the wire's browser proof. Posed through
    // the width chip's real menu, the idiom AdvancedSectionsBehaviorTest
    // proves; driven back through the row menu's "Go to Base" doorway,
    // where the same pick IS the unset.
    $size_row_js = "[...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--typography .daedalus-stylish-inspector__widget-row')]"
      . ".find((row) => row.querySelector('.daedalus-stylish-inspector__widget-axis')?.textContent.trim() === 'Size')";
    $size_map_js = $map . '[' . json_encode($block_a_key) . "]?.default?.['font-size']";
    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-chip-row")]/button[contains(@class, "daedalus-stylish-chip--width")]');
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'Lit value at a pose: the width chip opened its menu on the shared primitive.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Medium').click();"
    );
    $this->assertJsCondition(
      "document.documentElement.hasAttribute('data-canvas-width')"
      . " && (() => { const chip = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-chip--width');"
      . " return !!chip && chip.classList.contains('daedalus-stylish-chip--lit') && chip.textContent.includes('Medium'); })()",
      20000,
      'Lit value at a pose: the width chip posed the canvas at Medium (the document wears the width stamp, the chip reads Medium, lit).',
    );
    // The well: the Base commit lit on the stop face at the Medium
    // compound, the winner of the stack read, no ghost text anywhere.
    $this->assertJsCondition(
      "(() => { const well = document.querySelector(" . json_encode($size_well) . ');'
      . " return !!well && well.classList.contains('daedalus-stylish-choice-well--stop')"
      . " && !well.classList.contains('daedalus-stylish-choice-well--unset')"
      . " && well.querySelector('.daedalus-stylish-choice-well__text')?.textContent.trim() === '1.5rem'"
      . " && !well.querySelector('[class*=\"--ghost\"]')"
      . " && well.getAttribute('title') !== 'Unset; 1.5rem applies'; })()",
      20000,
      'Lit value at a pose: posed at Medium, the Size well wears the Base commit LIT on its stop face, 1.5rem (the display face, never var(--text-title-2)), no ghost text and no "Unset; 1.5rem applies" title: the shown value is the stack winner\'s whichever condition delivers it.',
    );
    // The mark: the same read as the face, so the row's hue is the
    // element layer's (the own layer delivering from Base) and its story
    // names the delivering condition.
    $this->assertJsCondition(
      "($size_row_js)?.style.getPropertyValue('--daedalus-stylish-origin').trim() === 'hsl(213 90% 66%)'",
      20000,
      'Lit value at a pose: the Size row\'s --daedalus-stylish-origin is the element layer\'s hue (layers.js layerColor(ELEMENT_LAYER)): the face and the mark come from the one stack read of the Base commit.',
    );
    $this->assertJsCondition(
      "!!($size_row_js)?.querySelector('[title=\"1.5rem from Base\"]')",
      20000,
      'Lit value at a pose: the Size row\'s mark is titled "1.5rem from Base", originOf\'s inherited-from-own-at-another-condition sentence.',
    );
    // No Unset at the pose: the reopened list checks the lit stop, and
    // picking it again (the well's one unset gesture) is refused since
    // the element's own entry says nothing at Medium: no op leaves the
    // panel, the map keeps the Base commit, the well stays lit.
    $this->recordOps();
    $this->clickPanel($size_well);
    $this->assertJsCondition(
      "(() => { const options = Array.from(document.querySelectorAll(" . json_encode($size_options) . '));'
      . " const checked = options.filter((option) => option.getAttribute('aria-selected') === 'true');"
      . " return options.length > 1 && checked.length === 1 && checked[0].textContent.includes('1.5rem'); })()",
      20000,
      'Lit value at a pose: reopened at Medium, the well\'s list checks the lit Base stop and no other.',
    );
    $session->executeScript(
      'Array.from(document.querySelectorAll(' . json_encode($size_options) . '))'
      . ".find((option) => option.getAttribute('aria-selected') === 'true').click();"
    );
    $this->assertJsCondition(
      '!document.querySelector(' . json_encode($size_options) . ')'
      . " && $size_map_js === 'var(--text-title-2)'"
      . ' && (() => { const well = document.querySelector(' . json_encode($size_well) . ');'
      . " return !!well && well.classList.contains('daedalus-stylish-choice-well--stop')"
      . " && well.querySelector('.daedalus-stylish-choice-well__text')?.textContent.trim() === '1.5rem'; })()",
      20000,
      'Lit value at a pose: picking the lit stop again at Medium closed the list and was REFUSED as an unset (the map keeps var(--text-title-2) at Base, the well stays lit 1.5rem): nothing of the element\'s own delivers at the pose, so there is nothing to unset.',
    );
    $this->assertSame(
      0,
      (int) $session->evaluateScript(self::OP_LOG . '.length'),
      'Lit value at a pose: the refused unset dispatched no op.',
    );
    // The origin in words and its drive doorway: the row menu's header
    // tells the story the mark wears with no Unset item beside it, and
    // "Go to Base" drives the canvas back to the delivering condition.
    $session->executeScript(
      "(() => { const row = $size_row_js; const rect = row.getBoundingClientRect();"
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true,"
      . ' clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2 })); })();'
    );
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')",
      20000,
      'Lit value at a pose: the right-click on the Size row opened the row menu with its origin header on the shared primitive.',
    );
    $size_menu = json_decode((string) $session->evaluateScript(
      "JSON.stringify({ header: document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header').textContent,"
      . " labels: Array.from(document.querySelectorAll('.daedalus-ui-menu__item .daedalus-ui-menu__label')).map((label) => label.textContent.trim()) })"
    ), TRUE);
    $this->assertStringContainsString('1.5rem', $size_menu['header'], 'Lit value at a pose: the row menu header names the lit value by its display face. Header: ' . $size_menu['header']);
    $this->assertStringContainsString('Base', $size_menu['header'], 'Lit value at a pose: the row menu header names the delivering condition (Base), not the posed one. Header: ' . $size_menu['header']);
    $this->assertNotContains('Unset', $size_menu['labels'], 'Lit value at a pose: the row menu offers no Unset doorway at Medium (the own entry delivers at Base, not at the pose). Items: ' . implode(', ', $size_menu['labels']));
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Go to Base').click();"
    );
    $this->assertJsCondition(
      "!document.documentElement.hasAttribute('data-canvas-width')"
      . " && !document.querySelector('.daedalus-ui-menu')"
      . " && (() => { const chip = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-chip--width');"
      . " return !!chip && !chip.classList.contains('daedalus-stylish-chip--lit') && chip.textContent.trim().startsWith('Base'); })()",
      20000,
      'Lit value at a pose: "Go to Base" drove the canvas back (the width stamp is gone, the chip reads Base, unlit, the row menu closed).',
    );
    $this->assertJsCondition(
      "(() => { const well = document.querySelector(" . json_encode($size_well) . ');'
      . " return !!well && well.classList.contains('daedalus-stylish-choice-well--stop')"
      . " && well.querySelector('.daedalus-stylish-choice-well__text')?.textContent.trim() === '1.5rem'; })()",
      20000,
      'Lit value at a pose: back at Base the Size well wears the committed stop\'s face, 1.5rem lit, the same face the pose showed: the value is the row\'s own at the pose now.',
    );
    // Unset present at Base: the same pick of the checked row now IS the
    // unset (the own entry delivers at the exact pose), the map loses
    // font-size and the well rests unset; a fresh pick of 1.5rem
    // restores the value for the steps that follow.
    $this->recordOps();
    $this->clickPanel($size_well);
    $this->assertJsCondition(
      'Array.from(document.querySelectorAll(' . json_encode($size_options) . "))"
      . ".some((option) => option.getAttribute('aria-selected') === 'true' && option.getAttribute('title') === 'Unset')",
      20000,
      'Unset at Base: the reopened list\'s checked row carries the Unset title.',
    );
    $session->executeScript(
      'Array.from(document.querySelectorAll(' . json_encode($size_options) . '))'
      . ".find((option) => option.getAttribute('aria-selected') === 'true').click();"
    );
    $this->assertJsCondition(
      "$size_map_js === undefined"
      . ' && (() => { const well = document.querySelector(' . json_encode($size_well) . ');'
      . " return !!well && well.classList.contains('daedalus-stylish-choice-well--unset'); })()",
      20000,
      'Unset at Base: picking the lit stop again at Base unset it (the map holds no font-size for the block, the well rests unset): the own entry delivered at the exact pose, so the gesture had something to remove.',
    );
    $this->assertOneOp($block_a_key, ['font-size' => NULL], 'Unset at Base: the unset was ONE update_style op on the block carrying font-size: null.');
    $this->clickPanel($size_well);
    $session->executeScript(
      'Array.from(document.querySelectorAll(' . json_encode($size_options) . '))'
      . ".find((option) => option.textContent.includes('1.5rem')).click();"
    );
    $this->assertJsCondition(
      "$size_map_js === 'var(--text-title-2)'"
      . ' && (() => { const well = document.querySelector(' . json_encode($size_well) . ');'
      . " return !!well && well.classList.contains('daedalus-stylish-choice-well--stop')"
      . " && well.querySelector('.daedalus-stylish-choice-well__text')?.textContent.trim() === '1.5rem'; })()",
      20000,
      'Unset at Base: a fresh pick of 1.5rem restored var(--text-title-2) to the map and the lit face to the well for the steps that follow.',
    );

    // ----- The Font row wears the skin's stacks and commits a reference. -----
    // Seam: the literal face beside a reference commit, across the token
    // scope boundary. daedalus_stylish's own site declaration binds font-family
    // to the font scale, so under strict enforcement the Text section's
    // Font row is the closed offer of the guide_example skin's stacks
    // and nothing else. guide_example mints three DISTINCT stacks (body,
    // heading, mono: no collision), and a font stop faces by its token
    // name (stopFace on a non-numeric scale), so the seven-character
    // heading face outgrows FACE_BUDGET and the spine answers the WELL
    // (surfaceFor), the surface the Size row proved above, never the
    // chips. The panel sits OUTSIDE the token scope, so a face wears
    // its stack through the stop's literal display value (ChoiceRow's
    // faceStyle seam, the typography widget's familyFace: every option
    // in the well's listbox and the well's own lit face): the browser
    // compares an option's computed font-family with the token resolved
    // INSIDE the block, the one place var(--font-*) means anything.
    $this->recordOps();
    $font_well = self::FONT_WELL;
    $font_options = self::SIZE_OPTIONS;
    $font_row_js = "[...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--typography .daedalus-stylish-inspector__widget-row')]"
      . ".find((row) => row.querySelector('.daedalus-stylish-inspector__widget-axis')?.textContent.trim() === 'Font')";
    // A listbox row by the token NAME its label opens with ("mono · ...",
    // stopLabel's "name · value"): a name can be a substring of a stack
    // ("mono" of "ui-monospace"), so only the label's head names one row.
    $font_option_xpath = static fn (string $name): string => '//div[contains(@class, "daedalus-ui-popover")]//div[contains(@class, "daedalus-stylish-choice-popover")]'
      . '//button[contains(@class, "daedalus-stylish-choice-list__option")][starts-with(normalize-space(.//span[contains(@class, "daedalus-stylish-choice-list__label")]), "' . $name . ' ·")]';
    $font_option_names_js = '(() => Array.from(document.querySelectorAll(' . json_encode($font_options) . '))'
      . ".map((option) => option.querySelector('.daedalus-stylish-choice-list__label')?.textContent.split('·')[0].trim()))()";
    $this->assertJsCondition(
      "(() => { const row = $font_row_js; if (!row) { return false; }"
      . ' const well = document.querySelector(' . json_encode($font_well) . ');'
      . " return !!well && row.contains(well)"
      . " && !row.querySelector('button.daedalus-stylish-inspector__segment, input, select')"
      . " && well.classList.contains('daedalus-stylish-choice-well--unset')"
      . " && well.getAttribute('aria-expanded') !== 'true'"
      . ' && !document.querySelector(' . json_encode($font_options) . '); })()',
      20000,
      'The Text section\'s Font row is the choice well (no chips, no input, no select: the heading face outgrows the chip budget), wearing the unset face with its popover closed while nothing is set.',
    );
    // A REAL click on the well opens the popover: the offer is the
    // minted font scale, one listbox row per stack faced by its token
    // name, var() on no row, nothing checked while the row is unset.
    $this->clickPanel($font_well);
    $this->assertJsCondition(
      '(() => { const names = ' . $font_option_names_js . ';'
      . ' const options = Array.from(document.querySelectorAll(' . json_encode($font_options) . '));'
      . " return JSON.stringify([...names].sort()) === JSON.stringify(['body', 'heading', 'mono'])"
      . " && options.every((option) => !option.textContent.includes('var(') && option.getAttribute('aria-selected') !== 'true'); })()",
      20000,
      'The Font well lists guide_example\'s three stacks by token name (body, heading, mono), var() on no row, nothing checked while unset.',
    );
    // Each option's computed stack against the token resolved inside the
    // block: the same string the canvas will wear on a pick.
    $font_options_json = json_encode($font_options);
    $font_facts = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const block = document.querySelector("$canvas_a");
        const resolved = (name) => {
          const probe = document.createElement('span');
          probe.style.fontFamily = 'var(--font-' + name + ')';
          block.append(probe);
          const stack = getComputedStyle(probe).fontFamily;
          probe.remove();
          return stack;
        };
        const option = (name) => {
          const match = Array.from(document.querySelectorAll($font_options_json))
            .find((candidate) => candidate.querySelector('.daedalus-stylish-choice-list__label')?.textContent.trim().startsWith(name + ' ·'));
          return match ? getComputedStyle(match).fontFamily : null;
        };
        return JSON.stringify({
          heading: { option: option('heading'), token: resolved('heading') },
          mono: { option: option('mono'), token: resolved('mono') },
          before: getComputedStyle(block).fontFamily,
        });
      })()
      JS), TRUE);
    $this->assertIsArray($font_facts, 'The Font options and the block\'s font stacks were measured.');
    $this->assertSame(
      $font_facts['heading']['token'],
      $font_facts['heading']['option'],
      'The heading option wears the heading token\'s stack as its own computed font-family (the literal display value, resolved outside the token scope).',
    );
    $this->assertSame(
      $font_facts['mono']['token'],
      $font_facts['mono']['option'],
      'The mono option wears the mono token\'s stack as its own computed font-family.',
    );
    $this->assertStringContainsString('Roboto', $font_facts['heading']['option'], 'The heading option\'s stack is guide_example\'s system-ui stack (Roboto among its faces).');
    $this->assertStringContainsString('Consolas', $font_facts['mono']['option'], 'The mono option\'s stack is guide_example\'s ui-monospace stack (Consolas among its faces).');
    $this->assertNotSame(
      $font_facts['mono']['token'],
      $font_facts['before'],
      'The block does not already wear the mono stack, so the pick below is observable.',
    );
    // A REAL pick on the mono row: the reference lands alone, in one
    // op, and the canvas wears the resolved stack.
    $this->clickPanelXpath($font_option_xpath('mono'));
    $this->assertJsCondition(
      $map . '[' . json_encode($block_a_key) . "]?.default?.['font-family'] === 'var(--font-mono)'",
      20000,
      'The mono pick committed the token\'s REFERENCE to the map (well, gesture, op, mirror).',
    );
    $this->assertOneOp($block_a_key, ['font-family' => 'var(--font-mono)'], 'The mono pick was ONE update_style op carrying font-family alone.');
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas_a\")).fontFamily === " . json_encode($font_facts['mono']['token']),
      20000,
      'The canvas block wears the mono token\'s resolved stack: the var() commit restyled it without reload.',
    );
    // The popover closed on the pick and the well wears the lit stop:
    // its face reads the token name and, through the same faceStyle
    // seam the options wore, is set in the mono stack itself.
    $this->assertJsCondition(
      '(() => { const well = document.querySelector(' . json_encode($font_well) . ');'
      . " const text = well?.querySelector('.daedalus-stylish-choice-well__text');"
      . " return !!well && !!text && well.classList.contains('daedalus-stylish-choice-well--stop')"
      . " && text.textContent.trim() === 'mono'"
      . ' && getComputedStyle(text).fontFamily === ' . json_encode($font_facts['mono']['token'])
      . " && well.getAttribute('aria-expanded') !== 'true'"
      . ' && !document.querySelector(' . json_encode($font_options) . '); })()',
      20000,
      'The popover closed on the pick and the Font well\'s face reads mono, set in the mono stack.',
    );
    // The well's unset is the lit stop's own pick (choice-row.js: picking
    // the lit stop unsets, as on the chips; the listbox titles the
    // checked row Unset). Reopened, the list checks mono alone; a REAL
    // click on that row drops the property from the map and the block
    // falls back to what it wore before.
    $this->clickPanel($font_well);
    $this->assertJsCondition(
      '(() => { const options = Array.from(document.querySelectorAll(' . json_encode($font_options) . '));'
      . " const checked = options.filter((option) => option.getAttribute('aria-selected') === 'true');"
      . ' return options.length === 3 && checked.length === 1'
      . " && checked[0].querySelector('.daedalus-stylish-choice-list__label')?.textContent.trim().startsWith('mono ·')"
      . " && checked[0].getAttribute('title') === 'Unset'; })()",
      20000,
      'Reopened, the Font well\'s list checks the committed mono stop alone and titles it Unset.',
    );
    $this->clickPanelXpath($font_option_xpath('mono'));
    $this->assertJsCondition(
      $map . '[' . json_encode($block_a_key) . "]?.default?.['font-family'] === undefined",
      20000,
      'The lit mono row\'s pick cleared font-family from the map.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas_a\")).fontFamily === " . json_encode($font_facts['before']),
      20000,
      'The canvas block wears its previous stack again after the unset.',
    );
    $this->assertJsCondition(
      '(() => { const well = document.querySelector(' . json_encode($font_well) . ');'
      . " return !!well && well.classList.contains('daedalus-stylish-choice-well--unset')"
      . " && well.getAttribute('aria-expanded') !== 'true'"
      . ' && !document.querySelector(' . json_encode($font_options) . '); })()',
      20000,
      'The Font well wears the unset face again with its popover closed after the unset.',
    );

    // ----- Empty-vocabulary row absent. -----
    // width is bound to the measure scale, which no skin mints: the
    // closed space is honestly empty, so the Width control renders NO
    // field row (there is nothing legal to offer), while the typography
    // rows beside it render normally.
    $this->assertJsCondition(
      "(() => { const widget = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--width');"
      . " return !!widget && !widget.querySelector('.daedalus-stylish-dimension'); })()",
      20000,
      'The Width row is absent under strict enforcement: its bound scale mints nothing, the closed vocabulary is empty.',
    );

    // ----- The padding control at every depth (strict). -----
    // Placement curates offers and birth, never legality: the container
    // kind grants the whole spacing group, so the one Spacing control
    // writes the uniform `padding` shorthand on a container nested inside
    // the band and on the band itself. The seam only a browser sees: the
    // served grant (no depth projection) -> controlModels' granted subset
    // -> the padding row's shorthandGranted read, observed as WHAT the
    // control writes at both depths; the depth read still shapes OFFERS
    // (the nested picker withholds the root-only Width axis). The row is
    // PaddingRow's DimensionField over the shorthand's value space, and
    // the suite binds padding to the space scale site-wide
    // (constraint_defaults: scale space, allowed 0) with guide_example's
    // stop curation merged over it (2/4/6/8/12/16 of its ladder), closed
    // under the strict reference system's law, so at both depths the
    // field is the closed dimension select (the stops faced by value,
    // the reference the pick commits), never a text input; the 8 stop
    // (2rem) is the value written.
    $nested_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_section',
              uuid: '$nested_uuid',
              location: { anchor: '$section_a_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__nestError = error.message;
        });
      })();
      JS);
    $this->assertNotNull(
      $assert_session->waitForElement('css', "[data-selectable='container:$nested_uuid']", 20000),
      'The nested container rendered: ' . ($session->evaluateScript('window.__nestError ?? null') ?? ''),
    );
    // Selecting the nested container re-renders the panel. Rows are worn
    // references only and the nested container wears nothing, so its bar
    // is the add row alone; the OFFER lives in the variant picker, whose
    // groups (Layout among them, never the root-only Width) beside the
    // mounted Layout section are the signature that the nested container,
    // not the band or the block, is bound.
    $session->executeScript("Drupal.Daedalus.Selection.select('container:$nested_uuid');");
    $this->assertJsCondition(
      "!!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__layout')"
      . " && !document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-wear-bar__row')"
      . " && !!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-wear-bar__add:not(:disabled)')",
      20000,
      'The nested container is bound: the Layout section mounted, no worn row, the add row offers.',
    );
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-wear-bar__add');
    $this->assertJsCondition(<<<JS
      (() => {
        const groups = [...document.querySelectorAll('.daedalus-ui-popover .daedalus-stylish-variant-picker__group[role="group"]')]
          .map((group) => group.getAttribute('aria-label'));
        return groups.includes('Layout') && !groups.includes('Width');
      })()
      JS,
      20000,
      'The nested container\'s picker offers Layout and no root-only Width.',
    );
    $this->pressKeyOnFocused('Escape');
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker')",
      20000,
      'Escape closed the nested container\'s variant picker.',
    );
    $padding_select = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--spacing select.daedalus-stylish-dimension__select[aria-label='Padding']";
    $padding_input = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--spacing input[aria-label='Padding']";
    $this->assertJsCondition(
      '!!document.querySelector(' . json_encode($padding_select) . ')'
      . ' && !document.querySelector(' . json_encode($padding_input) . ')',
      20000,
      'The nested container\'s Spacing control renders its Padding row as the closed dimension select (padding bound to the skin\'s space scale under strict law), no text input.',
    );
    // The 8 stop, picked by the face its option wears (2rem): the pick
    // is the commit, and the shorthand carries the stop's reference.
    $this->pickPanelOption($padding_select, '2rem');
    $nested_key = "container:$nested_uuid";
    $this->assertJsCondition(
      "(() => { const entry = $map" . '[' . json_encode($nested_key) . "]?.default ?? {};"
      . " return entry.padding === 'var(--space-8)' && entry['padding-top'] === undefined && entry['padding-bottom'] === undefined; })()",
      20000,
      'The nested container\'s padding row wrote the uniform `padding` shorthand as the 8 stop\'s reference, var(--space-8) (the suite\'s space binding, guide_example\'s stop, the strict reference\'s law).',
    );
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(\"[data-selectable='$nested_key']\"));"
      . " return style.paddingTop !== '0px' && style.paddingLeft === style.paddingTop && style.paddingRight === style.paddingTop; })()",
      20000,
      'The nested container wears the shorthand on every side (the inline sides included).',
    );

    // The band itself: the same control, the same stop picked, the same
    // shorthand written (the grant is the declaration's at every depth).
    $session->executeScript("Drupal.Daedalus.Selection.select('container:$section_a_uuid');");
    $worn_labels = "[...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-wear-bar__row .daedalus-stylish-wear-bar__label')].map((label) => label.textContent.trim())";
    $this->assertJsCondition(
      "(() => { const labels = $worn_labels; return labels.includes('Width'); })()",
      20000,
      'The band is bound (its wear bar shows the worn root-only Width row, the birth default).',
    );
    $this->assertJsCondition(
      '!!document.querySelector(' . json_encode($padding_select) . ')'
      . ' && !document.querySelector(' . json_encode($padding_input) . ')',
      20000,
      'The band\'s Spacing control renders its Padding row as the same closed dimension select, no text input.',
    );
    $this->pickPanelOption($padding_select, '2rem');
    $band_key = "container:$section_a_uuid";
    $this->assertJsCondition(
      "(() => { const entry = $map" . '[' . json_encode($band_key) . "]?.default ?? {};"
      . " return entry.padding === 'var(--space-8)' && entry['padding-top'] === undefined && entry['padding-bottom'] === undefined; })()",
      20000,
      'The band\'s padding row wrote the uniform `padding` shorthand as var(--space-8): the grant is the same at root depth.',
    );
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(\"[data-selectable='$band_key']\"));"
      . " return style.paddingTop !== '0px' && style.paddingLeft === style.paddingTop && style.paddingRight === style.paddingTop; })()",
      20000,
      'The band wears the shorthand on every side: its own padding masks the width variant\'s inline inset (a layer mask the inspector surfaces, never a withheld grant).',
    );
    // Back on the block for the open-flip steps below.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_a_key');");

    // ----- The open flip. -----
    // The site switches back to the recipe's olivero design system,
    // open as shipped (the open reference: no mode is forced on either
    // guide), server-side; the payload caches on the activation pointer
    // and the system's tag, so the reload serves the open guide with
    // olivero's vocabulary. The strict phase's guide_example references
    // stay in the staged map: under open law an unminted reference is
    // inert, never refused, and no open-phase claim reads block A or
    // section A's own values. The Edit Mode cookies persist, so the next
    // render is still the editing chrome.
    // Two base:a variants for the wear-at-the-rule leg at the end: the
    // site ships none, and a rule's offer is its landing's (the tag `a`
    // for container::a), so these two are exactly what the picker
    // lists there. Minted before the activation so the materials
    // payload the reload boots the store with (the roster's cache rides
    // the active-system config tag) carries them.
    foreach ([
      ['label' => 'Accent link', 'color' => '#336699', 'letter-spacing' => '2px'],
      ['label' => 'Quiet link', 'color' => '#993366', 'letter-spacing' => '3px'],
    ] as $link_variant) {
      $variant = DaedalusStylishVariant::create([
        'label' => $link_variant['label'],
        'scope' => 'base:a',
      ]);
      $variant->setStyles(['' => ['default' => ['color' => $link_variant['color'], 'letter-spacing' => $link_variant['letter-spacing']]]]);
      $variant->save();
    }
    // One published gradient swatch for the Background replacement step:
    // the client guide payload carries it as the widget's gradient stop
    // and the compiled swatch stylesheet delivers its custom property, so
    // it too is minted before the activation and the reload.
    $swatch = DaedalusStylishSwatch::create(['label' => 'Hero fade', 'kind' => 'gradient']);
    $swatch->setSwatchValue('linear-gradient(135deg, #e94560, #16213e)')->save();
    $this->assertSame('hero_fade', $swatch->getMachineName(), 'The gradient swatch minted as --swatch-hero_fade.');
    $this->activateSkin('olivero', 'olivero');
    $this->assertSame(
      'open',
      \Drupal::service('daedalus_stylish.style_guide')->enforcement(),
      'The re-activated olivero design system ships open enforcement: the open phase runs on the open reference as shipped.',
    );
    $this->assertSame(
      'olivero',
      \Drupal::service('daedalus_stylish.skin_manager')->activeSkinName(),
      'The olivero skin is active again: its type and space scales are the detented rows\' offers.',
    );
    $this->drupalGet($node->toUrl());
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted after the switch to the open reference.',
    );

    // A fresh block for the open phase: the capture claim needs no
    // carried-over state, only the same binding under the open posture.
    $block_b_uuid = $uuid_service->generate();
    $section_b_uuid = $uuid_service->generate();
    $this->placeTextBlock($section_b_uuid, $block_b_uuid, '__openPlacementError');
    $block_b_key = "block:$block_b_uuid";

    $this->openInspector();
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_b_key');");

    // ----- The detented return. -----
    // The same binding, constraints no longer law: the Size row is a
    // text input again, with the stop picker's chevron after it (the
    // curated offer behind free entry; no native list, which would
    // filter the stops against the typed text).
    $size_input = self::SIZE_INPUT;
    $size_chevron = self::SIZE_CHEVRON;
    $this->assertNotNull(
      $assert_session->waitForElement('css', $size_input),
      'The Size row renders as the detented text input under open enforcement.',
    );
    $this->assertJsCondition(
      "(() => { const input = document.querySelector(" . json_encode($size_input) . ');'
      . ' const chevron = document.querySelector(' . json_encode($size_chevron) . ');'
      . " return !!input && !input.hasAttribute('list')"
      . " && !!chevron && chevron.getAttribute('aria-haspopup') === 'menu'; })()",
      20000,
      'The detented Size input carries the stop picker chevron (aria-haspopup=menu) and no native list.',
    );
    // The Width row is back: with constraints no longer law, the empty
    // closed space opens up, so the strict-phase absence was the
    // law-gated hide, not a lost row.
    $this->assertJsCondition(
      "!!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--width .daedalus-stylish-dimension')",
      20000,
      'The Width row returns under open enforcement: the empty-vocabulary hide is law-gated.',
    );

    // ----- The stop picker lists every stop; a pick captures the ref. -----
    // A REAL click on the chevron: the field's hook opens the shared menu
    // through the cross-library facade (Drupal.Daedalus.menu), the
    // host renders in the leader document, and every stop lists as its
    // display value (var() on no label), no header, nothing checked
    // while the field is empty.
    $this->clickPanel($size_chevron);
    $this->assertJsCondition(
      "(() => { const menu = document.querySelector('.daedalus-ui-menu'); if (!menu) { return false; }"
      . " const labels = Array.from(menu.querySelectorAll('.daedalus-ui-menu__label')).map((label) => label.textContent);"
      . " return labels.some((label) => label.includes('0.85rem')) && labels.some((label) => label.includes('1.25rem'))"
      . " && labels.every((label) => !label.includes('var('))"
      . " && !menu.querySelector('.daedalus-ui-menu__header')"
      . " && !menu.querySelector('[aria-checked=\"true\"]'); })()",
      20000,
      'The chevron opens the shared menu listing the type scale as display values (caption as 0.85rem, lead as 1.25rem), headerless, nothing checked on an empty field.',
    );
    // The pick: the menu closes, then the item runs (preview the stop's
    // display value, settle it), and magnetic capture commits the
    // token's reference.
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.includes('1.25rem')).click();"
    );
    $this->assertJsCondition(
      $map . '[' . json_encode($block_b_key) . "]?.default?.['font-size'] === 'var(--text-lead)'",
      20000,
      'Picking the lead stop committed the token\'s REFERENCE (menu pick, preview, settleValue, magnetic capture, the op path).',
    );
    $this->assertJsCondition(
      "(() => { const input = document.querySelector(" . json_encode($size_input) . ');'
      . " return !document.querySelector('.daedalus-ui-menu') && input.value === '1.25'"
      . " && input.closest('.daedalus-stylish-dimension').querySelector('.daedalus-stylish-dimension__unit-label').textContent === 'rem'"
      . " && !input.closest('.daedalus-stylish-dimension').querySelector('.daedalus-stylish-dimension__unit-well')"
      . " && input.closest('.daedalus-stylish-dimension').classList.contains('daedalus-stylish-dimension--blessed'); })()",
      20000,
      'The menu closed on the pick and the face wears the stop\'s display value (the number in the input, the unit on the static label; the type scale names rem, so no select) with the blessed chrome.',
    );

    // ----- Alt+ArrowDown from the input opens the picker. -----
    // The keyboard opener: the same menu, anchored to the value box, the
    // worn stop checked and no other; an outside pointerdown dismisses.
    $session->executeScript(
      '(() => { const input = document.querySelector(' . json_encode($size_input) . ');'
      . ' input.focus();'
      . " input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', altKey: true, bubbles: true, cancelable: true })); })();"
    );
    $this->assertJsCondition(
      "(() => { const menu = document.querySelector('.daedalus-ui-menu'); if (!menu) { return false; }"
      . " const checked = Array.from(menu.querySelectorAll('[aria-checked=\"true\"] .daedalus-ui-menu__label')).map((label) => label.textContent);"
      . " return checked.length === 1 && checked[0].includes('1.25rem'); })()",
      20000,
      'Alt+ArrowDown in the input opens the stop picker with the worn stop (lead) checked and no other.',
    );
    $session->executeScript("document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));");
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu')",
      20000,
      'An outside pointerdown dismissed the stop picker.',
    );

    // ----- Typed capture goes blessed. -----
    // Typing the caption token's value in a different spelling: a real input
    // event arms the typed flag, the blur settles, and magnetic capture
    // commits the token's reference. Type and settle in separate ticks,
    // like a real user's keystrokes then blur.
    $this->typeIntoPanel($size_input, '.85rem');
    $this->assertJsCondition(
      'document.querySelector(' . json_encode($size_input) . ").value === '.85'",
      20000,
      'The typed text echoed into the controlled input as its number face (the unit rides the label).',
    );
    $this->blurPanel($size_input);
    $this->assertJsCondition(
      $map . '[' . json_encode($block_b_key) . "]?.default?.['font-size'] === 'var(--text-caption)'",
      20000,
      'The typed settle committed the matched token\'s REFERENCE, not the literal (magnetic capture through the op path).',
    );
    $this->assertJsCondition(
      "(() => { const input = document.querySelector(" . json_encode($size_input) . ');'
      . " const field = input.closest('.daedalus-stylish-dimension');"
      . " return input.value === '0.85'"
      . " && !!field && field.classList.contains('daedalus-stylish-dimension--blessed'); })()",
      20000,
      'The captured reference wears its display value\'s number on the input face with the blessed chrome.',
    );

    // ----- A literal color round-trips through the well and the chip. -----
    // The Advanced half: the Text color row (Typography, open because the
    // section carries the committed font-size) is the color field over
    // `color`'s value space; a typed literal that matches no palette stop
    // settles as itself through the row's ordinary commit path.
    $canvas_b = "[data-selectable='$block_b_key']";
    $this->switchMode('Advanced');
    $color_input = self::ADVANCED_COLOR_INPUT;
    $this->assertNotNull(
      $assert_session->waitForElement('css', $color_input),
      'The Advanced Text color row renders the color field\'s text input.',
    );
    $this->assertJsCondition(
      "(() => { const input = document.querySelector(" . json_encode($color_input) . ');'
      . " const field = input.closest('.daedalus-stylish-color-field');"
      . " const picker = field?.querySelector('.daedalus-stylish-native-picker');"
      . " return !!field && !!field.querySelector('.daedalus-stylish-color-field__well')"
      . " && picker?.type === 'color' && getComputedStyle(picker).opacity === '0'"
      . " && [...field.children].every((child) => child.matches('.daedalus-stylish-color-field__well, .daedalus-stylish-native-picker, .daedalus-stylish-color-field__text')); })()",
      20000,
      'The color field is face | text with the native picker a hidden input (the open render: one door, the lane).',
    );

    // The color lane: the face opens the palette popover, whose lane
    // row carries "Pick a color"; clicking it closes the popover and
    // opens the native chooser (showPicker, inside the click). The
    // chooser cannot be driven headlessly, so its two events are
    // dispatched on the hidden input: input previews (the text and the
    // face follow the draft), change settles through the row's commit.
    // The field is found by its text input's name (xpath: Mink's CSS
    // translator has no :has(); the browser-side querySelectors below do).
    $color_field = "#daedalus_stylish_inspector details.daedalus-stylish-inspector__section .daedalus-stylish-color-field:has(input[aria-label='Text color'])";
    $color_field_xpath = '//details[contains(@class, "daedalus-stylish-inspector__section")]//span[contains(@class, "daedalus-stylish-color-field")][.//input[@aria-label = "Text color"]]';
    $this->clickPanelXpath($color_field_xpath . '/button[contains(@class, "daedalus-stylish-color-field__well")]');
    $this->assertNotNull(
      $assert_session->waitForElement('css', '.daedalus-ui-popover .daedalus-stylish-swatch-popover .daedalus-stylish-palette-lanes'),
      'The face opened the palette popover with the lane row after the groups.',
    );
    $this->clickPanelXpath('//div[contains(@class, "daedalus-ui-popover")]//div[contains(@class, "daedalus-stylish-swatch-popover")]//button[contains(@class, "daedalus-stylish-palette-lane--color")]');
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-swatch-popover')",
      20000,
      'The color lane closed the popover.',
    );
    $hidden_picker = "$color_field .daedalus-stylish-native-picker";
    $this->typeIntoPanel($hidden_picker, '#22aa66');
    $this->assertJsCondition(
      "(() => { const input = document.querySelector(" . json_encode($color_input) . ');'
      . " const face = input.closest('.daedalus-stylish-color-field')?.querySelector('.daedalus-stylish-color-field__face');"
      . " return input.value === '#22aa66' && !!face && getComputedStyle(face).backgroundColor === 'rgb(34, 170, 102)'; })()",
      20000,
      'The hidden picker\'s input event drove the field\'s draft: the text echoed the hex and the face repainted.',
    );
    $this->changePanel($hidden_picker);
    $this->assertJsCondition(
      $map . '[' . json_encode($block_b_key) . "]?.default?.color === '#22aa66'",
      20000,
      'The hidden picker\'s change event committed the literal through the row\'s settle.',
    );

    // The text path: a typed literal that matches no palette stop
    // settles as itself.
    $this->typeIntoPanel($color_input, '#ff8800');
    $this->assertJsCondition(
      'document.querySelector(' . json_encode($color_input) . ").value === '#ff8800'",
      20000,
      'The typed color echoed into the controlled input.',
    );
    $this->blurPanel($color_input);
    $this->assertJsCondition(
      $map . '[' . json_encode($block_b_key) . "]?.default?.color === '#ff8800'",
      20000,
      'The Advanced color field committed the LITERAL to the map (no palette stop matched, so no capture).',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas_b\")).color === 'rgb(255, 136, 0)'",
      20000,
      'The canvas block wears the literal color without reload.',
    );

    // The Basic half: the text_color well reads the same map entry and
    // wears the painted LITERAL face (its own paint under the custom
    // marker), never the "?" custom face.
    $this->switchMode('Basic');
    $widget = self::TEXT_COLOR_WIDGET;
    $well = "$widget .daedalus-stylish-inspector__widget-row .daedalus-stylish-inspector__widget-value > .daedalus-stylish-swatch-well";
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$well.daedalus-stylish-swatch-well--literal"),
      'The text_color well wears the literal face for the committed custom color.',
    );
    $this->assertJsCondition(
      "(() => { const well = document.querySelector(" . json_encode($well) . ');'
      . " const face = well?.querySelector('.daedalus-stylish-swatch-well__face');"
      . " return !!face && getComputedStyle(face).backgroundColor === 'rgb(255, 136, 0)'"
      . " && !well.classList.contains('daedalus-stylish-swatch-well--custom'); })()",
      20000,
      'The literal face is painted with the committed color itself, not the checker \"?\".',
    );

    // The well IS the control: its popover carries the palette, then the
    // self-labelled lane row, and the color lane closes the popover and
    // opens the native chooser in the same click (the well row is the
    // hidden picker's positioned mount). The chooser cannot be driven
    // headlessly, so its two events are dispatched on the hidden picker:
    // input stages the widget draft (the well repaints its literal face
    // live, nothing committed), change settles through the widget's pick
    // path (the explicit replacement over the trio) and the map, the well
    // and the canvas follow.
    // The popover floats: its content portals into the shared surface
    // (.daedalus-ui-popover, a body child, never a descendant of the inspector),
    // and the rows below stay put (the widget's own height does not
    // grow). Seam: the Preact-to-plain-DOM portal across separately
    // built bundles; representative of the class (the choice well, the
    // color field and the variant picker ride the same hook).
    $widget_height_js = 'document.querySelector(' . json_encode($widget) . ').getBoundingClientRect().height';
    $widget_height = (float) $session->evaluateScript($widget_height_js);
    $this->clickPanel($well);
    $this->assertNotNull(
      $assert_session->waitForElement('css', '.daedalus-ui-popover .daedalus-stylish-swatch-popover .daedalus-stylish-palette-lane--color'),
      'The well opened its popover with the color lane after the palette.',
    );
    $this->assertJsCondition(
      "(() => { const popover = document.querySelector('.daedalus-ui-popover');"
      . " return !!popover && popover.parentElement === document.body"
      . " && !document.getElementById('daedalus_stylish_inspector').contains(popover)"
      . " && !!popover.querySelector('.daedalus-stylish-swatch-popover')"
      . " && Math.abs($widget_height_js - $widget_height) < 1; })()",
      20000,
      'The open popover is a body child on the floating surface, not a descendant of the inspector, and the widget did not grow: the rows below stay put.',
    );
    $this->clickPanel('.daedalus-ui-popover .daedalus-stylish-palette-lane--color');
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-swatch-popover')",
      20000,
      'The color lane closed the well popover.',
    );
    $widget_picker = "$widget .daedalus-stylish-inspector__widget-row .daedalus-stylish-native-picker";
    $this->typeIntoPanel($widget_picker, '#0a55f0');
    $this->assertJsCondition(
      "(() => { const well = document.querySelector(" . json_encode($well) . ');'
      . " const face = well?.querySelector('.daedalus-stylish-swatch-well__face');"
      . " return !!well && well.classList.contains('daedalus-stylish-swatch-well--literal')"
      . " && !!face && getComputedStyle(face).backgroundColor === 'rgb(10, 85, 240)'"
      . ' && ' . $map . '[' . json_encode($block_b_key) . "]?.default?.color === '#ff8800'; })()",
      20000,
      'The hidden picker\'s input event repainted the well live from the staged draft, before any commit.',
    );
    $this->changePanel($widget_picker);
    $this->assertJsCondition(
      $map . '[' . json_encode($block_b_key) . "]?.default?.color === '#0a55f0'",
      20000,
      'The hidden picker\'s change event committed the new literal through the widget\'s pick path (lane, picker, widget, op, mirror).',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas_b\")).color === 'rgb(10, 85, 240)'",
      20000,
      'The canvas block wears the picker-committed color without reload.',
    );

    // Reopening the well offers the mint row: a literal is worn on an
    // open space under the editor's grant (mintOffered, the color
    // field's gate), so save-as-swatch lives inside the popover.
    $this->clickPanel($well);
    $this->assertNotNull(
      $assert_session->waitForElement('css', '.daedalus-ui-popover .daedalus-stylish-swatch-popover .daedalus-stylish-mint-row .daedalus-stylish-mint-row__save'),
      'Reopening the well offered the save-as-swatch mint row over the worn literal.',
    );

    // ----- shift+v opens the variant picker for the selection. -----
    // Seam: daedalus_ui's hotkey dispatcher (the keydown on document,
    // the selection context) -> daedalus_stylish's opener seam -> the mounted wear
    // bar's add row. Escape first closes the well's popover (the floating
    // surface consumes the press at document capture, so it is dispatched
    // on the focused chip as a keyboard would, never on document), so one
    // popover is open at a time.
    $this->pressKeyOnFocused('Escape');
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-swatch-popover')",
      20000,
      'Escape closed the well\'s popover.',
    );
    $add_row = '#daedalus_stylish_inspector .daedalus-stylish-wear-bar__add';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $add_row),
      'The wear bar renders its add row for the selected block.',
    );
    $this->pressHotkey('v', ['shiftKey' => TRUE]);
    $this->assertJsCondition(
      "(() => { const input = document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker__input');"
      . " return !!input && document.activeElement === input"
      . " && document.querySelector(" . json_encode($add_row) . ").getAttribute('aria-expanded') === 'true'; })()",
      20000,
      'shift+v opened the variant picker for the selected block: the search field is focused and the add row reads expanded.',
    );
    $this->pressKeyOnFocused('Escape');
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker')"
      . " && document.activeElement === document.querySelector(" . json_encode($add_row) . ')',
      20000,
      'Escape closed the picker and returned focus to the add row.',
    );
    // With the panel closed, shift+v opens the panel first, then the
    // picker. Seam: the hotkey action -> SidebarManager.openSidebar (the
    // panel toggle's path) -> the opener seam once the panel is visible.
    $panel_hidden_js = "document.getElementById('daedalus_stylish_inspector').classList.contains('daedalus-ui-hidden')";
    // Closed from inside, through the header's close control (the preact
    // twin riding daedalus_ui's [data-sidebar-close] behavior).
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-ui-panel__close');
    $this->assertJsCondition($panel_hidden_js, 20000, 'The inspector panel closed from its header close control.');
    $this->pressHotkey('v', ['shiftKey' => TRUE]);
    $this->assertJsCondition(
      "(() => { const input = document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker__input');"
      . " return !$panel_hidden_js && !!input && document.activeElement === input; })()",
      20000,
      'shift+v with the panel closed opened the inspector panel and then the picker, with the search field focused.',
    );
    $this->pressKeyOnFocused('Escape');
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker')"
      . " && document.activeElement === document.querySelector(" . json_encode($add_row) . ')',
      20000,
      'Escape closed the picker opened through the closed panel and returned focus to the add row.',
    );

    // ----- Copy styles, paste styles. -----
    // Seam: daedalus_ui's hotkey dispatcher (mod+alt+c and mod+alt+v under
    // the selection context) -> the styles clipboard (copy-styles.js:
    // the target's own document out of the mirror's map) -> one
    // commitBatch of update_style ops under one label -> the mirror and
    // the canvas -> one undo entry. Block B wears the picker's literal;
    // block A, styled earlier, gains it additively. 'mod' resolves by
    // platform (binding.js): the meta key on a Mac, ctrl elsewhere, so
    // the press carries whichever this browser reads, and an alt combo
    // matches on event.code (macOS composes alt+c into a character), so
    // the press carries the physical key too.
    $mod = $session->evaluateScript('/mac/i.test(navigator.platform)') ? 'metaKey' : 'ctrlKey';
    $this->pressHotkey('c', [$mod => TRUE, 'altKey' => TRUE, 'code' => 'KeyC']);
    $this->assertJsCondition(
      "Drupal.Daedalus.messenger.history().some((entry) => entry.type === 'status' && /^Copied \\d+ styles?\\.$/.test(entry.message))",
      20000,
      'mod+alt+c copied block B\'s styles and said so through the messenger.',
    );
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_a_key');");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === " . json_encode($block_a_key),
      20000,
      'Block A is the selection for the paste.',
    );
    $this->pressHotkey('v', [$mod => TRUE, 'altKey' => TRUE, 'code' => 'KeyV']);
    $this->assertJsCondition(
      $map . '[' . json_encode($block_a_key) . "]?.default?.color === '#0a55f0'",
      20000,
      'mod+alt+v pasted the copied color onto block A: the batch landed in the mirror.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas_a\")).color === 'rgb(10, 85, 240)'",
      20000,
      'The canvas block A wears the pasted color without reload.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.messenger.history().some((entry) => entry.type === 'status' && /^Pasted \\d+ styles?\\.$/.test(entry.message))",
      20000,
      'The paste toasted its count once the batch settled.',
    );
    // One paste is one history entry: undo takes the whole batch back.
    $this->pressHotkey('z', [$mod => TRUE]);
    $this->assertJsCondition(
      $map . '[' . json_encode($block_a_key) . "]?.default?.color !== '#0a55f0'",
      20000,
      'Undo reverted the paste as one entry: block A no longer carries the pasted color.',
    );

    // ----- A claimed axis yields its row to the section surfacing it. -----
    // Seam: the inspector's mode -> the claim aggregation from the mounted
    // sections (basic mounts the Layout section, whose Stack | Columns
    // segment IS the container_layout wearing's surface) -> the wear
    // bar's worn rows. The wear is real: the segment's Columns flip
    // applies the shipped row variant through apply_variant.
    $container_key = "container:$section_b_uuid";
    $session->executeScript("Drupal.Daedalus.Selection.select('$container_key');");
    $columns_segment = '//div[contains(@class, "daedalus-stylish-inspector__layout")]//button[contains(@class, "daedalus-stylish-inspector__segment")][normalize-space() = "Columns"]';
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', $columns_segment),
      'Basic mode mounts the container\'s Layout section with its Columns segment.',
    );
    // The hosted section leads: a container's panel opens on Layout,
    // the roster sections (Surface, Motion) beneath it, while a block
    // (asserted above) keeps the plain roster order.
    $this->assertJsCondition(
      "(() => { const titles = [...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-inspector__control-section-title')]"
      . " .map((title) => title.textContent.trim()); return titles[0] === 'Layout' && titles.length > 1; })()",
      20000,
      'The container\'s Layout section renders first, ahead of the roster sections.',
    );
    $this->clickPanelXpath($columns_segment);
    $this->assertJsCondition(
      'JSON.stringify(' . $map . '[' . json_encode($container_key) . "]?.variants ?? []).includes('columns')",
      20000,
      'The Columns flip committed apply_variant: the container wears the shipped columns variant on container_layout.',
    );
    $layout_row = '//div[contains(@class, "daedalus-stylish-wear-bar__row")][.//span[contains(@class, "daedalus-stylish-wear-bar__label")][normalize-space() = "Layout"]]';
    $layout_row_js = 'document.evaluate(' . json_encode($layout_row) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue';
    $this->assertJsCondition(
      '(() => { const pressed = document.evaluate(' . json_encode($columns_segment) . ", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue?.getAttribute('aria-pressed') === 'true';"
      . " return pressed && !!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-wear-bar') && !$layout_row_js; })()",
      20000,
      'Basic mode: the Columns segment reads pressed and the wear bar renders NO Layout row (the mounted Layout section claims the axis).',
    );
    $this->switchMode('Advanced');
    $this->assertJsCondition(
      "($layout_row_js)?.querySelector('.daedalus-stylish-wear-bar__variant-label')?.textContent.trim() === 'Columns'",
      20000,
      'Advanced mode mounts no Layout section, so the worn Layout row shows, its swap trigger reading Columns.',
    );
    $this->switchMode('Basic');
    $this->assertJsCondition(
      "!$layout_row_js && !!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__layout')",
      20000,
      'Back in Basic the Layout section is mounted again and the Layout row yields to it.',
    );

    // ----- Every settled write released its preview. -----
    // Seam: the settle path's release, bound at dispatch to what was
    // staged (preview.settling): the Size capture above previewed the
    // stop's literal and committed the token's reference, and the
    // literal must not outlive its op. An empty preview sheet here means
    // the duplicate's computed claims below read the entity tag alone.
    $this->assertSame(
      '',
      (string) $session->evaluateScript("document.querySelector('style[data-daedalus-stylish-preview]')?.textContent ?? ''"),
      'The preview sheet is empty after the last write settled: no staged value outlived its op (the Size capture\'s literal included).',
    );

    // ----- Duplicate lands a styled clone without reload. -----
    // Seam: the duplicate response's targets (daedalus_stylish's subscriber
    // declares the root daedalus_stylish_styles field beside the op's ADDED clone)
    // -> the whole-entity swap -> the entity-level style tag carrying the
    // clone's @scope rule entering the browser cascade. Block B is the
    // source: it wears the captured font-size and the picker-committed
    // color, both read back COMPUTED from the canvas so the clone claim
    // is about worn styles, never the map alone.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_b_key');");
    $duplicate_verb = ".daedalus-ui-frame__chip--primary[data-selection-key='$block_b_key'] .daedalus-ui-frame__verb[aria-label='Duplicate']";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $duplicate_verb),
      'Block B\'s selection chip offers the Duplicate verb on its face.',
    );
    $source_style = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const block = document.querySelector("$canvas_b");
        const probe = document.createElement('span');
        probe.style.fontSize = 'var(--text-caption)';
        block.append(probe);
        const tokenSize = getComputedStyle(probe).fontSize;
        probe.remove();
        const style = getComputedStyle(block);
        return JSON.stringify({ tokenSize, fontSize: style.fontSize, color: style.color });
      })()
      JS), TRUE);
    $this->assertIsArray($source_style, 'The source block\'s computed styles were read before the duplicate.');
    $this->assertSame(
      $source_style['tokenSize'],
      $source_style['fontSize'],
      'The source block wears the captured caption token\'s resolved font-size going into the duplicate.',
    );
    $this->assertSame(
      'rgb(10, 85, 240)',
      $source_style['color'],
      'The source block wears the picker-committed color going into the duplicate.',
    );
    // A reload would drop this window property: every clone claim below
    // holds only while it survives.
    $session->executeScript('window.__duplicateNoReload = true;');
    $this->clickPanel($duplicate_verb);
    // Settle: the verb hands the clone's uuid to the mutation as its
    // selection, so the selection moving to a block other than B whose
    // element is on the canvas IS the response landed (markup swapped,
    // mirror replaced, selection committed, in that order).
    $selected_js = 'Drupal.Daedalus.Selection.getSelected()';
    $this->assertJsCondition(
      "(() => { const key = $selected_js;"
      . ' return !!key && key !== ' . json_encode($block_b_key) . " && key.startsWith('block:')"
      . ' && !!Drupal.Daedalus.Selection.getElement(key); })()',
      20000,
      'The duplicate settled: the selection followed the clone onto the canvas.',
    );
    $clone_key = (string) $session->evaluateScript($selected_js);
    $this->assertTrue(
      (bool) $session->evaluateScript('window.__duplicateNoReload === true'),
      'The page did not reload across the duplicate.',
    );
    $canvas_clone = "[data-selectable='$clone_key']";
    $this->assertJsCondition(
      "(() => { const clone = document.querySelector(" . json_encode($canvas_clone) . ');'
      . " const source = document.querySelector(\"$canvas_b\");"
      . " return !!clone && !!source && clone !== source"
      . " && !!document.querySelector(\"[data-selectable='container:$section_b_uuid']\")?.contains(clone); })()",
      20000,
      'The clone rendered beside its source inside the same section.',
    );
    $this->assertJsCondition(
      "(() => { const entry = $map" . '[' . json_encode($clone_key) . "]?.default ?? {};"
      . " return entry['font-size'] === 'var(--text-caption)' && entry.color === '#0a55f0'; })()",
      20000,
      'The mirror holds the clone\'s style map entry, a copy of the source\'s (the subscriber\'s write rode the response).',
    );
    // Delivery: the page entity's own style tag (the one INSIDE the entity
    // root, so it came in with the whole-entity swap; other entities on
    // the page carry tags of their own) holds the clone's @scope block
    // with both properties. Read on the tag rather than only computed, so
    // the preview sheet (which prints the merged map whenever anything is
    // staged) cannot stand in for the authoritative tag.
    $entity_tag_js = "document.querySelector(\"[data-selectable='entity:{$node->uuid()}'] style[data-daedalus-stylish-entity]\")";
    $this->assertJsCondition(
      "(() => { const text = $entity_tag_js?.textContent ?? '';"
      . ' const start = text.indexOf(' . json_encode('@scope ([data-selectable="' . $clone_key . '"])') . ');'
      . ' if (start < 0) { return false; }'
      . " const next = text.indexOf('@scope', start + 6);"
      . ' const block = text.slice(start, next < 0 ? undefined : next);'
      . ' return /font-size:\s*var\(--text-caption\)/.test(block) && /color:\s*#0a55f0/.test(block); })()',
      20000,
      'The entity root\'s style tag holds the clone\'s @scope block with the source\'s font-size and color: the whole-entity swap delivered the clone\'s CSS.',
    );
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(" . json_encode($canvas_clone) . '));'
      . ' return style.fontSize === ' . json_encode($source_style['fontSize'])
      . ' && style.color === ' . json_encode($source_style['color']) . '; })()',
      20000,
      'The clone wears the source\'s computed font-size and color without reload.',
    );

    // ----- The creating posture's header fits the panel. -----
    // Seam: the save doorway (StyleActions, offered because the clone's
    // captureEntries are non-empty) -> startSaveSession -> the session
    // frame's markup meeting the panel's width in the browser's flex
    // layout. The name input and the axis select left the non-wrapping
    // top row for the draft row beneath the posture label (with the
    // description input on its own line under them), and the body's
    // session inset out-cascades the panel recipe's padding by
    // specificity. Node tests pin the frame's model; that the header no
    // longer widens the body past the panel (the sideways scroll the
    // topic opened on) is a box-model fact only a browser can see.
    $this->clickPanel("#daedalus_stylish_inspector button[aria-label='Save as variant']");
    $draft_row = '#daedalus_stylish_inspector .daedalus-stylish-session__draft';
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$draft_row .daedalus-stylish-session__name"),
      'The Save doorway opened the creating posture with its name input in the draft row.',
    );
    $this->assertJsCondition(
      '(() => { const row = document.querySelector(' . json_encode($draft_row) . ');'
      . " const top = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-session__top');"
      . ' return !!row && !!top && row.previousElementSibling === top'
      . " && !top.querySelector('.daedalus-stylish-session__name, .daedalus-stylish-session__axis, .daedalus-stylish-session__description')"
      . " && !!row.querySelector('input.daedalus-stylish-session__name')"
      . " && !!row.querySelector('select.daedalus-stylish-session__axis')"
      . " && !!row.querySelector('input.daedalus-stylish-session__description'); })()",
      20000,
      'The name input, the axis select and the description input sit in the draft row right after the top row, none of them in the top row.',
    );
    $session_body = '#daedalus_stylish_inspector .daedalus-stylish-inspector--session .daedalus-stylish-inspector__body';
    $this->assertJsCondition(
      '(() => { const body = document.querySelector(' . json_encode($session_body) . ');'
      . ' if (!body) { return false; }'
      . ' const style = getComputedStyle(body);'
      . " const inset = (0.5 * parseFloat(getComputedStyle(document.documentElement).fontSize)) + 'px';"
      . ' return [style.paddingTop, style.paddingRight, style.paddingBottom, style.paddingLeft].every((side) => side === inset); })()',
      20000,
      'The session body wears its 0.5rem inset on all four sides (the session rule out-cascades the panel recipe\'s padding).',
    );
    $body_overflow = json_decode((string) $session->evaluateScript(
      '(() => { const body = document.querySelector(' . json_encode($session_body) . ');'
      . ' return JSON.stringify({ scrollWidth: body.scrollWidth, clientWidth: body.clientWidth }); })()'
    ), TRUE);
    $this->assertIsArray($body_overflow, 'The session body\'s widths were read.');
    $this->assertLessThanOrEqual(
      $body_overflow['clientWidth'],
      $body_overflow['scrollWidth'],
      sprintf(
        'The creating posture\'s header does not widen the panel body past its width (scrollWidth %d <= clientWidth %d): no sideways scroll.',
        $body_overflow['scrollWidth'],
        $body_overflow['clientWidth'],
      ),
    );
    // Leave the panel as the tail found it: the draft is discarded.
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-push-bar .daedalus-stylish-inspector__variant-button');
    $this->assertJsCondition(
      "!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-push-bar')"
      . ' && !document.querySelector(' . json_encode($draft_row) . ')',
      20000,
      'Discard closed the creating posture: the push bar and the draft row are gone.',
    );

    // ----- A color pick completes an unset border in one op. -----
    // Seam: three sub-controls (color well, width field, style well)
    // feeding ONE map entry through the stroke completion rule
    // (stroke-model.js strokeWrite), one dispatch, the mirror, the
    // canvas. The clone is the target: it wears no border, and the
    // Surface section's Border row is the composite over the three
    // border shorthands, no chips inside it (the settled decision: the
    // style vocabulary rides a well).
    $this->recordOps();
    $corners_border = '#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--corners-border';
    $border_row_js = "[...document.querySelectorAll('$corners_border .daedalus-stylish-stroke-row')].find((row) => row.querySelector('input[aria-label=\"Border\"]'))";
    $border_row_xpath = '//div[contains(@class, "daedalus-stylish-inspector__widget--corners-border")]'
      . '//div[contains(@class, "daedalus-stylish-stroke-row")][.//input[@aria-label = "Border"]]';
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', $border_row_xpath),
      'The clone\'s Surface section renders the Border stroke row.',
    );
    $this->assertJsCondition(
      "(() => { const row = $border_row_js; if (!row) { return false; }"
      . " const color = row.querySelector('.daedalus-stylish-stroke-row__part--color .daedalus-stylish-swatch-well');"
      . " const style = row.querySelector('.daedalus-stylish-stroke-row__part--style .daedalus-stylish-swatch-well');"
      . " const entry = $map" . '[' . json_encode($clone_key) . "]?.default ?? {};"
      . " return !!color && color.classList.contains('daedalus-stylish-swatch-well--unset')"
      . " && !!style && style.classList.contains('daedalus-stylish-swatch-well--unset')"
      . " && row.querySelector('input[aria-label=\"Border\"]').value === ''"
      . " && !row.querySelector('.daedalus-stylish-inspector__segment')"
      . " && entry['border-width'] === undefined && entry['border-style'] === undefined && entry['border-color'] === undefined; })()",
      20000,
      'The Border row is color well + width field + style well, every part unset and no chips inside the composite, over a clone with no border in the map.',
    );
    // Pre-commit facts: the accent token resolved inside the clone (the
    // color the stroke will wear) and the clone's current borderless
    // state, so the restyle below is observable.
    $border_facts = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const block = document.querySelector("$canvas_clone");
        const probe = document.createElement('span');
        probe.style.color = 'var(--color-accent)';
        block.append(probe);
        const accent = getComputedStyle(probe).color;
        probe.remove();
        const style = getComputedStyle(block);
        return JSON.stringify({ accent, width: style.borderTopWidth, lineStyle: style.borderTopStyle });
      })()
      JS), TRUE);
    $this->assertIsArray($border_facts, 'The clone\'s border state and the accent color were measured before the pick.');
    $this->assertSame('0px', $border_facts['width'], 'The clone wears no border going into the pick.');
    $this->assertMatchesRegularExpression('/^rgb\(/', $border_facts['accent'], 'The accent token resolves to a color inside the clone.');
    // A REAL click on the color well opens its popover (the palette grid
    // over the skin's color tokens), and a REAL pick of the accent
    // swatch commits the COMPLETED stroke.
    $this->clickPanelXpath($border_row_xpath . '//span[contains(@class, "daedalus-stylish-stroke-row__part--color")]/button[contains(@class, "daedalus-stylish-swatch-well")]');
    $accent_stop_xpath = '//div[contains(@class, "daedalus-ui-popover")]//div[contains(@class, "daedalus-stylish-swatch-popover")]'
      . '//button[contains(@class, "daedalus-stylish-palette-grid__stop")][translate(@aria-label, "ACCENT", "accent") = "accent"]';
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', $accent_stop_xpath),
      'The Border color well opened the palette popover offering the accent swatch.',
    );
    $this->clickPanelXpath($accent_stop_xpath);
    $this->assertJsCondition(
      "(() => { const entry = $map" . '[' . json_encode($clone_key) . "]?.default ?? {};"
      . " return entry['border-width'] === '1px' && entry['border-style'] === 'solid' && entry['border-color'] === 'var(--color-accent)'; })()",
      20000,
      'The accent pick completed the stroke in the map: the first width stop, solid, and the accent reference.',
    );
    $this->assertOneOp(
      $clone_key,
      ['border-width' => '1px', 'border-style' => 'solid', 'border-color' => 'var(--color-accent)'],
      'The color pick on the unset border was ONE update_style op carrying width, style and color together (the completion rule through one dispatch).',
    );
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(" . json_encode($canvas_clone) . '));'
      . " return ['Top', 'Right', 'Bottom', 'Left'].every((side) => style['border' + side + 'Width'] === '1px'"
      . " && style['border' + side + 'Style'] === 'solid' && style['border' + side + 'Color'] === " . json_encode($border_facts['accent']) . '); })()',
      20000,
      'The canvas clone wears the accent-coloured solid 1px border on every side without reload.',
    );
    $this->assertJsCondition(
      "(() => { const row = $border_row_js; if (!row) { return false; }"
      . " const input = row.querySelector('input[aria-label=\"Border\"]');"
      . " return row.querySelector('.daedalus-stylish-stroke-row__part--color .daedalus-stylish-swatch-well').classList.contains('daedalus-stylish-swatch-well--stop')"
      . " && row.querySelector('.daedalus-stylish-stroke-row__part--style .daedalus-stylish-swatch-well').classList.contains('daedalus-stylish-swatch-well--stop')"
      . " && input.value === '1' && input.closest('.daedalus-stylish-dimension').querySelector('.daedalus-stylish-dimension__unit-label').textContent === 'px'"
      . " && !document.querySelector('.daedalus-ui-popover .daedalus-stylish-swatch-popover'); })()",
      20000,
      'The popover closed and the row wears the completed stroke: the color and style wells light their stops and the width field reads 1 beside its static px label (the stroke widths name px).',
    );

    // ----- The ring's right cell commits twelve longhands in one op. -----
    // Seam: the diagram's gesture path (applyGesture, settleGesture,
    // patchFor) carrying the merged three-family patch
    // (strokeSidesPatch, three families wide) through the op path, and
    // the highlight claim stack painting the touched side on the canvas.
    // The step rides the keyboard: a synthetic pointer cannot take
    // pointer capture, and ArrowUp on the focused cell runs the same
    // gesture and settle path a scrub does (preview per press, one
    // commit on release).
    $this->recordOps();
    $this->clickPanel("$corners_border button[aria-label='Edit border per side']");
    $right_cell_xpath = '//div[contains(@class, "daedalus-stylish-boxmodel__ring--border")]'
      . '//button[contains(@class, "daedalus-stylish-boxmodel__cell")][starts-with(@aria-label, "Border right:")]';
    $right_cell_js = 'document.evaluate(' . json_encode($right_cell_xpath) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue';
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', $right_cell_xpath),
      'The pencil opened the border ring beneath the Border row, with a right cell.',
    );
    $this->assertJsCondition(
      "(() => { const cell = $right_cell_js;"
      . " return !!cell && cell.getAttribute('aria-label') === 'Border right: 1px (set here)'"
      . " && cell.classList.contains('daedalus-stylish-boxmodel__cell--authored') && cell.classList.contains('daedalus-stylish-boxmodel__cell--scrubbable')"
      . " && !document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-boxmodel--blocked'); })()",
      20000,
      'The right cell reads the shorthand\'s 1px as set here, scrubbable, and the ring is not blocked (the token-coloured shorthand splits per side).',
    );
    // A REAL click selects the side: the side editor mounts as the
    // stroke row over the right longhands, and the cell claims the
    // highlight for the side.
    $this->clickPanelXpath($right_cell_xpath);
    $this->assertJsCondition(
      "(() => { const cell = $right_cell_js;"
      . " return !!cell && cell.getAttribute('aria-pressed') === 'true'"
      . " && !!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-boxmodel__editor input[aria-label=\"Border right\"]'); })()",
      20000,
      'The click selected the right cell and mounted the side editor as the stroke row over the right side.',
    );
    // The step: focus explicitly (the cell's pointerdown suppresses the
    // click's focus), ArrowUp previews, its release settles.
    $session->executeScript(
      "(() => { const cell = $right_cell_js; cell.focus();"
      . " cell.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true, cancelable: true })); })();"
    );
    $this->assertJsCondition(
      "($right_cell_js)?.getAttribute('aria-label') === 'Border right: 2px (set here)'",
      20000,
      'ArrowUp previewed the right cell to the next stop, 2px, before any commit.',
    );
    $this->assertSame(
      0,
      (int) $session->evaluateScript(self::OP_LOG . '.length'),
      'Nothing was dispatched while the key is held: the diagram commits on release.',
    );
    $session->executeScript(
      "($right_cell_js).dispatchEvent(new KeyboardEvent('keyup', { key: 'ArrowUp', bubbles: true, cancelable: true }));"
    );
    $ring_patch = [
      'border-width' => NULL,
      'border-style' => NULL,
      'border-color' => NULL,
      'border-top-width' => '1px',
      'border-right-width' => '2px',
      'border-bottom-width' => '1px',
      'border-left-width' => '1px',
      'border-top-style' => 'solid',
      'border-right-style' => 'solid',
      'border-bottom-style' => 'solid',
      'border-left-style' => 'solid',
      'border-top-color' => 'var(--color-accent)',
      'border-right-color' => 'var(--color-accent)',
      'border-bottom-color' => 'var(--color-accent)',
      'border-left-color' => 'var(--color-accent)',
    ];
    $this->assertOneOp(
      $clone_key,
      $ring_patch,
      'The release was ONE update_style op unsetting the three shorthands and writing all twelve side longhands (the right width stepped, the rest carried).',
    );
    $this->assertJsCondition(
      "(() => { const entry = $map" . '[' . json_encode($clone_key) . "]?.default ?? {};"
      . " const expected = " . json_encode(array_filter($ring_patch, static fn ($value) => $value !== NULL)) . ';'
      . " return entry['border-width'] === undefined && entry['border-style'] === undefined && entry['border-color'] === undefined"
      . " && Object.entries(expected).every(([name, value]) => entry[name] === value); })()",
      20000,
      'The mirror holds the twelve longhands and no shorthand after the expansion.',
    );
    $this->assertJsCondition(
      "(() => { const cell = $right_cell_js;"
      . " return !!cell && cell.getAttribute('aria-label') === 'Border right: 2px (set here)'"
      . " && cell.classList.contains('daedalus-stylish-boxmodel__cell--authored'); })()",
      20000,
      'The right cell wears the authored 2px after the commit.',
    );
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(" . json_encode($canvas_clone) . '));'
      . " return style.borderRightWidth === '2px' && style.borderTopWidth === '1px' && style.borderBottomWidth === '1px' && style.borderLeftWidth === '1px'"
      . " && style.borderRightStyle === 'solid' && style.borderRightColor === " . json_encode($border_facts['accent']) . '; })()',
      20000,
      'The canvas clone wears the 2px right border beside 1px on the other sides, solid and accent throughout.',
    );
    // The claim's trace on the canvas: the selected cell claims the right
    // side's three longhands, the border painter answers for any of
    // them with the RIGHT band alone, and the renderer paints it 2px
    // wide at the clone's right edge (document coordinates), no other
    // band visible.
    $this->assertJsCondition(<<<JS
      (() => {
        const block = document.querySelector("$canvas_clone");
        const bands = [...document.querySelectorAll('#daedalus-root .daedalus-stylish-bands .daedalus-stylish-band')].filter((band) => !band.hidden);
        if (bands.length !== 1 || !bands[0].classList.contains('daedalus-stylish-band--border')) { return false; }
        const band = bands[0].getBoundingClientRect();
        const rect = block.getBoundingClientRect();
        return Math.abs(band.width - 2) < 0.6
          && Math.abs(band.left - (rect.right - 2)) < 1.5
          && band.top > rect.top && band.bottom < rect.bottom + 0.6;
      })()
      JS,
      20000,
      'Exactly one border band paints on the canvas: 2px wide at the clone\'s right edge, the selected cell\'s claim over the right side\'s three longhands.',
    );

    // ----- An outline write reveals the gated offset row. -----
    // Seam: the gated row (rendered only while the outline stroke or the
    // offset is set) crossing the settle path: a typed width settles
    // through the completion rule (solid lands beside it), the mirror
    // replaces, the row re-renders with the offset field mounted.
    $this->recordOps();
    $outline_input = "$corners_border input[aria-label='Outline']";
    $offset_input = "$corners_border input[aria-label='Outline offset']";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $outline_input),
      'The Outline stroke row renders its width field.',
    );
    $this->assertJsCondition(
      '!document.querySelector(' . json_encode($offset_input) . ')'
      . " && (() => { const entry = $map" . '[' . json_encode($clone_key) . "]?.default ?? {};"
      . " return entry['outline-width'] === undefined && entry['outline-style'] === undefined && entry['outline-offset'] === undefined; })()",
      20000,
      'No Outline offset row renders while nothing outlines the clone.',
    );
    $this->typeIntoPanel($outline_input, '2px');
    $this->blurPanel($outline_input);
    $this->assertJsCondition(
      "(() => { const entry = $map" . '[' . json_encode($clone_key) . "]?.default ?? {};"
      . " return entry['outline-width'] === '2px' && entry['outline-style'] === 'solid' && entry['outline-color'] === undefined && entry['outline-offset'] === undefined; })()",
      20000,
      'The typed outline width settled as outline-width plus outline-style: solid, color and offset untouched.',
    );
    $this->assertOneOp(
      $clone_key,
      ['outline-width' => '2px', 'outline-style' => 'solid'],
      'The outline settle was ONE update_style op carrying the width and the completing solid style.',
    );
    $this->assertNotNull(
      $assert_session->waitForElement('css', $offset_input),
      'The Outline offset row mounted once the outline stroke was set.',
    );
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(" . json_encode($canvas_clone) . '));'
      . " return style.outlineStyle === 'solid' && style.outlineWidth === '2px'; })()",
      20000,
      'The canvas clone wears the solid 2px outline without reload.',
    );

    // ----- The page spaces its bands through the layout root. -----
    // Seam one: an element-bound kind control gating on the bound part's
    // live child count. daedalus_edit binds the entity kind's `gap` control
    // to `.daedalus-layout-layout`, the layout root daedalus_layout renders around the
    // root sections (the view render's wrapper and the edit canvas's
    // #layout-builder are the same declared part), and the gap widget's
    // gate reads that part's OWN child boxes (self.partChildCount), never
    // the entity root's, whose one child box is the layout root itself.
    // The page holds two bands: section A from the strict phase, staged
    // in tempstore across the enforcement reload, and section B. Pinned
    // first, so the row's presence below is the gate opening on a real
    // count and never an unknown context degrading to an offer.
    $entity_key = "entity:{$node->uuid()}";
    $layout_root = "[data-selectable='$entity_key'] .daedalus-layout-layout";
    $section_a = "[data-selectable='container:$section_a_uuid']";
    $section_b = "[data-selectable='container:$section_b_uuid']";
    $session->executeScript("Drupal.Daedalus.Selection.select('$entity_key');");
    $this->assertJsCondition(
      '(() => { const root = document.querySelector(' . json_encode($layout_root) . ');'
      . " if (!root || root.id !== 'layout-builder') { return false; }"
      . " const boxes = [...root.children].filter((child) => !child.matches('style, script, template, link'));"
      . ' return boxes.length === 2'
      . ' && boxes.some((box) => box.matches(' . json_encode($section_a) . '))'
      . ' && boxes.some((box) => box.matches(' . json_encode($section_b) . '))'
      . " && getComputedStyle(root).display === 'flex'"
      . " && getComputedStyle(root).flexDirection === 'column'; })()",
      20000,
      'The edit canvas renders the layout root (daedalus_layout\'s #layout-builder wearing .daedalus-layout-layout) inside the entity root, holding exactly the two bands as its child boxes and laid out as the declared flex column.',
    );
    $gap_widget = '#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--gap';
    $gap_input = "$gap_widget input[aria-label='Gap']";
    $gap_chevron = "$gap_input + .daedalus-stylish-stop-menu__chevron";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $gap_input),
      'The Page\'s Basic tab renders the Gap row: the element-bound kind control passed its presence gate and its bound part\'s two-band count.',
    );
    $this->assertJsCondition(
      '(() => { const widget = document.querySelector(' . json_encode($gap_widget) . ');'
      . " const title = widget?.closest('.daedalus-stylish-inspector__control-section')?.querySelector('.daedalus-stylish-inspector__control-section-title')?.textContent.trim();"
      . ' const input = document.querySelector(' . json_encode($gap_input) . ');'
      . ' const chevron = document.querySelector(' . json_encode($gap_chevron) . ');'
      . " return title === 'Layout' && !!input && input.value === ''"
      . " && !!chevron && chevron.getAttribute('aria-haspopup') === 'menu'; })()",
      20000,
      'The Gap row sits in the Page\'s Layout section as the detented field, empty, with the stop picker\'s chevron after it.',
    );

    // Seam two: a part-keyed write landing through the entity's @scope
    // tag onto the module-rendered element. Pre-commit facts: the layout
    // root's computed row-gap and the distance between the two bands, so
    // the restyle below is observable as canvas geometry, not only as a
    // computed string.
    $bands_apart_js = '(() => { const rects = [document.querySelector(' . json_encode($section_a) . '), document.querySelector(' . json_encode($section_b) . ')]'
      . ' .map((band) => band.getBoundingClientRect()).sort((a, b) => a.top - b.top);'
      . ' return rects[1].top - rects[0].bottom; })()';
    // The md token's resolved length is measured by a probe inside the
    // layout root (the one place var(--space-md) means anything), so the
    // claims below read the skin, never a hard-coded px.
    $layout_root_json = json_encode($layout_root);
    $gap_facts = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const root = document.querySelector($layout_root_json);
        const probe = document.createElement('span');
        probe.style.paddingTop = 'var(--space-md)';
        root.append(probe);
        const tokenGap = getComputedStyle(probe).paddingTop;
        probe.remove();
        return JSON.stringify({ tokenGap, rowGap: getComputedStyle(root).rowGap, apart: $bands_apart_js });
      })()
      JS), TRUE);
    $this->assertIsArray($gap_facts, 'The md token\'s length, the layout root\'s row-gap and the bands\' distance were measured before the pick.');
    $this->assertMatchesRegularExpression('/^\d+(\.\d+)?px$/', $gap_facts['tokenGap'], 'The md space token resolves to a length inside the layout root.');
    $this->assertNotSame($gap_facts['tokenGap'], $gap_facts['rowGap'], 'The layout root does not already wear the md gap, so the pick below is observable.');
    // A REAL click on the chevron opens the shared menu over the space
    // the suite binds gap to (constraint_defaults: the skin's space
    // scale, curated by olivero's guide fragment to sm/md/lg/xl, the
    // built-in px table displaced on the part as on every box), as
    // display values, var() on no label, nothing checked while the row
    // is unset.
    $this->recordOps();
    $this->clickPanel($gap_chevron);
    $this->assertJsCondition(
      "(() => { const menu = document.querySelector('.daedalus-ui-menu'); if (!menu) { return false; }"
      . " const labels = Array.from(menu.querySelectorAll('.daedalus-ui-menu__label')).map((label) => label.textContent.trim());"
      . " return ['0.5rem', '1rem', '2rem', '4rem'].every((step) => labels.some((label) => label.includes(step)))"
      . " && labels.every((label) => !label.includes('var('))"
      . " && !menu.querySelector('[aria-checked=\"true\"]'); })()",
      20000,
      'The chevron opens the shared menu listing the skin\'s space stops (0.5rem to 4rem) as display values, var() on no label, nothing checked on the empty row.',
    );
    // The md pick: the menu closes, the item previews the stop's display
    // value and settles it, and magnetic capture commits the token's
    // reference (the same path the Size row's pick took).
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.includes('1rem')).click();"
    );
    $part_key = "$entity_key::.daedalus-layout-layout";
    $this->assertJsCondition(
      $map . '[' . json_encode($part_key) . "]?.default?.gap === 'var(--space-md)'",
      20000,
      'The pick committed gap: var(--space-md) at the part-keyed address entity:{uuid}::.daedalus-layout-layout (menu pick, settle, magnetic capture, the op path, the mirror).',
    );
    $this->assertOneOp($part_key, ['gap' => 'var(--space-md)'], 'The gap pick was ONE update_style op on the part key carrying gap alone, as the reference.');
    // Delivery: the entity root's own style tag prints the part rule
    // inside the entity's @scope block (CssPrinter's part targets), the
    // one tag the whole-entity swap carries, so the layout root wears it
    // in edit mode exactly as the view render will.
    $this->assertJsCondition(
      "(() => { const text = $entity_tag_js?.textContent ?? '';"
      . ' const start = text.indexOf(' . json_encode('@scope ([data-selectable="' . $entity_key . '"])') . ');'
      . ' if (start < 0) { return false; }'
      . " const next = text.indexOf('@scope', start + 6);"
      . ' const block = text.slice(start, next < 0 ? undefined : next);'
      . ' return /\.daedalus-layout-layout[^{}]*\{[^}]*gap:\s*var\(--space-md\)/.test(block); })()',
      20000,
      'The entity root\'s style tag holds the part rule: the layout root\'s gap: var(--space-md) inside the entity\'s @scope block.',
    );
    $token_gap = (float) $gap_facts['tokenGap'];
    $this->assertJsCondition(
      '(() => { const root = document.querySelector(' . json_encode($layout_root) . ');'
      . ' return !!root && getComputedStyle(root).rowGap === ' . json_encode($gap_facts['tokenGap'])
      . " && Math.abs($bands_apart_js - (" . json_encode((float) $gap_facts['apart']) . ' + ' . json_encode($token_gap) . ')) < 1; })()',
      20000,
      'The layout root computes the md token\'s row-gap and the two bands sit that much further apart on the canvas without reload: the declared flex column laid the page gap out.',
    );
    $this->assertJsCondition(
      '(() => { const input = document.querySelector(' . json_encode($gap_input) . ');'
      . " return !document.querySelector('.daedalus-ui-menu') && !!input && input.value === '1'"
      . " && input.closest('.daedalus-stylish-dimension').querySelector('.daedalus-stylish-dimension__unit-label').textContent === 'rem'"
      . " && !input.closest('.daedalus-stylish-dimension').querySelector('.daedalus-stylish-dimension__unit-well')"
      . " && input.closest('.daedalus-stylish-dimension').classList.contains('daedalus-stylish-dimension--blessed'); })()",
      20000,
      'The menu closed on the pick and the Gap field wears the stop\'s display value (1 on the input, rem on the static unit label; the space scale names rem, so no select) with the blessed chrome.',
    );

    // ----- Background: one row over the link-first candidate list. -----
    // Seam: daedalus_edit_text_block restates the kind's `background`
    // binding over `['.text-block-link', '']` beside the shipped `button`
    // variant; controls.js builds one candidate per served address and
    // resolveCandidates answers, per owned property, the first candidate
    // whose stack delivers an AUTHORED value (here the anchor: the root's
    // reference layer resolved at the part's suffix), so the block's OWN
    // basic panel renders exactly one Background row lit with the
    // anchor's fill and no part caption (the retired second row). The
    // pipeline routes the row's writes to the resolved key (write where
    // you read), the Surface header's chip surfaces a second authored
    // candidate (`more`), and an absent first candidate (the unlinked
    // block's anchor) falls to the root. The served list, the rendered
    // row and the routed op only meet in a browser. Under open law only:
    // guide_example's strict law withholds the button (its literal hex
    // fails the bound color scale), which is why the leg rides the open
    // phase.
    $block_c_uuid = $uuid_service->generate();
    $section_c_uuid = $uuid_service->generate();
    $this->placeTextTypeBlock($section_c_uuid, $block_c_uuid, '__linkPlacementError', 'https://example.com');
    $block_c_key = "block:$block_c_uuid";
    $link_key = "$block_c_key::.text-block-link";
    $canvas_c = "[data-selectable='$block_c_key']";
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_c_key');");
    $this->assertJsCondition(
      "(() => { const active = document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]');"
      . " return !!active && active.textContent.trim() === 'Basic'; })()",
      20000,
      'Background: the inspector is in Basic mode on the linked text block.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Materials.has('variant', 'button')",
      20000,
      'Background: the shipped button variant (daedalus_edit_text_block\'s, imported on install) is on the open roster.',
    );
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{ op: 'apply_variant', key: '$block_c_key', variant: 'button' }],
        })).catch((error) => {
          window.__buttonWearError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      'window.__buttonWearError !== undefined || JSON.stringify(' . $map . '[' . json_encode($block_c_key) . "]?.variants ?? []).includes('button')",
      20000,
      'Background: the button wear resolved.',
    );
    $wear_error = $session->evaluateScript('window.__buttonWearError ?? null');
    $this->assertNull($wear_error, 'Background: wearing the button variant failed: ' . ($wear_error ?? ''));
    // Seam one: the resolution rule picked the anchor and the caption is
    // gone. The row is the background widget's, its axis the binding's
    // label (the kind's "Background", restated, never "Link fill"), and
    // it is the only row of that name on the panel.
    $background_rows_js = "[...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--background .daedalus-stylish-inspector__widget-row')]"
      . ".filter((row) => row.querySelector('.daedalus-stylish-inspector__widget-axis')?.textContent.trim() === 'Background')";
    $background_row_js = "($background_rows_js)[0]";
    $background_well_xpath = '//div[@id = "daedalus_stylish_inspector"]//*[contains(@class, "daedalus-stylish-inspector__widget--background")]'
      . '//*[contains(@class, "daedalus-stylish-inspector__widget-row")][.//*[contains(@class, "daedalus-stylish-inspector__widget-axis") and normalize-space() = "Background"]]'
      . '//button[contains(@class, "daedalus-stylish-swatch-well")]';
    $this->assertJsCondition(
      "($background_rows_js).length === 1",
      20000,
      'Background: the linked block\'s Basic panel renders exactly ONE row whose axis reads "Background" (the kind\'s id restated over the link-first list; no "Link fill" row beside it).',
    );
    $this->assertJsCondition(
      "!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__control-caption')",
      20000,
      'Background: no part caption renders anywhere on the panel (one row per binding, always; the caption is retired).',
    );
    $this->assertJsCondition(
      "(() => { const row = $background_row_js; if (!row) { return false; }"
      . " const well = row.querySelector('button.daedalus-stylish-swatch-well');"
      . " const face = well?.querySelector('.daedalus-stylish-swatch-well__face');"
      . " return !!well && well.classList.contains('daedalus-stylish-swatch-well--literal')"
      . " && !!face && getComputedStyle(face).backgroundColor === 'rgb(26, 26, 46)'; })()",
      20000,
      'Background: the row\'s swatch well wears the literal face painted the button\'s #1a1a2e (rgb(26, 26, 46)): the rule resolved background-color to the anchor, whose stack lit the root\'s reference layer at .text-block-link.',
    );
    $this->assertJsCondition(
      "($background_row_js)?.style.getPropertyValue('--daedalus-stylish-origin').trim() === " . json_encode(self::layerColor('button')),
      20000,
      'Background: the row\'s --daedalus-stylish-origin is the button layer\'s hue (layerColor(variantLayerId(button))).',
    );
    $this->assertJsCondition(
      "($background_row_js)?.querySelector('.daedalus-stylish-inspector__mark')?.title === '#1a1a2e from Button'",
      20000,
      'Background: the row\'s mark is titled "#1a1a2e from Button", originOf\'s inherited-from-variant sentence over the anchor\'s read.',
    );
    $this->assertJsCondition(
      '!' . $map . '[' . json_encode($link_key) . ']',
      20000,
      'Background: the map holds no entry at the part key; the lit fill is the variant layer\'s, never a write.',
    );

    // Seam two: the second authored candidate rides the row's menu. A
    // root background written through the op path (never the row: the
    // row addresses the anchor) is a second AUTHORED candidate for the
    // same property; the rule keeps the row on the first (the anchor)
    // and the read carries the other box as `alsoOn` (controls.js
    // resolveCandidates) into the origin and the row menu's inert
    // "Also set on element" line, hinted with the band's value, so the
    // hidden value surfaces honestly on the row that flattened it (no
    // section-header chip). The menu opens through the shared row menu
    // hook on a synthetic right-click; an outside pointerdown dismisses
    // the surface (the popover's own leave rule).
    $background_menu_items_js = "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".map((item) => [item.querySelector('.daedalus-ui-menu__label')?.textContent.trim(), item.querySelector('.daedalus-ui-menu__hint')?.textContent.trim() ?? null, item.getAttribute('aria-disabled')])";
    $open_background_menu_js = "(() => { const row = $background_row_js; const rect = row.getBoundingClientRect();"
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: rect.left + 8, clientY: rect.top + rect.height / 2 })); })()";
    $dismiss_menu_js = "document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));";
    $session->executeScript($open_background_menu_js);
    $this->assertJsCondition(
      "(() => { const items = $background_menu_items_js; if (!document.querySelector('.daedalus-ui-menu') || items.length === 0) { return false; }"
      . " return document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')?.textContent.trim() === '#1a1a2e from Button'"
      . " && !items.some(([label]) => (label ?? '').startsWith('Also set on')); })()",
      20000,
      'Background: before the root write the row menu opens under the origin\'s header ("#1a1a2e from Button") with no "Also set on" line (one authored candidate, nothing hidden).',
    );
    $session->executeScript($dismiss_menu_js);
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu')",
      20000,
      'Background: the outside pointerdown dismissed the row menu.',
    );
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{ op: 'update_style', key: '$block_c_key', properties: { 'background-color': '#ff0000' } }],
        })).catch((error) => {
          window.__rootBandError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      'window.__rootBandError !== undefined || ' . $map . '[' . json_encode($block_c_key) . "]?.default?.['background-color'] === '#ff0000'",
      20000,
      'Background: the root band write resolved.',
    );
    $root_band_error = $session->evaluateScript('window.__rootBandError ?? null');
    $this->assertNull($root_band_error, 'Background: writing background-color on the block root failed: ' . ($root_band_error ?? ''));
    $this->assertJsCondition(
      "(() => { const rows = $background_rows_js; if (rows.length !== 1) { return false; }"
      . " const face = rows[0].querySelector('button.daedalus-stylish-swatch-well .daedalus-stylish-swatch-well__face');"
      . " const mark = rows[0].querySelector('.daedalus-stylish-inspector__mark');"
      . " return !!face && getComputedStyle(face).backgroundColor === 'rgb(26, 26, 46)'"
      . " && !!mark && mark.title === '#1a1a2e from Button'"
      . " && mark.classList.contains('daedalus-stylish-inspector__mark--here')"
      . " && mark.style.getPropertyValue('--daedalus-stylish-origin').trim() === " . json_encode(self::layerColor('button')) . '; })()',
      20000,
      'Background: the one row still reads the anchor (the button\'s #1a1a2e, its mark titled from Button, filled in the button hue since the variant\'s block is the posed Base), never the root\'s band: authored-first keeps list order between two authored candidates.',
    );
    $session->executeScript($open_background_menu_js);
    $this->assertJsCondition(
      "(() => { const items = $background_menu_items_js; if (!document.querySelector('.daedalus-ui-menu') || items.length === 0) { return false; }"
      . " return document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')?.textContent.trim() === '#1a1a2e from Button'"
      . " && items.some(([label, hint, disabled]) => label === 'Also set on element' && hint === '#ff0000' && disabled === 'true'); })()",
      20000,
      'Background: the row menu carries the inert "Also set on element" line hinted with the band\'s #ff0000 under the anchor\'s header: the root\'s authored band is the second authored candidate the row does not show (resolveCandidates\' alsoOn, through the origin into rowMenu).',
    );
    $session->executeScript($dismiss_menu_js);
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu')",
      20000,
      'Background: the row menu closed again.',
    );
    $this->assertJsCondition(
      "(() => { const block = document.querySelector(\"$canvas_c\"); const link = block?.querySelector('a.text-block-link');"
      . " return !!link && getComputedStyle(block).backgroundColor === 'rgb(255, 0, 0)' && getComputedStyle(link).backgroundColor === 'rgb(26, 26, 46)'; })()",
      20000,
      'Background: both boxes paint on the canvas (the root\'s red band, the anchor\'s dark pill): the menu line names a value that really paints, the honesty rule\'s reason.',
    );

    // Undo the root band (the timeline's last step), so the pick below
    // lands on a block whose only authored background is the anchor's.
    $session->executeScript('Drupal.Daedalus.History.undo();');
    $this->assertJsCondition(
      $map . '[' . json_encode($block_c_key) . "]?.default?.['background-color'] === undefined",
      20000,
      'Background: undo cleared the root band from the map.',
    );
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_c_key');");
    $this->assertJsCondition(
      "($background_rows_js).length === 1",
      20000,
      'Background: after undo the one row stands.',
    );
    $session->executeScript($open_background_menu_js);
    $this->assertJsCondition(
      "(() => { const items = $background_menu_items_js; if (!document.querySelector('.daedalus-ui-menu') || items.length === 0) { return false; }"
      . " return !items.some(([label]) => (label ?? '').startsWith('Also set on')); })()",
      20000,
      'Background: after undo the row menu carries no "Also set on" line (one authored candidate again).',
    );
    $session->executeScript($dismiss_menu_js);
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu')",
      20000,
      'Background: the row menu closed after the undo read.',
    );

    // ----- The mark at the pose: set here, then not here. -----
    // Seam: the canvas vector (the width chip's real menu posing the
    // canvas at Medium) -> the editing compound the row writes at -> the
    // stack read at the pose (stackAt, originOf's two axes) -> the mark
    // (widgets/lib/mark.js: FILL = the winner's block is the posed
    // compound, HUE = the supplying layer, SHAPE = the gesture). Four
    // layers that only agree in a browser. The Alignment row is the row
    // for it: text-align binds the block root alone and no layer beneath
    // delivers it (the skin sets a text block's size, not its
    // alignment), so a REAL pick of Center while posed at Medium lands
    // at the root's medium compound and the row's mark is a FILLED
    // circle (a pick row) in the element hue titled "center set here at
    // Medium"; driving the canvas back to Base through the chip's Base
    // entry leaves nothing delivering text-align there, so the same mark
    // is HOLLOW in the same hue titled "Set at Medium, not here" over
    // unpressed segments (the row as a whole reads "authored elsewhere,
    // not here"), and the row menu's header says so with the Medium
    // entry as its drive. The states and sentences are node-pinned
    // (mark.test.mjs, provenance-model.test.mjs); the wire from the chip
    // to the rendered mark exists only here.
    $alignment_row_js = "[...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-inspector__widget-row')]"
      . ".find((row) => row.querySelector('.daedalus-stylish-inspector__widget-axis')?.textContent.trim() === 'Alignment')";
    $alignment_mark_js = "($alignment_row_js)?.querySelector('.daedalus-stylish-inspector__mark')";
    $alignment_pressed_js = "[...(($alignment_row_js)?.querySelectorAll('.daedalus-ui-segment') ?? [])].map((segment) => segment.getAttribute('aria-pressed')).join(',')";
    $center_segment_xpath = '//div[@id = "daedalus_stylish_inspector"]//*[contains(@class, "daedalus-stylish-inspector__widget-row")][.//*[contains(@class, "daedalus-stylish-inspector__widget-axis") and normalize-space() = "Alignment"]]'
      . '//button[contains(@class, "daedalus-ui-segment")][@aria-label = "Center"]';
    $this->assertJsCondition(
      "(() => { const mark = $alignment_mark_js; if (!mark) { return false; }"
      . " return mark.title === 'Not set' && !mark.classList.contains('daedalus-stylish-inspector__mark--here')"
      . " && $alignment_pressed_js === 'false,false,false,false'; })()",
      20000,
      'Mark at the pose: before the pick the Alignment row\'s mark is the resting ring titled "Not set" (no layer authors text-align on the block) over four unpressed segments.',
    );
    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-chip-row")]/button[contains(@class, "daedalus-stylish-chip--width")]');
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'Mark at the pose: the width chip opened its menu on the shared primitive.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Medium').click();"
    );
    $this->assertJsCondition(
      "document.documentElement.hasAttribute('data-canvas-width')"
      . " && (() => { const chip = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-chip--width');"
      . " return !!chip && chip.classList.contains('daedalus-stylish-chip--lit') && chip.textContent.includes('Medium'); })()",
      20000,
      'Mark at the pose: the width chip posed the canvas at Medium (the document wears the width stamp, the chip reads Medium, lit).',
    );
    $this->clickPanelXpath($center_segment_xpath);
    $this->assertJsCondition(
      $map . '[' . json_encode($block_c_key) . "]?.medium?.['text-align'] === 'center'"
      . ' && ' . $map . '[' . json_encode($block_c_key) . "]?.default?.['text-align'] === undefined",
      20000,
      'Mark at the pose: the Center pick committed text-align: center at the block root\'s medium compound and nothing at Base (the editing compound follows the pose; Alignment binds the root).',
    );
    $this->assertJsCondition(
      "(() => { const mark = $alignment_mark_js; if (!mark) { return false; }"
      . " return mark.classList.contains('daedalus-stylish-inspector__mark--here')"
      . " && !mark.classList.contains('daedalus-stylish-inspector__mark--slide')"
      . " && mark.style.getPropertyValue('--daedalus-stylish-origin').trim() === 'hsl(213 90% 66%)'"
      . " && mark.title === 'center set here at Medium'"
      . " && $alignment_pressed_js === 'false,true,false,false'; })()",
      20000,
      'Mark at the pose: the Alignment row\'s mark is FILLED (--here) in the element hue (layers.js layerColor(ELEMENT_LAYER)), a circle (a pick row, no --slide) titled "center set here at Medium", the Center segment pressed: the winner\'s block is the posed compound.',
    );
    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-chip-row")]/button[contains(@class, "daedalus-stylish-chip--width")]');
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'Mark at the pose: the width chip reopened its menu.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Base').click();"
    );
    $this->assertJsCondition(
      "!document.documentElement.hasAttribute('data-canvas-width')"
      . " && !document.querySelector('.daedalus-ui-menu')"
      . " && (() => { const chip = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-chip--width');"
      . " return !!chip && !chip.classList.contains('daedalus-stylish-chip--lit') && chip.textContent.trim().startsWith('Base'); })()",
      20000,
      'Mark at the pose: the chip\'s Base entry drove the canvas back (the width stamp is gone, the chip reads Base, unlit, the menu closed).',
    );
    $this->assertJsCondition(
      "(() => { const mark = $alignment_mark_js; if (!mark) { return false; }"
      . " return !mark.classList.contains('daedalus-stylish-inspector__mark--here')"
      . " && mark.style.getPropertyValue('--daedalus-stylish-origin').trim() === 'hsl(213 90% 66%)'"
      . " && mark.title === 'Set at Medium, not here'"
      . " && $alignment_pressed_js === 'false,false,false,false'; })()",
      20000,
      'Mark at the pose: back at Base the Alignment row\'s mark is HOLLOW (no --here) in the element hue titled "Set at Medium, not here" over four unpressed segments: nothing delivers text-align at Base, and the mark still names the layer that authored it elsewhere.',
    );
    $session->executeScript(
      "(() => { const row = $alignment_row_js; const rect = row.getBoundingClientRect();"
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: rect.left + 8, clientY: rect.top + rect.height / 2 })); })()"
    );
    $this->assertJsCondition(
      "(() => { const items = $background_menu_items_js; if (!document.querySelector('.daedalus-ui-menu') || items.length === 0) { return false; }"
      . " return document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')?.textContent.trim() === 'Set at Medium, not here'"
      . " && items.some(([label, hint]) => label === 'Medium' && hint === 'center'); })()",
      20000,
      'Mark at the pose: the Alignment row\'s menu at Base opens under the same "Set at Medium, not here" header (every menu a header) with Medium in its "Also set at" group hinted center, the drive to where the value lives.',
    );
    $session->executeScript($dismiss_menu_js);
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu') && " . $map . '[' . json_encode($block_c_key) . "]?.medium?.['text-align'] === 'center'",
      20000,
      'Mark at the pose: the menu closed and the drive wrote nothing; the medium block keeps its center.',
    );

    // Seam three: write where you read. Pre-commit facts: the accent
    // token resolved inside the block (the color the anchor will wear).
    // A REAL click on the row's well opens the palette popover, and a
    // REAL pick of the accent swatch commits ONE update_style at the
    // PART key, the candidate the row read from, carrying
    // background-color alone (backgroundPick over an unset image), and
    // the root gets nothing.
    $accent_js = "(() => { const block = document.querySelector(\"$canvas_c\"); const probe = document.createElement('span');"
      . " probe.style.color = 'var(--color-accent)'; block.append(probe); const accent = getComputedStyle(probe).color; probe.remove(); return accent; })()";
    $accent = (string) $session->evaluateScript($accent_js);
    $this->assertMatchesRegularExpression('/^rgb\(/', $accent, 'The accent token resolves to a color inside the linked block.');
    $this->recordOps();
    $this->clickPanelXpath($background_well_xpath);
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', $accent_stop_xpath),
      'Background: the row\'s well opened the palette popover offering the accent swatch.',
    );
    $this->clickPanelXpath($accent_stop_xpath);
    $this->assertJsCondition(
      $map . '[' . json_encode($link_key) . "]?.default?.['background-color'] === 'var(--color-accent)'"
      . ' && ' . $map . '[' . json_encode($block_c_key) . "]?.default?.['background-color'] === undefined",
      20000,
      'Background: the pick committed background-color: var(--color-accent) at block:{uuid}::.text-block-link, the candidate the row read from, and nothing on the root (write where you read).',
    );
    $this->assertOneOp(
      $link_key,
      ['background-color' => 'var(--color-accent)'],
      'Background: the pick was ONE update_style op on the PART key carrying background-color alone: the pipeline routed the gesture to the resolved key.',
    );
    $this->assertJsCondition(
      "(() => { const link = document.querySelector(\"$canvas_c a.text-block-link\");"
      . ' return !!link && getComputedStyle(link).backgroundColor === ' . json_encode($accent) . '; })()',
      20000,
      'Background: the anchor wears the accent on the canvas without reload: the part-keyed write paints where the row read.',
    );
    $this->assertJsCondition(
      "(() => { const row = $background_row_js; if (!row) { return false; }"
      . " const well = row.querySelector('button.daedalus-stylish-swatch-well');"
      . " return !!well && well.classList.contains('daedalus-stylish-swatch-well--stop')"
      . " && !document.querySelector('.daedalus-ui-popover .daedalus-stylish-swatch-popover'); })()",
      20000,
      'Background: the popover closed on the pick and the row\'s well lights the accent stop: the row reads back its own write.',
    );

    // Seam four: the absent first candidate falls to the root. An
    // UNLINKED text block renders no anchor, so its `.text-block-link`
    // candidate is absent (layout.self.parts) and the rule drops it: the
    // same binding renders one Background row over the root alone, and
    // the same pick commits at the plain block key.
    $block_d_uuid = $uuid_service->generate();
    $section_d_uuid = $uuid_service->generate();
    $this->placeTextTypeBlock($section_d_uuid, $block_d_uuid, '__plainTextPlacementError', NULL);
    $block_d_key = "block:$block_d_uuid";
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_d_key');");
    $this->assertJsCondition(
      "(() => { const active = document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]');"
      . " return !!active && active.textContent.trim() === 'Basic'; })()",
      20000,
      'Background: the inspector is in Basic mode on the unlinked text block.',
    );
    $this->assertJsCondition(
      "($background_rows_js).length === 1 && !document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__control-caption')",
      20000,
      'Background: the unlinked block\'s Basic panel renders exactly ONE captionless Background row: the absent anchor dropped out of the list and the root is the only candidate left.',
    );
    $this->recordOps();
    $this->clickPanelXpath($background_well_xpath);
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', $accent_stop_xpath),
      'Background: the unlinked block\'s well opened the palette popover offering the accent swatch.',
    );
    $this->clickPanelXpath($accent_stop_xpath);
    $this->assertJsCondition(
      $map . '[' . json_encode($block_d_key) . "]?.default?.['background-color'] === 'var(--color-accent)'"
      . ' && !' . $map . '[' . json_encode("$block_d_key::.text-block-link") . ']',
      20000,
      'Background: the pick committed background-color: var(--color-accent) at the plain block key and nothing at the absent part key (the absent first candidate fell to the root).',
    );
    $this->assertOneOp(
      $block_d_key,
      ['background-color' => 'var(--color-accent)'],
      'Background: the unlinked block\'s pick was ONE update_style op on the plain block key carrying background-color alone.',
    );
    $this->assertJsCondition(
      "(() => { const block = document.querySelector(\"[data-selectable='$block_d_key']\");"
      . ' return !!block && getComputedStyle(block).backgroundColor === ' . json_encode($accent) . '; })()',
      20000,
      'Background: the unlinked block\'s root wears the accent on the canvas without reload.',
    );

    // Seam five: the one legitimate multi-key batch. A gesture's SET
    // values land on ONE box as a group (controls.js resolveGroup, the
    // address rule asked for the set) and a null member (a removal)
    // lands where the value lives (write where you read, per property),
    // so a background REPLACEMENT on a linked block whose root wears an
    // authored band spans two boxes: the gradient pick's image means the
    // anchor (nothing authored for it anywhere: first present) while the
    // band it replaces is cleared on the root. The pipeline joins the
    // two keys in ONE atomic mutate() batch (compoundOperations) under
    // one undo label; the batch through the real op path and the canvas
    // repaint (the anchor painting the gradient, the band gone) exist
    // only here. A fresh linked block, so the root's band is the only
    // authored background on either box (block C's anchor wears the
    // button and its accent pick).
    $block_f_uuid = $uuid_service->generate();
    $section_f_uuid = $uuid_service->generate();
    $this->placeTextTypeBlock($section_f_uuid, $block_f_uuid, '__replacementPlacementError', 'https://example.com/replacement');
    $block_f_key = "block:$block_f_uuid";
    $link_f_key = "$block_f_key::.text-block-link";
    $canvas_f = "[data-selectable='$block_f_key']";
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{ op: 'update_style', key: '$block_f_key', properties: { 'background-color': '#ff0000' } }],
        })).catch((error) => {
          window.__replacementBandError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      'window.__replacementBandError !== undefined || ' . $map . '[' . json_encode($block_f_key) . "]?.default?.['background-color'] === '#ff0000'",
      20000,
      'Replacement: the root band write resolved.',
    );
    $replacement_band_error = $session->evaluateScript('window.__replacementBandError ?? null');
    $this->assertNull($replacement_band_error, 'Replacement: writing background-color on the fresh linked block\'s root failed: ' . ($replacement_band_error ?? ''));
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_f_key');");
    $this->assertJsCondition(
      "(() => { const rows = $background_rows_js; if (rows.length !== 1) { return false; }"
      . " const face = rows[0].querySelector('button.daedalus-stylish-swatch-well .daedalus-stylish-swatch-well__face');"
      . " return !!face && getComputedStyle(face).backgroundColor === 'rgb(255, 0, 0)'; })()",
      20000,
      'Replacement: the fresh linked block\'s one Background row reads the root\'s authored band (rgb(255, 0, 0)) on its well face: the band is the only authored value on either box.',
    );
    $hero_fade_stop_xpath = '//div[contains(@class, "daedalus-ui-popover")]//div[contains(@class, "daedalus-stylish-swatch-popover")]'
      . '//button[contains(@class, "daedalus-stylish-palette-grid__stop")][@aria-label = "Hero fade"]';
    $this->recordOps();
    $this->clickPanelXpath($background_well_xpath);
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', $hero_fade_stop_xpath),
      'Replacement: the row\'s well opened the palette popover offering the gradient swatch as a stop.',
    );
    $this->clickPanelXpath($hero_fade_stop_xpath);
    $this->assertJsCondition(
      $map . '[' . json_encode($link_f_key) . "]?.default?.['background-image'] === 'var(--swatch-hero_fade)'"
      . ' && ' . $map . '[' . json_encode($block_f_key) . "]?.default?.['background-color'] === undefined"
      . ' && ' . $map . '[' . json_encode($link_f_key) . "]?.default?.['background-color'] === undefined",
      20000,
      'Replacement: the gradient pick placed background-image: var(--swatch-hero_fade) on the anchor (the set\'s box as a group) and cleared the root\'s background-color (the removal, where the band lived); the anchor got no color.',
    );
    $this->assertOneBatch(
      [
        [$link_f_key, ['background-image' => 'var(--swatch-hero_fade)']],
        [$block_f_key, ['background-color' => NULL]],
      ],
      'Replacement: the pick was ONE mutate() batch of exactly two update_style ops, the image on the PART key and the null on the ROOT key, each carrying its property alone.',
    );
    $this->assertJsCondition(
      "(() => { const block = document.querySelector(\"$canvas_f\"); const link = document.querySelector(\"$canvas_f a.text-block-link\");"
      . " return !!block && !!link && getComputedStyle(link).backgroundImage.includes('linear-gradient')"
      . " && getComputedStyle(block).backgroundColor === 'rgba(0, 0, 0, 0)'; })()",
      20000,
      'Replacement: the canvas repainted without reload: the anchor paints the swatch gradient and the root\'s band is gone.',
    );
    $this->assertJsCondition(
      "(() => { const row = $background_row_js; if (!row) { return false; }"
      . " const well = row.querySelector('button.daedalus-stylish-swatch-well');"
      . " return !!well && well.classList.contains('daedalus-stylish-swatch-well--stop')"
      . " && !document.querySelector('.daedalus-ui-popover .daedalus-stylish-swatch-popover'); })()",
      20000,
      'Replacement: the popover closed on the pick and the row\'s well lights the gradient stop: the row reads back the anchor\'s image, the box the group resolved to.',
    );

    // ----- The write address: one link, every link in its section. -----
    // Seam: the address switcher (inspector.js over write-address.js
    // addressSet) -> the spine's styling target seated on the section's
    // rule (Selection.target, the inspector reading it back as its
    // address by reach, write-address.js facetOf) -> three
    // consumers that only agree in a browser: the canvas paint slot
    // (canvas-selector-paint.js, the printers' targets injected as CSS
    // and computed by the cascade), controlModels' rebase and withheld
    // verdicts rendered as live and disabled rows, and the Basic
    // widget's typed settle landing ONE update_style at the ADDRESS key
    // while the selection stays on the link, the delivered rule then
    // computing on every anchor the section holds. addressSet, the
    // rebase rule and the editable-layer rule are node-tested
    // (write-address, controls, style-stack); the chip, the menu, the
    // paint and the disabled posture over one real write exist only
    // here. The fixture: a new band holding three LINKED text blocks
    // (two `p` roots and one `h3` root, so the tag rule's reach into a
    // heading-rooted block is on the canvas too), placed through the op
    // spine; the site's demo container carries no linked siblings, so
    // the scenario mints its own.
    $section_e_uuid = $uuid_service->generate();
    $link_uuids = [$uuid_service->generate(), $uuid_service->generate(), $uuid_service->generate()];
    [$link_1, $link_2, $link_3] = $link_uuids;
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_section',
              uuid: '$section_e_uuid',
              location: { anchor: Blueprint.getRoot().uuid, placement: 'inside' },
            },
            {
              op: 'add_element',
              uuid: '$link_1',
              block_type: 'text',
              properties: { field_text_text: { value: 'First link' }, field_text_tag: 'p', field_text_url: { uri: 'https://example.com/1' } },
              location: { anchor: '$section_e_uuid', placement: 'inside' },
            },
            {
              op: 'add_element',
              uuid: '$link_2',
              block_type: 'text',
              properties: { field_text_text: { value: 'Second link' }, field_text_tag: 'p', field_text_url: { uri: 'https://example.com/2' } },
              location: { anchor: '$link_1', placement: 'after' },
            },
            {
              op: 'add_element',
              uuid: '$link_3',
              block_type: 'text',
              properties: { field_text_text: { value: 'Third link' }, field_text_tag: 'h3', field_text_url: { uri: 'https://example.com/3' } },
              location: { anchor: '$link_2', placement: 'after' },
            },
          ],
        })).catch((error) => {
          window.__addressPlacementError = error.message;
        });
      })();
      JS);
    $anchors_js = '[' . implode(', ', array_map(static fn (string $uuid) => "document.querySelector(\"[data-selectable='block:$uuid'] a.text-block-link\")", $link_uuids)) . ']';
    $this->assertJsCondition(
      "window.__addressPlacementError !== undefined"
      . " || (($anchors_js).every((anchor) => !!anchor) && JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$link_3'))",
      20000,
      'Write address: the band of three linked text blocks placed and rendered, each with its anchor.',
    );
    $address_placement_error = $session->evaluateScript('window.__addressPlacementError ?? null');
    $this->assertNull($address_placement_error, 'Write address: placing the linked band failed: ' . ($address_placement_error ?? ''));
    $container_e_key = "container:$section_e_uuid";
    $tag_rule_key = "$container_e_key::a";
    $block_e1_key = "block:$link_1";
    $link_e1_key = "$block_e1_key::.text-block-link";
    $address_chip = '#daedalus_stylish_inspector .daedalus-stylish-inspector__address';
    $write_paint_js = "document.querySelector('style[data-daedalus-stylish-selector-paint=\"write\"]')";
    $menu_items_js = "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".map((item) => [item.querySelector('.daedalus-ui-menu__label')?.textContent.trim(), item.querySelector('.daedalus-ui-menu__hint')?.textContent.trim() ?? null, item.getAttribute('aria-checked')])";
    $background_well = '#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--background button.daedalus-stylish-swatch-well';
    $corners_input = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--corners-border input[aria-label='Corners']";
    // The bare element first: the subject line's chip is always rendered
    // (the line keeps one shape), reading "This element" at the block's
    // own key (the chip's map key is the bare key, no selector), unlit.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_e1_key');");
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); if (!chip) { return false; }"
      . ' return chip.dataset.daedalusStylishAddress === ' . json_encode($block_e1_key)
      . " && chip.querySelector('.daedalus-stylish-inspector__address-label')?.textContent.trim() === 'This element'"
      . " && !chip.classList.contains('daedalus-stylish-chip--lit')"
      . " && Drupal.Daedalus.Selection.getTarget() === null; })()",
      20000,
      'Write address: at the bare element the chip renders at the block\'s own key reading "This element", unlit, with no target seated.',
    );
    // The reach gate on the canvas. Seam: the part resolver's injected
    // reachable-row predicate (view-registry.js over reach.js) under the
    // Selector's deep policy. In Basic the mode mirror flipped the Markup
    // view off, so the panel draws no shell rows: a Cmd-click on the
    // first link's anchor (synthetic, the OS modifier cannot ride a Mink
    // click; the same capture-phase document listener) resolves the
    // deepest DEPICTED thing, the block, and seats no target even though
    // block::.text-block-link exists in the index. The Markup view back
    // on through the Show menu's real toggle, the same click seats the
    // link part; off again, the standing target degrades to a doorway
    // seat and stands (a view flip never drops a target).
    $first_anchor_js = "document.querySelector(\"[data-selectable='$block_e1_key'] a.text-block-link\")";
    $cmd_click_js = "($first_anchor_js).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, metaKey: true }));";
    $markup_item_js = "Array.from(document.querySelectorAll('.daedalus-ui-popover > .daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent === 'Markup') ?? null";
    $link_row_js = "document.querySelector('.daedalus-ui-tree [data-tree-address=\"$link_e1_key\"]')";
    $this->assertJsCondition(
      "$link_row_js === null",
      20000,
      'Reach: in Basic (Markup view off) the panel draws no row for the first link part.',
    );
    // Cleared first, so the click's element commit is a real write (a
    // same-address commit dispatches nothing).
    $session->executeScript("Drupal.Daedalus.Selection.clear(); $cmd_click_js");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === " . json_encode($block_e1_key)
      . ' && Drupal.Daedalus.Selection.getTarget() === null',
      20000,
      'Reach: a Cmd-click on the first link\'s anchor with the Markup view off selects the block and seats no target: the depth stop is the deepest depicted row.',
    );
    $session->executeScript("document.querySelector('.daedalus-ui-show-menu__button').click();");
    $this->assertJsCondition(
      "(() => { const item = $markup_item_js; return !!item && item.getAttribute('role') === 'menuitemcheckbox' && item.getAttribute('aria-checked') === 'false'; })()",
      20000,
      'Reach: the Show menu lists the Markup view unchecked in Basic.',
    );
    $session->executeScript("($markup_item_js).click();");
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover') && $link_row_js !== null",
      20000,
      'Reach: activating the Markup item closed the Show menu and the panel drew the first link\'s part row.',
    );
    $session->executeScript($cmd_click_js);
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === " . json_encode($block_e1_key)
      . ' && Drupal.Daedalus.Selection.getTarget()?.key === ' . json_encode($block_e1_key)
      . " && Drupal.Daedalus.Selection.getTarget()?.selector === '.text-block-link'",
      20000,
      'Reach: the same Cmd-click with the Markup view on seats the link part: the row is drawn, so the stop lands on it.',
    );
    $session->executeScript("document.querySelector('.daedalus-ui-show-menu__button').click();");
    $this->assertJsCondition(
      "(() => { const item = $markup_item_js; return !!item && item.getAttribute('aria-checked') === 'true'; })()",
      20000,
      'Reach: the reopened Show menu shows the Markup view checked.',
    );
    $session->executeScript("($markup_item_js).click();");
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover') && $link_row_js === null"
      . ' && Drupal.Daedalus.Selection.getSelected() === ' . json_encode($block_e1_key)
      . " && Drupal.Daedalus.Selection.getTarget()?.selector === '.text-block-link'",
      20000,
      'Reach: the Markup view off again dropped the link\'s row and the seated target still stands on the block (a doorway seat, not a drop).',
    );
    // Target the first link PART: the selection is the block, its link
    // part the styling target, and both stay for the whole leg. The
    // switcher renders on the target chip at the own address ("This
    // link"), unlit, and no write paint stands while the address is the
    // own key.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_e1_key'); Drupal.Daedalus.Selection.target('$block_e1_key', '.text-block-link');");
    $this->assertJsCondition(
      "(() => { const active = document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]');"
      . " return !!active && active.textContent.trim() === 'Basic'; })()",
      20000,
      'Write address: the inspector is in Basic mode on the first link.',
    );
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); if (!chip) { return false; }"
      . ' return chip.dataset.daedalusStylishAddress === ' . json_encode($link_e1_key)
      . " && chip.querySelector('.daedalus-stylish-inspector__address-label')?.textContent.trim() === 'This link'"
      . " && !chip.classList.contains('daedalus-stylish-chip--lit') && !$write_paint_js; })()",
      20000,
      'Write address: the switcher chip renders on the link at its own address (block::.text-block-link), reading "This link", unlit, with no write paint on the canvas.',
    );
    // A REAL click on the chip opens the shared menu headed "Style this
    // at": the own key first, then the mintable addresses nearest
    // ancestor first, every one marked "new rule" (nothing exists yet).
    // The tag rule reads "Every link in Section": the fresh section
    // carries no label, and the base `a` tag reads its shipped word
    // (StyleGuide::BASE_TAG_LABELS served as the guide's tagLabels; no
    // instance namespace words it); the chained shapes carry the text
    // block's part word.
    $this->clickPanel($address_chip);
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu') && document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')?.textContent.trim() === 'Style this at'",
      20000,
      'Write address: the chip opened the shared menu headed "Style this at".',
    );
    $this->assertSame(
      [
        ['This link', NULL, 'true'],
        ['This element', NULL, 'false'],
        ['Every link in Section', 'new rule', 'false'],
        ['Text block links directly in Section', 'new rule', 'false'],
        ['Text block links in Section', 'new rule', 'false'],
      ],
      json_decode((string) $session->evaluateScript("JSON.stringify($menu_items_js)"), TRUE),
      'Write address: the menu lists the link\'s address set in order (the own key checked, "This element" as the way back to the bare element while the part facet stands, then the three mintable addresses on the section, each hinted "new rule"), the tag rule labelled "Every link in Section", and no generated-box doorway: basic mode mounts no rows at a box, so it offers none (advanced and code do).',
    );
    // A REAL pick of the tag rule: the chip lights at container::a, the
    // styling target moves to the section's rule (one target: the part's
    // dashed box goes with it), the canvas paints the rule's every match
    // through the printers' own targets (all three anchors wear the
    // dotted write box), and the selection is still the first link.
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Every link in Section').click();"
    );
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); if (!chip) { return false; }"
      . ' return chip.dataset.daedalusStylishAddress === ' . json_encode($tag_rule_key)
      . " && chip.querySelector('.daedalus-stylish-inspector__address-label')?.textContent.trim() === 'Every link in Section'"
      . " && chip.classList.contains('daedalus-stylish-chip--lit') && !document.querySelector('.daedalus-ui-menu')"
      . " && Drupal.Daedalus.Selection.getSelected() === " . json_encode($block_e1_key)
      . " && Drupal.Daedalus.Selection.getTarget()?.key === " . json_encode($container_e_key)
      . " && Drupal.Daedalus.Selection.getTarget()?.selector === 'a'; })()",
      20000,
      'Write address: the pick closed the menu, seated the section\'s `a` rule as the styling target and lit the chip at container::a ("Every link in Section") while the selection stayed on the first link.',
    );
    $this->assertJsCondition(
      "(() => { const text = $write_paint_js?.textContent ?? '';"
      . ' return text.includes(' . json_encode('@scope ([data-selectable="' . $container_e_key . '"])') . ")"
      . " && /\\n  a \\{\\n    outline: 2px dotted/.test(text); })()",
      20000,
      'Write address: the write paint slot holds the tag rule\'s @scope block on the section with the bare `a` target (the printer\'s own target for a tag) in the dotted treatment.',
    );
    $this->assertJsCondition(
      "(() => { return ($anchors_js).map((anchor) => getComputedStyle(anchor))"
      . ".every((style) => style.outlineStyle === 'dotted' && style.outlineWidth === '2px' && style.outlineOffset === '5px'); })()",
      20000,
      'Write address: every anchor in the section wears the dotted write box on the canvas (the blast radius before any write), the first one included: the target is the rule now, so the part\'s dashed box is gone.',
    );
    // The rows stay the LINK's rows, judged at the address: base `a`
    // grants typography and motion only, so the Background well and the
    // Corners field render disabled with the roster's reason as their
    // title, while the Size field stays live (typography lands at `a`).
    $withheld_title = static fn (string $property) => sprintf('%s is not allowed at "a" on Section: the style guide does not grant it there. Choose this element alone to set it here.', $property);
    $this->assertJsCondition(
      "(() => { const well = document.querySelector('$background_well'); const corners = document.querySelector(\"$corners_input\"); const size = document.querySelector(\"" . self::SIZE_INPUT . "\");"
      . ' return !!well && well.disabled && well.title === ' . json_encode($withheld_title('background-color'))
      . ' && !!corners && corners.disabled && corners.title === ' . json_encode($withheld_title('border-radius'))
      . ' && !!size && !size.disabled; })()',
      20000,
      'Write address: under the section\'s `a` rule the link\'s Background well and Corners field are disabled with the grant reason as their title (base `a` grants neither), while the Size field stays live (typography is granted at `a`).',
    );
    // Pre-write facts: the block roots' font sizes, which the tag rule
    // must leave alone (it targets the anchors, never the roots).
    $roots_js = '[' . implode(', ', array_map(static fn (string $uuid) => "document.querySelector(\"[data-selectable='block:$uuid']\")", $link_uuids)) . ']';
    $root_sizes_js = "($roots_js).map((root) => getComputedStyle(root).fontSize)";
    $root_sizes = json_decode((string) $session->evaluateScript("JSON.stringify($root_sizes_js)"), TRUE);
    $this->assertCount(3, $root_sizes, 'Write address: the three block roots\' font sizes were measured before the write.');
    $this->assertJsCondition(
      "!($anchors_js).some((anchor) => getComputedStyle(anchor).fontSize === '30px')",
      20000,
      'Write address: no anchor computes 30px before the write, so the write below is observable.',
    );
    // The write: a typed Size settles through the widget's own path and
    // lands ONE update_style at the ADDRESS key (container::a), nothing
    // on the link or its block; the delivered rule computes on all
    // three anchors, the third inside an h3-rooted block (a tag rule
    // reaches every anchor under the section, doctrine B), and the
    // roots are untouched.
    $this->recordOps();
    $this->typeIntoPanel(self::SIZE_INPUT, '30px');
    $this->blurPanel(self::SIZE_INPUT);
    $this->assertJsCondition(
      $map . '[' . json_encode($tag_rule_key) . "]?.default?.['font-size'] === '30px'"
      . ' && !' . $map . '[' . json_encode($link_e1_key) . ']'
      . ' && ' . $map . '[' . json_encode($block_e1_key) . "]?.default?.['font-size'] === undefined",
      20000,
      'Write address: the typed Size committed font-size: 30px at container::a, the write address, and nothing at the link or its block.',
    );
    $this->assertOneOp(
      $tag_rule_key,
      ['font-size' => '30px'],
      'Write address: the settle was ONE update_style op on the address key carrying font-size alone: the Basic widget wrote at the rebased candidate.',
    );
    $this->assertJsCondition(
      "($anchors_js).every((anchor) => getComputedStyle(anchor).fontSize === '30px')",
      20000,
      'Write address: all three anchors compute 30px on the canvas without reload, the third inside an h3-rooted block: one rule on the section, every link in it.',
    );
    $this->assertJsCondition(
      "JSON.stringify($root_sizes_js) === " . json_encode(json_encode($root_sizes)),
      20000,
      'Write address: the block roots keep their font sizes: the tag rule reaches the anchors alone.',
    );
    // Back to the own key: the reopened menu checks the tag rule, now
    // existing (no "new rule" hint); picking "This link" clears the
    // address, the write paint, and the Size row reads the section's
    // rule as the inherited origin on its mark.
    $this->clickPanel($address_chip);
    $this->assertJsCondition(
      "(() => { const items = $menu_items_js; return items.length === 5"
      . " && items[0][0] === 'This link' && items[0][2] === 'false'"
      . " && items[1][0] === 'This element' && items[1][2] === 'false'"
      . " && items[2][0] === 'Every link in Section' && items[2][1] === null && items[2][2] === 'true'; })()",
      20000,
      'Write address: the reopened menu checks "Every link in Section", no longer hinted "new rule" (the address exists in the map now), and the own key and the "This element" way back are unchecked.',
    );
    $session->executeScript("document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item')[0].click();");
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); if (!chip) { return false; }"
      . ' return chip.dataset.daedalusStylishAddress === ' . json_encode($link_e1_key)
      . " && !chip.classList.contains('daedalus-stylish-chip--lit') && !$write_paint_js && !document.querySelector('.daedalus-ui-menu'); })()",
      20000,
      'Write address: picking "This link" put the chip back on the own address, unlit, and removed the write paint.',
    );
    $this->assertJsCondition(
      "(() => { const input = document.querySelector(\"" . self::SIZE_INPUT . "\"); const row = input?.closest('.daedalus-stylish-inspector__widget-row');"
      . " return !!row && input.value === '30'"
      . " && row.querySelector('.daedalus-stylish-inspector__mark')?.title === '30px inherited from Section (a)'"
      . " && row.style.getPropertyValue('--daedalus-stylish-origin').trim() !== '';"
      . ' })()',
      20000,
      'Write address: back on the own key the Size row reads 30 with its mark titled "30px inherited from Section (a)" and the row hued for the ancestor layer: the link reads the section\'s rule through its stack.',
    );
    $this->assertJsCondition(
      "(() => { const well = document.querySelector('$background_well'); return !!well && !well.disabled; })()",
      20000,
      'Write address: the Background well is live again at the own key.',
    );
    // The own entry wins on this anchor alone: a typed 24px lands ONE
    // update_style at the link's own key, the first anchor computes it
    // and its siblings keep the section's 30px (the child's own entry
    // wins by @scope proximity, never by weight).
    $this->recordOps();
    $this->typeIntoPanel(self::SIZE_INPUT, '24px');
    $this->blurPanel(self::SIZE_INPUT);
    $this->assertJsCondition(
      $map . '[' . json_encode($link_e1_key) . "]?.default?.['font-size'] === '24px'"
      . ' && ' . $map . '[' . json_encode($tag_rule_key) . "]?.default?.['font-size'] === '30px'",
      20000,
      'Write address: the typed Size committed font-size: 24px at the link\'s own key, the section\'s rule still holding 30px.',
    );
    $this->assertOneOp(
      $link_e1_key,
      ['font-size' => '24px'],
      'Write address: the settle was ONE update_style op on the own part key carrying font-size alone.',
    );
    $this->assertJsCondition(
      "($anchors_js).map((anchor) => getComputedStyle(anchor).fontSize).join(',') === '24px,30px,30px'",
      20000,
      'Write address: the first anchor computes its own 24px and its two siblings keep the section\'s 30px: the child\'s own entry wins on that anchor only.',
    );

    // ----- Wear at the rule: the wear bar and a card apply at the target. -----
    // Seam: the styling target seated on the section's `a` rule -> the
    // wear bar (wear-bar.js, its `target` the rule) and the Materials
    // card (library.js, applyDoorway over the same target) -> ONE
    // apply_variant at the RULE key -> the server's rule-wear lane
    // (VariantStylesheet::ruleWearCss, the printer twin on the client)
    // printing the variant inside the section's @scope, so every anchor
    // computes it and no element carries a variant class. The landing
    // pairing, the reference placement and the printed lane are
    // Kernel-pinned; the gestures aimed at the target and the delivered
    // wear on the canvas exist only here. The rule is re-seated through
    // the facade, the road the switcher's pick took above.
    $wear_bar = '#daedalus_stylish_inspector .daedalus-stylish-wear-bar';
    $wear_rows = "document.querySelectorAll('$wear_bar .daedalus-stylish-wear-bar__row')";
    $picker_labels_js = "Array.from(document.querySelectorAll('.daedalus-ui-popover .daedalus-stylish-variant-picker__option .daedalus-stylish-variant-picker__label')).map((label) => label.textContent.trim())";
    $rule_refs_js = "JSON.stringify(($map" . '[' . json_encode($tag_rule_key) . "]?.variants ?? []).map((ref) => [ref.variant, ref.fragment]))";
    $anchor_wear_js = "($anchors_js).map((anchor) => getComputedStyle(anchor)).map((style) => style.color + ' ' + style.letterSpacing).join(',')";
    $one_apply_op = static fn (string $variant) => '(() => { const log = ' . self::OP_LOG . ';'
      . ' if (log.length !== 1 || log[0].length !== 1) { return false; }'
      . ' const [operation] = log[0];'
      . " return operation.op === 'apply_variant' && operation.key === " . json_encode($tag_rule_key)
      . ' && operation.variant === ' . json_encode($variant) . '; })()';
    $session->executeScript("Drupal.Daedalus.Selection.target('$container_e_key', 'a');");
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); const bar = document.querySelector('$wear_bar');"
      . " const add = document.querySelector('$wear_bar .daedalus-stylish-wear-bar__add');"
      . ' return !!chip && chip.dataset.daedalusStylishAddress === ' . json_encode($tag_rule_key)
      . " && chip.classList.contains('daedalus-stylish-chip--lit')"
      . " && !!bar && !bar.classList.contains('daedalus-stylish-wear-bar--read-only')"
      . " && $wear_rows.length === 0 && !!add && !add.disabled; })()",
      20000,
      'Rule wear: with container::a re-seated the chip lights there again and the wear bar is LIVE at the rule (a tag landing wears), no row yet, its add row enabled.',
    );
    $this->clickPanel("$wear_bar .daedalus-stylish-wear-bar__add");
    $this->assertJsCondition(
      "(() => { const labels = $picker_labels_js; labels.sort(); return JSON.stringify(labels) === " . json_encode(json_encode(['Accent link', 'Quiet link'])) . '; })()',
      20000,
      'Rule wear: the picker offers exactly the two base:a variants (the rule\'s landing is the tag `a`; nothing element-scoped offers at it).',
    );
    $this->recordOps();
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-popover .daedalus-stylish-variant-picker__option'))"
      . ".find((option) => option.querySelector('.daedalus-stylish-variant-picker__label')?.textContent.trim() === 'Accent link').click();"
    );
    $this->assertJsCondition(
      $one_apply_op('accent_link'),
      20000,
      'Rule wear: the pick dispatched ONE apply_variant op at container::a, the rule key, never at the link or its block.',
    );
    $this->assertJsCondition(
      "$rule_refs_js === " . json_encode(json_encode([['accent_link', '']]))
      . ' && ' . $map . '[' . json_encode($tag_rule_key) . "]?.default?.['font-size'] === '30px'"
      . ' && ' . $map . '[' . json_encode($link_e1_key) . '].variants === undefined'
      . ' && ' . $map . '[' . json_encode($block_e1_key) . ']?.variants === undefined',
      20000,
      'Rule wear: the map carries the reference on the RULE\'s entry beside its own font-size, and neither the link nor its block wears anything.',
    );
    $this->assertJsCondition(
      "(() => { const rows = $wear_rows; return !document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker')"
      . " && rows.length === 1 && rows[0].querySelector('.daedalus-stylish-wear-bar__variant-label')?.textContent.trim() === 'Accent link'; })()",
      20000,
      'Rule wear: the picker closed and the bar renders one row at the rule naming Accent link.',
    );
    $this->assertJsCondition(
      "$anchor_wear_js === 'rgb(51, 102, 153) 2px,rgb(51, 102, 153) 2px,rgb(51, 102, 153) 2px'",
      20000,
      'Rule wear: every anchor in the section computes the variant\'s color and letter-spacing without reload, the h3-rooted block\'s included: the rule delivers the wear to every match.',
    );
    $this->assertJsCondition(
      "(() => { const band = document.querySelector(\"[data-selectable='$container_e_key']\");"
      . " return !!band && !band.className.includes('daedalus-stylish-variant--') && !band.querySelector('[class*=\"daedalus-stylish-variant--\"]'); })()",
      20000,
      'Rule wear: no element in the band carries a variant class: a rule\'s wear is delivered inside its @scope, never stamped.',
    );
    // The Materials place: the target chip renders there too, still the
    // rule, and the catalog's cards read the same target: Accent link's
    // Apply is withheld as already worn AT THE RULE (the block wears
    // nothing), Quiet link's is ready, and its click applies at the rule,
    // replacing Accent link on the axis.
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__place');
    $library_row_js = static fn (string $label) => "Array.from(document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-library__row'))"
      . ".find((row) => row.querySelector('.daedalus-stylish-library__name')?.textContent.trim() === " . json_encode($label) . ')';
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip');"
      . " return document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__place')?.getAttribute('aria-pressed') === 'true'"
      . ' && !!chip && chip.dataset.daedalusStylishAddress === ' . json_encode($tag_rule_key)
      . " && chip.classList.contains('daedalus-stylish-chip--lit')"
      . " && chip.querySelector('.daedalus-stylish-inspector__address-label')?.textContent.trim() === 'Every link in Section'"
      . ' && !!' . $library_row_js('Accent link') . ' && !!' . $library_row_js('Quiet link') . '; })()',
      20000,
      'Rule wear: the Materials place shows the target chip lit at "Every link in Section" and lists both link variants.',
    );
    $apply_button = '#daedalus_stylish_inspector .daedalus-stylish-library-card__action--apply';
    $session->executeScript($library_row_js('Accent link') . '.click();');
    $this->assertJsCondition(
      "(() => { const apply = document.querySelector('$apply_button');"
      . " return !!apply && apply.disabled && apply.title.startsWith('The styling target already wears it'); })()",
      20000,
      'Rule wear: Accent link\'s card withholds Apply as already worn at the styling target: the card reads the rule\'s entry, not the selected block\'s.',
    );
    $session->executeScript($library_row_js('Quiet link') . '.click();');
    $this->assertJsCondition(
      "(() => { const apply = document.querySelector('$apply_button');"
      . " return document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-library-card__action--apply').length === 1"
      . " && !!apply && !apply.disabled && apply.title.startsWith('Apply this variant at the styling target'); })()",
      20000,
      'Rule wear: Quiet link\'s card offers Apply at the styling target (base:a offers at the rule\'s landing).',
    );
    $this->recordOps();
    $this->clickPanel($apply_button);
    $this->assertJsCondition(
      $one_apply_op('quiet_link'),
      20000,
      'Rule wear: the card dispatched ONE apply_variant op at container::a, the same rule the wear bar wrote at.',
    );
    $this->assertJsCondition(
      "$rule_refs_js === " . json_encode(json_encode([['quiet_link', '']]))
      . ' && ' . $map . '[' . json_encode($tag_rule_key) . "]?.default?.['font-size'] === '30px'"
      . ' && ' . $map . '[' . json_encode($link_e1_key) . '].variants === undefined',
      20000,
      'Rule wear: the card\'s apply replaced Accent link with Quiet link on the rule\'s style axis, the rule\'s own font-size and the link\'s entry untouched.',
    );
    $this->assertJsCondition(
      "$anchor_wear_js === 'rgb(153, 51, 102) 3px,rgb(153, 51, 102) 3px,rgb(153, 51, 102) 3px'"
      . " && ($anchors_js).map((anchor) => getComputedStyle(anchor).fontSize).join(',') === '24px,30px,30px'",
      20000,
      'Rule wear: every anchor now computes Quiet link\'s color and letter-spacing while the font sizes stay (the rule\'s 30px, the first link\'s own 24px): wear at a rule layers beneath the entries exactly as it does at an element.',
    );

    // ----- The member reads the rule's wear: hue, sentence, doorways. -----
    // Seam: the first link's part targeted again below the selection ->
    // its stack's ancestor tier as a group per rule position
    // (style-stack.js ancestorLayers: the worn layer
    // `ancestor:{sec}::a|quiet_link` carrying the variant's identity and
    // its carrier, the rule's own layer beneath it) -> the Basic Text
    // color row's origin (originOf's worn-ancestor branch: the variant's
    // hue through layerColor, the sentence naming the variant AND the
    // carrier in the switcher's words) -> the row's shared menu hook
    // (use-row-menu.js) offering the variant's document and "Edit at"
    // the CARRIER -> the target seated on the rule with the wear bar
    // live naming the variant. The group, the words and the menu spec
    // are node-pinned; the rendered mark on a self-rendered row, the
    // real menu there and the doorway's seat exist only here. The Text
    // color row is the row Quiet link styles (a base:a variant grants
    // no background), so it is the row that reads the wear.
    $this->switchMode('Basic');
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_e1_key'); Drupal.Daedalus.Selection.target('$block_e1_key', '.text-block-link');");
    $text_color_row = self::TEXT_COLOR_WIDGET . ' .daedalus-stylish-inspector__widget-row';
    $text_color_mark = "$text_color_row .daedalus-stylish-inspector__mark";
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); const row = document.querySelector('$text_color_row'); const mark = document.querySelector('$text_color_mark');"
      . ' if (!chip || !row || !mark || chip.dataset.daedalusStylishAddress !== ' . json_encode($link_e1_key) . ') { return false; }'
      . " return row.style.getPropertyValue('--daedalus-stylish-origin').trim() === 'hsl(33 70% 60%)'"
      . " && (mark.title ?? '').startsWith('#993366 from Quiet link at Every link in Section'); })()",
      20000,
      'Member wear: back on the link\'s own address, the Basic Text color row wears Quiet link\'s hue (the variant\'s own djb2 hue, never the ancestor teal) and its mark\'s title names the variant and the rule it arrives through in the switcher\'s words: the link reads the rule\'s worn variant as a worn ancestor layer carrying the variant\'s identity.',
    );
    $session->executeScript(
      "(() => { const row = document.querySelector('$text_color_row'); const rect = row.getBoundingClientRect();"
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: rect.left + 8, clientY: rect.top + rect.height / 2 })); })()"
    );
    $this->assertJsCondition(
      "(() => { const items = $menu_items_js; if (!document.querySelector('.daedalus-ui-menu') || items.length === 0) { return false; }"
      . " return items.some(([label, hint]) => (label ?? '').startsWith('Edit Quiet link at ') && hint === 'opens the variant')"
      . " && items.some(([label, hint]) => label === 'Edit at Every link in Section' && hint === 'writes land on that rule; the selection stays'); })()",
      20000,
      'Member wear: a right-click on the Text color row opens the provenance menu (the shared row menu hook on a self-rendered row; the browser\'s own menu no longer falls through here) offering the variant\'s document ("Edit Quiet link at …", opens the variant) and "Edit at Every link in Section", the CARRIER rule.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Edit at Every link in Section').click();"
    );
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); const bar = document.querySelector('$wear_bar'); const rows = $wear_rows;"
      . " return !document.querySelector('.daedalus-ui-menu')"
      . ' && Drupal.Daedalus.Selection.getSelected() === ' . json_encode($block_e1_key)
      . ' && Drupal.Daedalus.Selection.getTarget()?.key === ' . json_encode($container_e_key)
      . " && Drupal.Daedalus.Selection.getTarget()?.selector === 'a'"
      . ' && !!chip && chip.dataset.daedalusStylishAddress === ' . json_encode($tag_rule_key)
      . " && chip.classList.contains('daedalus-stylish-chip--lit')"
      . " && !!bar && !bar.classList.contains('daedalus-stylish-wear-bar--read-only')"
      . " && rows.length === 1 && rows[0].querySelector('.daedalus-stylish-wear-bar__variant-label')?.textContent.trim() === 'Quiet link'; })()",
      20000,
      'Member wear: the "Edit at" pick seated the CARRIER as the styling target (container::a, the selection still the first link), the chip lit there and the wear bar live at the rule with its one row naming Quiet link: a worn value\'s provenance is a door back to where it was set.',
    );

    // ----- Every choice row carries the source: the Weight doorway. -----
    // Seam: a typed Weight settling at the seated rule (the Basic
    // typography rows under the write address write at the rebased
    // candidate, controls.js keys) -> the link part re-targeted below
    // the selection -> the Weight row's stack reading the rule's own
    // layer (style-stack.js ancestorLayers, the rule's own position)
    // -> originOf's own-ancestor doorway -> ChoiceRow's shared menu
    // hook (use-row-menu.js) dispatching `ancestor` through the row's
    // OWN `source` prop -> source.target seating the rule. The Text
    // color row above carries its own hook call; the choice row is the
    // one every other basic row renders through, and its source once
    // rode inside the highlight bag only the two gap rows filled, so
    // this menu opened with an inert "Edit at" on Font, Size, Weight,
    // Width, Alignment, Position, Shadow, Corners and the motion rows:
    // the item ran and nothing seated. The words and the dispatch are
    // node-pinned (provenance-model, use-row-menu); the prop's road
    // from the widget contract through the row into the hook exists
    // only here. The Weight row is the open field under olivero (a
    // number property, the weight scale unclosed), so a typed 550, no
    // stop's value, settles as the literal.
    $weight_input = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--typography input[aria-label='Weight']";
    $weight_row_js = 'document.querySelector(' . json_encode($weight_input) . ")?.closest('.daedalus-stylish-inspector__widget-row')";
    $this->assertJsCondition(
      '(() => { const input = document.querySelector(' . json_encode($weight_input) . ');'
      . ' return Drupal.Daedalus.Selection.getTarget()?.key === ' . json_encode($container_e_key)
      . ' && !!input && !input.disabled'
      . ' && ' . $map . '[' . json_encode($tag_rule_key) . "]?.default?.['font-weight'] === undefined"
      . " && !($anchors_js).some((anchor) => getComputedStyle(anchor).fontWeight === '550'); })()",
      20000,
      'Row source: with the rule still seated, the Weight row is the live field at `a` (typography lands there), the rule holds no font-weight, and no anchor computes 550 before the write.',
    );
    $this->recordOps();
    $this->typeIntoPanel($weight_input, '550');
    $this->blurPanel($weight_input);
    $this->assertOneOp(
      $tag_rule_key,
      ['font-weight' => '550'],
      'Row source: the typed Weight settled ONE update_style at container::a carrying font-weight: 550 (a unitless number matching no stop, so the literal): the Weight row writes at the rebased candidate as the Size row did.',
    );
    $this->assertJsCondition(
      $map . '[' . json_encode($tag_rule_key) . "]?.default?.['font-weight'] === '550'"
      . " && ($anchors_js).every((anchor) => getComputedStyle(anchor).fontWeight === '550')",
      20000,
      'Row source: the rule holds font-weight: 550 and every anchor in the section computes it without reload.',
    );
    // Back below the selection: the link's own address reads the rule's
    // weight as an inherited own-ancestor value, the origin whose one
    // doorway is "Edit at" the rule in its own words.
    $session->executeScript("Drupal.Daedalus.Selection.target('$block_e1_key', '.text-block-link');");
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); const input = document.querySelector(" . json_encode($weight_input) . "); const row = $weight_row_js;"
      . ' if (!chip || !input || !row || chip.dataset.daedalusStylishAddress !== ' . json_encode($link_e1_key) . ') { return false; }'
      . " return input.value === '550'"
      . " && row.querySelector('.daedalus-stylish-inspector__mark')?.title === '550 inherited from Section (a)'; })()",
      20000,
      'Row source: back on the link\'s own address the Weight field reads the rule\'s 550 with its mark titled "550 inherited from Section (a)": the row reads the rule\'s own layer through its stack.',
    );
    $session->executeScript(
      "(() => { const row = $weight_row_js; const rect = row.getBoundingClientRect();"
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: rect.left + 8, clientY: rect.top + rect.height / 2 })); })()"
    );
    $this->assertJsCondition(
      "(() => { const items = $menu_items_js; if (!document.querySelector('.daedalus-ui-menu') || items.length === 0) { return false; }"
      . " return items.some(([label, hint]) => label === 'Edit at Section (a)' && hint === 'writes land on that rule; the selection stays'); })()",
      20000,
      'Row source: a right-click on the Weight row opens the provenance menu on the shared primitive offering "Edit at Section (a)" (originOf\'s own-ancestor doorway in the rule\'s own words) hinted that the selection stays.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Edit at Section (a)').click();"
    );
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip');"
      . " return !document.querySelector('.daedalus-ui-menu')"
      . ' && Drupal.Daedalus.Selection.getSelected() === ' . json_encode($block_e1_key)
      . ' && Drupal.Daedalus.Selection.getTarget()?.key === ' . json_encode($container_e_key)
      . " && Drupal.Daedalus.Selection.getTarget()?.selector === 'a'"
      . ' && !!chip && chip.dataset.daedalusStylishAddress === ' . json_encode($tag_rule_key)
      . " && chip.classList.contains('daedalus-stylish-chip--lit'); })()",
      20000,
      'Row source: the "Edit at" pick on the Weight row seated the rule as the styling target (container::a, the selection still the first link, the chip lit there): the choice row hands its own source to the menu hook, so the doorway every basic row offers is live, never inert.',
    );

    // ----- Typing into the Padding field previews mid-gesture. -----
    // Seam: a native input event on the Basic Padding field ->
    // DimensionField's typedPreview (a bare number in the space's unit,
    // the static label's rem)
    // -> useFieldGesture.preview handing its caller ONE object,
    // {padding: value} -> the padding row's patchFor over that object
    // (uniformCommit: the shorthand alone while no own longhand stands)
    // -> basic-controls.js route -> source.preview -> the preview
    // sheet's live slot inline on the band. The hook and the row are
    // node-tested apart (use-gesture's shape, padding-row's patch), but
    // the callback shape crosses from the hook to the component and only
    // the browser runs both: a handler still destructuring the old
    // (name, value) pair reads an undefined value and paints nothing
    // until blur. The band is selected whole (select() drops the rule
    // target with it), and under the open phase its Padding row over
    // olivero's rem space ladder is the detented field.
    $session->executeScript("Drupal.Daedalus.Selection.select('$container_e_key');");
    $padding_field = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--spacing input[aria-label='Padding']";
    $band_js = "document.querySelector(\"[data-selectable='$container_e_key']\")";
    $preview_sheet_js = "(document.querySelector('style[data-daedalus-stylish-preview]')?.textContent ?? '')";
    $this->assertJsCondition(
      '(() => { const input = document.querySelector(' . json_encode($padding_field) . ');'
      . ' return Drupal.Daedalus.Selection.getTarget() === null && !!input'
      . " && input.closest('.daedalus-stylish-dimension')?.querySelector('.daedalus-stylish-dimension__unit-label')?.textContent === 'rem'"
      . " && !input.closest('.daedalus-stylish-dimension')?.querySelector('.daedalus-stylish-dimension__unit-well')"
      . ' && ' . $map . '[' . json_encode($container_e_key) . ']?.default?.padding === undefined'
      . " && $preview_sheet_js === ''; })()",
      20000,
      'Padding preview: selecting the band whole dropped the rule target, its Basic Padding row renders as the detented field with rem as its static unit label (no select: the space names its unit), the band wears no own padding, and the preview sheet is empty before the gesture.',
    );
    $this->recordOps();
    $this->typeIntoPanel($padding_field, '2.75');
    $expected_padding_js = "(parseFloat(getComputedStyle(document.documentElement).fontSize) * 2.75) + 'px'";
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle($band_js); const sheet = $preview_sheet_js;"
      . " return style.paddingTop === $expected_padding_js"
      . ' && style.paddingRight === style.paddingTop && style.paddingBottom === style.paddingTop && style.paddingLeft === style.paddingTop'
      . " && sheet.includes('--daedalus-stylish-live-') && sheet.includes('padding')"
      . ' && document.querySelector(' . json_encode($padding_field) . ").value === '2.75'; })()",
      20000,
      'Padding preview: the input event alone painted the band, every side computing 2.75rem through the preview sheet\'s live slot while the field echoes the draft: the field\'s preview reached the row\'s patch as one object, mid-gesture, before any settle.',
    );
    $this->assertSame(
      0,
      (int) $session->evaluateScript(self::OP_LOG . '.length'),
      'Padding preview: nothing was dispatched on the input event; the paint is the staged preview and the commit waits for the settle.',
    );
    $this->blurPanel($padding_field);
    $this->assertOneOp(
      $container_e_key,
      ['padding' => '2.75rem'],
      'Padding preview: the blur settled ONE update_style at the band carrying the typed value in the space\'s unit (2.75rem matches no space stop, so it stays a literal).',
    );
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle($band_js);"
      . ' return ' . $map . '[' . json_encode($container_e_key) . "]?.default?.padding === '2.75rem'"
      . " && style.paddingTop === $expected_padding_js"
      . " && $preview_sheet_js === ''; })()",
      20000,
      'Padding preview: the settled op landed padding: 2.75rem in the map, the band computes it from the entity tag alone, and the preview sheet released its slot.',
    );

    // ----- An unset ring cell under rem steps in rem, seeded. -----
    // Seam: the canvas probe's px-resolved computed side and the
    // document's measured bases (computed-read.js, readCanvas) -> the
    // padding ring's cellFor (box-model.js sideMeasured: a supplied
    // side whose value is outside the dimension grammar reads as its
    // measurement; sideAnchor: the cell's unit through contextUnit, the
    // space's rem, its number the placeholder converted through the
    // MEASURED root, seedAnchor) -> stepKeyGesture's anchor ->
    // sideStepValue over the detented space -> applyGesture's preview
    // through the ring's patch -> settleGesture's settleSpelling and ONE
    // commit. The seed and the step are node-pinned; the placeholder's
    // road from the canvas into the cell and the unit the committed op
    // carries exist only here: before the seed rule this cell read its
    // px placeholder as its own anchor and wrote px beneath a row whose
    // select said rem, and until the conversion rule it printed that px
    // whole beside the rem select. Band D wears no own padding; born by
    // add_section it wears the Site width variant, whose
    // `padding-inline: max(var(--space-gutter), ...)` gives the left
    // cell its gutter, so the cell shows that measurement converted
    // into the space's rem (olivero's gutter token is 2rem: 2rem at the
    // theme's root, never 32) beneath a Padding row whose static label
    // says rem. Olivero's ladder (0.5, 1, 2, 4rem) holds no stop inside
    // the first tenth above 2rem, so the step stays a literal (a stop
    // there would magnetize to its reference). The step rides the
    // keyboard like the border ring's: arrows run the pointer scrub's
    // gesture and settle path.
    $band_d_key = "container:$section_d_uuid";
    $band_d_js = "document.querySelector(\"[data-selectable='$band_d_key']\")";
    $root_px_js = 'parseFloat(getComputedStyle(document.documentElement).fontSize)';
    $session->executeScript("Drupal.Daedalus.Selection.select('$band_d_key');");
    $this->clickPanel("#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--spacing button[aria-label='Edit padding per side']");
    $left_cell_xpath = '//div[contains(@class, "daedalus-stylish-boxmodel__ring--padding")]'
      . '//button[contains(@class, "daedalus-stylish-boxmodel__cell")][starts-with(@aria-label, "Padding left:")]';
    $left_cell_js = 'document.evaluate(' . json_encode($left_cell_xpath) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue';
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', $left_cell_xpath),
      'Ring seed: the pencil opened the padding ring beneath band D\'s Padding row, with a left cell.',
    );
    $this->assertJsCondition(
      "(() => { const cell = $left_cell_js; const px = getComputedStyle($band_d_js).paddingLeft;"
      . ' const input = document.querySelector(' . json_encode($padding_field) . ');'
      . " const field = input?.closest('.daedalus-stylish-dimension');"
      . " return !!cell && !!field && Math.abs(parseFloat(px) - 2 * $root_px_js) < 0.05"
      . " && cell.getAttribute('aria-label') === 'Padding left: 2rem (from Site width)'"
      . " && cell.textContent.trim() === '2rem'"
      . " && cell.classList.contains('daedalus-stylish-boxmodel__cell--variant') && cell.classList.contains('daedalus-stylish-boxmodel__cell--scrubbable')"
      . " && !cell.classList.contains('daedalus-stylish-boxmodel__cell--opaque') && !cell.classList.contains('daedalus-stylish-boxmodel__cell--computed')"
      . " && cell.title.startsWith('2rem from Site width') && cell.title.includes('Drag sideways to change')"
      . " && field.querySelector('.daedalus-stylish-dimension__unit-label')?.textContent === 'rem'"
      . " && !field.querySelector('.daedalus-stylish-dimension__unit-well')"
      . ' && ' . $map . '[' . json_encode($band_d_key) . "]?.default?.['padding-left'] === undefined"
      . ' && ' . $map . '[' . json_encode($band_d_key) . "]?.default?.padding === undefined"
      . " && $preview_sheet_js === ''; })()",
      20000,
      'Ring seed: band D\'s left cell reads the Site width variant\'s gutter (computing 2rem at the root) as 2rem in the variant\'s hue, titled "2rem from Site width", scrubbable and not opaque, beneath a Padding row whose static label says rem (no select), the band wearing no own padding and the preview sheet empty.',
    );
    // The seed the cell takes: 2rem (the 32px measurement through the
    // measured 16px root, at the ring's tenths), then one diagram grain
    // (0.1rem) up. Pinned as literals: the gutter is the skin's fixture
    // fact the precondition above asserts, so a changed gutter reads as
    // a fixture change, never a seed failure.
    $stepped = 2.1;
    $stepped_text = '2.1rem';
    $stepped_label = json_encode('Padding left: ' . $stepped_text . ' (set here)');
    $this->recordOps();
    $session->executeScript(
      "(() => { const cell = $left_cell_js; cell.focus();"
      . " cell.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true, cancelable: true })); })();"
    );
    $this->assertJsCondition(
      "(() => { const cell = $left_cell_js; const sheet = $preview_sheet_js;"
      . " return !!cell && cell.getAttribute('aria-label') === $stepped_label"
      . " && sheet.includes('--daedalus-stylish-live-')"
      . " && Math.abs(parseFloat(getComputedStyle($band_d_js).paddingLeft) - $stepped * $root_px_js) < 0.05; })()",
      20000,
      'Ring seed: ArrowUp previewed the left cell in REM, one tenth above the placeholder converted through the measured root (2.1rem: never 0.1rem from zero, never px from the placeholder), the band computing it through the live slot.',
    );
    $this->assertSame(
      0,
      (int) $session->evaluateScript(self::OP_LOG . '.length'),
      'Ring seed: nothing was dispatched while the key is held; the diagram commits on release.',
    );
    $session->executeScript(
      "($left_cell_js).dispatchEvent(new KeyboardEvent('keyup', { key: 'ArrowUp', bubbles: true, cancelable: true }));"
    );
    $this->assertOneOp(
      $band_d_key,
      ['padding-left' => $stepped_text],
      'Ring seed: the release was ONE update_style at band D carrying padding-left as the rem literal the seed and one grain produce (no own shorthand to expand, no stop to capture).',
    );
    $this->assertJsCondition(
      "(() => { const cell = $left_cell_js;"
      . ' return ' . $map . '[' . json_encode($band_d_key) . "]?.default?.['padding-left'] === " . json_encode($stepped_text)
      . " && !!cell && cell.getAttribute('aria-label') === $stepped_label"
      . " && cell.classList.contains('daedalus-stylish-boxmodel__cell--authored')"
      . " && $preview_sheet_js === ''; })()",
      20000,
      'Ring seed: the map holds the rem longhand, the cell wears it authored, and the preview sheet released its slot.',
    );

    // ----- The ring's inline cells read the Site width variant. -----
    // Seam: the stack's `padding-inline` line (the worn Site width
    // variant's `max(var(--space-gutter), calc((100% -
    // var(--layout-max-width)) / 2))`) through resolveSides'
    // logical-longhand read (box-model.js, LOGICAL_SIDES) into the
    // right cell's variant hue, its hover words and its converted
    // placeholder, then the side editor's `padding-right` write beating
    // it on the canvas while the variant stays worn. The logical read
    // and the measured-cell rule are node-pinned (box-model); the hue,
    // the measurement and the write meet only in the browser: before
    // the logical read the same cell read "computed from the theme"
    // (false: the variant) with the canvas's px as its number, and a
    // per-side write silently defeated the site width. Band D's left
    // side is now its own (the step above), so the right cell is the
    // variant's beside an authored left: an own per-side padding beats
    // the worn width variant and the ring says nothing special about it
    // (the wear bar names the override). The top cell, which no layer
    // styles, still reads computed: the logical read lands on the
    // inline pair alone.
    $right_cell_xpath = '//div[contains(@class, "daedalus-stylish-boxmodel__ring--padding")]'
      . '//button[contains(@class, "daedalus-stylish-boxmodel__cell")][starts-with(@aria-label, "Padding right:")]';
    $right_cell_js = 'document.evaluate(' . json_encode($right_cell_xpath) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue';
    $top_cell_xpath = '//div[contains(@class, "daedalus-stylish-boxmodel__ring--padding")]'
      . '//button[contains(@class, "daedalus-stylish-boxmodel__cell")][starts-with(@aria-label, "Padding top:")]';
    $top_cell_js = 'document.evaluate(' . json_encode($top_cell_xpath) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue';
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', $right_cell_xpath),
      'Site width: the padding ring is still open beneath band D\'s Padding row, with a right cell.',
    );
    $this->assertJsCondition(
      "(() => { const right = $right_cell_js; const left = $left_cell_js; const top = $top_cell_js;"
      . " const px = getComputedStyle($band_d_js).paddingRight;"
      . " return !!right && !!left && !!top && Math.abs(parseFloat(px) - 2 * $root_px_js) < 0.05"
      . " && right.getAttribute('aria-label') === 'Padding right: 2rem (from Site width)'"
      . " && right.textContent.trim() === '2rem'"
      . " && right.classList.contains('daedalus-stylish-boxmodel__cell--variant') && right.classList.contains('daedalus-stylish-boxmodel__cell--scrubbable')"
      . " && !right.classList.contains('daedalus-stylish-boxmodel__cell--opaque')"
      . " && right.style.getPropertyValue('--daedalus-stylish-origin') !== ''"
      . " && right.title.startsWith('2rem from Site width') && right.title.includes('Drag sideways to change')"
      . " && left.getAttribute('aria-label') === $stepped_label && left.classList.contains('daedalus-stylish-boxmodel__cell--authored')"
      . " && top.classList.contains('daedalus-stylish-boxmodel__cell--computed') && top.getAttribute('aria-label').endsWith('(computed from the theme)')"
      . " && !document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-boxmodel--blocked'); })()",
      20000,
      'Site width: the right cell reads the variant\'s padding-inline as 2rem "from Site width" in the variant\'s hue (the origin custom property set), scrubbable and not opaque, beside the authored left, while the top cell no layer styles still reads computed from the theme, and the ring is not blocked.',
    );
    // A REAL click selects the side: the side editor mounts as the
    // DimensionField over padding-right, EMPTY (a max() is no text to
    // edit per side) with the measurement as its placeholder converted
    // into the space's rem, beside the static rem label.
    $this->clickPanelXpath($right_cell_xpath);
    $editor_input = '#daedalus_stylish_inspector .daedalus-stylish-boxmodel__editor input[aria-label="Padding right"]';
    $this->assertJsCondition(
      "(() => { const cell = $right_cell_js; const input = document.querySelector(" . json_encode($editor_input) . ');'
      . " if (!cell || !input) { return false; } const field = input.closest('.daedalus-stylish-dimension');"
      . " return cell.getAttribute('aria-pressed') === 'true'"
      . " && input.value === '' && input.placeholder === '2'"
      . " && field.querySelector('.daedalus-stylish-dimension__unit-label')?.textContent === 'rem'"
      . " && !field.querySelector('.daedalus-stylish-dimension__unit-well'); })()",
      20000,
      'Site width: the click selected the right cell and mounted the side editor EMPTY over the variant\'s measurement as its placeholder (2, the 32px through the measured root into the space\'s rem) beside the static rem label, no select.',
    );
    $this->recordOps();
    $this->typeIntoPanel($editor_input, '3');
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle($band_d_js); const sheet = $preview_sheet_js;"
      . " return Math.abs(parseFloat(style.paddingRight) - 3 * $root_px_js) < 0.05"
      . " && Math.abs(parseFloat(style.paddingLeft) - $stepped * $root_px_js) < 0.05"
      . " && sheet.includes('--daedalus-stylish-live-') && sheet.includes('padding-right')"
      . ' && document.querySelector(' . json_encode($editor_input) . ").value === '3'; })()",
      20000,
      'Site width: typing 3 into the side editor previewed padding-right as 3rem on the band through the live slot (the physical longhand over the variant\'s padding-inline), the left side keeping its own 2.1rem, before any settle.',
    );
    $this->assertSame(
      0,
      (int) $session->evaluateScript(self::OP_LOG . '.length'),
      'Site width: nothing was dispatched on the input event; the commit waits for the settle.',
    );
    $this->blurPanel($editor_input);
    $this->assertOneOp(
      $band_d_key,
      ['padding-right' => '3rem'],
      'Site width: the blur settled ONE update_style at band D carrying padding-right: 3rem, the physical longhand in the space\'s unit (3rem matches no olivero stop, so it stays a literal), never a padding-inline of the band\'s own.',
    );
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle($band_d_js); const right = $right_cell_js;"
      . ' return ' . $map . '[' . json_encode($band_d_key) . "]?.default?.['padding-right'] === '3rem'"
      . ' && ' . $map . '[' . json_encode($band_d_key) . "]?.default?.['padding-inline'] === undefined"
      . " && Math.abs(parseFloat(style.paddingRight) - 3 * $root_px_js) < 0.05"
      . " && Math.abs(parseFloat(style.paddingLeft) - $stepped * $root_px_js) < 0.05"
      . " && !!right && right.getAttribute('aria-label') === 'Padding right: 3rem (set here)'"
      . " && right.classList.contains('daedalus-stylish-boxmodel__cell--authored')"
      . " && $preview_sheet_js === ''; })()",
      20000,
      'Site width: the map holds padding-right: 3rem and no padding-inline (the variant stays worn beneath, beaten on that side by the own longhand), the band computes 3rem on the right and its own 2.1rem on the left from the entity tag alone, the cell wears it authored, and the preview sheet released its slot.',
    );

    // ----- The value column: one edge, three surfaces. -----
    // Seam: one CSS column contract (inspector.css: every property row
    // lays a label cell beside a value cell, __widget-value, the
    // inspector root's --daedalus-stylish-value-column wide at the row's
    // end; a pick surface fills the cell, a slide surface's cluster
    // fills it) met by three unrelated surfaces on a text block's Basic
    // panel: the Font choice well (choice-row.js), the Size dimension
    // field's cluster (dimension-field.js) and the Alignment segments
    // (segments.js). That the well's left edge equals the cluster's left
    // edge and the segments' right edge equals the cluster's right edge
    // is a fact only a layout engine can confirm, read in ONE
    // evaluateScript so every rect comes from the same layout pass. Back
    // on the open phase's plain text block: a Font well, a free Size
    // field and the Alignment segments on one panel.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_b_key');");
    $this->assertNotNull(
      $assert_session->waitForElement('css', self::FONT_WELL),
      'Value column: the text block\'s Basic panel renders the Font well.',
    );
    $this->assertNotNull(
      $assert_session->waitForElement('css', self::SIZE_INPUT),
      'Value column: the text block\'s Basic panel renders the free Size field.',
    );
    $column = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const rows = [...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-inspector__widget-row')];
        const rowOf = (axis) => rows.find((row) => (row.querySelector('.daedalus-stylish-inspector__widget-axis, .daedalus-stylish-dimension__label')?.textContent.trim() ?? '') === axis) ?? null;
        const edges = (element) => {
          if (!element) { return null; }
          const rect = element.getBoundingClientRect();
          return { left: rect.left, right: rect.right, width: rect.width };
        };
        const font = rowOf('Font');
        const size = rowOf('Size');
        const alignment = rowOf('Alignment');
        return JSON.stringify({
          fontWell: edges(font?.querySelector('.daedalus-stylish-choice-well')),
          fontCell: edges(font?.querySelector('.daedalus-stylish-inspector__widget-value')),
          sizeControls: edges(size?.querySelector('.daedalus-stylish-dimension__controls')),
          sizeCell: edges(size?.querySelector('.daedalus-stylish-inspector__widget-value')),
          segments: edges(alignment?.querySelector('.daedalus-stylish-inspector__segments')),
          alignmentCell: edges(alignment?.querySelector('.daedalus-stylish-inspector__widget-value')),
          row: edges(size),
        });
      })()
      JS), TRUE);
    foreach (['fontWell', 'fontCell', 'sizeControls', 'sizeCell', 'segments', 'alignmentCell', 'row'] as $part) {
      $this->assertNotNull($column[$part], "Value column: the panel renders the $part to measure (the Font row's well, the Size row's cluster and the Alignment row's segments, each in its row's value cell).");
    }
    $this->assertEqualsWithDelta(
      $column['sizeControls']['left'],
      $column['fontWell']['left'],
      1.0,
      'Value column: the Font well\'s left edge is the Size cluster\'s left edge within a pixel (a pick surface fills the value cell a slide surface\'s cluster also fills). Font well left ' . $column['fontWell']['left'] . ', Size cluster left ' . $column['sizeControls']['left'] . '.',
    );
    $this->assertEqualsWithDelta(
      $column['sizeControls']['right'],
      $column['segments']['right'],
      1.0,
      'Value column: the Alignment segments\' right edge is the Size cluster\'s right edge within a pixel (both end at the row\'s end). Segments right ' . $column['segments']['right'] . ', Size cluster right ' . $column['sizeControls']['right'] . '.',
    );
    $this->assertEqualsWithDelta(
      $column['sizeCell']['left'],
      $column['fontCell']['left'],
      1.0,
      'Value column: the Font and Size rows\' value cells start on one edge. Font cell left ' . $column['fontCell']['left'] . ', Size cell left ' . $column['sizeCell']['left'] . '.',
    );
    $this->assertEqualsWithDelta(
      $column['sizeCell']['left'],
      $column['alignmentCell']['left'],
      1.0,
      'Value column: the Alignment row\'s value cell starts on the same edge. Alignment cell left ' . $column['alignmentCell']['left'] . ', Size cell left ' . $column['sizeCell']['left'] . '.',
    );
    $this->assertEqualsWithDelta(
      $column['sizeCell']['left'],
      $column['sizeControls']['left'],
      1.0,
      'Value column: the Size cluster starts on its cell\'s left edge (the cluster fills the value cell, so the cell\'s edge IS the column edge every surface meets). Cell left ' . $column['sizeCell']['left'] . ', cluster left ' . $column['sizeControls']['left'] . '.',
    );
    $this->assertLessThan(
      $column['sizeCell']['left'],
      $column['row']['left'] + $column['row']['width'] / 3,
      'Value column: the column edge sits past the row\'s first third (the label cell is the row\'s free width; the value cell is the 8.5rem cluster width at the rail\'s default width). Row left ' . $column['row']['left'] . ', width ' . $column['row']['width'] . ', cell left ' . $column['sizeCell']['left'] . '.',
    );
  }

}
