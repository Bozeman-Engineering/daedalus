<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Functional;

use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;

/**
 * Chromeless documents: pop-outs, bare documents and preview carry no chrome.
 *
 * One spine (Chromeless) decides which documents render without page
 * chrome, and DaedalusUiHooks::preprocessHtml drops page_top and
 * page_bottom for them: core navigation's rail and top bar, daedalus_ui's
 * mode bars, sidebars and fabs, and everything those attach. Preview mode
 * (?navigationMode=preview, the responsive preview's iframe) and a bare
 * route (the _daedalus_ui.bare option) additionally veto the Edit mode
 * (ShouldNotEditMode), so no mode-bound panel or setting ships there
 * either. This tier sees all three directly in the served html; no
 * browser is needed.
 *
 * The bare site document is the third kind: one piece of site content
 * rendered alone in the skin. It takes core's simple_page variant, so
 * the site frame does not render around the content (the frame's header
 * and footer slot doors are the pin), keeps the skin as
 * preview mode does, wears nothing of the pop-out document chrome, and
 * builds no Edit Mode even when the request names the edit mode: the
 * peek door posts from the editing page, and the cookie is domain-wide in
 * this tier. The probe route is the daedalus_ui_bare_test module's, a
 * node's full view under the option, since the one consumer of the option
 * is daedalus_blueprint_api's peek door, whose own tier pins it.
 *
 * A pop-out route also wears the pop-out document chrome: the
 * daedalus-ui-popout class on the html element (the same preprocess hook),
 * the daedalus_ui/popout library (DaedalusUiHooks::pageAttachments: the
 * stylesheet that paints the window and neutralizes the theme around the
 * mount, and the keyboard half that senses every Edit Mode shortcut
 * pressed in the pop-out for the editing page's dispatcher) and the
 * display variant's mount wrapper the stylesheet anchors on. Preview mode
 * wears none of it: a site page with the editor gone, whose keys stay
 * native. The stylish satellite's own stylesheet is that consumer's
 * column layout only and attaches on its route alone (aggregation is off
 * in this tier, so the stylesheet links are honest; the keyboard script
 * is a native module served standalone in every tier).
 *
 * The skin is the other thing the drop must not decide. daedalus_stylish
 * delivers the site's look (the compiled defaults stylesheet and the token
 * scope element every token definition scopes to) through
 * hook_page_attachments and the off-canvas wrapper preprocess, seams the
 * page_top drop cannot reach, gated by the skin's own reach predicate
 * (_daedalus_stylish_skinless_document()): preview mode is a site page, so
 * the skin reaches it exactly as it reaches the editing page; a pop-out is
 * a tool window, so it carries neither the defaults link nor the scope
 * element. The four legs below pin both halves of that reach.
 *
 * The seam the preview-mode assertions guard: a preview page that still
 * carried the editing app also carried the stylish inspector's pop-out
 * settings (drupalSettings.daedalusStylish.popout) and the edit session's
 * identity, so the iframe became a SECOND leader on the edit session's
 * BroadcastChannel scope and answered the satellite's snapshot requests with
 * an empty selection on every reload. No popout settings, no session
 * identity, no second leader.
 *
 * The session identity is delivered once per document kind: the editing
 * page carries daedalusUi.editSession.scope (the Edit mode; every wire of
 * the session pairs on it, and every pop-out opener forwards it as
 * ?scope), a pop-out document carries the forwarded value as
 * daedalusUi.popout.scope (DaedalusUiHooks::pageAttachments, the same seam
 * that attaches the pop-out stylesheet, gated on the route option), and
 * neither carries the other's. The responsivePreview setting on the editing
 * page is the opener's URL alone; no feature setting carries a scope of its
 * own.
 */
#[Group('daedalus_ui')]
class PopoutDocumentTest extends BrowserTestBase {

  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
    \Drupal::service('module_installer')->install(['daedalus_ui_bare_test']);
    $this->rebuildContainer();
  }

  /**
   * The chrome and the edit bundle on the editing page, and nowhere else.
   */
  public function testChromelessDocuments(): void {
    $this->loginAsEditor();
    $node = Node::create([
      'type' => 'page',
      'title' => 'Pop-out document page',
    ]);
    $node->save();
    // One text block on the page, so a document of the page has content
    // to find (the recipe's Page template is blank and the bundle has no
    // body field): written as a capture writes a document, then committed.
    $host = \Drupal::service('daedalus_blueprint.host_resolver')->fromEntity($node);
    $result = \Drupal::service('daedalus_blueprint.document_instantiator')->instantiateDocument($host, [
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'children' => [
          [
            'kind' => 'region',
            'region' => 'content',
            'children' => [
              [
                'kind' => 'block',
                'block_type' => 'text',
                'block_fields' => ['field_text_text' => ['value' => 'Bare probe text']],
              ],
            ],
          ],
        ],
      ],
    ], [], ['indexed' => TRUE, 'batch' => ['system' => TRUE, 'admitted' => TRUE]]);
    $this->assertTrue($result->isSuccess(), implode("\n", array_map(static fn ($error) => $error->getPath() . ': ' . $error->getMessage(), $result->getErrors())));
    \Drupal::service('daedalus_blueprint.storage_handler')->commit($host);

    $assert_session = $this->assertSession();

    // The control: the editing page (cookie-rendered arrival in edit mode)
    // carries every piece of chrome the chromeless documents must not, the
    // edit session's identity, the preview opener's URL (and no scope of
    // its own), the stylish inspector's pop-out URL (again no scope of its
    // own), and nothing a pop-out document carries.
    $this->getSession()->setCookie('navigationMode', 'edit');
    $this->drupalGet($node->toUrl());
    $assert_session->elementExists('css', 'html[data-daedalus-ui-mode="edit"]');
    $this->assertPageChrome(TRUE);
    $this->assertPopoutDocument(FALSE);
    $this->assertSkin(TRUE);
    $assert_session->elementExists('css', '#edit-mode-top-bar');
    $settings = $this->getDrupalSettings();
    $scope = $settings['daedalusUi']['editSession']['scope'] ?? NULL;
    $this->assertIsString($scope);
    $this->assertNotSame('', $scope);
    $this->assertSame(['url'], array_keys($settings['daedalusUi']['responsivePreview']));
    $this->assertSame(['url'], array_keys($settings['daedalusStylish']['popout']));
    $this->assertStringContainsString('scope=' . rawurlencode($scope), $settings['daedalusStylish']['popout']['url']);
    $this->assertArrayNotHasKey('popout', $settings['daedalusUi']);
    // The site frame on the editing page: the header and footer slot doors
    // the recipe's frame places (SlotBlock::DOOR_ATTRIBUTE, stamped for the
    // editor's permission), which the bare document must not carry.
    $assert_session->elementExists('css', '[data-daedalus-slot="header"]');
    $assert_session->elementExists('css', '[data-daedalus-slot="footer"]');
    $assert_session->pageTextContains('Bare probe text');

    // A bare route, with the edit cookie still set: the page's content in
    // the skin and nothing else. No chrome and no frame slot door (core's
    // simple_page variant), the skin kept (the token scope and the
    // defaults link), nothing of the pop-out chrome, no Edit Mode (no top
    // bar, no edit bundle, no density stamp, no session identity of either
    // kind, no pop-out settings), and no heading, since the route declares
    // no title.
    $this->drupalGet('/daedalus-ui-bare-test/' . $node->id());
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Bare probe text');
    $this->assertPageChrome(FALSE);
    $this->assertPopoutDocument(FALSE);
    $this->assertSkin(TRUE);
    $assert_session->elementNotExists('css', '[data-daedalus-slot]');
    $assert_session->elementNotExists('css', '#edit-mode-top-bar');
    $assert_session->elementNotExists('css', 'html[data-daedalus-ui-chrome-density]');
    $assert_session->elementNotExists('css', 'h1.page-title');
    $settings = $this->getDrupalSettings();
    $this->assertArrayNotHasKey('popout', $settings['daedalusStylish'] ?? []);
    $this->assertArrayNotHasKey('editSession', $settings['daedalusUi'] ?? []);
    $this->assertArrayNotHasKey('popout', $settings['daedalusUi'] ?? []);
    $this->getSession()->setCookie('navigationMode', NULL);

    // Preview mode: a site page (the node still renders in the theme) with
    // the editor gone. The mode stamp says preview, no chrome, no mode bar,
    // no edit bundle, no pop-out settings and no session identity of either
    // kind (the second-leader seam); the skin stays, since it is what the
    // preview shows.
    $this->drupalGet($node->toUrl(), ['query' => ['navigationMode' => 'preview']]);
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextContains('Pop-out document page');
    $assert_session->elementExists('css', 'html[data-daedalus-ui-mode="preview"]');
    $this->assertPageChrome(FALSE);
    $this->assertPopoutDocument(FALSE);
    $this->assertSkin(TRUE);
    $assert_session->elementNotExists('css', '#edit-mode-top-bar');
    $assert_session->elementNotExists('css', 'html[data-daedalus-ui-chrome-density]');
    $settings = $this->getDrupalSettings();
    $this->assertArrayNotHasKey('popout', $settings['daedalusStylish'] ?? []);
    $this->assertArrayNotHasKey('editSession', $settings['daedalusUi'] ?? []);
    $this->assertArrayNotHasKey('popout', $settings['daedalusUi'] ?? []);

    // A pop-out route: the responsive preview shell renders its mount and
    // nothing of the page chrome, no page title either (the pop-out display
    // variant; the route's _title is the window's <title> only), and no
    // skin: a tool window is outside the skin's reach. The scope the opener
    // forwarded arrives as the pop-out document's session identity, and the
    // shell's own setting carries none.
    $this->drupalGet('/daedalus-ui/responsive-preview', ['query' => ['url' => $node->toUrl()->toString(), 'scope' => 'probe']]);
    $assert_session->statusCodeEquals(200);
    $assert_session->elementExists('css', '#daedalus-ui-responsive-preview-root');
    $this->assertPageChrome(FALSE);
    $this->assertPopoutDocument(TRUE);
    $this->assertSkin(FALSE);
    $assert_session->elementExists('css', '.daedalus-ui-popout__mount #daedalus-ui-responsive-preview-root');
    // A pop-out is a bare document too: no frame slot door around the mount.
    $assert_session->elementNotExists('css', '[data-daedalus-slot]');
    // The stylish satellite's stylesheet is that consumer's own column
    // layout, not the pop-out chrome: it never rides another pop-out.
    $assert_session->elementNotExists('css', 'link[rel="stylesheet"][href*="daedalus_stylish/css/satellite.css"]');
    $assert_session->elementNotExists('css', '#edit-mode-top-bar');
    $assert_session->elementNotExists('css', 'h1.page-title');
    $assert_session->titleEquals('Responsive preview | Drupal');
    $settings = $this->getDrupalSettings();
    $this->assertSame('probe', $settings['daedalusUi']['popout']['scope']);
    $this->assertArrayNotHasKey('scope', $settings['daedalusUi']['responsivePreview']);
    $this->assertArrayNotHasKey('editSession', $settings['daedalusUi']);

    // The other pop-out consumer: the stylish inspector satellite. Its
    // "Styles" heading is the panel header the inspector renders itself, so
    // the served document carries the title in <title> and no h1. The same
    // session identity, from the same seam.
    $this->drupalGet('/daedalus_stylish/inspector-popout', ['query' => ['scope' => 'probe']]);
    $assert_session->statusCodeEquals(200);
    $this->assertPageChrome(FALSE);
    $this->assertPopoutDocument(TRUE);
    $this->assertSkin(FALSE);
    $assert_session->elementExists('css', '.daedalus-ui-popout__mount #daedalus-stylish-inspector-root');
    $assert_session->elementNotExists('css', '[data-daedalus-slot]');
    $assert_session->elementExists('css', 'link[rel="stylesheet"][href*="daedalus_stylish/css/satellite.css"]');
    $assert_session->elementNotExists('css', 'h1.page-title');
    $assert_session->titleEquals('Styles | Drupal');
    $settings = $this->getDrupalSettings();
    $this->assertSame('probe', $settings['daedalusUi']['popout']['scope']);
    $this->assertArrayNotHasKey('editSession', $settings['daedalusUi']);
    $this->assertArrayNotHasKey('popout', $settings['daedalusStylish']);
  }

  /**
   * Asserts the pop-out document chrome is present, or absent.
   *
   * The html class the pop-out stylesheet anchors on (preprocess_html), the
   * stylesheet and the keyboard half (page_attachments, the one library)
   * and the display variant's mount wrapper: all four from the spine, on
   * every pop-out route and on nothing else (the editing page runs the
   * leader's own sensor inside its bundle; preview mode senses nothing).
   */
  private function assertPopoutDocument(bool $present): void {
    $assert_session = $this->assertSession();
    $selectors = [
      'html.daedalus-ui-popout',
      'link[rel="stylesheet"][href*="daedalus_ui/css/popout.css"]',
      'script[type="module"][src*="daedalus_ui/js/popout/keyboard.js"]',
      '.daedalus-ui-popout__mount',
    ];
    foreach ($selectors as $selector) {
      if ($present) {
        $assert_session->elementExists('css', $selector);
      }
      else {
        $assert_session->elementNotExists('css', $selector);
      }
    }
  }

  /**
   * Asserts the skin is present, or absent.
   *
   * The compiled defaults stylesheet's link (daedalus_stylish/defaults, a
   * hash-busted file under the site's files directory, attached from
   * hook_page_attachments) and the token scope element (stamped from the
   * off-canvas wrapper preprocess): the two halves of the skin's reach, on
   * site pages (the editing page, preview mode) and on no pop-out.
   */
  private function assertSkin(bool $present): void {
    $assert_session = $this->assertSession();
    $selectors = [
      'link[rel="stylesheet"][href*="/daedalus_stylish/defaults-"]',
      '.daedalus-stylish-token-scope',
    ];
    foreach ($selectors as $selector) {
      if ($present) {
        $assert_session->elementExists('css', $selector);
      }
      else {
        $assert_session->elementNotExists('css', $selector);
      }
    }
  }

  /**
   * Asserts the page_top chrome and the edit bundle are present, or absent.
   *
   * The pieces are the ones a chromeless document used to leak: core
   * navigation's rail and its control bar, daedalus_ui's right sidebar (the
   * stylish inspector's aside) and the edit-mode bundle script.
   */
  private function assertPageChrome(bool $present): void {
    $assert_session = $this->assertSession();
    $selectors = [
      '#admin-toolbar',
      '.admin-toolbar-control-bar',
      '#daedalus-ui-right-sidebar',
      'script[src*="edit-mode-app.bundle.js"]',
    ];
    foreach ($selectors as $selector) {
      if ($present) {
        $assert_session->elementExists('css', $selector);
      }
      else {
        $assert_session->elementNotExists('css', $selector);
      }
    }
  }

}
