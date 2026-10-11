<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Functional;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\daedalus_layout\AddPanel\BlockTypeFace;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Favorite for everyone on a block type: the write lands, the gate holds.
 *
 * The Add panel's row menu writes a block type's site default favorite
 * through one route in daedalus_layout
 * (`POST /daedalus-layout/block-type/{type}/favorite`, {favorite: bool}),
 * gated by the block type's update access (the bundle's own admin
 * permission, "administer block types") and the session's CSRF header
 * token. The write is the block type form's own (BlockTypeFace): TRUE is
 * stored, FALSE unsets the key, since absence is the default's one
 * spelling. An actor without the permission is refused whatever the
 * token; a bad token is refused whatever the permission; a body without
 * a boolean `favorite` is a 400 in the gestures' error shape.
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class BlockTypeFavoriteRouteTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block_content',
    'daedalus_layout',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The route's path for the test block type.
   */
  private const PATH = '/daedalus-layout/block-type/basic/favorite';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    BlockContentType::create([
      'id' => 'basic',
      'label' => 'Basic',
    ])->save();
  }

  /**
   * The write lands for the bundle's admin and is refused for anyone else.
   */
  public function testWriteAndGate(): void {
    // The bundle's admin: TRUE lands as the stored key.
    $this->drupalLogin($this->drupalCreateUser(['administer block types']));
    $token = $this->csrfToken();
    $response = $this->post(['favorite' => TRUE], $token);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSame(['id' => 'inline_block:basic', 'favorite' => TRUE], json_decode((string) $response->getBody(), TRUE));
    $this->assertTrue($this->storedFavorite(), 'The site default is stored on the block type.');

    // FALSE unsets the key: absence is the default's one spelling, as the
    // block type form writes it.
    $response = $this->post(['favorite' => FALSE], $token);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSame(['id' => 'inline_block:basic', 'favorite' => FALSE], json_decode((string) $response->getBody(), TRUE));
    $this->assertNull($this->storedFavorite(), 'FALSE is the key\'s absence, never a stored FALSE.');

    // A body without a boolean favorite is a 400 in the gestures' shape.
    foreach ([['favorite' => 'yes'], ['favorite' => 1], [], ['starred' => TRUE]] as $body) {
      $response = $this->post($body, $token);
      $this->assertSame(400, $response->getStatusCode(), json_encode($body));
      $errors = json_decode((string) $response->getBody(), TRUE)['errors'];
      $this->assertSame('INVALID_PAYLOAD', $errors[0]['code']);
      $this->assertSame('favorite', $errors[0]['path']);
    }
    $this->assertNull($this->storedFavorite(), 'A refused body writes nothing.');

    // The permission with a bad token is refused.
    $response = $this->post(['favorite' => TRUE], 'not-the-token');
    $this->assertSame(403, $response->getStatusCode());
    $this->assertNull($this->storedFavorite());

    // An actor without the permission is refused, token or not: the gate
    // is the bundle's own admin permission, not any editing grant.
    $this->drupalLogin($this->drupalCreateUser(['configure any layout', 'use daedalus ui edit mode']));
    $response = $this->post(['favorite' => TRUE], $this->csrfToken());
    $this->assertSame(403, $response->getStatusCode());
    $this->assertNull($this->storedFavorite(), 'An unprivileged actor writes nothing.');

    // Anonymous never reaches the route.
    $this->drupalLogout();
    $response = $this->post(['favorite' => TRUE], $this->csrfToken());
    $this->assertSame(403, $response->getStatusCode());
    $this->assertNull($this->storedFavorite());
  }

  /**
   * The stored favorite on a fresh load of the block type, or NULL.
   *
   * The web process wrote it; this process's config factory still holds
   * the config object it loaded last, so that cache is reset beside the
   * storage's before the read.
   */
  private function storedFavorite(): ?bool {
    $this->container->get('config.factory')->reset('block_content.type.basic');
    $storage = $this->container->get('entity_type.manager')->getStorage('block_content_type');
    $storage->resetCache(['basic']);
    return $storage->load('basic')->getThirdPartySetting(BlockTypeFace::NAMESPACE, BlockTypeFace::FAVORITE_SETTING);
  }

  /**
   * POSTs a JSON body to the route as the browser session.
   */
  private function post(array $body, string $token): ResponseInterface {
    return $this->getHttpClient()->post($this->buildUrl(self::PATH), [
      'cookies' => $this->getSessionCookies(),
      'headers' => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $token,
      ],
      'body' => json_encode($body),
      'http_errors' => FALSE,
    ]);
  }

  /**
   * The session's CSRF token, as the panel's JS fetches it.
   */
  private function csrfToken(): string {
    $this->drupalGet('session/token');
    return $this->getSession()->getPage()->getContent();
  }

}
