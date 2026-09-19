<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_api\Functional;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests the edit-history HTTP API: capture on mutate, timeline, restore.
 *
 * Runs with daedalus_tempstore installed so mutations stage in tempstore and the
 * strategy write tee captures them: each POST to the mutate endpoint is one
 * request and therefore one history entry.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class HistoryEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_test',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_tempstore',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user who can view and update entity_test entities.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $editor;

  /**
   * The test entity.
   *
   * @var \Drupal\entity_test\Entity\EntityTest
   */
  protected EntityTest $entity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    FieldStorageConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'entity_test',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'label' => 'Subtitle',
    ])->save();

    $this->entity = EntityTest::create([
      'name' => 'History test entity',
      'field_subtitle' => 'Saved subtitle',
    ]);
    $this->entity->save();

    $this->editor = $this->drupalCreateUser([
      'view test entity',
      'administer entity_test content',
    ]);
  }

  /**
   * Mutations extend the timeline; undo/redo/jump restore staged state.
   */
  public function testHistoryLifecycle(): void {
    $this->drupalLogin($this->editor);
    $token = $this->getCsrfToken();

    // Two labeled mutations = baseline + two entries.
    $payload = $this->mutate('First draft', 'Wrote the first draft', $token);
    $this->assertSame('tempstore', $payload['persisted']);
    $this->assertSame(1, $payload['history']['pointer']);
    $this->assertCount(2, $payload['history']['entries']);

    $payload = $this->mutate('Second draft', NULL, $token);
    $this->assertSame(2, $payload['history']['pointer']);
    $labels = array_column($payload['history']['entries'], 'label');
    $this->assertSame(['Initial state', 'Wrote the first draft', 'Edited field_subtitle'], $labels);

    // The GET side serves the same timeline.
    $this->drupalGet($this->historyUrl());
    $this->assertSession()->statusCodeEquals(200);
    $timeline = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame(2, $timeline['pointer']);
    $this->assertTrue($timeline['can_undo']);
    $this->assertFalse($timeline['can_redo']);

    // Undo: staged state reverts to the first draft; the response is the
    // mutation contract with the restored markup and the moved timeline.
    $payload = $this->restore(['direction' => 'undo'], $token);
    $this->assertTrue($payload['success']);
    $this->assertSame('Wrote the first draft', $payload['restored_label']);
    $this->assertSame(1, $payload['history']['pointer']);
    $this->assertTrue($payload['history']['can_redo']);
    $this->assertSame('First draft', $payload['blueprint']['fields']['field_subtitle']);
    $this->assertSame('First draft', $this->stagedSubtitle());
    $this->assertContains(
      ['uuid' => $this->entity->uuid(), 'field_name' => 'field_subtitle'],
      $payload['targets'],
    );

    // Undo to the absent baseline: the tempstore entry disappears and reads
    // fall back to the saved entity.
    $payload = $this->restore(['direction' => 'undo'], $token);
    $this->assertSame(0, $payload['history']['pointer']);
    $this->assertSame('Saved subtitle', $this->stagedSubtitle());

    // Nothing further to undo.
    $response = $this->postJson($this->historyUrl() . '/restore', ['direction' => 'undo'], $token);
    $this->assertSame(409, $response->getStatusCode());
    $error = json_decode((string) $response->getBody(), TRUE);
    $this->assertSame('NOTHING_TO_RESTORE', $error['errors'][0]['code']);

    // Jump straight to the newest entry.
    $payload = $this->restore(['to' => 2], $token);
    $this->assertSame(2, $payload['history']['pointer']);
    $this->assertSame('Second draft', $this->stagedSubtitle());

    // A new mutation while undone truncates the redo tail.
    $this->restore(['direction' => 'undo'], $token);
    $payload = $this->mutate('Third draft', 'Rewrote it', $token);
    $this->assertFalse($payload['history']['can_redo']);
    $this->assertSame('Third draft', $this->stagedSubtitle());
  }

  /**
   * The restore route is gated by update access and the CSRF header.
   */
  public function testAccess(): void {
    $viewer = $this->drupalCreateUser(['view test entity']);
    $this->drupalLogin($viewer);
    $this->drupalGet($this->historyUrl());
    $this->assertSession()->statusCodeEquals(403);
    $response = $this->postJson($this->historyUrl() . '/restore', ['direction' => 'undo'], $this->getCsrfToken());
    $this->assertSame(403, $response->getStatusCode());

    // An editor without the CSRF token is rejected.
    $this->drupalLogin($this->editor);
    $response = $this->postJson($this->historyUrl() . '/restore', ['direction' => 'undo']);
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * POSTs one field mutation and returns the decoded payload.
   */
  protected function mutate(string $subtitle, ?string $label, string $token): array {
    $body = [
      'operations' => [
        ['op' => 'update_element', 'properties' => ['field_subtitle' => $subtitle]],
      ],
    ];
    if ($label !== NULL) {
      $body['label'] = $label;
    }
    $response = $this->postJson($this->buildUrl('/daedalus-blueprint/entity_test/' . $this->entity->id()), $body, $token);
    $this->assertSame(200, $response->getStatusCode());
    return json_decode((string) $response->getBody(), TRUE);
  }

  /**
   * POSTs a restore and returns the decoded payload, asserting success.
   */
  protected function restore(array $body, string $token): array {
    $response = $this->postJson($this->historyUrl() . '/restore', $body, $token);
    $this->assertSame(200, $response->getStatusCode());
    return json_decode((string) $response->getBody(), TRUE);
  }

  /**
   * The staged subtitle as the blueprint read endpoint reports it.
   */
  protected function stagedSubtitle(): string {
    $this->drupalGet('/daedalus-blueprint/entity_test/' . $this->entity->id());
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    return $payload['blueprint']['fields']['field_subtitle'];
  }

  /**
   * Returns the history endpoint URL (relative for drupalGet, or absolute).
   */
  protected function historyUrl(): string {
    return '/daedalus-blueprint/entity_test/' . $this->entity->id() . '/history';
  }

  /**
   * POSTs a JSON body as the logged-in user.
   */
  protected function postJson(string $url, array $body, ?string $token = NULL): ResponseInterface {
    $headers = ['Content-Type' => 'application/json'];
    if ($token !== NULL) {
      $headers['X-CSRF-Token'] = $token;
    }
    if (!str_starts_with($url, 'http')) {
      $url = $this->buildUrl($url);
    }
    return $this->getHttpClient()->post($url, [
      'cookies' => $this->getSessionCookies(),
      'headers' => $headers,
      'body' => json_encode($body),
      'http_errors' => FALSE,
    ]);
  }

  /**
   * Fetches a CSRF token for the logged-in user.
   */
  protected function getCsrfToken(): string {
    $this->drupalGet('session/token');
    return $this->getSession()->getPage()->getContent();
  }

}
