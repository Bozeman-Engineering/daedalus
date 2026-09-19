<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\daedalus_edit\DaedalusEditMediaLibraryOpener;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media_library\MediaLibraryState;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the media library opener for blueprint-edited media references.
 *
 * The opener's contract: buildUrl() constructs the media_library.ui URL a
 * blueprint editor opens (state hash included, host entity in the opener
 * parameters), checkAccess() gates the library on host-entity update access,
 * and getSelectionResponse() returns the one selection command the client
 * routes to the current edit.
 *
 * @group daedalus_edit
 */
#[RunTestsInSeparateProcesses]
class DaedalusEditMediaLibraryOpenerTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'options',
    'editor',
    'file',
    'image',
    'views',
    'media',
    'media_library',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
    'entity_test',
  ];

  /**
   * The opener under test.
   */
  protected DaedalusEditMediaLibraryOpener $opener;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->opener = $this->container->get(DaedalusEditMediaLibraryOpener::SERVICE_ID);
  }

  /**
   * A media reference field definition with the given target bundles.
   */
  protected function fieldDefinition(array $target_bundles): BaseFieldDefinition {
    return BaseFieldDefinition::create('entity_reference')
      ->setName('field_test_media')
      ->setSetting('target_type', 'media')
      ->setSetting('handler_settings', ['target_bundles' => $target_bundles]);
  }

  /**
   * A state addressing this opener with the given opener parameters.
   */
  protected function state(array $opener_parameters): MediaLibraryState {
    return MediaLibraryState::create(
      DaedalusEditMediaLibraryOpener::SERVICE_ID,
      ['image'],
      'image',
      1,
      $opener_parameters,
    );
  }

  /**
   * Access follows host-entity update access; broken addressing is forbidden.
   */
  public function testCheckAccess(): void {
    // User 1 bypasses access; burn it so the real accounts are ordinary.
    $this->createUser();
    $no_access = $this->createUser();
    $editor = $this->createUser(['administer entity_test content']);

    $host = EntityTest::create(['name' => 'Host page']);
    $host->save();

    // Missing opener parameters forbid, never fall through.
    $access = $this->opener->checkAccess($this->state([]), $editor);
    $this->assertTrue($access->isForbidden());
    $this->assertSame('entity_type_id parameter is missing.', $access->getReason());

    // An unloadable host forbids.
    $access = $this->opener->checkAccess($this->state([
      'entity_type_id' => 'entity_test',
      'entity_id' => '999',
    ]), $editor);
    $this->assertTrue($access->isForbidden());

    // The gate is the HOST entity's update access — the same check the
    // blueprint mutation endpoint the selection commits through enforces.
    $parameters = [
      'entity_type_id' => 'entity_test',
      'entity_id' => (string) $host->id(),
    ];
    $this->assertFalse($this->opener->checkAccess($this->state($parameters), $no_access)->isAllowed());
    $this->assertTrue($this->opener->checkAccess($this->state($parameters), $editor)->isAllowed());
  }

  /**
   * The selection returns as the one command the client routes.
   */
  public function testSelectionResponse(): void {
    $response = $this->opener->getSelectionResponse($this->state([
      'entity_type_id' => 'entity_test',
      'entity_id' => '1',
    ]), [5]);
    $this->assertInstanceOf(AjaxResponse::class, $response);

    $commands = $response->getCommands();
    $this->assertCount(1, $commands);
    $this->assertSame('daedalusEditMediaLibrarySelection', $commands[0]['command']);
    $this->assertSame([5], $commands[0]['selectedIds']);
  }

  /**
   * The buildUrl() method produces a valid, tamper-proof library URL.
   */
  public function testBuildUrl(): void {
    $host = EntityTest::create(['name' => 'Host page']);
    $host->save();

    $url = $this->opener->buildUrl($host, $this->fieldDefinition(['image' => 'image', 'video' => 'video']), 'video');
    $this->assertNotNull($url);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $this->assertSame(DaedalusEditMediaLibraryOpener::SERVICE_ID, $query['media_library_opener_id']);
    $this->assertSame(['image', 'video'], array_values($query['media_library_allowed_types']));
    $this->assertSame('video', $query['media_library_selected_type']);
    $this->assertSame('1', $query['media_library_remaining']);
    $this->assertSame([
      'entity_type_id' => 'entity_test',
      'entity_id' => (string) $host->id(),
    ], $query['media_library_opener_parameters']);

    // The state round-trips: fromRequest() re-validates the hash (throwing
    // on tampering), and the reconstructed state passes checkAccess for a
    // host editor — the whole open-the-modal handshake.
    $state = MediaLibraryState::fromRequest(Request::create($url));
    $this->createUser();
    $editor = $this->createUser(['administer entity_test content']);
    $this->assertTrue($this->opener->checkAccess($state, $editor)->isAllowed());

    // A selected type outside the allowed list falls back to the first
    // allowed type instead of producing an invalid state.
    $url = $this->opener->buildUrl($host, $this->fieldDefinition(['image' => 'image']), 'video');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $this->assertSame('image', $query['media_library_selected_type']);

    // An unsaved host cannot be addressed by the opener parameters.
    $this->assertNull($this->opener->buildUrl(EntityTest::create(['name' => 'Unsaved']), $this->fieldDefinition(['image' => 'image'])));
  }

}
