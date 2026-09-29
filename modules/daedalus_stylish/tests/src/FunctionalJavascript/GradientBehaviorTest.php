<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\FunctionalJavascript;

use Drupal\file\Entity\File;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\media\Entity\Media;
use Drupal\daedalus_ui\ElementModel\ElementResolver;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\TestFileCreationTrait;
use Drupal\node\Entity\Node;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the basic-mode color widgets' gradient capability on a real page.
 *
 * The capability under test: the Background and Text color ControlWidgets
 * are gradient-capable. Gradient-kind swatches offer as painted stops in
 * both widgets' swatch-well popovers (the palette renders behind the
 * well, a face button wearing the row's current value; picking closes
 * the popover again), a gradient chip opens the shared GradientBuilder
 * popover for custom authoring, and gradient text is a direct widget
 * batch (the recipe: background-image gradient + background-clip: text
 * + color: transparent, all three in ONE update_style op). Every step
 * here locks a WIRING seam that node tests (the pure models in
 * widget-gradients.js) and kernel tests (validation, printing) cannot
 * see, because it only exists where the widget DOM, the op queue,
 * tempstore, and the rendered canvas meet in a browser:
 * - Stop pick round trip: clicking the gradient stop on Background commits
 *   var(--swatch-*) through the op queue into tempstore and the mutation
 *   response's re-render restyles the canvas WITHOUT a reload — the
 *   swatch's custom property resolving through the compiled swatch file on
 *   the token scope element (pick → op → tempstore → render, four layers
 *   that only agree in a browser).
 * - Custom gradient via the chip popover: the builder's input previews and
 *   its blur settle commit a literal gradient through the same pipeline.
 * - The text recipe lands as ONE compound batch: a single blueprint POST
 *   carries all three properties into the map entry together.
 * - Unpick clears all three: the recipe state is the only lit-stop state,
 *   and its unpick unsets exactly the set trio.
 * - Collision honesty both directions: each widget renders the honest
 *   custom pill by reading the OTHER widget's occupancy of
 *   background-image from the live map entry (the background widget reads
 *   the recipe's marker from the posed entry — textRecipeOccupies).
 * - Field-root recipe on the strict guide: the same batch lands on an
 *   inline-block FIELD key (block:{uuid}:{field}) under strict validation,
 *   sanctioned by the daedalus_edit baseline's field-root background group.
 *   Selection is driven programmatically (Selection.select) and the
 *   assertions are the map entry plus the emitted @scope CSS, NOT a
 *   computed style: the formatted text block renders collapsed, so no
 *   stamped field-root element exists on the page (validation-true,
 *   visually inert here; an earlier theme smoke test found the same shape).
 * - The text-claim co-target resolves in the real cascade: the scenario's
 *   skin fixture lands a DIRECT base:a link color on a linked text
 *   block's claiming anchor (the interposer the co-target exists to
 *   beat), a root color committed through the basic widget must then WIN
 *   there via the zero-weight companion, and the gradient-text recipe on
 *   the same linked block must SPLIT across two elements (box properties
 *   on the root, color: transparent riding the marker onto the anchor)
 *   for the glyphs to paint. Kernel tests pin the resolver stamp and the
 *   emitted CSS text; only a browser resolves who wins, and where each
 *   of the recipe's three properties lands.
 *
 * The scenario then back-fills the builder + palette capability that was
 * previously verified only by browser smoke (the gradient builder and
 * edit-in-place work this build inherited):
 * - Track editing is real pointer wiring: a click on empty track adds a
 *   stop wearing a neighbor-sampled color, a pointer-captured drag crosses
 *   another stop and reorders the array to visual order (the selection
 *   following the dragged stop), and the settle serializes the explicit
 *   percent positions.
 * - Save-as-swatch mints site-wide from the builder: the commit becomes
 *   the var() reference, and the delivery pair (the response's materials
 *   delta seating the record in the store, plus the leader source's
 *   custom-property stamp) resolves the fresh custom property on the
 *   canvas and grows every widget's stop vocabulary, all in the same
 *   document.
 * - Palette apply on a second element: the fresh swatch is pickable
 *   vocabulary on a widget that mounted after the mint, one POST like any
 *   stop pick.
 * - Edit-in-place over a worn swatch: with the swatch reference committed,
 *   the same chip gates into the update session over the swatch's OWN
 *   value; its preview restyles a SECOND wearer live (the source's
 *   previewSwatch seam, before any commit), Discard restores the stored value
 *   everywhere, and Update persists one revisioned entity save that
 *   restyles every wearer for good while the wears keep holding the
 *   reference.
 * - Strict-site swatch authoring from the palette card: strict governs
 *   consumers, never authors, so the palette's mint gesture (the color
 *   kind's picker driving the shared draft, Save submitting the
 *   save_material op) persists a LITERAL color while the guide is strict,
 *   and the fresh swatch joins the widget vocabulary in the same
 *   document. The sanctioned extension point staying open is a wiring
 *   fact only the strict-posture browser scenario can lock.
 * - Basic media pick commits the seeded companion batch: the media chip's
 *   pick lands the semantic token PLUS the best-guess companions (size
 *   cover / repeat no-repeat / position center, computed at the click and
 *   threaded through the pickMedia payload) in ONE update_style op (one
 *   POST, the whole family arriving in the map entry together), and the
 *   lit media stop's unpick clears the whole family, not just the token.
 * - Kind switches replace, never bury: a media pick over a worn color
 *   ships the replacement null in the same batch (mediaPickReplacements),
 *   and a color pick over the image clears the seeded companion family
 *   with it (the pick-clears-family growth of backgroundPick).
 *   The layers that only meet in a browser: the real media library modal
 *   round trip (opener URL, AJAX dialog, selection command) feeding the
 *   seed-carrying commit through the op queue into the mirror.
 * - Scale-constrained padding commits the token reference: with block
 *   padding closed over the site's space scale (the test-module layer)
 *   and the skin minting the tokens, the basic padding row must render
 *   the CLOSED field whose select offers the MINTED SCALE as display
 *   values (the built-in table displaced), a select pick must commit
 *   the var(--space-*) REFERENCE (the strict scale rule's only legal
 *   spelling, never the value the face displays) through the same op
 *   round trip, and the canvas's computed padding must equal the
 *   token's literal — the value-space derivation, the payload's minted
 *   tokens, the strict validator, and the compiled skin file's
 *   token-scope custom property only agree in a browser.
 *   Representative-of-class for every scale-constrained dimension row
 *   (the color twin is locked by the palette steps above).
 * - A site constraint_defaults binding closes a row no node constrains:
 *   the test module binds border-radius to the space scale in the SITE
 *   declaration only, and the section's Corners row must still close
 *   over the minted tokens (guide payload constraintDefaults → the
 *   client's resolved constraint read → the value space → the choice
 *   well's listbox) with the built-in radius table displaced. The defaults
 *   channel crossing payload build, model resolution, and widget DOM is
 *   the seam; node tests pin each hop in isolation.
 * Satellite parity stays smoke-only: a second WebDriver window is heavy,
 * and the satellite wiring is pure client over the same seams.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site, as
 * daedalus_editor) wearing a scenario skin in place of the recipe's
 * olivero (the skin is this scenario's interposer and its minted space
 * scale; see the test method). The skin rides a system the test builds
 * in code (the trait's activateSkin, what a site builder creating a
 * system in the UI produces), and the test authors that system's mode
 * as STRICT, the closed world every commit below passes: the recipe's
 * olivero ships open, and no test forces a mode onto a shipped guide,
 * so the law here is the scenario's own system's. One fixture, one
 * scenario: a full site install from the recipe is by far the most
 * expensive part of a run (the framework installs and tears down per
 * method), so all behavior lives in the single test method.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class GradientBehaviorTest extends WebDriverTestBase {

  use DesignSystemActivationTrait;
  use EditModeTrait;
  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }
  use TestFileCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The scale-constraint fixture layer: the daedalus baseline is
   * deliberately curated-but-unconstrained, so the scale-constrained
   * dimension property the padding step needs (a site space token scale
   * plus a `scale:` constraint on block padding) rides a test-module
   * declaration over it, the way a deployment's theme layer would. The
   * same layer authors the site's constraint_defaults (border-radius →
   * the space scale, node-authored nowhere) for the offer-vocabulary
   * step. The token VALUES stay skin data, minted by the scenario skin
   * below.
   */
  protected static $modules = ['daedalus_stylish_scale_constraint_test'];

  /**
   * {@inheritdoc}
   *
   * The recipe's editor role carries the swatch authoring pair alongside
   * the variants pair (daedalus_editor_roles), so the mint,
   * edit-in-place, and palette-card steps below run on the shipped grant
   * list — this suite is part of the proof that the list is sufficient.
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
  }

  /**
   * Scrolls a panel element into view and really clicks it.
   *
   * The inspector is a scrollable right-rail panel, so a widget row can sit
   * below its fold; centering it first keeps the click a REAL pointer click
   * (the wiring under test) instead of a synthetic dispatch.
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
   * Opens a color widget's swatch-well palette popover.
   *
   * The palette is an open vocabulary behind the well (the swatch-well
   * redesign): stop chips exist only inside the OPEN popover, and every
   * pick closes it again, so each stop interaction starts here. Any
   * OTHER widget's open popover is closed first through its own well
   * with a synthetic toggle click: closing reflows the panel (the
   * popover is in-flow), which would stale a real pointer click's
   * coordinates mid-gesture; the real click below then lands on a
   * stable panel, keeping the well toggle itself the wiring under test.
   */
  private function openWell(string $widget): void {
    $this->getSession()->executeScript(
      "document.querySelectorAll('.daedalus-stylish-swatch-well--open').forEach((well) => well.click());"
    );
    $this->clickPanel("$widget .daedalus-stylish-swatch-well");
    $this->assertNotNull(
      $this->assertSession()->waitForElement('css', '.daedalus-ui-popover .daedalus-stylish-swatch-popover'),
      sprintf('The swatch-well popover opened on %s.', $widget),
    );
  }

  /**
   * Runs a lane inside a color widget's well popover.
   *
   * The well IS the control: every door to a value the grid cannot offer
   * (the gradient builder, the media library) is a self-labelled lane
   * inside the popover, and the lane's own surface takes over from a
   * CLOSED popover inside the same click (usePalettePopover `lanes`), so
   * each lane gesture starts by opening the well.
   */
  private function openLane(string $widget, string $lane): void {
    $this->openWell($widget);
    $this->clickPanel(".daedalus-ui-popover .daedalus-stylish-palette-lane--$lane");
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-swatch-popover')",
      20000,
      sprintf('The %s lane closed the well popover on %s.', $lane, $widget),
    );
  }

  /**
   * Closes a widget's open swatch-well popover (the well's own toggle).
   *
   * A real click is safe here: the popover floats on the shared surface
   * under body, so its close never reflows the panel under the pointer.
   */
  private function closeWell(string $widget): void {
    $this->clickPanel("$widget .daedalus-stylish-swatch-well--open");
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-swatch-popover')",
      20000,
      'The swatch-well popover closed.',
    );
  }

  /**
   * Types into a Preact-controlled panel input, without settling it.
   *
   * The native value setter plus a real input event is the same wiring a
   * keystroke runs. Deliberately NO settle here: the builder's settle
   * closes over the RENDERED state, so typing and settling in one
   * synchronous breath would settle the pre-input state. A caller must
   * wait for the state echo (a strip repaint, a canvas restyle) and only
   * then dispatch the blur in a LATER call, like a real user's separate
   * tick (blurPanel).
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
   * A color input's settle event is 'change', not blur: the native wheel
   * fires it with the final value when it closes, so dispatching it after
   * typeIntoPanel's input event runs exactly the picker path's own wiring.
   */
  private function changePanel(string $selector): void {
    $this->getSession()->executeScript(
      'document.querySelector(' . json_encode($selector) . ").dispatchEvent(new Event('change', { bubbles: true }));"
    );
  }

  /**
   * Gradient picks, the chip popover, the text recipe batch, and unpick.
   *
   * One fixture (the recipe-installed site, strict) drives the whole
   * gradient capability through the real widgets on a real page:
   * - a gradient swatch renders as a stop in the Background widget and its
   *   pick restyles the canvas without reload (pick → op → tempstore →
   *   render round trip),
   * - the worn background gradient makes the Text color widget honestly
   *   custom (collision, direction one), and unpick clears it,
   * - the chip popover commits a custom literal gradient,
   * - a Text color gradient pick lands the 3-property recipe as ONE
   *   compound batch, makes the Background widget honestly custom
   *   (collision, direction two), and its unpick clears all three,
   * - the same recipe lands on an inline-block field root under the strict
   *   guide, asserted on the map entry and the emitted @scope CSS,
   * - the builder's track edits for real (click-to-add samples neighbors,
   *   a captured drag crosses, reorders, and serializes positions),
   * - save-as-swatch mints site-wide and the canvas resolves the fresh
   *   var() without reload, the new stop appearing in both widgets,
   * - the swatch applies from the palette on a SECOND element, whose
   *   wear then restyles live under the first element's edit-in-place
   *   session (Update persists to the entity, Discard restores),
   * - the palette card mints a literal-color swatch under the SAME strict
   *   guide (the picker path, then the save_material op) and the vocabulary
   *   grows without a reload,
   * - the basic media pick lands the token plus the seeded companions
   *   (cover / no-repeat / center) as ONE compound batch through the real
   *   media library modal, and the lit media stop's unpick clears the
   *   whole family,
   * - a linked text block proves the text-claim co-target in the real
   *   cascade: unstyled, the skin fixture's base:a rule owns the claiming
   *   anchor; a root color pick through the basic widget then wins on the
   *   claimed glyphs at zero weight,
   * - the gradient-text recipe on that same linked block splits across
   *   two elements (box properties stay on the root while color:
   *   transparent rides the claim marker onto the anchor), which only a
   *   browser can resolve,
   * - a scale-constrained padding pick commits the minted var(--space-*)
   *   reference and the canvas's computed padding equals the token's
   *   literal (the skin-tracking dimension vocabulary, end to end),
   * - the section's Corners row (the choice well over the minted space
   *   tokens) derives from the site declaration's constraint_defaults
   *   binding (border-radius → the space scale, node-authored nowhere),
   *   displacing the built-in radius table,
   * - a push the server refuses (the role loses "create variants" after
   *   the page loaded) toasts once in the document-level messenger through
   *   push bar → op queue → reportFailure, the session stays open, and no
   *   inline strip renders,
   * - and none of it ever touches the saved node (staged in tempstore).
   */
  public function testGradientCapabilityScenario(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // The palette fixture: one published gradient swatch, created BEFORE the
    // page loads so the client guide payload carries it and the compiled
    // swatch stylesheet delivers its custom property. It is what renders as
    // the gradient stop in both widgets.
    $swatch = DaedalusStylishSwatch::create(['label' => 'Hero fade', 'kind' => 'gradient']);
    $swatch->setSwatchValue('linear-gradient(135deg, #e94560, #16213e)')->save();
    $this->assertSame('hero_fade', $swatch->getMachineName(), 'The swatch minted as --swatch-hero_fade.');

    // The skin fixture: an active skin whose one base:a default is the
    // co-target steps' interposer, the DIRECT link-color rule the compiled
    // skin file lands on every content anchor
    // (:where(.daedalus-stylish-token-scope) :where(a)). Created and activated
    // BEFORE the page loads so the skin file is linked into the document
    // under test; it displaces the recipe's olivero, whose base:a is a
    // token the co-target steps could not tell from "some color landed",
    // and without a literal interposer they would never prove who WINS.
    $skin = DaedalusStylishSkin::create([
      'label' => 'Scenario skin',
      'machine_name' => 'scenario_skin',
      'status' => TRUE,
    ]);
    // The skin also MINTS the space scale the test-module layer declares
    // (code declares the scale, the skin mints the values — the code/data
    // split): three tokens, delivered as --space-{name} custom properties
    // on the token scope by the compiled skin file, which is what the
    // padding step's committed var() must resolve through.
    $skin->setTokens([
      'space' => ['2' => '0.5rem', '4' => '1rem', '8' => '2rem'],
    ]);
    $skin->setDefaults([
      'base:a' => ['' => ['default' => ['color' => '#881144']]],
    ])->save();
    // The skin's system is the test's own (built in code around the
    // skin), so its mode is the test's to author: strict, the closed
    // world the scenario's commits (the field-root recipe especially)
    // are exercised against, which is the claim. The recipe's olivero
    // ships open and is not forced; the law here is this system's.
    $this->activateSkin($skin);
    $this->enforce('strict');
    $this->assertSame(
      'scenario_skin',
      \Drupal::service('daedalus_stylish.skin_manager')->activeSkinName(),
      'The scenario skin activated: its base:a link color is the interposer the co-target steps beat.',
    );
    $this->assertSame(
      'strict',
      \Drupal::service('daedalus_stylish.style_guide')->enforcement(),
      'The scenario skin\'s own system is in strict enforcement: the scenario runs against the closed world.',
    );

    // A real Layout Builder page, the surface the widgets operate on. The
    // recipe enables styling for the page bundle, so the node's
    // daedalus_stylish_styles field hosts the style map for the whole layout tree.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Gradient behavior page',
    ]);
    $node->save();
    $nid = (int) $node->id();

    $this->drupalGet($node->toUrl());

    // A WIDE window on purpose: the editing compound follows the measured
    // stage width (the container-query canvas), and a squeezed stage would
    // land every write condition-keyed under `narrow` instead of `default`.
    // 1920 keeps the stage above every band ceiling with the sidebar open.
    $session->resizeWindow(1920, 1200);

    // Enter Edit Mode the way the recipe starts a real page: the
    // server-rendered cookie path, which renders the full Edit Mode chrome
    // (the inspector sidebar, every tool's JS, the blueprint mirror boot).
    $this->enterEditModeViaCookies($node->toUrl());
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted.',
    );

    // Place a section with a formatted text block through the same mutation
    // batch a palette drop enqueues — the block kind binds BOTH color
    // widgets (Background and Text color), which the collision steps need
    // on one element.
    $uuid_service = \Drupal::service('uuid');
    $section_uuid = $uuid_service->generate();
    $component_uuid = $uuid_service->generate();
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
              uuid: '$component_uuid',
              block_type: 'formatted_text',
              properties: {
                field_formatted_text_text: {
                  value: '<p>Gradient canvas text</p>',
                  format: 'daedalus_edit_formatted_text',
                },
              },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__placementError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__placementError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$component_uuid')",
      20000,
      'The formatted text block placement resolved.',
    );
    $placement_error = $session->evaluateScript('window.__placementError ?? null');
    $this->assertNull($placement_error, 'Formatted text block placement failed: ' . ($placement_error ?? ''));

    $block_key = "block:$component_uuid";
    $field_key = "block:$component_uuid:field_formatted_text_text";
    $canvas = "[data-selectable='$block_key']";
    $composed = $assert_session->waitForElement('css', $canvas);
    $this->assertNotNull($composed, 'The formatted text block rendered on the canvas.');

    // Two scenario-wide sentinels. The reload sentinel proves every round
    // trip below happened in THIS document (the "without reload" half of the
    // round-trip seam); the POST counter is how "ONE compound batch" is a
    // wiring fact rather than an inference from the map shape.
    $session->executeScript(<<<JS
      window.__sameDocument = true;
      window.__blueprintPosts = 0;
      const originalFetch = window.fetch;
      window.fetch = function (url, options) {
        if (String(url).includes('daedalus-blueprint') && options && options.method === 'POST') {
          window.__blueprintPosts++;
        }
        return originalFetch.apply(this, arguments);
      };
      JS);

    // The inspector owns the right rail's top slot and is default-open; the
    // cookie entry path can render it either way depending on the sidebar
    // cookie state, so open it explicitly (the toggle's own API) before
    // asserting on its widgets.
    $assert_session->waitForElement('css', '#daedalus_stylish_inspector');
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
      'The Styles inspector (right/top) is open.',
    );

    // Select the block programmatically — the facade is the sanctioned
    // programmatic face of a canvas click, and the panel follows the
    // committed selection. Both color widgets must render, each offering
    // the swatch's gradient stop inside its swatch-well popover (the
    // payload-derived vocabulary reaching the widget DOM; the palette
    // renders behind the well, so the offer only exists while open).
    $bg = '#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--background';
    $text = '#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--text-color';
    $stop = "button[aria-label='Hero fade']";
    // The palette chips live in the open well's popover, portaled onto the
    // shared floating surface under body (never the widget's subtree); one
    // popover is open per window, so the surface's chips are the well's
    // that openWell() just opened.
    $chips = '.daedalus-ui-popover .daedalus-stylish-swatch-popover';
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_key');");
    $this->openWell($bg);
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$chips $stop"),
      'The Background widget offers the gradient swatch as a stop in its palette popover.',
    );
    $this->openWell($text);
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$chips $stop"),
      'The Text color widget offers the gradient swatch as a stop in its palette popover.',
    );

    // The map read every step below asserts against: the daedalus_stylish_styles
    // field of the root entity's blueprint mirror — the same authority the
    // canvas re-renders from, replaced by every mutation response.
    $entry = "(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles ?? {})[" . json_encode($block_key) . "]";

    // ——— Gradient stop pick on Background: the full round trip. ———
    // A real click on the stop commits var(--swatch-hero_fade) to
    // background-image through the op queue; the mutation response lands it
    // in the mirror's map entry and swaps the canvas subtree, whose
    // computed background resolves the swatch var() through the compiled
    // swatch file on the token scope element. Four layers, one gesture, no
    // reload.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->openWell($bg);
    $this->clickPanel("$chips $stop");
    $this->assertJsCondition(
      "$entry?.default?.['background-image'] === 'var(--swatch-hero_fade)'",
      20000,
      'The stop pick committed the swatch var() reference into the map entry (op → tempstore → mirror).',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas\")).backgroundImage.includes('linear-gradient')",
      20000,
      'The canvas restyled without reload: the swatch var() resolved to the gradient on the re-rendered block.',
    );
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The stop pick was exactly one blueprint POST.',
    );
    $this->assertJsCondition(
      "!!document.querySelector(\"$bg .daedalus-stylish-swatch-well--stop\")",
      20000,
      'The well wears the picked stop\'s face (the pick closed the popover).',
    );

    // ——— Collision honesty, direction one. ———
    // The Text color widget reads background-image's occupancy from the
    // same live map entry: a background gradient under no text recipe is a
    // partial from its perspective, so its well wears the honest custom
    // face and no stop face.
    $this->assertJsCondition(
      "(() => { const w = document.querySelector(\"$text\");"
      . " return !!w.querySelector('.daedalus-stylish-swatch-well--custom')"
      . " && !w.querySelector('.daedalus-stylish-swatch-well--stop'); })()",
      20000,
      'With a background gradient worn, the Text color well wears the honest custom face (collision, direction one).',
    );

    // ——— Unpick on Background clears what the pick wrote. ———
    // Reopening the well lights the worn stop (aria-selected, the grid's
    // listbox state) for the unpick.
    // Clicking the lit stop unsets background-image (the only set member of
    // the widget's pair — the corners rule: clear only what is set).
    $this->openWell($bg);
    $this->assertJsCondition(
      "document.querySelector(\"$chips $stop\")?.getAttribute('aria-selected') === 'true'",
      20000,
      'The reopened popover lit the worn gradient stop (aria-selected).',
    );
    $this->clickPanel("$chips $stop");
    $this->assertJsCondition(
      "($entry?.default?.['background-image'] ?? null) === null",
      20000,
      'Unpicking the background stop cleared background-image from the map entry.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas\")).backgroundImage === 'none'",
      20000,
      'The canvas background restored on unpick.',
    );
    $this->assertJsCondition(
      "!document.querySelector(\"$text .daedalus-stylish-swatch-well--custom\")",
      20000,
      'The Text color widget\'s custom face cleared with the collision gone.',
    );

    // ——— Custom gradient via the well's gradient lane. ———
    // The lane opens the shared GradientBuilder popover (gradient-chip.js,
    // the media rows' wiring reaching the widgets); typing a stop color
    // previews per input and the blur settle commits the SERIALIZED literal
    // through the widget's ordinary pick path — background-image only, per
    // the builder-commit contract on this widget.
    $this->openLane($bg, 'gradient');
    $this->assertNotNull(
      $assert_session->waitForElement('css', '.daedalus-stylish-gradient__stop-color'),
      'The gradient builder popover opened from the well\'s gradient lane.',
    );
    // Typed input runs in two ticks (typeIntoPanel/blurPanel): the strip's
    // repaint is the deterministic "the state took the color" signal
    // between them.
    $stop_color = '.daedalus-stylish-gradient__stop-color';
    $this->typeIntoPanel($stop_color, '#ff8800');
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector('.daedalus-stylish-gradient__strip')).backgroundImage.includes('255, 136, 0')",
      20000,
      'The builder previewed the typed stop color (the track strip repainted).',
    );
    $this->blurPanel($stop_color);
    $this->assertJsCondition(
      "(() => { const image = $entry?.default?.['background-image'] ?? '';"
      . " return image.startsWith('linear-gradient(') && image.includes('#ff8800'); })()",
      20000,
      'The popover commit landed the custom gradient LITERAL (not a var()) in the map entry.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas\")).backgroundImage.includes('255, 136, 0')",
      20000,
      'The canvas wears the custom gradient (the typed stop color resolved in the computed background).',
    );
    // Close the builder (its own close control) so the panel is back to
    // the row chrome.
    $this->clickPanel("$bg .daedalus-stylish-gradient__close");
    $this->assertJsCondition(
      "!document.querySelector(\"$bg .daedalus-stylish-gradient\")",
      20000,
      'The builder popover closed.',
    );

    // ——— The text recipe lands as ONE compound batch. ———
    // A gradient stop pick on Text color commits background-image +
    // background-clip: text + color: transparent in ONE update_style op:
    // one POST, all three properties arriving in the map entry together
    // (replacing the custom background literal — three positive writes).
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->openWell($text);
    $this->clickPanel("$chips $stop");
    $this->assertJsCondition(
      "(() => { const e = $entry?.default ?? {};"
      . " return e['background-image'] === 'var(--swatch-hero_fade)'"
      . " && e['background-clip'] === 'text'"
      . " && e['color'] === 'transparent'; })()",
      20000,
      'The text gradient pick landed the full 3-property recipe in the map entry.',
    );
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The recipe was ONE compound batch: a single blueprint POST carried all three properties.',
    );
    $this->assertJsCondition(
      "(() => { const s = getComputedStyle(document.querySelector(\"$canvas\"));"
      . " return s.backgroundClip === 'text' && s.color === 'rgba(0, 0, 0, 0)'"
      . " && s.backgroundImage.includes('linear-gradient'); })()",
      20000,
      'The canvas wears gradient text: clipped background, transparent color, gradient image.',
    );
    $this->assertJsCondition(
      "!!document.querySelector(\"$text .daedalus-stylish-swatch-well--stop\")",
      20000,
      'The recipe state lit the Text color well with the stop\'s face.',
    );

    // ——— Collision honesty, direction two. ———
    // The Background widget does not own background-clip, so it reads the
    // recipe's occupancy from the posed map entry (textRecipeOccupies): the
    // marker makes the pair custom — no lit stop it could offer a
    // destructive unpick from.
    $this->assertJsCondition(
      "(() => { const w = document.querySelector(\"$bg\");"
      . " return !!w.querySelector('.daedalus-stylish-swatch-well--custom')"
      . " && !w.querySelector('.daedalus-stylish-swatch-well--stop'); })()",
      20000,
      'With the text recipe worn, the Background well wears the honest custom face (collision, direction two).',
    );

    // ——— Unpick clears all three. ———
    // The lit stop is only reachable in the exact-recipe state, so its
    // unpick unsets exactly the set trio, leaving the entry bare and the
    // canvas restored.
    $this->openWell($text);
    $this->assertJsCondition(
      "document.querySelector(\"$chips $stop\")?.getAttribute('aria-selected') === 'true'",
      20000,
      'The reopened popover lit the worn recipe stop (aria-selected).',
    );
    $this->clickPanel("$chips $stop");
    $this->assertJsCondition(
      "(() => { const e = $entry?.default ?? {};"
      . " return !('background-image' in e) && !('background-clip' in e) && !('color' in e); })()",
      20000,
      'Unpicking the text gradient cleared all three recipe properties from the map entry.',
    );
    $this->assertJsCondition(
      "(() => { const s = getComputedStyle(document.querySelector(\"$canvas\"));"
      . " return s.backgroundClip !== 'text' && s.backgroundImage === 'none'; })()",
      20000,
      'The canvas restored on recipe unpick.',
    );
    $this->assertJsCondition(
      "!document.querySelector(\"$bg .daedalus-stylish-swatch-well--custom\")",
      20000,
      'The Background widget\'s custom face cleared with the recipe gone.',
    );

    // ——— The field-root recipe on the strict guide. ———
    // Inline-block fields key as block:{uuid}:{field} and are selectable
    // programmatically (synthetic canvas clicks never move selection). The
    // field KIND binds Text color but no Background control, and its root
    // carries the baseline's background group — the sanction that makes the
    // recipe batch strict-legal at a field root. The formatted text block
    // renders collapsed (no stamped field-root element exists), so the
    // honest assertions are the map entry and the emitted @scope CSS, not a
    // computed style.
    $session->executeScript("Drupal.Daedalus.Selection.select('$field_key');");
    $this->assertJsCondition(
      "(() => { return !!document.querySelector(\"$text .daedalus-stylish-swatch-well\")"
      . " && !document.querySelector(\"$bg\"); })()",
      20000,
      'The field-root selection rendered Text color (field kind) and no Background widget.',
    );
    $field_entry = "(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles ?? {})[" . json_encode($field_key) . "]";
    $this->openWell($text);
    $this->clickPanel("$chips $stop");
    $this->assertJsCondition(
      "(() => { const e = $field_entry?.default ?? {};"
      . " return e['background-image'] === 'var(--swatch-hero_fade)'"
      . " && e['background-clip'] === 'text'"
      . " && e['color'] === 'transparent'; })()",
      20000,
      'The recipe batch landed on the FIELD key under the strict guide.',
    );
    $this->assertJsCondition(
      "(() => { const scope = '[data-selectable=' + JSON.stringify(" . json_encode($field_key) . ") + ']';"
      . " return [...document.querySelectorAll('style[data-daedalus-stylish-entity]')].some((tag) =>"
      . " tag.textContent.includes(scope)"
      . " && tag.textContent.includes('background-clip: text;')"
      . " && tag.textContent.includes('var(--swatch-hero_fade)')); })()",
      20000,
      'The field-root recipe emitted as an @scope block in the entity\'s inline CSS.',
    );

    // ═════ Back-fill: the builder + palette capability. ═════
    // Everything below was previously verified only by browser smoke; it
    // appends to the SAME scenario because the fixture (a full install
    // plus the recipe apply) dwarfs every step, so the builder and
    // palette seams ride the setUp already paid for.
    // Back to the block: its entry is bare again after the recipe unpick,
    // so the Background well's gradient lane opens the BUILDER posture (no
    // committed swatch reference to gate the edit-in-place session on)
    // over a fresh palette-seeded gradient.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_key');");
    $this->openLane($bg, 'gradient');
    $this->assertNotNull(
      $assert_session->waitForElement('css', '.daedalus-stylish-gradient__track'),
      'The builder popover opened with its stops track.',
    );

    // Pin both seed stops to known colors first: the seed comes from the
    // site's own palette, and the sampling assertion below needs
    // deterministic neighbors (#ff0000 to #0000ff). Each blur settle
    // commits through the widget's ordinary pick path.
    $this->typeIntoPanel($stop_color, '#ff0000');
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector('.daedalus-stylish-gradient__strip')).backgroundImage.includes('255, 0, 0')",
      20000,
      'The builder previewed the first pinned stop color.',
    );
    $this->blurPanel($stop_color);
    $this->assertJsCondition(
      "($entry?.default?.['background-image'] ?? '').includes('#ff0000')",
      20000,
      'The first stop color settled into the committed gradient.',
    );
    // A real click on the other handle moves the track selection
    // (trackDown selects it; the release settles the unchanged stops).
    $this->clickPanel('.daedalus-stylish-gradient__handle[data-index="1"]');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-stylish-gradient__handle--selected')?.dataset.index === '1'",
      20000,
      'Clicking the second handle took the track selection.',
    );
    $this->typeIntoPanel($stop_color, '#0000ff');
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector('.daedalus-stylish-gradient__strip')).backgroundImage.includes('0, 0, 255')",
      20000,
      'The builder previewed the second pinned stop color.',
    );
    $this->blurPanel($stop_color);
    $this->assertJsCondition(
      "(() => { const image = $entry?.default?.['background-image'] ?? '';"
      . " return image.includes('#0000ff') && image.indexOf('#ff0000') < image.indexOf('#0000ff'); })()",
      20000,
      'Both pinned stop colors settled, in track order.',
    );

    // ——— Click-track add with a neighbor-sampled color. ———
    // A click on empty track adds a stop AT the click, colored by
    // interpolating the neighbors' face paints. The dispatch carries the
    // exact midpoint so the sample is deterministic: #ff0000 to #0000ff
    // mixes to #800080 at 50%. One discrete gesture, committed like a
    // select pick.
    $session->executeScript(<<<'JS'
      (() => {
        const track = document.querySelector('.daedalus-stylish-gradient__track');
        const rect = track.getBoundingClientRect();
        track.dispatchEvent(new MouseEvent('click', {
          bubbles: true,
          clientX: rect.left + rect.width / 2,
          clientY: rect.top + rect.height / 2,
        }));
      })();
      JS);
    $this->assertJsCondition(
      "(() => { const image = $entry?.default?.['background-image'] ?? '';"
      . " return document.querySelectorAll('.daedalus-stylish-gradient__handle').length === 3"
      . " && image.includes('#800080 50%')"
      . " && image.indexOf('#ff0000') < image.indexOf('#800080'); })()",
      20000,
      'The track click added and committed a 50% stop wearing the neighbor-sampled mix (#800080).',
    );

    // ——— Drag a stop: pointer capture, crossing reorder, position. ———
    // A REAL pointer drag (WebDriver actions): grab the first handle
    // (#ff0000, at 0%) and release it over the last one (at 100%). The
    // handle captures the pointer (trackDown), the crossing reorders the
    // array to visual order with the selection following the dragged stop
    // (moveStop), and the settle serializes the explicit percent: array
    // order IS visual order, never CSS's silent render clamping. The
    // capture is also why the release's compat click cannot add a stop.
    $page = $session->getPage();
    $first_handle = $page->find('css', '.daedalus-stylish-gradient__handle[data-index="0"]');
    $last_handle = $page->find('css', '.daedalus-stylish-gradient__handle[data-index="2"]');
    $this->assertNotNull($first_handle, 'The first stop handle renders.');
    $this->assertNotNull($last_handle, 'The last stop handle renders.');
    $first_handle->dragTo($last_handle);
    $this->assertJsCondition(
      "(() => { const image = $entry?.default?.['background-image'] ?? '';"
      . " const dragged = image.match(/#ff0000 (\\d+(?:\\.\\d+)?)%/);"
      . " return dragged !== null && Number(dragged[1]) >= 90"
      . " && image.indexOf('#800080') < image.indexOf('#ff0000'); })()",
      20000,
      'The drag crossed and reordered: the dragged stop settled AFTER the 50% stop, wearing its explicit serialized percent.',
    );
    $this->assertJsCondition(
      "document.querySelectorAll('.daedalus-stylish-gradient__handle').length === 3"
      . " && document.querySelector('.daedalus-stylish-gradient__handle--selected')?.title === '#ff0000'",
      20000,
      'The selection followed the dragged stop across the crossing, and the captured release added no stop.',
    );

    // ——— Save-as-swatch from the builder. ———
    // Naming the gradient and saving mints it site-wide; the edited
    // layer's slot becomes the var() reference. The delivery pair (the
    // materials delta seating the record in the store, plus the leader
    // source's custom-property stamp) must resolve the fresh custom
    // property on the canvas AND grow the widget vocabulary in THIS
    // document; no reload anywhere near.
    $this->typeIntoPanel('.daedalus-stylish-gradient__mint-name', 'Molten brass');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-stylish-gradient__mint-save')?.disabled === false",
      20000,
      'Naming the swatch armed the save gesture.',
    );
    $this->clickPanel('.daedalus-stylish-gradient__mint-save');
    $this->assertJsCondition(
      "$entry?.default?.['background-image'] === 'var(--swatch-molten_brass)'",
      20000,
      'The mint committed the fresh swatch reference in the literal\'s place.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas\")).backgroundImage.includes('128, 0, 128')",
      20000,
      'The canvas resolved the freshly minted var() without a reload (the materials delta + the custom-property stamp).',
    );
    $this->assertJsCondition(
      "(() => { return !document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-gradient')"
      . " && !!document.querySelector(\"$bg .daedalus-stylish-swatch-well--stop\"); })()",
      20000,
      'The mint closed the builder popover and the well wears the fresh swatch stop.',
    );
    $this->openWell($bg);
    $this->assertJsCondition(
      "document.querySelector(\"$chips button[aria-label='Molten brass']\")?.getAttribute('aria-selected') === 'true'",
      20000,
      'The minted swatch joined the widget vocabulary live, lit as the worn stop.',
    );
    $this->closeWell($bg);

    // ——— Palette apply on a second element. ———
    // A second block through the same mutation batch. The minted swatch
    // must already be pickable vocabulary on its widget (which mounted
    // AFTER the mint, still without a reload), and its pick is the same
    // one-POST op round trip as any stop pick.
    $second_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_element',
              uuid: '$second_uuid',
              block_type: 'formatted_text',
              properties: {
                field_formatted_text_text: {
                  value: '<p>Second wearer</p>',
                  format: 'daedalus_edit_formatted_text',
                },
              },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__secondPlacementError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__secondPlacementError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$second_uuid')",
      20000,
      'The second formatted text block placement resolved.',
    );
    $second_error = $session->evaluateScript('window.__secondPlacementError ?? null');
    $this->assertNull($second_error, 'Second block placement failed: ' . ($second_error ?? ''));
    $second_key = "block:$second_uuid";
    $second_canvas = "[data-selectable='$second_key']";
    $second_entry = "(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles ?? {})[" . json_encode($second_key) . "]";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $second_canvas),
      'The second block rendered on the canvas.',
    );
    $session->executeScript("Drupal.Daedalus.Selection.select('$second_key');");
    $molten = "$chips button[aria-label='Molten brass']";
    $this->openWell($bg);
    $this->assertJsCondition(
      "(() => { const stop = document.querySelector(\"$molten\");"
      . " return !!stop && stop.getAttribute('aria-selected') !== 'true'; })()",
      20000,
      'The second element\'s Background widget offers the minted swatch, unworn.',
    );
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickPanel($molten);
    $this->assertJsCondition(
      "$second_entry?.default?.['background-image'] === 'var(--swatch-molten_brass)'",
      20000,
      'The palette apply committed the swatch reference on the second element.',
    );
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The palette apply was exactly one blueprint POST.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$second_canvas\")).backgroundImage.includes('128, 0, 128')",
      20000,
      'The second element wears the swatch gradient.',
    );

    // ——— Edit-in-place session over the worn swatch. ———
    // Back on the first element the committed value is exactly the swatch
    // reference, so the SAME lane now gates into the update session over
    // the swatch's OWN value. Its preview rides the previewSwatch seam:
    // every wearer (the second element included) restyles live while the
    // session edits, Discard restores the stored value, and Update
    // persists one revisioned save that restyles every wearer for good.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_key');");
    $this->openLane($bg, 'gradient');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-stylish-gradient--session .daedalus-stylish-gradient__session-name')?.textContent === 'Molten brass'",
      20000,
      'The lane opened the edit-in-place session over the worn swatch (session chrome wearing its identity).',
    );
    $this->typeIntoPanel($stop_color, '#22cc88');
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$second_canvas\")).backgroundImage.includes('34, 204, 136')",
      20000,
      'The session preview restyled the SECOND wearer live, before any commit.',
    );
    $this->clickPanel('.daedalus-stylish-gradient__session-bar .daedalus-ui-pill-button:not(.daedalus-stylish-gradient__session-update)');
    $this->assertJsCondition(
      "(() => { return getComputedStyle(document.querySelector(\"$second_canvas\")).backgroundImage.includes('128, 0, 128')"
      . " && !document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-gradient'); })()",
      20000,
      'Discard restored the stored swatch value on every wearer and closed the session.',
    );
    $this->openLane($bg, 'gradient');
    $this->assertNotNull(
      $assert_session->waitForElement('css', '.daedalus-stylish-gradient--session'),
      'The lane reopened the session posture.',
    );
    $this->typeIntoPanel($stop_color, '#ffaa00');
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$second_canvas\")).backgroundImage.includes('255, 170, 0')",
      20000,
      'The second session\'s preview reached the second wearer.',
    );
    $this->clickPanel('.daedalus-stylish-gradient__session-update');
    $this->assertJsCondition(
      "!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-gradient')",
      20000,
      'Update swatch committed and closed the session.',
    );
    $this->assertJsCondition(
      "(() => { return getComputedStyle(document.querySelector(\"$canvas\")).backgroundImage.includes('255, 170, 0')"
      . " && getComputedStyle(document.querySelector(\"$second_canvas\")).backgroundImage.includes('255, 170, 0'); })()",
      20000,
      'Both wearers restyled to the updated swatch value without a reload.',
    );
    $this->assertJsCondition(
      "$entry?.default?.['background-image'] === 'var(--swatch-molten_brass)'"
      . " && $second_entry?.default?.['background-image'] === 'var(--swatch-molten_brass)'",
      20000,
      'The wears still hold the reference, not a value: the update rode the swatch, the live link held.',
    );
    // The update was a real revisioned save: the swatch ENTITY carries the
    // edited value (swatch writes are site content, never tempstore).
    $swatch_storage = \Drupal::entityTypeManager()->getStorage('daedalus_swatch');
    $swatch_storage->resetCache();
    $minted_swatches = $swatch_storage->loadByProperties(['machine_name' => 'molten_brass']);
    $minted = reset($minted_swatches);
    $this->assertNotFalse($minted, 'The minted swatch persisted as a site-wide entity.');
    $minted_value = $minted->getSwatchValue();
    $this->assertIsString($minted_value, 'The builder minted a plain string swatch value.');
    $this->assertStringContainsString(
      '#ffaa00',
      $minted_value,
      'Update swatch persisted the edited value to the entity.',
    );

    // ═════ Strict-site swatch authoring from the palette card. ═════
    // Strict governs CONSUMERS, never authors: the palette card is the
    // sanctioned extension point the strict posture funnels people
    // toward, so minting a LITERAL color there must work with the guide
    // strict (asserted at the top of the scenario). The seam is the mint
    // gesture's wiring end to end (the picker input driving the shared
    // draft, Save submitting the save_material op through the op queue,
    // and the delivery extension growing the vocabulary in THIS
    // document): layers that node tests (the pure editors) and kernel
    // tests (the op) never see meet.
    // To the Materials place's Palette section: the chrome row's Materials
    // toggle, then the catalog segments (Variants, Palette), then the
    // "+ New swatch" gesture (offered: the editor holds the recipe-shipped
    // "create swatches" grant, the human authoring gate).
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__place');
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__catalog .daedalus-ui-segment:nth-child(2)');
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-palette__mint-open');
    $this->assertNotNull(
      $assert_session->waitForElement('css', '.daedalus-stylish-palette-mint'),
      'The "+ New swatch" gesture opened the mint card.',
    );

    // The color kind's editor. The mint card seeds from the guide
    // payload's first kind (server data, no contract on its order), so
    // pick 'color' explicitly through the kind select's own change
    // wiring when it landed elsewhere.
    $session->executeScript(<<<'JS'
      (() => {
        const select = document.querySelector('.daedalus-stylish-palette-mint__identity select');
        if (select && select.value !== 'color') {
          const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
          setter.call(select, 'color');
          select.dispatchEvent(new Event('change', { bubbles: true }));
        }
      })();
      JS);
    $picker = '.daedalus-stylish-palette-mint .daedalus-stylish-native-picker';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $picker),
      'The color kind mounted its value editor (the shared color field): the hidden native picker behind the face popover\'s color lane.',
    );

    // The picker path, in the two-tick idiom: the input event previews
    // into the shared draft (the free-text input echoing the literal and
    // the face repainting from payload data are the state echo), then the
    // change event settles it: the same event pair the native wheel
    // fires.
    $this->typeIntoPanel($picker, '#3366cc');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-stylish-palette-mint .daedalus-stylish-color-field__text')?.value === '#3366cc'"
      . " && getComputedStyle(document.querySelector('.daedalus-stylish-palette-mint .daedalus-stylish-color-field__face')).backgroundColor === 'rgb(51, 102, 204)'",
      20000,
      'The picker input drove the shared draft: the text input echoed the literal and the face repainted.',
    );
    $this->changePanel($picker);

    // Name it and save: the palette card's mint gesture, one save_material
    // op through the op queue, then the card closes and the list grows the
    // row.
    $this->typeIntoPanel('.daedalus-stylish-palette-mint .daedalus-stylish-library-card__label', 'Ember coral');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-stylish-palette-mint .daedalus-stylish-library-card__action')?.disabled === false",
      20000,
      'Label plus value armed the Save swatch gesture.',
    );
    $this->clickPanel('.daedalus-stylish-palette-mint .daedalus-stylish-library-card__action');
    $this->assertJsCondition(
      "(() => { return !document.querySelector('.daedalus-stylish-palette-mint')"
      . " && [...document.querySelectorAll('.daedalus-stylish-palette .daedalus-stylish-library__name')]"
      . ".some((name) => name.textContent === 'Ember coral'); })()",
      20000,
      'The mint landed: the card closed and the palette list grew the new swatch row.',
    );

    // The confirmation rides the notify seam to the edit-mode messenger
    // (source.notify → the edit plugin's message → Drupal.Message): the
    // kind-aware status toast lands in the page's message display, not any
    // panel-local strip. Asserted inside the status toast's display window.
    $this->assertJsCondition(
      "[...document.querySelectorAll('[data-drupal-messages] [data-drupal-message-id]')]"
      . ".some((message) => message.getAttribute('data-drupal-message-type') === 'status'"
      . " && message.textContent.includes('Added the Ember coral color to the color palette.'))",
      20000,
      'The mint confirmation landed in the edit-mode message display (notify → messenger).',
    );

    // The literal persisted as a site-wide entity UNDER STRICT: the
    // server accepted a plain hex, never demanding a var() reference,
    // because authoring is not strict-gated.
    $swatch_storage->resetCache();
    $ember_swatches = $swatch_storage->loadByProperties(['machine_name' => 'ember_coral']);
    $ember = reset($ember_swatches);
    $this->assertNotFalse($ember, 'The palette mint persisted as a site-wide entity (machine name from the label).');
    $this->assertSame('color', $ember->getKind(), 'The mint carried the color kind.');
    $this->assertSame('#3366cc', $ember->getSwatchValue(), 'The strict site accepted the LITERAL color value.');

    // And it is spendable vocabulary in the same document: back in the
    // styling place through the Basic mode segment (a mode click always
    // lands there), the selection's Background widget offers the fresh
    // color stop unworn (the materials delta grew the store; no reload
    // anywhere near, and the sentinel below still guards).
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__ui-mode .daedalus-ui-segment:nth-child(1)');
    $this->openWell($bg);
    $this->assertJsCondition(
      "(() => { const stop = document.querySelector(\"$chips button[aria-label='Ember coral']\");"
      . " return !!stop && stop.getAttribute('aria-selected') !== 'true'; })()",
      20000,
      'The minted color joined the widget vocabulary live, offered unworn.',
    );
    $this->closeWell($bg);

    // ═════ Basic media pick: the seeded companion batch. ═════
    // The well's image lane is the curated basic gesture: its pick computes the
    // best-guess companions at the click (mediaCompanions) and threads
    // them through the pickMedia payload, so the leader commits token +
    // companions as ONE update_style op and the common case never tiles.
    // Nothing in this element's effective entry and no skin layer answers
    // size/repeat/position here, so all three seed. The media entity is
    // minted server-side mid-scenario: the library UI is an AJAX fetch,
    // so the modal lists it without any reload (the sentinel guards).
    $image_files = $this->getTestFiles('image');
    $image_file = File::create(['uri' => reset($image_files)->uri]);
    $image_file->save();
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Companion hero',
      'field_media_image' => [
        ['target_id' => $image_file->id(), 'alt' => 'Companion hero'],
      ],
    ]);
    $media->save();
    $media_token = json_encode("url('media:" . strtolower($media->uuid()) . "')");

    // The lane opens the REAL media library modal (the opener URL the
    // sidebar embedded, core's AJAX dialog) from the closed well popover,
    // inside the same click. Select the minted media by its id (demo
    // content ships media too, so index 0 is not ours) and insert: the
    // selection returns as ONE daedalusStylishMediaLibrarySelection command
    // carrying the semantic token, routed to the waiting picker.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->openLane($bg, 'image');
    $media_checkbox = $assert_session->waitForElement(
      'css',
      ".js-media-library-view .js-click-to-select-checkbox input[value='" . $media->id() . "']",
      20000,
    );
    $this->assertNotNull($media_checkbox, 'The media library modal opened offering the minted image media.');
    $media_checkbox->check();
    $assert_session->elementExists('css', '.ui-dialog-buttonpane')->pressButton('Insert selected');

    // The seam: the committed map after the op settles. The token and all
    // three companions arrived TOGETHER (replacing the worn swatch
    // reference), and the batch was one POST.
    $this->assertJsCondition(
      "(() => { const e = $entry?.default ?? {};"
      . " return e['background-image'] === $media_token"
      . " && e['background-size'] === 'cover'"
      . " && e['background-repeat'] === 'no-repeat'"
      . " && e['background-position'] === 'center'; })()",
      20000,
      'The media pick committed the token plus all three seeded companions in the map entry.',
    );
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The seeded pick was ONE compound batch: a single blueprint POST carried token and companions.',
    );
    // The user-visible half of the seed: the canvas paints the image
    // covered, not tiled (the browser defaults the seed exists to avoid).
    $this->assertJsCondition(
      "(() => { const s = getComputedStyle(document.querySelector(\"$canvas\"));"
      . " return s.backgroundImage.includes('url(') && !s.backgroundImage.includes('gradient')"
      . " && s.backgroundSize === 'cover' && s.backgroundRepeat === 'no-repeat'; })()",
      20000,
      'The canvas wears the picked image with the seeded companions applied (cover, no-repeat).',
    );
    $this->assertJsCondition(
      "!!document.querySelector(\"$bg .daedalus-stylish-swatch-well--media\")",
      20000,
      'The well wears the media face (the synthetic media stop, never the checker custom).',
    );

    // ——— Unpick clears the whole family. ———
    // The committed token joins the grid as the synthetic media stop,
    // lit; its toggle-off unpick clears every set member of the grown
    // family (pair + companions), leaving the entry bare. Companions are
    // inert without an image, so the clear keeps the map clean.
    $this->openWell($bg);
    $media_stop = "$chips button[aria-label='Media image']";
    $this->assertJsCondition(
      "document.querySelector(\"$media_stop\")?.getAttribute('aria-selected') === 'true'",
      20000,
      'The reopened popover lit the worn media stop (aria-selected).',
    );
    $this->clickPanel($media_stop);
    $this->assertJsCondition(
      "(() => { const e = $entry?.default ?? {};"
      . " return !('background-image' in e) && !('background-size' in e)"
      . " && !('background-repeat' in e) && !('background-position' in e); })()",
      20000,
      'Unpicking the media stop cleared the token AND the whole seeded companion family.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas\")).backgroundImage === 'none'",
      20000,
      'The canvas background restored on the family unpick.',
    );

    // ═════ Kind switches replace, never bury. ═════
    // The explicit-replacement rule over the grown family, both
    // directions. First wear a color so the media pick has something to
    // replace.
    $ember_stop = "$chips button[aria-label='Ember coral']";
    $this->openWell($bg);
    $this->clickPanel($ember_stop);
    $this->assertJsCondition(
      "$entry?.default?.['background-color'] === 'var(--swatch-ember_coral)'",
      20000,
      'The color stop pick committed the swatch reference.',
    );

    // ——— Media pick over the worn color: the replacement rides the batch. ———
    // mediaPickReplacements ships the background-color null in the SAME
    // one-op payload as the token and seeds (one POST), so the color is
    // replaced, never buried under the image as a both-set custom
    // compound.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->openLane($bg, 'image');
    $media_checkbox = $assert_session->waitForElement(
      'css',
      ".js-media-library-view .js-click-to-select-checkbox input[value='" . $media->id() . "']",
      20000,
    );
    $this->assertNotNull($media_checkbox, 'The media library modal reopened for the pick over the color.');
    $media_checkbox->check();
    $assert_session->elementExists('css', '.ui-dialog-buttonpane')->pressButton('Insert selected');
    $this->assertJsCondition(
      "(() => { const e = $entry?.default ?? {};"
      . " return e['background-image'] === $media_token"
      . " && e['background-size'] === 'cover'"
      . " && !('background-color' in e); })()",
      20000,
      'The media pick landed token plus seeds AND cleared the worn color (explicit replacement).',
    );
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'Token, seeds, and the replacement null rode ONE blueprint POST.',
    );
    $this->assertJsCondition(
      "!!document.querySelector(\"$bg .daedalus-stylish-swatch-well--media\")",
      20000,
      'The well wears the media face, not the buried-color custom checker.',
    );

    // ——— Color pick over the image: the pick clears the companion family. ———
    // The pick-clears-family decision: a color stop's represented state
    // carries no size/repeat/position, so the pick nulls the image AND
    // the seeded companions in one op, leaving no inert clutter behind.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->openWell($bg);
    $this->clickPanel($ember_stop);
    $this->assertJsCondition(
      "(() => { const e = $entry?.default ?? {};"
      . " return e['background-color'] === 'var(--swatch-ember_coral)'"
      . " && !('background-image' in e) && !('background-size' in e)"
      . " && !('background-repeat' in e) && !('background-position' in e); })()",
      20000,
      'The color pick replaced the image and cleared the whole seeded companion family.',
    );
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The family-clearing pick was ONE blueprint POST.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas\")).backgroundImage === 'none'",
      20000,
      'The canvas dropped the image on the kind switch.',
    );

    // ═════ The text-claim co-target: root styling through a link. ═════
    // A linked text block nests its glyphs inside an anchor that CLAIMS
    // the text field, and the skin's base:a default styles that anchor
    // DIRECTLY: a direct rule beats anything the block root hands down by
    // inheritance, so without the co-target "style it, link it" keeps the
    // size and loses the color. The fix is pure delivery: daedalus_ui
    // stamps the claiming anchor with its content-claim marker and every
    // daedalus_stylish printer co-targets the root entry's inherited text
    // properties onto the marker at zero weight. Kernel tests pin the
    // stamp and the emitted CSS text; the seam here is what no lower tier
    // can see: resolver stamp, printer emission, and the real browser
    // cascade against the compiled skin file, resolved to a winner.
    // Place a LINKED text block through the same mutation batch: the
    // anchor exists from birth, so the pre-style assertion below can show
    // the skin's link color winning on this exact markup, the negative
    // that gives the co-target win its teeth.
    $text_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_element',
              uuid: '$text_uuid',
              block_type: 'text',
              properties: {
                field_text_text: { value: 'Claimed glyphs' },
                field_text_url: { uri: 'https://example.com' },
              },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__textPlacementError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__textPlacementError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$text_uuid')",
      20000,
      'The linked text block placement resolved.',
    );
    $text_error = $session->evaluateScript('window.__textPlacementError ?? null');
    $this->assertNull($text_error, 'Linked text block placement failed: ' . ($text_error ?? ''));
    $text_key = "block:$text_uuid";
    $text_canvas = "[data-selectable='$text_key']";
    $text_entry = "(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles ?? {})[" . json_encode($text_key) . "]";

    // The resolver's stamp reached real canvas markup: the conditional
    // link part claims field:field_text_text, so its anchor carries the
    // content-claim marker. The attribute is daedalus_ui's anatomy
    // fact; reference the owning constant, never the string.
    $anchor = $text_canvas . ' a[' . ElementResolver::CLAIMS_CONTENT_ATTRIBUTE . ']';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $anchor),
      'The linked text block rendered its claiming anchor wearing the content-claim marker.',
    );

    // The negative that arms the win: unstyled, the skin owns the link.
    // No root style exists yet, so no companion CSS matches the marker
    // and the skin file's DIRECT base:a rule colors the glyphs while the
    // root keeps the theme's text color. This is the exact bug class the
    // co-target closes, shown live before the fix engages; kernel can
    // assert emitted CSS text, only the browser can assert a rule
    // WINNING.
    $this->assertJsCondition(
      "(() => { const a = document.querySelector(\"$anchor\");"
      . " return a !== null && getComputedStyle(a).color === 'rgb(136, 17, 68)'"
      . " && getComputedStyle(a.closest(\"$text_canvas\")).color !== 'rgb(136, 17, 68)'; })()",
      20000,
      'Unstyled, the skin base:a rule styles the anchor DIRECTLY (skin link color on the glyphs, not on the root): the interposer is real in this browser.',
    );

    // Root color follows the claim. The Ember coral stop minted above is
    // written at the block ROOT through the op spine: basic mode's text
    // color row addresses a linked block's anchor first (the link-fill
    // candidate rule, BasicStylingBehaviorTest's Background step), so
    // the widget is not the root's door here; the ONE write target is
    // the root entry, committing color: var(--swatch-ember_coral). The
    // companion rule rides the entity's inline map CSS at zero weight
    // and must WIN against base:a on the anchor.
    $session->executeScript("Drupal.Daedalus.Selection.select('$text_key');");
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{ op: 'update_style', key: '$text_key', properties: { color: 'var(--swatch-ember_coral)' } }],
        })).catch((error) => {
          window.__rootColorError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "$text_entry?.default?.['color'] === 'var(--swatch-ember_coral)'",
      20000,
      'The root color write committed the swatch reference to the block ROOT entry (one write target, no part fork).',
    );
    $this->assertJsCondition(
      "[...document.querySelectorAll('style[data-daedalus-stylish-entity]')].some((tag) =>"
      . " tag.textContent.includes(':scope :where([" . ElementResolver::CLAIMS_CONTENT_ATTRIBUTE . "])')"
      . " && tag.textContent.includes('var(--swatch-ember_coral)'))",
      20000,
      'The root entry emitted its co-target companion in the delivered inline map CSS.',
    );
    $this->assertJsCondition(
      "(() => { const a = document.querySelector(\"$anchor\");"
      . " return a !== null && getComputedStyle(a).color === 'rgb(51, 102, 204)'; })()",
      20000,
      'The root color WON on the claimed anchor: the zero-weight co-target beat the skin base:a direct rule in the real cascade.',
    );

    // The gradient-text recipe on the LINKED block: the split. A distinct
    // seam, not a variation of the win above: box properties never
    // co-target, so the recipe's three properties must land in TWO
    // different places for the glyphs to paint at all. background-image
    // and background-clip stay on the ROOT (the paint source) while only
    // the inherited color: transparent rides the marker onto the anchor;
    // without the companion, base:a would paint the glyphs opaque over
    // the clipped gradient. Kernel pins which rule carries which
    // property; only the browser proves where each one lands.
    $this->openWell($text);
    $this->clickPanel("$chips $stop");
    $this->assertJsCondition(
      "(() => { const e = $text_entry?.default ?? {};"
      . " return e['background-image'] === 'var(--swatch-hero_fade)'"
      . " && e['background-clip'] === 'text'"
      . " && e['color'] === 'transparent'; })()",
      20000,
      'The gradient text pick replaced the root color with the full 3-property recipe on the root entry.',
    );
    $this->assertJsCondition(
      "(() => { const root = getComputedStyle(document.querySelector(\"$text_canvas\"));"
      . " return root.backgroundClip === 'text'"
      . " && root.backgroundImage.includes('linear-gradient')"
      . " && root.color === 'rgba(0, 0, 0, 0)'; })()",
      20000,
      'The box half of the recipe landed on the ROOT: clipped gradient background, transparent color.',
    );
    $this->assertJsCondition(
      "(() => { const a = document.querySelector(\"$anchor\");"
      . " if (a === null) { return false; }"
      . " const s = getComputedStyle(a);"
      . " return s.backgroundImage === 'none' && s.backgroundClip !== 'text'"
      . " && s.color === 'rgba(0, 0, 0, 0)'; })()",
      20000,
      'The split resolved on the ANCHOR: no box property co-targeted (the background stays on the root) while color: transparent rode the marker past base:a, so the glyphs paint the root\'s clipped gradient.',
    );

    // ═════ Scale-constrained padding: the minted vocabulary, end to end. ═════
    // Block padding is closed over the site's space scale (the
    // daedalus_stylish_scale_constraint_test layer; the scenario skin minted the
    // tokens), so the basic padding row must BE the closed field over
    // the minted scale — a select offering display VALUES, the built-in
    // literal table displaced — and a pick must commit the var()
    // REFERENCE, the strict scale rule's only legal spelling, never the
    // value the face displays. The canvas then resolves the reference
    // through the compiled skin file's token-scope custom property: the
    // derivation, the payload's minted tokens, the strict validator, and
    // the skin stylesheet agreeing is the seam only a browser can see.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_key');");
    $spacing = '#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--spacing';
    $padding_select = "$spacing select[aria-label='Padding']";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $padding_select),
      'The scale-closed padding row renders as the closed field select.',
    );
    $this->assertJsCondition(
      '(() => { const options = Array.from(document.querySelectorAll(' . json_encode("$padding_select option") . '));'
      . " return options.some((option) => option.value === 'var(--space-4)' && option.textContent.trim() === '1rem')"
      . " && options.every((option) => option.value === '' || option.value.startsWith('var(--space-')); })()",
      20000,
      'The select offers ONLY the minted space scale, as display values (the 4 token faced as 1rem): the built-in table gave way.',
    );
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $session->executeScript(
      'document.querySelector(' . json_encode($padding_select) . ").scrollIntoView({ block: 'center' });"
    );
    $assert_session->elementExists('css', $padding_select)->selectOption('1rem');
    $this->assertJsCondition(
      "$entry?.default?.['padding'] === 'var(--space-4)'",
      20000,
      'The pick committed the token var() reference into the map entry — the skin-tracking spelling, accepted by the strict scale rule.',
    );
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The padding pick was exactly one blueprint POST.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas\")).padding === '16px'",
      20000,
      'The canvas resolved var(--space-4) through the compiled skin file: computed padding equals the token literal (1rem at the 16px root).',
    );
    $this->assertJsCondition(
      '(() => { const select = document.querySelector(' . json_encode($padding_select) . ');'
      . " const field = select.closest('.daedalus-stylish-dimension');"
      . " return select.selectedOptions[0]?.textContent.trim() === '1rem'"
      . " && !!field && field.classList.contains('daedalus-stylish-dimension--blessed'); })()",
      20000,
      'The committed reference wears its display value on the select face with the blessed chrome (reference-spelling matching).',
    );

    // ═════ Site constraint_defaults: the binding derives a row no node
    // constrains. ═════
    // border-radius carries NO per-node constraint anywhere in this
    // guide; the test module binds it to the space scale site-wide
    // through the site declaration's authored constraint_defaults, a
    // block that merges over the suite's own radius binding
    // (daedalus_stylish.page_builder.yml: scale radius, allowed 0), so the
    // resolved block is {scale: space, allowed: ['0']}. The section's
    // Corners row must therefore CLOSE over the minted scale plus that
    // one allowed literal: the payload's constraintDefaults map reaching
    // the widget through the client's resolved constraint read into the
    // value space, with the built-in radius table displaced as an OFFER
    // (its rows are gone) while its `None` label still faces the allowed
    // 0 as the literal's label twin (value-space.js: a curated literal
    // faces as its name beside its value). The surface is the spine's:
    // a closed dimension space earns chips or the well, never a select,
    // and the minted `0.5rem` face exceeds the chip budget, so the row is
    // the choice well whose listbox enumerates the three tokens as
    // `name · value` rows and the literal as `None · 0`. The defaults
    // channel crossing payload build, model resolution, and widget DOM in
    // one document is the seam; node and kernel tests pin each hop in
    // isolation.
    $session->executeScript("Drupal.Daedalus.Selection.select('container:$section_uuid');");
    $corners = '#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--corners-border';
    $corners_well = "$corners button.daedalus-stylish-choice-well[aria-label='Corners']";
    // The listbox rides the shared floating surface under body, never
    // the widget's own subtree; one popover is open per window, so the
    // surface's content is the Corners well's while it is up.
    $corners_options = '.daedalus-ui-popover .daedalus-stylish-choice-popover .daedalus-stylish-choice-list__option';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $corners_well),
      'The Corners row renders as the choice well through the site constraint_defaults binding.',
    );
    $this->assertNull(
      $session->getPage()->find('css', "$corners select[aria-label='Corners']"),
      'No closed select renders for Corners: the choice well IS the closed surface.',
    );
    $this->assertJsCondition(
      '(() => { const well = document.querySelector(' . json_encode($corners_well) . ');'
      . " return !!well && well.classList.contains('daedalus-stylish-choice-well--unset')"
      . " && well.getAttribute('aria-expanded') === 'false'; })()",
      20000,
      'The Corners well reads unset (no border-radius is written on the section) and closed.',
    );
    $this->clickPanel($corners_well);
    $this->assertNotNull(
      $assert_session->waitForElement('css', $corners_options),
      'Clicking the Corners well opens its listbox.',
    );
    $this->assertJsCondition(
      '(() => { const options = Array.from(document.querySelectorAll(' . json_encode($corners_options) . '));'
      . " const texts = options.map((option) => option.textContent.trim());"
      . " return texts.length === 4"
      . " && texts.some((text) => text.endsWith('1rem'))"
      . " && texts.filter((text) => /^[0-9]+ · (0\\.5rem|1rem|2rem)$/.test(text)).length === 3"
      . " && texts.includes('None · 0')"
      . " && options.every((option) => option.getAttribute('aria-selected') === 'false'); })()",
      20000,
      'The Corners listbox offers ONLY the minted space tokens (name · display value) plus the suite binding\'s allowed 0 faced by its None twin, none lit: the built-in radius table gave way to the site-wide binding merged over the suite\'s.',
    );
    // Close the well again (the trigger toggles), leaving the panel as
    // the steps below found it.
    $this->clickPanel($corners_well);
    $this->assertJsCondition(
      'document.querySelector(' . json_encode($corners_options) . ') === null',
      20000,
      'A second click on the Corners well closes its listbox.',
    );

    // ═════ A refused push toasts in the messenger; no strip renders. ═════
    // The push bar is the pending engine's one commit surface, and a
    // commit the server refuses has exactly one display: the op queue's
    // source reports it (reportFailure on the leader) into the
    // document-level messenger, and the session stays open for a
    // correction. Seam: push bar → op queue → reportFailure → messenger,
    // four layers that only meet in a browser. The refusal is real and
    // server-side: the editor's role loses "create variants" AFTER the
    // page loaded, so the Save doorway is still offered (the client's
    // gate is drupalSettings, read at load) and the server is the law.
    // The retired inline strip (.daedalus-stylish-inspector__variant-message) must
    // not regrow beside the toast.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_key');");
    $this->clickPanel("#daedalus_stylish_inspector button[aria-label='Save as variant']");
    $session_name = '#daedalus_stylish_inspector .daedalus-stylish-session__name';
    $push_commit = '#daedalus_stylish_inspector .daedalus-stylish-push-bar__commit';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $session_name),
      'The Save doorway opened a pending save session (the session header renders its name input).',
    );
    $this->assertJsCondition(
      "document.querySelector(" . json_encode($push_commit) . ")?.disabled === true",
      20000,
      'The push bar renders its Save variant commit, disabled until the draft has a name.',
    );
    $editor_role = Role::load('daedalus_editor');
    $editor_role->revokePermission('create variants')->save();
    $this->typeIntoPanel($session_name, 'Refused look');
    $this->assertJsCondition(
      "document.querySelector(" . json_encode($push_commit) . ")?.disabled === false",
      20000,
      'Naming the draft armed the Save variant commit (the capture holds the element\'s own overrides).',
    );
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickPanel($push_commit);
    $refusal = 'Minting variants requires the "create variants" permission.';
    $error_toasts = "[...document.querySelectorAll('[data-drupal-messages].daedalus-ui-messenger [data-drupal-message-id]')]"
      . ".filter((message) => message.getAttribute('data-drupal-message-type') === 'error')";
    $this->assertJsCondition(
      "$error_toasts.some((message) => message.textContent.includes(" . json_encode($refusal) . "))",
      20000,
      'The refused push toasted in the messenger (push bar → op queue → reportFailure → messenger): the server\'s message on an error toast in the claimed [data-drupal-messages] wrapper.',
    );
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The refused push was exactly one blueprint POST.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript(
        "!!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__variant-message, #daedalus_stylish_inspector .daedalus-stylish-push-bar [class*=\"error\"], #daedalus_stylish_inspector .daedalus-stylish-session [class*=\"error\"]')"
      ),
      'No inline strip renders beside the toast: the push bar and the session frame carry no error line (the retired exception stays retired).',
    );
    $this->assertJsCondition(
      "document.querySelector(" . json_encode($push_commit) . ")?.disabled === false"
      . " && !!document.querySelector(" . json_encode($session_name) . ')',
      20000,
      'The session stayed open with Save re-armed after the refusal: the toast is the report, the correction happens here.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript(
        '(() => { const last = Drupal.Daedalus.messenger.history().at(-1);'
        . " return !!last && last.type === 'error' && last.message.includes(" . json_encode($refusal) . ')'
        . ' && last.target === ' . json_encode($block_key) . '; })()'
      ),
      'The refusal joined the persisted history with the element key as its target (reportFailure\'s elementId rides as the messenger\'s target).',
    );
    // Leave the panel as the tail found it: the role gets its permission
    // back and the draft is discarded.
    $editor_role->grantPermission('create variants')->save();
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-push-bar .daedalus-stylish-inspector__variant-button');
    $this->assertJsCondition(
      "!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-push-bar')",
      20000,
      'Discard closed the refused session.',
    );

    // ——— Staged, not committed — and truly no reload. ———
    // Everything above lives in tempstore: the saved node has neither a
    // layout override nor any style map (the daedalus_stylish analog of daedalus_edit's
    // "stage, don't save"). And the sentinel proves the whole scenario ran
    // in one document: every restyle was an op round trip, never a reload.
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    $saved = $storage->load($nid);
    $this->assertTrue(
      $saved->get('daedalus_stylish_styles')->isEmpty(),
      'Every style write is staged in tempstore, not committed to the node.',
    );
    $this->assertTrue(
      $saved->get('layout_builder__layout')->isEmpty(),
      'The block placement is staged in tempstore, not committed to the node.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript('window.__sameDocument === true'),
      'The whole scenario ran without a page reload.',
    );
  }

}
