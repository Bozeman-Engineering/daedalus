<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit_formatted_text_block\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the formatted text block's blueprint rich-text editing in place.
 *
 * The formatted text block is the rich-text leaf on the blueprint mutation
 * spine: one text_long field whose FILTERED output renders collapsed as a
 * single attributed div, edited in place by an inline CKEditor seeded with
 * the RAW {value, format} pair from the blueprint mirror, and committed as
 * ONE update_element op. No entity form is involved. This test covers the
 * behaviors that define the widget on a real page:
 * - The collapsed render contract: the block IS a single div directly inside
 *   the container root (one box, no part element; no block sandwich)
 *   carrying the selectable key, the
 *   component uuid, the editor attribution (data-daedalus-edit-editor), and the
 *   editor settings stamp (the gesture's format plus the accessible
 *   editor-backed formats; editor-less formats are excluded by design). The
 *   div's content is the format-filtered output, never the raw value.
 * - The commit grammar: open the edit (the root becomes the CKEditor
 *   surface and the snapshot re-baselines from editor.getData(), the
 *   normalization guard), change the data, blur, and exactly ONE
 *   update_element op rides the queue; the response's markup swap repaints
 *   the filtered output; the change stays staged in tempstore.
 * - The no-change restore: a blur with nothing changed sends nothing, and
 *   the saved filtered markup is restored byte-for-byte by hand, because no
 *   re-render happens (the edit showed the raw value, so a snapshot restore
 *   alone would leave raw markup on the canvas).
 * - Escape: the editor is destroyed, the filtered markup is restored, and
 *   nothing is sent.
 * - The format switch: the Text format widget's picker lists the accessible
 *   editor-backed formats, a pick rebuilds the CKEditor with the target
 *   format's configuration (through core's editor/filter_xss port), and
 *   blur commits the {value, format} bag; the swap re-stamps the settings
 *   with the stored format.
 * - The open edit is a DRAFT (the three-way rule): a foreign mutation's
 *   response that replaces the root while the edit is open swaps the
 *   CKEditor's element out from under the typing; the orphaned editor is
 *   destroyed and the edit resumes over the fresh root by property: base =
 *   the mirror snapshot at edit open, draft = the editor's {value, format}
 *   at the swap, remote = the mirror after the response. A style scrub
 *   through the spine (update_style on the block key, the reported bug)
 *   leaves the field alone (base == remote), so the draft is seeded into
 *   ONE fresh CKEditor and blur commits it as ONE op with the style
 *   intact; a foreign update_element on the field (remote != base) wins,
 *   the fresh editor shows the server value, the snapshot re-baselines to
 *   it, and blur commits nothing.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site, as
 * daedalus_editor); the recipe's formatted text block ingredient installs
 * this module and imports the block type from its config/install. One
 * fixture, one scenario: a full site install from the recipe is by far the
 * most expensive part of a run, so all behavior lives in the single test
 * method. There is no JS unit-test runner in this repo, so JS-level behavior
 * is asserted through assertJsCondition against the live app.
 *
 * @group daedalus_edit
 * @group daedalus_edit_formatted_text_block
 */
#[Group('daedalus_edit')]
#[Group('daedalus_edit_formatted_text_block')]
#[RunTestsInSeparateProcesses]
class FormattedTextBlockBlueprintEditBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * {@inheritdoc}
   *
   * The recipe ships ONE editor-backed format (daedalus_edit_formatted_text,
   * the format its content stamps) and grants the editor only that. The
   * format-switch legs
   * need what a real site's administrator adds on top: a second
   * editor-backed format to switch to, and an accessible editor-less format
   * the picker must exclude. Core's own recipes supply both (full_html with
   * its CKEditor, restricted_html with none), and the editor role is granted
   * them the way a site would: the persona stays the recipe's editor, with
   * the two site-added grants this scenario is about.
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
    $this->applyRecipe($this->root . '/core/recipes/full_html_format_editor');
    $this->applyRecipe($this->root . '/core/recipes/restricted_html_format');
    $editor = Role::load('daedalus_editor');
    $editor->grantPermission('use text format full_html');
    $editor->grantPermission('use text format restricted_html');
    $editor->save();
  }

  /**
   * A formatted text block edits in place through the blueprint spine.
   */
  public function testFormattedTextBlockBlueprintEditing(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // A real Layout Builder page, the surface blueprint editing operates on.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Formatted text block behavior page',
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

    // Place a formatted text block with a known RAW value through the same
    // mutation batch a palette drop enqueues. The raw value carries an
    // attribute daedalus_edit_formatted_text strips (onclick), so the
    // filtered-vs-raw halves of the render contract are distinguishable on
    // the canvas.
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
                  value: '<p onclick="window.__evil=1">Hello <em>rich</em> world</p>',
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

    // The collapsed render contract: the block IS one attributed div,
    // directly inside the container ROOT (the one-box container: no part
    // element between; regions have no markup or keys under the container
    // model), containing the FILTERED output. The template is the stamp,
    // so this same shape re-appears after every mutation swap.
    $wrapper = "[data-selectable='block:$component_uuid']";
    $leaf = "{$wrapper}[data-daedalus-edit-editor='formatted_text']";
    $composed = $assert_session->waitForElement('css', "div{$leaf} > p > em");
    $this->assertNotNull($composed, 'The formatted text block rendered collapsed: one attributed div holding the filtered output.');
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const root = document.querySelector("$leaf");
          return root.parentElement.matches("[data-selectable='container:$section_uuid']")
            && root.closest('[data-layout-builder-block-uuid]') === root
            && !root.querySelector('p').hasAttribute('onclick')
            && window.__evil === undefined;
        })()
        JS),
      'The collapsed root is the container root\'s direct child, nothing above it carries the block identity, and the canvas shows the filtered output (onclick stripped by daedalus_edit_formatted_text), not the raw value.',
    );

    // The editor settings stamp: the gesture's format plus every accessible
    // editor-backed format. restricted_html is accessible to the editor but
    // has no editor, so it must be excluded (editor-less formats are out of
    // this widget by design).
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const settings = JSON.parse(document.querySelector("$leaf").dataset.daedalusEditEditorSettings);
          return settings.format === 'daedalus_edit_formatted_text'
            && 'daedalus_edit_formatted_text' in settings.formats
            && 'full_html' in settings.formats
            && !('restricted_html' in settings.formats);
        })()
        JS),
      'The settings stamp carries the stored format and only the accessible editor-backed formats.',
    );

    // Arm the edit session and count blueprint POSTs: the "exactly one op
    // per gesture" half of the commit grammar (the filter_xss round trip of
    // a format switch is not a blueprint mutation and must not count).
    $this->activateTool('edit');
    $session->executeScript(<<<JS
      window.__blueprintPosts = 0;
      const originalFetch = window.fetch;
      window.fetch = function (url, options) {
        if (String(url).includes('daedalus-blueprint') && options && options.method === 'POST') {
          window.__blueprintPosts++;
        }
        return originalFetch.apply(this, arguments);
      };
      JS);

    // The open-edit signal, shared by every gesture below: the leaf is the
    // current edit, the root itself is the CKEditor surface, the Text
    // format widget's toggle is on the chip, and the snapshot has re-baselined
    // from
    // editor.getData() (CKEditor normalizes markup, so the mirror's raw
    // value would diff dirty on an untouched gesture; the re-baseline is
    // what makes the no-change contract testable at all).
    $editor_ready = <<<JS
      (() => {
        const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        if (!current || !current.ckeditorId) {
          return false;
        }
        const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.get(current.ckeditorId);
        const root = document.querySelector("$leaf");
        return !!editor && !!root && root.isContentEditable
          && !!document.querySelector("[data-daedalus-edit-toggle='formats']")
          && !!current.snapshot
          && current.snapshot.field_formatted_text_text.value === editor.getData();
      })()
      JS;

    // No-change restore: open the edit (the canvas now shows CKEditor's
    // rendering of the RAW value) and blur without touching anything.
    // Nothing is sent, and because no re-render is coming, the editor must
    // put the saved filtered markup back by hand, byte for byte.
    $session->executeScript(<<<JS
      window.__filtered = document.querySelector("$leaf").innerHTML;
      document.querySelector("$leaf").scrollIntoView({ block: 'center' });
      JS);
    $session->getPage()->find('css', "$leaf p")->click();
    $this->assertJsCondition($editor_ready, 20000, 'Clicking the leaf opened the in-place rich text edit.');
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        return Drupal.Daedalus.Edit.CurrentlyEditingElement === null
          && window.__blueprintPosts === 0
          && root.innerHTML === window.__filtered
          && !root.isContentEditable
          && !root.classList.contains('daedalus-edit-inline-editing')
          && !root.classList.contains('daedalus-edit-blueprint-editing')
          && Drupal.Daedalus.Edit.Ckeditor5Instances.size === 0;
      })()
      JS,
      20000,
      'The no-change blur sent nothing and restored the filtered markup byte for byte.',
    );

    // The commit grammar: change the editor's data, blur, and exactly ONE
    // update_element op rides the queue. The mirror carrying the new value
    // is the deterministic "response applied" signal; the same response
    // swaps the canvas markup to the new FILTERED output.
    $session->getPage()->find('css', "$leaf p")->click();
    $this->assertJsCondition($editor_ready, 20000, 'Re-clicking the leaf opened the edit for the commit gesture.');
    $session->executeScript(<<<JS
      (() => {
        const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        Drupal.Daedalus.Edit.Ckeditor5Instances.get(current.ckeditorId).setData('<p>Edited rich text canvas</p>');
        current.plugin.blur();
      })();
      JS);
    $this->assertJsCondition(
      "JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('Edited rich text canvas')",
      20000,
      'The blur commit reached the blueprint mirror.',
    );
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        return window.__blueprintPosts === 1
          && root.querySelector('p')?.textContent === 'Edited rich text canvas'
          && !root.isContentEditable
          && Drupal.Daedalus.Edit.CurrentlyEditingElement === null
          && Drupal.Daedalus.Edit.Ckeditor5Instances.size === 0;
      })()
      JS,
      20000,
      'The edit gesture sent exactly one mutation and the swap repainted the filtered output.',
    );

    // Staged, not committed: the whole layout (placement and edit alike)
    // lives in tempstore; the saved node still has no layout override.
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    $this->assertTrue(
      $storage->load($nid)->get('layout_builder__layout')->isEmpty(),
      'The formatted text edit is staged in tempstore, not committed to the node.',
    );

    // Escape: destroy the editor, restore the filtered markup, send
    // nothing. The canvas baseline is the post-commit filtered output.
    $session->executeScript("window.__filtered = document.querySelector(\"$leaf\").innerHTML;");
    $session->getPage()->find('css', "$leaf p")->click();
    $this->assertJsCondition($editor_ready, 20000, 'Re-clicking the leaf opened the edit for the Escape gesture.');
    $session->executeScript(<<<JS
      (() => {
        const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        Drupal.Daedalus.Edit.Ckeditor5Instances.get(current.ckeditorId).setData('<p>Discard me</p>');
      })();
      JS);
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        return Drupal.Daedalus.Edit.CurrentlyEditingElement === null
          && window.__blueprintPosts === 1
          && root.innerHTML === window.__filtered
          && !document.body.innerHTML.includes('Discard me')
          && !JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('Discard me')
          && Drupal.Daedalus.Edit.Ckeditor5Instances.size === 0;
      })()
      JS,
      20000,
      'Escape restored the filtered markup and sent no mutation.',
    );

    // The format switch: the Text format widget's picker (an edit-scoped
    // widget verb on the chip's face) lists the accessible editor-backed
    // formats; a pick tears the CKEditor down and rebuilds it with the
    // target format's configuration (the filter_xss port runs in
    // between); blur then commits the {value, format} bag as one op, and
    // the response swap re-stamps the settings with the new stored format.
    // The toggle acts on CLICK like every face button; the format buttons
    // inside the popover activate on mousedown so a pick keeps the caret.
    $session->getPage()->find('css', "$leaf p")->click();
    $this->assertJsCondition($editor_ready, 20000, 'Re-clicking the leaf opened the edit for the format switch.');
    $session->executeScript(<<<JS
      window.__openingCkeditorId = Drupal.Daedalus.Edit.CurrentlyEditingElement.ckeditorId;
      document.querySelector("[data-daedalus-edit-toggle='formats']").click();
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        const popover = document.querySelector("[data-daedalus-edit-popover='formats']");
        return !!popover && !popover.hidden
          && !!popover.querySelector("button[data-format='daedalus_edit_formatted_text']")
          && !!popover.querySelector("button[data-format='full_html']")
          && !popover.querySelector("button[data-format='restricted_html']")
          && popover.querySelector("button[data-format='daedalus_edit_formatted_text']").getAttribute('aria-pressed') === 'true';
      })()
      JS,
      20000,
      'The widget toggle opened the format picker listing only editor-backed formats, with the current format pressed.',
    );
    $session->executeScript(<<<JS
      document.querySelector("[data-daedalus-edit-popover='formats'] button[data-format='full_html']")
        .dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        const current = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        if (!current || current.format !== 'full_html' || !current.ckeditorId) {
          return false;
        }
        return current.ckeditorId !== window.__openingCkeditorId
          && !!Drupal.Daedalus.Edit.Ckeditor5Instances.get(current.ckeditorId)
          && document.querySelector("[data-daedalus-edit-popover='formats']").hidden
          && document.querySelector("[data-daedalus-edit-popover='formats'] button[data-format='full_html']").getAttribute('aria-pressed') === 'true';
      })()
      JS,
      20000,
      'The pick rebuilt the CKEditor with the target format and the picker reflects it.',
    );
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition(
      <<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        const component = Blueprint.componentForUuid(Blueprint.getBlueprint(), '$component_uuid');
        const root = document.querySelector("$leaf");
        return window.__blueprintPosts === 2
          && component?.block_fields?.field_formatted_text_text?.format === 'full_html'
          && !!root
          && JSON.parse(root.dataset.daedalusEditEditorSettings).format === 'full_html'
          && Drupal.Daedalus.Edit.CurrentlyEditingElement === null
          && Drupal.Daedalus.Edit.Ckeditor5Instances.size === 0;
      })()
      JS,
      20000,
      'The format switch committed as one {value, format} op and the swap re-stamped the settings.',
    );

    // The open edit is a DRAFT, half one: a foreign STYLE mutation. With
    // the edit open and a local draft in the live CKEditor, a font-size
    // scrub through the spine (update_style on the block key, the reported
    // bug: the Daedalus Stylish inspector styles WHILE editing) swaps the root, the
    // editor's own element, out from under the typing. The seam only a
    // browser can see: the op queue's swap detaches the root (the engine
    // captures the draft there and the orphaned editor is destroyed), the
    // mirror is replaced, and once the response is fully applied the edit
    // resumes over the fresh root by the three-way rule. The style touched
    // nothing the edit holds (base == remote for the field), so the draft
    // is seeded into ONE fresh CKEditor on the swapped-in root: same
    // current edit, the local draft in the editor, the editing marks and
    // the Text format widget standing, the snapshot still the remote.
    $session->getPage()->find('css', "$leaf p")->click();
    $this->assertJsCondition($editor_ready, 20000, 'Re-clicking the leaf opened the edit for the draft steps.');
    $session->executeScript(<<<JS
      (() => {
        window.__draftEdit = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        Drupal.Daedalus.Edit.Ckeditor5Instances.get(window.__draftEdit.ckeditorId).setData('<p>Local draft</p>');
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
        if (!editing || editing !== window.__draftEdit || !root || !editing.ckeditorId) {
          return false;
        }
        const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.get(editing.ckeditorId);
        return !!editor
          && Drupal.Daedalus.Edit.Ckeditor5Instances.size === 1
          && root.dataset.ckeditor5Id === editing.ckeditorId
          && editor.getData().includes('Local draft')
          && root.isContentEditable
          && root.classList.contains('daedalus-edit-inline-editing')
          && root.classList.contains('daedalus-edit-blueprint-editing')
          && !!document.querySelector("[data-daedalus-edit-toggle='formats']")
          && editing.format === 'full_html'
          && editing.snapshot.field_formatted_text_text.value.includes('Edited rich text canvas')
          && !editing.snapshot.field_formatted_text_text.value.includes('Local draft')
          && window.__blueprintPosts === 3;
      })()
      JS,
      20000,
      'The style scrub swapped the root and the draft resumed over it: one fresh CKEditor holding the local draft, the remote snapshot, the widget standing.',
    );
    $style_scrub_error = $session->evaluateScript('window.__styleScrubError ?? null');
    $this->assertNull($style_scrub_error, 'The mid-edit style scrub failed: ' . ($style_scrub_error ?? ''));

    // Blur commits the draft: exactly ONE more op, the mirror carries the
    // typed markup, and the scrubbed font-size rides the re-render (both
    // survived, the bug's whole complaint; the root IS the block, so the
    // key-rooted style computes on it).
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        return window.__blueprintPosts === 4
          && Drupal.Daedalus.Edit.CurrentlyEditingElement === null
          && JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('Local draft')
          && !!root && root.querySelector('p')?.textContent === 'Local draft'
          && !root.isContentEditable
          && Drupal.Daedalus.Edit.Ckeditor5Instances.size === 0
          && getComputedStyle(root).fontSize === '32px';
      })()
      JS,
      20000,
      'Blur committed the resumed draft as one op, and the mirror carries the markup beside the scrubbed style.',
    );

    // Half two: a foreign mutation on the VERY field being edited (a
    // previous commit landing during a re-edit, an undo, an AI turn).
    // Remote != base, so the remote wins: the fresh CKEditor shows the
    // server value, the snapshot re-baselines to it (Escape would now
    // restore server truth), the edit stays open, and the count grows by
    // the foreign op alone.
    $session->getPage()->find('css', "$leaf p")->click();
    $this->assertJsCondition($editor_ready, 20000, 'Re-clicking the leaf opened the edit for the remote-wins step.');
    $session->executeScript(<<<JS
      (() => {
        window.__draftEdit = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        Drupal.Daedalus.Edit.Ckeditor5Instances.get(window.__draftEdit.ckeditorId).setData('<p>Superseded draft</p>');
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_element',
            element: '$component_uuid',
            properties: {
              field_formatted_text_text: {
                value: '<p>Remote <em>rich</em> text</p>',
                format: 'full_html',
              },
            },
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
        const root = document.querySelector("$leaf");
        if (!editing || editing !== window.__draftEdit || !root || !editing.ckeditorId) {
          return false;
        }
        const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.get(editing.ckeditorId);
        const data = editor ? editor.getData() : '';
        return !!editor
          && Drupal.Daedalus.Edit.Ckeditor5Instances.size === 1
          && root.textContent.includes('Remote rich text')
          && /Remote <(em|i)>rich<\\/(em|i)> text/.test(data)
          && !data.includes('Superseded draft')
          && root.isContentEditable
          && root.classList.contains('daedalus-edit-inline-editing')
          && root.classList.contains('daedalus-edit-blueprint-editing')
          && editing.snapshot.field_formatted_text_text.value === data
          && window.__blueprintPosts === 5;
      })()
      JS,
      20000,
      'The foreign field change won over the draft: server markup in a fresh editor, the snapshot re-baselined to it, the edit open.',
    );
    $remote_wins_error = $session->evaluateScript('window.__remoteWinsError ?? null');
    $this->assertNull($remote_wins_error, 'The mid-edit foreign field change failed: ' . ($remote_wins_error ?? ''));

    // Blur after a remote win sends nothing (the editor holds the
    // re-baselined remote, an empty diff) and restores the filtered markup
    // re-captured from the swapped-in root: the <em> the filter kept.
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        return window.__blueprintPosts === 5
          && Drupal.Daedalus.Edit.CurrentlyEditingElement === null
          && !!root && root.querySelector('p')?.textContent === 'Remote rich text'
          && !!root.querySelector('p > em')
          && !root.isContentEditable
          && Drupal.Daedalus.Edit.Ckeditor5Instances.size === 0;
      })()
      JS,
      20000,
      'The no-change blur after the remote win sent nothing and restored the swapped-in filtered markup.',
    );
  }

}
