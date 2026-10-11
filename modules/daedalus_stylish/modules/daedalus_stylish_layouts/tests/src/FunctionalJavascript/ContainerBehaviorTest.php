<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish_layouts\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the one-box container's behavior on a real page.
 *
 * The capability under test is the one-box container model: a container is
 * ONE element at every depth. A page band is a real <section> whose root
 * carries the width inset (padding-inline), the arrangement (flex on
 * itself), and the empty-drop affordance; nested, the same declaration
 * resolves to a plain grouping <div> with no width vocabulary. Kernel tests
 * pin the declaration, the resolver, and the printed CSS; every step here
 * locks a WIRING seam that only exists where the palette, the drag layer,
 * the widgets, the op queue, tempstore, and the rendered canvas meet in a
 * browser:
 * - Palette placement, whole pipeline: dragging the Add panel's Container
 *   item onto the empty page arms the drag layer (dragstart on the panel
 *   item), the caret offers the empty flow, and the drop's add_section
 *   round trip renders a root <section> with NO inner div — the composed
 *   render straight from the declaration. The empty affordance PAINTS:
 *   `content: attr(data-container-empty-hint)` resolving into a rendered
 *   ::before, the dashed outline, and the min-height floor are browser
 *   facts (the kernel sees only the stamped attribute).
 * - A stacked band lays out its children with the skin's gap (seam: the
 *   composed kind's declared `display: flex; flex-direction: column`
 *   compiled into the defaults file, stamped on the section root, and
 *   actually laying out the canvas): the container kind declares its
 *   root display and direction as token-free code
 *   (daedalus_stylish_layouts.page_builder.yml), the skin carries only the
 *   look (`gap: var(--space-gutter)` on kind:container, the shape every
 *   shipped skin uses), so a newborn band holding two blocks computes
 *   `display: flex`, `flex-direction: column` and a `row-gap` equal to
 *   the minted gutter, and the blocks sit that far apart. Before the
 *   declared tier the gap was dead on this very skin (a gap with no
 *   arranging display paints nothing): the Kernel tier pins the compiled
 *   block; only a browser proves it arranges.
 * - Columns lands as computed style: a page band is BORN stacked (no
 *   arrangement reference at the add_section door), so the palette band
 *   computes the declared column before any gesture. A real click on the
 *   Layout widget's Columns segment commits apply_variant on the SELECTED
 *   root key in ONE blueprint POST and the computed `flex-direction`
 *   becomes row; clicking Stack commits remove_variant the same way and
 *   the direction is column again, the display flex both ways (the row
 *   variant flips the direction, it never ships a display) — widget op →
 *   mutation response → inline variant CSS → computed style, four layers
 *   that only agree in a browser, exercised in both directions. The
 *   children pseudo-part's equal fill shows as two side-by-side equal
 *   columns.
 * - Row is the second shipped arrangement on the same axis (seam: the
 *   Layout widget's three-mode segment over the worn shipped name, the
 *   within-axis replace, and the mode-scoped alignment rows). With
 *   Columns worn, a real click on Row commits ONE apply_variant (one
 *   POST) and the root computes `flex-direction: row` AND `flex-wrap:
 *   wrap` with the two blocks side by side at their NATURAL width (Row
 *   ships no children rule, so no equal fill); the section offers the
 *   Horizontal row as justify-content with FOUR stops, Spread the fourth,
 *   and a real click on Spread writes `justify-content: space-between`
 *   on the band's own block in one POST, computed on the root and laid
 *   out (the blocks pushed to the content box's two edges). Clicking
 *   Columns replaces Row in one POST: the wrap goes with the variant,
 *   the equal fill returns, and the Horizontal row is GONE while Vertical
 *   remains (columns fill the main axis; justify-content has nothing to
 *   distribute). Mode reads and axes per mode are node-locked; only a
 *   browser proves the flip lands, lays out and re-scopes the rows.
 * - Width is variant delivery in edit mode: the wear bar's worn Width row
 *   reads the birth default (Site width, container_site) whose
 *   `padding-inline: max(var(--space-gutter), calc((100% -
 *   var(--layout-max-width)) / 2))` must RESOLVE through the active skin's
 *   compiled token file on the token scope — picking Small (640px)
 *   through the row's swap trigger (the variant picker preset to Width)
 *   changes the computed inset on the same root to the 640px stop's
 *   formula. Token minting, variant delivery, and the cascade only meet
 *   in a browser.
 * - A masked wearing is legible where it is chosen: the root grant is the
 *   whole spacing group, so the padding row writes the `padding`
 *   shorthand on the band, and the band's own padding beats the worn
 *   width inset (own overrides beat worn variants). Padding is bound
 *   site-wide by the suite (daedalus_stylish.page_builder.yml binds it to the
 *   space scale), so the row's offer is the scenario skin's space steps
 *   through its stop picker and the write is a token (the lg step's
 *   reference, landed through magnetic capture), never the built-in
 *   table. The wear bar's Width row must then read overridden, its
 *   sub-line naming the lost `padding-inline` (the shadow read matches
 *   on the longhand grain, not the property name), and its Reset
 *   overrides row action must drop the own padding so the inset
 *   delivers again. Seams: the padding row's grant read over the site
 *   binding, the grain-aware shadow read, and the row action's
 *   update_style unset landing as computed style.
 * - Nested is a plain <div> with no Width offer: an add_section inside
 *   the band renders the residual div form as the band's DIRECT child,
 *   and selecting it re-renders the wear bar with no worn row (it wears
 *   nothing) and a variant picker WITHOUT a Width group (the root-only
 *   axis filtered by the client's nested-depth read).
 * - The Layout section obeys both halves of the pose doctrine: the Width
 *   chip poses the canvas at Narrow (the vector stamp, the container
 *   query re-evaluating on the stage), the segment keeps reading Columns
 *   (a wear surface, unconditioned) with the sub-line "Columns stacks at
 *   Narrow" and no proportion strip, the Gap row reads the layer stack
 *   at the pose (the worn variant's gutter, lit, its mark naming the
 *   delivering condition), and a typed gap lands under `narrow` ALONE (no
 *   companion: the worn row variant's narrow block is literally the
 *   stack, so the widget has no display to write), changing the canvas
 *   at narrow only, a flex column with the typed gap; un-posed, the flex
 *   row, its gutter gap (the scenario skin's 32px), the strip and the
 *   variant's lit value are back. The pose → vector → widget read/write seam only
 *   exists in a browser.
 * - A stacked child's Bottom is a browser-resolved auto margin: a tall
 *   band (a typed `min-height: 60vh` on the band, the op door standing in
 *   for the advanced row's update_style) holding two text blocks, the
 *   last one selected and drilled into its basic Position widget, whose
 *   Vertical row under a flex-column parent is the auto-margin pair
 *   (widget-values.js positionAxes over the parent's computed facts). A
 *   real click on Bottom commits ONE update_style on the block's key
 *   carrying exactly `margin-top: auto` (margin-bottom absent: the pair
 *   write trims the side it does not own), the computed `margin-top`
 *   resolves to a non-zero used length, and the block's margin box
 *   (rect.bottom plus its own margin-bottom, the theme's paragraph
 *   rhythm) meets the band's content-box bottom within 1px while its
 *   sibling stays at the top; the row's Bottom chip reads lit from the
 *   pair. Seam: the widget's pair write through the op door to a
 *   browser-resolved auto margin, which no node test can see (the node
 *   tier pins the write's shape; only the flex algorithm hands the free
 *   space to the margin).
 * - The Layout section's alignment rows light the container stack's
 *   winner at the pose and write at the compound: on the tall band (born
 *   stacked, so the section offers both screen axes) the Horizontal row
 *   is align-items on the flex column, unlit while nothing delivers it;
 *   a real click on Center commits ONE update_style on the band's key
 *   carrying `align-items: center` with no condition (the compound is
 *   Base), the canvas centres the children, and Center lights. Posed
 *   Narrow through the Width chip the band still computes centred (the
 *   default block reaches narrow through the condition cascade) and
 *   Center STAYS lit: the row reads the stack's winner at the pose
 *   (layout-widget.js alignmentState over stackAt, the Gap row's read),
 *   not the narrow block's own value, which is empty. A click on Right
 *   at Narrow then lands `align-items: flex-end` under `narrow` ALONE
 *   (default keeps its centre), the canvas ends the children at narrow,
 *   Right lights; un-posed, Center is lit again and the canvas is
 *   centred. Seam: alignmentState's read into the lit segment, which
 *   the node spec pins as a value and only the posed panel proves as a
 *   face.
 * - The empty hint outranks a page's own generated box (seam: the
 *   specificity fight between the affordance stylesheet, a head
 *   stylesheet, and the entity's inline map CSS later in the document,
 *   which wins every tie): an empty band minted after the tall one
 *   paints the hint; a user `&::before` on it, minted through the op
 *   spine and born with the seed `content: ""`, prints as
 *   `:scope::before` inside the band's @scope at 0-1-1, and the hint's
 *   rule (`#daedalus-ui-stage [data-container-empty-hint]::before`,
 *   1-1-1, opening with `all: initial`) still paints its text, a
 *   transparent background and the full width over the page's content,
 *   red and 10px: the chrome asserts its own box while the band is
 *   empty. Only a real cascade orders the two stylesheets; the kernel
 *   tier prints each alone. Representative of the chrome class
 *   (explode's chip reset rides the same law).
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site, as
 * daedalus_editor) wearing a scenario skin in place of the recipe's
 * olivero: the skin mints the two tokens the shipped width variants
 * reference (--space-gutter, --layout-max-width) in literal px so the
 * inset math below is unit-exact, a space ladder for the padding row's
 * offer (the suite binds padding and gap to the space scale site-wide,
 * and a bound scale offers only what the skin mints), and the one look
 * every shipped skin gives the container kind, its gap as the gutter.
 * The skin rides a system the test builds in code (the trait's
 * activateSkin, what a site builder creating a system in the UI
 * produces), and the test authors that system's mode as open: the
 * class proves the container's mechanics (birth, arrangement, width
 * delivery, pose writes, a child's placement), not scale law, so the
 * bindings shape the
 * offers without closing the rows; the scenarios that prove law
 * activate the shipped strict reference (guide_example) instead. One
 * fixture, one scenario: a full site install from the recipe is by far
 * the most expensive part of a run, so all behavior lives in the single
 * test method.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class ContainerBehaviorTest extends WebDriverTestBase {

  use DesignSystemActivationTrait;
  use EditModeTrait;
  use DaedalusFixtureTrait;

  /**
   * Scrolls a panel element into view and really clicks it.
   *
   * The inspector is a scrollable right-rail panel, so a control can sit
   * below its fold; centering it first keeps the click a REAL pointer
   * click (the wiring under test) instead of a synthetic dispatch.
   */
  private function clickByXpath(string $xpath): void {
    $element = $this->assertSession()->waitForElement('xpath', $xpath);
    $this->assertNotNull($element, sprintf('The element %s is present to click.', $xpath));
    $this->getSession()->executeScript(
      'document.evaluate(' . json_encode($xpath) . ", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue.scrollIntoView({ block: 'center' });"
    );
    $element->click();
  }

  /**
   * The CSS twin of clickByXpath, for elements a selector names.
   */
  private function clickBySelector(string $selector): void {
    $element = $this->assertSession()->waitForElement('css', $selector);
    $this->assertNotNull($element, sprintf('The element %s is present to click.', $selector));
    $this->getSession()->executeScript(
      'document.querySelector(' . json_encode($selector) . ").scrollIntoView({ block: 'center' });"
    );
    $element->click();
  }

  /**
   * The palette band, arrangement, width delivery, the nested div, and a
   * child pinned to a tall band's bottom.
   */
  public function testOneBoxContainerScenario(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // The token fixture: an active skin minting the two tokens the
    // shipped width variants reference, plus a space ladder. Values are
    // literal px so the padding math below is unit-exact regardless of
    // the theme's root font size. The ladder is what the padding row
    // offers: the suite binds padding (and gap) to the space scale
    // site-wide, and a bound scale's offer is the skin's minted tokens,
    // never the built-in table, so a skin minting only the gutter would
    // give the row one step. No step is 24px: the pose leg types 24 into
    // the Gap row as a literal, and a matching step would capture it to
    // a reference. The skin also carries the container kind's LOOK the
    // way every shipped skin does (gap as the gutter, on kind:container's
    // root) and nothing of its mechanic: the arranging `display: flex;
    // flex-direction: column` is the composed kind's declared default in
    // code, which is what the stacked-band leg below proves lays the gap
    // out. Created BEFORE the
    // page loads so the compiled skin and defaults files are linked into
    // the document under test.
    $skin = DaedalusStylishSkin::create([
      'label' => 'Scenario skin',
      'machine_name' => 'scenario_skin',
      'status' => TRUE,
    ]);
    $skin->setPackageTokens([
      'space' => ['sm' => '8px', 'md' => '16px', 'lg' => '48px', 'gutter' => '32px'],
      'layout' => ['max-width' => '1024px'],
    ]);
    $skin->setPackageDefaults([
      'kind:container' => ['' => ['default' => ['gap' => 'var(--space-gutter)']]],
    ]);
    $skin->save();
    // The skin's system is the test's own (built in code around the
    // skin, displacing the recipe's olivero), so its mode is the test's
    // to author: open, because the class proves mechanics, not law (see
    // the class docblock). The suite's bindings still shape the offers.
    $this->activateSkin($skin);
    $this->enforce('open');
    $this->assertSame(
      'scenario_skin',
      \Drupal::service('daedalus_stylish.skin_manager')->activeSkinName(),
      'The scenario skin activated: --space-gutter and --layout-max-width are minted for the width variants to resolve through, and the space ladder for the padding row to offer.',
    );
    $this->assertSame(
      'open',
      \Drupal::service('daedalus_stylish.style_guide')->enforcement(),
      'The scenario skin\'s own system is open: the suite\'s space binding shapes the padding and gap offers without closing the rows.',
    );

    // A real Layout Builder page, empty: the palette drop below is what
    // gives it its first band.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Container behavior page',
    ]);
    $node->save();
    $nid = (int) $node->id();

    $this->drupalGet($node->toUrl());

    // A WIDE window on purpose: the row variant stacks its columns under
    // the `narrow` band, and the width formulas only rise above their
    // gutter floor on a wide stage.
    $session->resizeWindow(1920, 1200);

    // Enter Edit Mode the way the recipe starts a real page: the
    // server-rendered cookie path, which renders the full Edit Mode chrome
    // (the Add panel with its palette items, the inspector, the blueprint
    // mirror boot).
    $this->enterEditModeViaCookies($node->toUrl());
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted.',
    );

    // Two scenario-wide sentinels. The reload sentinel proves every round
    // trip below happened in THIS document; the POST counter is how
    // "one gesture, one blueprint POST" is a wiring fact rather than an
    // inference from the map shape.
    $session->executeScript(<<<JS
      window.__sameDocument = true;
      window.__blueprintPosts = 0;
      window.__fetchLog = [];
      const originalFetch = window.fetch;
      window.fetch = function (url, options) {
        const target = url && url.url ? url.url : String(url);
        const method = (options && options.method) || (url && url.method) || 'GET';
        window.__fetchLog.push(method + ' ' + target);
        if (target.includes('daedalus-blueprint') && method === 'POST') {
          window.__blueprintPosts++;
        }
        return originalFetch.apply(this, arguments);
      };
      const originalOpen = XMLHttpRequest.prototype.open;
      XMLHttpRequest.prototype.open = function (method, target) {
        window.__fetchLog.push('XHR ' + method + ' ' + target);
        return originalOpen.apply(this, arguments);
      };
      JS);

    // ——— (a) A page band via the palette. ———
    //
    // Open the Add panel (its toggle's own API; the cookie entry path can
    // render the sidebar either way) so the gesture starts where a user's
    // does: the panel's always-armed Container item.
    $assert_session->waitForElement('css', '#place_block');
    $session->executeScript(<<<JS
      (() => {
        const panel = document.getElementById('place_block');
        if (panel && panel.classList.contains('daedalus-ui-hidden')) {
          Drupal.Daedalus.SidebarManager.openSidebar('place_block');
        }
      })();
      JS);
    // The Container's row under Favorites (the one set open by default;
    // the Container is a favorite by the panel's own word) is a drag
    // source only once the bundle's row mounted and armed it
    // (makeDraggable assigns the handler property); waiting on that is the
    // deterministic "palette is live" signal.
    $this->assertJsCondition(
      "(() => { const item = document.querySelector('#place_block [data-daedalus-layout-add-panel-in=\"favorites\"][data-daedalus-layout-add-panel-row=\"daedalus_layout_section\"]');"
      . " return !!item && typeof item.ondragstart === 'function'; })()",
      20000,
      'The Add panel rendered the Container item and the drag layer armed it.',
    );

    // The drag replays the native sequence the drag controller consumes:
    // dragstart on the palette item (arms activeSource through the item's
    // own handler — the real palette wiring, not beginExternalDrag), then
    // dragover across the empty canvas.
    $session->executeScript(<<<JS
      (() => {
        const dt = new DataTransfer();
        document.querySelector('#place_block [data-daedalus-layout-add-panel-in="favorites"][data-daedalus-layout-add-panel-row="daedalus_layout_section"]').dispatchEvent(
          new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: dt }),
        );
        const target = document.querySelector('[data-main-entity]');
        target.scrollIntoView({ block: 'center' });
        const rect = target.getBoundingClientRect();
        target.dispatchEvent(new DragEvent('dragover', {
          bubbles: true,
          cancelable: true,
          dataTransfer: dt,
          clientX: rect.left + rect.width / 2,
          clientY: rect.top + rect.height / 2,
        }));
      })();
      JS);
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-caret__empty') && !document.querySelector('.daedalus-ui-caret__empty').hidden",
      20000,
      'The palette Container drag armed the drag layer and the caret offered the empty page.',
    );

    // The drop commits one add_section through the blueprint spine; the
    // item's dragend closes the gesture exactly as the browser would.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $session->executeScript(<<<JS
      (() => {
        const dt = new DataTransfer();
        const target = document.querySelector('[data-main-entity]');
        const rect = target.getBoundingClientRect();
        const x = rect.left + rect.width / 2;
        const y = rect.top + rect.height / 2;
        target.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt, clientX: x, clientY: y }));
        target.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt, clientX: x, clientY: y }));
        document.querySelector('#place_block [data-daedalus-layout-add-panel-in="favorites"][data-daedalus-layout-add-panel-row="daedalus_layout_section"]').dispatchEvent(
          new DragEvent('dragend', { bubbles: true, cancelable: true, dataTransfer: dt }),
        );
      })();
      JS);
    $band = $assert_session->waitForElement('css', "[data-main-entity] [data-selectable^='container:']", 20000);
    $this->assertNotNull($band, 'The palette drop placed a container band on the page.');
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The palette drop was exactly one blueprint POST (one plain add_section — a section is born a container).',
    );
    $section_key = (string) $session->evaluateScript(
      "document.querySelector(\"[data-main-entity] [data-selectable^='container:']\").getAttribute('data-selectable')",
    );
    $section_uuid = substr($section_key, strlen('container:'));
    $root = "[data-main-entity] [data-selectable='container:$section_uuid']";

    // The one-box band: a real <section> root, nothing inside it, wearing
    // the resolver-stamped empty pair — and the affordance PAINTING.
    // `content: attr()` resolving into a rendered ::before, the dashed
    // outline, and the min-height floor are the browser half the kernel
    // cannot see (it only asserts the attribute).
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const root = document.querySelector("$root");
          const style = getComputedStyle(root);
          const before = getComputedStyle(root, '::before');
          const painted = before.content.replace(/["']/g, '');
          return root.tagName === 'SECTION'
            && !root.querySelector('div')
            && root.getAttribute('data-container-empty-hint') === 'Empty container'
            && root.hasAttribute('data-container-empty')
            && (painted === 'Empty container' || before.content.includes('data-container-empty-hint'))
            && style.outlineStyle === 'dashed'
            && parseFloat(style.minHeight) >= parseFloat(style.fontSize) * 4 - 1
            && root.getBoundingClientRect().height >= parseFloat(style.fontSize) * 4 - 1;
        })()
        JS),
      'The band is one <section> with no inner div, and the empty affordance paints: attr()-fed ::before hint, dashed outline, min-height floor.',
    );

    // ——— (b) Two blocks, then Columns. ———
    //
    // Place two formatted text blocks through the same mutation batch a
    // palette drop enqueues; the arrangement gesture below needs siblings
    // to arrange.
    $uuid_service = \Drupal::service('uuid');
    $first_uuid = $uuid_service->generate();
    $second_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_element',
              uuid: '$first_uuid',
              block_type: 'formatted_text',
              properties: {
                field_formatted_text_text: { value: '<p>Column one</p>', format: 'daedalus_edit_formatted_text' },
              },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
            {
              op: 'add_element',
              uuid: '$second_uuid',
              block_type: 'formatted_text',
              properties: {
                field_formatted_text_text: { value: '<p>Column two</p>', format: 'daedalus_edit_formatted_text' },
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
      . " || (!!document.querySelector(\"[data-selectable='block:$first_uuid']\")"
      . " && !!document.querySelector(\"[data-selectable='block:$second_uuid']\"))",
      20000,
      'The two block placements resolved.',
    );
    $placement_error = $session->evaluateScript('window.__placementError ?? null');
    $this->assertNull($placement_error, 'Block placement failed: ' . ($placement_error ?? ''));

    // The populated band re-rendered from the same declaration: the empty
    // pair is gone, and both block roots are the section root's DIRECT
    // children — no part element, no sandwich, at load like at birth.
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const root = document.querySelector("$root");
          const first = document.querySelector("[data-selectable='block:$first_uuid']");
          const second = document.querySelector("[data-selectable='block:$second_uuid']");
          return !root.hasAttribute('data-container-empty')
            && !root.hasAttribute('data-container-empty-hint')
            && first.parentElement === root
            && second.parentElement === root;
        })()
        JS),
      'Populated, the band dropped the empty pair and holds both blocks as direct children of the one section root.',
    );

    // Select the band programmatically (the facade is the sanctioned
    // programmatic face of a canvas click) and open the inspector, whose
    // Layout section is the arrangement widget's home in Basic mode.
    $assert_session->waitForElement('css', '#daedalus_stylish_inspector');
    $session->executeScript(<<<JS
      (() => {
        const panel = document.getElementById('daedalus_stylish_inspector');
        if (panel && panel.classList.contains('daedalus-ui-hidden')) {
          Drupal.Daedalus.SidebarManager.openSidebar('daedalus_stylish_inspector');
        }
      })();
      JS);
    $session->executeScript("Drupal.Daedalus.Selection.select('container:$section_uuid');");
    $this->assertJsCondition(
      "!!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__layout')",
      20000,
      'The Layout section rendered for the selected container.',
    );

    // A page band is BORN stacked: no arrangement reference is stamped at
    // the add_section door, so the palette band computes the declared
    // column before any gesture, and the widget's Stack segment is the
    // selected one. Stacked is a FLEX COLUMN: the composed container kind
    // declares its root `display: flex; flex-direction: column`
    // (daedalus_stylish_layouts.page_builder.yml, the declared tier
    // compiled into the defaults file and selecting the section root by
    // its stamped data-element address), so the skin's gap (kind:container `gap:
    // var(--space-gutter)`, the look) lays the two blocks out 32px apart.
    // Read on the root and as the blocks' geometry: the Kernel tier pins
    // the compiled block, only the browser proves it arranges.
    $stacked = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const root = document.querySelector("$root");
        const style = getComputedStyle(root);
        const first = document.querySelector("[data-selectable='block:$first_uuid']").getBoundingClientRect();
        const second = document.querySelector("[data-selectable='block:$second_uuid']").getBoundingClientRect();
        return JSON.stringify({ display: style.display, direction: style.flexDirection, rowGap: style.rowGap, between: second.top - first.bottom });
      })()
      JS), TRUE);
    $this->assertIsArray($stacked, 'The newborn band\'s computed arrangement was read.');
    $this->assertSame(
      'flex',
      $stacked['display'],
      'The palette band was born stacked as a flex box: the container kind\'s declared display compiled into the defaults file and stamped on the section root, no row variant and no gesture.',
    );
    $this->assertSame(
      'column',
      $stacked['direction'],
      'The palette band was born a flex COLUMN: the container kind\'s declared flex-direction, the stack, before any gesture (the row variant is what flips it).',
    );
    $this->assertSame(
      '32px',
      $stacked['rowGap'],
      'The stacked band computes the skin\'s gutter as its row-gap: the declared flex column gives the skin\'s gap a box to act on.',
    );
    $this->assertEqualsWithDelta(
      32.0,
      (float) $stacked['between'],
      1.0,
      'The two stacked blocks sit one gutter apart on the canvas (the row-gap laid out by the flex column, not just computed).',
    );
    $layout_mode = "//div[@id='daedalus_stylish_inspector']//span[@aria-label='Layout mode']";
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', "{$layout_mode}//button[normalize-space(.)='Stack'][@aria-pressed='true']"),
      'The Layout widget reads the newborn band as Stack.',
    );

    // The widget round trip, direction one: clicking Columns commits
    // apply_variant on the SELECTED key (one POST), and the mutation
    // response's variant CSS lands as computed `flex-direction: row` on
    // the very element the editor selected; the display stays the
    // declared flex (the row variant flips the direction, it ships no
    // display).
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickByXpath("{$layout_mode}//button[normalize-space(.)='Columns']");
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(\"$root\"));"
      . " return style.display === 'flex' && style.flexDirection === 'row'; })()",
      20000,
      'Columns landed as computed flex-direction row on the selected section root, the display still flex (op → response → variant CSS → computed style).',
    );
    // The computed change can land ahead of the network (the mirror
    // updates optimistically and the style tag re-renders client-side),
    // so the POST count is waited for, then pinned to exactly one.
    $session->wait(20000, 'window.__blueprintPosts >= ' . ($posts + 1));
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The Columns click was exactly one blueprint POST. Network log: '
      . $session->evaluateScript('JSON.stringify(window.__fetchLog)'),
    );

    // Direction two: a real click on Stack commits remove_variant on the
    // same key (one POST) and the computed direction is column again, the
    // display still flex — the un-wear delivered through the same four
    // layers. Then back to Columns so the equal-fill geometry below has a
    // row to measure.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickByXpath("{$layout_mode}//button[normalize-space(.)='Stack']");
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(\"$root\"));"
      . " return style.display === 'flex' && style.flexDirection === 'column' && style.rowGap === '32px'; })()",
      20000,
      'Stack removed the row variant: the selected root is the declared flex column again (display flex, direction column), its row-gap the skin\'s gutter.',
    );
    $session->wait(20000, 'window.__blueprintPosts >= ' . ($posts + 1));
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The Stack click was exactly one blueprint POST. Network log: '
      . $session->evaluateScript('JSON.stringify(window.__fetchLog)'),
    );
    $this->clickByXpath("{$layout_mode}//button[normalize-space(.)='Columns']");
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$root\")).flexDirection === 'row'",
      20000,
      'Columns re-applied (computed flex-direction row) for the geometry check.',
    );
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const first = document.querySelector("[data-selectable='block:$first_uuid']").getBoundingClientRect();
          const second = document.querySelector("[data-selectable='block:$second_uuid']").getBoundingClientRect();
          return Math.abs(first.top - second.top) < 2
            && second.left > first.right - 2
            && Math.abs(first.width - second.width) < 2;
        })()
        JS),
      'The two blocks sit side by side as equal columns (the ::> * equal-fill children rule resolved in layout).',
    );

    // ——— (b') Row, Spread, and back to Columns. ———
    //
    // Row is the plain flex row an editor used to hand-write in code mode
    // (which the widget could only call Custom): the shipped `row`
    // variant on the same container_layout axis, `flex-direction: row`
    // and `flex-wrap: wrap` with NO children rule, so the blocks keep
    // their natural width instead of Columns' equal fill. A real click on
    // the Row segment while Columns is worn commits ONE apply_variant
    // (the within-axis replace) in one POST, and both declarations land
    // as computed style on the selected root.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickByXpath("{$layout_mode}//button[normalize-space(.)='Row']");
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(\"$root\"));"
      . " return style.display === 'flex' && style.flexDirection === 'row' && style.flexWrap === 'wrap'; })()",
      20000,
      'Row landed as computed flex-direction row AND flex-wrap wrap on the selected section root (Columns ships no wrap: the wrap is the Row variant\'s own declaration, delivered through the same four layers).',
    );
    $session->wait(20000, 'window.__blueprintPosts >= ' . ($posts + 1));
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The Row click was exactly one blueprint POST (replacing Columns by Row on the axis is one apply_variant). Network log: '
      . $session->evaluateScript('JSON.stringify(window.__fetchLog)'),
    );
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', "{$layout_mode}//button[normalize-space(.)='Row'][@aria-pressed='true']"),
      'The Layout widget reads the band as Row: the mode is the worn shipped name.',
    );
    // The blocks' geometry under Row: side by side one gutter apart, at
    // natural width. Read against the root's content box so "natural"
    // is a fact about the row (the pair fills well under half of it)
    // rather than a guess at the paragraphs' pixel widths.
    $row_geometry = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const root = document.querySelector("$root");
        const style = getComputedStyle(root);
        const box = root.getBoundingClientRect();
        const first = document.querySelector("[data-selectable='block:$first_uuid']").getBoundingClientRect();
        const second = document.querySelector("[data-selectable='block:$second_uuid']").getBoundingClientRect();
        return JSON.stringify({
          content: box.width - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight),
          columnGap: style.columnGap,
          sameRow: Math.abs(first.top - second.top) < 2,
          between: second.left - first.right,
          widths: [first.width, second.width],
        });
      })()
      JS), TRUE);
    $this->assertIsArray($row_geometry, 'The Row band\'s geometry was read.');
    $this->assertTrue($row_geometry['sameRow'], 'Under Row the two blocks share a line (flex-direction row laid out). Read: ' . json_encode($row_geometry));
    $this->assertSame('32px', $row_geometry['columnGap'], 'Row computes the skin\'s gutter as its column-gap (the variant\'s gap declaration). Read: ' . json_encode($row_geometry));
    $this->assertEqualsWithDelta(32.0, (float) $row_geometry['between'], 1.0, 'The two blocks sit one gutter apart on the line. Read: ' . json_encode($row_geometry));
    $this->assertLessThan(
      (float) $row_geometry['content'] / 2,
      (float) $row_geometry['widths'][0] + (float) $row_geometry['widths'][1] + 32.0,
      'Under Row the blocks keep their natural width: the pair with its gutter fills less than half the content box, nothing like Columns\' equal fill (Row ships no children rule). Read: ' . json_encode($row_geometry),
    );

    // The alignment rows are mode-scoped. Under Row the Horizontal row is
    // justify-content with FOUR stops, Spread (space-between) the fourth:
    // natural-width children leave the main axis room to distribute.
    // Nothing delivers justify-content before the pick, so every chip
    // reads unlit and no custom pill shows. The row's face is read as one
    // string (label:pressed per chip) so a failure names what was lit;
    // step (g) reads the same rows on the tall band.
    $horizontal_row = "//div[@id='daedalus_stylish_inspector']"
      . "//section[contains(concat(' ', normalize-space(@class), ' '), ' daedalus-stylish-inspector__layout ')]"
      . "//div[contains(@class,'daedalus-stylish-inspector__widget-row')]"
      . "[.//span[contains(@class,'daedalus-stylish-inspector__widget-axis')][normalize-space(.)='Horizontal']]";
    $vertical_row = "//div[@id='daedalus_stylish_inspector']"
      . "//section[contains(concat(' ', normalize-space(@class), ' '), ' daedalus-stylish-inspector__layout ')]"
      . "//div[contains(@class,'daedalus-stylish-inspector__widget-row')]"
      . "[.//span[contains(@class,'daedalus-stylish-inspector__widget-axis')][normalize-space(.)='Vertical']]";
    $axis_state = static fn (string $row): string => "(() => { const row = document.evaluate(" . json_encode($row) . ", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;"
      . " if (!row) { return null; } const chips = [...row.querySelectorAll('button.daedalus-stylish-inspector__segment')];"
      . " return { chips: chips.map((chip) => chip.getAttribute('aria-label') + ':' + chip.getAttribute('aria-pressed')).join(','),"
      . " custom: !!row.querySelector('.daedalus-stylish-inspector__segment--custom') }; })()";
    $horizontal_state = $axis_state($horizontal_row);
    $vertical_state = $axis_state($vertical_row);
    $horizontal_reads = static fn (string $chips): string => "(() => { const state = $horizontal_state;"
      . ' return !!state && state.chips === ' . json_encode($chips) . ' && !state.custom; })()';
    $vertical_reads = static fn (string $chips): string => "(() => { const state = $vertical_state;"
      . ' return !!state && state.chips === ' . json_encode($chips) . ' && !state.custom; })()';
    $session->wait(20000, $horizontal_reads('Left:false,Center:false,Right:false,Spread:false'));
    $this->assertTrue(
      (bool) $session->evaluateScript($horizontal_reads('Left:false,Center:false,Right:false,Spread:false')),
      'Under Row the Layout section offers the Horizontal row with four stops, Spread the fourth, all unlit and no custom pill: nothing delivers justify-content on the band. Read: '
      . $session->evaluateScript("JSON.stringify($horizontal_state)"),
    );
    $this->assertTrue(
      (bool) $session->evaluateScript($vertical_reads('Top:false,Middle:false,Bottom:false')),
      'Under Row the Vertical row (align-items, the cross axis) stands beside Horizontal with its three stops, unlit. Read: '
      . $session->evaluateScript("JSON.stringify($vertical_state)"),
    );

    // A real click on Spread: one update_style on the band's own default
    // block (one POST), `justify-content: space-between` alone, computed
    // on the root and laid out: the first block hugs the content box's
    // left edge and the second its right edge.
    $band_entry = $this->styleMapJs() . "['container:$section_uuid']";
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickByXpath("{$horizontal_row}//button[contains(@class,'daedalus-stylish-inspector__segment')][@aria-label='Spread']");
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$root\")).justifyContent === 'space-between'",
      20000,
      'Spread landed as computed justify-content space-between on the band.',
    );
    $session->wait(20000, 'window.__blueprintPosts >= ' . ($posts + 1));
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The Spread click was exactly one blueprint POST. Network log: '
      . $session->evaluateScript('JSON.stringify(window.__fetchLog)'),
    );
    $this->assertJsCondition(
      "(() => { const entry = $band_entry?.default ?? {};"
      . " return entry['justify-content'] === 'space-between' && entry['flex-direction'] === undefined && entry.display === undefined; })()",
      20000,
      'The Spread pick wrote justify-content: space-between alone on the band\'s own default block: the arrangement stays the worn variant\'s, no custom claim.',
    );
    $session->wait(20000, $horizontal_reads('Left:false,Center:false,Right:false,Spread:true'));
    $this->assertTrue(
      (bool) $session->evaluateScript($horizontal_reads('Left:false,Center:false,Right:false,Spread:true')),
      'The Horizontal row lights Spread from the band\'s own block. Read: '
      . $session->evaluateScript("JSON.stringify($horizontal_state)"),
    );
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const root = document.querySelector("$root");
          const style = getComputedStyle(root);
          const box = root.getBoundingClientRect();
          const first = document.querySelector("[data-selectable='block:$first_uuid']").getBoundingClientRect();
          const second = document.querySelector("[data-selectable='block:$second_uuid']").getBoundingClientRect();
          return Math.abs(first.left - (box.left + parseFloat(style.paddingLeft))) < 2
            && Math.abs(second.right - (box.right - parseFloat(style.paddingRight))) < 2
            && second.left - first.right > 32;
        })()
        JS),
      'Spread laid out: the first block hugs the content box\'s left edge, the second its right edge, the space between them wider than the gutter (space-between resolved in layout, not just computed).',
    );

    // Back to Columns: a real click replaces Row by Columns on the axis
    // in one POST; the wrap goes with the Row variant, the children rule
    // returns (equal fill again, the band's own inert justify-content
    // notwithstanding), and the section re-scopes its rows: Horizontal is
    // gone (columns fill the main axis) and Vertical remains.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickByXpath("{$layout_mode}//button[normalize-space(.)='Columns']");
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(\"$root\"));"
      . " return style.display === 'flex' && style.flexDirection === 'row' && style.flexWrap === 'nowrap'; })()",
      20000,
      'Columns replaced Row: computed flex-direction row with the wrap gone (Columns ships no flex-wrap; the Row declaration left with its variant).',
    );
    $session->wait(20000, 'window.__blueprintPosts >= ' . ($posts + 1));
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The Columns click over Row was exactly one blueprint POST. Network log: '
      . $session->evaluateScript('JSON.stringify(window.__fetchLog)'),
    );
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', "{$layout_mode}//button[normalize-space(.)='Columns'][@aria-pressed='true']"),
      'The Layout widget reads the band as Columns again.',
    );
    $this->assertJsCondition(
      "(() => { const horizontal = $horizontal_state; const vertical = $vertical_state;"
      . " return horizontal === null && !!vertical && vertical.chips === 'Top:false,Middle:false,Bottom:false' && !vertical.custom; })()",
      20000,
      'Under Columns the Layout section drops the Horizontal row (the columns fill the main axis; justify-content has nothing to distribute) and keeps Vertical with its three stops. Read: '
      . $session->evaluateScript("JSON.stringify({ horizontal: $horizontal_state, vertical: $vertical_state })"),
    );
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const first = document.querySelector("[data-selectable='block:$first_uuid']").getBoundingClientRect();
          const second = document.querySelector("[data-selectable='block:$second_uuid']").getBoundingClientRect();
          return Math.abs(first.top - second.top) < 2
            && second.left > first.right - 2
            && Math.abs(first.width - second.width) < 2;
        })()
        JS),
      'Back under Columns the two blocks are equal columns again: the children rule returned with the variant, and the band\'s own justify-content is inert against the equal fill.',
    );

    // ——— (c) Width Small through the wear bar. ———
    //
    // The band was born wearing the width default (container_site): its
    // padding-inline must have RESOLVED through the skin's minted tokens —
    // max(32px, (100% - 1024px) / 2) against the band's own containing
    // width. Percentage padding resolves against the containing block, and
    // an auto-width block's border-box equals it, so offsetWidth is the
    // formula's own input.
    $width_row_xpath = "//div[@id='daedalus_stylish_inspector']"
      . "//div[contains(@class,'daedalus-stylish-wear-bar__row')]"
      . "[.//span[contains(@class,'daedalus-stylish-wear-bar__label')][normalize-space(.)='Width']]";
    $width_label = $assert_session->waitForElement('xpath', "{$width_row_xpath}//span[contains(@class,'daedalus-stylish-wear-bar__variant-label')]");
    $this->assertNotNull($width_label, 'The wear bar renders a worn Width row for the root-depth band.');
    $this->assertSame(
      'Site width',
      $width_label->getText(),
      'The band wears the birth default width variant (Site width, container_site).',
    );
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const root = document.querySelector("$root");
          const expected = Math.max(32, (root.offsetWidth - 1024) / 2);
          window.__padBefore = parseFloat(getComputedStyle(root).paddingLeft);
          return Math.abs(window.__padBefore - expected) < 2;
        })()
        JS),
      'The birth default width resolved through the minted tokens: computed padding-inline equals max(gutter, (100% - 1024px) / 2).',
    );

    // ——— (c') The band's own padding masks the worn inset, legibly. ———
    //
    // The root grant is the whole spacing group, so the padding row writes
    // the `padding` shorthand on the band; the element's own layer beats
    // the worn variant, so the shorthand hides Site width's
    // padding-inline. The wear row must SAY so (the overridden state: the
    // modifier class, the lost declaration named in the sub-line) and its
    // Reset overrides action must drop the own padding so the inset
    // delivers again. The write speaks the skin: padding is bound to the
    // space scale site-wide by the suite, so under the scenario's open
    // law the row is the detented field whose stop picker (the chevron's
    // shared menu) lists the skin's ladder as `name · value`, and a real
    // pick of the lg step (48px) lands the token's reference through
    // magnetic capture. 48px, so the masked inset cannot coincide with
    // the gutter floor (32px) the formula may already sit at.
    $this->assertJsCondition(
      "!document.evaluate(" . json_encode($width_row_xpath) . ", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue"
      . ".classList.contains('daedalus-stylish-wear-bar__row--overridden')",
      20000,
      'Before any own padding the Width row is plainly worn (no overridden state).',
    );
    $padding_input = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--spacing input[aria-label='Padding']";
    $padding_chevron = "$padding_input + .daedalus-stylish-stop-menu__chevron";
    $this->assertJsCondition(
      '!!document.querySelector(' . json_encode($padding_input) . ')'
      . ' && !!document.querySelector(' . json_encode($padding_chevron) . ')',
      20000,
      'The band\'s Spacing control renders its Padding row as the detented field with the stop picker\'s chevron (the whole spacing group is granted at root; the suite\'s space binding is an offer under open law).',
    );
    $this->clickBySelector($padding_chevron);
    $this->assertJsCondition(
      "(() => { const menu = document.querySelector('.daedalus-ui-menu'); if (!menu) { return false; }"
      . " const labels = Array.from(menu.querySelectorAll('.daedalus-ui-menu__label')).map((label) => label.textContent.trim());"
      . " return ['8px', '16px', '48px', '32px'].every((step) => labels.some((label) => label.includes(step)))"
      . " && labels.every((label) => !label.includes('var('))"
      . " && !menu.querySelector('[aria-checked=\"true\"]'); })()",
      20000,
      'The chevron opens the shared menu listing the scenario skin\'s space ladder (the suite binds padding to the space scale; the offer is the skin\'s minted steps, the built-in table displaced), var() on no label, nothing checked on the empty row.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.includes('48px')).click();"
    );
    $this->assertJsCondition(
      "(() => { const entry = $band_entry?.default ?? {};"
      . " return entry.padding === 'var(--space-lg)' && entry['padding-inline'] === undefined; })()",
      20000,
      'The padding row wrote the uniform `padding` shorthand on the band as the lg step\'s reference, var(--space-lg) (pick, magnetic capture, the op path), beside its worn width reference.',
    );
    $this->assertJsCondition(<<<JS
      (() => {
        const style = getComputedStyle(document.querySelector("$root"));
        return style.paddingTop === '48px' && style.paddingLeft === style.paddingTop && style.paddingRight === style.paddingTop;
      })()
      JS,
      20000,
      'The own padding beats the worn inset on every side: the band computes the lg token\'s 48px all round (the reference resolved through the compiled skin file; the layer rule, own over worn).',
    );
    $this->assertJsCondition(
      "(() => { const row = document.evaluate(" . json_encode($width_row_xpath) . ", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;"
      . " const text = row?.querySelector('.daedalus-stylish-wear-bar__overridden-text')?.textContent.trim() ?? '';"
      . " return !!row && row.classList.contains('daedalus-stylish-wear-bar__row--overridden')"
      . " && text === 'padding-inline overridden by your padding'"
      . " && row.querySelector('.daedalus-stylish-wear-bar__variant-label')?.textContent.trim() === 'Site width'; })()",
      20000,
      'The Width row reads overridden: still worn as Site width, the sub-line names the lost padding-inline under the own padding (the grain-aware shadow read).',
    );

    // The row action: a real click on Reset overrides commits the
    // update_style unset of the own padding, so the row is plainly worn
    // again and the inset formula delivers on the canvas as before.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickByXpath("{$width_row_xpath}//button[contains(@class,'daedalus-stylish-wear-bar__reset')][normalize-space(.)='Reset overrides']");
    $this->assertJsCondition(
      "(() => { const entry = $band_entry?.default ?? {}; return entry.padding === undefined; })()",
      20000,
      'Reset overrides dropped the band\'s own padding from the map.',
    );
    $session->wait(20000, 'window.__blueprintPosts >= ' . ($posts + 1));
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The Reset overrides click was exactly one blueprint POST. Network log: '
      . $session->evaluateScript('JSON.stringify(window.__fetchLog)'),
    );
    $this->assertJsCondition(
      "(() => { const row = document.evaluate(" . json_encode($width_row_xpath) . ", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;"
      . " return !!row && !row.classList.contains('daedalus-stylish-wear-bar__row--overridden')"
      . " && !row.querySelector('.daedalus-stylish-wear-bar__overridden'); })()",
      20000,
      'After the reset the Width row is plainly worn again: no overridden state, no sub-line.',
    );
    $this->assertJsCondition(<<<JS
      (() => {
        const style = getComputedStyle(document.querySelector("$root"));
        return style.paddingTop === '0px' && Math.abs(parseFloat(style.paddingLeft) - window.__padBefore) < 2;
      })()
      JS,
      20000,
      'The reset restored the worn inset: computed padding-inline is the Site width formula again and the top padding is gone.',
    );

    // Pick Small (640px) through the row's swap trigger: the worn row is
    // one button that opens the variant picker preset to Width (flat, no
    // group headers), a real click on the option commits apply_variant on
    // the same root key, and the response's variant CSS must move the
    // computed inset to the 640px stop's formula in this same document.
    $this->clickByXpath("{$width_row_xpath}//button[contains(@class,'daedalus-stylish-wear-bar__trigger')]");
    $this->clickByXpath(
      "//div[contains(@class,'daedalus-ui-popover')]//div[contains(@class,'daedalus-stylish-variant-picker')]//div[@role='option']"
      . "[.//span[contains(@class,'daedalus-stylish-variant-picker__label')][normalize-space(.)='Small (640px)']]"
    );
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker')",
      20000,
      'The pick of Small (640px) closed the variant picker.',
    );
    $this->assertJsCondition(<<<JS
      (() => {
        const root = document.querySelector("$root");
        const padding = parseFloat(getComputedStyle(root).paddingLeft);
        const expected = Math.max(32, (root.offsetWidth - 640) / 2);
        return padding > window.__padBefore && Math.abs(padding - expected) < 2;
      })()
      JS,
      20000,
      'Width Small changed the computed padding-inline on the same root to the 640px stop (variant delivery in edit mode).',
    );

    // ——— (d) A nested container is a plain <div> with no Width row. ———
    $nested_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_section',
              uuid: '$nested_uuid',
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__nestError = error.message;
        });
      })();
      JS);
    $nested = $assert_session->waitForElement('css', "[data-selectable='container:$nested_uuid']", 20000);
    $this->assertNotNull($nested, 'The nested container rendered: ' . ($session->evaluateScript('window.__nestError ?? null') ?? ''));
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const nested = document.querySelector("[data-selectable='container:$nested_uuid']");
          return nested.tagName === 'DIV'
            && nested.parentElement === document.querySelector("$root");
        })()
        JS),
      'Nested, the same declaration resolves to a plain <div> — the band\'s direct child, one element at every depth.',
    );

    // Selecting the nested container re-renders the wear bar without the
    // root-only width axis: the client's nested-depth read filters the
    // vocabulary. Rows are worn references only, and the nested container
    // wears nothing, so the bar is the add row alone; the OFFER lives in
    // the variant picker, whose groups are the depth-blind axes
    // (Animation and Hover among them) and never Width.
    // The wait flips only when the re-render lands — the band's own bar
    // (with its worn Width row) is on screen when the select commits.
    $session->executeScript("Drupal.Daedalus.Selection.select('container:$nested_uuid');");
    $this->assertJsCondition(
      "!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-wear-bar__row')"
      . " && !!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-wear-bar__add:not(:disabled)')",
      20000,
      'The nested container wears nothing: no worn row, the add row offers.',
    );
    $this->clickByXpath("//div[@id='daedalus_stylish_inspector']//button[contains(@class,'daedalus-stylish-wear-bar__add')]");
    $this->assertJsCondition(<<<JS
      (() => {
        const groups = [...document.querySelectorAll('.daedalus-ui-popover .daedalus-stylish-variant-picker__group[role="group"]')]
          .map((group) => group.getAttribute('aria-label'));
        return groups.length > 0 && groups.includes('Animation') && groups.includes('Hover') && !groups.includes('Width');
      })()
      JS,
      20000,
      'The nested container offers no Width: the root-only axis is out of its picker vocabulary, the depth-blind axes remain.',
    );
    $this->pressKeyOnFocused('Escape');
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker')",
      20000,
      'Escape closed the nested container\'s variant picker.',
    );

    // ——— (e) The Layout section renders posed and writes at the pose. ———
    //
    // Back on the band (worn Columns): the Width chip poses the canvas at
    // Narrow (the band's midpoint, the same px drive-there uses). The
    // segment is a WEAR surface and keeps reading Columns; the worn
    // variant's own narrow block stacks the children, and the section
    // says so in a sub-line instead of retargeting the wear. The
    // proportion strip is a property surface for a row and leaves.
    $session->executeScript("Drupal.Daedalus.Selection.select('container:$section_uuid');");
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', "{$layout_mode}//button[normalize-space(.)='Columns'][@aria-pressed='true']"),
      'Re-selected, the band\'s Layout widget reads Columns.',
    );
    $this->assertJsCondition(
      "!!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__layout-strip')"
      . " && !document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__layout-pose')",
      20000,
      'Unposed, the Columns wear renders its proportion strip and no sub-line.',
    );
    $this->clickByXpath("//div[@id='daedalus_stylish_inspector']//div[contains(@class, 'daedalus-stylish-chip-row')]/button[contains(@class, 'daedalus-stylish-chip--width')]");
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'The Width chip opened its menu on the shared primitive.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Narrow').click();"
    );
    $this->assertJsCondition(
      "document.documentElement.hasAttribute('data-canvas-width')"
      . " && parseInt(document.documentElement.getAttribute('data-canvas-width'), 10) < 768",
      20000,
      'The Width chip posed the canvas vector inside the narrow band (the document stamp).',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$root\")).flexDirection === 'column'",
      20000,
      'At Narrow the worn Columns variant\'s own narrow block stacks the band (flex-direction column on the canvas).',
    );
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', "{$layout_mode}//button[normalize-space(.)='Columns'][@aria-pressed='true']"),
      'Posed Narrow, the segment still reads Columns: the wear is unconditioned and the segment reflects it.',
    );
    $this->assertJsCondition(
      "(() => { const pose = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__layout-pose');"
      . " return !!pose && pose.textContent.trim() === 'Columns stacks at Narrow'"
      . " && !document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__layout-strip'); })()",
      20000,
      'The sub-line reads "Columns stacks at Narrow" and the proportion strip is gone (the effective arrangement at the pose is a stack).',
    );

    // The gap is a property surface: its value is what the root's layer
    // stack delivers at the pose (the worn row variant's
    // var(--space-gutter) default reaching narrow, shown through the
    // scenario skin's 32px and lit in the variant's hue: the field shows
    // the winner, never a ghost, and the mark's provenance names the
    // delivering layer and condition since Base is not the pose), and a
    // write lands under `narrow` ALONE (no companion: the worn row
    // variant's narrow block is literally the stack, a flex column over
    // the declared display, so the widget has no display to write and
    // the narrow block carries only the gap), so the canvas gap changes
    // at narrow only.
    $gap_input = "#daedalus_stylish_inspector .daedalus-stylish-inspector__layout input[aria-label='Gap']";
    $gap_row_reads = static fn (string $title): string => "(() => { const input = document.querySelector(" . json_encode($gap_input) . ');'
      . " if (!input) { return false; } const row = input.closest('.daedalus-stylish-dimension');"
      . " return input.value === '32' && row.querySelector('.daedalus-stylish-dimension__unit-label').textContent === 'px'"
      . " && !row.querySelector('.daedalus-stylish-dimension__unit-well')"
      . " && row.classList.contains('daedalus-stylish-dimension--blessed')"
      . " && row.querySelector('.daedalus-stylish-inspector__mark')?.title === " . json_encode($title) . '; })()';
    $gap_row_state = "(() => { const input = document.querySelector(" . json_encode($gap_input) . ');'
      . " if (!input) { return null; } const row = input.closest('.daedalus-stylish-dimension');"
      . " return { value: input.value, unit: row.querySelector('.daedalus-stylish-dimension__unit-label')?.textContent ?? null, classes: row.className, title: row.querySelector('.daedalus-stylish-inspector__mark')?.title ?? null }; })()";
    $session->wait(20000, $gap_row_reads('32px from Columns at Base'));
    $this->assertTrue(
      $session->evaluateScript($gap_row_reads('32px from Columns at Base')),
      'Posed Narrow, the Gap row reads the worn variant\'s gutter default resolved through the skin to 32 px (the number on the input, the unit on the static label: the value\'s own px, which is also the scenario skin\'s space unit, so no select), lit as a token reference (blessed) with the mark naming its delivering layer and condition ("32px from Columns at Base": the layer stack read at the pose, the delivering condition not the pose). Read: ' . json_encode($session->evaluateScript($gap_row_state)),
    );
    $map_entry = $this->styleMapJs() . "['container:$section_uuid']";
    $session->executeScript(
      '(() => {'
      . ' const input = document.querySelector(' . json_encode($gap_input) . ');'
      . " const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;"
      . " setter.call(input, '24');"
      . " input.dispatchEvent(new Event('input', { bubbles: true }));"
      . ' })();'
    );
    $session->executeScript(
      'document.querySelector(' . json_encode($gap_input) . ").dispatchEvent(new FocusEvent('blur'));"
    );
    $this->assertJsCondition(
      "(() => { const narrow = $map_entry?.narrow ?? {};"
      . " return narrow.gap === '24px' && narrow.display === undefined && Object.keys(narrow).length === 1"
      . " && ($map_entry?.default?.gap ?? null) === null; })()",
      20000,
      'The gap settle landed under narrow ALONE (the gap, no display companion: the worn row variant\'s narrow block is the stack), and default carries no gap.',
    );
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(\"$root\"));"
      . " return style.display === 'flex' && style.flexDirection === 'column' && style.rowGap === '24px'; })()",
      20000,
      'At Narrow the canvas band is a flex column (the variant\'s narrow block over the declared display) with the 24px gap laid out as its row-gap.',
    );

    // Un-posing (the chip's Base entry) returns the canvas to its natural
    // width: the row variant's flex row and gutter gap are back on the
    // canvas, the strip returns, and the Gap row reads the variant's
    // default again (the own narrow entry does not deliver at Base, so
    // the winner is the worn variant's at the pose itself, no condition
    // tail on the mark's sentence, the narrow entry named as set
    // elsewhere) with nothing of its own at default.
    $this->clickByXpath("//div[@id='daedalus_stylish_inspector']//div[contains(@class, 'daedalus-stylish-chip-row')]/button[contains(@class, 'daedalus-stylish-chip--width')]");
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'The Width chip re-opened its menu.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Base').click();"
    );
    $this->assertJsCondition(
      "!document.documentElement.hasAttribute('data-canvas-width')",
      20000,
      'The Base entry un-posed the canvas width.',
    );
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(\"$root\"));"
      . " return style.display === 'flex' && style.flexDirection === 'row' && style.columnGap === '32px'; })()",
      20000,
      'Un-posed, the band is the variant\'s flex row with its gutter gap (the skin\'s 32px): the narrow write changed the canvas at narrow only.',
    );
    $unposed_gap_row = '(() => { return ' . $gap_row_reads('32px from Columns. Also set at Narrow')
      . " && !!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__layout-strip')"
      . " && !document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__layout-pose'); })()";
    $session->wait(20000, $unposed_gap_row);
    $this->assertTrue(
      $session->evaluateScript($unposed_gap_row),
      'Un-posed, the Gap row reads the worn variant\'s 32 px again, lit, the mark naming the layer alone and the own narrow entry beside it ("32px from Columns. Also set at Narrow": the delivering condition is the pose), the strip is back and the sub-line is gone. Read: ' . json_encode($session->evaluateScript($gap_row_state)),
    );

    // ——— (f) A stacked child's Bottom is a browser-resolved auto margin. ———
    //
    // The reason the stack is a flex column: per-child vertical placement
    // has a working spelling. A second band (the first one's last child
    // is the nested container, and an auto margin pins only the LAST
    // child to the edge) holding two text blocks, born stacked like the
    // palette band, given `min-height: 60vh` through the op door (the
    // same update_style the advanced Sizing row sends, so the band is
    // taller than its content and there is free space for the margin to
    // take). Both placements ride one mutation batch; the min-height its
    // own, once the band renders.
    $tall_uuid = $uuid_service->generate();
    $top_uuid = $uuid_service->generate();
    $pinned_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_section',
              uuid: '$tall_uuid',
              location: { anchor: '$section_uuid', placement: 'after' },
            },
            {
              op: 'add_element',
              uuid: '$top_uuid',
              block_type: 'formatted_text',
              properties: {
                field_formatted_text_text: { value: '<p>Stays at the top</p>', format: 'daedalus_edit_formatted_text' },
              },
              location: { anchor: '$tall_uuid', placement: 'inside' },
            },
            {
              op: 'add_element',
              uuid: '$pinned_uuid',
              block_type: 'formatted_text',
              properties: {
                field_formatted_text_text: { value: '<p>Pinned to the bottom</p>', format: 'daedalus_edit_formatted_text' },
              },
              location: { anchor: '$tall_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__tallError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__tallError !== undefined"
      . " || (!!document.querySelector(\"[data-selectable='block:$top_uuid']\")"
      . " && !!document.querySelector(\"[data-selectable='block:$pinned_uuid']\"))",
      20000,
      'The tall band and its two block placements resolved.',
    );
    $tall_error = $session->evaluateScript('window.__tallError ?? null');
    $this->assertNull($tall_error, 'The tall band placement failed: ' . ($tall_error ?? ''));
    $tall_root = "[data-main-entity] [data-selectable='container:$tall_uuid']";
    $pinned = "[data-selectable='block:$pinned_uuid']";
    $top = "[data-selectable='block:$top_uuid']";
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const root = document.querySelector("$tall_root");
          const style = getComputedStyle(root);
          return root.tagName === 'SECTION'
            && document.querySelector("$top").parentElement === root
            && document.querySelector("$pinned").parentElement === root
            && style.display === 'flex' && style.flexDirection === 'column';
        })()
        JS),
      'The second band is born a stacked section too (the declared flex column) with both blocks as its direct children, so the pinned block\'s parent box is a flex column.',
    );
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'update_style', key: 'container:$tall_uuid', properties: { 'min-height': '60vh' } },
          ],
        })).catch((error) => {
          window.__tallError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__tallError !== undefined"
      . " || (() => { const root = document.querySelector(\"$tall_root\");"
      . " return Math.abs(parseFloat(getComputedStyle(root).minHeight) - window.innerHeight * 0.6) <= 1"
      . " && root.getBoundingClientRect().height >= window.innerHeight * 0.6 - 1; })()",
      20000,
      'The typed min-height landed on the band through the op door and the band grew to 60vh (the free space the auto margin below will take).',
    );
    $tall_error = $session->evaluateScript('window.__tallError ?? null');
    $this->assertNull($tall_error, 'The min-height update_style failed: ' . ($tall_error ?? ''));

    // The geometry the pick must change, read BEFORE it: the pinned
    // block's margin box (its rect plus its own margin-bottom, the
    // theme's paragraph rhythm: what the auto margin controls is the
    // margin box, so that is what is measured against the band's
    // content-box bottom, the padding and border taken off the rect)
    // sits well above the band's content edge, and its margin-top is
    // zero. Without this read the 1px assertion after the pick could
    // pass on a band with no free space at all.
    $geometry = <<<JS
      (() => {
        const root = document.querySelector("$tall_root");
        const rootStyle = getComputedStyle(root);
        const rootRect = root.getBoundingClientRect();
        const contentTop = rootRect.top + parseFloat(rootStyle.borderTopWidth) + parseFloat(rootStyle.paddingTop);
        const contentBottom = rootRect.bottom - parseFloat(rootStyle.borderBottomWidth) - parseFloat(rootStyle.paddingBottom);
        const box = (selector) => {
          const element = document.querySelector(selector);
          const style = getComputedStyle(element);
          const rect = element.getBoundingClientRect();
          return {
            marginTop: parseFloat(style.marginTop),
            marginBoxTop: rect.top - parseFloat(style.marginTop),
            marginBoxBottom: rect.bottom + parseFloat(style.marginBottom),
          };
        };
        return { contentTop, contentBottom, top: box("$top"), pinned: box("$pinned") };
      })()
      JS;
    $before = json_decode((string) $session->evaluateScript("JSON.stringify($geometry)"), TRUE);
    $this->assertIsArray($before, 'The tall band\'s geometry was read before the pick.');
    $margin_before = (float) $before['pinned']['marginTop'];
    $this->assertGreaterThan(
      100.0,
      (float) $before['contentBottom'] - (float) $before['pinned']['marginBoxBottom'],
      'Before the pick the pinned block\'s margin box sits well above the tall band\'s content-box bottom: the band has free space for an auto margin to take.',
    );

    // Select the last block and drill into its Position widget: under a
    // flex-column parent (the widget reads the parent box's computed
    // display and direction, layout-context.js parentFacts) the Vertical
    // row is the auto-margin pair, offered as the three vertical chips,
    // enabled, none lit: the pair reads no `auto` on either side.
    $session->executeScript("Drupal.Daedalus.Selection.select('block:$pinned_uuid');");
    $vertical_row = "//div[@id='daedalus_stylish_inspector']"
      . "//div[contains(@class,'daedalus-stylish-inspector__widget--position')]"
      . "//div[contains(@class,'daedalus-stylish-inspector__widget-row')]"
      . "[.//span[contains(@class,'daedalus-stylish-inspector__widget-axis')][normalize-space(.)='Vertical']]";
    $bottom_chip = "{$vertical_row}//button[contains(@class,'daedalus-stylish-inspector__segment')][@aria-label='Bottom']";
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', "{$bottom_chip}[not(@disabled)]", 20000),
      'The selected block\'s Position widget offers an enabled Bottom chip on its Vertical row: the parent\'s computed flex column read as the margin axis.',
    );
    $this->assertJsCondition(
      "(() => { const row = document.evaluate(" . json_encode($vertical_row) . ", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;"
      . " const chips = [...row.querySelectorAll('button.daedalus-stylish-inspector__segment')];"
      . " return chips.map((chip) => chip.getAttribute('aria-label')).join(',') === 'Top,Middle,Bottom'"
      . " && chips.every((chip) => chip.getAttribute('aria-pressed') === 'false' && !chip.disabled)"
      . " && !row.querySelector('.daedalus-stylish-inspector__segment--custom'); })()",
      20000,
      'The Vertical row is the margin axis\'s three chips (Top, Middle, Bottom), all enabled and none lit before the pick: neither margin side reads auto.',
    );

    // The op log: the leader source dispatches every widget commit
    // through Drupal.Daedalus.Blueprint.mutate, looked up at call time,
    // so a wrapper records each dispatch's operations without touching
    // the widget; installed once, cleared here so the pick is the only
    // dispatch it holds.
    $op_log = 'Drupal.Daedalus.Blueprint.__containerOpLog';
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        if (!Blueprint.__containerOpLog) {
          const mutate = Blueprint.mutate;
          Blueprint.__containerOpLog = [];
          Blueprint.mutate = (context) => {
            Blueprint.__containerOpLog.push((context.operations ?? []).map((operation) => JSON.parse(JSON.stringify(operation))));
            return mutate.call(Blueprint, context);
          };
        }
        Blueprint.__containerOpLog.length = 0;
      })();
      JS);

    // A real click on Bottom: the pair write is `margin-top: auto` alone
    // (margin-bottom carries nothing to clear, so the write trims it:
    // marginAxisWrite), one update_style on the block's key, one POST,
    // landing in the block's own default block of the map.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickByXpath($bottom_chip);
    // Waited for, then asserted with the log read AFTER the wait, so a
    // failure names what was actually dispatched.
    $op_condition = "(() => { const log = $op_log; if (log.length !== 1 || log[0].length !== 1) { return false; }"
      . " const [operation] = log[0];"
      . " return operation.op === 'update_style' && operation.key === 'block:$pinned_uuid'"
      . " && JSON.stringify(operation.properties) === JSON.stringify({ 'margin-top': 'auto' }); })()";
    $session->wait(20000, $op_condition);
    $this->assertTrue(
      (bool) $session->evaluateScript($op_condition),
      'The Bottom click dispatched exactly one update_style on the block\'s key carrying `margin-top: auto` and nothing else (margin-bottom absent: the pair write through the op door). Op log: '
      . $session->evaluateScript("JSON.stringify($op_log)"),
    );
    $pinned_entry = $this->styleMapJs() . "['block:$pinned_uuid']";
    $this->assertJsCondition(
      "(() => { const entry = $pinned_entry?.default ?? {};"
      . " return entry['margin-top'] === 'auto' && entry['margin-bottom'] === undefined; })()",
      20000,
      'The map carries the block\'s own `margin-top: auto` at default with no margin-bottom beside it (the pair write landed as one side).',
    );
    $session->wait(20000, 'window.__blueprintPosts >= ' . ($posts + 1));
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The Bottom click was exactly one blueprint POST. Network log: '
      . $session->evaluateScript('JSON.stringify(window.__fetchLog)'),
    );

    // The browser half no node test can see: the flex algorithm hands
    // the band's free space to the auto margin, so the computed
    // margin-top resolves to a used length in px (never the `auto`
    // keyword: parseFloat of `auto` is NaN and fails the comparison),
    // far above whatever the theme gave the tag before the pick; the
    // pinned block's margin box meets the band's content-box bottom
    // within 1px, and its sibling stays at the content-box top (the auto
    // margin moved one child, not the stack).
    $geometry_condition = "(() => { const g = $geometry;"
      . " return g.pinned.marginTop > $margin_before + 100"
      . " && Math.abs(g.pinned.marginBoxBottom - g.contentBottom) <= 1"
      . " && Math.abs(g.top.marginBoxTop - g.contentTop) <= 1; })()";
    $session->wait(20000, $geometry_condition);
    $this->assertTrue(
      (bool) $session->evaluateScript($geometry_condition),
      'The auto margin resolved in layout: the pinned block\'s computed margin-top is a used length taking the band\'s free space, its margin box bottom meets the band\'s content-box bottom within 1px, and its sibling stays at the top (the pair write through the op door to a browser-resolved auto margin). Geometry: '
      . $session->evaluateScript("JSON.stringify($geometry)"),
    );
    $this->assertJsCondition(
      "(() => { const chip = document.evaluate(" . json_encode($bottom_chip) . ", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;"
      . " return !!chip && chip.getAttribute('aria-pressed') === 'true' && chip.getAttribute('title') === 'Unset'; })()",
      20000,
      'The Vertical row reads the pair back: Bottom is lit from the block\'s own margin-top auto (the delivering side) and titled Unset.',
    );

    // ——— (g) The alignment rows light the stack's winner at the pose. ———
    //
    // Back on the tall band (born stacked, nothing worn, so the Layout
    // section offers both screen axes): the Horizontal row is align-items
    // on the flex column. Nothing delivers it before the pick (no own
    // block, no variant, and neither the scenario skin nor the container
    // declaration carries an alignment), so its three chips read unlit
    // and no custom pill shows. The row xpath and its face reader are
    // step (b')'s: the Layout section is one section whichever container
    // is selected.
    $session->executeScript("Drupal.Daedalus.Selection.select('container:$tall_uuid');");
    $center_chip = "{$horizontal_row}//button[contains(@class,'daedalus-stylish-inspector__segment')][@aria-label='Center']";
    $right_chip = "{$horizontal_row}//button[contains(@class,'daedalus-stylish-inspector__segment')][@aria-label='Right']";
    $tall_entry = $this->styleMapJs() . "['container:$tall_uuid']";
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', "{$layout_mode}//button[normalize-space(.)='Stack'][@aria-pressed='true']", 20000),
      'Re-selected, the tall band\'s Layout widget reads Stack (born stacked, nothing worn), so the section offers the Horizontal row.',
    );
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', "{$center_chip}[not(@disabled)]", 20000),
      'The Layout section\'s Horizontal row offers an enabled Center chip: align-items on the flex column.',
    );
    $session->wait(20000, $horizontal_reads('Left:false,Center:false,Right:false'));
    $this->assertTrue(
      (bool) $session->evaluateScript($horizontal_reads('Left:false,Center:false,Right:false')),
      'Before the pick the Horizontal row is unlit with no custom pill: no layer of the band\'s stack delivers align-items. Read: '
      . $session->evaluateScript("JSON.stringify($horizontal_state)"),
    );

    // A real click on Center, unposed: one update_style on the band's
    // key carrying `align-items: center` and no condition (the compound
    // is Base), one POST, landing in the band's default block; the flex
    // column centres its children and the row lights Center.
    $session->executeScript("$op_log.length = 0;");
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickByXpath($center_chip);
    $center_op = "(() => { const log = $op_log; if (log.length !== 1 || log[0].length !== 1) { return false; }"
      . " const [operation] = log[0];"
      . " return operation.op === 'update_style' && operation.key === 'container:$tall_uuid'"
      . " && operation.condition === undefined"
      . " && JSON.stringify(operation.properties) === JSON.stringify({ 'align-items': 'center' }); })()";
    $session->wait(20000, $center_op);
    $this->assertTrue(
      (bool) $session->evaluateScript($center_op),
      'The Center click dispatched exactly one update_style on the band\'s key carrying `align-items: center` with no condition (alignOps at Base). Op log: '
      . $session->evaluateScript("JSON.stringify($op_log)"),
    );
    $this->assertJsCondition(
      "(() => { const entry = $tall_entry ?? {};"
      . " return entry.default?.['align-items'] === 'center' && entry.narrow === undefined; })()",
      20000,
      'The map carries the band\'s own `align-items: center` at default and no narrow block.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$tall_root\")).alignItems === 'center'",
      20000,
      'The canvas centres the tall band\'s children (align-items on the declared flex column).',
    );
    $session->wait(20000, 'window.__blueprintPosts >= ' . ($posts + 1));
    $this->assertSame(
      $posts + 1,
      (int) $session->evaluateScript('window.__blueprintPosts'),
      'The Center click was exactly one blueprint POST. Network log: '
      . $session->evaluateScript('JSON.stringify(window.__fetchLog)'),
    );
    $session->wait(20000, $horizontal_reads('Left:false,Center:true,Right:false'));
    $this->assertTrue(
      (bool) $session->evaluateScript($horizontal_reads('Left:false,Center:true,Right:false')),
      'Unposed, the Horizontal row lights Center from the band\'s own default block. Read: '
      . $session->evaluateScript("JSON.stringify($horizontal_state)"),
    );

    // Posed Narrow (the Width chip, as in (e)): the band still computes
    // centred, because its default block reaches narrow through the
    // condition cascade, and the row's Center STAYS lit. This is the
    // seam: the row reads the stack's winner at the pose (alignmentState
    // over stackAt), not the narrow block's own value, which is empty;
    // a row reading the compound's own block alone would go dark here
    // while the canvas stayed centred.
    $this->clickByXpath("//div[@id='daedalus_stylish_inspector']//div[contains(@class, 'daedalus-stylish-chip-row')]/button[contains(@class, 'daedalus-stylish-chip--width')]");
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'The tall band\'s Width chip opened its menu.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Narrow').click();"
    );
    $this->assertJsCondition(
      "document.documentElement.hasAttribute('data-canvas-width')"
      . " && parseInt(document.documentElement.getAttribute('data-canvas-width'), 10) < 768",
      20000,
      'The Width chip posed the canvas vector inside the narrow band.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$tall_root\")).alignItems === 'center'",
      20000,
      'Posed Narrow, the band still computes centred: the default block\'s align-items reaches narrow through the cascade.',
    );
    $session->wait(20000, $horizontal_reads('Left:false,Center:true,Right:false'));
    $this->assertTrue(
      (bool) $session->evaluateScript($horizontal_reads('Left:false,Center:true,Right:false')),
      'Posed Narrow, the Horizontal row keeps Center lit: the row lights the stack\'s winner at the pose (the own default block delivering at narrow), not the narrow block\'s own value. Read: '
      . $session->evaluateScript("JSON.stringify($horizontal_state)"),
    );

    // A real click on Right at Narrow: the pick lands at the compound,
    // `align-items: flex-end` under `narrow` ALONE (default keeps its
    // centre), the canvas ends the children at narrow, and Right lights
    // as the narrow block's own winner over default's.
    $session->executeScript("$op_log.length = 0;");
    $this->clickByXpath($right_chip);
    $right_op = "(() => { const log = $op_log; if (log.length !== 1 || log[0].length !== 1) { return false; }"
      . " const [operation] = log[0];"
      . " return operation.op === 'update_style' && operation.key === 'container:$tall_uuid'"
      . " && operation.condition === 'narrow'"
      . " && JSON.stringify(operation.properties) === JSON.stringify({ 'align-items': 'flex-end' }); })()";
    $session->wait(20000, $right_op);
    $this->assertTrue(
      (bool) $session->evaluateScript($right_op),
      'The Right click at Narrow dispatched exactly one update_style on the band\'s key under `narrow` carrying `align-items: flex-end` (the pick lands at the compound). Op log: '
      . $session->evaluateScript("JSON.stringify($op_log)"),
    );
    $this->assertJsCondition(
      "(() => { const entry = $tall_entry ?? {}; const narrow = entry.narrow ?? {};"
      . " return narrow['align-items'] === 'flex-end' && Object.keys(narrow).length === 1"
      . " && entry.default?.['align-items'] === 'center'; })()",
      20000,
      'The map carries `align-items: flex-end` under narrow alone and default keeps its centre.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$tall_root\")).alignItems === 'flex-end'",
      20000,
      'At Narrow the canvas ends the tall band\'s children (the narrow block over default).',
    );
    $session->wait(20000, $horizontal_reads('Left:false,Center:false,Right:true'));
    $this->assertTrue(
      (bool) $session->evaluateScript($horizontal_reads('Left:false,Center:false,Right:true')),
      'Posed Narrow after the pick, the Horizontal row lights Right: the narrow block\'s own value is the winner at the pose. Read: '
      . $session->evaluateScript("JSON.stringify($horizontal_state)"),
    );

    // Un-posed (the chip's Base entry): the narrow block does not
    // deliver at Base, so the canvas is centred again and Center lights.
    $this->clickByXpath("//div[@id='daedalus_stylish_inspector']//div[contains(@class, 'daedalus-stylish-chip-row')]/button[contains(@class, 'daedalus-stylish-chip--width')]");
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'The tall band\'s Width chip re-opened its menu.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Base').click();"
    );
    $this->assertJsCondition(
      "!document.documentElement.hasAttribute('data-canvas-width')",
      20000,
      'The Base entry un-posed the canvas width again.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$tall_root\")).alignItems === 'center'",
      20000,
      'Un-posed, the band computes centred again: the narrow write changed the canvas at narrow only.',
    );
    $session->wait(20000, $horizontal_reads('Left:false,Center:true,Right:false'));
    $this->assertTrue(
      (bool) $session->evaluateScript($horizontal_reads('Left:false,Center:true,Right:false')),
      'Un-posed, the Horizontal row lights Center again from the default block. Read: '
      . $session->evaluateScript("JSON.stringify($horizontal_state)"),
    );

    // ——— (h) The empty hint outranks a page's own generated box. ———
    //
    // Seam: the specificity fight between the affordance stylesheet
    // (edit-affordances.css, a head stylesheet earlier in the document)
    // and the entity's inline map CSS (later in the document, which
    // wins every tie): a user's `&::before` on an empty band prints as
    // `:scope::before` inside the band's @scope (0-1-1), and the hint's
    // rule carries the edit stage's id (`#daedalus-ui-stage
    // [data-container-empty-hint]::before`, 1-1-1) and opens with
    // `all: initial`, so the hint's text, a transparent background and
    // the full width stand over the page's content, colour and width
    // for exactly as long as the band is empty. Only a real cascade
    // orders the two stylesheets; the kernel tier prints each alone.
    // The box is minted through the op spine (the advanced row's
    // update_style) and lands with the op spine's seed, `content: ""`,
    // at default.
    $empty_uuid = $uuid_service->generate();
    $empty_root = "[data-main-entity] [data-selectable='container:$empty_uuid']";
    $empty_box_key = "container:$empty_uuid::&::before";
    $hint_js = "(() => { const root = document.querySelector(\"$empty_root\"); if (!root) { return null; }"
      . " const before = getComputedStyle(root, '::before');"
      . " return { hint: root.getAttribute('data-container-empty-hint'), staged: !!root.closest('#daedalus-ui-stage'),"
      . " content: before.content.replace(/[\"']/g, ''), background: before.backgroundColor, width: before.width, outline: before.outlineStyle }; })()";
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_section',
              uuid: '$empty_uuid',
              location: { anchor: '$tall_uuid', placement: 'after' },
            },
          ],
        })).catch((error) => {
          window.__emptyBandError = error.message;
        });
      })();
      JS);
    $this->assertNotNull(
      $assert_session->waitForElement('css', $empty_root, 20000),
      'The empty band rendered after the tall band: ' . ($session->evaluateScript('window.__emptyBandError ?? null') ?? ''),
    );
    $this->assertJsCondition(
      "(() => { const facts = $hint_js; return !!facts && facts.staged && facts.hint === 'Empty container'"
      . " && facts.content === 'Empty container' && facts.outline === 'dashed'; })()",
      20000,
      'Before any box of its own the empty band paints the hint inside the edit stage: the attr()-fed ::before text and the dashed edge.',
    );
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'update_style', key: '$empty_box_key', properties: { 'background-color': '#ff0000', width: '10px' } },
          ],
        })).catch((error) => {
          window.__emptyBoxError = error.message;
        });
      })();
      JS);
    $empty_entry = $this->styleMapJs() . "[" . json_encode($empty_box_key) . ']';
    $this->assertJsCondition(
      "window.__emptyBoxError !== undefined || ($empty_entry)?.default?.['background-color'] === '#ff0000'",
      20000,
      'The box write at the empty band resolved.',
    );
    $this->assertNull(
      $session->evaluateScript('window.__emptyBoxError ?? null'),
      'Writing the empty band\'s `&::before` failed: ' . ($session->evaluateScript('window.__emptyBoxError ?? null') ?? ''),
    );
    $this->assertSame(
      ['default' => ['content' => '""', 'background-color' => '#ff0000', 'width' => '10px']],
      json_decode((string) $session->evaluateScript("JSON.stringify($empty_entry)"), TRUE),
      'The box was born with the op spine\'s seed, `content: ""` at default beneath the written lines: on its own the page\'s box would exist and paint red, 10px wide.',
    );
    $this->assertJsCondition(
      "(() => { const facts = $hint_js; return !!facts && facts.staged && facts.hint === 'Empty container'"
      . " && facts.content === 'Empty container' && facts.background === 'rgba(0, 0, 0, 0)'"
      . " && parseFloat(facts.width) > 100 && facts.outline === 'dashed'; })()",
      20000,
      'With the page\'s own `&::before` delivered (content, red, 10px wide at 0-1-1 inside the band\'s @scope, printed later in the document) the hint still paints: its text, a transparent background and the full width under `all: initial` at 1-1-1, the dashed edge: the chrome asserts its own box while the band is empty. Read: '
      . $session->evaluateScript("JSON.stringify($hint_js)"),
    );

    // The whole scenario ran in one document, staged in tempstore: the
    // saved node never gained a layout.
    $this->assertTrue(
      (bool) $session->evaluateScript('window.__sameDocument === true'),
      'Every round trip happened without a page reload.',
    );
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $node_storage->resetCache([$nid]);
    $this->assertTrue(
      $node_storage->load($nid)->get('layout_builder__layout')->isEmpty(),
      'The band, blocks, arrangement, and width are staged in tempstore, not committed to the node.',
    );
  }

}
