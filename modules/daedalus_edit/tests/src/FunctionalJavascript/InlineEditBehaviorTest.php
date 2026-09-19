<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\daedalus\Traits\ImageMediaCreationTrait;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives daedalus_edit's inline-edit-to-tempstore pipeline on a real page.
 *
 * The defining capability of daedalus_edit is "stage, don't save": clicking a field
 * on the rendered page edits it in place, and the change is written to
 * tempstore rather than committed to the entity, so iterative editing creates
 * no
 * revisions until an explicit save. The entry gesture is the armed edit
 * session: the Edit tool arms it, a click resolves to the editable leaf under
 * the cursor (no indicators), the real entity form loads in the sidebar, and a
 * CKEditor editor attaches to the rendered page element itself, seeded with
 * the field's raw value (the canvas-native gesture: rich text edits in place
 * on the canvas; the hidden sidebar textarea remains the serialization source
 * of truth).
 * This test drives that whole path plus the part that makes daedalus_edit
 * daedalus_edit: actually changing the value and confirming the change rounds
 * through the real tempstore pipeline without touching the saved entity — and
 * the session grammar around it (Tab commits, Escape cancels then exits,
 * Cmd+Enter and a press into non-keeping chrome commit and leave), plus the
 * open edit's life as a DRAFT across a foreign mutation's re-render.
 *
 * The fixture is DaedalusFixtureTrait: the site the daedalus recipe
 * installs, daedalus_edit_test_fixture's 'plain' bundle (a node whose fields are
 * configured for inline editing, no Layout Builder) installed on top, and
 * the scenario run as daedalus_editor. daedalus_edit pulls the recipe in
 * through its require-dev, which is the point of the per-module test
 * harness: a contributor's merge request exercises the real recipe site
 * with the machinery-derived fixture bundles. daedalus_edit is a page-builder
 * module, so it requires the base daedalus, not the AI superset.
 *
 * One fixture, one scenario. A full site install from the recipe is by far
 * the most expensive part of a run (the framework installs and tears down
 * per method), so all behavior lives in the single test method.
 *
 * @group daedalus_edit
 */
#[Group('daedalus_edit')]
#[RunTestsInSeparateProcesses]
class InlineEditBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use ImageMediaCreationTrait;
  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->daedalusSetUp();

    // Enable styling for the plain node bundle so Daedalus Stylish's inspector panel
    // (the right rail's TOP slot, default-open) renders on the test node. The
    // recipe only enables styling for page, but this test asserts the
    // slot contract that motivated moving daedalus_edit form panels to the BOTTOM
    // slot: the inspector must stay open through form-swap edits instead of
    // being evicted by them, so the inspector has to exist on the page under
    // test. enable() creates the daedalus_stylish_styles field for the bundle, which is
    // the enablement state InspectorSidebar::applies() reads.
    \Drupal::service('daedalus_stylish.field_manager')->enable('node', 'plain');

    // The page-title leg edits the theme's H1 (the outside-rendered label).
    // The recipe places no page title block in its theme (page nodes carry
    // their title in the layout), so place one unless theme initialization
    // already copied one over.
    $existing = \Drupal::entityTypeManager()->getStorage('block')
      ->loadByProperties(['theme' => $this->defaultTheme, 'plugin' => 'page_title_block']);
    if (!$existing) {
      $this->drupalPlaceBlock('page_title_block');
    }

    // The dissolved-accordion leg edits the URL alias pane in the page
    // form's advanced group. The alias widget is access-gated
    // (PathFieldItemList::defaultAccess demands a path permission) and the
    // editor-role contract deliberately grants none, so the pane would not
    // render for the test editor at all. Grant the editor-grade path
    // permission here: the leg needs an advanced PANE WIDGET on the form
    // under test, and the alias is the one the scenario uses.
    $editor_role = Role::load('daedalus_editor');
    $editor_role->grantPermission('create url aliases');
    $editor_role->save();
  }

  /**
   * An inline field edit stages to tempstore without committing the entity.
   *
   * One fixture (the applied base recipe) drives daedalus_edit's signature
   * "stage, don't save" pipeline end to end on the fixture's 'plain'
   * bundle:
   * - Open the field: Edit Mode is entered, the Edit tool activated (arming the
   *   session), and the body field clicked — the armed session resolves the
   *   click to the editable leaf, commits it as the selection, loads the real
   *   entity form in the sidebar, and attaches a CKEditor editor to the page
   *   element itself, seeded with the current raw value.
   * - Edit and stage: a new value is typed into the inline editor and the field
   *   blurred. Blurring is what daedalus_edit turns into its real updateTempstore()
   *   server round-trip: the Update submit handler writes the changed entity to
   *   tempstore (InlineEntityFormAlter::update -> tempstoreRepository->set,
   *   never ::save), and the AJAX response re-renders the field from that
   *   tempstore entity and invokes daedalusEditIsDoneUpdating(). Waiting on that
   *   invocation is the deterministic signal that the server has staged the
   *   edit, with no dependence on AJAX timing.
   * - tempstore, not the entity: the saved node, reloaded fresh from storage,
   *   still has its original body. The edit lives only in tempstore, exactly as
   *   a real edit session leaves it before an explicit save. (This is the
   *   daedalus_edit analog of daedalus_layout asserting an empty layout override after
   *   staging a placement.)
   * - The honest panel and the slot contract: the related field
   *   (entity_reference_autocomplete, the form-swap tier) opens with its real
   *   widget revealed in-flow in the right/BOTTOM form panel — no sized
   *   placeholder, no position: fixed projection — while the page element
   *   keeps a selection outline, and the Styles inspector in the right/TOP
   *   slot stays open through the whole form lifecycle (load, edit, update
   *   round trip) instead of being evicted by it.
   * - The staged value survives a reload: re-requesting the node in Edit Mode
   *   (the server-rendered cookie path) renders the new body, because daedalus_edit
   *   swaps in the tempstore version of the entity when a page is rendered in
   *   Edit Mode. This is the user-facing other half of "stage": the change is
   *   not lost, it is just not committed.
   * - The graduated entity-form panel (EntityFormSidebar + the client-side
   *   subject model): form loads retarget the panel's SUBJECT, a canceled
   *   edit's subject death reverts the open panel to a fresh top-level form,
   *   the reload's boot restore refills the server-rendered open panel, the
   *   dumb icon opens the top-level form under the pointer tool, and an
   *   armed blueprint-block edit (on a page-built page) leaves the panel
   *   untouched — the subject model ignores non-form edits.
   * - The dissolved accordion (sidebar-hosted forms replace core's advanced
   *   vertical_tabs with a container of stacked details): a pane widget
   *   still wires gesture-to-tempstore after the structural rebuild —
   *   opening the URL alias pane on the page form and changing the alias
   *   fires the pane's auto-submit and stages the value, never the entity —
   *   and core's summary plumbing repaints the pane's at-a-glance line from
   *   the same edit (the dissolve keeps the plain-details summary consumer
   *   alive; no vertical-tabs DOM remains).
   * - The pinned panel header: the daedalus-ui-panel__header title is panel-shell
   *   chrome the client subject model writes from the subject form's
   *   shipped title — it names the top-level bundle, flips to the block
   *   bundle label when a block form becomes the subject, and reverts on
   *   subject death.
   * - The form-edited block (the fixture's form_block bundle, no composed
   *   content class): its placement declares the block-form endpoint kind
   *   in its attribution (data-daedalus-edit-form-endpoint="block-form",
   *   stamped by BlockFormEndpoint), an armed field-leaf click fetches
   *   UpdateBlockFormForEditTool into the panel's form cache with the
   *   widget revealed in-flow, the fetch retargets the panel's subject to
   *   the BLOCK (block AS subject), the widget edit rounds through the
   *   same stage-don't-save pipeline, and a canceled block edit's subject
   *   death reverts the open panel to the top-level entity.
   * - Add-field-to-page (the fixture's empty-by-default field_empty): the
   *   form's Add field button materializes a generated sample value onto
   *   the canvas (populateEmptyField -> updatePage) as staged tempstore
   *   state, and emptying the field through its inline edit reverses both
   *   halves — the canvas render is removed and the button returns.
   * - Panel widget visibility is a client fact (panel-widget-visibility.js
   *   over server-hidden wrappers): a field's panel widget is hidden iff
   *   the field has a canvas leaf. The title (a leaf: the fixture's H1)
   *   stays hidden; the orphan field_empty is revealed with auto-submit,
   *   a change stages it under the Selector, the resulting leaf hides the
   *   widget again, and emptying it through the leaf reveals it once more.
   * - The form-path media library (the fixture's field_media): the media
   *   plugin reveals the real media_library_widget in the panel, the
   *   selection swaps through the actual library modal (the widget-scoped
   *   AJAX wrappers and opener parameters FormMediaFieldAttributes stamps,
   *   the Insert selected routing ViewsFormMediaLibraryWidgetAlter reads),
   *   and blur stages the swap through the same pipeline.
   * - The commit half the staging legs stop short of: the top bar's Save
   *   navigates through Tempstore::save — the staged batch lands on the
   *   real entity as ONE new revision attributed to the editor, the
   *   tempstore entry is deleted, and Edit Mode drops on the redirect.
   *   Discard is the other ending: a staged edit is thrown away through
   *   ConfirmDiscardChangesForm's confirm dialog, restoring the committed
   *   values with the tempstore entry deleted.
   * - The block label (the block_property pseudo-field): a placement's
   *   visible label renders as an attributed pseudo-field leaf
   *   (daedalus_edit_preprocess_block's ...::label::block_property vocabulary)
   *   whose in-place edit rounds through UpdateBlockFormForEditTool's
   *   attributeLabel() form item and back onto the canvas re-attributed.
   * - The open edit is a DRAFT (the form path's three-way landing): a
   *   foreign mutation's response re-renders the canvas under the editor,
   *   and on the form path a root-targeting value change also evicts the
   *   root's cached form (a stale form would clobber the foreign change on
   *   submit). The engine captures the draft as the swap detaches the leaf
   *   and FormEditorBase.resume() judges it once the response has FULLY
   *   applied, refetch included: base = the mirror value at edit open,
   *   remote = the mirror after. A style scrub on the node (update_style
   *   through the spine, the reported bug) leaves the field alone, so the
   *   body's draft rides into ONE fresh canvas CKEditor over the swapped-in
   *   element on a refetched form and blur stages it with the style intact;
   *   the subtitle's typed text is re-seeded onto the new element and the
   *   refetched input, which further typing keeps reaching. A foreign
   *   update_element on the body (remote != base) ENDS the edit with the
   *   remote standing on the canvas and in the refetched textarea, nothing
   *   submitted. On a form-edited block, a block-key style scrub swaps the
   *   block subtree without evicting the block's own form: the same edit
   *   re-opens on the new page element and the panel widget's typed value
   *   commits on blur.
   * - Commit and leave: Cmd+Enter (the dialog's OK to Escape's cancel)
   *   commits the open edit and leaves the session for the Selector with
   *   the selection kept; a real press into chrome that keeps nothing (the
   *   page-elements panel header) does the same, because under the Edit
   *   tool a selection that is not editing is forbidden.
   */
  public function testInlineEditStagesToTempstore(): void {
    $editor_account = $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // Two library entries for the form-path media leg: the node starts on
    // the first, the library swap re-points it at the second. Distinct
    // fixtures and alts make the rendered swap unambiguous.
    $first_media = $this->createImageMedia('image-1.png', 'First media image', 'First media alt');
    $second_media = $this->createImageMedia('image-2.jpg', 'Second media image', 'Second media alt');
    $first_media_id = (int) $first_media->id();
    $second_media_id = (int) $second_media->id();

    // Two reference targets for the related (honest-panel) leg: the node
    // under test starts pointing at the first, the edit re-points it at
    // the second. The 'target' bundle is the fixture's plain reference
    // target: it carries no reference fields, so it can never reference
    // back into the fixture's own render (the teaser-block lesson).
    $first_referenced = Node::create([
      'type' => 'target',
      'title' => 'First referenced node',
    ]);
    $first_referenced->save();
    $second_referenced = Node::create([
      'type' => 'target',
      'title' => 'Second referenced node',
    ]);
    $second_referenced->save();

    // The fixture's 'plain' type configures its body field for inline
    // editing without Layout Builder: the simplest Edit-tool surface. Its
    // related field (entity_reference_autocomplete) is the form-swap
    // tier: a widget that edits in the form panel, not in place.
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Inline editing test node',
      'body' => [
        'value' => 'Original body text.',
        'format' => 'daedalus_edit_formatted_text',
      ],
      'field_related' => ['target_id' => $first_referenced->id()],
      'field_media' => ['target_id' => $first_media_id],
      // The draft legs' plain-text subject: a string_textfield rendered
      // INSIDE the entity wrapper, so a root style scrub swaps its leaf
      // (the page title is the theme's H1 outside the wrapper, which a
      // whole-entity swap never touches).
      'field_subtitle' => 'Original subtitle',
    ]);
    $node->save();
    $nid = (int) $node->id();

    $this->drupalGet($node->toUrl());

    // A taller viewport than the WebDriver default. With the inspector open
    // the content column reflows, and on the default 1024x768 window the body
    // field's click point lands under the fixed edit-mode toolbar pill, which
    // intercepts the real pointer click below. More vertical room keeps the
    // page content and the pill apart without giving up the real click.
    $session->resizeWindow(1200, 1600);

    $this->enterEditMode();

    // The Styles inspector owns the right rail's TOP slot and is default-open:
    // entering Edit Mode opens it client-side (EditModePlugin's
    // openDefaultSidebars). Waiting for it here anchors the slot-contract
    // assertions below: every later "the inspector is still open" only means
    // something because it verifiably started open.
    $this->assertJsCondition(
      "(() => { const p = document.getElementById('daedalus_stylish_inspector');"
      . " return !!p && !p.classList.contains('daedalus-ui-hidden'); })()",
      20000,
      'The Styles inspector (right/top) opened by default on Edit Mode entry.',
    );

    // Activate the Edit tool, arming the edit session. The body field has been
    // attributed for inline editing (twig_events + daedalus_edit): it carries the
    // field-value wrapper and the per-item editable id the session resolves.
    $this->activateTool('edit');

    $wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::body::value"]';
    $assert_session->waitForElement('css', $wrapper . ' .daedalus-edit-field-value');

    // Click the body field itself — no indicator overlay: the armed session's
    // document-level handler resolves the click to the editable leaf and opens
    // its editor through the registered engine.
    $session->getPage()->find('css', $wrapper . ' .daedalus-edit-field-value')->click();

    // The session commits the leaf's field as the selection (one selection
    // spine for the Selector and the session).
    $this->assertJsCondition(
      sprintf(
        "Drupal.Daedalus.Selection.getSelected() === 'field:%s:body'",
        $node->uuid(),
      ),
      20000,
      'The armed click committed the body field as the selection.',
    );

    // The canvas-native gesture: the engine loads the real entity form in
    // the sidebar (the hidden textarea is what the form submit serializes),
    // but the CKEditor 5 editor attaches to the rendered page element
    // itself, seeded with the field's raw value. The editor must be ON the
    // canvas — inside the field's page wrapper — and NOT in the sidebar.
    $editor = $assert_session->waitForElement('css', $wrapper . ' .ck-editor__editable');
    $this->assertNotNull($editor, 'The CKEditor inline editor attached to the body field\'s page element.');
    $this->assertStringContainsString('Original body text.', $editor->getText());
    $this->assertNull(
      $session->getPage()->find('css', '#daedalus-ui-right-sidebar .ck-editor__editable'),
      'No CKEditor was built inside the sidebar — the editor rides the canvas.',
    );

    // Loading the form did NOT evict the inspector: the entity-form panel
    // (the EntityFormSidebar plugin, a REAL right-rail panel) declares the
    // BOTTOM slot, so the top-slot inspector rides through the form load
    // untouched — slots stack, they don't compete. The fetched form landed
    // in the panel's form cache and became its SUBJECT (recorded on the
    // mount, data-keeps-edit riding the panel root via the plugin's build).
    $this->assertJsCondition(
      "(() => {"
      . " const panel = document.getElementById('daedalus_edit_entity_form');"
      . " const mount = document.getElementById('daedalus-edit-form-cache');"
      . " const inspector = document.getElementById('daedalus_stylish_inspector');"
      . " return !!panel && panel.dataset.sidebarType === 'daedalus_edit_form'"
      . " && panel.dataset.sidebarSlot === 'bottom'"
      . " && panel.hasAttribute('data-keeps-edit')"
      . " && !panel.classList.contains('daedalus-ui-hidden')"
      . " && !!mount && mount.dataset.daedalusEditSubject === 'node::" . $nid . "'"
      . " && !!mount.querySelector(':scope > .daedalus-edit-form--subject[data-daedalus-edit-form-id=\"node::" . $nid . "\"]')"
      . " && !!inspector && !inspector.classList.contains('daedalus-ui-hidden');"
      . " })()",
      20000,
      'The entity-form panel opened in right/bottom on the node subject and the right/top inspector stayed open through the form load.',
    );

    // Install a race-free completion counter. daedalus_edit's update AJAX response
    // invokes daedalusEditIsDoneUpdating() once the server has staged the edit
    // (DaedalusUiFormTrait::updateAjaxSubmit, after the Update submit
    // handler has written to tempstore). Wrapping it and counting invocations
    // is
    // a deterministic "the server is done staging" signal that does not depend
    // on observing a transient throbber between poll intervals.
    $session->executeScript(<<<JS
      window.__daedalusEditDoneCount = 0;
      const original = jQuery.fn.daedalusEditIsDoneUpdating;
      jQuery.fn.daedalusEditIsDoneUpdating = function () {
        window.__daedalusEditDoneCount++;
        return original.apply(this, arguments);
      };
    JS);

    // Type a new value into the inline editor and blur the field. Blurring is
    // what the InlineEditor field plugin turns into the real updateTempstore()
    // call: it detects the value changed against the original and submits the
    // hidden Update button, which stages the edit on the server.
    $session->executeScript(<<<JS
      const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value;
      editor.setData('<p>New body text.</p>');
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
    JS);

    // The edit has reached the server (and therefore tempstore) exactly when
    // the
    // update AJAX response fires daedalusEditIsDoneUpdating().
    $this->assertJsCondition('window.__daedalusEditDoneCount >= 1', 20000);

    // The field re-rendered with the new value (the AJAX response replaces the
    // field markup with the tempstore-rendered field).
    $assert_session->waitForElement('css', $wrapper);
    $this->assertStringContainsString(
      'New body text.',
      $session->getPage()->find('css', $wrapper)->getText(),
      'The edited field re-rendered with the new value.',
    );

    // The headline: the edit is staged in tempstore, not committed. The saved
    // node, loaded fresh from storage, still has its original body.
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    $saved = $storage->load($nid);
    $this->assertSame(
      'Original body text.',
      $saved->get('body')->value,
      'The inline edit is staged in tempstore, not committed to the node.',
    );

    // ----- The root edit target: the page title IS the title field. -----
    // A node's title never renders inside the entity on its canonical page
    // (node preprocess pulls it out of content); the H1 is the theme's
    // page-title block. daedalus_edit attributes that render as the title
    // field's editable leaf (PageTitleEditing + preprocess_page_title), so
    // the armed session has a leaf to open on click. Only the click: the
    // root row's Edit verb opens the Page form (the next leg) and never
    // routes here. The click is synthetic like the later legs: the fixed
    // edit-mode chrome overlaps the H1's coordinates.
    $title_wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::title::value"]';
    $title_leaf = $title_wrapper . ' .daedalus-edit-field-value';
    $assert_session->waitForElement('css', $title_leaf);
    $session->executeScript(
      "document.querySelector('" . $title_leaf . "')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );

    // The leaf is the root's edit target, so the committed selection is the
    // ROOT row: the projection absorbed the title field into it (the label
    // presents AS the entity row), and a selection key the panel cannot
    // name would lead nowhere.
    $this->assertJsCondition(
      sprintf(
        "Drupal.Daedalus.Selection.getSelected() === 'entity:%s'",
        $node->uuid(),
      ),
      20000,
      'Clicking the page title committed the ROOT row as the selection.',
    );

    // The title is the in-place text tier: the H1 itself edits.
    $this->assertJsCondition(
      "document.querySelector('" . $title_leaf . "').hasAttribute('contenteditable')",
      20000,
      'The page title edits in place.',
    );

    // Type a new title and blur: the same stage-don't-save round trip.
    $session->executeScript(<<<JS
      const h1 = document.querySelector('{$title_leaf}');
      h1.textContent = 'A staged new title';
      h1.dispatchEvent(new Event('input', { bubbles: true }));
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
    JS);
    $this->assertJsCondition('window.__daedalusEditDoneCount >= 2', 20000);

    // The commit REPLACED the H1 instead of removing it: the label is not
    // in the entity render, so updatePage delivers a fresh page-title
    // render beside the entity content for the wrapper selector to match,
    // and that render arrives re-attributed for the next edit.
    $assert_session->waitForElement('css', $title_leaf);
    $this->assertStringContainsString(
      'A staged new title',
      $session->getPage()->find('css', $title_wrapper)->getText(),
      'The page title re-rendered with the staged value, still attributed.',
    );

    // Staged, not committed: the saved node keeps its original title.
    $storage->resetCache([$nid]);
    $this->assertSame(
      'Inline editing test node',
      $storage->load($nid)->getTitle(),
      'The title edit is staged in tempstore, not committed to the node.',
    );

    // ----- The root row's Edit verb: the Page form, no tool change. -----
    // The root's editor is the entity-form panel, never a canvas leaf: the
    // label may have no canvas render at all (daedalus_theme places no
    // page title block; the H1 above is this fixture's own placement), so
    // the verb opens the panel on the top-level subject and leaves the tool
    // alone. Panel widgets stage to tempstore on change under any tool, so
    // the Selector is the tool to prove it under: a route through the Edit
    // tool would flip the cookie. Drop to the Selector and close the panel
    // first, so the verb's open is the only thing that can bring it back.
    $this->activateTool('selector');
    $session->executeScript(
      "Drupal.Daedalus.SidebarManager.closeSidebar('daedalus_edit_entity_form');"
    );
    $this->assertJsCondition(
      "document.getElementById('daedalus_edit_entity_form').classList.contains('daedalus-ui-hidden')",
      20000,
      'The entity-form panel is closed ahead of the root Edit verb.',
    );

    // The seam: the verb registry's applies gate admits the root (the
    // delivered _element annotation carries an edit_target), and the verb's
    // run goes to the sidebar manager, not the tool manager. verbsFor is
    // the same lookup every face (chip button, context menu, tree row)
    // uses, so this is the gesture minus the pointer.
    $root_key = 'entity:' . $node->uuid();
    $session->executeScript(<<<JS
      (() => {
        const key = '{$root_key}';
        Drupal.Daedalus.Selection.select(key);
        const verb = Drupal.Daedalus.Verbs.verbsFor(key)
          .find((candidate) => candidate.id === 'daedalus_edit.edit');
        if (!verb) {
          throw new Error('The Edit verb does not apply to the root row.');
        }
        verb.run(key);
      })();
      JS);
    $this->assertJsCondition(
      "(() => {"
      . " const panel = document.getElementById('daedalus_edit_entity_form');"
      . " const mount = document.getElementById('daedalus-edit-form-cache');"
      . " return !!panel && !panel.classList.contains('daedalus-ui-hidden')"
      . " && mount.dataset.daedalusEditSubject === 'node::" . $nid . "'"
      . " && !!mount.querySelector(':scope > .daedalus-edit-form--subject[data-daedalus-edit-form-id=\"node::" . $nid . "\"]')"
      . " && Drupal.Daedalus.getCookieValue('activeTool') === 'selector';"
      . " })()",
      20000,
      'The root Edit verb opened the entity-form panel on the top-level subject and left the Selector as the tool.',
    );

    // ----- Panel widget visibility: hidden iff the field has a canvas leaf. -----
    // The server ships every field wrapper hidden (FormDefaultFieldAttributes)
    // and decides nothing else: whether a field renders on the canvas is a
    // theme and layout fact only the client can see. One behavior
    // (panel-widget-visibility.js) reveals the wrappers with no canvas leaf
    // and gives them auto-submit; the rest stay hidden for their leaf's
    // editor. On this fixture the placed title block gives the title a leaf
    // (the H1 edited above), so its wrapper stays hidden; field_empty ships
    // empty, so it has no leaf and its widget belongs to the panel. Still
    // the Selector: the rule and the panel's staging are tool-blind.
    $subject_form = '#daedalus-edit-form-cache .daedalus-edit-form--subject';
    $title_form_item = '[data-daedalus-edit-form-item-wrapper-id="node::' . $nid . '::title::value"]';
    $empty_form_item = '[data-daedalus-edit-form-item-wrapper-id="node::' . $nid . '::field_empty::value"]';
    $empty_wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::field_empty::value"]';
    $this->assertJsCondition(<<<JS
      (() => {
        const title = document.querySelector('{$subject_form} {$title_form_item}');
        const empty = document.querySelector('{$subject_form} {$empty_form_item}');
        return !!title && title.classList.contains('daedalus-edit-hidden')
          && !title.hasAttribute('data-daedalus-edit-auto-submit')
          && !!empty && !empty.classList.contains('daedalus-edit-hidden')
          && empty.hasAttribute('data-daedalus-edit-auto-submit')
          && getComputedStyle(empty).visibility === 'visible';
      })()
      JS,
      20000,
      'The title wrapper (its leaf is the H1) stays hidden without auto-submit; the orphan field_empty wrapper is revealed with auto-submit.',
    );

    // Change the revealed widget: a plain change event, no inline editor,
    // rides DaedalusEditEntityFormAutoSave into the same stage-don't-save
    // pipeline as every leg. A panel submit names no single element, so
    // updatePage swaps the whole entity.
    $session->executeScript(<<<JS
      const orphanInput = document.querySelector('{$subject_form} {$empty_form_item} input[name="field_empty[0][value]"]');
      orphanInput.value = 'A panel-staged orphan value';
      orphanInput.dispatchEvent(new Event('change', { bubbles: true }));
    JS);
    $this->assertJsCondition('window.__daedalusEditDoneCount >= 3', 20000);
    $tempstore = \Drupal::service('daedalus_tempstore.entity_tempstore_repository');
    $storage->resetCache([$nid]);
    $this->assertSame(
      'A panel-staged orphan value',
      $tempstore->get($storage->load($nid))->get('field_empty')->value,
      'The revealed widget staged the orphan field in tempstore.',
    );
    $this->assertTrue(
      $storage->load($nid)->get('field_empty')->isEmpty(),
      'The orphan edit is staged in tempstore, not committed to the node.',
    );

    // The staged value gave the field a canvas leaf, and the swap re-ran
    // the rule (updateElement re-attaches behaviors): the same wrapper is
    // hidden now with its auto-submit withdrawn. A field with a leaf edits
    // through the leaf, never through the panel.
    $this->assertNotNull(
      $assert_session->waitForElement('css', $empty_wrapper . ' .daedalus-edit-field-value'),
      'The panel-staged value rendered the field on the canvas.',
    );
    $this->assertJsCondition(<<<JS
      (() => {
        const empty = document.querySelector('{$subject_form} {$empty_form_item}');
        return !!empty && empty.classList.contains('daedalus-edit-hidden')
          && !empty.hasAttribute('data-daedalus-edit-auto-submit');
      })()
      JS,
      20000,
      'The new canvas leaf hid the panel widget and withdrew its auto-submit.',
    );

    // Re-arm the Edit tool for the legs below with nothing selected: the
    // armed session opens the selection's editor on arm, and the related
    // leg wants to open its own edit from a clean click.
    $session->executeScript('Drupal.Daedalus.Selection.clear();');
    $this->activateTool('edit');

    // ...and back through the leaf. The add-field leg below needs
    // field_empty EMPTY again (its Add field button, no canvas render), and
    // emptying it in place is the rule's other direction: the emptied
    // value removes the canvas render (updateElement treats a live-only
    // selector as a removal) and rebuilds the form (the empty_field flag),
    // and the behavior reveals the fresh wrapper with auto-submit again.
    $session->executeScript(
      "document.querySelector('" . $empty_wrapper . " .daedalus-edit-field-value')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_empty'",
      20000,
      'Clicking the panel-staged leaf opened its in-place edit.',
    );
    $session->executeScript(<<<JS
      const orphanLeaf = Drupal.Daedalus.Edit.CurrentlyEditingElement.getPageElementHandle();
      orphanLeaf.textContent = '';
      orphanLeaf.dispatchEvent(new Event('input', { bubbles: true }));
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
    JS);
    $this->assertJsCondition('window.__daedalusEditDoneCount >= 4', 20000);
    $this->assertJsCondition(<<<JS
      (() => {
        const empty = document.querySelector('{$subject_form} {$empty_form_item}');
        return !document.querySelector('{$empty_wrapper}')
          && !!empty && !empty.classList.contains('daedalus-edit-hidden')
          && empty.hasAttribute('data-daedalus-edit-auto-submit');
      })()
      JS,
      20000,
      'Emptying the field removed its canvas leaf and revealed its panel widget again.',
    );
    $storage->resetCache([$nid]);
    $this->assertTrue(
      $tempstore->get($storage->load($nid))->get('field_empty')->isEmpty(),
      'The orphan field is staged empty again.',
    );

    // The honest-panel gesture (the form-swap tier). The related field
    // is an entity_reference_autocomplete: a widget that looks like a form
    // control wherever it is, so its plugin (EntityReferenceAutocompletePlugin
    // extends DefaultPlugin) reveals the real widget in-flow in the form panel
    // instead of projecting it position: fixed over the page. Clicking the
    // field must therefore (a) open the edit with the widget visible in the
    // right/bottom form panel — no sized placeholder, no fixed positioning —
    // (b) keep the page element visible with the panel-editing outline, and
    // (c) leave the right/top inspector open: the form panel's close/open
    // cycle in getForm() is scoped to the bottom slot. The click is synthetic
    // for the same reason as the re-click below: the open sidebar overlaps the
    // field's coordinates in the headless layout.
    // The wrapper's property suffix is 'value', not 'target_id': the
    // attribute subscriber resolves the main property via
    // getMainPropertyName(), which exists on base-field definitions but not
    // on FieldConfig, so configurable fields take the 'value' fallback.
    $related_wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid
      . '::field_related::value"]';
    $session->executeScript(<<<JS
      (() => {
        const target = document.querySelector('{$related_wrapper} .daedalus-edit-field-value');
        if (!target) {
          throw new Error('Related-content field value not found. Wrappers present: '
            + [...document.querySelectorAll('[data-daedalus-edit-field-value-wrapper]')]
              .map((el) => el.getAttribute('data-daedalus-edit-field-value-wrapper')).join(' | '));
        }
        target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
      })();
      JS);
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_related'",
      20000,
      'Clicking the related field opened its edit.',
    );
    $this->assertJsCondition(<<<JS
      (() => {
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        const wrapper = editing.getFormItemWrapper();
        const formItem = editing.getFormItemHandle();
        const pageElement = editing.getPageElementHandle();
        const panel = wrapper.closest('[data-sidebar-type="daedalus_edit_form"]');
        const mount = document.getElementById('daedalus-edit-form-cache');
        const inspector = document.getElementById('daedalus_stylish_inspector');
        return !wrapper.classList.contains('daedalus-edit-hidden')
          && getComputedStyle(formItem).position !== 'fixed'
          && document.querySelector('.sized-placeholder') === null
          && pageElement.classList.contains('daedalus-edit-panel-editing')
          && !!panel && panel.dataset.sidebarSlot === 'bottom'
          && !panel.classList.contains('daedalus-ui-hidden')
          && mount.dataset.daedalusEditSubject === 'node::{$nid}'
          && !!inspector && !inspector.classList.contains('daedalus-ui-hidden')
          && !document.cookie.split('; ').includes('right_top_sidebar=');
      })()
      JS,
      20000,
      'The widget revealed in-flow in the right/bottom panel with the node as the subject, the page element kept its outline, and the right/top inspector stayed open with no empty right/top cookie.',
    );

    // Change the reference and blur: the same stage-don't-save pipeline the
    // body edit proved, but through the panel-revealed widget. blurElement()
    // diffs the input against the value captured on focus and submits the
    // hidden Update button, so the tempstore round trip and the
    // daedalusEditIsDoneUpdating() completion signal are identical.
    $session->executeScript(<<<JS
      const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
      const input = editing.plugin.getFormItemInputs(editing)[0];
      input.value = 'Second referenced node ({$second_referenced->id()})';
      editing.plugin.blur();
    JS);
    $this->assertJsCondition('window.__daedalusEditDoneCount >= 5', 20000);

    // The field re-rendered from tempstore with the new reference; the saved
    // node still points at the original. Same headline as the body leg:
    // staged, not committed.
    $assert_session->waitForElement('css', $related_wrapper);
    $this->assertStringContainsString(
      'Second referenced node',
      $session->getPage()->find('css', $related_wrapper)->getText(),
      'The related field re-rendered with the new reference.',
    );
    $storage->resetCache([$nid]);
    $saved = $storage->load($nid);
    $this->assertSame(
      (int) $first_referenced->id(),
      (int) $saved->get('field_related')->target_id,
      'The reference edit is staged in tempstore, not committed to the node.',
    );

    // And the update round trip (which AJAX-replaces the field markup and the
    // form wrapper) did not disturb the inspector either.
    $this->assertJsCondition(
      "(() => { const p = document.getElementById('daedalus_stylish_inspector');"
      . " return !!p && !p.classList.contains('daedalus-ui-hidden'); })()",
      20000,
      'The inspector is still open after the panel-widget edit committed.',
    );

    // The session grammar around editing. Re-opening the editor uses a
    // synthetic click into the session's document-level handler (the same
    // path as the real first click above): the sidebar form is open now and
    // overlaps the field's coordinates in the headless layout, so WebDriver
    // cannot land a real pointer on it.
    $reclick = "document.querySelector('" . $wrapper . " .daedalus-edit-field-value')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));";

    // The blurred editor's element can linger in the sidebar DOM, so "the
    // editor is open" is asserted on daedalus_edit's editing state, not markup.
    $body_editing = "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'body'";

    // Tab while editing commits the edit (with no following editable leaf on
    // this page, nothing else opens): the body's edit ends. The synthetic
    // keydown reaches the app's one hotkey dispatcher; while CKEditor holds
    // focus the typing-context twin is what fires, so this also proves
    // session keys survive the typing guard.
    $session->executeScript($reclick);
    $this->assertJsCondition($body_editing, 20000, 'Re-clicking the field re-opened its editor.');
    $this->pressHotkey('Tab');
    $this->assertJsCondition(
      "!Drupal.Daedalus.Edit.CurrentlyEditingElement || Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName !== 'body'",
      20000,
      'Tab committed the body edit through the session dispatcher.',
    );

    // Escape follows the dialog convention: the first press cancels the
    // current edit, the second exits the session back to the Selector.
    $session->executeScript($reclick);
    $this->assertJsCondition($body_editing, 20000, 'The editor re-opened for the Escape leg.');
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement === null',
      20000,
      'Escape while editing canceled the edit.',
    );

    // Revert on subject death: the cancel evicted the subject's cached form
    // (its values are unknowable after a discard), which is a subject-death
    // path — the OPEN panel reverts to the top-level entity, re-fetching its
    // form quietly. The panel stays open and the fresh node form is the
    // subject again.
    $this->assertJsCondition(
      "(() => {"
      . " const panel = document.getElementById('daedalus_edit_entity_form');"
      . " const mount = document.getElementById('daedalus-edit-form-cache');"
      . " return !!panel && !panel.classList.contains('daedalus-ui-hidden')"
      . " && mount.dataset.daedalusEditSubject === 'node::" . $nid . "'"
      . " && !!mount.querySelector(':scope > .daedalus-edit-form--subject[data-daedalus-edit-form-id=\"node::" . $nid . "\"]');"
      . " })()",
      20000,
      'The canceled edit killed the subject form and the open panel reverted to a fresh top-level form.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "Drupal.Daedalus.getCookieValue('activeTool') === 'selector'",
      20000,
      'Escape while armed (not editing) exited the session to the Selector tool.',
    );

    // ——— The whole-entity refresh fallback (UpdateElement). ———
    // daedalus_edit's update responses ride daedalus_ui's UpdateElement
    // command. Its primary path replaces the selector-matched elements (the
    // body and reference legs above proved it); its FALLBACK handles adds
    // and removes: when a selector matches the freshly rendered content but
    // nothing live (the element is new or changed identity), the command
    // replaces the WHOLE entity wrapper instead — found in both trees by
    // its canonical data-daedalus-ui-entity-wrapper id. Drive the
    // command directly with content whose marker exists only in the
    // rendered tree (a marked clone stands in for a server re-render) and
    // assert the live wrapper was swapped wholesale. This pins the contract
    // that both the rendered content and the live page carry the same
    // canonical wrapper id on a replaceable element.
    $swapped = $session->evaluateScript(<<<JS
      (() => {
        const live = document.querySelector('[data-daedalus-ui-entity-wrapper][data-main-entity]');
        const clone = live.cloneNode(true);
        clone.setAttribute('data-test-fallback-swap', '1');
        const rendered = document.createElement('div');
        rendered.appendChild(clone);
        Drupal.AjaxCommands.prototype.updateElement({}, {
          selectors: ['[data-test-fallback-swap="1"]'],
          content: rendered.innerHTML,
        });
        const after = document.querySelector('[data-daedalus-ui-entity-wrapper][data-main-entity]');
        return after.getAttribute('data-test-fallback-swap') === '1';
      })()
      JS);
    $this->assertTrue(
      $swapped,
      'The unmatched-selector fallback replaced the whole entity wrapper by its canonical id.',
    );

    // The staged value survives a fresh request: re-requesting the node in Edit
    // Mode (the server-rendered cookie path) renders the new body, because
    // daedalus_edit swaps in the tempstore version of the entity when a page
    // renders
    // in Edit Mode. This is the user-facing "stage" half: the change persists
    // across a reload while the committed entity stays untouched.
    $this->enterEditModeViaCookies($node->toUrl(), 'edit');
    $assert_session->pageTextContains('New body text.');
    $assert_session->pageTextNotContains('Original body text.');
    // The staged title too: the tempstore-swapped route entity feeds the
    // title resolver, so the theme's H1 renders the staged value.
    $assert_session->pageTextContains('A staged new title');

    // Exactly ONE element answers to the title's identity on the SSR
    // edit-mode render. Core's buildTitle() inlines the rendered title
    // FIELD inside the H1 (#is_page_title); daedalus_ui skips
    // attributing that inner render so the page-title element daedalus_edit
    // stamps stays the field's one carrier; duplicates would make the
    // form-swap and markup replacement ambiguous.
    $this->assertJsCondition(
      "document.querySelectorAll('[data-daedalus-edit-field-value-wrapper=\"node::" . $nid . "::title::value\"]').length === 1"
      . " && document.querySelectorAll('[data-selectable=\"field:" . $node->uuid() . ":title\"]').length === 1",
      20000,
      'The page title is the title field\'s ONE attributed render.',
    );

    // The panel survives the reload: the (side, slot) cookie renders it
    // open server-side, but cached forms are client-only, so the reload
    // lands an open, EMPTY panel — the boot restore fetches the top-level
    // entity's form (the panel's default subject) so the open panel is
    // truthful again.
    $this->assertJsCondition(
      "(() => {"
      . " const panel = document.getElementById('daedalus_edit_entity_form');"
      . " const mount = document.getElementById('daedalus-edit-form-cache');"
      . " return !!panel && !panel.classList.contains('daedalus-ui-hidden')"
      . " && !!mount && mount.dataset.daedalusEditSubject === 'node::" . $nid . "'"
      . " && !!mount.querySelector(':scope > .daedalus-edit-form--subject');"
      . " })()",
      20000,
      'The reloaded open panel restored its top-level subject.',
    );

    // The reload killed the previous JS context, so re-arm the Edit tool
    // deterministically and install a fresh completion counter for the
    // add-field, empty-field, and media legs below.
    $this->activateTool('edit');
    $session->executeScript(<<<JS
      window.__nodeDoneCount = 0;
      const nodeOriginal = jQuery.fn.daedalusEditIsDoneUpdating;
      jQuery.fn.daedalusEditIsDoneUpdating = function () {
        window.__nodeDoneCount++;
        return nodeOriginal.apply(this, arguments);
      };
    JS);

    // ——— Add-field-to-page: the empty field materializes on canvas. ———
    // The fixture's field_empty ships empty by default, so the canonical
    // page has nothing to click for it — the entity form grows an "Add
    // field" button instead (DaedalusEditFormTrait::addFieldToPageButton).
    // Clicking it runs populateEmptyField: a sample value is generated
    // (field_sample_value's random_string), staged to tempstore, and
    // updatePage re-renders the whole entity with the field ON the canvas,
    // attributed for the next edit; the rebuilt form drops the button (the
    // field is no longer empty).
    $add_empty_button = '#daedalus-edit-form-cache [name="add_empty_field::field_empty"]';
    $this->assertJsCondition(
      "!!document.querySelector('" . $add_empty_button . "')",
      20000,
      'The restored top-level form offers the Add field button for the empty fixture field.',
    );
    $this->assertNull(
      $session->getPage()->find('css', $empty_wrapper),
      'The empty field has no canvas render before the add gesture.',
    );
    $session->executeScript("jQuery('" . $add_empty_button . "').mousedown();");
    $sample_leaf = $assert_session->waitForElement('css', $empty_wrapper . ' .daedalus-edit-field-value');
    $this->assertNotNull($sample_leaf, 'The add gesture materialized the field on canvas.');
    $this->assertNotSame('', trim($sample_leaf->getText()), 'The materialized field carries a generated sample value.');
    $this->assertJsCondition(
      "document.querySelector('" . $add_empty_button . "') === null",
      20000,
      'The Add field button left the rebuilt form: the field is on the page now.',
    );
    $storage->resetCache([$nid]);
    $this->assertTrue(
      $storage->load($nid)->get('field_empty')->isEmpty(),
      'The materialized sample value is staged in tempstore, not committed.',
    );

    // ...and the button RETURNS when the field empties again: emptying the
    // field through its inline edit reverses both halves of the round trip
    // — updatePage removes the canvas render (updateElement treats a
    // live-only selector as a removal) and the rebuilt form regrows the
    // button (the empty_field hidden input updateTempstore sets when a
    // blur empties the value is what flags the form rebuild).
    $session->executeScript(
      "document.querySelector('" . $empty_wrapper . " .daedalus-edit-field-value')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_empty'",
      20000,
      'Clicking the materialized field opened its in-place edit.',
    );
    $session->executeScript(<<<JS
      const emptyLeaf = Drupal.Daedalus.Edit.CurrentlyEditingElement.getPageElementHandle();
      emptyLeaf.textContent = '';
      emptyLeaf.dispatchEvent(new Event('input', { bubbles: true }));
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
    JS);
    $this->assertJsCondition('window.__nodeDoneCount >= 1', 20000);
    $this->assertJsCondition(
      "document.querySelector('" . $empty_wrapper . "') === null"
      . " && !!document.querySelector('" . $add_empty_button . "')",
      20000,
      'Emptying the field removed its canvas render and the Add field button returned.',
    );

    // ——— The form-path media library. ———
    // field_media rides media_library_widget, the widget the form-path
    // media machinery is keyed to: FormMediaFieldAttributes serializes the
    // widget's selection onto the form item (data-daedalus-edit-media-value,
    // the value media.js diffs on blur) and scopes the widget's AJAX
    // wrappers and library-opener parameters to THIS widget, so identical
    // widgets on other cached forms cannot swallow the update.
    $media_wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::field_media::value"]';
    $media_form_item = '[data-daedalus-edit-form-item-wrapper-id="node::' . $nid . '::field_media::value"]';
    $session->executeScript(
      "document.querySelector('" . $media_wrapper . " .daedalus-edit-field-value')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_media'"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.id === 'media'",
      20000,
      'Clicking the media field opened its edit through the media plugin.',
    );
    $this->assertJsCondition(
      "(() => {"
      . " const wrapper = document.querySelector('$media_form_item');"
      . " const item = wrapper?.querySelector('[data-daedalus-edit-media-value]');"
      . " if (!wrapper || wrapper.classList.contains('daedalus-edit-hidden') || !item) { return false; }"
      . " return JSON.parse(item.dataset.daedalusEditMediaValue).map(String).join() === '$first_media_id';"
      . " })()",
      20000,
      'The media widget revealed in the panel carrying the serialized current selection.',
    );

    // Swap the selection through the real library: remove the current item
    // (the widget-scoped AJAX wrapper rebuilds just this widget), open the
    // library, pick the second image, Insert selected.
    $session->executeScript("jQuery('$media_form_item input[name^=\"field_media-0-media-library-remove-button\"]').mousedown();");
    $open_button = $assert_session->waitForElement('css', "$media_form_item .js-media-library-open-button", 20000);
    $this->assertNotNull($open_button, 'Removing the selection revealed the Add media opener.');
    $session->executeScript("jQuery('$media_form_item .js-media-library-open-button').mousedown();");
    $second_checkbox = $assert_session->waitForElement(
      'css',
      ".media-library-widget-modal input[type='checkbox'][value='$second_media_id']",
      20000,
    );
    $this->assertNotNull($second_checkbox, 'The media library modal rendered the second media item.');
    $session->executeScript("document.querySelector(\".media-library-widget-modal input[type='checkbox'][value='$second_media_id']\").click();");
    $session->executeScript(<<<JS
      (() => {
        const modal = document.querySelector('.media-library-widget-modal');
        const button = modal.querySelector('input[value="Insert selected"], button.media-library-select');
        if (!button) {
          throw new Error('Insert selected button not found in the media library modal.');
        }
        button.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
      })();
      JS);
    $this->assertJsCondition(
      "(() => {"
      . " const item = document.querySelector('$media_form_item [data-daedalus-edit-media-value]');"
      . " return !document.querySelector('.media-library-widget-modal')"
      . " && !!item && JSON.parse(item.dataset.daedalusEditMediaValue).map(String).join() === '$second_media_id';"
      . " })()",
      20000,
      'Insert selected landed the new media in THIS widget and re-serialized its selection.',
    );

    // Blur commits: media.js diffs the serialized selection against the
    // value captured on focus and stages the swap through the same
    // pipeline. Staged, not committed, like every leg before it.
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition('window.__nodeDoneCount >= 2', 20000);
    $this->assertJsCondition(
      "!!document.querySelector('$media_wrapper img[src*=image-2]')",
      20000,
      'The media field re-rendered from tempstore with the second image.',
    );
    $storage->resetCache([$nid]);
    $this->assertSame(
      $first_media_id,
      (int) $storage->load($nid)->get('field_media')->target_id,
      'The media swap is staged in tempstore, not committed to the node.',
    );

    // ——— Save: the top bar commits the staged batch and drops Edit Mode. ———
    // Everything above stopped at "staged, not committed"; the Save button
    // is the committing gesture. It navigates to Tempstore::save, which
    // applies the staged tempstore entity onto the freshly loaded node,
    // marks ONE new revision (the plain bundle's new_revision setting;
    // revision metadata stamped to the editor, not the prior author),
    // saves, deletes the tempstore entry, and redirects back with a
    // path-scoped navigationMode=none cookie — the page lands committed
    // and OUT of Edit Mode.
    $storage->resetCache([$nid]);
    $vids_before = $storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('nid', $nid)->execute();
    $session->executeScript("window.__preSave = true; document.querySelector('#daedalus-ui-save').click();");
    $this->assertJsCondition('window.__preSave === undefined', 20000, 'The Save button navigated.');
    // The landed page is OUT of Edit Mode: the redirect's path-scoped
    // navigationMode=none cookie outranks the session-wide edit cookie, so
    // the committed values render with NO editing attribution and the Edit
    // Mode chrome hidden (it stays in the DOM for a permitted user — the
    // mode toggle reveals it client-side — but paints nothing).
    $landing = json_decode((string) $session->evaluateScript(
      "JSON.stringify({"
      . " url: window.location.href,"
      . " saveVisible: document.querySelector('#daedalus-ui-save')?.offsetParent !== null,"
      . " attributed: !!document.querySelector('[data-daedalus-edit-field-value-wrapper]'),"
      . " hasBody: document.body.textContent.includes('New body text.'),"
      . " hasTitle: document.body.textContent.includes('A staged new title')"
      . " })"
    ), TRUE);
    $this->assertTrue($landing['hasBody'], 'Save landed on the committed body. ' . print_r($landing, TRUE));
    $this->assertTrue($landing['hasTitle'], 'Save landed on the committed title. ' . print_r($landing, TRUE));
    $this->assertFalse($landing['saveVisible'], 'The Edit Mode chrome dropped with the save. ' . print_r($landing, TRUE));
    $this->assertFalse($landing['attributed'], 'No editing attribution rides the post-save page. ' . print_r($landing, TRUE));
    $storage->resetCache([$nid]);
    $committed = $storage->load($nid);
    $this->assertStringContainsString('New body text.', $committed->get('body')->value, 'Save committed the staged body.');
    $this->assertSame('A staged new title', $committed->getTitle(), 'Save committed the staged title.');
    $this->assertSame((int) $second_referenced->id(), (int) $committed->get('field_related')->target_id, 'Save committed the staged reference.');
    $this->assertSame($second_media_id, (int) $committed->get('field_media')->target_id, 'Save committed the staged media swap.');
    $this->assertTrue($committed->get('field_empty')->isEmpty(), 'The re-emptied field committed as empty.');
    $vids_after = $storage->getQuery()->accessCheck(FALSE)->allRevisions()->condition('nid', $committed->id())->execute();
    $this->assertCount(count($vids_before) + 1, $vids_after, 'The save created exactly one new revision.');
    $this->assertSame((int) $editor_account->id(), (int) $committed->getRevisionUserId(), 'The new revision is attributed to the editor.');
    $this->assertFalse($tempstore->has($committed), 'The tempstore entry died with the save.');

    // ——— Discard: the confirm form restores the committed state. ———
    // Re-enter Edit Mode on the node (the save's redirect planted a
    // path-scoped navigationMode=none cookie, so plant edit back on the
    // same path), stage a fresh body edit, then throw it away through the
    // top bar's confirm dialog: ConfirmDiscardChangesForm's submit loop
    // deletes the tempstore entries and the redirect renders the committed
    // values again.
    $session->executeScript("document.cookie = 'navigationMode=edit; path=' + window.location.pathname + ';';");
    $session->reload();
    $this->activateTool('edit');
    $assert_session->waitForElement('css', $wrapper . ' .daedalus-edit-field-value');
    $session->executeScript(<<<JS
      window.__discardDoneCount = 0;
      const discardOriginal = jQuery.fn.daedalusEditIsDoneUpdating;
      jQuery.fn.daedalusEditIsDoneUpdating = function () {
        window.__discardDoneCount++;
        return discardOriginal.apply(this, arguments);
      };
    JS);
    $session->executeScript($reclick);
    $this->assertJsCondition($body_editing, 20000, 'The body editor opened for the discard leg.');
    $session->executeScript(<<<JS
      const doomedEditor = Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value;
      doomedEditor.setData('<p>Doomed edit.</p>');
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
    JS);
    $this->assertJsCondition('window.__discardDoneCount >= 1', 20000);
    $this->assertJsCondition(
      "document.querySelector('$wrapper').textContent.includes('Doomed edit.')",
      20000,
      'The doomed edit staged to tempstore.',
    );
    $this->assertTrue($tempstore->has($committed), 'The doomed edit re-created the tempstore entry.');

    $session->executeScript("document.querySelector('#daedalus-ui-discard-changes').click();");
    $dialog = $assert_session->waitForElement('css', '.discard-changes-dialog');
    $this->assertNotNull($dialog, 'The discard confirm dialog opened.');
    // Core's dialog behavior clones the confirm form's submit into the
    // jQuery UI buttonpane and hides the original, so the click targets
    // whichever rendered: the pane's button by its label, else the form's
    // own submit (a JS click submits either).
    $session->executeScript('window.__preDiscard = true;');
    $session->executeScript(<<<JS
      (() => {
        const dialog = document.querySelector('.discard-changes-dialog');
        const paneButton = [...dialog.querySelectorAll('.ui-dialog-buttonpane button')]
          .find((button) => button.textContent.trim() === 'Discard changes');
        const target = paneButton
          ?? dialog.querySelector('input[value="Discard changes"], form .form-submit');
        if (!target) {
          throw new Error('Discard changes submit not found in the confirm dialog.');
        }
        target.click();
      })();
      JS);
    $this->assertJsCondition(
      "window.__preDiscard === undefined"
      . " && document.body.textContent.includes('New body text.')"
      . " && !document.body.textContent.includes('Doomed edit.')",
      20000,
      'Discard restored the committed values on the reloaded page.',
    );
    $storage->resetCache([$nid]);
    $this->assertStringContainsString('New body text.', $storage->load($nid)->get('body')->value, 'The committed body survived the discard untouched.');
    $this->assertFalse($tempstore->has($committed), 'Discard deleted the tempstore entry.');

    // ——— The open edit is a DRAFT: the form path's three-way landing. ———
    // A foreign mutation during an open edit re-renders the canvas under
    // the editor. On the form path a value-changing op that targets the
    // root also EVICTS the root's cached form (BlueprintMutationClearForms:
    // InlineEntityFormAlter::update writes the form's whole cached entity,
    // so a stale form would clobber every foreign change on submit) and a
    // style op's key has no stamped element, so the WHOLE entity subtree
    // swaps. The engine captures the draft as the swap detaches the leaf
    // (DaedalusEditDraft, trigger 'unload') and, once the response has fully
    // applied (the eviction rides the piggybacked commands, AFTER the
    // markup swap), FormEditorBase.resume() waits out the panel's refetch,
    // re-resolves the fresh widget, and judges by the three-way rule: base
    // = the mirror value at edit open, remote = the mirror after. Equal
    // (a style scrub touched nothing the edit holds) re-projects the draft
    // and re-opens the edit on the swapped-in element; different (the very
    // field being edited changed) ENDS the edit with the remote standing,
    // nothing committed. Only a browser sees this seam: the op queue's
    // detach, the mirror replace, the eviction + refetch round trip and a
    // real editing surface all take part. These legs run on the node
    // after the discard (the committed page, no tempstore), re-entered in
    // Edit Mode so the blueprint mirror boots fresh and the ops below
    // enqueue through the spine with the plain node as the root.
    $this->enterEditModeViaCookies($node->toUrl(), 'edit');
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted on the plain node for the draft legs.',
    );
    $this->activateTool('edit');
    $assert_session->waitForElement('css', $wrapper . ' .daedalus-edit-field-value');
    $session->executeScript(<<<JS
      window.__draftDoneCount = 0;
      const draftOriginal = jQuery.fn.daedalusEditIsDoneUpdating;
      jQuery.fn.daedalusEditIsDoneUpdating = function () {
        window.__draftDoneCount++;
        return draftOriginal.apply(this, arguments);
      };
    JS);
    $node_uuid = $node->uuid();
    $body_editor_open = $body_editing . ' && Drupal.Daedalus.Edit.Ckeditor5Instances.size === 1';
    // The body widget is text_textarea_with_summary: the form's FIRST
    // textarea is the summary, so the value textarea is addressed by its
    // form-item id.
    $body_textarea = '[data-daedalus-edit-form-item-id="node::' . $nid . '::body::0::value"] textarea';
    $body_selectable = '[data-selectable="field:' . $node_uuid . ':body"]';

    // The body under a root style scrub (the reported bug through the
    // spine): with the edit open and a draft in the live canvas CKEditor,
    // a font-size scrub on the body key. The edit is stashed before the op
    // and compared by identity after: the same edit object resumed, no
    // re-open. A rejected op is recorded so a grammar or validation
    // failure reads as its message instead of a blind timeout.
    $session->executeScript($reclick);
    $this->assertJsCondition($body_editor_open, 20000, 'The body editor re-opened for the style-scrub leg with one live editor.');
    $session->executeScript(<<<JS
      (() => {
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value;
        editor.setData('<p>Draft typed across the scrub.</p>');
        window.__draftEdit = editing;
        window.__draftForm = editing.getForm();
        window.__draftPageElement = editing.getPageElementHandle();
        window.__draftError = undefined;
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_style',
            key: 'field:$node_uuid:body',
            properties: { 'font-size': '2rem' },
          }],
        })).catch((error) => {
          window.__draftError = error.message;
        });
      })();
      JS);
    // Resumed: the SAME edit, its form refetched (a fresh node form is the
    // panel's subject), ONE live CKEditor and it sits on the swapped-in
    // page element, holding the draft.
    $this->assertJsCondition(<<<JS
      (() => {
        if (window.__draftError !== undefined) { return true; }
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        if (!editing || editing !== window.__draftEdit) { return false; }
        const form = editing.getForm();
        const mount = document.getElementById('daedalus-edit-form-cache');
        const pageElement = editing.getPageElementHandle();
        const instances = Drupal.Daedalus.Edit.Ckeditor5Instances;
        const editor = pageElement?.dataset.ckeditor5Id ? instances.get(pageElement.dataset.ckeditor5Id) : null;
        return !!form && form !== window.__draftForm
          && form.dataset.daedalusEditFormId === 'node::{$nid}'
          && form.classList.contains('daedalus-edit-form--subject')
          && mount.dataset.daedalusEditSubject === 'node::{$nid}'
          && !!pageElement && pageElement !== window.__draftPageElement
          && pageElement.classList.contains('daedalus-edit-inline-editing')
          && instances.size === 1 && !!editor
          && editor.getData().includes('Draft typed across the scrub.');
      })()
      JS,
      20000,
      'The body edit resumed over the swapped-in entity: same edit, refetched subject form, one fresh canvas editor holding the draft.',
    );
    $draft_error = $session->evaluateScript('window.__draftError ?? null');
    $this->assertNull($draft_error, 'The style scrub on the body failed: ' . ($draft_error ?? ''));

    // Blur stages the draft through the refetched form, and the scrubbed
    // style stands on the re-rendered field: both halves survived.
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition('window.__draftDoneCount >= 1', 20000, 'Blur after the resume staged the draft.');
    $this->assertJsCondition(
      "document.querySelector('$wrapper').textContent.includes('Draft typed across the scrub.')"
      . " && getComputedStyle(document.querySelector('$body_selectable')).fontSize === '32px'",
      20000,
      'The re-rendered body shows the draft at the scrubbed 2rem (32px): the draft and the foreign style both survived.',
    );

    // Remote wins: a foreign update_element on the body itself. The remote
    // differs from the base, so the edit ENDS with nothing submitted; the
    // canvas and the refetched textarea already hold the remote value and
    // no editor is left behind.
    $session->executeScript($reclick);
    $this->assertJsCondition($body_editor_open, 20000, 'The body editor re-opened for the remote-wins leg.');
    $session->executeScript(<<<JS
      (() => {
        const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value;
        editor.setData('<p>Local draft the remote overrides.</p>');
        window.__draftError = undefined;
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_element',
            element: '$node_uuid',
            properties: { body: { value: '<p>Remote body</p>', format: 'daedalus_edit_formatted_text' } },
          }],
        })).catch((error) => {
          window.__draftError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__draftError !== undefined || (Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && Drupal.Daedalus.Edit.Ckeditor5Instances.size === 0"
      . " && document.querySelector('$wrapper').textContent.includes('Remote body')"
      . " && !document.querySelector('$wrapper').textContent.includes('Local draft the remote overrides.')"
      . " && !!document.querySelector('$body_textarea')"
      . " && document.querySelector('$body_textarea').value.includes('Remote body'))",
      20000,
      'The foreign body change ended the edit: the canvas and the refetched textarea hold the remote, no editor left.',
    );
    $draft_error = $session->evaluateScript('window.__draftError ?? null');
    $this->assertNull($draft_error, 'The foreign body update failed: ' . ($draft_error ?? ''));
    $this->assertSame(1, (int) $session->evaluateScript('window.__draftDoneCount'), 'Conceding to the remote submitted nothing.');

    // The plain-text tier under the same scrub: the subtitle's in-place
    // text mirrors into the form input on every keystroke, so its draft
    // is the input's value at the detach. The resume re-seeds it onto the
    // swapped-in page element AND the refetched input, and the input
    // listener re-binds to the new element, so typing on keeps reaching
    // the input the blur submits.
    $subtitle_wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::field_subtitle::value"]';
    $subtitle_leaf = $subtitle_wrapper . ' .daedalus-edit-field-value';
    $subtitle_input = '[data-daedalus-edit-form-item-wrapper-id="node::' . $nid . '::field_subtitle::value"] input';
    $session->executeScript(
      "document.querySelector('" . $subtitle_leaf . "')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_subtitle'"
      . " && document.querySelector('$subtitle_leaf')?.hasAttribute('contenteditable')",
      20000,
      'Clicking the subtitle opened its in-place text edit.',
    );
    $session->executeScript(<<<JS
      (() => {
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        const leaf = editing.getPageElementHandle();
        leaf.textContent = 'Subtitle draft';
        leaf.dispatchEvent(new Event('input', { bubbles: true }));
        window.__draftEdit = editing;
        window.__draftPageElement = leaf;
        window.__draftError = undefined;
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_style',
            key: 'field:$node_uuid:field_subtitle',
            properties: { 'font-size': '2rem' },
          }],
        })).catch((error) => {
          window.__draftError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(<<<JS
      (() => {
        if (window.__draftError !== undefined) { return true; }
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        if (!editing || editing !== window.__draftEdit) { return false; }
        const leaf = editing.getPageElementHandle();
        const input = document.querySelector('$subtitle_input');
        return !!leaf && leaf !== window.__draftPageElement
          && leaf.hasAttribute('contenteditable')
          && leaf.classList.contains('daedalus-edit-text-editing')
          && leaf.textContent === 'Subtitle draft'
          && !!input && input.value === 'Subtitle draft';
      })()
      JS,
      20000,
      'The subtitle edit resumed with its draft re-seeded onto the swapped-in element and the refetched input.',
    );
    $draft_error = $session->evaluateScript('window.__draftError ?? null');
    $this->assertNull($draft_error, 'The style scrub on the subtitle failed: ' . ($draft_error ?? ''));
    $session->executeScript(<<<JS
      const subtitleLeaf = Drupal.Daedalus.Edit.CurrentlyEditingElement.getPageElementHandle();
      subtitleLeaf.textContent = 'Subtitle draft, typed on';
      subtitleLeaf.dispatchEvent(new Event('input', { bubbles: true }));
    JS);
    $this->assertJsCondition(
      "document.querySelector('$subtitle_input')?.value === 'Subtitle draft, typed on'",
      20000,
      'Typing after the resume reaches the refetched input.',
    );
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition('window.__draftDoneCount >= 2', 20000, 'Blur after the subtitle resume staged the draft.');
    $this->assertJsCondition(
      "document.querySelector('$subtitle_wrapper')?.textContent.includes('Subtitle draft, typed on')",
      20000,
      'The subtitle re-rendered with the resumed draft.',
    );

    // ——— Cmd+Enter: the dialog's OK. ———
    // Escape cancels; Cmd+Enter commits the open edit AND leaves the
    // session for the Selector with the selection kept. Under the Edit
    // tool "selected" means "editing", so a commit that stayed armed would
    // land on the forbidden "selected but not editing"; the tool switch
    // runs the Edit tool's own disable (commit the open edit, refuse on an
    // invalid widget), so leaving IS the commit. The selenium Chrome is
    // Linux, where the binding's `mod` resolves to Ctrl.
    $session->executeScript($reclick);
    $this->assertJsCondition($body_editor_open, 20000, 'The body editor re-opened for the Cmd+Enter leg.');
    $session->executeScript(<<<JS
      Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value.setData('<p>Committed by Cmd+Enter.</p>');
    JS);
    $this->pressHotkey('Enter', ['ctrlKey' => TRUE]);
    $this->assertJsCondition('window.__draftDoneCount >= 3', 20000, 'Cmd+Enter committed the open edit.');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && document.querySelector('$wrapper').textContent.includes('Committed by Cmd+Enter.')"
      . " && Drupal.Daedalus.getCookieValue('activeTool') === 'selector'"
      . " && Drupal.Daedalus.Selection.getSelected() === 'field:$node_uuid:body'",
      20000,
      'Cmd+Enter staged the draft and left the session for the Selector with the body still selected.',
    );

    // ——— The outside press: chrome that keeps nothing. ———
    // A REAL click into chrome outside the canvas that declares no
    // data-keeps-edit (the page-elements panel header; the AI chat is the
    // other such surface) commits the open edit through the session's
    // capture-phase press table and then, the selection staying, leaves
    // for the Selector by the same rule as Cmd+Enter. The tree panel is
    // default-open on the left rail, clear of the fixed chrome, so the
    // pointer lands.
    $this->activateTool('edit');
    $session->executeScript($reclick);
    $this->assertJsCondition($body_editor_open, 20000, 'The body editor re-opened for the outside-press leg.');
    $session->executeScript(<<<JS
      Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value.setData('<p>Committed by the outside press.</p>');
    JS);
    // The header's title, not the row: the row's action cluster carries the
    // panel's close control, and a press there would close the panel.
    $tree_header = $session->getPage()->find('css', '#page_elements .daedalus-ui-panel__header h3');
    $this->assertNotNull($tree_header, 'The page-elements panel header title (non-keeping chrome) is on the page.');
    $this->assertTrue($tree_header->isVisible(), 'The page-elements panel is open for the real outside press.');
    $tree_header->click();
    $this->assertJsCondition('window.__draftDoneCount >= 4', 20000, 'The outside press committed the open edit.');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && document.querySelector('$wrapper').textContent.includes('Committed by the outside press.')"
      . " && Drupal.Daedalus.getCookieValue('activeTool') === 'selector'"
      . " && Drupal.Daedalus.Selection.getSelected() === 'field:$node_uuid:body'",
      20000,
      'The outside press staged the draft and left the session for the Selector with the body still selected.',
    );

    // ——— The graduated panel's own doors, on a page-built page. ———
    // The icon is DUMB (it toggles the panel on the TOP-LEVEL entity's form
    // under any tool, regardless of selection) and blueprint edits never
    // touch the panel. Both need a page with blueprint-edited blocks, so
    // this leg runs on the recipe's page bundle with a text block placed
    // through the real mutation batch a palette drop enqueues.
    $page = Node::create([
      'type' => 'page',
      'title' => 'Entity form sidebar page',
    ]);
    $page->save();
    $pid = (int) $page->id();

    $this->drupalGet($page->toUrl());
    $session->resizeWindow(1200, 1600);
    $this->enterEditModeViaCookies($page->toUrl(), 'selector');
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted on the page node.',
    );

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
                field_text_text: 'A blueprint-edited block',
                field_text_tag: 'h3',
              },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__placementError = error.message;
        });
      })();
      JS);
    // A loud placement: a grammar or validation failure surfaces as the
    // recorded error instead of a blind timeout.
    $this->assertJsCondition(
      "window.__placementError !== undefined"
      . " || !!document.querySelector(\"[data-selectable='block:$component_uuid'][data-daedalus-edit-editor]\")",
      20000,
      'The text block placement resolved.',
    );
    $placement_error = $session->evaluateScript('window.__placementError ?? null');
    $this->assertNull($placement_error, 'Text block placement failed: ' . ($placement_error ?? ''));
    $blueprint_leaf = "[data-selectable='block:$component_uuid'][data-daedalus-edit-editor]";

    // The (side, slot) cookie from the node scenario renders the panel open
    // here too, and the boot restore refills it with the PAGE node's form —
    // the panel's default subject follows the page it is on. Wait for that
    // to settle (it doubles as boot-restore coverage on a second bundle),
    // then close the panel: the icon leg needs the closed state.
    $this->assertJsCondition(
      "document.getElementById('daedalus-edit-form-cache')?.dataset.daedalusEditSubject === 'node::" . $pid . "'",
      20000,
      'The boot restore refilled the reloaded panel with the page node form.',
    );
    $session->executeScript(<<<JS
      (async () => {
        const panel = document.getElementById('daedalus_edit_entity_form');
        if (panel && !panel.classList.contains('daedalus-ui-hidden')) {
          await Drupal.Daedalus.SidebarManager.closeSidebar('daedalus_edit_entity_form');
        }
        window.__panelClosed = true;
      })();
      JS);
    $this->assertJsCondition(
      "window.__panelClosed === true"
      . " && document.getElementById('daedalus_edit_entity_form').classList.contains('daedalus-ui-hidden')",
      20000,
      'The panel is closed ahead of the icon leg.',
    );

    // Pointer tool + icon: the toggle opens the panel on the TOP-LEVEL
    // entity's form, fetched on demand — no selection, no Edit tool. The
    // form's content (its accordion details / form items) is reachable in
    // the panel.
    $session->executeScript(
      "document.querySelector('[data-sidebar-button-for=\"#daedalus_edit_entity_form\"]')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "(() => {"
      . " const panel = document.getElementById('daedalus_edit_entity_form');"
      . " const mount = document.getElementById('daedalus-edit-form-cache');"
      . " if (!panel || panel.classList.contains('daedalus-ui-hidden')) { return false; }"
      . " if (mount.dataset.daedalusEditSubject !== 'node::" . $pid . "') { return false; }"
      . " const form = mount.querySelector(':scope > .daedalus-edit-form--subject[data-daedalus-edit-form-id=\"node::" . $pid . "\"]');"
      . " return !!form && !!form.querySelector('details, .form-item');"
      . " })()",
      20000,
      'The icon opened the panel on the top-level entity form under the pointer tool, its content reachable.',
    );

    // The pinned header names the subject: the panel's daedalus-ui-panel__header
    // title is chrome the client subject model writes from the subject
    // form's data-daedalus-edit-form-title (a node form ships its bundle
    // label). This anchors the retarget assertions in the form-block leg
    // below: the flip only means something because the header verifiably
    // started on the top-level label.
    $this->assertJsCondition(
      "document.getElementById('daedalus-edit-form-panel-title')?.textContent === 'Page'",
      20000,
      'The pinned panel header names the top-level subject by its bundle label.',
    );

    // ——— The dissolved accordion: a pane widget stages to tempstore. ———
    // Sidebar-hosted forms dissolve core's advanced vertical_tabs into a
    // container of stacked details (DaedalusEditFormTrait::dissolveAdvancedTabs
    // + applyPanelGrammar), and the auto-submit traversal reads the
    // dissolved container's group registry instead of the vertical-tabs
    // structure. Kernel tests can't see the details DOM or the AJAX
    // plumbing, so the wire is proven here: the URL alias pane renders as
    // a stacked details inside the dissolved container with no
    // vertical-tabs DOM anywhere in the form, and changing the alias in
    // the opened pane fires the pane's auto-submit (a change event, no
    // inline editor involved) through the same stage-don't-save pipeline
    // as every field leg.
    $path_pane = '#daedalus-edit-form-cache .daedalus-edit-form--subject details.path-form';
    $this->assertJsCondition(
      "(() => {"
      . " const form = document.querySelector('#daedalus-edit-form-cache .daedalus-edit-form--subject');"
      . " const pane = form?.querySelector('.daedalus-edit-advanced details.path-form');"
      . " return !!pane && pane.classList.contains('daedalus-ui-details')"
      . " && !form.querySelector('.vertical-tabs, .vertical-tabs__menu');"
      . " })()",
      20000,
      'The URL alias pane is a stacked daedalus-ui-details inside the dissolved advanced container, with no vertical-tabs DOM.',
    );
    $session->executeScript("document.querySelector('$path_pane > summary').click();");
    $this->assertJsCondition(
      "document.querySelector('$path_pane').open === true",
      20000,
      'Clicking the pane summary opened the accordion pane.',
    );
    $session->executeScript(<<<JS
      window.__aliasDoneCount = 0;
      const aliasOriginal = jQuery.fn.daedalusEditIsDoneUpdating;
      jQuery.fn.daedalusEditIsDoneUpdating = function () {
        window.__aliasDoneCount++;
        return aliasOriginal.apply(this, arguments);
      };
    JS);
    $session->executeScript(<<<JS
      const aliasInput = document.querySelector('{$path_pane} input[name="path[0][alias]"]');
      aliasInput.value = '/accordion-staged-alias';
      aliasInput.dispatchEvent(new Event('change', { bubbles: true }));
    JS);
    $this->assertJsCondition('window.__aliasDoneCount >= 1', 20000);
    $storage->resetCache([$pid]);
    $page_fresh = $storage->load($pid);
    $this->assertSame(
      '/accordion-staged-alias',
      $tempstore->get($page_fresh)->get('path')->alias,
      'The pane auto-submit staged the alias edit in tempstore.',
    );
    $this->assertNull(
      $page_fresh->get('path')->alias,
      'The alias edit is staged in tempstore, not committed to the node.',
    );

    // ——— The summary line: core plumbing survives the dissolve. ———
    // The pane summaries ("Alias: /...", "Not in menu") are core's
    // drupalSetSummary / details-summarized-content plumbing, which the
    // deleted vertical-tabs consumer also painted; the dissolve keeps the
    // plain-details consumer, so the line must repaint from the edit above
    // — core's summaryUpdated event reaching the painted span is pure
    // browser wiring.
    $this->assertJsCondition(
      "document.querySelector('$path_pane summary .summary')"
      . "?.textContent.includes('/accordion-staged-alias')",
      20000,
      'The URL alias summary line repainted with the staged alias.',
    );

    // With the page form open, an ARMED BLUEPRINT-BLOCK EDIT leaves the
    // sidebar untouched: the subject model ignores non-form edits — the
    // blueprint path fetches no form and nothing else may reach the panel.
    $this->activateTool('edit');
    $forms_before = $session->evaluateScript(
      "document.getElementById('daedalus-edit-form-cache').children.length"
    );
    $session->executeScript(
      "document.querySelector(\"$blueprint_leaf\")"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing()',
      20000,
      'The armed click opened the blueprint block edit.',
    );
    $this->assertJsCondition(
      "(() => {"
      . " const panel = document.getElementById('daedalus_edit_entity_form');"
      . " const mount = document.getElementById('daedalus-edit-form-cache');"
      . " return !panel.classList.contains('daedalus-ui-hidden')"
      . " && mount.dataset.daedalusEditSubject === 'node::" . $pid . "'"
      . " && mount.children.length === $forms_before;"
      . " })()",
      20000,
      'The armed blueprint-block edit left the panel untouched: open, same subject, no form fetched.',
    );

    // ——— The form-edited block: block-form endpoint, block AS subject. ———
    // The fixture's form_block bundle has NO composed content class, so a
    // placement of it rides the sidebar block form. First end the open
    // blueprint edit (Escape cancels it; the session stays armed on the
    // Edit tool), then place a form_block through the same mutation batch,
    // referencing the first target node. The placement carries a VISIBLE
    // label (label_display rides the fixture's managed-keys declaration
    // through the op spine) so the block-label leg at the end of this
    // scenario has a rendered title element to edit.
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement === null',
      20000,
      'Escape ended the blueprint-block edit ahead of the form-block leg.',
    );
    $fb_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_element',
              uuid: '$fb_uuid',
              block_type: 'form_block',
              properties: {
                field_fb_text: 'Form block fixture text',
                field_fb_related: {$first_referenced->id()},
                label: 'Original block label',
                label_display: 'visible',
              },
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__fbPlacementError = error.message;
        });
      })();
      JS);
    $fb_wrapper = "[data-selectable='block:$fb_uuid']";
    $this->assertJsCondition(
      "window.__fbPlacementError !== undefined"
      . " || !!document.querySelector(\"$fb_wrapper\")",
      20000,
      'The form-block placement resolved.',
    );
    $fb_placement_error = $session->evaluateScript('window.__fbPlacementError ?? null');
    $this->assertNull($fb_placement_error, 'Form block placement failed: ' . ($fb_placement_error ?? ''));

    // The placement declares its form endpoint kind: the block wrapper (or
    // an ancestor placement wrapper) carries the block-form attribution the
    // leaves resolve their fetch route from — and the block's field leaves
    // rendered attributed for the form path, with NO blueprint editor.
    $fb_related_leaf = "$fb_wrapper [data-daedalus-edit-field-value-wrapper\$='::field_fb_related::value'] .daedalus-edit-field-value";
    $this->assertJsCondition(
      "(() => { const block = document.querySelector(\"$fb_wrapper\");"
      . " const leaf = document.querySelector(\"$fb_related_leaf\");"
      . " return !!block && !!block.closest('[data-daedalus-edit-form-endpoint=\"block-form\"]')"
      . " && !block.hasAttribute('data-daedalus-edit-editor')"
      . " && !!leaf; })()",
      20000,
      'The form-block placement wears the block-form endpoint declaration and form-path leaf attribution.',
    );

    // A fresh completion counter for this page load (the node scenario's
    // counter died with its page).
    $session->executeScript(<<<JS
      window.__fbDoneCount = 0;
      const fbOriginal = jQuery.fn.daedalusEditIsDoneUpdating;
      jQuery.fn.daedalusEditIsDoneUpdating = function () {
        window.__fbDoneCount++;
        return fbOriginal.apply(this, arguments);
      };
    JS);

    // Armed click on the block's related leaf: the endpoint declaration
    // routes the fetch to the block-form route (BlockFormController ->
    // UpdateBlockFormForEditTool), which answers with the same
    // append-into-the-form-cache contract as the entity form — and the
    // fetched BLOCK form retargets the panel's subject (block AS subject).
    $session->executeScript(
      "document.querySelector(\"$fb_related_leaf\")"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_fb_related'",
      20000,
      "Clicking the form-block's related leaf opened its form edit.",
    );
    $this->assertJsCondition(<<<JS
      (() => {
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        const wrapper = editing.getFormItemWrapper();
        const mount = document.getElementById('daedalus-edit-form-cache');
        const panel = document.getElementById('daedalus_edit_entity_form');
        const form = wrapper?.closest('.daedalus-edit-form');
        return !!wrapper
          && !!wrapper.closest('[data-sidebar-type="daedalus_edit_form"]')
          && mount.contains(wrapper)
          && !wrapper.classList.contains('daedalus-edit-hidden')
          && !!wrapper.querySelector('input.form-autocomplete')
          && !panel.classList.contains('daedalus-ui-hidden')
          && form.dataset.daedalusEditFormId.startsWith('block_content::')
          && mount.dataset.daedalusEditSubject === form.dataset.daedalusEditFormId
          && form.classList.contains('daedalus-edit-form--subject');
      })()
      JS,
      20000,
      'The sidebar block form arrived in the form cache with the widget revealed and the BLOCK as the panel subject.',
    );

    // The pinned header retargeted with the subject: the subject model
    // wrote the block form's shipped title ("@bundle_label Block") into
    // the panel-shell chrome — only a browser sees the header flip.
    $this->assertJsCondition(
      "document.getElementById('daedalus-edit-form-panel-title')?.textContent === 'Form Block Block'",
      20000,
      'The pinned header flipped to the block bundle label with the block as subject.',
    );

    // The widget edits: re-point the reference and blur — the same
    // stage-don't-save round trip, through the block form's update cycle.
    $session->executeScript(<<<JS
      const fbEditing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
      const fbInput = fbEditing.plugin.getFormItemInputs(fbEditing)[0];
      fbInput.value = 'Second referenced node ({$second_referenced->id()})';
      fbEditing.plugin.blur();
    JS);
    $this->assertJsCondition('window.__fbDoneCount >= 1', 20000);
    $assert_session->waitForElement('css', $fb_wrapper);
    $this->assertJsCondition(
      "document.querySelector(\"$fb_wrapper\")?.textContent.includes('Second referenced node')",
      20000,
      'The block re-rendered from tempstore with the re-pointed reference.',
    );
    // A committed edit keeps the cached block form AND the block subject:
    // only subject DEATH reverts the panel.
    $this->assertJsCondition(
      "document.getElementById('daedalus-edit-form-cache')?.dataset.daedalusEditSubject?.startsWith('block_content::')",
      20000,
      'The committed block edit left the block as the panel subject.',
    );

    // Revert on block-subject death: re-open the block edit and cancel it.
    // The cancel evicts the block's cached form (its values are unknowable
    // after a discard), and the OPEN panel reverts to the top-level entity
    // — whose form is already cached, so the revert is immediate.
    $session->executeScript(
      "document.querySelector(\"$fb_related_leaf\")"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_fb_related'",
      20000,
      'The block edit re-opened for the cancel leg.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "(() => {"
      . " const mount = document.getElementById('daedalus-edit-form-cache');"
      . " const panel = document.getElementById('daedalus_edit_entity_form');"
      . " return Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && !panel.classList.contains('daedalus-ui-hidden')"
      . " && mount.dataset.daedalusEditSubject === 'node::" . $pid . "'"
      . " && !mount.querySelector(':scope > [data-daedalus-edit-form-id^=\"block_content::\"]');"
      . " })()",
      20000,
      "The canceled block edit evicted the block form and the open panel reverted to the top-level subject.",
    );
    $this->assertJsCondition(
      "document.getElementById('daedalus-edit-form-panel-title')?.textContent === 'Page'",
      20000,
      'The pinned header reverted to the top-level bundle label on block-subject death.',
    );

    // ——— The block label: the block_property pseudo-field round trip. ———
    // A placement's LABEL is not a field on the block_content entity — it
    // is component configuration — yet with label_display visible it
    // renders as page content, so daedalus_edit attributes the title element
    // as a pseudo-field leaf (daedalus_edit_preprocess_block's
    // ...::label::block_property vocabulary) and routes its edit through
    // the block form: UpdateBlockFormForEditTool::attributeLabel() rehomes
    // core's settings[label] input under the same lookup ids, so the
    // string_textfield editor drives it like any field. The round trip:
    // in-place H2 edit -> blur -> the block form's update cycle writes the
    // component configuration to the layout tempstore -> updatePage
    // re-renders the title, re-attributed for the next edit.
    $fb_label_wrapper = "$fb_wrapper [data-daedalus-edit-field-value-wrapper\$='::label::block_property']";
    $fb_label_leaf = "$fb_label_wrapper .daedalus-edit-field-value";
    $this->assertJsCondition(
      "document.querySelector(\"$fb_label_leaf\")?.textContent.trim() === 'Original block label'",
      20000,
      'The form-block placement renders its visible label as an attributed pseudo-field leaf.',
    );
    $session->executeScript(
      "document.querySelector(\"$fb_label_leaf\")"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'label'"
      . " && document.querySelector(\"$fb_label_leaf\")?.hasAttribute('contenteditable')",
      20000,
      'Clicking the block label opened its in-place edit through the block form path.',
    );
    $session->executeScript(<<<JS
      const labelLeaf = Drupal.Daedalus.Edit.CurrentlyEditingElement.getPageElementHandle();
      labelLeaf.textContent = 'Renamed block label';
      labelLeaf.dispatchEvent(new Event('input', { bubbles: true }));
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
    JS);
    $this->assertJsCondition('window.__fbDoneCount >= 2', 20000);
    $this->assertJsCondition(
      "document.querySelector(\"$fb_label_leaf\")?.textContent.trim() === 'Renamed block label'",
      20000,
      'The label re-rendered from the staged component configuration, still attributed for the next edit.',
    );

    // ——— The draft over a block swap: the block's form is its own. ———
    // The form-edited block's related leaf edits in the PANEL widget (the
    // draft lives in the widget, nothing to capture from the canvas). A
    // style scrub on the block key swaps the block subtree (the edited
    // leaf detaches with it) but targets no block_content field, so
    // BlueprintMutationClearForms leaves the block's cached form alone.
    // The resume finds the same form, judges base == remote for the
    // reference, and re-opens the same edit on the swapped-in page element
    // with the widget's typed value untouched; blur commits it.
    $session->executeScript(
      "document.querySelector(\"$fb_related_leaf\")"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_fb_related'"
      . " && !Drupal.Daedalus.Edit.CurrentlyEditingElement.getFormItemWrapper()?.classList.contains('daedalus-edit-hidden')",
      20000,
      'The block edit re-opened for the block-swap draft leg with its widget revealed.',
    );
    $session->executeScript(<<<JS
      (() => {
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        const input = editing.plugin.getFormItemInputs(editing)[0];
        input.value = 'First referenced node ({$first_referenced->id()})';
        window.__fbEdit = editing;
        window.__fbForm = editing.getForm();
        window.__fbBlock = document.querySelector("$fb_wrapper");
        window.__fbError = undefined;
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_style',
            key: 'block:$fb_uuid',
            properties: { 'font-size': '2rem' },
          }],
        })).catch((error) => {
          window.__fbError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(<<<JS
      (() => {
        if (window.__fbError !== undefined) { return true; }
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        if (!editing || editing !== window.__fbEdit) { return false; }
        const block = document.querySelector("$fb_wrapper");
        const pageElement = editing.getPageElementHandle();
        const input = editing.plugin.getFormItemInputs(editing)[0];
        return editing.getForm() === window.__fbForm
          && !!block && block !== window.__fbBlock
          && !!pageElement && block.contains(pageElement)
          && pageElement.classList.contains('daedalus-edit-panel-editing')
          && !!input && input.value === 'First referenced node ({$first_referenced->id()})';
      })()
      JS,
      20000,
      'The block edit resumed over the swapped-in block on its untouched form, the widget still holding the typed value.',
    );
    $fb_error = $session->evaluateScript('window.__fbError ?? null');
    $this->assertNull($fb_error, 'The style scrub on the form block failed: ' . ($fb_error ?? ''));
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition('window.__fbDoneCount >= 3', 20000, 'Blur after the block resume staged the widget value.');
    $this->assertJsCondition(
      "document.querySelector(\"$fb_wrapper\")?.textContent.includes('First referenced node')",
      20000,
      'The block re-rendered from tempstore with the reference the re-projected draft committed.',
    );
  }

}
