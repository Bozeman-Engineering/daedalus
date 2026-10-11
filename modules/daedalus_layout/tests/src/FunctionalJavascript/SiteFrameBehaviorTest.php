<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint_library\BlueprintInterface;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The site frame around a page: its header edited at its root, then claimed.
 *
 * Every front-end route of daedalus_theme renders inside the recipe's site
 * frame (a `daedalus_frame` config entity rendered by daedalus_layout's
 * page display variant): the frame's header and footer slot doors show the
 * `header` and `footer` site_chrome blueprints as their defaults, and the
 * main slot takes the route's output. On a page in Edit Mode the frame's
 * nodes are a foreign document seen from the page: the spine judges each
 * node by the host whose document holds it, the attributes subscriber
 * stamps the verdict's portal on the placement root, and the client's
 * Portal component paints the chip and takes the double-click through the
 * one leave. This scenario carries the two gestures that cross the frame's
 * boundary end to end in the browser, as one person who is both the
 * recipe's editor (the page's gestures) and its site builder (the chrome's
 * canvas and the frame's).
 *
 * The seams only a browser sees:
 * - THE PORTAL FROM A FRAME HOST: the header door, held by the frame and
 *   showing its default, wears the BLUEPRINT's portal (the door showing a
 *   default reads as the linked placement it is), and the frame's nodes
 *   are outside the page's projection (never selectable). Hovering inside the door paints the
 *   chip with the header's label, an Edit link to its canvas and the
 *   claim; the double-click leaves through Portal.leave() for the
 *   blueprint canvas carrying the page as `destination`, the canvas's
 *   first visit lands in Edit Mode (the server's arming, no cookie of the
 *   page's reaches it), and Save there returns to the page in ITS mode
 *   (the page's own path-scoped edit cookie, untouched by the canvas's
 *   `none`).
 * - SLOT CLAIMING ACROSS FRAME AND PAGE IN ONE RENDER: the chip's "Detach
 *   into this slot" sends detach_blueprint {slot} for the page and reloads
 *   (the frame is outside the mutation response); the page's own copy of
 *   the header, a slot-tagged section of its document with provenance,
 *   renders inside the frame's door ONCE (a fill renders where its slot
 *   is and nowhere else) as the page's own selection, the door drops its
 *   claim stamp and wears the frame's portal, Save commits the fill into
 *   the page's document and it reads back; another page of the same
 *   theme still shows the default with its claim.
 * - THE TREE FOLLOWS THE RENDER UNDER THE DOOR: on the frame's own canvas
 *   the header door's row reads "Header slot" and grows a shown-document
 *   row named by the blueprint, no selection, whose menu is its doorways
 *   (Edit alone there: the frame's own door is not claimable), and Edit
 *   from the row leaves through the one leave with the canvas as
 *   `destination` (seam: the annotation's `showing` -> the projection's
 *   shown row -> the row's doorway -> Portal.leave()).
 * - THE DOOR HOSTS THE BLUEPRINT CANVAS: the leave lands on the header's
 *   canvas framed, the canvas root inside the frame's header door and the
 *   main slot showing the stand-in, the hosting door wearing no portal,
 *   and Save returns to the frame canvas (seam: RouteOutput's host ->
 *   InnerRender::routeSlot() -> SlotBlock -> `[data-main-entity]` inside
 *   `[data-daedalus-slot="header"]`).
 *
 * The portal leaves from entity pages, by decision (Tim, 2026-10-08). A
 * host-less front-end route (a view such as the front page, the login
 * form, a 404) has no Edit Mode and so no portal: ShouldNotEditMode vetoes
 * the mode where there is no host and no canvas, so no Portal component
 * mounts there although the frame's stamps are rendered (PageVariantTest
 * pins the stamps on view.frontpage.page_1). A chrome-only Edit Mode for
 * such routes (the top bar and the Portal component with no canvas root)
 * is a sibling topic on the board, not a step this scenario is waiting to
 * grow: this class is complete as the entity-page scenario.
 *
 * The fixture is DaedalusFixtureTrait (the recipe's site: daedalus_theme's
 * frame, the two chrome blueprints, the slot roster) and two `page` nodes
 * of its own. The frame's and the header's identities are the recipe's
 * shipped uuids, pinned here as EditModeBehaviorTest pins the footer's: a
 * recipe content change that renames them fails the first assertion that
 * reads one. One fixture, one scenario: a full site install from the
 * recipe is the dominant cost of a run, so all behavior lives in the
 * single test method.
 *
 * @group daedalus_layout
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class SiteFrameBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait;

  /**
   * The frame's header slot door (daedalus_blueprint.frame.daedalus_theme).
   */
  private const HEADER_DOOR_UUID = 'c59a4674-73c1-4279-87c0-9c9bda293640';

  /**
   * The header blueprint's one section (daedalus_site_chrome content).
   */
  private const HEADER_SECTION_UUID = '6f021a00-1598-4951-98be-0d5ff0dd54c7';

  /**
   * The header blueprint's branding block, inside that section.
   */
  private const BRANDING_UUID = 'da951346-d197-403a-83e0-62b8ef6601fc';

  /**
   * The header's machine name: the slot id and the default are both it.
   */
  private const HEADER = 'header';

  /**
   * Creates and logs in a person who is both the editor and the site builder.
   *
   * The frame's chrome is the site builder's (the `site_chrome` blueprint
   * type, the frame's canvas) while claiming a slot is the page's gesture
   * (the editor's override permission on the bundle). The recipe composes
   * the two roles on one person who does both; this scenario needs that
   * person, so it departs from the fixture's editor-only persona here and
   * nowhere else. No admin bypass: every step still proves the two roles'
   * grants suffice.
   */
  private function loginAsSiteBuildingEditor(): UserInterface {
    $account = $this->drupalCreateUser();
    $account->addRole('daedalus_editor');
    $account->addRole('daedalus_site_builder');
    $account->save();
    $this->drupalLogin($account);
    return $account;
  }

  /**
   * Renders a page in Edit Mode through its own path-scoped cookie.
   *
   * The cookie a real entry plants (the toggle, a new page's redirect) is
   * scoped to the page's path, so leaving for another canvas and coming
   * back finds the page in its mode while the canvas keeps its own. Mink's
   * setCookie() has no path (domain-wide, which would arm every later
   * page including the blueprint canvas and hide the server's arming), so
   * the cookie is written from the page itself, then the page is rendered
   * again: the server builds the full Edit Mode chrome from it.
   */
  private function enterEditModeOnPath(Url $url): void {
    $this->drupalGet($url);
    $this->getSession()->executeScript("document.cookie = 'navigationMode=edit; path=' + window.location.pathname + ';';");
    $this->drupalGet($url);
  }

  /**
   * Waits for the blueprint mirror to boot in Edit Mode on a root.
   */
  private function waitForMirror(string $root_uuid, string $message): void {
    $this->assertJsCondition(
      "document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'"
      . " && window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint"
      . " && Drupal.Daedalus.Blueprint.getRoot()?.uuid === '$root_uuid'"
      . " && Drupal.Daedalus.Portal && typeof Drupal.Daedalus.Portal.leave === 'function'",
      20000,
      $message,
    );
  }

  /**
   * Clicks the top bar's Save and waits for the landing.
   *
   * A window variable stamped before the click vanishes exactly when the
   * browser swaps documents (LockedPageSlotBehaviorTest's rule).
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
   * Dispatches a pointer move onto an element, as the portal chip listens.
   *
   * Dispatched on the element (the Portal component reads event.target),
   * not driven: the frame's header sits under the fixed top bar in the
   * headless layout.
   */
  private function hover(string $selector): void {
    $this->getSession()->executeScript(sprintf(
      "(() => { const el = document.querySelector(%s); el.scrollIntoView({ block: 'center' });"
      . " const r = el.getBoundingClientRect();"
      . " el.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: r.left + 4, clientY: r.top + 4 })); })();",
      json_encode($selector),
    ));
  }

  /**
   * The slot sections of a stored node's own document, slot id to block count.
   *
   * An overridable page's document also holds its untagged sections (the
   * template seeded on first read); only the fills are read here.
   *
   * @return array<string, int>
   *   Slot id to the number of components in its section.
   */
  private function storedSlots(int $nid): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    $slots = [];
    foreach ($storage->load($nid)->get('layout_builder__layout')->getSections() as $section) {
      $slot = LayoutSerializer::slotOf($section);
      if ($slot !== NULL) {
        $slots[$slot] = count($section->getComponents());
      }
    }
    return $slots;
  }

  /**
   * The header edited through the frame's portal, then claimed by one page.
   */
  public function testSiteFrame(): void {
    $this->loginAsSiteBuildingEditor();
    $session = $this->getSession();
    $assert_session = $this->assertSession();

    $header_blueprints = \Drupal::entityTypeManager()->getStorage(BlueprintInterface::ENTITY_TYPE_ID)
      ->loadByProperties(['machine_name' => self::HEADER]);
    $header = reset($header_blueprints);
    $this->assertInstanceOf(BlueprintInterface::class, $header, 'The recipe shipped the header site_chrome blueprint.');
    $header_url = \Drupal::service('daedalus_ui.ui')->getEditingUrl($header)->toString();
    $frame = Frame::load('daedalus_theme');
    $this->assertNotNull($frame, 'The recipe shipped daedalus_theme\'s site frame.');
    $frame_url = $frame->toUrl()->toString();
    $frame_label = (string) $frame->label();

    $page = Node::create(['type' => 'page', 'title' => 'Framed page']);
    $page->save();
    $other = Node::create(['type' => 'page', 'title' => 'Other framed page']);
    $other->save();
    $page_path = $page->toUrl()->toString();

    $door_key = 'block:' . self::HEADER_DOOR_UUID;
    $door = "[data-selectable='$door_key']";
    $branding = "[data-selectable='block:" . self::BRANDING_UUID . "']";
    $default_section = "[data-selectable='container:" . self::HEADER_SECTION_UUID . "']";
    $portal_chip = '#daedalus-root .daedalus-ui-frame__chip--portal';
    $claim_button = "$portal_chip [data-portal-action='daedalus_layout.claim_slot']";
    $door_attribute = SlotBlock::DOOR_ATTRIBUTE;
    $claim_attribute = SlotBlock::CLAIM_ATTRIBUTE;
    $slot = self::HEADER;

    // ——— 1. The page in Edit Mode: the frame around it, foreign. ———
    //
    // The header door shows its default: the blueprint's own section with
    // its branding block renders inside the frame's door, outside the
    // page's main entity. The door reads as the linked placement it is
    // (the blueprint's portal, for an actor who may update the header) and
    // offers the claim (the door is open for this page, the default is a
    // published blueprint, nothing beneath fills it). None of it is in
    // the page's projection.
    $this->drupalGet($page->toUrl());
    $session->resizeWindow(1600, 1400);
    $this->enterEditModeOnPath($page->toUrl());
    $this->waitForMirror($page->uuid(), 'The page renders in Edit Mode with the mirror rooted on it.');
    $this->assertNotNull($assert_session->waitForElement('css', "$door $default_section $branding", 20000), 'The frame\'s header door renders the header blueprint\'s section and branding block as its default.');
    $this->assertJsCondition(
      "(() => { const door = document.querySelector(\"$door\"); return !!door"
      . " && door.closest('[data-main-entity]') === null"
      . " && door.getAttribute('$door_attribute') === '$slot'"
      . " && door.getAttribute('$claim_attribute') === '" . self::HEADER . "'"
      . " && door.getAttribute('data-daedalus-ui-portal-url') === " . json_encode($header_url)
      . " && door.getAttribute('data-daedalus-ui-portal-label') === " . json_encode($header->label())
      . " && !JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('" . self::HEADER_SECTION_UUID . "'); })()",
      20000,
      'The header door is outside the main entity, names its slot, offers the claim of its default, and is the blueprint\'s portal; the default is absent from the page\'s document.',
    );
    $this->assertJsCondition(
      "(() => { const S = Drupal.Daedalus.Selection;"
      . " return S.selectable('$door_key') === false && S.selectable('block:" . self::BRANDING_UUID . "') === false; })()",
      20000,
      'The frame\'s nodes and the default\'s are outside the page\'s projection: nothing in the frame is selectable.',
    );

    // ——— 2. The portal: hover paints the chip, double-click leaves. ———
    $this->hover($branding);
    $this->assertNotNull($assert_session->waitForElement('css', $portal_chip, 20000), 'Hovering inside the header door painted the portal chip.');
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector(\"$portal_chip\");"
      . " return chip.querySelector('.daedalus-ui-frame__name').textContent === " . json_encode($header->label())
      . " && chip.querySelector('a.daedalus-ui-frame__verb').getAttribute('href') === " . json_encode($header_url)
      . " && !!chip.querySelector(\"[data-portal-action='daedalus_layout.claim_slot']\")"
      . " && Drupal.Daedalus.Selection.getHovered() === null; })()",
      20000,
      'The chip reads the door\'s stamps: the header\'s label, an Edit link to its canvas and the claim; the page\'s hover names nothing.',
    );
    // The double-click leaves for the blueprint canvas carrying this page
    // as the way back. The session's edit cookie is scoped to the page's
    // path, so only the canvas route's own first-visit arming can render
    // the landing in Edit Mode.
    $this->dispatchDoubleClick($branding);
    $this->assertTrue(
      $session->wait(20000, "document.readyState === 'complete' && window.location.pathname === " . json_encode($header_url)),
      'Double-clicking inside the header door navigated to the header blueprint\'s canvas.',
    );
    $this->assertSame(
      'destination=' . rawurlencode($page_path),
      (string) $session->evaluateScript('window.location.search.slice(1)'),
      'The leave carried the page as the canvas\'s destination.',
    );
    $this->waitForMirror($header->uuid(), 'The blueprint canvas landed in Edit Mode with the mirror rooted on the header: the server armed the first visit.');
    $this->assertNotNull($assert_session->waitForElement('css', "[data-main-entity] $branding", 20000), 'On its own canvas the header is the main entity: the branding block is inside the universe.');
    $this->assertJsCondition(
      "Drupal.Daedalus.Blueprint.rootContext().entityType === 'blueprint' && !!document.querySelector('#edit-mode-top-bar #daedalus-ui-save')",
      20000,
      'The canvas edits the blueprint entity and offers Save.',
    );

    // Save on the canvas: the redirect goes where the leave said, and the
    // canvas's own `none` cookie never touches the page's path, so the
    // page comes back in its mode.
    $this->save();
    $this->assertSame($page_path, (string) $session->evaluateScript('window.location.pathname'), 'Save on the blueprint canvas returned to the page it was left from.');
    $this->waitForMirror($page->uuid(), 'The page is back in Edit Mode, the mirror rooted on it again.');

    // ——— 3. The claim: the header detached into this page's slot. ———
    //
    // The chip's "Detach into this slot" is the page's gesture made on the
    // portal: one detach_blueprint {slot} through the one door, landing the
    // default's tree as the page's own slot section with provenance, then
    // a reload (the frame's door sits outside the mutation response).
    $this->hover($branding);
    $this->assertNotNull($assert_session->waitForElement('css', $claim_button, 20000), 'The chip offers "Detach into this slot" on the default.');
    $session->executeScript("window.__preClaim = true; document.querySelector(\"$claim_button\").click();");
    $this->assertTrue(
      $session->wait(20000, "window.__preClaim === undefined && document.readyState === 'complete'"),
      'The claim submitted and reloaded the page.',
    );
    $this->waitForMirror($page->uuid(), 'The page re-rendered in Edit Mode after the claim.');
    $fill = "$door [data-selectable^='container:']";
    $this->assertNotNull($assert_session->waitForElement('css', "$fill [data-layout-builder-block-uuid]", 20000), 'The frame\'s header door renders a section of the page\'s own with blocks in it.');
    $fill_uuid = (string) $session->evaluateScript("document.querySelector(\"$fill\").getAttribute('data-selectable').slice('container:'.length)");
    $this->assertNotSame(self::HEADER_SECTION_UUID, $fill_uuid, 'The fill is a copy with fresh identity, not the blueprint\'s section.');
    $fill_key = "container:$fill_uuid";
    $this->assertJsCondition(
      "(() => { const layout = Drupal.Daedalus.Blueprint.getBlueprint().layout ?? [];"
      . " const fill = layout.find((node) => node.uuid === '$fill_uuid');"
      . " return !!fill && fill.slot === '$slot' && fill.blueprint === '" . self::HEADER . "'"
      . " && (fill.children ?? []).length > 0; })()",
      20000,
      'The mirror reads the fill as a top-level section of the page\'s own, tagged with the slot and stamped with the header\'s provenance.',
    );
    $this->assertJsCondition(
      "document.querySelectorAll(\"[data-selectable='$fill_key']\").length === 1"
      . " && document.querySelector(\"$default_section\") === null"
      . " && document.querySelector(\"$branding\") === null",
      20000,
      'The fill renders once, where its slot is, and the default is gone from this page.',
    );
    $this->assertJsCondition(
      "(() => { const door = document.querySelector(\"$door\");"
      . " return !door.hasAttribute('$claim_attribute') && door.getAttribute('$door_attribute') === '$slot'"
      . " && door.getAttribute('data-daedalus-ui-portal-url') === " . json_encode($frame_url) . "; })()",
      20000,
      'The filled door offers no claim and wears the frame\'s portal: the fill inside it is the page\'s.',
    );
    $this->assertJsCondition(
      "(() => { const S = Drupal.Daedalus.Selection;"
      . " const block = document.querySelector(\"$fill [data-selectable^='block:']\").getAttribute('data-selectable');"
      . " return S.selectable('$fill_key') && S.capable('$fill_key', 'insert') && S.capable('$fill_key', 'arrange')"
      . " && S.selectable(block) && S.capable(block, 'arrange') && S.selectable('$door_key') === false; })()",
      20000,
      'The fill and its blocks are the page\'s own on the client; the frame\'s door stays foreign.',
    );
    $this->assertSame([], $this->storedSlots((int) $page->id()), 'The claim is staged, not committed to the page.');

    // Save: the fill commits into the page's document and reads back.
    $this->save();
    $this->assertSame($page_path, (string) $session->evaluateScript('window.location.pathname'), 'Save landed on the page.');
    $this->assertSame([self::HEADER => 2], $this->storedSlots((int) $page->id()), 'The page\'s own document holds the header\'s two blocks as its header slot section.');
    $this->enterEditModeOnPath($page->toUrl());
    $this->waitForMirror($page->uuid(), 'The page re-enters Edit Mode after its save.');
    $this->assertJsCondition(
      "(() => { const layout = Drupal.Daedalus.Blueprint.getBlueprint().layout ?? [];"
      . " return layout.some((node) => node.uuid === '$fill_uuid' && node.slot === '$slot')"
      . " && !!document.querySelector(\"$door [data-selectable='$fill_key']\")"
      . " && !document.querySelector(\"$door\").hasAttribute('$claim_attribute'); })()",
      20000,
      'The saved fill reads back inside the frame\'s door, the claim still taken.',
    );

    // ——— 4. Another page of the theme keeps the default. ———
    $this->enterEditModeOnPath($other->toUrl());
    $this->waitForMirror($other->uuid(), 'The other page renders in Edit Mode.');
    $this->assertNotNull($assert_session->waitForElement('css', "$door $default_section $branding", 20000), 'The other page\'s header door still shows the blueprint\'s default.');
    $this->assertJsCondition(
      "(() => { const door = document.querySelector(\"$door\");"
      . " return door.getAttribute('$claim_attribute') === '" . self::HEADER . "'"
      . " && door.getAttribute('data-daedalus-ui-portal-url') === " . json_encode($header_url)
      . " && !(Drupal.Daedalus.Blueprint.getBlueprint().layout ?? []).some((node) => node.slot === '$slot'); })()",
      20000,
      'The other page may still claim the default and owns no header fill: the claim replaced the frame\'s default on one page only.',
    );

    // ——— 5. The frame canvas: the tree follows the render under the door. ———
    //
    // On the frame's own canvas the header door is the frame's node, and
    // the Page elements tree says what is behind it: the door's row reads
    // the annotator's words ("Header slot", typed "Slot") and grows a
    // shown-document row named by the blueprint ("Header", typed
    // "Blueprint, by reference") that is no selection. The row's menu is
    // its doorways: Edit, through the one leave with the canvas as the way
    // back. The claim is not among them here, by the spine's answer: a door
    // is claimed from the page it frames, never from the frame's own canvas
    // (its showing reads `claimable: false`), so the row offers Edit alone
    // and the chip would too. The seam: the annotation's `showing` ->
    // the projection's shown row -> the row's doorway -> Portal.leave().
    $this->drupalGet($frame_url);
    $this->waitForMirror($frame->uuid(), 'The frame canvas landed in Edit Mode with the mirror rooted on the frame: the server armed the first visit.');
    $door_row = ".daedalus-ui-tree__row[data-tree-key='$door_key']";
    $shown_row = ".daedalus-ui-tree__row--shown[data-tree-shown='$door_key']";
    $this->assertNotNull($assert_session->waitForElement('css', $shown_row, 20000), 'The header door\'s row grew a shown-document row in the Page elements tree.');
    $this->assertJsCondition(
      "(() => { const rows = [...document.querySelectorAll('.daedalus-ui-tree__row')];"
      . " const door = document.querySelector(\"$door_row\"); const shown = document.querySelector(\"$shown_row\");"
      . " const level = (r) => Number(r.getAttribute('aria-level'));"
      . " const name = (r) => r.querySelector('.daedalus-ui-outline__name').textContent;"
      . " return !!door && !!shown && name(door) === 'Header slot' && door.getAttribute('title') === 'Slot'"
      . " && name(shown) === " . json_encode($header->label()) . " && shown.getAttribute('title') === 'Blueprint, by reference'"
      . " && !shown.hasAttribute('data-tree-key') && rows.indexOf(shown) === rows.indexOf(door) + 1 && level(shown) === level(door) + 1; })()",
      20000,
      'The door row reads "Header slot" typed Slot, and the shown-document row under it names the header blueprint, typed "Blueprint, by reference", with no selectable key.',
    );
    $this->assertJsCondition(
      "(() => { const node = Drupal.Daedalus.Blueprint.componentForUuid(Drupal.Daedalus.Blueprint.getBlueprint(), '" . self::HEADER_DOOR_UUID . "');"
      . " const showing = node?._element?.showing; return !!showing && showing.shows === 'default' && showing.blueprint === '" . self::HEADER . "'"
      . " && showing.portal?.url === " . json_encode($header_url) . " && showing.claimable === false; })()",
      20000,
      'The door\'s annotation carries the spine\'s showing: the header by default, its portal, not claimable from the frame\'s own canvas.',
    );
    // A click on the shown row selects nothing: the shown document is no
    // element of this host.
    $session->executeScript("document.querySelector(\"$shown_row\").click();");
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === null",
      10000,
      'Clicking the shown-document row selected nothing.',
    );
    // The row's menu: Edit alone, on the shared menu primitive.
    $session->executeScript(
      "(() => { const row = document.querySelector(\"$shown_row\"); const rect = row.getBoundingClientRect();"
      . " row.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true,"
      . ' clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2 })); })();'
    );
    $menu_labels = "Array.from(document.querySelectorAll('.daedalus-ui-popover > .daedalus-ui-menu .daedalus-ui-menu__item .daedalus-ui-menu__label')).map((label) => label.textContent)";
    $this->assertJsCondition(
      "JSON.stringify($menu_labels) === " . json_encode(json_encode(['Edit'])),
      20000,
      'The shown-document row\'s menu offers Edit and, the frame\'s own door being unclaimable, no "Detach into this slot".',
    );
    // Edit from the row: the one leave, carrying the frame canvas as the
    // way back. The header canvas's first-visit arming was spent (and
    // proven) in step 2 and its Save left a `none` cookie on its path, so
    // the landing's mode is written for it here, path-scoped as a real
    // entry writes it: this leg proves the leave and the framed landing,
    // not the arming.
    $session->executeScript("document.cookie = 'navigationMode=edit; path=" . $header_url . ";';");
    $session->executeScript("document.querySelector('.daedalus-ui-popover > .daedalus-ui-menu .daedalus-ui-menu__item').click();");
    $this->assertTrue(
      $session->wait(20000, "document.readyState === 'complete' && window.location.pathname === " . json_encode($header_url)),
      'Edit on the shown-document row navigated to the header blueprint\'s canvas.',
    );
    $this->assertSame(
      'destination=' . rawurlencode($frame_url),
      (string) $session->evaluateScript('window.location.search.slice(1)'),
      'The leave carried the frame canvas as the destination.',
    );

    // ——— 6. The blueprint canvas renders framed: the door hosts it. ———
    //
    // The leave lands on the header's canvas inside the frame: the canvas
    // root is the frame's header door's content (RouteOutput's host ->
    // InnerRender::routeSlot() -> SlotBlock) and the main slot shows the
    // stand-in, so the header is edited where it is seen. The door hosting
    // the canvas wears no portal, as main does on a page. Save returns to
    // the frame canvas the leave came from.
    $this->waitForMirror($header->uuid(), 'The blueprint canvas is in Edit Mode with the mirror rooted on the header.');
    $this->assertNotNull($assert_session->waitForElement('css', "[$door_attribute='$slot'] [data-main-entity] $branding", 20000), 'The canvas root renders inside the frame\'s header door, the branding block inside the universe.');
    $this->assertJsCondition(
      "(() => { const main = document.querySelector(\"[$door_attribute='" . FrameHost::MAIN_SLOT . "']\");"
      . " const door = document.querySelector(\"[$door_attribute='$slot']\");"
      . " return !!main && main.textContent.includes(\"Each page's content shows here.\") && !main.querySelector('[data-main-entity]')"
      . " && !!door && !door.hasAttribute('data-daedalus-ui-portal-url') && !door.hasAttribute('$claim_attribute')"
      . " && document.querySelectorAll('[data-main-entity]').length === 1; })()",
      20000,
      'The main slot shows the stand-in, the header door hosts the one canvas root and wears neither a portal nor a claim.',
    );
    $this->save();
    $this->assertSame($frame_url, (string) $session->evaluateScript('window.location.pathname'), 'Save on the framed blueprint canvas returned to the frame canvas it was left from.');
  }

}
