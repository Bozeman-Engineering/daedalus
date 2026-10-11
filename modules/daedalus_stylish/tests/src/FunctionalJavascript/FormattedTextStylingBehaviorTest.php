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
 *   while the panel's subject chip targets it, so canvas and tree agree on
 *   a selection universe that only exists because instance rows render
 *   in basic.
 * - Canvas seat to outline reveal (seam: the selection spine's target to
 *   the Outline's selection rule to the part row's scroll): with the
 *   tree collapsed all, a modifier click on a paragraph of the already
 *   selected block moves only the TARGET, and the outline opens the
 *   block's closed default down to the seated paragraph's row, which
 *   renders active and inside the panel's scrollport. The reveal and
 *   the row id it reveals are node-pinned (collapse.test.mjs); only a
 *   browser proves the store's target reaches the effect and the row it
 *   mounts scrolls into view.
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
 *   to the owning block (… › Formatted text › Bulleted list › the outer
 *   item's text › Bulleted list › Eta nested item: a carrier's crumb is
 *   its text, the row grammar's name, and a list wrapper reads as its
 *   word). Ancestry is node-pinned over a fake index; only a browser
 *   sees the spine, the index built from the live annotation, and the
 *   indicator agree.
 * - Keyboard climb over the markup tier (seam: hotkey dispatcher to
 *   traversal over the index's markup tier to the spine to the
 *   inspector): ArrowLeft from the nested item commits its list as the
 *   part facet, so the inspector chip names the nested list's own
 *   identity and the breadcrumb shortens by one crumb.
 * - The instance part's address set (seam: annotation instances to
 *   matcher facts to switcher, live): with a minted paragraph targeted,
 *   the subject chip's "Style this at" menu offers the element's own tag
 *   tier ("Every paragraph here") and the section's tag rule ("Every
 *   paragraph in the section"), and no entry names the identity class.
 *   The node suites prove the switcher's shapes over a fixture; only a
 *   browser proves the annotation the inspector hands the matcher carries
 *   the instances rows the fold reads.
 * - Subject and address (seam: chip menu to spine address to basic rows
 *   to the op): picking "Every paragraph in Section" seats the spine's
 *   WRITE ADDRESS alone (the selection and the minted paragraph's SUBJECT
 *   facet stay), the chip lights at container::p, the paragraph's
 *   curated controls stay the rows, a typed Size lands ONE update_style
 *   at the section's rule key, the canvas delivers it to the neighbour
 *   block's paragraph while block A's keep the nearer tier's size, and
 *   the own entry drops the address alone. The node suites pin the
 *   slice, the chip model and the rebased control models; only a
 *   browser proves the pick reaches the spine and the op lands where
 *   the chip says.
 * - An own-key address in code mode (seam: chip pick to spine address to
 *   the code view's lit mark and its draft, live): with code mode open,
 *   picking "Every paragraph here" lights the `p` PART row of block A
 *   alone (a part header, the facet row dark, no second header for the
 *   key anywhere: an own-key address places no virtual rule row); on
 *   block C's list item, picking the mintable "Every list item here"
 *   drafts the `li` island with the cursor, the ancestor seat's parity,
 *   the map untouched. The model pins the placement rule; only a
 *   browser proves the pick reaches the view's one lit rule and the
 *   draft takes focus.
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
   *   chip-less instance row selects it (row active, the subject chip
   *   targets the part), the curated text-alignment control renders from the
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
   *   and the panel's subject chip retargets, in basic mode.
   * - Seat reveal: collapse all folds block A over its carriers; a
   *   modifier click on the target paragraph (block A still selected)
   *   opens the block down to that paragraph's row, active and inside
   *   the panel's scrollport, with the sibling's row rendered inactive.
   * - List rows: block C stages a bulleted list with a nested list;
   *   universal minting stamps the wrappers and every item. A real
   *   click on one item's row selects it and a Center pick commits
   *   text-align onto the ITEM's part key; the canvas centers exactly
   *   that item, not its sibling item.
   * - Nested sync: the nested list's item renders its own row in the
   *   basic tree; a modifier click on it in the canvas highlights that
   *   row and retargets the subject chip (flat ownership at depth, live).
   * - Breadcrumb depth: with the nested item selected, the selection
   *   indicator's breadcrumb ends "Formatted text › Bulleted list › Zeta
   *   outer itemEta nested itemTheta nested item › Bulleted list › Eta
   *   nested item" (markup parents by their names, the outer item's name
   *   its whole text, then the owner).
   * - Keyboard climb: ArrowLeft commits the nested list as the part
   *   facet; the inspector chip names its minted identity and the
   *   breadcrumb ends at "Bulleted list".
   * - Address set: block A's target paragraph is targeted again through
   *   the spine; the subject chip's "Style this at" menu lists "Every
   *   paragraph here" before "Every paragraph in Section" and no
   *   entry containing `.s-`.
   * - Subject and address: a real pick of "Every paragraph in Section"
   *   seats the write address (the facet and selection unchanged, the
   *   chip lit, the paragraph's controls still mounted); a typed Size
   *   commits ONE update_style at the section's `p` rule, block B's
   *   paragraph computes it and block A's keep the lead size; the own
   *   entry drops the address alone.
   * - Own-key address in code mode: the mode flips to Code; "Every
   *   paragraph here" lights block A's `p` part row alone (one header
   *   for the key, the facet row dark); block C's list item targeted
   *   through the spine, "Every list item here" drafts the `li` island
   *   with the cursor and writes nothing.
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

    $this->enterEditModeViaCookies($node->toUrl());
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
    // (the row's name), because that is the identity a user reads. Rich
    // text starts collapsed (a text block over its carriers, a list over
    // its items), so the polls that wait for a carrier's row press expand
    // all (the page elements root row's control over the tree's one
    // outline) on the way, the keyboard-free twin of a user opening the
    // tree; the posture is keyed by row id and stands for the scenario.
    $session->executeScript(<<<'JS'
      window.__ft = {
        partRow(needle) {
          return Array.from(document.querySelectorAll('.daedalus-ui-tree__row--part'))
            .find((row) => (row.querySelector('.daedalus-ui-outline__name')?.textContent ?? '').includes(needle)) ?? null;
        },
        expandAll() {
          document.querySelector('.daedalus-ui-tree__tools .daedalus-ui-expand-all')?.click();
        },
        collapseAll() {
          document.querySelector('.daedalus-ui-tree__tools .daedalus-ui-collapse-all')?.click();
        },
        // Whether a row sits wholly inside its scrollport, read the way the
        // reveal rule (daedalus_ui scroll-reveal.js) reads it: the nearest
        // vertically scrolling ancestor's padding box, else the viewport.
        inScrollport(row) {
          const scrolling = new Set(['auto', 'scroll', 'overlay']);
          let scroller = null;
          for (let node = row.parentElement; node && node !== document.documentElement && node !== document.body; node = node.parentElement) {
            if (scrolling.has(getComputedStyle(node).overflowY) && node.scrollHeight > node.clientHeight) {
              scroller = node;
              break;
            }
          }
          const port = scroller
            ? (() => { const rect = scroller.getBoundingClientRect(); const top = rect.top + scroller.clientTop; return { top, bottom: top + scroller.clientHeight }; })()
            : { top: 0, bottom: document.documentElement.clientHeight };
          const rect = row.getBoundingClientRect();
          return rect.top >= port.top && rect.bottom <= port.bottom;
        },
      };
      JS);

    // ----- Instance row styling in basic. -----
    // The target paragraph's row exists in the BASIC tree (instance rows
    // are content, not markup, so no Show-all-markup toggle is touched).
    $this->assertJsCondition(
      "(() => { window.__ft.expandAll(); return !!window.__ft.partRow('Alpha paragraph target'); })()",
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
    // The basic panel retargeted the drilled part: the subject line's
    // address chip stands at the part's own address, and the curated
    // control set is the instance declaration's, not the block root's.
    $this->assertJsCondition(
      "document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__address')?.dataset.daedalusStylishAddress === " . json_encode("$block_a_key::$target_part"),
      20000,
      'The panel subject line targets the selected paragraph part.',
    );
    $alignment = '#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--text-alignment';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $alignment),
      'The instance declaration\'s curated text-alignment control rendered for the drilled part.',
    );

    // The map read every commit below asserts against: the root entity's
    // blueprint mirror, the same authority the canvas re-renders from (the
    // flat index derived from the tree's node styles, see styleMapJs()).
    $map = $this->styleMapJs();
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
      "document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__address')?.dataset.daedalusStylishAddress === " . json_encode("$block_a_key::$sibling_part"),
      20000,
      'The panel subject line retargeted to the deep-selected paragraph: canvas, tree, and panel agree in basic.',
    );

    // ----- Canvas seat to outline reveal. -----
    // Seam: the selection spine's target to the Outline's one selection
    // rule to the part row's scroll. Formatted text defaults to closed,
    // and the rule read on the selected KEY alone opened the path to the
    // block and stopped at its fold, so a seated paragraph stayed hidden
    // (and, with the block already selected, the path did not even
    // change). The rule now reveals the seated part's row (tree-outline.js
    // revealId) and the row scrolls itself into the panel's scrollport.
    // The reveal pure function is node-pinned; only a browser proves the
    // store's target reaches the effect and the mounted row reveals.
    // Collapse all first (the root row's control): block A folds over its
    // carriers and the target paragraph's row is gone from the tree.
    $session->executeScript('window.__ft.collapseAll();');
    $this->assertJsCondition(
      "!window.__ft.partRow('Alpha paragraph target')",
      20000,
      'Collapse all folded block A over its carriers: the target paragraph has no rendered row.',
    );
    // A modifier click on the TARGET paragraph while block A is already
    // the selection and its sibling the target: only the target moves,
    // which is exactly the gesture whose path did not change before.
    $session->executeScript(
      "document.querySelector(\"$canvas_a p.$target_class\").dispatchEvent("
      . "new MouseEvent('click', { bubbles: true, cancelable: true, metaKey: true }));"
    );
    $this->assertJsCondition(
      "(() => { const row = window.__ft.partRow('Alpha paragraph target');"
      . " return !!row && row.classList.contains('daedalus-ui-tree__row--part-active'); })()",
      20000,
      'Canvas seat to outline reveal: the seat opened the collapsed block down to the seated paragraph, whose row rendered active.',
    );
    $this->assertJsCondition(
      "(() => { const row = window.__ft.partRow('Alpha paragraph target');"
      . ' return !!row && window.__ft.inScrollport(row); })()',
      20000,
      'Canvas seat to outline reveal: the revealed row sits inside the panel\'s scrollport (the row scrolled itself into view by the reveal rule).',
    );
    $this->assertJsCondition(
      "(() => { const row = window.__ft.partRow('Beta paragraph sibling');"
      . " return !!row && !row.classList.contains('daedalus-ui-tree__row--part-active'); })()",
      20000,
      'Canvas seat to outline reveal: the sibling row, under the same opened block, is rendered and no longer active (one target on the spine).',
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

    // An EXACT-text row finder beside the includes() one: a list item's
    // name is its text and so is its paragraph's, so only exact name
    // equality in document order addresses the item's own row.
    $session->executeScript(<<<'JS'
      window.__ft.exactRow = (text) => Array.from(document.querySelectorAll('.daedalus-ui-tree__row--part'))
        .find((row) => (row.querySelector('.daedalus-ui-outline__name')?.textContent ?? '').trim() === text) ?? null;
      JS);

    // The item's row exists in the BASIC tree; a real click selects it.
    $this->assertJsCondition(
      "(() => { window.__ft.expandAll(); return !!window.__ft.exactRow('Delta list item target'); })()",
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
      "document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__address')?.dataset.daedalusStylishAddress === " . json_encode("$block_c_key::.$item_class"),
      20000,
      'The panel subject line targets the selected list item part.',
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
    // highlights while the subject chip retargets, which only works when
    // rows at depth carry the owning model key (flat ownership).
    $this->assertJsCondition(
      "(() => { window.__ft.expandAll(); return !!window.__ft.exactRow('Eta nested item'); })()",
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
      "document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__address')?.dataset.daedalusStylishAddress === " . json_encode("$block_c_key::.$nested_class"),
      20000,
      'The panel subject line retargeted to the nested item: canvas, tree, and panel agree at depth.',
    );

    // ----- Breadcrumb depth over the markup tier. -----
    // The selection indicator's breadcrumb walks the ONE selection index
    // over both tiers (daedalus_ui ancestry over projection-index,
    // built from the live annotation), so the deep-selected nested item
    // trails part to part through its markup parents up to the owning
    // block. The spine, the merged index, and the indicator agree only
    // in a browser; the crumb text is read from the rendered nav. A
    // carrier's crumb is its NAME under the outline row grammar (its
    // text excerpt, the annotator's whitespace-normalized textContent,
    // so the outer item's name runs on into its nested items' text); a
    // list wrapper reads as its vocabulary word, since its text is only
    // its items'.
    $outer_item_name = 'Zeta outer itemEta nested itemTheta nested item';
    $crumbs = "Array.from(document.querySelectorAll('.daedalus-ui-breadcrumb .daedalus-ui-breadcrumb__crumb'), (crumb) => crumb.textContent.trim()).join(' › ')";
    $this->assertJsCondition(
      "$crumbs.endsWith(" . json_encode(' › Eta nested item') . ')',
      20000,
      'The breadcrumb ends on the nested item\'s own name.',
    );
    $this->assertStringEndsWith(
      " › Formatted text › Bulleted list › $outer_item_name › Bulleted list › Eta nested item",
      (string) $session->evaluateScript($crumbs),
      'The breadcrumb trails the nested item through its markup parents (each by its row name) to the owning Formatted text block.',
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
      "document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-inspector__address')?.dataset.daedalusStylishAddress === " . json_encode("$block_c_key::.$nested_list_class"),
      20000,
      'ArrowLeft climbed from the nested item to its list: the inspector chip stands at the nested list part.',
    );
    $this->assertJsCondition(
      "$crumbs.endsWith(" . json_encode(" › Formatted text › Bulleted list › $outer_item_name › Bulleted list") . ')',
      20000,
      'The breadcrumb followed the climb: the trail now ends at the nested list.',
    );

    // ----- The instance part's address set. -----
    // Seam: annotation instances to matcher facts to switcher, live. The
    // annotation's instances rows (daedalus_ui's annotator, stamped with
    // each minted element's tag) fold into the matcher's node facts as
    // class to tag, and the switcher offers a minted paragraph its class
    // of things by TAG: the element's own tag tier ("Every paragraph
    // here", the `{block A}::p` rule the tier step above wrote), then the
    // section's `p` rule and the chained shapes ending in `p`, never a
    // chain ending in the identity class. The node suites
    // (selector-matcher, write-address, parts) prove the model; only the
    // live page proves that the annotation the inspector hands the
    // matcher carries the rows the switcher reads. Back to block A's
    // target paragraph through the spine's own calls (the row-click and
    // canvas legs above already proved the selection gestures).
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_a_key'); Drupal.Daedalus.Selection.target('$block_a_key', '$target_part');");
    $address_chip = '#daedalus_stylish_inspector .daedalus-stylish-inspector__address';
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip');"
      . ' return !!chip && chip.dataset.daedalusStylishAddress === ' . json_encode("$block_a_key::$target_part")
      . " && chip.tagName === 'BUTTON'; })()",
      20000,
      'Annotation instances to matcher facts to switcher: the subject line\'s chip stands at the target paragraph part as a menu button (the switcher found more than the own entry to offer).',
    );
    $this->clickPanel($address_chip);
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu') && document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')?.textContent.trim() === 'Style this at'",
      20000,
      'Annotation instances to matcher facts to switcher: the chip opened the shared menu headed "Style this at".',
    );
    $menu_labels = json_decode((string) $session->evaluateScript(
      "JSON.stringify(Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'), (item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() ?? ''))"
    ), TRUE);
    $this->assertIsArray($menu_labels, 'Annotation instances to matcher facts to switcher: the menu read returned its entry labels.');
    $this->assertContains(
      'Every paragraph here',
      $menu_labels,
      'Annotation instances to matcher facts to switcher: the menu offers the element\'s own tag tier "Every paragraph here" (the matcher learned the paragraph\'s tag from the live annotation, so the switcher could name its class of things).',
    );
    $this->assertContains(
      'Every paragraph in Section',
      $menu_labels,
      'Annotation instances to matcher facts to switcher: the menu offers the section\'s tag rule "Every paragraph in Section" (the label-less section reads "Section", its minted name; the tag rule reaches the minted paragraph through the folded instance fact).',
    );
    $this->assertLessThan(
      array_search('Every paragraph in Section', $menu_labels, TRUE),
      array_search('Every paragraph here', $menu_labels, TRUE),
      'Annotation instances to matcher facts to switcher: the own tag tier precedes the ancestors\' shapes (the nearest owner that styles every paragraph at once comes first).',
    );
    $this->assertSame(
      [],
      array_values(array_filter($menu_labels, static fn (string $label) => str_contains($label, '.s-'))),
      'Annotation instances to matcher facts to switcher: no entry names the identity class (a minted element\'s class of things is its tag; the chains end in `p`, never in `.s-*`).',
    );

    // ----- A minted paragraph styled at the section's rule. -----
    // Seam: chip menu to spine address to basic rows to the op, live.
    // The spine carries two facts: the SUBJECT facet (the minted
    // paragraph, whose rows the panel reads) and the WRITE ADDRESS (the
    // section's `p` rule, where a write lands). A real pick of "Every
    // paragraph in Section" seats the address alone: the selection and
    // the facet stay, the chip lights at the rule, the curated instance
    // controls stay mounted (nothing derives the subject from the rule,
    // so the minted element keeps its own rows), and a typed Size
    // settles as ONE update_style at the SECTION's rule key, which the
    // canvas delivers to the neighbouring block's paragraph while block
    // A's paragraphs keep the nearer block tier's lead size (the
    // document-order cascade's proximity, a browser fact). The node
    // suites pin the slice, the chip model and the rebased control
    // models; only a browser proves the pick reaches the spine, the
    // panel reads both facts and the op lands where the chip says.
    $section_key = "container:$section_uuid";
    $section_rule_key = "$section_key::p";
    $size_input = "#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--typography input[aria-label='Size']";
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Every paragraph in Section').click();"
    );
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); const size = document.querySelector(\"$size_input\"); const alignment = document.querySelector('$alignment');"
      . ' if (!chip || !size || !alignment) { return false; }'
      . ' const target = Drupal.Daedalus.Selection.getTarget(); const address = Drupal.Daedalus.Selection.getAddress();'
      . ' return chip.dataset.daedalusStylishAddress === ' . json_encode($section_rule_key)
      . " && chip.querySelector('.daedalus-stylish-inspector__address-label')?.textContent.trim() === 'Every paragraph in Section'"
      . " && chip.classList.contains('daedalus-stylish-chip--lit') && !document.querySelector('.daedalus-ui-menu')"
      . ' && Drupal.Daedalus.Selection.getSelected() === ' . json_encode($block_a_key)
      . ' && target?.key === ' . json_encode($block_a_key) . ' && target?.selector === ' . json_encode($target_part)
      . ' && address?.key === ' . json_encode($section_key) . " && address?.selector === 'p'"
      . ' && !size.disabled; })()',
      20000,
      'Subject and address: the pick seated the section\'s `p` rule as the WRITE ADDRESS (the chip lit at container::p, "Every paragraph in Section") while the selection stayed on block A and the SUBJECT facet stayed the minted paragraph, whose curated controls (Size live, Text alignment mounted) are still the rows: no inverse of the rule replaced the element.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas_b p.$control_class\")).fontSize !== '30px'",
      20000,
      'Subject and address: block B\'s paragraph does not compute 30px before the write, so the write below is observable.',
    );
    $this->recordOps();
    $this->typeIntoPanel($size_input, '30px');
    $this->blurPanel($size_input);
    $this->assertJsCondition(
      $map . '[' . json_encode($section_rule_key) . "]?.default?.['font-size'] === '30px'"
      . ' && ' . $map . '[' . $target_key . "]?.default?.['font-size'] === undefined",
      20000,
      'Subject and address: the typed Size committed font-size: 30px at container::p, the write address, and nothing on the minted paragraph\'s own key.',
    );
    $this->assertOneOp(
      $section_rule_key,
      ['font-size' => '30px'],
      'Subject and address: the settle was ONE update_style op on the address key carrying font-size alone: the basic widget wrote at the rebased candidate of the paragraph\'s own control model.',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$canvas_b p.$control_class\")).fontSize === '30px'"
      . " && getComputedStyle(document.querySelector(\"$canvas_a p.$target_class\")).fontSize === $lead_size"
      . " && getComputedStyle(document.querySelector(\"$canvas_a p.$sibling_class\")).fontSize === $lead_size",
      20000,
      'Subject and address: the section\'s rule delivers 30px to block B\'s paragraph without reload, while block A\'s paragraphs keep the lead size: the nearer block tier beats the section rule by scope proximity, exactly the reach the chip named.',
    );
    // The own entry: picking the first item ("This paragraph") drops the
    // address alone (address(null)); the facet stands, the chip returns
    // to the paragraph's own key, unlit.
    $this->clickPanel($address_chip);
    $this->assertJsCondition(
      "(() => { const items = Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'));"
      . " return items.length > 0 && items.find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Every paragraph in Section')?.getAttribute('aria-checked') === 'true'; })()",
      20000,
      'Subject and address: the reopened menu checks "Every paragraph in Section", the address standing.',
    );
    $session->executeScript("document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item')[0].click();");
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); if (!chip) { return false; }"
      . ' const target = Drupal.Daedalus.Selection.getTarget();'
      . ' return chip.dataset.daedalusStylishAddress === ' . json_encode("$block_a_key::$target_part")
      . " && !chip.classList.contains('daedalus-stylish-chip--lit') && !document.querySelector('.daedalus-ui-menu')"
      . ' && Drupal.Daedalus.Selection.getAddress() === null'
      . ' && target?.key === ' . json_encode($block_a_key) . ' && target?.selector === ' . json_encode($target_part) . '; })()',
      20000,
      'Subject and address: the own entry dropped the address (address(null)) and nothing else: the chip is back on the paragraph\'s own key, unlit, and the paragraph is still the subject facet.',
    );

    // ----- An own-key address in code mode: one lit rule, one draft. -----
    // Seam: chip pick to spine address to the code view's roster and
    // its lit mark, live. The code view (one ElementIslands body for
    // the selection, fed by the leader source) rows the element's own
    // tag tier `{block A}::p` as a PART row already, and the chip's
    // "Every paragraph here" is an address on the selection's own key
    // naming that row. The model pins that an own-key address reaches
    // no stack and places no rule row (element-model.test.mjs); only a
    // browser proves the pick reaches the view's one lit rule: the `p`
    // part header lights (a part header, never a rule header), the
    // facet's own header goes dark, no second header for the key prints
    // anywhere, and the chip reads the address. Then the placement
    // rule's own-key half on a MINTABLE address: block C's list item
    // targeted, "Every list item here" (`{block C}::li`, held by no
    // entry) seats the address and the view drafts its island WITH the
    // cursor, the ancestor seat's parity, the map untouched until a
    // write. Headers are read by the data attributes the page tree's
    // hot painter addresses (owner key and selector), the one spelling
    // a part header and a rule header share.
    $session->executeScript(<<<'JS'
      window.__ft.codeHeaders = (owner, part) => Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .daedalus-stylish-islands__part'))
        .filter((header) => header.dataset.daedalusStylishPartOwner === owner && header.dataset.daedalusStylishPart === part);
      window.__ft.lit = (header) => !!header
        && header.classList.contains('daedalus-stylish-islands__part--active')
        && header.querySelector('.daedalus-stylish-islands__part-select')?.getAttribute('aria-pressed') === 'true';
      window.__ft.litHeaders = () => document.querySelectorAll('.daedalus-stylish-page-tree .daedalus-stylish-islands__part--active').length;
      JS);
    $block_a_json = json_encode($block_a_key);
    $target_part_json = json_encode($target_part);
    $this->clickPanel('.daedalus-stylish-inspector__ui-mode-button--code');
    $this->assertJsCondition(
      "(() => { const facet = window.__ft.codeHeaders($block_a_json, $target_part_json); const tier = window.__ft.codeHeaders($block_a_json, 'p');"
      . ' return facet.length === 1 && window.__ft.lit(facet[0]) && tier.length === 1 && !window.__ft.lit(tier[0])'
      . ' && window.__ft.litHeaders() === 1 && Drupal.Daedalus.Selection.getAddress() === null; })()',
      20000,
      'Own-key address in code mode: code mode mounted block A\'s islands with the minted paragraph\'s facet row lit and the `p` tier row dark (one header each), no address standing.',
    );
    $this->clickPanel($address_chip);
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu') && document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__header')?.textContent.trim() === 'Style this at'",
      20000,
      'Own-key address in code mode: the chip opened the "Style this at" menu in code mode.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Every paragraph here').click();"
    );
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); const address = Drupal.Daedalus.Selection.getAddress(); const target = Drupal.Daedalus.Selection.getTarget();"
      . " const facet = window.__ft.codeHeaders($block_a_json, $target_part_json); const tier = window.__ft.codeHeaders($block_a_json, 'p');"
      . ' return !!chip && chip.dataset.daedalusStylishAddress === ' . json_encode("$block_a_key::p") . " && !document.querySelector('.daedalus-ui-menu')"
      . " && address?.key === $block_a_json && address?.selector === 'p'"
      . " && target?.key === $block_a_json && target?.selector === $target_part_json"
      . " && tier.length === 1 && window.__ft.lit(tier[0]) && !tier[0].classList.contains('daedalus-stylish-islands__part--rule')"
      . ' && facet.length === 1 && !window.__ft.lit(facet[0]) && window.__ft.litHeaders() === 1'
      . ' && ' . $map . '[' . json_encode("$block_a_key::p") . "]?.default?.['font-size'] === 'var(--text-lead)'; })()",
      20000,
      'Own-key address in code mode: "Every paragraph here" seated {block A}::p as the write address (the chip reads it, the facet stays the minted paragraph) and the code view lit the `p` PART row alone: a part header, not a rule header, the facet row dark, exactly one header for the key on the page (no virtual rule row under the facet), the tier entry untouched.',
    );

    // The mintable own-key address: block C's list item target has no
    // `li` tier yet. The pick drafts the `li` island with the cursor.
    $block_c_json = json_encode($block_c_key);
    $item_part_json = json_encode(".$item_class");
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_c_key'); Drupal.Daedalus.Selection.target('$block_c_key', '.$item_class');");
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); const item = window.__ft.codeHeaders($block_c_json, $item_part_json);"
      . ' return !!chip && chip.dataset.daedalusStylishAddress === ' . json_encode("$block_c_key::.$item_class") . " && chip.tagName === 'BUTTON'"
      . ' && Drupal.Daedalus.Selection.getAddress() === null && item.length === 1 && window.__ft.lit(item[0])'
      . " && window.__ft.codeHeaders($block_c_json, 'li').length === 0"
      . ' && ' . $map . '[' . json_encode("$block_c_key::li") . '] === undefined; })()',
      20000,
      'Mintable own-key address in code mode: block C\'s list item is the facet (its part row lit, the chip at its key as a menu button), no `li` row prints and the map holds no `li` tier.',
    );
    $this->clickPanel($address_chip);
    $this->assertJsCondition(
      "(() => { const items = Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'));"
      . " return items.length > 0 && !!items.find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Every list item here'); })()",
      20000,
      'Mintable own-key address in code mode: the chip\'s menu offers "Every list item here" (the item\'s own tag tier, mintable: the open reference declares the tag).',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Every list item here').click();"
    );
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('$address_chip'); const address = Drupal.Daedalus.Selection.getAddress(); const target = Drupal.Daedalus.Selection.getTarget();"
      . " const tier = window.__ft.codeHeaders($block_c_json, 'li'); const item = window.__ft.codeHeaders($block_c_json, $item_part_json);"
      . ' if (!chip || tier.length !== 1 || item.length !== 1) { return false; }'
      . " const body = tier[0].nextElementSibling; const editor = body?.querySelector('.cm-editor');"
      . ' return chip.dataset.daedalusStylishAddress === ' . json_encode("$block_c_key::li") . " && !document.querySelector('.daedalus-ui-menu')"
      . " && address?.key === $block_c_json && address?.selector === 'li'"
      . " && target?.key === $block_c_json && target?.selector === $item_part_json"
      . " && window.__ft.lit(tier[0]) && !tier[0].classList.contains('daedalus-stylish-islands__part--rule') && !window.__ft.lit(item[0]) && window.__ft.litHeaders() === 1"
      . " && body?.classList.contains('daedalus-stylish-islands__part-body') && !!editor && editor.contains(document.activeElement)"
      . " && document.querySelectorAll('.daedalus-stylish-page-tree .cm-editor').length === 1"
      . ' && ' . $map . '[' . json_encode("$block_c_key::li") . '] === undefined; })()',
      20000,
      'Mintable own-key address in code mode: "Every list item here" seated {block C}::li as the write address (the chip reads it, the facet stays the item) and the view drafted the `li` PART row with the cursor: its header lit, its body holding the one live editor with focus, the item row dark, and the map still without the `li` tier (a draft is view state until a write).',
    );
  }

  /**
   * The op log wrapper around Drupal.Daedalus.Blueprint.mutate.
   */
  private const OP_LOG = 'Drupal.Daedalus.Blueprint.__daedalusStylishOpLog';

  /**
   * Starts (or restarts) the op log for the "in one op" claims.
   *
   * The leader source dispatches every op through
   * Drupal.Daedalus.Blueprint.mutate, looked up at call time, so a
   * wrapper installed on the page records each dispatch's operations
   * without touching the source. Installed once per page load, cleared
   * on every call (BasicStylingBehaviorTest's recorder).
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
   * One dispatch of one op, carrying exactly the given properties.
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
   * Types into a panel input through the native value setter plus input.
   *
   * Preact's controlled inputs read the event; the prototype setter keeps
   * the framework's value tracker honest (BasicStylingBehaviorTest's).
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

}
