<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Functional;

use Drupal\daedalus_ui\EditorSettings;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;

/**
 * The chrome density stamp follows the edit chrome, not the active mode.
 *
 * The per-user "Editing chrome" preference reaches CSS and the reveal engine
 * as one html attribute, data-daedalus-ui-chrome-density, stamped server-side in
 * daedalus_ui_preprocess_html. The edit chrome (top bar, sidebar panels)
 * is server-rendered present-but-hidden on every page where Edit mode is
 * available, and the client-side toggle reveals it by flipping the mode
 * stamp alone. So the density stamp must be present on that same set of
 * pages, mode active or not: a stamp gated on the active mode is absent
 * after an AJAX entry, and every consumer reads the absence as full density
 * (labeled top bar buttons at minimal, secondary chrome shown at clean)
 * until a reload. This tier sees that failure directly in the served html;
 * no browser is needed.
 *
 * @group daedalus_ui
 */
class ChromeDensityStampTest extends BrowserTestBase {

  use DaedalusFixtureTrait;

  /**
   * The stamp on pages where the chrome renders, and only there.
   */
  public function testDensityStampFollowsChromePresence(): void {
    $editor = $this->loginAsEditor();
    // The same map the save route writes (EditorSettings::set), set directly:
    // the subject is the stamp, not the save route.
    $editor->set(EditorSettings::FIELD_NAME, ['daedalus_ui' => ['chrome_density' => 'minimal']]);
    $editor->save();

    $node = Node::create([
      'type' => 'page',
      'title' => 'Chrome density stamp page',
    ]);
    $node->save();

    $assert_session = $this->assertSession();

    // Out of mode (no navigationMode cookie): the chrome is present but
    // hidden, the mode stamp is absent, and the density stamp is already
    // there for the client-side entry to reveal correctly.
    $this->drupalGet($node->toUrl());
    $assert_session->elementExists('css', '#edit-mode-top-bar');
    $assert_session->elementNotExists('css', 'html[data-daedalus-ui-mode]');
    $assert_session->elementExists('css', 'html[data-daedalus-ui-chrome-density="minimal"]');

    // In mode (cookie-rendered arrival): both stamps.
    $this->getSession()->setCookie('navigationMode', 'edit');
    $this->drupalGet($node->toUrl());
    $assert_session->elementExists('css', 'html[data-daedalus-ui-mode="edit"]');
    $assert_session->elementExists('css', 'html[data-daedalus-ui-chrome-density="minimal"]');
    $this->getSession()->setCookie('navigationMode', NULL);

    // A user without Edit mode gets no chrome, and so no density stamp: the
    // predicate is the chrome's presence, not authentication.
    $this->drupalLogin($this->drupalCreateUser());
    $this->drupalGet($node->toUrl());
    $assert_session->elementNotExists('css', '#edit-mode-top-bar');
    $assert_session->elementNotExists('css', 'html[data-daedalus-ui-chrome-density]');
  }

}
