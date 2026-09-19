<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\FunctionalJavascript;

use Drupal\daedalus_edit\IdentityMinter;
use Drupal\filter\Entity\FilterFormat;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the CKEditor identity plugin mirrors the PHP IdentityMinter.
 *
 * Eager identity has one server-side source of truth (IdentityMinter) and
 * one live client mirror (the Identity CKEditor plugin: 'sid' model
 * attribute, two-way class converters, postfixer). The mirror-parity
 * contract this test pins, on a real canvas editor attached through the
 * real edit gesture:
 * - Round-trip fidelity: server-minted ids survive the editor's
 *   upcast/downcast untouched — under full_html's allow-everything GHS,
 *   which would swallow s-* classes into rename-fragile per-element-type
 *   attributes if the plugin's upcast lost the conversion race.
 * - Client minting satisfies the server: everything the postfixer produces
 *   is a byte-identical no-op for IdentityMinter — same vocabulary, same
 *   dedupe (first carrier owns, later duplicates re-mint, ONE law at any
 *   depth), same id shape. The one client-side subtlety: a collapsed
 *   <li>text</li> holds no paragraph element in the data, so its model
 *   paragraph carries no sid (there is nothing for the class to ride).
 * - Identity survives rename (p -> h2), the reason sid is its own uniform
 *   attribute instead of a GHS piggyback.
 * - Lists mint live at every level: making a list mints the wrapper's id
 *   through the list-scope lsid attribute and each item's id through the
 *   item-scope isid attribute (neither is a model element), the wrapper
 *   id survives a bulleted -> numbered switch (the reason lsid is not a
 *   per-type GHS custody), an enter-split item re-mints by the duplicate
 *   law while staying in the same wrapper, and indent/outdent re-mint
 *   item and wrapper identity (ListEditing resets list attributes on an
 *   indent change, the same road list properties ride): an indented item
 *   mints its nested wrapper fresh, an outdented item adopts the wrapper
 *   it rejoins.
 * - Blur commits a value the staging pipeline accepts unchanged, so the
 *   presave backstop is free (the minter's byte-identical no-op).
 *
 * One fixture, one scenario: the recipe install (DaedalusFixtureTrait, as
 * daedalus_editor) dominates the run
 * cost (see InlineEditBehaviorTest), so all parity legs share the method.
 *
 * @group daedalus_edit
 */
#[Group('daedalus_edit')]
#[RunTestsInSeparateProcesses]
class IdentityPostFixerParityTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * {@inheritdoc}
   *
   * The recipe ships daedalus_edit_formatted_text only, whose editor allows no
   * class outside the identity vocabulary, so a foreign class cannot survive
   * it and the allow-everything GHS this test's race is about never arises.
   * full_html is what a real site's administrator adds on top; core's own
   * recipe supplies it, and the editor role is granted it the way a site
   * would.
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
    $this->applyRecipe($this->root . '/core/recipes/full_html_format_editor');
    $editor = Role::load('daedalus_editor');
    $editor->grantPermission('use text format full_html');
    $editor->save();
  }

  /**
   * All s-* ids in $html, in document order.
   *
   * @return string[]
   *   The ids, without their s- prefix.
   */
  protected function ids(string $html): array {
    preg_match_all('/class="([^"]*)"/', $html, $matches);
    $ids = [];
    foreach ($matches[1] as $classes) {
      foreach (preg_split('/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY) as $class) {
        if (preg_match(IdentityMinter::CLASS_PATTERN, $class, $m)) {
          $ids[] = $m[1];
        }
      }
    }
    return $ids;
  }

  /**
   * Asserts $html is a byte-identical no-op for the server minter.
   *
   * The headline parity assertion: the client already did everything the
   * server would do, so the canonical guarantee has nothing left to change.
   */
  protected function assertServerNoOp(string $html, string $message): void {
    $minter = \Drupal::service('daedalus_edit.identity_minter');
    $this->assertSame(
      $html,
      $minter->mint($html, FilterFormat::load('full_html')),
      $message,
    );
  }

  /**
   * The client identity plugin is a live mirror of IdentityMinter.
   */
  public function testPostFixerMirrorsIdentityMinter(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // The fixture covers every minter rule at once: a bare vocabulary
    // element, a pre-identified heading, a colliding duplicate, a foreign
    // class rider, list content, and a blockquote. The blockquote wraps an
    // explicit paragraph because CKEditor autoparagraphs bare blockquote
    // text on upcast, which would change the value regardless of identity.
    // Saving runs the identity minter's presave wiring, so the STORED value
    // is server-minted before the editor ever sees it.
    $node = Node::create([
      'type' => 'plain',
      'title' => 'Identity parity test node',
      'body' => [
        'value' => '<p>Plain paragraph</p><h2 class="s-ab">Pre-identified heading</h2><p class="s-ab">Duplicate id paragraph</p><p class="other s-cd">Foreign class paragraph</p><ul><li>Collapsed item</li></ul><blockquote><p>Quote</p></blockquote>',
        'format' => 'full_html',
      ],
    ]);
    $node->save();
    $nid = (int) $node->id();

    $stored = Node::load($nid)->get('body')->value;
    $stored_ids = $this->ids($stored);
    $this->assertCount(8, $stored_ids, 'The presave backstop minted the whole vocabulary, list wrapper and item included.');
    $this->assertSame('ab', $stored_ids[1], 'The first s-ab carrier kept its id.');
    $this->assertNotSame('ab', $stored_ids[2], 'The duplicate re-minted at presave.');

    // Open the canvas editor through the real gesture: Edit Mode, Edit
    // tool, a click on the body field. attachEditor injects the Identity
    // plugin into every canvas editor, which is what puts it under test.
    $this->drupalGet($node->toUrl());
    $session->resizeWindow(1200, 1600);
    $this->enterEditMode();
    $this->activateTool('edit');

    $wrapper = '[data-daedalus-edit-field-value-wrapper="node::' . $nid . '::body::value"]';
    $assert_session->waitForElement('css', $wrapper . ' .daedalus-edit-field-value');
    $session->getPage()->find('css', $wrapper . ' .daedalus-edit-field-value')->click();
    $editor_element = $assert_session->waitForElement('css', $wrapper . ' .ck-editor__editable');
    $this->assertNotNull($editor_element, 'The canvas CKEditor attached to the body field.');

    $get_data = 'Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value.getData()';

    // ----- Round-trip fidelity. -----
    // The editor upcast the server-minted raw value and its postfixer ran.
    // Nothing may change: every id the server minted is owned and unique,
    // so a client that re-mints ANY of them (e.g. because full_html's
    // allow-all GHS won the class and the sid never made it onto the
    // model) fails here.
    $data = $session->evaluateScript($get_data);
    $this->assertSame(
      $stored_ids,
      $this->ids($data),
      'Server-minted identity survived upcast, postfixer, and downcast untouched.',
    );
    $this->assertServerNoOp($data, 'The editor round trip stays a server no-op.');

    // The live mirror half of eager identity: the EDITING view's DOM
    // carries the id classes too, so mid-edit elements are addressable on
    // the canvas (rows, Daedalus Stylish) exactly like cold-rendered ones.
    $this->assertTrue(
      $session->evaluateScript(
        "!!document.querySelector('{$wrapper} .ck-editor__editable h2.s-ab')",
      ),
      'The editing-view DOM carries the s-* identity classes live.',
    );

    // ----- Client minting satisfies the server. -----
    // Seed the editor with raw UNMINTED markup: the postfixer must do,
    // live, everything IdentityMinter would do at presave.
    $session->executeScript(<<<JS
      Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value.setData(
        '<p>Plain paragraph</p><h2 class="s-ab">Pre-identified heading</h2>'
        + '<p class="s-ab">Duplicate id paragraph</p>'
        + '<p class="other s-cd">Foreign class paragraph</p>'
        + '<ul><li>Collapsed item</li></ul><blockquote><p>Quote</p></blockquote>'
      );
      JS);
    $minted = $session->evaluateScript($get_data);
    $minted_ids = $this->ids($minted);
    $this->assertCount(8, $minted_ids, 'The postfixer minted the whole vocabulary, list wrapper and item included.');
    $this->assertSame($minted_ids, array_unique($minted_ids), 'Minted ids are unique.');
    foreach ([0, 2, 4, 5, 6, 7] as $fresh) {
      // The id shape contract for fresh mints: 3 chars of [0-9a-z]. (The
      // fixture's own pre-set ids are 2 chars, so this also proves which
      // elements the client minted.)
      $this->assertMatchesRegularExpression('/^[a-z0-9]{3}$/', $minted_ids[$fresh]);
    }
    $this->assertSame('ab', $minted_ids[1], 'The first s-ab carrier kept its id.');
    $this->assertNotSame('ab', $minted_ids[2], 'The client re-minted the duplicate.');
    $this->assertSame('cd', $minted_ids[3], 'An id riding foreign classes is owned, not re-minted.');
    $this->assertStringContainsString('other', $minted, 'Foreign classes survive.');
    // The wrapper AND the item minted (universal minting: every element
    // the markup has is addressable); the collapsed item's text holds no
    // paragraph element, so no <p> is forced and no sid exists there. The
    // li may also carry CKEditor's own data-list-item-id (its list
    // round-trip, identity-independent).
    $this->assertMatchesRegularExpression('/<ul class="s-[a-z0-9]{3}">/', $minted, 'The list wrapper minted live.');
    $this->assertMatchesRegularExpression('/<li[^>]*class="s-[a-z0-9]{3}"[^>]*>Collapsed item<\/li>/', $minted, 'The item minted live and stays collapsed.');
    $this->assertServerNoOp($minted, 'Client-minted output is a byte-identical server no-op.');

    // ----- First carrier owns, at any depth. -----
    // The expanded item's paragraph owns s-ab (first in document order):
    // the outside duplicate re-mints while the inside carrier is never
    // rewritten, and the wrapper and item mint their own ids alongside —
    // the one duplicate law, list depth included.
    $session->executeScript(<<<JS
      Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value.setData(
        '<ol><li><p class="s-ab">In</p></li></ol><p class="s-ab">Out</p>'
      );
      JS);
    $inside = $session->evaluateScript($get_data);
    $this->assertMatchesRegularExpression('/<li[^>]*class="s-[a-z0-9]{3}"[^>]*><p class="s-ab">In<\/p><\/li>/', $inside, 'The inside carrier is untouched and its item minted.');
    $inside_ids = $this->ids($inside);
    $this->assertCount(4, $inside_ids);
    $this->assertMatchesRegularExpression('/^<ol class="s-[a-z0-9]{3}">/', $inside, 'The ol wrapper minted.');
    $this->assertSame('ab', $inside_ids[2], 'The inside carrier kept its id.');
    $this->assertNotSame('ab', $inside_ids[3], 'The outside duplicate re-minted.');
    $this->assertServerNoOp($inside, 'The universal walk matches the server exactly.');

    // ----- Identity survives rename. -----
    // The reason sid is one uniform attribute: a GHS piggyback would drop
    // the id on p -> h2 (htmlPAttributes vs htmlH2Attributes). The heading
    // option is resolved from config because the model name (heading1 vs
    // heading2) is configuration; the view h2 is the contract.
    $renamed = $session->evaluateScript(<<<JS
      (() => {
        const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value;
        editor.setData('<p class="s-zz">Rename me</p>');
        const option = editor.config.get('heading.options')
          .find((o) => (typeof o.view === 'string' ? o.view : o.view?.name) === 'h2');
        editor.model.change((writer) => {
          writer.setSelection(editor.model.document.getRoot().getChild(0), 'in');
        });
        editor.execute('heading', { value: option.model });
        return editor.getData();
      })()
      JS);
    $this->assertStringContainsString('<h2 class="s-zz">Rename me</h2>', $renamed, 'The id survived the p -> h2 rename.');
    $this->assertServerNoOp($renamed, 'The renamed element still satisfies the server.');

    // ----- List minting: wrapper and item identity, live. -----
    // A typed list has no ul or li element in the model (document lists
    // are flat), so the wrapper's identity rides the list-scope lsid
    // attribute and the item's the item-scope isid attribute, both
    // downcast onto the elements ListEditing builds. Making a paragraph
    // a list must mint both live, exactly what the server would do to
    // the committed data. The paragraph keeps the sid it minted as a
    // plain paragraph, so the item rides expanded (<li><p class>).
    $listed = $session->evaluateScript(<<<JS
      (() => {
        const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value;
        editor.setData('<p>First</p>');
        editor.model.change((writer) => {
          writer.setSelection(editor.model.document.getRoot().getChild(0), 'in');
        });
        editor.execute('bulletedList');
        return editor.getData();
      })()
      JS);
    $this->assertMatchesRegularExpression('/^<ul class="s-[a-z0-9]{3}">/', $listed, 'Making a list minted the wrapper id live.');
    $this->assertMatchesRegularExpression('/<li[^>]*class="s-[a-z0-9]{3}"[^>]*><p class="s-[a-z0-9]{3}">First<\/p><\/li>/', $listed, 'The item minted its own id and the paragraph kept its sid.');
    $this->assertServerNoOp($listed, 'The client-minted list is a server no-op.');
    preg_match('/^<ul class="s-([a-z0-9]{3})">/', $listed, $list_match);
    $list_id = $list_match[1];

    // The live-mirror half: the editing-view ul carries the id class, so
    // the mid-edit list is addressable on the canvas.
    $this->assertTrue(
      $session->evaluateScript(
        "!!document.querySelector('{$wrapper} .ck-editor__editable ul.s-{$list_id}')",
      ),
      'The editing-view DOM carries the list identity live.',
    );

    // The id survives a bulleted -> numbered switch: lsid is one uniform
    // list-scope attribute, the sid rename argument one level up — the
    // GHS custody it avoids (htmlUlAttributes vs htmlOlAttributes) is
    // per list type and dropped on switch.
    $switched = $session->evaluateScript(<<<JS
      (() => {
        const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value;
        editor.execute('numberedList');
        return editor.getData();
      })()
      JS);
    $this->assertStringContainsString('<ol class="s-' . $list_id . '">', $switched, 'The id survived the list-type switch.');
    $this->assertServerNoOp($switched, 'The switched list still satisfies the server.');

    // An enter-split item re-mints by the duplicate law: the split copies
    // the list attributes (lsid, isid) and the paragraph's sid onto the
    // new item, the first carriers own them, and the duplicates re-mint —
    // so the typed item joins the SAME wrapper with fresh item and
    // paragraph identities. Indenting it builds a nested list: ListEditing
    // resets list attributes on an indent change (the road list
    // properties ride), so the nested wrapper and the moved item mint
    // fresh; typing in the nested item mints nothing further; outdenting
    // resets again, so the item ADOPTS the wrapper it rejoins (one
    // visual list stays one wrapper) with a fresh item identity, while
    // the paragraph's sid rides through every gesture (sid is not a list
    // attribute).
    $nested = json_decode($session->evaluateScript(<<<JS
      (() => {
        const editor = Drupal.Daedalus.Edit.Ckeditor5Instances.values().next().value;
        editor.model.change((writer) => {
          writer.setSelection(editor.model.document.getRoot().getChild(0), 'end');
        });
        editor.execute('enter');
        editor.execute('insertText', { text: 'Second' });
        const grown = editor.getData();
        editor.execute('indentList');
        const indented = editor.getData();
        editor.execute('insertText', { text: ' more' });
        const typed = editor.getData();
        editor.execute('outdentList');
        return JSON.stringify({ grown, indented, typed, outdented: editor.getData() });
      })()
      JS), TRUE);
    $this->assertSame(2, substr_count($nested['grown'], '<li'), 'The typed item joined the list.');
    $this->assertSame(1, substr_count($nested['grown'], '<ol'), 'One wrapper: the new item rides the same wrapper identity.');
    $grown_ids = $this->ids($nested['grown']);
    $this->assertCount(5, $grown_ids, 'Wrapper, two items, two paragraphs.');
    $this->assertSame($grown_ids, array_unique($grown_ids), 'The split item re-minted its copied identities.');
    $this->assertServerNoOp($nested['grown'], 'The grown list still satisfies the server.');

    $this->assertStringContainsString('<ol class="s-' . $list_id . '">', $nested['indented'], 'The root list kept its id across the indent.');
    preg_match('/First<\/p><ol class="s-([a-z0-9]{3})">/', $nested['indented'], $nested_match);
    $this->assertNotEmpty($nested_match, 'The nested wrapper minted.');
    $nested_id = $nested_match[1];
    $this->assertNotSame($list_id, $nested_id, 'The nested wrapper is a new element with a fresh id.');
    $indented_ids = $this->ids($nested['indented']);
    $this->assertCount(6, $indented_ids, 'Both wrappers, both items, both paragraphs.');
    $this->assertSame($indented_ids, array_unique($indented_ids), 'All identities stay unique at depth.');
    $this->assertServerNoOp($nested['indented'], 'The nested list matches the server universal walk.');

    // Nested typing is identity-stable: no re-mints, no fresh ids.
    $this->assertSame($indented_ids, $this->ids($nested['typed']), 'Typing in the nested item minted nothing.');
    $this->assertServerNoOp($nested['typed'], 'The typed nested list stays a server no-op.');

    // Outdent: the indent-change reset strips the item's list-borne
    // identities, so it adopts the wrapper it rejoins (one visual list,
    // one wrapper, the original id) and mints a fresh item id; the
    // nested wrapper element is gone and its id with it. The paragraph's
    // sid rides through untouched.
    $outdented_ids = $this->ids($nested['outdented']);
    $this->assertMatchesRegularExpression(
      '/^<ol class="s-' . $list_id . '"><li[^>]*class="s-[a-z0-9]{3}"[^>]*><p class="s-[a-z0-9]{3}">First<\/p><\/li><li[^>]*class="s-[a-z0-9]{3}"[^>]*><p class="s-[a-z0-9]{3}">Second more<\/p><\/li><\/ol>$/',
      $nested['outdented'],
      'The outdented item rejoined the original wrapper.',
    );
    $this->assertCount(5, $outdented_ids, 'One wrapper, two items, two paragraphs.');
    $this->assertNotContains($nested_id, $outdented_ids, 'The nested wrapper id left with its element.');
    $this->assertSame(end($indented_ids), end($outdented_ids), 'The paragraph sid rode through the outdent.');
    $this->assertServerNoOp($nested['outdented'], 'The outdented list satisfies the server.');

    // ----- Blur commits a value staging accepts unchanged. -----
    // The client minted everything, so the tempstore round trip (stage
    // seam + presave backstop, both running IdentityMinter) is a no-op on
    // it: the re-rendered field carries exactly the ids the editor made.
    $expected_ids = $this->ids($session->evaluateScript($get_data));
    $session->executeScript(<<<JS
      window.__daedalusEditDoneCount = 0;
      const original = jQuery.fn.daedalusEditIsDoneUpdating;
      jQuery.fn.daedalusEditIsDoneUpdating = function () {
        window.__daedalusEditDoneCount++;
        return original.apply(this, arguments);
      };
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
      JS);
    $this->assertJsCondition('window.__daedalusEditDoneCount >= 1', 20000);
    $assert_session->waitForElement('css', $wrapper);
    $rendered_ids = $this->ids($session->evaluateScript(
      "document.querySelector('{$wrapper}').innerHTML",
    ));
    $this->assertSame(
      $expected_ids,
      $rendered_ids,
      'The staged, re-rendered field carries exactly the client-minted ids.',
    );
  }

}
