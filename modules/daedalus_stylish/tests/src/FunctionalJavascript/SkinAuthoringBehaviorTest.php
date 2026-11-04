<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\FunctionalJavascript;

use Drupal\daedalus_blueprint\Materials\MaterialsBudget;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\SkinStylesheet;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives skin authoring on the canvas: the Design system place's Skin tab.
 *
 * The capability under test is the skin as content authored where it is
 * listed: the Design system place's Skin tab lists the active skin's
 * tokens per scale, then its animations and fonts, each row the one
 * material card that edits the material through the material ops
 * (update_material) and the skin routes, with the canvas previewing every
 * tick through the token scope lane. The models are node-tested
 * (skin-model.test.mjs owns the sections, the gesture roster and the name
 * grammar; material-card.test.mjs the row's anatomy and the card's
 * sentences; preview.test.mjs the scope lane and the
 * materials delivery; material-gestures.test.mjs the op shapes) and the
 * server contracts are kernel-pinned (TokenOpsTest the ops and the
 * layers, SkinMaterialManagerTest the usage, rename, revert and delete routes,
 * SkinLayerTest the merge). Everything here is wiring that only exists
 * where the payload, the panel, the op spine, the compiled files and the
 * browser's CSS engine meet:
 * - F1, a dragged color token restyles an element before commit (seam:
 *   the preview's inline custom property on the token scope element, in
 *   a real cascade): the card's color editor previews per input event,
 *   the token scope lane prints the property's shape into the preview
 *   sheet as an @scope block on the token scope element and writes the
 *   literal inline there, and a block whose own color is var(--color-
 *   accent) computes the draft while the store still holds the shipped
 *   value. Node proves the lane's shape, not that the browser resolves
 *   var() through the scope element over the compiled file. On the
 *   way, the card's "Offer to the AI" control carries the door to the
 *   AI context usage (seam: the inspector's settings to the shared
 *   toggle to daedalus_blueprint's modal): the link the inspector
 *   handed the URL for opens the usage display, the bar by kind, in
 *   core's modal through the spine's one opener.
 * - F2, the commit holds across a reload (seam: gesture to op to site
 *   layer to recompiled file to page): Save value submits update_material
 *   (kind token), the write lands in the skin's SITE layer (the package
 *   layer untouched: the olivero skin is shipped and the site is a
 *   consuming one), the defaults file recompiles on the skin save, and
 *   after a reload the block computes the committed color from the file
 *   alone (no preview sheet on the fresh page). Four layers that only
 *   agree in a browser.
 * - F3, a minted token is a stop on a basic row bound to its scale (seam:
 *   the materials delta to the guide value space to the choice row): the
 *   "+ New token" card mints space/hero through save_material, the op
 *   queue lands the delta on the store, and the section's Padding row
 *   (the suite binds padding to the space scale, olivero's ladder offered
 *   under the open posture) lists 3rem beside the shipped steps in its
 *   stop menu; picking it commits var(--space-hero). The delta crosses
 *   the store, the guide payload and the row registry.
 * - F5, unsetting the site value reveals the package's on the canvas
 *   (seam: the layer reveal through delivery): the card over the
 *   overridden token offers "Use shipped value" (the revert route drops
 *   the site's record; the management read says the layer is both and
 *   the shipped state modified), the delta lands the package's record,
 *   and the block computes the shipped color again without a reload (the
 *   delivery re-stages what differs from the compiled file, which now
 *   prints the override). The merge is kernel; its arrival on the page is
 *   not.
 *
 * - F4, the skin session from a row's "Edit skin…" (seam: the session
 *   doorway, the defaults preview, the update_default round trip): two
 *   h2 text blocks compute the olivero skin's base:h2 size through the
 *   compiled file; the first one's basic Size row opens the row menu
 *   whose header names the skin and whose "Edit skin…" doorway enters
 *   the editing session on the skin's base:h2 document (the frame says
 *   "Editing the skin", the push bar the scope's blast radius); a size
 *   typed into the session body's font-size row stages one line and
 *   previews on the anchoring heading alone (the wearer-only honesty a
 *   variant session has); Push commits update_default, the skin's site
 *   layer holds the value, the package layer stands, and BOTH headings
 *   compute the pushed size without a reload (the source's defaults
 *   refresh stamps the recompiled CSS on the canvas and the panel's
 *   rows read the pushed document as the skin's). Session mounting and
 *   the staged CSS landing are wiring only a browser shows.
 *
 * - F7, the skin document's selector rows (seam: the document mint and
 *   the staged move ride the pending lane to Push to the compiled
 *   defaults, the lane the host body never takes): the skin session
 *   re-entered through F4's doorway and switched to the code mode renders
 *   the base:h2 document as islands; `> * {` typed on the root island
 *   mints the children row as a draft of the document view (no op, no
 *   session), one declaration settles as one staged line at the drafted
 *   key, the double-click pair on the row header swaps its code face for
 *   the selector editor, the retyped `a` previews through the
 *   hover-selector facet on the anchoring heading, Enter stages the move
 *   as its own pending row (the line following to ::a), and Push lands
 *   ONE update_default at the moved key alone (the key the row left
 *   stored nothing, so no null) while the defaults delivery uppercases a
 *   linked heading's anchor and not the heading. The host body's move is
 *   CodeModeBehaviorTest's (move_style); this is the document landing,
 *   node-pinned in pending.test.mjs and variant-document.test.mjs and
 *   wired only where the islands, the session and the push bar meet.
 * - F6, a font minted from a file renders on a heading (seam: the file
 *   lane to the compiled @font-face to the browser loading the file):
 *   the Fonts section's "+ New font" card uploads a real woff2 through
 *   the bytes door (the font store over daedalus_ui's file lane), the
 *   face draft carries the uuid the upload answered, Save submits
 *   save_material (kind font) onto the active skin, the skin's entity
 *   hook recompiles the skin file (the faces open it), the leader's font
 *   delivery re-reads the @font-face print and
 *   stamps it on the page, and a heading whose own stack names the
 *   family renders in it: the browser fetches the face's url() and
 *   reports it loaded (document.fonts). Only a browser loads a font.
 *
 * Idiom: one method (the framework installs a site per method), the real
 * recipe through the fixture trait, the editor persona (the recipe's
 * daedalus_editor role carries "create skin materials", "update skin
 * materials" and "edit skin defaults"), every assertion with a message.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SkinAuthoringBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'daedalus_theme';

  /**
   * {@inheritdoc}
   */
  protected $profile = '';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installFixture();

    // The card's door to the AI context usage (F1) is the permission's
    // alone (the materials budget's spine permission, daedalus_blueprint);
    // the recipe's editor role does not carry it as shipped (the grant is
    // the recipe's own follow-up), so the scenario grants it here.
    $editor = Role::load('daedalus_editor');
    $editor->grantPermission(MaterialsBudget::PERMISSION);
    $editor->save();
  }

  /**
   * The token scope element: the compiled files' and the lane's target.
   */
  private const SCOPE_JS = "document.querySelector('.daedalus-stylish-token-scope')";

  /**
   * The preview sheet's text ('' while nothing is staged or delivered).
   */
  private const SHEET_JS = "(document.querySelector('style[data-daedalus-stylish-preview]')?.textContent ?? '')";

  /**
   * The gradient token F8 drags a stop of: red at the start, blue at the end.
   */
  private const GRADIENT = 'linear-gradient(90deg, #ff0000 0%, #0000ff 100%)';

  /**
   * The accent token's record in the store.
   */
  private const ACCENT_JS = "Drupal.Daedalus.Materials.getRoster().get('token', 'color/accent')";

  /**
   * The olivero accent, as the browser computes it (#1475ad).
   */
  private const SHIPPED_ACCENT = 'rgb(20, 117, 173)';

  /**
   * The site's accent, as the browser computes it (#cc3300).
   */
  private const SITE_ACCENT = 'rgb(204, 51, 0)';

  /**
   * The Design system place's Skin tab: the place toggle, the first segment.
   */
  private function openSkin(): void {
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__place');
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__catalog .daedalus-ui-segment:nth-child(1)');
    $this->assertNotNull(
      $this->assertSession()->waitForElement('css', '#daedalus_stylish_inspector .daedalus-stylish-skin'),
      'The Skin tab rendered.',
    );
  }

  /**
   * Clicks "+ New" on the Skin tab, then one kind from its picker.
   */
  private function mintNew(string $kind): void {
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-skin__new');
    $this->assertNotNull(
      $this->assertSession()->waitForElement('css', '.daedalus-ui-menu'),
      'The "+ New" kind picker opened.',
    );
    $this->getSession()->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === " . json_encode($kind) . ').click();'
    );
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
   * Types into a Preact-controlled panel input, without settling it.
   *
   * The native value setter plus a real 'input' event is the same wiring
   * a keystroke runs; the settle is dispatched in a later call, like a
   * real user's separate tick (blurPanel, changePanel).
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
   * Settles a panel color input (change), the native chooser's own event.
   */
  private function changePanel(string $selector): void {
    $this->getSession()->executeScript(
      'document.querySelector(' . json_encode($selector) . ").dispatchEvent(new Event('change', { bubbles: true }));"
    );
  }

  /**
   * Clicks a panel element for real (scrolled to center so the pointer lands).
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
   * Places a section holding one text block whose own color is the accent.
   *
   * The placement and the style ride the op spine; the block's own
   * `color: var(--color-accent)` is the use the token legs watch (a
   * theme rule on anchors could outrank the skin's base:a default, so
   * the element's own entry is the honest probe).
   */
  private function placeAccentBlock(string $section_uuid, string $block_uuid): void {
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
              properties: { field_text_text: { value: 'Skin authoring probe' } },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
            { op: 'update_style', key: 'block:$block_uuid', properties: { color: 'var(--color-accent)' } },
          ],
        })).catch((error) => {
          window.__skinPlacementError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      'window.__skinPlacementError !== undefined'
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$block_uuid')",
      20000,
      'The text block placement resolved.',
    );
    $placement_error = $session->evaluateScript('window.__skinPlacementError ?? null');
    $this->assertNull($placement_error, 'Text block placement failed: ' . ($placement_error ?? ''));
    $this->assertNotNull(
      $this->assertSession()->waitForElement('css', "[data-selectable='block:$block_uuid']"),
      'The text block rendered on the canvas.',
    );
  }

  /**
   * Places two text blocks rendering as h2 inside the section.
   *
   * A text block's root IS its tag element (field_text_tag picks it), so
   * each block is an h2 the skin's base:h2 block styles: the elements
   * every push to that scope must restyle.
   */
  private function placeHeadings(string $section_uuid, string $first_uuid, string $second_uuid): void {
    $session = $this->getSession();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_element',
              uuid: '$first_uuid',
              block_type: 'text',
              properties: { field_text_text: { value: 'First heading' }, field_text_tag: 'h2' },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
            {
              op: 'add_element',
              uuid: '$second_uuid',
              block_type: 'text',
              properties: { field_text_text: { value: 'Second heading' }, field_text_tag: 'h2' },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__skinHeadingError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      'window.__skinHeadingError !== undefined'
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$second_uuid')",
      20000,
      'The heading placements resolved.',
    );
    $placement_error = $session->evaluateScript('window.__skinHeadingError ?? null');
    $this->assertNull($placement_error, 'Heading placement failed: ' . ($placement_error ?? ''));
    foreach ([$first_uuid, $second_uuid] as $uuid) {
      $this->assertNotNull(
        $this->assertSession()->waitForElement('css', "h2[data-selectable='block:$uuid']"),
        'The text block rendered on the canvas as an h2, the tag element being the block\'s root.',
      );
    }
  }

  /**
   * Right-clicks a panel row, opening the shared row menu at its center.
   */
  private function openRowMenu(string $selector): void {
    $this->getSession()->executeScript(
      '(() => { const row = document.querySelector(' . json_encode($selector) . '); const rect = row.getBoundingClientRect();'
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true,"
      . ' clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2 })); })();'
    );
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')",
      20000,
      'The row menu opened with its origin header.',
    );
  }

  /**
   * A real woff2 core ships (Umami's Scope One), the F6 upload.
   */
  private const FONT_FILE = 'core/profiles/demo_umami/themes/umami/fonts/scope-one-v14-latin-regular.woff2';

  /**
   * The font delivery tag's text ('' until a font write landed).
   */
  private const FONTS_JS = "(document.querySelector('style[data-daedalus-stylish-font-delivery]')?.textContent ?? '')";

  /**
   * The scenario, F1 to F7 (F7 between F4 and F6, inside the skin's reach).
   */
  public function testSkinAuthoringScenario(): void {
    $this->loginAsEditor();
    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // The recipe activates olivero (open): its shipped skin is the subject,
    // and this site consumes it (no developer setting), so every write
    // below is the site's layer over the package's.
    $this->assertSame(
      'olivero',
      \Drupal::service('daedalus_stylish.skin_manager')->activeSkinName(),
      'The olivero skin is active: the shipped skin the site layer goes over.',
    );
    $this->assertSame(
      'open',
      \Drupal::service('daedalus_stylish.style_guide')->enforcement(),
      'The olivero design system ships open enforcement: the stop rows offer the scale, a minted token included.',
    );
    // One gradient token on the olivero skin for F8: the compiled skin
    // file delivers its custom property (--gradient-hero-fade) and the
    // roster boots with it, so a block can wear it before the tab opens.
    $skin_material_manager = \Drupal::service('daedalus_stylish.skin_material_manager');
    $skin_material_manager->mint($skin_material_manager->activeSkin(), 'gradient', 'hero-fade', self::GRADIENT);

    $node = Node::create(['type' => 'page', 'title' => 'Skin authoring page']);
    $node->save();
    $this->drupalGet($node->toUrl());
    $session->resizeWindow(1920, 1200);
    $this->enterEditModeViaCookies($node->toUrl());
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted.',
    );

    $uuid_service = \Drupal::service('uuid');
    $section_uuid = $uuid_service->generate();
    $block_uuid = $uuid_service->generate();
    $this->placeAccentBlock($section_uuid, $block_uuid);
    $block_key = "block:$block_uuid";
    $block_color_js = "getComputedStyle(document.querySelector(\"[data-selectable='$block_key']\")).color";
    $this->assertJsCondition(
      "$block_color_js === '" . self::SHIPPED_ACCENT . "'",
      20000,
      'The block computes the shipped accent through the compiled skin file: var(--color-accent) resolves on the token scope element.',
    );

    $this->openInspector();
    $this->openSkin();

    // ----- F1: a dragged color token restyles before commit. -----
    $accent_row = '#daedalus_stylish_inspector .daedalus-stylish-skin__row[data-token="color/accent"]';
    $this->assertJsCondition(
      '(() => { const row = document.querySelector(' . json_encode($accent_row) . ');'
      . " return !!row && !row.querySelector('.daedalus-ui-chip')"
      . " && row.querySelector('.daedalus-stylish-skin__row-value')?.textContent.trim() === '#1475ad'; })()",
      20000,
      'The Skin tab lists color/accent with its default value and no origin pill on the row.',
    );
    $this->clickPanel($accent_row);
    $card = '#daedalus_stylish_inspector .daedalus-stylish-material-card--token';
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$card .daedalus-stylish-material-card__edit"),
      'The material card opened offering Edit value: the editor holds "update skin materials", the override grade.',
    );
    // The card says who shipped it and that the edit layers over it, and
    // shows the identity gesture the account's grade reaches disabled with
    // the reason (the row shows nothing of this): Rename rides the update
    // grant the editor holds, so it renders disabled as the package's;
    // Delete is the skin administrator's ("delete skin materials", a grant
    // the recipe's editor role does not carry), so without the grant the
    // gesture is hidden, never shown disabled (materialCardGestures' locks).
    $this->assertJsCondition(
      '(() => { const card = document.querySelector(' . json_encode($card) . '); if (!card) { return false; }'
      . " const origin = card.querySelector('.daedalus-stylish-material-card__origin')?.textContent ?? '';"
      . " const rename = card.querySelector('.daedalus-stylish-material-card__rename');"
      . " const remove = card.querySelector('.daedalus-stylish-material-card__delete');"
      . " return origin.startsWith('Shipped by ') && origin.includes('Your value layers over it.')"
      . " && !!rename && rename.disabled && rename.title.includes(\"rename it in the module's skins file\")"
      . " && remove === null; })()",
      20000,
      'The card states the origin in a sentence, shows Rename disabled with the reason (the identity is the package\'s on a consuming site), and offers no Delete to an editor without the delete grant.',
    );

    // The card's offer control carries the door to the AI context usage
    // (seam: the inspector's settings to the shared toggle to
    // daedalus_blueprint's modal): the editor holds "view ai context
    // usage" (setUp), so the inspector handed the bundle the display's
    // URL by route name and the toggle rendered the link beside its box,
    // wearing the attribute the spine's one opener catches. Following it
    // opens the design system size in core's modal: the bar of what the
    // site shares with the AI by kind, the tokens this card is one of
    // among them, and the system; under the bar the form, one collapsed
    // section per kind with a box per record (the tokens' among them,
    // this card's toggle being one of those boxes' second door) and its
    // Save.
    $link = "$card .daedalus-stylish-ai-offer__context";
    $this->assertJsCondition(
      '(() => { const link = document.querySelector(' . json_encode($link) . ');'
      . " return !!link && link.hasAttribute('data-daedalus-modal') && link.textContent.trim() === 'See what the AI holds'"
      . " && link.getAttribute('href') === drupalSettings.daedalusStylish.aiContext.url; })()",
      20000,
      'The token card\'s offer control carries the link to the usage display, its href the URL the inspector handed over.',
    );
    $this->clickPanel($link);
    $this->assertJsCondition(
      // Core appends an empty, hidden #drupal-modal on every attach, so the
      // element exists before the dialog wraps it: a poll that reads the
      // dialog or the form before both are there returns false, never
      // throws (a thrown condition ends the wait as an error).
      "(() => { const modal = document.querySelector('#drupal-modal'); if (!modal) { return false; }"
      . " const dialog = modal.closest('.ui-dialog'); if (!dialog) { return false; }"
      . " const form = modal.querySelector('#daedalus-blueprint-ai-context form.daedalus-ai-context-form'); if (!form) { return false; }"
      . " const tokens = form.querySelector('details.daedalus-ai-context-form__kind[data-kind=\"token\"]');"
      . " const save = [...dialog.querySelectorAll('.ui-dialog-buttonpane button')].some((button) => button.textContent.trim() === 'Save')"
      . "   || !!dialog.querySelector('#daedalus-blueprint-ai-context input[type=\"submit\"][value=\"Save\"]');"
      . " return dialog.classList.contains('daedalus-ai-context-dialog') && dialog.querySelector('.ui-dialog-title').textContent.trim() === 'Design system size'"
      . " && !!modal.querySelector('.daedalus-ai-context__bar [data-kind=\"token\"]') && !!modal.querySelector('.daedalus-ai-context__legend [data-kind=\"token\"]')"
      . " && !!modal.querySelector('.daedalus-ai-context__bar [data-kind=\"system\"]')"
      . " && !!tokens && !tokens.open && tokens.querySelectorAll('input[type=\"checkbox\"]').length > 0"
      . " && [...form.querySelectorAll('details.daedalus-ai-context-form__kind')].every((section) => !section.open) && save; })()",
      20000,
      'Following the link opened the design system size in core\'s modal: the bar with a tokens segment (this card\'s kind) and the system, and the form with a collapsed tokens section of boxes and its Save.',
    );
    // Core sizes a modal on a debounced window handler 20 ms after it
    // opens (dialog.position.js: resetSize turns the 95% max height into
    // pixels on the content element); a close landing before that timer
    // fires leaves it to run on the removed dialog, a console error no
    // user can produce. So the leg waits for the settled size, then
    // closes through the title bar's button, the user's gesture.
    $this->assertJsCondition(
      "document.querySelector('#drupal-modal').style.maxHeight.endsWith('px')",
      20000,
      'Core sized the modal: its autoResize settled.',
    );
    $session->getPage()->find('css', '.daedalus-ai-context-dialog .ui-dialog-titlebar-close')->click();
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ai-context') && !!document.querySelector(" . json_encode("$card .daedalus-stylish-material-card__edit") . ')',
      20000,
      'Closing the modal removed the display and left the card open.',
    );

    $this->clickPanel("$card .daedalus-stylish-material-card__edit");
    $picker = "$card .daedalus-stylish-native-picker";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $picker),
      'The color scale mounted the color kind\'s editor (the shared color field, its native picker behind the face).',
    );
    $this->typeIntoPanel($picker, '#cc3300');
    $this->assertJsCondition(
      '(() => { const sheet = ' . self::SHEET_JS . ';'
      . " return $block_color_js === '" . self::SITE_ACCENT . "'"
      . " && sheet.includes('@scope (.daedalus-stylish-token-scope)')"
      . " && sheet.includes('--color-accent: var(--daedalus-stylish-live-')"
      . ' && !sheet.includes(\'#cc3300\')'
      . ' && ' . self::SCOPE_JS . ".style.cssText.includes('#cc3300')"
      . ' && ' . self::ACCENT_JS . "?.value?.default === '#1475ad'; })()",
      20000,
      'F1: the block computes the draft before any commit: the token scope lane printed the property\'s shape into the preview sheet and wrote the literal inline on the token scope element, while the store still holds the shipped value.',
    );

    // ----- F2: commit, reload; the value holds. -----
    $this->changePanel($picker);
    $this->clickPanel("$card .daedalus-stylish-material-card__save");
    $this->assertJsCondition(
      '(() => { return ' . self::ACCENT_JS . "?.value?.default === '#cc3300'"
      . " && !document.querySelector(" . json_encode("$card .daedalus-stylish-material-card__save") . ')'
      . " && $block_color_js === '" . self::SITE_ACCENT . "'; })()",
      20000,
      'F2: Save value landed update_material (kind token): the store carries the site\'s value, the editor closed, and the block keeps the color through the delivery.',
    );
    $skin_storage = \Drupal::entityTypeManager()->getStorage('daedalus_skin');
    $skin_storage->resetCache();
    $skins = $skin_storage->loadByProperties(['machine_name' => 'olivero']);
    $skin = reset($skins);
    assert($skin instanceof DaedalusStylishSkinInterface);
    $this->assertNotFalse($skin, 'The olivero skin loads.');
    // The card's pair editor commits the whole pair (the dark entry seeded
    // from the record, the honesty rule), so the site's record carries
    // the pair with the new default.
    $this->assertSame(['default' => '#cc3300', 'dark' => '#52b3eb'], $skin->siteTokens()['color']['accent']['value'] ?? NULL, 'F2: the write landed in the SITE layer as the accent\'s value, the dark entry kept.');
    $this->assertSame(['default' => '#1475ad', 'dark' => '#52b3eb'], $skin->packageTokens()['color']['accent']['value'], 'F2: the package layer is untouched: the shipped pair stands under the site\'s value.');
    $this->assertSame(['default' => '#cc3300', 'dark' => '#52b3eb'], $skin->tokens()['color']['accent']['value'], 'F2: the merge wears the site\'s record over the package\'s.');

    $this->enterEditModeViaCookies($node->toUrl());
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()'
      . " && !!document.querySelector(\"[data-selectable='$block_key']\")",
      20000,
      'The page reloaded in edit mode with the block.',
    );
    $this->assertJsCondition(
      "$block_color_js === '" . self::SITE_ACCENT . "' && " . self::SHEET_JS . " === ''",
      20000,
      'F2: after the reload the block computes the committed color from the recompiled defaults file alone: no preview sheet on the fresh page.',
    );

    // ----- F3: a minted token is a stop on a row bound to its scale. -----
    $this->openInspector();
    $this->openSkin();
    $this->mintNew('Token');
    $mint = '#daedalus_stylish_inspector .daedalus-stylish-skin-mint--token';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $mint),
      'The "+ New" › Token gesture opened the token mint card: the editor holds "create skin materials", the extension gate.',
    );
    $session->executeScript(<<<JS
      (() => {
        const select = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-skin-mint--token select[aria-label="Scale"]');
        const setter = Object.getOwnPropertyDescriptor(window.HTMLSelectElement.prototype, 'value').set;
        setter.call(select, 'space');
        select.dispatchEvent(new Event('change', { bubbles: true }));
      })();
      JS);
    $value_input = "$mint input[aria-label='Token value']";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $value_input),
      'The space scale (a dimension shape) mounted the text value editor.',
    );
    $this->typeIntoPanel("$mint input[aria-label='Token name']", 'hero');
    $this->typeIntoPanel($value_input, '3rem');
    $this->blurPanel($value_input);
    $this->assertJsCondition(
      'document.querySelector(' . json_encode("$mint .daedalus-stylish-skin-mint__save") . ')?.disabled === false',
      20000,
      'Scale, a legal name and a value armed Save token.',
    );
    $this->clickPanel("$mint .daedalus-stylish-skin-mint__save");
    $this->assertJsCondition(
      '(() => { return !document.querySelector(' . json_encode($mint) . ')'
      . ' && !!document.querySelector(\'#daedalus_stylish_inspector .daedalus-stylish-skin__row[data-token="space/hero"]\')'
      . " && Drupal.Daedalus.Materials.getRoster().get('token', 'space/hero')?.value === '3rem'; })()",
      20000,
      'F3: the mint landed through save_material: the card closed, the skin section grew the space/hero row, and the store holds the record.',
    );
    $skin_storage->resetCache();
    $skins = $skin_storage->loadByProperties(['machine_name' => 'olivero']);
    $skin = reset($skins);
    assert($skin instanceof DaedalusStylishSkinInterface);
    $this->assertSame('3rem', $skin->siteTokens()['space']['hero']['value'] ?? NULL, 'F3: the minted token lives in the site layer.');

    // The section's Padding row, in the styling place: the Basic segment
    // lands there, the selection is the section (its Padding row over
    // olivero's rem ladder is the detented field with the stop menu).
    $session->executeScript("Drupal.Daedalus.Selection.select('container:$section_uuid');");
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__ui-mode .daedalus-ui-segment:nth-child(1)');
    $padding_input = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--spacing input[aria-label='Padding']";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $padding_input),
      'The section\'s Basic Padding row renders as the detented field.',
    );
    $this->clickPanel("$padding_input + .daedalus-stylish-stop-menu__chevron");
    $this->assertJsCondition(
      "(() => { const menu = document.querySelector('.daedalus-ui-menu'); if (!menu) { return false; }"
      . " const labels = Array.from(menu.querySelectorAll('.daedalus-ui-menu__label')).map((label) => label.textContent.trim());"
      . " return ['0.5rem', '1rem', '2rem', '4rem', '3rem'].every((step) => labels.some((label) => label.includes(step)))"
      . " && labels.every((label) => !label.includes('var(')); })()",
      20000,
      'F3: the Padding row\'s stop menu lists the minted 3rem beside the shipped steps: the materials delta reached the guide value space and the row.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.includes('3rem')).click();"
    );
    $map = $this->styleMapJs();
    $this->assertJsCondition(
      $map . '[' . json_encode("container:$section_uuid") . "]?.default?.padding === 'var(--space-hero)'",
      20000,
      'F3: picking the minted stop committed its reference, var(--space-hero), on the section.',
    );

    // ----- F5: unset the site value; the package's shows. -----
    $this->openSkin();
    $this->clickPanel($accent_row);
    $revert = "$card .daedalus-stylish-material-card__revert";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $revert),
      'F5: the overridden token\'s card offers "Use shipped value": the management read says the layer is both and the shipped state modified.',
    );
    $this->assertJsCondition(
      '(() => { const rename = document.querySelector(' . json_encode("$card .daedalus-stylish-material-card__rename") . ');'
      . ' const remove = document.querySelector(' . json_encode("$card .daedalus-stylish-material-card__delete") . ');'
      . ' return !!rename && rename.disabled && remove === null; })()',
      20000,
      'F5: Rename is shown disabled on the overridden package token (its identity is the package\'s on a consuming site, and the card says so instead of hiding the gesture the update grant reaches), while Delete stays hidden from an editor without the delete grant.',
    );
    $this->clickPanel($revert);
    $this->clickPanel('.daedalus-ui-dialog__button--confirm');
    $this->assertJsCondition(
      '(() => { return ' . self::ACCENT_JS . "?.value?.default === '#1475ad'"
      . " && $block_color_js === '" . self::SHIPPED_ACCENT . "'"
      . ' && ' . self::SHEET_JS . ".includes('--color-accent: var(--daedalus-stylish-live-'); })()",
      20000,
      'F5: the revert dropped the site\'s record: the store wears the package\'s value and the block computes the shipped accent without a reload, the delivery staging what now differs from the compiled file.',
    );
    $skin_storage->resetCache();
    $skins = $skin_storage->loadByProperties(['machine_name' => 'olivero']);
    $skin = reset($skins);
    assert($skin instanceof DaedalusStylishSkinInterface);
    $this->assertArrayNotHasKey('accent', $skin->siteTokens()['color'] ?? [], 'F5: the site layer no longer holds the accent.');
    $this->assertSame('3rem', $skin->siteTokens()['space']['hero']['value'] ?? NULL, 'F5: the site-minted token is untouched by the revert.');

    // ----- F4: "Edit skin…" enters the skin session; change, Push; every h2
    // restyles. -----
    $heading_a = $uuid_service->generate();
    $heading_b = $uuid_service->generate();
    $this->placeHeadings($section_uuid, $heading_a, $heading_b);
    $size_a = "getComputedStyle(document.querySelector(\"[data-selectable='block:$heading_a']\")).fontSize";
    $size_b = "getComputedStyle(document.querySelector(\"[data-selectable='block:$heading_b']\")).fontSize";
    // olivero's base:h2 block sets font-size: var(--text-headline), the
    // headline token's wide entry being 2.25rem (36px at the root size).
    $this->assertJsCondition(
      "$size_a === '36px' && $size_b === '36px'",
      20000,
      'F4: both headings compute the skin\'s base:h2 size through the compiled defaults file (var(--text-headline), 2.25rem at the wide canvas).',
    );
    $session->executeScript("Drupal.Daedalus.Selection.select('block:$heading_a');");
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__ui-mode .daedalus-ui-segment:nth-child(1)');
    $size_row = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--typography input[aria-label='Size']";
    $this->assertNotNull(
      $assert_session->waitForElement('css', $size_row),
      'F4: the heading\'s Basic typography Size row renders as the detented field (olivero is open).',
    );
    $this->openRowMenu($size_row);
    $header = (string) $session->evaluateScript("document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header').textContent");
    $this->assertStringContainsString('from the active skin', $header, 'F4: the Size row\'s menu header names the skin as the supplier. Header: ' . $header);
    $this->assertJsCondition(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__label')).some((label) => label.textContent.trim() === 'Edit skin…')",
      20000,
      'F4: the menu offers "Edit skin…", the doorway into the skin session on the delivering scope.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Edit skin…').click();"
    );
    $frame = '#daedalus_stylish_inspector .daedalus-stylish-session';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $frame),
      'F4: the doorway mounted the editing session frame over the panel.',
    );
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector(" . json_encode($frame) . ');'
      . " return frame.querySelector('.daedalus-stylish-session__posture')?.textContent.trim() === 'Editing the skin'"
      . " && frame.querySelector('.daedalus-stylish-session__label')?.textContent.trim() === 'Heading 2'"
      . " && (document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-push-bar__impact')?.textContent ?? '').includes('restyles every Heading 2 site-wide'); })()",
      20000,
      'F4: the frame says "Editing the skin" on the Heading 2 document (the guide\'s tag word for base:h2) and the push bar states the scope\'s blast radius.',
    );
    // The session body is the Advanced rows over the h2\'s stack with the
    // skin\'s base:h2 block as the editable document: its font-size row.
    $font_size_js = "Array.from(document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-session input[aria-label]'))"
      . ".find((input) => ['font-size', 'Font size'].includes(input.getAttribute('aria-label')))";
    $this->assertJsCondition("!!$font_size_js", 20000, 'F4: the session body renders the font-size row (the Typography section over the skin document).');
    $session->executeScript(
      "(() => { const input = $font_size_js;"
      . " const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;"
      . " setter.call(input, '3rem'); input.dispatchEvent(new Event('input', { bubbles: true })); })();"
    );
    $session->executeScript("($font_size_js).dispatchEvent(new FocusEvent('blur'));");
    $this->assertJsCondition(
      "(() => { const impact = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-push-bar__impact')?.textContent ?? '';"
      . " return impact.includes('1 pending change')"
      . " && !!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-session .daedalus-stylish-inspector__property--pending')"
      . " && $size_a === '48px' && $size_b === '36px'; })()",
      20000,
      'F4: the settled size staged one pending line (the row wears the pending mark) and previews on the anchoring heading alone: the other h2 keeps the skin\'s value until Push, the wearer-only honesty a variant session has.',
    );
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-push-bar__commit');
    $this->assertJsCondition(
      '(() => { return !document.querySelector(' . json_encode($frame) . ')'
      . " && $size_a === '48px' && $size_b === '48px'"
      . " && (document.querySelector('style[data-daedalus-stylish-defaults-delivery]')?.textContent ?? '').includes('font-size: 3rem'); })()",
      20000,
      'F4: Push committed update_default and closed the session; the defaults delivery stamped the recompiled CSS on the page, so BOTH headings compute the pushed size without a reload.',
    );
    $skin_storage->resetCache();
    $skins = $skin_storage->loadByProperties(['machine_name' => 'olivero']);
    $skin = reset($skins);
    assert($skin instanceof DaedalusStylishSkinInterface);
    $this->assertSame('3rem', $skin->siteDefaults()['base:h2']['']['default']['font-size'] ?? NULL, 'F4: the push landed in the skin\'s SITE layer at base:h2.');
    $this->assertSame('var(--text-headline)', $skin->packageDefaults()['base:h2']['']['default']['font-size'] ?? NULL, 'F4: the package layer stands beneath the site\'s value.');
    $this->assertSame('3rem', $skin->defaults()['base:h2']['']['default']['font-size'] ?? NULL, 'F4: the merge wears the site\'s default.');
    // The panel reads the pushed document as the skin\'s: the Size row\'s
    // origin now names the pushed value (the live skinDefaults the
    // source delivered, not the boot payload).
    $this->assertNotNull($assert_session->waitForElement('css', $size_row), 'F4: the Basic Size row is back after the session closed.');
    $this->openRowMenu($size_row);
    $header = (string) $session->evaluateScript("document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header').textContent");
    $this->assertStringContainsString('3rem from the active skin', $header, 'F4: after the push the row reads the pushed default as the skin\'s (the panel\'s skin defaults refreshed with the canvas). Header: ' . $header);
    $session->executeScript("document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));");

    // ----- F7: the skin document's selector rows, minted, moved, pushed. -----
    // The edit-selector landing's document parity (seam: the document
    // mint and the staged move ride the pending lane to Push to the
    // compiled defaults, the lane the host body never takes: the host
    // sends move_style, a document stages a fact). A third heading
    // carries a URL, so its link part renders an anchor inside the h2:
    // the element the pushed rule must restyle.
    $heading_c = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_element',
              uuid: '$heading_c',
              block_type: 'text',
              properties: { field_text_text: { value: 'Linked heading' }, field_text_tag: 'h2', field_text_url: { uri: 'https://example.com/skin' } },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__skinLinkedHeadingError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__skinLinkedHeadingError !== undefined || !!document.querySelector(\"h2[data-selectable='block:$heading_c'] a\")",
      20000,
      'F7: the linked heading placement resolved.',
    );
    $placement_error = $session->evaluateScript('window.__skinLinkedHeadingError ?? null');
    $this->assertNull($placement_error, 'F7: the linked heading placement failed: ' . ($placement_error ?? ''));
    $link_transform = "getComputedStyle(document.querySelector(\"h2[data-selectable='block:$heading_c'] a\")).textTransform";
    $heading_transform = "getComputedStyle(document.querySelector(\"h2[data-selectable='block:$heading_c']\")).textTransform";
    $this->assertSame('none', (string) $session->evaluateScript($link_transform), 'F7: the control: the linked heading\'s anchor computes no text-transform before anything is pushed.');
    // The same doorway F4 took, into the same document.
    $session->executeScript("Drupal.Daedalus.Selection.select('block:$heading_a');");
    $this->assertNotNull($assert_session->waitForElement('css', $size_row), 'F7: the first heading\'s Basic Size row is back as the doorway\'s row.');
    $this->openRowMenu($size_row);
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Edit skin…').click();"
    );
    $this->assertNotNull($assert_session->waitForElement('css', $frame), 'F7: "Edit skin…" mounted the editing session frame again on the base:h2 document.');
    // The code mode over the session is the document view: the skin's
    // base:h2 document as islands, the root slot carried by the anchoring
    // heading.
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__ui-mode-button--code');
    $document = '#daedalus_stylish_inspector .daedalus-stylish-session .daedalus-stylish-document';
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$document .daedalus-stylish-island__editor"),
      'F7: the code mode rendered the skin document\'s islands inside the session frame.',
    );
    // The document-step helpers: the root slot's default island (the
    // first island the document renders), a wake at the end of its last
    // printed line (the glyph's right edge, so the cursor lands after the
    // line), a selector row header by its document key, the live editor
    // count inside the session and the push bar's impact text.
    $session->executeScript(<<<JS
      window.__skin = {
        rootIsland() {
          return document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-session .daedalus-stylish-document .daedalus-stylish-island__editor');
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
        header(key) {
          return Array.from(document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-session .daedalus-stylish-document [data-daedalus-stylish-document-key]'))
            .find((header) => header.dataset.daedalusStylishDocumentKey === key) ?? null;
        },
        editors() {
          return document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-session .cm-editor').length;
        },
        impact() {
          return document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-push-bar__impact')?.textContent ?? '';
        },
      };
      JS);
    // `> * {` typed on the root island mints the children row as a DRAFT
    // of the document view (the brace swallowed with the selector, the
    // same gate the host body mints by): its island live and focused,
    // nothing staged, no op.
    $session->executeScript('window.__skin.wakeAtEnd(window.__skin.rootIsland());');
    $this->assertJsCondition('window.__skin.editors() === 1', 10000, 'F7: the root island woke for the selector line.');
    $session->executeScript("document.execCommand('insertText', false, '\\n> * ');");
    $session->executeScript("document.execCommand('insertText', false, '{');");
    // The push bar stands open with the session frame and counts zero
    // pending changes while the draft is view state alone.
    $this->assertJsCondition(
      "(() => { const header = window.__skin.header('::> *'); if (!header) { return false; } const body = header.nextElementSibling;"
      . " return header.querySelector('.daedalus-stylish-islands__prelude')?.textContent === '> *'"
      . " && !!body && body.querySelectorAll('.cm-editor').length === 1 && body.contains(document.activeElement)"
      . " && window.__skin.impact().includes('0 pending changes'); })()",
      20000,
      'F7: typing `> * {` on the skin document\'s root island minted the children row as a draft (the brace swallowed), its island live and focused, with nothing staged and no op (the push bar counts zero pending changes): the mint is the document view\'s own.',
    );
    // One declaration settles into the session as one staged line at the
    // drafted key: the island writes through the pending lane.
    $session->executeScript("document.execCommand('insertText', false, 'text-transform: uppercase;');");
    $this->assertJsCondition(
      "window.__skin.impact().includes('1 pending change') && window.__skin.header('::> *') !== null",
      20000,
      'F7: the declaration settled as one staged line at the drafted key (::> *): the session opened on the first staging, the push bar counting one pending change.',
    );
    // The double-click pair on the row header (Edit Mode's one definition
    // of a double-click: two `click` events, the second carrying the OS
    // count) swaps the code face for the selector editor; the retyped
    // selector previews its reach on the slot's carrier through the
    // hover-selector facet; Enter stages the move as its own pending fact
    // and the staged line follows to the new key.
    $header_button_js = "window.__skin.header('::> *')?.querySelector('.daedalus-stylish-islands__part-select')";
    $editor_js = "window.__skin.header('::> *')?.querySelector('.daedalus-stylish-islands__prelude--editing .cm-editor')";
    $session->executeScript(<<<JS
      (() => {
        const button = $header_button_js;
        button.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail: 1 }));
        button.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail: 2 }));
      })();
      JS);
    $this->assertJsCondition(
      "(() => { const editor = $editor_js; return !!editor && editor.querySelector('.cm-content').textContent === '> *' && editor.contains(document.activeElement); })()",
      20000,
      'F7: the double-click pair on the row header swapped its code face for the selector editor, seeded with the selector and focused.',
    );
    $session->executeScript(<<<JS
      (() => {
        const content = ($editor_js).querySelector('.cm-content');
        content.focus();
        const selection = window.getSelection();
        selection.selectAllChildren(content.querySelector('.cm-line'));
        document.execCommand('insertText', false, 'a');
      })();
      JS);
    $this->assertJsCondition(
      "(() => { const editor = $editor_js; const hovered = Drupal.Daedalus.Selection.getHoveredSelector();"
      . " return !!editor && editor.querySelector('.cm-content').textContent === 'a'"
      . " && hovered?.key === " . json_encode("block:$heading_a") . " && hovered?.selector === 'a'"
      . " && window.__skin.impact().includes('1 pending change'); })()",
      20000,
      'F7: the retyped selector previews its reach through the hover-selector facet on the anchoring heading (the root slot\'s carrier) while nothing moved yet: the session still holds its one line.',
    );
    $session->executeScript("($editor_js).querySelector('.cm-content').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true, cancelable: true }));");
    $this->assertJsCondition(
      "(() => { const moved = window.__skin.header('::a'); return !!moved && window.__skin.header('::> *') === null"
      . " && moved.querySelector('.daedalus-stylish-islands__prelude')?.textContent === 'a'"
      . " && !moved.querySelector('.daedalus-stylish-islands__prelude--editing')"
      . " && window.__skin.impact().includes('2 pending changes')"
      . " && Drupal.Daedalus.Selection.getHoveredSelector() === null; })()",
      20000,
      'F7: Enter staged the move: the row stands at the new key (::a) with its code face, no row stands at the key it left, the session counts the followed line and the move as two pending rows, and the preview cleared.',
    );
    // Push spells the session as update_default ops: the line at its
    // moved key alone (the row it left stored nothing, so no null is
    // spelled there), and the defaults delivery restyles the matching
    // anchors site-wide, never the headings themselves.
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-push-bar__commit');
    $this->assertJsCondition(
      '(() => { return !document.querySelector(' . json_encode($frame) . ')'
      . " && (document.querySelector('style[data-daedalus-stylish-defaults-delivery]')?.textContent ?? '').includes('text-transform: uppercase')"
      . " && $link_transform === 'uppercase' && $heading_transform === 'none'; })()",
      20000,
      'F7: Push committed the moved row as update_default at the new key and closed the session; the defaults delivery restyles the linked heading\'s anchor (h2 a) and not the heading itself.',
    );
    $skin_storage->resetCache();
    $skins = $skin_storage->loadByProperties(['machine_name' => 'olivero']);
    $skin = reset($skins);
    assert($skin instanceof DaedalusStylishSkinInterface);
    $this->assertSame('uppercase', $skin->siteDefaults()['base:h2']['::a']['default']['text-transform'] ?? NULL, 'F7: the push landed in the skin\'s SITE layer at the moved key (base:h2, ::a).');
    $this->assertArrayNotHasKey('::> *', $skin->siteDefaults()['base:h2'] ?? [], 'F7: nothing landed at the key the row left (::> *): the staged move replaced the line\'s relative and a key that stored nothing earns no null.');
    $this->assertSame('3rem', $skin->siteDefaults()['base:h2']['']['default']['font-size'] ?? NULL, 'F7: F4\'s pushed size stands beside the new rule.');
    // Back to the Basic mode F6 expects the panel in.
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__ui-mode .daedalus-ui-segment:nth-child(1)');

    // ----- F6: mint a font from a file; a heading renders in it. -----
    $this->openSkin();
    $this->mintNew('Font');
    $font_mint = '#daedalus_stylish_inspector .daedalus-stylish-skin-mint--font';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $font_mint),
      'F6: the "+ New" › Font gesture opened the font mint card: the editor holds "create skin materials", the extension gate.',
    );
    $file_input = $assert_session->waitForElement('css', "$font_mint input[type='file']");
    $this->assertNotNull($file_input, 'F6: the mint card offers the file input (woff2 and woff).');
    $file_input->attachFile(\Drupal::root() . '/' . self::FONT_FILE);
    $this->assertJsCondition(
      "(() => { const draft = document.querySelector(" . json_encode("$font_mint .daedalus-stylish-faces-editor__draft") . ');'
      . " return !!draft && draft.querySelector('.daedalus-stylish-faces-editor__file')?.textContent.includes('scope-one')"
      . " && draft.querySelector('.daedalus-stylish-faces-editor__weight')?.value === '400'"
      . " && draft.querySelector('.daedalus-stylish-faces-editor__style')?.value === 'normal'; })()",
      20000,
      'F6: the upload landed through the bytes door and became a face draft: the file by its stored name, the weight and style the name suggests (regular).',
    );
    $this->typeIntoPanel("$font_mint input[aria-label='Font label']", 'Scope One');
    $this->assertJsCondition(
      'document.querySelector(' . json_encode("$font_mint .daedalus-stylish-skin-mint__save") . ')?.disabled === false',
      20000,
      'F6: a face and a label armed Save font.',
    );
    $this->clickPanel("$font_mint .daedalus-stylish-skin-mint__save");
    $this->assertJsCondition(
      '(() => { return !document.querySelector(' . json_encode($font_mint) . ')'
      . " && !!document.querySelector(\"#daedalus_stylish_inspector .daedalus-stylish-skin__row[data-font='scope_one']\")"
      . " && Drupal.Daedalus.Materials.getRoster().get('font', 'scope_one')?.spellings?.font === '\"Scope One\"'"
      . ' && ' . self::FONTS_JS . ".includes('@font-face') && " . self::FONTS_JS . ".includes('font-family: \"Scope One\"'); })()",
      20000,
      'F6: the mint landed through save_material (kind font): the card closed, the Skin tab\'s Fonts section grew the row, the store holds the record spelled as the quoted family, and the font delivery stamped the recompiled @font-face print on the page.',
    );
    $skin_storage = \Drupal::entityTypeManager()->getStorage('daedalus_skin');
    $skin_storage->resetCache();
    $font = \Drupal::service('daedalus_stylish.skin_manager')->loadActiveSkin()?->fonts()['scope_one'] ?? NULL;
    $this->assertNotNull($font, 'F6: the active skin mints the font under the name derived from the label.');
    $this->assertSame('Scope One', $font['family'], 'F6: the family is the label as typed.');
    $this->assertCount(1, $font['faces'], 'F6: one face, the uploaded file.');
    $face = $font['faces'][0];
    $this->assertSame('400', $face['weight'], 'F6: the face stored canonical (weight).');
    $this->assertSame('normal', $face['style'], 'F6: the face stored canonical (style).');
    $this->assertStringStartsWith('@font-face {', file_get_contents(\Drupal::state()->get(SkinStylesheet::STATE_KEY)), 'F6: the skin stylesheet recompiled on the save and opens with the face.');

    // The heading wears the family through its own stack (update_style,
    // the op every row commits); the browser then fetches the face.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'update_style', key: 'block:$heading_a', properties: { 'font-family': '"Scope One", serif' } },
          ],
        })).catch((error) => {
          window.__skinFontError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "(() => { if (window.__skinFontError !== undefined) { return true; }"
      . " const heading = document.querySelector(\"[data-selectable='block:$heading_a']\");"
      . " return !!heading && getComputedStyle(heading).fontFamily.includes('Scope One')"
      . " && document.fonts.check('16px \"Scope One\"')"
      . " && Array.from(document.fonts).some((face) => face.family.replace(/[\"']/g, '') === 'Scope One' && face.status === 'loaded'); })()",
      20000,
      'F6: the heading computes the minted family and the browser loaded its face from the delivered @font-face (document.fonts reports the Scope One face loaded).',
    );
    $font_error = $session->evaluateScript('window.__skinFontError ?? null');
    $this->assertNull($font_error, 'F6: the stack commit failed: ' . ($font_error ?? ''));

    // The font's card: the face list with its specimen, the management
    // read (a site font, no provider).
    $this->clickPanel("#daedalus_stylish_inspector .daedalus-stylish-skin__row[data-font='scope_one']");
    $this->assertJsCondition(
      "(() => { const card = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-material-card--font'); if (!card) { return false; }"
      . " return card.querySelector('.daedalus-stylish-faces__face-label')?.textContent.trim() === '400'"
      . " && getComputedStyle(card.querySelector('.daedalus-stylish-faces__specimen')).fontFamily.includes('Scope One')"
      . " && !!card.querySelector('.daedalus-stylish-library-card__meta') && !card.querySelector('.daedalus-stylish-library-card__meta').textContent.includes('counting'); })()",
      20000,
      'F6: the font card lists the 400 face with its specimen in the family and finished its management read.',
    );

    // ----- F8: a gradient token through the builder; the canvas repaints.
    // ----- The second heading wears the gradient token as its background image
    // (update_style, the op every row commits): the use the drag must repaint
    // before any commit.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'update_style', key: 'block:$heading_b', properties: { 'background-image': 'var(--gradient-hero-fade)' } },
          ],
        })).catch((error) => {
          window.__skinGradientError = error.message;
        });
      })();
      JS);
    $gradient_js = "getComputedStyle(document.querySelector(\"[data-selectable='block:$heading_b']\")).backgroundImage";
    $this->assertJsCondition(
      "window.__skinGradientError !== undefined || ($gradient_js.indexOf('rgb(255, 0, 0)') !== -1 && $gradient_js.indexOf('rgb(255, 0, 0)') < $gradient_js.indexOf('rgb(0, 0, 255)'))",
      20000,
      'F8: the heading computes the shipped gradient through the compiled skin file: red before blue, var(--gradient-hero-fade) resolved on the token scope element.',
    );
    $this->assertNull($session->evaluateScript('window.__skinGradientError ?? null'), 'F8: the background commit failed.');
    $this->openSkin();
    $gradient_row = '#daedalus_stylish_inspector .daedalus-stylish-skin__row[data-token="gradient/hero-fade"]';
    $this->assertNotNull($assert_session->waitForElement('css', $gradient_row), 'F8: the Skin tab lists the gradient token under its scale.');
    $this->clickPanel($gradient_row);
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$card .daedalus-stylish-material-card__edit"),
      'F8: the gradient token\'s card opened offering Edit value.',
    );
    $this->clickPanel("$card .daedalus-stylish-material-card__edit");
    $first_handle = $assert_session->waitForElement('css', "$card .daedalus-stylish-gradient__handle[data-index=\"0\"]");
    $this->assertNotNull($first_handle, 'F8: the gradient scale mounted the gradient builder: the stop handles render on the track.');
    $last_handle = $session->getPage()->find('css', "$card .daedalus-stylish-gradient__handle[data-index=\"1\"]");
    $this->assertNotNull($last_handle, 'F8: the second stop handle renders.');
    $session->executeScript('document.querySelector(' . json_encode("$card .daedalus-stylish-gradient__track") . ")?.scrollIntoView({ block: 'center' });");
    // A REAL pointer drag: the red stop rides from 0% to the far end of
    // the track (the drop lands at the blue handle's center, just short
    // of 100%, so with two stops nothing crosses; the crossing reorder is
    // the builder's own logic, GradientBehaviorTest's seam), and every
    // settle previews the draft through the token scope lane: the heading
    // repaints with red at or past 90% while the store still holds the
    // shipped value.
    $first_handle->dragTo($last_handle);
    $this->assertJsCondition(
      "(() => { const image = $gradient_js;"
      . " const moved = image.match(/rgb\\(255, 0, 0\\) (\\d+(?:\\.\\d+)?)%/);"
      . " return moved !== null && Number(moved[1]) >= 90"
      . " && Drupal.Daedalus.Materials.getRoster().get('token', 'gradient/hero-fade')?.value === " . json_encode(self::GRADIENT) . '; })()',
      20000,
      'F8: the heading computes the dragged stop (red at or past 90%) before any commit, while the store still holds the shipped value: the builder previews through the token scope lane.',
    );
    $this->clickPanel("$card .daedalus-stylish-material-card__save");
    $this->assertJsCondition(
      "(() => { const value = Drupal.Daedalus.Materials.getRoster().get('token', 'gradient/hero-fade')?.value;"
      . " const image = $gradient_js;"
      . " return typeof value === 'string' && value !== " . json_encode(self::GRADIENT)
      . " && /#0000ff/.test(value) && Number((value.match(/#ff0000 (\\d+(?:\\.\\d+)?)%/) ?? [0, 0])[1]) >= 90"
      . " && !document.querySelector(" . json_encode("$card .daedalus-stylish-material-card__save") . ')'
      . " && Number((image.match(/rgb\\(255, 0, 0\\) (\\d+(?:\\.\\d+)?)%/) ?? [0, 0])[1]) >= 90; })()",
      20000,
      'F8: Save value landed update_material (kind token): the store carries the moved stop, the editor closed, and the heading keeps the new gradient through the delivery (the recompiled skin file, no reload).',
    );
    $skin_storage->resetCache();
    $skins = $skin_storage->loadByProperties(['machine_name' => 'olivero']);
    $skin = reset($skins);
    assert($skin instanceof DaedalusStylishSkinInterface);
    $saved = $skin->siteTokens()['gradient']['hero-fade']['value'] ?? NULL;
    $this->assertIsString($saved, 'F8: the gradient token\'s new value lives in the site layer.');
    $this->assertMatchesRegularExpression('/#ff0000 (9\d(?:\.\d+)?|100)%, #0000ff/', $saved, 'F8: the saved gradient carries the dragged stop\'s serialized percent (at or past 90%) ahead of the blue stop.');
  }

}
