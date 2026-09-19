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
 * - The resting value at a pose reaches the well (seam: the row's
 *   resting value at a pose is the delivering layer's, reaching the row
 *   through the one derivation, choice-row-model.js restingValue beneath
 *   originFor's ghost, the same read that hues the mark): basic mode
 *   reads a row's VALUE at the exact compound, so posed at Medium the
 *   Size row holds nothing of its own while its Base commit still
 *   delivers there; the well rests unset with the Base value as its
 *   ghost, faced as the display value, and the row's mark wears the
 *   element layer's hue. The derivation (skin, declared, own-at-Base,
 *   variant, set, nothing) is node-pinned; the placeholder wire from the
 *   origin read into the rendered well's ghost text only exists in a
 *   browser, and no shipped fixture skin sets a basic-owned property on
 *   a block scope, so the pose is the wire's proof.
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
 *   and the binding Kernel-pinned; the declared grid laying the gap out
 *   on the edit canvas exists only here.
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
   * - Resting value at a pose (strict): the width chip's real menu poses
   *   the canvas at Medium; the Size well wears the unset face with the
   *   Base commit as its ghost text (1.5rem, never var()), titled "Unset;
   *   1.5rem applies", the row's --daedalus-stylish-origin in the element layer's
   *   hue; the row menu's header names the value and Base, and its "Go to
   *   Base" doorway drives the canvas back, the well lit with 1.5rem
   *   again before the next step.
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
   *   the section headers Text, Surface, Layout, Motion, in roster
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
   *   reload, and B) as its child boxes and computes the declared grid;
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
   *   wears 1 with rem on its unit select and the blessed chrome.
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

    $this->enterEditModeViaCookies($node->toUrl(), 'selector');
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
    // per-control header: the baseline binds typography and text
    // color (Text), background, corners and shadow (Surface),
    // position, spacing and width (Layout) and the motion trigger
    // (Motion). The grouping is node-tested; only the browser proves
    // the payload's roster reaches the rendered panel.
    $this->assertJsCondition(
      "(() => { const titles = [...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-inspector__control-section-title')]"
      . " .map((title) => title.textContent.trim());"
      . " return JSON.stringify(titles) === JSON.stringify(['Text', 'Surface', 'Layout', 'Motion']); })()",
      20000,
      'The Basic tab reads exactly the section headers Text, Surface, Layout, Motion for the block.',
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

    // ----- The resting value at a pose reaches the well. -----
    // Seam: the row's resting value at a pose is the delivering layer's,
    // reaching the row through the one derivation (choice-row-model.js
    // restingValue beneath originFor's `ghost`, the same read that hues
    // the mark and writes the origin sentence). Basic mode reads a row's
    // VALUE at the exact compound (controls.js: entry[condition]), so
    // posed at Medium the Size row holds nothing of its own while the
    // Base commit of var(--text-title-2) still delivers there: the well
    // rests unset with the Base value as its ghost, faced (1.5rem, never
    // the var() spelling), titled with the resting story, and the row's
    // mark wears the element layer's hue. The skin branch of the same
    // derivation is node-pinned (no shipped fixture skin sets a
    // basic-owned property on a block scope), so the pose is the wire's
    // browser proof. Posed through the width chip's real menu, the idiom
    // AdvancedSectionsBehaviorTest proves; driven back through the row
    // menu's "Go to Base" doorway before the next step.
    $size_row_js = "[...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--typography .daedalus-stylish-inspector__widget-row')]"
      . ".find((row) => row.querySelector('.daedalus-stylish-inspector__widget-axis')?.textContent.trim() === 'Size')";
    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-chip-row")]/button[contains(@class, "daedalus-stylish-chip--width")]');
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'Resting value at a pose: the width chip opened its menu on the shared primitive.',
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
      'Resting value at a pose: the width chip posed the canvas at Medium (the document wears the width stamp, the chip reads Medium, lit).',
    );
    // The well: unset at the Medium compound, the Base value resting
    // beneath it as the ghost text, faced through ghostFace at the pose.
    $this->assertJsCondition(
      "(() => { const well = document.querySelector(" . json_encode($size_well) . ');'
      . " return !!well && well.classList.contains('daedalus-stylish-choice-well--unset')"
      . " && !well.classList.contains('daedalus-stylish-choice-well--stop')"
      . " && well.querySelector('.daedalus-stylish-choice-well__text--ghost')?.textContent.trim() === '1.5rem'"
      . " && well.getAttribute('title') === 'Unset; 1.5rem applies'; })()",
      20000,
      'Resting value at a pose: posed at Medium, the Size well wears the unset face (nothing set at the Medium compound) with the Base commit as its ghost text 1.5rem (the display face, never var(--text-title-2)), titled "Unset; 1.5rem applies".',
    );
    // The mark: the same read as the ghost, so the row's hue is the
    // element layer's (the own layer delivering from Base).
    $this->assertJsCondition(
      "($size_row_js)?.style.getPropertyValue('--daedalus-stylish-origin').trim() === 'hsl(213 90% 66%)'",
      20000,
      'Resting value at a pose: the Size row\'s --daedalus-stylish-origin is the element layer\'s hue (layers.js layerColor(ELEMENT_LAYER)): the ghost and the mark come from the one read of the Base commit.',
    );
    // The origin in words and its drive doorway: the row menu's header
    // tells the story the ghost wears, and "Go to Base" drives the
    // canvas back to the delivering condition, the well lit again.
    $session->executeScript(
      "(() => { const row = $size_row_js; const rect = row.getBoundingClientRect();"
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true,"
      . ' clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2 })); })();'
    );
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')",
      20000,
      'Resting value at a pose: the right-click on the Size row opened the row menu with its origin header on the shared primitive.',
    );
    $size_header = (string) $session->evaluateScript("document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header').textContent");
    $this->assertStringContainsString('1.5rem', $size_header, 'Resting value at a pose: the row menu header names the resting value by its display face. Header: ' . $size_header);
    $this->assertStringContainsString('Base', $size_header, 'Resting value at a pose: the row menu header names the delivering condition (Base), not the posed one. Header: ' . $size_header);
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
      'Resting value at a pose: "Go to Base" drove the canvas back (the width stamp is gone, the chip reads Base, unlit, the row menu closed).',
    );
    $this->assertJsCondition(
      "(() => { const well = document.querySelector(" . json_encode($size_well) . ');'
      . " return !!well && well.classList.contains('daedalus-stylish-choice-well--stop')"
      . " && !well.querySelector('.daedalus-stylish-choice-well__text--ghost')"
      . " && well.querySelector('.daedalus-stylish-choice-well__text')?.textContent.trim() === '1.5rem'; })()",
      20000,
      'Resting value at a pose: back at Base the Size well wears the committed stop\'s face, 1.5rem lit with no ghost: the value is the row\'s own again for the steps that follow.',
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
      . " && input.closest('.daedalus-stylish-dimension').querySelector('.daedalus-stylish-dimension__unit').value === 'rem'"
      . " && input.closest('.daedalus-stylish-dimension').classList.contains('daedalus-stylish-dimension--blessed'); })()",
      20000,
      'The menu closed on the pick and the face wears the stop\'s display value (the number in the input, the unit on the select) with the blessed chrome.',
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
      'The typed text echoed into the controlled input as its number face (the unit rides the select).',
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
    $well = "$widget .daedalus-stylish-inspector__widget-row > .daedalus-stylish-swatch-well";
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
      'JSON.stringify(' . $map . '[' . json_encode($container_key) . "]?.variants ?? []).includes('row')",
      20000,
      'The Columns flip committed apply_variant: the container wears the shipped row variant on container_layout.',
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
      . " && input.value === '1' && input.closest('.daedalus-stylish-dimension').querySelector('.daedalus-stylish-dimension__unit').value === 'px'"
      . " && !document.querySelector('.daedalus-ui-popover .daedalus-stylish-swatch-popover'); })()",
      20000,
      'The popover closed and the row wears the completed stroke: the color and style wells light their stops and the width field reads 1px.',
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
      . " && getComputedStyle(root).display === 'grid'; })()",
      20000,
      'The edit canvas renders the layout root (daedalus_layout\'s #layout-builder wearing .daedalus-layout-layout) inside the entity root, holding exactly the two bands as its child boxes and laid out as the declared grid.',
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
      'The layout root computes the md token\'s row-gap and the two bands sit that much further apart on the canvas without reload: the declared grid laid the page gap out.',
    );
    $this->assertJsCondition(
      '(() => { const input = document.querySelector(' . json_encode($gap_input) . ');'
      . " return !document.querySelector('.daedalus-ui-menu') && !!input && input.value === '1'"
      . " && input.closest('.daedalus-stylish-dimension').querySelector('.daedalus-stylish-dimension__unit').value === 'rem'"
      . " && input.closest('.daedalus-stylish-dimension').classList.contains('daedalus-stylish-dimension--blessed'); })()",
      20000,
      'The menu closed on the pick and the Gap field wears the stop\'s display value (1 on the input, rem on the unit select) with the blessed chrome.',
    );
  }

}
