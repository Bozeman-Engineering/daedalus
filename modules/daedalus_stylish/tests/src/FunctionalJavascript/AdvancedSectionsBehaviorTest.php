<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\FunctionalJavascript;

use Drupal\file\Entity\File;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\daedalus_stylish\Traits\LayerColorTrait;
use Drupal\Tests\TestFileCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the advanced-mode property-group sections on a real page.
 *
 * The capability under test is the inspector's Advanced surface: one
 * native <details> per declared property group, its `open` prop
 * CONTROLLED by the collapse model (daedalus_ui/js/outline/collapse.js:
 * `explicit ?? default`, the Sections' one posture over a flat outline
 * whose defaults are the arrival signals, style-guide.js autoOpen; the
 * reset a remount, the Sections keyed on the anchor). The model is
 * node-tested exhaustively (daedalus_ui's collapse.test.mjs owns the
 * rule: explicit over default both ways, the open, add, delete
 * sequence, the all-gestures and their predicates;
 * style-guide-refs.test.mjs owns the arrival signals). Everything here
 * is wiring that only exists where Preact's controlled push of
 * `details.open`, the summary's click handler, daedalus_ui's shared
 * collapse controls, the op spine and the selection facade meet in a
 * browser:
 * - Arrival state reaches the DOM (seam: the three auto signals crossing
 *   the prop into `details.open` on mount): on an element carrying one
 *   committed value in Typography, flipping to Advanced renders
 *   Typography's details `[open]` (the values signal), Spacing's `[open]`
 *   too with no value of its own (the defaults-tier signal: the skin
 *   zeroes `kind:block` margins, so the Margin top and Margin bottom
 *   rows show the skin's 0 LIT, the selected option, in the skin
 *   tier's hue), and Border's not (no value, no pending line, no skin
 *   value). Node tests pin that autoOpen answers true/false per signal;
 *   only a browser proves each answer lands as the native attribute,
 *   and the skin signal is only reachable where a real skin delivers
 *   a defaults-tier value to a mounted row.
 * - A skin-delivered value is the row's lit value (seam: the style
 *   stack's skin layer crossing stackAt into the closed select's
 *   controlled value and the row's `--daedalus-stylish-origin`): the
 *   Margin top select reads the skin's 0 as its VALUE, its mark titled
 *   "0 from the active skin", the row hued in the skin tier's color.
 *   style-stack.test.mjs pins the winner and provenance-model.test.mjs
 *   the sentence; the display rule (the shown value is always the
 *   winner's, lit, no ghost) reaching a mounted select is wiring.
 * - A hand-opened section survives its last value's deletion (seam: the
 *   summary click writing the explicit posture, then the default
 *   flipping open and back to closed while the explicit posture keeps
 *   the DOM open). This is the bug the model exists for: before it, the
 *   delete pushed open=false under the user. The click-not-toggle
 *   decision (the toggle event fires for programmatic opens too) and
 *   Preact's push-on-change are both invisible to a node test. The leg
 *   runs on Border, the group that
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
 * - The anchor reset remounts the sections (seam: a selection change
 *   moving the anchor the Sections are keyed on, the remount dropping
 *   the explicit postures, and the new element's arrival state landing
 *   in fresh details): selecting a second, value-less element closes
 *   the hand-opened Border again, while Spacing arrives open on it by
 *   the skin signal and Typography closed. The reset is a key, not a
 *   model function, so no node test sees it; the remount is
 *   browser-only.
 * - Collapse all and expand all over the sections (seam: the model's
 *   collapseAll and expandAll crossing Preact's controlled push into
 *   `details.open` for every section at once through daedalus_ui's
 *   shared control at the list head, the pair's disabled states reading
 *   the same posture, and the arrival state returning after a selection
 *   move): on the mixed second element both buttons are enabled;
 *   collapse all leaves no section `[open]` and disables itself; expand
 *   all opens every section, the ones arriving closed included, and
 *   disables itself; selecting the first element again shows its own
 *   arrival state (Typography and Spacing open, Border closed) and
 *   selecting the second restores its. collapse.test.mjs pins the
 *   all-gestures and the predicates; only a browser proves the control,
 *   the push and the remount agree.
 * - A row's provenance is read at the DELIVERING condition (seam: the
 *   element's style stack, built once by the Inspector with every
 *   layer's RAW conditions, read per row through stackAt's cascade at
 *   the pose, then the winner's hue landing as the row's
 *   `--daedalus-stylish-origin` and originOf's sentence as the shared
 *   menu's header): a variant minted and worn through the op spine sets
 *   border-radius at Base (Border, closed on the second element, opens
 *   by the values signal alone: the delivered entry carries the worn
 *   value); with the canvas posed at Medium through the width chip's
 *   real menu, the Border radius row carries the variant layer's hue
 *   (layers.js layerColor over variantLayerId, spelled here as the
 *   oracle) while no retired layer dot exists anywhere, a right-click
 *   on the row opens the row menu whose header names the variant AND
 *   Base, and its "Go to Base" doorway drives the canvas back (the
 *   chip row's width face returns to Base, the document's width stamp
 *   clears). style-stack.test.mjs pins the cascaded read and
 *   provenance-model.test.mjs the posture, color and sentence; only a
 *   browser proves the stack reaches the row with its conditions
 *   unsliced, the hue reaches the DOM, the menu mounts on the
 *   primitive, and the doorway reaches the canvas vector.
 * - A worn variant's PART value is lit on the drilled part's row (seam:
 *   the drilled part's row reading the ROOT's reference layer through
 *   the stack, the reference's styles resolved at the part's relative
 *   suffix): a text block wearing the shipped `button` variant (its
 *   styles keyed at `::.text-block-link`) is drilled to its link, and
 *   the Padding row's input reads the variant's `0.75em 1.5em` as its
 *   VALUE (no placeholder, no ghost), the row hued in the variant
 *   layer's color, its mark titled "0.75em 1.5em from Button", and the
 *   row menu offers the variant's doorway and no Unset (nothing of the
 *   element's own delivers at the pose). style-stack.test.mjs pins the
 *   part suffix resolution; that the selection's part facet reaches
 *   the Inspector's one stack build and out to a mounted row is wiring.
 *   Under strict law the shipped button is withheld (its literal hex
 *   and em padding fail guide_example's bound scales), so this leg and
 *   the next run on the recipe's open olivero system.
 * - The wear bar is read-only on a drilled part (seam: the wear bar
 *   reading the root's reference layers regardless of the drill, the
 *   selection facet flowing into its posture instead of unmounting it):
 *   drilled to the link, the bar wears its read-only class, its one row
 *   names the axis and Button as a plain reading carrying no trigger
 *   (no button, no picker chevron), no add row exists; the row's
 *   right-click menu carries exactly Outline Variant and Select wearer,
 *   and Select wearer re-selects the whole element (the part crumb
 *   gone, the add row back). wear-bar-model.test.mjs pins the read-only
 *   menu; the facet-to-posture wiring and the facade round trip only
 *   exist in a browser.
 * - The element doorway under a write address (seam: originOf under
 *   `editable: address`, where style-stack.js gives the seated rule's
 *   ancestor layer the editable mark so the element's own block sits
 *   ABOVE it, emitting the `element` doorway; the advanced row's
 *   useRowMenu dispatching it through the source's select(key), the
 *   selection write that clears the target; and the row mounted with
 *   NO sheet, the advanced Sections outside a session passing none, so
 *   onWinner is never handed): with the block wearing an own padding
 *   and its section's `> pb-text` rule seated as the styling target
 *   (the chip lit at that address), the Padding row keeps the element's
 *   value with its mark titled "1rem from this element", a right-click
 *   offers "Edit on the element" and never the session's "Go to
 *   element", and the pick drops the target through the source (the
 *   selection stays, the chip is back on the own address, the row is
 *   quiet again) with nothing logged: before this the row emitted the
 *   session's winner doorway here and its click threw on the null
 *   sheet. provenance-model.test.mjs pins the doorway and
 *   use-row-menu.test.mjs the dispatch; that the mounted row outside a
 *   session reaches the source and no sheet is wiring.
 * - A generated box seated from the chip and styled through a row (seam:
 *   the chip's "+ After" doorway seating the element's own `&::after` as
 *   the styling target, a seat and never a write; the advanced Sections
 *   mounting the box's derived roster, the host's groups plus Generated
 *   content; the Background color row's settle committing ONE
 *   update_style at the box key, the op spine birthing the entry with
 *   `content: ""` at default in the same op; the printed `:scope::after`
 *   inside the element's @scope landing on a generated box, and the
 *   selection paint's own `:scope::after` outline computing on the
 *   pseudo-element itself): the address set ends with "+ Before" /
 *   "+ After" (then "Copy key" closes the menu after the separator),
 *   the pick faces the chip "After" at block::&::after unlit with no map
 *   entry, the paint slot holds the dashed `:scope::after` rule and the
 *   pseudo computes it while no box exists (content none), a typed
 *   `#ff0000` settled by blur lands with the seed beneath it, the host's
 *   own padding untouched, and the box computes `""`, red and dashed on
 *   the canvas with the Generated content section open on its Content
 *   row. subject-model.test.mjs pins the doorway and
 *   write-address.test.mjs the box's set; the kernel tier pins the seed
 *   and the print; no lower tier sees a generated box.
 * - A generated box carries an image through its Content row (seam: the
 *   Content row's media chip, offered because `content` carries the
 *   media flag on a keyword-typed definition, opening the REAL media
 *   library modal; the selection command landing the token at the
 *   picker; the leader's pick committing ONE update_style at the box
 *   key with the token as the whole `content` value; MediaValueResolver
 *   substituting the file URL in the printed `:scope::after`; the
 *   browser computing `url("…")` as the pseudo-element's content): with
 *   the seeded box standing, the Generated content section offers the
 *   media chip and no gradient opener (the offer follows the type), a
 *   media minted mid-scenario is picked by id and inserted, the entry's
 *   `content` becomes the token beside the untouched background line,
 *   and the pseudo computes a `url("…")` ending in the file's name.
 *   GradientBehaviorTest locks the same modal on `background-image`;
 *   this leg locks it on the second consumer, and only a browser sees
 *   the modal and the computed pseudo.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site as
 * daedalus_editor, the universal block kind declaring spacing,
 * typography and border among its property groups) switched to the
 * strict reference design system by setUp: the recipe's olivero system
 * ships open and the closed-row premises need law, so the scenario
 * activates daedalus_stylish_guide_example's guide_example system (strict as
 * shipped, its own skin and stop curation) rather than forcing a mode
 * onto a guide written to be open. The two part legs at the tail switch
 * the site back to olivero in-scenario (open as shipped), the posture
 * under which the shipped button variant is offered. One fixture, one
 * scenario: the site install dominates the run cost, so all behavior
 * lives in the single test method. Future advanced-mode seams append
 * here.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class AdvancedSectionsBehaviorTest extends WebDriverTestBase {

  use DesignSystemActivationTrait;
  use EditModeTrait;
  use LayerColorTrait;
  use TestFileCreationTrait;
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
   * a row whose winner is another layer selects that value's option,
   * never the unset one (the Margin top leg), so the unset option's
   * face is not this test's claim.
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
   * Border is the posture legs' group: it arrives closed on a
   * value-less block (the skin delivers nothing to any of its rows),
   * which the hand-open, delete, reset and all-gesture claims need.
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
   * The Padding row's detented input inside the advanced Spacing section.
   *
   * The open render of the same binding PADDING_SELECT reads closed: under
   * olivero's open law a bound dimension is the free field, so the
   * drilled link's Padding row is a text input carrying the row's label
   * as its aria-label.
   */
  private const PADDING_INPUT = "#daedalus_stylish_inspector details.daedalus-stylish-inspector__section input[aria-label='Padding']";

  /**
   * The wear bar and its parts (wear-bar.js).
   */
  private const WEAR_BAR = '#daedalus_stylish_inspector .daedalus-stylish-wear-bar';

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
   * Places a section holding one LINKED text block and waits for the render.
   *
   * daedalus_edit_text_block's composed block: the anchor (`.text-block-link`,
   * the declared conditional part the shipped button variant styles)
   * renders only while field_text_url holds a value, so the block is
   * born linked and the part exists to drill.
   *
   * @param string $section_uuid
   *   The new section's uuid.
   * @param string $block_uuid
   *   The new block's uuid.
   * @param string $sentinel
   *   A per-call window variable name for the placement error.
   */
  private function placeLinkedTextBlock(string $section_uuid, string $block_uuid, string $sentinel): void {
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
              block_type: 'text',
              properties: {
                field_text_text: { value: 'Advanced sections link' },
                field_text_url: { uri: 'https://example.com' },
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
      'The linked text block placement resolved.',
    );
    $placement_error = $session->evaluateScript("window.$sentinel ?? null");
    $this->assertNull($placement_error, 'Linked text block placement failed: ' . ($placement_error ?? ''));
    $this->assertNotNull(
      $this->assertSession()->waitForElement('css', "[data-selectable='block:$block_uuid'] a.text-block-link"),
      'The text block rendered on the canvas with its link part (the anchor the button variant styles).',
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
    return $this->styleMapJs() . '[' . json_encode($key) . ']';
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
   *   rows, the defaults-tier signal) and its Border details is closed
   *   (no value, no pending line, no skin value): the three auto
   *   signals landed as the native attribute.
   * - The lit skin value: the Margin top select's value IS the skin's 0
   *   (the allowed literal's option selected, the unset option leading
   *   unselected), the row hued in the skin tier's color, the mark
   *   titled "0 from the active skin", the map holding no margin-top.
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
   * - The origin at the delivering condition: a variant "Grip & Tim's origin"
   *   (border-radius var(--radius-md) at Base: a minted radius token,
   *   the only legal spelling on the bound property under strict law)
   *   is minted from B and worn by B through the op spine; Border opens
   *   by the values signal alone (the stack's delivered entry carries
   *   the worn value); the width chip's real menu poses the canvas at Medium; the
   *   Border radius row's `--daedalus-stylish-origin` is the variant layer's hue
   *   and no layer dot exists; a right-click on the row opens the row
   *   menu with a header naming the variant and Base; its "Go to Base"
   *   doorway returns the chip row's width face to Base and clears the
   *   document's width stamp.
   * - The open flip: the site switches back to the recipe's olivero
   *   design system (open as shipped, the posture under which the
   *   shipped `button` variant is offered: under strict law its literal
   *   hex and em padding are withheld) server-side and the page reloads
   *   still in Edit Mode; a section with one LINKED text block (C) is
   *   placed through the op spine, selected, the inspector opened and
   *   put in Advanced.
   * - The lit part value: C wears `button` through apply_variant (the
   *   variant's styles keyed at `::.text-block-link`); the wear bar
   *   shows the wearing editable with its add row; the link is drilled
   *   through the selection facade's part facet (the part crumb
   *   mounts); the Padding row's input reads `0.75em 1.5em` as its value
   *   with an empty placeholder, the row hued in the button layer's
   *   color, the mark titled "0.75em 1.5em from Button", the map holding
   *   nothing at the part key; the row menu's header names Button and
   *   its items carry the variant's Edit doorway and no Unset; an
   *   outside pointerdown dismisses it.
   * - The read-only wear bar: still drilled, the bar wears its read-only
   *   class, its Style row names Button as a span with the target
   *   disabled, no picker trigger and no add row; the row's right-click
   *   menu lists exactly Outline Variant and Select wearer; Select
   *   wearer re-selects the whole element: the part crumb is gone, the
   *   bar is editable again with its add row, the menu closed.
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

    $this->enterEditModeViaCookies($node->toUrl());
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
    // Margin bottom show that 0 lit), and the group with neither renders
    // collapsed.
    $this->assertSectionOpen('Typography', TRUE, 'Typography arrives open: it carries the committed color (the values signal to details.open).');
    $this->assertSectionOpen('Spacing', TRUE, 'Spacing arrives open with no value of its own: the skin\'s kind:block margin zeroes reach the Margin rows (the defaults-tier signal to details.open).');

    // ----- A skin-delivered value is the row's lit value. -----
    // Seam: the stack's skin layer (guide_example's kind:block margin
    // zero) is the Margin top row's WINNER at Base, so the closed
    // select's controlled value is the skin's 0, the option the suite's
    // allowed literal mints, never the unset option with a faded face;
    // the row's --daedalus-stylish-origin is the skin tier's hue
    // (layers.js SKIN_COLOR) and the mark's title is originOf's
    // inherited-from-skin sentence. The map holds nothing for the block
    // at margin-top: the value is the layer's, lit, not a write.
    $margin_top_select = "#daedalus_stylish_inspector details.daedalus-stylish-inspector__section select[aria-label='Margin top']";
    $margin_top_row = $this->rowJs($margin_top_select);
    $this->assertJsCondition(
      '(() => { const select = document.querySelector(' . json_encode($margin_top_select) . ');'
      . " return !!select && select.value === '0' && select.selectedOptions[0]?.textContent.trim() === '0'"
      . " && select.options[0].value === '' && select.options[0].textContent.trim() === '- Unset -'; })()",
      20000,
      'Lit skin value: the Margin top select reads the skin\'s delivered 0 as its VALUE (the selected option is the allowed literal 0, the unset option leads unselected with no ghost face): the stack\'s skin layer is the row\'s winner.',
    );
    $this->assertJsCondition(
      "($margin_top_row)?.style.getPropertyValue('--daedalus-stylish-origin').trim() === 'hsl(172 66% 50%)'",
      20000,
      'Lit skin value: the Margin top row\'s --daedalus-stylish-origin is the skin tier\'s hue (layers.js layerColor over the skin layer id, SKIN_COLOR).',
    );
    $this->assertJsCondition(
      "($margin_top_row)?.querySelector('.daedalus-stylish-inspector__mark')?.title === '0 from the active skin'",
      20000,
      'Lit skin value: the Margin top row\'s mark is titled "0 from the active skin", originOf\'s inherited-from-skin sentence over the stack read.',
    );
    $this->assertJsCondition(
      '!(' . $this->mapJs($block_a_key) . "?.default?.['margin-top'])",
      20000,
      'Lit skin value: the map holds no margin-top for the block; the lit 0 is the skin layer\'s, never a write.',
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
    // whatever face it wears (read off the option, never assumed).
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
    // A REAL click on the summary: the native toggle is suppressed and
    // Preact's click handler writes the explicit posture (the model's
    // toggle over its answer for the row, the DOM's state at click
    // time), pushed to details.open by the prop change alone.
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

    // ----- The anchor reset remounts the sections. -----
    // A second block with no values: selecting it moves the anchor the
    // Sections are keyed on, the remount drops the explicit postures,
    // and B's arrival state lands in fresh details.
    $block_b_uuid = $uuid_service->generate();
    $this->placeTextBlock($uuid_service->generate(), $block_b_uuid, '__placementErrorB');
    $block_b_key = "block:$block_b_uuid";
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_b_key');");

    $this->assertSectionOpen('Border', FALSE, 'Border arrives closed on the second element: the hand-open posture went with the remount on the new anchor.');
    $this->assertSectionOpen('Spacing', TRUE, 'Spacing arrives open on the value-less second element by the skin signal: its own arrival state, not a carried-over posture.');
    $this->assertSectionOpen('Typography', FALSE, 'Typography is closed on the value-less second element: the arrival state is the new element\'s own, nothing carried over.');

    // ----- Collapse all, expand all, then the arrival state again. -----
    // Seam: the model's all-gestures crossing Preact's controlled push
    // into details.open for every section at once through the shared
    // pair at the list head, the pair's disabled states reading the
    // same posture, and the arrival state returning after a selection
    // move (the remount). Every read is one JS expression over the
    // mounted sections, so a stale detail never satisfies it.
    $sections_js = "document.querySelectorAll('#daedalus_stylish_inspector details.daedalus-stylish-inspector__section')";
    $open_sections_js = "document.querySelectorAll('#daedalus_stylish_inspector details.daedalus-stylish-inspector__section[open]')";
    $pair_js = "(() => { const head = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__sections-head');"
      . " return { collapse: head?.querySelector('.daedalus-ui-collapse-all')?.disabled ?? null,"
      . " expand: head?.querySelector('.daedalus-ui-expand-all')?.disabled ?? null }; })()";
    $pair_reads = fn (string $collapse, string $expand): string =>
      "(() => { const pair = $pair_js; return pair.collapse === $collapse && pair.expand === $expand; })()";
    // B's arrival state is mixed (Spacing open, Border and Typography
    // closed): neither gesture would do nothing, so both are enabled.
    $this->assertJsCondition($pair_reads('false', 'false'), 20000, 'Both collapse all and expand all are enabled over the mixed arrival state (one section open, two closed).');

    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-inspector__sections-head")]/button[contains(@class, "daedalus-ui-collapse-all")]');
    $this->assertJsCondition("$sections_js.length > 0 && $open_sections_js.length === 0", 20000, 'Collapse all closed every section at once: no details carries open, the one arriving open included.');
    $this->assertJsCondition($pair_reads('true', 'false'), 20000, 'With every section collapsed, collapse all is disabled and expand all enabled: the pair reads the posture it wrote.');

    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-inspector__sections-head")]/button[contains(@class, "daedalus-ui-expand-all")]');
    $this->assertJsCondition("$sections_js.length > 0 && $open_sections_js.length === $sections_js.length", 20000, 'Expand all opened every section at once, the ones arriving closed included.');
    $this->assertJsCondition($pair_reads('false', 'true'), 20000, 'With every section expanded, expand all is disabled and collapse all enabled.');

    // Select A again: the anchor moves, the Sections remount, and A's
    // own arrival state shows (the color in Typography, the skin's
    // margins in Spacing, Border unset). Border first: it is open on B
    // after the expand and closed on A, so the read cannot pass on B's
    // stale details.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_a_key');");
    $this->assertSectionOpen('Border', FALSE, 'Border arrives closed on the first element again after the expand all on the second: the arrival state, not the other element\'s posture.');
    $this->assertSectionOpen('Typography', TRUE, 'Typography arrives open on the first element again: its committed color, the values signal.');
    $this->assertSectionOpen('Spacing', TRUE, 'Spacing arrives open on the first element again: the skin signal.');

    // And back to B for the legs below: its own arrival state again,
    // no posture carried from either visit. Typography first: open on A,
    // closed on B.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_b_key');");
    $this->assertSectionOpen('Typography', FALSE, 'Typography arrives closed on the second element again: nothing carried over from the first.');
    $this->assertSectionOpen('Border', FALSE, 'Border arrives closed on the second element again: the expand all before the move did not survive the remount.');
    $this->assertSectionOpen('Spacing', TRUE, 'Spacing arrives open on the second element again by the skin signal.');

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
            label: "Grip & Tim's origin",
            key: '$block_b_key',
            styles: { '': { default: { 'border-radius': 'var(--radius-md)' } } },
          }],
          label: "Saved variant Grip & Tim's origin",
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
    $this->assertSame('grip_tim_s_origin', $variant_id, 'The variant minted under its derived machine name (the label\'s & and \' collapse to underscores).');

    // The worn value reaches the section model through the stack's
    // delivered entry: Border (closed on B a moment ago, no mark under
    // B's anchor) opens by the values signal alone.
    $this->assertSectionOpen('Border', TRUE, 'Border opened by the values signal on the worn variant\'s border-radius: the delivered entry carries the supplied value.');
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
    // the stack carries every layer's raw conditions (never pre-sliced
    // at the pose, which is what lost this case before), stackAt's
    // cascade finds the variant's Base block as the winner, and the
    // winner's hue lands on the row as `--daedalus-stylish-origin`, the
    // variant layer's color from the one identity function. The retired
    // layer dots are gone from the DOM entirely: the slot is the only
    // mark. The row is the same closed select's property element the
    // surviving-mark leg read.
    $this->assertJsCondition(
      "($radius_row)?.style.getPropertyValue('--daedalus-stylish-origin').trim() === " . json_encode(self::layerColor($variant_id)),
      20000,
      'Origin read at the delivering condition: posed at Medium, the Border radius row supplied by the variant at Base carries --daedalus-stylish-origin in the variant layer\'s hue (layerColor(variantLayerId(grip_tim_s_origin))).',
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
    $this->assertStringContainsString("Grip & Tim's origin", $header, 'Row menu header: the header names the supplying variant, its & and \' as text (an @ sigil would read &amp; / &#039;). Header: ' . $header);
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

    // ----- The open flip. -----
    // The two part legs need the shipped `button` variant offered, and
    // under guide_example's strict law it is withheld (its literal hex
    // fill and em padding fail the bound color and space scales), so the
    // site switches back to the recipe's olivero design system, open as
    // shipped, server-side; the reload serves the open guide with the
    // button on the roster. The Edit Mode cookies persist, so the next
    // render is still the editing chrome.
    $this->activateSkin('olivero', 'olivero');
    $this->assertSame(
      'open',
      \Drupal::service('daedalus_stylish.style_guide')->enforcement(),
      'The re-activated olivero design system ships open enforcement: the part legs run on the open reference, where the shipped button variant is offered.',
    );
    $this->drupalGet($node->toUrl());
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted after the switch to the open reference.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Materials.has('variant', 'button')",
      20000,
      'The shipped button variant (daedalus_edit_text_block\'s, imported on install) is on the open roster.',
    );

    // A linked text block (C): the anchor exists from birth, so the
    // button's part styles have a part to land on and the panel a part
    // to drill.
    $block_c_uuid = $uuid_service->generate();
    // The section's uuid is kept: the element-doorway leg at the tail
    // seats one of its rules as the styling target.
    $section_c_uuid = $uuid_service->generate();
    $this->placeLinkedTextBlock($section_c_uuid, $block_c_uuid, '__placementErrorC');
    $block_c_key = "block:$block_c_uuid";
    $this->openInspector();
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_c_key');");
    // Advanced, whichever mode the reload restored: the flip is the mode
    // segment's real button, as the first phase's.
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]')",
      20000,
      'The inspector mounted its mode segment after the reload.',
    );
    if ((string) $session->evaluateScript("document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]').textContent.trim()") !== 'Advanced') {
      $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-inspector__ui-mode")]/button[normalize-space() = "Advanced"]');
    }
    $this->assertJsCondition(
      "(() => { const active = document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]');"
      . " return !!active && active.textContent.trim() === 'Advanced'; })()",
      20000,
      'The inspector is in Advanced mode for the part legs.',
    );

    // C wears the shipped button through the op spine: the reference on
    // the ROOT's entry, the variant's styles keyed at ::.text-block-link.
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
      "window.__buttonWearError !== undefined || JSON.stringify(" . $this->mapJs($block_c_key) . "?.variants ?? []).includes('button')",
      20000,
      'The button wear resolved.',
    );
    $wear_error = $session->evaluateScript('window.__buttonWearError ?? null');
    $this->assertNull($wear_error, 'Wearing the button variant failed: ' . ($wear_error ?? ''));
    // The whole element selected: the wear bar is editable, its add row
    // offered, so the read-only posture below is the drill's doing.
    $wear_bar = self::WEAR_BAR;
    $this->assertJsCondition(
      '(() => { const bar = document.querySelector(' . json_encode($wear_bar) . ');'
      . " return !!bar && !bar.classList.contains('daedalus-stylish-wear-bar--read-only')"
      . " && !!bar.querySelector('.daedalus-stylish-wear-bar__add')"
      . " && !!bar.querySelector('.daedalus-stylish-wear-bar__row .daedalus-stylish-wear-bar__variant-label')"
      . " && bar.querySelector('.daedalus-stylish-wear-bar__row .daedalus-stylish-wear-bar__variant-label').textContent.trim() === 'Button'; })()",
      20000,
      'Whole element selected: the wear bar shows the Button wearing editable, with its add row.',
    );

    // ----- The lit part value. -----
    // Seam: the drilled part's row reads the ROOT's reference layer
    // through the stack. The selection facade's styling target
    // (select(key) then target(key, selector), the canvas click's and
    // the tree row's one grammar) retargets the Inspector's one stack build to
    // `root::.text-block-link`, where buildStack resolves the button's
    // styles at the part's relative suffix; stackAt's winner for
    // padding is the variant's block, so the Padding row's input reads
    // the value LIT (never a placeholder), hued and titled by the
    // variant layer, while the map holds nothing at the part key.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_c_key'); Drupal.Daedalus.Selection.target('$block_c_key', '.text-block-link');");
    $this->assertJsCondition(
      "document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__address')?.dataset.daedalusStylishAddress === " . json_encode("$block_c_key::.text-block-link"),
      20000,
      'Lit part value: the subject chip stands at the part (the panel targets root::.text-block-link).',
    );
    $padding_input = self::PADDING_INPUT;
    $padding_part_row = 'document.querySelector(' . json_encode($padding_input) . ").closest('.daedalus-stylish-inspector__property')";
    $this->assertJsCondition(
      '(() => { const input = document.querySelector(' . json_encode($padding_input) . ');'
      . " return !!input && input.value === '0.75em 1.5em' && !input.placeholder; })()",
      20000,
      'Lit part value: the drilled link\'s Padding row reads the button variant\'s 0.75em 1.5em as its VALUE (an empty placeholder: the value is the winner\'s, lit, not a ghost): the part row read the root\'s reference layer through the stack.',
    );
    $this->assertJsCondition(
      "($padding_part_row)?.style.getPropertyValue('--daedalus-stylish-origin').trim() === " . json_encode(self::layerColor('button')),
      20000,
      'Lit part value: the Padding row\'s --daedalus-stylish-origin is the button layer\'s hue (layerColor(variantLayerId(button))).',
    );
    $this->assertJsCondition(
      "($padding_part_row)?.querySelector('.daedalus-stylish-inspector__mark')?.title === '0.75em 1.5em from Button'",
      20000,
      'Lit part value: the Padding row\'s mark is titled "0.75em 1.5em from Button", originOf\'s inherited-from-variant sentence.',
    );
    $this->assertJsCondition(
      '!' . $this->mapJs("$block_c_key::.text-block-link"),
      20000,
      'Lit part value: the map holds no entry at the part key; the lit padding is the variant layer\'s, never a write.',
    );
    // The row menu: the variant's doorway and no Unset (nothing of the
    // element's own delivers at the pose, so there is nothing to unset;
    // a typed value would write an own override instead).
    $session->executeScript(
      "(() => { const row = $padding_part_row; const rect = row.getBoundingClientRect();"
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true,"
      . ' clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2 })); })();'
    );
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')",
      20000,
      'Lit part value: the right-click on the Padding row opened the row menu with its origin header.',
    );
    $padding_menu = json_decode((string) $session->evaluateScript(
      "JSON.stringify({ header: document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header').textContent,"
      . " labels: Array.from(document.querySelectorAll('.daedalus-ui-menu__item .daedalus-ui-menu__label')).map((label) => label.textContent.trim()) })"
    ), TRUE);
    $this->assertStringContainsString('Button', $padding_menu['header'], 'Lit part value: the row menu header names the supplying variant. Header: ' . $padding_menu['header']);
    $this->assertNotContains('Unset', $padding_menu['labels'], 'Lit part value: the row menu offers no Unset doorway on a variant-delivered value (nothing of the element\'s own delivers at the pose). Items: ' . implode(', ', $padding_menu['labels']));
    $this->assertNotEmpty(
      array_filter($padding_menu['labels'], static fn (string $label): bool => str_starts_with($label, 'Edit Button')),
      'Lit part value: the row menu offers the variant\'s document doorway (Edit Button at Base…). Items: ' . implode(', ', $padding_menu['labels']),
    );
    $session->executeScript("document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));");
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu')",
      20000,
      'Lit part value: an outside pointerdown dismissed the row menu.',
    );

    // ----- The read-only wear bar. -----
    // Seam: the wear bar reads the root's reference layers regardless of
    // the drill. The part facet flows into VariantArea's readOnly
    // posture (the area stays mounted, never remounts), so the bar
    // still names the button that supplies every "from Button" mark
    // beneath, without the whole-element gestures: the row carries no
    // trigger (a plain reading, no picker), no add row, and the row menu
    // reduced to Outline Variant and Select wearer.
    $this->assertJsCondition(
      '(() => { const bar = document.querySelector(' . json_encode($wear_bar) . ');'
      . " if (!bar || !bar.classList.contains('daedalus-stylish-wear-bar--read-only')) { return false; }"
      . " const row = bar.querySelector('.daedalus-stylish-wear-bar__row'); if (!row) { return false; }"
      . " return row.querySelector('.daedalus-stylish-wear-bar__label')?.textContent.trim() === 'Style'"
      . " && row.querySelector('span.daedalus-stylish-wear-bar__plain .daedalus-stylish-wear-bar__variant-label')?.textContent.trim() === 'Button'"
      . " && !row.querySelector('.daedalus-stylish-wear-bar__trigger') && !row.querySelector('button')"
      . " && !bar.querySelector('.daedalus-stylish-wear-bar__add'); })()",
      20000,
      'Read-only wear bar: drilled to the link, the bar wears its read-only class and its Style row names Button as a span with the target disabled, no picker trigger and no add row: the root\'s wearing anchors the part\'s marks without the whole-element gestures.',
    );
    $session->executeScript(
      '(() => { const row = document.querySelector(' . json_encode($wear_bar . ' .daedalus-stylish-wear-bar__row') . '); const rect = row.getBoundingClientRect();'
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true,"
      . ' clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2 })); })();'
    );
    $this->assertJsCondition(
      "document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item .daedalus-ui-menu__label').length === 2",
      20000,
      'Read-only wear bar: the right-click on the Style row opened the row menu with two items.',
    );
    $this->assertSame(
      ['Outline Variant', 'Select wearer'],
      json_decode((string) $session->evaluateScript(
        "JSON.stringify(Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item .daedalus-ui-menu__label')).map((label) => label.textContent.trim()))"
      ), TRUE),
      'Read-only wear bar: the row menu carries exactly Outline Variant and Select wearer (no swap, detach, push or edit on a drilled part).',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Select wearer').click();"
    );
    $this->assertJsCondition(
      "document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__address')?.dataset.daedalusStylishAddress === " . json_encode($block_c_key)
      . ' && (() => { const bar = document.querySelector(' . json_encode($wear_bar) . ');'
      . " return !!bar && !bar.classList.contains('daedalus-stylish-wear-bar--read-only')"
      . " && !!bar.querySelector('.daedalus-stylish-wear-bar__add'); })()"
      . " && !document.querySelector('.daedalus-ui-menu')",
      20000,
      'Read-only wear bar: Select wearer re-selected the whole element through the facade (the part crumb is gone, the bar is editable again with its add row, the menu closed).',
    );

    // ----- The element doorway under a write address. -----
    // Seam: originOf under `editable: address`. Seating an ancestor rule
    // as the styling target gives that rule's ancestor layer the
    // editable mark (style-stack.js), so a value the element itself
    // styles sits ABOVE the editable layer; the origin is then the
    // element's, its doorway `element`, and the advanced row's
    // useRowMenu dispatches it through source.select(key), the selection
    // write that clears the target. The advanced Sections outside a
    // session pass no sheet, so the row hands the hook no onWinner:
    // before this the origin emitted the session's winner doorway here
    // and the click threw on the null sheet. The node specs pin the
    // doorway and the dispatch; only a mounted row proves it reaches the
    // source and never a sheet.
    $this->commitStyle($block_c_key, 'padding', '1rem', '__styleErrorC');
    $section_c_rule_key = "container:$section_c_uuid::> pb-text";
    $padding_row = 'document.querySelector(' . json_encode($padding_input) . ").closest('.daedalus-stylish-inspector__property')";
    $address_chip = '#daedalus_stylish_inspector .daedalus-stylish-inspector__address';
    $session->executeScript("Drupal.Daedalus.Selection.address('container:$section_c_uuid', '> pb-text');");
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); const input = document.querySelector(" . json_encode($padding_input) . "); const row = $padding_row;"
      . ' if (!chip || !input || !row || chip.dataset.daedalusStylishAddress !== ' . json_encode($section_c_rule_key) . ') { return false; }'
      . " return chip.classList.contains('daedalus-stylish-chip--lit')"
      . " && input.value === '1'"
      . " && row.querySelector('.daedalus-stylish-inspector__mark')?.title === '1rem from this element'; })()",
      20000,
      'Element doorway: with the section\'s `> pb-text` rule seated as the write address (the chip lit at its address, the selection and its bare-element subject unchanged) the Padding row keeps the element\'s own 1rem (olivero\'s open law renders the free field: the number in the input, rem on its select), its mark titled "1rem from this element": the own block sits above the address\'s editable layer and the origin is the element\'s.',
    );
    $session->executeScript(
      "(() => { const row = $padding_row; const rect = row.getBoundingClientRect();"
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: rect.left + 8, clientY: rect.top + rect.height / 2 })); })()"
    );
    $menu_items_js = "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".map((item) => [item.querySelector('.daedalus-ui-menu__label')?.textContent.trim(), item.querySelector('.daedalus-ui-menu__hint')?.textContent.trim() ?? null])";
    $this->assertJsCondition(
      "(() => { const items = $menu_items_js; if (!document.querySelector('.daedalus-ui-menu') || items.length === 0) { return false; }"
      . " return items.some(([label, hint]) => label === 'Edit on the element' && hint === 'drops the address; writes land on the element')"
      . " && !items.some(([label]) => label === 'Go to element'); })()",
      20000,
      'Element doorway: a right-click on the Padding row offers "Edit on the element" (hinted that it drops the address) and never the session\'s "Go to element".',
    );
    $errors_js = "JSON.parse(sessionStorage.getItem('js_testing_log_test.errors') || '[]').length";
    $errors_before = (int) $session->evaluateScript($errors_js);
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Edit on the element').click();"
    );
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); const row = $padding_row;"
      . " return !document.querySelector('.daedalus-ui-menu')"
      . ' && Drupal.Daedalus.Selection.getSelected() === ' . json_encode($block_c_key)
      . ' && Drupal.Daedalus.Selection.getTarget() === null'
      . ' && Drupal.Daedalus.Selection.getAddress() === null'
      . ' && !!chip && chip.dataset.daedalusStylishAddress === ' . json_encode($block_c_key)
      . " && !chip.classList.contains('daedalus-stylish-chip--lit')"
      . " && !!row && (() => { const mark = row.querySelector('.daedalus-stylish-inspector__mark');"
      . " return !!mark && mark.title === '1rem set here' && mark.classList.contains('daedalus-stylish-inspector__mark--here'); })(); })()",
      20000,
      'Element doorway: the pick dropped the address through the source (address(null): the block still selected, no facet, no address, the chip unlit on the own address) and the Padding row\'s mark reads "1rem set here", filled: its 1rem is the editable layer\'s own at the pose.',
    );
    $this->assertSame(
      $errors_before,
      (int) $session->evaluateScript($errors_js),
      'Element doorway: the pick logged no error (the advanced row outside a session hands the hook no sheet; the doorway rides the source, never sheet.onWinner).',
    );

    // ----- A generated box seated from the chip, styled through a row. -----
    // Seam: the chip's "+ After" doorway (subject-model.js boxAddresses,
    // a seat and never a write) -> the selection spine's target at the
    // box (`&::after`, a rule facet the advanced Sections mount for: the
    // box's derived roster, the host's groups plus Generated content) ->
    // the Background color row's settle committing ONE update_style at
    // the box key -> the op spine's birth seed landing `content: ""`
    // beneath it in the same op -> the mirror replacement -> the printed
    // `:scope::after` inside the element's @scope landing on a generated
    // box, plus two facts only a browser has: the selection paint's
    // `:scope::after` outline computes on the pseudo-element itself (no
    // node, no rect; the browser's own geometry for it) and the seeded
    // content is what makes the box exist at all. The node specs pin the
    // doorway, the address set and the roster; the kernel tier pins the
    // seed and the print; no lower tier sees a generated box.
    $box_key = "$block_c_key::&::after";
    $after_js = "getComputedStyle(document.querySelector(\"[data-selectable='$block_c_key']\"), '::after')";
    $selected_paint_js = "document.querySelector('style[data-daedalus-stylish-selector-paint=\"selected\"]')";
    $background_color_input = "#daedalus_stylish_inspector details.daedalus-stylish-inspector__section input[aria-label='Background color']";
    // The editing compound is Base (the drive doorway returned the pose
    // before the reload and nothing posed it since), so the row's write
    // lands at default, where the seed lives.
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-chip--width');"
      . " return !!chip && !chip.classList.contains('daedalus-stylish-chip--lit') && chip.textContent.trim().startsWith('Base'); })()",
      20000,
      'Generated box: the width chip reads Base, unlit: the row below writes at default.',
    );
    $this->clickPanelXpath('//button[contains(@class, "daedalus-stylish-inspector__address")]');
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu') && document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')?.textContent.trim() === 'Style this at'",
      20000,
      'Generated box: the chip opened the shared menu headed "Style this at".',
    );
    // Read as [label, hint, aria-checked] triples (the element doorway's
    // $menu_items_js above reads pairs; the checked state matters here).
    $box_menu_js = "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".map((item) => [item.querySelector('.daedalus-ui-menu__label')?.textContent.trim(), item.querySelector('.daedalus-ui-menu__hint')?.textContent.trim() ?? null, item.getAttribute('aria-checked')])";
    $box_menu = json_decode((string) $session->evaluateScript("JSON.stringify($box_menu_js)"), TRUE);
    $this->assertSame(['This element', NULL, 'true'], $box_menu[0], 'Generated box: the menu leads with the own key, checked.');
    $this->assertSame(
      [['+ Before', NULL, 'false'], ['+ After', NULL, 'false'], ['Copy key', NULL, NULL]],
      array_slice($box_menu, -3),
      'Generated box: the address set ends with the element\'s two box doorways, unhinted and unchecked (seat only), and the menu closes with "Copy key" after the separator (the subject word\'s copy gesture lives here now, unchecked: not an address). Items: ' . json_encode($box_menu),
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === '+ After').click();"
    );
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu')"
      . ' && Drupal.Daedalus.Selection.getSelected() === ' . json_encode($block_c_key)
      . ' && Drupal.Daedalus.Selection.getTarget()?.key === ' . json_encode($block_c_key)
      . " && Drupal.Daedalus.Selection.getTarget()?.selector === '&::after'"
      . " && (() => { const chip = document.querySelector('$address_chip'); return !!chip"
      . '   && chip.dataset.daedalusStylishAddress === ' . json_encode($box_key)
      . "   && chip.querySelector('.daedalus-stylish-inspector__address-label')?.textContent.trim() === 'After'"
      . "   && !chip.classList.contains('daedalus-stylish-chip--lit'); })()",
      20000,
      'Generated box: the pick closed the menu and seated the element\'s own `&::after` as the styling target (the selection unmoved), the chip facing "After" at block::&::after, unlit (the own box is no ancestor\'s rule).',
    );
    $this->assertJsCondition(
      '!' . $this->mapJs($box_key),
      20000,
      'Generated box: the seat wrote nothing: the map holds no entry at the box key (the doorway seats, the first write births).',
    );
    $this->assertJsCondition(
      "(() => { const text = $selected_paint_js?.textContent ?? '';"
      . ' return text.includes(' . json_encode('@scope ([data-selectable="' . $block_c_key . '"])') . ")"
      . " && /\\n  :scope::after \\{\\n    outline: 2px dashed/.test(text); })()",
      20000,
      'Generated box: the selection paint slot holds the box\'s @scope rule with the printer\'s own `:scope::after` target in the dashed facet treatment.',
    );
    $this->assertJsCondition(
      "($after_js).outlineStyle === 'dashed' && ($after_js).content === 'none'",
      20000,
      'Generated box: the paint computes on the pseudo-element itself (a dashed outline the browser resolves with no node behind it) while no box exists yet (content none).',
    );
    // The box's advanced roster: the host's groups plus Generated
    // content. Background arrives closed (nothing delivers at a box, no
    // skin value either) and opens by a real summary click; Generated
    // content arrives closed too, its Content row waiting for the seed.
    $this->assertSectionOpen('Background', FALSE, 'Generated box: the Background section rendered for the box, closed (nothing delivers there yet).');
    $this->assertSectionOpen('Generated content', FALSE, 'Generated box: the Generated content section rendered for the box (the pseudo-only group rides the derived grant), closed.');
    $this->clickPanelXpath('//details[contains(@class, "daedalus-stylish-inspector__section")]/summary[normalize-space() = "Background"]');
    $this->assertSectionOpen('Background', TRUE, 'Generated box: the summary click opened the Background section.');
    $this->assertNotNull(
      $this->assertSession()->waitForElement('css', $background_color_input),
      'Generated box: the Background color row renders its free text input (olivero\'s open law).',
    );
    // Typing previews; the blur settles: ONE update_style at the box key
    // (the native setter plus a real input event is a keystroke's wiring,
    // the blur a later tick, like a user's).
    $session->executeScript(
      '(() => { const input = document.querySelector(' . json_encode($background_color_input) . ');'
      . " const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;"
      . " setter.call(input, '#ff0000'); input.dispatchEvent(new Event('input', { bubbles: true })); })();"
    );
    $session->executeScript('document.querySelector(' . json_encode($background_color_input) . ").dispatchEvent(new FocusEvent('blur'));");
    $this->assertJsCondition(
      $this->mapJs($box_key) . "?.default?.['background-color'] === '#ff0000'",
      20000,
      'Generated box: the row\'s settle committed background-color: #ff0000 at block::&::after, minting the box\'s entry.',
    );
    $this->assertSame(
      ['default' => ['content' => '""', 'background-color' => '#ff0000']],
      json_decode((string) $session->evaluateScript('JSON.stringify(' . $this->mapJs($box_key) . ')'), TRUE),
      'Generated box: the entry was born with the op spine\'s seed, `content: ""` at default beneath the typed background (one write, two lines: the seed rides the same op).',
    );
    $this->assertSame(
      '1rem',
      $session->evaluateScript($this->mapJs($block_c_key) . '?.default?.padding ?? null'),
      'Generated box: the host\'s own entry is untouched by the write at its box.',
    );
    $this->assertJsCondition(
      "($after_js).content === '\"\"' && ($after_js).backgroundColor === 'rgb(255, 0, 0)' && ($after_js).outlineStyle === 'dashed'",
      20000,
      'Generated box: the canvas materializes the box without reload: the seeded content makes it exist, the row\'s red paints it, and the selection paint outlines the generated box itself.',
    );
    $this->assertSectionOpen('Generated content', TRUE, 'Generated box: the Generated content section arrived open on the seeded value (the values signal).');
    $this->assertJsCondition(
      "document.querySelector(\"#daedalus_stylish_inspector details.daedalus-stylish-inspector__section [aria-label='Content']\")?.value === '\"\"'",
      20000,
      'Generated box: the Content row reads the seed, `""`, the spelling the validator accepts.',
    );

    // ----- A generated box carries an image through its Content row. -----
    // Seam: the Content row's media chip (inspector.js controlFor: any
    // `definition.media` row wears the library opener; `content` carries
    // the flag on a keyword-typed definition, so the row offers its
    // keywords and the opener and never the gradient half, which
    // widget-values.js imageSlot withholds) -> the REAL media library
    // modal (the opener URL the sidebar embedded, core's AJAX dialog)
    // -> the selection returning as ONE daedalusStylishMediaLibrarySelection
    // command carrying the semantic token -> the leader's pickMedia
    // committing ONE update_style at the box key with the token as the
    // whole `content` value (the advanced row seeds no companions) ->
    // MediaValueResolver substituting the file URL in the printed
    // `:scope::after` -> the browser computing `url("…")` as the
    // pseudo-element's content. GradientBehaviorTest locks the modal on
    // `background-image`; this is the second consumer of the one media
    // seam, and only a browser sees the modal and the computed pseudo.
    // The media is minted server-side mid-scenario: the library UI is an
    // AJAX fetch, so the modal lists it without any reload.
    $generated_content_js = $this->sectionJs('Generated content');
    $this->assertJsCondition(
      "(() => { const section = $generated_content_js; return !!section"
      . " && !!section.querySelector('.daedalus-stylish-inspector__media-button')"
      . " && !section.querySelector('.daedalus-stylish-inspector__gradient-opener')"
      . " && !section.querySelector('.daedalus-stylish-inspector__palette-picker'); })()",
      20000,
      'Content media: the Content row offers the media library chip and no gradient opener or palette grid (the offer follows the type; the flag adds the picker).',
    );
    $image_files = $this->getTestFiles('image');
    $image_file = File::create(['uri' => reset($image_files)->uri]);
    $image_file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Box icon',
      'field_media_image' => [
        ['target_id' => $image_file->id(), 'alt' => 'Box icon'],
      ],
    ]);
    $media->save();
    $media_token = "url('media:" . strtolower($media->uuid()) . "')";
    $file_name = basename($image_file->getFileUri());

    // The chip opens the modal inside the same click. Select the minted
    // media by its id (demo content ships media too, so index 0 is not
    // ours) and insert.
    $this->clickPanelXpath('//details[contains(@class, "daedalus-stylish-inspector__section")][summary[normalize-space() = "Generated content"]]//button[contains(@class, "daedalus-stylish-inspector__media-button")]');
    $media_checkbox = $assert_session->waitForElement(
      'css',
      ".js-media-library-view .js-click-to-select-checkbox input[value='" . $media->id() . "']",
      20000,
    );
    $this->assertNotNull($media_checkbox, 'Content media: the media library modal opened from the Content row offering the minted image media.');
    $media_checkbox->check();
    $assert_session->elementExists('css', '.ui-dialog-buttonpane')->pressButton('Insert selected');

    // The seam: the committed map after the op settles. The token is the
    // whole `content` value, the seed it replaced gone, the row's own
    // background line untouched, nothing else minted (no companions).
    $this->assertJsCondition(
      $this->mapJs($box_key) . '?.default?.content === ' . json_encode($media_token),
      20000,
      'Content media: the pick committed the media token as the box\'s whole `content` value at block::&::after.',
    );
    $box_entry = json_decode((string) $session->evaluateScript('JSON.stringify(' . $this->mapJs($box_key) . ')'), TRUE);
    $this->assertSame(['default'], array_keys($box_entry), 'Content media: the pick wrote at default alone (the width chip read Base).');
    $this->assertSame('#ff0000', $box_entry['default']['background-color'], 'Content media: the box\'s own background line survived the pick at its sibling property.');
    $this->assertCount(2, $box_entry['default'], 'Content media: the pick landed the token alone beside the background (the advanced row seeds no companions).');
    // The canvas: the pseudo computes the file URL as its content, the
    // resolver having substituted the token (no image style is set on
    // this site, so the original file's URL prints, ending in its name),
    // the row's red still painting it and the paint still outlining it.
    $this->assertJsCondition(
      "(() => { const content = ($after_js).content; return content.startsWith('url(\"')"
      . ' && content.endsWith(' . json_encode($file_name . '")') . ')'
      . " && ($after_js).backgroundColor === 'rgb(255, 0, 0)' && ($after_js).outlineStyle === 'dashed'; })()",
      20000,
      'Content media: the generated box computes the picked file\'s URL as its content (MediaValueResolver in the printed :scope::after), still red and still outlined.',
    );
    // The row's face: the chip wears the picked media's thumbnail (the
    // leader extended its uuid -> URL table from the selection response
    // and the row reads the committed token's first uuid through it).
    $this->assertJsCondition(
      "!!($generated_content_js)?.querySelector('.daedalus-stylish-inspector__media-button .daedalus-stylish-inspector__media-thumbnail')",
      20000,
      'Content media: the Content row\'s media chip wears the picked media\'s thumbnail.',
    );

    // ----- The advanced row's mark and label share a center. -----
    // Seam: a real layout fact. The property line lays its mark and its
    // label in one label cell (inspector.css __property-line,
    // align-items: center, the basic widget row's rule); before this the
    // line aligned on a baseline synthesized from a textless first input
    // (a color well, a palette button), so the mark's center and the
    // label's text center met only by accident, row by row. Every
    // visible line on the panel as it stands (the box's Generated
    // content section open over a color well, a media chip and
    // dimension fields), the dimension lines' scrub label and the other
    // lines' property label alike, measured in ONE evaluateScript so
    // every rect comes from the same layout pass; lines inside a closed
    // section have no box and are skipped.
    $centering = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const lines = [...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-inspector__property-line')];
        const deltas = lines.map((line) => {
          const mark = line.querySelector('.daedalus-stylish-inspector__mark');
          const label = line.querySelector('.daedalus-stylish-inspector__property-label, .daedalus-stylish-dimension__label');
          if (!mark || !label) { return null; }
          const a = mark.getBoundingClientRect();
          const b = label.getBoundingClientRect();
          if (a.height === 0 || b.height === 0) { return null; }
          return Math.abs((a.top + a.height / 2) - (b.top + b.height / 2));
        }).filter((delta) => delta !== null);
        return JSON.stringify({ lines: lines.length, measured: deltas.length, max: deltas.length ? Math.max(...deltas) : null });
      })()
      JS), TRUE);
    $this->assertGreaterThan(0, $centering['measured'], 'Row centering: the panel renders visible property lines with a mark and a label to measure. Lines: ' . $centering['lines'] . ', measured: ' . $centering['measured'] . '.');
    $this->assertLessThanOrEqual(1.0, $centering['max'], 'Row centering: on every visible advanced property line the mark\'s vertical center and the label\'s vertical center coincide within a pixel (the line centers its items; no synthesized baseline). Measured ' . $centering['measured'] . ' lines, largest delta ' . $centering['max'] . 'px.');
  }

}
