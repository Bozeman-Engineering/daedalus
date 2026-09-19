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
 *   composed kind's declared `display: grid` compiled into the defaults
 *   file, stamped on the section root, and actually laying out the
 *   canvas): the container kind declares its root display as token-free
 *   code (daedalus_stylish_layouts.page_builder.yml), the skin carries only the
 *   look (`gap: var(--space-gutter)` on kind:container, the shape every
 *   shipped skin uses), so a newborn band holding two blocks computes
 *   `display: grid` and a `row-gap` equal to the minted gutter, and the
 *   blocks sit that far apart. Before the declared tier the gap was dead
 *   on this very skin (a gap with no arranging display paints nothing):
 *   the Kernel tier pins the compiled block; only a browser proves it
 *   arranges.
 * - Columns lands as computed style: a page band is BORN stacked (no
 *   arrangement reference at the add_section door), so the palette band
 *   computes no flex before any gesture. A real click on the Layout
 *   widget's Columns segment commits apply_variant on the SELECTED root
 *   key in ONE blueprint POST and the computed display becomes flex;
 *   clicking Stack commits remove_variant the same way and the flex
 *   leaves — widget op → mutation response → inline variant CSS →
 *   computed style, four layers that only agree in a browser, exercised
 *   in both directions. The children pseudo-part's equal fill shows as
 *   two side-by-side equal columns.
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
 *   Narrow" and no proportion strip, the Gap row's ghost reads the layer
 *   stack at the pose, and a typed gap lands under `narrow` beside the
 *   widget's own `display: grid` stack upgrade (the worn row variant's
 *   flex column would otherwise beat the kind's declared grid at the
 *   pose), changing the canvas at narrow only; un-posed, the flex row,
 *   its gutter gap (the scenario skin's 32px), the strip and the ghost
 *   are back. The pose → vector → widget read/write seam only exists in
 *   a browser.
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
 * delivery, pose writes), not scale law, so the bindings shape the
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
   * The palette band, arrangement, width delivery, and the nested div.
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
    // root) and nothing of its mechanic: the arranging `display: grid` is
    // the composed kind's declared default in code, which is what the
    // stacked-band leg below proves lays the gap out. Created BEFORE the
    // page loads so the compiled skin and defaults files are linked into
    // the document under test.
    $skin = DaedalusStylishSkin::create([
      'label' => 'Scenario skin',
      'machine_name' => 'scenario_skin',
      'status' => TRUE,
    ]);
    $skin->setTokens([
      'space' => ['sm' => '8px', 'md' => '16px', 'lg' => '48px', 'gutter' => '32px'],
      'layout' => ['max-width' => '1024px'],
    ]);
    $skin->setDefaults([
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
    $this->enterEditModeViaCookies($node->toUrl(), 'selector');
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
    // The item is a drag source only once the add-panel behavior armed it
    // (makeDraggable assigns the handler property); waiting on that is the
    // deterministic "palette is live" signal.
    $this->assertJsCondition(
      "(() => { const item = document.getElementById('empty-section');"
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
        document.getElementById('empty-section').dispatchEvent(
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
        document.getElementById('empty-section').dispatchEvent(
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
    // the add_section door, so the palette band computes no flex before
    // any gesture, and the widget's Stack segment is the selected one.
    // Stacked is a GRID: the composed container kind declares its root
    // `display: grid` (daedalus_stylish_layouts.page_builder.yml, the declared
    // tier compiled into the defaults file and stamped on the section
    // root by ContainerDefaultsStamp), so the skin's gap (kind:container
    // `gap: var(--space-gutter)`, the look) lays the two blocks out 32px
    // apart. Read on the root and as the blocks' geometry: the Kernel
    // tier pins the compiled block, only the browser proves it arranges.
    $stacked = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const root = document.querySelector("$root");
        const style = getComputedStyle(root);
        const first = document.querySelector("[data-selectable='block:$first_uuid']").getBoundingClientRect();
        const second = document.querySelector("[data-selectable='block:$second_uuid']").getBoundingClientRect();
        return JSON.stringify({ display: style.display, rowGap: style.rowGap, between: second.top - first.bottom });
      })()
      JS), TRUE);
    $this->assertIsArray($stacked, 'The newborn band\'s computed arrangement was read.');
    $this->assertSame(
      'grid',
      $stacked['display'],
      'The palette band was born stacked as a grid: the container kind\'s declared display, no row variant and no flex before any gesture.',
    );
    $this->assertSame(
      '32px',
      $stacked['rowGap'],
      'The stacked band computes the skin\'s gutter as its row-gap: the declared grid gives the skin\'s gap a box to act on.',
    );
    $this->assertEqualsWithDelta(
      32.0,
      (float) $stacked['between'],
      1.0,
      'The two stacked blocks sit one gutter apart on the canvas (the row-gap laid out, not just computed).',
    );
    $layout_mode = "//div[@id='daedalus_stylish_inspector']//span[@aria-label='Layout mode']";
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', "{$layout_mode}//button[normalize-space(.)='Stack'][@aria-pressed='true']"),
      'The Layout widget reads the newborn band as Stack.',
    );

    // The widget round trip, direction one: clicking Columns commits
    // apply_variant on the SELECTED key (one POST), and the mutation
    // response's variant CSS lands as computed `display: flex` on the very
    // element the editor selected.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickByXpath("{$layout_mode}//button[normalize-space(.)='Columns']");
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$root\")).display === 'flex'",
      20000,
      'Columns landed as computed flex on the selected section root (op → response → variant CSS → computed style).',
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
    // same key (one POST) and the computed display leaves flex — the
    // un-wear delivered through the same four layers. Then back to
    // Columns so the equal-fill geometry below has a row to measure.
    $posts = (int) $session->evaluateScript('window.__blueprintPosts');
    $this->clickByXpath("{$layout_mode}//button[normalize-space(.)='Stack']");
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(\"$root\"));"
      . " return style.display === 'grid' && style.rowGap === '32px'; })()",
      20000,
      'Stack removed the row variant: the selected root is the declared grid stack again, its row-gap the skin\'s gutter.',
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
      "getComputedStyle(document.querySelector(\"$root\")).display === 'flex'",
      20000,
      'Columns re-applied for the geometry check.',
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
      'The two blocks sit side by side as equal columns (the ::> equal-fill children rule resolved in layout).',
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
    $band_entry = "(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles ?? {})['container:$section_uuid']";
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

    // Pick Small (640px) through the row's swap trigger: the variant label
    // opens the variant picker preset to Width (flat, no group headers),
    // a real click on the option commits apply_variant on the same root
    // key, and the response's variant CSS must move the computed inset to
    // the 640px stop's formula in this same document.
    $this->clickByXpath("{$width_row_xpath}//button[contains(@class,'daedalus-stylish-wear-bar__variant')]");
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

    // The gap is a property surface: its ghost reads the layer stack at
    // the pose (the variant's var(--space-gutter) default reaching narrow,
    // shown through the scenario skin's 32px), and a write lands under
    // `narrow` beside the widget's own stack upgrade (`display: grid` as
    // an own write: the worn row variant's flex column beats the kind's
    // declared grid at the pose, so the widget takes it over as a grid
    // stack), so the canvas gap changes at narrow only.
    $gap_input = "#daedalus_stylish_inspector .daedalus-stylish-inspector__layout input[aria-label='Gap']";
    $this->assertJsCondition(
      "(() => { const input = document.querySelector(" . json_encode($gap_input) . ');'
      . " return !!input && input.value === '' && input.placeholder === '32'"
      . " && input.closest('.daedalus-stylish-dimension').querySelector('.daedalus-stylish-dimension__unit').value === 'px'; })()",
      20000,
      'Posed Narrow, the Gap row is empty with the variant\'s gutter default as its ghost, resolved through the skin to 32 px (the number on the input, the unit on the select: the layer stack read at the pose).',
    );
    $map_entry = "(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles ?? {})['container:$section_uuid']";
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
      . " return narrow.gap === '24px' && narrow.display === 'grid' && ($map_entry?.default?.gap ?? null) === null; })()",
      20000,
      'The gap settle landed under narrow beside the stack upgrade (display: grid), and default carries no gap.',
    );
    $this->assertJsCondition(
      "(() => { const style = getComputedStyle(document.querySelector(\"$root\"));"
      . " return style.display === 'grid' && style.columnGap === '24px'; })()",
      20000,
      'At Narrow the canvas band is a grid stack with the 24px gap.',
    );

    // Un-posing (the chip's Base entry) returns the canvas to its natural
    // width: the row variant's flex row and gutter gap are back on the
    // canvas, the strip returns, and the Gap row's ghost reads the
    // variant's default again with nothing of its own at default.
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
    $this->assertJsCondition(
      "(() => { const input = document.querySelector(" . json_encode($gap_input) . ');'
      . " return !!input && input.value === '' && input.placeholder === '32'"
      . " && input.closest('.daedalus-stylish-dimension').querySelector('.daedalus-stylish-dimension__unit').value === 'px'"
      . " && !!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__layout-strip')"
      . " && !document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__layout-pose'); })()",
      20000,
      'Un-posed, the Gap row\'s ghost reads the variant\'s default again, the strip is back and the sub-line is gone.',
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
