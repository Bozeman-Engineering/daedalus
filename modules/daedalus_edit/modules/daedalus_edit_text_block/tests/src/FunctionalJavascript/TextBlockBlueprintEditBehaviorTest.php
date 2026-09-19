<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit_text_block\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the text block's blueprint inline editing on a real page-built page.
 *
 * The text block is the first blueprint-edited leaf: its three fields (text,
 * tag, URL) render as ONE composed element (link wraps tag wraps text) that
 * is edited in place and committed through the blueprint mutation spine, no
 * entity form involved. daedalus_edit's InlineEditBehaviorTest covers the form
 * path; this test covers the blueprint path's defining behaviors on a real
 * page:
 * - The collapsed composed render: the block IS its composed root — ALWAYS
 *   the tag element, with the anchor nested inside it when linked
 *   (tag > a, the anchor itself carrying the typing surface: editing
 *   attributes ride the declared elements, no editing-only span) so
 *   daedalus_stylish's block-scoped styles land on the element the theme's own tag
 *   rules target — directly inside the container root (one box, no part
 *   element) with no block sandwich, carrying the selectable key, the
 *   component uuid, and
 *   the identity-only editor attribution (data-daedalus-edit-editor + settings)
 *   on that one element.
 * - The anchor part: linked, the anchor carries the stable text-block-link
 *   class from BOTH producers of the linked shape (the generative render
 *   and the client-side link preview, one resolver grammar shared through
 *   its client twin); unlinked, no element carries it — the
 *   conditional-part honesty. The shipped Button variant, worn through the
 *   ordinary apply_variant op, must style the ANCHOR (background, display,
 *   color, no underline) via its part rule in the key-rooted edit-mode
 *   style emission, preview included, stay inert while unlinked, and
 *   return with the anchor on relink.
 * - Part-presence gating, on a second linked block after the draft steps'
 *   removal: element projections (the page-elements MARKUP view row, the
 *   code view's `.` completion offer) follow the anchor's DOM presence
 *   (linked they offer the Link part, unlinked both hide it) while the
 *   store projection (the code-view row materialized from the
 *   part's stored letter-spacing) persists unconditionally as the one home
 *   for the orphaned style; relinking remounts the row, re-offers the
 *   completion, and the stored style lands back on the fresh anchor. The
 *   seams are pure wiring: the markup view recomposes off a canvas
 *   MutationObserver, the completion reads live presence facts at
 *   keystroke time, and only a browser can watch all three surfaces agree
 *   across one link toggle.
 * - Links are selection surfaces, not navigation: with the edit session
 *   armed, a real click on the rendered anchor must open the leaf's edit
 *   instead of leaving the page (the session's capture-phase click handler
 *   preventDefaults anything resolving to an editable leaf).
 * - The commit grammar: type into the contenteditable surface, blur, and
 *   exactly ONE update_element op rides the queue; the response replaces
 *   the mirror and swaps the canvas markup; the change is staged in
 *   tempstore, never committed to the saved node.
 * - The edit's widget verbs: the Tag entry (an edit-scoped widget verb on
 *   the selection chip's face, wearing a text label at full density) opens
 *   the tag picker popover, and a tag-button mousedown previews the tag
 *   swap in place without ending the edit; Escape restores the mirror
 *   snapshot (previewed wraps included) and sends nothing. Cmd+K is the
 *   shared Link verb's typing-context hotkey through the registry, and
 *   Tab out of the link input commits through the session.
 * - The link round trip, including from EMPTY: an empty field serializes
 *   into the blueprint as a form-only stub, not a value, and the server
 *   treats a stub arriving in a write as "leave this field alone" — so the
 *   editor must snapshot a stub as null, or linking an unlinked block
 *   spreads the stub's marker into the sent value and the write silently
 *   no-ops (the link "disappears" on the swap).
 * - The link autocomplete: the link widget's input shows the spine's
 * displayable
 *   grammar (never storage uris), typing a title fragment fans out to
 *   core's real entity autocomplete endpoint (the key minted into the
 *   drupalSettings payload must resolve server-side — that response is the
 *   seam under test), and picking a suggestion commits the structural
 *   `entity:TYPE/ID` uri whose swap prints the resolved url.
 * - The open edit is a DRAFT (the three-way rule): a foreign mutation's
 *   response that replaces the edited subtree while the edit is open swaps
 *   the editing surface out from under the typing, and the edit resumes
 *   over the fresh markup by property: base = the mirror snapshot at edit
 *   open, draft = what the surface held at the swap, remote = the mirror
 *   after the response. A style scrub through the spine (update_style on
 *   the block key, the reported bug) leaves the text alone (base ==
 *   remote), so the typed draft is re-projected and blur commits it as
 *   ONE op with the style intact; a foreign update_element on the text
 *   (remote != base) wins, the surface shows the server text, the
 *   snapshot re-baselines to it, and nothing commits. A swap that removes
 *   the component drops the edit without committing.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site, as
 * daedalus_editor); the recipe's text block ingredient installs this
 * module and imports the text block type from its config/install. One
 * fixture, one scenario: a full site install from the recipe is by far the
 * most expensive part of a run, so all behavior lives in the single test
 * method. There is no JS unit-test runner in this repo, so JS-level behavior
 * is asserted through assertJsCondition against the live app.
 *
 * @group daedalus_edit
 * @group daedalus_edit_text_block
 */
#[Group('daedalus_edit')]
#[Group('daedalus_edit_text_block')]
#[RunTestsInSeparateProcesses]
class TextBlockBlueprintEditBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait;

  /**
   * A text block edits in place through the blueprint mutation spine.
   */
  public function testTextBlockBlueprintEditing(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // A real Layout Builder page, the surface blueprint editing operates on.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Text block behavior page',
    ]);
    $node->save();
    $nid = (int) $node->id();

    // Enter Edit Mode the way the recipe starts a real page: the
    // server-rendered cookie path, which renders the full Edit Mode chrome
    // (every tool's JS, the blueprint mirror boot).
    $this->drupalGet($node->toUrl());
    $session->resizeWindow(1200, 1600);
    $this->enterEditModeViaCookies($node->toUrl(), 'selector');
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted.',
    );

    // Place a text block with known values through the same mutation batch a
    // palette drop enqueues (add_section + add_element). Client-named UUIDs
    // let the test wait on the rendered element without racing the response.
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
              block_type: 'text',
              properties: {
                field_text_text: 'Hello from the text block',
                field_text_tag: 'h3',
                field_text_url: { uri: 'https://example.com/' },
              },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__placementError = error.message;
        });
      })();
      JS);

    // A loud placement: the mirror carrying the component is the "response
    // applied" signal; a grammar or validation failure surfaces as the
    // recorded error instead of a blind timeout.
    $this->assertJsCondition(
      "window.__placementError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$component_uuid')",
      20000,
      'The text block placement resolved.',
    );
    $placement_error = $session->evaluateScript('window.__placementError ?? null');
    $this->assertNull($placement_error, 'Text block placement failed: ' . ($placement_error ?? ''));

    // The collapsed composed render: the block IS its composed root — the
    // tag element, the anchor nested inside it when linked and carrying
    // the typing surface itself (editing attributes ride the declared
    // elements; no editing-only span) — carrying the selectable key, the
    // component uuid, and the editor attribution on ONE element, directly
    // inside the container ROOT (the one-box container: no part element,
    // no block sandwich; regions have no markup or keys under the
    // container model). The generative render is the stamp, so this same
    // shape re-appears after every mutation swap.
    $wrapper = "[data-selectable='block:$component_uuid']";
    $leaf = "{$wrapper}[data-daedalus-edit-editor='text_composite']";
    $composed = $assert_session->waitForElement(
      'css',
      "h3{$leaf} > a[href='https://example.com/'][data-daedalus-edit-text]",
    );
    $this->assertNotNull($composed, 'The text block rendered collapsed: the attributed tag root wraps the anchor, which is the text surface.');
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const root = document.querySelector("$leaf");
          return root.parentElement.matches("[data-selectable='container:$section_uuid']")
            && root.closest('[data-layout-builder-block-uuid]') === root;
        })()
        JS),
      'The collapsed root is the container root\'s direct child and nothing above it carries the block identity.',
    );

    // The anchor is the block's declared conditional part: the generative
    // render stamps the stable text-block-link class on it (the part key
    // IS the class), the target of every
    // part style and of the shipped Button variant. This is producer ONE of
    // the linked shape (the server render); producer TWO (the client-side
    // link preview) is asserted in the relink step below.
    $this->assertJsCondition(
      "document.querySelector(\"$leaf > a\").classList.contains('text-block-link')",
      20000,
      'The server-rendered anchor carries the text-block-link part class.',
    );

    // Wear the shipped Button variant through the ordinary apply_variant
    // op. The seam only a browser can see: the op writes the style map, the
    // reference resolves key-rooted into the entity's authoritative edit
    // -mode style tag (edit mode stamps no variant class — that is the
    // LIVE delivery), and the part rule must actually land background/
    // display/padding ON the anchor — the styles theme `a` element rules
    // would beat anywhere else. This also proves the shipped variants file
    // imported on the recipe's install lane: apply_variant fails loudly
    // here if the button variant entity does not exist.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'apply_variant',
            key: 'block:$component_uuid',
            variant: 'button',
          }],
        })).catch((error) => {
          window.__variantError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__variantError !== undefined"
      . " || getComputedStyle(document.querySelector(\"$leaf > a.text-block-link\")).backgroundColor === 'rgb(26, 26, 46)'",
      20000,
      'The button variant application resolved.',
    );
    $variant_error = $session->evaluateScript('window.__variantError ?? null');
    $this->assertNull($variant_error, 'Applying the button variant failed: ' . ($variant_error ?? ''));
    $this->assertJsCondition(
      <<<JS
      (() => {
        const anchor = document.querySelector("$leaf > a.text-block-link");
        if (!anchor) {
          return false;
        }
        const style = getComputedStyle(anchor);
        return style.backgroundColor === 'rgb(26, 26, 46)'
          && style.display === 'inline-block'
          && style.color === 'rgb(255, 255, 255)'
          && style.textDecorationLine === 'none';
      })()
      JS,
      20000,
      'The worn button variant styles the ANCHOR: background, display, color, and no underline landed on the part.',
    );

    // Arm the edit session.
    $this->activateTool('edit');

    // A navigation sentinel: any page navigation produces a fresh JS context
    // without this property, so its presence proves the click stayed on the
    // page. The counter proxies window.fetch to count blueprint POSTs, the
    // "exactly one op per gesture" half of the commit grammar.
    $session->executeScript(<<<JS
      window.__notNavigated = true;
      window.__blueprintPosts = 0;
      const originalFetch = window.fetch;
      window.fetch = function (url, options) {
        if (String(url).includes('daedalus-blueprint') && options && options.method === 'POST') {
          window.__blueprintPosts++;
        }
        return originalFetch.apply(this, arguments);
      };
      JS);

    // The headline anchor check: a REAL pointer click on the rendered link
    // (the anchor inside the root). The armed session's capture-phase click
    // handler resolves it to the editable leaf and prevents its default, so
    // the click must open the edit instead of navigating to example.com.
    $session->executeScript("document.querySelector(\"$leaf\").scrollIntoView({ block: 'center' });");
    $session->getPage()->find('css', "$leaf a")->click();
    $this->assertJsCondition(
      <<<JS
      (() => {
        const text = document.querySelector("$leaf [data-daedalus-edit-text]");
        return window.__notNavigated === true
          && Drupal.Daedalus.Edit.CurrentlyEditingElement !== null
          && !!text && text.isContentEditable
          && !!document.querySelector("[data-daedalus-edit-toggle='tags']");
      })()
      JS,
      20000,
      'Clicking the rendered link did not navigate; it opened the in-place edit with its widget verbs.',
    );

    // The edit's controls ride the chip's verb face as widget verbs: while
    // the armed frame wears --editing, the primary chip renders the Tag and
    // Link entries in widget slots, and at FULL density (the default) the
    // Tag entry carries a TEXT label — the seam is the frame renderer's
    // density ladder now reaching daedalus_edit's entries, which the old
    // imperative toggles never did.
    $this->assertJsCondition(
      <<<JS
      (() => {
        const chip = document.querySelector(".daedalus-ui-frame--editing .daedalus-ui-frame__chip--primary");
        const tagLabel = chip?.querySelector(".daedalus-ui-frame__widget [data-daedalus-edit-toggle='tags'] .daedalus-ui-frame__label");
        return !!tagLabel && tagLabel.textContent === "Tag"
          && !!chip.querySelector(".daedalus-ui-frame__widget [data-daedalus-edit-toggle='link']");
      })()
      JS,
      10000,
      "The edit's widget verbs render on the primary chip, the Tag entry labeled at full density.",
    );

    // Type and blur: the gesture commits as exactly ONE update_element op.
    // The mirror carrying the new value is the deterministic "response
    // applied" signal (the same response swaps the canvas markup).
    $session->executeScript(<<<JS
      document.querySelector("$leaf [data-daedalus-edit-text]").textContent = 'Edited on the canvas';
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
      JS);
    $this->assertJsCondition(
      "JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('Edited on the canvas')",
      20000,
      'The blur commit reached the blueprint mirror.',
    );
    $this->assertJsCondition(
      "window.__blueprintPosts === 1"
      . " && document.querySelector(\"$leaf [data-daedalus-edit-text]\")?.textContent === 'Edited on the canvas'",
      20000,
      'The edit gesture sent exactly one mutation and the swap re-rendered the leaf.',
    );

    // Staged, not committed: the whole layout (placement and edit alike)
    // lives in tempstore; the saved node still has no layout override.
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    $this->assertTrue(
      $storage->load($nid)->get('layout_builder__layout')->isEmpty(),
      'The text block edit is staged in tempstore, not committed to the node.',
    );

    // The tag picker: it lives behind the Tag entry's </> toggle on the
    // chip. The toggle acts on CLICK like every face button (its mousedown
    // only preventDefaults, keeping the caret in the text), while the tag
    // buttons inside the popover activate on mousedown so a pick keeps the
    // caret too. The pick previews the tag swap in place and the edit
    // stays open through it.
    $session->getPage()->find('css', "$leaf [data-daedalus-edit-text]")->click();
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null && !!document.querySelector(\"[data-daedalus-edit-toggle='tags']\")",
      20000,
      'Re-clicking the leaf re-opened its edit.',
    );
    $session->executeScript(<<<JS
      document.querySelector("[data-daedalus-edit-toggle='tags']").click();
      JS);
    $this->assertJsCondition(
      "!document.querySelector(\"[data-daedalus-edit-popover='tags']\").hidden",
      20000,
      'The </> toggle opened the tag picker popover.',
    );
    // The picker offers the whole shipped ladder, h1 included: a text
    // block may be the page's one h1.
    $this->assertJsCondition(
      "!!document.querySelector(\"[data-daedalus-edit-popover='tags'] button[data-tag='h1']\")",
      20000,
      'The tag picker offers h1.',
    );
    $session->executeScript(<<<JS
      document.querySelector("[data-daedalus-edit-popover='tags'] button[data-tag='h2']")
        .dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
      JS);
    // The tag switch rides the resolver twin: the previewed bag resolves
    // to the h2 tree and the morph retags the root in place, the nested
    // anchor moving over as the same node — which is also what keeps the
    // contenteditable surface (and the caret's text node) alive through
    // the swap, a wiring fact only a browser can see.
    $this->assertJsCondition(
      <<<JS
      (() => {
        const anchor = document.querySelector("h2{$leaf} > a[data-daedalus-edit-text]");
        return !!anchor && anchor.isContentEditable
          && Drupal.Daedalus.Edit.CurrentlyEditingElement !== null;
      })()
      JS,
      20000,
      'The tag picker previewed h3 to h2 in place (root retagged, nested anchor kept as the live surface) without ending the edit.',
    );

    // The open picker is a roving-focus group: arrows/Tab cycle its
    // buttons, and Escape from INSIDE it closes only the popover — the
    // popover's own handler stops the event before the document-level
    // hotkey dispatcher, so the edit survives. Real presses bubble from the
    // focused button, so the events dispatch there, not on document.
    $session->executeScript(<<<JS
      document.querySelector("[data-daedalus-edit-popover='tags'] button[data-tag='h2']").focus();
      document.activeElement.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true, cancelable: true }));
      JS);
    $this->assertJsCondition(
      "document.activeElement.dataset.tag === 'h3'",
      20000,
      'ArrowRight moved the roving focus to the next tag button.',
    );
    $session->executeScript(<<<JS
      document.activeElement.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        return document.querySelector("[data-daedalus-edit-popover='tags']").hidden
          && Drupal.Daedalus.Edit.CurrentlyEditingElement !== null
          && document.activeElement === document.querySelector("$leaf [data-daedalus-edit-text]");
      })()
      JS,
      20000,
      'Escape inside the picker closed only the popover and returned focus to the text.',
    );

    // Escape restores the mirror snapshot, previewed wraps included, and
    // sends nothing: the h3 returns and the mutation count is unchanged.
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      <<<JS
      (() => {
        return Drupal.Daedalus.Edit.CurrentlyEditingElement === null
          && !!document.querySelector("h3{$leaf} > a[data-daedalus-edit-text]")
          && window.__blueprintPosts === 1;
      })()
      JS,
      20000,
      'Escape restored the snapshot (h3 back) and sent no mutation.',
    );

    // The link round trip. Unlink first: clear the link input. The client
    // preview resolves the unlinked tree through the resolver twin and the
    // morph unmounts the anchor BEFORE any commit — the text and the
    // typing-surface markers (data-daedalus-edit-text, contenteditable) follow
    // the mount back onto the root per the mounting rule, so the user
    // keeps typing through the unlink. The seam is pure wiring (twin
    // resolve, DOM morph, marker migration) only a browser can see.
    $session->getPage()->find('css', "$leaf [data-daedalus-edit-text]")->click();
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null && !!document.querySelector(\".daedalus-edit-link-input\")",
      20000,
      'Re-clicking the leaf opened the edit for the unlink gesture.',
    );
    $session->executeScript(<<<JS
      (() => {
        const input = document.querySelector(".daedalus-edit-link-input");
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
      })();
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        return !!root && root.matches("h3[data-daedalus-edit-text][contenteditable='true']")
          && !root.querySelector('a')
          && root.textContent === 'Edited on the canvas'
          && Drupal.Daedalus.Edit.CurrentlyEditingElement !== null;
      })()
      JS,
      20000,
      'The unlink preview unmounted the anchor and re-seated the typing surface on the root, text intact, before any commit.',
    );
    // Blur commits: the swap re-renders the root as the bare tag element.
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        return window.__blueprintPosts === 2
          && !!root && root.matches("h3[data-daedalus-edit-text]")
          && !JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('example.com');
      })()
      JS,
      20000,
      'The unlink committed and the swap re-rendered the bare tag root.',
    );

    // The conditional-part honesty, browser-verified: unlinked, no element
    // carries the part class, and the still-worn button variant is INERT —
    // its part-scoped rule reaches nothing, and in particular does not leak
    // onto the root.
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        return !document.querySelector("$leaf .text-block-link")
          && getComputedStyle(root).backgroundColor !== 'rgb(26, 26, 46)';
      })()
      JS,
      20000,
      'Unlinked, the part is honestly absent and the worn button variant is inert (no leak onto the root).',
    );

    // Relink from EMPTY, the stub trap: the unlinked field now sits in the
    // mirror as a form-only stub, and the fresh edit's snapshot must read
    // it as null — a stub kept in the snapshot gets spread into the sent
    // value, which the server treats as "leave this field alone", silently
    // dropping the link the user just typed.
    $session->getPage()->find('css', "$leaf")->click();
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null && !!document.querySelector(\".daedalus-edit-link-input\")",
      20000,
      'Re-clicking the leaf opened the edit for the relink gesture.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement.snapshot.field_text_url === null",
      20000,
      'The empty link field snapshots as null, not as the mirror\'s form-only stub.',
    );
    // Cmd+K opens the link widget mid-edit: the shared Link verb's
    // TYPING-CONTEXT hotkey through the verb registry, dispatched from
    // the contenteditable surface so the typing context is live; the
    // popover opens with its input focused. (The binding string is
    // 'mod+k'; the driven browser is not a Mac, so mod resolves to ctrl.)
    // Unlinked, the ROOT is the typing surface (no descendant carries the
    // marker), so the keydown dispatches on the leaf itself.
    $session->executeScript(<<<JS
      document.querySelector("$leaf").dispatchEvent(
        new KeyboardEvent('keydown', { key: 'k', ctrlKey: true, bubbles: true, cancelable: true }));
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        const input = document.querySelector(".daedalus-edit-link-input");
        return !document.querySelector("[data-daedalus-edit-popover='link']").hidden
          && !!input && document.activeElement === input
          && input.value === '';
      })()
      JS,
      20000,
      'Cmd+K opened the link widget mid-edit with its input focused (empty: the block is unlinked).',
    );
    $session->executeScript(<<<JS
      (() => {
        const input = document.querySelector(".daedalus-edit-link-input");
        input.value = 'https://drupal.org/';
        input.dispatchEvent(new Event('input', { bubbles: true }));
      })();
      JS);
    // Producer TWO of the linked shape: the client-side link preview
    // resolves the linked tree through the resolver twin and morphs the
    // anchor into existence — the same declaration grammar the server
    // render consumes, so the part class and the href land by model, and
    // the editing attributes ride the DECLARED anchor (decision: no
    // editing-only elements; the client-preview span is dead). The
    // attributes-on-anchor seam: the anchor itself carries
    // data-daedalus-edit-text and stays the live contenteditable surface, and
    // only a browser can vouch that contenteditable-in-anchor works (the
    // exception path decision 7 reserves was not needed).
    $this->assertJsCondition(
      <<<JS
      (() => {
        const anchor = document.querySelector("$leaf > a.text-block-link[href='https://drupal.org/']");
        return !!anchor
          && anchor.matches("[data-daedalus-edit-text][contenteditable='true']")
          && anchor.isContentEditable
          && !anchor.querySelector('span')
          && !document.querySelector("$leaf").matches('[data-daedalus-edit-text]');
      })()
      JS,
      20000,
      'The link preview mounted the declared anchor with the part class, the typed href, and the typing surface riding the anchor itself (no span).',
    );
    $this->assertJsCondition(
      "getComputedStyle(document.querySelector(\"$leaf > a.text-block-link\")).backgroundColor === 'rgb(26, 26, 46)'",
      20000,
      'The worn button variant styled the PREVIEWED anchor before any commit.',
    );
    // Tab out commits: the link input is not one of the editor's edge
    // inputs, so the keydown falls through the popover to the session's
    // Tab grammar, which commits the gesture — "type the link, Tab, move
    // on".
    $session->executeScript(<<<JS
      document.querySelector(".daedalus-edit-link-input").dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        return window.__blueprintPosts === 3
          && !!document.querySelector("h3{$leaf} > a[href='https://drupal.org/'][data-daedalus-edit-text]")
          && JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('https://drupal.org/');
      })()
      JS,
      20000,
      'Tab out of the link input committed the typed uri and the swap re-rendered the nested anchor.',
    );
    // The wearing survived the whole link round trip: the server-rendered
    // relinked anchor carries the part class and the button styling again.
    $this->assertJsCondition(
      "(() => { const anchor = document.querySelector(\"$leaf > a.text-block-link\");"
      . " return !!anchor && getComputedStyle(anchor).backgroundColor === 'rgb(26, 26, 46)'; })()",
      20000,
      'Relinked, the part class and the worn button styling returned with the anchor.',
    );

    // The open edit is a DRAFT, half one: a foreign STYLE mutation. With
    // the edit open and a local draft typed on the surface, a font-size
    // scrub through the spine (update_style on the block key, the reported
    // bug: the Daedalus Stylish inspector styles WHILE editing) swaps the edited
    // markup out from under the typing. The seam only a browser can see:
    // the op queue's swap detaches the subtree (the engine captures the
    // draft there), the mirror is replaced, and once the response is fully
    // applied the edit resumes over the fresh markup by the three-way
    // rule. The style touched nothing the edit holds (base == remote for
    // the text), so the draft is re-projected: same current edit, the
    // local text back on a rebuilt surface (contenteditable, editing
    // class, the chip's widgets standing), the snapshot still the remote
    // text, and the scrubbed font-size landed on the block.
    $session->getPage()->find('css', "$leaf [data-daedalus-edit-text]")->click();
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement !== null',
      20000,
      'Re-clicking the leaf re-opened its edit for the draft steps.',
    );
    $session->executeScript(<<<JS
      (() => {
        window.__draftEdit = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        const text = document.querySelector("$leaf [data-daedalus-edit-text]");
        text.textContent = 'Local draft';
        text.dispatchEvent(new Event('input', { bubbles: true }));
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_style',
            key: 'block:$component_uuid',
            properties: { 'font-size': '2rem' },
          }],
        })).catch((error) => {
          window.__styleScrubError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        if (window.__styleScrubError !== undefined) {
          return true;
        }
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        const root = document.querySelector("$leaf");
        const text = document.querySelector("$leaf [data-daedalus-edit-text]");
        return !!editing && editing === window.__draftEdit && !!root && !!text
          && getComputedStyle(root).fontSize === '32px'
          && text.textContent === 'Local draft'
          && text.isContentEditable
          && root.classList.contains('daedalus-edit-blueprint-editing')
          && !!document.querySelector("[data-daedalus-edit-toggle='tags']")
          && editing.snapshot.field_text_text === 'Edited on the canvas'
          && window.__blueprintPosts === 4;
      })()
      JS,
      20000,
      'The style scrub swapped the markup and the draft resumed over it: local text on the rebuilt surface, the remote snapshot, the font-size landed.',
    );
    $style_scrub_error = $session->evaluateScript('window.__styleScrubError ?? null');
    $this->assertNull($style_scrub_error, 'The mid-edit style scrub failed: ' . ($style_scrub_error ?? ''));

    // Blur commits the draft: exactly ONE more op, the mirror carries the
    // typed text, and the scrubbed style rides the re-render (both
    // survived, the bug's whole complaint).
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        const text = document.querySelector("$leaf [data-daedalus-edit-text]");
        return window.__blueprintPosts === 5
          && Drupal.Daedalus.Edit.CurrentlyEditingElement === null
          && JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('Local draft')
          && !!text && text.textContent === 'Local draft'
          && !!root && getComputedStyle(root).fontSize === '32px';
      })()
      JS,
      20000,
      'Blur committed the resumed draft as one op, and the mirror carries the text beside the scrubbed style.',
    );

    // Half two: a foreign mutation on the VERY property being edited (a
    // previous commit landing during a re-edit, an undo, an AI turn).
    // Remote != base for the text, so the remote wins: the surface shows
    // the server text, the snapshot re-baselines to it (Escape would now
    // restore server truth), the edit stays open, and nothing commits (the
    // count grows by the foreign op alone).
    $session->getPage()->find('css', "$leaf [data-daedalus-edit-text]")->click();
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement !== null',
      20000,
      'Re-clicking the leaf re-opened its edit for the remote-wins step.',
    );
    $session->executeScript(<<<JS
      (() => {
        window.__draftEdit = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        const text = document.querySelector("$leaf [data-daedalus-edit-text]");
        text.textContent = 'Superseded draft';
        text.dispatchEvent(new Event('input', { bubbles: true }));
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_element',
            element: '$component_uuid',
            properties: { field_text_text: 'Server swapped text' },
          }],
        })).catch((error) => {
          window.__remoteWinsError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        if (window.__remoteWinsError !== undefined) {
          return true;
        }
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        const text = document.querySelector("$leaf [data-daedalus-edit-text]");
        return !!editing && editing === window.__draftEdit && !!text
          && text.textContent === 'Server swapped text'
          && text.isContentEditable
          && editing.getPageElement().classList.contains('daedalus-edit-blueprint-editing')
          && editing.snapshot.field_text_text === 'Server swapped text'
          && window.__blueprintPosts === 6;
      })()
      JS,
      20000,
      'The foreign text change won over the draft: server text on the surface, the snapshot re-baselined, the edit open, no commit.',
    );
    $remote_wins_error = $session->evaluateScript('window.__remoteWinsError ?? null');
    $this->assertNull($remote_wins_error, 'The mid-edit foreign text change failed: ' . ($remote_wins_error ?? ''));

    // A swap that removes the component has nothing to resume: the edit is
    // dropped without committing (the mutation count grows only by the
    // remove itself; a spurious blur commit would add another).
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{ op: 'remove_element', element: '$component_uuid' }],
        })).catch((error) => {
          console.error('Mid-edit removal failed: ' + error.message);
        });
      })();
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        return document.querySelector("$wrapper") === null
          && Drupal.Daedalus.Edit.CurrentlyEditingElement === null
          && window.__blueprintPosts === 7;
      })()
      JS,
      20000,
      'Removing the edited component dropped the edit without a commit.',
    );

    // ——— Phase 2: part gating across the three surfaces. ———
    // Element projections project the PAGE, each by its own question: the
    // `.` completion offer gates on the anchor's DOM presence, the
    // markup-view row on placement-as-own-element (the absent anchor
    // answers no to both); store projections (a code-view row
    // materialized from stored declarations) project the STYLE MAP and
    // persist unconditionally. The exact-count
    // gesture grammar ended above, so the ops from here are scenario
    // plumbing and the counter is no longer asserted. A fresh linked
    // block replaces the removed one.
    $component2_uuid = $uuid_service->generate();
    $wrapper2 = "[data-selectable='block:$component2_uuid']";
    $leaf2 = "{$wrapper2}[data-daedalus-edit-editor='text_composite']";
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'add_element',
            uuid: '$component2_uuid',
            block_type: 'text',
            properties: {
              field_text_text: 'Presence gated text',
              field_text_tag: 'h3',
              field_text_url: { uri: 'https://example.com/' },
            },
            location: { anchor: '$section_uuid', placement: 'inside' },
          }],
        })).catch((error) => {
          window.__presencePlaceError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__presencePlaceError !== undefined"
      . " || !!document.querySelector(\"h3{$leaf2} > a.text-block-link\")",
      20000,
      'The presence-gating block placed and rendered linked.',
    );
    $presence_error = $session->evaluateScript('window.__presencePlaceError ?? null');
    $this->assertNull($presence_error, 'Placing the presence-gating block failed: ' . ($presence_error ?? ''));

    // Style the link part directly (no variant this time): the stored
    // declaration is what materializes the code-view row and what must
    // survive the unlink as an orphaned part style. Computed style, never
    // a class: edit mode delivers styles key-rooted in the entity's inline
    // style tag.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_style',
            key: 'block:$component2_uuid::.text-block-link',
            properties: { 'letter-spacing': '4px' },
          }],
        })).catch((error) => {
          window.__presenceStyleError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__presenceStyleError !== undefined"
      . " || getComputedStyle(document.querySelector(\"$leaf2 > a.text-block-link\")).letterSpacing === '4px'",
      20000,
      'The stored part style landed on the anchor.',
    );
    $presence_error = $session->evaluateScript('window.__presenceStyleError ?? null');
    $this->assertNull($presence_error, 'Styling the link part failed: ' . ($presence_error ?? ''));

    // Select the block on the spine and reveal the page-elements tree
    // with its Markup view through the Show menu's real entry (the view
    // slice seeds from localStorage only at boot, so the entry is the one
    // live doorway, the same wiring a user drives). The Show menu rides
    // the shared menu primitive: its entries are labelled
    // menuitemcheckbox buttons (.daedalus-ui-menu__item > .daedalus-ui-menu__label), and
    // activating one closes the menu before flipping the axis.
    $session->executeScript(<<<JS
      Drupal.Daedalus.Selection.select('block:$component2_uuid');
      Drupal.Daedalus.SidebarManager.openSidebar('page_elements');
      JS);
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-tree') && !!document.querySelector('.daedalus-ui-show-menu__button')",
      20000,
      'The page-elements tree mounted with its Show menu.',
    );
    $session->executeScript("document.querySelector('.daedalus-ui-show-menu__button').click();");
    $markup_entry = "Array.from(document.querySelectorAll('.daedalus-ui-menu .daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent === 'Markup')";
    $this->assertJsCondition(
      "!!$markup_entry",
      20000,
      'The Show menu opened with the Markup view toggle.',
    );
    $session->executeScript("$markup_entry.click();");

    // Linked: the markup view mounts the Link shell row (placement-gated
    // by compose through its live canvas channel: the anchor resolved as
    // its own element). Rows fold; the poll
    // unfolds any folded disclosure on the way, the keyboard-free twin of
    // a user expanding the tree.
    $link_row = "[...document.querySelectorAll('.daedalus-ui-tree__row--part .daedalus-ui-tree__label')].some((el) => el.textContent === 'Link')";
    $this->assertJsCondition(
      "(() => {"
      . " document.querySelectorAll(\".daedalus-ui-tree__disclosure[aria-expanded='false']\").forEach((b) => b.click());"
      . " return $link_row; })()",
      20000,
      'Linked: the markup view mounts the Link part row.',
    );

    // The code view: the styled part's materialized row (the store
    // projection) and the `.` completion (an element projection) in the
    // same surface.
    $part_row = "[data-daedalus-stylish-part-owner='block:$component2_uuid'][data-daedalus-stylish-part='.text-block-link']";
    $session->executeScript("Drupal.Daedalus.SidebarManager.openSidebar('daedalus_stylish_inspector');");
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-stylish-inspector__ui-mode-button--code')",
      20000,
      'The style inspector mounted.',
    );
    $session->executeScript("document.querySelector('.daedalus-stylish-inspector__ui-mode-button--code').click();");
    $this->assertJsCondition(
      "!!document.querySelector(\"$part_row\")",
      20000,
      'Code view: the styled link part has its materialized row.',
    );

    // Typing `.` in the selected block's root island offers the declared
    // Link part while its anchor is present. Islands print by default and
    // one live editor mounts on activation (island-surface.js), so the
    // gesture is two-beat: a pointerdown on the printed island wakes its
    // editor, then the insert goes through the browser's editing command,
    // the same beforeinput CodeMirror handles for a keypress. A gesture
    // token owns its line (gestureCompletions: nothing after the cursor),
    // and the root island is never empty (the tag's declared defaults and
    // the skin's floor lines print in it), so the `.` goes on a fresh line
    // at the island's end, the way a user presses Enter and types; the
    // cleanup removes exactly that token and its line (structure never
    // becomes characters; the typed `.` is view state, not a declaration)
    // and leaves the printed declarations alone.
    $island_root = "document.querySelector(\"[data-daedalus-stylish-page-key='block:$component2_uuid']\")"
      . "?.closest('.daedalus-stylish-page-tree__node')"
      . "?.querySelector('.daedalus-stylish-page-tree__islands > .daedalus-stylish-islands > .daedalus-stylish-island')";
    $root_island = "($island_root)?.querySelector('.cm-content')";
    $type_gesture = <<<JS
      (() => {
        const content = $root_island;
        content.focus();
        const range = document.createRange();
        range.selectNodeContents(content);
        range.collapse(false);
        const selection = document.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
        document.execCommand('insertText', false, '\\n.');
      })();
      JS;
    $gesture_typed = "($root_island)?.lastElementChild?.textContent === '.'";
    $clear_gesture = <<<JS
      (() => {
        const content = $root_island;
        content.focus();
        document.execCommand('delete');
        document.execCommand('delete');
        content.blur();
      })();
      JS;
    $wake_island = <<<JS
      (() => {
        const island = $island_root;
        const print = island.querySelector('.daedalus-stylish-island__print');
        if (!print) {
          return;
        }
        island.scrollIntoView({ block: 'center' });
        const rect = print.querySelector('.daedalus-stylish-island__line').getBoundingClientRect();
        print.dispatchEvent(new PointerEvent('pointerdown', {
          bubbles: true,
          cancelable: true,
          button: 0,
          clientX: rect.left + 1,
          clientY: rect.top + rect.height / 2,
        }));
      })();
      JS;
    $this->assertJsCondition(
      "!!($island_root)?.querySelector('.daedalus-stylish-island__print')",
      20000,
      'Code view: the selected block\'s root island printed.',
    );
    $session->executeScript($wake_island);
    $this->assertJsCondition(
      "!!($root_island)",
      20000,
      'Code view: the pointerdown on the print woke the block island\'s editor.',
    );
    $session->executeScript($type_gesture);
    $this->assertJsCondition(
      "[...document.querySelectorAll('.cm-tooltip-autocomplete .cm-completionLabel')]"
      . ".some((el) => el.textContent === '.text-block-link')",
      20000,
      'Linked: typing `.` in the block island offers the Link part.',
    );
    // Clean the gesture token back out so the unlinked probe below types
    // its own fresh line into the same island.
    $session->executeScript($clear_gesture);

    // Unlink through the op spine: the swap re-renders the bare tag root,
    // the part leaves the DOM, and the stored style goes honestly inert
    // (nothing leaks onto the root).
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_element',
            element: '$component2_uuid',
            properties: { field_text_url: null },
          }],
        })).catch((error) => {
          window.__presenceUnlinkError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__presenceUnlinkError !== undefined"
      . " || (() => { const root = document.querySelector(\"$leaf2\");"
      . " return !!root && root.matches('h3[data-daedalus-edit-text]')"
      . " && !document.querySelector(\"$leaf2 .text-block-link\")"
      . " && getComputedStyle(root).letterSpacing === 'normal'; })()",
      20000,
      'The unlink swap re-rendered the bare root: part absent, stored part style inert.',
    );
    $presence_error = $session->evaluateScript('window.__presenceUnlinkError ?? null');
    $this->assertNull($presence_error, 'Unlinking the presence-gating block failed: ' . ($presence_error ?? ''));

    // A frame plus a settle beat before the negatives: the tree's
    // recompose is MutationObserver-driven and a driven browser has been
    // seen to lag a frame (build notes). Each negative below pairs with a
    // positive read in the same pass so a stale surface cannot fake it.
    $session->executeScript(<<<JS
      window.__presenceSettled = false;
      requestAnimationFrame(() => {
        setTimeout(() => { window.__presenceSettled = true; }, 1000);
      });
      JS);
    $this->assertJsCondition('window.__presenceSettled === true', 20000, 'The recompose settle beat elapsed.');
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          document.querySelectorAll(".daedalus-ui-tree__disclosure[aria-expanded='false']").forEach((b) => b.click());
          const linkRow = $link_row;
          const storedRow = document.querySelector("$part_row");
          const storedLine = !!storedRow
            && storedRow.nextElementSibling.textContent.includes('letter-spacing');
          return !linkRow && storedLine;
        })()
        JS),
      'Unlinked: the markup-view Link row unmounted while the styled part\'s code-view row persists with its declaration.',
    );

    // The `.` completion no longer offers the absent part. The typed `.`
    // registering is the liveness proof for this negative. The cleanup
    // blur parked the island back to print (and the unlink recompose may
    // have re-printed it regardless), so wake it again before typing.
    $session->executeScript($wake_island);
    $this->assertJsCondition(
      "!!($root_island)",
      20000,
      'Unlinked probe: the re-woken block island editor mounted.',
    );
    $session->executeScript($type_gesture);
    $session->executeScript(<<<JS
      window.__completionSettled = false;
      setTimeout(() => { window.__completionSettled = true; }, 1000);
      JS);
    $this->assertJsCondition('window.__completionSettled === true', 20000, 'The completion settle beat elapsed.');
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const typed = $gesture_typed;
          const offered = [...document.querySelectorAll('.cm-tooltip-autocomplete .cm-completionLabel')]
            .some((el) => el.textContent === '.text-block-link');
          return typed && !offered;
        })()
        JS),
      'Unlinked: the typed `.` registered but the Link part is no longer offered.',
    );
    $session->executeScript($clear_gesture);

    // Relink: presence returns, the element projections reactivate with
    // no manual refresh (the observer's class vocabulary sees the anchor
    // arrive), and the stored style lands back on the fresh anchor.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_element',
            element: '$component2_uuid',
            properties: { field_text_url: { uri: 'https://drupal.org/' } },
          }],
        })).catch((error) => {
          window.__presenceRelinkError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__presenceRelinkError !== undefined"
      . " || (() => { const anchor = document.querySelector(\"h3{$leaf2} > a.text-block-link\");"
      . " return !!anchor && getComputedStyle(anchor).letterSpacing === '4px'; })()",
      20000,
      'Relinked: the part is back and its stored style applies to the anchor again.',
    );
    $presence_error = $session->evaluateScript('window.__presenceRelinkError ?? null');
    $this->assertNull($presence_error, 'Relinking the presence-gating block failed: ' . ($presence_error ?? ''));
    $this->assertJsCondition(
      "(() => {"
      . " document.querySelectorAll(\".daedalus-ui-tree__disclosure[aria-expanded='false']\").forEach((b) => b.click());"
      . " return $link_row; })()",
      20000,
      'Relinked: the markup view Link row remounted with no manual refresh.',
    );

    // ——— The link autocomplete cutover. The seam is core's REAL
    // autocomplete endpoint: the hmac key minted into the drupalSettings
    // payload must resolve in the entity_autocomplete keyValue store
    // server-side, and its JSON response feeds the dropdown — so the whole
    // chain (target-type default ['node'], key minting, payload delivery,
    // debounced fan-out, structural pick) runs with no mock. ———
    $session->executeScript("document.querySelector(\"$leaf2\").scrollIntoView({ block: 'center' });");
    $session->getPage()->find('css', "$leaf2 [data-daedalus-edit-text]")->click();
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null && !!document.querySelector(\".daedalus-edit-link-input\")",
      20000,
      'Re-clicking the leaf opened the edit for the autocomplete gesture.',
    );
    // Open the link popover through the Link entry's toggle (click, like
    // every face button); the input autofocuses showing the DISPLAYABLE
    // value — for an external uri that is the uri itself.
    $session->executeScript(<<<JS
      document.querySelector("[data-daedalus-edit-toggle='link']").click();
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        const input = document.querySelector(".daedalus-edit-link-input");
        return !document.querySelector("[data-daedalus-edit-popover='link']").hidden
          && !!input && input.value === 'https://drupal.org/';
      })()
      JS,
      20000,
      'The link popover opened showing the displayable value.',
    );
    // Type a fragment of this page's own title. The debounced fan-out hits
    // the minted core endpoint; the node arrives as a suggestion. The
    // typed fragment also previews RAW on the anchor (cosmetic; the spine
    // normalizes on write).
    $session->executeScript(<<<JS
      (() => {
        const input = document.querySelector(".daedalus-edit-link-input");
        input.value = 'behavior page';
        input.dispatchEvent(new Event('input', { bubbles: true }));
      })();
      JS);
    $this->assertJsCondition(
      "[...document.querySelectorAll('.daedalus-edit-link-suggestion')]"
      . ".some((el) => el.textContent.includes('Text block behavior page'))",
      20000,
      'Typing a title fragment fetched the node suggestion from the real endpoint.',
    );
    // Pick it (mousedown — focus and caret stay in the input): the pick
    // writes the STRUCTURAL storage uri onto the leaf, no "Label (id)"
    // parsing between the pick and the spine, and the input shows the
    // picked displayable.
    $session->executeScript(<<<JS
      [...document.querySelectorAll(".daedalus-edit-link-suggestion")]
        .find((el) => el.textContent.includes('Text block behavior page'))
        .dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        const input = document.querySelector(".daedalus-edit-link-input");
        const anchor = document.querySelector("$leaf2 > a");
        return input.value === 'Text block behavior page ($nid)'
          && document.querySelector(".daedalus-edit-link-suggestions").hidden
          && !!anchor && anchor.getAttribute('href') === 'entity:node/$nid';
      })()
      JS,
      20000,
      'The pick closed the dropdown, showed the displayable in the input, and previewed the structural entity uri.',
    );
    // Blur commits: the structural uri lands in the blueprint and the swap
    // prints the RESOLVED url on the anchor.
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition(
      <<<JS
      (() => {
        const anchor = document.querySelector("$leaf2 > a.text-block-link");
        return JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('entity:node/$nid')
          && !!anchor && (anchor.getAttribute('href') || '').endsWith('/node/$nid');
      })()
      JS,
      20000,
      'The pick committed entity:node/N and the swap rendered the resolved url.',
    );
    // The displayable round trip: a fresh edit's input shows the committed
    // entity uri as the spine's "Label (id)" enrichment, never the storage
    // uri.
    $session->getPage()->find('css', "$leaf2 [data-daedalus-edit-text]")->click();
    $this->assertJsCondition(
      "document.querySelector(\".daedalus-edit-link-input\")?.value"
      . " === 'Text block behavior page ($nid)'",
      20000,
      'A fresh edit shows the entity link as its displayable "Label (id)", not the storage uri.',
    );
  }

}
