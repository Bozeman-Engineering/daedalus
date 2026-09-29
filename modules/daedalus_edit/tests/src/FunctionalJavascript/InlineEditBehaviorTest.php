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
 * Drives daedalus_edit's entity-form path on a real page: one spine, two transports.
 *
 * The defining capability of daedalus_edit is "stage, don't save": clicking a
 * field on the rendered page edits it in place, and the change is written
 * to tempstore rather than committed to the entity, so iterative editing
 * creates no revisions until an explicit save. The entity form is a widget
 * host and value extractor, never a write path: every edit is one field of
 * one subject committed through the daedalus_blueprint op spine, by one of
 * two transports chosen by the leaf's kind (a blueprint-edited leaf sends a
 * value op; a form-edited leaf or a panel widget submits the subject's
 * cached form naming the one field, FormCommit::FIELD_INPUT, and the
 * server hands that field's items to the spine as an ExtractedValue), and
 * answered by the one mutation response (markup by key, the blueprint
 * mirror, an annotated history entry), applied through the app's one
 * pipeline and sequenced through the op queue. The entry gesture is Edit
 * Mode's one pointer grammar: a double-click at the leaf (or Enter on the
 * selected leaf) calls the one editor door, the real entity form loads in
 * the sidebar, and a CKEditor editor attaches to the rendered page element
 * itself, seeded with the field's raw value.
 *
 * This test drives that whole path plus the part that makes daedalus_edit
 * daedalus_edit: actually changing values and confirming they round through
 * the spine into tempstore without touching the saved entity, the editing
 * grammar around it (Tab across the canvas-panel seam, Escape, Cmd+Enter
 * and the Update button, a press into non-keeping chrome), the panel's
 * field-state judge and the Fill with sample verb, the panel widget
 * editor's generic commit triggers (a native change, a widget's own AJAX
 * round), the panel refresh from a foreign response's targets, and the
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
   * Installs a race-free counter of fully applied mutation responses.
   *
   * Every write (a form commit through the form transport, a value op, a
   * foreign mutation) is one mutation response applied through the app's
   * one pipeline, which reports the end of every application to the open
   * edit (EditSession.mutationApplied, the applied seam). Wrapping
   * it and counting invocations is a deterministic "the server staged it
   * and the page shows it" signal that does not depend on observing a
   * transient throbber between poll intervals. Per page load: a reload
   * kills the JS context.
   *
   * @param string $variable
   *   The window variable holding the count.
   */
  private function countApplied(string $variable): void {
    $this->getSession()->executeScript(<<<JS
      window.$variable = 0;
      (() => {
        const original = Drupal.Daedalus.EditSession.mutationApplied;
        Drupal.Daedalus.EditSession.mutationApplied = function () {
          window.$variable += 1;
          return original.apply(this, arguments);
        };
      })();
      JS);
  }

  /**
   * An inline field edit stages to tempstore without committing the entity.
   *
   * One fixture (the applied base recipe) drives daedalus_edit's signature
   * "stage, don't save" pipeline end to end on the fixture's 'plain'
   * bundle:
   * - Open the field: Edit Mode is entered and the body field double-clicked
   *   with a real pointer — the click commits the leaf as the selection, the
   *   dblclick at the leaf calls the editor door, which loads the real
   *   entity form in the sidebar and attaches a CKEditor editor to the page
   *   element itself, seeded with the current raw value.
   * - Edit and stage: a new value is typed into the inline editor and the
   *   field blurred. Blurring is what the editor turns into the form
   *   transport's commit: the form's own AJAX submit naming the body
   *   (FormCommit::FIELD_INPUT), the submit handler handing the body's
   *   items to the spine as an ExtractedValue (InlineEntityFormAlter::update
   *   -> FormCommit::commit, never ::save), and the response carrying the
   *   mutation payload as one daedalusBlueprintApplyResponse command that
   *   swaps the field by key and moves the history timeline.
   * - tempstore, not the entity: the saved node, reloaded fresh from storage,
   *   still has its original body. The edit lives only in tempstore, exactly as
   *   a real edit session leaves it before an explicit save.
   * - The root edit target: the theme's H1 has the pointer door (hover names
   *   the root row, a double-click edits the title in place, the leaf under
   *   the pointer routing the door since the H1 sits outside the entity
   *   wrapper) and re-renders through the mutation response (the page-title
   *   subscriber delivers the H1 under the label's key).
   * - The root row's Edit verb presents the root through the door: the Page
   *   form opens on the top-level subject with its first revealed widget as
   *   the open edit.
   * - Field state has one judge (panel-widget-visibility.js over the mirror
   *   and the canvas): the title's widget stays hidden behind its H1 leaf;
   *   the empty field_empty is revealed with the Fill with sample button and
   *   the verb applies to it. A change in the revealed widget commits through
   *   the form transport, the resulting leaf hides the widget and ends the
   *   panel edit, and emptying the field through the leaf reveals the widget
   *   (and the button) again.
   * - The seam: Tab from a panel widget walks back onto the last canvas leaf
   *   and forward onto the panel again; the Update button and Cmd+Enter from
   *   a focused panel widget commit and stay with the selection kept, and
   *   Enter on the selected leaf re-opens its editor.
   * - The honest panel and the slot contract: the related field
   *   (entity_reference_autocomplete, a widget with no canvas editor) opens
   *   with its real widget revealed in-flow in the right/BOTTOM form panel
   *   through the panel widget editor, while the page element keeps a
   *   selection outline, and the Styles inspector in the right/TOP slot stays
   *   open through the whole form lifecycle; its native change commits.
   * - The editing grammar: Tab commits, Escape cancels (restoring the
   *   snapshot, the cached form untouched) and the next Escape clears the
   *   selection, the panel and the mode untouched.
   * - The staged value survives a reload; the boot restore refills the
   *   server-rendered open panel.
   * - Fill with sample: the empty field materializes on the canvas through
   *   the spine's `_sample` request (the button on the widget's row), the
   *   widget hides, and emptying the field through its leaf brings the widget
   *   and the button back.
   * - The form-path media library: the media widget reveals in the panel;
   *   removing the selection and inserting a new one through the real
   *   library modal each commit through the AJAX-touched trigger, with no
   *   change event and no blur, and the canvas shows the new image without a
   *   reload.
   * - The commit half the staging legs stop short of: the top bar's Save
   *   waits for the op queue to drain and navigates through Tempstore::save;
   *   Discard throws a staged edit away through the confirm dialog.
   * - The open edit is a DRAFT (the one base's three-way landing): a style
   *   scrub leaves the field alone, so the body's draft rides into ONE fresh
   *   canvas CKEditor over the swapped-in element on the SAME cached form and
   *   blur commits it with the style intact; a foreign update_element on the
   *   body (remote != base) refetches the subject form (its targets name the
   *   edit's field) and the edit continues over the remote, the draft
   *   dropped, nothing submitted; the subtitle's typed text is re-seeded onto
   *   the new element and the input, which further typing keeps reaching.
   * - The panel refresh from targets: a foreign write to a revealed field
   *   refetches the subject form.
   * - Commit and stay: Cmd+Enter and a real press into chrome that keeps
   *   nothing commit the open edit with the selection kept.
   * - On a page-built page: the icon presents the root through the door and
   *   opens the Page form; a dissolved advanced pane's alias commits through the
   *   form transport (the wrapper identity follows the grouped widget); a
   *   blueprint-block edit leaves the panel untouched; the form-edited block
   *   (block AS subject) commits its reference and its label, a canceled
   *   dirty edit refetches the block form and keeps the block as subject, and
   *   a block-key style scrub resumes the open panel-widget edit over the
   *   swapped-in block with the typed value intact, which blur commits.
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
    // related field (entity_reference_autocomplete) has no canvas editor:
    // a widget that edits in the form panel, not in place.
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
    $node_uuid = $node->uuid();

    $this->drupalGet($node->toUrl());

    // A taller viewport than the WebDriver default. With the inspector open
    // the content column reflows, and on the default 1024x768 window the body
    // field's click point lands under the fixed edit-mode chrome, which
    // intercepts the real pointer click below. More vertical room keeps the
    // page content and the chrome apart without giving up the real click.
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

    // The body field has been attributed for inline editing (twig_events +
    // daedalus_edit): it carries the field-value wrapper and the per-item
    // editable id the editor door resolves.
    $wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::body::value"]';
    $assert_session->waitForElement('css', $wrapper . ' .daedalus-edit-field-value');

    // Double-click the body field itself with a real pointer — no indicator
    // overlay: the click half commits the leaf's field as the selection
    // (the one selection spine), and the dblclick at the leaf calls the
    // editor door, which opens its editor through the registered engine.
    $session->getPage()->find('css', $wrapper . ' .daedalus-edit-field-value')->doubleClick();

    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'field:$node_uuid:body'",
      20000,
      'The double-click\'s click half committed the body field as the selection.',
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

    $this->countApplied('__applied');

    // Type a new value into the inline editor and blur the field. Blurring is
    // what the InlineEditor plugin turns into the form transport's commit:
    // it diffs the editor's data against the snapshot and submits the form
    // naming the body, which stages the edit on the server through the
    // spine and answers the mutation payload.
    $session->executeScript(<<<JS
      const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value;
      editor.setData('<p>New body text.</p>');
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
    JS);

    // The edit has reached the server (and therefore tempstore) exactly when
    // the response has been applied.
    $this->assertJsCondition('window.__applied >= 1', 20000);

    // The field re-rendered with the new value (the response's markup swap
    // replaces the field's subtree by its key from the tempstore render).
    $assert_session->waitForElement('css', $wrapper);
    $this->assertStringContainsString(
      'New body text.',
      $session->getPage()->find('css', $wrapper)->getText(),
      'The edited field re-rendered with the new value.',
    );

    // The commit rode the spine: the history timeline gained a labeled
    // entry targeting the body field (undo swaps the field, not the entity).
    $this->assertJsCondition(<<<JS
      (() => {
        const entries = Drupal.Daedalus.History?.getState?.().entries ?? [];
        const latest = entries[entries.length - 1];
        return !!latest && latest.label === 'Edited Body'
          && Array.isArray(latest.targets) && latest.targets.length === 1
          && latest.targets[0].field_name === 'body';
      })()
      JS,
      20000,
      'The form commit annotated a history entry labeled by the field and targeted at it.',
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
    // field's editable leaf (PageTitleEditing + preprocess_page_title), and
    // the leaf has the pointer door every leaf has: the projection absorbed
    // the title field into the root row (the label presents AS the entity
    // row), so the resolver promotes the leaf's key to the root row inside
    // the pointer chain, the same mapping the editing gate applies for the
    // Tab walk. Hover on the H1 names the ROOT row, the double-click's
    // click half selects it, and the dblclick at the leaf opens the title's
    // in-place editor through the door, routed by the leaf under the
    // pointer (the H1 sits outside the entity wrapper, where no key search
    // finds it; the root's Enter keeps the key road and presents the panel
    // form, pinned by the root Edit verb leg below).
    $title_wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::title::value"]';
    $title_leaf = $title_wrapper . ' .daedalus-edit-field-value';
    $assert_session->waitForElement('css', $title_leaf);
    $session->executeScript(<<<JS
      document.querySelector('{$title_leaf}').dispatchEvent(
        new MouseEvent('mousemove', { bubbles: true, cancelable: true }),
      );
    JS);
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getHovered() === 'entity:$node_uuid'",
      20000,
      'Hovering the page title names the ROOT row (the label leaf promotes to the row that presents it).',
    );
    $this->dispatchDoubleClick($title_leaf);

    // The committed selection is the ROOT row, never the absorbed field key:
    // a selection key the panel cannot name would lead nowhere.
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'entity:$node_uuid'",
      20000,
      'Double-clicking the page title committed the ROOT row as the selection.',
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
    $this->assertJsCondition('window.__applied >= 2', 20000);

    // The commit REPLACED the H1 instead of removing it: the label is not
    // in the entity render, so the page-title subscriber delivers a fresh
    // H1 render under the label's key on the mutation response, and it
    // arrives re-attributed for the next edit.
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

    // ----- The root row's Edit verb: presents the root, opens the Page form. -----
    // The root's editor is the entity-form panel, never a canvas leaf: the
    // label may have no canvas render at all (daedalus_theme places no
    // page title block; the H1 above is this fixture's own placement). The
    // verb is the same editor door as Enter: the door, finding no canvas
    // leaf for the root, asks the engine to present the root as the
    // panel's subject and opens the panel's first revealed widget as the
    // edit, the selection moving to that widget's field key. Close the
    // panel first, so the verb is the only thing that can bring it back.
    $session->executeScript(
      "Drupal.Daedalus.SidebarManager.closeSidebar('daedalus_edit_entity_form');"
    );
    $this->assertJsCondition(
      "document.getElementById('daedalus_edit_entity_form').classList.contains('daedalus-ui-hidden')",
      20000,
      'The entity-form panel is closed ahead of the root Edit verb.',
    );

    // The seam: the verb registry's applies gate admits the root and the
    // verb's run calls the door. verbsFor is the same lookup every face
    // (chip button, context menu, tree row) uses, so this is the gesture
    // minus the pointer.
    $root_key = 'entity:' . $node_uuid;
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
      . " const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;"
      . " return !!panel && !panel.classList.contains('daedalus-ui-hidden')"
      . " && mount.dataset.daedalusEditSubject === 'node::" . $nid . "'"
      . " && !!mount.querySelector(':scope > .daedalus-edit-form--subject[data-daedalus-edit-form-id=\"node::" . $nid . "\"]')"
      . " && Drupal.Daedalus.EditSession.isEditing() === true"
      . " && !!editing && editing.plugin.id === 'panel_widget'"
      . " && (Drupal.Daedalus.Selection.getSelected() ?? '').startsWith('field:$node_uuid:');"
      . " })()",
      20000,
      'The root Edit verb presented the root through the door: the entity-form panel opened on the top-level subject with its first revealed widget as the open edit, the selection on that field.',
    );
    // Escape cancels the widget edit the door opened (nothing typed, so the
    // cancel refetches nothing) and keeps the selection: the judge leg
    // below reads field_empty's PLAIN face (the verb registry's edit-scope
    // gate: a selection verb such as Fill with sample applies only while
    // no live edit lands on the key), so the door's open edit must end
    // first.
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "Drupal.Daedalus.EditSession.isEditing() === false"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && (Drupal.Daedalus.Selection.getSelected() ?? '').startsWith('field:$node_uuid:')",
      20000,
      'Escape cancelled the panel widget edit the root door opened and kept the selection on its field.',
    );

    // ----- Field state has one judge: hidden iff a leaf, sample iff empty. -----
    // The server ships every field wrapper hidden (FormDefaultFieldAttributes)
    // and decides nothing else. One behavior (panel-widget-visibility.js)
    // judges every wrapper from the mirror value and the canvas: the placed
    // title block gives the title a leaf (the H1 edited above), so its
    // wrapper stays hidden; field_empty ships empty, so it has no leaf and
    // its widget is revealed carrying the Fill with sample button, and the
    // Fill with sample verb applies to its key (isEmptyValue over the
    // mirror's flagged stub, the judge's own reading).
    $subject_form = '#daedalus-edit-form-cache .daedalus-edit-form--subject';
    $title_form_item = '[data-daedalus-edit-form-item-wrapper-id="node::' . $nid . '::title::value"]';
    $empty_form_item = '[data-daedalus-edit-form-item-wrapper-id="node::' . $nid . '::field_empty::value"]';
    $empty_wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::field_empty::value"]';
    $empty_key = 'field:' . $node_uuid . ':field_empty';
    $empty_input = "{$subject_form} {$empty_form_item} input[name=\"field_empty[0][value]\"]";
    $sample_button = "{$subject_form} {$empty_form_item} .daedalus-edit-sample-fill";
    $empty_revealed = <<<JS
      (() => {
        const empty = document.querySelector('{$subject_form} {$empty_form_item}');
        return !!empty && !empty.classList.contains('daedalus-edit-hidden')
          && getComputedStyle(empty).visibility === 'visible'
          && !!document.querySelector('{$sample_button}')
          && !document.querySelector('{$empty_wrapper}')
          && Drupal.Daedalus.Verbs.verbsFor('{$empty_key}').some((verb) => verb.id === 'daedalus_edit.fill_sample');
      })()
      JS;
    $empty_hidden = <<<JS
      (() => {
        const empty = document.querySelector('{$subject_form} {$empty_form_item}');
        return !!empty && empty.classList.contains('daedalus-edit-hidden')
          && !document.querySelector('{$sample_button}')
          && !!document.querySelector('{$empty_wrapper} .daedalus-edit-field-value')
          && !Drupal.Daedalus.Verbs.verbsFor('{$empty_key}').some((verb) => verb.id === 'daedalus_edit.fill_sample');
      })()
      JS;
    $this->assertJsCondition(<<<JS
      (() => {
        const title = document.querySelector('{$subject_form} {$title_form_item}');
        return !!title && title.classList.contains('daedalus-edit-hidden') && $empty_revealed;
      })()
      JS,
      20000,
      'The title wrapper (its leaf is the H1) stays hidden; the empty field_empty wrapper is revealed with the Fill with sample button and the verb applies.',
    );

    // Press into the revealed widget: the open edit's press decision
    // resolves a panel item before anything else, with or without an edit
    // open, and edits it through the panel widget editor (the widget IS
    // the editor), the field's key becoming the selection and the editing
    // key.
    $session->executeScript(
      "document.querySelector('" . $empty_input . "')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.id === 'panel_widget'"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_empty'"
      . " && Drupal.Daedalus.Selection.getSelected() === '$empty_key'"
      . " && Drupal.Daedalus.EditSession.isEditing()",
      20000,
      'The press into the revealed widget opened a panel widget edit on the field key.',
    );

    // A native change is the panel widget editor's first generic trigger:
    // the form transport commits field_empty (one field of one subject)
    // and the response materializes its canvas leaf. The leaf's arrival
    // ends the panel edit (the leaf edits now) and the judge hides the
    // widget: a field with a leaf edits through the leaf, never through
    // the panel.
    $session->executeScript(<<<JS
      const orphanInput = document.querySelector('{$empty_input}');
      orphanInput.value = 'A panel-staged orphan value';
      orphanInput.dispatchEvent(new Event('change', { bubbles: true }));
    JS);
    $this->assertJsCondition('window.__applied >= 3', 20000);
    $tempstore = \Drupal::service('daedalus_tempstore.entity_tempstore_repository');
    $storage->resetCache([$nid]);
    $this->assertSame(
      'A panel-staged orphan value',
      $tempstore->get($storage->load($nid))->get('field_empty')->value,
      'The revealed widget committed the orphan field to tempstore through the form transport.',
    );
    $this->assertTrue(
      $storage->load($nid)->get('field_empty')->isEmpty(),
      'The orphan edit is staged in tempstore, not committed to the node.',
    );
    $this->assertJsCondition(
      $empty_hidden . ' && Drupal.Daedalus.Edit.CurrentlyEditingElement === null',
      20000,
      'The new canvas leaf hid the panel widget, withdrew the sample verb, and ended the panel edit.',
    );

    // ...and back through the leaf: emptying the field in place is the
    // judge's other direction. The emptied value removes the canvas render
    // (the response falls back to the whole-entity swap for a key with no
    // subtree) and the judge reveals the wrapper again, button and all.
    $empty_via_leaf = function () use ($session, $empty_wrapper): void {
      $this->dispatchDoubleClick($empty_wrapper . ' .daedalus-edit-field-value');
      $this->assertJsCondition(
        "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
        . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_empty'"
        . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.id === 'textfield'",
        20000,
        'Double-clicking the field_empty leaf opened its in-place edit.',
      );
      $session->executeScript(<<<JS
        const orphanLeaf = Drupal.Daedalus.Edit.CurrentlyEditingElement.getPageElementHandle();
        orphanLeaf.textContent = '';
        orphanLeaf.dispatchEvent(new Event('input', { bubbles: true }));
        Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
      JS);
    };
    $empty_via_leaf();
    $this->assertJsCondition('window.__applied >= 4', 20000);
    $this->assertJsCondition($empty_revealed, 20000, 'Emptying the field removed its canvas leaf and revealed its panel widget again, with the sample button.');
    $storage->resetCache([$nid]);
    $this->assertTrue(
      $tempstore->get($storage->load($nid))->get('field_empty')->isEmpty(),
      'The orphan field is staged empty again.',
    );

    // ----- The seam: Tab walks the canvas and the panel as one list. -----
    // A press into the revealed widget opens its panel edit; Shift+Tab from
    // its first control steps to the entry before it in session order, the
    // LAST canvas leaf (an edit opens on the canvas, the editing key leaves
    // the panel); Tab from that leaf's edge steps forward onto the first
    // panel widget again.
    $session->executeScript(
      "document.querySelector('" . $empty_input . "')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement?.info.fieldName === 'field_empty'"
      . " && document.activeElement === document.querySelector('$empty_input')",
      20000,
      'The panel widget edit re-opened with its input focused for the seam leg.',
    );
    $this->pressHotkey('Tab', ['shiftKey' => TRUE]);
    $this->assertJsCondition(<<<JS
      (() => {
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        const mount = document.getElementById('daedalus-edit-form-cache');
        const element = editing?.getPageElement();
        return !!editing && editing.info.fieldName !== 'field_empty'
          && !!element && !mount.contains(element);
      })()
      JS,
      20000,
      'Shift+Tab from the first panel widget stepped back onto the last canvas leaf.',
    );
    $this->pressHotkey('Tab');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement?.info.fieldName === 'field_empty'"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.id === 'panel_widget'"
      . " && Drupal.Daedalus.Selection.getSelected() === '$empty_key'",
      20000,
      'Tab from the last canvas leaf landed on the first panel widget again.',
    );

    // ----- The Update button: the visible commit door. -----
    // The widget reported input (the explicit floor's dirty signal); the
    // Update button's native press is the commit door (Cmd+Enter's visible
    // twin): the open edit commits through the form transport and stays,
    // the selection kept. Never the form's own submit.
    $session->executeScript(<<<JS
      const input = document.querySelector('{$empty_input}');
      input.value = 'Committed by the Update button';
      input.dispatchEvent(new Event('input', { bubbles: true }));
      document.querySelector('{$subject_form} .daedalus-edit-update-button').click();
    JS);
    $this->assertJsCondition('window.__applied >= 5', 20000);
    $storage->resetCache([$nid]);
    $this->assertSame(
      'Committed by the Update button',
      $tempstore->get($storage->load($nid))->get('field_empty')->value,
      'The Update button committed the panel widget through the form transport.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && Drupal.Daedalus.Selection.getSelected() === '$empty_key'",
      20000,
      'The Update button committed and stayed: no edit open, the field still selected.',
    );
    $this->assertJsCondition($empty_hidden, 20000, 'The committed value materialized the leaf and hid the widget.');

    // Enter on the selection: the selected key owns a canvas leaf now, so
    // the editor door (daedalus_ui's `edit.enter` binding, selection
    // context) opens the leaf's in-place editor; empty it through the
    // leaf for the next panel leg.
    $this->pressHotkey('Enter');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement?.info.fieldName === 'field_empty'"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.id === 'textfield'",
      20000,
      'Enter with the field_empty leaf selected opened its in-place editor through the door.',
    );
    $session->executeScript(<<<JS
      const orphanLeaf = Drupal.Daedalus.Edit.CurrentlyEditingElement.getPageElementHandle();
      orphanLeaf.textContent = '';
      orphanLeaf.dispatchEvent(new Event('input', { bubbles: true }));
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
    JS);
    $this->assertJsCondition('window.__applied >= 6', 20000);
    $this->assertJsCondition($empty_revealed, 20000, 'Emptied again: the widget and the sample button are back.');

    // ----- Cmd+Enter from a focused panel widget: the dialog's OK. -----
    // The selenium Chrome is Linux, where the binding's `mod` resolves to
    // Ctrl. The keystroke lands in the typing context (focus is in the
    // widget), which is why the session registers its twin there.
    $session->executeScript(
      "document.querySelector('" . $empty_input . "')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement?.plugin.id === 'panel_widget'"
      . " && document.activeElement === document.querySelector('$empty_input')",
      20000,
      'The panel widget edit re-opened for the Cmd+Enter leg.',
    );
    $session->executeScript(<<<JS
      const input = document.querySelector('{$empty_input}');
      input.value = 'Committed by Cmd+Enter';
      input.dispatchEvent(new Event('input', { bubbles: true }));
    JS);
    $this->pressHotkey('Enter', ['ctrlKey' => TRUE]);
    $this->assertJsCondition('window.__applied >= 7', 20000);
    $storage->resetCache([$nid]);
    $this->assertSame(
      'Committed by Cmd+Enter',
      $tempstore->get($storage->load($nid))->get('field_empty')->value,
      'Cmd+Enter committed the panel widget through the form transport.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && Drupal.Daedalus.Selection.getSelected() === '$empty_key'",
      20000,
      'Cmd+Enter committed and stayed: no edit open, the field still selected.',
    );

    // ----- The honest panel: a widget with no canvas editor. -----
    // The related field is an entity_reference_autocomplete: a widget that
    // looks like a form control wherever it is, so the panel widget editor
    // reveals the real widget in-flow in the form panel for the edit's
    // duration instead of projecting it over the page. Double-clicking the
    // leaf must therefore (a) open the edit with the widget visible in the
    // right/bottom form panel, (b) keep the page element visible with the
    // panel-editing outline, and (c) leave the right/top inspector open.
    // Cleared first so the double-click is the only gesture.
    $session->executeScript('Drupal.Daedalus.Selection.clear();');
    $related_wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid
      . '::field_related::target_id"]';
    $related_form_item = '[data-daedalus-edit-form-item-wrapper-id="node::' . $nid . '::field_related::target_id"]';
    $related_input = "{$subject_form} {$related_form_item} input.form-autocomplete";
    $this->assertJsCondition(
      "!!document.querySelector('{$related_wrapper} .daedalus-edit-field-value')",
      20000,
      'The related-content field value is on the canvas. Wrappers present: '
      . implode(' | ', (array) $session->evaluateScript(
        "[...document.querySelectorAll('[data-daedalus-edit-field-value-wrapper]')].map((el) => el.getAttribute('data-daedalus-edit-field-value-wrapper'))",
      )),
    );
    $this->dispatchDoubleClick($related_wrapper . ' .daedalus-edit-field-value');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_related'"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.id === 'panel_widget'",
      20000,
      'Double-clicking the related field opened its edit through the panel widget editor.',
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

    // Change the reference: the widget's native change commits through the
    // form transport (the autocomplete's chosen value leaves the widget
    // only through the form's own submit), and the edit ends with the
    // field's leaf re-rendered from tempstore.
    $session->executeScript(<<<JS
      const input = document.querySelector('{$related_input}');
      input.value = 'Second referenced node ({$second_referenced->id()})';
      input.dispatchEvent(new Event('change', { bubbles: true }));
    JS);
    $this->assertJsCondition('window.__applied >= 8', 20000);

    // The field re-rendered from tempstore with the new reference; the saved
    // node still points at the original. Same headline as the body leg:
    // staged, not committed.
    $assert_session->waitForElement('css', $related_wrapper);
    $this->assertJsCondition(
      "document.querySelector('$related_wrapper')?.textContent.includes('Second referenced node')",
      20000,
      'The related field re-rendered with the new reference.',
    );
    $storage->resetCache([$nid]);
    $saved = $storage->load($nid);
    $this->assertSame(
      (int) $first_referenced->id(),
      (int) $saved->get('field_related')->target_id,
      'The reference edit is staged in tempstore, not committed to the node.',
    );
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement?.plugin.blur();');
    $this->assertJsCondition('Drupal.Daedalus.Edit.CurrentlyEditingElement === null', 20000, 'The related edit ended.');

    // And the round trip (which swaps the field markup by key) did not
    // disturb the inspector either.
    $this->assertJsCondition(
      "(() => { const p = document.getElementById('daedalus_stylish_inspector');"
      . " return !!p && !p.classList.contains('daedalus-ui-hidden'); })()",
      20000,
      'The inspector is still open after the panel-widget edit committed.',
    );

    // The editing grammar around an open edit. Re-opening the editor uses
    // a dispatched double-click into the pointer grammar's document-level
    // handlers (the same three events the real first double-click above
    // sent): the sidebar form is open now and overlaps the field's
    // coordinates in the headless layout, so WebDriver cannot land a real
    // pointer on it.
    $body_leaf = $wrapper . ' .daedalus-edit-field-value';

    // The blurred editor's element can linger in the sidebar DOM, so "the
    // editor is open" is asserted on daedalus_edit's editing state, not markup.
    $body_editing = "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'body'";

    // Tab while editing commits the edit and advances: the body's edit ends.
    // The synthetic keydown reaches the app's one hotkey dispatcher; while
    // CKEditor holds focus the typing-context twin is what fires, so this
    // also proves the edit's keys survive the typing guard.
    $this->dispatchDoubleClick($body_leaf);
    $this->assertJsCondition($body_editing, 20000, 'Double-clicking the field re-opened its editor.');
    $this->pressHotkey('Tab');
    $this->assertJsCondition(
      "!Drupal.Daedalus.Edit.CurrentlyEditingElement || Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName !== 'body'",
      20000,
      'Tab committed the body edit through the dispatcher.',
    );
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement?.plugin.blur();');

    // Escape follows the dialog convention, one ladder: the first press
    // cancels the current edit (the snapshot is restored, nothing
    // submitted, and the cached form stays exactly as it is: its values
    // are known), the second clears the selection, with the panel and
    // Edit Mode untouched. Escape never switches anything and never
    // traverses.
    $this->dispatchDoubleClick($body_leaf);
    $this->assertJsCondition($body_editing, 20000, 'The editor re-opened for the Escape leg.');
    $session->executeScript("window.__formBeforeCancel = document.querySelector('$subject_form');");
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement === null'
      . " && Drupal.Daedalus.Selection.getSelected() === 'field:$node_uuid:body'",
      20000,
      'Escape while editing canceled the edit and kept the selection.',
    );
    $this->assertJsCondition(
      "(() => {"
      . " const panel = document.getElementById('daedalus_edit_entity_form');"
      . " const mount = document.getElementById('daedalus-edit-form-cache');"
      . " return !!panel && !panel.classList.contains('daedalus-ui-hidden')"
      . " && mount.dataset.daedalusEditSubject === 'node::" . $nid . "'"
      . " && document.querySelector('" . $subject_form . "') === window.__formBeforeCancel;"
      . " })()",
      20000,
      'The canceled canvas edit left the cached subject form untouched: no refetch, the panel stays open on it.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === null'
      . ' && Drupal.Daedalus.EditSession.isEditing() === false'
      . " && !document.getElementById('daedalus_edit_entity_form').classList.contains('daedalus-ui-hidden')"
      . " && document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'",
      20000,
      'The next Escape cleared the selection with the panel still open and Edit Mode unchanged.',
    );
    // The staged value survives a fresh request: re-requesting the node in Edit
    // Mode (the server-rendered cookie path) renders the new body, because
    // daedalus_edit swaps in the tempstore version of the entity when a page
    // renders in Edit Mode. This is the user-facing "stage" half: the change
    // persists across a reload while the committed entity stays untouched.
    $this->enterEditModeViaCookies($node->toUrl());
    $assert_session->pageTextContains('New body text.');
    $assert_session->pageTextNotContains('Original body text.');
    // The staged title too: the tempstore-swapped route entity feeds the
    // title resolver, so the theme's H1 renders the staged value.
    $assert_session->pageTextContains('A staged new title');
    $assert_session->pageTextContains('Committed by Cmd+Enter');

    // Exactly ONE element answers to the title's identity on the SSR
    // edit-mode render. Core's buildTitle() inlines the rendered title
    // FIELD inside the H1 (#is_page_title); daedalus_ui skips
    // attributing that inner render so the page-title element daedalus_edit
    // stamps stays the field's one carrier; duplicates would make the
    // form-swap and markup replacement ambiguous.
    $this->assertJsCondition(
      "document.querySelectorAll('[data-daedalus-edit-field-value-wrapper=\"node::" . $nid . "::title::value\"]').length === 1"
      . " && document.querySelectorAll('[data-selectable=\"field:" . $node_uuid . ":title\"]').length === 1",
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

    // The reload killed the previous JS context, so install a fresh
    // completion counter for the sample-fill and media legs below.
    $this->countApplied('__nodeApplied');

    // ——— Fill with sample: the empty field materializes on canvas. ———
    // field_empty holds the Cmd+Enter value (a leaf); empty it through the
    // leaf so the panel offers the sample fill again.
    $empty_via_leaf();
    $this->assertJsCondition('window.__nodeApplied >= 1', 20000);
    $this->assertJsCondition($empty_revealed, 20000, 'The empty field offers the Fill with sample button on its revealed widget.');

    // The button runs the spine's `_sample` request on the field: a sample
    // value is generated server-side (field_sample_value), staged to
    // tempstore as an ExtractedValue, and the response re-renders the
    // field ON the canvas, attributed for the next edit; the judge hides
    // the widget (the field is no longer empty) and the verb withdraws.
    $session->executeScript("document.querySelector('$sample_button').click();");
    $this->assertJsCondition('window.__nodeApplied >= 2', 20000);
    $sample_leaf = $assert_session->waitForElement('css', $empty_wrapper . ' .daedalus-edit-field-value');
    $this->assertNotNull($sample_leaf, 'The sample fill materialized the field on canvas.');
    $this->assertNotSame('', trim($sample_leaf->getText()), 'The materialized field carries a generated sample value.');
    $this->assertJsCondition($empty_hidden, 20000, 'The filled field hid its widget and withdrew the sample button and verb.');
    $storage->resetCache([$nid]);
    $this->assertFalse(
      $tempstore->get($storage->load($nid))->get('field_empty')->isEmpty(),
      'The sample value is staged in tempstore.',
    );
    $this->assertTrue(
      $storage->load($nid)->get('field_empty')->isEmpty(),
      'The materialized sample value is staged in tempstore, not committed.',
    );

    // ...and the button RETURNS when the field empties again: emptying
    // the field through its inline edit reverses both halves.
    $empty_via_leaf();
    $this->assertJsCondition('window.__nodeApplied >= 3', 20000);
    $this->assertJsCondition($empty_revealed, 20000, 'Emptying the field removed its canvas render and the Fill with sample button returned.');

    // ——— The form-path media library. ———
    // field_media rides media_library_widget, a widget with no canvas
    // editor: the panel widget editor reveals it in the panel
    // (FormMediaFieldAttributes serializes the widget's selection onto the
    // form item and scopes the widget's AJAX wrappers and library-opener
    // parameters to THIS widget, so identical widgets on other cached forms
    // cannot swallow the update). The editor knows nothing about the widget:
    // each of the widget's own AJAX rounds (remove, insert) is the
    // AJAX-touched trigger, committing through the form transport with no
    // change event and no blur.
    $media_wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::field_media::target_id"]';
    $media_form_item = '[data-daedalus-edit-form-item-wrapper-id="node::' . $nid . '::field_media::target_id"]';
    $this->dispatchDoubleClick($media_wrapper . ' .daedalus-edit-field-value');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_media'"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.id === 'panel_widget'",
      20000,
      'Double-clicking the media field opened its edit through the panel widget editor.',
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

    // Remove the current item: the widget-scoped AJAX wrapper rebuilds just
    // this widget, and that round is the AJAX-touched trigger: the emptied
    // field commits, its leaf leaves the canvas (the whole-entity swap for
    // a key with no subtree) and the edit that rode the leaf ends; the
    // widget stays revealed (no leaf) for the insert.
    $session->executeScript("jQuery('$media_form_item input[name^=\"field_media-0-media-library-remove-button\"]').mousedown();");
    $this->assertJsCondition('window.__nodeApplied >= 4', 20000, 'Removing the media selection committed through the AJAX-touched trigger.');
    $this->assertJsCondition(
      "!document.querySelector('$media_wrapper')"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && !document.querySelector('$media_form_item').classList.contains('daedalus-edit-hidden')",
      20000,
      'The emptied media field left the canvas, its edit ended, and the widget stays revealed.',
    );
    $storage->resetCache([$nid]);
    $this->assertTrue(
      $tempstore->get($storage->load($nid))->get('field_media')->isEmpty(),
      'The removal is staged in tempstore.',
    );

    // Open the library from the revealed widget, pick the second image,
    // Insert selected: the selection lands as the widget's own update
    // round (ViewsFormMediaLibraryWidgetAlter routes it to THIS widget),
    // and that round commits through the same trigger.
    $open_button = $assert_session->waitForElement('css', "$media_form_item .js-media-library-open-button", 20000);
    $this->assertNotNull($open_button, 'The revealed media widget offers the Add media opener.');
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
    $this->assertJsCondition('window.__nodeApplied >= 5', 20000, 'Inserting the selection committed through the AJAX-touched trigger.');
    $this->assertJsCondition(
      "!document.querySelector('.media-library-widget-modal')"
      . " && !!document.querySelector('$media_wrapper img[src*=image-2]')"
      . " && document.querySelector('$media_form_item').classList.contains('daedalus-edit-hidden')",
      20000,
      'The media field re-rendered from tempstore with the second image, no change event and no blur, and the widget hid behind the new leaf.',
    );
    $storage->resetCache([$nid]);
    $this->assertSame(
      $first_media_id,
      (int) $storage->load($nid)->get('field_media')->target_id,
      'The media swap is staged in tempstore, not committed to the node.',
    );

    // ——— Save: the top bar commits the staged batch and drops Edit Mode. ———
    // Everything above stopped at "staged, not committed"; the Save button
    // is the committing gesture. It waits for the op queue to drain (a
    // commit still on the wire must land first) and navigates to
    // Tempstore::save, an adapter over the storage handler's commit door:
    // the working copy (the staged entity) is loaded and committed as it
    // is, no field copied or filtered, because the spine judged every
    // write on the way in. The door marks ONE new revision (the plain
    // bundle's new_revision setting; revision metadata stamped to the
    // editor, not the prior author), saves, deletes the tempstore entry,
    // and the controller redirects back with a path-scoped
    // navigationMode=none cookie: the page lands committed and OUT of
    // Edit Mode.
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
    $assert_session->waitForElement('css', $wrapper . ' .daedalus-edit-field-value');
    $this->countApplied('__discardApplied');
    $this->dispatchDoubleClick($body_leaf);
    $this->assertJsCondition($body_editing, 20000, 'The body editor opened for the discard leg.');
    $session->executeScript(<<<JS
      const doomedEditor = Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value;
      doomedEditor.setData('<p>Doomed edit.</p>');
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
    JS);
    $this->assertJsCondition('window.__discardApplied >= 1', 20000);
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

    // ——— The open edit is a DRAFT: the one base's three-way landing. ———
    // A foreign mutation during an open edit re-renders the canvas under
    // the editor. The engine captures the draft as the swap detaches the
    // leaf (DaedalusEditDraft, trigger 'unload') and, once the response has
    // fully applied, refreshes the panel from the response's targets (a
    // target naming the edit's field refetches the subject form, so the
    // widget-backed store reads the remote) and hands the draft to
    // resume(), which judges by the three-way rule: base = the widget's
    // value at edit open, remote = the widget's value now. Equal (a style
    // scrub touched nothing the edit holds) re-projects the draft over the
    // swapped-in element on the SAME cached form; different (the very
    // field being edited changed) drops the draft and the edit continues
    // over the remote, nothing committed. Only a browser sees this seam:
    // the op queue's detach, the mirror replace, the refetch round trip and
    // a real editing surface all take part. These legs run on the node
    // after the discard (the committed page, no tempstore), re-entered in
    // Edit Mode so the blueprint mirror boots fresh and the ops below
    // enqueue through the spine with the plain node as the root.
    $this->enterEditModeViaCookies($node->toUrl());
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted on the plain node for the draft legs.',
    );
    $assert_session->waitForElement('css', $wrapper . ' .daedalus-edit-field-value');
    $this->countApplied('__draftApplied');
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
    $this->dispatchDoubleClick($body_leaf);
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
    // Resumed: the SAME edit on the SAME cached form (a style write names
    // no field, so nothing refetched), ONE live CKEditor and it sits on
    // the swapped-in page element, holding the draft.
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
        return !!form && form === window.__draftForm
          && form.classList.contains('daedalus-edit-form--subject')
          && mount.dataset.daedalusEditSubject === 'node::{$nid}'
          && !!pageElement && pageElement !== window.__draftPageElement
          && pageElement.classList.contains('daedalus-edit-inline-editing')
          && instances.size === 1 && !!editor
          && editor.getData().includes('Draft typed across the scrub.');
      })()
      JS,
      20000,
      'The body edit resumed over the swapped-in entity: same edit, same cached form, one fresh canvas editor holding the draft.',
    );
    $draft_error = $session->evaluateScript('window.__draftError ?? null');
    $this->assertNull($draft_error, 'The style scrub on the body failed: ' . ($draft_error ?? ''));

    // Blur commits the draft through the form transport, and the scrubbed
    // style stands on the re-rendered field: both halves survived.
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition('window.__draftApplied >= 2', 20000, 'Blur after the resume committed the draft.');
    $this->assertJsCondition(
      "document.querySelector('$wrapper').textContent.includes('Draft typed across the scrub.')"
      . " && getComputedStyle(document.querySelector('$body_selectable')).fontSize === '32px'",
      20000,
      'The re-rendered body shows the draft at the scrubbed 2rem (32px): the draft and the foreign style both survived.',
    );

    // Remote wins: a foreign update_element on the body itself. Its target
    // names the edit's field, so the subject form is refetched (the
    // textarea now holds the remote); the remote differs from the base, so
    // the draft is dropped and the edit CONTINUES over the remote on the
    // fresh form, nothing submitted. Escape then cancels it cleanly.
    $this->dispatchDoubleClick($body_leaf);
    $this->assertJsCondition($body_editor_open, 20000, 'The body editor re-opened for the remote-wins leg.');
    $session->executeScript(<<<JS
      (() => {
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value;
        editor.setData('<p>Local draft the remote overrides.</p>');
        window.__draftEdit = editing;
        window.__draftForm = editing.getForm();
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
    $this->assertJsCondition(<<<JS
      (() => {
        if (window.__draftError !== undefined) { return true; }
        const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
        if (!editing || editing !== window.__draftEdit) { return false; }
        const form = editing.getForm();
        const pageElement = editing.getPageElementHandle();
        const instances = Drupal.Daedalus.Edit.Ckeditor5Instances;
        const editor = pageElement?.dataset.ckeditor5Id ? instances.get(pageElement.dataset.ckeditor5Id) : null;
        const textarea = document.querySelector('$body_textarea');
        return !!form && form !== window.__draftForm
          && form.classList.contains('daedalus-edit-form--subject')
          && !!textarea && textarea.value.includes('Remote body')
          && instances.size === 1 && !!editor
          && editor.getData().includes('Remote body')
          && !editor.getData().includes('Local draft the remote overrides.');
      })()
      JS,
      20000,
      'The foreign body change refetched the subject form and the edit continued over the remote: the fresh textarea and the one live editor hold the remote, the draft dropped.',
    );
    $draft_error = $session->evaluateScript('window.__draftError ?? null');
    $this->assertNull($draft_error, 'The foreign body update failed: ' . ($draft_error ?? ''));
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && Drupal.Daedalus.Edit.Ckeditor5Instances.size === 0"
      . " && document.querySelector('$wrapper').textContent.includes('Remote body')"
      . " && !document.querySelector('$wrapper').textContent.includes('Local draft the remote overrides.')",
      20000,
      'Escape canceled the continued edit with the remote standing on the canvas, no editor left.',
    );
    $this->assertSame(3, (int) $session->evaluateScript('window.__draftApplied'), 'Nothing was submitted for the remote-won edit: only the foreign op applied.');

    // The plain-text tier under the same scrub: the subtitle's in-place
    // text mirrors into the form input on every keystroke, so its draft
    // is the input's value at the detach. The resume writes it back onto
    // the swapped-in page element AND the input (its store), and the input
    // listener re-binds to the new element, so typing on keeps reaching
    // the input the commit submits.
    $subtitle_wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::field_subtitle::value"]';
    $subtitle_leaf = $subtitle_wrapper . ' .daedalus-edit-field-value';
    $subtitle_input = '[data-daedalus-edit-form-item-wrapper-id="node::' . $nid . '::field_subtitle::value"] input';
    $this->dispatchDoubleClick($subtitle_leaf);
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_subtitle'"
      . " && document.querySelector('$subtitle_leaf')?.hasAttribute('contenteditable')",
      20000,
      'Double-clicking the subtitle opened its in-place text edit.',
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
      'The subtitle edit resumed with its draft re-projected onto the swapped-in element and the input.',
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
      'Typing after the resume reaches the input.',
    );
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition('window.__draftApplied >= 5', 20000, 'Blur after the subtitle resume committed the draft.');
    $this->assertJsCondition(
      "document.querySelector('$subtitle_wrapper')?.textContent.includes('Subtitle draft, typed on')",
      20000,
      'The subtitle re-rendered with the resumed draft.',
    );

    // ——— The panel refresh from targets: a foreign write to a revealed field. ———
    // field_empty is committed empty, so its widget is revealed. A foreign
    // update_element on it (an AI turn's shape) names the field in its
    // targets: the panel refetches the subject form (the client-side
    // successor of server-side form eviction), and the judge hides the
    // fresh widget behind the leaf the write materialized, its input
    // holding the remote.
    $this->assertJsCondition($empty_revealed, 20000, 'field_empty is revealed ahead of the foreign write.');
    $session->executeScript(<<<JS
      (() => {
        window.__refreshForm = document.querySelector('{$subject_form}');
        window.__refreshError = undefined;
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_element',
            element: '$node_uuid',
            properties: { field_empty: 'A foreign value' },
          }],
        })).catch((error) => {
          window.__refreshError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(<<<JS
      (() => {
        if (window.__refreshError !== undefined) { return true; }
        const form = document.querySelector('{$subject_form}');
        const input = document.querySelector('{$empty_input}');
        return !!form && form !== window.__refreshForm
          && !!input && input.value === 'A foreign value'
          && $empty_hidden;
      })()
      JS,
      20000,
      'The foreign write to a revealed field refetched the subject form: the fresh widget holds the remote and hides behind the new leaf.',
    );
    $refresh_error = $session->evaluateScript('window.__refreshError ?? null');
    $this->assertNull($refresh_error, 'The foreign write to field_empty failed: ' . ($refresh_error ?? ''));

    // ——— Cmd+Enter on a canvas edit: the dialog's OK. ———
    // Escape cancels; Cmd+Enter commits the open edit and STAYS, the
    // selection kept and no edit open (there is no session to leave). The
    // keystroke lands in the typing context (CKEditor holds focus), which
    // is why the edit registers its twin there.
    $this->dispatchDoubleClick($body_leaf);
    $this->assertJsCondition($body_editor_open, 20000, 'The body editor re-opened for the Cmd+Enter leg.');
    $session->executeScript(<<<JS
      Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value.setData('<p>Committed by Cmd+Enter.</p>');
    JS);
    $this->pressHotkey('Enter', ['ctrlKey' => TRUE]);
    $this->assertJsCondition('window.__draftApplied >= 7', 20000, 'Cmd+Enter committed the open edit.');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . ' && Drupal.Daedalus.EditSession.isEditing() === false'
      . " && document.querySelector('$wrapper').textContent.includes('Committed by Cmd+Enter.')"
      . " && Drupal.Daedalus.Selection.getSelected() === 'field:$node_uuid:body'",
      20000,
      'Cmd+Enter committed the draft and stayed: no edit open, the body still selected.',
    );

    // ——— The outside press: chrome that keeps nothing. ———
    // A REAL click into chrome outside the canvas that declares no
    // data-keeps-edit (the page-elements panel header; the AI chat is the
    // other such surface) commits the open edit through the open edit's
    // capture-phase press decision, the selection staying (the press is
    // outside the canvas, so the pointer grammar lands nothing). The tree
    // panel is default-open on the left rail, clear of the fixed chrome,
    // so the pointer lands.
    $this->dispatchDoubleClick($body_leaf);
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
    $this->assertJsCondition('window.__draftApplied >= 8', 20000, 'The outside press committed the open edit.');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && document.querySelector('$wrapper').textContent.includes('Committed by the outside press.')"
      . " && Drupal.Daedalus.Selection.getSelected() === 'field:$node_uuid:body'",
      20000,
      'The outside press committed the draft and kept the body selected with no edit open.',
    );

    // ——— The graduated panel's own doors, on a page-built page. ———
    // The icon opens the panel on the TOP-LEVEL entity's form and presents
    // the root through the editor door, and blueprint edits never touch
    // the panel. Both need a page with blueprint-edited blocks, so this
    // leg runs on the recipe's page bundle with a text block placed
    // through the real mutation batch a palette drop enqueues.
    $page = Node::create([
      'type' => 'page',
      'title' => 'Entity form sidebar page',
    ]);
    $page->save();
    $pid = (int) $page->id();

    $this->drupalGet($page->toUrl());
    $session->resizeWindow(1200, 1600);
    $this->enterEditModeViaCookies($page->toUrl());
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

    // The icon: the toggle opens the panel on the TOP-LEVEL entity's form,
    // fetched on demand, selects the root row and presents the root
    // through the editor door, which opens the panel's first revealed
    // widget as the edit (the selection moving to that widget's field key)
    // so the panel's widgets join the Tab walk. The form's content (its
    // accordion details / form items) is reachable in the panel. The
    // widget edit is then cancelled through Escape so the pane leg below
    // starts with no edit open, the panel standing.
    $page_uuid = $page->uuid();
    $session->executeScript(
      "document.querySelector('[data-sidebar-button-for=\"#daedalus_edit_entity_form\"]')"
      . ".dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));"
    );
    $this->assertJsCondition(
      "(() => {"
      . " const panel = document.getElementById('daedalus_edit_entity_form');"
      . " const mount = document.getElementById('daedalus-edit-form-cache');"
      . " const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;"
      . " if (!panel || panel.classList.contains('daedalus-ui-hidden')) { return false; }"
      . " if (mount.dataset.daedalusEditSubject !== 'node::" . $pid . "') { return false; }"
      . " const form = mount.querySelector(':scope > .daedalus-edit-form--subject[data-daedalus-edit-form-id=\"node::" . $pid . "\"]');"
      . " return !!form && !!form.querySelector('details, .form-item')"
      . " && (Drupal.Daedalus.Selection.getSelected() ?? '').startsWith('field:$page_uuid:')"
      . " && Drupal.Daedalus.EditSession.isEditing() === true"
      . " && !!editing && editing.plugin.id === 'panel_widget';"
      . " })()",
      20000,
      'The icon opened the panel on the top-level entity form, its content reachable, and presented the root through the door: its first revealed widget is the open edit.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing() === false'
      . " && !document.getElementById('daedalus_edit_entity_form').classList.contains('daedalus-ui-hidden')",
      20000,
      'Escape cancelled the presented widget edit with the panel still open.',
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

    // ——— The dissolved accordion: a pane widget commits to tempstore. ———
    // Sidebar-hosted forms dissolve core's advanced vertical_tabs into a
    // container of stacked details (DaedalusEditFormTrait::dissolveAdvancedTabs
    // + applyPanelGrammar). The path widget's delta is a details grouped
    // into that container, so its field's wrapper identity FOLLOWS the
    // grouped widget (followGroupedWidgets): the pane is the path field's
    // wrapper, revealed by the judge (no leaf), and a change in the opened
    // pane commits the path field through the form transport like every
    // field leg. Kernel tests can't see the details DOM or the AJAX
    // plumbing, so the wire is proven here.
    $this->countApplied('__pageApplied');
    $path_pane = '#daedalus-edit-form-cache .daedalus-edit-form--subject details.path-form';
    $this->assertJsCondition(
      "(() => {"
      . " const form = document.querySelector('#daedalus-edit-form-cache .daedalus-edit-form--subject');"
      . " const pane = form?.querySelector('.daedalus-edit-advanced details.path-form');"
      . " return !!pane && pane.classList.contains('daedalus-ui-details')"
      . " && pane.dataset.daedalusEditFormItemWrapperId === 'node::" . $pid . "::path::alias'"
      . " && !pane.classList.contains('daedalus-edit-hidden')"
      . " && !form.querySelector('.vertical-tabs, .vertical-tabs__menu');"
      . " })()",
      20000,
      'The URL alias pane is a stacked daedalus-ui-details inside the dissolved advanced container carrying the path field\'s wrapper identity, revealed, with no vertical-tabs DOM.',
    );
    $session->executeScript("document.querySelector('$path_pane').open = true;");
    $this->assertJsCondition(
      "document.querySelector('$path_pane').open === true",
      20000,
      'The accordion pane opened.',
    );
    $session->executeScript(<<<JS
      const aliasInput = document.querySelector('{$path_pane} input[name="path[0][alias]"]');
      aliasInput.value = '/accordion-staged-alias';
      aliasInput.dispatchEvent(new Event('change', { bubbles: true }));
    JS);
    $this->assertJsCondition('window.__pageApplied >= 1', 20000);
    $storage->resetCache([$pid]);
    $page_fresh = $storage->load($pid);
    $this->assertSame(
      '/accordion-staged-alias',
      $tempstore->get($page_fresh)->get('path')->alias,
      'The pane widget committed the alias through the form transport.',
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

    // With the page form open, a BLUEPRINT-BLOCK EDIT leaves the sidebar
    // untouched: the subject model ignores non-form edits — the blueprint
    // path fetches no form and nothing else may reach the panel. The block
    // sits inside a section, so the gesture is Cmd+double-click: the deep
    // clicks resolve the block under the pointer directly (a bare click
    // would stop at the section) and the dblclick at the leaf is the door.
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement?.plugin.blur();');
    $forms_before = $session->evaluateScript(
      "document.getElementById('daedalus-edit-form-cache').children.length"
    );
    $this->dispatchDoubleClick($blueprint_leaf, ['metaKey' => TRUE]);
    $this->assertJsCondition(
      'Drupal.Daedalus.EditSession.isEditing()'
      . " && Drupal.Daedalus.Selection.getSelected() === 'block:$component_uuid'",
      20000,
      'Cmd+double-click opened the blueprint block edit.',
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
      'The blueprint-block edit left the panel untouched: open, same subject, no form fetched.',
    );

    // ——— The form-edited block: block-form endpoint, block AS subject. ———
    // The fixture's form_block bundle has NO composed content class, so a
    // placement of it rides the sidebar block form. First end the open
    // blueprint edit (Escape cancels it, the selection standing), then
    // place a form_block through the same mutation batch,
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
    $fb_related_leaf = "$fb_wrapper [data-daedalus-edit-field-value-wrapper\$='::field_fb_related::target_id'] .daedalus-edit-field-value";
    $this->assertJsCondition(
      "(() => { const block = document.querySelector(\"$fb_wrapper\");"
      . " const leaf = document.querySelector(\"$fb_related_leaf\");"
      . " return !!block && !!block.closest('[data-daedalus-edit-form-endpoint=\"block-form\"]')"
      . " && !block.hasAttribute('data-daedalus-edit-editor')"
      . " && !!leaf; })()",
      20000,
      'The form-block placement wears the block-form endpoint declaration and form-path leaf attribution.',
    );

    // Cmd+double-click on the block's related leaf (deep: the leaf's own
    // key under the section, then the door): the endpoint declaration
    // routes the fetch to the block-form route (BlockFormController ->
    // PanelUpdateBlockForm), which answers with the same
    // append-into-the-form-cache contract as the entity form — and the
    // fetched BLOCK form retargets the panel's subject (block AS subject).
    $this->dispatchDoubleClick($fb_related_leaf, ['metaKey' => TRUE]);
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_fb_related'"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.id === 'panel_widget'",
      20000,
      "Cmd+double-clicking the form-block's related leaf opened its form edit through the panel widget editor.",
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

    // The widget edits: re-point the reference and let its native change
    // commit — the same stage-don't-save round trip, through the block
    // form's transport (the block as the subject, the component as the
    // spine element).
    $fb_input = "#daedalus-edit-form-cache .daedalus-edit-form--subject [data-daedalus-edit-form-item-wrapper-id\$='::field_fb_related::target_id'] input.form-autocomplete";
    $session->executeScript(<<<JS
      const fbInput = document.querySelector("{$fb_input}");
      fbInput.value = 'Second referenced node ({$second_referenced->id()})';
      fbInput.dispatchEvent(new Event('change', { bubbles: true }));
    JS);
    $this->assertJsCondition('window.__pageApplied >= 2', 20000);
    $assert_session->waitForElement('css', $fb_wrapper);
    $this->assertJsCondition(
      "document.querySelector(\"$fb_wrapper\")?.textContent.includes('Second referenced node')",
      20000,
      'The block re-rendered from tempstore with the re-pointed reference.',
    );
    // A committed edit keeps the cached block form AND the block subject.
    $this->assertJsCondition(
      "document.getElementById('daedalus-edit-form-cache')?.dataset.daedalusEditSubject?.startsWith('block_content::')",
      20000,
      'The committed block edit left the block as the panel subject.',
    );
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement?.plugin.blur();');

    // Cancel discards through a refetch: re-open the block edit, type into
    // the widget (the dirty signal) and press Escape. The widget's
    // uncommitted state is not the client's to restore, so the block form
    // is fetched again: a FRESH form element, the block still the subject,
    // the typed value gone.
    $this->dispatchDoubleClick($fb_related_leaf, ['metaKey' => TRUE]);
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_fb_related'",
      20000,
      'The block edit re-opened for the cancel leg.',
    );
    $session->executeScript(<<<JS
      window.__fbFormBeforeCancel = Drupal.Daedalus.Edit.CurrentlyEditingElement.getForm();
      const fbInput = document.querySelector("{$fb_input}");
      fbInput.value = 'Typed and discarded';
      fbInput.dispatchEvent(new Event('input', { bubbles: true }));
    JS);
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "(() => {"
      . " const mount = document.getElementById('daedalus-edit-form-cache');"
      . " const panel = document.getElementById('daedalus_edit_entity_form');"
      . " const form = mount.querySelector(':scope > .daedalus-edit-form--subject');"
      . " const input = document.querySelector(\"$fb_input\");"
      . " return Drupal.Daedalus.Edit.CurrentlyEditingElement === null"
      . " && !panel.classList.contains('daedalus-ui-hidden')"
      . " && mount.dataset.daedalusEditSubject.startsWith('block_content::')"
      . " && !!form && form !== window.__fbFormBeforeCancel"
      . " && !!input && input.value.includes('Second referenced node');"
      . " })()",
      20000,
      'The canceled dirty block edit refetched the block form, which stays the subject with the committed value in its widget.',
    );
    $this->assertJsCondition(
      "document.getElementById('daedalus-edit-form-panel-title')?.textContent === 'Form Block Block'",
      20000,
      'The pinned header kept the block bundle label across the refetch.',
    );

    // ——— The block label: the block_property pseudo-field round trip. ———
    // A placement's LABEL is not a field on the block_content entity — it
    // is component configuration — yet with label_display visible it
    // renders as page content, so daedalus_edit attributes the title element
    // as a pseudo-field leaf (daedalus_edit_preprocess_block's
    // ...::label::block_property vocabulary) and routes its edit through
    // the block form: PanelUpdateBlockForm::attributeLabel() rehomes
    // core's settings[label] input under the same lookup ids, so the
    // string_textfield editor drives it like any field. The round trip:
    // in-place H2 edit -> blur -> the form transport names 'label' -> the
    // spine writes the placement label -> the response re-renders the
    // block by key, re-attributed for the next edit.
    $fb_label_wrapper = "$fb_wrapper [data-daedalus-edit-field-value-wrapper\$='::label::block_property']";
    $fb_label_leaf = "$fb_label_wrapper .daedalus-edit-field-value";
    $this->assertJsCondition(
      "document.querySelector(\"$fb_label_leaf\")?.textContent.trim() === 'Original block label'",
      20000,
      'The form-block placement renders its visible label as an attributed pseudo-field leaf.',
    );
    $this->dispatchDoubleClick($fb_label_leaf, ['metaKey' => TRUE]);
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'label'"
      . " && document.querySelector(\"$fb_label_leaf\")?.hasAttribute('contenteditable')",
      20000,
      'Cmd+double-clicking the block label opened its in-place edit through the block form path.',
    );
    $session->executeScript(<<<JS
      const labelLeaf = Drupal.Daedalus.Edit.CurrentlyEditingElement.getPageElementHandle();
      labelLeaf.textContent = 'Renamed block label';
      labelLeaf.dispatchEvent(new Event('input', { bubbles: true }));
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
    JS);
    $this->assertJsCondition('window.__pageApplied >= 3', 20000);
    $this->assertJsCondition(
      "document.querySelector(\"$fb_label_leaf\")?.textContent.trim() === 'Renamed block label'",
      20000,
      'The label re-rendered from the staged component configuration, still attributed for the next edit.',
    );

    // ——— The draft over a block swap: the block's form is its own. ———
    // The form-edited block's related leaf edits in the PANEL widget (the
    // draft lives in the widget, nothing to capture from the canvas). A
    // style scrub on the block key swaps the block subtree (the edited
    // leaf detaches with it) but targets no block_content field, so the
    // block's cached form is left alone. The resume re-mounts the same
    // edit on the swapped-in page element with the widget's typed value
    // untouched; the explicit floor (blur) commits it.
    $this->dispatchDoubleClick($fb_related_leaf, ['metaKey' => TRUE]);
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
        const input = document.querySelector("{$fb_input}");
        input.value = 'First referenced node ({$first_referenced->id()})';
        input.dispatchEvent(new Event('input', { bubbles: true }));
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
        const input = document.querySelector("{$fb_input}");
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
    $this->assertJsCondition('window.__pageApplied >= 5', 20000, 'Blur after the block resume committed the widget value.');
    $this->assertJsCondition(
      "document.querySelector(\"$fb_wrapper\")?.textContent.includes('First referenced node')",
      20000,
      'The block re-rendered from tempstore with the reference the resumed edit committed.',
    );

    // ——— The Configure dialog on the transport: core's form, the spine's answer. ———
    // daedalus_layout's Configure verb opens core's UpdateBlockForm in the
    // edit-mode dialog. daedalus_edit's alter puts that form on the form
    // transport (LayoutBuilderBlockFormAlter: core's whole-storage submit
    // leaves the chain, the changed properties ride one update_element)
    // and its client half runs the dialog's Update as a job on the op
    // queue (configure-dialog.js). The seam only a browser sees: the verb's
    // dialog, the form's own AJAX round, the payload applied through the
    // app's pipeline, the close command on the dialog the verb opened. The
    // proof of "by key": the placement's visible label is a keyed leaf of
    // its own (block:{uuid}:label), so exactly that leaf is swapped while
    // the block and its section elements survive, where core's path
    // replaced the whole layout.
    $session->executeScript(<<<JS
      (() => {
        window.__fbSectionBeforeDialog = document.querySelector("[data-selectable='container:$section_uuid']");
        window.__fbBlockBeforeDialog = document.querySelector("$fb_wrapper");
        window.__fbLabelLeafBeforeDialog = document.querySelector("$fb_label_leaf");
        Drupal.Daedalus.Selection.select('block:$fb_uuid');
        const verb = Drupal.Daedalus.Verbs.verbsFor('block:$fb_uuid')
          .find((candidate) => candidate.id === 'daedalus_layout.configure');
        verb.run('block:$fb_uuid');
      })();
      JS);
    $dialog_form = '#layout-builder-modal form[data-daedalus-edit-configure-dialog="block:' . $fb_uuid . '"]';
    $dialog_label = $assert_session->waitForElementVisible('css', "$dialog_form input[name=\"settings[label]\"]", 20000);
    $this->assertNotNull($dialog_label, 'The Configure verb opened the block dialog, its form stamped for the transport.');
    $this->assertSame('Renamed block label', $dialog_label->getValue(), 'The dialog shows the staged placement label.');
    $dialog_label->setValue('Configured block label');
    // Core's Layout Builder forms declare no actions container, so the
    // Update button renders bare in the form (no .form-actions, no dialog
    // button pane); the server stamps it for the client half, and a real
    // click on it (mousedown first, which the takeover binds) is what a
    // user does.
    $update_button = $assert_session->waitForElementVisible('css', "$dialog_form [data-daedalus-edit-configure-dialog-submit]");
    $this->assertNotNull($update_button, 'The dialog rendered its stamped Update button.');
    $update_button->click();
    $this->assertJsCondition('window.__pageApplied >= 6', 20000, 'The dialog submit rode the op queue and applied the mutation payload.');
    $this->assertJsCondition(
      "(() => {"
      . " const block = document.querySelector(\"$fb_wrapper\");"
      . " const section = document.querySelector(\"[data-selectable='container:$section_uuid']\");"
      . " const leaf = document.querySelector(\"$fb_label_leaf\");"
      . " const modal = document.querySelector('#layout-builder-modal');"
      . " return block === window.__fbBlockBeforeDialog"
      . " && section === window.__fbSectionBeforeDialog"
      . " && !!leaf && leaf !== window.__fbLabelLeafBeforeDialog"
      . " && leaf.textContent.trim() === 'Configured block label'"
      . " && (!modal || modal.getClientRects().length === 0);"
      . " })()",
      20000,
      'The dialog commit swapped the label leaf by key (the block and section elements survived) and closed the dialog.',
    );
    $this->assertJsCondition(<<<JS
      (() => {
        const entries = Drupal.Daedalus.History?.getState?.().entries ?? [];
        const latest = entries[entries.length - 1];
        return !!latest && latest.label === 'Configured Renamed block label'
          && Array.isArray(latest.targets) && latest.targets.length === 1
          && latest.targets[0].uuid === '$fb_uuid'
          && latest.targets[0].field_name === 'label';
      })()
      JS,
      20000,
      'The dialog commit rode the spine: one history entry naming the placement, targeted at its label.',
    );
  }

}
