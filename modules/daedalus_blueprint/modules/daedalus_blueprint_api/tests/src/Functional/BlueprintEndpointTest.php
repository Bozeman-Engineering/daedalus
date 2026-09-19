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
 * Tests the HTTP blueprint endpoint: contract shape, access, and CSRF.
 */
#[RunTestsInSeparateProcesses]
#[Group('daedalus_blueprint')]
class BlueprintEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_test', 'daedalus_blueprint', 'daedalus_blueprint_api'];

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
   * A user who can only view entity_test entities.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $viewer;

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
    // Show the field in rendered output so the markup half of the contract
    // can be asserted against.
    \Drupal::service('entity_display.repository')
      ->getViewDisplay('entity_test', 'entity_test')
      ->setComponent('field_subtitle', ['type' => 'string'])
      ->save();

    $this->entity = EntityTest::create([
      'name' => 'Endpoint test entity',
      'field_subtitle' => 'Original subtitle',
    ]);
    $this->entity->save();

    $this->editor = $this->drupalCreateUser([
      'view test entity',
      'administer entity_test content',
    ]);
    $this->viewer = $this->drupalCreateUser([
      'view test entity',
    ]);
  }

  /**
   * A mutation round-trip: apply, contract shape, persistence.
   */
  public function testMutationLifecycle(): void {
    $this->drupalLogin($this->editor);

    $response = $this->postOperations([
      'operations' => [
        [
          'op' => 'update_element',
          'properties' => ['field_subtitle' => 'Updated subtitle'],
        ],
      ],
    ], $this->getCsrfToken());
    $this->assertSame(200, $response->getStatusCode());

    $payload = json_decode((string) $response->getBody(), TRUE);
    $this->assertTrue($payload['success']);
    $this->assertSame('entity_test', $payload['entity_type']);
    $this->assertSame((string) $this->entity->id(), (string) $payload['entity_id']);
    $this->assertSame($this->entity->uuid(), $payload['entity_uuid']);
    $this->assertSame([], $payload['errors']);
    $this->assertContains(
      ['uuid' => $this->entity->uuid(), 'field_name' => 'field_subtitle'],
      $payload['targets'],
    );
    // No tempstore module is installed, so persistence falls back to the
    // database.
    $this->assertSame('database', $payload['persisted']);
    // The markup key carries the re-rendered entity. With no data-selectable
    // attribution stamped yet, subtree extraction falls back to the whole
    // entity under its root selectable key.
    $entity_key = 'entity:' . $this->entity->uuid();
    $this->assertArrayHasKey($entity_key, $payload['markup']);
    $this->assertStringContainsString('Updated subtitle', $payload['markup'][$entity_key]);
    $this->assertSame([], $payload['warnings']);
    $this->assertIsArray($payload['attachments']);
    $this->assertSame([], $payload['drupal_commands']);
    // The response blueprint reflects the mutation.
    $this->assertSame('Updated subtitle', $payload['blueprint']['fields']['field_subtitle']);

    $storage = \Drupal::entityTypeManager()->getStorage('entity_test');
    $storage->resetCache([$this->entity->id()]);
    $reloaded = $storage->load($this->entity->id());
    $this->assertSame('Updated subtitle', $reloaded->get('field_subtitle')->value);
  }

  /**
   * Read modes: full, summary, element, and invalid.
   */
  public function testReadModes(): void {
    $this->drupalLogin($this->editor);
    $base = '/daedalus-blueprint/entity_test/' . $this->entity->id();

    $this->drupalGet($base);
    $this->assertSession()->statusCodeEquals(200);
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('full', $payload['mode']);
    $this->assertSame('Original subtitle', $payload['blueprint']['fields']['field_subtitle']);

    $this->drupalGet($base, ['query' => ['mode' => 'summary']]);
    $this->assertSession()->statusCodeEquals(200);
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('summary', $payload['mode']);
    $this->assertArrayHasKey('blueprint', $payload);

    $this->drupalGet($base, ['query' => ['mode' => 'element', 'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']]);
    $this->assertSession()->statusCodeEquals(404);
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('ELEMENT_NOT_FOUND', $payload['errors'][0]['code']);

    $this->drupalGet($base, ['query' => ['mode' => 'bogus']]);
    $this->assertSession()->statusCodeEquals(400);
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('INVALID_MODE', $payload['errors'][0]['code']);
  }

  /**
   * Failed operations return 422 with structured errors and change nothing.
   */
  public function testValidationFailure(): void {
    $this->drupalLogin($this->editor);
    $token = $this->getCsrfToken();

    $response = $this->postOperations([
      'operations' => [['op' => 'polish_the_chrome']],
    ], $token);
    $this->assertSame(422, $response->getStatusCode());
    $payload = json_decode((string) $response->getBody(), TRUE);
    $this->assertFalse($payload['success']);
    $this->assertNotEmpty($payload['errors']);

    $response = $this->postOperations([
      'operations' => [
        [
          'op' => 'update_element',
          'properties' => ['field_nonexistent' => 'value'],
        ],
      ],
    ], $token);
    $this->assertSame(422, $response->getStatusCode());
    $payload = json_decode((string) $response->getBody(), TRUE);
    $this->assertFalse($payload['success']);
    $this->assertNotEmpty($payload['errors']);
    $this->assertArrayHasKey('code', $payload['errors'][0]);

    $storage = \Drupal::entityTypeManager()->getStorage('entity_test');
    $storage->resetCache([$this->entity->id()]);
    $this->assertSame('Original subtitle', $storage->load($this->entity->id())->get('field_subtitle')->value);
  }

  /**
   * Malformed request bodies return 400 with the shared error shape.
   */
  public function testRequestValidation(): void {
    $this->drupalLogin($this->editor);
    $token = $this->getCsrfToken();

    $response = $this->getHttpClient()->post($this->mutationUrl(), [
      'cookies' => $this->getSessionCookies(),
      'headers' => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $token,
      ],
      'body' => 'this is not json',
      'http_errors' => FALSE,
    ]);
    $this->assertSame(400, $response->getStatusCode());
    $payload = json_decode((string) $response->getBody(), TRUE);
    $this->assertSame('INVALID_REQUEST_BODY', $payload['errors'][0]['code']);

    $response = $this->postOperations(['langcode' => 'en'], $token);
    $this->assertSame(400, $response->getStatusCode());
    $payload = json_decode((string) $response->getBody(), TRUE);
    $this->assertSame('MISSING_OPERATIONS', $payload['errors'][0]['code']);
  }

  /**
   * Entity access and the CSRF header gate the endpoint.
   */
  public function testAccess(): void {
    // A user without update access can read but not mutate.
    $this->drupalLogin($this->viewer);
    $this->drupalGet('/daedalus-blueprint/entity_test/' . $this->entity->id());
    $this->assertSession()->statusCodeEquals(200);

    $response = $this->postOperations([
      'operations' => [
        ['op' => 'update_element', 'properties' => ['field_subtitle' => 'Nope']],
      ],
    ], $this->getCsrfToken());
    $this->assertSame(403, $response->getStatusCode());

    // An editor without the CSRF token is rejected.
    $this->drupalLogin($this->editor);
    $response = $this->postOperations([
      'operations' => [
        ['op' => 'update_element', 'properties' => ['field_subtitle' => 'Nope']],
      ],
    ]);
    $this->assertSame(403, $response->getStatusCode());

    // Anonymous users cannot read.
    $this->drupalLogout();
    $this->drupalGet('/daedalus-blueprint/entity_test/' . $this->entity->id());
    $this->assertSession()->statusCodeEquals(403);

    $storage = \Drupal::entityTypeManager()->getStorage('entity_test');
    $storage->resetCache([$this->entity->id()]);
    $this->assertSame('Original subtitle', $storage->load($this->entity->id())->get('field_subtitle')->value);
  }

  /**
   * Returns the mutation endpoint URL for the test entity.
   */
  protected function mutationUrl(): string {
    return $this->buildUrl('/daedalus-blueprint/entity_test/' . $this->entity->id());
  }

  /**
   * POSTs a mutation request body as the logged-in user.
   *
   * @param array $body
   *   The request body to JSON-encode.
   * @param string|null $token
   *   The CSRF token, or NULL to omit the header.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function postOperations(array $body, ?string $token = NULL): ResponseInterface {
    $headers = ['Content-Type' => 'application/json'];
    if ($token !== NULL) {
      $headers['X-CSRF-Token'] = $token;
    }
    return $this->getHttpClient()->post($this->mutationUrl(), [
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
