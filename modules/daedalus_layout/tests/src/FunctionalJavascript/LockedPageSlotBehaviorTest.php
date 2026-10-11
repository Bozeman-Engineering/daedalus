<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\FunctionalJavascript;

use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_ui\Render\BlockStandIn;
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
 * A locked page fills its template's slot in place, as the editor.
 *
 * A page of a locked bundle (Layout Builder on, overrides off) renders
 * its template as a foreign document and owns only what the template's
 * slot blocks let it fill: slot sections in its own override field,
 * rendered inside the slot by the one render primitive. The spine judges
 * every node by the host whose document holds it, stamps the verdict on
 * every read, and the client asks the one gate for every gesture. This
 * scenario carries the whole editing path end to end in the browser, as
 * the recipe's editor, who may fill the slot (the bundle's override
 * permission, defined by daedalus_layout for a locked bundle) and cannot
 * open the template.
 *
 * The seam only a browser sees: the multi-host render's attribution
 * reaching the client's selection (the template's nodes inert, the slot
 * door selectable for insert alone, the page's slot section and its
 * block live), and the mutation response re-rendering by key across the
 * two hosts (the placement into an empty door swaps the whole page with
 * the minted slot section inside the slot, a later removal empties it
 * again), then Save committing the page's own document while the
 * template is untouched. In scenario order:
 * - THE LOCKED PAGE IN EDIT MODE: the template's text block renders inert,
 *   the slot's stand-in reads open, the Add panel is mounted (insert is
 *   allowed somewhere: on the door).
 * - THE INSERT VERB ON THE DOOR: selecting the door and pressing the
 *   Insert key arms click-to-place on it; a row click places a text
 *   block inside the slot through one POST, the response rendering the
 *   page's slot section inside the slot block with the template around
 *   it, the mirror reading one slot-tagged section of the page's own.
 * - SAVE: the page's own document commits with the slot section, the
 *   display is as it was, the saved page shows the slot's content, and
 *   Edit Mode re-entered reads the same.
 * - EMPTYING: removing the slot's last block through the door's own
 *   grammar empties the slot (the section goes with it), the stand-in
 *   is back, and Save leaves the page with no section.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site) plus a
 * locked 'slotted' node type built in setUp: its default display holds a
 * container with a text block (a value block, as a display stores one)
 * and the hero slot block; the setUp adds hero to the recipe's slot
 * roster. A recipe-tier fixture adds to the roster and never replaces it,
 * because the site frame places the roster's slots and a dropped slot
 * swallows the page. One fixture, one scenario: a full site install from
 * the recipe is the dominant cost of a run, so all behavior lives in the
 * single test method.
 *
 * @group daedalus_layout
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class LockedPageSlotBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * The locked bundle.
   */
  private const BUNDLE = 'slotted';

  /**
   * The template's identities.
   */
  private const CONTAINER_UUID = '7b000000-0000-4000-8000-000000000001';
  private const TEXT_UUID = '7b000000-0000-4000-8000-000000000002';
  private const DOOR_UUID = '7b000000-0000-4000-8000-000000000003';

  /**
   * The slot the template places.
   */
  private const SLOT = 'hero';

  /**
   * The template text block's text.
   */
  private const TEMPLATE_TEXT = 'Template band text';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->daedalusSetUp();

    // The locked bundle: the page builder on (a type needs the daedalus_ui
    // `enabled` setting before the edit-mode bundle attaches at all),
    // arriving outside Edit Mode so the cookie entry is the one door.
    $type = NodeType::create([
      'type' => self::BUNDLE,
      'name' => 'Slotted',
      'new_revision' => TRUE,
    ]);
    $type->setThirdPartySetting('daedalus_ui', 'enabled', TRUE);
    $type->setThirdPartySetting('daedalus_ui', 'initial_mode', 'none');
    $type->save();

    // The hero slot joins the recipe's roster; the roster save rebuilds the
    // block definitions. A recipe-tier fixture adds to the roster and never
    // replaces it: the site frame places the roster's own slots (main,
    // header, footer), and dropping one leaves its placement on core's
    // broken fallback, which renders nothing, so the page is swallowed.
    $config = \Drupal::configFactory()->getEditable(SlotBlock::CONFIG_NAME);
    $config
      ->set(SlotBlock::ROSTER_KEY, (array) $config->get(SlotBlock::ROSTER_KEY) + [
        self::SLOT => ['label' => 'Hero', 'tier' => SlotRoster::TIER_TEMPLATE],
      ])
      ->save();

    // The template: Layout Builder on, overrides OFF, one container holding
    // a text block (a value block, the way a display stores inline content)
    // and the hero slot block. Enabled and saved first: core's display
    // preSave moves the display's default components (the node's `links`
    // extra field) into the first section on the enable transition, so
    // the template's one section is set on a second save to hold exactly
    // these two components.
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => self::BUNDLE,
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->save();
    $section = new Section('daedalus_stylish_container');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', self::CONTAINER_UUID);
    $section->appendComponent(new SectionComponent(self::TEXT_UUID, 'content', [
      'id' => 'inline_block:text',
      'label' => 'Template text',
      'label_display' => '0',
      'block_values' => ['field_text_text' => self::TEMPLATE_TEXT],
    ]));
    $section->appendComponent(new SectionComponent(self::DOOR_UUID, 'content', [
      'id' => SlotBlock::PLUGIN_ID . ':' . self::SLOT,
      'label' => 'Hero',
      'label_display' => '0',
      'context_mapping' => SlotBlock::CONTEXT_MAPPING,
      SlotBlock::DEFAULT_KEY => NULL,
    ]));
    $display->removeAllSections()->appendSection($section)->save();

    // The consumer contract of the recipe's editor role: a bundle created
    // after install grants its own permissions. Update access is the host
    // access policy's door; the override permission (core's name, defined
    // by daedalus_layout for the locked bundle) is the road to the page's
    // own slots. Nothing opens the template.
    Role::load('daedalus_editor')
      ->grantPermission('create ' . self::BUNDLE . ' content')
      ->grantPermission('edit any ' . self::BUNDLE . ' content')
      ->grantPermission('configure editable ' . self::BUNDLE . ' node layout overrides')
      ->save();
  }

  /**
   * Installs a counter of blueprint POSTs, per page load.
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
   * Waits for the blueprint mirror to boot on the current page, on a root.
   */
  private function waitForMirror(string $root_uuid, string $message): void {
    $this->assertJsCondition(
      "document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'"
      . " && window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint"
      . " && Drupal.Daedalus.Blueprint.getRoot()?.uuid === '$root_uuid'"
      . " && Drupal.Daedalus.Hotkeys && typeof Drupal.Daedalus.Hotkeys.register === 'function'",
      20000,
      $message,
    );
  }

  /**
   * Clicks the top bar's Save and waits for the landing.
   *
   * Save waits for the op queue to drain and navigates to the tempstore
   * save route, whose redirect lands on the page itself: a window
   * variable stamped before the click vanishes exactly when the browser
   * swaps documents.
   */
  private function save(): void {
    $session = $this->getSession();
    $session->executeScript("window.__preSave = true; document.querySelector('#daedalus-ui-save').click();");
    $this->assertTrue(
      $session->wait(20000, "window.__preSave === undefined && document.readyState === 'complete'"),
      'The Save button navigated and landed a document.',
    );
  }

  /**
   * Re-enters Edit Mode on the current page after its own save's redirect.
   *
   * The save's redirect plants a path-scoped navigationMode=none cookie
   * that outranks the session-wide edit cookie, so edit is planted back
   * on the same path and the page reloaded.
   */
  private function reenterEditMode(string $root_uuid, string $message): void {
    $session = $this->getSession();
    $session->executeScript("document.cookie = 'navigationMode=edit; path=' + window.location.pathname + ';';");
    $session->reload();
    $this->waitForMirror($root_uuid, $message);
  }

  /**
   * The slot sections of a stored node's own document, slot id to block count.
   *
   * @return array<string, int>
   *   Slot id to the number of components in its section.
   */
  private function storedSlots(int $nid): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    $slots = [];
    foreach ($storage->load($nid)->get('layout_builder__layout')->getSections() as $section) {
      $slots[(string) LayoutSerializer::slotOf($section)] = count($section->getComponents());
    }
    return $slots;
  }

  /**
   * The locked page's slot, filled in place, saved, and emptied again.
   */
  public function testLockedPageSlot(): void {
    $this->loginAsEditor();
    $session = $this->getSession();
    $assert_session = $this->assertSession();

    $node = Node::create([
      'type' => self::BUNDLE,
      'title' => 'Slotted page',
    ]);
    $node->save();
    $nid = (int) $node->id();
    $node_uuid = $node->uuid();

    $container_key = 'container:' . self::CONTAINER_UUID;
    $text_key = 'block:' . self::TEXT_UUID;
    $door_key = 'block:' . self::DOOR_UUID;
    $door = "[data-main-entity] [data-selectable='$door_key']";
    $slot_section = "$door [data-selectable^='container:']";
    $slot_open = BlockStandIn::SLOT_OPEN;
    $door_attribute = SlotBlock::DOOR_ATTRIBUTE;
    $slot = self::SLOT;

    // ——— 1. The locked page in Edit Mode: template inert, door open. ———
    //
    // The cookie entry renders the full chrome with the mirror booted on
    // the page (rooted on the node, reading the template's document then
    // the page's own, empty) and the spine's verdicts.
    $this->drupalGet($node->toUrl());
    $session->resizeWindow(1600, 1400);
    $this->enterEditModeViaCookies($node->toUrl());
    $this->waitForMirror($node_uuid, 'The locked page renders in Edit Mode with the mirror rooted on the page.');
    $this->assertJsCondition(
      "JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('" . self::CONTAINER_UUID . "')"
      . " && (Drupal.Daedalus.Blueprint.getBlueprint().layout ?? []).length === 1",
      20000,
      'The page reads the template\'s document and owns no section yet.',
    );
    $this->countPosts();

    // The published verdict through the one gate: the template's container
    // and text block allow nothing; the door keeps insert alone and is
    // therefore selectable; the lean node names the slot it declares.
    $this->assertJsCondition(
      "(() => { const S = Drupal.Daedalus.Selection;"
      . " return S.selectable('$container_key') === false && S.selectable('$text_key') === false"
      . " && S.selectable('$door_key') === true && S.capable('$door_key', 'insert') === true"
      . " && ['content', 'configure', 'style', 'arrange'].every((c) => S.capable('$door_key', c) === false)"
      . " && Drupal.Daedalus.Blueprint.componentForUuid(Drupal.Daedalus.Blueprint.getBlueprint(), '" . self::DOOR_UUID . "')?.slot === '$slot'; })()",
      20000,
      'The gate answers from the published verdict: the template is refused everything, the door keeps insert, the node names its slot.',
    );
    $this->assertJsCondition(
      "(() => { const door = document.querySelector(\"$door\"); return !!door"
      . " && door.getAttribute('data-block-stand-in') === '$slot_open'"
      . " && door.getAttribute('$door_attribute') === '$slot'"
      . " && door.textContent.includes('Hero') && door.textContent.includes('Empty slot')"
      . " && door.querySelector(\"[data-selectable^='container:']\") === null; })()",
      20000,
      'The empty slot wears its open stand-in, named for the drag layer, with no section inside.',
    );
    $this->assertJsCondition(
      "document.getElementById('place_block') !== null",
      20000,
      'The Add panel is mounted: insert is allowed somewhere (on the door).',
    );
    $text_block_js = "document.querySelector(\"[data-main-entity] [data-selectable='$text_key']\")";
    $this->assertJsCondition("!!$text_block_js && $text_block_js.textContent.includes('" . self::TEMPLATE_TEXT . "')", 20000, 'The template\'s text block renders on the page.');

    // ——— 2. The Insert verb on the door: click-to-place into the slot. ———
    //
    // Selecting the door and pressing the Insert key arms click-to-place
    // on the door itself (its own insert verdict, not its parent's) and
    // opens the Add panel; the row click sends one add_element anchored
    // inside the door, which the op door lands in the page's slot
    // section, minted for it. The response re-renders the page: the
    // template around, the slot section inside the slot block.
    $session->executeScript("Drupal.Daedalus.Selection.select('$door_key');");
    $this->assertJsCondition("Drupal.Daedalus.Selection.getSelected() === '$door_key'", 10000, 'The door is selected.');
    $this->pressHotkey('i');
    $this->assertJsCondition(
      "document.documentElement.classList.contains('daedalus-layout-insert-pending')"
      . " && !document.getElementById('place_block').classList.contains('daedalus-ui-hidden')",
      10000,
      'The Insert verb armed click-to-place on the door and opened the Add panel.',
    );
    $text_row = $this->revealAddPanelRow('inline_block:text');
    $session->executeScript("document.querySelector('$text_row').click();");
    $this->assertJsCondition('window.__blueprintPosts === 1', 20000, 'The text block click was one blueprint POST.');
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$slot_section [data-layout-builder-block-uuid]", 20000),
      'The page\'s slot section rendered inside the slot block with the text block in it.',
    );
    $placed_uuid = (string) $session->evaluateScript("document.querySelector(\"$slot_section [data-layout-builder-block-uuid]\").dataset.layoutBuilderBlockUuid");
    $this->assertNotEmpty($placed_uuid, 'The placed block carries its component uuid.');
    $placed_key = "block:$placed_uuid";
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === '$placed_key' && document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      'The placement selected the placed block and kept the session armed.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "!document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      'Escape ended the insert session.',
    );

    // The mirror: the template's section, then one section of the page's
    // own, tagged with the slot, holding the placed block; the door no
    // longer wears a stand-in; the template is as it was.
    $this->assertJsCondition(
      "(() => { const layout = Drupal.Daedalus.Blueprint.getBlueprint().layout;"
      . " return layout.length === 2 && layout[0].uuid === '" . self::CONTAINER_UUID . "' && layout[1].slot === '$slot'"
      . " && JSON.stringify(layout[1]).includes('$placed_uuid'); })()",
      20000,
      'The mirror reads the page\'s slot section after the template.',
    );
    // The page elements panel: the fill nests under its door. The mirror
    // prints the fill as a root section tagged with the slot; the panel's
    // projection pairs it with the door declaring that slot
    // (slot-fills.js), so the door's row shows the fill's container row
    // and its block beneath it, the picture the canvas renders. The seam:
    // the real bundle reading the delivered mirror's slot tags and the
    // tree's rendered rows, which the node tier never renders.
    $door_row_js = "document.querySelector('.daedalus-ui-tree__row[data-tree-key=\"$door_key\"]')";
    $this->assertJsCondition(
      "(() => { const rows = [...document.querySelectorAll('.daedalus-ui-tree__row')];"
      . " const row = (key) => rows.find((r) => r.getAttribute('data-tree-key') === key);"
      . " const level = (r) => Number(r.getAttribute('aria-level'));"
      . " const door = row('$door_key'); const fill = row(document.querySelector(\"$slot_section\").getAttribute('data-selectable')); const block = row('$placed_key');"
      . " return !!door && !!fill && !!block && door.getAttribute('data-tree-door') === '$slot'"
      . " && rows.indexOf(fill) === rows.indexOf(door) + 1 && level(fill) === level(door) + 1"
      . " && rows.indexOf(block) === rows.indexOf(fill) + 1 && level(block) === level(fill) + 1; })()",
      20000,
      'The page elements panel shows the slot section and its block under the door\'s row.',
    );
    $this->assertJsCondition(
      "(() => { const door = document.querySelector(\"$door\"); return !door.hasAttribute('data-block-stand-in')"
      . " && !!$text_block_js && $text_block_js.textContent.includes('" . self::TEMPLATE_TEXT . "'); })()",
      20000,
      'The door shows the fill instead of its stand-in and the template\'s text block is still there.',
    );
    $this->assertJsCondition(
      "(() => { const S = Drupal.Daedalus.Selection; const section = document.querySelector(\"$slot_section\").getAttribute('data-selectable');"
      . " return S.selectable('$placed_key') && S.capable('$placed_key', 'content') && S.capable('$placed_key', 'arrange')"
      . " && S.selectable(section) && S.capable(section, 'insert') && S.capable(section, 'arrange')"
      . " && S.selectable('$text_key') === false; })()",
      20000,
      'The slot section and its block are the page\'s own on the client; the template stays inert.',
    );
    $this->assertSame([], $this->storedSlots($nid), 'The placement is staged, not committed to the node.');

    // ——— 3. Save: the page's own document commits, the template untouched. ———
    $this->save();
    $this->assertSame($node->toUrl()->toString(), (string) $session->evaluateScript('window.location.pathname'), 'Save landed on the page.');
    $this->assertSame([self::SLOT => 1], $this->storedSlots($nid), 'The page\'s own document holds one slot section with the placed block.');
    $display = LayoutBuilderEntityViewDisplay::load('node.' . self::BUNDLE . '.default');
    $this->assertFalse($display->isOverridable(), 'The bundle is still locked.');
    $this->assertSame([self::TEXT_UUID, self::DOOR_UUID], array_keys($display->getSection(0)->getComponents()), 'The template is as it was: the text block and the slot block.');
    $this->assertNotNull($assert_session->waitForElement('css', "$slot_section [data-layout-builder-block-uuid='$placed_uuid']", 20000), 'The saved page renders the slot\'s content inside the slot block.');
    $this->assertJsCondition("!!$text_block_js && $text_block_js.textContent.includes('" . self::TEMPLATE_TEXT . "')", 20000, 'And the template\'s text block.');
    $this->reenterEditMode($node_uuid, 'The page re-enters Edit Mode after its save.');
    $this->countPosts();
    $this->assertJsCondition(
      "(() => { const layout = Drupal.Daedalus.Blueprint.getBlueprint().layout; return layout.length === 2 && layout[1].slot === '$slot'; })()",
      20000,
      'The saved slot section reads back on the canvas.',
    );

    // ——— 4. Emptying: the last block leaving the slot empties it. ———
    //
    // One remove_element through the door's own grammar (the verb the chip
    // offers sends the same op): the section goes with its last element,
    // the response renders the page with the door empty again.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{ op: 'remove_element', element: '$placed_uuid' }],
        })).catch((error) => {
          window.__removeError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__removeError !== undefined || (Drupal.Daedalus.Blueprint.getBlueprint().layout ?? []).length === 1",
      20000,
      'The removal resolved.',
    );
    $this->assertNull($session->evaluateScript('window.__removeError ?? null'), 'Removing the slot\'s block failed: ' . ($session->evaluateScript('window.__removeError ?? null') ?? ''));
    $this->assertJsCondition('window.__blueprintPosts === 1', 10000, 'The removal was one blueprint POST.');
    $this->assertJsCondition(
      "(() => { const door = document.querySelector(\"$door\"); return !!door"
      . " && door.getAttribute('data-block-stand-in') === '$slot_open'"
      . " && door.querySelector(\"[data-selectable^='container:']\") === null"
      . " && !!$text_block_js; })()",
      20000,
      'The slot is empty again: the stand-in is back, the template around it.',
    );
    $this->assertJsCondition(
      "(() => { const rows = [...document.querySelectorAll('.daedalus-ui-tree__row')]; const door = $door_row_js;"
      . " const next = rows[rows.indexOf(door) + 1];"
      . " return !!door && !door.hasAttribute('aria-expanded')"
      . " && (!next || Number(next.getAttribute('aria-level')) <= Number(door.getAttribute('aria-level'))); })()",
      20000,
      'The door\'s row is a leaf again.',
    );
    $this->save();
    $this->assertSame([], $this->storedSlots($nid), 'Save left the page with no section of its own.');
  }

}
