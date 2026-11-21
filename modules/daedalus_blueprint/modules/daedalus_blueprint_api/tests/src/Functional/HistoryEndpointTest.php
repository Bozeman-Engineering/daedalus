<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_api\Functional;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests the edit-history HTTP API: capture on mutate, timeline, restore.
 *
 * Runs with daedalus_tempstore installed so mutations stage in tempstore and
 * the strategy write tee captures them: each POST to the mutate endpoint is one
 * request and therefore one history entry. daedalus_edit is installed for
 * the inline editing permission the history routes ask, and for Save.
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
    'daedalus_edit',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * What an editor of the test entity holds.
   */
  protected const EDITOR_PERMISSIONS = [
    'view test entity',
    'administer entity_test content',
    'access inline editing',
  ];

  /**
   * A user who can view, update and inline-edit entity_test entities.
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

    $this->editor = $this->drupalCreateUser(self::EDITOR_PERMISSIONS);
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
    $this->assertSame('undo', $payload['restored_direction']);
    $this->assertSame(
      [2 => 'Edited field_subtitle'],
      array_column($payload['restored_changes'], 'label', 'seq'),
      'The answer names the change the undo took back, not the entry it landed on.',
    );
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
   * The timeline is the page's: a second editor sees it, and may step it.
   */
  public function testTimelineIsSharedByEditors(): void {
    $this->drupalLogin($this->editor);
    $this->mutate('First draft', 'Wrote the first draft', $this->getCsrfToken());

    $second = $this->drupalCreateUser(self::EDITOR_PERMISSIONS);
    $this->drupalLogin($second);
    $token = $this->getCsrfToken();
    $payload = $this->mutate('Second draft', 'Wrote the second draft', $token);
    $entries = $payload['history']['entries'];
    $this->assertSame(['Initial state', 'Wrote the first draft', 'Wrote the second draft'], array_column($entries, 'label'));
    $this->assertSame(
      [NULL, $this->editor->getDisplayName(), $second->getDisplayName()],
      array_column($entries, 'author'),
      'Each change names who made it.',
    );

    // Two undos by the second editor walk back over both editors' changes;
    // each answer names the change it took back and who made it.
    $payload = $this->restore(['direction' => 'undo'], $token);
    $this->assertSame('First draft', $this->stagedSubtitle());
    $this->assertSame('undo', $payload['restored_direction']);
    $this->assertSame([
      [
        'seq' => 2,
        'label' => 'Wrote the second draft',
        'uid' => (int) $second->id(),
        'author' => $second->getDisplayName(),
      ],
    ], $payload['restored_changes']);
    $payload = $this->restore(['direction' => 'undo'], $token);
    $this->assertSame(0, $payload['history']['pointer']);
    $this->assertSame([
      [
        'seq' => 1,
        'label' => 'Wrote the first draft',
        'uid' => (int) $this->editor->id(),
        'author' => $this->editor->getDisplayName(),
      ],
    ], $payload['restored_changes'], 'An undo of another editor\'s change names that editor.');

    // A redo names the change it put back.
    $payload = $this->restore(['direction' => 'redo'], $token);
    $this->assertSame('redo', $payload['restored_direction']);
    $this->assertSame('Wrote the first draft', $payload['restored_changes'][0]['label']);
    $this->assertSame($this->editor->getDisplayName(), $payload['restored_changes'][0]['author']);
    $payload = $this->restore(['direction' => 'undo'], $token);
    $this->assertSame(0, $payload['history']['pointer']);

    // The first editor reads the same, moved timeline.
    $this->drupalLogin($this->editor);
    $this->drupalGet($this->historyUrl());
    $timeline = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame(0, $timeline['pointer']);
    $this->assertTrue($timeline['can_redo']);
  }

  /**
   * A Save by one editor leaves no editor an entry to restore.
   */
  public function testSaveEndsTheTimelineForEveryEditor(): void {
    $this->drupalLogin($this->editor);
    $token = $this->getCsrfToken();
    $this->mutate('First draft', NULL, $token);
    $this->mutate('Second draft', NULL, $token);

    $second = $this->drupalCreateUser(self::EDITOR_PERMISSIONS);
    $this->drupalLogin($second);
    $response = $this->postJson('/daedalus-edit/tempstore/save/entity_test/' . $this->entity->id(), [], $this->getCsrfToken());
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    $this->assertSame('Second draft', $this->stagedSubtitle(), 'The save committed the unsaved copy.');

    // The first editor, whose changes those were, has nothing to step back
    // to: the saved page cannot be overwritten from an older snapshot.
    $this->drupalLogin($this->editor);
    $token = $this->getCsrfToken();
    $this->drupalGet($this->historyUrl());
    $timeline = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame([], $timeline['entries']);
    $this->assertFalse($timeline['can_undo']);
    foreach ([['direction' => 'undo'], ['to' => 0], ['to' => 1]] as $body) {
      $response = $this->postJson($this->historyUrl() . '/restore', $body, $token);
      $this->assertSame(409, $response->getStatusCode());
    }
    $this->assertSame('Second draft', $this->stagedSubtitle());
  }

  /**
   * A label the client sends is kept to the recorder's ceiling.
   */
  public function testClientLabelIsCapped(): void {
    $this->drupalLogin($this->editor);
    $payload = $this->mutate('First draft', str_repeat('x', 5000), $this->getCsrfToken());
    $this->assertSame(HistoryRecorder::MAX_LABEL_LENGTH, mb_strlen($payload['history']['entries'][1]['label']));
  }

  /**
   * The history routes ask update access and the inline editing permission.
   *
   * The restore asks the session token too.
   */
  public function testAccess(): void {
    $viewer = $this->drupalCreateUser(['view test entity', 'access inline editing']);
    $this->drupalLogin($viewer);
    $this->drupalGet($this->historyUrl());
    $this->assertSession()->statusCodeEquals(403);
    $response = $this->postJson($this->historyUrl() . '/restore', ['direction' => 'undo'], $this->getCsrfToken());
    $this->assertSame(403, $response->getStatusCode());

    // Update access alone is not enough: the routes ask what Save and
    // Discard ask. The mutation itself is not this permission's to gate.
    $updater = $this->drupalCreateUser(['view test entity', 'administer entity_test content']);
    $this->drupalLogin($updater);
    $token = $this->getCsrfToken();
    $this->mutate('First draft', NULL, $token);
    $this->drupalGet($this->historyUrl());
    $this->assertSession()->statusCodeEquals(403);
    $response = $this->postJson($this->historyUrl() . '/restore', ['direction' => 'undo'], $token);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame('First draft', $this->stagedSubtitle(), 'The refused restore moved nothing.');

    // An editor without the CSRF token is rejected.
    $this->drupalLogin($this->editor);
    $response = $this->postJson($this->historyUrl() . '/restore', ['direction' => 'undo']);
    $this->assertSame(403, $response->getStatusCode());
    $this->drupalGet($this->historyUrl());
    $this->assertSession()->statusCodeEquals(200);
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
