<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives formatted-text styling (minted instance parts) on a real page.
 *
 * The capability under test is the formatted-text styling model that
 * landed with the instances cutover: minted text elements (`.s-*`
 * identity classes in the stored rich text) are content the BASIC panel
 * can select and style, and the element-scoped tag tier (`{key}::p`)
 * styles all of one element's paragraphs with a real cascade win over
 * the skin's base:p. The models are node/kernel-tested exhaustively
 * (controls.test.mjs sub-key cases, StyleMapValidatorTest tag cases,
 * compose.test.mjs instance-row cases); everything here is wiring that
 * only exists where the tree, the inspector, the op spine, and the
 * browser's CSS engine meet:
 * - Instance-row styling in basic (seam: tree-selection to controls to
 *   op to render, across three modules): a real click on a paragraph's
 *   instance row in the BASIC tree commits the part facet on the
 *   daedalus_ui selection spine, daedalus_stylish's basic panel retargets
 *   the drilled part (basicTarget) and renders the instance
 *   declaration's curated controls, a real widget click commits an
 *   update_style op through daedalus_blueprint, and the canvas restyles
 *   exactly that paragraph, not its sibling. Four layers in three
 *   modules agree only in a browser.
 * - The tag tier beats the skin's base:p (seam: cross-tier
 *   document-order cascade, a real-browser CSS fact): a `{key}::p`
 *   font-size write must beat the Olivero skin's base:p, a DIRECT
 *   zero-specificity :where(p) rule the compiled skin file lands on
 *   every paragraph, on the canvas, for every paragraph of that element
 *   and none of a neighbor's. The write speaks the skin's type scale
 *   (font-size is bound to it site-wide by the suite's own
 *   constraint_defaults, so the honest spelling on the tier is a token,
 *   under open law as under strict): the lead token, whose resolved
 *   size the probe proves differs from body's, so the win is
 *   observable. The kernel printer tests pin the emitted rule text;
 *   only a browser computes who wins.
 * - Canvas deep-select in basic (seam: canvas-tree selection plumbing
 *   with instance rows in basic): a modifier click on a canvas
 *   paragraph resolves the instance part through the registered part
 *   behavior, and the BASIC tree's row for that paragraph highlights
 *   while the panel breadcrumb targets it, so canvas and tree agree on
 *   a selection universe that only exists because instance rows render
 *   in basic.
 * - List-item row styling in basic (seam: the universal-minting model
 *   through the same four layers): every declared tag mints at any
 *   depth, so a list ITEM is a carrier with its own basic-tree row, the
 *   same curated instance controls bound to its own part key, and an op
 *   commit that restyles exactly that item on the canvas while its
 *   sibling item is untouched. The minting walk and `::li` validation
 *   are kernel-pinned; only a browser proves an item row drives the
 *   whole round trip.
 * - Nested-list row selection sync in basic (seam: flat ownership at
 *   depth in a real tree): a nested list's item renders a row in the
 *   basic tree, and a modifier click on it in the canvas highlights
 *   that row and retargets the panel, which only works because rows at
 *   any depth carry the owning MODEL key (nesting is display, ownership
 *   is flat — the compose.test.mjs invariant, exercised here through
 *   the live selection spine).
 * - Breadcrumb depth over the markup tier (seam: selection spine to the
 *   two-tier selection index to the indicator): the deep-selected nested
 *   item's breadcrumb trails part to part through its markup parents up
 *   to the owning block (… › Formatted text › Bulleted list › List item ›
 *   Bulleted list › List item). Ancestry is node-pinned over a fake
 *   index; only a browser sees the spine, the index built from the live
 *   annotation, and the indicator agree.
 * - Keyboard climb over the markup tier (seam: hotkey dispatcher to
 *   traversal over the index's markup tier to the spine to the
 *   inspector): ArrowLeft from the nested item commits its list as the
 *   part facet, so the inspector chip names the nested list's own
 *   identity and the breadcrumb shortens by one crumb.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site as
 * daedalus_editor) as the recipe ships it: the olivero design system
 * active and open (the recipe's finished look offers, never refuses),
 * its skin the base:p interposer the tag-tier step beats. Nothing here
 * needs law, so the scenario rides the open reference untouched; the
 * scenarios that prove closed spaces activate the strict reference
 * (guide_example) instead. One fixture, one scenario: the site install
 * dominates the run cost, so all behavior lives in the single test
 * method.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class FormattedTextStylingBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait;

  /**
   * Scrolls a panel element into view and really clicks it.
   *
   * Sidebars are scrollable rails, so a row or widget can sit below the
   * fold; centering it first keeps the click a REAL pointer click (the
   * wiring under test) instead of a synthetic dispatch.
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
   * The formatted-text styling scenario: rows, curated controls, tag tier.
   *
   * The scripted scenario, in order:
   * - Fixture: a page node, Edit Mode via the cookie path, one section
   *   with two formatted_text blocks placed through the op spine. Block
   *   A holds two paragraphs (the target and its sibling), block B one
   *   paragraph (the cross-element control). Staging mints the `.s-*`
   *   identities, read back from the canvas DOM.
   * - Entry: both sidebars open, the inspector confirmed in BASIC mode
   *   (the mode a fresh session starts in).
   * - Instance row styling: a real click on the target paragraph's
   *   chip-less instance row selects it (row active, breadcrumb targets
   *   the part), the curated text-alignment control renders from the
   *   instance declaration, and a real click on its Center segment
   *   commits `text-align: center` to the part key. The canvas centers
   *   exactly that paragraph; the sibling is untouched.
   * - Tag tier: the paragraphs' pre-write font-size is proven to BE the
   *   skin's base:p value (a probe span resolving var(--text-body) in
   *   place computes the same size) and the lead token to resolve to a
   *   different size, then an update_style op writes `{block A}::p`
   *   font-size var(--text-lead) and both of block A's paragraphs
   *   compute the lead size while block B's stays on the body value.
   * - Deep-select: a modifier click on the sibling paragraph in the
   *   canvas commits its part facet; the sibling's tree row highlights
   *   and the panel breadcrumb retargets, in basic mode.
   * - List rows: block C stages a bulleted list with a nested list;
   *   universal minting stamps the wrappers and every item. A real
   *   click on one item's row selects it and a Center pick commits
   *   text-align onto the ITEM's part key; the canvas centers exactly
   *   that item, not its sibling item.
   * - Nested sync: the nested list's item renders its own row in the
   *   basic tree; a modifier click on it in the canvas highlights that
   *   row and retargets the breadcrumb (flat ownership at depth, live).
   * - Breadcrumb depth: with the nested item selected, the selection
   *   indicator's breadcrumb ends "Formatted text › Bulleted list › List
   *   item › Bulleted list › List item" (markup parents, then the owner).
   * - Keyboard climb: ArrowLeft commits the nested list as the part
   *   facet; the inspector chip names its minted identity and the
   *   breadcrumb ends at "Bulleted list".
   */
  public function testFormattedTextStylingScenario(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // The scenario rides the recipe's open reference as shipped; assert
    // the posture so a tightened fixture cannot silently turn these
    // offer-shaped claims into law claims (those belong to the
    // guide_example scenarios).
    $this->assertSame(
      'open',
      \Drupal::service('daedalus_stylish.style_guide')->enforcement(),
      'The recipe\'s olivero design system ships open enforcement: the scenario runs on the open reference as shipped.',
    );
    // The tag-tier step's interposer: the recipe activates the Olivero
    // skin, whose base:p lands a direct font-size on every paragraph.
    $this->assertSame(
      'olivero',
      \Drupal::service('daedalus_stylish.skin_manager')->activeSkinName(),
      'The recipe activated the Olivero skin: its base:p font-size is the rule the tag tier must beat.',
    );

    // A real Layout Builder page: the recipe enables styling for the
    // page bundle, so its daedalus_stylish_styles field hosts the style map.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Formatted text styling page',
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

    // Place one section holding block A (two paragraphs: the styling
    // target and its isolation control) and block B (the cross-element
    // control for the tag tier), B anchored after A. Staging mints the
    // paragraph identities on the way in (the stage-time seam), so the
    // rendered canvas already carries the `.s-*` classes.
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
                  value: '<p>Alpha paragraph target</p><p>Beta paragraph sibling</p>',
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
                  value: '<p>Gamma paragraph control</p>',
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
    $canvas_a = "[data-selectable='$block_a_key']";
    $canvas_b = "[data-selectable='$block_b_key']";
    $this->assertJsCondition(
      "window.__placementError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$block_b_uuid')",
      20000,
      'The two-block placement resolved.',
    );
    $placement_error = $session->evaluateScript('window.__placementError ?? null');
    $this->assertNull($placement_error, 'Block placement failed: ' . ($placement_error ?? ''));
    $this->assertNotNull(
      $assert_session->waitForElement('css', $canvas_b),
      'Both formatted text blocks rendered on the canvas.',
    );

    // Read the minted identities back from the canvas: the stage-time
    // minter is the authority, the DOM its projection, and every step
    // below addresses the paragraphs by these classes.
    $minted = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const byText = {};
        document.querySelectorAll("$canvas_a p, $canvas_b p").forEach((p) => {
          const identity = Array.from(p.classList).find((name) => /^s-[a-z0-9]+$/.test(name));
          byText[p.textContent.trim()] = identity ?? null;
        });
        return JSON.stringify(byText);
      })()
      JS), TRUE);
    $this->assertIsArray($minted, 'The minted-identity read returned its map.');
    $target_class = $minted['Alpha paragraph target'] ?? NULL;
    $sibling_class = $minted['Beta paragraph sibling'] ?? NULL;
    $control_class = $minted['Gamma paragraph control'] ?? NULL;
    $this->assertNotNull($target_class, 'The target paragraph minted an identity at stage time.');
    $this->assertNotNull($sibling_class, 'The sibling paragraph minted an identity at stage time.');
    $this->assertNotNull($control_class, 'The control paragraph minted an identity at stage time.');
    $this->assertNotSame($target_class, $sibling_class, 'The two paragraphs minted distinct identities.');
    $target_part = ".$target_class";
    $sibling_part = ".$sibling_class";

    // Both rails: the page tree on the left (the basic tree is the
    // fallback projection, instance rows included), the inspector on
    // the right. The cookie entry can render either closed.
    foreach (['page_elements', 'daedalus_stylish_inspector'] as $sidebar) {
      $assert_session->waitForElement('css', "#$sidebar");
      $session->executeScript(<<<JS
        (() => {
          const panel = document.getElementById('$sidebar');
          if (panel && panel.classList.contains('daedalus-ui-hidden')) {
            Drupal.Daedalus.SidebarManager.openSidebar('$sidebar');
          }
        })();
        JS);
      $this->assertJsCondition(
        "(() => { const p = document.getElementById('$sidebar');"
        . " return !!p && !p.classList.contains('daedalus-ui-hidden'); })()",
        20000,
        sprintf('The %s sidebar is open.', $sidebar),
      );
    }

    // The whole scenario runs in BASIC mode, the mode a fresh session
    // starts in; assert it so the claims below stay basic-mode claims.
    $this->assertJsCondition(
      "(() => { const active = document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]');"
      . " return !!active && active.textContent.trim() === 'Basic'; })()",
      20000,
      'The inspector is in Basic mode (the fresh-session default).',
    );

    // The row finder: instance rows are located by their content excerpt
    // (the sublabel), because that is the identity a user reads.
    $session->executeScript(<<<'JS'
      window.__ft = {
        partRow(needle) {
          return Array.from(document.querySelectorAll('.daedalus-ui-tree__row--part'))
            .find((row) => (row.querySelector('.daedalus-ui-tree__sublabel')?.textContent ?? '').includes(needle)) ?? null;
        },
      };
      JS);

    // ----- Instance row styling in basic. -----
    // The target paragraph's row exists in the BASIC tree (instance rows
    // are content, not markup, so no Show-all-markup toggle is touched).
    $this->assertJsCondition(
      "!!window.__ft.partRow('Alpha paragraph target')",
      20000,
      'The target paragraph renders an instance row in the basic tree.',
    );
    // A REAL click on the row: tagged just-in-time so Mink can address
    // it, scrolled to center so the pointer lands on it.
    $session->executeScript(<<<'JS'
      (() => {
        const row = window.__ft.partRow('Alpha paragraph target');
        row.setAttribute('data-fjs-row', 'target');
        row.scrollIntoView({ block: 'center' });
      })();
      JS);
    $assert_session->elementExists('css', "[data-fjs-row='target']")->click();
    // The click committed the part facet on the selection spine: the row
    // reads its active state back from the store.
    $this->assertJsCondition(
      "(() => { const row = window.__ft.partRow('Alpha paragraph target');"
      . " return !!row && row.classList.contains('daedalus-ui-tree__row--part-active'); })()",
      20000,
      'The clicked instance row is active (the part facet committed on the spine).',
    );
    // The basic panel retargeted the drilled part: the breadcrumb wears
    // the part, and the curated control set is the instance
    // declaration's, not the block root's.
    $this->assertJsCondition(
      "document.querySelector('.daedalus-stylish-inspector__part')?.getAttribute('title') === " . json_encode($target_part),
      20000,
      'The panel breadcrumb targets the selected paragraph part.',
    );
    $alignment = '#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--text-alignment';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $alignment),
      'The instance declaration\'s curated text-alignment control rendered for the drilled part.',
    );

    // The map read every commit below asserts against: the root entity's
    // blueprint mirror, the same authority the canvas re-renders from.
    $map = "(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles ?? {})";
    // The compound part key is owner + '::' + part selector.
    $target_key = json_encode($block_a_key . '::' . $target_part);

    // A real click on the Center segment commits text-align to the PART
    // key itself (instance controls are root-bound to the part), through
    // the same op path every widget uses.
    $this->clickPanel("$alignment button[aria-label='Center']");
    $this->assertJsCondition(
      $map . '[' . $target_key . "]?.default?.['text-align'] === 'center'",
      20000,
      'The Center pick committed text-align onto the paragraph part key (control, op, mirror).',
    );
    // The canvas restyled exactly the selected paragraph, without reload;
    // its sibling paragraph is untouched. This is the round trip the
    // three modules only complete in a browser.
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas_a p.$target_class\")).textAlign === 'center'",
      20000,
      'The selected paragraph centered on the canvas.',
    );
    $this->assertNotSame(
      'center',
      $session->evaluateScript("getComputedStyle(document.querySelector(\"$canvas_a p.$sibling_class\")).textAlign"),
      'The sibling paragraph did not center: the part write stayed on its element.',
    );

    // ----- The tag tier beats the skin's base:p. -----
    // Prove the interposer first: the sibling paragraph's computed
    // font-size IS the skin's base:p value (olivero's base:p wears
    // var(--text-body)), measured by a probe span resolving the body
    // token in the same token scope. Without this the step could pass
    // against no opponent. The tier write below speaks the skin's type
    // scale (the suite binds font-size to it site-wide, so a token is
    // the honest spelling on the tier under any posture): the lead
    // token, measured the same way so the claim reads the skin, and
    // proven to resolve to a size body's does not, so the win is
    // observable.
    $tier_facts = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const sibling = document.querySelector("$canvas_a p.$sibling_class");
        const resolve = (reference) => {
          const probe = document.createElement('span');
          probe.style.fontSize = reference;
          sibling.parentElement.append(probe);
          const size = getComputedStyle(probe).fontSize;
          probe.remove();
          return size;
        };
        return JSON.stringify({
          skinSize: resolve('var(--text-body)'),
          leadSize: resolve('var(--text-lead)'),
          siblingBefore: getComputedStyle(sibling).fontSize,
          controlBefore: getComputedStyle(document.querySelector("$canvas_b p.$control_class")).fontSize,
        });
      })()
      JS), TRUE);
    $this->assertIsArray($tier_facts, 'The pre-write font-size probe returned its measurements.');
    $this->assertSame(
      $tier_facts['skinSize'],
      $tier_facts['siblingBefore'],
      'Before the tag write, the paragraph wears the skin base:p font-size (the body token: the interposer is live).',
    );
    $this->assertMatchesRegularExpression('/^\d+(\.\d+)?px$/', $tier_facts['leadSize'], 'The lead token resolves to a length inside the block (the skin mints it).');
    $this->assertNotSame(
      $tier_facts['skinSize'],
      $tier_facts['leadSize'],
      'The lead token resolves to a size that differs from base:p\'s body token, so the tag tier\'s win is observable.',
    );

    // The tag-tier write, through the same update_style op the code view
    // and the dialect commit: `{key}::p`, a live rule over every
    // paragraph of block A, present and future, spelled as the lead
    // token's reference.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'update_style', key: '$block_a_key::p', properties: { 'font-size': 'var(--text-lead)' } },
          ],
        })).catch((error) => {
          window.__tagTierError = error.message;
        });
      })();
      JS);
    $tag_key = json_encode("$block_a_key::p");
    $this->assertJsCondition(
      'window.__tagTierError !== undefined || ' . $map . '[' . $tag_key . "]?.default?.['font-size'] === 'var(--text-lead)'",
      20000,
      'The tag-tier commit resolved.',
    );
    $tag_error = $session->evaluateScript('window.__tagTierError ?? null');
    $this->assertNull($tag_error, 'The tag-tier update_style failed: ' . ($tag_error ?? ''));
    // The browser fact: the element-scoped descendant tag rule beats the
    // skin's base:p on BOTH of block A's paragraphs (each computes the
    // lead token's resolved size), and block B's paragraph still wears
    // the body value (the tier is element-scoped).
    $lead_size = json_encode($tier_facts['leadSize']);
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas_a p.$target_class\")).fontSize === $lead_size"
      . " && getComputedStyle(document.querySelector(\"$canvas_a p.$sibling_class\")).fontSize === $lead_size",
      20000,
      'Both of the element\'s paragraphs compute the tag-tier font-size (the lead token\'s resolved size): the tier beat base:p\'s body token on the canvas.',
    );
    $this->assertSame(
      $tier_facts['controlBefore'],
      $session->evaluateScript("getComputedStyle(document.querySelector(\"$canvas_b p.$control_class\")).fontSize"),
      'The neighboring block\'s paragraph kept the skin\'s body font-size: the tag tier is element-scoped.',
    );

    // ----- Canvas deep-select in basic. -----
    // A modifier click on the SIBLING paragraph in the canvas resolves
    // its instance part through the registered part behavior and commits
    // the facet; tree and panel must follow. The click is synthetic
    // because the OS-level modifier state cannot ride a Mink click, but
    // it runs the same capture-phase document listener a real click
    // does.
    $session->executeScript(
      "document.querySelector(\"$canvas_a p.$sibling_class\").dispatchEvent("
      . "new MouseEvent('click', { bubbles: true, cancelable: true, metaKey: true }));"
    );
    $this->assertJsCondition(
      "(() => { const row = window.__ft.partRow('Beta paragraph sibling');"
      . " return !!row && row.classList.contains('daedalus-ui-tree__row--part-active'); })()",
      20000,
      'The deep-selected paragraph\'s instance row highlighted in the basic tree.',
    );
    $this->assertJsCondition(
      "document.querySelector('.daedalus-stylish-inspector__part')?.getAttribute('title') === " . json_encode($sibling_part),
      20000,
      'The panel breadcrumb retargeted to the deep-selected paragraph: canvas, tree, and panel agree in basic.',
    );

    // ----- List-item row styling in basic. -----
    // Block C: a bulleted list carrying a nested list. Universal minting
    // stamps every declared tag at any depth, so the wrapper, each item,
    // the nested wrapper, and the nested item all mint identities at
    // stage time — every one of them a carrier row.
    $block_c_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_element',
              uuid: '$block_c_uuid',
              block_type: 'formatted_text',
              properties: {
                field_formatted_text_text: {
                  value: '<ul><li>Delta list item target</li><li>Epsilon list item sibling</li>'
                    // Two nested items: a wrapper's excerpt is its whole
                    // textContent, so a single-item nested list would make
                    // the nested UL's row and its item's row textually
                    // identical and the exact-text row finder ambiguous.
                    + '<li>Zeta outer item<ul><li>Eta nested item</li><li>Theta nested item</li></ul></li></ul>',
                  format: 'daedalus_edit_formatted_text',
                },
              },
              location: { anchor: '$block_b_uuid', placement: 'after' },
            },
          ],
        })).catch((error) => {
          window.__listPlacementError = error.message;
        });
      })();
      JS);
    $block_c_key = "block:$block_c_uuid";
    $canvas_c = "[data-selectable='$block_c_key']";
    $this->assertJsCondition(
      "window.__listPlacementError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$block_c_uuid')",
      20000,
      'The list-block placement resolved.',
    );
    $list_placement_error = $session->evaluateScript('window.__listPlacementError ?? null');
    $this->assertNull($list_placement_error, 'List block placement failed: ' . ($list_placement_error ?? ''));
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$canvas_c li"),
      'The list block rendered on the canvas.',
    );

    // The minted item identities, read back by each item's OWN text. The
    // outer item's textContent includes its nested list, so exact-text
    // keys address the two flat items and the nested item unambiguously.
    $list_minted = json_decode((string) $session->evaluateScript(<<<JS
      (() => {
        const byText = {};
        document.querySelectorAll("$canvas_c li").forEach((li) => {
          const identity = Array.from(li.classList).find((name) => /^s-[a-z0-9]+$/.test(name));
          byText[li.textContent.trim()] = identity ?? null;
        });
        return JSON.stringify(byText);
      })()
      JS), TRUE);
    $this->assertIsArray($list_minted, 'The list minted-identity read returned its map.');
    $item_class = $list_minted['Delta list item target'] ?? NULL;
    $item_sibling_class = $list_minted['Epsilon list item sibling'] ?? NULL;
    $nested_class = $list_minted['Eta nested item'] ?? NULL;
    $this->assertNotNull($item_class, 'The target list item minted an identity at stage time (universal minting).');
    $this->assertNotNull($item_sibling_class, 'The sibling list item minted an identity at stage time.');
    $this->assertNotNull($nested_class, 'The nested list item minted an identity at stage time (minting at depth).');
    $this->assertNotSame($item_class, $item_sibling_class, 'The two list items minted distinct identities.');

    // An EXACT-text row finder beside the includes() one: a list
    // wrapper's excerpt contains every item's text (its textContent),
    // so only exact sublabel equality addresses one item's row.
    $session->executeScript(<<<'JS'
      window.__ft.exactRow = (text) => Array.from(document.querySelectorAll('.daedalus-ui-tree__row--part'))
        .find((row) => (row.querySelector('.daedalus-ui-tree__sublabel')?.textContent ?? '').trim() === text) ?? null;
      JS);

    // The item's row exists in the BASIC tree; a real click selects it.
    $this->assertJsCondition(
      "!!window.__ft.exactRow('Delta list item target')",
      20000,
      'The target list item renders an instance row in the basic tree.',
    );
    $session->executeScript(<<<'JS'
      (() => {
        const row = window.__ft.exactRow('Delta list item target');
        row.setAttribute('data-fjs-row', 'list-target');
        row.scrollIntoView({ block: 'center' });
      })();
      JS);
    $assert_session->elementExists('css', "[data-fjs-row='list-target']")->click();
    $this->assertJsCondition(
      "(() => { const row = window.__ft.exactRow('Delta list item target');"
      . " return !!row && row.classList.contains('daedalus-ui-tree__row--part-active'); })()",
      20000,
      'The clicked list-item row is active (the part facet committed on the spine).',
    );
    $this->assertJsCondition(
      "document.querySelector('.daedalus-stylish-inspector__part')?.getAttribute('title') === " . json_encode(".$item_class"),
      20000,
      'The panel breadcrumb targets the selected list item part.',
    );
    // The same curated instance controls render for an item (the
    // namespace declaration is tag-agnostic), bound to the item's key.
    $this->assertNotNull(
      $assert_session->waitForElement('css', $alignment),
      'The curated text-alignment control rendered for the drilled list item.',
    );
    $item_key = json_encode($block_c_key . '::' . ".$item_class");
    $this->clickPanel("$alignment button[aria-label='Center']");
    $this->assertJsCondition(
      $map . '[' . $item_key . "]?.default?.['text-align'] === 'center'",
      20000,
      'The Center pick committed text-align onto the list item part key.',
    );
    // The canvas restyled exactly the selected item; its sibling item
    // keeps its alignment.
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas_c li.$item_class\")).textAlign === 'center'",
      20000,
      'The selected list item centered on the canvas.',
    );
    $this->assertNotSame(
      'center',
      $session->evaluateScript("getComputedStyle(document.querySelector(\"$canvas_c li.$item_sibling_class\")).textAlign"),
      'The sibling list item did not center: the part write stayed on its item.',
    );

    // ----- Nested-list row selection sync in basic. -----
    // The nested item has its own row at depth; a modifier click on it
    // in the canvas resolves ITS part (the closest carrier) and the row
    // highlights while the breadcrumb retargets — which only works when
    // rows at depth carry the owning model key (flat ownership).
    $this->assertJsCondition(
      "!!window.__ft.exactRow('Eta nested item')",
      20000,
      'The nested list item renders an instance row at depth in the basic tree.',
    );
    $session->executeScript(
      "document.querySelector(\"$canvas_c li.$nested_class\").dispatchEvent("
      . "new MouseEvent('click', { bubbles: true, cancelable: true, metaKey: true }));"
    );
    $this->assertJsCondition(
      "(() => { const row = window.__ft.exactRow('Eta nested item');"
      . " return !!row && row.classList.contains('daedalus-ui-tree__row--part-active'); })()",
      20000,
      'The deep-selected nested item\'s row highlighted in the basic tree.',
    );
    $this->assertJsCondition(
      "document.querySelector('.daedalus-stylish-inspector__part')?.getAttribute('title') === " . json_encode(".$nested_class"),
      20000,
      'The panel breadcrumb retargeted to the nested item: canvas, tree, and panel agree at depth.',
    );

    // ----- Breadcrumb depth over the markup tier. -----
    // The selection indicator's breadcrumb walks the ONE selection index
    // over both tiers (daedalus_ui ancestry over projection-index,
    // built from the live annotation), so the deep-selected nested item
    // trails part to part through its markup parents up to the owning
    // block. The spine, the merged index, and the indicator agree only
    // in a browser; the crumb text is read from the rendered nav.
    $crumbs = "Array.from(document.querySelectorAll('.daedalus-ui-breadcrumb .daedalus-ui-breadcrumb__crumb'), (crumb) => crumb.textContent.trim()).join(' › ')";
    $this->assertJsCondition(
      "$crumbs.endsWith(" . json_encode(' › Formatted text › Bulleted list › List item › Bulleted list › List item') . ')',
      20000,
      'The breadcrumb trails the nested item through its markup parents to the owning Formatted text block.',
    );

    // ----- Keyboard climb over the markup tier. -----
    // ArrowLeft from the nested item: the hotkey dispatcher routes the key
    // to traversal, traversal climbs the index's markup tier to the nested
    // list and commits {owner, part} on the spine, and the inspector chip
    // names the nested list's own minted identity. The key is dispatched
    // on document, the exact event the dispatcher listens for.
    $nested_list_class = $session->evaluateScript(
      "Array.from(document.querySelector(\"$canvas_c li.$nested_class\").parentElement.classList)"
      . '.find((name) => /^s-[a-z0-9]+$/.test(name)) ?? null'
    );
    $this->assertNotNull($nested_list_class, 'The nested list wrapper minted an identity at stage time.');
    $this->pressHotkey('ArrowLeft');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-stylish-inspector__part')?.getAttribute('title') === " . json_encode(".$nested_list_class"),
      20000,
      'ArrowLeft climbed from the nested item to its list: the inspector chip names the nested list part.',
    );
    $this->assertJsCondition(
      "$crumbs.endsWith(" . json_encode(' › Formatted text › Bulleted list › List item › Bulleted list') . ')',
      20000,
      'The breadcrumb followed the climb: the trail now ends at the nested list.',
    );
  }

}
