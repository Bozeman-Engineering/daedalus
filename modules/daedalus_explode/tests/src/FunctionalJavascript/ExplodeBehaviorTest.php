<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_explode\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the Explode panel's wiring on a real, page-built page.
 *
 * Everything the module computes is locked in node tests: the plane model
 * (depth, cap, isolate, reach), the stylesheet printer (byte-exact CSS)
 * and the settings store under tests/js; daedalus_ui's Show-menu
 * composition under its own tests/js. What only a browser can see is the
 * wiring between them and the page builder, which is what this one
 * scenario pins:
 * - the Page elements panel's Show kebab listing the module's registered
 *   item (app.tree.registerShowMenuItem) as a checked entry that reads the
 *   panel's DOM open state, and the item's run() reaching the sidebar
 *   manager and the module's sidebar plugin type registered through the
 *   DaedalusUiQueue seam;
 * - the plugin's open() stamping data-daedalus-ui-canvas-view="exploded"
 *   on <html> and starting the host, whose printed <style> actually lands
 *   on the canvas: the stage's computed transform is a 3D matrix with its
 *   isolation released, a block's own computed transform carries a
 *   non-zero Z translation (the lift composed under preserve-3d), and its
 *   ::before chip reads the block's type through the Blueprint facade;
 * - the selection emphasis: the printed emphasis factor on the canvas
 *   parent and the primary's oversized outline computed from it;
 * - the canvas-view contract across modules: daedalus_ui's selection frame
 *   hides its strokes under the attribute in its OWN stylesheet, with the
 *   frame still tracking the selection;
 * - the Zoom control: the panel's plus stepper writing the settings store
 *   and the host re-printing the stage scale from it;
 * - daedalus_ui's hotkey dispatcher (a keydown on document) reaching the
 *   sidebar.{id} binding, which, with no toggle button on the page, falls
 *   back to the sidebar manager and closes the panel: the panel hides at
 *   once, the attribute drops only after the return transition, the style
 *   is removed, the block's transform is none and the frame's strokes
 *   paint again.
 *
 * The scenario, in dependency order:
 * - FIXTURE: the daedalus recipe's site (DaedalusFixtureTrait) plus the
 *   daedalus_explode module, as daedalus_editor; a page node in Edit Mode
 *   through the cookie entry (the full chrome renders every applicable
 *   sidebar panel with its build()-attached library, which is how the
 *   Explode panel and its bundle reach the page); one section holding one
 *   formatted text block, placed through the blueprint facade.
 * - AT REST: the panel rendered closed with NO top-bar toggle button, no
 *   canvas-view attribute, the sidebar.daedalus_explode binding registered
 *   on x, the block selected and its primary stroke painting.
 * - OPEN BY MENU: the Show kebab lists an unchecked Explode item;
 *   activating it opens the panel, stamps the attribute, prints the sheet,
 *   tilts the stage, lifts the block, labels it, and hides the frame's
 *   strokes; the reopened menu shows the item checked.
 * - SELECTION EMPHASIS: the canvas parent carries the emphasis factor and
 *   the primary's outline computes wider than the flat frame's stroke.
 * - ZOOM: the plus stepper moves the stage scale one step and the readout
 *   follows.
 * - CLOSE BY HOTKEY: x, with no button to click, hides the panel at once;
 *   the attribute, the sheet and the lift are gone once the return has
 *   run, and the strokes are back.
 *
 * One fixture, one scenario: a full site install from the recipe is the
 * dominant cost of a run, so all behavior lives in the single test method.
 *
 * @group daedalus_explode
 */
#[Group('daedalus_explode')]
#[RunTestsInSeparateProcesses]
class ExplodeBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * The Explode panel's DOM id (the sidebar plugin id, verbatim).
   */
  private const PANEL_ID = 'daedalus_explode';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
    // The recipe ships the page builder; Explode is an opt-in role on top,
    // installed after the recipe so it joins the real composition.
    $this->container->get('module_installer')->install(['daedalus_explode']);
  }

  /**
   * The JS expression for a plane element's own translateZ, in pixels.
   *
   * A transform is computed as a matrix; translateZ lands in the 3D
   * matrix's fifteenth value (m43). A 2D matrix or none reads 0.
   */
  private function translateZJs(string $selector): string {
    $selector_json = json_encode($selector);
    return "(() => { const t = getComputedStyle(document.querySelector($selector_json)).transform;"
      . " return t.startsWith('matrix3d(') ? parseFloat(t.slice(9).split(',')[14]) : 0; })()";
  }

  /**
   * The Explode panel's open state, the sidebar manager's DOM truth.
   */
  private function panelOpenJs(): string {
    return "(() => { const p = document.getElementById('" . self::PANEL_ID . "');"
      . " return !!p && !p.classList.contains('daedalus-ui-hidden'); })()";
  }

  /**
   * The JS expression for the Explode item in the open Show menu, or null.
   *
   * The menu is the shared primitive riding the floating surface; the item
   * is the menuitemcheckbox whose label reads "Explode".
   */
  private function menuItemJs(): string {
    return "Array.from(document.querySelectorAll('.daedalus-ui-popover > .daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent === 'Explode') ?? null";
  }

  /**
   * The JS expression for a custom property the sheet prints on the
   * canvas parent, trimmed.
   */
  private function canvasParentPropertyJs(string $property): string {
    return "getComputedStyle(document.querySelector('.dialog-off-canvas-main-canvas')).getPropertyValue('$property').trim()";
  }

  /**
   * The Explode panel opens from the Show menu and closes by hotkey.
   */
  public function testExplodeBehavior(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // ——— FIXTURE. ———
    $node = Node::create([
      'type' => 'page',
      'title' => 'Explode page',
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
      "document.documentElement.classList.contains('edit_mode')",
      20000,
      'Edit Mode is on in the JS runtime (the cookie entry armed the hotkey dispatcher).',
    );

    // One section holding one formatted text block, through the blueprint
    // facade: the block plane sits two steps below the root, one above its
    // section, so its own lift is one gap.
    $uuid_service = \Drupal::service('uuid');
    $section_uuid = $uuid_service->generate();
    $block_uuid = $uuid_service->generate();
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
                  value: '<p>Explode target text</p>',
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
    $block_key = "block:$block_uuid";
    $block_selector = "[data-selectable='$block_key']";
    $this->assertJsCondition(
      "window.__placementError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$block_uuid')",
      20000,
      'The block placement resolved.',
    );
    $placement_error = $session->evaluateScript('window.__placementError ?? null');
    $this->assertNull($placement_error, 'Block placement failed: ' . ($placement_error ?? ''));
    $this->assertNotNull(
      $assert_session->waitForElement('css', $block_selector, 20000),
      'The formatted text block rendered on the canvas.',
    );

    // ——— AT REST. ———
    // The cookie entry rendered the Explode panel (the sidebar plugin
    // applies in edit mode) with its bundle, closed, and NO top-bar toggle:
    // the panel's opener is the Show-menu entry, so the button's absence is
    // what makes the hotkey take the manager fallback below.
    $this->assertNotNull(
      $assert_session->waitForElement('css', '#' . self::PANEL_ID, 20000),
      'The Explode panel rendered with the Edit Mode chrome.',
    );
    $assert_session->elementNotExists('css', '[data-sidebar-button-for="#' . self::PANEL_ID . '"]');
    $this->assertFalse(
      (bool) $session->evaluateScript($this->panelOpenJs()),
      'The Explode panel rendered closed.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript("document.documentElement.hasAttribute('data-daedalus-ui-canvas-view')"),
      'No canvas view is set while the panel is closed.',
    );
    $this->assertNull(
      $session->evaluateScript("document.getElementById('daedalus-explode')"),
      'No exploded sheet is printed while the panel is closed.',
    );
    // The panel's Preact controls mounted into the server-rendered mount
    // (the bundle registered through the queue and its behavior attached).
    $this->assertJsCondition(
      "!!document.querySelector('#daedalus-explode-panel[data-daedalus-explode-mounted]') && !!document.querySelector('#daedalus-explode-panel .daedalus-explode__range')",
      20000,
      'The Explode bundle mounted the panel controls into the mount point.',
    );
    // The panel's toggle binding registered from the server map under the
    // declared key, in the Panels section, remappable.
    $this->assertJsCondition(
      "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Hotkeys"
      . " && Drupal.Daedalus.Hotkeys.getBindings().some((b) => b.id === 'sidebar." . self::PANEL_ID . "' && b.key === 'x' && b.remappable === true)",
      20000,
      'The sidebar.daedalus_explode hotkey binding registered on x.',
    );

    // Select the block: the frame paints its primary stroke from the
    // block's rect, which is the chrome the exploded view must stand down.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_key');");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$block_key'",
      10000,
      'The block is selected.',
    );
    $stroke_display_js = "(() => { const s = document.querySelector('.daedalus-ui-frame__stroke--primary');"
      . " return s ? getComputedStyle(s).display : 'missing'; })()";
    $this->assertJsCondition(
      "$stroke_display_js !== 'none' && $stroke_display_js !== 'missing'",
      10000,
      'The selection frame paints the primary stroke while the canvas is flat.',
    );
    $this->assertSame(
      'none',
      $session->evaluateScript("getComputedStyle(document.querySelector(\"$block_selector\")).transform"),
      'The block has no transform while the canvas is flat.',
    );

    // ——— OPEN BY MENU. ———
    // Seam: the Page elements panel's Show kebab -> the registered item
    // (app.tree.registerShowMenuItem, composed as the menu opens with
    // checked read from the panel's DOM state) -> the item's run() -> the
    // sidebar manager -> the module's plugin open() -> the attribute and
    // the host's first print. The kebab is concealed at minimal density
    // until the pointer nears, so it is clicked from JS.
    $menu_item_js = $this->menuItemJs();
    $this->assertNotNull(
      $assert_session->waitForElement('css', '.daedalus-ui-show-menu__button', 20000),
      'The Page elements panel renders its Show kebab (the registered Explode item counts toward it).',
    );
    $session->executeScript("document.querySelector('.daedalus-ui-show-menu__button').click();");
    $this->assertJsCondition(
      "(() => { const item = $menu_item_js; return !!item && item.getAttribute('role') === 'menuitemcheckbox' && item.getAttribute('aria-checked') === 'false'; })()",
      10000,
      'The Show menu lists Explode as an unchecked item while the panel is closed.',
    );
    $this->assertJsCondition(
      "(() => { const item = $menu_item_js; return !!item && !!item.querySelector('.daedalus-ui-menu__icon svg'); })()",
      10000,
      'The Explode item wears its inline stack glyph.',
    );
    $session->executeScript("($menu_item_js).click();");
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover')",
      10000,
      'Activating the item closed the Show menu.',
    );
    $this->assertJsCondition(
      $this->panelOpenJs(),
      10000,
      'The Explode item opened the Explode panel.',
    );
    // The item's checked state is read as the menu opens, from the panel's
    // DOM open state: reopened, the menu shows it checked. The kebab click
    // while open closes the menu again (the anchor counts as inside).
    $session->executeScript("document.querySelector('.daedalus-ui-show-menu__button').click();");
    $this->assertJsCondition(
      "(() => { const item = $menu_item_js; return !!item && item.getAttribute('aria-checked') === 'true'; })()",
      10000,
      'The reopened Show menu shows Explode checked while the panel is open.',
    );
    $session->executeScript("document.querySelector('.daedalus-ui-show-menu__button').click();");
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover')",
      10000,
      'The kebab closed the Show menu again.',
    );
    $this->assertJsCondition(
      "document.documentElement.getAttribute('data-daedalus-ui-canvas-view') === 'exploded'",
      10000,
      'Opening the panel stamped data-daedalus-ui-canvas-view="exploded" on html.',
    );
    $this->assertJsCondition(
      "(() => { const s = document.getElementById('daedalus-explode');"
      . " return !!s && s.textContent.includes('[data-selectable=\"$block_key\"]'); })()",
      10000,
      'The host printed the exploded sheet with a rule for the block plane.',
    );
    // The sheet lands on the canvas: the stage is projected (a 3D matrix,
    // its isolation released so the planes are not flattened), the block
    // is lifted by its own step above the section.
    $this->assertJsCondition(
      "(() => { const cs = getComputedStyle(document.getElementById('daedalus-ui-stage'));"
      . " return cs.transform.startsWith('matrix3d(') && cs.isolation === 'auto' && cs.transformStyle === 'preserve-3d'; })()",
      10000,
      'The stage computes a 3D transform under preserve-3d with its isolation released.',
    );
    $this->assertJsCondition(
      $this->translateZJs($block_selector) . ' > 0',
      10000,
      'The block\'s own computed transform is a matrix3d with a positive Z translation (its lift).',
    );
    $this->assertJsCondition(
      $this->translateZJs("[data-selectable='container:$section_uuid']") . ' > 0',
      10000,
      'The section\'s own computed transform lifts it too (each plane lifts one step above its plane parent).',
    );
    // The chip: the block plane speaks its type through the Blueprint
    // facade's one type resolver (the host's wording rule), printed as the
    // ::before content. This is the leg that found the host calling the
    // resolver on the Selection facade, where it does not exist.
    $this->assertJsCondition(
      "(() => { const el = document.querySelector(\"$block_selector\");"
      . " const type = Drupal.Daedalus.Blueprint.typeForKey('$block_key');"
      . " return !!type && getComputedStyle(el, '::before').content === JSON.stringify(type); })()",
      10000,
      'The block\'s ::before chip reads the block type from the Blueprint facade\'s typeForKey.',
    );
    // The cross-module contract: daedalus_ui's frame hides its strokes
    // under the attribute in its own stylesheet, while the selection (and
    // the frame's tracking of it) is untouched.
    $this->assertJsCondition(
      "$stroke_display_js === 'none'",
      10000,
      'The selection frame\'s primary stroke is hidden under the exploded view.',
    );
    $this->assertSame(
      $block_key,
      $session->evaluateScript('Drupal.Daedalus.Selection.getSelected()'),
      'Exploding kept the selection.',
    );

    // ——— SELECTION EMPHASIS. ———
    // Seam: the printer's stage rules put the emphasis factor on the
    // canvas parent (an unregistered custom property substitutes its
    // var() but never evaluates the calc(), so the token text is what
    // computes), and the paint rules size the primary's outline by it: at
    // the default scale the outline computes to 4px / 0.62, well over the
    // flat frame's 2px stroke.
    $this->assertJsCondition(
      $this->canvasParentPropertyJs('--daedalus-explode-emphasis') . ".includes('calc(')",
      10000,
      'The canvas parent carries the printed emphasis factor.',
    );
    $this->assertJsCondition(
      "parseFloat(getComputedStyle(document.querySelector(\"$block_selector\")).outlineWidth) > 2",
      10000,
      'The selected block\'s outline computes wider than 2px (the oversized selection).',
    );

    // ——— ZOOM. ———
    // Seam: the panel's plus stepper -> settings.set({scale}) -> the host's
    // re-print -> the stage scale on the canvas parent; the readout follows
    // the store. The default 0.62 steps to exactly 0.72.
    $this->assertSame(
      '0.62',
      $session->evaluateScript($this->canvasParentPropertyJs('--daedalus-explode-scale')),
      'The stage scale starts at the default.',
    );
    $session->executeScript("document.querySelector('#daedalus-explode-panel .daedalus-explode__step--plus').click();");
    $this->assertJsCondition(
      $this->canvasParentPropertyJs('--daedalus-explode-scale') . " === '0.72'",
      10000,
      'The plus stepper moved the printed stage scale one step up.',
    );
    $this->assertJsCondition(
      "document.querySelector('#daedalus-explode-panel output[for=\"daedalus-explode-scale\"]')?.textContent === '72%'",
      10000,
      'The Zoom readout follows the store.',
    );

    // ——— CLOSE BY HOTKEY. ———
    // Seam: the dispatcher's keydown on document -> the sidebar.{id}
    // binding, which finds no toggle button on the page and toggles the
    // panel through the sidebar manager by its DOM open state -> close()
    // -> the host's flat re-print with the attribute still on -> after the
    // return transition, the sheet and the attribute drop together.
    $this->pressHotkey('x');
    $this->assertJsCondition(
      '!' . $this->panelOpenJs(),
      10000,
      'x closed the Explode panel through the manager fallback.',
    );
    $this->assertJsCondition(
      "!document.documentElement.hasAttribute('data-daedalus-ui-canvas-view')",
      10000,
      'The canvas-view attribute is gone once the return has run.',
    );
    $this->assertJsCondition(
      "!document.getElementById('daedalus-explode')",
      10000,
      'The exploded sheet was removed with the attribute.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$block_selector\")).transform === 'none'",
      10000,
      'The block\'s transform is none again.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.getElementById('daedalus-ui-stage')).transform === 'none'",
      10000,
      'The stage\'s transform is none again.',
    );
    $this->assertJsCondition(
      "$stroke_display_js !== 'none' && $stroke_display_js !== 'missing'",
      10000,
      'The selection frame paints the primary stroke again on the flat canvas.',
    );
  }

}
