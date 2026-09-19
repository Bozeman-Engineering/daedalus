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
 * Drives the advanced-mode property-group sections on a real page.
 *
 * The capability under test is the inspector's Advanced surface: one
 * native <details> per declared property group, its `open` prop
 * CONTROLLED by the section-open model (section-open.js: the auto
 * arrival signals, the user's per-anchor marks folded into the prop, the
 * anchor reset). The model is node-tested exhaustively
 * (section-open.test.mjs owns the rule: auto only, mark over auto both
 * ways, stale-anchor marks ignored, reset yields auto). Everything here
 * is wiring that only exists where Preact's controlled push of
 * `details.open`, the summary's click handler, the op spine and the
 * selection facade meet in a browser:
 * - Arrival state reaches the DOM (seam: the three auto signals crossing
 *   the prop into `details.open` on mount): on an element carrying one
 *   committed value in Typography, flipping to Advanced renders
 *   Typography's details `[open]` (the values signal), Spacing's `[open]`
 *   too with no value of its own (the defaults-tier signal: the skin
 *   zeroes `kind:block` margins, so the Margin top and Margin bottom
 *   rows wear the skin's value, faced as the ghost on their unset
 *   option), and Border's not (no value, no pending line, no skin
 *   value). Node tests pin that autoOpen answers true/false per signal;
 *   only a browser proves each answer lands as the native attribute,
 *   and the skin signal is only reachable where a real skin delivers
 *   a defaults-tier value to a mounted row.
 * - A hand-opened section survives its last value's deletion (seam: the
 *   summary click recording the mark, then the prop flipping true and
 *   back to false while the mark keeps the DOM open). This is the bug
 *   the model exists for: before it, the delete pushed open=false under
 *   the user. The click-not-toggle decision (the toggle event fires for
 *   programmatic opens too) and Preact's push-on-change are both
 *   invisible to a node test. The leg runs on Border, the group that
 *   ARRIVES closed here (the flip true -> false is only observable on
 *   a section whose auto state is false without the value), and the
 *   value it sets rides the Border radius row's closed select: the
 *   suite binds border-radius to the `radius` scale with the allowed
 *   literal 0 and the skin mints sm, md, lg and pill, so under strict
 *   law the row is DimensionField's ClosedField over exactly those.
 * - One value space per property (seam: the suite's site-wide
 *   constraint_defaults with the active package's stops merged over
 *   them -> the payload's constraints -> valueSpaceFor ->
 *   DimensionField's ClosedField): the vocabulary is the SKIN's, not
 *   the widget's built-in table. Padding is bound site-wide by the
 *   suite (daedalus_stylish.page_builder.yml binds it to the `space` scale with
 *   the allowed literal 0, a promise every shipped skin keeps), and the
 *   guide_example package layers its stop curation over that block
 *   (stops 2, 4, 6, 8, 12, 16 of its space ladder), so under the strict
 *   reference system's law the space is closed and the advanced row is
 *   the closed render, a select whose options are exactly those stops
 *   (value the reference spelling, face the resolved value) and whose
 *   pick commits the reference, whose unset pick commits the null; node
 *   tests pin the space, only a browser proves the advanced mount
 *   reaches it.
 * - The anchor reset pushes the DOM (seam: a selection change turning
 *   the marks stale, the prop recomputing to the new element's auto
 *   state, and Preact writing that change to the still-mounted
 *   details): selecting a second, value-less element closes the
 *   hand-opened Border again, while Spacing arrives open on it by the
 *   skin signal and Typography closed. Node tests pin marksFor's stale
 *   read; the push through the mounted DOM is browser-only.
 * - A row's provenance is read at the DELIVERING condition (seam: the
 *   whole supplier table crossing from the Inspector's variant-scope
 *   reads into rowOrigin, then the origin's hue landing as the row's
 *   `--daedalus-stylish-origin` and its story as the shared menu's header): a
 *   variant minted and worn through the op spine sets border-radius at
 *   Base (Border, closed on the second element, opens by the values
 *   signal alone: the resolved entry carries the worn value); with the
 *   canvas posed at Medium through the width chip's real menu, the
 *   Border radius row carries the variant layer's hue (layers.js
 *   layerColor over variantLayerId, spelled here as the oracle) while
 *   no retired layer dot exists anywhere, a right-click on the row
 *   opens the row menu whose header names the variant AND Base, and
 *   its "Go to Base" doorway drives the canvas back (the chip row's
 *   width face returns to Base, the document's width stamp clears).
 *   provenance-model.test.mjs pins the posture, color and sentence;
 *   only a browser proves the tables reach the row unsliced, the hue
 *   reaches the DOM, the menu mounts on the primitive, and the doorway
 *   reaches the canvas vector.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site as
 * daedalus_editor, the universal block kind declaring spacing,
 * typography and border among its property groups) switched to the
 * strict reference design system by setUp: the recipe's olivero system
 * ships open and the closed-row premises need law, so the scenario
 * activates daedalus_stylish_guide_example's guide_example system (strict as
 * shipped, its own skin and stop curation) rather than forcing a mode
 * onto a guide written to be open. One fixture, one scenario: the site install
 * dominates the run cost, so all behavior lives in the single test
 * method. Future advanced-mode seams append here.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class AdvancedSectionsBehaviorTest extends WebDriverTestBase {

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
   * The Padding row's closed dimension select inside the advanced
   * Spacing section.
   *
   * The suite binds padding to the `space` scale site-wide
   * (daedalus_stylish.page_builder.yml constraint_defaults), so under the strict
   * reference system's law the row's value space is CLOSED and
   * DimensionField renders its ClosedField: a native select of the stop
   * values carrying the row's label as its aria-label (no free input,
   * no stop picker chevron).
   */
  private const PADDING_SELECT = "#daedalus_stylish_inspector details.daedalus-stylish-inspector__section select[aria-label='Padding']";

  /**
   * The closed Padding select's stop options, `[value, face]` pairs in
   * order (a list, not a map: the literal 0 as a map key would cast to
   * the integer and no longer equal the option's string value).
   *
   * The skin's vocabulary, not the widget's built-in table: the
   * guide_example package curates the suite's padding binding to the
   * space ladder's 2, 4, 6, 8, 12 and 16 (each option's value the
   * token's reference spelling, the commit the pick makes; its face the
   * resolved value), and the suite's block allows the literal 0 beside
   * them (the zero no token mints, its own spelling as value and face).
   * The unset option (value '') leads the list and is not pinned here:
   * its face is the ghost when a value is inherited, and the ghost is
   * not this test's claim.
   */
  private const PADDING_STOPS = [
    ['var(--space-2)', '0.5rem'],
    ['var(--space-4)', '1rem'],
    ['var(--space-6)', '1.5rem'],
    ['var(--space-8)', '2rem'],
    ['var(--space-12)', '3rem'],
    ['var(--space-16)', '4rem'],
    ['0', '0'],
  ];

  /**
   * The Border radius row's closed select in the advanced Border section.
   *
   * The suite binds border-radius to the `radius` scale site-wide with
   * the allowed literal 0 (no package curation: guide_example's site
   * section carries no radius delta), so under the strict reference
   * system's law the row is the same ClosedField shape as Padding's.
   * Border is the section-open legs' group: it arrives closed on a
   * value-less block (the skin delivers nothing to any of its rows),
   * which the hand-open, delete and reset claims need.
   */
  private const RADIUS_SELECT = "#daedalus_stylish_inspector details.daedalus-stylish-inspector__section select[aria-label='Border radius']";

  /**
   * The closed Border radius select's stop options, `[value, face]` pairs.
   *
   * In order: the skin's four minted radius tokens as their references
   * faced by value, then the suite binding's allowed 0.
   */
  private const RADIUS_STOPS = [
    ['var(--radius-sm)', '0.25rem'],
    ['var(--radius-md)', '0.5rem'],
    ['var(--radius-lg)', '1rem'],
    ['var(--radius-pill)', '9999px'],
    ['0', '0'],
  ];

  /**
   * A JS expression for a closed select's row: its property element.
   *
   * The leading slot's hue lands on it as `--daedalus-stylish-origin`, and its
   * right-click opens the row menu.
   *
   * @param string $select
   *   The row's select, one of the *_SELECT constants.
   */
  private function rowJs(string $select): string {
    return 'document.querySelector(' . json_encode($select) . ").closest('.daedalus-stylish-inspector__property')";
  }

  /**
   * A worn variant layer's stable hue: layers.js layerColor(variantLayerId()).
   *
   * The identity system's one hue rule (djb2 over the layer id to a
   * hue, shifted off the element blue's band), spelled in PHP so the
   * row's `--daedalus-stylish-origin` is asserted against the FUNCTION every
   * identity surface shares, not against a color copied from one run.
   *
   * @param string $reference
   *   The variant's record id.
   *
   * @return string
   *   The CSS color layerColor() returns for that variant's layer.
   */
  private static function layerColor(string $reference): string {
    $hash = 5381;
    foreach (str_split("variant:$reference") as $char) {
      $hash = (($hash << 5) + $hash + ord($char)) & 0xFFFFFFFF;
    }
    $hue = $hash % 360;
    if (abs($hue - 213) < 24) {
      $hue = ($hue + 48) % 360;
    }
    return "hsl($hue 70% 60%)";
  }

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
                  value: '<p>Advanced sections target text</p>',
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
   * Commits one style property on a key through the op spine.
   *
   * The arrival-state step needs an element that ARRIVES with a value,
   * so the value is written the way any non-panel consumer writes it (an
   * update_style op through the blueprint facade), not through the
   * widget whose section state is under test.
   *
   * @param string $key
   *   The selectable key.
   * @param string $property
   *   The CSS property.
   * @param string $value
   *   The value.
   * @param string $sentinel
   *   A per-call window variable name for the op error.
   */
  private function commitStyle(string $key, string $property, string $value, string $sentinel): void {
    $session = $this->getSession();
    $properties = json_encode([$property => $value]);
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'update_style', key: '$key', properties: $properties },
          ],
        })).catch((error) => {
          window.$sentinel = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.$sentinel !== undefined || " . $this->mapJs($key) . '?.default?.[' . json_encode($property) . '] === ' . json_encode($value),
      20000,
      'The style commit resolved.',
    );
    $op_error = $session->evaluateScript("window.$sentinel ?? null");
    $this->assertNull($op_error, 'Style commit failed: ' . ($op_error ?? ''));
  }

  /**
   * A JS expression for a key's entry in the blueprint mirror's style map.
   *
   * The same authority the canvas re-renders from, and the map every
   * commit assertion reads.
   */
  private function mapJs(string $key): string {
    return '(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles ?? {})[' . json_encode($key) . ']';
  }

  /**
   * A JS expression for an advanced section's <details> by its label.
   *
   * Sections carry no id in the DOM; the summary's text IS the group's
   * label, the only name an advanced-mode user reads. Evaluates to the
   * details element, or undefined when no such section rendered.
   */
  private function sectionJs(string $label): string {
    return "Array.from(document.querySelectorAll('#daedalus_stylish_inspector details.daedalus-stylish-inspector__section'))"
      . ".find((details) => details.querySelector(':scope > summary')?.textContent.trim() === " . json_encode($label) . ')';
  }

  /**
   * Asserts an advanced section rendered and holds the given open state.
   */
  private function assertSectionOpen(string $label, bool $open, string $message): void {
    $this->assertJsCondition(
      '(' . $this->sectionJs($label) . ')?.open === ' . ($open ? 'true' : 'false'),
      20000,
      $message,
    );
  }

  /**
   * Clicks a panel element found by XPath, scrolled into the viewport.
   *
   * A REAL click (not a dispatched event): the summary's native toggle
   * and Preact's click handler both ride the browser's own gesture.
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
   * Picks an option in a panel's closed select by its face, scrolled
   * into the viewport.
   *
   * The driver's own selectOption: a real click on the option (the
   * browser selects it and fires 'change' on the select; a closed field
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
   * The advanced sections scenario: arrival, the surviving mark, the reset.
   *
   * The scripted scenario, in order:
   * - Fixture: a page node, Edit Mode via the cookie path, one section
   *   with one formatted_text block (A) placed through the op spine, a
   *   Typography value (color, as the skin's accent token: guide_example
   *   binds color to its color scale, so under its law a literal would
   *   be refused) committed on A through an update_style op, the block
   *   selected, the inspector opened in its Basic default and flipped
   *   to Advanced through the mode segment's real button.
   * - Arrival state: A's Typography details is open (it carries the
   *   committed color), its Spacing details is open too (the skin's
   *   `kind:block` margin zeroes reach the Margin top and Margin bottom
   *   rows as their ghost, the defaults-tier signal) and its Border
   *   details is closed (no value, no pending line, no skin value): the
   *   three auto signals landed as the native attribute.
   * - The value space: in the open Spacing section the Padding row is
   *   the closed select over the skin's space scale (its options exactly
   *   the package's curated stops plus the suite's allowed 0, no free
   *   input or chevron); picking the 4 stop commits var(--space-4) to
   *   the map and picking the unset option unsets it again, Spacing
   *   open throughout (its skin signal holds either way).
   * - The surviving mark: a real click on the Border summary opens it
   *   (the mark is recorded); the Border radius row is the closed select
   *   over the skin's radius scale (its options exactly the four minted
   *   tokens plus the suite's allowed 0); picking the md stop commits
   *   var(--radius-md) to the map (the prop flips to true under the
   *   already-open DOM); picking the unset option unsets it from the
   *   map, and Border is STILL open: the user's word beat the prop's
   *   flip back to false.
   * - The reset: a second formatted_text block (B) is placed with no
   *   values and selected; Border recomputes closed (A's mark is stale
   *   under B's anchor), Spacing arrives open by the skin signal and
   *   Typography closed (B's own arrival state, nothing carried over).
   * - The origin at the delivering condition: a variant "Grip origin"
   *   (border-radius var(--radius-md) at Base: a minted radius token,
   *   the only legal spelling on the bound property under strict law)
   *   is minted from B and worn by B through the op spine; Border opens
   *   by the values signal alone (the resolved entry carries the worn
   *   value); the width chip's real menu poses the canvas at Medium; the
   *   Border radius row's `--daedalus-stylish-origin` is the variant layer's hue
   *   and no layer dot exists; a right-click on the row opens the row
   *   menu with a header naming the variant and Base; its "Go to Base"
   *   doorway returns the chip row's width face to Base and clears the
   *   document's width stamp.
   */
  public function testAdvancedSectionsScenario(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // The scenario runs under the law of the system setUp activated;
    // assert the posture and the skin so a loosened package (or a
    // fixture left on olivero) cannot silently change which rows render.
    $this->assertSame(
      'strict',
      \Drupal::service('daedalus_stylish.style_guide')->enforcement(),
      'The activated guide_example design system ships strict enforcement: the Padding row\'s closed space is the strict reference guide\'s own law.',
    );
    $this->assertSame(
      'guide_example',
      \Drupal::service('daedalus_stylish.skin_manager')->activeSkinName(),
      'The guide_example skin is active: its space ladder is the vocabulary the closed Padding select offers.',
    );

    // A real Layout Builder page: the recipe enables styling for the
    // page bundle, so its daedalus_stylish_styles field hosts the style map.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Advanced sections page',
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
    $this->placeTextBlock($uuid_service->generate(), $block_a_uuid, '__placementErrorA');
    $block_a_key = "block:$block_a_uuid";

    // A arrives with ONE value, in Typography: the arrival-state step
    // needs a group with a value and a group without one on the same
    // element. Written through the op spine, not the panel, and spelled
    // as the skin's accent token: guide_example binds color to its color
    // scale, so under its strict law a literal hex would be refused.
    $this->commitStyle($block_a_key, 'color', 'var(--color-accent)', '__styleErrorA');

    $this->openInspector();

    // Select the block: the facade is the sanctioned programmatic face
    // of a canvas click, and the panel follows the committed selection.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_a_key');");

    // A fresh session starts in Basic; the flip is a real click on the
    // mode segment's Advanced button, so the sections mount the way a
    // user reaches them.
    $this->assertJsCondition(
      "(() => { const active = document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]');"
      . " return !!active && active.textContent.trim() === 'Basic'; })()",
      20000,
      'The inspector starts in Basic mode.',
    );
    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-inspector__ui-mode")]/button[normalize-space() = "Advanced"]');
    $this->assertJsCondition(
      "(() => { const active = document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]');"
      . " return !!active && active.textContent.trim() === 'Advanced'; })()",
      20000,
      'The Advanced button flipped the inspector to Advanced mode.',
    );

    // ----- Arrival state reaches the DOM. -----
    // The three auto signals through the controlled prop: the group
    // carrying the committed color renders open (values), the group
    // whose rows wear the skin's defaults renders open (defaults tier:
    // guide_example's skin zeroes kind:block margins, so Margin top and
    // Margin bottom face that 0 as the ghost on their unset option), and
    // the group with neither renders collapsed.
    $this->assertSectionOpen('Typography', TRUE, 'Typography arrives open: it carries the committed color (the values signal to details.open).');
    $this->assertSectionOpen('Spacing', TRUE, 'Spacing arrives open with no value of its own: the skin\'s kind:block margin zeroes reach the Margin rows (the defaults-tier signal to details.open).');
    $this->assertJsCondition(
      "(() => { const select = document.querySelector(\"#daedalus_stylish_inspector details.daedalus-stylish-inspector__section select[aria-label='Margin top']\");"
      . " return !!select && select.value === '' && select.options[0].textContent.trim() === '- 0 -'; })()",
      20000,
      'The Margin top row is unset and its unset option faces the skin\'s delivered 0 as the ghost: the defaults-tier value the section opened on.',
    );
    $this->assertSectionOpen('Border', FALSE, 'Border arrives closed: no value, no pending line, no skin value.');

    // ----- One value space per property. -----
    // The Padding row inside the open Spacing section: the closed
    // dimension select (the skin's space scale is law here).
    $padding_select = self::PADDING_SELECT;
    $this->assertNotNull(
      $assert_session->waitForElement('css', $padding_select),
      'The open Spacing section renders the Padding row as the closed dimension select (padding bound to the skin\'s space scale under strict law).',
    );
    // One value space per property, both modes: the advanced row mounted
    // over the space the suite binds padding to (constraint_defaults:
    // scale space, allowed 0) with guide_example's stop curation merged
    // over it (stops 2/4/6/8/12/16), closed under the strict reference
    // system's law, so the field is the select and nothing else: no
    // free input, no stop picker chevron, no native list rides the row.
    $padding_row = $this->rowJs($padding_select);
    $this->assertJsCondition(
      "(() => { const row = $padding_row;"
      . " return !!row && !row.querySelector('input') && !row.querySelector('.daedalus-stylish-stop-menu__chevron') && !row.querySelector('datalist'); })()",
      20000,
      'The closed Padding row carries no free input, no stop picker chevron and no native list: the select is the whole field.',
    );
    // The options are exactly the skin's vocabulary: the unset option
    // first, then each curated stop as `[reference, face]` and the
    // allowed literal 0 last. The reference is what a pick commits; the
    // face is the resolved value the user reads.
    $options = $session->evaluateScript(
      'Array.from(document.querySelector(' . json_encode($padding_select) . ').options)'
      . '.map((option) => [option.value, option.textContent.trim()])'
    );
    $this->assertSame('', $options[0][0] ?? NULL, 'The closed Padding select leads with the unset option (value \'\').');
    $this->assertSame(
      self::PADDING_STOPS,
      array_slice($options, 1),
      'The closed Padding select offers exactly the guide_example package\'s curated space stops (2, 4, 6, 8, 12, 16 as their var() references faced by value) plus the suite binding\'s allowed literal 0: the vocabulary is the skin\'s through the site binding, the built-in table displaced.',
    );
    // The 4 stop, picked by the face its option wears (1rem).
    $this->pickPanelOption($padding_select, array_column(self::PADDING_STOPS, 1, 0)['var(--space-4)']);
    $map_a = $this->mapJs($block_a_key);
    $this->assertJsCondition(
      "$map_a?.default?.padding === 'var(--space-4)'",
      20000,
      'The 4 pick committed padding as the stop\'s reference spelling, var(--space-4), to the map (widget, op, mirror): the section now has a value.',
    );
    $this->assertJsCondition(
      'document.querySelector(' . json_encode($padding_select) . ").value === 'var(--space-4)'",
      20000,
      'The controlled Padding select reads the committed reference.',
    );
    $this->assertSectionOpen('Spacing', TRUE, 'Spacing stays open with its padding set (the values signal joins the skin signal; prop and DOM agree).');

    // Unset it: the select's unset option settling commits the null
    // (ClosedField's change with the empty value, the same settle an
    // emptied input makes). The unset option is the first, picked by
    // whatever face it wears (the ghost, when a value is inherited).
    $unset_face = (string) $session->evaluateScript(
      'document.querySelector(' . json_encode($padding_select) . ').options[0].textContent.trim()'
    );
    $this->pickPanelOption($padding_select, $unset_face);
    $this->assertJsCondition(
      "($map_a?.default?.padding ?? null) === null",
      20000,
      'The unset pick unset padding from the map: the section is value-less again.',
    );
    $this->assertJsCondition(
      'document.querySelector(' . json_encode($padding_select) . ").value === ''",
      20000,
      'The controlled Padding select reads unset again.',
    );
    $this->assertSectionOpen('Spacing', TRUE, 'Spacing is still open after its padding was unset: the skin signal holds without any value of its own.');

    // ----- A hand-opened section survives its last value's deletion. -----
    // On Border, the group whose auto state is false: the flip the mark
    // must beat only exists where no other signal keeps the prop true.
    // A REAL click on the summary: the native toggle opens the details
    // and Preact's click handler records the mark (details.open is the
    // pre-toggle state at click time, so the mark is its negation).
    $this->clickPanelXpath('//details[contains(@class, "daedalus-stylish-inspector__section")]/summary[normalize-space() = "Border"]');
    $this->assertSectionOpen('Border', TRUE, 'The summary click opened Border by hand.');

    // Set a value through the section's own widget: the Border radius
    // row's closed dimension select (the suite binds border-radius to
    // the skin's radius scale, law under strict).
    $radius_select = self::RADIUS_SELECT;
    $this->assertNotNull(
      $assert_session->waitForElement('css', $radius_select),
      'The hand-opened Border section renders the Border radius row as the closed dimension select (border-radius bound to the skin\'s radius scale under strict law).',
    );
    $radius_options = $session->evaluateScript(
      'Array.from(document.querySelector(' . json_encode($radius_select) . ').options)'
      . '.map((option) => [option.value, option.textContent.trim()])'
    );
    $this->assertSame('', $radius_options[0][0] ?? NULL, 'The closed Border radius select leads with the unset option (value \'\').');
    $this->assertSame(
      self::RADIUS_STOPS,
      array_slice($radius_options, 1),
      'The closed Border radius select offers exactly the skin\'s four minted radius tokens (sm, md, lg, pill as their var() references faced by value) plus the suite binding\'s allowed literal 0: the suite\'s uncurated radius binding served whole.',
    );
    // The md stop, picked by the face its option wears (0.5rem).
    $this->pickPanelOption($radius_select, array_column(self::RADIUS_STOPS, 1, 0)['var(--radius-md)']);
    $this->assertJsCondition(
      "$map_a?.default?.['border-radius'] === 'var(--radius-md)'",
      20000,
      'The md pick committed border-radius as the stop\'s reference spelling, var(--radius-md), to the map (widget, op, mirror): the section now has a value.',
    );
    $this->assertJsCondition(
      'document.querySelector(' . json_encode($radius_select) . ").value === 'var(--radius-md)'",
      20000,
      'The controlled Border radius select reads the committed reference.',
    );
    // With a value the auto signal is true and the prop flipped
    // false -> true under an already-open DOM: no visible change, but
    // the prop and DOM now agree, which is the setup for the delete.
    $this->assertSectionOpen('Border', TRUE, 'Border stays open with its value set (prop and DOM agree).');

    // Unset it: the auto signal falls back to false and the prop flips
    // true -> false; the recorded mark must keep the DOM open. This is
    // the bug's exact sequence (open by hand > add > delete > closes
    // under the user). The unset option is the first, picked by the
    // face it wears.
    $radius_unset_face = (string) $session->evaluateScript(
      'document.querySelector(' . json_encode($radius_select) . ').options[0].textContent.trim()'
    );
    $this->pickPanelOption($radius_select, $radius_unset_face);
    $this->assertJsCondition(
      "($map_a?.default?.['border-radius'] ?? null) === null",
      20000,
      'The unset pick unset border-radius from the map: the section is value-less again.',
    );
    $this->assertJsCondition(
      'document.querySelector(' . json_encode($radius_select) . ").value === ''",
      20000,
      'The controlled Border radius select reads unset again.',
    );
    $this->assertSectionOpen('Border', TRUE, 'Border is STILL open after its last value was deleted: the summary-click mark beat the prop flipping back to false.');

    // ----- The anchor reset pushes the DOM. -----
    // A second block with no values: selecting it moves the anchor, the
    // marks read as stale, and the prop recomputes to B's arrival state.
    // Preact writes that change to the still-mounted details.
    $block_b_uuid = $uuid_service->generate();
    $this->placeTextBlock($uuid_service->generate(), $block_b_uuid, '__placementErrorB');
    $block_b_key = "block:$block_b_uuid";
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_b_key');");

    $this->assertSectionOpen('Border', FALSE, 'Border recomputed closed on the second element: the hand-open mark was stale under the new anchor.');
    $this->assertSectionOpen('Spacing', TRUE, 'Spacing arrives open on the value-less second element by the skin signal: its own arrival state, not a carried-over mark.');
    $this->assertSectionOpen('Typography', FALSE, 'Typography is closed on the value-less second element: the arrival state is the new element\'s own, nothing carried over.');

    // ----- The origin is read at the delivering condition. -----
    // A variant that sets border-radius at Base, minted from B and worn
    // by B through the op spine (save_material lands the record in the
    // materials store, apply_variant writes the reference into B's map
    // entry). The row's provenance must then come from the supplier
    // table read at the DELIVERING condition (Base), whatever the pose.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'save_material',
            kind: 'variant',
            label: 'Grip origin',
            key: '$block_b_key',
            styles: { '': { default: { 'border-radius': 'var(--radius-md)' } } },
          }],
          label: 'Saved variant Grip origin',
        }))
          .then((payload) => {
            const minted = (payload.materials?.upsert ?? []).find((record) => record.kind === 'variant');
            if (!minted) {
              throw new Error(JSON.stringify(payload));
            }
            window.__gripVariant = minted.id;
            return Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
              operations: [{ op: 'apply_variant', key: '$block_b_key', variant: minted.id }],
            }));
          })
          .catch((error) => {
            window.__gripVariantError = error.message;
          });
      })();
      JS);
    $this->assertJsCondition(
      "window.__gripVariantError !== undefined || (typeof window.__gripVariant === 'string'"
      . " && Drupal.Daedalus.Materials.has('variant', window.__gripVariant)"
      . ' && JSON.stringify(' . $this->mapJs($block_b_key) . "?.variants ?? []).includes(window.__gripVariant))",
      20000,
      'The variant mint and wear resolved.',
    );
    $variant_error = $session->evaluateScript('window.__gripVariantError ?? null');
    $this->assertNull($variant_error, 'Minting or wearing the variant failed: ' . ($variant_error ?? ''));
    $variant_id = (string) $session->evaluateScript('window.__gripVariant');
    $this->assertSame('grip_origin', $variant_id, 'The variant minted under its derived machine name.');

    // The worn value reaches the section model through the RESOLVED
    // entry: Border (closed on B a moment ago, no mark under B's
    // anchor) opens by the values signal alone.
    $this->assertSectionOpen('Border', TRUE, 'Border opened by the values signal on the worn variant\'s border-radius: the resolved entry carries the supplied value.');
    $radius_row = $this->rowJs($radius_select);

    // Pose the canvas at Medium through the width chip's real menu: the
    // pick lands the band's midpoint on the canvas vector, the document
    // wears the width stamp, and the chip's face reads the band, lit.
    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-chip-row")]/button[contains(@class, "daedalus-stylish-chip--width")]');
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'The width chip opened its menu on the shared primitive.',
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
      'The width chip posed the canvas at Medium: the document wears the width stamp and the chip reads Medium, lit.',
    );

    // Seam: origin read at the delivering condition. Posed at Medium,
    // the Border radius row's value is delivered by the variant AT BASE;
    // the supplier table crosses into rowOrigin whole (not pre-sliced at
    // the pose, which is what lost this case before), and the origin's
    // hue lands on the row as `--daedalus-stylish-origin`, the variant layer's
    // color from the one identity function. The retired layer dots are
    // gone from the DOM entirely: the slot is the only mark. The row is
    // the same closed select's property element the surviving-mark leg
    // read.
    $this->assertJsCondition(
      "($radius_row)?.style.getPropertyValue('--daedalus-stylish-origin').trim() === " . json_encode(self::layerColor($variant_id)),
      20000,
      'Origin read at the delivering condition: posed at Medium, the Border radius row supplied by the variant at Base carries --daedalus-stylish-origin in the variant layer\'s hue (layerColor(variantLayerId(grip_origin))).',
    );
    $this->assertSame(
      0,
      (int) $session->evaluateScript("document.querySelectorAll('.daedalus-stylish-inspector__layer-dot').length"),
      'Origin read at the delivering condition: no retired layer dot exists anywhere; the row\'s leading slot is the only provenance mark.',
    );

    // Seam: row menu header. A right-click on the row opens the row
    // menu on the shared primitive, whose header is the origin in words:
    // the value, the variant's label, and the delivering condition.
    $session->executeScript(
      "(() => { const row = $radius_row; const rect = row.getBoundingClientRect();"
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true,"
      . ' clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2 })); })();'
    );
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')",
      20000,
      'Row menu header: the right-click on the Border radius row opened the row menu with a header on the shared primitive.',
    );
    $header = (string) $session->evaluateScript("document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header').textContent");
    $this->assertStringContainsString('Grip origin', $header, 'Row menu header: the header names the supplying variant. Header: ' . $header);
    $this->assertStringContainsString('Base', $header, 'Row menu header: the header names the delivering condition (Base), not the posed one. Header: ' . $header);

    // Seam: drive doorway. "Go to Base" is the origin's drive doorway;
    // its run reaches the Inspector's driveCanvas and the canvas vector:
    // the width pose clears, the chip row's width face returns to Base.
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Go to Base').click();"
    );
    $this->assertJsCondition(
      "!document.documentElement.hasAttribute('data-canvas-width')"
      . " && (() => { const chip = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-chip--width');"
      . " return !!chip && !chip.classList.contains('daedalus-stylish-chip--lit') && chip.textContent.trim().startsWith('Base'); })()",
      20000,
      'Drive doorway: "Go to Base" drove the canvas back; the chip row\'s active width condition is default (the chip reads Base, unlit, and the document\'s width stamp is gone).',
    );
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu')",
      20000,
      'Drive doorway: the row menu closed on the pick.',
    );
  }

}
