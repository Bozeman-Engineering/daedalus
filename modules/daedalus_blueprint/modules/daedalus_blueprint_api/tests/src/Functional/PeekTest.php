<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_api\Functional;

use Drupal\Component\Utility\Html;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * The peek door: a drop's batch, dry run, answered as a bare document.
 *
 * POST /daedalus-blueprint/{type}/{id}/peek takes the batch a drop would
 * send and the key of the element to show, applies the batch to the
 * staged working copy in memory and answers a document holding the host's
 * own style tag and the cut element, in the skin and with none of the
 * page around it (daedalus_ui's bare chromeless kind). It persists
 * nothing: the counts of nodes, block content, media and files are as
 * they were, a read after a peek shows no placement, and the history has
 * no new entry. A batch the spine refuses is 422 in the mutation
 * contract's error shape with no document; the door is gated as the
 * mutation door is (update access and the CSRF header). The canvas
 * renders in the posture the navigationMode query parameter selects: with
 * mode edit an empty block answers core's placeholder element, without
 * the mode the visitor posture drops it and the key is ELEMENT_NOT_FOUND.
 * Every failure answers the contract's shape: a placed block whose build
 * throws an \Error is 500 INTERNAL_ERROR as JSON, never a PHP fatal.
 *
 * The fixture is the recipe's product: the skin, the site frame's header
 * and footer slot doors and the text block type are the recipe's, so the
 * document is pinned against what a site serves.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class PeekTest extends BrowserTestBase {

  use DaedalusFixtureTrait;

  /**
   * The render test module, for its block whose build throws an \Error.
   *
   * Installed on top of the recipe's product; its view-alter probe records
   * a flag and nothing else, so the document legs are unaffected.
   *
   * @var string[]
   */
  protected static $modules = ['daedalus_blueprint_api_render_test'];

  /**
   * The document, the dry run and the gates.
   */
  public function testPeek(): void {
    $editor = $this->loginAsEditor();
    $node = Node::create(['type' => 'page', 'title' => 'Peek page']);
    // The page's own style map: the one style tag the document must carry
    // beside the element (an element's CSS never travels inside it).
    $node->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode([
      "entity:{$node->uuid()}" => ['default' => ['color' => '#123456']],
    ]));
    $node->save();
    $token = $this->csrfToken();
    $mutate = $this->buildUrl('/daedalus-blueprint/node/' . $node->id());
    $peek = $this->buildUrl('/daedalus-blueprint/node/' . $node->id() . '/peek');

    // The control: the page as the editor sees it carries the site frame's
    // header and footer slot doors and the skin, so the selectors the bare
    // document is pinned against are real.
    $this->drupalGet($node->toUrl());
    $assert_session = $this->assertSession();
    $assert_session->elementExists('css', '[data-daedalus-slot="header"]');
    $assert_session->elementExists('css', '[data-daedalus-slot="footer"]');
    $assert_session->elementExists('css', '.daedalus-stylish-token-scope');

    // A real mutation first, so the staged copy differs from the saved
    // entity (a section only the tempstore holds) and the history has
    // entries a peek must not extend: what the actor sees on the canvas is
    // what the peek builds on.
    $section = $this->container->get('uuid')->generate();
    $response = $this->post($mutate, [
      'operations' => [
        ['op' => 'add_section', 'uuid' => $section, 'location' => ['anchor' => $node->uuid(), 'placement' => 'inside']],
      ],
    ], $token);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSame('tempstore', json_decode((string) $response->getBody(), TRUE)['persisted']);
    $host = \Drupal::service('daedalus_blueprint.host_resolver')->fromEntity($node);
    $timeline = \Drupal::service('daedalus_tempstore.history_manager')->timelineFor($host);
    $history = \Drupal::service('daedalus_tempstore.history_store');
    $entries = $history->loadIndex($editor->id(), $timeline)['entries'];
    $this->assertNotEmpty($entries, 'The mutation left history to compare against.');
    $counts = $this->counts();

    // The peek: the drop's own batch (a text block into the staged
    // section, with the uuid the client minted), shown by that uuid.
    $block = $this->container->get('uuid')->generate();
    $batch = [
      'operations' => [
        [
          'op' => 'add_element',
          'uuid' => $block,
          'block_type' => 'text',
          'properties' => ['field_text_text' => ['value' => 'Peeked text']],
          'location' => ['anchor' => $section, 'placement' => 'inside'],
        ],
      ],
      'show' => "block:$block",
    ];
    $response = $this->post($peek, $batch, $token);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
    $document = (string) $response->getBody();
    $this->assertStringContainsString('Peeked text', $document);
    // The main content: the host's own style tag and the cut element.
    $this->assertElement($document, "style[data-daedalus-stylish-entity=\"{$node->uuid()}\"]", TRUE);
    $this->assertStringContainsString('#123456', $document);
    $this->assertElement($document, "[data-selectable=\"block:$block\"]", TRUE);
    $this->assertElement($document, "[data-selectable=\"container:$section\"]", FALSE);
    // The skin: the token scope wraps the content and the defaults link
    // rides the head.
    $this->assertElement($document, '.daedalus-stylish-token-scope', TRUE);
    $this->assertElement($document, 'link[rel="stylesheet"][href*="/daedalus_stylish/defaults-"]', TRUE);
    // None of the frame's slot doors, no page chrome, no pop-out chrome, no
    // Edit Mode, no heading.
    foreach ([
      '[data-daedalus-slot]',
      '#admin-toolbar',
      '.admin-toolbar-control-bar',
      '#daedalus-ui-right-sidebar',
      '#edit-mode-top-bar',
      'script[src*="edit-mode-app.bundle.js"]',
      'html.daedalus-ui-popout',
      'link[rel="stylesheet"][href*="daedalus_ui/css/popout.css"]',
      'script[src*="daedalus_ui/js/popout/keyboard.js"]',
      '.daedalus-ui-popout__mount',
      'h1.page-title',
      'html[data-daedalus-ui-chrome-density]',
    ] as $selector) {
      $this->assertElement($document, $selector, FALSE);
    }

    // Nothing persisted: the counts, the staged document and the history
    // are as they were.
    $this->assertSame($counts, $this->counts(), 'A peek saves nothing.');
    $this->drupalGet('/daedalus-blueprint/node/' . $node->id());
    $assert_session->statusCodeEquals(200);
    $read = $this->getSession()->getPage()->getContent();
    $this->assertStringContainsString($section, $read, 'The read is the staged copy: the mutated section is there.');
    $this->assertStringNotContainsString($block, $read, 'The peeked block was never placed.');
    $this->assertStringNotContainsString('Peeked text', $read);
    $this->assertSame($entries, $history->loadIndex($editor->id(), $timeline)['entries'], 'The history has no new entry.');

    // A second peek of the same batch answers the same: nothing of the
    // first one stuck.
    $response = $this->post($peek, $batch, $token);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringContainsString('Peeked text', (string) $response->getBody());
    $this->assertSame($counts, $this->counts());

    // A refused batch: the mutation contract's error shape, 422, no
    // document.
    $response = $this->post($peek, ['operations' => [['op' => 'polish_the_chrome']], 'show' => "block:$block"], $token);
    $this->assertSame(422, $response->getStatusCode());
    $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    $payload = json_decode((string) $response->getBody(), TRUE);
    $this->assertFalse($payload['success']);
    $this->assertNotEmpty($payload['errors']);
    $this->assertArrayHasKey('code', $payload['errors'][0]);
    $this->assertSame('node', $payload['entity_type']);
    $this->assertSame(['upsert' => [], 'remove' => []], $payload['materials']);
    $this->assertArrayNotHasKey('persisted', $payload);
    // A request outside the contract: no show, a show outside the grammar.
    foreach ([
      ['operations' => $batch['operations']],
      ['operations' => $batch['operations'], 'show' => "field:{$node->uuid()}:title"],
      ['operations' => $batch['operations'], 'show' => 'block:not-a-uuid'],
    ] as $body) {
      $response = $this->post($peek, $body, $token);
      $this->assertSame(400, $response->getStatusCode());
      $this->assertSame('INVALID_REQUEST', json_decode((string) $response->getBody(), TRUE)['errors'][0]['code']);
    }
    // An element the render does not stamp: the key names nothing. The
    // sentence is the editor's (the frame shows it in the element's place)
    // and the key rides in context.
    $missing = 'block:' . $this->container->get('uuid')->generate();
    $response = $this->post($peek, ['show' => $missing] + $batch, $token);
    $this->assertSame(404, $response->getStatusCode());
    $error = json_decode((string) $response->getBody(), TRUE)['errors'][0];
    $this->assertSame('ELEMENT_NOT_FOUND', $error['code']);
    $this->assertSame('Nothing to preview: the placed element rendered no markup.', $error['message']);
    $this->assertSame(['show' => $missing], $error['context']);
    $this->assertSame($counts, $this->counts());

    // The two postures. The door renders the canvas in the posture the
    // effective mode selects, and ModeState reads navigationMode from the
    // query before the cookie (the cookie is path-scoped to the edited
    // page, so every daedalus_blueprint_api client carries the mode as a
    // query parameter). With mode edit the editing posture gives an empty
    // block a body: core's placeholder, stamped with its label attribute
    // and the selectable key. Without the mode the visitor posture drops
    // the empty block and the key names nothing. A menu block of a menu
    // with no links is the fixture-free empty block: the recipe's one menu
    // link sits in main, the footer menu is bare.
    $empty = $this->container->get('uuid')->generate();
    $empty_batch = [
      'operations' => [
        [
          'op' => 'add_element',
          'uuid' => $empty,
          'plugin_id' => 'system_menu_block:footer',
          'location' => ['anchor' => $section, 'placement' => 'inside'],
        ],
      ],
      'show' => "block:$empty",
    ];
    $response = $this->post($peek . '?navigationMode=edit', $empty_batch, $token);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
    $this->assertElement((string) $response->getBody(), "[data-selectable=\"block:$empty\"][data-layout-content-preview-placeholder-label]", TRUE);
    $response = $this->post($peek, $empty_batch, $token);
    $this->assertSame(404, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSame('ELEMENT_NOT_FOUND', json_decode((string) $response->getBody(), TRUE)['errors'][0]['code']);
    $this->assertSame($counts, $this->counts(), 'A peek in either posture saves nothing.');

    // A placed block whose build throws an \Error (the render test module's
    // block asserts, the shape a failed assertion takes): the door catches
    // every Throwable and answers the contract's shape, 500 INTERNAL_ERROR
    // as JSON, where a catch of \Exception alone left a PHP fatal and core's
    // HTML 500 page.
    $throwing = $this->container->get('uuid')->generate();
    $response = $this->post($peek . '?navigationMode=edit', [
      'operations' => [
        [
          'op' => 'add_element',
          'uuid' => $throwing,
          'plugin_id' => 'daedalus_blueprint_api_render_test_throwing',
          'location' => ['anchor' => $section, 'placement' => 'inside'],
        ],
      ],
      'show' => "block:$throwing",
    ], $token);
    $this->assertSame(500, $response->getStatusCode(), (string) $response->getBody());
    $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    $payload = json_decode((string) $response->getBody(), TRUE);
    $this->assertFalse($payload['success']);
    $this->assertSame('INTERNAL_ERROR', $payload['errors'][0]['code']);
    $this->assertSame($counts, $this->counts(), 'A peek that fails saves nothing.');

    // The gates, as the mutation door's: no CSRF header, and an actor who
    // may not mutate the page.
    $response = $this->post($peek, $batch, NULL);
    $this->assertSame(403, $response->getStatusCode(), 'The CSRF header gates the door.');
    $this->drupalLogin($this->drupalCreateUser(['access content']));
    $response = $this->post($peek, $batch, $this->csrfToken());
    $this->assertSame(403, $response->getStatusCode(), 'Update access gates the door.');
    $this->assertSame($counts, $this->counts());
  }

  /**
   * Posts a JSON body as the logged-in user.
   */
  protected function post(string $url, array $body, ?string $token): ResponseInterface {
    $headers = ['Content-Type' => 'application/json'];
    if ($token !== NULL) {
      $headers['X-CSRF-Token'] = $token;
    }
    return $this->getHttpClient()->post($url, [
      'cookies' => $this->getSessionCookies(),
      'headers' => $headers,
      'body' => json_encode($body),
      'http_errors' => FALSE,
    ]);
  }

  /**
   * The logged-in user's CSRF token.
   */
  protected function csrfToken(): string {
    $this->drupalGet('session/token');
    return $this->getSession()->getPage()->getContent();
  }

  /**
   * Asserts a CSS selector matches, or matches nothing, in a document.
   */
  protected function assertElement(string $document, string $selector, bool $present): void {
    $xpath = new \DOMXPath(Html::load($document));
    $count = $xpath->query((new CssSelectorConverter())->toXPath($selector))->length;
    if ($present) {
      $this->assertGreaterThan(0, $count, "The document holds $selector.");
    }
    else {
      $this->assertSame(0, $count, "The document holds no $selector.");
    }
  }

  /**
   * The number of nodes, blocks, media items and files in storage.
   *
   * @return array<string, int>
   *   The counts, keyed by entity type ID.
   */
  protected function counts(): array {
    $counts = [];
    foreach (['node', 'block_content', 'media', 'file'] as $entity_type) {
      $counts[$entity_type] = (int) \Drupal::entityTypeManager()->getStorage($entity_type)->getQuery()->accessCheck(FALSE)->count()->execute();
    }
    return $counts;
  }

}
