<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\FunctionalJavascript;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the capability gate on a live template page, as the editor.
 *
 * A page of a non-overridable bundle (Layout Builder on, overrides off)
 * renders its display's document and owns only its fields: a plain host.
 * The spine judges every element for the actor (the ownership policy
 * refuses a template element everything, except that a field block
 * presenting one of the page's own fields keeps that field's content
 * verdict), stamps the verdict on every read, and the client asks ONE
 * gate (capable, selectable) for every gesture: selection, verbs, drags,
 * chrome. This test carries that whole path end to end, in the browser,
 * as the recipe's editor, who cannot open the template.
 *
 * The seams only a browser sees, in scenario order:
 * - CLICK RESOLUTION THROUGH THE PUBLISHED VERDICT: a click inside the
 *   template's text block selects nothing, a click on the field block's
 *   text selects the page's field (the placement row that presents it),
 *   Enter opens its editor and the edit commits to tempstore. The server
 *   stamp, the projection and the resolver agree only here; no lower
 *   tier sees them together.
 * - THE DRAG CONTROLLER'S GATE UNDER REAL POINTER EVENTS: the template
 *   rows are shown and inert in the page elements panel, a drag started
 *   on one sends no mutation and shows no caret, and the canvas delegate
 *   refuses the gesture on the presented field block (arrange refused).
 *   This is the regression that opened the topic (a drag reaching the
 *   server to be refused FOREIGN_DOCUMENT), so it earns its step by
 *   accretion.
 * - SIDEBARS AND THE NOTICE FROM SERVER VERDICTS: the inspector and the
 *   Add panel are not mounted (no style, no insert, anywhere), and the
 *   notice at the top of the page elements panel names the template with
 *   no portal for an editor who cannot open it. No kernel render sees a
 *   sidebar mounted.
 * - THE ROLE POLICY REACHING CHROME: on an ordinary overridable page, an
 *   editor without the bundle's style permission selects and moves a
 *   container through a real row drag while the inspector stays
 *   unmounted and the Add panel is up.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site) plus a
 * non-overridable 'live' node type built in setUp: its default display
 * holds a container with a text block (a value block, as a display stores
 * one) and a field block presenting the type's own plain-text field,
 * configured for inline editing. The whole scenario runs as the editor
 * persona, whose role gains the type's edit permission (the recipe's
 * consumer contract: a bundle created after install grants its own) and
 * nothing else; the fourth step revokes the editor's style permission on
 * the recipe's Page type. One fixture, one scenario: a full site install
 * from the recipe is the dominant cost of a run, so all behavior lives in
 * the single test method.
 *
 * @group daedalus_layout
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class LiveTemplateBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * The non-overridable bundle.
   */
  private const BUNDLE = 'live';

  /**
   * The page's own plain-text field, presented by the template's field block.
   */
  private const FIELD = 'field_live_text';

  /**
   * The template's identities.
   */
  private const CONTAINER_UUID = '7a000000-0000-4000-8000-000000000001';
  private const TEXT_UUID = '7a000000-0000-4000-8000-000000000002';
  private const FIELD_BLOCK_UUID = '7a000000-0000-4000-8000-000000000003';

  /**
   * The template text block's text.
   */
  private const TEMPLATE_TEXT = 'Template band text';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->daedalusSetUp();

    // The non-overridable bundle: the page builder on (a type needs the
    // daedalus_ui `enabled` setting before the edit-mode bundle attaches at
    // all), arriving outside Edit Mode so the cookie entry is the one door.
    $type = NodeType::create([
      'type' => self::BUNDLE,
      'name' => 'Live',
      'new_revision' => TRUE,
    ]);
    $type->setThirdPartySetting('daedalus_ui', 'enabled', TRUE);
    $type->setThirdPartySetting('daedalus_ui', 'initial_mode', 'none');
    $type->save();

    // The page's own field: a string editing in place through the entity
    // form path (the textfield tier), the shape the fixture's subtitle has.
    FieldStorageConfig::create([
      'field_name' => self::FIELD,
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    $field = FieldConfig::create([
      'field_name' => self::FIELD,
      'entity_type' => 'node',
      'bundle' => self::BUNDLE,
      'label' => 'Live text',
    ]);
    $field->setThirdPartySetting('daedalus_edit', 'disable', FALSE);
    $field->setThirdPartySetting('daedalus_edit', 'handle', 'form_item');
    $field->save();
    \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', self::BUNDLE)
      ->setComponent(self::FIELD, ['type' => 'string_textfield'])
      ->save();

    // The template: Layout Builder on, overrides OFF, one container holding
    // a text block (a value block, the way a display stores inline content)
    // and the field block presenting the page's own field.
    $section = new Section('daedalus_stylish_container');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', self::CONTAINER_UUID);
    $section->appendComponent(new SectionComponent(self::TEXT_UUID, 'content', [
      'id' => 'inline_block:text',
      'label' => 'Template text',
      'label_display' => '0',
      'block_values' => ['field_text_text' => self::TEMPLATE_TEXT],
    ]));
    $section->appendComponent(new SectionComponent(self::FIELD_BLOCK_UUID, 'content', [
      'id' => 'field_block:node:' . self::BUNDLE . ':' . self::FIELD,
      'label' => 'Live text',
      'label_display' => '0',
      'formatter' => [
        'type' => 'string',
        'label' => 'hidden',
        'settings' => ['link_to_entity' => FALSE],
        'third_party_settings' => [],
      ],
      'context_mapping' => ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
    ]));
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => self::BUNDLE,
      'mode' => 'default',
      'status' => TRUE,
    ])
      ->enableLayoutBuilder()
      ->appendSection($section)
      ->save();

    // The consumer contract of the recipe's editor role: a bundle created
    // after install grants its own permissions. Update access is the host
    // access policy's door; nothing structural or stylistic is granted,
    // and the ownership policy would refuse it anyway.
    Role::load('daedalus_editor')
      ->grantPermission('create ' . self::BUNDLE . ' content')
      ->grantPermission('edit any ' . self::BUNDLE . ' content')
      ->save();
  }

  /**
   * Installs a counter of blueprint POSTs, per page load.
   *
   * "A refused gesture, no POST" is a wiring fact; the counter proxies
   * window.fetch, which the op spine's client uses. A reload kills the JS
   * context, so this runs again after every navigation.
   */
  private function countPosts(): void {
    $this->getSession()->executeScript(<<<'JS'
      window.__blueprintPosts = 0;
      const originalFetch = window.fetch;
      window.fetch = function (url, options) {
        const target = url && url.url ? url.url : String(url);
        const method = (options && options.method) || (url && url.method) || 'GET';
        if (target.includes('daedalus-blueprint') && method === 'POST') {
          window.__blueprintPosts++;
        }
        return originalFetch.apply(this, arguments);
      };
      JS);
  }

  /**
   * Installs a race-free counter of fully applied mutation responses.
   *
   * Every write is one mutation response applied through the app's one
   * pipeline, which reports the end of every application to the open edit
   * (EditSession.mutationApplied). Counting those is the deterministic
   * "the server staged it and the page shows it" signal.
   */
  private function countApplied(): void {
    $this->getSession()->executeScript(<<<'JS'
      window.__applied = 0;
      (() => {
        const original = Drupal.Daedalus.EditSession.mutationApplied;
        Drupal.Daedalus.EditSession.mutationApplied = function () {
          window.__applied += 1;
          return original.apply(this, arguments);
        };
      })();
      JS);
  }

  /**
   * Waits for the blueprint mirror to boot on the current page.
   */
  private function waitForMirror(string $message): void {
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      $message,
    );
  }

  /**
   * Dispatches a single click into the pointer grammar's document handlers.
   *
   * A real pointer at this viewport can land on the fixed chrome instead
   * of the leaf; the dispatched click runs the same document-level
   * resolver a real click does.
   */
  private function dispatchClick(string $selector_js): void {
    $named = json_encode($selector_js);
    $this->getSession()->executeScript(<<<JS
      (() => {
        const target = $selector_js;
        if (!target) {
          throw new Error('dispatchClick: no element for ' + $named);
        }
        target.scrollIntoView({ block: 'center' });
        target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
      })();
      JS);
  }

  /**
   * The JS expression: whether any caret face is showing.
   */
  private const CARET_SHOWING_JS = "(() => { const caret = document.querySelector('.daedalus-ui-caret'); if (!caret) { return false; }"
    . " return ['.daedalus-ui-caret__line', '.daedalus-ui-caret__empty', '.daedalus-ui-caret__container']"
    . " .some((selector) => Array.from(caret.querySelectorAll(selector)).some((face) => !face.hidden)); })()";

  /**
   * The template page as the editor: one gate for every gesture.
   */
  public function testLiveTemplateGate(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // The template's label, as the notice will name it (read before the
    // browser touches anything, so no staged work exists to collide with).
    $display = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('node.' . self::BUNDLE . '.default');
    $template_label = (string) $display->label();
    $this->assertNotSame('', $template_label, 'The template has a label for the notice.');

    $node = Node::create([
      'type' => self::BUNDLE,
      'title' => 'Live template page',
      self::FIELD => 'Original live text',
    ]);
    $node->save();
    $nid = (int) $node->id();
    $node_uuid = $node->uuid();

    $container_key = 'container:' . self::CONTAINER_UUID;
    $text_key = 'block:' . self::TEXT_UUID;
    $field_block_key = 'block:' . self::FIELD_BLOCK_UUID;

    // The cookie entry: the full Edit Mode chrome server-rendered (every
    // applicable sidebar mounted with its assets), the mirror booted on
    // the page with the display's document and the spine's verdicts.
    $this->drupalGet($node->toUrl());
    $session->resizeWindow(1600, 1400);
    $this->enterEditModeViaCookies($node->toUrl());
    $this->assertJsCondition("document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'", 20000, 'The live template page renders in Edit Mode from the cookie.');
    $this->waitForMirror('The blueprint mirror booted on the live template page.');
    $this->assertJsCondition(
      sprintf("Drupal.Daedalus.Blueprint.getRoot()?.uuid === '%s'", $node_uuid),
      20000,
      'The mirror is rooted on the page, not the display.',
    );
    $this->assertJsCondition(
      "JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('" . self::CONTAINER_UUID . "')",
      20000,
      'The page reads the template\'s document.',
    );
    $this->assertJsCondition(
      "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Hotkeys && typeof Drupal.Daedalus.Hotkeys.register === 'function'",
      20000,
    );
    $this->countPosts();
    $this->countApplied();

    // The published verdict, read through the one gate: the template's
    // container and text block allow nothing; the field block presenting
    // the page's field carries content and nothing else.
    $this->assertJsCondition(
      "(() => { const S = Drupal.Daedalus.Selection;"
      . " return S.selectable('$container_key') === false && S.selectable('$text_key') === false"
      . " && S.selectable('$field_block_key') === true"
      . " && S.capable('$field_block_key', 'content') === true"
      . " && ['configure', 'style', 'arrange', 'insert'].every((c) => S.capable('$field_block_key', c) === false)"
      . " && S.capable('$text_key', 'content') === false; })()",
      20000,
      'The gate answers from the published verdict: the template elements are refused everything, the presented field block keeps content alone.',
    );

    // ——— 1. Click resolution through the published verdict. ———
    //
    // The template's text block: its leaf is attributed (attribution is
    // host-blind), but the represented chain under it keeps no selectable
    // key, so the click resolves to nothing, as a click on the bare canvas
    // does, and the dblclick reaches no editor door.
    $text_block_js = "document.querySelector(\"[data-main-entity] [data-selectable='$text_key']\")";
    $text_leaf_js = "($text_block_js.querySelector('[data-daedalus-edit-text], [data-daedalus-edit-id]') ?? $text_block_js)";
    $this->assertJsCondition("!!$text_block_js && $text_block_js.textContent.includes('" . self::TEMPLATE_TEXT . "')", 20000, 'The template\'s text block renders on the page.');
    $this->dispatchHover($text_leaf_js);
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getHovered() === null',
      20000,
      'Hovering inside the template\'s text block names no key.',
    );
    $this->dispatchClick($text_leaf_js);
    $session->wait(500);
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === null && Drupal.Daedalus.EditSession.isEditing() === false',
      20000,
      'A click inside the template\'s text block selects nothing.',
    );
    $this->dispatchDoubleClick("[data-main-entity] [data-selectable='$text_key']");
    $session->wait(500);
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === null && Drupal.Daedalus.Edit.CurrentlyEditingElement === null'
      . ' && window.__blueprintPosts === 0',
      20000,
      'A double-click inside the template\'s text block opens no editor and sends nothing.',
    );

    // The field block presents the page's own field: its leaf is the
    // field's editable render, the chain under it keeps the placement row
    // (merged into the field, the field having no row of its own on a
    // plain host), so hover names it and the click selects it.
    $field_wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::' . self::FIELD . '::value"]';
    $field_leaf = $field_wrapper . ' .daedalus-edit-field-value';
    $field_leaf_js = "document.querySelector('$field_leaf')";
    $this->assertNotNull($assert_session->waitForElement('css', "[data-main-entity] [data-selectable='$field_block_key'] $field_leaf", 20000), 'The field block renders the page\'s own field as an editable leaf. Wrappers: ' . implode(' | ', (array) $session->evaluateScript(
      "[...document.querySelectorAll('[data-daedalus-edit-field-value-wrapper]')].map((el) => el.getAttribute('data-daedalus-edit-field-value-wrapper'))",
    )));
    $this->dispatchHover($field_leaf_js);
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getHovered() === '$field_block_key'",
      20000,
      'Hovering the presented field names the placement row that presents it.',
    );
    $this->dispatchClick($field_leaf_js);
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$field_block_key' && Drupal.Daedalus.EditSession.isEditing() === false",
      20000,
      'A click on the field block\'s text selects the page\'s field (the placement row presenting it), a plain selection.',
    );

    // Enter, the editor door, opens the presented field's in-place editor
    // (the placement's edit target is the field), and the edit commits
    // through the form transport to tempstore: the page's content is the
    // page's, whoever owns the layout.
    $this->pressHotkey('Enter');
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === '" . self::FIELD . "'"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.id === 'textfield'"
      . " && Drupal.Daedalus.EditSession.isEditing() === true",
      20000,
      'Enter on the selected field block opened the presented field\'s in-place editor.',
    );
    $session->executeScript(<<<'JS'
      const leaf = Drupal.Daedalus.Edit.CurrentlyEditingElement.getPageElementHandle();
      leaf.textContent = 'Edited live text';
      leaf.dispatchEvent(new Event('input', { bubbles: true }));
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
      JS);
    $this->assertJsCondition('window.__applied >= 1', 20000, 'The blur commit\'s mutation response was applied.');
    $assert_session->waitForElement('css', $field_wrapper);
    $this->assertJsCondition(
      "document.querySelector('$field_wrapper')?.textContent.includes('Edited live text')",
      20000,
      'The presented field re-rendered with the staged value.',
    );
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    $tempstore = \Drupal::service('daedalus_tempstore.entity_tempstore_repository');
    $this->assertSame(
      'Edited live text',
      $tempstore->get($storage->load($nid))->get(self::FIELD)->value,
      'The edit is staged in the page\'s tempstore: the page owns its fields.',
    );
    $this->assertSame(
      'Original live text',
      $storage->load($nid)->get(self::FIELD)->value,
      'The edit is staged, not committed to the node.',
    );
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement === null',
      20000,
      'The blur ended the edit.',
    );
    $this->assertJsCondition(
      "JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('" . self::CONTAINER_UUID . "')",
      20000,
      'The mutation response kept the template\'s document on the page.',
    );
    $posts_after_edit = (int) $session->evaluateScript('window.__blueprintPosts');
    $session->executeScript('Drupal.Daedalus.Selection.clear();');

    // ——— 2. The template rows are inert; a drag on one goes nowhere. ———
    //
    // The page elements panel shows the template as it normally does:
    // the container row, expandable, with the text block's and the field
    // block's rows under it. The container and the text block wear the
    // inert treatment (no drag affordance, no hover, no selection on
    // click); the field block's row is live.
    $this->assertJsCondition(
      "document.getElementById('page_elements') && !document.getElementById('page_elements').classList.contains('daedalus-ui-hidden')",
      20000,
      'The page elements panel is open.',
    );
    $container_row_js = "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"$container_key\"]')";
    $text_row_js = "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"$text_key\"]')";
    $field_row_js = "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"$field_block_key\"]')";
    $this->assertJsCondition(
      "(() => { const c = $container_row_js; const t = $text_row_js; const f = $field_row_js;"
      . " return !!c && !!t && !!f"
      . " && c.hasAttribute('data-tree-inert') && c.classList.contains('daedalus-ui-tree__row--unselectable') && !c.hasAttribute('draggable')"
      . " && c.getAttribute('aria-expanded') === 'true'"
      . " && t.hasAttribute('data-tree-inert') && !t.hasAttribute('draggable')"
      . " && !f.hasAttribute('data-tree-inert') && !f.classList.contains('daedalus-ui-tree__row--unselectable'); })()",
      20000,
      'The tree draws the template rows inert (the container still expanded) and the presented field block\'s row live.',
    );
    $session->executeScript("$container_row_js.click();");
    $session->wait(500);
    $this->assertJsCondition(
      'Drupal.Daedalus.Selection.getSelected() === null',
      20000,
      'Clicking an inert template row selects nothing.',
    );
    $session->executeScript("$field_row_js.click();");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$field_block_key'",
      20000,
      'Clicking the presented field block\'s row selects it.',
    );
    $session->executeScript('Drupal.Daedalus.Selection.clear();');

    // A pointer drag started on the template container's row: the row is
    // no drag source (nothing starts), the caret never shows, the drop on
    // the canvas sends no mutation, and the page is as it was. The
    // regression Tim hit was this drag reaching the server.
    $field_block_js = "document.querySelector(\"[data-main-entity] [data-selectable='$field_block_key']\")";
    $this->startDrag($container_row_js, $field_block_js);
    $session->wait(1000);
    $this->assertFalse((bool) $session->evaluateScript(self::CARET_SHOWING_JS), 'No caret face shows during the drag from the inert container row.');
    $this->assertNull($session->evaluateScript("document.querySelector('.daedalus-ui-caret')"), 'The drag from the inert template row armed no drag layer at all.');
    $this->finishDrag($container_row_js, $field_block_js);
    $session->wait(1000);
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-caret') === null && window.__blueprintPosts === $posts_after_edit",
      20000,
      'The drop from the inert template row sent no mutation.',
    );
    $this->startDrag($text_row_js, $field_block_js);
    $session->wait(1000);
    $this->assertFalse((bool) $session->evaluateScript(self::CARET_SHOWING_JS), 'No caret face shows during the drag from the inert text block row.');
    $this->finishDrag($text_row_js, $field_block_js);
    $session->wait(1000);
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-caret') === null && window.__blueprintPosts === $posts_after_edit",
      20000,
      'The drop from the inert text block row sent no mutation either.',
    );

    // The canvas delegate's gate: hovering the presented field block arms
    // the main entity wrapper as the drag handle (the field block is a
    // movable placement), and its dragstart asks arrange of the block,
    // which the ownership policy refuses: the controller cancels the
    // gesture (preventDefault), nothing drags, nothing is sent.
    $this->dispatchHover($field_leaf_js);
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getHovered() === '$field_block_key'"
      . " && document.querySelector('[data-main-entity]').getAttribute('draggable') === 'true'",
      20000,
      'Hovering the presented field block armed the canvas drag delegate on it.',
    );
    $started = $this->startDrag($field_block_js, $text_block_js);
    $session->wait(1000);
    $this->assertFalse($started, 'The canvas delegate cancelled the dragstart on the field block: arrange is refused.');
    $this->assertFalse((bool) $session->evaluateScript(self::CARET_SHOWING_JS), 'No caret face shows for the refused canvas drag.');
    $this->finishDrag($field_block_js, $text_block_js);
    $session->wait(1000);
    $this->assertJsCondition(
      "document.querySelector('.daedalus-ui-caret') === null && window.__blueprintPosts === $posts_after_edit",
      20000,
      'The refused canvas drag sent no mutation.',
    );
    $this->assertJsCondition(
      "(() => { const container = document.querySelector(\"[data-main-entity] [data-selectable='$container_key']\");"
      . " const blocks = Array.from(container.querySelectorAll(\"[data-selectable^='block:']\")).map((el) => el.getAttribute('data-selectable'));"
      . " return blocks[0] === '$text_key' && blocks.includes('$field_block_key'); })()",
      20000,
      'The template\'s structure is as it was: the text block first, the field block after it, inside the container.',
    );

    // The keyboard never stands on the template either: ArrowDown from
    // nothing enters at the root, ArrowRight drills into the root's first
    // reachable row (reach walks past the unselectable container, hoisting
    // its rows into its place), and the walk down from there reaches the
    // presented field block without stopping on the container or the
    // text block.
    $root_key = 'entity:' . $node_uuid;
    $this->pressHotkey('ArrowDown');
    $this->assertJsCondition("Drupal.Daedalus.Selection.getSelected() === '$root_key'", 20000, 'ArrowDown with no selection enters the tree at the root.');
    $session->executeScript(<<<JS
      window.__walk = [];
      JS);
    $this->pressHotkey('ArrowRight');
    $session->executeScript('window.__walk.push(Drupal.Daedalus.Selection.getSelected());');
    for ($i = 0; $i < 3; $i++) {
      $this->pressHotkey('ArrowDown');
      $session->executeScript('window.__walk.push(Drupal.Daedalus.Selection.getSelected());');
    }
    $walk = (array) $session->evaluateScript('window.__walk');
    $this->assertContains($field_block_key, $walk, 'The arrow walk reaches the presented field block. Walk: ' . implode(' > ', array_map('strval', $walk)));
    $this->assertNotContains($container_key, $walk, 'The arrow walk never stands on the template container. Walk: ' . implode(' > ', array_map('strval', $walk)));
    $this->assertNotContains($text_key, $walk, 'The arrow walk never stands on the template text block. Walk: ' . implode(' > ', array_map('strval', $walk)));
    $session->executeScript('Drupal.Daedalus.Selection.clear();');

    // ——— 3. No inspector, no Add panel, one notice naming the template. ———
    //
    // Sidebars mount by capability: style is refused everywhere (the
    // editor holds no style permission for this bundle) and insert
    // everywhere (the document is the template's), so neither panel is in
    // the chrome at all. The notice at the top of the page elements panel
    // explains the host-level refusal and offers no portal: the editor
    // cannot open the template.
    $this->assertJsCondition(
      "document.getElementById('daedalus_stylish_inspector') === null && document.getElementById('place_block') === null",
      20000,
      'Neither the Styles inspector nor the Add panel is mounted on the live template page.',
    );
    $notice_js = "document.querySelector('#page_elements .daedalus-ui-document-notice__line[data-refusal-code=\"FOREIGN_DOCUMENT\"]')";
    $this->assertJsCondition(
      "(() => { const line = $notice_js; return !!line"
      . " && line.querySelector('.daedalus-ui-document-notice__text')?.textContent === " . json_encode("Layout from the template $template_label.")
      . " && line.querySelector('.daedalus-ui-document-notice__portal') === null; })()",
      20000,
      "The notice names the template ($template_label) with no portal for the editor. Notice: " . (string) $session->evaluateScript("document.querySelector('#page_elements .daedalus-ui-document-notice')?.outerHTML ?? 'none'"),
    );
    $this->assertJsCondition(
      "document.querySelectorAll('#page_elements .daedalus-ui-document-notice__line').length === 1",
      20000,
      'The notice carries exactly one line: the one host-level refusal.',
    );
    // The Insert verb's hotkey arms nothing: no element admits insert.
    $session->executeScript("Drupal.Daedalus.Selection.select('$field_block_key');");
    $this->pressHotkey('i');
    $session->wait(500);
    $this->assertJsCondition(
      "!document.documentElement.classList.contains('daedalus-layout-insert-pending') && window.__blueprintPosts === $posts_after_edit",
      20000,
      'The Insert hotkey arms no insert session on the presented field block.',
    );
    $session->executeScript('Drupal.Daedalus.Selection.clear();');

    // ——— 4. The role policy reaching chrome, on an ordinary page. ———
    //
    // The recipe's Page type is overridable and the editor holds its
    // override permission; revoke the bundle's style permission. On that
    // page the editor selects a container and moves it through a real
    // row drag (arrange and insert open, the Add panel up) while the
    // inspector stays unmounted (style refused everywhere) and no notice
    // shows (a permission refusal is not a scenario).
    $editor_role = Role::load('daedalus_editor');
    $this->assertTrue($editor_role->hasPermission('style page node elements'), 'The recipe grants the editor the Page type\'s style permission.');
    $editor_role->revokePermission('style page node elements')->save();

    $page = Node::create(['type' => 'page', 'title' => 'Ordinary page']);
    $page->save();
    $this->drupalGet($page->toUrl());
    $this->assertJsCondition("document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'", 20000, 'The ordinary page renders in Edit Mode from the cookie.');
    $this->waitForMirror('The mirror booted on the ordinary page.');
    $this->assertJsCondition(
      sprintf("Drupal.Daedalus.Blueprint.getRoot()?.uuid === '%s'", $page->uuid()),
      20000,
      'The mirror is rooted on the ordinary page.',
    );
    $this->assertJsCondition(
      "window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Hotkeys && typeof Drupal.Daedalus.Hotkeys.register === 'function'",
      20000,
    );
    $this->countPosts();
    $this->assertJsCondition(
      "document.getElementById('daedalus_stylish_inspector') === null"
      . " && document.getElementById('place_block') !== null"
      . " && document.querySelector('#page_elements .daedalus-ui-document-notice') === null",
      20000,
      'Without the bundle\'s style permission the inspector is not mounted, the Add panel is, and no notice shows.',
    );

    // Two top-level containers through the same batch a palette drop
    // enqueues.
    $uuid_service = \Drupal::service('uuid');
    $a = $uuid_service->generate();
    $b = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        const root = Blueprint.getRoot().uuid;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'add_section', uuid: '$a', location: { anchor: root, placement: 'inside' } },
            { op: 'add_section', uuid: '$b', location: { anchor: '$a', placement: 'after' } },
          ],
        })).catch((error) => {
          window.__structureError = error.message;
        });
      })();
      JS);
    $containers_js = "Array.from(document.querySelectorAll(\"[data-main-entity] [data-selectable^='container:']\")).map((el) => el.getAttribute('data-selectable'))";
    $before_move = json_encode(json_encode(["container:$a", "container:$b"]));
    $this->assertJsCondition(
      "window.__structureError !== undefined || JSON.stringify($containers_js) === $before_move",
      20000,
      'The two containers resolved.',
    );
    $this->assertNull($session->evaluateScript('window.__structureError ?? null'), 'Placing the containers failed: ' . ($session->evaluateScript('window.__structureError ?? null') ?? ''));
    $this->assertJsCondition('window.__blueprintPosts === 1', 10000, 'The page structure was one blueprint POST.');
    $this->assertJsCondition(
      "(() => { const S = Drupal.Daedalus.Selection; return S.selectable('container:$a') && S.capable('container:$a', 'arrange')"
      . " && S.capable('container:$a', 'insert') && S.capable('container:$a', 'style') === false; })()",
      20000,
      'The gate on the ordinary page: arrange and insert open through the override permission, style refused by the role policy.',
    );

    // Select A through its row (live, a drag source), then drag the row
    // onto B's bottom edge: the root-flow gap after B. One POST, the
    // order swapped, A still at top level.
    $a_row_js = "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"container:$a\"]')";
    $this->assertJsCondition(
      "(() => { const row = $a_row_js; return !!row && !row.hasAttribute('data-tree-inert') && row.getAttribute('draggable') === 'true'; })()",
      20000,
      'Container A\'s row is live and armed as a drag source.',
    );
    $session->executeScript("$a_row_js.click();");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'container:$a'",
      20000,
      'Clicking container A\'s row selects it.',
    );
    $b_js = "document.querySelector(\"[data-main-entity] [data-selectable='container:$b']\")";
    $started = $this->startDrag($a_row_js, $b_js);
    $this->assertTrue($started, 'The row drag started: arrange is open on container A.');
    $this->assertJsCondition(self::CARET_SHOWING_JS, 20000, 'The caret offered the gap after B to the dragged row.');
    $this->finishDrag($a_row_js, $b_js);
    $this->assertJsCondition('window.__blueprintPosts === 2', 20000, 'The drop was one blueprint POST.');
    $after_move = json_encode(json_encode(["container:$b", "container:$a"]));
    $this->assertJsCondition(
      "JSON.stringify($containers_js) === $after_move",
      20000,
      'The move landed: B then A, both at top level.',
    );
    // Top level is the page's own: the container above A, if any, is the
    // site frame's section around the main entity, a foreign document's.
    $this->assertJsCondition(
      "(() => { const a = document.querySelector(\"[data-main-entity] [data-selectable='container:$a']\");"
      . " const parent = a && a.parentElement.closest(\"[data-selectable^='container:']\");"
      . " return !!a && (parent === null || parent.closest('[data-main-entity]') === null); })()",
      20000,
      'Container A sits at top level after the move.',
    );
    $this->assertJsCondition(
      "document.getElementById('daedalus_stylish_inspector') === null && document.getElementById('place_block') !== null",
      20000,
      'The inspector stayed unmounted through the move; the Add panel is still up.',
    );
  }

}
