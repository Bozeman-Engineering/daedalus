<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_api\Functional;

use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
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
   * Read mode css: one element's styles as text/css, words included.
   *
   * The bare endpoint pins the request contract (uuid required, unknown
   * uuid, a selector outside the grammar); then the styling stack joins
   * the site at runtime (daedalus_stylish for the style map, daedalus_layout
   * for section identity, daedalus_ui with them) and a styled section
   * prints: its words from the annotator through the read event, its own
   * default entry as a rule, its conditions and rules flat, and with
   * `selector` the rule's entry and its continuations by descent alone.
   */
  public function testReadCssMode(): void {
    $this->drupalLogin($this->editor);
    $base = '/daedalus-blueprint/entity_test/' . $this->entity->id();

    $this->drupalGet($base, ['query' => ['mode' => 'css']]);
    $this->assertSession()->statusCodeEquals(400);
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('INVALID_REQUEST', $payload['errors'][0]['code']);

    $this->drupalGet($base, ['query' => ['mode' => 'css', 'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee']]);
    $this->assertSession()->statusCodeEquals(404);
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('ELEMENT_NOT_FOUND', $payload['errors'][0]['code']);

    $this->drupalGet($base, [
      'query' => ['mode' => 'css', 'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'selector' => ')))'],
    ]);
    $this->assertSession()->statusCodeEquals(400);
    $payload = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertSame('INVALID_REQUEST', $payload['errors'][0]['code']);

    $this->installStylingStack();
    $uuid = $this->container->get('uuid')->generate();
    $styled = $this->createStyledHost($uuid, [
      "container:$uuid" => ['default' => ['color' => 'red'], 'hover' => ['color' => 'blue']],
      "container:$uuid::> pb-text" => ['default' => ['gap' => '1rem']],
      "container:$uuid::> pb-text .link" => ['default' => ['padding' => '0']],
      "container:$uuid::> *" => ['default' => ['margin' => '0']],
    ]);
    $styled_base = '/daedalus-blueprint/entity_test/' . $styled->id();

    // The raw body, byte-exact: Mink's page content trims a text/css
    // response, so the read goes through the HTTP client.
    $response = $this->getCss($styled_base, ['mode' => 'css', 'uuid' => $uuid]);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith('text/css', $response->getHeaderLine('Content-Type'));
    $this->assertSame(
      "  /* Section: Welcome */\n"
      . "  #$uuid { color: red; }\n"
      . "  #$uuid:hover { color: blue; }\n"
      . "  #$uuid > pb-text { gap: 1rem; }\n"
      . "  #$uuid > pb-text .link { padding: 0; }\n"
      . "  #$uuid > * { margin: 0; }\n",
      (string) $response->getBody(),
    );

    $response = $this->getCss($styled_base, ['mode' => 'css', 'uuid' => $uuid, 'selector' => '> pb-text']);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame(
      "  /* Section: Welcome */\n"
      . "  #$uuid > pb-text { gap: 1rem; }\n"
      . "  #$uuid > pb-text .link { padding: 0; }\n",
      (string) $response->getBody(),
    );
  }

  /**
   * The parse door: POST parse-css answers entries and writes nothing.
   *
   * A nested block under an element key comes back as the flat entries
   * the client will batch, a corrective comes back with its line in the
   * sent text, the entity's style map is untouched afterwards, and the
   * route is gated as a mutation is: a request outside the contract is
   * 400, no CSRF header or no update access is 403.
   */
  public function testParseCss(): void {
    $uuid = $this->container->get('uuid')->generate();
    $body = ['key' => "container:$uuid", 'css' => "color: blue;\n&:hover { color: green; }\n> * { margin: 0; }"];
    // The gates first, on the bare entity, and the editor's session and
    // token taken before the styling stack joins the site: from then on
    // this fixture renders no themed page (the stack's sidebar plugins
    // want modules it does not carry), so logins are over and every
    // answer, a refusal included, is asked for as JSON.
    $format = ['query' => ['_format' => 'json']];
    $bare = $this->buildUrl('/daedalus-blueprint/entity_test/' . $this->entity->id() . '/parse-css', $format);
    $this->drupalLogin($this->viewer);
    $response = $this->postOperations($body, $this->getCsrfToken(), $bare);
    $this->assertSame(403, $response->getStatusCode(), 'View access does not open the door.');
    $this->drupalLogin($this->editor);
    $token = $this->getCsrfToken();
    $response = $this->postOperations($body, NULL, $bare);
    $this->assertSame(403, $response->getStatusCode(), 'The CSRF header gates the door.');

    $this->installStylingStack();
    $map = ["container:$uuid" => ['default' => ['color' => 'red']]];
    $styled = $this->createStyledHost($uuid, $map);
    $url = $this->buildUrl('/daedalus-blueprint/entity_test/' . $styled->id() . '/parse-css', $format);
    $response = $this->postOperations($body, $token, $url);
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getBody(), TRUE);
    $this->assertSame("container:$uuid", $payload['key']);
    $this->assertSame([], $payload['errors']);
    $this->assertSame([], $payload['wear']);
    $this->assertSame([
      ['key' => "container:$uuid", 'condition' => 'default', 'properties' => ['color' => 'blue']],
      ['key' => "container:$uuid", 'condition' => 'hover', 'properties' => ['color' => 'green']],
      ['key' => "container:$uuid::> *", 'condition' => 'default', 'properties' => ['margin' => '0']],
    ], $payload['entries']);

    $response = $this->postOperations(['key' => "container:$uuid", 'css' => "color: blue;\n&:sparkle { color: green; }"], $token, $url);
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getBody(), TRUE);
    $this->assertSame('UNKNOWN_STATE', $payload['errors'][0]['code']);
    $this->assertSame(2, $payload['errors'][0]['context']['line']);
    $this->assertSame([['key' => "container:$uuid", 'condition' => 'default', 'properties' => ['color' => 'blue']]], $payload['entries']);

    \Drupal::entityTypeManager()->getStorage('entity_test')->resetCache([$styled->id()]);
    $this->assertSame($map, json_decode(EntityTest::load($styled->id())->get(DaedalusStylishFieldManager::FIELD_NAME)->value, TRUE), 'The door wrote nothing.');

    $response = $this->getHttpClient()->request('POST', $url, [
      'cookies' => $this->getSessionCookies(),
      'headers' => ['Content-Type' => 'application/json', 'X-CSRF-Token' => $token],
      'body' => 'this is not json',
      'http_errors' => FALSE,
    ]);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('INVALID_REQUEST_BODY', json_decode((string) $response->getBody(), TRUE)['errors'][0]['code']);

    $response = $this->postOperations(['key' => "container:$uuid"], $token, $url);
    $this->assertSame(400, $response->getStatusCode());
    $this->assertSame('INVALID_REQUEST', json_decode((string) $response->getBody(), TRUE)['errors'][0]['code']);
  }

  /**
   * Joins the styling stack to the site at runtime.
   *
   * The other cases pin the bare endpoint (database persistence, no
   * tempstore), so daedalus_stylish (the style map) and daedalus_layout
   * (section identity) are installed inside the cases that need a styled
   * element, and Layout Builder overrides are enabled on entity_test.
   */
  protected function installStylingStack(): void {
    $this->container->get('module_installer')->install(['daedalus_stylish', 'daedalus_layout']);
    $this->rebuildContainer();
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default')
      ?? LayoutBuilderEntityViewDisplay::create([
        'targetEntityType' => 'entity_test',
        'bundle' => 'entity_test',
        'mode' => 'default',
        'status' => TRUE,
      ]);
    $display->enableLayoutBuilder()->setOverridable()->save();
  }

  /**
   * Creates an entity holding one section named Welcome and a style map.
   *
   * @param string $uuid
   *   The section's uuid (its container key's identity).
   * @param array $map
   *   The style map, keyed by style key.
   *
   * @return \Drupal\entity_test\Entity\EntityTest
   *   The saved entity.
   */
  protected function createStyledHost(string $uuid, array $map): EntityTest {
    $section = new Section('layout_onecol', ['label' => 'Welcome']);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    $styled = EntityTest::create([
      'name' => 'Styled host',
      'layout_builder__layout' => [$section],
      DaedalusStylishFieldManager::FIELD_NAME => ['value' => json_encode($map)],
    ]);
    $styled->save();
    return $styled;
  }

  /**
   * GETs the read endpoint raw, as the logged-in user.
   *
   * @param string $path
   *   The endpoint path.
   * @param array $query
   *   The query parameters.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response, body untouched.
   */
  protected function getCss(string $path, array $query): ResponseInterface {
    return $this->getHttpClient()->request('GET', $this->buildUrl($path, ['query' => $query]), [
      'cookies' => $this->getSessionCookies(),
      'http_errors' => FALSE,
    ]);
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
   * A label mutation carries the theme's page title under the label's key.
   *
   * With daedalus_edit on the site, a node's title is the root's edit
   * target and renders outside the entity as the theme's H1, which
   * daedalus_edit attributes as the title field's leaf. A title op on this
   * endpoint (the AI's and the undo timeline's road too) must answer with
   * that H1 under the field's key, attributed exactly as the canvas one, so
   * the client's swap-by-key replaces it; and with the H1 alone, since the
   * label is outside the entity by construction. The request carries the
   * mode on the query, as the editing client sends it (the mode cookie is
   * path-scoped to the edited page).
   */
  public function testLabelMutationCarriesThePageTitle(): void {
    // The other cases pin the bare endpoint (database persistence, no
    // attribution), so the editing stack joins the site here, at runtime.
    $this->container->get('module_installer')->install(['node', 'daedalus_edit']);
    $this->rebuildContainer();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $node = Node::create(['type' => 'page', 'title' => 'Original title']);
    $node->save();
    $this->drupalLogin($this->drupalCreateUser([
      'access content',
      'edit any page content',
      'access inline editing',
    ]));

    $url = $this->buildUrl('/daedalus-blueprint/node/' . $node->id(), ['query' => ['navigationMode' => 'edit']]);
    $response = $this->postOperations([
      'operations' => [
        ['op' => 'update_element', 'properties' => ['title' => 'Renamed title']],
      ],
      'view_mode' => 'full',
    ], $this->getCsrfToken(), $url);
    $this->assertSame(200, $response->getStatusCode());
    $payload = json_decode((string) $response->getBody(), TRUE);
    $this->assertTrue($payload['success']);
    $this->assertSame([], $payload['warnings']);
    $this->assertContains(['uuid' => $node->uuid(), 'field_name' => 'title'], $payload['targets']);

    $label_key = 'field:' . $node->uuid() . ':title';
    $this->assertSame([$label_key], array_keys($payload['markup']), 'A label-only mutation swaps the H1 alone.');
    $h1 = $payload['markup'][$label_key];
    $this->assertStringStartsWith('<h1', $h1);
    $this->assertStringContainsString('data-selectable="' . $label_key . '"', $h1);
    $this->assertStringContainsString(
      sprintf('data-daedalus-edit-id="node::page::%s::title::0::%s::string::string::value"', $node->id(), $node->language()->getId()),
      $h1,
    );
    $this->assertStringContainsString('Renamed title', $h1);
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
   * @param string|null $url
   *   The endpoint URL, or NULL for the test entity's.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   The response.
   */
  protected function postOperations(array $body, ?string $token = NULL, ?string $url = NULL): ResponseInterface {
    $headers = ['Content-Type' => 'application/json'];
    if ($token !== NULL) {
      $headers['X-CSRF-Token'] = $token;
    }
    return $this->getHttpClient()->post($url ?? $this->mutationUrl(), [
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
